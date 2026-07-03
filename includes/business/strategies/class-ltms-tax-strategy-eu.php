<?php
/**
 * LTMS Tax Strategy EU
 *
 * Implements EU VAT calculation for cross-border B2C and B2B transactions.
 *
 * - VAT 19%–25% depending on member state (MS).
 * - OSS (One Stop Shop) for B2C cross-border inside EU: VAT of the MS of
 *   consumption is charged and declared via a single VAT return.
 * - Reverse charge for B2B intra-EU (buyer self-accounts VAT; 0% charged).
 * - VAT MOSS / OSS for digital services (B2C): destination-country rate.
 * - IOSS (Import One Stop Shop) for imports < €150: VAT collected at
 *   checkout, declared via IOSS return; no customs VAT.
 * - De minimis for imports: €150 (handled by Customs Calculator, IOSS
 *   applies below this threshold).
 *
 * Strategy interface return shape keeps the CO/MX fields for compatibility,
 * mapping EU concepts as follows:
 *   - iva              → VAT charged to buyer
 *   - reteiva          → reverse-charge flag value (0 — buyer self-accounts)
 *   - retefuente/isr/ieps/reteica → 0 (not applicable in EU)
 *
 * Legal basis: Council Directive 2006/112/EC (VAT Directive), Council
 * Implementing Regulation (EU) 282/2011, Regulation (EU) 2017/2455 (OSS/IOSS),
 * Council Directive (EU) 2017/2455.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/business/strategies
 * @version    1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Tax_Strategy_EU
 */
final class LTMS_Tax_Strategy_EU implements LTMS_Tax_Strategy_Interface {

    /**
     * Default VAT rates per EU member state (standard rate, percentage).
     *
     * Configurable via `ltms_eu_vat_rates` ([ '{MS}' => rate ]).
     * Source: European Commission TAXUD — VAT rates applied in the MS.
     *
     * @var array<string,float>
     */
    private const DEFAULT_VAT_RATES = [
        'AT' => 20.0, // Austria.
        'BE' => 21.0, // Belgium.
        'BG' => 20.0, // Bulgaria.
        'HR' => 25.0, // Croatia.
        'CY' => 19.0, // Cyprus.
        'CZ' => 21.0, // Czech Republic.
        'DK' => 25.0, // Denmark.
        'EE' => 22.0, // Estonia.
        'FI' => 25.5, // Finland (raised to 25.5% as of 2025).
        'FR' => 20.0, // France.
        'DE' => 19.0, // Germany.
        'GR' => 24.0, // Greece.
        'HU' => 27.0, // Hungary.
        'IE' => 23.0, // Ireland.
        'IT' => 22.0, // Italy.
        'LV' => 21.0, // Latvia.
        'LT' => 21.0, // Lithuania.
        'LU' => 17.0, // Luxembourg.
        'MT' => 18.0, // Malta.
        'NL' => 21.0, // Netherlands.
        'PL' => 23.0, // Poland.
        'PT' => 23.0, // Portugal.
        'RO' => 19.0, // Romania.
        'SK' => 23.0, // Slovakia.
        'SI' => 22.0, // Slovenia.
        'ES' => 21.0, // Spain.
        'SE' => 25.0, // Sweden.
        'XI' => 20.0, // Northern Ireland (UK VAT rate post-Brexit for goods — EU-BUG-1).
    ];

    /**
     * Goods / services eligible for reduced VAT rates per Directive 2006/112/EC.
     * Categories listed here use the reduced rate from `ltms_eu_reduced_rate`
     * (default 5% — varies per MS; admin must override for accuracy).
     */
    private const REDUCED_PRODUCT_TYPES = [
        'basic_food',
        'medicine',
        'books',
        'ebooks',
        'newspapers',
        'children_clothing',
        'public_transport',
        'agricultural_supplies',
    ];

    /**
     * Get the standard VAT rate for an EU member state.
     *
     * @param string $ms 2-letter ISO country code (e.g. 'DE').
     * @return float Rate as percentage (0-100).
     */
    private function get_vat_rate( string $ms ): float {
        $ms = strtoupper( $ms );

        $configured = LTMS_Core_Config::get( 'ltms_eu_vat_rates', [] );
        if ( is_array( $configured ) && isset( $configured[ $ms ] ) ) {
            return (float) $configured[ $ms ];
        }

        return (float) ( self::DEFAULT_VAT_RATES[ $ms ] ?? 0.0 );
    }

    /**
     * Get the reduced VAT rate for an EU member state.
     *
     * Defaults to 5%; admins must override per MS for accurate figures.
     *
     * @param string $ms 2-letter ISO country code.
     * @return float Rate as percentage (0-100).
     */
    private function get_reduced_vat_rate( string $ms ): float {
        $ms = strtoupper( $ms );
        $configured = LTMS_Core_Config::get( 'ltms_eu_reduced_rates', [] );
        if ( is_array( $configured ) && isset( $configured[ $ms ] ) ) {
            return (float) $configured[ $ms ];
        }
        return (float) LTMS_Core_Config::get( 'ltms_eu_reduced_rate_default', 5.0 );
    }

    /**
     * Validate a VAT identification number (VIES-style format check).
     *
     * NOTE: This is a format-level heuristic only. For legal certainty a
     * real VIES SOAP API call must be performed at checkout; the result is
     * cacheable for 24h per MS+VATID. Override via filter `ltms_eu_vat_valid`.
     *
     * @param string $vat_id Full VAT ID including country prefix (e.g. 'DE123456789').
     * @return bool
     */
    private function is_valid_vat_id_format( string $vat_id ): bool {
        $vat_id = trim( $vat_id );
        if ( strlen( $vat_id ) < 4 ) {
            return false;
        }
        $ms = substr( $vat_id, 0, 2 );
        $num = substr( $vat_id, 2 );
        if ( ! isset( self::DEFAULT_VAT_RATES[ strtoupper( $ms ) ] ) ) {
            return false;
        }
        if ( ! preg_match( '/^[A-Z0-9+\.\-]{2,}$/', $num ) ) {
            return false;
        }
        return (bool) apply_filters( 'ltms_eu_vat_valid', true, $vat_id, $ms );
    }

    /**
     * Calculate EU VAT for a transaction.
     *
     * @param float $gross_amount Pre-tax amount.
     * @param array $order_data {
     *     @type string $product_type     Product category key.
     *     @type string $destination_ms   2-letter ISO code of MS of consumption.
     *     @type string $origin_ms        2-letter ISO code of MS of origin (vendor).
     *     @type bool   $is_b2b           True if buyer is a business with valid VAT ID.
     *     @type string $buyer_vat_id     Buyer's VAT ID (B2B reverse charge check).
     *     @type bool   $is_digital       True if TBE (telecom/broadcasting/electronic) service.
     *     @type bool   $ioss_registered  True if platform uses IOSS for imports < €150.
     *     @type float  $item_value       Fallback for customs/IOSS threshold checks.
     * }
     * @param array $vendor_data {
     *     @type string $vendor_ms        2-letter ISO code of vendor's MS.
     *     @type bool   $oss_registered   Vendor/platform registered under OSS.
     *     @type string $vendor_vat_id    Vendor's VAT ID.
     *     @type string $tax_regime       Unused (kept for interface parity).
     * }
     * @return array Tax breakdown compatible with LTMS_Tax_Strategy_Interface.
         * @phpstan-return array{
     *     iva: float, iva_rate: float, retefuente: float, retefuente_rate: float,
     *     reteiva: float, reteiva_rate: float, ica: float, ica_rate: float,
     *     isr: float, isr_rate: float, ieps: float, ieps_rate: float,
     *     total_taxes: float, total_withholding: float, net_to_vendor: float,
     *     strategy: string
     * }
     */
    public function calculate( float $gross_amount, array $order_data, array $vendor_data ): array {
        $product_type      = (string) ( $order_data['product_type']     ?? 'physical' );
        $destination_ms    = strtoupper( (string) ( $order_data['destination_ms'] ?? '' ) );
        $origin_ms         = strtoupper( (string) ( $order_data['origin_ms']      ?? ( $vendor_data['vendor_ms'] ?? '' ) ) );
        $is_b2b            = (bool) ( $order_data['is_b2b']            ?? false );
        $buyer_vat_id      = (string) ( $order_data['buyer_vat_id']    ?? '' );
        $is_digital        = (bool) ( $order_data['is_digital']        ?? false );
        $ioss_registered   = (bool) ( $order_data['ioss_registered']   ?? false );
        $oss_registered    = (bool) ( $vendor_data['oss_registered']   ?? false );

        // DEFAULT_VAT_RATES is used as a VAT lookup table; some non-EU codes
        // (XI = Northern Ireland post-Brexit, GB) are present for VAT purposes
        // but are NOT EU member states for intra-EU rules (OSS, reverse charge).
        // Exclude them from the intra_eu determination to avoid regression
        // introduced by EU-BUG-1 (XI added to DEFAULT_VAT_RATES).
        $non_eu_with_vat = [ 'XI', 'GB' ];

        $intra_eu = ( $origin_ms !== '' && $destination_ms !== '' && $origin_ms !== $destination_ms
                      && isset( self::DEFAULT_VAT_RATES[ $origin_ms ] )
                      && isset( self::DEFAULT_VAT_RATES[ $destination_ms ] )
                      && ! in_array( $origin_ms, $non_eu_with_vat, true )
                      && ! in_array( $destination_ms, $non_eu_with_vat, true ) );

        $is_valid_buyer_vat = $buyer_vat_id !== '' && $this->is_valid_vat_id_format( $buyer_vat_id );

        // 1. Reverse charge for B2B intra-EU with valid VAT ID — buyer self-accounts VAT.
        $reverse_charge = $is_b2b && $intra_eu && $is_valid_buyer_vat;
        if ( $reverse_charge ) {
            return $this->build_result(
                $gross_amount,
                0.0,
                0.0,
                0.0,
                0.0,
                [
                    'mechanism'        => 'reverse_charge',
                    'destination_ms'   => $destination_ms,
                    'origin_ms'        => $origin_ms,
                    'buyer_vat_id'     => $buyer_vat_id,
                    'vat_due_by_buyer' => round( $gross_amount * ( $this->get_vat_rate( $destination_ms ) / 100 ), 2 ),
                ]
            );
        }

        // 2. IOSS for imports < €150 — VAT charged at destination rate, no customs VAT.
        $ioss_threshold = (float) LTMS_Core_Config::get( 'ltms_eu_ioss_threshold', 150.0 );
        $item_value     = (float) ( $order_data['item_value'] ?? $gross_amount );
        // EU-BUG-4: IOSS threshold uses strict < (items AT €150 are NOT IOSS-eligible;
        // previously used <= which incorrectly applied IOSS at exactly the threshold).
        $is_ioss_import = $ioss_registered && $item_value > 0 && $item_value < $ioss_threshold;

        // 3. Determine applicable rate.
        $rate = in_array( $product_type, self::REDUCED_PRODUCT_TYPES, true )
            ? $this->get_reduced_vat_rate( $destination_ms )
            : $this->get_vat_rate( $destination_ms );

        // 4. Determine which MS rate applies:
        //    - Digital B2C (TBE) → destination MS rate (OSS mandatory).
        //    - Physical B2C intra-EU → destination MS rate if OSS registered; else origin MS rate.
        //    - Domestic (origin == destination) → origin MS rate.
        //    - IOSS import → destination MS rate.
        if ( $is_digital && $intra_eu && $oss_registered ) {
            $applicable_ms = $destination_ms;
            $mechanism = 'oss_digital';
        } elseif ( $intra_eu && $oss_registered ) {
            $applicable_ms = $destination_ms;
            $mechanism = 'oss_goods';
        } elseif ( $intra_eu && ! $oss_registered ) {
            // Distance sale under €10k threshold: origin MS rate (simplified).
            // Above threshold without OSS: technically must register in each MS.
            $applicable_ms = $origin_ms;
            $mechanism = 'distance_sale_origin';
        } elseif ( $is_ioss_import ) {
            $applicable_ms = $destination_ms;
            $mechanism = 'ioss_import';
        } else {
            $applicable_ms = $destination_ms ?: $origin_ms;
            $mechanism = 'domestic';
        }

        $rate = in_array( $product_type, self::REDUCED_PRODUCT_TYPES, true )
            ? $this->get_reduced_vat_rate( $applicable_ms )
            : $this->get_vat_rate( $applicable_ms );

        $vat_amount = round( $gross_amount * ( $rate / 100 ), 2 );

        return $this->build_result(
            $gross_amount,
            $rate,
            $vat_amount,
            0.0,
            0.0,
            [
                'mechanism'      => $mechanism,
                'destination_ms' => $destination_ms,
                'origin_ms'      => $origin_ms,
                'applicable_ms'  => $applicable_ms,
                'oss_registered' => $oss_registered,
                'ioss_import'    => $is_ioss_import,
                'is_b2b'         => $is_b2b,
                'is_digital'     => $is_digital,
                'reverse_charge' => false,
            ]
        );
    }

    /**
     * Build a strategy-compatible result array.
     *
     * @param float  $gross_amount    Gross amount.
     * @param float  $vat_rate        VAT rate as percentage (0-100).
     * @param float  $vat_amount      VAT amount charged.
     * @param float  $withholding     Withholding amount (always 0 for EU).
     * @param float  $excise          Excise amount (always 0 for EU here).
     * @param array  $extra           Extra metadata to merge into result.
     * @return array
     */
    private function build_result( float $gross_amount, float $vat_rate, float $vat_amount, float $withholding, float $excise, array $extra = [] ): array {
        return (array) apply_filters(
            'ltms_eu_tax_calculate',
            array_merge(
                [
                    'gross'             => $gross_amount,
                    'iva'               => $vat_amount,
                    'iva_rate'          => $vat_rate / 100, // Interface expects decimal.
                    'retefuente'        => 0.0,
                    'retefuente_rate'   => 0.0,
                    'reteiva'           => 0.0,
                    'reteiva_rate'      => 0.0,
                    'ica'               => 0.0,
                    'ica_rate'          => 0.0,
                    'isr'               => 0.0,
                    'isr_rate'          => 0.0,
                    'ieps'              => $excise,
                    'ieps_rate'         => 0.0,
                    'impoconsumo'       => 0.0,
                    'impoconsumo_rate'  => 0.0,
                    'total_taxes'       => round( $vat_amount + $excise, 2 ),
                    'total_withholding' => $withholding,
                    'net_to_vendor'     => round( $gross_amount - $withholding, 2 ),
                    'platform_fee'      => 0.0,
                    'strategy'          => self::class,
                    'country'           => 'EU',
                    'currency'          => 'EUR',
                ],
                $extra
            ),
            $gross_amount
        );
    }

    /**
     * Whether to apply withholding.
     *
     * EU has no VAT-withholding mechanism. Reverse charge (B2B intra-EU)
     * is conceptually different — the buyer self-accounts, but it is not
     * a "withholding" on the vendor payout.
         *
         * @param array $vendor_data Vendor data (unused for EU).
     * @return bool
     */
    public function should_apply_withholding( array $vendor_data ): bool {
        return false;
    }

    /**
     * Country code.
     *
     * The EU strategy is multi-country; we expose 'EU' as the canonical
     * identifier. Concrete member state codes are passed via order_data.
     *
     * @return string
     */
    public function get_country_code(): string {
        return 'EU';
    }
}
