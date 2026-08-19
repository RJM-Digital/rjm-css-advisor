<?php
/**
 * Persistent Ask/Plan chat history, scoped to a single component instance.
 *
 * Storage (current user's user meta):
 *   rjm_css_chats_{scope_hash}             - lightweight index, newest first
 *   rjm_css_chat_{scope_hash}_{chat_id}    - full chat record
 *
 * Screenshots are never persisted; only a per-message count is retained so the
 * transcript can show a placeholder when an expired chat is reopened.
 */

defined( 'ABSPATH' ) || exit;

class RJM_CSS_Advisor_Chat_History {

	const INDEX_PREFIX   = 'rjm_css_chats_';
	const CHAT_PREFIX    = 'rjm_css_chat_';
	const CRON_HOOK      = 'rjm_css_advisor_prune_chats';
	const EXPIRY         = 90 * DAY_IN_SECONDS;
	const MAX_CHATS      = 100;
	const MAX_MESSAGES   = 200;
	const MAX_TITLE_LEN  = 80;
	const PREVIEW_LEN    = 120;

	/**
	 * Register the daily prune schedule.
	 */
	public static function init() {
		add_action( self::CRON_HOOK, [ __CLASS__, 'prune_all_users' ] );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	// -------------------------------------------------------------------------
	// Keys
	// -------------------------------------------------------------------------

	/**
	 * @param string $scope Raw scope string from build_memory_scope().
	 * @return string
	 */
	public static function scope_hash( $scope ) {
		return md5( (string) $scope );
	}

	private static function index_key( $scope_hash ) {
		return self::INDEX_PREFIX . $scope_hash;
	}

	private static function chat_key( $scope_hash, $chat_id ) {
		return self::CHAT_PREFIX . $scope_hash . '_' . md5( (string) $chat_id );
	}

	// -------------------------------------------------------------------------
	// Read
	// -------------------------------------------------------------------------

	/**
	 * Chat index for a scope, newest first.
	 *
	 * @param string $scope
	 * @return array
	 */
	public static function get_index( $scope ) {
		$data = get_user_meta( get_current_user_id(), self::index_key( self::scope_hash( $scope ) ), true );

		return is_array( $data ) ? array_values( $data ) : [];
	}

	/**
	 * @param string $scope
	 * @param string $chat_id
	 * @return array|null
	 */
	public static function get_chat( $scope, $chat_id ) {
		if ( ! $chat_id ) {
			return null;
		}

		$data = get_user_meta( get_current_user_id(), self::chat_key( self::scope_hash( $scope ), $chat_id ), true );

		return is_array( $data ) && ! empty( $data['id'] ) ? $data : null;
	}

	/**
	 * Most recently updated chat id for a scope.
	 *
	 * @param string $scope
	 * @return string
	 */
	public static function latest_chat_id( $scope ) {
		$index = self::get_index( $scope );

		return (string) ( $index[0]['id'] ?? '' );
	}

	// -------------------------------------------------------------------------
	// Write
	// -------------------------------------------------------------------------

	/**
	 * Persist the state of a plan session after a completed turn.
	 *
	 * @param string $scope
	 * @param string $chat_id
	 * @param array  $session           Live plan session array.
	 * @param bool   $ready_to_generate
	 * @return array The saved chat record.
	 */
	public static function record_turn( $scope, $chat_id, $session, $ready_to_generate = false ) {
		$scope_hash = self::scope_hash( $scope );
		$existing   = self::get_chat( $scope, $chat_id );
		$messages   = self::normalize_messages( (array) ( $session['messages'] ?? [] ) );
		$now        = time();

		$chat = [
			'id'                => (string) $chat_id,
			'mode'              => 'ask',
			'title'             => (string) ( $existing['title'] ?? '' ),
			'title_source'      => (string) ( $existing['title_source'] ?? '' ),
			'created_at'        => (int) ( $existing['created_at'] ?? $now ),
			'updated_at'        => $now,
			'layout'            => (string) ( $session['layout'] ?? '' ),
			'field'             => (string) ( $session['field'] ?? '' ),
			'is_global'         => ! empty( $session['is_global'] ),
			'breakpoints'       => array_values( (array) ( $session['breakpoints'] ?? [] ) ),
			'brief'             => (string) ( $session['brief'] ?? '' ),
			'existing_css_context' => (string) ( $session['existing_css_context'] ?? '' ),
			'ready_to_generate' => (bool) $ready_to_generate,
			'messages'          => $messages,
		];

		if ( ! $chat['title'] ) {
			$chat['title']        = self::fallback_title( $messages );
			$chat['title_source'] = 'fallback';
		}

		self::save_chat( $scope_hash, $chat );
		self::update_index_entry( $scope_hash, $chat );

		return $chat;
	}

	/**
	 * Replace a chat title.
	 *
	 * @param string $scope
	 * @param string $chat_id
	 * @param string $title
	 * @param string $source fallback|ai|manual
	 * @return string The stored title, or empty string when the chat is missing.
	 */
	public static function set_title( $scope, $chat_id, $title, $source = 'manual' ) {
		$chat = self::get_chat( $scope, $chat_id );
		if ( ! $chat ) {
			return '';
		}

		$title = self::sanitize_title( $title );
		if ( ! $title ) {
			return (string) $chat['title'];
		}

		$scope_hash           = self::scope_hash( $scope );
		$chat['title']        = $title;
		$chat['title_source'] = in_array( $source, [ 'fallback', 'ai', 'manual' ], true ) ? $source : 'manual';

		self::save_chat( $scope_hash, $chat );
		self::update_index_entry( $scope_hash, $chat );

		return $title;
	}

	/**
	 * @param string $scope
	 * @param string $chat_id
	 * @return bool
	 */
	public static function delete_chat( $scope, $chat_id ) {
		$scope_hash = self::scope_hash( $scope );
		$user_id    = get_current_user_id();

		delete_user_meta( $user_id, self::chat_key( $scope_hash, $chat_id ) );

		$index = self::get_index( $scope );
		$index = array_values( array_filter(
			$index,
			static function ( $entry ) use ( $chat_id ) {
				return (string) ( $entry['id'] ?? '' ) !== (string) $chat_id;
			}
		) );

		self::save_index( $scope_hash, $index );

		return true;
	}

	/**
	 * Remove every chat stored for a scope.
	 *
	 * @param string $scope
	 * @return int Number of chats removed.
	 */
	public static function clear_all( $scope ) {
		$scope_hash = self::scope_hash( $scope );
		$user_id    = get_current_user_id();
		$index      = self::get_index( $scope );

		foreach ( $index as $entry ) {
			delete_user_meta( $user_id, self::chat_key( $scope_hash, (string) ( $entry['id'] ?? '' ) ) );
		}

		delete_user_meta( $user_id, self::index_key( $scope_hash ) );

		return count( $index );
	}

	// -------------------------------------------------------------------------
	// Internals
	// -------------------------------------------------------------------------

	private static function save_chat( $scope_hash, $chat ) {
		update_user_meta( get_current_user_id(), self::chat_key( $scope_hash, $chat['id'] ), $chat );
	}

	private static function save_index( $scope_hash, $index ) {
		update_user_meta( get_current_user_id(), self::index_key( $scope_hash ), array_values( $index ) );
	}

	/**
	 * Move a chat to the top of the index, then prune expired and surplus entries.
	 */
	private static function update_index_entry( $scope_hash, $chat ) {
		$user_id = get_current_user_id();
		$index   = get_user_meta( $user_id, self::index_key( $scope_hash ), true );
		$index   = is_array( $index ) ? array_values( $index ) : [];

		$index = array_values( array_filter(
			$index,
			static function ( $entry ) use ( $chat ) {
				return (string) ( $entry['id'] ?? '' ) !== $chat['id'];
			}
		) );

		array_unshift( $index, [
			'id'            => $chat['id'],
			'title'         => $chat['title'],
			'title_source'  => $chat['title_source'],
			'mode'          => $chat['mode'],
			'preview'       => self::build_preview( $chat['messages'] ),
			'message_count' => count( $chat['messages'] ),
			'created_at'    => $chat['created_at'],
			'updated_at'    => $chat['updated_at'],
		] );

		$index = self::prune_index( $scope_hash, $index );

		self::save_index( $scope_hash, $index );
	}

	/**
	 * Drop entries past the expiry window or beyond the per-scope cap, deleting their records.
	 *
	 * @return array The retained index.
	 */
	private static function prune_index( $scope_hash, $index ) {
		$user_id  = get_current_user_id();
		$cutoff   = time() - self::EXPIRY;
		$retained = [];
		$removed  = [];

		foreach ( $index as $entry ) {
			$id = (string) ( $entry['id'] ?? '' );
			if ( ! $id ) {
				continue;
			}

			if ( (int) ( $entry['updated_at'] ?? 0 ) < $cutoff || count( $retained ) >= self::MAX_CHATS ) {
				$removed[] = $id;
				continue;
			}

			$retained[] = $entry;
		}

		foreach ( $removed as $id ) {
			delete_user_meta( $user_id, self::chat_key( $scope_hash, $id ) );
		}

		return $retained;
	}

	/**
	 * Strip screenshot payloads, stamp timestamps, and cap transcript length.
	 *
	 * @param array $messages
	 * @return array
	 */
	private static function normalize_messages( $messages ) {
		$out = [];
		$now = time();

		foreach ( (array) $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$content = trim( (string) ( $message['content'] ?? '' ) );
			$shots   = [];
			if ( ! empty( $message['screenshots'] ) && is_array( $message['screenshots'] ) ) {
				$shots = $message['screenshots'];
			} elseif ( ! empty( $message['screenshot'] ) && is_array( $message['screenshot'] ) ) {
				$shots = [ $message['screenshot'] ];
			}

			$count = isset( $message['screenshot_count'] ) ? (int) $message['screenshot_count'] : count( $shots );

			if ( ! $content && ! $count ) {
				continue;
			}

			$out[] = [
				'role'             => ( ( $message['role'] ?? '' ) === 'user' ) ? 'user' : 'assistant',
				'content'          => $content,
				'screenshot_count' => $count,
				'created_at'       => (int) ( $message['created_at'] ?? $now ),
			];
		}

		return array_slice( $out, -self::MAX_MESSAGES );
	}

	/**
	 * @param array $messages
	 * @return string
	 */
	private static function fallback_title( $messages ) {
		foreach ( (array) $messages as $message ) {
			if ( ( $message['role'] ?? '' ) === 'user' && ! empty( $message['content'] ) ) {
				return self::sanitize_title( $message['content'] );
			}
		}

		return __( 'Untitled chat', 'rjm-css-advisor' );
	}

	/**
	 * @param array $messages
	 * @return string
	 */
	private static function build_preview( $messages ) {
		$last = end( $messages );
		if ( ! is_array( $last ) ) {
			return '';
		}

		return self::truncate( (string) ( $last['content'] ?? '' ), self::PREVIEW_LEN );
	}

	/**
	 * @param string $title
	 * @return string
	 */
	public static function sanitize_title( $title ) {
		$title = sanitize_text_field( (string) $title );
		$title = trim( preg_replace( '/\s+/', ' ', $title ) );
		$title = trim( $title, "\"'“”‘’ " );

		return self::truncate( $title, self::MAX_TITLE_LEN );
	}

	private static function truncate( $text, $length ) {
		$text = trim( (string) $text );

		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > $length ) {
			return rtrim( mb_substr( $text, 0, $length - 1 ) ) . '…';
		}

		if ( ! function_exists( 'mb_strlen' ) && strlen( $text ) > $length ) {
			return rtrim( substr( $text, 0, $length - 1 ) ) . '…';
		}

		return $text;
	}

	// -------------------------------------------------------------------------
	// Cron
	// -------------------------------------------------------------------------

	/**
	 * Delete chat records that fell out of the retention window.
	 *
	 * Indexes are rewritten lazily on next write; the records themselves are the
	 * bulk of the storage, so they are removed here directly.
	 */
	public static function prune_all_users() {
		global $wpdb;

		$cutoff = time() - self::EXPIRY;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT umeta_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
				$wpdb->esc_like( self::CHAT_PREFIX ) . '%'
			)
		);

		foreach ( (array) $rows as $row ) {
			$chat = maybe_unserialize( $row->meta_value );
			if ( ! is_array( $chat ) || (int) ( $chat['updated_at'] ?? 0 ) >= $cutoff ) {
				continue;
			}

			$wpdb->delete( $wpdb->usermeta, [ 'umeta_id' => (int) $row->umeta_id ], [ '%d' ] );
		}
	}
}
