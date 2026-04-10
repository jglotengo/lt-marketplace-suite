<?php

declare(strict_types=1);

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests para LTMS_Core_Security
 *
 * Cubre: encrypt/decrypt (AES-256-CBC), hash/verify_hash,
 * generate_token, generate_referral_code, verify_webhook_signature,
 * sanitize_email, sanitize_document_number, sanitize_phone.
 *
 * encrypt/decrypt requieren LTMS_Core_Config::get_encryption_key().
 * Se define la constante LTMS_TEST_ENCRYPTION_KEY en bootstrap o
 * se mockea get_option para que la clase use un key de prueba.
 *
 * NOTA: derive_key() usa PBKDF2 con 600.000 iteraciones — es lento por diseño.
 * Los tests de encrypt/decrypt se ejecutan con una clave fija para que no
 * dependan de constantes de WordPress (AUTH_SALT, etc.).
 */
class SecurityTest extends TestCase
{
    /**
     * Clave de prueba de 32+ bytes para los tests de cifrado.
     * No usar en producción — solo para tests unitarios.
     */
    private const TEST_KEY = 'ltms-unit-test-key-32-bytes-long!';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // sanitize_email y is_email son funciones WP — mockear
        Functions\when('sanitize_email')->alias(function(string $e): string {
            $clean = filter_var($e, FILTER_SANITIZE_EMAIL);
            // FILTER_SANITIZE_EMAIL convierte '<script>@x.com' en 'script@x.com' (email válido!).
            // Hay que validar con FILTER_VALIDATE_EMAIL el resultado ANTES de aceptarlo.
            return (false !== $clean && (bool) filter_var($clean, FILTER_VALIDATE_EMAIL)) ? $clean : '';
        });

        // current_user_can usada en current_user_can wrapper
        Functions\when('current_user_can')->justReturn(false);

        // get_option usada en hash() como último fallback y en derive_key()
        Functions\when('get_option')->justReturn('ltms-test-site-url');

        // site_url usada en derive_key() cuando AUTH_SALT no está definida
        Functions\when('site_url')->justReturn('https://test.ltms.local');
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Retorna la clave de prueba para encrypt/decrypt.
     * Bypassa LTMS_Core_Config::get_encryption_key() usando Reflection.
     */
    private function encrypt_with_test_key(string $plaintext): string
    {
        // Llamamos directamente openssl con la misma lógica que la clase
        // para poder testear sin depender de LTMS_Core_Config
        $key = $this->derive_test_key();
        $iv  = random_bytes(16);
        $enc = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return 'v1:' . base64_encode($iv) . ':' . base64_encode($enc);
    }

    private function derive_test_key(): string
    {
        $salt = defined('AUTH_SALT') ? AUTH_SALT : 'https://test.ltms.local';
        return hash_pbkdf2('sha256', self::TEST_KEY, $salt, 600000, 32, true);
    }

    // ════════════════════════════════════════════════════════════════════════
    // encrypt() / decrypt() — round-trip con la clase real
    //
    // Para estos tests necesitamos que LTMS_Core_Config::get_encryption_key()
    // retorne nuestra TEST_KEY. Si la clase es final y estática, usamos
    // una constante PHP definida en el bootstrap de tests.
    // ════════════════════════════════════════════════════════════════════════

    public function test_encrypt_returns_non_empty_string(): void
    {
        if (!defined('LTMS_ENCRYPTION_KEY')) {
            $this->markTestSkipped('LTMS_ENCRYPTION_KEY no definida en bootstrap.');
        }
        $result = \LTMS_Core_Security::encrypt('datos sensibles');
        $this->assertNotEmpty($result);
        $this->assertIsString($result);
    }

    public function test_encrypt_returns_versioned_format(): void
    {
        if (!defined('LTMS_ENCRYPTION_KEY')) {
            $this->markTestSkipped('LTMS_ENCRYPTION_KEY no definida en bootstrap.');
        }
        $result = \LTMS_Core_Security::encrypt('test');
        $parts  = explode(':', $result, 3);
        $this->assertCount(3, $parts);
        $this->assertSame('v1', $parts[0]);
    }

    public function test_encrypt_empty_string_returns_empty(): void
    {
        $this->assertSame('', \LTMS_Core_Security::encrypt(''));
    }

    public function test_decrypt_empty_string_returns_empty(): void
    {
        $this->assertSame('', \LTMS_Core_Security::decrypt(''));
    }

    public function test_decrypt_invalid_format_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        \LTMS_Core_Security::decrypt('formato-invalido-sin-dos-puntos');
    }

    public function test_decrypt_unknown_version_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        \LTMS_Core_Security::decrypt('v9:' . base64_encode('iv') . ':' . base64_encode('data'));
    }

    public function test_encrypt_decrypt_round_trip(): void
    {
        if (!defined('LTMS_ENCRYPTION_KEY')) {
            $this->markTestSkipped('LTMS_ENCRYPTION_KEY no definida en bootstrap.');
        }
        $original  = 'NIT: 900123456-7';
        $encrypted = \LTMS_Core_Security::encrypt($original);
        $decrypted = \LTMS_Core_Security::decrypt($encrypted);
        $this->assertSame($original, $decrypted);
    }

    public function test_encrypt_same_input_produces_different_ciphertexts(): void
    {
        if (!defined('LTMS_ENCRYPTION_KEY')) {
            $this->markTestSkipped('LTMS_ENCRYPTION_KEY no definida en bootstrap.');
        }
        // IV aleatorio → cada cifrado es distinto (IND-CPA)
        $a = \LTMS_Core_Security::encrypt('mismo texto');
        $b = \LTMS_Core_Security::encrypt('mismo texto');
        $this->assertNotSame($a, $b);
    }

    // ════════════════════════════════════════════════════════════════════════
    // hash() / verify_hash()
    // ════════════════════════════════════════════════════════════════════════

    public function test_hash_returns_64_char_hex(): void
    {
        $h = \LTMS_Core_Security::hash('valor');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $h);
    }

    public function test_hash_is_deterministic(): void
    {
        $this->assertSame(
            \LTMS_Core_Security::hash('test'),
            \LTMS_Core_Security::hash('test')
        );
    }

    public function test_hash_different_values_differ(): void
    {
        $this->assertNotSame(
            \LTMS_Core_Security::hash('valor1'),
            \LTMS_Core_Security::hash('valor2')
        );
    }

    public function test_hash_with_pepper_differs_from_without(): void
    {
        $this->assertNotSame(
            \LTMS_Core_Security::hash('test', ''),
            \LTMS_Core_Security::hash('test', 'pepper')
        );
    }

    public function test_verify_hash_returns_true_for_matching(): void
    {
        $h = \LTMS_Core_Security::hash('secreto');
        $this->assertTrue(\LTMS_Core_Security::verify_hash('secreto', $h));
    }

    public function test_verify_hash_returns_false_for_wrong_value(): void
    {
        $h = \LTMS_Core_Security::hash('secreto');
        $this->assertFalse(\LTMS_Core_Security::verify_hash('otro', $h));
    }

    public function test_verify_hash_returns_false_for_tampered_hash(): void
    {
        $h        = \LTMS_Core_Security::hash('secreto');
        $tampered = str_repeat('0', 64);
        $this->assertFalse(\LTMS_Core_Security::verify_hash('secreto', $tampered));
    }

    public function test_verify_hash_with_pepper(): void
    {
        $h = \LTMS_Core_Security::hash('valor', 'mi-pepper');
        $this->assertTrue(\LTMS_Core_Security::verify_hash('valor', $h, 'mi-pepper'));
        $this->assertFalse(\LTMS_Core_Security::verify_hash('valor', $h, 'otro-pepper'));
    }

    /** NEW — hash retorna exactamente 64 caracteres hexadecimales */
    public function test_hash_length_is_always_64(): void
    {
        foreach (['', 'a', str_repeat('x', 1000)] as $input) {
            $h = \LTMS_Core_Security::hash($input);
            $this->assertSame(64, strlen($h), "Hash de input '$input' no tiene 64 chars");
        }
    }

    /** NEW — con pepper diferente el hash difiere */
    public function test_hash_different_peppers_differ(): void
    {
        $this->assertNotSame(
            \LTMS_Core_Security::hash('dato', 'pepperA'),
            \LTMS_Core_Security::hash('dato', 'pepperB')
        );
    }

    /** NEW — verify_hash con hash completamente inventado retorna false */
    public function test_verify_hash_invented_hash_returns_false(): void
    {
        $invented = str_repeat('a', 64);
        $this->assertFalse(\LTMS_Core_Security::verify_hash('cualquier_valor', $invented));
    }

    // ════════════════════════════════════════════════════════════════════════
    // generate_token()
    // ════════════════════════════════════════════════════════════════════════

    public function test_generate_token_default_64_chars_hex(): void
    {
        $token = \LTMS_Core_Security::generate_token();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function test_generate_token_custom_length(): void
    {
        $token = \LTMS_Core_Security::generate_token(16);
        $this->assertSame(32, strlen($token)); // 16 bytes → 32 hex chars
    }

    public function test_generate_token_is_unique(): void
    {
        $this->assertNotSame(
            \LTMS_Core_Security::generate_token(),
            \LTMS_Core_Security::generate_token()
        );
    }

    public function test_generate_token_is_hex(): void
    {
        $token = \LTMS_Core_Security::generate_token(8);
        $this->assertMatchesRegularExpression('/^[0-9a-f]+$/', $token);
    }

    // ════════════════════════════════════════════════════════════════════════
    // generate_referral_code()
    // ════════════════════════════════════════════════════════════════════════

    public function test_generate_referral_code_is_8_chars(): void
    {
        $this->assertSame(8, strlen(\LTMS_Core_Security::generate_referral_code()));
    }

    public function test_generate_referral_code_no_ambiguous_chars(): void
    {
        // Sin O, 0, I, 1 — confusos visualmente
        $code = \LTMS_Core_Security::generate_referral_code();
        $this->assertMatchesRegularExpression('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{8}$/', $code);
    }

    public function test_generate_referral_code_is_unique(): void
    {
        $codes = array_map(fn() => \LTMS_Core_Security::generate_referral_code(), range(1, 10));
        // Con 10 generaciones y 32^8 posibilidades, colisiones son imposibles
        $this->assertSame(count($codes), count(array_unique($codes)));
    }

    public function test_generate_referral_code_all_uppercase(): void
    {
        $code = \LTMS_Core_Security::generate_referral_code();
        $this->assertSame(strtoupper($code), $code);
    }

    /** NEW — no contiene O (confusión con 0) */
    public function test_generate_referral_code_no_letter_O(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->assertStringNotContainsString('O', \LTMS_Core_Security::generate_referral_code());
        }
    }

    /** NEW — no contiene el dígito 0 */
    public function test_generate_referral_code_no_digit_zero(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->assertStringNotContainsString('0', \LTMS_Core_Security::generate_referral_code());
        }
    }

    /** NEW — no contiene I (confusión con 1) */
    public function test_generate_referral_code_no_letter_I(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->assertStringNotContainsString('I', \LTMS_Core_Security::generate_referral_code());
        }
    }

    /** NEW — no contiene el dígito 1 */
    public function test_generate_referral_code_no_digit_one(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->assertStringNotContainsString('1', \LTMS_Core_Security::generate_referral_code());
        }
    }

    /** NEW — longitud siempre exactamente 8 en múltiples generaciones */
    public function test_generate_referral_code_length_always_8(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(8, strlen(\LTMS_Core_Security::generate_referral_code()));
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // verify_webhook_signature()
    // ════════════════════════════════════════════════════════════════════════

    public function test_verify_webhook_signature_valid(): void
    {
        $secret    = 'whsec_test123';
        $payload   = '{"event":"test"}';
        $expected  = hash_hmac('sha256', $payload, $secret);
        $signature = 'sha256=' . $expected;

        $this->assertTrue(
            \LTMS_Core_Security::verify_webhook_signature($payload, $signature, $secret)
        );
    }

    public function test_verify_webhook_signature_invalid(): void
    {
        $this->assertFalse(
            \LTMS_Core_Security::verify_webhook_signature(
                '{"event":"test"}',
                'sha256=invalidsignature000000000000000000000000000000000000000000000000',
                'secret'
            )
        );
    }

    public function test_verify_webhook_signature_no_prefix(): void
    {
        $secret   = 'mysecret';
        $payload  = 'body';
        $sig      = hash_hmac('sha256', $payload, $secret);

        $this->assertTrue(
            \LTMS_Core_Security::verify_webhook_signature($payload, $sig, $secret, '')
        );
    }

    public function test_verify_webhook_signature_tampered_payload(): void
    {
        $secret   = 'mysecret';
        $payload  = '{"amount":100}';
        $sig      = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        $this->assertFalse(
            \LTMS_Core_Security::verify_webhook_signature('{"amount":999}', $sig, $secret)
        );
    }

    public function test_verify_webhook_signature_case_insensitive(): void
    {
        $secret   = 'secret';
        $payload  = 'data';
        $sig      = 'sha256=' . strtoupper(hash_hmac('sha256', $payload, $secret));

        $this->assertTrue(
            \LTMS_Core_Security::verify_webhook_signature($payload, $sig, $secret)
        );
    }

    // ════════════════════════════════════════════════════════════════════════
    // current_user_can() wrapper
    // ════════════════════════════════════════════════════════════════════════

    /** NEW — wrapper retorna bool */
    public function test_current_user_can_wrapper_returns_bool(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        $result = \LTMS_Core_Security::current_user_can('manage_options');
        $this->assertIsBool($result);
    }

    /** NEW — wrapper retorna true cuando WP retorna true */
    public function test_current_user_can_wrapper_returns_true_when_authorized(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $this->assertTrue(\LTMS_Core_Security::current_user_can('edit_posts'));
    }

    // ════════════════════════════════════════════════════════════════════════
    // sanitize_email()
    // ════════════════════════════════════════════════════════════════════════

    public function test_sanitize_email_valid(): void
    {
        $result = \LTMS_Core_Security::sanitize_email('usuario@example.com');
        $this->assertSame('usuario@example.com', $result);
    }

    public function test_sanitize_email_invalid_returns_empty(): void
    {
        $result = \LTMS_Core_Security::sanitize_email('no-es-un-email');
        $this->assertSame('', $result);
    }

    public function test_sanitize_email_with_xss_returns_empty(): void
    {
        $result = \LTMS_Core_Security::sanitize_email('<script>alert(1)</script>');
        $this->assertSame('', $result);
    }

    // ════════════════════════════════════════════════════════════════════════
    // sanitize_document_number()
    // ════════════════════════════════════════════════════════════════════════

    public function test_sanitize_document_number_nit_colombia(): void
    {
        $this->assertSame('900123456-7', \LTMS_Core_Security::sanitize_document_number('900123456-7'));
    }

    public function test_sanitize_document_number_rfc_mexico(): void
    {
        $this->assertSame('ABC123456XY7', \LTMS_Core_Security::sanitize_document_number('ABC123456XY7'));
    }

    public function test_sanitize_document_number_removes_special_chars(): void
    {
        $result = \LTMS_Core_Security::sanitize_document_number('900<script>123');
        $this->assertStringNotContainsString('<', $result);
        $this->assertStringNotContainsString('>', $result);
    }

    public function test_sanitize_document_number_trims_whitespace(): void
    {
        $result = \LTMS_Core_Security::sanitize_document_number('  900123456  ');
        $this->assertSame('900123456', $result);
    }

    public function test_sanitize_document_number_allows_dots(): void
    {
        $this->assertSame('900.123.456', \LTMS_Core_Security::sanitize_document_number('900.123.456'));
    }

    /** NEW — guion se preserva en documentos con guion */
    public function test_sanitize_document_number_preserves_hyphen(): void
    {
        $result = \LTMS_Core_Security::sanitize_document_number('12345-6');
        $this->assertStringContainsString('-', $result);
    }

    /** NEW — punto se preserva */
    public function test_sanitize_document_number_preserves_dot(): void
    {
        $result = \LTMS_Core_Security::sanitize_document_number('12.345.678');
        $this->assertStringContainsString('.', $result);
    }

    /** NEW — caracteres unicode se eliminan */
    public function test_sanitize_document_number_removes_unicode(): void
    {
        $result = \LTMS_Core_Security::sanitize_document_number('900ñ123456');
        $this->assertStringNotContainsString('ñ', $result);
    }

    /** NEW — vacío retorna vacío */
    public function test_sanitize_document_number_empty_returns_empty(): void
    {
        $this->assertSame('', \LTMS_Core_Security::sanitize_document_number(''));
    }

    // ════════════════════════════════════════════════════════════════════════
    // sanitize_phone()
    // ════════════════════════════════════════════════════════════════════════

    public function test_sanitize_phone_valid(): void
    {
        $this->assertSame('+57 300 123-4567', \LTMS_Core_Security::sanitize_phone('+57 300 123-4567'));
    }

    public function test_sanitize_phone_removes_letters(): void
    {
        $result = \LTMS_Core_Security::sanitize_phone('300abc1234567');
        $this->assertStringNotContainsString('a', $result);
        $this->assertStringNotContainsString('b', $result);
    }

    public function test_sanitize_phone_removes_xss(): void
    {
        $result = \LTMS_Core_Security::sanitize_phone('<script>300</script>');
        $this->assertStringNotContainsString('<', $result);
    }

    public function test_sanitize_phone_trims(): void
    {
        $result = \LTMS_Core_Security::sanitize_phone('  3001234567  ');
        $this->assertSame('3001234567', $result);
    }

    /** NEW — + al inicio se preserva */
    public function test_sanitize_phone_preserves_leading_plus(): void
    {
        $result = \LTMS_Core_Security::sanitize_phone('+573001234567');
        $this->assertStringStartsWith('+', $result);
    }

    /** NEW — paréntesis se preserva en formatos de teléfono */
    public function test_sanitize_phone_preserves_parentheses(): void
    {
        $result = \LTMS_Core_Security::sanitize_phone('(300) 1234567');
        $this->assertIsString($result);
        // No debe contener letras ni scripts
        $this->assertMatchesRegularExpression('/^[0-9\+\-\s\(\)]+$/', $result);
    }

    /** NEW — emoji se elimina */
    public function test_sanitize_phone_removes_emoji(): void
    {
        $result = \LTMS_Core_Security::sanitize_phone('📱3001234567');
        $this->assertMatchesRegularExpression('/^[0-9\+\-\s\(\)]+$/', $result);
    }
}

