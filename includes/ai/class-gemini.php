foreach (
	$definitions as $tool
) {

	if ( ! is_array( $tool ) ) {
		continue;
	}

	$parameters =
		isset( $tool['parameters'] ) &&
		is_array( $tool['parameters'] )
			? $tool['parameters']
			: array(
				'type' =>
					'object',

				'properties' =>
					array(),
			);

	/*
	 * Gemini does not need an empty required array.
	 *
	 * Only include "required" when the tool actually has
	 * required parameters.
	 */
	if (
		isset( $parameters['required'] ) &&
		is_array( $parameters['required'] ) &&
		empty( $parameters['required'] )
	) {
		unset( $parameters['required'] );
	}

	$tools[] =
		array(
			'type' =>
				'function',

			'name' =>
				isset( $tool['name'] )
					? sanitize_key(
						$tool['name']
					)
					: '',

			'description' =>
				isset( $tool['description'] )
					? (string) $tool['description']
					: '',

			'parameters' =>
				$parameters,
		);
}