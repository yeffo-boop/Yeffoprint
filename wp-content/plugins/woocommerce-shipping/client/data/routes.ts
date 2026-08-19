import { NAMESPACE, WC_NAMESPACE } from './constants';
import { Carrier, CustomPackageType } from 'types';

export const getRatesPath = () => `${ NAMESPACE }/label/rate`;

export const getBatchRatesPath = () => `${ NAMESPACE }/label/rate/batch`;

export const getAutoAssignPackagesPath = () =>
	`${ NAMESPACE }/label/auto-assign-packages`;

export const getOriginAddressesPath = () => `${ NAMESPACE }/address/origins`;

/**
 * Store address vs main sender sync meta (v2). Used to refresh the address Redux store.
 */
export const getStoreAddressSyncPath = () =>
	'/wcshipping/v2/addresses/store-address-sync';

export const getUpdateOriginPath = () => `${ NAMESPACE }/address/update_origin`;

export const getUpdateDestinationPath = ( orderId: string ) =>
	`${ NAMESPACE }/address/${ orderId }/update_destination`;

export const getAddressNormalizationPath = () =>
	`${ NAMESPACE }/address/normalize`;

export const getVerifyOrderShippingAddressPath = ( orderId: string ) =>
	`${ NAMESPACE }/address/${ orderId }/verify_order`;

export const getPackagesPath = () => `${ NAMESPACE }/packages`;
export const getShipmentsPath = ( orderId: string ) =>
	`${ NAMESPACE }/shipments/${ orderId }`;

export const getLabelPurchasePath = ( orderId: number ) =>
	`${ NAMESPACE }/label/purchase/${ orderId }`;

export const getBatchLabelPurchasePath = () =>
	`${ NAMESPACE }/label/purchase/batch`;

export const getAccountSettingsPath = () => `${ NAMESPACE }/account/settings`;

export const getLabelsStatusPath = ( orderId: number, labelId: number ) =>
	`${ NAMESPACE }/label/status/${ orderId }/${ labelId }`;

export const getLabelsPrintPath = () => `${ NAMESPACE }/label/print`;
export const getLabelTestPrintPath = () => `${ NAMESPACE }/label/preview`;

export const getPackingSlipPrintPath = ( labelId: number, orderId: number ) =>
	`${ NAMESPACE }/label/print/packing-list/${ labelId }/${ orderId }`;

export const getPackingSlipWithoutLabelPrintPath = ( orderId: number ) =>
	`${ NAMESPACE }/label/print/packing-slip?order_id=${ orderId }`;

export const getWCOrdersPath = ( orderId: string ) =>
	`${ WC_NAMESPACE }/orders/${ orderId }`;

export const getLabelRefundPath = ( orderId: number, labelId: number ) =>
	`${ NAMESPACE }/label/refund/${ orderId }/${ labelId }`;

export const getDeleteOriginAddressPath = ( id: string ) =>
	`${ NAMESPACE }/address/${ id }`;

export const getWPCOMConnectionPath = () => `${ NAMESPACE }/wpcom-connection`;

export const getDeletePackagePath = ( type: CustomPackageType, id: string ) =>
	`${ NAMESPACE }/packages/${ type }/${ id }`;

export const getCarrierStrategyPath = ( carrierId: Carrier ) =>
	`${ NAMESPACE }/carrier-strategy/${ carrierId }`;

export const getLabelsReportPath = () => `${ NAMESPACE }/reports/labels`;

/**
 * ScanForm routes.
 */
export const getScanFormOriginsPath = () => `${ NAMESPACE }/scan-form/origins`;

export const getCreateScanFormPath = () => `${ NAMESPACE }/scan-form/create`;

export const getScanFormReviewPath = () => `${ NAMESPACE }/scan-form/review`;

export const getScanFormHistoryPath = ( page: number, per_page: number ) =>
	`${ NAMESPACE }/scan-form/history?page=${ page }&per_page=${ per_page }`;

export const getScanFormLabelsPath = ( scanFormId: string ) =>
	`${ NAMESPACE }/scan-form/${ scanFormId }/labels`;

export const getServiceStatusPath = () => `${ NAMESPACE }/service-status`;
