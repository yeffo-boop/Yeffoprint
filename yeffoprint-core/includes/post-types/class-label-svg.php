<?php
/**
 * SVG label templates: one vector file per Template whose layer names
 * say which parts customers can recolor and where their text goes.
 *
 * Direct request (2026-11-23): the old preview stacked live HTML text
 * on a flat PNG that already had sample text baked in, painted the
 * Background pick *behind* an opaque image, and put every shared field
 * at the same spot on every design — so templates never previewed
 * right. An SVG fixes all three: every recolorable part is its own
 * shape, every text spot belongs to the design itself.
 *
 * File rules (the admin Template drawer repeats them):
 *  - Color parts: a layer/group/shape named `bg` (or `background`),
 *    `text`, `accent`, `accent-2`…, `stripe-1`…, or `color-<anything>`.
 *    Everything painted inside it takes the customer's pick.
 *  - Text slots: a text box whose words include `{compound}`,
 *    `{strength}`, `{batch}`/`{lot}`, `{exp}`, `{notes}` or any Label
 *    Fields id in braces. Mixed text works: `LOT {batch} · EXP {exp}`.
 *  - Optional fit box: a rectangle named `box-compound` (etc). The
 *    slot's text shrinks to stay inside it. The box itself never shows.
 *  - Optional QR spot: a rectangle named `qr`.
 *  - Anything else prints exactly as drawn (gradients, shading, logos).
 *
 * The upload is sanitized here (allow-listed elements/attributes, no
 * scripts, no outside URLs) and rewritten once, so the browser only
 * has to set CSS variables: each painted shape in a part gets
 * `fill: var(--yp-<part>, <original color>)`.
 *
 * Customer side: each part becomes a `color_choice` field
 * (virtual_fields()), replacing the Template's old dot-based color
 * choices, so picks ride through cart, checkout, orders and reorders
 * exactly like before. Field ids for `bg` and `text` match the old
 * Background/Text choices, so existing saved designs keep their picks.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Label_Svg {

	/** Normalized file + what was found in it (see analyze()). */
	public const META = '_yp_label_svg';

	/** Saved color sets: [ { name, colors: { part: '#HEX' } } ]. */
	public const COLORWAYS = '_yp_label_svg_colorways';

	public const MAX_BYTES = 1572864; // 1.5 MB.

	public const MAX_COLORWAYS = 8;

	private const ELEMENTS = [
		'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'style',
		'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
		'text', 'tspan',
		'lineargradient', 'radialgradient', 'stop', 'clippath', 'mask', 'pattern', 'image',
		'filter', 'feblend', 'fecolormatrix', 'fecomposite', 'fedropshadow', 'feflood',
		'fegaussianblur', 'femerge', 'femergenode', 'feoffset', 'femorphology', 'feturbulence',
		'fedisplacementmap', 'fecomponenttransfer', 'fefunca', 'fefuncr', 'fefuncg', 'fefuncb', 'fetile',
	];

	/** Painted shapes that can take a part's color. */
	private const PAINTED = [ 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'text', 'tspan', 'use' ];

	/** Slot names people naturally type, mapped to the shared Label Fields ids. */
	private const SLOT_ALIASES = [
		'compound'   => 'compound-name',
		'name'       => 'compound-name',
		'strength'   => 'strength-mg-or-iu',
		'dose'       => 'strength-mg-or-iu',
		'batch'      => 'batch-number',
		'lot'        => 'batch-number',
		'exp'        => 'expiration-date',
		'expiration' => 'expiration-date',
		'expiry'     => 'expiration-date',
		'notes'      => 'design-notes',
		'qr'         => 'qr-code',
	];

	public function __construct() {
		add_action( 'init', [ $this, 'register_script' ] );
	}

	/** Shared renderer, used by the product page (theme) and the admin app. */
	public function register_script(): void {
		wp_register_script(
			'yeffoprint-label-svg',
			YEFFOPRINT_CORE_URL . 'assets/frontend/label-svg.js',
			[],
			yeffoprint_core_asset_version( 'assets/frontend/label-svg.js' ),
			[ 'strategy' => 'defer' ]
		);
	}

	/* ---------- Stored data ---------- */

	/** @return array{markup:string,parts:array,slots:array,qr:bool,width:float,height:float}|null */
	public static function get( int $template_id ): ?array {
		$data = get_post_meta( $template_id, self::META, true );
		return is_array( $data ) && ! empty( $data['markup'] ) ? $data : null;
	}

	public static function has( int $template_id ): bool {
		return null !== self::get( $template_id );
	}

	public static function remove( int $template_id ): void {
		delete_post_meta( $template_id, self::META );
	}

	/** @return true|\WP_Error */
	public static function save( int $template_id, string $raw ) {
		$result = self::analyze( $raw );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		unset( $result['warnings'] );
		update_post_meta( $template_id, self::META, wp_slash( $result ) );
		return true;
	}

	public static function get_colorways( int $template_id ): array {
		return self::sanitize_colorways( get_post_meta( $template_id, self::COLORWAYS, true ) );
	}

	public static function update_colorways( int $template_id, $colorways ): void {
		update_post_meta( $template_id, self::COLORWAYS, self::sanitize_colorways( $colorways ) );
	}

	public static function sanitize_colorways( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$out = [];
		foreach ( $value as $way ) {
			if ( ! is_array( $way ) || count( $out ) >= self::MAX_COLORWAYS ) {
				continue;
			}
			$colors = [];
			foreach ( (array) ( $way['colors'] ?? [] ) as $part => $hex ) {
				$key = self::part_key( (string) $part );
				$hex = sanitize_hex_color( is_string( $hex ) ? $hex : '' );
				if ( $key && $hex ) {
					$colors[ $key ] = strtoupper( $hex );
				}
			}
			$name = sanitize_text_field( (string) ( $way['name'] ?? '' ) );
			if ( $colors ) {
				$out[] = [ 'name' => '' !== $name ? $name : sprintf( 'Colorway %d', count( $out ) + 1 ), 'colors' => $colors ];
			}
		}
		return $out;
	}

	/* ---------- Customer side ---------- */

	/**
	 * Everything configurator.js needs to draw the label: the file,
	 * which Label Field each {slot} fills, and the saved colorways.
	 */
	public static function customer_payload( int $template_id, array $field_schema ): ?array {
		$svg = self::get( $template_id );
		if ( ! $svg ) {
			return null;
		}

		$slot_fields = [];
		$slots       = $svg['slots'];
		if ( ! empty( $svg['qr'] ) ) {
			$slots[] = 'qr';
		}
		foreach ( $slots as $slot ) {
			$field_id = self::resolve_slot( $slot, $field_schema );
			if ( $field_id ) {
				$slot_fields[ $slot ] = $field_id;
			}
		}

		$colorways = array_values( array_filter( self::get_colorways( $template_id ), static function ( $way ) {
			return ! empty( $way['colors'] );
		} ) );

		return [
			'markup'      => $svg['markup'],
			'width'       => $svg['width'],
			'height'      => $svg['height'],
			'parts'       => array_map( static function ( $part ) {
				return [ 'key' => $part['key'], 'field_id' => self::field_id( $part['key'] ), 'group' => $part['group'] ];
			}, $svg['parts'] ),
			'slot_fields' => (object) $slot_fields,
			'colorways'   => $colorways,
		];
	}

	/** Which Label Fields id a {slot} fills: exact id, then the alias list, then an id starting with it. */
	public static function resolve_slot( string $slot, array $field_schema ): ?string {
		$ids = array_map( static function ( $field ) {
			return (string) ( $field['id'] ?? '' );
		}, array_filter( $field_schema, static function ( $field ) {
			return 'color_choice' !== ( $field['type'] ?? '' );
		} ) );

		if ( in_array( $slot, $ids, true ) ) {
			return $slot;
		}
		if ( isset( self::SLOT_ALIASES[ $slot ] ) && in_array( self::SLOT_ALIASES[ $slot ], $ids, true ) ) {
			return self::SLOT_ALIASES[ $slot ];
		}
		foreach ( $ids as $id ) {
			if ( 0 === strpos( $id, $slot ) ) {
				return $id;
			}
		}
		return null;
	}

	public static function field_id( string $part_key ): string {
		$map = [ 'bg' => 'background' ];
		return YeffoPrint_Label_Color_Meta::FIELD_PREFIX . ( $map[ $part_key ] ?? $part_key );
	}

	/**
	 * The file's color parts as `color_choice` fields — used instead of
	 * the old dot-based choices whenever a Template has an SVG.
	 */
	public static function virtual_fields( int $template_id ): array {
		$svg = self::get( $template_id );
		if ( ! $svg ) {
			return [];
		}

		$options = array_values( array_map( static function ( array $color ): array {
			return [ 'name' => $color['name'], 'hex' => $color['hex'] ];
		}, YeffoPrint_Label_Color_Meta::get_colors() ) );

		$fields = [];
		foreach ( $svg['parts'] as $part ) {
			$target = 'bg' === $part['key'] ? 'background' : ( 'text' === $part['key'] ? 'text' : 'part' );
			$fields[] = array_merge( YeffoPrint_Field_Schema::default_field(), [
				'id'              => self::field_id( $part['key'] ),
				'label'           => $part['label'],
				'type'            => 'color_choice',
				'default'         => $part['default'],
				'required'        => true,
				'max_chars'       => 7,
				'show_in_preview' => false,
				'position'        => [ 'x' => null, 'y' => null ],
				'hint'            => $part['hint'],
				'target'          => $target,
				'part'            => $part['key'],
				'group'           => $part['group'],
				'layer_url'       => '',
				'any_color'       => true,
				'options'         => $options,
			] );
		}

		return $fields;
	}

	/* ---------- Reading an upload ---------- */

	/**
	 * Sanitizes and rewrites an uploaded SVG.
	 *
	 * @return array{markup:string,parts:array,slots:array,qr:bool,width:float,height:float,warnings:array}|\WP_Error
	 */
	public static function analyze( string $raw ) {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return new \WP_Error( 'yeffoprint_svg_empty', __( 'That file is empty.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}
		if ( strlen( $raw ) > self::MAX_BYTES ) {
			return new \WP_Error( 'yeffoprint_svg_too_big', __( 'That SVG is over 1.5 MB. Convert big photos inside it to smaller images, or outline fewer fonts.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}
		if ( preg_match( '/<!ENTITY/i', $raw ) ) {
			return new \WP_Error( 'yeffoprint_svg_entity', __( 'That SVG uses XML entities, which aren’t allowed. Re-export it from your design app.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}
		// Doctype and processing instructions carry nothing we need.
		$raw = preg_replace( '/<!DOCTYPE[^>]*>/i', '', $raw );
		$raw = preg_replace( '/<\?(?!xml\s)[\s\S]*?\?>/i', '', $raw );

		$previous = libxml_use_internal_errors( true );
		$doc      = new \DOMDocument();
		$loaded   = $doc->loadXML( $raw, LIBXML_NONET | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$root = $loaded ? $doc->documentElement : null;
		if ( ! $root || 'svg' !== strtolower( $root->localName ) ) {
			return new \WP_Error( 'yeffoprint_svg_invalid', __( 'That doesn’t look like an SVG file.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$warnings = [];

		// Size: keep the viewBox, drop fixed width/height so it scales.
		$view_box = trim( (string) $root->getAttribute( 'viewBox' ) );
		$box      = array_map( 'floatval', preg_split( '/[\s,]+/', $view_box ) ?: [] );
		if ( 4 !== count( $box ) || $box[2] <= 0 || $box[3] <= 0 ) {
			$w = (float) $root->getAttribute( 'width' );
			$h = (float) $root->getAttribute( 'height' );
			if ( $w <= 0 || $h <= 0 ) {
				return new \WP_Error( 'yeffoprint_svg_size', __( 'That SVG has no size. Export it with a viewBox (Illustrator: “Responsive” off is fine, artboard size is used).', 'yeffoprint-core' ), [ 'status' => 400 ] );
			}
			$box = [ 0.0, 0.0, $w, $h ];
		}

		// Class → fill/stroke from <style> blocks, so parts painted via
		// Illustrator's .st0 classes still get their starting colors.
		$class_paint = self::class_paint( $doc );

		// Read layer names before namespaced editor attributes are stripped.
		$names = [];
		foreach ( self::all_elements( $root ) as $el ) {
			$name = '';
			foreach ( [ 'id', 'data-name' ] as $attr ) {
				if ( $el->hasAttribute( $attr ) && self::part_key( $el->getAttribute( $attr ) ) . self::special_name( $el->getAttribute( $attr ) ) ) {
					$name = $el->getAttribute( $attr );
					break;
				}
			}
			if ( '' === $name ) {
				foreach ( $el->attributes as $attr ) {
					if ( 'label' === $attr->localName && $attr->namespaceURI && false !== strpos( $attr->namespaceURI, 'inkscape' ) ) {
						$name = $attr->value;
					}
				}
			}
			if ( '' !== $name ) {
				$names[] = [ $el, $name ];
			}
		}

		self::clean( $root, $warnings );

		// Scope the file's own CSS so it never leaks onto the page.
		$scope = 'yp-svg-' . substr( md5( $raw ), 0, 8 );
		foreach ( iterator_to_array( $doc->getElementsByTagName( 'style' ) ) as $style ) {
			$style->textContent = self::scope_css( $style->textContent, $scope );
		}

		// Color parts.
		$parts = [];
		foreach ( $names as [ $el, $name ] ) {
			if ( ! $el->parentNode ) {
				continue; // Removed by clean().
			}
			$key = self::part_key( $name );
			if ( ! $key ) {
				continue;
			}
			if ( isset( $parts[ $key ] ) ) {
				// Same name twice (e.g. two "accent" shapes): both follow one pick.
				$parts[ $key ]['elements'][] = $el;
				continue;
			}
			$parts[ $key ] = [ 'key' => $key, 'elements' => [ $el ] ];
		}

		foreach ( $parts as $key => &$part ) {
			$colors = [];
			foreach ( $part['elements'] as $el ) {
				$colors = array_merge( $colors, self::recolor( $el, $key, $class_paint ) );
			}
			$part['default'] = self::most_common( $colors ) ?: '#000000';
			// Fill in the fallback now that the starting color is known.
			foreach ( $part['elements'] as $el ) {
				self::set_fallback( $el, $key, $part['default'] );
			}
			if ( ! $colors ) {
				$warnings[] = sprintf( __( '“%s” has no solid color to change (gradients and images can’t be recolored).', 'yeffoprint-core' ), $key );
			}
			unset( $part['elements'] );
			$part = array_merge( $part, self::part_label( $key ) );
		}
		unset( $part );
		$parts = array_values( $parts );
		usort( $parts, static function ( $a, $b ) {
			return self::part_order( $a['key'] ) <=> self::part_order( $b['key'] ) ?: strnatcmp( $a['key'], $b['key'] );
		} );

		// Fit boxes and the QR spot.
		$qr = false;
		foreach ( $names as [ $el, $name ] ) {
			if ( ! $el->parentNode ) {
				continue;
			}
			$special = self::special_name( $name );
			if ( 'qr' === $special ) {
				$el->setAttribute( 'data-yp-qr', '1' );
				$el->setAttribute( 'style', 'fill:none;stroke:none' );
				$qr = true;
			} elseif ( 0 === strpos( $special, 'box-' ) ) {
				$el->setAttribute( 'data-yp-box', substr( $special, 4 ) );
				$el->setAttribute( 'style', 'fill:none;stroke:none' );
			}
		}

		// Text slots: flatten each slot's text runs to one, keep the first run's spot and style.
		$slots = [];
		foreach ( iterator_to_array( $doc->getElementsByTagName( 'text' ) ) as $text ) {
			$content = preg_replace( '/\s+/u', ' ', (string) $text->textContent );
			if ( ! preg_match_all( '/\{\s*([a-z0-9_-]+)\s*\}/i', $content, $m ) ) {
				continue;
			}
			$template = trim( preg_replace( '/\{\s*([a-z0-9_-]+)\s*\}/i', '{$1}', $content ) );
			$template = preg_replace_callback( '/\{([a-z0-9_-]+)\}/i', static function ( $match ) {
				return '{' . strtolower( $match[1] ) . '}';
			}, $template );

			$first = null;
			foreach ( $text->childNodes as $child ) {
				if ( XML_ELEMENT_NODE === $child->nodeType && 'tspan' === strtolower( $child->localName ) ) {
					$first = $child;
					break;
				}
			}
			if ( $first ) {
				foreach ( [ 'x', 'y', 'dx', 'dy', 'font-family', 'font-size', 'font-weight', 'font-style', 'letter-spacing', 'fill' ] as $attr ) {
					if ( $first->hasAttribute( $attr ) && ! $text->hasAttribute( $attr ) ) {
						$text->setAttribute( $attr, $first->getAttribute( $attr ) );
					}
				}
				$class = trim( $text->getAttribute( 'class' ) . ' ' . $first->getAttribute( 'class' ) );
				if ( '' !== $class ) {
					$text->setAttribute( 'class', $class );
				}
				if ( $first->hasAttribute( 'style' ) ) {
					$text->setAttribute( 'style', trim( $first->getAttribute( 'style' ) . ';' . $text->getAttribute( 'style' ), ';' ) );
				}
			}
			while ( $text->firstChild ) {
				$text->removeChild( $text->firstChild );
			}
			$text->appendChild( $doc->createTextNode( $template ) );
			$text->setAttribute( 'data-yp-slot', $template );
			$slots = array_merge( $slots, array_map( 'strtolower', $m[1] ) );
		}
		$slots = array_values( array_unique( $slots ) );

		if ( ! $parts ) {
			$warnings[] = __( 'No color parts found. Name layers bg, text, accent, stripe-1… so customers can recolor them.', 'yeffoprint-core' );
		}
		if ( ! $slots ) {
			$warnings[] = __( 'No text slots found. Type {compound}, {strength}, {batch}, {exp} where customer text goes.', 'yeffoprint-core' );
		}

		$root->removeAttribute( 'width' );
		$root->removeAttribute( 'height' );
		$root->setAttribute( 'viewBox', implode( ' ', array_map( static function ( $n ) {
			return rtrim( rtrim( number_format( $n, 3, '.', '' ), '0' ), '.' );
		}, $box ) ) );
		$root->setAttribute( 'class', trim( 'yp-svg ' . $scope ) );
		$root->setAttribute( 'xmlns', 'http://www.w3.org/2000/svg' );

		$markup = $doc->saveXML( $root );
		// Plain svg namespace only: drop leftover editor xmlns declarations.
		$markup = preg_replace( '/\sxmlns:(?!xlink)[a-z0-9_-]+="[^"]*"/i', '', $markup );

		return [
			'markup'   => $markup,
			'parts'    => $parts,
			'slots'    => $slots,
			'qr'       => $qr,
			'width'    => (float) $box[2],
			'height'   => (float) $box[3],
			'warnings' => $warnings,
		];
	}

	/** `bg`, `text`, `accent`, `accent-2`, `stripe-3`, `color-border`… from a layer name, else ''. */
	public static function part_key( string $name ): string {
		$name = self::normalize_name( $name );
		if ( 'background' === $name ) {
			return 'bg';
		}
		if ( preg_match( '/^(bg|text|accent|accent-\d{1,2}|stripe-\d{1,2}|color-[a-z0-9][a-z0-9-]{0,30})$/', $name ) ) {
			return $name;
		}
		return '';
	}

	/** `qr` or `box-<slot>`, else ''. */
	private static function special_name( string $name ): string {
		$name = self::normalize_name( $name );
		if ( 'qr' === $name || 'box-qr' === $name ) {
			return 'qr';
		}
		return preg_match( '/^box-[a-z0-9_-]+$/', $name ) ? $name : '';
	}

	/** Lowercase, spaces/underscores to dashes, Illustrator's `_2_` duplicate suffix dropped. */
	private static function normalize_name( string $name ): string {
		$name = strtolower( trim( $name ) );
		$name = preg_replace( '/_x([0-9a-f]{2})_/', '', $name ); // Illustrator's escaped characters.
		$name = preg_replace( '/_\d+_$/', '', $name );
		$name = preg_replace( '/[\s_]+/', '-', $name );
		return trim( $name, '-' );
	}

	private static function part_label( string $key ): array {
		if ( 'bg' === $key ) {
			return [ 'label' => __( 'Background', 'yeffoprint-core' ), 'hint' => __( 'Behind everything', 'yeffoprint-core' ), 'group' => 'bg' ];
		}
		if ( 'text' === $key ) {
			return [ 'label' => __( 'Text', 'yeffoprint-core' ), 'hint' => __( 'Compound name and details', 'yeffoprint-core' ), 'group' => 'text' ];
		}
		if ( preg_match( '/^(accent|stripe)-(\d+)$/', $key, $m ) ) {
			$plural = 'stripe' === $m[1] ? __( 'Stripes', 'yeffoprint-core' ) : __( 'Accents', 'yeffoprint-core' );
			return [ 'label' => ucfirst( $m[1] ) . ' ' . $m[2], 'hint' => '', 'group' => $plural ];
		}
		if ( 'accent' === $key ) {
			return [ 'label' => __( 'Accent', 'yeffoprint-core' ), 'hint' => __( 'Logo and highlights', 'yeffoprint-core' ), 'group' => 'accent' ];
		}
		$label = ucfirst( str_replace( '-', ' ', preg_replace( '/^color-/', '', $key ) ) );
		return [ 'label' => $label, 'hint' => '', 'group' => $key ];
	}

	private static function part_order( string $key ): int {
		if ( 'bg' === $key ) {
			return 0;
		}
		if ( 'text' === $key ) {
			return 1;
		}
		if ( 0 === strpos( $key, 'accent' ) ) {
			return 2;
		}
		if ( 0 === strpos( $key, 'stripe' ) ) {
			return 3;
		}
		return 4;
	}

	/** Allow-list pass: unknown elements, scripts, event handlers and outside URLs are removed. */
	private static function clean( \DOMElement $root, array &$warnings ): void {
		$dropped = [];
		foreach ( array_reverse( self::all_elements( $root ) ) as $el ) {
			$tag = strtolower( $el->localName );
			if ( $el !== $root && ( ! in_array( $tag, self::ELEMENTS, true ) || ( $el->namespaceURI && 'http://www.w3.org/2000/svg' !== $el->namespaceURI ) ) ) {
				// Editor data (other namespaces, <metadata>) goes quietly; only
				// real drawing parts that can't be shown are worth a warning.
				$foreign = $el->namespaceURI && 'http://www.w3.org/2000/svg' !== $el->namespaceURI;
				if ( ! $foreign && ! in_array( $tag, [ 'metadata', 'namedview', 'script', 'foreignobject' ], true ) ) {
					$dropped[ $tag ] = true;
				}
				$el->parentNode->removeChild( $el );
				continue;
			}

			$remove = [];
			foreach ( $el->attributes as $attr ) {
				$name  = strtolower( $attr->nodeName );
				$value = (string) $attr->value;
				$local = strtolower( $attr->localName );

				if ( 0 === strpos( $local, 'on' ) ) {
					$remove[] = $attr;
				} elseif ( 'href' === $local ) {
					if ( ! self::safe_href( $value ) ) {
						$remove[] = $attr;
					}
				} elseif ( $attr->namespaceURI && 'xml:space' !== $name ) {
					$remove[] = $attr; // Editor-only attributes (inkscape:, sodipodi:, i:…).
				} elseif ( preg_match( '/javascript:|expression\s*\(|@import/i', $value ) ) {
					$remove[] = $attr;
				} elseif ( preg_match_all( '/url\(\s*([\'"]?)([^)\'"]*)\1\s*\)/i', $value, $urls ) ) {
					foreach ( $urls[2] as $url ) {
						if ( '#' !== substr( trim( $url ), 0, 1 ) ) {
							$remove[] = $attr;
							break;
						}
					}
				}
			}
			foreach ( $remove as $attr ) {
				$el->removeAttributeNode( $attr );
			}

			// <use> may only point inside the file.
			if ( 'use' === $tag && ! $el->hasAttribute( 'href' ) && ! $el->hasAttributeNS( 'http://www.w3.org/1999/xlink', 'href' ) ) {
				$el->parentNode->removeChild( $el );
				continue;
			}
			if ( 'use' === $tag ) {
				$href = $el->getAttribute( 'href' ) ?: $el->getAttributeNS( 'http://www.w3.org/1999/xlink', 'href' );
				if ( '#' !== substr( $href, 0, 1 ) ) {
					$el->parentNode->removeChild( $el );
				}
			}
		}
		if ( $dropped ) {
			$warnings[] = sprintf( __( 'Removed parts the site can’t show: %s.', 'yeffoprint-core' ), implode( ', ', array_keys( $dropped ) ) );
		}
	}

	private static function safe_href( string $value ): bool {
		$value = trim( $value );
		return '#' === substr( $value, 0, 1 ) || (bool) preg_match( '#^data:image/(png|jpe?g|gif|webp);base64,#i', $value );
	}

	/** Scopes every selector to the file's own class and drops anything that loads from outside. */
	private static function scope_css( string $css, string $scope ): string {
		$css = preg_replace( '#/\*.*?\*/#s', '', $css );
		$css = preg_replace( '/@import[^;]*;?/i', '', $css );
		$css = preg_replace( '/url\(\s*([\'"]?)(?!#|data:font|data:application\/(x-)?font)[^)]*\)/i', 'none', $css );
		$css = preg_replace( '/expression\s*\(|javascript:|behavior\s*:/i', '', $css );
		$css = str_replace( '<', '', $css );

		$out    = '';
		$offset = 0;
		while ( preg_match( '/([^{}]+)\{([^{}]*)\}/', $css, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
			$selectors = trim( $m[1][0] );
			$body      = $m[2][0];
			$offset    = $m[0][1] + strlen( $m[0][0] );
			if ( 0 === stripos( $selectors, '@font-face' ) ) {
				$out .= '@font-face{' . $body . '}';
				continue;
			}
			if ( '@' === substr( $selectors, 0, 1 ) ) {
				continue; // Other at-rules aren't needed for a static label.
			}
			$scoped = implode( ',', array_map( static function ( $selector ) use ( $scope ) {
				return '.' . $scope . ' ' . trim( $selector );
			}, explode( ',', $selectors ) ) );
			$out .= $scoped . '{' . $body . '}';
		}
		return $out;
	}

	/** .st0 → [ fill, stroke ] from the file's <style> blocks. */
	private static function class_paint( \DOMDocument $doc ): array {
		$map = [];
		foreach ( $doc->getElementsByTagName( 'style' ) as $style ) {
			if ( ! preg_match_all( '/([^{}]+)\{([^{}]*)\}/', (string) $style->textContent, $rules, PREG_SET_ORDER ) ) {
				continue;
			}
			foreach ( $rules as $rule ) {
				$decl = self::parse_style( $rule[2] );
				foreach ( explode( ',', $rule[1] ) as $selector ) {
					if ( preg_match( '/^\s*\.([a-z0-9_-]+)\s*$/i', $selector, $sm ) ) {
						$map[ $sm[1] ] = array_merge( $map[ $sm[1] ] ?? [], array_intersect_key( $decl, [ 'fill' => 1, 'stroke' => 1 ] ) );
					}
				}
			}
		}
		return $map;
	}

	private static function parse_style( string $style ): array {
		$out = [];
		foreach ( explode( ';', $style ) as $decl ) {
			$pair = explode( ':', $decl, 2 );
			if ( 2 === count( $pair ) ) {
				$out[ strtolower( trim( $pair[0] ) ) ] = trim( $pair[1] );
			}
		}
		return $out;
	}

	/**
	 * Points every solid fill/stroke inside a part at the part's CSS
	 * variable. Returns the solid colors it replaced (hex), so the
	 * most common one becomes the part's starting color.
	 */
	private static function recolor( \DOMElement $part, string $key, array $class_paint ): array {
		$found = [];
		$els   = array_merge( [ $part ], self::all_elements( $part, false ) );
		foreach ( $els as $el ) {
			$tag = strtolower( $el->localName );
			if ( ! in_array( $tag, self::PAINTED, true ) && 'g' !== $tag ) {
				continue;
			}
			$style = self::parse_style( $el->getAttribute( 'style' ) );
			foreach ( [ 'fill', 'stroke' ] as $prop ) {
				$value = $style[ $prop ] ?? $el->getAttribute( $prop );
				if ( '' === $value ) {
					foreach ( preg_split( '/\s+/', trim( $el->getAttribute( 'class' ) ) ) as $class ) {
						if ( isset( $class_paint[ $class ][ $prop ] ) ) {
							$value = $class_paint[ $class ][ $prop ];
						}
					}
				}
				$hex = self::to_hex( $value );
				if ( $hex ) {
					$found[]        = $hex;
					$style[ $prop ] = 'var(--yp-' . $key . ',@@)';
				} elseif ( '' === $value && 'fill' === $prop && $el === $part ) {
					// Unpainted children inherit from the part itself.
					$style['fill'] = 'var(--yp-' . $key . ',@@)';
				}
			}
			if ( $style ) {
				if ( isset( $style['fill'] ) && false !== strpos( $style['fill'], '--yp-' ) ) {
					$el->removeAttribute( 'fill' );
				}
				if ( isset( $style['stroke'] ) && false !== strpos( $style['stroke'], '--yp-' ) ) {
					$el->removeAttribute( 'stroke' );
				}
				$el->setAttribute( 'style', self::build_style( $style ) );
			}
		}
		return $found;
	}

	private static function set_fallback( \DOMElement $part, string $key, string $hex ): void {
		foreach ( array_merge( [ $part ], self::all_elements( $part, false ) ) as $el ) {
			if ( $el->hasAttribute( 'style' ) ) {
				$el->setAttribute( 'style', str_replace( 'var(--yp-' . $key . ',@@)', 'var(--yp-' . $key . ',' . $hex . ')', $el->getAttribute( 'style' ) ) );
			}
		}
	}

	private static function build_style( array $style ): string {
		$out = [];
		foreach ( $style as $prop => $value ) {
			if ( '' !== $prop && '' !== $value ) {
				$out[] = $prop . ':' . $value;
			}
		}
		return implode( ';', $out );
	}

	/** #RGB/#RRGGBB/rgb()/a few names → #RRGGBB; none/url()/currentColor → ''. */
	public static function to_hex( string $value ): string {
		$value = strtolower( trim( $value ) );
		$named = [ 'black' => '#000000', 'white' => '#FFFFFF', 'red' => '#FF0000', 'blue' => '#0000FF', 'green' => '#008000', 'yellow' => '#FFFF00', 'gray' => '#808080', 'grey' => '#808080', 'navy' => '#000080', 'gold' => '#FFD700' ];
		if ( isset( $named[ $value ] ) ) {
			return $named[ $value ];
		}
		if ( preg_match( '/^#([0-9a-f]{3})$/', $value, $m ) ) {
			return strtoupper( '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2] );
		}
		if ( preg_match( '/^#[0-9a-f]{6}$/', $value ) ) {
			return strtoupper( $value );
		}
		if ( preg_match( '/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/', $value, $m ) ) {
			return sprintf( '#%02X%02X%02X', min( 255, (int) $m[1] ), min( 255, (int) $m[2] ), min( 255, (int) $m[3] ) );
		}
		return '';
	}

	private static function most_common( array $colors ): string {
		if ( ! $colors ) {
			return '';
		}
		$counts = array_count_values( $colors );
		arsort( $counts );
		return (string) array_key_first( $counts );
	}

	/** @return \DOMElement[] */
	private static function all_elements( \DOMElement $root, bool $include_root = true ): array {
		$out = $include_root ? [ $root ] : [];
		foreach ( $root->getElementsByTagName( '*' ) as $el ) {
			$out[] = $el;
		}
		return $out;
	}
}
