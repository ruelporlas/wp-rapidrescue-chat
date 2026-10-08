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
 *
 * The AI may request a tool, but PHP remains responsible for:
 *
 * - validating arguments
 * - checking identity
 * - checking authorization
 * - querying WordPress
 * - creating records
 * - returning only safe data
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
	 * Register all business tools.
	 *
	 * @return void
	 */
	private static function register_tools() {

		self::$tools = array(

			'search_knowledge' => array(
				'name'        => 'search_knowledge',
				'description' =>
					'Search the approved business knowledge base for information relevant to the customer request.',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'query' => array(
							'type'        => 'string',
							'description' =>
								'The customer question or topic to search for.',
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
					'Retrieve the currently identified customer basic profile. Only the current conversation customer may be requested.',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(),
					'required'   => array(),
				),
			),

			'lookup_ticket' => array(
				'name'        => 'lookup_ticket',
				'description' =>
					'Look up a support ticket reference without revealing private ticket information. Use this before attempting ticket verification.',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'ticket_key' => array(
							'type'        => 'string',
							'description' =>
								'The support ticket reference, such as RR-00004.',
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
					'Verify that a customer is authorized to access a specific support ticket using the ticket reference and associated email address.',
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
					'Create a new human-support ticket after PHP has confirmed that the customer explicitly authorized ticket creation. PHP generates the ticket number.',
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
								'A factual summary of the customer issue using only information actually supplied by the customer.',
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

		$tool_name = sanitize_key(
			$tool_name
		);

		if (
			isset(
				self::$tools[ $tool_name ]
			)
		) {
			return self::$tools[ $tool_name ];
		}

		return null;
	}

	/**
	 * Execute a tool.
	 *
	 * @param string $tool_name Tool name.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context Execution context.
	 * @return array|WP_Error
	 */
	public static function execute(
		$tool_name,
		$arguments = array(),
		$context = array()
	) {

		self::init();

		$tool_name = sanitize_key(
			$tool_name
		);

		if (
			! isset(
				self::$tools[ $tool_name ]
			)
		) {
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
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	private static function search_knowledge(
		$arguments,
		$context
	) {

		$query = isset(
			$arguments['query']
		)
			? sanitize_textarea_field(
				$arguments['query']
			)
			: '';

		if ( '' === trim( $query ) ) {
			return new WP_Error(
				'invalid_knowledge_query',
				'A knowledge search query is required.'
			);
		}

		$results =
			WP_RapidRescue_Chat_Knowledge::search(
				$query,
				5
			);

		$safe_results = array();

		foreach ( $results as $result ) {

			if ( ! is_array( $result ) ) {
				continue;
			}

			$safe_results[] = array(
				'title' => isset(
					$result['title']
				)
					? sanitize_text_field(
						$result['title']
					)
					: '',
				'content' => isset(
					$result['content']
				)
					? wp_strip_all_tags(
						$result['content']
					)
					: '',
				'categories' => isset(
					$result['categories']
				) &&
				is_array(
					$result['categories']
				)
					? array_map(
						'sanitize_text_field',
						$result['categories']
					)
					: array(),
			);
		}

		return array(
			'success' => true,
			'results' => $safe_results,
		);
	}

	/**
	 * Get the current customer.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
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
				'success' => false,
				'status'  => 'not_identified',
			);
		}

		$customer =
			WP_RapidRescue_Chat_Customer::get_by_id(
				$customer_id
			);

		if ( ! $customer ) {

			return array(
				'success' => false,
				'status'  => 'not_found',
			);
		}

		return array(
			'success'  => true,
			'status'   => 'verified',
			'customer' =>
				WP_RapidRescue_Chat_Tool_Security::customer_to_safe_array(
					$customer
				),
		);
	}

	/**
	 * Look up a ticket without revealing private information.
	 *
	 * IMPORTANT:
	 *
	 * This method intentionally does NOT return the ticket subject,
	 * status, summary, priority, customer ID, or email.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	private static function lookup_ticket(
		$arguments,
		$context
	) {

		$ticket_key = isset(
			$arguments['ticket_key']
		)
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
			return new WP_Error(
				'invalid_ticket_key',
				'The ticket reference is invalid.'
			);
		}

		$ticket =
			WP_RapidRescue_Chat_Ticket::get_by_key(
				$ticket_key
			);

		if ( ! $ticket ) {

			/*
			 * Deliberately generic.
			 *
			 * The AI must not learn whether a guessed ticket belongs
			 * to another customer.
			 */
			return array(
				'success'              => true,
				'found'                => false,
				'verified'             => false,
				'requires_verification' => true,
				'ticket_key'           => $ticket_key,
			);
		}

		return array(
			'success'               => true,
			'found'                 => true,
			'verified'              => false,
			'requires_verification' => true,
			'ticket_key'            => $ticket_key,
		);
	}

	/**
	 * Verify a ticket using the associated email address.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	private static function verify_ticket(
		$arguments,
		$context
	) {

		$ticket_key = isset(
			$arguments['ticket_key']
		)
			? strtoupper(
				sanitize_text_field(
					$arguments['ticket_key']
				)
			)
			: '';

		$email = isset(
			$arguments['email']
		)
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
			return new WP_Error(
				'invalid_ticket_key',
				'The ticket reference is invalid.'
			);
		}

		if ( '' === $email ) {
			return array(
				'success'     => true,
				'verified'    => false,
				'status'      => 'email_required',
				'ticket_key'  => $ticket_key,
			);
		}

		$ticket =
			WP_RapidRescue_Chat_Ticket::get_by_key(
				$ticket_key
			);

		/*
		 * Generic failure for both nonexistent tickets and tickets
		 * belonging to another customer.
		 */
		if ( ! $ticket ) {

			return array(
				'success'    => true,
				'verified'   => false,
				'status'     => 'not_verified',
				'ticket_key' => $ticket_key,
			);
		}

		$customer_id =
			WP_RapidRescue_Chat_Tool_Security::get_customer_id(
				$context
			);

		/*
		 * If the conversation has an identified customer, the ticket
		 * must also belong to that customer.
		 */
		if ( $customer_id > 0 ) {

			if (
				absint(
					$ticket->customer_id
				) !== $customer_id
			) {
				return array(
					'success'    => true,
					'verified'   => false,
					'status'     => 'not_verified',
					'ticket_key' => $ticket_key,
				);
			}
		}

		$customer = null;

		if (
			absint(
				$ticket->customer_id
			) > 0
		) {

			$customer =
				WP_RapidRescue_Chat_Customer::get_by_id(
					absint(
						$ticket->customer_id
					)
				);
		}

		/*
		 * The actual authorization check.
		 *
		 * The ticket email must match the supplied email. If the ticket
		 * has no stored email, the associated customer email is used for
		 * legacy records.
		 */
		$email_matches =
			WP_RapidRescue_Chat_Tool_Security::emails_match_ticket(
				$email,
				$ticket,
				$customer
			);

		if ( ! $email_matches ) {

			return array(
				'success'    => true,
				'verified'   => false,
				'status'     => 'not_verified',
				'ticket_key' => $ticket_key,
			);
		}

		/*
		 * The customer record is now established from the ticket.
		 *
		 * If this conversation did not yet have a customer, we can
		 * safely associate it with the ticket's customer after the
		 * email check succeeds.
		 */
		if (
			$customer_id < 1 &&
			absint(
				$ticket->customer_id
			) > 0
		) {

			$customer_id =
				absint(
					$ticket->customer_id
				);

			$context['customer_id'] =
				$customer_id;
		}

		/*
		 * Verify the ticket for this tool execution context.
		 */
		$context =
			WP_RapidRescue_Chat_Tool_Security::verify_ticket_context(
				$ticket_key,
				$context
			);

		/*
		 * Only now may private ticket fields be returned.
		 */
		return array(
			'success'    => true,
			'verified'   => true,
			'status'     => 'verified',
			'ticket_key' => $ticket_key,
			'ticket'     =>
				WP_RapidRescue_Chat_Tool_Security::ticket_to_safe_array(
					$ticket
				),
			'customer_id' => $customer_id,
			'context'     => array(
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
	 * Create a new support ticket.
	 *
	 * The AI cannot authorize ticket creation by itself.
	 * PHP must provide explicit_ticket_confirmation in the context.
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
			return new WP_Error(
				'ticket_creation_not_authorized',
				'Ticket creation has not been authorized by the application.'
			);
		}

		$conversation_id = absint(
			isset( $context['conversation_id'] )
				? $context['conversation_id']
				: 0
		);

		$customer_id =
			WP_RapidRescue_Chat_Tool_Security::get_customer_id(
				$context
			);

		$subject = isset(
			$arguments['subject']
		)
			? sanitize_text_field(
				$arguments['subject']
			)
			: '';

		$summary = isset(
			$arguments['summary']
		)
			? sanitize_textarea_field(
				$arguments['summary']
			)
			: '';

		$priority = isset(
			$arguments['priority']
		)
			? sanitize_key(
				$arguments['priority']
			)
			: 'normal';

		if ( '' === $subject ) {
			$subject = 'Customer Support Request';
		}

		if ( '' === trim( $summary ) ) {
			return new WP_Error(
				'invalid_ticket_summary',
				'A ticket summary is required.'
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

		/*
		 * PHP creates the ticket.
		 *
		 * The AI never supplies a ticket number.
		 */
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
			return $result;
		}

		if ( empty( $result['ticket_key'] ) ) {
			return new WP_Error(
				'ticket_creation_unconfirmed',
				'The ticket was not successfully confirmed after creation.'
			);
		}

		/*
		 * Only return the minimum information required by the AI
		 * to truthfully tell the customer that the ticket exists.
		 */
		return array(
			'success'        => true,
			'created'        => ! empty(
				$result['created']
			),
			'already_exists' => ! empty(
				$result['already_exists']
			),
			'ticket_id'      => absint(
				$result['ticket_id']
			),
			'ticket_key'     => sanitize_text_field(
				$result['ticket_key']
			),
			'status'         => 'open',
		);
	}
}