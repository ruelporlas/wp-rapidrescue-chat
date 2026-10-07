<?php
/**
 * Plugin settings.
 *
 * @package WP_RapidRescue_Chat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles plugin settings.
 */
class WP_RapidRescue_Chat_Settings {

	/**
	 * Settings option name.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'wp_rapidrescue_chat_settings';

	/**
	 * Register settings.
	 *
	 * @return void
	 */
	public static function register() {

		register_setting(
			'wp_rapidrescue_chat_settings_group',
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => array(),
			)
		);

		add_settings_section(
			'wp_rapidrescue_chat_general',
			'General Settings',
			array( __CLASS__, 'render_general_section' ),
			'wp-rapidrescue-chat'
		);

		add_settings_field(
			'openai_api_key',
			'OpenAI API Key',
			array( __CLASS__, 'render_api_key_field' ),
			'wp-rapidrescue-chat',
			'wp_rapidrescue_chat_general'
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @param mixed $input Submitted settings.
	 * @return array
	 */
	public static function sanitize( $input ) {

		if ( ! is_array( $input ) ) {
			return array();
		}

		$settings = array();

		if ( isset( $input['openai_api_key'] ) ) {
			$settings['openai_api_key'] = sanitize_text_field(
				$input['openai_api_key']
			);
		}

		return $settings;
	}

	/**
	 * Render the general settings section.
	 *
	 * @return void
	 */
	public static function render_general_section() {
		echo '<p>Configure the basic settings for WP RapidRescue Chat.</p>';
	}

	/**
	 * Render the OpenAI API key field.
	 *
	 * @return void
	 */
	public static function render_api_key_field() {

		$settings = get_option( self::OPTION_NAME, array() );

		$api_key = isset( $settings['openai_api_key'] )
			? $settings['openai_api_key']
			: '';
		?>

		<input
			type="password"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[openai_api_key]"
			value="<?php echo esc_attr( $api_key ); ?>"
			class="regular-text"
			autocomplete="new-password"
		/>

		<p class="description">
			The API key is stored on the WordPress server and will not be exposed to the public chat interface.
		</p>

		<?php
	}

	/**
	 * Get a setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {

		$settings = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $settings ) || ! array_key_exists( $key, $settings ) ) {
			return $default;
		}

		return $settings[ $key ];
	}
}