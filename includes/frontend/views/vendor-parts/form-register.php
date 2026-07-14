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
                <label><?php esc_html_e( '¿Qué tipo de productos o servicios ofreces? *', 'ltms' ); ?></label>
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
                               style="position:absolute;opacity:0;pointer-events:none;" required>
                    </label>
                    <?php endforeach; ?>
                </div>
                <small class="ltms-field-hint" style="margin-top:6px;display:block;">
                    <?php esc_html_e( 'Puedes ofrecer más de un tipo — elige el principal. Podrás ajustarlo desde tu panel luego.', 'ltms' ); ?>
                </small>
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

        <!-- Paso 3: Contraseña y TyC -->
        <div class="ltms-wizard-page" data-page="3" style="display:none;">
            <div class="ltms-form-group">
                <label for="ltms-reg-password"><?php esc_html_e( 'Contraseña *', 'ltms' ); ?></label>
                <div class="ltms-input-group">
                    <input type="password" id="ltms-reg-password" name="password" class="ltms-form-control" required minlength="8" placeholder="••••••••">
                    <button type="button" class="ltms-toggle-password" data-target="ltms-reg-password" aria-label="<?php esc_attr_e( 'Mostrar contraseña', 'ltms' ); ?>">&#128065;</button>
                </div>
                <div class="ltms-password-strength" id="ltms-password-strength">
                    <div class="ltms-strength-bar"></div>
                    <span class="ltms-strength-label"></span>
                </div>
            </div>

            <div class="ltms-form-group">
                <label for="ltms-reg-password-confirm"><?php esc_html_e( 'Confirmar Contraseña *', 'ltms' ); ?></label>
                <div class="ltms-input-group">
                    <input type="password" id="ltms-reg-password-confirm" name="password_confirm" class="ltms-form-control" required placeholder="••••••••">
                    <button type="button" class="ltms-toggle-password" data-target="ltms-reg-password-confirm" aria-label="<?php esc_attr_e( 'Mostrar contraseña', 'ltms' ); ?>">&#128065;</button>
                </div>
            </div>

            <div class="ltms-form-group">
                <label class="ltms-checkbox-label">
                    <input type="checkbox" name="accept_terms" value="1" required>
                    <?php
                    $terms_url   = get_permalink( get_option( 'ltms_terms_page_id' ) ) ?: '#';
                    $privacy_url = get_permalink( get_option( 'ltms_privacy_page_id' ) ) ?: '#';
                    printf(
                        esc_html__( 'Acepto los %1$sTérminos y Condiciones%2$s y la %3$sPolítica de Privacidad%4$s *', 'ltms' ),
                        '<a href="' . esc_url( $terms_url ) . '" target="_blank">',
                        '</a>',
                        '<a href="' . esc_url( $privacy_url ) . '" target="_blank">',
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
                    <?php esc_html_e( 'Autorizo el tratamiento de mis datos para cumplimiento de la Ley SAGRILAFT (Ley 526 de 1999) *', 'ltms' ); ?>
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
            <script>
            window.onloadTurnstileCallback = function() {
                turnstile.render('.cf-turnstile', {
                    callback: function(token) {
                        document.getElementById('ltms-turnstile-token').value = token;
                    }
                });
            };
            </script>
            <?php endif; ?>
        </div>

    
<script>
(function() {
    var sel    = document.getElementById('ltms-reg-vendor-country');
    var phone  = document.getElementById('ltms-reg-phone');
    var docSel = document.getElementById('ltms-reg-document-type');

    var docOpts = {
        CO: [
            {v:'', l:'Seleccionar...'},
            {v:'CC', l:'Cédula de Ciudadanía'},
            {v:'CE', l:'Cédula de Extranjería'},
            {v:'NIT', l:'NIT'},
            {v:'PAS', l:'Pasaporte'}
        ],
        MX: [
            {v:'', l:'Seleccionar...'},
            {v:'RFC', l:'RFC'},
            {v:'CURP', l:'CURP'},
            {v:'PAS', l:'Pasaporte'}
        ]
    };

    var municipioWrap = document.getElementById('ltms-municipality-wrap');
    var municipioSel  = document.getElementById('ltms-reg-municipality');

    function updateCountry(country) {
        if (phone) phone.placeholder = country === 'MX' ? '+52 55 0000 0000' : '+57 300 000 0000';
        if (docSel) {
            var opts = docOpts[country] || docOpts.CO;
            docSel.innerHTML = opts.map(function(o){
                return '<option value="'+o.v+'">'+o.l+'</option>';
            }).join('');
        }
        // M-AUDIT-REG-06: ocultar campo municipio DANE para vendedores MX (solo aplica a CO).
        // El campo se renderiza en PHP si el servidor es CO, así que siempre estará en el
        // DOM con required. Si el vendedor elige MX en el select, lo ocultamos y le quitamos
        // required para que no bloquee el wizard ni el submit del registro.
        if (municipioWrap) {
            var isCO = country === 'CO';
            municipioWrap.style.display = isCO ? '' : 'none';
            if (municipioSel) municipioSel.required = isCO;
        }
    }

    if (sel) {
        sel.addEventListener('change', function(){ updateCountry(this.value); });
        updateCountry(sel.value);
    }
})();
</script>
</form>

    <?php if ( class_exists( 'LTMS_Google_OAuth' ) && LTMS_Google_OAuth::is_configured() ) : ?>
    <div class="ltms-oauth-divider">
        <span><?php esc_html_e( 'o regístrate con', 'ltms' ); ?></span>
    </div>
    <?php echo LTMS_Google_OAuth::render_google_button(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
    <?php endif; ?>
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
