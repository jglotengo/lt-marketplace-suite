<?php
/**
 * LTMS Shipping Parallel Quoter
 *
 * Cotiza todos los proveedores logísticos activos en paralelo usando
 * curl_multi_exec. Timeout máximo: 3 segundos. Aplica badges de
 * "Mejor precio", "Más rápido" y "Recomendado".
 *
 * @package    LTMS
 * @subpackage LTMS/includes/shipping
 * @version    1.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LTMS_Shipping_Parallel_Quoter
 */
class LTMS_Shipping_Parallel_Quoter {

	/** Max time to wait for all providers (seconds). */
	private const PARALLEL_TIMEOUT = 3;

	/**
	 * Cotiza todos los proveedores activos en paralelo.
	 *
	 * @param array $package WooCommerce package (destination, contents).
	 * @return array Normalized rate array sorted by price, with badges.
	 */
	public static function quote_all( array $package ): array {
		$cache_key = self::get_cache_key( $package );
		$cached    = self::get_cached( $cache_key );
		if ( $cached !== null ) {
			return $cached;
		}

		$timeout = (int) LTMS_Core_Config::get( 'ltms_shipping_parallel_timeout', self::PARALLEL_TIMEOUT );
		$weight  = self::get_package_weight( $package );
		$dest    = $package['destination'] ?? [];
		$country = LTMS_Core_Config::get_country();

		$requests = self::build_requests( $weight, $dest, $country );
		if ( empty( $requests ) ) {
			return [];
		}

		$mh      = curl_multi_init();
		$handles = [];

		foreach ( $requests as $name => $opts ) {
			$ch = curl_init();
			curl_setopt_array( $ch, $opts );
			curl_multi_add_handle( $mh, $ch );
			$handles[ $name ] = $ch;
		}

		$start = microtime( true );
		do {
			$status = curl_multi_exec( $mh, $still_running );
			if ( CURLM_OK !== $status ) {
				break;
			}
			if ( $still_running ) {
				curl_multi_select( $mh, 0.2 );
			}
			if ( ( microtime( true ) - $start ) > $timeout ) {
				break; // Hard timeout — exclude remaining providers
			}
		} while ( $still_running );

		$rates = [];
		foreach ( $handles as $name => $ch ) {
			$response  = curl_multi_getcontent( $ch );
			$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			curl_multi_remove_handle( $mh, $ch );
			curl_close( $ch );

			if ( ! $response || $http_code === 0 ) {
				continue; // Timed out — skip silently
			}

			$parsed = self::parse_response( $name, $response, $http_code );
			if ( $parsed !== null ) {
				$rates[] = $parsed;
			}
		}
		curl_multi_close( $mh );

		$rates = self::apply_badges( $rates );
		usort( $rates, static fn( $a, $b ) => $a['cost'] <=> $b['cost'] );

		self::cache_result( $cache_key, $rates );
		return $rates;
	}

	/**
	 * Devuelve la cotización más barata para un paquete (usado por envío absorbido).
	 *
	 * @param array $package WooCommerce package.
	 * @return array|null  ['provider', 'cost', 'label'] o null si no hay cotizaciones.
	 */
	public static function get_cheapest_quote( array $package ): ?array {
		$rates = self::quote_all( $package );
		if ( empty( $rates ) ) return null;
		$cheapest = $rates[0]; // ya ordenado por cost ASC.
		return [
			'provider' => $cheapest['provider'] ?? 'unknown',
			'cost'     => (float) ( $cheapest['cost'] ?? 0 ),
			'label'    => $cheapest['label'] ?? '',
		];
	}

	// ── Private helpers ──────────────────────────────────────────────

	private static function build_requests( float $weight, array $dest, string $country ): array {
		$requests = [];

		// Aveonline — Colombia only
		if ( $country === 'CO' ) {
			$ak = LTMS_Core_Config::get( 'ltms_aveonline_api_key' );
			if ( $ak && ! empty( $dest['city'] ) ) {
				$requests['aveonline'] = self::build_curl_opts(
					'https://api.aveonline.co/v1/rates?' . http_build_query( [
						'destination_city' => $dest['city'],
						'weight'           => $weight,
						'api_key'          => $ak,
					] ),
					[ 'Accept: application/json' ]
				);
			}
		}

		// Heka
		$hk = LTMS_Core_Config::get( 'ltms_heka_api_key' );
		if ( $hk && ! empty( $dest['city'] ) ) {
			$requests['heka'] = self::build_curl_opts(
				'https://api.hekaentrega.co/v1/quotes?' . http_build_query( [
					'city'   => $dest['city'],
					'weight' => $weight,
				] ),
				[
					'Accept: application/json',
					'Authorization: Bearer ' . $hk,
				]
			);
		}

		// Uber Direct (token required)
		$uber_token = get_transient( 'ltms_uber_access_token' );
		if ( $uber_token && ! empty( $dest['postcode'] ) ) {
			$requests['uber'] = self::build_curl_opts(
				'https://api.uber.com/v1/eats/deliveries/quotes',
				[
					'Accept: application/json',
					'Content-Type: application/json',
					'Authorization: Bearer ' . $uber_token,
				]
			);
		}

		return $requests;
	}

	private static function build_curl_opts( string $url, array $headers ): array {
		return [
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 3,
			CURLOPT_CONNECTTIMEOUT => 2,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_SSL_VERIFYPEER => true,
		];
	}

	private static function parse_response( string $name, string $body, int $http_code ): ?array {
		if ( $http_code < 200 || $http_code >= 300 ) {
			return null;
		}
		$data = json_decode( $body, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return null;
		}
		switch ( $name ) {
			case 'aveonline':
				$cost = (float) ( $data['price'] ?? $data['total'] ?? 0 );
				$days = (int) ( $data['delivery_time'] ?? $data['days'] ?? 5 );
				return $cost > 0 ? [ 'provider' => 'aveonline', 'cost' => $cost, 'estimated_days' => $days,
					'label' => 'Aveonline (' . $days . ' días)', 'badges' => [] ] : null;

			case 'heka':
				$cost = (float) ( $data['total_price'] ?? $data['price'] ?? 0 );
				$days = (int) ( $data['estimated_days'] ?? $data['days'] ?? 7 );
				return $cost > 0 ? [ 'provider' => 'heka', 'cost' => $cost, 'estimated_days' => $days,
					'label' => 'Heka (' . $days . ' días)', 'badges' => [] ] : null;

			case 'uber':
				$cost = (float) ( $data['fee']['value'] ?? 0 );
				$eta  = (int) ( $data['dropoff_eta'] ?? 7200 );
				$hrs  = round( $eta / 3600, 1 );
				return $cost > 0 ? [ 'provider' => 'uber', 'cost' => $cost, 'estimated_days' => 0,
					'label' => 'Uber Direct (~' . $hrs . 'h)', 'badges' => [] ] : null;
		}
		return null;
	}

	private static function apply_badges( array $rates ): array {
		if ( count( $rates ) < 2 ) {
			return $rates;
		}
		$cheapest = null;
		$fastest  = null;
		foreach ( $rates as $r ) {
			if ( $cheapest === null || $r['cost'] < $cheapest['cost'] ) {
				$cheapest = $r;
			}
			$days = $r['estimated_days'] ?: 0;
			if ( $fastest === null || $days < ( $fastest['estimated_days'] ?: 0 ) ) {
				$fastest = $r;
			}
		}
		foreach ( $rates as &$rate ) {
			$rate['badges'] = [];
			if ( $cheapest && $rate['provider'] === $cheapest['provider'] ) {
				$rate['badges'][] = '💰 Mejor precio';
			}
			if ( $fastest && $rate['provider'] === $fastest['provider'] ) {
				$rate['badges'][] = '⚡ Más rápido';
			}
		}
		unset( $rate );
		return $rates;
	}

	private static function get_package_weight( array $package ): float {
		$default_w = (float) LTMS_Core_Config::get( 'ltms_default_product_weight_kg', 0.5 );
		$min_w     = (float) LTMS_Core_Config::get( 'ltms_min_shipping_weight_kg', 0.1 );
		$weight    = 0.0;
		foreach ( ( $package['contents'] ?? [] ) as $item ) {
			$product = $item['data'] ?? null;
			if ( $product instanceof WC_Product ) {
				$pw     = (float) $product->get_weight();
				$weight += ( $pw > 0 ? $pw : $default_w ) * (int) ( $item['quantity'] ?? 1 );
			}
		}
		return max( $min_w, $weight > 0 ? $weight : $default_w );
	}

	private static function get_cache_key( array $package ): string {
		$dest  = $package['destination'] ?? [];
		$items = [];
		foreach ( ( $package['contents'] ?? [] ) as $item ) {
			$items[] = ( (int) ( $item['product_id'] ?? 0 ) ) . ':' . ( (int) ( $item['quantity'] ?? 1 ) );
		}
		return hash( 'sha256', wp_json_encode( [ $dest, $items ] ) );
	}

	private static function get_cached( string $key ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT quote_data FROM `{$wpdb->prefix}lt_shipping_quotes_cache`
				 WHERE cache_key = %s AND provider = 'parallel' AND expires_at > %s LIMIT 1",
				$key,
				current_time( 'mysql', true )
			)
		);
		if ( $row ) {
			$data = json_decode( $row->quote_data, true );
			return is_array( $data ) ? $data : null;
		}
		return null;
	}

	private static function cache_result( string $key, array $rates ): void {
		global $wpdb;
		$ttl     = (int) LTMS_Core_Config::get( 'ltms_shipping_cache_ttl', 600 );
		$expires = gmdate( 'Y-m-d H:i:s', time() + $ttl );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->replace(
			$wpdb->prefix . 'lt_shipping_quotes_cache',
			[
				'cache_key'  => $key,
				'provider'   => 'parallel',
				'quote_data' => wp_json_encode( $rates ),
				'expires_at' => $expires,
				'created_at' => current_time( 'mysql', true ),
			],
			[ '%s', '%s', '%s', '%s', '%s' ]
		);
	}
}
