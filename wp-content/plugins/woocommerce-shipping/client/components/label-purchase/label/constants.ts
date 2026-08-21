import { Carrier } from 'types';

export const pickupUrls: Record< Carrier, string > = {
	usps: 'https://tools.usps.com/schedule-pickup-steps.htm',
	fedex: 'https://www.fedex.com/en-us/shipping/schedule-manage-pickups.html',
	ups: 'https://wwwapps.ups.com/pickup/request',
	upsdap: 'https://wwwapps.ups.com/pickup/request',
	dhlexpress:
		'https://mydhl.express.dhl/us/en/schedule-pickup.html#/schedule-pickup#label-reference',
};

export const trackingUrls: Record< Carrier, ( trackingId: string ) => string > =
	{
		usps: ( tracking ) =>
			`https://tools.usps.com/go/TrackConfirmAction.action?tLabels=${ tracking }`,
		fedex: ( tracking ) =>
			`https://www.fedex.com/apps/fedextrack/?action=track&tracknumbers=${ tracking }`,
		ups: ( tracking ) =>
			`https://www.ups.com/track?loc=en_US&tracknum=${ tracking }`,
		upsdap: ( tracking ) =>
			`https://www.ups.com/track?loc=en_US&tracknum=${ tracking }`,
		dhlexpress: ( tracking ) =>
			`https://www.dhl.com/en/express/tracking.html?AWB=${ tracking }&brand=DHL`,
	};
