<?php
/**
 * Template: My Account - Plaza Viva Design System
 *
 * Mi Cuenta nativa de WooCommerce bajo el design system Plaza Viva.
 * Reemplaza al template de WC via `template_include` (ver
 * LTMS_Native_Templates::maybe_override() - integra my-account.php cuando
 * is_account_page(); el archivo existia como ruta fantasma hasta MA-08).
 *
 * AUDIT-FE-UIUX3-MA-08 FIX: la pagina renderizaba con el tema, fuera del
 * design system. Crear el override nativo era el backlog pendiente de
 * decision de producto, autorizado explicitamente por el usuario.
 *
 * Estructura:
 *  - Invitados: formulario login de WC (myaccount/form-login.php) estilado.
 *  - Logueados: header con saludo + layout 2 columnas
 *    (navegacion de endpoints | contenido del endpoint).
 *
 * La navegacion usa wc_get_account_menu_items(), por lo que preserva los
 * endpoints registrados por LTMS ("Mis Reservas", compliance turistico)
 * y cualquier tercero que se enganche a woocommerce_account_menu_items.
 *
 * El contenido de cada endpoint sigue generandolo WooCommerce
 * (do_action woocommerce_account_content): este template NO reimplementa
 * logica de negocio, solo envuelve y estila.
 *
 * Hooks propios:
 *  - ltms_before_account_plazaviva / ltms_after_account_plazaviva
 *
 * @package LTMS
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Salida directa no permitida.
}

// Garantizar que WooCommerce esta cargado y expone la API de cuenta.
if ( ! function_exists( 'WC' ) || ! function_exists( 'wc_get_account_menu_items' ) || ! function_exists( 'wc_get_account_endpoint_url' ) ) {
    get_header( 'shop' );
    echo '<div class="pv-scope pv-account"><main class="pv-section pv-fallback__section"><p class="pv-fallback__msg">' . esc_html__( 'WooCommerce no está activo o Mi Cuenta no está disponible.', 'ltms' ) . '</p></main></div>';
    get_footer( 'shop' );
    return;
}

/**
 * Invitados: el mismo template sirve la vista de login (comportamiento WC
 * canonico). Sin esta rama, un visitante sin sesion veria el shell vacio.
 */
if ( ! is_user_logged_in() ) {
    get_header( 'shop' );
    ?>
    <div class="pv-scope pv-account">
        <main class="pv-section pv-account__main pv-account__main--guest" role="main">
            <?php wc_get_template( 'myaccount/form-login.php' ); ?>
        </main>
    </div>
    <?php
    get_footer( 'shop' );
    return;
}

/* ---------------------------------------------------------------------------
 * Navegacion: items registrados (incluye endpoints LTMS y de terceros).
 * El item activo se resuelve con is_wc_endpoint_url(); el dashboard es el
 * estado sin endpoint. El item de logout nunca queda marcado activo.
 * ------------------------------------------------------------------------- */
$pv_menu       = wc_get_account_menu_items();
$pv_on_any     = false;
foreach ( array_keys( $pv_menu ) as $pv_ep ) {
    if ( 'customer-logout' === $pv_ep ) {
        continue;
    }
    if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( $pv_ep ) ) {
        $pv_on_any = true;
        break;
    }
}

$pv_user      = wp_get_current_user();
$pv_greeting  = $pv_user->display_name ? $pv_user->display_name : $pv_user->user_login;

do_action( 'ltms_before_account_plazaviva' );

get_header( 'shop' );
?>
<div class="pv-scope pv-account">

    <header class="pv-account__header pv-section">
        <div class="pv-account__header-inner">
            <div class="pv-account__title-wrap">
                <h1 class="pv-account__title"><?php esc_html_e( 'Mi cuenta', 'ltms' ); ?></h1>
                <p class="pv-account__sub">
                    <?php
                    /* translators: %s: nombre visible del usuario. */
                    echo esc_html( sprintf( __( 'Hola, %s. Gestiona tus pedidos, datos y reservas.', 'ltms' ), $pv_greeting ) );
                    ?>
                </p>
            </div>
            <span class="pv-badge pv-badge--verified pv-badge--dot"><?php esc_html_e( 'Sesión segura', 'ltms' ); ?></span>
        </div>
    </header>

    <main class="pv-account__main pv-section" role="main">

        <nav class="pv-account__nav" aria-label="<?php esc_attr_e( 'Menú de mi cuenta', 'ltms' ); ?>">
            <ul class="pv-account__nav-list" role="list">
                <?php foreach ( $pv_menu as $pv_ep => $pv_label ) :
                    if ( 'customer-logout' === $pv_ep ) {
                        $pv_active = false;
                    } elseif ( $pv_on_any ) {
                        $pv_active = function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( $pv_ep );
                    } else {
                        $pv_active = ( 'dashboard' === $pv_ep );
                    }
                    ?>
                <li class="pv-account__nav-item<?php echo $pv_active ? ' is-active' : ''; ?>">
                    <a class="pv-account__nav-link<?php echo $pv_active ? ' is-active' : ''; ?>"
                       href="<?php echo esc_url( wc_get_account_endpoint_url( $pv_ep ) ); ?>">
                        <?php echo esc_html( $pv_label ); ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </nav>

        <section class="pv-account__content-col" aria-label="<?php esc_attr_e( 'Contenido de mi cuenta', 'ltms' ); ?>">
            <?php if ( function_exists( 'wc_print_notices' ) ) { wc_print_notices(); } ?>
            <div class="pv-account__content woocommerce">
                <?php do_action( 'woocommerce_account_content' ); ?>
            </div>
        </section>

    </main>

</div><!-- /.pv-scope.pv-account -->

<?php
do_action( 'ltms_after_account_plazaviva' );

get_footer( 'shop' );
