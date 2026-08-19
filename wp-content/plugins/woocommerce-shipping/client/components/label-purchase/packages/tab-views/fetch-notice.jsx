import { __experimentalSpacer as Spacer, Notice } from '@wordpress/components';
import { isEmpty, reject } from 'lodash';
import { useLabelPurchaseContext } from 'context/label-purchase';

export const FetchNotice = ( { margin = 'before' } ) => {
	const {
		rates: { errors },
	} = useLabelPurchaseContext();
	const errorMessages = Array.isArray( errors.endpoint?.rates ?? '' )
		? errors.endpoint.rates
		: reject( [ `${ errors.endpoint?.rates ?? '' }`.trim() ], isEmpty );

	return (
		errorMessages.length > 0 && (
			<>
				{ margin === 'before' && <Spacer /> }
				<Notice
					status="warning"
					politeness="polite"
					isDismissible={ false }
					className="rates-fetch-error"
				>
					{ errorMessages.map( ( error ) => (
						<p key={ error }>{ error }</p>
					) ) }
				</Notice>
				{ margin === 'after' && <Spacer /> }
			</>
		)
	);
};
