<?php
/**
 * Sección de configuración — Google OAuth (M-62)
 *
 * Permite configurar el Client ID y Client Secret de Google OAuth
 * para el login/registro con Google de vendedores.
 *
 * @package LTMS
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$client_id     = get_option( 'ltms_google_client_id', '' );
$client_secret = get_option( 'ltms_google_client_secret', '' );
$is_configured = class_exists( 'LTMS_Google_OAuth' ) && LTMS_Google_OAuth::is_configured();
$redirect_uri  = add_query_arg( 'ltms_oauth', 'google', home_url( '/' ) );
?>
<div class="ltms-settings-section">
    <h2 style="margin-top:24px;">🔑 Google OAuth — Login con Google</h2>

    <?php if ( $is_configured ) : ?>
    <div class="notice notice-success inline" style="margin:12px 0;">
        <p>✅ <strong><?php esc_html_e( 'Google OAuth configurado y activo.', 'ltms' ); ?></strong>
        <?php esc_html_e( 'Los vendedores pueden iniciar sesión con su cuenta de Google.', 'ltms' ); ?></p>
    </div>
    <?php else : ?>
    <div class="notice notice-warning inline" style="margin:12px 0;">
        <p>⚠️ <strong><?php esc_html_e( 'Google OAuth no configurado.', 'ltms' ); ?></strong>
        <?php esc_html_e( 'Ingresa el Client ID y Client Secret para activar el login con Google.', 'ltms' ); ?></p>
    </div>
    <?php endif; ?>

    <h3 style="margin:20px 0 4px;padding-bottom:6px;border-bottom:1px solid #ddd;">
        <?php esc_html_e( 'Credenciales de Google Cloud', 'ltms' ); ?>
    </h3>

    <table class="form-table" role="presentation"><tbody>
        <tr>
            <th scope="row"><label for="ltms_google_client_id"><?php esc_html_e( 'Client ID', 'ltms' ); ?></label></th>
            <td>
                <input type="text" id="ltms_google_client_id" name="ltms_google_client_id"
                       value="<?php echo esc_attr( $client_id === 'GOOGLE_CLIENT_ID_PLACEHOLDER' ? '' : $client_id ); ?>"
                       class="regular-text"
                       placeholder="xxxxxxxxxxxx-xxxx.apps.googleusercontent.com">
                <p class="description"><?php esc_html_e( 'El Client ID de tu proyecto en Google Cloud Console.', 'ltms' ); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="ltms_google_client_secret"><?php esc_html_e( 'Client Secret', 'ltms' ); ?></label></th>
            <td>
                <input type="password" id="ltms_google_client_secret" name="ltms_google_client_secret"
                       value="<?php echo esc_attr( $client_secret ); ?>"
                       class="regular-text"
                       autocomplete="new-password"
                       placeholder="<?php echo $client_secret ? '••••••••••••••••' : esc_attr__( 'Ingresa el Client Secret', 'ltms' ); ?>">
                <p class="description"><?php esc_html_e( 'Se guarda cifrado en la base de datos.', 'ltms' ); ?></p>
            </td>
        </tr>
    </tbody></table>

    <h3 style="margin:24px 0 4px;padding-bottom:6px;border-bottom:1px solid #ddd;">
        <?php esc_html_e( 'Configuración en Google Cloud Console', 'ltms' ); ?>
    </h3>
    <p><?php esc_html_e( 'En tu proyecto de Google Cloud Console, agrega los siguientes valores:', 'ltms' ); ?></p>
    <table class="form-table" role="presentation"><tbody>
        <tr>
            <th scope="row"><?php esc_html_e( 'Orígenes JS autorizados', 'ltms' ); ?></th>
            <td>
                <code><?php echo esc_html( home_url() ); ?></code>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e( 'URI de redireccionamiento', 'ltms' ); ?></th>
            <td>
                <code><?php echo esc_html( $redirect_uri ); ?></code>
                <p class="description"><?php esc_html_e( 'Copia esta URI exacta en los "URIs de redireccionamiento autorizados" del Client ID.', 'ltms' ); ?></p>
            </td>
        </tr>
    </tbody></table>
</div>
