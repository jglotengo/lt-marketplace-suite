<?php
/**
 * Vista: Admin Settings - Configuración del Plugin
 *
 * @package LTMS
 * @version 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$active_tab = sanitize_key( $_GET['tab'] ?? 'general' ); // phpcs:ignore

$tabs = [
    'general'     => __( "General", "ltms" ),
    'commissions' => __( "Comisiones", "ltms" ),
    'payments'    => __( "Pagos / Pasarelas", "ltms" ),
    'siigo'       => __( "Siigo ERP", "ltms" ),
    'kyc'         => __( "KYC / Compliance", "ltms" ),
    'mlm'         => __( "Marketing / MLM", "ltms" ),
    'security'    => __( "Seguridad", "ltms" ),
    'emails'      => __( "Emails", "ltms" ),
    // v1.6.0 — Módulos Enterprise
    'backblaze'   => __( "Backblaze B2", "ltms" ),
    'uber_direct' => __( "Uber Direct", "ltms" ),
    'heka'        => __( "Heka Entrega", "ltms" ),
    'aveonline'   => __( "Aveonline", "ltms" ),
    'xcover'      => __( "Seguros XCover", "ltms" ),
    // v2.1.0 — Contabilidad
    'alegra'      => __( "Alegra Contabilidad", "ltms" ),
    // v2.2.0 — Autenticación social (M-62)
    'google_oauth' => __( "Google OAuth", "ltms" ),
    // v2.2.0 — Firma electrónica
    'zapsign'     => __( "ZapSign Firma", "ltms" ),
    'deprisa'     => __( "Deprisa", "ltms" ),
    // v2.3.0 — Analytics & Tracking
    'analytics'   => __( "Analytics / Tracking", "ltms" ),
    // v3.1.0 — Cross-Border Commerce (Task 63-C)
    'cross_border'=> __( "Cross-Border", "ltms" ),
    // v2.9.13 — Privacy / Habeas Data / ARCO
    'privacy'     => __( "Privacidad / ARCO", "ltms" ),
];

// Bienestar: mostrar aviso si vienen del wizard de activación
$is_welcome = ! empty( $_GET['ltms_welcome'] ); // phpcs:ignore
?>
<div class="wrap ltms-admin-wrap">

    <div class="ltms-header">
        <h1><?php esc_html_e( 'Configuración LTMS', 'ltms' ); ?></h1>
    </div>

    <?php if ( $is_welcome ) : ?>
    <div class="notice notice-success is-dismissible">
        <p><strong><?php esc_html_e( '¡Bienvenido a LT Marketplace Suite!', 'ltms' ); ?></strong>
        <?php esc_html_e( 'Configura los ajustes básicos para comenzar a operar.', 'ltms' ); ?></p>
    </div>
    <?php endif; ?>

    <!-- Tabs de navegación -->
    <nav class="nav-tab-wrapper" style="margin-bottom:0">
        <?php foreach ( $tabs as $slug => $label ) : ?>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ltms-settings&tab=' . $slug ) ); ?>"
           class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>">
            <?php echo esc_html( $label ); ?>
        </a>
        <?php endforeach; ?>
    </nav>

    <form class="ltms-settings-form" id="ltms-settings-form-<?php echo esc_attr( $active_tab ); ?>">
        <?php wp_nonce_field( 'ltms_settings_nonce', 'ltms_nonce' ); ?>
        <input type="hidden" name="tab" value="<?php echo esc_attr( $active_tab ); ?>">

        <?php
        // Buscar el archivo de sección: probar primero con el slug tal cual,
        // luego con underscores convertidos a hyphens (cross_border → cross-border).
        $section_file = LTMS_INCLUDES_DIR . 'admin/views/settings/section-' . $active_tab . '.php';
        if ( ! file_exists( $section_file ) ) {
            $alt_file = LTMS_INCLUDES_DIR . 'admin/views/settings/section-' . str_replace( '_', '-', $active_tab ) . '.php';
            if ( file_exists( $alt_file ) ) {
                $section_file = $alt_file;
            }
        }
        if ( file_exists( $section_file ) ) {
            include_once $section_file;
        } else {
            echo '<div class="notice notice-warning inline" style="margin:16px 0;"><p>';
            echo '<strong>Sección "' . esc_html( $active_tab ) . '" no encontrada.</strong> ';
            echo 'Intenta desactivar y reactivar el plugin.</p></div>';
        }
        ?>

        <div style="margin-top:24px;padding-top:16px;border-top:1px solid #eee;display:flex;gap:12px;align-items:center;">
            <button type="submit" class="ltms-btn ltms-btn-primary" id="ltms-save-settings">
                💾 <?php esc_html_e( 'Guardar Cambios', 'ltms' ); ?>
            </button>
            <span class="ltms-save-status" style="display:none;color:#27ae60;"></span>
        </div>
    </form>

</div>

<script>
jQuery(function($) {
    $('#ltms-settings-form-<?php echo esc_js( $active_tab ); ?>').on('submit', function(e) {
        e.preventDefault();
        var $btn = $('#ltms-save-settings');
        var $status = $('.ltms-save-status');

        $btn.prop('disabled', true).text('<?php echo esc_js( __( "Guardando...", "ltms" ) ); ?>');

        var data = {};
        $(this).find('input, select, textarea').each(function() {
            var name = $(this).attr('name');
            if (!name || name === 'ltms_nonce' || name === 'tab' || name === '_wp_http_referer') return;
            if ($(this).attr('type') === 'checkbox') {
                data[name] = $(this).is(':checked') ? 'yes' : 'no';
            } else {
                data[name] = $(this).val();
            }
        });

        $.post(ltmsAdmin.ajax_url, {
            action: 'ltms_save_settings_section',
            nonce: ltmsAdmin.nonce,
            section: '<?php echo esc_js( $active_tab ); ?>',
            data: data
        }, function(response) {
            $btn.prop('disabled', false).text('💾 <?php echo esc_js( __( "Guardar Cambios", "ltms" ) ); ?>');
            if (response.success) {
                $status.text('✓ ' + response.data.message).show().delay(3000).fadeOut();
                LTMS.Admin.showNotice('success', response.data.message);
            } else {
                LTMS.Admin.showNotice('error', response.data || '<?php echo esc_js( __( "Error al guardar.", "ltms" ) ); ?>');
            }
        });
    });
});
</script>
<?php

/**
 * Renderiza una sección de configuración genérica con los campos del grupo.
 *
 * @param string $tab       Pestaña activa.
 * @param array  $tab_labels Mapa slug => etiqueta para el título de sección.
 */
if ( ! function_exists( 'ltms_render_generic_settings_section' ) ) :
function ltms_render_generic_settings_section( string $tab, array $tab_labels = [] ): void {
    $fields_map = [
        'general' => [
            [ 'key' => 'ltms_platform_name',    'label' => __( "Nombre de la Plataforma", "ltms" ),   'type' => 'text',   'default' => get_bloginfo( 'name' ) ?? '' ],
            [ 'key' => 'ltms_country',           'label' => __( "País Principal", "ltms" ),            'type' => 'select', 'options' => [ 'CO' => 'Colombia', 'MX' => 'México' ] ],
            [ 'key' => 'ltms_environment',       'label' => __( "Entorno", "ltms" ),                   'type' => 'select', 'options' => [ 'sandbox' => 'Sandbox (Pruebas)', 'production' => 'Producción' ] ],
            [ 'key' => 'ltms_currency',          'label' => __( "Moneda", "ltms" ),                    'type' => 'select', 'options' => [ 'COP' => 'COP (Peso Colombiano)', 'MXN' => 'MXN (Peso Mexicano)' ] ],
        ],
        'commissions' => [
            [ 'key' => 'ltms_platform_commission_rate', 'label' => __( "Comisión Base Plataforma (%)", "ltms" ), 'type' => 'number', 'default' => '10', 'attrs' => 'min="0" max="100" step="0.1"' ],
            [ 'key' => 'ltms_premium_commission_rate',  'label' => __( "Comisión Vendedor Premium (%)", "ltms" ), 'type' => 'number', 'default' => '8', 'attrs' => 'min="0" max="100" step="0.1"' ],
            [ 'key' => 'ltms_volume_tiers_enabled',     'label' => __( "Tiers de Volumen", "ltms" ),            'type' => 'checkbox', 'default' => 'no' ],
        ],
        'payments' => [
            [ 'key' => 'ltms_openpay_enabled',      'label' => __( "Openpay Activo", "ltms" ),           'type' => 'checkbox' ],
            [ 'key' => 'ltms_openpay_merchant_id',  'label' => __( "Openpay Merchant ID", "ltms" ),      'type' => 'text' ],
            [ 'key' => 'ltms_openpay_public_key',   'label' => __( "Openpay Public Key", "ltms" ),       'type' => 'text' ],
            [ 'key' => 'ltms_openpay_private_key',  'label' => __( "Openpay Private Key (cifrado)", "ltms" ), 'type' => 'password', 'desc' => __( "Se guarda cifrado con AES-256", "ltms" ) ],
            [ 'key' => 'ltms_pse_enabled',          'label' => __( "PSE (Solo Colombia)", "ltms" ),      'type' => 'checkbox', 'default' => 'yes' ],
            [ 'key' => 'ltms_addi_enabled',         'label' => __( "Addi BNPL Activo", "ltms" ),         'type' => 'checkbox' ],
        ],
        'siigo' => [
            [ 'key' => 'ltms_siigo_enabled',    'label' => __( "Siigo Activo", "ltms" ),             'type' => 'checkbox' ],
            [ 'key' => 'ltms_siigo_username',   'label' => __( "Siigo Usuario", "ltms" ),            'type' => 'text' ],
            [ 'key' => 'ltms_siigo_password',   'label' => __( "Siigo Contraseña (cifrado)", "ltms" ), 'type' => 'password', 'desc' => __( "Se guarda cifrado", "ltms" ) ],
            [ 'key' => 'ltms_siigo_partner_id', 'label' => __( "Partner Token", "ltms" ),            'type' => 'text' ],
        ],
        'kyc' => [
            [ 'key' => 'ltms_kyc_required_for_payout', 'label' => __( "KYC Requerido para Retiros", "ltms" ), 'type' => 'checkbox', 'default' => 'yes' ],
            [ 'key' => 'ltms_kyc_auto_approve',        'label' => __( "Aprobación Automática", "ltms" ),      'type' => 'checkbox', 'default' => 'no' ],
            [ 'key' => 'ltms_min_payout_amount',       'label' => __( "Monto Mínimo Retiro", "ltms" ),        'type' => 'number', 'default' => '50000' ],
        ],
        'mlm' => [
            [ 'key' => 'ltms_mlm_enabled',     'label' => __( "Red de Referidos Activa", "ltms" ),  'type' => 'checkbox' ],
            [ 'key' => 'ltms_tptc_enabled',    'label' => __( "TPTC Sincronización", "ltms" ),      'type' => 'checkbox' ],
            [ 'key' => 'ltms_tptc_api_key',    'label' => __( "TPTC API Key (cifrado)", "ltms" ),   'type' => 'password' ],
            [ 'key' => 'ltms_tptc_program_id', 'label' => __( "TPTC Program ID", "ltms" ),          'type' => 'text' ],
            [ 'key' => 'ltms_referral_rates',  'label' => __( "Tasas por Nivel (JSON)", "ltms" ),   'type' => 'textarea', 'default' => '[0.40, 0.20, 0.10]', 'desc' => __( "Array JSON: [nivel1, nivel2, nivel3]", "ltms" ) ],
        ],
        'security' => [
            [ 'key' => 'ltms_waf_enabled',         'label' => __( "WAF Activo", "ltms" ),             'type' => 'checkbox', 'default' => 'yes' ],
            [ 'key' => 'ltms_waf_block_threshold',  'label' => __( "Umbral de Bloqueo WAF", "ltms" ), 'type' => 'number', 'default' => '10' ],
            [ 'key' => 'ltms_rate_limit_enabled',   'label' => __( "Rate Limiting API", "ltms" ),     'type' => 'checkbox', 'default' => 'yes' ],
        ],
        'emails' => [
            [ 'key' => 'ltms_email_from_name',    'label' => __( "Nombre Remitente", "ltms" ),   'type' => 'text',  'default' => get_bloginfo( 'name' ) ?? '' ],
            [ 'key' => 'ltms_email_from_address', 'label' => __( "Email Remitente", "ltms" ),    'type' => 'email', 'default' => get_option( 'admin_email' ) ?? '' ],
            [ 'key' => 'ltms_email_header_color', 'label' => __( "Color Header Email", "ltms" ), 'type' => 'text',  'default' => '#1a5276' ],
        ],
        // v1.6.0 — Enterprise Module Settings
        'backblaze' => [
            [ 'key' => 'ltms_backblaze_endpoint',       'label' => __( "Endpoint S3 (URL base)", "ltms" ),         'type' => 'text',     'default' => 'https://s3.us-west-004.backblazeb2.com', 'desc' => __( "Ej: https://s3.us-west-004.backblazeb2.com", "ltms" ) ],
            [ 'key' => 'ltms_backblaze_key_id',         'label' => __( "Key ID", "ltms" ),                         'type' => 'text' ],
            [ 'key' => 'ltms_backblaze_app_key',        'label' => __( "Application Key 🔐", "ltms" ),             'type' => 'password', 'desc' => __( "Se guarda cifrado con AES-256. Dejar vacío para no cambiar.", "ltms" ) ],
            [ 'key' => 'ltms_backblaze_default_bucket', 'label' => __( "Bucket Público (defecto)", "ltms" ),       'type' => 'text' ],
            [ 'key' => 'ltms_backblaze_private_bucket', 'label' => __( "Bucket Privado (KYC/Facturas)", "ltms" ),  'type' => 'text' ],
        ],
        'uber_direct' => [
            [ 'key' => 'ltms_uber_direct_client_id',      'label' => __( "Uber Direct Client ID", "ltms" ),           'type' => 'text' ],
            [ 'key' => 'ltms_uber_direct_client_secret',  'label' => __( "Client Secret 🔐", "ltms" ),                'type' => 'password', 'desc' => __( "Se guarda cifrado con AES-256.", "ltms" ) ],
            [ 'key' => 'ltms_uber_direct_customer_id',    'label' => __( "Customer ID", "ltms" ),                     'type' => 'text' ],
            [ 'key' => 'ltms_uber_direct_webhook_secret', 'label' => __( "Webhook Secret (HMAC)", "ltms" ),           'type' => 'text',     'desc' => __( "Para validar firmas de webhooks Uber.", "ltms" ) ],
        ],
        'heka' => [
            [ 'key' => 'ltms_heka_api_key',    'label' => __( "Heka API Key 🔐", "ltms" ),   'type' => 'password', 'desc' => __( "Se guarda cifrado con AES-256.", "ltms" ) ],
            [ 'key' => 'ltms_heka_account_id', 'label' => __( "Heka Account ID", "ltms" ),   'type' => 'text' ],
        ],
        'aveonline' => [
            [ 'key' => 'ltms_aveonline_enabled',          'label' => __( "Habilitar Aveonline", "ltms" ),              'type' => 'checkbox', 'default' => 'no' ],
            [ 'key' => 'ltms_aveonline_usuario',          'label' => __( "Usuario Aveonline", "ltms" ),                'type' => 'text',     'desc' => __( "Usuario de ingreso a la plataforma Aveonline.", "ltms" ) ],
            [ 'key' => 'ltms_aveonline_clave',            'label' => __( "Contraseña 🔐", "ltms" ),                   'type' => 'password', 'desc' => __( "Se guarda cifrado con AES-256. Dejar vacío para no cambiar.", "ltms" ) ],
            [ 'key' => 'ltms_aveonline_idempresa',        'label' => __( "ID Empresa (idempresa)", "ltms" ),           'type' => 'text',     'desc' => __( "Número de ID de la empresa dentro de Aveonline. Se obtiene al autenticarse.", "ltms" ) ],
            [ 'key' => 'ltms_aveonline_idagente',         'label' => __( "ID Agente (idagente)", "ltms" ),             'type' => 'text',     'desc' => __( "Agente logístico asociado a la cuenta.", "ltms" ) ],
            [ 'key' => 'ltms_aveonline_idtransportador',  'label' => __( "Transportadora por defecto", "ltms" ),      'type' => 'text',     'desc' => __( "Código de la transportadora (ej: 29 = ENVIA). Vacío = cotizar todas.", "ltms" ) ],
            [ 'key' => 'ltms_aveonline_codigo',           'label' => __( "Código de guía (codigo)", "ltms" ),         'type' => 'text',     'desc' => __( "Usuario secundario para generación de guías (campo codigo).", "ltms" ) ],
            [ 'key' => 'ltms_aveonline_clave_guia',       'label' => __( "Clave de guía 🔐 (dsclavex)", "ltms" ),     'type' => 'password', 'desc' => __( "Contraseña secundaria para generación de guías. Se guarda cifrado.", "ltms" ) ],
            [ 'key' => 'ltms_store_city',                 'label' => __( "Ciudad de origen (bodega)", "ltms" ),       'type' => 'text',     'default' => 'Bogotá', 'desc' => __( "Ciudad desde donde se despachan los paquetes. Ej: MEDELLIN(ANTIOQUIA)", "ltms" ) ],
        ],
        'xcover' => [
            [ 'key' => 'ltms_xcover_api_key',               'label' => __( "XCover API Key 🔐", "ltms" ),               'type' => 'password', 'desc' => __( "Se guarda cifrado con AES-256.", "ltms" ) ],
            [ 'key' => 'ltms_xcover_partner_code',          'label' => __( "XCover Partner Code", "ltms" ),             'type' => 'text' ],
            [ 'key' => 'ltms_xcover_parcel_protection',     'label' => __( "Protección del Paquete", "ltms" ),          'type' => 'checkbox', 'default' => 'no',  'desc' => __( "Muestra opción de seguro de paquete en checkout.", "ltms" ) ],
            [ 'key' => 'ltms_xcover_purchase_protection',   'label' => __( "Protección de Compra", "ltms" ),            'type' => 'checkbox', 'default' => 'no',  'desc' => __( "Muestra opción de protección de compra en checkout.", "ltms" ) ],
        ],
        // v2.1.0 — Alegra Contabilidad
        'alegra' => [
            [ 'key' => 'ltms_alegra_enabled',                'label' => __( "Alegra Activo", "ltms" ),                       'type' => 'checkbox', 'default' => 'no' ],
            [ 'key' => 'ltms_alegra_email',                  'label' => __( "Email de la cuenta Alegra", "ltms" ),           'type' => 'email',    'desc' => __( "El mismo email con el que accedes a app.alegra.com.", "ltms" ) ],
            [ 'key' => 'ltms_alegra_token',                  'label' => __( "Token de API Alegra 🔐", "ltms" ),              'type' => 'password', 'desc' => __( "Ajustes → API en Alegra. Se guarda cifrado con AES-256.", "ltms" ) ],
            [ 'key' => 'ltms_alegra_default_number_template','label' => __( "ID Numeración de Facturas", "ltms" ),           'type' => 'number',   'desc' => __( "ID de la plantilla de numeración en Alegra (ej: 1). Déjalo en 0 para usar la numeración por defecto.", "ltms" ) ],
            [ 'key' => 'ltms_alegra_bank_account_id',        'label' => __( "ID Cuenta Bancaria Alegra", "ltms" ),           'type' => 'number',   'desc' => __( "ID de la cuenta bancaria en Alegra para registrar pagos de retiros.", "ltms" ) ],
            [ 'key' => 'ltms_alegra_invoice_on_processing',  'label' => __( "Crear factura en 'En proceso'", "ltms" ),       'type' => 'checkbox', 'default' => 'no',  'desc' => __( "Por defecto, la factura se crea cuando el pedido llega a 'Completado'.", "ltms" ) ],
            [ 'key' => 'ltms_alegra_send_invoice_email',     'label' => __( "Enviar factura por email", "ltms" ),            'type' => 'checkbox', 'default' => 'no',  'desc' => __( "Alegra envía automáticamente la factura al email del comprador.", "ltms" ) ],
            [ 'key' => 'ltms_alegra_auto_payment',           'label' => __( "Registrar pago automáticamente", "ltms" ),       'type' => 'checkbox', 'default' => 'no',  'desc' => __( "Registra el pago en Alegra al crear la factura. Requiere ID Cuenta Bancaria.", "ltms" ) ],
            [ 'key' => 'ltms_alegra_commission_account_id',  'label' => __( "ID Cuenta Comisiones Plataforma", "ltms" ),      'type' => 'number',   'desc' => __( "ID de cuenta bancaria en Alegra donde se registran las comisiones del marketplace. Déjalo en 0 para omitir.", "ltms" ) ],
            [ 'key' => 'ltms_alegra_retefuente_tax_id',      'label' => __( "ID Impuesto Retención en la Fuente (CO)", "ltms" ),   'type' => 'number',   'desc' => __( "ID del impuesto de retefuente en Alegra (Configuración → Impuestos). Déjalo en 0 si no aplica.", "ltms" ) ],
            [ 'key' => 'ltms_alegra_reteiva_tax_id',         'label' => __( "ID Impuesto ReteIVA (CO)", "ltms" ),                 'type' => 'number',   'desc' => __( "NC-1: ID del impuesto ReteIVA (15% del IVA) en Alegra. Aplica cuando el vendor es gran contribuyente. Déjalo en 0 si no aplica.", "ltms" ) ],
            [ 'key' => 'ltms_alegra_reteica_tax_id',         'label' => __( "ID Impuesto ReteICA (CO)", "ltms" ),                 'type' => 'number',   'desc' => __( "NC-1: ID del impuesto ReteICA (municipal) en Alegra. Aplica cuando el vendor tiene CIIU + municipio. Déjalo en 0 si no aplica.", "ltms" ) ],
            [ 'key' => 'ltms_alegra_inc_tax_id',             'label' => __( "ID Impuesto Impoconsumo (CO)", "ltms" ),             'type' => 'number',   'desc' => __( "NC-5: ID del impuesto Impoconsumo (8% restaurantes) en Alegra. Déjalo en 0 si no aplica.", "ltms" ) ],
            [ 'key' => 'ltms_alegra_iva_retenido_mx_tax_id', 'label' => __( "ID Impuesto IVA Retenido (MX)", "ltms" ),            'type' => 'number',   'desc' => __( "NC-1: ID del impuesto IVA retenido (4% persona moral, art. 1-A LIVA) en Alegra. Déjalo en 0 si no aplica.", "ltms" ) ],
            [ 'key' => 'ltms_alegra_ish_tax_id',             'label' => __( "ID Impuesto ISH Hospedaje (MX)", "ltms" ),            'type' => 'number',   'desc' => __( "ID del impuesto ISH (Impuesto Sobre Hospedaje) en Alegra. Déjalo en 0 si no aplica.", "ltms" ) ],
            [ 'key' => 'ltms_alegra_fx_sync',                'label' => __( "Sincronizar asientos FX con Alegra", "ltms" ),        'type' => 'checkbox', 'default' => 'yes',  'desc' => __( "NC-2: envía asientos de ganancia/pérdida cambiaria (NIIF 9 / NIF B-15) a Alegra.", "ltms" ) ],
            [ 'key' => 'ltms_alegra_fx_gain_account_id',     'label' => __( "ID Cuenta Ingreso FX (4255 PUC)", "ltms" ),           'type' => 'number',   'desc' => __( "NC-2: ID de la cuenta de ingreso por diferencia en cambio en Alegra (típicamente 4255 en PUC CO).", "ltms" ) ],
            [ 'key' => 'ltms_alegra_fx_loss_account_id',     'label' => __( "ID Cuenta Gasto FX (5255 PUC)", "ltms" ),             'type' => 'number',   'desc' => __( "NC-2: ID de la cuenta de gasto por diferencia en cambio en Alegra (típicamente 5255 en PUC CO).", "ltms" ) ],
            [ 'key' => 'ltms_alegra_shipping_tax_id',        'label' => __( "ID Impuesto para Envíos", "ltms" ),              'type' => 'number',   'desc' => __( "ID del impuesto a aplicar en el ítem de envío. Default: 1 (exento en Colombia).", "ltms" ) ],
            [ 'key' => 'ltms_alegra_exchange_rate',          'label' => __( "Tasa de cambio (moneda extranjera)", "ltms" ),   'type' => 'number',   'desc' => __( "Tasa de cambio a usar cuando el pedido no es en COP/MXN. Default: 1.", "ltms" ) ],
            [ 'key' => 'ltms_alegra_webhook_secret',         'label' => __( "Webhook Secret (token)", "ltms" ),              'type' => 'text',     'desc' => __( "Token para validar webhooks entrantes de Alegra. Configura este mismo valor en Alegra al crear la suscripción.", "ltms" ) ],
            [ 'key' => 'ltms_alegra_webhook_url',            'label' => __( "URL del Webhook (solo lectura)", "ltms" ),      'type' => 'text',     'default' => function_exists( 'home_url' ) ? home_url( '/wp-json/ltms/v1/webhooks/alegra' ) : '', 'attrs' => 'readonly style="background:#f9f9f9;cursor:default;"', 'desc' => __( "Registra esta URL en Alegra → Webhooks para recibir notificaciones de facturas.", "ltms" ) ],

            // NC-3 (v2.9.12) — Resolución DIAN Colombia.
            [ 'key' => 'ltms_dian_resolution_number',        'label' => __( "Número Resolución DIAN (CO)", "ltms" ),           'type' => 'text',     'desc' => __( "NC-3: Número de la resolución DIAN vigente (ej: 18764000004200). Res. DIAN 000042/2020 art. 5.", "ltms" ) ],
            [ 'key' => 'ltms_dian_resolution_date',          'label' => __( "Fecha Resolución DIAN", "ltms" ),                 'type' => 'text',     'desc' => __( "Fecha de la resolución DIAN (YYYY-MM-DD).", "ltms" ) ],
            [ 'key' => 'ltms_dian_prefix',                   'label' => __( "Prefijo Autorizado DIAN", "ltms" ),               'type' => 'text',     'desc' => __( "Prefijo autorizado por DIAN (ej: SET, SETP).", "ltms" ) ],
            [ 'key' => 'ltms_dian_range_from',               'label' => __( "Rango DIAN Desde", "ltms" ),                      'type' => 'text',     'desc' => __( "Número inicial del rango autorizado por DIAN.", "ltms" ) ],
            [ 'key' => 'ltms_dian_range_to',                 'label' => __( "Rango DIAN Hasta", "ltms" ),                      'type' => 'text',     'desc' => __( "Número final del rango autorizado por DIAN.", "ltms" ) ],
            [ 'key' => 'ltms_dian_technical_key',            'label' => __( "Clave Técnica DIAN", "ltms" ),                    'type' => 'password', 'desc' => __( "Clave técnica de configuración de software DIAN (se guarda cifrado).", "ltms" ) ],
        ],
    ];

    $fields = $fields_map[ $tab ] ?? [];
    if ( empty( $fields ) ) {
        echo '<div class="ltms-form-section"><p>' . esc_html__( "No hay configuraciones para esta sección.", "ltms" ) . '</p></div>';
        return;
    }

    echo '<div class="ltms-form-section">';
    echo '<h2>' . esc_html( $tab_labels[ $tab ] ?? $tab ) . '</h2>';

    foreach ( $fields as $field ) {
        $value = LTMS_Core_Config::get( $field['key'], $field['default'] ?? '' );

        // A-6 FIX: Los campos _rate/_percent se guardan como decimales (0.1 = 10%)
        // pero el UI los muestra como porcentaje — multiplicar por 100 al mostrar.
        if ( isset( $field['type'] ) && $field['type'] === 'number'
            && ( strpos( $field['key'], '_rate' ) !== false || strpos( $field['key'], '_percent' ) !== false )
            && is_numeric( $value ) && (float) $value <= 1 && (float) $value > 0 ) {
            $value = round( (float) $value * 100, 4 );
        }

        // No mostrar contraseñas cifradas en texto plano
        if ( ( $field['type'] ?? '' ) === 'password' && strpos( $value, 'v1:' ) === 0 ) {
            $value = '';
            $field['placeholder'] = __( "(guardado — dejar vacío para mantener)", "ltms" );
        }

        echo '<div class="ltms-form-row">';
        echo '<label for="' . esc_attr( $field['key'] ) . '">' . esc_html( $field['label'] ?? $field['key'] ) . '</label>';
        echo '<div>';

        switch ( $field['type'] ?? 'text' ) {
            case 'checkbox':
                echo '<input type="checkbox" id="' . esc_attr( $field['key'] ) . '" name="' . esc_attr( $field['key'] ) . '" value="yes"' . checked( $value, 'yes', false ) . '>';
                break;
            case 'select':
                echo '<select id="' . esc_attr( $field['key'] ) . '" name="' . esc_attr( $field['key'] ) . '">';
                foreach ( ( $field['options'] ?? [] ) as $opt_val => $opt_label ) {
                    echo '<option value="' . esc_attr( $opt_val ) . '"' . selected( $value, $opt_val, false ) . '>' . esc_html( $opt_label ) . '</option>';
                }
                echo '</select>';
                break;
            case 'textarea':
                echo '<textarea id="' . esc_attr( $field['key'] ) . '" name="' . esc_attr( $field['key'] ) . '" rows="3">' . esc_textarea( $value ) . '</textarea>';
                break;
            default:
                $attrs       = $field['attrs'] ?? '';
                $placeholder = isset( $field['placeholder'] ) ? 'placeholder="' . esc_attr( $field['placeholder'] ) . '"' : '';
                echo '<input type="' . esc_attr( $field['type'] ) . '" id="' . esc_attr( $field['key'] ) . '" name="' . esc_attr( $field['key'] ) . '" value="' . esc_attr( $value ) . '" ' . $placeholder . ' ' . $attrs . '>'; // phpcs:ignore
        }

        if ( ! empty( $field['desc'] ) ) {
            echo '<p class="description">' . esc_html( $field['desc'] ) . '</p>';
        }
        echo '</div></div>';
    }

    echo '</div>';
}
endif;