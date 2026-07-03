<?php
/**
 * LTMS Tax Strategy Brazil
 *
 * Implements Brazilian tax calculation for marketplace transactions.
 *
 * - ICMS (Imposto sobre Circulação de Mercadorias e Serviços): state-level
 *   consumption tax, 17%–19% depending on UF (São Paulo 18%, Rio 20% for
 *   some operations, etc.). The complementary LC 190/2022 + Lei 14.874/2024
 *   introduced a partial ICMS reform.
 * - IPI (Imposto sobre Produtos Industrializados): federal excise on
 *   manufactured goods, varies by NCM/Tipi (0%–30%+).
 * - PIS/COFINS: federal contributions (PIS 0.65%–1.65%, COFINS 3%–7.6%).
 * - ISS (Imposto Sobre Serviços): municipal service tax (2%–5%) — applies
 *   only to services, not goods.
 * - "Remessa Conforme" (Lei 14.973/2024 + Portaria MF 612/2024): for
 *   cross-border shipments under USD 50, ICMS charged at 17% effective on
 *   the import value with a 20% reduction on the calculation base.
 *   Above USD $50: 60% rate on top of CIF (the so-called "taxa de 60%").
 *
 * Strategy interface return shape keeps the CO/MX fields for compatibility,
 * mapping BR concepts as follows:
 *   - iva        → ICMS (state tax)
 *   - ieps       → IPI (federal excise)
 *   - retefuente → PIS/COFINS retained (when applicable)
 *   - reteiva    → 0 (no VAT-withholding analog)
 *   - ica        → ISS (municipal, only for services)
 *   - ii         → Imposto de Importação (cross-border over USD 50)
 *   - isr        → IRPJ/CSLL withholding (when applicable)
 *
 * Legal basis: CF/88 arts. 153, 155, 156; LC 87/96 (ICMS); LC 116/03 (ISS);
 * Lei 10.637/02 (PIS), Lei 10.833/03 (COFINS); Decreto 7.212/10 (IPI/Tipi);
 * Lei 14.973/2024 + Portaria MF 612/2024 (Remessa Conforme).
 *
 * @package    LTMS
 * @subpackage LTMS/includes/business/strategies
 * @version    1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Tax_Strategy_Brazil
 */
final class LTMS_Tax_Strategy_Brazil implements LTMS_Tax_Strategy_Interface {

    /**
     * Default ICMS rate per UF (Unidade da Federação).
     *
     * Configurable via `ltms_br_icms_rates` ([ '{UF}' => rate ]).
     * Values are simplified standard internal rates (percentage).
     *
     * @var array<string,float>
     */
    private const DEFAULT_ICMS_RATES = [
        'AC' => 17.0, 'AL' => 18.0, 'AP' => 18.0, 'AM' => 18.0,
        'BA' => 18.0, 'CE' => 18.0, 'DF' => 18.0, 'ES' => 17.0,
        'GO' => 17.0, 'MA' => 18.0, 'MT' => 17.0, 'MS' => 17.0,
        'MG' => 18.0, 'PA' => 17.0, 'PB' => 18.0, 'PR' => 19.0,
        'PE' => 18.0, 'PI' => 18.0, 'RJ' => 20.0, 'RN' => 18.0,
        'RS' => 17.0, 'RO' => 17.5, 'RR' => 17.0, 'SC' => 17.0,
        'SP' => 18.0, 'SE' => 18.0, 'TO' => 18.0,
    ];

    /**
     * Get the ICMS rate for a given UF.
     *
     * @param string $uf 2-letter Brazilian state code (e.g. 'SP').
     * @return float Rate as percentage (0-100).
     */
    private function get_icms_rate( string $uf ): float {
        $uf = strtoupper( $uf );

        $configured = LTMS_Core_Config::get( 'ltms_br_icms_rates', [] );
        if ( is_array( $configured ) && isset( $configured[ $uf ] ) ) {
            return (float) $configured[ $uf ];
        }

        return (float) ( self::DEFAULT_ICMS_RATES[ $uf ] ?? 18.0 );
    }

    /**
     * Get IPI rate for a product NCM.
     *
     * Real-world IPI requires lookup of the NCM against the Tipi table
     * (Decreto 7.212/10). Here we expose a small default table for
     * common categories; admins MUST override `ltms_br_ipi_rates` with
     * real NCM-keyed rates for compliance.
     *
     * @param string $product_type Product category key or NCM.
     * @return float Rate as percentage (0-100).
     */
    private function get_ipi_rate( string $product_type ): float {
        $configured = LTMS_Core_Config::get( 'ltms_br_ipi_rates', [] );
        if ( is_array( $configured ) && isset( $configured[ $product_type ] ) ) {
            return (float) $configured[ $product_type ];
        }

        $defaults = [
            'tobacco'            => 60.0,
            'beverages_spirits'  => 30.0,
            'beer'               => 20.0,
            'soft_drinks'        => 10.0,
            'cosmetics'          => 20.0,
            'electronics'        => 5.0,
            'auto_parts'         => 5.0,
            'clothing'           => 0.0,
            'food_general'       => 0.0,
            'books'              => 0.0,
            'medicine'           => 0.0,
        ];

        return (float) ( $defaults[ $product_type ] ?? 0.0 );
    }

    /**
     * Get combined PIS/COFINS rate for a given vendor regime.
     *
     * Cumulative regime (lucro_presumido): PIS 0.65% + COFINS 3% = 3.65%.
     * Non-cumulative regime (lucro_real): PIS 1.65% + COFINS 7.6% = 9.25%.
     * Configurable per regime via `ltms_br_pis_cofins_rates` ( [ '{regime}' => rate ] ).
     *
     * @param string $regime Vendor tax regime ('lucro_real' | 'lucro_presumido').
     * @return float Rate as percentage (0-100).
     */
    private function get_pis_cofins_rate( string $regime = 'lucro_presumido' ): float {
        $configured = LTMS_Core_Config::get( 'ltms_br_pis_cofins_rates', [] );
        if ( is_array( $configured ) && isset( $configured[ $regime ] ) ) {
            return (float) $configured[ $regime ];
        }
        // BR-BUG-3: switch PIS/COFINS rate by regime (was hardcoded 3.65%).
        return ( 'lucro_real' === $regime ) ? 9.25 : 3.65;
    }

    /**
     * Get ISS rate for a given municipality.
     *
     * @param string $municipality_code IBGE municipality code (optional).
     * @return float Rate as percentage (0-100).
     */
    private function get_iss_rate( string $municipality_code ): float {
        $configured = LTMS_Core_Config::get( 'ltms_br_iss_rates', [] );
        if ( is_array( $configured ) && $municipality_code !== '' && isset( $configured[ $municipality_code ] ) ) {
            return (float) $configured[ $municipality_code ];
        }
        return (float) LTMS_Core_Config::get( 'ltms_br_iss_rate_default', 5.0 );
    }

    /**
     * Determine whether the "Remessa Conforme" regime applies to a
     * cross-border import.
     *
     * Rules (Portaria MF 612/2024):
     *   - Buyer is PF (pessoa física).
     *   - Importer is registered as Remessa Conforme (Shein, Shopee, AliExpress, etc.).
     *   - CIF value ≤ USD 50  → 17% ICMS with 20% reduction on base.
     *   - CIF value > USD 50  → 60% combined rate on CIF (60% Imposto de Importação
     *     compounded, ICMS still applies).
     *
     * @param array $order_data {
     *     @type bool   $is_cross_border    True if import from non-BR origin.
     *     @type bool   $remessa_conforme   Importer enrolled in Remessa Conforme.
     *     @type bool   $buyer_is_pf        Buyer is individual.
     *     @type float  $cif_value          CIF value in USD for threshold check.
     * }
     * @return bool
     */
    private function is_remessa_conforme( array $order_data ): bool {
        $is_cross_border  = (bool) ( $order_data['is_cross_border']  ?? false );
        $remessa_conforme = (bool) ( $order_data['remessa_conforme'] ?? false );
        $buyer_is_pf      = (bool) ( $order_data['buyer_is_pf']      ?? true );

        return $is_cross_border && $remessa_conforme && $buyer_is_pf;
    }

    /**
     * Calculate Brazilian taxes for a transaction.
     *
     * @param float $gross_amount Pre-tax amount charged to the buyer.
     * @param array $order_data {
     *     @type string $product_type        Product category key.
     *     @type string $destination_uf      2-letter Brazilian state code.
     *     @type string $origin_uf           Origin state (for ICMS inter-state rules).
     *     @type string $municipality_code   IBGE code (for ISS).
     *     @type bool   $is_service          True if service (ISS instead of ICMS).
     *     @type bool   $is_cross_border     True if import.
     *     @type bool   $remessa_conforme    Importer enrolled.
     *     @type bool   $buyer_is_pf         Buyer is individual.
     *     @type float  $cif_value           CIF value for import tax.
     *     @type string $ncm                 NCM code (optional, for IPI lookup).
     * }
     * @param array $vendor_data {
     *     @type string $tax_regime          'cumulative' | 'non_cumulative' | 'simples_nacional'.
     *     @type bool   $is_industrialized   Vendor industrializes the product (IPI applies).
     *     @type string $cnpj                Vendor CNPJ.
     * }
     * @return array Tax breakdown compatible with LTMS_Tax_Strategy_Interface.
         * @phpstan-return array{
     *     iva: float, iva_rate: float, retefuente: float, retefuente_rate: float,
     *     reteiva: float, reteiva_rate: float, ica: float, ica_rate: float,
     *     isr: float, isr_rate: float, ieps: float, ieps_rate: float,
     *     ii: float, ii_rate: float,
     *     total_taxes: float, total_withholding: float, net_to_vendor: float,
     *     strategy: string
     * }
     */
    public function calculate( float $gross_amount, array $order_data, array $vendor_data ): array {
        $product_type      = (string) ( $order_data['product_type']      ?? 'physical' );
        $destination_uf    = strtoupper( (string) ( $order_data['destination_uf'] ?? 'SP' ) );
        $municipality_code = (string) ( $order_data['municipality_code'] ?? '' );
        $is_service        = (bool) ( $order_data['is_service']          ?? false );
        $cif_value         = (float) ( $order_data['cif_value']          ?? $gross_amount );
        $ncm               = (string) ( $order_data['ncm']               ?? '' );

        $vendor_regime       = (string) ( $vendor_data['tax_regime']        ?? 'simples_nacional' );
        $vendor_industrial   = (bool)   ( $vendor_data['is_industrialized'] ?? false );

        $remessa_conforme = $this->is_remessa_conforme( $order_data );

        // ── 1. ICMS (or ISS for services) ───────────────────────────────────
        $icms_rate  = 0.0;
        $icms_base  = $gross_amount;
        $icms_amount = 0.0;
        $iss_rate   = 0.0;
        $iss_amount = 0.0;
        // Imposto de Importação (II) — only for Remessa Conforme over USD 50.
        $ii_rate    = 0.0;
        $ii_amount  = 0.0;

        if ( $is_service ) {
            $iss_rate   = $this->get_iss_rate( $municipality_code );
            $iss_amount = round( $gross_amount * ( $iss_rate / 100 ), 2 );
        } else {
            $icms_rate = $this->get_icms_rate( $destination_uf );

            if ( $remessa_conforme ) {
                $remessa_threshold = (float) LTMS_Core_Config::get( 'ltms_br_remessa_threshold', 50.0 );

                if ( $cif_value <= $remessa_threshold ) {
                    // Under USD 50: 17% ICMS with 20% reduction on calculation base.
                    $icms_rate    = (float) LTMS_Core_Config::get( 'ltms_br_remessa_icms_rate', 17.0 );
                    $icms_base    = $cif_value * 0.80; // 20% reduction.
                    $icms_amount  = round( $icms_base * ( $icms_rate / 100 ), 2 );
                } else {
                    // Above USD 50: II (Imposto de Importação) at 60% on CIF +
                    // ICMS still applies on (CIF + II) with 20% reduction.
                    // II is reported separately (BR-BUG-2) — previously it was
                    // bundled into icms_amount causing audit/compliance issues.
                    $ii_rate   = (float) LTMS_Core_Config::get( 'ltms_br_remessa_over_threshold_rate', 60.0 );
                    $ii_amount = round( $cif_value * ( $ii_rate / 100 ), 2 );
                    $icms_base    = ( $cif_value + $ii_amount ) * 0.80; // 20% reduction on combined base.
                    $icms_amount  = round( $icms_base * ( $icms_rate / 100 ), 2 );
                }
            } else {
                $icms_amount = round( $gross_amount * ( $icms_rate / 100 ), 2 );
            }
        }

        // ── 2. IPI (federal excise on manufactured goods) ──────────────────
        $ipi_rate   = 0.0;
        $ipi_amount = 0.0;

        if ( ! $is_service && $vendor_industrial ) {
            // Simples Nacional vendors do not charge IPI separately on internal sales.
            if ( $vendor_regime !== 'simples_nacional' ) {
                $ipi_rate   = $this->get_ipi_rate( $ncm !== '' ? $ncm : $product_type );
                $ipi_amount = round( $gross_amount * ( $ipi_rate / 100 ), 2 );
            }
        }

        // ── 3. PIS/COFINS (federal contributions) ──────────────────────────
        // Simples Nacional already bundles these; only retained for non-Simples vendors.
        $pis_cofins_rate   = 0.0;
        $pis_cofins_amount = 0.0;

        if ( $vendor_regime !== 'simples_nacional' && $this->should_apply_withholding( $vendor_data ) ) {
            // Rate depends on regime: lucro_real = 9.25% (non-cumulative),
            // lucro_presumido = 3.65% (cumulative) — BR-BUG-3.
            $pis_cofins_rate   = $this->get_pis_cofins_rate( $vendor_regime );
            $pis_cofins_amount = round( $gross_amount * ( $pis_cofins_rate / 100 ), 2 );
        }

        // ── 4. IRPJ/CSLL withholding (mapped to isr for interface parity) ──
        // Marketplace withholding (Lei 12.974/14, IN RFB 1.681/17): 1.5% on
        // service payouts to PJ vendors, 1.0% on goods payouts. Only for
        // non-Simples PJ vendors above R$ 10 monthly.
        $isr_rate   = 0.0;
        $isr_amount = 0.0;

        if ( $this->should_apply_withholding( $vendor_data ) ) {
            $isr_rate = $is_service
                ? (float) LTMS_Core_Config::get( 'ltms_br_irpj_services_rate', 1.5 )
                : (float) LTMS_Core_Config::get( 'ltms_br_irpj_goods_rate',    1.0 );
            $isr_amount = round( $gross_amount * ( $isr_rate / 100 ), 2 );
        }

        // ── 5. Totals ──────────────────────────────────────────────────────
        $total_taxes       = round( $icms_amount + $iss_amount + $ipi_amount + $ii_amount, 2 );
        $total_withholding = round( $pis_cofins_amount + $isr_amount, 2 );
        $net_to_vendor     = round( $gross_amount - $total_withholding, 2 );

        /**
         * Filters the Brazilian tax calculation result.
         *
         * @param array $result      Tax breakdown.
         * @param float $gross_amount Gross amount.
         * @param array $order_data  Order context.
         * @param array $vendor_data Vendor context.
         */
        return (array) apply_filters(
            'ltms_br_tax_calculate',
            [
                'gross'             => $gross_amount,
                'iva'               => $icms_amount,
                'iva_rate'          => $icms_rate / 100, // Interface expects decimal.
                'retefuente'        => $pis_cofins_amount,
                'retefuente_rate'   => $pis_cofins_rate / 100,
                'reteiva'           => 0.0,
                'reteiva_rate'      => 0.0,
                'ica'               => $iss_amount,
                'ica_rate'          => $iss_rate / 100,
                'isr'               => $isr_amount,
                'isr_rate'          => $isr_rate / 100,
                'ieps'              => $ipi_amount,
                'ieps_rate'         => $ipi_rate / 100,
                'impoconsumo'       => 0.0,
                'impoconsumo_rate'  => 0.0,
                'ii'                => $ii_amount,
                'ii_rate'           => $ii_rate,
                'total_taxes'       => $total_taxes,
                'total_withholding' => $total_withholding,
                'net_to_vendor'     => $net_to_vendor,
                'platform_fee'      => 0.0,
                'strategy'          => self::class,
                'country'           => 'BR',
                'currency'          => 'BRL',
                'uf'                => strtoupper( $destination_uf ),
                'remessa_conforme'  => $remessa_conforme,
                'is_service'        => $is_service,
                'icms_base'         => round( $icms_base, 2 ),
                'pis_cofins'        => $pis_cofins_amount,
                'pis_cofins_rate'   => $pis_cofins_rate,
                'ipi_rate'          => $ipi_rate,
            ],
            $gross_amount,
            $order_data,
            $vendor_data
        );
    }

    /**
     * Whether to apply withholding for this vendor.
     *
     * Simples Nacional vendors are not subject to PIS/COFINS or IRPJ
     * withholding — the regime is unified. Only PJ vendors under the
     * "lucro real" / "lucro presumido" regimes trigger withholding.
     *
     * @param array $vendor_data {
     *     @type string $tax_regime Vendor tax regime.
     * }
     * @return bool
     */
    public function should_apply_withholding( array $vendor_data ): bool {
        $regime = $vendor_data['tax_regime'] ?? 'simples_nacional';
        return in_array( $regime, [ 'lucro_real', 'lucro_presumido' ], true );
    }

    /**
     * Country code.
     *
     * @return string
     */
    public function get_country_code(): string {
        return 'BR';
    }
}
