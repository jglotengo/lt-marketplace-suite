<?php
/**
 * LTMS — Configuración de bodega Deprisa por vendedor
 *
 * Añade una sección "Deprisa — Bodega de origen" al perfil de cada vendedor
 * en el admin de WordPress. Los datos guardados aquí son leídos por
 * LTMS_Deprisa_Order_Split::get_vendor_data() para usar como remitente
 * en lugar de la configuración global de la tienda.
 *
 * Hooks registrados (llamar desde ltms-deprisa-loader.php):
 *   add_action( 'show_user_profile',       [ LTMS_Deprisa_Vendor_Settings::class, 'render_fields' ] );
 *   add_action( 'edit_user_profile',       [ LTMS_Deprisa_Vendor_Settings::class, 'render_fields' ] );
 *   add_action( 'personal_options_update', [ LTMS_Deprisa_Vendor_Settings::class, 'save_fields' ] );
 *   add_action( 'edit_user_profile_update',[ LTMS_Deprisa_Vendor_Settings::class, 'save_fields' ] );
 *
 * @package LTMS
 * @since   1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LTMS_Deprisa_Vendor_Settings {

    /** Prefijo de los user_meta para datos Deprisa del vendedor. */
    const META_PREFIX = '_ltms_vendor_deprisa_';

    /** Definición de campos del formulario. */
    private static function fields(): array {
        return [
            'cliente_remitente'   => [
                'label'       => 'Código Cliente Alertran',
                'type'        => 'text',
                'placeholder' => '00000011',
                'maxlength'   => 8,
                'desc'        => '8 dígitos. Asignado por Deprisa. Si está vacío se usa el de la tienda.',
            ],
            'centro_remitente'    => [
                'label'       => 'Centro Remitente',
                'type'        => 'text',
                'placeholder' => '01',
                'maxlength'   => 4,
                'desc'        => 'Código del centro cliente (generalmente 01).',
            ],
            'nombre_remitente'    => [
                'label'       => 'Nombre del Remitente',
                'type'        => 'text',
                'placeholder' => 'Bodega Norte',
                'desc'        => 'Nombre que aparecerá como remitente en la guía.',
            ],
            'direccion_remitente' => [
                'label'       => 'Dirección de la Bodega',
                'type'        => 'text',
                'placeholder' => 'Cra 7 # 100-23',
                'desc'        => '',
            ],
            'ciudad_remitente'    => [
                'label'       => 'Ciudad',
                'type'        => 'text',
                'placeholder' => 'BOGOTA',
                'desc'        => 'En mayúsculas, tal como aparece en Alertran.',
            ],
            'cp_remitente'        => [
                'label'       => 'Código Postal',
                'type'        => 'text',
                'placeholder' => '110911',
                'maxlength'   => 7,
                'desc'        => '',
            ],
            'tipo_doc_remitente'  => [
                'label'   => 'Tipo de Documento',
                'type'    => 'select',
                'options' => [ 'NIT' => 'NIT', 'CC' => 'Cédula', 'CE' => 'Cédula Extranjería', 'PASS' => 'Pasaporte' ],
                'desc'    => '',
            ],
            'nit_remitente'       => [
                'label'       => 'NIT / Documento',
                'type'        => 'text',
                'placeholder' => '900123456',
                'desc'        => '',
            ],
            'contacto_remitente'  => [
                'label'       => 'Persona de Contacto',
                'type'        => 'text',
                'placeholder' => 'Juan García',
                'desc'        => '',
            ],
            'telefono_remitente'  => [
                'label'       => 'Teléfono de Contacto',
                'type'        => 'text',
                'placeholder' => '6012345678',
                'desc'        => '',
            ],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Render                                                               */
    /* ------------------------------------------------------------------ */

    public static function render_fields( WP_User $user ): void {
        // Mostrar solo a admins o al propio usuario vendedor
        if ( ! current_user_can( 'manage_woocommerce' ) && get_current_user_id() !== $user->ID ) {
            return;
        }

        if ( ! get_option( 'ltms_deprisa_enabled' ) ) {
            return;
        }

        $prefix = self::META_PREFIX;
        $fields = self::fields();
        ?>
        <h2>🚚 Deprisa — Bodega de origen</h2>
        <p style="color:#555; margin-bottom:16px;">
            Si los campos están vacíos, se usará la configuración global de la tienda.
            Completa estos datos solo si este vendedor tiene una bodega propia con código
            Alertran diferente al de la tienda.
        </p>
        <table class="form-table">
        <?php foreach ( $fields as $key => $field ) :
            $meta_key = $prefix . $key;
            $value    = get_user_meta( $user->ID, $meta_key, true );
            ?>
            <tr>
                <th><label for="<?php echo esc_attr( $meta_key ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
                <td>
                <?php if ( $field['type'] === 'select' ) : ?>
                    <select name="<?php echo esc_attr( $meta_key ); ?>" id="<?php echo esc_attr( $meta_key ); ?>">
                        <?php foreach ( $field['options'] as $opt_val => $opt_label ) : ?>
                            <option value="<?php echo esc_attr( $opt_val ); ?>" <?php selected( $value, $opt_val ); ?>>
                                <?php echo esc_html( $opt_label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else : ?>
                    <input
                        type="<?php echo esc_attr( $field['type'] ); ?>"
                        name="<?php echo esc_attr( $meta_key ); ?>"
                        id="<?php echo esc_attr( $meta_key ); ?>"
                        value="<?php echo esc_attr( $value ); ?>"
                        class="regular-text"
                        <?php if ( ! empty( $field['placeholder'] ) ) echo 'placeholder="' . esc_attr( $field['placeholder'] ) . '"'; ?>
                        <?php if ( ! empty( $field['maxlength'] ) ) echo 'maxlength="' . (int) $field['maxlength'] . '"'; ?>
                    >
                <?php endif; ?>
                <?php if ( ! empty( $field['desc'] ) ) : ?>
                    <p class="description"><?php echo esc_html( $field['desc'] ); ?></p>
                <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </table>
        <?php
        wp_nonce_field( 'ltms_deprisa_vendor_settings_' . $user->ID, 'ltms_deprisa_vendor_nonce' );
    }

    /* ------------------------------------------------------------------ */
    /* Guardar                                                              */
    /* ------------------------------------------------------------------ */

    public static function save_fields( int $user_id ): void {
        if ( ! isset( $_POST['ltms_deprisa_vendor_nonce'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce(
            sanitize_text_field( wp_unslash( $_POST['ltms_deprisa_vendor_nonce'] ) ),
            'ltms_deprisa_vendor_settings_' . $user_id
        ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_woocommerce' ) && get_current_user_id() !== $user_id ) {
            return;
        }

        $prefix = self::META_PREFIX;
        $fields = self::fields();

        foreach ( $fields as $key => $field ) {
            $meta_key = $prefix . $key;
            $raw      = $_POST[ $meta_key ] ?? '';

            if ( $field['type'] === 'select' ) {
                $allowed = array_keys( $field['options'] );
                $value   = in_array( $raw, $allowed, true ) ? $raw : '';
            } else {
                $value = sanitize_text_field( wp_unslash( $raw ) );
                // Ciudad siempre en mayúsculas
                if ( $key === 'ciudad_remitente' ) {
                    $value = strtoupper( $value );
                }
            }

            if ( $value !== '' ) {
                update_user_meta( $user_id, $meta_key, $value );
            } else {
                delete_user_meta( $user_id, $meta_key );
            }
        }
    }
}
