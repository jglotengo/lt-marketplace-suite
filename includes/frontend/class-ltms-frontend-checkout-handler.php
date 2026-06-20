<?php
/**
 * LTMS Frontend Checkout Handler — Controlador AJAX del Checkout
 *
 * Expone los endpoints AJAX que el JS del checkout necesita:
 *  - ltms_process_checkout : procesa el pago (tarjeta Openpay, PSE, OXXO, Nequi, Addi)
 *  - ltms_get_pse_banks    : devuelve la lista de bancos PSE disponibles
 *
 * Bug corregido (M-14): el JS ltms-checkout.js llamaba estas dos acciones AJAX
 * pero no existía ningún handler PHP registrado — WordPress devolvía 0/-1 en cada
 * intento de pago, haciendo el checkout completamente no funcional.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/frontend
 * @version    1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Frontend_Checkout_Handler
 */
final class LTMS_Frontend_Checkout_Handler {

    use LTMS_Logger_Aware;

    /**
     * Bancos PSE disponibles en Colombia (códigos Openpay).
     * Fuente: https://www.openpay.co/recursos/bancos-pse/
     *
     * @var array<string,string>
     */
    private const PSE_BANKS_CO = [
        '1006' => 'Banco Agrario',
        '1283' => 'CFA Cooperativa Financiera',
        '1052' => 'Banco AV Villas',
        '1032' => 'Banco Caja Social',
        '1019' => 'SCOTIABANK COLPATRIA',
        '1066' => 'Banco Cooperativo Coopcentral',
        '1051' => 'Banco Davivienda',
        '1001' => 'Banco de Bogotá',
        '1023' => 'Banco de Occidente',
        '1062' => 'Banco Falabella',
        '1069' => 'Banco Finandina',
        '1012' => 'Banco GNB Sudameris',
        '1060' => 'Banco Pichincha',
        '1002' => 'Banco Popular',
        '1065' => 'Banco Santander',
        '1007' => 'Bancolombia',
        '1013' => 'BBVA Colombia',
        '1009' => 'Citibank',
        '1370' => 'Confiar Cooperativa Financiera',
        '1292' => 'Coofinep Cooperativa Financiera',
        '1289' => 'Cotrafa',
        '1097' => 'Dale',
        '1551' => 'DaviPlata',
        '1303' => 'Financiera Juriscoop',
        '1637' => 'IRIS',
        '1022' => 'Bancolombia (PSE)',
        '1507' => 'Nequi',
        '1059' => 'Banco Pichincha',
        '1049' => 'Itaú',
    ];

    /**
     * Registra los hooks AJAX del handler.
     *
     * @return void
     */
    public static function init(): void {
        // L-3: consentimiento de datos personales en checkout (Ley 1581/2012)
        add_action( 'woocommerce_review_order_before_submit',  [ __CLASS__, 'add_privacy_consent_field' ] );
        add_action( 'woocommerce_checkout_process',            [ __CLASS__, 'validate_privacy_consent' ] );
        add_action( 'woocommerce_checkout_order_created',      [ __CLASS__, 'save_privacy_consent' ] );

        // FIX CHECKOUT-01: LTMS ya tiene su propio checkbox de consentimiento (Ley 1581).
        // Eliminamos los checkboxes nativos de WooCommerce para evitar duplicados.
        add_action( 'woocommerce_checkout_terms_and_conditions', [ __CLASS__, 'remove_wc_native_checkboxes' ], 1 );
        $instance = new self();

        // P-02: Validar que el carrito solo tenga productos de un mismo vendedor.
        // Evita que el método de pickup muestre la dirección incorrecta y que
        // las comisiones se asignen al vendedor equivocado en pedidos mixtos.
        add_filter( 'woocommerce_add_to_cart_validation', [ __CLASS__, 'validate_single_vendor_cart' ], 10, 2 );

        // Proceso de pago — solo usuarios logueados
        add_action( 'wp_ajax_ltms_process_checkout',        [ $instance, 'ajax_process_checkout' ] );

        // Lista de bancos PSE — también disponible para no logueados (el checkout puede ser guest)
        add_action( 'wp_ajax_ltms_get_pse_banks',           [ $instance, 'ajax_get_pse_banks' ] );
        add_action( 'wp_ajax_nopriv_ltms_get_pse_banks',    [ $instance, 'ajax_get_pse_banks' ] );

        // Checkout para usuarios no logueados (compra como invitado)
        add_action( 'wp_ajax_nopriv_ltms_process_checkout', [ $instance, 'ajax_process_checkout' ] );
    }

    // =========================================================================
    // AJAX: ltms_get_pse_banks
    // =========================================================================

    /**
     * Devuelve la lista de bancos PSE para Colombia.
     *
     * Respuesta JSON:
     * {
     *   success: true,
     *   data: {
     *     banks: [ { id: string, name: string }, ... ]
     *   }
     * }
     *
     * @return void
     */
    public function ajax_get_pse_banks(): void {
        // Verificar nonce (opcional en este endpoint pero buena práctica)
        check_ajax_referer( 'ltms_checkout_nonce', 'nonce', false );

        $country = LTMS_Core_Config::get_country();

        if ( 'CO' !== $country ) {
            wp_send_json_error( __( 'PSE solo disponible en Colombia.', 'ltms' ), 400 );
        }

        $banks = [];
        foreach ( self::PSE_BANKS_CO as $id => $name ) {
            $banks[] = [ 'id' => $id, 'name' => $name ];
        }

        // Ordenar alfabéticamente por nombre
        usort( $banks, fn( $a, $b ) => strcmp( $a['name'], $b['name'] ) );

        wp_send_json_success( [ 'banks' => $banks ] );
    }

    // =========================================================================
    // AJAX: ltms_process_checkout
    // =========================================================================

    /**
     * Procesa el pago del checkout.
     *
     * Parámetros POST esperados (enviados por ltms-checkout.js):
     *  - nonce          : ltms_checkout_nonce
     *  - payment_method : card | pse | oxxo | addi | nequi | daviplata
     *  - order_id       : ID del pedido WooCommerce (si ya existe) o se crea nuevo
     *  - billing_*      : campos de facturación del formulario
     *
     * Para tarjeta:
     *  - openpay_token_id, device_session_id, card_type, card_brand, installments
     *
     * Para PSE:
     *  - bank_code, person_type, document_number, document_type, redirect_url
     *
     * Para Nequi / Daviplata:
     *  - phone
     *
     * @return void
     */
    public function ajax_process_checkout(): void {
        // ── 1. Verificar nonce ────────────────────────────────────────────────
        if ( ! check_ajax_referer( 'ltms_checkout_nonce', 'nonce', false ) ) {
            wp_send_json_error( __( 'Sesión expirada. Recarga la página.', 'ltms' ), 403 );
        }

        // ── 2. Obtener y sanitizar datos del POST ─────────────────────────────
        $payment_method = sanitize_key( $_POST['payment_method'] ?? '' );
        $order_id       = absint( $_POST['order_id'] ?? 0 );

        // L-3 FIX: Registrar consentimiento explícito del checkout.
        // Ley 1480/2011, art. 51 — confirmación de compra con términos claros.
        // Ley 1581/2012 — tratamiento de datos del comprador requiere consentimiento registrado.
        if ( class_exists( 'LTMS_Legal_Compliance' ) && $order_id ) {
            LTMS_Legal_Compliance::save_checkout_consent(
                get_current_user_id(),
                $order_id,
                ! empty( $_POST['terms_consent'] ) // phpcs:ignore
            );
        }

        if ( empty( $payment_method ) ) {
            wp_send_json_error( __( 'Método de pago no especificado.', 'ltms' ), 400 );
        }

        // ── 3. Obtener el pedido WooCommerce ──────────────────────────────────
        $order = $order_id ? wc_get_order( $order_id ) : null;

        if ( ! $order ) {
            // Intentar obtener el pedido de la sesión de WooCommerce
            $order = $this->get_or_create_order_from_session();
        }

        if ( ! $order ) {
            wp_send_json_error( __( 'No se encontró el pedido. Intenta de nuevo.', 'ltms' ), 404 );
        }

        // ── 4. Verificar que el pedido no esté ya pagado ──────────────────────
        if ( $order->is_paid() ) {
            wp_send_json_success( [
                'message'  => __( 'Este pedido ya fue pagado.', 'ltms' ),
                'redirect' => $order->get_checkout_order_received_url(),
            ] );
        }

        // ── 4b. M-105: Verificar propiedad del pedido ─────────────────────────
        // Si el order_id fue enviado explícitamente en POST, validar que pertenece
        // al usuario actual o a la sesión de WooCommerce activa.
        if ( $order_id ) {
            $current_user_id   = get_current_user_id();
            $order_customer_id = (int) $order->get_customer_id();
            $session_order_ids = WC()->session ? (array) WC()->session->get( 'order_awaiting_payment' ) : [];

            $is_owner = ( $current_user_id && $current_user_id === $order_customer_id )
                        || in_array( $order->get_id(), $session_order_ids, true );

            if ( ! $is_owner ) {
                wp_send_json_error( __( 'No tienes permiso para pagar este pedido.', 'ltms' ), 403 );
            }
        }

        // ── 5. Preparar datos del cliente para la pasarela ───────────────────
        $customer = [
            'name'         => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
            'email'        => $order->get_billing_email(),
            'phone_number' => $order->get_billing_phone(),
            'city'         => $order->get_billing_city(),
        ];

        $amount      = (float) $order->get_total();
        $currency    = get_woocommerce_currency();
        $order_ref   = 'LTMS-' . $order->get_id() . '-' . time();
        $description = sprintf(
            __( 'Pedido #%d en Lo Tengo', 'ltms' ),
            $order->get_id()
        );

        // ── 6. Despachar al método de pago correcto ───────────────────────────
        try {
            switch ( $payment_method ) {
                case 'card':
                    $result = $this->process_card_payment( $order, $customer, $amount, $currency, $description, $order_ref );
                    break;

                case 'pse':
                    $result = $this->process_pse_payment( $order, $customer, $amount, $description, $order_ref );
                    break;

                case 'oxxo':
                    $result = $this->process_oxxo_payment( $order, $customer, $amount, $description, $order_ref );
                    break;

                case 'addi':
                    $result = $this->process_addi_payment( $order, $customer, $amount, $currency );
                    break;

                case 'nequi':
                case 'daviplata':
                    $result = $this->process_mobile_payment( $order, $customer, $amount, $description, $order_ref, $payment_method );
                    break;

                default:
                    wp_send_json_error(
                        sprintf( __( 'Método de pago no soportado: %s', 'ltms' ), esc_html( $payment_method ) ),
                        400
                    );
                    return;
            }

            // ── 7. Procesar resultado ─────────────────────────────────────────
            if ( $result['success'] ) {
                // Marcar pedido como procesando si hay transaction_id
                if ( ! empty( $result['transaction_id'] ) ) {
                    $order->payment_complete( $result['transaction_id'] );
                    $order->add_order_note(
                        sprintf(
                            __( 'Pago exitoso via %s. Transacción: %s', 'ltms' ),
                            strtoupper( $result['gateway_used'] ?? $payment_method ),
                            $result['transaction_id']
                        )
                    );
                }

                // Respuesta para redirect (PSE, Addi)
                if ( ! empty( $result['redirect'] ) ) {
                    wp_send_json_success( [
                        'redirect' => $result['redirect'],
                        'message'  => $result['message'] ?? __( 'Redirigiendo al banco...', 'ltms' ),
                    ] );
                }

                // Respuesta para OXXO (referencia de pago)
                if ( ! empty( $result['reference'] ) ) {
                    wp_send_json_success( [
                        'reference'   => $result['reference'],
                        'barcode_url' => $result['barcode_url'] ?? '',
                        'message'     => __( 'Presenta esta referencia en cualquier OXXO para completar tu pago.', 'ltms' ),
                        'redirect'    => $order->get_checkout_order_received_url(),
                    ] );
                }

                // Pago completado directo
                wp_send_json_success( [
                    'message'  => __( '¡Pago exitoso! Gracias por tu compra.', 'ltms' ),
                    'redirect' => $order->get_checkout_order_received_url(),
                ] );

            } else {
                $order->add_order_note(
                    sprintf(
                        __( 'Pago fallido via %s: %s', 'ltms' ),
                        strtoupper( $payment_method ),
                        $result['error'] ?? 'Error desconocido'
                    )
                );
                wp_send_json_error( $result['error'] ?? __( 'Error al procesar el pago. Intenta de nuevo.', 'ltms' ) );
            }

        } catch ( \Throwable $e ) {
            $this->log_error( 'CHECKOUT_HANDLER_EXCEPTION', $e->getMessage(), [
                'order_id'       => $order->get_id(),
                'payment_method' => $payment_method,
            ] );

            $order->add_order_note(
                sprintf( __( 'Error en checkout LTMS: %s', 'ltms' ), $e->getMessage() )
            );

            wp_send_json_error( __( 'Error interno. Por favor intenta de nuevo o contacta soporte.', 'ltms' ) );
        }
    }

    // =========================================================================
    // Métodos privados de procesamiento por pasarela
    // =========================================================================

    /**
     * Procesa pago con tarjeta de crédito/débito vía Openpay.
     *
     * @param WC_Order $order       Pedido WooCommerce.
     * @param array    $customer    Datos del cliente.
     * @param float    $amount      Monto total.
     * @param string   $currency    Código de moneda.
     * @param string   $description Descripción del cobro.
     * @param string   $order_ref   Referencia única del pedido.
     * @return array
     */
    private function process_card_payment(
        WC_Order $order,
        array    $customer,
        float    $amount,
        string   $currency,
        string   $description,
        string   $order_ref
    ): array {
        $token_id         = sanitize_text_field( $_POST['openpay_token_id'] ?? '' );
        $device_session   = sanitize_text_field( $_POST['device_session_id'] ?? '' );
        $installments     = max( 1, absint( $_POST['installments'] ?? 1 ) );

        if ( empty( $token_id ) ) {
            return [ 'success' => false, 'error' => __( 'Token de tarjeta no recibido.', 'ltms' ) ];
        }

        // Intentar con LTMS_Payment_Orchestrator (tiene fallback automático Stripe ↔ Openpay)
        if ( class_exists( 'LTMS_Payment_Orchestrator' ) ) {
            $payment_type = $currency === 'MXN' ? 'card_local' : 'card_intl';

            // Determinar si es tarjeta local según el brand
            $card_brand = sanitize_text_field( $_POST['card_brand'] ?? '' );
            if ( in_array( $card_brand, [ 'visa', 'mastercard' ], true ) && $currency === 'COP' ) {
                $payment_type = 'card_local';
            }

            return LTMS_Payment_Orchestrator::process_with_fallback(
                $amount,
                $currency,
                $payment_type,
                [
                    'token_id'       => $token_id,
                    'device_session' => $device_session,
                    'installments'   => $installments,
                    'customer'       => $customer,
                    'description'    => $description,
                    'order_ref'      => $order_ref,
                ],
                $order
            );
        }

        // Fallback directo a Openpay si el orquestador no está disponible
        $openpay = new LTMS_Api_Openpay();
        $charge  = $openpay->create_charge(
            $token_id,
            $amount,
            $description,
            $customer,
            $order_ref,
            $device_session,
            true
        );

        return [
            'success'        => isset( $charge['id'] ) && in_array( $charge['status'] ?? '', [ 'completed', 'in_progress' ], true ),
            'transaction_id' => $charge['id'] ?? '',
            'gateway_used'   => 'openpay',
            'error'          => $charge['error_message'] ?? __( 'Error al procesar la tarjeta.', 'ltms' ),
        ];
    }

    /**
     * Procesa pago vía PSE (Colombia).
     *
     * @param WC_Order $order       Pedido WooCommerce.
     * @param array    $customer    Datos del cliente.
     * @param float    $amount      Monto total.
     * @param string   $description Descripción.
     * @param string   $order_ref   Referencia.
     * @return array
     */
    private function process_pse_payment(
        WC_Order $order,
        array    $customer,
        float    $amount,
        string   $description,
        string   $order_ref
    ): array {
        $bank_code    = sanitize_text_field( $_POST['bank_code'] ?? '' );
        $redirect_url = esc_url_raw( $_POST['redirect_url'] ?? $order->get_checkout_order_received_url() );

        if ( empty( $bank_code ) ) {
            return [ 'success' => false, 'error' => __( 'Selecciona tu banco para PSE.', 'ltms' ) ];
        }

        // Enriquecer datos del cliente con info de PSE
        $customer['document_number'] = sanitize_text_field( $_POST['document_number'] ?? '' );
        $customer['document_type']   = sanitize_text_field( $_POST['document_type'] ?? 'CC' );
        $customer['person_type']     = sanitize_text_field( $_POST['person_type'] ?? 'natural' );

        $openpay = new LTMS_Api_Openpay();
        $charge  = $openpay->create_pse_charge(
            $amount,
            $description,
            $customer,
            $bank_code,
            $redirect_url,
            $order_ref
        );

        // PSE devuelve una URL de redirección al banco
        $payment_method_data = $charge['payment_method'] ?? [];
        $bank_url            = $payment_method_data['url'] ?? '';

        if ( empty( $bank_url ) ) {
            return [
                'success' => false,
                'error'   => __( 'No se pudo iniciar la sesión PSE. Intenta de nuevo.', 'ltms' ),
            ];
        }

        // Guardar el charge_id en el pedido para verificación posterior
        $order->update_meta_data( '_ltms_pse_charge_id', $charge['id'] ?? '' );
        $order->update_meta_data( '_ltms_pse_bank_code', $bank_code );
        $order->update_status( 'pending', __( 'Esperando confirmación PSE del banco.', 'ltms' ) );
        $order->save();

        return [
            'success'  => true,
            'redirect' => $bank_url,
            'message'  => __( 'Redirigiendo a tu banco para completar el pago...', 'ltms' ),
        ];
    }

    /**
     * Procesa pago vía OXXO (México).
     *
     * @param WC_Order $order       Pedido WooCommerce.
     * @param array    $customer    Datos del cliente.
     * @param float    $amount      Monto total.
     * @param string   $description Descripción.
     * @param string   $order_ref   Referencia.
     * @return array
     */
    private function process_oxxo_payment(
        WC_Order $order,
        array    $customer,
        float    $amount,
        string   $description,
        string   $order_ref
    ): array {
        $openpay = new LTMS_Api_Openpay();
        $charge  = $openpay->create_oxxo_charge( $amount, $description, $customer, $order_ref );

        $reference   = $charge['payment_method']['reference'] ?? '';
        $barcode_url = $charge['payment_method']['barcode_url'] ?? '';

        if ( empty( $reference ) ) {
            return [
                'success' => false,
                'error'   => __( 'No se pudo generar la referencia OXXO. Intenta de nuevo.', 'ltms' ),
            ];
        }

        $order->update_meta_data( '_ltms_oxxo_reference', $reference );
        $order->update_meta_data( '_ltms_oxxo_charge_id', $charge['id'] ?? '' );
        $order->update_status( 'pending', sprintf( __( 'Esperando pago en OXXO. Referencia: %s', 'ltms' ), $reference ) );
        $order->save();

        return [
            'success'     => true,
            'reference'   => $reference,
            'barcode_url' => $barcode_url,
        ];
    }

    /**
     * Procesa pago vía Addi BNPL.
     *
     * @param WC_Order $order    Pedido WooCommerce.
     * @param array    $customer Datos del cliente.
     * @param float    $amount   Monto total.
     * @param string   $currency Moneda.
     * @return array
     */
    private function process_addi_payment(
        WC_Order $order,
        array    $customer,
        float    $amount,
        string   $currency
    ): array {
        if ( ! class_exists( 'LTMS_Api_Addi' ) ) {
            return [ 'success' => false, 'error' => __( 'Addi no disponible.', 'ltms' ) ];
        }

        $addi   = new LTMS_Api_Addi();
        $result = $addi->create_application( [
            'order_id'           => (string) $order->get_id(),
            'amount'             => $amount,
            'currency'           => $currency,
            'items'              => $this->build_addi_items( $order ),
            'client'             => [
                'first_name' => $order->get_billing_first_name(),
                'last_name'  => $order->get_billing_last_name(),
                'email'      => $order->get_billing_email(),
                'phone'      => $order->get_billing_phone(),
                'document'   => sanitize_text_field( $_POST['document_number'] ?? '' ),
            ],
            'callback_approved'  => add_query_arg( [ 'addi_status' => 'approved', 'order_id' => $order->get_id() ], $order->get_checkout_order_received_url() ),
            'callback_rejected'  => add_query_arg( [ 'addi_status' => 'rejected' ], wc_get_checkout_url() ),
            'callback_cancelled' => add_query_arg( [ 'addi_status' => 'cancelled' ], wc_get_checkout_url() ),
        ] );

        if ( empty( $result['checkout_url'] ) ) {
            return [ 'success' => false, 'error' => __( 'No se pudo iniciar el flujo Addi. Intenta de nuevo.', 'ltms' ) ];
        }

        $order->update_meta_data( '_ltms_addi_application_id', $result['application_id'] ?? '' );
        $order->update_status( 'pending', __( 'Esperando aprobación Addi BNPL.', 'ltms' ) );
        $order->save();

        return [
            'success'  => true,
            'redirect' => $result['checkout_url'],
            'message'  => __( 'Redirigiendo a Addi para financiar tu compra...', 'ltms' ),
        ];
    }

    /**
     * Procesa pago vía Nequi o Daviplata.
     *
     * @param WC_Order $order          Pedido WooCommerce.
     * @param array    $customer       Datos del cliente.
     * @param float    $amount         Monto total.
     * @param string   $description    Descripción.
     * @param string   $order_ref      Referencia.
     * @param string   $payment_method 'nequi' o 'daviplata'.
     * @return array
     */
    private function process_mobile_payment(
        WC_Order $order,
        array    $customer,
        float    $amount,
        string   $description,
        string   $order_ref,
        string   $payment_method
    ): array {
        $phone = sanitize_text_field( $_POST['phone'] ?? '' );

        if ( empty( $phone ) ) {
            return [
                'success' => false,
                'error'   => sprintf( __( 'Ingresa el número de teléfono para %s.', 'ltms' ), ucfirst( $payment_method ) ),
            ];
        }

        // Openpay procesa Nequi y Daviplata como PSE con bank_code especial
        $bank_codes = [
            'nequi'     => '1507',
            'daviplata' => '1551',
        ];

        $bank_code    = $bank_codes[ $payment_method ] ?? '1507';
        $redirect_url = $order->get_checkout_order_received_url();

        // Enriquecer customer con teléfono móvil
        $customer['phone_number'] = $phone;

        $openpay = new LTMS_Api_Openpay();
        $charge  = $openpay->create_pse_charge(
            $amount,
            $description,
            $customer,
            $bank_code,
            $redirect_url,
            $order_ref
        );

        $payment_url = $charge['payment_method']['url'] ?? '';

        if ( empty( $payment_url ) ) {
            return [
                'success' => false,
                'error'   => sprintf( __( 'No se pudo iniciar el pago con %s. Intenta de nuevo.', 'ltms' ), ucfirst( $payment_method ) ),
            ];
        }

        $order->update_meta_data( '_ltms_' . $payment_method . '_charge_id', $charge['id'] ?? '' );
        $order->update_status( 'pending', sprintf( __( 'Esperando confirmación de pago por %s.', 'ltms' ), ucfirst( $payment_method ) ) );
        $order->save();

        return [
            'success'  => true,
            'redirect' => $payment_url,
            'message'  => sprintf( __( 'Completa el pago en tu app de %s.', 'ltms' ), ucfirst( $payment_method ) ),
        ];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Intenta obtener el pedido de la sesión de WooCommerce o crearlo.
     *
     * @return WC_Order|null
     */
    private function get_or_create_order_from_session(): ?WC_Order {
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

    /**
     * Construye el array de items para Addi desde el pedido WooCommerce.
     *
     * @param WC_Order $order Pedido.
     * @return array
     */
    private function build_addi_items( WC_Order $order ): array {
        $items = [];
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            $items[] = [
                'name'     => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'price'    => $product ? (float) $product->get_price() : 0.0,
                'sku'      => $product ? $product->get_sku() : '',
            ];
        }
        return $items;
    }

    /**
     * P-02: Valida que el carrito solo tenga productos del mismo vendedor.
     *
     * Evita que el método de pickup muestre la dirección del vendedor A cuando
     * el carrito también contiene productos del vendedor B, y previene que las
     * comisiones del pedido se asignen incorrectamente a un único vendedor.
     *
     * @param bool $passed   Resultado de validaciones anteriores.
     * @param int  $product_id ID del producto que se intenta agregar.
     * @return bool
     */
    public static function validate_single_vendor_cart( bool $passed, int $product_id ): bool {
        if ( ! $passed || WC()->cart->is_empty() ) {
            return $passed;
        }

        $new_vendor_id = (int) get_post_meta( $product_id, '_ltms_vendor_id', true );
        if ( ! $new_vendor_id ) {
            // Fallback: usar post_author si no tiene meta de vendedor
            $new_vendor_id = (int) get_post_field( 'post_author', $product_id );
        }

        if ( ! $new_vendor_id ) {
            return $passed; // Sin vendedor identificable, no bloquear
        }

        foreach ( WC()->cart->get_cart() as $item ) {
            $pid            = (int) ( $item['product_id'] ?? 0 );
            $cart_vendor_id = (int) get_post_meta( $pid, '_ltms_vendor_id', true );
            if ( ! $cart_vendor_id ) {
                $cart_vendor_id = (int) get_post_field( 'post_author', $pid );
            }

            if ( $cart_vendor_id && $cart_vendor_id !== $new_vendor_id ) {
                $cart_vendor_name = get_user_meta( $cart_vendor_id, 'ltms_store_name', true )
                    ?: get_userdata( $cart_vendor_id )->display_name ?? '';
                $new_vendor_name  = get_user_meta( $new_vendor_id, 'ltms_store_name', true )
                    ?: get_userdata( $new_vendor_id )->display_name ?? '';

                wc_add_notice(
                    sprintf(
                        /* translators: %1$s: nombre tienda en carrito, %2$s: nombre tienda del producto nuevo */
                        __( 'Tu carrito ya tiene productos de <strong>%1$s</strong>. Para agregar productos de <strong>%2$s</strong> debes vaciar el carrito primero.', 'ltms' ),
                        esc_html( $cart_vendor_name ),
                        esc_html( $new_vendor_name )
                    ),
                    'error'
                );
                return false;
            }
        }

        return $passed;
    }

    /**
     * FIX CHECKOUT-01: Eliminar checkboxes nativos de WooCommerce (duplicados con LTMS).
     */
    public static function remove_wc_native_checkboxes(): void {
        remove_action( 'woocommerce_checkout_terms_and_conditions', 'wc_checkout_privacy_policy_text', 20 );
        remove_action( 'woocommerce_checkout_terms_and_conditions', 'wc_terms_and_conditions_page_content', 30 );
    }

    /**
     * L-3: Campo de consentimiento de datos en checkout.
     */
    public static function add_privacy_consent_field(): void {
        $privacy_url = get_privacy_policy_url() ?: get_permalink( get_option( 'ltms_privacy_page_id' ) ) ?: '#';
        $checked     = WC()->session ? WC()->session->get( 'ltms_privacy_consent', false ) : false;
        echo '<p class="form-row ltms-checkout-consent">';
        echo '<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">';
        echo '<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" ';
        echo 'name="ltms_privacy_consent" id="ltms-privacy-consent" value="1"' . checked( $checked, true, false ) . ' required>';
        echo '<span>' . wp_kses_post( sprintf(
            __( 'He leído y acepto la <a href="%s" target="_blank">Política de Tratamiento de Datos Personales</a>. Autorizo el uso de mis datos para gestión del pedido conforme a la Ley 1581/2012. *', 'ltms' ),
            esc_url( $privacy_url )
        ) ) . '</span>';
        echo '</label></p>';
    }

    /**
     * L-3: Validar consentimiento en checkout.
     */
    public static function validate_privacy_consent(): void {
        if ( empty( $_POST['ltms_privacy_consent'] ) ) { // phpcs:ignore
            wc_add_notice( __( 'Debes aceptar la Política de Tratamiento de Datos Personales para continuar.', 'ltms' ), 'error' );
        }
    }

    /**
     * L-3: Guardar consentimiento con timestamp y versión.
     */
    public static function save_privacy_consent( \WC_Order $order ): void {
        if ( ! empty( $_POST['ltms_privacy_consent'] ) ) { // phpcs:ignore
            $order->update_meta_data( '_ltms_privacy_consent', '1' );
            $order->update_meta_data( '_ltms_privacy_consent_date', gmdate( 'Y-m-d H:i:s' ) );
            $order->update_meta_data( '_ltms_privacy_consent_ip', sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ) ); // phpcs:ignore
            $order->save();
        }
    }

} // end class LTMS_Frontend_Checkout_Handler