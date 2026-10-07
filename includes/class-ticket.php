<?php
/**
 * Ticket storage and management.
 *
 * @package WP_RapidRescue_Chat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles support tickets.
 */
class WP_RapidRescue_Chat_Ticket {

	/**
	 * Get tickets table name.
	 *
	 * @return string
	 */
	private static function table() {

		global $wpdb;

		return $wpdb->prefix . 'rr_tickets';
	}

	/**
	 * Extract a ticket key from a customer message.
	 *
	 * @param string $message Customer message.
	 * @return string
	 */
	public static function extract_ticket_key( $message ) {

		$message = (string) $message;

		if ( '' === trim( $message ) ) {
			return '';
		}

		if (
			preg_match(
				'/\bRR-\d{1,10}\b/i',
				$message,
				$matches
			)
		) {
			return strtoupper(
				sanitize_text_field(
					$matches[0]
				)
			);
		}

		return '';
	}

	/**
	 * Create or update the tickets database table.
	 *
	 * @return void
	 */
	public static function create_table() {

		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table           = self::table();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			ticket_key varchar(20) NOT NULL DEFAULT '',
			customer_id bigint(20) unsigned NULL,
			customer_email varchar(191) NOT NULL DEFAULT '',
			conversation_id bigint(20) unsigned NULL,
			subject varchar(255) NOT NULL DEFAULT '',
			summary longtext NULL,
			status varchar(20) NOT NULL DEFAULT 'open',
			priority varchar(20) NOT NULL DEFAULT 'normal',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY ticket_key (ticket_key),
			KEY customer_id (customer_id),
			KEY customer_email (customer_email),
			KEY conversation_id (conversation_id),
			KEY status (status),
			KEY priority (priority),
			KEY updated_at (updated_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Create a ticket.
	 *
	 * @param string   $subject         Ticket subject.
	 * @param string   $summary         Technical/customer summary.
	 * @param int|null $customer_id     Customer ID.
	 * @param int|null $conversation_id Conversation ID.
	 * @param string   $priority        Ticket priority.
	 * @return int|WP_Error
	 */
	public static function create(
		$subject = '',
		$summary = '',
		$customer_id = null,
		$conversation_id = null,
		$priority = 'normal'
	) {

		global $wpdb;

		$subject = sanitize_text_field( $subject );
		$summary = sanitize_textarea_field( $summary );

		$customer_id = absint( $customer_id );

		if ( $customer_id < 1 ) {
			$customer_id = null;
		}

		$customer_email = '';

		if ( $customer_id ) {

			$customer = WP_RapidRescue_Chat_Customer::get_by_id(
				$customer_id
			);

			if ( $customer && ! empty( $customer->email ) ) {
				$customer_email = sanitize_email(
					$customer->email
				);
			}
		}

		$conversation_id = absint( $conversation_id );

		if ( $conversation_id < 1 ) {
			$conversation_id = null;
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

		if ( '' === $subject ) {
			$subject = 'Customer Support Request';
		}

		if ( '' === trim( $summary ) ) {
			$summary = $subject;
		}

		$now = current_time(
			'mysql',
			true
		);

		$result = $wpdb->insert(
			self::table(),
			array(
				'ticket_key'     => '',
				'customer_id'    => $customer_id,
				'customer_email' => $customer_email,
				'conversation_id'=> $conversation_id,
				'subject'        => $subject,
				'summary'        => $summary,
				'status'         => 'open',
				'priority'       => $priority,
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array(
				'%s',
				'%d',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
			)
		);

		if ( false === $result ) {
			return new WP_Error(
				'ticket_create_failed',
				'The ticket could not be created.'
			);
		}

		$ticket_id = (int) $wpdb->insert_id;

		$ticket_key = 'RR-' . str_pad(
			(string) $ticket_id,
			5,
			'0',
			STR_PAD_LEFT
		);

		$updated = $wpdb->update(
			self::table(),
			array(
				'ticket_key' => $ticket_key,
				'updated_at' => $now,
			),
			array(
				'id' => $ticket_id,
			),
			array(
				'%s',
				'%s',
			),
			array(
				'%d',
			)
		);

		if ( false === $updated ) {
			return new WP_Error(
				'ticket_key_update_failed',
				'The ticket was created but its reference number could not be assigned.'
			);
		}

		return $ticket_id;
	}

	/**
	 * Create a ticket from a conversation.
	 *
	 * @param int         $conversation_id Conversation ID.
	 * @param int|null    $customer_id Customer ID.
	 * @param string      $subject Ticket subject.
	 * @param string      $summary Ticket summary.
	 * @param string      $priority Ticket priority.
	 * @param bool        $allow_new_issue Whether this is explicitly a new issue.
	 * @return array|WP_Error
	 */
	public static function create_from_conversation(
		$conversation_id,
		$customer_id = null,
		$subject = '',
		$summary = '',
		$priority = 'normal',
		$allow_new_issue = false
	) {

		$conversation_id = absint(
			$conversation_id
		);

		$customer_id = absint(
			$customer_id
		);

		if ( $conversation_id < 1 ) {
			return new WP_Error(
				'invalid_conversation',
				'The conversation could not be identified.'
			);
		}

		$conversation =
			WP_RapidRescue_Chat_Conversation::get_by_id(
				$conversation_id
			);

		if ( ! $conversation ) {
			return new WP_Error(
				'conversation_not_found',
				'The conversation could not be found.'
			);
		}

		if ( $customer_id < 1 ) {

			$customer_id = absint(
				$conversation->customer_id
			);

			if ( $customer_id < 1 ) {
				$customer_id = null;
			}
		}

		if ( ! $allow_new_issue ) {

			$existing_tickets =
				self::get_by_conversation(
					$conversation_id,
					10
				);

			if ( ! empty( $existing_tickets ) ) {

				foreach (
					$existing_tickets as $existing_ticket
				) {

					if (
						in_array(
							$existing_ticket->status,
							array(
								'open',
								'in_progress',
								'waiting_customer',
							),
							true
						)
					) {

						return array(
							'created'        => false,
							'already_exists' => true,
							'ticket_id'      =>
								(int) $existing_ticket->id,
							'ticket_key'     =>
								$existing_ticket->ticket_key,
							'ticket'         =>
								$existing_ticket,
						);
					}
				}
			}
		}

		$ticket_id = self::create(
			$subject,
			$summary,
			$customer_id,
			$conversation_id,
			$priority
		);

		if ( is_wp_error( $ticket_id ) ) {
			return $ticket_id;
		}

		$ticket = self::get_by_id(
			$ticket_id
		);

		if ( ! $ticket ) {
			return new WP_Error(
				'ticket_confirmation_failed',
				'The ticket was created but could not be confirmed.'
			);
		}

		return array(
			'created'        => true,
			'already_exists' => false,
			'ticket_id'     => (int) $ticket->id,
			'ticket_key'    => $ticket->ticket_key,
			'ticket'        => $ticket,
		);
	}

	/**
	 * Get a ticket by database ID.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return object|null
	 */
	public static function get_by_id(
		$ticket_id
	) {

		global $wpdb;

		$ticket_id = absint(
			$ticket_id
		);

		if ( $ticket_id < 1 ) {
			return null;
		}

		$table = self::table();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT *
				FROM {$table}
				WHERE id = %d
				LIMIT 1",
				$ticket_id
			)
		);
	}

	/**
	 * Get a ticket by human-friendly ticket key.
	 *
	 * @param string $ticket_key Ticket key.
	 * @return object|null
	 */
	public static function get_by_key(
		$ticket_key
	) {

		global $wpdb;

		$ticket_key = strtoupper(
			sanitize_text_field(
				$ticket_key
			)
		);

		if ( '' === $ticket_key ) {
			return null;
		}

		$table = self::table();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT *
				FROM {$table}
				WHERE ticket_key = %s
				LIMIT 1",
				$ticket_key
			)
		);
	}

	/**
	 * Assign an anonymous ticket to a verified customer.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @param int $customer_id Customer ID.
	 * @return bool|WP_Error
	 */
	public static function assign_customer(
		$ticket_id,
		$customer_id
	) {

		global $wpdb;

		$ticket_id   = absint( $ticket_id );
		$customer_id = absint( $customer_id );

		if ( $ticket_id < 1 ) {
			return new WP_Error(
				'invalid_ticket',
				'Invalid ticket.'
			);
		}

		if ( $customer_id < 1 ) {
			return new WP_Error(
				'invalid_customer',
				'Invalid customer.'
			);
		}

		$ticket = self::get_by_id(
			$ticket_id
		);

		if ( ! $ticket ) {
			return new WP_Error(
				'ticket_not_found',
				'Ticket not found.'
			);
		}

		$customer = WP_RapidRescue_Chat_Customer::get_by_id(
			$customer_id
		);

		$customer_email = '';

		if ( $customer && ! empty( $customer->email ) ) {
			$customer_email = sanitize_email(
				$customer->email
			);
		}

		if ( absint( $ticket->customer_id ) > 0 ) {

			if (
				absint( $ticket->customer_id ) ===
				$customer_id
			) {

				if ( '' !== $customer_email ) {
					$wpdb->update(
						self::table(),
						array(
							'customer_email' => $customer_email,
							'updated_at'     =>
								current_time(
									'mysql',
									true
								),
						),
						array(
							'id' => $ticket_id,
						),
						array(
							'%s',
							'%s',
						),
						array(
							'%d',
						)
					);
				}

				return true;
			}

			return new WP_Error(
				'ticket_customer_conflict',
				'This ticket is already associated with another customer.'
			);
		}

		$result = $wpdb->update(
			self::table(),
			array(
				'customer_id'    => $customer_id,
				'customer_email' => $customer_email,
				'updated_at'     => current_time(
					'mysql',
					true
				),
			),
			array(
				'id' => $ticket_id,
			),
			array(
				'%d',
				'%s',
				'%s',
			),
			array(
				'%d',
			)
		);

		if ( false === $result ) {
			return new WP_Error(
				'ticket_customer_update_failed',
				'The ticket could not be associated with the customer.'
			);
		}

		return true;
	}

	/**
	 * Get tickets belonging to a customer.
	 *
	 * @param int $customer_id Customer ID.
	 * @param int $limit Maximum number of tickets.
	 * @return array
	 */
	public static function get_by_customer(
		$customer_id,
		$limit = 50
	) {

		global $wpdb;

		$customer_id = absint(
			$customer_id
		);

		$limit = absint(
			$limit
		);

		if ( $customer_id < 1 ) {
			return array();
		}

		if ( $limit < 1 ) {
			$limit = 50;
		}

		if ( $limit > 100 ) {
			$limit = 100;
		}

		$table = self::table();

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
	 * Get tickets belonging to a conversation.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @param int $limit Maximum number of tickets.
	 * @return array
	 */
	public static function get_by_conversation(
		$conversation_id,
		$limit = 50
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
			$limit = 50;
		}

		if ( $limit > 100 ) {
			$limit = 100;
		}

		$table = self::table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *
				FROM {$table}
				WHERE conversation_id = %d
				ORDER BY updated_at DESC
				LIMIT %d",
				$conversation_id,
				$limit
			)
		);
	}

	/**
	 * Update a ticket.
	 *
	 * @param int    $ticket_id Ticket ID.
	 * @param string $subject Ticket subject.
	 * @param string $summary Ticket summary.
	 * @param string $status Ticket status.
	 * @param string $priority Ticket priority.
	 * @return bool|WP_Error
	 */
	public static function update(
		$ticket_id,
		$subject = '',
		$summary = '',
		$status = '',
		$priority = ''
	) {

		global $wpdb;

		$ticket_id = absint(
			$ticket_id
		);

		if ( $ticket_id < 1 ) {
			return new WP_Error(
				'invalid_ticket',
				'Invalid ticket.'
			);
		}

		$ticket = self::get_by_id(
			$ticket_id
		);

		if ( ! $ticket ) {
			return new WP_Error(
				'ticket_not_found',
				'Ticket not found.'
			);
		}

		$data    = array();
		$formats = array();

		$subject = sanitize_text_field(
			$subject
		);

		if ( '' !== $subject ) {
			$data['subject'] = $subject;
			$formats[]       = '%s';
		}

		$summary = sanitize_textarea_field(
			$summary
		);

		if ( '' !== trim( $summary ) ) {
			$data['summary'] = $summary;
			$formats[]        = '%s';
		}

		$status = sanitize_key(
			$status
		);

		if (
			'' !== $status &&
			in_array(
				$status,
				array(
					'open',
					'in_progress',
					'waiting_customer',
					'resolved',
					'closed',
				),
				true
			)
		) {
			$data['status'] = $status;
			$formats[]      = '%s';
		}

		$priority = sanitize_key(
			$priority
		);

		if (
			'' !== $priority &&
			in_array(
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
			$data['priority'] = $priority;
			$formats[]        = '%s';
		}

		if ( empty( $data ) ) {
			return true;
		}

		$data['updated_at'] = current_time(
			'mysql',
			true
		);

		$formats[] = '%s';

		$result = $wpdb->update(
			self::table(),
			$data,
			array(
				'id' => $ticket_id,
			),
			$formats,
			array(
				'%d',
			)
		);

		if ( false === $result ) {
			return new WP_Error(
				'ticket_update_failed',
				'The ticket could not be updated.'
			);
		}

		return true;
	}

	/**
	 * Update ticket status.
	 *
	 * @param int    $ticket_id Ticket ID.
	 * @param string $status New status.
	 * @return bool|WP_Error
	 */
	public static function update_status(
		$ticket_id,
		$status
	) {

		return self::update(
			$ticket_id,
			'',
			'',
			$status,
			''
		);
	}
}