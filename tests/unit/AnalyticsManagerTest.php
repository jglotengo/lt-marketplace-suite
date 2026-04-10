<?php
/**
 * Tests unitarios — LTMS_Analytics_Manager
 *
 * Métodos testeables sin WP/WC:
 *   - inject_ga4()         — output condicional según config GA4
 *   - inject_gtm_head()    — output condicional según config GTM
 *   - inject_gtm_body()    — output condicional según config GTM
 *   - inject_meta_pixel()  — output condicional según config Pixel
 *   - inject_vendor_pixels() — sin output cuando is_singular=false
 *   - inject_datalayer_events() — sin output cuando WC()->session es null
 *   - init()               — idempotente, no lanza excepciones
 *   - Reflexión: clase, métodos públicos estáticos
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Class AnalyticsManagerTest
 */
class AnalyticsManagerTest extends LTMS_Unit_Test_Case {

    protected function setUp(): void {
        parent::setUp();

        if ( ! class_exists( 'LTMS_Analytics_Manager' ) ) {
            $this->markTestSkipped( 'LTMS_Analytics_Manager no disponible.' );
        }

        // Resetear el flag estático $initialized entre tests via Reflection
        $ref = new \ReflectionProperty( 'LTMS_Analytics_Manager', 'initialized' );
        $ref->setAccessible( true );
        $ref->setValue( null, false );

        \LTMS_Core_Config::flush_cache();

        // Funciones WP condicionales usadas por AnalyticsManager
        Functions\stubs( [
            'is_singular'    => false,
            'get_post_field' => '',
            'get_the_ID'     => 0,
            'get_user_meta'  => '',
        ] );
    }

    protected function tearDown(): void {
        if ( class_exists( 'LTMS_Analytics_Manager' ) ) {
            $ref = new \ReflectionProperty( 'LTMS_Analytics_Manager', 'initialized' );
            $ref->setAccessible( true );
            $ref->setValue( null, false );
        }
        \LTMS_Core_Config::flush_cache();
        parent::tearDown();
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 1 — Estructura de clase
    // ════════════════════════════════════════════════════════════════════════

    public function test_class_exists(): void {
        $this->assertTrue( class_exists( 'LTMS_Analytics_Manager' ) );
    }

    public function test_inject_ga4_method_exists(): void {
        $this->assertTrue( method_exists( 'LTMS_Analytics_Manager', 'inject_ga4' ) );
    }

    public function test_inject_gtm_head_method_exists(): void {
        $this->assertTrue( method_exists( 'LTMS_Analytics_Manager', 'inject_gtm_head' ) );
    }

    public function test_inject_gtm_body_method_exists(): void {
        $this->assertTrue( method_exists( 'LTMS_Analytics_Manager', 'inject_gtm_body' ) );
    }

    public function test_inject_meta_pixel_method_exists(): void {
        $this->assertTrue( method_exists( 'LTMS_Analytics_Manager', 'inject_meta_pixel' ) );
    }

    public function test_inject_vendor_pixels_method_exists(): void {
        $this->assertTrue( method_exists( 'LTMS_Analytics_Manager', 'inject_vendor_pixels' ) );
    }

    public function test_inject_datalayer_events_method_exists(): void {
        $this->assertTrue( method_exists( 'LTMS_Analytics_Manager', 'inject_datalayer_events' ) );
    }

    public function test_inject_ga4_is_public_static(): void {
        $ref = new \ReflectionMethod( 'LTMS_Analytics_Manager', 'inject_ga4' );
        $this->assertTrue( $ref->isPublic() );
        $this->assertTrue( $ref->isStatic() );
    }

    public function test_inject_gtm_head_is_public_static(): void {
        $ref = new \ReflectionMethod( 'LTMS_Analytics_Manager', 'inject_gtm_head' );
        $this->assertTrue( $ref->isPublic() );
        $this->assertTrue( $ref->isStatic() );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 2 — inject_ga4() — sin config → sin output
    // ════════════════════════════════════════════════════════════════════════

    public function test_inject_ga4_no_output_cuando_sin_configurar(): void {
        ob_start();
        \LTMS_Analytics_Manager::inject_ga4();
        $output = ob_get_clean();

        $this->assertEmpty( trim( $output ) );
    }

    public function test_inject_ga4_no_output_con_id_vacio(): void {
        \LTMS_Core_Config::set( 'ltms_ga4_measurement_id', '' );

        ob_start();
        \LTMS_Analytics_Manager::inject_ga4();
        $output = ob_get_clean();

        $this->assertEmpty( trim( $output ) );
    }

    public function test_inject_ga4_produce_script_cuando_configurado(): void {
        \LTMS_Core_Config::set( 'ltms_ga4_measurement_id', 'G-TESTID123' );

        ob_start();
        \LTMS_Analytics_Manager::inject_ga4();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'G-TESTID123', $output );
        $this->assertStringContainsString( '<script', $output );
        $this->assertStringContainsString( 'googletagmanager.com/gtag/js', $output );
    }

    public function test_inject_ga4_incluye_gtag_config(): void {
        \LTMS_Core_Config::set( 'ltms_ga4_measurement_id', 'G-ABCD9876' );

        ob_start();
        \LTMS_Analytics_Manager::inject_ga4();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'gtag', $output );
        $this->assertStringContainsString( 'G-ABCD9876', $output );
    }

    public function test_inject_ga4_contiene_async(): void {
        \LTMS_Core_Config::set( 'ltms_ga4_measurement_id', 'G-ASYNCTEST' );

        ob_start();
        \LTMS_Analytics_Manager::inject_ga4();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'async', $output );
    }

    public function test_inject_ga4_contiene_datalayer_push(): void {
        \LTMS_Core_Config::set( 'ltms_ga4_measurement_id', 'G-DLAYER01' );

        ob_start();
        \LTMS_Analytics_Manager::inject_ga4();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'dataLayer', $output );
    }

    public function test_inject_ga4_id_en_url_src(): void {
        \LTMS_Core_Config::set( 'ltms_ga4_measurement_id', 'G-URLCHECK' );

        ob_start();
        \LTMS_Analytics_Manager::inject_ga4();
        $output = ob_get_clean();

        // El ID debe aparecer como query param en el src
        $this->assertStringContainsString( 'id=G-URLCHECK', $output );
    }

    public function test_inject_ga4_produce_exactamente_dos_tags_script(): void {
        \LTMS_Core_Config::set( 'ltms_ga4_measurement_id', 'G-TWOSCRIPTS' );

        ob_start();
        \LTMS_Analytics_Manager::inject_ga4();
        $output = ob_get_clean();

        // Debe haber exactamente 2 tags <script (uno async externo, uno inline)
        $this->assertSame( 2, substr_count( $output, '<script' ) );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 3 — inject_gtm_head() — sin config → sin output
    // ════════════════════════════════════════════════════════════════════════

    public function test_inject_gtm_head_no_output_cuando_sin_configurar(): void {
        ob_start();
        \LTMS_Analytics_Manager::inject_gtm_head();
        $output = ob_get_clean();

        $this->assertEmpty( trim( $output ) );
    }

    public function test_inject_gtm_head_produce_script_con_id(): void {
        \LTMS_Core_Config::set( 'ltms_google_tag_manager_id', 'GTM-ABCDE12' );

        ob_start();
        \LTMS_Analytics_Manager::inject_gtm_head();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'GTM-ABCDE12', $output );
        $this->assertStringContainsString( 'Google Tag Manager', $output );
        $this->assertStringContainsString( '<script>', $output );
    }

    public function test_inject_gtm_head_incluye_datalayer(): void {
        \LTMS_Core_Config::set( 'ltms_google_tag_manager_id', 'GTM-XYZ9999' );

        ob_start();
        \LTMS_Analytics_Manager::inject_gtm_head();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'dataLayer', $output );
    }

    public function test_inject_gtm_head_incluye_gtm_js_url(): void {
        \LTMS_Core_Config::set( 'ltms_google_tag_manager_id', 'GTM-URLTEST' );

        ob_start();
        \LTMS_Analytics_Manager::inject_gtm_head();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'googletagmanager.com/gtm.js', $output );
    }

    public function test_inject_gtm_head_id_en_script(): void {
        \LTMS_Core_Config::set( 'ltms_google_tag_manager_id', 'GTM-IDINSCR' );

        ob_start();
        \LTMS_Analytics_Manager::inject_gtm_head();
        $output = ob_get_clean();

        // El ID debe aparecer dentro del JS
        $this->assertStringContainsString( 'GTM-IDINSCR', $output );
    }

    public function test_inject_gtm_head_no_output_con_id_vacio(): void {
        \LTMS_Core_Config::set( 'ltms_google_tag_manager_id', '' );

        ob_start();
        \LTMS_Analytics_Manager::inject_gtm_head();
        $output = ob_get_clean();

        $this->assertEmpty( trim( $output ) );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 4 — inject_gtm_body() — sin config → sin output
    // ════════════════════════════════════════════════════════════════════════

    public function test_inject_gtm_body_no_output_cuando_sin_configurar(): void {
        ob_start();
        \LTMS_Analytics_Manager::inject_gtm_body();
        $output = ob_get_clean();

        $this->assertEmpty( trim( $output ) );
    }

    public function test_inject_gtm_body_produce_noscript_con_id(): void {
        \LTMS_Core_Config::set( 'ltms_google_tag_manager_id', 'GTM-BODY123' );

        ob_start();
        \LTMS_Analytics_Manager::inject_gtm_body();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'GTM-BODY123', $output );
        $this->assertStringContainsString( '<noscript>', $output );
        $this->assertStringContainsString( 'googletagmanager.com/ns.html', $output );
    }

    public function test_inject_gtm_body_contiene_iframe(): void {
        \LTMS_Core_Config::set( 'ltms_google_tag_manager_id', 'GTM-IFRAME1' );

        ob_start();
        \LTMS_Analytics_Manager::inject_gtm_body();
        $output = ob_get_clean();

        $this->assertStringContainsString( '<iframe', $output );
        $this->assertStringContainsString( '</iframe>', $output );
    }

    public function test_inject_gtm_body_id_como_query_param(): void {
        \LTMS_Core_Config::set( 'ltms_google_tag_manager_id', 'GTM-QUERYPAM' );

        ob_start();
        \LTMS_Analytics_Manager::inject_gtm_body();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'id=GTM-QUERYPAM', $output );
    }

    public function test_inject_gtm_body_contiene_display_none(): void {
        \LTMS_Core_Config::set( 'ltms_google_tag_manager_id', 'GTM-HIDDENX' );

        ob_start();
        \LTMS_Analytics_Manager::inject_gtm_body();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'display:none', $output );
    }

    public function test_inject_gtm_body_no_output_con_id_vacio(): void {
        \LTMS_Core_Config::set( 'ltms_google_tag_manager_id', '' );

        ob_start();
        \LTMS_Analytics_Manager::inject_gtm_body();
        $output = ob_get_clean();

        $this->assertEmpty( trim( $output ) );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 5 — inject_meta_pixel() — sin config → sin output
    // ════════════════════════════════════════════════════════════════════════

    public function test_inject_meta_pixel_no_output_cuando_sin_configurar(): void {
        ob_start();
        \LTMS_Analytics_Manager::inject_meta_pixel();
        $output = ob_get_clean();

        $this->assertEmpty( trim( $output ) );
    }

    public function test_inject_meta_pixel_produce_script_con_pixel_id(): void {
        \LTMS_Core_Config::set( 'ltms_meta_pixel_id', '123456789012345' );

        ob_start();
        \LTMS_Analytics_Manager::inject_meta_pixel();
        $output = ob_get_clean();

        $this->assertStringContainsString( '123456789012345', $output );
        $this->assertStringContainsString( 'Meta Pixel', $output );
        $this->assertStringContainsString( 'fbq', $output );
    }

    public function test_inject_meta_pixel_incluye_pageview_track(): void {
        \LTMS_Core_Config::set( 'ltms_meta_pixel_id', '111222333444555' );

        ob_start();
        \LTMS_Analytics_Manager::inject_meta_pixel();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'PageView', $output );
    }

    public function test_inject_meta_pixel_incluye_fbevents_js(): void {
        \LTMS_Core_Config::set( 'ltms_meta_pixel_id', '999888777666555' );

        ob_start();
        \LTMS_Analytics_Manager::inject_meta_pixel();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'fbevents.js', $output );
    }

    public function test_inject_meta_pixel_incluye_fbq_init(): void {
        \LTMS_Core_Config::set( 'ltms_meta_pixel_id', '112233445566778' );

        ob_start();
        \LTMS_Analytics_Manager::inject_meta_pixel();
        $output = ob_get_clean();

        $this->assertStringContainsString( "fbq('init'", $output );
    }

    public function test_inject_meta_pixel_incluye_connect_facebook_net(): void {
        \LTMS_Core_Config::set( 'ltms_meta_pixel_id', '223344556677889' );

        ob_start();
        \LTMS_Analytics_Manager::inject_meta_pixel();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'connect.facebook.net', $output );
    }

    public function test_inject_meta_pixel_no_output_con_id_vacio(): void {
        \LTMS_Core_Config::set( 'ltms_meta_pixel_id', '' );

        ob_start();
        \LTMS_Analytics_Manager::inject_meta_pixel();
        $output = ob_get_clean();

        $this->assertEmpty( trim( $output ) );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 6 — inject_vendor_pixels() — sin contexto WP → sin output
    // ════════════════════════════════════════════════════════════════════════

    public function test_inject_vendor_pixels_no_output_cuando_no_es_product(): void {
        // is_singular ya está stubado a false en setUp
        ob_start();
        \LTMS_Analytics_Manager::inject_vendor_pixels();
        $output = ob_get_clean();

        $this->assertEmpty( trim( $output ) );
    }

    public function test_inject_vendor_pixels_no_output_cuando_vendor_ga4_vacio(): void {
        // is_singular=true pero get_user_meta devuelve '' → sin output
        Functions\stubs( [
            'is_singular'   => true,
            'get_user_meta' => '',
            'get_the_ID'    => 42,
        ] );

        ob_start();
        \LTMS_Analytics_Manager::inject_vendor_pixels();
        $output = ob_get_clean();

        $this->assertEmpty( trim( $output ) );
    }

    public function test_inject_vendor_pixels_produce_script_cuando_vendor_tiene_ga4(): void {
        Functions\stubs( [
            'is_singular'    => true,
            'get_post_field' => '7',
            'get_the_ID'     => 99,
        ] );
        // get_user_meta devuelve el ID de GA4 del vendor
        Functions\when( 'get_user_meta' )->alias(
            static fn( $uid, $key, $single ) => $key === 'ltms_vendor_ga4_id' ? 'G-VENDOR123' : ''
        );

        ob_start();
        \LTMS_Analytics_Manager::inject_vendor_pixels();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'G-VENDOR123', $output );
        $this->assertStringContainsString( '<script', $output );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 7 — inject_datalayer_events() — sin WC session → sin output
    // ════════════════════════════════════════════════════════════════════════

    public function test_inject_datalayer_events_no_output_sin_wc_session(): void {
        ob_start();
        \LTMS_Analytics_Manager::inject_datalayer_events();
        $output = ob_get_clean();

        $this->assertEmpty( trim( $output ) );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 8 — init() idempotente
    // ════════════════════════════════════════════════════════════════════════

    public function test_init_is_idempotent(): void {
        $this->expectNotToPerformAssertions();
        \LTMS_Analytics_Manager::init();
        \LTMS_Analytics_Manager::init(); // segunda llamada — no-op silencioso
    }

    public function test_init_no_lanza_excepcion(): void {
        $this->expectNotToPerformAssertions();
        \LTMS_Analytics_Manager::init();
    }

    public function test_init_flag_se_resetea_entre_tests(): void {
        $ref = new \ReflectionProperty( 'LTMS_Analytics_Manager', 'initialized' );
        $ref->setAccessible( true );

        $this->assertFalse( $ref->getValue( null ), 'Flag debe estar reseteado al inicio del test' );

        \LTMS_Analytics_Manager::init();

        $this->assertTrue( $ref->getValue( null ), 'Flag debe ser true después de init()' );
    }

    public function test_init_con_gtm_configurado_no_lanza(): void {
        $this->expectNotToPerformAssertions();
        \LTMS_Core_Config::set( 'ltms_google_tag_manager_id', 'GTM-INITX01' );
        \LTMS_Analytics_Manager::init();
    }

    public function test_init_sin_gtm_sin_ga4_no_lanza(): void {
        $this->expectNotToPerformAssertions();
        \LTMS_Core_Config::set( 'ltms_google_tag_manager_id', '' );
        \LTMS_Core_Config::set( 'ltms_ga4_measurement_id', '' );
        \LTMS_Analytics_Manager::init();
    }

    public function test_init_segunda_llamada_no_muta_estado(): void {
        \LTMS_Analytics_Manager::init();

        $ref = new \ReflectionProperty( 'LTMS_Analytics_Manager', 'initialized' );
        $ref->setAccessible( true );
        $this->assertTrue( $ref->getValue( null ) );

        // Segunda llamada no debe lanzar ni cambiar estado
        \LTMS_Analytics_Manager::init();
        $this->assertTrue( $ref->getValue( null ) );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 9 — Reflexión completa
    // ════════════════════════════════════════════════════════════════════════

    public function test_reflection_inject_gtm_body_is_public_static(): void {
        $ref = new \ReflectionMethod( 'LTMS_Analytics_Manager', 'inject_gtm_body' );
        $this->assertTrue( $ref->isPublic() );
        $this->assertTrue( $ref->isStatic() );
    }

    public function test_reflection_inject_meta_pixel_is_public_static(): void {
        $ref = new \ReflectionMethod( 'LTMS_Analytics_Manager', 'inject_meta_pixel' );
        $this->assertTrue( $ref->isPublic() );
        $this->assertTrue( $ref->isStatic() );
    }

    public function test_reflection_inject_vendor_pixels_is_public_static(): void {
        $ref = new \ReflectionMethod( 'LTMS_Analytics_Manager', 'inject_vendor_pixels' );
        $this->assertTrue( $ref->isPublic() );
        $this->assertTrue( $ref->isStatic() );
    }

    public function test_reflection_inject_datalayer_events_is_public_static(): void {
        $ref = new \ReflectionMethod( 'LTMS_Analytics_Manager', 'inject_datalayer_events' );
        $this->assertTrue( $ref->isPublic() );
        $this->assertTrue( $ref->isStatic() );
    }

    public function test_reflection_push_purchase_event_param_is_int(): void {
        $ref    = new \ReflectionMethod( 'LTMS_Analytics_Manager', 'push_purchase_event' );
        $params = $ref->getParameters();
        $this->assertCount( 1, $params );
        $this->assertSame( 'order_id', $params[0]->getName() );
        $this->assertSame( 'int', $params[0]->getType()->getName() );
    }

    public function test_reflection_init_return_type_is_void(): void {
        $ref = new \ReflectionMethod( 'LTMS_Analytics_Manager', 'init' );
        $this->assertSame( 'void', $ref->getReturnType()->getName() );
    }

    public function test_reflection_class_is_not_final(): void {
        $rc = new \ReflectionClass( 'LTMS_Analytics_Manager' );
        $this->assertFalse( $rc->isFinal() );
    }

    public function test_reflection_initialized_property_is_static(): void {
        $ref = new \ReflectionProperty( 'LTMS_Analytics_Manager', 'initialized' );
        $this->assertTrue( $ref->isStatic() );
    }

    public function test_reflection_initialized_property_is_private(): void {
        $ref = new \ReflectionProperty( 'LTMS_Analytics_Manager', 'initialized' );
        $this->assertTrue( $ref->isPrivate() );
    }

    public function test_reflection_queue_add_to_cart_event_param_count(): void {
        $ref = new \ReflectionMethod( 'LTMS_Analytics_Manager', 'queue_add_to_cart_event' );
        $this->assertCount( 6, $ref->getParameters() );
    }
}

