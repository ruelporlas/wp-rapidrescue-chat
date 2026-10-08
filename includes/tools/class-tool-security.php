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
 * This class determines what information/actions are permitted.
 */
class WP_RapidRescue_Chat_Tool_Security {

	/**
	 * Information access levels.
	 */
	const ACCESS_PUBLIC          = 'public';
	const ACCESS_CUSTOMER_BASIC  = 'customer_basic';
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
	 * Determine whether the current conversation has verified
	 * access to a particular customer.
	 *
	 * @param int   $customer_id Customer ID.
	 * @param array $context Tool execution context.
	 * @return bool
	 */
	public static function can_access_customer(
		$customer_id,
		$context
	) {

		$customer_id = absint( $customer_id );

		if ( $customer_id < 1 ) {
			return false;
		}

		return $customer_id === self::get_customer_id(
			$context
		);
	}

	/**
	 * Determine whether ticket-private information has been verified.
	 *
	 * @param object $ticket  Ticket object.
	 * @param array  $context Tool execution context.
	 * @return bool
	 */
	public static function can_access_ticket(
		$ticket,
		$context
	) {

		if ( ! $ticket || ! is_object( $ticket ) ) {
			return false;
		}

		$customer_id = self::get_customer_id(
			$context
		);

		if ( $customer_id < 1 ) {
			return false;
		}

		/*
		 * A ticket must belong to the identified customer.
		 *
		 * We deliberately do not trust the AI to establish this
		 * relationship.
		 */
		if (
			absint( $ticket->customer_id ) !==
			$customer_id
		) {
			return false;
		}

		/*
		 * A matching customer ID is not enough by itself.
		 *
		 * The tool manager must establish verified ticket access.
		 */
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
	 * This is used as part of ticket verification.
	 *
	 * @param string $supplied_email Supplied email.
	 * @param object $customer       Customer record.
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

		$supplied_email = sanitize_email(
			$supplied_email
		);

		$customer_email = sanitize_email(
			isset( $customer->email )
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
	 * @param string $supplied_email Supplied email.
	 * @param object $ticket         Ticket record.
	 * @param object $customer       Optional customer record.
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

		$supplied_email = sanitize_email(
			$supplied_email
		);

		if ( '' === $supplied_email ) {
			return false;
		}

		$ticket_email = sanitize_email(
			isset( $ticket->customer_email )
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

		/*
		 * Legacy tickets may not have customer_email populated.
		 *
		 * In that case, fall back to the associated customer's
		 * email address.
		 */
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
	 * Mark a ticket as verified in the current execution context.
	 *
	 * @param string $ticket_key Ticket key.
	 * @param array  $context    Tool execution context.
	 * @return array
	 */
	public static function verify_ticket_context(
		$ticket_key,
		$context
	) {

		$ticket_key = strtoupper(
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
			$context['verified_ticket_keys'] = array();
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
	 * Determine whether a ticket may be created.
	 *
	 * Ticket creation requires:
	 *
	 * - identified customer
	 * - conversation
	 * - explicit application-level confirmation
	 *
	 * @param array $context Tool execution context.
	 * @return bool
	 */
	public static function can_create_ticket( $context ) {

		if ( ! self::has_customer_identity( $context ) ) {
			return false;
		}

		if (
			absint(
				isset( $context['conversation_id'] )
					? $context['conversation_id']
					: 0
			) < 1
		) {
			return false;
		}

		/*
		 * This flag must be established by PHP.
		 * The AI cannot grant itself permission by saying
		 * "confirmed": true.
		 */
		return ! empty(
			$context['explicit_ticket_confirmation']
		);
	}

	/**
	 * Remove private fields from a customer record.
	 *
	 * @param object $customer Customer record.
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
			'id' => absint(
				$customer->id
			),
			'name' => sanitize_text_field(
				$customer->name
			),
			'email' => sanitize_email(
				$customer->email
			),
			'site_url' => esc_url_raw(
				$customer->site_url
			),
		);
	}

	/**
	 * Convert a verified ticket into customer-safe data.
	 *
	 * Internal database fields are never returned directly.
	 *
	 * @param object $ticket Ticket record.
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
			'ticket_key' => sanitize_text_field(
				$ticket->ticket_key
			),
			'subject' => sanitize_text_field(
				$ticket->subject
			),
			'status' => sanitize_key(
				$ticket->status
			),
			'priority' => sanitize_key(
				$ticket->priority
			),
			'summary' => sanitize_textarea_field(
				$ticket->summary
			),
		);
	}
}