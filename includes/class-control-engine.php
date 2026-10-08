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
	const STATE_TICKET_CREATED             = 'ticket_created';
	const STATE_TICKET_ACTIVE              = 'ticket_active';
	const STATE_VERIFICATION_REQUIRED      = 'ticket_verification_required';
	const STATE_TICKET_UPDATE_REQUESTED    = 'ticket_update_requested';
	const STATE_TICKET_UPDATE_AUTHORIZED   = 'ticket_update_authorized';

	/**
	 * Evaluate the current application state.
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

		$explicit_confirmation =
			! empty(
				$context['explicit_ticket_confirmation']
			);

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

		/*
		 * ---------------------------------------------------------
		 * EXISTING TICKET UPDATE
		 * ---------------------------------------------------------
		 *
		 * A verified ticket plus a customer request to follow up,
		 * update, add information, or report that the issue remains
		 * unresolved requires the update_ticket tool.
		 *
		 * PHP determines whether the ticket is verified.
		 * PHP also determines whether the request looks like an
		 * update request.
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
				isset( $verified_ticket['status'] )
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
					'state'            => self::STATE_TICKET_UPDATE_AUTHORIZED,
					'next_action'      => 'update_ticket',
					'required_tool'    => 'update_ticket',
					'tool_choice'      => 'required',
					'customer_id'      => $customer_id,
					'conversation_id' => $conversation_id,
					'pending'         => false,
					'confirmation'    => false,
					'ticket_key'      =>
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
			 * The ticket is verified but no longer active.
			 *
			 * Do not authorize an update because the Tool Manager
			 * deliberately does not silently reopen resolved/closed
			 * tickets.
			 */
			return array(
				'state'            => self::STATE_TICKET_UPDATE_REQUESTED,
				'next_action'      => 'ticket_not_updateable',
				'required_tool'    => '',
				'tool_choice'      => 'auto',
				'customer_id'      => $customer_id,
				'conversation_id' => $conversation_id,
				'pending'         => false,
				'confirmation'    => false,
				'ticket_key'      =>
					isset(
						$verified_ticket['ticket_key']
					)
						? sanitize_text_field(
							$verified_ticket['ticket_key']
						)
						: '',
			);
		}

		$active_ticket_key =
			isset( $context['active_ticket_key'] )
				? strtoupper(
					sanitize_text_field(
						$context['active_ticket_key']
					)
				)
				: '';

		/*
		 * A PHP-confirmed ticket creation is the highest-priority
		 * creation workflow.
		 */
		if (
			! empty( $pending ) &&
			$explicit_confirmation
		) {

			if ( $customer_id < 1 ) {

				return array(
					'state'            => self::STATE_NEEDS_IDENTITY,
					'next_action'      => 'ask_customer_for_identity',
					'required_tool'    => '',
					'tool_choice'      => 'auto',
					'customer_id'      => 0,
					'conversation_id' => $conversation_id,
					'pending'         => true,
					'confirmation'    => true,
				);
			}

			return array(
				'state'            => self::STATE_TICKET_CREATION_AUTHORIZED,
				'next_action'      => 'create_ticket',
				'required_tool'    => 'create_ticket',
				'tool_choice'      => 'required',
				'customer_id'      => $customer_id,
				'conversation_id' => $conversation_id,
				'pending'         => true,
				'confirmation'    => true,
			);
		}

		/*
		 * A pending escalation without identity cannot proceed.
		 */
		if (
			! empty( $pending ) &&
			$customer_id < 1
		) {

			return array(
				'state'            => self::STATE_NEEDS_IDENTITY,
				'next_action'      => 'ask_customer_for_identity',
				'required_tool'    => '',
				'tool_choice'      => 'auto',
				'customer_id'      => 0,
				'conversation_id' => $conversation_id,
				'pending'         => true,
				'confirmation'    => false,
			);
		}

		/*
		 * A pending escalation with identity is waiting for
		 * explicit customer approval.
		 */
		if ( ! empty( $pending ) ) {

			return array(
				'state'            => self::STATE_AWAITING_CONFIRMATION,
				'next_action'      => 'ask_customer_for_confirmation',
				'required_tool'    => '',
				'tool_choice'      => 'auto',
				'customer_id'      => $customer_id,
				'conversation_id' => $conversation_id,
				'pending'         => true,
				'confirmation'    => false,
			);
		}

		/*
		 * A verified active ticket remains available for normal
		 * ticket-related conversation.
		 */
		if ( '' !== $active_ticket_key ) {

			return array(
				'state'            => self::STATE_TICKET_ACTIVE,
				'next_action'      => 'use_active_ticket',
				'required_tool'    => '',
				'tool_choice'      => 'auto',
				'customer_id'      => $customer_id,
				'conversation_id' => $conversation_id,
				'pending'         => false,
				'confirmation'    => $explicit_confirmation,
			);
		}

		/*
		 * Ticket context supplied by PHP but not verified.
		 */
		foreach ( $ticket_context as $ticket ) {

			if (
				is_array( $ticket ) &&
				isset( $ticket['lookup_status'] ) &&
				'email_required' ===
					sanitize_key(
						$ticket['lookup_status']
					)
			) {

				return array(
					'state'            => self::STATE_VERIFICATION_REQUIRED,
					'next_action'      => 'verify_ticket',
					'required_tool'    => '',
					'tool_choice'      => 'auto',
					'customer_id'      => $customer_id,
					'conversation_id' => $conversation_id,
					'pending'         => false,
					'confirmation'    => false,
				);
			}
		}

		return array(
			'state'            => self::STATE_NORMAL,
			'next_action'      => 'continue_conversation',
			'required_tool'    => '',
			'tool_choice'      => 'auto',
			'customer_id'      => $customer_id,
			'conversation_id' => $conversation_id,
			'pending'         => false,
			'confirmation'    => false,
		);
	}

	/**
	 * Add control-engine state to provider context.
	 *
	 * @param array $context Runtime context.
	 * @return array
	 */
	public static function prepare_context( $context = array() ) {

		if ( ! is_array( $context ) ) {
			$context = array();
		}

		$decision =
			self::evaluate( $context );

		$context['control_engine'] =
			$decision;

		return $context;
	}

	/**
	 * Determine whether the AI must call a specific tool.
	 *
	 * @param array $context Runtime context.
	 * @return string
	 */
	public static function get_required_tool( $context = array() ) {

		$decision =
			isset( $context['control_engine'] ) &&
			is_array( $context['control_engine'] )
				? $context['control_engine']
				: self::evaluate( $context );

		return isset( $decision['required_tool'] )
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
	public static function describe( $decision ) {

		if ( ! is_array( $decision ) ) {
			return 'No control decision is available.';
		}

		$state =
			isset( $decision['state'] )
				? sanitize_key(
					$decision['state']
				)
				: self::STATE_NORMAL;

		$next_action =
			isset( $decision['next_action'] )
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
	 * Find a verified ticket in ticket context.
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

			if ( ! is_array( $ticket ) ) {
				continue;
			}

			if (
				isset( $ticket['lookup_status'] ) &&
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
	 * Determine whether the customer is requesting a ticket update.
	 *
	 * This is intentionally conservative. The AI still handles the
	 * natural-language interpretation, while PHP only authorizes the
	 * workflow when the message contains a clear update/follow-up
	 * signal.
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

		/*
		 * Explicit update/follow-up language.
		 */
		$patterns = array(
			'/\bupdate\b.*\bticket\b/i',
			'/\bticket\b.*\bupdate\b/i',
			'/\bfollow[\s-]?up\b/i',
			'/\bfollow up\b/i',
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

			if ( preg_match( $pattern, $message ) ) {
				return true;
			}
		}

		return false;
	}
} 