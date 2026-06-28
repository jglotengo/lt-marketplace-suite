<?php
/**
 * Sección de configuración: ZapSign — Firma Electrónica
 * Tab: zapsign
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$enabled            = get_option( 'ltms_zapsign_enabled', 'no' );
$api_token          = get_option( 'ltms_zapsign_api_token', '' );
$kyc_enabled        = get_option( 'ltms_kyc_zapsign_enabled', 'no' );
$template_id        = get_option( 'ltms_zapsign_vendor_template_id', '' );
$contract_pdf_url   = get_option( 'ltms_zapsign_contract_pdf_url', '' );
$attachment_id      = get_option( 'ltms_zapsign_contract_attachment_id', '' );
$sandbox            = get_option( 'ltms_zapsign_sandbox', 'no' );
$webhook_url        = home_url( '/wp-json/ltms/v1/webhooks/zapsign' );
$is_configured      = ! empty( $api_token ) && $api_token !== '';
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
        <th scope="row"><?php esc_html_e( 'Modo Sandbox', 'ltms' ); ?></th>
        <td>
            <label>
                <input type="checkbox" name="ltms_zapsign_sandbox" value="yes" <?php checked( $sandbox, 'yes' ); ?>>
                <?php esc_html_e( 'Usar sandbox (pruebas — los documentos NO tienen validez legal)', 'ltms' ); ?>
            </label>
            <p class="description"><?php esc_html_e( 'Desactiva en producción para que los contratos sean legalmente vinculantes.', 'ltms' ); ?></p>
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
</table>

<hr style="margin:24px 0;">
<h3><?php esc_html_e( '📄 Plantilla del Contrato', 'ltms' ); ?></h3>
<p style="color:#666;">
    <?php esc_html_e( 'Configura la plantilla del contrato. Opción A (recomendada): ID de plantilla ZapSign. Opción B: URL directa del PDF.', 'ltms' ); ?>
</p>

<table class="form-table" role="presentation">
    <tr>
        <th scope="row"><label for="ltms_zapsign_vendor_template_id"><?php esc_html_e( 'ID Plantilla ZapSign (recomendado)', 'ltms' ); ?></label></th>
        <td>
            <input type="text" id="ltms_zapsign_vendor_template_id" name="ltms_zapsign_vendor_template_id"
                   value="<?php echo esc_attr( $template_id ); ?>"
                   class="regular-text"
                   placeholder="526a9570-0160-42f9-999b-5b624527ba5e">
            <p class="description">
                <?php esc_html_e( 'ID de la plantilla en ZapSign (app.zapsign.com.br → Plantillas → URL del modelo). Con esto NO necesitas subir el PDF en cada envío. ', 'ltms' ); ?>
                <?php if ( ! empty( $template_id ) ) : ?>
                    <strong style="color:#2271b1;">✓ Plantilla configurada: <?php echo esc_html( $template_id ); ?></strong>
                <?php endif; ?>
            </p>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="ltms_zapsign_contract_pdf_url"><?php esc_html_e( 'URL del PDF del Contrato (alternativa)', 'ltms' ); ?></label></th>
        <td>
            <input type="url" id="ltms_zapsign_contract_pdf_url" name="ltms_zapsign_contract_pdf_url"
                   value="<?php echo esc_attr( $contract_pdf_url ); ?>"
                   class="large-text"
                   placeholder="https://lo-tengo.com.co/contratos/contrato-vendedor.pdf">
            <p class="description">
                <?php esc_html_e( 'URL pública del PDF del contrato. Se usa si no hay template_id. Debe ser accesible sin autenticación.', 'ltms' ); ?>
            </p>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="ltms_zapsign_contract_attachment_id"><?php esc_html_e( 'PDF desde Biblioteca de Medios (ID)', 'ltms' ); ?></label></th>
        <td>
            <input type="number" id="ltms_zapsign_contract_attachment_id" name="ltms_zapsign_contract_attachment_id"
                   value="<?php echo esc_attr( $attachment_id ); ?>"
                   class="small-text"
                   placeholder="0">
            <?php if ( $attachment_id ) :
                $att_url = wp_get_attachment_url( (int) $attachment_id );
            ?>
                <p class="description">
                    ✓ <a href="<?php echo esc_url( $att_url ); ?>" target="_blank"><?php echo esc_html( $att_url ); ?></a>
                </p>
            <?php else : ?>
            <p class="description">
                <?php esc_html_e( 'ID del adjunto en la Biblioteca de Medios de WordPress (alternativa a la URL manual).', 'ltms' ); ?>
            </p>
            <?php endif; ?>
        </td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e( 'URL Webhook', 'ltms' ); ?></th>
        <td>
            <code style="background:#f0f0f1;padding:4px 8px;border-radius:4px;font-size:13px;">
                <?php echo esc_url( $webhook_url ); ?>
            </code>
            <p class="description">
                <?php esc_html_e( 'Copia esta URL en ZapSign → Ajustes → Integraciones → URL de notificación (webhook).', 'ltms' ); ?>
            </p>
        </td>
    </tr>
</table>

<hr style="margin:24px 0;">
<h3><?php esc_html_e( '📋 Cómo configurar ZapSign', 'ltms' ); ?></h3>
<ol style="color:#444;line-height:2;">
    <li><?php esc_html_e( 'Crea cuenta en ', 'ltms' ); ?><a href="https://app.zapsign.com.br" target="_blank">app.zapsign.com.br</a></li>
    <li><?php esc_html_e( 'Ve a Ajustes → API → copia el Token API y pégalo arriba', 'ltms' ); ?></li>
    <li><?php esc_html_e( 'Sube el contrato como Plantilla en ZapSign (Plantillas → + Nueva)', 'ltms' ); ?></li>
    <li><?php esc_html_e( 'Copia el ID de la plantilla de la URL y pégalo en "ID Plantilla ZapSign" arriba', 'ltms' ); ?></li>
    <li><?php esc_html_e( 'Ve a Ajustes → Integraciones → pega la URL del webhook de arriba', 'ltms' ); ?></li>
    <li><?php esc_html_e( 'Activa "Auto-aprobación KYC" para que los contratos firmados aprueben vendedores automáticamente', 'ltms' ); ?></li>
    <li><?php esc_html_e( 'Desactiva "Modo Sandbox" cuando estés listo para producción', 'ltms' ); ?></li>
</ol>

