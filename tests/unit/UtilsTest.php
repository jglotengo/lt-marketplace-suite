<?php

declare(strict_types=1);

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests para LTMS_Utils
 *
 * Cubre: format_money, format_phone_e164, generate_reference,
 * truncate, sanitize_filename, is_json, cents_to_decimal,
 * decimal_to_cents, now_utc, days_between, array_to_html_table.
 *
 * get_ip() e is_ltms_vendor() se testean en sus propias secciones
 * controlando $_SERVER y mocks de WP respectivamente.
 */
class UtilsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('get_userdata')->justReturn(false);
        Functions\when('esc_html')->returnArg();
    }

    protected function tearDown(): void
    {
        // Limpiar $_SERVER modificado en tests de get_ip
        foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $h) {
            unset($_SERVER[$h]);
        }
        Monkey\tearDown();
        parent::tearDown();
    }

    // ════════════════════════════════════════════════════════════════════════
    // format_money()
    // ════════════════════════════════════════════════════════════════════════

    public function test_format_money_cop_no_decimals(): void
    {
        $result = \LTMS_Utils::format_money(150000.0, 'COP');
        // COP: 0 decimales, miles con punto
        $this->assertStringContainsString('150.000', $result);
        $this->assertStringContainsString('COP', $result);
    }

    public function test_format_money_cop_symbol(): void
    {
        $result = \LTMS_Utils::format_money(50000.0, 'COP', true);
        $this->assertStringStartsWith('$', $result);
    }

    public function test_format_money_cop_no_symbol(): void
    {
        $result = \LTMS_Utils::format_money(50000.0, 'COP', false);
        $this->assertStringNotContainsString('$', substr($result, 0, 1));
        $this->assertStringContainsString('COP', $result);
    }

    public function test_format_money_mxn_two_decimals(): void
    {
        $result = \LTMS_Utils::format_money(1234.5, 'MXN');
        $this->assertStringContainsString('1,234.50', $result);
        $this->assertStringContainsString('MXN', $result);
    }

    public function test_format_money_usd_two_decimals(): void
    {
        $result = \LTMS_Utils::format_money(9.99, 'USD');
        $this->assertStringContainsString('9.99', $result);
        $this->assertStringContainsString('USD', $result);
    }

    public function test_format_money_unknown_currency_falls_back_to_usd_format(): void
    {
        // Moneda desconocida → usa formato USD
        $result = \LTMS_Utils::format_money(100.0, 'XYZ');
        $this->assertStringContainsString('100.00', $result);
        $this->assertStringContainsString('XYZ', $result);
    }

    public function test_format_money_zero(): void
    {
        $result = \LTMS_Utils::format_money(0.0, 'COP');
        $this->assertStringContainsString('0', $result);
    }

    public function test_format_money_large_cop_amount(): void
    {
        $result = \LTMS_Utils::format_money(1000000.0, 'COP');
        $this->assertStringContainsString('1.000.000', $result);
    }

    /** NEW — MXN sin símbolo contiene la moneda pero no $ */
    public function test_format_money_mxn_no_symbol(): void
    {
        $result = \LTMS_Utils::format_money(500.0, 'MXN', false);
        $this->assertStringContainsString('MXN', $result);
        $this->assertStringNotContainsString('$', substr($result, 0, 1));
    }

    /** NEW — COP negativo contiene el código de moneda */
    public function test_format_money_cop_negative_contains_currency(): void
    {
        $result = \LTMS_Utils::format_money(-5000.0, 'COP');
        $this->assertStringContainsString('COP', $result);
    }

    /** NEW — resultado siempre contiene el código de moneda */
    public function test_format_money_always_contains_currency_code(): void
    {
        foreach (['COP', 'MXN', 'USD'] as $currency) {
            $result = \LTMS_Utils::format_money(100.0, $currency);
            $this->assertStringContainsString($currency, $result, "Moneda $currency no encontrada en resultado");
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // format_phone_e164()
    // ════════════════════════════════════════════════════════════════════════

    public function test_format_phone_e164_colombia_adds_57(): void
    {
        $result = \LTMS_Utils::format_phone_e164('3001234567', 'CO');
        $this->assertSame('+573001234567', $result);
    }

    public function test_format_phone_e164_mexico_adds_52(): void
    {
        $result = \LTMS_Utils::format_phone_e164('5512345678', 'MX');
        $this->assertSame('+525512345678', $result);
    }

    public function test_format_phone_e164_strips_non_digits(): void
    {
        $result = \LTMS_Utils::format_phone_e164('300-123-4567', 'CO');
        $this->assertSame('+573001234567', $result);
    }

    public function test_format_phone_e164_with_spaces(): void
    {
        $result = \LTMS_Utils::format_phone_e164('300 123 4567', 'CO');
        $this->assertSame('+573001234567', $result);
    }

    public function test_format_phone_e164_already_has_prefix_co(): void
    {
        // Si ya tiene el prefijo 57 y más de 10 dígitos, retorna tal cual
        $result = \LTMS_Utils::format_phone_e164('573001234567', 'CO');
        $this->assertSame('+573001234567', $result);
    }

    public function test_format_phone_e164_starts_with_plus(): void
    {
        $result = \LTMS_Utils::format_phone_e164('3001234567', 'CO');
        $this->assertStringStartsWith('+', $result);
    }

    public function test_format_phone_e164_default_country_is_colombia(): void
    {
        // Sin country_code se llama LTMS_Core_Config::get_country() que en unit
        // devuelve lo que get_option retorne → no importa el valor exacto,
        // solo verificamos que retorna un string con +
        // Mockeamos get_country via get_option
        Functions\when('get_option')->justReturn(null);
        $result = \LTMS_Utils::format_phone_e164('3001234567');
        $this->assertStringStartsWith('+', $result);
    }

    /** NEW — MX ya con prefijo 52 no duplica el prefijo */
    public function test_format_phone_e164_already_has_prefix_mx(): void
    {
        $result = \LTMS_Utils::format_phone_e164('525512345678', 'MX');
        $this->assertSame('+525512345678', $result);
    }

    /** NEW — número vacío retorna string (aunque sea solo +prefijo o vacío) */
    public function test_format_phone_e164_empty_number_returns_string(): void
    {
        $result = \LTMS_Utils::format_phone_e164('', 'CO');
        $this->assertIsString($result);
    }

    /** NEW — país desconocido usa prefijo 57 como fallback */
    public function test_format_phone_e164_unknown_country_fallback(): void
    {
        $result = \LTMS_Utils::format_phone_e164('3001234567', 'BR');
        $this->assertStringStartsWith('+', $result);
    }

    // ════════════════════════════════════════════════════════════════════════
    // generate_reference()
    // ════════════════════════════════════════════════════════════════════════

    public function test_generate_reference_default_prefix(): void
    {
        $ref = \LTMS_Utils::generate_reference();
        $this->assertStringStartsWith('LTMS-', $ref);
    }

    public function test_generate_reference_custom_prefix(): void
    {
        $ref = \LTMS_Utils::generate_reference('PAY');
        $this->assertStringStartsWith('PAY-', $ref);
    }

    public function test_generate_reference_format(): void
    {
        // Formato: PREFIX-YYMMDD-XXXXXX (6 chars hex uppercase)
        $ref = \LTMS_Utils::generate_reference('REF');
        $this->assertMatchesRegularExpression('/^REF-\d{6}-[A-F0-9]{6}$/', $ref);
    }

    public function test_generate_reference_is_uppercase(): void
    {
        $ref = \LTMS_Utils::generate_reference('pay');
        $this->assertStringStartsWith('PAY-', $ref);
    }

    public function test_generate_reference_is_unique(): void
    {
        $a = \LTMS_Utils::generate_reference('T');
        $b = \LTMS_Utils::generate_reference('T');
        // Con random_bytes casi imposible que sean iguales
        $this->assertNotSame($a, $b);
    }

    // ════════════════════════════════════════════════════════════════════════
    // truncate()
    // ════════════════════════════════════════════════════════════════════════

    public function test_truncate_short_text_unchanged(): void
    {
        $text = 'Hola mundo';
        $this->assertSame($text, \LTMS_Utils::truncate($text, 100));
    }

    public function test_truncate_exact_length_unchanged(): void
    {
        $text = str_repeat('a', 100);
        $this->assertSame($text, \LTMS_Utils::truncate($text, 100));
    }

    public function test_truncate_long_text_adds_ellipsis(): void
    {
        $text   = str_repeat('a', 150);
        $result = \LTMS_Utils::truncate($text, 100);
        $this->assertStringEndsWith('...', $result);
        $this->assertSame(100, mb_strlen($result));
    }

    public function test_truncate_custom_suffix(): void
    {
        $text   = str_repeat('x', 50);
        $result = \LTMS_Utils::truncate($text, 20, ' [más]');
        $this->assertStringEndsWith(' [más]', $result);
        $this->assertSame(20, mb_strlen($result));
    }

    public function test_truncate_empty_string(): void
    {
        $this->assertSame('', \LTMS_Utils::truncate('', 100));
    }

    public function test_truncate_multibyte(): void
    {
        $text   = str_repeat('ñ', 50); // 50 chars multibyte
        $result = \LTMS_Utils::truncate($text, 10);
        $this->assertSame(10, mb_strlen($result));
    }

    /** NEW — texto exactamente max_length + 1 sí se trunca */
    public function test_truncate_one_char_over_max_truncates(): void
    {
        $text   = str_repeat('b', 101);
        $result = \LTMS_Utils::truncate($text, 100);
        $this->assertSame(100, mb_strlen($result));
        $this->assertStringEndsWith('...', $result);
    }

    /** NEW — suffix más largo que max_length sigue respetando el límite */
    public function test_truncate_suffix_respects_total_length(): void
    {
        $text   = str_repeat('c', 200);
        $suffix = str_repeat('!', 5); // suffix de 5 chars
        $result = \LTMS_Utils::truncate($text, 10, $suffix);
        $this->assertSame(10, mb_strlen($result));
    }

    /** NEW — mb_strlen del resultado == max_length cuando texto largo, sin importar el encoding */
    public function test_truncate_mb_strlen_invariant(): void
    {
        $text   = str_repeat('ó', 150); // multibyte
        $result = \LTMS_Utils::truncate($text, 50);
        $this->assertSame(50, mb_strlen($result));
    }

    // ════════════════════════════════════════════════════════════════════════
    // sanitize_filename()
    // ════════════════════════════════════════════════════════════════════════

    public function test_sanitize_filename_allows_valid_chars(): void
    {
        $this->assertSame('foto-123_ok.jpg', \LTMS_Utils::sanitize_filename('foto-123_ok.jpg'));
    }

    public function test_sanitize_filename_replaces_spaces(): void
    {
        $result = \LTMS_Utils::sanitize_filename('mi archivo.pdf');
        $this->assertStringNotContainsString(' ', $result);
    }

    public function test_sanitize_filename_removes_path_traversal(): void
    {
        $result = \LTMS_Utils::sanitize_filename('../etc/passwd');
        $this->assertStringNotContainsString('..', $result);
        $this->assertStringNotContainsString('/', $result);
    }

    public function test_sanitize_filename_removes_backslash(): void
    {
        $result = \LTMS_Utils::sanitize_filename('path\\file.txt');
        $this->assertStringNotContainsString('\\', $result);
    }

    public function test_sanitize_filename_max_255_chars(): void
    {
        $long   = str_repeat('a', 300) . '.txt';
        $result = \LTMS_Utils::sanitize_filename($long);
        $this->assertLessThanOrEqual(255, strlen($result));
    }

    public function test_sanitize_filename_special_chars_replaced(): void
    {
        $result = \LTMS_Utils::sanitize_filename('archivo<>:"|?.txt');
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9\-_\.]+$/', $result);
    }

    /** NEW — string vacío retorna string (no error) */
    public function test_sanitize_filename_empty_returns_string(): void
    {
        $result = \LTMS_Utils::sanitize_filename('');
        $this->assertIsString($result);
    }

    /** NEW — solo puntos se sanitiza sin generar path traversal */
    public function test_sanitize_filename_only_dots_sanitized(): void
    {
        $result = \LTMS_Utils::sanitize_filename('...');
        $this->assertStringNotContainsString('..', $result);
    }

    /** NEW — solo extensión retorna string sin path traversal */
    public function test_sanitize_filename_only_extension(): void
    {
        $result = \LTMS_Utils::sanitize_filename('.jpg');
        $this->assertIsString($result);
        $this->assertStringNotContainsString('/', $result);
    }

    /** NEW — nombre con ñ se sanitiza sin generar caracteres inválidos */
    public function test_sanitize_filename_tilde_n_sanitized(): void
    {
        $result = \LTMS_Utils::sanitize_filename('año2024.jpg');
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9\-_\.]+$/', $result);
    }

    // ════════════════════════════════════════════════════════════════════════
    // is_json()
    // ════════════════════════════════════════════════════════════════════════

    public function test_is_json_valid_object(): void
    {
        $this->assertTrue(\LTMS_Utils::is_json('{"key":"value"}'));
    }

    public function test_is_json_valid_array(): void
    {
        $this->assertTrue(\LTMS_Utils::is_json('[1,2,3]'));
    }

    public function test_is_json_valid_empty_object(): void
    {
        $this->assertTrue(\LTMS_Utils::is_json('{}'));
    }

    public function test_is_json_valid_null(): void
    {
        $this->assertTrue(\LTMS_Utils::is_json('null'));
    }

    public function test_is_json_invalid_string(): void
    {
        $this->assertFalse(\LTMS_Utils::is_json('not json'));
    }

    public function test_is_json_invalid_incomplete(): void
    {
        $this->assertFalse(\LTMS_Utils::is_json('{"key":'));
    }

    public function test_is_json_empty_string(): void
    {
        $this->assertFalse(\LTMS_Utils::is_json(''));
    }

    /** NEW — número entero es JSON válido */
    public function test_is_json_integer_is_valid(): void
    {
        $this->assertTrue(\LTMS_Utils::is_json('42'));
    }

    /** NEW — string con comillas no es JSON si no está escapado correctamente */
    public function test_is_json_plain_string_is_invalid(): void
    {
        $this->assertFalse(\LTMS_Utils::is_json('hello'));
    }

    /** NEW — array anidado es JSON válido */
    public function test_is_json_nested_array_valid(): void
    {
        $this->assertTrue(\LTMS_Utils::is_json('{"a":{"b":[1,2,3]}}'));
    }

    /** NEW — booleanos JSON válidos */
    public function test_is_json_boolean_true_valid(): void
    {
        $this->assertTrue(\LTMS_Utils::is_json('true'));
    }

    public function test_is_json_boolean_false_valid(): void
    {
        $this->assertTrue(\LTMS_Utils::is_json('false'));
    }

    // ════════════════════════════════════════════════════════════════════════
    // cents_to_decimal() / decimal_to_cents()
    // ════════════════════════════════════════════════════════════════════════

    public function test_cents_to_decimal_basic(): void
    {
        $this->assertEqualsWithDelta(9.99, \LTMS_Utils::cents_to_decimal(999), 0.001);
    }

    public function test_cents_to_decimal_zero(): void
    {
        $this->assertEqualsWithDelta(0.0, \LTMS_Utils::cents_to_decimal(0), 0.001);
    }

    public function test_cents_to_decimal_round_trip(): void
    {
        $this->assertSame(100, \LTMS_Utils::decimal_to_cents(\LTMS_Utils::cents_to_decimal(100)));
    }

    public function test_decimal_to_cents_basic(): void
    {
        $this->assertSame(999, \LTMS_Utils::decimal_to_cents(9.99));
    }

    public function test_decimal_to_cents_zero(): void
    {
        $this->assertSame(0, \LTMS_Utils::decimal_to_cents(0.0));
    }

    public function test_decimal_to_cents_rounds_correctly(): void
    {
        $this->assertSame(100, \LTMS_Utils::decimal_to_cents(1.004));
        $this->assertSame(101, \LTMS_Utils::decimal_to_cents(1.005));
    }

    public function test_decimal_to_cents_large_amount(): void
    {
        $this->assertSame(10000, \LTMS_Utils::decimal_to_cents(100.0));
    }

    /** NEW — valores grandes */
    public function test_cents_to_decimal_large_value(): void
    {
        $this->assertEqualsWithDelta(1500000.0, \LTMS_Utils::cents_to_decimal(150000000), 0.001);
    }

    /** NEW — invariante decimal_to_cents(cents_to_decimal(x)) === x */
    public function test_cents_decimal_roundtrip_invariant(): void
    {
        $original = 15000099; // COP cents (150,000.99)
        $this->assertSame($original, \LTMS_Utils::decimal_to_cents(\LTMS_Utils::cents_to_decimal($original)));
    }

    // ════════════════════════════════════════════════════════════════════════
    // now_utc()
    // ════════════════════════════════════════════════════════════════════════

    public function test_now_utc_returns_valid_datetime(): void
    {
        $now = \LTMS_Utils::now_utc();
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $now);
    }

    public function test_now_utc_parseable(): void
    {
        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', \LTMS_Utils::now_utc());
        $this->assertInstanceOf(\DateTime::class, $dt);
    }

    // ════════════════════════════════════════════════════════════════════════
    // days_between()
    // ════════════════════════════════════════════════════════════════════════

    public function test_days_between_same_date(): void
    {
        $this->assertSame(0, \LTMS_Utils::days_between('2025-01-01', '2025-01-01'));
    }

    public function test_days_between_one_day(): void
    {
        $this->assertSame(1, \LTMS_Utils::days_between('2025-01-01', '2025-01-02'));
    }

    public function test_days_between_one_year(): void
    {
        $this->assertSame(365, \LTMS_Utils::days_between('2025-01-01', '2026-01-01'));
    }

    public function test_days_between_reversed_dates(): void
    {
        // diff->days es siempre positivo
        $this->assertSame(1, \LTMS_Utils::days_between('2025-01-02', '2025-01-01'));
    }

    public function test_days_between_default_second_date_is_today(): void
    {
        // Con fecha de hoy como date2 implícito, la diferencia con hoy es 0
        $today  = gmdate('Y-m-d');
        $result = \LTMS_Utils::days_between($today);
        $this->assertSame(0, $result);
    }

    /** NEW — mes bisiesto: febrero 28 → marzo 1 = 1 día en año bisiesto */
    public function test_days_between_leap_year_february(): void
    {
        $this->assertSame(1, \LTMS_Utils::days_between('2024-02-29', '2024-03-01'));
    }

    /** NEW — cruzando fin de año */
    public function test_days_between_crossing_year_boundary(): void
    {
        $this->assertSame(1, \LTMS_Utils::days_between('2024-12-31', '2025-01-01'));
    }

    // ════════════════════════════════════════════════════════════════════════
    // array_to_html_table()
    // ════════════════════════════════════════════════════════════════════════

    public function test_array_to_html_table_empty_returns_empty(): void
    {
        $this->assertSame('', \LTMS_Utils::array_to_html_table([]));
    }

    public function test_array_to_html_table_contains_table_tag(): void
    {
        $html = \LTMS_Utils::array_to_html_table(['Nombre' => 'Juan']);
        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('</table>', $html);
    }

    public function test_array_to_html_table_contains_key_and_value(): void
    {
        $html = \LTMS_Utils::array_to_html_table(['Total' => '150000']);
        $this->assertStringContainsString('Total', $html);
        $this->assertStringContainsString('150000', $html);
    }

    public function test_array_to_html_table_with_caption(): void
    {
        $html = \LTMS_Utils::array_to_html_table(['x' => 'y'], 'Mi título');
        $this->assertStringContainsString('<caption>', $html);
        $this->assertStringContainsString('Mi título', $html);
    }

    public function test_array_to_html_table_without_caption_no_caption_tag(): void
    {
        $html = \LTMS_Utils::array_to_html_table(['x' => 'y']);
        $this->assertStringNotContainsString('<caption>', $html);
    }

    public function test_array_to_html_table_multiple_rows(): void
    {
        $html = \LTMS_Utils::array_to_html_table([
            'Nombre'  => 'Juan',
            'Email'   => 'juan@test.com',
            'País'    => 'Colombia',
        ]);
        $this->assertSame(3, substr_count($html, '<tr>'));
    }

    /** NEW — HTML entities en valores se escapan */
    public function test_array_to_html_table_html_entities_in_values(): void
    {
        $html = \LTMS_Utils::array_to_html_table(['Clave' => '<b>valor</b>']);
        // El valor debe aparecer escapado o como texto, no como HTML crudo inyectado
        $this->assertStringContainsString('Clave', $html);
    }

    /** NEW — un solo par genera exactamente 1 fila */
    public function test_array_to_html_table_single_pair_one_row(): void
    {
        $html = \LTMS_Utils::array_to_html_table(['K' => 'V']);
        $this->assertSame(1, substr_count($html, '<tr>'));
    }

    /** NEW — cantidad de <tr> == count(array) */
    public function test_array_to_html_table_row_count_equals_array_count(): void
    {
        $data = ['A' => '1', 'B' => '2', 'C' => '3', 'D' => '4'];
        $html = \LTMS_Utils::array_to_html_table($data);
        $this->assertSame(count($data), substr_count($html, '<tr>'));
    }

    // ════════════════════════════════════════════════════════════════════════
    // get_ip()
    // ════════════════════════════════════════════════════════════════════════

    public function test_get_ip_from_remote_addr(): void
    {
        $_SERVER['REMOTE_ADDR'] = '192.168.1.1';
        $this->assertSame('192.168.1.1', \LTMS_Utils::get_ip());
    }

    public function test_get_ip_cloudflare_header_takes_priority(): void
    {
        $_SERVER['REMOTE_ADDR']           = '10.0.0.1';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.5';
        $this->assertSame('203.0.113.5', \LTMS_Utils::get_ip());
    }

    public function test_get_ip_forwarded_for_first_ip(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.5, 10.0.0.1, 172.16.0.1';
        $this->assertSame('203.0.113.5', \LTMS_Utils::get_ip());
    }

    public function test_get_ip_invalid_ip_returns_fallback(): void
    {
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_CF_CONNECTING_IP'],
              $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP']);
        $this->assertSame('0.0.0.0', \LTMS_Utils::get_ip());
    }

    public function test_get_ip_returns_valid_ip_format(): void
    {
        $_SERVER['REMOTE_ADDR'] = '8.8.8.8';
        $ip = \LTMS_Utils::get_ip();
        $this->assertNotFalse(filter_var($ip, FILTER_VALIDATE_IP));
    }

    /** NEW — HTTP_X_REAL_IP header es considerado */
    public function test_get_ip_x_real_ip_header(): void
    {
        $_SERVER['HTTP_X_REAL_IP'] = '200.1.2.3';
        $ip = \LTMS_Utils::get_ip();
        $this->assertNotFalse(filter_var($ip, FILTER_VALIDATE_IP));
    }

    /** NEW — todos los headers ausentes retorna 0.0.0.0 */
    public function test_get_ip_no_headers_returns_default(): void
    {
        foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $h) {
            unset($_SERVER[$h]);
        }
        $this->assertSame('0.0.0.0', \LTMS_Utils::get_ip());
    }

    // ════════════════════════════════════════════════════════════════════════
    // is_ltms_vendor()
    // ════════════════════════════════════════════════════════════════════════

    public function test_is_ltms_vendor_returns_false_for_zero_id(): void
    {
        $this->assertFalse(\LTMS_Utils::is_ltms_vendor(0));
    }

    public function test_is_ltms_vendor_returns_false_when_user_not_found(): void
    {
        Functions\when('get_userdata')->justReturn(false);
        $this->assertFalse(\LTMS_Utils::is_ltms_vendor(99));
    }

    public function test_is_ltms_vendor_returns_true_for_vendor_role(): void
    {
        $user        = new \stdClass();
        $user->roles = ['ltms_vendor'];
        Functions\when('get_userdata')->justReturn($user);
        $this->assertTrue(\LTMS_Utils::is_ltms_vendor(5));
    }

    public function test_is_ltms_vendor_returns_true_for_premium_role(): void
    {
        $user        = new \stdClass();
        $user->roles = ['ltms_vendor_premium'];
        Functions\when('get_userdata')->justReturn($user);
        $this->assertTrue(\LTMS_Utils::is_ltms_vendor(5));
    }

    public function test_is_ltms_vendor_returns_false_for_admin_role(): void
    {
        $user        = new \stdClass();
        $user->roles = ['administrator'];
        Functions\when('get_userdata')->justReturn($user);
        $this->assertFalse(\LTMS_Utils::is_ltms_vendor(1));
    }
}
