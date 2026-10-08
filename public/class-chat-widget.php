<?php
/**
 * Frontend chat widget.
 *
 * @package WP_RapidRescue_Chat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the frontend chat widget.
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
			'wp-rapidrescue-chat-font',
			'https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&display=swap',
			array(),
			null
		);

		wp_enqueue_style(
			'wp-rapidrescue-chat',
			$css_url,
			array( 'wp-rapidrescue-chat-font' ),
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
						'rapidrescue/v1/chat'
					)
				),
			)
		);
	}

	/**
	 * Render the chat widget.
	 *
	 * The widget intentionally starts closed. JavaScript opens it only
	 * after the visitor clicks the launcher button.
	 *
	 * @return void
	 */
	public static function render() {
		?>

		<div
			id="wp-rapidrescue-chat"
			class="wp-rapidrescue-chat"
			aria-hidden="true"
		>

			<div class="wp-rapidrescue-chat__header">

				<div class="wp-rapidrescue-chat__brand">

					<div class="wp-rapidrescue-chat__brand-mark">
						<span></span>
					</div>

					<div class="wp-rapidrescue-chat__brand-text">

						<strong class="wp-rapidrescue-chat__title">
							WP RapidRescue
						</strong>

						<span class="wp-rapidrescue-chat__status">
							<span class="wp-rapidrescue-chat__status-dot"></span>
							Online
						</span>

					</div>

				</div>

				<button
					type="button"
					class="wp-rapidrescue-chat__close"
					aria-label="Close chat"
					title="Close chat"
				>
					<span aria-hidden="true">×</span>
				</button>

			</div>

			<div
				id="wp-rapidrescue-chat-messages"
				class="wp-rapidrescue-chat__messages"
				aria-live="polite"
				aria-label="Chat messages"
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

				<div class="wp-rapidrescue-chat__composer">

					<textarea
						id="wp-rapidrescue-chat-input"
						class="wp-rapidrescue-chat__input"
						rows="1"
						placeholder="Type your message..."
						autocomplete="off"
						required
					></textarea>

					<button
						type="submit"
						class="wp-rapidrescue-chat__send"
						aria-label="Send message"
					>
						<span class="wp-rapidrescue-chat__send-label">
							Send
						</span>

						<span
							class="wp-rapidrescue-chat__send-icon"
							aria-hidden="true"
						>
							→
						</span>
					</button>

				</div>

				<div class="wp-rapidrescue-chat__hint">
					Press Enter to send · Shift + Enter for a new line
				</div>

			</form>

		</div>

		<button
			id="wp-rapidrescue-chat-launcher"
			class="wp-rapidrescue-chat__launcher"
			type="button"
			aria-label="Open chat"
			aria-controls="wp-rapidrescue-chat"
			aria-expanded="false"
		>
			<span
				class="wp-rapidrescue-chat__launcher-icon wp-rapidrescue-chat__launcher-icon--open"
				aria-hidden="true"
			>
				<svg
					viewBox="0 0 24 24"
					width="24"
					height="24"
					fill="none"
					stroke="currentColor"
					stroke-width="1.8"
					stroke-linecap="round"
					stroke-linejoin="round"
				>
					<path d="M20 11.5a7.5 7.5 0 0 1-7.5 7.5H8l-4 3v-7.2A7.5 7.5 0 0 1 4.5 4.5 7.5 7.5 0 0 1 12 2h.5A7.5 7.5 0 0 1 20 9.5z"></path>
				</svg>
			</span>

			<span
				class="wp-rapidrescue-chat__launcher-icon wp-rapidrescue-chat__launcher-icon--close"
				aria-hidden="true"
			>
				×
			</span>
		</button>

		<?php
	}
}