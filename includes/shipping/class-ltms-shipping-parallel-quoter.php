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

                $requests = self::build_requests( $weight, $dest, $country, $package );
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

        private static function build_requests( float $weight, array $dest, string $country, array $package = [] ): array {
                $requests = [];

                // Aveonline — Colombia only
                // API v2: autenticación por token JWT en el body (POST), no por query param.
                if ( $country === 'CO' ) {
                        $token      = get_transient( 'ltms_aveonline_jwt' );
                        $idempresa  = (int) LTMS_Core_Config::get( 'ltms_aveonline_idempresa', 0 );

                        // Resolver idagente por vendedor si el package lo proporciona
                        $vendor_id = class_exists( 'LTMS_Business_Aveonline_Agents' )
                                ? LTMS_Business_Aveonline_Agents::get_vendor_id_from_package( $package )
                                : null;
                        $idagente  = class_exists( 'LTMS_Business_Aveonline_Agents' )
                                ? LTMS_Business_Aveonline_Agents::get_vendor_idagente( $vendor_id )
                                : (string) LTMS_Core_Config::get( 'ltms_aveonline_idagente', '' );
                        $origin     = LTMS_Core_Config::get( 'ltms_store_city', 'Bogotá' );
                        // Normalizar ciudad destino al formato oficial Aveonline
                        $dest_city = $dest['city'] ?? '';
                        if ( $dest_city && class_exists( 'LTMS_Business_Aveonline_Cities' ) ) {
                                $dest_city = LTMS_Business_Aveonline_Cities::find_by_name( $dest_city );
                        }

                        if ( $token && $idempresa && ! empty( $dest_city ) ) {
                                $body = wp_json_encode( [
                                        'tipo'           => 'cotizar2',
                                        'token'          => $token,
                                        'idempresa'      => $idempresa,
                                        'origen'         => $origin,
                                        'destino'        => $dest_city,
                                        'valorrecaudo'   => 0,
                                        'unidades'       => 1,
                                        'productos'      => [[
                                                'alto'           => '15',
                                                'largo'          => '30',
                                                'ancho'          => '20',
                                                'peso'           => (string) $weight,
                                                'unidades'       => 1,
                                                'nombre'         => 'Producto',
                                                'valorDeclarado' => '10000',
                                        ]],
                                        'valorMinimo'    => 0,
                                        'idasumecosto'   => 0,
                                        'contraentrega'  => 0,
                                        'idtransportador'=> '',
                                        'plugin'         => 'apiave',
                                        'idagente'       => $idagente,
                                ] );
                                $requests['aveonline'] = self::build_curl_opts_post(
                                        'https://app.aveonline.co/api/nal/v1.0/generarGuiaTransporteNacional.php',
                                        [ 'Content-Type: application/json', 'Accept: application/json' ],
                                        $body
                                );
                        }
                }

                // Heka — PQ-BUG-3 fix: correct domain (.com, not .co), correct endpoint
                // (/v1/rates, not /v1/quotes), POST with JSON body instead of GET query string.
                // Heka API client uses X-API-Key header (confirmed in class-ltms-api-heka.php:53)
                // and the stored key is encrypted, so it must be decrypted before sending.
                $hk_encrypted = LTMS_Core_Config::get( 'ltms_heka_api_key' );
                if ( $hk_encrypted && ! empty( $dest['city'] ) && class_exists( 'LTMS_Core_Security' ) ) {
                        $hk = LTMS_Core_Security::decrypt( $hk_encrypted );
                        if ( $hk ) {
                                $heka_body = wp_json_encode( [
                                        'origin_city'      => LTMS_Core_Config::get( 'ltms_store_city', 'Bogotá' ),
                                        'destination_city' => $dest['city'],
                                        'weight_kg'        => $weight,
                                        'declared_value'   => (float) LTMS_Core_Config::get( 'ltms_default_declared_value', 0.0 ),
                                        'items_count'      => self::count_package_items( $package ),
                                        'account_id'       => LTMS_Core_Config::get( 'ltms_heka_account_id', '' ),
                                ] );
                                $requests['heka'] = self::build_curl_opts_post(
                                        'https://api.hekaentrega.com/v1/rates',
                                        [
                                                'Accept: application/json',
                                                'Content-Type: application/json',
                                                'X-API-Key: ' . $hk,
                                        ],
                                        $heka_body
                                );
                        }
                }

                // Uber Direct (token required) — PQ-BUG-2 fix: correct endpoint
                // (/v1/customers/{customer_id}/delivery_quotes, not /v1/eats/deliveries/quotes
                // which is Uber Eats food delivery), POST with pickup/dropoff/manifest body,
                // and Idempotency-Key header (matches API-BUG-9 pattern in class-ltms-api-uber.php).
                $uber_token = get_transient( 'ltms_uber_access_token' );
                if ( $uber_token && ! empty( $dest['postcode'] ) ) {
                        $uber_customer_id = LTMS_Core_Config::get( 'ltms_uber_direct_customer_id', '' );
                        if ( $uber_customer_id ) {
                                $pickup_addr = [
                                        'street_address' => [ (string) LTMS_Core_Config::get( 'ltms_store_address', '' ) ],
                                        'city'           => (string) LTMS_Core_Config::get( 'ltms_store_city', 'Bogotá' ),
                                        'state'          => (string) LTMS_Core_Config::get( 'ltms_store_state', '' ),
                                        'zip_code'       => (string) LTMS_Core_Config::get( 'ltms_store_postcode', '' ),
                                        'country'        => $country,
                                ];
                                $dropoff_addr = [
                                        'street_address' => [ (string) ( $dest['address_1'] ?? '' ), (string) ( $dest['address_2'] ?? '' ) ],
                                        'city'           => (string) ( $dest['city'] ?? '' ),
                                        'state'          => (string) ( $dest['state'] ?? '' ),
                                        'zip_code'       => (string) ( $dest['postcode'] ?? '' ),
                                        'country'        => (string) ( $dest['country'] ?? $country ),
                                ];

                                $default_w = (float) LTMS_Core_Config::get( 'ltms_default_product_weight_kg', 0.5 );
                                $manifest  = [];
                                foreach ( ( $package['contents'] ?? [] ) as $item ) {
                                        $product = $item['data'] ?? null;
                                        $item_w  = $default_w;
                                        if ( $product instanceof WC_Product ) {
                                                $pw = (float) $product->get_weight();
                                                if ( $pw > 0 ) {
                                                        $item_w = $pw;
                                                }
                                        }
                                        $size  = $item_w < 1.0 ? 'small' : ( $item_w < 5.0 ? 'medium' : ( $item_w < 15.0 ? 'large' : 'xlarge' ) );
                                        $name  = ( $product instanceof WC_Product && method_exists( $product, 'get_name' ) )
                                                ? $product->get_name()
                                                : 'Producto';
                                        $manifest[] = [
                                                'name'     => $name,
                                                'quantity' => (int) ( $item['quantity'] ?? 1 ),
                                                'size'     => $size,
                                        ];
                                }
                                if ( empty( $manifest ) ) {
                                        $manifest[] = [
                                                'name'     => 'Producto',
                                                'quantity' => 1,
                                                'size'     => 'small',
                                        ];
                                }

                                $uber_body = wp_json_encode( [
                                        'pickup_address'  => $pickup_addr,
                                        'dropoff_address' => $dropoff_addr,
                                        'manifest_items'  => $manifest,
                                ] );

                                // API-BUG-9 pattern: deterministic Idempotency-Key so retried quote requests
                                // return the same quote_id instead of creating duplicates.
                                $idem_key = 'ltms_quote_' . md5( $uber_body );

                                $requests['uber'] = self::build_curl_opts_post(
                                        'https://api.uber.com/v1/customers/' . rawurlencode( $uber_customer_id ) . '/delivery_quotes',
                                        [
                                                'Accept: application/json',
                                                'Content-Type: application/json',
                                                'Authorization: Bearer ' . $uber_token,
                                                'Idempotency-Key: ' . $idem_key,
                                        ],
                                        $uber_body
                                );
                        }
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


        private static function build_curl_opts_post( string $url, array $headers, string $body ): array {
                return [
                        CURLOPT_URL            => $url,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT        => 3,
                        CURLOPT_CONNECTTIMEOUT => 2,
                        CURLOPT_HTTPHEADER     => $headers,
                        CURLOPT_SSL_VERIFYPEER => true,
                        CURLOPT_POST           => true,
                        CURLOPT_POSTFIELDS     => $body,
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
                                // API v2 devuelve array 'cotizaciones'; elegimos la más barata sin error.
                                $cotizaciones = $data['cotizaciones'] ?? [];
                                $best_cost    = PHP_INT_MAX;
                                $best_days    = 5;
                                $best_name    = 'Aveonline';
                                foreach ( $cotizaciones as $c ) {
                                        if ( ( $c['numbererror'] ?? '-0-' ) !== '-0-' ) {
                                                continue; // tiene error de cobertura
                                        }
                                        $c_cost = (float) ( $c['total'] ?? $c['valorTotal'] ?? 0 );
                                        if ( $c_cost > 0 && $c_cost < $best_cost ) {
                                                $best_cost = $c_cost;
                                                $best_days = (int) ( $c['diasentrega'] ?? 5 );
                                                $best_name = $c['nombreTransportadora'] ?? 'Aveonline';
                                        }
                                }
                                if ( $best_cost === PHP_INT_MAX || $best_cost <= 0 ) {
                                        return null;
                                }
                                return [
                                        'provider'       => 'aveonline',
                                        'cost'           => $best_cost,
                                        'estimated_days' => $best_days,
                                        'label'          => sprintf( 'Aveonline / %s (%d días)', $best_name, $best_days ),
                                        'badges'         => [],
                                ];

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
                // PQ-BUG-1 (regression of Task 53-G): the cache key previously only used
                // product_id:quantity, which caused quote collisions for:
                //   - Variations of the same parent product (different weights/dimensions).
                //   - Same product with different weights (volumetric weight affects price).
                //   - Different origins (multi-vendor stores with different pickup cities).
                //   - Different sets of active carriers (disabled carrier would be served
                //     from a stale cached quote that included it).
                // The key now includes every dimension that affects the quote.
                $dest    = $package['destination'] ?? [];
                $country = LTMS_Core_Config::get_country();
                $origin  = (string) LTMS_Core_Config::get( 'ltms_store_city', 'Bogotá' );

                $default_w = (float) LTMS_Core_Config::get( 'ltms_default_product_weight_kg', 0.5 );
                $items     = [];
                foreach ( ( $package['contents'] ?? [] ) as $item ) {
                        $product_id   = (int) ( $item['product_id'] ?? 0 );
                        $variation_id = (int) ( $item['variation_id'] ?? 0 );
                        $quantity     = (int) ( $item['quantity'] ?? 1 );
                        $item_weight  = $default_w;
                        $product      = $item['data'] ?? null;
                        if ( $product instanceof WC_Product ) {
                                $pw = (float) $product->get_weight();
                                if ( $pw > 0 ) {
                                        $item_weight = $pw;
                                }
                        }
                        $items[] = $product_id . ':' . $variation_id . ':' . $quantity . ':' . $item_weight;
                }

                $total_weight = self::get_package_weight( $package );

                // Replicate the active-carrier conditions from build_requests() so the
                // cache key invalidates whenever a carrier is enabled or disabled between
                // runs (e.g., an Heka API key is added/removed, an Uber token expires).
                $carriers = [];
                if ( 'CO' === $country ) {
                        $ave_token    = get_transient( 'ltms_aveonline_jwt' );
                        $ave_idemp    = (int) LTMS_Core_Config::get( 'ltms_aveonline_idempresa', 0 );
                        $ave_destcity = $dest['city'] ?? '';
                        if ( $ave_destcity && class_exists( 'LTMS_Business_Aveonline_Cities' ) ) {
                                $ave_destcity = LTMS_Business_Aveonline_Cities::find_by_name( $ave_destcity );
                        }
                        if ( $ave_token && $ave_idemp && ! empty( $ave_destcity ) ) {
                                $carriers[] = 'aveonline';
                        }
                }
                $hk_encrypted = LTMS_Core_Config::get( 'ltms_heka_api_key' );
                if ( $hk_encrypted && ! empty( $dest['city'] ) ) {
                        $carriers[] = 'heka';
                }
                $uber_token = get_transient( 'ltms_uber_access_token' );
                if ( $uber_token && ! empty( $dest['postcode'] ) && LTMS_Core_Config::get( 'ltms_uber_direct_customer_id', '' ) ) {
                        $carriers[] = 'uber';
                }

                $cache_data = [
                        'origin'       => $origin,
                        'destination'  => $dest,
                        'items'        => $items,
                        'total_weight' => $total_weight,
                        'carriers'     => implode( ',', $carriers ),
                ];
                // Format kept as 64-char sha256 hex to preserve the contract verified by
                // ShippingParallelQuoterTest::test_get_cache_key_is_64_chars / _is_hex_string.
                return hash( 'sha256', wp_json_encode( $cache_data ) );
        }

        /**
         * Counts the total quantity of items in a WooCommerce shipping package.
         *
         * Used by the Heka rate request body (items_count field).
         *
         * @param array $package WooCommerce package.
         * @return int Total item quantity across all line items.
         */
        private static function count_package_items( array $package ): int {
                $total = 0;
                foreach ( ( $package['contents'] ?? [] ) as $item ) {
                        $total += (int) ( $item['quantity'] ?? 1 );
                }
                return max( 1, $total );
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
