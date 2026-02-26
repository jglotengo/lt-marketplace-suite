<?php
/**
 * Vista SPA: Configuración del Vendedor
 *
 * @package LTMS
 * @version 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$vendor_id    = get_current_user_id();
$user         = get_userdata( $vendor_id );
$store_name   = get_user_meta( $vendor_id, 'ltms_store_name', true );
$store_desc   = get_user_meta( $vendor_id, 'ltms_store_description', true );
$phone        = get_user_meta( $vendor_id, 'ltms_phone', true );
$bank_name    = get_user_meta( $vendor_id, 'ltms_bank_name', true );
$kyc_status   = get_user_meta( $vendor_id, 'ltms_kyc_status', true ) ?: 'pending';
$referral_code = get_user_meta( $vendor_id, 'ltms_referral_code', true );

$kyc_badges = [
    'pending'  => [ 'class' => 'ltms-badge-warning',  'label' => __( 'Pendiente', 'ltms' ) ],
    'approved' => [ 'class' => 'ltms-badge-success',   'label' => __( 'Aprobado', 'ltms' ) ],
    'rejected' => [ 'class' => 'ltms-badge-danger',    'label' => __( 'Rechazado', 'ltms' ) ],
];

$kyc_badge = $kyc_badges[ $kyc_status ] ?? $kyc_badges['pending'];
?>
<div style="padding:24px;">

    <div class="ltms-view-header">
        <h2><?php esc_html_e( 'Configuración de Mi Cuenta', 'ltms' ); ?></h2>
    </div>

    <!-- KYC Status -->
    <div class="ltms-card" style="margin-bottom:20px;">
        <div class="ltms-card-header">
            <?php esc_html_e( 'Verificación de Identidad (KYC)', 'ltms' ); ?>
            <span class="ltms-badge <?php echo esc_attr( $kyc_badge['class'] ); ?>"><?php echo esc_html( $kyc_badge['label'] ); ?></span>
        </div>
        <div class="ltms-card-body">
            <?php if ( $kyc_status === 'approved' ) : ?>
            <p style="color:#166534;margin:0;">✓ <?php esc_html_e( 'Tu identidad ha sido verificada exitosamente.', 'ltms' ); ?></p>
            <?php elseif ( $kyc_status === 'pending' ) : ?>
            <p style="margin:0 0 12px;"><?php esc_html_e( 'Para solicitar retiros, debes completar la verificación de identidad.', 'ltms' ); ?></p>
            <button type="button" class="ltms-btn ltms-btn-primary ltms-btn-sm" data-ltms-modal-open="ltms-modal-kyc">
                <?php esc_html_e( 'Completar KYC', 'ltms' ); ?>
            </button>
            <?php else : ?>
            <p style="color:#991b1b;margin:0;"><?php esc_html_e( 'Tu verificación fue rechazada. Contacta soporte.', 'ltms' ); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Código de referido -->
    <?php if ( $referral_code ) : ?>
    <div class="ltms-card" style="margin-bottom:20px;">
        <div class="ltms-card-header"><?php esc_html_e( 'Mi Código de Referido', 'ltms' ); ?></div>
        <div class="ltms-card-body">
            <div style="display:flex;align-items:center;gap:12px;">
                <code style="font-size:1.2rem;font-weight:700;background:#f4f7f9;padding:8px 16px;border-radius:6px;letter-spacing:2px;">
                    <?php echo esc_html( $referral_code ); ?>
                </code>
                <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm"
                        onclick="navigator.clipboard.writeText('<?php echo esc_js( $referral_code ); ?>').then(()=>{this.textContent='✓ Copiado!'})">
                    📋 <?php esc_html_e( 'Copiar', 'ltms' ); ?>
                </button>
            </div>
            <p style="margin:8px 0 0;font-size:0.8rem;color:#6b7280;">
                <?php esc_html_e( 'Comparte este código para ganar comisiones cuando nuevos vendedores se registren.', 'ltms' ); ?>
            </p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Formulario de configuración -->
    <div class="ltms-card">
        <div class="ltms-card-header"><?php esc_html_e( 'Datos de la Tienda', 'ltms' ); ?></div>
        <div class="ltms-card-body">
            <div id="ltms-settings-notice" style="display:none;margin-bottom:16px;"></div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
                <div>
                    <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Nombre de la Tienda', 'ltms' ); ?></label>
                    <input type="text" name="ltms_store_name" id="ltms-setting-store-name"
                           value="<?php echo esc_attr( $store_name ); ?>"
                           style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;">
                </div>
                <div>
                    <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Teléfono de Contacto', 'ltms' ); ?></label>
                    <input type="tel" name="ltms_store_phone" id="ltms-setting-phone"
                           value="<?php echo esc_attr( $phone ); ?>"
                           style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;">
                </div>
            </div>
            <div style="margin-bottom:16px;">
                <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Descripción de la Tienda', 'ltms' ); ?></label>
                <textarea name="ltms_store_description" rows="3"
                          style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;resize:vertical;"><?php echo esc_textarea( $store_desc ); ?></textarea>
            </div>
            <div style="margin-bottom:20px;">
                <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Banco para Retiros', 'ltms' ); ?></label>
                <input type="text" name="ltms_bank_name" id="ltms-setting-bank"
                       value="<?php echo esc_attr( $bank_name ); ?>"
                       style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;">
            </div>

            <button type="button" class="ltms-btn ltms-btn-primary" id="ltms-save-settings-btn">
                💾 <?php esc_html_e( 'Guardar Cambios', 'ltms' ); ?>
            </button>
        </div>
    </div>

</div>

<script>
jQuery('#ltms-save-settings-btn').on('click', function() {
    var settings = {};
    jQuery('[name^="ltms_"]').each(function() {
        settings[jQuery(this).attr('name')] = jQuery(this).val();
    });

    jQuery.ajax({
        url: ltmsDashboard.ajax_url,
        method: 'POST',
        data: { action: 'ltms_save_vendor_settings', nonce: ltmsDashboard.nonce, settings: settings },
        success: function(response) {
            var $notice = jQuery('#ltms-settings-notice');
            if (response.success) {
                $notice.attr('class', 'ltms-form-notice ltms-notice-success').text(response.data.message).show();
            } else {
                $notice.attr('class', 'ltms-form-notice ltms-notice-error').text(response.data).show();
            }
            setTimeout(function() { $notice.fadeOut(); }, 4000);
        }
    });
});
</script>
