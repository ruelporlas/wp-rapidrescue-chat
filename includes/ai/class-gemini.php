<?php
/**
 * Gemini provider.
 *
 * @package WP_RapidRescue_Chat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gemini provider.
 */
class WP_RapidRescue_Chat_AI_Gemini extends WP_RapidRescue_Chat_AI_Provider {

	const API_ENDPOINT    = 'https://generativelanguage.googleapis.com/v1beta/interactions';
	const MAX_TOOL_ROUNDS = 6;

	/**
	 * Tools that are safe to expose when PHP has not authorized
	 * an application-changing operation.
	 *
	 * IMPORTANT:
	 * create_ticket and update_ticket are intentionally excluded.
	 *
	 * @var array
	 */
	const READ_ONLY_TOOLS = array(
		'search_knowledge',
		'get_customer',
		'lookup_ticket',
		'verify_ticket',
	);

	public function get_id() {

		return 'gemini';
	}

	public function get_name() {

		return 'Gemini';
	}

	public function respond( $message ) {

		return $this->respond_with_tools(
			$message,
			array()
		);
	}

	/**
	 * Respond using Gemini function calling.
	 *
	 * PHP Control Engine is the authority over which application
	 * operation may execute.
	 *
	 * @param string $message Message.
	 * @param array  $context Runtime context.
	 * @return array|WP_Error
	 */
	public function respond_with_tools(
		$message,
		$context = array()
	) {

		$api_key =
			WP_RapidRescue_Chat_Settings::get(
				'gemini_api_key',
				''
			);

		$model =
			WP_RapidRescue_Chat_Settings::get(
				'gemini_model',
				'gemini-3.5-flash-lite'
			);

		if ( '' === $api_key ) {

			return new WP_Error(
				'missing_gemini_api_key',
				'The Gemini API key has not been configured.'
			);
		}

		if ( ! is_array( $context ) ) {
			$context = array();
		}

		/*
		 * PHP determines whether a specific application-changing
		 * tool is currently authorized.
		 */
		$required_tool =
			WP_RapidRescue_Chat_Control_Engine::get_required_tool(
				$context
			);

		$required_tool =
			sanitize_key(
				$required_tool
			);

		$required_tool_pending =
			'' !== $required_tool;

		/*
		 * ---------------------------------------------------------
		 * TOOL AVAILABILITY
		 * ---------------------------------------------------------
		 *
		 * If PHP has authorized a required tool, expose ONLY that
		 * exact tool.
		 *
		 * If PHP has NOT authorized a required tool, expose only
		 * read-only tools.
		 *
		 * Therefore Gemini cannot invent:
		 *
		 * - create_ticket
		 * - update_ticket
		 *
		 * during an ordinary conversation or email collection step.
		 */
		if ( $required_tool_pending ) {

			$tools =
				$this->get_tools(
					array(
						$required_tool,
					)
				);

		} else {

			$tools =
				$this->get_tools(
					self::READ_ONLY_TOOLS
				);
		}

		$system_instruction =
			WP_RapidRescue_Chat_AI::get_system_instructions();

		$verified_customer_id =
			isset( $context['customer_id'] )
				? absint(
					$context['customer_id']
				)
				: 0;

		$verified_ticket_keys =
			isset( $context['verified_ticket_keys'] ) &&
			is_array( $context['verified_ticket_keys'] )
				? array_values(
					array_filter(
						array_map(
							'sanitize_text_field',
							$context['verified_ticket_keys']
						)
					)
				)
				: array();

		/*
		 * PHP-controlled identity follow-up flag.
		 *
		 * This is informational to the provider and reinforces that
		 * an email-only message is not ticket authorization.
		 */
		$identity_followup =
			! empty(
				$context['identity_followup']
			);

		$request_body = array(
			'model'              => $model,
			'input'              => (string) $message,
			'system_instruction' => (string) $system_instruction,
			'tools'              => $tools,
			'store'              => true,
		);

		/*
		 * ---------------------------------------------------------
		 * REQUIRED TOOL MODE
		 * ---------------------------------------------------------
		 *
		 * When PHP requires a specific application operation,
		 * Gemini is constrained to that exact tool.
		 */
		if ( $required_tool_pending ) {

			$request_body['generation_config'] =
				array(
					'tool_choice' =>
						array(
							'allowed_tools' =>
								array(
									'mode' =>
										'any',

									'tools' =>
										array(
											$required_tool,
										),
								),
						),
				);

			WP_RapidRescue_Chat_Debug::ai(
				'Control Engine requiring Gemini tool call',
				array(
					'tool' =>
						$required_tool,
				)
			);

		} else {

			WP_RapidRescue_Chat_Debug::ai(
				'Gemini restricted to read-only tools',
				array(
					'identity_followup' =>
						$identity_followup,

					'tool_count' =>
						count( $tools ),
				)
			);
		}

		WP_RapidRescue_Chat_Debug::ai(
			'Gemini request prepared',
			array(
				'model' =>
					$model,

				'input_type' =>
					'string',

				'tool_count' =>
					count( $tools ),

				'required_tool' =>
					$required_tool,

				'required_tool_pending' =>
					$required_tool_pending,

				'identity_followup' =>
					$identity_followup,

				'endpoint' =>
					self::API_ENDPOINT,
			)
		);

		$interaction_id = '';

		$tool_results = array();

		for (
			$round = 0;
			$round < self::MAX_TOOL_ROUNDS;
			$round++
		) {

			$result =
				$this->request(
					$api_key,
					$request_body,
					$model
				);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if (
				isset( $result['interaction_id'] ) &&
				'' !== $result['interaction_id']
			) {

				$interaction_id =
					$result['interaction_id'];
			}

			/*
			 * Never accept a normal text response while PHP still
			 * requires a business action.
			 */
			if ( empty( $result['tool_calls'] ) ) {

				if ( $required_tool_pending ) {

					WP_RapidRescue_Chat_Debug::ai(
						'Gemini attempted to finish before required tool execution',
						array(
							'required_tool' =>
								$required_tool,
						)
					);

					return new WP_Error(
						'required_gemini_tool_not_executed',
						'Gemini did not execute the required application action.'
					);
				}

				$text =
					isset( $result['text'] )
						? trim(
							$result['text']
						)
						: '';

				if ( '' === $text ) {

					return new WP_Error(
						'empty_gemini_response',
						'Gemini returned no text response.'
					);
				}

				return array(
					'text' =>
						$text,

					'provider' =>
						$this->get_id(),

					'model' =>
						$model,

					'response_id' =>
						$interaction_id,

					'customer_id' =>
						$verified_customer_id,

					'verified_ticket_keys' =>
						array_values(
							array_unique(
								$verified_ticket_keys
							)
						),

					'tool_results' =>
						$tool_results,
				);
			}

			$function_results = array();

			foreach (
				$result['tool_calls'] as $tool_call
			) {

				if ( ! is_array( $tool_call ) ) {
					continue;
				}

				$tool_name =
					isset( $tool_call['name'] )
						? sanitize_key(
							$tool_call['name']
						)
						: '';

				$call_id =
					isset( $tool_call['id'] )
						? sanitize_text_field(
							$tool_call['id']
						)
						: '';

				$arguments =
					isset( $tool_call['arguments'] )
						? $tool_call['arguments']
						: array();

				if ( is_string( $arguments ) ) {

					$decoded =
						json_decode(
							$arguments,
							true
						);

					$arguments =
						is_array( $decoded )
							? $decoded
							: array();
				}

				if ( ! is_array( $arguments ) ) {
					$arguments = array();
				}

				if (
					'' === $tool_name ||
					'' === $call_id
				) {
					continue;
				}

				/*
				 * Defense in depth.
				 *
				 * Even though the Gemini request only contains
				 * authorized tools, never allow a write tool to slip
				 * through if PHP did not explicitly require it.
				 */
				if (
					in_array(
						$tool_name,
						array(
							'create_ticket',
							'update_ticket',
						),
						true
					) &&
					$tool_name !== $required_tool
				) {

					WP_RapidRescue_Chat_Debug::tool(
						'Gemini write tool blocked because PHP did not authorize it',
						array(
							'tool' =>
								$tool_name,

							'required_tool' =>
								$required_tool,
						)
					);

					$tool_result =
						array(
							'success' =>
								false,

							'state' =>
								'php_authorization_required',

							'next_action' =>
								'continue_conversation',

							'tool' =>
								$tool_name,

							'blocked' =>
								true,
						);

				} else {

					WP_RapidRescue_Chat_Debug::tool(
						'Executing AI tool',
						array(
							'tool' =>
								$tool_name,
						)
					);

					$current_context =
						$context;

					$current_context['customer_id'] =
						$verified_customer_id;

					$current_context['verified_ticket_keys'] =
						$verified_ticket_keys;

					$current_context['required_tool_pending'] =
						$required_tool_pending;

					$tool_result =
						WP_RapidRescue_Chat_Tool_Manager::execute(
							$tool_name,
							$arguments,
							$current_context
						);

					if ( is_wp_error( $tool_result ) ) {

						$tool_result =
							array(
								'success' =>
									false,

								'state' =>
									'tool_error',

								'next_action' =>
									'tool_error',

								'error' =>
									$tool_result->get_error_message(),
							);
					}
				}

				$tool_results[] =
					array(
						'tool' =>
							$tool_name,

						'result' =>
							$tool_result,
					);

				/*
				 * Only actual successful PHP execution consumes
				 * the Control Engine requirement.
				 */
				if (
					$required_tool_pending &&
					$tool_name === $required_tool &&
					is_array( $tool_result ) &&
					! empty(
						$tool_result[
							'required_tool_satisfied'
						]
					)
				) {

					$required_tool_pending = false;

					WP_RapidRescue_Chat_Debug::ai(
						'Gemini required tool successfully completed',
						array(
							'tool' =>
								$tool_name,
						)
					);
				}

				if ( is_array( $tool_result ) ) {

					if (
						isset(
							$tool_result['customer_id']
						)
					) {

						$returned_customer_id =
							absint(
								$tool_result['customer_id']
							);

						if (
							$returned_customer_id > 0
						) {

							$verified_customer_id =
								$returned_customer_id;
						}
					}

					if (
						isset(
							$tool_result['verified_ticket_key']
						)
					) {

						$key =
							sanitize_text_field(
								$tool_result[
									'verified_ticket_key'
								]
							);

						if ( '' !== $key ) {

							$verified_ticket_keys[] =
								$key;
						}
					}

					if (
						isset(
							$tool_result[
								'verified_ticket_keys'
							]
						) &&
						is_array(
							$tool_result[
								'verified_ticket_keys'
							]
						)
					) {

						foreach (
							$tool_result[
								'verified_ticket_keys'
							] as $key
						) {

							$key =
								sanitize_text_field(
									$key
								);

							if ( '' !== $key ) {

								$verified_ticket_keys[] =
									$key;
							}
						}
					}

					$verified_ticket_keys =
						array_values(
							array_unique(
								$verified_ticket_keys
							)
						);
				}

				$result_text =
					wp_json_encode(
						$tool_result,
						JSON_INVALID_UTF8_SUBSTITUTE
					);

				if ( false === $result_text ) {

					$result_text =
						'{"error":"Unable to encode tool result."}';
				}

				$function_results[] =
					array(
						'type' =>
							'function_result',

						'name' =>
							$tool_name,

						'call_id' =>
							$call_id,

						'result' =>
							array(
								array(
									'type' =>
										'text',

									'text' =>
										$result_text,
								),
							),
					);
			}

			if ( empty( $function_results ) ) {

				return new WP_Error(
					'gemini_empty_tool_result',
					'Gemini requested a tool but no valid tool result was produced.'
				);
			}

			/*
			 * Continue the Gemini interaction using the authoritative
			 * PHP tool result.
			 */
			$request_body = array(
				'model' =>
					$model,

				'input' =>
					$function_results,

				'previous_interaction_id' =>
					$interaction_id,

				'system_instruction' =>
					(string) $system_instruction,

				'tools' =>
					$required_tool_pending
						? $this->get_tools(
							array(
								$required_tool,
							)
						)
						: $this->get_tools(
							self::READ_ONLY_TOOLS
						),

				'store' =>
					true,
			);

			/*
			 * If the required action has not actually succeeded,
			 * keep forcing the exact same action.
			 */
			if ( $required_tool_pending ) {

				$request_body['generation_config'] =
					array(
						'tool_choice' =>
							array(
								'allowed_tools' =>
									array(
										'mode' =>
											'any',

										'tools' =>
											array(
												$required_tool,
											),
									),
							),
					);
			}
		}

		return new WP_Error(
			'gemini_tool_limit',
			'Gemini exceeded the maximum number of tool-calling rounds.'
		);
	}

	/**
	 * Get normalized Gemini tools.
	 *
	 * When $allowed_tools is supplied, only those tools are returned.
	 *
	 * @param array|null $allowed_tools Allowed tool names.
	 * @return array
	 */
	public function get_tools(
		$allowed_tools = null
	) {

		$registered_tools =
			WP_RapidRescue_Chat_Tool_Manager::get_tools();

		$tools = array();

		if ( ! is_array( $registered_tools ) ) {
			return $tools;
		}

		$allowed_lookup = null;

		if ( is_array( $allowed_tools ) ) {

			$allowed_lookup = array();

			foreach ( $allowed_tools as $allowed_tool ) {

				$allowed_tool =
					sanitize_key(
						$allowed_tool
					);

				if ( '' !== $allowed_tool ) {

					$allowed_lookup[
						$allowed_tool
					] = true;
				}
			}
		}

		foreach ( $registered_tools as $tool ) {

			if ( ! is_array( $tool ) ) {
				continue;
			}

			$name =
				isset( $tool['name'] )
					? sanitize_key(
						$tool['name']
					)
					: '';

			if ( '' === $name ) {
				continue;
			}

			/*
			 * Apply the PHP-selected allow-list.
			 */
			if (
				is_array( $allowed_lookup ) &&
				! isset(
					$allowed_lookup[ $name ]
				)
			) {
				continue;
			}

			$parameters =
				isset( $tool['parameters'] )
					? $tool['parameters']
					: array();

			if (
				! is_array( $parameters ) ||
				empty( $parameters )
			) {

				$parameters =
					array(
						'type' =>
							'object',
					);
			}

			if (
				! isset(
					$parameters['type']
				)
			) {

				$parameters['type'] =
					'object';
			}

			if (
				isset( $parameters['properties'] ) &&
				is_array( $parameters['properties'] ) &&
				empty( $parameters['properties'] )
			) {

				unset(
					$parameters['properties']
				);
			}

			if (
				isset( $parameters['required'] ) &&
				(
					! is_array( $parameters['required'] ) ||
					empty( $parameters['required'] )
				)
			) {

				unset(
					$parameters['required']
				);
			}

			$tools[] =
				array(
					'type' =>
						'function',

					'name' =>
						$name,

					'description' =>
						isset( $tool['description'] )
							? sanitize_text_field(
								$tool['description']
							)
							: '',

					'parameters' =>
						$parameters,
				);
		}

		return $tools;
	}

	/**
	 * Send Gemini request.
	 *
	 * @param string $api_key API key.
	 * @param array  $request_body Request body.
	 * @param string $model Model.
	 * @return array|WP_Error
	 */
	private function request(
		$api_key,
		$request_body,
		$model
	) {

		$json_body =
			wp_json_encode(
				$request_body,
				JSON_INVALID_UTF8_SUBSTITUTE
			);

		if ( false === $json_body ) {

			return new WP_Error(
				'gemini_json_encode_failed',
				'Gemini request could not be encoded as JSON.'
			);
		}

		json_decode(
			$json_body,
			true
		);

		if (
			JSON_ERROR_NONE !==
			json_last_error()
		) {

			return new WP_Error(
				'gemini_invalid_local_json',
				'Gemini request JSON is invalid.'
			);
		}

		$response =
			wp_remote_post(
				self::API_ENDPOINT,
				array(
					'timeout' =>
						60,

					'headers' =>
						array(
							'x-goog-api-key' =>
								$api_key,

							'Content-Type' =>
								'application/json',
						),

					'body' =>
						$json_body,
				)
			);

		if ( is_wp_error( $response ) ) {

			return new WP_Error(
				'gemini_request_failed',
				$response->get_error_message()
			);
		}

		$status_code =
			wp_remote_retrieve_response_code(
				$response
			);

		$response_body =
			wp_remote_retrieve_body(
				$response
			);

		WP_RapidRescue_Chat_Debug::ai(
			'Gemini HTTP response received',
			array(
				'status_code' =>
					$status_code,

				'response_length' =>
					strlen(
						$response_body
					),

				'response_body' =>
					$response_body,
			)
		);

		$data =
			json_decode(
				$response_body,
				true
			);

		if (
			$status_code < 200 ||
			$status_code >= 300
		) {

			$error_message =
				'The Gemini API request failed.';

			if (
				is_array( $data ) &&
				isset(
					$data['error']['message']
				)
			) {

				$error_message =
					sanitize_text_field(
						$data['error']['message']
					);
			}

			return new WP_Error(
				'gemini_api_error',
				$error_message
			);
		}

		if ( ! is_array( $data ) ) {

			return new WP_Error(
				'invalid_gemini_response',
				'Gemini returned an invalid response.'
			);
		}

		$interaction_id =
			isset( $data['id'] )
				? sanitize_text_field(
					$data['id']
				)
				: '';

		$text       = '';
		$tool_calls = array();

		if (
			isset( $data['steps'] ) &&
			is_array( $data['steps'] )
		) {

			foreach (
				$data['steps'] as $step
			) {

				if ( ! is_array( $step ) ) {
					continue;
				}

				$type =
					isset( $step['type'] )
						? sanitize_key(
							$step['type']
						)
						: '';

				if (
					'function_call' ===
					$type
				) {

					$name =
						isset( $step['name'] )
							? sanitize_key(
								$step['name']
							)
							: '';

					$id =
						isset( $step['id'] )
							? sanitize_text_field(
								$step['id']
							)
							: '';

					if (
						'' !== $name &&
						'' !== $id
					) {

						$tool_calls[] =
							array(
								'name' =>
									$name,

								'id' =>
									$id,

								'arguments' =>
									isset(
										$step['arguments']
									)
										? $step['arguments']
										: array(),
							);
					}

					continue;
				}

				if (
					'model_output' ===
					$type &&
					isset( $step['content'] ) &&
					is_array( $step['content'] )
				) {

					foreach (
						$step['content'] as $content
					) {

						if (
							! is_array( $content )
						) {
							continue;
						}

						if (
							isset( $content['type'] ) &&
							'text' ===
								sanitize_key(
									$content['type']
								) &&
							isset( $content['text'] )
						) {

							$text .=
								(string)
								$content['text'];
						}
					}
				}
			}
		}

		return array(
			'text' =>
				trim( $text ),

			'tool_calls' =>
				$tool_calls,

			'interaction_id' =>
				$interaction_id,

			'provider' =>
				$this->get_id(),

			'model' =>
				$model,
		);
	}
}