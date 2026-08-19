import { dateI18n } from '@wordpress/date';
import { ReportLabel } from 'types';
import CurrencyFactory from '@woocommerce/currency';
import { Link } from 'components/wc';
import { ExternalLink } from '@wordpress/components';
import { trackingUrls } from 'components/label-purchase/label/constants';

export const mapRowToTableData =
	( currency: ReturnType< typeof CurrencyFactory > ) =>
	( item: ReportLabel ) => {
		const createdDate = item.createdDate
			? dateI18n( 'M d, Y g:i a', new Date( item.createdDate ), false )
			: null;
		return [
			{
				display: createdDate,
				value: createdDate,
			},
			{
				display: (
					<Link
						href={ `post.php?post=${ item.orderId }&action=edit` }
						type="wp-admin"
						target="_blank"
					>
						{ `#${ item.orderId }` }
					</Link>
				),
				value: item.orderId,
			},
			{
				display: currency?.render( item.rate ?? 0 ),
				value: item.rate ?? 0,
			},
			{ display: item.serviceName, value: item.serviceName },
			{
				display:
					item.carrierId &&
					item.tracking &&
					trackingUrls[ item.carrierId ] ? (
						<ExternalLink
							href={ trackingUrls[ item.carrierId ](
								item.tracking
							) }
						>
							{ item.tracking }
						</ExternalLink>
					) : (
						item.tracking
					),
				value: item.tracking,
			},
			{
				display: item.refund?.status,
				value: item.refund?.status,
			},
		] as {
			display: string;
			value: string | number;
		}[];
	};
