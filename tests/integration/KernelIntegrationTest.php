<?php
/**
 * Tests de integración del Kernel LTMS.
 *
 * Verifica que el Kernel arranca correctamente con WP real instalado.
 * Requiere: WP Test Suite + WooCommerce instalado en el entorno de test.
 *
 * @package LTMS\Tests\Integration
 */

declare( strict_types=1 );

namespace LTMS\Tests\Integration;

/**
 * Class KernelIntegrationTest
 */
class KernelIntegrationTest extends LTMS_Integration_Test_Case {

    /**
     * El Kernel debe poder instanciarse (patrón Singleton).
     */
    public function test_kernel_singleton_returns_instance(): void {
        $this->require_ltms_class( 'LTMS_Core_Kernel' );

        $instance = \LTMS_Core_Kernel::get_instance();

        $this->assertInstanceOf(
            \LTMS_Core_Kernel::class,
            $instance,
            'get_instance() debe retornar una instancia de LTMS_Core_Kernel'
        );
    }

    /**
     * Dos llamadas a get_instance() deben retornar el mismo objeto.
     */
    public function test_kernel_is_true_singleton(): void {
        $this->require_ltms_class( 'LTMS_Core_Kernel' );

        $instance_1 = \LTMS_Core_Kernel::get_instance();
        $instance_2 = \LTMS_Core_Kernel::get_instance();

        $this->assertSame(
            $instance_1,
            $instance_2,
            'get_instance() debe retornar siempre el mismo objeto (Singleton)'
        );
    }

    /**
     * El Kernel debe tener un método boot() público.
     */
    public function test_kernel_has_boot_method(): void {
        $this->require_ltms_class( 'LTMS_Core_Kernel' );

        $this->assertTrue(
            method_exists( \LTMS_Core_Kernel::class, 'boot' ),
            'LTMS_Core_Kernel debe tener un método boot()'
        );
    }

    /**
     * boot() no debe lanzar excepciones con WP y WC disponibles.
     */
    public function test_kernel_boot_does_not_throw(): void {
        $this->require_ltms_class( 'LTMS_Core_Kernel' );
        $this->require_woocommerce();

        $kernel = \LTMS_Core_Kernel::get_instance();

        // Resetear estado de boot si ya arrancó
        $this->reset_kernel_boot_state( $kernel );

        $exception = null;

        try {
            $kernel->boot();
        } catch ( \Throwable $e ) {
            $exception = $e;
        }

        $this->assertNull(
            $exception,
            sprintf(
                "boot() lanzó una excepción: %s: %s en %s:%d\n\nTrace:\n%s",
                get_class( $exception ?? new \Exception() ),
                $exception ? $exception->getMessage() : '',
                $exception ? $exception->getFile() : '',
                $exception ? $exception->getLine() : 0,
                $exception ? $exception->getTraceAsString() : ''
            )
        );
    }

    /**
     * Después de boot(), el Kernel debe estar en estado "booted".
     */
    public function test_kernel_is_booted_after_boot(): void {
        $this->require_ltms_class( 'LTMS_Core_Kernel' );
        $this->require_woocommerce();

        $kernel = \LTMS_Core_Kernel::get_instance();
        $this->reset_kernel_boot_state( $kernel );

        try {
            $kernel->boot();
        } catch ( \Throwable $e ) {
            $this->markTestSkipped( 'boot() falló: ' . $e->getMessage() );
        }

        // Verificar que $booted = true (via método público o reflexión)
        if ( method_exists( $kernel, 'is_booted' ) ) {
            $this->assertTrue( $kernel->is_booted(), 'is_booted() debe retornar true después de boot()' );
        } else {
            // Reflexión como fallback
            try {
                $ref  = new \ReflectionClass( $kernel );
                $prop = $ref->getProperty( 'booted' );
                $prop->setAccessible( true );
                $booted = $prop->getValue( $kernel );
                $this->assertTrue( $booted, 'La propiedad $booted debe ser true después de boot()' );
            } catch ( \ReflectionException $e ) {
                $this->markTestSkipped( 'No se puede verificar estado booted: ' . $e->getMessage() );
            }
        }
    }

    /**
     * La acción ltms_kernel_booted debe dispararse después del boot exitoso.
     */
    public function test_kernel_booted_action_fires(): void {
        $this->require_ltms_class( 'LTMS_Core_Kernel' );
        $this->require_woocommerce();

        $action_fired = false;

        add_action( 'ltms_kernel_booted', function() use ( &$action_fired ) {
            $action_fired = true;
        } );

        $kernel = \LTMS_Core_Kernel::get_instance();
        $this->reset_kernel_boot_state( $kernel );

        try {
            $kernel->boot();
        } catch ( \Throwable $e ) {
            $this->markTestSkipped( 'boot() falló: ' . $e->getMessage() );
        }

        $this->assertTrue(
            $action_fired,
            "La acción 'ltms_kernel_booted' no se disparó tras boot()"
        );
    }

    // ─── Helpers privados ────────────────────────────────────────────────────

    /**
     * Resetea el estado interno del Kernel para poder hacer boot() de nuevo.
     * Usa Reflexión para acceder a $booted private/protected.
     */
    private function reset_kernel_boot_state( object $kernel ): void {
        try {
            $ref  = new \ReflectionClass( $kernel );
            $prop = $ref->getProperty( 'booted' );
            $prop->setAccessible( true );
            $prop->setValue( $kernel, false );
        } catch ( \ReflectionException $e ) {
            // No se puede resetear — el test puede fallar por estado previo
        }
    }
}
