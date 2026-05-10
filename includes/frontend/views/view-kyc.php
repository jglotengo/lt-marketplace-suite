<?php
/**
 * Vista: Verificación de Identidad (KYC) del Vendedor
 *
 * Permite al vendedor subir su documento de identidad y enviar su
 * solicitud de verificación para revisión por el administrador.
 *
 * @package LTMS
 * @version 2.0.2
 */

if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;
$user_id   = get_current_user_id();
$kyc_table = $wpdb->prefix . 'lt_vendor_kyc';

// Buscar solicitud KYC más reciente del vendedor
$kyc = $wpdb->get_row( $wpdb->prepare(
    "SELECT * FROM `{$kyc_table}` WHERE vendor_id = %d ORDER BY id DESC LIMIT 1",
    $user_id
) );

$status        = $kyc ? $kyc->status : 'none';
$status_labels = [
    'none'     => '—',
    'pending'  => 'En revisión',
    'approved' => 'Aprobado ✓',
    'rejected' => 'Rechazado',
    'expired'  => 'Expirado',
];
$status_colors = [
    'none'     => '#9ca3af',
    'pending'  => '#f59e0b',
    'approved' => '#10b981',
    'rejected' => '#ef4444',
    'expired'  => '#6b7280',
];
$label = $status_labels[ $status ] ?? $status;
$color = $status_colors[ $status ] ?? '#888';
$nonce = wp_create_nonce( 'ltms_dashboard_nonce' );
?>
<div class="ltms-view-pad">

    <div class="ltms-view-header">
        <h2><?php esc_html_e( 'Verificación de Identidad (KYC)', 'ltms' ); ?></h2>
        <span class="ltms-badge" style="background:<?php echo esc_attr( $color ); ?>22;color:<?php echo esc_attr( $color ); ?>;">
            <?php echo esc_html( $label ); ?>
        </span>
    </div>

    <?php if ( 'approved' === $status ) : ?>
        <!-- Identidad verificada -->
        <div class="ltms-card" style="padding:28px;text-align:center;">
            <div style="font-size:3rem;margin-bottom:12px;">✅</div>
            <h3 style="color:#10b981;margin-bottom:8px;"><?php esc_html_e( 'Identidad Verificada', 'ltms' ); ?></h3>
            <p style="color:#6b7280;">
                <?php esc_html_e( 'Tu identidad ha sido verificada. Ya puedes solicitar retiros sin restricciones.', 'ltms' ); ?>
            </p>
            <?php if ( $kyc && $kyc->expires_at ) : ?>
                <p style="color:#9ca3af;font-size:.85rem;">
                    <?php printf( esc_html__( 'Válido hasta: %s', 'ltms' ), esc_html( $kyc->expires_at ) ); ?>
                </p>
            <?php endif; ?>
        </div>

    <?php elseif ( 'pending' === $status ) : ?>
        <!-- Solicitud en revisión -->
        <div class="ltms-card" style="padding:28px;text-align:center;">
            <div style="font-size:3rem;margin-bottom:12px;">⏳</div>
            <h3 style="color:#f59e0b;margin-bottom:8px;"><?php esc_html_e( 'Solicitud en Revisión', 'ltms' ); ?></h3>
            <p style="color:#6b7280;">
                <?php esc_html_e( 'Tu solicitud de verificación está siendo revisada. Te notificaremos por correo cuando haya una respuesta (normalmente 1–2 días hábiles).', 'ltms' ); ?>
            </p>
            <p style="color:#9ca3af;font-size:.85rem;">
                <?php printf( esc_html__( 'Enviada el: %s', 'ltms' ), esc_html( $kyc->submitted_at ) ); ?>
            </p>
        </div>

    <?php else : ?>
        <!-- Formulario de envío KYC -->
        <?php if ( 'rejected' === $status && $kyc && $kyc->notes ) : ?>
            <div class="ltms-card ltms-modal-error" style="padding:16px;margin-bottom:16px;border-radius:8px;">
                <strong><?php esc_html_e( 'Solicitud rechazada:', 'ltms' ); ?></strong>
                <?php echo esc_html( $kyc->notes ); ?>
            </div>
        <?php endif; ?>

        <div class="ltms-card" style="padding:24px;">
            <p style="color:#6b7280;margin-bottom:20px;">
                <?php esc_html_e( 'Para cumplir con los requisitos legales (SAGRILAFT/SIPLAFT) y poder retirar tus ganancias, necesitamos verificar tu identidad. El proceso toma 1–2 días hábiles.', 'ltms' ); ?>
            </p>

            <div id="ltms-kyc-notice" style="display:none;padding:10px 14px;border-radius:6px;margin-bottom:16px;font-size:.875rem;"></div>

            <div class="ltms-form-group">
                <label><?php esc_html_e( 'Nombre completo (como aparece en el documento)', 'ltms' ); ?></label>
                <input type="text" id="ltms-kyc-full-name" class="ltms-form-control"
                       value="<?php echo esc_attr( get_user_meta( $user_id, 'ltms_full_name', true ) ); ?>"
                       placeholder="<?php esc_attr_e( 'Ej: Juan Pérez García', 'ltms' ); ?>">
            </div>

            <div class="ltms-form-group">
                <label><?php esc_html_e( 'Tipo de documento', 'ltms' ); ?></label>
                <select id="ltms-kyc-doc-type" class="ltms-form-control">
                    <option value="cc"><?php esc_html_e( 'Cédula de Ciudadanía (CC)', 'ltms' ); ?></option>
                    <option value="ce"><?php esc_html_e( 'Cédula de Extranjería (CE)', 'ltms' ); ?></option>
                    <option value="nit"><?php esc_html_e( 'NIT (Empresa)', 'ltms' ); ?></option>
                    <option value="passport"><?php esc_html_e( 'Pasaporte', 'ltms' ); ?></option>
                </select>
            </div>

            <div class="ltms-form-group">
                <label><?php esc_html_e( 'Número de documento', 'ltms' ); ?></label>
                <input type="text" id="ltms-kyc-doc-number" class="ltms-form-control"
                       placeholder="<?php esc_attr_e( 'Ej: 12345678', 'ltms' ); ?>">
            </div>

            <div class="ltms-form-group">
                <label><?php esc_html_e( 'Foto del documento (frente)', 'ltms' ); ?></label>
                <input type="file" id="ltms-kyc-file" accept="image/*,application/pdf" class="ltms-form-control"
                       style="padding:8px;">
                <span class="ltms-field-hint">
                    <?php esc_html_e( 'Formatos aceptados: JPG, PNG, PDF. Tamaño máximo: 5 MB.', 'ltms' ); ?>
                </span>
            </div>

            <div id="ltms-kyc-upload-status" style="display:none;font-size:.85rem;color:#6b7280;margin-bottom:12px;"></div>
            <input type="hidden" id="ltms-kyc-file-path" value="">

            <button type="button" id="ltms-kyc-submit-btn"
                    class="ltms-btn ltms-btn-primary" style="width:100%;justify-content:center;padding:12px;">
                <?php esc_html_e( 'Enviar para Verificación', 'ltms' ); ?>
            </button>
        </div>

        <script>
        (function($){
            'use strict';
            var nonce   = '<?php echo esc_js( $nonce ); ?>';
            var ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
            var $notice = $('#ltms-kyc-notice');

            function showNotice(msg, type) {
                var bg    = type === 'success' ? '#f0fdf4' : '#fef2f2';
                var color = type === 'success' ? '#16a34a' : '#dc2626';
                $notice.css({ background: bg, color: color, border: '1px solid ' + color + '44' })
                       .text(msg).show();
                if (type === 'success') setTimeout(function(){ $notice.hide(); }, 4000);
            }

            // Paso 1: subir documento (usa ltms_upload_kyc_document)
            function uploadDocument(callback) {
                var file = $('#ltms-kyc-file')[0].files[0];
                if (!file) { callback(null); return; }

                var $status = $('#ltms-kyc-upload-status');
                $status.text('Subiendo documento...').show();

                var fd = new FormData();
                fd.append('action', 'ltms_upload_kyc_document');
                fd.append('nonce', nonce);
                fd.append('kyc_doc', file);

                $.ajax({ url: ajaxUrl, method: 'POST', data: fd,
                    processData: false, contentType: false,
                    success: function(r) {
                        if (r.success) {
                            $status.text('Documento subido ✓');
                            $('#ltms-kyc-file-path').val(r.data.file_path || '');
                            callback(r.data.file_path || '');
                        } else {
                            $status.text('Error al subir: ' + (r.data || 'intente de nuevo'));
                            callback(null);
                        }
                    },
                    error: function() {
                        $status.text('Error de conexión al subir documento.');
                        callback(null);
                    }
                });
            }

            // Paso 2: submit KYC (usa ltms_submit_kyc)
            $('#ltms-kyc-submit-btn').on('click', function() {
                var fullName  = $.trim($('#ltms-kyc-full-name').val());
                var docType   = $('#ltms-kyc-doc-type').val();
                var docNumber = $.trim($('#ltms-kyc-doc-number').val());

                if (!fullName || !docNumber) {
                    showNotice('Por favor completa todos los campos obligatorios.', 'error');
                    return;
                }

                var $btn = $(this).prop('disabled', true).text('Procesando...');

                uploadDocument(function(filePath) {
                    $.ajax({ url: ajaxUrl, method: 'POST',
                        data: {
                            action:          'ltms_submit_kyc',
                            nonce:           nonce,
                            full_name:       fullName,
                            document_type:   docType,
                            document_number: docNumber,
                            file_path:       filePath || '',
                        },
                        success: function(r) {
                            $btn.prop('disabled', false).text('Enviar para Verificación');
                            if (r.success) {
                                showNotice('✓ Solicitud enviada. Te notificaremos por correo cuando sea revisada.', 'success');
                                setTimeout(function(){ location.reload(); }, 2500);
                            } else {
                                showNotice('Error: ' + (r.data || 'intente de nuevo'), 'error');
                            }
                        },
                        error: function() {
                            $btn.prop('disabled', false).text('Enviar para Verificación');
                            showNotice('Error de conexión. Intenta de nuevo.', 'error');
                        }
                    });
                });
            });
        })(jQuery);
        </script>
    <?php endif; ?>
</div>
