<?php
/**
 * AI tool security and authorization.
 *
 * @package WP_RapidRescue_Chat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles authorization for AI tools.
 *
 * The AI is never treated as the security boundary.
 */
class WP_RapidRescue_Chat_Tool_Security {

	const ACCESS_PUBLIC           = 'public';
	const ACCESS_CUSTOMER_BASIC   = 'customer_basic';
	const ACCESS_CUSTOMER_PRIVATE = 'customer_private';
	const ACCESS_TICKET_PRIVATE   = 'ticket_private';
	const ACCESS_INTERNAL         = 'internal';

	/**
	 * Determine whether a customer context is available.
	 *
	 * @param array $context Tool execution context.
	 * @return bool
	 */
	public static function has_customer_identity( $context ) {

		if ( ! is_array( $context ) ) {
			return false;
		}

		return absint(
			isset( $context['customer_id'] )
				? $context['customer_id']
				: 0
		) > 0;
	}

	/**
	 * Get the current customer ID.
	 *
	 * @param array $context Tool execution context.
	 * @return int
	 */
	public static function get_customer_id( $context ) {

		if ( ! is_array( $context ) ) {
			return 0;
		}

		return absint(
			isset( $context['customer_id'] )
				? $context['customer_id']
				: 0
		);
	}

	/**
	 * Determine whether a customer may be accessed.
	 *
	 * @param int   $customer_id Customer ID.
	 * @param array $context Tool context.
	 * @return bool
	 */
	public static function can_access_customer(
		$customer_id,
		$context
	) {

		$customer_id =
			absint(
				$customer_id
			);

		if ( $customer_id < 1 ) {
			return false;
		}

		return $customer_id ===
			self::get_customer_id(
				$context
			);
	}

	/**
	 * Determine whether ticket-private information is accessible.
	 *
	 * @param object $ticket Ticket.
	 * @param array  $context Tool context.
	 * @return bool
	 */
	public static function can_access_ticket(
		$ticket,
		$context
	) {

		if (
			! $ticket ||
			! is_object( $ticket )
		) {
			return false;
		}

		$customer_id =
			self::get_customer_id(
				$context
			);

		if ( $customer_id < 1 ) {
			return false;
		}

		if (
			absint(
				$ticket->customer_id
			) !== $customer_id
		) {
			return false;
		}

		return ! empty(
			$context['verified_ticket_keys']
		) &&
		is_array(
			$context['verified_ticket_keys']
		) &&
		in_array(
			strtoupper(
				sanitize_text_field(
					$ticket->ticket_key
				)
			),
			$context['verified_ticket_keys'],
			true
		);
	}

	/**
	 * Determine whether an email belongs to a customer.
	 *
	 * @param string $supplied_email Email.
	 * @param object $customer Customer.
	 * @return bool
	 */
	public static function emails_match_customer(
		$supplied_email,
		$customer
	) {

		if (
			! $customer ||
			! is_object( $customer )
		) {
			return false;
		}

		$supplied_email =
			sanitize_email(
				$supplied_email
			);

		$customer_email =
			sanitize_email(
				isset(
					$customer->email
				)
					? $customer->email
					: ''
			);

		if (
			'' === $supplied_email ||
			'' === $customer_email
		) {
			return false;
		}

		return strtolower(
			$supplied_email
		) === strtolower(
			$customer_email
		);
	}

	/**
	 * Determine whether an email belongs to a ticket.
	 *
	 * @param string $supplied_email Email.
	 * @param object $ticket Ticket.
	 * @param object $customer Customer.
	 * @return bool
	 */
	public static function emails_match_ticket(
		$supplied_email,
		$ticket,
		$customer = null
	) {

		if (
			! $ticket ||
			! is_object( $ticket )
		) {
			return false;
		}

		$supplied_email =
			sanitize_email(
				$supplied_email
			);

		if ( '' === $supplied_email ) {
			return false;
		}

		$ticket_email =
			sanitize_email(
				isset(
					$ticket->customer_email
				)
					? $ticket->customer_email
					: ''
			);

		if ( '' !== $ticket_email ) {

			return strtolower(
				$supplied_email
			) === strtolower(
				$ticket_email
			);
		}

		if (
			$customer &&
			self::emails_match_customer(
				$supplied_email,
				$customer
			)
		) {
			return true;
		}

		return false;
	}

	/**
	 * Mark a ticket as verified in the current context.
	 *
	 * @param string $ticket_key Ticket key.
	 * @param array  $context Context.
	 * @return array
	 */
	public static function verify_ticket_context(
		$ticket_key,
		$context
	) {

		$ticket_key =
			strtoupper(
				sanitize_text_field(
					$ticket_key
				)
			);

		if ( '' === $ticket_key ) {
			return $context;
		}

		if (
			! isset(
				$context['verified_ticket_keys']
			) ||
			! is_array(
				$context['verified_ticket_keys']
			)
		) {
			$context['verified_ticket_keys'] =
				array();
		}

		if (
			! in_array(
				$ticket_key,
				$context['verified_ticket_keys'],
				true
			)
		) {

			$context['verified_ticket_keys'][] =
				$ticket_key;
		}

		return $context;
	}

	/**
	 * Determine whether a new ticket may be created.
	 *
	 * PHP authorization requires:
	 *
	 * - identified customer
	 * - valid conversation
	 * - control engine explicitly authorizing creation
	 * - pending escalation with meaningful details
	 *
	 * No second customer confirmation is required.
	 *
	 * @param array $context Tool execution context.
	 * @return bool
	 */
	public static function can_create_ticket(
		$context
	) {

		if (
			! self::has_customer_identity(
				$context
			)
		) {
			return false;
		}

		if (
			absint(
				isset(
					$context['conversation_id']
				)
					? $context['conversation_id']
					: 0
			) < 1
		) {
			return false;
		}

		/*
		 * This flag is generated by the PHP Control Engine.
		 *
		 * It does NOT mean that the customer typed "yes".
		 * It means PHP has determined that the customer explicitly
		 * requested a ticket and supplied enough information for
		 * creation.
		 */
		if (
			empty(
				$context['explicit_ticket_confirmation']
			)
		) {
			return false;
		}

		/*
		 * Verify the persisted pending state as a second PHP-side
		 * safety check.
		 */
		$pending =
			WP_RapidRescue_Chat_Conversation::get_pending_sensitive_escalation(
				absint(
					$context['conversation_id']
				)
			);

		if (
			! is_array( $pending ) ||
			empty( $pending )
		) {
			return false;
		}

		if (
			empty(
				$pending['confirmed']
			)
		) {
			return false;
		}

		if (
			empty(
				$pending['summary']
			) ||
			'' === trim(
				sanitize_textarea_field(
					$pending['summary']
				)
			)
		) {
			return false;
		}

		return true;
	}

	/**
	 * Remove private fields from a customer.
	 *
	 * @param object $customer Customer.
	 * @return array
	 */
	public static function customer_to_safe_array(
		$customer
	) {

		if (
			! $customer ||
			! is_object( $customer )
		) {
			return array();
		}

		return array(
			'id' =>
				absint(
					$customer->id
				),

			'name' =>
				sanitize_text_field(
					$customer->name
				),

			'email' =>
				sanitize_email(
					$customer->email
				),

			'site_url' =>
				esc_url_raw(
					$customer->site_url
				),
		);
	}

	/**
	 * Convert a verified ticket into safe data.
	 *
	 * @param object $ticket Ticket.
	 * @return array
	 */
	public static function ticket_to_safe_array(
		$ticket
	) {

		if (
			! $ticket ||
			! is_object( $ticket )
		) {
			return array();
		}

		return array(
			'ticket_key' =>
				sanitize_text_field(
					$ticket->ticket_key
				),

			'subject' =>
				sanitize_text_field(
					$ticket->subject
				),

			'status' =>
				sanitize_key(
					$ticket->status
				),

			'priority' =>
				sanitize_key(
					$ticket->priority
				),

			'summary' =>
				sanitize_textarea_field(
					$ticket->summary
				),
		);
	}
}