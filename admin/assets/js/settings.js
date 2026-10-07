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

	if (!providerSelect) {
		return;
	}

	function updateProviderFields() {
		const provider = providerSelect.value;

		openaiFields.forEach(function (field) {
			field.style.display = provider === 'openai' ? '' : 'none';
		});

		geminiFields.forEach(function (field) {
			field.style.display = provider === 'gemini' ? '' : 'none';
		});
	}

	providerSelect.addEventListener('change', updateProviderFields);

	updateProviderFields();
});