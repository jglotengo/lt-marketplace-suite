<?php
/**
 * Partial: Formulario de recuperación de contraseña del vendedor
 *
 * LOST-PASSWORD-PAGE FIX (2026-09-07): página propia de recuperación con el
 * diseño del login de vendedor. El form se envía por AJAX
 * (ajax_vendor_lost_password) y en éxito muestra la pantalla "Revisa tu correo"
 * con opción de reenviar. Respuesta genérica (no enumera cuentas).
 *
 * @package    LTMS\Frontend\Views
 * @version    1.0.0
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="ltms-auth-card ltms-login-card" id="ltms-lost-password-wrap">

    <div class="ltms-auth-logo">
        <?php
        $ltms_lp_logo_id = get_theme_mod( 'custom_logo' );
        if ( $ltms_lp_logo_id ) {
            echo wp_get_attachment_image( $ltms_lp_logo_id, 'medium', false, [ 'class' => 'ltms-auth-logo-img', 'alt' => get_bloginfo( 'name' ) ] );
        } else {
            echo '<span class="ltms-auth-site-name">' . esc_html( get_bloginfo( 'name' ) ) . '</span>';
        }
        ?>
    </div>

    <h2 class="ltms-auth-title"><?php esc_html_e( 'Recuperar contraseña', 'ltms' ); ?></h2>
    <p class="ltms-auth-subtitle"><?php esc_html_e( 'Ingresa tu email o usuario de vendedor y te enviaremos un enlace para restablecer tu contraseña.', 'ltms' ); ?></p>

    <div id="ltms-lost-password-notice" class="ltms-notice" style="display:none;" role="alert"></div>

    <form id="ltms-lost-password-form" class="ltms-auth-form" novalidate>
        <div class="ltms-form-group">
            <label for="ltms-lost-password-email"><?php esc_html_e( 'Email o Usuario', 'ltms' ); ?></label>
            <input
                type="text"
                id="ltms-lost-password-email"
                name="email"
                class="ltms-form-control"
                autocomplete="username"
                required
                placeholder="<?php esc_attr_e( 'tu@email.com', 'ltms' ); ?>"
            >
        </div>

        <button type="submit" class="ltms-btn ltms-btn-primary ltms-btn-full" id="ltms-lost-password-btn">
            <span class="ltms-btn-text"><?php esc_html_e( 'Enviar enlace de recuperación', 'ltms' ); ?></span>
            <span class="ltms-btn-spinner" style="display:none;">&#9696;</span>
        </button>
    </form>

    <div id="ltms-lost-password-success" style="display:none;" role="status">
        <div style="text-align:center;padding:8px 0 4px;">
            <div style="font-size:3rem;margin-bottom:10px;">📧</div>
            <h3 style="margin:0 0 8px;color:#15803d;font-size:1.15rem;font-weight:800;"><?php esc_html_e( 'Revisa tu correo', 'ltms' ); ?></h3>
            <p id="ltms-lost-password-success-msg" style="margin:0 0 8px;color:#4b5563;font-size:0.9rem;line-height:1.5;"><?php esc_html_e( 'Si la cuenta existe, te enviamos un enlace para restablecer tu contraseña.', 'ltms' ); ?></p>
            <p style="margin:0 0 14px;color:#92400e;font-size:0.85rem;line-height:1.5;"><?php esc_html_e( 'Revisa tu bandeja de entrada y la carpeta de spam. El enlace es válido por 24 horas.', 'ltms' ); ?></p>
            <button type="button" id="ltms-lost-password-resend" class="ltms-btn ltms-btn-primary" style="padding:10px 18px;font-size:0.875rem;">
                <span class="ltms-resend-btn-text"><?php esc_html_e( 'Reenviar email', 'ltms' ); ?></span>
                <span class="ltms-resend-btn-spinner" style="display:none;">&#9696;</span>
            </button>
        </div>
    </div>

    <div class="ltms-auth-footer">
        <p>
            <?php esc_html_e( '¿Recordaste tu contraseña?', 'ltms' ); ?>
            <?php
            $ltms_lp_pages  = get_option( 'ltms_installed_pages', [] );
            $ltms_lp_login  = $ltms_lp_pages['ltms-login'] ?? 0;
            $ltms_lp_url    = $ltms_lp_login ? get_permalink( $ltms_lp_login ) : home_url( '/login-vendedor/' );
            ?>
            <a href="<?php echo esc_url( $ltms_lp_url ); ?>" class="ltms-auth-switch-link">
                <?php esc_html_e( 'Volver a iniciar sesión', 'ltms' ); ?>
            </a>
        </p>
    </div>

</div><!-- .ltms-login-card -->