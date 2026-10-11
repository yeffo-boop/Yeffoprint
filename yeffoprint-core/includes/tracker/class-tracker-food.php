<?php
/**
 * YeffoHealth food search: look up a food's calories and macros by name
 * or by scanning its barcode (Today › Food & water › Search food / Scan
 * barcode). Direct request (Jeff): "Is there some kind of api or
 * database of food/calories we can use so people can just search for a
 * food to add macros to their tracker?"
 *
 * Two free databases, always called from here so the API key stays on
 * the server (the tracker page's CSP only lets it talk to this site):
 *
 *   USDA FoodData Central  name search: everyday foods (Survey/SR Legacy/
 *                          Foundation) and US branded products. Public
 *                          domain. Key from api.data.gov, 1,000 calls an
 *                          hour, saved in admin Settings › Integrations
 *                          (or YEFFOPRINT_USDA_API_KEY in wp-config).
 *                          Without one it falls back to DEMO_KEY, which
 *                          is only good for trying it out.
 *   Open Food Facts        barcodes: about 3M packaged products. No key.
 *                          ODbL, so the app credits it under the results.
 *                          USDA's branded foods are the fallback.
 *
 * Every answer is shaped the same way for the app: per-100 g (or mL)
 * nutrients plus the servings it knows, so the app does the serving
 * math. Answers are cached for everyone (keyed by the query, never by
 * who asked), which keeps us well inside both services' limits.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Tracker_Food {

	public const KEY_OPTION = 'yeffoprint_usda_api_key';

	private const USDA_URL = 'https://api.nal.usda.gov/fdc/v1/foods/search';
	private const OFF_URL  = 'https://world.openfoodfacts.org/api/v2/product/';

	/** Generic foods first, then brands, so "banana" isn't a page of banana chips. */
	private const GENERIC_TYPES = 'Survey (FNDDS),SR Legacy,Foundation';

	private const SEARCH_TTL    = WEEK_IN_SECONDS;
	private const BARCODE_TTL   = MONTH_IN_SECONDS;
	private const NOT_FOUND_TTL = DAY_IN_SECONDS;

	/** Lookups one customer can make in 10 minutes. Typing a search is one per pause, so this is generous. */
	private const RATE_LIMIT = 120;

	/** USDA nutrient ids. Energy can come under several, so each key lists its choices in order. */
	private const NUTRIENTS = [
		'kcal'    => [ 1008, 2047, 2048 ],
		'protein' => [ 1003 ],
		'carbs'   => [ 1005, 1050 ],
		'fat'     => [ 1004, 1085 ],
		'fiber'   => [ 1079 ],
	];

	public static function api_key(): string {
		if ( defined( 'YEFFOPRINT_USDA_API_KEY' ) && YEFFOPRINT_USDA_API_KEY ) {
			return (string) YEFFOPRINT_USDA_API_KEY;
		}
		return trim( (string) get_option( self::KEY_OPTION, '' ) );
	}

	/** @return true|\WP_Error */
	public static function check_rate( int $user_id ) {
		$key   = 'ypt_food_rate_' . $user_id;
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT ) {
			return new \WP_Error( 'yeffoprint_food_busy', __( 'That’s a lot of searches. Give it a few minutes and try again.', 'yeffoprint-core' ), [ 'status' => 429 ] );
		}
		set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Foods matching what the customer typed.
	 *
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public static function search( string $query ) {
		$query = trim( preg_replace( '/\s+/', ' ', $query ) );
		if ( mb_strlen( $query ) < 2 ) {
			return [];
		}
		$query     = mb_substr( $query, 0, 80 );
		$cache_key = 'ypt_food_s_' . md5( mb_strtolower( $query ) );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$generic = self::usda( [ 'query' => $query, 'dataType' => self::GENERIC_TYPES, 'pageSize' => 12 ] );
		if ( is_wp_error( $generic ) ) {
			return $generic;
		}
		$branded = self::usda( [ 'query' => $query, 'dataType' => 'Branded', 'pageSize' => 18 ] );
		if ( is_wp_error( $branded ) ) {
			$branded = [];
		}

		$out  = [];
		$seen = [];
		foreach ( array_merge( $generic, $branded ) as $food ) {
			$item = self::from_usda( $food );
			if ( ! $item ) {
				continue;
			}
			$dupe = mb_strtolower( $item['name'] . '|' . $item['brand'] );
			if ( isset( $seen[ $dupe ] ) ) {
				continue;
			}
			$seen[ $dupe ] = true;
			$out[]         = $item;
		}

		set_transient( $cache_key, $out, self::SEARCH_TTL );
		return $out;
	}

	/**
	 * The product with this barcode, or null when neither database knows it.
	 *
	 * @return array<string,mixed>|null|\WP_Error
	 */
	public static function barcode( string $code ) {
		$code = preg_replace( '/\D/', '', $code );
		if ( strlen( $code ) < 8 || strlen( $code ) > 14 ) {
			return null;
		}
		$cache_key = 'ypt_food_b_' . $code;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : null;
		}

		$item = self::open_food_facts( $code );
		if ( is_wp_error( $item ) ) {
			$item = null;
		}
		if ( ! $item ) {
			$item = self::usda_barcode( $code );
		}

		set_transient( $cache_key, $item ?: 'none', $item ? self::BARCODE_TTL : self::NOT_FOUND_TTL );
		return $item ?: null;
	}

	/** @return array<int,array<string,mixed>>|\WP_Error The raw `foods` list. */
	private static function usda( array $args ) {
		$key      = self::api_key();
		$url      = add_query_arg( array_map( 'rawurlencode', $args + [ 'api_key' => '' !== $key ? $key : 'DEMO_KEY' ] ), self::USDA_URL );
		$response = wp_remote_get( $url, [ 'timeout' => 8, 'headers' => [ 'Accept' => 'application/json' ] ] );
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'yeffoprint_food_down', __( 'Food search isn’t answering right now. Try again in a minute.', 'yeffoprint-core' ), [ 'status' => 502 ] );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 429 === $status ) {
			return new \WP_Error( 'yeffoprint_food_busy', __( 'Food search is busy. Try again in a few minutes.', 'yeffoprint-core' ), [ 'status' => 429 ] );
		}
		if ( 200 !== $status ) {
			return new \WP_Error( 'yeffoprint_food_down', __( 'Food search isn’t answering right now. Try again in a minute.', 'yeffoprint-core' ), [ 'status' => 502 ] );
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $json['foods'] ?? null ) ? $json['foods'] : [];
	}

	/** USDA's branded foods carry the UPC, so a barcode Open Food Facts doesn't know can still turn up there. */
	private static function usda_barcode( string $code ) {
		$foods = self::usda( [ 'query' => $code, 'dataType' => 'Branded', 'pageSize' => 5 ] );
		if ( is_wp_error( $foods ) ) {
			return null;
		}
		$want = ltrim( $code, '0' );
		foreach ( $foods as $food ) {
			if ( ltrim( (string) ( $food['gtinUpc'] ?? '' ), '0' ) === $want ) {
				$item = self::from_usda( $food );
				if ( $item ) {
					$item['code'] = $code;
					return $item;
				}
			}
		}
		return null;
	}

	/** @return array<string,mixed>|null|\WP_Error */
	private static function open_food_facts( string $code ) {
		$fields   = 'code,product_name,product_name_en,brands,nutriments,serving_size,serving_quantity,serving_quantity_unit,product_quantity,product_quantity_unit';
		$response = wp_remote_get( self::OFF_URL . rawurlencode( $code ) . '.json?fields=' . $fields, [
			'timeout' => 8,
			// Open Food Facts asks every app to name itself.
			'headers' => [ 'User-Agent' => 'YeffoHealth/1.0 (' . home_url( '/' ) . ')' ],
		] );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$p    = is_array( $json['product'] ?? null ) ? $json['product'] : null;
		if ( ! $p || 1 !== (int) ( $json['status'] ?? 0 ) ) {
			return null;
		}
		$n   = is_array( $p['nutriments'] ?? null ) ? $p['nutriments'] : [];
		$per = [
			'kcal'    => self::num( $n['energy-kcal_100g'] ?? null ),
			'protein' => self::num( $n['proteins_100g'] ?? null ),
			'carbs'   => self::num( $n['carbohydrates_100g'] ?? null ),
			'fat'     => self::num( $n['fat_100g'] ?? null ),
			'fiber'   => self::num( $n['fiber_100g'] ?? null ),
		];
		if ( null === $per['kcal'] && isset( $n['energy-kj_100g'] ) ) {
			$per['kcal'] = round( (float) $n['energy-kj_100g'] / 4.184, 1 );
		}
		$name = trim( (string) ( $p['product_name_en'] ?? '' ) ) ?: trim( (string) ( $p['product_name'] ?? '' ) );
		if ( '' === $name || null === $per['kcal'] ) {
			// Known product, but nothing to count: let the customer type the label in.
			return null;
		}

		$unit     = 'ml' === strtolower( (string) ( $p['serving_quantity_unit'] ?? $p['product_quantity_unit'] ?? '' ) ) ? 'mL' : 'g';
		$servings = [];
		$serving  = self::num( $p['serving_quantity'] ?? null );
		if ( $serving > 0 ) {
			$servings[] = [ 'label' => self::serving_label( (string) ( $p['serving_size'] ?? '' ), $serving, $unit ), 'g' => $serving ];
		}
		$whole = self::num( $p['product_quantity'] ?? null );
		if ( $whole > 0 && abs( $whole - (float) $serving ) > 1 && $whole <= 2000 ) {
			$servings[] = [ 'label' => 'Whole package (' . self::fmt( $whole ) . ' ' . $unit . ')', 'g' => $whole ];
		}

		$brands = array_filter( array_map( 'trim', explode( ',', (string) ( $p['brands'] ?? '' ) ) ) );
		return [
			'src'      => 'off',
			'id'       => 'off-' . $code,
			'code'     => $code,
			'name'     => self::tidy( $name ),
			'brand'    => self::tidy( (string) ( reset( $brands ) ?: '' ), 'title' ),
			'unit'     => $unit,
			'per'      => array_map( static function ( $v ) {
				return null === $v ? 0 : $v;
			}, $per ),
			'servings' => $servings,
		];
	}

	/** One USDA search hit in the app's shape, or null without calories. @return array<string,mixed>|null */
	private static function from_usda( array $food ): ?array {
		$values = [];
		foreach ( (array) ( $food['foodNutrients'] ?? [] ) as $row ) {
			$id   = (int) ( $row['nutrientId'] ?? 0 );
			$unit = strtoupper( (string) ( $row['unitName'] ?? '' ) );
			if ( in_array( $id, self::NUTRIENTS['kcal'], true ) && 'KCAL' !== $unit ) {
				continue;
			}
			if ( ! isset( $values[ $id ] ) && isset( $row['value'] ) ) {
				$values[ $id ] = (float) $row['value'];
			}
		}
		$per = [];
		foreach ( self::NUTRIENTS as $key => $ids ) {
			$per[ $key ] = 0;
			foreach ( $ids as $id ) {
				if ( isset( $values[ $id ] ) ) {
					$per[ $key ] = round( $values[ $id ], 1 );
					break;
				}
			}
		}
		$found = array_intersect_key( $values, array_flip( self::NUTRIENTS['kcal'] ) );
		$name  = trim( (string) ( $food['description'] ?? '' ) );
		if ( '' === $name || ! $found ) {
			return null;
		}

		$branded  = 'Branded' === ( $food['dataType'] ?? '' );
		$unit     = in_array( strtolower( (string) ( $food['servingSizeUnit'] ?? '' ) ), [ 'ml', 'mlt' ], true ) ? 'mL' : 'g';
		$servings = [];
		if ( $branded ) {
			$size = self::num( $food['servingSize'] ?? null );
			if ( $size > 0 ) {
				$servings[] = [ 'label' => self::serving_label( (string) ( $food['householdServingFullText'] ?? '' ), $size, $unit ), 'g' => $size ];
			}
		} else {
			// Everyday foods come with household measures ("1 cup", "1 medium").
			foreach ( (array) ( $food['foodMeasures'] ?? [] ) as $m ) {
				$text = trim( (string) ( $m['disseminationText'] ?? '' ) );
				$g    = self::num( $m['gramWeight'] ?? null );
				if ( '' === $text || ! $g || 'Quantity not specified' === $text ) {
					continue;
				}
				$servings[] = [ 'label' => self::tidy( $text, 'lower' ) . ' (' . self::fmt( $g ) . ' g)', 'g' => $g ];
				if ( count( $servings ) >= 4 ) {
					break;
				}
			}
		}

		return [
			'src'      => 'usda',
			'id'       => 'usda-' . (int) ( $food['fdcId'] ?? 0 ),
			'code'     => $branded ? preg_replace( '/\D/', '', (string) ( $food['gtinUpc'] ?? '' ) ) : '',
			'name'     => self::tidy( $name ),
			'brand'    => $branded ? self::tidy( (string) ( $food['brandName'] ?? '' ) ?: (string) ( $food['brandOwner'] ?? '' ), 'title' ) : '',
			'unit'     => $unit,
			'per'      => $per,
			'servings' => $servings,
		];
	}

	/** "1 bottle (414 mL)" from a household text and an amount. */
	private static function serving_label( string $text, float $amount, string $unit ): string {
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );
		$size = self::fmt( $amount ) . ' ' . $unit;
		if ( '' === $text || preg_match( '/^[\d.,]+\s*(g|ml|gram|grams)$/i', $text ) ) {
			return $size;
		}
		// Open Food Facts' text often already has the amount in it: "1 bottle (414 ml)".
		if ( false !== strpos( $text, '(' ) ) {
			return self::tidy( $text, 'lower' );
		}
		return self::tidy( $text, 'lower' ) . ' (' . $size . ')';
	}

	/**
	 * USDA's branded names are ALL CAPS ("GRILLED CHICKEN BREAST STRIPS"),
	 * so those become "Grilled chicken breast strips" ('sentence'), and
	 * brands "Tyson" ('title'). Anything already in mixed case is kept.
	 */
	private static function tidy( string $text, string $case = 'sentence' ): string {
		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) );
		if ( '' !== $text && preg_match( '/[A-Z]/', $text ) && mb_strtoupper( $text ) === $text ) {
			$text = mb_strtolower( $text );
			if ( 'title' === $case ) {
				$text = mb_convert_case( $text, MB_CASE_TITLE );
			} elseif ( 'sentence' === $case ) {
				$text = mb_strtoupper( mb_substr( $text, 0, 1 ) ) . mb_substr( $text, 1 );
			}
		}
		return mb_substr( $text, 0, 90 );
	}

	private static function num( $value ): ?float {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return null;
		}
		$n = (float) $value;
		return is_finite( $n ) && $n >= 0 ? round( $n, 2 ) : null;
	}

	private static function fmt( float $n ): string {
		return rtrim( rtrim( number_format( $n, 1, '.', '' ), '0' ), '.' );
	}
}
