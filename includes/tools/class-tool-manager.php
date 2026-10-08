<?php
/**
 * Provider-independent AI tool manager.
 *
 * @package WP_RapidRescue_Chat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages AI tools and executes them through PHP.
 */
class WP_RapidRescue_Chat_Tool_Manager {

	/**
	 * Registered tool definitions.
	 *
	 * @var array
	 */
	private static $tools = array();

	/**
	 * Initialize the tool manager.
	 *
	 * @return void
	 */
	public static function init() {

		if ( ! empty( self::$tools ) ) {
			return;
		}

		self::register_tools();
	}

	/**
	 * Register business tools.
	 *
	 * @return void
	 */
	private static function register_tools() {

		self::$tools = array(

			'search_knowledge' => array(
				'name'        => 'search_knowledge',
				'description' =>
					'Search the approved business knowledge base for authoritative information relevant to the customer request. Use this when the answer depends on business-specific information such as services, pricing, policies, procedures, or support information.',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'query' => array(
							'type'        => 'string',
							'description' =>
								'The customer question or business topic to search for.',
						),
					),
					'required'   => array(
						'query',
					),
				),
			),

			'get_customer' => array(
				'name'        => 'get_customer',
				'description' =>
					'Retrieve the basic profile of the customer associated with the current conversation. Do not use this tool to access another customer.',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(),
					'required'   => array(),
				),
			),

			'lookup_ticket' => array(
				'name'        => 'lookup_ticket',
				'description' =>
					'Begin the support ticket lookup workflow using a ticket reference. This tool does not reveal private ticket information. The result tells the assistant what information is required next, such as an associated email address.',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'ticket_key' => array(
							'type'        => 'string',
							'description' =>
								'The support ticket reference, such as RR-00005.',
						),
					),
					'required'   => array(
						'ticket_key',
					),
				),
			),

			'verify_ticket' => array(
				'name'        => 'verify_ticket',
				'description' =>
					'Verify customer access to a specific support ticket using the ticket reference and associated email address. Only after successful verification may private ticket information be returned.',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'ticket_key' => array(
							'type'        => 'string',
							'description' =>
								'The support ticket reference.',
						),
						'email' => array(
							'type'        => 'string',
							'description' =>
								'The email address associated with the support ticket.',
						),
					),
					'required'   => array(
						'ticket_key',
						'email',
					),
				),
			),

			'update_ticket' => array(
				'name'        => 'update_ticket',
				'description' =>
					'Update an existing verified customer support ticket when the customer says the issue is still unresolved, provides a follow-up, adds information, or asks for the ticket to be prioritized. PHP verifies customer ownership and ticket access before any update is written. Use the customer\'s actual follow-up message as the update; never invent facts or promise a resolution time.',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'ticket_key' => array(
							'type'        => 'string',
							'description' =>
								'The existing support ticket reference, such as RR-00005.',
						),
						'update_message' => array(
							'type'        => 'string',
							'description' =>
								'A concise factual note containing the customer\'s follow-up or new information. Preserve the customer\'s requested urgency without promising that the issue will be resolved by a particular time.',
						),
						'priority' => array(
							'type'        => 'string',
							'enum'        => array(
								'low',
								'normal',
								'high',
								'urgent',
							),
							'description' =>
								'Optional requested priority. Keep the existing priority unless the customer clearly requests urgency or the issue warrants escalation.',
						),
					),
					'required'   => array(
						'ticket_key',
						'update_message',
					),
				),
			),

			'create_ticket' => array(
				'name'        => 'create_ticket',
				'description' =>
					'Create a NEW human-support ticket. Multiple open tickets are allowed when the customer explicitly requests a new or separate ticket. PHP controls authorization, pending escalation state, and ticket number generation. An existing active ticket does not block creation of a separately requested new ticket. Never invent a ticket number.',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'subject' => array(
							'type'        => 'string',
							'description' =>
								'A concise support ticket subject.',
						),
						'summary' => array(
							'type'        => 'string',
							'description' =>
								'A factual summary of the customer issue using information supplied by the customer.',
						),
						'priority' => array(
							'type'        => 'string',
							'enum'        => array(
								'low',
								'normal',
								'high',
								'urgent',
							),
							'description' =>
								'Ticket priority. Use normal unless the issue clearly warrants another priority.',
						),
					),
					'required'   => array(
						'subject',
						'summary',
						'priority',
					),
				),
			),
		);
	}

	/**
	 * Get all tool definitions.
	 *
	 * @return array
	 */
	public static function get_tools() {

		self::init();

		return self::$tools;
	}

	/**
	 * Get one tool definition.
	 *
	 * @param string $tool_name Tool name.
	 * @return array|null
	 */
	public static function get_tool( $tool_name ) {

		self::init();

		$tool_name = sanitize_key( $tool_name );

		if ( isset( self::$tools[ $tool_name ] ) ) {
			return self::$tools[ $tool_name ];
		}

		return null;
	}

	/**
	 * Execute a tool.
	 *
	 * PHP is the final authorization boundary.
	 *
	 * When the Control Engine requires a specific tool, that
	 * requirement cannot be bypassed by the AI provider or model.
	 *
	 * @param string $tool_name Tool name.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @return array|WP_Error
	 */
	public static function execute(
		$tool_name,
		$arguments = array(),
		$context = array()
	) {

		self::init();

		$tool_name = sanitize_key( $tool_name );

		if ( ! isset( self::$tools[ $tool_name ] ) ) {

			return new WP_Error(
				'unknown_ai_tool',
				'The requested AI tool is not available.'
			);
		}

		if ( ! is_array( $arguments ) ) {
			$arguments = array();
		}

		if ( ! is_array( $context ) ) {
			$context = array();
		}

		/*
		 * ---------------------------------------------------------
		 * PHP CONTROL ENGINE GATE
		 * ---------------------------------------------------------
		 *
		 * The model may request a registered tool, but registration
		 * does not mean that the tool is currently authorized.
		 *
		 * When a required tool is pending, only that exact tool may
		 * execute.
		 *
		 * This is defense-in-depth. The provider also constrains the
		 * model's tool choice, but PHP remains the final authority.
		 */
		$required_tool =
			WP_RapidRescue_Chat_Control_Engine::get_required_tool(
				$context
			);

		$required_tool_pending =
			! empty(
				$context['required_tool_pending']
			);

		if (
			$required_tool_pending &&
			'' !== $required_tool &&
			$tool_name !== $required_tool
		) {

			WP_RapidRescue_Chat_Debug::tool(
				'Tool blocked by Control Engine requirement',
				array(
					'requested_tool' =>
						$tool_name,

					'required_tool' =>
						$required_tool,
				)
			);

			return array(
				'success'     => false,
				'state'       => 'required_tool_mismatch',
				'next_action' => 'execute_required_tool',
				'tool'        => $tool_name,
				'required_tool' =>
					$required_tool,
				'blocked'     => true,
			);
		}

		$result = null;

		switch ( $tool_name ) {

			case 'search_knowledge':
				$result = self::search_knowledge(
					$arguments,
					$context
				);
				break;

			case 'get_customer':
				$result = self::get_customer(
					$arguments,
					$context
				);
				break;

			case 'lookup_ticket':
				$result = self::lookup_ticket(
					$arguments,
					$context
				);
				break;

			case 'verify_ticket':
				$result = self::verify_ticket(
					$arguments,
					$context
				);
				break;

			case 'update_ticket':
				$result = self::update_ticket(
					$arguments,
					$context
				);
				break;

			case 'create_ticket':
				$result = self::create_ticket(
					$arguments,
					$context
				);
				break;
		}

		if ( null === $result ) {

			return new WP_Error(
				'tool_execution_failed',
				'The requested AI tool could not be executed.'
			);
		}

		/*
		 * Explicitly tell the provider whether the required business
		 * operation was genuinely completed.
		 *
		 * Do not use only success=true. Some tools can return
		 * success=true while still requiring another step.
		 */
		if (
			is_array( $result ) &&
			$required_tool_pending &&
			'' !== $required_tool &&
			$tool_name === $required_tool &&
			self::required_tool_satisfied(
				$tool_name,
				$result
			)
		) {

			$result['required_tool_satisfied'] = true;
		} else if (
			is_array( $result ) &&
			$required_tool_pending &&
			$tool_name === $required_tool
		) {

			$result['required_tool_satisfied'] = false;
		}

		return $result;
	}

	/**
	 * Determine whether a required tool actually completed its
	 * required business operation.
	 *
	 * @param string $tool_name Tool name.
	 * @param array  $result Tool result.
	 * @return bool
	 */
	private static function required_tool_satisfied(
		$tool_name,
		$result
	) {

		if ( ! is_array( $result ) ) {
			return false;
		}

		if ( empty( $result['success'] ) ) {
			return false;
		}

		switch ( $tool_name ) {

			case 'create_ticket':
				return (
					! empty( $result['created'] ) &&
					absint(
						isset( $result['ticket_id'] )
							? $result['ticket_id']
							: 0
					) > 0 &&
					'' !== sanitize_text_field(
						isset( $result['ticket_key'] )
							? $result['ticket_key']
							: ''
					)
				);

			case 'update_ticket':
				return ! empty(
					$result['updated']
				);

			case 'verify_ticket':
				return (
					isset( $result['state'] ) &&
					'verified' ===
						sanitize_key(
							$result['state']
						)
				);

			case 'lookup_ticket':
				return (
					isset( $result['state'] ) &&
					in_array(
						sanitize_key(
							$result['state']
						),
						array(
							'email_required',
							'not_found',
						),
						true
					)
				);

			default:
				return true;
		}
	}

	/**
	 * Search approved business knowledge.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Tool context.
	 * @return array|WP_Error
	 */
	private static function search_knowledge(
		$arguments,
		$context
	) {

		$query = isset( $arguments['query'] )
			? sanitize_textarea_field( $arguments['query'] )
			: '';

		if ( '' === trim( $query ) ) {

			return array(
				'success'     => false,
				'state'       => 'invalid_request',
				'next_action' => 'ask_for_knowledge_query',
			);
		}

		$results = WP_RapidRescue_Chat_Knowledge::search(
			$query,
			5
		);

		$safe_results = array();

		foreach ( $results as $result ) {

			if ( ! is_array( $result ) ) {
				continue;
			}

			$safe_results[] = array(
				'title' => isset( $result['title'] )
					? sanitize_text_field( $result['title'] )
					: '',

				'content' => isset( $result['content'] )
					? wp_strip_all_tags( $result['content'] )
					: '',

				'categories' =>
					isset( $result['categories'] ) &&
					is_array( $result['categories'] )
						? array_map(
							'sanitize_text_field',
							$result['categories']
						)
						: array(),
			);
		}

		if ( empty( $safe_results ) ) {

			return array(
				'success'     => true,
				'state'       => 'not_found',
				'next_action' => 'knowledge_not_confirmed',
				'data'        => array(
					'results' => array(),
				),
			);
		}

		return array(
			'success'     => true,
			'state'       => 'found',
			'next_action' => 'use_knowledge_results',
			'data'        => array(
				'results' => $safe_results,
			),
		);
	}

	/**
	 * Get the current customer.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Tool context.
	 * @return array|WP_Error
	 */
	private static function get_customer(
		$arguments,
		$context
	) {

		$customer_id =
			WP_RapidRescue_Chat_Tool_Security::get_customer_id(
				$context
			);

		if ( $customer_id < 1 ) {

			return array(
				'success'     => false,
				'state'       => 'not_identified',
				'next_action' => 'ask_customer_for_identity',
			);
		}

		$customer =
			WP_RapidRescue_Chat_Customer::get_by_id(
				$customer_id
			);

		if ( ! $customer ) {

			return array(
				'success'     => false,
				'state'       => 'not_found',
				'next_action' => 'customer_not_available',
			);
		}

		return array(
			'success'     => true,
			'state'       => 'found',
			'next_action' => 'use_customer_information',
			'data'        => array(
				'customer' =>
					WP_RapidRescue_Chat_Tool_Security::customer_to_safe_array(
						$customer
					),
			),
		);
	}

	/**
	 * Start a ticket lookup.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Tool context.
	 * @return array|WP_Error
	 */
	private static function lookup_ticket(
		$arguments,
		$context
	) {

		$ticket_key = isset( $arguments['ticket_key'] )
			? strtoupper(
				sanitize_text_field(
					$arguments['ticket_key']
				)
			)
			: '';

		if (
			'' === $ticket_key ||
			! preg_match(
				'/^RR-\d{1,10}$/',
				$ticket_key
			)
		) {

			return array(
				'success'     => false,
				'state'       => 'invalid_ticket_reference',
				'next_action' =>
					'ask_customer_to_check_ticket_number',
				'found'       => false,
			);
		}

		$ticket =
			WP_RapidRescue_Chat_Ticket::get_by_key(
				$ticket_key
			);

		if ( ! $ticket ) {

			return array(
				'success'     => true,
				'state'       => 'not_found',
				'next_action' =>
					'ask_customer_for_email',
				'found'       => false,
				'data'        => array(
					'ticket_key' => $ticket_key,
				),
			);
		}

		return array(
			'success'     => true,
			'state'       => 'email_required',
			'next_action' => 'ask_customer_for_email',
			'found'       => true,
			'data'        => array(
				'ticket_key' => $ticket_key,
			),
		);
	}

	/**
	 * Verify a ticket.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Tool execution context.
	 * @return array|WP_Error
	 */
	private static function verify_ticket(
		$arguments,
		$context
	) {

		$ticket_key = isset( $arguments['ticket_key'] )
			? strtoupper(
				sanitize_text_field(
					$arguments['ticket_key']
				)
			)
			: '';

		$email = isset( $arguments['email'] )
			? sanitize_email(
				$arguments['email']
			)
			: '';

		if (
			'' === $ticket_key ||
			! preg_match(
				'/^RR-\d{1,10}$/',
				$ticket_key
			)
		) {

			return array(
				'success'     => false,
				'state'       => 'invalid_ticket_reference',
				'next_action' =>
					'ask_customer_to_check_ticket_number',
			);
		}

		if ( '' === $email ) {

			return array(
				'success'     => true,
				'state'       => 'email_required',
				'next_action' => 'ask_customer_for_email',
				'data'        => array(
					'ticket_key' => $ticket_key,
				),
			);
		}

		$ticket =
			WP_RapidRescue_Chat_Ticket::get_by_key(
				$ticket_key
			);

		if ( ! $ticket ) {

			return array(
				'success'     => false,
				'state'       => 'not_verified',
				'next_action' =>
					'ask_customer_to_check_ticket_details',
			);
		}

		$ticket_customer = null;

		if ( absint( $ticket->customer_id ) > 0 ) {

			$ticket_customer =
				WP_RapidRescue_Chat_Customer::get_by_id(
					absint( $ticket->customer_id )
				);
		}

		$email_matches =
			WP_RapidRescue_Chat_Tool_Security::emails_match_ticket(
				$email,
				$ticket,
				$ticket_customer
			);

		if ( ! $email_matches ) {

			return array(
				'success'     => false,
				'state'       => 'not_verified',
				'next_action' => 'ask_customer_to_check_email',
			);
		}

		$verified_customer_id =
			absint(
				$ticket->customer_id
			);

		if ( $verified_customer_id < 1 ) {

			$email_customer =
				WP_RapidRescue_Chat_Customer::get_by_email(
					$email
				);

			if ( $email_customer ) {

				$verified_customer_id =
					absint(
						$email_customer->id
					);

				$assign_result =
					WP_RapidRescue_Chat_Ticket::assign_customer(
						$ticket->id,
						$verified_customer_id
					);

				if ( is_wp_error( $assign_result ) ) {

					return array(
						'success'     => false,
						'state'       => 'not_verified',
						'next_action' =>
							'ask_customer_to_try_again',
					);
				}

				$ticket =
					WP_RapidRescue_Chat_Ticket::get_by_id(
						$ticket->id
					);
			}
		}

		$context['customer_id'] =
			$verified_customer_id;

		$context =
			WP_RapidRescue_Chat_Tool_Security::verify_ticket_context(
				$ticket_key,
				$context
			);

		return array(
			'success'     => true,
			'state'       => 'verified',
			'next_action' => 'provide_ticket_information',
			'data'        => array(
				'ticket' =>
					WP_RapidRescue_Chat_Tool_Security::ticket_to_safe_array(
						$ticket
					),
			),
			'customer_id' =>
				$verified_customer_id,
			'context'     => array(
				'customer_id' =>
					$verified_customer_id,
				'verified_ticket_keys' =>
					isset(
						$context['verified_ticket_keys']
					) &&
					is_array(
						$context['verified_ticket_keys']
					)
						? $context['verified_ticket_keys']
						: array(),
			),
		);
	}

	/**
	 * Update an existing verified support ticket.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Tool context.
	 * @return array|WP_Error
	 */
	private static function update_ticket(
		$arguments,
		$context
	) {

		$ticket_key = isset( $arguments['ticket_key'] )
			? strtoupper(
				sanitize_text_field(
					$arguments['ticket_key']
				)
			)
			: '';

		$update_message = isset( $arguments['update_message'] )
			? sanitize_textarea_field(
				$arguments['update_message']
			)
			: '';

		$requested_priority = isset( $arguments['priority'] )
			? sanitize_key( $arguments['priority'] )
			: '';

		if (
			'' === $ticket_key ||
			! preg_match(
				'/^RR-\d{1,10}$/',
				$ticket_key
			)
		) {

			return array(
				'success'     => false,
				'state'       => 'invalid_ticket_reference',
				'next_action' => 'ask_customer_to_check_ticket_number',
			);
		}

		if ( '' === trim( $update_message ) ) {

			return array(
				'success'     => false,
				'state'       => 'invalid_update_message',
				'next_action' => 'ask_customer_for_update_details',
			);
		}

		$ticket =
			WP_RapidRescue_Chat_Ticket::get_by_key(
				$ticket_key
			);

		if ( ! $ticket ) {

			return array(
				'success'     => false,
				'state'       => 'not_found',
				'next_action' => 'ask_customer_to_check_ticket_details',
			);
		}

		if (
			! WP_RapidRescue_Chat_Tool_Security::can_access_ticket(
				$ticket,
				$context
			)
		) {

			WP_RapidRescue_Chat_Debug::tool(
				'update_ticket blocked by PHP security gate',
				array(
					'ticket_key' => $ticket_key,
				)
			);

			return array(
				'success'     => false,
				'state'       => 'ticket_verification_required',
				'next_action' => 'verify_ticket_before_update',
			);
		}

		if (
			! in_array(
				$ticket->status,
				array(
					'open',
					'in_progress',
					'waiting_customer',
				),
				true
			)
		) {

			WP_RapidRescue_Chat_Debug::tool(
				'update_ticket blocked: ticket is not active',
				array(
					'ticket_key' => $ticket_key,
					'status'     => sanitize_key( $ticket->status ),
				)
			);

			return array(
				'success'     => false,
				'state'       => 'ticket_not_updateable',
				'next_action' => 'tell_customer_ticket_not_updateable',
				'data'        => array(
					'ticket' =>
						WP_RapidRescue_Chat_Tool_Security::ticket_to_safe_array(
							$ticket
						),
				),
			);
		}

		$follow_up_date =
			wp_date(
				'F j, Y',
				current_time(
					'timestamp',
					true
				)
			);

		$follow_up =
			'Customer follow-up (' .
			$follow_up_date .
			'): ' .
			trim(
				$update_message
			);

		$existing_summary =
			sanitize_textarea_field(
				isset(
					$ticket->summary
				)
					? $ticket->summary
					: ''
			);

		if ( '' !== trim( $existing_summary ) ) {

			$new_summary =
				$existing_summary .
				"\n\n" .
				$follow_up;

		} else {

			$new_summary =
				$follow_up;
		}

		$priority = '';

		if (
			in_array(
				$requested_priority,
				array(
					'low',
					'normal',
					'high',
					'urgent',
				),
				true
			)
		) {

			$priority =
				$requested_priority;
		}

		$update_result =
			WP_RapidRescue_Chat_Ticket::update(
				absint( $ticket->id ),
				'',
				$new_summary,
				'',
				$priority
			);

		if ( is_wp_error( $update_result ) ) {

			WP_RapidRescue_Chat_Debug::tool(
				'update_ticket database update failed',
				array(
					'ticket_key' =>
						$ticket_key,

					'error_code' =>
						$update_result->get_error_code(),
				)
			);

			return array(
				'success'     => false,
				'state'       => 'update_failed',
				'next_action' => 'tell_customer_update_failed',
			);
		}

		$updated_ticket =
			WP_RapidRescue_Chat_Ticket::get_by_id(
				absint( $ticket->id )
			);

		if ( ! $updated_ticket ) {

			return array(
				'success'     => false,
				'state'       => 'update_unconfirmed',
				'next_action' => 'tell_customer_update_failed',
			);
		}

		WP_RapidRescue_Chat_Debug::tool(
			'update_ticket succeeded',
			array(
				'ticket_key' =>
					$ticket_key,

				'priority' =>
					sanitize_key(
						$updated_ticket->priority
					),
			)
		);

		return array(
			'success'     => true,
			'state'       => 'updated',
			'next_action' => 'tell_customer_ticket_updated',
			'updated'     => true,
			'ticket_id'   =>
				absint(
					$updated_ticket->id
				),
			'ticket_key'  =>
				sanitize_text_field(
					$updated_ticket->ticket_key
				),
			'data'        => array(
				'ticket' =>
					WP_RapidRescue_Chat_Tool_Security::ticket_to_safe_array(
						$updated_ticket
					),
			),
		);
	}

	/**
	 * Create a support ticket.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	private static function create_ticket(
		$arguments,
		$context
	) {

		if (
			! WP_RapidRescue_Chat_Tool_Security::can_create_ticket(
				$context
			)
		) {

			WP_RapidRescue_Chat_Debug::tool(
				'create_ticket blocked by PHP security gate'
			);

			return array(
				'success'     => false,
				'state'       => 'creation_not_authorized',
				'next_action' => 'ask_customer_for_confirmation',
			);
		}

		$conversation_id =
			absint(
				isset( $context['conversation_id'] )
					? $context['conversation_id']
					: 0
			);

		$customer_id =
			WP_RapidRescue_Chat_Tool_Security::get_customer_id(
				$context
			);

		if (
			$conversation_id < 1 ||
			$customer_id < 1
		) {

			return array(
				'success'     => false,
				'state'       => 'invalid_creation_context',
				'next_action' => 'ask_customer_for_identity',
			);
		}

		$pending =
			WP_RapidRescue_Chat_Conversation::get_pending_sensitive_escalation(
				$conversation_id
			);

		if (
			! is_array( $pending ) ||
			empty( $pending )
		) {

			WP_RapidRescue_Chat_Debug::tool(
				'create_ticket blocked: no pending escalation'
			);

			return array(
				'success'     => false,
				'state'       => 'no_pending_escalation',
				'next_action' => 'offer_sensitive_ticket',
			);
		}

		$subject =
			isset( $pending['subject'] )
				? sanitize_text_field(
					$pending['subject']
				)
				: '';

		$summary =
			isset( $pending['summary'] )
				? sanitize_textarea_field(
					$pending['summary']
				)
				: '';

		$priority =
			isset( $pending['priority'] )
				? sanitize_key(
					$pending['priority']
				)
				: 'normal';

		if ( '' === $subject ) {
			$subject =
				isset( $arguments['subject'] )
					? sanitize_text_field(
						$arguments['subject']
					)
					: 'Customer Support Request';
		}

		if ( '' === trim( $summary ) ) {
			$summary =
				isset( $arguments['summary'] )
					? sanitize_textarea_field(
						$arguments['summary']
					)
					: '';
		}

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

		if ( '' === trim( $summary ) ) {

			return array(
				'success'     => false,
				'state'       => 'invalid_request',
				'next_action' => 'provide_ticket_summary',
			);
		}

		$result =
			WP_RapidRescue_Chat_Ticket::create_from_conversation(
				$conversation_id,
				$customer_id,
				$subject,
				$summary,
				$priority,
				false
			);

		if ( is_wp_error( $result ) ) {

			WP_RapidRescue_Chat_Debug::tool(
				'create_ticket database creation failed',
				array(
					'error_code' =>
						$result->get_error_code(),
				)
			);

			return array(
				'success'     => false,
				'state'       => 'creation_failed',
				'next_action' =>
					'tell_customer_creation_failed',
				'error'       =>
					$result->get_error_message(),
			);
		}

		if (
			empty( $result['ticket_key'] ) ||
			empty( $result['ticket_id'] )
		) {

			WP_RapidRescue_Chat_Debug::tool(
				'create_ticket returned incomplete creation result'
			);

			return array(
				'success'     => false,
				'state'       => 'creation_unconfirmed',
				'next_action' =>
					'tell_customer_creation_failed',
			);
		}

		$ticket_id =
			absint(
				$result['ticket_id']
			);

		$ticket_key =
			sanitize_text_field(
				$result['ticket_key']
			);

		WP_RapidRescue_Chat_Conversation::clear_pending_sensitive_escalation(
			$conversation_id
		);

		WP_RapidRescue_Chat_Conversation::set_active_ticket(
			$conversation_id,
			$ticket_key,
			$customer_id
		);

		WP_RapidRescue_Chat_Debug::tool(
			'create_ticket succeeded',
			array(
				'ticket_id' =>
					$ticket_id,

				'ticket_key' =>
					$ticket_key,
			)
		);

		return array(
			'success' =>
				true,

			'state' =>
				'created',

			'next_action' =>
				'tell_customer_ticket_created',

			'ticket_id' =>
				$ticket_id,

			'ticket_key' =>
				$ticket_key,

			'created' =>
				true,

			'already_exists' =>
				false,
		);
	}
}