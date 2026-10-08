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
			customer_id bigint(20) unsigned NULL,
			session_id varchar(64) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			summary longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY session_id (session_id),
			KEY customer_id (customer_id),
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
	 * @param string   $session_id Browser session identifier.
	 * @param int|null $customer_id Optional customer ID.
	 * @return int|WP_Error
	 */
	public static function create(
		$session_id,
		$customer_id = null
	) {

		global $wpdb;

		$session_id = sanitize_text_field( $session_id );

		if ( '' === $session_id ) {
			return new WP_Error(
				'invalid_session_id',
				'Invalid conversation session.'
			);
		}

		$customer_id = absint( $customer_id );

		if ( $customer_id < 1 ) {
			$customer_id = null;
		}

		$now = current_time( 'mysql', true );

		$result = $wpdb->insert(
			self::conversations_table(),
			array(
				'customer_id' => $customer_id,
				'session_id'  => $session_id,
				'status'      => 'active',
				'summary'     => '',
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array(
				'%d',
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
	 * @param string   $session_id Browser session identifier.
	 * @param int|null $customer_id Optional customer ID.
	 * @return object|WP_Error
	 */
	public static function get_or_create(
		$session_id,
		$customer_id = null
	) {

		$conversation = self::get_by_session( $session_id );

		if ( $conversation ) {

			if (
				empty( $conversation->customer_id ) &&
				absint( $customer_id ) > 0
			) {
				self::assign_customer(
					$conversation->id,
					$customer_id
				);

				$conversation = self::get_by_session( $session_id );
			}

			return $conversation;
		}

		$conversation_id = self::create(
			$session_id,
			$customer_id
		);

		if ( is_wp_error( $conversation_id ) ) {
			return $conversation_id;
		}

		return self::get_by_session( $session_id );
	}

	/**
	 * Assign a customer to a conversation.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @param int $customer_id Customer ID.
	 * @return bool|WP_Error
	 */
	public static function assign_customer(
		$conversation_id,
		$customer_id
	) {

		global $wpdb;

		$conversation_id = absint( $conversation_id );
		$customer_id     = absint( $customer_id );

		if ( $conversation_id < 1 ) {
			return new WP_Error(
				'invalid_conversation',
				'Invalid conversation.'
			);
		}

		if ( $customer_id < 1 ) {
			return new WP_Error(
				'invalid_customer',
				'Invalid customer.'
			);
		}

		$conversation = self::get_by_id( $conversation_id );

		if ( ! $conversation ) {
			return new WP_Error(
				'conversation_not_found',
				'Conversation not found.'
			);
		}

		$result = $wpdb->update(
			self::conversations_table(),
			array(
				'customer_id' => $customer_id,
				'updated_at'  => current_time( 'mysql', true ),
			),
			array(
				'id' => $conversation_id,
			),
			array(
				'%d',
				'%s',
			),
			array(
				'%d',
			)
		);

		if ( false === $result ) {
			return new WP_Error(
				'conversation_customer_update_failed',
				'The conversation could not be associated with the customer.'
			);
		}

		return true;
	}

	/**
	 * Get conversations belonging to a customer.
	 *
	 * @param int $customer_id Customer ID.
	 * @param int $limit Number of conversations.
	 * @return array
	 */
	public static function get_by_customer(
		$customer_id,
		$limit = 50
	) {

		global $wpdb;

		$customer_id = absint( $customer_id );
		$limit       = absint( $limit );

		if ( $customer_id < 1 ) {
			return array();
		}

		if ( $limit < 1 ) {
			$limit = 50;
		}

		if ( $limit > 100 ) {
			$limit = 100;
		}

		$table = self::conversations_table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *
				FROM {$table}
				WHERE customer_id = %d
				ORDER BY updated_at DESC
				LIMIT %d",
				$customer_id,
				$limit
			)
		);
	}

	/**
	 * Set the active verified ticket for a conversation.
	 *
	 * @param int      $conversation_id Conversation ID.
	 * @param string   $ticket_key Ticket number.
	 * @param int|null $customer_id Verified customer ID.
	 * @return bool|WP_Error
	 */
	public static function set_active_ticket(
		$conversation_id,
		$ticket_key,
		$customer_id = null
	) {

		global $wpdb;

		$conversation_id = absint( $conversation_id );
		$ticket_key      = strtoupper(
			sanitize_text_field( $ticket_key )
		);
		$customer_id     = absint( $customer_id );

		if ( $conversation_id < 1 ) {
			return new WP_Error(
				'invalid_conversation',
				'Invalid conversation.'
			);
		}

		if ( '' === $ticket_key ) {
			return new WP_Error(
				'invalid_ticket_key',
				'Invalid ticket number.'
			);
		}

		$conversation = self::get_by_id( $conversation_id );

		if ( ! $conversation ) {
			return new WP_Error(
				'conversation_not_found',
				'Conversation not found.'
			);
		}

		$summary_data = self::get_summary_data(
			$conversation
		);

		$summary_data['active_ticket_key'] = $ticket_key;
		$summary_data['active_ticket_verified'] = true;

		if ( $customer_id > 0 ) {
			$summary_data['active_ticket_customer_id'] = $customer_id;
		} else {
			unset(
				$summary_data['active_ticket_customer_id']
			);
		}

		return self::save_summary_data(
			$conversation_id,
			$summary_data
		);
	}

	/**
	 * Get the active verified ticket key.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return string
	 */
	public static function get_active_ticket_key(
		$conversation_id
	) {

		$conversation_id = absint( $conversation_id );

		if ( $conversation_id < 1 ) {
			return '';
		}

		$conversation = self::get_by_id(
			$conversation_id
		);

		if ( ! $conversation ) {
			return '';
		}

		$summary_data = self::get_summary_data(
			$conversation
		);

		if (
			empty(
				$summary_data['active_ticket_key']
			) ||
			empty(
				$summary_data['active_ticket_verified']
			)
		) {
			return '';
		}

		return strtoupper(
			sanitize_text_field(
				$summary_data['active_ticket_key']
			)
		);
	}

	/**
	 * Get the verified customer ID associated with the active ticket.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return int|null
	 */
	public static function get_active_ticket_customer_id(
		$conversation_id
	) {

		$conversation_id = absint( $conversation_id );

		if ( $conversation_id < 1 ) {
			return null;
		}

		$conversation = self::get_by_id(
			$conversation_id
		);

		if ( ! $conversation ) {
			return null;
		}

		$summary_data = self::get_summary_data(
			$conversation
		);

		if (
			empty(
				$summary_data['active_ticket_verified']
			) ||
			empty(
				$summary_data['active_ticket_customer_id']
			)
		) {
			return null;
		}

		return absint(
			$summary_data['active_ticket_customer_id']
		);
	}

	/**
 * Set a pending sensitive escalation request.
 *
 * The ticket is NOT created here.
 *
 * @param int    $conversation_id Conversation ID.
 * @param string $subject Ticket subject.
 * @param string $summary Ticket summary.
 * @param string $priority Ticket priority.
 * @param string $reason Escalation reason.
 * @param bool   $confirmed Whether the customer has already confirmed creation.
 * @return bool|WP_Error
 */
public static function set_pending_sensitive_escalation(
	$conversation_id,
	$subject,
	$summary,
	$priority = 'normal',
	$reason = '',
	$confirmed = false
) {

	$conversation_id = absint( $conversation_id );

	if ( $conversation_id < 1 ) {
		return new WP_Error(
			'invalid_conversation',
			'Invalid conversation.'
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

	$priority = sanitize_key( $priority );

	if (
		! in_array(
			$priority,
			array(
				'low',
				'normal',
				'high',
				'urgent',
			),
			true
		)
	) {
		$priority = 'normal';
	}

	$pending = array(
		'subject'   => sanitize_text_field( $subject ),
		'summary'   => sanitize_textarea_field( $summary ),
		'priority'  => $priority,
		'reason'    => sanitize_textarea_field( $reason ),
		'confirmed' => (bool) $confirmed,
	);

	$summary_data = self::get_summary_data(
		$conversation
	);

	$summary_data['pending_sensitive_escalation'] = $pending;

	return self::save_summary_data(
		$conversation_id,
		$summary_data
	);
} 
	/**
	 * Get a pending sensitive escalation request.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return array|null
	 */
	public static function get_pending_sensitive_escalation(
		$conversation_id
	) {

		$conversation_id = absint( $conversation_id );

		if ( $conversation_id < 1 ) {
			return null;
		}

		$conversation = self::get_by_id(
			$conversation_id
		);

		if ( ! $conversation ) {
			return null;
		}

		$summary_data = self::get_summary_data(
			$conversation
		);

		if (
			empty(
				$summary_data['pending_sensitive_escalation']
			) ||
			! is_array(
				$summary_data['pending_sensitive_escalation']
			)
		) {
			return null;
		}

		return $summary_data['pending_sensitive_escalation'];
	}

	/**
	 * Clear pending sensitive escalation state.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return bool|WP_Error
	 */
	public static function clear_pending_sensitive_escalation(
		$conversation_id
	) {

		$conversation_id = absint( $conversation_id );

		if ( $conversation_id < 1 ) {
			return new WP_Error(
				'invalid_conversation',
				'Invalid conversation.'
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

		$summary_data = self::get_summary_data(
			$conversation
		);

		unset(
			$summary_data['pending_sensitive_escalation']
		);

		return self::save_summary_data(
			$conversation_id,
			$summary_data
		);
	}

	/**
	 * Clear the active ticket state.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return bool|WP_Error
	 */
	public static function clear_active_ticket(
		$conversation_id
	) {

		$conversation_id = absint( $conversation_id );

		if ( $conversation_id < 1 ) {
			return new WP_Error(
				'invalid_conversation',
				'Invalid conversation.'
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

		$summary_data = self::get_summary_data(
			$conversation
		);

		unset(
			$summary_data['active_ticket_key'],
			$summary_data['active_ticket_verified'],
			$summary_data['active_ticket_customer_id']
		);

		return self::save_summary_data(
			$conversation_id,
			$summary_data
		);
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

		if (
			! in_array(
				$role,
				array( 'user', 'assistant' ),
				true
			)
		) {
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

		$now = current_time(
			'mysql',
			true
		);

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

		$conversation_id = absint(
			$conversation_id
		);

		if ( $conversation_id < 1 ) {
			return null;
		}

		$table = self::conversations_table();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT *
				FROM {$table}
				WHERE id = %d
				LIMIT 1",
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

		$conversation_id = absint(
			$conversation_id
		);

		$limit = absint(
			$limit
		);

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

	/**
	 * Decode conversation summary state.
	 *
	 * @param object $conversation Conversation object.
	 * @return array
	 */
	private static function get_summary_data(
		$conversation
	) {

		if (
			! $conversation ||
			empty( $conversation->summary )
		) {
			return array();
		}

		$decoded = json_decode(
			$conversation->summary,
			true
		);

		return is_array( $decoded )
			? $decoded
			: array();
	}

	/**
	 * Save conversation summary state.
	 *
	 * @param int   $conversation_id Conversation ID.
	 * @param array $summary_data Summary data.
	 * @return bool|WP_Error
	 */
	private static function save_summary_data(
		$conversation_id,
		$summary_data
	) {

		global $wpdb;

		$result = $wpdb->update(
			self::conversations_table(),
			array(
				'summary'    => wp_json_encode( $summary_data ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array(
				'id' => absint( $conversation_id ),
			),
			array(
				'%s',
				'%s',
			),
			array(
				'%d',
			)
		);

		if ( false === $result ) {
			return new WP_Error(
				'conversation_state_update_failed',
				'Conversation state could not be saved.'
			);
		}

		return true;
	}
}