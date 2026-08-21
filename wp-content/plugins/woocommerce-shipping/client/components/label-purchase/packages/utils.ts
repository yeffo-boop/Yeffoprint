import { CustomPackage, Package } from 'types';
import { CUSTOM_PACKAGE_TYPES } from './constants';
import { recordEvent } from 'utils';

export const DELETION_EVENTS = {
	CLICKED: 'label_purchase_delete_package_clicked',
	CONFIRMED: 'label_purchase_delete_package_confirmed',
	CANCELLED: 'label_purchase_delete_package_cancelled',
} as const;

export const trackPackageDeletion = (
	event: ( typeof DELETION_EVENTS )[ keyof typeof DELETION_EVENTS ],
	pkg: Package | CustomPackage
) => {
	const tracksProperties = {
		package_id: pkg.id,
		is_letter: pkg.isUserDefined
			? pkg.type === CUSTOM_PACKAGE_TYPES.ENVELOPE
			: pkg.isLetter,
		width: pkg.width,
		height: pkg.height,
		length: pkg.length,
		template_name: pkg.name,
		is_user_defined: pkg.isUserDefined,
	};
	recordEvent( event, tracksProperties );
};
