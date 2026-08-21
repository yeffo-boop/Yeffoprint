import { ExternalLink, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useServiceStatus } from './use-service-status';

const isHttpUrl = ( url: string ): boolean => {
	try {
		const { protocol } = new URL( url );
		return protocol === 'http:' || protocol === 'https:';
	} catch {
		return false;
	}
};

/**
 * Displays service status notices fetched from the Connect Server.
 * Renders severity-based banners for active carrier incidents.
 */
export const ServiceStatusNotices = () => {
	const { notices } = useServiceStatus();

	if ( notices.length === 0 ) {
		return null;
	}

	return (
		<>
			{ notices.map( ( notice ) => (
				<Notice
					key={ notice.id }
					status={ notice.type }
					isDismissible={ notice.dismissible ?? false }
				>
					<span>{ notice.message }</span>
					{ notice.action && isHttpUrl( notice.action ) && (
						<>
							{ ' ' }
							<ExternalLink href={ notice.action }>
								{ __(
									'View status page',
									'woocommerce-shipping'
								) }
							</ExternalLink>
						</>
					) }
				</Notice>
			) ) }
		</>
	);
};
