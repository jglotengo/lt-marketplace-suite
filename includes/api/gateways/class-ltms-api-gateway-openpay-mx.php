<?php
/**
 * LTMS API Gateway Openpay MX
 * @package LTMS
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}



// ─────────────────────────────────────────────────────────────────────────────
// OPENPAY MX GATEWAY  (v1.7.5)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Class LTMS_Api_Gateway_Openpay_MX
 *
 * Pasarela Openpay para México (MXN).
 * Soporta: tarjeta, OXXO y SPEI.
 */
class LTMS_Api_Gateway_Openpay_MX extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'ltms_openpay_mx';
        $this->has_fields         = true;
        $this->method_title       = __( 'Openpay México — LT Marketplace', 'ltms' );
        $this->method_description = __( 'Pago con tarjeta, OXXO y SPEI vía Openpay México (MXN).', 'ltms' );
        $this->supports           = [ 'products', 'refunds' ];
        $this->init_form_fields();
        $this->init_settings();
        $this->title       = $this->get_option( 'title', __( 'Pago con tarjeta / OXXO / SPEI (Openpay MX)', 'ltms' ) );
        $this->description = $this->get_option( 'description', __( 'Paga de forma segura con Openpay México.', 'ltms' ) );
        $this->enabled     = $this->get_option( 'enabled', 'yes' );
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_checkout_scripts' ] );
        if ( is_admin() && 'yes' === $this->enabled ) {
            add_action( 'admin_notices', [ $this, 'maybe_show_credentials_notice' ] );
        }
    }

    public function maybe_show_credentials_notice(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
        $merchant_id = $this->get_option( 'merchant_id', '' );
        $private_key = $this->get_option( 'private_key', '' );
        if ( empty( $merchant_id ) || empty( $private_key ) ) {
            $url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=ltms_openpay_mx' );
            echo '<div class="notice notice-error is-dismissible"><p>' .
                 sprintf( wp_kses( __( '<strong>LTMS Openpay MX:</strong> Gateway activo sin credenciales. <a href="%s">Configurar</a>.', 'ltms' ), [ 'strong' => [], 'a' => [ 'href' => [] ] ] ), esc_url( $url ) ) .
                 '</p></div>';
        }
    }

    public function init_form_fields(): void {
        $this->form_fields = [
            'enabled'        => [ 'title' => __( 'Habilitar', 'ltms' ), 'type' => 'checkbox', 'label' => __( 'Habilitar Openpay México', 'ltms' ), 'default' => 'yes' ],
            'title'          => [ 'title' => __( 'Título', 'ltms' ), 'type' => 'text', 'default' => __( 'Pago con tarjeta / OXXO / SPEI (Openpay MX)', 'ltms' ), 'desc_tip' => true ],
            'description'    => [ 'title' => __( 'Descripción', 'ltms' ), 'type' => 'textarea', 'default' => __( 'Paga con Openpay México.', 'ltms' ) ],
            'testmode'       => [ 'title' => __( 'Sandbox', 'ltms' ), 'type' => 'checkbox', 'label' => __( 'Modo de pruebas', 'ltms' ), 'default' => 'yes', 'desc_tip' => true ],
            'merchant_id'    => [ 'title' => __( 'Merchant ID (MX)', 'ltms' ), 'type' => 'text', 'description' => __( 'Merchant ID de dashboard.openpay.mx', 'ltms' ), 'default' => '', 'desc_tip' => true ],
            'public_key'     => [ 'title' => __( 'Clave Pública (MX)', 'ltms' ), 'type' => 'text', 'description' => __( 'pk_... de Openpay MX', 'ltms' ), 'default' => '', 'desc_tip' => true ],
            'private_key'    => [ 'title' => __( 'Clave Privada (MX)', 'ltms' ), 'type' => 'password', 'description' => __( 'sk_... de Openpay MX', 'ltms' ), 'default' => '', 'desc_tip' => true ],
            'payment_method' => [ 'title' => __( 'Método', 'ltms' ), 'type' => 'select', 'options' => [ 'card' => __( 'Tarjeta', 'ltms' ), 'oxxo' => 'OXXO', 'spei' => 'SPEI' ], 'default' => 'card' ],
        ];
    }

    public function payment_fields(): void {
        if ( $this->description ) { echo '<p>' . esc_html( $this->description ) . '</p>'; }
        $method = $this->get_option( 'payment_method', 'card' );
        echo '<input type="hidden" name="_ltms_openpay_mx_method" value="' . esc_attr( $method ) . '" />';
        if ( 'card' === $method ) {
            echo '<fieldset id="ltms-openpay-mx-fields" style="border:0;padding:0;margin:0;">';
            echo '<div id="ltms-openpay-mx-card" style="padding:10px;border:1px solid #ccc;border-radius:4px;background:#fff;min-height:40px;"></div>';
            echo '<input type="hidden" name="openpay_mx_token" id="ltms_openpay_mx_token" value="" />';
            echo '<input type="hidden" name="openpay_mx_device_session_id" id="ltms_openpay_mx_device" value="" />';
            echo '</fieldset>';
        } elseif ( 'oxxo' === $method ) {
            echo '<p>' . esc_html__( 'Se generará una referencia OXXO al confirmar el pedido.', 'ltms' ) . '</p>';
        } else {
            echo '<p>' . esc_html__( 'Se generará una CLABE SPEI para transferencia bancaria.', 'ltms' ) . '</p>';
        }
    }

    public function validate_fields(): bool {
        $method = sanitize_text_field( wp_unslash( $_POST['_ltms_openpay_mx_method'] ?? 'card' ) );
        if ( 'card' === $method && empty( sanitize_text_field( wp_unslash( $_POST['openpay_mx_token'] ?? '' ) ) ) ) {
            wc_add_notice( __( 'Por favor completa los datos de tu tarjeta.', 'ltms' ), 'error' );
            return false;
        }
        return true;
    }

    public function process_payment( $order_id ): array {
        $order = wc_get_order( $order_id );
        if ( ! $order ) { wc_add_notice( __( 'Pedido no encontrado.', 'ltms' ), 'error' ); return [ 'result' => 'fail' ]; }
        $merchant_id = $this->get_option( 'merchant_id', '' );
        $private_key = $this->get_option( 'private_key', '' );
        $public_key  = $this->get_option( 'public_key', '' );
        $sandbox     = $this->get_option( 'testmode', 'yes' ) === 'yes';
        if ( empty( $merchant_id ) || empty( $private_key ) ) {
            wc_add_notice( __( 'Openpay MX no está configurado.', 'ltms' ), 'error' );
            return [ 'result' => 'fail' ];
        }
        $method  = sanitize_text_field( wp_unslash( $_POST['_ltms_openpay_mx_method'] ?? 'card' ) );
        $openpay = new LTMS_Api_Openpay( $merchant_id, $private_key, $public_key, 'MX', $sandbox );
        $amount  = (float) $order->get_total();
        try {
            if ( 'card' === $method ) {
                $result = $openpay->create_charge( [
                    'method'            => 'card',
                    'source_id'         => sanitize_text_field( wp_unslash( $_POST['openpay_mx_token'] ?? '' ) ),
                    'amount'            => $amount,
                    'currency'          => 'MXN',
                    'description'       => sprintf( __( 'Pedido #%d — lo-tengo.com.co', 'ltms' ), $order_id ),
                    'device_session_id' => sanitize_text_field( wp_unslash( $_POST['openpay_mx_device_session_id'] ?? '' ) ),
                    'customer'          => [ 'name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(), 'last_name' => '', 'phone_number' => $order->get_billing_phone() ?: '0000000000', 'email' => $order->get_billing_email() ],
                    'redirect_url'      => $order->get_checkout_order_received_url(),
                ] );
            } elseif ( 'oxxo' === $method ) {
                $result = $openpay->create_oxxo_charge( $amount, $order->get_billing_email(), $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(), sprintf( __( 'Pedido #%d', 'ltms' ), $order_id ) );
            } else {
                $result = $openpay->create_pse_charge( $amount, $order->get_billing_email(), $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(), '', $order->get_checkout_order_received_url() );
            }
        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::error( 'OPENPAY_MX_PROCESS_FAIL', $e->getMessage(), [ 'order_id' => $order_id ] );
            wc_add_notice( esc_html( $e->getMessage() ), 'error' );
            return [ 'result' => 'fail' ];
        }
        if ( empty( $result['id'] ) && empty( $result['success'] ) ) {
            wc_add_notice( esc_html( $result['error_message'] ?? $result['error'] ?? __( 'Error en Openpay MX.', 'ltms' ) ), 'error' );
            return [ 'result' => 'fail' ];
        }
        $charge_id = $result['id'] ?? ( $result['data']['id'] ?? '' );
        $order->update_meta_data( '_ltms_openpay_mx_charge_id', $charge_id );
        $order->update_meta_data( '_ltms_openpay_mx_method', $method );
        if ( 'card' === $method ) {
            $order->payment_complete( $charge_id );
            $order->add_order_note( sprintf( __( 'Pago tarjeta Openpay MX. Charge: %s', 'ltms' ), $charge_id ) );
        } else {
            $ref = $result['payment_method']['barcode_url'] ?? $result['payment_method']['clabe'] ?? $charge_id;
            $order->update_status( 'on-hold', sprintf( __( 'Esperando pago %s. Ref: %s', 'ltms' ), strtoupper( $method ), $ref ) );
            $order->update_meta_data( '_ltms_openpay_mx_reference', $ref );
        }
        $order->save();
        WC()->cart->empty_cart();
        return [ 'result' => 'success', 'redirect' => $order->get_checkout_order_received_url() ];
    }

    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) { return new WP_Error( 'ltms_openpay_mx_refund', __( 'Pedido no encontrado.', 'ltms' ) ); }
        $charge_id = $order->get_meta( '_ltms_openpay_mx_charge_id', true );
        if ( empty( $charge_id ) ) { return new WP_Error( 'ltms_openpay_mx_refund', __( 'No se encontró el cargo de Openpay MX.', 'ltms' ) ); }
        $openpay = new LTMS_Api_Openpay( $this->get_option( 'merchant_id', '' ), $this->get_option( 'private_key', '' ), $this->get_option( 'public_key', '' ), 'MX', $this->get_option( 'testmode', 'yes' ) === 'yes' );
        try {
            $result = $openpay->create_refund( $charge_id, $amount ?? (float) $order->get_total(), $reason ?: 'Reembolso solicitado' );
        } catch ( \Throwable $e ) {
            return new WP_Error( 'ltms_openpay_mx_refund', esc_html( $e->getMessage() ) );
        }
        if ( empty( $result['id'] ) && empty( $result['success'] ) ) {
            return new WP_Error( 'ltms_openpay_mx_refund', esc_html( $result['error_message'] ?? $result['error'] ?? 'Error' ) );
        }
        $order->add_order_note( sprintf( __( 'Reembolso Openpay MX. Monto: %s | Motivo: %s', 'ltms' ), wc_price( $amount ?? (float) $order->get_total() ), $reason ?: __( 'No especificado', 'ltms' ) ) );
        return true;
    }

    public function enqueue_checkout_scripts(): void {
        if ( ! is_checkout() || $this->enabled !== 'yes' ) { return; }
        $sandbox     = $this->get_option( 'testmode', 'yes' ) === 'yes';
        $merchant_id = $this->get_option( 'merchant_id', '' );
        $public_key  = $this->get_option( 'public_key', '' );
        if ( empty( $merchant_id ) || empty( $public_key ) ) { return; }
        $base = $sandbox ? 'https://sandbox-js.openpay.mx/v1.0/' : 'https://js.openpay.mx/';
        wp_enqueue_script( 'openpay-mx-js',   $base . 'openpay.v1.min.js',      [], null, true );
        wp_enqueue_script( 'openpay-mx-data', $base . 'openpay-data.v1.min.js', [ 'openpay-mx-js' ], null, true );
        wp_enqueue_script( 'ltms-openpay-mx', LTMS_ASSETS_URL . 'js/ltms-openpay-mx.js', [ 'jquery', 'openpay-mx-js', 'openpay-mx-data' ], defined( 'LTMS_VERSION' ) ? LTMS_VERSION : '1.7.5', true );
        wp_localize_script( 'ltms-openpay-mx', 'ltmsOpenpayMX', [ 'merchant_id' => $merchant_id, 'public_key' => $public_key, 'sandbox' => $sandbox, 'method' => $this->get_option( 'payment_method', 'card' ) ] );
    }
}

// Auto-registro en WooCommerce
add_filter( 'woocommerce_payment_gateways', static function( array $gateways ): array {
    if ( ! in_array( 'LTMS_Api_Gateway_Openpay_MX', $gateways, true ) ) {
        $gateways[] = 'LTMS_Api_Gateway_Openpay_MX';
    }
    return $gateways;
} );
