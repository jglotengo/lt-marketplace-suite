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
$phone        = get_user_meta( $vendor_id, 'ltms_store_phone', true ) ?: get_user_meta( $vendor_id, 'ltms_phone', true );
$bank_name        = get_user_meta( $vendor_id, 'ltms_bank_name',           true );
$bank_account_raw = get_user_meta( $vendor_id, 'ltms_bank_account_number',  true );
$bank_account_type= get_user_meta( $vendor_id, 'ltms_bank_account_type',    true ) ?: 'ahorros';
$bank_holder      = get_user_meta( $vendor_id, 'ltms_bank_account_holder',  true );

// v2.9.61 DEEP-AUDIT-002 P0-6 FIX: Desencriptar el número de cuenta y enmascararlo.
// Antes se mostraba el valor encriptado (v1:abc...) como garbage en el input.
// Si el valor ya está desencriptado (no empieza con v1:), mostrarlo tal cual.
$bank_account = '';
if ( ! empty( $bank_account_raw ) ) {
    if ( class_exists( 'LTMS_Core_Security' ) && method_exists( 'LTMS_Core_Security', 'decrypt' ) ) {
        $decrypted = LTMS_Core_Security::decrypt( $bank_account_raw );
        $bank_account = ( $decrypted !== false && $decrypted !== '' ) ? $decrypted : $bank_account_raw;
    } else {
        $bank_account = $bank_account_raw;
    }
    // Si después de desencriptar sigue teniendo formato de ciphertext, mostrar vacío.
    if ( preg_match( '/^v[0-9]+:/', $bank_account ) ) {
        $bank_account = '';
    }
}
$kyc_status   = get_user_meta( $vendor_id, 'ltms_kyc_status', true ) ?: 'pending';
$referral_code = get_user_meta( $vendor_id, 'ltms_referral_code', true );
// v2.3.0 — Analytics por vendedor
$vendor_ga4_id    = get_user_meta( $vendor_id, 'ltms_vendor_ga4_id',    true );
$vendor_pixel_id  = get_user_meta( $vendor_id, 'ltms_vendor_pixel_id',  true );
$platform_ga4_on  = get_option( 'ltms_vendor_ga4_enabled',   'yes' ) === 'yes';
$platform_pix_on  = get_option( 'ltms_vendor_pixel_enabled', 'yes' ) === 'yes';
// v2.9.81 P1: Vacation mode + store logo (Woodmart-inspired)
$vacation_mode   = get_user_meta( $vendor_id, 'ltms_vacation_mode', true ) === 'yes';
$vacation_msg    = get_user_meta( $vendor_id, 'ltms_vacation_message', true );
$store_logo_id   = (int) get_user_meta( $vendor_id, 'ltms_store_logo_id', true );
$store_logo_url  = $store_logo_id ? wp_get_attachment_image_url( $store_logo_id, 'thumbnail' ) : '';
// v2.9.83 P2: Store schedule + social links
$store_schedule  = get_user_meta( $vendor_id, 'ltms_store_schedule', true );
$store_instagram = get_user_meta( $vendor_id, 'ltms_store_instagram', true );
$store_facebook  = get_user_meta( $vendor_id, 'ltms_store_facebook', true );
$store_whatsapp  = get_user_meta( $vendor_id, 'ltms_store_whatsapp', true );

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

    <!-- Información Fiscal (M-101) -->
    <div class="ltms-card" style="margin-bottom:20px;">
        <div class="ltms-card-header"><?php esc_html_e( 'Información Fiscal / Tributaria', 'ltms' ); ?></div>
        <div class="ltms-card-body">
            <p style="color:#6b7280;font-size:.875rem;margin-bottom:16px;">
                <?php esc_html_e( 'Estos datos se usan para calcular correctamente ReteFuente, ReteICA y ReteIVA sobre tus ventas.', 'ltms' ); ?>
            </p>
            <?php
            $tax_regime    = get_user_meta( $vendor_id, 'ltms_tax_regime', true ) ?: 'simplified';
            $nit           = get_user_meta( $vendor_id, 'ltms_nit', true ) ?: '';
            $ciiu_code     = get_user_meta( $vendor_id, 'ltms_ciiu_code', true ) ?: '';
            $municipality  = get_user_meta( $vendor_id, 'ltms_municipality', true ) ?: '';
            $gran_contrib  = get_user_meta( $vendor_id, 'ltms_is_gran_contribuyente', true ) ? 'yes' : 'no';
            ?>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
                <div>
                    <label style="display:block;font-size:.875rem;font-weight:500;margin-bottom:6px;">
                        <?php esc_html_e( 'Régimen Tributario', 'ltms' ); ?>
                    </label>
                    <select name="ltms_tax_regime" style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;">
                        <option value="simplified" <?php selected( $tax_regime, 'simplified' ); ?>><?php esc_html_e( 'Simplificado (No responsable de IVA)', 'ltms' ); ?></option>
                        <option value="common" <?php selected( $tax_regime, 'common' ); ?>><?php esc_html_e( 'Régimen Común (Responsable de IVA)', 'ltms' ); ?></option>
                        <option value="special" <?php selected( $tax_regime, 'special' ); ?>><?php esc_html_e( 'Régimen Especial (ESAL)', 'ltms' ); ?></option>
                        <option value="gran_contribuyente" <?php selected( $tax_regime, 'gran_contribuyente' ); ?>><?php esc_html_e( 'Gran Contribuyente', 'ltms' ); ?></option>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:.875rem;font-weight:500;margin-bottom:6px;">
                        <?php esc_html_e( 'NIT / Cédula Fiscal', 'ltms' ); ?>
                    </label>
                    <input type="text" name="ltms_nit"
                           value="<?php echo esc_attr( $nit ); ?>"
                           placeholder="<?php esc_attr_e( 'Ej: 900123456-1', 'ltms' ); ?>"
                           style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;">
                </div>
                <div>
                    <label style="display:block;font-size:.875rem;font-weight:500;margin-bottom:6px;">
                        <?php esc_html_e( 'Código CIIU (actividad económica)', 'ltms' ); ?>
                    </label>
                    <input type="text" name="ltms_ciiu_code"
                           value="<?php echo esc_attr( $ciiu_code ); ?>"
                           placeholder="<?php esc_attr_e( 'Ej: 4791', 'ltms' ); ?>"
                           style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;">
                    <span style="font-size:.75rem;color:#9ca3af;"><?php esc_html_e( 'Código de 4 dígitos DIAN', 'ltms' ); ?></span>
                </div>
                <div>
                    <label style="display:block;font-size:.875rem;font-weight:500;margin-bottom:6px;">
                        <?php esc_html_e( 'Municipio (para ReteICA)', 'ltms' ); ?>
                    </label>
                    <?php
                    // M-200: select DANE — fuente de verdad para territorialidad ReteICA.
                    // Si el valor guardado es legacy ('bogota', etc.), Order_Split lo resuelve a DANE.
                    $muni_options = class_exists( 'LTMS_Business_Dane_Catalog' )
                        ? LTMS_Business_Dane_Catalog::get_options( true )
                        : [];
                    ?>
                    <select name="ltms_municipality"
                            style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;">
                        <?php if ( empty( $muni_options ) ) : ?>
                            <option value=""><?php esc_html_e( 'Catálogo no disponible', 'ltms' ); ?></option>
                        <?php else : ?>
                            <?php foreach ( $muni_options as $code => $label ) : ?>
                                <option value="<?php echo esc_attr( (string) $code ); ?>"
                                    <?php selected( (string) $municipality, (string) $code ); ?>>
                                    <?php echo esc_html( $label ); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <span style="font-size:.75rem;color:#9ca3af;">
                        <?php esc_html_e( 'Código DANE — define la tarifa ReteICA municipal aplicable.', 'ltms' ); ?>
                    </span>
                </div>
            </div>
            <div style="margin-bottom:8px;">
                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                    <input type="checkbox" name="ltms_is_gran_contribuyente" value="yes"
                           <?php checked( $gran_contrib, 'yes' ); ?>>
                    <span style="font-size:.875rem;font-weight:500;">
                        <?php esc_html_e( 'Soy Gran Contribuyente (activará ReteIVA 15%)', 'ltms' ); ?>
                    </span>
                </label>
            </div>
        </div>
    </div>

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
            <!-- Datos bancarios para retiros -->
            <div style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:8px;padding:16px;margin-bottom:20px;">
                <p style="font-size:0.8rem;font-weight:600;color:#374151;margin:0 0 14px;letter-spacing:.5px;text-transform:uppercase;">
                    🏦 <?php esc_html_e( 'Cuenta Bancaria para Retiros', 'ltms' ); ?>
                </p>
                <p style="font-size:0.78rem;color:#6b7280;margin:0 0 14px;">
                    <?php esc_html_e( 'Esta cuenta se usará automáticamente al solicitar un retiro.', 'ltms' ); ?>
                </p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                    <div>
                        <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:5px;"><?php esc_html_e( 'Banco', 'ltms' ); ?></label>
                        <input type="text" name="ltms_bank_name" id="ltms-setting-bank"
                               value="<?php echo esc_attr( $bank_name ); ?>"
                               placeholder="<?php esc_attr_e( 'Ej: Bancolombia', 'ltms' ); ?>"
                               style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;">
                    </div>
                    <div>
                        <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:5px;"><?php esc_html_e( 'Tipo de Cuenta', 'ltms' ); ?></label>
                        <select name="ltms_bank_account_type" style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;">
                            <option value="ahorros" <?php selected( $bank_account_type, 'ahorros' ); ?>><?php esc_html_e( 'Ahorros', 'ltms' ); ?></option>
                            <option value="corriente" <?php selected( $bank_account_type, 'corriente' ); ?>><?php esc_html_e( 'Corriente', 'ltms' ); ?></option>
                            <option value="nequi" <?php selected( $bank_account_type, 'nequi' ); ?>><?php esc_html_e( 'Nequi', 'ltms' ); ?></option>
                            <option value="daviplata" <?php selected( $bank_account_type, 'daviplata' ); ?>><?php esc_html_e( 'Daviplata', 'ltms' ); ?></option>
                        </select>
                    </div>
                </div>
                <div style="margin-bottom:12px;">
                    <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:5px;"><?php esc_html_e( 'Número de Cuenta', 'ltms' ); ?></label>
                    <input type="text" name="ltms_bank_account_number" id="ltms-setting-bank-account"
                           value="<?php echo esc_attr( $bank_account ); ?>"
                           placeholder="<?php esc_attr_e( 'Ej: 69812345678', 'ltms' ); ?>"
                           style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;">
                </div>
                <div>
                    <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:5px;"><?php esc_html_e( 'Nombre del Titular', 'ltms' ); ?></label>
                    <input type="text" name="ltms_bank_account_holder" id="ltms-setting-bank-holder"
                           value="<?php echo esc_attr( $bank_holder ); ?>"
                           placeholder="<?php esc_attr_e( 'Nombre como aparece en el banco', 'ltms' ); ?>"
                           style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;">
                </div>
            </div>

            <button type="button" class="ltms-btn ltms-btn-primary" id="ltms-save-settings-btn">
                💾 <?php esc_html_e( 'Guardar Cambios', 'ltms' ); ?>
            </button>
        </div>
    </div>

    <!-- v2.9.81 P1: Vacation Mode (Woodmart-inspired) -->
    <div class="ltms-card" style="margin-bottom:20px;">
        <div class="ltms-card-header">🏖️ <?php esc_html_e( 'Modo Vacaciones', 'ltms' ); ?></div>
        <div class="ltms-card-body">
            <p style="font-size:0.85rem;color:#6b7280;margin-bottom:16px;">
                <?php esc_html_e( 'Activa el modo vacaciones para pausar temporalmente tus ventas. Tus productos seguirán visibles pero no se podrán comprar.', 'ltms' ); ?>
            </p>
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="ltms_vacation_mode" id="ltms-vacation-mode" value="yes" <?php checked( $vacation_mode ); ?>>
                    <span style="font-weight:600;font-size:0.9rem;"><?php esc_html_e( 'Activar modo vacaciones', 'ltms' ); ?></span>
                </label>
            </div>
            <div>
                <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:5px;"><?php esc_html_e( 'Mensaje para clientes (opcional)', 'ltms' ); ?></label>
                <textarea name="ltms_vacation_message" id="ltms-vacation-message" rows="2"
                          placeholder="<?php esc_attr_e( 'Ej: Estaremos de vacaciones del 1 al 15 de enero.', 'ltms' ); ?>"
                          style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;font-size:0.85rem;"><?php echo esc_textarea( $vacation_msg ); ?></textarea>
            </div>
        </div>
    </div>

    <!-- v2.9.81 P1: Store Logo Upload -->
    <div class="ltms-card" style="margin-bottom:20px;">
        <div class="ltms-card-header">🖼️ <?php esc_html_e( 'Logo de tu Tienda', 'ltms' ); ?></div>
        <div class="ltms-card-body">
            <p style="font-size:0.85rem;color:#6b7280;margin-bottom:16px;">
                <?php esc_html_e( 'Sube un logo para tu tienda. Se mostrará en tu vitrina pública.', 'ltms' ); ?>
            </p>
            <div style="display:flex;align-items:center;gap:16px;">
                <div id="ltms-logo-preview" style="width:80px;height:80px;border:2px dashed #d1d5db;border-radius:12px;display:flex;align-items:center;justify-content:center;overflow:hidden;cursor:pointer;background:#f9fafb;">
                    <?php if ( $store_logo_url ) : ?>
                        <img src="<?php echo esc_url( $store_logo_url ); ?>" alt="Logo" style="width:100%;height:100%;object-fit:cover;">
                    <?php else : ?>
                        <span style="font-size:1.5rem;color:#d1d5db;">📷</span>
                    <?php endif; ?>
                </div>
                <div>
                    <input type="hidden" name="ltms_store_logo_id" id="ltms-store-logo-id" value="<?php echo esc_attr( $store_logo_id ); ?>">
                    <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm" id="ltms-upload-logo-btn">
                        <?php esc_html_e( 'Subir logo', 'ltms' ); ?>
                    </button>
                    <?php if ( $store_logo_id ) : ?>
                    <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm" id="ltms-remove-logo-btn" style="margin-left:8px;color:#ef4444;">
                        <?php esc_html_e( 'Quitar', 'ltms' ); ?>
                    </button>
                    <?php endif; ?>
                    <p style="font-size:0.75rem;color:#9ca3af;margin-top:6px;"><?php esc_html_e( 'JPG, PNG o WEBP. Máx 2MB. Recomendado: 400x400px.', 'ltms' ); ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- v2.9.83 P2: Horarios y Redes Sociales (Woodmart-inspired) -->
    <div class="ltms-card" style="margin-bottom:20px;">
        <div class="ltms-card-header">🕐 <?php esc_html_e( 'Horarios y Redes Sociales', 'ltms' ); ?></div>
        <div class="ltms-card-body">
            <div style="margin-bottom:12px;">
                <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:5px;"><?php esc_html_e( 'Horario de atención', 'ltms' ); ?></label>
                <input type="text" name="ltms_store_schedule" value="<?php echo esc_attr( $store_schedule ); ?>"
                       placeholder="<?php esc_attr_e( 'Ej: Lun-Vie 9am-6pm, Sáb 10am-2pm', 'ltms' ); ?>"
                       style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;">
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div>
                    <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:5px;">📷 Instagram</label>
                    <input type="text" name="ltms_store_instagram" value="<?php echo esc_attr( $store_instagram ); ?>"
                           placeholder="<?php esc_attr_e( '@mitienda', 'ltms' ); ?>"
                           style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;">
                </div>
                <div>
                    <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:5px;">👍 Facebook</label>
                    <input type="text" name="ltms_store_facebook" value="<?php echo esc_attr( $store_facebook ); ?>"
                           placeholder="<?php esc_attr_e( 'facebook.com/mitienda', 'ltms' ); ?>"
                           style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;">
                </div>
            </div>
            <div style="margin-top:12px;">
                <label style="display:block;font-size:0.8rem;font-weight:500;margin-bottom:5px;">💬 WhatsApp</label>
                <input type="text" name="ltms_store_whatsapp" value="<?php echo esc_attr( $store_whatsapp ); ?>"
                       placeholder="<?php esc_attr_e( '+57 300 000 0000', 'ltms' ); ?>"
                       style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;">
            </div>
        </div>
    </div>

<?php if ( $platform_ga4_on || $platform_pix_on ) : ?>
    <div class="ltms-card" style="margin-bottom:20px;">
        <div class="ltms-card-header">📊 <?php esc_html_e( 'Analytics & Tracking de Mi Tienda', 'ltms' ); ?></div>
        <div class="ltms-card-body">
            <p style="font-size:0.85rem;color:#6b7280;margin-bottom:16px;">
                <?php esc_html_e( 'Configura tu propio pixel para medir el tráfico hacia tus productos. Tus píxeles se activan solo en las páginas de tus productos.', 'ltms' ); ?>
            </p>
            <?php if ( $platform_ga4_on ) : ?>
            <div style="margin-bottom:16px;">
                <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;">
                    <?php esc_html_e( 'Google Analytics 4 — Measurement ID', 'ltms' ); ?>
                </label>
                <input type="text" name="ltms_vendor_ga4_id"
                       value="<?php echo esc_attr( $vendor_ga4_id ); ?>"
                       placeholder="G-XXXXXXXXXX"
                       style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;">
                <p style="font-size:0.78rem;color:#9ca3af;margin-top:4px;">
                    <?php esc_html_e( 'Encuéntralo en Google Analytics → Admin → Flujos de datos.', 'ltms' ); ?>
                </p>
            </div>
            <?php endif; ?>
            <?php if ( $platform_pix_on ) : ?>
            <div style="margin-bottom:16px;">
                <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;">
                    <?php esc_html_e( 'Meta Pixel ID (Facebook / Instagram)', 'ltms' ); ?>
                </label>
                <input type="text" name="ltms_vendor_pixel_id"
                       value="<?php echo esc_attr( $vendor_pixel_id ); ?>"
                       placeholder="123456789012345"
                       style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;">
                <p style="font-size:0.78rem;color:#9ca3af;margin-top:4px;">
                    <?php esc_html_e( 'Encuéntralo en Meta Business Suite → Fuentes de datos → Píxeles.', 'ltms' ); ?>
                </p>
            </div>
            <?php endif; ?>
            <button type="button" class="ltms-btn ltms-btn-primary" id="ltms-save-analytics-btn">
                💾 <?php esc_html_e( 'Guardar Analytics', 'ltms' ); ?>
            </button>
            <span id="ltms-analytics-notice" style="display:none;margin-left:12px;font-size:0.85rem;"></span>
        </div>
    </div>
<?php endif; ?>

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

// v2.3.0 — Guardar solo campos de analytics
jQuery('#ltms-save-analytics-btn').on('click', function() {
    var settings = {};
    jQuery('[name="ltms_vendor_ga4_id"], [name="ltms_vendor_pixel_id"]').each(function() {
        settings[jQuery(this).attr('name')] = jQuery(this).val();
    });
    var $btn    = jQuery(this).prop('disabled', true).text('Guardando…');
    var $notice = jQuery('#ltms-analytics-notice');
    jQuery.ajax({
        url: ltmsDashboard.ajax_url,
        method: 'POST',
        data: { action: 'ltms_save_vendor_settings', nonce: ltmsDashboard.nonce, settings: settings },
        success: function(response) {
            if (response.success) {
                $notice.css('color','#16a34a').text('✅ Guardado').show();
            } else {
                $notice.css('color','#dc2626').text('❌ Error al guardar').show();
            }
            setTimeout(function() { $notice.fadeOut(); }, 3000);
        },
        complete: function() { $btn.prop('disabled', false).text('💾 Guardar Analytics'); }
    });
});
</script>
