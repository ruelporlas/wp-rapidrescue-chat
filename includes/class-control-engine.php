<?php
/**
 * Deterministic application control engine.
 *
 * @package WP_RapidRescue_Chat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Controls application workflow independently of the AI model.
 *
 * The AI interprets language.
 * This engine decides what application state permits next.
 */
class WP_RapidRescue_Chat_Control_Engine {

	const STATE_NORMAL                     = 'normal';
	const STATE_NEEDS_IDENTITY             = 'needs_customer_identity';
	const STATE_ESCALATION_PENDING         = 'escalation_pending';
	const STATE_AWAITING_CONFIRMATION      = 'escalation_awaiting_confirmation';
	const STATE_TICKET_CREATION_AUTHORIZED = 'ticket_creation_authorized';
	const STATE_NEW_TICKET_REQUESTED       = 'new_ticket_requested';
	const STATE_TICKET_CREATED             = 'ticket_created';
	const STATE_TICKET_ACTIVE              = 'ticket_active';
	const STATE_VERIFICATION_REQUIRED      = 'ticket_verification_required';
	const STATE_TICKET_UPDATE_REQUESTED    = 'ticket_update_requested';
	const STATE_TICKET_UPDATE_AUTHORIZED   = 'ticket_update_authorized';

	/**
	 * Evaluate application state.
	 *
	 * A direct request to create a ticket is itself authorization to
	 * create that ticket. There is no second confirmation step.
	 * Human-support requests are treated as escalation intent and are
	 * also considered authorized once the required information exists.
	 *
	 * @param array $context Runtime application context.
	 * @return array
	 */
	public static function evaluate( $context = array() ) {

		if ( ! is_array( $context ) ) {
			$context = array();
		}

		$customer_id = absint(
			isset( $context['customer_id'] )
				? $context['customer_id']
				: 0
		);

		$conversation_id = absint(
			isset( $context['conversation_id'] )
				? $context['conversation_id']
				: 0
		);

		$pending =
			isset( $context['pending_escalation'] ) &&
			is_array( $context['pending_escalation'] )
				? $context['pending_escalation']
				: array();

		$ticket_context =
			isset( $context['ticket_context'] ) &&
			is_array( $context['ticket_context'] )
				? $context['ticket_context']
				: array();

		$current_message =
			isset( $context['current_message'] )
				? sanitize_textarea_field(
					$context['current_message']
				)
				: '';

		$active_ticket_key =
			isset( $context['active_ticket_key'] )
				? strtoupper(
					sanitize_text_field(
						$context['active_ticket_key']
					)
				)
				: '';

		$explicit_new_ticket =
			self::is_new_ticket_request(
				$current_message
			);

		$human_escalation =
			self::is_human_escalation_request(
				$current_message
			);

		/*
		 * A direct request starts persistent ticket-creation state.
		 *
		 * This is intentionally done before evaluating the rest of the
		 * conversation so the next turn cannot forget what the customer
		 * asked for.
		 */
		if (
			$explicit_new_ticket ||
			$human_escalation
		) {

			$pending =
				self::start_or_update_authorized_escalation(
					$conversation_id,
					$pending,
					$current_message,
					$explicit_new_ticket
				);
		}

		/*
		 * Existing authorized escalation state survives across turns.
		 *
		 * This is the critical state-machine behavior that was missing.
		 */
		if ( ! empty( $pending ) ) {

			$authorized =
				! empty(
					$pending['confirmed']
				);

			$summary =
				isset( $pending['summary'] )
					? trim(
						sanitize_textarea_field(
							$pending['summary']
						)
					)
					: '';

			/*
			 * If the customer supplied the actual request after saying
			 * "create a new ticket for", preserve that detail as the
			 * pending summary.
			 *
			 * Never replace it with "yes", "confirm", etc.
			 */
			if (
				$authorized &&
				'' === $summary &&
				self::is_substantive_ticket_detail(
					$current_message
				)
			) {

				$pending['summary'] =
					sanitize_textarea_field(
						$current_message
					);

				self::save_pending(
					$conversation_id,
					$pending
				);

				$summary =
					trim(
						$pending['summary']
					);
			}

			/*
			 * Identity is always required before ticket creation.
			 */
			if ( $customer_id < 1 ) {

				return array(
					'state'            =>
						self::STATE_NEEDS_IDENTITY,

					'next_action'      =>
						'ask_customer_for_identity',

					'required_tool'    => '',
					'tool_choice'      => 'auto',

					'customer_id'      => 0,

					'conversation_id' =>
						$conversation_id,

					'pending'         => true,

					'confirmation'    =>
						$authorized,

					'new_ticket'      => true,
				);
			}

			/*
			 * Once the customer is identified and we have actual ticket
			 * details, creation is authorized immediately.
			 *
			 * There is NO confirmation step.
			 */
			if (
				$authorized &&
				'' !== $summary
			) {

				return array(
					'state'            =>
						self::STATE_TICKET_CREATION_AUTHORIZED,

					'next_action'      =>
						'create_ticket',

					'required_tool'    =>
						'create_ticket',

					'tool_choice'      =>
						'required',

					'customer_id'      =>
						$customer_id,

					'conversation_id' =>
						$conversation_id,

					'pending'         => true,

					'confirmation'    => true,

					'new_ticket'      => true,
				);
			}

			/*
			 * The user has requested escalation but has not yet supplied
			 * enough information to create the ticket.
			 */
			return array(
				'state' =>
					$authorized
						? self::STATE_NEW_TICKET_REQUESTED
						: self::STATE_ESCALATION_PENDING,

				'next_action' =>
					'collect_ticket_details',

				'required_tool' => '',
				'tool_choice'   => 'auto',

				'customer_id' =>
					$customer_id,

				'conversation_id' =>
					$conversation_id,

				'pending' =>
					true,

				'confirmation' =>
					$authorized,

				'new_ticket' =>
					true,
			);
		}

		/*
		 * ---------------------------------------------------------
		 * EXISTING TICKET UPDATE
		 * ---------------------------------------------------------
		 */
		$verified_ticket =
			self::get_verified_ticket(
				$ticket_context
			);

		if (
			$verified_ticket &&
			self::is_ticket_update_request(
				$current_message
			)
		) {

			$ticket_status =
				isset(
					$verified_ticket['status']
				)
					? sanitize_key(
						$verified_ticket['status']
					)
					: '';

			if (
				in_array(
					$ticket_status,
					array(
						'open',
						'in_progress',
						'waiting_customer',
					),
					true
				)
			) {

				return array(
					'state' =>
						self::STATE_TICKET_UPDATE_AUTHORIZED,

					'next_action' =>
						'update_ticket',

					'required_tool' =>
						'update_ticket',

					'tool_choice' =>
						'required',

					'customer_id' =>
						$customer_id,

					'conversation_id' =>
						$conversation_id,

					'pending' =>
						false,

					'confirmation' =>
						false,

					'new_ticket' =>
						false,

					'ticket_key' =>
						isset(
							$verified_ticket['ticket_key']
						)
							? sanitize_text_field(
								$verified_ticket['ticket_key']
							)
							: '',
				);
			}

			return array(
				'state' =>
					self::STATE_TICKET_UPDATE_REQUESTED,

				'next_action' =>
					'ticket_not_updateable',

				'required_tool' =>
					'',

				'tool_choice' =>
					'auto',

				'customer_id' =>
					$customer_id,

				'conversation_id' =>
					$conversation_id,

				'pending' =>
					false,

				'confirmation' =>
					false,

				'new_ticket' =>
					false,

				'ticket_key' =>
					isset(
						$verified_ticket['ticket_key']
					)
						? sanitize_text_field(
							$verified_ticket['ticket_key']
						)
						: '',
			);
		}

		/*
		 * ---------------------------------------------------------
		 * EXISTING ACTIVE TICKET
		 * ---------------------------------------------------------
		 *
		 * Only reached when no new-ticket/escalation state exists.
		 */
		if ( '' !== $active_ticket_key ) {

			return array(
				'state' =>
					self::STATE_TICKET_ACTIVE,

				'next_action' =>
					'use_active_ticket',

				'required_tool' =>
					'',

				'tool_choice' =>
					'auto',

				'customer_id' =>
					$customer_id,

				'conversation_id' =>
					$conversation_id,

				'pending' =>
					false,

				'confirmation' =>
					false,

				'new_ticket' =>
					false,

				'ticket_key' =>
					$active_ticket_key,
			);
		}

		/*
		 * ---------------------------------------------------------
		 * UNVERIFIED TICKET CONTEXT
		 * ---------------------------------------------------------
		 */
		foreach ( $ticket_context as $ticket ) {

			if (
				is_array( $ticket ) &&
				isset(
					$ticket['lookup_status']
				) &&
				'email_required' ===
					sanitize_key(
						$ticket['lookup_status']
					)
			) {

				return array(
					'state' =>
						self::STATE_VERIFICATION_REQUIRED,

					'next_action' =>
						'verify_ticket',

					'required_tool' =>
						'',

					'tool_choice' =>
						'auto',

					'customer_id' =>
						$customer_id,

					'conversation_id' =>
						$conversation_id,

					'pending' =>
						false,

					'confirmation' =>
						false,

					'new_ticket' =>
						false,
				);
			}
		}

		return array(
			'state' =>
				self::STATE_NORMAL,

			'next_action' =>
				'continue_conversation',

			'required_tool' =>
				'',

			'tool_choice' =>
				'auto',

			'customer_id' =>
				$customer_id,

			'conversation_id' =>
				$conversation_id,

			'pending' =>
				false,

			'confirmation' =>
				false,

			'new_ticket' =>
				false,
		);
	}

	/**
	 * Add deterministic control state to provider context.
	 *
	 * @param array $context Runtime context.
	 * @return array
	 */
	public static function prepare_context(
		$context = array()
	) {

		if ( ! is_array( $context ) ) {
			$context = array();
		}

		$decision =
			self::evaluate(
				$context
			);

		$context['control_engine'] =
			$decision;

		/*
		 * The authorization is generated by PHP, never by the model.
		 *
		 * Tool Security may safely use this PHP-established flag when
		 * create_ticket is required.
		 */
		if (
			! empty(
				$decision['confirmation']
			) &&
			! empty(
				$decision['new_ticket']
			)
		) {

			$context['explicit_ticket_confirmation'] =
				true;
		}

		/*
		 * Refresh persisted pending state after evaluate().
		 */
		if (
			! empty(
				$decision['pending']
			)
		) {

			$pending =
				WP_RapidRescue_Chat_Conversation::get_pending_sensitive_escalation(
					absint(
						isset(
							$context['conversation_id']
						)
							? $context['conversation_id']
							: 0
					)
				);

			$context['pending_escalation'] =
				$pending
					? $pending
					: array();
		}

		return $context;
	}

	/**
	 * Determine whether the AI must call a specific tool.
	 *
	 * @param array $context Runtime context.
	 * @return string
	 */
	public static function get_required_tool(
		$context = array()
	) {

		$decision =
			isset(
				$context['control_engine']
			) &&
			is_array(
				$context['control_engine']
			)
				? $context['control_engine']
				: self::evaluate(
					$context
				);

		return isset(
			$decision['required_tool']
		)
			? sanitize_key(
				$decision['required_tool']
			)
			: '';
	}

	/**
	 * Get a human-readable state description.
	 *
	 * @param array $decision Control decision.
	 * @return string
	 */
	public static function describe(
		$decision
	) {

		if ( ! is_array( $decision ) ) {
			return 'No control decision is available.';
		}

		$state =
			isset(
				$decision['state']
			)
				? sanitize_key(
					$decision['state']
				)
				: self::STATE_NORMAL;

		$next_action =
			isset(
				$decision['next_action']
			)
				? sanitize_key(
					$decision['next_action']
				)
				: 'continue_conversation';

		return sprintf(
			'CONTROL ENGINE STATE: %s. NEXT APPLICATION ACTION: %s.',
			$state,
			$next_action
		);
	}

	/**
	 * Persist or update an authorized escalation request.
	 *
	 * @param int    $conversation_id Conversation ID.
	 * @param array  $pending Existing pending state.
	 * @param string $message Current customer message.
	 * @param bool   $direct_new_ticket Whether user explicitly requested a new ticket.
	 * @return array
	 */
	private static function start_or_update_authorized_escalation(
		$conversation_id,
		$pending,
		$message,
		$direct_new_ticket
	) {

		$conversation_id =
			absint(
				$conversation_id
			);

		if ( $conversation_id < 1 ) {
			return is_array( $pending )
				? $pending
				: array();
		}

		$pending =
			is_array( $pending )
				? $pending
				: array();

		$subject =
			isset(
				$pending['subject']
			)
				? sanitize_text_field(
					$pending['subject']
				)
				: '';

		$summary =
			isset(
				$pending['summary']
			)
				? sanitize_textarea_field(
					$pending['summary']
				)
				: '';

		$reason =
			isset(
				$pending['reason']
			)
				? sanitize_textarea_field(
					$pending['reason']
				)
				: '';

		$priority =
			isset(
				$pending['priority']
			)
				? sanitize_key(
					$pending['priority']
				)
				: 'normal';

		if ( '' === $subject ) {
			$subject =
				'Customer support request';
		}

		if ( '' === $reason ) {

			$reason =
				$direct_new_ticket
					? 'Customer explicitly requested a new support ticket.'
					: 'Customer explicitly requested human support.';
		}

		if (
			'' === $summary &&
			self::is_substantive_ticket_detail(
				$message
			)
		) {

			$summary =
				sanitize_textarea_field(
					$message
				);
		}

		/*
		 * Direct ticket requests and human-help requests are both
		 * authorized. The customer does not need to confirm twice.
		 */
		$confirmed = true;

		$result =
			WP_RapidRescue_Chat_Conversation::set_pending_sensitive_escalation(
				$conversation_id,
				$subject,
				$summary,
				$priority,
				$reason,
				$confirmed
			);

		if ( is_wp_error( $result ) ) {
			return $pending;
		}

		$stored =
			WP_RapidRescue_Chat_Conversation::get_pending_sensitive_escalation(
				$conversation_id
			);

		return $stored && is_array( $stored )
			? $stored
			: $pending;
	}

	/**
	 * Save pending state.
	 *
	 * @param int   $conversation_id Conversation ID.
	 * @param array $pending Pending state.
	 * @return void
	 */
	private static function save_pending(
		$conversation_id,
		$pending
	) {

		if (
			absint(
				$conversation_id
			) < 1 ||
			! is_array(
				$pending
			)
		) {
			return;
		}

		WP_RapidRescue_Chat_Conversation::set_pending_sensitive_escalation(
			$conversation_id,

			isset(
				$pending['subject']
			)
				? $pending['subject']
				: 'Customer support request',

			isset(
				$pending['summary']
			)
				? $pending['summary']
				: '',

			isset(
				$pending['priority']
			)
				? $pending['priority']
				: 'normal',

			isset(
				$pending['reason']
			)
				? $pending['reason']
				: '',

			! empty(
				$pending['confirmed']
			)
		);
	}

	/**
	 * Determine whether a message contains useful ticket details.
	 *
	 * @param string $message Message.
	 * @return bool
	 */
	private static function is_substantive_ticket_detail(
		$message
	) {

		$message =
			strtolower(
				trim(
					sanitize_textarea_field(
						$message
					)
				)
			);

		if ( '' === $message ) {
			return false;
		}

		$non_details =
			array(
				'create a new ticket',
				'create new ticket',
				'open a new ticket',
				'open new ticket',
				'create a ticket',
				'open a ticket',
				'new ticket',
				'create it',
				'yes',
				'yes please',
				'confirm',
				'go ahead',
			);

		if (
			in_array(
				$message,
				$non_details,
				true
			)
		) {
			return false;
		}

		/*
		 * An unfinished request such as
		 * "create a new ticket for"
		 * is intent, not the actual ticket details.
		 */
		if (
			preg_match(
				'/\b(for|about|regarding|because|that|this|concerning|on)\s*$/i',
				$message
			)
		) {
			return false;
		}

		return strlen( $message ) >= 8;
	}

	/**
	 * Find a verified ticket in context.
	 *
	 * @param array $ticket_context Ticket context.
	 * @return array|null
	 */
	private static function get_verified_ticket(
		$ticket_context
	) {

		if ( ! is_array( $ticket_context ) ) {
			return null;
		}

		foreach ( $ticket_context as $ticket ) {

			if (
				is_array( $ticket ) &&
				isset(
					$ticket['lookup_status']
				) &&
				'verified' ===
					sanitize_key(
						$ticket['lookup_status']
					)
			) {
				return $ticket;
			}
		}

		return null;
	}

	/**
	 * Determine whether the customer explicitly wants a NEW ticket.
	 *
	 * @param string $message Customer message.
	 * @return bool
	 */
	private static function is_new_ticket_request(
		$message
	) {

		$message =
			strtolower(
				trim(
					sanitize_textarea_field(
						$message
					)
				)
			);

		if ( '' === $message ) {
			return false;
		}

		$patterns =
			array(

				'/\bcreate\s+(a\s+)?new\s+(support\s+)?ticket\b/i',
				'/\bopen\s+(a\s+)?new\s+(support\s+)?ticket\b/i',
				'/\bmake\s+(a\s+)?new\s+(support\s+)?ticket\b/i',
				'/\bstart\s+(a\s+)?new\s+(support\s+)?ticket\b/i',

				'/\bcreate\s+(a\s+)?separate\s+ticket\b/i',
				'/\bopen\s+(a\s+)?separate\s+ticket\b/i',
				'/\bcreate\s+another\s+ticket\b/i',
				'/\bopen\s+another\s+ticket\b/i',
				'/\bneed\s+another\s+ticket\b/i',
				'/\bneed\s+a\s+separate\s+ticket\b/i',

				'/\bcreate\s+one\b/i',
				'/\bopen\s+one\b/i',
				'/\bmake\s+one\b/i',

				'/\bnew\s+one\b/i',
				'/\banother\s+one\b/i',
				'/\bseparate\s+one\b/i',

				'/\bdo\s+not\s+use\s+(the|my)\s+existing\s+ticket\b/i',
				'/\bdon[\'’]?t\s+use\s+(the|my)\s+existing\s+ticket\b/i',
			);

		foreach ( $patterns as $pattern ) {

			if (
				preg_match(
					$pattern,
					$message
				)
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine whether the customer explicitly requests human support.
	 *
	 * @param string $message Customer message.
	 * @return bool
	 */
	private static function is_human_escalation_request(
		$message
	) {

		$message =
			strtolower(
				trim(
					sanitize_textarea_field(
						$message
					)
				)
			);

		if ( '' === $message ) {
			return false;
		}

		$patterns =
			array(

				'/\b(i|we)\s+want\s+(a\s+)?(person|human|agent|support\s+person)\b/i',

				'/\b(i|we)\s+need\s+(a\s+)?(person|human|agent|support\s+person)\b/i',

				'/\b(can|could|would)\s+(someone|a\s+person|an\s+agent)\s+(help|assist)\b/i',

				'/\b(talk|speak|chat)\s+(to|with)\s+(a\s+)?(person|human|agent)\b/i',

				'/\bhuman\s+(help|support|assistance)\b/i',

				'/\b(person|agent)\s+(help|support|assistance)\b/i',

				'/\bconnect\s+me\s+(with|to)\s+(a\s+)?(person|human|agent)\b/i',

				'/\bneed\s+someone\s+to\s+help\b/i',
			);

		foreach ( $patterns as $pattern ) {

			if (
				preg_match(
					$pattern,
					$message
				)
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine whether the customer is requesting an existing
	 * ticket update.
	 *
	 * @param string $message Customer message.
	 * @return bool
	 */
	private static function is_ticket_update_request(
		$message
	) {

		$message =
			strtolower(
				trim(
					sanitize_textarea_field(
						$message
					)
				)
			);

		if ( '' === $message ) {
			return false;
		}

		$patterns =
			array(

				'/\bupdate\b.*\bticket\b/i',
				'/\bticket\b.*\bupdate\b/i',

				'/\bfollow[\s-]?up\b/i',

				'/\bnot resolved\b/i',
				'/\bnot yet resolved\b/i',
				'/\bstill not resolved\b/i',
				'/\bstill unresolved\b/i',
				'/\bstill not fixed\b/i',
				'/\bnot fixed yet\b/i',

				'/\bissue is still\b/i',
				'/\bproblem is still\b/i',

				'/\badd (this|that|some) (to|on) (my|the) ticket\b/i',
				'/\badd (this|that|some) information\b/i',
				'/\badd (a )?note\b.*\bticket\b/i',

				'/\bplease (put|add|note)\b.*\bticket\b/i',
				'/\bplease update\b/i',

				'/\bupdate my case\b/i',
				'/\bupdate my support request\b/i',
			);

		foreach ( $patterns as $pattern ) {

			if (
				preg_match(
					$pattern,
					$message
				)
			) {
				return true;
			}
		}

		return false;
	}
}