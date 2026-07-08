<?php
/**
 * Vista SPA: Seguridad 2FA — Activación TOTP para Vendedores
 *
 * Permite a los vendedores activar/desactivar autenticación de dos factores (TOTP)
 * usando apps como Google Authenticator, Authy, 1Password, etc.
 *
 * @package LTMS
 * @version 2.9.31
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$user_id = get_current_user_id();
$has_2fa = get_user_meta( $user_id, '_ltms_2fa_enabled', true ) === 'yes';
$enabled_at = get_user_meta( $user_id, '_ltms_2fa_enabled_at', true );
$backup_codes = get_user_meta( $user_id, '_ltms_2fa_backup_codes', true );
$backup_codes = is_array( $backup_codes ) ? $backup_codes : [];
?>
<div style="padding:24px;" id="ltms-security-view">

    <div class="ltms-view-header" style="margin-bottom:24px;">
        <h2 style="margin:0;">🔐 Seguridad de la Cuenta</h2>
        <p style="color:#6b7280;margin:8px 0 0;font-size:0.875rem;">
            <?php esc_html_e( 'Protege tu cuenta con autenticación de dos factores (2FA). Al activarlo, necesitarás un código de tu app autenticadora además de tu contraseña para iniciar sesión.', 'ltms' ); ?>
        </p>
    </div>

    <!-- Estado actual -->
    <div class="ltms-card" style="margin-bottom:20px;">
        <div class="ltms-card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <span><?php esc_html_e( 'Estado actual', 'ltms' ); ?></span>
            <?php if ( $has_2fa ) : ?>
                <span class="ltms-badge ltms-badge-success">✓ <?php esc_html_e( '2FA ACTIVO', 'ltms' ); ?></span>
            <?php else : ?>
                <span class="ltms-badge ltms-badge-pending">⚠ <?php esc_html_e( '2FA INACTIVO', 'ltms' ); ?></span>
            <?php endif; ?>
        </div>
        <div class="ltms-card-body">
            <?php if ( $has_2fa ) : ?>
                <p style="margin:0;color:#16a34a;">
                    <?php esc_html_e( 'Tu cuenta está protegida con autenticación de dos factores.', 'ltms' ); ?>
                </p>
                <?php if ( $enabled_at ) : ?>
                <p style="margin:8px 0 0;font-size:0.85rem;color:#6b7280;">
                    <?php echo esc_html( sprintf( __( 'Activado el: %s', 'ltms' ), date_i18n( 'd M Y H:i', strtotime( $enabled_at ) ) ) ); ?>
                </p>
                <?php endif; ?>

                <?php if ( ! empty( $backup_codes ) ) : ?>
                <div style="margin-top:20px;padding:16px;background:#f9fafb;border-radius:8px;">
                    <h4 style="margin:0 0 8px;font-size:0.9rem;">
                        🔑 <?php esc_html_e( 'Códigos de respaldo', 'ltms' ); ?>
                    </h4>
                    <p style="margin:0 0 12px;font-size:0.8rem;color:#6b7280;">
                        <?php esc_html_e( 'Guárdalos en un lugar seguro. Úsalos si pierdes acceso a tu app autenticadora. Cada código solo se puede usar una vez.', 'ltms' ); ?>
                    </p>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:6px;font-family:monospace;font-size:0.85rem;">
                        <?php foreach ( $backup_codes as $code ) : ?>
                            <code style="background:#fff;padding:4px 8px;border-radius:4px;border:1px solid #e5e7eb;text-align:center;">
                                <?php echo esc_html( $code ); ?>
                            </code>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div style="margin-top:20px;display:flex;gap:8px;flex-wrap:wrap;">
                    <button type="button" id="ltms-disable-2fa-btn" class="ltms-btn ltms-btn-danger ltms-btn-sm">
                        <?php esc_html_e( 'Desactivar 2FA', 'ltms' ); ?>
                    </button>
                </div>
            <?php else : ?>
                <p style="margin:0;color:#dc2626;">
                    <?php esc_html_e( 'Tu cuenta NO tiene 2FA activado. Te recomendamos activarlo para mayor seguridad.', 'ltms' ); ?>
                </p>
                <div style="margin-top:16px;">
                    <button type="button" id="ltms-setup-2fa-btn" class="ltms-btn ltms-btn-primary">
                        🔐 <?php esc_html_e( 'Activar 2FA', 'ltms' ); ?>
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal de configuración (oculto por defecto) -->
    <div id="ltms-2fa-setup-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;padding:20px;">
        <div style="background:#fff;border-radius:12px;max-width:480px;width:100%;max-height:90vh;overflow-y:auto;">
            <div style="padding:20px 24px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;">
                <h3 style="margin:0;">🔐 <?php esc_html_e( 'Configurar 2FA', 'ltms' ); ?></h3>
                <button type="button" id="ltms-2fa-close-modal" style="background:none;border:none;font-size:24px;cursor:pointer;color:#6b7280;">×</button>
            </div>
            <div style="padding:24px;">
                <!-- Paso 1: QR -->
                <div id="ltms-2fa-step-qr">
                    <p style="margin:0 0 16px;">
                        <?php esc_html_e( '1. Escanea este código QR con tu app autenticadora (Google Authenticator, Authy, 1Password, etc.):', 'ltms' ); ?>
                    </p>
                    <div id="ltms-2fa-qr-container" style="text-align:center;margin:16px 0;">
                        <div style="display:inline-block;padding:16px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;">
                            <img id="ltms-2fa-qr-img" src="" alt="QR Code" style="width:200px;height:200px;">
                        </div>
                    </div>
                    <p style="margin:0 0 8px;font-size:0.85rem;color:#6b7280;">
                        <?php esc_html_e( '¿No puedes escanear? Ingresa este código manualmente:', 'ltms' ); ?>
                    </p>
                    <code id="ltms-2fa-secret" style="display:block;padding:8px 12px;background:#f3f4f6;border-radius:4px;font-size:0.85rem;text-align:center;letter-spacing:1px;margin-bottom:16px;"></code>
                </div>

                <!-- Paso 2: Verificar -->
                <div style="margin-top:20px;">
                    <p style="margin:0 0 8px;">
                        <?php esc_html_e( '2. Ingresa el código de 6 dígitos de tu app:', 'ltms' ); ?>
                    </p>
                    <input type="text"
                           id="ltms-2fa-verify-code"
                           maxlength="6"
                           pattern="[0-9]{6}"
                           placeholder="000000"
                           style="width:100%;padding:12px;font-size:1.5rem;text-align:center;letter-spacing:8px;border:2px solid #e5e7eb;border-radius:8px;font-family:monospace;">
                </div>

                <div id="ltms-2fa-error" style="display:none;color:#dc2626;margin-top:12px;font-size:0.85rem;"></div>

                <div style="margin-top:20px;display:flex;gap:8px;">
                    <button type="button" id="ltms-2fa-confirm-btn" class="ltms-btn ltms-btn-primary" style="flex:1;">
                        ✓ <?php esc_html_e( 'Confirmar y activar', 'ltms' ); ?>
                    </button>
                    <button type="button" id="ltms-2fa-cancel-btn" class="ltms-btn ltms-btn-outline">
                        <?php esc_html_e( 'Cancelar', 'ltms' ); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de desactivación -->
    <div id="ltms-2fa-disable-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;padding:20px;">
        <div style="background:#fff;border-radius:12px;max-width:420px;width:100%;">
            <div style="padding:20px 24px;border-bottom:1px solid #e5e7eb;">
                <h3 style="margin:0;color:#dc2626;">⚠ <?php esc_html_e( 'Desactivar 2FA', 'ltms' ); ?></h3>
            </div>
            <div style="padding:24px;">
                <p style="margin:0 0 16px;">
                    <?php esc_html_e( 'Para desactivar 2FA, ingresa un código de tu app autenticadora o un código de respaldo:', 'ltms' ); ?>
                </p>
                <input type="text"
                       id="ltms-2fa-disable-code"
                       maxlength="6"
                       placeholder="000000"
                       style="width:100%;padding:12px;font-size:1.25rem;text-align:center;letter-spacing:4px;border:2px solid #e5e7eb;border-radius:8px;font-family:monospace;">
                <div id="ltms-2fa-disable-error" style="display:none;color:#dc2626;margin-top:12px;font-size:0.85rem;"></div>
                <div style="margin-top:20px;display:flex;gap:8px;">
                    <button type="button" id="ltms-2fa-disable-confirm-btn" class="ltms-btn ltms-btn-danger" style="flex:1;">
                        <?php esc_html_e( 'Desactivar', 'ltms' ); ?>
                    </button>
                    <button type="button" id="ltms-2fa-disable-cancel-btn" class="ltms-btn ltms-btn-outline">
                        <?php esc_html_e( 'Cancelar', 'ltms' ); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
(function($){
    'use strict';

    var nonce = (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.nonce) || '';
    var ajaxUrl = (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.ajax_url) || ajaxurl;

    function showError(sel, msg) {
        $(sel).text(msg).show();
    }

    // Abrir modal de setup
    $('#ltms-setup-2fa-btn').on('click', function(){
        var $btn = $(this);
        $btn.prop('disabled', true).text('Generando...');
        $.post(ajaxUrl, {
            action: 'ltms_setup_2fa',
            nonce: nonce
        }).done(function(resp){
            $btn.prop('disabled', false).html('🔐 Activar 2FA');
            if (resp.success) {
                $('#ltms-2fa-qr-img').attr('src', resp.data.qr_url);
                $('#ltms-2fa-secret').text(resp.data.secret);
                $('#ltms-2fa-error').hide();
                $('#ltms-2fa-verify-code').val('');
                $('#ltms-2fa-setup-modal').css('display', 'flex');
            } else {
                LTMS.UX.toastError('Error', resp.data.message || 'Error al generar código 2FA');
            }
        }).fail(function(){
            $btn.prop('disabled', false).html('🔐 Activar 2FA');
            LTMS.UX.toastError('Error', 'Error de red. Intenta de nuevo.');
        });
    });

    // Confirmar setup
    $('#ltms-2fa-confirm-btn').on('click', function(){
        var code = $('#ltms-2fa-verify-code').val().trim();
        if (!/^\d{6}$/.test(code)) {
            showError('#ltms-2fa-error', 'El código debe tener 6 dígitos');
            return;
        }
        var $btn = $(this);
        $btn.prop('disabled', true).text('Verificando...');
        $.post(ajaxUrl, {
            action: 'ltms_confirm_2fa',
            nonce: nonce,
            code: code
        }).done(function(resp){
            $btn.prop('disabled', false).html('✓ Confirmar y activar');
            if (resp.success) {
                $('#ltms-2fa-setup-modal').hide();
                LTMS.UX.toastSuccess('Éxito', '2FA activado correctamente. Guarda tus códigos de respaldo.');
                LTMS.Dashboard.loadView('security', true);
            } else {
                showError('#ltms-2fa-error', resp.data.message || 'Código incorrecto');
            }
        }).fail(function(){
            $btn.prop('disabled', false).html('✓ Confirmar y activar');
            showError('#ltms-2fa-error', 'Error de red. Intenta de nuevo.');
        });
    });

    // Cancelar setup
    $('#ltms-2fa-cancel-btn, #ltms-2fa-close-modal').on('click', function(){
        $('#ltms-2fa-setup-modal').hide();
    });

    // Abrir modal de desactivación
    $('#ltms-disable-2fa-btn').on('click', function(){
        $('#ltms-2fa-disable-error').hide();
        $('#ltms-2fa-disable-code').val('');
        $('#ltms-2fa-disable-modal').css('display', 'flex');
    });

    // Confirmar desactivación
    $('#ltms-2fa-disable-confirm-btn').on('click', function(){
        var code = $('#ltms-2fa-disable-code').val().trim();
        if (!code) {
            showError('#ltms-2fa-disable-error', 'Ingresa un código');
            return;
        }
        var $btn = $(this);
        $btn.prop('disabled', true).text('Desactivando...');
        $.post(ajaxUrl, {
            action: 'ltms_disable_2fa',
            nonce: nonce,
            code: code
        }).done(function(resp){
            $btn.prop('disabled', false).html('Desactivar');
            if (resp.success) {
                $('#ltms-2fa-disable-modal').hide();
                LTMS.UX.toastSuccess('Éxito', '2FA desactivado.');
                LTMS.Dashboard.loadView('security', true);
            } else {
                showError('#ltms-2fa-disable-error', resp.data.message || 'Código incorrecto');
            }
        }).fail(function(){
            $btn.prop('disabled', false).html('Desactivar');
            showError('#ltms-2fa-disable-error', 'Error de red. Intenta de nuevo.');
        });
    });

    // Cancelar desactivación
    $('#ltms-2fa-disable-cancel-btn').on('click', function(){
        $('#ltms-2fa-disable-modal').hide();
    });

})(jQuery);
</script>
