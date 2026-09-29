/**
 * Dose Tracker app (/tracker/) — see includes/tracker/class-tracker-app.php.
 *
 * Vanilla JS, no build step, same as the theme's scripts. Everything the
 * customer enters goes through the encrypted REST API
 * (rest/class-tracker-controller.php); this file keeps a local copy so
 * the app opens instantly and works offline, queueing changes until the
 * connection is back. Records:
 *
 *   protocol  { compound, dose, unit, route, device:'syringe'|'pen'|'single', schedule:{type,days,every,on,off}, times[], start, weeks, color, notes, paused, doseOf }
 *             doseOf: for a blend, the peptide the dose is measured by ('' = the whole blend).
 *   dose      { protocolId, compound, date, time, status:'taken'|'skipped', at, dose, unit, vialId, units, note }
 *   vial      { kind:'vial'|'pen', compound, mode:'mg'|'iu'|'conc'|'blend', amount, water, conc, volume, mixed, syringe, finished, parts, blend }
 *             A blend has parts [{ name, amount (mg) }] and amount = their total; blend is 'bought' or 'mixed' (the customer combined vials).
 *             A pen is a 3 mL cartridge the customer mixes like a vial; its dial is read as U-100 units (0.01 mL each).
 *   settings  { tz, reminders, reminderNames }
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
	var VIAL_WARN_DAYS = 28;
	var NONCE_ERRORS = [ 'yeffoprint_invalid_nonce', 'rest_cookie_invalid_nonce' ];

	var STORE_KEY = 'ypt:' + ( CFG.userKey || 'anon' );
	var QUEUE_KEY = 'ypt-q:' + ( CFG.userKey || 'anon' );
	var UI_KEY = 'ypt-ui';

	var state = {
		records: { protocol: {}, dose: {}, vial: {}, stock: {}, settings: {} },
		push: { publicKey: '', devices: 0 },
		shares: [],
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
		plus: 'M12 5v14M5 12h14',
		vials: 'M9 3h6M10 3v4l-3 3v10a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V10l-3-3V3M7 14h10',
		me: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21c1-4 4-6 8-6s7 2 8 6',
		lock: 'M6 11h12v10H6zM8 11V8a4 4 0 0 1 8 0v3',
		bell: 'M6 16V11a6 6 0 0 1 12 0v5l2 2H4zM10 21h4',
		calc: 'M6 3h12v18H6zM9 7h6M9 12h1M14 12h1M9 16h1M14 16h1',
		check: 'M5 12l5 5L20 7',
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
		if ( ! weeks ) {
			return '';
		}
		var wk = Math.floor( daysBetween( p.start, date ) / 7 ) + 1;
		return 'wk ' + Math.min( wk, weeks ) + ' of ' + weeks;
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
		var per = p ? unitsForDose( p.dose, p.unit, v, p.doseOf ) : null;
		var dosesLeft = left != null && per ? Math.floor( ( left + 0.0001 ) / per ) : null;
		// The date of the last scheduled dose this vial still covers.
		var lastDose = null;
		if ( p && dosesLeft ) {
			var remaining = dosesLeft;
			var perDay = timesOf( p ).length;
			var d = todayStr();
			// Today's doses already logged don't need covering again.
			var loggedToday = slotsOn( d ).filter( function ( s ) {
				return s.protocol.id === p.id && s.log;
			} ).length;
			for ( var i = 0; i < 400 && remaining > 0; i++, d = addDays( d, 1 ) ) {
				var need = isDueOn( p, d ) ? perDay - ( i === 0 ? loggedToday : 0 ) : 0;
				if ( need > 0 ) {
					remaining -= need;
					lastDose = d;
				}
			}
		}
		return {
			total: total,
			left: left,
			pct: total ? Math.max( 0, Math.min( 100, ( left / total ) * 100 ) ) : 0,
			protocol: p,
			perDose: per,
			dosesLeft: dosesLeft,
			lastDose: lastDose,
			age: v.mixed ? daysBetween( v.mixed, todayStr() ) : 0,
		};
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

	function amountLabel( dose, unit ) {
		return fmtNum( +dose, 3 ) + ' ' + unitLabel( unit, dose );
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
		saveJSON( STORE_KEY, { records: state.records, push: state.push, shares: state.shares, savedAt: Date.now() } );
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
			state.records = Object.assign( { protocol: {}, dose: {}, vial: {}, stock: {}, settings: {} }, cached.records );
			state.push = cached.push || state.push;
			state.shares = cached.shares || [];
			state.loaded = true;
			render();
		}

		return api( 'GET', 'tracker/state' ).then( function ( json ) {
			var fresh = json.records || {};
			[ 'protocol', 'dose', 'vial', 'stock', 'settings' ].forEach( function ( k ) {
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
		var units = v ? unitsForDose( p.dose, p.unit, v, p.doseOf ) : ( p.unit === 'units' ? +p.dose : null );
		// Logging a past day's dose: stamp it at its scheduled time.
		var at = slot.date !== todayStr() ? wallToIso( slot.date, slot.time ) : new Date().toISOString();
		put( 'dose', slot.id, {
			protocolId: p.id,
			compound: p.compound,
			date: slot.date,
			time: slot.time,
			status: status,
			at: at,
			dose: +p.dose,
			unit: p.unit,
			vialId: v ? v.id : '',
			units: units != null ? Math.round( units * 100 ) / 100 : null,
			note: '',
		} );
		if ( status === 'taken' ) {
			toast( p.compound + ' logged', function () {
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
		if ( ! state.loaded ) {
			return;
		}
		var scrollY = window.scrollY;
		var focusedId = document.activeElement && document.activeElement.id;
		root.textContent = '';
		if ( state.offline ) {
			root.appendChild( h( 'div', { class: 'ypt-offline' }, queue.length ? 'Offline · ' + queue.length + ' change' + ( queue.length === 1 ? '' : 's' ) + ' will sync' : 'Offline · showing your saved copy' ) );
		}
		var screens = { today: renderToday, history: renderHistory, vials: renderSupply, me: renderMe };
		root.appendChild( ( screens[ ui.tab ] || renderToday )() );
		root.appendChild( lockLine() );
		root.appendChild( renderTabs() );
		if ( ui.sheet ) {
			root.appendChild( ui.sheet.node );
		}
		window.scrollTo( 0, scrollY );
		syncAlerts();
		syncNativeReminders();
		if ( focusedId && document.getElementById( focusedId ) && ! ui.sheet ) {
			document.getElementById( focusedId ).focus();
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
		function tab( id, label, ic ) {
			return h( 'button', { type: 'button', class: 'ypt-tab', 'aria-current': ui.tab === id ? 'page' : null, onclick: function () {
				go( id );
			} }, icon( ic ), label );
		}
		return h( 'nav', { class: 'ypt-tabs', 'aria-label': 'Tracker' },
			h( 'div', { class: 'ypt-tabs__inner' },
				tab( 'today', 'Today', 'today' ),
				tab( 'history', 'History', 'history' ),
				h( 'button', { type: 'button', class: 'ypt-tab ypt-tab--add', onclick: function () {
					openProtocolSheet( null );
				} }, h( 'span', { class: 'ypt-tab__plus' }, icon( 'plus' ) ), 'Add' ),
				tab( 'vials', 'Supply', 'vials' ),
				tab( 'me', 'Me', 'me' )
			)
		);
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
							h( 'div', { class: 'ypt-dose__sub' }, amountLabel( d.dose, d.unit ) + ( d.note ? ' · ' + d.note : '' ) )
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
		var units = log && log.units != null ? +log.units : ( v ? unitsForDose( p.dose, p.unit, v, p.doseOf ) : null );
		var how = p.device === 'pen' ? 'pen' : p.device === 'single' ? 'single-use injector' : p.route ? routeInfo( p.route ).short || p.route.toLowerCase() : '';
		var sub = [ amountLabel( p.dose, p.unit ) + ( p.doseOf ? ' ' + p.doseOf : '' ), how, scheduleLabel( p ), weekLabel( p, s.date ) ].filter( Boolean ).join( ' · ' );

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

		var vi = v ? vialInfo( v ) : null;
		var what = p.device === 'pen' ? 'pen' : 'vial';
		// Mix day: the vial in use has nothing left for this dose (or there's none yet but some on hand).
		if ( ! log && s.date === todayStr() && usesVial( p ) && ( vi ? vi.dosesLeft === 0 : stockFor( p ).some( function ( x ) {
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
				h( 'b', null, 'Mix day. ' ), v ? 'This ' + what + ' is used up.' : 'Mix a ' + what + ' to see the units for this dose.',
				have ? ' You have ' + have + ' on hand.' : ' You have none on hand.',
				h( 'div', { class: 'ypt-mixday__actions' },
					h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--accent', onclick: function () {
						var src = stockFor( p ).filter( function ( x ) {
							return stockLeft( x ) > 0;
						} )[ 0 ];
						var preset = v ? { kind: v.kind, mode: v.mode, amount: v.amount, water: v.water, conc: v.conc, volume: v.volume, parts: v.parts, blend: v.blend, syringe: v.syringe } : {};
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
				card.appendChild( h( 'div', { class: 'ypt-draw' + ( info && info.age > VIAL_WARN_DAYS ? ' ypt-draw--warn' : '' ) },
					isPen( v ) ? [ log ? 'Dialed ' : 'Dial ', h( 'b', null, fmtNum( units, 1 ) + ' units' ), ' on your pen' ] : [ log ? 'Drew ' : 'Draw ', h( 'b', null, fmtNum( units, 1 ) + ' units' ), ' on a U-100 syringe' ],
					v ? ' · ' + ( isPen( v ) ? 'pen' : 'vial' ) + ' mixed ' + fmtDay( v.mixed ).replace( /^\w+, /, '' ) : '',
					info && info.age > VIAL_WARN_DAYS ? ' · ' + info.age + ' days old' : '',
					! log && info && info.dosesLeft === 1 && s.date === todayStr() ? ' · last dose in this ' + what : '',
					isBlend( v ) ? h( 'span', { class: 'ypt-draw__blend' }, 'Each dose: ' + blendDoseLine( v, units ) ) : null
				) );
			} else if ( ! log && ( p.unit === 'mcg' || p.unit === 'mg' || p.unit === 'IU' ) && usesVial( p ) ) {
				card.appendChild( h( 'button', { type: 'button', class: 'ypt-draw', style: { width: '100%', textAlign: 'left' }, onclick: function () {
					openVialSheet( null, { compound: p.compound, dose: p.dose, unit: p.unit, kind: p.device === 'pen' ? 'pen' : 'vial' } );
				} }, p.device === 'pen' ? 'Add your pen to see how many units to dial →' : 'Add your vial to see how many units to draw →' ) );
			}
		}
		return card;
	}

	/* ---------- Shared protocols ---------- */

	/*
	 * A share link carries only what's on the card (includes/tracker/class-tracker-shares.php):
	 * { compound, dose, unit, route, device, doseOf, schedule, times, weeks, notes, mix? }.
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
			[ 'Dose', amountLabel( pr.dose, pr.unit ) + ( pr.doseOf ? ' ' + pr.doseOf : '' ) + ( how ? ', ' + how : '' ) ],
			[ 'Schedule', ( scheduleLabel( pr ) === 'daily' ? 'Every day' : scheduleLabel( pr ).charAt( 0 ).toUpperCase() + scheduleLabel( pr ).slice( 1 ) ) + ', ' + timesOf( pr ).map( fmtTime ).join( ' & ' ) ],
			+pr.weeks ? [ 'Length', pr.weeks + ' week' + ( +pr.weeks === 1 ? '' : 's' ) ] : null,
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
		var inc = { mix: !! v, weeks: !! +p.weeks, notes: false };
		var link = { key: '', url: '' };
		var preview = h( 'div', null );
		var linkBox = h( 'div', null );
		var foot = h( 'div', { style: { display: 'flex', gap: '8px', flex: '1' } } );

		function payload() {
			var out = { compound: p.compound, dose: p.dose, unit: p.unit, route: p.route, device: p.device || '', doseOf: p.doseOf || '', schedule: p.schedule, times: timesOf( p ), weeks: inc.weeks ? +p.weeks || 0 : 0, notes: inc.notes ? p.notes || '' : '' };
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
						navigator.share( { title: p.compound + ' protocol', text: 'Here’s my ' + p.compound + ' schedule. Add it to your Dose Tracker:', url: link.url } ).catch( function () {} );
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
				p.notes ? check( 'notes', 'My note', '“' + p.notes.slice( 0, 40 ) + ( p.notes.length > 40 ? '…' : '' ) + '”' ) : null,
				! v && ! +p.weeks && ! p.notes ? h( 'p', { class: 'ypt-list-row ypt-muted ypt-small' }, 'The dose and schedule above.' ) : null
			),
			h( 'p', { class: 'ypt-hint ypt-share-privacy' }, icon( 'lock' ), 'Never shared: your name, your history, your vials. The link only holds what’s on the card above, and anyone with it can see that. You can stop sharing it any time from Me › Shared links.' ),
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
		openSheet( 'What’s new', 'Dose Tracker', [ h( 'div', { class: 'ypt-card ypt-list' }, newsList().map( newsItem ) ) ] );
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
				h( 'div', null, h( 'b', null, 'Add Dose Tracker to your home screen' ), ' so it opens like an app.',
					h( 'div', { style: { marginTop: '8px' } }, h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--primary', onclick: promptInstall }, 'Install app' ) ) ),
				close );
		}
		return h( 'div', { class: 'ypt-banner ypt-banner--info' },
			h( 'div', null, h( 'b', null, 'Add to your Home Screen' ), ' to use Dose Tracker like an app and get reminders: tap the Share button, then “Add to Home Screen”.' ),
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
				h( 'span', null, h( 'i', { style: { background: '#BFE9FA' } } ), 'Some' ),
				h( 'span', null, h( 'i', { style: { background: '#FDE0EF' } } ), 'Missed' )
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

		// Selected day's detail.
		var day = ui.historyDay;
		var rows = [];
		slotsOn( day ).forEach( function ( s ) {
			if ( filter && s.protocol.id !== filter ) {
				return;
			}
			rows.push( { name: s.protocol.compound + ' · ' + amountLabel( s.protocol.dose, s.protocol.unit ), log: s.log, time: s.time, slot: s } );
		} );
		extrasOn( day ).forEach( function ( d ) {
			if ( filter && d.protocolId !== filter ) {
				return;
			}
			rows.push( { name: d.compound + ' · ' + amountLabel( d.dose, d.unit ) + ' (extra)', log: d, time: d.time } );
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
					h( 'div', null, h( 'div', null, r.name ), r.log && r.log.note ? h( 'div', { class: 'ypt-log__note' }, '“' + r.log.note + '”' ) : null ),
					h( 'span', { class: 'ypt-log__right', style: ! r.log && right === 'Missed' ? { color: 'var(--ypt-magenta-deep)' } : null }, right )
				);
			} ) ) );
		}

		wrap.appendChild( h( 'p', { style: { textAlign: 'center', marginTop: '14px' } },
			h( 'button', { type: 'button', class: 'ypt-link', onclick: exportCsv }, 'Export CSV for your provider' ) ) );

		return wrap;
	}

	function exportCsv() {
		var rows = [ [ 'Date', 'Time', 'Compound', 'Dose', 'Unit', 'Status', 'Units drawn', 'Note' ] ];
		values( state.records.dose ).sort( function ( a, b ) {
			return ( a.date + a.time ).localeCompare( b.date + b.time );
		} ).forEach( function ( d ) {
			rows.push( [ d.date, d.status === 'taken' && d.at ? fmtIsoTime( d.at ) : fmtTime( d.time ), d.compound, d.dose, d.unit, d.status, d.units == null ? '' : d.units, d.note || '' ] );
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
	 * Supply: what's on hand, how long it lasts, when to mix next
	 * ======================================================= */

	/*
	 * A stock record is what the customer has at home that isn't in use yet:
	 *   form 'powder'   unmixed vials (amount + amountUnit mg|IU each)
	 *        'pen'      pen cartridges to mix (amount + amountUnit)
	 *        'premixed' ready-to-use vials (conc mg/mL, volume mL)
	 *        'count'    pills, sprays, patches… (count is how many of them)
	 * count is how many vials/pens (or pills) there were at countedAt;
	 * mixing a vial from supply takes one off, and pills count down with
	 * each dose taken after countedAt. warn: days of supply left that
	 * counts as running low (0 = never warn).
	 */
	var STOCK_FORMS = [ [ 'powder', 'Powder vial' ], [ 'pen', 'Pen cartridge' ], [ 'premixed', 'Premixed vial' ], [ 'count', 'Pills & other' ] ];
	var WARN_CHOICES = [ [ 7, '1 week' ], [ 14, '2 weeks' ], [ 30, '1 month' ], [ 0, 'Off' ] ];
	var PLAN_DAYS = 730;
	var BUY_AHEAD_DAYS = 7;

	function stocks() {
		return values( state.records.stock ).sort( function ( a, b ) {
			return String( a.compound ).localeCompare( String( b.compound ) );
		} );
	}

	function isVialStock( s ) {
		return s.form !== 'count';
	}

	/** Whether a stock item feeds this protocol: same name, and the same kind of thing (pen cartridges for pen doses, vials for syringe doses, pills for anything without a vial). */
	function stockFits( s, p ) {
		if ( ! sameCompound( s.compound, p.compound ) ) {
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
		var u = s.countUnit || 'tablet';
		return n === 1 ? u : UNIT_PLURAL[ u ] || u;
	}

	/** "10 mg powder vials", "5 mg pen cartridges", "2.5 mg/mL · 10 mL vials", "500 mg tablets" */
	function stockSummary( s, n ) {
		var many = n !== 1;
		if ( s.form === 'premixed' ) {
			return ( +s.conc > 0 ? fmtNum( +s.conc ) + ' mg/mL · ' : '' ) + ( +s.volume > 0 ? fmtNum( +s.volume ) + ' mL ' : '' ) + ( many ? 'vials' : 'vial' );
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
		var per = cur ? unitsForDose( p.dose, p.unit, cur, p.doseOf ) : null;
		// Same strength as the vial in use: the customer will most likely mix it the same way.
		if ( cur && per && ! isBlend( cur ) && ( s.form === 'premixed' ? +s.conc === +cur.conc || ! +s.conc : +s.amount === +cur.amount || ! +s.amount ) ) {
			var total = s.form === 'premixed' && +s.volume > 0 ? +s.volume * 100 : vialTotalUnits( cur );
			return total ? Math.floor( ( total + 0.0001 ) / per ) : null;
		}
		if ( cur && isBlend( cur ) && per ) {
			return Math.floor( ( vialTotalUnits( cur ) + 0.0001 ) / per );
		}
		var dose = +p.dose;
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
			var cur = currentVial( p );
			var info = cur ? vialInfo( cur ) : null;
			var curLeft = info && info.dosesLeft != null ? info.dosesLeft : 0;
			var perVial = null;
			list.some( function ( s ) {
				perVial = dosesPerStockVial( s, p );
				return perVial;
			} );
			if ( ! perVial && info && info.perDose && info.total ) {
				perVial = Math.floor( ( info.total + 0.0001 ) / info.perDose );
			}
			plan.perVial = perVial;
			plan.known = !! perVial || ( ! list.length && !! cur );
			plan.onHand = list.reduce( function ( n, s ) {
				return n + stockLeft( s );
			}, 0 );
			var idx = 0;
			if ( cur ) {
				plan.segments.push( { status: 'use', vial: cur, mixOn: cur.mixed, to: curLeft ? dates[ Math.min( curLeft, dates.length ) - 1 ] : null, doses: curLeft } );
				idx = curLeft;
			}
			for ( var i = 0; perVial && i < plan.onHand && idx < dates.length; i++ ) {
				plan.segments.push( { status: 'stock', mixOn: dates[ idx ], to: dates[ Math.min( idx + perVial, dates.length ) - 1 ], doses: Math.min( perVial, dates.length - idx ) } );
				idx += perVial;
			}
			plan.covered = idx;
			if ( perVial && up.end ) {
				while ( idx < dates.length && plan.segments.length < 40 ) {
					plan.segments.push( { status: 'buy', mixOn: dates[ idx ], to: dates[ Math.min( idx + perVial, dates.length ) - 1 ], doses: Math.min( perVial, dates.length - idx ) } );
					plan.needBuy++;
					idx += perVial;
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

	/** Running low: the stock item's plan runs out within its warning window (and before the cycle ends). */
	function stockStatus( s ) {
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
				var cur = currentVial( p );
				var info = cur ? vialInfo( cur ) : null;
				if ( ! info || ! info.lastDose ) {
					return;
				}
				var up = upcomingDoseDates( p ).dates;
				var next = up[ info.dosesLeft ];
				if ( ! next || next <= today ) {
					return;
				}
				var have = stockFor( p ).reduce( function ( n, x ) {
					return n + stockLeft( x );
				}, 0 );
				var what = p.device === 'pen' ? 'pen' : 'vial';
				out.push( {
					id: 'mix-' + labelKey( p.compound ).slice( 0, 30 ) + '-' + next.replace( /-/g, '' ),
					date: addDays( next, -1 ),
					time: '19:00',
					title: 'Mix a new ' + p.compound + ' ' + what + ' tomorrow',
					body: 'Your next dose needs a new ' + what + '.' + ( have ? ' You have ' + have + ' on hand.' : ' You have none on hand.' ),
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
					h( 'div', { style: { marginTop: '8px' } }, h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--white', onclick: function () {
						openStockSheet( x.s, { bought: true } );
					} }, 'I bought more' ) ) )
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
				h( 'div', { class: 'ypt-vial__img', 'aria-hidden': 'true' }, h( 'i', { style: { height: 'calc(' + info.pct + '% - 8px)', background: color, minHeight: info.pct > 0 ? '4px' : '0' } } ) ),
				h( 'div', { style: { flex: '1', minWidth: '0' } },
					h( 'h2', null, v.compound + ( v.mode !== 'conc' && ! isBlend( v ) && +v.amount ? ' · ' + fmtNum( +v.amount ) + ( v.mode === 'iu' ? ' IU' : ' mg' ) : '' ) ),
					h( 'div', { class: 'ypt-muted ypt-small', style: { marginTop: '2px' } }, detail.join( ' · ' ) ),
					h( 'div', { class: 'ypt-bar' }, h( 'i', { style: { width: info.pct + '%', background: color } } ) ),
					h( 'div', { class: 'ypt-small' }, status ),
					vialSupplyTag( v ),
					info.age > VIAL_WARN_DAYS ? h( 'div', { class: 'ypt-small', style: { color: 'var(--ypt-warn)', marginTop: '4px' } }, 'Mixed ' + info.age + ' days ago. Check the storage guidance for this peptide.' ) : null,
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
		return h( 'span', { class: 'ypt-mini ypt-mini--' + ( s.form === 'count' ? 'bottle' : s.form === 'pen' ? 'pen' : 'vial' ), 'aria-hidden': 'true' } );
	}

	function renderOnHand() {
		var wrap = h( 'div', null );
		var list = stocks();
		wrap.appendChild( h( 'p', { class: 'ypt-muted ypt-small', style: { margin: '2px 4px 10px' } }, 'Unmixed vials, pens and pills you have at home. Mixing one takes it off this list.' ) );
		if ( ! list.length ) {
			wrap.appendChild( h( 'div', { class: 'ypt-card ypt-empty' },
				h( 'h2', null, 'Keep track of what you have' ),
				h( 'p', null, 'Add the vials, pens or pills you have at home. We’ll show how long they’ll last on your schedule and remind you before you run out.' ),
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
				} ) : h( 'div', { class: 'ypt-stock__count' }, h( 'b', null, fmtNum( left, 0 ) ), h( 'span', null, countUnit( s, left ) ) )
			);
		} ) ) );
		var warnsOn = list.some( function ( s ) {
			return s.warn == null || +s.warn > 0;
		} );
		wrap.appendChild( h( 'div', { class: 'ypt-card ypt-note' }, icon( 'bell' ),
			h( 'div', null, warnsOn
				? 'We’ll warn you here and on Today before anything runs out' + ( state.pushOnHere ? ', with a notification too.' : '. Turn on reminders on the Me tab to get a notification too.' )
				: 'Running-low warnings are off for everything here.',
			list.some( function ( s ) {
				return ! isVialStock( s );
			} ) ? ' Pills count down each time you tap Take.' : '' ) ) );
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
					sub = 'Mixed ' + fmtDay( sg.mixOn ).replace( /^\w+, /, '' ) + ( sg.to ? ' · runs out ' + fmtDay( sg.to ).replace( /^\w+, /, '' ) : ' · used up' );
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

		var s = state.records.settings.me || {};
		if ( usesVial( p ) ) {
			var on = s.mixReminders !== false;
			var sw = h( 'button', { type: 'button', class: 'ypt-switch', role: 'switch', 'aria-checked': on ? 'true' : 'false', 'aria-label': 'Remind me on mix days' } );
			sw.addEventListener( 'click', function () {
				put( 'settings', 'me', Object.assign( {}, state.records.settings.me || {}, { mixReminders: ! on } ) );
			} );
			wrap.appendChild( h( 'div', { class: 'ypt-card', style: { padding: '2px 14px' } }, h( 'div', { class: 'ypt-toggle' },
				h( 'div', null, h( 'b', null, 'Remind me on mix days' ), h( 'div', { class: 'ypt-muted ypt-small' }, state.pushOnHere ? 'A notification the evening before.' : 'A notification the evening before, once reminders are on (Me tab).' ) ),
				sw ) ) );
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
			compound: opts.compound || '',
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
			return existing ? left + adding : +s.count || 0;
		}

		var saveBtn;

		function saveLabel() {
			return existing ? ( adding ? 'Add ' + adding : 'Save' ) : 'Add ' + ( +s.count || 0 );
		}

		function renderPreview() {
			if ( saveBtn ) {
				saveBtn.textContent = saveLabel();
			}
			preview.textContent = '';
			if ( ! s.compound ) {
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

		var body = [
			field( 'Name', compoundInput( 'ypt-s-compound', s.compound, function ( v ) {
				s.compound = v;
				renderPreview();
			} ), null, 'ypt-s-compound' ),
			h( 'div', { class: 'ypt-field' },
				h( 'span', { class: 'ypt-label' }, 'What is it?' ),
				seg( STOCK_FORMS, s.form, function ( f ) {
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
				// Same thing added again (another order of the same vials): add to that line instead of a second one.
				var twin = existing ? null : stocks().filter( function ( x ) {
					return sameCompound( x.compound, data.compound ) && x.form === data.form && +x.amount === data.amount && +x.conc === data.conc && ( x.countUnit || '' ) === data.countUnit;
				} )[ 0 ];
				closeSheet();
				if ( twin ) {
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

	/** One row per peptide + strength: open vials ticked, a few recently finished ones offered unticked. */
	function labelRows() {
		var rows = [];
		var qty = ( CFG.qtyPresets || [] )[ 0 ] || 10;
		var finished = vials( true ).filter( function ( v ) {
			return v.finished;
		} );
		vials( false ).concat( finished ).forEach( function ( v ) {
			var strength = vialStrength( v );
			var key = labelKey( v.compound ) + '|' + labelKey( strength );
			if ( rows.some( function ( r ) {
				return r.key === key;
			} ) ) {
				return;
			}
			if ( v.finished && rows.filter( function ( r ) {
				return ! r.open;
			} ).length >= 5 ) {
				return;
			}
			rows.push( { key: key, compound: v.compound, strength: strength, qty: qty, on: ! v.finished, open: ! v.finished, values: null } );
		} );
		return rows;
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

	function openLabelOrder() {
		if ( state.offline ) {
			toast( 'Connect to the internet to order labels.' );
			return;
		}
		var rows = labelRows();
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
			body.appendChild( h( 'p', { class: 'ypt-muted ypt-small ypt-lo-lead' }, 'Each vial gets its own label with its peptide and strength filled in.' ) );

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
						h( 'span', { class: 'ypt-muted ypt-small' }, [ r.strength || 'Strength not set', r.open ? '' : 'finished' ].filter( Boolean ).join( ' · ' ) )
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

		wrap.appendChild( h( 'div', { class: 'ypt-eyebrow ypt-section-label' }, 'Reminders' ) );
		wrap.appendChild( remindersCard( s ) );

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
					h( 'span', null, p.paused ? 'Paused' : amountLabel( p.dose, p.unit ) + ' · ' + scheduleLabel( p ) )
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
			)
		) );

		wrap.appendChild( h( 'p', { class: 'ypt-muted ypt-small', style: { margin: '16px 4px 0' } },
			'Dose Tracker is a personal log and reminder tool. It isn’t medical advice. Talk to a qualified provider about any peptide, medication, dose or schedule.' ) );

		return wrap;
	}

	function remindersCard( s ) {
		var supported = NATIVE || ( 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window );
		var card = h( 'div', { class: 'ypt-card' } );
		var errBox = h( 'p', { class: 'ypt-error', hidden: true } );

		if ( ! supported ) {
			card.appendChild( h( 'p', { class: 'ypt-small' }, isIOS() && ! isStandalone()
				? 'On iPhone, reminders work once Dose Tracker is on your Home Screen. Tap the Share button, then “Add to Home Screen”, and open it from there.'
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
				slot.names.push( p.compound );
				slot.lines.push( ( p.compound + ' ' + ( +p.dose > 0 ? amountLabel( p.dose, p.unit ) + ( p.doseOf ? ' ' + p.doseOf : '' ) : '' ) ).trim() );
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
		supplyAlerts().forEach( function ( a ) {
			var at = Date.parse( wallToIso( a.date, a.time ) );
			if ( at <= now ) {
				return;
			}
			var mix = a.id.indexOf( 'mix-' ) === 0;
			out.push( {
				tag: 'yp-supply-' + a.id,
				at: at,
				title: names ? a.title : ( mix ? 'Time to mix a new vial' : 'Your supply is running low' ),
				body: names ? a.body : ( mix ? 'Your next dose needs a new vial or pen.' : 'Open the tracker to see what to reorder.' ),
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
		var id = draft && draft.id ? draft.id : existing ? existing.id : uid( 'p' );
		var err = h( 'p', { class: 'ypt-error', hidden: true } );
		var vialBox = h( 'div', null );
		var schedDetail = h( 'div', null );
		var timesBox = h( 'div', { class: 'ypt-times' } );
		var drawBox = h( 'div', null );
		var doseOfBox = h( 'div', null );
		var unitBox = h( 'div', { style: { flex: '1 1 auto', minWidth: '0' } } );
		var routeSelect;
		var vialField = h( 'div', { class: 'ypt-field' }, h( 'span', { class: 'ypt-label' }, 'Vial' ), vialBox );
		var routeTouched = !! existing;
		var deviceField = h( 'div', { class: 'ypt-field' } );
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
			renderUnits();
			renderVial();
			renderDraw();
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
			} ) );
			doseInput.placeholder = isInjected( p.route ) ? '250' : '1';
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

		function saveProtocol() {
			var dose = parseFloat( p.dose );
			err.hidden = true;
			var problem = ! p.compound ? 'Enter what you’re taking.'
				: ! ( dose > 0 ) ? 'Enter your dose.'
				: p.schedule.type === 'weekdays' && ! p.schedule.days.length ? 'Pick at least one day.'
				: ! p.start ? 'Pick a start date.'
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
			};
			closeSheet();
			put( 'protocol', id, data );
			toast( existing ? 'Saved' : data.compound + ' added' );
		}

		var doseInput = h( 'input', { class: 'ypt-input', id: 'ypt-p-dose', type: 'number', inputmode: 'decimal', min: '0', step: 'any', value: p.dose, placeholder: '250', oninput: function ( e ) {
			p.dose = e.target.value;
			renderDraw();
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
			h( 'div', { class: 'ypt-row' },
				field( 'Start', h( 'input', { class: 'ypt-input', id: 'ypt-p-start', type: 'date', value: p.start, onchange: function ( e ) {
					p.start = e.target.value;
				} } ), null, 'ypt-p-start' ),
				field( 'Length (weeks)', h( 'input', { class: 'ypt-input', id: 'ypt-p-weeks', type: 'number', inputmode: 'numeric', min: '0', max: '520', placeholder: 'Ongoing', value: p.weeks || '', oninput: function ( e ) {
					p.weeks = e.target.value;
				} } ), null, 'ypt-p-weeks' )
			),
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
			field( 'Notes', h( 'textarea', { class: 'ypt-textarea', id: 'ypt-p-notes', maxlength: '500', placeholder: 'Injection site rotation, with food, fasted, etc.', oninput: function ( e ) {
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
		renderDevice();
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
			[ 'kind', 'mode', 'amount', 'water', 'conc', 'volume', 'parts', 'blend', 'syringe' ].forEach( function ( k ) {
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
		var calc = { dose: opts.dose || '', unit: opts.unit || 'mcg', doseOf: '' };
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

		function renderStockBox() {
			stockBox.textContent = '';
			var st = existing ? null : stockToMix( v );
			if ( ! st ) {
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
			} } ), null, 'ypt-v-mixed' ) );
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

		openSheet( existing ? ( isPen( v ) ? 'Edit pen' : 'Edit vial' ) : opts.kind === 'pen' ? 'Mix a pen' : 'Mix a vial or pen', 'Vial & pen calculator', body,
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
		var body = [
			h( 'p', { class: 'ypt-muted' }, amountLabel( d.dose, d.unit ) + ' · ' + fmtDay( d.date ) ),
			h( 'div', { class: 'ypt-field' },
				h( 'span', { class: 'ypt-label' }, 'Status' ),
				seg( [ [ 'taken', 'Taken' ], [ 'skipped', 'Skipped' ] ], d.status, function ( s ) {
					d.status = s;
				} )
			),
			field( 'Time taken', h( 'input', { class: 'ypt-input', id: 'ypt-l-time', type: 'time', value: timeVal, onchange: function ( e ) {
				timeVal = e.target.value || timeVal;
			} } ), null, 'ypt-l-time' ),
			field( 'Note', h( 'textarea', { class: 'ypt-textarea', id: 'ypt-l-note', maxlength: '500', placeholder: 'Injection site, how you felt…', oninput: function ( e ) {
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
				}
				closeSheet();
				put( 'dose', id, d );
			} }, 'Save' ) );
	}

	/* ---------- Extra (unscheduled) dose ---------- */

	function openExtraSheet( date ) {
		var list = protocols();
		var d = { compound: list[ 0 ] ? list[ 0 ].compound : '', dose: list[ 0 ] ? list[ 0 ].dose : '', unit: list[ 0 ] ? list[ 0 ].unit : 'mcg', protocolId: list[ 0 ] ? list[ 0 ].id : '', note: '' };
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
					d.dose = p.dose;
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
				vialId: v ? v.id : '',
				units: units != null ? Math.round( units * 100 ) / 100 : null,
				note: d.note || '',
			} );
			toast( 'Extra dose logged' );
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
				state.records = { protocol: {}, dose: {}, vial: {}, stock: {}, settings: {} };
				state.shares = [];
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
			h( 'p', null, 'This permanently erases every peptide, medication, dose, vial and note in your tracker, plus your encryption key. Your YeffoDesign account and orders aren’t affected.' ),
			field( 'Type DELETE to confirm', h( 'input', { class: 'ypt-input', id: 'ypt-del', type: 'text', autocapitalize: 'characters', autocomplete: 'off', oninput: function ( e ) {
				typed = e.target.value.trim().toUpperCase();
				btn.disabled = typed !== 'DELETE';
			} } ), null, 'ypt-del' ),
		], btn );
	}

	/* =========================================================
	 * Signed-out / not-ready screens
	 * ======================================================= */

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
					'New here? ', h( 'a', { href: CFG.registerUrl }, 'Create a free account' ), ', then open the Dose Tracker and it’ll be waiting.' ) );
				root.appendChild( h( 'div', { class: 'ypt-banner ypt-banner--info', style: { marginTop: '16px' } }, h( 'div', null, 'Doses here come from another person, not from YeffoDesign. Check them with your provider.' ) ) );
			}
			root.appendChild( h( 'div', { class: 'ypt-eyebrow ypt-section-label', style: { marginTop: '28px' } }, 'About the Dose Tracker' ) );
		} else {
			root.appendChild( h( 'div', { class: 'ypt-hero' },
			h( 'a', { class: 'ypt-brand', href: CFG.homeUrl }, h( 'img', { src: iconUrl( 'icon-192.png' ), alt: '' } ), 'YeffoDesign' ),
			h( 'div', { class: 'ypt-eyebrow', style: { marginTop: '28px' } }, 'Free for customers' ),
			h( 'h1', null, 'Dose Tracker' ),
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
			'A personal log and reminder tool, not medical advice.' ) );
	}

	function iconUrl( file ) {
		var link = document.querySelector( 'link[rel="icon"]' );
		return link ? link.href.replace( /[^/]+$/, file ) : '';
	}

	/* =========================================================
	 * Boot
	 * ======================================================= */

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
		if ( CFG.share && CFG.share.protocol ) {
			// Signing up goes through My Account, not back here: remember the link so the tracker offers it next time.
			saveJSON( PENDING_SHARE_KEY, CFG.share );
		}
		renderSignedOut();
		return;
	}
	if ( ! CFG.ready ) {
		renderMessage( 'Almost ready', 'The Dose Tracker is being set up on our end. Please check back soon.', false );
		return;
	}

	// Only this account's copy stays on the device: another customer who
	// signed in on this browser earlier shouldn't leave theirs behind.
	try {
		Object.keys( window.localStorage ).forEach( function ( k ) {
			if ( ( k.indexOf( 'ypt:' ) === 0 && k !== STORE_KEY ) || ( k.indexOf( 'ypt-q:' ) === 0 && k !== QUEUE_KEY ) || ( k.indexOf( 'ypt-native-reminders:' ) === 0 && k !== NATIVE_KEY ) ) {
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
		}
	} );
}() );
