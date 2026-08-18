<?php
/**
 * GitHub API client + GitHub Copilot chat API.
 *
 * Fetches component source files from the configured GitHub repository
 * and submits them to GitHub Copilot to generate CSS advice.
 *
 * Caching strategy:
 *  - Transient key: rjm_css_{sha1 of component slug}
 *  - The ETag returned by GitHub's Contents API is stored alongside the advice.
 *  - On subsequent requests, If-None-Match is sent; a 304 response reuses the cache.
 *  - If the file has changed, fresh Copilot advice is generated and cached.
 */

defined( 'ABSPATH' ) || exit;

class RJM_CSS_Advisor_GitHub_Client {

	const GITHUB_API_BASE  = 'https://api.github.com';
	const COPILOT_API_BASE = 'https://api.githubcopilot.com';
	const OPENAI_API_BASE  = 'https://api.openai.com/v1';

	// Map ACF layout name → React component filename (without path/extension).
	const LAYOUT_TO_COMPONENT = [
		'hero'                   => 'Hero',
		'page_break'             => 'PageBreak',
		'header_1'               => 'Header1',
		'lead_magnet_banner_1'   => 'LeadMagnetBanner1',
		'lead_magnet_banner_2'   => 'LeadMagnetBanner2',
		'cta_banner'             => 'CtaBanner',
		'highlights_section'     => 'HighlightsSection',
		'coach_bio_section'      => 'CoachBioSection',
		'program_summary'        => 'ProgramSummary',
		'emotional_hook_section' => 'EmotionalHookSection',
		'program_story_section'  => 'ProgramStorySection',
		'blog_carousel'          => 'BlogCarousel',
		'contact_us'             => 'ContactUs',
		'guide_download_section' => 'GuideDownloadSection',
		'guide_benefits_section' => 'GuideBenefitsSection',
		'faq_section'            => 'FaqSection',
		'answer_section'         => 'AnswerSection',
		'testimonial'            => 'Testimonial',
		'contact_form'           => 'ContactForm',
		'discovery_call_booking' => 'DiscoveryCallBooking',
		'google_review'          => 'GoogleReview',
		'as_seen_in'             => 'AsSeenIn',
		'pricing_section'        => 'PricingSection',
		'duo_card_section'       => 'DuoCardSection',
		'steps_section'          => 'StepsSection',
		'events_section'         => 'EventsSection',
		'podcast_section'        => 'PodcastSection',
		'i_frame'                => 'IFrame',
		'custom_code_block'      => 'CustomCodeBlock',
	];

	// Human-readable labels for sub-component CSS field names.
	const SUBFIELD_LABELS = [
		'pricing_card_custom_css' => 'an individual pricing card inside the Pricing Section',
		'card_custom_css'         => 'an individual card inside the Duo Card Section',
		'step_custom_css'         => 'an individual step inside the Steps Section',
	];

	// CSS field names that are sub-component (not whole layout) fields.
	const SUBFIELD_NAMES = [ 'pricing_card_custom_css', 'card_custom_css', 'step_custom_css' ];

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Generate actual CSS code for a given layout, field, and user-stated goal.
	 *
	 * Unlike get_advice(), this produces ready-to-paste CSS, not explanatory text.
	 * Results are not cached because each goal is unique.
	 *
	 * @param string $layout_name  ACF flexible content layout name (e.g. 'header_1').
	 * @param string $field_name   ACF field name (e.g. 'custom_css').
	 * @param bool   $is_global    Whether this is the global CSS field.
	 * @param string $goal         Plain-English description of what the user wants to achieve.
	 * @param array  $breakpoints   Selected breakpoint slugs from the advisor form.
	 * @return array|WP_Error  { css: string }
	 */
	public function generate_css( $layout_name, $field_name = 'custom_css', $is_global = false, $goal = '', $breakpoints = [], $existing_css_context = '', $screenshot_data = '' ) {
		$selected_breakpoints = is_array( $breakpoints ) ? $breakpoints : [];
		$existing_css_block   = $this->build_existing_css_context_block( $existing_css_context );
		$is_global            = $is_global || ( 'global_custom_css' === $field_name );

		$token = RJM_CSS_Advisor_Settings::get_token();
		if ( ! $token ) {
			return new WP_Error( 'no_token', __( 'No GitHub token configured. Please visit Settings → RJM CSS Advisor.', 'rjm-css-advisor' ) );
		}

		$context = $this->build_component_or_global_context( $token, $layout_name, $field_name, $is_global );
		if ( is_wp_error( $context ) ) {
			$this->log_debug_error( 'generate_css', $context, [
				'layout'    => $layout_name,
				'field'     => $field_name,
				'is_global' => $is_global,
			] );
			return $context;
		}

		$user_message  = "Goal: {$goal}" . $this->build_breakpoint_context( $selected_breakpoints ) . "\n\n";
		$user_message .= "Context:\n" . $context['context'];
		$user_message .= $existing_css_block;
		$system_prompt = $this->get_css_generator_system_prompt( $is_global );

		$css = $this->call_copilot_with_context( $token, 'CSS Generator', $user_message, $system_prompt, $screenshot_data );

		if ( is_wp_error( $css ) ) {
			$this->log_debug_error( 'generate_css', $css, [
				'layout'    => $layout_name,
				'field'     => $field_name,
				'is_global' => $is_global,
			] );
			return $css;
		}

		$parsed = $this->parse_structured_css_response( $css );

		if ( $is_global ) {
			return $parsed;
		}

		return $this->enforce_breakpoint_policy( $token, $user_message, $system_prompt, $parsed, $selected_breakpoints );
	}

	/**
	 * Run a single Ask/Plan chat turn and return assistant guidance.
	 *
	 * @param string $layout_name
	 * @param string $field_name
	 * @param bool   $is_global
	 * @param array  $messages       Array of ['role' => 'user|assistant', 'content' => string].
	 * @param array  $breakpoints    Selected breakpoints.
	 * @return array|WP_Error
	 */
	public function plan_css_turn( $layout_name, $field_name = 'custom_css', $is_global = false, $messages = [], $breakpoints = [], $existing_css_context = '' ) {
		$token = RJM_CSS_Advisor_Settings::get_token();
		if ( ! $token ) {
			return new WP_Error( 'no_token', __( 'No GitHub token configured. Please visit Settings → RJM CSS Advisor.', 'rjm-css-advisor' ) );
		}

		$context = $this->build_component_or_global_context( $token, $layout_name, $field_name, $is_global );
		if ( is_wp_error( $context ) ) {
			$this->log_debug_error( 'plan_css_turn', $context, [
				'layout'    => $layout_name,
				'field'     => $field_name,
				'is_global' => $is_global,
			] );
			return $context;
		}

		$conversation = $this->format_chat_messages_for_prompt( $messages );
		$screenshot_data = $this->get_latest_screenshot_data( $messages );
		$prompt = "Selected breakpoints: " . implode( ', ', $this->normalize_selected_breakpoints( $breakpoints ) ) . "\n\n";
		$prompt .= "Context:\n" . $context['context'] . "\n\n";
		$prompt .= $this->build_existing_css_context_block( $existing_css_context );
		$prompt .= "\n\n";
		$prompt .= "Conversation so far:\n" . $conversation;

		$raw = $this->call_copilot_with_context(
			$token,
			'CSS Planner',
			$prompt,
			$this->get_css_planner_system_prompt(),
			$screenshot_data
		);

		if ( is_wp_error( $raw ) ) {
			$this->log_debug_error( 'plan_css_turn', $raw, [
				'layout'    => $layout_name,
				'field'     => $field_name,
				'is_global' => $is_global,
			] );
			return $raw;
		}

		$decoded = json_decode( trim( self::strip_code_fences( $raw ) ), true );
		if ( ! is_array( $decoded ) ) {
			return [
				'assistant_message' => trim( (string) $raw ),
				'ready_to_generate' => false,
				'brief' => '',
			];
		}

		return [
			'assistant_message' => trim( (string) ( $decoded['assistant_message'] ?? '' ) ),
			'ready_to_generate' => ! empty( $decoded['ready_to_generate'] ),
			'brief'             => trim( (string) ( $decoded['brief'] ?? '' ) ),
		];
	}

	/**
	 * Create a build plan from a goal.
	 *
	 * @param string $layout_name
	 * @param string $field_name
	 * @param bool   $is_global
	 * @param string $goal
	 * @param array  $breakpoints
	 * @return array|WP_Error
	 */
	public function create_css_build_plan( $layout_name, $field_name = 'custom_css', $is_global = false, $goal = '', $breakpoints = [], $existing_css_context = '' ) {
		$token = RJM_CSS_Advisor_Settings::get_token();
		if ( ! $token ) {
			return new WP_Error( 'no_token', __( 'No GitHub token configured. Please visit Settings → RJM CSS Advisor.', 'rjm-css-advisor' ) );
		}

		$context = $this->build_component_or_global_context( $token, $layout_name, $field_name, $is_global );
		if ( is_wp_error( $context ) ) {
			$this->log_debug_error( 'create_css_build_plan', $context, [
				'layout'    => $layout_name,
				'field'     => $field_name,
				'is_global' => $is_global,
			] );
			return $context;
		}

		$message  = "Goal: " . $goal . "\n";
		$message .= $this->build_breakpoint_context( $breakpoints ) . "\n\n";
		$message .= "Context:\n" . $context['context'];
		$message .= $this->build_existing_css_context_block( $existing_css_context );

		$raw = $this->call_copilot_with_context(
			$token,
			'CSS Build Planner',
			$message,
			$this->get_css_build_planner_system_prompt()
		);

		if ( is_wp_error( $raw ) ) {
			$this->log_debug_error( 'create_css_build_plan', $raw, [
				'layout'    => $layout_name,
				'field'     => $field_name,
				'is_global' => $is_global,
			] );
			return $raw;
		}

		$decoded = json_decode( trim( self::strip_code_fences( $raw ) ), true );
		$steps = [];

		if ( is_array( $decoded ) && ! empty( $decoded['steps'] ) && is_array( $decoded['steps'] ) ) {
			$steps = array_values( array_filter( array_map( 'trim', $decoded['steps'] ) ) );
		}

		if ( ! $steps ) {
			$steps = [
				__( 'Set core typography and spacing first.', 'rjm-css-advisor' ),
				__( 'Style main backgrounds and containers.', 'rjm-css-advisor' ),
				__( 'Style interactive elements and hover states.', 'rjm-css-advisor' ),
				__( 'Refine responsive behavior for selected breakpoints.', 'rjm-css-advisor' ),
			];
		}

		return [
			'steps' => $steps,
			'title' => trim( (string) ( $decoded['title'] ?? __( 'Step-by-step CSS build', 'rjm-css-advisor' ) ) ),
		];
	}

	/**
	 * Generate CSS for one build step.
	 *
	 * @param string $layout_name
	 * @param string $field_name
	 * @param bool   $is_global
	 * @param string $goal
	 * @param string $step
	 * @param string $approved_css
	 * @param array  $breakpoints
	 * @param string $revision_feedback
	 * @return array|WP_Error
	 */
	public function generate_css_build_step( $layout_name, $field_name = 'custom_css', $is_global = false, $goal = '', $step = '', $approved_css = '', $breakpoints = [], $revision_feedback = '', $existing_css_context = '' ) {
		$selected_breakpoints = is_array( $breakpoints ) ? $breakpoints : [];
		$token = RJM_CSS_Advisor_Settings::get_token();
		if ( ! $token ) {
			return new WP_Error( 'no_token', __( 'No GitHub token configured. Please visit Settings → RJM CSS Advisor.', 'rjm-css-advisor' ) );
		}

		$context = $this->build_component_or_global_context( $token, $layout_name, $field_name, $is_global );
		if ( is_wp_error( $context ) ) {
			$this->log_debug_error( 'generate_css_build_step', $context, [
				'layout'    => $layout_name,
				'field'     => $field_name,
				'is_global' => $is_global,
			] );
			return $context;
		}

		$user_message  = "Primary goal: {$goal}\n";
		$user_message .= "Current build step: {$step}\n";
		$user_message .= "Already approved CSS:\n" . ( $approved_css ? $approved_css : 'None yet.' ) . "\n";

		if ( $revision_feedback ) {
			$user_message .= "Revision feedback for this step: {$revision_feedback}\n";
		}

		$user_message .= $this->build_breakpoint_context( $selected_breakpoints ) . "\n\n";
		$user_message .= "Context:\n" . $context['context'];
		$user_message .= $this->build_existing_css_context_block( $existing_css_context );

		$system_prompt = $this->get_css_builder_system_prompt( $is_global );
		$raw = $this->call_copilot_with_context( $token, 'CSS Build Step', $user_message, $system_prompt );

		if ( is_wp_error( $raw ) ) {
			$this->log_debug_error( 'generate_css_build_step', $raw, [
				'layout'    => $layout_name,
				'field'     => $field_name,
				'is_global' => $is_global,
			] );
			return $raw;
		}

		$parsed = $this->parse_structured_css_response( $raw );

		if ( $is_global ) {
			return $parsed;
		}

		return $this->enforce_breakpoint_policy( $token, $user_message, $system_prompt, $parsed, $selected_breakpoints );
	}

	/**
	 * Get AI CSS advice for a given layout and CSS field name.
	 *
	 * @param string $layout_name  ACF flexible content layout name (e.g. 'hero').
	 * @param string $field_name   ACF field name (e.g. 'custom_css', 'pricing_card_custom_css').
	 * @param bool   $is_global    Whether this is the global CSS field (theme settings).
	 * @return array|WP_Error  { advice: string, from_cache: bool }
	 */
	public function get_advice( $layout_name, $field_name = 'custom_css', $is_global = false ) {
		$token = RJM_CSS_Advisor_Settings::get_token();
		if ( ! $token ) {
			return new WP_Error( 'no_token', __( 'No GitHub token configured. Please visit Settings → RJM CSS Advisor.', 'rjm-css-advisor' ) );
		}

		if ( $is_global ) {
			return $this->get_global_advice( $token );
		}

		$component = self::LAYOUT_TO_COMPONENT[ $layout_name ] ?? null;
		if ( ! $component ) {
			return new WP_Error( 'unknown_layout', sprintf( __( 'No component mapping found for layout "%s".', 'rjm-css-advisor' ), $layout_name ) );
		}

		$cache_key = 'rjm_css_' . sha1( $layout_name . '|' . $field_name );
		$cached    = get_transient( $cache_key );

		// We store [ advice, etag ] in the transient.
		$saved_etag   = is_array( $cached ) ? ( $cached['etag'] ?? '' ) : '';
		$saved_advice = is_array( $cached ) ? ( $cached['advice'] ?? '' ) : '';

		// Fetch source file from GitHub.
		$file_path = 'src/components/' . $component . '.js';
		$result    = $this->fetch_github_file( $token, $file_path, $saved_etag );

		if ( is_wp_error( $result ) ) {
			// If 304 Not Modified and we have cached advice, return it.
			if ( $result->get_error_code() === 'not_modified' && $saved_advice ) {
				// Refresh transient TTL.
				set_transient( $cache_key, $cached, RJM_CSS_Advisor_Settings::get_cache_ttl() );
				return [ 'advice' => $saved_advice, 'from_cache' => true ];
			}
			return $result;
		}

		$source_code = $result['content'];
		$etag        = $result['etag'];

		// Generate advice via Copilot.
		$is_sub = in_array( $field_name, self::SUBFIELD_NAMES, true );
		$advice  = $this->call_copilot( $token, $component, $source_code, $is_sub, $field_name );

		if ( is_wp_error( $advice ) ) {
			return $advice;
		}

		// Cache the result.
		set_transient( $cache_key, [ 'advice' => $advice, 'etag' => $etag ], RJM_CSS_Advisor_Settings::get_cache_ttl() );

		return [ 'advice' => $advice, 'from_cache' => false ];
	}

	/**
	 * Verify that the GitHub token can reach the configured repository.
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		$token = RJM_CSS_Advisor_Settings::get_token();
		if ( ! $token ) {
			return new WP_Error( 'no_token', __( 'No token saved.', 'rjm-css-advisor' ) );
		}

		$repo = RJM_CSS_Advisor_Settings::get_repo();
		$url  = self::GITHUB_API_BASE . '/repos/' . $repo;

		$response = wp_remote_get( $url, [
			'headers' => $this->github_headers( $token ),
			'timeout' => 10,
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code === 200 ) {
			return true;
		}

		return new WP_Error( 'http_' . $code, sprintf( __( 'GitHub returned HTTP %d.', 'rjm-css-advisor' ), $code ) );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Fetch a single file from GitHub Contents API.
	 *
	 * @return array|WP_Error  { content: string, etag: string }
	 */
	private function fetch_github_file( $token, $file_path, $saved_etag = '' ) {
		$repo   = RJM_CSS_Advisor_Settings::get_repo();
		$branch = RJM_CSS_Advisor_Settings::get_branch();
		$url    = self::GITHUB_API_BASE . '/repos/' . $repo . '/contents/' . $file_path . '?ref=' . rawurlencode( $branch );

		$headers = $this->github_headers( $token );
		if ( $saved_etag ) {
			$headers['If-None-Match'] = $saved_etag;
		}

		$response = wp_remote_get( $url, [
			'headers' => $headers,
			'timeout' => 15,
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code === 304 ) {
			return new WP_Error( 'not_modified', 'Not modified' );
		}

		if ( $code !== 200 ) {
			$error = new WP_Error(
				'github_error',
				sprintf( __( 'GitHub API returned HTTP %d for file "%s".', 'rjm-css-advisor' ), $code, $file_path )
			);
			$this->log_debug_error( 'fetch_github_file', $error, [
				'file_path' => $file_path,
				'http_code' => $code,
			] );
			return $error;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['content'] ) ) {
			$error = new WP_Error( 'empty_content', __( 'GitHub returned an empty file.', 'rjm-css-advisor' ) );
			$this->log_debug_error( 'fetch_github_file', $error, [
				'file_path' => $file_path,
			] );
			return $error;
		}

		$etag    = wp_remote_retrieve_header( $response, 'etag' );
		$content = base64_decode( str_replace( "\n", '', $body['content'] ) );

		return [ 'content' => $content, 'etag' => $etag ];
	}

	/**
	 * Generate global-CSS advice (no component file — describes all global selectors).
	 */
	private function get_global_advice( $token ) {
		$cache_key = 'rjm_css_global';
		$cached    = get_transient( $cache_key );

		if ( $cached ) {
			return [ 'advice' => $cached, 'from_cache' => true ];
		}

		// For the global CSS field we give Copilot a curated list of the most
		// commonly used root-level CSS custom properties and shared classes.
		$context = $this->build_global_site_context( $token );

		$advice = $this->call_copilot_with_context(
			$token,
			'Global Custom CSS (Theme Settings)',
			$context,
			$this->get_global_system_prompt()
		);

		if ( is_wp_error( $advice ) ) {
			return $advice;
		}

		set_transient( $cache_key, $advice, RJM_CSS_Advisor_Settings::get_cache_ttl() );
		return [ 'advice' => $advice, 'from_cache' => false ];
	}

	/**
	 * Call GitHub Copilot to generate CSS advice for a component.
	 */
	private function call_copilot( $token, $component_name, $source_code, $is_sub_field = false, $field_name = 'custom_css' ) {
		$sub_context = '';
		if ( $is_sub_field && isset( self::SUBFIELD_LABELS[ $field_name ] ) ) {
			$sub_context = sprintf(
				"\n\nNote: this CSS field targets %s within the %s component, not the whole section.",
				self::SUBFIELD_LABELS[ $field_name ],
				$component_name
			);
		}

		$system_prompt = $this->get_component_system_prompt();
		$user_message  = "Component name: {$component_name}{$sub_context}\n\nSource code:\n```jsx\n{$source_code}\n```";

		return $this->call_copilot_with_context( $token, $component_name, $user_message, $system_prompt );
	}

	/**
	 * Make the actual AI chat/completions API request.
	 * Routes to OpenAI or GitHub Copilot depending on the saved ai_provider setting.
	 */
	private function call_copilot_with_context( $token, $label, $user_message, $system_prompt, $screenshot_data = '' ) {
		$provider = RJM_CSS_Advisor_Settings::get_ai_provider();
		$model    = RJM_CSS_Advisor_Settings::get_model();
		$user_content = $user_message;
		if ( $screenshot_data && 'openai' === $provider ) {
			$user_content = [
				[ 'type' => 'text', 'text' => $user_message ],
				[ 'type' => 'image_url', 'image_url' => [ 'url' => $screenshot_data, 'detail' => 'auto' ] ],
			];
		} elseif ( $screenshot_data ) {
			$user_content .= "\n\nNote: A screenshot is attached, but this provider cannot inspect images. Do not claim to have analyzed it; ask the user to describe the visual details in text.";
		}

		$body = wp_json_encode( [
			'model'    => $model,
			'messages' => [
				[ 'role' => 'system', 'content' => $system_prompt ],
				[ 'role' => 'user',   'content' => $user_content  ],
			],
		] );

		if ( $provider === 'openai' ) {
			return $this->call_openai( $body );
		}

		return $this->call_copilot_api( $token, $body );
	}

	/**
	 * Call the GitHub Copilot Business API.
	 */
	private function call_copilot_api( $token, $body ) {
		$response = wp_remote_post(
			self::COPILOT_API_BASE . '/chat/completions',
			[
				'headers' => [
					'Authorization'          => 'Bearer ' . $token,
					'Content-Type'           => 'application/json',
					'Copilot-Integration-Id' => 'rjm-css-advisor',
					'Editor-Version'         => 'rjm-css-advisor/1.0.0',
				],
				'body'    => $body,
				'timeout' => 60,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code === 401 ) {
			$error = new WP_Error( 'copilot_auth', __( 'Copilot authentication failed. Ensure your PAT has access to a Copilot Business seat.', 'rjm-css-advisor' ) );
			$this->log_debug_error( 'call_copilot_api', $error, [ 'http_code' => $code ] );
			return $error;
		}

		if ( $code !== 200 ) {
			$error_body = json_decode( wp_remote_retrieve_body( $response ), true );
			$error_msg  = $error_body['error']['message'] ?? sprintf( __( 'Copilot API returned HTTP %d.', 'rjm-css-advisor' ), $code );
			$error = new WP_Error( 'copilot_error', $error_msg );
			$this->log_debug_error( 'call_copilot_api', $error, [ 'http_code' => $code ] );
			return $error;
		}

		return $this->extract_ai_content( $response );
	}

	/**
	 * Call the OpenAI API.
	 */
	private function call_openai( $body ) {
		$openai_key = RJM_CSS_Advisor_Settings::get_openai_key();
		if ( ! $openai_key ) {
			return new WP_Error( 'no_openai_key', __( 'No OpenAI API key configured. Please visit Settings → RJM CSS Advisor and add your OpenAI key.', 'rjm-css-advisor' ) );
		}

		$response = wp_remote_post(
			self::OPENAI_API_BASE . '/chat/completions',
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $openai_key,
					'Content-Type'  => 'application/json',
				],
				'body'    => $body,
				'timeout' => 60,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code === 401 ) {
			$error = new WP_Error( 'openai_auth', __( 'OpenAI authentication failed. Check that your API key is correct and has not expired.', 'rjm-css-advisor' ) );
			$this->log_debug_error( 'call_openai', $error, [ 'http_code' => $code ] );
			return $error;
		}

		if ( $code === 429 ) {
			$error = new WP_Error( 'openai_rate_limit', __( 'OpenAI rate limit reached. Please wait a moment and try again.', 'rjm-css-advisor' ) );
			$this->log_debug_error( 'call_openai', $error, [ 'http_code' => $code ] );
			return $error;
		}

		if ( $code !== 200 ) {
			$error_body = json_decode( wp_remote_retrieve_body( $response ), true );
			$error_msg  = $error_body['error']['message'] ?? sprintf( __( 'OpenAI API returned HTTP %d.', 'rjm-css-advisor' ), $code );
			$error = new WP_Error( 'openai_error', $error_msg );
			$this->log_debug_error( 'call_openai', $error, [ 'http_code' => $code ] );
			return $error;
		}

		return $this->extract_ai_content( $response );
	}

	/**
	 * Extract the text content from a successful OpenAI-compatible API response.
	 */
	private function extract_ai_content( $response ) {
		$data    = json_decode( wp_remote_retrieve_body( $response ), true );
		$content = $data['choices'][0]['message']['content'] ?? '';

		if ( ! $content ) {
			$error = new WP_Error( 'empty_advice', __( 'The AI returned an empty response.', 'rjm-css-advisor' ) );
			$this->log_debug_error( 'extract_ai_content', $error );
			return $error;
		}

		return $content;
	}

	// -------------------------------------------------------------------------
	// Prompt helpers
	// -------------------------------------------------------------------------

	/**
	 * Strip markdown code fences (e.g. ```css ... ```) that the AI may include
	 * despite being instructed to output raw CSS only.
	 */
	private static function strip_code_fences( $text ) {
		$text = trim( $text );
		$text = preg_replace( '/^```[a-z]*\s*/i', '', $text );
		$text = preg_replace( '/\s*```\s*$/i', '', $text );
		return trim( $text );
	}

	/**
	 * System prompt for CSS code generation.
	 *
	 * @param bool $is_global  True when generating for the global CSS field.
	 */
	private function get_css_generator_system_prompt( $is_global = false ) {
		if ( $is_global ) {
			return <<<'PROMPT'
You are a CSS code writer for a coaching website built with React and Gatsby using Bootstrap 5.

You will receive a description of the site's global CSS variables and shared classes, plus a user's styling goal. Write the minimal CSS needed to achieve that goal globally across the site.

Output rules — follow these exactly:
1. Output ONLY valid JSON. No explanations, no prose, no markdown fences.
2. Return an object with these keys: css, explanation, follow_up_questions, recommendations.
3. css must contain ONLY valid CSS, with no markdown fences.
4. explanation must be a short plain-language summary of what changed.
5. follow_up_questions and recommendations must be arrays of short strings.
6. Use the CSS custom properties (--variables) and class names described in the context.
7. Include /* short comment */ on lines where the value should be customised (e.g. colours, sizes).
8. Prefer :root {} CSS custom property overrides where applicable.
PROMPT;
		}

		return <<<'PROMPT'
You are a CSS code writer for a coaching website built with React and Gatsby using Bootstrap 5.

You will receive a React component's source code and a user's styling goal. Write the minimal CSS needed to achieve that goal.

Output rules — follow these exactly:
1. Output ONLY valid JSON. No explanations, no prose, no markdown fences.
2. Return an object with these keys: css, explanation, follow_up_questions, recommendations.
3. css must contain ONLY valid CSS, with no markdown fences.
4. explanation must be a short plain-language summary of what changed.
5. follow_up_questions and recommendations must be arrays of short strings.
6. Use the exact CSS class names found in the component source code.
7. Include /* short comment */ on lines where the value should be customised (e.g. colours, sizes).
			8. Do not include @media blocks unless the user explicitly requests responsive or breakpoint-specific behavior.
			9. If one or more breakpoints are selected, output CSS ONLY inside @media blocks for those selected breakpoints.
			10. When breakpoints are selected, do NOT output any base/root selector rules outside @media blocks.
			11. If no breakpoints are selected, do NOT output responsive media queries.
			12. Use only these responsive media queries when applicable:
   - Mobile:  @media (max-width: 767.98px)
   - Tablet:  @media (min-width: 768px) and (max-width: 991.98px)
   - Desktop: @media (min-width: 992px)
13. Keep output concise and targeted to the stated goal only.
PROMPT;
	}

	/**
	 * Build a short instruction block describing the selected breakpoints.
	 *
	 * @param array $breakpoints
	 * @return string
	 */
	private function build_breakpoint_context( $breakpoints ) {
		$labels = [];
		$map    = [
			'mobile'  => 'Mobile',
			'tablet'  => 'Tablet',
			'desktop' => 'Desktop',
		];

		foreach ( (array) $breakpoints as $breakpoint ) {
			$breakpoint = sanitize_key( $breakpoint );
			if ( isset( $map[ $breakpoint ] ) ) {
				$labels[] = $map[ $breakpoint ];
			}
		}

		if ( ! $labels ) {
			return "\n\nNo breakpoints selected. Output base CSS only and do not include responsive media queries.";
		}

		return "\n\nSelected breakpoints: " . implode( ', ', $labels ) . "\nOutput ONLY @media blocks for these selected breakpoints. Do NOT include any base selector rules outside @media.";
	}

	/**
	 * Enforce breakpoint policy for generated component CSS.
	 *
	 * @param string $token
	 * @param string $user_message
	 * @param string $system_prompt
	 * @param array  $parsed
	 * @param array  $selected_breakpoints
	 * @return array
	 */
	private function enforce_breakpoint_policy( $token, $user_message, $system_prompt, $parsed, $selected_breakpoints ) {
		$css      = (string) ( $parsed['css'] ?? '' );
		$selected = $this->normalize_selected_breakpoints( $selected_breakpoints );

		$policy = $this->validate_breakpoint_css_policy( $css, $selected );
		if ( $policy['valid'] ) {
			return $parsed;
		}

		$retry_message = $user_message . "\n\nPolicy correction required:\n" . $this->build_breakpoint_policy_correction_text( $selected );
		$retry_raw     = $this->call_copilot_with_context( $token, 'CSS Generator Policy Correction', $retry_message, $system_prompt );

		if ( ! is_wp_error( $retry_raw ) ) {
			$retry_parsed = $this->parse_structured_css_response( $retry_raw );
			$retry_policy = $this->validate_breakpoint_css_policy( (string) ( $retry_parsed['css'] ?? '' ), $selected );

			if ( $retry_policy['valid'] ) {
				return $this->append_policy_recommendation( $retry_parsed, __( 'Breakpoint-only output policy was enforced automatically.', 'rjm-css-advisor' ) );
			}

			$retry_repaired = $this->repair_css_for_breakpoint_policy( (string) ( $retry_parsed['css'] ?? '' ), $selected );
			if ( $this->validate_breakpoint_css_policy( $retry_repaired, $selected )['valid'] ) {
				$retry_parsed['css'] = $retry_repaired;
				return $this->append_policy_recommendation( $retry_parsed, __( 'Breakpoint-only output policy was enforced by filtering unsupported rules.', 'rjm-css-advisor' ) );
			}
		}

		$repaired = $this->repair_css_for_breakpoint_policy( $css, $selected );
		if ( $this->validate_breakpoint_css_policy( $repaired, $selected )['valid'] ) {
			$parsed['css'] = $repaired;
			return $this->append_policy_recommendation( $parsed, __( 'Breakpoint-only output policy was enforced by filtering unsupported rules.', 'rjm-css-advisor' ) );
		}

		return $this->append_policy_recommendation( $parsed, __( 'Generated CSS did not fully match breakpoint policy. Review before inserting.', 'rjm-css-advisor' ) );
	}

	/**
	 * Validate whether generated CSS respects selected breakpoint policy.
	 *
	 * @param string $css
	 * @param array  $selected_breakpoints
	 * @return array{valid: bool, reason: string}
	 */
	private function validate_breakpoint_css_policy( $css, $selected_breakpoints ) {
		$blocks = $this->extract_css_top_level_blocks( $css );
		if ( $blocks === null ) {
			return [ 'valid' => false, 'reason' => 'parse_failed' ];
		}

		$allowed_headers = $this->get_selected_media_headers( $selected_breakpoints );

		if ( empty( $selected_breakpoints ) ) {
			foreach ( $blocks as $block ) {
				if ( $this->is_media_header( $block['header'] ) ) {
					return [ 'valid' => false, 'reason' => 'unexpected_media_block' ];
				}
			}

			return [ 'valid' => true, 'reason' => '' ];
		}

		$has_selected_media = false;
		foreach ( $blocks as $block ) {
			$header = $this->normalize_media_header( $block['header'] );

			if ( ! $this->is_media_header( $block['header'] ) ) {
				return [ 'valid' => false, 'reason' => 'base_rule_present' ];
			}

			if ( ! isset( $allowed_headers[ $header ] ) ) {
				return [ 'valid' => false, 'reason' => 'unselected_media_block_present' ];
			}

			$has_selected_media = true;
		}

		if ( ! $has_selected_media ) {
			return [ 'valid' => false, 'reason' => 'no_selected_media_blocks' ];
		}

		return [ 'valid' => true, 'reason' => '' ];
	}

	/**
	 * Repair CSS to match selected breakpoint policy by filtering top-level blocks.
	 *
	 * @param string $css
	 * @param array  $selected_breakpoints
	 * @return string
	 */
	private function repair_css_for_breakpoint_policy( $css, $selected_breakpoints ) {
		$blocks = $this->extract_css_top_level_blocks( $css );
		if ( $blocks === null ) {
			return trim( $css );
		}

		$selected = $this->normalize_selected_breakpoints( $selected_breakpoints );
		$allowed_headers = $this->get_selected_media_headers( $selected );
		$kept = [];

		foreach ( $blocks as $block ) {
			$header = $this->normalize_media_header( $block['header'] );

			if ( empty( $selected ) ) {
				if ( ! $this->is_media_header( $block['header'] ) ) {
					$kept[] = trim( $block['raw'] );
				}
				continue;
			}

			if ( $this->is_media_header( $block['header'] ) && isset( $allowed_headers[ $header ] ) ) {
				$kept[] = trim( $block['raw'] );
			}
		}

		$output = trim( implode( "\n\n", array_filter( $kept ) ) );
		return $output ? $output . "\n" : '';
	}

	/**
	 * Extract top-level CSS blocks and preserve raw text for safe filtering.
	 *
	 * @param string $css
	 * @return array<int, array{header: string, raw: string}>|null
	 */
	private function extract_css_top_level_blocks( $css ) {
		$css    = (string) $css;
		$length = strlen( $css );
		$index  = 0;
		$blocks = [];

		while ( $index < $length ) {
			$index = $this->skip_css_whitespace_and_comments( $css, $index );

			if ( $index >= $length ) {
				break;
			}

			$start = $index;
			$brace = strpos( $css, '{', $index );
			if ( $brace === false ) {
				break;
			}

			$header = trim( substr( $css, $index, $brace - $index ) );
			$end    = $this->find_css_matching_brace( $css, $brace );
			if ( $end === -1 ) {
				return null;
			}

			$blocks[] = [
				'header' => $header,
				'raw'    => substr( $css, $start, $end - $start + 1 ),
			];

			$index = $end + 1;
		}

		return $blocks;
	}

	/**
	 * Skip CSS whitespace/comments while scanning blocks.
	 *
	 * @param string $css
	 * @param int    $index
	 * @return int
	 */
	private function skip_css_whitespace_and_comments( $css, $index ) {
		$length = strlen( $css );

		while ( $index < $length ) {
			$char = $css[ $index ];

			if ( ctype_space( $char ) ) {
				$index++;
				continue;
			}

			if ( $char === '/' && isset( $css[ $index + 1 ] ) && $css[ $index + 1 ] === '*' ) {
				$comment_end = strpos( $css, '*/', $index + 2 );
				if ( $comment_end === false ) {
					return $length;
				}
				$index = $comment_end + 2;
				continue;
			}

			break;
		}

		return $index;
	}

	/**
	 * Find matching closing brace while respecting comments and strings.
	 *
	 * @param string $css
	 * @param int    $open_index
	 * @return int
	 */
	private function find_css_matching_brace( $css, $open_index ) {
		$depth      = 0;
		$in_single  = false;
		$in_double  = false;
		$in_comment = false;

		for ( $index = $open_index, $length = strlen( $css ); $index < $length; $index++ ) {
			$char = $css[ $index ];
			$next = isset( $css[ $index + 1 ] ) ? $css[ $index + 1 ] : '';

			if ( $in_comment ) {
				if ( $char === '*' && $next === '/' ) {
					$in_comment = false;
					$index++;
				}
				continue;
			}

			if ( $in_single ) {
				if ( $char === '\\' ) {
					$index++;
				} elseif ( $char === "'" ) {
					$in_single = false;
				}
				continue;
			}

			if ( $in_double ) {
				if ( $char === '\\' ) {
					$index++;
				} elseif ( $char === '"' ) {
					$in_double = false;
				}
				continue;
			}

			if ( $char === '/' && $next === '*' ) {
				$in_comment = true;
				$index++;
				continue;
			}

			if ( $char === "'" ) {
				$in_single = true;
				continue;
			}

			if ( $char === '"' ) {
				$in_double = true;
				continue;
			}

			if ( $char === '{' ) {
				$depth++;
			} elseif ( $char === '}' ) {
				$depth--;
				if ( $depth === 0 ) {
					return $index;
				}
			}
		}

		return -1;
	}

	/**
	 * Normalize selected breakpoints to known values.
	 *
	 * @param array $breakpoints
	 * @return array
	 */
	private function normalize_selected_breakpoints( $breakpoints ) {
		$allowed = [ 'mobile' => true, 'tablet' => true, 'desktop' => true ];
		$selected = [];

		foreach ( (array) $breakpoints as $breakpoint ) {
			$key = sanitize_key( $breakpoint );
			if ( isset( $allowed[ $key ] ) ) {
				$selected[ $key ] = true;
			}
		}

		return array_keys( $selected );
	}

	/**
	 * Build selected media header map.
	 *
	 * @param array $selected_breakpoints
	 * @return array<string, bool>
	 */
	private function get_selected_media_headers( $selected_breakpoints ) {
		$map = [
			'mobile'  => '@media (max-width: 767.98px)',
			'tablet'  => '@media (min-width: 768px) and (max-width: 991.98px)',
			'desktop' => '@media (min-width: 992px)',
		];

		$headers = [];
		foreach ( (array) $selected_breakpoints as $breakpoint ) {
			if ( isset( $map[ $breakpoint ] ) ) {
				$headers[ $this->normalize_media_header( $map[ $breakpoint ] ) ] = true;
			}
		}

		return $headers;
	}

	/**
	 * Normalize media header for matching.
	 *
	 * @param string $header
	 * @return string
	 */
	private function normalize_media_header( $header ) {
		$header = preg_replace( '/\s+/', ' ', (string) $header );
		return strtolower( trim( (string) $header ) );
	}

	/**
	 * Check whether header is a media query block.
	 *
	 * @param string $header
	 * @return bool
	 */
	private function is_media_header( $header ) {
		return (bool) preg_match( '/^@media\b/i', trim( (string) $header ) );
	}

	/**
	 * Build correction text for strict policy retry.
	 *
	 * @param array $selected_breakpoints
	 * @return string
	 */
	private function build_breakpoint_policy_correction_text( $selected_breakpoints ) {
		$selected = $this->normalize_selected_breakpoints( $selected_breakpoints );

		if ( empty( $selected ) ) {
			return "No breakpoints are selected. Output base CSS only with no @media blocks.";
		}

		$headers = array_keys( $this->get_selected_media_headers( $selected ) );
		return "Output ONLY @media blocks for these selected breakpoints: " . implode( '; ', $headers ) . ". Do NOT output base selector rules outside @media.";
	}

	/**
	 * Append a policy note to recommendations.
	 *
	 * @param array  $parsed
	 * @param string $message
	 * @return array
	 */
	private function append_policy_recommendation( $parsed, $message ) {
		$parsed['recommendations'] = $this->normalize_string_list( $parsed['recommendations'] ?? [] );
		$parsed['recommendations'][] = trim( (string) $message );
		$parsed['recommendations'] = array_values( array_unique( array_filter( $parsed['recommendations'] ) ) );
		return $parsed;
	}

	/**
	 * Parse the AI response into the structured payload expected by the UI.
	 *
	 * @param string $response
	 * @return array
	 */
	private function parse_structured_css_response( $response ) {
		$clean = trim( self::strip_code_fences( $response ) );
		$decoded = json_decode( $clean, true );

		if ( ! is_array( $decoded ) ) {
			return [
				'css'                 => $clean,
				'explanation'         => '',
				'follow_up_questions' => [],
				'recommendations'     => [],
			];
		}

		return [
			'css'                 => trim( (string) ( $decoded['css'] ?? $clean ) ),
			'explanation'         => trim( (string) ( $decoded['explanation'] ?? '' ) ),
			'follow_up_questions' => $this->normalize_string_list( $decoded['follow_up_questions'] ?? $decoded['questions'] ?? [] ),
			'recommendations'     => $this->normalize_string_list( $decoded['recommendations'] ?? [] ),
		];
	}

	/**
	 * Normalize a JSON array or scalar into a flat string list.
	 *
	 * @param mixed $value
	 * @return array
	 */
	private function normalize_string_list( $value ) {
		if ( is_string( $value ) ) {
			$value = [ $value ];
		}

		if ( ! is_array( $value ) ) {
			return [];
		}

		return array_values( array_filter( array_map( static function ( $item ) {
			return trim( (string) $item );
		}, $value ) ) );
	}

	private function get_component_system_prompt() {
		return <<<'PROMPT'
You are a friendly CSS advisor helping a non-technical web designer customise a coaching website built with React and Gatsby. You will be given the source code of a website component.

Your task is to produce clear, practical CSS advice structured as follows:

## Available CSS Classes

List every CSS class name found in the component as a bullet list. Group related classes together (e.g. "Heading & Text", "Buttons", "Layout", "Images"). Use plain language to describe what each class controls.

## Ready-to-Use CSS Snippets

Provide 5–8 copy-paste CSS snippets for the most common customisations a web designer would want to make. Each snippet must:
- Have a short plain-English heading (e.g. "Change the heading font size")
- Show the exact class selector and property to edit
- Include a helpful comment explaining the value they should change

Focus on: text size/colour/font, background colours, padding/spacing, button styles, and mobile (max-width: 767px) overrides.

## Tips

Add 2–3 short tips specific to this component (e.g. "This section has a background image — make sure text colours contrast well").

Keep all language simple and friendly. Do not use technical jargon. Assume the reader knows what CSS is but is not a developer.
PROMPT;
	}

	private function get_global_system_prompt() {
		return <<<'PROMPT'
You are a friendly CSS advisor helping a non-technical web designer customise a coaching website. The Global Custom CSS field applies styles across the ENTIRE website — every page.

Your task is to produce clear, practical CSS advice structured as follows:

## What the Global CSS Field Does

Briefly explain that styles written here affect the whole website, not just one section.

## Common Global Customisations

Provide 6–10 copy-paste CSS snippets for the most useful global changes:
- Changing the site's primary font family (body text)
- Changing heading fonts
- Changing the primary button colour and hover colour
- Setting a global link colour
- Adjusting global body font size
- Hiding a specific section on mobile
- Adding a custom scrollbar style
- Setting a global page background colour

Each snippet must have a plain-English heading and inline comments explaining what to change.

## CSS Custom Properties (Design Tokens)

Explain that the site uses CSS variables (custom properties) for colours. List the key ones they can override:
- --primary-colour
- --secondary-colour
- --primary-cta-colour
- --primary-cta-hover-colour
- --primary-cta-text-colour
- --primary-cta-hover-text-colour

Show an example of overriding them inside :root {}.

## Tips

3 tips about using global CSS safely (e.g. "Test on mobile after any change", "Be careful with font-size — it can affect the whole layout").

Keep all language simple and friendly.
PROMPT;
	}

	private function get_global_css_context() {
		return <<<'CONTEXT'
This is the Global Custom CSS field in the Theme Settings of a coaching website.
Styles written here apply site-wide across all pages.
The site uses Bootstrap 5 for its grid and utility classes.
Common shared component CSS classes include:
- .hero-section, .hero-heading, .hero-subheading, .hero-body, .hero-cta-button
- .cta-banner-section, .cta-banner-heading, .cta-banner-description
- .banner-container, .banner-text
- .footer-container, .footer-copyright-text, .footer-cta-button
- .navbar (Bootstrap NavBar)
- Buttons use .btn-primary and .cta-secondary classes
- CSS custom properties: --primary-colour, --secondary-colour, --primary-cta-colour, --primary-cta-hover-colour, --primary-cta-text-colour, --primary-cta-hover-text-colour
CONTEXT;
	}

	/**
	 * Build a site-wide global CSS context summary from shared source files.
	 *
	 * Falls back to the curated static context if repository discovery fails.
	 *
	 * @param string $token
	 * @return string
	 */
	private function build_global_site_context( $token ) {
		$repo      = RJM_CSS_Advisor_Settings::get_repo();
		$branch    = RJM_CSS_Advisor_Settings::get_branch();
		$cache_key = 'rjm_css_global_context_' . md5( $repo . '|' . $branch );
		$cached    = get_transient( $cache_key );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$fallback_context = $this->get_global_css_context();
		$file_paths       = [
			'src/utls/DynamicCtaStyles.js',
			'src/styles/global.scss',
			'src/components/Layout.js',
			'src/components/NavBar.js',
			'src/components/Footer.js',
			'src/components/Hero.js',
			'src/components/BlogCta.js',
		];

		$file_contents = $this->fetch_repository_files( $token, $file_paths );
		if ( empty( $file_contents ) ) {
			return $fallback_context;
		}

		$selectors         = [];
		$custom_properties = [];
		foreach ( $file_contents as $source ) {
			$selectors         = array_merge( $selectors, $this->extract_jsx_class_names( $source ) );
			$custom_properties = array_merge( $custom_properties, $this->extract_custom_properties_from_source( $source ) );
		}

		$selectors         = array_slice( array_values( array_unique( $selectors ) ), 0, 40 );
		$custom_properties = array_slice( array_values( array_unique( $custom_properties ) ), 0, 20 );
		$components        = array_values( array_unique( array_values( self::LAYOUT_TO_COMPONENT ) ) );
		sort( $components );

		$context  = "This is the Global Custom CSS field in the Theme Settings of a coaching website.\n";
		$context .= "Styles written here apply site-wide across all pages and should prefer shared selectors, shared button styles, and theme tokens when possible.\n";
		$context .= "The site uses Bootstrap 5, shared CTA classes, reusable components, and global design tokens.\n\n";
		$context .= "Component inventory:\n- " . implode( ', ', array_slice( $components, 0, 30 ) ) . "\n\n";

		if ( ! empty( $selectors ) ) {
			$context .= "Shared selectors discovered from the codebase:\n- ." . implode( "\n- .", $selectors ) . "\n\n";
		}

		if ( ! empty( $custom_properties ) ) {
			$context .= "Theme custom properties discovered from the codebase:\n- " . implode( "\n- ", $custom_properties ) . "\n\n";
		}

		$context .= "Common global targets include CTA buttons, hero sections, blog CTAs, the site navbar, footer elements, typography tokens, and shared spacing/hover states.\n";
		$context .= "When making global changes, prefer stable shared selectors or variables over one-off page-specific selectors unless the user asks for a narrow override.";

		set_transient( $cache_key, $context, RJM_CSS_Advisor_Settings::get_cache_ttl() );

		return $context;
	}

	/**
	 * Build component or global context text for planner/builder modes.
	 *
	 * @param string $token
	 * @param string $layout_name
	 * @param string $field_name
	 * @param bool   $is_global
	 * @return array|WP_Error
	 */
	private function build_component_or_global_context( $token, $layout_name, $field_name, $is_global ) {
		if ( $is_global ) {
			return [
				'label'   => 'Global CSS',
				'context' => $this->build_global_site_context( $token ),
			];
		}

		$component = self::LAYOUT_TO_COMPONENT[ $layout_name ] ?? null;
		if ( ! $component ) {
			$error = new WP_Error(
				'unknown_layout',
				sprintf( __( 'No component mapping found for layout "%s".', 'rjm-css-advisor' ), $layout_name )
			);
			$this->log_debug_error( 'build_component_or_global_context', $error, [
				'layout'    => $layout_name,
				'field'     => $field_name,
				'is_global' => $is_global,
			] );
			return $error;
		}

		$file_path = 'src/components/' . $component . '.js';
		$result    = $this->fetch_github_file( $token, $file_path );
		if ( is_wp_error( $result ) ) {
			$this->log_debug_error( 'build_component_or_global_context', $result, [
				'layout'    => $layout_name,
				'field'     => $field_name,
				'is_global' => $is_global,
				'file_path' => $file_path,
			] );
			return $result;
		}

		$sub_context = '';
		if ( in_array( $field_name, self::SUBFIELD_NAMES, true ) && isset( self::SUBFIELD_LABELS[ $field_name ] ) ) {
			$sub_context = "\nNote: the CSS targets " . self::SUBFIELD_LABELS[ $field_name ] . " within this component.";
		}

		return [
			'label'   => $component,
			'context' => "Component: {$component}{$sub_context}\n\nSource code:\n```jsx\n" . $result['content'] . "\n```",
		];
	}

	/**
	 * Convert stored chat messages into a deterministic prompt transcript.
	 *
	 * @param array $messages
	 * @return string
	 */
	private function format_chat_messages_for_prompt( $messages ) {
		$lines = [];
		foreach ( (array) $messages as $message ) {
			$role    = sanitize_key( $message['role'] ?? 'user' );
			$content = trim( (string) ( $message['content'] ?? '' ) );
			if ( ! $content ) {
				continue;
			}
			$attachment_note = ! empty( $message['screenshot']['data'] ) ? ' [Screenshot attached]' : '';
			$lines[] = strtoupper( $role ) . $attachment_note . ': ' . $content;
		}

		return $lines ? implode( "\n", $lines ) : 'USER: (no conversation yet)';
	}

	/**
	 * Return the most recent validated screenshot from a plan conversation.
	 *
	 * @param array $messages
	 * @return string
	 */
	private function get_latest_screenshot_data( $messages ) {
		foreach ( array_reverse( (array) $messages ) as $message ) {
			$data = (string) ( $message['screenshot']['data'] ?? '' );
			if ( $data ) {
				return $data;
			}
		}

		return '';
	}

	/**
	 * Fetch a set of repository files, skipping any that fail.
	 *
	 * @param string $token
	 * @param array  $file_paths
	 * @return array<string,string>
	 */
	private function fetch_repository_files( $token, $file_paths ) {
		$contents = [];
		foreach ( (array) $file_paths as $file_path ) {
			$result = $this->fetch_github_file( $token, $file_path );
			if ( is_wp_error( $result ) || empty( $result['content'] ) ) {
				continue;
			}

			$contents[ $file_path ] = (string) $result['content'];
		}

		return $contents;
	}

	/**
	 * Extract JSX class names from component source.
	 *
	 * @param string $source
	 * @return array
	 */
	private function extract_jsx_class_names( $source ) {
		preg_match_all( '/className\s*=\s*(?:\{`([^`]+)`\}|"([^"]+)"|\'([^\']+)\')/m', (string) $source, $matches, PREG_SET_ORDER );
		$class_names = [];

		foreach ( $matches as $match ) {
			$raw = '';
			for ( $index = 1; $index <= 3; $index++ ) {
				if ( ! empty( $match[ $index ] ) ) {
					$raw = (string) $match[ $index ];
					break;
				}
			}

			$raw = preg_replace( '/\$\{[^}]+\}/', ' ', $raw );
			foreach ( preg_split( '/\s+/', trim( (string) $raw ) ) as $token ) {
				$token = trim( (string) $token );
				if ( '' === $token || preg_match( '/[^a-zA-Z0-9_-]/', $token ) ) {
					continue;
				}

				if ( $this->is_utility_class_name( $token ) ) {
					continue;
				}

				$class_names[] = $token;
			}
		}

		sort( $class_names );

		return $class_names;
	}

	/**
	 * Extract custom property tokens from source.
	 *
	 * @param string $source
	 * @return array
	 */
	private function extract_custom_properties_from_source( $source ) {
		preg_match_all( '/--[a-z0-9-]+/i', (string) $source, $matches );
		$properties = array_values( array_unique( $matches[0] ?? [] ) );
		sort( $properties );

		return $properties;
	}

	/**
	 * Filter obvious Bootstrap/layout utility classes from global selector summaries.
	 *
	 * @param string $class_name
	 * @return bool
	 */
	private function is_utility_class_name( $class_name ) {
		return (bool) preg_match(
			'/^(?:btn|container(?:-[a-z]+)?|row|col(?:-[a-z0-9-]+)?|d-[a-z]+|w-\d+|h-\d+|m[trblxy]?-\d+|p[trblxy]?-\d+|g-\d+|gap-\d+|text-[a-z0-9-]+|bg-[a-z0-9-]+|justify-content-[a-z-]+|align-items-[a-z-]+|position-[a-z-]+|sticky-top|overflow-[a-z-]+|fs-\d+|me-\d+|ms-\d+|mt-\d+|mb-\d+|pt-\d+|pb-\d+|px-\d+|py-\d+|mx-auto)$/',
			(string) $class_name
		);
	}

	/**
	 * Store a sanitized GitHub client error entry in the admin debug console.
	 *
	 * @param string           $action
	 * @param \WP_Error|mixed $error
	 * @param array            $details
	 * @return void
	 */
	private function log_debug_error( $action, $error, $details = [] ) {
		if ( is_wp_error( $error ) ) {
			$details['error_code']    = $error->get_error_code();
			$details['error_message'] = $error->get_error_message();
		}

		RJM_CSS_Advisor_Settings::add_debug_entry( 'github_client', $action, 'error', $details );
	}

	/**
	 * System prompt for Ask/Plan turn replies.
	 *
	 * @return string
	 */
	private function get_css_planner_system_prompt() {
		return <<<'PROMPT'
You are a CSS planning assistant inside a WordPress editor workflow.

Goal:
- Help the user clarify exactly what CSS should be generated.
- Ask concise follow-up questions when needed.
- Keep a running brief of agreed decisions.

Output rules:
1. Return ONLY valid JSON.
2. Keys required: assistant_message, ready_to_generate, brief.
3. assistant_message should be concise and practical.
4. ready_to_generate should be true only when enough detail exists to produce CSS.
5. brief should be a compact summary the generator can use directly.
PROMPT;
	}

	/**
	 * System prompt for creating step-by-step build plans.
	 *
	 * @return string
	 */
	private function get_css_build_planner_system_prompt() {
		return <<<'PROMPT'
You are planning CSS implementation work into small steps.

Output rules:
1. Return ONLY valid JSON.
2. Keys: title, steps.
3. steps must be an array of 3-6 short step descriptions.
4. Each step should focus on one styling objective and be implementation-ready.
PROMPT;
	}

	/**
	 * System prompt for generating one build step snippet.
	 *
	 * @param bool $is_global
	 * @return string
	 */
	private function get_css_builder_system_prompt( $is_global = false ) {
		if ( $is_global ) {
			return <<<'PROMPT'
You are a CSS builder generating one global CSS snippet at a time.

Output rules:
1. Return ONLY valid JSON with keys: css, explanation, follow_up_questions, recommendations.
2. css must contain only the snippet for the current step.
3. Keep css concise and focused to one objective.
4. Do not include markdown fences.
PROMPT;
		}

		return <<<'PROMPT'
You are a CSS builder generating one component CSS snippet at a time.

Output rules:
1. Return ONLY valid JSON with keys: css, explanation, follow_up_questions, recommendations.
2. css must contain only the snippet for the current step.
3. Use only class names present in the provided component source.
4. Respect responsive instructions exactly when breakpoints are selected.
5. Keep css concise and focused to one objective.
6. Do not include markdown fences.
PROMPT;
	}

	/**
	 * Build an existing CSS context block with smart compaction to control token usage.
	 *
	 * @param string $css
	 * @return string
	 */
	private function build_existing_css_context_block( $css ) {
		$css = trim( (string) $css );
		if ( ! $css ) {
			return '';
		}

		if ( strlen( $css ) <= 6000 ) {
			return "\n\nExisting Custom CSS (full):\n```css\n{$css}\n```\nUse this as the current source of truth. Prefer editing existing selectors/properties instead of duplicating them.";
		}

		$lines = preg_split( '/\R/', $css ) ?: [];
		$recent_lines = array_slice( $lines, -120 );
		$signal_lines = array_values( array_filter( $lines, static function ( $line ) {
			return (bool) preg_match( '/@keyframes|animation|transition|duration|timing-function|transform/i', (string) $line );
		} ) );

		if ( count( $signal_lines ) > 80 ) {
			$signal_lines = array_slice( $signal_lines, -80 );
		}

		$summary  = "\n\nExisting Custom CSS (smart summary):\n";
		$summary .= '- Total CSS length: ' . strlen( $css ) . " chars\n";
		$summary .= '- Total lines: ' . count( $lines ) . "\n";
		$summary .= "- Priority focus: animation/transition related declarations and most recent lines\n\n";
		$summary .= "Signal lines:\n```css\n" . implode( "\n", $signal_lines ) . "\n```\n\n";
		$summary .= "Recent tail:\n```css\n" . implode( "\n", $recent_lines ) . "\n```\n";
		$summary .= 'Use this as the current source of truth. Prefer updating existing selectors/properties, and avoid duplicate declarations unless necessary.';

		return $summary;
	}

	// -------------------------------------------------------------------------
	// Header helpers
	// -------------------------------------------------------------------------

	private function github_headers( $token ) {
		return [
			'Authorization' => 'Bearer ' . $token,
			'Accept'        => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
			'User-Agent'    => 'RJM-CSS-Advisor/1.0.0',
		];
	}
}
