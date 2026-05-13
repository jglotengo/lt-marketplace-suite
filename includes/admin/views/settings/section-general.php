<?php
/**
 * Settings Section: General
 * Fully inline — no external function dependencies (SiteGround double-load safe)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$fields = [
    'ltms_platform_name'    => [ 'label' => 'Nombre de la Plataforma', 'type' => 'text',   'default' => 'Lo-Tengo Marketplace', 'desc' => 'Aparece en emails y en el panel del vendedor.' ],
    'ltms_country'          => [ 'label' => 'País de Operación',        'type' => 'select', 'default' => 'CO', 'options' => [ 'CO' => 'Colombia', 'MX' => 'México', 'PE' => 'Perú', 'CL' => 'Chile' ] ],
    'ltms_environment'      => [ 'label' => 'Entorno',                  'type' => 'select', 'default' => 'sandbox', 'options' => [ 'sandbox' => 'Sandbox (Pruebas)', 'production' => 'Producción' ] ],
    'ltms_currency'         => [ 'label' => 'Moneda Principal',         'type' => 'select', 'default' => 'COP', 'options' => [ 'COP' => 'COP - Peso Colombiano', 'MXN' => 'MXN - Peso Mexicano', 'USD' => 'USD - Dólar' ] ],
    'ltms_platform_email'   => [ 'label' => 'Email de la Plataforma',  'type' => 'email',  'default' => get_option('admin_email'), 'desc' => 'Usado en notificaciones del sistema.' ],
    'ltms_support_phone'    => [ 'label' => 'Teléfono de Soporte',     'type' => 'text',   'default' => '', 'desc' => 'Visible para vendedores en el panel.' ],
    'ltms_terms_url'        => [ 'label' => 'URL Términos y Condiciones', 'type' => 'url', 'default' => home_url('/terminos-y-condiciones/') ],
    'ltms_privacy_url'      => [ 'label' => 'URL Política de Privacidad', 'type' => 'url', 'default' => home_url('/politica-de-privacidad/') ],
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
                <label><input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( $value, '1' ); ?>> <?php echo esc_html( $field['desc'] ?? '' ); ?></label>
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
</div>
