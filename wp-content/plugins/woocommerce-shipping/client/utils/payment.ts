import { getConfig } from './config';
import { WCShippingConfig } from '../types';
import { camelCaseKeys } from './common';

export const hasSelectedPaymentMethod = (
	{
		accountSettings,
	}: Pick< WCShippingConfig, 'accountSettings' > = getConfig()
) => accountSettings.purchaseSettings.selected_payment_method_id > 0;

export const hasPaymentMethod = (
	{
		accountSettings,
	}: Pick< WCShippingConfig, 'accountSettings' > = getConfig()
) => accountSettings.purchaseMeta.payment_methods.length > 0;

export const getPaymentSettings = ( { accountSettings } = getConfig() ) =>
	camelCaseKeys( accountSettings.purchaseSettings );

export const getAddPaymentMethodURL = (
	{
		accountSettings,
	}: Pick< WCShippingConfig, 'accountSettings' > = getConfig()
) => accountSettings.purchaseMeta.add_payment_method_url;

export const canManagePayments = (
	{
		accountSettings,
	}: Pick< WCShippingConfig, 'accountSettings' > = getConfig()
) => accountSettings.purchaseMeta.can_manage_payments;
