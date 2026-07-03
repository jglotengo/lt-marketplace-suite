<?php
/**
 * LTMS Currency Manager
 *
 * Manages multi-currency support for cross-border operations:
 *   - Display currency (what customer sees)
 *   - Settlement currency (what vendor receives)
 *   - Base currency (platform accounting currency)
 *   - Conversion with configurable spread
 *   - Rounding rules per currency
 *   - Currency display formatting
 *
 * Three-currency model:
 *   1. BASE currency     — Platform accounting currency (default USD).
 *                          All internal ledger entries are stored in BASE.
 *   2. DISPLAY currency  — What the customer sees in the storefront.
 *                          Detected via: session selection → geo-IP → base.
 *   3. SETTLEMENT currency — What each vendor receives at payout.
 *                            Configured per-vendor via user meta
 *                            'ltms_payout_currency', fallback to vendor's
 *                            country currency, fallback to base currency.
 *
 * Depends on:
 *   - LTMS_FX_Rate_Provider (live FX rates + cache)
 *   - LTMS_Core_Config      (settings: base currency, spread, enabled list)
 *   - WooCommerce           (WC()->session, WC_Geolocation)
 *
 * @package    LTMS
 * @subpackage LTMS/includes/business
 * @version    1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Currency_Manager
 *
 * Stateless utility class — all methods are static.
 */
class LTMS_Currency_Manager {

    /**
     * Get the platform's base currency (for accounting).
     *
     * The base currency is the one in which all internal ledger entries
     * are stored. Defaults to USD.
     *
     * @return string ISO 4217 (default 'USD').
     */
    public static function get_base_currency(): string {
        $currency = LTMS_Core_Config::get( 'ltms_base_currency', 'USD' );
        return $currency ? strtoupper( (string) $currency ) : 'USD';
    }

    /**
     * Get the customer's display currency (from session, geo, or config).
     *
     * Resolution order:
     *   1. WooCommerce session (customer selected currency via switcher)
     *   2. Geo-IP detection (WooCommerce geolocation by country)
     *   3. Base currency (fallback)
     *
     * @return string ISO 4217.
     */
    public static function get_display_currency(): string {
        // 1. Check session (customer selected currency).
        if ( function_exists( 'WC' ) && WC()->session ) {
            $selected = WC()->session->get( 'ltms_display_currency' );
            if ( $selected ) {
                return strtoupper( (string) $selected );
            }
        }

        // 2. Check geo-IP.
        $geo_country = self::get_geo_country();
        if ( $geo_country ) {
            $currency = self::get_currency_for_country( $geo_country );
            if ( $currency ) {
                return $currency;
            }
        }

        // 3. Default to base currency.
        return self::get_base_currency();
    }

    /**
     * Set the customer's display currency (session).
     *
     * Used by the currency switcher widget on the storefront.
     *
     * @param string $currency ISO 4217 code (case-insensitive).
     * @return void
     */
    public static function set_display_currency( string $currency ): void {
        $currency = strtoupper( trim( $currency ) );
        if ( strlen( $currency ) !== 3 ) {
            return; // Invalid format, ignore.
        }

        // Validate against the enabled list — prevents session poisoning
        // with arbitrary codes (e.g. 'XYZ' or lowercase 'usd').
        $enabled = self::get_enabled_currencies();
        if ( ! isset( $enabled[ $currency ] ) ) {
            return; // Invalid currency, ignore.
        }

        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->set( 'ltms_display_currency', $currency );
        }
    }

    /**
     * Get the vendor's settlement currency.
     *
     * Vendors receive payouts in their configured currency.
     * Resolution order:
     *   1. Vendor's explicit payout currency (user meta 'ltms_payout_currency')
     *   2. Vendor's country currency (user meta 'ltms_vendor_country')
     *   3. Base currency (fallback)
     *
     * @param int $vendor_id WordPress user ID of the vendor.
     * @return string ISO 4217.
     */
    public static function get_vendor_currency( int $vendor_id ): string {
        $currency = get_user_meta( $vendor_id, 'ltms_payout_currency', true );
        if ( $currency ) {
            $currency = strtoupper( trim( (string) $currency ) );
            // CM-2: Validate the configured payout currency is a well-formed
            // ISO 4217 code (3 uppercase ASCII letters). Without this check,
            // a malformed meta value (whitespace, '0', numeric string, or a
            // 2-letter country code like 'CO' mistakenly stored instead of
            // 'COP') would silently propagate to LTMS_FX_Rate_Provider,
            // fail every conversion, and force the vendor to be paid in the
            // base currency — not the currency their bank account uses.
            if ( strlen( $currency ) === 3 && ctype_alpha( $currency ) ) {
                return $currency;
            }
            LTMS_Core_Logger::warning(
                'VENDOR_INVALID_PAYOUT_CURRENCY',
                                sprintf( 'Vendor %d has malformed payout_currency "%s"; falling back to country/base.', $vendor_id, $currency )
            );
        }

        // Default to vendor's country currency.
        $vendor_country = get_user_meta( $vendor_id, 'ltms_vendor_country', true );
        if ( $vendor_country ) {
            $currency = self::get_currency_for_country( (string) $vendor_country );
            if ( $currency ) {
                return $currency;
            }
        }

        return self::get_base_currency();
    }

    /**
     * Get the currency for a country code.
     *
     * Maps ISO 3166-1 alpha-2 country codes to ISO 4217 currency codes.
     * Returns null for unmapped countries.
     *
     * @param string $country ISO 3166-1 alpha-2 country code (case-insensitive).
     * @return string|null ISO 4217 currency code, or null if unmapped.
     */
    public static function get_currency_for_country( string $country ): ?string {
        $map = [
            'CO' => 'COP',
            'MX' => 'MXN',
            'US' => 'USD',
            'BR' => 'BRL',
            'AR' => 'ARS',
            'CL' => 'CLP',
            'PE' => 'PEN',
            'GB' => 'GBP',
            'CA' => 'CAD',
            // EU member states (all 27 — EUR zone).
            'AT' => 'EUR', 'BE' => 'EUR', 'BG' => 'EUR', 'CY' => 'EUR',
            'CZ' => 'EUR', 'DE' => 'EUR', 'DK' => 'EUR', 'EE' => 'EUR',
            'ES' => 'EUR', 'FI' => 'EUR', 'FR' => 'EUR', 'GR' => 'EUR',
            'HR' => 'EUR', 'HU' => 'EUR', 'IE' => 'EUR', 'IT' => 'EUR',
            'LT' => 'EUR', 'LU' => 'EUR', 'LV' => 'EUR', 'MT' => 'EUR',
            'NL' => 'EUR', 'PL' => 'EUR', 'PT' => 'EUR', 'RO' => 'EUR',
            'SE' => 'EUR', 'SI' => 'EUR', 'SK' => 'EUR',
        ];
        return $map[ strtoupper( $country ) ] ?? null;
    }

    /**
     * Convert and format an amount for display.
     *
     * Takes an amount expressed in the BASE currency and converts it
     * to the DISPLAY currency, applying the configured FX spread.
     *
     * @param float       $amount           Amount in base currency.
     * @param string|null $display_currency Target currency (null = auto-detect).
     * @return array {
     *     @type float  $amount        Converted amount in display currency.
     *     @type string $currency      Display currency code.
     *     @type string $formatted     Pre-formatted string (symbol + amount).
     *     @type float  $rate          Effective rate used (1 base = X display).
     *     @type float  $base_amount   Original amount in base currency.
     *     @type string $base_currency Base currency code.
     * }
     */
    public static function format_for_display( float $amount, ?string $display_currency = null ): array {
        $base    = self::get_base_currency();
        $display = $display_currency ?? self::get_display_currency();

        if ( $base === $display ) {
            $converted = $amount;
            $rate      = 1.0;
        } else {
            $spread    = (float) LTMS_Core_Config::get( 'ltms_fx_spread_percentage', 0.0 );
            $converted = LTMS_FX_Rate_Provider::convert( $amount, $base, $display, $spread );
            $rate      = LTMS_FX_Rate_Provider::get_rate( $base, $display );
            if ( $converted === null ) {
                // Fallback: show in base currency.
                $converted = $amount;
                $display   = $base;
                $rate      = 1.0;
            }
        }

        return [
            'amount'        => round( $converted, self::get_decimals( $display ) ),
            'currency'      => $display,
            'formatted'     => self::format_amount( $converted, $display ),
            'rate'          => $rate,
            'base_amount'   => $amount,
            'base_currency' => $base,
        ];
    }

    /**
     * Format an amount with the currency symbol and decimals.
     *
     * Uses `number_format` with thousands separators. Symbol placement:
     *   - EUR: amount + space + symbol (e.g. "10.00 €")
     *   - All others: symbol + amount (e.g. "$10.00", "R$10.00")
     *
     * @param float  $amount   Numeric amount.
     * @param string $currency ISO 4217 currency code.
     * @return string Formatted amount with symbol.
     */
    public static function format_amount( float $amount, string $currency ): string {
        $currencies = LTMS_FX_Rate_Provider::get_supported_currencies();
        $info       = $currencies[ $currency ] ?? [ 'symbol' => '$', 'decimals' => 2 ];
        $decimals   = $info['decimals'];
        $symbol     = $info['symbol'];

        // Handle negative amounts cleanly so refunds render as "-$100.00"
        // rather than "$-100.00".
        $negative  = $amount < 0;
        $formatted = number_format( abs( $amount ), $decimals, '.', ',' );

        // Symbol placement: EUR = after, all others = before.
        $symbol_after = in_array( $currency, [ 'EUR' ], true );
        $result = $symbol_after ? $formatted . ' ' . $symbol : $symbol . $formatted;
        return $negative ? '-' . $result : $result;
    }

    /**
     * Get the number of decimals for a currency.
     *
     * COP and CLP use 0 decimals (no centavos/centavos de peso issued).
     * Most other currencies use 2 decimals.
     *
     * @param string $currency ISO 4217 currency code.
     * @return int Number of decimal places (default 2).
     */
    public static function get_decimals( string $currency ): int {
        $currencies = LTMS_FX_Rate_Provider::get_supported_currencies();
        return $currencies[ $currency ]['decimals'] ?? 2;
    }

    /**
     * Detect customer country via geo-IP.
     *
     * Uses WooCommerce's built-in geolocation (which respects the
     * configured geolocation service — MaxMind, ipapi, etc.).
     *
     * @return string|null ISO 3166-1 alpha-2 country code, or null if unavailable.
     */
    public static function get_geo_country(): ?string {
        if ( class_exists( 'WC_Geolocation' ) ) {
            $geo = WC_Geolocation::geolocate_ip();
            return $geo['country'] ?? null;
        }
        return null;
    }

    /**
     * Get all enabled currencies for the currency switcher.
     *
     * Returns the subset of supported currencies that the admin has
     * enabled via the 'ltms_enabled_currencies' setting. Defaults to
     * ['COP', 'MXN', 'USD'] if not configured.
     *
     * @return array<string, array{name: string, symbol: string, decimals: int, country: string}>
     */
    public static function get_enabled_currencies(): array {
        $enabled = LTMS_Core_Config::get( 'ltms_enabled_currencies', [ 'COP', 'MXN', 'USD' ] );
        if ( ! is_array( $enabled ) ) {
            $enabled = [ 'COP', 'MXN', 'USD' ];
        }

        $all    = LTMS_FX_Rate_Provider::get_supported_currencies();
        $result = [];
        foreach ( $enabled as $code ) {
            $code = strtoupper( (string) $code );
            if ( isset( $all[ $code ] ) ) {
                $result[ $code ] = $all[ $code ];
            }
        }
        return $result;
    }

    /**
     * Settlement: convert from display currency to vendor's settlement currency.
     *
     * Used at order completion / payout time to determine how much to
     * credit the vendor's wallet, in the vendor's preferred currency.
     * No spread is applied at settlement (spread is already taken at
     * the display-conversion stage).
     *
     * CM-1: Callers SHOULD pass a `$rate` retrieved from order meta when
     * settling past orders, so the vendor is paid at the FX rate that was
     * in effect when the order was placed — NOT the current live rate.
     * Using the live rate for past orders creates an unbounded FX exposure
     * for the platform (rates can move >10% intraday in volatile markets
     * such as ARS/TRY) and silently re-prices historical vendor payouts.
     * If `$rate` is null (default), the live rate is fetched (backward
     * compatible with callers that have not yet been migrated).
     *
     * @param float       $amount           Amount in display currency.
     * @param string      $display_currency Display currency (ISO 4217).
     * @param int         $vendor_id        Vendor WordPress user ID.
     * @param float|null  $rate             Historical rate to use (1 display = X settlement).
     *                                      Null = fetch live rate (legacy behavior).
     * @return array {
     *     @type float  $amount   Settlement amount in settlement currency.
     *     @type string $currency Settlement currency code.
     *     @type float  $rate     Rate used (1 display = X settlement).
     * }
     */
    public static function convert_to_settlement( float $amount, string $display_currency, int $vendor_id, ?float $rate = null ): array {
        $settlement_currency = self::get_vendor_currency( $vendor_id );

        if ( $display_currency === $settlement_currency ) {
            return [
                'amount'   => round( $amount, self::get_decimals( $settlement_currency ) ),
                'currency' => $settlement_currency,
                'rate'     => 1.0,
            ];
        }

        // CM-1: If a historical rate is supplied, use it directly. This skips
        // the live FX fetch (and its fallback chain) so past orders settle at
        // the rate recorded at order-placement time.
        if ( $rate !== null && $rate > 0 ) {
            $converted = round( $amount * $rate, self::get_decimals( $settlement_currency ) );
            return [
                'amount'   => $converted,
                'currency' => $settlement_currency,
                'rate'     => $rate,
            ];
        }

        $converted = LTMS_FX_Rate_Provider::convert( $amount, $display_currency, $settlement_currency );
        if ( $converted === null ) {
            // FX failure fallback: prefer BASE currency (which the platform
            // can always settle) over the display currency, because the
            // vendor's bank account is in their settlement currency, not
            // the customer's display currency.
            $base = self::get_base_currency();
            if ( $display_currency !== $base ) {
                $converted = LTMS_FX_Rate_Provider::convert( $amount, $display_currency, $base );
                if ( $converted !== null ) {
                    return [
                        'amount'   => round( $converted, self::get_decimals( $base ) ),
                        'currency' => $base,
                        'rate'     => LTMS_FX_Rate_Provider::get_rate( $display_currency, $base ),
                    ];
                }
            }
            // Last resort: display currency (original amount unchanged).
            return [
                'amount'   => round( $amount, self::get_decimals( $display_currency ) ),
                'currency' => $display_currency,
                'rate'     => 1.0,
            ];
        }

        return [
            'amount'   => round( $converted, self::get_decimals( $settlement_currency ) ),
            'currency' => $settlement_currency,
            'rate'     => LTMS_FX_Rate_Provider::get_rate( $display_currency, $settlement_currency ),
        ];
    }
}
