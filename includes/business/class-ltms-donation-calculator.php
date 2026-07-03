<?php
/**
 * LTMS Donation Calculator
 *
 * Pure calculation logic for Fundación Cardio Infantil donations.
 * Computes donation amount based on admin-configured percentage and basis.
 *
 * This class is SIDE-EFFECT FREE: no DB writes, no hooks, no logging.
 * It reads config via LTMS_Core_Config::get() (which is cached/read-only)
 * and returns a structured result array. All persistence/orchestration
 * is handled by LTMS_Donation_Manager.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/business
 * @version    1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Donation_Calculator
 *
 * Pure math motor for donation amount computation.
 */
final class LTMS_Donation_Calculator {

    /**
     * Calculate the donation amount for an order.
     *
     * @param array $args {
     *     @type float  $platform_fee    Comisión del marketplace.
     *     @type float  $order_total     Total de la orden.
     *     @type float  $vendor_net      Neto del vendedor.
     *     @type float  $platform_profit Ganancia neta del marketplace (platform_fee - costos).
     *     @type string $currency        COP, MXN.
     *     @type float  $customer_extra  Donación extra del cliente (opt-in).
     * }
     * @return array {
     *     @type float  $basis_amount    Monto base usado para el cálculo.
     *     @type string $basis_type      Tipo de base (platform_fee, order_total, etc.).
     *     @type float  $percentage      Porcentaje aplicado.
     *     @type float  $raw_amount      Monto antes de redondeo y límites.
     *     @type float  $rounded_amount  Monto después de redondeo.
     *     @type float  $final_amount    Monto final (después de min/max).
     *     @type float  $customer_extra  Donación extra del cliente.
     *     @type float  $total_donation  Suma de final_amount + customer_extra.
     *     @type bool   $enabled         Si las donaciones están habilitadas.
     *     @type string $currency        Moneda.
     * }
     */
    public static function calculate( array $args ): array {
        $enabled = LTMS_Core_Config::get( 'ltms_donation_enabled', 'no' ) === 'yes';

        if ( ! $enabled ) {
            return self::zero_result( $args['currency'] ?? 'COP' );
        }

        $percentage = (float) LTMS_Core_Config::get( 'ltms_donation_percentage', 0.0 );
        // CALC-BUG-3: Clamp percentage to valid bounds [0, 100]. Admin UI sanitizes,
        // but a direct DB write could bypass it — clamp defensively.
        $percentage = max( 0.0, min( 100.0, $percentage ) );
        $basis_type = LTMS_Core_Config::get( 'ltms_donation_basis', 'platform_fee' );
        $min_amount = (float) LTMS_Core_Config::get( 'ltms_donation_min_amount', 0.0 );
        $max_amount = (float) LTMS_Core_Config::get( 'ltms_donation_max_amount', 0.0 );
        $rounding   = LTMS_Core_Config::get( 'ltms_donation_rounding', 'none' );

        $basis_amount = self::get_basis_amount( $basis_type, $args );
        $raw_amount   = round( $basis_amount * ( $percentage / 100 ), 2 );
        $rounded      = self::apply_rounding( $raw_amount, $rounding );
        $final        = self::apply_min_max( $rounded, $min_amount, $max_amount );

        // CALC-BUG-1: Clamp customer_extra to >= 0. A negative value (e.g. from a
        // corrupted order meta) would otherwise reduce the donation total.
        $customer_extra = max( 0.0, (float) ( $args['customer_extra'] ?? 0.0 ) );
        $total          = round( $final + $customer_extra, 2 );

        return [
            'basis_amount'   => round( $basis_amount, 2 ),
            'basis_type'     => $basis_type,
            'percentage'     => $percentage,
            'raw_amount'     => $raw_amount,
            'rounded_amount' => $rounded,
            'final_amount'   => $final,
            'customer_extra' => $customer_extra,
            'total_donation' => $total,
            'enabled'        => true,
            'currency'       => $args['currency'] ?? 'COP',
        ];
    }

    /**
     * Get the basis amount based on the configured type.
     *
     * @param string $basis_type One of: platform_fee, order_total, vendor_net, platform_profit.
     * @param array  $args       Input args from calculate().
     * @return float
     */
    private static function get_basis_amount( string $basis_type, array $args ): float {
        switch ( $basis_type ) {
            case 'order_total':
                // CALC-BUG-4: Clamp to >= 0 — a negative basis (e.g. from a malformed
                // split) would produce a negative raw_amount that apply_rounding()
                // would silently zero-out, but better to fail-safe here too.
                return max( 0.0, (float) ( $args['order_total'] ?? 0.0 ) );
            case 'vendor_net':
                return max( 0.0, (float) ( $args['vendor_net'] ?? 0.0 ) );
            case 'platform_profit':
                return max( 0.0, (float) ( $args['platform_profit'] ?? 0.0 ) );
            case 'platform_fee':
            default:
                return max( 0.0, (float) ( $args['platform_fee'] ?? 0.0 ) );
        }
    }

    /**
     * Apply rounding to the donation amount.
     *
     * COP-friendly rounding increments: 50 / 100 / 500 pesos.
     *
     * @param float  $amount   Raw donation amount.
     * @param string $rounding One of: none, up_50, up_100, up_500.
     * @return float
     */
    private static function apply_rounding( float $amount, string $rounding ): float {
        if ( $amount <= 0 ) {
            return 0.0;
        }
        switch ( $rounding ) {
            case 'up_50':
                return (float) ( ceil( $amount / 50 ) * 50 );
            case 'up_100':
                return (float) ( ceil( $amount / 100 ) * 100 );
            case 'up_500':
                return (float) ( ceil( $amount / 500 ) * 500 );
            case 'none':
            default:
                return (float) round( $amount, 2 );
        }
    }

    /**
     * Apply min/max limits.
     *
     * @param float $amount Amount after rounding.
     * @param float $min    Minimum donation (0 = no minimum).
     * @param float $max    Maximum donation (0 = no maximum).
     * @return float
     */
    private static function apply_min_max( float $amount, float $min, float $max ): float {
        // CALC-BUG-2: If min > max (misconfiguration), the original code would let
        // the min check override the max check, silently returning min even when
        // it exceeded max. Swap and log so admin can spot the bad config.
        if ( $min > 0 && $max > 0 && $min > $max ) {
            LTMS_Core_Logger::warning(
                'DONATION_MIN_MAX_INVALID',
                sprintf( 'Configuración inválida: min_amount (%.2f) > max_amount (%.2f). Intercambiando valores.', $min, $max ),
                [ 'min' => $min, 'max' => $max ]
            );
            $tmp = $min;
            $min = $max;
            $max = $tmp;
        }
        if ( $min > 0 && $amount < $min ) {
            return $min;
        }
        if ( $max > 0 && $amount > $max ) {
            return $max;
        }
        return $amount;
    }

    /**
     * Return a zero result (donations disabled or no basis).
     *
     * @param string $currency ISO 4217 code.
     * @return array
     */
    private static function zero_result( string $currency ): array {
        return [
            'basis_amount'   => 0.0,
            'basis_type'     => LTMS_Core_Config::get( 'ltms_donation_basis', 'platform_fee' ),
            'percentage'     => 0.0,
            'raw_amount'     => 0.0,
            'rounded_amount' => 0.0,
            'final_amount'   => 0.0,
            'customer_extra' => 0.0,
            'total_donation' => 0.0,
            'enabled'        => false,
            'currency'       => $currency,
        ];
    }

    /**
     * Validate that the donation percentage is within bounds.
     *
     * @param float $percentage Percentage value (0-100).
     * @return bool
     */
    public static function is_valid_percentage( float $percentage ): bool {
        return $percentage >= 0 && $percentage <= 100;
    }

    /**
     * Get a summary of the donation config for display (admin UI, transparency pages).
     *
     * @return array
     */
    public static function get_config_summary(): array {
        return [
            'enabled'    => LTMS_Core_Config::get( 'ltms_donation_enabled', 'no' ) === 'yes',
            'percentage' => (float) LTMS_Core_Config::get( 'ltms_donation_percentage', 0.0 ),
            'basis'      => LTMS_Core_Config::get( 'ltms_donation_basis', 'platform_fee' ),
            'min_amount' => (float) LTMS_Core_Config::get( 'ltms_donation_min_amount', 0.0 ),
            'max_amount' => (float) LTMS_Core_Config::get( 'ltms_donation_max_amount', 0.0 ),
            'rounding'   => LTMS_Core_Config::get( 'ltms_donation_rounding', 'none' ),
            'foundation' => LTMS_Core_Config::get( 'ltms_donation_foundation_name', 'Fundación Cardio Infantil' ),
            'frequency'  => LTMS_Core_Config::get( 'ltms_donation_payout_frequency', 'monthly' ),
        ];
    }
}
