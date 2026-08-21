import { mapKeys } from 'lodash';

export const normalizeSelectionKey = < T >( selection: Record< string, T > ) =>
	mapKeys( selection, ( value, key ) => key.replace( 'shipment_', '' ) );
