import { addFilter } from '@wordpress/hooks';
import { lazy } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
// importing here as the rest are lazy loaded
import '../../components/analytics/style.scss';

const Analytics = lazy( () => import( 'components/analytics' ) );

addFilter(
	'woocommerce_admin_reports_list',
	'analytics/shipping',
	( reports ) => [
		...reports,
		{
			report: 'shipping',
			title: __( 'Shipping Labels', 'woocommerce-shipping' ),
			component: Analytics,
			navArgs: {
				id: 'shipping-analytics',
			},
		},
	]
);
