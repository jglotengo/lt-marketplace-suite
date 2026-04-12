<?php
/**
 * Partial: Formulario de registro del vendedor
 *
 * @package    LTMS\Frontend\Views
 * @version    1.5.0
 */

defined( 'ABSPATH' ) || exit;

$country = LTMS_Core_Config::get_country();
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

    <h2 class="ltms-auth-title"><?php esc_html_e( 'Crear Cuenta de Vendedor', 'ltms' ); ?></h2>
    <p class="ltms-auth-subtitle"><?php esc_html_e( 'Únete a la plataforma y empieza a vender.', 'ltms' ); ?></p>

    <div id="ltms-register-notice" class="ltms-notice" style="display:none;" role="alert"></div>

    <!-- Pasos del wizard -->
    <div class="ltms-steps" aria-label="<?php esc_attr_e( 'Pasos del registro', 'ltms' ); ?>">
        <div class="ltms-step active" data-step="1"><?php esc_html_e( 'Datos Personales', 'ltms' ); ?></div>
        <div class="ltms-step" data-step="2"><?php esc_html_e( 'Tu Tienda', 'ltms' ); ?></div>
        <div class="ltms-step" data-step="3"><?php esc_html_e( 'Seguridad', 'ltms' ); ?></div>
    </div>

    <form id="ltms-register-form" class="ltms-auth-form" novalidate>
        <?php wp_nonce_field( 'ltms_vendor_register', 'ltms_register_nonce' ); ?>

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

            <div class="ltms-form-group">
                <label for="ltms-reg-phone"><?php esc_html_e( 'Teléfono', 'ltms' ); ?></label>
                <input type="tel" id="ltms-reg-phone" name="phone" class="ltms-form-control" placeholder="<?php echo 'CO' === $country ? '+57 300 000 0000' : '+52 55 0000 0000'; ?>">
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
            <div class="ltms-form-group">
                <label for="ltms-reg-store-name"><?php esc_html_e( 'Nombre de tu Tienda *', 'ltms' ); ?></label>
                <input type="text" id="ltms-reg-store-name" name="store_name" class="ltms-form-control" required placeholder="<?php esc_attr_e( 'Mi Tienda Genial', 'ltms' ); ?>">
                <small class="ltms-field-hint"><?php esc_html_e( 'Este nombre será visible para los compradores.', 'ltms' ); ?></small>
            </div>

            <div class="ltms-form-group">
                <label for="ltms-reg-store-description"><?php esc_html_e( 'Descripción de tu Tienda', 'ltms' ); ?></label>
                <textarea id="ltms-reg-store-description" name="store_description" class="ltms-form-control" rows="3" placeholder="<?php esc_attr_e( 'Vendo productos de...', 'ltms' ); ?>"></textarea>
            </div>

            <div class="ltms-form-group">
                <label for="ltms-reg-referral-code"><?php esc_html_e( 'Código de Referido', 'ltms' ); ?></label>
                <input
                    type="text"
                    id="ltms-reg-referral-code"
                    name="referral_code"
                    class="ltms-form-control"
                    placeholder="<?php esc_attr_e( 'Opcional', 'ltms' ); ?>"
                    value="<?php echo esc_attr( $_GET['ref'] ?? '' ); ?>"
                >
                <small class="ltms-field-hint"><?php esc_html_e( 'Si alguien te invitó, ingresa su código.', 'ltms' ); ?></small>
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
        </div>

    </form>

    <div class="ltms-auth-footer">
        <p>
            <?php esc_html_e( '¿Ya tienes cuenta?', 'ltms' ); ?>
            <a href="<?php echo esc_url( home_url( '/ltms-login/' ) ); ?>" class="ltms-auth-switch-link">
                <?php esc_html_e( 'Iniciar sesión', 'ltms' ); ?>
            </a>
        </p>
    </div>

</div><!-- .ltms-register-card -->
