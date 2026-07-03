<?php
/**
 * LTMS Customs Calculator
 *
 * Calculates import duties, customs fees, and taxes for cross-border shipments.
 * Supports DDP (Delivered Duty Paid) and DDU (Delivered Duty Unpaid / DAP).
 *
 * INCOTERMS supported: DDP, DDU (DAP)
 *
 * Duty rates are configurable per country + HS code (Harmonized System).
 * Falls back to a default duty rate if HS code is not found.
 *
 * Configurable options (LTMS_Core_Config):
 *   - ltms_customs_duty_rates      : array  Override map [ '{COUNTRY}_{HS}' => rate, '{COUNTRY}_default' => rate ]
 *   - ltms_customs_fees            : array  Per-country fee config [ '{COUNTRY}' => [ 'flat' => x, 'percentage' => y ] ]
 *   - ltms_customs_vat_rates       : array  Per-country VAT/GST rate overrides
 *   - ltms_customs_de_minimis      : array  Per-country de minimis thresholds (in base currency)
 *   - ltms_customs_default_duty    : float  Default duty rate when country has no entry (default 5.0)
 *   - ltms_customs_excise_rates    : array  Excise tax map [ '{COUNTRY}_{HS_PREFIX}' => rate ]
 *
 * @package    LTMS
 * @subpackage LTMS/includes/business
 * @version    1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Customs_Calculator
 *
 * Pure customs/duty engine. No WordPress hooks, fully static and unit-testable.
 * Delegates destination VAT to country tax strategies when available, but keeps
 * a self-contained VAT table for cross-border fallbacks.
 */
final class LTMS_Customs_Calculator {

    const INCOTERM_DDP = 'DDP'; // Seller pays duties.
    const INCOTERM_DDU = 'DDU'; // Buyer pays duties (also called DAP).

    /**
     * Calculate customs duties and taxes for a cross-border shipment.
     *
     * @param array $args {
     *     @type float  $item_value          Value of goods in base currency.
     *     @type string $origin_country      ISO 2-letter (e.g. 'CO').
     *     @type string $destination_country ISO 2-letter (e.g. 'US').
     *     @type string $hs_code             Harmonized System code (optional).
     *     @type float  $shipping_cost       Shipping cost (for CIF calculation).
     *     @type float  $insurance_cost      Insurance cost (for CIF calculation).
     *     @type string $incoterm            DDP or DDU (default: DDU).
     *     @type string $currency            Currency of item_value.
     * }
     * @return array {
     *     @type float  $cif_value          CIF value (Cost + Insurance + Freight).
     *     @type float  $duty_rate          Duty rate (%).
     *     @type float  $duty_amount        Duty amount.
     *     @type float  $vat_rate           VAT/GST rate (%).
     *     @type float  $vat_amount         VAT amount.
     *     @type float  $other_taxes        Other taxes (excise, etc.).
     *     @type float  $customs_fee        Customs processing fee.
     *     @type float  $total_duties       Total duties + taxes.
     *     @type string $incoterm           DDP or DDU.
     *     @type string $paid_by            'seller', 'buyer' or 'n/a'.
     *     @type bool   $below_de_minimis   True if shipment is below threshold (no duties).
     *     @type array  $breakdown          Detailed breakdown.
     * }
     */
    public static function calculate( array $args ): array {
        // RB-5 FIX (v2.9.19): Disparar ltms_customs_calc_args ANTES de procesar
        // para que los listeners (PP-8 enhance FTA, CB-2 extend incoterms 2020)
        // puedan enriquecer los argumentos (origin_country, preferential_tariff,
        // incoterm extendido, etc.). Antes de este fix, esos listeners estaban
        // registrados pero NUNCA se disparaban → silent dead code desde v2.9.15.
        $args = apply_filters( 'ltms_customs_calc_args', $args, [
            'origin_country'      => strtoupper( (string) ( $args['origin_country'] ?? '' ) ),
            'destination_country' => strtoupper( (string) ( $args['destination_country'] ?? '' ) ),
        ] );

        // Clamp negative inputs to 0 (CC-BUG-5).
        $item_value     = max( 0.0, (float) ( $args['item_value']     ?? 0 ) );
        $shipping_cost  = max( 0.0, (float) ( $args['shipping_cost']  ?? 0 ) );
        $insurance_cost = max( 0.0, (float) ( $args['insurance_cost'] ?? 0 ) );
        $origin         = strtoupper( (string) ( $args['origin_country'] ?? '' ) );
        $destination    = strtoupper( (string) ( $args['destination_country'] ?? '' ) );
        // Sanitize HS code to digits only (CC-BUG-8).
        $hs_code        = preg_replace( '/[^0-9]/', '', (string) ( $args['hs_code'] ?? '' ) );
        $incoterm       = strtoupper( (string) ( $args['incoterm'] ?? self::INCOTERM_DDU ) );

        // RB-5 cont.: tras aplicar el filter, validar incoterm contra los 11 de ICC 2020
        // (extendidos por CB-2). Si es DDP o DDU legacy → mantener; si es otro válido
        // de ICC 2020 → permitir; si no → default DDU.
        $valid_incoterms_2020 = [ 'EXW','FCA','FAS','FOB','CFR','CIF','CPT','CIP','DAP','DPU','DDP','DDU' ];
        if ( ! in_array( $incoterm, $valid_incoterms_2020, true ) ) {
            $incoterm = self::INCOTERM_DDU;
        }

        // Domestic shipment — no customs.
        if ( $origin === $destination ) {
            return self::zero_result( $incoterm );
        }

        // CIF value (Cost + Insurance + Freight) — used by most countries.
        $cif_value = $item_value + $shipping_cost + $insurance_cost;

        // De Minimis check — threshold applies to CIF value (CC-BUG-1).
        if ( self::is_below_de_minimis( $item_value, $destination, $shipping_cost, $insurance_cost ) ) {
            return self::zero_result( $incoterm, true );
        }

        // Get duty rate.
        $duty_rate = self::get_duty_rate( $destination, $hs_code );

        // Duty base: US CBP uses FOB (item_value, excluding freight/insurance);
        // most other jurisdictions use CIF (CC-BUG-3).
        $duty_base = $cif_value;
        if ( 'US' === $destination ) {
            $duty_base = $item_value;
        }

        // Calculate duty.
        $duty_amount = round( $duty_base * ( $duty_rate / 100 ), 2 );

        // Get VAT/GST rate.
        $vat_rate = self::get_vat_rate( $destination );

        // VAT is calculated on (CIF + duty) in most countries.
        $vat_base   = $cif_value + $duty_amount;
        $vat_amount = round( $vat_base * ( $vat_rate / 100 ), 2 );

        // Customs processing fee (flat or percentage).
        // CC-3: Use $duty_base (FOB for US, CIF for others) as the ad-valorem
        // base — US CBP computes the MPF on the entered value (FOB), not CIF.
        // Using CIF for US over-collects MPF on shipments with freight/insurance.
        $customs_fee = self::get_customs_fee( $destination, $duty_base );

        // Other taxes (excise, anti-dumping, etc.).
        $other_taxes = self::calculate_other_taxes( $destination, $cif_value, $hs_code );

        $total = $duty_amount + $vat_amount + $other_taxes + $customs_fee;

        /**
         * Filters the customs calculation result.
         *
         * @param array  $result      Final calculation result.
         * @param array  $args        Original input args.
         * @param string $destination Destination country code.
         */
        return (array) apply_filters(
            'ltms_customs_calculator_result',
            [
                'cif_value'          => round( $cif_value, 2 ),
                'duty_rate'          => $duty_rate,
                'duty_amount'        => $duty_amount,
                'vat_rate'           => $vat_rate,
                'vat_amount'         => $vat_amount,
                'other_taxes'        => $other_taxes,
                'customs_fee'        => $customs_fee,
                'total_duties'       => round( $total, 2 ),
                'incoterm'           => $incoterm,
                'paid_by'            => $incoterm === self::INCOTERM_DDP ? 'seller' : 'buyer',
                'below_de_minimis'   => false,
                'breakdown'          => [
                    'item_value'     => $item_value,
                    'shipping_cost'  => $shipping_cost,
                    'insurance_cost' => $insurance_cost,
                    'cif_value'      => round( $cif_value, 2 ),
                    'duty'           => $duty_amount,
                    'vat'            => $vat_amount,
                    'customs_fee'    => $customs_fee,
                    'other'          => $other_taxes,
                ],
            ],
            $args,
            $destination
        );
    }

    /**
     * Get duty rate for destination country + HS code.
     *
     * Resolution order:
     *   1. Configured override key "{COUNTRY}_{HS}".
     *   2. Configured country default "{COUNTRY}_default".
     *   3. Built-in defaults (self::get_default_duty_rates()).
     *   4. Global fallback (ltms_customs_default_duty, default 5.0%).
     *
     * @param string $destination ISO 2-letter country code.
     * @param string $hs_code     Harmonized System code (optional).
     * @return float Duty rate as percentage (0-100).
     */
    private static function get_duty_rate( string $destination, string $hs_code ): float {
        $rates = LTMS_Core_Config::get( 'ltms_customs_duty_rates', [] );
        // Parse textarea string format: "US=3.4\nUS_6101=15.0\nBR=11.0" (CC-BUG-7).
        if ( is_string( $rates ) ) {
            $lines  = explode( "\n", $rates );
            $parsed = [];
            foreach ( $lines as $line ) {
                $line = trim( $line );
                if ( '' === $line || false === strpos( $line, '=' ) ) {
                    continue;
                }
                list( $key, $rate ) = explode( '=', $line, 2 );
                $parsed[ trim( $key ) ] = (float) trim( $rate );
            }
            $rates = $parsed;
        }

        $rate = null;
        if ( is_array( $rates ) ) {
            $key = $destination . '_' . $hs_code;
            if ( isset( $rates[ $key ] ) ) {
                $rate = (float) $rates[ $key ];
            } else {
                // Country default rate.
                $country_default = $destination . '_default';
                if ( isset( $rates[ $country_default ] ) ) {
                    $rate = (float) $rates[ $country_default ];
                }
            }
        }

        // Fallback: built-in defaults.
        if ( $rate === null ) {
            $defaults = self::get_default_duty_rates();
            if ( isset( $defaults[ $destination ] ) ) {
                $rate = (float) $defaults[ $destination ];
            } else {
                $rate = (float) LTMS_Core_Config::get( 'ltms_customs_default_duty', 5.0 );
            }
        }

        // CC-1: Clamp duty rate to [0, 100]. A negative rate would produce a
        // negative duty (a subsidy paid to importers — revenue leak), and a
        // rate >100% almost always indicates a config typo (decimal point
        // error like "1150" meaning 11.50%). Legitimate >100% levies should
        // be modeled via the excise/anti-dumping mechanism, not the duty rate.
        return max( 0.0, min( 100.0, $rate ) );
    }

    /**
     * Get VAT/GST rate for destination country.
     *
     * US has no federal VAT (state sales tax is handled separately by the
     * US tax strategy). Values are percentages.
     *
     * @param string $destination ISO 2-letter country code.
     * @return float VAT/GST rate as percentage (0-100).
     */
    private static function get_vat_rate( string $destination ): float {
        $overrides = LTMS_Core_Config::get( 'ltms_customs_vat_rates', [] );
        if ( is_array( $overrides ) && isset( $overrides[ $destination ] ) ) {
            // CC-2: Clamp admin-overridden VAT rate to [0, 100]. A negative
            // VAT would credit importers; >100% is a config typo. The
            // built-in table below is pre-validated, so only overrides need
            // clamping here.
            return max( 0.0, min( 100.0, (float) $overrides[ $destination ] ) );
        }

        $vat_rates = [
            'US' => 0.0,    // US has sales tax, not VAT (handled by US tax strategy).
            'CA' => 5.0,    // GST.
            'GB' => 20.0,   // UK VAT.
            'DE' => 19.0,   // Germany VAT.
            'FR' => 20.0,   // France VAT.
            'ES' => 21.0,   // Spain VAT.
            'IT' => 22.0,   // Italy VAT.
            'NL' => 21.0,   // Netherlands VAT.
            'PT' => 23.0,   // Portugal VAT.
            'IE' => 23.0,   // Ireland VAT.
            'BR' => 17.0,   // Brazil ICMS (cross-border simplified).
            'AR' => 21.0,   // Argentina IVA.
            'CL' => 19.0,   // Chile IVA.
            'PE' => 18.0,   // Peru IGV.
            'CO' => 19.0,   // Colombia IVA.
            'MX' => 16.0,   // Mexico IVA.
            'AU' => 10.0,   // Australia GST.
            'JP' => 10.0,   // Japan consumption tax.
        ];

        if ( ! isset( $vat_rates[ $destination ] ) ) {
            LTMS_Core_Logger::warning(
                'CUSTOMS_UNKNOWN_COUNTRY',
                sprintf( 'No VAT rate for %s, using 0', $destination )
            );
            return 0.0;
        }

        return (float) $vat_rates[ $destination ];
    }

    /**
     * Get customs processing fee.
     *
     * Returns a flat fee plus an optional percentage applied on the supplied
     * base value. Callers should pass the jurisdiction-appropriate base:
     * FOB (item value) for US, CIF for most other jurisdictions.
     *
     * @param string $destination ISO 2-letter country code.
     * @param float  $base_value  Ad-valorem base (FOB for US, CIF for others).
     * @return float Fee amount.
     */
    private static function get_customs_fee( string $destination, float $base_value ): float {
        $fees = LTMS_Core_Config::get( 'ltms_customs_fees', [] );
        if ( is_array( $fees ) && isset( $fees[ $destination ] ) ) {
            $fee_config = $fees[ $destination ];
            if ( is_array( $fee_config ) && isset( $fee_config['flat'] ) ) {
                $fee = (float) $fee_config['flat'];
                if ( isset( $fee_config['percentage'] ) && $fee_config['percentage'] > 0 ) {
                    $fee += round( $base_value * ( (float) $fee_config['percentage'] / 100 ), 2 );
                }
                return self::maybe_cap_mpf( $destination, $fee );
            }
        }

        // Defaults: US = flat $6.50 + 0.346% (Merchandise Processing Fee).
        $defaults = [
            'US' => [ 'flat' => 6.50, 'percentage' => 0.346 ],
            'CA' => [ 'flat' => 9.95, 'percentage' => 0.0 ],
            'GB' => [ 'flat' => 15.00, 'percentage' => 0.0 ],
            'BR' => [ 'flat' => 0.0, 'percentage' => 0.0 ], // No separate fee.
            'AU' => [ 'flat' => 50.00, 'percentage' => 0.0 ], // AUD threshold fee.
        ];
        $config = $defaults[ $destination ] ?? [ 'flat' => 10.00, 'percentage' => 0.0 ];

        $fee = (float) $config['flat'];
        if ( $config['percentage'] > 0 ) {
            $fee += round( $base_value * ( $config['percentage'] / 100 ), 2 );
        }
        return self::maybe_cap_mpf( $destination, $fee );
    }

    /**
     * Apply US MPF (Merchandise Processing Fee) min/max cap.
     *
     * CBP imposes a minimum ($25) and maximum ($614.25, 2024 figure) on the
     * ad-valorem MPF. Without this cap, high-value shipments over-collect
     * (CC-BUG-4).
     *
     * @param string $destination ISO 2-letter country code.
     * @param float  $fee         Computed customs fee.
     * @return float Fee after cap (cap only applied for US).
     */
    private static function maybe_cap_mpf( string $destination, float $fee ): float {
        if ( 'US' === $destination ) {
            return max( 25.00, min( 614.25, $fee ) );
        }
        return $fee;
    }

    /**
     * Calculate other taxes (excise, anti-dumping, etc.).
     *
     * Currently implements basic excise lookup by HS code prefix.
     *
     * @param string $destination ISO 2-letter country code.
     * @param float  $cif_value   CIF value used as tax base.
     * @param string $hs_code     Harmonized System code.
     * @return float Tax amount.
     */
    private static function calculate_other_taxes( string $destination, float $cif_value, string $hs_code ): float {
        $excise_rates = LTMS_Core_Config::get( 'ltms_customs_excise_rates', [] );
        if ( ! is_array( $excise_rates ) ) {
            $excise_rates = [];
        }

        // Try exact match first, then prefix (4-digit, 2-digit).
        $candidates = [
            $destination . '_' . $hs_code,
            $destination . '_' . substr( $hs_code, 0, 4 ),
            $destination . '_' . substr( $hs_code, 0, 2 ),
        ];

        foreach ( $candidates as $key ) {
            if ( isset( $excise_rates[ $key ] ) ) {
                // CC-4: Clamp excise rate to [0, 1000]. Excise/sin taxes can
                // legitimately exceed 100% (e.g. tobacco, alcohol), so the
                // upper bound is generous — but a negative rate would produce
                // a subsidy and values >1000% are almost certainly typos.
                $rate = max( 0.0, min( 1000.0, (float) $excise_rates[ $key ] ) );
                return round( $cif_value * ( $rate / 100 ), 2 );
            }
        }

        return 0.0;
    }

    /**
     * Default duty rates by country (percentages).
     *
     * These are simplified average rates. Real-world tariff schedules vary
     * by HS code and trade agreements; admins should override via config.
     *
     * @return array<string,float>
     */
    private static function get_default_duty_rates(): array {
        return [
            'US' => 3.4,    // US average duty rate.
            'CA' => 4.0,
            'GB' => 3.5,
            'DE' => 4.2,    // EU average.
            'FR' => 4.2,
            'ES' => 4.2,
            'IT' => 4.2,
            'NL' => 4.2,
            'PT' => 4.2,
            'IE' => 4.2,
            'BR' => 11.0,   // Brazil has high duties.
            'AR' => 14.0,
            'CL' => 6.0,
            'PE' => 5.0,
            'CO' => 10.0,
            'MX' => 5.0,
            'AU' => 5.0,
            'JP' => 4.0,
        ];
    }

    /**
     * Get De Minimis threshold (below which no duties apply).
     *
     * Thresholds are expressed in the destination country's currency for
     * the well-known cases (US USD, EU EUR, UK GBP, etc.). When the base
     * currency differs from the threshold currency, callers must convert
     * before comparison or override via `ltms_customs_de_minimis` config
     * keyed by country code.
     *
     * @param string $destination ISO 2-letter country code.
     * @return float Threshold in base currency (or 0.0 if unknown — treated as "no threshold").
     */
    public static function get_de_minimis( string $destination ): float {
        $thresholds = LTMS_Core_Config::get( 'ltms_customs_de_minimis', [] );
        if ( is_array( $thresholds ) && isset( $thresholds[ $destination ] ) ) {
            return (float) $thresholds[ $destination ];
        }

        $defaults = [
            'US' => 800.00,     // US: $800 (Section 321).
            'CA' => 20.00,      // Canada: CAD $20.
            'GB' => 135.00,     // UK: £135 (post-Brexit).
            'DE' => 150.00,     // EU: €150 (IOSS threshold).
            'FR' => 150.00,
            'ES' => 150.00,
            'IT' => 150.00,
            'NL' => 150.00,
            'PT' => 150.00,
            'IE' => 150.00,
            'BR' => 50.00,      // Brazil: USD $50 (Remessa Conforme).
            'AR' => 50.00,
            'CL' => 30.00,
            'PE' => 200.00,
            'CO' => 200.00,     // Colombia: USD $200.
            'MX' => 50.00,      // Mexico: USD $50.
            'AU' => 1000.00,    // Australia: AUD $1000.
            'JP' => 10000.00,   // Japan: ¥10,000.
        ];

        $threshold = (float) ( $defaults[ $destination ] ?? 0.0 );

        // RB-6 FIX (v2.9.19): Disparar filter ltms_customs_de_minimis para que
        // el listener CB-9 (convert_de_minimis_currency) pueda convertir el
        // threshold a la moneda base del marketplace antes de la comparación.
        // Antes de este fix, ltms_customs_de_minimis era una CONFIG OPTION
        // (LTMS_Core_Config::get) leída al inicio de la función — el filter
        // NUNCA se disparaba → CB-9 era silent dead code desde v2.9.18.
        // Pasamos 3 args: threshold, destination, base_currency.
        $base_currency = class_exists( 'LTMS_Core_Config' ) ? LTMS_Core_Config::get_currency() : 'COP';
        $threshold = (float) apply_filters( 'ltms_customs_de_minimis', $threshold, $destination, $base_currency );

        return $threshold;
    }

    /**
     * Check if shipment is below De Minimis (no duties apply).
     *
     * Threshold applies to CIF value (Cost + Insurance + Freight), not FOB
     * (CC-BUG-1). Comparison is strictly below threshold (CC-BUG-2).
     *
     * @param float  $item_value      Value of the goods (FOB).
     * @param string $destination     ISO 2-letter country code.
     * @param float  $shipping_cost   Shipping cost (freight).
     * @param float  $insurance_cost  Insurance cost.
     * @return bool
     */
    public static function is_below_de_minimis( float $item_value, string $destination, float $shipping_cost = 0, float $insurance_cost = 0 ): bool {
        $threshold = self::get_de_minimis( $destination );

        // No threshold known → never skip duties.
        if ( $threshold <= 0.0 ) {
            return false;
        }

        $cif = $item_value + $shipping_cost + $insurance_cost;
        return $cif < $threshold;
    }

    /**
     * Build a zero result for domestic / below-de-minimis shipments.
     *
     * @param string $incoterm          DDP or DDU.
     * @param bool   $below_de_minimis  Whether the result is due to de minimis.
     * @return array
     */
    private static function zero_result( string $incoterm, bool $below_de_minimis = false ): array {
        return [
            'cif_value'          => 0.0,
            'duty_rate'          => 0.0,
            'duty_amount'        => 0.0,
            'vat_rate'           => 0.0,
            'vat_amount'         => 0.0,
            'other_taxes'        => 0.0,
            'customs_fee'        => 0.0,
            'total_duties'       => 0.0,
            'incoterm'           => $incoterm,
            'paid_by'            => 'n/a',
            'below_de_minimis'   => $below_de_minimis,
            'breakdown'          => [],
        ];
    }
}
