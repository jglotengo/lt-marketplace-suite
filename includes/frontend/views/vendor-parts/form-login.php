<?php
/**
 * Partial: Formulario de inicio de sesión del vendedor
 *
 * @package    LTMS\Frontend\Views
 * @version    1.5.0
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="ltms-auth-card ltms-login-card" id="ltms-login-wrap">

    <div class="ltms-auth-logo">
        <?php
        $logo_id = get_theme_mod( 'custom_logo' );
        if ( $logo_id ) {
            echo wp_get_attachment_image( $logo_id, 'medium', false, [ 'class' => 'ltms-auth-logo-img', 'alt' => get_bloginfo( 'name' ) ] );
        } else {
            echo '<span class="ltms-auth-site-name">' . esc_html( get_bloginfo( 'name' ) ) . '</span>';
        }
        ?>
    </div>

    <h2 class="ltms-auth-title"><?php esc_html_e( 'Panel de Vendedor', 'ltms' ); ?></h2>
    <p class="ltms-auth-subtitle"><?php esc_html_e( 'Inicia sesión para acceder a tu panel.', 'ltms' ); ?></p>

    <div id="ltms-login-notice" class="ltms-notice" style="display:none;" role="alert"></div>

    <form id="ltms-login-form" class="ltms-auth-form" novalidate>
        <?php wp_nonce_field( 'ltms_vendor_login', 'ltms_login_nonce' ); ?>

        <div class="ltms-form-group">
            <label for="ltms-login-username"><?php esc_html_e( 'Usuario o Email', 'ltms' ); ?></label>
            <input
                type="text"
                id="ltms-login-username"
                name="username"
                class="ltms-form-control"
                autocomplete="username"
                required
                placeholder="<?php esc_attr_e( 'tu@email.com', 'ltms' ); ?>"
            >
        </div>

        <div class="ltms-form-group">
            <label for="ltms-login-password"><?php esc_html_e( 'Contraseña', 'ltms' ); ?></label>
            <div class="ltms-input-group">
                <input
                    type="password"
                    id="ltms-login-password"
                    name="password"
                    class="ltms-form-control"
                    autocomplete="current-password"
                    required
                    placeholder="••••••••"
                >
                <button type="button" class="ltms-toggle-password" data-target="ltms-login-password" aria-label="<?php esc_attr_e( 'Mostrar/ocultar contraseña', 'ltms' ); ?>">
                    <span class="ltms-icon-eye">&#128065;</span>
                </button>
            </div>
        </div>

        <div class="ltms-form-group ltms-form-row">
            <label class="ltms-checkbox-label">
                <input type="checkbox" name="rememberme" value="1">
                <?php esc_html_e( 'Recordarme', 'ltms' ); ?>
            </label>
            <?php
            $ltms_pages_login    = get_option( 'ltms_installed_pages', [] );
            $ltms_login_page_id  = $ltms_pages_login['ltms-login'] ?? 0;
            $ltms_login_self_url = $ltms_login_page_id ? get_permalink( $ltms_login_page_id ) : home_url( '/login-vendedor/' );
            $lost_password_url   = wp_lostpassword_url( $ltms_login_self_url );
            ?>
            <a href="<?php echo esc_url( $lost_password_url ); ?>" class="ltms-forgot-link">
                <?php esc_html_e( '¿Olvidaste tu contraseña?', 'ltms' ); ?>
            </a>
        </div>

        <button type="submit" class="ltms-btn ltms-btn-primary ltms-btn-full" id="ltms-login-btn">
            <span class="ltms-btn-text"><?php esc_html_e( 'Iniciar Sesión', 'ltms' ); ?></span>
            <span class="ltms-btn-spinner" style="display:none;">&#9696;</span>
        </button>

    </form>

    <div class="ltms-auth-footer">
        <p>
            <?php esc_html_e( '¿No tienes cuenta?', 'ltms' ); ?>
            <?php
            $ltms_register_id  = $ltms_pages_login['ltms-vendor-register'] ?? 0;
            $ltms_register_url = $ltms_register_id ? get_permalink( $ltms_register_id ) : home_url( '/registro-vendedor/' );
            ?>
            <a href="<?php echo esc_url( $ltms_register_url ); ?>" class="ltms-auth-switch-link">
                <?php esc_html_e( 'Regístrate como vendedor', 'ltms' ); ?>
            </a>
        </p>
    </div>

</div><!-- .ltms-login-card -->
