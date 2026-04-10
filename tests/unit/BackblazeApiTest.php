<?php
/**
 * BackblazeApiTest — Tests unitarios para LTMS_Api_Backblaze
 *
 * Cubre la lógica pura que no requiere HTTP real:
 *   1. extract_region_from_endpoint() — parseo de región desde URL
 *   2. derive_signing_key()           — derivación HMAC de la clave AWS Sig V4
 *   3. get_signed_url()               — estructura de la presigned URL
 *   4. Constructor guards             — excepción si endpoint no configurado
 *
 * wp_remote_request, upload_file y list_files dependen de HTTP real
 * y se cubren en integración.
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * @covers LTMS_Api_Backblaze
 */
class BackblazeApiTest extends TestCase
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // wp_parse_url delegates to native parse_url
        Functions\when('wp_parse_url')->alias(
            static fn(string $url, int $component = -1): mixed => parse_url($url, $component)
        );

        // Core WP functions needed by LTMS_Core_Config and API clients
        Functions\stubs([
            'get_option'    => static fn(string $k, mixed $d = false): mixed => $d,
            'update_option' => static fn(): bool => true,
            'get_transient' => static fn(): mixed => false,
            'set_transient' => static fn(): bool => true,
        ]);

        // Reset LTMS_Core_Config overrides between tests
        \LTMS_Core_Config::flush_cache();
    }

    protected function tearDown(): void
    {
        \LTMS_Core_Config::flush_cache();
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Builds a Backblaze instance with a given endpoint injected via Config.
     */
    private function make_client( string $endpoint = 'https://s3.us-west-004.backblazeb2.com', string $key_id = 'test_key_id_000000001' ): \LTMS_Api_Backblaze
    {
        \LTMS_Core_Config::set( 'ltms_backblaze_endpoint',       $endpoint );
        \LTMS_Core_Config::set( 'ltms_backblaze_key_id',         $key_id );
        \LTMS_Core_Config::set( 'ltms_backblaze_app_key',        \LTMS_Core_Security::encrypt( 'test_app_key_secret' ) );
        \LTMS_Core_Config::set( 'ltms_backblaze_default_bucket', 'test-public-bucket' );
        \LTMS_Core_Config::set( 'ltms_backblaze_private_bucket', 'test-private-bucket' );

        return new \LTMS_Api_Backblaze();
    }

    // ── Section 1: Constructor ─────────────────────────────────────────────────

    /**
     * @test
     */
    public function test_constructor_throws_when_endpoint_not_configured(): void
    {
        // No endpoint set → Config returns ''
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/ltms_backblaze_endpoint/i');

        new \LTMS_Api_Backblaze();
    }

    /**
     * @test
     */
    public function test_constructor_succeeds_with_endpoint_configured(): void
    {
        $client = $this->make_client();
        $this->assertInstanceOf(\LTMS_Api_Backblaze::class, $client);
    }

    /**
     * @test
     */
    public function test_provider_slug_is_backblaze(): void
    {
        $client = $this->make_client();
        $this->assertSame('backblaze', $client->get_provider_slug());
    }

    // ── Section 2: extract_region_from_endpoint() ──────────────────────────────

    /**
     * @test
     * @dataProvider endpoint_region_provider
     */
    public function test_extract_region_from_endpoint( string $endpoint, string $expected_region ): void
    {
        $client = $this->make_client( $endpoint );

        $ref    = new ReflectionMethod($client, 'extract_region_from_endpoint');
        $ref->setAccessible(true);

        $result = $ref->invoke($client, $endpoint);

        $this->assertSame($expected_region, $result);
    }

    public static function endpoint_region_provider(): array
    {
        return [
            'standard us-west-004'        => ['https://s3.us-west-004.backblazeb2.com',       'us-west-004'],
            'eu-central-003'               => ['https://s3.eu-central-003.backblazeb2.com',    'eu-central-003'],
            'us-east-005'                  => ['https://s3.us-east-005.backblazeb2.com',       'us-east-005'],
            'alternative prefix pattern'  => ['https://us-west-004.backblazeb2.com',           'us-west-004'],
            'no backblaze pattern → default' => ['https://custom.storage.example.com',         'us-west-004'],
            'empty host → default'         => ['not-a-url',                                    'us-west-004'],
        ];
    }

    // ── Section 3: derive_signing_key() ───────────────────────────────────────

    /**
     * @test
     * The signing key is derived from: HMAC(HMAC(HMAC(HMAC("AWS4"+app_key, date), region), service), "aws4_request")
     * We verify it is a 32-byte binary string (256-bit key).
     */
    public function test_derive_signing_key_returns_32_byte_binary(): void
    {
        $client = $this->make_client();
        $ref    = new ReflectionMethod($client, 'derive_signing_key');
        $ref->setAccessible(true);

        $key = $ref->invoke($client, gmdate('Ymd'));

        $this->assertSame(32, strlen($key), 'Signing key must be 32 bytes for AES-256');
    }

    /**
     * @test
     * Two calls with the same date must return identical keys (deterministic).
     */
    public function test_derive_signing_key_is_deterministic(): void
    {
        $client = $this->make_client();
        $ref    = new ReflectionMethod($client, 'derive_signing_key');
        $ref->setAccessible(true);

        $date = '20240315';
        $key1 = $ref->invoke($client, $date);
        $key2 = $ref->invoke($client, $date);

        $this->assertSame($key1, $key2);
    }

    /**
     * @test
     * Keys for different dates must differ.
     */
    public function test_derive_signing_key_differs_across_dates(): void
    {
        $client = $this->make_client();
        $ref    = new ReflectionMethod($client, 'derive_signing_key');
        $ref->setAccessible(true);

        $key_a = $ref->invoke($client, '20240315');
        $key_b = $ref->invoke($client, '20240316');

        $this->assertNotSame(bin2hex($key_a), bin2hex($key_b));
    }

    /**
     * @test
     * Keys for different services (s3 vs glacier) must differ.
     */
    public function test_derive_signing_key_differs_by_service(): void
    {
        $client = $this->make_client();
        $ref    = new ReflectionMethod($client, 'derive_signing_key');
        $ref->setAccessible(true);

        $date    = '20240315';
        $key_s3  = $ref->invoke($client, $date, 's3');
        $key_glc = $ref->invoke($client, $date, 'glacier');

        $this->assertNotSame(bin2hex($key_s3), bin2hex($key_glc));
    }

    /**
     * @test
     * Two clients with different app_keys must produce different signing keys.
     */
    public function test_derive_signing_key_differs_by_app_key(): void
    {
        $client_a = $this->make_client();

        \LTMS_Core_Config::flush_cache();
        \LTMS_Core_Config::set( 'ltms_backblaze_endpoint',       'https://s3.us-west-004.backblazeb2.com' );
        \LTMS_Core_Config::set( 'ltms_backblaze_key_id',         'other_key_id' );
        \LTMS_Core_Config::set( 'ltms_backblaze_app_key',        \LTMS_Core_Security::encrypt( 'DIFFERENT_APP_KEY_SECRET' ) );
        \LTMS_Core_Config::set( 'ltms_backblaze_default_bucket', 'test-bucket' );
        \LTMS_Core_Config::set( 'ltms_backblaze_private_bucket', 'private-bucket' );
        $client_b = new \LTMS_Api_Backblaze();

        $ref = new ReflectionMethod($client_a, 'derive_signing_key');
        $ref->setAccessible(true);

        $date  = '20240315';
        $key_a = $ref->invoke($client_a, $date);
        $key_b = $ref->invoke($client_b, $date);

        $this->assertNotSame(bin2hex($key_a), bin2hex($key_b));
    }

    // ── Section 4: get_signed_url() ───────────────────────────────────────────

    /**
     * @test
     * The presigned URL must start with the endpoint + path.
     */
    public function test_get_signed_url_starts_with_endpoint_and_path(): void
    {
        $endpoint = 'https://s3.us-west-004.backblazeb2.com';
        $client   = $this->make_client($endpoint);

        $url = $client->get_signed_url('my-bucket', 'images/photo.jpg');

        $this->assertStringStartsWith($endpoint . '/my-bucket/images/photo.jpg', $url);
    }

    /**
     * @test
     * The URL must contain all required AWS Sig V4 query parameters.
     */
    public function test_get_signed_url_contains_required_sigv4_params(): void
    {
        $client = $this->make_client();
        $url    = $client->get_signed_url('test-bucket', 'file.pdf');

        $this->assertStringContainsString('X-Amz-Algorithm=AWS4-HMAC-SHA256', $url);
        $this->assertStringContainsString('X-Amz-Credential=', $url);
        $this->assertStringContainsString('X-Amz-Date=', $url);
        $this->assertStringContainsString('X-Amz-Expires=', $url);
        $this->assertStringContainsString('X-Amz-SignedHeaders=host', $url);
        $this->assertStringContainsString('X-Amz-Signature=', $url);
    }

    /**
     * @test
     * The default TTL of 3600 seconds appears in the URL.
     */
    public function test_get_signed_url_default_ttl_is_3600(): void
    {
        $client = $this->make_client();
        $url    = $client->get_signed_url('test-bucket', 'file.txt');

        $this->assertStringContainsString('X-Amz-Expires=3600', $url);
    }

    /**
     * @test
     * A custom TTL is reflected in the presigned URL.
     */
    public function test_get_signed_url_custom_ttl(): void
    {
        $client = $this->make_client();
        $url    = $client->get_signed_url('test-bucket', 'file.txt', 7200);

        $this->assertStringContainsString('X-Amz-Expires=7200', $url);
    }

    /**
     * @test
     * The X-Amz-Credential must embed the key_id and region.
     */
    public function test_get_signed_url_credential_contains_key_id_and_region(): void
    {
        // Build client with custom key_id via make_client parameter
        $client = $this->make_client('https://s3.eu-central-003.backblazeb2.com', 'MY_KEY_ID_ABCDEF');

        $url = $client->get_signed_url('bucket', 'object');

        $this->assertStringContainsString('MY_KEY_ID_ABCDEF', urldecode($url));
        $this->assertStringContainsString('eu-central-003', urldecode($url));
    }

    /**
     * @test
     * The signature is a 64-character hex string (SHA-256 output).
     */
    public function test_get_signed_url_signature_is_64_hex_chars(): void
    {
        $client = $this->make_client();
        $url    = $client->get_signed_url('test-bucket', 'file.txt');

        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        $this->assertArrayHasKey('X-Amz-Signature', $params);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $params['X-Amz-Signature']);
    }

    /**
     * @test
     * Two presigned URLs for different keys must have different signatures.
     */
    public function test_get_signed_url_differs_by_object_key(): void
    {
        $client = $this->make_client();
        $url_a  = $client->get_signed_url('bucket', 'file_a.txt');
        $url_b  = $client->get_signed_url('bucket', 'file_b.txt');

        parse_str(parse_url($url_a, PHP_URL_QUERY), $params_a);
        parse_str(parse_url($url_b, PHP_URL_QUERY), $params_b);

        $this->assertNotSame($params_a['X-Amz-Signature'], $params_b['X-Amz-Signature']);
    }

    /**
     * @test
     * Two presigned URLs for different buckets must have different signatures.
     */
    public function test_get_signed_url_differs_by_bucket(): void
    {
        $client = $this->make_client();
        $url_a  = $client->get_signed_url('bucket-alpha', 'file.txt');
        $url_b  = $client->get_signed_url('bucket-beta',  'file.txt');

        parse_str(parse_url($url_a, PHP_URL_QUERY), $params_a);
        parse_str(parse_url($url_b, PHP_URL_QUERY), $params_b);

        $this->assertNotSame($params_a['X-Amz-Signature'], $params_b['X-Amz-Signature']);
    }

    // ── Section 5: Reflection — structure checks ───────────────────────────────

    /**
     * @test
     */
    public function test_class_extends_abstract_api_client(): void
    {
        $ref = new ReflectionClass(\LTMS_Api_Backblaze::class);
        $this->assertSame('LTMS_Abstract_API_Client', $ref->getParentClass()->getName());
    }

    /**
     * @test
     */
    public function test_sign_request_is_private(): void
    {
        $ref = new ReflectionMethod(\LTMS_Api_Backblaze::class, 'sign_request');
        $this->assertTrue($ref->isPrivate());
    }

    /**
     * @test
     */
    public function test_derive_signing_key_is_private(): void
    {
        $ref = new ReflectionMethod(\LTMS_Api_Backblaze::class, 'derive_signing_key');
        $this->assertTrue($ref->isPrivate());
    }

    /**
     * @test
     */
    public function test_extract_region_is_private(): void
    {
        $ref = new ReflectionMethod(\LTMS_Api_Backblaze::class, 'extract_region_from_endpoint');
        $this->assertTrue($ref->isPrivate());
    }
}

