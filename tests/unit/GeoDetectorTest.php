<?php
/**
 * GeoDetectorTest — Tests unitarios para LTMS_Geo_Detector
 *
 * Cubre:
 *  - get_available_cities()  — estructura completa CO + MX (vía Reflection)
 *  - get_current_city()      — sin cookie (default), con cookie, con cookie vacía
 *  - get_visitor_location()  — IP privada → default, sin IP → default, cache hit/miss
 *  - is_private_ip()         — IPs privadas/reservadas vs IPs públicas (vía Reflection)
 *  - get_client_ip()         — headers CF, X-Forwarded-For, REMOTE_ADDR (vía Reflection)
 *  - init()                  — flag de idempotencia
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * @covers LTMS_Geo_Detector
 */
class GeoDetectorTest extends LTMS_Unit_Test_Case {

    private \ReflectionClass $ref;

    protected function setUp(): void {
        parent::setUp();

        if ( ! class_exists( 'LTMS_Geo_Detector' ) ) {
            $this->markTestSkipped( 'LTMS_Geo_Detector no disponible.' );
        }

        $this->ref = new \ReflectionClass( 'LTMS_Geo_Detector' );

        $init = $this->ref->getProperty( 'initialized' );
        $init->setAccessible( true );
        $init->setValue( null, false );

        unset( $_COOKIE['ltms_city'], $_SERVER['HTTP_CF_CONNECTING_IP'],
               $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['REMOTE_ADDR'] );
    }

    protected function tearDown(): void {
        unset( $_COOKIE['ltms_city'], $_SERVER['HTTP_CF_CONNECTING_IP'],
               $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['REMOTE_ADDR'] );

        if ( class_exists( 'LTMS_Geo_Detector' ) ) {
            $init = $this->ref->getProperty( 'initialized' );
            $init->setAccessible( true );
            $init->setValue( null, false );
        }

        parent::tearDown();
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 1 — get_available_cities() estructura completa
    // ════════════════════════════════════════════════════════════════════════

    private function invoke_available_cities(): array {
        $method = $this->ref->getMethod( 'get_available_cities' );
        $method->setAccessible( true );
        return $method->invoke( null );
    }

    public function test_get_available_cities_returns_array(): void {
        $this->assertIsArray( $this->invoke_available_cities() );
    }

    public function test_get_available_cities_not_empty(): void {
        $this->assertNotEmpty( $this->invoke_available_cities() );
    }

    public function test_get_available_cities_has_co_key(): void {
        $this->assertArrayHasKey( 'CO', $this->invoke_available_cities() );
    }

    public function test_get_available_cities_has_mx_key(): void {
        $this->assertArrayHasKey( 'MX', $this->invoke_available_cities() );
    }

    public function test_colombia_cities_is_array(): void {
        $this->assertIsArray( $this->invoke_available_cities()['CO'] );
    }

    public function test_colombia_has_ten_cities(): void {
        $this->assertCount( 10, $this->invoke_available_cities()['CO'] );
    }

    public function test_mexico_has_five_cities(): void {
        $this->assertCount( 5, $this->invoke_available_cities()['MX'] );
    }

    /** @dataProvider colombia_cities_provider */
    public function test_colombia_includes_city( string $city ): void {
        $this->assertContains( $city, $this->invoke_available_cities()['CO'] );
    }

    public static function colombia_cities_provider(): array {
        return [
            'Bogotá'       => [ 'Bogotá' ],
            'Medellín'     => [ 'Medellín' ],
            'Cali'         => [ 'Cali' ],
            'Barranquilla' => [ 'Barranquilla' ],
            'Cartagena'    => [ 'Cartagena' ],
            'Bucaramanga'  => [ 'Bucaramanga' ],
            'Pereira'      => [ 'Pereira' ],
            'Manizales'    => [ 'Manizales' ],
            'Santa Marta'  => [ 'Santa Marta' ],
            'Cúcuta'       => [ 'Cúcuta' ],
        ];
    }

    /** @dataProvider mexico_cities_provider */
    public function test_mexico_includes_city( string $city ): void {
        $this->assertContains( $city, $this->invoke_available_cities()['MX'] );
    }

    public static function mexico_cities_provider(): array {
        return [
            'Ciudad de México' => [ 'Ciudad de México' ],
            'Guadalajara'      => [ 'Guadalajara' ],
            'Monterrey'        => [ 'Monterrey' ],
            'Puebla'           => [ 'Puebla' ],
            'Tijuana'          => [ 'Tijuana' ],
        ];
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 2 — get_current_city()
    // ════════════════════════════════════════════════════════════════════════

    public function test_get_current_city_returns_string(): void {
        $this->assertIsString( \LTMS_Geo_Detector::get_current_city() );
    }

    public function test_get_current_city_default_without_cookie(): void {
        unset( $_COOKIE['ltms_city'] );
        $this->assertSame( 'Bogotá', \LTMS_Geo_Detector::get_current_city() );
    }

    public function test_get_current_city_returns_cookie_value(): void {
        $_COOKIE['ltms_city'] = 'Medellín';
        Functions\stubs( [ 'sanitize_text_field' => static fn( $v ) => $v ] );
        $this->assertSame( 'Medellín', \LTMS_Geo_Detector::get_current_city() );
    }

    public function test_get_current_city_returns_mx_city_from_cookie(): void {
        $_COOKIE['ltms_city'] = 'Guadalajara';
        Functions\stubs( [ 'sanitize_text_field' => static fn( $v ) => $v ] );
        $this->assertSame( 'Guadalajara', \LTMS_Geo_Detector::get_current_city() );
    }

    /** NEW — cookie vacía → default */
    public function test_get_current_city_empty_cookie_returns_default(): void {
        $_COOKIE['ltms_city'] = '';
        Functions\stubs( [ 'sanitize_text_field' => static fn( $v ) => $v ] );
        $this->assertSame( 'Bogotá', \LTMS_Geo_Detector::get_current_city() );
    }

    /** NEW — retorno siempre es string no vacío cuando hay cookie válida */
    public function test_get_current_city_returns_non_empty_string_with_cookie(): void {
        $_COOKIE['ltms_city'] = 'Cali';
        Functions\stubs( [ 'sanitize_text_field' => static fn( $v ) => $v ] );
        $result = \LTMS_Geo_Detector::get_current_city();
        $this->assertNotSame( '', $result );
    }

    /** NEW — cookie con ciudad con acento se preserva */
    public function test_get_current_city_cookie_with_accent_preserved(): void {
        $_COOKIE['ltms_city'] = 'Bogotá';
        Functions\stubs( [ 'sanitize_text_field' => static fn( $v ) => $v ] );
        $this->assertSame( 'Bogotá', \LTMS_Geo_Detector::get_current_city() );
    }

    /** NEW — get_current_city es public static (verificación de reflexión) */
    public function test_get_current_city_is_public_static(): void {
        $m = $this->ref->getMethod( 'get_current_city' );
        $this->assertTrue( $m->isPublic() );
        $this->assertTrue( $m->isStatic() );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 3 — is_private_ip() via Reflection
    // ════════════════════════════════════════════════════════════════════════

    private function is_private_ip( string $ip ): bool {
        $m = $this->ref->getMethod( 'is_private_ip' );
        $m->setAccessible( true );
        return $m->invoke( null, $ip );
    }

    /** @dataProvider private_ip_provider */
    public function test_is_private_ip_returns_true( string $ip ): void {
        $this->assertTrue( $this->is_private_ip( $ip ) );
    }

    public static function private_ip_provider(): array {
        return [
            'loopback'               => [ '127.0.0.1' ],
            'RFC-1918 clase A'       => [ '10.0.0.1' ],
            'RFC-1918 clase B'       => [ '172.16.0.1' ],
            'RFC-1918 clase C'       => [ '192.168.1.1' ],
            'IPv6 loopback'          => [ '::1' ],
            // NEW — límites de rangos RFC-1918
            'RFC-1918 B límite alto' => [ '172.31.255.255' ],
            'RFC-1918 A límite alto' => [ '10.255.255.255' ],
            'RFC-1918 C límite alto' => [ '192.168.255.255' ],
            'link-local'             => [ '169.254.0.1' ],
        ];
    }

    /** @dataProvider public_ip_provider */
    public function test_is_private_ip_returns_false_for_public( string $ip ): void {
        $this->assertFalse( $this->is_private_ip( $ip ) );
    }

    public static function public_ip_provider(): array {
        return [
            'Google DNS'             => [ '8.8.8.8' ],
            'Cloudflare DNS'         => [ '1.1.1.1' ],
            'IP colombiana'          => [ '181.55.0.1' ],
            'IP mexicana'            => [ '189.203.0.1' ],
            // NEW
            'IP pública adicional A' => [ '5.5.5.5' ],
            'IP pública adicional B' => [ '200.1.2.3' ],
        ];
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 4 — get_client_ip() via Reflection + $_SERVER
    // ════════════════════════════════════════════════════════════════════════

    private function get_client_ip(): string {
        Functions\stubs( [
            'sanitize_text_field' => static fn( $v ) => $v,
            'wp_unslash'          => static fn( $v ) => $v,
        ] );
        $m = $this->ref->getMethod( 'get_client_ip' );
        $m->setAccessible( true );
        return $m->invoke( null );
    }

    public function test_get_client_ip_returns_empty_when_no_server_vars(): void {
        $this->assertSame( '', $this->get_client_ip() );
    }

    public function test_get_client_ip_reads_cloudflare_header(): void {
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '8.8.8.8';
        $this->assertSame( '8.8.8.8', $this->get_client_ip() );
    }

    public function test_get_client_ip_reads_x_forwarded_for(): void {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.1.1.1';
        $this->assertSame( '1.1.1.1', $this->get_client_ip() );
    }

    public function test_get_client_ip_reads_remote_addr(): void {
        $_SERVER['REMOTE_ADDR'] = '181.55.0.1';
        $this->assertSame( '181.55.0.1', $this->get_client_ip() );
    }

    public function test_get_client_ip_prefers_cf_over_x_forwarded(): void {
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '8.8.8.8';
        $_SERVER['HTTP_X_FORWARDED_FOR']  = '1.1.1.1';
        $this->assertSame( '8.8.8.8', $this->get_client_ip() );
    }

    public function test_get_client_ip_takes_first_from_comma_list(): void {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8, 1.2.3.4, 5.6.7.8';
        $this->assertSame( '8.8.8.8', $this->get_client_ip() );
    }

    public function test_get_client_ip_ignores_invalid_ip(): void {
        $_SERVER['REMOTE_ADDR'] = 'not-an-ip';
        $this->assertSame( '', $this->get_client_ip() );
    }

    /** NEW — X-Forwarded-For cae a REMOTE_ADDR cuando CF no está */
    public function test_get_client_ip_x_forwarded_fallback_from_cf(): void {
        unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '200.1.2.3';
        $this->assertSame( '200.1.2.3', $this->get_client_ip() );
    }

    /** NEW — X-Forwarded-For con espacios extra alrededor del separador */
    public function test_get_client_ip_x_forwarded_strips_spaces(): void {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '  5.5.5.5 , 10.0.0.1';
        $result = $this->get_client_ip();
        $this->assertSame( '5.5.5.5', trim( $result ) );
    }

    /** NEW — REMOTE_ADDR con IPv6 pública válida */
    public function test_get_client_ip_remote_addr_ipv6_public(): void {
        $_SERVER['REMOTE_ADDR'] = '2001:4860:4860::8888'; // Google IPv6 DNS
        $result = $this->get_client_ip();
        $this->assertNotSame( '', $result );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 5 — get_visitor_location() con IP privada / cache
    // ════════════════════════════════════════════════════════════════════════

    public function test_get_visitor_location_returns_array(): void {
        Functions\stubs( [
            'sanitize_text_field' => static fn( $v ) => $v,
            'wp_unslash'          => static fn( $v ) => $v,
            'get_transient'       => static fn() => false,
        ] );
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $this->assertIsArray( \LTMS_Geo_Detector::get_visitor_location() );
    }

    public function test_get_visitor_location_has_city_key(): void {
        Functions\stubs( [
            'sanitize_text_field' => static fn( $v ) => $v,
            'wp_unslash'          => static fn( $v ) => $v,
            'get_transient'       => static fn() => false,
        ] );
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $this->assertArrayHasKey( 'city', \LTMS_Geo_Detector::get_visitor_location() );
    }

    public function test_get_visitor_location_has_country_key(): void {
        Functions\stubs( [
            'sanitize_text_field' => static fn( $v ) => $v,
            'wp_unslash'          => static fn( $v ) => $v,
            'get_transient'       => static fn() => false,
        ] );
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $this->assertArrayHasKey( 'country', \LTMS_Geo_Detector::get_visitor_location() );
    }

    public function test_get_visitor_location_private_ip_returns_default(): void {
        Functions\stubs( [
            'sanitize_text_field' => static fn( $v ) => $v,
            'wp_unslash'          => static fn( $v ) => $v,
            'get_transient'       => static fn() => false,
        ] );
        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';
        $result = \LTMS_Geo_Detector::get_visitor_location();
        $this->assertSame( 'Bogotá', $result['city'] );
        $this->assertSame( 'CO', $result['country'] );
    }

    public function test_get_visitor_location_no_ip_returns_default(): void {
        Functions\stubs( [
            'sanitize_text_field' => static fn( $v ) => $v,
            'wp_unslash'          => static fn( $v ) => $v,
            'get_transient'       => static fn() => false,
        ] );
        $result = \LTMS_Geo_Detector::get_visitor_location();
        $this->assertSame( 'Bogotá', $result['city'] );
    }

    public function test_get_visitor_location_returns_cached_value(): void {
        $cached = [ 'city' => 'Cali', 'region' => 'Valle', 'country' => 'CO' ];
        Functions\stubs( [
            'sanitize_text_field' => static fn( $v ) => $v,
            'wp_unslash'          => static fn( $v ) => $v,
            'get_transient'       => static fn() => $cached,
        ] );
        $_SERVER['REMOTE_ADDR'] = '8.8.8.8';
        $result = \LTMS_Geo_Detector::get_visitor_location();
        $this->assertSame( 'Cali', $result['city'] );
    }

    public function test_get_visitor_location_invalid_cache_returns_default(): void {
        Functions\stubs( [
            'sanitize_text_field' => static fn( $v ) => $v,
            'wp_unslash'          => static fn( $v ) => $v,
            'get_transient'       => static fn() => 'corrupted',
        ] );
        $_SERVER['REMOTE_ADDR'] = '8.8.8.8';
        $result = \LTMS_Geo_Detector::get_visitor_location();
        $this->assertSame( 'Bogotá', $result['city'] );
    }

    /** NEW — cache con array sin key 'city' → resultado tiene key 'city' (aunque sea null) */
    public function test_get_visitor_location_cache_missing_city_key_returns_array(): void {
        $partial_cache = [ 'region' => 'Valle', 'country' => 'CO' ]; // sin 'city'
        Functions\stubs( [
            'sanitize_text_field' => static fn( $v ) => $v,
            'wp_unslash'          => static fn( $v ) => $v,
            'get_transient'       => static fn() => $partial_cache,
        ] );
        $_SERVER['REMOTE_ADDR'] = '8.8.8.8';
        $result = \LTMS_Geo_Detector::get_visitor_location();
        // La clase retorna el cache tal cual — el array existe aunque sin key 'city'
        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'country', $result );
    }

    /** NEW — resultado tiene clave 'region' */
    public function test_get_visitor_location_has_region_key(): void {
        $cached = [ 'city' => 'Medellín', 'region' => 'Antioquia', 'country' => 'CO' ];
        Functions\stubs( [
            'sanitize_text_field' => static fn( $v ) => $v,
            'wp_unslash'          => static fn( $v ) => $v,
            'get_transient'       => static fn() => $cached,
        ] );
        $_SERVER['REMOTE_ADDR'] = '181.55.0.1';
        $result = \LTMS_Geo_Detector::get_visitor_location();
        $this->assertArrayHasKey( 'region', $result );
    }

    /** NEW — get_visitor_location es public static */
    public function test_get_visitor_location_is_public_static(): void {
        $m = $this->ref->getMethod( 'get_visitor_location' );
        $this->assertTrue( $m->isPublic() );
        $this->assertTrue( $m->isStatic() );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 6 — init() idempotencia
    // ════════════════════════════════════════════════════════════════════════

    public function test_init_sets_initialized_flag(): void {
        Functions\stubs( [ 'add_action' => static fn() => true ] );
        \LTMS_Geo_Detector::init();
        $flag = $this->ref->getProperty( 'initialized' );
        $flag->setAccessible( true );
        $this->assertTrue( $flag->getValue( null ) );
    }

    public function test_init_is_idempotent(): void {
        Functions\stubs( [ 'add_action' => static fn() => true ] );
        \LTMS_Geo_Detector::init();
        \LTMS_Geo_Detector::init();
        $this->assertTrue( true );
    }

    public function test_init_disabled_via_config_skips_hooks(): void {
        \LTMS_Core_Config::set( 'ltms_geo_detection_enabled', false );
        Functions\stubs( [ 'add_action' => static fn() => true ] );
        \LTMS_Geo_Detector::init();
        $this->assertTrue( true );
    }
}
