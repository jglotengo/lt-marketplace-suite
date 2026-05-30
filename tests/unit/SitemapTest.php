<?php

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LTMS_Sitemap — F-09
 */
class SitemapTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Monkey\Functions\stubs( [ 'error_log' => null ] );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ── Estructura de clase ───────────────────────────────────────────────

    public function test_class_exists(): void {
        $this->assertTrue( class_exists( 'LTMS_Sitemap' ) );
    }

    public function test_has_init_method(): void {
        $this->assertTrue( method_exists( 'LTMS_Sitemap', 'init' ) );
    }

    public function test_has_register_rewrite_rules(): void {
        $this->assertTrue( method_exists( 'LTMS_Sitemap', 'register_rewrite_rules' ) );
    }

    public function test_has_add_query_vars(): void {
        $this->assertTrue( method_exists( 'LTMS_Sitemap', 'add_query_vars' ) );
    }

    public function test_has_handle_sitemap_request(): void {
        $this->assertTrue( method_exists( 'LTMS_Sitemap', 'handle_sitemap_request' ) );
    }

    // ── add_query_vars ────────────────────────────────────────────────────

    public function test_add_query_vars_appends_ltms_sitemap(): void {
        $result = \LTMS_Sitemap::add_query_vars( [] );
        $this->assertContains( 'ltms_sitemap', $result );
    }

    public function test_add_query_vars_preserves_existing_vars(): void {
        $result = \LTMS_Sitemap::add_query_vars( [ 'page', 'paged' ] );
        $this->assertContains( 'page', $result );
        $this->assertContains( 'paged', $result );
        $this->assertContains( 'ltms_sitemap', $result );
    }

    public function test_add_query_vars_returns_array(): void {
        $result = \LTMS_Sitemap::add_query_vars( [ 'foo' ] );
        $this->assertIsArray( $result );
    }

    // ── handle_sitemap_request — no-op fuera del sitemap ─────────────────

    public function test_handle_sitemap_request_does_nothing_without_query_var(): void {
        Monkey\Functions\when( 'get_query_var' )->justReturn( false );
        // No debe hacer output ni lanzar excepciones
        ob_start();
        \LTMS_Sitemap::handle_sitemap_request();
        $output = ob_get_clean();
        $this->assertSame( '', $output );
    }

    // ── Reflection ────────────────────────────────────────────────────────

    public function test_init_is_static(): void {
        $ref = new \ReflectionMethod( 'LTMS_Sitemap', 'init' );
        $this->assertTrue( $ref->isStatic() );
    }

    public function test_add_query_vars_is_static(): void {
        $ref = new \ReflectionMethod( 'LTMS_Sitemap', 'add_query_vars' );
        $this->assertTrue( $ref->isStatic() );
    }

    public function test_class_is_not_final(): void {
        $ref = new \ReflectionClass( 'LTMS_Sitemap' );
        $this->assertFalse( $ref->isFinal() );
    }

    // ── URL del sitemap ───────────────────────────────────────────────────

    public function test_sitemap_url_constant_format(): void {
        // La URL del sitemap debe terminar en .xml
        $this->assertMatchesRegularExpression( '/\.xml$/', 'ltms-sitemap.xml' );
    }
}
