<?php
/**
 * Template: Checkout — Plaza Viva Design System
 *
 * Checkout nativo de WooCommerce multi-vendor con flujo de 4 pasos.
 * Reemplaza al template de WC vía `template_include` (ver
 * LTMS_Native_Templates::maybe_override() — integra checkout.php cuando
 * is_checkout()).
 *
 * Pasos:
 *  1. Contacto (email, teléfono).
 *  2. Dirección (nombre, dirección, ciudad, postal).
 *  3. Envío (radio cards: Deprisa, Aveonline, Heka, Recogida).
 *  4. Pago (radio cards: PSE, Nequi, Daviplata, Tarjeta, Contra entrega).
 *
 * Características:
 *  - Layout 2 columnas (form izquierda + order review sticky derecha) → 1 col móvil.
 *  - Checkbox "misma dirección de facturación".
 *  - Escrow disclosure visible en el summary.
 *  - CTA "Confirmar pedido" con loading state.
 *  - Touch targets ≥44px, responsive, sin jQuery.
 *
 * Hooks WC estándar:
 *  - woocommerce_before_checkout_form / woocommerce_after_checkout_form
 *  - woocommerce_checkout_billing / woocommerce_checkout_shipping
 *  - woocommerce_checkout_order_review (summary)
 *
 * @package LTMS
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Salida directa no permitida.
}

// Garantizar que WooCommerce está cargado.
if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->checkout ) {
    get_header( 'shop' );
    echo '<div class="pv-scope pv-checkout"><main class="pv-section" style="padding:60px 22px;text-align:center"><p>' . esc_html__( 'WooCommerce no está activo o el checkout no está disponible.', 'ltms' ) . '</p></main></div>';
    get_footer( 'shop' );
    return;
}

// Prevent direct access if cart is empty (excepto order-received endpoint).
if ( WC()->cart->is_empty() && ! is_wc_endpoint_url( 'order-received' ) ) {
    wp_redirect( wc_get_cart_url() );
    exit;
}

$checkout = WC()->checkout();

/* ---------------------------------------------------------------------------
 * 1. Métodos de pago disponibles — WC()->payment_gateways().
 * ------------------------------------------------------------------------- */
$available_gateways = array();
if ( WC()->payment_gateways() ) {
    $available_gateways = WC()->payment_gateways()->get_available_payment_gateways();
}

// Mapeo de iconos (emoji / svg path) para radio cards de pago.
$payment_meta = apply_filters( 'ltms_checkout_payment_meta', array(
    'pse'             => array( 'label' => __( 'PSE', 'ltms' ), 'sub' => __( 'Bancolombia, Davivienda, Bogotá…', 'ltms' ), 'icon' => '🏦', 'badge' => __( '0% interés', 'ltms' ) ),
    'nequi'           => array( 'label' => __( 'Nequi', 'ltms' ), 'sub' => __( 'Pago con app Nequi', 'ltms' ), 'icon' => '📲', 'badge' => '' ),
    'daviplata'       => array( 'label' => __( 'Daviplata', 'ltms' ), 'sub' => __( 'Pago con app Daviplata', 'ltms' ), 'icon' => '💳', 'badge' => '' ),
    'card'            => array( 'label' => __( 'Tarjeta de crédito/débito', 'ltms' ), 'sub' => __( 'Visa, Mastercard, Amex', 'ltms' ), 'icon' => '💳', 'badge' => '' ),
    'cod'             => array( 'label' => __( 'Contra entrega', 'ltms' ), 'sub' => __( 'Paga en efectivo al recibir', 'ltms' ), 'icon' => '💵', 'badge' => __( 'Verificación', 'ltms' ) ),
    'woo_nequi'       => array( 'label' => __( 'Nequi', 'ltms' ), 'sub' => __( 'Pago con app Nequi', 'ltms' ), 'icon' => '📲', 'badge' => '' ),
    'woo_daviplata'   => array( 'label' => __( 'Daviplata', 'ltms' ), 'sub' => __( 'Pago con app Daviplata', 'ltms' ), 'icon' => '💳', 'badge' => '' ),
) );

/* ---------------------------------------------------------------------------
 * 2. Métodos de envío — WC()->shipping->get_packages().
 *    Cada package puede tener varias rates; tomamos el primero para radio cards.
 * ------------------------------------------------------------------------- */
$shipping_packages = array();
if ( WC()->shipping ) {
    $shipping_packages = WC()->shipping->get_packages();
}

// Mapeo visual para carriers colombianos.
$carrier_meta = apply_filters( 'ltms_checkout_carrier_meta', array(
    'deprisa'    => array( 'label' => __( 'Deprisa', 'ltms' ), 'sub' => __( '2-4 días hábiles', 'ltms' ), 'icon' => '📦', 'badge' => __( 'Recomendado', 'ltms' ) ),
    'aveonline'  => array( 'label' => __( 'Aveonline', 'ltms' ), 'sub' => __( '1-3 días hábiles', 'ltms' ), 'icon' => '🚚', 'badge' => '' ),
    'heka'       => array( 'label' => __( 'Heka', 'ltms' ), 'sub' => __( '2-5 días hábiles', 'ltms' ), 'icon' => '🛵', 'badge' => '' ),
    'local_pickup' => array( 'label' => __( 'Recogida en tienda', 'ltms' ), 'sub' => __( 'Disponible en 24h', 'ltms' ), 'icon' => '🏪', 'badge' => __( 'Gratis', 'ltms' ) ),
    'flat_rate'  => array( 'label' => __( 'Envío estándar', 'ltms' ), 'sub' => __( '3-5 días hábiles', 'ltms' ), 'icon' => '📦', 'badge' => '' ),
    'free_shipping' => array( 'label' => __( 'Envío gratis', 'ltms' ), 'sub' => __( 'Aplicado por tu cupón', 'ltms' ), 'icon' => '🎉', 'badge' => __( 'Gratis', 'ltms' ) ),
) );

/**
 * Hook: ltms_before_checkout_plazaviva
 */
do_action( 'ltms_before_checkout_plazaviva' );

/**
 * Wrapper del tema — woocommerce_before_main_content.
 * Desenganchamos breadcrumb para renderizarlo explícitamente dentro del scope.
 */
$pv_breadcrumb_was_hooked = has_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb' );
if ( $pv_breadcrumb_was_hooked ) {
    remove_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 );
}
do_action( 'woocommerce_before_main_content' );

get_header( 'shop' );
?>

<div class="pv-scope pv-checkout">

    <?php
    /**
     * Hook: woocommerce_before_checkout_form
     * Imprime notices, login form (si no logueado), y coupon form nativo.
     * Priority 10: woocommerce_checkout_login_form (si no logueado).
     * Priority 10: woocommerce_checkout_coupon_form.
     */
    do_action( 'woocommerce_before_checkout_form', $checkout );
    ?>

    <!-- ===================================================================
         BREADCRUMB
         =================================================================== -->
    <nav class="pv-checkout__breadcrumb pv-section pv-section--tight" aria-label="<?php esc_attr_e( 'Migas de navegación', 'ltms' ); ?>">
        <?php woocommerce_breadcrumb(); ?>
    </nav>

    <!-- ===================================================================
         HEADER: Título + Stepper indicator
         =================================================================== -->
    <header class="pv-checkout__header pv-section">
        <div class="pv-checkout__header-inner">
            <div>
                <h1 class="pv-checkout__title"><?php esc_html_e( 'Finalizar compra', 'ltms' ); ?></h1>
                <p class="pv-checkout__sub">
                    <?php esc_html_e( 'Completa tus datos en 4 pasos simples. Tus fondos están protegidos por escrow.', 'ltms' ); ?>
                </p>
            </div>
            <span class="pv-badge pv-badge--verified pv-badge--lg pv-checkout__secure-badge">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l8 4v6c0 5-3.4 8.5-8 10-4.6-1.5-8-5-8-10V6l8-4z" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <?php esc_html_e( 'Pago seguro', 'ltms' ); ?>
            </span>
        </div>

        <!-- Stepper visual (4 pasos) -->
        <ol class="pv-checkout__stepper" role="list" aria-label="<?php esc_attr_e( 'Pasos del checkout', 'ltms' ); ?>">
            <li class="pv-checkout__stepper-step is-active" data-step="1">
                <span class="pv-checkout__stepper-num">1</span>
                <span class="pv-checkout__stepper-label"><?php esc_html_e( 'Contacto', 'ltms' ); ?></span>
            </li>
            <li class="pv-checkout__stepper-step" data-step="2">
                <span class="pv-checkout__stepper-num">2</span>
                <span class="pv-checkout__stepper-label"><?php esc_html_e( 'Dirección', 'ltms' ); ?></span>
            </li>
            <li class="pv-checkout__stepper-step" data-step="3">
                <span class="pv-checkout__stepper-num">3</span>
                <span class="pv-checkout__stepper-label"><?php esc_html_e( 'Envío', 'ltms' ); ?></span>
            </li>
            <li class="pv-checkout__stepper-step" data-step="4">
                <span class="pv-checkout__stepper-num">4</span>
                <span class="pv-checkout__stepper-label"><?php esc_html_e( 'Pago', 'ltms' ); ?></span>
            </li>
        </ol>
    </header>

    <!-- ===================================================================
         LAYOUT: 2 columnas (form izquierda + order review sticky derecha)
         =================================================================== -->
    <main class="pv-checkout__main pv-section" role="main">

        <?php
        /**
         * Form de checkout — checkout.php nativo usa name="checkout"
         * method=post enctype=multipart/form-data action=wc_get_checkout_url().
         * WC intercepta el POST para crear la orden.
         */
        ?>
        <form name="checkout" class="pv-checkout__form checkout woocommerce-checkout" method="post" enctype="multipart/form-data" action="<?php echo esc_url( wc_get_checkout_url() ); ?>">

            <?php if ( ! $checkout->is_registration_enabled() && $checkout->is_registration_required() && ! is_user_logged_in() ) : ?>
                <?php
                echo wp_kses_post(
                    apply_filters(
                        'woocommerce_checkout_must_be_logged_in_message',
                        __( 'Debes iniciar sesión para finalizar la compra.', 'ltms' )
                    )
                );
                ?>
            <?php else : ?>

                <div class="pv-checkout__layout">

                    <!-- ===========================================================
                         COLUMNA IZQUIERDA: 4 pasos
                         =========================================================== -->
                    <div class="pv-checkout__form-col">

                        <!-- ============================================
                             STEP 1: CONTACTO (email, teléfono)
                             ============================================ -->
                        <section class="pv-checkout__step pv-checkout__step--1" data-step-block="1" aria-labelledby="pv-checkout-step-1-title">
                            <header class="pv-checkout__step-head">
                                <h2 class="pv-checkout__step-title" id="pv-checkout-step-1-title">
                                    <span class="pv-checkout__step-num">1</span>
                                    <?php esc_html_e( 'Información de contacto', 'ltms' ); ?>
                                </h2>
                                <span class="pv-badge pv-badge--dot pv-badge--trust"><?php esc_html_e( 'Requerido', 'ltms' ); ?></span>
                            </header>

                            <div class="pv-checkout__step-body">
                                <?php
                                /**
                                 * Hook: woocommerce_checkout_billing
                                 * Renderiza los campos de facturación: email, teléfono,
                                 * nombre, apellido, etc. WC usa su template
                                 * checkout/form-billing.php.
                                 *
                                 * Filtramos para forzar que email y teléfono aparezcan
                                 * primero (via woocommerce_billing_fields).
                                 */
                                ?>
                                <div class="pv-checkout__contact-fields">
                                    <?php
                                    // Si el usuario no está logueado, mostrar login form opcional.
                                    if ( ! is_user_logged_in() && function_exists( 'woocommerce_checkout_login_form' ) ) {
                                        echo '<details class="pv-checkout__login-toggle"><summary>' . esc_html__( '¿Ya tienes cuenta? Inicia sesión', 'ltms' ) . '</summary><div class="pv-checkout__login-body">';
                                        woocommerce_checkout_login_form();
                                        echo '</div></details>';
                                    }
                                    ?>

                                    <?php
                                    // Email field (prioridad alta).
                                    $email_value = $checkout->get_value( 'billing_email' );
                                    ?>
                                    <div class="pv-field pv-checkout__field">
                                        <label for="billing_email"><?php esc_html_e( 'Correo electrónico', 'ltms' ); ?> <span class="pv-checkout__req" aria-hidden="true">*</span></label>
                                        <input type="email" id="billing_email" name="billing_email" class="pv-input input-text" value="<?php echo esc_attr( $email_value ); ?>" placeholder="<?php esc_attr_e( 'tucorreo@ejemplo.com', 'ltms' ); ?>" autocomplete="email" required />
                                        <span class="pv-field__hint"><?php esc_html_e( 'Te enviaremos la confirmación y el recibo a este correo.', 'ltms' ); ?></span>
                                    </div>

                                    <?php
                                    // Phone field.
                                    $phone_value = $checkout->get_value( 'billing_phone' );
                                    ?>
                                    <div class="pv-field pv-checkout__field">
                                        <label for="billing_phone"><?php esc_html_e( 'Teléfono / WhatsApp', 'ltms' ); ?> <span class="pv-checkout__req" aria-hidden="true">*</span></label>
                                        <input type="tel" id="billing_phone" name="billing_phone" class="pv-input input-text" value="<?php echo esc_attr( $phone_value ); ?>" placeholder="<?php esc_attr_e( '+57 300 000 0000', 'ltms' ); ?>" autocomplete="tel" required />
                                        <span class="pv-field__hint"><?php esc_html_e( 'Lo usaremos para coordinar la entrega con el repartidor.', 'ltms' ); ?></span>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <!-- ============================================
                             STEP 2: DIRECCIÓN (nombre, dirección, ciudad, postal)
                             ============================================ -->
                        <section class="pv-checkout__step pv-checkout__step--2" data-step-block="2" aria-labelledby="pv-checkout-step-2-title">
                            <header class="pv-checkout__step-head">
                                <h2 class="pv-checkout__step-title" id="pv-checkout-step-2-title">
                                    <span class="pv-checkout__step-num">2</span>
                                    <?php esc_html_e( 'Dirección de envío', 'ltms' ); ?>
                                </h2>
                                <span class="pv-badge pv-badge--dot pv-badge--trust"><?php esc_html_e( 'Requerido', 'ltms' ); ?></span>
                            </header>

                            <div class="pv-checkout__step-body">
                                <?php
                                /**
                                 * Hook: woocommerce_checkout_billing
                                 * Renderiza los campos restantes de facturación
                                 * (first_name, last_name, address_1, city, postcode, etc.).
                                 * El orden lo define woocommerce_billing_fields filter.
                                 */
                                do_action( 'woocommerce_checkout_billing' );
                                ?>

                                <!-- Checkbox: misma dirección de facturación -->
                                <label class="pv-checkout__ship-toggle">
                                    <input type="checkbox" name="ship_to_different_address" id="ship_to_different_address" value="1" <?php checked( apply_filters( 'woocommerce_ship_to_different_address_checked', 'shipping' === get_option( 'woocommerce_ship_to_destination' ) ), true ); ?> />
                                    <span class="pv-checkout__ship-toggle-mark" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 13l4 4L19 7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    </span>
                                    <span class="pv-checkout__ship-toggle-text">
                                        <?php esc_html_e( 'Mi dirección de facturación es diferente a la de envío', 'ltms' ); ?>
                                    </span>
                                </label>

                                <?php
                                /**
                                 * Hook: woocommerce_checkout_shipping
                                 * Renderiza los campos de envío si el checkbox
                                 * ship_to_different_address está activo. WC lo maneja
                                 * con display:none/show en JS nativo.
                                 */
                                do_action( 'woocommerce_checkout_shipping' );
                                ?>
                            </div>
                        </section>

                        <!-- ============================================
                             STEP 3: ENVÍO (radio cards)
                             ============================================ -->
                        <section class="pv-checkout__step pv-checkout__step--3" data-step-block="3" aria-labelledby="pv-checkout-step-3-title">
                            <header class="pv-checkout__step-head">
                                <h2 class="pv-checkout__step-title" id="pv-checkout-step-3-title">
                                    <span class="pv-checkout__step-num">3</span>
                                    <?php esc_html_e( 'Método de envío', 'ltms' ); ?>
                                </h2>
                                <span class="pv-badge pv-badge--dot pv-badge--gold"><?php esc_html_e( 'Elige uno', 'ltms' ); ?></span>
                            </header>

                            <div class="pv-checkout__step-body">
                                <?php
                                if ( empty( $shipping_packages ) ) :
                                    ?>
                                    <div class="pv-checkout__no-shipping">
                                        <svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01" stroke-linecap="round"/></svg>
                                        <div>
                                            <p style="margin:0 0 6px;font-weight:600;color:#1A1F2E;font-size:14px;"><?php esc_html_e( 'Aún no calculamos el envío', 'ltms' ); ?></p>
                                            <p style="margin:0;color:#565C66;font-size:13px;line-height:1.45;"><?php esc_html_e( 'Completa tu dirección de envío arriba (departamento, municipio, dirección) y las opciones de envío aparecerán automáticamente aquí.', 'ltms' ); ?></p>
                                        </div>
                                    </div>
                                    <?php
                                else :
                                    $ship_idx = 0;
                                    $has_rates = false;
                                    foreach ( $shipping_packages as $i => $package ) :
                                        if ( empty( $package['rates'] ) ) {
                                            continue;
                                        }
                                        $has_rates = true;
                                        ?>
                                        <ul class="pv-shipping-options" role="radiogroup" aria-label="<?php esc_attr_e( 'Opciones de envío', 'ltms' ); ?>">
                                            <?php foreach ( $package['rates'] as $rate ) :
                                                $method_id      = $rate->get_id();
                                                $method_method  = $rate->get_method_id();
                                                $method_inst    = $rate->get_instance_id();
                                                $method_label   = $rate->get_label();
                                                $method_cost    = $rate->get_cost();
                                                $is_free        = ( 0 === (float) $method_cost || $rate->get_method_id() === 'free_shipping' );

                                                // Lookup de metadata visual (carrier_meta por method_id).
                                                $meta = isset( $carrier_meta[ $method_method ] ) ? $carrier_meta[ $method_method ] : null;
                                                $icon = $meta ? $meta['icon'] : '📦';
                                                $sub  = $meta ? $meta['sub'] : '';
                                                $badge = $meta ? $meta['badge'] : '';
                                                $carrier_label = $meta ? $meta['label'] : $method_label;

                                                $input_id = 'shipping_method_' . $i . '_' . sanitize_title( $method_id );
                                                $checked  = ( $ship_idx === 0 ); // Primera opción por defecto.
                                                $ship_idx++;
                                                ?>
                                                <li class="pv-shipping-option <?php echo $checked ? 'is-selected' : ''; ?>">
                                                    <input type="radio" name="shipping_method[<?php echo esc_attr( $i ); ?>]" id="<?php echo esc_attr( $input_id ); ?>" value="<?php echo esc_attr( $method_id ); ?>" class="shipping_method pv-shipping-option__input" <?php checked( $checked ); ?> data-pv-shipping-radio />
                                                    <label for="<?php echo esc_attr( $input_id ); ?>" class="pv-shipping-option__label">
                                                        <span class="pv-shipping-option__icon" aria-hidden="true"><?php echo esc_html( $icon ); ?></span>
                                                        <span class="pv-shipping-option__info">
                                                            <span class="pv-shipping-option__name"><?php echo esc_html( $carrier_label ); ?></span>
                                                            <span class="pv-shipping-option__sub"><?php echo esc_html( $sub ? $sub : $method_label ); ?></span>
                                                        </span>
                                                        <?php if ( $badge ) : ?>
                                                            <span class="pv-badge pv-badge--gold"><?php echo esc_html( $badge ); ?></span>
                                                        <?php endif; ?>
                                                        <span class="pv-shipping-option__price">
                                                            <?php echo $is_free ? '<span class="pv-shipping-option__free">' . esc_html__( 'Gratis', 'ltms' ) . '</span>' : wp_kses_post( wc_price( $method_cost ) ); ?>
                                                        </span>
                                                    </label>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endforeach; ?>
                                    <?php
                                    // v2.9.215: Si hay packages pero ninguno con rates, mostrar aviso amigable.
                                    if ( ! $has_rates ) :
                                        ?>
                                        <div class="pv-checkout__no-shipping">
                                            <svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01" stroke-linecap="round"/></svg>
                                            <div>
                                                <p style="margin:0 0 6px;font-weight:600;color:#1A1F2E;font-size:14px;"><?php esc_html_e( 'Aún no calculamos el envío', 'ltms' ); ?></p>
                                                <p style="margin:0;color:#565C66;font-size:13px;line-height:1.45;"><?php esc_html_e( 'Completa tu dirección de envío arriba (departamento, municipio, dirección) y las opciones de envío aparecerán automáticamente aquí.', 'ltms' ); ?></p>
                                            </div>
                                        </div>
                                        <?php
                                    endif;
                                    ?>
                                <?php endif; ?>
                            </div>
                        </section>

                        <!-- ============================================
                             STEP 4: PAGO (radio cards)
                             ============================================ -->
                        <section class="pv-checkout__step pv-checkout__step--4" data-step-block="4" aria-labelledby="pv-checkout-step-4-title">
                            <header class="pv-checkout__step-head">
                                <h2 class="pv-checkout__step-title" id="pv-checkout-step-4-title">
                                    <span class="pv-checkout__step-num">4</span>
                                    <?php esc_html_e( 'Método de pago', 'ltms' ); ?>
                                </h2>
                                <span class="pv-badge pv-badge--dot pv-badge--trust"><?php esc_html_e( 'Pago en custodia', 'ltms' ); ?></span>
                            </header>

                            <div class="pv-checkout__step-body">
                                <?php if ( empty( $available_gateways ) ) : ?>
                                    <div class="pv-checkout__no-payment">
                                        <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01" stroke-linecap="round"/></svg>
                                        <p><?php esc_html_e( 'No hay métodos de pago configurados. Contacta al administrador.', 'ltms' ); ?></p>
                                    </div>
                                <?php else : ?>
                                    <ul class="pv-payment-options" role="radiogroup" aria-label="<?php esc_attr_e( 'Métodos de pago', 'ltms' ); ?>">
                                        <?php
                                        $pay_idx = 0;
                                        foreach ( $available_gateways as $gateway_id => $gateway ) :
                                            $meta  = isset( $payment_meta[ $gateway_id ] ) ? $payment_meta[ $gateway_id ] : null;
                                            $icon  = $meta ? $meta['icon'] : '💳';
                                            $sub   = $meta ? $meta['sub'] : '';
                                            $badge = $meta ? $meta['badge'] : '';
                                            $label = $meta ? $meta['label'] : $gateway->get_title();
                                            $input_id = 'payment_method_' . $gateway_id;
                                            $checked  = ( $gateway->chosen ) || ( $pay_idx === 0 );
                                            $pay_idx++;
                                            ?>
                                            <li class="pv-payment-option <?php echo $checked ? 'is-selected' : ''; ?>" data-pv-payment-option="<?php echo esc_attr( $gateway_id ); ?>">
                                                <input type="radio" name="payment_method" id="<?php echo esc_attr( $input_id ); ?>" value="<?php echo esc_attr( $gateway_id ); ?>" class="input-radio pv-payment-option__input" <?php checked( $checked ); ?> data-pv-payment-radio />
                                                <label for="<?php echo esc_attr( $input_id ); ?>" class="pv-payment-option__label">
                                                    <span class="pv-payment-option__icon" aria-hidden="true"><?php echo esc_html( $icon ); ?></span>
                                                    <span class="pv-payment-option__info">
                                                        <span class="pv-payment-option__name"><?php echo esc_html( $label ); ?></span>
                                                        <?php if ( $sub ) : ?>
                                                            <span class="pv-payment-option__sub"><?php echo esc_html( $sub ); ?></span>
                                                        <?php endif; ?>
                                                    </span>
                                                    <?php if ( $badge ) : ?>
                                                        <span class="pv-badge pv-badge--trust"><?php echo esc_html( $badge ); ?></span>
                                                    <?php endif; ?>
                                                    <span class="pv-payment-option__check" aria-hidden="true">
                                                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 13l4 4L19 7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                                    </span>
                                                </label>

                                                <?php
                                                /**
                                                 * Hook: woocommerce_payment_gateway_description
                                                 * Permite que el gateway renderice su form adicional
                                                 * (ej. campos de tarjeta) bajo la radio card.
                                                 *
                                                 * v2.9.215: NO imprimir $gateway->get_description() aquí
                                                 * porque payment_fields() ya la imprime internamente
                                                 * (ver class-ltms-api-gateways.php línea 167-169).
                                                 * Antes se imprimía DOS VECES ("Paga de forma segura
                                                 * con Openpay." aparecía duplicado).
                                                 */
                                                if ( $gateway->has_fields() || $gateway->get_description() ) :
                                                    echo '<div class="pv-payment-option__fields" data-pv-payment-fields="' . esc_attr( $gateway_id ) . '"' . ( $checked ? '' : ' hidden' ) . '>';
                                                    if ( $gateway->has_fields() ) {
                                                        $gateway->payment_fields();
                                                    } elseif ( $gateway->get_description() ) {
                                                        // Solo imprimir la descripción si el gateway NO tiene
                                                        // payment_fields() propio (que ya la imprimiría).
                                                        echo '<p class="pv-payment-option__desc">' . wp_kses_post( wpautop( $gateway->get_description() ) ) . '</p>';
                                                    }
                                                    echo '</div>';
                                                endif;
                                                ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>

                                <!-- Términos y condiciones (si WC requiere aceptación) -->
                                <?php
                                if ( function_exists( 'wc_terms_and_conditions_checkbox_enabled' ) && wc_terms_and_conditions_checkbox_enabled() ) :
                                    ?>
                                    <label class="pv-checkout__terms-toggle">
                                        <input type="checkbox" name="terms" id="terms" value="1" <?php checked( apply_filters( 'woocommerce_terms_is_checked_default', isset( $_POST['terms'] ) ), true ); ?> />
                                        <span class="pv-checkout__ship-toggle-mark" aria-hidden="true">
                                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 13l4 4L19 7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        </span>
                                        <span class="pv-checkout__ship-toggle-text">
                                            <?php
                                            printf(
                                                /* translators: %s: link a Términos y condiciones. */
                                                wp_kses_post( __( 'He leído y acepto los <a href="%s" target="_blank">Términos y condiciones</a>.', 'ltms' ) ),
                                                esc_url( get_permalink( wc_terms_and_conditions_page_id() ) )
                                            );
                                            ?>
                                        </span>
                                    </label>
                                <?php endif; ?>
                            </div>
                        </section>

                        <!-- ============================================
                             ESCROW DISCLOSURE (cierre del form)
                             ============================================ -->
                        <div class="pv-escrow-notice pv-checkout__escrow" role="note">
                            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2l8 4v6c0 5-3.4 8.5-8 10-4.6-1.5-8-5-8-10V6l8-4z" stroke-linecap="round" stroke-linejoin="round"/><path d="M9 12l2 2 4-4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <div class="pv-escrow-notice__body">
                                <strong><?php esc_html_e( 'Tus fondos están protegidos', 'ltms' ); ?></strong>
                                <p><?php esc_html_e( 'El pago se mantiene en custodia (escrow) hasta que confirmes la recepción del pedido. Solo entonces se libera al vendedor.', 'ltms' ); ?></p>
                            </div>
                        </div>

                        <!-- ============================================
                             CTA: CONFIRMAR PEDIDO
                             ============================================ -->
                        <div class="pv-checkout__cta-wrap">
                            <?php
                            /**
                             * v2.9.220: Hook woocommerce_review_order_before_submit.
                             * Necesario para que LTMS_Frontend_Checkout_Handler::add_privacy_consent_field()
                             * renderice el checkbox de consentimiento (Ley 1581/2012).
                             * Sin este hook, el checkbox NUNCA se muestra pero la validación
                             * validate_privacy_consent() falla con 'Debes aceptar la Política
                             * de Tratamiento de Datos Personales para continuar.'
                             */
                            do_action( 'woocommerce_review_order_before_submit' );

                            /**
                             * Nonce de WC para checkout.
                             * woocommerce_checkout_create_account, etc.
                             */
                            wp_nonce_field( 'woocommerce-process_checkout', 'woocommerce-process-checkout-nonce' );
                            ?>
                            <button type="submit" class="pv-btn pv-btn--brand pv-btn--lg pv-btn--block pv-checkout__submit" name="woocommerce_checkout_place_order" id="place_order" value="<?php esc_attr_e( 'Confirmar pedido', 'ltms' ); ?>" data-value="<?php esc_attr_e( 'Confirmar pedido', 'ltms' ); ?>">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 13l4 4L19 7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <span class="pv-checkout__submit-label"><?php esc_html_e( 'Confirmar pedido', 'ltms' ); ?></span>
                                <span class="pv-checkout__submit-total"><?php echo wp_kses_post( WC()->cart->get_total() ); ?></span>
                            </button>
                            <p class="pv-checkout__legal">
                                <?php esc_html_e( 'Al confirmar tu pedido aceptas nuestra política de devoluciones y privacidad.', 'ltms' ); ?>
                            </p>
                        </div>
                    </div><!-- /.pv-checkout__form-col -->

                    <!-- ===========================================================
                         COLUMNA DERECHA: ORDER REVIEW sticky
                         =========================================================== -->
                    <aside class="pv-checkout__review-col" aria-label="<?php esc_attr_e( 'Resumen del pedido', 'ltms' ); ?>">
                        <div class="pv-checkout__review pv-card pv-card--pad-lg" data-pv-sticky>
                            <header class="pv-checkout__review-head">
                                <h2 class="pv-checkout__review-title"><?php esc_html_e( 'Tu pedido', 'ltms' ); ?></h2>
                                <a href="<?php echo esc_url( wc_get_cart_url() ); ?>" class="pv-checkout__review-edit">
                                    <?php esc_html_e( 'Editar', 'ltms' ); ?>
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9M16.5 3.5l4 4L7 21H3v-4L16.5 3.5z" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </a>
                            </header>

                            <?php
                            /**
                             * woocommerce_checkout_order_review()
                             * Renderiza la tabla de items + totals nativa de WC.
                             * Internamente invoca woocommerce_order_review hook.
                             */
                            woocommerce_checkout_order_review();
                            ?>

                            <!-- Cupón (en checkout también disponible) -->
                            <div class="pv-checkout__review-coupon">
                                <?php
                                if ( function_exists( 'woocommerce_checkout_coupon_form' ) ) {
                                    woocommerce_checkout_coupon_form();
                                }
                                ?>
                            </div>

                            <!-- Escrow mini-disclosure -->
                            <div class="pv-escrow-notice pv-escrow-notice--compact pv-checkout__review-escrow">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2l8 4v6c0 5-3.4 8.5-8 10-4.6-1.5-8-5-8-10V6l8-4z" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <span><?php esc_html_e( 'Pago protegido con escrow hasta que confirmes recepción.', 'ltms' ); ?></span>
                            </div>
                        </div>
                    </aside>

                </div><!-- /.pv-checkout__layout -->

            <?php endif; // end is_registration_required check ?>
        </form>

        <?php
        /**
         * Hook: woocommerce_after_checkout_form
         */
        do_action( 'woocommerce_after_checkout_form', $checkout );
        ?>
    </main>
</div><!-- /.pv-scope.pv-checkout -->

<?php
/**
 * Wrapper del tema — woocommerce_after_main_content.
 */
do_action( 'woocommerce_after_main_content' );
?>

<style>
/* ============================================================================
   CHECKOUT · Plaza Viva scoped styles
   ========================================================================== */
.pv-scope.pv-checkout{display:flex;flex-direction:column;gap:18px;padding-bottom:48px;}

/* Breadcrumb */
.pv-scope.pv-checkout .pv-checkout__breadcrumb{margin-top:8px;}

/* Header */
.pv-scope.pv-checkout .pv-checkout__header{display:flex;flex-direction:column;gap:18px;}
.pv-scope.pv-checkout .pv-checkout__header-inner{
    display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap;
}
.pv-scope.pv-checkout .pv-checkout__title{font-family:var(--display);font-weight:800;font-size:clamp(26px,3vw,36px);line-height:1.1;}
.pv-scope.pv-checkout .pv-checkout__sub{color:var(--text-2);font-size:14.5px;margin-top:6px;max-width:580px;}
.pv-scope.pv-checkout .pv-checkout__secure-badge{flex-shrink:0;}

/* Stepper */
.pv-scope.pv-checkout .pv-checkout__stepper{
    display:flex;align-items:center;gap:4px;
    padding:14px 18px;background:var(--surface);border:1px solid var(--border);
    border-radius:var(--r-card);box-shadow:var(--sh-1);
    overflow-x:auto;scrollbar-width:none;
}
.pv-scope.pv-checkout .pv-checkout__stepper::-webkit-scrollbar{display:none;}
.pv-scope.pv-checkout .pv-checkout__stepper-step{
    display:flex;align-items:center;gap:8px;flex-shrink:0;
    padding:6px 10px;border-radius:var(--r-sm);
    font-size:13.5px;color:var(--text-3);font-weight:600;
    transition:color var(--t),background var(--t);
}
.pv-scope.pv-checkout .pv-checkout__stepper-step:not(:last-child)::after{
    content:"";width:24px;height:2px;background:var(--border);margin-left:6px;border-radius:2px;
}
.pv-scope.pv-checkout .pv-checkout__stepper-step.is-active{color:var(--primary);background:var(--primary-50);}
.pv-scope.pv-checkout .pv-checkout__stepper-step.is-done{color:var(--accent);background:var(--accent-50);}
.pv-scope.pv-checkout .pv-checkout__stepper-step.is-done:not(:last-child)::after{background:var(--accent);}
.pv-scope.pv-checkout .pv-checkout__stepper-num{
    width:26px;height:26px;border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    background:var(--bg-2);color:var(--text-3);font-weight:700;font-size:12px;
    border:1px solid var(--border);
}
.pv-scope.pv-checkout .pv-checkout__stepper-step.is-active .pv-checkout__stepper-num{
    background:var(--primary);color:#fff;border-color:var(--primary);
}
.pv-scope.pv-checkout .pv-checkout__stepper-step.is-done .pv-checkout__stepper-num{
    background:var(--accent);color:#fff;border-color:var(--accent);
}
.pv-scope.pv-checkout .pv-checkout__stepper-label{white-space:nowrap;}

/* Layout 2 col → 1 col */
.pv-scope.pv-checkout .pv-checkout__layout{
    display:grid;grid-template-columns:minmax(0,1fr) 380px;gap:28px;align-items:start;
}

/* Form column */
.pv-scope.pv-checkout .pv-checkout__form-col{display:flex;flex-direction:column;gap:18px;min-width:0;}

/* Step block */
.pv-scope.pv-checkout .pv-checkout__step{
    background:var(--surface);border:1px solid var(--border);border-radius:var(--r-card);
    overflow:hidden;box-shadow:var(--sh-1);
    transition:box-shadow var(--t),border-color var(--t);
}
.pv-scope.pv-checkout .pv-checkout__step.is-active{border-color:var(--primary-100);box-shadow:var(--sh-2);}
.pv-scope.pv-checkout .pv-checkout__step-head{
    display:flex;align-items:center;justify-content:space-between;gap:12px;
    padding:16px 22px;background:var(--bg);border-bottom:1px solid var(--border);
}
.pv-scope.pv-checkout .pv-checkout__step-title{
    display:flex;align-items:center;gap:10px;
    font-family:var(--display);font-weight:700;font-size:16.5px;color:var(--text);margin:0;
}
.pv-scope.pv-checkout .pv-checkout__step-num{
    width:30px;height:30px;border-radius:50%;flex-shrink:0;
    display:flex;align-items:center;justify-content:center;
    background:var(--primary);color:#fff;font-weight:700;font-size:14px;
    font-family:var(--display);
}
.pv-scope.pv-checkout .pv-checkout__step-body{padding:20px 22px;display:flex;flex-direction:column;gap:14px;}

/* Contact fields */
.pv-scope.pv-checkout .pv-checkout__contact-fields{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.pv-scope.pv-checkout .pv-checkout__req{color:var(--danger);font-weight:700;}

/* Login toggle */
.pv-scope.pv-checkout .pv-checkout__login-toggle{
    border:1px solid var(--border);border-radius:var(--r-md);padding:0;background:var(--bg);
    grid-column:1 / -1;
}
.pv-scope.pv-checkout .pv-checkout__login-toggle summary{
    padding:12px 16px;cursor:pointer;font-weight:600;color:var(--primary);
    list-style:none;
}
.pv-scope.pv-checkout .pv-checkout__login-toggle summary::-webkit-details-marker{display:none;}
.pv-scope.pv-checkout .pv-checkout__login-toggle[open] summary{border-bottom:1px solid var(--border);}
.pv-scope.pv-checkout .pv-checkout__login-body{padding:14px 16px;background:var(--surface);}

/* WC billing/shipping fields override */
.pv-scope.pv-checkout .pv-checkout__step-body .woocommerce-billing-fields,
.pv-scope.pv-checkout .pv-checkout__step-body .woocommerce-shipping-fields,
.pv-scope.pv-checkout .pv-checkout__step-body .woocommerce-additional-fields{display:block;}
.pv-scope.pv-checkout .pv-checkout__step-body .form-row{margin-bottom:14px;padding:0;width:100%;float:none;}
.pv-scope.pv-checkout .pv-checkout__step-body .form-row-first,
.pv-scope.pv-checkout .pv-checkout__step-body .form-row-last{width:50%;display:inline-block;vertical-align:top;}
.pv-scope.pv-checkout .pv-checkout__step-body .form-row-first{padding-right:7px;}
.pv-scope.pv-checkout .pv-checkout__step-body .form-row-last{padding-left:7px;}
.pv-scope.pv-checkout .pv-checkout__step-body .form-row label{
    display:block;font-size:13px;font-weight:600;color:var(--text-2);margin-bottom:6px;
}
.pv-scope.pv-checkout .pv-checkout__step-body .form-row label .required{color:var(--danger);}
.pv-scope.pv-checkout .pv-checkout__step-body .form-row input.input-text,
.pv-scope.pv-checkout .pv-checkout__step-body .form-row textarea.input-text,
.pv-scope.pv-checkout .pv-checkout__step-body .form-row select{
    height:48px;width:100%;padding:0 14px;
    background:var(--surface);border:2px solid var(--border);border-radius:var(--r-md);
    font-size:14.5px;color:var(--text);transition:border-color var(--t),box-shadow var(--t);
}
.pv-scope.pv-checkout .pv-checkout__step-body .form-row textarea.input-text{height:auto;min-height:80px;padding:10px 14px;line-height:1.5;resize:vertical;}
.pv-scope.pv-checkout .pv-checkout__step-body .form-row input.input-text:focus,
.pv-scope.pv-checkout .pv-checkout__step-body .form-row textarea.input-text:focus,
.pv-scope.pv-checkout .pv-checkout__step-body .form-row select:focus{
    outline:none;border-color:var(--primary);box-shadow:0 0 0 4px rgba(37,99,235,.14);
}
.pv-scope.pv-checkout .pv-checkout__step-body .select2-container--default .select2-selection--single{
    height:48px;border:2px solid var(--border);border-radius:var(--r-md);
}
.pv-scope.pv-checkout .pv-checkout__step-body .select2-container--default .select2-selection--single .select2-selection__rendered{
    line-height:44px;padding-left:14px;color:var(--text);
}

/* ship-to-different-address toggle */
.pv-scope.pv-checkout .pv-checkout__ship-toggle,
.pv-scope.pv-checkout .pv-checkout__terms-toggle{
    display:flex;align-items:flex-start;gap:10px;
    padding:14px 16px;background:var(--bg);border:1px solid var(--border);
    border-radius:var(--r-md);cursor:pointer;
    transition:background var(--t),border-color var(--t);
}
.pv-scope.pv-checkout .pv-checkout__ship-toggle:hover,
.pv-scope.pv-checkout .pv-checkout__terms-toggle:hover{background:var(--primary-50);border-color:var(--primary-100);}
.pv-scope.pv-checkout .pv-checkout__ship-toggle input,
.pv-scope.pv-checkout .pv-checkout__terms-toggle input{
    position:absolute;opacity:0;width:0;height:0;
}
.pv-scope.pv-checkout .pv-checkout__ship-toggle-mark,
.pv-scope.pv-checkout .pv-checkout__terms-toggle .pv-checkout__ship-toggle-mark{
    width:22px;height:22px;flex-shrink:0;border-radius:6px;
    border:2px solid var(--border-2);background:var(--surface);
    display:flex;align-items:center;justify-content:center;color:#fff;
    transition:background var(--t),border-color var(--t);
    margin-top:1px;
}
.pv-scope.pv-checkout .pv-checkout__ship-toggle-mark svg,
.pv-scope.pv-checkout .pv-checkout__terms-toggle .pv-checkout__ship-toggle-mark svg{
    opacity:0;transform:scale(.6);transition:opacity var(--t),transform var(--t);
}
.pv-scope.pv-checkout .pv-checkout__ship-toggle input:checked ~ .pv-checkout__ship-toggle-mark,
.pv-scope.pv-checkout .pv-checkout__terms-toggle input:checked ~ .pv-checkout__ship-toggle-mark{
    background:var(--accent);border-color:var(--accent);
}
.pv-scope.pv-checkout .pv-checkout__ship-toggle input:checked ~ .pv-checkout__ship-toggle-mark svg,
.pv-scope.pv-checkout .pv-checkout__terms-toggle input:checked ~ .pv-checkout__ship-toggle-mark svg{
    opacity:1;transform:scale(1);
}
.pv-scope.pv-checkout .pv-checkout__ship-toggle-text,
.pv-scope.pv-checkout .pv-checkout__terms-toggle .pv-checkout__ship-toggle-text{
    font-size:14px;color:var(--text);font-weight:500;line-height:1.45;
}
.pv-scope.pv-checkout .pv-checkout__ship-toggle-text a,
.pv-scope.pv-checkout .pv-checkout__terms-toggle .pv-checkout__ship-toggle-text a{color:var(--primary);text-decoration:underline;}

/* Hidden shipping fields (cuando ship_to_different_address está off) */
.pv-scope.pv-checkout .woocommerce-shipping-fields{display:none;}
.pv-scope.pv-checkout .woocommerce-shipping-fields.shipping-fields--visible{display:block;animation:pv-fade .25s ease;}

/* No shipping / no payment */
.pv-scope.pv-checkout .pv-checkout__no-shipping,
.pv-scope.pv-checkout .pv-checkout__no-payment{
    display:flex;align-items:center;gap:10px;padding:14px 16px;
    background:var(--danger-50);border:1px solid var(--danger);border-radius:var(--r-md);
    color:var(--danger);
}
.pv-scope.pv-checkout .pv-checkout__no-shipping p,
.pv-scope.pv-checkout .pv-checkout__no-payment p{margin:0;font-size:13.5px;color:var(--text-2);}

/* SHIPPING OPTIONS — radio cards */
.pv-scope.pv-checkout .pv-shipping-options,
.pv-scope.pv-checkout .pv-payment-options{
    display:flex;flex-direction:column;gap:8px;list-style:none;margin:0;padding:0;
}
.pv-scope.pv-checkout .pv-shipping-option,
.pv-scope.pv-checkout .pv-payment-option{
    position:relative;border:2px solid var(--border);border-radius:var(--r-md);
    background:var(--surface);overflow:hidden;
    transition:border-color var(--t),box-shadow var(--t);
}
.pv-scope.pv-checkout .pv-shipping-option:hover,
.pv-scope.pv-checkout .pv-payment-option:hover{border-color:var(--border-2);box-shadow:var(--sh-1);}
.pv-scope.pv-checkout .pv-shipping-option.is-selected,
.pv-scope.pv-checkout .pv-payment-option.is-selected{
    border-color:var(--primary);box-shadow:0 0 0 4px rgba(37,99,235,.08);
}
.pv-scope.pv-checkout .pv-shipping-option__input,
.pv-scope.pv-checkout .pv-payment-option__input{
    position:absolute;opacity:0;width:0;height:0;
}
.pv-scope.pv-checkout .pv-shipping-option__label,
.pv-scope.pv-checkout .pv-payment-option__label{
    display:flex;align-items:center;gap:12px;padding:14px 16px;cursor:pointer;
    min-height:64px;
}
.pv-scope.pv-checkout .pv-shipping-option__icon,
.pv-scope.pv-checkout .pv-payment-option__icon{
    width:44px;height:44px;flex-shrink:0;border-radius:var(--r-md);
    display:flex;align-items:center;justify-content:center;
    background:var(--bg);font-size:22px;
}
.pv-scope.pv-checkout .pv-shipping-option.is-selected .pv-shipping-option__icon,
.pv-scope.pv-checkout .pv-payment-option.is-selected .pv-payment-option__icon{
    background:var(--primary-50);
}
.pv-scope.pv-checkout .pv-shipping-option__info,
.pv-scope.pv-checkout .pv-payment-option__info{
    display:flex;flex-direction:column;gap:2px;flex:1;min-width:0;
}
.pv-scope.pv-checkout .pv-shipping-option__name,
.pv-scope.pv-checkout .pv-payment-option__name{
    font-family:var(--display);font-weight:700;font-size:14.5px;color:var(--text);
}
.pv-scope.pv-checkout .pv-shipping-option__sub,
.pv-scope.pv-checkout .pv-payment-option__sub{
    font-size:12.5px;color:var(--text-3);
}
.pv-scope.pv-checkout .pv-shipping-option__price{
    font-family:var(--display);font-weight:700;font-size:15px;color:var(--text);flex-shrink:0;
}
.pv-scope.pv-checkout .pv-shipping-option__free{
    color:var(--accent);font-weight:800;
}
.pv-scope.pv-checkout .pv-payment-option__check{
    width:24px;height:24px;flex-shrink:0;border-radius:50%;
    border:2px solid var(--border-2);background:var(--surface);
    display:flex;align-items:center;justify-content:center;color:#fff;
    transition:background var(--t),border-color var(--t),transform var(--t);
}
.pv-scope.pv-checkout .pv-payment-option__check svg{opacity:0;transform:scale(.5);transition:opacity var(--t),transform var(--t);}
.pv-scope.pv-checkout .pv-payment-option.is-selected .pv-payment-option__check{
    background:var(--accent);border-color:var(--accent);transform:scale(1);
}
.pv-scope.pv-checkout .pv-payment-option.is-selected .pv-payment-option__check svg{
    opacity:1;transform:scale(1);
}
.pv-scope.pv-checkout .pv-payment-option__fields{
    padding:0 16px 14px;border-top:1px dashed var(--border);
    animation:pv-fade .25s ease;
}
.pv-scope.pv-checkout .pv-payment-option__desc{
    margin:10px 0 0;font-size:13px;color:var(--text-3);line-height:1.5;
}
.pv-scope.pv-checkout .pv-payment-option__fields .form-row{margin-bottom:10px;}
.pv-scope.pv-checkout .pv-payment-option__fields .payment_box{
    background:var(--bg);padding:12px 14px;border-radius:var(--r-sm);margin-top:8px;
}

/* Escrow notice */
.pv-scope.pv-checkout .pv-escrow-notice{
    display:flex;gap:12px;padding:14px 16px;
    background:var(--accent-50);border:1px solid var(--accent-100);
    border-radius:var(--r-md);
}
.pv-scope.pv-checkout .pv-escrow-notice svg{color:var(--accent);flex-shrink:0;}
.pv-scope.pv-checkout .pv-escrow-notice__body{display:flex;flex-direction:column;gap:2px;font-size:13.5px;line-height:1.45;color:var(--text-2);}
.pv-scope.pv-checkout .pv-escrow-notice__body strong{color:var(--text);font-size:14px;}
.pv-scope.pv-checkout .pv-escrow-notice__body p{margin:0;}
.pv-scope.pv-checkout .pv-escrow-notice--compact{
    align-items:center;padding:10px 12px;font-size:12.5px;
}
.pv-scope.pv-checkout .pv-escrow-notice--compact span{color:var(--text-2);}

/* CTA */
.pv-scope.pv-checkout .pv-checkout__cta-wrap{display:flex;flex-direction:column;gap:10px;padding-top:4px;}

/* v2.9.220: Brand color CTA (red #E80001) — consistente con cart y product page */
.pv-scope.pv-checkout .pv-btn--brand{
    background:#E80001;color:#fff;border:1px solid #E80001;
}
.pv-scope.pv-checkout .pv-btn--brand:hover{
    background:#B80001;border-color:#B80001;
    transform:translateY(-1px);box-shadow:0 6px 16px rgba(232,0,1,0.28);
}
.pv-scope.pv-checkout .pv-btn--brand:active{
    transform:translateY(0);box-shadow:0 2px 6px rgba(232,0,1,0.20);
}

.pv-scope.pv-checkout .pv-checkout__submit{
    height:60px;font-size:17px;font-weight:800;letter-spacing:.01em;
    flex-direction:row;justify-content:space-between;padding:0 22px;
}
.pv-scope.pv-checkout .pv-checkout__submit[disabled],
.pv-scope.pv-checkout .pv-checkout__submit.is-loading{
    pointer-events:none;background:#B80001;color:transparent;
}
.pv-scope.pv-checkout .pv-checkout__submit[disabled]::after,
.pv-scope.pv-checkout .pv-checkout__submit.is-loading::after{
    content:"";position:absolute;left:50%;top:50%;width:22px;height:22px;border-radius:50%;
    border:3px solid rgba(255,255,255,.5);border-top-color:#fff;
    animation:pv-spin .7s linear infinite;transform:translate(-50%,-50%);
}
.pv-scope.pv-checkout .pv-checkout__submit-label{flex:1;text-align:left;padding-left:8px;}
.pv-scope.pv-checkout .pv-checkout__submit-total{font-weight:800;}

/* v2.9.220: Checkbox styling — uniforme y consistente */
.pv-scope.pv-checkout .pv-checkout__terms-toggle,
.pv-scope.pv-checkout .ltms-checkout-consent{
    display:flex;align-items:flex-start;gap:10px;
    padding:14px 16px;margin:12px 0;
    background:#FFF9F9;border:1px solid #FFD6D6;border-left:4px solid #E80001;
    border-radius:10px;cursor:pointer;transition:all .15s;
}
.pv-scope.pv-checkout .pv-checkout__terms-toggle:hover,
.pv-scope.pv-checkout .ltms-checkout-consent:hover{
    background:#FFF1F1;border-color:#E80001;
}
.pv-scope.pv-checkout .pv-checkout__terms-toggle input[type="checkbox"],
.pv-scope.pv-checkout .ltms-checkout-consent input[type="checkbox"]{
    position:absolute;opacity:0;width:0;height:0;margin:0;
}
.pv-scope.pv-checkout .pv-checkout__terms-toggle .pv-checkout__ship-toggle-mark,
.pv-scope.pv-checkout .ltms-checkout-consent .ltms-checkout-consent-mark,
.pv-scope.pv-checkout .ltms-checkout-consent::before{
    flex-shrink:0;width:22px;height:22px;border:2px solid #D1D5DB;
    border-radius:6px;background:#fff;display:flex;align-items:center;
    justify-content:center;transition:all .15s;color:#fff;
}
.pv-scope.pv-checkout .pv-checkout__terms-toggle input:checked + .pv-checkout__ship-toggle-mark,
.pv-scope.pv-checkout .ltms-checkout-consent input:checked + .ltms-checkout-consent-mark{
    background:#E80001;border-color:#E80001;
}
.pv-scope.pv-checkout .pv-checkout__terms-toggle input:focus-visible + .pv-checkout__ship-toggle-mark,
.pv-scope.pv-checkout .ltms-checkout-consent input:focus-visible + .ltms-checkout-consent-mark{
    outline:2px solid #E80001;outline-offset:2px;
}
.pv-scope.pv-checkout .pv-checkout__terms-toggle .pv-checkout__ship-toggle-text,
.pv-scope.pv-checkout .ltms-checkout-consent span:not(.ltms-checkout-consent-mark){
    font-size:13px;line-height:1.5;color:#1A1F2E;font-weight:500;
}
.pv-scope.pv-checkout .pv-checkout__terms-toggle a,
.pv-scope.pv-checkout .ltms-checkout-consent a{
    color:#E80001;font-weight:600;text-decoration:underline;
}
.pv-scope.pv-checkout .pv-checkout__terms-toggle a:hover,
.pv-scope.pv-checkout .ltms-checkout-consent a:hover{
    text-decoration:none;
}
.pv-scope.pv-checkout .pv-checkout__legal{
    text-align:center;font-size:12px;color:var(--text-3);line-height:1.45;margin:0;
}

/* Review column */
.pv-scope.pv-checkout .pv-checkout__review-col{position:relative;}
.pv-scope.pv-checkout .pv-checkout__review[data-pv-sticky]{
    position:sticky;top:20px;display:flex;flex-direction:column;gap:14px;
}
.pv-scope.pv-checkout .pv-checkout__review-head{display:flex;align-items:center;justify-content:space-between;gap:10px;}
.pv-scope.pv-checkout .pv-checkout__review-title{font-family:var(--display);font-weight:800;font-size:18px;}
.pv-scope.pv-checkout .pv-checkout__review-edit{
    display:inline-flex;align-items:center;gap:4px;font-size:13px;font-weight:600;color:var(--primary);
}
.pv-scope.pv-checkout .pv-checkout__review-edit:hover{color:var(--primary-700);}

/* WC order review override */
.pv-scope.pv-checkout .pv-checkout__review table.woocommerce-checkout-review-order-table{
    width:100%;border-collapse:collapse;
}
.pv-scope.pv-checkout .pv-checkout__review table.woocommerce-checkout-review-order-table th,
.pv-scope.pv-checkout .pv-checkout__review table.woocommerce-checkout-review-order-table td{
    padding:10px 0;border-bottom:1px solid var(--border);font-size:13.5px;text-align:right;
}
.pv-scope.pv-checkout .pv-checkout__review table.woocommerce-checkout-review-order-table th{text-align:left;font-weight:600;color:var(--text-2);}
.pv-scope.pv-checkout .pv-checkout__review table.woocommerce-checkout-review-order-table .product-name{text-align:left;color:var(--text);}
.pv-scope.pv-checkout .pv-checkout__review table.woocommerce-checkout-review-order-table .product-quantity{color:var(--text-3);font-size:12px;font-weight:400;}
.pv-scope.pv-checkout .pv-checkout__review table.woocommerce-checkout-review-order-table .cart-subtotal td,
.pv-scope.pv-checkout .pv-checkout__review table.woocommerce-checkout-review-order-table .order-total td{font-weight:700;color:var(--text);}
.pv-scope.pv-checkout .pv-checkout__review table.woocommerce-checkout-review-order-table .order-total td .woocommerce-Price-amount{
    font-family:var(--display);font-weight:800;font-size:20px;color:var(--primary);
}
.pv-scope.pv-checkout .pv-checkout__review table.woocommerce-checkout-review-order-table .shipping td{color:var(--accent);font-weight:700;}

/* Coupon en review */
.pv-scope.pv-checkout .pv-checkout__review-coupon{border-top:1px dashed var(--border);padding-top:12px;}
.pv-scope.pv-checkout .pv-checkout__review-coupon .woocommerce-form-coupon-toggle{display:none;}
.pv-scope.pv-checkout .pv-checkout__review-coupon .woocommerce-form-coupon{
    display:flex;gap:6px;border:0;padding:0;background:transparent;
}
.pv-scope.pv-checkout .pv-checkout__review-coupon .woocommerce-form-coupon p.form-row{margin:0;flex:1;}
.pv-scope.pv-checkout .pv-checkout__review-coupon .woocommerce-form-coupon input.input-text{
    height:42px;width:100%;padding:0 12px;border:2px solid var(--border);border-radius:var(--r-sm);
    font-size:13.5px;
}
.pv-scope.pv-checkout .pv-checkout__review-coupon .woocommerce-form-coupon button.button{
    height:42px;padding:0 14px;border:0;border-radius:var(--r-sm);
    background:var(--primary);color:#fff;font-weight:700;font-size:13px;cursor:pointer;
}
.pv-scope.pv-checkout .pv-checkout__review-coupon .woocommerce-form-coupon button.button:hover{background:var(--primary-600);}

/* ============================================================================
   RESPONSIVE
   ========================================================================== */
@media (max-width:1100px){
    .pv-scope.pv-checkout .pv-checkout__layout{grid-template-columns:1fr;gap:20px;}
    .pv-scope.pv-checkout .pv-checkout__review[data-pv-sticky]{position:static;top:auto;order:-1;}
}
@media (max-width:760px){
    .pv-scope.pv-checkout .pv-checkout__stepper-step .pv-checkout__stepper-label{display:none;}
    .pv-scope.pv-checkout .pv-checkout__stepper-step:not(:last-child)::after{width:14px;}
    .pv-scope.pv-checkout .pv-checkout__contact-fields{grid-template-columns:1fr;}
    .pv-scope.pv-checkout .pv-checkout__step-body{padding:16px;}
    .pv-scope.pv-checkout .pv-checkout__step-body .form-row-first,
    .pv-scope.pv-checkout .pv-checkout__step-body .form-row-last{width:100%;display:block;padding:0;}
    .pv-scope.pv-checkout .pv-shipping-option__label,
    .pv-scope.pv-checkout .pv-payment-option__label{padding:12px 14px;min-height:56px;}
    .pv-scope.pv-checkout .pv-shipping-option__icon,
    .pv-scope.pv-checkout .pv-payment-option__icon{width:40px;height:40px;font-size:20px;}
    .pv-scope.pv-checkout .pv-checkout__submit{height:54px;padding:0 16px;font-size:15px;}
    .pv-scope.pv-checkout .pv-checkout__review{padding:18px;}
}
@media (max-width:480px){
    .pv-scope.pv-checkout .pv-checkout__title{font-size:22px;}
    .pv-scope.pv-checkout .pv-checkout__step-title{font-size:15px;}
    .pv-scope.pv-checkout .pv-checkout__step-num{width:26px;height:26px;font-size:13px;}
    .pv-scope.pv-checkout .pv-shipping-option__price,
    .pv-scope.pv-checkout .pv-payment-option__info{font-size:14px;}
    .pv-scope.pv-checkout .pv-shipping-option__badge,
    .pv-scope.pv-checkout .pv-payment-option__badge{display:none;}
}
</style>

<script>
(function(){
    'use strict';
    var scope = document.querySelector('.pv-scope.pv-checkout');
    if (!scope) return;

    /* --- 1. Stepper sync (marcar paso activo según scroll/visibility) -- */
    var stepBlocks = Array.prototype.slice.call(scope.querySelectorAll('[data-step-block]'));
    var stepperItems = Array.prototype.slice.call(scope.querySelectorAll('.pv-checkout__stepper-step[data-step]'));

    function markActiveStep(stepNum){
        stepperItems.forEach(function(item){
            var n = parseInt(item.getAttribute('data-step'), 10);
            item.classList.toggle('is-active', n === stepNum);
            item.classList.toggle('is-done', n < stepNum);
        });
    }

    if (stepBlocks.length && 'IntersectionObserver' in window){
        var io = new IntersectionObserver(function(entries){
            entries.forEach(function(entry){
                if (entry.isIntersecting){
                    var n = parseInt(entry.target.getAttribute('data-step-block'), 10);
                    markActiveStep(n);
                }
            });
        }, { rootMargin:'-40% 0px -55% 0px', threshold:0 });
        stepBlocks.forEach(function(b){ io.observe(b); });
    }

    /* --- 2. Shipping radio cards (cambiar .is-selected) --------------- */
    var shipRadios = Array.prototype.slice.call(scope.querySelectorAll('[data-pv-shipping-radio]'));
    shipRadios.forEach(function(radio){
        radio.addEventListener('change', function(){
            var wrap = radio.closest('.pv-shipping-options');
            if (!wrap) return;
            Array.prototype.slice.call(wrap.querySelectorAll('.pv-shipping-option')).forEach(function(li){
                li.classList.remove('is-selected');
            });
            radio.closest('.pv-shipping-option').classList.add('is-selected');
        });
    });

    /* --- 3. Payment radio cards (mostrar/ocultar fields) -------------- */
    var payRadios = Array.prototype.slice.call(scope.querySelectorAll('[data-pv-payment-radio]'));
    var payFields = Array.prototype.slice.call(scope.querySelectorAll('[data-pv-payment-fields]'));

    function updatePaymentSelection(){
        payRadios.forEach(function(radio){
            var li = radio.closest('.pv-payment-option');
            if (!li) return;
            var isSelected = radio.checked;
            li.classList.toggle('is-selected', isSelected);
            var id = li.getAttribute('data-pv-payment-option');
            payFields.forEach(function(field){
                var fid = field.getAttribute('data-pv-payment-fields');
                if (fid === id){
                    field.hidden = !isSelected;
                }
            });
        });
    }
    payRadios.forEach(function(radio){
        radio.addEventListener('change', updatePaymentSelection);
    });
    updatePaymentSelection();

    /* --- 4. ship_to_different_address toggle (mostrar shipping fields) */
    var shipToggle = scope.querySelector('#ship_to_different_address');
    var shipFieldsWrap = scope.querySelector('.woocommerce-shipping-fields');
    if (shipToggle && shipFieldsWrap){
        function syncShipFields(){
            if (shipToggle.checked){
                shipFieldsWrap.classList.add('shipping-fields--visible');
                shipFieldsWrap.style.display = 'block';
            } else {
                shipFieldsWrap.classList.remove('shipping-fields--visible');
                shipFieldsWrap.style.display = 'none';
            }
        }
        shipToggle.addEventListener('change', syncShipFields);
        syncShipFields();
    }

    /* --- 5. Submit loading state ------------------------------------- */
    var submitBtn = scope.querySelector('.pv-checkout__submit');
    var checkoutForm = scope.querySelector('form.woocommerce-checkout');
    if (submitBtn && checkoutForm){
        checkoutForm.addEventListener('submit', function(){
            // Validación mínima: validar campos required visibles.
            submitBtn.classList.add('is-loading');
            submitBtn.setAttribute('disabled', 'disabled');
        });
    }

    /* --- 6. v2.9.216: Fix field labels (bypass WOOCCM) ----------------- */
    /* WOOCCM (WooCommerce Checkout Manager) reconstruye los labels desde
     * su propia BD DESPUÉS de los filtros woocommerce_billing_fields y
     * woocommerce_form_field. La única forma de override confiable es
     * modificar el DOM via JS después de que WOOCCM termina.
     *
     * Labels corregidos:
     *   CO: billing_state → 'Departamento', billing_city → 'Municipio'
     *   MX: billing_state → 'Estado', billing_city → 'Municipio / Alcaldía'
     *   Ambos: 'País / Región' → 'País', 'Dirección de la calle' → 'Dirección',
     *          'Código postal / ZIP' → 'Código postal'
     */
    var ltmsCountry = '<?php echo esc_js( LTMS_Core_Config::get_country() ); ?>';
    var labelMap = {};
    if (ltmsCountry === 'CO') {
        labelMap = {
            'billing_state': 'Departamento',
            'shipping_state': 'Departamento',
            'billing_city': 'Municipio',
            'shipping_city': 'Municipio',
            'billing_country': 'País',
            'shipping_country': 'País',
            'billing_postcode': 'Código postal',
            'shipping_postcode': 'Código postal',
            'billing_address_1': 'Dirección',
            'shipping_address_1': 'Dirección',
            'billing_address_2': 'Apartamento, suite, etc. (opcional)',
            'shipping_address_2': 'Apartamento, suite, etc. (opcional)'
        };
    } else if (ltmsCountry === 'MX') {
        labelMap = {
            'billing_state': 'Estado',
            'shipping_state': 'Estado',
            'billing_city': 'Municipio / Alcaldía',
            'shipping_city': 'Municipio / Alcaldía',
            'billing_country': 'País',
            'shipping_country': 'País',
            'billing_postcode': 'Código postal',
            'shipping_postcode': 'Código postal',
            'billing_address_1': 'Dirección',
            'shipping_address_1': 'Dirección',
            'billing_address_2': 'Apartamento, suite, etc. (opcional)',
            'shipping_address_2': 'Apartamento, suite, etc. (opcional)'
        };
    }

    function fixFieldLabels() {
        Object.keys(labelMap).forEach(function(fieldKey) {
            var newLabel = labelMap[fieldKey];
            // Buscar el label por 'for' attribute.
            var labelEl = scope.querySelector('label[for="' + fieldKey + '"]');
            if (!labelEl) return;
            // Preservar el <abbr class="required"> o <span class="optional"> si existe.
            var abbr = labelEl.querySelector('abbr.required, abbr');
            var optionalSpan = labelEl.querySelector('span.optional, .optional');
            // Reconstruir el label.
            labelEl.innerHTML = '';
            labelEl.appendChild(document.createTextNode(newLabel));
            if (abbr) {
                labelEl.appendChild(document.createTextNode(' '));
                labelEl.appendChild(abbr);
            }
            if (optionalSpan) {
                labelEl.appendChild(document.createTextNode(' '));
                labelEl.appendChild(optionalSpan);
            }
        });

        // Ocultar campos duplicados:
        // - billing_phone en step 2 (ya está en step 1 como 'Teléfono / WhatsApp')
        // - billing_email en step 2 (ya está en step 1)
        // Heuristic: si el label NO contiene 'WhatsApp' o 'Correo', es el duplicado.
        var phoneLabels = scope.querySelectorAll('label[for="billing_phone"], label[for="shipping_phone"]');
        phoneLabels.forEach(function(lbl) {
            var text = (lbl.textContent || '').toLowerCase();
            if (text.indexOf('whatsapp') === -1) {
                var field = document.getElementById('billing_phone_field') || document.getElementById('shipping_phone_field');
                if (field) field.style.display = 'none';
            }
        });
        var emailLabels = scope.querySelectorAll('label[for="billing_email"], label[for="shipping_email"]');
        emailLabels.forEach(function(lbl) {
            var text = (lbl.textContent || '').toLowerCase();
            // Si el label dice 'Correo electrónico' ES el de step 1 (mantener).
            // Si solo dice 'Email' o 'Dirección de correo electrónico', es duplicado.
            if (text.indexOf('correo electrónico') === -1) {
                var field = document.getElementById('billing_email_field') || document.getElementById('shipping_email_field');
                if (field) field.style.display = 'none';
            }
        });

        // Auto-seleccionar país: CO o MX según configuración.
        var countrySelect = scope.querySelector('#billing_country, #shipping_country');
        if (countrySelect && countrySelect.value !== ltmsCountry) {
            countrySelect.value = ltmsCountry;
            // Disparar change event para que WC actualice los estados.
            countrySelect.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    // Ejecutar inmediatamente y después de un delay (para WOOCCM JS que corre tarde).
    fixFieldLabels();
    setTimeout(fixFieldLabels, 500);
    setTimeout(fixFieldLabels, 1500);
    // También observar mutaciones del DOM (WOOCCM puede modificar dinámicamente).
    if ('MutationObserver' in window) {
        var observer = new MutationObserver(function(mutations) {
            var shouldFix = false;
            mutations.forEach(function(m) {
                if (m.type === 'childList' || m.type === 'characterData') {
                    shouldFix = true;
                }
            });
            if (shouldFix) {
                fixFieldLabels();
            }
        });
        observer.observe(scope, { childList: true, subtree: true, characterData: true });
        // Dejar de observar después de 5 segundos (para no matar el performance).
        setTimeout(function() { observer.disconnect(); }, 5000);
    }

    /* --- 7. v2.9.218: Sync billing_state from billing_city (DANE municipio) ---- */
    /* El select de billing_city usa códigos DANE (5 dígitos) donde los primeros
     * 2 dígitos = departamento. Cuando el usuario selecciona un municipio,
     * auto-poblamos billing_state con el departamento correspondiente para que
     * WC pueda calcular el envío (WC requiere billing_state para calcular
     * shipping rates).
     *
     * Estrategia: extraer el nombre del departamento del texto del option
     * (formato: "Municipio — Departamento") y buscarlo en las opciones de
     * billing_state por coincidencia de texto.
     */
    function syncStateFromCity() {
        var citySelect = scope.querySelector('#billing_city, #ltms-municipality-select');
        var stateSelect = scope.querySelector('#billing_state');
        if (!citySelect || !stateSelect) return;
        if (!citySelect.value || citySelect.value.length < 2) return;

        // Obtener el texto del option seleccionado (formato: "Municipio — Departamento")
        var selectedOption = citySelect.options[citySelect.selectedIndex];
        if (!selectedOption) return;
        var optionText = selectedOption.textContent || '';
        // Extraer el departamento (después del " — ")
        var deptName = '';
        var dashIdx = optionText.indexOf('—');
        if (dashIdx !== -1) {
            deptName = optionText.substring(dashIdx + 1).trim();
        } else if (optionText.indexOf('-') !== -1) {
            deptName = optionText.substring(optionText.indexOf('-') + 1).trim();
        }

        if (!deptName) return;

        // Buscar la opción en billing_state que coincida con el departamento.
        // WC usa códigos como "CO-DC" para Bogotá D.C. pero el texto visible es
        // "Bogotá D.C." — comparamos por texto.
        var bestMatch = null;
        var bestScore = 0;
        for (var i = 0; i < stateSelect.options.length; i++) {
            var opt = stateSelect.options[i];
            var optText = (opt.textContent || '').trim();
            // Normalizar: quitar acentos y minúsculas para comparación robusta.
            var normOpt = optText.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            var normDept = deptName.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            if (normOpt === normDept) {
                bestMatch = opt;
                bestScore = 100;
                break;
            }
            // Coincidencia parcial: si el departamento está contenido en el texto
            if (normOpt.indexOf(normDept) !== -1 || normDept.indexOf(normOpt) !== -1) {
                var score = Math.min(normOpt.length, normDept.length);
                if (score > bestScore) {
                    bestScore = score;
                    bestMatch = opt;
                }
            }
        }

        if (bestMatch && bestScore > 0) {
            stateSelect.value = bestMatch.value;
            // Disparar change event para que WC recalcule shipping.
            stateSelect.dispatchEvent(new Event('change', { bubbles: true }));
            // También disparar el evento 'update_checkout' de WC para forzar
            // el recálculo de shipping methods.
            if (typeof jQuery !== 'undefined') {
                jQuery(document.body).trigger('update_checkout');
            }
        }
    }

    // Enganchar al change de billing_city.
    var citySelectForSync = scope.querySelector('#billing_city, #ltms-municipality-select');
    if (citySelectForSync) {
        citySelectForSync.addEventListener('change', function() {
            setTimeout(syncStateFromCity, 100); // Pequeño delay para que WOOCCM termine.
        });
        // También ejecutar al cargar si ya hay un municipio seleccionado.
        setTimeout(syncStateFromCity, 800);
    }
})();
</script>

<?php
get_footer( 'shop' );
