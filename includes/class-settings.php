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
					'ai_provider'             => 'openai',
					'openai_api_key'         => '',
					'openai_model'           => 'gpt-6-luna',
					'gemini_api_key'         => '',
					'gemini_model'           => 'gemini-3.8-flash',
					'ai_assistant_role'      => 'professional AI assistant',
					'ai_primary_goal'        => 'Understand the customer\'s needs, provide accurate information from the business knowledge base, help when possible, and guide the customer toward the appropriate next step.',
					'ai_conversation_style'  => 'Friendly, professional, natural, and concise. Ask relevant questions instead of overwhelming the customer with unnecessary information.',
					'ai_behavior'             => 'Understand the customer\'s situation before recommending products, services, pricing, or next steps. Answer the customer\'s actual question first. Ask follow-up questions when important information is missing. Use conversation history to maintain context.',
					'ai_avoid'                => 'Do not pressure the customer into buying something. Do not introduce pricing unnecessarily. Do not repeatedly ask for information the customer has already provided. Do not make assumptions when important information is unknown.',
					'ai_escalation'           => 'When the customer needs human assistance, explain that escalation is available and collect the information required by the application. Do not claim that a ticket, escalation, appointment, order, or other action has been completed unless the application has actually confirmed it.',
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
			'wp_rapidrescue_chat_skill_section'
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

		/*
		 * AI Assistant Skill settings.
		 */
		$sanitized['ai_assistant_role'] =
			isset( $input['ai_assistant_role'] )
				? sanitize_text_field(
					$input['ai_assistant_role']
				)
				: '';

		$sanitized['ai_primary_goal'] =
			isset( $input['ai_primary_goal'] )
				? sanitize_textarea_field(
					$input['ai_primary_goal']
				)
				: '';

		$sanitized['ai_conversation_style'] =
			isset( $input['ai_conversation_style'] )
				? sanitize_textarea_field(
					$input['ai_conversation_style']
				)
				: '';

		$sanitized['ai_behavior'] =
			isset( $input['ai_behavior'] )
				? sanitize_textarea_field(
					$input['ai_behavior']
				)
				: '';

		$sanitized['ai_avoid'] =
			isset( $input['ai_avoid'] )
				? sanitize_textarea_field(
					$input['ai_avoid']
				)
				: '';

		$sanitized['ai_escalation'] =
			isset( $input['ai_escalation'] )
				? sanitize_textarea_field(
					$input['ai_escalation']
				)
				: '';

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
	 * Render assistant role field.
	 *
	 * @return void
	 */
	public static function render_assistant_role_field() {

		$value = self::get(
			'ai_assistant_role',
			'professional AI assistant'
		);

		?>
		<input
			type="text"
			class="regular-text"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[ai_assistant_role]"
			value="<?php echo esc_attr( $value ); ?>"
		/>
		<p class="description">
			<?php
			echo esc_html(
				'Describe what kind of assistant this should be, for example: sales assistant, customer support assistant, booking assistant, technical helpdesk assistant, or consultant.'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Render primary goal field.
	 *
	 * @return void
	 */
	public static function render_primary_goal_field() {

		self::render_textarea(
			'ai_primary_goal',
			'Describe the main outcome the assistant should help customers achieve.'
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
			'Describe the desired tone and communication style.'
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
			'Describe how the assistant should approach conversations, questions, recommendations, qualification, and other tasks.'
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
			'List behaviors the assistant should avoid.'
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
			'Describe how the assistant should handle situations that require a human or another business process.'
		);
	}

	/**
	 * Render a skill textarea.
	 *
	 * @param string $key         Setting key.
	 * @param string $description Field description.
	 * @return void
	 */
	private static function render_textarea(
		$key,
		$description
	) {

		$value = self::get(
			$key,
			''
		);

		?>
		<textarea
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[<?php echo esc_attr( $key ); ?>]"
			rows="5"
			class="large-text"
		><?php echo esc_textarea( $value ); ?></textarea>

		<p class="description">
			<?php echo esc_html( $description ); ?>
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