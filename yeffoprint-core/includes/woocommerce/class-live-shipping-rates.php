<?php
/**
 * Live shipping rates at checkout. Direct request: "Can we look into
 * charging customers live rates for shipping based on their address? ...
 * Can we automatically add an additional $1 to whatever the live rate is
 * to cover packing materials?" Then: "I'd like a toggle in my dashboard
 * to turn live rates on/off."
 *
 * Once the customer's address (at least a ZIP) is known, Shippo is asked
 * for real prices for the Settings › Shipping default package, and each
 * of the zone's own flat rates is repriced in place: USPS Ground
 * Advantage, UPS 2nd Day Air and International keep their rate ids and
 * instance ids, so everything keyed by those (3D print shipping prices in
 * class-print-shipping.php, add-on orders, reports) keeps working. Only
 * the cost changes, to the live price plus the packing add-on. Services
 * the zone has no flat rate for (USPS Priority Mail, UPS Ground) are
 * added as extra rates when ticked.
 *
 * Anything Shippo doesn't return in time leaves that flat rate as it was,
 * so a slow or failed quote never leaves a customer without a way to
 * check out. Free shipping and local pickup are left alone, unless free
 * shipping over the zone's minimum is switched off here.
 *
 * WooCommerce caches rates per package, so every change to these options
 * bumps the shipping transient version (same as class-local-pickup.php),
 * and quotes are cached per address for a few hours so a checkout
 * re-render doesn't call Shippo again.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Live_Shipping_Rates {

	public const ENABLED_OPTION   = 'yeffoprint_live_rates_enabled';
	public const MARKUP_OPTION    = 'yeffoprint_live_rates_markup';
	public const KEEP_FREE_OPTION = 'yeffoprint_live_rates_keep_free';
	public const SERVICES_OPTION  = 'yeffoprint_live_rates_services';

	/** Rate id prefix for services added on top of the zone's flat rates. */
	public const METHOD_ID = 'yp_live_rate';

	/** Cheapest international rate from any carrier, rather than one Shippo service. */
	public const INTERNATIONAL = 'international';

	private const DEFAULT_MARKUP = 1.0;

	/**
	 * Shippo servicelevel token => name shown at checkout, the words that
	 * find the zone's matching flat rate by its title, and the carrier's
	 * usual transit time, shown when Shippo gives no estimate (or live
	 * rates are off).
	 */
	public const SERVICES = [
		'usps_ground_advantage' => [ 'label' => 'USPS Ground Advantage', 'match' => 'ground advantage', 'days' => '2–5' ],
		'ups_second_day_air'    => [ 'label' => 'UPS 2nd Day Air', 'match' => '2nd day', 'days' => '2' ],
		'usps_priority'         => [ 'label' => 'USPS Priority Mail', 'match' => 'priority', 'days' => '1–3' ],
		'ups_ground'            => [ 'label' => 'UPS Ground', 'match' => 'ups ground', 'days' => '1–5' ],
		self::INTERNATIONAL     => [ 'label' => 'Cheapest international option', 'match' => 'international', 'days' => '6–15' ],
	];

	private const DEFAULT_SERVICES = [ 'usps_ground_advantage', 'ups_second_day_air', 'usps_priority', self::INTERNATIONAL ];

	/** Short, so checkout never hangs on Shippo: past this the flat rates show instead. */
	private const TIMEOUT = 5;

	private const CACHE_TTL         = 6 * HOUR_IN_SECONDS;
	private const FAILURE_CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	public function __construct() {
		// Before class-print-shipping.php (90), so a 3D print's own price
		// still wins when it's higher than the live rate, and before local
		// pickup (100) and add-on orders (110).
		add_filter( 'woocommerce_package_rates', [ $this, 'apply_live_rates' ], 80, 2 );

		// Direct request: "Can checkout show delivery time estimates?" Last,
		// so it also covers rates other filters add or reprice. The Checkout
		// block shows a rate's delivery_time under its name.
		add_filter( 'woocommerce_package_rates', [ $this, 'add_delivery_times' ], 120, 2 );

		foreach ( [ self::ENABLED_OPTION, self::MARKUP_OPTION, self::KEEP_FREE_OPTION, self::SERVICES_OPTION ] as $option ) {
			add_action( 'add_option_' . $option, [ YeffoPrint_Local_Pickup::class, 'bust_rate_cache' ] );
			add_action( 'update_option_' . $option, [ YeffoPrint_Local_Pickup::class, 'bust_rate_cache' ] );
		}
	}

	public static function is_enabled(): bool {
		return (bool) get_option( self::ENABLED_OPTION, false );
	}

	public static function markup(): float {
		return max( 0.0, (float) get_option( self::MARKUP_OPTION, self::DEFAULT_MARKUP ) );
	}

	public static function keep_free_shipping(): bool {
		return (bool) get_option( self::KEEP_FREE_OPTION, true );
	}

	/** @return string[] Ticked service keys from SERVICES. */
	public static function services(): array {
		$saved = get_option( self::SERVICES_OPTION, null );
		if ( ! is_array( $saved ) ) {
			return self::DEFAULT_SERVICES;
		}
		return array_values( array_intersect( array_keys( self::SERVICES ), $saved ) );
	}

	/** Settings screen payload. */
	public static function settings(): array {
		return [
			'enabled'    => self::is_enabled(),
			'markup'     => self::markup(),
			'keep_free'  => self::keep_free_shipping(),
			'services'   => self::services(),
			'all'        => array_map( static fn( string $key, array $service ): array => [ 'key' => $key, 'label' => $service['label'] ], array_keys( self::SERVICES ), self::SERVICES ),
			'configured' => YeffoPrint_Shippo_Settings::is_configured(),
		];
	}

	/** Int, not bool: update_option( key, false ) never creates a missing row, and keep_free defaults to on. */
	public static function save( array $params ): void {
		update_option( self::ENABLED_OPTION, (int) ! empty( $params['enabled'] ) );
		update_option( self::MARKUP_OPTION, round( max( 0.0, (float) ( $params['markup'] ?? self::DEFAULT_MARKUP ) ), 2 ) );
		update_option( self::KEEP_FREE_OPTION, (int) ! empty( $params['keep_free'] ) );
		update_option( self::SERVICES_OPTION, array_values( array_intersect( array_keys( self::SERVICES ), array_map( 'strval', (array) ( $params['services'] ?? [] ) ) ) ) );
	}

	/**
	 * @param \WC_Shipping_Rate[] $rates
	 * @return \WC_Shipping_Rate[]
	 */
	public function apply_live_rates( array $rates, array $package ): array {
		if ( ! self::is_enabled() || ! YeffoPrint_Shippo_Settings::is_configured() || empty( $package['contents'] ) ) {
			return $rates;
		}

		$destination = (array) ( $package['destination'] ?? [] );
		$country     = strtoupper( (string) ( $destination['country'] ?? '' ) );
		$postcode    = trim( (string) ( $destination['postcode'] ?? '' ) );
		$domestic    = YeffoPrint_Shippo_Settings::domestic_country() === $country;

		// No quote is possible yet; the flat rates show until the ZIP is in.
		if ( '' === $country || ( $domestic && '' === $postcode ) ) {
			return $rates;
		}

		if ( ! self::keep_free_shipping() ) {
			foreach ( $rates as $id => $rate ) {
				if ( 'free_shipping' === $rate->get_method_id() ) {
					unset( $rates[ $id ] );
				}
			}
		}

		$quotes = self::quotes( $destination, $domestic, (float) ( $package['contents_cost'] ?? 0 ) );
		if ( null === $quotes ) {
			return $rates;
		}

		$services = self::services();
		$markup   = self::markup();
		$repriced = [];

		foreach ( $rates as $id => $rate ) {
			if ( 'flat_rate' !== $rate->get_method_id() ) {
				continue;
			}

			$key = $domestic ? self::service_for_label( $rate->get_label() ) : self::INTERNATIONAL;
			if ( null === $key || ( ! $domestic && ! in_array( self::INTERNATIONAL, $services, true ) ) ) {
				continue;
			}

			if ( $domestic && ! in_array( $key, $services, true ) ) {
				unset( $rates[ $id ] );
				continue;
			}

			$repriced[] = $key;
			if ( isset( $quotes[ $key ] ) ) {
				self::reprice( $rate, $quotes[ $key ], $markup, $package );
			}
		}

		// Extra services the zone has no flat rate for. Not for carts with a
		// 3D print priced per shipping option: those prices only cover the
		// zone's own options, and the quote is for a label-sized mailer.
		if ( $domestic && empty( $package[ YeffoPrint_Print_Shipping::PACKAGE_KEY ] ) ) {
			foreach ( $services as $key ) {
				if ( self::INTERNATIONAL === $key || in_array( $key, $repriced, true ) || ! isset( $quotes[ $key ] ) ) {
					continue;
				}
				$rate = new WC_Shipping_Rate( self::METHOD_ID . ':' . $key, self::SERVICES[ $key ]['label'], 0, [], self::METHOD_ID );
				self::reprice( $rate, $quotes[ $key ], $markup, $package );
				$rates[ $rate->get_id() ] = $rate;
			}
		}

		uasort( $rates, static fn( $a, $b ) => (float) $a->get_cost() <=> (float) $b->get_cost() );

		return $rates;
	}

	/** @param array{amount:float, days:?int} $quote */
	private static function reprice( WC_Shipping_Rate $rate, array $quote, float $markup, array $package ): void {
		$rate->set_cost( (string) round( $quote['amount'] + $markup, 2 ) );
		$rate->set_taxes( [] );
		$rate->add_meta_data( '_yp_live_rate', (string) round( $quote['amount'], 2 ) );
		if ( $quote['days'] ) {
			$rate->set_delivery_time( self::delivery_text( (string) $quote['days'], $package ) );
		}
	}

	/**
	 * Direct request: "Make sure to somehow indicate that those dates are
	 * after labels are produced, not from the date of order."
	 */
	private static function delivery_text( string $days, array $package ): string {
		// With live production time on, real dates that already include it.
		$arrival = YeffoPrint_Production_Time::arrival_text( $package, $days );
		if ( null !== $arrival ) {
			return $arrival;
		}

		return '1' === $days
			/* translators: shown under a shipping option at checkout */
			? __( 'Arrives 1 business day after your order is made and shipped', 'yeffoprint-core' )
			/* translators: %s: number or range of business days, e.g. 2–5 */
			: sprintf( __( 'Arrives %s business days after your order is made and shipped', 'yeffoprint-core' ), $days );
	}

	/**
	 * Fills in the usual transit time on every shipping option that has
	 * none yet: live rates with a Shippo estimate already carry their own
	 * (reprice()). Free shipping goes out by USPS Ground Advantage. Local
	 * pickup and add-on orders' "ships with" rate get nothing.
	 *
	 * @param \WC_Shipping_Rate[] $rates
	 * @return \WC_Shipping_Rate[]
	 */
	public function add_delivery_times( array $rates, array $package ): array {
		foreach ( $rates as $rate ) {
			if ( '' !== (string) $rate->get_delivery_time() ) {
				continue;
			}

			$method = $rate->get_method_id();
			if ( 'free_shipping' === $method ) {
				$key = 'usps_ground_advantage';
			} elseif ( self::METHOD_ID === $method ) {
				$key = substr( $rate->get_id(), strlen( self::METHOD_ID ) + 1 );
			} elseif ( 'flat_rate' === $method ) {
				$key = false !== stripos( $rate->get_label(), 'international' ) ? self::INTERNATIONAL : self::service_for_label( $rate->get_label() );
			} else {
				continue;
			}

			if ( isset( self::SERVICES[ $key ] ) ) {
				$rate->set_delivery_time( self::delivery_text( self::SERVICES[ $key ]['days'], $package ) );
			}
		}

		return $rates;
	}

	/** The SERVICES key a zone flat rate stands for, from its title, or null for one live rates don't touch. */
	public static function service_for_label( string $label ): ?string {
		$label = strtolower( $label );
		foreach ( self::SERVICES as $key => $service ) {
			if ( self::INTERNATIONAL !== $key && false !== strpos( $label, $service['match'] ) ) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * Live price per SERVICES key for one address, from cache when it's
	 * there. Null when Shippo couldn't be reached (also cached briefly, so
	 * a Shippo outage doesn't slow every checkout render down).
	 *
	 * @return array<string, array{amount:float, days:?int}>|null
	 */
	private static function quotes( array $destination, bool $domestic, float $value ): ?array {
		$address = [
			'street1' => (string) ( $destination['address_1'] ?? $destination['address'] ?? '' ),
			'street2' => (string) ( $destination['address_2'] ?? '' ),
			'city'    => (string) ( $destination['city'] ?? '' ),
			'state'   => (string) ( $destination['state'] ?? '' ),
			'zip'     => (string) ( $destination['postcode'] ?? '' ),
			'country' => strtoupper( (string) ( $destination['country'] ?? '' ) ),
		];
		$parcel = YeffoPrint_Shippo_Settings::get_default_package();

		$customs = null;
		if ( ! $domestic ) {
			$customs = YeffoPrint_Shippo_Settings::get_customs_defaults() + [ 'value' => max( 1.0, $value ), 'currency' => get_woocommerce_currency() ];
		}

		// Domestic prices only depend on the ZIP, so the street isn't part of
		// the key; international ones get the full address.
		$cache_address = $domestic ? [ $address['country'], $address['zip'] ] : $address;
		$cache_key     = 'yp_live_rates_v2_' . md5( wp_json_encode( [ $cache_address, $parcel, $domestic ? null : round( $value ) ] ) );

		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached['ok'] ? $cached['quotes'] : null;
		}

		$client = new YeffoPrint_Shippo_Client( YeffoPrint_Shippo_Settings::get_api_key(), self::TIMEOUT );
		$result = $client->get_rates( $address, $parcel, $customs );

		if ( is_wp_error( $result ) ) {
			set_transient( $cache_key, [ 'ok' => false ], self::FAILURE_CACHE_TTL );
			return null;
		}

		$quotes = [];
		foreach ( $result['rates'] as $rate ) {
			if ( 'USD' !== strtoupper( $rate['currency'] ) || $rate['amount'] <= 0 ) {
				continue;
			}
			$key = $domestic ? $rate['service_token'] : self::INTERNATIONAL;
			if ( isset( self::SERVICES[ $key ] ) && ( ! isset( $quotes[ $key ] ) || $rate['amount'] < $quotes[ $key ]['amount'] ) ) {
				// Carriers' international estimates leave out customs, so those keep the usual range.
				$quotes[ $key ] = [ 'amount' => $rate['amount'], 'days' => $domestic ? ( $rate['days'] ?: null ) : null ];
			}
		}

		set_transient( $cache_key, [ 'ok' => true, 'quotes' => $quotes ], self::CACHE_TTL );

		return $quotes;
	}
}
