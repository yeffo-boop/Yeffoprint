import {
	CUSTOM_BOX_ID_PREFIX,
	CUSTOM_PACKAGE_TYPES,
} from './packages/constants';
import { CustomPackage } from 'types';

export const OPEN_DESTINATION_ADDRESS_MODAL_EVENT =
	'wcshipping:open-destination-address-modal';

export const DESTINATION_ADDRESS_MODAL_FOCUS_FIELD = {
	PHONE: 'phone',
} as const;

export type DestinationAddressModalFocusField =
	( typeof DESTINATION_ADDRESS_MODAL_FOCUS_FIELD )[ keyof typeof DESTINATION_ADDRESS_MODAL_FOCUS_FIELD ];

export const requestDestinationAddressModal = (
	focusField: DestinationAddressModalFocusField = DESTINATION_ADDRESS_MODAL_FOCUS_FIELD.PHONE
) => {
	if ( typeof window === 'undefined' ) {
		return;
	}

	window.dispatchEvent(
		new CustomEvent( OPEN_DESTINATION_ADDRESS_MODAL_EVENT, {
			detail: { focusField },
		} )
	);
};

const getFieldLabelText = ( field: HTMLInputElement ) => {
	const fieldLabels = Array.from( field.labels ?? [] )
		.map( ( label ) => label.textContent ?? '' )
		.join( ' ' );
	const labelledBy = field.getAttribute( 'aria-labelledby' );
	const ariaLabelText = labelledBy
		? labelledBy
				.split( /\s+/ )
				.map(
					( id ) => document.getElementById( id )?.textContent ?? ''
				)
				.join( ' ' )
		: '';

	return `${ fieldLabels } ${ ariaLabelText }`.trim();
};

const findDestinationPhoneField = ( modal: Element ) => {
	const stablePhoneField = modal.querySelector< HTMLInputElement >(
		'input[name="phone"], input[id="phone"], input[id$="-phone"]'
	);

	if ( stablePhoneField ) {
		return stablePhoneField;
	}

	const labelledPhoneField = Array.from(
		modal.querySelectorAll< HTMLInputElement >( 'input' )
	).find( ( field ) =>
		/^phone(\s*\(optional\))?$/i.test( getFieldLabelText( field ) )
	);

	if ( labelledPhoneField ) {
		return labelledPhoneField;
	}

	return modal.querySelector< HTMLInputElement >(
		'input[placeholder*="212"], input[placeholder*="555"]'
	);
};

const isVisibleElement = ( element: Element ) => {
	const htmlElement = element as HTMLElement;

	return Boolean(
		htmlElement.offsetParent ?? htmlElement.getClientRects().length
	);
};

const findVisibleDestinationPhoneField = () => {
	const modals = Array.from(
		document.querySelectorAll(
			'.edit-address-modal, .components-modal__content'
		)
	);

	const visibleModals = modals.filter( isVisibleElement );
	const visiblePhoneField = visibleModals
		.map( findDestinationPhoneField )
		.find( Boolean );

	if ( visiblePhoneField ) {
		return visiblePhoneField;
	}

	return modals.map( findDestinationPhoneField ).find( Boolean );
};

const focusDestinationPhoneFieldWithRetry = ( remainingAttempts = 5 ) => {
	if ( typeof document === 'undefined' ) {
		return;
	}

	const phoneField = findVisibleDestinationPhoneField();

	if ( phoneField ) {
		phoneField.focus();

		if ( remainingAttempts > 0 ) {
			window.setTimeout( () => {
				if ( phoneField.ownerDocument.activeElement !== phoneField ) {
					focusDestinationPhoneFieldWithRetry(
						remainingAttempts - 1
					);
				}
			}, 50 );
		}

		return;
	}

	if ( remainingAttempts <= 0 ) {
		return;
	}

	window.setTimeout( () => {
		focusDestinationPhoneFieldWithRetry( remainingAttempts - 1 );
	}, 50 );
};

export const focusDestinationPhoneField = () => {
	focusDestinationPhoneFieldWithRetry();
};

export const mainModalContentSelector =
	'.label-purchase-modal > .components-modal__content';

export const defaultCustomPackageData: CustomPackage & { isLetter: boolean } = {
	name: '',
	length: '',
	width: '',
	height: '',
	boxWeight: 0,
	id: CUSTOM_BOX_ID_PREFIX,
	type: CUSTOM_PACKAGE_TYPES.BOX,
	isLetter: false,
	dimensions: '10 x 10 x 10',
	isUserDefined: true,
};

export const settingsPageUrl =
	'admin.php?page=wc-settings&tab=shipping&section=woocommerce-shipping-settings';

export const TIME_TO_WAIT_TO_CHECK_PURCHASED_LABEL_STATUS_MS = 10000;

// Maximum number of retries for checking label status (30 retries * 10 seconds = 5 minutes)
export const MAX_LABEL_STATUS_RETRIES = 30;

// Timeout duration for label purchase process (5 minutes)
export const LABEL_PURCHASE_TIMEOUT_MS = 5 * 60 * 1000;

/**
 * Inline styles for the customer-paid shipping banner.
 * Inline styles are used alongside the CSS class because CIAB does not load
 * external woocommerce-shipping CSS files.
 */
export const customerPaidBannerStyles = {
	container: {
		background: 'var(--gutenberg-gray-100, #f0f0f0)',
		borderRadius: '4px',
		padding: '12px 16px',
		width: '100%',
		boxSizing: 'border-box' as const,
	},
	text: {
		color: 'var(--gutenberg-gray-700, #757575)',
	},
};
