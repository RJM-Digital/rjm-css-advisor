<?php
/**
 * AJAX handler for CSS advice requests.
 *
 * Endpoint: wp-admin/admin-ajax.php  action=rjm_get_css_advice
 *
 * Expected POST parameters:
 *   nonce       - WordPress nonce (action: rjm_css_advisor)
 *   layout      - ACF flexible content layout name (e.g. "hero")
 *   field       - ACF field name (e.g. "custom_css", "pricing_card_custom_css")
 *   is_global   - "1" if this is the global_custom_css field
 *   force       - "1" to bypass cache and force a fresh Copilot call
 *
 * Responds with JSON:
 *   { success: true,  data: { html: string, from_cache: bool } }
 *   { success: false, data: { message: string } }
 */

defined( 'ABSPATH' ) || exit;

class RJM_CSS_Advisor_Ajax_Handler {

	const SESSION_TTL = HOUR_IN_SECONDS;
	const GLOBAL_CSS_FIELD_KEY = 'field_6964fb66b09f1';
	const MAX_SCREENSHOT_BYTES = 4 * 1024 * 1024;
	const MAX_SCREENSHOT_WIDTH = 4096;
	const MAX_SCREENSHOT_HEIGHT = 4096;

	public static function init() {
		add_action( 'wp_ajax_rjm_get_css_advice', [ __CLASS__, 'handle' ] );
		add_action( 'wp_ajax_rjm_generate_css',   [ __CLASS__, 'handle_generate' ] );
		add_action( 'wp_ajax_rjm_plan_css_chat',  [ __CLASS__, 'handle_plan_chat' ] );
		add_action( 'wp_ajax_rjm_plan_css_generate', [ __CLASS__, 'handle_plan_generate' ] );
		add_action( 'wp_ajax_rjm_build_css_start', [ __CLASS__, 'handle_build_start' ] );
		add_action( 'wp_ajax_rjm_build_css_step',  [ __CLASS__, 'handle_build_step' ] );
	}

	// -------------------------------------------------------------------------
	// Handler — generate CSS from a user-stated goal
	// -------------------------------------------------------------------------

	/**
	 * Handle the rjm_generate_css AJAX action.
	 *
	 * Expected POST parameters:
	 *   nonce       - WordPress nonce (action: rjm_css_advisor)
	 *   layout      - ACF flexible content layout name (e.g. "header_1")
	 *   field       - ACF field name (e.g. "custom_css")
	 *   is_global   - "1" if this is the global_custom_css field
	 *   goal        - Plain-English description of what the user wants to achieve
	 */
	public static function handle_generate() {
		check_ajax_referer( 'rjm_css_advisor', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'rjm-css-advisor' ) ], 403 );
		}

		$layout    = sanitize_key( wp_unslash( $_POST['layout']    ?? '' ) );
		$field     = sanitize_key( wp_unslash( $_POST['field']     ?? 'custom_css' ) );
		$field_key = sanitize_text_field( wp_unslash( $_POST['field_key'] ?? '' ) );
		$is_global = ( ( $_POST['is_global'] ?? '0' ) === '1' );
		$is_global = self::normalize_is_global_request( $field, $field_key, $is_global );
		$goal      = sanitize_textarea_field( wp_unslash( $_POST['goal'] ?? '' ) );
		$post_id   = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		$current_css = self::sanitize_css_payload( wp_unslash( $_POST['current_css'] ?? '' ) );
		$breakpoints = array_values( array_filter( array_map( 'sanitize_key', (array) wp_unslash( $_POST['breakpoints'] ?? [] ) ) ) );

		self::log_debug_request( 'generate', [
			'layout'     => $layout,
			'field'      => $field,
			'is_global'  => $is_global,
			'field_key'  => $field_key,
			'post_id'    => $post_id,
		] );

		if ( ! $goal ) {
			wp_send_json_error( [ 'message' => __( 'Please describe what you want to achieve.', 'rjm-css-advisor' ) ] );
		}

		$scope = self::build_memory_scope( $post_id, $field, $field_key, $layout, $is_global );
		$memory = self::get_field_memory( $scope );
		$existing_css_context = self::resolve_existing_css_context( $current_css, $memory );

		$client = new RJM_CSS_Advisor_GitHub_Client();
		$result = $client->generate_css( $layout, $field, $is_global, $goal, $breakpoints, $existing_css_context );

		if ( is_wp_error( $result ) ) {
			self::log_debug_error( 'generate', $result, [
				'layout'    => $layout,
				'field'     => $field,
				'is_global' => $is_global,
				'post_id'   => $post_id,
			] );
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		$html = self::render_generated_response( $result );

		$memory['current_css']    = $existing_css_context;
		$memory['last_generated'] = (string) ( $result['css'] ?? '' );
		$memory['updated_at']     = time();
		self::set_field_memory( $scope, $memory );

		self::log_debug_success( 'generate', [
			'layout'     => $layout,
			'field'      => $field,
			'is_global'  => $is_global,
			'field_key'  => $field_key,
			'post_id'    => $post_id,
			'css_length' => strlen( (string) ( $result['css'] ?? '' ) ),
		] );

		wp_send_json_success( [ 'html' => $html ] );
	}

	/**
	 * Handle Ask/Plan chat turns.
	 */
	public static function handle_plan_chat() {
		check_ajax_referer( 'rjm_css_advisor', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'rjm-css-advisor' ) ], 403 );
		}

		$layout      = sanitize_key( wp_unslash( $_POST['layout'] ?? '' ) );
		$field       = sanitize_key( wp_unslash( $_POST['field'] ?? 'custom_css' ) );
		$field_key   = sanitize_text_field( wp_unslash( $_POST['field_key'] ?? '' ) );
		$is_global   = ( ( $_POST['is_global'] ?? '0' ) === '1' );
		$is_global   = self::normalize_is_global_request( $field, $field_key, $is_global );
		$message     = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
		$screenshot  = self::validate_screenshot_payload( wp_unslash( $_POST['screenshot_data'] ?? '' ), wp_unslash( $_POST['screenshot_name'] ?? '' ) );
		$session_id  = sanitize_text_field( wp_unslash( $_POST['session_id'] ?? '' ) );
		$breakpoints = array_values( array_filter( array_map( 'sanitize_key', (array) wp_unslash( $_POST['breakpoints'] ?? [] ) ) ) );
		$post_id     = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		$current_css = self::sanitize_css_payload( wp_unslash( $_POST['current_css'] ?? '' ) );

		self::log_debug_request( 'plan_chat', [
			'layout'     => $layout,
			'field'      => $field,
			'is_global'  => $is_global,
			'field_key'  => $field_key,
			'post_id'    => $post_id,
		] );

		if ( ! $message ) {
			wp_send_json_error( [ 'message' => __( 'Please enter a message for Ask/Plan mode.', 'rjm-css-advisor' ) ] );
		}

		if ( is_wp_error( $screenshot ) ) {
			wp_send_json_error( [ 'message' => $screenshot->get_error_message() ] );
		}

		$scope = self::build_memory_scope( $post_id, $field, $field_key, $layout, $is_global );
		$memory = self::get_field_memory( $scope );
		$existing_css_context = self::resolve_existing_css_context( $current_css, $memory );

		if ( ! $session_id ) {
			$session_id = wp_generate_uuid4();
		}

		$session = self::get_plan_session( $session_id );
		if ( ! $session ) {
			$session = [
				'layout'      => $layout,
				'field'       => $field,
				'is_global'   => $is_global,
				'breakpoints' => $breakpoints,
				'messages'    => (array) ( $memory['chat_messages'] ?? [] ),
				'brief'       => '',
				'existing_css_context' => $existing_css_context,
			];
		}

		if ( ! empty( $existing_css_context ) ) {
			$session['existing_css_context'] = $existing_css_context;
		}

		$user_message = [
			'role'    => 'user',
			'content' => $message,
		];
		if ( $screenshot ) {
			$user_message['screenshot'] = $screenshot;
		}
		$session['messages'][] = $user_message;

		$client = new RJM_CSS_Advisor_GitHub_Client();
		$result = $client->plan_css_turn(
			$session['layout'],
			$session['field'],
			! empty( $session['is_global'] ),
			$session['messages'],
			$session['breakpoints'],
			(string) ( $session['existing_css_context'] ?? '' )
		);

		if ( is_wp_error( $result ) ) {
			self::log_debug_error( 'plan_chat', $result, [
				'layout'    => $layout,
				'field'     => $field,
				'is_global' => $is_global,
				'post_id'   => $post_id,
			] );
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		$assistant_message = trim( (string) ( $result['assistant_message'] ?? '' ) );
		if ( $assistant_message ) {
			$session['messages'][] = [
				'role'    => 'assistant',
				'content' => $assistant_message,
			];
		}

		if ( ! empty( $result['brief'] ) ) {
			$session['brief'] = trim( (string) $result['brief'] );
		}

		$memory['current_css']    = (string) ( $session['existing_css_context'] ?? '' );
		$memory['chat_messages']  = self::strip_screenshots_from_messages( array_slice( (array) $session['messages'], -12 ) );
		$memory['last_brief']     = (string) ( $session['brief'] ?? '' );
		$memory['updated_at']     = time();
		self::set_field_memory( $scope, $memory );

		self::set_plan_session( $session_id, $session );

		self::log_debug_success( 'plan_chat', [
			'layout'            => $layout,
			'field'             => $field,
			'is_global'         => $is_global,
			'field_key'         => $field_key,
			'post_id'           => $post_id,
			'session_id'        => $session_id,
			'ready_to_generate' => ! empty( $result['ready_to_generate'] ),
			'message_count'     => count( (array) $session['messages'] ),
		] );

		wp_send_json_success( [
			'session_id'        => $session_id,
			'transcript_html'   => self::render_plan_transcript( $session['messages'] ),
			'ready_to_generate' => ! empty( $result['ready_to_generate'] ),
			'brief'             => $session['brief'],
		] );
	}

	/**
	 * Generate final CSS from Ask/Plan session history.
	 */
	public static function handle_plan_generate() {
		check_ajax_referer( 'rjm_css_advisor', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'rjm-css-advisor' ) ], 403 );
		}

		$session_id = sanitize_text_field( wp_unslash( $_POST['session_id'] ?? '' ) );
		$goal_tail  = sanitize_textarea_field( wp_unslash( $_POST['goal'] ?? '' ) );
		$current_css = self::sanitize_css_payload( wp_unslash( $_POST['current_css'] ?? '' ) );
		$field_key   = sanitize_text_field( wp_unslash( $_POST['field_key'] ?? '' ) );
		$post_id     = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );

		self::log_debug_request( 'plan_generate', [
			'session_id' => $session_id,
			'field_key'  => $field_key,
			'post_id'    => $post_id,
		] );

		if ( ! $session_id ) {
			wp_send_json_error( [ 'message' => __( 'Plan session not found. Start Ask/Plan first.', 'rjm-css-advisor' ) ] );
		}

		$session = self::get_plan_session( $session_id );
		if ( ! $session ) {
			wp_send_json_error( [ 'message' => __( 'Plan session expired. Please restart Ask/Plan mode.', 'rjm-css-advisor' ) ] );
		}

		$scope = self::build_memory_scope( $post_id, (string) $session['field'], $field_key, (string) $session['layout'], ! empty( $session['is_global'] ) );
		$memory = self::get_field_memory( $scope );
		$existing_css_context = self::resolve_existing_css_context( $current_css, $memory );

		$goal_parts = [];
		if ( ! empty( $session['brief'] ) ) {
			$goal_parts[] = trim( (string) $session['brief'] );
		}

		$goal_parts[] = self::flatten_plan_messages_as_goal( $session['messages'] );

		if ( $goal_tail ) {
			$goal_parts[] = $goal_tail;
		}

		$goal = trim( implode( "\n\n", array_filter( $goal_parts ) ) );
		if ( ! $goal ) {
			wp_send_json_error( [ 'message' => __( 'Ask/Plan does not have enough detail to generate CSS yet.', 'rjm-css-advisor' ) ] );
		}

		$client = new RJM_CSS_Advisor_GitHub_Client();
		$screenshot_data = self::get_latest_screenshot_data( $session['messages'] );
		$result = $client->generate_css(
			$session['layout'],
			$session['field'],
			! empty( $session['is_global'] ),
			$goal,
			$session['breakpoints'],
			$existing_css_context,
			$screenshot_data
		);

		if ( is_wp_error( $result ) ) {
			self::log_debug_error( 'plan_generate', $result, [
				'session_id' => $session_id,
				'layout'     => (string) $session['layout'],
				'field'      => (string) $session['field'],
				'is_global'  => ! empty( $session['is_global'] ),
				'post_id'    => $post_id,
			] );
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		$memory['current_css']    = $existing_css_context;
		$memory['last_generated'] = (string) ( $result['css'] ?? '' );
		$memory['chat_messages']  = [];
		$memory['updated_at']     = time();
		self::set_field_memory( $scope, $memory );

		self::log_debug_success( 'plan_generate', [
			'session_id' => $session_id,
			'layout'     => (string) $session['layout'],
			'field'      => (string) $session['field'],
			'is_global'  => ! empty( $session['is_global'] ),
			'post_id'    => $post_id,
			'css_length' => strlen( (string) ( $result['css'] ?? '' ) ),
		] );

		delete_transient( self::plan_session_key( $session_id ) );

		wp_send_json_success( [
			'html' => self::render_generated_response( $result ),
		] );
	}

	/**
	 * Start Build mode and return the first generated step.
	 */
	public static function handle_build_start() {
		check_ajax_referer( 'rjm_css_advisor', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'rjm-css-advisor' ) ], 403 );
		}

		$layout      = sanitize_key( wp_unslash( $_POST['layout'] ?? '' ) );
		$field       = sanitize_key( wp_unslash( $_POST['field'] ?? 'custom_css' ) );
		$field_key   = sanitize_text_field( wp_unslash( $_POST['field_key'] ?? '' ) );
		$is_global   = ( ( $_POST['is_global'] ?? '0' ) === '1' );
		$is_global   = self::normalize_is_global_request( $field, $field_key, $is_global );
		$goal        = sanitize_textarea_field( wp_unslash( $_POST['goal'] ?? '' ) );
		$breakpoints = array_values( array_filter( array_map( 'sanitize_key', (array) wp_unslash( $_POST['breakpoints'] ?? [] ) ) ) );
		$post_id     = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		$current_css = self::sanitize_css_payload( wp_unslash( $_POST['current_css'] ?? '' ) );

		self::log_debug_request( 'build_start', [
			'layout'     => $layout,
			'field'      => $field,
			'is_global'  => $is_global,
			'field_key'  => $field_key,
			'post_id'    => $post_id,
		] );

		if ( ! $goal ) {
			wp_send_json_error( [ 'message' => __( 'Please describe what you want to build.', 'rjm-css-advisor' ) ] );
		}

		$scope = self::build_memory_scope( $post_id, $field, $field_key, $layout, $is_global );
		$memory = self::get_field_memory( $scope );
		$existing_css_context = self::resolve_existing_css_context( $current_css, $memory );

		$client = new RJM_CSS_Advisor_GitHub_Client();
		$plan   = $client->create_css_build_plan( $layout, $field, $is_global, $goal, $breakpoints, $existing_css_context );
		if ( is_wp_error( $plan ) ) {
			self::log_debug_error( 'build_start', $plan, [
				'layout'    => $layout,
				'field'     => $field,
				'is_global' => $is_global,
				'post_id'   => $post_id,
			] );
			wp_send_json_error( [ 'message' => $plan->get_error_message() ] );
		}

		$steps = array_values( (array) ( $plan['steps'] ?? [] ) );
		if ( ! $steps ) {
			wp_send_json_error( [ 'message' => __( 'Unable to create a build plan for this request.', 'rjm-css-advisor' ) ] );
		}

		$session_id = wp_generate_uuid4();
		$session = [
			'layout'            => $layout,
			'field'             => $field,
			'is_global'         => $is_global,
			'goal'              => $goal,
			'breakpoints'       => $breakpoints,
			'steps'             => $steps,
			'current_index'     => 0,
			'current_step_css'  => '',
			'approved_snippets' => [],
			'existing_css_context' => $existing_css_context,
		];

		$first = $client->generate_css_build_step(
			$layout,
			$field,
			$is_global,
			$goal,
			$steps[0],
			'',
			$breakpoints,
			'',
			$existing_css_context
		);

		if ( is_wp_error( $first ) ) {
			self::log_debug_error( 'build_start', $first, [
				'layout'    => $layout,
				'field'     => $field,
				'is_global' => $is_global,
				'post_id'   => $post_id,
			] );
			wp_send_json_error( [ 'message' => $first->get_error_message() ] );
		}

		$memory['current_css']     = $existing_css_context;
		$memory['last_build_goal'] = $goal;
		$memory['updated_at']      = time();
		self::set_field_memory( $scope, $memory );

		$session['current_step_css'] = (string) ( $first['css'] ?? '' );
		self::set_build_session( $session_id, $session );

		self::log_debug_success( 'build_start', [
			'layout'       => $layout,
			'field'        => $field,
			'is_global'    => $is_global,
			'field_key'    => $field_key,
			'post_id'      => $post_id,
			'session_id'   => $session_id,
			'total_steps'  => count( $steps ),
			'css_length'   => strlen( (string) ( $first['css'] ?? '' ) ),
		] );

		wp_send_json_success( [
			'session_id' => $session_id,
			'step'       => self::format_build_step_response( $session, $first ),
		] );
	}

	/**
	 * Continue Build mode after approve/revise/skip.
	 */
	public static function handle_build_step() {
		check_ajax_referer( 'rjm_css_advisor', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'rjm-css-advisor' ) ], 403 );
		}

		$session_id = sanitize_text_field( wp_unslash( $_POST['session_id'] ?? '' ) );
		$decision   = sanitize_key( wp_unslash( $_POST['decision'] ?? '' ) );
		$feedback   = sanitize_textarea_field( wp_unslash( $_POST['feedback'] ?? '' ) );
		$current_css = self::sanitize_css_payload( wp_unslash( $_POST['current_css'] ?? '' ) );
		$field_key   = sanitize_text_field( wp_unslash( $_POST['field_key'] ?? '' ) );
		$post_id     = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );

		self::log_debug_request( 'build_step', [
			'session_id' => $session_id,
			'decision'   => $decision,
			'field_key'  => $field_key,
			'post_id'    => $post_id,
		] );

		if ( ! $session_id ) {
			wp_send_json_error( [ 'message' => __( 'Build session not found. Start Build mode first.', 'rjm-css-advisor' ) ] );
		}

		$session = self::get_build_session( $session_id );
		if ( ! $session ) {
			wp_send_json_error( [ 'message' => __( 'Build session expired. Please restart Build mode.', 'rjm-css-advisor' ) ] );
		}

		$scope = self::build_memory_scope( $post_id, (string) $session['field'], $field_key, (string) $session['layout'], ! empty( $session['is_global'] ) );
		$memory = self::get_field_memory( $scope );
		$existing_css_context = self::resolve_existing_css_context( $current_css, $memory );

		if ( ! in_array( $decision, [ 'approve', 'revise', 'skip' ], true ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown build action.', 'rjm-css-advisor' ) ] );
		}

		if ( $decision === 'approve' && ! empty( $session['current_step_css'] ) ) {
			$session['approved_snippets'][] = trim( (string) $session['current_step_css'] );
			$session['current_index']++;
		}

		if ( $decision === 'skip' ) {
			$session['current_index']++;
		}

		if ( $decision === 'revise' ) {
			$feedback = $feedback ?: __( 'Please provide an alternative for this step while keeping the same intent.', 'rjm-css-advisor' );
		}

		$steps = (array) ( $session['steps'] ?? [] );
		if ( (int) $session['current_index'] >= count( $steps ) ) {
			$final_css = trim( implode( "\n\n", array_filter( (array) $session['approved_snippets'] ) ) );
			$final_result = [
				'css' => $final_css,
				'explanation' => __( 'Final build output from approved steps.', 'rjm-css-advisor' ),
				'follow_up_questions' => [],
				'recommendations' => [],
			];

			$memory['current_css']     = $existing_css_context;
			$memory['last_generated']  = $final_css;
			$memory['updated_at']      = time();
			self::set_field_memory( $scope, $memory );

			self::log_debug_success( 'build_step', [
				'session_id'       => $session_id,
				'decision'         => $decision,
				'post_id'          => $post_id,
				'completed'        => true,
				'approved_count'   => count( (array) $session['approved_snippets'] ),
				'final_css_length' => strlen( (string) $final_css ),
			] );

			delete_transient( self::build_session_key( $session_id ) );

			wp_send_json_success( [
				'complete' => true,
				'html'     => self::render_generated_response( $final_result ),
			] );
		}

		$approved_css = trim( implode( "\n\n", array_filter( (array) $session['approved_snippets'] ) ) );
		$current_step = (string) $steps[ (int) $session['current_index'] ];

		$client = new RJM_CSS_Advisor_GitHub_Client();
		$next = $client->generate_css_build_step(
			$session['layout'],
			$session['field'],
			! empty( $session['is_global'] ),
			$session['goal'],
			$current_step,
			$approved_css,
			$session['breakpoints'],
			$decision === 'revise' ? $feedback : '',
			$existing_css_context
		);

		if ( is_wp_error( $next ) ) {
			self::log_debug_error( 'build_step', $next, [
				'session_id' => $session_id,
				'layout'     => (string) $session['layout'],
				'field'      => (string) $session['field'],
				'is_global'  => ! empty( $session['is_global'] ),
				'post_id'    => $post_id,
			] );
			wp_send_json_error( [ 'message' => $next->get_error_message() ] );
		}

		$memory['current_css']    = $existing_css_context;
		$memory['last_generated'] = (string) ( $next['css'] ?? '' );
		$memory['updated_at']     = time();
		self::set_field_memory( $scope, $memory );

		$session['current_step_css'] = (string) ( $next['css'] ?? '' );
		self::set_build_session( $session_id, $session );

		self::log_debug_success( 'build_step', [
			'session_id'     => $session_id,
			'decision'       => $decision,
			'post_id'        => $post_id,
			'completed'      => false,
			'current_step'   => (int) $session['current_index'] + 1,
			'total_steps'    => count( (array) ( $session['steps'] ?? [] ) ),
			'approved_count' => count( (array) $session['approved_snippets'] ),
			'css_length'     => strlen( (string) ( $next['css'] ?? '' ) ),
		] );

		wp_send_json_success( [
			'complete' => false,
			'step'     => self::format_build_step_response( $session, $next ),
		] );
	}

	// -------------------------------------------------------------------------
	// Handler — legacy CSS advice (kept for backwards compatibility)
	// -------------------------------------------------------------------------

	public static function handle() {
		// Security.
		check_ajax_referer( 'rjm_css_advisor', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'rjm-css-advisor' ) ], 403 );
		}

		$layout    = sanitize_key( wp_unslash( $_POST['layout']    ?? '' ) );
		$field     = sanitize_key( wp_unslash( $_POST['field']     ?? 'custom_css' ) );
		$is_global = ( ( $_POST['is_global'] ?? '0' ) === '1' );
		$force     = ( ( $_POST['force']     ?? '0' ) === '1' );

		self::log_debug_request( 'legacy_get_advice', [
			'layout'    => $layout,
			'field'     => $field,
			'is_global' => $is_global,
			'force'     => $force,
		] );

		// When force-refresh is requested, delete the cached transient first.
		if ( $force ) {
			if ( $is_global ) {
				delete_transient( 'rjm_css_global' );
			} else {
				delete_transient( 'rjm_css_' . sha1( $layout . '|' . $field ) );
			}
		}

		$client = new RJM_CSS_Advisor_GitHub_Client();
		$result = $client->get_advice( $layout, $field, $is_global );

		if ( is_wp_error( $result ) ) {
			self::log_debug_error( 'legacy_get_advice', $result, [
				'layout'    => $layout,
				'field'     => $field,
				'is_global' => $is_global,
				'force'     => $force,
			] );
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		$html = self::render_advice_html( $result['advice'] );

		self::log_debug_success( 'legacy_get_advice', [
			'layout'     => $layout,
			'field'      => $field,
			'is_global'  => $is_global,
			'from_cache' => ! empty( $result['from_cache'] ),
		] );

		wp_send_json_success( [
			'html'       => $html,
			'from_cache' => (bool) $result['from_cache'],
		] );
	}

	// -------------------------------------------------------------------------
	// Rendering
	// -------------------------------------------------------------------------

	/**
	 * Convert the markdown-style Copilot response into safe HTML.
	 *
	 * The advice uses simple markdown: ## headings, - bullet lists, ``` code blocks.
	 * We convert these to HTML manually (no markdown library dependency).
	 *
	 * @param string $markdown
	 * @return string
	 */
	private static function render_advice_html( $markdown ) {
		$lines  = explode( "\n", $markdown );
		$html   = '';
		$in_code = false;
		$code_buf = '';

		foreach ( $lines as $line ) {
			// ---- Code block toggle ----
			if ( preg_match( '/^```/', $line ) ) {
				if ( $in_code ) {
					// Close code block.
					$html   .= self::render_code_block( $code_buf );
					$code_buf = '';
					$in_code  = false;
				} else {
					$in_code = true;
				}
				continue;
			}

			if ( $in_code ) {
				$code_buf .= $line . "\n";
				continue;
			}

			// ---- Headings ----
			if ( preg_match( '/^### (.+)$/', $line, $m ) ) {
				$html .= '<h4 class="rjm-advice-h4">' . esc_html( trim( $m[1] ) ) . '</h4>';
				continue;
			}
			if ( preg_match( '/^## (.+)$/', $line, $m ) ) {
				$html .= '<h3 class="rjm-advice-h3">' . esc_html( trim( $m[1] ) ) . '</h3>';
				continue;
			}
			if ( preg_match( '/^# (.+)$/', $line, $m ) ) {
				$html .= '<h2 class="rjm-advice-h2">' . esc_html( trim( $m[1] ) ) . '</h2>';
				continue;
			}

			// ---- Bullet list ----
			if ( preg_match( '/^[-*] (.+)$/', $line, $m ) ) {
				$html .= '<li class="rjm-advice-li">' . self::inline_format( trim( $m[1] ) ) . '</li>';
				continue;
			}

			// ---- Empty line ----
			if ( trim( $line ) === '' ) {
				$html .= '<br />';
				continue;
			}

			// ---- Normal paragraph ----
			$html .= '<p class="rjm-advice-p">' . self::inline_format( $line ) . '</p>';
		}

		// Close any unclosed code block.
		if ( $in_code && $code_buf ) {
			$html .= self::render_code_block( $code_buf );
		}

		return $html;
	}

	/**
	 * Render a CSS code block with a Copy button and optional Insert button.
	 *
	 * @param string $code  Raw CSS code.
	 * @return string
	 */
	private static function render_code_block( $code ) {
		$code = rtrim( $code );
		if ( ! $code ) {
			return '';
		}

		$id      = 'rjm-snippet-' . wp_generate_uuid4();
		$escaped = esc_html( $code );
		$encoded = esc_attr( $code );

		return sprintf(
			'<div class="rjm-code-block-wrap">
				<pre class="rjm-code-block" id="%s"><code>%s</code></pre>
				<div class="rjm-code-actions">
					<button type="button" class="button button-small rjm-copy-btn" data-target="%s">%s</button>
					<button type="button" class="button button-small rjm-insert-btn" data-code="%s">%s</button>
				</div>
			</div>',
			esc_attr( $id ),
			$escaped,
			esc_attr( $id ),
			esc_html__( 'Copy', 'rjm-css-advisor' ),
			$encoded,
			esc_html__( '↑ Insert into field', 'rjm-css-advisor' )
		);
	}

	/**
	 * Render the generated CSS plus the plain-language guidance box.
	 *
	 * @param array $result Structured AI response.
	 * @return string
	 */
	private static function render_generated_response( $result ) {
		$html = self::render_code_block( $result['css'] ?? '' );
		$html .= self::render_explanation_box( $result );

		return $html;
	}

	/**
	 * Render the plain-language explanation, follow-up questions, and recommendations.
	 *
	 * @param array $result Structured AI response.
	 * @return string
	 */
	private static function render_explanation_box( $result ) {
		$explanation = trim( (string) ( $result['explanation'] ?? '' ) );
		$questions = array_values( array_filter( array_map( 'trim', (array) ( $result['follow_up_questions'] ?? [] ) ) ) );
		$recommendations = array_values( array_filter( array_map( 'trim', (array) ( $result['recommendations'] ?? [] ) ) ) );

		if ( ! $explanation && ! $questions && ! $recommendations ) {
			return '';
		}

		ob_start();
		?>
		<div class="rjm-ai-summary-wrap">
			<div class="rjm-ai-summary-card">
				<h3 class="rjm-ai-summary-title"><?php esc_html_e( 'What changed', 'rjm-css-advisor' ); ?></h3>
				<?php if ( $explanation ) : ?>
					<p class="rjm-ai-summary-text"><?php echo esc_html( $explanation ); ?></p>
				<?php endif; ?>

				<?php if ( $questions ) : ?>
					<h4 class="rjm-ai-summary-subtitle"><?php esc_html_e( 'Questions to confirm', 'rjm-css-advisor' ); ?></h4>
					<ul class="rjm-ai-summary-list">
						<?php foreach ( $questions as $question ) : ?>
							<li><?php echo esc_html( $question ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php if ( $recommendations ) : ?>
					<h4 class="rjm-ai-summary-subtitle"><?php esc_html_e( 'Helpful recommendations', 'rjm-css-advisor' ); ?></h4>
					<ul class="rjm-ai-summary-list">
						<?php foreach ( $recommendations as $recommendation ) : ?>
							<li><?php echo esc_html( $recommendation ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Apply inline formatting: **bold**, `code`.
	 *
	 * @param string $text
	 * @return string
	 */
	private static function inline_format( $text ) {
		// Escape first, then apply formatting tags.
		$text = esc_html( $text );
		$text = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text );
		$text = preg_replace( '/`([^`]+)`/', '<code class="rjm-inline-code">$1</code>', $text );
		return $text;
	}

	/**
	 * Format Ask/Plan transcript as simple chat bubbles.
	 *
	 * @param array $messages
	 * @return string
	 */
	private static function render_plan_transcript( $messages ) {
		if ( ! $messages ) {
			return '<p class="rjm-plan-empty">' . esc_html__( 'Start by describing your CSS intent, and the assistant will help you refine it.', 'rjm-css-advisor' ) . '</p>';
		}

		ob_start();
		echo '<div class="rjm-plan-transcript">';
		foreach ( (array) $messages as $message ) {
			$role    = sanitize_key( $message['role'] ?? 'assistant' );
			$content = trim( (string) ( $message['content'] ?? '' ) );
			$screenshot = is_array( $message['screenshot'] ?? null ) ? $message['screenshot'] : [];
			if ( ! $content && empty( $screenshot['data'] ) ) {
				continue;
			}

			$class = $role === 'user' ? 'is-user' : 'is-assistant';
			echo '<div class="rjm-plan-message ' . esc_attr( $class ) . '">';
			if ( $content ) {
				echo '<p>' . esc_html( $content ) . '</p>';
			}
			if ( ! empty( $screenshot['data'] ) ) {
				echo '<div class="rjm-plan-screenshot"><img src="' . esc_attr( $screenshot['data'] ) . '" alt="' . esc_attr__( 'Attached screenshot', 'rjm-css-advisor' ) . '" /></div>';
			}
			echo '</div>';
		}
		echo '</div>';

		return (string) ob_get_clean();
	}

	/**
	 * Build a fallback generation goal from plan transcript messages.
	 *
	 * @param array $messages
	 * @return string
	 */
	private static function flatten_plan_messages_as_goal( $messages ) {
		$lines = [];
		foreach ( (array) $messages as $message ) {
			$role    = sanitize_key( $message['role'] ?? '' );
			$content = trim( (string) ( $message['content'] ?? '' ) );
			if ( ! $content ) {
				continue;
			}

			$prefix = $role === 'assistant' ? 'Assistant note' : 'User intent';
			$lines[] = $prefix . ': ' . $content;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Build response payload for a single build step.
	 *
	 * @param array $session
	 * @param array $result
	 * @return array
	 */
	private static function format_build_step_response( $session, $result ) {
		$steps         = (array) ( $session['steps'] ?? [] );
		$current_index = (int) ( $session['current_index'] ?? 0 );
		$total_steps   = count( $steps );

		return [
			'current_step' => $current_index + 1,
			'total_steps'  => $total_steps,
			'step_title'   => (string) ( $steps[ $current_index ] ?? '' ),
			'css'          => (string) ( $result['css'] ?? '' ),
			'explanation'  => (string) ( $result['explanation'] ?? '' ),
		];
	}

	/**
	 * Build Ask/Plan session transient key.
	 *
	 * @param string $session_id
	 * @return string
	 */
	private static function plan_session_key( $session_id ) {
		return 'rjm_css_plan_' . get_current_user_id() . '_' . md5( (string) $session_id );
	}

	/**
	 * Build Build-mode session transient key.
	 *
	 * @param string $session_id
	 * @return string
	 */
	private static function build_session_key( $session_id ) {
		return 'rjm_css_build_' . get_current_user_id() . '_' . md5( (string) $session_id );
	}

	/**
	 * @param string $session_id
	 * @return array|null
	 */
	private static function get_plan_session( $session_id ) {
		$data = get_transient( self::plan_session_key( $session_id ) );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * @param string $session_id
	 * @param array  $session
	 */
	private static function set_plan_session( $session_id, $session ) {
		set_transient( self::plan_session_key( $session_id ), $session, self::SESSION_TTL );
	}

	/**
	 * @param string $session_id
	 * @return array|null
	 */
	private static function get_build_session( $session_id ) {
		$data = get_transient( self::build_session_key( $session_id ) );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * @param string $session_id
	 * @param array  $session
	 */
	private static function set_build_session( $session_id, $session ) {
		set_transient( self::build_session_key( $session_id ), $session, self::SESSION_TTL );
	}

	/**
	 * Build a stable memory scope identifier.
	 *
	 * @param int    $post_id
	 * @param string $field_name
	 * @param string $field_key
	 * @param string $layout
	 * @param bool   $is_global
	 * @return string
	 */
	private static function build_memory_scope( $post_id, $field_name, $field_key, $layout, $is_global ) {
		return implode( '|', [
			'uid:' . get_current_user_id(),
			'pid:' . (int) $post_id,
			'field:' . sanitize_key( (string) $field_name ),
			'fieldkey:' . sanitize_key( (string) $field_key ),
			'layout:' . sanitize_key( (string) $layout ),
			'global:' . ( $is_global ? '1' : '0' ),
		] );
	}

	/**
	 * Load persisted field memory.
	 *
	 * @param string $scope
	 * @return array
	 */
	private static function get_field_memory( $scope ) {
		$key = 'rjm_css_memory_' . md5( (string) $scope );
		$data = get_user_meta( get_current_user_id(), $key, true );

		return is_array( $data ) ? $data : [];
	}

	/**
	 * Persist field memory.
	 *
	 * @param string $scope
	 * @param array  $memory
	 */
	private static function set_field_memory( $scope, $memory ) {
		$key = 'rjm_css_memory_' . md5( (string) $scope );
		update_user_meta( get_current_user_id(), $key, $memory );
	}

	/**
	 * Choose existing CSS context from the latest reliable source.
	 *
	 * @param string $current_css
	 * @param array  $memory
	 * @return string
	 */
	private static function resolve_existing_css_context( $current_css, $memory ) {
		$current_css = trim( (string) $current_css );
		if ( $current_css ) {
			return $current_css;
		}

		return trim( (string) ( $memory['current_css'] ?? '' ) );
	}

	/**
	 * Preserve CSS payload content while normalizing whitespace.
	 *
	 * @param string $css
	 * @return string
	 */
	private static function sanitize_css_payload( $css ) {
		$css = (string) $css;
		$css = str_replace( [ "\r\n", "\r" ], "\n", $css );
		return trim( $css );
	}

	/**
	 * Validate and normalize one temporary screenshot attachment.
	 *
	 * @param string $data Data URL containing the image.
	 * @param string $name Original filename.
	 * @return array|WP_Error|null
	 */
	private static function validate_screenshot_payload( $data, $name ) {
		$data = trim( (string) $data );
		if ( ! $data ) {
			return null;
		}

		if ( ! preg_match( '#^data:image/(png|jpeg|webp);base64,([A-Za-z0-9+/=]+)$#', $data, $matches ) ) {
			return new WP_Error( 'invalid_screenshot', __( 'Please attach a valid PNG, JPEG, or WebP screenshot.', 'rjm-css-advisor' ) );
		}

		$binary = base64_decode( $matches[2], true );
		if ( false === $binary || strlen( $binary ) > self::MAX_SCREENSHOT_BYTES ) {
			return new WP_Error( 'screenshot_too_large', __( 'Screenshot is too large. Please choose an image under 4 MB.', 'rjm-css-advisor' ) );
		}

		$image_info = @getimagesizefromstring( $binary );
		$mime = is_array( $image_info ) ? (string) ( $image_info['mime'] ?? '' ) : '';
		$width = is_array( $image_info ) ? (int) ( $image_info[0] ?? 0 ) : 0;
		$height = is_array( $image_info ) ? (int) ( $image_info[1] ?? 0 ) : 0;
		if ( ! in_array( $mime, [ 'image/png', 'image/jpeg', 'image/webp' ], true ) ) {
			return new WP_Error( 'invalid_screenshot', __( 'Please attach a valid PNG, JPEG, or WebP screenshot.', 'rjm-css-advisor' ) );
		}
		if ( $width < 1 || $height < 1 || $width > self::MAX_SCREENSHOT_WIDTH || $height > self::MAX_SCREENSHOT_HEIGHT ) {
			return new WP_Error( 'screenshot_dimensions', __( 'Screenshot dimensions must be no larger than 4096 by 4096 pixels.', 'rjm-css-advisor' ) );
		}

		return [
			'data' => 'data:' . $mime . ';base64,' . base64_encode( $binary ),
			'name' => sanitize_file_name( (string) $name ) ?: 'screenshot',
			'mime' => $mime,
		];
	}

	/**
	 * Keep screenshots transient-only; user-meta chat memory remains text-only.
	 *
	 * @param array $messages
	 * @return array
	 */
	private static function strip_screenshots_from_messages( $messages ) {
		return array_map(
			static function ( $message ) {
				$message = is_array( $message ) ? $message : [];
				unset( $message['screenshot'] );
				return $message;
			},
			(array) $messages
		);
	}

	/**
	 * Return the most recent screenshot retained by an active plan session.
	 *
	 * @param array $messages
	 * @return string
	 */
	private static function get_latest_screenshot_data( $messages ) {
		foreach ( array_reverse( (array) $messages ) as $message ) {
			$data = (string) ( $message['screenshot']['data'] ?? '' );
			if ( $data ) {
				return $data;
			}
		}

		return '';
	}

	/**
	 * Treat the global field as authoritative even if the client flag is missing.
	 *
	 * @param string $field_name
	 * @param bool   $is_global
	 * @return bool
	 */
	private static function normalize_is_global_request( $field_name, $field_key, $is_global ) {
		$field_name = (string) $field_name;
		$field_key  = (string) $field_key;

		return (bool) $is_global
			|| 'global_custom_css' === $field_name
			|| 'acf[' . self::GLOBAL_CSS_FIELD_KEY . ']' === $field_name
			|| self::GLOBAL_CSS_FIELD_KEY === $field_key;
	}

	/**
	 * Store a structured debug request entry when admin debugging is enabled.
	 *
	 * @param string $action_name
	 * @param array  $payload
	 * @return void
	 */
	private static function log_debug_request( $action_name, $payload ) {
		RJM_CSS_Advisor_Settings::add_debug_entry(
			'ajax_handler',
			$action_name,
			'request',
			$payload
		);
	}

	/**
	 * Store a structured debug error entry when admin debugging is enabled.
	 *
	 * @param string   $action_name
	 * @param \WP_Error $error
	 * @param array    $payload
	 * @return void
	 */
	private static function log_debug_error( $action_name, $error, $payload = [] ) {
		$payload['error_code']    = is_wp_error( $error ) ? $error->get_error_code() : '';
		$payload['error_message'] = is_wp_error( $error ) ? $error->get_error_message() : '';

		RJM_CSS_Advisor_Settings::add_debug_entry(
			'ajax_handler',
			$action_name,
			'error',
			$payload
		);
	}

	/**
	 * Store a structured debug success entry when admin debugging is enabled.
	 *
	 * @param string $action_name
	 * @param array  $payload
	 * @return void
	 */
	private static function log_debug_success( $action_name, $payload = [] ) {
		RJM_CSS_Advisor_Settings::add_debug_entry(
			'ajax_handler',
			$action_name,
			'success',
			$payload
		);
	}
}
