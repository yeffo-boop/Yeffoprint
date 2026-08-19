type JQueryLike = ( target: Document | HTMLElement | string ) => {
	on: (
		eventName: string,
		handler: (
			event?: { type?: string },
			data?: { result?: string }
		) => void
	) => void;
	stop?: ( clearQueue?: boolean ) => void;
};

declare global {
	interface Window {
		jQuery?: JQueryLike;
	}
}

export const classicNoticeTargetSelector =
	'.wcshipping-checkout-address-validation-notices';
const billingClassicNoticeTargetSelector =
	'.wcshipping-checkout-address-validation-notices--billing';
const shippingClassicNoticeTargetSelector =
	'.wcshipping-checkout-address-validation-notices--shipping';
export const addressValidationNoticeSelector =
	'.wcshipping-checkout-notice--address-validation';
const noticeWrapperSelector =
	'.wc-block-components-notice-banner, .woocommerce-info, .woocommerce-message, .woocommerce-error';
const pendingCheckoutErrorScrollWindowMs = 5000;

let shouldScrollAfterCheckoutError = false;
let pendingCheckoutErrorScrollTimeout: number | undefined;

const isShippingAddressTargetActive = ( scope: ParentNode ): boolean => {
	const shipToDifferentAddressCheckbox = scope.querySelector(
		'#ship-to-different-address-checkbox'
	) as HTMLInputElement | null;

	return Boolean( shipToDifferentAddressCheckbox?.checked );
};

const getClassicNoticeTarget = (
	scope: ParentNode,
	targets: Element[]
): Element | null => {
	if ( targets.length === 0 ) {
		return null;
	}

	const shippingTarget = scope.querySelector(
		shippingClassicNoticeTargetSelector
	);

	if ( isShippingAddressTargetActive( scope ) && shippingTarget ) {
		return shippingTarget;
	}

	const billingTarget = scope.querySelector(
		billingClassicNoticeTargetSelector
	);

	return billingTarget ?? shippingTarget ?? targets[ 0 ];
};

const getAddressValidationNoticeElement = ( marker: Element ): Element => {
	const wrapper = marker.closest( noticeWrapperSelector );

	if ( ! wrapper ) {
		return marker.parentElement ?? marker;
	}

	if ( wrapper.matches( '.woocommerce-error' ) ) {
		const listItem = marker.closest( 'li' );

		if ( listItem && wrapper.children.length > 1 ) {
			const singleNoticeWrapper = wrapper.cloneNode( false ) as Element;
			singleNoticeWrapper.appendChild( listItem );

			return singleNoticeWrapper;
		}
	}

	return wrapper;
};

interface MoveClassicAddressValidationNoticesOptions {
	preserveExistingTargetNotices?: boolean;
}

export const moveClassicAddressValidationNotices = (
	scope: ParentNode = document,
	{
		preserveExistingTargetNotices = false,
	}: MoveClassicAddressValidationNoticesOptions = {}
): Element | null => {
	const targets = Array.from(
		scope.querySelectorAll( classicNoticeTargetSelector )
	);
	const target = getClassicNoticeTarget( scope, targets );

	if ( ! target ) {
		return null;
	}

	const markers = Array.from(
		scope.querySelectorAll( addressValidationNoticeSelector )
	).filter(
		( marker ) =>
			! targets.some( ( noticeTarget ) =>
				noticeTarget.contains( marker )
			)
	);

	if ( preserveExistingTargetNotices && markers.length === 0 ) {
		targets.forEach( ( noticeTarget ) => {
			if ( noticeTarget !== target ) {
				noticeTarget.replaceChildren();
			}
		} );

		return target.querySelector( addressValidationNoticeSelector )
			? target
			: null;
	}

	targets.forEach( ( noticeTarget ) => noticeTarget.replaceChildren() );

	markers.forEach( ( marker ) => {
		target.appendChild( getAddressValidationNoticeElement( marker ) );
	} );

	return markers.length > 0 ? target : null;
};

const clearPendingCheckoutErrorScroll = (): void => {
	shouldScrollAfterCheckoutError = false;

	if ( pendingCheckoutErrorScrollTimeout ) {
		window.clearTimeout( pendingCheckoutErrorScrollTimeout );
		pendingCheckoutErrorScrollTimeout = undefined;
	}
};

const requestCheckoutErrorScroll = (): void => {
	shouldScrollAfterCheckoutError = true;

	if ( pendingCheckoutErrorScrollTimeout ) {
		window.clearTimeout( pendingCheckoutErrorScrollTimeout );
	}

	pendingCheckoutErrorScrollTimeout = window.setTimeout( () => {
		clearPendingCheckoutErrorScroll();
	}, pendingCheckoutErrorScrollWindowMs );
};

const stopClassicCheckoutScrollAnimation = (): void => {
	window.jQuery?.( 'html, body' ).stop?.( true );
};

const maybeScrollMovedTargetIntoView = (
	movedTarget: Element | null
): void => {
	if ( ! shouldScrollAfterCheckoutError || ! movedTarget ) {
		return;
	}

	clearPendingCheckoutErrorScroll();

	if (
		'scrollIntoView' in movedTarget &&
		typeof movedTarget.scrollIntoView === 'function'
	) {
		stopClassicCheckoutScrollAnimation();
		movedTarget.scrollIntoView( {
			block: 'center',
		} );
	}
};

const scheduleClassicAddressValidationNoticeMove = (
	scrollAfterMove = false
): void => {
	if ( scrollAfterMove ) {
		requestCheckoutErrorScroll();
	}

	window.setTimeout( () => {
		const movedTarget = moveClassicAddressValidationNotices( document, {
			preserveExistingTargetNotices: true,
		} );

		maybeScrollMovedTargetIntoView( movedTarget );
	}, 0 );
};

export const initClassicNoticePlacement = (): void => {
	clearPendingCheckoutErrorScroll();
	moveClassicAddressValidationNotices();

	if ( window.jQuery ) {
		window
			.jQuery( document.body )
			.on( 'updated_checkout checkout_error', ( event, data ) => {
				scheduleClassicAddressValidationNoticeMove(
					event?.type === 'checkout_error' ||
						( event?.type === 'updated_checkout' &&
							data?.result === 'failure' )
				);
			} );
	}
};
