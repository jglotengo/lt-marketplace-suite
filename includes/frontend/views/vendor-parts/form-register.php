<?php
/**
 * Partial: Formulario de registro del vendedor
 *
 * @package    LTMS\Frontend\Views
 * @version    1.5.0
 */

defined( 'ABSPATH' ) || exit;

$country = LTMS_Core_Config::get_country();

// v2.9.61 DEEP-AUDIT-002 UX-06: Detectar si el vendor viene del flujo de Google OAuth
// con perfil incompleto y necesita completar datos.
$complete_profile = isset( $_GET['complete_profile'] ) && $_GET['complete_profile'] === '1'; // phpcs:ignore
$current_user_id = get_current_user_id();
$profile_incomplete = $current_user_id && get_user_meta( $current_user_id, 'ltms_profile_incomplete', true );
?>

<div class="ltms-auth-card ltms-register-card" id="ltms-register-wrap">

    <div class="ltms-auth-logo">
        <?php
        $logo_id = get_theme_mod( 'custom_logo' );
        if ( $logo_id ) {
            echo wp_get_attachment_image( $logo_id, 'medium', false, [ 'class' => 'ltms-auth-logo-img', 'alt' => get_bloginfo( 'name' ) ] );
        } else {
            echo '<span class="ltms-auth-site-name">' . esc_html( get_bloginfo( 'name' ) ) . '</span>';
        }
        ?>
    </div>

    <?php if ( $complete_profile || $profile_incomplete ) : ?>
        <h2 class="ltms-auth-title"><?php esc_html_e( 'Completa tu Perfil de Vendedor', 'ltms' ); ?></h2>
        <p class="ltms-auth-subtitle"><?php esc_html_e( 'Necesitamos algunos datos adicionales para activar tu cuenta.', 'ltms' ); ?></p>
        <div class="ltms-notice ltms-notice-info" role="alert">
            <p>ℹ️ <?php esc_html_e( 'Tu cuenta fue creada con Google. Completa estos campos para poder publicar productos.', 'ltms' ); ?></p>
        </div>
    <?php else : ?>
    <h2 class="ltms-auth-title"><?php esc_html_e( 'Crear Cuenta de Vendedor', 'ltms' ); ?></h2>
    <p class="ltms-auth-subtitle"><?php esc_html_e( 'Únete a la plataforma y empieza a vender.', 'ltms' ); ?></p>
    <?php endif; ?>

    <div id="ltms-register-notice" class="ltms-notice" style="display:none;" role="alert"></div>

    <!-- Pasos del wizard -->
    <div class="ltms-wizard-steps" aria-label="<?php esc_attr_e( 'Pasos del registro', 'ltms' ); ?>">
        <div class="ltms-step active" data-step="1"><?php esc_html_e( 'Datos Personales', 'ltms' ); ?></div>
        <div class="ltms-step" data-step="2"><?php esc_html_e( 'Tu Tienda', 'ltms' ); ?></div>
        <div class="ltms-step" data-step="3"><?php esc_html_e( 'Seguridad', 'ltms' ); ?></div>
    </div>

    <form id="ltms-register-form" class="ltms-auth-form" novalidate>
        <?php
        // El nonce real viaja en ltmsAuth.nonce (wp_localize_script, action
        // 'ltms_auth_nonce') y lo verifica el handler con check_ajax_referer.
        // M-2: se eliminó wp_nonce_field duplicado que era código muerto.
        ?>

        <!-- C-5: Honeypot anti-bot. Campo oculto que humanos no rellenan.
             M-AUDIT-REG-05: se cambió de position:absolute;left:-9999px (que algunos
             gestores de contraseñas sí rellenan si el name contiene "email") a
             display:none, y se renombró el campo a ltms_hp_website para que los
             autocomplete heurísticos no lo reconozcan como un campo de correo. -->
        <div class="ltms-hp-field" aria-hidden="true" style="display:none;">
            <label for="ltms-hp-website">Website (do not fill)</label>
            <input type="text" name="ltms_hp_website" id="ltms-hp-website" tabindex="-1" autocomplete="off" value="">
        </div>

        <!-- Paso 1: Datos personales -->
        <div class="ltms-wizard-page" data-page="1">
            <div class="ltms-form-row-2">
                <div class="ltms-form-group">
                    <label for="ltms-reg-first-name"><?php esc_html_e( 'Nombre *', 'ltms' ); ?></label>
                    <input type="text" id="ltms-reg-first-name" name="first_name" class="ltms-form-control" required placeholder="<?php esc_attr_e( 'Juan', 'ltms' ); ?>">
                </div>
                <div class="ltms-form-group">
                    <label for="ltms-reg-last-name"><?php esc_html_e( 'Apellido *', 'ltms' ); ?></label>
                    <input type="text" id="ltms-reg-last-name" name="last_name" class="ltms-form-control" required placeholder="<?php esc_attr_e( 'Pérez', 'ltms' ); ?>">
                </div>
            </div>

            <div class="ltms-form-group">
                <label for="ltms-reg-email"><?php esc_html_e( 'Email *', 'ltms' ); ?></label>
                <input type="email" id="ltms-reg-email" name="email" class="ltms-form-control" required placeholder="<?php esc_attr_e( 'tu@email.com', 'ltms' ); ?>">
            </div>

            <!-- M-MX-1: Selector de país del vendedor. Controla documentos, placeholder tel, y moneda de wallet. -->
            <div class="ltms-form-group">
                <label for="ltms-reg-vendor-country"><?php esc_html_e( '¿En qué país vendes?', 'ltms' ); ?> *</label>
                <select id="ltms-reg-vendor-country" name="vendor_country" class="ltms-form-control" required>
                    <option value="CO" <?php selected( $country, 'CO' ); ?>>🇨🇴 Colombia</option>
                    <option value="MX">🇲🇽 México</option>
                </select>
            </div>

            <div class="ltms-form-group">
                <label for="ltms-reg-phone"><?php esc_html_e( 'Teléfono *', 'ltms' ); ?></label>
                <input type="tel" id="ltms-reg-phone" name="phone" class="ltms-form-control" required placeholder="<?php echo 'CO' === $country ? '+57 300 000 0000' : '+52 55 0000 0000'; ?>">
                <small class="ltms-field-hint"><?php esc_html_e( 'Requerido para el contrato de vinculación.', 'ltms' ); ?></small>
            </div>

            <div class="ltms-form-group">
                <label for="ltms-reg-document-type"><?php esc_html_e( 'Tipo de Documento *', 'ltms' ); ?></label>
                <select id="ltms-reg-document-type" name="document_type" class="ltms-form-control" required>
                    <?php if ( 'CO' === $country ) : ?>
                        <option value=""><?php esc_html_e( 'Seleccionar...', 'ltms' ); ?></option>
                        <option value="CC">Cédula de Ciudadanía</option>
                        <option value="CE">Cédula de Extranjería</option>
                        <option value="NIT">NIT</option>
                        <option value="PAS">Pasaporte</option>
                    <?php else : ?>
                        <option value=""><?php esc_html_e( 'Seleccionar...', 'ltms' ); ?></option>
                        <option value="RFC">RFC</option>
                        <option value="CURP">CURP</option>
                        <option value="PAS">Pasaporte</option>
                    <?php endif; ?>
                </select>
            </div>

            <div class="ltms-form-group">
                <label for="ltms-reg-document-number"><?php esc_html_e( 'Número de Documento *', 'ltms' ); ?></label>
                <input type="text" id="ltms-reg-document-number" name="document_number" class="ltms-form-control" required placeholder="<?php esc_attr_e( 'Número de identificación', 'ltms' ); ?>">
            </div>

            <button type="button" class="ltms-btn ltms-btn-primary ltms-btn-full ltms-wizard-next" data-next="2">
                <?php esc_html_e( 'Siguiente', 'ltms' ); ?> &rarr;
            </button>
        </div>

        <!-- Paso 2: Tienda -->
        <div class="ltms-wizard-page" data-page="2" style="display:none;">

            <!-- M-TURISMO-01: tipo de negocio — determina si el vendedor entra en el
                 flujo de Compliance RNT/SECTUR. Solo los de 'tourism' crean registro
                 en bkr_lt_tourism_compliance al aprobarse el KYC. -->
            <div class="ltms-form-group">
                <?php
                // UX-003 (P2) UX-AUDIT-REGISTER FIX: los radios de business_type
                // estaban sueltos dentro de un <div> sin agrupación semántica. Los
                // screen readers (NVDA, JAWS, VoiceOver) anuncian grupos de radio
                // por su <legend> dentro de un <fieldset> — sin esto, un usuario
                // de lector de pantalla oye "radio, Productos físicos; radio,
                // Productivos digitales; ..." sin contexto de que todos pertenecen
                // a la misma pregunta "¿Qué tipo de productos ofreces?". WCAG 2.1
                // SC 1.3.1 (Info and Relationships) requiere esta agrupación.
                ?>
                <fieldset class="ltms-btype-fieldset" style="border:0;padding:0;margin:0;min-width:0;">
                    <legend style="font-weight:600;font-size:0.95rem;color:#1d2327;padding:0;margin-bottom:6px;">
                        <?php esc_html_e( '¿Qué tipo de productos o servicios ofreces? *', 'ltms' ); ?>
                    </legend>
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-top:4px;">
                        <?php
                        $business_types = [
                            'physical'   => [ 'icon' => '📦', 'label' => 'Productos físicos',   'hint' => 'Ropa, electrónica, accesorios…' ],
                            'digital'    => [ 'icon' => '💻', 'label' => 'Productos digitales',  'hint' => 'Cursos, software, diseños…' ],
                            'services'   => [ 'icon' => '🛠️', 'label' => 'Servicios',            'hint' => 'Consultoría, reparaciones…' ],
                            'tourism'    => [ 'icon' => '🏨', 'label' => 'Turismo / Alojamiento','hint' => 'Hoteles, hostales, tours, glamping…' ],
                            'restaurant' => [ 'icon' => '🍽️', 'label' => 'Restaurante',          'hint' => 'Comida, bebidas, café, pastelería…' ],
                        ];
                        foreach ( $business_types as $val => $bt ) :
                        ?>
                        <label style="display:flex;flex-direction:column;gap:4px;padding:12px 14px;
                                      border:1.5px solid #d1d5db;border-radius:10px;cursor:pointer;
                                      background:#fafafa;transition:all .15s;"
                               class="ltms-btype-lbl" id="ltms-btype-lbl-<?php echo esc_attr($val); ?>">
                            <span style="font-size:1.4rem;"><?php echo esc_html($bt['icon']); ?></span>
                            <span style="font-weight:600;font-size:.875rem;color:#1d2327;">
                                <?php echo esc_html($bt['label']); ?>
                            </span>
                            <span style="font-size:.75rem;color:#6b7280;">
                                <?php echo esc_html($bt['hint']); ?>
                            </span>
                            <input type="radio" name="business_type" value="<?php echo esc_attr($val); ?>"
                                   id="ltms-btype-<?php echo esc_attr($val); ?>"
                                   class="ltms-btype-radio"
                                   style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;"
                                   required>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <small class="ltms-field-hint" style="margin-top:6px;display:block;">
                    <?php esc_html_e( 'Puedes ofrecer más de un tipo — elige el principal. Podrás ajustarlo desde tu panel luego.', 'ltms' ); ?>
                </small>

                <?php
                // REG-03 FIX: avisar al vendor DURANTE el registro qué documentos
                // extra necesitará según el business_type elegido. Antes, el vendor
                // solo se enteraba de INVIMA/COFEPRIS (restaurant) o RNT (tourism)
                // al llegar al paso de KYC post-registro, generando fricción y
                // abandono. Ahora el wizard lo adelanta.
                ?>
                <div id="ltms-btype-notice-restaurant" class="ltms-notice ltms-notice-info" style="display:none;margin-top:10px;padding:10px 14px;border-radius:8px;background:#fff7ed;border:1px solid #fed7aa;font-size:0.82rem;">
                    <strong>🍽️ Requisito adicional:</strong> Como restaurante, necesitarás tu
                    <strong>registro sanitario INVIMA</strong> (Colombia) o <strong>COFEPRIS</strong> (México).
                    No lo necesitas para registrarte ahora, pero sí para la verificación de identidad (KYC)
                    que harás en tu panel después. Tenlo listo antes de iniciar el KYC.
                </div>
                <div id="ltms-btype-notice-tourism" class="ltms-notice ltms-notice-info" style="display:none;margin-top:10px;padding:10px 14px;border-radius:8px;background:#eff6ff;border:1px solid #bfdbfe;font-size:0.82rem;">
                    <strong>🏨 Requisito adicional:</strong> Como turismo/alojamiento, necesitarás tu
                    <strong>Registro Nacional de Turismo (RNT)</strong> (Colombia, FONTUR) o
                    <strong>folio SECTUR</strong> (México).
                    No lo necesitas para registrarte ahora, pero sí para la verificación de identidad (KYC)
                    que harás en tu panel después. Tenlo listo antes de iniciar el KYC.
                </div>
            </div>

            <div class="ltms-form-group">
                <label for="ltms-reg-store-name"><?php esc_html_e( 'Nombre de tu Tienda *', 'ltms' ); ?></label>
                <input type="text" id="ltms-reg-store-name" name="store_name" class="ltms-form-control" required placeholder="<?php esc_attr_e( 'Mi Tienda Genial', 'ltms' ); ?>">
                <small class="ltms-field-hint"><?php esc_html_e( 'Este nombre será visible para los compradores.', 'ltms' ); ?></small>
            </div>

            <div class="ltms-form-group">
                <label for="ltms-reg-store-description"><?php esc_html_e( 'Descripción de tu Tienda', 'ltms' ); ?></label>
                <textarea id="ltms-reg-store-description" name="store_description" class="ltms-form-control" rows="3" placeholder="<?php esc_attr_e( 'Vendo productos de...', 'ltms' ); ?>"></textarea>
            </div>

            <?php
            // M-200: dropdown DANE de municipio del vendedor. Solo CO — define la tarifa ReteICA aplicable.
            if ( 'CO' === $country && class_exists( 'LTMS_Business_Dane_Catalog' ) ) :
                $muni_options = LTMS_Business_Dane_Catalog::get_options( true );
                if ( count( $muni_options ) > 1 ) :
            ?>
            <div class="ltms-form-group" id="ltms-municipality-wrap">
                <label for="ltms-reg-municipality"><?php esc_html_e( 'Municipio de tu tienda *', 'ltms' ); ?></label>
                <select id="ltms-reg-municipality" name="municipality_code" class="ltms-form-control" required>
                    <?php foreach ( $muni_options as $code => $label ) : ?>
                        <option value="<?php echo esc_attr( (string) $code ); ?>"><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="ltms-field-hint">
                    <?php esc_html_e( 'Define la tarifa ReteICA aplicable a tus ventas según el estatuto municipal.', 'ltms' ); ?>
                </small>
            </div>
            <?php endif; endif; ?>

            <div class="ltms-form-group">
                <label for="ltms-reg-address"><?php esc_html_e( 'Dirección de la tienda / domicilio *', 'ltms' ); ?></label>
                <input type="text" id="ltms-reg-address" name="store_address" class="ltms-form-control" required
                       placeholder="<?php esc_attr_e( 'Calle 10 # 5-23, Barrio Centro', 'ltms' ); ?>">
                <small class="ltms-field-hint"><?php esc_html_e( 'Dirección fiscal que aparecerá en el contrato de vinculación.', 'ltms' ); ?></small>
            </div>

            <?php if ( 'CO' === $country ) : ?>
            <div class="ltms-form-group">
                <label for="ltms-reg-tax-regime"><?php esc_html_e( 'Régimen tributario *', 'ltms' ); ?></label>
                <select id="ltms-reg-tax-regime" name="tax_regime" class="ltms-form-control" required>
                    <option value=""><?php esc_html_e( 'Seleccionar...', 'ltms' ); ?></option>
                    <option value="no_responsable_iva">Persona Natural — No responsable de IVA</option>
                    <option value="responsable_iva">Persona Natural — Responsable de IVA</option>
                    <option value="persona_juridica">Persona Jurídica — Responsable de IVA</option>
                    <option value="simplificado">Régimen Simple de Tributación (SIMPLE)</option>
                </select>
                <small class="ltms-field-hint"><?php esc_html_e( 'Determina las retenciones aplicables a tus pagos. Consulta tu RUT si tienes dudas.', 'ltms' ); ?></small>
            </div>
            <?php endif; ?>

            <div class="ltms-form-group">
                <label for="ltms-reg-referral-code"><?php esc_html_e( 'Código de Referido', 'ltms' ); ?></label>
                <input
                    type="text"
                    id="ltms-reg-referral-code"
                    name="referral_code"
                    class="ltms-form-control"
                    placeholder="<?php esc_attr_e( 'Opcional', 'ltms' ); ?>"
                    maxlength="8"
                    style="text-transform: uppercase;"
                    value="<?php echo esc_attr( strtoupper( sanitize_text_field( wp_unslash( $_GET['ref'] ?? '' ) ) ) ); ?>"
                >
                <small class="ltms-field-hint"><?php esc_html_e( 'Si alguien te invitó, ingresa su código (8 caracteres).', 'ltms' ); ?></small>
            </div>

            <div class="ltms-wizard-nav">
                <button type="button" class="ltms-btn ltms-btn-secondary ltms-wizard-back" data-back="1">&larr; <?php esc_html_e( 'Atrás', 'ltms' ); ?></button>
                <button type="button" class="ltms-btn ltms-btn-primary ltms-wizard-next" data-next="3"><?php esc_html_e( 'Siguiente', 'ltms' ); ?> &rarr;</button>
            </div>
        </div>

        <!-- Paso 3: Contraseña (solo registro normal) y TyC -->
        <div class="ltms-wizard-page" data-page="3" style="display:none;">

            <?php if ( $complete_profile || $profile_incomplete ) : ?>
            <!-- REG-E2E-003 (P2) REGISTRO-E2E FIX: en el flujo de completar perfil
                 (Google OAuth) los campos de contraseña NO se guardan —
                 ajax_complete_profile() no los lee y el vendor autentica con Google
                 (la cuenta se creó con password aleatorio). Antes se mostraban y
                 el usuario creía haber creado una contraseña válida para login por
                 credenciales, que nunca se guardaba. Ocultarlos + aviso claro. -->
            <div class="ltms-notice ltms-notice-info" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 14px;font-size:0.85rem;" role="note">
                <p style="margin:0;">🔐 <?php esc_html_e( 'Tu cuenta usa Google para iniciar sesión. No necesitas crear una contraseña.', 'ltms' ); ?></p>
            </div>
            <?php else : ?>
            <div class="ltms-form-group">
                <label for="ltms-reg-password"><?php esc_html_e( 'Contraseña *', 'ltms' ); ?></label>
                <div class="ltms-input-group">
                    <input type="password" id="ltms-reg-password" name="password" class="ltms-form-control" required minlength="8" placeholder="<?php esc_attr_e( 'Mínimo 8 caracteres, 1 mayúscula y 1 número', 'ltms' ); ?>">
                    <button type="button" class="ltms-toggle-password" data-target="ltms-reg-password" aria-label="<?php esc_attr_e( 'Mostrar contraseña', 'ltms' ); ?>">&#128065;</button>
                </div>
                <div class="ltms-password-strength" id="ltms-password-strength">
                    <div class="ltms-strength-bar"></div>
                    <span class="ltms-strength-label"></span>
                </div>
                <small class="ltms-field-hint"><?php esc_html_e( 'Mínimo 8 caracteres, incluye al menos una mayúscula y un número.', 'ltms' ); ?></small>
            </div>

            <div class="ltms-form-group">
                <label for="ltms-reg-password-confirm"><?php esc_html_e( 'Confirmar Contraseña *', 'ltms' ); ?></label>
                <div class="ltms-input-group">
                    <input type="password" id="ltms-reg-password-confirm" name="password_confirm" class="ltms-form-control" required placeholder="<?php esc_attr_e( 'Repite tu contraseña', 'ltms' ); ?>">
                    <button type="button" class="ltms-toggle-password" data-target="ltms-reg-password-confirm" aria-label="<?php esc_attr_e( 'Mostrar contraseña', 'ltms' ); ?>">&#128065;</button>
                </div>
            </div>
            <?php endif; ?>

            <div class="ltms-form-group">
                <label class="ltms-checkbox-label">
                    <input type="checkbox" name="accept_terms" value="1" required>
                    <?php
                    $terms_url   = get_permalink( get_option( 'ltms_terms_page_id' ) ) ?: '#';
                    $privacy_url = get_permalink( get_option( 'ltms_privacy_page_id' ) ) ?: '#';
                    // UX-008 (P2) UX-AUDIT-REGISTER FIX: rel="noopener noreferrer" en
                    // los links de Términos y Privacidad. Antes solo target="_blank"
                    // sin rel — vulnerabilidad de reverse tabnabbing (la página abierta
                    // puede hacer window.opener.location = phishing.com) y UX abrupta.
                    // noopener previene el acceso a window.opener, noreferrer evita que
                    // la página destino sepa el referer (privacidad del usuario).
                    printf(
                        esc_html__( 'Acepto los %1$sTérminos y Condiciones%2$s y la %3$sPolítica de Privacidad%4$s *', 'ltms' ),
                        '<a href="' . esc_url( $terms_url ) . '" target="_blank" rel="noopener noreferrer">',
                        '</a>',
                        '<a href="' . esc_url( $privacy_url ) . '" target="_blank" rel="noopener noreferrer">',
                        '</a>'
                    );
                    ?>
                </label>
            </div>

            <?php
            // SAGRILAFT consent (required in Colombia)
            if ( 'CO' === $country ) :
            ?>
            <div class="ltms-form-group">
                <label class="ltms-checkbox-label">
                    <input type="checkbox" name="accept_sagrilaft" value="1" required>
                    <?php
                    // UX-009 (P2) UX-AUDIT-REGISTER FIX: 'Autorizo SAGRILAFT' label
                    // ambiguo — usuarios no saben qué es SAGRILAFT ni qué autorizan.
                    // Ahora el label explica brevemente (prevención de lavado de
                    // activas, Ley 526/1999) y linka la fuente oficial de la ley
                    // para crear confianza y reducir la fricción del opt-in ciego.
                    // Rel="noopener noreferrer" por consistencia con UX-008.
                    $sagrilaft_law_url = 'https://www.funcionpublica.gov.co/eva/gestornormativo/norma.php?i=4282';
                    printf(
                        esc_html__( 'Autorizo el tratamiento de mis datos para prevención de lavado de activos (SAGRILAFT, %1$sLey 526 de 1999%2$s) *', 'ltms' ),
                        '<a href="' . esc_url( $sagrilaft_law_url ) . '" target="_blank" rel="noopener noreferrer" style="text-decoration:underline;">',
                        '</a>'
                    );
                    ?>
                </label>
            </div>
            <?php endif; ?>

            <div class="ltms-wizard-nav">
                <button type="button" class="ltms-btn ltms-btn-secondary ltms-wizard-back" data-back="2">&larr; <?php esc_html_e( 'Atrás', 'ltms' ); ?></button>
                <button type="submit" class="ltms-btn ltms-btn-primary" id="ltms-register-btn">
                    <span class="ltms-btn-text"><?php esc_html_e( 'Crear Cuenta', 'ltms' ); ?></span>
                    <span class="ltms-btn-spinner" style="display:none;">&#9696;</span>
                </button>
            </div>

            <?php
            // v2.9.60 MISSING-03: Cloudflare Turnstile CAPTCHA (opcional).
            // Solo se renderiza si el admin configura una site key en
            // LTMS → Settings → Security → Turnstile Site Key.
            // Si no hay key configurada, el honeypot sigue funcionando como fallback.
            $turnstile_site_key = LTMS_Core_Config::get( 'ltms_turnstile_site_key', '' );
            if ( ! empty( $turnstile_site_key ) ) :
            ?>
            <div class="ltms-form-group ltms-turnstile-wrap" style="margin-top:16px;">
                <div class="cf-turnstile" data-sitekey="<?php echo esc_attr( $turnstile_site_key ); ?>" data-theme="light"></div>
                <input type="hidden" name="ltms_turnstile_token" id="ltms-turnstile-token" value="">
            </div>
            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
            <?php /* FASE2B: moved to ltms-login-register.js */ ?>
            <?php endif; ?>
        </div>

    </form><!-- #ltms-register-form -->
    <!-- AUTH-RA1 (P1) RE-AUDIT-AUTH FIX: cierre de <form> ausente — el tag <form id="ltms-register-form"> -->
    <!-- abierto en línea ~53 nunca se cerraba, dejando el footer-auth y el cierre del card dentro -->
    <!-- del form. Bug HTML: (a) semántica inválida, (b) form.reset() en el JS (línea ~397) -->
    <!-- resetearía inputs futuros del footer si los tuviera, (c) el wizard step nav (.ltms-wizard-back) -->
    <!-- quedaba semánticamente dentro del form. -->

<?php
// FASE2B P0 FIX (CSP): inline <script> moved to external assets/js/ltms-login-register.js
wp_enqueue_script( 'ltms-login-register', LTMS_ASSETS_URL . 'js/ltms-login-register.js', [], LTMS_VERSION, true );
?>
    <div class="ltms-auth-footer">
        <p>
            <?php esc_html_e( '¿Ya tienes cuenta?', 'ltms' ); ?>
            <?php
            $ltms_pages   = get_option( 'ltms_installed_pages', [] );
            $ltms_login_id = $ltms_pages['ltms-login'] ?? 0;
            $ltms_login_url = $ltms_login_id ? get_permalink( $ltms_login_id ) : home_url( '/login-vendedor/' );
            ?>
            <a href="<?php echo esc_url( $ltms_login_url ); ?>" class="ltms-auth-switch-link">
                <?php esc_html_e( 'Iniciar sesión', 'ltms' ); ?>
            </a>
        </p>
    </div>

</div><!-- .ltms-register-card -->
