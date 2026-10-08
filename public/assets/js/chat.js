document.addEventListener('DOMContentLoaded', function () {
	'use strict';

	const form = document.querySelector(
		'#wp-rapidrescue-chat-form'
	);

	const input = document.querySelector(
		'#wp-rapidrescue-chat-input'
	);

	const messages = document.querySelector(
		'#wp-rapidrescue-chat-messages'
	);

	if (!form || !input || !messages) {
		return;
	}

	const SESSION_STORAGE_KEY =
		'wp_rapidrescue_chat_session_id';

	const sessionId = getOrCreateSessionId();

	const sendButton = form.querySelector(
		'.wp-rapidrescue-chat__send'
	);

	input.addEventListener('keydown', function (event) {

		if (
			event.key === 'Enter' &&
			!event.shiftKey
		) {
			event.preventDefault();

			if (!input.disabled) {
				form.requestSubmit();
			}
		}
	});

	input.addEventListener('input', function () {
		autoResizeInput();
	});

	form.addEventListener('submit', function (event) {
		event.preventDefault();

		const message = input.value.trim();

		if (!message || input.disabled) {
			return;
		}

		addMessage(message, 'user');

		input.value = '';
		autoResizeInput();

		setLoadingState(true);

		const loadingMessage = addLoadingMessage();

		fetch(
			wpRapidRescueChat.restUrl,
			{
				method: 'POST',
				headers: {
					'Content-Type': 'application/json'
				},
				body: JSON.stringify({
					message: message,
					session_id: sessionId
				})
			}
		)
			.then(function (response) {

				return response.json().then(
					function (data) {

						if (!response.ok) {
							throw new Error(
								data.message ||
								'The chat request failed.'
							);
						}

						return data;
					}
				);
			})
			.then(function (data) {

				removeMessage(loadingMessage);

				const reply =
					data.data &&
					data.data.text
						? data.data.text
						: 'I received your message, but no response was returned.';

				addMessage(reply, 'assistant');

				/*
				 * Temporary debug display.
				 *
				 * This is returned separately by the REST API and is
				 * never saved into the conversation transcript.
				 */
				if (
					data.data &&
					Array.isArray(data.data.debug_trace) &&
					data.data.debug_trace.length
				) {
					addDebugTrace(
						data.data.debug_trace
					);
				}
			})
			.catch(function (error) {

				removeMessage(loadingMessage);

				addMessage(
					error.message ||
					'Sorry, something went wrong. Please try again.',
					'assistant'
				);
			})
			.finally(function () {

				setLoadingState(false);

				input.focus();
			});
	});

	function setLoadingState(isLoading) {

		input.disabled = isLoading;

		if (sendButton) {
			sendButton.disabled = isLoading;
		}
	}

	function addMessage(text, type) {

		const messageElement =
			document.createElement('div');

		messageElement.className =
			'wp-rapidrescue-chat__message ' +
			'wp-rapidrescue-chat__message--' +
			type;

		messageElement.textContent = text;

		messages.appendChild(messageElement);

		scrollToBottom();

		return messageElement;
	}

	function addDebugTrace(trace) {

		const debugElement =
			document.createElement('pre');

		debugElement.className =
			'wp-rapidrescue-chat__debug';

		const lines = [
			'DEBUG TRACE',
			'────────────────────────────'
		];

		trace.forEach(function (entry) {

			if (!entry) {
				return;
			}

			const stage =
				entry.stage
					? String(entry.stage).toUpperCase()
					: 'DEBUG';

			const message =
				entry.message
					? String(entry.message)
					: '';

			let line =
				'[' +
				stage +
				'] ' +
				message;

			if (
				entry.data &&
				typeof entry.data === 'object'
			) {

				Object.keys(entry.data).forEach(
					function (key) {

						let value =
							entry.data[key];

						if (
							value &&
							typeof value === 'object'
						) {
							try {
								value =
									JSON.stringify(
										value
									);
							} catch (error) {
								value =
									'[object]';
							}
						}

						line +=
							' | ' +
							key +
							': ' +
							String(value);
					}
				);
			}

			lines.push(line);
		});

		lines.push(
			'────────────────────────────'
		);

		debugElement.textContent =
			lines.join('\n');

		messages.appendChild(
			debugElement
		);

		scrollToBottom();
	}

	function addLoadingMessage() {

		const messageElement =
			document.createElement('div');

		messageElement.className =
			'wp-rapidrescue-chat__message ' +
			'wp-rapidrescue-chat__message--assistant ' +
			'wp-rapidrescue-chat__message--loading';

		messageElement.innerHTML =
			'<span class="wp-rapidrescue-chat__typing" aria-label="Assistant is typing">' +
				'<span></span>' +
				'<span></span>' +
				'<span></span>' +
			'</span>';

		messages.appendChild(messageElement);

		scrollToBottom();

		return messageElement;
	}

	function removeMessage(element) {

		if (
			element &&
			element.parentNode
		) {
			element.parentNode.removeChild(
				element
			);
		}
	}

	function scrollToBottom() {

		requestAnimationFrame(function () {
			messages.scrollTop =
				messages.scrollHeight;
		});
	}

	function autoResizeInput() {

		input.style.height = 'auto';

		const newHeight =
			Math.min(input.scrollHeight, 120);

		input.style.height =
			newHeight + 'px';
	}

	function getOrCreateSessionId() {

		let storedSessionId =
			localStorage.getItem(
				SESSION_STORAGE_KEY
			);

		if (
			storedSessionId &&
			typeof storedSessionId === 'string' &&
			storedSessionId.length <= 64
		) {
			return storedSessionId;
		}

		storedSessionId =
			generateSessionId();

		localStorage.setItem(
			SESSION_STORAGE_KEY,
			storedSessionId
		);

		return storedSessionId;
	}

	function generateSessionId() {

		if (
			window.crypto &&
			typeof window.crypto.randomUUID === 'function'
		) {
			return window.crypto.randomUUID();
		}

		return (
			'rr-' +
			Date.now().toString(36) +
			'-' +
			Math.random().toString(36).substring(2, 15)
		);
	}
});