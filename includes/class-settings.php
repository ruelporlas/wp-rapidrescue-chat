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
				'default'           => array(
					'ai_provider'   => 'openai',
					'openai_api_key' => '',
					'openai_model'   => 'gpt-6-luna',
					'gemini_api_key' => '',
					'gemini_model'   => 'gemini-3.8-flash',
				),
			)
		);

		add_settings_section(
			'wp_rapidrescue_chat_ai_section',
			'AI Provider Settings',
			array( __CLASS__, 'render_ai_section' ),
			'wp-rapidrescue-chat'
		);

		add_settings_field(
			'ai_provider',
			'AI Provider',
			array( __CLASS__, 'render_provider_field' ),
			'wp-rapidrescue-chat',
			'wp_rapidrescue_chat_ai_section'
		);

		add_settings_field(
			'openai_api_key',
			'OpenAI API Key',
			array( __CLASS__, 'render_openai_api_key_field' ),
			'wp-rapidrescue-chat',
			'wp_rapidrescue_chat_ai_section'
		);

		add_settings_field(
			'openai_model',
			'OpenAI Model',
			array( __CLASS__, 'render_openai_model_field' ),
			'wp-rapidrescue-chat',
			'wp_rapidrescue_chat_ai_section'
		);

		add_settings_field(
			'gemini_api_key',
			'Google Gemini API Key',
			array( __CLASS__, 'render_gemini_api_key_field' ),
			'wp-rapidrescue-chat',
			'wp_rapidrescue_chat_ai_section'
		);

		add_settings_field(
			'gemini_model',
			'Google Gemini Model',
			array( __CLASS__, 'render_gemini_model_field' ),
			'wp-rapidrescue-chat',
			'wp_rapidrescue_chat_ai_section'
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Raw settings.
	 * @return array
	 */
	public static function sanitize( $input ) {

		$input = is_array( $input ) ? $input : array();

		$existing = get_option(
			self::OPTION_NAME,
			array()
		);

		$sanitized = array();

		$sanitized['ai_provider'] = isset( $input['ai_provider'] )
			? sanitize_key( $input['ai_provider'] )
			: 'openai';

		$sanitized['openai_api_key'] = isset( $input['openai_api_key'] )
			? sanitize_text_field( $input['openai_api_key'] )
			: '';

		$sanitized['openai_model'] = isset( $input['openai_model'] )
			? sanitize_text_field( $input['openai_model'] )
			: 'gpt-6-luna';

		$sanitized['gemini_api_key'] = isset( $input['gemini_api_key'] )
			? sanitize_text_field( $input['gemini_api_key'] )
			: '';

		$sanitized['gemini_model'] = isset( $input['gemini_model'] )
			? sanitize_text_field( $input['gemini_model'] )
			: 'gemini-3.8-flash';

		/*
		 * Preserve an existing API key if the field is left blank.
		 */
		if (
			empty( $sanitized['openai_api_key'] ) &&
			! empty( $existing['openai_api_key'] )
		) {
			$sanitized['openai_api_key'] =
				$existing['openai_api_key'];
		}

		if (
			empty( $sanitized['gemini_api_key'] ) &&
			! empty( $existing['gemini_api_key'] )
		) {
			$sanitized['gemini_api_key'] =
				$existing['gemini_api_key'];
		}

		return $sanitized;
	}

	/**
	 * Render AI settings section.
	 *
	 * @return void
	 */
	public static function render_ai_section() {

		echo '<p>';
		echo esc_html(
			'Configure the AI provider used by WP RapidRescue Chat.'
		);
		echo '</p>';
	}

	/**
	 * Render provider field.
	 *
	 * @return void
	 */
	public static function render_provider_field() {

		$value = self::get(
			'ai_provider',
			'openai'
		);

		$providers = array(
			'openai' => 'OpenAI',
			'gemini' => 'Google Gemini',
		);

		?>
		<select
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[ai_provider]"
		>
			<?php foreach ( $providers as $key => $label ) : ?>
				<option
					value="<?php echo esc_attr( $key ); ?>"
					<?php selected( $value, $key ); ?>
				>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Render OpenAI API key field.
	 *
	 * @return void
	 */
	public static function render_openai_api_key_field() {

		$value = self::get(
			'openai_api_key',
			''
		);

		?>
		<div class="rr-provider-openai">
			<input
				type="password"
				class="regular-text"
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[openai_api_key]"
				value="<?php echo esc_attr( $value ); ?>"
				autocomplete="off"
			/>
		</div>
		<?php
	}

	/**
	 * Render OpenAI model field.
	 *
	 * @return void
	 */
	public static function render_openai_model_field() {

		$value = self::get(
			'openai_model',
			'gpt-6-luna'
		);

		?>
		<div class="rr-provider-openai">
			<input
				type="text"
				class="regular-text"
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[openai_model]"
				value="<?php echo esc_attr( $value ); ?>"
			/>
		</div>
		<?php
	}

	/**
	 * Render Gemini API key field.
	 *
	 * @return void
	 */
	public static function render_gemini_api_key_field() {

		$value = self::get(
			'gemini_api_key',
			''
		);

		?>
		<div class="rr-provider-gemini">
			<input
				type="password"
				class="regular-text"
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gemini_api_key]"
				value="<?php echo esc_attr( $value ); ?>"
				autocomplete="off"
			/>
		</div>
		<?php
	}

	/**
	 * Render Gemini model field.
	 *
	 * @return void
	 */
	public static function render_gemini_model_field() {

		$value = self::get(
			'gemini_model',
			'gemini-3.8-flash'
		);

		$models = self::get_gemini_models();

		?>
		<div class="rr-provider-gemini">
			<select
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gemini_model]"
			>
				<?php foreach ( $models as $model_id => $label ) : ?>
					<option
						value="<?php echo esc_attr( $model_id ); ?>"
						<?php selected( $value, $model_id ); ?>
					>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php
	}

	/**
	 * Get Gemini model list.
	 *
	 * @return array
	 */
	public static function get_gemini_models() {

		return array(
			'gemini-3.8-flash'       => 'Gemini 3.8 Flash',
			'gemini-3.7-flash'       => 'Gemini 3.7 Flash',
			'gemini-3.6-flash'       => 'Gemini 3.6 Flash',
			'gemini-3.5-flash'       => 'Gemini 3.5 Flash',
			'gemini-3.5-flash-lite'  => 'Gemini 3.5 Flash Lite',
			'gemini-3.1-flash-lite'  => 'Gemini 3.1 Flash Lite',
			'gemini-3.1-pro-preview' => 'Gemini 3.1 Pro Preview',
			'gemini-3-flash-preview' => 'Gemini 3 Flash Preview',
			'gemini-2.5-pro'         => 'Gemini 2.5 Pro',
			'gemini-2.5-flash'       => 'Gemini 2.5 Flash',
			'gemini-2.5-flash-lite'  => 'Gemini 2.5 Flash Lite',
		);
	}

	/**
	 * Get a setting value.
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
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public static function enqueue_admin_assets( $hook_suffix ) {

		if ( 'settings_page_wp-rapidrescue-chat' !== $hook_suffix ) {
			return;
		}

		$script_path = WP_RAPIDRESCUE_CHAT_PATH .
			'admin/assets/js/settings.js';

		$script_url = WP_RAPIDRESCUE_CHAT_URL .
			'admin/assets/js/settings.js';

		/*
		 * Use the file modification time during development so the
		 * browser always receives the latest JavaScript file.
		 */
		$script_version = file_exists( $script_path )
			? filemtime( $script_path )
			: WP_RAPIDRESCUE_CHAT_VERSION;

		wp_enqueue_script(
			'wp-rapidrescue-chat-settings',
			$script_url,
			array(),
			$script_version,
			true
		);

		wp_localize_script(
			'wp-rapidrescue-chat-settings',
			'wpRapidRescueChatSettings',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce(
					'wp_rapidrescue_test_ai_connection'
				),
			)
		);
	}
}