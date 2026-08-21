import { Carrier } from './carrier';
import { LABEL_RATE_OPTION } from 'data/constants';
import { RecordValues } from 'types/helpers';
import { LabelRateType } from './label-rate-type';

export interface Rate {
	carrierId: Carrier;
	freePickup: boolean;
	insurance: number;
	isSelected: boolean;
	rate: number;
	rateId: string;
	retailRate: number;
	serviceId: string;
	shipmentId: string;
	title: string;
	tracking: boolean;
	deliveryDays?: number;
	deliveryDate?: string;
	deliveryDateGuaranteed?: boolean;
	caveats?: Array< string >;
	type?: SnakeToCamelCase< LabelRateType >;
	extraOptions?: RateExtraOptions; // extra options are the options that are added to the rate, added when saved to the order
	baseRate?: number; // base rate is the rate without any extra options, added when saved to the order
	isReturn?: boolean; // whether this is a return shipment
	promoId?: string;
}

export type RateExtraOptionNames = RecordValues< typeof LABEL_RATE_OPTION >;

export type RateExtraOptionValue = boolean | 'yes' | 'no' | 'adult';

export type RateExtraOptions = Record<
	RateExtraOptionNames,
	{
		value: RateExtraOptionValue;
		surcharge: number;
	}
>;

export type ExtraOptionCharges< T > = Record< RateExtraOptionNames, T >;
