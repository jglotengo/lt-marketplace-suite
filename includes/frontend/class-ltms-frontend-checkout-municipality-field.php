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
 * @package    LTMS
 * @subpackage LTMS/includes/frontend
 * @version    1.1.0
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
        if ( ! class_exists( 'LTMS_Core_Config' ) || LTMS_Core_Config::get_country() !== 'CO' ) {
            return;
        }

        $instance = new self();

        add_filter( 'woocommerce_billing_fields',             [ $instance, 'modify_billing_city_field' ], 20, 1 );
        add_action( 'woocommerce_checkout_process',           [ $instance, 'validate_municipality' ] );
        add_action( 'woocommerce_checkout_update_order_meta', [ $instance, 'save_municipality_meta' ], 10, 1 );
    }

    /**
     * Determina si el catálogo DANE tiene datos reales cargados (más que solo
     * el placeholder "— Selecciona tu municipio —"). Se usa tanto para decidir
     * si el campo se convierte en <select> como para decidir si la validación
     * estricta de código de 5 dígitos aplica — ambas piezas deben degradarse
     * juntas (M-204, fix de incidente: bloqueaba el 100% del checkout CO).
     *
     * @return bool
     */
    private function catalog_is_loaded(): bool {
        return count( LTMS_Business_Dane_Catalog::get_options( true ) ) > 1;
    }

    /**
     * Reemplaza el input billing_city con un select del catálogo DANE.
     * Conserva la key `billing_city` para no romper plugins de envío/correo.
     *
     * @param array $fields Campos de facturación WooCommerce.
     * @return array
     */
    public function modify_billing_city_field( array $fields ): array {
        if ( ! $this->catalog_is_loaded() ) {
            // Sin catálogo cargado, deja el campo original (degradación segura).
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
     * M-204: si el catálogo DANE no está cargado en bkr_lt_co_dane_municipalities,
     * modify_billing_city_field() deja billing_city como texto libre — un campo
     * de texto libre nunca puede producir un código de 5 dígitos exacto, así que
     * exigirlo aquí bloqueaba el 100% de los checkouts CO sin excepción. La
     * validación estricta solo aplica cuando el catálogo realmente ofrece un
     * select con códigos reales para elegir.
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
            // Degradación segura: catálogo vacío → campo de texto libre → no se
            // puede exigir formato de código DANE. Se acepta el texto tal cual,
            // igual que el comportamiento nativo de WooCommerce sin este módulo.
            return;
        }

        $code = sanitize_text_field( wp_unslash( (string) $raw ) );
        if ( ! preg_match( '/^\d{5}$/', $code ) ) {
            wc_add_notice( __( 'Selecciona un municipio válido del listado.', 'ltms' ), 'error' );
        }
    }

    /**
     * Guarda el código DANE en order meta y reemplaza billing_city por el nombre legible.
     * Si el catálogo está degradado (texto libre), no hay código DANE que guardar —
     * el pedido se crea igual, solo sin el meta de territorialidad ReteICA hasta que
     * el catálogo se pueble.
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
