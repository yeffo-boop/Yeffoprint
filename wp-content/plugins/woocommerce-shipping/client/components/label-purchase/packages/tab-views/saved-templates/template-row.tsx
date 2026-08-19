import React, { MouseEvent } from 'react';
import {
	__experimentalText as Text,
	Icon,
	Spinner,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { trash } from '@wordpress/icons';
import { getDimensionsUnit, getWeightUnit } from 'utils';
import { CustomPackage, Package } from 'types';
import { DELETION_EVENTS, trackPackageDeletion } from '../../utils';
import { PromoBadge } from 'components/label-purchase/promo';

interface TemplateRowProps {
	pkg: Package | CustomPackage;
	isBusy: boolean;
	deletePackage?: ( deletable: Package | CustomPackage ) => void;
}

export const TemplateRow = ( {
	pkg,
	isBusy,
	deletePackage,
}: TemplateRowProps ) => {
	const dimensionsUnit = getDimensionsUnit();
	const weightUnit = getWeightUnit();
	const preparedDimensions =
		(
			( pkg.isUserDefined
				? pkg.innerDimensions ?? pkg.dimensions
				: pkg.outerDimensions ?? pkg.innerDimensions ) ?? ''
		)
			.replaceAll( `${ dimensionsUnit } x`, 'x' ) // Convert `unit + ' x'` to `x`. E.g. `cm x cm x cm` to `x x x`.
			.replaceAll( 'x', `${ dimensionsUnit } x` ) +
		` ${ dimensionsUnit }`;

	const onDeleteIconClick = ( e: MouseEvent ) => {
		e.stopPropagation();
		trackPackageDeletion( DELETION_EVENTS.CLICKED, pkg );
		deletePackage?.( pkg );
	};

	return (
		<>
			<Text truncate title={ pkg.name }>
				{ pkg.name }
			</Text>
			<span>{ preparedDimensions }</span>
			<span>
				{ pkg.boxWeight }
				{ weightUnit }
			</span>
			{ 'carrierId' in pkg && <PromoBadge carrier={ pkg.carrierId } /> }
			{ isBusy && <Spinner /> }
			{ deletePackage && (
				<Icon
					className="saved-template-options__delete"
					aria-label={
						pkg.isUserDefined
							? __( 'Delete package', 'woocommerce-shipping' )
							: __( 'Remove package', 'woocommerce-shipping' )
					}
					icon={ trash }
					onClick={ onDeleteIconClick }
				/>
			) }
		</>
	);
};
