import {
	__experimentalHeading as Heading,
	Flex,
	TabPanel,
	Notice,
} from '@wordpress/components';
import { useRef, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { select, dispatch } from '@wordpress/data';
import { isEmpty } from 'lodash';
import { labelPurchaseStore } from 'data/label-purchase';
import { TAB_NAMES } from './constants';
import { CarrierPackage, CustomPackage, SavedTemplates } from './tab-views';
import { useLabelPurchaseContext } from 'context/label-purchase';
import { recordEvent } from 'utils/tracks';
import { mainModalContentSelector } from '../constants';
import { PACKAGE_SECTION } from '../essential-details/constants';
import { PromoRemainingCount } from '../promo';
import { PackagesCard } from '../design-next/cards/packages-card';

const tabViews = {
	[ TAB_NAMES.CUSTOM_PACKAGE ]: CustomPackage,
	[ TAB_NAMES.CARRIER_PACKAGE ]: CarrierPackage,
	[ TAB_NAMES.SAVED_TEMPLATES ]: SavedTemplates,
};
export const Packages = () => {
	const {
		packages: {
			getCustomPackage,
			setCustomPackage,
			getSelectedPackage,
			setSelectedPackage,
			setCurrentPackageTab,
			isPackageSpecified,
			currentPackageTab,
		},
		shipment: { shipments, currentShipmentId },
		essentialDetails: { focusArea: essentialDetailsFocusArea },
		rates: { removeSelectedRate },
		nextDesign,
	} = useLabelPurchaseContext();

	const selectedPackage = getSelectedPackage();
	const rawPackageData = getCustomPackage();
	const wrapperRef = useRef();
	const tabsRef = useRef( null );

	const tabSelectionClick = ( tabName ) => {
		// Prevent the click to be triggered on initial load, but also on each re-render.
		if ( currentPackageTab === tabName ) {
			return;
		}

		const availableRates =
			select( labelPurchaseStore ).getRatesForShipment(
				currentShipmentId
			);

		if ( ! isEmpty( availableRates ) ) {
			dispatch( labelPurchaseStore ).ratesReset();
		}

		removeSelectedRate();

		setCurrentPackageTab( tabName );

		recordEvent( 'label_purchase_package_tab_clicked', {
			tab_name: tabName,
		} );
	};

	/**
	 * Artificially simulate a click on a tab.
	 *
	 * Since <TabPanel> is an uncontrolled component then we cannot just change the active tab by changing
	 * the state of "currentPackageTab", so this is a workaround to achieve the same goal.
	 *
	 * Gutenberg has an experimental <Tabs> component that allows "controlled mode" that we can replace
	 * this solution with in the future: https://github.com/WordPress/gutenberg/tree/trunk/packages/components/src/tabs
	 *
	 * @param {string} tabName The name of the tab to simulate a click on.
	 */
	const simulateTabChange = ( tabName ) => {
		if ( tabsRef.current ) {
			const tabButton = tabsRef.current.querySelector(
				`button[id$="${ tabName }"]`
			);
			if ( tabButton ) {
				tabButton.click();
			}
		}
	};

	useEffect( () => {
		if (
			essentialDetailsFocusArea === PACKAGE_SECTION &&
			document.querySelector( mainModalContentSelector )
		) {
			if ( ! wrapperRef.current ) {
				return;
			}
			document.querySelector( mainModalContentSelector )?.scrollTo( {
				left: 0,
				// We have to offset the height of the header, so it doesn't overlap our message.
				// If there's more than one shipment being created, then we also have to take the
				// "Shipment tabs" component into account.
				// @todo We could make this smarter by finding the height with JS, but the heights
				//       are a fixed size, so we're keeping it dumb for now for simplicity.
				top:
					wrapperRef.current.offsetTop -
					( Object.keys( shipments ).length > 1 ? 140 : 72 ),
				behavior: 'smooth',
			} );
		}
	}, [ essentialDetailsFocusArea, shipments ] );

	return nextDesign ? (
		<PackagesCard />
	) : (
		<>
			<Flex className="packages-header" ref={ wrapperRef }>
				<Heading level={ 3 }>
					{ __( 'Package', 'woocommerce-shipping' ) }
				</Heading>
				<PromoRemainingCount />
			</Flex>
			{ ! isPackageSpecified() && (
				<Notice
					status="info"
					isDismissible={ false }
					className="packages-notice"
				>
					<strong>
						{ __(
							'Get shipping rates for your package.',
							'woocommerce-shipping'
						) }
					</strong>{ ' ' }
					{ __(
						"Enter your package's dimensions or pick a carrier package option to see the available shipping rates.",
						'woocommerce-shipping'
					) }
				</Notice>
			) }
			<Flex className="shipment-package">
				<TabPanel
					ref={ tabsRef }
					onSelect={ tabSelectionClick }
					activeClass="active-package-tab"
					className="package-tabs"
					initialTabName={ currentPackageTab }
					tabs={ [
						{
							name: `${ TAB_NAMES.CUSTOM_PACKAGE }`,
							title: __(
								'Custom package',
								'woocommerce-shipping'
							),
						},
						{
							name: `${ TAB_NAMES.CARRIER_PACKAGE }`,
							title: __(
								'Carrier package',
								'woocommerce-shipping'
							),
						},
						{
							name: `${ TAB_NAMES.SAVED_TEMPLATES }`,
							title: __(
								'Saved templates',
								'woocommerce-shipping'
							),
						},
					] }
					children={ ( { name } ) => {
						const TabView = tabViews[ name ];
						return (
							<TabView
								rawPackageData={ rawPackageData }
								setRawPackageData={ setCustomPackage }
								selectedPackage={ selectedPackage }
								setSelectedPackage={ setSelectedPackage }
								setTab={ simulateTabChange }
							/>
						);
					} }
				/>
			</Flex>
		</>
	);
};
