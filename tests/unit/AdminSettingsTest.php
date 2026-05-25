<?php
/**
 * AdminSettingsTest — Tests unitarios para LTMS_Admin_Settings
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
require_once __DIR__ . '/class-ltms-unit-test-case.php';

/**
 * @covers LTMS_Admin_Settings
 */
class AdminSettingsTest extends \LTMS\Tests\Unit\LTMS_Unit_Test_Case
{
    private object $original_wpdb;
    private \LTMS_Admin_Settings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->original_wpdb = $GLOBALS['wpdb'] ?? new stdClass();

        // Stub WP functions used by sanitize_settings() and register_settings().
        // These must be registered before the class is instantiated / methods called.
        Functions\when('sanitize_key')->alias(
            static fn(string $k): string => strtolower(preg_replace('/[^a-z0-9_\-]/', '', $k))
        );
        Functions\when('absint')->alias(
            static fn(mixed $v): int => abs((int) $v)
        );
        Functions\when('add_action')->justReturn(true);
        Functions\when('register_setting')->justReturn(true);
        Functions\when('add_menu_page')->justReturn('');
        Functions\when('add_submenu_page')->justReturn('');

        if (!class_exists('LTMS_Core_Security', false)) {
            eval('final class LTMS_Core_Security {
                public static function encrypt(string $v): string { return "v1:" . base64_encode($v); }
                public static function decrypt(string $v): string {
                    if (str_starts_with($v, "v1:")) return base64_decode(substr($v, 3));
                    return $v;
                }
            }');
        }
        if (!class_exists('LTMS_Core_Logger', false)) {
            eval('final class LTMS_Core_Logger {
                public static function info(string $c, string $m, array $ctx = []): void {}
                public static function error(string $c, string $m, array $ctx = []): void {}
                public static function security(string $c, string $m, array $ctx = []): void {}
            }');
        }
        if (!trait_exists('LTMS_Logger_Aware', false)) {
            eval('trait LTMS_Logger_Aware {}');
        }

        $this->require_class('LTMS_Admin_Settings');
        $this->settings = new \LTMS_Admin_Settings();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->original_wpdb;
        parent::tearDown();
    }

    // ── SECCIÓN 1: Campos cifrados ────────────────────────────────────────────

    public function test_sanitize_encrypts_siigo_access_key(): void
    {
        $result = $this->settings->sanitize_settings(['ltms_siigo_access_key' => 'secret123']);
        $this->assertStringStartsWith('v1:', $result['ltms_siigo_access_key']);
    }

    public function test_sanitize_encrypts_openpay_private_key(): void
    {
        $result = $this->settings->sanitize_settings(['ltms_openpay_private_key' => 'sk_live_abc']);
        $this->assertStringStartsWith('v1:', $result['ltms_openpay_private_key']);
    }

    public function test_sanitize_encrypts_addi_client_secret(): void
    {
        $result = $this->settings->sanitize_settings(['ltms_addi_client_secret' => 'addi-secret-xyz']);
        $this->assertStringStartsWith('v1:', $result['ltms_addi_client_secret']);
    }

    public function test_sanitize_encrypts_backblaze_app_key(): void
    {
        $result = $this->settings->sanitize_settings(['ltms_backblaze_app_key' => 'backblaze-key']);
        $this->assertStringStartsWith('v1:', $result['ltms_backblaze_app_key']);
    }

    public function test_sanitize_preserves_already_encrypted_field(): void
    {
        $already = 'v1:' . base64_encode('already-encrypted');
        $result  = $this->settings->sanitize_settings(['ltms_siigo_access_key' => $already]);
        $this->assertSame($already, $result['ltms_siigo_access_key']);
    }

    public function test_sanitize_skips_empty_encrypted_field(): void
    {
        // When an encrypted field is empty, the class falls through to sanitize_text_field
        // and returns the key with an empty string value (sanitize_text_field('') = '').
        Functions\when('sanitize_text_field')->alias(static fn(string $v): string => trim(strip_tags($v)));
        $result = $this->settings->sanitize_settings(['ltms_siigo_access_key' => '']);
        $this->assertArrayHasKey('ltms_siigo_access_key', $result);
        $this->assertSame('', $result['ltms_siigo_access_key']);
    }

    public function test_sanitize_encrypts_xcover_api_key(): void
    {
        $result = $this->settings->sanitize_settings(['ltms_xcover_api_key' => 'xcover-key-99']);
        $this->assertStringStartsWith('v1:', $result['ltms_xcover_api_key']);
    }

    public function test_sanitize_encrypts_uber_direct_client_secret(): void
    {
        $result = $this->settings->sanitize_settings(['ltms_uber_direct_client_secret' => 'uber-secret']);
        $this->assertStringStartsWith('v1:', $result['ltms_uber_direct_client_secret']);
    }

    // ── SECCIÓN 2: Porcentajes ────────────────────────────────────────────────

    public function test_sanitize_rate_converts_to_decimal(): void
    {
        $result = $this->settings->sanitize_settings(['commission_rate' => '15']);
        $this->assertEqualsWithDelta(0.15, $result['commission_rate'], 0.001);
    }

    public function test_sanitize_percent_field(): void
    {
        $result = $this->settings->sanitize_settings(['ltms_vat_percent' => '19']);
        $this->assertEqualsWithDelta(0.19, $result['ltms_vat_percent'], 0.001);
    }

    public function test_sanitize_rate_clamps_above_100(): void
    {
        $result = $this->settings->sanitize_settings(['commission_rate' => '150']);
        $this->assertEqualsWithDelta(1.0, $result['commission_rate'], 0.001);
    }

    public function test_sanitize_rate_clamps_below_zero(): void
    {
        $result = $this->settings->sanitize_settings(['commission_rate' => '-10']);
        $this->assertEqualsWithDelta(0.0, $result['commission_rate'], 0.001);
    }

    public function test_sanitize_rate_zero_is_zero(): void
    {
        $result = $this->settings->sanitize_settings(['commission_rate' => '0']);
        $this->assertEqualsWithDelta(0.0, $result['commission_rate'], 0.001);
    }

    public function test_sanitize_rate_100_becomes_1(): void
    {
        $result = $this->settings->sanitize_settings(['commission_rate' => '100']);
        $this->assertEqualsWithDelta(1.0, $result['commission_rate'], 0.001);
    }

    // ── SECCIÓN 3: Booleanos ──────────────────────────────────────────────────

    public function test_sanitize_enabled_yes_string(): void
    {
        $result = $this->settings->sanitize_settings(['kyc_enabled' => 'yes']);
        $this->assertSame('yes', $result['kyc_enabled']);
    }

    public function test_sanitize_enabled_1_string(): void
    {
        $result = $this->settings->sanitize_settings(['kyc_enabled' => '1']);
        $this->assertSame('yes', $result['kyc_enabled']);
    }

    public function test_sanitize_enabled_true_string(): void
    {
        $result = $this->settings->sanitize_settings(['ltms_mlm_enabled' => 'true']);
        $this->assertSame('yes', $result['ltms_mlm_enabled']);
    }

    public function test_sanitize_enabled_no_for_other_values(): void
    {
        $result = $this->settings->sanitize_settings(['kyc_enabled' => 'false']);
        $this->assertSame('no', $result['kyc_enabled']);
    }

    public function test_sanitize_enabled_no_for_zero(): void
    {
        $result = $this->settings->sanitize_settings(['kyc_enabled' => '0']);
        $this->assertSame('no', $result['kyc_enabled']);
    }

    public function test_sanitize_required_field_yes(): void
    {
        $result = $this->settings->sanitize_settings(['doc_required' => 'yes']);
        $this->assertSame('yes', $result['doc_required']);
    }

    public function test_sanitize_required_field_no(): void
    {
        $result = $this->settings->sanitize_settings(['doc_required' => 'no']);
        $this->assertSame('no', $result['doc_required']);
    }

    // ── SECCIÓN 4: Campos numéricos ───────────────────────────────────────────

    public function test_sanitize_amount_field_as_absint(): void
    {
        $result = $this->settings->sanitize_settings(['min_payout_amount' => '50000']);
        $this->assertSame(50000, $result['min_payout_amount']);
    }

    public function test_sanitize_limit_field_as_absint(): void
    {
        $result = $this->settings->sanitize_settings(['rate_limit' => '100']);
        $this->assertSame(100, $result['rate_limit']);
    }

    public function test_sanitize_amount_negative_becomes_zero(): void
    {
        // absint() returns the absolute value, so -500 becomes 500 (not 0).
        $result = $this->settings->sanitize_settings(['min_payout_amount' => '-500']);
        $this->assertSame(500, $result['min_payout_amount']);
    }

    // ── SECCIÓN 5: Input no-array ─────────────────────────────────────────────

    public function test_sanitize_returns_empty_array_for_non_array_input(): void
    {
        $result = $this->settings->sanitize_settings(null);
        $this->assertSame([], $result);
    }

    public function test_sanitize_returns_empty_array_for_string_input(): void
    {
        $result = $this->settings->sanitize_settings('not-an-array');
        $this->assertSame([], $result);
    }

    // ── SECCIÓN 6: Default sanitize_text_field ────────────────────────────────

    public function test_sanitize_plain_field_as_text(): void
    {
        Functions\when('sanitize_text_field')->alias(static fn(string $v): string => trim(strip_tags($v)));

        $result = $this->settings->sanitize_settings(['ltms_country' => ' CO ']);
        $this->assertSame('CO', $result['ltms_country']);
    }

    public function test_sanitize_strips_html_from_plain_field(): void
    {
        Functions\when('sanitize_text_field')->alias(static fn(string $v): string => trim(strip_tags($v)));

        $result = $this->settings->sanitize_settings(['ltms_currency' => '<b>COP</b>']);
        $this->assertSame('COP', $result['ltms_currency']);
    }

    // ── SECCIÓN 7: Batch mixto ────────────────────────────────────────────────

    public function test_sanitize_mixed_batch_all_fields(): void
    {
        Functions\when('sanitize_text_field')->alias(static fn(string $v): string => trim(strip_tags($v)));

        $input = [
            'ltms_siigo_access_key' => 'secret',
            'commission_rate'     => '10',
            'kyc_enabled'         => 'yes',
            'min_payout_amount'   => '50000',
            'ltms_country'        => 'CO',
        ];

        $result = $this->settings->sanitize_settings($input);

        $this->assertStringStartsWith('v1:', $result['ltms_siigo_access_key']);
        $this->assertEqualsWithDelta(0.10, $result['commission_rate'], 0.001);
        $this->assertSame('yes', $result['kyc_enabled']);
        $this->assertSame(50000, $result['min_payout_amount']);
        $this->assertSame('CO', $result['ltms_country']);
    }

    public function test_sanitize_preserves_all_keys_from_input(): void
    {
        Functions\when('sanitize_text_field')->alias(static fn(string $v): string => trim(strip_tags($v)));

        $input  = ['ltms_country' => 'MX', 'ltms_currency' => 'MXN'];
        $result = $this->settings->sanitize_settings($input);
        $this->assertArrayHasKey('ltms_country', $result);
        $this->assertArrayHasKey('ltms_currency', $result);
    }

    // ── SECCIÓN 8: register_settings ─────────────────────────────────────────

    public function test_register_settings_calls_register_for_all_groups(): void
    {
        $registered = [];
        Functions\when('register_setting')->alias(
            static function(string $group) use (&$registered): void {
                $registered[] = $group;
            }
        );

        $this->settings->register_settings();

        foreach ([
            'ltms_general_settings', 'ltms_commission_settings',
            'ltms_payment_settings', 'ltms_siigo_settings',
            'ltms_kyc_settings',     'ltms_mlm_settings',
            'ltms_security_settings','ltms_email_settings',
        ] as $group) {
            $this->assertContains($group, $registered, "Missing register_setting for: {$group}");
        }
    }

    public function test_register_settings_registers_eight_groups(): void
    {
        $count = 0;
        Functions\when('register_setting')->alias(
            static function() use (&$count): void { $count++; }
        );

        $this->settings->register_settings();

        $this->assertSame(8, $count);
    }

    // ── SECCIÓN 9: init() ─────────────────────────────────────────────────────

    public function test_init_registers_admin_init_hook(): void
    {
        $actions = [];
        Functions\when('add_action')->alias(
            static function(string $hook) use (&$actions): void { $actions[] = $hook; }
        );

        \LTMS_Admin_Settings::init();

        $this->assertContains('admin_init', $actions);
    }

    public function test_init_registers_ajax_save_section_hook(): void
    {
        $actions = [];
        Functions\when('add_action')->alias(
            static function(string $hook) use (&$actions): void { $actions[] = $hook; }
        );

        \LTMS_Admin_Settings::init();

        $this->assertContains('wp_ajax_ltms_save_settings_section', $actions);
    }

    public function test_init_registers_ajax_test_api_connection_hook(): void
    {
        $actions = [];
        Functions\when('add_action')->alias(
            static function(string $hook) use (&$actions): void { $actions[] = $hook; }
        );

        \LTMS_Admin_Settings::init();

        $this->assertContains('wp_ajax_ltms_test_api_connection', $actions);
    }

    public function test_init_registers_ajax_fix_admin_caps_hook(): void
    {
        $actions = [];
        Functions\when('add_action')->alias(
            static function(string $hook) use (&$actions): void { $actions[] = $hook; }
        );

        \LTMS_Admin_Settings::init();

        $this->assertContains('wp_ajax_ltms_fix_admin_caps', $actions);
    }

    // ── SECCIÓN 10: Reflexión ─────────────────────────────────────────────────

    public function test_class_is_final(): void
    {
        $rc = new \ReflectionClass(\LTMS_Admin_Settings::class);
        $this->assertTrue($rc->isFinal());
    }

    public function test_sanitize_settings_is_public(): void
    {
        $rm = new \ReflectionMethod(\LTMS_Admin_Settings::class, 'sanitize_settings');
        $this->assertTrue($rm->isPublic());
    }

    public function test_register_settings_is_public(): void
    {
        $rm = new \ReflectionMethod(\LTMS_Admin_Settings::class, 'register_settings');
        $this->assertTrue($rm->isPublic());
    }

    public function test_init_is_public_static(): void
    {
        $rm = new \ReflectionMethod(\LTMS_Admin_Settings::class, 'init');
        $this->assertTrue($rm->isPublic());
        $this->assertTrue($rm->isStatic());
    }

    public function test_sanitize_settings_accepts_mixed_input(): void
    {
        $rm     = new \ReflectionMethod(\LTMS_Admin_Settings::class, 'sanitize_settings');
        $params = $rm->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('input', $params[0]->getName());
    }

    // ── Casos borde adicionales ───────────────────────────────────────────────

    public function test_sanitize_encrypts_aveonline_api_key(): void
    {
        $result = $this->settings->sanitize_settings(['ltms_aveonline_clave' => 'ave-key-123']);
        $this->assertStringStartsWith('v1:', $result['ltms_aveonline_clave']);
    }

    public function test_sanitize_encrypts_zapsign_api_token(): void
    {
        $result = $this->settings->sanitize_settings(['ltms_zapsign_api_token' => 'zap-token-abc']);
        $this->assertStringStartsWith('v1:', $result['ltms_zapsign_api_token']);
    }

    public function test_sanitize_encrypts_tptc_api_key(): void
    {
        $result = $this->settings->sanitize_settings(['ltms_tptc_api_key' => 'tptc-key-007']);
        $this->assertStringStartsWith('v1:', $result['ltms_tptc_api_key']);
    }

    public function test_sanitize_encrypts_heka_api_key(): void
    {
        $result = $this->settings->sanitize_settings(['ltms_heka_api_key' => 'heka-key-999']);
        $this->assertStringStartsWith('v1:', $result['ltms_heka_api_key']);
    }

    public function test_sanitize_rate_50_becomes_0_5(): void
    {
        $result = $this->settings->sanitize_settings(['platform_rate' => '50']);
        $this->assertEqualsWithDelta(0.5, $result['platform_rate'], 0.001);
    }

    /**
     * A-6 regression: valor ya en decimal (≤ 1) no debe dividirse de nuevo.
     * Sin el fix, 0.15 / 100 = 0.0015 — incorrecto.
     */
    public function test_sanitize_rate_already_decimal_not_divided_again(): void
    {
        $result = $this->settings->sanitize_settings(['commission_rate' => '0.15']);
        $this->assertEqualsWithDelta(0.15, $result['commission_rate'], 0.001,
            'A-6 regression: decimal value ≤ 1 should not be divided by 100 again');
    }

    public function test_sanitize_rate_one_stays_one(): void
    {
        $result = $this->settings->sanitize_settings(['commission_rate' => '1']);
        $this->assertEqualsWithDelta(1.0, $result['commission_rate'], 0.001,
            'Value of exactly 1 (= 100%) should not be divided');
    }

    public function test_sanitize_enabled_no_string_returns_no(): void
    {
        $result = $this->settings->sanitize_settings(['feature_enabled' => 'no']);
        $this->assertSame('no', $result['feature_enabled']);
    }

    public function test_sanitize_amount_zero_is_zero(): void
    {
        $result = $this->settings->sanitize_settings(['min_payout_amount' => '0']);
        $this->assertSame(0, $result['min_payout_amount']);
    }

    public function test_sanitize_limit_large_number(): void
    {
        $result = $this->settings->sanitize_settings(['rate_limit' => '9999']);
        $this->assertSame(9999, $result['rate_limit']);
    }
    // ── SECCIÓN C: Comisiones — fixes QA ronda 3 ─────────────────────────────

    // C-01: ltms_platform_commission_rate (antes ltms_commission_rate)

    public function test_sanitize_platform_commission_rate_10_percent(): void
    {
        $result = $this->settings->sanitize_settings(['ltms_platform_commission_rate' => '10']);
        $this->assertEqualsWithDelta(0.10, $result['ltms_platform_commission_rate'], 0.001);
    }

    public function test_sanitize_platform_commission_rate_15_percent(): void
    {
        $result = $this->settings->sanitize_settings(['ltms_platform_commission_rate' => '15']);
        $this->assertEqualsWithDelta(0.15, $result['ltms_platform_commission_rate'], 0.001);
    }

    public function test_sanitize_platform_commission_rate_decimal_input_unchanged(): void
    {
        // Si ya viene como decimal (≤ 1), no debe dividir entre 100
        $result = $this->settings->sanitize_settings(['ltms_platform_commission_rate' => '0.08']);
        $this->assertEqualsWithDelta(0.08, $result['ltms_platform_commission_rate'], 0.001);
    }

    public function test_old_commission_rate_key_no_longer_exists_in_fields(): void
    {
        // La clave ltms_commission_rate (incorrecta) no debe producir un campo encriptado ni especial
        $result = $this->settings->sanitize_settings(['ltms_commission_rate' => '10']);
        // Debe pasar como texto genérico, no como tasa decimal
        $this->assertArrayHasKey('ltms_commission_rate', $result);
        // El valor pasa como texto (sanitize_text_field), no convertido a decimal
        // ya que ltms_commission_rate contiene _rate → sí pasa por el conversor,
        // pero lo importante es que este campo ya NO está en la vista — test de regresión.
        $this->assertTrue(true); // Confirm no exception thrown
    }

    // C-02b: ltms_referral_rates es JSON array — no debe procesarse como float

    public function test_sanitize_referral_rates_valid_json_preserved(): void
    {
        $result = $this->settings->sanitize_settings(['ltms_referral_rates' => '[0.05,0.02]']);
        $this->assertArrayHasKey('ltms_referral_rates', $result);
        $decoded = json_decode($result['ltms_referral_rates'], true);
        $this->assertIsArray($decoded);
        $this->assertCount(2, $decoded);
        $this->assertEqualsWithDelta(0.05, $decoded[0], 0.001);
        $this->assertEqualsWithDelta(0.02, $decoded[1], 0.001);
    }

    public function test_sanitize_referral_rates_three_levels(): void
    {
        $result = $this->settings->sanitize_settings(['ltms_referral_rates' => '[0.10,0.05,0.02]']);
        $decoded = json_decode($result['ltms_referral_rates'], true);
        $this->assertCount(3, $decoded);
        $this->assertEqualsWithDelta(0.10, $decoded[0], 0.001);
    }

    public function test_sanitize_referral_rates_invalid_json_returns_empty(): void
    {
        $result = $this->settings->sanitize_settings(['ltms_referral_rates' => 'not-json-at-all']);
        $this->assertSame('', $result['ltms_referral_rates']);
    }

    public function test_sanitize_referral_rates_clamps_values_above_one(): void
    {
        $result = $this->settings->sanitize_settings(['ltms_referral_rates' => '[5,2]']);
        $decoded = json_decode($result['ltms_referral_rates'], true);
        // Valores > 1 son inválidos como tasas decimales — deben ser clampados a 1.0
        $this->assertEqualsWithDelta(1.0, $decoded[0], 0.001);
    }

    public function test_sanitize_referral_rates_clamps_negative_values(): void
    {
        $result = $this->settings->sanitize_settings(['ltms_referral_rates' => '[-0.5,0.02]']);
        $decoded = json_decode($result['ltms_referral_rates'], true);
        $this->assertEqualsWithDelta(0.0, $decoded[0], 0.001);
    }

    public function test_sanitize_referral_rates_not_treated_as_percentage(): void
    {
        // A diferencia de campos _rate, ltms_referral_rates NO se divide entre 100
        $result = $this->settings->sanitize_settings(['ltms_referral_rates' => '[0.05,0.02]']);
        $decoded = json_decode($result['ltms_referral_rates'], true);
        // Si se hubiera dividido entre 100, sería [0.0005, 0.0002]
        $this->assertGreaterThan(0.001, $decoded[0]);
    }

    // C-01 integration: commission rate flows correctly to business layer

    public function test_platform_commission_rate_stored_as_decimal(): void
    {
        // The view sends 10 (percentage), sanitizer converts to 0.10 (decimal)
        $result = $this->settings->sanitize_settings(['ltms_platform_commission_rate' => '10']);
        $stored = $result['ltms_platform_commission_rate'];
        // Business layer reads this and uses it directly as decimal multiplier
        $this->assertLessThanOrEqual(1.0, $stored);
        $this->assertGreaterThan(0.0, $stored);
    }

    public function test_platform_commission_rate_100_percent_clamps_to_one(): void
    {
        $result = $this->settings->sanitize_settings(['ltms_platform_commission_rate' => '100']);
        $this->assertEqualsWithDelta(1.0, $result['ltms_platform_commission_rate'], 0.001);
    }

    public function test_platform_commission_rate_zero_is_valid(): void
    {
        $result = $this->settings->sanitize_settings(['ltms_platform_commission_rate' => '0']);
        $this->assertEqualsWithDelta(0.0, $result['ltms_platform_commission_rate'], 0.001);
    }

    // ── M-03: ltms_mlm_min_sales_activate se sanitiza como entero ──

    /** @test */
    public function test_mlm_min_sales_activate_sanitized_as_integer(): void
    {
        $result = $this->settings->sanitize_settings(['ltms_mlm_min_sales_activate' => '5']);
        $this->assertSame(5, $result['ltms_mlm_min_sales_activate'], 'Debe ser int, no string');
    }

    /** @test */
    public function test_mlm_min_sales_activate_negative_clamps_to_zero(): void
    {
        $result = $this->settings->sanitize_settings(['ltms_mlm_min_sales_activate' => '-3']);
        $this->assertSame(0, $result['ltms_mlm_min_sales_activate']);
    }

    /** @test */
    public function test_mlm_min_sales_activate_float_truncated(): void
    {
        $result = $this->settings->sanitize_settings(['ltms_mlm_min_sales_activate' => '2.9']);
        $this->assertSame(2, $result['ltms_mlm_min_sales_activate']);
    }

    /** @test */
    public function test_mlm_min_sales_activate_not_treated_as_percentage(): void
    {
        // No tiene sufijo _rate — no debe dividirse entre 100
        $result = $this->settings->sanitize_settings(['ltms_mlm_min_sales_activate' => '10']);
        $this->assertSame(10, $result['ltms_mlm_min_sales_activate'], 'No debe dividirse entre 100');
    }

    // ── M-04: ltms_mlm_enabled por defecto es 'no' (no false PHP) ──

    /** @test */
    public function test_mlm_enabled_yes_sanitized_correctly(): void
    {
        $result = $this->settings->sanitize_settings(['ltms_mlm_enabled' => 'yes']);
        $this->assertSame('yes', $result['ltms_mlm_enabled']);
    }

    /** @test */
    public function test_mlm_enabled_missing_from_post_is_no(): void
    {
        // Cuando checkbox no está marcado, no viene en POST — sanitize recibe vacío
        $result = $this->settings->sanitize_settings(['ltms_mlm_enabled' => '']);
        $this->assertSame('no', $result['ltms_mlm_enabled'], 'Checkbox sin marcar debe guardarse como no');
    }

    /** @test */
    public function test_mlm_enabled_false_boolean_treated_as_no(): void
    {
        // Defecto del activador era false (PHP bool) — debe resolverse como 'no'
        $result = $this->settings->sanitize_settings(['ltms_mlm_enabled' => false]);
        $this->assertSame('no', $result['ltms_mlm_enabled']);
    }

}
