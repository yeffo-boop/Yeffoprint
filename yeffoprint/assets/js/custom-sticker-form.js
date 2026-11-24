/**
 * Sticker Designer — the Custom Stickers page (templates/custom-stickers-form.html).
 *
 * The customer uploads art and sees it as a sticker right away: the cut
 * line is traced around the art on a canvas (the art's alpha is grown by
 * the border width, holes filled, edges smoothed), or it sits inside a
 * circle/square/rounded/oval shape. They pick the cut type (die-cut,
 * kiss-cut, sticker sheet), border, size, material (glossy, matte,
 * holographic, prism, clear, metallic — the light moves with the pointer)
 * and quantity, with the price on every option.
 *
 * Same endpoints as before: custom-stickers/options, custom-orders/uploads
 * (the art file, then a PNG of the finished preview), custom-stickers
 * (adds the line to the cart, with the designer's choices under
 * `design`). Prices here are a preview; the cart prices server-side
 * (YeffoPrint_Sticker_Pricing::calculate()).
 *
 * After a sticker is added the page stays put and opens the cart drawer,
 * so the customer can upload the next design.
 */
( function () {
	'use strict';

	if ( typeof yeffoprintCustomSticker === 'undefined' ) {
		return;
	}

	var root = document.getElementById( 'yp-custom-sticker' );
	if ( ! root ) {
		return;
	}

	var cfg = yeffoprintCustomSticker;
	var $ = function ( sel ) { return root.querySelector( sel ); };
	var $$ = function ( sel ) { return Array.prototype.slice.call( root.querySelectorAll( sel ) ); };

	var statusEl = $( '.yp-configurator__status' );
	var form = document.getElementById( 'yp-custom-sticker-form' );
	var stage = $( '[data-sd-stage]' );
	var cv = $( '[data-sd-canvas]' );
	var ctx = cv.getContext( '2d' );
	var fileInput = document.getElementById( 'yp-sd-file' );
	var submitButton = $( '[data-yp-cs-submit]' );
	var instructionsEl = document.getElementById( 'yp-cs-instructions' );

	/* ---------- options from the server ---------- */
	var OPT = { sizes: [], materials: [], types: {}, shapes: {}, presets: [], pricing: {} };

	var TYPE_INFO = {
		die_cut: { t: 'Die-cut', d: 'Cut through the backing too. Each sticker is its own shape.', svg: '<path d="M30 8c14-6 30 2 34 12 10-2 22 6 20 18-2 10-16 12-26 8-10 6-28 4-32-6-10-2-12-16 4-32z" fill="#fff" stroke="#141414" stroke-width="1.5"/>' },
		kiss_cut: { t: 'Kiss-cut', d: 'Sticker is cut, backing stays square. Easy to peel and hand out.', svg: '<rect x="22" y="3" width="76" height="46" rx="5" fill="#F2F0EB" stroke="#c9c5bd"/><path d="M42 12c10-4 20 1 23 8 7-1 14 4 13 12-1 7-11 8-17 5-7 4-19 3-21-4-7-1-8-11 2-21z" fill="#fff" stroke="#EC008C" stroke-width="1.3" stroke-dasharray="3 2"/>' },
		sheet: { t: 'Sticker sheet', d: 'Several stickers kiss-cut on one sheet.', svg: '<rect x="30" y="2" width="60" height="48" rx="3" fill="#F2F0EB" stroke="#c9c5bd"/><g fill="#fff" stroke="#EC008C" stroke-width="1.1" stroke-dasharray="2.5 1.8"><circle cx="46" cy="15" r="8"/><circle cx="74" cy="15" r="8"/><circle cx="46" cy="37" r="8"/><circle cx="74" cy="37" r="8"/></g>' }
	};
	var TYPE_ORDER = [ 'die_cut', 'kiss_cut', 'sheet' ];
	var SHAPE_INFO = {
		custom: { t: 'Contour', svg: '<path d="M9 6c6-4 12 0 13 4 5-1 9 3 8 9-1 6-8 7-12 5-4 4-12 3-14-2-5-2-6-10 5-16z" fill="none" stroke="currentColor" stroke-width="1.8"/>' },
		circle: { t: 'Circle', svg: '<circle cx="17" cy="17" r="13" fill="none" stroke="currentColor" stroke-width="1.8"/>' },
		square: { t: 'Square', svg: '<rect x="5" y="5" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8"/>' },
		rounded_square: { t: 'Rounded', svg: '<rect x="5" y="5" width="24" height="24" rx="7" fill="none" stroke="currentColor" stroke-width="1.8"/>' },
		oval: { t: 'Oval', svg: '<ellipse cx="17" cy="17" rx="14" ry="10" fill="none" stroke="currentColor" stroke-width="1.8"/>' }
	};
	var SHAPE_ORDER = [ 'custom', 'circle', 'square', 'rounded_square', 'oval' ];
	var FINISH_SWATCH = {
		glossy: 'linear-gradient(135deg,#fff 40%,#eef1f4 52%,#fff 64%)',
		matte: '#f7f6f3',
		holographic: 'linear-gradient(110deg,#9fdcff,#ffb3e4 30%,#fff0a6 55%,#b0ffd7 75%,#c8bcff)',
		prism: '#ccc url(' + ( cfg.prismUrl || '' ) + ') 0 0/60px',
		clear: 'repeating-conic-gradient(#e7e4de 0 25%,#fff 0 50%) 0 0/10px 10px',
		metallic: 'linear-gradient(120deg,#c3c7cd,#f2f3f5 35%,#a9aeb5 55%,#eceef0 75%,#b4b9bf)'
	};
	var BORDERS = [ 0, 1 / 32, 1 / 16, 1 / 8, 3 / 16, 1 / 4 ];
	var BORDER_LABEL = [ 'None', '1/32″', '1/16″', '1/8″', '3/16″', '1/4″' ];

	/* ---------- state ---------- */
	var S = {
		type: 'die_cut', shape: 'custom', border: 3, radius: 16,
		sizeId: 0, customLong: 4, materialId: 0, qty: 50, otherQty: 75, isOther: false,
		view: 'close', cutline: true, dark: false, removeBg: true, underlay: true,
		tilt: 0.35, hover: false
	};
	var art = null;     // { img, name, pxW, pxH, hadBg, vector, uploadId, uploading, error }
	var built = null;   // geometry for the current options
	var face = null;    // body + art without the moving light
	var prismImg = new Image();
	if ( cfg.prismUrl ) {
		prismImg.onload = function () { draw(); };
		prismImg.src = cfg.prismUrl;
	}

	/* ---------- helpers ---------- */
	function esc( v ) { var d = document.createElement( 'div' ); d.textContent = v == null ? '' : String( v ); return d.innerHTML; }
	function money( n ) { return '$' + n.toFixed( 2 ); }
	function money3( n ) { var s = ( Math.round( n * 1000 ) / 1000 ).toFixed( 3 ); return '$' + ( '0' === s.slice( -1 ) ? n.toFixed( 2 ) : s ); }
	function fmtIn( v ) { return ( Math.round( v * 100 ) / 100 ).toFixed( 2 ).replace( /0$/, '' ).replace( /\.0$/, '.0' ) + '″'; }
	function dpr() { return Math.min( 2, window.devicePixelRatio || 1 ); }
	function mk( w, h ) { var c = document.createElement( 'canvas' ); c.width = Math.max( 1, Math.round( w ) ); c.height = Math.max( 1, Math.round( h ) ); return c; }
	function rr( x, X, Y, W, H, r ) { x.beginPath(); if ( x.roundRect ) { x.roundRect( X, Y, W, H, r ); } else { x.rect( X, Y, W, H ); } }

	function size() { return OPT.sizes.filter( function ( s ) { return s.id === S.sizeId; } )[ 0 ] || null; }
	function material() { return OPT.materials.filter( function ( m ) { return m.id === S.materialId; } )[ 0 ] || null; }
	function finish() { var m = material(); return m ? m.finish : 'glossy'; }
	function qty() { return S.isOther ? S.otherQty : S.qty; }

	/** The box the sticker has to fit in, in inches ([w, h]). */
	function sizeBox() {
		var sz = size();
		if ( ! sz ) { return [ 3, 3 ]; }
		if ( sz.is_custom ) { var L = Math.max( 0.5, Math.min( 24, +S.customLong || 1 ) ); return [ L, L ]; }
		return [ sz.width_in || 3, sz.height_in || sz.width_in || 3 ];
	}

	/* ---------- pricing (mirrors YeffoPrint_Sticker_Pricing::calculate) ---------- */
	function sizeBase() {
		var sz = size();
		if ( ! sz ) { return 0; }
		if ( sz.is_custom ) {
			var w = built ? built.inW : sizeBox()[ 0 ], h = built ? built.inH : sizeBox()[ 1 ];
			return ( OPT.pricing.custom_rate_per_sq_in || 0 ) * w * h;
		}
		return sz.price || 0;
	}
	function unitPrice( q ) {
		var base = sizeBase(), m = material(), p = OPT.pricing;
		var adj = ( m ? m.price_adjustment || 0 : 0 ) + ( ( p.type_adjustments || {} )[ S.type ] || 0 ) + ( ( p.shape_adjustments || {} )[ S.shape ] || 0 );
		var tier = null;
		( p.tiers || [] ).forEach( function ( t ) { if ( q >= t.threshold ) { tier = t; } } );
		var disc = 0;
		if ( tier ) { disc = 'percent' === tier.type ? base * tier.value / 100 : Math.max( 0, base - tier.value ); }
		var before = Math.max( 0, base + adj ), after = Math.max( 0, Math.max( 0, base - disc ) + adj );
		return { before: before, unit: after, tier: tier, pct: before ? Math.round( ( 1 - after / before ) * 100 ) : 0 };
	}

	/* ---------- image processing ---------- */
	// Near-white pixels connected to the edge become transparent.
	function knockOutBg( c ) {
		var x = c.getContext( '2d' ), d = x.getImageData( 0, 0, c.width, c.height ), p = d.data, W = c.width, H = c.height;
		var seen = new Uint8Array( W * H ), st = [], i, j;
		function light( k ) { return p[ k * 4 + 3 ] > 0 && p[ k * 4 ] > 235 && p[ k * 4 + 1 ] > 235 && p[ k * 4 + 2 ] > 235; }
		for ( i = 0; i < W; i++ ) { st.push( i, ( H - 1 ) * W + i ); }
		for ( j = 0; j < H; j++ ) { st.push( j * W, j * W + W - 1 ); }
		while ( st.length ) {
			var k = st.pop();
			if ( seen[ k ] ) { continue; }
			seen[ k ] = 1;
			if ( ! light( k ) ) { continue; }
			p[ k * 4 + 3 ] = 0;
			var xx = k % W, yy = ( k / W ) | 0;
			if ( xx > 0 ) { st.push( k - 1 ); }
			if ( xx < W - 1 ) { st.push( k + 1 ); }
			if ( yy > 0 ) { st.push( k - W ); }
			if ( yy < H - 1 ) { st.push( k + W ); }
		}
		x.putImageData( d, 0, 0 );
	}
	function bbox( c ) {
		var d = c.getContext( '2d' ).getImageData( 0, 0, c.width, c.height ).data, W = c.width, H = c.height, x0 = W, y0 = H, x1 = -1, y1 = -1;
		for ( var y = 0; y < H; y++ ) {
			for ( var x = 0; x < W; x++ ) {
				if ( d[ ( y * W + x ) * 4 + 3 ] > 16 ) { if ( x < x0 ) { x0 = x; } if ( x > x1 ) { x1 = x; } if ( y < y0 ) { y0 = y; } if ( y > y1 ) { y1 = y; } }
			}
		}
		return x1 < 0 ? { x: 0, y: 0, w: W, h: H } : { x: x0, y: y0, w: x1 - x0 + 1, h: y1 - y0 + 1 };
	}
	function silhouette( src ) { var c = mk( src.width, src.height ), x = c.getContext( '2d' ); x.drawImage( src, 0, 0 ); x.globalCompositeOperation = 'source-in'; x.fillStyle = '#000'; x.fillRect( 0, 0, c.width, c.height ); return c; }
	// Grow a silhouette by r px (stamped around rings of circles).
	function dilate( sil, r, pad ) {
		var c = mk( sil.width + pad * 2, sil.height + pad * 2 ), x = c.getContext( '2d' );
		x.drawImage( sil, pad, pad );
		if ( r > 0 ) {
			var rings = Math.max( 1, Math.ceil( r / 3 ) );
			for ( var k = 1; k <= rings; k++ ) {
				var rad = r * k / rings, n = Math.max( 12, Math.round( rad * 1.2 ) );
				for ( var a = 0; a < n; a++ ) { var t = a / n * Math.PI * 2; x.drawImage( sil, pad + Math.cos( t ) * rad, pad + Math.sin( t ) * rad ); }
			}
		}
		return c;
	}
	// Smooth the edge, then fill every hole so the cut is one outline.
	function solidify( c, smooth ) {
		var W = c.width, H = c.height, x = c.getContext( '2d' );
		if ( smooth ) { var b = mk( W, H ), bx = b.getContext( '2d' ); bx.filter = 'blur(' + smooth + 'px)'; bx.drawImage( c, 0, 0 ); x.clearRect( 0, 0, W, H ); x.drawImage( b, 0, 0 ); }
		var d = x.getImageData( 0, 0, W, H ), p = d.data, out = new Uint8Array( W * H ), st = [], i, j;
		for ( i = 0; i < W; i++ ) { st.push( i, ( H - 1 ) * W + i ); }
		for ( j = 0; j < H; j++ ) { st.push( j * W, j * W + W - 1 ); }
		while ( st.length ) {
			var k = st.pop();
			if ( out[ k ] || p[ k * 4 + 3 ] >= 120 ) { continue; }
			out[ k ] = 1;
			var xx = k % W, yy = ( k / W ) | 0;
			if ( xx > 0 ) { st.push( k - 1 ); }
			if ( xx < W - 1 ) { st.push( k + 1 ); }
			if ( yy > 0 ) { st.push( k - W ); }
			if ( yy < H - 1 ) { st.push( k + W ); }
		}
		for ( var q = 0; q < W * H; q++ ) { p[ q * 4 ] = p[ q * 4 + 1 ] = p[ q * 4 + 2 ] = 0; p[ q * 4 + 3 ] = out[ q ] ? 0 : 255; }
		x.putImageData( d, 0, 0 );
		return c;
	}

	/* ---------- build the sticker's geometry ---------- */
	var RES = 760; // working px for the sticker's longest side
	function rebuild() {
		if ( ! art || ! art.img ) { built = null; face = null; updateUI(); draw(); return; }
		var box = sizeBox(), bIn = BORDERS[ S.border ];
		var nw = art.img.naturalWidth || art.pxW, nh = art.img.naturalHeight || art.pxH;
		var sc = Math.min( 1, 900 / Math.max( nw, nh ) );
		var a0 = mk( nw * sc, nh * sc );
		a0.getContext( '2d' ).drawImage( art.img, 0, 0, a0.width, a0.height );
		var knocked = art.hadBg && S.removeBg;
		if ( knocked ) { knockOutBg( a0 ); }
		var bb = ( art.hadBg && ! knocked ) ? { x: 0, y: 0, w: a0.width, h: a0.height } : bbox( a0 );
		var trimmed = mk( bb.w, bb.h );
		trimmed.getContext( '2d' ).drawImage( a0, -bb.x, -bb.y );
		var ar = bb.w / bb.h, w, h, body, artRect;
		var boxLong = Math.max( box[ 0 ], box[ 1 ] ), ppi = RES / boxLong;

		if ( 'custom' === S.shape ) {
			// Fit art + border inside the size box.
			var fitW = Math.max( 0.3, box[ 0 ] - 2 * bIn ), fitH = Math.max( 0.3, box[ 1 ] - 2 * bIn );
			var artW = Math.min( fitW, fitH * ar ), artH = artW / ar;
			var aw = artW * ppi, ah = artH * ppi;
			var scaled = mk( aw, ah );
			scaled.getContext( '2d' ).drawImage( trimmed, 0, 0, aw, ah );
			var bpx = bIn * ppi, pad = Math.ceil( bpx + 6 );
			var sil = dilate( silhouette( scaled ), bpx, pad );
			solidify( sil, Math.max( 1.2, bpx * 0.25 ) );
			var sb = bbox( sil );
			body = mk( sb.w, sb.h );
			body.getContext( '2d' ).drawImage( sil, -sb.x, -sb.y );
			artRect = { img: scaled, x: pad - sb.x, y: pad - sb.y, w: aw, h: ah };
			w = sb.w; h = sb.h;
		} else {
			if ( 'oval' === S.shape ) {
				w = box[ 0 ] * ppi; h = ( box[ 0 ] === box[ 1 ] ? box[ 1 ] * 0.7 : box[ 1 ] ) * ppi;
			} else if ( 'circle' === S.shape ) {
				w = h = Math.min( box[ 0 ], box[ 1 ] ) * ppi;
			} else {
				w = box[ 0 ] * ppi; h = box[ 1 ] * ppi;
			}
			body = mk( w, h );
			var bx = body.getContext( '2d' );
			bx.beginPath();
			if ( 'circle' === S.shape || 'oval' === S.shape ) { bx.ellipse( w / 2, h / 2, w / 2, h / 2, 0, 0, Math.PI * 2 ); }
			else if ( bx.roundRect ) { bx.roundRect( 0, 0, w, h, 'rounded_square' === S.shape ? Math.min( w, h ) * S.radius / 100 : 0 ); }
			else { bx.rect( 0, 0, w, h ); }
			bx.fillStyle = '#000';
			bx.fill();
			var inset = Math.max( bIn, 1 / 16 ) * ppi, availW = w - inset * 2, availH = h - inset * 2, f;
			if ( 'circle' === S.shape || 'oval' === S.shape ) {
				// Scale so the art's farthest opaque pixel touches the inner ellipse.
				var rx = availW / 2, ry = availH / 2, td = trimmed.getContext( '2d' ).getImageData( 0, 0, bb.w, bb.h ).data, mr = 0;
				for ( var py = 0; py < bb.h; py += 2 ) {
					for ( var px = 0; px < bb.w; px += 2 ) {
						if ( td[ ( py * bb.w + px ) * 4 + 3 ] > 40 ) { var e = Math.pow( ( px - bb.w / 2 ) / rx, 2 ) + Math.pow( ( py - bb.h / 2 ) / ry, 2 ); if ( e > mr ) { mr = e; } }
					}
				}
				f = mr ? 1 / Math.sqrt( mr ) : Math.min( availW / bb.w, availH / bb.h );
			} else {
				f = Math.min( availW / bb.w, availH / bb.h );
			}
			var aw2 = bb.w * f, ah2 = bb.h * f, sc2 = mk( aw2, ah2 );
			sc2.getContext( '2d' ).drawImage( trimmed, 0, 0, aw2, ah2 );
			artRect = { img: sc2, x: ( w - aw2 ) / 2, y: ( h - ah2 ) / 2, w: aw2, h: ah2 };
		}

		// The cut line: a thin ring just outside the sticker body.
		var ring = mk( w + 8, h + 8 ), rx2 = ring.getContext( '2d' );
		for ( var t = 0; t < 16; t++ ) { var an = t / 16 * Math.PI * 2; rx2.drawImage( body, 4 + Math.cos( an ) * 1.6, 4 + Math.sin( an ) * 1.6 ); }
		rx2.globalCompositeOperation = 'source-in';
		rx2.fillStyle = '#EC008C';
		rx2.fillRect( 0, 0, ring.width, ring.height );
		rx2.globalCompositeOperation = 'destination-out';
		rx2.drawImage( body, 4, 4 );

		built = {
			w: w, h: h, ppi: ppi, body: body, art: artRect, ring: ring,
			inW: w / ppi, inH: h / ppi,
			artPxLong: Math.max( nw * bb.w / a0.width, nh * bb.h / a0.height ),
			artInLong: Math.max( artRect.w, artRect.h ) / ppi
		};
		face = null;
		updateUI();
		draw();
	}

	/* ---------- materials ---------- */
	function composeFace() {
		var b = built, c = mk( b.w, b.h ), x = c.getContext( '2d' ), fin = finish();
		x.drawImage( b.body, 0, 0 );
		x.globalCompositeOperation = 'source-in';
		x.fillStyle = 'clear' === fin ? 'rgba(255,255,255,0.22)' : ( 'matte' === fin ? '#fbfaf8' : '#ffffff' );
		x.fillRect( 0, 0, b.w, b.h );
		x.globalCompositeOperation = 'source-over';
		x.save();
		if ( 'clear' === fin && ! S.underlay ) { x.globalCompositeOperation = 'multiply'; }
		if ( 'matte' === fin ) { x.filter = 'saturate(0.92) contrast(0.96)'; }
		x.drawImage( b.art.img, b.art.x, b.art.y, b.art.w, b.art.h );
		x.restore();
		x.globalCompositeOperation = 'destination-in';
		x.drawImage( b.body, 0, 0 );
		face = c;
	}
	function materialLayer( tilt ) {
		var b = built, fin = finish();
		if ( 'holographic' !== fin && 'prism' !== fin && 'metallic' !== fin ) { return null; }
		var W = b.w, H = b.h, c = mk( W, H ), x = c.getContext( '2d' ), g;
		if ( 'holographic' === fin ) {
			var off = ( tilt - 0.5 ) * W * 1.6;
			g = x.createLinearGradient( -W + off, 0, W * 2 + off, H * 0.6 );
			var cols = [ '#9fdcff', '#ffb3e4', '#fff0a6', '#b0ffd7', '#c8bcff', '#ffb3e4', '#fff0a6', '#9fdcff', '#ffb3e4', '#b0ffd7' ];
			cols.forEach( function ( col, i ) { g.addColorStop( i / ( cols.length - 1 ), col ); } );
			x.fillStyle = g;
			x.fillRect( 0, 0, W, H );
			x.globalCompositeOperation = 'multiply';
			var g2 = x.createLinearGradient( 0, 0, W, H );
			g2.addColorStop( 0, '#d6dadf' ); g2.addColorStop( 0.35, '#fafafa' ); g2.addColorStop( 0.6, '#dde0e3' ); g2.addColorStop( 1, '#f4f5f6' );
			x.fillStyle = g2;
			x.fillRect( 0, 0, W, H );
		} else if ( 'prism' === fin ) {
			// A faint veil of shards, so the art and its colors stay clear.
			x.fillStyle = '#fff';
			x.fillRect( 0, 0, W, H );
			if ( prismImg.complete && prismImg.naturalWidth ) {
				x.globalAlpha = 0.13;
				x.filter = 'hue-rotate(' + Math.round( tilt * 300 ) + 'deg) saturate(1.1) brightness(1.3)';
				var tile = W * 0.32, pat = mk( tile, tile );
				pat.getContext( '2d' ).drawImage( prismImg, 0, 0, tile, tile );
				x.fillStyle = x.createPattern( pat, 'repeat' );
				x.fillRect( 0, 0, W, H );
				x.filter = 'none';
				x.globalAlpha = 1;
			}
		} else {
			g = x.createLinearGradient( 0, 0, W, H * 0.5 );
			g.addColorStop( 0, '#c3c7cd' ); g.addColorStop( 0.35, '#f2f3f5' ); g.addColorStop( 0.55, '#a9aeb5' ); g.addColorStop( 0.75, '#eceef0' ); g.addColorStop( 1, '#b4b9bf' );
			x.fillStyle = g;
			x.fillRect( 0, 0, W, H );
		}
		x.globalCompositeOperation = 'destination-in';
		x.drawImage( b.body, 0, 0 );
		return c;
	}
	function sheenLayer( tilt ) {
		var b = built, fin = finish();
		if ( 'matte' === fin ) { return null; }
		var W = b.w, H = b.h, c = mk( W, H ), x = c.getContext( '2d' ), cx = ( 1.2 - tilt * 1.4 ) * W;
		var g = x.createLinearGradient( cx - W * 0.35, 0, cx + W * 0.35, H * 0.55 );
		g.addColorStop( 0, 'rgba(255,255,255,0)' );
		g.addColorStop( 0.45, 'rgba(255,255,255,' + ( 'clear' === fin ? 0.35 : 0.28 ) + ')' );
		g.addColorStop( 0.55, 'rgba(255,255,255,.06)' );
		g.addColorStop( 1, 'rgba(255,255,255,0)' );
		x.fillStyle = g;
		x.fillRect( 0, 0, W, H );
		x.globalCompositeOperation = 'destination-in';
		x.drawImage( b.body, 0, 0 );
		return c;
	}

	/* ---------- drawing ---------- */
	function drawSticker( x, X, Y, s, opts ) {
		var b = built, W = b.w * s, H = b.h * s, fin = finish();
		x.save();
		x.shadowColor = 'rgba(0,0,0,' + ( opts.shadow || 0.22 ) + ')';
		x.shadowBlur = 28 * s;
		x.shadowOffsetY = 10 * s;
		x.drawImage( b.body, X, Y, W, H );
		x.restore();
		if ( 'clear' === fin ) {
			x.save(); x.globalCompositeOperation = 'destination-out'; x.drawImage( b.body, X, Y, W, H ); x.restore();
			if ( opts.under ) { opts.under(); }
		}
		x.drawImage( face, X, Y, W, H );
		var ml = materialLayer( S.tilt );
		if ( ml ) { x.save(); x.globalCompositeOperation = 'multiply'; x.drawImage( ml, X, Y, W, H ); x.restore(); }
		var sh = opts.noSheen ? null : sheenLayer( S.tilt );
		if ( sh ) { x.save(); x.globalCompositeOperation = 'screen'; x.drawImage( sh, X, Y, W, H ); x.restore(); }
		if ( 'clear' === fin ) { x.save(); x.globalAlpha = 0.55; x.filter = 'grayscale(1) brightness(1.6)'; x.drawImage( b.ring, X - 4 * s, Y - 4 * s, b.ring.width * s, b.ring.height * s ); x.restore(); }
		if ( opts.cut ) { x.drawImage( b.ring, X - 4 * s, Y - 4 * s, b.ring.width * s, b.ring.height * s ); }
	}
	function dimLabel( x, text, X, Y, dark ) {
		x.font = '500 ' + Math.round( 12 * dpr() ) + 'px "IBM Plex Mono", monospace';
		x.textAlign = 'center';
		x.textBaseline = 'middle';
		var tw = x.measureText( text ).width + 12 * dpr(), th = 20 * dpr();
		x.fillStyle = dark ? 'rgba(20,20,20,.85)' : 'rgba(255,255,255,.95)';
		rr( x, X - tw / 2, Y - th / 2, tw, th, th / 2 );
		x.fill();
		x.fillStyle = dark ? '#fff' : '#3A3A3C';
		x.fillText( text, X, Y );
	}
	function sheetLayout() {
		var b = built, shW = 5.5, shH = 8.5, gap = 0.18, m = 0.25;
		var cols = Math.max( 1, Math.floor( ( shW - 2 * m + gap ) / ( b.inW + gap ) ) ), rows = Math.max( 1, Math.floor( ( shH - 2 * m + gap ) / ( b.inH + gap ) ) );
		if ( b.inW > shW - 2 * m || b.inH > shH - 2 * m ) { cols = rows = 1; shW = b.inW + 2 * m; shH = b.inH + 2 * m; }
		return { shW: shW, shH: shH, gap: gap, cols: cols, rows: rows };
	}

	function sizeCanvas() {
		var r = stage.getBoundingClientRect();
		cv.width = Math.max( 1, Math.round( r.width * dpr() ) );
		cv.height = Math.max( 1, Math.round( r.height * dpr() ) );
	}

	function draw() {
		var x = ctx, Wc = cv.width, Hc = cv.height;
		x.setTransform( 1, 0, 0, 1, 0, 0 );
		x.clearRect( 0, 0, Wc, Hc );
		var legend = $( '[data-sd-legend-size]' );
		if ( ! built ) { legend.textContent = ''; return; }
		if ( ! face ) { composeFace(); }
		var b = built, dark = S.dark && 'close' === S.view, D = dpr();

		if ( 'close' === S.view ) {
			if ( 'sheet' === S.type ) {
				var L = sheetLayout(), scale = Math.min( Wc * 0.78 / L.shW, Hc * 0.82 / L.shH ), sw = L.shW * scale, shh = L.shH * scale, sx = ( Wc - sw ) / 2, sy = ( Hc - shh ) / 2;
				x.save(); x.shadowColor = 'rgba(0,0,0,.18)'; x.shadowBlur = 18 * D; x.shadowOffsetY = 6 * D; x.fillStyle = '#f4f2ed'; rr( x, sx, sy, sw, shh, 6 * D ); x.fill(); x.restore();
				x.strokeStyle = '#d6d2ca'; x.lineWidth = D; rr( x, sx, sy, sw, shh, 6 * D ); x.stroke();
				var gw = L.cols * b.inW + ( L.cols - 1 ) * L.gap, gh = L.rows * b.inH + ( L.rows - 1 ) * L.gap, ox = sx + ( sw - gw * scale ) / 2, oy = sy + ( shh - gh * scale ) / 2, s1 = scale / b.ppi;
				for ( var r = 0; r < L.rows; r++ ) {
					for ( var c = 0; c < L.cols; c++ ) { drawSticker( x, ox + c * ( b.inW + L.gap ) * scale, oy + r * ( b.inH + L.gap ) * scale, s1, { shadow: 0.06, cut: S.cutline } ); }
				}
				dimLabel( x, L.cols * L.rows + ' per ' + fmtIn( L.shW ) + ' × ' + fmtIn( L.shH ) + ' sheet', Wc / 2, sy + shh + 16 * D, dark );
				legend.textContent = 'Each: ' + fmtIn( b.inW ) + ' × ' + fmtIn( b.inH );
				return;
			}
			var margin = 'kiss_cut' === S.type ? 0.18 : 0, totW = b.inW + 2 * margin, totH = b.inH + 2 * margin;
			var sc = Math.min( Wc * 0.66 / totW, Hc * 0.66 / totH ), s = sc / b.ppi;
			var X = ( Wc - b.w * s ) / 2, Y = ( Hc - b.h * s ) / 2 - 6 * D;
			if ( 'kiss_cut' === S.type ) {
				var kx = X - margin * sc, ky = Y - margin * sc, kw = totW * sc, kh = totH * sc;
				x.save(); x.shadowColor = 'rgba(0,0,0,.2)'; x.shadowBlur = 18 * D; x.shadowOffsetY = 6 * D; x.fillStyle = '#f4f2ed'; rr( x, kx, ky, kw, kh, 0.08 * sc ); x.fill(); x.restore();
				x.strokeStyle = '#d6d2ca'; x.lineWidth = D; rr( x, kx, ky, kw, kh, 0.08 * sc ); x.stroke();
				drawSticker( x, X, Y, s, { shadow: 0.08, cut: S.cutline } );
				dimLabel( x, fmtIn( totW ) + ' × ' + fmtIn( totH ) + ' backing', Wc / 2, ky + kh + 18 * D, dark );
			} else {
				drawSticker( x, X, Y, s, { cut: S.cutline } );
				x.strokeStyle = dark ? 'rgba(255,255,255,.5)' : 'rgba(20,20,20,.35)';
				x.lineWidth = D;
				var yy = Y + b.h * s + 18 * D, xx = X + b.w * s + 18 * D;
				x.beginPath();
				x.moveTo( X, yy ); x.lineTo( X + b.w * s, yy );
				x.moveTo( X, yy - 5 * D ); x.lineTo( X, yy + 5 * D );
				x.moveTo( X + b.w * s, yy - 5 * D ); x.lineTo( X + b.w * s, yy + 5 * D );
				x.moveTo( xx, Y ); x.lineTo( xx, Y + b.h * s );
				x.moveTo( xx - 5 * D, Y ); x.lineTo( xx + 5 * D, Y );
				x.moveTo( xx - 5 * D, Y + b.h * s ); x.lineTo( xx + 5 * D, Y + b.h * s );
				x.stroke();
				dimLabel( x, fmtIn( b.inW ), X + b.w * s / 2, yy, dark );
				dimLabel( x, fmtIn( b.inH ), xx, Y + b.h * s / 2, dark );
			}
		} else if ( 'laptop' === S.view ) {
			// 13″ laptop lid ≈ 11.9″ × 8.4″, sticker at true scale.
			var lw = 11.9, lh = 8.4, s2 = Math.min( Wc * 0.92 / lw, Hc * 0.92 / lh ), LW = lw * s2, LH = lh * s2, LX = ( Wc - LW ) / 2, LY = ( Hc - LH ) / 2;
			var lg = x.createLinearGradient( LX, LY, LX + LW, LY + LH );
			lg.addColorStop( 0, '#6d7178' ); lg.addColorStop( 0.5, '#8a8e95' ); lg.addColorStop( 1, '#5d6168' );
			x.save(); x.shadowColor = 'rgba(0,0,0,.25)'; x.shadowBlur = 20 * D; x.fillStyle = lg; rr( x, LX, LY, LW, LH, 0.35 * s2 ); x.fill(); x.restore();
			x.fillStyle = 'rgba(255,255,255,.12)'; x.beginPath(); x.arc( LX + LW / 2, LY + LH / 2, 0.45 * s2, 0, Math.PI * 2 ); x.fill();
			var s3 = s2 / b.ppi, X3 = LX + LW * 0.64 - b.w * s3 / 2, Y3 = LY + LH * 0.32 - b.h * s3 / 2;
			X3 = Math.max( LX + 4 * D, Math.min( X3, LX + LW - b.w * s3 - 4 * D ) ); Y3 = Math.max( LY + 4 * D, Y3 );
			drawSticker( x, X3, Y3, s3, { shadow: 0.18, under: function () { x.save(); x.beginPath(); x.rect( X3, Y3, b.w * s3, b.h * s3 ); x.clip(); x.fillStyle = lg; x.fillRect( X3, Y3, b.w * s3, b.h * s3 ); x.restore(); } } );
			dimLabel( x, 'Actual scale on a 13″ laptop', Wc / 2, LY + LH - 18 * D, true );
		} else {
			// 24 oz bottle, 3.4″ wide face.
			var bh2 = 9.5, s4 = Math.min( Wc * 0.9 / 7, Hc * 0.9 / bh2 ), BW = 3.4 * s4, BH = bh2 * s4, BX = ( Wc - BW ) / 2, BY = ( Hc - BH ) / 2 + 0.3 * s4;
			var gb = x.createLinearGradient( BX, 0, BX + BW, 0 );
			gb.addColorStop( 0, '#0d5c56' ); gb.addColorStop( 0.3, '#1d8a80' ); gb.addColorStop( 0.55, '#25a194' ); gb.addColorStop( 1, '#0b4a45' );
			x.save(); x.shadowColor = 'rgba(0,0,0,.25)'; x.shadowBlur = 20 * D; x.fillStyle = gb; rr( x, BX, BY, BW, BH, 0.6 * s4 ); x.fill(); x.restore();
			x.fillStyle = '#2a2b2f'; rr( x, BX + BW * 0.2, BY - 0.9 * s4, BW * 0.6, 1.0 * s4, 0.2 * s4 ); x.fill();
			var s5 = s4 / b.ppi, X5 = BX + ( BW - b.w * s5 ) / 2, Y5 = BY + BH * 0.38 - b.h * s5 / 2;
			drawSticker( x, X5, Y5, s5, { shadow: 0.15, under: function () { x.save(); x.beginPath(); x.rect( X5, Y5, b.w * s5, b.h * s5 ); x.clip(); x.fillStyle = gb; x.fillRect( X5, Y5, b.w * s5, b.h * s5 ); x.restore(); } } );
			var hl = x.createLinearGradient( BX, 0, BX + BW, 0 );
			hl.addColorStop( 0, 'rgba(0,0,0,.25)' ); hl.addColorStop( 0.25, 'rgba(255,255,255,.12)' ); hl.addColorStop( 0.32, 'rgba(255,255,255,0)' ); hl.addColorStop( 0.85, 'rgba(0,0,0,0)' ); hl.addColorStop( 1, 'rgba(0,0,0,.3)' );
			x.fillStyle = hl; rr( x, BX, BY, BW, BH, 0.6 * s4 ); x.fill();
			dimLabel( x, 'Actual scale on a 24 oz bottle', Wc / 2, BY + BH - 18 * D, true );
		}
		legend.textContent = 'Sticker: ' + fmtIn( b.inW ) + ' × ' + fmtIn( b.inH );
	}

	/** A PNG of the finished sticker with its cut line, saved with the order for production. */
	function previewBlob() {
		return new Promise( function ( resolve ) {
			if ( ! built ) { resolve( null ); return; }
			if ( ! face ) { composeFace(); }
			var b = built, pad = 24, c = mk( b.w + pad * 2, b.h + pad * 2 ), x = c.getContext( '2d' ), saved = S.tilt;
			S.tilt = 0.5;
			if ( 'kiss_cut' === S.type || 'sheet' === S.type ) {
				var m = 0.18 * b.ppi;
				x.fillStyle = '#f4f2ed';
				rr( x, pad - m, pad - m, b.w + 2 * m, b.h + 2 * m, 0.08 * b.ppi );
				x.fill();
			}
			drawSticker( x, pad, pad, 1, { shadow: 0.0001, cut: true, noSheen: true } );
			S.tilt = saved;
			if ( c.toBlob ) { c.toBlob( resolve, 'image/png' ); } else { resolve( null ); }
		} );
	}

	/* ---------- UI ---------- */
	function press( sel, attr, val ) { $$( sel ).forEach( function ( el ) { el.setAttribute( 'aria-pressed', String( el.getAttribute( attr ) === String( val ) ) ); } ); }

	function renderStatic() {
		$( '[data-sd-types]' ).innerHTML = TYPE_ORDER.filter( function ( k ) { return OPT.types[ k ]; } ).map( function ( k ) {
			var i = TYPE_INFO[ k ];
			return '<button type="button" class="yp-sd__card" data-sd-type="' + k + '" aria-pressed="false"><svg viewBox="0 0 120 52" aria-hidden="true">' + i.svg + '</svg><span class="yp-sd__card-t">' + esc( i.t ) + '</span><span class="yp-sd__card-d">' + esc( i.d ) + '</span></button>';
		} ).join( '' );
		$( '[data-sd-shapes]' ).innerHTML = SHAPE_ORDER.filter( function ( k ) { return OPT.shapes[ k ]; } ).map( function ( k ) {
			var i = SHAPE_INFO[ k ];
			return '<button type="button" class="yp-sd__shape" data-sd-shape="' + k + '" aria-pressed="false"><svg viewBox="0 0 34 34" aria-hidden="true">' + i.svg + '</svg>' + esc( i.t ) + '</button>';
		} ).join( '' );
		$( '[data-sd-sizes]' ).innerHTML = OPT.sizes.map( function ( sz ) {
			var label = sz.is_custom ? 'Custom' : ( sz.width_in && sz.height_in && sz.width_in !== sz.height_in ? fmtIn( sz.width_in ) + ' × ' + fmtIn( sz.height_in ) : fmtIn( Math.max( sz.width_in, sz.height_in ) || 0 ) );
			if ( ! sz.is_custom && ! sz.width_in ) { label = sz.name; }
			var sub = sz.is_custom ? 'any size' : money( sz.price || 0 );
			return '<button type="button" class="yp-sd__chip" data-sd-size="' + sz.id + '" aria-pressed="false"><span class="yp-sd__chip-t">' + esc( label ) + '</span><span class="yp-sd__chip-s">' + esc( sub ) + '</span></button>';
		} ).join( '' );
		$( '[data-sd-materials]' ).innerHTML = OPT.materials.map( function ( m ) {
			var out = false === m.in_stock, adj = m.price_adjustment || 0;
			return '<button type="button" class="yp-sd__sw" data-sd-material="' + m.id + '" aria-pressed="false"' + ( out ? ' disabled' : '' ) + '><span class="yp-sd__sw-c" style="background:' + ( FINISH_SWATCH[ m.finish ] || '#fff' ) + '"></span>' + esc( m.name ) + '<span class="yp-sd__sw-p">' + ( out ? 'back soon' : ( adj ? '+' + money( adj ) : 'included' ) ) + '</span></button>';
		} ).join( '' );

		$$( '[data-sd-type]' ).forEach( function ( el ) { el.addEventListener( 'click', function () { S.type = el.getAttribute( 'data-sd-type' ); updateUI(); draw(); } ); } );
		$$( '[data-sd-shape]' ).forEach( function ( el ) { el.addEventListener( 'click', function () { S.shape = el.getAttribute( 'data-sd-shape' ); rebuild(); } ); } );
		$$( '[data-sd-size]' ).forEach( function ( el ) { el.addEventListener( 'click', function () { S.sizeId = +el.getAttribute( 'data-sd-size' ); rebuild(); } ); } );
		$$( '[data-sd-material]' ).forEach( function ( el ) { el.addEventListener( 'click', function () { if ( el.disabled ) { return; } S.materialId = +el.getAttribute( 'data-sd-material' ); face = null; updateUI(); draw(); } ); } );
	}

	var SHAPE_NAMES = { custom: 'Contour', circle: 'Circle', square: 'Square', rounded_square: 'Rounded', oval: 'Oval' };

	function updateUI() {
		var b = built, fin = finish(), sz = size();
		press( '[data-sd-type]', 'data-sd-type', S.type );
		press( '[data-sd-shape]', 'data-sd-shape', S.shape );
		press( '[data-sd-size]', 'data-sd-size', S.sizeId );
		press( '[data-sd-material]', 'data-sd-material', S.materialId );
		press( '[data-sd-view]', 'data-sd-view', S.view );
		$( '[data-sd-border-val]' ).textContent = BORDER_LABEL[ S.border ];
		$( '[data-sd-radius-row]' ).hidden = 'rounded_square' !== S.shape;
		$( '[data-sd-radius-val]' ).textContent = S.radius + '%';
		$( '[data-sd-custom-size]' ).hidden = ! ( sz && sz.is_custom );
		$( '[data-sd-holo]' ).hidden = 'holographic' !== fin;
		$( '[data-sd-underlay-row]' ).hidden = 'clear' !== fin;
		$( '[data-sd-empty]' ).hidden = !! art;
		$( '[data-sd-hint]' ).hidden = ! art || 'matte' === fin;
		stage.classList.toggle( 'is-clear', 'clear' === fin && 'close' === S.view && ! S.dark );
		stage.classList.toggle( 'is-dark', S.dark && 'close' === S.view );

		var borderWord = 'holographic' === fin ? 'holographic' : ( 'clear' === fin ? 'clear' : 'white' );
		var hints = {
			custom: S.border ? 'The cut follows your art with a ' + BORDER_LABEL[ S.border ] + ' ' + borderWord + ' border around it.' : 'The cut follows the edge of your art, with no border.',
			circle: 'Your art is centered in a circle with a safe margin from the cut.',
			square: 'Square corners. Your art is centered with a safe margin.',
			rounded_square: 'Drag Corners to round them more or less.',
			oval: 'Your art is centered in an oval.'
		};
		$( '[data-sd-cut-hint]' ).textContent = hints[ S.shape ] || '';
		$( '[data-sd-size-out]' ).innerHTML = b ? 'Your sticker: <b>' + fmtIn( b.inW ) + ' × ' + fmtIn( b.inH ) + '</b>' + ( 'kiss_cut' === S.type ? ' on a ' + fmtIn( b.inW + 0.36 ) + ' × ' + fmtIn( b.inH + 0.36 ) + ' backing' : '' ) : 'Upload your art to see its exact size.';

		// Artwork checks
		var html = '';
		if ( art ) {
			if ( art.error ) { html += chk( 'bad', '<b>Upload failed.</b> ' + esc( art.error ) ); }
			else if ( art.uploading ) { html += chk( 'busy', '<b>Uploading your file…</b>' ); }
			if ( b ) {
				if ( art.isPdf ) { html += chk( 'ok', '<b>PDF file.</b> Our designer checks its print quality before printing.' ); }
				else if ( art.vector ) { html += chk( 'ok', '<b>Vector file.</b> Sharp at any size.' ); }
				else {
					var dpi = Math.round( b.artPxLong / b.artInLong );
					if ( dpi >= 300 ) { html += chk( 'ok', '<b>' + dpi + ' DPI at this size.</b> Prints sharp.' ); }
					else if ( dpi >= 150 ) { html += chk( 'warn', '<b>' + dpi + ' DPI at this size.</b> OK, may look a little soft up close.' ); }
					else { html += chk( 'bad', '<b>' + dpi + ' DPI at this size.</b> Will print blurry. Upload a larger file or pick a smaller size.' ); }
				}
			}
			if ( ! art.img ) {
				if ( art.previewError ) { html += chk( 'warn', esc( art.previewError ) ); }
			} else if ( ! art.hadBg ) { html += chk( 'ok', '<b>Transparent background found.</b> We cut around your art.' ); }
			else {
				html += chk( S.removeBg ? 'ok' : 'warn', '<b>White background ' + ( S.removeBg ? 'removed' : 'kept' ) + '.</b><br><label class="yp-sd__inline-toggle"><input type="checkbox" data-sd-rmbg' + ( S.removeBg ? ' checked' : '' ) + '> Remove white background</label>' );
			}
		}
		$( '[data-sd-checks]' ).innerHTML = html;
		var rm = $( '[data-sd-rmbg]' );
		if ( rm ) { rm.addEventListener( 'change', function () { S.removeBg = rm.checked; rebuild(); } ); }

		renderQty();
		renderSummary();
	}
	function chk( kind, msg ) { return '<div class="yp-sd__check"><span class="yp-sd__dot is-' + kind + '">' + ( 'ok' === kind ? '✓' : ( 'busy' === kind ? '…' : '!' ) ) + '</span><span>' + msg + '</span></div>'; }

	function renderQty() {
		var el = $( '[data-sd-qty]' ), h = '';
		OPT.presets.forEach( function ( q ) {
			var p = unitPrice( q );
			h += '<button type="button" class="yp-sd__q" data-sd-q="' + q + '" aria-pressed="' + ( ! S.isOther && S.qty === q ) + '"><span class="yp-sd__q-n">' + q + '</span><span class="yp-sd__q-p">' + money3( p.unit ) + ' ea</span>' + ( p.tier && p.pct ? '<span class="yp-sd__q-save">save ' + p.pct + '%</span>' : '' ) + '</button>';
		} );
		var po = unitPrice( S.otherQty );
		h += '<div class="yp-sd__q" aria-pressed="' + S.isOther + '"><input type="number" min="1" id="yp-cs-quantity-input" value="' + S.otherQty + '" aria-label="Other quantity"><span class="yp-sd__q-p">' + money3( po.unit ) + ' ea</span></div>';
		el.innerHTML = h;
		$$( '[data-sd-q]' ).forEach( function ( b ) { b.addEventListener( 'click', function () { S.qty = +b.getAttribute( 'data-sd-q' ); S.isOther = false; renderQty(); renderSummary(); } ); } );
		var oq = document.getElementById( 'yp-cs-quantity-input' );
		oq.addEventListener( 'focus', function () { if ( ! S.isOther ) { S.isOther = true; el.lastChild.setAttribute( 'aria-pressed', 'true' ); $$( '[data-sd-q]' ).forEach( function ( b ) { b.setAttribute( 'aria-pressed', 'false' ); } ); renderSummary(); } } );
		oq.addEventListener( 'input', function () { S.otherQty = Math.max( 1, parseInt( oq.value, 10 ) || 1 ); S.isOther = true; renderSummary(); } );
		oq.addEventListener( 'change', function () { renderQty(); } );
	}

	function renderSummary() {
		var q = qty(), p = unitPrice( q ), total = Math.round( p.unit * q * 100 ) / 100, m = material(), b = built;
		var typeName = ( TYPE_INFO[ S.type ] || {} ).t || '';
		$( '[data-sd-sum-what]' ).textContent = typeName + ' · ' + ( SHAPE_NAMES[ S.shape ] || '' ) + ( b ? ' · ' + fmtIn( b.inW ) + ' × ' + fmtIn( b.inH ) : '' );
		$( '[data-sd-sum-unit]' ).textContent = money3( p.unit ) + ' each';
		$( '[data-sd-sum-mat]' ).textContent = ( m ? m.name : '' ) + ( 'holographic' === finish() ? ' · +1 day to cure' : '' );
		$( '[data-sd-sum-qty]' ).textContent = '× ' + q;
		$( '[data-sd-sum-save-row]' ).hidden = ! ( p.tier && p.before > p.unit );
		$( '[data-sd-sum-save]' ).textContent = '−' + money( Math.round( ( p.before - p.unit ) * q * 100 ) / 100 );
		$( '[data-sd-total]' ).textContent = money( total );
		$( '[data-sd-m-total]' ).textContent = money( total );
		$( '[data-sd-m-sub]' ).textContent = q + ' stickers · ' + money3( p.unit ) + ' each';
	}

	/* ---------- artwork in ---------- */
	function loadPdfJs() {
		if ( window.pdfjsLib ) { return Promise.resolve( window.pdfjsLib ); }
		return new Promise( function ( resolve, reject ) {
			var s = document.createElement( 'script' );
			s.src = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
			s.onload = function () {
				window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
				resolve( window.pdfjsLib );
			};
			s.onerror = reject;
			document.head.appendChild( s );
		} );
	}
	// First page of a PDF, rendered big enough to judge, as an image.
	function pdfToImage( buffer ) {
		return loadPdfJs().then( function ( lib ) { return lib.getDocument( { data: buffer } ).promise; } ).then( function ( pdf ) { return pdf.getPage( 1 ); } ).then( function ( page ) {
			var vp0 = page.getViewport( { scale: 1 } ), scale = 2400 / Math.max( vp0.width, vp0.height ), vp = page.getViewport( { scale: scale } );
			var c = mk( vp.width, vp.height );
			return page.render( { canvasContext: c.getContext( '2d' ), viewport: vp, background: 'rgba(0,0,0,0)' } ).promise.then( function () { return c.toDataURL( 'image/png' ); } );
		} );
	}
	function cornerIsOpaque( img ) {
		var p = mk( Math.min( 200, img.naturalWidth || 200 ), Math.min( 200, img.naturalHeight || 200 ) ), x = p.getContext( '2d' );
		x.drawImage( img, 0, 0, p.width, p.height );
		var d = x.getImageData( 0, 0, p.width, p.height ).data, W = p.width, H = p.height;
		var corners = [ 0, ( W - 1 ) * 4, ( ( H - 1 ) * W ) * 4, ( ( H - 1 ) * W + W - 1 ) * 4 ];
		return corners.filter( function ( i ) { return d[ i + 3 ] > 200 && d[ i ] > 235 && d[ i + 1 ] > 235 && d[ i + 2 ] > 235; } ).length >= 3;
	}
	function showArt( src, name, kind, uploadId ) {
		var img = new Image();
		img.onload = function () {
			var w = img.naturalWidth || 1000, h = img.naturalHeight || 1000;
			var prev = art || {};
			art = { img: img, name: name, pxW: w, pxH: h, hadBg: cornerIsOpaque( img ), vector: 'svg' === kind, isPdf: 'pdf' === kind, uploadId: uploadId || prev.uploadId || 0, uploading: !! prev.uploading && ! uploadId, error: prev.error || null };
			S.removeBg = true;
			$( '[data-sd-file-name]' ).textContent = name;
			$( '[data-sd-file-sub]' ).textContent = ( 'svg' === kind ? 'SVG · vector' : ( 'pdf' === kind ? 'PDF · page 1' : w + ' × ' + h + ' px' ) ) + ( art.hadBg ? ' · white background' : ' · transparent background' );
			var th = $( '[data-sd-thumb]' ); th.src = src; th.hidden = false;
			$( '[data-sd-file-btn]' ).textContent = 'Replace';
			rebuild();
		};
		img.onerror = function () { showLoadError( "We couldn't preview that file. You can still add it, and our designer will check it." ); };
		img.src = src;
	}
	function showLoadError( msg ) {
		if ( art ) { art.previewError = msg; }
		$( '[data-sd-file-sub]' ).textContent = 'No preview';
		updateUI();
	}

	function takeFile( file ) {
		if ( ! file ) { return; }
		var ext = ( file.name.split( '.' ).pop() || '' ).toLowerCase();
		var kind = 'pdf' === ext || 'application/pdf' === file.type ? 'pdf' : ( 'svg' === ext || 'image/svg+xml' === file.type ? 'svg' : 'raster' );
		art = { img: null, name: file.name, uploadId: 0, uploading: true, error: null };
		built = null;
		uploadArt( file );
		var rd = new FileReader();
		if ( 'pdf' === kind ) {
			rd.onload = function () {
				pdfToImage( rd.result ).then( function ( url ) { showArt( url, file.name, 'pdf' ); } ).catch( function () { showLoadError( "We couldn't preview this PDF here. You can still add it, and our designer will check it." ); } );
			};
			rd.readAsArrayBuffer( file );
		} else {
			rd.onload = function () { showArt( rd.result, file.name, kind ); };
			rd.readAsDataURL( file );
		}
		$( '[data-sd-file-name]' ).textContent = file.name;
		$( '[data-sd-file-sub]' ).textContent = 'Loading preview…';
	}

	function postUpload( blobOrFile, name ) {
		var fd = new FormData();
		fd.append( 'files[]', blobOrFile, name );
		return fetch( cfg.restUrl + 'custom-orders/uploads', { method: 'POST', headers: { 'X-WP-Nonce': cfg.nonce }, body: fd } )
			.then( function ( r ) { return r.json().then( function ( d ) { return { ok: r.ok, data: d }; } ); } )
			.then( function ( res ) {
				var result = res.data && res.data.files ? res.data.files[ 0 ] : null;
				if ( ! res.ok || ! result || ! result.success ) { throw new Error( ( result && result.message ) || ( res.data && res.data.message ) || 'Upload failed.' ); }
				return result.id;
			} );
	}
	function uploadArt( file ) {
		var mine = art;
		postUpload( file, file.name ).then( function ( id ) {
			if ( art !== mine && ( ! art || art.name !== file.name ) ) { return; }
			art.uploadId = id; art.uploading = false; art.error = null; updateUI();
		} ).catch( function ( e ) {
			if ( ! art ) { return; }
			art.uploading = false; art.error = e.message; updateUI();
		} );
	}

	/* ---------- events ---------- */
	fileInput.addEventListener( 'change', function () { takeFile( fileInput.files[ 0 ] ); fileInput.value = ''; } );
	[ stage, $( '[data-sd-drop]' ) ].forEach( function ( zone ) {
		[ 'dragenter', 'dragover' ].forEach( function ( t ) { zone.addEventListener( t, function ( e ) { e.preventDefault(); zone.classList.add( 'is-drag' ); } ); } );
		[ 'dragleave', 'drop' ].forEach( function ( t ) { zone.addEventListener( t, function ( e ) { e.preventDefault(); zone.classList.remove( 'is-drag' ); } ); } );
		zone.addEventListener( 'drop', function ( e ) { if ( e.dataTransfer && e.dataTransfer.files[ 0 ] ) { takeFile( e.dataTransfer.files[ 0 ] ); } } );
	} );
	$$( '[data-sd-view]' ).forEach( function ( el ) { el.addEventListener( 'click', function () { S.view = el.getAttribute( 'data-sd-view' ); updateUI(); draw(); } ); } );
	document.getElementById( 'yp-sd-border' ).addEventListener( 'input', function ( e ) { S.border = +e.target.value; rebuild(); } );
	document.getElementById( 'yp-sd-radius' ).addEventListener( 'input', function ( e ) { S.radius = +e.target.value; rebuild(); } );
	document.getElementById( 'yp-sd-custom' ).addEventListener( 'input', function ( e ) { S.customLong = +e.target.value; rebuild(); } );
	document.getElementById( 'yp-sd-cutline' ).addEventListener( 'change', function ( e ) { S.cutline = e.target.checked; draw(); } );
	document.getElementById( 'yp-sd-dark' ).addEventListener( 'change', function ( e ) { S.dark = e.target.checked; updateUI(); draw(); } );
	document.getElementById( 'yp-sd-underlay' ).addEventListener( 'change', function ( e ) { S.underlay = e.target.checked; face = null; draw(); } );

	stage.addEventListener( 'pointermove', function ( e ) { var r = stage.getBoundingClientRect(); S.tilt = ( e.clientX - r.left ) / r.width; S.hover = true; draw(); } );
	stage.addEventListener( 'pointerleave', function () { S.hover = false; } );
	var reduce = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	var visible = true;
	if ( 'IntersectionObserver' in window ) { new IntersectionObserver( function ( en ) { visible = en[ 0 ].isIntersecting; } ).observe( stage ); }
	if ( ! reduce ) {
		var t0 = performance.now(), last = 0;
		( function tick( t ) {
			if ( ! S.hover && built && visible && 'matte' !== finish() && t - last > 40 ) { last = t; S.tilt = 0.5 + 0.35 * Math.sin( ( t - t0 ) / 2600 ); draw(); }
			requestAnimationFrame( tick );
		} )( t0 );
	}
	var resizeT;
	window.addEventListener( 'resize', function () { clearTimeout( resizeT ); resizeT = setTimeout( function () { sizeCanvas(); draw(); }, 80 ); } );

	/* ---------- submit ---------- */
	var noteEl = null;
	function showNote( html, isError ) {
		if ( ! noteEl ) {
			noteEl = document.createElement( 'p' );
			noteEl.setAttribute( 'role', 'status' );
			submitButton.insertAdjacentElement( 'afterend', noteEl );
		}
		noteEl.className = 'yp-configurator__cart-status' + ( isError ? ' is-error' : '' );
		noteEl.innerHTML = html;
		if ( isError && window.matchMedia( '(max-width: 900px)' ).matches ) { noteEl.scrollIntoView( { block: 'center', behavior: 'smooth' } ); }
	}
	function setBusy( busy, label ) {
		$$( '.yp-sd__cta' ).forEach( function ( b ) { b.disabled = busy; } );
		submitButton.textContent = label || 'Add to cart';
	}
	function waitForUpload() {
		return new Promise( function ( resolve, reject ) {
			( function check() {
				if ( ! art ) { reject( new Error( 'Please upload your artwork.' ) ); return; }
				if ( art.error ) { reject( new Error( art.error ) ); return; }
				if ( art.uploadId ) { resolve( art.uploadId ); return; }
				setTimeout( check, 250 );
			} )();
		} );
	}

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		if ( ! art ) { showNote( 'Please upload your artwork first.', true ); return; }
		var sz = size(), m = material();
		if ( ! sz || ! m ) { showNote( 'Please pick a size and material.', true ); return; }
		setBusy( true, 'Adding…' );
		var uploadId;
		waitForUpload().then( function ( id ) {
			uploadId = id;
			return previewBlob().then( function ( blob ) {
				return blob ? postUpload( blob, 'sticker-preview.png' ).catch( function () { return 0; } ) : 0;
			} );
		} ).then( function ( previewId ) {
			var b = built;
			var payload = {
				size_id: sz.id,
				material_id: m.id,
				sticker_type: S.type,
				shape: S.shape,
				custom_width_in: sz.is_custom && b ? Math.round( b.inW * 100 ) / 100 : 0,
				custom_height_in: sz.is_custom && b ? Math.round( b.inH * 100 ) / 100 : 0,
				quantity: qty(),
				instructions: instructionsEl ? instructionsEl.value : '',
				uploads: [ uploadId ],
				design: {
					border_in: BORDERS[ S.border ],
					corner_pct: 'rounded_square' === S.shape ? S.radius : 0,
					remove_bg: !! ( art.hadBg && S.removeBg ),
					white_underlay: 'clear' === finish() ? !! S.underlay : false,
					width_in: b ? Math.round( b.inW * 100 ) / 100 : 0,
					height_in: b ? Math.round( b.inH * 100 ) / 100 : 0,
					preview_id: previewId || 0
				}
			};
			return fetch( cfg.restUrl + 'custom-stickers', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
				body: JSON.stringify( payload )
			} ).then( function ( r ) { return r.json().then( function ( d ) { return { ok: r.ok, data: d }; } ); } );
		} ).then( function ( res ) {
			setBusy( false );
			if ( ! res.ok ) { showNote( esc( ( res.data && res.data.message ) || "Couldn't add your stickers. Please try again." ), true ); return; }
			if ( ! document.getElementById( 'yp-cart-drawer' ) ) { window.location.href = res.data.checkout_url; return; }
			showNote( 'Added to your cart. Upload your next design above, or <a href="' + esc( res.data.checkout_url ) + '">go to checkout</a>.' );
			if ( instructionsEl ) { instructionsEl.value = ''; }
			document.dispatchEvent( new CustomEvent( 'yp:cart-updated', { detail: { count: res.data.cart_count, drawerHtml: res.data.drawer_html } } ) );
		} ).catch( function ( err ) {
			setBusy( false );
			showNote( esc( ( err && err.message ) || "Couldn't reach the server. Please try again." ), true );
		} );
	} );

	/* ---------- reorder: ?reorder=<custom order id> ---------- */
	function prefill( id ) {
		return fetch( cfg.restUrl + 'custom-orders/' + encodeURIComponent( id ), { headers: { 'X-WP-Nonce': cfg.nonce } } )
			.then( function ( r ) { return r.json().then( function ( d ) { return { ok: r.ok, data: d }; } ); } )
			.then( function ( res ) {
				var d = res.data || {};
				if ( ! res.ok || ( d.order_type && 'sticker' !== d.order_type ) ) { showNote( esc( d.message || "Couldn't load that past sticker order." ), true ); return; }
				if ( d.sticker_type && OPT.types[ d.sticker_type ] ) { S.type = d.sticker_type; }
				if ( d.shape && OPT.shapes[ d.shape ] ) { S.shape = d.shape; }
				if ( d.size_id && OPT.sizes.some( function ( s ) { return s.id === d.size_id; } ) ) { S.sizeId = d.size_id; }
				if ( d.material_id && OPT.materials.some( function ( m ) { return m.id === d.material_id && false !== m.in_stock; } ) ) { S.materialId = d.material_id; }
				if ( d.quantity ) { var q = parseInt( d.quantity, 10 ); if ( OPT.presets.indexOf( q ) !== -1 ) { S.qty = q; S.isOther = false; } else { S.otherQty = q; S.isOther = true; } }
				if ( d.custom_width_in ) { S.customLong = Math.max( d.custom_width_in, d.custom_height_in || 0 ); document.getElementById( 'yp-sd-custom' ).value = S.customLong; }
				var sd = d.sticker_design || {};
				if ( sd.border_in != null ) { var bi = BORDERS.reduce( function ( best, v, i ) { return Math.abs( v - sd.border_in ) < Math.abs( BORDERS[ best ] - sd.border_in ) ? i : best; }, 0 ); S.border = bi; document.getElementById( 'yp-sd-border' ).value = bi; }
				if ( sd.corner_pct ) { S.radius = sd.corner_pct; document.getElementById( 'yp-sd-radius' ).value = sd.corner_pct; }
				if ( false === sd.white_underlay ) { S.underlay = false; document.getElementById( 'yp-sd-underlay' ).checked = false; }
				if ( d.instructions && instructionsEl ) { instructionsEl.value = d.instructions; }
				var a = ( d.artwork || [] )[ 0 ];
				if ( a && a.url ) {
					var kind = /pdf/.test( a.mime || '' ) ? 'pdf' : ( /svg/.test( a.mime || '' ) ? 'svg' : 'raster' );
					art = { img: null, name: a.name, uploadId: a.id, uploading: false, error: null };
					if ( 'pdf' === kind ) {
						fetch( a.url ).then( function ( r ) { return r.arrayBuffer(); } ).then( pdfToImage ).then( function ( url ) { showArt( url, a.name, 'pdf', a.id ); } ).catch( function () { showLoadError( "We couldn't preview your past file, but it's attached." ); updateUI(); } );
					} else {
						showArt( a.url, a.name, kind, a.id );
					}
				}
				updateUI();
			} )
			.catch( function () { showNote( "Couldn't load that past sticker order.", true ); } );
	}

	/* ---------- start ---------- */
	fetch( cfg.restUrl + 'custom-stickers/options' )
		.then( function ( r ) { return r.ok ? r.json() : Promise.reject( new Error( 'options' ) ); } )
		.then( function ( d ) {
			OPT.sizes = d.sizes || [];
			OPT.materials = ( d.materials || [] ).map( function ( m ) { m.finish = m.finish || 'glossy'; return m; } );
			OPT.types = d.sticker_types || {};
			OPT.shapes = d.shapes || {};
			OPT.presets = ( d.quantity_presets && d.quantity_presets.length ? d.quantity_presets : [ 10, 25, 50, 100 ] ).slice( 0, 7 );
			OPT.pricing = d.pricing || {};
			if ( ! OPT.types[ S.type ] ) { S.type = Object.keys( OPT.types )[ 0 ]; }
			if ( ! OPT.shapes[ S.shape ] ) { S.shape = Object.keys( OPT.shapes )[ 0 ]; }
			var mid = OPT.sizes.filter( function ( s ) { return ! s.is_custom; } );
			S.sizeId = ( mid[ Math.min( 1, mid.length - 1 ) ] || OPT.sizes[ 0 ] || {} ).id || 0;
			S.materialId = ( OPT.materials.filter( function ( m ) { return false !== m.in_stock; } )[ 0 ] || {} ).id || 0;
			S.qty = OPT.presets.indexOf( 50 ) !== -1 ? 50 : OPT.presets[ Math.min( 2, OPT.presets.length - 1 ) ];
			renderStatic();
			statusEl.hidden = true;
			form.hidden = false;
			sizeCanvas();
			updateUI();
			draw();
			var reorder = new URLSearchParams( window.location.search ).get( 'reorder' );
			if ( reorder ) { return prefill( reorder ); }
		} )
		.catch( function () {
			statusEl.textContent = "This page couldn't be loaded. Please refresh, or contact us directly.";
			statusEl.setAttribute( 'data-state', 'error' );
		} );
} )();
