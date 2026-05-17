<?php
/**
 * Sección de configuración: ZapSign — Firma Electrónica
 * Tab: zapsign
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$enabled       = get_option( 'ltms_zapsign_enabled', 'no' );
$api_token     = get_option( 'ltms_zapsign_api_token', '' );
$kyc_enabled   = get_option( 'ltms_kyc_zapsign_enabled', 'no' );
$webhook_url   = home_url( '/wp-json/ltms/v1/webhooks/zapsign' );
$is_configured = ! empty( $api_token ) && $api_token !== '';
?>

<p style="color:#666;margin-bottom:16px;">
    <?php esc_html_e( 'ZapSign permite enviar contratos digitales a vendedores para firma electrónica. Cuando el vendedor firma, su KYC se aprueba automáticamente.', 'ltms' ); ?>
</p>

<?php if ( $is_configured ) : ?>
<div class="notice notice-success inline" style="margin:0 0 16px;padding:8px 12px;">
    <p>✅ <strong><?php esc_html_e( 'ZapSign configurado.', 'ltms' ); ?></strong>
    <?php esc_html_e( 'El token API está guardado.', 'ltms' ); ?></p>
</div>
<?php else : ?>
<div class="notice notice-warning inline" style="margin:0 0 16px;padding:8px 12px;">
    <p>⚠️ <strong><?php esc_html_e( 'ZapSign no configurado.', 'ltms' ); ?></strong>
    <?php esc_html_e( 'Ingresa el token API para activar la firma electrónica.', 'ltms' ); ?></p>
</div>
<?php endif; ?>

<table class="form-table" role="presentation">
    <tr>
        <th scope="row"><?php esc_html_e( 'ZapSign Activo', 'ltms' ); ?></th>
        <td>
            <label>
                <input type="checkbox" name="ltms_zapsign_enabled" value="yes" <?php checked( $enabled, 'yes' ); ?>>
                <?php esc_html_e( 'Activar firma electrónica con ZapSign', 'ltms' ); ?>
            </label>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="ltms_zapsign_api_token"><?php esc_html_e( 'Token API', 'ltms' ); ?></label></th>
        <td>
            <input type="password" id="ltms_zapsign_api_token" name="ltms_zapsign_api_token"
                   value="<?php echo esc_attr( $api_token ); ?>"
                   class="regular-text"
                   placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
                   autocomplete="new-password">
            <p class="description">
                <?php esc_html_e( 'Ve a app.zapsign.com.br → Configuración → API → copia tu token.', 'ltms' ); ?>
            </p>
        </td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e( 'Auto-aprobación KYC', 'ltms' ); ?></th>
        <td>
            <label>
                <input type="checkbox" name="ltms_kyc_zapsign_enabled" value="yes" <?php checked( $kyc_enabled, 'yes' ); ?>>
                <?php esc_html_e( 'Aprobar KYC automáticamente cuando el vendedor firma el contrato', 'ltms' ); ?>
            </label>
            <p class="description"><?php esc_html_e( 'Recomendado: activo. El webhook de ZapSign dispara la aprobación.', 'ltms' ); ?></p>
        </td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e( 'URL Webhook', 'ltms' ); ?></th>
        <td>
            <code style="background:#f0f0f1;padding:4px 8px;border-radius:4px;font-size:13px;">
                <?php echo esc_url( $webhook_url ); ?>
            </code>
            <p class="description">
                <?php esc_html_e( 'Copia esta URL en ZapSign → Configuración → Webhooks → URL de notificación.', 'ltms' ); ?>
            </p>
        </td>
    </tr>
</table>

<hr style="margin:24px 0;">
<h3><?php esc_html_e( 'Cómo configurar ZapSign', 'ltms' ); ?></h3>
<ol style="color:#444;line-height:1.8;">
    <li><?php esc_html_e( 'Crea cuenta en ', 'ltms' ); ?><a href="https://app.zapsign.com.br" target="_blank">app.zapsign.com.br</a></li>
    <li><?php esc_html_e( 'Ve a Configuración → API → copia el Token API', 'ltms' ); ?></li>
    <li><?php esc_html_e( 'Pégalo arriba y guarda', 'ltms' ); ?></li>
    <li><?php esc_html_e( 'Ve a Configuración → Webhooks → pega la URL del webhook de arriba', 'ltms' ); ?></li>
    <li><?php esc_html_e( 'Activa "Auto-aprobación KYC" para que los contratos firmados aprueben vendedores automáticamente', 'ltms' ); ?></li>
</ol>
