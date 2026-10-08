<?php
/**
 * Temporary diagnostic/debug tracing.
 *
 * @package WP_RapidRescue_Chat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Debug trace manager.
 *
 * This class is intentionally isolated so debugging can be disabled
 * without removing the diagnostic code from the plugin.
 */
class WP_RapidRescue_Chat_Debug {

	/**
	 * Master debug switch.
	 *
	 * Change to false when debugging is no longer required.
	 *
	 * @var bool
	 */
	const ENABLED = true;

	/**
	 * Current request trace.
	 *
	 * @var array
	 */
	private static $trace = array();

	/**
	 * Whether the trace has been initialized.
	 *
	 * @var bool
	 */
	private static $initialized = false;

	/**
	 * Initialize the trace.
	 *
	 * @return void
	 */
	public static function init() {

		if ( self::$initialized ) {
			return;
		}

		self::$initialized = true;
		self::$trace       = array();

		if ( ! self::enabled() ) {
			return;
		}

		self::add(
			'DEBUG',
			'Debug trace initialized.'
		);
	}

	/**
	 * Determine whether debugging is enabled.
	 *
	 * @return bool
	 */
	public static function enabled() {

		return self::ENABLED;
	}

	/**
	 * Add a trace entry.
	 *
	 * @param string $stage   Trace stage.
	 * @param string $message Trace message.
	 * @param array  $data    Optional structured data.
	 * @return void
	 */
	public static function add(
		$stage,
		$message,
		$data = array()
	) {

		if ( ! self::enabled() ) {
			return;
		}

		self::init();

		$entry = array(
			'time'    => current_time( 'H:i:s' ),
			'stage'   => sanitize_text_field( $stage ),
			'message' => sanitize_text_field( $message ),
		);

		if ( ! empty( $data ) && is_array( $data ) ) {

			$entry['data'] =
				self::sanitize_data(
					$data
				);
		}

		self::$trace[] = $entry;
	}

	/**
	 * Add a trace entry containing a tool result.
	 *
	 * Sensitive values are sanitized before display.
	 *
	 * @param string $tool_name Tool name.
	 * @param array  $result    Tool result.
	 * @return void
	 */
	public static function tool_result(
		$tool_name,
		$result
	) {

		if ( is_wp_error( $result ) ) {

			self::add(
				'TOOL',
				$tool_name . ' returned WP_Error.',
				array(
					'error' =>
						$result->get_error_message(),
				)
			);

			return;
		}

		if ( ! is_array( $result ) ) {

			self::add(
				'TOOL',
				$tool_name . ' returned a non-array result.'
			);

			return;
		}

		$data = array();

		foreach (
			array(
				'success',
				'state',
				'next_action',
				'found',
				'customer_id',
				'ticket_id',
				'ticket_key',
			) as $key
		) {

			if ( array_key_exists( $key, $result ) ) {

				$data[ $key ] =
					$result[ $key ];
			}
		}

		if (
			isset( $result['data']['ticket'] ) &&
			is_array( $result['data']['ticket'] )
		) {

			$ticket =
				$result['data']['ticket'];

			/*
			 * Only expose safe diagnostic fields.
			 */
			$data['ticket_returned'] =
				true;

			if (
				isset( $ticket['ticket_key'] )
			) {

				$data['ticket_key'] =
					sanitize_text_field(
						$ticket['ticket_key']
					);
			}

			if (
				isset( $ticket['status'] )
			) {

				$data['ticket_status'] =
					sanitize_key(
						$ticket['status']
					);
			}
		}

		self::add(
			'TOOL',
			'Tool result received: ' . $tool_name,
			$data
		);
	}

	/**
	 * Add a trace entry for tool arguments.
	 *
	 * @param string $tool_name Tool name.
	 * @param array  $arguments Arguments.
	 * @return void
	 */
	public static function tool_call(
		$tool_name,
		$arguments
	) {

		$safe = array();

		if ( is_array( $arguments ) ) {

			foreach (
				$arguments as $key => $value
			) {

				$key =
					sanitize_key(
						$key
					);

				if (
					'email' === $key ||
					'customer_email' === $key
				) {

					$safe[ $key ] =
						self::mask_email(
							$value
						);

					continue;
				}

				if (
					is_scalar( $value )
				) {

					$safe[ $key ] =
						sanitize_text_field(
							(string) $value
						);
				}
			}
		}

		self::add(
			'TOOL',
			'Calling tool: ' . $tool_name,
			$safe
		);
	}

	/**
	 * Add a provider trace.
	 *
	 * @param string $message Trace message.
	 * @param array  $data    Optional data.
	 * @return void
	 */
	public static function ai(
		$message,
		$data = array()
	) {

		self::add(
			'AI',
			$message,
			$data
		);
	}

	/**
	 * Add a REST trace.
	 *
	 * @param string $message Trace message.
	 * @param array  $data    Optional data.
	 * @return void
	 */
	public static function rest(
		$message,
		$data = array()
	) {

		self::add(
			'REST',
			$message,
			$data
		);
	}

	/**
	 * Add a Gemini trace.
	 *
	 * @param string $message Trace message.
	 * @param array  $data    Optional data.
	 * @return void
	 */
	public static function gemini(
		$message,
		$data = array()
	) {

		self::add(
			'GEMINI',
			$message,
			$data
		);
	}

	/**
	 * Get the current trace.
	 *
	 * @return array
	 */
	public static function get_trace() {

		if ( ! self::enabled() ) {
			return array();
		}

		self::init();

		return self::$trace;
	}

	/**
	 * Sanitize structured diagnostic data.
	 *
	 * @param mixed $data Data.
	 * @return mixed
	 */
	private static function sanitize_data(
		$data
	) {

		if ( is_array( $data ) ) {

			$safe = array();

			foreach (
				$data as $key => $value
			) {

				$key =
					sanitize_key(
						$key
					);

				if (
					'email' === $key ||
					'customer_email' === $key
				) {

					$safe[ $key ] =
						self::mask_email(
							$value
						);

					continue;
				}

				/*
				 * Never expose credentials or authorization
				 * headers in the browser.
				 */
				if (
					false !==
					strpos(
						$key,
						'key'
					) ||
					false !==
					strpos(
						$key,
						'secret'
					) ||
					false !==
					strpos(
						$key,
						'token'
					) ||
					false !==
					strpos(
						$key,
						'authorization'
					)
				) {

					$safe[ $key ] = '[hidden]';

					continue;
				}

				$safe[ $key ] =
					self::sanitize_data(
						$value
					);
			}

			return $safe;
		}

		if ( is_scalar( $data ) ) {

			return sanitize_text_field(
				(string) $data
			);
		}

		return '[data]';
	}

	/**
	 * Mask an email address.
	 *
	 * @param string $email Email address.
	 * @return string
	 */
	private static function mask_email(
		$email
	) {

		$email =
			sanitize_email(
				(string) $email
			);

		if ( '' === $email ) {
			return '[invalid email]';
		}

		$parts =
			explode(
				'@',
				$email,
				2
			);

		if ( count( $parts ) !== 2 ) {
			return '[email]';
		}

		$local =
			$parts[0];

		$domain =
			$parts[1];

		$first =
			'' !== $local
				? substr( $local, 0, 1 )
				: '';

		return $first .
			'***@' .
			$domain;
	}
}