/**
 * YeffoPrint global UI behavior.
 *
 * Vanilla JS, no dependencies. Two small pieces of presentation logic:
 * header compaction on scroll, and a generic accessible drawer
 * primitive (open/close, focus trap, ESC, backdrop click) reused by
 * the search overlay now and the cart/mobile-filter drawers later.
 */
( function () {
	'use strict';

	var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

	/* ---------- Header compaction on scroll ---------- */

	function initHeaderScroll() {
		var header = document.querySelector( '.yp-header' );
		if ( ! header ) {
			return;
		}

		var threshold = 24;
		var ticking = false;

		function update() {
			header.classList.toggle( 'is-scrolled', window.scrollY > threshold );
			ticking = false;
		}

		window.addEventListener(
			'scroll',
			function () {
				if ( ! ticking ) {
					window.requestAnimationFrame( update );
					ticking = true;
				}
			},
			{ passive: true }
		);

		update();
	}

	/* ---------- Drawer primitive ---------- */

	var lastFocused = null;

	function trapFocus( panel, event ) {
		if ( event.key !== 'Tab' ) {
			return;
		}

		var focusable = panel.querySelectorAll( FOCUSABLE );
		if ( ! focusable.length ) {
			return;
		}

		var first = focusable[ 0 ];
		var last = focusable[ focusable.length - 1 ];

		if ( event.shiftKey && document.activeElement === first ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && document.activeElement === last ) {
			event.preventDefault();
			first.focus();
		}
	}

	function openDrawer( drawer ) {
		if ( drawer.dataset.open === 'true' ) {
			return;
		}

		lastFocused = document.activeElement;
		drawer.dataset.open = 'true';
		drawer.setAttribute( 'aria-hidden', 'false' );
		document.body.style.overflow = 'hidden';

		var panel = drawer.querySelector( '.yp-drawer__panel' );
		var focusable = panel ? panel.querySelectorAll( FOCUSABLE ) : [];
		if ( focusable.length ) {
			focusable[ 0 ].focus();
		}

		drawer._keydownHandler = function ( event ) {
			if ( event.key === 'Escape' ) {
				closeDrawer( drawer );
				return;
			}
			if ( panel ) {
				trapFocus( panel, event );
			}
		};
		document.addEventListener( 'keydown', drawer._keydownHandler );
	}

	function closeDrawer( drawer ) {
		if ( drawer.dataset.open !== 'true' ) {
			return;
		}

		drawer.dataset.open = 'false';
		drawer.setAttribute( 'aria-hidden', 'true' );
		document.body.style.overflow = '';

		if ( drawer._keydownHandler ) {
			document.removeEventListener( 'keydown', drawer._keydownHandler );
			drawer._keydownHandler = null;
		}

		if ( lastFocused && typeof lastFocused.focus === 'function' ) {
			lastFocused.focus();
		}
	}

	function initDrawers() {
		var triggers = document.querySelectorAll( '[data-yp-drawer-trigger]' );
		triggers.forEach( function ( trigger ) {
			var targetId = trigger.getAttribute( 'data-yp-drawer-trigger' );
			var drawer = document.getElementById( targetId );
			if ( ! drawer ) {
				return;
			}

			trigger.addEventListener( 'click', function () {
				openDrawer( drawer );
			} );
		} );

		document.querySelectorAll( '.yp-drawer' ).forEach( function ( drawer ) {
			drawer.querySelectorAll( '[data-yp-drawer-close]' ).forEach( function ( closer ) {
				closer.addEventListener( 'click', function () {
					closeDrawer( drawer );
				} );
			} );

			var backdrop = drawer.querySelector( '.yp-drawer__backdrop' );
			if ( backdrop ) {
				backdrop.addEventListener( 'click', function () {
					closeDrawer( drawer );
				} );
			}
		} );
	}

	/* ---------- Gallery sort auto-submit ---------- */

	/**
	 * The sort <select> in blocks/gallery-toolbar/render.php is a real
	 * form with an Apply button, so this is optional enhancement only —
	 * without it, sorting still works via the button.
	 */
	function initGalleryToolbar() {
		var select = document.getElementById( 'yp-sort-select' );
		if ( ! select ) {
			return;
		}

		select.addEventListener( 'change', function () {
			select.form.submit();
		} );
	}

	/* ---------- Cart drawer ---------- */

	/**
	 * PROJECT_SPEC §14: Add to Cart opens the drawer (not immediate
	 * navigation). configurator.js does the actual add-to-cart REST
	 * call and dispatches `yp:cart-updated` with the server-rendered
	 * drawer contents on success — this just renders that payload and
	 * opens the drawer that's already been here since Phase 2. Also
	 * refreshes on open in case the cart changed in another tab.
	 */
	function initCartDrawer() {
		var cartDrawer = document.getElementById( 'yp-cart-drawer' );
		if ( ! cartDrawer || typeof yeffoprintCart === 'undefined' ) {
			return;
		}

		var body = cartDrawer.querySelector( '.yp-drawer__body' );
		var countEls = document.querySelectorAll( '[data-yp-cart-count]' );

		function setCount( count ) {
			countEls.forEach( function ( el ) {
				el.textContent = count;
			} );
		}

		function setDrawerHtml( html ) {
			if ( body && html ) {
				body.innerHTML = html;
			}
		}

		function refreshDrawer() {
			fetch( yeffoprintCart.restUrl + 'cart/drawer' )
				.then( function ( response ) {
					return response.ok ? response.json() : null;
				} )
				.then( function ( data ) {
					if ( data ) {
						setCount( data.cart_count );
						setDrawerHtml( data.drawer_html );
					}
				} )
				.catch( function () {} );
		}

		var trigger = document.querySelector( '[data-yp-drawer-trigger="yp-cart-drawer"]' );
		if ( trigger ) {
			trigger.addEventListener( 'click', refreshDrawer );
		}

		// So the header badge reflects an existing session's cart
		// immediately, not just after the next add/open.
		refreshDrawer();

		document.addEventListener( 'yp:cart-updated', function ( event ) {
			var detail = event.detail || {};
			setCount( detail.count );
			setDrawerHtml( detail.drawerHtml );
			openDrawer( cartDrawer );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initHeaderScroll();
		initDrawers();
		initGalleryToolbar();
		initCartDrawer();
	} );
} )();
