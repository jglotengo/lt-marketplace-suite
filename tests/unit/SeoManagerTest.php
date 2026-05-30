<?php

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LTMS_SEO_Manager — F-09
 */
class SeoManagerTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Monkey\Functions\stubs( [ 'error_log' => null, 'get_option' => '' ] );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ── Estructura de clase ───────────────────────────────────────────────

    public function test_class_exists(): void {
        $this->assertTrue( class_exists( 'LTMS_SEO_Manager' ) );
    }

    public function test_has_init_method(): void {
        $this->assertTrue( method_exists( 'LTMS_SEO_Manager', 'init' ) );
    }

    public function test_has_inject_schema_org(): void {
        $this->assertTrue( method_exists( 'LTMS_SEO_Manager', 'inject_schema_org' ) );
    }

    public function test_has_inject_open_graph(): void {
        $this->assertTrue( method_exists( 'LTMS_SEO_Manager', 'inject_open_graph' ) );
    }

    public function test_has_inject_search_console(): void {
        $this->assertTrue( method_exists( 'LTMS_SEO_Manager', 'inject_search_console' ) );
    }

    public function test_has_optimize_title_parts(): void {
        $this->assertTrue( method_exists( 'LTMS_SEO_Manager', 'optimize_title_parts' ) );
    }

    // ── inject_search_console ─────────────────────────────────────────────

    public function test_inject_search_console_outputs_meta_when_key_set(): void {
        \LTMS_Core_Config::flush_cache();
        // Mockear get_option para que LTMS_Core_Config::get funcione sin WP
        Monkey\Functions\when( 'get_option' )
            ->alias( static fn( $key, $default = null ) =>
                $key === 'ltms_settings'
                    ? [ 'ltms_google_search_console_verify' => 'abc123XYZ' ]
                    : $default
            );
        Monkey\Functions\when( 'esc_attr' )->returnArg();

        ob_start();
        \LTMS_SEO_Manager::inject_search_console();
        $output = ob_get_clean();

        \LTMS_Core_Config::flush_cache();
        $this->assertStringContainsString( 'google-site-verification', $output );
        $this->assertStringContainsString( 'abc123XYZ', $output );
    }

    public function test_inject_search_console_outputs_nothing_when_key_empty(): void {
        \LTMS_Core_Config::flush_cache();
        // Sin set → get retorna default '' → no debe outputear nada

        ob_start();
        \LTMS_SEO_Manager::inject_search_console();
        $output = ob_get_clean();

        $this->assertSame( '', $output );
    }

    // ── inject_open_graph ─────────────────────────────────────────────────

    public function test_inject_open_graph_outputs_nothing_outside_product_or_home(): void {
        Monkey\Functions\when( 'is_singular' )->justReturn( false );
        Monkey\Functions\when( 'is_front_page' )->justReturn( false );

        ob_start();
        \LTMS_SEO_Manager::inject_open_graph();
        $output = ob_get_clean();

        $this->assertSame( '', $output );
    }

    // ── optimize_title_parts ──────────────────────────────────────────────

    public function test_optimize_title_parts_returns_array(): void {
        Monkey\Functions\when( 'is_singular' )->justReturn( false );
        $result = \LTMS_SEO_Manager::optimize_title_parts( [ 'title' => 'Test' ] );
        $this->assertIsArray( $result );
    }

    public function test_optimize_title_parts_preserves_keys_on_non_product(): void {
        Monkey\Functions\when( 'is_singular' )->justReturn( false );
        $input  = [ 'title' => 'My Page', 'site' => 'Lo Tengo' ];
        $result = \LTMS_SEO_Manager::optimize_title_parts( $input );
        $this->assertSame( 'My Page', $result['title'] );
        $this->assertSame( 'Lo Tengo', $result['site'] );
    }

    // ── Reflection: métodos estáticos ─────────────────────────────────────

    public function test_init_is_static(): void {
        $ref = new \ReflectionMethod( 'LTMS_SEO_Manager', 'init' );
        $this->assertTrue( $ref->isStatic() );
    }

    public function test_inject_schema_org_is_static(): void {
        $ref = new \ReflectionMethod( 'LTMS_SEO_Manager', 'inject_schema_org' );
        $this->assertTrue( $ref->isStatic() );
    }

    public function test_inject_open_graph_is_static(): void {
        $ref = new \ReflectionMethod( 'LTMS_SEO_Manager', 'inject_open_graph' );
        $this->assertTrue( $ref->isStatic() );
    }

    public function test_class_is_not_final(): void {
        $ref = new \ReflectionClass( 'LTMS_SEO_Manager' );
        $this->assertFalse( $ref->isFinal() );
    }
}
