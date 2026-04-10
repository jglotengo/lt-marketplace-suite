<?php
/**
 * LTMS Shipping Mode — maneja modos de envío (quoted, flat, free).
 *
 * @package LTMS\Business
 */
class LTMS_Shipping_Mode {

    /**
     * Calcula el costo de envío según el modo configurado.
     *
     * @param array $package Datos del paquete WooCommerce.
     * @return float|null Costo de envío, o null si no aplica.
     */
    public static function calculate_shipping( array $package ): ?float {
        try {
            $mode = class_exists( 'LTMS_Core_Config' )
                ? LTMS_Core_Config::get( 'ltms_shipping_mode', 'flat' )
                : 'flat';

            if ( 'quoted' === $mode ) {
                return null;
            }

            if ( 'free' === $mode ) {
                return 0.0;
            }

            // Modo flat u otro: retorna null por defecto (WC calcula)
            return null;

        } catch ( \Throwable $e ) {
            error_log( 'LTMS ShippingMode: ' . $e->getMessage() );
            return null;
        }
    }
}