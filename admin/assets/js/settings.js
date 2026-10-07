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

	if (providerSelect) {
		function updateProviderFields() {
			const provider = providerSelect.value;

			openaiFields.forEach(function (field) {
				field.style.display =
					provider === 'openai' ? '' : 'none';
			});

			geminiFields.forEach(function (field) {
				field.style.display =
					provider === 'gemini' ? '' : 'none';
			});
		}

		providerSelect.addEventListener(
			'change',
			updateProviderFields
		);

		updateProviderFields();
	}

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

				return response.json()
					.then(function (data) {

						return {
							ok: response.ok,
							status: response.status,
							data: data
						};
					})
					.catch(function () {

						return {
							ok: false,
							status: response.status,
							data: null
						};
					});
			})
			.then(function (result) {

				const data = result.data;

				if (
					result.ok &&
					data &&
					data.success
				) {
					const responseData = data.data || {};

					testResult.className =
						'notice notice-success inline';

					testResult.innerHTML =
						'<p><strong>Connection successful.</strong></p>' +
						'<p>' +
						'Provider: ' +
						escapeHtml(responseData.provider) +
						'<br>' +
						'Model: ' +
						escapeHtml(responseData.model) +
						'<br>' +
						'Response: ' +
						escapeHtml(responseData.response) +
						'</p>';

					return;
				}

				let message =
					'The AI connection test failed.';

				if (
					data &&
					data.data &&
					data.data.message
				) {
					message = data.data.message;
				}

				if (
					!result.ok &&
					!data
				) {
					message =
						'WordPress returned HTTP ' +
						result.status +
						' without a readable error message.';
				}

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
				testButton.textContent =
					'Test AI Connection';
			});
	});

	function showError(message) {

		testResult.className =
			'notice notice-error inline';

		testResult.innerHTML =
			'<p><strong>Connection failed.</strong></p>' +
			'<p>' +
			escapeHtml(message) +
			'</p>';
	}

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