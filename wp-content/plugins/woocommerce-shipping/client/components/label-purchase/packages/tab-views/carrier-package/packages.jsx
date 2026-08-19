import { dispatch, useSelect } from '@wordpress/data';

import {
	__experimentalItem as Item,
	__experimentalItemGroup as ItemGroup,
	__experimentalText as Text,
	Button,
	Panel,
	PanelBody,
	RadioControl,
} from '@wordpress/components';
import { starEmpty, starFilled } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';
import { useCallback, useState } from '@wordpress/element';
import { getDimensionsUnit, getWeightUnit } from 'utils';
import { labelPurchaseStore } from 'data/label-purchase';
import { useLabelPurchaseContext } from 'context/label-purchase';
import { recordEvent } from 'utils/tracks';
import { PACKAGE_CATEGORIES } from 'components/label-purchase/packages';

export const Packages = ( {
	packages,
	carrierId,
	setSelectedPackage,
	selectedPackage,
} ) => {
	const dimensionsUnit = getDimensionsUnit();
	const weightUnit = getWeightUnit();
	const [ isUpdating, setIsUpdating ] = useState( false );
	const {
		rates: { isFetching },
	} = useLabelPurchaseContext();

	const predefinedPackages = useSelect( ( select ) =>
		select( labelPurchaseStore ).getPredefinedPackages( carrierId )
	);
	const updatePredefined = useCallback(
		async ( packageId, shouldRemove = false ) => {
			setIsUpdating( true );

			if ( shouldRemove ) {
				await dispatch( labelPurchaseStore ).deletePackage(
					packageId,
					PACKAGE_CATEGORIES.PREDEFINED
				);
			} else {
				await dispatch( labelPurchaseStore ).saveFavoritePackage( {
					[ carrierId ]: [ packageId ],
				} );
			}

			setIsUpdating( false );
		},
		/* eslint-disable-next-line react-hooks/exhaustive-deps */
		[ predefinedPackages, carrierId ]
	);

	const selectPackage = useCallback(
		( packageId ) => {
			const selected = Object.values( packages )
				.map( ( { definitions } ) => definitions )
				.flat()
				.find( ( p ) => p.id === packageId );
			setSelectedPackage( selected );
		},
		[ setSelectedPackage, packages ]
	);

	const isOpen = ( definitions ) =>
		definitions.some(
			( definition ) => definition.id === selectedPackage?.id
		);

	return Object.entries( packages ).map(
		( [ groupId, { title, definitions } ] ) => (
			<Panel key={ groupId }>
				<PanelBody
					title={ title }
					initialOpen={ isOpen( definitions ) }
				>
					<ItemGroup
						isBordered={ false }
						className="label-purchase-packages"
						key={ `ItemGroup-${ groupId }` }
					>
						{ definitions.map(
							(
								{
									id,
									name,
									outerDimensions,
									innerDimensions,
									boxWeight,
								},
								index
							) => {
								const isPackageFavorite =
									predefinedPackages.includes( id );
								return (
									<Item key={ `${ id }-${ index }` }>
										<RadioControl
											title={ name }
											onChange={ selectPackage }
											options={ [
												{
													label: (
														<section>
															<Text
																truncate
																title={ name }
															>
																{ name }
															</Text>
															<span>
																{ outerDimensions ??
																	innerDimensions }{ ' ' }
																{
																	dimensionsUnit
																}
															</span>
															<span>
																{ boxWeight }
																{ weightUnit }
															</span>
															<Button
																icon={
																	isPackageFavorite
																		? starFilled
																		: starEmpty
																}
																className={
																	isPackageFavorite
																		? 'is-selected'
																		: ''
																}
																title={ __(
																	'By selecting this package, you add it to the saved templates section.',
																	'woocommerce-shipping'
																) }
																disabled={
																	isUpdating
																}
																onClick={ () => {
																	updatePredefined(
																		id,
																		isPackageFavorite
																	);
																	const tracksProperties =
																		{
																			carrier_id:
																				carrierId,
																			package_id:
																				id,
																			is_favorite:
																				! isPackageFavorite,
																		};
																	recordEvent(
																		'label_purchase_package_favorite_clicked',
																		tracksProperties
																	);
																} }
															/>
														</section>
													),
													value: id,
												},
											] }
											selected={ selectedPackage?.id }
											disabled={ isFetching }
										/>
									</Item>
								);
							}
						) }
					</ItemGroup>
				</PanelBody>
			</Panel>
		)
	);
};
