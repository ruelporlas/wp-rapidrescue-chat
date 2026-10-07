document.addEventListener('DOMContentLoaded', function () {
	const providerSelect = document.querySelector(
		'select[name="wp_rapidrescue_chat_settings[ai_provider]"]'
	);

	const openaiFields = document.querySelectorAll(
		'.rr-provider-openai'
	);

	const geminiFields = document.querySelectorAll(
		'.rr-provider-gemini'
	);

	/*
	 * Provider field visibility.
	 */
	if (providerSelect) {
		function updateProviderFields() {
			const provider = providerSelect.value;

			openaiFields.forEach(function (field) {
				field.style.display = provider === 'openai' ? '' : 'none';
			});

			geminiFields.forEach(function (field) {
				field.style.display = provider === 'gemini' ? '' : 'none';
			});
		}

		providerSelect.addEventListener(
			'change',
			updateProviderFields
		);

		updateProviderFields();
	}

	/*
	 * AI connection test.
	 */
	const testButton = document.querySelector(
		'#wp-rapidrescue-test-ai-connection'
	);

	const testResult = document.querySelector(
		'#wp-rapidrescue-ai-test-result'
	);

	if (!testButton || !testResult) {
		return;
	}

	testButton.addEventListener('click', function () {

		if (
			typeof wpRapidRescueChatSettings === 'undefined' ||
			!wpRapidRescueChatSettings.ajaxUrl ||
			!wpRapidRescueChatSettings.nonce
		) {
			showError(
				'The AI connection test could not be initialized. Please refresh the page and try again.'
			);

			return;
		}

		testButton.disabled = true;
		testButton.textContent = 'Testing...';

		testResult.innerHTML = '';
		testResult.className = '';

		const formData = new FormData();

		formData.append(
			'action',
			'wp_rapidrescue_test_ai_connection'
		);

		formData.append(
			'nonce',
			wpRapidRescueChatSettings.nonce
		);

		fetch(
			wpRapidRescueChatSettings.ajaxUrl,
			{
				method: 'POST',
				credentials: 'same-origin',
				body: formData
			}
		)
			.then(function (response) {

				if (!response.ok) {
					throw new Error(
						'WordPress returned HTTP ' + response.status + '.'
					);
				}

				return response.json();
			})
			.then(function (data) {

				if (data.success) {

					const result = data.data || {};

					testResult.className =
						'notice notice-success inline';

					testResult.innerHTML =
						'<p><strong>Connection successful.</strong></p>' +
						'<p>' +
						'Provider: ' +
						escapeHtml(result.provider) +
						'<br>' +
						'Model: ' +
						escapeHtml(result.model) +
						'<br>' +
						'Response: ' +
						escapeHtml(result.response) +
						'</p>';

					return;
				}

				const message =
					data.data &&
					data.data.message
						? data.data.message
						: 'The AI connection test failed.';

				showError(message);
			})
			.catch(function (error) {

				showError(
					error && error.message
						? error.message
						: 'WordPress could not complete the connection test.'
				);
			})
			.finally(function () {

				testButton.disabled = false;
				testButton.textContent = 'Test AI Connection';
			});
	});

	/**
	 * Display an error message.
	 *
	 * @param {string} message Error message.
	 */
	function showError(message) {

		testResult.className =
			'notice notice-error inline';

		testResult.innerHTML =
			'<p><strong>Connection failed.</strong></p>' +
			'<p>' +
			escapeHtml(message) +
			'</p>';
	}

	/**
	 * Escape text before inserting it into HTML.
	 *
	 * @param {*} value Value to escape.
	 * @return {string} Escaped HTML.
	 */
	function escapeHtml(value) {

		const div = document.createElement('div');

		div.textContent =
			value === null || typeof value === 'undefined'
				? ''
				: String(value);

		return div.innerHTML;
	}
});