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

	const OPTION_NAME = 'wp_rapidrescue_chat_settings';

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
			'AI Settings',
			array( __CLASS__, 'render_general_section' ),
			'wp-rapidrescue-chat'
		);

		add_settings_field(
			'ai_provider',
			'AI Provider',
			array( __CLASS__, 'render_provider_field' ),
			'wp-rapidrescue-chat',
			'wp_rapidrescue_chat_general'
		);

		add_settings_field(
			'openai_api_key',
			'OpenAI API Key',
			array( __CLASS__, 'render_openai_api_key_field' ),
			'wp-rapidrescue-chat',
			'wp_rapidrescue_chat_general',
			array(
				'class' => 'rr-provider-openai',
			)
		);

		add_settings_field(
			'openai_model',
			'OpenAI Model',
			array( __CLASS__, 'render_openai_model_field' ),
			'wp-rapidrescue-chat',
			'wp_rapidrescue_chat_general',
			array(
				'class' => 'rr-provider-openai',
			)
		);

		add_settings_field(
			'gemini_api_key',
			'Google Gemini API Key',
			array( __CLASS__, 'render_gemini_api_key_field' ),
			'wp-rapidrescue-chat',
			'wp_rapidrescue_chat_general',
			array(
				'class' => 'rr-provider-gemini',
			)
		);

		add_settings_field(
			'gemini_model',
			'Google Gemini Model',
			array( __CLASS__, 'render_gemini_model_field' ),
			'wp-rapidrescue-chat',
			'wp-rapidrescue_chat_general',
			array(
				'class' => 'rr-provider-gemini',
			)
		);
	}

	public static function sanitize( $input ) {

		if ( ! is_array( $input ) ) {
			return array();
		}

		$existing = get_option(
			self::OPTION_NAME,
			array()
		);

		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$settings = $existing;

		if ( isset( $input['ai_provider'] ) ) {

			$provider = sanitize_key(
				$input['ai_provider']
			);

			if ( array_key_exists( $provider, self::get_providers() ) ) {
				$settings['ai_provider'] = $provider;
			}
		}

		if ( isset( $input['openai_api_key'] ) ) {
			$settings['openai_api_key'] = sanitize_text_field(
				$input['openai_api_key']
			);
		}

		if ( isset( $input['openai_model'] ) ) {
			$settings['openai_model'] = sanitize_text_field(
				$input['openai_model']
			);
		}

		if ( isset( $input['gemini_api_key'] ) ) {
			$settings['gemini_api_key'] = sanitize_text_field(
				$input['gemini_api_key']
			);
		}

		if ( isset( $input['gemini_model'] ) ) {
			$settings['gemini_model'] = sanitize_text_field(
				$input['gemini_model']
			);
		}

		return $settings;
	}

	public static function render_general_section() {
		echo '<p>Choose the AI provider used by WP RapidRescue Chat.</p>';
	}

	public static function render_provider_field() {

		$current = self::get(
			'ai_provider',
			'openai'
		);
		?>

		<select
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[ai_provider]"
		>

			<?php foreach ( self::get_providers() as $id => $name ) : ?>

				<option
					value="<?php echo esc_attr( $id ); ?>"
					<?php selected( $current, $id ); ?>
				>
					<?php echo esc_html( $name ); ?>
				</option>

			<?php endforeach; ?>

		</select>

		<p class="description">
			Select the AI provider that will power the support assistant.
		</p>

		<?php
	}

	public static function render_openai_api_key_field() {

		$value = self::get(
			'openai_api_key',
			''
		);
		?>

		<input
			type="password"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[openai_api_key]"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
			autocomplete="new-password"
		/>

		<p class="description">
			Your OpenAI API key is stored on the WordPress server.
		</p>

		<?php
	}

	public static function render_openai_model_field() {

		$value = self::get(
			'openai_model',
			''
		);
		?>

		<input
			type="text"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[openai_model]"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
			autocomplete="off"
		/>

		<p class="description">
			Enter the OpenAI model ID you want to use.
		</p>

		<?php
	}

	public static function render_gemini_api_key_field() {

		$value = self::get(
			'gemini_api_key',
			''
		);
		?>

		<input
			type="password"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gemini_api_key]"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
			autocomplete="new-password"
		/>

		<p class="description">
			Your Google Gemini API key is stored on the WordPress server.
		</p>

		<?php
	}

	public static function render_gemini_model_field() {

		$value = self::get(
			'gemini_model',
			''
		);
		?>

		<input
			type="text"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gemini_model]"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
			autocomplete="off"
		/>

		<p class="description">
			Enter the Google Gemini model ID you want to use.
		</p>

		<?php
	}

	private static function get_providers() {

		return array(
			'openai' => 'OpenAI',
			'gemini' => 'Google Gemini',
		);
	}

	public static function get( $key, $default = null ) {

		$settings = get_option(
			self::OPTION_NAME,
			array()
		);

		if (
			! is_array( $settings ) ||
			! array_key_exists( $key, $settings )
		) {
			return $default;
		}

		return $settings[ $key ];
	}

	public static function enqueue_admin_assets( $hook ) {

		if ( 'settings_page_wp-rapidrescue-chat' !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'wp-rapidrescue-chat-settings',
			WP_RAPIDRESCUE_CHAT_URL . 'admin/assets/js/settings.js',
			array(),
			WP_RAPIDRESCUE_CHAT_VERSION,
			true
		);
	}
}