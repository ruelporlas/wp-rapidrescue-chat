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

	public function get_id() {
		return 'gemini';
	}

	public function get_name() {
		return 'Gemini';
	}

	/**
	 * Basic provider response.
	 *
	 * @param string $message User message.
	 * @return array|WP_Error
	 */
	public function respond( $message ) {

		$result = $this->respond_with_tools(
			$message,
			array(),
			array()
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $result;
	}

	/**
	 * Respond using Gemini function calling.
	 *
	 * @param string $message User message.
	 * @param array  $context Runtime context.
	 * @param array  $history Conversation history.
	 * @return array|WP_Error
	 */
	public function respond_with_tools( $message, $context = array(), $history = array() ) {

		$api_key = WP_RapidRescue_Chat_Settings::get(
			'gemini_api_key',
			''
		);

		$model = WP_RapidRescue_Chat_Settings::get(
			'gemini_model',
			'gemini-3.8-flash'
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

		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$tools = $this->get_tools();

		$system_instruction = WP_RapidRescue_Chat_AI::get_system_instructions();

		$previous_interaction_id = '';

		if ( isset( $context['previous_interaction_id'] ) ) {
			$previous_interaction_id = sanitize_text_field(
				$context['previous_interaction_id']
			);
		}

		/*
		 * Build the initial input.
		 *
		 * Gemini accepts a plain string for the initial interaction.
		 * When we have conversation history, send the history as an array
		 * so that the provider can preserve the conversation context.
		 */
		$input = $message;

		if ( ! empty( $history ) ) {
			$input_items = array();

			foreach ( $history as $history_item ) {

				if ( ! is_array( $history_item ) ) {
					continue;
				}

				$role = isset( $history_item['role'] )
					? sanitize_key( $history_item['role'] )
					: '';

				$content = isset( $history_item['content'] )
					? $history_item['content']
					: '';

				if ( ! is_string( $content ) ) {
					$content = wp_json_encode(
						$content,
						JSON_INVALID_UTF8_SUBSTITUTE
					);
				}

				if ( '' === $role || '' === $content ) {
					continue;
				}

				$input_items[] = array(
					'type' => 'message',
					'role' => $role,
					'content' => $content,
				);
			}

			$input_items[] = array(
				'type'    => 'message',
				'role'    => 'user',
				'content' => $message,
			);

			$input = $input_items;
		}

		$body = array(
			'model' => $model,
			'input' => $input,
			'system_instruction' => $system_instruction,
			'store' => false,
			'tools' => $tools,
		);

		if ( '' !== $previous_interaction_id ) {
			$body['previous_interaction_id'] = $previous_interaction_id;
		}

		WP_RapidRescue_Chat_Debug::ai(
			'Gemini request prepared',
			array(
				'model'               => $model,
				'input_type'          => gettype( $input ),
				'tool_count'          => count( $tools ),
				'body_length'         => strlen(
					wp_json_encode(
						$body,
						JSON_INVALID_UTF8_SUBSTITUTE
					)
				),
				'previous_interaction' => '' !== $previous_interaction_id,
			)
		);

		$verified_customer_id = 0;

		if ( isset( $context['customer_id'] ) ) {
			$verified_customer_id = absint( $context['customer_id'] );
		}

		$verified_ticket_keys = array();

		if ( isset( $context['verified_ticket_keys'] ) && is_array( $context['verified_ticket_keys'] ) ) {
			$verified_ticket_keys = array_values(
				array_filter(
					array_map(
						'sanitize_text_field',
						$context['verified_ticket_keys']
					)
				)
			);
		}

		$interaction_id = $previous_interaction_id;

		for ( $round = 0; $round < self::MAX_TOOL_ROUNDS; $round++ ) {

			$current_context = $context;

			$current_context['customer_id'] = $verified_customer_id;
			$current_context['verified_ticket_keys'] = $verified_ticket_keys;

			$result = $this->request(
				$api_key,
				$body,
				$model
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( isset( $result['interaction_id'] ) && '' !== $result['interaction_id'] ) {
				$interaction_id = $result['interaction_id'];
			}

			if ( ! empty( $result['tool_calls'] ) ) {

				$tool_results = array();

				foreach ( $result['tool_calls'] as $tool_call ) {

					if ( ! is_array( $tool_call ) ) {
						continue;
					}

					$tool_name = isset( $tool_call['name'] )
						? sanitize_key( $tool_call['name'] )
						: '';

					$arguments = isset( $tool_call['arguments'] )
						? $tool_call['arguments']
						: array();

					if ( ! is_array( $arguments ) ) {
						$decoded_arguments = json_decode(
							$arguments,
							true
						);

						$arguments = is_array( $decoded_arguments )
							? $decoded_arguments
							: array();
					}

					if ( '' === $tool_name ) {
						continue;
					}

					WP_RapidRescue_Chat_Debug::tool(
						'Executing AI tool',
						array(
							'tool' => $tool_name,
						)
					);

					$tool_result = WP_RapidRescue_Chat_Tool_Manager::execute(
						$tool_name,
						$arguments,
						$current_context
					);

					if ( is_wp_error( $tool_result ) ) {
						$tool_result = array(
							'error' => $tool_result->get_error_message(),
						);
					}

					/*
					 * Preserve security state returned by the PHP tool layer.
					 */
					if ( is_array( $tool_result ) ) {

						if ( isset( $tool_result['customer_id'] ) ) {
							$returned_customer_id = absint(
								$tool_result['customer_id']
							);

							if ( $returned_customer_id > 0 ) {
								$verified_customer_id = $returned_customer_id;
							}
						}

						if ( isset( $tool_result['verified_ticket_key'] ) ) {
							$returned_ticket_key = sanitize_text_field(
								$tool_result['verified_ticket_key']
							);

							if ( '' !== $returned_ticket_key ) {
								$verified_ticket_keys[] = $returned_ticket_key;
							}
						}

						if ( isset( $tool_result['verified_ticket_keys'] ) && is_array( $tool_result['verified_ticket_keys'] ) ) {
							foreach ( $tool_result['verified_ticket_keys'] as $verified_key ) {

								$verified_key = sanitize_text_field(
									$verified_key
								);

								if ( '' !== $verified_key ) {
									$verified_ticket_keys[] = $verified_key;
								}
							}
						}

						$verified_ticket_keys = array_values(
							array_unique( $verified_ticket_keys )
						);
					}

					$tool_results[] = array(
						'type' => 'function_result',
						'name' => $tool_name,
						'result' => $tool_result,
					);
				}

				if ( empty( $tool_results ) ) {
					return new WP_Error(
						'gemini_empty_tool_result',
						'Gemini requested a tool but no valid tool result was produced.'
					);
				}

				/*
				 * For a stateless Interactions request, send the tool results
				 * as the next input step while preserving the interaction ID.
				 */
				$body = array(
					'model' => $model,
					'input' => $tool_results,
					'system_instruction' => $system_instruction,
					'store' => false,
					'tools' => $tools,
				);

				if ( '' !== $interaction_id ) {
					$body['previous_interaction_id'] = $interaction_id;
				}

				$context['customer_id'] = $verified_customer_id;
				$context['verified_ticket_keys'] = array_values(
					array_unique( $verified_ticket_keys )
				);

				continue;
			}

			$text = isset( $result['text'] )
				? trim( $result['text'] )
				: '';

			if ( '' === $text ) {
				return new WP_Error(
					'empty_gemini_response',
					'Gemini returned no text response.'
				);
			}

			return array(
				'text'       => $text,
				'provider'   => $this->get_id(),
				'model'      => $model,
				'response_id' => $interaction_id,
				'customer_id' => $verified_customer_id,
				'verified_ticket_keys' => array_values(
					array_unique( $verified_ticket_keys )
				),
			);
		}

		return new WP_Error(
			'gemini_tool_limit',
			'Gemini exceeded the maximum number of tool-calling rounds.'
		);
	}

	/**
	 * Return normalized Gemini tools.
	 *
	 * @return array
	 */
	private function get_tools() {

		$registered_tools = WP_RapidRescue_Chat_Tool_Manager::get_tools();

		$tools = array();

		if ( ! is_array( $registered_tools ) ) {
			return $tools;
		}

		foreach ( $registered_tools as $tool ) {

			if ( ! is_array( $tool ) ) {
				continue;
			}

			$name = isset( $tool['name'] )
				? sanitize_key( $tool['name'] )
				: '';

			$description = isset( $tool['description'] )
				? sanitize_text_field( $tool['description'] )
				: '';

			if ( '' === $name ) {
				continue;
			}

			$parameters = array();

			if ( isset( $tool['parameters'] ) && is_array( $tool['parameters'] ) ) {
				$parameters = $tool['parameters'];
			}

			if ( empty( $parameters ) ) {
				$parameters = array(
					'type' => 'object',
					'properties' => array(),
				);
			}

			/*
			 * Gemini's function schema does not need an empty "required"
			 * array. Some API versions are stricter about this than others.
			 *
			 * Only send "required" when at least one required property exists.
			 */
			if ( isset( $parameters['required'] ) ) {

				if ( ! is_array( $parameters['required'] ) || empty( $parameters['required'] ) ) {
					unset( $parameters['required'] );
				}
			}

			/*
			 * Make sure properties exists for object parameters.
			 */
			if (
				isset( $parameters['type'] ) &&
				'object' === $parameters['type'] &&
				! isset( $parameters['properties'] )
			) {
				$parameters['properties'] = array();
			}

			$tools[] = array(
				'type'        => 'function',
				'name'        => $name,
				'description' => $description,
				'parameters'  => $parameters,
			);
		}

		return $tools;
	}

	/**
	 * Send request to Gemini.
	 *
	 * @param string $api_key API key.
	 * @param array  $request_body Request body.
	 * @param string $model Model name.
	 * @return array|WP_Error
	 */
	private function request( $api_key, $request_body, $model ) {

		$json_body = wp_json_encode(
			$request_body,
			JSON_INVALID_UTF8_SUBSTITUTE
		);

		if ( false === $json_body ) {
			$error_message = function_exists( 'json_last_error_msg' )
				? json_last_error_msg()
				: 'Unknown JSON encoding error.';

			WP_RapidRescue_Chat_Debug::ai(
				'Gemini JSON encoding failed',
				array(
					'error' => $error_message,
				)
			);

			return new WP_Error(
				'gemini_json_encode_failed',
				'Gemini request could not be encoded as JSON: ' . $error_message
			);
		}

		/*
		 * Verify the JSON locally before sending it.
		 */
		json_decode( $json_body, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {

			$error_message = function_exists( 'json_last_error_msg' )
				? json_last_error_msg()
				: 'Unknown JSON error.';

			WP_RapidRescue_Chat_Debug::ai(
				'Gemini local JSON validation failed',
				array(
					'error' => $error_message,
				)
			);

			return new WP_Error(
				'gemini_invalid_local_json',
				'Gemini request JSON is invalid: ' . $error_message
			);
		}

		$response = wp_remote_post(
			self::API_ENDPOINT,
			array(
				'timeout' => 60,
				'headers' => array(
					'x-goog-api-key' => $api_key,
					'Content-Type'  => 'application/json',
				),
				'body' => $json_body,
			)
		);

		if ( is_wp_error( $response ) ) {

			WP_RapidRescue_Chat_Debug::ai(
				'Gemini HTTP request failed',
				array(
					'error' => $response->get_error_message(),
				)
			);

			return new WP_Error(
				'gemini_request_failed',
				$response->get_error_message()
			);
		}

		$status_code = wp_remote_retrieve_response_code(
			$response
		);

		$body = wp_remote_retrieve_body(
			$response
		);

		WP_RapidRescue_Chat_Debug::ai(
			'Gemini HTTP response received',
			array(
				'status_code'    => $status_code,
				'response_length' => strlen( $body ),
				'response_body'  => $body,
			)
		);

		$data = json_decode(
			$body,
			true
		);

		if ( $status_code < 200 || $status_code >= 300 ) {

			$error_message = 'The Gemini API request failed.';

			if (
				is_array( $data ) &&
				isset( $data['error']['message'] )
			) {
				$error_message = sanitize_text_field(
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

		$interaction_id = '';

		if ( isset( $data['id'] ) ) {
			$interaction_id = sanitize_text_field(
				$data['id']
			);
		}

		$text = '';

		if (
			isset( $data['outputs'] ) &&
			is_array( $data['outputs'] )
		) {

			foreach ( $data['outputs'] as $output ) {

				if ( ! is_array( $output ) ) {
					continue;
				}

				if (
					isset( $output['type'] ) &&
					'text' === $output['type'] &&
					isset( $output['text'] )
				) {
					$text .= (string) $output['text'];
				}
			}
		}

		/*
		 * Handle function calls returned by the Interactions API.
		 */
		$tool_calls = array();

		if (
			isset( $data['outputs'] ) &&
			is_array( $data['outputs'] )
		) {

			foreach ( $data['outputs'] as $output ) {

				if ( ! is_array( $output ) ) {
					continue;
				}

				if (
					isset( $output['type'] ) &&
					'function_call' === $output['type']
				) {

					$name = isset( $output['name'] )
						? sanitize_key( $output['name'] )
						: '';

					$arguments = array();

					if ( isset( $output['arguments'] ) ) {

						if ( is_array( $output['arguments'] ) ) {
							$arguments = $output['arguments'];
						} elseif ( is_string( $output['arguments'] ) ) {

							$decoded = json_decode(
								$output['arguments'],
								true
							);

							if ( is_array( $decoded ) ) {
								$arguments = $decoded;
							}
						}
					}

					if ( '' !== $name ) {
						$tool_calls[] = array(
							'name'      => $name,
							'arguments' => $arguments,
						);
					}
				}
			}
		}

		return array(
			'text'           => trim( $text ),
			'tool_calls'     => $tool_calls,
			'interaction_id' => $interaction_id,
			'provider'       => $this->get_id(),
			'model'          => $model,
		);
	}
}