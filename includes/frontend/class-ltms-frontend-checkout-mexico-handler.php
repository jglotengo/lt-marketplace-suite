<?php
/**
 * LTMS Frontend Checkout Mexico Handler — Controlador AJAX del Checkout MX
 *
 * Expone los endpoints AJAX que el JS ltms-checkout-mexico.js necesita:
 *  - ltms_create_oxxo_reference  : genera referencia de pago OXXO
 *  - ltms_download_oxxo_voucher  : devuelve URL del PDF del comprobante OXXO
 *  - ltms_create_spei_reference  : genera CLABE SPEI para transferencia
 *  - ltms_get_msi_options        : planes de Meses Sin Intereses disponibles
 *
 * Bug corregido (M-20): el JS ltms-checkout-mexico.js llamaba estas acciones
 * pero no existía ningún handler PHP — solo aplica cuando country = 'MX'.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/frontend
 * @version    1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Frontend_Checkout_Mexico_Handler
 */
final class LTMS_Frontend_Checkout_Mexico_Handler {

    use LTMS_Logger_Aware;

    /** @var array<int,float> Planes MSI: meses => tasa de interés */
    private const MSI_PLANS = [
        3  => 0,
        6  => 0,
        9  => 0,
        12 => 0,
        18 => 0.05,
        24 => 0.08,
    ];

    /** @var float Monto mínimo para MSI (MXN) */
    private const MSI_MIN_AMOUNT = 300.00;

    /**
     * Registra los hooks AJAX del handler (solo en México).
     */
    public static function init(): void {
        $instance = new self();

        add_action( 'wp_ajax_ltms_create_oxxo_reference',       [ $instance, 'ajax_create_oxxo_reference' ] );
        add_action( 'wp_ajax_nopriv_ltms_create_oxxo_reference', [ $instance, 'ajax_create_oxxo_reference' ] );

        add_action( 'wp_ajax_ltms_download_oxxo_voucher',        [ $instance, 'ajax_download_oxxo_voucher' ] );
        add_action( 'wp_ajax_nopriv_ltms_download_oxxo_voucher', [ $instance, 'ajax_download_oxxo_voucher' ] );

        add_action( 'wp_ajax_ltms_create_spei_reference',        [ $instance, 'ajax_create_spei_reference' ] );
        add_action( 'wp_ajax_nopriv_ltms_create_spei_reference', [ $instance, 'ajax_create_spei_reference' ] );

        add_action( 'wp_ajax_ltms_get_msi_options',              [ $instance, 'ajax_get_msi_options' ] );
        add_action( 'wp_ajax_nopriv_ltms_get_msi_options',       [ $instance, 'ajax_get_msi_options' ] );
    }

    // =========================================================================
    // AJAX: ltms_create_oxxo_reference
    // =========================================================================

    public function ajax_create_oxxo_reference(): void {
        check_ajax_referer( 'ltms_checkout_nonce', 'nonce', false );

        $order_id = absint( $_POST['order_id'] ?? 0 );
        $order    = $order_id ? wc_get_order( $order_id ) : $this->get_order_from_session();

        if ( ! $order ) {
            wp_send_json_error( [ 'message' => __( 'Pedido no encontrado.', 'ltms' ) ] );
        }

        if ( $order->is_paid() ) {
            wp_send_json_error( [ 'message' => __( 'Este pedido ya fue pagado.', 'ltms' ) ] );
        }

        // Devolver referencia existente si ya fue generada
        $existing_ref = $order->get_meta( '_ltms_oxxo_reference' );
        if ( $existing_ref ) {
            wp_send_json_success( [
                'reference'   => $existing_ref,
                'amount'      => (float) $order->get_total(),
                'expiry_date' => $order->get_meta( '_ltms_oxxo_expiry' ) ?: gmdate( 'd/m/Y', strtotime( '+3 days' ) ),
                'barcode_url' => $order->get_meta( '_ltms_oxxo_barcode_url' ) ?: '',
            ] );
        }

        try {
            $openpay  = new LTMS_Api_Openpay();
            $customer = [
                'name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
                'email' => $order->get_billing_email(),
            ];

            $charge = $openpay->create_oxxo_charge(
                (float) $order->get_total(),
                sprintf( __( 'Pedido #%d', 'ltms' ), $order->get_id() ),
                $customer,
                'LTMS-' . $order->get_id() . '-' . time()
            );

            $reference   = $charge['payment_method']['reference'] ?? '';
            $barcode_url = $charge['payment_method']['barcode_url'] ?? '';
            $expiry_date = gmdate( 'd/m/Y', strtotime( '+3 days' ) );

            if ( empty( $reference ) ) {
                wp_send_json_error( [ 'message' => __( 'No se pudo generar la referencia OXXO.', 'ltms' ) ] );
            }

            $order->update_meta_data( '_ltms_oxxo_reference', $reference );
            $order->update_meta_data( '_ltms_oxxo_barcode_url', $barcode_url );
            $order->update_meta_data( '_ltms_oxxo_expiry', $expiry_date );
            $order->update_meta_data( '_ltms_oxxo_charge_id', $charge['id'] ?? '' );
            $order->update_status( 'pending', sprintf( __( 'Referencia OXXO generada: %s', 'ltms' ), $reference ) );
            $order->save();

            wp_send_json_success( [
                'reference'   => $reference,
                'amount'      => (float) $order->get_total(),
                'expiry_date' => $expiry_date,
                'barcode_url' => $barcode_url,
            ] );

        } catch ( \Throwable $e ) {
            $this->log_error( 'OXXO_REFERENCE_ERROR', $e->getMessage(), [ 'order_id' => $order->get_id() ] );
            wp_send_json_error( [ 'message' => __( 'Error al conectar con Openpay. Intenta de nuevo.', 'ltms' ) ] );
        }
    }

    // =========================================================================
    // AJAX: ltms_download_oxxo_voucher
    // =========================================================================

    public function ajax_download_oxxo_voucher(): void {
        check_ajax_referer( 'ltms_checkout_nonce', 'nonce', false );

        $order_id  = absint( $_POST['order_id'] ?? 0 );
        $reference = sanitize_text_field( $_POST['reference'] ?? '' );
        $order     = $order_id ? wc_get_order( $order_id ) : null;

        if ( ! $order || empty( $reference ) ) {
            wp_send_json_error( [ 'message' => __( 'Datos inválidos.', 'ltms' ) ] );
        }

        $barcode_url = $order->get_meta( '_ltms_oxxo_barcode_url' );

        if ( $barcode_url ) {
            wp_send_json_success( [ 'pdf_url' => $barcode_url ] );
        } else {
            $charge_id = $order->get_meta( '_ltms_oxxo_charge_id' );
            if ( $charge_id ) {
                wp_send_json_success( [
                    'pdf_url'   => '',
                    'reference' => $reference,
                    'message'   => __( 'Descarga no disponible. Usa la referencia para pagar en OXXO.', 'ltms' ),
                ] );
            } else {
                wp_send_json_error( [ 'message' => __( 'Comprobante no disponible.', 'ltms' ) ] );
            }
        }
    }

    // =========================================================================
    // AJAX: ltms_create_spei_reference
    // =========================================================================

    public function ajax_create_spei_reference(): void {
        check_ajax_referer( 'ltms_checkout_nonce', 'nonce', false );

        $order_id = absint( $_POST['order_id'] ?? 0 );
        $order    = $order_id ? wc_get_order( $order_id ) : $this->get_order_from_session();

        if ( ! $order ) {
            wp_send_json_error( [ 'message' => __( 'Pedido no encontrado.', 'ltms' ) ] );
        }

        // Devolver CLABE existente si ya fue generada
        $existing_clabe = $order->get_meta( '_ltms_spei_clabe' );
        if ( $existing_clabe ) {
            wp_send_json_success( [
                'clabe'       => $existing_clabe,
                'bank_name'   => $order->get_meta( '_ltms_spei_bank' ) ?: 'STP',
                'beneficiary' => get_bloginfo( 'name' ),
                'amount'      => (float) $order->get_total(),
                'expiry_date' => $order->get_meta( '_ltms_spei_expiry' ) ?: gmdate( 'd/m/Y H:i', strtotime( '+24 hours' ) ),
            ] );
        }

        try {
            $openpay  = new LTMS_Api_Openpay();
            $customer = [
                'name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
                'email' => $order->get_billing_email(),
            ];

            $charge = $openpay->create_pse_charge(
                (float) $order->get_total(),
                sprintf( __( 'Pedido #%d — Lo Tengo', 'ltms' ), $order->get_id() ),
                $customer,
                '',
                $order->get_checkout_order_received_url(),
                'LTMS-SPEI-' . $order->get_id() . '-' . time()
            );

            $clabe       = $charge['payment_method']['clabe'] ?? $charge['payment_method']['url'] ?? '';
            $bank_name   = $charge['payment_method']['name'] ?? 'STP';
            $expiry_date = gmdate( 'd/m/Y H:i', strtotime( '+24 hours' ) );

            if ( empty( $clabe ) ) {
                $payment_url = $charge['payment_method']['url'] ?? '';
                if ( $payment_url ) {
                    $order->update_meta_data( '_ltms_spei_charge_id', $charge['id'] ?? '' );
                    $order->update_status( 'pending', __( 'Esperando pago SPEI.', 'ltms' ) );
                    $order->save();
                    wp_send_json_success( [
                        'clabe'        => 'Ver instrucciones en pantalla',
                        'bank_name'    => 'Openpay',
                        'beneficiary'  => get_bloginfo( 'name' ),
                        'amount'       => (float) $order->get_total(),
                        'expiry_date'  => $expiry_date,
                        'redirect_url' => $payment_url,
                    ] );
                }
                wp_send_json_error( [ 'message' => __( 'No se pudo generar la CLABE SPEI.', 'ltms' ) ] );
            }

            $order->update_meta_data( '_ltms_spei_clabe', $clabe );
            $order->update_meta_data( '_ltms_spei_bank', $bank_name );
            $order->update_meta_data( '_ltms_spei_expiry', $expiry_date );
            $order->update_meta_data( '_ltms_spei_charge_id', $charge['id'] ?? '' );
            $order->update_status( 'pending', sprintf( __( 'CLABE SPEI generada: %s', 'ltms' ), $clabe ) );
            $order->save();

            wp_send_json_success( [
                'clabe'       => $clabe,
                'bank_name'   => $bank_name,
                'beneficiary' => get_bloginfo( 'name' ),
                'amount'      => (float) $order->get_total(),
                'expiry_date' => $expiry_date,
            ] );

        } catch ( \Throwable $e ) {
            $this->log_error( 'SPEI_REFERENCE_ERROR', $e->getMessage(), [ 'order_id' => $order->get_id() ] );
            wp_send_json_error( [ 'message' => __( 'Error al generar CLABE SPEI. Intenta de nuevo.', 'ltms' ) ] );
        }
    }

    // =========================================================================
    // AJAX: ltms_get_msi_options
    // =========================================================================

    public function ajax_get_msi_options(): void {
        check_ajax_referer( 'ltms_checkout_nonce', 'nonce', false );

        $amount = (float) ( $_POST['amount'] ?? 0 );

        if ( $amount < self::MSI_MIN_AMOUNT ) {
            wp_send_json_success( [
                'plans'   => [],
                'message' => sprintf(
                    __( 'MSI disponible para compras mayores a %s MXN', 'ltms' ),
                    number_format( self::MSI_MIN_AMOUNT, 0, '.', ',' )
                ),
            ] );
        }

        $plans = [];
        foreach ( self::MSI_PLANS as $months => $interest_rate ) {
            $monthly_base = $amount / $months;
            if ( $monthly_base < 50 ) {
                continue;
            }

            $total_with_interest = $amount * ( 1 + $interest_rate );
            $monthly_amount      = $total_with_interest / $months;

            $plans[] = [
                'months'         => $months,
                'monthly_amount' => round( $monthly_amount, 2 ),
                'interest_rate'  => $interest_rate,
                'total'          => round( $total_with_interest, 2 ),
            ];
        }

        wp_send_json_success( [
            'plans'      => $plans,
            'currency'   => 'MXN',
            'min_amount' => self::MSI_MIN_AMOUNT,
        ] );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function get_order_from_session(): ?WC_Order {
        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return null;
        }
        $order_id = WC()->session->get( 'order_awaiting_payment' );
        if ( $order_id ) {
            $order = wc_get_order( absint( $order_id ) );
            if ( $order ) {
                return $order;
            }
        }
        return null;
    }
}
