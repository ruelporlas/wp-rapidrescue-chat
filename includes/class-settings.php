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

	/**
	 * Register plugin settings.
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
				'class' => 'rr-provider-field rr-provider-openai',
			)
		);

		add_settings_field(
			'openai_model',
			'OpenAI Model',
			array( __CLASS__, 'render_openai_model_field' ),
			'wp-rapidrescue-chat',
			'wp_rapidrescue_chat_general',
			array(
				'class' => 'rr-provider-field rr-provider-openai',
			)
		);

		add_settings_field(
			'gemini_api_key',
			'Google Gemini API Key',
			array( __CLASS__, 'render_gemini_api_key_field' ),
			'wp-rapidrescue-chat',
			'wp_rapidrescue_chat_general',
			array(
				'class' => 'rr-provider-field rr-provider-gemini',
			)
		);

		add_settings_field(
			'gemini_model',
			'Google Gemini Model',
			array( __CLASS__, 'render_gemini_model_field' ),
			'wp-rapidrescue-chat',
			'wp_rapidrescue_chat_general',
			array(
				'class' => 'rr-provider-field rr-provider-gemini',
			)
		);
	}

	/**
	 * Sanitize plugin settings.
	 *
	 * @param mixed $input Submitted settings.
	 * @return array
	 */
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

		/*
		 * AI provider.
		 */
		if ( isset( $input['ai_provider'] ) ) {

			$provider = sanitize_key(
				$input['ai_provider']
			);

			if ( array_key_exists( $provider, self::get_providers() ) ) {
				$settings['ai_provider'] = $provider;
			}
		}

		/*
		 * OpenAI API key.
		 */
		if ( isset( $input['openai_api_key'] ) ) {
			$settings['openai_api_key'] = sanitize_text_field(
				$input['openai_api_key']
			);
		}

		/*
		 * OpenAI model.
		 */
		if ( isset( $input['openai_model'] ) ) {
			$settings['openai_model'] = sanitize_text_field(
				$input['openai_model']
			);
		}

		/*
		 * Gemini API key.
		 */
		if ( isset( $input['gemini_api_key'] ) ) {
			$settings['gemini_api_key'] = sanitize_text_field(
				$input['gemini_api_key']
			);
		}

		/*
		 * Gemini model.
		 */
		if ( isset( $input['gemini_model'] ) ) {

			$gemini_model = sanitize_text_field(
				$input['gemini_model']
			);

			if ( array_key_exists( $gemini_model, self::get_gemini_models() ) ) {
				$settings['gemini_model'] = $gemini_model;
			}
		}

		return $settings;
	}

	/**
	 * Render general settings section.
	 */
	public static function render_general_section() {
		?>
		<p>
			Select the AI provider that will power the WP RapidRescue support assistant.
		</p>
		<?php
	}

	/**
	 * Render AI provider field.
	 */
	public static function render_provider_field() {

		$current = self::get(
			'ai_provider',
			'openai'
		);
		?>

		<select
			id="wp-rapidrescue-ai-provider"
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
			Choose which AI service will handle customer conversations.
		</p>

		<?php
	}

	/**
	 * Render OpenAI API key field.
	 */
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
			Your OpenAI API key is stored on the WordPress server and is never sent to the public chat interface.
		</p>

		<?php
	}

	/**
	 * Render OpenAI model field.
	 *
	 * This remains a text field for now because we will handle
	 * provider-specific model availability separately.
	 */
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

	/**
	 * Render Gemini API key field.
	 */
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
			Your Google Gemini API key is stored on the WordPress server and is never sent to the public chat interface.
		</p>

		<?php
	}

	/**
	 * Render Gemini model selector.
	 */
	public static function render_gemini_model_field() {

		$current = self::get(
			'gemini_model',
			'gemini-3.8-flash'
		);
		?>

		<select
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gemini_model]"
			id="wp-rapidrescue-gemini-model"
		>

			<?php foreach ( self::get_gemini_models() as $model_id => $model_name ) : ?>

				<option
					value="<?php echo esc_attr( $model_id ); ?>"
					<?php selected( $current, $model_id ); ?>
				>
					<?php echo esc_html( $model_name ); ?>
				</option>

			<?php endforeach; ?>

		</select>

		<p class="description">
			Select the Google Gemini model used by the support assistant.
		</p>

		<?php
	}

	/**
	 * Get available AI providers.
	 *
	 * @return array
	 */
	private static function get_providers() {

		return array(
			'openai' => 'OpenAI',
			'gemini' => 'Google Gemini',
		);
	}

	/**
	 * Get available Gemini models.
	 *
	 * The IDs here are the values sent to Google's API.
	 *
	 * @return array
	 */
	private static function get_gemini_models() {

		return array(
			'gemini-3.8-flash' => 'Gemini 3.8 Flash',
			'gemini-3.7-flash' => 'Gemini 3.7 Flash',
			'gemini-3.6-flash' => 'Gemini 3.6 Flash',
			'gemini-3.5-flash' => 'Gemini 3.5 Flash',
			'gemini-3.5-flash-lite' => 'Gemini 3.5 Flash-Lite',
			'gemini-3.1-flash-lite' => 'Gemini 3.1 Flash-Lite',
			'gemini-3.1-pro-preview' => 'Gemini 3.1 Pro (Preview)',
			'gemini-3-flash-preview' => 'Gemini 3 Flash (Preview)',
			'gemini-2.5-pro' => 'Gemini 2.5 Pro',
			'gemini-2.5-flash' => 'Gemini 2.5 Flash',
			'gemini-2.5-flash-lite' => 'Gemini 2.5 Flash-Lite',
		);
	}

	/**
	 * Get a setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
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

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
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