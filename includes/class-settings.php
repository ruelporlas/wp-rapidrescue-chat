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
	 * Get the default settings.
	 *
	 * These defaults are intentionally business-agnostic.
	 *
	 * @return array
	 */
	public static function get_defaults() {

		return array(
			'ai_provider'            => 'openai',
			'openai_api_key'         => '',
			'openai_model'           => 'gpt-6-luna',
			'gemini_api_key'         => '',
			'gemini_model'           => 'gemini-3.8-flash',

			'ai_assistant_role'      => 'AI customer assistant',

			'ai_primary_goal'        => 'Understand the customer\'s needs, provide helpful and accurate information using the approved business knowledge, and guide the customer toward the appropriate next step.',

			'ai_conversation_style'  => 'Friendly, professional, natural, and easy to understand. Keep responses focused and concise while providing enough detail to be useful.',

			'ai_behavior'            => 'Understand the customer\'s request before responding. Answer the customer\'s question directly. Ask relevant follow-up questions when important information is missing. Use conversation history to maintain context and avoid asking for information the customer has already provided.',

			'ai_avoid'               => 'Do not pressure the customer, make unnecessary recommendations, repeat questions, make unsupported assumptions, or provide information that has not been confirmed.',

			'ai_escalation'          => 'When the customer\'s request requires human assistance or cannot be confidently handled using the available information, explain that human assistance may be needed and guide the customer through the next appropriate step. Never claim that an escalation or other action has been completed unless the application has confirmed it.',
		);
	}

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
				'default'           => self::get_defaults(),
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
			'wp-rapidrescue_chat_ai_section'
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

		add_settings_section(
			'wp_rapidrescue_chat_skill_section',
			'AI Assistant Skill',
			array( __CLASS__, 'render_skill_section' ),
			'wp-rapidrescue-chat'
		);

		add_settings_field(
			'ai_assistant_role',
			'Assistant Role',
			array( __CLASS__, 'render_assistant_role_field' ),
			'wp-rapidrescue-chat',
			'wp_rapidrescue_chat_skill_section'
		);

		add_settings_field(
			'ai_primary_goal',
			'Primary Goal',
			array( __CLASS__, 'render_primary_goal_field' ),
			'wp-rapidrescue-chat',
			'wp_rapidrescue_chat_skill_section'
		);

		add_settings_field(
			'ai_conversation_style',
			'Conversation Style',
			array( __CLASS__, 'render_conversation_style_field' ),
			'wp-rapidrescue-chat',
			'wp_rapidrescue_chat_skill_section'
		);

		add_settings_field(
			'ai_behavior',
			'Behavior Instructions',
			array( __CLASS__, 'render_behavior_field' ),
			'wp-rapidrescue-chat',
			'wp-rapidrescue_chat_skill_section'
		);

		add_settings_field(
			'ai_avoid',
			'Things to Avoid',
			array( __CLASS__, 'render_avoid_field' ),
			'wp-rapidrescue-chat',
			'wp_rapidrescue_chat_skill_section'
		);

		add_settings_field(
			'ai_escalation',
			'Escalation Guidance',
			array( __CLASS__, 'render_escalation_field' ),
			'wp-rapidrescue-chat',
			'wp_rapidrescue_chat_skill_section'
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

		$defaults = self::get_defaults();

		$sanitized = array();

		$sanitized['ai_provider'] = isset( $input['ai_provider'] )
			? sanitize_key( $input['ai_provider'] )
			: $defaults['ai_provider'];

		$sanitized['openai_api_key'] = isset( $input['openai_api_key'] )
			? sanitize_text_field( $input['openai_api_key'] )
			: '';

		$sanitized['openai_model'] = isset( $input['openai_model'] )
			? sanitize_text_field( $input['openai_model'] )
			: $defaults['openai_model'];

		$sanitized['gemini_api_key'] = isset( $input['gemini_api_key'] )
			? sanitize_text_field( $input['gemini_api_key'] )
			: '';

		$sanitized['gemini_model'] = isset( $input['gemini_model'] )
			? sanitize_text_field( $input['gemini_model'] )
			: $defaults['gemini_model'];

		/*
		 * Preserve existing API keys when the password field is left blank.
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

		/*
		 * AI Assistant Skill settings.
		 *
		 * If a field is submitted empty, restore its generic default
		 * rather than leaving the assistant without instructions.
		 */
		$skill_fields = array(
			'ai_assistant_role'     => 'sanitize_text_field',
			'ai_primary_goal'       => 'sanitize_textarea_field',
			'ai_conversation_style' => 'sanitize_textarea_field',
			'ai_behavior'           => 'sanitize_textarea_field',
			'ai_avoid'              => 'sanitize_textarea_field',
			'ai_escalation'         => 'sanitize_textarea_field',
		);

		foreach ( $skill_fields as $key => $callback ) {

			$value = isset( $input[ $key ] )
				? call_user_func( $callback, $input[ $key ] )
				: '';

			$sanitized[ $key ] = '' !== trim( $value )
				? $value
				: $defaults[ $key ];
		}

		return $sanitized;
	}

	/**
	 * Render AI provider settings section.
	 *
	 * @return void
	 */
	public static function render_ai_section() {

		echo '<p>';
		echo esc_html(
			'Configure the AI provider used by the plugin.'
		);
		echo '</p>';
	}

	/**
	 * Render AI Assistant Skill section.
	 *
	 * @return void
	 */
	public static function render_skill_section() {

		echo '<p>';
		echo esc_html(
			'Define how the AI should behave for this business. These instructions customize the assistant without changing the plugin\'s core safety and integrity rules.'
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
			<p class="description">
				<?php
				echo esc_html(
					'Enter the API key for the selected OpenAI account. Keep this key private.'
				);
				?>
			</p>
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
			<p class="description">
				<?php
				echo esc_html(
					'Enter the API key for the selected Google Gemini account. Keep this key private.'
				);
				?>
			</p>
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
	 * Render assistant role field.
	 *
	 * @return void
	 */
	public static function render_assistant_role_field() {

		self::render_text_field(
			'ai_assistant_role',
			self::get_defaults()['ai_assistant_role'],
			'e.g. Describe the role you want the assistant to perform for your business.'
		);
	}

	/**
	 * Render primary goal field.
	 *
	 * @return void
	 */
	public static function render_primary_goal_field() {

		self::render_textarea(
			'ai_primary_goal',
			'e.g. What should the assistant primarily help customers accomplish?'
		);
	}

	/**
	 * Render conversation style field.
	 *
	 * @return void
	 */
	public static function render_conversation_style_field() {

		self::render_textarea(
			'ai_conversation_style',
			'e.g. Describe the tone, personality, response length, and communication style you want.'
		);
	}

	/**
	 * Render behavior field.
	 *
	 * @return void
	 */
	public static function render_behavior_field() {

		self::render_textarea(
			'ai_behavior',
			'e.g. Describe how the assistant should approach questions, recommendations, follow-up questions, and conversations.'
		);
	}

	/**
	 * Render avoid field.
	 *
	 * @return void
	 */
	public static function render_avoid_field() {

		self::render_textarea(
			'ai_avoid',
			'e.g. List behaviors, language, or actions the assistant should avoid.'
		);
	}

	/**
	 * Render escalation field.
	 *
	 * @return void
	 */
	public static function render_escalation_field() {

		self::render_textarea(
			'ai_escalation',
			'e.g. Explain when the assistant should involve a human and how it should communicate that to the customer.'
		);
	}

	/**
	 * Render a text field.
	 *
	 * @param string $key         Setting key.
	 * @param string $default     Default value.
	 * @param string $placeholder Placeholder text.
	 * @return void
	 */
	private static function render_text_field(
		$key,
		$default,
		$placeholder
	) {

		$value = self::get(
			$key,
			$default
		);

		?>
		<input
			type="text"
			class="regular-text"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[<?php echo esc_attr( $key ); ?>]"
			value="<?php echo esc_attr( $value ); ?>"
			placeholder="<?php echo esc_attr( $placeholder ); ?>"
		/>
		<?php
	}

	/**
	 * Render a skill textarea.
	 *
	 * @param string $key         Setting key.
	 * @param string $placeholder Placeholder text.
	 * @return void
	 */
	private static function render_textarea(
		$key,
		$placeholder
	) {

		$defaults = self::get_defaults();

		$value = self::get(
			$key,
			isset( $defaults[ $key ] )
				? $defaults[ $key ]
				: ''
		);

		?>
		<textarea
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[<?php echo esc_attr( $key ); ?>]"
			rows="5"
			class="large-text"
			placeholder="<?php echo esc_attr( $placeholder ); ?>"
		><?php echo esc_textarea( $value ); ?></textarea>

		<p class="description">
			<?php echo esc_html( $placeholder ); ?>
		</p>
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

		/*
		 * Settings is now a submenu under the plugin's top-level menu.
		 *
		 * WordPress generates this hook as:
		 * wp-rapidrescue-chat_page_wp-rapidrescue-chat-settings
		 */
		if (
			'wp-rapidrescue-chat_page_wp-rapidrescue-chat-settings'
			!== $hook_suffix
		) {
			return;
		}

		$script_path = WP_RAPIDRESCUE_CHAT_PATH .
			'admin/assets/js/settings.js';

		$script_url = WP_RAPIDRESCUE_CHAT_URL .
			'admin/assets/js/settings.js';

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