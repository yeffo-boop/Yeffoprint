import React from 'react';
import {
	__experimentalSpacer as Spacer,
	Button,
	Dropdown,
	Flex,
	MenuItemsChoice,
} from '@wordpress/components';
import { chevronDown } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';
import { memo, useCallback, useEffect, useState } from '@wordpress/element';
import { dispatch, useSelect } from '@wordpress/data';
import { labelPurchaseStore } from 'data/label-purchase';
import { Conditional } from 'components/HOC';
import { CustomPackage, Package } from 'types';
import { TemplateRow } from './saved-templates/template-row';
import { NoSavedTemplates } from './saved-templates/no-saved-templates';
import { useLabelPurchaseContext } from 'context/label-purchase';
import { FetchNotice } from './fetch-notice';
import { TotalWeight } from '../../total-weight';
import { CustomsWeightWarning } from './customs-weight-warning';
import { GetRatesButton } from '../../get-rates-button';
import { recordEvent } from 'utils';
import { withBoundary } from 'components/HOC/error-boundary';
import { usePackageState } from '../../hooks';
import { ConfirmPackageDeletion } from './saved-templates/confirm-package-deletion';
import { DELETION_EVENTS, trackPackageDeletion } from '../utils';
import { CUSTOM_PACKAGE_TYPES, PACKAGE_CATEGORIES } from '../constants';

interface SavedTemplatesProps {
	savedPackages: Package[] | CustomPackage[];
	selectedPackage:
		| ReturnType<
				ReturnType< typeof usePackageState >[ 'getSelectedPackage' ]
		  >
		| ReturnType<
				ReturnType< typeof usePackageState >[ 'getCustomPackage' ]
		  >;
	setSelectedPackage: ReturnType<
		typeof usePackageState
	>[ 'setSelectedPackage' ];
}

export const SavedTemplates = withBoundary(
	Conditional(
		( forwardedProps: Record< string, unknown > ) => {
			const savedPackages = useSelect(
				( select ) => select( labelPurchaseStore ).getSavedPackages(),
				[]
			);
			return {
				render: savedPackages.length === 0,
				props: { ...forwardedProps, savedPackages },
			};
		},
		NoSavedTemplates,
		// @ts-expect-error // Conditional is writen in js
		memo(
			( {
				savedPackages,
				selectedPackage,
				setSelectedPackage,
			}: SavedTemplatesProps ) => {
				const {
					rates: { isFetching, fetchRates, errors, availableRates },
					customs: { hasErrors: hasCustomsErrors },
					hazmat: { isHazmatSpecified },
					packages: { isSelectedASavedPackage, getPackageForRequest },
					weight: { getShipmentTotalWeight },
					labels: { hasMissingPurchase },
					shipment: { isExtraLabelPurchaseValid },
				} = useLabelPurchaseContext();
				const [ deletablePackage, setDeletablePackage ] = useState<
					Package | CustomPackage | false
				>( false );

				const [ isDeletingPackage, setIsDeletingPackage ] =
					useState( false );

				const isExtraLabelPurchase = () => {
					return ! hasMissingPurchase();
				};

				const isGetRatesButtonDisabled = Boolean(
					! selectedPackage ||
						isFetching ||
						Boolean( errors.totalWeight ) ||
						hasCustomsErrors() ||
						! isHazmatSpecified() ||
						! getShipmentTotalWeight() ||
						( isExtraLabelPurchase() &&
							! isExtraLabelPurchaseValid() )
				);

				const onConfirmPackageDeletion = async (
					pkg: Package | CustomPackage
				) => {
					setIsDeletingPackage( true );
					trackPackageDeletion( DELETION_EVENTS.CONFIRMED, pkg );

					await dispatch( labelPurchaseStore ).deletePackage(
						pkg.id,
						pkg.isUserDefined
							? PACKAGE_CATEGORIES.CUSTOM
							: PACKAGE_CATEGORIES.PREDEFINED
					);

					setIsDeletingPackage( false );
					setDeletablePackage( false );
				};

				const onDeletePackage = ( pkg: Package | CustomPackage ) => {
					setDeletablePackage( pkg );
				};

				const onCancelDeletion = () => {
					if ( deletablePackage ) {
						trackPackageDeletion(
							DELETION_EVENTS.CANCELLED,
							deletablePackage
						);
					}

					setDeletablePackage( false );
				};

				const options = savedPackages.map( ( pkg ) => ( {
					label: (
						<TemplateRow
							pkg={ pkg }
							key={ pkg.name + pkg.id }
							isBusy={ isFetching }
							deletePackage={ onDeletePackage }
						/>
					),
					value: pkg.id,
				} ) );

				const getOptionById = useCallback(
					( current: string ) =>
						savedPackages.find(
							( option ) => option.id === current
						)!,
					[ savedPackages ]
				);

				const select = useCallback(
					( id: string ) => {
						setSelectedPackage( getOptionById( id ) );
					},
					[ getOptionById, setSelectedPackage ]
				);

				const getRates = useCallback(
					( userInitiated = true ) => {
						/**
						 * getPackageForRequest fills isLetter for custom packages
						 */
						const selectedPackageForTracks = getPackageForRequest();
						const tracksProperties = {
							package_id: selectedPackageForTracks?.id,
							is_letter:
								selectedPackageForTracks?.type ===
								CUSTOM_PACKAGE_TYPES.ENVELOPE,
							width: selectedPackageForTracks?.width,
							height: selectedPackageForTracks?.height,
							length: selectedPackageForTracks?.length,
							template_name: selectedPackageForTracks?.name,
							is_saved_template: true,
						};
						recordEvent(
							'label_purchase_get_rates_clicked',
							tracksProperties
						);
						if ( selectedPackage ) {
							fetchRates( selectedPackage, { userInitiated } );
						}
					},
					[ selectedPackage, fetchRates, getPackageForRequest ]
				);

				/**
				 * Automatically get rates on load when a package when all the
				 * conditions for enabling the get rates button are met.
				 */
				useEffect( () => {
					if (
						! isGetRatesButtonDisabled &&
						! availableRates &&
						! errors.endpoint // It should bail if there are errors reported by the endpoint
					) {
						// System-driven refetch: don't clear purchase/status errors.
						getRates( false );
					}
					// We only want to run this if no rates available
					// eslint-disable-next-line react-hooks/exhaustive-deps
				}, [ isGetRatesButtonDisabled, availableRates ] );

				return (
					<>
						<label htmlFor="saved-templates">
							{ __( 'Package template', 'woocommerce-shipping' ) }
						</label>
						<Dropdown
							className="saved-templates"
							contentClassName="saved-template-options"
							popoverProps={ {
								placement: 'bottom-start',
								noArrow: false,
								resize: true,
								shift: true,
								inline: true,
							} }
							renderToggle={ ( { isOpen, onToggle } ) => (
								<Button
									onClick={ onToggle }
									aria-expanded={ isOpen }
									variant="secondary"
									icon={ chevronDown }
									className="saved-template__toggle"
									disabled={ isFetching }
									aria-disabled={ isFetching }
								>
									{ ! selectedPackage ||
									! isSelectedASavedPackage() ? (
										__(
											'Please select',
											'woocommerce-shipping'
										)
									) : (
										<section>
											<TemplateRow
												pkg={ selectedPackage }
												isBusy={ isFetching }
											/>
										</section>
									) }
								</Button>
							) }
							renderContent={ ( { onToggle } ) => (
								<MenuItemsChoice
									// @ts-ignore-next-line - TS nags about Element being set for label value which is wrongly defined as string on the type
									choices={ options }
									onSelect={ ( value: string ) => {
										select( value );
										onToggle();
									} }
									value={ selectedPackage?.name ?? '' }
								/>
							) }
						/>
						<Spacer marginTop={ 4 } marginBottom={ 0 } />
						<Flex align="flex-end" gap={ 6 }>
							<TotalWeight
								packageWeight={ Number(
									selectedPackage?.boxWeight ?? '0'
								) }
							/>

							<GetRatesButton
								onClick={ getRates }
								isBusy={ isFetching }
								disabled={ isGetRatesButtonDisabled }
							/>
						</Flex>
						<CustomsWeightWarning />
						<FetchNotice />
						{ deletablePackage && (
							<ConfirmPackageDeletion
								pkg={ deletablePackage }
								onDelete={ onConfirmPackageDeletion }
								close={ onCancelDeletion }
								isBusy={ isDeletingPackage }
							/>
						) }
					</>
				);
			}
		)
	)
)( 'SavedTemplates' );
