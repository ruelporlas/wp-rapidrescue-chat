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

			'create_ticket' => array(
				'name'        => 'create_ticket',
				'description' =>
					'Create a new human-support ticket. PHP controls whether ticket creation is authorized and generates the ticket number. Never invent a ticket number.',
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

		switch ( $tool_name ) {

			case 'search_knowledge':
				return self::search_knowledge(
					$arguments,
					$context
				);

			case 'get_customer':
				return self::get_customer(
					$arguments,
					$context
				);

			case 'lookup_ticket':
				return self::lookup_ticket(
					$arguments,
					$context
				);

			case 'verify_ticket':
				return self::verify_ticket(
					$arguments,
					$context
				);

			case 'create_ticket':
				return self::create_ticket(
					$arguments,
					$context
				);
		}

		return new WP_Error(
			'tool_execution_failed',
			'The requested AI tool could not be executed.'
		);
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
				'success'    => false,
				'state'      => 'invalid_request',
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
	 * The existence result is used internally by the PHP workflow.
	 * No private ticket fields are returned.
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

		/*
		 * We do not expose private ticket data here.
		 *
		 * The old REST workflow needs to know internally whether
		 * a record exists so it can continue its PHP-controlled
		 * verification flow.
		 */
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
	 * The supplied email is the evidence used to authorize access
	 * to the ticket. Existing customer context is used when useful,
	 * but it must not prevent a legitimate legacy ticket from being
	 * verified when the ticket's own email/customer relationship
	 * matches the supplied email.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Tool context.
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

		/*
		 * First verify against the ticket's own stored email or
		 * its associated customer's email.
		 */
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

		/*
		 * We have now proven that the supplied email belongs to
		 * this ticket.
		 *
		 * Determine the authoritative customer.
		 */
		$verified_customer_id =
			absint(
				$ticket->customer_id
			);

		/*
		 * Legacy anonymous ticket:
		 *
		 * The ticket has no customer_id, but the supplied email
		 * identifies an existing customer.
		 */
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

				/*
				 * Attach the legacy ticket to the verified customer.
				 */
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

		/*
		 * If the ticket has an associated customer, the supplied
		 * email has already been checked against that customer's
		 * email or the ticket's stored customer_email.
		 *
		 * Do not let an earlier conversation identity prevent the
		 * actual ticket/email verification from succeeding.
		 */
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

			/*
			 * Keep these at the top level as well because the
			 * provider adapters use them to carry application
			 * state between tool rounds.
			 */
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
	 * Create a support ticket.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Tool context.
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

		$subject =
			isset( $arguments['subject'] )
				? sanitize_text_field(
					$arguments['subject']
				)
				: '';

		$summary =
			isset( $arguments['summary'] )
				? sanitize_textarea_field(
					$arguments['summary']
				)
				: '';

		$priority =
			isset( $arguments['priority'] )
				? sanitize_key(
					$arguments['priority']
				)
				: 'normal';

		if ( '' === $subject ) {
			$subject = 'Customer Support Request';
		}

		if ( '' === trim( $summary ) ) {

			return array(
				'success'     => false,
				'state'       => 'invalid_request',
				'next_action' => 'provide_ticket_summary',
			);
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

			return array(
				'success'     => false,
				'state'       => 'creation_failed',
				'next_action' =>
					'tell_customer_creation_failed',
				'error'       =>
					$result->get_error_message(),
			);
		}

		if ( empty( $result['ticket_key'] ) ) {

			return array(
				'success'     => false,
				'state'       => 'creation_unconfirmed',
				'next_action' =>
					'tell_customer_creation_failed',
			);
		}

		return array(
			'success' =>
				true,

			'state' =>
				'created',

			'next_action' =>
				'tell_customer_ticket_created',

			'ticket_id' =>
				absint(
					$result['ticket_id']
				),

			'ticket_key' =>
				sanitize_text_field(
					$result['ticket_key']
				),

			'created' =>
				! empty(
					$result['created']
				),

			'already_exists' =>
				! empty(
					$result['already_exists']
				),
		);
	}
}