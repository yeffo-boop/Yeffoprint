import { isEmpty } from 'lodash';
import {
	useCallback,
	useMemo,
	useState,
	useEffect,
	useRef,
} from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { labelPurchaseStore } from 'data/label-purchase';
import {
	getAccountSettings,
	getAvailableCarrierPackages,
	getAvailablePackagesById,
	getPackageDimensions,
	getWeightUnit,
	convertWeightToUnit,
	WEIGHT_UNITS,
} from 'utils';
import {
	CustomPackage,
	Package,
	ShipmentItem,
	ReturnShipmentInfo,
} from 'types';
import { defaultCustomPackageData } from '../constants';
import {
	CUSTOM_BOX_ID_PREFIX,
	CUSTOM_PACKAGE_TYPES,
	TAB_NAMES,
} from '../packages';

export const getInitialPackageAndTab = (
	savedPackages: Package[]
): {
	initialTab: string;
	initialPackage: Package | null;
} => {
	const isUseLastBoxEnabled =
		getAccountSettings()?.purchaseSettings?.use_last_package;
	const lastBoxId = getAccountSettings()?.userMeta?.last_box_id;

	if ( isUseLastBoxEnabled && lastBoxId ) {
		const matchingSavedPackage = savedPackages.find(
			( { id } ) => id === lastBoxId
		);

		if ( matchingSavedPackage ) {
			return {
				initialTab: TAB_NAMES.SAVED_TEMPLATES,
				initialPackage: {
					...matchingSavedPackage,
					...( getPackageDimensions( matchingSavedPackage ) || {} ),
				},
			};
		}

		if ( getAvailableCarrierPackages() ) {
			const allCarrierPackagesById = getAvailablePackagesById();
			if ( Object.keys( allCarrierPackagesById ).includes( lastBoxId ) ) {
				return {
					initialTab: TAB_NAMES.CARRIER_PACKAGE,
					initialPackage: {
						...allCarrierPackagesById[ lastBoxId ],
						...( getPackageDimensions(
							allCarrierPackagesById[ lastBoxId ]
						) || {} ),
					},
				};
			}
		}
	}

	return {
		initialTab: TAB_NAMES.CUSTOM_PACKAGE,
		initialPackage: null,
	};
};

export function usePackageState(
	currentShipmentId: string,
	shipments: Record< string, ShipmentItem[] >,
	totalWeight: number,
	returnShipments?: Record< string, ReturnShipmentInfo >,
	setShipmentTotalWeight?: ( weight: number ) => void,
	getShipmentWeight?: () => number
) {
	const savedPackages = useSelect(
		( select ) => select( labelPurchaseStore ).getSavedPackages(),
		// eslint-disable-next-line react-hooks/exhaustive-deps -- we want this to update when the shipmentId changes
		[ currentShipmentId ]
	);
	const { initialTab, initialPackage } = useMemo(
		() => getInitialPackageAndTab( savedPackages ),
		[ savedPackages ]
	);
	const [ initialCarrierTab, setInitialCarrierTab ] = useState< string >();

	// Initialize tab state only once, and don't reset it when initialTab changes
	const [ currentPackageTab, setCurrentPackageTabState ] = useState(
		() => initialTab
	);

	// Prevent initialTab changes from overriding our restoration
	const previousInitialTab = useRef( initialTab );
	useEffect( () => {
		// Only update tab from initialTab if this is the very first time or if we're not in a return shipment
		if ( previousInitialTab.current !== initialTab ) {
			// Check if this is a return shipment - if so, don't override with initialTab
			const returnShipment = returnShipments?.[ currentShipmentId ];
			if ( ! returnShipment?.isReturn ) {
				setCurrentPackageTabState( initialTab );
			}

			previousInitialTab.current = initialTab;
		}
	}, [ initialTab, currentShipmentId, returnShipments, currentPackageTab ] );

	// Wrap setCurrentPackageTab for consistent interface
	const setCurrentPackageTab = useCallback( ( tab: string ) => {
		setCurrentPackageTabState( tab );
	}, [] );
	const [ customPackageData, setCustomPackageData ] = useState<
		Record< string, CustomPackage >
	>( {
		[ currentShipmentId ]: defaultCustomPackageData,
	} );
	const initialPackages = Object.keys( shipments ).reduce(
		( packages: Record< string, Package | null >, id: string ) => {
			packages[ id ] = initialPackage;
			return packages;
		},
		{}
	);
	const [ selectedPackage, setSelected ] =
		useState< Record< string, Package | CustomPackage | null > >(
			initialPackages
		);

	const selectedBoxWeight = Number(
		( selectedPackage[ currentShipmentId ] ?? initialPackage )?.boxWeight ??
			0
	);
	const [ appliedBoxWeight, setAppliedBoxWeight ] = useState( 0 );

	if ( selectedBoxWeight !== appliedBoxWeight ) {
		setAppliedBoxWeight( selectedBoxWeight );
		setShipmentTotalWeight?.(
			( getShipmentWeight?.() ?? 0 ) + selectedBoxWeight
		);
	}

	const setCustomPackage = useCallback(
		( data: CustomPackage ) => {
			setCustomPackageData( ( prev ) => ( {
				...( prev || {} ),
				[ currentShipmentId ]: data,
			} ) );
		},
		[ currentShipmentId ]
	);

	const hasDistinctDimensions = (
		pkg: CustomPackage | Package
	): pkg is CustomPackage =>
		Object.hasOwn( pkg, 'length' ) &&
		Object.hasOwn( pkg, 'width' ) &&
		Object.hasOwn( pkg, 'height' );

	const setSelectedPackage = useCallback(
		( pkg: Package | CustomPackage ) => {
			setSelected( ( prev ) => ( {
				...( prev || {} ),
				[ currentShipmentId ]: {
					...pkg,
					...( hasDistinctDimensions( pkg )
						? pkg
						: getPackageDimensions( pkg ) || {} ),
				},
			} ) );
		},
		[ currentShipmentId ]
	);

	const getCustomPackage = useCallback( () => {
		if ( customPackageData[ currentShipmentId ] ) {
			return {
				...customPackageData[ currentShipmentId ],
				isLetter:
					customPackageData[ currentShipmentId ].type ===
					CUSTOM_PACKAGE_TYPES.ENVELOPE,
			};
		}
		return defaultCustomPackageData;
	}, [ currentShipmentId, customPackageData ] );

	const getSelectedPackage = useCallback( () => {
		const shipmentPackage =
			selectedPackage[ currentShipmentId ] ?? initialPackage;
		return shipmentPackage;
	}, [ selectedPackage, currentShipmentId, initialPackage ] );

	const isCustomPackageTab = useCallback(
		() => currentPackageTab === TAB_NAMES.CUSTOM_PACKAGE,
		[ currentPackageTab ]
	);
	const getPackageForRequest = useCallback(
		() =>
			isCustomPackageTab()
				? getCustomPackage()
				: ( getSelectedPackage() as Package ),
		[ getCustomPackage, getSelectedPackage, isCustomPackageTab ]
	);

	const isSelectedASavedPackage = useCallback( () => {
		return savedPackages.some( ( p ) => p.id === getSelectedPackage()?.id );
	}, [ savedPackages, getSelectedPackage ] );

	const isPackageSpecified = () => {
		if ( totalWeight === 0 ) {
			return false;
		}

		if ( currentPackageTab === TAB_NAMES.CUSTOM_PACKAGE ) {
			const { width, height, length } = getCustomPackage();
			return [ width, height, length ]
				.map( parseFloat )
				.every( ( dimension ) => dimension > 0 );
		}
		if ( currentPackageTab === TAB_NAMES.CARRIER_PACKAGE ) {
			return (
				! isEmpty( getSelectedPackage() ) &&
				! getSelectedPackage()?.id.includes( CUSTOM_BOX_ID_PREFIX )
			);
		}

		// currentPackageTab === TAB_NAMES.SAVED_TEMPLATES
		return ! isEmpty( getSelectedPackage() ) && isSelectedASavedPackage();
	};

	/**
	 * Helper function to create a custom package from preserved label data
	 */
	const createCustomPackageFromLabel = useCallback(
		( preservedPackage: ReturnShipmentInfo[ 'preservedPackage' ] ) => {
			if ( ! preservedPackage ) {
				return null;
			}

			// Convert package weight from stored unit (oz) to store's configured unit
			let boxWeight = preservedPackage.packageWeight ?? 0;
			if ( boxWeight > 0 ) {
				const storedUnit =
					( preservedPackage.packageWeightUnit as
						| ( typeof WEIGHT_UNITS )[ keyof typeof WEIGHT_UNITS ]
						| undefined ) ?? 'oz';
				const storeUnit = getWeightUnit();
				boxWeight = convertWeightToUnit(
					boxWeight,
					storedUnit,
					storeUnit
				);
			}

			return {
				length: String( preservedPackage.packageLength ?? '' ),
				width: String( preservedPackage.packageWidth ?? '' ),
				height: String( preservedPackage.packageHeight ?? '' ),
				boxWeight,
				id: CUSTOM_BOX_ID_PREFIX,
				type: CUSTOM_PACKAGE_TYPES.BOX,
				dimensions: `${ preservedPackage.packageLength ?? 0 } x ${
					preservedPackage.packageWidth ?? 0
				} x ${ preservedPackage.packageHeight ?? 0 }`,
				isUserDefined: true,
				name: 'Return Package (from original)',
			} as CustomPackage;
		},
		[]
	);

	/**
	 * Helper function to check if preserved package has label data
	 */
	const hasLabelPackageData = useCallback(
		( preservedPackage: ReturnShipmentInfo[ 'preservedPackage' ] ) => {
			if ( ! preservedPackage ) {
				return false;
			}
			return (
				preservedPackage.packageWeight !== undefined ||
				preservedPackage.packageLength !== undefined ||
				preservedPackage.packageWidth !== undefined ||
				preservedPackage.packageHeight !== undefined
			);
		},
		[]
	);

	/**
	 * Effect to restore package data from label information
	 */
	useEffect( () => {
		if ( ! returnShipments ) {
			return;
		}

		const returnShipment = returnShipments[ currentShipmentId ];
		const { preservedPackage } = returnShipment || {};

		// Only proceed if this is a return shipment with preserved package data
		if ( ! returnShipment?.isReturn || ! preservedPackage ) {
			return;
		}

		if ( hasLabelPackageData( preservedPackage ) ) {
			// Check if this is a saved template first
			if ( preservedPackage.packageName && savedPackages.length > 0 ) {
				const matchingSavedPackage = savedPackages.find(
					( pkg ) => pkg.name === preservedPackage.packageName
				);

				if ( matchingSavedPackage ) {
					// Use the saved template
					setCurrentPackageTab( TAB_NAMES.SAVED_TEMPLATES );
					setSelectedPackage( matchingSavedPackage );

					// Simulate a tab click to update the TabPanel UI
					setTimeout( () => {
						const tabsContainer =
							document.querySelector( '.package-tabs' );
						if ( tabsContainer ) {
							const tabButton = tabsContainer.querySelector(
								`button[id$="${ TAB_NAMES.SAVED_TEMPLATES }"]`
							);
							if (
								tabButton &&
								tabButton instanceof HTMLButtonElement
							) {
								tabButton.click();
							}
						}
					}, 100 );

					// Set total weight if available
					if (
						preservedPackage.packageWeight !== undefined &&
						setShipmentTotalWeight
					) {
						setShipmentTotalWeight(
							preservedPackage.packageWeight
						);
					}
					return;
				}
			}

			// Check if this might be a carrier package using packageId
			if ( preservedPackage.packageId ) {
				const allCarrierPackagesById = getAvailablePackagesById();

				// Try both string and number versions for compatibility
				const carrierPackage =
					allCarrierPackagesById[ preservedPackage.packageId ];
				const carrierPackageStr =
					allCarrierPackagesById[
						String( preservedPackage.packageId )
					];
				const carrierPackageNum =
					allCarrierPackagesById[
						Number( preservedPackage.packageId )
					];

				const finalCarrierPackage =
					carrierPackage ?? carrierPackageStr ?? carrierPackageNum;

				if ( finalCarrierPackage ) {
					// Find which carrier this package belongs to
					const carrierPackages = getAvailableCarrierPackages();
					let foundCarrierId: string | undefined;
					for ( const [ carrierId, groups ] of Object.entries(
						carrierPackages
					) ) {
						for ( const group of Object.values(
							groups as Record< string, unknown >
						) ) {
							const typedGroup = group as {
								definitions?: { id: string | number }[];
							};
							if (
								typedGroup.definitions?.some(
									( pkg ) =>
										pkg.id === preservedPackage.packageId
								)
							) {
								foundCarrierId = carrierId;
								break;
							}
						}
						if ( foundCarrierId ) {
							break;
						}
					}

					// Switch to carrier package tab
					setCurrentPackageTab( TAB_NAMES.CARRIER_PACKAGE );
					setSelectedPackage( finalCarrierPackage );

					// Set the initial carrier tab so the correct carrier is selected
					if ( foundCarrierId ) {
						setInitialCarrierTab( foundCarrierId );
					}

					// Simulate tab click for carrier packages
					setTimeout( () => {
						const tabsContainer =
							document.querySelector( '.package-tabs' );
						if ( tabsContainer ) {
							const tabButton = tabsContainer.querySelector(
								`button[id$="${ TAB_NAMES.CARRIER_PACKAGE }"]`
							);
							if (
								tabButton &&
								tabButton instanceof HTMLButtonElement
							) {
								tabButton.click();

								// After the main tab is clicked, we need to click the carrier sub-tab
								setTimeout( () => {
									const carrierTabsContainer =
										document.querySelector(
											'.carrier-package-tabs'
										);
									if (
										carrierTabsContainer &&
										foundCarrierId
									) {
										const carrierTabButton =
											carrierTabsContainer.querySelector(
												`button[id$="${ foundCarrierId }"]`
											);
										if (
											carrierTabButton &&
											carrierTabButton instanceof
												HTMLButtonElement
										) {
											carrierTabButton.click();
										}
									}
								}, 100 );
							}
						}
					}, 100 );

					// Set total weight if available
					if (
						preservedPackage.packageWeight !== undefined &&
						setShipmentTotalWeight
					) {
						setShipmentTotalWeight(
							preservedPackage.packageWeight
						);
					}
					return;
				}
				// Fallback: search by package name if packageId doesn't work
				if ( preservedPackage.packageName ) {
					const carrierPackages = getAvailableCarrierPackages();
					let foundPackage:
						| { id: string | number; name: string }
						| undefined;
					let foundCarrierId: string | undefined;

					searchLoop: for ( const [
						carrierId,
						groups,
					] of Object.entries( carrierPackages ) ) {
						for ( const group of Object.values(
							groups as Record< string, unknown >
						) ) {
							const typedGroup = group as {
								definitions?: {
									id: string | number;
									name: string;
								}[];
							};
							if ( typedGroup.definitions ) {
								foundPackage = typedGroup.definitions.find(
									( pkg ) =>
										pkg.name ===
										preservedPackage.packageName
								);
								if ( foundPackage ) {
									foundCarrierId = carrierId;
									break searchLoop;
								}
							}
						}
					}

					if ( foundPackage && foundCarrierId ) {
						// Switch to carrier package tab
						setCurrentPackageTab( TAB_NAMES.CARRIER_PACKAGE );
						setSelectedPackage( foundPackage as Package );
						setInitialCarrierTab( foundCarrierId );

						// Simulate tab click for carrier packages
						setTimeout( () => {
							const tabsContainer =
								document.querySelector( '.package-tabs' );
							if ( tabsContainer ) {
								const tabButton = tabsContainer.querySelector(
									`button[id$="${ TAB_NAMES.CARRIER_PACKAGE }"]`
								);
								if (
									tabButton &&
									tabButton instanceof HTMLButtonElement
								) {
									tabButton.click();

									// After the main tab is clicked, we need to click the carrier sub-tab
									setTimeout( () => {
										const carrierTabsContainer =
											document.querySelector(
												'.carrier-package-tabs'
											);
										if (
											carrierTabsContainer &&
											foundCarrierId
										) {
											const carrierTabButton =
												carrierTabsContainer.querySelector(
													`button[id$="${ foundCarrierId }"]`
												);
											if (
												carrierTabButton &&
												carrierTabButton instanceof
													HTMLButtonElement
											) {
												carrierTabButton.click();
											}
										}
									}, 100 );
								}
							}
						}, 100 );

						// Set total weight if available
						if (
							preservedPackage.packageWeight !== undefined &&
							setShipmentTotalWeight
						) {
							setShipmentTotalWeight(
								preservedPackage.packageWeight
							);
						}
						return;
					}
				}
			}

			// Otherwise create a custom package from the preserved label data
			setCurrentPackageTab( TAB_NAMES.CUSTOM_PACKAGE );

			const customPackageFromLabel =
				createCustomPackageFromLabel( preservedPackage );
			if ( customPackageFromLabel ) {
				setCustomPackage( customPackageFromLabel );
			}

			// Note: TotalWeight component will calculate and set the total weight
			// by adding item weights + boxWeight (which is now correctly converted)
		}
	}, [
		currentShipmentId,
		returnShipments,
		createCustomPackageFromLabel,
		hasLabelPackageData,
		setCustomPackage,
		setCurrentPackageTab,
		setShipmentTotalWeight,
		savedPackages,
		setSelectedPackage,
		setInitialCarrierTab,
	] );

	/**
	 * Effect to restore legacy package selection data
	 */
	useEffect( () => {
		if ( ! returnShipments ) {
			return;
		}

		const returnShipment = returnShipments[ currentShipmentId ];
		const { preservedPackage } = returnShipment || {};

		// Only proceed for return shipments with legacy package data
		if (
			! returnShipment?.isReturn ||
			! preservedPackage ||
			hasLabelPackageData( preservedPackage )
		) {
			return;
		}

		try {
			// Restore total weight if available
			if (
				preservedPackage.totalWeight !== undefined &&
				setShipmentTotalWeight
			) {
				setShipmentTotalWeight( preservedPackage.totalWeight );
			}

			// Restore package tab selection and data
			if ( preservedPackage.isCustomPackageTab ) {
				// Switch to custom package tab and restore custom package data
				setCurrentPackageTab( TAB_NAMES.CUSTOM_PACKAGE );

				if ( preservedPackage.customPackage ) {
					setCustomPackage(
						preservedPackage.customPackage as CustomPackage
					);
				}
			} else {
				// Switch to carrier package tab and restore selected package
				setCurrentPackageTab( TAB_NAMES.CARRIER_PACKAGE );

				if ( preservedPackage.selectedPackage ) {
					setSelectedPackage(
						preservedPackage.selectedPackage as Package
					);
				}
			}
		} catch {
			// Handle package restoration errors gracefully
		}
	}, [
		currentShipmentId,
		returnShipments,
		hasLabelPackageData,
		setCustomPackage,
		setSelectedPackage,
		setCurrentPackageTab,
		setShipmentTotalWeight,
	] );

	return {
		getCustomPackage,
		setCustomPackage,
		getSelectedPackage,
		setSelectedPackage,
		currentPackageTab,
		setCurrentPackageTab,
		getPackageForRequest,
		isPackageSpecified,
		isSelectedASavedPackage,
		isCustomPackageTab,
		initialCarrierTab,
		setInitialCarrierTab,
	};
}
