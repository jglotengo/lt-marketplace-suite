<?php
/**
 * Clase base para tests de INTEGRACIÓN de LTMS.
 *
 * Extiende WP_UnitTestCase — requiere WP Test Suite instalada.
 * Provee helpers comunes: crear vendors, órdenes, wallets, usuarios.
 *
 * @package LTMS\Tests\Integration
 */

declare( strict_types=1 );

namespace LTMS\Tests\Integration;

use WP_UnitTestCase;

/**
 * Class LTMS_Integration_Test_Case
 */
abstract class LTMS_Integration_Test_Case extends WP_UnitTestCase {

    /**
     * ID del usuario administrador creado para cada test.
     */
    protected int $admin_user_id = 0;

    /**
     * ID del usuario vendor creado para cada test.
     */
    protected int $vendor_user_id = 0;

    /**
     * setUp — corre antes de cada test.
     */
    public function setUp(): void {
        parent::setUp();

        // Limpiar cualquier estado de output buffer
        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        // Crear usuario admin de test
        $this->admin_user_id = $this->factory->user->create( [
            'role'       => 'administrator',
            'user_login' => 'test_admin_' . uniqid(),
            'user_email' => 'admin_' . uniqid() . '@test.com',
        ] );
        wp_set_current_user( $this->admin_user_id );

        // Crear usuario vendor de test
        $this->vendor_user_id = $this->factory->user->create( [
            'role'       => 'subscriber',
            'user_login' => 'test_vendor_' . uniqid(),
            'user_email' => 'vendor_' . uniqid() . '@test.com',
        ] );
    }

    /**
     * tearDown — corre después de cada test.
     */
    public function tearDown(): void {
        // Limpiar custom caps del admin de test
        if ( $this->admin_user_id ) {
            $user = get_user_by( 'id', $this->admin_user_id );
            if ( $user ) {
                $user->remove_all_caps();
            }
        }

        parent::tearDown();
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    /**
     * Crea una orden WooCommerce de test.
     *
     * @param int   $vendor_id  ID del vendor al que pertenece la orden.
     * @param float $total      Total de la orden.
     * @param string $status    Estado inicial de la orden.
     * @return \WC_Order
     */
    protected function create_test_order(
        int $vendor_id,
        float $total = 100000.0,
        string $status = 'pending'
    ): \WC_Order {
        $order = wc_create_order( [
            'customer_id' => $vendor_id,
            'status'      => $status,
        ] );

        $product = $this->create_test_product( $total );
        $order->add_product( $product, 1 );
        $order->calculate_totals();
        $order->save();

        return $order;
    }

    /**
     * Crea un producto WooCommerce simple de test.
     *
     * @param float $price Precio del producto.
     * @return \WC_Product_Simple
     */
    protected function create_test_product( float $price = 100000.0 ): \WC_Product_Simple {
        $product = new \WC_Product_Simple();
        $product->set_name( 'Producto Test ' . uniqid() );
        $product->set_regular_price( (string) $price );
        $product->set_status( 'publish' );
        $product->save();
        return $product;
    }

    /**
     * Asigna el rol 'ltms_vendor' a un usuario.
     *
     * @param int $user_id
     */
    protected function make_vendor( int $user_id ): void {
        $user = get_user_by( 'id', $user_id );
        if ( $user ) {
            $user->set_role( 'ltms_vendor' );
        }
    }

    /**
     * Verifica que WooCommerce esté disponible, saltando el test si no.
     */
    protected function require_woocommerce(): void {
        if ( ! function_exists( 'WC' ) || ! class_exists( 'WC_Order' ) ) {
            $this->markTestSkipped( 'WooCommerce no está disponible en este entorno de test.' );
        }
    }

    /**
     * Verifica que una clase LTMS esté disponible.
     *
     * @param string $class_name Nombre completo de la clase.
     */
    protected function require_ltms_class( string $class_name ): void {
        if ( ! class_exists( $class_name ) ) {
            $this->markTestSkipped( "Clase {$class_name} no disponible — plugin no cargado correctamente." );
        }
    }
}
