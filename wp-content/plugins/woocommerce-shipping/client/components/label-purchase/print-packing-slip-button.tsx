import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useLabelPurchaseContext } from 'context/label-purchase';
import { recordEvent } from 'utils';

export const PrintPackingSlipButton = (): JSX.Element => {
	const {
		labels: { printPackingSlip, isPrintingPackingSlip },
		nextDesign,
	} = useLabelPurchaseContext();

	const initiatePrint = async () => {
		recordEvent( 'print_packing_slip' );
		await printPackingSlip();
	};

	return (
		<Button
			variant="secondary"
			onClick={ initiatePrint }
			className="print-packing-slip-button"
			isBusy={ isPrintingPackingSlip }
			aria-busy={ isPrintingPackingSlip }
			disabled={ isPrintingPackingSlip }
			__next40pxDefaultSize={ nextDesign }
		>
			{ __( 'Print packing slip', 'woocommerce-shipping' ) }
		</Button>
	);
};
