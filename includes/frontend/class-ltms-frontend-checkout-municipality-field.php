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
 * @version    1.0.0
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
     * Reemplaza el input billing_city con un select del catálogo DANE.
     * Conserva la key `billing_city` para no romper plugins de envío/correo.
     *
     * @param array $fields Campos de facturación WooCommerce.
     * @return array
     */
    public function modify_billing_city_field( array $fields ): array {
        $options = LTMS_Business_Dane_Catalog::get_options( true );
        if ( count( $options ) <= 1 ) {
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
            'options'     => $options,
            'default'     => '',
            'placeholder' => __( 'Selecciona tu municipio', 'ltms' ),
            'input_class' => [ 'ltms-municipality-select' ],
        ];

        return $fields;
    }

    /**
     * Valida que el value enviado sea un código DANE de 5 dígitos.
     *
     * @return void
     */
    public function validate_municipality(): void {
        $raw = $_POST['billing_city'] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( $raw === '' ) {
            wc_add_notice( __( 'Por favor selecciona tu municipio.', 'ltms' ), 'error' );
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
