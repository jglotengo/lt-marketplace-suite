<?php
/**
 * ZapsignApiTest — Tests unitarios para LTMS_Api_Zapsign
 *
 * Cubre:
 *   1. Constructor — URL fija, api_token descifrado
 *   2. get_provider_slug() — 'zapsign'
 *   3. create_document() — payload: url_pdf vs base64_pdf, format_signers, mapeo respuesta
 *   4. get_document_status() — determina 'completed' cuando todos signed, 'pending' si no
 *   5. download_signed_document() — retorna base64 o vacío
 *   6. delete_document() — retorna bool
 *   7. send_vendor_contract() — lanza excepción sin usuario, guarda user_meta en éxito
 *   8. format_signers (Reflection) — mapeo completo incluyendo whatsapp flag
 *   9. get_default_headers() — Authorization Bearer
 *  10. Reflection — clase final
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
 * @covers LTMS_Api_Zapsign
 */
class ZapsignApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\stubs([
            'get_option'    => static fn(string $k, mixed $d = false): mixed => $d,
            'update_option' => static fn(): bool => true,
            'get_transient' => static fn(): mixed => false,
            'set_transient' => static fn(): bool => true,
        ]);

        \LTMS_Core_Config::flush_cache();
    }

    protected function tearDown(): void
    {
        \LTMS_Core_Config::flush_cache();
        Monkey\tearDown();
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function set_credentials(): void
    {
        \LTMS_Core_Config::set('ltms_zapsign_api_token', \LTMS_Core_Security::encrypt('zap_token_test'));
    }

    private function make_client(): \LTMS_Api_Zapsign
    {
        $this->set_credentials();
        return new \LTMS_Api_Zapsign();
    }

    private function stub_response(mixed $body, int $code = 200): void
    {
        Functions\when('wp_remote_request')->justReturn([]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn($code);
        Functions\when('wp_remote_retrieve_body')->justReturn(json_encode($body));
        Functions\when('wp_json_encode')->alias(static fn(mixed $v): string => json_encode($v));
    }

    // ── Section 1: Constructor ─────────────────────────────────────────────────

    /**
     * @test
     */
    public function test_constructor_decrypts_api_token(): void
    {
        $client = $this->make_client();
        $ref    = new ReflectionClass($client);
        $prop   = $ref->getProperty('api_token');
        $prop->setAccessible(true);
        $this->assertSame('zap_token_test', $prop->getValue($client));
    }

    /**
     * @test
     */
    public function test_constructor_sets_fixed_api_url(): void
    {
        $client = $this->make_client();
        $ref    = (new ReflectionClass($client))->getParentClass()->getProperty('api_url');
        $ref->setAccessible(true);
        $this->assertStringContainsString('zapsign.com.br', $ref->getValue($client));
    }

    // ── Section 2: get_provider_slug ──────────────────────────────────────────

    /**
     * @test
     */
    public function test_get_provider_slug_returns_zapsign(): void
    {
        $this->assertSame('zapsign', $this->make_client()->get_provider_slug());
    }

    // ── Section 3: create_document ────────────────────────────────────────────

    /**
     * @test
     */
    public function test_create_document_sends_url_pdf_when_provided(): void
    {
        $client  = $this->make_client();
        $captured = null;

        Functions\when('wp_remote_request')->alias(
            static function(string $url, array $args) use (&$captured): mixed {
                $captured = $args;
                return new \WP_Error('stop', 'stopped');
            }
        );
        Functions\when('is_wp_error')->justReturn(true);
        Functions\when('wp_json_encode')->alias(static fn(mixed $v): string => json_encode($v));
        Functions\when('get_bloginfo')->justReturn('Mi Tienda');

        try {
            $client->create_document([
                'name'     => 'Contrato Vendedor',
                'pdf_url'  => 'https://cdn.example.com/contract.pdf',
                'signers'  => [['name' => 'Juan García', 'email' => 'juan@test.com', 'phone' => '3001234567']],
            ]);
        } catch (\RuntimeException) {}

        $body = json_decode($captured['body'] ?? '{}', true);
        $this->assertSame('Contrato Vendedor', $body['name']);
        $this->assertSame('https://cdn.example.com/contract.pdf', $body['url_pdf']);
        $this->assertSame('es', $body['lang']);
        $this->assertStringContainsString('LTMS/Contratos/', $body['folder_path']);
    }

    /**
     * @test
     */
    public function test_create_document_uses_base64_when_no_url(): void
    {
        $client  = $this->make_client();
        $captured = null;

        Functions\when('wp_remote_request')->alias(
            static function(string $url, array $args) use (&$captured): mixed {
                $captured = $args;
                return new \WP_Error('stop', 'stopped');
            }
        );
        Functions\when('is_wp_error')->justReturn(true);
        Functions\when('wp_json_encode')->alias(static fn(mixed $v): string => json_encode($v));
        Functions\when('get_bloginfo')->justReturn('Mi Tienda');

        try {
            $client->create_document([
                'name'       => 'Contrato Base64',
                'pdf_base64' => 'JVBERi0xLjQ...',
                'signers'    => [],
            ]);
        } catch (\RuntimeException) {}

        $body = json_decode($captured['body'] ?? '{}', true);
        $this->assertSame('JVBERi0xLjQ...', $body['base64_pdf']);
        $this->assertNull($body['url_pdf']);
    }

    /**
     * @test
     */
    public function test_create_document_returns_success_true_when_token_present(): void
    {
        $client = $this->make_client();
        $this->stub_response([
            'token'   => 'DOC-TOKEN-001',
            'signers' => [['sign_url' => 'https://app.zapsign.com.br/sign/001']],
        ]);
        Functions\when('get_bloginfo')->justReturn('Tienda');

        $result = $client->create_document(['name' => 'Doc', 'pdf_url' => 'https://x.com/f.pdf', 'signers' => []]);
        $this->assertTrue($result['success']);
        $this->assertSame('DOC-TOKEN-001', $result['doc_token']);
        $this->assertStringContainsString('sign', $result['sign_url']);
    }

    /**
     * @test
     */
    public function test_create_document_returns_success_false_when_no_token(): void
    {
        $client = $this->make_client();
        $this->stub_response(['error' => 'invalid pdf']);
        Functions\when('get_bloginfo')->justReturn('Tienda');

        $result = $client->create_document(['name' => 'Doc', 'pdf_url' => '', 'signers' => []]);
        $this->assertFalse($result['success']);
        $this->assertSame('', $result['doc_token']);
    }

    // ── Section 4: get_document_status ────────────────────────────────────────

    /**
     * @test
     */
    public function test_get_document_status_returns_completed_when_all_signed(): void
    {
        $client = $this->make_client();
        $this->stub_response([
            'signers' => [
                ['name' => 'A', 'email' => 'a@x.com', 'status' => 'signed', 'signed_at' => '2025-01-01T10:00:00Z'],
                ['name' => 'B', 'email' => 'b@x.com', 'status' => 'signed', 'signed_at' => '2025-01-01T11:00:00Z'],
            ],
        ]);

        $result = $client->get_document_status('DOC-001');
        $this->assertSame('completed', $result['status']);
        $this->assertCount(2, $result['signers']);
    }

    /**
     * @test
     */
    public function test_get_document_status_returns_pending_when_not_all_signed(): void
    {
        $client = $this->make_client();
        $this->stub_response([
            'signers' => [
                ['name' => 'A', 'email' => 'a@x.com', 'status' => 'signed',  'signed_at' => '2025-01-01T10:00:00Z'],
                ['name' => 'B', 'email' => 'b@x.com', 'status' => 'pending', 'signed_at' => null],
            ],
        ]);

        $result = $client->get_document_status('DOC-002');
        $this->assertSame('pending', $result['status']);
    }

    /**
     * @test
     */
    public function test_get_document_status_returns_pending_when_no_signers(): void
    {
        $client = $this->make_client();
        $this->stub_response(['signers' => []]);

        $result = $client->get_document_status('DOC-003');
        $this->assertSame('pending', $result['status']);
        $this->assertSame([], $result['signers']);
    }

    // ── Section 5: download_signed_document ───────────────────────────────────

    /**
     * @test
     */
    public function test_download_signed_document_returns_base64(): void
    {
        $client = $this->make_client();
        $this->stub_response(['base64_pdf' => 'JVBERi0xLjQsigned...']);

        $this->assertSame('JVBERi0xLjQsigned...', $client->download_signed_document('DOC-001'));
    }

    /**
     * @test
     */
    public function test_download_signed_document_returns_empty_on_missing_key(): void
    {
        $client = $this->make_client();
        $this->stub_response(['status' => 'pending']);

        $this->assertSame('', $client->download_signed_document('DOC-002'));
    }

    // ── Section 6: delete_document ────────────────────────────────────────────

    /**
     * @test
     */
    public function test_delete_document_returns_true_on_empty_response(): void
    {
        $client = $this->make_client();
        $this->stub_response([], 204);

        $this->assertTrue($client->delete_document('DOC-001'));
    }

    /**
     * @test
     */
    public function test_delete_document_returns_true_when_deleted_key_present(): void
    {
        $client = $this->make_client();
        $this->stub_response(['deleted' => true]);

        $this->assertTrue($client->delete_document('DOC-002'));
    }

    // ── Section 7: send_vendor_contract ───────────────────────────────────────

    /**
     * @test
     */
    public function test_send_vendor_contract_throws_when_user_not_found(): void
    {
        $client = $this->make_client();
        Functions\when('get_userdata')->justReturn(false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no encontrado/i');
        $client->send_vendor_contract(999, 'https://cdn.example.com/contract.pdf');
    }

    /**
     * @test
     */
    public function test_send_vendor_contract_saves_meta_on_success(): void
    {
        $client = $this->make_client();

        $fake_user = new \stdClass();
        $fake_user->display_name = 'Juan García';
        $fake_user->user_email   = 'juan@test.com';
        Functions\when('get_userdata')->justReturn($fake_user);
        Functions\when('get_user_meta')->justReturn('3001234567');

        // Stub create_document via wp_remote_request
        $this->stub_response([
            'token'   => 'DOC-CONTRACT-001',
            'signers' => [['sign_url' => 'https://app.zapsign.com.br/sign/contract-001']],
        ]);
        Functions\when('get_bloginfo')->justReturn('Mi Tienda');
        Functions\when('gmdate')->justReturn('2025');

        $meta_calls = [];
        Functions\when('update_user_meta')->alias(
            static function(int $uid, string $key, mixed $val) use (&$meta_calls): bool {
                $meta_calls[$key] = $val;
                return true;
            }
        );
        $result = $client->send_vendor_contract(42, 'https://cdn.example.com/contract.pdf');

        $this->assertTrue($result['success']);
        $this->assertSame('DOC-CONTRACT-001', $meta_calls['ltms_contract_token']);
        $this->assertSame('pending', $meta_calls['ltms_contract_status']);
        $this->assertArrayHasKey('ltms_contract_sent_at', $meta_calls);
    }

    // ── Section 8: format_signers (Reflection) ────────────────────────────────

    /**
     * @test
     */
    public function test_format_signers_maps_all_fields(): void
    {
        $client = $this->make_client();
        $ref    = new ReflectionMethod(\LTMS_Api_Zapsign::class, 'format_signers');
        $ref->setAccessible(true);

        $signers = [['name' => 'Juan García', 'email' => 'juan@test.com', 'phone' => '3001234567', 'phone_country' => '57', 'auth_mode' => 'assinaturaTela']];
        $result  = $ref->invoke($client, $signers);

        $this->assertCount(1, $result);
        $this->assertSame('Juan García', $result[0]['name']);
        $this->assertSame('juan@test.com', $result[0]['email']);
        $this->assertSame('57', $result[0]['phone_country']);
        $this->assertTrue($result[0]['send_automatic_email']);
        $this->assertTrue($result[0]['send_automatic_whatsapp']); // phone is non-empty
    }

    /**
     * @test
     */
    public function test_format_signers_sets_whatsapp_false_when_no_phone(): void
    {
        $client = $this->make_client();
        $ref    = new ReflectionMethod(\LTMS_Api_Zapsign::class, 'format_signers');
        $ref->setAccessible(true);

        $result = $ref->invoke($client, [['name' => 'Sin Teléfono', 'email' => 'x@y.com']]);
        $this->assertFalse($result[0]['send_automatic_whatsapp']);
    }

    /**
     * @test
     */
    public function test_format_signers_strips_non_digits_from_phone(): void
    {
        $client = $this->make_client();
        $ref    = new ReflectionMethod(\LTMS_Api_Zapsign::class, 'format_signers');
        $ref->setAccessible(true);

        $result = $ref->invoke($client, [['name' => 'X', 'email' => 'x@y.com', 'phone' => '+57 300-123-4567']]);
        $this->assertSame('573001234567', $result[0]['phone_number']);
    }

    /**
     * @test
     */
    public function test_format_signers_empty_returns_empty(): void
    {
        $client = $this->make_client();
        $ref    = new ReflectionMethod(\LTMS_Api_Zapsign::class, 'format_signers');
        $ref->setAccessible(true);

        $this->assertSame([], $ref->invoke($client, []));
    }

    // ── Section 9: get_default_headers ────────────────────────────────────────

    /**
     * @test
     */
    public function test_default_headers_include_bearer_authorization(): void
    {
        $client  = $this->make_client();
        $ref     = new ReflectionMethod(\LTMS_Api_Zapsign::class, 'get_default_headers');
        $ref->setAccessible(true);
        $headers = $ref->invoke($client);

        $this->assertArrayHasKey('Authorization', $headers);
        $this->assertSame('Bearer zap_token_test', $headers['Authorization']);
    }

    // ── Section 10: Reflection ────────────────────────────────────────────────

    /**
     * @test
     */
    public function test_class_is_final(): void
    {
        $this->assertTrue((new ReflectionClass(\LTMS_Api_Zapsign::class))->isFinal());
    }

    /**
     * @test
     */
    public function test_api_base_constant_points_to_zapsign(): void
    {
        $ref = new ReflectionClass(\LTMS_Api_Zapsign::class);
        $this->assertStringContainsString('zapsign.com.br', $ref->getConstant('API_BASE'));
    }
}
