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
     * Active checkout idempotency lock key (empty when no lock is held).
     * Populated by ajax_process_checkout() and cleared by release_checkout_lock()
     * via a shutdown function so the lock is released on every exit path.
     *
     * @var string
     */
    private $checkout_lock_key = '';

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

        // Task 67-A / UX-CART-1: structured cart contents for the UX cart drawer.
        // Replaces the non-existent woocommerce_get_cart_contents AJAX action.
        add_action( 'wp_ajax_ltms_get_cart',                [ $instance, 'ajax_get_cart' ] );
        add_action( 'wp_ajax_nopriv_ltms_get_cart',         [ $instance, 'ajax_get_cart' ] );

        // Checkout para usuarios no logueados (compra como invitado)
        add_action( 'wp_ajax_nopriv_ltms_process_checkout', [ $instance, 'ajax_process_checkout' ] );

        // v3.1.0 — Cross-Border motor (Task 63-D): currency switcher + customs
        // estimate endpoints. Available to both logged-in and guest checkouts.
        add_action( 'wp_ajax_ltms_change_currency',         [ $instance, 'ajax_change_currency' ] );
        add_action( 'wp_ajax_nopriv_ltms_change_currency',  [ $instance, 'ajax_change_currency' ] );
        add_action( 'wp_ajax_ltms_get_customs_estimate',    [ $instance, 'ajax_get_customs_estimate' ] );
        add_action( 'wp_ajax_nopriv_ltms_get_customs_estimate', [ $instance, 'ajax_get_customs_estimate' ] );

        // Render the currency selector widget above the checkout totals.
        add_action( 'woocommerce_checkout_before_customer_details', [ __CLASS__, 'render_currency_selector' ], 5 );
        add_action( 'woocommerce_checkout_before_order_review',      [ __CLASS__, 'render_customs_estimate' ], 5 );

        // Persist display-currency metadata on the order when it is created.
        add_action( 'woocommerce_checkout_order_created', [ __CLASS__, 'save_currency_meta_on_order' ] );

        // DDP: add duties to the order total via a fee line. Done at checkout
        // calculation time so the buyer sees the inclusive total.
        add_action( 'woocommerce_cart_calculate_fees', [ __CLASS__, 'add_ddp_duties_fee' ], 20 );

        // ───────────────────────────────────────────────────────────────────
        // Task 67-B — UX Enhancements AJAX endpoints + gift-wrapping fee.
        //
        // The ltms-ux-enhancements.js layer posts to these actions from the
        // customer-facing storefront (product pages, cart, checkout, vendor
        // dashboard). They are registered for BOTH logged-in and guest users
        // because most storefront flows are guest-accessible. Each handler
        // verifies the dedicated `ltms_ux_nonce` action and sanitizes input.
        //
        // See class-ltms-frontend-assets.php → enqueue_frontend_assets() for
        // the `window.ltmsUX` bootstrap that exposes `ajax_url` + `nonce`
        // globally so the JS can call these endpoints from any page.
        // ───────────────────────────────────────────────────────────────────

        // UX-FAKE-5 — Gift wrapping fee applied at cart calculation time.
        add_action( 'woocommerce_cart_calculate_fees', [ __CLASS__, 'add_gift_wrapping_fee' ], 30 );

        // a. Product recommendations (related / cross-sell / up-sell / viewed).
        add_action( 'wp_ajax_ltms_get_recommendations',        [ __CLASS__, 'ajax_get_recommendations' ] );
        add_action( 'wp_ajax_nopriv_ltms_get_recommendations', [ __CLASS__, 'ajax_get_recommendations' ] );

        // b. Quick view modal data (formato estructurado: {id,name,price,...}).
        // v2.9.49 PERF: Solo este handler responde; el de LTMS_Quick_View se
        // desactivó para evitar procesamiento doble del AJAX.
        add_action( 'wp_ajax_ltms_quick_view',        [ __CLASS__, 'ajax_quick_view' ] );
        add_action( 'wp_ajax_nopriv_ltms_quick_view', [ __CLASS__, 'ajax_quick_view' ] );

        // c. One-click reorder (UX-FAKE-1).
        add_action( 'wp_ajax_ltms_reorder', [ __CLASS__, 'ajax_reorder' ] );

        // d. Coupon validation (UX-FAKE-6).
        add_action( 'wp_ajax_ltms_validate_coupon',        [ __CLASS__, 'ajax_validate_coupon' ] );
        add_action( 'wp_ajax_nopriv_ltms_validate_coupon', [ __CLASS__, 'ajax_validate_coupon' ] );

        // e. Bundle add-to-cart (UX-FAKE-3).
        add_action( 'wp_ajax_ltms_add_bundle_to_cart',        [ __CLASS__, 'ajax_add_bundle_to_cart' ] );
        add_action( 'wp_ajax_nopriv_ltms_add_bundle_to_cart', [ __CLASS__, 'ajax_add_bundle_to_cart' ] );

        // f. Subscription toggle (UX-FAKE-4).
        add_action( 'wp_ajax_ltms_toggle_subscription',        [ __CLASS__, 'ajax_toggle_subscription' ] );
        add_action( 'wp_ajax_nopriv_ltms_toggle_subscription', [ __CLASS__, 'ajax_toggle_subscription' ] );

        // g. Recent purchases for social proof notifications.
        add_action( 'wp_ajax_ltms_get_recent_purchases',        [ __CLASS__, 'ajax_get_recent_purchases' ] );
        add_action( 'wp_ajax_nopriv_ltms_get_recent_purchases', [ __CLASS__, 'ajax_get_recent_purchases' ] );

        // h. Submit a product review (uses the WP comment system).
        add_action( 'wp_ajax_ltms_submit_review',        [ __CLASS__, 'ajax_submit_review' ] );
        add_action( 'wp_ajax_nopriv_ltms_submit_review', [ __CLASS__, 'ajax_submit_review' ] );

        // i. Waitlist subscribe (back-in-stock notification).
        add_action( 'wp_ajax_ltms_waitlist_subscribe',        [ __CLASS__, 'ajax_waitlist_subscribe' ] );
        add_action( 'wp_ajax_nopriv_ltms_waitlist_subscribe', [ __CLASS__, 'ajax_waitlist_subscribe' ] );

        // j. Search autocomplete (live product suggestions).
        add_action( 'wp_ajax_ltms_search_autocomplete',        [ __CLASS__, 'ajax_search_autocomplete' ] );
        add_action( 'wp_ajax_nopriv_ltms_search_autocomplete', [ __CLASS__, 'ajax_search_autocomplete' ] );
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
    // AJAX: ltms_get_cart (Task 67-A / UX-CART-1)
    // =========================================================================

    /**
     * Returns the current WooCommerce cart contents as structured JSON for
     * the LTMS cart drawer (`ltms-ux-enhancements.js` module #53).
     *
     * Task 67-A / UX-CART-1 FIX: the drawer previously POSTed to the
     * `woocommerce_get_cart_contents` AJAX action which does NOT exist in
     * WooCommerce core — every request 404'd / returned `-1`, the success
     * branch never ran, and `renderCartEmpty()` was always called even when
     * the cart had items. This endpoint returns the same shape the JS
     * `renderCartItems()` helper expects (`items[]` with `key`, `name`,
     * `quantity`, `image`, `variation`, `price_formatted`, plus
     * `total_formatted` and `count`).
     *
     * Available to both logged-in and guest checkouts (the drawer opens on
     * the storefront for any visitor). Reads directly from `WC()->cart` so
     * the data is always live.
     *
     * @return void
     */
    public function ajax_get_cart(): void {
        // Nonce is optional here (drawer opens for guests), but verify when
        // provided to harden against CSRF on logged-in sessions.
        check_ajax_referer( 'ltms_ux_nonce', 'nonce', false );

        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            wp_send_json_success( [
                'items'           => [],
                'total_formatted' => '',
                'count'           => 0,
                'cart_url'        => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '',
                'checkout_url'    => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '',
            ] );
        }

        $cart     = WC()->cart;
        $items    = [];

        foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
            $product      = $cart_item['data'];
            $variation    = '';
            $variation_id = isset( $cart_item['variation_id'] ) ? absint( $cart_item['variation_id'] ) : 0;

            if ( $variation_id && $product instanceof \WC_Product_Variation ) {
                $attribute_summary = $product->get_attribute_summary();
                if ( $attribute_summary ) {
                    $variation = $attribute_summary;
                } elseif ( ! empty( $cart_item['variation'] ) ) {
                    $pairs = [];
                    foreach ( $cart_item['variation'] as $tax => $val ) {
                        $term  = get_term_by( 'slug', $val, $tax );
                        $pairs[] = $term ? $term->name : $val;
                    }
                    $variation = implode( ', ', $pairs );
                }
            }

            $image_url = '';
            if ( $product && $product->get_image_id() ) {
                $image_url = wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) ?: '';
            }

            $items[] = [
                'key'             => $cart_item_key,
                'product_id'      => $product ? $product->get_id() : 0,
                'name'            => $product ? $product->get_name() : '',
                'quantity'        => isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 1,
                'variation'       => $variation,
                'image'           => $image_url,
                'price_formatted' => $product ? wp_strip_all_tags( wc_price( $product->get_price() * $cart_item['quantity'] ) ) : '',
                'single_price_formatted' => $product ? wp_strip_all_tags( wc_price( $product->get_price() ) ) : '',
                'product_url'     => $product ? $product->get_permalink() : '',
            ];
        }

        // v2.9.51: Decodificar entidades HTML (&#36; → $, &nbsp; → espacio) del total
        // para que el JS pueda usar textContent de forma segura sin depender de innerHTML.
        // Esto evita el bug del doble-escape cuando el valor pasa por wp_json_encode.
        $total_formatted = wp_strip_all_tags( $cart->get_cart_total() );
        $total_formatted = html_entity_decode( $total_formatted, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

        // También decodificar los precios de los items.
        foreach ( $items as &$item_ref ) {
            if ( ! empty( $item_ref['price_formatted'] ) ) {
                $item_ref['price_formatted'] = html_entity_decode( $item_ref['price_formatted'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            }
            if ( ! empty( $item_ref['single_price_formatted'] ) ) {
                $item_ref['single_price_formatted'] = html_entity_decode( $item_ref['single_price_formatted'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            }
        }
        unset( $item_ref );

        wp_send_json_success( [
            'items'           => $items,
            'total_formatted' => $total_formatted,
            'count'           => $cart->get_cart_contents_count(),
            'cart_url'        => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '',
            'checkout_url'    => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '',
        ] );
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

        // ── 1b. CR-CRASH-2: Idempotency lock ────────────────────────────────
        // Prevents double-submit (race window) — if a second checkout request
        // arrives while the first is still in flight, it is rejected with 409.
        // 60s TTL acts as a safety net if the script crashes before release.
        $user_id = get_current_user_id();
        if ( $user_id ) {
            $this->checkout_lock_key = 'ltms_checkout_lock_' . $user_id;
        } elseif ( function_exists( 'WC' ) && WC()->session ) {
            // Guest checkout — use the WC session customer_id so guests do not
            // share the same lock.
            $this->checkout_lock_key = 'ltms_checkout_lock_guest_' . WC()->session->get_customer_id();
        } else {
            $this->checkout_lock_key = 'ltms_checkout_lock_guest_anon';
        }

        if ( get_transient( $this->checkout_lock_key ) ) {
            wp_send_json_error( [ 'message' => __( 'Checkout already in progress', 'ltms' ) ], 409 );
        }
        set_transient( $this->checkout_lock_key, true, 60 );

        // Release the lock on every exit path — wp_send_json_* calls wp_die,
        // and register_shutdown_function runs after the response is sent.
        register_shutdown_function( [ $this, 'release_checkout_lock' ] );

        // ── 2. Obtener y sanitizar datos del POST ─────────────────────────────
        $payment_method = sanitize_key( $_POST['payment_method'] ?? '' );
        $order_id       = absint( $_POST['order_id'] ?? 0 );

        // L-3 FIX: Registrar consentimiento explícito del checkout.
        // Ley 1480/2011, art. 51 — confirmación de compra con términos claros.
        // Ley 1581/2012 — tratamiento de datos del comprador requiere consentimiento registrado.
        //
        // Task 67-A / CHK-CONSENT-1 FIX: the form field is rendered with
        // `name="ltms_privacy_consent"` (see add_privacy_consent_field()), but
        // this code previously read `$_POST['terms_consent']` — a key that was
        // never sent — so the consent flag was always `false`. We now read the
        // canonical name so the legal compliance log reflects the user's
        // actual choice. (CHK-SERIALIZE-1 in ltms-checkout.js ensures the
        // checkbox's checked state — not just its value — is serialized.)
        if ( class_exists( 'LTMS_Legal_Compliance' ) && $order_id ) {
            LTMS_Legal_Compliance::save_checkout_consent(
                get_current_user_id(),
                $order_id,
                ! empty( $_POST['ltms_privacy_consent'] ) // phpcs:ignore
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

        // ── 4c. CR-CRASH-1: Snapshot the cart for crash recovery ───────────
        // If the gateway call crashes the PHP process (fatal error, OOM, network
        // timeout mid-charge), the customer is left with an unpaid order and an
        // empty cart — a confusing state. The transient allows recovery: the
        // recover_orphaned_checkout() method (called by cron or on next load)
        // checks if the order ended up paid (gateway confirmed) and, if not,
        // restores the cart so the customer can retry. Deleted on success.
        if ( function_exists( 'WC' ) && WC()->cart && method_exists( WC()->cart, 'get_cart_for_session' ) ) {
            set_transient(
                'ltms_checkout_' . $order->get_id(),
                [
                    'cart'       => WC()->cart->get_cart_for_session(),
                    'user_id'    => get_current_user_id(),
                    'created_at' => time(),
                ],
                HOUR_IN_SECONDS
            );
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
        // ME-7 FIX: include a random suffix so concurrent checkouts in the same
        // second do not collide on order_ref (which Openpay uses to dedupe charges).
        $order_ref   = 'LTMS-' . $order->get_id() . '-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 6, false );
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
                // CR-CRASH-1: clean up the cart-state transient — the order has
                // either been paid (card) or has been redirected to the gateway
                // (PSE/OXXO/Addi). In both cases recovery is no longer needed.
                delete_transient( 'ltms_checkout_' . $order->get_id() );

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
                // CR-4 FIX: explicitly mark the order as 'failed' so WooCommerce's
                // failed-order workflow runs (stock restoration, customer & admin
                // notifications, status transitions). Previously the order stayed in
                // its previous state (pending/processing), ambiguous for the customer
                // and inconsistent for inventory.
                if ( ! $order->has_status( [ 'failed', 'cancelled', 'refunded' ] ) ) {
                    $order->update_status( 'failed', __( 'Payment failed', 'ltms' ) );
                }
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
        // CR-5 FIX: validate that the user-supplied redirect_url points back to
        // this site before passing it to Openpay. Otherwise Openpay would redirect
        // the customer to an attacker-controlled host after PSE (phishing risk).
        $redirect_url = esc_url_raw( $_POST['redirect_url'] ?? $order->get_checkout_order_received_url() );
        if ( $redirect_url && strpos( $redirect_url, home_url() ) !== 0 ) {
            $redirect_url = home_url( '/panel-vendedor/?tab=ordenes' );
        }
        if ( empty( $redirect_url ) ) {
            $redirect_url = $order->get_checkout_order_received_url();
        }

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
                // HI-7 FIX: get_userdata() may return false if the vendor user was
                // deleted. On PHP 8+ accessing ->display_name on false throws
                // TypeError. Fall back to a safe display name in that case.
                $cart_vendor_user = get_userdata( $cart_vendor_id );
                $cart_vendor_name = get_user_meta( $cart_vendor_id, 'ltms_store_name', true )
                    ?: ( $cart_vendor_user ? $cart_vendor_user->display_name : __( 'Unknown vendor', 'ltms' ) );
                $new_vendor_user  = get_userdata( $new_vendor_id );
                $new_vendor_name  = get_user_meta( $new_vendor_id, 'ltms_store_name', true )
                    ?: ( $new_vendor_user ? $new_vendor_user->display_name : __( 'Unknown vendor', 'ltms' ) );

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

    // =========================================================================
    // CR-CRASH-2: Idempotency lock release
    // =========================================================================

    /**
     * Releases the per-user checkout idempotency lock acquired in
     * ajax_process_checkout(). Registered as a shutdown function so it runs on
     * every exit path (wp_send_json_* -> wp_die, fatal errors, exceptions).
     *
     * @return void
     */
    public function release_checkout_lock(): void {
        if ( ! empty( $this->checkout_lock_key ) ) {
            delete_transient( $this->checkout_lock_key );
            $this->checkout_lock_key = '';
        }
    }

    // =========================================================================
    // CR-CRASH-1: Crash recovery for orphaned checkout transients
    // =========================================================================

    /**
     * Recovers from a crashed checkout.
     *
     * If the gateway call crashed the PHP process (fatal error, OOM, network
     * timeout mid-charge), an orphaned transient `ltms_checkout_{order_id}`
     * is left behind. This method checks the order's current state and either:
     *  - Completes the order cleanup if the gateway confirmed payment.
     *  - Restores the cart so the customer can retry the checkout.
     *
     * Should be called by a cron job (ltms_recover_orphaned_checkouts) or on
     * the customer's next page load.
     *
     * @param int $order_id Order ID to recover.
     * @return bool True if recovery action was taken, false otherwise.
     */
    public static function recover_orphaned_checkout( int $order_id ): bool {
        if ( ! $order_id ) {
            return false;
        }

        $transient_key = 'ltms_checkout_' . $order_id;
        $snapshot      = get_transient( $transient_key );
        if ( false === $snapshot || ! is_array( $snapshot ) ) {
            return false; // No orphaned state for this order.
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            delete_transient( $transient_key );
            return false;
        }

        // If the order is now paid or in a terminal state, the gateway
        // confirmed payment but we crashed before cleaning up. Delete the
        // transient and let WC's normal post-payment flow handle the rest.
        if ( $order->is_paid() || $order->has_status( [ 'processing', 'completed', 'cancelled', 'refunded', 'failed' ] ) ) {
            delete_transient( $transient_key );
            return true;
        }

        // Order is still pending — the gateway never confirmed payment.
        // Restore the cart from the snapshot so the customer can retry.
        $saved_cart = $snapshot['cart'] ?? [];
        if ( is_array( $saved_cart ) && function_exists( 'WC' ) && WC()->cart ) {
            WC()->cart->empty_cart();
            foreach ( $saved_cart as $cart_item ) {
                $product_id   = (int) ( $cart_item['product_id'] ?? 0 );
                $quantity     = (int) ( $cart_item['quantity'] ?? 1 );
                $variation_id = (int) ( $cart_item['variation_id'] ?? 0 );
                if ( $product_id && $quantity > 0 ) {
                    WC()->cart->add_to_cart( $product_id, $quantity, $variation_id );
                }
            }
        }

        delete_transient( $transient_key );
        return true;
    }

    // =========================================================================
    // v3.1.0 — Cross-Border motor (Task 63-D)
    // =========================================================================

    /**
     * Renders the currency selector widget at the top of the checkout form.
     *
     * The dropdown is populated with all enabled currencies (via
     * LTMS_Currency_Manager::get_enabled_currencies()). The JS handler
     * (ltms-currency-switcher.js) listens for `change` events and triggers
     * an AJAX request to refresh the cart totals in the new currency.
     *
     * @return void
     */
    public static function render_currency_selector(): void {
        // Defensive: if the cross-border motor is not loaded, render nothing.
        if ( ! class_exists( 'LTMS_Currency_Manager' ) ) {
            return;
        }

        $enabled    = LTMS_Currency_Manager::get_enabled_currencies();
        if ( empty( $enabled ) ) {
            return;
        }

        $current = LTMS_Currency_Manager::get_display_currency();

        echo '<div class="ltms-currency-switcher-wrap" id="ltms-currency-switcher-wrap">';
        echo '<label for="ltms-currency-select" class="ltms-currency-switcher__label">' . esc_html__( 'Ver precios en:', 'ltms' ) . '</label>';
        echo '<select id="ltms-currency-select" name="ltms_display_currency" class="ltms-currency-switcher__select">';
        foreach ( $enabled as $code => $info ) {
            $selected = selected( $code, $current, false );
            echo '<option value="' . esc_attr( $code ) . '" ' . $selected . '>';
            echo esc_html( $code . ' — ' . $info['symbol'] . ' (' . $info['name'] . ')' );
            echo '</option>';
        }
        echo '</select>';
        echo '<span class="ltms-currency-switcher__spinner" id="ltms-currency-spinner" style="display:none;">' . esc_html__( 'Convirtiendo…', 'ltms' ) . '</span>';
        echo '</div>';
    }

    /**
     * Renders the customs estimate block on the order review.
     *
     * Only shown when the cart's vendor origin country differs from the
     * shipping destination country (i.e. a cross-border order). The block
     * shows the estimated duties + taxes and clearly states whether the
     * duties are paid at checkout (DDP) or payable on delivery (DDU).
     *
     * @return void
     */
    public static function render_customs_estimate(): void {
        // Defensive: customs calculator must be available.
        if ( ! class_exists( 'LTMS_Customs_Calculator' ) ) {
            return;
        }

        // The block is a placeholder — the JS handler populates it via AJAX
        // (ltms_get_customs_estimate) whenever the shipping country changes.
        // Server-side rendering ensures the markup is always present even if
        // JS fails (progressive enhancement).
        echo '<div class="ltms-customs-estimate" id="ltms-customs-estimate" style="display:none;">';
        echo '<h3 class="ltms-customs-estimate__title">' . esc_html__( 'Estimación de impuestos aduaneros', 'ltms' ) . '</h3>';
        echo '<div class="ltms-customs-estimate__body" id="ltms-customs-estimate-body"></div>';
        echo '</div>';
    }

    /**
     * AJAX: ltms_change_currency
     *
     * Switches the customer's display currency and recalculates the cart
     * totals in the new currency. Returns the new totals + FX rate so the
     * JS can update all displayed prices on the page.
     *
     * @return void
     */
    public function ajax_change_currency(): void {
        // CHK-BUG-1 FIX (Task 65-C): Proper nonce verification. Previously,
        // check_ajax_referer was called with $die=false, which returns -1 on
        // failure WITHOUT killing the request — but the return value was never
        // checked, so attackers could call this endpoint with no/invalid nonce
        // and modify the customer's WC session (set_display_currency persists).
        if ( ! check_ajax_referer( 'ltms_checkout_nonce', 'nonce', false ) ) {
            wp_send_json_error(
                [ 'message' => __( 'Sesión expirada. Recarga la página e inténtalo de nuevo.', 'ltms' ) ],
                403
            );
        }

        $currency = strtoupper( sanitize_text_field( $_POST['currency'] ?? '' ) );
        if ( ! $currency ) {
            wp_send_json_error( [ 'message' => __( 'Moneda no especificada', 'ltms' ) ], 400 );
        }

        // Defensive: if the cross-border motor is not loaded, the switcher is a no-op.
        if ( ! class_exists( 'LTMS_Currency_Manager' ) || ! class_exists( 'LTMS_FX_Rate_Provider' ) ) {
            wp_send_json_error( [ 'message' => __( 'Multi-currency no disponible', 'ltms' ) ], 503 );
        }

        $enabled = LTMS_Currency_Manager::get_enabled_currencies();
        if ( ! isset( $enabled[ $currency ] ) ) {
            wp_send_json_error( [ 'message' => sprintf( __( 'Moneda no soportada: %s', 'ltms' ), $currency ) ], 400 );
        }

        $previous_currency = LTMS_Currency_Manager::get_display_currency();
        $base_currency     = LTMS_Currency_Manager::get_base_currency();

        // Persist selection to session.
        LTMS_Currency_Manager::set_display_currency( $currency );

        // CHK-BUG-3 FIX (Task 65-C): Recalculate cart totals server-side so
        // shipping, taxes, and fees are recomputed for the new display currency.
        // Previously, only the cart_total_display was multiplied by the FX rate
        // — WC()->cart->calculate_totals() was never called, so shipping and
        // taxes stayed in base currency while the displayed total switched,
        // creating a discrepancy between what the customer saw and what WC
        // actually charged.
        if ( function_exists( 'WC' ) && WC()->cart ) {
            WC()->cart->calculate_totals();
        }

        // Compute the effective FX rate (base → display) so the JS can
        // update all displayed prices client-side without re-fetching.
        $rate = ( $base_currency === $currency )
            ? 1.0
            : (float) ( LTMS_FX_Rate_Provider::get_rate( $base_currency, $currency ) ?? 0 );

        // Compute the new cart totals in the new currency.
        $cart_total_base    = 0.0;
        $cart_total_display = 0.0;
        if ( function_exists( 'WC' ) && WC()->cart ) {
            $cart_total_base = (float) WC()->cart->get_total( 'edit' );
            if ( $rate > 0 ) {
                $cart_total_display = round( $cart_total_base * $rate, LTMS_Currency_Manager::get_decimals( $currency ) );
            }
        }

        $formatted_total = LTMS_Currency_Manager::format_amount( $cart_total_display, $currency );

        // Re-run the customs estimate in the new currency.
        $customs = self::compute_customs_estimate_for_cart( $currency );

        wp_send_json_success( [
            'currency'           => $currency,
            'previous_currency'  => $previous_currency,
            'base_currency'      => $base_currency,
            'rate'               => $rate,
            'cart_total'         => $cart_total_display,
            'cart_total_formatted'=> $formatted_total,
            'customs'            => $customs,
        ] );
    }

    /**
     * AJAX: ltms_get_customs_estimate
     *
     * Returns the customs calculation for the cart given a destination
     * country. The cart value is computed from WC()->cart.
     *
     * @return void
     */
    public function ajax_get_customs_estimate(): void {
        // CHK-BUG-2 FIX (Task 65-C): Proper nonce verification — same fix as
        // CHK-BUG-1 for the customs estimate endpoint.
        if ( ! check_ajax_referer( 'ltms_checkout_nonce', 'nonce', false ) ) {
            wp_send_json_error(
                [ 'message' => __( 'Sesión expirada. Recarga la página e inténtalo de nuevo.', 'ltms' ) ],
                403
            );
        }

        $destination = strtoupper( sanitize_text_field( $_POST['destination_country'] ?? '' ) );
        if ( ! $destination ) {
            wp_send_json_error( [ 'message' => __( 'País de destino no especificado', 'ltms' ) ], 400 );
        }

        if ( ! class_exists( 'LTMS_Customs_Calculator' ) ) {
            wp_send_json_error( [ 'message' => __( 'Calculadora aduanera no disponible', 'ltms' ) ], 503 );
        }

        $customs = self::compute_customs_estimate_for_cart( '', $destination );

        if ( isset( $customs['error'] ) ) {
            wp_send_json_error( [ 'message' => $customs['error'] ], 400 );
        }

        wp_send_json_success( $customs );
    }

    /**
     * Computes the customs estimate for the current cart given a destination
     * country (defaults to the WC customer's shipping country).
     *
     * @param string $display_currency    Display currency (defaults to the
     *                                    customer's current display currency).
     * @param string $destination_country Destination ISO 2-letter country code
     *                                    (defaults to WC customer's shipping country).
     * @return array Customs calculation result + display info, or ['error' => string].
     */
    private static function compute_customs_estimate_for_cart( string $display_currency = '', string $destination_country = '' ): array {
        if ( ! class_exists( 'LTMS_Customs_Calculator' ) ) {
            return [ 'error' => 'Customs calculator not available' ];
        }

        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return [ 'error' => 'Cart not available' ];
        }

        $display_currency = $display_currency ?: ( class_exists( 'LTMS_Currency_Manager' ) ? LTMS_Currency_Manager::get_display_currency() : LTMS_Core_Config::get_currency() );

        // Resolve origin country from the cart's vendor (single-vendor cart is enforced
        // by validate_single_vendor_cart — the first item's vendor is the only vendor).
        $origin_country = '';
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $pid = (int) ( $cart_item['product_id'] ?? 0 );
            if ( ! $pid ) {
                continue;
            }
            $vendor_id = (int) get_post_meta( $pid, '_ltms_vendor_id', true );
            if ( $vendor_id <= 0 ) {
                $vendor_id = (int) get_post_field( 'post_author', $pid );
            }
            if ( $vendor_id > 0 ) {
                $origin_country = (string) ( get_user_meta( $vendor_id, 'ltms_vendor_country', true ) ?: '' );
                if ( ! $origin_country ) {
                    $origin_country = LTMS_Core_Config::get_country();
                }
                break;
            }
        }

        if ( ! $origin_country ) {
            $origin_country = LTMS_Core_Config::get_country();
        }

        if ( ! $destination_country ) {
            $destination_country = (string) ( WC()->customer ? WC()->customer->get_shipping_country() : '' );
        }
        if ( ! $destination_country ) {
            // No destination yet — return empty estimate so the UI doesn't break.
            return [
                'available'         => false,
                'reason'            => 'no_destination',
                'origin_country'    => $origin_country,
                'destination_country' => '',
                'incoterm'          => LTMS_Core_Config::get( 'ltms_default_incoterm', 'DDU' ),
                'total_duties_taxes' => 0.0,
                'currency'          => $display_currency,
            ];
        }

        // Domestic shipment — no customs.
        if ( strtoupper( $origin_country ) === strtoupper( $destination_country ) ) {
            return [
                'available'         => false,
                'reason'            => 'domestic',
                'origin_country'    => $origin_country,
                'destination_country' => $destination_country,
                'incoterm'          => '',
                'total_duties_taxes' => 0.0,
                'currency'          => $display_currency,
            ];
        }

        // IMPORTANT: avoid calling WC()->cart->get_total() here. This method
        // is invoked from the `woocommerce_cart_calculate_fees` hook (via
        // add_ddp_duties_fee), and calling get_total() inside that hook would
        // trigger an infinite recursion (WC re-enters calculate_totals()).
        // Use the raw subcomponents instead — they're stable during the fees
        // hook because WC has already populated them before firing the hook.
        $cart_total = (float) WC()->cart->get_cart_contents_total()
                    + (float) WC()->cart->get_shipping_total()
                    + (float) WC()->cart->get_cart_contents_tax()
                    + (float) WC()->cart->get_shipping_tax();
        $shipping   = (float) WC()->cart->get_shipping_total();
        $incoterm   = (string) LTMS_Core_Config::get( 'ltms_default_incoterm', 'DDU' );

        $customs = LTMS_Customs_Calculator::calculate( [
            'item_value'          => $cart_total,
            'origin_country'      => $origin_country,
            'destination_country' => $destination_country,
            'shipping_cost'       => $shipping,
            'incoterm'            => $incoterm,
            'currency'            => $display_currency,
        ] );

        $customs['available']          = true;
        $customs['reason']             = '';
        $customs['origin_country']     = $origin_country;
        $customs['destination_country']= $destination_country;
        $customs['currency']           = $display_currency;
        $customs['total_formatted']    = class_exists( 'LTMS_Currency_Manager' )
            ? LTMS_Currency_Manager::format_amount( (float) $customs['total_duties_taxes'], $display_currency )
            : (string) $customs['total_duties_taxes'];

        return $customs;
    }

    /**
     * Saves the display currency + FX rate metadata on the order at creation
     * time. The metadata is used by the Order Split + Alegra sync to record
     * the conversion context for audit.
     *
     * @param \WC_Order $order Pedido recién creado.
     * @return void
     */
    public static function save_currency_meta_on_order( \WC_Order $order ): void {
        if ( ! class_exists( 'LTMS_Currency_Manager' ) ) {
            return;
        }

        $display = LTMS_Currency_Manager::get_display_currency();
        $base    = LTMS_Currency_Manager::get_base_currency();

        // Persist display currency + the rate at which the order was placed.
        // WooCommerce's order->get_currency() returns the WCML/WooCommerce
        // currency — we set _ltms_display_currency to what the customer saw,
        // which may differ if the multi-currency switcher changed prices
        // without changing the WC order currency.
        $order->update_meta_data( '_ltms_display_currency', $display );

        if ( class_exists( 'LTMS_FX_Rate_Provider' ) && $display !== $base ) {
            $rate = LTMS_FX_Rate_Provider::get_rate( $base, $display );
            if ( $rate !== null ) {
                $order->update_meta_data( '_ltms_display_currency_rate', (float) $rate );
            }
        } else {
            $order->update_meta_data( '_ltms_display_currency_rate', 1.0 );
        }

        // Persist the customs declaration estimate (snapshot at purchase time).
        $customs = self::compute_customs_estimate_for_cart( $display );
        if ( ! empty( $customs['available'] ) ) {
            $order->update_meta_data( '_ltms_customs_declaration', $customs );
        }

        $order->save();
    }

    /**
     * DDP duties: adds the customs duties as a WooCommerce cart fee so the
     * buyer sees the inclusive total at checkout and pays them upfront.
     *
     * Only fires when:
     *   - The configured incoterm is DDP.
     *   - The order is cross-border (origin ≠ destination).
     *   - The customs calculator returns duties > 0.
     *
     * The fee is added in the WC order currency. The Order Split later
     * debits this amount from the vendor's wallet when settling DDP orders.
     *
     * @param \WC_Cart $cart Carrito.
     * @return void
     */
    public static function add_ddp_duties_fee( \WC_Cart $cart ): void {
        // Defensive: skip entirely if cross-border motor is not loaded.
        if ( ! class_exists( 'LTMS_Customs_Calculator' ) ) {
            return;
        }

        // Only add the fee if DDP is the configured incoterm.
        $incoterm = strtoupper( (string) LTMS_Core_Config::get( 'ltms_default_incoterm', 'DDU' ) );
        if ( $incoterm !== LTMS_Customs_Calculator::INCOTERM_DDP ) {
            return;
        }

        // Avoid running during REST/cart-rest requests where WC()->customer is
        // not fully initialized — the fee would be added to a zero-total cart.
        if ( ! function_exists( 'WC' ) || ! WC()->customer ) {
            return;
        }

        $customs = self::compute_customs_estimate_for_cart();
        if ( empty( $customs['available'] ) ) {
            return;
        }

        $duties = (float) ( $customs['total_duties_taxes'] ?? 0 );
        if ( $duties <= 0 ) {
            return;
        }

        // Mark the fee as taxable=false and as a "DDP customs" line — the
        // WooCommerce fee label is what the buyer sees on the totals table.
        $label = sprintf(
            /* translators: 1: destination country code, 2: incoterm */
            __( 'Impuestos aduaneros (DDP) — %1$s (%2$s)', 'ltms' ),
            $customs['destination_country'] ?? '',
            $incoterm
        );

        $cart->add_fee( $label, $duties, false, '' );
    }

    // =========================================================================
    // Task 67-B — UX Enhancements AJAX handlers + gift wrapping fee.
    //
    // All handlers below verify the dedicated `ltms_ux_nonce` action that is
    // exposed globally to JS via the `window.ltmsUX` bootstrap in
    // class-ltms-frontend-assets.php → enqueue_frontend_assets().
    // =========================================================================

    /**
     * UX-FAKE-5 — Adds the gift wrapping fee to the cart when the customer
     * has opted in via the `[data-gift-wrapping]` UX widget.
     *
     * The UX layer (assets/js/ltms-ux-enhancements.js → initGiftWrapping)
     * keeps a hidden `<input name="ltms_gift_wrapping" value="yes|no">` in
     * the WooCommerce checkout form. When the form is submitted (or when WC
     * triggers `update_order_review` to recalculate totals), the field is
     * POSTed to the server and this hook reads it. The session fallback
     * covers cart/mini-cart refresh requests where the checkout form is not
     * POSTed in full.
     *
     * @param \WC_Cart $cart Carrito.
     * @return void
     */
    public static function add_gift_wrapping_fee( \WC_Cart $cart ): void {
        $enabled = false;

        // Primary source — the checkout form POST.
        if ( isset( $_POST['ltms_gift_wrapping'] ) && sanitize_key( wp_unslash( $_POST['ltms_gift_wrapping'] ) ) === 'yes' ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $enabled = true;
        }

        // Fallback — the WC session (set by the AJAX toggle endpoint or by a
        // previous checkout POST). Survives cart fragment refreshes.
        if ( ! $enabled && function_exists( 'WC' ) && WC()->session ) {
            $session_val = WC()->session->get( 'ltms_gift_wrapping' );
            if ( $session_val === 'yes' ) {
                $enabled = true;
            }
        }

        if ( ! $enabled ) {
            return;
        }

        $fee   = (float) LTMS_Core_Config::get( 'ltms_gift_wrapping_fee', 5.00 );
        $label = __( 'Envoltura de regalo', 'ltms' );

        // The fee is taxable (third arg true) — defaults to standard tax rate.
        $cart->add_fee( $label, $fee, true );
    }

    // -------------------------------------------------------------------------
    // a. ltms_get_recommendations
    // -------------------------------------------------------------------------

    /**
     * AJAX: ltms_get_recommendations
     *
     * Returns product recommendations for a given product. Supports four
     * recommendation types: `related`, `viewed`, `cross-sell`, `up-sell`.
     *
     * POST params:
     *   - nonce       : ltms_ux_nonce
     *   - product_id  : absint
     *   - type        : 'related' | 'viewed' | 'cross-sell' | 'up-sell' (default 'related')
     *   - limit       : int (optional, default 6)
     *
     * @return void
     */
    public static function ajax_get_recommendations(): void {
        check_ajax_referer( 'ltms_ux_nonce', 'nonce' );

        $product_id = absint( $_POST['product_id'] ?? 0 );
        $type       = sanitize_key( $_POST['type'] ?? 'related' );
        $limit      = max( 1, min( 24, absint( $_POST['limit'] ?? 6 ) ) );

        $products = self::get_recommendations( $product_id, $type, $limit );

        wp_send_json_success( [ 'products' => $products ] );
    }

    /**
     * Returns an array of recommended products formatted for the JS UI.
     *
     * Each entry: { id, name, price, image, url, stock_status }.
     *
     * @param int    $product_id Source product ID.
     * @param string $type       related|viewed|cross-sell|up-sell.
     * @param int    $limit      Maximum number of results.
     * @return array
     */
    private static function get_recommendations( int $product_id, string $type, int $limit = 6 ): array {
        if ( ! function_exists( 'wc_get_products' ) ) {
            return [];
        }

        $candidate_ids = [];

        if ( $product_id ) {
            $source = wc_get_product( $product_id );

            if ( $source ) {
                switch ( $type ) {
                    case 'cross-sell':
                        $candidate_ids = $source->get_cross_sell_ids();
                        break;
                    case 'up-sell':
                        $candidate_ids = $source->get_upsell_ids();
                        break;
                    case 'related':
                    default:
                        $candidate_ids = wc_get_related_products( $product_id, $limit + 5 );
                        break;
                }
            }
        }

        // Fallback: best sellers by total_sales when no candidates were found.
        if ( empty( $candidate_ids ) ) {
            $args = [
                'limit'    => $limit + 5,
                'status'   => 'publish',
                'orderby'  => 'meta_value_num',
                'order'    => 'DESC',
                'meta_key' => 'total_sales', // phpcs:ignore WordPress.VIP.SlowDBMeta.slow_db_query_meta_key
            ];
            if ( $product_id ) {
                $args['exclude'] = [ $product_id ];
            }
            $fallback = wc_get_products( $args );
            if ( $fallback ) {
                // Plain loop — PHP 7.4+ arrow functions are not available in
                // every environment, so we avoid array_map with closures.
                $candidate_ids = [];
                foreach ( $fallback as $p ) {
                    $candidate_ids[] = $p->get_id();
                }
            }
        }

        if ( empty( $candidate_ids ) ) {
            return [];
        }

        // De-duplicate + exclude the source product + apply the limit.
        $candidate_ids = array_values( array_unique( array_map( 'absint', $candidate_ids ) ) );
        if ( $product_id ) {
            $candidate_ids = array_values( array_diff( $candidate_ids, [ $product_id ] ) );
        }
        $candidate_ids = array_slice( $candidate_ids, 0, $limit );

        $products = [];
        foreach ( $candidate_ids as $pid ) {
            $product = wc_get_product( $pid );
            if ( ! $product || 'publish' !== $product->get_status() ) {
                continue;
            }
            // Skip out-of-stock items so we never recommend unavailable products.
            if ( ! $product->is_in_stock() ) {
                continue;
            }
            $products[] = [
                'id'           => $product->get_id(),
                'name'         => $product->get_name(),
                'price'        => $product->get_price_html(),
                'image'        => wp_get_attachment_image_url( $product->get_image_id(), 'medium' ) ?: '',
                'url'          => $product->get_permalink(),
                'stock_status' => $product->get_stock_status(),
            ];
        }

        return $products;
    }

    // -------------------------------------------------------------------------
    // b. ltms_quick_view
    // -------------------------------------------------------------------------

    /**
     * AJAX: ltms_quick_view
     *
     * Returns the data needed to populate the Quick View modal:
     *   { id, name, price, description, image, add_to_cart_url, stock_status }.
     *
     * @return void
     */
    public static function ajax_quick_view(): void {
        check_ajax_referer( 'ltms_ux_nonce', 'nonce' );

        $product_id = absint( $_POST['product_id'] ?? 0 );
        if ( ! $product_id ) {
            wp_send_json_error( [ 'message' => __( 'Producto no especificado', 'ltms' ) ], 400 );
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            wp_send_json_error( [ 'message' => __( 'Producto no encontrado', 'ltms' ) ], 404 );
        }

        $rating = $product->get_average_rating();
        $count  = $product->get_rating_count();

        wp_send_json_success( [
            'id'            => $product->get_id(),
            'name'          => $product->get_name(),
            'price'         => $product->get_price_html(),
            'description'   => wp_trim_words( wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() ), 30 ),
            'image'         => wp_get_attachment_image_url( $product->get_image_id(), 'medium' ) ?: '',
            'add_to_cart_url' => $product->add_to_cart_url(),
            'stock_status'  => $product->get_stock_status(),
            'in_stock'      => $product->is_in_stock(),
            'rating'        => (float) $rating,
            'review_count'  => (int) $count,
            'url'           => $product->get_permalink(),
        ] );
    }

    // -------------------------------------------------------------------------
    // c. ltms_reorder (UX-FAKE-1)
    // -------------------------------------------------------------------------

    /**
     * AJAX: ltms_reorder
     *
     * Re-adds all items from a previous order to the cart. Restricted to the
     * logged-in user that owns the order.
     *
     * POST params:
     *   - nonce    : ltms_ux_nonce
     *   - order_id : absint
     *
     * @return void
     */
    public static function ajax_reorder(): void {
        check_ajax_referer( 'ltms_ux_nonce', 'nonce' );

        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            wp_send_json_error( [ 'message' => __( 'Carrito no disponible', 'ltms' ) ], 503 );
        }

        $order_id = absint( $_POST['order_id'] ?? 0 );
        if ( ! $order_id ) {
            wp_send_json_error( [ 'message' => __( 'Pedido no especificado', 'ltms' ) ], 400 );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => __( 'Pedido no encontrado', 'ltms' ) ], 404 );
        }

        // Ownership check — only the order's customer can reorder it.
        $current_user = get_current_user_id();
        if ( ! $current_user || (int) $order->get_customer_id() !== $current_user ) {
            wp_send_json_error( [ 'message' => __( 'No tienes permiso para reordenar este pedido', 'ltms' ) ], 403 );
        }

        $added   = 0;
        $skipped = 0;
        foreach ( $order->get_items() as $item ) {
            $product_id = $item->get_product_id();
            $qty        = $item->get_quantity();
            if ( ! $product_id ) {
                continue;
            }
            $result = WC()->cart->add_to_cart( $product_id, $qty );
            if ( $result ) {
                $added++;
            } else {
                $skipped++;
            }
        }

        if ( $added === 0 ) {
            wp_send_json_error( [
                'message' => __( 'No se pudo agregar ningún producto al carrito', 'ltms' ),
                'skipped' => $skipped,
            ], 422 );
        }

        $cart_count = (int) WC()->cart->get_cart_contents_count();

        wp_send_json_success( [
            'message'    => __( 'Productos agregados al carrito', 'ltms' ),
            'added'      => $added,
            'skipped'    => $skipped,
            'cart_count' => $cart_count,
        ] );
    }

    // -------------------------------------------------------------------------
    // d. ltms_validate_coupon (UX-FAKE-6)
    // -------------------------------------------------------------------------

    /**
     * AJAX: ltms_validate_coupon
     *
     * Validates a coupon code without applying it. The customer still has to
     * click "Place order" to finalize; the validation is purely informational
     * so the UX layer can show a success/error message immediately.
     *
     * @return void
     */
    public static function ajax_validate_coupon(): void {
        check_ajax_referer( 'ltms_ux_nonce', 'nonce' );

        $code = sanitize_text_field( wp_unslash( $_POST['coupon_code'] ?? '' ) );
        if ( '' === $code ) {
            wp_send_json_error( [ 'message' => __( 'Ingresa un código de cupón', 'ltms' ) ], 400 );
        }

        if ( ! class_exists( 'WC_Coupon' ) ) {
            wp_send_json_error( [ 'message' => __( 'WooCommerce no disponible', 'ltms' ) ], 503 );
        }

        $coupon = new \WC_Coupon( $code );
        if ( ! $coupon->get_id() ) {
            wp_send_json_error( [ 'message' => __( 'Cupón no válido', 'ltms' ) ] );
        }

        $discount = (float) $coupon->get_amount();
        $type     = $coupon->get_discount_type();

        // Human-readable discount summary.
        if ( $type === 'percent' ) {
            $discount_text = sprintf( '%s%% de descuento', $coupon->get_amount() );
        } elseif ( $type === 'fixed_cart' || $type === 'fixed_product' ) {
            $discount_text = function_exists( 'wc_price' )
                ? wp_strip_all_tags( wc_price( $discount ) ) . ' de descuento'
                : (string) $discount;
        } else {
            $discount_text = (string) $coupon->get_amount();
        }

        wp_send_json_success( [
            'message'  => __( 'Cupón válido', 'ltms' ),
            'discount' => $discount,
            'type'     => $type,
            'summary'  => $discount_text,
        ] );
    }

    // -------------------------------------------------------------------------
    // e. ltms_add_bundle_to_cart (UX-FAKE-3)
    // -------------------------------------------------------------------------

    /**
     * AJAX: ltms_add_bundle_to_cart
     *
     * Adds multiple products to the cart in one shot (the bundle builder).
     *
     * @return void
     */
    public static function ajax_add_bundle_to_cart(): void {
        check_ajax_referer( 'ltms_ux_nonce', 'nonce' );

        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            wp_send_json_error( [ 'message' => __( 'Carrito no disponible', 'ltms' ) ], 503 );
        }

        $raw_ids = isset( $_POST['product_ids'] ) ? (array) wp_unslash( $_POST['product_ids'] ) : [];
        $product_ids = array_filter( array_map( 'absint', $raw_ids ) );

        if ( empty( $product_ids ) ) {
            wp_send_json_error( [ 'message' => __( 'Selecciona al menos un producto', 'ltms' ) ], 400 );
        }

        $added   = 0;
        $skipped = 0;
        foreach ( $product_ids as $id ) {
            $result = WC()->cart->add_to_cart( $id, 1 );
            if ( $result ) {
                $added++;
            } else {
                $skipped++;
            }
        }

        if ( $added === 0 ) {
            wp_send_json_error( [
                'message' => __( 'No se pudo agregar ningún producto al carrito', 'ltms' ),
                'skipped' => $skipped,
            ], 422 );
        }

        $cart_count = (int) WC()->cart->get_cart_contents_count();

        wp_send_json_success( [
            'message'    => __( 'Bundle agregado', 'ltms' ),
            'added'      => $added,
            'skipped'    => $skipped,
            'cart_count' => $cart_count,
        ] );
    }

    // -------------------------------------------------------------------------
    // f. ltms_toggle_subscription (UX-FAKE-4)
    // -------------------------------------------------------------------------

    /**
     * AJAX: ltms_toggle_subscription
     *
     * Persists the customer's subscription choice (one-time vs recurring) in
     * the WC session, keyed by product ID. The checkout flow can later read
     * the same session key to convert the line item into a WC Subscription
     * when the WC Subscriptions plugin is active.
     *
     * @return void
     */
    public static function ajax_toggle_subscription(): void {
        check_ajax_referer( 'ltms_ux_nonce', 'nonce' );

        if ( ! function_exists( 'WC' ) ) {
            wp_send_json_error( [ 'message' => __( 'WooCommerce no disponible', 'ltms' ) ], 503 );
        }

        $product_id = absint( $_POST['product_id'] ?? 0 );
        if ( ! $product_id ) {
            wp_send_json_error( [ 'message' => __( 'Producto no especificado', 'ltms' ) ], 400 );
        }

        $subscribe = isset( $_POST['subscribe'] ) ? filter_var( wp_unslash( $_POST['subscribe'] ), FILTER_VALIDATE_BOOLEAN ) : false;

        if ( ! WC()->session ) {
            wp_send_json_error( [ 'message' => __( 'Sesión no disponible', 'ltms' ) ], 503 );
        }

        WC()->session->set( 'ltms_subscription_' . $product_id, $subscribe );

        // Also store the frequency if provided.
        if ( isset( $_POST['frequency'] ) ) {
            $frequency = absint( $_POST['frequency'] );
            WC()->session->set( 'ltms_subscription_freq_' . $product_id, $frequency );
        }

        wp_send_json_success( [
            'message'    => __( 'Preferencia guardada', 'ltms' ),
            'subscribe'  => $subscribe,
            'product_id' => $product_id,
        ] );
    }

    // -------------------------------------------------------------------------
    // g. ltms_get_recent_purchases
    // -------------------------------------------------------------------------

    /**
     * AJAX: ltms_get_recent_purchases
     *
     * Returns the most recent completed purchases (last 24 h) for the social
     * proof notification widget. Customer display names are anonymized to
     * the first name + last initial; city is included as-is from the billing
     * address. Returns an empty array if no recent purchases exist (the JS
     * widget then stays silent instead of fabricating notifications).
     *
     * @return void
     */
    public static function ajax_get_recent_purchases(): void {
        check_ajax_referer( 'ltms_ux_nonce', 'nonce' );

        if ( ! class_exists( 'WooCommerce' ) ) {
            wp_send_json_success( [ 'purchases' => [] ] );
        }

        global $wpdb;

        // The HPOS `wc_orders` table is the canonical source as of WC 8.x+.
        // Fall back to legacy postmeta lookup if HPOS is disabled.
        $use_hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_enabled();

        $purchases = [];

        if ( $use_hpos ) {
            $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->prepare(
                    "SELECT o.id, o.customer_id, o.billing_first_name, o.billing_last_name, o.billing_city,
                            oi.order_item_name
                     FROM {$wpdb->prefix}wc_orders o
                     JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_id = o.id
                     WHERE o.status = %s AND o.date_created_gmt > (UTC_TIMESTAMP() - INTERVAL 24 HOUR)
                     ORDER BY o.date_created_gmt DESC LIMIT 10",
                    'wc-completed'
                ),
                ARRAY_A
            );
        } else {
            // Legacy (CPT-based) order storage.
            $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->prepare(
                    "SELECT p.ID AS id, p.post_author AS customer_id,
                            MAX(CASE WHEN pm.meta_key = '_billing_first_name' THEN pm.meta_value END) AS billing_first_name,
                            MAX(CASE WHEN pm.meta_key = '_billing_last_name'  THEN pm.meta_value END) AS billing_last_name,
                            MAX(CASE WHEN pm.meta_key = '_billing_city'       THEN pm.meta_value END) AS billing_city,
                            oi.order_item_name AS order_item_name
                     FROM {$wpdb->prefix}posts p
                     JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_id = p.ID
                     LEFT JOIN {$wpdb->prefix}postmeta pm ON pm.post_id = p.ID
                        AND pm.meta_key IN ('_billing_first_name','_billing_last_name','_billing_city')
                     WHERE p.post_type = 'shop_order' AND p.post_status = %s
                       AND p.post_date_gmt > (UTC_TIMESTAMP() - INTERVAL 24 HOUR)
                     GROUP BY p.ID, oi.order_item_name
                     ORDER BY p.post_date_gmt DESC LIMIT 10",
                    'wc-completed'
                ),
                ARRAY_A
            );
        }

        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $first = (string) ( $row['billing_first_name'] ?? '' );
                $last  = (string) ( $row['billing_last_name'] ?? '' );
                // Anonymize: "Juan Pérez" → "Juan P."
                $name = trim( $first );
                if ( $last !== '' ) {
                    $name .= ' ' . mb_substr( $last, 0, 1 ) . '.';
                }
                $purchases[] = [
                    'name'    => $name,
                    'city'    => (string) ( $row['billing_city'] ?? '' ),
                    'product' => (string) ( $row['order_item_name'] ?? '' ),
                ];
            }
        }

        wp_send_json_success( [ 'purchases' => $purchases ] );
    }

    // -------------------------------------------------------------------------
    // h. ltms_submit_review
    // -------------------------------------------------------------------------

    /**
     * AJAX: ltms_submit_review
     *
     * Inserts a product review via the WP comment system. Reviews are stored
     * as approved comments with a `rating` comment-meta (1-5). When the
     * reviewer is logged in the comment is attributed to their user account;
     * otherwise the display name is taken from POST.
     *
     * @return void
     */
    public static function ajax_submit_review(): void {
        check_ajax_referer( 'ltms_ux_nonce', 'nonce' );

        $product_id = absint( $_POST['product_id'] ?? 0 );
        if ( ! $product_id ) {
            wp_send_json_error( [ 'message' => __( 'Producto no especificado', 'ltms' ) ], 400 );
        }

        $rating = absint( $_POST['rating'] ?? 0 );
        if ( $rating < 1 || $rating > 5 ) {
            wp_send_json_error( [ 'message' => __( 'Calificación inválida (debe ser 1-5)', 'ltms' ) ], 400 );
        }

        $comment = sanitize_textarea_field( wp_unslash( $_POST['comment'] ?? '' ) );
        if ( '' === $comment ) {
            wp_send_json_error( [ 'message' => __( 'El comentario no puede estar vacío', 'ltms' ) ], 400 );
        }

        $user = wp_get_current_user();
        $author = $user->ID ? $user->display_name : ( sanitize_text_field( $_POST['author'] ?? 'Cliente' ) );
        $email  = $user->ID ? $user->user_email   : ( sanitize_email( $_POST['email'] ?? '' ) );

        $comment_id = wp_insert_comment( [
            'comment_post_ID'      => $product_id,
            'comment_author'       => $author,
            'comment_author_email' => $email,
            'comment_author_url'   => '',
            'comment_content'      => $comment,
            'comment_type'         => 'review',
            'comment_parent'       => 0,
            'user_id'              => $user->ID ?: 0,
            'comment_approved'     => 1,
            'comment_meta'         => [
                'rating' => $rating,
            ],
        ] );

        if ( ! $comment_id || is_wp_error( $comment_id ) ) {
            wp_send_json_error( [ 'message' => __( 'No se pudo guardar la reseña', 'ltms' ) ], 500 );
        }

        // Recalculate product rating average so the storefront reflects the new review.
        if ( $product = wc_get_product( $product_id ) ) {
            // WooCommerce hooks into transition_post_status to recompute.
            clean_post_cache( $product_id );
        }

        wp_send_json_success( [
            'message'     => __( 'Reseña enviada', 'ltms' ),
            'comment_id'  => (int) $comment_id,
        ] );
    }

    // -------------------------------------------------------------------------
    // i. ltms_waitlist_subscribe
    // -------------------------------------------------------------------------

    /**
     * AJAX: ltms_waitlist_subscribe
     *
     * Records a back-in-stock subscription in the `lt_waitlist` custom table.
     * If the table does not exist (plugin upgrade path), it is created on
     * demand so the customer's signup is never silently dropped.
     *
     * @return void
     */
    public static function ajax_waitlist_subscribe(): void {
        check_ajax_referer( 'ltms_ux_nonce', 'nonce' );

        $product_id = absint( $_POST['product_id'] ?? 0 );
        if ( ! $product_id ) {
            wp_send_json_error( [ 'message' => __( 'Producto no especificado', 'ltms' ) ], 400 );
        }

        $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        if ( ! is_email( $email ) ) {
            wp_send_json_error( [ 'message' => __( 'Email inválido', 'ltms' ) ], 400 );
        }

        $phone = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );

        global $wpdb;
        $table = $wpdb->prefix . 'lt_waitlist';

        // Ensure the waitlist table exists — avoids silent data loss when the
        // plugin is upgraded before the migration runs.
        self::ensure_waitlist_table( $table );

        // De-duplicate — same email + product should not get multiple rows.
        $existing = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE product_id = %d AND email = %s LIMIT 1",
                $product_id,
                $email
            )
        );

        if ( $existing ) {
            wp_send_json_success( [
                'message' => __( 'Ya estás en la lista de espera para este producto', 'ltms' ),
                'id'      => (int) $existing,
            ] );
        }

        $inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $table,
            [
                'product_id' => $product_id,
                'email'      => $email,
                'phone'      => $phone,
                'created_at' => current_time( 'mysql', true ),
            ],
            [ '%d', '%s', '%s', '%s' ]
        );

        if ( false === $inserted ) {
            wp_send_json_error( [ 'message' => __( 'No se pudo registrar en la lista de espera', 'ltms' ) ], 500 );
        }

        wp_send_json_success( [
            'message' => __( 'Te avisaremos cuando esté disponible', 'ltms' ),
            'id'      => (int) $wpdb->insert_id,
        ] );
    }

    /**
     * Creates the waitlist table on demand if it does not exist.
     *
     * @param string $table Full table name (with prefix).
     * @return void
     */
    private static function ensure_waitlist_table( string $table ): void {
        global $wpdb;

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            return;
        }

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            email VARCHAR(255) NOT NULL,
            phone VARCHAR(64) DEFAULT '',
            created_at DATETIME NOT NULL,
            notified_at DATETIME DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY product_email (product_id, email(191))
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    // -------------------------------------------------------------------------
    // j. ltms_search_autocomplete
    // -------------------------------------------------------------------------

    /**
     * AJAX: ltms_search_autocomplete
     *
     * Returns up to 10 published products matching the query string. Each
     * result contains id / name / price / image / url. Designed for the live
     * search dropdown in the storefront header.
     *
     * @return void
     */
    public static function ajax_search_autocomplete(): void {
        check_ajax_referer( 'ltms_ux_nonce', 'nonce' );

        $query = sanitize_text_field( wp_unslash( $_POST['query'] ?? '' ) );
        if ( mb_strlen( $query ) < 2 ) {
            wp_send_json_success( [ 'results' => [], 'products' => [] ] );
        }

        if ( ! function_exists( 'wc_get_products' ) ) {
            wp_send_json_success( [ 'results' => [], 'products' => [] ] );
        }

        $args = [
            'limit'   => 10,
            'status'  => 'publish',
            'orderby' => 'title',
            'order'   => 'ASC',
            's'       => $query,
        ];
        $products = wc_get_products( $args );

        $results = [];
        foreach ( $products as $p ) {
            $results[] = [
                'id'    => $p->get_id(),
                'name'  => $p->get_name(),
                'price' => $p->get_price_html(),
                'image' => wp_get_attachment_image_url( $p->get_image_id(), 'thumbnail' ) ?: '',
                'url'   => $p->get_permalink(),
            ];
        }

        // The JS search-autocomplete module expects `products` and
        // `categories` keys (see assets/js/ltms-ux-enhancements.js →
        // showResults). Return both `results` (per the task spec) and
        // `products` (what the JS UI actually consumes) for compatibility.
        wp_send_json_success( [
            'results'    => $results,
            'products'   => $results,
            'categories' => [],
            'popular'    => [],
        ] );
    }

} // end class LTMS_Frontend_Checkout_Handler