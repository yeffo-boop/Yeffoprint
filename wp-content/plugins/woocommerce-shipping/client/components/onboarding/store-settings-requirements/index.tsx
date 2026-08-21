import React from 'react';
import {
	Notice,
	__experimentalHeading as Heading,
	__experimentalText as Text,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import './style.scss';
import { createInterpolateElement } from '@wordpress/element';

export const StoreSettingsRequirements = ( {
	countryName,
	currency,
	isCountrySupported,
	isCurrencySupported,
}: {
	countryName: string;
	currency: string;
	isCountrySupported: boolean;
	isCurrencySupported: boolean;
} ): React.JSX.Element | false => {
	return (
		<Notice
			status="warning"
			isDismissible={ false }
			className="wcshipping-onboarding-requirements"
		>
			<Heading level={ 5 }>
				{ __(
					'The site is unable to use WooCommerce Shipping for the following reason(s):',
					'woocommerce-shipping'
				) }
			</Heading>

			<ul className="wcshipping-onboarding-requirements__list">
				{ ! isCountrySupported && (
					<li>
						{ createInterpolateElement(
							sprintf(
								// translators: %s The full country name of the store's address.
								__(
									'Your country, <strong>%s</strong>, is not supported yet.',
									'woocommerce-shipping'
								),
								countryName
							),
							{
								strong: <strong></strong>,
							}
						) }
					</li>
				) }

				{ ! isCurrencySupported && (
					<li>
						{ createInterpolateElement(
							sprintf(
								// translators: %s The store's selected currency in short-form (e.g. USD).
								__(
									'Your currency, <strong>%s</strong>, is not supported yet.',
									'woocommerce-shipping'
								),
								currency
							),
							{
								strong: <strong></strong>,
							}
						) }
					</li>
				) }
			</ul>

			<Text
				size="footnote"
				className="wcshipping-onboarding-requirements__footnote"
			>
				{ __(
					'We are actively expanding our international support and will notify you as soon as your store is fully supported..',
					'woocommerce-shipping'
				) }
			</Text>
		</Notice>
	);
};
