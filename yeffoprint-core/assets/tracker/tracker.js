/**
 * Dose Tracker app (/tracker/) — see includes/tracker/class-tracker-app.php.
 *
 * Vanilla JS, no build step, same as the theme's scripts. Everything the
 * customer enters goes through the encrypted REST API
 * (rest/class-tracker-controller.php); this file keeps a local copy so
 * the app opens instantly and works offline, queueing changes until the
 * connection is back. Records:
 *
 *   protocol  { compound, dose, unit, route, device:'syringe'|'pen'|'single', schedule:{type,days,every,on,off}, times[], start, weeks, color, notes, paused, doseOf, sites[], siteOff, cycle, steps[] }
 *             cycle { on, off }: weeks on, then weeks off, repeating from the start (the cycle planner).
 *             steps [{ week, dose }]: titration. From `week` weeks after the start the dose is `dose`; before the first step it's `dose` above.
 *             sites / siteOff: the injection spots this protocol rotates through ([] = all) and whether to track them at all.
 *             doseOf: for a blend, the peptide the dose is measured by ('' = the whole blend).
 *   dose      { protocolId, compound, date, time, status:'taken'|'skipped', at, dose, unit, vialId, units, note, site, tags[] }
 *             tags: how the customer felt (side effects and the like), e.g. [ 'Nausea', 'Slept well' ].
 *             site: where an injection went (SITES id), for injection site rotation.
 *   vial      { kind:'vial'|'pen', compound, mode:'mg'|'iu'|'conc'|'blend', amount, water, conc, volume, mixed, syringe, finished, parts, blend, goodFor }
 *             goodFor: days the mixed vial keeps (default 28, 0 = don't track), for the expiry countdown.
 *             A blend has parts [{ name, amount (mg) }] and amount = their total; blend is 'bought' or 'mixed' (the customer combined vials).
 *             A pen is a 3 mL cartridge the customer mixes like a vial; its dial is read as U-100 units (0.01 mL each).
 *   settings  { tz, reminders, reminderNames, units:'us'|'metric', tags[] }   (tags: the customer's own feeling tags)
 *   progress  { date, weight, wUnit:'lb'|'kg', m:{ waist, chest, hips, arm, thigh, neck }, mUnit:'in'|'cm', fat, note, photos[] }
 *             photos are ids of encrypted photo records, fetched one at a time from /tracker/photos/{id}.
 *
 * The Mix a vial calculator is the same math as the Peptide & Hormone
 * Calculator page (theme assets/js/peptide-calculator.js):
 *   powder:   concentration = vial amount ÷ water mL;  volume = dose ÷ concentration
 *   premixed: volume = dose mg ÷ (mg/mL on the label)
 *   blend:    concentration = total mg (or the one peptide the dose is measured by) ÷ water mL
 *   units to draw = volume (mL) × 100   (U-100 insulin syringe)
 *
 * Order labels (Vials tab) builds one template cart line from the
 * customer's vials through the storefront's own REST routes; see
 * openLabelOrder().
 *
 * All customer text is rendered with textContent (via h()), never innerHTML.
 */
( function () {
	'use strict';

	var CFG = window.YP_TRACKER || {};
	var root = document.getElementById( 'yp-tracker' );
	if ( ! root ) {
		return;
	}

	var COLORS = [ '#00AEEF', '#EC008C', '#F5B400', '#7C4DFF', '#1F9D55', '#FF6B35', '#0078A4', '#C2007A' ];
	// Countable units are stored singular and shown plural when the dose isn't 1 ("2 tablets").
	var UNITS = [ 'mcg', 'mg', 'g', 'IU', 'units', 'mL', 'tablet', 'capsule', 'spray', 'drop', 'puff', 'patch', 'pump', 'application', 'suppository', 'dose' ];
	var UNIT_PLURAL = { tablet: 'tablets', capsule: 'capsules', spray: 'sprays', drop: 'drops', puff: 'puffs', patch: 'patches', pump: 'pumps', application: 'applications', suppository: 'suppositories', dose: 'doses' };
	/*
	 * How it's taken. `v` is what's stored on a protocol: the first six are
	 * the original values, so older protocols keep working. `units` are the
	 * choices shown for that route, first one the default.
	 */
	var ROUTES = [
		{ v: 'Subcutaneous', label: 'Injection, under the skin (subcutaneous)', short: 'subcutaneous', units: [ 'mcg', 'mg', 'IU', 'units', 'mL' ] },
		{ v: 'Intramuscular', label: 'Injection, into the muscle (intramuscular)', short: 'intramuscular', units: [ 'mg', 'mcg', 'IU', 'units', 'mL' ] },
		{ v: 'Oral', label: 'By mouth (pill, capsule, liquid)', short: 'oral', units: [ 'tablet', 'capsule', 'mg', 'mcg', 'g', 'IU', 'mL', 'drop' ] },
		{ v: 'Sublingual', label: 'Under the tongue (sublingual)', short: 'sublingual', units: [ 'mg', 'mcg', 'tablet', 'drop', 'mL', 'spray' ] },
		{ v: 'Nasal', label: 'Nasal spray', short: 'nasal', units: [ 'spray', 'mcg', 'mg', 'mL' ] },
		{ v: 'Inhaled', label: 'Inhaler or nebulizer', short: 'inhaled', units: [ 'puff', 'mcg', 'mg', 'mL' ] },
		{ v: 'Topical', label: 'On the skin (cream, gel)', short: 'topical', units: [ 'pump', 'application', 'g', 'mL', 'mg' ] },
		{ v: 'Transdermal', label: 'Patch', short: 'transdermal', units: [ 'patch', 'mg', 'mcg' ] },
		{ v: 'Eye drops', label: 'Eye drops', short: 'eye drops', units: [ 'drop', 'mL' ] },
		{ v: 'Ear drops', label: 'Ear drops', short: 'ear drops', units: [ 'drop', 'mL' ] },
		{ v: 'Rectal', label: 'Rectal', short: 'rectal', units: [ 'suppository', 'mg', 'g', 'mL' ] },
		{ v: 'Vaginal', label: 'Vaginal', short: 'vaginal', units: [ 'suppository', 'application', 'mg', 'g' ] },
		{ v: 'Other', label: 'Other', short: '', units: UNITS },
	];
	var DOW = [ 'S', 'M', 'T', 'W', 'T', 'F', 'S' ];
	var DOW_LONG = [ 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' ];
	var MONTHS = [ 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December' ];
	var VIAL_GOOD_DAYS = 28;
	var EXPIRY_WARN_DAYS = 3;
	var EXPIRY_NOTICE_DAYS = 2;
	var NONCE_ERRORS = [ 'yeffoprint_invalid_nonce', 'rest_cookie_invalid_nonce' ];

	var STORE_KEY = 'ypt:' + ( CFG.userKey || 'anon' );
	var QUEUE_KEY = 'ypt-q:' + ( CFG.userKey || 'anon' );
	var UI_KEY = 'ypt-ui';
	var THEME_KEY = 'ypt-theme';

	var state = {
		records: { protocol: {}, dose: {}, vial: {}, stock: {}, settings: {}, progress: {}, lab: {} },
		push: { publicKey: '', devices: 0 },
		shares: [],
		snoozes: [],
		loaded: false,
		offline: ! navigator.onLine,
		syncing: false,
	};
	var ui = {
		tab: 'today',
		day: todayStr(),
		month: todayStr().slice( 0, 7 ),
		historyDay: todayStr(),
		historyFilter: '',
		sheet: null,
		siteChoice: {}, // slot id -> spot picked on Today before tapping Take
	};
	var queue = [];
	var installPrompt = null;
	var toastTimer = null;

	/* =========================================================
	 * Small helpers
	 * ======================================================= */

	function h( tag, attrs ) {
		var node = document.createElement( tag );
		var kids = [].slice.call( arguments, 2 );
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( k ) {
				var v = attrs[ k ];
				if ( v == null || v === false ) {
					return;
				}
				if ( k === 'class' ) {
					node.className = v;
				} else if ( k === 'style' && typeof v === 'object' ) {
					Object.keys( v ).forEach( function ( s ) {
						// Custom properties as-is; camelCase names → CSS names.
						node.style.setProperty( s.indexOf( '--' ) === 0 ? s : s.replace( /[A-Z]/g, function ( c ) {
							return '-' + c.toLowerCase();
						} ), v[ s ] );
					} );
				} else if ( k.slice( 0, 2 ) === 'on' && typeof v === 'function' ) {
					node.addEventListener( k.slice( 2 ), v );
				} else if ( k === 'value' ) {
					node.value = v;
				} else if ( v === true ) {
					node.setAttribute( k, '' );
				} else {
					node.setAttribute( k, v );
				}
			} );
		}
		append( node, kids );
		return node;
	}

	function append( node, kids ) {
		kids.forEach( function ( kid ) {
			if ( kid == null || kid === false ) {
				return;
			}
			if ( Array.isArray( kid ) ) {
				append( node, kid );
			} else if ( typeof kid === 'string' || typeof kid === 'number' ) {
				node.appendChild( document.createTextNode( String( kid ) ) );
			} else {
				node.appendChild( kid );
			}
		} );
	}

	var SVG_NS = 'http://www.w3.org/2000/svg';
	function svg( tag, attrs, parent ) {
		var node = document.createElementNS( SVG_NS, tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			node.setAttribute( k, attrs[ k ] );
		} );
		if ( parent ) {
			parent.appendChild( node );
		}
		return node;
	}

	var ICONS = {
		today: 'M4 5h16v15H4zM4 9h16M8 3v4M16 3v4M8 13l2.5 2.5L16 12',
		history: 'M4 19V9M10 19V5M16 19v-7M22 19H2',
		progress: 'M3 17l6-6 4 4 8-8M15 7h6v6',
		cal: 'M4 5h16v15H4zM4 9h16M8 3v4M16 3v4M8 13h2M14 13h2M8 17h2',
		syringe: 'M18 2l4 4M17 7l3-3M19 9L9 19l-4 1 1-4L16 6zM14 8l2 2M11 11l2 2',
		pill: 'M10.5 20.5a5 5 0 0 1-7-7l10-10a5 5 0 0 1 7 7zM8.5 8.5l7 7',
		feel: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM8 14s1.5 2 4 2 4-2 4-2M9 9h.01M15 9h.01',
		plus: 'M12 5v14M5 12h14',
		vials: 'M9 3h6M10 3v4l-3 3v10a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V10l-3-3V3M7 14h10',
		me: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21c1-4 4-6 8-6s7 2 8 6',
		lock: 'M6 11h12v10H6zM8 11V8a4 4 0 0 1 8 0v3',
		bell: 'M6 16V11a6 6 0 0 1 12 0v5l2 2H4zM10 21h4',
		belloff: 'M6 16V11a6 6 0 0 1 9.5-4.9M18 11v5l2 2H8M10 21h4M3 3l18 18',
		calc: 'M6 3h12v18H6zM9 7h6M9 12h1M14 12h1M9 16h1M14 16h1',
		check: 'M5 12l5 5L20 7',
		help: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.7.3-1 .9-1 1.7M12 17h.01',
		problem: 'M8 8a4 4 0 0 1 8 0v7a4 4 0 0 1-8 0zM12 11v6M4 13h4M16 13h4M5 7l3 2M19 7l-3 2M5 19l3-2M19 19l-3-2',
		idea: 'M9 18h6M10 21h4M12 3a6 6 0 0 0-3.5 10.9c.6.5 1 1.2 1 2.1h5c0-.9.4-1.6 1-2.1A6 6 0 0 0 12 3z',
		doc: 'M7 3h7l5 5v13H7zM14 3v5h5M10 13h6M10 17h6',
		camera: 'M4 8h3l2-3h6l2 3h3v11H4zM12 17a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z',
		scale: 'M5 4h14a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1zM8 9a4 4 0 0 1 8 0zM12 9l1.5-2.5',
		flask: 'M9 3h6M10 3v6l-5.2 9.1A2 2 0 0 0 6.5 21h11a2 2 0 0 0 1.7-2.9L14 9V3M7.4 15h9.2',
	};

	function icon( name ) {
		var s = svg( 'svg', { viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', 'stroke-width': '2', 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'aria-hidden': 'true' } );
		svg( 'path', { d: ICONS[ name ] }, s );
		return s;
	}

	function pad( n ) {
		return ( n < 10 ? '0' : '' ) + n;
	}

	function dateStr( d ) {
		return d.getFullYear() + '-' + pad( d.getMonth() + 1 ) + '-' + pad( d.getDate() );
	}

	function todayStr() {
		return dateStr( effDate() );
	}

	function parseDate( s ) {
		var p = String( s || '' ).split( '-' );
		return new Date( +p[ 0 ], +p[ 1 ] - 1, +p[ 2 ] );
	}

	function addDays( s, n ) {
		var d = parseDate( s );
		d.setDate( d.getDate() + n );
		return dateStr( d );
	}

	// Whole days from a to b, DST-proof (compares UTC midnights).
	function daysBetween( a, b ) {
		var pa = String( a ).split( '-' );
		var pb = String( b ).split( '-' );
		return Math.round( ( Date.UTC( +pb[ 0 ], +pb[ 1 ] - 1, +pb[ 2 ] ) - Date.UTC( +pa[ 0 ], +pa[ 1 ] - 1, +pa[ 2 ] ) ) / 86400000 );
	}

	function nowTime() {
		var d = effDate();
		return pad( d.getHours() ) + ':' + pad( d.getMinutes() );
	}

	/*
	 * Travel mode. Dose times follow one clock: the customer's home zone
	 * (settings.baseTz), or, while they ease into a new zone, a fixed
	 * offset that moves `step` minutes a day (settings.travel). The
	 * reminder sweep uses the same rule (YeffoPrint_Tracker_Schedule::timezone()),
	 * so keep the two in step. effDate() is "now" on that clock: a Date
	 * whose local getters read the schedule's wall time.
	 */

	/** Minutes east of UTC for an IANA zone at an instant, or null if the browser doesn't know it. */
	function tzOffset( tz, ms ) {
		try {
			var o = {};
			new Intl.DateTimeFormat( 'en-US', { timeZone: tz, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' } ).formatToParts( new Date( ms ) ).forEach( function ( x ) {
				o[ x.type ] = x.value;
			} );
			var asUtc = Date.UTC( +o.year, +o.month - 1, +o.day, +o.hour % 24, +o.minute, +o.second );
			return Math.round( ( asUtc - Math.floor( ms / 1000 ) * 1000 ) / 60000 );
		} catch ( e ) {
			return null;
		}
	}

	function deviceTz() {
		try {
			return Intl.DateTimeFormat().resolvedOptions().timeZone || '';
		} catch ( e ) {
			return '';
		}
	}

	// Assigned on first use: todayStr() runs while the app's state is still being set up.
	var offsetMemo;

	function effectiveOffset( ms ) {
		var s = ( state && state.records.settings.me ) || {};
		var key = Math.floor( ms / 60000 ) + '|' + JSON.stringify( [ s.baseTz, s.tz, s.travel ] );
		if ( offsetMemo && offsetMemo.key === key ) {
			return offsetMemo.val;
		}
		var val = null;
		var t = s.travel;
		if ( t && t.from != null && t.to != null && t.at ) {
			var diff = t.to - t.from;
			var moved = Math.min( Math.abs( diff ), Math.max( 15, +t.step || 120 ) * Math.max( 0, Math.floor( ( ms / 1000 - t.at ) / 86400 ) ) );
			val = moved < Math.abs( diff ) ? t.from + ( diff < 0 ? -moved : moved ) : tzOffset( t.tz, ms );
		} else if ( s.baseTz || s.tz ) {
			val = tzOffset( s.baseTz || s.tz, ms );
		}
		offsetMemo = { key: key, val: val == null ? -new Date( ms ).getTimezoneOffset() : val };
		return offsetMemo.val;
	}

	/** Minutes the schedule's clock is ahead of this device's clock (0 unless traveling). */
	function clockShift( ms ) {
		return effectiveOffset( ms ) + new Date( ms ).getTimezoneOffset();
	}

	function effDate( ms ) {
		ms = ms == null ? Date.now() : ms;
		return new Date( ms + clockShift( ms ) * 60000 );
	}

	/** A date + "HH:MM" on the schedule's clock, as an ISO instant. */
	function wallToIso( date, time ) {
		var d = parseDate( date );
		var t = String( time || '00:00' ).split( ':' );
		d.setHours( +t[ 0 ], +t[ 1 ] );
		return new Date( d.getTime() - clockShift( d.getTime() ) * 60000 ).toISOString();
	}

	/** "HH:MM" on the schedule's clock, as this device's clock shows it. */
	function localTimeOf( time ) {
		var d = effDate();
		var t = String( time ).split( ':' );
		d.setHours( +t[ 0 ], +t[ 1 ], 0, 0 );
		var real = new Date( d.getTime() - clockShift( Date.now() ) * 60000 );
		return pad( real.getHours() ) + ':' + pad( real.getMinutes() );
	}

	function tzCity( tz ) {
		return String( tz || '' ).split( '/' ).pop().replace( /_/g, ' ' );
	}

	function fmtTime( t ) {
		if ( ! t ) {
			return '';
		}
		var p = t.split( ':' );
		var hr = +p[ 0 ];
		return ( ( hr % 12 ) || 12 ) + ':' + p[ 1 ] + ' ' + ( hr < 12 ? 'AM' : 'PM' );
	}

	function fmtIsoTime( iso ) {
		var d = effDate( Date.parse( iso ) );
		return isNaN( d ) ? '' : fmtTime( pad( d.getHours() ) + ':' + pad( d.getMinutes() ) );
	}

	function fmtDay( s, withYear ) {
		var d = parseDate( s );
		return DOW_LONG[ d.getDay() ] + ', ' + MONTHS[ d.getMonth() ].slice( 0, 3 ) + ' ' + d.getDate() + ( withYear ? ', ' + d.getFullYear() : '' );
	}

	function fmtNum( n, digits ) {
		if ( ! isFinite( n ) ) {
			return '–';
		}
		var d = digits == null ? 2 : digits;
		return Number( Number( n ).toFixed( d ) ).toLocaleString( 'en-US', { maximumFractionDigits: d } );
	}

	function uid( prefix ) {
		var chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
		var out = prefix || '';
		var buf = new Uint8Array( 12 );
		( window.crypto || window.msCrypto ).getRandomValues( buf );
		for ( var i = 0; i < buf.length; i++ ) {
			out += chars[ buf[ i ] % chars.length ];
		}
		return out;
	}

	function slotId( protocolId, date, time ) {
		return 'd-' + protocolId + '-' + date.replace( /-/g, '' ) + '-' + time.replace( ':', '' );
	}

	function values( obj ) {
		return Object.keys( obj || {} ).map( function ( k ) {
			return Object.assign( { id: k }, obj[ k ] );
		} );
	}

	function sameCompound( a, b ) {
		return String( a || '' ).trim().toLowerCase() === String( b || '' ).trim().toLowerCase();
	}

	function loadJSON( key, fallback ) {
		try {
			var raw = window.localStorage.getItem( key );
			return raw ? JSON.parse( raw ) : fallback;
		} catch ( e ) {
			return fallback;
		}
	}

	function saveJSON( key, value ) {
		try {
			window.localStorage.setItem( key, JSON.stringify( value ) );
		} catch ( e ) {}
	}

	function removeKey( key ) {
		try {
			window.localStorage.removeItem( key );
		} catch ( e ) {}
	}

	function isStandalone() {
		// The Android app counts as installed (NATIVE is set further down, before anything renders).
		return NATIVE || window.matchMedia( '(display-mode: standalone)' ).matches || window.navigator.standalone === true;
	}

	function isIOS() {
		return /iphone|ipad|ipod/i.test( navigator.userAgent ) || ( navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1 );
	}

	/* =========================================================
	 * Schedule — keep isDueOn() in step with
	 * includes/tracker/class-tracker-schedule.php
	 * ======================================================= */

	function isDueOn( p, date ) {
		if ( ! p || p.paused || ! p.start ) {
			return false;
		}
		var offset = daysBetween( p.start, date );
		if ( offset < 0 ) {
			return false;
		}
		var weeks = parseInt( p.weeks, 10 ) || 0;
		if ( weeks > 0 && offset >= weeks * 7 ) {
			return false;
		}
		var cyc = cycleOf( p );
		if ( cyc && offset % ( ( cyc.on + cyc.off ) * 7 ) >= cyc.on * 7 ) {
			return false;
		}
		var s = p.schedule || {};
		switch ( s.type ) {
			case 'weekdays':
				return ( s.days || [] ).map( Number ).indexOf( parseDate( date ).getDay() ) !== -1;
			case 'interval':
				return offset % Math.max( 1, parseInt( s.every, 10 ) || 1 ) === 0;
			case 'cycle':
				var on = Math.max( 1, parseInt( s.on, 10 ) || 5 );
				var off = Math.max( 0, parseInt( s.off, 10 ) || 0 );
				return offset % ( on + off ) < on;
			default:
				return true;
		}
	}

	function timesOf( p ) {
		var t = ( p.times || [] ).filter( function ( x ) {
			return /^([01]\d|2[0-3]):[0-5]\d$/.test( x );
		} ).sort();
		return t.length ? t : [ '09:00' ];
	}

	function scheduleLabel( p ) {
		var s = p.schedule || {};
		switch ( s.type ) {
			case 'weekdays':
				var days = ( s.days || [] ).map( Number ).sort();
				if ( days.length === 7 ) {
					return 'daily';
				}
				return days.map( function ( d ) {
					return DOW_LONG[ d ];
				} ).join( ', ' );
			case 'interval':
				var n = parseInt( s.every, 10 ) || 1;
				return n === 2 ? 'every other day' : 'every ' + n + ' days';
			case 'cycle':
				return ( s.on || 5 ) + ' on / ' + ( s.off || 0 ) + ' off';
			default:
				return 'daily';
		}
	}

	function weekLabel( p, date ) {
		var weeks = parseInt( p.weeks, 10 ) || 0;
		var cyc = cycleOf( p );
		if ( cyc ) {
			var ph = cyclePhase( p, date );
			return ph ? ( ph.on ? 'on' : 'off' ) + ' wk ' + ph.week + ' of ' + ph.of : '';
		}
		if ( ! weeks ) {
			return '';
		}
		var wk = Math.floor( daysBetween( p.start, date ) / 7 ) + 1;
		return 'wk ' + Math.min( wk, weeks ) + ' of ' + weeks;
	}

	/*
	 * Cycle planner — keep in step with YeffoPrint_Tracker_Schedule
	 * (is_due_on() and dose_on()): repeating weeks on / weeks off, and
	 * titration steps that change the dose a set number of weeks in.
	 */

	/** { on, off } in weeks, or null when the protocol doesn't cycle. */
	function cycleOf( p ) {
		var c = ( p && p.cycle ) || {};
		var on = parseInt( c.on, 10 ) || 0;
		var off = parseInt( c.off, 10 ) || 0;
		return on > 0 && off > 0 ? { on: on, off: off } : null;
	}

	/** Where a date falls in the cycle: { on, week (1-based in this phase), of, round, next (first day of the next phase) }. */
	function cyclePhase( p, date ) {
		var cyc = cycleOf( p );
		if ( ! cyc || ! p.start ) {
			return null;
		}
		var offset = daysBetween( p.start, date );
		if ( offset < 0 ) {
			return null;
		}
		var len = ( cyc.on + cyc.off ) * 7;
		var inRound = offset % len;
		var on = inRound < cyc.on * 7;
		var into = on ? inRound : inRound - cyc.on * 7;
		return {
			on: on,
			week: Math.floor( into / 7 ) + 1,
			of: on ? cyc.on : cyc.off,
			round: Math.floor( offset / len ) + 1,
			next: addDays( date, ( on ? cyc.on * 7 : len ) - inRound ),
		};
	}

	/** Titration steps, earliest first: [{ week, dose }]. */
	function stepsOf( p ) {
		return ( Array.isArray( p && p.steps ) ? p.steps : [] ).filter( function ( s ) {
			return +s.week > 0 && +s.dose > 0;
		} ).map( function ( s ) {
			return { week: +s.week, dose: +s.dose };
		} ).sort( function ( a, b ) {
			return a.week - b.week;
		} );
	}

	/** The dose on a date, after any titration steps. */
	function doseOn( p, date ) {
		var dose = +p.dose || 0;
		if ( ! p.start || ! date ) {
			return dose;
		}
		var wk = Math.floor( daysBetween( p.start, date ) / 7 );
		stepsOf( p ).forEach( function ( s ) {
			if ( s.week <= wk ) {
				dose = s.dose;
			}
		} );
		return dose;
	}

	/** The titration step a date is in: { n (1 = the starting dose), of, dose, since (its first day), next: { date, dose } | null }, or null without steps. */
	function stepInfo( p, date ) {
		var steps = stepsOf( p );
		if ( ! steps.length || ! p.start ) {
			return null;
		}
		var wk = Math.floor( daysBetween( p.start, date ) / 7 );
		var n = 1;
		steps.forEach( function ( s, i ) {
			if ( s.week <= wk ) {
				n = i + 2;
			}
		} );
		var cur = n === 1 ? { week: 0, dose: +p.dose } : steps[ n - 2 ];
		var nxt = steps[ n - 1 ] || null;
		return {
			n: n,
			of: steps.length + 1,
			dose: cur.dose,
			since: addDays( p.start, cur.week * 7 ),
			next: nxt ? { date: addDays( p.start, nxt.week * 7 ), dose: nxt.dose } : null,
		};
	}

	/** "0.25 → 0.5 (wk 5) → 1 mg (wk 9)" */
	function stepsLine( p ) {
		var steps = stepsOf( p );
		if ( ! steps.length ) {
			return '';
		}
		return [ fmtNum( +p.dose, 3 ) ].concat( steps.map( function ( s ) {
			return fmtNum( s.dose, 3 ) + ' (wk ' + ( s.week + 1 ) + ')';
		} ) ).join( ' → ' ) + ' ' + unitLabel( p.unit, 2 );
	}

	function cycleLine( p ) {
		var cyc = cycleOf( p );
		return cyc ? cyc.on + ' week' + ( cyc.on === 1 ? '' : 's' ) + ' on, ' + cyc.off + ' off, repeating' : '';
	}

	/** The cycle planner's picture: a bar per week, as tall as that week's dose, with gaps for off weeks and a line at today. */
	function cyclePreview( p ) {
		var cyc = cycleOf( p );
		var steps = stepsOf( p );
		var weeks = parseInt( p.weeks, 10 ) || 0;
		var n = Math.min( 52, weeks || Math.max( cyc ? ( cyc.on + cyc.off ) * 2 : 0, steps.length ? steps[ steps.length - 1 ].week + 4 : 0, 12 ) );
		var rows = [];
		var max = 0;
		for ( var w = 0; w < n; w++ ) {
			var off = !! cyc && w % ( cyc.on + cyc.off ) >= cyc.on;
			var d = off ? 0 : doseOn( p, addDays( p.start, w * 7 ) );
			rows.push( { off: off, d: d } );
			max = Math.max( max, d );
		}
		var W = 320;
		var TOP = 22;
		var BASE = 96;
		var bw = W / n;
		var color = protocolColor( p );
		var s = svg( 'svg', { class: 'ypt-cplan__svg', viewBox: '0 0 ' + W + ' 118', role: 'img', 'aria-label': [ stepsLine( p ), cycleLine( p ) ].filter( Boolean ).join( '. ' ) || 'Cycle plan' } );
		var lastLabelX = -99;
		var prev = null;
		rows.forEach( function ( r, i ) {
			var x = i * bw;
			if ( r.off ) {
				svg( 'rect', { x: x + 1, y: BASE - 3, width: Math.max( 1, bw - 2 ), height: 3, rx: 1, class: 'c-off' }, s );
			} else {
				var bh = max ? Math.max( 3, ( r.d / max ) * ( BASE - TOP ) ) : 3;
				svg( 'rect', { x: x + 1, y: BASE - bh, width: Math.max( 1, bw - 2 ), height: bh, rx: Math.min( 3, bw / 3 ), fill: color, opacity: '0.85' }, s );
				if ( r.d !== prev && x - lastLabelX >= 34 ) {
					svg( 'text', { x: x + 1, y: BASE - bh - 5, class: 'c-val' }, s ).textContent = fmtNum( r.d, 3 );
					lastLabelX = x;
				}
				prev = r.d;
			}
		} );
		var every = n <= 12 ? 2 : n <= 26 ? 4 : 8;
		for ( var k = 0; k < n; k += every ) {
			svg( 'text', { x: k * bw + 1, y: 112, class: 'c-wk' }, s ).textContent = 'wk ' + ( k + 1 );
		}
		var t = daysBetween( p.start, todayStr() );
		if ( t >= 0 && t < n * 7 ) {
			var tx = ( t / 7 ) * bw;
			svg( 'line', { x1: tx, x2: tx, y1: TOP - 14, y2: BASE + 2, class: 'c-today' }, s );
			svg( 'text', { x: Math.min( tx + 3, W - 30 ), y: TOP - 8, class: 'c-wk' }, s ).textContent = 'today';
		}
		return h( 'div', null, s,
			steps.length ? h( 'p', { class: 'ypt-hint', style: { marginTop: '4px' } }, 'Dose: ' + stepsLine( p ) ) : null,
			cyc ? h( 'p', { class: 'ypt-hint', style: { marginTop: '2px' } }, cycleLine( p ) + ( weeks ? ', for ' + weeks + ' weeks.' : '.' ) ) : null );
	}

	function protocols() {
		return values( state.records.protocol ).sort( function ( a, b ) {
			return String( a.compound ).localeCompare( String( b.compound ) );
		} );
	}

	/** Every scheduled dose on a date, earliest first, each with its logged record (if any). */
	function slotsOn( date ) {
		var out = [];
		protocols().forEach( function ( p ) {
			if ( ! isDueOn( p, date ) ) {
				return;
			}
			timesOf( p ).forEach( function ( t ) {
				var id = slotId( p.id, date, t );
				out.push( { id: id, protocol: p, date: date, time: t, log: state.records.dose[ id ] || null } );
			} );
		} );
		return out.sort( function ( a, b ) {
			return a.time < b.time ? -1 : a.time > b.time ? 1 : String( a.protocol.compound ).localeCompare( String( b.protocol.compound ) );
		} );
	}

	function extrasOn( date ) {
		return values( state.records.dose ).filter( function ( d ) {
			return d.date === date && d.id.indexOf( 'x-' ) === 0;
		} ).sort( function ( a, b ) {
			return String( a.time ).localeCompare( String( b.time ) );
		} );
	}

	/** 'full' | 'part' | 'miss' | 'none' (nothing scheduled) | 'future' */
	function dayStatus( date, filterId ) {
		if ( date > todayStr() ) {
			return 'future';
		}
		var slots = slotsOn( date ).filter( function ( s ) {
			return ! filterId || s.protocol.id === filterId;
		} );
		var extras = extrasOn( date ).filter( function ( d ) {
			return ! filterId || d.protocolId === filterId;
		} );
		if ( ! slots.length ) {
			return extras.length ? 'full' : 'none';
		}
		var taken = slots.filter( function ( s ) {
			return s.log && s.log.status === 'taken';
		} ).length;
		if ( taken === slots.length ) {
			return 'full';
		}
		// Today isn't "missed" while doses are still ahead.
		if ( date === todayStr() ) {
			return taken ? 'part' : 'none';
		}
		return taken ? 'part' : 'miss';
	}

	function adherence( days ) {
		var scheduled = 0;
		var taken = 0;
		var today = todayStr();
		for ( var i = 0; i < days; i++ ) {
			var date = addDays( today, -i );
			slotsOn( date ).forEach( function ( s ) {
				// Today's later doses don't count against anyone yet.
				if ( date === today && s.time > nowTime() && ! s.log ) {
					return;
				}
				scheduled++;
				if ( s.log && s.log.status === 'taken' ) {
					taken++;
				}
			} );
		}
		return scheduled ? Math.round( ( taken / scheduled ) * 100 ) : null;
	}

	function streak() {
		var today = todayStr();
		var n = 0;
		var st = dayStatus( today );
		if ( st === 'full' ) {
			n++;
		}
		for ( var i = 1; i < 730; i++ ) {
			var date = addDays( today, -i );
			var s = dayStatus( date );
			if ( s === 'none' ) {
				// Nothing scheduled: doesn't break a streak, unless we've
				// gone back before any protocol existed.
				if ( ! protocols().some( function ( p ) {
					return p.start && p.start <= date;
				} ) ) {
					break;
				}
				continue;
			}
			if ( s !== 'full' ) {
				break;
			}
			n++;
		}
		return n;
	}

	function nextDue( p ) {
		var today = todayStr();
		for ( var i = 0; i < 400; i++ ) {
			var d = addDays( today, i );
			if ( isDueOn( p, d ) ) {
				return d;
			}
		}
		return null;
	}

	/* =========================================================
	 * Vial math (same as the Peptide Calculator)
	 * ======================================================= */

	function vialConcentration( v ) {
		if ( ! v ) {
			return null;
		}
		if ( v.mode === 'conc' ) {
			return +v.conc > 0 ? { amount: +v.conc, unit: 'mg' } : null;
		}
		if ( +v.amount > 0 && +v.water > 0 ) {
			return { amount: v.amount / v.water, unit: v.mode === 'iu' ? 'IU' : 'mg' };
		}
		return null;
	}

	function vialTotalUnits( v ) {
		var ml = v.mode === 'conc' ? +v.volume : +v.water;
		return ml > 0 ? ml * 100 : null;
	}

	function isBlend( v ) {
		return !! v && v.mode === 'blend' && Array.isArray( v.parts );
	}

	/** The blend part a dose is measured by, or null for the whole blend. */
	function blendPart( v, doseOf ) {
		if ( ! doseOf || ! isBlend( v ) ) {
			return null;
		}
		return v.parts.filter( function ( x ) {
			return sameCompound( x.name, doseOf );
		} )[ 0 ] || null;
	}

	/** "BPC-157 10 mg + TB-500 5 mg" */
	function blendSummary( v ) {
		return v.parts.map( function ( x ) {
			return x.name + ' ' + fmtNum( +x.amount ) + ' mg';
		} ).join( ' + ' );
	}

	/** What one dose of `units` from a blend holds: "250 mcg BPC-157 + 125 mcg TB-500". */
	function blendDoseLine( v, units ) {
		if ( ! isBlend( v ) || ! ( +v.water > 0 ) || ! ( units > 0 ) ) {
			return '';
		}
		return v.parts.map( function ( x ) {
			var mg = ( +x.amount / +v.water ) * ( units / 100 );
			return mg < 1 ? fmtNum( mg * 1000, 1 ) + ' mcg ' + x.name : fmtNum( mg, 3 ) + ' mg ' + x.name;
		} ).join( ' + ' );
	}

	/**
	 * Syringe units for one dose from this vial, or null when the units don't line up (e.g. an IU dose from an mg vial).
	 * `doseOf` (blends only): the peptide the dose is measured by; otherwise the dose is of the whole blend.
	 */
	function unitsForDose( dose, unit, v, doseOf ) {
		dose = parseFloat( dose );
		if ( ! ( dose > 0 ) ) {
			return null;
		}
		if ( unit === 'units' ) {
			return dose;
		}
		if ( unit === 'mL' ) {
			return dose * 100;
		}
		var c = vialConcentration( v );
		var part = blendPart( v, doseOf );
		if ( part ) {
			c = +part.amount > 0 && +v.water > 0 ? { amount: part.amount / v.water, unit: 'mg' } : null;
		}
		if ( ! c ) {
			return null;
		}
		var amount = null;
		if ( c.unit === 'mg' && ( unit === 'mg' || unit === 'mcg' ) ) {
			amount = unit === 'mcg' ? dose / 1000 : dose;
		} else if ( c.unit === 'IU' && unit === 'IU' ) {
			amount = dose;
		}
		return amount == null ? null : ( amount / c.amount ) * 100;
	}

	function vials( includeFinished ) {
		return values( state.records.vial ).filter( function ( v ) {
			return includeFinished || ! v.finished;
		} ).sort( function ( a, b ) {
			return String( b.mixed ).localeCompare( String( a.mixed ) );
		} );
	}

	/** The vial (or pen, for pen protocols) a protocol draws from: the newest open one of the same compound. */
	function currentVial( p ) {
		if ( ! usesVial( p ) ) {
			return null;
		}
		var kind = p.device === 'pen' ? 'pen' : 'vial';
		var list = vials( false ).filter( function ( v ) {
			return sameCompound( v.compound, p.compound ) && ( v.kind || 'vial' ) === kind;
		} );
		return list[ 0 ] || null;
	}

	function unitsUsed( v ) {
		return values( state.records.dose ).reduce( function ( sum, d ) {
			return d.vialId === v.id && d.status === 'taken' && +d.units > 0 ? sum + +d.units : sum;
		}, 0 );
	}

	function vialInfo( v ) {
		var total = vialTotalUnits( v );
		var used = unitsUsed( v );
		var left = total == null ? null : Math.max( 0, total - used );
		var p = protocols().filter( function ( x ) {
			return sameCompound( x.compound, v.compound ) && ! x.paused;
		} )[ 0 ] || null;
		var today = todayStr();
		var per = p ? unitsForDose( doseOn( p, today ), p.unit, v, p.doseOf ) : null;
		var exp = vialExpiry( v );
		var dosesLeft = null;
		var usable = null;
		// The date of the last scheduled dose this vial still covers.
		var lastDose = null;
		if ( p && left != null && per ) {
			// Walk the doses still to take (today's logged ones are already
			// out of the vial), each at its own dose, so titration counts.
			var remaining = left;
			var dates = upcomingDoseDates( p ).dates;
			var lastPer = per;
			dosesLeft = 0;
			usable = 0;
			for ( var i = 0; i < dates.length; i++ ) {
				var u = unitsForDose( doseOn( p, dates[ i ] ), p.unit, v, p.doseOf );
				if ( ! ( u > 0 ) || remaining + 0.0001 < u ) {
					break;
				}
				remaining -= u;
				lastPer = u;
				dosesLeft++;
				lastDose = dates[ i ];
				if ( ! exp || dates[ i ] < exp ) {
					usable++;
				}
			}
			// The schedule ends before the vial does: what's left is still doses.
			if ( i === dates.length ) {
				dosesLeft += Math.floor( ( remaining + 0.0001 ) / lastPer );
				usable = exp ? usable : dosesLeft;
			}
		}
		return {
			total: total,
			left: left,
			pct: total ? Math.max( 0, Math.min( 100, ( left / total ) * 100 ) ) : 0,
			protocol: p,
			perDose: per,
			dosesLeft: dosesLeft,
			// Doses it still covers before it expires (the same as dosesLeft without an expiry).
			usable: usable,
			lastDose: lastDose,
			age: v.mixed ? daysBetween( v.mixed, today ) : 0,
			expires: exp,
			expiresIn: exp ? daysBetween( today, exp ) : null,
			expired: !! exp && exp <= today,
		};
	}

	/* ---------- Vial expiry: a countdown from the mix date ---------- */

	/** Days a mixed vial keeps: its own setting, else VIAL_GOOD_DAYS. 0 means the customer turned the countdown off. */
	function vialGoodFor( v ) {
		if ( ! v || v.goodFor == null || v.goodFor === '' ) {
			return VIAL_GOOD_DAYS;
		}
		return Math.max( 0, parseInt( v.goodFor, 10 ) || 0 );
	}

	/** The first day the vial shouldn't be used ("expires Oct 30"), or null. */
	function vialExpiry( v ) {
		var days = vialGoodFor( v );
		return days && v.mixed ? addDays( v.mixed, days ) : null;
	}

	/** "Expires Oct 30 · 12 days left" with a tone for the tag. */
	function expiryTag( info ) {
		if ( ! info.expires ) {
			return null;
		}
		var on = fmtDay( info.expires ).replace( /^\w+, /, '' );
		var n = info.expiresIn;
		if ( n <= 0 ) {
			return { tone: 'bad', text: n === 0 ? 'Expires today' : 'Expired ' + on };
		}
		return { tone: n <= EXPIRY_WARN_DAYS ? 'warn' : 'ok', text: 'Expires ' + on + ' · ' + ( n === 1 ? '1 day left' : n + ' days left' ) };
	}

	function protocolColor( p ) {
		return p && p.color ? p.color : COLORS[ 0 ];
	}

	function colorForCompound( name ) {
		var p = protocols().filter( function ( x ) {
			return sameCompound( x.compound, name );
		} )[ 0 ];
		return p ? protocolColor( p ) : '#9A9A9E';
	}

	function unitLabel( unit, dose ) {
		return UNIT_PLURAL[ unit ] && +dose !== 1 ? UNIT_PLURAL[ unit ] : unit || '';
	}

	/*
	 * Pills are counted ("2 tablets"), so a tablet or capsule also keeps its
	 * strength: the dosage on the bottle, like 500 mg. Stored on protocols
	 * and dose logs as strength + strengthUnit.
	 */
	var PILL_UNITS = [ 'tablet', 'capsule' ];
	var STRENGTH_UNITS = [ 'mg', 'mcg', 'g', 'IU' ];

	/** { amount, unit } for a tablet / capsule record with its strength filled in, else null. */
	function strengthOf( src, unit ) {
		unit = unit || ( src && src.unit );
		if ( ! src || PILL_UNITS.indexOf( unit ) === -1 || ! ( +src.strength > 0 ) || STRENGTH_UNITS.indexOf( src.strengthUnit ) === -1 ) {
			return null;
		}
		return { amount: +src.strength, unit: src.strengthUnit };
	}

	/** "250 mcg", "2 tablets" — and with `src` (a protocol or dose log) a pill's strength: "2 tablets (500 mg each)". */
	function amountLabel( dose, unit, src ) {
		var s = strengthOf( src, unit );
		return fmtNum( +dose, 3 ) + ' ' + unitLabel( unit, dose ) + ( s ? ' (' + fmtNum( s.amount, 3 ) + ' ' + s.unit + ( +dose === 1 ? ')' : ' each)' ) : '' );
	}

	function routeInfo( value ) {
		return ROUTES.filter( function ( r ) {
			return r.v === value;
		} )[ 0 ] || ROUTES[ ROUTES.length - 1 ];
	}

	function isInjected( route ) {
		return ! route || route === 'Subcutaneous' || route === 'Intramuscular';
	}

	/** Syringe and pen injections have a vial or pen to mix, and units to draw or dial; single-use injectors don't. */
	function usesVial( p ) {
		return isInjected( p.route ) && p.device !== 'single';
	}

	function isPen( v ) {
		return v && v.kind === 'pen';
	}

	var PEN_ML = 3;

	var DEVICES = [ [ 'syringe', 'Syringe' ], [ 'pen', 'Multi-dose pen' ], [ 'single', 'Single-use' ] ];

	/* =========================================================
	 * Injection sites (rotation)
	 *
	 * A taken injection dose stores `site` (one of SITES' ids). The next
	 * spot suggested is the one in the protocol's rotation that has gone
	 * longest without a dose, counting every injection the customer logged
	 * (any compound), so two protocols rotate around each other. Protocols
	 * keep `sites` (the spots the customer uses; empty = every spot for
	 * the route) and `siteOff` (don't suggest or ask).
	 *
	 * The map is drawn as if looking in a mirror: the customer's left side
	 * is on the left in both the front and back views.
	 * ======================================================= */

	// `im` / `subq`: which injections the spot suits. x/y are on the body map (front figure centered at 80, back at 240).
	var SITES = [
		{ id: 'delt-l', label: 'Left shoulder', subq: false, im: true, x: 43, y: 66 },
		{ id: 'delt-r', label: 'Right shoulder', subq: false, im: true, x: 117, y: 66 },
		{ id: 'belly-ul', label: 'Belly, upper left', subq: true, im: false, x: 68, y: 106 },
		{ id: 'belly-ur', label: 'Belly, upper right', subq: true, im: false, x: 92, y: 106 },
		{ id: 'belly-ll', label: 'Belly, lower left', subq: true, im: false, x: 68, y: 132 },
		{ id: 'belly-lr', label: 'Belly, lower right', subq: true, im: false, x: 92, y: 132 },
		{ id: 'thigh-l', label: 'Left thigh', subq: true, im: true, x: 66, y: 214 },
		{ id: 'thigh-r', label: 'Right thigh', subq: true, im: true, x: 94, y: 214 },
		{ id: 'arm-l', label: 'Back of left arm', subq: true, im: false, x: 202, y: 92 },
		{ id: 'arm-r', label: 'Back of right arm', subq: true, im: false, x: 278, y: 92 },
		{ id: 'flank-l', label: 'Left love handle', subq: true, im: false, x: 220, y: 128 },
		{ id: 'flank-r', label: 'Right love handle', subq: true, im: false, x: 260, y: 128 },
		{ id: 'glute-l', label: 'Left glute', subq: true, im: true, x: 226, y: 160 },
		{ id: 'glute-r', label: 'Right glute', subq: true, im: true, x: 254, y: 160 },
	];

	function siteById( id ) {
		return SITES.filter( function ( s ) {
			return s.id === id;
		} )[ 0 ] || null;
	}

	function siteLabel( id ) {
		var s = siteById( id );
		return s ? s.label : '';
	}

	/** Spots that suit the route: intramuscular gets shoulders, thighs and glutes; everything else under the skin. */
	function sitesForRoute( route ) {
		var im = route === 'Intramuscular';
		return SITES.filter( function ( s ) {
			return im ? s.im : s.subq;
		} );
	}

	/** The spots a protocol rotates through (all of the route's spots unless the customer narrowed it down). */
	function rotationOf( p ) {
		var all = sitesForRoute( p.route );
		var mine = all.filter( function ( s ) {
			return ( p.sites || [] ).indexOf( s.id ) !== -1;
		} );
		return mine.length ? mine : all;
	}

	function tracksSites( p ) {
		return !! p && isInjected( p.route ) && ! p.siteOff;
	}

	/** { siteId: 'YYYY-MM-DD' of the latest taken dose there }, optionally ignoring one dose (the one being edited). */
	function siteLastUsed( exceptId ) {
		var last = {};
		values( state.records.dose ).forEach( function ( d ) {
			if ( d.status !== 'taken' || ! d.site || d.id === exceptId ) {
				return;
			}
			var key = d.date + ( d.at || '' );
			if ( ! last[ d.site ] || key > last[ d.site ].key ) {
				last[ d.site ] = { key: key, date: d.date };
			}
		} );
		Object.keys( last ).forEach( function ( k ) {
			last[ k ] = last[ k ].date;
		} );
		return last;
	}

	/** The spot in the rotation that has rested longest (never-used spots first, in map order). */
	function nextSite( p, exceptId ) {
		var last = siteLastUsed( exceptId );
		var best = null;
		rotationOf( p ).forEach( function ( s ) {
			if ( ! best || ( last[ s.id ] || '' ) < ( last[ best.id ] || '' ) ) {
				best = s;
			}
		} );
		return best ? best.id : '';
	}

	function restedLabel( date ) {
		if ( ! date ) {
			return 'Not used yet';
		}
		var n = daysBetween( date, todayStr() );
		return n <= 0 ? 'Used today' : n === 1 ? 'Used yesterday' : 'Used ' + n + ' days ago';
	}

	/** 'recent' (0-2 days), 'week' (3-6 days) or 'rested'. */
	function siteHeat( date ) {
		if ( ! date ) {
			return 'rested';
		}
		var n = daysBetween( date, todayStr() );
		return n <= 2 ? 'recent' : n <= 6 ? 'week' : 'rested';
	}

	/**
	 * The body map. opts:
	 *   sites     spots to draw as choices (others are hidden)
	 *   selected  id, or an array of ids when `multi`
	 *   multi     tap toggles (the protocol sheet's "spots you use")
	 *   last      siteLastUsed() map, to shade by how recently each spot was used
	 *   onPick    function( id )
	 */
	function bodyMap( opts ) {
		var s = svg( 'svg', { class: 'ypt-body', viewBox: '0 0 320 300', role: 'group', 'aria-label': 'Body map, front and back' } );
		[ 80, 240 ].forEach( function ( cx ) {
			var g = svg( 'g', { class: 'b-figure', 'aria-hidden': 'true' }, s );
			svg( 'circle', { cx: cx, cy: 24, r: 15 }, g );
			svg( 'rect', { x: cx - 6, y: 36, width: 12, height: 12, rx: 3 }, g );
			// Torso (shoulders to hips), arms, legs.
			svg( 'path', { d: 'M' + ( cx - 34 ) + ' 56 Q' + ( cx - 34 ) + ' 46 ' + ( cx - 22 ) + ' 46 L' + ( cx + 22 ) + ' 46 Q' + ( cx + 34 ) + ' 46 ' + ( cx + 34 ) + ' 56 L' + ( cx + 28 ) + ' 116 L' + ( cx + 30 ) + ' 170 L' + ( cx - 30 ) + ' 170 L' + ( cx - 28 ) + ' 116 Z' }, g );
			svg( 'path', { d: 'M' + ( cx - 34 ) + ' 54 L' + ( cx - 48 ) + ' 120 L' + ( cx - 46 ) + ' 160', class: 'b-limb' }, g );
			svg( 'path', { d: 'M' + ( cx + 34 ) + ' 54 L' + ( cx + 48 ) + ' 120 L' + ( cx + 46 ) + ' 160', class: 'b-limb' }, g );
			svg( 'path', { d: 'M' + ( cx - 15 ) + ' 168 L' + ( cx - 17 ) + ' 280', class: 'b-limb b-leg' }, g );
			svg( 'path', { d: 'M' + ( cx + 15 ) + ' 168 L' + ( cx + 17 ) + ' 280', class: 'b-limb b-leg' }, g );
			if ( cx === 80 ) {
				svg( 'circle', { cx: cx, cy: 119, r: 2, class: 'b-navel' }, g );
			}
			svg( 'text', { x: cx, y: 298, class: 'b-caption' }, s ).textContent = cx === 80 ? 'Front' : 'Back';
			svg( 'text', { x: cx - 58, y: 298, class: 'b-side' }, s ).textContent = 'L';
			svg( 'text', { x: cx + 58, y: 298, class: 'b-side' }, s ).textContent = 'R';
		} );
		var picked = opts.multi ? opts.selected || [] : [ opts.selected ];
		var last = opts.last || {};
		opts.sites.forEach( function ( site ) {
			var on = picked.indexOf( site.id ) !== -1;
			var cls = 'b-site' + ( on ? ' is-on' : '' ) + ( opts.multi ? '' : ' b-site--' + siteHeat( last[ site.id ] ) );
			var g = svg( 'g', { class: cls, role: opts.multi ? 'checkbox' : 'radio', tabindex: '0', 'aria-checked': on ? 'true' : 'false', 'aria-label': site.label + ( opts.multi ? '' : ', ' + restedLabel( last[ site.id ] ).toLowerCase() ) }, s );
			svg( 'circle', { cx: site.x, cy: site.y, r: 18, class: 'b-hit' }, g );
			svg( 'circle', { cx: site.x, cy: site.y, r: 9, class: 'b-dot' }, g );
			if ( on ) {
				svg( 'path', { d: 'M' + ( site.x - 4 ) + ' ' + site.y + ' l3 3 l5 -6', class: 'b-check' }, g );
			}
			function pick() {
				if ( opts.onPick ) {
					opts.onPick( site.id );
				}
			}
			g.addEventListener( 'click', pick );
			g.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Enter' || e.key === ' ' ) {
					e.preventDefault();
					pick();
				}
			} );
		} );
		return s;
	}

	function mapLegend() {
		return h( 'div', { class: 'ypt-legend ypt-legend--sites' },
			h( 'span', null, h( 'i', { class: 'is-recent' } ), 'Last 2 days' ),
			h( 'span', null, h( 'i', { class: 'is-week' } ), 'This week' ),
			h( 'span', null, h( 'i', { class: 'is-rested' } ), 'Rested' )
		);
	}

	/**
	 * Pick a spot for one dose: its map, the spot's label and when it was
	 * last used. Returns the node; `onChange( id )` fires on every tap, and
	 * once up front with the suggestion unless `noDefault` (fixing a dose
	 * that was logged without a spot shouldn't invent one).
	 */
	function sitePicker( p, current, exceptId, onChange, noDefault ) {
		var wrap = h( 'div', { class: 'ypt-sitepick' } );
		var last = siteLastUsed( exceptId );
		var suggested = nextSite( p, exceptId );
		var sel = current || ( noDefault ? '' : suggested );
		// A spot logged outside the rotation (or before it changed) still shows.
		var shown = rotationOf( p ).slice();
		if ( sel && ! shown.some( function ( s ) {
			return s.id === sel;
		} ) && siteById( sel ) ) {
			shown.push( siteById( sel ) );
		}
		function draw() {
			wrap.textContent = '';
			wrap.appendChild( bodyMap( { sites: shown, selected: sel, last: last, onPick: function ( id ) {
				sel = id;
				onChange( id );
				draw();
			} } ) );
			wrap.appendChild( sel ? h( 'p', { class: 'ypt-sitepick__line' },
				h( 'b', null, siteLabel( sel ) ),
				' · ' + restedLabel( last[ sel ] ) + ( sel === suggested ? ' · suggested' : '' ) ) : h( 'p', { class: 'ypt-sitepick__line ypt-muted' }, 'Tap the spot you used.' ) );
			wrap.appendChild( mapLegend() );
		}
		draw();
		if ( sel ) {
			onChange( sel );
		}
		return wrap;
	}

	/** From a dose card: choose where this dose goes (before taking it) or fix where it went. */
	function openSiteSheet( slot ) {
		var p = slot.protocol;
		var log = slot.log;
		var site = log ? log.site : ui.siteChoice[ slot.id ];
		var body = [
			h( 'p', { class: 'ypt-muted' }, log ? 'Tap where you injected.' : 'The highlighted spot has rested longest. Tap another spot to use it instead.' ),
			sitePicker( p, site, log ? slot.id : null, function ( id ) {
				site = id;
			}, !! log ),
		];
		openSheet( log ? 'Where you injected' : 'Where to inject', p.compound, body,
			h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--primary ypt-btn--block', onclick: function () {
				if ( log ) {
					closeSheet();
					if ( site ) {
						put( 'dose', slot.id, Object.assign( {}, log, { site: site } ) );
					}
					return;
				}
				ui.siteChoice[ slot.id ] = site;
				closeSheet();
				logDose( slot, 'taken' );
			} }, log ? 'Save' : 'Take dose here' ) );
	}


	/** Unit choices: buttons when a few short ones fit on a phone, otherwise a dropdown. The current unit is always offered. */
	function unitPicker( units, current, onPick ) {
		var list = units.indexOf( current ) === -1 && current ? units.concat( [ current ] ) : units;
		var opts = list.map( function ( u ) {
			return [ u, UNIT_PLURAL[ u ] || u ];
		} );
		var chars = opts.reduce( function ( n, o ) {
			return n + o[ 1 ].length;
		}, 0 );
		if ( list.length <= 5 && chars <= 18 ) {
			return seg( opts, current, onPick );
		}
		return h( 'select', { class: 'ypt-select', 'aria-label': 'Unit', onchange: function ( e ) {
			onPick( e.target.value );
		} }, opts.map( function ( o ) {
			return h( 'option', { value: o[ 0 ], selected: o[ 0 ] === current }, o[ 1 ] );
		} ) );
	}

	/* =========================================================
	 * Sync: local copy + queued writes + REST
	 * ======================================================= */

	function persist() {
		saveJSON( STORE_KEY, { records: state.records, push: state.push, shares: state.shares, snoozes: state.snoozes, savedAt: Date.now() } );
		saveJSON( QUEUE_KEY, queue );
	}

	function api( method, path, body, retried ) {
		return fetch( CFG.restUrl + path, {
			method: method,
			credentials: 'same-origin',
			cache: 'no-store',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
			body: body === undefined ? undefined : JSON.stringify( body ),
		} ).then( function ( res ) {
			return res.json().catch( function () {
				return {};
			} ).then( function ( json ) {
				if ( res.ok ) {
					return json;
				}
				// A nonce older than a day (the app stayed open, or opened
				// from the Home Screen's cached copy): get a fresh one once.
				// WordPress answers an expired nonce with its own
				// rest_cookie_invalid_nonce before our check even runs.
				if ( res.status === 403 && NONCE_ERRORS.indexOf( json.code ) !== -1 && ! retried ) {
					return fetch( CFG.appUrl + 'session', { credentials: 'same-origin', cache: 'no-store' } )
						.then( function ( r ) {
							return r.json();
						} )
						.then( function ( n ) {
							if ( ! n.signedIn ) {
								var out = new Error( 'Signed out' );
								out.status = 401;
								throw out;
							}
							CFG.nonce = n.nonce;
							return api( method, path, body, true );
						} );
				}
				var err = new Error( json.message || 'Request failed' );
				err.status = res.status;
				err.code = json.code;
				throw err;
			} );
		} );
	}

	function isNetworkError( err ) {
		return ! err.status;
	}

	function handleAuthError( err ) {
		if ( err.status === 401 || ( err.status === 403 && NONCE_ERRORS.indexOf( err.code ) !== -1 ) ) {
			// Signed out elsewhere — go back through sign-in.
			removeKey( STORE_KEY );
			removeKey( QUEUE_KEY );
			window.location.href = CFG.loginUrl;
			return true;
		}
		return false;
	}

	function put( kind, id, data ) {
		state.records[ kind ][ id ] = data;
		queue = queue.filter( function ( q ) {
			return ! ( q.kind === kind && q.id === id );
		} );
		queue.push( { op: 'put', kind: kind, id: id, data: data } );
		persist();
		render();
		flush();
	}

	function del( kind, id ) {
		delete state.records[ kind ][ id ];
		queue = queue.filter( function ( q ) {
			return ! ( q.kind === kind && q.id === id );
		} );
		queue.push( { op: 'del', kind: kind, id: id } );
		persist();
		render();
		flush();
	}

	function flush() {
		if ( state.syncing || ! queue.length || state.offline ) {
			return Promise.resolve();
		}
		state.syncing = true;
		var item = queue[ 0 ];
		var path = 'tracker/records/' + item.kind + '/' + encodeURIComponent( item.id );
		var req = item.op === 'put' ? api( 'PUT', path, { data: item.data } ) : api( 'DELETE', path );

		return req.then( function () {
			if ( queue[ 0 ] === item ) {
				queue.shift();
			}
			persist();
			state.syncing = false;
			return flush();
		} ).catch( function ( err ) {
			state.syncing = false;
			if ( isNetworkError( err ) ) {
				setOffline( true );
				return;
			}
			if ( handleAuthError( err ) ) {
				return;
			}
			// The server refused this one change (too long, full…) — drop
			// it so it can't block everything queued behind it.
			if ( queue[ 0 ] === item ) {
				queue.shift();
			}
			persist();
			toast( err.message || 'That change couldn’t be saved.' );
			return flush();
		} );
	}

	function load() {
		var cached = loadJSON( STORE_KEY, null );
		queue = loadJSON( QUEUE_KEY, [] ) || [];
		if ( cached && cached.records ) {
			state.records = Object.assign( { protocol: {}, dose: {}, vial: {}, stock: {}, settings: {}, progress: {}, lab: {} }, cached.records );
			state.push = cached.push || state.push;
			state.shares = cached.shares || [];
			state.snoozes = cached.snoozes || [];
			state.loaded = true;
			render();
		}

		return api( 'GET', 'tracker/state' ).then( function ( json ) {
			var fresh = json.records || {};
			[ 'protocol', 'dose', 'vial', 'stock', 'settings', 'progress', 'lab' ].forEach( function ( k ) {
				state.records[ k ] = fresh[ k ] && ! Array.isArray( fresh[ k ] ) ? fresh[ k ] : {};
			} );
			// Re-apply anything still waiting to upload on top.
			queue.forEach( function ( q ) {
				if ( q.op === 'put' ) {
					state.records[ q.kind ][ q.id ] = q.data;
				} else {
					delete state.records[ q.kind ][ q.id ];
				}
			} );
			state.push = json.push || state.push;
			state.shares = Array.isArray( json.shares ) ? json.shares : [];
			state.snoozes = Array.isArray( json.snoozes ) ? json.snoozes : [];
			state.loaded = true;
			setOffline( false );
			persist();
			ensureSettings();
			render();
			flush();
		} ).catch( function ( err ) {
			if ( handleAuthError( err ) ) {
				return;
			}
			if ( isNetworkError( err ) ) {
				setOffline( true );
			}
			if ( ! state.loaded ) {
				renderMessage( 'We couldn’t load your tracker', err.message && ! isNetworkError( err ) ? err.message : 'Check your connection and try again.', true );
			}
		} );
	}

	function ensureSettings() {
		var tz = deviceTz();
		var s = state.records.settings.me || {};
		var next = Object.assign( { reminders: true }, s );
		if ( tz ) {
			next.tz = tz;
		}
		// Home zone: where the schedule's times are kept. Before travel mode it was simply the device's zone.
		if ( ! next.baseTz ) {
			next.baseTz = s.tz || tz;
		}
		// Back home, or somewhere on the same clock: nothing to ask about.
		if ( tz && next.baseTz !== tz && ! next.travel && tzOffset( next.baseTz, Date.now() ) === tzOffset( tz, Date.now() ) ) {
			next.baseTz = tz;
		}
		if ( next.skipTz && next.skipTz !== tz ) {
			delete next.skipTz;
		}
		// Finished easing into the new zone: it's the home clock now.
		if ( next.travel && effectiveOffset( Date.now() ) === tzOffset( next.travel.tz, Date.now() ) && Date.now() / 1000 - next.travel.at > 86400 * Math.ceil( Math.abs( next.travel.to - next.travel.from ) / ( next.travel.step || 120 ) ) ) {
			next.baseTz = next.travel.tz;
			delete next.travel;
		}
		if ( JSON.stringify( next ) !== JSON.stringify( s ) ) {
			put( 'settings', 'me', next );
		}
	}

	function setOffline( off ) {
		if ( state.offline !== off ) {
			state.offline = off;
			render();
		}
	}

	window.addEventListener( 'online', function () {
		setOffline( false );
		flush();
	} );
	window.addEventListener( 'offline', function () {
		setOffline( true );
	} );
	document.addEventListener( 'visibilitychange', function () {
		// Coming back to the app (e.g. from a reminder): new day, fresh data.
		if ( document.visibilityState === 'visible' && state.loaded && CFG.signedIn ) {
			if ( ui.tab === 'today' && ui.day < todayStr() && ui.lastSeenToday === ui.day ) {
				ui.day = todayStr();
			}
			load();
		}
	} );

	/* =========================================================
	 * Actions
	 * ======================================================= */

	function logDose( slot, status ) {
		var p = slot.protocol;
		var v = status === 'taken' ? currentVial( p ) : null;
		// Titration: the dose for that day, not the starting one.
		var dose = doseOn( p, slot.date );
		var units = v ? unitsForDose( dose, p.unit, v, p.doseOf ) : ( p.unit === 'units' ? dose : null );
		var site = status === 'taken' && tracksSites( p ) ? ui.siteChoice[ slot.id ] || nextSite( p ) : '';
		// Logging a past day's dose: stamp it at its scheduled time.
		var at = slot.date !== todayStr() ? wallToIso( slot.date, slot.time ) : new Date().toISOString();
		put( 'dose', slot.id, {
			protocolId: p.id,
			compound: p.compound,
			date: slot.date,
			time: slot.time,
			status: status,
			at: at,
			dose: dose,
			unit: p.unit,
			strength: strengthOf( p ) ? +p.strength : 0,
			strengthUnit: strengthOf( p ) ? p.strengthUnit : '',
			vialId: v ? v.id : '',
			units: units != null ? Math.round( units * 100 ) / 100 : null,
			note: '',
			site: site,
			tags: [],
		} );
		delete ui.siteChoice[ slot.id ];
		if ( status === 'taken' ) {
			toast( p.compound + ' logged' + ( site ? ' · ' + siteLabel( site ) : '' ), function () {
				del( 'dose', slot.id );
			} );
		}
	}

	function toast( msg, undo ) {
		var old = document.querySelector( '.ypt-toast' );
		if ( old ) {
			old.remove();
		}
		clearTimeout( toastTimer );
		var node = h( 'div', { class: 'ypt-toast', role: 'status' }, msg,
			undo ? h( 'button', { type: 'button', onclick: function () {
				undo();
				node.remove();
			} }, 'Undo' ) : null
		);
		document.body.appendChild( node );
		toastTimer = setTimeout( function () {
			node.remove();
		}, 4000 );
	}

	/* =========================================================
	 * Rendering
	 * ======================================================= */

	function render() {
		var guest = ! CFG.signedIn;
		if ( ! state.loaded && ! guest ) {
			return;
		}
		var scrollY = window.scrollY;
		var focusedId = document.activeElement && document.activeElement.id;
		root.textContent = '';
		root.appendChild( appBar() );
		if ( guest ) {
			// Signed out: the calculator, with the rest of the app a sign-in away.
			root.appendChild( renderCalc() );
			root.appendChild( guestAbout() );
		} else {
			if ( state.offline ) {
				root.appendChild( h( 'div', { class: 'ypt-offline' }, queue.length ? 'Offline · ' + queue.length + ' change' + ( queue.length === 1 ? '' : 's' ) + ' will sync' : 'Offline · showing your saved copy' ) );
			}
			var screens = { today: renderToday, history: renderHistory, progress: renderProgressTab, vials: renderSupply, calc: renderCalc, me: renderMe };
			root.appendChild( ( screens[ ui.tab ] || renderToday )() );
			root.appendChild( lockLine() );
		}
		root.appendChild( renderTabs() );
		if ( ui.sheet ) {
			root.appendChild( ui.sheet.node );
		}
		window.scrollTo( 0, scrollY );
		if ( ! guest ) {
			syncAlerts();
			syncNativeReminders();
		}
		if ( focusedId && document.getElementById( focusedId ) && ! ui.sheet ) {
			document.getElementById( focusedId ).focus();
		}
	}

	/**
	 * The YeffoHealth bar across the top of every tab: logo (back to
	 * Today), reminders bell and the Me button (the avatar; Me's tab
	 * went to the Calculator). On wide screens the tabs move up into it
	 * (tracker.css hides the bottom bar there). Signed out it has the
	 * Calculator, the locked tabs and a Sign in button.
	 */
	function appBar() {
		var guest = ! CFG.signedIn;
		var current = guest ? 'calc' : ui.tab === 'history' ? 'today' : ui.tab;
		var bellOn = state.pushOnHere === true;
		var initial = String( CFG.firstName || '' ).trim().charAt( 0 ).toUpperCase();
		function tab( id, label ) {
			var locked = guest && id !== 'calc';
			return h( 'button', { type: 'button', class: 'ypt-appbar__tab' + ( locked ? ' is-locked' : '' ), 'aria-current': current === id ? 'page' : null, 'aria-haspopup': locked ? 'dialog' : null, onclick: function () {
				if ( locked ) {
					guestLocked( id );
				} else {
					go( id );
				}
			} }, locked ? icon( 'lock' ) : null, label );
		}
		var brand = h( 'button', { type: 'button', class: 'ypt-appbar__brand', 'aria-label': guest ? 'YeffoHealth' : 'YeffoHealth, go to Today', onclick: function () {
			if ( guest ) {
				window.scrollTo( 0, 0 );
				return;
			}
			ui.day = todayStr();
			go( 'today' );
		} }, h( 'img', { src: iconUrl( 'icon-192.png' ), alt: '' } ), h( 'span', null, 'Yeffo', h( 'span', { class: 'ypt-appbar__h' }, 'Health' ) ) );
		if ( guest ) {
			return h( 'header', { class: 'ypt-appbar' },
				h( 'div', { class: 'ypt-appbar__inner' },
					brand,
					h( 'nav', { class: 'ypt-appbar__nav', 'aria-label': 'YeffoHealth' },
						tab( 'calc', 'Calculator' ),
						tab( 'today', 'Today' ),
						tab( 'progress', 'Progress' ),
						tab( 'vials', 'Supply' )
					),
					h( 'div', { class: 'ypt-appbar__actions' },
						h( 'a', { class: 'ypt-appbar__signin', href: CFG.loginUrl }, 'Sign in' )
					)
				),
				h( 'div', { class: 'ypt-stripe', 'aria-hidden': 'true' } )
			);
		}
		return h( 'header', { class: 'ypt-appbar' },
			h( 'div', { class: 'ypt-appbar__inner' },
				brand,
				h( 'nav', { class: 'ypt-appbar__nav', 'aria-label': 'Tracker' },
					tab( 'today', 'Today' ),
					tab( 'progress', 'Progress' ),
					tab( 'vials', 'Supply' ),
					tab( 'calc', 'Calculator' ),
					h( 'button', { type: 'button', class: 'ypt-appbar__tab ypt-appbar__add', 'aria-haspopup': 'dialog', onclick: openAddMenu }, '+ Add' )
				),
				h( 'div', { class: 'ypt-appbar__actions' },
					h( 'button', { type: 'button', class: 'ypt-appbar__icon' + ( bellOn ? '' : ' is-off' ), 'aria-label': bellOn ? 'Reminders are on' : 'Reminders are off on this device', onclick: function () {
						go( 'me' );
						var card = document.getElementById( 'ypt-reminders' );
						if ( card ) {
							card.scrollIntoView( { block: 'start' } );
						}
					} }, icon( bellOn ? 'bell' : 'belloff' ) ),
					h( 'button', { type: 'button', class: 'ypt-appbar__me', 'aria-label': 'Me', 'aria-current': current === 'me' ? 'page' : null, onclick: function () {
						go( 'me' );
					} }, initial || icon( 'me' ) )
				)
			),
			h( 'div', { class: 'ypt-stripe', 'aria-hidden': 'true' } )
		);
	}

	/* ---------- Appearance (dark mode) ---------- */

	var darkQuery = window.matchMedia ? window.matchMedia( '(prefers-color-scheme: dark)' ) : null;

	/** 'auto' follows the phone; 'light' / 'dark' are picked on Me. Kept per device, like the screen it's on. */
	function themePref() {
		var t = loadJSON( THEME_KEY, 'auto' );
		return t === 'light' || t === 'dark' ? t : 'auto';
	}

	function applyTheme() {
		var t = themePref();
		var el = document.documentElement;
		if ( t === 'auto' ) {
			el.removeAttribute( 'data-theme' );
		} else {
			el.setAttribute( 'data-theme', t );
		}
		var dark = t === 'dark' || ( t === 'auto' && !! darkQuery && darkQuery.matches );
		var meta = document.querySelector( 'meta[name="theme-color"]' );
		if ( meta ) {
			meta.setAttribute( 'content', dark ? '#121211' : '#FAF9F6' );
		}
	}

	function lockLine() {
		return h( 'p', { class: 'ypt-lock' }, icon( 'lock' ), 'Encrypted · only you can see this' );
	}

	function go( tab ) {
		ui.tab = tab;
		saveJSON( UI_KEY, { tab: tab } );
		render();
		window.scrollTo( 0, 0 );
	}

	function renderTabs() {
		// History (the full dose log) opens from Today's calendar button, so Today stays lit there.
		var guest = ! CFG.signedIn;
		var current = guest ? 'calc' : ui.tab === 'history' ? 'today' : ui.tab;
		function tab( id, label, ic ) {
			// Signed out, everything but the calculator explains what an account adds.
			var locked = guest && id !== 'calc';
			return h( 'button', { type: 'button', class: 'ypt-tab' + ( locked ? ' is-locked' : '' ), 'aria-current': current === id ? 'page' : null, 'aria-haspopup': locked ? 'dialog' : null, 'aria-label': locked ? label + ' (needs a free account)' : null, onclick: function () {
				if ( locked ) {
					guestLocked( id );
				} else {
					go( id );
				}
			} }, icon( ic ), label, locked ? h( 'span', { class: 'ypt-tab__lock', 'aria-hidden': 'true' }, icon( 'lock' ) ) : null );
		}
		if ( guest ) {
			return h( 'nav', { class: 'ypt-tabs', 'aria-label': 'YeffoHealth' },
				h( 'div', { class: 'ypt-tabs__inner' },
					tab( 'calc', 'Calculator', 'calc' ),
					tab( 'today', 'Today', 'today' ),
					tab( 'progress', 'Progress', 'progress' ),
					tab( 'vials', 'Supply', 'vials' )
				)
			);
		}
		return h( 'nav', { class: 'ypt-tabs', 'aria-label': 'Tracker' },
			h( 'div', { class: 'ypt-tabs__inner' },
				tab( 'today', 'Today', 'today' ),
				tab( 'progress', 'Progress', 'progress' ),
				h( 'button', { type: 'button', class: 'ypt-tab ypt-tab--add', 'aria-haspopup': 'dialog', onclick: openAddMenu }, h( 'span', { class: 'ypt-tab__plus' }, icon( 'plus' ) ), 'Add' ),
				tab( 'vials', 'Supply', 'vials' ),
				tab( 'calc', 'Calculator', 'calc' )
			)
		);
	}

	/** The + button: everything you can add or log, from any tab. */
	function openAddMenu() {
		var today = todayStr();
		var lastTaken = values( state.records.dose ).filter( function ( d ) {
			return d.date === today && d.status === 'taken';
		} ).sort( function ( a, b ) {
			return String( b.at || '' ).localeCompare( String( a.at || '' ) );
		} )[ 0 ];
		function row( ic, title, sub, onPick ) {
			return h( 'button', { type: 'button', class: 'ypt-help__row', onclick: function () {
				closeSheet();
				onPick();
			} },
				h( 'span', { class: 'ypt-help__ic ypt-help__ic--' + ic }, icon( ic ) ),
				h( 'span', { class: 'ypt-help__text' }, h( 'b', null, title ), h( 'span', null, sub ) ),
				h( 'span', { class: 'ypt-help__chev', 'aria-hidden': 'true' }, '›' )
			);
		}
		openSheet( 'What do you want to add?', 'Add', [
			h( 'div', { class: 'ypt-card ypt-list ypt-help' },
				protocols().length ? row( 'syringe', 'Log a dose', 'Something you just took, on or off schedule', function () {
					openExtraSheet( today );
				} ) : null,
				row( 'pill', 'New peptide or medication', 'Dose, schedule and reminders', function () {
					openProtocolSheet( null );
				} ),
				row( 'vials', 'Mix a vial or pen', 'Get the units to draw', function () {
					openVialSheet( null, null );
				} ),
				row( 'scale', 'Weight & measurements', 'Adds to Progress', function () {
					openProgressSheet( null );
				} ),
				row( 'camera', 'Progress photo', 'Encrypted, only you can see it', function () {
					openProgressSheet( null, { photo: true } );
				} ),
				row( 'flask', 'Lab result', 'Bloodwork values, charted with your doses', function () {
					openLabSheet( null );
				} ),
				lastTaken ? row( 'feel', 'How I feel', 'Side effects, sleep, energy · on your ' + lastTaken.compound + ' dose', function () {
					openLogSheet( lastTaken );
				} ) : null
			),
		] );
	}

	/* ---------- Progress ---------- */

	function renderProgressTab() {
		var wrap = h( 'div', null );
		wrap.appendChild( h( 'header', { class: 'ypt-top' },
			h( 'div', null, h( 'div', { class: 'ypt-eyebrow' }, 'Weight, photos & lab results' ), h( 'h1', null, 'Progress' ) )
		) );
		wrap.appendChild( renderProgress() );
		wrap.appendChild( renderLabs() );
		return wrap;
	}

	/** Today's week strip: the week of the day shown, each day with how it went (tap one to see it), then the full calendar. */
	function weekStrip( date ) {
		var start = addDays( date, -parseDate( date ).getDay() );
		var last = addDays( todayStr(), 6 );
		var strip = h( 'div', { class: 'ypt-week', role: 'group', 'aria-label': 'This week' } );
		for ( var i = 0; i < 7; i++ ) {
			( function ( d ) {
				var st = dayStatus( d, '' );
				strip.appendChild( h( 'button', {
					type: 'button',
					class: 'ypt-week__d ypt-week__d--' + st + ( d === date ? ' is-on' : '' ) + ( d === todayStr() ? ' is-today' : '' ),
					disabled: d > last,
					'aria-current': d === date ? 'date' : null,
					'aria-label': fmtDay( d ) + ', ' + { full: 'all taken', part: 'some taken', miss: 'missed', none: 'nothing due or logged', future: 'upcoming' }[ st ],
					onclick: function () {
						ui.day = d;
						render();
					},
				}, DOW[ parseDate( d ).getDay() ], h( 'b', null, String( parseDate( d ).getDate() ) ), h( 'i', { 'aria-hidden': 'true' } ) ) );
			}( addDays( start, i ) ) );
		}
		// The month calendar, older days and the PDF report.
		strip.appendChild( h( 'button', { type: 'button', class: 'ypt-week__cal', 'aria-label': 'Calendar and full history', onclick: function () {
			ui.historyDay = date > todayStr() ? todayStr() : date;
			ui.month = ui.historyDay.slice( 0, 7 );
			go( 'history' );
		} }, icon( 'cal' ), h( 'span', null, 'All' ) ) );
		return strip;
	}

	/* ---------- Today ---------- */

	function renderToday() {
		var date = ui.day;
		var isToday = date === todayStr();
		ui.lastSeenToday = todayStr();
		var slots = slotsOn( date );
		var extras = extrasOn( date );
		var done = slots.filter( function ( s ) {
			return s.log;
		} ).length;
		var pct = slots.length ? Math.round( ( done / slots.length ) * 100 ) : 0;

		var wrap = h( 'div', null );
		wrap.appendChild( h( 'header', { class: 'ypt-top' },
			h( 'div', null,
				h( 'div', { class: 'ypt-eyebrow' }, fmtDay( date ) ),
				h( 'div', { class: 'ypt-daynav' },
					h( 'h1', null, isToday ? 'Today' : ( date === addDays( todayStr(), -1 ) ? 'Yesterday' : ( date > todayStr() ? 'Upcoming' : 'Past day' ) ) ),
					h( 'button', { type: 'button', 'aria-label': 'Previous day', onclick: function () {
						ui.day = addDays( ui.day, -1 );
						render();
					} }, '‹' ),
					h( 'button', { type: 'button', 'aria-label': 'Next day', disabled: date >= addDays( todayStr(), 6 ), onclick: function () {
						ui.day = addDays( ui.day, 1 );
						render();
					} }, '›' ),
					! isToday ? h( 'button', { type: 'button', class: 'ypt-pill', style: { width: 'auto', padding: '0 10px' }, onclick: function () {
						ui.day = todayStr();
						render();
					} }, 'Today' ) : null
				)
			),
			slots.length ? h( 'div', { class: 'ypt-ring' + ( pct === 100 ? ' ypt-ring--done' : '' ), style: { '--p': pct }, role: 'img', 'aria-label': done + ' of ' + slots.length + ' doses done' },
				h( 'span', null, done + '/' + slots.length ) ) : null
		) );
		if ( protocols().length ) {
			wrap.appendChild( weekStrip( date ) );
		}

		if ( isToday ) {
			var banner = installBanner();
			if ( banner ) {
				wrap.appendChild( banner );
			}
			var news = whatsNewBanner();
			if ( news ) {
				wrap.appendChild( news );
			}
			var travel = travelCard();
			if ( travel ) {
				wrap.appendChild( travel );
			}
			var low = lowBanner();
			if ( low ) {
				wrap.appendChild( low );
			}
			var expiring = expiryBanner();
			if ( expiring ) {
				wrap.appendChild( expiring );
			}
		}
		var clockNote = travelNote();
		if ( clockNote ) {
			wrap.appendChild( clockNote );
		}

		if ( ! protocols().length ) {
			var hasVial = vials( false ).length > 0;
			wrap.appendChild( h( 'div', { class: 'ypt-card ypt-empty' },
				h( 'h2', null, hasVial ? 'Let’s set up your first peptide' : 'Let’s add your first peptide or medication' ),
				h( 'p', null, 'Once you’re set, each day shows what’s due and sends a reminder when it’s time. Peptides from a vial take two quick steps so you also see the units to draw.' ),
				h( 'ol', { class: 'ypt-steps' },
					h( 'li', { class: hasVial ? 'is-done' : null },
						h( 'b', null, 'Mix your vial' ),
						h( 'span', null, hasVial ? 'Done.' : 'What’s in the vial and how much water you added.' )
					),
					h( 'li', null,
						h( 'b', null, 'Set your dose and schedule' ),
						h( 'span', null, 'How much, how often, and what time.' )
					)
				),
				hasVial ? h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--primary', onclick: function () {
					openProtocolSheet( null, { p: { compound: vials( false )[ 0 ].compound } } );
				} }, 'Set your schedule' ) : h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--primary', onclick: function () {
					openVialSheet( null, null );
				} }, 'Mix your vial' ),
				h( 'p', { style: { marginTop: '12px', marginBottom: '0' } }, h( 'button', { type: 'button', class: 'ypt-link', onclick: function () {
					openProtocolSheet( null );
				} }, hasVial ? 'Add something else' : 'Pills, sprays, pens or anything without a vial? Add it here' ) )
			) );
			return wrap;
		}

		if ( ! slots.length && ! extras.length ) {
			var upcoming = protocols().map( function ( p ) {
				return { p: p, d: nextDue( p ) };
			} ).filter( function ( x ) {
				return x.d;
			} ).sort( function ( a, b ) {
				return a.d.localeCompare( b.d );
			} );
			wrap.appendChild( h( 'div', { class: 'ypt-card ypt-empty' },
				h( 'h2', null, 'Nothing scheduled' + ( isToday ? ' today' : '' ) ),
				upcoming.length ? h( 'p', null, 'Next up: ' + upcoming[ 0 ].p.compound + ' on ' + fmtDay( upcoming[ 0 ].d ) + '.' ) : h( 'p', null, 'All your protocols are paused or finished.' )
			) );
		}

		// Cycle planner: protocols resting this week say when they're back.
		protocols().forEach( function ( p ) {
			var ph = ! p.paused ? cyclePhase( p, date ) : null;
			if ( ! ph || ph.on || ( parseInt( p.weeks, 10 ) && daysBetween( p.start, date ) >= p.weeks * 7 ) ) {
				return;
			}
			wrap.appendChild( h( 'div', { class: 'ypt-offweek' }, h( 'span', { class: 'ypt-dot', style: { background: protocolColor( p ) } } ),
				h( 'span', null, h( 'b', null, p.compound + ': off week ' + ph.week + ' of ' + ph.of + '. ' ), 'Back on ' + fmtDay( ph.next ).replace( /^\w+, /, '' ) + ( stepsOf( p ).length ? ' at ' + amountLabel( doseOn( p, ph.next ), p.unit ) : '' ) + '.' ) ) );
		} );

		var lastGroup = null;
		slots.forEach( function ( s ) {
			var group = s.time < '12:00' ? 'Morning' : s.time < '17:00' ? 'Afternoon' : 'Evening';
			var here = localTimeOf( s.time );
			var label = group + ' · ' + fmtTime( s.time ) + ( here !== s.time ? ' · ' + fmtTime( here ) + ' here' : '' );
			if ( label !== lastGroup ) {
				wrap.appendChild( h( 'div', { class: 'ypt-eyebrow ypt-section-label' }, label ) );
				lastGroup = label;
			}
			wrap.appendChild( doseCard( s ) );
		} );

		if ( extras.length ) {
			wrap.appendChild( h( 'div', { class: 'ypt-eyebrow ypt-section-label' }, 'Extra doses' ) );
			extras.forEach( function ( d ) {
				wrap.appendChild( h( 'div', { class: 'ypt-card' },
					h( 'div', { class: 'ypt-dose' },
						h( 'div', { class: 'ypt-dot', style: { background: colorForCompound( d.compound ) } } ),
						h( 'div', { class: 'ypt-dose__main' },
							h( 'div', { class: 'ypt-dose__name' }, d.compound ),
							h( 'div', { class: 'ypt-dose__sub' }, amountLabel( d.dose, d.unit, d ) + ( tagsOf( d ).length ? ' · ' + tagsOf( d ).join( ', ' ) : '' ) + ( d.note ? ' · ' + d.note : '' ) )
						),
						h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--done', onclick: function () {
							openLogSheet( d );
						} }, '✓ ' + fmtIsoTime( d.at ) )
					)
				) );
			} );
		}

		wrap.appendChild( h( 'button', { type: 'button', class: 'ypt-card ypt-card--dashed', style: { marginTop: '8px' }, onclick: function () {
			openExtraSheet( date );
		} }, '+ Log an extra dose' ) );

		return wrap;
	}

	function doseCard( s ) {
		var p = s.protocol;
		var log = s.log;
		var v = currentVial( p );
		var dose = log && +log.dose > 0 ? +log.dose : doseOn( p, s.date );
		var units = log && log.units != null ? +log.units : ( v ? unitsForDose( dose, p.unit, v, p.doseOf ) : null );
		var how = p.device === 'pen' ? 'pen' : p.device === 'single' ? 'single-use injector' : p.route ? routeInfo( p.route ).short || p.route.toLowerCase() : '';
		var sub = [ amountLabel( dose, p.unit, log && log.strength ? log : p ) + ( p.doseOf ? ' ' + p.doseOf : '' ), how, scheduleLabel( p ), weekLabel( p, s.date ) ].filter( Boolean ).join( ' · ' );

		var actions;
		if ( log ) {
			actions = h( 'button', {
				type: 'button',
				class: 'ypt-btn ' + ( log.status === 'taken' ? 'ypt-btn--done' : 'ypt-btn--skipped' ),
				'aria-label': ( log.status === 'taken' ? 'Taken' : 'Skipped' ) + ', edit',
				onclick: function () {
					openLogSheet( Object.assign( { id: s.id }, log ) );
				},
			}, log.status === 'taken' ? '✓ ' + fmtIsoTime( log.at ) : 'Skipped' );
		} else {
			actions = h( 'div', { class: 'ypt-dose__actions' },
				h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--ghost', onclick: function () {
					logDose( s, 'skipped' );
				} }, 'Skip' ),
				h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--primary', onclick: function () {
					logDose( s, 'taken' );
				} }, 'Take' )
			);
		}

		var card = h( 'div', { class: 'ypt-card' },
			h( 'div', { class: 'ypt-dose' },
				h( 'div', { class: 'ypt-dot', style: { background: protocolColor( p ) } } ),
				h( 'button', { type: 'button', class: 'ypt-dose__main', style: { background: 'none', border: '0', padding: '0', textAlign: 'left' }, onclick: function () {
					openProtocolSheet( p );
				} },
					h( 'div', { class: 'ypt-dose__name' }, p.compound ),
					h( 'div', { class: 'ypt-dose__sub' }, sub )
				),
				actions
			)
		);

		var snoozeRow = snoozeLine( s );
		if ( snoozeRow ) {
			card.appendChild( snoozeRow );
		}

		// Titration: the first week of a new dose says so.
		var step = stepInfo( p, s.date );
		if ( step && step.n > 1 && daysBetween( step.since, s.date ) < 7 && ! log ) {
			card.appendChild( h( 'div', { class: 'ypt-stepline' }, h( 'b', null, 'New dose this week: ' + amountLabel( step.dose, p.unit ) ), ' · step ' + step.n + ' of ' + step.of ) );
		}

		var vi = v ? vialInfo( v ) : null;
		var what = p.device === 'pen' ? 'pen' : 'vial';
		// Mix day: the vial in use has nothing left for this dose or has expired (or there's none yet but some on hand).
		if ( ! log && s.date === todayStr() && usesVial( p ) && ( vi ? vi.dosesLeft === 0 || vi.expired : stockFor( p ).some( function ( x ) {
			return stockLeft( x ) > 0;
		} ) ) ) {
			var have = stockFor( p ).reduce( function ( n, x ) {
				return n + stockLeft( x );
			}, 0 );
			var plan = supplyPlan( p );
			var nth = plan.segments.filter( function ( sg ) {
				return sg.status === 'use';
			} ).length + 1;
			var total = plan.end && plan.known ? plan.segments.length + ( v ? 0 : 1 ) : 0;
			card.appendChild( h( 'div', { class: 'ypt-mixday' },
				h( 'b', null, 'Mix day. ' ), v ? ( vi.expired && vi.dosesLeft !== 0 ? 'This ' + what + ' expired ' + ( vi.expiresIn === 0 ? 'today' : fmtDay( vi.expires ).replace( /^\w+, /, '' ) ) + '.' : 'This ' + what + ' is used up.' ) : 'Mix a ' + what + ' to see the units for this dose.',
				have ? ' You have ' + have + ' on hand.' : ' You have none on hand.',
				h( 'div', { class: 'ypt-mixday__actions' },
					h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--accent', onclick: function () {
						var src = stockFor( p ).filter( function ( x ) {
							return stockLeft( x ) > 0;
						} )[ 0 ];
						var preset = v ? { kind: v.kind, mode: v.mode, amount: v.amount, water: v.water, conc: v.conc, volume: v.volume, parts: v.parts, blend: v.blend, syringe: v.syringe, goodFor: v.goodFor } : {};
						if ( src && src.form === 'premixed' ) {
							preset = Object.assign( preset, { mode: 'conc', conc: src.conc, volume: src.volume } );
						} else if ( src && ! ( v && isBlend( v ) ) ) {
							preset = Object.assign( preset, { mode: src.amountUnit === 'IU' ? 'iu' : 'mg', amount: src.amount } );
						}
						openVialSheet( null, { compound: p.compound, kind: what, preset: preset, replaces: v ? v.id : '' } );
					} }, total > 1 && nth <= total ? 'Mix ' + what + ' ' + nth + ' of ' + total : 'Mix a new ' + what ),
					have ? null : h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--white', onclick: function () {
						var mine = stockFor( p )[ 0 ];
						openStockSheet( mine || null, mine ? { bought: true } : { compound: p.compound, form: p.device === 'pen' ? 'pen' : 'powder' } );
					} }, 'I bought more' )
				)
			) );
			return card;
		}

		if ( ! log || log.status === 'taken' ) {
			if ( units != null && isFinite( units ) ) {
				var info = vi;
				var soon = ! log && info && info.expires && info.expiresIn <= EXPIRY_WARN_DAYS;
				card.appendChild( h( 'div', { class: 'ypt-draw' + ( soon ? ' ypt-draw--warn' : '' ) },
					isPen( v ) ? [ log ? 'Dialed ' : 'Dial ', h( 'b', null, fmtNum( units, 1 ) + ' units' ), ' on your pen' ] : [ log ? 'Drew ' : 'Draw ', h( 'b', null, fmtNum( units, 1 ) + ' units' ), ' on a U-100 syringe' ],
					v ? ' · ' + ( isPen( v ) ? 'pen' : 'vial' ) + ' mixed ' + fmtDay( v.mixed ).replace( /^\w+, /, '' ) : '',
					soon ? ' · expires ' + ( info.expiresIn <= 0 ? 'today' : info.expiresIn === 1 ? 'tomorrow' : 'in ' + info.expiresIn + ' days' ) : '',
					! log && info && info.dosesLeft === 1 && s.date === todayStr() ? ' · last dose in this ' + what : '',
					isBlend( v ) ? h( 'span', { class: 'ypt-draw__blend' }, 'Each dose: ' + blendDoseLine( v, units ) ) : null
				) );
			} else if ( ! log && ( p.unit === 'mcg' || p.unit === 'mg' || p.unit === 'IU' ) && usesVial( p ) ) {
				card.appendChild( h( 'button', { type: 'button', class: 'ypt-draw', style: { width: '100%', textAlign: 'left' }, onclick: function () {
					openVialSheet( null, { compound: p.compound, dose: dose, unit: p.unit, kind: p.device === 'pen' ? 'pen' : 'vial' } );
				} }, p.device === 'pen' ? 'Add your pen to see how many units to dial →' : 'Add your vial to see how many units to draw →' ) );
			}
		}
		var siteRow = siteLine( s );
		if ( siteRow ) {
			card.appendChild( siteRow );
		}
		var feel = feelLine( s );
		if ( feel ) {
			card.appendChild( feel );
		}
		return card;
	}

	/**
	 * A dose that's due and not logged yet: put its reminder off for half
	 * an hour. Android and desktop reminders have this button themselves;
	 * iPhone's don't, so this is where iPhone users snooze.
	 */
	function snoozeLine( s ) {
		// In the Android app reminders are scheduled on the phone, so the snooze is too.
		if ( s.log || s.date !== todayStr() || nowTime() < s.time || ! ( NATIVE ? state.pushOnHere === true : state.push.devices > 0 ) ) {
			return null;
		}
		var open = ( NATIVE ? nativeSnoozes() : state.snoozes ).filter( function ( z ) {
			return ( z.slots || [] ).indexOf( s.id ) !== -1 && z.at * 1000 > Date.now();
		} )[ 0 ];
		if ( open ) {
			return h( 'div', { class: 'ypt-snooze is-set' }, icon( 'bell' ), h( 'span', null, 'We’ll remind you again at ' + fmtIsoTime( new Date( open.at * 1000 ).toISOString() ) + '.' ) );
		}
		var btn = h( 'button', { type: 'button', class: 'ypt-snooze', onclick: function () {
			btn.disabled = true;
			if ( NATIVE ) {
				snoozeNative( s.id );
				render();
				toast( 'We’ll remind you again in 30 minutes' );
				return;
			}
			api( 'POST', 'tracker/snooze', { slots: [ s.id ] } ).then( function ( json ) {
				state.snoozes = Array.isArray( json.snoozes ) ? json.snoozes : state.snoozes;
				persist();
				render();
				toast( 'We’ll remind you again in 30 minutes' );
			} ).catch( function ( err ) {
				btn.disabled = false;
				if ( ! handleAuthError( err ) ) {
					toast( isNetworkError( err ) ? 'You’re offline. Connect to snooze this reminder.' : err.message || 'That reminder couldn’t be snoozed.' );
				}
			} );
		} }, icon( 'bell' ), h( 'span', null, 'Remind me in 30 min' ) );
		return btn;
	}

	/** Under a taken dose: how the customer felt (tap to change), or a nudge to add it on today's doses. */
	function feelLine( s ) {
		var log = s.log;
		if ( ! log || log.status !== 'taken' ) {
			return null;
		}
		var tags = tagsOf( log );
		if ( ! tags.length && ! log.note && s.date !== todayStr() ) {
			return null;
		}
		return h( 'button', { type: 'button', class: 'ypt-feel', onclick: function () {
			openLogSheet( Object.assign( { id: s.id }, log ) );
		} }, tags.length || log.note ? [
			tags.length ? h( 'span', { class: 'ypt-feel__tags' }, tags.map( tagChip ) ) : null,
			log.note ? h( 'span', { class: 'ypt-feel__note' }, '“' + log.note + '”' ) : null,
		] : [ h( 'span', { class: 'ypt-feel__add' }, '+ How do you feel?' ), h( 'span', { class: 'ypt-feel__hint' }, 'Side effects, sleep, energy…' ) ] );
	}

	/** "Next spot: Left thigh  Change" before the dose, "Injected: Left thigh" after (tap to fix it). */
	function siteLine( s ) {
		var p = s.protocol;
		var log = s.log;
		if ( log ? log.status !== 'taken' || ( ! log.site && ! tracksSites( p ) ) : ! tracksSites( p ) ) {
			return null;
		}
		var site = log ? log.site : ui.siteChoice[ s.id ] || nextSite( p );
		var text = log
			? ( site ? [ 'Injected: ', h( 'b', null, siteLabel( site ) ) ] : [ 'Where did you inject? ', h( 'b', null, 'Add spot' ) ] )
			: [ 'Next spot: ', h( 'b', null, siteLabel( site ) ) ];
		return h( 'button', { type: 'button', class: 'ypt-site', onclick: function () {
			openSiteSheet( s );
		} }, h( 'span', { class: 'ypt-site__pin', 'aria-hidden': 'true' } ), h( 'span', { class: 'ypt-site__text' }, text ), h( 'span', { class: 'ypt-site__go' }, log ? 'Edit' : 'Change' ) );
	}

	/* ---------- Shared protocols ---------- */

	/*
	 * A share link carries only what's on the card (includes/tracker/class-tracker-shares.php):
	 * { compound, dose, unit, route, device, doseOf, schedule, times, weeks, notes, mix?, cycle?, steps? }.
	 */

	/** "5 mg vial + 2 mL water · 10 units" for a shared mix and dose. */
	function mixLine( pr ) {
		var m = pr.mix;
		if ( ! m ) {
			return '';
		}
		var what = m.kind === 'pen' ? 'pen' : 'vial';
		var v = Object.assign( {}, m, { parts: m.parts || [] } );
		var line = m.mode === 'conc' ? fmtNum( +m.conc ) + ' mg/mL premixed' : m.mode === 'blend' ? blendSummary( v ) + ' + ' + fmtNum( +m.water ) + ' mL water' : fmtNum( +m.amount ) + ( m.mode === 'iu' ? ' IU ' : ' mg ' ) + what + ' + ' + fmtNum( +m.water ) + ' mL water';
		var u = unitsForDose( pr.dose, pr.unit, v, pr.doseOf );
		return line + ( u != null && isFinite( u ) ? ' · ' + fmtNum( u, 1 ) + ' units' : '' );
	}

	function protoCard( pr ) {
		var how = pr.device === 'pen' ? 'multi-dose pen' : pr.device === 'single' ? 'single-use injector' : pr.route ? routeInfo( pr.route ).short || String( pr.route ).toLowerCase() : '';
		var rows = [
			[ 'Dose', amountLabel( pr.dose, pr.unit, pr ) + ( pr.doseOf ? ' ' + pr.doseOf : '' ) + ( how ? ', ' + how : '' ) ],
			[ 'Schedule', ( scheduleLabel( pr ) === 'daily' ? 'Every day' : scheduleLabel( pr ).charAt( 0 ).toUpperCase() + scheduleLabel( pr ).slice( 1 ) ) + ', ' + timesOf( pr ).map( fmtTime ).join( ' & ' ) ],
			+pr.weeks ? [ 'Length', pr.weeks + ' week' + ( +pr.weeks === 1 ? '' : 's' ) ] : null,
			cycleOf( pr ) ? [ 'Cycle', cycleLine( pr ) ] : null,
			stepsOf( pr ).length ? [ 'Titration', stepsLine( pr ) ] : null,
			pr.mix ? [ 'Mixing', mixLine( pr ) ] : null,
			pr.notes ? [ 'Notes', pr.notes ] : null,
		].filter( Boolean );
		return h( 'div', { class: 'ypt-proto' },
			h( 'div', { class: 'ypt-eyebrow' }, 'Protocol' ),
			h( 'h2', null, pr.compound ),
			h( 'dl', null, rows.map( function ( r ) {
				return [ h( 'dt', null, r[ 0 ] ), h( 'dd', null, r[ 1 ] ) ];
			} ) )
		);
	}

	function openShareSheet( p ) {
		var v = currentVial( p );
		var hasPlan = !! cycleOf( p ) || stepsOf( p ).length > 0;
		var inc = { mix: !! v, weeks: !! +p.weeks, notes: false, plan: hasPlan };
		var link = { key: '', url: '' };
		var preview = h( 'div', null );
		var linkBox = h( 'div', null );
		var foot = h( 'div', { style: { display: 'flex', gap: '8px', flex: '1' } } );

		function payload() {
			var out = { compound: p.compound, dose: p.dose, unit: p.unit, route: p.route, device: p.device || '', doseOf: p.doseOf || '', strength: strengthOf( p ) ? +p.strength : 0, strengthUnit: strengthOf( p ) ? p.strengthUnit : '', schedule: p.schedule, times: timesOf( p ), weeks: inc.weeks ? +p.weeks || 0 : 0, notes: inc.notes ? p.notes || '' : '' };
			if ( inc.plan && cycleOf( p ) ) {
				out.cycle = cycleOf( p );
			}
			if ( inc.plan && stepsOf( p ).length ) {
				out.steps = stepsOf( p );
			}
			if ( inc.mix && v ) {
				out.mix = { kind: v.kind || 'vial', mode: v.mode, amount: +v.amount || 0, water: +v.water || 0, conc: +v.conc || 0, volume: +v.volume || 0 };
				if ( isBlend( v ) ) {
					out.mix.parts = v.parts;
				}
			}
			return out;
		}

		function refresh() {
			preview.textContent = '';
			preview.appendChild( protoCard( payload() ) );
			var key = JSON.stringify( payload() );
			linkBox.textContent = '';
			foot.textContent = '';
			if ( link.key === key && link.url ) {
				linkBox.appendChild( h( 'div', { class: 'ypt-linkbox' }, link.url.replace( /^https?:\/\//, '' ) ) );
				foot.appendChild( h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--block', onclick: function () {
					copyText( link.url ).then( function () {
						toast( 'Link copied' );
					} );
				} }, 'Copy link' ) );
				if ( navigator.share ) {
					foot.appendChild( h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--block ypt-btn--accent', onclick: function () {
						navigator.share( { title: p.compound + ' protocol', text: 'Here’s my ' + p.compound + ' schedule. Add it to your YeffoHealth tracker:', url: link.url } ).catch( function () {} );
					} }, 'Share…' ) );
				}
				return;
			}
			foot.appendChild( h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--block ypt-btn--accent', onclick: function () {
				var btn = this;
				if ( state.offline ) {
					toast( 'Connect to the internet to share.' );
					return;
				}
				btn.disabled = true;
				btn.textContent = 'Creating link…';
				api( 'POST', 'tracker/shares', { protocol: payload() } ).then( function ( res ) {
					link = { key: key, url: res.url };
					state.shares.unshift( { code: res.code, url: res.url, compound: p.compound, created: new Date().toISOString().slice( 0, 10 ) } );
					persist();
					refresh();
				} ).catch( function ( e ) {
					btn.disabled = false;
					btn.textContent = 'Create link';
					toast( e.message || 'Couldn’t create the link.' );
				} );
			} }, 'Create link' ) );
		}

		function check( key, label, extra ) {
			var box = h( 'button', { type: 'button', class: 'ypt-check', role: 'checkbox', 'aria-checked': inc[ key ] ? 'true' : 'false', onclick: function () {
				inc[ key ] = ! inc[ key ];
				box.setAttribute( 'aria-checked', inc[ key ] ? 'true' : 'false' );
				refresh();
			} }, h( 'i', { 'aria-hidden': 'true' } ), h( 'span', null, label, extra ? h( 'small', null, ' ' + extra ) : null ) );
			return box;
		}

		refresh();
		openSheet( 'Share this protocol', p.compound, [
			preview,
			h( 'div', { class: 'ypt-label', style: { marginTop: '16px' } }, 'Include' ),
			h( 'div', { class: 'ypt-card ypt-list', style: { marginTop: '6px' } },
				v ? check( 'mix', 'How to mix it' ) : null,
				+p.weeks ? check( 'weeks', 'Cycle length' ) : null,
				hasPlan ? check( 'plan', 'Cycle plan', [ cycleOf( p ) ? 'weeks on/off' : '', stepsOf( p ).length ? 'dose steps' : '' ].filter( Boolean ).join( ' and ' ) ) : null,
				p.notes ? check( 'notes', 'My note', '“' + p.notes.slice( 0, 40 ) + ( p.notes.length > 40 ? '…' : '' ) + '”' ) : null,
				! v && ! +p.weeks && ! p.notes && ! hasPlan ? h( 'p', { class: 'ypt-list-row ypt-muted ypt-small' }, 'The dose and schedule above.' ) : null
			),
			h( 'p', { class: 'ypt-hint ypt-share-privacy' }, icon( 'lock' ), 'Never shared: your name, your history, your vials. The link only holds what’s on the card above, and anyone with it can see that. You can stop sharing it any time from Me (your initial at the top) › Shared links.' ),
			linkBox,
		], foot );
	}

	/** Me › Shared links: copy a link again, or stop it so it no longer opens for anyone. */
	function openSharedLinkSheet( sh ) {
		var stop = h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--danger ypt-btn--block', onclick: function () {
			if ( state.offline ) {
				toast( 'Connect to the internet to stop sharing.' );
				return;
			}
			stop.disabled = true;
			api( 'DELETE', 'tracker/shares/' + encodeURIComponent( sh.code ) ).catch( function ( e ) {
				// Already gone on the server (404) is what they wanted anyway.
				if ( ! e || e.status !== 404 ) {
					throw e;
				}
			} ).then( function () {
				state.shares = state.shares.filter( function ( x ) {
					return x.code !== sh.code;
				} );
				persist();
				closeSheet();
				render();
				toast( 'Link stopped' );
			} ).catch( function ( e ) {
				stop.disabled = false;
				toast( e.message || 'Couldn’t stop the link. Check your connection.' );
			} );
		} }, 'Stop sharing' );
		openSheet( sh.compound || 'Shared link', 'Shared ' + fmtDay( sh.created, true ), [
			h( 'div', { class: 'ypt-linkbox' }, sh.url.replace( /^https?:\/\//, '' ) ),
			h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--block', style: { marginTop: '10px' }, onclick: function () {
				copyText( sh.url ).then( function () {
					toast( 'Link copied' );
				} );
			} }, 'Copy link' ),
			h( 'p', { class: 'ypt-hint' }, 'Anyone with this link can see the protocol. Stopping it means the link won’t open for anyone, including people who already have it. Anyone who already added it keeps their copy.' ),
		], stop );
	}

	function copyText( text ) {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			return navigator.clipboard.writeText( text ).catch( function () {
				return fallback();
			} );
		}
		return fallback();
		function fallback() {
			var ta = h( 'textarea', { style: { position: 'fixed', opacity: '0' } }, text );
			document.body.appendChild( ta );
			ta.select();
			try {
				document.execCommand( 'copy' );
			} catch ( e ) {}
			ta.remove();
			return Promise.resolve();
		}
	}

	var PENDING_SHARE_KEY = 'ypt-pending-share';

	/** Someone opened /tracker/p/{code} (or signed up after opening one): offer to add it. */
	function openReceivedShare( share ) {
		if ( ! share.protocol ) {
			openSheet( 'This link doesn’t work', 'Shared protocol', [ h( 'p', null, 'The person who sent it may have stopped sharing it. Ask them for a new link.' ) ],
				h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--primary ypt-btn--block', onclick: closeSheet }, 'OK' ) );
			return;
		}
		var pr = share.protocol;
		var start = todayStr();
		var dateInput = h( 'input', { class: 'ypt-input', type: 'date', value: start, 'aria-label': 'Start date', hidden: true, onchange: function ( e ) {
			start = e.target.value || todayStr();
		} } );
		var already = protocols().some( function ( x ) {
			return sameCompound( x.compound, pr.compound ) && ! x.paused;
		} );
		openSheet( 'A protocol for you', 'Shared with you', [
			protoCard( pr ),
			h( 'div', { class: 'ypt-field' },
				h( 'span', { class: 'ypt-label' }, 'Start on' ),
				seg( [ [ 'today', 'Today' ], [ 'tomorrow', 'Tomorrow' ], [ 'pick', 'Pick a date' ] ], 'today', function ( w ) {
					dateInput.hidden = w !== 'pick';
					start = w === 'tomorrow' ? addDays( todayStr(), 1 ) : w === 'pick' ? dateInput.value || todayStr() : todayStr();
				} ),
				dateInput
			),
			already ? h( 'div', { class: 'ypt-draw ypt-draw--warn' }, 'You already track ' + pr.compound + '. Adding this makes a second schedule; you can pause the old one after.' ) : null,
			h( 'div', { class: 'ypt-banner ypt-banner--info', style: { marginTop: '14px' } }, h( 'div', null, 'Doses here come from another person, not from YeffoDesign. Check them with your provider. You can change anything after adding it.' ) ),
		], h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--accent ypt-btn--block', onclick: function () {
			var id = uid( 'p' );
			var data = {
				compound: pr.compound,
				dose: +pr.dose,
				unit: pr.unit || 'mcg',
				route: pr.route || 'Subcutaneous',
				device: pr.device || '',
				schedule: Object.assign( { type: 'daily', days: [], every: 2, on: 5, off: 0 }, pr.schedule || {} ),
				times: timesOf( pr ),
				start: start,
				weeks: +pr.weeks || 0,
				color: COLORS[ protocols().length % COLORS.length ],
				notes: pr.notes || '',
				paused: false,
				doseOf: pr.doseOf || '',
				strength: strengthOf( pr ) ? +pr.strength : 0,
				strengthUnit: strengthOf( pr ) ? pr.strengthUnit : '',
				cycle: cycleOf( pr ),
				steps: stepsOf( pr ),
			};
			closeSheet();
			removeKey( PENDING_SHARE_KEY );
			put( 'protocol', id, data );
			go( 'today' );
			if ( pr.mix && usesVial( data ) && ! currentVial( data ) ) {
				toast( pr.compound + ' added. Now mix your ' + ( pr.mix.kind === 'pen' ? 'pen' : 'vial' ) + '.' );
				openVialSheet( null, { compound: pr.compound, dose: data.dose, unit: data.unit, kind: pr.mix.kind, preset: pr.mix } );
			} else {
				toast( pr.compound + ' added' );
			}
		} }, 'Add to my tracker' ) );
	}

	/* ---------- Running low (Today) ---------- */

	var LOW_KEY = 'ypt-low-dismissed';

	/** One banner for everything running low; dismissing it hides those items until tomorrow. */
	function lowBanner() {
		var dismissed = loadJSON( LOW_KEY, {} ) || {};
		var today = todayStr();
		var list = lowSupplies().filter( function ( x ) {
			return dismissed[ labelKey( x.s.compound ) ] !== today;
		} );
		if ( ! list.length ) {
			return null;
		}
		var first = list[ 0 ];
		return h( 'div', { class: 'ypt-banner ypt-banner--warn' },
			h( 'div', null, h( 'b', null, first.s.compound + ' is running low. ' ), lowLine( first ),
				list.length > 1 ? ' Plus ' + ( list.length - 1 ) + ' more.' : '',
				h( 'div', { style: { marginTop: '8px', display: 'flex', gap: '8px', flexWrap: 'wrap' } },
					h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--white', onclick: function () {
						openStockSheet( first.s, { bought: true } );
					} }, 'I bought more' ),
					reorderButton( list ),
					h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--white', onclick: function () {
						ui.supplyView = 'plan';
						go( 'vials' );
					} }, 'See my supply' )
				) ),
			h( 'button', { type: 'button', class: 'ypt-banner__close', 'aria-label': 'Dismiss for today', onclick: function () {
				list.forEach( function ( x ) {
					dismissed[ labelKey( x.s.compound ) ] = today;
				} );
				saveJSON( LOW_KEY, dismissed );
				render();
			} }, '×' ) );
	}

	/* ---------- Vial expiry (Today) ---------- */

	var EXP_KEY = 'ypt-exp-dismissed';

	/** A mixed vial or pen that expires within a few days (or has): mix a new one, or mark it finished. Dismissed per vial for the day. */
	function expiryBanner() {
		var dismissed = loadJSON( EXP_KEY, {} ) || {};
		var today = todayStr();
		var list = vials( false ).map( function ( v ) {
			return { v: v, info: vialInfo( v ) };
		} ).filter( function ( x ) {
			return x.info.expires && x.info.expiresIn <= EXPIRY_WARN_DAYS && dismissed[ x.v.id ] !== today;
		} ).sort( function ( a, b ) {
			return a.info.expiresIn - b.info.expiresIn;
		} );
		if ( ! list.length ) {
			return null;
		}
		var x = list[ 0 ];
		var v = x.v;
		var what = isPen( v ) ? 'pen' : 'vial';
		var n = x.info.expiresIn;
		var when = n < 0 ? 'expired ' + fmtDay( x.info.expires ).replace( /^\w+, /, '' ) : n === 0 ? 'expires today' : n === 1 ? 'expires tomorrow' : 'expires in ' + n + ' days';
		return h( 'div', { class: 'ypt-banner ypt-banner--warn' },
			h( 'div', null, h( 'b', null, 'Your ' + v.compound + ' ' + what + ' ' + when + '. ' ),
				'Mixed ' + fmtDay( v.mixed ).replace( /^\w+, /, '' ) + ', good for ' + vialGoodFor( v ) + ' days.' + ( list.length > 1 ? ' Plus ' + ( list.length - 1 ) + ' more.' : '' ),
				h( 'div', { style: { marginTop: '8px', display: 'flex', gap: '8px', flexWrap: 'wrap' } },
					h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--white', onclick: function () {
						openVialSheet( null, { compound: v.compound, kind: what, preset: { kind: v.kind, mode: v.mode, amount: v.amount, water: v.water, conc: v.conc, volume: v.volume, parts: v.parts, blend: v.blend, syringe: v.syringe, goodFor: v.goodFor }, replaces: v.id } );
					} }, 'Mix a new ' + what ),
					h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--white', onclick: function () {
						put( 'vial', v.id, Object.assign( {}, v, { finished: true } ) );
						toast( ( isPen( v ) ? 'Pen' : 'Vial' ) + ' marked finished', function () {
							put( 'vial', v.id, Object.assign( {}, v, { finished: false } ) );
						} );
					} }, 'Mark finished' )
				) ),
			h( 'button', { type: 'button', class: 'ypt-banner__close', 'aria-label': 'Dismiss for today', onclick: function () {
				list.forEach( function ( y ) {
					dismissed[ y.v.id ] = today;
				} );
				saveJSON( EXP_KEY, dismissed );
				render();
			} }, '×' ) );
	}

	/* ---------- Travel mode (Today) ---------- */

	var EASE_STEP = 120;

	/** The zone the schedule is headed for: the one being eased into, or home. */
	function travelTarget( s ) {
		return s.travel ? s.travel.tz : s.baseTz || s.tz;
	}

	function hoursLabel( min ) {
		var hrs = Math.abs( min ) / 60;
		return fmtNum( hrs, 1 ) + ' hour' + ( hrs === 1 ? '' : 's' );
	}

	/** Asks what to do with reminder times when the phone's time zone no longer matches the schedule's. */
	function travelCard() {
		var s = state.records.settings.me || {};
		var here = deviceTz();
		var now = Date.now();
		if ( ! here || ! protocols().length || here === travelTarget( s ) || here === s.skipTz ) {
			return null;
		}
		var from = effectiveOffset( now );
		var to = tzOffset( here, now );
		if ( to == null || to === from ) {
			return null;
		}
		var diff = to - from;
		var first = protocols().filter( function ( p ) {
			return ! p.paused;
		} ).map( function ( p ) {
			return timesOf( p )[ 0 ];
		} ).sort()[ 0 ] || '09:00';
		var homeName = tzCity( s.travel ? s.travel.tz : s.baseTz );
		var city = tzCity( here );
		var steps = Math.ceil( Math.abs( diff ) / EASE_STEP );
		var choice = Math.abs( diff ) > EASE_STEP ? 'ease' : 'switch';
		var atHere = localTimeOf( first );

		function option( id, title, text ) {
			return h( 'button', { type: 'button', class: 'ypt-opt', 'aria-pressed': choice === id ? 'true' : 'false', onclick: function () {
				choice = id;
				[].forEach.call( this.parentNode.querySelectorAll( '.ypt-opt' ), function ( b ) {
					b.setAttribute( 'aria-pressed', 'false' );
				} );
				this.setAttribute( 'aria-pressed', 'true' );
			} }, h( 'i', { 'aria-hidden': 'true' } ), h( 'span', null, h( 'b', null, title ), text ) );
		}

		var opts = h( 'div', null );
		if ( Math.abs( diff ) > EASE_STEP ) {
			opts.appendChild( option( 'ease', 'Ease into ' + city + ' time', 'Shift ' + EASE_STEP / 60 + ' hours a day. On ' + city + ' time by ' + fmtDay( addDays( todayStr(), steps ) ).replace( /^\w+, /, '' ) + '.' ) );
		}
		opts.appendChild( option( 'switch', 'Switch now', 'Your ' + fmtTime( first ) + ' dose moves to ' + fmtTime( first ) + ' ' + city + ' time, ' + hoursLabel( diff ) + ' ' + ( diff > 0 ? 'earlier' : 'later' ) + ', once.' ) );
		opts.appendChild( option( 'home', 'Keep ' + homeName + ' time', 'Doses stay at ' + fmtTime( atHere ) + ' here. Good for short trips.' ) );

		return h( 'div', { class: 'ypt-card ypt-travel' },
			h( 'div', { class: 'ypt-travel__head' }, h( 'span', { 'aria-hidden': 'true' }, '✈️' ),
				h( 'div', null, h( 'b', null, 'Looks like you’re on ' + city + ' time' ), h( 'span', null, hoursLabel( diff ) + ' ' + ( diff > 0 ? 'ahead of' : 'behind' ) + ' ' + homeName ) ) ),
			h( 'div', { class: 'ypt-stats' },
				h( 'div', { class: 'ypt-stat' }, h( 'b', null, fmtTime( first ) ), h( 'span', null, 'your dose, ' + homeName + ' time' ) ),
				h( 'div', { class: 'ypt-stat' }, h( 'b', null, fmtTime( atHere ) ), h( 'span', null, 'same moment here' ) )
			),
			h( 'div', { class: 'ypt-label', style: { marginTop: '14px' } }, 'How should your reminders work?' ),
			opts,
			h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--primary ypt-btn--block', style: { marginTop: '12px' }, onclick: function () {
				setTravel( choice, here, from, to );
			} }, 'Use this' ),
			h( 'p', { class: 'ypt-muted ypt-small', style: { textAlign: 'center', marginTop: '8px' } }, 'Weekly doses keep their day. We’ll ask again when you’re home.' )
		);
	}

	function setTravel( choice, here, from, to ) {
		var s = Object.assign( {}, state.records.settings.me || {} );
		delete s.skipTz;
		if ( choice === 'switch' ) {
			delete s.travel;
			s.baseTz = here;
			toast( 'Reminders now follow ' + tzCity( here ) + ' time' );
		} else if ( choice === 'ease' ) {
			s.travel = { tz: here, from: from, to: to, at: Math.floor( Date.now() / 1000 ), step: EASE_STEP };
			toast( 'Easing into ' + tzCity( here ) + ' time' );
		} else {
			// Keep the clock they're on now: home, or wherever the ease has got to.
			if ( s.travel ) {
				s.baseTz = effectiveOffset( Date.now() ) === tzOffset( s.baseTz, Date.now() ) ? s.baseTz : s.travel.tz;
				delete s.travel;
			}
			s.skipTz = here;
			toast( 'Keeping ' + tzCity( s.baseTz ) + ' time' );
		}
		put( 'settings', 'me', s );
	}

	/** A line under the date while the schedule isn't on this phone's clock. */
	function travelNote() {
		var s = state.records.settings.me || {};
		var here = deviceTz();
		if ( ! protocols().length || ! clockShift( Date.now() ) || ( here !== s.skipTz && here !== travelTarget( s ) ) ) {
			return null;
		}
		var text = s.travel ? 'Easing into ' + tzCity( s.travel.tz ) + ' time. Times below are today’s.' : 'Following ' + tzCity( s.baseTz ) + ' time while you’re away.';
		// Change: back on the home clock, and the card asks again.
		return h( 'p', { class: 'ypt-travel-note' }, text, ' ', h( 'button', { type: 'button', class: 'ypt-link ypt-small', onclick: function () {
			var next = Object.assign( {}, s );
			delete next.skipTz;
			delete next.travel;
			put( 'settings', 'me', next );
		} }, 'Change' ) );
	}

	/* ---------- What's new (entries come from includes/tracker/whats-new.php) ---------- */

	var NEWS_KEY = 'ypt-news-seen';

	function newsList() {
		return CFG.whatsNew || [];
	}

	/** Entries this browser hasn't seen. Someone brand new to the tracker has nothing to catch up on, so they start caught up. */
	function unseenNews() {
		var list = newsList();
		if ( ! list.length ) {
			return [];
		}
		var seen = loadJSON( NEWS_KEY, null );
		if ( seen === null ) {
			if ( ! protocols().length && ! vials( true ).length ) {
				saveJSON( NEWS_KEY, list[ 0 ].id );
				return [];
			}
			seen = '';
		}
		return list.filter( function ( n ) {
			return String( n.id ) > String( seen );
		} );
	}

	function markNewsSeen() {
		var list = newsList();
		if ( list.length ) {
			saveJSON( NEWS_KEY, list[ 0 ].id );
		}
	}

	function whatsNewBanner() {
		var fresh = unseenNews();
		if ( ! fresh.length ) {
			return null;
		}
		return h( 'div', { class: 'ypt-banner ypt-banner--info' },
			h( 'div', null, h( 'b', null, 'New: ' + fresh[ 0 ].title + '. ' ), fresh.length > 1 ? 'Plus ' + ( fresh.length - 1 ) + ' more update' + ( fresh.length > 2 ? 's' : '' ) + '. ' : '',
				h( 'div', { style: { marginTop: '8px' } }, h( 'button', { type: 'button', class: 'ypt-btn', onclick: function () {
					markNewsSeen();
					render();
					openWhatsNew();
				} }, 'See what’s new' ) ) ),
			h( 'button', { type: 'button', class: 'ypt-banner__close', 'aria-label': 'Dismiss', onclick: function () {
				markNewsSeen();
				render();
			} }, '×' ) );
	}

	function newsItem( n ) {
		return h( 'div', { class: 'ypt-news' },
			h( 'div', { class: 'ypt-eyebrow' }, fmtDay( n.date ).replace( /^\w+, /, '' ) ),
			h( 'b', null, n.title ),
			h( 'p', { class: 'ypt-small' }, n.text ) );
	}

	function openWhatsNew() {
		markNewsSeen();
		openSheet( 'What’s new', 'YeffoHealth', [ h( 'div', { class: 'ypt-card ypt-list' }, newsList().map( newsItem ) ) ] );
	}

	function installBanner() {
		var dismissed = loadJSON( 'ypt-install-dismissed', 0 );
		if ( isStandalone() || ( dismissed && Date.now() - dismissed < 14 * 86400000 ) ) {
			return null;
		}
		if ( ! installPrompt && ! isIOS() ) {
			return null;
		}
		var close = h( 'button', { type: 'button', class: 'ypt-banner__close', 'aria-label': 'Dismiss', onclick: function () {
			saveJSON( 'ypt-install-dismissed', Date.now() );
			render();
		} }, '×' );
		if ( installPrompt ) {
			return h( 'div', { class: 'ypt-banner ypt-banner--info' },
				h( 'div', null, h( 'b', null, 'Add YeffoHealth to your home screen' ), ' so it opens like an app.',
					h( 'div', { style: { marginTop: '8px' } }, h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--primary', onclick: promptInstall }, 'Install app' ) ) ),
				close );
		}
		return h( 'div', { class: 'ypt-banner ypt-banner--info' },
			h( 'div', null, h( 'b', null, 'Add to your Home Screen' ), ' to use YeffoHealth like an app and get reminders: tap the Share button, then “Add to Home Screen”.' ),
			close );
	}

	function promptInstall() {
		if ( ! installPrompt ) {
			return;
		}
		installPrompt.prompt();
		installPrompt.userChoice.finally( function () {
			installPrompt = null;
			render();
		} );
	}

	/* ---------- History ---------- */

	function renderHistory() {
		var wrap = h( 'div', null );
		var month = ui.month;
		var first = parseDate( month + '-01' );
		var filter = ui.historyFilter;
		var list = protocols();

		var select = h( 'select', { class: 'ypt-pill', 'aria-label': 'Filter by medication', onchange: function ( e ) {
			ui.historyFilter = e.target.value;
			render();
		} }, h( 'option', { value: '' }, 'All' ), list.map( function ( p ) {
			return h( 'option', { value: p.id, selected: p.id === filter }, p.compound );
		} ) );

		// Opened from Today's calendar button; weight and photos live on the Progress tab.
		wrap.appendChild( h( 'button', { type: 'button', class: 'ypt-back', onclick: function () {
			go( 'today' );
		} }, '‹ Today' ) );
		wrap.appendChild( h( 'header', { class: 'ypt-top' },
			h( 'div', null, h( 'div', { class: 'ypt-eyebrow' }, MONTHS[ first.getMonth() ] + ' ' + first.getFullYear() ), h( 'h1', null, 'History' ) ),
			list.length > 1 ? select : null
		) );

		var cal = h( 'div', { class: 'ypt-cal' } );
		DOW.forEach( function ( d ) {
			cal.appendChild( h( 'div', { class: 'ypt-cal__h' }, d ) );
		} );
		for ( var b = 0; b < first.getDay(); b++ ) {
			cal.appendChild( h( 'div', null ) );
		}
		var days = new Date( first.getFullYear(), first.getMonth() + 1, 0 ).getDate();
		for ( var i = 1; i <= days; i++ ) {
			( function ( date ) {
				var st = dayStatus( date, filter );
				cal.appendChild( h( 'button', {
					type: 'button',
					class: 'ypt-cal__d ypt-cal__d--' + st + ( date === ui.historyDay ? ' ypt-cal__d--sel' : '' ),
					'aria-label': fmtDay( date ) + ', ' + { full: 'all taken', part: 'some taken', miss: 'missed', none: 'nothing scheduled', future: 'upcoming' }[ st ],
					'aria-pressed': date === ui.historyDay ? 'true' : 'false',
					onclick: function () {
						ui.historyDay = date;
						render();
					},
				}, String( parseDate( date ).getDate() ) ) );
			}( month + '-' + pad( i ) ) );
		}

		var prevMonth = dateStr( new Date( first.getFullYear(), first.getMonth() - 1, 1 ) ).slice( 0, 7 );
		var nextMonth = dateStr( new Date( first.getFullYear(), first.getMonth() + 1, 1 ) ).slice( 0, 7 );

		wrap.appendChild( h( 'div', { class: 'ypt-card' },
			h( 'div', { class: 'ypt-cal-nav' },
				h( 'button', { type: 'button', class: 'ypt-pill', onclick: function () {
					ui.month = prevMonth;
					render();
				} }, '‹ ' + MONTHS[ parseDate( prevMonth + '-01' ).getMonth() ].slice( 0, 3 ) ),
				h( 'b', null, MONTHS[ first.getMonth() ] ),
				h( 'button', { type: 'button', class: 'ypt-pill', disabled: nextMonth > todayStr().slice( 0, 7 ), onclick: function () {
					ui.month = nextMonth;
					render();
				} }, MONTHS[ parseDate( nextMonth + '-01' ).getMonth() ].slice( 0, 3 ) + ' ›' )
			),
			cal,
			h( 'div', { class: 'ypt-legend' },
				h( 'span', null, h( 'i', { style: { background: 'var(--ypt-cyan)' } } ), 'All taken' ),
				h( 'span', null, h( 'i', { style: { background: 'var(--ypt-info-line)' } } ), 'Some' ),
				h( 'span', null, h( 'i', { style: { background: 'var(--ypt-pink-soft)' } } ), 'Missed' )
			)
		) );

		var adh = adherence( 30 );
		var totalTaken = values( state.records.dose ).filter( function ( d ) {
			return d.status === 'taken';
		} ).length;
		wrap.appendChild( h( 'div', { class: 'ypt-card ypt-summary' },
			h( 'div', null, h( 'b', null, adh == null ? '–' : adh + '%' ), h( 'span', null, 'on schedule' ) ),
			h( 'div', null, h( 'b', null, String( streak() ) ), h( 'span', null, 'day streak' ) ),
			h( 'div', null, h( 'b', null, String( totalTaken ) ), h( 'span', null, 'doses logged' ) )
		) );

		var feelCard = historyFeelings();
		if ( feelCard ) {
			wrap.appendChild( h( 'div', { class: 'ypt-eyebrow ypt-section-label' }, 'Side effects & notes' ) );
			wrap.appendChild( feelCard );
		}

		var sitesCard = historySites();
		if ( sitesCard ) {
			wrap.appendChild( h( 'div', { class: 'ypt-eyebrow ypt-section-label' }, 'Injection sites' ) );
			wrap.appendChild( sitesCard );
		}

		// Selected day's detail.
		var day = ui.historyDay;
		var rows = [];
		slotsOn( day ).forEach( function ( s ) {
			if ( filter && s.protocol.id !== filter ) {
				return;
			}
			rows.push( { name: s.protocol.compound + ' · ' + amountLabel( s.log && +s.log.dose > 0 ? s.log.dose : doseOn( s.protocol, day ), s.protocol.unit, s.log && s.log.strength ? s.log : s.protocol ), log: s.log, time: s.time, slot: s } );
		} );
		extrasOn( day ).forEach( function ( d ) {
			if ( filter && d.protocolId !== filter ) {
				return;
			}
			rows.push( { name: d.compound + ' · ' + amountLabel( d.dose, d.unit, d ) + ' (extra)', log: d, time: d.time } );
		} );
		rows.sort( function ( a, b ) {
			return String( a.time ).localeCompare( String( b.time ) );
		} );

		wrap.appendChild( h( 'div', { class: 'ypt-eyebrow ypt-section-label' }, fmtDay( day ) ) );
		if ( ! rows.length ) {
			wrap.appendChild( h( 'div', { class: 'ypt-card ypt-muted' }, 'Nothing scheduled or logged this day.' ) );
		} else {
			wrap.appendChild( h( 'div', { class: 'ypt-card' }, rows.map( function ( r ) {
				var right = r.log
					? ( r.log.status === 'taken' ? fmtIsoTime( r.log.at ) + ' ✓' : 'Skipped' )
					: ( day > todayStr() || ( day === todayStr() && r.time > nowTime() ) ? fmtTime( r.time ) : 'Missed' );
				return h( 'button', { type: 'button', class: 'ypt-log', style: { width: '100%', background: 'none', border: '0', borderTop: '1px solid var(--ypt-soft)', textAlign: 'left' }, onclick: function () {
					if ( r.log ) {
						openLogSheet( Object.assign( { id: r.slot ? r.slot.id : r.log.id }, r.log ) );
					} else if ( r.slot && day <= todayStr() ) {
						ui.day = day;
						go( 'today' );
					}
				} },
					h( 'div', null, h( 'div', null, r.name ),
						r.log && r.log.status === 'taken' && r.log.site ? h( 'div', { class: 'ypt-log__site' }, siteLabel( r.log.site ) ) : null,
						r.log && tagsOf( r.log ).length ? h( 'div', { class: 'ypt-feel__tags', style: { marginTop: '4px' } }, tagsOf( r.log ).map( tagChip ) ) : null,
						r.log && r.log.note ? h( 'div', { class: 'ypt-log__note' }, '“' + r.log.note + '”' ) : null ),
					h( 'span', { class: 'ypt-log__right', style: ! r.log && right === 'Missed' ? { color: 'var(--ypt-magenta-deep)' } : null }, right )
				);
			} ) ) );
		}

		wrap.appendChild( h( 'div', { class: 'ypt-card ypt-note ypt-report-cta' }, icon( 'doc' ),
			h( 'div', null,
				h( 'div', { class: 'ypt-note__title' }, 'Report for your doctor or coach' ),
				'A clean PDF of your doses, side effects and progress.',
				h( 'div', { style: { display: 'flex', gap: '8px', marginTop: '10px', flexWrap: 'wrap' } },
					h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--primary', onclick: openReportSheet }, 'Create PDF report' ),
					h( 'button', { type: 'button', class: 'ypt-btn', onclick: exportCsv }, 'Export CSV' )
				)
			) ) );

		return wrap;
	}

	/**
	 * How the customer felt, tallied over a range: each tag with how often
	 * it came up, the medication it came up with most, and how many of
	 * them fell in the first week of a titration step (a new, higher dose).
	 */
	function feelingStats( from, filterId ) {
		var byTag = {};
		var tagged = 0;
		var taken = 0;
		values( state.records.dose ).forEach( function ( d ) {
			if ( d.status !== 'taken' || ( from && d.date < from ) || ( filterId && d.protocolId !== filterId ) ) {
				return;
			}
			taken++;
			var tags = tagsOf( d );
			if ( ! tags.length ) {
				return;
			}
			tagged++;
			var p = d.protocolId ? state.records.protocol[ d.protocolId ] : null;
			var step = p ? stepInfo( p, d.date ) : null;
			var newDose = !! step && step.n > 1 && daysBetween( step.since, d.date ) < 7;
			tags.forEach( function ( t ) {
				var key = String( t ).toLowerCase();
				var row = byTag[ key ] || ( byTag[ key ] = { tag: t, n: 0, by: {}, newDose: 0, last: '' } );
				row.n++;
				row.by[ d.compound ] = ( row.by[ d.compound ] || 0 ) + 1;
				row.newDose += newDose ? 1 : 0;
				row.last = d.date > row.last ? d.date : row.last;
			} );
		} );
		var rows = Object.keys( byTag ).map( function ( k ) {
			var r = byTag[ k ];
			r.top = Object.keys( r.by ).sort( function ( a, b ) {
				return r.by[ b ] - r.by[ a ];
			} )[ 0 ] || '';
			return r;
		} ).sort( function ( a, b ) {
			return b.n - a.n || String( a.tag ).localeCompare( String( b.tag ) );
		} );
		return { rows: rows, tagged: tagged, taken: taken };
	}

	var FEEL_RANGES = [ [ 30, '30 days' ], [ 90, '90 days' ], [ 0, 'All' ] ];

	function historyFeelings() {
		var range = ui.feelRange == null ? 30 : ui.feelRange;
		var from = range ? addDays( todayStr(), -( range - 1 ) ) : '';
		var st = feelingStats( from, ui.historyFilter );
		var ever = values( state.records.dose ).some( function ( d ) {
			return tagsOf( d ).length || d.note;
		} );
		if ( ! ever ) {
			return st.taken ? h( 'div', { class: 'ypt-card ypt-muted ypt-small' }, 'Tap a taken dose to note how you felt (nausea, sleep, energy…). Patterns show up here.' ) : null;
		}
		var card = h( 'div', { class: 'ypt-card' } );
		card.appendChild( h( 'div', { class: 'ypt-chips', style: { marginBottom: '10px' } }, FEEL_RANGES.map( function ( r ) {
			return h( 'button', { type: 'button', class: 'ypt-chip ypt-chip--sm', 'aria-pressed': r[ 0 ] === range ? 'true' : 'false', onclick: function () {
				ui.feelRange = r[ 0 ];
				render();
			} }, r[ 1 ] );
		} ) ) );
		if ( ! st.rows.length ) {
			card.appendChild( h( 'p', { class: 'ypt-muted ypt-small', style: { margin: '0' } }, 'Nothing tagged in this time.' ) );
			return card;
		}
		var max = st.rows[ 0 ].n;
		st.rows.slice( 0, 8 ).forEach( function ( r ) {
			var sub = [ r.top ? ( r.by[ r.top ] === r.n ? 'all with ' + r.top : r.by[ r.top ] + ' with ' + r.top ) : '' ];
			if ( r.newDose >= 2 || ( r.newDose && r.newDose === r.n ) ) {
				sub.push( r.newDose === r.n ? 'all in a week your dose went up' : r.newDose + ' in a week your dose went up' );
			}
			card.appendChild( h( 'div', { class: 'ypt-feelrow' },
				h( 'div', { class: 'ypt-feelrow__head' }, tagChip( r.tag ), h( 'b', null, r.n + '×' ) ),
				h( 'div', { class: 'ypt-feelrow__bar' }, h( 'i', { class: 'ypt-feelrow__fill--' + tagTone( r.tag ), style: { width: Math.max( 6, ( r.n / max ) * 100 ) + '%' } } ) ),
				h( 'div', { class: 'ypt-muted ypt-small' }, sub.filter( Boolean ).join( ' · ' ) )
			) );
		} );
		card.appendChild( h( 'p', { class: 'ypt-muted ypt-small', style: { margin: '10px 0 0' } }, 'Noted on ' + st.tagged + ' of ' + st.taken + ' doses taken' + ( range ? ' in the last ' + range + ' days.' : '.' ) ) );
		return card;
	}

	/* =========================================================
	 * Progress: weight, measurements and photos (History › Progress),
	 * charted against the doses taken. Photos are their own encrypted
	 * records, fetched one at a time (/tracker/photos/{id}) and only
	 * kept in memory, never in the offline copy.
	 * ======================================================= */

	var MEASURES = [ [ 'waist', 'Waist' ], [ 'chest', 'Chest' ], [ 'hips', 'Hips' ], [ 'arm', 'Arm' ], [ 'thigh', 'Thigh' ], [ 'neck', 'Neck' ] ];
	var PROGRESS_RANGES = [ [ 30, '1M' ], [ 90, '3M' ], [ 180, '6M' ], [ 365, '1Y' ], [ 0, 'All' ] ];
	var MAX_PROGRESS_PHOTOS = 3;
	var photoCache = {};

	/** 'us' (lb, in) or 'metric' (kg, cm): the Me setting, else a guess from the browser's language. */
	function unitsPref() {
		var s = state.records.settings.me || {};
		if ( s.units === 'us' || s.units === 'metric' ) {
			return s.units;
		}
		return /^en-(US|LR)|^my/i.test( navigator.language || 'en-US' ) ? 'us' : 'metric';
	}

	function weightUnit() {
		return unitsPref() === 'metric' ? 'kg' : 'lb';
	}

	function lengthUnit() {
		return unitsPref() === 'metric' ? 'cm' : 'in';
	}

	function convert( v, from, to ) {
		if ( from === to ) {
			return v;
		}
		var factor = { lb: 1 / 2.20462, kg: 1, in: 2.54, cm: 1 };
		return ( v * ( factor[ from ] || 1 ) ) / ( factor[ to ] || 1 );
	}

	function progressEntries() {
		return values( state.records.progress ).filter( function ( e ) {
			return /^\d{4}-\d{2}-\d{2}$/.test( e.date || '' );
		} ).sort( function ( a, b ) {
			return a.date.localeCompare( b.date ) || String( a.id ).localeCompare( String( b.id ) );
		} );
	}

	function metricName( key ) {
		if ( key === 'weight' ) {
			return 'Weight';
		}
		if ( key === 'fat' ) {
			return 'Body fat';
		}
		var m = MEASURES.filter( function ( x ) {
			return x[ 0 ] === key;
		} )[ 0 ];
		return m ? m[ 1 ] : key;
	}

	function metricUnit( key ) {
		return key === 'weight' ? weightUnit() : key === 'fat' ? '%' : lengthUnit();
	}

	/** An entry's value for a metric in the customer's units, or null. */
	function metricValue( e, key ) {
		if ( key === 'weight' ) {
			return +e.weight > 0 ? convert( +e.weight, e.wUnit || 'lb', weightUnit() ) : null;
		}
		if ( key === 'fat' ) {
			return +e.fat > 0 ? +e.fat : null;
		}
		var v = e.m && +e.m[ key ];
		return v > 0 ? convert( v, e.mUnit || 'in', lengthUnit() ) : null;
	}

	function fmtMetric( v, key ) {
		return fmtNum( v, 1 ) + ( key === 'fat' ? '%' : ' ' + metricUnit( key ) );
	}

	function availableMetrics( entries ) {
		return [ 'weight' ].concat( MEASURES.map( function ( m ) {
			return m[ 0 ];
		} ) ).concat( [ 'fat' ] ).filter( function ( k ) {
			return entries.some( function ( e ) {
				return metricValue( e, k ) != null;
			} );
		} );
	}

	/* ---------- Photos ---------- */

	/** A fresh REST nonce when the page's has expired (same as api()'s retry). */
	function refreshNonce() {
		return fetch( CFG.appUrl + 'session', { credentials: 'same-origin', cache: 'no-store' } ).then( function ( r ) {
			return r.json();
		} ).then( function ( n ) {
			if ( ! n.signedIn ) {
				throw new Error( 'Signed out' );
			}
			CFG.nonce = n.nonce;
		} );
	}

	function fetchPhoto( id, retried ) {
		return fetch( CFG.restUrl + 'tracker/photos/' + encodeURIComponent( id ), { credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': CFG.nonce } } ).then( function ( res ) {
			if ( res.ok ) {
				return res.blob().then( function ( b ) {
					return URL.createObjectURL( b );
				} );
			}
			if ( res.status === 403 && ! retried ) {
				return refreshNonce().then( function () {
					return fetchPhoto( id, true );
				} );
			}
			throw new Error( 'That photo couldn’t be loaded.' );
		} );
	}

	/** The photo as a blob: URL (kept for the session). */
	function photoUrl( id ) {
		if ( ! photoCache[ id ] ) {
			photoCache[ id ] = fetchPhoto( id ).catch( function ( e ) {
				delete photoCache[ id ];
				throw e;
			} );
		}
		return photoCache[ id ];
	}

	/** An <img> that fills in once its photo has loaded (offline: a placeholder). */
	function photoImg( id, alt ) {
		var img = h( 'img', { class: 'ypt-photo is-loading', alt: alt || 'Progress photo' } );
		photoUrl( id ).then( function ( url ) {
			img.src = url;
			img.classList.remove( 'is-loading' );
		} ).catch( function () {
			img.classList.remove( 'is-loading' );
			img.classList.add( 'is-missing' );
		} );
		return img;
	}

	function forgetPhotos() {
		Object.keys( photoCache ).forEach( function ( id ) {
			photoCache[ id ].then( function ( url ) {
				URL.revokeObjectURL( url );
			} ).catch( function () {} );
		} );
		photoCache = {};
	}

	/** Every photo, oldest first: [{ id, date, entry }]. */
	function allPhotos() {
		var out = [];
		progressEntries().forEach( function ( e ) {
			( e.photos || [] ).forEach( function ( id ) {
				out.push( { id: id, date: e.date, entry: e } );
			} );
		} );
		return out;
	}

	/** Full-size photos, swipe through with ‹ ›. */
	function openPhotoViewer( list, index ) {
		var i = Math.max( 0, Math.min( index, list.length - 1 ) );
		var box = h( 'div', { class: 'ypt-viewer' } );
		function draw() {
			box.textContent = '';
			var x = list[ i ];
			var w = metricValue( x.entry, 'weight' );
			box.appendChild( h( 'div', { class: 'ypt-viewer__img' }, photoImg( x.id, 'Progress photo, ' + fmtDay( x.date, true ) ) ) );
			box.appendChild( h( 'div', { class: 'ypt-viewer__bar' },
				h( 'button', { type: 'button', class: 'ypt-pill', disabled: i === 0, 'aria-label': 'Earlier photo', onclick: function () {
					i--;
					draw();
				} }, '‹' ),
				h( 'div', null, h( 'b', null, fmtDay( x.date, true ) ), h( 'span', { class: 'ypt-muted ypt-small' }, ( w != null ? fmtMetric( w, 'weight' ) + ' · ' : '' ) + ( i + 1 ) + ' of ' + list.length ) ),
				h( 'button', { type: 'button', class: 'ypt-pill', disabled: i === list.length - 1, 'aria-label': 'Later photo', onclick: function () {
					i++;
					draw();
				} }, '›' )
			) );
		}
		draw();
		openSheet( 'Progress photos', 'Only you can see these', [ box ] );
	}

	/* ---------- The Progress view ---------- */

	function renderProgress() {
		var wrap = h( 'div', null );
		var entries = progressEntries();
		var logBtn = h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--primary', onclick: function () {
			openProgressSheet( null );
		} }, '+ Log progress' );
		if ( ! entries.length ) {
			wrap.appendChild( h( 'div', { class: 'ypt-card ypt-empty' },
				h( 'h2', null, 'Track your progress' ),
				h( 'p', null, 'Log your weight, measurements and progress photos. We chart them against your doses so you can see what’s working.' ),
				logBtn,
				h( 'p', { class: 'ypt-muted ypt-small', style: { marginTop: '12px', marginBottom: '0' } }, 'Photos are encrypted like everything else here. Only you can see them.' )
			) );
			return wrap;
		}

		var metrics = availableMetrics( entries );
		var key = metrics.indexOf( ui.progMetric ) !== -1 ? ui.progMetric : metrics[ 0 ] || 'weight';
		var range = ui.progRange == null ? 90 : ui.progRange;
		var today = todayStr();
		var from = range ? addDays( today, -( range - 1 ) ) : entries[ 0 ].date;
		var inRange = entries.filter( function ( e ) {
			return e.date >= from && metricValue( e, key ) != null;
		} );
		var withValue = entries.filter( function ( e ) {
			return metricValue( e, key ) != null;
		} );

		// Headline: the latest value and the change over the range shown.
		var head = h( 'div', { class: 'ypt-card ypt-prog-head' } );
		var latest = withValue[ withValue.length - 1 ];
		if ( latest ) {
			var lv = metricValue( latest, key );
			var base = inRange.length > 1 ? inRange[ 0 ] : null;
			var change = base ? lv - metricValue( base, key ) : null;
			head.appendChild( h( 'div', null,
				h( 'div', { class: 'ypt-eyebrow' }, metricName( key ) + ' · ' + fmtDay( latest.date ).replace( /^\w+, /, '' ) ),
				h( 'div', { class: 'ypt-prog-head__big' }, fmtMetric( lv, key ) ),
				change != null ? h( 'div', { class: 'ypt-prog-head__delta' + ( Math.abs( change ) < 0.05 ? '' : change < 0 ? ' is-down' : ' is-up' ) },
					( change > 0 ? '+' : change < 0 ? '−' : '±' ) + fmtMetric( Math.abs( change ), key ) + ' since ' + fmtDay( base.date ).replace( /^\w+, /, '' ) ) : h( 'div', { class: 'ypt-muted ypt-small' }, 'Log again to see your change.' )
			) );
		} else {
			head.appendChild( h( 'div', null, h( 'div', { class: 'ypt-eyebrow' }, 'Progress' ), h( 'div', { class: 'ypt-muted' }, 'No ' + metricName( key ).toLowerCase() + ' logged yet.' ) ) );
		}
		head.appendChild( logBtn );
		wrap.appendChild( head );

		if ( metrics.length > 1 ) {
			wrap.appendChild( h( 'div', { class: 'ypt-chips', style: { margin: '2px 0 10px' } }, metrics.map( function ( k ) {
				return h( 'button', { type: 'button', class: 'ypt-chip ypt-chip--sm', 'aria-pressed': k === key ? 'true' : 'false', onclick: function () {
					ui.progMetric = k;
					render();
				} }, metricName( k ) );
			} ) ) );
		}

		var chartCard = h( 'div', { class: 'ypt-card' } );
		chartCard.appendChild( h( 'div', { class: 'ypt-prog-ranges' }, PROGRESS_RANGES.map( function ( r ) {
			return h( 'button', { type: 'button', class: 'ypt-chip ypt-chip--sm', 'aria-pressed': r[ 0 ] === range ? 'true' : 'false', onclick: function () {
				ui.progRange = r[ 0 ];
				render();
			} }, r[ 1 ] );
		} ) ) );
		chartCard.appendChild( progressChart( entries, key, from, today ) );
		wrap.appendChild( chartCard );

		// Then & now: the first and latest photos side by side.
		var photos = allPhotos();
		if ( photos.length ) {
			wrap.appendChild( h( 'div', { class: 'ypt-eyebrow ypt-section-label' }, 'Photos' ) );
			var first = photos[ 0 ];
			var last = photos[ photos.length - 1 ];
			var pair = first.id === last.id ? [ first ] : [ first, last ];
			wrap.appendChild( h( 'div', { class: 'ypt-card' },
				h( 'div', { class: 'ypt-thennow' }, pair.map( function ( x, n ) {
					var w = metricValue( x.entry, 'weight' );
					return h( 'button', { type: 'button', class: 'ypt-thennow__item', onclick: function () {
						openPhotoViewer( photos, n ? photos.length - 1 : 0 );
					} }, photoImg( x.id ), h( 'span', null, h( 'b', null, pair.length > 1 ? ( n ? 'Now' : 'Then' ) : 'First photo' ), ' · ' + fmtDay( x.date ).replace( /^\w+, /, '' ) + ( w != null ? ' · ' + fmtMetric( w, 'weight' ) : '' ) ) );
				} ) ),
				photos.length > 2 ? h( 'button', { type: 'button', class: 'ypt-link', style: { marginTop: '10px' }, onclick: function () {
					openPhotoViewer( photos, photos.length - 1 );
				} }, 'All ' + photos.length + ' photos ›' ) : null
			) );
		}

		wrap.appendChild( h( 'div', { class: 'ypt-eyebrow ypt-section-label' }, 'Entries' ) );
		var shown = entries.slice().reverse().slice( 0, ui.progAll ? 500 : 12 );
		wrap.appendChild( h( 'div', { class: 'ypt-card ypt-list' }, shown.map( function ( e ) {
			var idx = entries.indexOf( e );
			var w = metricValue( e, 'weight' );
			var prevW = null;
			for ( var j = idx - 1; j >= 0 && prevW == null; j-- ) {
				prevW = metricValue( entries[ j ], 'weight' );
			}
			var bits = [];
			MEASURES.forEach( function ( m ) {
				var v = metricValue( e, m[ 0 ] );
				if ( v != null ) {
					bits.push( m[ 1 ] + ' ' + fmtNum( v, 1 ) );
				}
			} );
			if ( metricValue( e, 'fat' ) != null ) {
				bits.push( fmtNum( +e.fat, 1 ) + '% fat' );
			}
			var nPhotos = ( e.photos || [] ).length;
			return h( 'button', { type: 'button', class: 'ypt-list-row ypt-prog-row', onclick: function () {
				openProgressSheet( e );
			} },
				h( 'span', null, h( 'b', null, fmtDay( e.date ).replace( /^\w+, /, '' ) ), bits.length || e.note ? h( 'span', { class: 'ypt-muted ypt-small ypt-prog-row__sub' }, bits.join( ' · ' ) + ( e.note ? ( bits.length ? ' · ' : '' ) + '“' + e.note + '”' : '' ) ) : null ),
				h( 'span', { class: 'ypt-prog-row__right' },
					nPhotos ? h( 'span', { class: 'ypt-prog-row__cam', 'aria-label': nPhotos + ' photo' + ( nPhotos === 1 ? '' : 's' ) }, icon( 'camera' ), String( nPhotos ) ) : null,
					w != null ? h( 'b', null, fmtMetric( w, 'weight' ) ) : null,
					w != null && prevW != null && Math.abs( w - prevW ) >= 0.05 ? h( 'span', { class: 'ypt-small ' + ( w < prevW ? 'is-down' : 'is-up' ) }, ( w < prevW ? '−' : '+' ) + fmtNum( Math.abs( w - prevW ), 1 ) ) : null
				)
			);
		} ),
			entries.length > shown.length ? h( 'button', { type: 'button', class: 'ypt-list-row', onclick: function () {
				ui.progAll = true;
				render();
			} }, h( 'span', null, 'Show all ' + entries.length ), h( 'span', null, '›' ) ) : null
		) );

		wrap.appendChild( h( 'div', { class: 'ypt-row', style: { alignItems: 'center', marginTop: '14px' } },
			h( 'span', { class: 'ypt-muted ypt-small ypt-shrink' }, 'Units' ),
			seg( [ [ 'us', 'lb · in' ], [ 'metric', 'kg · cm' ] ], unitsPref(), function ( u ) {
				put( 'settings', 'me', Object.assign( {}, state.records.settings.me || {}, { units: u } ) );
			} )
		) );
		return wrap;
	}

	/**
	 * The progress chart: the metric as a line over the range, and under
	 * it a lane per medication with a tick for every dose taken, the dose
	 * written in where it changed. Titration steps draw a dashed line up
	 * through the chart, so a dose change and what followed line up.
	 */
	/** `opts.pts` charts [{ date, v }] instead of a progress metric (lab results), with `opts.name` and an optional `opts.band` { low, high } normal range. */
	function progressChart( entries, key, from, to, opts ) {
		opts = opts || {};
		var pts = opts.pts ? opts.pts.filter( function ( x ) {
			return x.date >= from && x.date <= to;
		} ) : entries.filter( function ( e ) {
			return e.date >= from && e.date <= to && metricValue( e, key ) != null;
		} ).map( function ( e ) {
			return { date: e.date, v: metricValue( e, key ) };
		} );
		var band = opts.band || null;
		var span = Math.max( 1, daysBetween( from, to ) );
		var W = 340;
		var L = 34;
		var R = 10;
		var TOP = 12;
		var CH = 130;
		var plotW = W - L - R;
		function xOf( date ) {
			return L + ( daysBetween( from, date ) / span ) * plotW;
		}

		// Dose lanes: the medications taken in this range, most-taken first (up to 4).
		var byProto = {};
		values( state.records.dose ).forEach( function ( d ) {
			if ( d.status !== 'taken' || d.date < from || d.date > to ) {
				return;
			}
			var k = d.protocolId || 'x:' + String( d.compound ).toLowerCase();
			( byProto[ k ] = byProto[ k ] || { name: d.compound, p: state.records.protocol[ d.protocolId ] || null, doses: [] } ).doses.push( d );
		} );
		var lanes = Object.keys( byProto ).map( function ( k ) {
			return byProto[ k ];
		} ).sort( function ( a, b ) {
			return b.doses.length - a.doses.length;
		} ).slice( 0, 4 );
		var LANE = 22;
		var lanesTop = TOP + CH + 26;
		var H = lanesTop + lanes.length * LANE + ( lanes.length ? 4 : 0 );

		var s = svg( 'svg', { class: 'ypt-chart', viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': ( opts.name || metricName( key ) ) + ' from ' + fmtDay( from ) + ' to ' + fmtDay( to ) + ( pts.length ? ', ' + ( opts.fmt || function ( v ) {
			return fmtMetric( v, key );
		} )( pts[ 0 ].v ) + ' to ' + ( opts.fmt || function ( v ) {
			return fmtMetric( v, key );
		} )( pts[ pts.length - 1 ].v ) : '' ) } );

		// Y scale with a little headroom; three gridlines.
		var vals = pts.map( function ( x ) {
			return x.v;
		} );
		// The normal range counts too, so a result just outside it shows how far out it is.
		if ( band ) {
			vals = vals.concat( [ band.low, band.high ].filter( function ( v ) {
				return v != null;
			} ) );
		}
		var lo = vals.length ? Math.min.apply( null, vals ) : 0;
		var hi = vals.length ? Math.max.apply( null, vals ) : 1;
		var pad = opts.pts ? Math.max( ( hi - lo ) * 0.15, Math.abs( hi ) * 0.05, 0.05 ) : Math.max( ( hi - lo ) * 0.15, key === 'weight' ? 1 : 0.5 );
		lo -= pad;
		hi += pad;
		function yOf( v ) {
			return TOP + CH - ( ( v - lo ) / ( hi - lo ) ) * CH;
		}
		if ( band ) {
			var bTop = yOf( band.high != null ? band.high : hi );
			svg( 'rect', { x: L, y: bTop, width: plotW, height: Math.max( 1, yOf( band.low != null ? band.low : lo ) - bTop ), class: 'g-band' }, s );
		}
		for ( var g = 0; g < 3; g++ ) {
			var gv = lo + ( ( hi - lo ) * ( g + 0.5 ) ) / 3;
			svg( 'line', { x1: L, x2: W - R, y1: yOf( gv ), y2: yOf( gv ), class: 'g-grid' }, s );
			svg( 'text', { x: L - 6, y: yOf( gv ) + 3, class: 'g-axis g-axis--y' }, s ).textContent = fmtNum( gv, hi - lo < 1 ? 2 : hi - lo < 10 ? 1 : 0 );
		}
		// X labels: start, middle, end.
		[ from, addDays( from, Math.round( span / 2 ) ), to ].forEach( function ( d, i ) {
			svg( 'text', { x: xOf( d ), y: TOP + CH + 14, class: 'g-axis g-axis--x' + ( i === 0 ? ' is-start' : i === 2 ? ' is-end' : '' ) }, s ).textContent = MONTHS[ parseDate( d ).getMonth() ].slice( 0, 3 ) + ' ' + parseDate( d ).getDate();
		} );

		// Titration steps that start inside the range.
		lanes.forEach( function ( ln ) {
			if ( ! ln.p ) {
				return;
			}
			stepsOf( ln.p ).forEach( function ( st ) {
				var d = addDays( ln.p.start, st.week * 7 );
				if ( d < from || d > to ) {
					return;
				}
				var x = xOf( d );
				svg( 'line', { x1: x, x2: x, y1: TOP, y2: TOP + CH, class: 'g-step', stroke: protocolColor( ln.p ) }, s );
				svg( 'text', { x: x + 3, y: TOP + 9, class: 'g-step-label', fill: protocolColor( ln.p ) }, s ).textContent = '↑ ' + fmtNum( st.dose, 3 );
			} );
		} );

		if ( pts.length ) {
			var dPath = pts.map( function ( x, i ) {
				return ( i ? 'L' : 'M' ) + xOf( x.date ).toFixed( 1 ) + ' ' + yOf( x.v ).toFixed( 1 );
			} ).join( ' ' );
			svg( 'path', { d: dPath, class: 'g-line' }, s );
			pts.forEach( function ( x, i ) {
				svg( 'circle', { cx: xOf( x.date ), cy: yOf( x.v ), r: i === pts.length - 1 ? 4.5 : 3, class: 'g-dot' + ( i === pts.length - 1 ? ' is-last' : '' ) }, s );
			} );
			var lp = pts[ pts.length - 1 ];
			var lx = xOf( lp.date );
			svg( 'text', { x: Math.min( lx, W - R ), y: yOf( lp.v ) - 9, class: 'g-last' + ( lx > W - 60 ? ' is-end' : '' ) }, s ).textContent = fmtNum( lp.v, opts.pts ? 2 : 1 );
		} else {
			svg( 'text', { x: L + plotW / 2, y: TOP + CH / 2, class: 'g-empty' }, s ).textContent = 'Nothing logged in this range';
		}

		lanes.forEach( function ( ln, i ) {
			var y = lanesTop + i * LANE;
			var color = ln.p ? protocolColor( ln.p ) : colorForCompound( ln.name );
			svg( 'line', { x1: L, x2: W - R, y1: y + 11, y2: y + 11, class: 'g-lane' }, s );
			var prevDose = null;
			var lastLabel = -99;
			ln.doses.sort( function ( a, b ) {
				return a.date.localeCompare( b.date );
			} ).forEach( function ( d ) {
				var x = xOf( d.date );
				svg( 'rect', { x: x - 1, y: y + 5, width: 2, height: 12, rx: 1, fill: color }, s );
				if ( +d.dose !== prevDose && x - lastLabel > 30 ) {
					svg( 'text', { x: x + 3, y: y + 4, class: 'g-dose' }, s ).textContent = fmtNum( +d.dose, 3 );
					lastLabel = x;
				}
				prevDose = +d.dose;
			} );
			var label = svg( 'text', { x: 2, y: y + 15, class: 'g-lane-label', fill: color }, s );
			label.textContent = String( ln.name ).length > 6 ? String( ln.name ).slice( 0, 5 ) + '…' : ln.name;
		} );

		return h( 'div', { class: 'ypt-chartwrap' }, s,
			lanes.length ? h( 'div', { class: 'ypt-legend', style: { justifyContent: 'flex-start' } }, lanes.map( function ( ln ) {
				return h( 'span', null, h( 'i', { style: { background: ln.p ? protocolColor( ln.p ) : colorForCompound( ln.name ) } } ), ln.name + ' · ' + ln.doses.length + ' dose' + ( ln.doses.length === 1 ? '' : 's' ) );
			} ) ) : h( 'p', { class: 'ypt-muted ypt-small', style: { margin: '6px 0 0' } }, 'Doses you take show up under the chart.' ) );
	}

	/* ---------- Lab results ---------- */

	/** Common bloodwork, each with the unit US labs usually report it in. Free text is fine too; these are suggestions. */
	var LAB_TESTS = [
		[ 'Testosterone, total', 'ng/dL' ], [ 'Testosterone, free', 'pg/mL' ], [ 'Estradiol', 'pg/mL' ], [ 'SHBG', 'nmol/L' ],
		[ 'LH', 'mIU/mL' ], [ 'FSH', 'mIU/mL' ], [ 'Prolactin', 'ng/mL' ], [ 'PSA', 'ng/mL' ], [ 'IGF-1', 'ng/mL' ],
		[ 'TSH', 'mIU/L' ], [ 'Free T4', 'ng/dL' ], [ 'Free T3', 'pg/mL' ], [ 'Cortisol', 'µg/dL' ],
		[ 'A1C', '%' ], [ 'Fasting glucose', 'mg/dL' ], [ 'Fasting insulin', 'µIU/mL' ],
		[ 'Total cholesterol', 'mg/dL' ], [ 'LDL', 'mg/dL' ], [ 'HDL', 'mg/dL' ], [ 'Triglycerides', 'mg/dL' ], [ 'ApoB', 'mg/dL' ],
		[ 'Hematocrit', '%' ], [ 'Hemoglobin', 'g/dL' ], [ 'ALT', 'U/L' ], [ 'AST', 'U/L' ], [ 'Creatinine', 'mg/dL' ], [ 'eGFR', 'mL/min' ],
		[ 'Vitamin D', 'ng/mL' ], [ 'Ferritin', 'ng/mL' ], [ 'hs-CRP', 'mg/L' ],
	];

	function labResults() {
		return values( state.records.lab ).filter( function ( r ) {
			return r && r.name && isFinite( +r.value );
		} ).sort( function ( a, b ) {
			return a.date.localeCompare( b.date ) || String( a.id ).localeCompare( String( b.id ) );
		} );
	}

	function labKey( name ) {
		return String( name || '' ).trim().toLowerCase();
	}

	/** Results grouped by test, newest test first: [{ name, list (oldest first), latest }]. */
	function labGroups() {
		var by = {};
		labResults().forEach( function ( r ) {
			var k = labKey( r.name );
			( by[ k ] = by[ k ] || { name: r.name, list: [] } ).list.push( r );
		} );
		return Object.keys( by ).map( function ( k ) {
			var g = by[ k ];
			g.latest = g.list[ g.list.length - 1 ];
			g.name = g.latest.name;
			return g;
		} ).sort( function ( a, b ) {
			return b.latest.date.localeCompare( a.latest.date ) || a.name.localeCompare( b.name );
		} );
	}

	function labNum( n ) {
		return n === '' || n == null || ! isFinite( +n ) ? null : +n;
	}

	function fmtLab( v, unit ) {
		return fmtNum( +v, 3 ) + ( unit ? ( unit === '%' ? '%' : ' ' + unit ) : '' );
	}

	/** Where a result sits against the normal range the customer copied from their report. */
	function labFlag( r ) {
		var lo = labNum( r.low );
		var hi = labNum( r.high );
		if ( lo == null && hi == null ) {
			return null;
		}
		if ( lo != null && +r.value < lo ) {
			return { tone: 'warn', text: 'Low' };
		}
		if ( hi != null && +r.value > hi ) {
			return { tone: 'warn', text: 'High' };
		}
		return { tone: 'ok', text: 'In range' };
	}

	function labRangeLabel( r ) {
		var lo = labNum( r.low );
		var hi = labNum( r.high );
		if ( lo != null && hi != null ) {
			return fmtNum( lo, 3 ) + '–' + fmtNum( hi, 3 );
		}
		return lo != null ? 'over ' + fmtNum( lo, 3 ) : hi != null ? 'under ' + fmtNum( hi, 3 ) : '';
	}

	function renderLabs() {
		var wrap = h( 'div', null );
		var groups = labGroups();
		wrap.appendChild( h( 'div', { class: 'ypt-eyebrow ypt-section-label' }, 'Lab results' ) );
		if ( ! groups.length ) {
			wrap.appendChild( h( 'div', { class: 'ypt-card ypt-labs-empty' },
				h( 'span', { class: 'ypt-help__ic ypt-help__ic--flask' }, icon( 'flask' ) ),
				h( 'div', null,
					h( 'b', null, 'Keep your bloodwork here' ),
					h( 'p', { class: 'ypt-muted ypt-small' }, 'Enter results like testosterone, estradiol, A1C or IGF-1 from your lab report. Each test gets a chart with your doses underneath.' ),
					h( 'button', { type: 'button', class: 'ypt-btn', style: { marginTop: '10px' }, onclick: function () {
						openLabSheet( null );
					} }, '+ Add a lab result' )
				)
			) );
			return wrap;
		}
		wrap.appendChild( h( 'div', { class: 'ypt-card ypt-list' },
			groups.map( function ( g ) {
				var r = g.latest;
				var prev = g.list.length > 1 ? g.list[ g.list.length - 2 ] : null;
				var flag = labFlag( r );
				var diff = prev ? +r.value - +prev.value : null;
				return h( 'button', { type: 'button', class: 'ypt-list-row ypt-lab-row', onclick: function () {
					openLabTestSheet( g.name );
				} },
					h( 'span', { class: 'ypt-lab-row__main' },
						h( 'b', null, g.name ),
						h( 'span', { class: 'ypt-muted ypt-small' }, fmtDay( r.date, true ).replace( /^\w+, /, '' ) + ( g.list.length > 1 ? ' · ' + g.list.length + ' results' : '' ) + ( diff != null && diff !== 0 ? ' · ' + ( diff > 0 ? '+' : '−' ) + fmtNum( Math.abs( diff ), 3 ) + ' since last' : '' ) )
					),
					h( 'span', { class: 'ypt-lab-row__val' },
						h( 'b', null, fmtLab( r.value, r.unit ) ),
						flag ? h( 'span', { class: 'ypt-tag ypt-tag--' + flag.tone }, flag.text ) : null
					)
				);
			} ),
			h( 'button', { type: 'button', class: 'ypt-list-row ypt-link-row', onclick: function () {
				openLabSheet( null );
			} }, h( 'span', null, '+ Add a lab result' ), h( 'span', null, '' ) )
		) );
		return wrap;
	}

	/** One test: its chart (doses underneath) and every result, newest first. */
	function openLabTestSheet( name ) {
		var g = labGroups().filter( function ( x ) {
			return labKey( x.name ) === labKey( name );
		} )[ 0 ];
		if ( ! g ) {
			closeSheet();
			return;
		}
		var last = g.latest;
		var today = todayStr();
		var first = g.list[ 0 ].date;
		// A little room before the first result, so its doses show too.
		var from = addDays( first < today ? first : today, -14 );
		var unitMix = g.list.some( function ( r ) {
			return String( r.unit || '' ) !== String( last.unit || '' );
		} );
		var pts = g.list.filter( function ( r ) {
			return String( r.unit || '' ) === String( last.unit || '' );
		} ).map( function ( r ) {
			return { date: r.date, v: +r.value };
		} );
		var band = labNum( last.low ) != null || labNum( last.high ) != null ? { low: labNum( last.low ), high: labNum( last.high ) } : null;
		openSheet( g.name, 'Lab results', [
			h( 'div', { class: 'ypt-card' },
				progressChart( [], null, from, today, { pts: pts, band: band, name: g.name, fmt: function ( v ) {
					return fmtLab( v, last.unit );
				} } ),
				band ? h( 'p', { class: 'ypt-muted ypt-small', style: { margin: '6px 0 0' } }, h( 'span', { class: 'ypt-lab-key' } ), 'Normal range ' + labRangeLabel( last ) + ( last.unit ? ( last.unit === '%' ? '%' : ' ' + last.unit ) : '' ) + ', from your report' ) : null,
				unitMix ? h( 'p', { class: 'ypt-muted ypt-small', style: { margin: '6px 0 0' } }, 'Only results in ' + ( last.unit || 'the latest unit' ) + ' are charted.' ) : null
			),
			h( 'div', { class: 'ypt-card ypt-list', style: { marginTop: '12px' } }, g.list.slice().reverse().map( function ( r ) {
				var flag = labFlag( r );
				return h( 'button', { type: 'button', class: 'ypt-list-row ypt-lab-row', onclick: function () {
					openLabSheet( r );
				} },
					h( 'span', { class: 'ypt-lab-row__main' },
						h( 'b', null, fmtDay( r.date, true ).replace( /^\w+, /, '' ) ),
						r.note ? h( 'span', { class: 'ypt-muted ypt-small' }, r.note ) : null
					),
					h( 'span', { class: 'ypt-lab-row__val' }, h( 'b', null, fmtLab( r.value, r.unit ) ), flag ? h( 'span', { class: 'ypt-tag ypt-tag--' + flag.tone }, flag.text ) : null )
				);
			} ) ),
		], h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--primary ypt-btn--block', onclick: function () {
			openLabSheet( null, g.name );
		} }, '+ Add a result' ) );
	}

	/** Add or edit one result. A test logged before fills in its unit and normal range. */
	function openLabSheet( existing, presetName ) {
		var r = existing ? Object.assign( {}, existing ) : { date: todayStr(), name: presetName || '', value: '', unit: '', low: '', high: '', note: '' };
		var id = existing ? existing.id : uid( 'lab' );
		var err = h( 'p', { class: 'ypt-error', hidden: true } );
		var known = {};
		labResults().forEach( function ( x ) {
			known[ labKey( x.name ) ] = x;
		} );
		var unitIn = h( 'input', { class: 'ypt-input ypt-lab-unit', id: 'ypt-lab-unit', type: 'text', maxlength: '16', placeholder: 'Unit', 'aria-label': 'Unit', value: r.unit || '', oninput: function ( e ) {
			r.unit = e.target.value;
		} } );
		var lowIn = h( 'input', { class: 'ypt-input', id: 'ypt-lab-low', type: 'number', inputmode: 'decimal', step: 'any', placeholder: 'Low', 'aria-label': 'Normal range, low', value: r.low == null ? '' : r.low, oninput: function ( e ) {
			r.low = e.target.value;
		} } );
		var highIn = h( 'input', { class: 'ypt-input', id: 'ypt-lab-high', type: 'number', inputmode: 'decimal', step: 'any', placeholder: 'High', 'aria-label': 'Normal range, high', value: r.high == null ? '' : r.high, oninput: function ( e ) {
			r.high = e.target.value;
		} } );
		function fillFrom( name ) {
			var prev = known[ labKey( name ) ];
			var sug = LAB_TESTS.filter( function ( t ) {
				return labKey( t[ 0 ] ) === labKey( name );
			} )[ 0 ];
			if ( ! String( r.unit || '' ).trim() && ( prev || sug ) ) {
				r.unit = prev ? prev.unit || '' : sug[ 1 ];
				unitIn.value = r.unit;
			}
			if ( prev && r.low === '' && r.high === '' ) {
				r.low = prev.low == null ? '' : prev.low;
				r.high = prev.high == null ? '' : prev.high;
				lowIn.value = r.low;
				highIn.value = r.high;
			}
		}
		var names = Object.keys( known ).map( function ( k ) {
			return known[ k ].name;
		} );
		LAB_TESTS.forEach( function ( t ) {
			if ( ! known[ labKey( t[ 0 ] ) ] ) {
				names.push( t[ 0 ] );
			}
		} );
		var list = h( 'datalist', { id: 'ypt-lab-names' }, names.map( function ( n ) {
			return h( 'option', { value: n } );
		} ) );
		var nameIn = h( 'input', { class: 'ypt-input', id: 'ypt-lab-name', type: 'text', maxlength: '60', list: 'ypt-lab-names', autocomplete: 'off', placeholder: 'Testosterone, total', value: r.name || '', oninput: function ( e ) {
			r.name = e.target.value;
		}, onchange: function ( e ) {
			fillFrom( e.target.value );
		} } );
		if ( presetName && ! existing ) {
			fillFrom( presetName );
		}

		var save = h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--primary ypt-btn--block', onclick: function () {
			err.hidden = true;
			var name = String( r.name || '' ).trim();
			var value = parseFloat( r.value );
			var low = labNum( String( r.low ).trim() === '' ? null : parseFloat( r.low ) );
			var high = labNum( String( r.high ).trim() === '' ? null : parseFloat( r.high ) );
			var problem = ! name ? 'Enter the test name, like it appears on your report.'
				: ! isFinite( value ) ? 'Enter the result as a number.'
				: low != null && high != null && low > high ? 'The low end of the range should be smaller than the high end.' : '';
			if ( problem ) {
				err.textContent = problem;
				err.hidden = false;
				return;
			}
			// Same test, same spelling as before, so results group together.
			var prev = known[ labKey( name ) ];
			var data = {
				date: r.date || todayStr(),
				name: prev ? prev.name : name,
				value: Math.round( value * 10000 ) / 10000,
				unit: String( r.unit || '' ).trim(),
				low: low,
				high: high,
				note: String( r.note || '' ).trim(),
			};
			closeSheet();
			put( 'lab', id, data );
			go( 'progress' );
			toast( existing ? 'Result updated' : 'Lab result saved' );
		} }, 'Save' );

		openSheet( existing ? 'Edit lab result' : 'Add a lab result', 'Lab results', [
			field( 'Test', h( 'div', null, nameIn, list ), 'Pick one or type it the way your report does.', 'ypt-lab-name' ),
			h( 'div', { class: 'ypt-field' },
				h( 'label', { for: 'ypt-lab-value' }, 'Result' ),
				h( 'div', { class: 'ypt-row' },
					h( 'input', { class: 'ypt-input', id: 'ypt-lab-value', type: 'number', inputmode: 'decimal', step: 'any', placeholder: '650', value: r.value, oninput: function ( e ) {
						r.value = e.target.value;
					} } ),
					unitIn
				)
			),
			h( 'div', { class: 'ypt-field' },
				h( 'label', { for: 'ypt-lab-low' }, 'Normal range (optional)' ),
				h( 'div', { class: 'ypt-row ypt-lab-range' }, lowIn, h( 'span', { class: 'ypt-muted' }, 'to' ), highIn ),
				h( 'p', { class: 'ypt-hint' }, 'Usually printed next to the result. We use it to mark results high or low.' )
			),
			field( 'Date of the test', h( 'input', { class: 'ypt-input', id: 'ypt-lab-date', type: 'date', value: r.date, max: todayStr(), onchange: function ( e ) {
				r.date = e.target.value || todayStr();
			} } ), null, 'ypt-lab-date' ),
			field( 'Note', h( 'textarea', { class: 'ypt-textarea', id: 'ypt-lab-note', maxlength: '300', placeholder: 'Fasted, lab name, trough or peak…', oninput: function ( e ) {
				r.note = e.target.value;
			} }, r.note || '' ), null, 'ypt-lab-note' ),
			existing ? h( 'button', { type: 'button', class: 'ypt-link', style: { color: 'var(--ypt-danger)', marginTop: '12px' }, onclick: function () {
				if ( window.confirm( 'Delete this result?' ) ) {
					closeSheet();
					del( 'lab', id );
				}
			} }, 'Delete this result' ) : null,
			err,
		], save );
	}

	/* ---------- Log progress ---------- */

	/** Log or edit a progress entry. `opts.photo` opens the photo picker right away (Add > Progress photo). */
	function openProgressSheet( existing, opts ) {
		var prev = progressEntries().filter( function ( x ) {
			return ! existing || x.id !== existing.id;
		} ).pop() || null;
		var e = existing ? JSON.parse( JSON.stringify( existing ) ) : { date: todayStr(), weight: '', wUnit: weightUnit(), m: {}, mUnit: lengthUnit(), fat: '', note: '', photos: [] };
		e.m = e.m || {};
		e.photos = Array.isArray( e.photos ) ? e.photos : [];
		var id = existing ? existing.id : uid( 'g' );
		var added = [];
		var removed = [];
		var err = h( 'p', { class: 'ypt-error', hidden: true, role: 'alert' } );
		var photosBox = h( 'div', { class: 'ypt-shots' } );
		var showMeasures = MEASURES.some( function ( m ) {
			return +e.m[ m[ 0 ] ] > 0;
		} ) || +e.fat > 0;
		var measuresBox = h( 'div', { hidden: ! showMeasures } );
		var picker = h( 'input', { type: 'file', accept: 'image/*', hidden: true, onchange: function () {
			var room = MAX_PROGRESS_PHOTOS - e.photos.length - added.length;
			var files = [].slice.call( picker.files || [], 0, Math.max( 0, room ) );
			picker.value = '';
			Promise.all( files.map( function ( file ) {
				return shrinkImage( file, 1280, 0.8 );
			} ) ).then( function ( urls ) {
				added = added.concat( urls );
				drawPhotos();
			} ).catch( function ( x ) {
				toast( x.message );
			} );
		} } );
		picker.setAttribute( 'multiple', '' );

		function prevHint( key ) {
			var v = prev ? metricValue( prev, key ) : null;
			return v != null ? 'Last: ' + fmtNum( v, 1 ) : '';
		}

		function drawPhotos() {
			photosBox.textContent = '';
			e.photos.forEach( function ( pid, i ) {
				photosBox.appendChild( h( 'div', { class: 'ypt-shot' }, photoImg( pid ),
					h( 'button', { type: 'button', class: 'ypt-shot__x', 'aria-label': 'Remove photo', onclick: function () {
						removed.push( pid );
						e.photos.splice( i, 1 );
						drawPhotos();
					} }, '×' ) ) );
			} );
			added.forEach( function ( url, i ) {
				photosBox.appendChild( h( 'div', { class: 'ypt-shot' }, h( 'img', { src: url, alt: 'New photo ' + ( i + 1 ) } ),
					h( 'button', { type: 'button', class: 'ypt-shot__x', 'aria-label': 'Remove photo', onclick: function () {
						added.splice( i, 1 );
						drawPhotos();
					} }, '×' ) ) );
			} );
			if ( e.photos.length + added.length < MAX_PROGRESS_PHOTOS ) {
				photosBox.appendChild( h( 'button', { type: 'button', class: 'ypt-shot ypt-shot--add', 'aria-label': 'Add a photo', onclick: function () {
					picker.click();
				} }, '+' ) );
			}
		}

		function num( val, placeholder, label, onInput, idAttr ) {
			return h( 'input', { class: 'ypt-input', id: idAttr, type: 'number', inputmode: 'decimal', min: '0', step: 'any', value: val || '', placeholder: placeholder, 'aria-label': label, oninput: function ( ev ) {
				onInput( ev.target.value );
			} } );
		}

		var unitBox = h( 'div', { class: 'ypt-shrink' }, seg( [ 'lb', 'kg' ], e.wUnit === 'kg' ? 'kg' : 'lb', function ( u ) {
			e.wUnit = u;
		} ) );
		var grid = h( 'div', { class: 'ypt-prog-grid' }, MEASURES.map( function ( m ) {
			return h( 'label', { class: 'ypt-prog-grid__f' }, h( 'span', null, m[ 1 ] ), num( e.m[ m[ 0 ] ], prevHint( m[ 0 ] ).replace( 'Last: ', '' ), m[ 1 ], function ( v ) {
				e.m[ m[ 0 ] ] = v;
			} ) );
		} ).concat( [ h( 'label', { class: 'ypt-prog-grid__f' }, h( 'span', null, 'Body fat %' ), num( e.fat, prevHint( 'fat' ).replace( 'Last: ', '' ), 'Body fat percent', function ( v ) {
			e.fat = v;
		} ) ) ] ) );
		append( measuresBox, [
			h( 'div', { class: 'ypt-row', style: { alignItems: 'center', margin: '4px 0 8px' } }, h( 'span', { class: 'ypt-small ypt-muted' }, 'Measured in' ), h( 'div', { class: 'ypt-shrink' }, seg( [ 'in', 'cm' ], e.mUnit === 'cm' ? 'cm' : 'in', function ( u ) {
				e.mUnit = u;
			} ) ) ),
			grid,
		] );
		var moreBtn = h( 'button', { type: 'button', class: 'ypt-link', hidden: showMeasures, onclick: function () {
			measuresBox.hidden = false;
			moreBtn.hidden = true;
		} }, '+ Add measurements' );

		var save = h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--primary ypt-btn--block', onclick: function () {
			err.hidden = true;
			var m = {};
			MEASURES.forEach( function ( x ) {
				var v = parseFloat( e.m[ x[ 0 ] ] );
				if ( v > 0 ) {
					m[ x[ 0 ] ] = Math.round( v * 100 ) / 100;
				}
			} );
			var data = {
				date: e.date || todayStr(),
				weight: parseFloat( e.weight ) > 0 ? Math.round( parseFloat( e.weight ) * 100 ) / 100 : 0,
				wUnit: e.wUnit === 'kg' ? 'kg' : 'lb',
				m: m,
				mUnit: e.mUnit === 'cm' ? 'cm' : 'in',
				fat: parseFloat( e.fat ) > 0 && parseFloat( e.fat ) < 80 ? Math.round( parseFloat( e.fat ) * 10 ) / 10 : 0,
				note: String( e.note || '' ).trim(),
				photos: e.photos.slice(),
			};
			if ( ! data.weight && ! Object.keys( m ).length && ! data.fat && ! data.note && ! data.photos.length && ! added.length ) {
				err.textContent = 'Enter your weight, a measurement or a photo.';
				err.hidden = false;
				return;
			}
			if ( ( added.length || removed.length ) && state.offline ) {
				err.textContent = 'You’re offline. Connect to the internet to save photos.';
				err.hidden = false;
				return;
			}
			save.disabled = true;
			save.textContent = added.length ? 'Saving photos…' : 'Saving…';
			// Photos first (each its own encrypted record), then the entry that lists them.
			var chain = Promise.resolve();
			added.forEach( function ( url ) {
				chain = chain.then( function () {
					var pid = uid( 'ph' );
					return api( 'PUT', 'tracker/photos/' + pid, { data: url } ).then( function () {
						data.photos.push( pid );
					} );
				} );
			} );
			chain.then( function () {
				removed.forEach( function ( pid ) {
					api( 'DELETE', 'tracker/photos/' + encodeURIComponent( pid ) ).catch( function () {} );
				} );
				closeSheet();
				put( 'progress', id, data );
				if ( ! existing && data.weight && ! ( state.records.settings.me || {} ).units ) {
					// The first weigh-in's unit becomes the customer's units.
					put( 'settings', 'me', Object.assign( {}, state.records.settings.me || {}, { units: data.wUnit === 'kg' ? 'metric' : 'us' } ) );
				}
				go( 'progress' );
				toast( existing ? 'Progress updated' : 'Progress logged' );
			} ).catch( function ( x ) {
				save.disabled = false;
				save.textContent = 'Save';
				if ( handleAuthError( x ) ) {
					return;
				}
				err.textContent = isNetworkError( x ) ? 'You’re offline. Connect to the internet to save photos.' : x.message || 'That couldn’t be saved.';
				err.hidden = false;
			} );
		} }, 'Save' );

		drawPhotos();
		openSheet( existing ? 'Edit progress' : 'Log progress', fmtDay( e.date ), [
			field( 'Date', h( 'input', { class: 'ypt-input', id: 'ypt-g-date', type: 'date', value: e.date, max: todayStr(), onchange: function ( ev ) {
				e.date = ev.target.value || todayStr();
			} } ), null, 'ypt-g-date' ),
			h( 'div', { class: 'ypt-field' },
				h( 'label', { for: 'ypt-g-weight' }, 'Weight' ),
				h( 'div', { class: 'ypt-row' }, num( e.weight, prev && metricValue( prev, 'weight' ) != null ? fmtNum( convert( +prev.weight, prev.wUnit || 'lb', e.wUnit ), 1 ) : '', 'Weight', function ( v ) {
					e.weight = v;
				}, 'ypt-g-weight' ), unitBox ),
				prev && metricValue( prev, 'weight' ) != null ? h( 'p', { class: 'ypt-hint' }, 'Last time: ' + fmtNum( convert( +prev.weight, prev.wUnit || 'lb', e.wUnit ), 1 ) + ' ' + e.wUnit + ' on ' + fmtDay( prev.date ).replace( /^\w+, /, '' ) + '.' ) : null
			),
			h( 'div', { class: 'ypt-field' }, h( 'span', { class: 'ypt-label' }, 'Measurements (optional)' ), moreBtn, measuresBox ),
			h( 'div', { class: 'ypt-field' }, h( 'span', { class: 'ypt-label' }, 'Photos (optional)' ), photosBox, picker,
				h( 'p', { class: 'ypt-hint ypt-share-privacy' }, icon( 'lock' ), 'Encrypted like the rest of your tracker. Only you can see them, and location data is removed.' ) ),
			field( 'Note', h( 'textarea', { class: 'ypt-textarea', id: 'ypt-g-note', maxlength: '500', placeholder: 'Diet, training, how you feel…', oninput: function ( ev ) {
				e.note = ev.target.value;
			} }, e.note || '' ), null, 'ypt-g-note' ),
			existing ? h( 'button', { type: 'button', class: 'ypt-link', style: { color: 'var(--ypt-danger)', marginTop: '12px' }, onclick: function () {
				if ( e.photos.length + removed.length && state.offline ) {
					toast( 'Connect to the internet to delete an entry with photos.' );
					return;
				}
				if ( window.confirm( 'Delete this entry' + ( existing.photos && existing.photos.length ? ' and its photos' : '' ) + '?' ) ) {
					( existing.photos || [] ).forEach( function ( pid ) {
						api( 'DELETE', 'tracker/photos/' + encodeURIComponent( pid ) ).catch( function () {} );
					} );
					closeSheet();
					del( 'progress', id );
				}
			} }, 'Delete this entry' ) : null,
			err,
		], save );
		if ( opts && opts.photo ) {
			picker.click();
		}
	}

	/** Where the customer has injected lately, for everyone who logs spots. Tap a spot to see when it was last used. */
	function historySites() {
		var last = siteLastUsed();
		if ( ! Object.keys( last ).length ) {
			return null;
		}
		var shown = SITES.filter( function ( x ) {
			return last[ x.id ] || protocols().some( function ( p ) {
				return tracksSites( p ) && rotationOf( p ).indexOf( x ) !== -1;
			} );
		} );
		var line = h( 'p', { class: 'ypt-sitepick__line ypt-muted' }, 'Tap a spot to see when you last used it.' );
		var card = h( 'div', { class: 'ypt-card' } );
		function draw( sel ) {
			card.textContent = '';
			card.appendChild( bodyMap( { sites: shown, selected: sel, last: last, onPick: function ( id ) {
				line.className = 'ypt-sitepick__line';
				line.textContent = '';
				append( line, [ h( 'b', null, siteLabel( id ) ), ' · ' + restedLabel( last[ id ] ) ] );
				draw( id );
			} } ) );
			card.appendChild( line );
			card.appendChild( mapLegend() );
		}
		draw( '' );
		return card;
	}

	function exportCsv() {
		var rows = [ [ 'Date', 'Time', 'Compound', 'Dose', 'Unit', 'Status', 'Units drawn', 'Injection site', 'How you felt', 'Note' ] ];
		values( state.records.dose ).sort( function ( a, b ) {
			return ( a.date + a.time ).localeCompare( b.date + b.time );
		} ).forEach( function ( d ) {
			rows.push( [ d.date, d.status === 'taken' && d.at ? fmtIsoTime( d.at ) : fmtTime( d.time ), d.compound, d.dose, d.unit, d.status, d.units == null ? '' : d.units, d.status === 'taken' ? siteLabel( d.site ) : '', tagsOf( d ).join( '; ' ), d.note || '' ] );
		} );
		var csv = rows.map( function ( r ) {
			return r.map( function ( c ) {
				var s = String( c == null ? '' : c );
				// Spreadsheet formula injection guard (tab and carriage
				// return start a formula in some spreadsheet apps too).
				if ( /^[=+\-@\t\r]/.test( s ) ) {
					s = '\'' + s;
				}
				return /[",\r\n]/.test( s ) ? '"' + s.replace( /"/g, '""' ) + '"' : s;
			} ).join( ',' );
		} ).join( '\n' );
		var blob = new Blob( [ csv ], { type: 'text/csv' } );
		var a = h( 'a', { href: URL.createObjectURL( blob ), download: 'dose-history-' + todayStr() + '.csv' } );
		document.body.appendChild( a );
		a.click();
		setTimeout( function () {
			URL.revokeObjectURL( a.href );
			a.remove();
		}, 1000 );
	}

	/* =========================================================
	 * Printable report: a PDF of the dose history to take to a
	 * provider. Built here in the browser (nothing leaves the device),
	 * with the PDF's own Helvetica so there's no font to download.
	 * ======================================================= */

	var REPORT_RANGES = [ [ 30, '30 days' ], [ 90, '90 days' ], [ 180, '6 months' ], [ 0, 'All' ] ];
	var REPORT_PARTS = [
		[ 'meds', 'Medications & schedule', 'Doses, titration, cycles and how many were taken' ],
		[ 'progress', 'Progress', 'Weight and measurements, with a weight chart' ],
		[ 'labs', 'Lab results', 'Each test’s first and latest result, with the normal range' ],
		[ 'feel', 'Side effects & notes', 'What you tagged and how often' ],
		[ 'sites', 'Injection spots', 'How often each spot was used' ],
		[ 'log', 'Dose log', 'Every dose, one per line' ],
	];

	/* Helvetica and Helvetica-Bold advance widths (1/1000 em), chars 32–126. */
	var PDF_W = [
		[ 278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584 ],
		[ 278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611, 975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556, 333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584 ],
	];
	/* Unicode → WinAnsi for the punctuation the app uses; widths [regular, bold]. */
	var PDF_HIGH = {
		8217: [ 146, 222, 278 ], 8216: [ 145, 222, 278 ], 8220: [ 147, 333, 500 ], 8221: [ 148, 333, 500 ], 8226: [ 149, 350, 350 ],
		8211: [ 150, 556, 556 ], 8212: [ 151, 1000, 1000 ], 8230: [ 133, 1000, 1000 ], 183: [ 183, 278, 278 ], 215: [ 215, 584, 584 ], 176: [ 176, 400, 400 ],
	};

	/** Text as WinAnsi bytes (one char per byte); anything Helvetica can't show becomes '?'. */
	function pdfBytes( str ) {
		var out = '';
		String( str == null ? '' : str ).replace( /→/g, '->' ).replace( /[✓✔]/g, 'v' ).split( '' ).forEach( function ( ch ) {
			var c = ch.charCodeAt( 0 );
			if ( c >= 32 && c < 127 ) {
				out += ch;
			} else if ( PDF_HIGH[ c ] ) {
				out += String.fromCharCode( PDF_HIGH[ c ][ 0 ] );
			} else if ( c >= 160 && c < 256 ) {
				out += ch;
			} else if ( c === 10 || c === 9 ) {
				out += ' ';
			} else if ( c < 0xdc00 || c > 0xdfff ) {
				out += '?';
			}
		} );
		return out;
	}

	function pdfWidth( str, size, bold ) {
		var w = 0;
		String( str == null ? '' : str ).split( '' ).forEach( function ( ch ) {
			var c = ch.charCodeAt( 0 );
			if ( c >= 32 && c < 127 ) {
				w += PDF_W[ bold ? 1 : 0 ][ c - 32 ];
			} else if ( PDF_HIGH[ c ] ) {
				w += PDF_HIGH[ c ][ bold ? 2 : 1 ];
			} else {
				w += 556;
			}
		} );
		return ( w * size ) / 1000;
	}

	/** Cut to fit `max` points wide, ending in "…". */
	function pdfFit( str, max, size, bold ) {
		var s = String( str == null ? '' : str );
		if ( pdfWidth( s, size, bold ) <= max ) {
			return s;
		}
		while ( s.length > 1 && pdfWidth( s + '…', size, bold ) > max ) {
			s = s.slice( 0, -1 );
		}
		return s.replace( /\s+$/, '' ) + '…';
	}

	/** Word-wrapped lines no wider than `max` points. */
	function pdfWrap( str, max, size, bold ) {
		var lines = [];
		String( str == null ? '' : str ).split( /\n/ ).forEach( function ( para ) {
			var line = '';
			para.split( /\s+/ ).filter( Boolean ).forEach( function ( word ) {
				var next = line ? line + ' ' + word : word;
				if ( line && pdfWidth( next, size, bold ) > max ) {
					lines.push( line );
					line = word;
				} else {
					line = next;
				}
			} );
			lines.push( pdfFit( line, max, size, bold ) );
		} );
		return lines;
	}

	function pdfColor( hex ) {
		var m = /^#?([0-9a-f]{6})$/i.exec( hex || '' );
		var n = m ? parseInt( m[ 1 ], 16 ) : 0;
		return [ ( n >> 16 ) & 255, ( n >> 8 ) & 255, n & 255 ].map( function ( v ) {
			return ( v / 255 ).toFixed( 3 );
		} ).join( ' ' );
	}

	function pdfNum( n ) {
		return String( Math.round( n * 100 ) / 100 );
	}

	/**
	 * A US Letter document drawn from the top-left (y grows down, like the
	 * screen). doc.text/rect/line draw on the current page; doc.bytes() is
	 * the finished file.
	 */
	function pdfDoc() {
		var W = 612;
		var H = 792;
		var pages = [];
		var cur = null;
		var doc = {
			W: W,
			H: H,
			page: function () {
				cur = [];
				pages.push( cur );
				return doc;
			},
			pageCount: function () {
				return pages.length;
			},
			onPage: function ( i ) {
				cur = pages[ i ];
				return doc;
			},
			text: function ( x, y, str, o ) {
				o = o || {};
				var size = o.size || 10;
				var s = pdfBytes( str );
				if ( o.align === 'right' ) {
					x -= pdfWidth( str, size, o.bold );
				} else if ( o.align === 'center' ) {
					x -= pdfWidth( str, size, o.bold ) / 2;
				}
				cur.push( 'BT ' + pdfColor( o.color || '#141414' ) + ' rg /' + ( o.bold ? 'F2' : 'F1' ) + ' ' + size + ' Tf ' + pdfNum( x ) + ' ' + pdfNum( H - y ) + ' Td (' + s.replace( /[\\()]/g, '\\$&' ) + ') Tj ET' );
				return doc;
			},
			rect: function ( x, y, w, hgt, fill ) {
				cur.push( pdfColor( fill ) + ' rg ' + pdfNum( x ) + ' ' + pdfNum( H - y - hgt ) + ' ' + pdfNum( w ) + ' ' + pdfNum( hgt ) + ' re f' );
				return doc;
			},
			line: function ( pts, color, width, dash ) {
				if ( pts.length < 2 ) {
					return doc;
				}
				cur.push( 'q ' + pdfColor( color ) + ' RG ' + pdfNum( width || 1 ) + ' w 1 J 1 j ' + ( dash ? '[' + dash + '] 0 d ' : '' ) + pts.map( function ( p, i ) {
					return pdfNum( p[ 0 ] ) + ' ' + pdfNum( H - p[ 1 ] ) + ( i ? ' l' : ' m' );
				} ).join( ' ' ) + ' S Q' );
				return doc;
			},
			dot: function ( x, y, r, fill ) {
				var k = r * 0.5523;
				var Y = H - y;
				cur.push( pdfColor( fill ) + ' rg ' + [
					[ x + r, Y ], [ x + r, Y + k, x + k, Y + r, x, Y + r ], [ x - k, Y + r, x - r, Y + k, x - r, Y ],
					[ x - r, Y - k, x - k, Y - r, x, Y - r ], [ x + k, Y - r, x + r, Y - k, x + r, Y ],
				].map( function ( c, i ) {
					return c.map( pdfNum ).join( ' ' ) + ( i ? ' c' : ' m' );
				} ).join( ' ' ) + ' f' );
				return doc;
			},
			bytes: function ( title ) {
				var objs = [];
				objs[ 1 ] = '<< /Type /Catalog /Pages 2 0 R >>';
				objs[ 3 ] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
				objs[ 4 ] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
				objs[ 5 ] = '<< /Title (' + pdfBytes( title ).replace( /[\\()]/g, '\\$&' ) + ') /Producer (YeffoHealth) >>';
				var kids = [];
				pages.forEach( function ( ops, i ) {
					var pageId = 6 + i * 2;
					var stream = ops.join( '\n' );
					kids.push( pageId + ' 0 R' );
					objs[ pageId ] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' + W + ' ' + H + '] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' + ( pageId + 1 ) + ' 0 R >>';
					objs[ pageId + 1 ] = '<< /Length ' + stream.length + ' >>\nstream\n' + stream + '\nendstream';
				} );
				objs[ 2 ] = '<< /Type /Pages /Kids [' + kids.join( ' ' ) + '] /Count ' + pages.length + ' >>';
				var out = '%PDF-1.4\n%\xE2\xE3\xCF\xD3\n';
				var offsets = [];
				for ( var i = 1; i < objs.length; i++ ) {
					offsets[ i ] = out.length;
					out += i + ' 0 obj\n' + objs[ i ] + '\nendobj\n';
				}
				var xref = out.length;
				out += 'xref\n0 ' + objs.length + '\n0000000000 65535 f \n';
				for ( var j = 1; j < objs.length; j++ ) {
					out += ( '000000000' + offsets[ j ] ).slice( -10 ) + ' 00000 n \n';
				}
				out += 'trailer\n<< /Size ' + objs.length + ' /Root 1 0 R /Info 5 0 R >>\nstartxref\n' + xref + '\n%%EOF\n';
				var bytes = new Uint8Array( out.length );
				for ( var k = 0; k < out.length; k++ ) {
					bytes[ k ] = out.charCodeAt( k ) & 255;
				}
				return bytes;
			},
		};
		return doc;
	}

	function shortDate( s, withYear ) {
		var d = parseDate( s );
		return MONTHS[ d.getMonth() ].slice( 0, 3 ) + ' ' + d.getDate() + ( withYear ? ', ' + d.getFullYear() : '' );
	}

	/** The first day a report of `range` days covers ('' range 0 = since the first entry). */
	function reportFrom( range ) {
		var today = todayStr();
		if ( range ) {
			return addDays( today, -( range - 1 ) );
		}
		var first = today;
		values( state.records.dose ).concat( values( state.records.progress ) ).forEach( function ( r ) {
			if ( /^\d{4}-\d{2}-\d{2}$/.test( r.date || '' ) && r.date < first ) {
				first = r.date;
			}
		} );
		protocols().forEach( function ( p ) {
			if ( p.start && p.start < first ) {
				first = p.start;
			}
		} );
		return first;
	}

	/** Scheduled vs taken for one medication between two dates (today's later doses don't count yet). */
	function protocolAdherence( p, from, to ) {
		var start = p.start && p.start > from ? p.start : from;
		var days = Math.min( 1100, daysBetween( start, to ) + 1 );
		var scheduled = 0;
		var taken = 0;
		var skipped = 0;
		for ( var i = 0; i < days; i++ ) {
			var date = addDays( start, i );
			if ( ! isDueOn( p, date ) ) {
				continue;
			}
			timesOf( p ).forEach( function ( t ) {
				var log = state.records.dose[ slotId( p.id, date, t ) ];
				if ( date === todayStr() && t > nowTime() && ! log ) {
					return;
				}
				scheduled++;
				taken += log && log.status === 'taken' ? 1 : 0;
				skipped += log && log.status === 'skipped' ? 1 : 0;
			} );
		}
		return { scheduled: scheduled, taken: taken, skipped: skipped };
	}

	function buildReport( opts ) {
		var today = todayStr();
		var from = reportFrom( opts.range );
		var inc = opts.parts;
		var doc = pdfDoc().page();
		var M = 48;
		var R = doc.W - M;
		var BOTTOM = doc.H - 60;
		var INK = '#141414';
		var MUTED = '#6B6A67';
		var LINE = '#E7E5E1';
		var ACCENT = '#0078A4';
		var y = M;

		function room( need ) {
			if ( y + need > BOTTOM ) {
				doc.page();
				y = M;
				return false;
			}
			return true;
		}

		function heading( title, sub ) {
			room( 64 );
			y += 18;
			doc.text( M, y, title, { size: 14, bold: true } );
			if ( sub ) {
				doc.text( R, y, sub, { size: 9, color: MUTED, align: 'right' } );
			}
			y += 8;
			doc.line( [ [ M, y ], [ R, y ] ], INK, 0.8 );
			y += 16;
		}

		function para( str, o ) {
			o = o || {};
			var size = o.size || 10;
			pdfWrap( str, ( o.width || R - M ) - ( o.indent || 0 ), size, o.bold ).forEach( function ( l ) {
				room( size + 4 );
				doc.text( M + ( o.indent || 0 ), y, l, { size: size, bold: o.bold, color: o.color } );
				y += size + 4;
			} );
		}

		/* Header */
		doc.text( M, y + 10, 'YEFFODESIGN DOSE TRACKER', { size: 8, bold: true, color: ACCENT } );
		y += 34;
		doc.text( M, y, 'Dose report', { size: 24, bold: true } );
		y += 20;
		doc.text( M, y, shortDate( from, true ) + ' – ' + shortDate( today, true ) + ' (' + ( daysBetween( from, today ) + 1 ) + ' days)', { size: 11, color: MUTED } );
		doc.text( R, y, 'Created ' + shortDate( today, true ), { size: 9, color: MUTED, align: 'right' } );
		y += 10;

		var doses = values( state.records.dose ).filter( function ( d ) {
			return d.date >= from && d.date <= today;
		} ).sort( function ( a, b ) {
			return ( a.date + a.time ).localeCompare( b.date + b.time );
		} );
		var taken = doses.filter( function ( d ) {
			return d.status === 'taken';
		} );

		/* Summary boxes */
		var allAdh = { scheduled: 0, taken: 0 };
		protocols().forEach( function ( p ) {
			var a = protocolAdherence( p, from, today );
			allAdh.scheduled += a.scheduled;
			allAdh.taken += a.taken;
		} );
		var boxes = [
			[ String( taken.length ), 'doses taken' ],
			[ allAdh.scheduled ? Math.round( ( allAdh.taken / allAdh.scheduled ) * 100 ) + '%' : '–', 'of scheduled doses taken' ],
			[ String( protocols().length ), protocols().length === 1 ? 'medication' : 'medications' ],
		];
		y += 18;
		var bw = ( R - M - 24 ) / 3;
		boxes.forEach( function ( b, i ) {
			var x = M + i * ( bw + 12 );
			doc.rect( x, y, bw, 52, '#F1F0EC' );
			doc.text( x + 12, y + 26, b[ 0 ], { size: 18, bold: true } );
			doc.text( x + 12, y + 42, b[ 1 ], { size: 9, color: MUTED } );
		} );
		y += 60;

		if ( inc.meds && protocols().length ) {
			heading( 'Medications & schedule' );
			protocols().forEach( function ( p ) {
				var a = protocolAdherence( p, from, today );
				var lines = [
					'Dose now: ' + amountLabel( doseOn( p, today ), p.unit, p ) + ', ' + scheduleLabel( p ) + ' at ' + timesOf( p ).map( fmtTime ).join( ', ' ) + ( p.route ? ' · ' + p.route : '' ),
					stepsOf( p ).length ? 'Titration: ' + stepsLine( p ) : '',
					cycleOf( p ) ? 'Cycle: ' + cycleLine( p ) : '',
					'Started ' + shortDate( p.start, true ) + ( parseInt( p.weeks, 10 ) ? ' · ' + parseInt( p.weeks, 10 ) + ' weeks' : '' ) + ( p.paused ? ' · paused' : '' ),
					a.scheduled ? 'Taken ' + a.taken + ' of ' + a.scheduled + ' scheduled (' + Math.round( ( a.taken / a.scheduled ) * 100 ) + '%)' + ( a.skipped ? ', ' + a.skipped + ' skipped' : '' ) : 'Nothing scheduled in this period',
				].filter( Boolean );
				room( 18 + lines.length * 14 );
				doc.rect( M, y - 9, 4, 12 + lines.length * 13, protocolColor( p ) );
				doc.text( M + 12, y, p.compound, { size: 11, bold: true } );
				y += 15;
				lines.forEach( function ( l ) {
					para( l, { indent: 12, size: 9.5, color: l.indexOf( 'Taken ' ) === 0 ? INK : MUTED } );
				} );
				y += 8;
			} );
		}

		var entries = progressEntries().filter( function ( e ) {
			return e.date >= from && e.date <= today;
		} );
		if ( inc.progress && entries.length ) {
			heading( 'Progress', entries.length + ' check-in' + ( entries.length === 1 ? '' : 's' ) );
			var metrics = availableMetrics( entries );
			var cols = [ M, M + 150, M + 260, M + 370 ];
			doc.text( cols[ 0 ], y, 'Measure', { size: 8.5, bold: true, color: MUTED } );
			doc.text( cols[ 1 ], y, 'First', { size: 8.5, bold: true, color: MUTED } );
			doc.text( cols[ 2 ], y, 'Latest', { size: 8.5, bold: true, color: MUTED } );
			doc.text( cols[ 3 ], y, 'Change', { size: 8.5, bold: true, color: MUTED } );
			y += 14;
			metrics.forEach( function ( k ) {
				var have = entries.filter( function ( e ) {
					return metricValue( e, k ) != null;
				} );
				var a = have[ 0 ];
				var b = have[ have.length - 1 ];
				var va = metricValue( a, k );
				var vb = metricValue( b, k );
				var diff = vb - va;
				room( 16 );
				doc.text( cols[ 0 ], y, metricName( k ), { size: 10, bold: true } );
				doc.text( cols[ 1 ], y, fmtMetric( va, k ) + '  ' + shortDate( a.date ), { size: 9.5 } );
				doc.text( cols[ 2 ], y, fmtMetric( vb, k ) + '  ' + shortDate( b.date ), { size: 9.5 } );
				doc.text( cols[ 3 ], y, have.length < 2 ? '–' : ( diff > 0 ? '+' : diff < 0 ? '−' : '' ).replace( '−', '-' ) + fmtMetric( Math.abs( diff ), k ), { size: 9.5, bold: true } );
				y += 6;
				doc.line( [ [ M, y ], [ R, y ] ], LINE, 0.5 );
				y += 12;
			} );
			var chartKey = metrics[ 0 ];
			var pts = entries.filter( function ( e ) {
				return metricValue( e, chartKey ) != null;
			} );
			if ( pts.length >= 2 ) {
				room( 150 );
				y += 6;
				doc.text( M, y, metricName( chartKey ) + ' (' + metricUnit( chartKey ) + ')', { size: 9, bold: true, color: MUTED } );
				y += 8;
				var cx = M + 34;
				var cw = R - cx;
				var ch = 100;
				var vals = pts.map( function ( e ) {
					return metricValue( e, chartKey );
				} );
				var lo = Math.min.apply( null, vals );
				var hi = Math.max.apply( null, vals );
				var pad = ( hi - lo ) * 0.15 || 1;
				lo -= pad;
				hi += pad;
				var span = Math.max( 1, daysBetween( from, today ) );
				var X = function ( date ) {
					return cx + ( Math.max( 0, daysBetween( from, date ) ) / span ) * cw;
				};
				var Y = function ( v ) {
					return y + ch - ( ( v - lo ) / ( hi - lo ) ) * ch;
				};
				[ 0, 0.5, 1 ].forEach( function ( f ) {
					var v = lo + ( hi - lo ) * f;
					doc.line( [ [ cx, Y( v ) ], [ R, Y( v ) ] ], LINE, 0.5 );
					doc.text( cx - 6, Y( v ) + 3, fmtNum( v, 1 ), { size: 7.5, color: MUTED, align: 'right' } );
				} );
				// Doses taken, as ticks along the bottom in each medication's colour.
				taken.forEach( function ( d ) {
					var p = d.protocolId ? state.records.protocol[ d.protocolId ] : null;
					doc.line( [ [ X( d.date ), y + ch + 4 ], [ X( d.date ), y + ch + 10 ] ], p ? protocolColor( p ) : colorForCompound( d.compound ), 1 );
				} );
				var line = pts.map( function ( e ) {
					return [ X( e.date ), Y( metricValue( e, chartKey ) ) ];
				} );
				doc.line( line, ACCENT, 1.6 );
				line.forEach( function ( p ) {
					doc.dot( p[ 0 ], p[ 1 ], 2.2, ACCENT );
				} );
				doc.text( cx, y + ch + 22, shortDate( from ), { size: 7.5, color: MUTED } );
				doc.text( R, y + ch + 22, shortDate( today ), { size: 7.5, color: MUTED, align: 'right' } );
				doc.text( ( cx + R ) / 2, y + ch + 22, 'Ticks along the bottom are doses taken', { size: 7.5, color: MUTED, align: 'center' } );
				y += ch + 34;
			}
			var noted = entries.filter( function ( e ) {
				return e.note;
			} );
			if ( noted.length ) {
				y += 4;
				noted.slice( -6 ).forEach( function ( e ) {
					para( shortDate( e.date ) + ': ' + e.note, { size: 9, color: MUTED } );
				} );
			}
		}

		var labs = labGroups().map( function ( g ) {
			return { name: g.name, list: g.list.filter( function ( r ) {
				return r.date >= from && r.date <= today;
			} ) };
		} ).filter( function ( g ) {
			return g.list.length;
		} );
		if ( inc.labs && labs.length ) {
			var nLabs = labs.reduce( function ( n, g ) {
				return n + g.list.length;
			}, 0 );
			heading( 'Lab results', nLabs + ' result' + ( nLabs === 1 ? '' : 's' ) + ', typed in from lab reports' );
			var lc = [ M, M + 150, M + 260, M + 370 ];
			doc.text( lc[ 0 ], y, 'Test', { size: 8.5, bold: true, color: MUTED } );
			doc.text( lc[ 1 ], y, 'First', { size: 8.5, bold: true, color: MUTED } );
			doc.text( lc[ 2 ], y, 'Latest', { size: 8.5, bold: true, color: MUTED } );
			doc.text( lc[ 3 ], y, 'Normal range', { size: 8.5, bold: true, color: MUTED } );
			y += 14;
			labs.forEach( function ( g ) {
				var a = g.list[ 0 ];
				var b = g.list[ g.list.length - 1 ];
				var flag = labFlag( b );
				room( 16 );
				doc.text( lc[ 0 ], y, pdfFit( g.name, 140, 10, true ), { size: 10, bold: true } );
				doc.text( lc[ 1 ], y, g.list.length > 1 ? fmtLab( a.value, a.unit ) + '  ' + shortDate( a.date ) : '–', { size: 9.5 } );
				doc.text( lc[ 2 ], y, fmtLab( b.value, b.unit ) + '  ' + shortDate( b.date ), { size: 9.5, bold: !! flag && flag.tone === 'warn' } );
				doc.text( lc[ 3 ], y, ( labRangeLabel( b ) || '–' ) + ( flag && flag.tone === 'warn' ? '  (' + flag.text + ')' : '' ), { size: 9.5, color: flag && flag.tone === 'warn' ? '#B26B00' : INK } );
				y += 6;
				doc.line( [ [ M, y ], [ R, y ] ], LINE, 0.5 );
				y += 12;
			} );
		}

		if ( inc.feel ) {
			var st = feelingStats( from, '' );
			if ( st.rows.length ) {
				heading( 'Side effects & notes', 'Noted on ' + st.tagged + ' of ' + st.taken + ' doses taken' );
				st.rows.slice( 0, 14 ).forEach( function ( r ) {
					room( 16 );
					var tone = tagTone( r.tag );
					doc.dot( M + 4, y - 3, 3, tone === 'bad' ? '#C62828' : tone === 'good' ? '#1F9D55' : '#A9A6A0' );
					doc.text( M + 14, y, r.tag, { size: 10, bold: true } );
					doc.text( M + 150, y, r.n + '×', { size: 10, bold: true } );
					var sub = [ r.top ? ( r.by[ r.top ] === r.n ? 'all with ' + r.top : r.by[ r.top ] + ' with ' + r.top ) : '', r.newDose ? r.newDose + ' in a week the dose went up' : '', 'last ' + shortDate( r.last ) ].filter( Boolean ).join( ' · ' );
					doc.text( M + 190, y, pdfFit( sub, R - M - 190, 9 ), { size: 9, color: MUTED } );
					y += 16;
				} );
			}
		}

		if ( inc.sites ) {
			var bySite = {};
			taken.forEach( function ( d ) {
				if ( d.site && siteLabel( d.site ) ) {
					bySite[ d.site ] = bySite[ d.site ] || { n: 0, last: '' };
					bySite[ d.site ].n++;
					bySite[ d.site ].last = d.date > bySite[ d.site ].last ? d.date : bySite[ d.site ].last;
				}
			} );
			var siteIds = Object.keys( bySite ).sort( function ( a, b ) {
				return bySite[ b ].n - bySite[ a ].n;
			} );
			if ( siteIds.length ) {
				heading( 'Injection spots' );
				var max = bySite[ siteIds[ 0 ] ].n;
				siteIds.forEach( function ( id ) {
					room( 16 );
					doc.text( M, y, siteLabel( id ), { size: 10 } );
					doc.rect( M + 140, y - 8, Math.max( 3, ( bySite[ id ].n / max ) * 220 ), 9, ACCENT );
					doc.text( M + 370, y, bySite[ id ].n + '×', { size: 10, bold: true } );
					doc.text( R, y, 'last ' + shortDate( bySite[ id ].last ), { size: 9, color: MUTED, align: 'right' } );
					y += 16;
				} );
			}
		}

		if ( inc.log ) {
			heading( 'Dose log', doses.length + ' entr' + ( doses.length === 1 ? 'y' : 'ies' ) );
			var LC = [ [ 'Date', M ], [ 'Time', M + 74 ], [ 'Medication', M + 124 ], [ 'Dose', M + 240 ], [ 'Status', M + 310 ], [ 'Spot', M + 360 ], [ 'Felt / note', M + 444 ] ];
			var logHead = function () {
				LC.forEach( function ( c ) {
					doc.text( c[ 1 ], y, c[ 0 ], { size: 8, bold: true, color: MUTED } );
				} );
				y += 6;
				doc.line( [ [ M, y ], [ R, y ] ], LINE, 0.6 );
				y += 12;
			};
			if ( ! doses.length ) {
				para( 'No doses logged in this period.', { color: MUTED } );
			} else {
				logHead();
			}
			doses.forEach( function ( d, i ) {
				var extra = [ tagsOf( d ).join( ', ' ), d.note || '' ].filter( Boolean ).join( ' – ' );
				var noteLines = extra ? pdfWrap( extra, R - LC[ 6 ][ 1 ], 8 ).slice( 0, 3 ) : [ '' ];
				var rowH = 6 + noteLines.length * 10;
				if ( ! room( rowH + 4 ) ) {
					logHead();
				}
				if ( i % 2 ) {
					doc.rect( M - 4, y - 10, R - M + 8, rowH + 2, '#FAF9F6' );
				}
				var cells = [
					shortDate( d.date, true ),
					d.status === 'taken' && d.at ? fmtIsoTime( d.at ) : fmtTime( d.time ),
					d.compound,
					amountLabel( d.dose, d.unit, d ) + ( d.units != null && d.units !== '' && d.status === 'taken' ? ' (' + fmtNum( +d.units, 1 ) + ' u)' : '' ),
					d.status === 'taken' ? 'Taken' : d.status === 'skipped' ? 'Skipped' : String( d.status || '' ),
					d.status === 'taken' ? siteLabel( d.site ) : '',
				];
				cells.forEach( function ( c, j ) {
					doc.text( LC[ j ][ 1 ], y, pdfFit( c, LC[ j + 1 ][ 1 ] - LC[ j ][ 1 ] - 6, 8.5, j === 2 ), { size: 8.5, bold: j === 2, color: d.status === 'skipped' && j === 4 ? '#C62828' : INK } );
				} );
				noteLines.forEach( function ( l, k ) {
					doc.text( LC[ 6 ][ 1 ], y + k * 10, l, { size: 8, color: MUTED } );
				} );
				y += rowH + 4;
			} );
		}

		/* Footers, once the page count is known. */
		var n = doc.pageCount();
		for ( var i = 0; i < n; i++ ) {
			doc.onPage( i );
			doc.line( [ [ M, doc.H - 40 ], [ R, doc.H - 40 ] ], LINE, 0.5 );
			doc.text( M, doc.H - 26, 'A personal log kept in YeffoHealth. YeffoHealth is not a healthcare provider and this isn’t medical advice.', { size: 7.5, color: MUTED } );
			doc.text( R, doc.H - 26, 'Page ' + ( i + 1 ) + ' of ' + n, { size: 7.5, color: MUTED, align: 'right' } );
		}
		return doc.bytes( 'Dose report ' + shortDate( from, true ) + ' – ' + shortDate( today, true ) );
	}

	function openReportSheet() {
		var opts = { range: 90, parts: { meds: true, progress: true, labs: true, feel: true, sites: true, log: true } };
		var body = h( 'div' );
		var summary = h( 'p', { class: 'ypt-hint', style: { marginTop: '6px' } } );

		function drawSummary() {
			var from = reportFrom( opts.range );
			var n = values( state.records.dose ).filter( function ( d ) {
				return d.date >= from;
			} ).length;
			summary.textContent = shortDate( from, true ) + ' to today · ' + n + ' dose' + ( n === 1 ? '' : 's' ) + ' logged';
		}

		body.appendChild( field( 'Time period', seg( REPORT_RANGES, opts.range, function ( v ) {
			opts.range = v;
			drawSummary();
		} ) ) );
		body.appendChild( summary );
		drawSummary();
		body.appendChild( h( 'div', { class: 'ypt-label', style: { marginTop: '18px' } }, 'Include' ) );
		var list = h( 'div', { class: 'ypt-card ypt-report-parts' } );
		REPORT_PARTS.forEach( function ( part ) {
			var sw = h( 'button', { type: 'button', class: 'ypt-switch', role: 'switch', 'aria-checked': 'true', 'aria-label': part[ 1 ], onclick: function () {
				opts.parts[ part[ 0 ] ] = ! opts.parts[ part[ 0 ] ];
				sw.setAttribute( 'aria-checked', opts.parts[ part[ 0 ] ] ? 'true' : 'false' );
			} } );
			list.appendChild( h( 'div', { class: 'ypt-toggle' }, h( 'div', null, h( 'b', null, part[ 1 ] ), h( 'div', { class: 'ypt-muted ypt-small' }, part[ 2 ] ) ), sw ) );
		} );
		body.appendChild( list );
		body.appendChild( h( 'p', { class: 'ypt-fb-privacy' }, icon( 'lock' ), h( 'span', null, 'The PDF is made on this device. It isn’t uploaded or sent anywhere unless you share it.' ) ) );

		function file() {
			var bytes = buildReport( opts );
			return new File( [ bytes ], 'dose-report-' + todayStr() + '.pdf', { type: 'application/pdf' } );
		}

		function download() {
			var f = file();
			var a = h( 'a', { href: URL.createObjectURL( f ), download: f.name } );
			document.body.appendChild( a );
			a.click();
			setTimeout( function () {
				URL.revokeObjectURL( a.href );
				a.remove();
			}, 1000 );
			closeSheet();
			toast( 'Report saved' );
		}

		var canShare = !! ( navigator.canShare && window.File && navigator.canShare( { files: [ new File( [ '' ], 'x.pdf', { type: 'application/pdf' } ) ] } ) );
		var foot = h( 'div', { style: { display: 'flex', gap: '8px', width: '100%' } },
			canShare ? h( 'button', { type: 'button', class: 'ypt-btn', style: { flex: '1' }, onclick: function () {
				navigator.share( { files: [ file() ], title: 'Dose report' } ).then( closeSheet ).catch( function () {} );
			} }, 'Share' ) : null,
			h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--primary', style: { flex: '2' }, onclick: download }, 'Download PDF' )
		);
		openSheet( 'Printable report', 'PDF for your provider', body, foot );
	}

	/* =========================================================
	 * Supply: what's on hand, how long it lasts, when to mix next
	 * ======================================================= */

	/*
	 * A stock record is what the customer has at home that isn't in use yet:
	 *   form 'powder'   unmixed vials (amount + amountUnit mg|IU each)
	 *        'pen'      pen cartridges to mix (amount + amountUnit)
	 *        'premixed' ready-to-use vials (conc mg/mL, volume mL)
	 *        'count'    pills, sprays, patches… (count is how many of them)
 *        'water'    bac water (volume mL per bottle, ml = mL left across
 *                   every bottle; mixing a vial from supply takes its water off)
	 * count is how many vials/pens (or pills) there were at countedAt;
	 * mixing a vial from supply takes one off, and pills count down with
	 * each dose taken after countedAt. warn: days of supply left that
	 * counts as running low (0 = never warn).
	 */
	var STOCK_FORMS = [ [ 'powder', 'Powder vial' ], [ 'pen', 'Pen cartridge' ], [ 'premixed', 'Premixed vial' ], [ 'count', 'Pills & other' ], [ 'water', 'Bac water' ] ];
	var WATER_NAME = 'Bac water';
	var WARN_CHOICES = [ [ 7, '1 week' ], [ 14, '2 weeks' ], [ 30, '1 month' ], [ 0, 'Off' ] ];
	var PLAN_DAYS = 730;
	var BUY_AHEAD_DAYS = 7;

	function stocks() {
		return values( state.records.stock ).sort( function ( a, b ) {
			return String( a.compound ).localeCompare( String( b.compound ) );
		} );
	}

	function isVialStock( s ) {
		return s.form !== 'count' && s.form !== 'water';
	}

	function isWaterStock( s ) {
		return s.form === 'water';
	}

	function waterStocks() {
		return stocks().filter( isWaterStock );
	}

	/** The bac water line a mix takes from: the one with the most left. */
	function waterToMix() {
		return waterStocks().filter( function ( s ) {
			return stockLeft( s ) > 0;
		} ).sort( function ( a, b ) {
			return stockLeft( b ) - stockLeft( a );
		} )[ 0 ] || null;
	}

	/** Whether a stock item feeds this protocol: same name, and the same kind of thing (pen cartridges for pen doses, vials for syringe doses, pills for anything without a vial). */
	function stockFits( s, p ) {
		if ( isWaterStock( s ) || ! sameCompound( s.compound, p.compound ) ) {
			return false;
		}
		if ( ! isVialStock( s ) ) {
			return ! usesVial( p );
		}
		return usesVial( p ) && ( s.form === 'pen' ) === ( p.device === 'pen' );
	}

	/** Every stock item that fits a protocol (a customer might have 5 mg and 10 mg vials of the same peptide). */
	function stockFor( p ) {
		return stocks().filter( function ( s ) {
			return stockFits( s, p );
		} );
	}

	function stockProtocol( s ) {
		return protocols().filter( function ( p ) {
			return ! p.paused && stockFits( s, p );
		} )[ 0 ] || null;
	}

	/** Whether a stock item can be mixed into this vial / pen. */
	function stockMatchesVial( s, v ) {
		return isVialStock( s ) && sameCompound( s.compound, v.compound ) && ( s.form === 'pen' ) === ( v.kind === 'pen' );
	}

	/** How many of one pill/spray/patch a dose uses: the dose itself for countable units ("2 tablets"), otherwise one. */
	function perDoseCount( p ) {
		return UNIT_PLURAL[ p.unit ] ? Math.max( 0, +p.dose || 0 ) : 1;
	}

	/** What's left of a stock item: vials/pens as counted, pills minus every dose taken since they were counted. */
	function stockLeft( s ) {
		if ( isWaterStock( s ) ) {
			return Math.max( 0, Math.round( ( +s.ml || 0 ) * 10 ) / 10 );
		}
		var count = Math.max( 0, +s.count || 0 );
		if ( isVialStock( s ) ) {
			return count;
		}
		var since = s.countedAt || '';
		var used = values( state.records.dose ).reduce( function ( sum, d ) {
			if ( d.status !== 'taken' || ! sameCompound( d.compound, s.compound ) || ! d.at || d.at <= since ) {
				return sum;
			}
			return sum + ( UNIT_PLURAL[ d.unit ] ? Math.max( 0, +d.dose || 0 ) : 1 );
		}, 0 );
		return Math.max( 0, count - used );
	}

	function countUnit( s, n ) {
		if ( isWaterStock( s ) ) {
			return 'mL';
		}
		var u = s.countUnit || 'tablet';
		return n === 1 ? u : UNIT_PLURAL[ u ] || u;
	}

	/** "10 mg powder vials", "5 mg pen cartridges", "2.5 mg/mL · 10 mL vials", "500 mg tablets" */
	function stockSummary( s, n ) {
		var many = n !== 1;
		if ( s.form === 'premixed' ) {
			return ( +s.conc > 0 ? fmtNum( +s.conc ) + ' mg/mL · ' : '' ) + ( +s.volume > 0 ? fmtNum( +s.volume ) + ' mL ' : '' ) + ( many ? 'vials' : 'vial' );
		}
		if ( isWaterStock( s ) ) {
			return ( +s.volume > 0 ? fmtNum( +s.volume ) + ' mL ' : '' ) + ( many ? 'bottles' : 'bottle' );
		}
		if ( s.form === 'count' ) {
			return ( s.strength ? s.strength + ' ' : '' ) + countUnit( s, n );
		}
		var amt = +s.amount > 0 ? fmtNum( +s.amount ) + ' ' + ( s.amountUnit === 'IU' ? 'IU' : 'mg' ) + ' ' : '';
		return amt + ( s.form === 'pen' ? ( many ? 'pen cartridges' : 'pen cartridge' ) : ( many ? 'powder vials' : 'powder vial' ) );
	}

	/** Doses one unmixed vial or pen will give on this protocol, or null when we can't tell. */
	function dosesPerStockVial( s, p ) {
		var cur = currentVial( p );
		var per = cur ? unitsForDose( doseOn( p, todayStr() ), p.unit, cur, p.doseOf ) : null;
		// Same strength as the vial in use: the customer will most likely mix it the same way.
		if ( cur && per && ! isBlend( cur ) && ( s.form === 'premixed' ? +s.conc === +cur.conc || ! +s.conc : +s.amount === +cur.amount || ! +s.amount ) ) {
			var total = s.form === 'premixed' && +s.volume > 0 ? +s.volume * 100 : vialTotalUnits( cur );
			return total ? Math.floor( ( total + 0.0001 ) / per ) : null;
		}
		if ( cur && isBlend( cur ) && per ) {
			return Math.floor( ( vialTotalUnits( cur ) + 0.0001 ) / per );
		}
		var dose = doseOn( p, todayStr() );
		if ( ! ( dose > 0 ) ) {
			return null;
		}
		var inVial = s.form === 'premixed' ? ( +s.conc > 0 && +s.volume > 0 ? +s.conc * +s.volume : 0 ) : +s.amount;
		var vialUnit = s.form === 'premixed' ? 'mg' : ( s.amountUnit === 'IU' ? 'IU' : 'mg' );
		var doseAmt = p.unit === 'mcg' && vialUnit === 'mg' ? dose / 1000 : p.unit === vialUnit ? dose : null;
		if ( ! ( inVial > 0 ) || ! doseAmt ) {
			return null;
		}
		return Math.floor( inVial / doseAmt + 0.0001 );
	}

	/** One date per dose still to take, from today (minus today's logged ones), until the protocol ends or PLAN_DAYS. */
	function upcomingDoseDates( p ) {
		var out = [];
		var today = todayStr();
		var perDay = timesOf( p ).length;
		var logged = slotsOn( today ).filter( function ( sl ) {
			return sl.protocol.id === p.id && sl.log;
		} ).length;
		var weeks = parseInt( p.weeks, 10 ) || 0;
		var end = weeks && p.start ? addDays( p.start, weeks * 7 - 1 ) : null;
		for ( var i = 0, d = today; i < PLAN_DAYS; i++, d = addDays( d, 1 ) ) {
			if ( end && d > end ) {
				break;
			}
			if ( isDueOn( p, d ) ) {
				for ( var k = i === 0 ? logged : 0; k < perDay; k++ ) {
					out.push( d );
				}
			}
		}
		return { dates: out, end: end };
	}

	/** Each upcoming dose as a share of today's dose, so titration stretches or shrinks what a vial covers. Null when the dose never changes (all ones). */
	function doseWeights( p, dates ) {
		if ( ! stepsOf( p ).length ) {
			return null;
		}
		var base = doseOn( p, todayStr() ) || +p.dose || 1;
		return dates.map( function ( d ) {
			return doseOn( p, d ) / base;
		} );
	}

	/** Index just past the doses one vial covers from `from`: up to `cap` of today's doses, and none on or after `expires`. */
	function vialRun( dates, w, from, cap, expires ) {
		var i = from;
		var used = 0;
		while ( i < dates.length ) {
			var x = w ? w[ i ] : 1;
			if ( used + x > cap + 0.0001 || ( expires && dates[ i ] >= expires ) ) {
				break;
			}
			used += x;
			i++;
		}
		return i;
	}

	/**
	 * Every vial a protocol will go through: the one in use, then a new
	 * one each time the last runs out or expires (mixed like the one in
	 * use and kept the same number of days). runs: [{ from, to }] as
	 * indexes into dates; the first is the vial in use when there is one.
	 */
	function mixPlan( p ) {
		var up = upcomingDoseDates( p );
		var dates = up.dates;
		var w = doseWeights( p, dates );
		var cur = currentVial( p );
		var info = cur ? vialInfo( cur ) : null;
		var perVial = null;
		stockFor( p ).some( function ( s ) {
			perVial = dosesPerStockVial( s, p );
			return perVial;
		} );
		if ( ! perVial && info && info.perDose && info.total ) {
			perVial = Math.floor( ( info.total + 0.0001 ) / info.perDose );
		}
		var good = vialGoodFor( cur );
		var runs = [];
		var idx = 0;
		if ( cur ) {
			var cap = info.left != null && info.perDose ? info.left / info.perDose : info.dosesLeft || 0;
			idx = vialRun( dates, w, 0, cap, info.expires );
			runs.push( { from: 0, to: idx } );
		}
		while ( perVial && idx < dates.length && runs.length < 300 ) {
			var end = vialRun( dates, w, idx, perVial, good ? addDays( dates[ idx ], good ) : null );
			end = Math.max( end, idx + 1 );
			runs.push( { from: idx, to: end } );
			idx = end;
		}
		return { up: up, dates: dates, cur: cur, info: info, perVial: perVial, runs: runs };
	}

	/**
	 * How a protocol's supply plays out: the vial in use, then each vial
	 * on hand in turn, then (for a set-length cycle) the ones still to buy.
	 * Pills and other countables are just "enough until…".
	 */
	function supplyPlan( p ) {
		var list = stockFor( p );
		var up = upcomingDoseDates( p );
		var dates = up.dates;
		var plan = { protocol: p, stocks: list, end: up.end, dates: dates, segments: [], covered: 0, perVial: null, onHand: 0, needBuy: 0, buyBy: null, nextNeeded: null, known: true };

		if ( ! usesVial( p ) ) {
			var pills = list.reduce( function ( n, s ) {
				return n + stockLeft( s );
			}, 0 );
			var per = perDoseCount( p );
			plan.onHand = pills;
			plan.covered = per > 0 ? Math.floor( pills / per + 0.0001 ) : 0;
		} else {
			var mp = mixPlan( p );
			var cur = mp.cur;
			var perVial = mp.perVial;
			plan.perVial = perVial;
			plan.known = !! perVial || ( ! list.length && !! cur );
			plan.onHand = list.reduce( function ( n, s ) {
				return n + stockLeft( s );
			}, 0 );
			var idx = 0;
			var runs = mp.runs.slice();
			if ( cur ) {
				var first = runs.shift();
				plan.segments.push( { status: 'use', vial: cur, mixOn: cur.mixed, to: first.to ? dates[ first.to - 1 ] : null, doses: first.to, expires: mp.info.expires, expiresFirst: !! mp.info.expires && mp.info.dosesLeft > first.to } );
				idx = first.to;
			}
			for ( var i = 0; perVial && i < plan.onHand && runs.length; i++ ) {
				var r = runs.shift();
				plan.segments.push( { status: 'stock', mixOn: dates[ r.from ], to: dates[ r.to - 1 ], doses: r.to - r.from } );
				idx = r.to;
			}
			plan.covered = idx;
			if ( perVial && up.end ) {
				while ( runs.length && plan.segments.length < 40 ) {
					var b = runs.shift();
					plan.segments.push( { status: 'buy', mixOn: dates[ b.from ], to: dates[ b.to - 1 ], doses: b.to - b.from } );
					plan.needBuy++;
				}
			}
			var firstBuy = plan.segments.filter( function ( sg ) {
				return sg.status === 'buy';
			} )[ 0 ];
			if ( firstBuy ) {
				var by = addDays( firstBuy.mixOn, -BUY_AHEAD_DAYS );
				plan.buyBy = by < todayStr() ? todayStr() : by;
			}
		}
		plan.all = plan.covered >= dates.length;
		plan.coveredUntil = plan.all ? ( dates.length ? dates[ dates.length - 1 ] : null ) : plan.covered ? dates[ plan.covered - 1 ] : null;
		plan.nextNeeded = plan.all ? null : dates[ plan.covered ];
		return plan;
	}

	/**
	 * Bac water: every upcoming mix across all vial schedules (each new
	 * vial mixed like the one in use, with the same water), paid for in
	 * date order from the mL on hand. coveredUntil is the day before the
	 * first mix there isn't enough water for.
	 */
	function waterPlan() {
		var mixes = [];
		protocols().forEach( function ( p ) {
			if ( p.paused || ! usesVial( p ) ) {
				return;
			}
			var cur = currentVial( p );
			var water = cur && cur.mode !== 'conc' ? +cur.water || 0 : 0;
			var info = cur ? vialInfo( cur ) : null;
			if ( ! water || ! info || info.dosesLeft == null ) {
				return;
			}
			var mp = mixPlan( p );
			mp.runs.slice( 1 ).forEach( function ( r ) {
				if ( mixes.length < 300 ) {
					mixes.push( { date: mp.dates[ r.from ], ml: water, protocol: p } );
				}
			} );
		} );
		mixes.sort( function ( a, b ) {
			return a.date.localeCompare( b.date );
		} );
		var left = waterStocks().reduce( function ( n, s ) {
			return n + stockLeft( s );
		}, 0 );
		var covered = 0;
		while ( covered < mixes.length && left + 0.0001 >= mixes[ covered ].ml ) {
			left -= mixes[ covered ].ml;
			covered++;
		}
		var next = mixes[ covered ] || null;
		return {
			water: true,
			mixes: mixes,
			covered: covered,
			known: mixes.length > 0,
			all: covered >= mixes.length,
			nextNeeded: next ? next.date : null,
			coveredUntil: next ? addDays( next.date, -1 ) : mixes.length ? mixes[ mixes.length - 1 ].date : null,
		};
	}

	function waterStatus( s ) {
		var left = stockLeft( s );
		var plan = waterPlan();
		var warn = s.warn == null ? 14 : +s.warn;
		if ( ! plan.known ) {
			return { protocol: null, left: left, plan: plan, tone: 'mute', text: 'No mixes coming up', low: false };
		}
		if ( plan.all ) {
			return { protocol: null, left: left, plan: plan, tone: 'ok', text: 'Enough for ' + plan.mixes.length + ' mix' + ( plan.mixes.length === 1 ? '' : 'es' ), low: false };
		}
		var until = fmtDay( plan.nextNeeded ).replace( /^\w+, /, '' );
		if ( ! plan.covered ) {
			var soon = daysBetween( todayStr(), plan.nextNeeded ) < warn;
			return { protocol: null, left: left, plan: plan, tone: soon ? 'bad' : 'warn', text: 'Not enough for your next mix', low: warn > 0 && soon };
		}
		var low = warn > 0 && daysBetween( todayStr(), plan.coveredUntil ) < warn;
		return { protocol: null, left: left, plan: plan, tone: low ? 'warn' : 'ok', text: ( low ? 'Low · short on ' : 'Covered to ' ) + until, low: low };
	}

	/** Running low: the stock item's plan runs out within its warning window (and before the cycle ends). */
	function stockStatus( s ) {
		if ( isWaterStock( s ) ) {
			return waterStatus( s );
		}
		var p = stockProtocol( s );
		var left = stockLeft( s );
		if ( ! p ) {
			return { protocol: null, left: left, plan: null, tone: 'mute', text: 'Not in a schedule', low: false };
		}
		var plan = supplyPlan( p );
		var warn = s.warn == null ? 14 : +s.warn;
		if ( ! plan.known ) {
			return { protocol: p, left: left, plan: plan, tone: 'mute', text: 'Add your dose to see how long it lasts', low: false };
		}
		if ( plan.all ) {
			return { protocol: p, left: left, plan: plan, tone: 'ok', text: plan.end ? 'Enough for this cycle' : 'Covered 2+ years', low: false };
		}
		var daysLeft = plan.coveredUntil ? daysBetween( todayStr(), plan.coveredUntil ) : -1;
		var low = warn > 0 && daysLeft < warn;
		var until = plan.coveredUntil ? fmtDay( plan.coveredUntil ).replace( /^\w+, /, '' ) : '';
		if ( ! plan.covered ) {
			return { protocol: p, left: left, plan: plan, tone: 'bad', text: 'Out', low: warn > 0 };
		}
		if ( ! left ) {
			return { protocol: p, left: left, plan: plan, tone: low ? 'warn' : 'mute', text: 'Out · last dose ' + until, low: low };
		}
		return { protocol: p, left: left, plan: plan, tone: low ? 'warn' : 'ok', text: ( low ? 'Low · runs out ' : 'Covered to ' ) + until, low: low };
	}

	/** Compounds running low right now, one entry each. */
	function lowSupplies() {
		var seen = {};
		return stocks().map( function ( s ) {
			return { s: s, st: stockStatus( s ) };
		} ).filter( function ( x ) {
			var key = String( x.s.compound ).toLowerCase();
			if ( ! x.st.low || seen[ key ] ) {
				return false;
			}
			seen[ key ] = true;
			return true;
		} );
	}

	function lowLine( x ) {
		var plan = x.st.plan;
		if ( isWaterStock( x.s ) ) {
			var mixDay = fmtDay( plan.nextNeeded ).replace( /^\w+, /, '' );
			return ! x.st.left ? 'You’re out, and you mix next on ' + mixDay + '.'
				: fmtNum( x.st.left, 1 ) + ' mL left' + ( plan.covered ? ', enough for your mixes until ' + mixDay + '.' : ', not enough for your mix on ' + mixDay + '.' );
		}
		if ( ! plan || ! plan.covered ) {
			return 'You’ve run out.';
		}
		var until = fmtDay( plan.coveredUntil ).replace( /^\w+, /, '' );
		return usesVial( x.st.protocol )
			? ( x.st.left ? 'You have enough until ' + until + '.' : 'Your ' + ( x.s.form === 'pen' ? 'pen' : 'vial' ) + ' runs out ' + until + ' and you have none on hand.' )
			: fmtNum( x.st.left, 0 ) + ' ' + countUnit( x.s, x.st.left ) + ' left, enough until ' + until + '.';
	}

	/* ---------- Supply alerts for the reminder sweep ---------- */

	/**
	 * Upcoming running-low and mix-day notifications, saved as the
	 * `alerts` settings record for class-tracker-reminders.php to send
	 * when each one's time comes (the server doesn't do vial math).
	 * Worked out again whenever anything changes; only saved when the
	 * list differs and reminders are on somewhere.
	 */
	function supplyAlerts() {
		var s = state.records.settings.me || {};
		var out = [];
		var today = todayStr();
		stocks().forEach( function ( st ) {
			var warn = st.warn == null ? 14 : +st.warn;
			var status = stockStatus( st );
			var plan = status.plan;
			if ( ! warn || ! plan || ! plan.known || plan.all || ! plan.coveredUntil ) {
				return;
			}
			var day = addDays( plan.coveredUntil, -warn );
			// Already low: the banner in the app says so. (Re-dating it to today would repeat it every day.)
			if ( day < today ) {
				return;
			}
			out.push( {
				id: 'low-' + labelKey( st.compound ).slice( 0, 30 ) + '-' + plan.coveredUntil.replace( /-/g, '' ),
				date: day,
				time: '10:00',
				title: st.compound + ' is running low',
				body: 'You have enough until ' + fmtDay( plan.coveredUntil ).replace( /^\w+, /, '' ) + '. Time to reorder.',
			} );
		} );
		if ( s.mixReminders !== false ) {
			protocols().forEach( function ( p ) {
				if ( p.paused || ! usesVial( p ) ) {
					return;
				}
				var mp = mixPlan( p );
				if ( ! mp.cur || ! mp.runs.length || ! mp.runs[ 0 ].to ) {
					return;
				}
				var next = mp.dates[ mp.runs[ 0 ].to ];
				if ( ! next || next <= today ) {
					return;
				}
				var have = stockFor( p ).reduce( function ( n, x ) {
					return n + stockLeft( x );
				}, 0 );
				var what = p.device === 'pen' ? 'pen' : 'vial';
				var expiring = mp.info.expires && next >= mp.info.expires && mp.info.dosesLeft > mp.runs[ 0 ].to;
				out.push( {
					id: 'mix-' + labelKey( p.compound ).slice( 0, 30 ) + '-' + next.replace( /-/g, '' ),
					date: addDays( next, -1 ),
					time: '19:00',
					title: 'Mix a new ' + p.compound + ' ' + what + ' tomorrow',
					body: ( expiring ? 'Your ' + what + ' will have expired by your next dose.' : 'Your next dose needs a new ' + what + '.' ) + ( have ? ' You have ' + have + ' on hand.' : ' You have none on hand.' ),
				} );
			} );
		}
		// Vial expiry: a heads-up a couple of days before a mixed vial or pen expires.
		if ( s.expiryReminders !== false ) {
			vials( false ).forEach( function ( v ) {
				var exp = vialExpiry( v );
				var day = exp ? addDays( exp, -EXPIRY_NOTICE_DAYS ) : null;
				if ( ! exp || day < today ) {
					return;
				}
				var what = isPen( v ) ? 'pen' : 'vial';
				out.push( {
					id: 'exp-' + String( v.id ).slice( 0, 30 ) + '-' + exp.replace( /-/g, '' ),
					date: day,
					time: '10:00',
					title: 'Your ' + v.compound + ' ' + what + ' expires in ' + EXPIRY_NOTICE_DAYS + ' days',
					body: 'Mixed ' + fmtDay( v.mixed ).replace( /^\w+, /, '' ) + ', good for ' + vialGoodFor( v ) + ' days. Plan to mix a new one by ' + fmtDay( exp ).replace( /^\w+, /, '' ) + '.',
				} );
			} );
		}
		return out.filter( function ( a ) {
			return a.date >= today;
		} ).sort( function ( a, b ) {
			return ( a.date + a.time ).localeCompare( b.date + b.time );
		} ).slice( 0, 20 );
	}

	var alertsTimer = null;
	function syncAlerts() {
		if ( ! state.loaded || ! ( state.push.devices > 0 ) ) {
			return;
		}
		clearTimeout( alertsTimer );
		alertsTimer = setTimeout( function () {
			var list = supplyAlerts();
			var old = state.records.settings.alerts;
			if ( JSON.stringify( ( old && old.list ) || [] ) !== JSON.stringify( list ) ) {
				put( 'settings', 'alerts', { list: list } );
			}
		}, 400 );
	}

	/* ---------- Supply tab ---------- */

	function renderSupply() {
		var wrap = h( 'div', null );
		var view = ui.supplyView || 'mixed';
		wrap.appendChild( h( 'header', { class: 'ypt-top' },
			h( 'div', null, h( 'div', { class: 'ypt-eyebrow' }, 'Inventory' ), h( 'h1', null, 'Supply' ) ),
			h( 'div', { class: 'ypt-top__actions' },
				h( 'button', { type: 'button', class: 'ypt-btn', onclick: function () {
					openStockSheet( null, null );
				} }, '+ Add stock' ),
				h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--primary', onclick: function () {
					openVialSheet( null, null );
				} }, '+ Mix' )
			)
		) );

		lowSupplies().slice( 0, 2 ).forEach( function ( x ) {
			wrap.appendChild( h( 'div', { class: 'ypt-banner ypt-banner--warn' },
				h( 'div', null, h( 'b', null, x.s.compound + ' is running low. ' ), lowLine( x ),
					h( 'div', { style: { marginTop: '8px', display: 'flex', gap: '8px', flexWrap: 'wrap' } }, h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--white', onclick: function () {
						openStockSheet( x.s, { bought: true } );
					} }, 'I bought more' ), reorderButton( [ x ] ) ) )
			) );
		} );

		var mixedCount = vials( false ).length;
		var onHand = stocks().length;
		wrap.appendChild( h( 'div', { class: 'ypt-views', role: 'tablist' }, [ [ 'mixed', 'Mixed · ' + mixedCount ], [ 'onhand', 'On hand · ' + onHand ], [ 'plan', 'Plan' ] ].map( function ( v ) {
			return h( 'button', { type: 'button', role: 'tab', 'aria-selected': view === v[ 0 ] ? 'true' : 'false', onclick: function () {
				ui.supplyView = v[ 0 ];
				saveJSON( UI_KEY, { tab: ui.tab, supplyView: v[ 0 ] } );
				render();
			} }, v[ 1 ] );
		} ) ) );

		wrap.appendChild( view === 'onhand' ? renderOnHand() : view === 'plan' ? renderPlan() : renderMixed() );
		return wrap;
	}

	/** The expiry countdown under a mixed vial: a tag, plus a note when it expires before it's used up. */
	function expiryLine( v, info ) {
		var tag = expiryTag( info );
		if ( ! tag ) {
			return null;
		}
		var waste = info.dosesLeft != null && info.usable != null && info.dosesLeft > info.usable && ! info.expired ? info.dosesLeft - info.usable : 0;
		return h( 'div', { style: { marginTop: '8px' } },
			h( 'span', { class: 'ypt-tag ypt-tag--' + tag.tone }, tag.text ),
			waste ? h( 'div', { class: 'ypt-small', style: { color: 'var(--ypt-warn)', marginTop: '4px' } }, 'It expires before it’s used up, with about ' + waste + ' dose' + ( waste === 1 ? '' : 's' ) + ' left in it.' ) : null );
	}

	/** "3 more on hand · covered to Dec 8" under a vial in use. */
	function vialSupplyTag( v ) {
		var p = protocols().filter( function ( x ) {
			return ! x.paused && usesVial( x ) && currentVial( x ) && currentVial( x ).id === v.id;
		} )[ 0 ];
		if ( ! p || ! stockFor( p ).length ) {
			return null;
		}
		var plan = supplyPlan( p );
		var have = plan.onHand;
		var until = plan.coveredUntil ? fmtDay( plan.coveredUntil ).replace( /^\w+, /, '' ) : '';
		var low = lowSupplies().some( function ( x ) {
			return sameCompound( x.s.compound, p.compound );
		} );
		var text = have ? have + ' more on hand' : 'None on hand';
		if ( plan.known && have ) {
			text += plan.all ? ( plan.end ? ' · enough for this cycle' : '' ) : until ? ' · covered to ' + until : '';
		}
		return h( 'div', { style: { marginTop: '8px' } }, h( 'span', { class: 'ypt-tag ypt-tag--' + ( low ? 'warn' : have ? 'ok' : 'mute' ) }, text ) );
	}

	function renderMixed() {
		var wrap = h( 'div', null );
		var list = vials( false );
		if ( ! list.length ) {
			wrap.appendChild( h( 'div', { class: 'ypt-card ypt-empty' },
				h( 'h2', null, 'Add the vial you’re using' ),
				h( 'p', null, 'Enter what’s in the vial and how much water you added. We’ll work out how many units to draw for each dose and count how many doses are left.' ),
				h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--primary', onclick: function () {
					openVialSheet( null, null );
				} }, 'Mix a vial' )
			) );
		}

		if ( list.length ) {
			wrap.appendChild( h( 'div', { class: 'ypt-lo-promo' },
				h( 'div', { class: 'ypt-eyebrow' }, 'Labels' ),
				h( 'h2', null, list.length > 1 ? 'Label all ' + list.length + ' vials in one order' : 'Order a label for this vial' ),
				h( 'p', null, 'Pick a design and we fill in each peptide and strength for you.' ),
				h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--accent', onclick: openLabelOrder }, 'Order labels' )
			) );
		}

		list.forEach( function ( v ) {
			var info = vialInfo( v );
			var color = colorForCompound( v.compound );
			var detail = [ 'Mixed ' + fmtDay( v.mixed ).replace( /^\w+, /, '' ) ];
			if ( v.mode === 'conc' ) {
				detail.push( fmtNum( +v.conc ) + ' mg/mL · ' + fmtNum( +v.volume ) + ' mL' );
			} else if ( isBlend( v ) ) {
				detail.push( blendSummary( v ) );
				detail.push( fmtNum( +v.water ) + ' mL' + ( v.blend === 'mixed' ? ' in all' : ' water' ) );
			} else {
				detail.push( fmtNum( +v.water ) + ' mL water' );
			}
			if ( isPen( v ) ) {
				detail.unshift( 'Pen' );
			}
			if ( isBlend( v ) ) {
				detail.unshift( isPen( v ) ? 'Blend' : 'Blend vial' );
			}
			if ( info.perDose ) {
				detail.push( fmtNum( info.perDose, 1 ) + ' units/dose' );
			}
			var status = [];
			if ( info.dosesLeft != null ) {
				status.push( h( 'b', null, info.dosesLeft + ' dose' + ( info.dosesLeft === 1 ? '' : 's' ) + ' left' ) );
				if ( info.lastDose ) {
					status.push( ' · last dose ' + fmtDay( info.lastDose ).replace( /^\w+, /, '' ) );
				}
			} else if ( info.left != null ) {
				status.push( h( 'b', null, fmtNum( info.left, 0 ) + ' units left' ) );
			}

			wrap.appendChild( h( 'div', { class: 'ypt-card ypt-vial' },
				h( 'div', { class: 'ypt-vial__img' + ( isPen( v ) ? ' ypt-vial__img--pen' : '' ), 'aria-hidden': 'true' }, h( 'i', { style: { height: 'calc(' + info.pct + '% - 8px)', background: color, minHeight: info.pct > 0 ? '4px' : '0' } } ) ),
				h( 'div', { style: { flex: '1', minWidth: '0' } },
					h( 'h2', null, v.compound + ( v.mode !== 'conc' && ! isBlend( v ) && +v.amount ? ' · ' + fmtNum( +v.amount ) + ( v.mode === 'iu' ? ' IU' : ' mg' ) : '' ) ),
					h( 'div', { class: 'ypt-muted ypt-small', style: { marginTop: '2px' } }, detail.join( ' · ' ) ),
					h( 'div', { class: 'ypt-bar' }, h( 'i', { style: { width: info.pct + '%', background: color } } ) ),
					h( 'div', { class: 'ypt-small' }, status ),
					vialSupplyTag( v ),
					expiryLine( v, info ),
					h( 'div', { class: 'ypt-vial__actions' },
						h( 'button', { type: 'button', class: 'ypt-pill', onclick: function () {
							openVialSheet( v, null );
						} }, 'Edit' ),
						h( 'button', { type: 'button', class: 'ypt-pill', onclick: function () {
							put( 'vial', v.id, Object.assign( {}, v, { finished: true } ) );
							toast( ( isPen( v ) ? 'Pen' : 'Vial' ) + ' marked finished', function () {
								put( 'vial', v.id, Object.assign( {}, v, { finished: false } ) );
							} );
						} }, 'Finished' )
					)
				)
			) );
		} );

		var finished = vials( true ).filter( function ( v ) {
			return v.finished;
		} );
		if ( finished.length ) {
			wrap.appendChild( h( 'div', { class: 'ypt-eyebrow ypt-section-label' }, 'Finished' ) );
			wrap.appendChild( h( 'div', { class: 'ypt-card ypt-list' }, finished.slice( 0, 10 ).map( function ( v ) {
				return h( 'button', { type: 'button', class: 'ypt-list-row', onclick: function () {
					openVialSheet( v, null );
				} }, h( 'span', null, v.compound ), h( 'span', null, 'Mixed ' + fmtDay( v.mixed, true ).replace( /^\w+, /, '' ) ) );
			} ) ) );
		}
		return wrap;
	}

	function stepper( value, label, onChange ) {
		return h( 'div', { class: 'ypt-stepper', role: 'group', 'aria-label': label },
			h( 'button', { type: 'button', 'aria-label': 'One less', disabled: value <= 0, onclick: function () {
				onChange( Math.max( 0, value - 1 ) );
			} }, '−' ),
			h( 'b', null, String( value ) ),
			h( 'button', { type: 'button', 'aria-label': 'One more', onclick: function () {
				onChange( value + 1 );
			} }, '+' )
		);
	}

	function stockIcon( s ) {
		return h( 'span', { class: 'ypt-mini ypt-mini--' + ( s.form === 'count' || isWaterStock( s ) ? 'bottle' : s.form === 'pen' ? 'pen' : 'vial' ), 'aria-hidden': 'true' } );
	}

	function renderOnHand() {
		var wrap = h( 'div', null );
		var list = stocks();
		wrap.appendChild( h( 'p', { class: 'ypt-muted ypt-small', style: { margin: '2px 4px 10px' } }, 'Unmixed vials, pens, bac water and pills you have at home. Mixing one takes it off this list.' ) );
		if ( ! list.length ) {
			wrap.appendChild( h( 'div', { class: 'ypt-card ypt-empty' },
				h( 'h2', null, 'Keep track of what you have' ),
				h( 'p', null, 'Add the vials, pens, bac water or pills you have at home. We’ll show how long they’ll last on your schedule and remind you before you run out.' ),
				h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--primary', onclick: function () {
					openStockSheet( null, null );
				} }, 'Add stock' )
			) );
			return wrap;
		}
		wrap.appendChild( h( 'div', { class: 'ypt-card ypt-list ypt-stock' }, list.map( function ( s ) {
			var st = stockStatus( s );
			var left = st.left;
			var expires = s.expires ? daysBetween( todayStr(), s.expires ) : null;
			var sub = [ stockSummary( s, 2 ) ];
			if ( s.expires ) {
				sub.push( ( expires < 0 ? 'expired ' : 'exp. ' ) + MONTHS[ parseDate( s.expires ).getMonth() ].slice( 0, 3 ) + ' ' + parseDate( s.expires ).getFullYear() );
			}
			return h( 'div', { class: 'ypt-stock__row' },
				stockIcon( s ),
				h( 'button', { type: 'button', class: 'ypt-stock__main', onclick: function () {
					openStockSheet( s, null );
				} },
					h( 'b', null, s.compound ),
					h( 'span', null, sub.join( ' · ' ) ),
					h( 'span', { class: 'ypt-stock__tags' },
						h( 'span', { class: 'ypt-tag ypt-tag--' + st.tone }, st.text ),
						expires != null && expires < 30 ? h( 'span', { class: 'ypt-tag ypt-tag--warn' }, expires < 0 ? 'Expired' : 'Expires soon' ) : null
					)
				),
				isVialStock( s ) ? stepper( left, s.compound + ' on hand', function ( n ) {
					put( 'stock', s.id, Object.assign( {}, s, { count: n, countedAt: new Date().toISOString() } ) );
				} ) : h( 'div', { class: 'ypt-stock__count' }, h( 'b', null, fmtNum( left, isWaterStock( s ) ? 1 : 0 ) ), h( 'span', null, countUnit( s, left ) ) )
			);
		} ) ) );
		var warnsOn = list.some( function ( s ) {
			return s.warn == null || +s.warn > 0;
		} );
		wrap.appendChild( h( 'div', { class: 'ypt-card ypt-note' }, icon( 'bell' ),
			h( 'div', null, warnsOn
				? 'We’ll warn you here and on Today before anything runs out' + ( state.pushOnHere ? ', with a notification too.' : '. Turn on reminders (tap the bell at the top) to get a notification too.' )
				: 'Running-low warnings are off for everything here.',
			list.some( function ( s ) {
				return s.form === 'count';
			} ) ? ' Pills count down each time you tap Take.' : '',
			list.some( isWaterStock ) ? ' Bac water counts down each time you mix a vial.' : '' ) ) );
		return wrap;
	}

	function renderPlan() {
		var wrap = h( 'div', null );
		var list = protocols().filter( function ( p ) {
			return ! p.paused && ( usesVial( p ) ? currentVial( p ) || stockFor( p ).length : stockFor( p ).length );
		} );
		if ( ! list.length ) {
			wrap.appendChild( h( 'div', { class: 'ypt-card ypt-empty' },
				h( 'h2', null, 'Plan your supply' ),
				h( 'p', null, 'Once you’ve mixed a vial or added what you have on hand, this shows when to mix each new vial and how many more you need to finish your cycle.' ),
				h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--primary', onclick: function () {
					openStockSheet( null, null );
				} }, 'Add stock' )
			) );
			return wrap;
		}
		var p = list.filter( function ( x ) {
			return x.id === ui.planId;
		} )[ 0 ] || list[ 0 ];
		if ( list.length > 1 ) {
			wrap.appendChild( h( 'div', { class: 'ypt-chips', style: { margin: '4px 0 12px' } }, list.map( function ( x ) {
				return h( 'button', { type: 'button', class: 'ypt-chip', 'aria-pressed': x.id === p.id ? 'true' : 'false', onclick: function () {
					ui.planId = x.id;
					render();
				} }, x.compound );
			} ) ) );
		}

		var plan = supplyPlan( p );
		var what = p.device === 'pen' ? 'pen' : 'vial';
		var card = h( 'div', { class: 'ypt-card' } );
		card.appendChild( h( 'div', { class: 'ypt-eyebrow' }, plan.end ? 'This cycle · ' + fmtDay( p.start ).replace( /^\w+, /, '' ) + ' to ' + fmtDay( plan.end ).replace( /^\w+, /, '' ) : 'Ongoing · ' + scheduleLabel( p ) ) );
		var headline;
		if ( ! plan.known ) {
			headline = usesVial( p ) ? 'Add the strength of your ' + what + 's on hand to see how long they last.' : 'Add your dose to see how long this lasts.';
		} else if ( plan.all ) {
			headline = plan.end ? [ 'You have enough to finish this cycle.' ] : [ 'You’re covered for 2+ years.' ];
		} else if ( plan.needBuy ) {
			headline = [ 'You need ', h( 'span', { class: 'ypt-hl' }, plan.needBuy + ' more ' + what + ( plan.needBuy === 1 ? '' : 's' ) ), ' to finish. Buy by ' + fmtDay( plan.buyBy ).replace( /^\w+, /, '' ) + '.' ];
		} else if ( plan.coveredUntil ) {
			headline = [ 'Covered to ', h( 'span', { class: 'ypt-hl' }, fmtDay( plan.coveredUntil ).replace( /^\w+, /, '' ) ), '.' ];
		} else {
			headline = [ h( 'span', { class: 'ypt-hl' }, 'You’re out.' ), ' Add what you have to plan ahead.' ];
		}
		card.appendChild( h( 'div', { class: 'ypt-plan__head' }, headline ) );
		if ( usesVial( p ) && plan.known && plan.end ) {
			var needed = plan.segments.length;
			var have = needed - plan.needBuy;
			card.appendChild( h( 'div', { class: 'ypt-summary', style: { marginTop: '14px' } },
				h( 'div', null, h( 'b', null, String( needed ) ), h( 'span', null, what + 's needed' ) ),
				h( 'div', null, h( 'b', null, String( have ) ), h( 'span', null, 'you have' ) ),
				h( 'div', null, h( 'b', null, String( plan.needBuy ) ), h( 'span', null, 'to buy' ) )
			) );
		} else if ( ! usesVial( p ) && plan.known ) {
			var st = stockFor( p )[ 0 ];
			card.appendChild( h( 'p', { class: 'ypt-muted ypt-small', style: { marginTop: '8px' } }, fmtNum( plan.onHand, 0 ) + ' ' + ( st ? countUnit( st, plan.onHand ) : 'left' ) + ' on hand · ' + fmtNum( perDoseCount( p ), 2 ) + ' per dose · ' + scheduleLabel( p ) ) );
		}
		// Reorder: more vials to buy means more vials to label.
		if ( usesVial( p ) && plan.known && ! plan.all ) {
			card.appendChild( h( 'div', { class: 'ypt-reorder' },
				h( 'span', null, plan.needBuy ? 'Buying ' + plan.needBuy + ' more? Get their labels in the same trip.' : 'Reordering soon? Label the new ones too.' ),
				h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--accent', onclick: function () {
					openLabelOrder( { compounds: [ p.compound ] } );
				} }, 'Order labels' ) ) );
		}
		wrap.appendChild( card );

		if ( usesVial( p ) && plan.segments.length ) {
			var n = 0;
			var tl = h( 'div', { class: 'ypt-tl' } );
			plan.segments.slice( 0, 12 ).forEach( function ( sg ) {
				n++;
				var label = what.charAt( 0 ).toUpperCase() + what.slice( 1 ) + ' ' + n;
				var title;
				var sub;
				if ( sg.status === 'use' ) {
					title = label + ' · in use';
					sub = 'Mixed ' + fmtDay( sg.mixOn ).replace( /^\w+, /, '' ) + ( sg.expiresFirst ? ' · expires ' + fmtDay( sg.expires ).replace( /^\w+, /, '' ) : sg.to ? ' · runs out ' + fmtDay( sg.to ).replace( /^\w+, /, '' ) : ' · used up' );
				} else {
					title = 'Mix ' + what + ' ' + n + ' · ' + fmtDay( sg.mixOn ).replace( /^\w+, /, '' );
					sub = sg.status === 'buy'
						? 'You don’t have this one yet' + ( sg.doses < plan.perVial ? ' · ' + sg.doses + ' dose' + ( sg.doses === 1 ? '' : 's' ) + ' needed' : '' )
						: 'From your supply · lasts to ' + fmtDay( sg.to ).replace( /^\w+, /, '' );
				}
				tl.appendChild( h( 'div', { class: 'ypt-tl__item ypt-tl__item--' + sg.status }, h( 'b', null, title ), h( 'span', null, sub ) ) );
			} );
			if ( plan.segments.length > 12 ) {
				tl.appendChild( h( 'div', { class: 'ypt-tl__item' }, h( 'span', null, '+ ' + ( plan.segments.length - 12 ) + ' more' ) ) );
			}
			if ( plan.end ) {
				tl.appendChild( h( 'div', { class: 'ypt-tl__item ypt-tl__item--end' }, h( 'b', null, 'Cycle ends ' + fmtDay( plan.end ).replace( /^\w+, /, '' ) ),
					h( 'button', { type: 'button', class: 'ypt-link ypt-small', onclick: function () {
						openProtocolSheet( p );
					} }, 'Change length' ) ) );
			} else if ( plan.nextNeeded && plan.known ) {
				tl.appendChild( h( 'div', { class: 'ypt-tl__item ypt-tl__item--buy' }, h( 'b', null, 'Next ' + what + ' needed ' + fmtDay( plan.nextNeeded ).replace( /^\w+, /, '' ) ),
					h( 'button', { type: 'button', class: 'ypt-link ypt-small', onclick: function () {
						openProtocolSheet( p );
					} }, 'Set a cycle length' ) ) );
			}
			wrap.appendChild( h( 'div', { class: 'ypt-card' }, tl ) );
		}

		var waters = waterStocks();
		if ( usesVial( p ) && waters.length ) {
			var wst = waterStatus( waters[ 0 ] );
			var wLeft = waters.reduce( function ( n, x ) {
				return n + stockLeft( x );
			}, 0 );
			wrap.appendChild( h( 'button', { type: 'button', class: 'ypt-card ypt-note ypt-note--btn', onclick: function () {
				openStockSheet( waters[ 0 ], null );
			} }, stockIcon( waters[ 0 ] ),
				h( 'div', null,
					h( 'div', { class: 'ypt-note__title' }, WATER_NAME + ': ' + fmtNum( wLeft, 1 ) + ' mL left ', h( 'span', { class: 'ypt-tag ypt-tag--' + wst.tone }, wst.text ) ),
					wst.plan.known ? 'Covers ' + wst.plan.covered + ' of your next ' + wst.plan.mixes.length + ' mix' + ( wst.plan.mixes.length === 1 ? '' : 'es' ) + ', across all your vials.' : 'No mixes coming up yet.' ) ) );
		}

		var s = state.records.settings.me || {};
		if ( usesVial( p ) ) {
			var on = s.mixReminders !== false;
			var sw = h( 'button', { type: 'button', class: 'ypt-switch', role: 'switch', 'aria-checked': on ? 'true' : 'false', 'aria-label': 'Remind me on mix days' } );
			sw.addEventListener( 'click', function () {
				put( 'settings', 'me', Object.assign( {}, state.records.settings.me || {}, { mixReminders: ! on } ) );
			} );
			var expOn = s.expiryReminders !== false;
			var expSw = h( 'button', { type: 'button', class: 'ypt-switch', role: 'switch', 'aria-checked': expOn ? 'true' : 'false', 'aria-label': 'Remind me before a vial expires' } );
			expSw.addEventListener( 'click', function () {
				put( 'settings', 'me', Object.assign( {}, state.records.settings.me || {}, { expiryReminders: ! expOn } ) );
			} );
			wrap.appendChild( h( 'div', { class: 'ypt-card', style: { padding: '2px 14px' } }, h( 'div', { class: 'ypt-toggle' },
				h( 'div', null, h( 'b', null, 'Remind me on mix days' ), h( 'div', { class: 'ypt-muted ypt-small' }, state.pushOnHere ? 'A notification the evening before.' : 'A notification the evening before, once reminders are on (tap the bell at the top).' ) ),
				sw ), h( 'div', { class: 'ypt-toggle', style: { borderTop: '1px solid var(--ypt-soft)' } },
				h( 'div', null, h( 'b', null, 'Remind me before a vial expires' ), h( 'div', { class: 'ypt-muted ypt-small' }, EXPIRY_NOTICE_DAYS + ' days before, counted from the day you mixed it.' ) ),
				expSw ) ) );
		}
		wrap.appendChild( h( 'p', { style: { textAlign: 'center', marginTop: '10px' } }, h( 'button', { type: 'button', class: 'ypt-link', onclick: function () {
			var mine = stockFor( p )[ 0 ];
			openStockSheet( mine || null, mine ? { bought: true } : { compound: p.compound, form: usesVial( p ) ? ( p.device === 'pen' ? 'pen' : 'powder' ) : 'count' } );
		} }, stockFor( p ).length ? 'Update what you have' : 'Add what you have on hand' ) ) );
		return wrap;
	}

	/* ---------- Add / edit stock ---------- */

	/**
	 * `opts`: { compound, form } to prefill a new item, or { bought: true }
	 * on an existing one to jump straight to adding more.
	 */
	function openStockSheet( existing, opts ) {
		opts = opts || {};
		var cur = protocols().filter( function ( p ) {
			return opts.compound && sameCompound( p.compound, opts.compound );
		} )[ 0 ];
		var curVial = cur ? currentVial( cur ) : null;
		var s = existing ? JSON.parse( JSON.stringify( existing ) ) : {
			compound: opts.compound || ( opts.form === 'water' ? WATER_NAME : '' ),
			form: opts.form || 'powder',
			amount: curVial && curVial.mode !== 'conc' && ! isBlend( curVial ) ? curVial.amount : '',
			amountUnit: curVial && curVial.mode === 'iu' ? 'IU' : 'mg',
			conc: curVial && curVial.mode === 'conc' ? curVial.conc : '',
			volume: curVial && curVial.mode === 'conc' ? curVial.volume : '',
			count: 1,
			countUnit: cur && UNIT_PLURAL[ cur.unit ] ? cur.unit : 'tablet',
			strength: '',
			bought: todayStr(),
			expires: '',
			warn: 14,
		};
		var id = existing ? existing.id : uid( 's' );
		var left = existing ? stockLeft( existing ) : 0;
		var adding = existing && opts.bought ? 1 : 0;
		var err = h( 'p', { class: 'ypt-error', hidden: true } );
		var formBox = h( 'div', null );
		var preview = h( 'div', { 'aria-live': 'polite' } );

		function total() {
			if ( isWaterStock( s ) ) {
				return Math.round( ( existing ? left + adding * ( +s.volume || 0 ) : ( +s.count || 0 ) * ( +s.volume || 0 ) ) * 10 ) / 10;
			}
			return existing ? left + adding : +s.count || 0;
		}

		var saveBtn;

		function saveLabel() {
			var n = existing ? adding : +s.count || 0;
			var what = isWaterStock( s ) ? ' bottle' + ( n === 1 ? '' : 's' ) : '';
			return existing ? ( adding ? 'Add ' + adding + what : 'Save' ) : 'Add ' + n + what;
		}

		function renderPreview() {
			if ( saveBtn ) {
				saveBtn.textContent = saveLabel();
			}
			preview.textContent = '';
			if ( ! s.compound ) {
				return;
			}
			if ( isWaterStock( s ) ) {
				var saved0 = state.records.stock[ id ];
				state.records.stock[ id ] = Object.assign( {}, s, { id: id, ml: total() } );
				var wp = waterPlan();
				if ( saved0 ) {
					state.records.stock[ id ] = saved0;
				} else {
					delete state.records.stock[ id ];
				}
				if ( wp.known && total() > 0 ) {
					preview.appendChild( h( 'div', { class: 'ypt-calc-result ypt-plan__preview' },
						fmtNum( total(), 1 ) + ' mL = ', h( 'b', null, wp.covered + ' of your next ' + wp.mixes.length + ' mix' + ( wp.mixes.length === 1 ? '' : 'es' ) ), '. ',
						wp.all ? 'That’s enough for every vial you have planned.' : [ 'You’re covered to ', h( 'b', null, fmtDay( wp.coveredUntil ).replace( /^\w+, /, '' ) ), '.' ]
					) );
				}
				return;
			}
			var probe = Object.assign( {}, s, { id: id, count: total(), countedAt: new Date().toISOString() } );
			var p = protocols().filter( function ( x ) {
				return ! x.paused && stockFits( probe, x );
			} )[ 0 ];
			if ( ! p ) {
				return;
			}
			var saved = state.records.stock[ id ];
			state.records.stock[ id ] = probe;
			var plan = supplyPlan( p );
			if ( saved ) {
				state.records.stock[ id ] = saved;
			} else {
				delete state.records.stock[ id ];
			}
			if ( ! plan.known ) {
				return;
			}
			var n = total();
			var doses = isVialStock( s ) ? ( plan.perVial || 0 ) * n : Math.floor( n / ( perDoseCount( p ) || 1 ) );
			preview.appendChild( h( 'div', { class: 'ypt-calc-result ypt-plan__preview' },
				n + ' ' + ( isVialStock( s ) ? ( s.form === 'pen' ? 'pen' : 'vial' ) + ( n === 1 ? '' : 's' ) : countUnit( s, n ) ) + ' = ', h( 'b', null, doses + ' dose' + ( doses === 1 ? '' : 's' ) ), ' on your ' + p.compound + ' schedule. ',
				plan.all ? ( plan.end ? 'That’s enough to finish this cycle.' : 'That covers you for 2+ years.' ) : plan.coveredUntil ? [ 'With these you’re covered to ', h( 'b', null, fmtDay( plan.coveredUntil ).replace( /^\w+, /, '' ) ), '.' ] : ''
			) );
		}

		function numField( label, key, placeholder, idAttr ) {
			return field( label, h( 'input', { class: 'ypt-input', id: idAttr, type: 'number', inputmode: 'decimal', min: '0', step: 'any', placeholder: placeholder, value: s[ key ], oninput: function ( e ) {
				s[ key ] = e.target.value;
				renderPreview();
			} } ), null, idAttr );
		}

		function renderForm() {
			formBox.textContent = '';
			if ( isWaterStock( s ) ) {
				renderWaterForm();
				return;
			}
			if ( s.form === 'powder' || s.form === 'pen' ) {
				formBox.appendChild( h( 'div', { class: 'ypt-field' },
					h( 'label', { for: 'ypt-s-amount' }, s.form === 'pen' ? 'In each pen' : 'In each vial' ),
					h( 'div', { class: 'ypt-row' },
						h( 'input', { class: 'ypt-input', id: 'ypt-s-amount', type: 'number', inputmode: 'decimal', min: '0', step: 'any', placeholder: '10', value: s.amount, oninput: function ( e ) {
							s.amount = e.target.value;
							renderPreview();
						} } ),
						h( 'div', { class: 'ypt-shrink' }, seg( [ 'mg', 'IU' ], s.amountUnit === 'IU' ? 'IU' : 'mg', function ( u ) {
							s.amountUnit = u;
							renderPreview();
						} ) )
					)
				) );
			} else if ( s.form === 'premixed' ) {
				formBox.appendChild( h( 'div', { class: 'ypt-row' },
					numField( 'Strength (mg/mL)', 'conc', '200', 'ypt-s-conc' ),
					numField( 'Vial size (mL)', 'volume', '10', 'ypt-s-volume' )
				) );
			} else {
				formBox.appendChild( h( 'div', { class: 'ypt-row' },
					field( 'Strength (optional)', h( 'input', { class: 'ypt-input', id: 'ypt-s-strength', type: 'text', maxlength: '30', placeholder: '500 mg', value: s.strength || '', oninput: function ( e ) {
						s.strength = e.target.value;
					} } ), null, 'ypt-s-strength' ),
					field( 'Counted in', h( 'select', { class: 'ypt-select', id: 'ypt-s-unit', onchange: function ( e ) {
						s.countUnit = e.target.value;
						renderPreview();
					} }, Object.keys( UNIT_PLURAL ).map( function ( u ) {
						return h( 'option', { value: u, selected: u === ( s.countUnit || 'tablet' ) }, UNIT_PLURAL[ u ] );
					} ) ), null, 'ypt-s-unit' )
				) );
			}

			if ( existing ) {
				formBox.appendChild( h( 'div', { class: 'ypt-field' },
					h( 'span', { class: 'ypt-label' }, 'Just bought more?' ),
					isVialStock( s ) ? h( 'div', { class: 'ypt-row', style: { alignItems: 'center' } },
						h( 'span', { class: 'ypt-small' }, 'You have ' + left + ' now. Adding' ),
						h( 'div', { class: 'ypt-shrink' }, stepper( adding, 'Adding', function ( n ) {
							adding = n;
							renderForm();
							renderPreview();
						} ) )
					) : h( 'div', { class: 'ypt-row', style: { alignItems: 'center' } },
						h( 'span', { class: 'ypt-small ypt-shrink' }, fmtNum( left, 0 ) + ' left now, plus' ),
						h( 'input', { class: 'ypt-input', type: 'number', inputmode: 'numeric', min: '0', 'aria-label': 'How many you bought', value: adding || '', placeholder: '0', oninput: function ( e ) {
							adding = Math.max( 0, parseInt( e.target.value, 10 ) || 0 );
							renderPreview();
						} } )
					),
					h( 'p', { class: 'ypt-hint' }, 'Miscounted? Use − and + on the On hand list to set the exact number.' )
				) );
			} else {
				formBox.appendChild( isVialStock( s ) ? h( 'div', { class: 'ypt-field' },
					h( 'span', { class: 'ypt-label' }, 'How many' ),
					stepper( +s.count || 0, 'How many', function ( n ) {
						s.count = n;
						renderForm();
						renderPreview();
					} )
				) : field( 'How many ' + ( UNIT_PLURAL[ s.countUnit || 'tablet' ] || 'you have' ), h( 'input', { class: 'ypt-input', id: 'ypt-s-count', type: 'number', inputmode: 'numeric', min: '0', value: s.count, oninput: function ( e ) {
					s.count = Math.max( 0, parseInt( e.target.value, 10 ) || 0 );
					renderPreview();
				} } ), null, 'ypt-s-count' ) );
			}
		}

		// Bac water: bottles of a set size, tracked in mL.
		function renderWaterForm() {
			formBox.appendChild( numField( 'Bottle size (mL)', 'volume', '30', 'ypt-s-volume' ) );
			if ( existing ) {
				formBox.appendChild( field( 'mL left now', h( 'input', { class: 'ypt-input', id: 'ypt-s-ml', type: 'number', inputmode: 'decimal', min: '0', step: 'any', value: left, oninput: function ( e ) {
					left = Math.max( 0, parseFloat( e.target.value ) || 0 );
					renderPreview();
				} } ), 'A rough guess is fine. It counts down each time you mix a vial.', 'ypt-s-ml' ) );
				formBox.appendChild( h( 'div', { class: 'ypt-field' },
					h( 'span', { class: 'ypt-label' }, 'Just bought more?' ),
					h( 'div', { class: 'ypt-row', style: { alignItems: 'center' } },
						h( 'span', { class: 'ypt-small' }, 'Bottles to add' ),
						h( 'div', { class: 'ypt-shrink' }, stepper( adding, 'Bottles to add', function ( n ) {
							adding = n;
							renderForm();
							renderPreview();
						} ) )
					)
				) );
				return;
			}
			formBox.appendChild( h( 'div', { class: 'ypt-field' },
				h( 'span', { class: 'ypt-label' }, 'How many bottles' ),
				stepper( +s.count || 0, 'How many bottles', function ( n ) {
					s.count = n;
					renderForm();
					renderPreview();
				} ),
				h( 'p', { class: 'ypt-hint' }, 'Already opened one? Save, then tap it to set the mL left.' )
			) );
		}

		var nameInput = compoundInput( 'ypt-s-compound', s.compound, function ( v ) {
			s.compound = v;
			renderPreview();
		} );
		var body = [
			field( 'Name', nameInput, null, 'ypt-s-compound' ),
			h( 'div', { class: 'ypt-field' },
				h( 'span', { class: 'ypt-label' }, 'What is it?' ),
				seg( STOCK_FORMS, s.form, function ( f ) {
					if ( f === 'water' && s.form !== 'water' ) {
						s.volume = '';
						if ( ! String( s.compound || '' ).trim() ) {
							s.compound = WATER_NAME;
							var el = nameInput.querySelector ? nameInput.querySelector( 'input' ) || nameInput : nameInput;
							el.value = WATER_NAME;
						}
					}
					s.form = f;
					renderForm();
					renderPreview();
				}, 'ypt-seg--wrap' )
			),
			formBox,
			h( 'div', { class: 'ypt-row' },
				field( 'Bought', h( 'input', { class: 'ypt-input', id: 'ypt-s-bought', type: 'date', value: s.bought || '', max: todayStr(), onchange: function ( e ) {
					s.bought = e.target.value;
				} } ), null, 'ypt-s-bought' ),
				field( 'Expires (optional)', h( 'input', { class: 'ypt-input', id: 'ypt-s-expires', type: 'date', value: s.expires || '', onchange: function ( e ) {
					s.expires = e.target.value;
				} } ), null, 'ypt-s-expires' )
			),
			h( 'div', { class: 'ypt-field' },
				h( 'span', { class: 'ypt-label' }, 'Warn me when I have' ),
				h( 'div', { class: 'ypt-chips' }, WARN_CHOICES.map( function ( w ) {
					return h( 'button', { type: 'button', class: 'ypt-chip', 'aria-pressed': +( s.warn == null ? 14 : s.warn ) === w[ 0 ] ? 'true' : 'false', onclick: function () {
						s.warn = w[ 0 ];
						[].forEach.call( this.parentNode.children, function ( b ) {
							b.setAttribute( 'aria-pressed', 'false' );
						} );
						this.setAttribute( 'aria-pressed', 'true' );
					} }, w[ 1 ] );
				} ) ),
				h( 'p', { class: 'ypt-hint' }, 'left, based on your schedule. You’ll see it here and on Today, plus a notification if reminders are on.' )
			),
			preview,
			existing ? h( 'button', { type: 'button', class: 'ypt-link', style: { color: 'var(--ypt-danger)', marginTop: '12px' }, onclick: function () {
				if ( window.confirm( 'Remove ' + existing.compound + ' from your supply?' ) ) {
					closeSheet();
					del( 'stock', id );
				}
			} }, 'Remove from supply' ) : null,
			err,
		];

		renderForm();
		renderPreview();

		openSheet( existing ? 'Edit supply' : 'Add to your supply', existing ? existing.compound : 'Supply', body,
			saveBtn = h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--primary ypt-btn--block', onclick: function () {
				err.hidden = true;
				var problem = ! String( s.compound || '' ).trim() ? 'Enter what it is.'
					: s.form === 'premixed' && ! ( +s.conc > 0 && +s.volume > 0 ) ? 'Enter the strength and vial size.'
					: ( s.form === 'powder' || s.form === 'pen' ) && ! ( +s.amount > 0 ) ? 'Enter how much is in each ' + ( s.form === 'pen' ? 'pen.' : 'vial.' )
					: isWaterStock( s ) && ! ( +s.volume > 0 ) ? 'Enter the bottle size.'
					: '';
				if ( problem ) {
					err.textContent = problem;
					err.hidden = false;
					return;
				}
				var data = {
					compound: String( s.compound ).trim(),
					form: s.form,
					amount: s.form === 'powder' || s.form === 'pen' ? +s.amount : 0,
					amountUnit: s.amountUnit === 'IU' ? 'IU' : 'mg',
					conc: s.form === 'premixed' ? +s.conc : 0,
					volume: s.form === 'premixed' ? +s.volume : 0,
					strength: s.form === 'count' ? String( s.strength || '' ).trim() : '',
					countUnit: s.form === 'count' ? s.countUnit || 'tablet' : '',
					count: total(),
					countedAt: new Date().toISOString(),
					bought: s.bought || '',
					expires: s.expires || '',
					warn: s.warn == null ? 14 : +s.warn,
				};
				if ( isWaterStock( s ) ) {
					data.volume = +s.volume;
					data.ml = total();
					data.count = 0;
				}
				// Same thing added again (another order of the same vials): add to that line instead of a second one.
				var twin = existing ? null : stocks().filter( function ( x ) {
					return sameCompound( x.compound, data.compound ) && x.form === data.form && +x.amount === data.amount && +x.conc === data.conc && ( x.countUnit || '' ) === data.countUnit;
				} )[ 0 ];
				closeSheet();
				if ( twin && isWaterStock( twin ) ) {
					put( 'stock', twin.id, Object.assign( {}, twin, { ml: stockLeft( twin ) + data.ml, volume: data.volume, countedAt: data.countedAt, bought: data.bought || twin.bought, expires: data.expires || twin.expires, warn: data.warn } ) );
				} else if ( twin ) {
					put( 'stock', twin.id, Object.assign( {}, twin, { count: stockLeft( twin ) + data.count, countedAt: data.countedAt, bought: data.bought || twin.bought, expires: data.expires || twin.expires, warn: data.warn } ) );
				} else {
					put( 'stock', id, data );
				}
				ui.tab = 'vials';
				ui.supplyView = 'onhand';
				render();
				toast( existing ? 'Supply updated' : data.compound + ' added to your supply' );
			} }, saveLabel() ) );
	}

	/** A stock item to take a vial or pen from when mixing, if there is one. */
	function stockToMix( v ) {
		var list = stocks().filter( function ( s ) {
			return stockMatchesVial( s, v ) && stockLeft( s ) > 0;
		} );
		return list.filter( function ( s ) {
			return v.mode === 'conc' ? +s.conc === +v.conc : +s.amount === +v.amount;
		} )[ 0 ] || list[ 0 ] || null;
	}

	/* ---------- Order labels: one design for every vial ---------- */

	/*
	 * Builds one normal template batch — the same cart line the product
	 * page's "Add another label" rows make — with one label row per
	 * peptide, then sends the customer to the cart. Designs, fields, sizes,
	 * materials and prices all come from the storefront's own routes
	 * (/templates/{id}/configurator, /pricing/calculate, /cart/add), so the
	 * cart re-validates everything exactly as it does for the product page.
	 * Nothing here is saved to the tracker.
	 */

	var labelCache = { list: null, schemas: {} };
	var LABEL_STAGE_REF = 480; // About the product-page stage width the templates' font sizes are set for.
	var CORNERS = [ [ 'squared', 'Squared' ], [ 'rounded', 'Rounded' ] ];

	function labelKey( s ) {
		return String( s || '' ).toLowerCase().replace( /[^a-z0-9]/g, '' );
	}

	/** What prints as a vial's Strength: "5 mg", "5000 IU", "10 mg/mL" for a premixed vial, or "10 mg + 5 mg" for a blend. */
	function vialStrength( v ) {
		if ( isBlend( v ) ) {
			return v.parts.map( function ( x ) {
				return fmtNum( +x.amount );
			} ).join( ' + ' ) + ' mg';
		}
		if ( v.mode === 'conc' ) {
			return +v.conc > 0 ? fmtNum( +v.conc ) + ' mg/mL' : '';
		}
		return +v.amount > 0 ? fmtNum( +v.amount ) + ( v.mode === 'iu' ? ' IU' : ' mg' ) : '';
	}

	/** What prints as an unmixed stock item's Strength, the same way as vialStrength(). */
	function stockStrength( s ) {
		if ( s.form === 'premixed' ) {
			return +s.conc > 0 ? fmtNum( +s.conc ) + ' mg/mL' : '';
		}
		return +s.amount > 0 ? fmtNum( +s.amount ) + ( s.amountUnit === 'IU' ? ' IU' : ' mg' ) : '';
	}

	/**
	 * One row per peptide + strength: open vials ticked, a few recently
	 * finished ones and the vials on hand offered unticked. `focus`
	 * (compound names, from "Order labels" on a running-low warning) ticks
	 * just those and lists them first.
	 */
	function labelRows( focus ) {
		var rows = [];
		var qty = ( CFG.qtyPresets || [] )[ 0 ] || 10;
		function focused( name ) {
			return ( focus || [] ).some( function ( c ) {
				return sameCompound( c, name );
			} );
		}
		function add( compound, strength, open, note ) {
			var key = labelKey( compound ) + '|' + labelKey( strength );
			if ( rows.some( function ( r ) {
				return r.key === key;
			} ) ) {
				return;
			}
			rows.push( { key: key, compound: compound, strength: strength, qty: qty, on: focus ? focused( compound ) : open, open: open, note: note, values: null } );
		}
		var finished = vials( true ).filter( function ( v ) {
			return v.finished;
		} );
		vials( false ).concat( finished ).forEach( function ( v ) {
			if ( v.finished && ! focused( v.compound ) && rows.filter( function ( r ) {
				return r.note === 'finished';
			} ).length >= 5 ) {
				return;
			}
			add( v.compound, vialStrength( v ), ! v.finished, v.finished ? 'finished' : '' );
		} );
		stocks().filter( isVialStock ).forEach( function ( s ) {
			add( s.compound, stockStrength( s ), false, 'on hand' );
		} );
		( focus || [] ).forEach( function ( c ) {
			if ( ! rows.some( function ( r ) {
				return sameCompound( r.compound, c );
			} ) ) {
				add( c, '', false, '' );
			}
		} );
		if ( focus ) {
			rows.sort( function ( a, b ) {
				return ( b.on ? 1 : 0 ) - ( a.on ? 1 : 0 );
			} );
		}
		return rows;
	}

	/** Reorder: labels for the compounds running low (the vial kinds; pills don't get vial labels). */
	function reorderButton( list, cls ) {
		var names = list.filter( function ( x ) {
			return isVialStock( x.s );
		} ).map( function ( x ) {
			return x.s.compound;
		} );
		return names.length ? h( 'button', { type: 'button', class: cls || 'ypt-btn ypt-btn--white', onclick: function () {
			openLabelOrder( { compounds: names } );
		} }, 'Order labels' ) : null;
	}

	function loadLabelTemplates() {
		if ( labelCache.list ) {
			return Promise.resolve( labelCache.list );
		}
		return api( 'GET', 'tracker/label-templates' ).then( function ( data ) {
			labelCache.list = { templates: data.templates || [], startingPrice: data.startingPrice || '' };
			return labelCache.list;
		} );
	}

	function loadLabelSchema( id ) {
		if ( labelCache.schemas[ id ] ) {
			return Promise.resolve( labelCache.schemas[ id ] );
		}
		return api( 'GET', 'templates/' + id + '/configurator' ).then( function ( s ) {
			labelCache.schemas[ id ] = s;
			return s;
		} );
	}

	/** `opts.compounds`: reordering from a running-low warning, so those vials come ticked and first. (Also used straight as a click handler, so anything else is ignored.) */
	function openLabelOrder( opts ) {
		if ( state.offline ) {
			toast( 'Connect to the internet to order labels.' );
			return;
		}
		var focus = opts && Array.isArray( opts.compounds ) && opts.compounds.length ? opts.compounds : null;
		var rows = labelRows( focus );
		var o = { templateId: 0, title: '', schema: null, sizeId: 0, materialId: 0, shared: {}, confirmed: false };
		var body = h( 'div', null );
		var foot = h( 'div', { class: 'ypt-lo-foot' } );
		var err = h( 'p', { class: 'ypt-error', hidden: true, role: 'alert' } );

		function showError( msg ) {
			err.textContent = msg;
			err.hidden = false;
			err.scrollIntoView( { block: 'nearest' } );
		}

		function picked() {
			return rows.filter( function ( r ) {
				return r.on;
			} );
		}

		function fieldsOf( types ) {
			return ( o.schema ? o.schema.field_schema : [] ).filter( function ( f ) {
				return types.indexOf( f.type ) !== -1;
			} );
		}

		/** Label fields that differ per vial (compound, strength, batch…); corners and colors are picked once for the order. */
		function rowFields() {
			return fieldsOf( [ 'text', 'textarea', 'qr_code' ] );
		}

		function sharedFields() {
			return fieldsOf( [ 'corner_style', 'color_choice', 'color' ] );
		}

		function findField( list, re ) {
			return list.filter( function ( f ) {
				return f.type === 'text' && re.test( f.label );
			} )[ 0 ] || null;
		}

		function sharedValue( f ) {
			return o.shared[ f.id ] != null ? o.shared[ f.id ] : ( f.type === 'corner_style' ? ( CORNERS.some( function ( c ) {
				return c[ 0 ] === f.default;
			} ) ? f.default : '' ) : ( f.default || '' ) );
		}

		function colorFor( target ) {
			var f = fieldsOf( [ 'color_choice' ] ).filter( function ( x ) {
				return x.target === target;
			} )[ 0 ];
			return f ? sharedValue( f ) : '';
		}

		function nameOf( list, id ) {
			var x = ( list || [] ).filter( function ( r ) {
				return r.id === id;
			} )[ 0 ];
			return x ? x.name : '';
		}

		function show( step ) {
			err.hidden = true;
			var eyebrow = ui.sheet && ui.sheet.node.querySelector( '.ypt-sheet__head .ypt-eyebrow' );
			if ( eyebrow ) {
				eyebrow.textContent = 'Step ' + step + ' of 3';
			}
			body.textContent = '';
			foot.textContent = '';
			body.appendChild( h( 'div', { class: 'ypt-lo-progress', 'aria-hidden': 'true' }, [ 1, 2, 3 ].map( function ( n ) {
				return h( 'i', { class: n <= step ? 'is-on' : null } );
			} ) ) );
			[ stepVials, stepDesign, stepReview ][ step - 1 ]();
			if ( body.parentNode ) {
				body.parentNode.scrollTop = 0;
			}
		}

		/* Step 1: which vials, and how many labels each. */
		function stepVials() {
			if ( ! rows.length ) {
				body.appendChild( h( 'div', { class: 'ypt-card ypt-empty' },
					h( 'p', null, 'Add a vial on the Vials tab first, then come back here to order labels for it.' )
				) );
				foot.appendChild( h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--block', onclick: closeSheet }, 'Close' ) );
				return;
			}
			body.appendChild( h( 'p', { class: 'ypt-muted ypt-small ypt-lo-lead' }, focus
				? 'Running low on ' + focus.join( ' and ' ) + '? Label your next vials now so they’re ready when they arrive. Each one gets its peptide and strength filled in.'
				: 'Each vial gets its own label with its peptide and strength filled in.' ) );

			var next = h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--primary ypt-btn--block', onclick: function () {
				show( 2 );
			} }, 'Next: pick a design' );

			function update() {
				var list = picked();
				next.disabled = ! list.length || list.some( function ( r ) {
					return ! ( r.qty >= 1 );
				} );
			}

			rows.forEach( function ( r ) {
				var row, box;
				var qty = h( 'input', { class: 'ypt-lo-qty__n', type: 'number', inputmode: 'numeric', min: '1', step: '1', value: String( r.qty ), 'aria-label': 'Labels for ' + r.compound, oninput: function () {
					r.qty = Math.max( 0, parseInt( this.value, 10 ) || 0 );
					update();
				} } );
				function setOn( on ) {
					r.on = on;
					box.setAttribute( 'aria-checked', on ? 'true' : 'false' );
					row.classList.toggle( 'is-on', on );
					update();
				}
				function bump( d ) {
					r.qty = Math.max( 1, ( r.qty || 0 ) + d );
					qty.value = String( r.qty );
					setOn( true );
				}
				box = h( 'button', { type: 'button', class: 'ypt-lo-check', role: 'checkbox', 'aria-checked': r.on ? 'true' : 'false', 'aria-label': r.compound + ( r.strength ? ' ' + r.strength : '' ), onclick: function () {
					setOn( ! r.on );
				} }, icon( 'check' ) );
				row = h( 'div', { class: 'ypt-card ypt-lo-row' + ( r.on ? ' is-on' : '' ) },
					box,
					h( 'div', { class: 'ypt-lo-row__main' },
						h( 'b', null, r.compound ),
						h( 'span', { class: 'ypt-muted ypt-small' }, [ r.strength || 'Strength not set', r.note ].filter( Boolean ).join( ' · ' ) )
					),
					h( 'div', { class: 'ypt-lo-qty' },
						h( 'button', { type: 'button', 'aria-label': 'Fewer labels', onclick: function () {
							bump( -10 );
						} }, '−' ),
						qty,
						h( 'button', { type: 'button', 'aria-label': 'More labels', onclick: function () {
							bump( 10 );
						} }, '+' )
					)
				);
				body.appendChild( row );
			} );
			body.appendChild( h( 'p', { class: 'ypt-hint' }, 'The number is how many labels to print for that peptide.' ) );
			foot.appendChild( next );
			update();
		}

		/* Step 2: design, then size, material, corners and colors for the whole order. */
		function stepDesign() {
			var grid = h( 'div', { class: 'ypt-lo-grid' }, h( 'p', { class: 'ypt-muted' }, 'Loading designs…' ) );
			var opts = h( 'div', null );
			var next = h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--primary ypt-btn--block', disabled: true, onclick: function () {
				show( 3 );
			} }, 'Next: check labels' );
			body.appendChild( grid );
			body.appendChild( opts );
			body.appendChild( err );
			foot.appendChild( h( 'button', { type: 'button', class: 'ypt-btn', onclick: function () {
				show( 1 );
			} }, 'Back' ) );
			foot.appendChild( next );

			function update() {
				next.disabled = ! o.schema || ! o.sizeId || ! o.materialId || sharedFields().some( function ( f ) {
					return f.required && ! sharedValue( f );
				} );
			}

			function choices( list, current, onPick ) {
				var node = h( 'div', { class: 'ypt-chips' } );
				list.forEach( function ( c ) {
					node.appendChild( h( 'button', { type: 'button', class: 'ypt-chip ypt-chip--sm ypt-lo-choice', 'aria-pressed': c[ 0 ] === current ? 'true' : 'false', onclick: function () {
						[].forEach.call( node.children, function ( b ) {
							b.setAttribute( 'aria-pressed', 'false' );
						} );
						this.setAttribute( 'aria-pressed', 'true' );
						onPick( c[ 0 ] );
						update();
					} }, c[ 1 ], c[ 2 ] ? h( 'small', null, c[ 2 ] ) : null ) );
				} );
				return node;
			}

			function colorPicker( f ) {
				var label = h( 'span', null );
				var node = h( 'div', { class: 'ypt-swatches' } );
				function sync() {
					var v = sharedValue( f );
					var match = ( f.options || [] ).filter( function ( c ) {
						return String( c.hex ).toLowerCase() === String( v ).toLowerCase();
					} )[ 0 ];
					label.textContent = match ? ' · ' + match.name : '';
					[].forEach.call( node.children, function ( b ) {
						b.setAttribute( 'aria-pressed', b.dataset.hex.toLowerCase() === String( v ).toLowerCase() ? 'true' : 'false' );
					} );
				}
				( f.options || [] ).forEach( function ( c ) {
					node.appendChild( h( 'button', { type: 'button', class: 'ypt-swatch', 'data-hex': c.hex, title: c.name, 'aria-label': c.name, style: { background: c.hex }, onclick: function () {
						o.shared[ f.id ] = c.hex;
						sync();
						update();
					} } ) );
				} );
				sync();
				return h( 'div', { class: 'ypt-field' }, h( 'div', { class: 'ypt-label' }, f.label, label ), node );
			}

			function renderOptions() {
				var s = o.schema;
				opts.textContent = '';
				// Custom sizes (the customer types inches) stay on the product page.
				var sizes = ( s.sizes || [] ).filter( function ( z ) {
					return +z.print_width_mm > 0 && +z.print_height_mm > 0;
				} );
				var mats = ( s.materials || [] ).filter( function ( m ) {
					return m.in_stock;
				} );
				if ( ! sizes.length || ! mats.length ) {
					opts.appendChild( h( 'p', { class: 'ypt-error' }, 'This design can’t be ordered from here right now. Please pick another one.' ) );
					update();
					return;
				}
				if ( ! sizes.some( function ( z ) {
					return z.id === o.sizeId;
				} ) ) {
					o.sizeId = sizes[ 0 ].id;
				}
				if ( ! mats.some( function ( m ) {
					return m.id === o.materialId;
				} ) ) {
					o.materialId = mats[ 0 ].id;
				}
				opts.appendChild( field( 'Size', choices( sizes.map( function ( z ) {
					return [ z.id, z.name, z.fit_note ];
				} ), o.sizeId, function ( v ) {
					o.sizeId = v;
				} ) ) );
				opts.appendChild( field( 'Material', choices( mats.map( function ( m ) {
					return [ m.id, m.name, '' ];
				} ), o.materialId, function ( v ) {
					o.materialId = v;
				} ) ) );
				sharedFields().forEach( function ( f ) {
					if ( f.type === 'corner_style' ) {
						opts.appendChild( field( f.label, choices( CORNERS, sharedValue( f ), function ( v ) {
							o.shared[ f.id ] = v;
						} ) ) );
					} else if ( f.type === 'color_choice' ) {
						opts.appendChild( colorPicker( f ) );
					} else {
						opts.appendChild( field( f.label, h( 'input', { class: 'ypt-input ypt-lo-color', type: 'color', value: sharedValue( f ) || '#141414', oninput: function () {
							o.shared[ f.id ] = this.value;
							update();
						} } ) ) );
					}
				} );
				update();
			}

			function pick( t, card ) {
				[].forEach.call( grid.children, function ( c ) {
					c.setAttribute( 'aria-pressed', c === card ? 'true' : 'false' );
				} );
				if ( o.templateId !== t.id ) {
					o.templateId = t.id;
					o.schema = null;
					o.shared = {};
				}
				o.title = t.title;
				err.hidden = true;
				opts.textContent = '';
				opts.appendChild( h( 'p', { class: 'ypt-muted' }, 'Loading options…' ) );
				update();
				loadLabelSchema( t.id ).then( function ( s ) {
					if ( o.templateId === t.id ) {
						o.schema = s;
						renderOptions();
					}
				} ).catch( function ( e ) {
					opts.textContent = '';
					showError( isNetworkError( e ) ? 'You’re offline. Connect to the internet to order labels.' : e.message );
				} );
			}

			loadLabelTemplates().then( function ( data ) {
				grid.textContent = '';
				if ( ! data.templates.length ) {
					grid.appendChild( h( 'p', { class: 'ypt-muted' }, 'No designs are available here right now. ', h( 'a', { href: CFG.labelsUrl }, 'Shop peptide labels' ) ) );
					return;
				}
				data.templates.forEach( function ( t ) {
					var card = h( 'button', { type: 'button', class: 'ypt-lo-tpl', 'aria-pressed': t.id === o.templateId ? 'true' : 'false', onclick: function () {
						pick( t, card );
					} },
						h( 'span', { class: 'ypt-lo-tpl__img' }, t.artworkUrl ? h( 'img', { src: t.artworkUrl, alt: '', loading: 'lazy' } ) : null ),
						h( 'b', null, t.title ),
						data.startingPrice ? h( 'span', { class: 'ypt-muted ypt-small' }, data.startingPrice ) : null
					);
					grid.appendChild( card );
				} );
				if ( o.templateId && o.schema ) {
					renderOptions();
				}
			} ).catch( function ( e ) {
				grid.textContent = '';
				showError( isNetworkError( e ) ? 'You’re offline. Connect to the internet to order labels.' : e.message );
			} );
		}

		/** The design's artwork with this row's own text drawn where the product page draws it. */
		function labelPreview( r ) {
			var s = o.schema;
			var size = ( s.sizes || [] ).filter( function ( z ) {
				return z.id === o.sizeId;
			} )[ 0 ];
			// A round Size (vial-lid stickers) masks the preview to a circle, as the product page does.
			var wrap = h( 'div', { class: 'ypt-lo-preview' + ( size && size.shape === 'circle' ? ' is-round' : '' ) } );
			var bg = colorFor( 'background' );
			if ( bg ) {
				wrap.appendChild( h( 'span', { class: 'ypt-lo-preview__fill', style: { background: bg } } ) );
			}
			if ( s.artwork_url ) {
				wrap.appendChild( h( 'img', { src: s.artwork_url, alt: '', onload: function () {
					fit();
				} } ) );
			}
			var textColor = colorFor( 'text' );
			var nodes = [];
			s.field_schema.forEach( function ( f ) {
				if ( ( f.type !== 'text' && f.type !== 'textarea' ) || f.show_in_preview === false || ! f.position ) {
					return;
				}
				var el = h( 'div', { class: 'ypt-lo-preview__f' + ( f.type === 'textarea' ? ' is-multi' : '' ), style: {
					left: f.position.x + '%',
					top: f.position.y + '%',
					transform: 'translate(' + ( f.alignment === 'left' ? '0%' : f.alignment === 'right' ? '-100%' : '-50%' ) + ', -50%)',
					textAlign: f.alignment || 'center',
					color: textColor || f.text_color || '#000000',
					textTransform: { uppercase: 'uppercase', lowercase: 'lowercase', capitalize: 'capitalize' }[ f.formatting_rule ] || 'none',
				} } );
				el.fieldDef = f;
				nodes.push( el );
				wrap.appendChild( el );
			} );

			function fit() {
				var w = wrap.clientWidth;
				if ( ! w ) {
					return;
				}
				var scale = w / LABEL_STAGE_REF;
				nodes.forEach( function ( el ) {
					var f = el.fieldDef;
					el.textContent = r.values[ f.id ] || '';
					var max = Math.max( 6, ( +f.font_size_max || 24 ) * scale );
					var min = Math.max( 5, ( +f.font_size_min || 10 ) * scale );
					var size = max;
					el.style.maxWidth = ( w * 0.86 ) + 'px';
					el.style.fontSize = size + 'px';
					while ( size > min && el.scrollWidth > w * 0.86 + 1 ) {
						size -= 0.5;
						el.style.fontSize = size + 'px';
					}
				} );
			}
			wrap.refresh = fit;
			return wrap;
		}

		/* Step 3: every label, filled in and editable, then add to cart. */
		function stepReview() {
			var s = o.schema;
			var list = picked();
			var perRow = rowFields();
			var compoundF = findField( perRow, /compound|peptide|product name/i ) || perRow.filter( function ( f ) {
				return f.type === 'text' && f.required;
			} )[ 0 ] || null;
			var strengthF = findField( perRow, /strength|dos(e|age)|\bmg\b/i );
			if ( strengthF === compoundF ) {
				strengthF = null;
			}
			var previews = [];

			list.forEach( function ( r ) {
				if ( ! r.values ) {
					r.values = {};
					if ( compoundF ) {
						r.values[ compoundF.id ] = r.compound;
					}
					if ( strengthF ) {
						r.values[ strengthF.id ] = r.strength;
					}
				}
			} );

			body.appendChild( h( 'p', { class: 'ypt-muted ypt-small ypt-lo-lead' }, [ o.title, nameOf( s.sizes, o.sizeId ), nameOf( s.materials, o.materialId ) ].filter( Boolean ).join( ' · ' ) ) );

			list.forEach( function ( r ) {
				var preview = labelPreview( r );
				previews.push( preview );
				var main = [];
				var extra = [];
				perRow.forEach( function ( f ) {
					var always = f === compoundF || f === strengthF || f.required;
					var id = 'ypt-lo-' + labelKey( r.key ) + '-' + f.id;
					var attrs = { class: f.type === 'textarea' ? 'ypt-textarea' : 'ypt-input', id: id, value: r.values[ f.id ] || '', maxlength: String( f.max_chars || 40 ), placeholder: f.type === 'qr_code' ? 'https://' : '', oninput: function () {
						r.values[ f.id ] = this.value;
						preview.refresh();
					} };
					if ( f.type === 'qr_code' ) {
						attrs.type = 'url';
						attrs.inputmode = 'url';
					}
					var control = h( f.type === 'textarea' ? 'textarea' : 'input', attrs );
					( always || r.values[ f.id ] ? main : extra ).push( field( f.label + ( f.required ? '' : ' (optional)' ), control, null, id ) );
				} );
				var more = extra.length ? h( 'div', { hidden: true }, extra ) : null;
				var moreBtn = extra.length ? h( 'button', { type: 'button', class: 'ypt-link ypt-lo-more', onclick: function () {
					more.hidden = false;
					this.hidden = true;
				} }, '+ More details (' + extra.map( function ( node ) {
					return node.querySelector( 'label' ).textContent.replace( ' (optional)', '' );
				} ).join( ', ' ) + ')' ) : null;

				body.appendChild( h( 'div', { class: 'ypt-card ypt-lo-label' },
					h( 'div', { class: 'ypt-lo-label__head' },
						h( 'b', null, r.compound ),
						h( 'span', { class: 'ypt-muted ypt-small' }, r.qty + ' label' + ( r.qty === 1 ? '' : 's' ) )
					),
					preview,
					main,
					moreBtn,
					more
				) );
			} );

			var count = list.reduce( function ( n, r ) {
				return n + r.qty;
			}, 0 );
			var total = h( 'b', null, '' );
			body.appendChild( h( 'div', { class: 'ypt-lo-total' }, h( 'span', { class: 'ypt-muted' }, count + ' labels' ), total ) );

			var confirmBox = h( 'input', { type: 'checkbox', id: 'ypt-lo-confirm', checked: o.confirmed, onchange: function () {
				o.confirmed = this.checked;
				err.hidden = true;
			} } );
			body.appendChild( h( 'label', { class: 'ypt-lo-confirm', for: 'ypt-lo-confirm' },
				confirmBox,
				h( 'span', null, h( 'b', null, 'I’ve double-checked my label details. ' ), 'Every label prints exactly as entered, so check each peptide name and strength.' )
			) );
			body.appendChild( err );

			var add = h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--accent ypt-btn--block', onclick: submit }, 'Add to cart' );
			foot.appendChild( h( 'button', { type: 'button', class: 'ypt-btn', onclick: function () {
				show( 2 );
			} }, 'Back' ) );
			foot.appendChild( add );

			// Layout exists once the sheet is on screen; the artwork's own onload refits too.
			window.requestAnimationFrame( function () {
				previews.forEach( function ( p ) {
					p.refresh();
				} );
			} );

			api( 'GET', 'pricing/calculate?quantity=' + count + '&size_id=' + o.sizeId + '&material_id=' + o.materialId ).then( function ( p ) {
				if ( p && p.total != null ) {
					total.textContent = '$' + ( +p.total ).toFixed( 2 );
					add.textContent = 'Add to cart · $' + ( +p.total ).toFixed( 2 );
				}
			} ).catch( function () {} );

			function problem() {
				for ( var i = 0; i < list.length; i++ ) {
					for ( var j = 0; j < perRow.length; j++ ) {
						var f = perRow[ j ];
						var v = String( list[ i ].values[ f.id ] || '' ).trim();
						if ( f.required && ! v ) {
							return 'Fill in ' + f.label + ' for ' + list[ i ].compound + '.';
						}
					}
				}
				return o.confirmed ? '' : 'Tick the box to confirm your label details.';
			}

			function submit() {
				err.hidden = true;
				var msg = problem();
				if ( msg ) {
					showError( msg );
					return;
				}
				var shared = {};
				sharedFields().forEach( function ( f ) {
					shared[ f.id ] = sharedValue( f );
				} );
				var variants = list.map( function ( r ) {
					var values = Object.assign( {}, shared );
					perRow.forEach( function ( f ) {
						values[ f.id ] = String( r.values[ f.id ] || '' ).trim();
					} );
					return { quantity: r.qty, values: values };
				} );
				var label = add.textContent;
				add.disabled = true;
				add.textContent = 'Adding…';
				api( 'POST', 'cart/add', { template_id: o.templateId, size_id: o.sizeId, material_id: o.materialId, variants: variants } ).then( function () {
					add.textContent = 'Opening your cart…';
					window.location.href = CFG.cartUrl;
				} ).catch( function ( e ) {
					add.disabled = false;
					add.textContent = label;
					if ( handleAuthError( e ) ) {
						return;
					}
					showError( isNetworkError( e ) ? 'You’re offline. Connect to the internet and try again.' : e.message );
				} );
			}
		}

		openSheet( 'Order labels', 'Step 1 of 3', body, foot );
		show( 1 );
	}

	/* ---------- Me ---------- */

	function renderMe() {
		var s = state.records.settings.me || {};
		var wrap = h( 'div', null );
		wrap.appendChild( h( 'header', { class: 'ypt-top' },
			h( 'div', null, h( 'div', { class: 'ypt-eyebrow' }, 'Signed in' ), h( 'h1', null, CFG.firstName || 'Me' ) )
		) );

		wrap.appendChild( h( 'div', { class: 'ypt-eyebrow ypt-section-label', id: 'ypt-reminders' }, 'Reminders' ) );
		wrap.appendChild( remindersCard( s ) );

		wrap.appendChild( h( 'div', { class: 'ypt-eyebrow ypt-section-label' }, 'Appearance' ) );
		wrap.appendChild( h( 'div', { class: 'ypt-card' },
			seg( [ [ 'auto', 'Automatic' ], [ 'light', 'Light' ], [ 'dark', 'Dark' ] ], themePref(), function ( v ) {
				saveJSON( THEME_KEY, v );
				applyTheme();
				render();
			} ),
			h( 'p', { class: 'ypt-muted ypt-small', style: { marginTop: '8px' } }, themePref() === 'auto' ? 'Matches your phone’s light or dark setting.' : 'Just on this device.' )
		) );

		wrap.appendChild( h( 'div', { class: 'ypt-eyebrow ypt-section-label' }, 'Help & feedback' ) );
		wrap.appendChild( h( 'div', { class: 'ypt-card ypt-list ypt-help' }, FEEDBACK_TYPES.map( function ( t ) {
			return h( 'button', { type: 'button', class: 'ypt-help__row', onclick: function () {
				openFeedbackSheet( t.v );
			} },
				h( 'span', { class: 'ypt-help__ic ypt-help__ic--' + t.v }, icon( t.v ) ),
				h( 'span', { class: 'ypt-help__text' }, h( 'b', null, t.title ), h( 'span', null, t.sub ) ),
				h( 'span', { class: 'ypt-help__chev', 'aria-hidden': 'true' }, '›' )
			);
		} ) ) );

		if ( newsList().length ) {
			wrap.appendChild( h( 'div', { class: 'ypt-eyebrow ypt-section-label' }, 'What’s new' ) );
			wrap.appendChild( h( 'div', { class: 'ypt-card ypt-list' },
				newsList().slice( 0, 2 ).map( newsItem ),
				newsList().length > 2 ? h( 'button', { type: 'button', class: 'ypt-list-row', onclick: openWhatsNew }, h( 'span', null, 'All updates' ), h( 'span', null, '›' ) ) : null
			) );
		}

		wrap.appendChild( h( 'div', { class: 'ypt-eyebrow ypt-section-label' }, 'Your peptides & medications' ) );
		var list = protocols();
		wrap.appendChild( h( 'div', { class: 'ypt-card ypt-list' },
			list.length ? list.map( function ( p ) {
				return h( 'button', { type: 'button', class: 'ypt-list-row', onclick: function () {
					openProtocolSheet( p );
				} },
					h( 'span', null, h( 'span', { style: { display: 'inline-block', width: '10px', height: '10px', borderRadius: '50%', background: protocolColor( p ), marginRight: '8px' } } ), p.compound ),
					h( 'span', null, p.paused ? 'Paused' : amountLabel( doseOn( p, todayStr() ), p.unit, p ) + ' · ' + scheduleLabel( p ) )
				);
			} ) : h( 'p', { class: 'ypt-list-row ypt-muted' }, 'None yet.' )
		) );

		if ( state.shares.length ) {
			wrap.appendChild( h( 'div', { class: 'ypt-eyebrow ypt-section-label' }, 'Shared links' ) );
			wrap.appendChild( h( 'div', { class: 'ypt-card ypt-list' },
				state.shares.map( function ( sh ) {
					return h( 'button', { type: 'button', class: 'ypt-list-row', onclick: function () {
						openSharedLinkSheet( sh );
					} }, h( 'span', null, sh.compound || 'Protocol' ), h( 'span', null, MONTHS[ parseDate( sh.created ).getMonth() ].slice( 0, 3 ) + ' ' + parseDate( sh.created ).getDate() + ' ›' ) );
				} )
			) );
		}

		wrap.appendChild( h( 'div', { class: 'ypt-eyebrow ypt-section-label' }, 'Tools' ) );
		wrap.appendChild( h( 'div', { class: 'ypt-card ypt-list' },
			! isStandalone() && installPrompt ? h( 'button', { type: 'button', class: 'ypt-list-row', onclick: promptInstall }, h( 'span', null, 'Install the app' ), h( 'span', null, '›' ) ) : null,
			! isStandalone() && isIOS() ? h( 'div', { class: 'ypt-list-row' }, h( 'span', null, 'Add to Home Screen' ), h( 'span', null, 'Share → Add to Home Screen' ) ) : null,
			h( 'a', { class: 'ypt-list-row', href: CFG.calculatorUrl }, h( 'span', null, 'Peptide & Hormone Calculator' ), h( 'span', null, '›' ) ),
			h( 'button', { type: 'button', class: 'ypt-list-row', onclick: openReportSheet }, h( 'span', null, 'Printable report (PDF)' ), h( 'span', null, '›' ) ),
			h( 'button', { type: 'button', class: 'ypt-list-row', onclick: exportCsv }, h( 'span', null, 'Export dose history (CSV)' ), h( 'span', null, '›' ) ),
			h( 'a', { class: 'ypt-list-row', href: CFG.homeUrl }, h( 'span', null, 'YeffoDesign.com' ), h( 'span', null, '›' ) )
		) );

		wrap.appendChild( h( 'div', { class: 'ypt-eyebrow ypt-section-label' }, 'Privacy' ) );
		wrap.appendChild( h( 'div', { class: 'ypt-card' },
			h( 'p', { class: 'ypt-small' }, 'Everything you enter is encrypted before it’s stored, with a key unique to your account. YeffoDesign staff can’t view your entries. Deleting your data erases it and your key for good.' ),
			h( 'div', { style: { display: 'flex', gap: '8px', marginTop: '12px', flexWrap: 'wrap' } },
				h( 'a', { class: 'ypt-btn', href: CFG.logoutUrl, onclick: function () {
					removeKey( STORE_KEY );
					removeKey( QUEUE_KEY );
					forgetNative();
				} }, 'Sign out' ),
				h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--danger', onclick: confirmDeleteAll }, 'Delete my data' )
			),
			CFG.deleteAccountUrl ? h( 'p', { class: 'ypt-muted ypt-small', style: { margin: '12px 0 0' } }, 'To close your YeffoDesign account and erase everything, ', h( 'a', { href: CFG.deleteAccountUrl }, 'delete your account' ), '.' ) : null
		) );

		wrap.appendChild( h( 'p', { class: 'ypt-muted ypt-small', style: { margin: '16px 4px 0' } },
			'YeffoHealth is not a healthcare provider and doesn’t give medical advice. It simply keeps track of the information that matters to you. Talk to a qualified healthcare provider before starting, changing or stopping any peptide, medication, dose or schedule.' ) );

		return wrap;
	}

	/* ---------- Help & feedback (includes/tracker/class-tracker-feedback.php) ---------- */

	var FEEDBACK_TYPES = [
		{ v: 'help', label: 'Help', title: 'Get help', sub: 'Ask a question about the tracker' },
		{ v: 'problem', label: 'Problem', title: 'Report a problem', sub: 'Something broken or looks wrong' },
		{ v: 'idea', label: 'Idea', title: 'Suggest an idea', sub: 'Tell us what would make it better' },
	];
	var MAX_SHOTS = 3;

	/** Phone, browser and app version, so problem reports say where they happened. No tracker data. */
	function deviceLine() {
		var ua = navigator.userAgent || '';
		var os = /iPhone/.test( ua ) ? 'iPhone' : /iPad/.test( ua ) || ( /Macintosh/.test( ua ) && navigator.maxTouchPoints > 1 ) ? 'iPad' : /Android/.test( ua ) ? 'Android' : /Mac OS X/.test( ua ) ? 'Mac' : /Windows/.test( ua ) ? 'Windows' : /Linux/.test( ua ) ? 'Linux' : 'Other';
		var br = /EdgA?\//.test( ua ) ? 'Edge' : /SamsungBrowser/.test( ua ) ? 'Samsung Internet' : /FxiOS|Firefox/.test( ua ) ? 'Firefox' : /CriOS|Chrome/.test( ua ) ? 'Chrome' : /Safari/.test( ua ) ? 'Safari' : 'Browser';
		if ( /; wv\)/.test( ua ) ) {
			br = 'Android app';
		}
		return [ os, br + ( isStandalone() ? ' (Home Screen)' : '' ), 'tracker ' + ( CFG.version || '' ), 'reminders ' + ( state.pushOnHere ? 'on' : 'off' ) ].join( ' · ' );
	}

	/** The opt-in "tracker setup": medications/schedules and reminder settings only. Never doses, notes, vials, stock or injection spots. */
	function setupLines() {
		var s = state.records.settings.me || {};
		var lines = protocols().map( function ( p ) {
			return [ p.compound, amountLabel( doseOn( p, todayStr() ), p.unit, p ) + ( stepsOf( p ).length ? ' (titration: ' + stepsLine( p ) + ')' : '' ), scheduleLabel( p ) + ( cycleOf( p ) ? ', ' + cycleLine( p ) : '' ) + ' ' + timesOf( p ).map( fmtTime ).join( ', ' ), p.route || '' ].filter( Boolean ).join( ' · ' ) + ( p.paused ? ' (paused)' : '' );
		} );
		lines.push( 'Reminders on this device: ' + ( state.pushOnHere ? 'on' : 'off' ) );
		lines.push( 'Show names in reminders: ' + ( s.reminderNames === false ? 'off' : 'on' ) );
		lines.push( 'Time zone: ' + ( s.baseTz || s.tz || ( Intl.DateTimeFormat().resolvedOptions().timeZone || '' ) ) + ( s.travel ? ' (travel mode)' : '' ) );
		return lines;
	}

	/** A picked image, shrunk to at most `maxPx` (1600) and re-encoded as JPEG (which also drops photo metadata such as location). */
	function shrinkImage( file, maxPx, quality ) {
		return new Promise( function ( resolve, reject ) {
			var url = URL.createObjectURL( file );
			var img = new Image();
			img.onload = function () {
				var scale = Math.min( 1, ( maxPx || 1600 ) / Math.max( img.naturalWidth, img.naturalHeight ) );
				var c = document.createElement( 'canvas' );
				c.width = Math.max( 1, Math.round( img.naturalWidth * scale ) );
				c.height = Math.max( 1, Math.round( img.naturalHeight * scale ) );
				var ctx = c.getContext( '2d' );
				ctx.fillStyle = '#fff';
				ctx.fillRect( 0, 0, c.width, c.height );
				ctx.drawImage( img, 0, 0, c.width, c.height );
				URL.revokeObjectURL( url );
				resolve( c.toDataURL( 'image/jpeg', quality || 0.82 ) );
			};
			img.onerror = function () {
				URL.revokeObjectURL( url );
				reject( new Error( 'That file isn’t an image we can read.' ) );
			};
			img.src = url;
		} );
	}

	function openFeedbackSheet( type ) {
		var f = { type: type || 'help', message: '', email: CFG.email || '', shots: [], setup: false };
		var err = h( 'p', { class: 'ypt-error', hidden: true, role: 'alert' } );
		var shotsBox = h( 'div', { class: 'ypt-shots' } );
		var setupBox = h( 'div', null );
		var picker = h( 'input', { type: 'file', accept: 'image/*', hidden: true, onchange: function () {
			var files = [].slice.call( picker.files || [], 0, MAX_SHOTS - f.shots.length );
			picker.value = '';
			Promise.all( files.map( function ( file ) {
				return shrinkImage( file );
			} ) ).then( function ( urls ) {
				f.shots = f.shots.concat( urls ).slice( 0, MAX_SHOTS );
				drawShots();
			} ).catch( function ( e ) {
				toast( e.message );
			} );
		} } );
		if ( MAX_SHOTS > 1 ) {
			picker.setAttribute( 'multiple', '' );
		}
		var hints = { help: 'What are you trying to do?', problem: 'What happened, and what did you expect to happen?', idea: 'What would make the tracker better for you?' };
		var msg = h( 'textarea', { class: 'ypt-textarea', id: 'ypt-fb-msg', rows: '4', maxlength: '4000', placeholder: hints[ f.type ], oninput: function () {
			f.message = msg.value;
		} } );
		var email = h( 'input', { class: 'ypt-input', id: 'ypt-fb-email', type: 'email', autocomplete: 'email', value: f.email, oninput: function () {
			f.email = email.value.trim();
		} } );

		function drawShots() {
			shotsBox.textContent = '';
			f.shots.forEach( function ( url, i ) {
				shotsBox.appendChild( h( 'div', { class: 'ypt-shot' },
					h( 'img', { src: url, alt: 'Screenshot ' + ( i + 1 ) } ),
					h( 'button', { type: 'button', class: 'ypt-shot__x', 'aria-label': 'Remove screenshot', onclick: function () {
						f.shots.splice( i, 1 );
						drawShots();
					} }, '×' )
				) );
			} );
			if ( f.shots.length < MAX_SHOTS ) {
				shotsBox.appendChild( h( 'button', { type: 'button', class: 'ypt-shot ypt-shot--add', 'aria-label': 'Add a screenshot', onclick: function () {
					picker.click();
				} }, '+' ) );
			}
		}

		function drawSetup() {
			setupBox.textContent = '';
			var sw = h( 'button', { type: 'button', class: 'ypt-switch', role: 'switch', 'aria-checked': f.setup ? 'true' : 'false', 'aria-label': 'Include my tracker setup', onclick: function () {
				f.setup = ! f.setup;
				drawSetup();
			} } );
			setupBox.appendChild( h( 'div', { class: 'ypt-toggle', style: { borderTop: '1px solid var(--ypt-line)', marginTop: '16px' } },
				h( 'div', null, h( 'b', null, 'Include my tracker setup' ), h( 'div', { class: 'ypt-muted ypt-small' }, f.setup ? 'Helps us fix problems faster.' : 'Helps us fix problems faster. Off by default.' ) ),
				sw
			) );
			if ( ! f.setup ) {
				setupBox.appendChild( h( 'p', { class: 'ypt-fb-privacy' }, icon( 'lock' ), h( 'span', null, 'We only get what you type, any screenshots, and your device type (phone, browser, app version). Your doses, medications and vials stay encrypted and are not sent.' ) ) );
				return;
			}
			var meds = setupLines();
			var protoLines = meds.slice( 0, meds.length - 3 );
			setupBox.appendChild( h( 'div', { class: 'ypt-label' }, 'This note will include' ) );
			setupBox.appendChild( h( 'div', { class: 'ypt-fb-incl' },
				h( 'div', null, h( 'span', { class: 'ypt-fb-incl__y' }, '✓' ), h( 'span', null, 'Your medications and schedules', protoLines.length ? protoLines.map( function ( l ) {
					return h( 'span', { class: 'ypt-muted', style: { display: 'block' } }, l );
				} ) : h( 'span', { class: 'ypt-muted', style: { display: 'block' } }, 'None yet' ) ) ),
				h( 'div', null, h( 'span', { class: 'ypt-fb-incl__y' }, '✓' ), h( 'span', null, 'Reminder settings and time zone' ) ),
				h( 'div', null, h( 'span', { class: 'ypt-fb-incl__y' }, '✓' ), h( 'span', null, 'Device and app version' ) ),
				h( 'div', { class: 'ypt-fb-incl__sep' }, h( 'span', { class: 'ypt-fb-incl__n' }, '✕' ), h( 'span', null, 'Dose history and notes' ) ),
				h( 'div', null, h( 'span', { class: 'ypt-fb-incl__n' }, '✕' ), h( 'span', null, 'Vials, stock and injection spots' ) )
			) );
			setupBox.appendChild( h( 'p', { class: 'ypt-hint' }, 'Only with this one note. It’s erased once we’ve sorted it out.' ) );
		}

		var send = h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--primary ypt-btn--block', onclick: function () {
			err.hidden = true;
			if ( ! f.message.trim() ) {
				err.textContent = 'Write a message first.';
				err.hidden = false;
				msg.focus();
				return;
			}
			if ( ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( f.email ) ) {
				err.textContent = 'Enter an email address we can reply to.';
				err.hidden = false;
				email.focus();
				return;
			}
			if ( state.offline ) {
				err.textContent = 'You’re offline. Connect to the internet to send this.';
				err.hidden = false;
				return;
			}
			send.disabled = true;
			send.textContent = 'Sending…';
			api( 'POST', 'tracker/feedback', {
				type: f.type,
				message: f.message.trim(),
				email: f.email,
				device: deviceLine(),
				setup: f.setup ? setupLines() : [],
				screenshots: f.shots,
			} ).then( function () {
				openFeedbackSent( f );
			} ).catch( function ( e ) {
				send.disabled = false;
				send.textContent = 'Send';
				err.textContent = e.message || 'Your note couldn’t be sent. Please try again.';
				err.hidden = false;
			} );
		} }, 'Send' );

		drawShots();
		drawSetup();
		openSheet( 'Send us a note', 'Help & feedback', [
			h( 'div', { class: 'ypt-field' }, h( 'span', { class: 'ypt-label' }, 'What’s this about?' ), seg( FEEDBACK_TYPES.map( function ( t ) {
				return [ t.v, t.label ];
			} ), f.type, function ( v ) {
				f.type = v;
				msg.setAttribute( 'placeholder', hints[ v ] );
			} ) ),
			field( 'Message', msg, null, 'ypt-fb-msg' ),
			h( 'div', { class: 'ypt-field' }, h( 'span', { class: 'ypt-label' }, 'Screenshot (optional)' ), shotsBox, picker ),
			field( 'Reply to', email, 'We answer by email, usually within a day.', 'ypt-fb-email' ),
			setupBox,
			err,
		], send );
	}

	function openFeedbackSent( f ) {
		var label = FEEDBACK_TYPES.filter( function ( t ) {
			return t.v === f.type;
		} )[ 0 ].label;
		var extras = [];
		if ( f.shots.length ) {
			extras.push( f.shots.length === 1 ? '1 screenshot' : f.shots.length + ' screenshots' );
		}
		extras.push( f.setup ? 'tracker setup included' : 'tracker setup not included' );
		openSheet( 'Thanks, we got it', 'Help & feedback', [
			h( 'div', { class: 'ypt-fb-sent' },
				h( 'div', { class: 'ypt-fb-sent__check' }, icon( 'check' ) ),
				h( 'p', { class: 'ypt-muted' }, 'We’ll reply to ', h( 'b', null, f.email ), ', usually within a day.' ),
				h( 'div', { class: 'ypt-card', style: { textAlign: 'left', marginTop: '14px' } },
					h( 'div', { class: 'ypt-eyebrow' }, label ),
					h( 'p', { style: { margin: '4px 0 0', whiteSpace: 'pre-line' } }, f.message.trim() ),
					h( 'p', { class: 'ypt-muted ypt-small', style: { margin: '6px 0 0' } }, extras.join( ' · ' ) )
				)
			),
		], h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--block', onclick: closeSheet }, 'Done' ) );
	}

	function remindersCard( s ) {
		var supported = NATIVE || ( 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window );
		var card = h( 'div', { class: 'ypt-card' } );
		var errBox = h( 'p', { class: 'ypt-error', hidden: true } );

		if ( ! supported ) {
			card.appendChild( h( 'p', { class: 'ypt-small' }, isIOS() && ! isStandalone()
				? 'On iPhone, reminders work once YeffoHealth is on your Home Screen. Tap the Share button, then “Add to Home Screen”, and open it from there.'
				: 'This browser doesn’t support reminders. Try Chrome, Safari (from the Home Screen) or Firefox.' ) );
			return card;
		}

		var on = state.pushOnHere === true;
		var sw = h( 'button', { type: 'button', class: 'ypt-switch', role: 'switch', 'aria-checked': on ? 'true' : 'false', 'aria-label': 'Reminders on this device' } );
		sw.addEventListener( 'click', function () {
			sw.disabled = true;
			errBox.hidden = true;
			( NATIVE ? ( on ? disableNative() : enableNative() ) : ( on ? disablePush() : enablePush() ) ).then( function () {
				render();
			} ).catch( function ( e ) {
				sw.disabled = false;
				errBox.textContent = e.message || 'Couldn’t change reminders.';
				errBox.hidden = false;
			} );
		} );

		card.appendChild( h( 'div', { class: 'ypt-toggle' },
			h( 'div', null, h( 'b', null, 'Remind me on this device' ), h( 'div', { class: 'ypt-muted ypt-small' }, on ? 'You’ll get a notification when each dose is due.' : 'Get a notification when each dose is due.' ) ),
			sw
		) );
		if ( on && NATIVE && ! state.exactHere ) {
			card.appendChild( h( 'div', { class: 'ypt-banner ypt-banner--warn', style: { marginTop: '12px' } },
				h( 'div', null, h( 'b', null, 'Reminders may come late.' ), ' Allow “Alarms & reminders” for Dose Tracker so each one arrives right on time.',
					h( 'div', { style: { marginTop: '8px' } }, h( 'button', { type: 'button', class: 'ypt-btn', onclick: function () {
						allowExactNative().catch( function () {} );
					} }, 'Allow' ) ) )
			) );
		}
		if ( on ) {
			card.appendChild( h( 'button', { type: 'button', class: 'ypt-link', onclick: function () {
				( NATIVE ? testNative() : api( 'POST', 'tracker/push/test' ) ).then( function () {
					toast( 'Test reminder sent' );
				} ).catch( function ( e ) {
					toast( e.message || 'Couldn’t send a test.' );
				} );
			} }, 'Send a test reminder' ) );
		}
		if ( on || state.push.devices > 0 ) {
			// Lock-screen privacy: names show by default (Jeff), and can be hidden.
			var names = s.reminderNames !== false;
			var namesSw = h( 'button', { type: 'button', class: 'ypt-switch', role: 'switch', 'aria-checked': names ? 'true' : 'false', 'aria-label': 'Show names in reminders' } );
			namesSw.addEventListener( 'click', function () {
				put( 'settings', 'me', Object.assign( {}, state.records.settings.me || {}, { reminderNames: ! names } ) );
			} );
			card.appendChild( h( 'div', { class: 'ypt-toggle', style: { marginTop: '12px' } },
				h( 'div', null, h( 'b', null, 'Show names in reminders' ), h( 'div', { class: 'ypt-muted ypt-small' }, names ? 'Reminders say what’s due, like “Time for BPC-157”. Anyone who sees your lock screen can read them.' : 'Reminders just say a dose is due, without naming it.' ) ),
				namesSw
			) );
		}
		// The app's reminders live on the phone, so they aren't one of the server's push devices.
		var mine = on && ! NATIVE ? 1 : 0;
		if ( state.push.devices > mine ) {
			card.appendChild( h( 'p', { class: 'ypt-muted ypt-small', style: { marginTop: '8px' } }, 'Also on for ' + ( state.push.devices - mine ) + ' other device' + ( state.push.devices - mine === 1 ? '' : 's' ) + '.' ) );
		}
		if ( s.baseTz || s.tz ) {
			card.appendChild( h( 'p', { class: 'ypt-muted ypt-small', style: { marginTop: '8px' } }, s.travel
				? 'Easing from ' + tzCity( s.baseTz ) + ' to ' + tzCity( s.travel.tz ) + ' time.'
				: 'Times are in ' + String( s.baseTz || s.tz ).replace( /_/g, ' ' ) + ' time. When you travel, we’ll ask whether to move them.' ) );
		}
		card.appendChild( errBox );
		return card;
	}

	/* =========================================================
	 * Push
	 * ======================================================= */

	function b64ToBytes( b64 ) {
		var s = b64.replace( /-/g, '+' ).replace( /_/g, '/' );
		s += '===='.slice( ( s.length % 4 ) || 4 );
		var bin = atob( s );
		var out = new Uint8Array( bin.length );
		for ( var i = 0; i < bin.length; i++ ) {
			out[ i ] = bin.charCodeAt( i );
		}
		return out;
	}

	function swReady() {
		return navigator.serviceWorker.ready;
	}

	function checkPushHere() {
		if ( NATIVE || ! ( 'serviceWorker' in navigator ) || ! ( 'PushManager' in window ) ) {
			return;
		}
		swReady().then( function ( reg ) {
			return reg.pushManager.getSubscription();
		} ).then( function ( sub ) {
			var was = state.pushOnHere;
			state.pushOnHere = !! sub && Notification.permission === 'granted';
			if ( was !== state.pushOnHere ) {
				render();
			}
		} ).catch( function () {} );
	}

	function enablePush() {
		if ( ! state.push.publicKey ) {
			return Promise.reject( new Error( 'Reminders aren’t available right now.' ) );
		}
		return Notification.requestPermission().then( function ( perm ) {
			if ( perm !== 'granted' ) {
				throw new Error( 'Notifications are blocked. Allow them for this site in your settings, then try again.' );
			}
			return swReady();
		} ).then( function ( reg ) {
			return reg.pushManager.getSubscription().then( function ( existing ) {
				return existing || reg.pushManager.subscribe( { userVisibleOnly: true, applicationServerKey: b64ToBytes( state.push.publicKey ) } );
			} );
		} ).then( function ( sub ) {
			return api( 'POST', 'tracker/push', sub.toJSON() );
		} ).then( function () {
			state.pushOnHere = true;
			state.push.devices = ( state.push.devices || 0 ) + 1;
			var s = state.records.settings.me || {};
			if ( s.reminders === false ) {
				put( 'settings', 'me', Object.assign( {}, s, { reminders: true } ) );
			}
			toast( 'Reminders are on' );
		} );
	}

	function disablePush() {
		return swReady().then( function ( reg ) {
			return reg.pushManager.getSubscription();
		} ).then( function ( sub ) {
			if ( ! sub ) {
				return null;
			}
			var endpoint = sub.endpoint;
			return sub.unsubscribe().then( function () {
				return api( 'DELETE', 'tracker/push', { endpoint: endpoint } );
			} );
		} ).then( function () {
			state.pushOnHere = false;
			state.push.devices = Math.max( 0, ( state.push.devices || 1 ) - 1 );
		} );
	}

	/* =========================================================
	 * Android app (tracker-android/ in the repo, built with Capacitor)
	 *
	 * The app is this same page in an Android WebView with Capacitor's
	 * bridge added. A WebView can't do Web Push, so in the app reminders
	 * are scheduled on the phone itself: the next NATIVE_DAYS of doses
	 * and supply alerts, worked out here (same wording as
	 * class-tracker-reminders.php) and replaced whenever anything
	 * changes or the app opens. Nothing goes through a push server.
	 * Plain bridge calls, no @capacitor/core bundle, so this file stays
	 * build-free.
	 * ======================================================= */

	var NATIVE = !! ( window.Capacitor && typeof window.Capacitor.isNativePlatform === 'function' && window.Capacitor.isNativePlatform() && typeof window.Capacitor.nativePromise === 'function' );
	var NATIVE_KEY = 'ypt-native-reminders:' + ( CFG.userKey || 'anon' );
	var NATIVE_DAYS = 14;
	// Android limits how many alarms one app can have waiting (about 500).
	var NATIVE_MAX = 150;
	var NATIVE_CHANNEL = 'yp-reminders';
	// Snoozed reminders waiting on this phone ({slots, at, n}, at in seconds like the server's).
	var NATIVE_SNOOZE_KEY = 'ypt-native-snooze:' + ( CFG.userKey || 'anon' );
	var NATIVE_SNOOZE_MINUTES = 30;
	var NATIVE_MAX_SNOOZES = 6;
	var nativeChecked = false;
	var nativeLast = '';
	var nativeTimer = null;

	function nativeCall( method, options ) {
		return window.Capacitor.nativePromise( 'LocalNotifications', method, options || {} );
	}

	/** A stable positive 31-bit id per reminder tag (Android notification ids are ints). */
	function nativeId( tag ) {
		var n = 0;
		for ( var i = 0; i < tag.length; i++ ) {
			n = ( ( n * 31 ) + tag.charCodeAt( i ) ) | 0;
		}
		return ( n & 0x7fffffff ) || 1;
	}

	function nativeChannel() {
		return nativeCall( 'createChannel', { id: NATIVE_CHANNEL, name: 'Dose reminders', description: 'When a dose is due, and before you run low', importance: 4, vibration: true } );
	}

	/** @return {Array<{tag:string,at:number,title:string,body:string}>} Upcoming reminders, soonest first. */
	function nativeReminderList() {
		var s = state.records.settings.me || {};
		if ( s.reminders === false ) {
			return [];
		}
		var names = s.reminderNames !== false;
		var now = Date.now();
		var today = todayStr();
		var out = [];
		for ( var i = 0; i < NATIVE_DAYS; i++ ) {
			var date = addDays( today, i );
			var slots = {};
			slotsOn( date ).forEach( function ( sl ) {
				if ( sl.log ) {
					return;
				}
				var key = date + 'T' + sl.time;
				var p = sl.protocol;
				var slot = slots[ key ] || ( slots[ key ] = { at: Date.parse( wallToIso( date, sl.time ) ), key: key.replace( /[-:]/g, '' ), names: [], lines: [] } );
				// Titration: that day's dose, as the server's reminders word it.
				var dose = doseOn( p, date );
				slot.names.push( p.compound );
				slot.lines.push( ( p.compound + ' ' + ( dose > 0 ? amountLabel( dose, p.unit, p ) + ( p.doseOf ? ' ' + p.doseOf : '' ) : '' ) ).trim() );
			} );
			Object.keys( slots ).forEach( function ( key ) {
				var slot = slots[ key ];
				if ( slot.at <= now ) {
					return;
				}
				var one = slot.lines.length === 1;
				out.push( {
					tag: 'yp-dose-' + slot.key,
					at: slot.at,
					title: names ? ( one ? 'Time for ' + slot.names[ 0 ] : 'Time for your doses' ) : 'Dose reminder',
					body: names ? slot.lines.join( ' + ' ) : ( one ? 'You have a dose due. Open your tracker to see it.' : 'You have ' + slot.lines.length + ' doses due. Open your tracker to see them.' ),
				} );
			} );
		}
		nativeSnoozes().forEach( function ( z ) {
			var due = slotsOn( today ).filter( function ( sl ) {
				return ! sl.log && z.slots.indexOf( sl.id ) !== -1;
			} );
			if ( ! due.length || z.at * 1000 <= now ) {
				return;
			}
			var one = due.length === 1;
			out.push( {
				tag: 'yp-snooze-' + z.slots.join( '+' ) + '-' + z.n,
				at: z.at * 1000,
				title: names ? ( one ? 'Time for ' + due[ 0 ].protocol.compound : 'Time for your doses' ) : 'Dose reminder',
				body: names ? due.map( function ( sl ) {
					var dose = doseOn( sl.protocol, today );
					return ( sl.protocol.compound + ' ' + ( dose > 0 ? amountLabel( dose, sl.protocol.unit, sl.protocol ) + ( sl.protocol.doseOf ? ' ' + sl.protocol.doseOf : '' ) : '' ) ).trim();
				} ).join( ' + ' ) : ( one ? 'You have a dose due. Open your tracker to see it.' : 'You have ' + due.length + ' doses due. Open your tracker to see them.' ),
			} );
		} );
		supplyAlerts().forEach( function ( a ) {
			var at = Date.parse( wallToIso( a.date, a.time ) );
			if ( at <= now ) {
				return;
			}
			var mix = a.id.indexOf( 'mix-' ) === 0;
			var exp = a.id.indexOf( 'exp-' ) === 0;
			out.push( {
				tag: 'yp-supply-' + a.id,
				at: at,
				title: names ? a.title : ( exp ? 'A vial is about to expire' : mix ? 'Time to mix a new vial' : 'Your supply is running low' ),
				body: names ? a.body : ( exp ? 'Open the tracker to see which one.' : mix ? 'Your next dose needs a new vial or pen.' : 'Open the tracker to see what to reorder.' ),
			} );
		} );
		return out.sort( function ( a, b ) {
			return a.at - b.at;
		} ).slice( 0, NATIVE_MAX );
	}

	/** Swaps every waiting reminder for `list`. Exact times only when the phone allows them, so a reschedule never opens Settings. */
	function nativeReplace( list ) {
		var exact = false;
		return nativeCall( 'checkExactNotificationSetting' ).then( function ( r ) {
			exact = !! r && r.exact_alarm === 'granted';
			return nativeCall( 'cancelAll' );
		} ).then( function () {
			if ( ! list.length ) {
				return null;
			}
			return nativeCall( 'schedule', { notifications: list.map( function ( r ) {
				return {
					id: nativeId( r.tag ),
					title: r.title,
					body: r.body,
					channelId: NATIVE_CHANNEL,
					smallIcon: 'ic_stat_reminder',
					iconColor: '#EC008C',
					isExactNotification: exact,
					schedule: { at: new Date( r.at ).toISOString(), allowWhileIdle: true },
				};
			} ) } );
		} );
	}

	/** Today's snoozes that haven't come due yet (older ones are dropped as they're read). */
	function nativeSnoozes() {
		var now = Date.now();
		var list = loadJSON( NATIVE_SNOOZE_KEY, [] );
		return ( Array.isArray( list ) ? list : [] ).filter( function ( z ) {
			return z && Array.isArray( z.slots ) && z.at * 1000 > now && z.at * 1000 - now <= NATIVE_SNOOZE_MINUTES * 60000;
		} );
	}

	/** "Remind me in 30 min" in the app: one more reminder for that dose, scheduled on the phone. */
	function snoozeNative( slotIdStr ) {
		var list = loadJSON( NATIVE_SNOOZE_KEY, [] );
		list = Array.isArray( list ) ? list : [];
		var n = list.filter( function ( z ) {
			return z && Array.isArray( z.slots ) && z.slots.indexOf( slotIdStr ) !== -1;
		} ).reduce( function ( max, z ) {
			return Math.max( max, z.n || 0 );
		}, 0 );
		if ( n >= NATIVE_MAX_SNOOZES ) {
			return;
		}
		list = list.filter( function ( z ) {
			return z && Array.isArray( z.slots ) && z.slots.indexOf( slotIdStr ) === -1 && z.at * 1000 > Date.now();
		} );
		list.push( { slots: [ slotIdStr ], at: Math.floor( Date.now() / 1000 ) + NATIVE_SNOOZE_MINUTES * 60, n: n + 1 } );
		saveJSON( NATIVE_SNOOZE_KEY, list );
		nativeLast = '';
		syncNativeReminders();
	}

	function syncNativeReminders() {
		if ( ! NATIVE || ! nativeChecked || ! state.loaded ) {
			return;
		}
		clearTimeout( nativeTimer );
		nativeTimer = setTimeout( function () {
			var list = state.pushOnHere ? nativeReminderList() : [];
			var sig = JSON.stringify( list );
			if ( sig === nativeLast ) {
				return;
			}
			nativeLast = sig;
			nativeReplace( list ).catch( function () {
				nativeLast = '';
			} );
		}, 600 );
	}

	function checkNativeHere() {
		if ( ! loadJSON( NATIVE_KEY, false ) ) {
			state.pushOnHere = false;
			nativeChecked = true;
			syncNativeReminders();
			return;
		}
		Promise.all( [ nativeCall( 'checkPermissions' ), nativeCall( 'checkExactNotificationSetting' ), nativeChannel() ] ).then( function ( r ) {
			state.pushOnHere = !! r[ 0 ] && r[ 0 ].display === 'granted';
			state.exactHere = !! r[ 1 ] && r[ 1 ].exact_alarm === 'granted';
		} ).catch( function () {
			state.pushOnHere = false;
		} ).then( function () {
			nativeChecked = true;
			nativeLast = '';
			render();
			syncNativeReminders();
		} );
	}

	function enableNative() {
		return nativeCall( 'requestPermissions' ).then( function ( p ) {
			if ( ! p || p.display !== 'granted' ) {
				throw new Error( 'Notifications are off for Dose Tracker. Turn them on in your phone’s Settings under Apps, then try again.' );
			}
			return Promise.all( [ nativeChannel(), nativeCall( 'checkExactNotificationSetting' ) ] );
		} ).then( function ( r ) {
			// Android 12+ may need "Alarms & reminders" allowed too, or a
			// reminder can arrive long after its time. Asked once here; the
			// Me tab keeps offering it while it's off.
			return r[ 1 ] && r[ 1 ].exact_alarm === 'granted' ? r[ 1 ] : nativeCall( 'changeExactNotificationSetting' ).catch( function () {
				return null;
			} );
		} ).then( function ( exact ) {
			saveJSON( NATIVE_KEY, true );
			state.pushOnHere = true;
			state.exactHere = !! exact && exact.exact_alarm === 'granted';
			nativeChecked = true;
			nativeLast = '';
			var s = state.records.settings.me || {};
			if ( s.reminders === false ) {
				put( 'settings', 'me', Object.assign( {}, s, { reminders: true } ) );
			}
			toast( 'Reminders are on' );
		} );
	}

	function disableNative() {
		removeKey( NATIVE_KEY );
		state.pushOnHere = false;
		nativeLast = '';
		return nativeReplace( [] );
	}

	/** Signing out: this account's reminders leave the phone with its saved copy. */
	function forgetNative() {
		if ( ! NATIVE ) {
			return;
		}
		removeKey( NATIVE_KEY );
		removeKey( NATIVE_SNOOZE_KEY );
		state.pushOnHere = false;
		nativeLast = '';
		nativeCall( 'cancelAll' ).catch( function () {} );
	}

	function testNative() {
		return nativeCall( 'schedule', { notifications: [ {
			id: nativeId( 'yp-test' ),
			title: 'Test reminder',
			body: 'Reminders are working on this phone.',
			channelId: NATIVE_CHANNEL,
			smallIcon: 'ic_stat_reminder',
			iconColor: '#EC008C',
			isExactNotification: false,
			schedule: { at: new Date( Date.now() + 3000 ).toISOString(), allowWhileIdle: true },
		} ] } );
	}

	/** Without "Alarms & reminders" allowed (off by default from Android 14), Android can hold a reminder back well past its time. */
	function allowExactNative() {
		return nativeCall( 'changeExactNotificationSetting' ).then( function ( r ) {
			state.exactHere = !! r && r.exact_alarm === 'granted';
			nativeLast = '';
			render();
		} );
	}

	/* =========================================================
	 * Sheets
	 * ======================================================= */

	function openSheet( title, eyebrow, body, foot, onClose ) {
		var backdrop = h( 'div', { class: 'ypt-sheet-backdrop', onclick: function ( e ) {
			if ( e.target === backdrop ) {
				closeSheet();
			}
		} } );
		var sheet = h( 'div', { class: 'ypt-sheet', role: 'dialog', 'aria-modal': 'true', 'aria-label': title },
			h( 'div', { class: 'ypt-sheet__head' },
				h( 'div', null, eyebrow ? h( 'div', { class: 'ypt-eyebrow' }, eyebrow ) : null, h( 'h1', { style: { fontSize: '24px' } }, title ) ),
				h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--ghost', onclick: closeSheet }, 'Cancel' )
			),
			h( 'div', { class: 'ypt-sheet__body' }, body ),
			foot ? h( 'div', { class: 'ypt-sheet__foot' }, foot ) : null
		);
		backdrop.appendChild( sheet );
		ui.sheet = { node: backdrop, onClose: onClose };
		document.body.style.overflow = 'hidden';
		render();
		var first = sheet.querySelector( 'input, select, textarea' );
		if ( first && ! ( 'ontouchstart' in window ) ) {
			first.focus();
		}
	}

	function closeSheet() {
		ui.sheet = null;
		document.body.style.overflow = '';
		render();
	}

	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' && ui.sheet ) {
			closeSheet();
		}
	} );

	function field( label, control, hint, id ) {
		return h( 'div', { class: 'ypt-field' },
			h( 'label', { for: id || null }, label ),
			control,
			hint ? h( 'p', { class: 'ypt-hint' }, hint ) : null
		);
	}

	function seg( options, current, onPick, cls ) {
		var node = h( 'div', { class: 'ypt-seg' + ( cls ? ' ' + cls : '' ), role: 'group' } );
		options.forEach( function ( o ) {
			var val = typeof o === 'string' ? o : o[ 0 ];
			var label = typeof o === 'string' ? o : o[ 1 ];
			node.appendChild( h( 'button', { type: 'button', 'aria-pressed': val === current ? 'true' : 'false', onclick: function () {
				[].forEach.call( node.children, function ( b ) {
					b.setAttribute( 'aria-pressed', 'false' );
				} );
				this.setAttribute( 'aria-pressed', 'true' );
				onPick( val );
			} }, label ) );
		} );
		return node;
	}

	/**
	 * Free-text name with suggestions: the Compound List's peptides and
	 * hormones, common medications and supplements, and anything the
	 * customer already uses. Picking a medication passes its usual route
	 * as onChange's second argument.
	 */
	function compoundInput( id, value, onChange, placeholder ) {
		var input = h( 'input', { class: 'ypt-input', id: id, type: 'text', value: value || '', autocomplete: 'off', autocapitalize: 'words', spellcheck: 'false', placeholder: placeholder || 'e.g. BPC-157 or Metformin', maxlength: '80' } );
		var box = h( 'div', { class: 'ypt-suggest', hidden: true } );
		var names = ( CFG.compounds || [] ).slice();
		var routes = {};
		function add( name, route ) {
			if ( name && ! names.some( function ( n ) {
				return sameCompound( n, name );
			} ) ) {
				names.push( name );
				if ( route ) {
					routes[ name ] = route;
				}
			}
		}
		protocols().forEach( function ( x ) {
			add( x.compound, x.route );
		} );
		vials( true ).forEach( function ( x ) {
			add( x.compound );
		} );
		( CFG.medications || [] ).forEach( function ( m ) {
			add( m.n, m.r );
		} );

		function norm( s ) {
			return String( s ).toLowerCase().replace( /[^a-z0-9]/g, '' );
		}

		function update() {
			var q = norm( input.value );
			box.textContent = '';
			if ( ! q ) {
				box.hidden = true;
				return;
			}
			var hits = names.filter( function ( n ) {
				return norm( n ).indexOf( q ) !== -1 && norm( n ) !== q;
			} ).sort( function ( a, b ) {
				return norm( a ).indexOf( q ) - norm( b ).indexOf( q ) || a.length - b.length;
			} ).slice( 0, 6 );
			hits.forEach( function ( n ) {
				box.appendChild( h( 'button', { type: 'button', onmousedown: function ( e ) {
					e.preventDefault();
				}, onclick: function () {
					input.value = n;
					box.hidden = true;
					onChange( n, routes[ n ] );
				} }, n ) );
			} );
			box.hidden = ! hits.length;
		}

		input.addEventListener( 'input', function () {
			onChange( input.value.trim() );
			update();
		} );
		input.addEventListener( 'blur', function () {
			setTimeout( function () {
				box.hidden = true;
			}, 150 );
		} );
		return h( 'div', null, input, box );
	}

	/* ---------- Add / edit a peptide or medication ---------- */

	/**
	 * Add / edit a peptide or medication. `draft` ({ p, id }) reopens the sheet with
	 * what was already typed, after a detour to Mix a vial.
	 */
	function openProtocolSheet( existing, draft ) {
		var defaults = {
			compound: '',
			dose: '',
			unit: 'mcg',
			route: 'Subcutaneous',
			schedule: { type: 'daily', days: [ parseDate( todayStr() ).getDay() ], every: 2, on: 5, off: 2 },
			times: [ '09:00' ],
			start: todayStr(),
			weeks: '',
			color: COLORS[ protocols().length % COLORS.length ],
			notes: '',
			paused: false,
		};
		var p = existing ? JSON.parse( JSON.stringify( existing ) ) : defaults;
		if ( draft && draft.p ) {
			p = Object.assign( {}, p, draft.p );
		}
		p.schedule = Object.assign( { type: 'daily', days: [ parseDate( todayStr() ).getDay() ], every: 2, on: 5, off: 2 }, p.schedule || {} );
		// Cycle planner: weeks on/off and titration steps, each behind its own switch.
		var useCycle = !! cycleOf( p );
		var useSteps = stepsOf( p ).length > 0;
		p.cycle = cycleOf( p ) || { on: 8, off: 4 };
		p.steps = stepsOf( p );
		var gen = { by: '', every: 4, upTo: '' };
		var planField = h( 'div', { class: 'ypt-field ypt-cplan' } );
		var planPreview = h( 'div', { class: 'ypt-cplan__preview' } );
		var id = draft && draft.id ? draft.id : existing ? existing.id : uid( 'p' );
		var err = h( 'p', { class: 'ypt-error', hidden: true } );
		var vialBox = h( 'div', null );
		var schedDetail = h( 'div', null );
		var timesBox = h( 'div', { class: 'ypt-times' } );
		var drawBox = h( 'div', null );
		var doseOfBox = h( 'div', null );
		var unitBox = h( 'div', { style: { flex: '1 1 auto', minWidth: '0' } } );
		var strengthBox = h( 'div', null );
		var routeSelect;
		var vialField = h( 'div', { class: 'ypt-field' }, h( 'span', { class: 'ypt-label' }, 'Vial' ), vialBox );
		var routeTouched = !! existing;
		var deviceField = h( 'div', { class: 'ypt-field' } );
		var sitesField = h( 'div', { class: 'ypt-field' } );
		var unitTouched = !! existing || !! ( draft && draft.p && draft.p.unit );

		function setRoute( route ) {
			p.route = route;
			var units = routeInfo( route ).units;
			// Until a unit's been picked, follow the route's usual one (tablets for oral, sprays for nasal…).
			if ( ! unitTouched || units.indexOf( p.unit ) === -1 ) {
				p.unit = units[ 0 ];
			}
			if ( routeSelect ) {
				routeSelect.value = route;
			}
			renderDevice();
			renderSites();
			renderUnits();
			renderVial();
			renderDraw();
		}

		/** Injection site rotation: on/off, and which spots to rotate through. */
		function renderSites() {
			sitesField.textContent = '';
			sitesField.hidden = ! isInjected( p.route );
			if ( sitesField.hidden ) {
				return;
			}
			var sw = h( 'button', { type: 'button', class: 'ypt-switch', role: 'switch', 'aria-checked': p.siteOff ? 'false' : 'true', 'aria-label': 'Suggest where to inject next', onclick: function () {
				p.siteOff = ! p.siteOff;
				renderSites();
			} } );
			sitesField.appendChild( h( 'div', { class: 'ypt-toggle' },
				h( 'div', null, h( 'b', null, 'Rotate injection sites' ), h( 'div', { class: 'ypt-muted ypt-small' }, 'Suggests the spot that has rested longest and remembers where each dose went.' ) ),
				sw ) );
			if ( p.siteOff ) {
				return;
			}
			var on = rotationOf( p ).map( function ( x ) {
				return x.id;
			} );
			sitesField.appendChild( h( 'p', { class: 'ypt-hint', style: { marginTop: '10px' } }, 'Tap to choose the spots you use (' + on.length + ' of ' + sitesForRoute( p.route ).length + ').' ) );
			sitesField.appendChild( bodyMap( { sites: sitesForRoute( p.route ), selected: on, multi: true, onPick: function ( id ) {
				var i = on.indexOf( id );
				if ( i === -1 ) {
					on.push( id );
				} else if ( on.length > 1 ) {
					on.splice( i, 1 );
				}
				p.sites = on;
				renderSites();
			} } ) );
		}

		function renderDevice() {
			deviceField.textContent = '';
			deviceField.hidden = ! isInjected( p.route );
			if ( deviceField.hidden ) {
				return;
			}
			deviceField.appendChild( h( 'span', { class: 'ypt-label' }, 'Injected with' ) );
			deviceField.appendChild( seg( DEVICES, p.device || 'syringe', function ( d ) {
				p.device = d;
				renderDevice();
				renderUnits();
				renderVial();
				renderDraw();
			} ) );
		}

		function renderUnits() {
			unitBox.textContent = '';
			unitBox.appendChild( unitPicker( routeInfo( p.route ).units, p.unit, function ( u ) {
				p.unit = u;
				unitTouched = true;
				renderDraw();
				renderPlan();
				renderStrength();
			} ) );
			doseInput.placeholder = isInjected( p.route ) ? '250' : '1';
			renderStrength();
		}

		/** Tablets and capsules: how many is the dose above, so also ask the dosage on the bottle (500 mg). */
		function renderStrength() {
			strengthBox.textContent = '';
			if ( PILL_UNITS.indexOf( p.unit ) === -1 ) {
				return;
			}
			if ( STRENGTH_UNITS.indexOf( p.strengthUnit ) === -1 ) {
				p.strengthUnit = 'mg';
			}
			strengthBox.appendChild( h( 'label', { for: 'ypt-p-strength', class: 'ypt-label', style: { marginTop: '12px' } }, 'Dosage per ' + p.unit ) );
			strengthBox.appendChild( h( 'div', { class: 'ypt-row' },
				h( 'div', { style: { flex: '0 0 34%' } }, h( 'input', { class: 'ypt-input', id: 'ypt-p-strength', type: 'number', inputmode: 'decimal', min: '0', step: 'any', value: p.strength || '', placeholder: '500', oninput: function ( e ) {
					p.strength = e.target.value;
				} } ) ),
				h( 'div', { style: { flex: '1 1 auto', minWidth: '0' } }, seg( STRENGTH_UNITS.map( function ( u ) {
					return [ u, u ];
				} ), p.strengthUnit, function ( u ) {
					p.strengthUnit = u;
				} ) )
			) );
			strengthBox.appendChild( h( 'p', { class: 'ypt-hint' }, 'The strength printed on the bottle, like 500 mg. Leave it blank if you don’t know it.' ) );
		}

		function renderSchedDetail() {
			schedDetail.textContent = '';
			var s = p.schedule;
			if ( s.type === 'weekdays' ) {
				var chips = h( 'div', { class: 'ypt-chips', style: { marginTop: '8px' } } );
				[ 1, 2, 3, 4, 5, 6, 0 ].forEach( function ( d ) {
					chips.appendChild( h( 'button', { type: 'button', class: 'ypt-chip', 'aria-label': DOW_LONG[ d ], 'aria-pressed': s.days.map( Number ).indexOf( d ) !== -1 ? 'true' : 'false', onclick: function () {
						var i = s.days.map( Number ).indexOf( d );
						if ( i === -1 ) {
							s.days.push( d );
						} else {
							s.days.splice( i, 1 );
						}
						this.setAttribute( 'aria-pressed', i === -1 ? 'true' : 'false' );
					} }, DOW[ d ] ) );
				} );
				schedDetail.appendChild( chips );
			} else if ( s.type === 'interval' ) {
				schedDetail.appendChild( h( 'div', { class: 'ypt-row', style: { marginTop: '8px', alignItems: 'center' } },
					h( 'span', { class: 'ypt-shrink' }, 'Every' ),
					h( 'input', { class: 'ypt-input', type: 'number', inputmode: 'numeric', min: '2', max: '60', value: s.every, oninput: function ( e ) {
						s.every = parseInt( e.target.value, 10 ) || 2;
					} } ),
					h( 'span', { class: 'ypt-shrink' }, 'days' )
				) );
			} else if ( s.type === 'cycle' ) {
				schedDetail.appendChild( h( 'div', { class: 'ypt-row', style: { marginTop: '8px', alignItems: 'center' } },
					h( 'input', { class: 'ypt-input', type: 'number', inputmode: 'numeric', min: '1', max: '90', value: s.on, 'aria-label': 'Days on', oninput: function ( e ) {
						s.on = parseInt( e.target.value, 10 ) || 1;
					} } ),
					h( 'span', { class: 'ypt-shrink' }, 'days on,' ),
					h( 'input', { class: 'ypt-input', type: 'number', inputmode: 'numeric', min: '0', max: '90', value: s.off, 'aria-label': 'Days off', oninput: function ( e ) {
						s.off = parseInt( e.target.value, 10 ) || 0;
					} } ),
					h( 'span', { class: 'ypt-shrink' }, 'off' )
				) );
			}
		}

		function renderTimes() {
			timesBox.textContent = '';
			p.times.forEach( function ( t, i ) {
				timesBox.appendChild( h( 'div', { class: 'ypt-row' },
					h( 'input', { class: 'ypt-input', type: 'time', value: t, 'aria-label': 'Dose time ' + ( i + 1 ), onchange: function ( e ) {
						p.times[ i ] = e.target.value || '09:00';
					} } ),
					p.times.length > 1 ? h( 'button', { type: 'button', class: 'ypt-btn ypt-shrink', 'aria-label': 'Remove this time', onclick: function () {
						p.times.splice( i, 1 );
						renderTimes();
					} }, '×' ) : null
				) );
			} );
			if ( p.times.length < 6 ) {
				timesBox.appendChild( h( 'button', { type: 'button', class: 'ypt-link', style: { alignSelf: 'flex-start' }, onclick: function () {
					p.times.push( p.times.length ? '21:00' : '09:00' );
					renderTimes();
				} }, '+ Add another time' ) );
			}
		}

		// Step 1 of a new peptide: the vial, so the dose step can show units to draw.
		function renderVial() {
			vialBox.textContent = '';
			vialField.hidden = ! usesVial( p );
			if ( vialField.hidden ) {
				return;
			}
			var pen = p.device === 'pen';
			vialField.firstChild.textContent = pen ? 'Pen' : 'Vial';
			var v = p.compound ? currentVial( p ) : null;
			if ( v ) {
				var c = vialConcentration( v );
				vialBox.appendChild( h( 'div', { class: 'ypt-vialpick' },
					h( 'div', null,
						h( 'b', null, v.mode === 'conc' ? fmtNum( +v.conc ) + ' mg/mL · ' + fmtNum( +v.volume ) + ' mL' : isBlend( v ) ? blendSummary( v ) + ' · ' + fmtNum( +v.water ) + ' mL' : fmtNum( +v.amount ) + ( v.mode === 'iu' ? ' IU' : ' mg' ) + ' + ' + fmtNum( +v.water ) + ' mL water' ),
						h( 'span', null, 'Mixed ' + fmtDay( v.mixed ).replace( /^\w+, /, '' ) + ( c ? ' · ' + fmtNum( c.amount, 2 ) + ' ' + c.unit + '/mL' : '' ) )
					),
					h( 'button', { type: 'button', class: 'ypt-pill', onclick: detourToVial }, pen ? 'New pen' : 'New vial' )
				) );
				return;
			}
			vialBox.appendChild( h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--accent ypt-btn--block', style: { fontSize: '15px', padding: '13px' }, onclick: detourToVial }, pen ? 'Mix your pen first' : 'Mix your vial first' ) );
			vialBox.appendChild( h( 'p', { class: 'ypt-hint' }, pen ? 'Enter how much peptide went into the pen, and every dose will show the units to dial.' : 'Enter what’s in the vial and the water you added, and every dose will show the units to draw. Anything premeasured can skip this.' ) );
		}

		function detourToVial() {
			var draftP = JSON.parse( JSON.stringify( p ) );
			closeSheet();
			openVialSheet( null, {
				compound: p.compound,
				dose: p.dose,
				unit: p.unit,
				kind: p.device === 'pen' ? 'pen' : 'vial',
				onSaved: function ( vial ) {
					var next = Object.assign( draftP, { compound: draftP.compound || vial.compound } );
					if ( vial.mode === 'iu' && next.unit !== 'IU' && ! next.dose ) {
						next.unit = 'IU';
					}
					openProtocolSheet( existing, { p: next, id: id } );
				},
			} );
		}

		// A blend's dose is either of the whole blend or measured by one peptide in it.
		function renderDoseOf( v ) {
			doseOfBox.textContent = '';
			if ( ! isBlend( v ) ) {
				return;
			}
			if ( p.doseOf && ! blendPart( v, p.doseOf ) ) {
				p.doseOf = '';
			}
			doseOfBox.appendChild( h( 'div', { class: 'ypt-row', style: { alignItems: 'center', marginTop: '8px' } },
				h( 'span', { class: 'ypt-shrink ypt-small' }, 'That dose is' ),
				h( 'select', { class: 'ypt-select', id: 'ypt-p-doseof', 'aria-label': 'What the dose measures', onchange: function ( e ) {
					p.doseOf = e.target.value;
					renderDraw();
				} }, [ h( 'option', { value: '', selected: ! p.doseOf }, 'the whole blend' ) ].concat( v.parts.map( function ( x ) {
					return h( 'option', { value: x.name, selected: sameCompound( x.name, p.doseOf ) }, 'the ' + x.name + ' in it' );
				} ) ) )
			) );
		}

		function renderDraw() {
			drawBox.textContent = '';
			var v = p.compound ? currentVial( p ) : null;
			renderDoseOf( v );
			if ( ! v || ! ( parseFloat( p.dose ) > 0 ) ) {
				return;
			}
			var u = unitsForDose( p.dose, p.unit, v, p.doseOf );
			drawBox.appendChild( h( 'div', { class: 'ypt-draw' },
				u != null ? ( isPen( v ) ? [ 'Dial ', h( 'b', null, fmtNum( u, 1 ) + ' units' ), ' on your pen for each dose.' ] : [ 'Draw ', h( 'b', null, fmtNum( u, 1 ) + ' units' ), ' on a U-100 syringe for each dose.' ] )
					: 'Your ' + ( isPen( v ) ? 'pen' : 'vial' ) + ' is measured in ' + ( vialConcentration( v ) || {} ).unit + ', so pick that unit for your dose.',
				u != null && isBlend( v ) ? h( 'span', { class: 'ypt-draw__blend' }, 'Each dose: ' + blendDoseLine( v, u ) ) : null ) );
		}

		/** The protocol as typed so far, with the planner's switches applied: what the preview and Save use. */
		function planned() {
			return Object.assign( {}, p, {
				dose: parseFloat( p.dose ) || 0,
				cycle: useCycle && +p.cycle.on > 0 && +p.cycle.off > 0 ? { on: +p.cycle.on, off: +p.cycle.off } : null,
				steps: useSteps ? stepsOf( p ) : [],
			} );
		}

		function planSwitch( label, hint, on, onToggle ) {
			var sw = h( 'button', { type: 'button', class: 'ypt-switch', role: 'switch', 'aria-checked': on ? 'true' : 'false', 'aria-label': label, onclick: onToggle } );
			return h( 'div', { class: 'ypt-toggle' }, h( 'div', null, h( 'b', null, label ), h( 'div', { class: 'ypt-muted ypt-small' }, hint ) ), sw );
		}

		function smallNum( value, attrs, onInput ) {
			return h( 'input', Object.assign( { class: 'ypt-input ypt-input--sm', type: 'number', inputmode: 'decimal', step: 'any', value: value, oninput: function ( e ) {
				onInput( e.target.value );
				renderPlanPreview();
			} }, attrs ) );
		}

		// Fill in steps from a rule: "change by X every N weeks, up to Y".
		function generateSteps() {
			var by = parseFloat( gen.by );
			var every = Math.max( 1, parseInt( gen.every, 10 ) || 0 );
			var upTo = parseFloat( gen.upTo );
			var start = parseFloat( p.dose );
			if ( ! ( start > 0 ) || ! by ) {
				toast( ! ( start > 0 ) ? 'Enter your starting dose first.' : 'Enter how much to change the dose by.' );
				return;
			}
			var steps = [];
			var horizon = ( parseInt( p.weeks, 10 ) || 52 ) - 1;
			for ( var k = 1; k * every <= horizon && steps.length < 12; k++ ) {
				var d = Math.round( ( start + k * by ) * 10000 ) / 10000;
				var capped = upTo > 0 && ( by > 0 ? d >= upTo : d <= upTo );
				if ( capped ) {
					d = upTo;
				}
				if ( ! ( d > 0 ) ) {
					break;
				}
				steps.push( { week: k * every, dose: d } );
				if ( capped ) {
					break;
				}
			}
			p.steps = steps;
			renderPlan();
		}

		function renderPlan() {
			planField.textContent = '';
			planField.appendChild( h( 'span', { class: 'ypt-label' }, 'Cycle planner' ) );
			planField.appendChild( planSwitch( 'Weeks on, weeks off', 'Like 8 weeks on, 4 off. Off weeks drop off Today and reminders, then it starts again.', useCycle, function () {
				useCycle = ! useCycle;
				renderPlan();
			} ) );
			if ( useCycle ) {
				planField.appendChild( h( 'div', { class: 'ypt-row ypt-cplan__row' },
					smallNum( p.cycle.on, { min: '1', max: '52', 'aria-label': 'Weeks on' }, function ( v ) {
						p.cycle.on = parseInt( v, 10 ) || 0;
					} ),
					h( 'span', { class: 'ypt-shrink' }, 'weeks on,' ),
					smallNum( p.cycle.off, { min: '1', max: '52', 'aria-label': 'Weeks off' }, function ( v ) {
						p.cycle.off = parseInt( v, 10 ) || 0;
					} ),
					h( 'span', { class: 'ypt-shrink' }, 'off' )
				) );
			}
			planField.appendChild( planSwitch( 'Change the dose over time', 'Titration: raise (or lower) the dose on a schedule. Today shows the right dose each week.', useSteps, function () {
				useSteps = ! useSteps;
				renderPlan();
			} ) );
			if ( useSteps ) {
				var unit = unitLabel( p.unit, 2 );
				planField.appendChild( h( 'div', { class: 'ypt-cplan__gen' },
					h( 'div', { class: 'ypt-row ypt-cplan__row' },
						h( 'span', { class: 'ypt-shrink' }, 'Change by' ),
						smallNum( gen.by, { placeholder: '+0.25', 'aria-label': 'Change the dose by' }, function ( v ) {
							gen.by = v;
						} ),
						h( 'span', { class: 'ypt-shrink' }, unit + ' every' ),
						smallNum( gen.every, { min: '1', max: '52', 'aria-label': 'Every how many weeks' }, function ( v ) {
							gen.every = v;
						} ),
						h( 'span', { class: 'ypt-shrink' }, 'wks' )
					),
					h( 'div', { class: 'ypt-row ypt-cplan__row' },
						h( 'span', { class: 'ypt-shrink' }, 'up to' ),
						smallNum( gen.upTo, { min: '0', placeholder: 'max', 'aria-label': 'Up to (optional)' }, function ( v ) {
							gen.upTo = v;
						} ),
						h( 'span', { class: 'ypt-shrink' }, unit ),
						h( 'button', { type: 'button', class: 'ypt-btn ypt-shrink', onclick: generateSteps }, 'Fill in steps' )
					)
				) );
				var list = h( 'div', { class: 'ypt-cplan__steps' } );
				list.appendChild( h( 'div', { class: 'ypt-cplan__step ypt-muted' }, h( 'span', null, 'Week 1' ), h( 'b', null, ( parseFloat( p.dose ) > 0 ? fmtNum( parseFloat( p.dose ), 3 ) : '–' ) + ' ' + unit ), h( 'span', { class: 'ypt-small' }, 'starting dose' ) ) );
				p.steps.forEach( function ( s, i ) {
					list.appendChild( h( 'div', { class: 'ypt-cplan__step' },
						h( 'span', null, 'From week' ),
						smallNum( s.week + 1, { min: '2', max: '520', 'aria-label': 'From week' }, function ( v ) {
							s.week = Math.max( 1, ( parseInt( v, 10 ) || 2 ) - 1 );
						} ),
						smallNum( s.dose, { min: '0', 'aria-label': 'Dose from that week' }, function ( v ) {
							s.dose = parseFloat( v ) || 0;
						} ),
						h( 'span', { class: 'ypt-shrink' }, unit ),
						h( 'button', { type: 'button', class: 'ypt-btn ypt-shrink', 'aria-label': 'Remove this step', onclick: function () {
							p.steps.splice( i, 1 );
							renderPlan();
						} }, '×' )
					) );
				} );
				if ( p.steps.length < 24 ) {
					list.appendChild( h( 'button', { type: 'button', class: 'ypt-link', style: { alignSelf: 'flex-start' }, onclick: function () {
						var last = p.steps[ p.steps.length - 1 ];
						p.steps.push( { week: last ? last.week + 4 : 4, dose: last ? last.dose : parseFloat( p.dose ) || 0 } );
						renderPlan();
					} }, '+ Add a step' ) );
				}
				planField.appendChild( list );
			}
			planField.appendChild( planPreview );
			renderPlanPreview();
		}

		function renderPlanPreview() {
			planPreview.textContent = '';
			var pp = planned();
			if ( ( ! pp.cycle && ! pp.steps.length ) || ! ( pp.dose > 0 ) || ! pp.start ) {
				return;
			}
			planPreview.appendChild( cyclePreview( pp ) );
		}

		function saveProtocol() {
			var dose = parseFloat( p.dose );
			err.hidden = true;
			var problem = ! p.compound ? 'Enter what you’re taking.'
				: ! ( dose > 0 ) ? 'Enter your dose.'
				: p.schedule.type === 'weekdays' && ! p.schedule.days.length ? 'Pick at least one day.'
				: ! p.start ? 'Pick a start date.'
				: useCycle && ! ( +p.cycle.on > 0 && +p.cycle.off > 0 ) ? 'Enter the weeks on and off, or turn that off.'
				: useSteps && ! stepsOf( p ).length ? 'Add a dose step, or turn off “Change the dose over time”.'
				: '';
			if ( problem ) {
				err.textContent = problem;
				err.hidden = false;
				return;
			}
			var data = {
				compound: p.compound.trim(),
				dose: dose,
				unit: p.unit,
				route: p.route,
				device: isInjected( p.route ) ? p.device || 'syringe' : '',
				schedule: { type: p.schedule.type, days: p.schedule.days.map( Number ), every: +p.schedule.every || 2, on: +p.schedule.on || 5, off: +p.schedule.off || 0 },
				times: p.times.filter( Boolean ).filter( function ( t, i, a ) {
					return a.indexOf( t ) === i;
				} ).sort(),
				start: p.start,
				weeks: parseInt( p.weeks, 10 ) || 0,
				color: p.color,
				notes: p.notes || '',
				paused: !! p.paused,
				doseOf: usesVial( p ) && blendPart( currentVial( p ), p.doseOf ) ? p.doseOf : '',
				strength: strengthOf( p ) ? +p.strength : 0,
				strengthUnit: strengthOf( p ) ? p.strengthUnit : '',
				// Every spot for the route picked = no narrowing, so spots added to the map later join in.
				sites: isInjected( p.route ) && rotationOf( p ).length < sitesForRoute( p.route ).length ? rotationOf( p ).map( function ( x ) {
					return x.id;
				} ) : [],
				siteOff: isInjected( p.route ) && !! p.siteOff,
				cycle: planned().cycle,
				steps: planned().steps,
			};
			closeSheet();
			put( 'protocol', id, data );
			toast( existing ? 'Saved' : data.compound + ' added' );
		}

		var doseInput = h( 'input', { class: 'ypt-input', id: 'ypt-p-dose', type: 'number', inputmode: 'decimal', min: '0', step: 'any', value: p.dose, placeholder: '250', oninput: function ( e ) {
			p.dose = e.target.value;
			renderDraw();
			renderPlanPreview();
		} } );

		var body = [
			field( 'Peptide or medication', compoundInput( 'ypt-p-compound', p.compound, function ( v, route ) {
				p.compound = v;
				if ( route && ! routeTouched && route !== p.route ) {
					setRoute( route );
					return;
				}
				renderVial();
				renderDraw();
			} ), 'Pick a suggestion or type any name.', 'ypt-p-compound' ),
			field( 'How you take it', routeSelect = h( 'select', { class: 'ypt-select', id: 'ypt-p-route', onchange: function ( e ) {
				routeTouched = true;
				setRoute( e.target.value );
			} }, ROUTES.map( function ( r ) {
				return h( 'option', { value: r.v, selected: r.v === p.route }, r.label );
			} ).concat( ROUTES.some( function ( r ) {
				return r.v === p.route;
			} ) ? [] : [ h( 'option', { value: p.route, selected: true }, p.route ) ] ) ), null, 'ypt-p-route' ),
			deviceField,
			vialField,
			h( 'div', { class: 'ypt-field' },
				h( 'label', { for: 'ypt-p-dose' }, 'Dose' ),
				h( 'div', { class: 'ypt-row' }, h( 'div', { style: { flex: '0 0 34%' } }, doseInput ), unitBox ),
				strengthBox,
				doseOfBox,
				drawBox
			),
			h( 'div', { class: 'ypt-field' },
				h( 'span', { class: 'ypt-label' }, 'How often' ),
				seg( [ [ 'daily', 'Daily' ], [ 'weekdays', 'Days' ], [ 'interval', 'Every N' ], [ 'cycle', 'On / off' ] ], p.schedule.type, function ( t ) {
					p.schedule.type = t;
					renderSchedDetail();
				} ),
				schedDetail
			),
			h( 'div', { class: 'ypt-field' }, h( 'span', { class: 'ypt-label' }, 'Time' ), timesBox ),
			sitesField,
			h( 'div', { class: 'ypt-row' },
				field( 'Start', h( 'input', { class: 'ypt-input', id: 'ypt-p-start', type: 'date', value: p.start, onchange: function ( e ) {
					p.start = e.target.value;
					renderPlanPreview();
				} } ), null, 'ypt-p-start' ),
				field( 'Length (weeks)', h( 'input', { class: 'ypt-input', id: 'ypt-p-weeks', type: 'number', inputmode: 'numeric', min: '0', max: '520', placeholder: 'Ongoing', value: p.weeks || '', oninput: function ( e ) {
					p.weeks = e.target.value;
					renderPlanPreview();
				} } ), null, 'ypt-p-weeks' )
			),
			planField,
			h( 'div', { class: 'ypt-field' },
				h( 'span', { class: 'ypt-label' }, 'Color' ),
				h( 'div', { class: 'ypt-swatches' }, COLORS.map( function ( c ) {
					return h( 'button', { type: 'button', class: 'ypt-swatch', style: { background: c }, 'aria-label': 'Color ' + c, 'aria-pressed': c === p.color ? 'true' : 'false', onclick: function () {
						p.color = c;
						[].forEach.call( this.parentNode.children, function ( b ) {
							b.setAttribute( 'aria-pressed', 'false' );
						} );
						this.setAttribute( 'aria-pressed', 'true' );
					} } );
				} ) )
			),
			field( 'Notes', h( 'textarea', { class: 'ypt-textarea', id: 'ypt-p-notes', maxlength: '500', placeholder: 'With food, fasted, etc.', oninput: function ( e ) {
				p.notes = e.target.value;
			} }, p.notes || '' ), null, 'ypt-p-notes' ),
			existing ? h( 'button', { type: 'button', class: 'ypt-btn', style: { marginTop: '14px' }, onclick: function () {
				closeSheet();
				openShareSheet( Object.assign( { id: existing.id }, state.records.protocol[ existing.id ] || existing ) );
			} }, 'Share this protocol' ) : null,
			existing ? h( 'div', { class: 'ypt-toggle' },
				h( 'div', null, h( 'b', null, 'Pause' ), h( 'div', { class: 'ypt-muted ypt-small' }, 'Hide from Today and stop reminders. History is kept.' ) ),
				( function () {
					var sw = h( 'button', { type: 'button', class: 'ypt-switch', role: 'switch', 'aria-checked': p.paused ? 'true' : 'false', 'aria-label': 'Pause' } );
					sw.addEventListener( 'click', function () {
						p.paused = ! p.paused;
						sw.setAttribute( 'aria-checked', p.paused ? 'true' : 'false' );
					} );
					return sw;
				}() )
			) : null,
			existing ? h( 'button', { type: 'button', class: 'ypt-link', style: { color: 'var(--ypt-danger)', marginTop: '10px' }, onclick: function () {
				if ( window.confirm( 'Delete ' + existing.compound + '? Doses you already logged stay in your history.' ) ) {
					closeSheet();
					del( 'protocol', id );
				}
			} }, 'Delete ' + existing.compound ) : null,
			err,
		];

		renderSchedDetail();
		renderTimes();
		renderPlan();
		renderDevice();
		renderSites();
		renderUnits();
		renderVial();
		renderDraw();

		openSheet( existing ? 'Edit' : 'Add a peptide or medication', existing ? existing.compound : 'New protocol', body,
			h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--primary ypt-btn--block', onclick: function () {
				saveProtocol();
			} }, existing ? 'Save changes' : 'Save' ) );
	}

	/* ---------- Mix a vial or pen (the calculator) ---------- */

	/**
	 * Mix a vial or a multi-dose pen. A pen is a 3 mL cartridge mixed the
	 * same way, so it's a vial record with kind 'pen': the water (or
	 * premixed volume) defaults to 3 mL, there's no syringe to pick, and
	 * doses read as units to dial. `opts` (all optional): kind, compound, dose + unit to preview,
	 * onSaved(vial) — set when Add a peptide sent the customer here
	 * first, so saving goes straight back to it — preset (mode, amount,
	 * water, conc, volume, parts, blend: a mix to start from, e.g. the
	 * vial being replaced or a shared protocol's), and replaces (the id of
	 * a used-up vial to mark finished once this one's saved).
	 */
	function openVialSheet( existing, opts ) {
		opts = opts || {};
		var v = existing ? JSON.parse( JSON.stringify( existing ) ) : {
			kind: opts.kind === 'pen' ? 'pen' : 'vial',
			compound: opts.compound || '',
			mode: opts.unit === 'IU' ? 'iu' : 'mg',
			amount: '',
			water: opts.kind === 'pen' ? PEN_ML : '',
			conc: '',
			volume: opts.kind === 'pen' ? PEN_ML : '',
			mixed: todayStr(),
			syringe: 50,
			finished: false,
		};
		if ( ! existing && opts.preset ) {
			[ 'kind', 'mode', 'amount', 'water', 'conc', 'volume', 'parts', 'blend', 'syringe', 'goodFor' ].forEach( function ( k ) {
				if ( opts.preset[ k ] != null && opts.preset[ k ] !== '' ) {
					v[ k ] = JSON.parse( JSON.stringify( opts.preset[ k ] ) );
				}
			} );
		}
		v.kind = v.kind === 'pen' ? 'pen' : 'vial';
		v.parts = Array.isArray( v.parts ) && v.parts.length ? v.parts : [ { name: '', amount: '' }, { name: '', amount: '' } ];
		v.blend = v.blend === 'mixed' ? 'mixed' : 'bought';
		// A new blend's name follows its peptides ("BPC-157 + TB-500") until the customer types their own.
		var nameTouched = !! existing || !! v.compound;
		var nameLabel;
		var id = existing ? existing.id : uid( 'v' );
		var p = null;
		var calc = { dose: opts.dose || '', unit: opts.unit || 'mcg', doseOf: opts.doseOf || '' };
		if ( v.compound && ! opts.dose ) {
			p = protocols().filter( function ( x ) {
				return sameCompound( x.compound, v.compound );
			} )[ 0 ] || null;
			if ( p ) {
				calc = { dose: p.dose, unit: p.unit, doseOf: p.doseOf || '' };
			}
		}
		var err = h( 'p', { class: 'ypt-error', hidden: true } );
		var inputs = h( 'div', null );
		var strength = h( 'div', { 'aria-live': 'polite' } );
		var result = h( 'div', { class: 'ypt-calc-result', 'aria-live': 'polite' } );
		var calcOfBox = h( 'div', null );
		// New vials only: take one from the Supply tab's on-hand count.
		var stockBox = h( 'div', null );
		var takeStock = true;
		var takeWater = true;
		// Expiry countdown: how many days the mixed vial keeps.
		v.goodFor = vialGoodFor( v );
		var keepsBox = h( 'div', { class: 'ypt-field' } );

		function renderKeeps() {
			keepsBox.textContent = '';
			var pen = v.kind === 'pen';
			var exp = vialExpiry( v );
			var custom = [ 14, 21, 28, 42, 0 ].indexOf( +v.goodFor ) === -1;
			var num = h( 'input', { class: 'ypt-input ypt-input--sm', type: 'number', inputmode: 'numeric', min: '1', max: '365', value: custom ? v.goodFor : '', placeholder: 'days', 'aria-label': 'Days it keeps', oninput: function ( e ) {
				var n = parseInt( e.target.value, 10 );
				if ( n > 0 ) {
					v.goodFor = Math.min( 365, n );
					line.textContent = keepsLine();
					[].forEach.call( chipsBox.children, function ( b ) {
						b.setAttribute( 'aria-pressed', 'false' );
					} );
				}
			} } );
			function keepsLine() {
				var e = vialExpiry( v );
				return e ? 'Expires ' + fmtDay( e ).replace( /^\w+, /, '' ) + '. We’ll remind you ' + EXPIRY_NOTICE_DAYS + ' days before.' : 'No expiry countdown for this ' + ( pen ? 'pen' : 'vial' ) + '.';
			}
			var chipsBox = h( 'div', { class: 'ypt-chips' }, [ [ 14, '14 days' ], [ 21, '21' ], [ 28, '28' ], [ 42, '42' ], [ 0, 'Off' ] ].map( function ( c ) {
				return h( 'button', { type: 'button', class: 'ypt-chip ypt-chip--sm', 'aria-pressed': +v.goodFor === c[ 0 ] ? 'true' : 'false', onclick: function () {
					v.goodFor = c[ 0 ];
					renderKeeps();
				} }, c[ 1 ] );
			} ) );
			var line = h( 'p', { class: 'ypt-hint' }, keepsLine() );
			keepsBox.appendChild( h( 'span', { class: 'ypt-label' }, 'Good for (after ' + ( v.mode === 'conc' ? 'opening' : 'mixing' ) + ')' ) );
			keepsBox.appendChild( h( 'div', { class: 'ypt-row', style: { alignItems: 'center' } }, chipsBox, h( 'div', { class: 'ypt-shrink', style: { width: '76px' } }, num ) ) );
			keepsBox.appendChild( line );
			if ( exp ) {
				keepsBox.appendChild( h( 'p', { class: 'ypt-hint', style: { marginTop: '2px' } }, 'Go by the storage guidance for your peptide.' ) );
			}
		}

		function renderStockBox() {
			stockBox.textContent = '';
			var st = existing ? null : stockToMix( v );
			if ( ! st ) {
				renderWaterBox();
				return;
			}
			var on = h( 'button', { type: 'button', class: 'ypt-switch', role: 'switch', 'aria-checked': takeStock ? 'true' : 'false', 'aria-label': 'Take it from your supply' } );
			on.addEventListener( 'click', function () {
				takeStock = ! takeStock;
				on.setAttribute( 'aria-checked', takeStock ? 'true' : 'false' );
			} );
			var n = stockLeft( st );
			stockBox.appendChild( h( 'div', { class: 'ypt-toggle ypt-field' },
				h( 'div', null, h( 'b', null, 'Take it from your supply' ), h( 'div', { class: 'ypt-muted ypt-small' }, n + ' ' + stockSummary( st, n ) + ' on hand' ) ),
				on ) );
			renderWaterBox();
		}

		// New vials mixed with water: take the water off the bac water line too.
		function renderWaterBox() {
			var w = existing || v.mode === 'conc' ? null : waterToMix();
			if ( ! w ) {
				return;
			}
			var sw = h( 'button', { type: 'button', class: 'ypt-switch', role: 'switch', 'aria-checked': takeWater ? 'true' : 'false', 'aria-label': 'Use bac water from your supply' } );
			sw.addEventListener( 'click', function () {
				takeWater = ! takeWater;
				sw.setAttribute( 'aria-checked', takeWater ? 'true' : 'false' );
			} );
			stockBox.appendChild( h( 'div', { class: 'ypt-toggle ypt-field' },
				h( 'div', null, h( 'b', null, 'Use bac water from your supply' ), h( 'div', { class: 'ypt-muted ypt-small' }, fmtNum( stockLeft( w ), 1 ) + ' mL left' + ( +v.water > 0 ? ', ' + fmtNum( Math.max( 0, stockLeft( w ) - +v.water ), 1 ) + ' mL after this' : '' ) ) ),
				sw ) );
		}

		function numInput( id2, key, placeholder, target, onInput ) {
			return h( 'input', { class: 'ypt-input', id: id2, type: 'number', inputmode: 'decimal', min: '0', step: 'any', placeholder: placeholder, value: ( target || v )[ key ], oninput: function ( e ) {
				( target || v )[ key ] = e.target.value;
				if ( onInput ) {
					onInput();
				}
				renderResult();
			} } );
		}

		function chips( key, list, suffix ) {
			return h( 'div', { class: 'ypt-chips', style: { marginTop: '6px' } }, list.map( function ( n ) {
				return h( 'button', { type: 'button', class: 'ypt-chip ypt-chip--sm', onclick: function () {
					v[ key ] = n;
					var el = inputs.querySelector( '[data-key="' + key + '"]' );
					if ( el ) {
						el.value = n;
					}
					renderResult();
				} }, n + ' ' + suffix );
			} ) );
		}

		function blendParts() {
			return v.parts.filter( function ( x ) {
				return String( x.name || '' ).trim() && +x.amount > 0;
			} ).map( function ( x ) {
				return { name: String( x.name ).trim(), amount: +x.amount };
			} );
		}

		// Keep the blend's total (what the math reads) and default name in step with its rows.
		function syncBlend() {
			var list = blendParts();
			v.amount = list.reduce( function ( n, x ) {
				return n + x.amount;
			}, 0 ) || '';
			if ( ! nameTouched ) {
				v.compound = v.parts.map( function ( x ) {
					return String( x.name || '' ).trim();
				} ).filter( Boolean ).join( ' + ' );
				var el = document.getElementById( 'ypt-v-compound' );
				if ( el ) {
					el.value = v.compound;
				}
			}
		}

		function blendInputs( pen ) {
			var mixed = v.blend === 'mixed';
			var rowsBox = h( 'div', { class: 'ypt-blend' } );
			function renderRows() {
				rowsBox.textContent = '';
				v.parts.forEach( function ( x, i ) {
					var amt = h( 'input', { class: 'ypt-input', type: 'number', inputmode: 'decimal', min: '0', step: 'any', placeholder: '5', value: x.amount, 'aria-label': ( x.name || 'Peptide ' + ( i + 1 ) ) + ' mg', oninput: function ( e ) {
						x.amount = e.target.value;
						syncBlend();
						renderResult();
					} } );
					rowsBox.appendChild( h( 'div', { class: 'ypt-blend__row' },
						h( 'div', { class: 'ypt-blend__name' }, compoundInput( 'ypt-v-part-' + i, x.name, function ( name ) {
							x.name = name;
							syncBlend();
							renderResult();
						}, 'Peptide ' + ( i + 1 ) ) ),
						h( 'div', { class: 'ypt-blend__amt' }, amt, h( 'span', null, 'mg' ) ),
						v.parts.length > 2 ? h( 'button', { type: 'button', class: 'ypt-btn ypt-shrink', 'aria-label': 'Remove ' + ( x.name || 'this peptide' ), onclick: function () {
							v.parts.splice( i, 1 );
							syncBlend();
							renderRows();
							renderResult();
						} }, '×' ) : null
					) );
				} );
				if ( v.parts.length < 5 ) {
					rowsBox.appendChild( h( 'button', { type: 'button', class: 'ypt-link', style: { alignSelf: 'flex-start' }, onclick: function () {
						v.parts.push( { name: '', amount: '' } );
						renderRows();
						var el = document.getElementById( 'ypt-v-part-' + ( v.parts.length - 1 ) );
						if ( el ) {
							el.focus();
						}
					} }, '+ Add a peptide' ) );
				}
			}
			renderRows();
			inputs.appendChild( h( 'div', { class: 'ypt-field' },
				h( 'span', { class: 'ypt-label' }, 'How you got it' ),
				seg( [ [ 'bought', 'Bought blended' ], [ 'mixed', 'I mixed my own' ] ], v.blend, function ( b ) {
					v.blend = b;
					renderInputs();
					renderResult();
				} )
			) );
			inputs.appendChild( h( 'div', { class: 'ypt-field' },
				h( 'span', { class: 'ypt-label' }, mixed ? 'mg of each that went in' : 'Peptides in the ' + ( pen ? 'pen' : 'vial' ) ),
				rowsBox,
				h( 'p', { class: 'ypt-hint' }, mixed ? [ 'Used part of a vial? Enter just the mg you added. ', h( 'a', { href: CFG.calculatorUrl + '#blend-mix' }, 'The blend mixer' ), ' works out how much of each vial to combine.' ] : 'As printed on the label.' )
			) );
			var w = numInput( 'ypt-v-water', 'water', pen ? '3' : '2' );
			w.setAttribute( 'data-key', 'water' );
			inputs.appendChild( field( mixed ? 'Total liquid in the blend (mL)' : 'Bacteriostatic water added (mL)', w, mixed ? 'All the water from the vials you combined.' : pen ? 'Pens hold 3 mL. Change it only if you filled yours with less.' : null, 'ypt-v-water' ) );
			if ( ! pen && ! mixed ) {
				inputs.appendChild( chips( 'water', [ 1, 2, 2.5, 3 ], 'mL' ) );
			}
		}

		function renderInputs() {
			inputs.textContent = '';
			var amountUnit = v.mode === 'iu' ? 'IU' : 'mg';
			var pen = v.kind === 'pen';
			var where = pen ? 'pen' : 'vial';
			if ( nameLabel ) {
				nameLabel.textContent = v.mode === 'blend' ? 'Blend name' : 'Peptide';
			}
			if ( v.mode === 'blend' ) {
				blendInputs( pen );
			} else if ( v.mode === 'conc' ) {
				var c1 = numInput( 'ypt-v-conc', 'conc', '200' );
				c1.setAttribute( 'data-key', 'conc' );
				var c2 = numInput( 'ypt-v-volume', 'volume', '10' );
				c2.setAttribute( 'data-key', 'volume' );
				inputs.appendChild( h( 'div', { class: 'ypt-row' },
					field( 'Strength (mg/mL)', c1, 'Printed on the label', 'ypt-v-conc' ),
					field( pen ? 'Pen size (mL)' : 'Vial size (mL)', c2, null, 'ypt-v-volume' )
				) );
			} else {
				var a = numInput( 'ypt-v-amount', 'amount', v.mode === 'iu' ? '10' : '5' );
				a.setAttribute( 'data-key', 'amount' );
				var w = numInput( 'ypt-v-water', 'water', '2' );
				w.setAttribute( 'data-key', 'water' );
				inputs.appendChild( field( 'In the ' + where + ' (' + amountUnit + ')', a, null, 'ypt-v-amount' ) );
				inputs.appendChild( chips( 'amount', v.mode === 'iu' ? [ 5, 10, 12, 15, 36 ] : [ 2, 5, 10, 15 ], amountUnit ) );
				inputs.appendChild( field( 'Bacteriostatic water added (mL)', w, pen ? 'Pens hold 3 mL. Change it only if you filled yours with less.' : null, 'ypt-v-water' ) );
				if ( ! pen ) {
					inputs.appendChild( chips( 'water', [ 1, 2, 2.5, 3 ], 'mL' ) );
				}
			}
			inputs.appendChild( field( v.mode === 'conc' ? 'Opened on' : 'Mixed on', h( 'input', { class: 'ypt-input', id: 'ypt-v-mixed', type: 'date', value: v.mixed, max: todayStr(), onchange: function ( e ) {
				v.mixed = e.target.value || todayStr();
				renderKeeps();
			} } ), null, 'ypt-v-mixed' ) );
			inputs.appendChild( keepsBox );
			renderKeeps();
			inputs.appendChild( strength );
			var doseUnits = v.mode === 'iu' ? [ 'IU' ] : [ 'mcg', 'mg' ];
			if ( doseUnits.indexOf( calc.unit ) === -1 ) {
				calc.unit = doseUnits[ 0 ];
			}
			inputs.appendChild( h( 'div', { class: 'ypt-field' },
				h( 'label', { for: 'ypt-v-dose' }, 'Check a dose (optional)' ),
				h( 'div', { class: 'ypt-row' },
					h( 'div', { style: { flex: '0 0 40%' } }, numInput( 'ypt-v-dose', 'dose', v.mode === 'iu' ? '2' : '250', calc ) ),
					doseUnits.length > 1 ? seg( doseUnits, calc.unit, function ( u ) {
						calc.unit = u;
						renderResult();
					} ) : h( 'div', { class: 'ypt-seg' }, h( 'button', { type: 'button', 'aria-pressed': 'true' }, 'IU' ) )
				),
				calcOfBox,
				h( 'p', { class: 'ypt-hint' }, p ? 'From your ' + p.compound + ' schedule.' : 'See how many units that is. You’ll set your actual dose with your schedule.' )
			) );
			if ( ! pen ) {
				inputs.appendChild( h( 'div', { class: 'ypt-field' },
					h( 'span', { class: 'ypt-label' }, 'Syringe' ),
					seg( [ [ 30, '0.3 mL' ], [ 50, '0.5 mL' ], [ 100, '1 mL' ] ], +v.syringe || 50, function ( s ) {
						v.syringe = s;
						renderResult();
					} )
				) );
			}
		}

		// What the mix works out to — shown as soon as the vial is filled
		// in, before (and without) any dose.
		function renderStrength() {
			strength.textContent = '';
			var c = vialConcentration( v );
			var total = vialTotalUnits( v );
			if ( ! c ) {
				return;
			}
			strength.appendChild( h( 'div', { class: 'ypt-stats' },
				h( 'div', { class: 'ypt-stat' }, h( 'b', null, fmtNum( c.amount, 2 ) + ' ' + c.unit + '/mL' ), h( 'span', null, 'strength' ) ),
				h( 'div', { class: 'ypt-stat' }, h( 'b', null, c.unit === 'mg' ? fmtNum( c.amount * 1000 / 100, 1 ) + ' mcg' : fmtNum( c.amount / 100, 2 ) + ' IU' ), h( 'span', null, isPen( v ) ? 'per unit dialed' : 'per syringe unit' ) ),
				h( 'div', { class: 'ypt-stat' }, h( 'b', null, total ? fmtNum( total, 0 ) : '–' ), h( 'span', null, 'units in the ' + ( isPen( v ) ? 'pen' : 'vial' ) ) )
			) );
			if ( v.mode === 'blend' && +v.water > 0 ) {
				strength.appendChild( h( 'p', { class: 'ypt-hint' }, 'Per mL: ' + blendParts().map( function ( x ) {
					return fmtNum( x.amount / v.water, 2 ) + ' mg ' + x.name;
				} ).join( ' · ' ) ) );
			}
		}

		// Blends: whether the dose being checked is of the whole blend or one peptide in it.
		function renderCalcOf() {
			calcOfBox.textContent = '';
			var list = v.mode === 'blend' ? blendParts() : [];
			if ( ! list.length ) {
				return;
			}
			if ( calc.doseOf && ! list.some( function ( x ) {
				return sameCompound( x.name, calc.doseOf );
			} ) ) {
				calc.doseOf = '';
			}
			calcOfBox.appendChild( h( 'div', { class: 'ypt-row', style: { alignItems: 'center', marginTop: '8px' } },
				h( 'span', { class: 'ypt-shrink ypt-small' }, 'That dose is' ),
				h( 'select', { class: 'ypt-select', 'aria-label': 'What the dose measures', onchange: function ( e ) {
					calc.doseOf = e.target.value;
					renderResult();
				} }, [ h( 'option', { value: '', selected: ! calc.doseOf }, 'the whole blend' ) ].concat( list.map( function ( x ) {
					return h( 'option', { value: x.name, selected: sameCompound( x.name, calc.doseOf ) }, 'the ' + x.name + ' in it' );
				} ) ) )
			) );
		}

		function renderResult() {
			renderStrength();
			renderCalcOf();
			renderStockBox();
			result.textContent = '';
			var blendV = v.mode === 'blend' ? Object.assign( {}, v, { parts: blendParts() } ) : v;
			var units = unitsForDose( calc.dose, calc.unit, blendV, calc.doseOf );
			var c = vialConcentration( v );
			var total = vialTotalUnits( v );
			var cap = +v.syringe || 50;
			result.hidden = ! c || units == null;
			if ( result.hidden ) {
				return;
			}
			var pen = isPen( v );
			var over = ! pen && units > cap;
			result.appendChild( h( 'div', { class: 'ypt-eyebrow' }, pen ? 'Dial to' : 'Draw to' ) );
			result.appendChild( h( 'div', { class: 'ypt-calc-big' }, fmtNum( units, 1 ), h( 'small', null, 'units' ) ) );
			result.appendChild( h( 'p', { class: 'ypt-muted ypt-small', style: { marginTop: '4px' } }, fmtNum( units / 100, 3 ) + ( pen ? ' mL, on a pen that dials in units (0.01 mL each)' : ' mL on a U-100 insulin syringe' ) ) );
			if ( isBlend( blendV ) ) {
				result.appendChild( h( 'p', { class: 'ypt-small', style: { marginTop: '6px' } }, 'Each dose: ' + blendDoseLine( blendV, units ) ) );
			}
			if ( ! pen ) {
				result.appendChild( drawSyringe( units, cap ) );
			}
			if ( over ) {
				result.appendChild( h( 'div', { class: 'ypt-draw ypt-draw--warn' }, 'That’s more than a ' + ( cap / 100 ) + ' mL syringe holds. Pick a bigger syringe, or add less water for a stronger mix.' ) );
			} else if ( units < 2 ) {
				result.appendChild( h( 'div', { class: 'ypt-draw ypt-draw--warn' }, 'Under 2 units is hard to measure. Adding more water makes each dose easier to draw.' ) );
			}
			if ( total ) {
				result.appendChild( h( 'p', { class: 'ypt-muted ypt-small', style: { marginTop: '10px' } }, 'About ' + Math.floor( ( total + 0.0001 ) / units ) + ' doses from this ' + ( pen ? 'pen' : 'vial' ) + '.' ) );
			}
		}

		var body = [
			h( 'div', { class: 'ypt-field' },
				h( 'span', { class: 'ypt-label' }, 'Mixing' ),
				seg( [ [ 'vial', 'A vial' ], [ 'pen', 'A multi-dose pen' ] ], v.kind, function ( k ) {
					v.kind = k;
					// A pen is 3 mL; don't leave a vial's water amount behind, or a pen's in a vial.
					if ( k === 'pen' ) {
						v.water = v.water && +v.water !== 0 ? v.water : PEN_ML;
						v.volume = v.volume || PEN_ML;
					}
					renderInputs();
					renderResult();
				} )
			),
			h( 'div', { class: 'ypt-field' },
				h( 'span', { class: 'ypt-label' }, 'What’s in it' ),
				seg( [ [ 'mg', 'Peptide (mg)' ], [ 'iu', 'HGH / HCG (IU)' ], [ 'conc', 'Premixed' ], [ 'blend', 'Blend' ] ], v.mode, function ( m ) {
					var was = v.mode;
					v.mode = m;
					if ( m === 'blend' ) {
						syncBlend();
					} else if ( was === 'blend' ) {
						// Leaving a blend: don't keep its total as a single peptide's mg.
						v.amount = '';
					}
					if ( ( m === 'blend' || was === 'blend' ) && ! nameTouched ) {
						v.compound = m === 'blend' ? v.compound : '';
						var el = document.getElementById( 'ypt-v-compound' );
						if ( el ) {
							el.value = v.compound;
						}
					}
					renderInputs();
					renderResult();
				}, 'ypt-seg--wrap' )
			),
			( function () {
				var f = field( v.mode === 'blend' ? 'Blend name' : 'Peptide', compoundInput( 'ypt-v-compound', v.compound, function ( name ) {
					v.compound = name;
					nameTouched = !! name;
					renderStockBox();
					var match = protocols().filter( function ( x ) {
						return sameCompound( x.compound, name );
					} )[ 0 ];
					if ( match && ! opts.dose ) {
						p = match;
						calc.dose = match.dose;
						calc.unit = match.unit;
						calc.doseOf = match.doseOf || '';
						renderInputs();
						renderResult();
					}
				} ), null, 'ypt-v-compound' );
				nameLabel = f.firstChild;
				return f;
			}() ),
			inputs,
			result,
			stockBox,
			h( 'p', { class: 'ypt-hint' }, 'Same math as the ', h( 'a', { href: CFG.calculatorUrl }, 'Peptide & Hormone Calculator' ), '. Always double-check against your vial’s label.' ),
			existing ? h( 'button', { type: 'button', class: 'ypt-link', style: { color: 'var(--ypt-danger)', marginTop: '12px' }, onclick: function () {
				if ( window.confirm( 'Delete this ' + ( isPen( v ) ? 'pen' : 'vial' ) + '?' ) ) {
					closeSheet();
					del( 'vial', id );
				}
			} }, 'Delete this ' + ( isPen( v ) ? 'pen' : 'vial' ) ) : null,
			err,
		];

		renderInputs();
		renderResult();

		openSheet( existing ? ( isPen( v ) ? 'Edit pen' : 'Edit vial' ) : opts.title || ( opts.kind === 'pen' ? 'Mix a pen' : 'Mix a vial or pen' ), 'Vial & pen calculator', body,
			h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--primary ypt-btn--block', onclick: function () {
				err.hidden = true;
				var problem = v.mode === 'blend' && blendParts().length < 2 ? 'Add at least two peptides with their mg.'
					: ! v.compound ? ( v.mode === 'blend' ? 'Give the blend a name.' : 'Enter the peptide’s name.' )
					: ! vialConcentration( v ) || ! vialTotalUnits( v ) ? ( v.mode === 'conc' ? 'Enter the strength and size.' : 'Enter what’s in it and the water you added.' )
					: '';
				if ( problem ) {
					err.textContent = problem;
					err.hidden = false;
					return;
				}
				closeSheet();
				var saved = {
					kind: v.kind,
					compound: v.compound.trim(),
					mode: v.mode,
					amount: v.mode === 'conc' ? 0 : +v.amount,
					water: v.mode === 'conc' ? 0 : +v.water,
					conc: v.mode === 'conc' ? +v.conc : 0,
					volume: v.mode === 'conc' ? +v.volume : 0,
					mixed: v.mixed || todayStr(),
					syringe: +v.syringe || 50,
					finished: !! v.finished,
					goodFor: Math.max( 0, parseInt( v.goodFor, 10 ) || 0 ),
				};
				if ( v.mode === 'blend' ) {
					saved.parts = blendParts();
					saved.blend = v.blend;
				}
				var fromStock = ! existing && takeStock ? stockToMix( saved ) : null;
				put( 'vial', id, saved );
				if ( fromStock ) {
					put( 'stock', fromStock.id, Object.assign( {}, fromStock, { count: Math.max( 0, stockLeft( fromStock ) - 1 ) } ) );
				}
				var water = ! existing && takeWater && saved.water > 0 ? waterToMix() : null;
				if ( water ) {
					put( 'stock', water.id, Object.assign( {}, water, { ml: Math.max( 0, Math.round( ( stockLeft( water ) - saved.water ) * 10 ) / 10 ), countedAt: new Date().toISOString() } ) );
				}
				if ( opts.replaces && state.records.vial[ opts.replaces ] && ! state.records.vial[ opts.replaces ].finished ) {
					put( 'vial', opts.replaces, Object.assign( {}, state.records.vial[ opts.replaces ], { finished: true } ) );
				}
				if ( opts.onSaved ) {
					opts.onSaved( saved );
					toast( saved.kind === 'pen' ? 'Pen saved' : 'Vial saved' );
				} else if ( ! existing && ! protocols().some( function ( x ) {
					return sameCompound( x.compound, saved.compound );
				} ) ) {
					// Step 2 of first-time setup: straight on to the schedule.
					openProtocolSheet( null, { p: {
						compound: saved.compound,
						dose: parseFloat( calc.dose ) > 0 ? calc.dose : '',
						unit: saved.mode === 'iu' ? 'IU' : calc.unit,
						doseOf: saved.parts ? calc.doseOf || '' : '',
						device: saved.kind === 'pen' ? 'pen' : 'syringe',
					} } );
					toast( ( saved.kind === 'pen' ? 'Pen' : 'Vial' ) + ' saved. Now set your dose and schedule.' );
				} else {
					toast( saved.kind === 'pen' ? 'Pen saved' : 'Vial saved' );
				}
			} }, existing ? 'Save vial' : 'Save to my vials' ) );
	}

	function drawSyringe( units, cap ) {
		var BX = 50;
		var BW = 520;
		var BY = 26;
		var BH = 40;
		var s = svg( 'svg', { class: 'ypt-syringe', viewBox: '0 0 640 100', role: 'img', 'aria-label': units ? 'Syringe filled to ' + fmtNum( units, 1 ) + ' units' : 'Empty syringe' } );
		svg( 'rect', { x: 6, y: BY + BH / 2 - 1.2, width: 36, height: 2.4, rx: 1.2, class: 's-metal' }, s );
		svg( 'rect', { x: BX, y: BY, width: BW, height: BH, rx: 6, class: 's-barrel' }, s );
		var f = Math.max( 0, Math.min( units / cap, 1 ) );
		if ( f > 0 ) {
			svg( 'rect', { x: BX + 2, y: BY + 3, width: Math.max( 2, f * BW - 4 ), height: BH - 6, rx: 3, fill: units > cap ? '#F5B400' : '#00AEEF', opacity: '0.75' }, s );
		}
		var minor = cap === 100 ? 2 : 1;
		var labelEvery = cap === 100 ? 10 : 5;
		for ( var u = 0; u <= cap; u += minor ) {
			var x = BX + ( u / cap ) * BW;
			var major = u % labelEvery === 0;
			svg( 'line', { x1: x, x2: x, y1: BY, y2: BY + ( major ? 14 : 8 ), class: 's-tick' }, s );
			if ( major ) {
				svg( 'text', { x: x, y: BY - 7, class: 's-num' }, s ).textContent = u;
			}
		}
		svg( 'rect', { x: BX + BW + 8, y: BY - 6, width: 7, height: BH + 12, rx: 3, class: 's-metal' }, s );
		if ( f > 0 ) {
			var mx = BX + f * BW;
			svg( 'line', { x1: mx, x2: mx, y1: BY - 2, y2: BY + BH + 8, class: 's-mark' }, s );
			svg( 'rect', { x: mx - 30, y: BY + BH + 7, width: 60, height: 20, rx: 10, class: 's-flag' }, s );
			svg( 'text', { x: mx, y: BY + BH + 21, class: 's-flag-text' }, s ).textContent = units > cap ? cap + '+ u' : fmtNum( units, 1 ) + ' u';
		}
		return s;
	}

	/* ---------- Edit a logged dose ---------- */

	function openLogSheet( log ) {
		var d = JSON.parse( JSON.stringify( log ) );
		var id = log.id;
		var at = effDate( d.at ? Date.parse( d.at ) : Date.now() );
		var timeVal = pad( at.getHours() ) + ':' + pad( at.getMinutes() );
		var lp = d.protocolId ? state.records.protocol[ d.protocolId ] : null;
		d.tags = tagsOf( d );
		var siteField = d.site || tracksSites( lp ) ? h( 'div', { class: 'ypt-field' },
			h( 'span', { class: 'ypt-label' }, 'Injection site' ),
			sitePicker( lp && isInjected( lp.route ) ? lp : { route: 'Subcutaneous' }, d.site, id, function ( site ) {
				d.site = site;
			}, true )
		) : null;
		var body = [
			h( 'p', { class: 'ypt-muted' }, amountLabel( d.dose, d.unit, d ) + ' · ' + fmtDay( d.date ) ),
			h( 'div', { class: 'ypt-field' },
				h( 'span', { class: 'ypt-label' }, 'Status' ),
				seg( [ [ 'taken', 'Taken' ], [ 'skipped', 'Skipped' ] ], d.status, function ( s ) {
					d.status = s;
				} )
			),
			field( 'Time taken', h( 'input', { class: 'ypt-input', id: 'ypt-l-time', type: 'time', value: timeVal, onchange: function ( e ) {
				timeVal = e.target.value || timeVal;
			} } ), null, 'ypt-l-time' ),
			siteField,
			h( 'div', { class: 'ypt-field' }, h( 'span', { class: 'ypt-label' }, 'How did you feel?' ), tagPicker( d.tags ) ),
			field( 'Note', h( 'textarea', { class: 'ypt-textarea', id: 'ypt-l-note', maxlength: '500', placeholder: 'Anything else to remember…', oninput: function ( e ) {
				d.note = e.target.value;
			} }, d.note || '' ), null, 'ypt-l-note' ),
			h( 'button', { type: 'button', class: 'ypt-link', style: { color: 'var(--ypt-danger)', marginTop: '12px' }, onclick: function () {
				closeSheet();
				del( 'dose', id );
			} }, id.indexOf( 'x-' ) === 0 ? 'Delete this dose' : 'Undo (mark as not logged)' ),
		];
		openSheet( d.compound, 'Logged dose', body,
			h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--primary ypt-btn--block', onclick: function () {
				d.at = wallToIso( d.date, timeVal );
				delete d.id;
				if ( d.status === 'skipped' ) {
					d.units = null;
					d.site = '';
				}
				closeSheet();
				put( 'dose', id, d );
			} }, 'Save' ) );
	}

	/* ---------- How you felt: side effect and note tags on a dose ---------- */

	// tone: 'bad' (side effects), 'good', or 'mid' — only the chip color.
	var FEELINGS = [
		{ t: 'Nausea', tone: 'bad' },
		{ t: 'Headache', tone: 'bad' },
		{ t: 'Tired', tone: 'bad' },
		{ t: 'Dizzy', tone: 'bad' },
		{ t: 'Upset stomach', tone: 'bad' },
		{ t: 'Sore injection site', tone: 'bad' },
		{ t: 'Redness or bump', tone: 'bad' },
		{ t: 'Poor sleep', tone: 'bad' },
		{ t: 'Water retention', tone: 'bad' },
		{ t: 'Hungry', tone: 'mid' },
		{ t: 'Less hungry', tone: 'mid' },
		{ t: 'Slept well', tone: 'good' },
		{ t: 'More energy', tone: 'good' },
		{ t: 'Good mood', tone: 'good' },
		{ t: 'Felt great', tone: 'good' },
	];
	var MAX_CUSTOM_TAGS = 20;

	function tagsOf( d ) {
		return ( Array.isArray( d && d.tags ) ? d.tags : [] ).filter( function ( t ) {
			return typeof t === 'string' && t.trim();
		} );
	}

	function tagTone( t ) {
		var f = FEELINGS.filter( function ( x ) {
			return sameCompound( x.t, t );
		} )[ 0 ];
		return f ? f.tone : 'mid';
	}

	function tagChip( t ) {
		return h( 'span', { class: 'ypt-ftag ypt-ftag--' + tagTone( t ) }, t );
	}

	/** The customer's own tags (Me settings), kept so they're offered next time. */
	function customTags() {
		var s = state.records.settings.me || {};
		return ( Array.isArray( s.tags ) ? s.tags : [] ).filter( function ( t ) {
			return typeof t === 'string' && t.trim();
		} );
	}

	/** Tap-to-toggle feelings, plus "+ Your own". `selected` is the dose's tags array; it's changed in place. */
	function tagPicker( selected ) {
		var wrap = h( 'div', { class: 'ypt-tagpick' } );
		function has( t ) {
			return selected.some( function ( x ) {
				return sameCompound( x, t );
			} );
		}
		function toggle( t ) {
			var i = -1;
			selected.forEach( function ( x, k ) {
				if ( sameCompound( x, t ) ) {
					i = k;
				}
			} );
			if ( i === -1 ) {
				selected.push( t );
			} else {
				selected.splice( i, 1 );
			}
			draw();
		}
		function chip( t ) {
			return h( 'button', { type: 'button', class: 'ypt-chip ypt-chip--sm ypt-ftag-btn ypt-ftag-btn--' + tagTone( t ), 'aria-pressed': has( t ) ? 'true' : 'false', onclick: function () {
				toggle( t );
			} }, t );
		}
		var addBtn = h( 'button', { type: 'button', class: 'ypt-chip ypt-chip--sm ypt-chip--dashed', onclick: function () {
			var name = String( window.prompt( 'Add your own tag (for example “Joint pain”)' ) || '' ).trim().slice( 0, 30 );
			if ( ! name ) {
				return;
			}
			if ( ! has( name ) ) {
				selected.push( name );
			}
			var list = customTags();
			if ( ! FEELINGS.some( function ( x ) {
				return sameCompound( x.t, name );
			} ) && ! list.some( function ( x ) {
				return sameCompound( x, name );
			} ) ) {
				put( 'settings', 'me', Object.assign( {}, state.records.settings.me || {}, { tags: list.concat( [ name ] ).slice( -MAX_CUSTOM_TAGS ) } ) );
			}
			draw();
		} }, '+ Your own' );
		function draw() {
			wrap.textContent = '';
			var seen = [];
			var mine = customTags().concat( selected ).filter( function ( t ) {
				var dupe = FEELINGS.some( function ( x ) {
					return sameCompound( x.t, t );
				} ) || seen.some( function ( y ) {
					return sameCompound( y, t );
				} );
				seen.push( t );
				return ! dupe;
			} );
			wrap.appendChild( h( 'div', { class: 'ypt-tagpick__group' }, h( 'span', { class: 'ypt-tagpick__label' }, 'Side effects' ), h( 'div', { class: 'ypt-chips' }, FEELINGS.filter( function ( x ) {
				return x.tone === 'bad';
			} ).map( function ( x ) {
				return chip( x.t );
			} ) ) ) );
			wrap.appendChild( h( 'div', { class: 'ypt-tagpick__group' }, h( 'span', { class: 'ypt-tagpick__label' }, 'Other' ), h( 'div', { class: 'ypt-chips' }, FEELINGS.filter( function ( x ) {
				return x.tone !== 'bad';
			} ).map( function ( x ) {
				return chip( x.t );
			} ).concat( mine.map( chip ) ).concat( [ addBtn ] ) ) ) );
		}
		draw();
		return wrap;
	}

	/* ---------- Extra (unscheduled) dose ---------- */

	function openExtraSheet( date ) {
		var list = protocols();
		var d = { compound: list[ 0 ] ? list[ 0 ].compound : '', dose: list[ 0 ] ? doseOn( list[ 0 ], date ) : '', unit: list[ 0 ] ? list[ 0 ].unit : 'mcg', protocolId: list[ 0 ] ? list[ 0 ].id : '', note: '', tags: [] };
		var timeVal = date === todayStr() ? nowTime() : '09:00';
		var custom = ! list.length;
		var box = h( 'div', null );
		var err = h( 'p', { class: 'ypt-error', hidden: true } );

		function renderBox() {
			box.textContent = '';
			if ( custom ) {
				box.appendChild( field( 'Peptide or medication', compoundInput( 'ypt-x-compound', d.compound, function ( v ) {
					d.compound = v;
					d.protocolId = '';
				} ), null, 'ypt-x-compound' ) );
			}
			var picked = ! custom && d.protocolId ? state.records.protocol[ d.protocolId ] : null;
			box.appendChild( h( 'div', { class: 'ypt-field' },
				h( 'label', { for: 'ypt-x-dose' }, 'Dose' ),
				h( 'div', { class: 'ypt-row' },
					h( 'div', { style: { flex: '0 0 34%' } }, h( 'input', { class: 'ypt-input', id: 'ypt-x-dose', type: 'number', inputmode: 'decimal', min: '0', step: 'any', value: d.dose, oninput: function ( e ) {
						d.dose = e.target.value;
					} } ) ),
					h( 'div', { style: { flex: '1 1 auto', minWidth: '0' } }, unitPicker( picked ? routeInfo( picked.route ).units : UNITS, d.unit, function ( u ) {
						d.unit = u;
					} ) )
				)
			) );
			d.site = '';
			if ( tracksSites( picked ) ) {
				box.appendChild( h( 'div', { class: 'ypt-field' },
					h( 'span', { class: 'ypt-label' }, 'Injection site' ),
					sitePicker( picked, '', null, function ( site ) {
						d.site = site;
					} )
				) );
			}
		}

		var picker = list.length ? h( 'div', { class: 'ypt-field' },
			h( 'label', { for: 'ypt-x-which' }, 'Which one' ),
			h( 'select', { class: 'ypt-select', id: 'ypt-x-which', onchange: function ( e ) {
				if ( e.target.value === '__other' ) {
					custom = true;
					d.compound = '';
					d.protocolId = '';
				} else {
					var p = state.records.protocol[ e.target.value ];
					custom = false;
					d.protocolId = e.target.value;
					d.compound = p.compound;
					d.dose = doseOn( p, date );
					d.unit = p.unit;
				}
				renderBox();
			} }, list.map( function ( p ) {
				return h( 'option', { value: p.id }, p.compound );
			} ), h( 'option', { value: '__other' }, 'Something else…' ) )
		) : null;

		renderBox();
		openSheet( 'Log an extra dose', fmtDay( date ), [
			picker,
			box,
			field( 'Time', h( 'input', { class: 'ypt-input', id: 'ypt-x-time', type: 'time', value: timeVal, onchange: function ( e ) {
				timeVal = e.target.value || timeVal;
			} } ), null, 'ypt-x-time' ),
			h( 'div', { class: 'ypt-field' }, h( 'span', { class: 'ypt-label' }, 'How did you feel? (optional)' ), tagPicker( d.tags ) ),
			field( 'Note', h( 'textarea', { class: 'ypt-textarea', id: 'ypt-x-note', maxlength: '500', oninput: function ( e ) {
				d.note = e.target.value;
			} } ), null, 'ypt-x-note' ),
			err,
		], h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--primary ypt-btn--block', onclick: function () {
			if ( ! d.compound || ! ( parseFloat( d.dose ) > 0 ) ) {
				err.textContent = ! d.compound ? 'Enter what you took.' : 'Enter the dose.';
				err.hidden = false;
				return;
			}
			var p = d.protocolId ? state.records.protocol[ d.protocolId ] : null;
			var v = currentVial( { compound: d.compound } );
			var units = v ? unitsForDose( d.dose, d.unit, v, p ? p.doseOf : '' ) : null;
			var str = p && p.unit === d.unit ? strengthOf( p ) : null;
			closeSheet();
			put( 'dose', uid( 'x-' ), {
				protocolId: p ? d.protocolId : '',
				compound: d.compound.trim(),
				date: date,
				time: timeVal,
				status: 'taken',
				at: wallToIso( date, timeVal ),
				dose: parseFloat( d.dose ),
				unit: d.unit,
				strength: str ? str.amount : 0,
				strengthUnit: str ? str.unit : '',
				vialId: v ? v.id : '',
				units: units != null ? Math.round( units * 100 ) / 100 : null,
				note: d.note || '',
				site: d.site || '',
				tags: d.tags,
			} );
			toast( 'Extra dose logged' + ( d.site ? ' · ' + siteLabel( d.site ) : '' ) );
		} }, 'Log dose' ) );
	}

	/* ---------- Delete everything ---------- */

	function confirmDeleteAll() {
		var typed = '';
		var btn = h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--danger ypt-btn--block', disabled: true, onclick: function () {
			btn.disabled = true;
			api( 'DELETE', 'tracker/all' ).then( function () {
				return ( NATIVE ? disableNative() : disablePush() ).catch( function () {} );
			} ).then( function () {
				state.records = { protocol: {}, dose: {}, vial: {}, stock: {}, settings: {}, progress: {}, lab: {} };
				state.shares = [];
				forgetPhotos();
				queue = [];
				removeKey( STORE_KEY );
				removeKey( QUEUE_KEY );
				closeSheet();
				go( 'today' );
				toast( 'Your tracker data was deleted' );
			} ).catch( function ( e ) {
				btn.disabled = false;
				toast( e.message || 'Couldn’t delete. Check your connection.' );
			} );
		} }, 'Delete everything' );
		openSheet( 'Delete my data', 'This can’t be undone', [
			h( 'p', null, 'This permanently erases every peptide, medication, dose, vial, note and progress photo in your tracker, plus your encryption key. Your YeffoDesign account and orders aren’t affected.' ),
			field( 'Type DELETE to confirm', h( 'input', { class: 'ypt-input', id: 'ypt-del', type: 'text', autocapitalize: 'characters', autocomplete: 'off', oninput: function ( e ) {
				typed = e.target.value.trim().toUpperCase();
				btn.disabled = typed !== 'DELETE';
			} } ), null, 'ypt-del' ),
		], btn );
	}

	/* =========================================================
	 * Signed-out / not-ready screens
	 * ======================================================= */

	/* ---------- Calculator tab (also the signed-out home screen) ---------- */

	/*
	 * Jeff: one place for the calculator and the tracker, without making
	 * anyone sign in just to do the math. Signed out, /tracker/ opens on
	 * this screen with the other tabs locked; signed in it's a tab (Me
	 * moved to the avatar in the app bar). Same math as the Mix a vial
	 * sheet (see the top of this file). Nothing typed here is stored
	 * unless a signed-out visitor taps Save: then the numbers wait in
	 * CALC_DRAFT_KEY (no ypt: prefix, so signing in on wp-login.php
	 * doesn't clear them) and open as a new vial once they're signed in.
	 */
	var CALC_DRAFT_KEY = 'ypt-calc-draft';
	var CALC_DRAFT_DAYS = 7;
	var CALC_MODES = [ [ 'mg', 'Peptides' ], [ 'iu', 'HGH / HCG' ], [ 'hormone', 'Hormones' ], [ 'blend', 'Blends' ] ];

	function calcState() {
		if ( ! ui.calc ) {
			ui.calc = { mode: 'mg', kind: 'vial', compound: '', amount: '', water: '', conc: '', volume: '', per: 'week', weekly: '', perWeek: '2', dose: '', unit: 'mcg', doseOf: '', parts: [ { name: '', amount: '' }, { name: '', amount: '' } ], syringe: 50 };
		}
		return ui.calc;
	}

	function calcParts( c ) {
		return c.parts.filter( function ( x ) {
			return String( x.name || '' ).trim() && +x.amount > 0;
		} ).map( function ( x ) {
			return { name: String( x.name ).trim(), amount: +x.amount };
		} );
	}

	/** The calculator's inputs as a vial record, so vialConcentration() and friends read it. */
	function calcVial( c ) {
		if ( c.mode === 'hormone' ) {
			return { kind: c.kind, mode: 'conc', conc: c.conc, volume: c.volume };
		}
		if ( c.mode === 'blend' ) {
			var parts = calcParts( c );
			return { kind: c.kind, mode: 'blend', parts: parts, blend: 'bought', water: c.water, amount: parts.reduce( function ( n, x ) {
				return n + x.amount;
			}, 0 ) };
		}
		return { kind: c.kind, mode: c.mode, amount: c.amount, water: c.water };
	}

	/** One dose: { dose, unit }. Hormones can be entered per week and split into injections. */
	function calcDose( c ) {
		if ( c.mode === 'hormone' ) {
			var n = Math.round( +c.perWeek );
			if ( c.per === 'week' ) {
				return +c.weekly > 0 && n > 0 ? { dose: +c.weekly / n, unit: 'mg' } : { dose: '', unit: 'mg' };
			}
			return { dose: c.dose, unit: 'mg' };
		}
		return { dose: c.dose, unit: c.mode === 'iu' ? 'IU' : c.unit };
	}

	/** "Start from a vial I have": fill the calculator from a saved vial and its schedule. */
	function calcFromVial( v ) {
		var c = calcState();
		var p = protocols().filter( function ( x ) {
			return sameCompound( x.compound, v.compound );
		} )[ 0 ];
		c.kind = isPen( v ) ? 'pen' : 'vial';
		c.compound = v.compound || '';
		c.syringe = +v.syringe || 50;
		c.mode = v.mode === 'conc' ? 'hormone' : v.mode === 'iu' || v.mode === 'blend' ? v.mode : 'mg';
		c.amount = v.mode === 'conc' || v.mode === 'blend' ? '' : v.amount;
		c.water = v.water || '';
		c.conc = v.conc || '';
		c.volume = v.volume || '';
		c.parts = v.mode === 'blend' && Array.isArray( v.parts ) && v.parts.length ? v.parts.map( function ( x ) {
			return { name: x.name, amount: x.amount };
		} ) : [ { name: '', amount: '' }, { name: '', amount: '' } ];
		c.doseOf = p && p.doseOf ? p.doseOf : '';
		c.dose = '';
		if ( p && ( c.mode === 'iu' ? p.unit === 'IU' : p.unit === 'mg' || ( p.unit === 'mcg' && c.mode !== 'hormone' ) ) ) {
			c.dose = p.dose;
			c.unit = p.unit;
		}
		if ( c.mode === 'hormone' ) {
			c.per = 'injection';
		}
	}

	function calcNum( id, c, key, placeholder, onInput, label ) {
		return h( 'input', { class: 'ypt-input', id: id, type: 'number', inputmode: 'decimal', min: '0', step: 'any', placeholder: placeholder, value: c[ key ], 'aria-label': label || null, oninput: function ( e ) {
			c[ key ] = e.target.value;
			onInput();
		} } );
	}

	function calcChips( c, key, list, suffix, onPick ) {
		return h( 'div', { class: 'ypt-chips', style: { marginTop: '6px' } }, list.map( function ( n ) {
			return h( 'button', { type: 'button', class: 'ypt-chip ypt-chip--sm', 'aria-pressed': +c[ key ] === n ? 'true' : 'false', onclick: function () {
				c[ key ] = n;
				var el = document.getElementById( 'ypt-c-' + key );
				if ( el ) {
					el.value = n;
				}
				[].forEach.call( this.parentNode.children, function ( b ) {
					b.setAttribute( 'aria-pressed', 'false' );
				} );
				this.setAttribute( 'aria-pressed', 'true' );
				onPick();
			} }, n + ' ' + suffix );
		} ) );
	}

	function renderCalc() {
		var c = calcState();
		var guest = ! CFG.signedIn;
		var pen = c.kind === 'pen';
		var wrap = h( 'div', { class: 'ypt-calc' } );
		var result = h( 'div', { class: 'ypt-calc-result ypt-calc-result--main', 'aria-live': 'polite' } );
		var update = function () {
			drawCalcResult( result, c );
		};

		wrap.appendChild( h( 'header', { class: 'ypt-top' },
			h( 'div', null, h( 'div', { class: 'ypt-eyebrow' }, guest ? 'Free · no account needed' : 'Vials, pens & doses' ), h( 'h1', null, 'Calculator' ) )
		) );

		wrap.appendChild( h( 'div', { class: 'ypt-calc-modes', role: 'group', 'aria-label': 'What you’re measuring' }, CALC_MODES.map( function ( m ) {
			return h( 'button', { type: 'button', class: 'ypt-calc-mode', 'aria-pressed': c.mode === m[ 0 ] ? 'true' : 'false', onclick: function () {
				if ( c.mode === m[ 0 ] ) {
					return;
				}
				c.mode = m[ 0 ];
				c.dose = '';
				c.unit = m[ 0 ] === 'iu' ? 'IU' : 'mcg';
				c.compound = '';
				render();
			} }, m[ 1 ] );
		} ) ) );

		var mine = guest ? [] : vials( false );
		if ( mine.length ) {
			wrap.appendChild( h( 'label', { class: 'ypt-calc-from' },
				h( 'span', { class: 'ypt-calc-from__ic' }, icon( 'vials' ) ),
				h( 'span', { class: 'ypt-calc-from__text' }, h( 'b', null, c.compound ? 'Using your ' + c.compound + ( pen ? ' pen' : ' vial' ) : 'Start from a vial I have' ), mine.slice( 0, 3 ).map( function ( v ) {
					return v.compound;
				} ).join( ' · ' ) ),
				h( 'span', { class: 'ypt-calc-from__pick' }, c.compound ? 'Change' : 'Pick' ),
				h( 'select', { class: 'ypt-calc-from__select', 'aria-label': 'Start from a vial I have', onchange: function ( e ) {
					var v = e.target.value === '' ? null : mine.filter( function ( x ) {
						return x.id === e.target.value;
					} )[ 0 ];
					if ( v ) {
						calcFromVial( v );
					} else {
						ui.calc = null;
					}
					render();
				} }, [ h( 'option', { value: '' }, c.compound ? 'Start over (blank)' : 'Choose a vial or pen…' ) ].concat( mine.map( function ( v ) {
					return h( 'option', { value: v.id }, v.compound + ( isPen( v ) ? ' (pen)' : '' ) + ( v.mode === 'conc' ? ' · ' + fmtNum( +v.conc ) + ' mg/mL' : v.mode === 'blend' ? '' : ' · ' + fmtNum( +v.amount ) + ( v.mode === 'iu' ? ' IU' : ' mg' ) ) );
				} ) ) )
			) );
		}

		var form = h( 'div', { class: 'ypt-card ypt-calc-form' } );
		var where = pen ? 'pen' : 'vial';
		if ( c.mode === 'hormone' ) {
			form.appendChild( h( 'div', { class: 'ypt-row' },
				field( 'Strength (mg/mL)', calcNum( 'ypt-c-conc', c, 'conc', '200', update ), 'On the label', 'ypt-c-conc' ),
				field( ( pen ? 'Pen' : 'Vial' ) + ' size (mL)', calcNum( 'ypt-c-volume', c, 'volume', '10', update ), null, 'ypt-c-volume' )
			) );
			form.appendChild( h( 'div', { class: 'ypt-field' },
				h( 'span', { class: 'ypt-label' }, 'My dose is' ),
				seg( [ [ 'week', 'Per week' ], [ 'injection', 'Per injection' ] ], c.per, function ( v ) {
					c.per = v;
					render();
				} )
			) );
			if ( c.per === 'week' ) {
				form.appendChild( h( 'div', { class: 'ypt-row' },
					field( 'Weekly dose (mg)', calcNum( 'ypt-c-weekly', c, 'weekly', '200', update ), null, 'ypt-c-weekly' ),
					field( 'Injections per week', calcNum( 'ypt-c-perWeek', c, 'perWeek', '2', update ), null, 'ypt-c-perWeek' )
				) );
			} else {
				form.appendChild( field( 'Dose per injection (mg)', calcNum( 'ypt-c-dose', c, 'dose', '100', update ), null, 'ypt-c-dose' ) );
			}
		} else {
			if ( c.mode === 'blend' ) {
				var rows = h( 'div', { class: 'ypt-blend' } );
				c.parts.forEach( function ( x, i ) {
					rows.appendChild( h( 'div', { class: 'ypt-blend__row' },
						h( 'div', { class: 'ypt-blend__name' }, compoundInput( 'ypt-c-part-' + i, x.name, function ( name ) {
							x.name = name;
							update();
						}, 'Peptide ' + ( i + 1 ) ) ),
						h( 'div', { class: 'ypt-blend__amt' }, h( 'input', { class: 'ypt-input', id: 'ypt-c-part-amt-' + i, type: 'number', inputmode: 'decimal', min: '0', step: 'any', placeholder: '5', value: x.amount, 'aria-label': ( x.name || 'Peptide ' + ( i + 1 ) ) + ' mg', oninput: function ( e ) {
							x.amount = e.target.value;
							update();
						} } ), h( 'span', null, 'mg' ) ),
						c.parts.length > 2 ? h( 'button', { type: 'button', class: 'ypt-btn ypt-shrink', 'aria-label': 'Remove ' + ( x.name || 'this peptide' ), onclick: function () {
							c.parts.splice( i, 1 );
							render();
						} }, '×' ) : null
					) );
				} );
				if ( c.parts.length < 5 ) {
					rows.appendChild( h( 'button', { type: 'button', class: 'ypt-link', style: { alignSelf: 'flex-start' }, onclick: function () {
						c.parts.push( { name: '', amount: '' } );
						render();
					} }, '+ Add a peptide' ) );
				}
				form.appendChild( h( 'div', { class: 'ypt-field' }, h( 'span', { class: 'ypt-label' }, 'Peptides in the ' + where ), rows,
					h( 'p', { class: 'ypt-hint' }, 'As printed on the label. Mixing your own from separate vials? ', h( 'a', { href: CFG.calculatorUrl + '#blend-mix' }, 'Use the blend mixer' ), '.' ) ) );
				form.appendChild( field( 'Bacteriostatic water added (mL)', calcNum( 'ypt-c-water', c, 'water', '2', update ), null, 'ypt-c-water' ) );
				form.appendChild( calcChips( c, 'water', [ 1, 2, 2.5, 3 ], 'mL', update ) );
			} else {
				var unit = c.mode === 'iu' ? 'IU' : 'mg';
				form.appendChild( h( 'div', { class: 'ypt-row' },
					field( ( c.mode === 'iu' ? 'IU' : 'Peptide' ) + ' in the ' + where, h( 'div', { class: 'ypt-input-unit' }, calcNum( 'ypt-c-amount', c, 'amount', c.mode === 'iu' ? '10' : '10', update ), h( 'span', null, unit ) ), null, 'ypt-c-amount' ),
					field( 'Water added', h( 'div', { class: 'ypt-input-unit' }, calcNum( 'ypt-c-water', c, 'water', '2', update ), h( 'span', null, 'mL' ) ), null, 'ypt-c-water' )
				) );
				form.appendChild( calcChips( c, 'water', pen ? [ 3 ] : [ 1, 2, 2.5, 3 ], 'mL', update ) );
			}
			var doseUnits = c.mode === 'iu' ? [ 'IU' ] : [ 'mcg', 'mg' ];
			if ( doseUnits.indexOf( c.unit ) === -1 ) {
				c.unit = doseUnits[ 0 ];
			}
			var ofBox = null;
			var parts = c.mode === 'blend' ? calcParts( c ) : [];
			if ( parts.length ) {
				ofBox = h( 'div', { class: 'ypt-row', style: { alignItems: 'center', marginTop: '8px' } },
					h( 'span', { class: 'ypt-shrink ypt-small' }, 'That dose is' ),
					h( 'select', { class: 'ypt-select', 'aria-label': 'What the dose measures', onchange: function ( e ) {
						c.doseOf = e.target.value;
						update();
					} }, [ h( 'option', { value: '', selected: ! c.doseOf }, 'the whole blend' ) ].concat( parts.map( function ( x ) {
						return h( 'option', { value: x.name, selected: sameCompound( x.name, c.doseOf ) }, 'the ' + x.name + ' in it' );
					} ) ) )
				);
			}
			form.appendChild( h( 'div', { class: 'ypt-field' },
				h( 'label', { for: 'ypt-c-dose' }, 'Your dose' ),
				h( 'div', { class: 'ypt-row', style: { alignItems: 'center' } },
					h( 'div', { style: { flex: '1 1 50%' } }, calcNum( 'ypt-c-dose', c, 'dose', c.mode === 'iu' ? '2' : '250', update ) ),
					doseUnits.length > 1 ? seg( doseUnits, c.unit, function ( u ) {
						c.unit = u;
						update();
					} ) : h( 'div', { class: 'ypt-seg' }, h( 'button', { type: 'button', 'aria-pressed': 'true' }, 'IU' ) )
				),
				ofBox
			) );
		}
		if ( ! pen ) {
			form.appendChild( h( 'div', { class: 'ypt-field' },
				h( 'span', { class: 'ypt-label' }, 'Syringe' ),
				seg( [ [ 30, '0.3 mL · 30u' ], [ 50, '0.5 mL · 50u' ], [ 100, '1 mL · 100u' ] ], +c.syringe || 50, function ( s ) {
					c.syringe = s;
					update();
				}, 'ypt-seg--wrap' )
			) );
		}
		wrap.appendChild( form );
		wrap.appendChild( result );
		update();

		if ( guest ) {
			wrap.appendChild( h( 'div', { class: 'ypt-calc-cta' },
				h( 'div', null, h( 'b', null, 'Track this vial for free' ), h( 'span', null, 'Reminders, the units to draw on every dose, and a heads-up before it runs out.' ) ),
				h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--accent', onclick: guestSave }, 'Save' )
			) );
		} else {
			wrap.appendChild( h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--accent ypt-btn--block', style: { marginTop: '12px' }, onclick: function () {
				saveCalcAsVial( c );
			} }, c.compound && vials( false ).some( function ( v ) {
				return sameCompound( v.compound, c.compound );
			} ) ? 'Save as a new ' + ( pen ? 'pen' : 'vial' ) : 'Save as a ' + ( pen ? 'pen' : 'vial' ) ) );
		}
		wrap.appendChild( h( 'p', { class: 'ypt-hint', style: { marginTop: '12px', textAlign: 'center' } }, 'Math only, not medical advice. Always double-check against your vial’s label and your provider’s instructions.' ) );
		return wrap;
	}

	function drawCalcResult( box, c ) {
		box.textContent = '';
		box.appendChild( h( 'div', { class: 'ypt-stripe', 'aria-hidden': 'true' } ) );
		var v = calcVial( c );
		var d = calcDose( c );
		var conc = vialConcentration( v );
		var total = vialTotalUnits( v );
		var pen = c.kind === 'pen';
		var cap = +c.syringe || 50;
		var units = conc ? unitsForDose( d.dose, d.unit, v, c.mode === 'blend' ? c.doseOf : '' ) : null;
		if ( ! conc ) {
			box.appendChild( h( 'p', { class: 'ypt-muted' }, c.mode === 'hormone' ? 'Enter the strength on the label to see the units to draw.' : c.mode === 'blend' ? 'Enter at least one peptide’s mg and the water you added.' : 'Enter what’s in the ' + ( pen ? 'pen' : 'vial' ) + ' and the water you added.' ) );
			if ( ! pen ) {
				box.appendChild( drawSyringe( 0, cap ) );
			}
			return;
		}
		if ( units == null ) {
			box.appendChild( h( 'p', { class: 'ypt-muted' }, 'Now enter your dose to see how many units to draw.' ) );
		} else {
			box.appendChild( h( 'div', { class: 'ypt-eyebrow' }, pen ? 'Dial to' : 'Draw to' ) );
			box.appendChild( h( 'div', { class: 'ypt-calc-big' }, fmtNum( units, 1 ), h( 'small', null, 'units · ' + fmtNum( units / 100, 3 ) + ' mL' ) ) );
			if ( c.mode === 'hormone' && c.per === 'week' ) {
				box.appendChild( h( 'p', { class: 'ypt-small', style: { marginTop: '6px' } }, fmtNum( d.dose, 2 ) + ' mg per injection (' + fmtNum( +c.weekly, 2 ) + ' mg ÷ ' + Math.round( +c.perWeek ) + ' a week)' ) );
			}
			if ( isBlend( v ) ) {
				box.appendChild( h( 'p', { class: 'ypt-small', style: { marginTop: '6px' } }, 'Each dose: ' + blendDoseLine( v, units ) ) );
			}
			if ( ! pen ) {
				box.appendChild( drawSyringe( units, cap ) );
			}
			if ( ! pen && units > cap ) {
				box.appendChild( h( 'div', { class: 'ypt-draw ypt-draw--warn' }, 'That’s more than a ' + ( cap / 100 ) + ' mL syringe holds. Pick a bigger syringe' + ( c.mode === 'hormone' ? ', or split it into more injections.' : ', or add less water for a stronger mix.' ) ) );
			} else if ( units < 2 ) {
				box.appendChild( h( 'div', { class: 'ypt-draw ypt-draw--warn' }, 'Under 2 units is hard to measure.' + ( c.mode === 'hormone' ? '' : ' Adding more water makes each dose easier to draw.' ) ) );
			}
		}
		var doses = units > 0 && total ? Math.floor( ( total + 0.0001 ) / units ) : 0;
		box.appendChild( h( 'div', { class: 'ypt-stats' },
			h( 'div', { class: 'ypt-stat' }, h( 'b', null, fmtNum( conc.amount, 2 ) + ' ' + conc.unit + '/mL' ), h( 'span', null, 'strength' ) ),
			h( 'div', { class: 'ypt-stat' }, h( 'b', null, conc.unit === 'mg' ? fmtNum( conc.amount * 10, 1 ) + ' mcg' : fmtNum( conc.amount / 100, 2 ) + ' IU' ), h( 'span', null, pen ? 'per unit dialed' : 'per syringe unit' ) ),
			h( 'div', { class: 'ypt-stat' }, h( 'b', null, doses ? doses + ' doses' : total ? fmtNum( total, 0 ) + ' u' : '–' ), h( 'span', null, doses ? ( c.mode === 'hormone' && c.per === 'week' && +c.perWeek > 0 ? 'about ' + fmtNum( doses / Math.round( +c.perWeek ), 1 ) + ' weeks' : 'per ' + ( pen ? 'pen' : 'vial' ) ) : 'units in the ' + ( pen ? 'pen' : 'vial' ) ) )
		) );
	}

	/** Signed in: on to the Mix a vial sheet, filled in. Saving it goes on to the schedule when there isn't one yet. */
	function saveCalcAsVial( c ) {
		var v = calcVial( c );
		var d = calcDose( c );
		openVialSheet( null, {
			title: 'Save as a ' + ( c.kind === 'pen' ? 'pen' : 'vial' ),
			kind: c.kind,
			compound: c.compound || ( c.mode === 'blend' ? v.parts.map( function ( x ) {
				return x.name;
			} ).join( ' + ' ) : '' ),
			preset: Object.assign( {}, v, { syringe: +c.syringe || 50, blend: c.mode === 'blend' ? 'bought' : '' } ),
			dose: parseFloat( d.dose ) > 0 ? String( Math.round( parseFloat( d.dose ) * 1000 ) / 1000 ) : '',
			unit: d.unit,
			doseOf: c.mode === 'blend' ? c.doseOf : '',
		} );
	}

	function guestSave() {
		var c = calcState();
		saveJSON( CALC_DRAFT_KEY, { c: c, at: Date.now() } );
		var v = calcVial( c );
		var d = calcDose( c );
		var conc = vialConcentration( v );
		var units = conc ? unitsForDose( d.dose, d.unit, v, c.mode === 'blend' ? c.doseOf : '' ) : null;
		var what = c.mode === 'hormone' ? ( +c.conc > 0 ? fmtNum( +c.conc ) + ' mg/mL' + ( +c.volume > 0 ? ', ' + fmtNum( +c.volume ) + ' mL' : '' ) : '' )
			: conc ? fmtNum( +v.amount ) + ( c.mode === 'iu' ? ' IU' : ' mg' ) + ' + ' + fmtNum( +v.water ) + ' mL water' : '';
		guestSheet( 'Sign in to save this ' + ( c.kind === 'pen' ? 'pen' : 'vial' ), 'Your numbers stay put. Once you’re signed in, YeffoHealth opens with everything filled in.', what ? h( 'div', { class: 'ypt-calc-kept' },
			units != null ? h( 'span', { class: 'ypt-calc-kept__n' }, fmtNum( units, 1 ) + ' u' ) : null,
			h( 'span', null, h( 'b', null, what ), units != null ? fmtNum( +d.dose, 3 ) + ' ' + d.unit + ' dose' : 'No dose entered yet' )
		) : null );
	}

	/** The locked tabs and Save: what an account adds, and the way in. */
	function guestSheet( title, text, extra ) {
		openSheet( title, 'YeffoHealth', [
			h( 'p', { class: 'ypt-muted' }, text ),
			extra,
			h( 'a', { class: 'ypt-btn ypt-btn--primary ypt-btn--block', style: { marginTop: '16px' }, href: CFG.registerUrl }, 'Create a free account' ),
			h( 'a', { class: 'ypt-btn ypt-btn--block', style: { marginTop: '10px' }, href: CFG.loginUrl }, 'I already have an account' ),
			h( 'p', { class: 'ypt-lock', style: { marginTop: '14px' } }, icon( 'lock' ), 'Encrypted · only you can see your doses' ),
		] );
	}

	function guestLocked( tab ) {
		var info = {
			today: [ 'Today’s doses, one tap to log', 'See what’s due, tap Take or Skip, and get a reminder on your phone when it’s time. Free with an account.' ],
			progress: [ 'See your progress', 'Weight, measurements, photos and lab results, charted alongside your doses. Free with an account.' ],
			vials: [ 'Your whole supply', 'Track every vial, pen and bottle, see when to mix the next one, and get a heads-up before you run low. Free with an account.' ],
		}[ tab ];
		guestSheet( info[ 0 ], info[ 1 ], null );
	}

	/** Below the signed-out calculator: what else the app does. */
	function guestAbout() {
		function feature( ic, title, text ) {
			return h( 'li', null, icon( ic ), h( 'div', null, h( 'b', null, title ), text ) );
		}
		return h( 'div', null,
			h( 'div', { class: 'ypt-eyebrow ypt-section-label', style: { marginTop: '28px' } }, 'Free with an account' ),
			h( 'ul', { class: 'ypt-features' },
				feature( 'check', 'Today’s doses at a glance', 'Tap Take or Skip. Daily, weekly, every few days, or on/off cycles.' ),
				feature( 'syringe', 'Units on every dose', 'Save your vial once; every dose shows exactly what to draw.' ),
				feature( 'bell', 'Reminders', 'A notification on your phone when a dose is due, and before you run low.' ),
				feature( 'vials', 'Your whole supply', 'Track vials and pills on hand, see when to mix the next one and when to reorder.' ),
				feature( 'lock', 'Private and encrypted', 'Your entries are encrypted with a key only your account unlocks.' )
			),
			h( 'a', { class: 'ypt-btn ypt-btn--primary ypt-btn--block', href: CFG.registerUrl }, 'Create a free account' ),
			h( 'p', { class: 'ypt-muted', style: { textAlign: 'center', marginTop: '14px' } }, 'Already have one? ', h( 'a', { href: CFG.loginUrl }, 'Sign in' ) ),
			h( 'p', { class: 'ypt-muted ypt-small', style: { textAlign: 'center', marginTop: '24px' } },
				'YeffoHealth is not a healthcare provider and doesn’t give medical advice. It simply keeps track of the information that matters to you.' )
		);
	}

	/** After signing in: a calculation saved while signed out opens as a new vial. */
	function takeCalcDraft() {
		var draft = loadJSON( CALC_DRAFT_KEY, null );
		removeKey( CALC_DRAFT_KEY );
		if ( ! draft || ! draft.c || ! ( Date.now() - ( +draft.at || 0 ) < CALC_DRAFT_DAYS * 86400000 ) ) {
			return;
		}
		ui.calc = null;
		var c = Object.assign( calcState(), draft.c );
		if ( ! Array.isArray( c.parts ) || ! c.parts.length ) {
			c.parts = [ { name: '', amount: '' }, { name: '', amount: '' } ];
		}
		go( 'calc' );
		saveCalcAsVial( c );
	}

	function renderMessage( title, text, retry ) {
		root.textContent = '';
		root.appendChild( h( 'div', { class: 'ypt-card ypt-empty', style: { marginTop: '30vh' } },
			h( 'h2', null, title ),
			h( 'p', null, text ),
			retry ? h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--primary', onclick: function () {
				window.location.reload();
			} }, 'Try again' ) : null
		) );
	}

	function renderSignedOut() {
		removeKey( STORE_KEY );
		removeKey( QUEUE_KEY );
		forgetNative();
		root.textContent = '';
		function feature( ic, title, text ) {
			return h( 'li', null, icon( ic ), h( 'div', null, h( 'b', null, title ), text ) );
		}
		var share = CFG.share;
		if ( share ) {
			root.appendChild( h( 'div', { class: 'ypt-hero ypt-hero--share' },
				h( 'a', { class: 'ypt-brand', href: CFG.homeUrl }, h( 'img', { src: iconUrl( 'icon-192.png' ), alt: '' } ), 'YeffoDesign' ),
				h( 'div', { class: 'ypt-eyebrow', style: { marginTop: '24px' } }, 'Shared with you' ),
				h( 'h1', null, share.protocol ? 'A friend sent you a protocol' : 'This link doesn’t work' ),
				share.protocol ? null : h( 'p', null, 'The person who sent it may have stopped sharing it. Ask them for a new link.' )
			) );
			if ( share.protocol ) {
				root.appendChild( protoCard( share.protocol ) );
				root.appendChild( h( 'a', { class: 'ypt-btn ypt-btn--accent ypt-btn--block', style: { marginTop: '16px' }, href: CFG.loginUrl }, 'Sign in to add it' ) );
				root.appendChild( h( 'p', { class: 'ypt-muted', style: { textAlign: 'center', marginTop: '14px' } },
					'New here? ', h( 'a', { href: CFG.registerUrl }, 'Create a free account' ), ', then open YeffoHealth and it’ll be waiting.' ) );
				root.appendChild( h( 'div', { class: 'ypt-banner ypt-banner--info', style: { marginTop: '16px' } }, h( 'div', null, 'Doses here come from another person, not from YeffoDesign. Check them with your provider.' ) ) );
			}
			root.appendChild( h( 'div', { class: 'ypt-eyebrow ypt-section-label', style: { marginTop: '28px' } }, 'About YeffoHealth' ) );
		} else {
			root.appendChild( h( 'div', { class: 'ypt-hero' },
			h( 'a', { class: 'ypt-brand', href: CFG.homeUrl }, h( 'img', { src: iconUrl( 'icon-192.png' ), alt: '' } ), 'YeffoDesign' ),
			h( 'div', { class: 'ypt-eyebrow', style: { marginTop: '28px' } }, 'Free for customers' ),
			h( 'h1', null, 'YeffoHealth' ),
			h( 'p', null, 'Log every peptide and medication dose, see exactly how many units to draw, and get a reminder when it’s time.' )
		) );
		}
		root.appendChild( h( 'ul', { class: 'ypt-features' },
			feature( 'check', 'Today’s doses at a glance', 'Tap Take or Skip. Daily, weekly, every few days, or on/off cycles.' ),
			feature( 'calc', 'Built-in vial calculator', 'Enter your vial and water once; every dose shows the units to draw.' ),
			feature( 'bell', 'Reminders', 'A notification on your phone when a dose is due, and before you run low.' ),
			feature( 'vials', 'Your whole supply', 'Track vials and pills on hand, see when to mix the next one and when to reorder.' ),
			feature( 'lock', 'Private and encrypted', 'Your entries are encrypted with a key only your account unlocks.' )
		) );
		if ( ! share || ! share.protocol ) {
			root.appendChild( h( 'a', { class: 'ypt-btn ypt-btn--primary ypt-btn--block', href: CFG.loginUrl }, 'Sign in to start' ) );
			root.appendChild( h( 'p', { class: 'ypt-muted', style: { textAlign: 'center', marginTop: '14px' } },
				'New here? ', h( 'a', { href: CFG.registerUrl }, 'Create a free account' ) ) );
		}
		root.appendChild( h( 'p', { class: 'ypt-muted ypt-small', style: { textAlign: 'center', marginTop: '24px' } },
			'YeffoHealth is not a healthcare provider and doesn’t give medical advice. It simply keeps track of the information that matters to you.' ) );
	}

	function iconUrl( file ) {
		var link = document.querySelector( 'link[rel="icon"]' );
		return link ? link.href.replace( /[^/]+$/, file ) : '';
	}

	/* =========================================================
	 * Boot
	 * ======================================================= */

	applyTheme();
	if ( darkQuery && darkQuery.addEventListener ) {
		darkQuery.addEventListener( 'change', applyTheme );
	}

	window.addEventListener( 'beforeinstallprompt', function ( e ) {
		e.preventDefault();
		installPrompt = e;
		render();
	} );

	if ( 'serviceWorker' in navigator && CFG.swUrl ) {
		navigator.serviceWorker.register( CFG.swUrl, { scope: CFG.appUrl.replace( /^https?:\/\/[^/]+/, '' ) } ).then( checkPushHere ).catch( function () {} );
		navigator.serviceWorker.addEventListener( 'message', function ( e ) {
			if ( e.data && e.data.type === 'yp-tracker-refresh' ) {
				ui.day = todayStr();
				load();
			}
		} );
	}

	if ( ! CFG.signedIn ) {
		if ( CFG.share ) {
			if ( CFG.share.protocol ) {
				// Signing up goes through My Account, not back here: remember the link so the tracker offers it next time.
				saveJSON( PENDING_SHARE_KEY, CFG.share );
			}
			renderSignedOut();
			return;
		}
		// Anyone can use the calculator; the rest of the app needs an account.
		removeKey( STORE_KEY );
		removeKey( QUEUE_KEY );
		forgetNative();
		render();
		return;
	}
	if ( ! CFG.ready ) {
		renderMessage( 'Almost ready', 'YeffoHealth is being set up on our end. Please check back soon.', false );
		return;
	}

	// Only this account's copy stays on the device: another customer who
	// signed in on this browser earlier shouldn't leave theirs behind.
	try {
		Object.keys( window.localStorage ).forEach( function ( k ) {
			if ( ( k.indexOf( 'ypt:' ) === 0 && k !== STORE_KEY ) || ( k.indexOf( 'ypt-q:' ) === 0 && k !== QUEUE_KEY ) || ( k.indexOf( 'ypt-native-reminders:' ) === 0 && k !== NATIVE_KEY ) || ( k.indexOf( 'ypt-native-snooze:' ) === 0 && k !== NATIVE_SNOOZE_KEY ) ) {
				removeKey( k );
			}
		} );
	} catch ( e ) {}

	if ( NATIVE ) {
		checkNativeHere();
	}

	var savedUi = loadJSON( UI_KEY, null );
	if ( savedUi && savedUi.tab && savedUi.tab !== 'today' ) {
		ui.tab = savedUi.tab;
	}
	if ( savedUi && savedUi.supplyView ) {
		ui.supplyView = savedUi.supplyView;
	}
	var incoming = CFG.share || loadJSON( PENDING_SHARE_KEY, null );
	if ( CFG.share ) {
		ui.tab = 'today';
		// The address bar goes back to the app, so a refresh or Home Screen install isn't the share page.
		try {
			window.history.replaceState( null, '', CFG.appUrl );
		} catch ( e ) {}
	}
	load().then( function () {
		if ( incoming && state.loaded ) {
			removeKey( PENDING_SHARE_KEY );
			openReceivedShare( incoming );
		} else if ( state.loaded ) {
			takeCalcDraft();
		}
	} );
}() );
