<?php

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LTMS_Analytics_Manager — F-10
 */
class AnalyticsManagerTest extends TestCase {

    /** @var array<string,mixed> */
    private static array $option_store = [];

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        self::$option_store = [];
        Monkey\Functions\stubs( [ 'error_log' => null ] );
        Monkey\Functions\when( 'get_option' )
            ->alias( static fn( $key, $default = null ) =>
                self::$option_store[ $key ] ?? $default
            );
        \LTMS_Core_Config::flush_cache();
    }

    protected function tearDown(): void {
        \LTMS_Core_Config::flush_cache();
        Monkey\tearDown();
        parent::tearDown();
    }

    // ── Estructura de clase ───────────────────────────────────────────────

    public function test_class_exists(): void {
        $this->assertTrue( class_exists( 'LTMS_Analytics_Manager' ) );
    }

    public function test_has_init_method(): void {
        $this->assertTrue( method_exists( 'LTMS_Analytics_Manager', 'init' ) );
    }

    public function test_has_inject_gtm_head(): void {
        $this->assertTrue( method_exists( 'LTMS_Analytics_Manager', 'inject_gtm_head' ) );
    }

    public function test_has_inject_gtm_body(): void {
        $this->assertTrue( method_exists( 'LTMS_Analytics_Manager', 'inject_gtm_body' ) );
    }

    public function test_has_inject_ga4(): void {
        $this->assertTrue( method_exists( 'LTMS_Analytics_Manager', 'inject_ga4' ) );
    }

    public function test_has_inject_meta_pixel(): void {
        $this->assertTrue( method_exists( 'LTMS_Analytics_Manager', 'inject_meta_pixel' ) );
    }

    public function test_has_inject_vendor_pixels(): void {
        $this->assertTrue( method_exists( 'LTMS_Analytics_Manager', 'inject_vendor_pixels' ) );
    }

    public function test_has_inject_datalayer_events(): void {
        $this->assertTrue( method_exists( 'LTMS_Analytics_Manager', 'inject_datalayer_events' ) );
    }

    public function test_has_push_purchase_event(): void {
        $this->assertTrue( method_exists( 'LTMS_Analytics_Manager', 'push_purchase_event' ) );
    }

    public function test_has_queue_add_to_cart_event(): void {
        $this->assertTrue( method_exists( 'LTMS_Analytics_Manager', 'queue_add_to_cart_event' ) );
    }

    // ── Reflection: métodos estáticos ─────────────────────────────────────

    public function test_init_is_static(): void {
        $ref = new \ReflectionMethod( 'LTMS_Analytics_Manager', 'init' );
        $this->assertTrue( $ref->isStatic() );
    }

    public function test_inject_gtm_head_is_static(): void {
        $ref = new \ReflectionMethod( 'LTMS_Analytics_Manager', 'inject_gtm_head' );
        $this->assertTrue( $ref->isStatic() );
    }

    public function test_inject_ga4_is_static(): void {
        $ref = new \ReflectionMethod( 'LTMS_Analytics_Manager', 'inject_ga4' );
        $this->assertTrue( $ref->isStatic() );
    }

    public function test_inject_meta_pixel_is_static(): void {
        $ref = new \ReflectionMethod( 'LTMS_Analytics_Manager', 'inject_meta_pixel' );
        $this->assertTrue( $ref->isStatic() );
    }

    public function test_push_purchase_event_is_static(): void {
        $ref = new \ReflectionMethod( 'LTMS_Analytics_Manager', 'push_purchase_event' );
        $this->assertTrue( $ref->isStatic() );
    }

    public function test_class_is_not_final(): void {
        $ref = new \ReflectionClass( 'LTMS_Analytics_Manager' );
        $this->assertFalse( $ref->isFinal() );
    }

    // ── inject_gtm_head ───────────────────────────────────────────────────

    public function test_inject_gtm_head_outputs_nothing_when_no_gtm_id(): void {
        ob_start();
        \LTMS_Analytics_Manager::inject_gtm_head();
        $output = ob_get_clean();
        $this->assertSame( '', $output );
    }

    public function test_inject_gtm_head_outputs_script_when_gtm_id_set(): void {
        self::$option_store['ltms_settings'] = [ 'ltms_google_tag_manager_id' => 'GTM-TEST123' ];
        \LTMS_Core_Config::flush_cache();
        Monkey\Functions\when( 'esc_js' )->returnArg();

        ob_start();
        \LTMS_Analytics_Manager::inject_gtm_head();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'googletagmanager.com/gtm.js', $output );
        $this->assertStringContainsString( 'GTM-TEST123', $output );
    }

    // ── inject_gtm_body ───────────────────────────────────────────────────

    public function test_inject_gtm_body_outputs_nothing_when_no_gtm_id(): void {
        ob_start();
        \LTMS_Analytics_Manager::inject_gtm_body();
        $output = ob_get_clean();
        $this->assertSame( '', $output );
    }

    public function test_inject_gtm_body_outputs_noscript_when_gtm_id_set(): void {
        self::$option_store['ltms_settings'] = [ 'ltms_google_tag_manager_id' => 'GTM-TEST123' ];
        \LTMS_Core_Config::flush_cache();
        Monkey\Functions\when( 'esc_attr' )->returnArg();

        ob_start();
        \LTMS_Analytics_Manager::inject_gtm_body();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'noscript', $output );
        $this->assertStringContainsString( 'GTM-TEST123', $output );
    }

    // ── inject_ga4 ────────────────────────────────────────────────────────

    public function test_inject_ga4_outputs_nothing_when_no_ga4_id(): void {
        ob_start();
        \LTMS_Analytics_Manager::inject_ga4();
        $output = ob_get_clean();
        $this->assertSame( '', $output );
    }

    public function test_inject_ga4_outputs_gtag_script_when_id_set(): void {
        self::$option_store['ltms_settings'] = [ 'ltms_ga4_measurement_id' => 'G-ABC123' ];
        \LTMS_Core_Config::flush_cache();
        Monkey\Functions\when( 'esc_attr' )->returnArg();
        Monkey\Functions\when( 'esc_js' )->returnArg();

        ob_start();
        \LTMS_Analytics_Manager::inject_ga4();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'gtag/js', $output );
        $this->assertStringContainsString( 'G-ABC123', $output );
        $this->assertStringContainsString( "gtag('config'", $output );
    }

    // ── inject_meta_pixel ─────────────────────────────────────────────────

    public function test_inject_meta_pixel_outputs_nothing_when_no_pixel_id(): void {
        ob_start();
        \LTMS_Analytics_Manager::inject_meta_pixel();
        $output = ob_get_clean();
        $this->assertSame( '', $output );
    }

    public function test_inject_meta_pixel_outputs_fbq_when_pixel_id_set(): void {
        self::$option_store['ltms_settings'] = [ 'ltms_meta_pixel_id' => '1234567890' ];
        \LTMS_Core_Config::flush_cache();
        Monkey\Functions\when( 'esc_js' )->returnArg();
        // stub get_userdata so Advanced Matching code path is a no-op (no user email).
        Monkey\Functions\when( 'get_userdata' )->justReturn( false );

        // v2.9.6 consent gating: require 'full' consent cookie or pixel is suppressed.
        $_COOKIE['ltms_cookie_consent'] = 'full';

        ob_start();
        \LTMS_Analytics_Manager::inject_meta_pixel();
        $output = ob_get_clean();

        unset( $_COOKIE['ltms_cookie_consent'] );

        $this->assertStringContainsString( 'fbq', $output );
        $this->assertStringContainsString( '1234567890', $output );
        $this->assertStringContainsString( 'PageView', $output );
    }

    // ── inject_vendor_pixels ──────────────────────────────────────────────

    public function test_inject_vendor_pixels_outputs_nothing_outside_product(): void {
        Monkey\Functions\when( 'is_singular' )->justReturn( false );

        ob_start();
        \LTMS_Analytics_Manager::inject_vendor_pixels();
        $output = ob_get_clean();

        $this->assertSame( '', $output );
    }

    public function test_inject_vendor_pixels_outputs_nothing_when_no_vendor_ga4(): void {
        Monkey\Functions\when( 'is_singular' )->justReturn( true );
        Monkey\Functions\when( 'get_post_field' )->justReturn( 99 );
        Monkey\Functions\when( 'get_the_ID' )->justReturn( 1 );
        Monkey\Functions\when( 'get_user_meta' )->justReturn( '' );

        ob_start();
        \LTMS_Analytics_Manager::inject_vendor_pixels();
        $output = ob_get_clean();

        $this->assertSame( '', $output );
    }

    public function test_inject_vendor_pixels_outputs_gtag_when_vendor_has_ga4(): void {
        Monkey\Functions\when( 'is_singular' )->justReturn( true );
        Monkey\Functions\when( 'get_post_field' )->justReturn( 42 );
        Monkey\Functions\when( 'get_the_ID' )->justReturn( 10 );
        Monkey\Functions\when( 'get_user_meta' )->justReturn( 'G-VENDOR99' );
        Monkey\Functions\when( 'esc_attr' )->returnArg();
        Monkey\Functions\when( 'esc_js' )->returnArg();

        ob_start();
        \LTMS_Analytics_Manager::inject_vendor_pixels();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'G-VENDOR99', $output );
        $this->assertStringContainsString( 'gtag/js', $output );
    }

    // ── inject_datalayer_events ───────────────────────────────────────────

    public function test_inject_datalayer_events_outputs_nothing_without_wc_session(): void {
        Monkey\Functions\when( 'WC' )->justReturn( (object) [ 'session' => null ] );

        ob_start();
        \LTMS_Analytics_Manager::inject_datalayer_events();
        $output = ob_get_clean();

        $this->assertSame( '', $output );
    }

    // ── push_purchase_event ───────────────────────────────────────────────

    public function test_push_purchase_event_outputs_nothing_when_order_not_found(): void {
        Monkey\Functions\when( 'wc_get_order' )->justReturn( false );

        ob_start();
        \LTMS_Analytics_Manager::push_purchase_event( 9999 );
        $output = ob_get_clean();

        $this->assertSame( '', $output );
    }

    // ── Firma de métodos ──────────────────────────────────────────────────

    public function test_queue_add_to_cart_event_has_six_params(): void {
        $ref    = new \ReflectionMethod( 'LTMS_Analytics_Manager', 'queue_add_to_cart_event' );
        $this->assertSame( 6, $ref->getNumberOfParameters() );
    }

    public function test_push_purchase_event_accepts_int_param(): void {
        $ref    = new \ReflectionMethod( 'LTMS_Analytics_Manager', 'push_purchase_event' );
        $params = $ref->getParameters();
        $this->assertCount( 1, $params );
        $this->assertSame( 'int', $params[0]->getType()->getName() );
    }

    public function test_inject_datalayer_events_has_no_params(): void {
        $ref = new \ReflectionMethod( 'LTMS_Analytics_Manager', 'inject_datalayer_events' );
        $this->assertSame( 0, $ref->getNumberOfParameters() );
    }
}
