/**
 * Origin Selection Step - Step 1 of ScanForm creation
 */

import { RadioControl } from '@wordpress/components';
import { __, sprintf, _n } from '@wordpress/i18n';
import { useCallback, useState, useEffect } from '@wordpress/element';
import type { ScanFormOrigin } from 'types';
import { addressToString } from 'utils';

interface OriginSelectionStepProps {
	origins: ScanFormOrigin[];
	onSelectOrigin: ( origin: ScanFormOrigin ) => void;
}

export const OriginSelectionStep = ( {
	origins,
	onSelectOrigin,
}: OriginSelectionStepProps ) => {
	const [ selectedOriginId, setSelectedOriginId ] = useState< string >(
		() => origins[ 0 ]?.origin_id || ''
	);

	// Select the first origin by default on mount.
	useEffect( () => {
		if ( origins.length > 0 && origins[ 0 ] ) {
			onSelectOrigin( origins[ 0 ] );
		}
	}, [ origins, onSelectOrigin ] );

	/**
	 * Handle radio selection change.
	 */
	const handleSelectionChange = useCallback(
		( value: string ) => {
			setSelectedOriginId( value );
			const selectedOrigin = origins.find(
				( origin ) => origin.origin_id === value
			);
			if ( selectedOrigin ) {
				onSelectOrigin( selectedOrigin );
			}
		},
		[ origins, onSelectOrigin ]
	);

	/**
	 * Generate radio options from origins.
	 */
	const radioOptions = origins.map( ( origin ) => {
		const address = addressToString( origin.origin_address );
		const labelCount = sprintf(
			/* translators: %d is number of labels */
			_n(
				'%d eligible label',
				'%d eligible labels',
				origin.label_count,
				'woocommerce-shipping'
			),
			origin.label_count
		);

		return {
			label: `${ address }\n${ labelCount }`,
			value: origin.origin_id,
		};
	} );

	return (
		<>
			<p className="scan-form-modal__step-description">
				{ __(
					'All shipments in a SCAN Form must share the same origin address. Select one address to continue.',
					'woocommerce-shipping'
				) }
			</p>

			<h4 className="scan-form-modal__addresses-title">
				{ __( 'Choose an address', 'woocommerce-shipping' ) }
			</h4>

			<RadioControl
				selected={ selectedOriginId }
				options={ radioOptions }
				onChange={ handleSelectionChange }
				className="scan-form-modal__origin-radio-group"
			/>
		</>
	);
};
