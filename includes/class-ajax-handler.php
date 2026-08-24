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
	const REST_NAMESPACE = 'rjm-css-advisor/v1';
	const GLOBAL_CSS_FIELD_KEY = 'field_6964fb66b09f1';
	const MAX_SCREENSHOT_BYTES = 4 * 1024 * 1024;
	const MAX_SCREENSHOT_WIDTH = 4096;
	const MAX_SCREENSHOT_HEIGHT = 4096;
	const MAX_SCREENSHOTS_PER_MESSAGE = 5;
	const MAX_SCREENSHOT_MESSAGE_BYTES = 20 * 1024 * 1024;
	const MAX_SCREENSHOT_SESSION_BYTES = 50 * 1024 * 1024;

	public static function init() {
		add_action( 'wp_ajax_rjm_get_css_advice', [ __CLASS__, 'handle' ] );
		add_action( 'wp_ajax_rjm_generate_css',   [ __CLASS__, 'handle_generate' ] );
		add_action( 'wp_ajax_rjm_save_global_css', [ __CLASS__, 'handle_save_global_css' ] );
		add_action( 'wp_ajax_rjm_plan_css_chat',  [ __CLASS__, 'handle_plan_chat' ] );
		add_action( 'wp_ajax_rjm_plan_css_generate', [ __CLASS__, 'handle_plan_generate' ] );
		add_action( 'wp_ajax_rjm_troubleshoot_chat', [ __CLASS__, 'handle_troubleshoot_chat' ] );
		add_action( 'wp_ajax_rjm_build_css_start', [ __CLASS__, 'handle_build_start' ] );
		add_action( 'wp_ajax_rjm_build_css_step',  [ __CLASS__, 'handle_build_step' ] );
		add_action( 'wp_ajax_rjm_css_chat_list',   [ __CLASS__, 'handle_chat_list' ] );
		add_action( 'wp_ajax_rjm_css_chat_open',   [ __CLASS__, 'handle_chat_open' ] );
		add_action( 'wp_ajax_rjm_css_chat_rename', [ __CLASS__, 'handle_chat_rename' ] );
		add_action( 'wp_ajax_rjm_css_chat_delete', [ __CLASS__, 'handle_chat_delete' ] );
		add_action( 'wp_ajax_rjm_css_chat_clear',  [ __CLASS__, 'handle_chat_clear' ] );
	}

	// -------------------------------------------------------------------------
	// Handlers — persistent chat history
	// -------------------------------------------------------------------------

	/**
	 * Resolve the component scope for a chat-history request.
	 *
	 * @return string
	 */
	private static function chat_scope_from_request() {
		check_ajax_referer( 'rjm_css_advisor', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'rjm-css-advisor' ) ], 403 );
		}

		$layout    = sanitize_key( wp_unslash( $_POST['layout'] ?? '' ) );
		$field     = sanitize_key( wp_unslash( $_POST['field'] ?? 'custom_css' ) );
		$field_key = sanitize_text_field( wp_unslash( $_POST['field_key'] ?? '' ) );
		$is_global = ( ( $_POST['is_global'] ?? '0' ) === '1' );
		$is_global = self::normalize_is_global_request( $field, $field_key, $is_global );
		$layout    = self::normalize_layout_request( $layout, $field_key );
		$post_id   = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		$mode      = sanitize_key( wp_unslash( $_POST['mode'] ?? 'ask' ) );

		return self::build_memory_scope( $post_id, $field, $field_key, $layout, $is_global, $mode );
	}

	/**
	 * Return the saved chat index for this component.
	 */
	public static function handle_chat_list() {
		$scope = self::chat_scope_from_request();

		wp_send_json_success( [ 'chats' => RJM_CSS_Advisor_Chat_History::get_index( $scope ) ] );
	}

	/**
	 * Load a saved chat and rehydrate it as the active plan session.
	 */
	public static function handle_chat_open() {
		$scope   = self::chat_scope_from_request();
		$chat_id = sanitize_text_field( wp_unslash( $_POST['chat_id'] ?? '' ) );

		if ( ! $chat_id ) {
			$chat_id = RJM_CSS_Advisor_Chat_History::latest_chat_id( $scope );
		}

		$chat = $chat_id ? RJM_CSS_Advisor_Chat_History::get_chat( $scope, $chat_id ) : null;

		if ( ! $chat ) {
			wp_send_json_success( [ 'chat' => null ] );
		}

		$session = self::get_plan_session( $chat['id'] );
		if ( ! $session ) {
			$session = [
				'layout'      => (string) $chat['layout'],
				'field'       => (string) $chat['field'],
				'is_global'   => ! empty( $chat['is_global'] ),
				'breakpoints' => (array) $chat['breakpoints'],
				'messages'    => (array) $chat['messages'],
				'brief'       => (string) $chat['brief'],
				'existing_css_context' => (string) ( $chat['existing_css_context'] ?? '' ),
			];
			self::set_plan_session( $chat['id'], $session );
		}

		wp_send_json_success( [
			'chat' => [
				'id'                => (string) $chat['id'],
				'title'             => (string) $chat['title'],
				'mode'              => (string) ( $chat['mode'] ?? 'ask' ),
				'brief'             => (string) $chat['brief'],
				'breakpoints'       => (array) $chat['breakpoints'],
				'ready_to_generate' => ! empty( $chat['ready_to_generate'] ),
				'updated_at'        => (int) $chat['updated_at'],
				'messages'          => self::chat_messages_for_client( $session['messages'] ),
			],
		] );
	}

	/**
	 * Shape stored messages for client-side transcript rebuilding.
	 *
	 * @param array $messages
	 * @return array
	 */
	private static function chat_messages_for_client( $messages ) {
		$out = [];

		foreach ( (array) $messages as $message ) {
			$screenshots = self::get_message_screenshots( $message );
			$content     = trim( (string) ( $message['content'] ?? '' ) );
			$missing     = max( 0, (int) ( $message['screenshot_count'] ?? 0 ) - count( $screenshots ) );

			if ( ! $content && ! $screenshots && ! $missing ) {
				continue;
			}

			$out[] = [
				'role'              => ( ( $message['role'] ?? '' ) === 'user' ) ? 'user' : 'assistant',
				'content'           => $content,
				'missing_screenshots' => $missing,
				'screenshots'       => array_values( array_filter( array_map(
					static function ( $screenshot ) {
						return empty( $screenshot['data'] ) ? null : [ 'data' => (string) $screenshot['data'] ];
					},
					$screenshots
				) ) ),
			];
		}

		return $out;
	}

	/**
	 * Rename a saved chat.
	 */
	public static function handle_chat_rename() {
		$scope   = self::chat_scope_from_request();
		$chat_id = sanitize_text_field( wp_unslash( $_POST['chat_id'] ?? '' ) );
		$title   = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );

		if ( ! $chat_id || ! trim( $title ) ) {
			wp_send_json_error( [ 'message' => __( 'Please enter a chat name.', 'rjm-css-advisor' ) ] );
		}

		$saved = RJM_CSS_Advisor_Chat_History::set_title( $scope, $chat_id, $title, 'manual' );

		if ( ! $saved ) {
			wp_send_json_error( [ 'message' => __( 'That chat could not be found.', 'rjm-css-advisor' ) ], 404 );
		}

		wp_send_json_success( [
			'chat_id' => $chat_id,
			'title'   => $saved,
			'chats'   => RJM_CSS_Advisor_Chat_History::get_index( $scope ),
		] );
	}

	/**
	 * Delete a single saved chat.
	 */
	public static function handle_chat_delete() {
		$scope   = self::chat_scope_from_request();
		$chat_id = sanitize_text_field( wp_unslash( $_POST['chat_id'] ?? '' ) );

		if ( ! $chat_id ) {
			wp_send_json_error( [ 'message' => __( 'That chat could not be found.', 'rjm-css-advisor' ) ], 404 );
		}

		RJM_CSS_Advisor_Chat_History::delete_chat( $scope, $chat_id );
		delete_transient( self::plan_session_key( $chat_id ) );

		wp_send_json_success( [ 'chats' => RJM_CSS_Advisor_Chat_History::get_index( $scope ) ] );
	}

	/**
	 * Delete every saved chat for this component.
	 */
	public static function handle_chat_clear() {
		$scope = self::chat_scope_from_request();

		foreach ( RJM_CSS_Advisor_Chat_History::get_index( $scope ) as $entry ) {
			delete_transient( self::plan_session_key( (string) ( $entry['id'] ?? '' ) ) );
		}

		RJM_CSS_Advisor_Chat_History::clear_all( $scope );

		wp_send_json_success( [ 'chats' => [] ] );
	}

	/**
	 * Register the streaming route. Must run outside is_admin(), which is false for REST.
	 */
	public static function init_rest() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_routes' ] );
	}

	public static function register_rest_routes() {
		$permission_callback = static function () {
			if ( ! current_user_can( 'edit_posts' ) ) {
				return false;
			}

			if ( RJM_CSS_Advisor_Settings::is_css_edit_lock_active_for_current_user() ) {
				return new WP_Error(
					'rjm_css_edit_access_disabled',
					__( 'Custom CSS edit access is disabled for your account.', 'rjm-css-advisor' ),
					[ 'status' => 403 ]
				);
			}

			return true;
		};

		register_rest_route(
			self::REST_NAMESPACE,
			'/plan-stream',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'handle_plan_stream' ],
				'permission_callback' => $permission_callback,
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/troubleshoot-stream',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'handle_troubleshoot_stream' ],
				'permission_callback' => $permission_callback,
			]
		);
	}

	/**
	 * Enforce capability and role-aware CSS edit lock for generation endpoints.
	 *
	 * @return void
	 */
	private static function enforce_generation_access() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'rjm-css-advisor' ) ], 403 );
		}

		if ( RJM_CSS_Advisor_Settings::is_css_edit_lock_active_for_current_user() ) {
			wp_send_json_error( [ 'message' => __( 'Custom CSS edit access is disabled for your account.', 'rjm-css-advisor' ) ], 403 );
		}
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
		self::enforce_generation_access();

		$layout    = sanitize_key( wp_unslash( $_POST['layout']    ?? '' ) );
		$field     = sanitize_key( wp_unslash( $_POST['field']     ?? 'custom_css' ) );
		$field_key = sanitize_text_field( wp_unslash( $_POST['field_key'] ?? '' ) );
		$is_global = ( ( $_POST['is_global'] ?? '0' ) === '1' );
		$is_global = self::normalize_is_global_request( $field, $field_key, $is_global );
		$layout    = self::normalize_layout_request( $layout, $field_key );
		$goal      = sanitize_textarea_field( wp_unslash( $_POST['goal'] ?? '' ) );
		$post_id   = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		$current_css = self::sanitize_css_payload( wp_unslash( $_POST['current_css'] ?? '' ) );
		$breakpoints = array_values( array_filter( array_map( 'sanitize_key', (array) wp_unslash( $_POST['breakpoints'] ?? [] ) ) ) );
		$native_settings = self::sanitize_native_settings_payload( wp_unslash( $_POST['native_settings'] ?? '' ) );

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
		$result = $client->generate_css( $layout, $field, $is_global, $goal, $breakpoints, $existing_css_context, '', $native_settings );

		if ( is_wp_error( $result ) ) {
			self::log_debug_error( 'generate', $result, [
				'layout'    => $layout,
				'field'     => $field,
				'is_global' => $is_global,
				'post_id'   => $post_id,
			] );
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		$html = self::render_generated_response( $result, $is_global );

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
	 * Handle the rjm_save_global_css AJAX action — append a generated snippet
	 * to the site-wide Global Custom CSS field instead of the local field the
	 * chat panel is attached to.
	 *
	 * Expected POST parameters:
	 *   nonce  - WordPress nonce (action: rjm_css_advisor)
	 *   code   - The CSS snippet to save
	 *   layout - ACF flexible content layout name, used only for the source comment
	 *   field  - ACF field name, used only for the source comment
	 */
	public static function handle_save_global_css() {
		check_ajax_referer( 'rjm_css_advisor', 'nonce' );
		self::enforce_generation_access();

		$layout = sanitize_key( wp_unslash( $_POST['layout'] ?? '' ) );
		$field  = sanitize_key( wp_unslash( $_POST['field']  ?? '' ) );
		$code   = self::sanitize_css_payload( wp_unslash( $_POST['code'] ?? '' ) );

		if ( ! $code ) {
			wp_send_json_error( [ 'message' => __( 'There is no CSS to save.', 'rjm-css-advisor' ) ] );
		}

		if ( ! function_exists( 'get_field' ) || ! function_exists( 'update_field' ) ) {
			wp_send_json_error( [ 'message' => __( 'Advanced Custom Fields is required to save global CSS.', 'rjm-css-advisor' ) ] );
		}

		$global_post_id = RJM_CSS_Advisor_ACF_Integration::resolve_global_css_post_id();
		if ( null === $global_post_id ) {
			wp_send_json_error( [ 'message' => __( 'Could not locate the Global Custom CSS field. Ask an administrator to configure the rjm_css_advisor_global_css_post_id filter.', 'rjm-css-advisor' ) ] );
		}

		$existing = (string) get_field( 'global_custom_css', $global_post_id );
		$source   = trim( $layout ? $layout : $field ) ?: __( 'a component', 'rjm-css-advisor' );
		$comment  = sprintf(
			'/* Added via CSS Advisor — from "%1$s" on %2$s */',
			$source,
			gmdate( 'Y-m-d' )
		);
		$snippet = $comment . "\n" . $code;
		$merged  = self::append_global_css_snippet( $existing, $snippet );

		self::log_debug_request( 'save_global_css', [
			'layout'    => $layout,
			'field'     => $field,
			'post_id'   => $global_post_id,
			'css_length' => strlen( $code ),
		] );

		$saved = update_field( 'global_custom_css', $merged, $global_post_id );
		if ( ! $saved ) {
			self::log_debug_error( 'save_global_css', new WP_Error( 'update_field_failed', 'update_field() returned false' ), [
				'post_id' => $global_post_id,
			] );
			wp_send_json_error( [ 'message' => __( 'Failed to save to the Global Custom CSS field.', 'rjm-css-advisor' ) ] );
		}

		self::log_debug_success( 'save_global_css', [
			'layout'  => $layout,
			'field'   => $field,
			'post_id' => $global_post_id,
		] );

		wp_send_json_success( [ 'message' => __( 'Saved to the site-wide Global Custom CSS field.', 'rjm-css-advisor' ) ] );
	}

	/**
	 * Append a snippet to existing global CSS, preserving whatever was there.
	 *
	 * @param string $existing
	 * @param string $snippet
	 * @return string
	 */
	private static function append_global_css_snippet( $existing, $snippet ) {
		$existing = trim( (string) $existing );
		$snippet  = trim( (string) $snippet );

		if ( ! $existing ) {
			return $snippet . "\n";
		}

		return $existing . "\n\n" . $snippet;
	}

	/**
	 * Handle Ask/Plan chat turns.
	 */
	public static function handle_plan_chat() {
		check_ajax_referer( 'rjm_css_advisor', 'nonce' );
		self::enforce_generation_access();

		$layout      = sanitize_key( wp_unslash( $_POST['layout'] ?? '' ) );
		$field       = sanitize_key( wp_unslash( $_POST['field'] ?? 'custom_css' ) );
		$field_key   = sanitize_text_field( wp_unslash( $_POST['field_key'] ?? '' ) );
		$is_global   = ( ( $_POST['is_global'] ?? '0' ) === '1' );
		$is_global   = self::normalize_is_global_request( $field, $field_key, $is_global );
		$layout      = self::normalize_layout_request( $layout, $field_key );
		$message     = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
		$screenshot_data = $_POST['screenshot_data'] ?? [];
		$screenshot_name = $_POST['screenshot_name'] ?? [];
		$screenshots = self::validate_screenshot_payloads( $screenshot_data, $screenshot_name );
		$session_id  = sanitize_text_field( wp_unslash( $_POST['session_id'] ?? '' ) );
		$breakpoints = array_values( array_filter( array_map( 'sanitize_key', (array) wp_unslash( $_POST['breakpoints'] ?? [] ) ) ) );
		$post_id     = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		$current_css = self::sanitize_css_payload( wp_unslash( $_POST['current_css'] ?? '' ) );
		$native_settings = self::sanitize_native_settings_payload( wp_unslash( $_POST['native_settings'] ?? '' ) );

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

		if ( is_wp_error( $screenshots ) ) {
			wp_send_json_error( [ 'message' => $screenshots->get_error_message() ] );
		}

		$scope = self::build_memory_scope( $post_id, $field, $field_key, $layout, $is_global );
		$memory = self::get_field_memory( $scope );
		$existing_css_context = self::resolve_existing_css_context( $current_css, $memory );

		if ( ! $session_id ) {
			$session_id = wp_generate_uuid4();
		}

		$session = self::get_plan_session( $session_id );
		if ( ! $session ) {
			$saved   = RJM_CSS_Advisor_Chat_History::get_chat( $scope, $session_id );
			$session = [
				'layout'      => $layout,
				'field'       => $field,
				'is_global'   => $is_global,
				'breakpoints' => $breakpoints,
				'messages'    => (array) ( $saved['messages'] ?? [] ),
				'brief'       => (string) ( $saved['brief'] ?? '' ),
				'existing_css_context' => $existing_css_context,
			];
		}

		if ( ! empty( $existing_css_context ) ) {
			$session['existing_css_context'] = $existing_css_context;
		}

		if ( $native_settings ) {
			$session['native_settings'] = $native_settings;
		}

		$session_screenshot_bytes = self::get_screenshot_bytes( $session['messages'] );
		$new_screenshot_bytes = self::get_screenshot_bytes( $screenshots );
		if ( $session_screenshot_bytes + $new_screenshot_bytes > self::MAX_SCREENSHOT_SESSION_BYTES ) {
			wp_send_json_error( [ 'message' => __( 'This plan session has reached its 50 MB screenshot limit. Remove some screenshots or start a new plan.', 'rjm-css-advisor' ) ] );
		}

		$user_message = [
			'role'    => 'user',
			'content' => $message,
		];
		if ( $screenshots ) {
			$user_message['screenshots'] = $screenshots;
		}
		$session['messages'][] = $user_message;

		$client = new RJM_CSS_Advisor_GitHub_Client();
		$result = $client->plan_css_turn(
			$session['layout'],
			$session['field'],
			! empty( $session['is_global'] ),
			$session['messages'],
			$session['breakpoints'],
			(string) ( $session['existing_css_context'] ?? '' ),
			(array) ( $session['native_settings'] ?? [] )
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

		$chat = RJM_CSS_Advisor_Chat_History::record_turn( $scope, $session_id, $session, ! empty( $result['ready_to_generate'] ) );
		$chat_title = self::maybe_generate_chat_title( $scope, $chat );

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
			'messages'          => self::plan_messages_for_client( $session['messages'] ),
			'ready_to_generate' => ! empty( $result['ready_to_generate'] ),
			'brief'             => $session['brief'],
			'chat_title'        => $chat_title,
		] );
	}

	/**
	 * Handle one non-streaming Troubleshoot chat turn.
	 */
	public static function handle_troubleshoot_chat() {
		check_ajax_referer( 'rjm_css_advisor', 'nonce' );
		self::enforce_generation_access();

		$layout      = sanitize_key( wp_unslash( $_POST['layout'] ?? '' ) );
		$field       = sanitize_key( wp_unslash( $_POST['field'] ?? 'custom_css' ) );
		$field_key   = sanitize_text_field( wp_unslash( $_POST['field_key'] ?? '' ) );
		$is_global   = ( ( $_POST['is_global'] ?? '0' ) === '1' );
		$is_global   = self::normalize_is_global_request( $field, $field_key, $is_global );
		$layout      = self::normalize_layout_request( $layout, $field_key );
		$message     = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
		$screenshot_data = $_POST['screenshot_data'] ?? [];
		$screenshot_name = $_POST['screenshot_name'] ?? [];
		$screenshots = self::validate_screenshot_payloads( $screenshot_data, $screenshot_name );
		$session_id  = sanitize_text_field( wp_unslash( $_POST['session_id'] ?? '' ) );
		$post_id     = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		$current_css = self::sanitize_css_payload( wp_unslash( $_POST['current_css'] ?? '' ) );
		$native_settings = self::sanitize_native_settings_payload( wp_unslash( $_POST['native_settings'] ?? '' ) );

		self::log_debug_request( 'troubleshoot_chat', [
			'layout'    => $layout,
			'field'     => $field,
			'is_global' => $is_global,
			'field_key' => $field_key,
			'post_id'   => $post_id,
		] );

		if ( ! $message ) {
			wp_send_json_error( [ 'message' => __( 'Please describe what looks wrong for Troubleshoot mode.', 'rjm-css-advisor' ) ] );
		}

		if ( is_wp_error( $screenshots ) ) {
			wp_send_json_error( [ 'message' => $screenshots->get_error_message() ] );
		}

		$scope  = self::build_memory_scope( $post_id, $field, $field_key, $layout, $is_global, 'troubleshoot' );
		$memory = self::get_field_memory( $scope );
		$existing_css_context = self::resolve_existing_css_context( $current_css, $memory );

		if ( ! $session_id ) {
			$session_id = wp_generate_uuid4();
		}

		$session = self::get_plan_session( $session_id );
		if ( ! $session ) {
			$saved   = RJM_CSS_Advisor_Chat_History::get_chat( $scope, $session_id );
			$session = [
				'layout'    => $layout,
				'field'     => $field,
				'is_global' => $is_global,
				'messages'  => (array) ( $saved['messages'] ?? [] ),
				'existing_css_context' => $existing_css_context,
			];
		}

		if ( ! empty( $existing_css_context ) ) {
			$session['existing_css_context'] = $existing_css_context;
		}

		if ( $native_settings ) {
			$session['native_settings'] = $native_settings;
		}

		$session_screenshot_bytes = self::get_screenshot_bytes( $session['messages'] );
		$new_screenshot_bytes = self::get_screenshot_bytes( $screenshots );
		if ( $session_screenshot_bytes + $new_screenshot_bytes > self::MAX_SCREENSHOT_SESSION_BYTES ) {
			wp_send_json_error( [ 'message' => __( 'This chat has reached its 50 MB screenshot limit. Remove some screenshots or start a new chat.', 'rjm-css-advisor' ) ] );
		}

		$user_message = [
			'role'    => 'user',
			'content' => $message,
		];
		if ( $screenshots ) {
			$user_message['screenshots'] = $screenshots;
		}
		$session['messages'][] = $user_message;

		$client = new RJM_CSS_Advisor_GitHub_Client();
		$result = $client->troubleshoot_css_turn(
			$session['layout'],
			$session['field'],
			! empty( $session['is_global'] ),
			$session['messages'],
			(string) ( $session['existing_css_context'] ?? '' ),
			(array) ( $session['native_settings'] ?? [] )
		);

		if ( is_wp_error( $result ) ) {
			self::log_debug_error( 'troubleshoot_chat', $result, [
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

		$handoff_instruction = trim( (string) ( $result['handoff_instruction'] ?? '' ) );

		$memory['current_css']   = (string) ( $session['existing_css_context'] ?? '' );
		$memory['chat_messages'] = self::strip_screenshots_from_messages( array_slice( (array) $session['messages'], -12 ) );
		$memory['updated_at']    = time();
		self::set_field_memory( $scope, $memory );

		self::set_plan_session( $session_id, $session );

		$chat = RJM_CSS_Advisor_Chat_History::record_turn( $scope, $session_id, $session, (bool) $handoff_instruction, 'troubleshoot' );
		$chat_title = self::maybe_generate_chat_title( $scope, $chat );

		self::log_debug_success( 'troubleshoot_chat', [
			'layout'        => $layout,
			'field'         => $field,
			'is_global'     => $is_global,
			'field_key'     => $field_key,
			'post_id'       => $post_id,
			'session_id'    => $session_id,
			'message_count' => count( (array) $session['messages'] ),
		] );

		wp_send_json_success( [
			'session_id'          => $session_id,
			'messages'            => self::plan_messages_for_client( $session['messages'] ),
			'handoff_instruction' => $handoff_instruction,
			'chat_title'          => $chat_title,
		] );
	}

	// -------------------------------------------------------------------------
	// Handler — Ask/Plan chat turn, streamed over Server-Sent Events
	// -------------------------------------------------------------------------

	/**
	 * Stream one Ask/Plan turn as SSE.
	 *
	 * Emits: open → delta* → done, or error. Always exits; never returns to the
	 * REST server, which would try to send its own JSON response.
	 *
	 * @param WP_REST_Request $request
	 * @return void
	 */
	public static function handle_plan_stream( $request ) {
		$layout      = sanitize_key( (string) $request->get_param( 'layout' ) );
		$field       = sanitize_key( (string) ( $request->get_param( 'field' ) ?: 'custom_css' ) );
		$field_key   = sanitize_text_field( (string) $request->get_param( 'field_key' ) );
		$is_global   = self::normalize_is_global_request( $field, $field_key, (bool) $request->get_param( 'is_global' ) );
		$layout      = self::normalize_layout_request( $layout, $field_key );
		$message     = sanitize_textarea_field( (string) $request->get_param( 'message' ) );
		$session_id  = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
		$breakpoints = array_values( array_filter( array_map( 'sanitize_key', (array) $request->get_param( 'breakpoints' ) ) ) );
		$post_id     = absint( $request->get_param( 'post_id' ) );
		$current_css = self::sanitize_css_payload( $request->get_param( 'current_css' ) );
		$native_settings = self::sanitize_native_settings_payload( (string) $request->get_param( 'native_settings' ) );
		$screenshots = self::validate_screenshot_payloads(
			$request->get_param( 'screenshot_data' ) ?? [],
			$request->get_param( 'screenshot_name' ) ?? []
		);

		self::start_sse_stream();

		self::log_debug_request( 'plan_stream', [
			'layout'    => $layout,
			'field'     => $field,
			'is_global' => $is_global,
			'field_key' => $field_key,
			'post_id'   => $post_id,
		] );

		if ( ! $message ) {
			self::sse_fail( __( 'Please enter a message for Ask/Plan mode.', 'rjm-css-advisor' ) );
		}

		if ( is_wp_error( $screenshots ) ) {
			self::sse_fail( $screenshots->get_error_message() );
		}

		$scope  = self::build_memory_scope( $post_id, $field, $field_key, $layout, $is_global );
		$memory = self::get_field_memory( $scope );
		$existing_css_context = self::resolve_existing_css_context( $current_css, $memory );

		if ( ! $session_id ) {
			$session_id = wp_generate_uuid4();
		}

		$session = self::get_plan_session( $session_id );
		if ( ! $session ) {
			$saved   = RJM_CSS_Advisor_Chat_History::get_chat( $scope, $session_id );
			$session = [
				'layout'      => $layout,
				'field'       => $field,
				'is_global'   => $is_global,
				'breakpoints' => $breakpoints,
				'messages'    => (array) ( $saved['messages'] ?? [] ),
				'brief'       => (string) ( $saved['brief'] ?? '' ),
				'existing_css_context' => $existing_css_context,
			];
		}

		if ( ! empty( $existing_css_context ) ) {
			$session['existing_css_context'] = $existing_css_context;
		}

		if ( $native_settings ) {
			$session['native_settings'] = $native_settings;
		}

		if ( self::get_screenshot_bytes( $session['messages'] ) + self::get_screenshot_bytes( $screenshots ) > self::MAX_SCREENSHOT_SESSION_BYTES ) {
			self::sse_fail( __( 'This plan session has reached its 50 MB screenshot limit. Remove some screenshots or start a new plan.', 'rjm-css-advisor' ) );
		}

		$user_message = [
			'role'    => 'user',
			'content' => $message,
		];
		if ( $screenshots ) {
			$user_message['screenshots'] = $screenshots;
		}
		$session['messages'][] = $user_message;

		self::sse_send( 'open', [ 'session_id' => $session_id ] );

		$client = new RJM_CSS_Advisor_GitHub_Client();
		$result = $client->plan_css_turn_stream(
			$session['layout'],
			$session['field'],
			! empty( $session['is_global'] ),
			$session['messages'],
			$session['breakpoints'],
			(string) ( $session['existing_css_context'] ?? '' ),
			(array) ( $session['native_settings'] ?? [] ),
			static function ( $chunk ) {
				self::sse_send( 'delta', [ 'text' => $chunk ] );
			}
		);

		if ( is_wp_error( $result ) ) {
			self::log_debug_error( 'plan_stream', $result, [
				'layout'    => $layout,
				'field'     => $field,
				'is_global' => $is_global,
				'post_id'   => $post_id,
			] );
			self::sse_fail( $result->get_error_message(), $result->get_error_code() );
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

		$memory['current_css']   = (string) ( $session['existing_css_context'] ?? '' );
		$memory['chat_messages'] = self::strip_screenshots_from_messages( array_slice( (array) $session['messages'], -12 ) );
		$memory['last_brief']    = (string) ( $session['brief'] ?? '' );
		$memory['updated_at']    = time();
		self::set_field_memory( $scope, $memory );

		self::set_plan_session( $session_id, $session );

		$chat = RJM_CSS_Advisor_Chat_History::record_turn( $scope, $session_id, $session, ! empty( $result['ready_to_generate'] ) );

		self::log_debug_success( 'plan_stream', [
			'layout'            => $layout,
			'field'             => $field,
			'is_global'         => $is_global,
			'field_key'         => $field_key,
			'post_id'           => $post_id,
			'session_id'        => $session_id,
			'ready_to_generate' => ! empty( $result['ready_to_generate'] ),
			'message_count'     => count( (array) $session['messages'] ),
		] );

		self::sse_send( 'done', [
			'session_id'        => $session_id,
			'message'           => $assistant_message,
			'ready_to_generate' => ! empty( $result['ready_to_generate'] ),
			'brief'             => (string) ( $session['brief'] ?? '' ),
			'chat_title'        => (string) $chat['title'],
		] );

		// Titling costs an extra API round trip, so it trails the visible response.
		$ai_title = self::maybe_generate_chat_title( $scope, $chat );
		if ( $ai_title !== $chat['title'] ) {
			self::sse_send( 'title', [
				'session_id' => $session_id,
				'chat_title' => $ai_title,
			] );
		}

		exit;
	}

	/**
	 * Stream one Troubleshoot turn as SSE.
	 *
	 * @param WP_REST_Request $request
	 * @return void
	 */
	public static function handle_troubleshoot_stream( $request ) {
		$layout      = sanitize_key( (string) $request->get_param( 'layout' ) );
		$field       = sanitize_key( (string) ( $request->get_param( 'field' ) ?: 'custom_css' ) );
		$field_key   = sanitize_text_field( (string) $request->get_param( 'field_key' ) );
		$is_global   = self::normalize_is_global_request( $field, $field_key, (bool) $request->get_param( 'is_global' ) );
		$layout      = self::normalize_layout_request( $layout, $field_key );
		$message     = sanitize_textarea_field( (string) $request->get_param( 'message' ) );
		$session_id  = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
		$post_id     = absint( $request->get_param( 'post_id' ) );
		$current_css = self::sanitize_css_payload( $request->get_param( 'current_css' ) );
		$native_settings = self::sanitize_native_settings_payload( (string) $request->get_param( 'native_settings' ) );
		$screenshots = self::validate_screenshot_payloads(
			$request->get_param( 'screenshot_data' ) ?? [],
			$request->get_param( 'screenshot_name' ) ?? []
		);

		self::start_sse_stream();

		self::log_debug_request( 'troubleshoot_stream', [
			'layout'    => $layout,
			'field'     => $field,
			'is_global' => $is_global,
			'field_key' => $field_key,
			'post_id'   => $post_id,
		] );

		if ( ! $message ) {
			self::sse_fail( __( 'Please describe what looks wrong for Troubleshoot mode.', 'rjm-css-advisor' ) );
		}

		if ( is_wp_error( $screenshots ) ) {
			self::sse_fail( $screenshots->get_error_message() );
		}

		$scope  = self::build_memory_scope( $post_id, $field, $field_key, $layout, $is_global, 'troubleshoot' );
		$memory = self::get_field_memory( $scope );
		$existing_css_context = self::resolve_existing_css_context( $current_css, $memory );

		if ( ! $session_id ) {
			$session_id = wp_generate_uuid4();
		}

		$session = self::get_plan_session( $session_id );
		if ( ! $session ) {
			$saved   = RJM_CSS_Advisor_Chat_History::get_chat( $scope, $session_id );
			$session = [
				'layout'    => $layout,
				'field'     => $field,
				'is_global' => $is_global,
				'messages'  => (array) ( $saved['messages'] ?? [] ),
				'existing_css_context' => $existing_css_context,
			];
		}

		if ( ! empty( $existing_css_context ) ) {
			$session['existing_css_context'] = $existing_css_context;
		}

		if ( $native_settings ) {
			$session['native_settings'] = $native_settings;
		}

		if ( self::get_screenshot_bytes( $session['messages'] ) + self::get_screenshot_bytes( $screenshots ) > self::MAX_SCREENSHOT_SESSION_BYTES ) {
			self::sse_fail( __( 'This chat has reached its 50 MB screenshot limit. Remove some screenshots or start a new chat.', 'rjm-css-advisor' ) );
		}

		$user_message = [
			'role'    => 'user',
			'content' => $message,
		];
		if ( $screenshots ) {
			$user_message['screenshots'] = $screenshots;
		}
		$session['messages'][] = $user_message;

		self::sse_send( 'open', [ 'session_id' => $session_id ] );

		$client = new RJM_CSS_Advisor_GitHub_Client();
		$result = $client->troubleshoot_css_turn_stream(
			$session['layout'],
			$session['field'],
			! empty( $session['is_global'] ),
			$session['messages'],
			(string) ( $session['existing_css_context'] ?? '' ),
			(array) ( $session['native_settings'] ?? [] ),
			static function ( $chunk ) {
				self::sse_send( 'delta', [ 'text' => $chunk ] );
			}
		);

		if ( is_wp_error( $result ) ) {
			self::log_debug_error( 'troubleshoot_stream', $result, [
				'layout'    => $layout,
				'field'     => $field,
				'is_global' => $is_global,
				'post_id'   => $post_id,
			] );
			self::sse_fail( $result->get_error_message(), $result->get_error_code() );
		}

		$assistant_message = trim( (string) ( $result['assistant_message'] ?? '' ) );
		if ( $assistant_message ) {
			$session['messages'][] = [
				'role'    => 'assistant',
				'content' => $assistant_message,
			];
		}

		$handoff_instruction = trim( (string) ( $result['handoff_instruction'] ?? '' ) );

		$memory['current_css']   = (string) ( $session['existing_css_context'] ?? '' );
		$memory['chat_messages'] = self::strip_screenshots_from_messages( array_slice( (array) $session['messages'], -12 ) );
		$memory['updated_at']    = time();
		self::set_field_memory( $scope, $memory );

		self::set_plan_session( $session_id, $session );

		$chat = RJM_CSS_Advisor_Chat_History::record_turn( $scope, $session_id, $session, (bool) $handoff_instruction, 'troubleshoot' );

		self::log_debug_success( 'troubleshoot_stream', [
			'layout'        => $layout,
			'field'         => $field,
			'is_global'     => $is_global,
			'field_key'     => $field_key,
			'post_id'       => $post_id,
			'session_id'    => $session_id,
			'message_count' => count( (array) $session['messages'] ),
		] );

		self::sse_send( 'done', [
			'session_id'          => $session_id,
			'message'             => $assistant_message,
			'handoff_instruction' => $handoff_instruction,
			'chat_title'          => (string) $chat['title'],
		] );

		// Titling costs an extra API round trip, so it trails the visible response.
		$ai_title = self::maybe_generate_chat_title( $scope, $chat );
		if ( $ai_title !== $chat['title'] ) {
			self::sse_send( 'title', [
				'session_id' => $session_id,
				'chat_title' => $ai_title,
			] );
		}

		exit;
	}

	/**
	 * Upgrade a placeholder chat title to an AI-generated one, once per chat.
	 *
	 * @param string $scope
	 * @param array  $chat
	 * @return string The title to show.
	 */
	private static function maybe_generate_chat_title( $scope, $chat ) {
		$current = (string) ( $chat['title'] ?? '' );

		if ( 'fallback' !== ( $chat['title_source'] ?? '' ) || count( (array) $chat['messages'] ) < 2 ) {
			return $current;
		}

		$first_user = '';
		$last_reply = '';
		foreach ( (array) $chat['messages'] as $message ) {
			if ( ! $first_user && 'user' === ( $message['role'] ?? '' ) ) {
				$first_user = (string) ( $message['content'] ?? '' );
			}
			if ( 'assistant' === ( $message['role'] ?? '' ) ) {
				$last_reply = (string) ( $message['content'] ?? '' );
			}
		}

		if ( ! $first_user ) {
			return $current;
		}

		$client = new RJM_CSS_Advisor_GitHub_Client();
		$title  = $client->generate_chat_title( $first_user, $last_reply );

		if ( is_wp_error( $title ) || ! trim( (string) $title ) ) {
			return $current;
		}

		$saved = RJM_CSS_Advisor_Chat_History::set_title( $scope, $chat['id'], $title, 'ai' );

		return $saved ? $saved : $current;
	}

	/**
	 * Switch the response into an unbuffered SSE stream.
	 */
	private static function start_sse_stream() {
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/event-stream; charset=utf-8' );
			header( 'Cache-Control: no-cache, no-store, no-transform, must-revalidate' );
			header( 'Connection: keep-alive' );
			header( 'X-Accel-Buffering: no' );
		}

		@ini_set( 'zlib.output_compression', '0' );
		@ini_set( 'output_buffering', 'off' );
		@ini_set( 'implicit_flush', '1' );
		@set_time_limit( 180 );
		ignore_user_abort( false );

		while ( ob_get_level() > 0 ) {
			@ob_end_flush();
		}

		// Padding plus a comment frame push proxies into forwarding the stream immediately.
		echo ':' . str_repeat( ' ', 2048 ) . "\n\n";
		flush();
	}

	private static function sse_send( $event, $payload ) {
		echo 'event: ' . $event . "\n";
		echo 'data: ' . wp_json_encode( $payload ) . "\n\n";
		flush();
	}

	/**
	 * Emit a terminal error frame and stop.
	 */
	private static function sse_fail( $message, $code = 'plan_stream_error' ) {
		self::sse_send( 'error', [
			'message' => (string) $message,
			'code'    => (string) $code,
		] );
		exit;
	}

	/**
	 * Reduce stored messages to the shape the browser transcript needs.
	 */
	private static function plan_messages_for_client( $messages ) {
		$out = [];

		foreach ( (array) $messages as $message ) {
			$role = ( ( $message['role'] ?? '' ) === 'user' ) ? 'user' : 'assistant';
			$item = [
				'role'    => $role,
				'content' => (string) ( $message['content'] ?? '' ),
			];

			$screenshots = self::get_message_screenshots( $message );
			if ( $screenshots ) {
				$item['screenshots'] = array_map(
					static function ( $screenshot ) {
						return [
							'data' => (string) ( $screenshot['data'] ?? '' ),
							'name' => (string) ( $screenshot['name'] ?? '' ),
						];
					},
					$screenshots
				);
			}

			$out[] = $item;
		}

		return $out;
	}

	/**
	 * Generate final CSS from Ask/Plan session history.
	 */
	public static function handle_plan_generate() {
		check_ajax_referer( 'rjm_css_advisor', 'nonce' );
		self::enforce_generation_access();

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
		$screenshot_data = self::get_screenshot_data( $session['messages'] );
		$result = $client->generate_css(
			$session['layout'],
			$session['field'],
			! empty( $session['is_global'] ),
			$goal,
			$session['breakpoints'],
			$existing_css_context,
			$screenshot_data,
			(array) ( $session['native_settings'] ?? [] )
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
			'html' => self::render_generated_response( $result, ! empty( $session['is_global'] ) ),
		] );
	}

	/**
	 * Start Build mode and return the first generated step.
	 */
	public static function handle_build_start() {
		check_ajax_referer( 'rjm_css_advisor', 'nonce' );
		self::enforce_generation_access();

		$layout      = sanitize_key( wp_unslash( $_POST['layout'] ?? '' ) );
		$field       = sanitize_key( wp_unslash( $_POST['field'] ?? 'custom_css' ) );
		$field_key   = sanitize_text_field( wp_unslash( $_POST['field_key'] ?? '' ) );
		$is_global   = ( ( $_POST['is_global'] ?? '0' ) === '1' );
		$is_global   = self::normalize_is_global_request( $field, $field_key, $is_global );
		$layout      = self::normalize_layout_request( $layout, $field_key );
		$goal        = sanitize_textarea_field( wp_unslash( $_POST['goal'] ?? '' ) );
		$breakpoints = array_values( array_filter( array_map( 'sanitize_key', (array) wp_unslash( $_POST['breakpoints'] ?? [] ) ) ) );
		$post_id     = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		$current_css = self::sanitize_css_payload( wp_unslash( $_POST['current_css'] ?? '' ) );
		$native_settings = self::sanitize_native_settings_payload( wp_unslash( $_POST['native_settings'] ?? '' ) );

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
		$plan   = $client->create_css_build_plan( $layout, $field, $is_global, $goal, $breakpoints, $existing_css_context, $native_settings );
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
			'native_settings'   => $native_settings,
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
			$existing_css_context,
			$native_settings
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
		self::enforce_generation_access();

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
				'html'     => self::render_generated_response( $final_result, ! empty( $session['is_global'] ) ),
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
			$existing_css_context,
			(array) ( $session['native_settings'] ?? [] )
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
		self::enforce_generation_access();

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

		$html = self::render_advice_html( $result['advice'], $is_global );

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
	 * @param bool   $is_global Whether this advice is for the global_custom_css field.
	 * @return string
	 */
	private static function render_advice_html( $markdown, $is_global = false ) {
		$lines  = explode( "\n", $markdown );
		$html   = '';
		$in_code = false;
		$code_buf = '';

		foreach ( $lines as $line ) {
			// ---- Code block toggle ----
			if ( preg_match( '/^```/', $line ) ) {
				if ( $in_code ) {
					// Close code block.
					$html   .= self::render_code_block( $code_buf, $is_global );
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
			$html .= self::render_code_block( $code_buf, $is_global );
		}

		return $html;
	}

	/**
	 * Render a CSS code block with a Copy button and optional Insert button.
	 *
	 * The "save as global style" checkbox is only offered when this snippet
	 * isn't already destined for the global field itself.
	 *
	 * @param string $code      Raw CSS code.
	 * @param bool   $is_global Whether this snippet belongs to the global_custom_css field.
	 * @return string
	 */
	private static function render_code_block( $code, $is_global = false ) {
		$code = rtrim( $code );
		if ( ! $code ) {
			return '';
		}

		$id      = 'rjm-snippet-' . wp_generate_uuid4();
		$escaped = esc_html( $code );
		$encoded = esc_attr( $code );

		$global_toggle = '';
		if ( ! $is_global ) {
			$global_toggle = sprintf(
				'<label class="rjm-global-toggle"><input type="checkbox" class="rjm-global-checkbox" /> %s</label>',
				esc_html__( 'Save as global style', 'rjm-css-advisor' )
			);
		}

		return sprintf(
			'<div class="rjm-code-block-wrap">
				<pre class="rjm-code-block" id="%s"><code>%s</code></pre>
				<div class="rjm-code-actions">
					<button type="button" class="button button-small rjm-copy-btn" data-target="%s">%s</button>
					%s
					<button type="button" class="button button-small rjm-insert-btn" data-code="%s" data-label-local="%s" data-label-global="%s">%s</button>
				</div>
			</div>',
			esc_attr( $id ),
			$escaped,
			esc_attr( $id ),
			esc_html__( 'Copy', 'rjm-css-advisor' ),
			$global_toggle,
			$encoded,
			esc_attr__( '↑ Insert into field', 'rjm-css-advisor' ),
			esc_attr__( '⇪ Save to Global CSS', 'rjm-css-advisor' ),
			esc_html__( '↑ Insert into field', 'rjm-css-advisor' )
		);
	}

	/**
	 * Render the generated CSS plus the plain-language guidance box.
	 *
	 * @param array $result    Structured AI response.
	 * @param bool  $is_global Whether this snippet belongs to the global_custom_css field.
	 * @return string
	 */
	private static function render_generated_response( $result, $is_global = false ) {
		$html = self::render_code_block( $result['css'] ?? '', $is_global );
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
			$screenshots = self::get_message_screenshots( $message );
			$missing_count = max( 0, (int) ( $message['screenshot_count'] ?? 0 ) - count( $screenshots ) );
			if ( ! $content && ! $screenshots && ! $missing_count ) {
				continue;
			}

			$class = $role === 'user' ? 'is-user' : 'is-assistant';
			echo '<div class="rjm-plan-message ' . esc_attr( $class ) . '">';
			if ( $content ) {
				echo '<p>' . esc_html( $content ) . '</p>';
			}
			foreach ( $screenshots as $screenshot ) {
				if ( empty( $screenshot['data'] ) ) {
					continue;
				}
				echo '<div class="rjm-plan-screenshot"><img loading="lazy" src="' . esc_attr( $screenshot['data'] ) . '" alt="' . esc_attr__( 'Attached screenshot', 'rjm-css-advisor' ) . '" /></div>';
			}
			if ( $missing_count ) {
				echo '<p class="rjm-plan-screenshot-missing">' . esc_html(
					sprintf(
						/* translators: %d: number of screenshots. */
						_n( '%d screenshot from this chat is no longer available.', '%d screenshots from this chat are no longer available.', $missing_count, 'rjm-css-advisor' ),
						$missing_count
					)
				) . '</p>';
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
	private static function build_memory_scope( $post_id, $field_name, $field_key, $layout, $is_global, $mode = 'ask' ) {
		return implode( '|', [
			'uid:' . get_current_user_id(),
			'pid:' . (int) $post_id,
			'field:' . sanitize_key( (string) $field_name ),
			'fieldkey:' . sanitize_key( (string) $field_key ),
			'layout:' . sanitize_key( (string) $layout ),
			'global:' . ( $is_global ? '1' : '0' ),
			'mode:' . sanitize_key( (string) $mode ),
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
	 * Validate and normalize the native ACF styling settings payload.
	 *
	 * Defensive against a tampered browser payload: whitelists keys/types and
	 * caps array/string sizes before this ever reaches the AI prompt.
	 *
	 * @param string $raw_json  JSON string from the data-native-settings attribute.
	 * @return array<int,array{label:string,name:string,type:string,choices:array<int,string>}>
	 */
	private static function sanitize_native_settings_payload( $raw_json ) {
		$raw_json = trim( (string) $raw_json );
		if ( ! $raw_json ) {
			return [];
		}

		$decoded = json_decode( $raw_json, true, 4 );
		if ( ! is_array( $decoded ) ) {
			return [];
		}

		$settings = [];
		foreach ( array_slice( $decoded, 0, 80 ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$name = sanitize_key( (string) ( $entry['name'] ?? '' ) );
			if ( ! $name ) {
				continue;
			}

			$choices = [];
			if ( ! empty( $entry['choices'] ) && is_array( $entry['choices'] ) ) {
				foreach ( array_slice( $entry['choices'], 0, 10 ) as $choice ) {
					$choice = sanitize_text_field( mb_substr( (string) $choice, 0, 40 ) );
					if ( $choice ) {
						$choices[] = $choice;
					}
				}
			}

			$scope = sanitize_key( (string) ( $entry['scope'] ?? '' ) );
			if ( ! in_array( $scope, [ 'component', 'global' ], true ) ) {
				$scope = 'component';
			}

			$settings[] = [
				'label'   => sanitize_text_field( mb_substr( (string) ( $entry['label'] ?? '' ), 0, 80 ) ),
				'name'    => $name,
				'type'    => sanitize_key( (string) ( $entry['type'] ?? '' ) ),
				'choices' => $choices,
				'scope'   => $scope,
			];
		}

		return $settings;
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
			'size' => strlen( $binary ),
		];
	}

	/**
	 * Validate a batch of screenshot data URLs.
	 *
	 * @param string|array $data  Screenshot data URL(s).
	 * @param string|array $names Original filename(s).
	 * @return array|WP_Error
	 */
	private static function validate_screenshot_payloads( $data, $names ) {
		$data = is_array( $data ) ? array_values( $data ) : ( $data ? [ $data ] : [] );
		$names = is_array( $names ) ? array_values( $names ) : ( $names ? [ $names ] : [] );
		if ( count( $data ) > self::MAX_SCREENSHOTS_PER_MESSAGE ) {
			return new WP_Error( 'screenshot_count', __( 'You can attach up to 5 screenshots per message.', 'rjm-css-advisor' ) );
		}

		$screenshots = [];
		$total_bytes = 0;
		foreach ( $data as $index => $item ) {
			$screenshot = self::validate_screenshot_payload( wp_unslash( $item ), wp_unslash( $names[ $index ] ?? '' ) );
			if ( is_wp_error( $screenshot ) ) {
				return $screenshot;
			}
			$total_bytes += (int) ( $screenshot['size'] ?? 0 );
			if ( $total_bytes > self::MAX_SCREENSHOT_MESSAGE_BYTES ) {
				return new WP_Error( 'screenshot_message_size', __( 'Screenshots in one message cannot exceed 20 MB total.', 'rjm-css-advisor' ) );
			}
			$screenshots[] = $screenshot;
		}

		return $screenshots;
	}

	/**
	 * Normalize current and legacy message attachment shapes.
	 *
	 * @param array $message
	 * @return array
	 */
	private static function get_message_screenshots( $message ) {
		if ( ! empty( $message['screenshots'] ) && is_array( $message['screenshots'] ) ) {
			return $message['screenshots'];
		}
		if ( ! empty( $message['screenshot'] ) && is_array( $message['screenshot'] ) ) {
			return [ $message['screenshot'] ];
		}
		return [];
	}

	/**
	 * Sum validated screenshot bytes in messages or attachment records.
	 *
	 * @param array $items
	 * @return int
	 */
	private static function get_screenshot_bytes( $items ) {
		$total = 0;
		foreach ( (array) $items as $item ) {
			if ( isset( $item['role'] ) || isset( $item['content'] ) ) {
				$attachments = self::get_message_screenshots( $item );
			} else {
				$attachments = [ $item ];
			}
			foreach ( $attachments as $attachment ) {
				if ( isset( $attachment['size'] ) ) {
					$total += (int) $attachment['size'];
				} elseif ( ! empty( $attachment['data'] ) ) {
					$parts = explode( ',', (string) $attachment['data'], 2 );
					$total += isset( $parts[1] ) ? (int) strlen( base64_decode( $parts[1], true ) ?: '' ) : 0;
				}
			}
		}
		return $total;
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
				unset( $message['screenshot'], $message['screenshots'] );
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
	private static function get_screenshot_data( $messages ) {
		$data = [];
		foreach ( (array) $messages as $message ) {
			foreach ( self::get_message_screenshots( $message ) as $screenshot ) {
				if ( ! empty( $screenshot['data'] ) ) {
					$data[] = (string) $screenshot['data'];
				}
			}
		}
		return $data;
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
	 * Resolve standalone Theme Settings field groups (Navbar/Footer/Banner) to
	 * their layout slug via field key, since the client can't always detect
	 * them (they're not inside an ACF flexible-content row).
	 *
	 * @param string $layout
	 * @param string $field_key
	 * @return string
	 */
	private static function normalize_layout_request( $layout, $field_key ) {
		$layout    = (string) $layout;
		$field_key = (string) $field_key;

		if ( '' !== $layout ) {
			return $layout;
		}

		return RJM_CSS_Advisor_ACF_Integration::FIELD_KEY_TO_LAYOUT[ $field_key ] ?? $layout;
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
