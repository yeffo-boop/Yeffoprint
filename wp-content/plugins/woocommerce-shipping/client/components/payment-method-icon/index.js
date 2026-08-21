import React from 'react';
import { __ } from '@wordpress/i18n';
import './style.scss';

/**
 * Image assets
 */
import creditCardAmexImage from './images/cc-amex.svg';
import creditCardDinersImage from './images/cc-diners.svg';
import creditCardDiscoverImage from './images/cc-discover.svg';
import creditCardJCBImage from './images/cc-jcb.svg';
import creditCardMasterCardImage from './images/cc-mastercard.svg';
import creditCardUnionPayImage from './images/cc-unionpay.svg';
import creditCardVisaImage from './images/cc-visa.svg';

const LOGO_PATHS = {
	amex: creditCardAmexImage,
	diners: creditCardDinersImage,
	discover: creditCardDiscoverImage,
	jcb: creditCardJCBImage,
	mastercard: creditCardMasterCardImage,
	unionpay: creditCardUnionPayImage,
	visa: creditCardVisaImage,
};

const ALT_TEXT = {
	amex: 'American Express',
	diners: 'Diners Club',
	discover: 'Discover',
	jcb: 'JCB',
	mastercard: 'Mastercard',
	unionpay: 'UnionPay',
	visa: 'Visa',
	placeholder: 'Payment logo',
};

export const POSSIBLE_TYPES = Object.keys( ALT_TEXT );

const PaymentMethodIcon = ( { altText, type } ) => {
	const className = 'payment-method-icon ' + 'is-' + type;
	const logoPath = LOGO_PATHS[ type ];
	const logoStyle = logoPath
		? { backgroundImage: `url(${ logoPath })` }
		: undefined;

	return (
		<div
			className={ className }
			style={ logoStyle }
			aria-label={
				altText ??
				ALT_TEXT[ type ] ??
				__( 'unknown payment', 'woocommerce-shipping' )
			}
		/>
	);
};

export default PaymentMethodIcon;
