import { ExternalLink } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';
import { Label } from 'types';

interface CommercialInvoiceProps {
	label?: Label;
	className?: string;
	onClick?: () => void;
	children?: ReactNode;
}

export const CommercialInvoice = ( {
	label,
	className,
	onClick,
	children,
}: CommercialInvoiceProps ) => {
	const commercialInvoiceUrl = label?.commercialInvoiceUrl;
	return (
		commercialInvoiceUrl && (
			<ExternalLink
				href={ commercialInvoiceUrl }
				className={ className }
				onClick={ onClick }
			>
				{ children ??
					__( 'Print customs form', 'woocommerce-shipping' ) }
			</ExternalLink>
		)
	);
};
