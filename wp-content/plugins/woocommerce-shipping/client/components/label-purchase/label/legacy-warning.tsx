import React from 'react';
import { __, _x, sprintf } from '@wordpress/i18n';
import {
	Notice,
	__experimentalHeading as Heading,
	__experimentalSpacer as Spacer,
	Icon,
} from '@wordpress/components';
import { caution as warning } from '@wordpress/icons';
import { createInterpolateElement } from '@wordpress/element';
import { useLabelPurchaseContext } from 'context/label-purchase';

export const LegacyWarning = () => {
	const {
		customs: { isCustomsNeeded },
	} = useLabelPurchaseContext();

	const hasCustomsForm = isCustomsNeeded();
	return (
		<>
			<Spacer margin={ 1 } />
			<Notice
				status="warning"
				isDismissible={ false }
				className="legacy-warning-notice"
			>
				<Heading level={ 5 }>
					<Icon icon={ warning } />
					{ __(
						'This is a migrated shipping label',
						'woocommerce-shipping'
					) }
				</Heading>
				<Spacer margin={ 3 } />
				{ sprintf(
					// translators: %s is filled with `and customs form`, if label has customs form
					__(
						`The "WooCommerce Shipping & Tax" plugin doesn't store as much information about labels as WooCommerce Shipping. Please refer to the label %s for more details. This affects the following label information:`,
						'woocommerce-shipping'
					),
					hasCustomsForm
						? _x(
								'and customs form',
								'Migrated Label Description',
								'woocommerce-shipping'
						  )
						: ''
				) }
				<ul>
					<li>
						{ __( 'Ship from address.', 'woocommerce-shipping' ) }
					</li>
					<li>
						{ __(
							'HAZMAT configurations.',
							'woocommerce-shipping'
						) }
					</li>
					<li>
						{ createInterpolateElement(
							__(
								'Label addons such as <i1/> and <i2/>.',
								'woocommerce-shipping'
							),
							{
								i1: (
									<i>
										{ __(
											'signature required',
											'woocommerce-shipping'
										) }
									</i>
								),
								i2: (
									<i>
										{ __(
											'adult signature required',
											'woocommerce-shipping'
										) }
									</i>
								),
							}
						) }
					</li>
					{ hasCustomsForm && (
						<li>
							{ __(
								'Customs information.',
								'woocommerce-shipping'
							) }
						</li>
					) }
				</ul>
			</Notice>
		</>
	);
};
