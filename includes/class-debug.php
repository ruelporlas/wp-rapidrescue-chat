<?php
/**
 * Temporary debug logger.
 *
 * @package WP_RapidRescue_Chat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Temporary debugging for the chat request flow.
 *
 * Set ENABLED to false when debugging is no longer needed.
 */
class WP_RapidRescue_Chat_Debug {

	/**
	 * Master debug switch.
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
	 * Initialize the debug logger.
	 *
	 * This method exists for compatibility with the plugin bootstrap.
	 *
	 * @return void
	 */
	public static function init() {
		self::$trace = array();
	}

	/**
	 * Add a trace entry.
	 *
	 * @param string $stage   Trace stage.
	 * @param string $message Trace message.
	 * @param array  $data    Optional data.
	 * @return void
	 */
	public static function add(
		$stage,
		$message,
		$data = array()
	) {

		if ( ! self::ENABLED ) {
			return;
		}

		$entry = array(
			'stage'   => strtoupper( sanitize_key( $stage ) ),
			'message' => sanitize_text_field( $message ),
		);

		if ( ! empty( $data ) ) {
			$entry['data'] = self::sanitize_data( $data );
		}

		self::$trace[] = $entry;

		/*
		 * Prevent an accidental runaway trace.
		 */
		if ( count( self::$trace ) > 100 ) {
			self::$trace = array_slice( self::$trace, -100 );
		}
	}

	/**
	 * Add REST trace entry.
	 *
	 * @param string $message Message.
	 * @param array  $data    Optional data.
	 * @return void
	 */
	public static function rest( $message, $data = array() ) {
		self::add( 'REST', $message, $data );
	}

	/**
	 * Add ticket trace entry.
	 *
	 * @param string $message Message.
	 * @param array  $data    Optional data.
	 * @return void
	 */
	public static function ticket( $message, $data = array() ) {
		self::add( 'TICKET', $message, $data );
	}

	/**
	 * Add tool trace entry.
	 *
	 * @param string $message Message.
	 * @param array  $data    Optional data.
	 * @return void
	 */
	public static function tool( $message, $data = array() ) {
		self::add( 'TOOL', $message, $data );
	}

	/**
	 * Add AI trace entry.
	 *
	 * @param string $message Message.
	 * @param array  $data    Optional data.
	 * @return void
	 */
	public static function ai( $message, $data = array() ) {
		self::add( 'AI', $message, $data );
	}

	/**
	 * Check whether debugging is enabled.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return self::ENABLED;
	}

	/**
	 * Get the current request trace.
	 *
	 * @return array
	 */
	public static function get_trace() {

		if ( ! self::ENABLED ) {
			return array();
		}

		return self::$trace;
	}

	/**
	 * Get the current request trace as readable text.
	 *
	 * @return string
	 */
	public static function get_trace_text() {

		if ( ! self::ENABLED || empty( self::$trace ) ) {
			return '';
		}

		$lines   = array();
		$lines[] = 'DEBUG TRACE';
		$lines[] = '────────────────────────────';

		foreach ( self::$trace as $entry ) {

			$stage   = isset( $entry['stage'] ) ? $entry['stage'] : 'DEBUG';
			$message = isset( $entry['message'] ) ? $entry['message'] : '';

			$line = '[' . $stage . '] ' . $message;

			if (
				isset( $entry['data'] ) &&
				is_array( $entry['data'] ) &&
				! empty( $entry['data'] )
			) {

				foreach ( $entry['data'] as $key => $value ) {

					if ( is_array( $value ) ) {
						$value = wp_json_encode( $value );
					} elseif ( is_bool( $value ) ) {
						$value = $value ? 'true' : 'false';
					} elseif ( null === $value ) {
						$value = 'null';
					} else {
						$value = (string) $value;
					}

					$line .= ' | ' . sanitize_key( $key ) . ': ' . $value;
				}
			}

			$lines[] = $line;
		}

		$lines[] = '────────────────────────────';

		return implode( "\n", $lines );
	}

	/**
	 * Sanitize debug data.
	 *
	 * Sensitive values such as API keys, tokens, secrets,
	 * authorization headers and email addresses are masked.
	 *
	 * @param mixed $data Data to sanitize.
	 * @return mixed
	 */
	private static function sanitize_data( $data ) {

		if ( is_array( $data ) ) {

			$output = array();

			foreach ( $data as $key => $value ) {

				$safe_key = sanitize_key( $key );

				if (
					false !== strpos( $safe_key, 'key' ) ||
					false !== strpos( $safe_key, 'secret' ) ||
					false !== strpos( $safe_key, 'token' ) ||
					false !== strpos( $safe_key, 'authorization' )
				) {
					$output[ $safe_key ] = '[REDACTED]';
					continue;
				}

				$output[ $safe_key ] = self::sanitize_data( $value );
			}

			return $output;
		}

		if ( is_object( $data ) ) {
			return '[OBJECT]';
		}

		if ( is_bool( $data ) || null === $data ) {
			return $data;
		}

		$value = (string) $data;

		$value = preg_replace_callback(
			'/([a-zA-Z0-9._%+\-])[a-zA-Z0-9._%+\-]*@([a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})/',
			function ( $matches ) {
				return $matches[1] . '***@' . $matches[2];
			},
			$value
		);

		if ( strlen( $value ) > 500 ) {
			$value = substr( $value, 0, 500 ) . '...';
		}

		return sanitize_text_field( $value );
	}
}
 
