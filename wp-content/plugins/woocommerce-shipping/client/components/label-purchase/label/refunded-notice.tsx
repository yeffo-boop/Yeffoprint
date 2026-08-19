import React from 'react';
import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export const RefundedNotice = () => (
	<>
		<Notice
			status="info"
			className="refunded-notice"
			isDismissible={ false }
		>
			<p>
				{ __(
					'You have successfully submitted a request for refund. You can purchase a new label.',
					'woocommerce-shipping'
				) }
			</p>
		</Notice>
	</>
);
