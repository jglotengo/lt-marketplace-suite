<?php
/**
 * LTMS Tax Strategy US
 *
 * Implements US sales tax calculation (state-level, no federal VAT).
 *
 * - No federal VAT; state + local sales tax (0%–~10%).
 * - Marketplace facilitator laws: marketplace collects/remits in 40+ states.
 * - Economic nexus: $100k sales OR 200 transactions per state (Wayfair, 2018).
 * - Use TaxJar / Taxify / Avalara for accurate rates (configurable via filter).
 * - No withholding tax (unlike CO/MX).
 * - De minimis: Section 321 ($800 import threshold handled by Customs Calculator).
 *
 * Strategy interface return shape keeps the CO/MX fields for compatibility,
 * mapping US concepts onto them as follows:
 *   - iva              → state sales tax collected from buyer
 *   - retefuente       → 0 (no withholding in US)
 *   - reteiva          → 0
 *   - reteica          → 0
 *   - isr              → 0 (US income tax handled separately on vendor filings)
 *   - ieps             → excise taxes (alcohol, tobacco, fuel)
 *
 * Legal basis: South Dakota v. Wayfair (2018), state marketplace facilitator
 * statutes (FL HB 159, CA AB 147, TX SB 826, NY S.6658-A, etc.).
 *
 * @package    LTMS
 * @subpackage LTMS/includes/business/strategies
 * @version    1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Tax_Strategy_US
 */
final class LTMS_Tax_Strategy_US implements LTMS_Tax_Strategy_Interface {

    /**
     * Economic nexus thresholds (Wayfair). Configurable per state via
     * `ltms_us_nexus_thresholds` ([ '{STATE}' => [ 'sales' => x, 'transactions' => y ] ]).
     */
    private const DEFAULT_NEXUS_SALES        = 100000.0; // USD.
    private const DEFAULT_NEXUS_TRANSACTIONS = 200;

    /**
     * Sales tax rate for a given state.
     *
     * Looks up `ltms_us_state_rates` config first; otherwise uses the
     * built-in fallback table of combined state + average local rates.
     *
     * @param string $state 2-letter US state code (e.g. 'CA').
     * @return float Combined rate as decimal (0.0725 = 7.25%).
     */
    private function get_state_rate( string $state ): float {
        $state = strtoupper( $state );

        $configured = LTMS_Core_Config::get( 'ltms_us_state_rates', [] );
        if ( is_array( $configured ) && isset( $configured[ $state ] ) ) {
            return (float) $configured[ $state ];
        }

        $defaults = [
            'AL' => 0.0903, 'AK' => 0.0000, 'AZ' => 0.0840, 'AR' => 0.0947,
            'CA' => 0.0882, 'CO' => 0.0772, 'CT' => 0.0635, 'DE' => 0.0000,
            'FL' => 0.0750, 'GA' => 0.0735, 'HI' => 0.0444, 'ID' => 0.0626,
            'IL' => 0.0964, 'IN' => 0.0700, 'IA' => 0.0694, 'KS' => 0.0867,
            'KY' => 0.0600, 'LA' => 0.0952, 'ME' => 0.0550, 'MD' => 0.0600,
            'MA' => 0.0625, 'MI' => 0.0600, 'MN' => 0.0746, 'MS' => 0.0707,
            'MO' => 0.0829, 'MT' => 0.0000, 'NE' => 0.0694, 'NV' => 0.0823,
            'NH' => 0.0000, 'NJ' => 0.0663, 'NM' => 0.0784, 'NY' => 0.0852,
            'NC' => 0.0698, 'ND' => 0.0696, 'OH' => 0.0717, 'OK' => 0.0895,
            'OR' => 0.0000, 'PA' => 0.0634, 'RI' => 0.0700, 'SC' => 0.0743,
            'SD' => 0.0640, 'TN' => 0.0955, 'TX' => 0.0820, 'UT' => 0.0719,
            'VT' => 0.0666, 'VA' => 0.0575, 'WA' => 0.0929, 'WV' => 0.0652,
            'WI' => 0.0543, 'WY' => 0.0533, 'DC' => 0.0600,
        ];

        return (float) ( $defaults[ $state ] ?? 0.0 );
    }

    /**
     * Get excise (IEPS-equivalent) per-unit rate for a product category.
     *
     * US federal excise taxes apply to alcohol, tobacco, fuel, certain
     * environmental taxes. Unlike Colombia/Mexico IEPS (which is ad-valorem),
     * US federal excise is per-unit (per pack, per gallon, per proof gallon).
     * State excise is layered on top; for the marketplace use case we model
     * only the federal portion here.
     *
     * Configurable via `ltms_us_excise_rates` ( [ '{product_type}' => per_unit_rate ] ).
     *
     * @param string $product_type Product category key.
     * @return float Per-unit rate in USD (0.0 = none). Units depend on category:
     *               tobacco = pack, fuel = gallon, alcohol = proof gallon.
     */
    private function get_excise_per_unit_rate( string $product_type ): float {
        $configured = LTMS_Core_Config::get( 'ltms_us_excise_rates', [] );
        if ( is_array( $configured ) && isset( $configured[ $product_type ] ) ) {
            return (float) $configured[ $product_type ];
        }

        // US federal excise per-unit rates (US-BUG-1: was previously a
        // percentage, producing nonsensical values like $1.0066 × $50 gross
        // = $50.33 for a single pack of tobacco).
        $defaults = [
            'tobacco'         => 1.0066,  // $1.0066 per pack (TTB).
            'cigarettes'      => 1.0066,  // Same as tobacco.
            'alcohol_spirits' => 13.50,   // $13.50 per proof gallon (TTB).
            'beer'            => 0.58,    // ~$0.58 per gallon (TTB, 2024).
            'wine'            => 1.07,    // ~$1.07 per gallon (TTB, table wine).
            'fuel_gasoline'   => 0.184,   // $0.184 per gallon (federal).
            'fuel_diesel'     => 0.244,   // $0.244 per gallon (federal).
            'firearms'        => 0.0,     // AOM 10% is ad-valorem; out of scope for per-unit table.
        ];

        return (float) ( $defaults[ $product_type ] ?? 0.0 );
    }

    /**
     * Determines whether marketplace facilitator rules apply for the given state.
     *
     * Most states now require the marketplace (not the seller) to collect and
     * remit sales tax when the marketplace facilitates the sale. Override via
     * `ltms_us_marketplace_facilitator_states` config (array of state codes).
     *
     * @param string $state 2-letter state code.
     * @return bool
     */
    private function is_marketplace_facilitator_state( string $state ): bool {
        $state = strtoupper( $state );

        $configured = LTMS_Core_Config::get( 'ltms_us_marketplace_facilitator_states', [] );
        if ( is_array( $configured ) ) {
            return in_array( $state, $configured, true );
        }

        // As of 2025, all states with sales tax except Kansas (which still
        // places primary liability on the seller for remote sales without
        // economic nexus safe harbor) enforce marketplace facilitator rules.
        // We treat any state with a positive rate as facilitator state by default.
        return $this->get_state_rate( $state ) > 0.0;
    }

    /**
     * Determine whether economic nexus has been established for a state.
     *
     * Triggers when prior-year (or current-year) sales into the state exceed
     * $100,000 OR 200 transactions. Default threshold from Wayfair; some
     * states (CA, TX, NY, etc.) vary — override via config.
     *
     * @param array $vendor_data {
     *     @type array $state_nexus_state   Map [ '{STATE}' => [ 'sales' => float, 'transactions' => int ] ].
     * }
     * @param string $state 2-letter state code.
     * @return bool
     */
    private function has_economic_nexus( array $vendor_data, string $state ): bool {
        $state = strtoupper( $state );

        $nexus_data = $vendor_data['state_nexus_state'][ $state ] ?? null;
        if ( ! is_array( $nexus_data ) ) {
            return false;
        }

        $thresholds = LTMS_Core_Config::get( 'ltms_us_nexus_thresholds', [] );
        if ( ! is_array( $thresholds ) || ! isset( $thresholds[ $state ] ) ) {
            $thresholds = [
                $state => [
                    'sales'        => self::DEFAULT_NEXUS_SALES,
                    'transactions' => self::DEFAULT_NEXUS_TRANSACTIONS,
                ],
            ];
        }

        $sales_thresh = (float) ( $thresholds[ $state ]['sales']        ?? self::DEFAULT_NEXUS_SALES );
        $tx_thresh    = (int)   ( $thresholds[ $state ]['transactions'] ?? self::DEFAULT_NEXUS_TRANSACTIONS );

        $sales = (float) ( $nexus_data['sales']        ?? 0 );
        $txs   = (int)   ( $nexus_data['transactions'] ?? 0 );

        return $sales >= $sales_thresh || $txs >= $tx_thresh;
    }

    /**
     * Calculate US sales tax + excise for a transaction.
     *
     * @param float $gross_amount Pre-tax amount charged to the buyer.
     * @param array $order_data {
     *     @type string $product_type      Product category key.
     *     @type string $destination_state 2-letter US state code.
     *     @type bool   $marketplace_collects Whether the marketplace is the collector of record.
     * }
     * @param array $vendor_data {
     *     @type array  $state_nexus_state    Per-state nexus tracking.
     *     @type string $tax_regime           Unused for US (kept for interface parity).
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
        $product_type       = (string) ( $order_data['product_type']       ?? 'physical' );
        $destination_state  = (string) ( $order_data['destination_state']  ?? '' );
        $marketplace_collects = (bool) ( $order_data['marketplace_collects'] ?? true );

        // US-BUG-4: empty destination_state previously yielded $0 tax silently
        // (no warning, under-collection risk). Log a warning so admins can
        // detect missing state info; tax falls through to $0 below.
        if ( '' === $destination_state ) {
            LTMS_Core_Logger::warning(
                'US_TAX_NO_STATE',
                'No destination state — cannot calculate US sales tax; returning 0.'
            );
        }

        // 1. Determine if sales tax must be collected:
        //    - Marketplace facilitator state (default: any state with rate > 0), OR
        //    - Seller has economic nexus in the destination state.
        $facilitator_state = $this->is_marketplace_facilitator_state( $destination_state );
        $seller_nexus      = $this->has_economic_nexus( $vendor_data, $destination_state );
        $must_collect      = ( $facilitator_state && $marketplace_collects ) || $seller_nexus;

        // 2. State + local sales tax.
        $sales_tax_rate = $must_collect ? $this->get_state_rate( $destination_state ) : 0.0;
        $sales_tax      = round( $gross_amount * $sales_tax_rate, 2 );

        // 3. Federal excise tax (per-unit: tobacco/pack, fuel/gallon,
        //    alcohol/proof gallon). US-BUG-1: was previously a percentage of
        //    gross (produced nonsensical values for per-unit federal excise).
        $excise_rate  = $this->get_excise_per_unit_rate( $product_type );
        $excise_units = (float) ( $order_data['units'] ?? 0 );
        $excise_amt   = round( $excise_rate * $excise_units, 2 );

        // 4. Totals — no withholding in the US context.
        $total_taxes       = $sales_tax + $excise_amt;
        $total_withholding = 0.0;
        $net_to_vendor     = round( $gross_amount, 2 ); // Buyer tax doesn't reduce vendor payout.

        /**
         * Filters the US tax calculation result.
         *
         * @param array $result      Tax breakdown.
         * @param float $gross_amount Gross amount.
         * @param array $order_data  Order context.
         * @param array $vendor_data Vendor context.
         */
        return (array) apply_filters(
            'ltms_us_tax_calculate',
            [
                'gross'             => $gross_amount,
                'iva'               => $sales_tax,
                'iva_rate'          => $sales_tax_rate,
                'retefuente'        => 0.0,
                'retefuente_rate'   => 0.0,
                'reteiva'           => 0.0,
                'reteiva_rate'      => 0.0,
                'ica'               => 0.0,
                'ica_rate'          => 0.0,
                'isr'               => 0.0,
                'isr_rate'          => 0.0,
                'ieps'              => $excise_amt,
                'ieps_rate'         => $excise_rate, // Per-unit rate (US-BUG-1).
                'ieps_units'        => $excise_units,
                'impoconsumo'       => 0.0,
                'impoconsumo_rate'  => 0.0,
                'total_taxes'       => $total_taxes,
                'total_withholding' => $total_withholding,
                'net_to_vendor'     => $net_to_vendor,
                'platform_fee'      => 0.0,
                'strategy'          => self::class,
                'country'           => 'US',
                'currency'          => 'USD',
                'state'             => strtoupper( $destination_state ),
                'marketplace_facilitator' => $facilitator_state,
                'economic_nexus'    => $seller_nexus,
                'collected'         => $must_collect,
            ],
            $gross_amount,
            $order_data,
            $vendor_data
        );
    }

    /**
     * Whether to apply withholding for this vendor.
     *
     * The US has no VAT/sales-tax withholding mechanism analogous to CO/MX.
     * Income-tax backup withholding (Form W-9 / 1099-K) is handled at the
     * payout/disbursement layer, not in the per-transaction tax calculation,
     * so we always return false here.
         *
         * @param array $vendor_data Vendor data (unused for US).
         * @return bool
     */
    public function should_apply_withholding( array $vendor_data ): bool {
        return false;
    }

    /**
     * Country code.
     *
     * @return string
     */
    public function get_country_code(): string {
        return 'US';
    }
}
