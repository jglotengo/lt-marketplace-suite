<?php
/**
 * Settings Section: General
 * Fully inline — no external function dependencies (SiteGround double-load safe)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$fields = [
    'ltms_platform_name'    => [ 'label' => 'Nombre de la Plataforma', 'type' => 'text',   'default' => 'Lo Tengo Colombia', 'desc' => 'Aparece en emails y en el panel del vendedor.' ],
    'ltms_og_site_name'     => [ 'label' => 'Nombre SEO del Sitio (og:site_name)', 'type' => 'text', 'default' => 'Lo Tengo Colombia', 'desc' => 'Usado en Open Graph, Twitter Cards y Schema.org. Ej: Lo Tengo Colombia' ],
    'ltms_country'          => [ 'label' => 'País de Operación',        'type' => 'select', 'default' => 'CO', 'options' => [ 'CO' => 'Colombia', 'MX' => 'México', 'PE' => 'Perú', 'CL' => 'Chile' ] ],
    'ltms_environment'      => [ 'label' => 'Entorno',                  'type' => 'select', 'default' => 'sandbox', 'options' => [ 'sandbox' => 'Sandbox (Pruebas)', 'production' => 'Producción' ] ],
    'ltms_currency'         => [ 'label' => 'Moneda Principal',         'type' => 'select', 'default' => 'COP', 'options' => [ 'COP' => 'COP - Peso Colombiano', 'MXN' => 'MXN - Peso Mexicano', 'USD' => 'USD - Dólar' ] ],
    'ltms_platform_email'   => [ 'label' => 'Email de la Plataforma',  'type' => 'email',  'default' => get_option('admin_email'), 'desc' => 'Usado en notificaciones del sistema.' ],
    'ltms_support_phone'    => [ 'label' => 'Teléfono de Soporte',     'type' => 'text',   'default' => '', 'desc' => 'Visible para vendedores en el panel.' ],
    'ltms_terms_url'        => [ 'label' => 'URL Términos y Condiciones', 'type' => 'url', 'default' => home_url('/terminos-y-condiciones/') ],
    'ltms_privacy_url'      => [ 'label' => 'URL Política de Privacidad', 'type' => 'url', 'default' => home_url('/politica-de-privacidad/') ],
    'ltms_devoluciones_url' => [ 'label' => 'URL Política de Devoluciones', 'type' => 'url', 'default' => home_url('/politica-de-devoluciones/'), 'desc' => 'Visible en footer y checkout.' ],
    // FIX PROD-01: Auto-publicación de productos de vendedores
    'ltms_vendor_product_auto_publish' => [
        'label'   => 'Auto-publicar productos de vendedores',
        'type'    => 'checkbox',
        'default' => 'no',
        'desc'    => 'Si está activo, los productos creados por vendedores se publican directamente (sin revisión). Si está inactivo, quedan en "Pendiente de revisión" hasta que el admin los apruebe.',
    ],
];

// Campos bancarios de la plataforma (mostrados en el modal de depósito del vendedor)
$bank_fields = [
    'ltms_bank_name'    => [ 'label' => 'Banco receptor de depósitos',       'type' => 'text', 'default' => 'Bancolombia', 'desc' => 'Ej: Bancolombia, Davivienda, Nequi...' ],
    'ltms_bank_account' => [ 'label' => 'Número de cuenta bancaria',         'type' => 'text', 'default' => '', 'desc' => 'Número de cuenta de ahorros o corriente de Lo Tengo Colombia S.A.S.' ],
    'ltms_company_nit'  => [ 'label' => 'NIT de la empresa',                 'type' => 'text', 'default' => '', 'desc' => 'Ej: 900.123.456-7. Aparece en el modal de depósito del vendedor.' ],
];
?>
<div class="ltms-settings-section">
    <h2 style="margin-top:24px;">⚙️ Configuración General</h2>
    <table class="form-table" role="presentation">
        <tbody>
        <?php foreach ( $fields as $key => $field ) :
            $value = get_option( $key, $field['default'] ?? '' );
        ?>
        <tr>
            <th scope="row"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
            <td>
            <?php if ( $field['type'] === 'select' ) : ?>
                <select id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" class="regular-text">
                    <?php foreach ( $field['options'] as $k => $v ) : ?>
                        <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $value, $k ); ?>><?php echo esc_html( $v ); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php elseif ( $field['type'] === 'checkbox' ) : ?>
                <label><input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="yes" <?php checked( $value, 'yes' ); ?>> <?php echo esc_html( $field['desc'] ?? '' ); ?></label>
            <?php else : ?>
                <input type="<?php echo esc_attr( $field['type'] ); ?>"
                       id="<?php echo esc_attr( $key ); ?>"
                       name="<?php echo esc_attr( $key ); ?>"
                       value="<?php echo esc_attr( $value ); ?>"
                       class="regular-text">
                <?php if ( ! empty( $field['desc'] ) ) : ?><p class="description"><?php echo esc_html( $field['desc'] ); ?></p><?php endif; ?>
            <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2 style="margin-top:32px;">🏦 Datos Bancarios de la Plataforma</h2>
    <p class="description" style="margin-bottom:16px;">
        Estos datos aparecen en el modal de "Depositar" del panel del vendedor para que sepa a dónde hacer la transferencia.
    </p>
    <table class="form-table" role="presentation">
        <tbody>
        <?php foreach ( $bank_fields as $key => $field ) :
            $value = get_option( $key, $field['default'] ?? '' );
        ?>
        <tr>
            <th scope="row"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
            <td>
                <input type="text"
                       id="<?php echo esc_attr( $key ); ?>"
                       name="<?php echo esc_attr( $key ); ?>"
                       value="<?php echo esc_attr( $value ); ?>"
                       class="regular-text">
                <?php if ( ! empty( $field['desc'] ) ) : ?><p class="description"><?php echo esc_html( $field['desc'] ); ?></p><?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
