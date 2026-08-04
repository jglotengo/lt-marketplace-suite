<?php
/**
 * Partial: Formulario de inicio de sesión del vendedor
 *
 * @package    LTMS\Frontend\Views
 * @version    1.5.0
 */

defined( 'ABSPATH' ) || exit;

// REG-AUDIT-002 F3: Notices desde parámetros GET (Google OAuth error, reenvío de
// verificación, verificación exitosa). Antes estos parámetros llegaban pero el
// form-login nunca los renderizaba — el usuario veía el form vacío sin contexto.
$ltms_login_notice = '';
$ltms_login_notice_type = 'info';
if ( isset( $_GET['ltms_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $ltms_login_notice = sanitize_text_field( wp_unslash( $_GET['ltms_error'] ) ); // phpcs:ignore
    $ltms_login_notice_type = 'error';
} elseif ( isset( $_GET['resend_verification'] ) && $_GET['resend_verification'] === '1' ) { // phpcs:ignore
    $ltms_login_notice_type = 'warning';
    $ltms_login_notice = 'resend_verification'; // marker — el bloque abajo construye HTML especíal
}
?>

<?php if ( $ltms_login_notice === 'resend_verification' ) : ?>
<div id="ltms-resend-verify-wrap" class="ltms-notice ltms-notice-warning" style="display:block;background:#fef3c7;border:1px solid #f59e0b;border-radius:8px;padding:16px 18px;margin-bottom:16px;" role="alert">
    <p style="margin:0 0 8px;color:#92400e;font-weight:700;">📧 Tu email aún no está verificado</p>
    <p style="margin:0 0 12px;color:#92400e;font-size:0.9rem;line-height:1.5;">
        Para acceder a tu panel de vendedor primero debes verificar tu email. Revisa tu bandeja de entrada y carpeta de spam. Si no recibiste el correo o se perdió, reenvíalo:
    </p>
    <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
        <div style="flex:1;min-width:180px;">
            <label for="ltms-resend-email" style="display:block;font-size:0.8rem;color:#92400e;margin-bottom:4px;font-weight:600;">Tu email o usuario de vendedor:</label>
            <input type="text" id="ltms-resend-email" class="ltms-form-control" placeholder="tu@email.com" style="width:100%;">
        </div>
        <button type="button" id="ltms-resend-verify-btn" class="ltms-btn ltms-btn-primary" style="background:#b45309;padding:10px 18px;font-size:0.875rem;">
            <span class="ltms-resend-btn-text">Reenviar email</span>
            <span class="ltms-resend-btn-spinner" style="display:none;">⏳</span>
        </button>
    </div>
    <p id="ltms-resend-verify-msg" style="margin:8px 0 0;font-size:0.82rem;color:#92400e;display:none;"></p>
</div>
<script>
(function(){
    var btn = document.getElementById('ltms-resend-verify-btn');
    if (!btn) return;
    btn.addEventListener('click', function(){
        var email = document.getElementById('ltms-resend-email');
        var msg = document.getElementById('ltms-resend-verify-msg');
        var txt = btn.querySelector('.ltms-resend-btn-text');
        var spin = btn.querySelector('.ltms-resend-btn-spinner');
        if (!email || !email.value.trim()) {
            if (msg) { msg.textContent = 'Ingresa tu email o usuario.'; msg.style.display = 'block'; }
            return;
        }
        btn.disabled = true; if (txt) txt.style.display='none'; if (spin) spin.style.display='inline-block';
        var body = new URLSearchParams();
        body.append('action', 'ltms_resend_verification');
        body.append('email', email.value.trim());
        body.append('nonce', (typeof ltmsAuth !== 'undefined' && ltmsAuth.nonce) ? ltmsAuth.nonce : '');
        fetch((typeof ltmsAuth !== 'undefined' && ltmsAuth.ajax_url) ? ltmsAuth.ajax_url : '/wp-admin/admin-ajax.php', {
            method:'POST', credentials:'same-origin',
            headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
            body: body.toString()
        }).then(function(r){ return r.json(); })
          .then(function(data){
            btn.disabled = false; if (txt) txt.style.display=''; if (spin) spin.style.display='none';
            if (msg) {
                msg.textContent = (data.success && data.data && data.data.message)
                    ? data.data.message
                    : 'No se pudo enviar el email. Intenta de nuevo.';
                msg.style.display = 'block';
            }
          })
          .catch(function(){
            btn.disabled = false; if (txt) txt.style.display=''; if (spin) spin.style.display='none';
            if (msg) { msg.textContent = 'Error de conexión. Intenta de nuevo.'; msg.style.display = 'block'; }
          });
    });
})();
</script>
<?php elseif ( $ltms_login_notice && $ltms_login_notice_type === 'error' ) : ?>
<div class="ltms-notice ltms-notice-error" style="display:block;background:#fee2e2;border:1px solid #ef4444;border-radius:8px;padding:14px 18px;margin-bottom:16px;" role="alert">
    <p style="margin:0;color:#991b1b;font-weight:600;"><?php echo esc_html( $ltms_login_notice ); ?></p>
</div>
<?php endif; ?>

<?php
// REG-AUDIT-002 F7: aviso admin-only cuando Google OAuth no está configurado.
// Los vendedores reportan "el login con Google no funciona" — en muchos casos
// es que el botón NO aparece porque las credenciales nunca se configuraron.
// Este aviso SOLO lo ve el admin (no el visitante final) y le indica cómo
// configurarlo. Enlace al admin directamente.
if ( current_user_can( 'manage_options' ) && class_exists( 'LTMS_Google_OAuth' ) && ! LTMS_Google_OAuth::is_configured() ) :
    $google_settings_url = admin_url( 'admin.php?page=ltms-settings&tab=google_oauth' );
    ?>
<div class="ltms-notice" style="background:#fef3c7;border:1.5px solid #f59e0b;border-radius:8px;padding:12px 16px;margin-bottom:16px;" role="alert">
    <p style="margin:0;color:#92400e;font-size:0.88rem;line-height:1.5;">
        <strong>⚠️ Aviso del admin:</strong> El botón "Continuar con Google" no aparece en esta página porque las credenciales de Google OAuth no están configuradas. Los vendedores que intentan ingresar con Google no pueden hacerlo.
        <a href="<?php echo esc_url( $google_settings_url ); ?>" style="color:#92400e;text-decoration:underline;font-weight:700;">Configurar Google OAuth →</a>
    </p>
</div>
<?php endif; ?>

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

    <?php if ( class_exists( 'LTMS_Google_OAuth' ) && LTMS_Google_OAuth::is_configured() ) : ?>
    <div class="ltms-oauth-divider">
        <span><?php esc_html_e( 'o continúa con', 'ltms' ); ?></span>
    </div>
    <?php echo LTMS_Google_OAuth::render_google_button(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
    <?php else : ?>
    <div class="ltms-notice ltms-notice-info" style="background:#f3f4f6;border:1px solid #d1d5db;border-radius:8px;padding:10px 14px;margin-top:14px;text-align:center;font-size:0.82rem;color:#4b5563;" role="note">
        <?php esc_html_e( 'Continuar con Google no está disponible en este momento. Usa tu email y contraseña, o crea una cuenta nueva.', 'ltms' ); ?>
    </div>
    <?php endif; ?>

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
