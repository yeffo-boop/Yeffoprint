/**
 * Dose Tracker app (/tracker/) — see includes/tracker/class-tracker-app.php.
 *
 * Vanilla JS, no build step, same as the theme's scripts. Everything the
 * customer enters goes through the encrypted REST API
 * (rest/class-tracker-controller.php); this file keeps a local copy so
 * the app opens instantly and works offline, queueing changes until the
 * connection is back. Records:
 *
 *   protocol  { compound, dose, unit, route, device:'syringe'|'pen'|'single', schedule:{type,days,every,on,off}, times[], start, weeks, color, notes, paused }
 *   dose      { protocolId, compound, date, time, status:'taken'|'skipped', at, dose, unit, vialId, units, note }
 *   vial      { kind:'vial'|'pen', compound, mode:'mg'|'iu'|'conc', amount, water, conc, volume, mixed, syringe, finished }
 *             A pen is a 3 mL cartridge the customer mixes like a vial; its dial is read as U-100 units (0.01 mL each).
 *   settings  { tz, reminders }
 *
 * The Mix a vial calculator is the same math as the Peptide & Hormone
 * Calculator page (theme assets/js/peptide-calculator.js):
 *   powder:   concentration = vial amount ÷ water mL;  volume = dose ÷ concentration
 *   premixed: volume = dose mg ÷ (mg/mL on the label)
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
		records: { protocol: {}, dose: {}, vial: {}, settings: {} },
		push: { publicKey: '', devices: 0 },
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
		return dateStr( new Date() );
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
		var d = new Date();
		return pad( d.getHours() ) + ':' + pad( d.getMinutes() );
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
		var d = new Date( iso );
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
		return window.matchMedia( '(display-mode: standalone)' ).matches || window.navigator.standalone === true;
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

	/** Syringe units for one dose from this vial, or null when the units don't line up (e.g. an IU dose from an mg vial). */
	function unitsForDose( dose, unit, v ) {
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
		var per = p ? unitsForDose( p.dose, p.unit, v ) : null;
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
		saveJSON( STORE_KEY, { records: state.records, push: state.push, savedAt: Date.now() } );
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
			state.records = Object.assign( { protocol: {}, dose: {}, vial: {}, settings: {} }, cached.records );
			state.push = cached.push || state.push;
			state.loaded = true;
			render();
		}

		return api( 'GET', 'tracker/state' ).then( function ( json ) {
			var fresh = json.records || {};
			[ 'protocol', 'dose', 'vial', 'settings' ].forEach( function ( k ) {
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
		var tz = '';
		try {
			tz = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
		} catch ( e ) {}
		var s = state.records.settings.me || {};
		if ( tz && s.tz !== tz ) {
			put( 'settings', 'me', Object.assign( { reminders: true }, s, { tz: tz } ) );
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
		var units = v ? unitsForDose( p.dose, p.unit, v ) : ( p.unit === 'units' ? +p.dose : null );
		var at = new Date();
		// Logging a past day's dose: stamp it at its scheduled time.
		if ( slot.date !== todayStr() ) {
			var d = parseDate( slot.date );
			var t = slot.time.split( ':' );
			d.setHours( +t[ 0 ], +t[ 1 ] );
			at = d;
		}
		put( 'dose', slot.id, {
			protocolId: p.id,
			compound: p.compound,
			date: slot.date,
			time: slot.time,
			status: status,
			at: at.toISOString(),
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
		var screens = { today: renderToday, history: renderHistory, vials: renderVials, me: renderMe };
		root.appendChild( ( screens[ ui.tab ] || renderToday )() );
		root.appendChild( lockLine() );
		root.appendChild( renderTabs() );
		if ( ui.sheet ) {
			root.appendChild( ui.sheet.node );
		}
		window.scrollTo( 0, scrollY );
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
				tab( 'vials', 'Vials', 'vials' ),
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
			var label = group + ' · ' + fmtTime( s.time );
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
		var units = log && log.units != null ? +log.units : ( v ? unitsForDose( p.dose, p.unit, v ) : null );
		var how = p.device === 'pen' ? 'pen' : p.device === 'single' ? 'single-use injector' : p.route ? routeInfo( p.route ).short || p.route.toLowerCase() : '';
		var sub = [ amountLabel( p.dose, p.unit ), how, scheduleLabel( p ), weekLabel( p, s.date ) ].filter( Boolean ).join( ' · ' );

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

		if ( ! log || log.status === 'taken' ) {
			if ( units != null && isFinite( units ) ) {
				var info = v ? vialInfo( v ) : null;
				card.appendChild( h( 'div', { class: 'ypt-draw' + ( info && info.age > VIAL_WARN_DAYS ? ' ypt-draw--warn' : '' ) },
					isPen( v ) ? [ log ? 'Dialed ' : 'Dial ', h( 'b', null, fmtNum( units, 1 ) + ' units' ), ' on your pen' ] : [ log ? 'Drew ' : 'Draw ', h( 'b', null, fmtNum( units, 1 ) + ' units' ), ' on a U-100 syringe' ],
					v ? ' · ' + ( isPen( v ) ? 'pen' : 'vial' ) + ' mixed ' + fmtDay( v.mixed ).replace( /^\w+, /, '' ) : '',
					info && info.age > VIAL_WARN_DAYS ? ' · ' + info.age + ' days old' : ''
				) );
			} else if ( ! log && ( p.unit === 'mcg' || p.unit === 'mg' || p.unit === 'IU' ) && usesVial( p ) ) {
				card.appendChild( h( 'button', { type: 'button', class: 'ypt-draw', style: { width: '100%', textAlign: 'left' }, onclick: function () {
					openVialSheet( null, { compound: p.compound, dose: p.dose, unit: p.unit, kind: p.device === 'pen' ? 'pen' : 'vial' } );
				} }, p.device === 'pen' ? 'Add your pen to see how many units to dial →' : 'Add your vial to see how many units to draw →' ) );
			}
		}
		return card;
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
				// Spreadsheet formula injection guard.
				if ( /^[=+\-@]/.test( s ) ) {
					s = '\'' + s;
				}
				return /[",\n]/.test( s ) ? '"' + s.replace( /"/g, '""' ) + '"' : s;
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

	/* ---------- Vials ---------- */

	function renderVials() {
		var wrap = h( 'div', null );
		wrap.appendChild( h( 'header', { class: 'ypt-top' },
			h( 'div', null, h( 'div', { class: 'ypt-eyebrow' }, 'Inventory' ), h( 'h1', null, 'Vials & pens' ) ),
			h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--primary', onclick: function () {
				openVialSheet( null, null );
			} }, '+ Mix' )
		) );

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
			} else {
				detail.push( fmtNum( +v.water ) + ' mL water' );
			}
			if ( isPen( v ) ) {
				detail.unshift( 'Pen' );
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
					h( 'h2', null, v.compound + ( v.mode !== 'conc' && +v.amount ? ' · ' + fmtNum( +v.amount ) + ( v.mode === 'iu' ? ' IU' : ' mg' ) : '' ) ),
					h( 'div', { class: 'ypt-muted ypt-small', style: { marginTop: '2px' } }, detail.join( ' · ' ) ),
					h( 'div', { class: 'ypt-bar' }, h( 'i', { style: { width: info.pct + '%', background: color } } ) ),
					h( 'div', { class: 'ypt-small' }, status ),
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

	/** What prints as a vial's Strength: "5 mg", "5000 IU", or "10 mg/mL" for a premixed vial. */
	function vialStrength( v ) {
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
			var wrap = h( 'div', { class: 'ypt-lo-preview' } );
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
				} }, 'Sign out' ),
				h( 'button', { type: 'button', class: 'ypt-btn ypt-btn--danger', onclick: confirmDeleteAll }, 'Delete my data' )
			)
		) );

		wrap.appendChild( h( 'p', { class: 'ypt-muted ypt-small', style: { margin: '16px 4px 0' } },
			'Dose Tracker is a personal log and reminder tool. It isn’t medical advice. Talk to a qualified provider about any peptide, medication, dose or schedule.' ) );

		return wrap;
	}

	function remindersCard( s ) {
		var supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
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
			( on ? disablePush() : enablePush() ).then( function () {
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
		if ( on ) {
			card.appendChild( h( 'button', { type: 'button', class: 'ypt-link', onclick: function () {
				api( 'POST', 'tracker/push/test' ).then( function () {
					toast( 'Test reminder sent' );
				} ).catch( function ( e ) {
					toast( e.message || 'Couldn’t send a test.' );
				} );
			} }, 'Send a test reminder' ) );
		}
		if ( state.push.devices > ( on ? 1 : 0 ) ) {
			card.appendChild( h( 'p', { class: 'ypt-muted ypt-small', style: { marginTop: '8px' } }, 'Also on for ' + ( state.push.devices - ( on ? 1 : 0 ) ) + ' other device' + ( state.push.devices - ( on ? 1 : 0 ) === 1 ? '' : 's' ) + '.' ) );
		}
		if ( s.tz ) {
			card.appendChild( h( 'p', { class: 'ypt-muted ypt-small', style: { marginTop: '8px' } }, 'Times are in ' + s.tz.replace( /_/g, ' ' ) + '.' ) );
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
		if ( ! ( 'serviceWorker' in navigator ) || ! ( 'PushManager' in window ) ) {
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
			schedule: { type: 'daily', days: [ new Date().getDay() ], every: 2, on: 5, off: 2 },
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
		p.schedule = Object.assign( { type: 'daily', days: [ new Date().getDay() ], every: 2, on: 5, off: 2 }, p.schedule || {} );
		var id = draft && draft.id ? draft.id : existing ? existing.id : uid( 'p' );
		var err = h( 'p', { class: 'ypt-error', hidden: true } );
		var vialBox = h( 'div', null );
		var schedDetail = h( 'div', null );
		var timesBox = h( 'div', { class: 'ypt-times' } );
		var drawBox = h( 'div', null );
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
						h( 'b', null, v.mode === 'conc' ? fmtNum( +v.conc ) + ' mg/mL · ' + fmtNum( +v.volume ) + ' mL' : fmtNum( +v.amount ) + ( v.mode === 'iu' ? ' IU' : ' mg' ) + ' + ' + fmtNum( +v.water ) + ' mL water' ),
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

		function renderDraw() {
			drawBox.textContent = '';
			var v = p.compound ? currentVial( p ) : null;
			if ( ! v || ! ( parseFloat( p.dose ) > 0 ) ) {
				return;
			}
			var u = unitsForDose( p.dose, p.unit, v );
			drawBox.appendChild( h( 'div', { class: 'ypt-draw' },
				u != null ? ( isPen( v ) ? [ 'Dial ', h( 'b', null, fmtNum( u, 1 ) + ' units' ), ' on your pen for each dose.' ] : [ 'Draw ', h( 'b', null, fmtNum( u, 1 ) + ' units' ), ' on a U-100 syringe for each dose.' ] )
					: 'Your ' + ( isPen( v ) ? 'pen' : 'vial' ) + ' is measured in ' + ( vialConcentration( v ) || {} ).unit + ', so pick that unit for your dose.' ) );
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
	 * and onSaved(vial) — set when Add a peptide sent the customer here
	 * first, so saving goes straight back to it.
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
		v.kind = v.kind === 'pen' ? 'pen' : 'vial';
		var id = existing ? existing.id : uid( 'v' );
		var p = null;
		var calc = { dose: opts.dose || '', unit: opts.unit || 'mcg' };
		if ( v.compound && ! opts.dose ) {
			p = protocols().filter( function ( x ) {
				return sameCompound( x.compound, v.compound );
			} )[ 0 ] || null;
			if ( p ) {
				calc = { dose: p.dose, unit: p.unit };
			}
		}
		var err = h( 'p', { class: 'ypt-error', hidden: true } );
		var inputs = h( 'div', null );
		var strength = h( 'div', { 'aria-live': 'polite' } );
		var result = h( 'div', { class: 'ypt-calc-result', 'aria-live': 'polite' } );

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

		function renderInputs() {
			inputs.textContent = '';
			var amountUnit = v.mode === 'iu' ? 'IU' : 'mg';
			var pen = v.kind === 'pen';
			var where = pen ? 'pen' : 'vial';
			if ( v.mode === 'conc' ) {
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
		}

		function renderResult() {
			renderStrength();
			result.textContent = '';
			var units = unitsForDose( calc.dose, calc.unit, v );
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
				seg( [ [ 'mg', 'Peptide (mg)' ], [ 'iu', 'HGH / HCG (IU)' ], [ 'conc', 'Premixed' ] ], v.mode, function ( m ) {
					v.mode = m;
					renderInputs();
					renderResult();
				} )
			),
			field( 'Peptide', compoundInput( 'ypt-v-compound', v.compound, function ( name ) {
				v.compound = name;
				var match = protocols().filter( function ( x ) {
					return sameCompound( x.compound, name );
				} )[ 0 ];
				if ( match && ! opts.dose ) {
					p = match;
					calc.dose = match.dose;
					calc.unit = match.unit;
					renderInputs();
					renderResult();
				}
			} ), null, 'ypt-v-compound' ),
			inputs,
			result,
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
				var problem = ! v.compound ? 'Enter the peptide’s name.'
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
				put( 'vial', id, saved );
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
		var at = new Date( d.at || Date.now() );
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
				var t = timeVal.split( ':' );
				var when = parseDate( d.date );
				when.setHours( +t[ 0 ], +t[ 1 ] );
				d.at = when.toISOString();
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
			var units = v ? unitsForDose( d.dose, d.unit, v ) : null;
			var t = timeVal.split( ':' );
			var when = parseDate( date );
			when.setHours( +t[ 0 ], +t[ 1 ] );
			closeSheet();
			put( 'dose', uid( 'x-' ), {
				protocolId: p ? d.protocolId : '',
				compound: d.compound.trim(),
				date: date,
				time: timeVal,
				status: 'taken',
				at: when.toISOString(),
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
				return disablePush().catch( function () {} );
			} ).then( function () {
				state.records = { protocol: {}, dose: {}, vial: {}, settings: {} };
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
		root.textContent = '';
		function feature( ic, title, text ) {
			return h( 'li', null, icon( ic ), h( 'div', null, h( 'b', null, title ), text ) );
		}
		root.appendChild( h( 'div', { class: 'ypt-hero' },
			h( 'a', { class: 'ypt-brand', href: CFG.homeUrl }, h( 'img', { src: iconUrl( 'icon-192.png' ), alt: '' } ), 'YeffoDesign' ),
			h( 'div', { class: 'ypt-eyebrow', style: { marginTop: '28px' } }, 'Free for customers' ),
			h( 'h1', null, 'Dose Tracker' ),
			h( 'p', null, 'Log every peptide and medication dose, see exactly how many units to draw, and get a reminder when it’s time.' )
		) );
		root.appendChild( h( 'ul', { class: 'ypt-features' },
			feature( 'check', 'Today’s doses at a glance', 'Tap Take or Skip. Daily, weekly, every few days, or on/off cycles.' ),
			feature( 'calc', 'Built-in vial calculator', 'Enter your vial and water once; every dose shows the units to draw.' ),
			feature( 'bell', 'Reminders', 'A notification on your phone when a dose is due.' ),
			feature( 'lock', 'Private and encrypted', 'Your entries are encrypted with a key only your account unlocks.' )
		) );
		root.appendChild( h( 'a', { class: 'ypt-btn ypt-btn--primary ypt-btn--block', href: CFG.loginUrl }, 'Sign in to start' ) );
		root.appendChild( h( 'p', { class: 'ypt-muted', style: { textAlign: 'center', marginTop: '14px' } },
			'New here? ', h( 'a', { href: CFG.registerUrl }, 'Create a free account' ) ) );
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
		renderSignedOut();
		return;
	}
	if ( ! CFG.ready ) {
		renderMessage( 'Almost ready', 'The Dose Tracker is being set up on our end. Please check back soon.', false );
		return;
	}

	var savedUi = loadJSON( UI_KEY, null );
	if ( savedUi && savedUi.tab && savedUi.tab !== 'today' ) {
		ui.tab = savedUi.tab;
	}
	load();
}() );
