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
    'general'     => __( 'General', 'ltms' ),
    'commissions' => __( 'Comisiones', 'ltms' ),
    'payments'    => __( 'Pagos / Pasarelas', 'ltms' ),
    'siigo'       => __( 'Siigo ERP', 'ltms' ),
    'kyc'         => __( 'KYC / Compliance', 'ltms' ),
    'mlm'         => __( 'Marketing / MLM', 'ltms' ),
    'security'    => __( 'Seguridad', 'ltms' ),
    'emails'      => __( 'Emails', 'ltms' ),
    // v1.6.0 — Módulos Enterprise
    'backblaze'   => __( 'Backblaze B2', 'ltms' ),
    'uber_direct' => __( 'Uber Direct', 'ltms' ),
    'heka'        => __( 'Heka Entrega', 'ltms' ),
    'xcover'      => __( 'Seguros XCover', 'ltms' ),
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
        $section_file = LTMS_INCLUDES_DIR . 'admin/views/settings/section-' . $active_tab . '.php';
        if ( file_exists( $section_file ) ) {
            include $section_file;
        } else {
            ltms_render_generic_settings_section( $active_tab );
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

        $btn.prop('disabled', true).text('<?php echo esc_js( __( 'Guardando...', 'ltms' ) ); ?>');

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
            $btn.prop('disabled', false).text('💾 <?php echo esc_js( __( 'Guardar Cambios', 'ltms' ) ); ?>');
            if (response.success) {
                $status.text('✓ ' + response.data.message).show().delay(3000).fadeOut();
                LTMS.Admin.showNotice('success', response.data.message);
            } else {
                LTMS.Admin.showNotice('error', response.data || '<?php echo esc_js( __( 'Error al guardar.', 'ltms' ) ); ?>');
            }
        });
    });
});
</script>
<?php

/**
 * Renderiza una sección de configuración genérica con los campos del grupo.
 *
 * @param string $tab Pestaña activa.
 */
function ltms_render_generic_settings_section( string $tab ): void {
    $fields_map = [
        'general' => [
            [ 'key' => 'ltms_platform_name',    'label' => __( 'Nombre de la Plataforma', 'ltms' ),   'type' => 'text',   'default' => get_bloginfo( 'name' ) ],
            [ 'key' => 'ltms_country',           'label' => __( 'País Principal', 'ltms' ),            'type' => 'select', 'options' => [ 'CO' => 'Colombia', 'MX' => 'México' ] ],
            [ 'key' => 'ltms_environment',       'label' => __( 'Entorno', 'ltms' ),                   'type' => 'select', 'options' => [ 'sandbox' => 'Sandbox (Pruebas)', 'production' => 'Producción' ] ],
            [ 'key' => 'ltms_currency',          'label' => __( 'Moneda', 'ltms' ),                    'type' => 'select', 'options' => [ 'COP' => 'COP (Peso Colombiano)', 'MXN' => 'MXN (Peso Mexicano)' ] ],
        ],
        'commissions' => [
            [ 'key' => 'ltms_platform_commission_rate', 'label' => __( 'Comisión Base Plataforma (%)', 'ltms' ), 'type' => 'number', 'default' => '10', 'attrs' => 'min="0" max="100" step="0.1"' ],
            [ 'key' => 'ltms_premium_commission_rate',  'label' => __( 'Comisión Vendedor Premium (%)', 'ltms' ), 'type' => 'number', 'default' => '8', 'attrs' => 'min="0" max="100" step="0.1"' ],
            [ 'key' => 'ltms_volume_tiers_enabled',     'label' => __( 'Tiers de Volumen', 'ltms' ),            'type' => 'checkbox', 'default' => 'no' ],
        ],
        'payments' => [
            [ 'key' => 'ltms_openpay_enabled',      'label' => __( 'Openpay Activo', 'ltms' ),           'type' => 'checkbox' ],
            [ 'key' => 'ltms_openpay_merchant_id',  'label' => __( 'Openpay Merchant ID', 'ltms' ),      'type' => 'text' ],
            [ 'key' => 'ltms_openpay_public_key',   'label' => __( 'Openpay Public Key', 'ltms' ),       'type' => 'text' ],
            [ 'key' => 'ltms_openpay_private_key',  'label' => __( 'Openpay Private Key (cifrado)', 'ltms' ), 'type' => 'password', 'desc' => __( 'Se guarda cifrado con AES-256', 'ltms' ) ],
            [ 'key' => 'ltms_pse_enabled',          'label' => __( 'PSE (Solo Colombia)', 'ltms' ),      'type' => 'checkbox', 'default' => 'yes' ],
            [ 'key' => 'ltms_addi_enabled',         'label' => __( 'Addi BNPL Activo', 'ltms' ),         'type' => 'checkbox' ],
        ],
        'siigo' => [
            [ 'key' => 'ltms_siigo_enabled',    'label' => __( 'Siigo Activo', 'ltms' ),             'type' => 'checkbox' ],
            [ 'key' => 'ltms_siigo_username',   'label' => __( 'Siigo Usuario', 'ltms' ),            'type' => 'text' ],
            [ 'key' => 'ltms_siigo_password',   'label' => __( 'Siigo Contraseña (cifrado)', 'ltms' ), 'type' => 'password', 'desc' => __( 'Se guarda cifrado', 'ltms' ) ],
            [ 'key' => 'ltms_siigo_partner_id', 'label' => __( 'Partner Token', 'ltms' ),            'type' => 'text' ],
        ],
        'kyc' => [
            [ 'key' => 'ltms_kyc_required_for_payout', 'label' => __( 'KYC Requerido para Retiros', 'ltms' ), 'type' => 'checkbox', 'default' => 'yes' ],
            [ 'key' => 'ltms_kyc_auto_approve',        'label' => __( 'Aprobación Automática', 'ltms' ),      'type' => 'checkbox', 'default' => 'no' ],
            [ 'key' => 'ltms_min_payout_amount',       'label' => __( 'Monto Mínimo Retiro', 'ltms' ),        'type' => 'number', 'default' => '50000' ],
        ],
        'mlm' => [
            [ 'key' => 'ltms_mlm_enabled',     'label' => __( 'Red de Referidos Activa', 'ltms' ),  'type' => 'checkbox' ],
            [ 'key' => 'ltms_tptc_enabled',    'label' => __( 'TPTC Sincronización', 'ltms' ),      'type' => 'checkbox' ],
            [ 'key' => 'ltms_tptc_api_key',    'label' => __( 'TPTC API Key (cifrado)', 'ltms' ),   'type' => 'password' ],
            [ 'key' => 'ltms_tptc_program_id', 'label' => __( 'TPTC Program ID', 'ltms' ),          'type' => 'text' ],
            [ 'key' => 'ltms_referral_rates',  'label' => __( 'Tasas por Nivel (JSON)', 'ltms' ),   'type' => 'textarea', 'default' => '[0.40, 0.20, 0.10]', 'desc' => __( 'Array JSON: [nivel1, nivel2, nivel3]', 'ltms' ) ],
        ],
        'security' => [
            [ 'key' => 'ltms_waf_enabled',         'label' => __( 'WAF Activo', 'ltms' ),             'type' => 'checkbox', 'default' => 'yes' ],
            [ 'key' => 'ltms_waf_block_threshold',  'label' => __( 'Umbral de Bloqueo WAF', 'ltms' ), 'type' => 'number', 'default' => '10' ],
            [ 'key' => 'ltms_rate_limit_enabled',   'label' => __( 'Rate Limiting API', 'ltms' ),     'type' => 'checkbox', 'default' => 'yes' ],
        ],
        'emails' => [
            [ 'key' => 'ltms_email_from_name',    'label' => __( 'Nombre Remitente', 'ltms' ),   'type' => 'text', 'default' => get_bloginfo( 'name' ) ],
            [ 'key' => 'ltms_email_from_address', 'label' => __( 'Email Remitente', 'ltms' ),    'type' => 'email', 'default' => get_option( 'admin_email' ) ],
            [ 'key' => 'ltms_email_header_color', 'label' => __( 'Color Header Email', 'ltms' ), 'type' => 'text', 'default' => '#1a5276' ],
        ],
        // v1.6.0 — Enterprise Module Settings
        'backblaze' => [
            [ 'key' => 'ltms_backblaze_endpoint',       'label' => __( 'Endpoint S3 (URL base)', 'ltms' ),         'type' => 'text',     'default' => 'https://s3.us-west-004.backblazeb2.com', 'desc' => __( 'Ej: https://s3.us-west-004.backblazeb2.com', 'ltms' ) ],
            [ 'key' => 'ltms_backblaze_key_id',         'label' => __( 'Key ID', 'ltms' ),                         'type' => 'text' ],
            [ 'key' => 'ltms_backblaze_app_key',        'label' => __( 'Application Key 🔐', 'ltms' ),             'type' => 'password', 'desc' => __( 'Se guarda cifrado con AES-256. Dejar vacío para no cambiar.', 'ltms' ) ],
            [ 'key' => 'ltms_backblaze_default_bucket', 'label' => __( 'Bucket Público (defecto)', 'ltms' ),       'type' => 'text' ],
            [ 'key' => 'ltms_backblaze_private_bucket', 'label' => __( 'Bucket Privado (KYC/Facturas)', 'ltms' ),  'type' => 'text' ],
        ],
        'uber_direct' => [
            [ 'key' => 'ltms_uber_direct_client_id',      'label' => __( 'Uber Direct Client ID', 'ltms' ),           'type' => 'text' ],
            [ 'key' => 'ltms_uber_direct_client_secret',  'label' => __( 'Client Secret 🔐', 'ltms' ),                'type' => 'password', 'desc' => __( 'Se guarda cifrado con AES-256.', 'ltms' ) ],
            [ 'key' => 'ltms_uber_direct_customer_id',    'label' => __( 'Customer ID', 'ltms' ),                     'type' => 'text' ],
            [ 'key' => 'ltms_uber_direct_webhook_secret', 'label' => __( 'Webhook Secret (HMAC)', 'ltms' ),           'type' => 'text',     'desc' => __( 'Para validar firmas de webhooks Uber.', 'ltms' ) ],
        ],
        'heka' => [
            [ 'key' => 'ltms_heka_api_key',    'label' => __( 'Heka API Key 🔐', 'ltms' ),   'type' => 'password', 'desc' => __( 'Se guarda cifrado con AES-256.', 'ltms' ) ],
            [ 'key' => 'ltms_heka_account_id', 'label' => __( 'Heka Account ID', 'ltms' ),   'type' => 'text' ],
        ],
        'xcover' => [
            [ 'key' => 'ltms_xcover_api_key',               'label' => __( 'XCover API Key 🔐', 'ltms' ),               'type' => 'password', 'desc' => __( 'Se guarda cifrado con AES-256.', 'ltms' ) ],
            [ 'key' => 'ltms_xcover_partner_code',          'label' => __( 'XCover Partner Code', 'ltms' ),             'type' => 'text' ],
            [ 'key' => 'ltms_xcover_parcel_protection',     'label' => __( 'Protección del Paquete', 'ltms' ),          'type' => 'checkbox', 'default' => 'no',  'desc' => __( 'Muestra opción de seguro de paquete en checkout.', 'ltms' ) ],
            [ 'key' => 'ltms_xcover_purchase_protection',   'label' => __( 'Protección de Compra', 'ltms' ),            'type' => 'checkbox', 'default' => 'no',  'desc' => __( 'Muestra opción de protección de compra en checkout.', 'ltms' ) ],
        ],
    ];

    $fields = $fields_map[ $tab ] ?? [];
    if ( empty( $fields ) ) {
        echo '<div class="ltms-form-section"><p>' . esc_html__( 'No hay configuraciones para esta sección.', 'ltms' ) . '</p></div>';
        return;
    }

    global $tabs;
    echo '<div class="ltms-form-section">';
    echo '<h2>' . esc_html( $tabs[ $tab ] ?? $tab ) . '</h2>';

    foreach ( $fields as $field ) {
        $value = LTMS_Core_Config::get( $field['key'], $field['default'] ?? '' );

        // No mostrar contraseñas cifradas en texto plano
        if ( ( $field['type'] ?? '' ) === 'password' && strpos( $value, 'v1:' ) === 0 ) {
            $value = '';
            $field['placeholder'] = __( '(guardado — dejar vacío para mantener)', 'ltms' );
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