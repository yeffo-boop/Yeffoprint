import React from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { useSettings } from 'data/settings/hooks';
import { settingsStore } from 'data/settings';
import { useSelect } from '@wordpress/data';

const ExternalInfo = () => {
	const { storeOwnerWpcomLogin, storeOwnerEmail, canManagePayments } =
		useSettings();
	const paymentMethods = useSelect( ( select ) => {
		return select( settingsStore ).getPaymentMethods();
	} );

	const selectedPaymentMethod = useSelect( ( select ) => {
		return select( settingsStore ).getSelectedPaymentMethod();
	} );

	if ( ! storeOwnerWpcomLogin ) {
		return null;
	}

	const selectedCard = paymentMethods.find(
		( method ) => method.payment_method_id === selectedPaymentMethod
	);
	if ( ! selectedCard ) {
		return null;
	}

	if ( canManagePayments ) {
		const extraInfo = sprintf(
			// translators: %1$s is the WordPress.com username, %2$s is the email address.
			__(
				'Credit cards are retrieved from the following WordPress.com account: %1$s <%2$s>',
				'woocommerce-shipping'
			),
			storeOwnerWpcomLogin,
			storeOwnerEmail
		);
		return <p className="wcshipping-settings__extras">{ extraInfo }</p>;
	}
	const cardDigits = selectedCard?.card_digits ?? '';
	const cardType = selectedCard?.card_type ?? '';
	const extraInfo = sprintf(
		// translators: %1$s is the credit card issuer, %2$s is the the last 4 digits of the card number, %3$s is the WordPress.com username.
		__(
			"We'll charge the credit card (%1$s **** %2$s) on the connected WordPress.com account (%3$s) to pay for the labels you purchase.",
			'woocommerce-shipping'
		),
		cardType,
		cardDigits,
		storeOwnerWpcomLogin
	);
	return <p className="wcshipping-settings__extras">{ extraInfo }</p>;
};

export default ExternalInfo;
