<?php
/**
 * LTMS FX Rate Provider
 *
 * Fetches and caches foreign exchange rates from multiple providers:
 *   - ECB (European Central Bank) — free, no API key, daily rates
 *   - exchangerate.host — free, no API key
 *   - Frankfurter.app — free, no API key
 *   - Manual admin override (highest priority)
 *
 * Fallback chain:
 *   1. Manual override (admin-configured fixed rates) — highest priority
 *   2. Cached rates (WordPress transient, 6h TTL)
 *   3. Frankfurter API (primary live source)
 *   4. exchangerate.host (secondary live source)
 *   5. ECB XML feed (EUR base, then cross-convert)
 *   6. Reverse cached rate (1 / opposite direction)
 *
 * Depends on:
 *   - LTMS_Core_Config  (manual overrides, settings)
 *   - LTMS_Core_Logger  (forensic log of refresh events)
 *
 * @package    LTMS
 * @subpackage LTMS/includes/business
 * @version    1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_FX_Rate_Provider
 *
 * All methods are static — this is a stateless utility class.
 */
class LTMS_FX_Rate_Provider {

    /**
     * Cache key prefix for storing rates per base currency.
     */
    const CACHE_KEY = 'ltms_fx_rates';

    /**
     * Cache TTL: 6 hours. FX rates don't change intraday for our purposes.
     */
    const CACHE_TTL = 6 * HOUR_IN_SECONDS; // 6 hours

    /**
     * Provider endpoints.
     *
     * @var array<string, string>
     */
    const PROVIDERS = [
        'frankfurter'   => 'https://api.frankfurter.app/latest?from=%s',
        'exchangerate'  => 'https://api.exchangerate.host/latest?base=%s',
        'ecb'           => 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml',
    ];

    /**
     * Get the exchange rate from one currency to another.
     *
     * Resolution order:
     *   1. Same currency → 1.0
     *   2. Manual admin override
     *   3. Cached rates (transient)
     *   4. Fresh fetch from providers (Frankfurter → exchangerate → ECB)
     *   5. Reverse cached rate (1 / opposite direction)
     *
     * @param string $from ISO 4217 (COP, MXN, USD, EUR).
     * @param string $to   ISO 4217.
     * @return float|null Rate (1 $from = X $to), null if unavailable.
     */
    public static function get_rate( string $from, string $to ): ?float {
        $from = strtoupper( trim( $from ) );
        $to   = strtoupper( trim( $to ) );

        // Validate ISO 4217 (3-letter codes, non-empty).
        if ( strlen( $from ) !== 3 || strlen( $to ) !== 3 ) {
            return null;
        }
        if ( empty( $from ) || empty( $to ) ) {
            return null;
        }

        if ( $from === $to ) {
            return 1.0;
        }

        // 1. Check manual override first (admin can set fixed rates).
        $override = self::get_manual_override( $from, $to );
        if ( $override !== null ) {
            return $override;
        }

        // 2. Check cache (ignore zero/negative entries — poisoned cache defense).
        $rates = self::get_cached_rates( $from );
        if ( isset( $rates[ $to ] ) && $rates[ $to ] > 0 ) {
            return (float) $rates[ $to ];
        }

        // 3. Fetch fresh rates.
        $rates = self::fetch_rates( $from );
        if ( $rates !== null ) {
            self::cache_rates( $from, $rates );

            // FX-1: Validate rate is strictly positive before returning. The
            // `?? null` operator returns 0 (not null) when the value is 0, so
            // a corrupted API response with rate=0 would silently zero-out
            // every downstream conversion (vendor payouts, display prices).
            if ( isset( $rates[ $to ] ) && $rates[ $to ] > 0 ) {
                return (float) $rates[ $to ];
            }
        }

        // 4. Try reverse rate as last resort (from cache).
        $reverse = self::get_cached_rates( $to );
        if ( isset( $reverse[ $from ] ) && $reverse[ $from ] > 0 ) {
            return 1.0 / (float) $reverse[ $from ];
        }

        return null;
    }

    /**
     * Convert an amount from one currency to another.
     *
     * @param float  $amount             Amount in $from currency.
     * @param string $from               Source currency (ISO 4217).
     * @param string $to                 Target currency (ISO 4217).
     * @param float  $spread_percentage  Optional spread/margin (e.g. 2.0 = 2% markup).
     *                                   The spread is ADDED to the rate, so the
     *                                   customer pays more than mid-market and the
     *                                   platform keeps the difference. A value of 0
     *                                   means mid-market rate.
     * @return float|null Converted amount, null if rate unavailable.
     */
    public static function convert( float $amount, string $from, string $to, float $spread_percentage = 0.0 ): ?float {
        $rate = self::get_rate( $from, $to );
        if ( $rate === null ) {
            return null;
        }

        // Apply spread (markup for currency conversion service).
        // Spread is added so customers pay MORE than mid-market — the
        // platform keeps the difference as margin.
        if ( $spread_percentage > 0 ) {
            $rate = $rate * ( 1.0 + ( $spread_percentage / 100.0 ) );
        }

        // Determine decimal precision for the target currency. Fall back
        // to 2 if the Currency Manager class/method is unavailable.
        $decimals = 2;
        if ( class_exists( 'LTMS_Currency_Manager' ) && method_exists( 'LTMS_Currency_Manager', 'get_decimals' ) ) {
            $decimals = LTMS_Currency_Manager::get_decimals( $to );
        }

        return round( $amount * $rate, $decimals );
    }

    /**
     * Fetch fresh rates from providers (with fallback chain).
     *
     * @param string $base Base currency (ISO 4217).
     * @return array<string, float>|null Associative array [currency => rate], null if all providers fail.
     */
    private static function fetch_rates( string $base ): ?array {
        // Try Frankfurter first (most reliable, free, no key).
        $rates = self::fetch_from_frankfurter( $base );
        if ( $rates !== null ) {
            return $rates;
        }

        // Try exchangerate.host.
        $rates = self::fetch_from_exchangerate( $base );
        if ( $rates !== null ) {
            return $rates;
        }

        // Try ECB (only EUR base, then convert).
        if ( $base !== 'EUR' ) {
            $eur_rates = self::fetch_from_ecb();
            if ( $eur_rates !== null ) {
                // Convert: base → EUR → target.
                $base_to_eur = $eur_rates[ $base ] ?? null;
                if ( $base_to_eur && $base_to_eur > 0 ) {
                    $result = [ $base => 1.0 ];
                    foreach ( $eur_rates as $curr => $eur_rate ) {
                        if ( $curr === $base ) {
                            continue;
                        }
                        $result[ $curr ] = $eur_rate / $base_to_eur;
                    }
                    return $result;
                }
            }
        } else {
            // Base is EUR — ECB returns EUR rates directly.
            $eur_rates = self::fetch_from_ecb();
            if ( $eur_rates !== null ) {
                return $eur_rates;
            }
        }

        return null;
    }

    /**
     * Fetch from Frankfurter API.
     *
     * @param string $base Base currency.
     * @return array<string, float>|null
     */
    private static function fetch_from_frankfurter( string $base ): ?array {
        $url = sprintf( self::PROVIDERS['frankfurter'], $base );
        $response = wp_remote_get( $url, [
            'timeout'   => 10,
            'sslverify' => true,
        ] );

        if ( is_wp_error( $response ) ) {
            LTMS_Core_Logger::warning(
                'FX_FRANKFURTER_ERROR',
                                'Frankfurter API request failed',
                                [
                    'base' => $base,
                    'error' => $response->get_error_message(),
                ]
            );
            return null;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        if ( ! isset( $data['rates'] ) || ! is_array( $data['rates'] ) ) {
            return null;
        }

        // FX-2: Filter out non-positive rates BEFORE caching. A corrupted or
        // hostile API response (e.g. {"COP": 0}) would otherwise be cached
        // for 6h, and even though `get_rate` re-validates on read, the bad
        // entries would still occupy the cache and force a re-fetch on every
        // call until TTL expiry.
        $rates = [ $base => 1.0 ];
        foreach ( $data['rates'] as $curr => $rate ) {
            $rate = (float) $rate;
            if ( $rate > 0 ) {
                $rates[ $curr ] = $rate;
            }
        }
        return count( $rates ) > 1 ? $rates : null;
    }

    /**
     * Fetch from exchangerate.host.
     *
     * @param string $base Base currency.
     * @return array<string, float>|null
     */
    private static function fetch_from_exchangerate( string $base ): ?array {
        $url = sprintf( self::PROVIDERS['exchangerate'], $base );
        $response = wp_remote_get( $url, [
            'timeout'   => 10,
            'sslverify' => true,
        ] );

        if ( is_wp_error( $response ) ) {
            LTMS_Core_Logger::warning(
                'FX_EXCHANGERATE_ERROR',
                'exchangerate.host API request failed',
                [
                    'base'  => $base,
                    'error' => $response->get_error_message(),
                ]
            );
            return null;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        if ( ! isset( $data['rates'] ) || ! is_array( $data['rates'] ) ) {
            return null;
        }

        // FX-2: Same non-positive-rate filter as Frankfurter (see above).
        $rates = [ $base => 1.0 ];
        foreach ( $data['rates'] as $curr => $rate ) {
            $rate = (float) $rate;
            if ( $rate > 0 ) {
                $rates[ $curr ] = $rate;
            }
        }
        return count( $rates ) > 1 ? $rates : null;
    }

    /**
     * Fetch from ECB (European Central Bank) — EUR base only.
     *
     * The ECB publishes a daily XML feed with EUR as the base currency.
     *
     * @return array<string, float>|null
     */
    private static function fetch_from_ecb(): ?array {
        $response = wp_remote_get( self::PROVIDERS['ecb'], [
            'timeout'   => 10,
            'sslverify' => true,
        ] );

        if ( is_wp_error( $response ) ) {
            LTMS_Core_Logger::warning(
                'FX_ECB_ERROR',
                'ECB XML feed request failed',
                [ 'error' => $response->get_error_message() ]
            );
            return null;
        }

        $body = wp_remote_retrieve_body( $response );

        // Parse XML. LIBXML_NONET disables network access during parsing
        // (defense in depth against XXE / external entity attacks).
        $xml = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NONET );
        if ( ! $xml ) {
            return null;
        }

        $rates = [ 'EUR' => 1.0 ];
        foreach ( $xml->Cube->Cube->Cube as $cube ) {
            $currency       = (string) $cube['currency'];
            $rate_val       = isset( $cube['rate'] ) ? (string) $cube['rate'] : '';
            // Validate rate is numeric AND strictly positive — ECB sometimes
            // publishes '.' for missing rates, which (float) silently coerces
            // to 0.0 and would poison the cache. is_numeric alone does not
            // reject negative values (FX-3).
            if ( ! is_numeric( $rate_val ) ) {
                continue;
            }
            $rate_float = (float) $rate_val;
            if ( $rate_float <= 0 ) {
                continue;
            }
            $rates[ $currency ] = $rate_float;
        }
        return count( $rates ) > 1 ? $rates : null;
    }

    /**
     * Get cached rates for a base currency.
     *
     * @param string $base Base currency.
     * @return array<string, float>
     */
    private static function get_cached_rates( string $base ): array {
        $cache = get_transient( self::CACHE_KEY . '_' . $base );
        return is_array( $cache ) ? $cache : [];
    }

    /**
     * Cache rates for a base currency.
     *
     * @param string                $base  Base currency.
     * @param array<string, float>  $rates Rates to cache.
     * @return void
     */
    private static function cache_rates( string $base, array $rates ): void {
        set_transient( self::CACHE_KEY . '_' . $base, $rates, self::CACHE_TTL );
    }

    /**
     * Get manual override rate (admin-configured fixed rates).
     *
     * Admins can define fixed exchange rates via the option key
     * 'ltms_fx_manual_overrides' (stored as an associative array
     * keyed by "{$from}_{$to}", e.g. "USD_COP" => 4100.50).
     *
     * @param string $from Source currency.
     * @param string $to   Target currency.
     * @return float|null
     */
    private static function get_manual_override( string $from, string $to ): ?float {
        $overrides = LTMS_Core_Config::get( 'ltms_fx_manual_overrides', [] );

        // The admin UI stores overrides as a textarea STRING ( sanitized with
        // sanitize_textarea_field ) in the format "USD_COP=3800\nUSD_MXN=17.5".
        // Parse it into an associative array if a string was returned.
        if ( is_string( $overrides ) ) {
            $lines  = explode( "\n", $overrides );
            $parsed = [];
            foreach ( $lines as $line ) {
                $line = trim( $line );
                if ( empty( $line ) || strpos( $line, '=' ) === false ) {
                    continue;
                }
                list( $pair, $rate ) = explode( '=', $line, 2 );
                $pair = trim( $pair );
                $rate = (float) trim( $rate );
                if ( $rate > 0 ) {
                    $parsed[ $pair ] = $rate;
                }
            }
            $overrides = $parsed;
        }

        if ( ! is_array( $overrides ) ) {
            return null;
        }
        $key = $from . '_' . $to;
        if ( isset( $overrides[ $key ] ) && $overrides[ $key ] > 0 ) {
            return (float) $overrides[ $key ];
        }
        return null;
    }

    /**
     * Get all supported currencies.
     *
     * Returns a map of ISO 4217 code → currency metadata.
     * `decimals` is the standard number of decimal places used
     * for that currency's display (0 for COP/CLP, 2 for most others).
     *
     * @return array<string, array{name: string, symbol: string, decimals: int, country: string}>
     */
    public static function get_supported_currencies(): array {
        return [
            'COP' => [ 'name' => 'Colombian Peso',    'symbol' => '$',  'decimals' => 0, 'country' => 'CO' ],
            'MXN' => [ 'name' => 'Mexican Peso',      'symbol' => '$',  'decimals' => 2, 'country' => 'MX' ],
            'USD' => [ 'name' => 'US Dollar',         'symbol' => '$',  'decimals' => 2, 'country' => 'US' ],
            'EUR' => [ 'name' => 'Euro',              'symbol' => '€',  'decimals' => 2, 'country' => 'EU' ],
            'BRL' => [ 'name' => 'Brazilian Real',    'symbol' => 'R$', 'decimals' => 2, 'country' => 'BR' ],
            'ARS' => [ 'name' => 'Argentine Peso',    'symbol' => '$',  'decimals' => 2, 'country' => 'AR' ],
            'CLP' => [ 'name' => 'Chilean Peso',      'symbol' => '$',  'decimals' => 0, 'country' => 'CL' ],
            'PEN' => [ 'name' => 'Peruvian Sol',      'symbol' => 'S/', 'decimals' => 2, 'country' => 'PE' ],
            'GBP' => [ 'name' => 'British Pound',     'symbol' => '£',  'decimals' => 2, 'country' => 'GB' ],
            'CAD' => [ 'name' => 'Canadian Dollar',   'symbol' => 'C$', 'decimals' => 2, 'country' => 'CA' ],
        ];
    }

    /**
     * Force refresh rates (admin action).
     *
     * Clears all cached FX rate transients so the next request
     * triggers a fresh fetch from the live providers.
     *
     * @return void
     */
    public static function refresh_rates(): void {
        global $wpdb;
        // Clear all cached rates (both transient and timeout entries).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ltms_fx_rates_%' OR option_name LIKE '_transient_timeout_ltms_fx_rates_%'"
        );
        LTMS_Core_Logger::info(
            'FX_RATES_REFRESHED',
            'FX rates cache cleared, will fetch fresh on next request'
        );
    }
}
