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

	/**
	 * Registered providers.
	 *
	 * @var array
	 */
	private static $providers = array();

	/**
	 * Generate an AI response.
	 *
	 * @param string $message         Current customer message.
	 * @param array  $history         Conversation history.
	 * @param array  $ticket_context  PHP-controlled ticket context.
	 * @param array  $tool_context    PHP-controlled application context.
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

		/*
		 * PHP application context.
		 */
		$tool_context['ticket_context'] =
			$ticket_context;

		$tool_context['current_message'] =
			$message;

		/*
		 * Evaluate deterministic application state.
		 *
		 * This does not tell the AI what the customer means.
		 * It tells the AI what PHP currently knows and what
		 * application action, if any, is authorized.
		 */
		$tool_context =
			WP_RapidRescue_Chat_Control_Engine::prepare_context(
				$tool_context
			);

		$decision =
			isset( $tool_context['control_engine'] ) &&
			is_array( $tool_context['control_engine'] )
				? $tool_context['control_engine']
				: array();

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
			self::get_provider(
				$provider_id
			);

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

		/*
		 * PHP remains authoritative over what actually happened.
		 */
		$response =
			self::reconcile_tool_results(
				$response,
				$tool_context
			);

		$response['control_engine'] =
			$decision;

		return $response;
	}

	/**
	 * Reconcile AI output with actual PHP tool execution.
	 *
	 * The AI's declared action is never considered proof that
	 * an operation happened.
	 *
	 * @param array $response     Provider response.
	 * @param array $tool_context PHP application context.
	 * @return array
	 */
	private static function reconcile_tool_results(
		$response,
		$tool_context
	) {

		$tool_results =
			isset( $response['tool_results'] ) &&
			is_array( $response['tool_results'] )
				? $response['tool_results']
				: array();

		/*
		 * ---------------------------------------------------------
		 * CREATE TICKET
		 * ---------------------------------------------------------
		 */
		$create_result =
			self::get_latest_tool_result(
				$tool_results,
				'create_ticket'
			);

		if ( $create_result ) {

			/*
			 * THIS IS THE CRITICAL CHECK.
			 *
			 * success=true is NOT sufficient.
			 *
			 * The ticket must explicitly report:
			 *
			 * created=true
			 *
			 * Otherwise an existing ticket may be mistaken for
			 * a newly-created ticket.
			 */
			$created =
				! empty(
					$create_result['created']
				);

			$already_exists =
				! empty(
					$create_result['already_exists']
				);

			$ticket_id =
				isset( $create_result['ticket_id'] )
					? absint(
						$create_result['ticket_id']
					)
					: 0;

			$ticket_key =
				isset( $create_result['ticket_key'] )
					? sanitize_text_field(
						$create_result['ticket_key']
					)
					: '';

			/*
			 * A genuine creation requires all three:
			 *
			 * created=true
			 * valid database ID
			 * actual ticket key
			 */
			if (
				$created &&
				! $already_exists &&
				$ticket_id > 0 &&
				'' !== $ticket_key
			) {

				$response['text'] =
					sprintf(
						'Your new support ticket has been created successfully. Your ticket number is %s.',
						$ticket_key
					);

				/*
				 * Keep the real PHP result available to the
				 * calling layer.
				 */
				$response['ticket_created'] =
					true;

				$response['ticket_id'] =
					$ticket_id;

				$response['ticket_key'] =
					$ticket_key;

				return $response;
			}

			/*
			 * -----------------------------------------------------
			 * EXISTING TICKET WAS RETURNED
			 * -----------------------------------------------------
			 *
			 * This is NOT ticket creation.
			 */
			if ( $already_exists ) {

				/*
				 * Do not expose the existing ticket as though it
				 * were the new request.
				 *
				 * The application can decide how to continue, but
				 * the AI must never claim creation occurred.
				 */
				$response['ticket_created'] =
					false;

				$response['ticket_creation_blocked'] =
					true;

				$response['text'] =
					'I could not create a new ticket because the ticket creation request was resolved to an existing ticket. No new ticket was created.';

				return $response;
			}

			/*
			 * -----------------------------------------------------
			 * CREATION FAILED / UNCONFIRMED
			 * -----------------------------------------------------
			 */
			$response['ticket_created'] =
				false;

			$response['text'] =
				'I\'m sorry, but I could not confirm creation of a new support ticket. No new ticket was created.';

			return $response;
		}

		/*
		 * ---------------------------------------------------------
		 * UPDATE TICKET
		 * ---------------------------------------------------------
		 */
		$update_result =
			self::get_latest_tool_result(
				$tool_results,
				'update_ticket'
			);

		if ( $update_result ) {

			$updated =
				! empty(
					$update_result['updated']
				);

			$success =
				! empty(
					$update_result['success']
				);

			if (
				$success &&
				$updated
			) {

				$response['text'] =
					'Your follow-up has been added to the support ticket.';

				$response['ticket_updated'] =
					true;

				return $response;
			}

			$response['ticket_updated'] =
				false;

			$response['text'] =
				'I\'m sorry, but I could not update the support ticket. No ticket changes were made.';

			return $response;
		}

		return $response;
	}

	/**
	 * Get the latest result for a tool.
	 *
	 * @param array  $tool_results Tool results.
	 * @param string $tool_name    Tool name.
	 * @return array|null
	 */
	private static function get_latest_tool_result(
		$tool_results,
		$tool_name
	) {

		$found = null;

		foreach (
			$tool_results as $tool_result
		) {

			if ( ! is_array( $tool_result ) ) {
				continue;
			}

			$current_tool =
				isset( $tool_result['tool'] )
					? sanitize_key(
						$tool_result['tool']
					)
					: '';

			if (
				$tool_name !== $current_tool
			) {
				continue;
			}

			$result =
				isset(
					$tool_result['result']
				) &&
				is_array(
					$tool_result['result']
				)
					? $tool_result['result']
					: array();

			$found = $result;
		}

		return $found;
	}

	/**
	 * Parse structured AI response.
	 *
	 * @param string $raw_text Raw provider response.
	 * @return array
	 */
	private static function parse_structured_response(
		$raw_text
	) {

		$raw_text =
			trim(
				(string) $raw_text
			);

		$default_action =
			array(
				'type'       => 'none',
				'subject'    => '',
				'summary'    => '',
				'priority'   => 'normal',
				'ticket_key' => '',
				'reason'     => '',
			);

		$default =
			array(
				'response' =>
					$raw_text,

				'action' =>
					$default_action,
			);

		if ( '' === $raw_text ) {
			return $default;
		}

		$data =
			json_decode(
				$raw_text,
				true
			);

		/*
		 * Some providers may return surrounding text.
		 * Attempt to extract the JSON object.
		 */
		if ( ! is_array( $data ) ) {

			$first =
				strpos(
					$raw_text,
					'{'
				);

			$last =
				strrpos(
					$raw_text,
					'}'
				);

			if (
				false !== $first &&
				false !== $last &&
				$last > $first
			) {

				$data =
					json_decode(
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
				? trim(
					$data['response']
				)
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

			$action =
				array(
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
		 * PHP owns ticket numbers.
		 */
		if (
			'create_ticket' ===
			$action['type']
		) {
			$action['ticket_key'] = '';
		}

		return array(
			'response' =>
				$response_text,

			'action' =>
				$action,
		);
	}

	/**
	 * Build the AI prompt.
	 *
	 * @param string $message         Current customer message.
	 * @param array  $history         Conversation history.
	 * @param array  $ticket_context  Ticket context.
	 * @param array  $tool_context    Application context.
	 * @return string
	 */
	private static function build_prompt(
		$message,
		$history,
		$ticket_context,
		$tool_context
	) {

		$decision =
			isset(
				$tool_context['control_engine']
			) &&
			is_array(
				$tool_context['control_engine']
			)
				? $tool_context['control_engine']
				: array();

		$prompt = array();

		/*
		 * ---------------------------------------------------------
		 * ROLE
		 * ---------------------------------------------------------
		 */
		$prompt[] =
			'SYSTEM ROLE:';

		$prompt[] =
			'You are an AI customer support assistant operating inside a business website.';

		$prompt[] =
			'Your job is to understand what the customer is actually asking and respond naturally using the available conversation, business knowledge, customer data, ticket data, and tools.';

		$prompt[] =
			'';

		/*
		 * ---------------------------------------------------------
		 * BUSINESS SKILL
		 * ---------------------------------------------------------
		 */
		$prompt[] =
			'BUSINESS AI SKILL:';

		$prompt[] =
			self::get_business_skill();

		$prompt[] =
			'';

		/*
		 * ---------------------------------------------------------
		 * REASONING
		 * ---------------------------------------------------------
		 */
		$prompt[] =
			'REASONING:';

		$prompt[] =
			'Interpret the customer message in the context of the conversation.';

		$prompt[] =
			'Use the information already gathered rather than repeatedly asking the customer for information that PHP has already established.';

		$prompt[] =
			'Consider whether the customer is discussing an existing request, following up on an existing ticket, or introducing a separate request.';

		$prompt[] =
			'Do not decide based solely on keywords. Consider the meaning of the complete customer message and the surrounding conversation.';

		$prompt[] =
			'A request for a human, agent, developer, designer, or representative does not automatically mean that a new ticket is required.';

		$prompt[] =
			'Likewise, the existence of an active ticket does not automatically mean that every future request belongs to that ticket.';

		$prompt[] =
			'Use your reasoning to determine the most appropriate action from the information available.';

		$prompt[] =
			'';

		/*
		 * ---------------------------------------------------------
		 * PHP AUTHORITY
		 * ---------------------------------------------------------
		 */
		$prompt[] =
			'PHP AUTHORITY:';

		$prompt[] =
			'PHP is authoritative for identity, verification, authorization, ticket ownership, ticket state, database records, and actual application actions.';

		$prompt[] =
			'Your interpretation does not grant permission to perform an operation.';

		$prompt[] =
			'Never bypass PHP authorization or verification.';

		$prompt[] =
			'Never invent customer data, ticket data, business information, or ticket numbers.';

		$prompt[] =
			'Never claim an application operation occurred unless the corresponding PHP tool actually succeeded.';

		$prompt[] =
			'';

		/*
		 * ---------------------------------------------------------
		 * CONTROL ENGINE
		 * ---------------------------------------------------------
		 */
		$prompt[] =
			'CONTROL ENGINE:';

		$prompt[] =
			'The PHP Control Engine provides authoritative application state and may require a specific tool.';

		$prompt[] =
			'Use the Control Engine information together with your reasoning.';

		$prompt[] =
			'Do not override a PHP-required operation.';

		$prompt[] =
			'';

		$prompt[] =
			'CONTROL STATE: ' .
			(
				isset(
					$decision['state']
				)
					? sanitize_key(
						$decision['state']
					)
					: 'normal'
			);

		$prompt[] =
			'CONTROL NEXT ACTION: ' .
			(
				isset(
					$decision['next_action']
				)
					? sanitize_key(
						$decision['next_action']
					)
					: 'continue_conversation'
			);

		$prompt[] =
			'CONTROL REQUIRED TOOL: ' .
			(
				isset(
					$decision['required_tool']
				) &&
				'' !== $decision['required_tool']
					? sanitize_key(
						$decision['required_tool']
					)
					: 'none'
			);

		$prompt[] =
			'';

		/*
		 * ---------------------------------------------------------
		 * TICKET SAFETY
		 * ---------------------------------------------------------
		 */
		$prompt[] =
			'TICKET SAFETY:';

		$prompt[] =
			'A ticket number alone does not authorize private ticket access or modification.';

		$prompt[] =
			'PHP must establish ticket verification before private ticket information or updates are permitted.';

		$prompt[] =
			'If PHP supplies a verified active ticket, that ticket is part of the current application context.';

		$prompt[] =
			'Use the customer message as the factual basis for ticket updates or new-ticket summaries.';

		$prompt[] =
			'Do not invent events, dates, promises, resolutions, or business commitments.';

		$prompt[] =
			'';

		/*
		 * ---------------------------------------------------------
		 * CURRENT APPLICATION STATE
		 * ---------------------------------------------------------
		 */
		$prompt[] =
			'CURRENT APPLICATION STATE:';

		$prompt[] =
			'customer_id=' .
			absint(
				isset(
					$tool_context['customer_id']
				)
					? $tool_context['customer_id']
					: 0
			);

		$prompt[] =
			'conversation_id=' .
			absint(
				isset(
					$tool_context['conversation_id']
				)
					? $tool_context['conversation_id']
					: 0
			);

		$prompt[] =
			'active_ticket_key=' .
			(
				isset(
					$tool_context['active_ticket_key']
				) &&
				'' !==
					$tool_context['active_ticket_key']
					? sanitize_text_field(
						$tool_context['active_ticket_key']
					)
					: 'none'
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
			'';

		/*
		 * ---------------------------------------------------------
		 * PENDING REQUEST
		 * ---------------------------------------------------------
		 */
		if (
			! empty(
				$tool_context['pending_escalation']
			)
		) {

			$pending =
				$tool_context['pending_escalation'];

			$prompt[] =
				'PENDING SUPPORT REQUEST:';

			$prompt[] =
				'Subject: ' .
				(
					isset(
						$pending['subject']
					)
						? sanitize_text_field(
							$pending['subject']
						)
						: ''
				);

			$prompt[] =
				'Summary: ' .
				(
					isset(
						$pending['summary']
					)
						? sanitize_textarea_field(
							$pending['summary']
						)
						: ''
				);

			$prompt[] =
				'Priority: ' .
				(
					isset(
						$pending['priority']
					)
						? sanitize_key(
							$pending['priority']
						)
						: 'normal'
				);

			$prompt[] =
				'Confirmed: ' .
				(
					! empty(
						$pending['confirmed']
					)
						? 'true'
						: 'false'
				);

			$prompt[] =
				'';
		}

		/*
		 * ---------------------------------------------------------
		 * TICKET CONTEXT
		 * ---------------------------------------------------------
		 */
		$prompt[] =
			'TICKET CONTEXT:';

		if (
			empty(
				$ticket_context
			)
		) {

			$prompt[] =
				'No ticket context is currently available.';

		} else {

			foreach (
				$ticket_context as $ticket
			) {

				if (
					! is_array(
						$ticket
					)
				) {
					continue;
				}

				$ticket_key =
					isset(
						$ticket['ticket_key']
					)
						? sanitize_text_field(
							$ticket['ticket_key']
						)
						: '';

				$lookup_status =
					isset(
						$ticket['lookup_status']
					)
						? sanitize_key(
							$ticket['lookup_status']
						)
						: 'unknown';

				$prompt[] =
					'TICKET REFERENCE: ' .
					(
						'' !== $ticket_key
							? $ticket_key
							: 'none'
					);

				$prompt[] =
					'LOOKUP STATUS: ' .
					$lookup_status;

				if (
					'verified' ===
					$lookup_status
				) {

					$prompt[] =
						'TICKET VERIFIED: true';

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

				$prompt[] =
					'';
			}
		}

		/*
		 * ---------------------------------------------------------
		 * KNOWLEDGE
		 * ---------------------------------------------------------
		 */
		$prompt[] =
			'KNOWLEDGE:';

		$prompt[] =
			'Use search_knowledge when authoritative business information is required.';

		$prompt[] =
			'Do not guess business policies, pricing, services, guarantees, or procedures.';

		$prompt[] =
			'';

		/*
		 * ---------------------------------------------------------
		 * CONVERSATION
		 * ---------------------------------------------------------
		 */
		$prompt[] =
			'RECENT CONVERSATION:';

		if (
			empty(
				$history
			)
		) {

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

				$display_role =
					'user' === $role
						? 'Customer'
						: (
							'assistant' === $role
								? 'Assistant'
								: 'Unknown'
						);

				$prompt[] =
					$display_role .
					': ' .
					$content;
			}
		}

		$prompt[] =
			'';

		/*
		 * ---------------------------------------------------------
		 * CURRENT MESSAGE
		 * ---------------------------------------------------------
		 */
		$prompt[] =
			'CURRENT CUSTOMER MESSAGE:';

		$prompt[] =
			$message;

		$prompt[] =
			'';

		/*
		 * ---------------------------------------------------------
		 * RESPONSE FORMAT
		 * ---------------------------------------------------------
		 */
		$prompt[] =
			'RESPONSE FORMAT:';

		$prompt[] =
			'Return ONLY valid JSON.';

		$prompt[] =
			'Use exactly this structure:';

		$prompt[] =
			'{';

		$prompt[] =
			'  "response": "customer-facing response",';

		$prompt[] =
			'  "action": {';

		$prompt[] =
			'    "type": "none",';

		$prompt[] =
			'    "subject": "",';

		$prompt[] =
			'    "summary": "",';

		$prompt[] =
			'    "priority": "normal",';

		$prompt[] =
			'    "ticket_key": "",';

		$prompt[] =
			'    "reason": ""';

		$prompt[] =
			'  }';

		$prompt[] =
			'}';

		$prompt[] =
			'';

		$prompt[] =
			'ACTION TYPES:';

		$prompt[] =
			'none = normal conversation or no application action is needed.';

		$prompt[] =
			'existing_ticket = the customer is discussing an existing ticket.';

		$prompt[] =
			'update_ticket = the customer wants an existing verified ticket updated.';

		$prompt[] =
			'offer_sensitive_ticket = the customer appears to need a new support ticket but PHP requires confirmation before creation.';

		$prompt[] =
			'create_ticket = PHP has authorized creation of a new ticket.';

		$prompt[] =
			'';

		/*
		 * ---------------------------------------------------------
		 * REQUIRED TOOL
		 * ---------------------------------------------------------
		 */
		$required_tool =
			isset(
				$decision['required_tool']
			)
				? sanitize_key(
					$decision['required_tool']
				)
				: '';

		if (
			'create_ticket' ===
			$required_tool
		) {

			$prompt[] =
				'PHP REQUIREMENT: create_ticket is authorized and required. Call create_ticket now.';

		} elseif (
			'update_ticket' ===
			$required_tool
		) {

			$prompt[] =
				'PHP REQUIREMENT: update_ticket is authorized and required. Call update_ticket now.';

		} elseif (
			isset(
				$decision['state']
			) &&
			'escalation_awaiting_confirmation' ===
			sanitize_key(
				$decision['state']
			)
		) {

			$prompt[] =
				'PHP REQUIREMENT: explicit customer confirmation is required before create_ticket may be called.';

		} else {

			$prompt[] =
				'PHP REQUIREMENT: no specific application tool is currently required. Use your reasoning and available tools to determine the appropriate response.';

		}

		$prompt[] =
			'';

		$prompt[] =
			'FINAL SAFETY RULES:';

		$prompt[] =
			'Never claim a ticket was created unless create_ticket returned created=true with a real ticket_id and ticket_key.';

		$prompt[] =
			'Never treat an already_exists result as a newly-created ticket.';

		$prompt[] =
			'Never display an empty ticket number.';

		$prompt[] =
			'Never invent a ticket number.';

		return implode(
			"\n",
			$prompt
		);
	}

	/**
	 * Get configured business AI skill.
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
				'Understand the customer naturally using the complete conversation and authoritative application context.',
				'Use available tools when authoritative information or application action is required.',
				'PHP is authoritative for identity, verification, authorization, database state, and application operations.',
				'Use reasoning to determine the customer intent from the available information.',
				'Do not reduce intent to keyword matching.',
				'A request for human assistance does not automatically mean a new ticket.',
				'An existing active ticket does not automatically mean every future request belongs to it.',
				'Never invent business information.',
				'Never invent ticket numbers.',
				'Never claim a ticket was created unless create_ticket actually returns created=true with a real ticket identifier.',
				'Never treat an already_exists result as a new ticket.',
				'Never claim an update succeeded unless update_ticket returns success.',
				'Never display an empty ticket number.',
			)
		);
	}
}