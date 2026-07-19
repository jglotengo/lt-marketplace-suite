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
                             STEP 2: DIRECCIÓN DE FACTURACIÓN Y ENVÍO
                             v2.9.223: Título más claro. WC muestra primero
                             los campos de facturación (que por defecto también
                             son los de envío). El checkbox nativo '¿Enviar a
                             una dirección diferente?' revela los campos de
                             envío por separado.
                             ============================================ -->
                        <section class="pv-checkout__step pv-checkout__step--2" data-step-block="2" aria-labelledby="pv-checkout-step-2-title">
                            <header class="pv-checkout__step-head">
                                <h2 class="pv-checkout__step-title" id="pv-checkout-step-2-title">
                                    <span class="pv-checkout__step-num">2</span>
                                    <?php esc_html_e( 'Dirección de facturación y envío', 'ltms' ); ?>
                                </h2>
                                <span class="pv-badge pv-badge--dot pv-badge--trust"><?php esc_html_e( 'Requerido', 'ltms' ); ?></span>
                            </header>

                            <div class="pv-checkout__step-body">
                                <p style="font-size:13px;color:#565C66;margin:0 0 14px;line-height:1.45;">
                                    <?php esc_html_e( 'Ingresa tu dirección principal. Si tu dirección de envío es diferente a la de facturación, marca el checkbox "¿Enviar a una dirección diferente?" que aparece abajo.', 'ltms' ); ?>
                                </p>
                                <?php
                                /**
                                 * Hook: woocommerce_checkout_billing
                                 * Renderiza los campos de facturación: email, teléfono,
                                 * nombre, apellidos, país, dirección, municipio, etc.
                                 * También renderiza el checkbox nativo de WC
                                 * '¿Enviar a una dirección diferente?'.
                                 */
                                do_action( 'woocommerce_checkout_billing' );
                                ?>

                                <?php
                                /**
                                 * v2.9.223: NO duplicar el checkbox 'ship_to_different_address'.
                                 * WC ya lo renderiza vía woocommerce_checkout_billing.
                                 * Antes teníamos un checkbox duplicado aquí que causaba
                                 * confusión: aparecían 2 toggles para lo mismo.
                                 */

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

<?php
// v2.9.228: CSS is enqueued via LTMS_Frontend_Checkout_Script_Injector::enqueue_checkout_fixes()
// (wp_enqueue_scripts hook) — not here in the template, because wp_enqueue_style
// must run before <head> is printed.
?>


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
