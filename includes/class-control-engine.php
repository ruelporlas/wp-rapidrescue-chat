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

	const STATE_NORMAL                    = 'normal';
	const STATE_NEEDS_IDENTITY            = 'needs_customer_identity';
	const STATE_ESCALATION_PENDING        = 'escalation_pending';
	const STATE_AWAITING_CONFIRMATION     = 'escalation_awaiting_confirmation';
	const STATE_TICKET_CREATION_AUTHORIZED = 'ticket_creation_authorized';
	const STATE_TICKET_CREATED            = 'ticket_created';
	const STATE_TICKET_ACTIVE             = 'ticket_active';
	const STATE_VERIFICATION_REQUIRED     = 'ticket_verification_required';

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

		$active_ticket_key =
			isset( $context['active_ticket_key'] )
				? strtoupper(
					sanitize_text_field(
						$context['active_ticket_key']
					)
				)
				: '';

		/*
		 * A verified active ticket takes precedence.
		 */
		if ( '' !== $active_ticket_key ) {

			return array(
				'state'             => self::STATE_TICKET_ACTIVE,
				'next_action'       => 'use_active_ticket',
				'required_tool'     => '',
				'tool_choice'       => 'auto',
				'customer_id'      => $customer_id,
				'conversation_id'  => $conversation_id,
				'pending'          => ! empty( $pending ),
				'confirmation'     => $explicit_confirmation,
			);
		}

		/*
		 * A PHP-confirmed ticket creation is the highest-priority
		 * workflow state.
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
		if ( ! empty( $pending ) && $customer_id < 1 ) {

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
		 * A pending escalation with identity is waiting for the
		 * customer's explicit approval.
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
}