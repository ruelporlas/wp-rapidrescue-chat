<?php
/**
 * Conversation and message storage.
 *
 * @package WP_RapidRescue_Chat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles chat conversations and messages.
 */
class WP_RapidRescue_Chat_Conversation {

	/**
	 * Number of recent messages to provide to the AI.
	 */
	const RECENT_MESSAGE_LIMIT = 20;

	/**
	 * Get conversations table name.
	 *
	 * @return string
	 */
	private static function conversations_table() {
		global $wpdb;

		return $wpdb->prefix . 'rr_conversations';
	}

	/**
	 * Get messages table name.
	 *
	 * @return string
	 */
	private static function messages_table() {
		global $wpdb;

		return $wpdb->prefix . 'rr_messages';
	}

	/**
	 * Create database tables.
	 *
	 * @return void
	 */
	public static function create_tables() {

		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$conversations_table = self::conversations_table();
		$messages_table      = self::messages_table();

		$sql = "CREATE TABLE {$conversations_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id varchar(64) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			summary longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY session_id (session_id),
			KEY status (status),
			KEY updated_at (updated_at)
		) {$charset_collate};

		CREATE TABLE {$messages_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			conversation_id bigint(20) unsigned NOT NULL,
			role varchar(20) NOT NULL,
			message longtext NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY conversation_id (conversation_id),
			KEY conversation_created (conversation_id, created_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Create a new conversation.
	 *
	 * @param string $session_id Browser session identifier.
	 * @return int|WP_Error
	 */
	public static function create( $session_id ) {

		global $wpdb;

		$session_id = sanitize_text_field( $session_id );

		if ( '' === $session_id ) {
			return new WP_Error(
				'invalid_session_id',
				'Invalid conversation session.'
			);
		}

		$now = current_time( 'mysql', true );

		$result = $wpdb->insert(
			self::conversations_table(),
			array(
				'session_id' => $session_id,
				'status'     => 'active',
				'summary'    => '',
				'created_at' => $now,
				'updated_at' => $now,
			),
			array(
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
			)
		);

		if ( false === $result ) {
			return new WP_Error(
				'conversation_create_failed',
				'The conversation could not be created.'
			);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Find a conversation by session ID.
	 *
	 * @param string $session_id Browser session identifier.
	 * @return object|null
	 */
	public static function get_by_session( $session_id ) {

		global $wpdb;

		$session_id = sanitize_text_field( $session_id );

		if ( '' === $session_id ) {
			return null;
		}

		$table = self::conversations_table();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE session_id = %s LIMIT 1",
				$session_id
			)
		);
	}

	/**
	 * Get an existing conversation or create one.
	 *
	 * @param string $session_id Browser session identifier.
	 * @return object|WP_Error
	 */
	public static function get_or_create( $session_id ) {

		$conversation = self::get_by_session( $session_id );

		if ( $conversation ) {
			return $conversation;
		}

		$conversation_id = self::create( $session_id );

		if ( is_wp_error( $conversation_id ) ) {
			return $conversation_id;
		}

		return self::get_by_session( $session_id );
	}

	/**
	 * Add a message to a conversation.
	 *
	 * @param int    $conversation_id Conversation ID.
	 * @param string $role Message role.
	 * @param string $message Message content.
	 * @return int|WP_Error
	 */
	public static function add_message(
		$conversation_id,
		$role,
		$message
	) {

		global $wpdb;

		$conversation_id = absint( $conversation_id );
		$role            = sanitize_key( $role );
		$message         = sanitize_textarea_field( $message );

		if ( $conversation_id < 1 ) {
			return new WP_Error(
				'invalid_conversation',
				'Invalid conversation.'
			);
		}

		if ( ! in_array(
			$role,
			array( 'user', 'assistant' ),
			true
		) ) {
			return new WP_Error(
				'invalid_message_role',
				'Invalid message role.'
			);
		}

		if ( '' === trim( $message ) ) {
			return new WP_Error(
				'empty_message',
				'The message cannot be empty.'
			);
		}

		$conversation = self::get_by_id(
			$conversation_id
		);

		if ( ! $conversation ) {
			return new WP_Error(
				'conversation_not_found',
				'Conversation not found.'
			);
		}

		$now = current_time( 'mysql', true );

		$result = $wpdb->insert(
			self::messages_table(),
			array(
				'conversation_id' => $conversation_id,
				'role'            => $role,
				'message'         => $message,
				'created_at'      => $now,
			),
			array(
				'%d',
				'%s',
				'%s',
				'%s',
			)
		);

		if ( false === $result ) {
			return new WP_Error(
				'message_save_failed',
				'The message could not be saved.'
			);
		}

		$wpdb->update(
			self::conversations_table(),
			array(
				'updated_at' => $now,
			),
			array(
				'id' => $conversation_id,
			),
			array(
				'%s',
			),
			array(
				'%d',
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Get a conversation by ID.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return object|null
	 */
	public static function get_by_id( $conversation_id ) {

		global $wpdb;

		$conversation_id = absint( $conversation_id );

		if ( $conversation_id < 1 ) {
			return null;
		}

		$table = self::conversations_table();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d LIMIT 1",
				$conversation_id
			)
		);
	}

	/**
	 * Get recent messages for a conversation.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @param int $limit Number of messages.
	 * @return array
	 */
	public static function get_recent_messages(
		$conversation_id,
		$limit = self::RECENT_MESSAGE_LIMIT
	) {

		global $wpdb;

		$conversation_id = absint( $conversation_id );
		$limit           = absint( $limit );

		if ( $conversation_id < 1 ) {
			return array();
		}

		if ( $limit < 1 ) {
			$limit = self::RECENT_MESSAGE_LIMIT;
		}

		if ( $limit > 50 ) {
			$limit = 50;
		}

		$table = self::messages_table();

		$messages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT role, message, created_at
				FROM {$table}
				WHERE conversation_id = %d
				ORDER BY id DESC
				LIMIT %d",
				$conversation_id,
				$limit
			)
		);

		if ( empty( $messages ) ) {
			return array();
		}

		return array_reverse( $messages );
	}
}