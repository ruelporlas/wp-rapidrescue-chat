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

	form.addEventListener('submit', function (event) {
		event.preventDefault();

		const message = input.value.trim();

		if (!message) {
			return;
		}

		addMessage(message, 'user');

		input.value = '';
		input.disabled = true;

		const sendButton = form.querySelector(
			'.wp-rapidrescue-chat__send'
		);

		if (sendButton) {
			sendButton.disabled = true;
		}

		const loadingMessage = addMessage(
			'Thinking...',
			'assistant'
		);

		fetch(
			wpRapidRescueChat.restUrl,
			{
				method: 'POST',
				headers: {
					'Content-Type': 'application/json'
				},
				body: JSON.stringify({
					message: message
				})
			}
		)
			.then(function (response) {
				return response.json().then(function (data) {
					if (!response.ok) {
						throw new Error(
							data.message ||
							'The chat request failed.'
						);
					}

					return data;
				});
			})
			.then(function (data) {
				removeMessage(loadingMessage);

				const reply =
					data.data &&
					data.data.text
						? data.data.text
						: 'I received your message, but no response was returned.';

				addMessage(reply, 'assistant');
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
				input.disabled = false;

				if (sendButton) {
					sendButton.disabled = false;
				}

				input.focus();
			});
	});

	function addMessage(text, type) {
		const messageElement =
			document.createElement('div');

		messageElement.className =
			'wp-rapidrescue-chat__message ' +
			'wp-rapidrescue-chat__message--' +
			type;

		messageElement.textContent = text;

		messages.appendChild(messageElement);

		messages.scrollTop = messages.scrollHeight;

		return messageElement;
	}

	function removeMessage(element) {
		if (
			element &&
			element.parentNode
		) {
			element.parentNode.removeChild(element);
		}
	}
});