import {
	Button,
	Flex,
	Modal,
	Notice,
	ProgressBar,
} from '@wordpress/components';
import { __, sprintf, _n } from '@wordpress/i18n';
import { useCallback, useMemo, useState } from '@wordpress/element';
import { DataViews } from '@wordpress/dataviews/wp';
import type { Field, View } from '@wordpress/dataviews/wp';
import { Icon, caution, closeSmall } from '@wordpress/icons';
import {
	buildServiceApplyOptions,
	resolveSelectedRate,
	SERVICE_CHEAPEST,
	useAssignablePackages,
	useBulkPurchaseOrders,
	useOrderRates,
	useOriginAddress,
} from 'data/bulk-labels';
import type {
	AssignablePackage,
	BulkPurchaseOrder,
	ManualPackageSelections,
	ManualServiceSelections,
	PurchasableBulkPurchaseOrder,
	RateRequestOrder,
} from 'data/bulk-labels';
import { CarrierIcon } from 'components/carrier-icon';
import { AddressGroupingSuggestion, NeedsFixNotice } from './banners';
import { Toolbar, type ApplyOption, type FilterMode } from './toolbar';
import { ApplyDropdown } from './apply-dropdown';
import { Sidebar } from './sidebar';
import type { BulkPurchaseModalProps } from './types';
import {
	AUTO_PACKAGE_VALUE,
	MANUAL_PACKAGE_VALUE,
	formatLocality,
	getOrderEditUrl,
	toBulkRequestPackage,
	toPurchaseDestination,
	toPurchaseOrigin,
	toSelectedBatchRate,
} from './utils';
import './style.scss';

export const BulkPurchaseModal = ( {
	orderIds,
	onClose,
	onCreateLabels,
}: BulkPurchaseModalProps ) => {
	const [ filter, setFilter ] = useState< FilterMode >( 'all' );
	const [ manualSelections, setManualSelections ] =
		useState< ManualPackageSelections >( {} );

	const { isResolving, error, records, orders, grouping } =
		useBulkPurchaseOrders( orderIds, manualSelections );

	const { packages: assignablePackages } = useAssignablePackages(
		orderIds.length > 0
	);

	const handleSelectPackage = useCallback(
		( orderId: number, pkg: AssignablePackage | null ) => {
			setManualSelections( ( prev ) => {
				if ( ! pkg ) {
					if ( ! ( orderId in prev ) ) {
						return prev;
					}
					const next = { ...prev };
					delete next[ orderId ];
					return next;
				}
				return { ...prev, [ orderId ]: pkg };
			} );
		},
		[]
	);

	// "Apply to all" package control. Selecting a package broadcasts it to
	// every order; "Automatic package selection" clears all overrides so
	// each row falls back to the box-packer suggestion.
	const handleApplyPackageToAll = useCallback(
		( value: string ) => {
			if ( value === MANUAL_PACKAGE_VALUE ) {
				// Display-only state — there's nothing to apply.
				return;
			}
			if ( value === AUTO_PACKAGE_VALUE ) {
				setManualSelections( {} );
				return;
			}
			const pkg = assignablePackages.find( ( p ) => p.key === value );
			if ( ! pkg ) {
				return;
			}
			setManualSelections( () => {
				const next: ManualPackageSelections = {};
				orders.forEach( ( order ) => {
					next[ order.order_id ] = pkg;
				} );
				return next;
			} );
		},
		[ assignablePackages, orders ]
	);

	// Derive the apply-to-all selection + option list from the per-order
	// state so the toolbar control and the row dropdowns stay in lockstep.
	const { packageApplyValue, packageApplyOptions } = useMemo( () => {
		const manualForOrders = orders.filter(
			( order ) => manualSelections[ order.order_id ]
		);
		const hasAnyManual = manualForOrders.length > 0;
		const allManual =
			orders.length > 0 && manualForOrders.length === orders.length;
		const uniqueKeys = new Set(
			manualForOrders.map(
				( order ) => manualSelections[ order.order_id ].key
			)
		);
		const uniformKey =
			allManual && uniqueKeys.size === 1 ? [ ...uniqueKeys ][ 0 ] : null;

		let value: string;
		if ( ! hasAnyManual ) {
			value = AUTO_PACKAGE_VALUE;
		} else if ( uniformKey ) {
			value = uniformKey;
		} else {
			value = MANUAL_PACKAGE_VALUE;
		}

		const options: ApplyOption[] = [
			{
				label: __(
					'Automatic package selection',
					'woocommerce-shipping'
				),
				value: AUTO_PACKAGE_VALUE,
			},
			// Only offer "Customized manually" when that's actually the
			// current state — it's a status reflection, not a real choice.
			...( value === MANUAL_PACKAGE_VALUE
				? [
						{
							label: __(
								'Customized manually',
								'woocommerce-shipping'
							),
							value: MANUAL_PACKAGE_VALUE,
						},
				  ]
				: [] ),
			...assignablePackages.map( ( pkg ) => ( {
				label: pkg.dimensions
					? sprintf(
							/* translators: 1: package name, 2: package dimensions e.g. 12×9×6 */
							__( '%1$s (%2$s)', 'woocommerce-shipping' ),
							pkg.name,
							pkg.dimensions
					  )
					: pkg.name,
				value: pkg.key,
			} ) ),
		];

		return { packageApplyValue: value, packageApplyOptions: options };
	}, [ orders, manualSelections, assignablePackages ] );

	// --- Per-order rates ---------------------------------------------------

	const [ serviceApplyMode, setServiceApplyMode ] =
		useState< string >( SERVICE_CHEAPEST );
	const [ manualServiceSelections, setManualServiceSelections ] =
		useState< ManualServiceSelections >( {} );

	const { origin, error: originError } = useOriginAddress(
		orderIds.length > 0
	);

	// Resolve each order's effective box (manual pick or auto-assigned,
	// looked up for real numeric dimensions) into the rate request shape.
	// Weight comes from the order total per the spec, falling back to the
	// box tare when the order carries no item weight.
	const rateRequestOrders = useMemo< RateRequestOrder[] >( () => {
		return orders
			.map( ( order ): RateRequestOrder | null => {
				const selectedKey = order.package_display.selected_key;
				const ap = selectedKey
					? assignablePackages.find( ( p ) => p.key === selectedKey )
					: undefined;
				const meta = order.package;

				const length = ap?.length ?? meta?.length ?? 0;
				const width = ap?.width ?? meta?.width ?? 0;
				const height = ap?.height ?? meta?.height ?? 0;
				if ( length <= 0 || width <= 0 || height <= 0 ) {
					return null;
				}

				const totalWeight = order.total_weight ?? 0;
				const weight =
					totalWeight > 0
						? totalWeight
						: ap?.weight ?? meta?.weight ?? 0;

				// Shipping-context strips the recipient's first/last name
				// (it lives in `customer_name`), so the rate destination
				// has no `name` and USPS rejects it ("a name or a company
				// is required"). Backfill from the order's customer name.
				const rawDestination = ( order.destination ?? {} ) as Record<
					string,
					unknown
				>;
				const existingName =
					typeof rawDestination.name === 'string'
						? rawDestination.name.trim()
						: '';
				const customerName = order.customer_name?.trim() ?? '';
				const destinationName =
					existingName.length > 0 ? existingName : customerName;

				return {
					order_id: order.order_id,
					destination: {
						...rawDestination,
						name: destinationName,
					},
					package: {
						length,
						width,
						height,
						weight,
						box_id:
							ap?.package_id ?? meta?.box_id ?? meta?.id ?? '',
						is_letter: ap?.is_letter ?? false,
					},
				};
			} )
			.filter( ( o ): o is RateRequestOrder => o !== null );
	}, [ orders, assignablePackages ] );

	const {
		resolvingIds,
		rates,
		rateErrors,
		error: rateRequestError,
	} = useOrderRates( origin, rateRequestOrders );

	const rateRequestOrderById = useMemo( () => {
		const map = new Map< number, RateRequestOrder >();
		rateRequestOrders.forEach( ( order ) => {
			map.set( order.order_id, order );
		} );
		return map;
	}, [ rateRequestOrders ] );

	// A failed origin lookup or a top-level batch-rate rejection isn't a
	// "no rates for this order" result — surface it once at the modal
	// level so rows don't all just read "No rates available".
	const rateFetchError = originError ?? rateRequestError;

	// O(1) lookup for "is this order's rate request in flight?".
	const resolvingRateIds = useMemo(
		() => new Set( resolvingIds ),
		[ resolvingIds ]
	);

	// The rate each row defaults to, given the apply-to-all strategy and
	// any per-row manual override.
	const selectedRateByOrder = useMemo( () => {
		const map: Record<
			number,
			ReturnType< typeof resolveSelectedRate >
		> = {};
		orders.forEach( ( order ) => {
			map[ order.order_id ] = resolveSelectedRate(
				rates[ order.order_id ] ?? [],
				serviceApplyMode,
				manualServiceSelections[ order.order_id ]
			);
		} );
		return map;
	}, [ orders, rates, serviceApplyMode, manualServiceSelections ] );

	// Readiness must reflect rates too: a row with no selectable rate
	// (a definitive empty/error result, or a global origin/rate request
	// failure) is "needs fix", not "ready". Rows still resolving stay as
	// their package status until the quote settles.
	const effectiveStatusById = useMemo( () => {
		const map: Record< number, 'ready' | 'needs_fix' > = {};
		orders.forEach( ( order ) => {
			const id = order.order_id;
			if ( order.status === 'needs_fix' ) {
				map[ id ] = 'needs_fix';
				return;
			}
			if ( toSelectedBatchRate( selectedRateByOrder[ id ] ) ) {
				map[ id ] = 'ready';
				return;
			}
			const definitiveNoRate =
				Boolean( rateFetchError ) ||
				Boolean( rateErrors[ id ] ) ||
				Array.isArray( rates[ id ] );
			map[ id ] =
				definitiveNoRate && ! resolvingRateIds.has( id )
					? 'needs_fix'
					: 'ready';
		} );
		return map;
	}, [
		orders,
		selectedRateByOrder,
		rates,
		rateErrors,
		rateFetchError,
		resolvingRateIds,
	] );

	const readinessCounts = useMemo( () => {
		let readyCount = 0;
		let needsFixCount = 0;
		orders.forEach( ( o ) => {
			if ( effectiveStatusById[ o.order_id ] === 'needs_fix' ) {
				needsFixCount += 1;
			} else {
				readyCount += 1;
			}
		} );
		return { readyCount, needsFixCount };
	}, [ orders, effectiveStatusById ] );

	const purchasableOrders = useMemo< PurchasableBulkPurchaseOrder[] >(
		() =>
			orders.flatMap( ( order ) => {
				if (
					( effectiveStatusById[ order.order_id ] ??
						order.status ) !== 'ready'
				) {
					return [];
				}

				const selectedRate = toSelectedBatchRate(
					selectedRateByOrder[ order.order_id ]
				);
				const rateRequestOrder = rateRequestOrderById.get(
					order.order_id
				);
				if ( ! selectedRate || ! rateRequestOrder ) {
					return [];
				}

				return [
					{
						...order,
						service: {
							...order.service,
							carrier: selectedRate.carrier_id,
							name: selectedRate.service_name,
						},
						cost: selectedRate.rate,
						cost_savings: Math.max(
							selectedRate.retail_rate - selectedRate.rate,
							0
						),
						selected_rate: selectedRate,
						request_package:
							toBulkRequestPackage( rateRequestOrder ),
						purchase_destination: toPurchaseDestination( order ),
					},
				];
			} ),
		[
			orders,
			effectiveStatusById,
			selectedRateByOrder,
			rateRequestOrderById,
		]
	);

	const purchaseRateSummary = useMemo( () => {
		let subtotal = 0;
		let total = 0;
		purchasableOrders.forEach( ( order ) => {
			subtotal += order.selected_rate.retail_rate;
			total += order.selected_rate.rate;
		} );
		const round = ( n: number ) => Math.round( n * 100 ) / 100;
		return {
			subtotal: round( subtotal ),
			discount: round( Math.max( subtotal - total, 0 ) ),
			total: round( total ),
		};
	}, [ purchasableOrders ] );

	const handleSelectRate = useCallback(
		( orderId: number, rateId: string ) => {
			setManualServiceSelections( ( prev ) => {
				if ( ! rateId ) {
					if ( ! ( orderId in prev ) ) {
						return prev;
					}
					const next = { ...prev };
					delete next[ orderId ];
					return next;
				}
				return { ...prev, [ orderId ]: rateId };
			} );
		},
		[]
	);

	// Changing the service strategy re-applies it to every order, so drop
	// the per-row overrides.
	const handleApplyServiceToAll = useCallback( ( value: string ) => {
		setServiceApplyMode( value );
		setManualServiceSelections( {} );
	}, [] );

	const serviceApplyOptions = useMemo( () => buildServiceApplyOptions(), [] );

	const isLoading = isResolving;
	const hasData = ! isLoading && records.length > 0;
	const erroredRecords = useMemo(
		() => records.filter( ( record ) => record.error ),
		[ records ]
	);
	const erroredRecordsCount = erroredRecords.length;

	const filteredOrders = useMemo( () => {
		if ( filter === 'ready' ) {
			return orders.filter(
				( o ) => effectiveStatusById[ o.order_id ] === 'ready'
			);
		}
		if ( filter === 'needs_fix' ) {
			return orders.filter(
				( o ) => effectiveStatusById[ o.order_id ] === 'needs_fix'
			);
		}
		return orders;
	}, [ orders, filter, effectiveStatusById ] );

	const fields = useMemo< Field< BulkPurchaseOrder >[] >(
		() => [
			{
				id: 'order',
				label: __( 'Order', 'woocommerce-shipping' ),
				enableSorting: false,
				enableHiding: false,
				render: ( { item }: { item: BulkPurchaseOrder } ) => (
					<a
						className="bulk-purchase-modal__order-number"
						href={ getOrderEditUrl( item.order_id ) }
						target="_blank"
						rel="noreferrer noopener"
					>
						{ sprintf(
							/* translators: %s: order number */
							__( '#%s', 'woocommerce-shipping' ),
							item.order_number ?? String( item.order_id )
						) }
					</a>
				),
			},
			{
				id: 'ship_to',
				label: __( 'Ship to', 'woocommerce-shipping' ),
				enableSorting: false,
				enableHiding: false,
				render: ( { item }: { item: BulkPurchaseOrder } ) => {
					const locality = formatLocality( item.destination );
					const country = item.destination?.country;
					const isIntl = country && country !== 'US';
					const itemCount = item.item_count ?? 0;
					return (
						<>
							{ item.customer_name && (
								<div className="bulk-purchase-modal__customer-name">
									{ item.customer_name }
								</div>
							) }
							<div className="bulk-purchase-modal__address-line">
								{ locality && (
									<>
										<span aria-hidden="true">✓ </span>
										{ locality }
									</>
								) }
								{ isIntl && (
									<span className="bulk-purchase-modal__intl-badge">
										{ __( 'Intl', 'woocommerce-shipping' ) }
									</span>
								) }
								{ itemCount > 0 && (
									<>
										{ ' · ' }
										{ sprintf(
											/* translators: %d: number of items in the order */
											_n(
												'%d item',
												'%d items',
												itemCount,
												'woocommerce-shipping'
											),
											itemCount
										) }
									</>
								) }
							</div>
						</>
					);
				},
			},
			{
				id: 'weight',
				label: __( 'Total weight', 'woocommerce-shipping' ),
				enableSorting: false,
				enableHiding: false,
				render: ( { item }: { item: BulkPurchaseOrder } ) => {
					const weight = item.total_weight ?? 0;
					if ( weight <= 0 ) {
						return (
							<span className="bulk-purchase-modal__note-empty">
								—
							</span>
						);
					}
					return (
						<span className="bulk-purchase-modal__weight">
							{ sprintf(
								/* translators: 1: total order weight, 2: weight unit (e.g. kg) */
								__( '%1$s %2$s', 'woocommerce-shipping' ),
								String( weight ),
								item.weight_unit ?? 'kg'
							) }
						</span>
					);
				},
			},
			{
				id: 'package',
				label: __( 'Package', 'woocommerce-shipping' ),
				header: (
					<span className="bulk-purchase-modal__column-header">
						<span aria-hidden="true">📦</span>
						{ __( 'Package', 'woocommerce-shipping' ) }
					</span>
				),
				enableSorting: false,
				enableHiding: false,
				render: ( { item }: { item: BulkPurchaseOrder } ) => {
					const selectedKey = item.package_display.selected_key ?? '';
					const hasSelection = selectedKey !== '';

					// The auto-assigned box (or a not-yet-starred custom
					// box) might not be in the fetched list; surface it as
					// its own option so the dropdown can show it selected.
					const selectedInList = assignablePackages.some(
						( pkg ) => pkg.key === selectedKey
					);
					const syntheticSelected =
						hasSelection && ! selectedInList
							? [
									{
										label: item.package_display.dimensions
											? sprintf(
													/* translators: 1: package name, 2: package dimensions e.g. 12×9×6 */
													__(
														'%1$s (%2$s)',
														'woocommerce-shipping'
													),
													item.package_display.name,
													item.package_display
														.dimensions
											  )
											: item.package_display.name,
										value: selectedKey,
									},
							  ]
							: [];

					return (
						<ApplyDropdown
							ariaLabel={ __(
								'Package',
								'woocommerce-shipping'
							) }
							value={ selectedKey }
							placeholder={
								assignablePackages.length === 0 &&
								! hasSelection
									? __(
											'No packages available',
											'woocommerce-shipping'
									  )
									: __(
											'Select a package…',
											'woocommerce-shipping'
									  )
							}
							onSelect={ ( value ) => {
								const picked =
									assignablePackages.find(
										( pkg ) => pkg.key === value
									) ?? null;
								handleSelectPackage( item.order_id, picked );
							} }
							options={ [
								...syntheticSelected,
								...assignablePackages.map( ( pkg ) => ( {
									label: pkg.dimensions
										? sprintf(
												/* translators: 1: package name, 2: package dimensions e.g. 12×9×6 */
												__(
													'%1$s (%2$s)',
													'woocommerce-shipping'
												),
												pkg.name,
												pkg.dimensions
										  )
										: pkg.name,
									value: pkg.key,
								} ) ),
							] }
						/>
					);
				},
			},
			{
				id: 'service',
				label: __( 'Service', 'woocommerce-shipping' ),
				header: (
					<span className="bulk-purchase-modal__column-header">
						<span aria-hidden="true">🚚</span>
						{ __( 'Service', 'woocommerce-shipping' ) }
					</span>
				),
				enableSorting: false,
				enableHiding: false,
				render: ( { item }: { item: BulkPurchaseOrder } ) => {
					const orderRates = rates[ item.order_id ] ?? [];
					const selectedRate =
						selectedRateByOrder[ item.order_id ] ?? null;
					const rateError = rateErrors[ item.order_id ];

					// In-flight (initial quote or a package change): show a
					// progress bar, never a stale/previous selected rate.
					if ( resolvingRateIds.has( item.order_id ) ) {
						return (
							<div className="bulk-purchase-modal__rate-loading">
								<ProgressBar />
							</div>
						);
					}

					if ( orderRates.length === 0 ) {
						return (
							<span className="bulk-purchase-modal__note bulk-purchase-modal__note--warning">
								<Icon icon={ caution } size={ 16 } />
								{ rateError ||
									__(
										'No rates available',
										'woocommerce-shipping'
									) }
							</span>
						);
					}

					return (
						<ApplyDropdown
							ariaLabel={ __(
								'Service',
								'woocommerce-shipping'
							) }
							value={ selectedRate?.rateId ?? '' }
							onSelect={ ( value ) =>
								handleSelectRate( item.order_id, value )
							}
							options={ orderRates.map( ( rate ) => ( {
								label: rate.deliveryDays
									? sprintf(
											/* translators: 1: service title, 2: price, 3: delivery days */
											__(
												'%1$s — $%2$s · %3$d days',
												'woocommerce-shipping'
											),
											rate.title,
											rate.rate.toFixed( 2 ),
											rate.deliveryDays
									  )
									: sprintf(
											/* translators: 1: service title, 2: price */
											__(
												'%1$s — $%2$s',
												'woocommerce-shipping'
											),
											rate.title,
											rate.rate.toFixed( 2 )
									  ),
								value: rate.rateId,
								icon: (
									<CarrierIcon
										carrier={ rate.carrierId }
										size="small"
									/>
								),
							} ) ) }
						/>
					);
				},
			},
			{
				id: 'note',
				label: __( 'Notes', 'woocommerce-shipping' ),
				enableSorting: false,
				enableHiding: false,
				render: ( { item }: { item: BulkPurchaseOrder } ) => {
					if ( ! item.note.type ) {
						return (
							<span className="bulk-purchase-modal__note-empty">
								&mdash;
							</span>
						);
					}
					return (
						<span
							className={ `bulk-purchase-modal__note bulk-purchase-modal__note--${ item.note.type }` }
						>
							{ item.note.type === 'warning' && (
								<Icon icon={ caution } size={ 16 } />
							) }
							{ item.note.text }
						</span>
					);
				},
			},
			{
				id: 'cost',
				label: __( 'Cost', 'woocommerce-shipping' ),
				enableSorting: false,
				enableHiding: false,
				render: ( { item }: { item: BulkPurchaseOrder } ) => {
					const selectedRate =
						selectedRateByOrder[ item.order_id ] ?? null;
					if ( ! selectedRate ) {
						return (
							<span className="bulk-purchase-modal__note-empty">
								—
							</span>
						);
					}
					return (
						<div className="bulk-purchase-modal__cost">
							<div className="bulk-purchase-modal__cost-amount">
								${ selectedRate.rate.toFixed( 2 ) }
							</div>
						</div>
					);
				},
			},
			{
				id: 'status',
				label: __( 'Status', 'woocommerce-shipping' ),
				enableSorting: false,
				enableHiding: false,
				render: ( { item }: { item: BulkPurchaseOrder } ) => {
					const status =
						effectiveStatusById[ item.order_id ] ?? item.status;
					return (
						<span
							className={ `bulk-purchase-modal__status bulk-purchase-modal__status--${ status }` }
						>
							<span className="bulk-purchase-modal__status-dot" />
							{ status === 'ready'
								? __( 'Ready', 'woocommerce-shipping' )
								: __( 'Needs fix', 'woocommerce-shipping' ) }
						</span>
					);
				},
			},
		],
		[
			assignablePackages,
			handleSelectPackage,
			rates,
			rateErrors,
			resolvingRateIds,
			selectedRateByOrder,
			handleSelectRate,
			effectiveStatusById,
		]
	);

	const view: View = {
		type: 'table',
		fields: [
			'order',
			'ship_to',
			'weight',
			'package',
			'service',
			'note',
			'cost',
			'status',
		],
		layout: {
			enableMoving: false,
		},
	};

	// Count the orders the modal will actually act on, not the raw
	// selection — orders that errored out of the shipping-context fetch
	// are dropped and surfaced in the "could not be loaded" notice, so
	// the title must match the table/tabs (e.g. "All 10"). Fall back to
	// the selection count while the fetch is still resolving so the
	// header doesn't flash "Create 0 shipping labels".
	const labelCount = hasData ? orders.length : orderIds.length;
	const title = sprintf(
		/* translators: %d: number of shipping labels to create */
		_n(
			'Create %d shipping label',
			'Create %d shipping labels',
			labelCount,
			'woocommerce-shipping'
		),
		labelCount
	);

	return (
		<Modal
			title={ title }
			onRequestClose={ onClose }
			overlayClassName="bulk-purchase-overlay"
			className="bulk-purchase-modal"
			shouldCloseOnClickOutside={ false }
			__experimentalHideHeader
			isDismissible={ false }
		>
			<Flex
				className="bulk-purchase-modal__header"
				justify="space-between"
				align="flex-start"
			>
				<div className="bulk-purchase-modal__title-block">
					{ /* The orders list page already owns the page-level <h1>; use <h2> here so screen readers don't announce two top-level headings. */ }
					<h2 className="bulk-purchase-modal__title">{ title }</h2>
					<p className="bulk-purchase-modal__intro">
						{ __(
							'We picked the cheapest service and your most-used package for each order. Review, override what you need to, and purchase everything at once.',
							'woocommerce-shipping'
						) }
					</p>
				</div>
				<Button
					icon={ closeSmall }
					onClick={ onClose }
					label={ __( 'Close', 'woocommerce-shipping' ) }
				/>
			</Flex>

			{ isLoading && (
				<div className="bulk-purchase-modal__loading">
					<div className="bulk-purchase-modal__loading-bar">
						<ProgressBar />
					</div>
				</div>
			) }

			{ ! isLoading && Boolean( error ) && (
				<Notice status="error" isDismissible={ false }>
					{ error?.message ??
						__(
							'Could not load order details.',
							'woocommerce-shipping'
						) }
				</Notice>
			) }

			{ ! isLoading && ! error && ! hasData && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'No orders found for this selection.',
						'woocommerce-shipping'
					) }
				</Notice>
			) }

			{ hasData && (
				<div className="bulk-purchase-modal__layout">
					<div className="bulk-purchase-modal__main">
						{ rateFetchError && (
							<Notice status="error" isDismissible={ false }>
								{ sprintf(
									/* translators: %s: error message from the rate/origin request */
									__(
										'Couldn’t fetch shipping rates: %s. Service and cost are unavailable until this is resolved.',
										'woocommerce-shipping'
									),
									rateFetchError.message
								) }
							</Notice>
						) }

						{ erroredRecordsCount > 0 && (
							<Notice status="warning" isDismissible={ false }>
								{ sprintf(
									/* translators: 1: number of orders that could not be loaded, 2: comma-separated list of failed order IDs (e.g. #101, #102) */
									_n(
										'%1$d order could not be loaded and will be skipped: %2$s.',
										'%1$d orders could not be loaded and will be skipped: %2$s.',
										erroredRecordsCount,
										'woocommerce-shipping'
									),
									erroredRecordsCount,
									erroredRecords
										.map(
											( record ) =>
												`#${ record.order_id }`
										)
										.join( ', ' )
								) }
							</Notice>
						) }

						{ grouping && (
							<AddressGroupingSuggestion
								customerName={ grouping.customerName }
								cityState={ grouping.cityState }
								orderIds={ grouping.orderIds }
								// Placeholder savings. Real grouping discount lands with WOOSHIP-2133.
								savings={ 8.1 }
							/>
						) }

						<NeedsFixNotice
							needsFixCount={ readinessCounts.needsFixCount }
							readyCount={ readinessCounts.readyCount }
							onShowOnlyIssues={ () => setFilter( 'needs_fix' ) }
						/>

						<Toolbar
							selectedCount={ filteredOrders.length }
							totalCount={ orders.length }
							readyCount={ readinessCounts.readyCount }
							needsFixCount={ readinessCounts.needsFixCount }
							filter={ filter }
							onFilterChange={ setFilter }
							packageApplyOptions={ packageApplyOptions }
							packageApplyValue={ packageApplyValue }
							onPackageApply={ handleApplyPackageToAll }
							serviceApplyOptions={ serviceApplyOptions }
							serviceApplyValue={ serviceApplyMode }
							onServiceApply={ handleApplyServiceToAll }
						/>

						<div className="bulk-purchase-modal__orders">
							<DataViews< BulkPurchaseOrder >
								view={ view }
								fields={ fields }
								data={ filteredOrders }
								isLoading={ false }
								onChangeView={ () => {} } // eslint-disable-line @typescript-eslint/no-empty-function
								search={ false }
								defaultLayouts={ {
									table: {
										showMedia: false,
									},
								} }
								paginationInfo={ {
									totalItems: filteredOrders.length,
									totalPages: 1,
								} }
								getItemId={ ( item: BulkPurchaseOrder ) =>
									String( item.order_id )
								}
							>
								<DataViews.Layout />
							</DataViews>
						</div>
					</div>

					<Sidebar
						readyCount={ purchasableOrders.length }
						needsFixCount={
							orders.length - purchasableOrders.length
						}
						subtotal={ purchaseRateSummary.subtotal }
						discount={ purchaseRateSummary.discount }
						total={ purchaseRateSummary.total }
						onPurchase={ () => {
							if ( purchasableOrders.length === 0 || ! origin ) {
								return;
							}
							onCreateLabels?.(
								purchasableOrders,
								toPurchaseOrigin(
									origin as Record< string, unknown >
								)
							);
						} }
					/>
				</div>
			) }
		</Modal>
	);
};
