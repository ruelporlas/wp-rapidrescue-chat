document.addEventListener('DOMContentLoaded', function () {
	'use strict';

	/*
	 * AI provider selector.
	 */
	const providerSelect = document.querySelector(
		'select[name="wp_rapidrescue_chat_settings[ai_provider]"]'
	);

	/*
	 * Get the complete settings row containing a provider field.
	 *
	 * WordPress Settings API normally places each setting inside
	 * a <tr>. Hiding the complete row prevents the field label from
	 * remaining visible when the field itself is hidden.
	 */
	function getSettingRows(selector) {

		const elements = document.querySelectorAll(selector);
		const rows = [];

		elements.forEach(function (element) {

			const row = element.closest('tr');

			if (row && !rows.includes(row)) {
				rows.push(row);
			}
		});

		return rows;
	}

	const openaiRows = getSettingRows(
		'.rr-provider-openai'
	);

	const geminiRows = getSettingRows(
		'.rr-provider-gemini'
	);

	/*
	 * Show only the settings belonging to the selected provider.
	 */
	function updateProviderFields() {

		if (!providerSelect) {
			return;
		}

		const provider = providerSelect.value;

		openaiRows.forEach(function (row) {

			row.style.display =
				provider === 'openai'
					? ''
					: 'none';
		});

		geminiRows.forEach(function (row) {

			row.style.display =
				provider === 'gemini'
					? ''
					: 'none';
		});
	}

	/*
	 * Initialize provider fields.
	 */
	if (providerSelect) {

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

		testResult.className =
			'notice inline';

		testResult.innerHTML =
			'<p>Testing AI connection...</p>';

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
			.catch(function () {

				showError(
					'WordPress could not complete the connection test.'
				);
			})
			.finally(function () {

				testButton.disabled = false;
				testButton.textContent =
					'Test AI Connection';
			});
	});

	/**
	 * Display an error.
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
	 * Escape HTML.
	 *
	 * @param {*} value Value to escape.
	 * @return {string} Escaped value.
	 */
	function escapeHtml(value) {

		const div = document.createElement('div');

		div.textContent =
			value === null ||
			typeof value === 'undefined'
				? ''
				: String(value);

		return div.innerHTML;
	}
});