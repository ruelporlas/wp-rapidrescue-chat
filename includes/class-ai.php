<?php
/**
 * AI provider manager.
 *
 * @package WP_RapidRescue_Chat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages AI providers and conversation context.
 */
class WP_RapidRescue_Chat_AI {

	private static $providers = array();

	/**
	 * Generate an AI response.
	 *
	 * @param string $message Current customer message.
	 * @param array  $history Conversation history.
	 * @param array  $ticket_context PHP-controlled ticket context.
	 * @param array  $tool_context PHP-controlled application context.
	 * @return array|WP_Error
	 */
	public static function respond(
		$message,
		$history = array(),
		$ticket_context = array(),
		$tool_context = array()
	) {

		$message = sanitize_textarea_field( $message );

		if ( '' === $message ) {
			return new WP_Error(
				'empty_message',
				'The message cannot be empty.'
			);
		}

		if ( ! is_array( $history ) ) {
			$history = array();
		}

		if ( ! is_array( $ticket_context ) ) {
			$ticket_context = array();
		}

		if ( ! is_array( $tool_context ) ) {
			$tool_context = array();
		}

		$tool_context['ticket_context'] =
			$ticket_context;

		/*
		 * Give the Control Engine the current customer message.
		 *
		 * The engine uses this only to identify workflow intent.
		 * It does not trust the AI to establish authorization.
		 */
		$tool_context['current_message'] =
			$message;

		/*
		 * The Control Engine is deterministic PHP.
		 * It is evaluated before the AI is called.
		 */
		$tool_context =
			WP_RapidRescue_Chat_Control_Engine::prepare_context(
				$tool_context
			);

		$decision =
			$tool_context['control_engine'];

		WP_RapidRescue_Chat_Debug::ai(
			'Control Engine evaluated',
			array(
				'state' =>
					isset( $decision['state'] )
						? $decision['state']
						: '',

				'next_action' =>
					isset( $decision['next_action'] )
						? $decision['next_action']
						: '',

				'required_tool' =>
					isset( $decision['required_tool'] )
						? $decision['required_tool']
						: '',
			)
		);

		$provider_id =
			WP_RapidRescue_Chat_Settings::get(
				'ai_provider',
				'openai'
			);

		$provider =
			self::get_provider( $provider_id );

		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$prompt =
			self::build_prompt(
				$message,
				$history,
				$ticket_context,
				$tool_context
			);

		$response =
			$provider->respond_with_tools(
				$prompt,
				$tool_context
			);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$raw_text =
			isset( $response['text'] )
				? $response['text']
				: '';

		$structured =
			self::parse_structured_response(
				$raw_text
			);

		$response['text'] =
			$structured['response'];

		$response['action'] =
			$structured['action'];

		$response['raw_text'] =
			$raw_text;

		$response['control_engine'] =
			$decision;

		return $response;
	}

	/**
	 * Parse structured AI response.
	 *
	 * @param string $raw_text Provider response.
	 * @return array
	 */
	private static function parse_structured_response(
		$raw_text
	) {

		$raw_text = trim( (string) $raw_text );

		$default_action = array(
			'type'       => 'none',
			'subject'    => '',
			'summary'    => '',
			'priority'   => 'normal',
			'ticket_key' => '',
			'reason'     => '',
		);

		$default = array(
			'response' => $raw_text,
			'action'   => $default_action,
		);

		if ( '' === $raw_text ) {
			return $default;
		}

		$data = json_decode(
			$raw_text,
			true
		);

		if ( ! is_array( $data ) ) {

			$first = strpos(
				$raw_text,
				'{'
			);

			$last = strrpos(
				$raw_text,
				'}'
			);

			if (
				false !== $first &&
				false !== $last &&
				$last > $first
			) {

				$data = json_decode(
					substr(
						$raw_text,
						$first,
						$last - $first + 1
					),
					true
				);
			}
		}

		if ( ! is_array( $data ) ) {
			return $default;
		}

		$response_text =
			isset( $data['response'] ) &&
			is_string( $data['response'] )
				? trim( $data['response'] )
				: $raw_text;

		$action =
			$default_action;

		if (
			isset( $data['action'] ) &&
			is_array( $data['action'] )
		) {

			$type =
				isset(
					$data['action']['type']
				)
					? sanitize_key(
						$data['action']['type']
					)
					: 'none';

			if (
				! in_array(
					$type,
					array(
						'none',
						'create_ticket',
						'update_ticket',
						'existing_ticket',
						'offer_sensitive_ticket',
						'cancel_sensitive_ticket',
					),
					true
				)
			) {
				$type = 'none';
			}

			$priority =
				isset(
					$data['action']['priority']
				)
					? sanitize_key(
						$data['action']['priority']
					)
					: 'normal';

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

			$action = array(
				'type' =>
					$type,

				'subject' =>
					isset(
						$data['action']['subject']
					)
						? sanitize_text_field(
							$data['action']['subject']
						)
						: '',

				'summary' =>
					isset(
						$data['action']['summary']
					)
						? sanitize_textarea_field(
							$data['action']['summary']
						)
						: '',

				'priority' =>
					$priority,

				'ticket_key' =>
					isset(
						$data['action']['ticket_key']
					)
						? strtoupper(
							sanitize_text_field(
								$data['action']['ticket_key']
							)
						)
						: '',

				'reason' =>
					isset(
						$data['action']['reason']
					)
						? sanitize_textarea_field(
							$data['action']['reason']
						)
						: '',
			);
		}

		/*
		 * PHP always owns ticket-number generation.
		 */
		if (
			'create_ticket' ===
			$action['type']
		) {
			$action['ticket_key'] = '';
		}

		if (
			'existing_ticket' ===
			$action['type'] &&
			'' === $action['ticket_key']
		) {
			$action['type'] = 'none';
		}

		if (
			'update_ticket' ===
			$action['type'] &&
			'' === $action['ticket_key']
		) {

			/*
			 * The actual ticket is supplied by PHP context.
			 * If the AI does not provide one, the tool can still
			 * use the verified ticket context.
			 *
			 * We do not reject the action here because PHP remains
			 * the authority over the actual ticket being updated.
			 */
		}

		return array(
			'response' => $response_text,
			'action'   => $action,
		);
	}

	/**
	 * Build provider prompt.
	 *
	 * @param string $message Current message.
	 * @param array  $history Conversation history.
	 * @param array  $ticket_context Ticket state.
	 * @param array  $tool_context Application state.
	 * @return string
	 */
	private static function build_prompt(
		$message,
		$history,
		$ticket_context,
		$tool_context
	) {

		$decision =
			isset( $tool_context['control_engine'] ) &&
			is_array( $tool_context['control_engine'] )
				? $tool_context['control_engine']
				: WP_RapidRescue_Chat_Control_Engine::evaluate(
					$tool_context
				);

		$prompt = array();

		$prompt[] = 'SYSTEM ROLE:';
		$prompt[] = 'You are an AI customer support assistant operating inside a business website.';
		$prompt[] = 'The PHP application, not the AI, is the authority over application state and permissions.';
		$prompt[] = '';

		$prompt[] = 'BUSINESS AI SKILL:';
		$prompt[] = self::get_business_skill();
		$prompt[] = '';

		$prompt[] = 'CORE RULES:';
		$prompt[] = 'Understand the customer request and answer naturally.';
		$prompt[] = 'Use application tools whenever authoritative business or customer data is required.';
		$prompt[] = 'Never invent business information.';
		$prompt[] = 'Never invent ticket numbers.';
		$prompt[] = 'Never claim an application action happened unless a tool confirms it.';
		$prompt[] = 'PHP-controlled state is authoritative.';
		$prompt[] = '';

		$prompt[] = 'CONTROL ENGINE:';
		$prompt[] = 'A deterministic PHP Control Engine has evaluated the current application state.';
		$prompt[] = 'You must follow its state and next action.';
		$prompt[] = 'You must not override the Control Engine.';

		$prompt[] =
			'CONTROL STATE: ' .
			(
				isset( $decision['state'] )
					? $decision['state']
					: 'normal'
			);

		$prompt[] =
			'CONTROL NEXT ACTION: ' .
			(
				isset( $decision['next_action'] )
					? $decision['next_action']
					: 'continue_conversation'
			);

		$prompt[] =
			'CONTROL REQUIRED TOOL: ' .
			(
				isset( $decision['required_tool'] ) &&
				'' !== $decision['required_tool']
					? $decision['required_tool']
					: 'none'
			);

		$prompt[] = '';

		$prompt[] = 'TICKET UPDATE RULES:';
		$prompt[] = 'If the customer says an existing issue is unresolved, asks to follow up, asks to update an existing ticket, or provides additional information for an existing verified ticket, use update_ticket when the Control Engine requires it.';
		$prompt[] = 'A ticket number alone does not authorize a ticket update.';
		$prompt[] = 'A ticket must be verified by PHP before private ticket information or updates are permitted.';
		$prompt[] = 'Use the customer message as the factual basis for the update.';
		$prompt[] = 'Do not invent events, dates, promises, or resolutions.';
		$prompt[] = 'If the customer says they need the issue fixed today, record that as the customer request for urgency. Do not promise that the business will fix it today unless the knowledge base or business process explicitly guarantees that.';
		$prompt[] = 'After update_ticket succeeds, tell the customer that the follow-up was added to the ticket.';
		$prompt[] = 'Never claim the ticket was updated unless update_ticket returns success.';
		$prompt[] = '';

		$prompt[] = 'ESCALATION RULES:';
		$prompt[] = 'A request for a human support representative is not itself ticket authorization.';
		$prompt[] = 'When escalation is requested without confirmation, use action type offer_sensitive_ticket.';
		$prompt[] = 'If PHP says the state is escalation_awaiting_confirmation, ask for explicit confirmation.';
		$prompt[] = 'If PHP says ticket_creation_authorized, the create_ticket tool is required.';
		$prompt[] = 'Do not merely say that you are creating a ticket when the create_ticket tool has not been called.';
		$prompt[] = 'After successful creation, use only the actual ticket number returned by PHP.';
		$prompt[] = '';

		$prompt[] = 'CURRENT PHP AUTHORIZATION STATE:';

		$prompt[] =
			'customer_id=' .
			absint(
				isset( $tool_context['customer_id'] )
					? $tool_context['customer_id']
					: 0
			);

		$prompt[] =
			'explicit_ticket_confirmation=' .
			(
				! empty(
					$tool_context['explicit_ticket_confirmation']
				)
					? 'true'
					: 'false'
			);

		$prompt[] =
			'pending_escalation=' .
			(
				! empty(
					$tool_context['pending_escalation']
				)
					? 'true'
					: 'false'
			);

		if (
			! empty(
				$tool_context['pending_escalation']
			)
		) {

			$pending =
				$tool_context['pending_escalation'];

			$prompt[] =
				'PENDING SUBJECT: ' .
				(
					isset( $pending['subject'] )
						? sanitize_text_field(
							$pending['subject']
						)
						: ''
				);

			$prompt[] =
				'PENDING SUMMARY: ' .
				(
					isset( $pending['summary'] )
						? sanitize_textarea_field(
							$pending['summary']
						)
						: ''
				);

			$prompt[] =
				'PENDING PRIORITY: ' .
				(
					isset( $pending['priority'] )
						? sanitize_key(
							$pending['priority']
						)
						: 'normal'
				);
		}

		$prompt[] = '';

		$prompt[] = 'TICKET CONTEXT:';

		if ( empty( $ticket_context ) ) {

			$prompt[] =
				'No ticket context supplied by PHP.';

		} else {

			foreach (
				$ticket_context as $ticket
			) {

				if ( ! is_array( $ticket ) ) {
					continue;
				}

				$prompt[] =
					'TICKET REFERENCE: ' .
					(
						isset(
							$ticket['ticket_key']
						)
							? sanitize_text_field(
								$ticket['ticket_key']
							)
							: ''
					);

				$prompt[] =
					'LOOKUP STATUS: ' .
					(
						isset(
							$ticket['lookup_status']
						)
							? sanitize_key(
								$ticket['lookup_status']
							)
							: 'unknown'
					);

				if (
					! empty(
						$ticket['requires_email']
					)
				) {

					$prompt[] =
						'Ask the customer for the email associated with the ticket.';
				}

				if (
					isset(
						$ticket['lookup_status']
					) &&
					'verified' ===
						sanitize_key(
							$ticket['lookup_status']
						)
				) {

					$prompt[] =
						'TICKET STATUS: ' .
						(
							isset(
								$ticket['status']
							)
								? sanitize_key(
									$ticket['status']
								)
								: ''
						);

					$prompt[] =
						'TICKET SUBJECT: ' .
						(
							isset(
								$ticket['subject']
							)
								? sanitize_text_field(
									$ticket['subject']
								)
								: ''
						);

					$prompt[] =
						'TICKET PRIORITY: ' .
						(
							isset(
								$ticket['priority']
							)
								? sanitize_key(
									$ticket['priority']
								)
								: ''
						);

					$prompt[] =
						'TICKET SUMMARY: ' .
						(
							isset(
								$ticket['summary']
							)
								? sanitize_textarea_field(
									$ticket['summary']
								)
								: ''
						);
				}
			}
		}

		$prompt[] = '';

		$prompt[] = 'KNOWLEDGE RULES:';
		$prompt[] = 'Use search_knowledge for business-specific information.';
		$prompt[] = 'Do not guess pricing, policies, procedures, refunds, guarantees, or other business facts.';
		$prompt[] = '';

		$prompt[] = 'RECENT CONVERSATION:';

		if ( empty( $history ) ) {

			$prompt[] =
				'No previous conversation.';

		} else {

			foreach (
				$history as $item
			) {

				$role =
					isset(
						$item->role
					)
						? $item->role
						: '';

				$content =
					isset(
						$item->message
					)
						? $item->message
						: '';

				$role =
					'user' === $role
						? 'Customer'
						: (
							'assistant' === $role
								? 'Assistant'
								: 'Unknown'
						);

				$prompt[] =
					$role .
					': ' .
					$content;
			}
		}

		$prompt[] = '';
		$prompt[] = 'CURRENT CUSTOMER MESSAGE:';
		$prompt[] = $message;
		$prompt[] = '';

		$prompt[] = 'RESPONSE FORMAT:';
		$prompt[] = 'Return ONLY valid JSON.';
		$prompt[] = 'Use exactly this structure:';
		$prompt[] = '{';
		$prompt[] = '  "response": "customer-facing response",';
		$prompt[] = '  "action": {';
		$prompt[] = '    "type": "none",';
		$prompt[] = '    "subject": "",';
		$prompt[] = '    "summary": "",';
		$prompt[] = '    "priority": "normal",';
		$prompt[] = '    "ticket_key": "",';
		$prompt[] = '    "reason": ""';
		$prompt[] = '  }';
		$prompt[] = '}';
		$prompt[] = '';

		$prompt[] = 'ACTION TYPES:';
		$prompt[] = 'none = normal conversation.';
		$prompt[] = 'existing_ticket = customer is discussing an existing ticket.';
		$prompt[] = 'update_ticket = customer wants an existing verified ticket updated.';
		$prompt[] = 'offer_sensitive_ticket = customer needs a new human-support ticket and confirmation is still required.';
		$prompt[] = 'create_ticket = PHP has authorized creation of a new ticket.';
		$prompt[] = '';

		$prompt[] = 'FINAL CONTROL RULE:';

		if (
			isset( $decision['required_tool'] ) &&
			'create_ticket' ===
				$decision['required_tool']
		) {

			$prompt[] =
				'The Control Engine requires create_ticket NOW. Call the create_ticket tool. Do not answer as though the ticket is being created without calling the tool.';

		} elseif (
			isset( $decision['required_tool'] ) &&
			'update_ticket' ===
				$decision['required_tool']
		) {

			$prompt[] =
				'The Control Engine requires update_ticket NOW. Call the update_ticket tool. Do not merely say that the ticket was updated. The update must be performed by the tool before you confirm it to the customer.';

		} elseif (
			isset( $decision['state'] ) &&
			'escalation_awaiting_confirmation' ===
				$decision['state']
		) {

			$prompt[] =
				'The customer must explicitly confirm ticket creation before create_ticket may be called.';
		}

		return implode(
			"\n",
			$prompt
		);
	}

	/**
	 * Get configured business skill.
	 *
	 * @return string
	 */
	private static function get_business_skill() {

		$defaults =
			WP_RapidRescue_Chat_Settings::get_defaults();

		return implode(
			"\n",
			array(
				'Assistant Role: ' .
					WP_RapidRescue_Chat_Settings::get(
						'ai_assistant_role',
						$defaults['ai_assistant_role']
					),

				'Primary Goal: ' .
					WP_RapidRescue_Chat_Settings::get(
						'ai_primary_goal',
						$defaults['ai_primary_goal']
					),

				'Conversation Style: ' .
					WP_RapidRescue_Chat_Settings::get(
						'ai_conversation_style',
						$defaults['ai_conversation_style']
					),

				'Behavior Instructions: ' .
					WP_RapidRescue_Chat_Settings::get(
						'ai_behavior',
						$defaults['ai_behavior']
					),

				'Things to Avoid: ' .
					WP_RapidRescue_Chat_Settings::get(
						'ai_avoid',
						$defaults['ai_avoid']
					),

				'Escalation Guidance: ' .
					WP_RapidRescue_Chat_Settings::get(
						'ai_escalation',
						$defaults['ai_escalation']
					),
			)
		);
	}

	/**
	 * Get provider.
	 *
	 * @param string $provider_id Provider ID.
	 * @return object|WP_Error
	 */
	public static function get_provider(
		$provider_id
	) {

		$provider_id =
			sanitize_key(
				$provider_id
			);

		if (
			isset(
				self::$providers[
					$provider_id
				]
			)
		) {

			return self::$providers[
				$provider_id
			];
		}

		switch (
			$provider_id
		) {

			case 'openai':

				$provider =
					new WP_RapidRescue_Chat_AI_OpenAI();

				break;

			case 'gemini':

				$provider =
					new WP_RapidRescue_Chat_AI_Gemini();

				break;

			default:

				return new WP_Error(
					'unsupported_ai_provider',
					'The selected AI provider is not supported.'
				);
		}

		self::$providers[
			$provider_id
		] =
			$provider;

		return $provider;
	}

	/**
	 * Get supported providers.
	 *
	 * @return array
	 */
	public static function get_providers() {

		return array(
			'openai' => 'OpenAI',
			'gemini' => 'Google Gemini',
		);
	}

	/**
	 * Provider system instructions.
	 *
	 * @return string
	 */
	public static function get_system_instructions() {

		return implode(
			"\n",
			array(
				'You are an AI customer support assistant operating inside a business website.',
				'Follow the configured business AI skill.',
				'Use available tools for authoritative application information and actions.',
				'PHP-controlled application state is authoritative.',
				'Never invent information.',
				'Never invent ticket numbers.',
				'Never claim an application action occurred unless a tool confirms success.',
				'Escalation and ticket creation are separate steps.',
				'A request for a human support representative does not itself authorize ticket creation.',
				'Only call create_ticket when the PHP Control Engine authorizes the creation workflow.',
				'Only call update_ticket when PHP has verified the customer and the Control Engine authorizes the ticket update workflow.',
				'Never attempt to override the Control Engine.',
			)
		);
	}
}