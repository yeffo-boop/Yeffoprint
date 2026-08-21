import { __ } from '@wordpress/i18n';

export const NoSavedTemplates = () => (
	<p>
		{ __(
			'You have no saved templates. Star carrier packages or add custom packages.',
			'woocommerce-shipping'
		) }
	</p>
);
