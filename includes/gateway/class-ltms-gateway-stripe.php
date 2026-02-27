<?php
/**
 * LTMS Gateway Stripe - Pasarela de Pago WooCommerce
 *
 * Integra Stripe como pasarela de pago nativa en WooCommerce
 * para Colombia (COP) y México (MXN). Soporta pagos con tarjeta
 * vía Stripe Elements, reembolsos y Stripe Connect para split de pagos.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/gateway
 * @version    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Gateway_Stripe
 *
 * @extends WC_Payment_Gateway
 */
class LTMS_Gateway_Stripe extends WC_Payment_Gateway {

    /**
     * Constructor — registra la pasarela y sus opciones de configuración.
     */
    public function __construct() {
        $this->id                 = 'ltms_stripe';
        $this->icon               = apply_filters( 'ltms_stripe_icon', LTMS_ASSETS_URL . 'images/stripe-badge.svg' );
        $this->has_fields         = true;
        $this->method_title       = __( 'Stripe — LT Marketplace', 'ltms' );
        $this->method_description = __( 'Pago con tarjeta vía Stripe para Colombia y México', 'ltms' );
        $this->supports           = [ 'products', 'refunds' ];

        // Cargar configuración guardada en la BD.
        $this->init_form_fields();
        $this->init_settings();

        // Título visible en el checkout.
        $this->title       = $this->get_option( 'title', __( 'Tarjeta de crédito / débito (Stripe)', 'ltms' ) );
        $this->description = $this->get_option( 'description', __( 'Paga de forma segura con tu tarjeta mediante Stripe.', 'ltms' ) );
        $this->enabled     = $this->get_option( 'enabled', 'yes' );

        // Hook para guardar la configuración desde la pantalla de admin.
        add_action(
            'woocommerce_update_options_payment_gateways_' . $this->id,
            [ $this, 'process_admin_options' ]
        );

        // Encolar Stripe.js y el JS propio en el checkout.
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_checkout_scripts' ] );
    }

    /**
     * Define los campos de configuración del panel de administración.
     *
     * @return void
     */
    public function init_form_fields(): void {
        $this->form_fields = [
            'enabled' => [
                'title'   => __( 'Habilitar/Deshabilitar', 'ltms' ),
                'type'    => 'checkbox',
                'label'   => __( 'Habilitar Stripe — LT Marketplace', 'ltms' ),
                'default' => 'yes',
            ],
            'title' => [
                'title'       => __( 'Título', 'ltms' ),
                'type'        => 'text',
                'description' => __( 'Título que ve el cliente en el checkout.', 'ltms' ),
                'default'     => __( 'Tarjeta de crédito / débito (Stripe)', 'ltms' ),
                'desc_tip'    => true,
            ],
            'description' => [
                'title'   => __( 'Descripción', 'ltms' ),
                'type'    => 'textarea',
                'default' => __( 'Paga de forma segura con tu tarjeta mediante Stripe.', 'ltms' ),
            ],
            'testmode' => [
                'title'       => __( 'Modo Sandbox', 'ltms' ),
                'type'        => 'checkbox',
                'label'       => __( 'Activar modo de pruebas (sandbox)', 'ltms' ),
                'description' => __( 'En modo sandbox se usan las claves de prueba de Stripe.', 'ltms' ),
                'default'     => 'yes',
                'desc_tip'    => true,
            ],
            // ── Claves de SANDBOX ───────────────────────────────────────────
            'publishable_key' => [
                'title'       => __( 'Clave Publicable (Sandbox)', 'ltms' ),
                'type'        => 'text',
                'description' => __( 'Clave pk_test_... de tu cuenta Stripe.', 'ltms' ),
                'default'     => '',
                'desc_tip'    => true,
            ],
            'secret_key' => [
                'title'       => __( 'Clave Secreta (Sandbox)', 'ltms' ),
                'type'        => 'password',
                'description' => __( 'Clave sk_test_... de tu cuenta Stripe. Nunca la compartas.', 'ltms' ),
                'default'     => '',
                'desc_tip'    => true,
            ],
            // ── Claves de PRODUCCIÓN / LIVE ─────────────────────────────────
            'publishable_key_live' => [
                'title'       => __( 'Clave Publicable (Live)', 'ltms' ),
                'type'        => 'text',
                'description' => __( 'Clave pk_live_... de tu cuenta Stripe.', 'ltms' ),
                'default'     => '',
                'desc_tip'    => true,
            ],
            'secret_key_live' => [
                'title'       => __( 'Clave Secreta (Live)', 'ltms' ),
                'type'        => 'password',
                'description' => __( 'Clave sk_live_... de tu cuenta Stripe. Nunca la compartas.', 'ltms' ),
                'default'     => '',
                'desc_tip'    => true,
            ],
            // ── Webhooks ────────────────────────────────────────────────────
            'webhook_secret' => [
                'title'       => __( 'Webhook Secret', 'ltms' ),
                'type'        => 'password',
                'description' => sprintf(
                    /* translators: %s: webhook URL */
                    __( 'Secreto whsec_... de tu endpoint de webhook en Stripe. URL: %s', 'ltms' ),
                    '<code>' . rest_url( 'ltms/v1/webhooks/stripe' ) . '</code>'
                ),
                'default'     => '',
            ],
            // ── Stripe Connect ───────────────────────────────────────────────
            'enable_connect' => [
                'title'       => __( 'Stripe Connect', 'ltms' ),
                'type'        => 'checkbox',
                'label'       => __( 'Habilitar Stripe Connect para split automático de pagos a vendedores', 'ltms' ),
                'default'     => 'no',
                'desc_tip'    => true,
                'description' => __( 'Requiere que los vendedores tengan una cuenta Connect vinculada.', 'ltms' ),
            ],
            'connect_account_id' => [
                'title'       => __( 'ID Cuenta Connect Principal', 'ltms' ),
                'type'        => 'text',
                'description' => __( 'ID de la cuenta Stripe Connect de la plataforma (acct_...). Solo si se usa Connect directo.', 'ltms' ),
                'default'     => '',
                'desc_tip'    => true,
            ],
            // ── País ────────────────────────────────────────────────────────
            'enable_co' => [
                'title'   => __( 'Habilitar para Colombia', 'ltms' ),
                'type'    => 'checkbox',
                'label'   => __( 'Aceptar pagos en COP (Colombia)', 'ltms' ),
                'default' => 'yes',
            ],
            'enable_mx' => [
                'title'   => __( 'Habilitar para México', 'ltms' ),
                'type'    => 'checkbox',
                'label'   => __( 'Aceptar pagos en MXN (México)', 'ltms' ),
                'default' => 'yes',
            ],
        ];
    }

    /**
     * Renderiza el formulario de pago en el checkout (Stripe Elements).
     *
     * @return void
     */
    public function payment_fields(): void {
        if ( $this->description ) {
            echo '<p>' . esc_html( $this->description ) . '</p>';
        }

        echo '<fieldset id="ltms-stripe-fields" style="border:0;padding:0;margin:0;">';
        echo '<div id="ltms-stripe-card-element" style="padding:10px;border:1px solid #ccc;border-radius:4px;background:#fff;"></div>';
        echo '<div id="ltms-stripe-card-errors" role="alert" style="color:#fa755a;margin-top:8px;font-size:13px;"></div>';
        echo '<input type="hidden" name="_ltms_stripe_payment_method" id="_ltms_stripe_payment_method" value="" />';
        echo '</fieldset>';
    }

    /**
     * Valida los campos antes de procesar el pago.
     *
     * @return bool
     */
    public function validate_fields(): bool {
        $payment_method = isset( $_POST['_ltms_stripe_payment_method'] )
            ? sanitize_text_field( wp_unslash( $_POST['_ltms_stripe_payment_method'] ) )
            : '';

        if ( empty( $payment_method ) ) {
            wc_add_notice(
                __( 'Por favor completa los datos de tu tarjeta para continuar.', 'ltms' ),
                'error'
            );
            return false;
        }

        return true;
    }

    /**
     * Procesa el pago cuando el cliente confirma el pedido.
     *
     * @param int $order_id ID del pedido de WooCommerce.
     * @return array{result: string, redirect?: string}
     */
    public function process_payment( $order_id ): array {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            wc_add_notice( __( 'Pedido no encontrado.', 'ltms' ), 'error' );
            return [ 'result' => 'fail' ];
        }

        $payment_method_id = isset( $_POST['_ltms_stripe_payment_method'] )
            ? sanitize_text_field( wp_unslash( $_POST['_ltms_stripe_payment_method'] ) )
            : '';

        if ( empty( $payment_method_id ) ) {
            wc_add_notice( __( 'No se recibieron los datos de la tarjeta. Por favor intenta de nuevo.', 'ltms' ), 'error' );
            return [ 'result' => 'fail' ];
        }

        $stripe  = $this->get_stripe_client();
        $amount  = (float) $order->get_total();
        $currency = strtolower( get_woocommerce_currency() );

        // Metadatos para trazabilidad.
        $metadata = [
            'order_id'   => (string) $order_id,
            'order_key'  => $order->get_order_key(),
            'customer'   => $order->get_billing_email(),
            'site_url'   => get_site_url(),
            'plugin'     => 'ltms',
        ];

        $result = $stripe->create_payment_intent( $amount, $currency, $order->get_billing_email(), $metadata );

        if ( ! $result['success'] ) {
            $error = $result['error'] ?? __( 'Error desconocido al crear el pago.', 'ltms' );
            LTMS_Core_Logger::error(
                'STRIPE_PROCESS_PAYMENT_FAIL',
                $error,
                [ 'order_id' => $order_id ]
            );
            wc_add_notice( esc_html( $error ), 'error' );
            return [ 'result' => 'fail' ];
        }

        $intent    = $result['data'];
        $intent_id = $intent['id'] ?? '';

        // Guardar el ID del PaymentIntent en el pedido para webhooks y reembolsos.
        $order->update_meta_data( '_ltms_stripe_payment_intent_id', $intent_id );
        $order->update_meta_data( '_ltms_stripe_payment_method_id', $payment_method_id );
        $order->save();

        // Marcar el pedido como pagado.
        $order->payment_complete( $intent_id );
        $order->add_order_note(
            sprintf(
                /* translators: %s: Stripe PaymentIntent ID */
                __( 'Pago procesado correctamente vía Stripe. PaymentIntent: %s', 'ltms' ),
                $intent_id
            )
        );

        // Reducir stock.
        wc_reduce_stock_levels( $order_id );

        // Vaciar el carrito.
        WC()->cart->empty_cart();

        return [
            'result'   => 'success',
            'redirect' => $order->get_checkout_order_received_url(),
        ];
    }

    /**
     * Procesa un reembolso desde el panel de administración de WooCommerce.
     *
     * @param int        $order_id ID del pedido.
     * @param float|null $amount   Monto a reembolsar (null = reembolso total).
     * @param string     $reason   Motivo del reembolso.
     * @return bool|WP_Error
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return new WP_Error( 'ltms_stripe_refund', __( 'Pedido no encontrado.', 'ltms' ) );
        }

        $intent_id = $order->get_meta( '_ltms_stripe_payment_intent_id', true );

        if ( empty( $intent_id ) ) {
            return new WP_Error(
                'ltms_stripe_refund',
                __( 'No se encontró el PaymentIntent de Stripe para este pedido.', 'ltms' )
            );
        }

        $refund_amount = $amount ?? (float) $order->get_total();
        $stripe        = $this->get_stripe_client();

        // Mapear motivo al formato aceptado por Stripe.
        $stripe_reason = 'requested_by_customer';
        if ( str_contains( strtolower( $reason ), 'fraud' ) || str_contains( strtolower( $reason ), 'fraude' ) ) {
            $stripe_reason = 'fraudulent';
        } elseif ( str_contains( strtolower( $reason ), 'duplic' ) ) {
            $stripe_reason = 'duplicate';
        }

        $result = $stripe->create_refund( $intent_id, $refund_amount, $stripe_reason );

        if ( ! $result['success'] ) {
            $error = $result['error'] ?? 'Error desconocido';
            LTMS_Core_Logger::error(
                'STRIPE_REFUND_FAIL',
                $error,
                [ 'order_id' => $order_id, 'intent_id' => $intent_id ]
            );
            return new WP_Error( 'ltms_stripe_refund', esc_html( $error ) );
        }

        $refund_id = $result['data']['id'] ?? 'unknown';
        $order->add_order_note(
            sprintf(
                /* translators: 1: refund ID, 2: amount, 3: reason */
                __( 'Reembolso procesado vía Stripe. ID: %1$s | Monto: %2$s | Motivo: %3$s', 'ltms' ),
                $refund_id,
                wc_price( $refund_amount ),
                $reason ?: __( 'No especificado', 'ltms' )
            )
        );

        return true;
    }

    /**
     * Encola Stripe.js y el script propio de LTMS Stripe en la página de checkout.
     *
     * @return void
     */
    public function enqueue_checkout_scripts(): void {
        if ( ! is_checkout() ) {
            return;
        }

        if ( $this->enabled !== 'yes' ) {
            return;
        }

        // Stripe.js oficial — DEBE cargarse siempre desde js.stripe.com.
        wp_enqueue_script(
            'stripe-js',
            'https://js.stripe.com/v3/',
            [],
            null, // No versionar: Stripe lo gestiona automáticamente.
            true
        );

        // Script propio de LTMS para montar Stripe Elements.
        wp_enqueue_script(
            'ltms-stripe',
            LTMS_ASSETS_URL . 'js/ltms-stripe.js',
            [ 'jquery', 'stripe-js' ],
            defined( 'LTMS_VERSION' ) ? LTMS_VERSION : '1.5.0',
            true
        );

        wp_localize_script( 'ltms-stripe', 'ltmsStripe', [
            'publishable_key' => $this->get_publishable_key(),
            'is_live'         => ! $this->is_testmode(),
            'i18n'            => [
                'card_error'  => __( 'Error en los datos de la tarjeta.', 'ltms' ),
                'processing'  => __( 'Procesando pago...', 'ltms' ),
            ],
        ] );
    }

    /**
     * Devuelve una instancia configurada de LTMS_Api_Stripe según el modo activo.
     *
     * @return LTMS_Api_Stripe
     */
    private function get_stripe_client(): LTMS_Api_Stripe {
        $secret_key = $this->is_testmode()
            ? $this->get_option( 'secret_key', '' )
            : $this->get_option( 'secret_key_live', '' );

        return new LTMS_Api_Stripe( $secret_key, ! $this->is_testmode() );
    }

    /**
     * Devuelve la clave publicable correcta según el modo activo.
     *
     * @return string
     */
    private function get_publishable_key(): string {
        return $this->is_testmode()
            ? $this->get_option( 'publishable_key', '' )
            : $this->get_option( 'publishable_key_live', '' );
    }

    /**
     * Indica si la pasarela está en modo sandbox/test.
     *
     * @return bool
     */
    private function is_testmode(): bool {
        return $this->get_option( 'testmode', 'yes' ) === 'yes';
    }
}
