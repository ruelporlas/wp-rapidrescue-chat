<?php
/**
 * OpenAI provider.
 *
 * @package WP_RapidRescue_Chat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OpenAI provider.
 */
class WP_RapidRescue_Chat_AI_OpenAI extends WP_RapidRescue_Chat_AI_Provider {

	/**
	 * Responses API endpoint.
	 *
	 * @var string
	 */
	const API_ENDPOINT = 'https://api.openai.com/v1/responses';

	/**
	 * Maximum number of tool-calling rounds.
	 *
	 * @var int
	 */
	const MAX_TOOL_ROUNDS = 6;

	/**
	 * Get provider identifier.
	 *
	 * @return string
	 */
	public function get_id() {

		return 'openai';
	}

	/**
	 * Get provider name.
	 *
	 * @return string
	 */
	public function get_name() {

		return 'OpenAI';
	}

	/**
	 * Send a message to OpenAI.
	 *
	 * @param string $message User message.
	 * @return array|WP_Error
	 */
	public function respond( $message ) {

		return $this->respond_with_tools(
			$message,
			array()
		);
	}

	/**
	 * Respond using OpenAI native function calling.
	 *
	 * PHP remains the authority over all application state,
	 * permissions, customer identity, ticket verification,
	 * ticket creation, and ticket updates.
	 *
	 * @param string $message User message.
	 * @param array  $context Runtime context.
	 * @return array|WP_Error
	 */
	public function respond_with_tools(
		$message,
		$context = array()
	) {

		$api_key =
			WP_RapidRescue_Chat_Settings::get(
				'openai_api_key',
				''
			);

		$model =
			WP_RapidRescue_Chat_Settings::get(
				'openai_model',
				'gpt-6-luna'
			);

		if ( '' === $api_key ) {

			return new WP_Error(
				'missing_openai_api_key',
				'The OpenAI API key has not been configured.'
			);
		}

		$message =
			(string) $message;

		if ( '' === trim( $message ) ) {

			return new WP_Error(
				'empty_openai_message',
				'The OpenAI message cannot be empty.'
			);
		}

		if ( ! is_array( $context ) ) {
			$context = array();
		}

		$tools =
			$this->get_tools();

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
		 * The deterministic Control Engine may require one
		 * specific tool on the first provider request.
		 *
		 * The requirement is consumed once the tool executes.
		 * We deliberately stop forcing that tool on subsequent
		 * rounds so OpenAI can formulate the final response from
		 * the authoritative PHP tool result.
		 */
		$required_tool =
			WP_RapidRescue_Chat_Control_Engine::get_required_tool(
				$context
			);

		$force_required_tool =
			'' !== $required_tool;

		$request_body =
			array(
				'model' =>
					$model,

				'instructions' =>
					(string) $system_instruction,

				'input' =>
					array(
						array(
							'role'    => 'user',
							'content' => $message,
						),
					),

				'tools' =>
					$tools,

				'store' =>
					true,
			);

		/*
		 * Control Engine tool requirement.
		 *
		 * Responses API accepts a function-specific tool choice.
		 */
		if ( $force_required_tool ) {

			$request_body['tool_choice'] =
				array(
					'type' =>
						'function',

					'name' =>
						$required_tool,
				);

			WP_RapidRescue_Chat_Debug::ai(
				'Control Engine requiring OpenAI tool call',
				array(
					'tool' =>
						$required_tool,
				)
			);
		}

		WP_RapidRescue_Chat_Debug::ai(
			'OpenAI request prepared',
			array(
				'model' =>
					$model,

				'tool_count' =>
					count( $tools ),

				'required_tool' =>
					$required_tool,

				'endpoint' =>
					self::API_ENDPOINT,
			)
		);

		$previous_response_id =
			'';

		$tool_results =
			array();

		for (
			$round = 0;
			$round < self::MAX_TOOL_ROUNDS;
			$round++
		) {

			/*
			 * Once the required tool has executed, the next
			 * request must allow normal model behavior.
			 */
			if (
				$round > 0 &&
				! $force_required_tool
			) {

				unset(
					$request_body['tool_choice']
				);
			}

			$result =
				$this->request(
					$api_key,
					$request_body,
					$model
				);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$response_id =
				isset( $result['response_id'] )
					? sanitize_text_field(
						$result['response_id']
					)
					: '';

			if ( '' !== $response_id ) {
				$previous_response_id =
					$response_id;
			}

			/*
			 * No tool calls means OpenAI has produced the
			 * final customer-facing response.
			 */
			if ( empty( $result['tool_calls'] ) ) {

				$text =
					isset( $result['text'] )
						? trim(
							$result['text']
						)
						: '';

				if ( '' === $text ) {

					return new WP_Error(
						'empty_openai_response',
						'OpenAI returned no text response.'
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
						$previous_response_id,

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

			$function_outputs =
				array();

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
					isset( $tool_call['call_id'] )
						? sanitize_text_field(
							$tool_call['call_id']
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

				WP_RapidRescue_Chat_Debug::tool(
					'Executing OpenAI tool',
					array(
						'tool' =>
							$tool_name,
					)
				);

				/*
				 * Always give PHP's current security context to
				 * the Tool Manager.
				 */
				$current_context =
					$context;

				$current_context['customer_id'] =
					$verified_customer_id;

				$current_context['verified_ticket_keys'] =
					$verified_ticket_keys;

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

				$tool_results[] =
					array(
						'tool' =>
							$tool_name,

						'result' =>
							$tool_result,
					);

				/*
				 * The required Control Engine action has now
				 * been consumed.
				 */
				if (
					'' !== $required_tool &&
					$tool_name === $required_tool
				) {

					$force_required_tool =
						false;

					WP_RapidRescue_Chat_Debug::ai(
						'Required OpenAI tool executed',
						array(
							'tool' =>
								$tool_name,
						)
					);
				}

				/*
				 * Preserve security context returned by PHP.
				 */
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

				$result_json =
					wp_json_encode(
						$tool_result,
						JSON_INVALID_UTF8_SUBSTITUTE
					);

				if ( false === $result_json ) {

					$result_json =
						'{"error":"Unable to encode tool result."}';
				}

				/*
				 * Responses API function output item.
				 *
				 * The result is returned as a string containing
				 * the authoritative PHP result.
				 */
				$function_outputs[] =
					array(
						'type' =>
							'function_call_output',

						'call_id' =>
							$call_id,

						'output' =>
							$result_json,
					);
			}

			if ( empty( $function_outputs ) ) {

				return new WP_Error(
					'openai_empty_tool_result',
					'OpenAI requested a tool but no valid tool result was produced.'
				);
			}

			/*
			 * The next request supplies the actual PHP results
			 * back to the same Responses conversation.
			 *
			 * Do not repeat the original user message here.
			 */
			$request_body =
				array(
					'model' =>
						$model,

					'instructions' =>
						(string) $system_instruction,

					'input' =>
						$function_outputs,

					'previous_response_id' =>
						$previous_response_id,

					'tools' =>
						$tools,

					'store' =>
						true,
				);

			/*
			 * The required tool was already consumed.
			 * Do not force it on the follow-up request.
			 */
			$force_required_tool =
				false;
		}

		return new WP_Error(
			'openai_tool_limit',
			'OpenAI exceeded the maximum number of tool-calling rounds.'
		);
	}

	/**
	 * Get OpenAI function tools.
	 *
	 * @return array
	 */
	public function get_tools() {

		$registered_tools =
			WP_RapidRescue_Chat_Tool_Manager::get_tools();

		$tools =
			array();

		if ( ! is_array( $registered_tools ) ) {
			return $tools;
		}

		foreach (
			$registered_tools as $tool
		) {

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

			$description =
				isset( $tool['description'] )
					? sanitize_textarea_field(
						$tool['description']
					)
					: '';

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

			/*
			 * OpenAI expects an object schema for function
			 * parameters.
			 */
			$tools[] =
				array(
					'type' =>
						'function',

					'name' =>
						$name,

					'description' =>
						$description,

					'parameters' =>
						$parameters,

					'strict' =>
						false,
				);
		}

		return $tools;
	}

	/**
	 * Send an HTTP request to OpenAI.
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
				'openai_json_encode_failed',
				'OpenAI request could not be encoded as JSON.'
			);
		}

		/*
		 * Validate the generated JSON locally before sending it.
		 */
		json_decode(
			$json_body,
			true
		);

		if (
			JSON_ERROR_NONE !==
			json_last_error()
		) {

			return new WP_Error(
				'openai_invalid_local_json',
				'OpenAI request JSON is invalid.'
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
							'Authorization' =>
								'Bearer ' . $api_key,

							'Content-Type' =>
								'application/json',
						),

					'body' =>
						$json_body,
				)
			);

		if ( is_wp_error( $response ) ) {

			return new WP_Error(
				'openai_request_failed',
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
			'OpenAI HTTP response received',
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
				'The OpenAI API request failed.';

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
				'openai_api_error',
				$error_message
			);
		}

		if ( ! is_array( $data ) ) {

			return new WP_Error(
				'invalid_openai_response',
				'OpenAI returned an invalid response.'
			);
		}

		$response_id =
			isset( $data['id'] )
				? sanitize_text_field(
					$data['id']
				)
				: '';

		$text =
			isset( $data['output_text'] ) &&
			is_string( $data['output_text'] )
				? trim(
					$data['output_text']
				)
				: '';

		$tool_calls =
			array();

		/*
		 * Responses API returns function calls as output items
		 * with:
		 *
		 * type       = function_call
		 * name       = tool name
		 * call_id    = tool call identifier
		 * arguments  = JSON string
		 */
		if (
			isset( $data['output'] ) &&
			is_array( $data['output'] )
		) {

			foreach (
				$data['output'] as $output_item
			) {

				if (
					! is_array(
						$output_item
					)
				) {
					continue;
				}

				$type =
					isset(
						$output_item['type']
					)
						? sanitize_key(
							$output_item['type']
						)
						: '';

				if (
					'function_call' ===
					$type
				) {

					$name =
						isset(
							$output_item['name']
						)
							? sanitize_key(
								$output_item['name']
							)
							: '';

					$call_id =
						isset(
							$output_item['call_id']
						)
							? sanitize_text_field(
								$output_item['call_id']
							)
							: '';

					$arguments =
						isset(
							$output_item['arguments']
						)
							? $output_item['arguments']
							: array();

					if (
						'' !== $name &&
						'' !== $call_id
					) {

						$tool_calls[] =
							array(
								'name' =>
									$name,

								'call_id' =>
									$call_id,

								'arguments' =>
									$arguments,
							);
					}
				}
			}
		}

		return array(
			'text' =>
				$text,

			'tool_calls' =>
				$tool_calls,

			'response_id' =>
				$response_id,

			'provider' =>
				$this->get_id(),

			'model' =>
				$model,
		);
	}
}