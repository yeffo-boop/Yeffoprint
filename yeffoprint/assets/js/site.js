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

	document.addEventListener( 'DOMContentLoaded', function () {
		initHeaderScroll();
		initDrawers();
	} );
} )();
