import {
	Button,
	Dropdown,
	Flex,
	MenuGroup,
	MenuItem,
	__experimentalText as Text,
	__experimentalScrollable as Scrollable,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { check, chevronDown, chevronUp } from '@wordpress/icons';
import { CarrierIcon } from 'components/carrier-icon';
import {
	CARRIER_ID_TO_NAME,
	TAB_NAMES,
} from 'components/label-purchase/packages';
import {
	AvailablePackages,
	Package as PackageType,
	CustomPackage as CustomPackageType,
} from 'types';
import {
	getAvailableCarrierPackages,
	getSelectedCarrierIdFromPackage,
} from 'utils';

interface PackageTypeSelectProps {
	currentPackageTab: string;
	setCurrentPackageTab: ( tabName: string ) => void;
	selectedPackage?: PackageType | CustomPackageType | null;
	setSelectedPackage?: ( pkg: PackageType ) => void;
}

const PackageLine = ( {
	provider,
	pkg,
}: {
	provider: string;
	pkg: PackageType & { maxWeight?: number };
} ) => {
	return (
		<Flex
			direction="row"
			wrap={ false }
			gap={ 2 }
			align="center"
			justify="flex-start"
		>
			<CarrierIcon carrier={ provider } size={ 'small' } />
			{ pkg.name }
			<Text
				size={ 13 }
				variant="muted"
				style={ {
					whiteSpace: 'nowrap',
				} }
			>
				{ pkg.outerDimensions }
			</Text>
			{ pkg.maxWeight && (
				<>
					<Text size="13">•</Text>
					<Text
						size={ 13 }
						variant="muted"
						style={ {
							whiteSpace: 'nowrap',
						} }
					>
						{ pkg.maxWeight &&
							sprintf(
								/* translators: %s: weight in lbs */
								__( '%slb', 'woocommerce-shipping' ),
								String( pkg.maxWeight )
							) }
					</Text>
				</>
			) }
		</Flex>
	);
};

const PackageTypeSelect = ( {
	currentPackageTab,
	setCurrentPackageTab,
	selectedPackage,
	setSelectedPackage,
}: PackageTypeSelectProps ) => {
	const availableCarrierPackages: AvailablePackages =
		getAvailableCarrierPackages();
	return (
		<Flex direction={ 'column' } gap={ 1 }>
			<Text size={ 11 } weight={ 500 } lineHeight={ '24px' } upperCase>
				{ __( 'Choose Package', 'woocommerce-shipping' ) }
			</Text>
			<Dropdown
				popoverProps={ {
					placement: 'bottom-start',
					resize: false,
					shift: false,
					inline: true,
					noArrow: true,
				} }
				style={ { width: '100%' } }
				renderToggle={ ( { isOpen, onToggle } ) => {
					return (
						<Button
							className="shipping-rates__sort"
							onClick={ onToggle }
							aria-expanded={ isOpen }
							icon={ isOpen ? chevronUp : chevronDown }
							iconPosition="right"
							style={ {
								width: '100%',
								justifyContent: 'space-between',
								boxShadow: isOpen
									? '0 0 0 1px inset #000'
									: '0 0 0 1px inset #949494',
								color: isOpen ? '#000' : '#555',
								paddingRight: '4px',
							} }
						>
							{ currentPackageTab === TAB_NAMES.CUSTOM_PACKAGE &&
								__( 'Custom package', 'woocommerce-shipping' ) }
							{ currentPackageTab === TAB_NAMES.SAVED_TEMPLATES &&
								__(
									'Saved templates',
									'woocommerce-shipping'
								) }
							{ currentPackageTab === TAB_NAMES.CARRIER_PACKAGE &&
								selectedPackage && (
									<PackageLine
										provider={
											getSelectedCarrierIdFromPackage(
												availableCarrierPackages,
												selectedPackage.id
											) ?? ''
										}
										pkg={ selectedPackage as PackageType }
									/>
								) }
						</Button>
					);
				} }
				renderContent={ ( { onClose } ) => (
					<Scrollable style={ { maxHeight: '300px' } }>
						<Flex
							direction={ 'column' }
							style={ {
								width: 'min-content',
							} }
							gap={ 0 }
						>
							<MenuGroup>
								<MenuItem
									onClick={ () => {
										setCurrentPackageTab(
											TAB_NAMES.CUSTOM_PACKAGE
										);
										onClose();
									} }
									role="menuitemradio"
									isSelected={
										currentPackageTab ===
										TAB_NAMES.CUSTOM_PACKAGE
									}
								>
									{ __(
										'Custom package',
										'woocommerce-shipping'
									) }
								</MenuItem>
							</MenuGroup>
							{ Object.keys( availableCarrierPackages ).length >
								0 &&
								Object.keys( availableCarrierPackages ).map(
									( provider: string ) => (
										<MenuGroup
											key={ provider }
											label={
												CARRIER_ID_TO_NAME[
													provider as keyof typeof CARRIER_ID_TO_NAME
												]
											}
										>
											{ Object.keys(
												availableCarrierPackages[
													provider
												]
											).map( ( pkgType ) => {
												const pkgDefinitions =
													availableCarrierPackages[
														provider
													][ pkgType ].definitions;
												return (
													<section key={ pkgType }>
														{ pkgDefinitions.map(
															(
																pkg: PackageType & {
																	maxWeight?: number;
																}
															) => (
																<MenuItem
																	key={
																		pkg.id
																	}
																	onClick={ () => {
																		setSelectedPackage?.(
																			pkg
																		);
																		setCurrentPackageTab(
																			TAB_NAMES.CARRIER_PACKAGE
																		);
																		onClose();
																	} }
																	role="menuitemradio"
																	icon={
																		currentPackageTab ===
																			TAB_NAMES.CARRIER_PACKAGE &&
																		selectedPackage?.id ===
																			pkg.id
																			? check
																			: undefined
																	}
																	iconPosition="right"
																	isSelected={
																		currentPackageTab ===
																			TAB_NAMES.CARRIER_PACKAGE &&
																		selectedPackage?.id ===
																			pkg.id
																	}
																>
																	<PackageLine
																		provider={
																			provider
																		}
																		pkg={
																			pkg
																		}
																	/>
																</MenuItem>
															)
														) }
													</section>
												);
											} ) }
										</MenuGroup>
									)
								) }
							{ /*<MenuGroup>
							<MenuItem
								onClick={ () => {
									setCurrentPackageTab(
										TAB_NAMES.SAVED_TEMPLATES
									);
									onClose();
								} }
								role="menuitemradio"
								isSelected={
									currentPackageTab ===
									TAB_NAMES.SAVED_TEMPLATES
								}
							>
								{ __(
									'Saved templates',
									'woocommerce-shipping'
								) }
							</MenuItem>
						</MenuGroup>*/ }
						</Flex>
					</Scrollable>
				) }
			/>
		</Flex>
	);
};

export default PackageTypeSelect;
