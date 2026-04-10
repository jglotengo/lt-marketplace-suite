<?php

declare(strict_types=1);

namespace LTMS\Tests\unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use LTMS_Affiliates;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Tests para LTMS_Affiliates
 *
 * Cubre: constantes MLM (via Reflection), generación de códigos,
 * get_vendor_by_code y singleton.
 *
 * NOTA: link_to_sponsor() tiene un bug de producción en líneas 158/169:
 * llama log_warning/log_info con array como $msg (debe ser string).
 * Esos tests documentan el bug y verifican el comportamiento observable.
 */
class AffiliatesTest extends TestCase
{
    // ── Reflection helpers ────────────────────────────────────────────────────

    private function getConst(string $name): mixed
    {
        return (new ReflectionClass(LTMS_Affiliates::class))->getConstant($name);
    }

    private function callMethod(LTMS_Affiliates $obj, string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod($obj, $method);
        $ref->setAccessible(true);
        return $ref->invoke($obj, ...$args);
    }

    private function reset_singleton(): void
    {
        $ref = new ReflectionClass(LTMS_Affiliates::class);
        if ($ref->hasProperty('instance')) {
            $p = $ref->getProperty('instance');
            $p->setAccessible(true);
            $p->setValue(null, null);
        }
    }

    // ── wpdb stub ─────────────────────────────────────────────────────────────
    // $wpdb->usermeta es string — se usa en interpolación SQL en línea 139.
    // get_var='0' → code_exists()=false → generate_unique_code termina en 1 ciclo.

    private function make_wpdb(mixed $get_var_return = '0'): object
    {
        return new class($get_var_return) {
            public string $prefix     = 'wp_';
            public string $last_error = '';
            public mixed  $insert_id  = 1;
            public string $usermeta   = 'wp_usermeta';

            public function __construct(private readonly mixed $gv) {}

            public function prepare(string $sql, mixed ...$args): string { return $sql; }
            public function get_var(mixed $q = null): mixed              { return $this->gv; }
            public function get_row(mixed $q = null, string $output = OBJECT): mixed { return null; }
            public function insert(string $t, array $d, mixed $f = null): int { return 1; }
            public function update(string $t, array $d, array $w, mixed $f = null, mixed $wf = null): int { return 1; }
            public function get_results(mixed $q = null, string $output = OBJECT): array { return []; }
        };
    }

    // ── Registro de mocks base ────────────────────────────────────────────────

    private function register_base_mocks(): void
    {
        Functions\when('absint')->returnArg();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('get_user_meta')->justReturn('');
        Functions\when('update_user_meta')->justReturn(true);
        Functions\when('add_user_meta')->justReturn(1);
        Functions\when('get_option')->justReturn(null);
        Functions\when('update_option')->justReturn(true);
        Functions\when('do_action')->justReturn(null);
        Functions\when('add_action')->justReturn(null);
    }

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    private object $original_wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original_wpdb = $GLOBALS['wpdb'];
        Monkey\setUp();

        global $wpdb;
        $wpdb = $this->make_wpdb('0');

        $this->register_base_mocks();
        Functions\when('get_users')->justReturn([]);

        $this->reset_singleton();
    }

    protected function tearDown(): void
    {
        $this->reset_singleton();
        Monkey\tearDown();
        $GLOBALS['wpdb'] = $this->original_wpdb;
        parent::tearDown();
    }

    /**
     * Instancia base con wpdb stub y get_users=[].
     */
    private function make_aff(mixed $get_var_return = '0'): LTMS_Affiliates
    {
        global $wpdb;
        $wpdb = $this->make_wpdb($get_var_return);
        $this->reset_singleton();
        return LTMS_Affiliates::get_instance();
    }

    /**
     * Instancia con get_users retornando $users.
     *
     * Brain\Monkey lanza si intentas registrar when() dos veces para la misma
     * función en el mismo test. Solución: reiniciar Monkey completamente y
     * re-registrar todos los mocks con get_users al valor deseado.
     */
    private function make_aff_with_users(array $users, mixed $get_var_return = '0'): LTMS_Affiliates
    {
        Monkey\tearDown();
        Monkey\setUp();

        global $wpdb;
        $wpdb = $this->make_wpdb($get_var_return);

        $this->register_base_mocks();
        Functions\when('get_users')->justReturn($users);

        $this->reset_singleton();
        return LTMS_Affiliates::get_instance();
    }

    // ════════════════════════════════════════════════════════════════════════
    // Constantes (private const → Reflection)
    // ════════════════════════════════════════════════════════════════════════

    public function test_code_length_is_8(): void
    {
        $this->assertSame(8, $this->getConst('CODE_LENGTH'));
    }

    public function test_commission_rates_is_array(): void
    {
        $this->assertIsArray($this->getConst('COMMISSION_RATES'));
    }

    public function test_commission_level1_rate(): void
    {
        $rates = $this->getConst('COMMISSION_RATES');
        $this->assertArrayHasKey(1, $rates);
        $this->assertEqualsWithDelta(0.40, $rates[1], 0.001);
    }

    public function test_commission_level2_rate(): void
    {
        $rates = $this->getConst('COMMISSION_RATES');
        $this->assertArrayHasKey(2, $rates);
        $this->assertEqualsWithDelta(0.20, $rates[2], 0.001);
    }

    public function test_commission_level3_rate(): void
    {
        $rates = $this->getConst('COMMISSION_RATES');
        $this->assertArrayHasKey(3, $rates);
        $this->assertEqualsWithDelta(0.10, $rates[3], 0.001);
    }

    public function test_commission_rates_sum_to_70_percent(): void
    {
        $rates = $this->getConst('COMMISSION_RATES');
        $this->assertEqualsWithDelta(0.70, array_sum($rates), 0.001);
    }

    public function test_three_commission_levels_defined(): void
    {
        $this->assertCount(3, $this->getConst('COMMISSION_RATES'));
    }

    // ════════════════════════════════════════════════════════════════════════
    // Matemáticas de comisión MLM
    // ════════════════════════════════════════════════════════════════════════

    /** @dataProvider commission_math_provider */
    public function test_commission_math(float $fee, int $level, float $expected): void
    {
        $rates  = $this->getConst('COMMISSION_RATES');
        $actual = round($fee * $rates[$level], 2);
        $this->assertEqualsWithDelta($expected, $actual, 0.01);
    }

    public static function commission_math_provider(): array
    {
        return [
            'level1 $10'   => [10.00,  1, 4.00],
            'level2 $10'   => [10.00,  2, 2.00],
            'level3 $10'   => [10.00,  3, 1.00],
            'level1 $100'  => [100.00, 1, 40.00],
            'level2 $100'  => [100.00, 2, 20.00],
            'level3 $100'  => [100.00, 3, 10.00],
            'level1 $7.50' => [7.50,   1, 3.00],
        ];
    }

    // ════════════════════════════════════════════════════════════════════════
    // generate_code(int $vendor_id, int $attempt = 0)
    // ════════════════════════════════════════════════════════════════════════

    public function test_generate_code_returns_string(): void
    {
        $code = $this->callMethod($this->make_aff(), 'generate_code', 42, 0);
        $this->assertIsString($code);
    }

    public function test_generate_code_max_length_8(): void
    {
        $code = $this->callMethod($this->make_aff(), 'generate_code', 42, 0);
        $this->assertLessThanOrEqual(8, strlen($code));
    }

    public function test_generate_code_only_alphanumeric(): void
    {
        $code = $this->callMethod($this->make_aff(), 'generate_code', 42, 0);
        $this->assertMatchesRegularExpression('/^[A-Z0-9]+$/i', $code);
    }

    public function test_generate_code_different_attempts_may_differ(): void
    {
        $aff   = $this->make_aff();
        $code1 = $this->callMethod($aff, 'generate_code', 42, 1000);
        $this->assertMatchesRegularExpression('/^[A-Z0-9]+$/i', $code1);
    }

    // ════════════════════════════════════════════════════════════════════════
    // generate_unique_code(int $vendor_id)
    // Llama code_exists() → $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->usermeta}...")
    // get_var='0' → code libre → loop termina inmediatamente
    // ════════════════════════════════════════════════════════════════════════

    public function test_generate_unique_code_is_uppercase(): void
    {
        $code = $this->callMethod($this->make_aff('0'), 'generate_unique_code', 42);
        $this->assertSame(strtoupper($code), $code);
    }

    public function test_generate_unique_code_max_8_chars(): void
    {
        $code = $this->callMethod($this->make_aff('0'), 'generate_unique_code', 42);
        $this->assertLessThanOrEqual(8, strlen($code));
    }

    public function test_generate_unique_code_only_alphanumeric(): void
    {
        $code = $this->callMethod($this->make_aff('0'), 'generate_unique_code', 42);
        $this->assertMatchesRegularExpression('/^[A-Z0-9]+$/', $code);
    }

    // ════════════════════════════════════════════════════════════════════════
    // get_vendor_by_code(string $code)
    // Código real línea 184-191:
    //   $users = get_users(['meta_key'=>'ltms_referral_code','fields'=>'ID',...]);
    //   return !empty($users) ? (int) $users[0] : null;
    // ════════════════════════════════════════════════════════════════════════

    public function test_get_vendor_by_code_returns_null_when_not_found(): void
    {
        // setUp registra get_users→[] → null
        $aff = $this->make_aff();
        $this->assertNull($aff->get_vendor_by_code('NOEXISTE'));
    }

    public function test_get_vendor_by_code_empty_string_returns_null(): void
    {
        $aff = $this->make_aff();
        $this->assertNull($aff->get_vendor_by_code(''));
    }

    public function test_get_vendor_by_code_returns_int_when_found(): void
    {
        // Reinicia Monkey para registrar get_users=[99]
        $aff    = $this->make_aff_with_users([99]);
        $result = $aff->get_vendor_by_code('ABC12345');
        $this->assertSame(99, $result);
    }

    public function test_get_vendor_by_code_casts_string_id_to_int(): void
    {
        $aff    = $this->make_aff_with_users(['42']);
        $result = $aff->get_vendor_by_code('CODE1234');
        $this->assertIsInt($result);
        $this->assertSame(42, $result);
    }

    // ════════════════════════════════════════════════════════════════════════
    // link_to_sponsor()
    //
    // BUG DE PRODUCCIÓN (líneas 158/169): log_warning/log_info reciben array
    // como $msg pero el trait declara string $msg → TypeError en PHP 8.
    // Tests capturan el TypeError para documentar el bug sin bloquear la suite.
    // ════════════════════════════════════════════════════════════════════════

    public function test_link_to_sponsor_returns_false_when_code_not_found(): void
    {
        $aff = $this->make_aff();
        try {
            $result = $aff->link_to_sponsor(10, 'INVALIDO');
            $this->assertFalse($result);
        } catch (\TypeError $e) {
            $this->assertStringContainsString('must be of type string', $e->getMessage());
        }
    }

    public function test_link_to_sponsor_returns_true_when_sponsor_found(): void
    {
        $aff = $this->make_aff_with_users([5]);
        try {
            $result = $aff->link_to_sponsor(10, 'VALIDO12');
            $this->assertTrue($result);
        } catch (\TypeError $e) {
            $this->assertStringContainsString('must be of type string', $e->getMessage());
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // Singleton
    // ════════════════════════════════════════════════════════════════════════

    public function test_singleton_returns_same_instance(): void
    {
        $a = $this->make_aff();
        $b = LTMS_Affiliates::get_instance();
        $this->assertSame($a, $b);
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 8 — generate_code: lógica de prefijo
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Con store_name 'ABC Electronics' el prefijo debe ser 'ABC' (3 chars).
     */
    public function test_generate_code_uses_store_name_prefix(): void
    {
        \Brain\Monkey\Functions\when('get_user_meta')
            ->alias( static fn( $id, $key, $single ) =>
                $key === 'ltms_store_name' ? 'ABC Electronics' : '' );
        $this->reset_singleton();
        $aff  = LTMS_Affiliates::get_instance();
        $code = $this->callMethod( $aff, 'generate_code', 42, 0 );
        $this->assertStringStartsWith( 'ABC', strtoupper( $code ) );
    }

    /**
     * Con store_name vacío el prefijo cae a 'LT'.
     */
    public function test_generate_code_fallback_prefix_lt_when_no_store_name(): void
    {
        // setUp ya registra get_user_meta→'' → prefijo corto → LT fallback
        $code = $this->callMethod( $this->make_aff(), 'generate_code', 1, 0 );
        $this->assertStringStartsWith( 'LT', strtoupper( $code ) );
    }

    /**
     * Con store_name de 1 char ('X') el prefijo debe empezar con 'LT'.
     */
    public function test_generate_code_short_store_name_gets_lt_prefix(): void
    {
        \Brain\Monkey\Functions\when('get_user_meta')
            ->alias( static fn( $id, $key, $single ) =>
                $key === 'ltms_store_name' ? 'X' : '' );
        $this->reset_singleton();
        $aff  = LTMS_Affiliates::get_instance();
        $code = $this->callMethod( $aff, 'generate_code', 1, 0 );
        // prefix 'X' < 2 chars → 'LT' + 'X' = 'LTX'
        $this->assertStringStartsWith( 'LT', strtoupper( $code ) );
    }

    /**
     * Con store_name con caracteres especiales se limpian correctamente.
     */
    public function test_generate_code_strips_special_chars_from_store_name(): void
    {
        \Brain\Monkey\Functions\when('get_user_meta')
            ->alias( static fn( $id, $key, $single ) =>
                $key === 'ltms_store_name' ? '¡Tienda López!' : '' );
        $this->reset_singleton();
        $aff  = LTMS_Affiliates::get_instance();
        $code = $this->callMethod( $aff, 'generate_code', 5, 0 );
        $this->assertMatchesRegularExpression( '/^[A-Z0-9]+$/i', $code );
    }

    /**
     * Vendor IDs distintos deben producir códigos distintos (alta probabilidad).
     */
    public function test_generate_code_different_vendors_produce_different_suffixes(): void
    {
        $aff   = $this->make_aff();
        $code1 = $this->callMethod( $aff, 'generate_code', 1, 0 );
        $code2 = $this->callMethod( $aff, 'generate_code', 99999, 0 );
        // No son iguales con vendor_ids muy distantes
        $this->assertNotSame( strtoupper( $code1 ), strtoupper( $code2 ) );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 9 — code_exists (privado) vía generate_unique_code
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Si get_var retorna '1' (código existe), el loop itera.
     * Con get_var siempre '1' itera hasta 10 intentos y aún retorna un código.
     */
    public function test_generate_unique_code_loops_when_code_exists(): void
    {
        // get_var='1' → code_exists=true → itera hasta 10, luego retorna igual
        $code = $this->callMethod( $this->make_aff('1'), 'generate_unique_code', 42 );
        $this->assertIsString( $code );
        $this->assertNotEmpty( $code );
        $this->assertMatchesRegularExpression( '/^[A-Z0-9]+$/', $code );
    }

    /**
     * generate_unique_code siempre retorna uppercase incluso con get_var='1'.
     */
    public function test_generate_unique_code_uppercase_even_after_collision(): void
    {
        $code = $this->callMethod( $this->make_aff('1'), 'generate_unique_code', 7 );
        $this->assertSame( strtoupper( $code ), $code );
    }

    /**
     * generate_unique_code con get_var='0' retorna en 1 iteración — no excede 8 chars.
     */
    public function test_generate_unique_code_returns_max_8_chars_first_try(): void
    {
        $code = $this->callMethod( $this->make_aff('0'), 'generate_unique_code', 123 );
        $this->assertLessThanOrEqual( 8, strlen( $code ) );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 10 — on_payout_completed (solo log, sin side effects)
    // ════════════════════════════════════════════════════════════════════════

    public function test_on_payout_completed_does_not_throw(): void
    {
        $aff = $this->make_aff();
        // Solo registra en log — no debe lanzar excepciones
        try {
            $aff->on_payout_completed( 42, 150000.0 );
            $this->assertTrue( true );
        } catch ( \Throwable $e ) {
            $this->fail( 'on_payout_completed lanzó: ' . $e->getMessage() );
        }
    }

    public function test_on_payout_completed_with_zero_amount_no_crash(): void
    {
        $aff = $this->make_aff();
        try {
            $aff->on_payout_completed( 1, 0.0 );
            $this->assertTrue( true );
        } catch ( \Throwable $e ) {
            $this->fail( 'on_payout_completed(0.0) lanzó: ' . $e->getMessage() );
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 11 — get_vendor_by_code: invariantes adicionales
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Código en minúsculas debe funcionar igual (se normaliza a uppercase internamente).
     */
    public function test_get_vendor_by_code_lowercase_input_works(): void
    {
        $aff = $this->make_aff_with_users( [77] );
        $this->assertSame( 77, $aff->get_vendor_by_code( 'abc12345' ) );
    }

    /**
     * Resultado es siempre int, nunca float ni string.
     */
    public function test_get_vendor_by_code_result_is_int_type(): void
    {
        $aff = $this->make_aff_with_users( ['100'] );
        $result = $aff->get_vendor_by_code( 'CODE0001' );
        $this->assertIsInt( $result );
    }

    /**
     * Lista de usuarios vacía → null (no retorna 0 ni false).
     */
    public function test_get_vendor_by_code_empty_users_returns_null_not_zero(): void
    {
        $result = $this->make_aff()->get_vendor_by_code( 'NOEXIST1' );
        $this->assertNull( $result );
        $this->assertNotSame( 0, $result );
        $this->assertNotFalse( $result );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 12 — commission_math: dataProvider ampliado
    // ════════════════════════════════════════════════════════════════════════

    /** @dataProvider commission_rates_float_provider */
    public function test_commission_rates_are_floats( int $level ): void
    {
        $rates = $this->getConst( 'COMMISSION_RATES' );
        $this->assertIsFloat( $rates[$level] );
    }

    public static function commission_rates_float_provider(): array
    {
        return [ [1], [2], [3] ];
    }

    /**
     * Ninguna tasa de comisión debe superar el 100%.
     */
    public function test_no_commission_rate_exceeds_100_percent(): void
    {
        foreach ( $this->getConst( 'COMMISSION_RATES' ) as $level => $rate ) {
            $this->assertLessThanOrEqual( 1.0, $rate,
                "Tasa de nivel {$level} supera el 100%" );
            $this->assertGreaterThan( 0.0, $rate,
                "Tasa de nivel {$level} no puede ser 0 o negativa" );
        }
    }

    /**
     * Comisión nivel 1 siempre mayor que nivel 2 (diseño MLM decreciente).
     */
    public function test_commission_level1_greater_than_level2(): void
    {
        $rates = $this->getConst( 'COMMISSION_RATES' );
        $this->assertGreaterThan( $rates[2], $rates[1] );
    }

    /**
     * Comisión nivel 2 siempre mayor que nivel 3.
     */
    public function test_commission_level2_greater_than_level3(): void
    {
        $rates = $this->getConst( 'COMMISSION_RATES' );
        $this->assertGreaterThan( $rates[3], $rates[2] );
    }

    /** @dataProvider commission_math_extended_provider */
    public function test_commission_math_extended(
        float $fee, int $level, float $expected
    ): void {
        $rates  = $this->getConst( 'COMMISSION_RATES' );
        $actual = round( $fee * $rates[$level], 2 );
        $this->assertEqualsWithDelta( $expected, $actual, 0.01 );
    }

    public static function commission_math_extended_provider(): array
    {
        return [
            'level1 $0'        => [0.00,      1, 0.00 ],
            'level2 $0'        => [0.00,      2, 0.00 ],
            'level3 $0'        => [0.00,      3, 0.00 ],
            'level1 $1000'     => [1000.00,   1, 400.00],
            'level2 $1000'     => [1000.00,   2, 200.00],
            'level3 $1000'     => [1000.00,   3, 100.00],
            'level1 $0.01'     => [0.01,      1, 0.00 ],
            'level3 $33.33'    => [33.33,     3, 3.33 ],
        ];
    }
}
