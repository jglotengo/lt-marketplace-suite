<?php
/**
 * LTMS Frontend Checkout Field Labels
 *
 * v2.9.216: Overridea los labels de los campos del checkout DEPUÉS de que
 * WOOCCM (WooCommerce Checkout Manager por QuadLayers) los reconstruye desde
 * su propia BD. WOOCCM ignora el array $fields que reciben los filtros
 * woocommerce_billing_fields / woocommerce_shipping_fields, pero el filtro
 * woocommerce_form_field dispara sobre el HTML renderado, DESPUÉS de WOOCCM.
 *
 * Labels corregidos:
 *   - billing_state / shipping_state: 'Departamento' (CO) / 'Estado' (MX)
 *   - billing_city / shipping_city: 'Municipio' (CO) / 'Municipio / Alcaldía' (MX)
 *   - billing_postcode / shipping_postcode: 'Código postal'
 *   - billing_country / shipping_country: 'País' (no 'País / Región')
 *   - billing_address_1: 'Dirección' (no 'Dirección de la calle')
 *   - billing_address_2: 'Apartamento, suite, etc.' (no 'Apartamento, habitación, escalera, etc.')
 *
 * También oculta campos redundantes:
 *   - billing_phone en step 2 (ya está en step 1 como 'Teléfono / WhatsApp')
 *   - billing_email en step 2 (ya está en step 1)
 *
 * @package    LTMS
 * @subpackage LTMS/includes/frontend
 * @version    2.9.216
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class LTMS_Frontend_Checkout_Field_Labels {

    /**
     * Registra los hooks. Solo en CO y MX.
     *
     * @return void
     */
    public static function init(): void {
        if ( ! class_exists( 'LTMS_Core_Config' ) ) {
            return;
        }
        $country = LTMS_Core_Config::get_country();
        if ( $country !== 'CO' && $country !== 'MX' ) {
            return;
        }

        // woocommerce_form_field dispara sobre el HTML renderado, DESPUÉS de
        // WOOCCM. Prioridad 9999 = último en ejecutarse.
        add_filter( 'woocommerce_form_field', [ __CLASS__, 'override_field_labels_html' ], 9999, 4 );
    }

    /**
     * Overridea el HTML de los campos del checkout con labels localizados.
     *
     * @param string $field_html HTML generado por woocommerce_form_field().
     * @param string $key        Clave del campo (ej. 'billing_state').
     * @param array  $args       Argumentos del campo.
     * @param mixed  $value      Valor actual del campo.
     * @return string HTML modificado.
     */
    public static function override_field_labels_html( string $field_html, string $key, array $args, $value ): string {
        $country = LTMS_Core_Config::get_country();

        // Mapeo de claves a labels localizados.
        $label_map = self::get_label_map( $country );

        // Si la clave no está en el mapa, devolver sin cambios.
        if ( ! isset( $label_map[ $key ] ) ) {
            return $field_html;
        }

        $new_label = $label_map[ $key ];

        // Ocultar campos redundantes:
        // - billing_phone en step 2: ya está en step 1 como 'Teléfono / WhatsApp'
        //   (WOOCCM a veces lo duplica)
        // - billing_email en step 2: ya está en step 1
        // Pero NO ocultar si el campo es shipping_phone o shipping_email.
        $hide_keys = [ 'billing_phone', 'billing_email' ];
        if ( in_array( $key, $hide_keys, true ) ) {
            // Verificar si este campo ya fue renderizado en step 1 (contact info).
            // Heuristic: si el field_html contiene 'Teléfono / WhatsApp' o
            // 'Correo electrónico' como label, es el de step 1 — mantenerlo.
            // Si solo dice 'Teléfono' o 'Email', es el duplicado de step 2 — ocultarlo.
            $is_step1_phone = ( $key === 'billing_phone' && strpos( $field_html, 'WhatsApp' ) !== false );
            $is_step1_email = ( $key === 'billing_email' && strpos( $field_html, 'Correo electrónico' ) !== false );
            if ( ! $is_step1_phone && ! $is_step1_email ) {
                // Ocultar el campo duplicado con display:none.
                $field_html = preg_replace(
                    '/<p class="form-row/',
                    '<p class="form-row" style="display:none !important;"',
                    $field_html,
                    1
                );
                return $field_html;
            }
            // Si es el campo de step 1, mantenerlo pero asegurar el label correcto.
            if ( $is_step1_phone ) {
                $new_label = __( 'Teléfono / WhatsApp', 'ltms' );
            } else {
                $new_label = __( 'Correo electrónico', 'ltms' );
            }
        }

        // Reemplazar el label en el HTML renderado.
        // WC/WOOCCM renderiza: <label for="...">Label Original <abbr>*</abbr></label>
        // o: <label for="...">Label Original</label>
        // Vamos a reemplazar el texto del label preservando el <abbr> si existe.

        // Patrón: <label ...>...texto...</label>
        // Capturamos el contenido del label y lo reemplazamos.
        $pattern = '/(<label[^>]*for="' . preg_quote( $key, '/' ) . '"[^>]*>)(.*?)(<\/label>)/is';
        if ( preg_match( $pattern, $field_html, $m ) ) {
            $label_open = $m[1];
            $label_content = $m[2];
            $label_close = $m[3];

            // Preservar el <abbr class="required"> si existe.
            $abbr = '';
            if ( preg_match( '/<abbr[^>]*>.*?<\/abbr>/is', $label_content, $abbr_match ) ) {
                $abbr = ' ' . $abbr_match[0];
            }

            // Construir nuevo label.
            $new_label_html = $label_open . esc_html( $new_label ) . $abbr . $label_close;
            $field_html = preg_replace( $pattern, $new_label_html, $field_html, 1 );
        }

        // Para billing_country (CO): auto-setear a Colombia y hacer readonly.
        // El usuario no debería poder cambiar el país si la tienda es CO-only.
        if ( $key === 'billing_country' || $key === 'shipping_country' ) {
            if ( $country === 'CO' ) {
                // Si es un <select>, pre-seleccionar CO.
                $field_html = preg_replace(
                    '/<option value="CO"/',
                    '<option value="CO" selected',
                    $field_html
                );
            } elseif ( $country === 'MX' ) {
                $field_html = preg_replace(
                    '/<option value="MX"/',
                    '<option value="MX" selected',
                    $field_html
                );
            }
        }

        return $field_html;
    }

    /**
     * Devuelve el mapeo de claves a labels localizados según el país.
     *
     * @param string $country Código de país (CO, MX).
     * @return array Mapeo clave → label.
     */
    private static function get_label_map( string $country ): array {
        if ( $country === 'CO' ) {
            return [
                'billing_state'    => __( 'Departamento', 'ltms' ),
                'shipping_state'   => __( 'Departamento', 'ltms' ),
                'billing_city'     => __( 'Municipio', 'ltms' ),
                'shipping_city'    => __( 'Municipio', 'ltms' ),
                'billing_postcode' => __( 'Código postal', 'ltms' ),
                'shipping_postcode'=> __( 'Código postal', 'ltms' ),
                'billing_country'  => __( 'País', 'ltms' ),
                'shipping_country' => __( 'País', 'ltms' ),
                'billing_address_1'=> __( 'Dirección', 'ltms' ),
                'shipping_address_1'=> __( 'Dirección', 'ltms' ),
                'billing_address_2'=> __( 'Apartamento, suite, etc. (opcional)', 'ltms' ),
                'shipping_address_2'=> __( 'Apartamento, suite, etc. (opcional)', 'ltms' ),
            ];
        }
        if ( $country === 'MX' ) {
            return [
                'billing_state'    => __( 'Estado', 'ltms' ),
                'shipping_state'   => __( 'Estado', 'ltms' ),
                'billing_city'     => __( 'Municipio / Alcaldía', 'ltms' ),
                'shipping_city'    => __( 'Municipio / Alcaldía', 'ltms' ),
                'billing_postcode' => __( 'Código postal', 'ltms' ),
                'shipping_postcode'=> __( 'Código postal', 'ltms' ),
                'billing_country'  => __( 'País', 'ltms' ),
                'shipping_country' => __( 'País', 'ltms' ),
                'billing_address_1'=> __( 'Dirección', 'ltms' ),
                'shipping_address_1'=> __( 'Dirección', 'ltms' ),
                'billing_address_2'=> __( 'Apartamento, suite, etc. (opcional)', 'ltms' ),
                'shipping_address_2'=> __( 'Apartamento, suite, etc. (opcional)', 'ltms' ),
            ];
        }
        return [];
    }
}
