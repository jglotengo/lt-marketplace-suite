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
$user_id      = get_current_user_id();
$vendor_country = strtoupper( (string) get_user_meta( $user_id, 'ltms_country', true ) );
if ( ! in_array( $vendor_country, [ 'CO', 'MX' ], true ) ) {
    $vendor_country = LTMS_Core_Config::get_country();
}
$is_mx = ( 'MX' === $vendor_country );
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
                       value="<?php echo esc_attr( $kyc && $kyc->full_name ? $kyc->full_name : get_user_meta( $user_id, 'ltms_full_name', true ) ); ?>"
                       placeholder="<?php esc_attr_e( 'Ej: Juan Pérez García', 'ltms' ); ?>">
            </div>

            <div class="ltms-form-group">
                <label><?php esc_html_e( 'Tipo de documento', 'ltms' ); ?></label>
                <select id="ltms-kyc-doc-type" class="ltms-form-control">
                    <?php if ( $is_mx ) : ?>
                        <option value="rfc" <?php selected( $kyc->document_type ?? '', 'rfc' ); ?>><?php esc_html_e( 'RFC', 'ltms' ); ?></option>
                        <option value="curp" <?php selected( $kyc->document_type ?? '', 'curp' ); ?>><?php esc_html_e( 'CURP', 'ltms' ); ?></option>
                        <option value="passport" <?php selected( $kyc->document_type ?? '', 'passport' ); ?>><?php esc_html_e( 'Pasaporte', 'ltms' ); ?></option>
                    <?php else : ?>
                        <option value="cc" <?php selected( $kyc->document_type ?? '', 'cc' ); ?>><?php esc_html_e( 'Cédula de Ciudadanía (CC)', 'ltms' ); ?></option>
                        <option value="ce" <?php selected( $kyc->document_type ?? '', 'ce' ); ?>><?php esc_html_e( 'Cédula de Extranjería (CE)', 'ltms' ); ?></option>
                        <option value="nit" <?php selected( $kyc->document_type ?? '', 'nit' ); ?>><?php esc_html_e( 'NIT (Empresa)', 'ltms' ); ?></option>
                        <option value="passport" <?php selected( $kyc->document_type ?? '', 'passport' ); ?>><?php esc_html_e( 'Pasaporte', 'ltms' ); ?></option>
                    <?php endif; ?>
                </select>
            </div>

            <div class="ltms-form-group">
                <label><?php esc_html_e( 'Número de documento', 'ltms' ); ?></label>
                <input type="text" id="ltms-kyc-doc-number" class="ltms-form-control"
                       value="<?php echo esc_attr( $kyc->document_number ?? '' ); ?>"
                       placeholder="<?php esc_attr_e( 'Ej: 12345678', 'ltms' ); ?>">
            </div>

            <!-- Documentos requeridos -->
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:14px;margin-bottom:20px;">
                <p style="font-size:.85rem;color:#166534;margin:0;">
                    <?php if ( $is_mx ) : ?>
                        <strong>📋 Documentos requeridos (SAT / Art. 113-A LISR)</strong><br>
                        La identificación oficial (INE/Pasaporte) es obligatoria. La Constancia de Situación Fiscal es requerida para personas físicas con actividad empresarial y personas morales. El Acta Constitutiva solo si eres persona moral.
                    <?php else : ?>
                        <strong>📋 Documentos requeridos (SAGRILAFT / Ley 1480)</strong><br>
                        La cédula o NIT son obligatorios para todos. El RUT aplica a régimen común y empresas. La Cámara de Comercio solo si eres persona jurídica.
                    <?php endif; ?>
                </p>
            </div>

            <!-- Cédula / NIT (obligatorio) -->
            <div class="ltms-form-group">
                <label><?php echo $is_mx
    ? esc_html__( '📄 Identificación Oficial — INE/IFE o Pasaporte, frente y reverso (obligatorio)', 'ltms' )
    : esc_html__( '📄 Cédula de Ciudadanía o NIT — frente y reverso (obligatorio)', 'ltms' ); ?></label>
                <input type="file" id="ltms-kyc-file" name="kyc_doc" accept="image/*,application/pdf" class="ltms-form-control" style="padding:8px;">
                <span class="ltms-field-hint"><?php esc_html_e( 'Foto o escáner claro. JPG, PNG o PDF. Máx 10 MB.', 'ltms' ); ?></span>
                <div id="ltms-kyc-upload-status" style="display:none;font-size:.85rem;color:#6b7280;margin-top:4px;"></div>
                <input type="hidden" id="ltms-kyc-file-path" value="">
            </div>

            <!-- RUT -->
            <div class="ltms-form-group">
                <label><?php echo $is_mx
    ? esc_html__( '📄 Constancia de Situación Fiscal (obligatoria para actividad empresarial y personas morales)', 'ltms' )
    : esc_html__( '📄 RUT — Registro Único Tributario (obligatorio para régimen común y empresas)', 'ltms' ); ?></label>
                <input type="file" id="ltms-kyc-file-rut" accept="image/*,application/pdf" class="ltms-form-control" style="padding:8px;">
                <span class="ltms-field-hint"><?php echo $is_mx
    ? esc_html__( 'Descárgala del portal del SAT en sat.gob.mx. JPG, PNG o PDF. Máx 10 MB.', 'ltms' )
    : esc_html__( 'Descárgalo actualizado en dian.gov.co. JPG, PNG o PDF. Máx 10 MB.', 'ltms' ); ?></span>
                <div id="ltms-kyc-status-rut" style="display:none;font-size:.85rem;color:#6b7280;margin-top:4px;"></div>
                <input type="hidden" id="ltms-kyc-path-rut" value="">
            </div>

            <!-- Cámara de Comercio -->
            <div class="ltms-form-group">
                <label><?php echo $is_mx
    ? esc_html__( '📄 Acta Constitutiva — solo personas morales', 'ltms' )
    : esc_html__( '📄 Certificado de Existencia — Cámara de Comercio (solo personas jurídicas)', 'ltms' ); ?></label>
                <input type="file" id="ltms-kyc-file-camara" accept="image/*,application/pdf" class="ltms-form-control" style="padding:8px;">
                <span class="ltms-field-hint"><?php echo $is_mx
    ? esc_html__( 'Notariada y apostillada si aplica. JPG, PNG o PDF. Máx 10 MB.', 'ltms' )
    : esc_html__( 'Vigencia no mayor a 90 días. Descárgalo en ccb.org.co. JPG, PNG o PDF. Máx 10 MB.', 'ltms' ); ?></span>
                <div id="ltms-kyc-status-camara" style="display:none;font-size:.85rem;color:#6b7280;margin-top:4px;"></div>
                <input type="hidden" id="ltms-kyc-path-camara" value="">
            </div>

            <!-- ============================================================
                 CERTIFICACIÓN BANCARIA
                 CO: Circular SFC 029/2014 — cuenta a nombre del rep. legal
                 MX: SAT — CLABE 18 dígitos, titular = RFC del vendedor
                 ============================================================ -->
            <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:18px;margin-bottom:20px;">
                <p style="font-size:.85rem;color:#1e40af;margin:0 0 14px;">
                    <?php if ( $is_mx ) : ?>
                        <strong>🏦 Certificación Bancaria (obligatoria)</strong><br>
                        La CLABE registrada debe pertenecer al titular del RFC del vendedor. Emitida por institución financiera mexicana. Vigencia máxima 90 días (CNBV).
                    <?php else : ?>
                        <strong>🏦 Certificación Bancaria (obligatoria)</strong><br>
                        La cuenta debe estar a nombre del representante legal registrado ante la DIAN. Emitida por entidad vigilada SFC. Vigencia máxima 30 días.
                    <?php endif; ?>
                </p>

                <div class="ltms-form-group" style="margin-bottom:12px;">
                    <label style="font-size:.875rem;"><?php echo $is_mx
                        ? esc_html__( 'Nombre del titular (debe coincidir con el RFC)', 'ltms' )
                        : esc_html__( 'Nombre del Representante Legal (titular de la cuenta)', 'ltms' ); ?></label>
                    <input type="text" id="ltms-kyc-rep-legal-name" class="ltms-form-control"
                           value="<?php echo esc_attr( $kyc->bank_rep_legal_name ?? '' ); ?>"
                           placeholder="<?php echo $is_mx
                               ? esc_attr__( 'Ej: María López Torres', 'ltms' )
                               : esc_attr__( 'Ej: Carlos Ramírez Gómez', 'ltms' ); ?>">
                </div>

                <div class="ltms-form-group" style="margin-bottom:12px;">
                    <label style="font-size:.875rem;"><?php esc_html_e( 'Entidad bancaria', 'ltms' ); ?></label>
                    <input type="text" id="ltms-kyc-bank-name" class="ltms-form-control"
                           value="<?php echo esc_attr( $kyc->bank_name ?? '' ); ?>"
                           placeholder="<?php echo $is_mx
                               ? esc_attr__( 'Ej: BBVA, Santander, Banorte…', 'ltms' )
                               : esc_attr__( 'Ej: Bancolombia, Davivienda, Nequi…', 'ltms' ); ?>">
                </div>

                <div class="ltms-form-group" style="margin-bottom:12px;">
                    <label style="font-size:.875rem;"><?php echo $is_mx
                        ? esc_html__( 'CLABE interbancaria (18 dígitos)', 'ltms' )
                        : esc_html__( 'Número de cuenta bancaria', 'ltms' ); ?></label>
                    <input type="text" id="ltms-kyc-account-number" class="ltms-form-control"
                           value="<?php echo esc_attr( $kyc->bank_account_number ?? '' ); ?>"
                           <?php if ( $is_mx ) : ?>maxlength="18" pattern="\d{18}" inputmode="numeric"
                           placeholder="<?php esc_attr_e( '18 dígitos, ej: 012345678901234567', 'ltms' ); ?>"
                           <?php else : ?>inputmode="numeric"
                           placeholder="<?php esc_attr_e( 'Ej: 2050123456789', 'ltms' ); ?>"
                           <?php endif; ?>>
                    <span class="ltms-field-hint"><?php echo $is_mx
                        ? esc_html__( 'CLABE de 18 dígitos. La encuentras en tu app bancaria o estado de cuenta.', 'ltms' )
                        : esc_html__( 'Número de cuenta de ahorros o corriente en banco colombiano vigilado por la SFC.', 'ltms' ); ?></span>
                </div>

                <div class="ltms-form-group" style="margin-bottom:4px;">
                    <label style="font-size:.875rem;"><?php echo $is_mx
                        ? esc_html__( '📎 Carta bancaria o estado de cuenta (vigencia máx. 90 días)', 'ltms' )
                        : esc_html__( '📎 Certificado bancario (vigencia máx. 30 días, expedido por la entidad)', 'ltms' ); ?></label>
                    <input type="file" id="ltms-kyc-file-banco" accept="image/*,application/pdf" class="ltms-form-control" style="padding:8px;">
                    <span class="ltms-field-hint"><?php echo $is_mx
                        ? esc_html__( 'Estado de cuenta o carta emitida por el banco. JPG, PNG o PDF. Máx 10 MB.', 'ltms' )
                        : esc_html__( 'Documento oficial de la entidad bancaria. JPG, PNG o PDF. Máx 10 MB.', 'ltms' ); ?></span>
                    <div id="ltms-kyc-status-banco" style="display:none;font-size:.85rem;color:#1d4ed8;margin-top:4px;"></div>
                    <input type="hidden" id="ltms-kyc-path-banco" value="">
                </div>
            </div>

            <!-- L-6: Consentimiento Habeas Data / Ley 1581 antes del envío -->
            <div class="ltms-form-group" style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:14px;margin-bottom:16px;">
                <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;font-size:.875rem;color:#78350f;">
                    <input type="checkbox" id="ltms-kyc-consent" value="1" required style="margin-top:3px;flex-shrink:0;">
                    <span>
                        <?php
                        $privacy_url = get_privacy_policy_url() ?: get_permalink( get_option( 'ltms_privacy_page_id' ) ) ?: '#';
                        echo wp_kses_post( sprintf(
                            __( '<strong>Autorización de Tratamiento de Datos Personales (Ley 1581/2012):</strong> Autorizo a <strong>%s</strong> para recopilar, almacenar, usar y tratar mis datos personales e información de identidad con fines de verificación (KYC), cumplimiento de SAGRILAFT, prevención de fraude y gestión de la plataforma. Los documentos se almacenan cifrados en servidores seguros. Conozco mis derechos de acceso, rectificación, cancelación y oposición según la <a href="%s" target="_blank">Política de Privacidad</a>. *', 'ltms' ),
                            get_bloginfo( 'name' ),
                            esc_url( $privacy_url )
                        ) );
                        ?>
                    </span>
                </label>
            </div>

            <?php
            // RB-9 FIX (v2.9.19): Disparar action ltms_kyc_fields_extra para que
            // los listeners (RT-2 render_sanitary_registration_fields) puedan
            // añadir campos adicionales al formulario KYC del vendor (registro
            // INVIMA/COFEPRIS para restaurantes, etc.). Antes de este fix, RT-2
            // era silent dead code desde v2.9.14. Recibe 2 args: ($vendor_id, $country).
            do_action( 'ltms_kyc_fields_extra', (int) get_current_user_id(), $vendor_country ?? 'CO' );
            ?>

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

            // Subir un documento individual a Backblaze B2 via vault
            function uploadSingleDoc(inputId, statusId, pathId, label, callback) {
                var file = $('#' + inputId)[0] && $('#' + inputId)[0].files[0];
                if (!file) { callback(null); return; }

                var $status = $('#' + statusId);
                $status.text('Subiendo ' + label + '...').show();

                var fd = new FormData();
                fd.append('action', 'ltms_upload_kyc_document');
                fd.append('nonce', nonce);
                fd.append('kyc_doc', file);

                $.ajax({ url: ajaxUrl, method: 'POST', data: fd,
                    processData: false, contentType: false,
                    success: function(r) {
                        if (r.success) {
                            $status.text(label + ' subido ✓');
                            $('#' + pathId).val(r.data.file_path || r.data.vault_url || '');
                            callback(r.data.file_path || r.data.vault_url || '');
                        } else {
                            $status.text('Error ' + label + ': ' + (r.data || 'intente de nuevo'));
                            callback(null);
                        }
                    },
                    error: function() {
                        $status.text('Error de conexión al subir ' + label + '.');
                        callback(null);
                    }
                });
            }

            // Subir todos los documentos en secuencia
            function uploadDocument(callback) {
                uploadSingleDoc('ltms-kyc-file', 'ltms-kyc-upload-status', 'ltms-kyc-file-path', 'Cédula/NIT', function(cedula) {
                    uploadSingleDoc('ltms-kyc-file-rut', 'ltms-kyc-status-rut', 'ltms-kyc-path-rut', 'RUT', function(rut) {
                        uploadSingleDoc('ltms-kyc-file-camara', 'ltms-kyc-status-camara', 'ltms-kyc-path-camara', 'Cámara de Comercio', function(camara) {
                            uploadSingleDoc('ltms-kyc-file-banco', 'ltms-kyc-status-banco', 'ltms-kyc-path-banco', 'Certificación Bancaria', function(banco) {
                                callback(cedula, rut, camara, banco);
                            });
                        });
                    });
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

                // Validar campos obligatorios de certificación bancaria
                var repLegalName  = $.trim($('#ltms-kyc-rep-legal-name').val());
                var bankName      = $.trim($('#ltms-kyc-bank-name').val());
                var accountNumber = $.trim($('#ltms-kyc-account-number').val());
                var bancoFile     = $('#ltms-kyc-file-banco')[0] && $('#ltms-kyc-file-banco')[0].files[0];

                if (!repLegalName || !bankName || !accountNumber || !bancoFile) {
                    showNotice('La certificación bancaria es obligatoria: nombre del representante legal, entidad, número de cuenta y archivo del certificado.', 'error');
                    return;
                }

                // L-6: validar consentimiento de datos personales
                if (!$('#ltms-kyc-consent').is(':checked')) {
                    showNotice('Debes aceptar la autorización de tratamiento de datos para continuar.', 'error');
                    return;
                }

                var $btn = $(this).prop('disabled', true).text('Procesando...');

                uploadDocument(function(cedulaPath, rutPath, camaraPath, bancoPath) {
                    if (!bancoPath) {
                        $btn.prop('disabled', false).text('Enviar para Verificación');
                        showNotice('Error al subir la certificación bancaria. Verifica el archivo e intenta de nuevo.', 'error');
                        return;
                    }
                    var filePath = cedulaPath || '';
                    $.ajax({ url: ajaxUrl, method: 'POST',
                        data: {
                            action:              'ltms_submit_kyc',
                            nonce:               nonce,
                            full_name:           fullName,
                            document_type:       docType,
                            document_number:     docNumber,
                            file_path:           filePath,
                            file_path_rut:       rutPath || '',
                            file_path_camara:    camaraPath || '',
                            file_path_banco:     bancoPath || '',
                            bank_rep_legal_name: repLegalName,
                            bank_name:           bankName,
                            bank_account_number: accountNumber,
                            privacy_consent:     '1',
                            consent_ts:          new Date().toISOString(),
                        },
                        success: function(r) {
                            $btn.prop('disabled', false).text('Enviar para Verificación');
                            if (r.success) {
                                showNotice('✓ Solicitud enviada. Te notificaremos por correo cuando sea revisada.', 'success');
                                setTimeout(function(){ LTMS.Dashboard.loadView('home', true); }, 2500);
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
