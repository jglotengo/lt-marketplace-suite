<?php
/**
 * LTMS API Gateways — Openpay y Addi (WC_Payment_Gateway)
 *
 * Integra Openpay y Addi como pasarelas de pago nativas en WooCommerce.
 * Son registradas por LTMS_Core_Kernel::register_payment_gateways().
 *
 * @package    LTMS
 * @subpackage LTMS/includes/api/gateways
 * @version    1.7.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// OPENPAY GATEWAY
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Class LTMS_Api_Gateway_Openpay
 *
 * Pasarela de pago Openpay para Colombia y México.
 * Soporta: tarjetas de crédito/débito, PSE, efectivo (Bancolombia, OXXO).
 */
class LTMS_Api_Gateway_Openpay extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'ltms_openpay';
        $this->has_fields         = true;
        $this->method_title       = __( 'Openpay — LT Marketplace', 'ltms' );
        $this->method_description = __( 'Pago con tarjeta, PSE y efectivo vía Openpay.', 'ltms' );
        $this->supports           = [ 'products', 'refunds' ];

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option( 'title', __( 'Pago con tarjeta / PSE (Openpay)', 'ltms' ) );
        $this->description = $this->get_option( 'description', __( 'Paga de forma segura con Openpay.', 'ltms' ) );
        $this->enabled     = $this->get_option( 'enabled', 'yes' );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
    }

    public function init_form_fields(): void {
        $this->form_fields = [
            'enabled'    => [
                'title'   => __( 'Habilitar', 'ltms' ),
                'type'    => 'checkbox',
                'label'   => __( 'Habilitar Openpay', 'ltms' ),
                'default' => 'yes',
            ],
            'title'      => [
                'title'   => __( 'Título', 'ltms' ),
                'type'    => 'text',
                'default' => __( 'Pago con tarjeta / PSE (Openpay)', 'ltms' ),
            ],
            'description' => [
                'title'   => __( 'Descripción', 'ltms' ),
                'type'    => 'textarea',
                'default' => __( 'Paga de forma segura con Openpay.', 'ltms' ),
            ],
            'testmode'   => [
                'title'   => __( 'Modo sandbox', 'ltms' ),
                'type'    => 'checkbox',
                'label'   => __( 'Activar sandbox', 'ltms' ),
                'default' => 'yes',
            ],
            'merchant_id' => [
                'title'   => __( 'Merchant ID', 'ltms' ),
                'type'    => 'text',
                'default' => '',
            ],
            'public_key' => [
                'title'   => __( 'Llave Pública', 'ltms' ),
                'type'    => 'text',
                'default' => '',
            ],
            'private_key' => [
                'title'   => __( 'Llave Privada', 'ltms' ),
                'type'    => 'password',
                'default' => '',
            ],
        ];
    }

    /**
     * Procesa el pago de un pedido.
     *
     * @param int $order_id
     * @return array{result:string, redirect:string}
     */
    public function process_payment( $order_id ): array {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wc_add_notice( __( 'Pedido no encontrado.', 'ltms' ), 'error' );
            return [ 'result' => 'failure', 'redirect' => '' ];
        }

        try {
            if ( ! class_exists( 'LTMS_Api_Openpay' ) ) {
                throw new \RuntimeException( __( 'Módulo Openpay no disponible.', 'ltms' ) );
            }

            $client  = LTMS_Api_Factory::get( 'openpay' );
            $token   = sanitize_text_field( $_POST['openpay_token'] ?? '' ); // phpcs:ignore
            $device  = sanitize_text_field( $_POST['openpay_device_session_id'] ?? '' ); // phpcs:ignore

            $result = $client->charge( [
                'order_id'         => $order_id,
                'amount'           => $order->get_total(),
                'currency'         => get_woocommerce_currency(),
                'source_id'        => $token,
                'device_session_id'=> $device,
                'description'      => sprintf( __( 'Pedido #%s', 'ltms' ), $order->get_order_number() ),
                'customer'         => [
                    'name'         => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                    'email'        => $order->get_billing_email(),
                    'phone_number' => $order->get_billing_phone(),
                ],
            ] );

            $order->payment_complete( $result['id'] ?? '' );
            $order->update_meta_data( '_openpay_transaction_id', $result['id'] ?? '' );
            $order->save();

            WC()->cart->empty_cart();

            return [
                'result'   => 'success',
                'redirect' => $this->get_return_url( $order ),
            ];

        } catch ( \Throwable $e ) {
            wc_add_notice( $e->getMessage(), 'error' );
            return [ 'result' => 'failure', 'redirect' => '' ];
        }
    }

    /**
     * Procesa un reembolso.
     *
     * @param int    $order_id
     * @param float  $amount
     * @param string $reason
     * @return bool|WP_Error
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        $order  = wc_get_order( $order_id );
        $txn_id = $order ? $order->get_meta( '_openpay_transaction_id' ) : '';

        if ( ! $txn_id ) {
            return new WP_Error( 'no_txn', __( 'No se encontró la transacción Openpay para reembolsar.', 'ltms' ) );
        }

        try {
            $client = LTMS_Api_Factory::get( 'openpay' );
            $client->refund( $txn_id, (float) $amount, $reason );
            return true;
        } catch ( \Throwable $e ) {
            return new WP_Error( 'refund_failed', $e->getMessage() );
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// ADDI GATEWAY
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Class LTMS_Api_Gateway_Addi
 *
 * Pasarela BNPL (Buy Now Pay Later) de Addi para Colombia.
 * Redirige al cliente al flujo de Addi y recibe confirmación por webhook.
 */
class LTMS_Api_Gateway_Addi extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'ltms_addi';
        $this->has_fields         = false;
        $this->method_title       = __( 'Addi — Compra ahora, paga después', 'ltms' );
        $this->method_description = __( 'Permite a tus clientes financiar sus compras con Addi.', 'ltms' );
        $this->supports           = [ 'products' ];

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option( 'title', __( 'Paga en cuotas con Addi', 'ltms' ) );
        $this->description = $this->get_option( 'description', __( 'Financia tu compra sin tarjeta de crédito.', 'ltms' ) );
        $this->enabled     = $this->get_option( 'enabled', 'yes' );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
    }

    public function init_form_fields(): void {
        $this->form_fields = [
            'enabled'       => [
                'title'   => __( 'Habilitar', 'ltms' ),
                'type'    => 'checkbox',
                'label'   => __( 'Habilitar Addi BNPL', 'ltms' ),
                'default' => 'yes',
            ],
            'title'         => [
                'title'   => __( 'Título', 'ltms' ),
                'type'    => 'text',
                'default' => __( 'Paga en cuotas con Addi', 'ltms' ),
            ],
            'description'   => [
                'title'   => __( 'Descripción', 'ltms' ),
                'type'    => 'textarea',
                'default' => __( 'Financia tu compra sin tarjeta de crédito.', 'ltms' ),
            ],
            'testmode'      => [
                'title'   => __( 'Modo sandbox', 'ltms' ),
                'type'    => 'checkbox',
                'label'   => __( 'Activar sandbox', 'ltms' ),
                'default' => 'yes',
            ],
            'client_id'     => [
                'title'   => __( 'Client ID', 'ltms' ),
                'type'    => 'text',
                'default' => '',
            ],
            'client_secret' => [
                'title'   => __( 'Client Secret', 'ltms' ),
                'type'    => 'password',
                'default' => '',
            ],
            'webhook_token' => [
                'title'       => __( 'Webhook Token', 'ltms' ),
                'type'        => 'text',
                'description' => __( 'Token para verificar los webhooks de Addi.', 'ltms' ),
                'default'     => wp_generate_password( 32, false ),
            ],
        ];
    }

    /**
     * Procesa el pago: crea la sesión de Addi y redirige al cliente.
     *
     * @param int $order_id
     * @return array
     */
    public function process_payment( $order_id ): array {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wc_add_notice( __( 'Pedido no encontrado.', 'ltms' ), 'error' );
            return [ 'result' => 'failure', 'redirect' => '' ];
        }

        try {
            if ( ! class_exists( 'LTMS_Api_Addi' ) ) {
                throw new \RuntimeException( __( 'Módulo Addi no disponible.', 'ltms' ) );
            }

            $client  = LTMS_Api_Factory::get( 'addi' );
            $session = $client->create_application( [
                'orderId'     => $order_id,
                'totalAmount' => [ 'value' => (float) $order->get_total(), 'currency' => get_woocommerce_currency() ],
                'client'      => [
                    'firstName' => $order->get_billing_first_name(),
                    'lastName'  => $order->get_billing_last_name(),
                    'email'     => $order->get_billing_email(),
                    'cellphone' => $order->get_billing_phone(),
                ],
                'redirectUrl' => $this->get_return_url( $order ),
                'webhookUrl'  => rest_url( 'ltms/v1/webhooks/addi' ),
            ] );

            // Marcar como pendiente en WooCommerce
            $order->update_status( 'pending', __( 'Esperando confirmación de Addi.', 'ltms' ) );
            $order->update_meta_data( '_ltms_addi_application_id', $session['applicationId'] ?? '' );
            $order->save();

            return [
                'result'   => 'success',
                'redirect' => $session['url'] ?? $this->get_return_url( $order ),
            ];

        } catch ( \Throwable $e ) {
            wc_add_notice( $e->getMessage(), 'error' );
            return [ 'result' => 'failure', 'redirect' => '' ];
        }
    }
}
