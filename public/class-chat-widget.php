<?php
/**
 * Temporary frontend chat widget.
 *
 * @package WP_RapidRescue_Chat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the temporary chat widget.
 */
class WP_RapidRescue_Chat_Chat_Widget {

	/**
	 * Initialize the widget.
	 *
	 * @return void
	 */
	public static function init() {

		add_action(
			'wp_enqueue_scripts',
			array( __CLASS__, 'enqueue_assets' )
		);

		add_action(
			'wp_footer',
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Enqueue widget assets.
	 *
	 * @return void
	 */
	public static function enqueue_assets() {

		$css_path = WP_RAPIDRESCUE_CHAT_PATH .
			'public/assets/css/chat.css';

		$js_path = WP_RAPIDRESCUE_CHAT_PATH .
			'public/assets/js/chat.js';

		$css_url = WP_RAPIDRESCUE_CHAT_URL .
			'public/assets/css/chat.css';

		$js_url = WP_RAPIDRESCUE_CHAT_URL .
			'public/assets/js/chat.js';

		$css_version = file_exists( $css_path )
			? filemtime( $css_path )
			: WP_RAPIDRESCUE_CHAT_VERSION;

		$js_version = file_exists( $js_path )
			? filemtime( $js_path )
			: WP_RAPIDRESCUE_CHAT_VERSION;

		wp_enqueue_style(
			'wp-rapidrescue-chat',
			$css_url,
			array(),
			$css_version
		);

		wp_enqueue_script(
			'wp-rapidrescue-chat',
			$js_url,
			array(),
			$js_version,
			true
		);

		wp_localize_script(
			'wp-rapidrescue-chat',
			'wpRapidRescueChat',
			array(
				'restUrl' => esc_url_raw(
					rest_url(
						'wp-rapidrescue/v1/chat'
					)
				),
			)
		);
	}

	/**
	 * Render the temporary chat widget.
	 *
	 * @return void
	 */
	public static function render() {
		?>

		<div
			id="wp-rapidrescue-chat"
			class="wp-rapidrescue-chat"
		>

			<div
				class="wp-rapidrescue-chat__header"
			>
				<strong>
					WP RapidRescue
				</strong>
			</div>

			<div
				id="wp-rapidrescue-chat-messages"
				class="wp-rapidrescue-chat__messages"
				aria-live="polite"
			>

				<div
					class="wp-rapidrescue-chat__message wp-rapidrescue-chat__message--assistant"
				>
					Hi! How can I help?
				</div>

			</div>

			<form
				id="wp-rapidrescue-chat-form"
				class="wp-rapidrescue-chat__form"
			>

				<label
					class="screen-reader-text"
					for="wp-rapidrescue-chat-input"
				>
					Type your message
				</label>

				<textarea
					id="wp-rapidrescue-chat-input"
					class="wp-rapidrescue-chat__input"
					rows="2"
					placeholder="Type your message..."
					required
				></textarea>

				<button
					type="submit"
					class="wp-rapidrescue-chat__send"
				>
					Send
				</button>

			</form>

		</div>

		<?php
	}
}