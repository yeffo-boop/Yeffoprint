import { ShipmentRecord } from './helpers';
import { getPreparedDestination } from 'data/address/selectors';

export type SelectedDestination = ShipmentRecord<
	ReturnType< typeof getPreparedDestination >
>;
