import { LABEL_RATE_TYPE } from 'data/constants';
import { Rate } from '../rates.d';
import { RecordValues } from '../helpers';

export type ShipmentRatesResponse = Record<
	RecordValues< typeof LABEL_RATE_TYPE >,
	{
		rates: Rate[];
		errors: Record< string, string >[];
	}
>;

export type RatesResponse = Record<
	string, // shipmentId
	ShipmentRatesResponse
>;
