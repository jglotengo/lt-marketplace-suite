<?php
/**
 * LTMS Frontend Checkout Municipality Field
 *
 * Convierte el input de texto `billing_city` del checkout WooCommerce en un select
 * con el catálogo DANE de municipios Colombia. El valor enviado es el código DANE
 * (5 dígitos); se guarda en order meta `_ltms_billing_municipality_code` para que
 * Order_Split lo use al calcular ReteICA territorialidad municipal (M-200).
 *
 * El nombre legible del municipio reemplaza `billing_city` después del submit para
 * que plugins de envío, emails y reportes sigan funcionando con texto humano.
 *
 * Solo activo en storefronts CO (`ltms_country = 'CO'`).
 *
 * Estrategia de enganche (M-205 / M-206):
 *   woocommerce_billing_fields NO funciona contra WooCommerce Checkout Manager
 *   (QuadLayers/WOOCCM), ya que ese plugin reconstruye los campos desde su propia
 *   configuración en BD ignorando el array $fields que recibe — independientemente
 *   de la prioridad del filtro. La solución correcta es `woocommerce_form_field`
 *   (alias `woocommerce_form_field_{type}`), que dispara sobre el HTML renderizado
 *   del campo justo antes de imprimirlo, después de que WOOCCM ya terminó. LTMS
 *   intercepta billing_city ahí y reemplaza el HTML completo por un <select> con
 *   las opciones del catálogo DANE.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/frontend
 * @version    1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class LTMS_Frontend_Checkout_Municipality_Field {

    public const META_KEY = '_ltms_billing_municipality_code';

    /**
     * Registra los hooks de WooCommerce. Solo en CO.
     *
     * @return void
     */
    public static function init(): void {
        if ( ! class_exists( 'LTMS_Core_Config' ) ) {
            return;
        }

        $country = LTMS_Core_Config::get_country();
        $instance = new self();

        // v2.9.215: Etiquetas de campos adaptadas al país (CO y MX).
        // WC por defecto muestra 'Región o provincia' para billing_state —
        // en Colombia se maneja 'Departamento' y en México 'Estado'.
        // Este filtro aplica para CO y MX (no solo CO como el resto de la clase).
        if ( $country === 'CO' || $country === 'MX' ) {
            add_filter( 'woocommerce_billing_fields',      [ __CLASS__, 'localize_state_field_label' ], 50, 1 );
            add_filter( 'woocommerce_shipping_fields',     [ __CLASS__, 'localize_state_field_label' ], 50, 1 );
        }

        // El resto de la lógica (catálogo DANE, validación, meta) solo aplica CO.
        if ( $country !== 'CO' ) {
            return;
        }

        // M-206: woocommerce_form_field dispara sobre el HTML renderizado, DESPUÉS
        // de que WOOCCM (QuadLayers) ya construyó el campo desde su propia BD.
        // El filtro recibe ($field_html, $key, $args, $value) y devuelve el HTML
        // final que se imprimirá. Al interceptar billing_city aquí, el <select>
        // de LTMS siempre gana — no importa qué haya hecho WOOCCM antes.
        //
        // woocommerce_billing_fields se mantiene como fallback para instalaciones
        // sin WOOCCM, pero ya NO es la ruta principal.
        add_filter( 'woocommerce_form_field',          [ $instance, 'override_city_field_html' ], 9999, 4 );
        add_filter( 'woocommerce_billing_fields',      [ $instance, 'modify_billing_city_field' ], 1000, 1 );

        add_action( 'woocommerce_checkout_process',           [ $instance, 'validate_municipality' ] );
        add_action( 'woocommerce_checkout_update_order_meta', [ $instance, 'save_municipality_meta' ], 10, 1 );
    }

    /**
     * v2.9.215: Localiza la etiqueta del campo 'state' (billing/shipping) según el país.
     *
     * - Colombia (CO): 'Departamento' (NO 'Región o provincia')
     * - México (MX): 'Estado'
     * - Otros: respeta el default de WC
     *
     * @param array $fields Campos de billing/shipping.
     * @return array Campos con etiqueta localizada.
     */
    public static function localize_state_field_label( array $fields ): array {
        $country = LTMS_Core_Config::get_country();
        $label   = null;

        if ( $country === 'CO' ) {
            $label = __( 'Departamento', 'ltms' );
        } elseif ( $country === 'MX' ) {
            $label = __( 'Estado', 'ltms' );
        }

        if ( $label === null ) {
            return $fields;
        }

        // Aplicar a billing_state y shipping_state
        foreach ( [ 'billing_state', 'shipping_state' ] as $key ) {
            if ( isset( $fields[ $key ] ) ) {
                $fields[ $key ]['label']       = $label;
                $fields[ $key ]['placeholder'] = $label;
                // Para CO y MX, billing_state debería ser un select (no texto libre)
                // WC lo maneja automáticamente cuando el país tiene estados definidos.
            }
        }

        // También ajustar el label de billing_city / shipping_city:
        // - CO: 'Municipio' (ya hecho por override_city_field_html, pero reforzamos aquí)
        // - MX: 'Municipio' / 'Delegación' (CDMX)
        $city_label = null;
        if ( $country === 'CO' ) {
            $city_label = __( 'Municipio', 'ltms' );
        } elseif ( $country === 'MX' ) {
            $city_label = __( 'Municipio / Alcaldía', 'ltms' );
        }
        if ( $city_label !== null ) {
            foreach ( [ 'billing_city', 'shipping_city' ] as $key ) {
                if ( isset( $fields[ $key ] ) ) {
                    $fields[ $key ]['label']       = $city_label;
                    $fields[ $key ]['placeholder'] = $city_label;
                }
            }
        }

        // Ajustar el label de billing_postcode / shipping_postcode:
        // - CO: 'Código postal' (WC default, pero a veces sale 'ZIP')
        // - MX: 'Código postal'
        $postcode_label = __( 'Código postal', 'ltms' );
        foreach ( [ 'billing_postcode', 'shipping_postcode' ] as $key ) {
            if ( isset( $fields[ $key ] ) ) {
                $fields[ $key ]['label']       = $postcode_label;
                $fields[ $key ]['placeholder'] = $postcode_label;
            }
        }

        return $fields;
    }

    /**
     * Determina si el catálogo DANE tiene datos reales cargados.
     *
     * @return bool
     */
    private function catalog_is_loaded(): bool {
        return count( LTMS_Business_Dane_Catalog::get_options( true ) ) > 1;
    }

    /**
     * Intercepta el HTML renderizado de billing_city y lo reemplaza por un <select>
     * con el catálogo DANE. Dispara vía woocommerce_form_field, DESPUÉS de WOOCCM.
     *
     * @param string $field_html HTML generado por woocommerce_form_field().
     * @param string $key        Clave del campo (ej. 'billing_city').
     * @param array  $args       Argumentos del campo.
     * @param mixed  $value      Valor actual del campo.
     * @return string
     */
    public function override_city_field_html( string $field_html, string $key, array $args, $value ): string {
        if ( $key !== 'billing_city' ) {
            return $field_html;
        }
        if ( ! $this->catalog_is_loaded() ) {
            return $field_html;
        }

        // Construir el <select> de municipios con el catálogo DANE.
        $options     = LTMS_Business_Dane_Catalog::get_options( true );
        $required    = ! empty( $args['required'] );
        $label       = $args['label'] ?? __( 'Municipio', 'ltms' );
        $label_class = ! empty( $args['label_class'] ) ? implode( ' ', (array) $args['label_class'] ) : '';
        $input_class = ! empty( $args['input_class'] ) ? implode( ' ', (array) $args['input_class'] ) : '';
        $wrap_class  = ! empty( $args['class'] ) ? implode( ' ', (array) $args['class'] ) : 'form-row-wide';
        $current_val = $value ?: '';

        $select_html  = '<p class="form-row ltms-municipality-row ' . esc_attr( $wrap_class ) . '"';
        $select_html .= ' id="billing_city_field">';
        $select_html .= '<label for="billing_city" class="' . esc_attr( $label_class ) . '">';
        $select_html .= esc_html( $label );
        if ( $required ) {
            $select_html .= ' <abbr class="required" title="' . esc_attr__( 'requerido', 'ltms' ) . '">*</abbr>';
        }
        $select_html .= '</label>';
        $select_html .= '<select name="billing_city" id="billing_city"';
        $select_html .= ' class="ltms-municipality-select select ' . esc_attr( $input_class ) . '"';
        if ( $required ) {
            $select_html .= ' required';
        }
        $select_html .= '>';
        foreach ( $options as $code => $label_opt ) {
            $select_html .= '<option value="' . esc_attr( $code ) . '"'
                . selected( $current_val, $code, false ) . '>'
                . esc_html( $label_opt ) . '</option>';
        }
        $select_html .= '</select>';
        $select_html .= '</p>';

        return $select_html;
    }

    /**
     * Fallback: reemplaza el campo billing_city con un select en el array de campos.
     * Funciona cuando WOOCCM NO está instalado. Cuando WOOCCM sí está activo,
     * override_city_field_html() toma el control en la capa de renderizado.
     *
     * @param array $fields Campos de facturación WooCommerce.
     * @return array
     */
    public function modify_billing_city_field( array $fields ): array {
        if ( ! $this->catalog_is_loaded() ) {
            return $fields;
        }

        $existing_class    = $fields['billing_city']['class']    ?? [ 'form-row-wide' ];
        $existing_priority = $fields['billing_city']['priority'] ?? 70;

        $fields['billing_city'] = [
            'type'        => 'select',
            'label'       => __( 'Municipio', 'ltms' ),
            'required'    => true,
            'class'       => array_unique( array_merge( $existing_class, [ 'ltms-billing-municipality' ] ) ),
            'priority'    => $existing_priority,
            'options'     => LTMS_Business_Dane_Catalog::get_options( true ),
            'default'     => '',
            'placeholder' => __( 'Selecciona tu municipio', 'ltms' ),
            'input_class' => [ 'ltms-municipality-select' ],
        ];

        return $fields;
    }

    /**
     * Valida que el value enviado sea un código DANE de 5 dígitos.
     *
     * M-204: si el catálogo DANE no está cargado, la validación estricta se omite
     * para no bloquear el checkout (degradación segura).
     *
     * @return void
     */
    public function validate_municipality(): void {
        $raw = $_POST['billing_city'] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( $raw === '' ) {
            wc_add_notice( __( 'Por favor selecciona tu municipio.', 'ltms' ), 'error' );
            return;
        }

        if ( ! $this->catalog_is_loaded() ) {
            return;
        }

        $code = sanitize_text_field( wp_unslash( (string) $raw ) );
        if ( ! preg_match( '/^\d{5}$/', $code ) ) {
            wc_add_notice( __( 'Selecciona un municipio válido del listado.', 'ltms' ), 'error' );
        }
    }

    /**
     * Guarda el código DANE en order meta y reemplaza billing_city por el nombre legible.
     *
     * @param int $order_id ID del pedido WooCommerce.
     * @return void
     */
    public function save_municipality_meta( int $order_id ): void {
        $raw = $_POST['billing_city'] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( $raw === '' ) {
            return;
        }
        $code = sanitize_text_field( wp_unslash( (string) $raw ) );
        if ( ! preg_match( '/^\d{5}$/', $code ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $order->update_meta_data( self::META_KEY, $code );

        $name = LTMS_Business_Dane_Catalog::get_name( $code );
        if ( $name !== '' ) {
            $order->set_billing_city( $name );
        }

        $order->save();
    }
}
