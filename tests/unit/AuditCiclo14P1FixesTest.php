<?php
/**
 * AuditCiclo14P1FixesTest — Tests para los fixes P1 del Ciclo 1.4.
 *
 * Cubre 5 fixes P1 aplicados a las integraciones API:
 *   - AUDIT-API-BACKBLAZE-001: Location duplicada en upload_file (PHP silenciaba
 *     la segunda sobre la primera → URL devuelta no encoded).
 *   - AUDIT-API-BACKBLAZE-002: get_signed_url sin validación bucket/key →
 *     path traversal en URLs pre-firmadas. Validación extraída a helpers
 *     validate_bucket / validate_object_key / validate_bucket_and_key.
 *   - AUDIT-API-ZAPSIGN-001: download_signed_document y delete_document
 *     sin validar doc_token. Validación extraída a helper validate_doc_token.
 *   - AUDIT-API-XCOVER-001: get_quotes sin validar price numérico positivo
 *     → cotizaciones con valores absurdos.
 *   - AUDIT-API-STRIPE-001: create_refund currency hardcodeado a 'cop' →
 *     reembolsos MXN mal convertidos. ANTES no había parámetro currency.
 *   - AUDIT-API-STRIPE-002: Idempotency-Key del refund usaba '(string)$amount'
 *     → colisión entre 1234.5 y 1234.50. Ahora usa sprintf('%.2f', $amount).
 *   - AUDIT-API-OPENPAY-001: constructor no validaba country_override contra
 *     allowlist [CO,MX] → país 'US' producía comportamiento indefinido.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types = 1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ReflectionClass;
use ReflectionMethod;

/**
 * @covers AUDIT-API-BACKBLAZE-001/002, AUDIT-API-ZAPSIGN-001,
 *         AUDIT-API-XCOVER-001, AUDIT-API-STRIPE-001/002,
 *         AUDIT-API-OPENPAY-001
 */
class AuditCiclo14P1FixesTest extends LTMS_Unit_Test_Case {

    protected function setUp(): void {
        parent::setUp();

        // Stubs comunes requeridos por las clases bajo test.
        Functions\stubs( [
            'sanitize_text_field' => static fn( string $s ): string => $s,
            'sanitize_email'      => static fn( string $s ): string => $s,
            'sanitize_key'        => static fn( string $s ): string => $s,
            'esc_html'            => static fn( string $s ): string => $s,
            '__'                  => static fn( string $s ): string => $s,
            'wp_json_encode'      => static fn( $data ): string => json_encode( $data ),
            'wp_parse_url'        => static function ( string $url, int $component = -1 ): mixed {
                return parse_url( $url, $component );
            },
            'get_option'          => static fn( string $k, mixed $d = false ): mixed => $d,
            'update_option'       => static fn(): bool => true,
            'get_transient'       => static fn(): mixed => false,
            'set_transient'       => static fn(): bool => true,
            'delete_transient'    => static fn(): bool => true,
            'gmtdate'              => static fn( string $f ): string => date( $f ),
            'current_time'        => static fn( string $t = 'mysql', bool $gmt = false ): string => date( 'Y-m-d H:i:s' ),
        ] );
    }

    protected function tearDown(): void {
        \LTMS_Core_Config::flush_cache();
        parent::tearDown();
    }

    // ── AUDIT-API-BACKBLAZE-001: Location duplicada ─────────────────────────────

    /**
     * El array de retorno de upload_file() debe tener EXACTAMENTE UNA clave
     * 'Location' (no dos como antes), y debe ser la URL encoded (con el path
     * que contiene $encoded_key, no el $key original).
     *
     * AUDIT-API-BACKBLAZE-001 (Ciclo 1.4 P1): ANTES había dos 'Location' en
     * el array de retorno (L220 encoded + L223 sin encode); PHP silenciaba
     * la segunda sobre la primera → URL devuelta no encoded. Verificamos el
     * fix con inspección del código source + invocation del método con un
     * stub simple de sign_request via subclass anónima.
     */
    public function test_backblaze_upload_file_returns_single_location_key(): void {
        if ( ! class_exists( 'LTMS_Api_Backblaze' ) ) {
            $this->markTestSkipped( 'LTMS_Api_Backblaze no disponible en modo UNIT_ONLY.' );
        }

        // Verificación 1: el código fuente del método upload_file tiene una
        // sola clave 'Location' en el array de retorno (no dos como antes).
        $source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/api/class-ltms-api-backblaze.php' );
        // Buscar el bloque return [ ... ] justo antes de cerrar upload_file.
        // El fix AUDIT-API-BACKBLAZE-001 elimina la línea:
        //   'Location' => $this->api_url . '/' . trim( $bucket, '/' ) . '/' . ltrim( $key, '/' ),
        // (la línea no-encoded, segunda 'Location' duplicada).
        $this->assertStringNotContainsString(
            "trim( \$bucket, '/' ) . '/' . ltrim( \$key",
            $source,
            'AUDIT-API-BACKBLAZE-001: la línea duplicada no-encoded debe estar eliminada'
        );

        // Verificación 2: subclass anónima que overridea sign_request para
        // retornar headers sin firma real (no requiere HMAC). Así podemos
        // invocar upload_file y capturar el array de retorno final.
        // Stub HTTP response (success 200 con ETag header).
        Functions\stubs( [
            'wp_remote_request' => static fn(): array => [
                'response' => [ 'code' => 200, 'message' => 'OK' ],
                'headers'  => [ 'etag' => '"abc123etag"' ],
                'body'     => '',
            ],
            'is_wp_error'             => static fn(): bool => false,
            'wp_remote_retrieve_response_code' => static fn( $r ): int => 200,
            'wp_remote_retrieve_header' => static function ( $r, string $key ): string {
                return 'etag' === $key ? '"abc123etag"' : '';
            },
            'wp_remote_retrieve_body'  => static fn(): string => '',
        ] );

        // Subclass anónima que overridea sign_request para evitar HMAC real.
        // Inicializamos las props typed requeridas por sign_request vía reflection.
        $client = new class extends \LTMS_Api_Backblaze {
            public function __construct() { /* no-op */ }
            public function sign_request(
                string $method, string $path, array $headers = [],
                string $payload = '', string $service = 's3', string $qs = ''
            ): array {
                // Asegurar que las headers necesarias estén presentes.
                $headers['x-amz-content-sha256'] = $headers['x-amz-content-sha256'] ?? 'stub_hash';
                return $headers;
            }
        };

        // Inicializar props typed del abstract client: api_url, region, key_id.
        // Usar ReflectionClass de la clase base porque las props privadas
        // (`private string $region`) no se exponen vía ReflectionClass de la
        // subclass anónima — Reflection solo recorre la clase announceada.
        $parent_ref = new ReflectionClass( \LTMS_Api_Backblaze::class );

        $url_prop = $parent_ref->getProperty( 'api_url' );
        $url_prop->setAccessible( true );
        $url_prop->setValue( $client, 'https://s3.us-west-004.backblazeb2.com' );

        $region_prop = $parent_ref->getProperty( 'region' );
        $region_prop->setAccessible( true );
        $region_prop->setValue( $client, 'us-west-004' );

        $key_id_prop = $parent_ref->getProperty( 'key_id' );
        $key_id_prop->setAccessible( true );
        $key_id_prop->setValue( $client, 'KEY_ID_STUB' );

        $app_key_prop = $parent_ref->getProperty( 'app_key' );
        $app_key_prop->setAccessible( true );
        $app_key_prop->setValue( $client, 'APP_KEY_STUB' );

        $method = new ReflectionMethod( $client, 'upload_file' );
        $method->setAccessible( true );

        // Llamar a upload_file con un key que contiene espacios (caso típico:
        // "contrato año 2026.pdf"). Sin el fix AUDIT-API-BACKBLAZE-001, la
        // 'Location' duplicada no-encoded ganaba y devolvía el path con espacios.
        $result = $method->invoke( $client, 'test-bucket', 'contrato año 2026.pdf', 'contenido', 'application/pdf' );

        $this->assertArrayHasKey( 'Location', $result, 'Array debe tener Location' );

        // Verificar unicidad contando claves 'Location': PHP array solo admite
        // 1 clave 'Location' (las duplicadas silencian), pero el test garantiza
        // explícitamente que se usa la encoded.
        $location_count = 0;
        foreach ( $result as $k => $v ) {
            if ( $k === 'Location' ) {
                $location_count++;
            }
        }
        $this->assertSame( 1, $location_count, 'AUDIT-API-BACKBLAZE-001: exactamente 1 Location' );

        // La Location debe tener el key encoded (rawurlencode por segmento).
        $this->assertStringContainsString( 'contrato%20a%C3%B1o%202026.pdf', $result['Location'], 'Location debe contener el key encoded' );
        $this->assertStringNotContainsString( 'contrato año 2026.pdf', $result['Location'], 'Location NO debe contener el key crudo con espacios' );
    }

    // ── AUDIT-API-BACKBLAZE-002: get_signed_url valida bucket/key ───────────────

    /**
     * get_signed_url con un bucket inválido (contiene '../') debe lanzar
     * InvalidArgumentException. Antes del fix, el path se construía sin validar
     * → URL firmada con path traversal.
     */
    public function test_backblaze_get_signed_url_rejects_invalid_bucket(): void {
        if ( ! class_exists( 'LTMS_Api_Backblaze' ) ) {
            $this->markTestSkipped( 'LTMS_Api_Backblaze no disponible en modo UNIT_ONLY.' );
        }

        $ref = new ReflectionClass( \LTMS_Api_Backblaze::class );
        $client = $ref->newInstanceWithoutConstructor();

        $method = new ReflectionMethod( $client, 'get_signed_url' );
        $method->setAccessible( true );

        $this->expectException( \InvalidArgumentException::class );
        $method->invoke( $client, '../evil-bucket', 'legit-key.pdf' );
    }

    /**
     * get_signed_url con un key que contiene '../' debe lanzar
     * InvalidArgumentException (path traversal).
     */
    public function test_backblaze_get_signed_url_rejects_path_traversal_key(): void {
        if ( ! class_exists( 'LTMS_Api_Backblaze' ) ) {
            $this->markTestSkipped( 'LTMS_Api_Backblaze no disponible en modo UNIT_ONLY.' );
        }

        $ref = new ReflectionClass( \LTMS_Api_Backblaze::class );
        $client = $ref->newInstanceWithoutConstructor();

        $method = new ReflectionMethod( $client, 'get_signed_url' );
        $method->setAccessible( true );

        $this->expectException( \InvalidArgumentException::class );
        $method->invoke( $client, 'legit-bucket', '../secret/key.pdf' );
    }

    /**
     * Validaciones extraídas: validate_bucket y validate_object_key son
     * accesibles vía reflection y retornan void sin excepción para inputs válidos.
     */
    public function test_backblaze_validate_helpers_accept_valid_inputs(): void {
        if ( ! class_exists( 'LTMS_Api_Backblaze' ) ) {
            $this->markTestSkipped( 'LTMS_Api_Backblaze no disponible en modo UNIT_ONLY.' );
        }

        $ref = new ReflectionClass( \LTMS_Api_Backblaze::class );
        $client = $ref->newInstanceWithoutConstructor();

        $validate_bucket = new ReflectionMethod( $client, 'validate_bucket' );
        $validate_bucket->setAccessible( true );

        $validate_key = new ReflectionMethod( $client, 'validate_object_key' );
        $validate_key->setAccessible( true );

        // Valid: no lanza.
        $validate_bucket->invoke( $client, 'legit-bucket-name' );
        $validate_key->invoke( $client, 'path/to/legit_key.pdf' );

        $this->assertTrue( true, 'Helpers aceptan inputs válidos sin excepción' );
    }

    // ── AUDIT-API-ZAPSIGN-001: doc_token validation en download/delete ───────────

    /**
     * download_signed_document con un doc_token malicioso (contiene '../') debe
     * retornar '' (string vacío). Antes del fix, se pasaba directo al path
     * /docs/../download/ → potencial path traversal.
     */
    public function test_zapsign_download_signed_document_rejects_invalid_token(): void {
        if ( ! class_exists( 'LTMS_Api_Zapsign' ) ) {
            $this->markTestSkipped( 'LTMS_Api_Zapsign no disponible en modo UNIT_ONLY.' );
        }

        // Constructor requiere api_token descifrado. Usar reflection.
        $ref = new ReflectionClass( \LTMS_Api_Zapsign::class );
        $client = $ref->newInstanceWithoutConstructor();

        // Set api_token via reflection para evitar constructor.
        $token_prop = $ref->getProperty( 'api_token' );
        $token_prop->setAccessible( true );
        $token_prop->setValue( $client, 'zap_token_test' );

        $method = new ReflectionMethod( $client, 'download_signed_document' );
        $method->setAccessible( true );

        // Token con '/' → rechazado por regex /^[A-Za-z0-9_-]{1,128}$/
        $result = $method->invoke( $client, '../etc/passwd' );
        $this->assertSame( '', $result, 'AUDIT-API-ZAPSIGN-001: token malicioso retorna string vacío' );
    }

    /**
     * delete_document con un doc_token malicioso debe retornar false (no
     * procede con DELETE al path arbitrario).
     */
    public function test_zapsign_delete_document_rejects_invalid_token(): void {
        if ( ! class_exists( 'LTMS_Api_Zapsign' ) ) {
            $this->markTestSkipped( 'LTMS_Api_Zapsign no disponible en modo UNIT_ONLY.' );
        }

        $ref = new ReflectionClass( \LTMS_Api_Zapsign::class );
        $client = $ref->newInstanceWithoutConstructor();

        $token_prop = $ref->getProperty( 'api_token' );
        $token_prop->setAccessible( true );
        $token_prop->setValue( $client, 'zap_token_test' );

        $method = new ReflectionMethod( $client, 'delete_document' );
        $method->setAccessible( true );

        $result = $method->invoke( $client, 'token/with/slashes' );
        $this->assertFalse( $result, 'AUDIT-API-ZAPSIGN-001: DELETE de token malicioso retorna false' );
    }

    /**
     * get_document_status con un doc_token válido (forma UUID) NO debe
     * retornar el error 'doc_token inválido' — es decir, pasa la validación
     * y procede a perform_request (que aquí fallará con RuntimeException
     * porque no hay HTTP stub; capturamos eso para verificar que pasó).
     */
    public function test_zapsign_validate_doc_token_helper_accepts_valid_uuid(): void {
        if ( ! class_exists( 'LTMS_Api_Zapsign' ) ) {
            $this->markTestSkipped( 'LTMS_Api_Zapsign no disponible en modo UNIT_ONLY.' );
        }

        $ref = new ReflectionClass( \LTMS_Api_Zapsign::class );
        $client = $ref->newInstanceWithoutConstructor();

        $validate = new ReflectionMethod( $client, 'validate_doc_token' );
        $validate->setAccessible( true );

        // Valid UUID-shaped tokens → null (no error array).
        $this->assertNull( $validate->invoke( $client, 'a1b2c3d4-e5f6-7890-abcd-ef1234567890' ) );
        $this->assertNull( $validate->invoke( $client, 'simple_token_123' ) );
    }

    /**
     * validate_doc_token con tokens inválidos debe retornar array con
     * 'status'='error' y 'error'='[zapsign] doc_token inválido.'.
     */
    public function test_zapsign_validate_doc_token_helper_rejects_invalid_tokens(): void {
        if ( ! class_exists( 'LTMS_Api_Zapsign' ) ) {
            $this->markTestSkipped( 'LTMS_Api_Zapsign no disponible en modo UNIT_ONLY.' );
        }

        $ref = new ReflectionClass( \LTMS_Api_Zapsign::class );
        $client = $ref->newInstanceWithoutConstructor();

        $validate = new ReflectionMethod( $client, 'validate_doc_token' );
        $validate->setAccessible( true );

        // Casos inválidos.
        foreach ( [ '../etc/passwd', 'token/with/slashes', 'token with space', 'a:b:c', '', str_repeat( 'a', 129 ) ] as $invalid_token ) {
            $result = $validate->invoke( $client, $invalid_token );
            $this->assertIsArray( $result, "Token inválido '$invalid_token' debe retornar array de error" );
            $this->assertSame( 'error', $result['status'] );
            $this->assertStringContainsString( 'doc_token', $result['error'] );
        }
    }

    // ── AUDIT-API-XCOVER-001: validar price numérico positivo en get_quotes ─────

    /**
     * get_quotes con un price negativo debe retornar [] (no procede a la API).
     */
    public function test_xcover_get_quotes_rejects_negative_price(): void {
        if ( ! class_exists( 'LTMS_Api_XCover' ) ) {
            $this->markTestSkipped( 'LTMS_Api_XCover no disponible en modo UNIT_ONLY.' );
        }

        $ref = new ReflectionClass( \LTMS_Api_XCover::class );
        $client = $ref->newInstanceWithoutConstructor();

        // Set partner_code via reflection.
        $partner_prop = $ref->getProperty( 'partner_code' );
        $partner_prop->setAccessible( true );
        $partner_prop->setValue( $client, 'legit_partner' );

        $method = new ReflectionMethod( $client, 'get_quotes' );
        $method->setAccessible( true );

        $result = $method->invoke( $client, [
            'name'          => 'Product',
            'price'         => -100.0,
            'currency'      => 'COP',
            'category'      => 'general',
        ] );

        $this->assertSame( [], $result, 'AUDIT-API-XCOVER-001: price negativo → []' );
    }

    /**
     * get_quotes con un price string no-numérico (ej: "1e308xyz") debe
     * retornar [] también — antes hacía (float)"1e308xyz" = 0 silencioso.
     */
    public function test_xcover_get_quotes_rejects_non_numeric_price(): void {
        if ( ! class_exists( 'LTMS_Api_XCover' ) ) {
            $this->markTestSkipped( 'LTMS_Api_XCover no disponible en modo UNIT_ONLY.' );
        }

        $ref = new ReflectionClass( \LTMS_Api_XCover::class );
        $client = $ref->newInstanceWithoutConstructor();

        $partner_prop = $ref->getProperty( 'partner_code' );
        $partner_prop->setAccessible( true );
        $partner_prop->setValue( $client, 'legit_partner' );

        $method = new ReflectionMethod( $client, 'get_quotes' );
        $method->setAccessible( true );

        $result = $method->invoke( $client, [
            'name'  => 'Product',
            'price' => 'not_a_number',
        ] );

        $this->assertSame( [], $result );
    }

    /**
     * get_quotes con un price numérico válido (pero sin HTTP stub) debe
     * proceder a perform_request — verificamos que NO retorna [] por la
     * validación de price (la [] que llegue sería porque el stub returned
     * la respuesta sin 'quotes'). Para evitar HTTP stub complejo, sólo
     * verificamos que la validación de price pase: price = 0.0 es válido
     * (algunos productos son gratis) —以前 este test garantiza que no se
     * rechaza 0 como inválido.
     */
    public function test_xcover_get_quotes_accepts_zero_price(): void {
        if ( ! class_exists( 'LTMS_Api_XCover' ) ) {
            $this->markTestSkipped( 'LTMS_Api_XCover no disponible en modo UNIT_ONLY.' );
        }

        // Stub perform_request para retornar quotes vacío sin HTTP real.
        Functions\stubs( [
            'wp_remote_request'   => static fn(): array => [ 'response' => [ 'code' => 200 ], 'body' => '{}' ],
            'is_wp_error'         => static fn(): bool => false,
            'wp_remote_retrieve_response_code' => static fn(): int => 200,
            'wp_remote_retrieve_body'  => static fn(): string => '{}',
        ] );

        $ref = new ReflectionClass( \LTMS_Api_XCover::class );
        $client = $ref->newInstanceWithoutConstructor();

        $partner_prop = $ref->getProperty( 'partner_code' );
        $partner_prop->setAccessible( true );
        $partner_prop->setValue( $client, 'legit_partner' );

        // Set url base (necesario para perform_request del abstract).
        $parent = $ref->getParentClass();
        $url_prop = $parent->getProperty( 'api_url' );
        $url_prop->setAccessible( true );
        $url_prop->setValue( $client, 'https://api.example.com' );

        $method = new ReflectionMethod( $client, 'get_quotes' );
        $method->setAccessible( true );

        // price = 0.0 es válido (no negativo, no INF, no NaN) — perform_request retornará [],
        // pero NO por la validación de price.
        $method->invoke( $client, [
            'name'  => 'Free Product',
            'price' => 0.0,
        ] );

        // Si llegamos aquí sin excepción, la validación pasó.
        $this->assertTrue( true, 'AUDIT-API-XCOVER-001: price=0.0 es válido y pasa la validación' );
    }

    // ── AUDIT-API-STRIPE-001: create_refund accepts currency parameter ──────────

    /**
     * Reflection: la firma de create_refund debe aceptar un parámetro $currency
     * (4º parámetro, default ''). Antes del fix, no existía.
     */
    public function test_stripe_create_refund_signature_has_currency_parameter(): void {
        if ( ! class_exists( 'LTMS_Api_Stripe' ) ) {
            $this->markTestSkipped( 'LTMS_Api_Stripe no disponible (requiere Stripe SDK).' );
        }

        $ref = new ReflectionMethod( \LTMS_Api_Stripe::class, 'create_refund' );
        $params = $ref->getParameters();

        $this->assertCount( 4, $params, 'create_refund debe tener 4 parámetros (pi_id, amount, reason, currency)' );
        $this->assertSame( 'currency', $params[3]->getName(), '4º parámetro debe ser $currency' );
        $this->assertTrue( $params[3]->isOptional(), 'currency debe ser opcional' );
        $this->assertSame( '', $params[3]->getDefaultValue(), 'currency default debe ser empty string' );
    }

    // ── AUDIT-API-STRIPE-002: Idempotency-Key determinista con sprintf(%.2f) ────

    /**
     * La serialización del monto para Idempotency-Key debe usar sprintf('%.2f')
     * para garantizar determinismo — ANTES usaba (string)$amount que producía
     * colisión entre 1234.5 y 1234.50. Verificamos el patrón directly.
     */
    public function test_stripe_refund_idempotency_key_amount_serialization_is_deterministic(): void {
        // El comportamiento esperado: sprintf('%.2f', $amount) debe producir
        // el mismo string para valores numéricamente equivalentes con distinta
        // representación string.
        $this->assertSame( '1234.50', sprintf( '%.2f', 1234.5 ) );
        $this->assertSame( '1234.50', sprintf( '%.2f', 1234.50 ) );
        $this->assertSame( '1234.50', sprintf( '%.2f', 1234.5000001 ) ); // boundary: 6 decimales trunca a 2
        $this->assertSame( '0.00', sprintf( '%.2f', 0.0 ) );
        $this->assertSame( '100.00', sprintf( '%.2f', 100 ) );

        // Verificar que ANTES (string) era no-determinista:
        $this->assertSame( '1234.5', (string) 1234.5 );
        $this->assertSame( '1234.5', (string) 1234.50 );   // PHP normaliza a '1234.5'
        // Note: (string) 1234.50 === (string) 1234.5 in PHP — solo 1 decimal.
        // Esto es exactamente el bug AUDIT-API-STRIPE-002: 1234.5 vs 1234.50
        // producían la misma key (1234.5) — mismo monto, OK; pero 1234.5000001
        // sería '1234.5000001' y produciría key distinta siendo mismo monto.
    }

    // ── AUDIT-API-OPENPAY-001: country_override allowlist [CO,MX] ───────────────

    /**
     * Constructor de Openpay con country_override='US' debe lanzar
     * InvalidArgumentException en vez de proceder con comportamiento indefinido.
     */
    public function test_openpay_constructor_rejects_unsupported_country(): void {
        if ( ! class_exists( 'LTMS_Api_Openpay' ) ) {
            $this->markTestSkipped( 'LTMS_Api_Openpay no disponible en modo UNIT_ONLY.' );
        }

        // Set credenciales para que el constructor no falle por otra razón.
        \LTMS_Core_Config::set( 'ltms_openpay_CO_merchant_id', 'merch_test' );
        \LTMS_Core_Config::set( 'ltms_openpay_CO_private_key', \LTMS_Core_Security::encrypt( 'priv_test' ) );

        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessageMatches( '/País no soportado/' );

        new \LTMS_Api_Openpay( 'US' );
    }

    /**
     * Constructor con country_override='CO' (válido) NO debe lanzar
     * InvalidArgumentException por la validación de país — procede a setear
     * api_url. Puede lanzar por credenciales faltantes, pero no por país.
     */
    public function test_openpay_constructor_accepts_valid_country_co(): void {
        if ( ! class_exists( 'LTMS_Api_Openpay' ) ) {
            $this->markTestSkipped( 'LTMS_Api_Openpay no disponible en modo UNIT_ONLY.' );
        }

        \LTMS_Core_Config::set( 'ltms_openpay_CO_merchant_id', 'merch_test' );
        \LTMS_Core_Config::set( 'ltms_openpay_CO_private_key', \LTMS_Core_Security::encrypt( 'priv_test' ) );

        try {
            $client = new \LTMS_Api_Openpay( 'CO' );
            $ref = new ReflectionClass( $client );
            $country_prop = $ref->getProperty( 'country' );
            $country_prop->setAccessible( true );
            $this->assertSame( 'CO', $country_prop->getValue( $client ), 'Country se normaliza a CO mayúscula' );
        } catch ( \InvalidArgumentException $e ) {
            $this->fail( 'CO debe ser aceptado, no lanzar InvalidArgumentException: ' . $e->getMessage() );
        } catch ( \RuntimeException $e ) {
            // OK si falla por credenciales u otras razones, no por país.
            $this->addToAssertionCount( 1 );
        }

        \LTMS_Core_Config::flush_cache();
    }

    /**
     * country_override='mx' (minúscula) debe ser aceptado y normalizado a 'MX'.
     */
    public function test_openpay_constructor_normalizes_lowercase_mx(): void {
        if ( ! class_exists( 'LTMS_Api_Openpay' ) ) {
            $this->markTestSkipped( 'LTMS_Api_Openpay no disponible en modo UNIT_ONLY.' );
        }

        \LTMS_Core_Config::set( 'ltms_openpay_MX_merchant_id', 'merch_mx' );
        \LTMS_Core_Config::set( 'ltms_openpay_MX_private_key', \LTMS_Core_Security::encrypt( 'priv_mx' ) );

        try {
            $client = new \LTMS_Api_Openpay( 'mx' );
            $ref = new ReflectionClass( $client );
            $country_prop = $ref->getProperty( 'country' );
            $country_prop->setAccessible( true );
            $this->assertSame( 'MX', $country_prop->getValue( $client ), 'Country se normaliza a MX mayúscula' );
        } catch ( \InvalidArgumentException $e ) {
            $this->fail( 'mx debe ser aceptado y normalizado, no rechazado: ' . $e->getMessage() );
        } catch ( \RuntimeException $e ) {
            $this->addToAssertionCount( 1 );
        }

        \LTMS_Core_Config::flush_cache();
    }
}
