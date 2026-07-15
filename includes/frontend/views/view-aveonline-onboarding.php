<?php
/**
 * Vista: Wizard de Onboarding Aveonline para Vendedores
 *
 * Flujo de 4 pasos que registra al vendedor en Aveonline:
 *   Paso 1: Aceptar Términos (email + teléfono)
 *   Paso 2: Crear Lead (nombre + password para Aveonline)
 *   Paso 3: Identidad + Documentos (CIFIN)
 *   Paso 4: Datos Comerciales (nombre comercial + dirección + ciudad)
 *
 * Se renderiza dentro del dashboard del vendedor (view-envios.php o vista dedicada).
 *
 * @package LTMS
 * @version 2.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$user_id          = get_current_user_id();
$onboarding_status = get_user_meta( $user_id, '_ltms_ave_onboarding_status', true ) ?: 'pending';
$id_empresa_ave   = (int) get_user_meta( $user_id, '_ltms_ave_empresa_id', true );

// Si ya completó, no mostrar el wizard
if ( $onboarding_status === 'completed' && $id_empresa_ave ) {
    return;
}

$user       = get_userdata( $user_id );
$store_name = get_user_meta( $user_id, 'ltms_store_name', true ) ?: $user->display_name;
$phone      = get_user_meta( $user_id, 'ltms_phone', true ) ?: get_user_meta( $user_id, 'billing_phone', true ) ?: '';
$nonce      = wp_create_nonce( 'ltms_vendor_nonce' );

// Verificar si el JWT de onboarding está configurado
$has_jwt = false;
if ( class_exists( 'LTMS_Api_Aveonline_Onboarding' ) ) {
    $has_jwt = LTMS_Api_Aveonline_Onboarding::instance()->has_token();
}

if ( ! $has_jwt ) {
    // El admin no ha configurado el token JWT de onboarding
    return;
}

$step_labels = [
    'pending'      => 1,
    'step1'        => 2,
    'step2'        => 3,
    'step3'        => 4,
    'cifin_failed' => 3, // puede reintentar paso 3
];
$current_step = $step_labels[ $onboarding_status ] ?? 1;
?>

<div class="ltms-ave-onboarding-wizard" id="ltms-ave-onboarding-wizard" style="margin-bottom:32px;">
    <div class="ltms-card" style="padding:0;overflow:hidden;">
        <!-- Header -->
        <div style="background:linear-gradient(135deg,#0F4C75,#3282B8);color:#fff;padding:24px 32px;">
            <h2 style="margin:0;font-size:20px;font-weight:700;display:flex;align-items:center;gap:8px;">
                🚚 <?php esc_html_e( 'Registro en Aveonline', 'ltms' ); ?>
            </h2>
            <p style="margin:8px 0 0;font-size:14px;opacity:0.9;">
                <?php esc_html_e( 'Para enviar paquetes necesitas estar registrado en Aveonline (nuestro socio logístico). Completa estos 4 pasos.', 'ltms' ); ?>
            </p>
        </div>

        <!-- Progress bar -->
        <div style="display:flex;padding:16px 32px;border-bottom:1px solid #e5e7eb;background:#f9fafb;">
            <?php for ( $s = 1; $s <= 4; $s++ ) :
                $is_done    = $s < $current_step;
                $is_current = $s === $current_step;
            ?>
            <div style="flex:1;display:flex;align-items:center;gap:8px;">
                <div style="width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;
                    <?php echo $is_done ? 'background:#16A34A;color:#fff;' : ($is_current ? 'background:#0F4C75;color:#fff;' : 'background:#e5e7eb;color:#6b7280;'); ?>">
                    <?php echo $is_done ? '✓' : $s; ?>
                </div>
                <span style="font-size:12px;font-weight:600;<?php echo $is_current ? 'color:#0F4C75;' : 'color:#6b7280;'; ?>">
                    <?php
                    echo [ 1 => 'Términos', 2 => 'Cuenta', 3 => 'Identidad', 4 => 'Comercial' ][ $s ];
                    ?>
                </span>
                <?php if ( $s < 4 ) : ?>
                <div style="flex:1;height:2px;background:<?php echo $is_done ? '#16A34A' : '#e5e7eb'; ?>;margin:0 4px;"></div>
                <?php endif; ?>
            </div>
            <?php endfor; ?>
        </div>

        <!-- CIFIN failed warning -->
        <?php if ( $onboarding_status === 'cifin_failed' ) : ?>
        <div style="padding:16px 32px;background:#FEF3C7;border-bottom:1px solid #FDE047;">
            <p style="margin:0;color:#92400E;font-size:14px;">
                ⚠️ <strong><?php esc_html_e( 'Validación CIFIN no exitosa.', 'ltms' ); ?></strong>
                <?php esc_html_e( 'Tu solicitud no calificó en la validación de listas restrictivas. Puedes reintentar o contactar a soporte.', 'ltms' ); ?>
            </p>
        </div>
        <?php endif; ?>

        <!-- Step content -->
        <div style="padding:24px 32px;" id="ltms-ave-onboarding-content">

            <!-- Paso 1: Términos -->
            <div class="ltms-ave-step" data-step="1" <?php echo $current_step !== 1 ? 'style="display:none;"' : ''; ?>>
                <h3 style="margin:0 0 16px;font-size:16px;"><?php esc_html_e( 'Paso 1: Aceptar Términos', 'ltms' ); ?></h3>
                <p style="color:#6b7280;font-size:14px;margin-bottom:16px;">
                    <?php esc_html_e( 'Confirma tu email y teléfono para iniciar el registro en Aveonline.', 'ltms' ); ?>
                </p>
                <div style="display:grid;gap:12px;max-width:400px;">
                    <div>
                        <label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Email', 'ltms' ); ?></label>
                        <input type="email" id="ave-ob-email" class="ltms-form-control" value="<?php echo esc_attr( $user->user_email ); ?>" readonly style="background:#f5f5f5;">
                    </div>
                    <div>
                        <label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Teléfono', 'ltms' ); ?></label>
                        <input type="tel" id="ave-ob-phone" class="ltms-form-control" value="<?php echo esc_attr( $phone ); ?>" placeholder="+57 300 000 0000">
                    </div>
                </div>
                <button type="button" class="ltms-btn ltms-btn-primary" id="ave-ob-step1-btn" style="margin-top:16px;">
                    <?php esc_html_e( 'Aceptar y continuar', 'ltms' ); ?>
                </button>
            </div>

            <!-- Paso 2: Crear Lead -->
            <div class="ltms-ave-step" data-step="2" <?php echo $current_step !== 2 ? 'style="display:none;"' : ''; ?>>
                <h3 style="margin:0 0 16px;font-size:16px;"><?php esc_html_e( 'Paso 2: Crear Cuenta', 'ltms' ); ?></h3>
                <p style="color:#6b7280;font-size:14px;margin-bottom:16px;">
                    <?php esc_html_e( 'Estos datos crean tu cuenta en Aveonline. La contraseña es para acceder a la plataforma de Aveonline (no a LTMS).', 'ltms' ); ?>
                </p>
                <div style="display:grid;gap:12px;max-width:400px;">
                    <div>
                        <label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Nombre completo', 'ltms' ); ?></label>
                        <input type="text" id="ave-ob-name" class="ltms-form-control" value="<?php echo esc_attr( $store_name ); ?>">
                    </div>
                    <div>
                        <label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Contraseña para Aveonline', 'ltms' ); ?></label>
                        <input type="password" id="ave-ob-password" class="ltms-form-control" placeholder="Mínimo 8 caracteres" minlength="8">
                    </div>
                </div>
                <button type="button" class="ltms-btn ltms-btn-primary" id="ave-ob-step2-btn" style="margin-top:16px;">
                    <?php esc_html_e( 'Crear cuenta', 'ltms' ); ?>
                </button>
            </div>

            <!-- Paso 3: Identidad y Documentos -->
            <div class="ltms-ave-step" data-step="3" <?php echo $current_step !== 3 ? 'style="display:none;"' : ''; ?>>
                <h3 style="margin:0 0 16px;font-size:16px;"><?php esc_html_e( 'Paso 3: Identidad y Documentos', 'ltms' ); ?></h3>
                <p style="color:#6b7280;font-size:14px;margin-bottom:16px;">
                    <?php esc_html_e( 'Sube tus documentos para validación CIFIN. Persona natural o jurídica.', 'ltms' ); ?>
                </p>
                <div style="display:grid;gap:12px;max-width:500px;">
                    <div>
                        <label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Tipo de persona', 'ltms' ); ?></label>
                        <select id="ave-ob-doc-type" class="ltms-form-control">
                            <option value="1"><?php esc_html_e( 'Persona Natural (Cédula)', 'ltms' ); ?></option>
                            <option value="3"><?php esc_html_e( 'Persona Jurídica (NIT)', 'ltms' ); ?></option>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Número de documento', 'ltms' ); ?></label>
                        <input type="text" id="ave-ob-id-document" class="ltms-form-control" placeholder="Ej: 1234567890">
                    </div>

                    <!-- Persona Natural -->
                    <div id="ave-ob-natural-fields">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                            <div>
                                <label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Nombres', 'ltms' ); ?></label>
                                <input type="text" id="ave-ob-full-name" class="ltms-form-control">
                            </div>
                            <div>
                                <label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Apellidos', 'ltms' ); ?></label>
                                <input type="text" id="ave-ob-lastname" class="ltms-form-control">
                            </div>
                        </div>
                        <div style="margin-top:8px;">
                            <label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Cédula frontal (foto)', 'ltms' ); ?></label>
                            <input type="file" id="ave-ob-cedula-front" accept="image/*,application/pdf" class="ltms-form-control">
                        </div>
                        <div style="margin-top:8px;">
                            <label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Cédula trasera (foto)', 'ltms' ); ?></label>
                            <input type="file" id="ave-ob-cedula-back" accept="image/*,application/pdf" class="ltms-form-control">
                        </div>
                    </div>

                    <!-- Persona Jurídica (hidden by default) -->
                    <div id="ave-ob-juridica-fields" style="display:none;">
                        <div>
                            <label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Razón social', 'ltms' ); ?></label>
                            <input type="text" id="ave-ob-business-name" class="ltms-form-control">
                        </div>
                        <div>
                            <label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Nombre del representante legal', 'ltms' ); ?></label>
                            <input type="text" id="ave-ob-nombre-legal" class="ltms-form-control">
                        </div>
                        <div>
                            <label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Cédula del representante', 'ltms' ); ?></label>
                            <input type="text" id="ave-ob-cedula-legal" class="ltms-form-control">
                        </div>
                        <div style="margin-top:8px;">
                            <label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'RUT (PDF)', 'ltms' ); ?></label>
                            <input type="file" id="ave-ob-rut" accept="image/*,application/pdf" class="ltms-form-control">
                        </div>
                        <div style="margin-top:8px;">
                            <label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Cámara de Comercio (PDF)', 'ltms' ); ?></label>
                            <input type="file" id="ave-ob-camara" accept="image/*,application/pdf" class="ltms-form-control">
                        </div>
                    </div>
                </div>
                <button type="button" class="ltms-btn ltms-btn-primary" id="ave-ob-step3-btn" style="margin-top:16px;">
                    <?php esc_html_e( 'Enviar documentos', 'ltms' ); ?>
                </button>
            </div>

            <!-- Paso 4: Datos Comerciales -->
            <div class="ltms-ave-step" data-step="4" <?php echo $current_step !== 4 ? 'style="display:none;"' : ''; ?>>
                <h3 style="margin:0 0 16px;font-size:16px;"><?php esc_html_e( 'Paso 4: Datos Comerciales', 'ltms' ); ?></h3>
                <p style="color:#6b7280;font-size:14px;margin-bottom:16px;">
                    <?php esc_html_e( 'Último paso: confirma el nombre comercial y dirección de tu tienda.', 'ltms' ); ?>
                </p>
                <div style="display:grid;gap:12px;max-width:400px;">
                    <div>
                        <label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Nombre comercial', 'ltms' ); ?></label>
                        <input type="text" id="ave-ob-tradename" class="ltms-form-control" value="<?php echo esc_attr( $store_name ); ?>">
                    </div>
                    <div>
                        <label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Dirección', 'ltms' ); ?></label>
                        <input type="text" id="ave-ob-address" class="ltms-form-control" value="<?php echo esc_attr( get_user_meta( $user_id, 'ltms_store_address', true ) ?: '' ); ?>">
                    </div>
                    <div>
                        <label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Código postal', 'ltms' ); ?></label>
                        <input type="text" id="ave-ob-city" class="ltms-form-control" placeholder="Ej: 110111" inputmode="numeric">
                    </div>
                </div>
                <button type="button" class="ltms-btn ltms-btn-primary" id="ave-ob-step4-btn" style="margin-top:16px;">
                    ✅ <?php esc_html_e( 'Completar registro', 'ltms' ); ?>
                </button>
            </div>

            <!-- Loading spinner -->
            <div id="ave-ob-loading" style="display:none;text-align:center;padding:24px;">
                <div class="ltms-spinner" style="margin:0 auto;"></div>
                <p style="margin-top:8px;color:#6b7280;font-size:14px;" id="ave-ob-loading-text"><?php esc_html_e( 'Procesando...', 'ltms' ); ?></p>
            </div>

            <!-- Error display -->
            <div id="ave-ob-error" style="display:none;padding:12px 16px;background:#FEE2E2;border-radius:8px;margin-top:16px;">
                <p style="margin:0;color:#991B1B;font-size:14px;" id="ave-ob-error-text"></p>
            </div>

            <!-- Success display -->
            <div id="ave-ob-success" style="display:none;padding:16px;background:#DCFCE7;border-radius:8px;margin-top:16px;">
                <p style="margin:0;color:#166534;font-size:14px;">
                    ✅ <strong><?php esc_html_e( '¡Registro completado!', 'ltms' ); ?></strong>
                    <?php esc_html_e( 'Ya puedes generar guías de envío en Aveonline.', 'ltms' ); ?>
                </p>
            </div>
        </div>
    </div>
</div>

<?php
// FASE2B P0 FIX (CSP): inline <script> moved to external assets/js/ltms-aveonline-onboarding.js
wp_enqueue_script( 'ltms-aveonline-onboarding', LTMS_ASSETS_URL . 'js/ltms-aveonline-onboarding.js', [ 'jquery' ], LTMS_VERSION, true );
?>
