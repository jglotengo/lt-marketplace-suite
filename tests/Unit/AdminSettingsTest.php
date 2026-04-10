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

    public function test_sanitize_encrypts_siigo_password(): void
    {
        $result = $this->settings->sanitize_settings(['ltms_siigo_password' => 'secret123']);
        $this->assertStringStartsWith('v1:', $result['ltms_siigo_password']);
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
        $result  = $this->settings->sanitize_settings(['ltms_siigo_password' => $already]);
        $this->assertSame($already, $result['ltms_siigo_password']);
    }

    public function test_sanitize_skips_empty_encrypted_field(): void
    {
        // When an encrypted field is empty, the class falls through to sanitize_text_field
        // and returns the key with an empty string value (sanitize_text_field('') = '').
        Functions\when('sanitize_text_field')->alias(static fn(string $v): string => trim(strip_tags($v)));
        $result = $this->settings->sanitize_settings(['ltms_siigo_password' => '']);
        $this->assertArrayHasKey('ltms_siigo_password', $result);
        $this->assertSame('', $result['ltms_siigo_password']);
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
            'ltms_siigo_password' => 'secret',
            'commission_rate'     => '10',
            'kyc_enabled'         => 'yes',
            'min_payout_amount'   => '50000',
            'ltms_country'        => 'CO',
        ];

        $result = $this->settings->sanitize_settings($input);

        $this->assertStringStartsWith('v1:', $result['ltms_siigo_password']);
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
        $result = $this->settings->sanitize_settings(['ltms_aveonline_api_key' => 'ave-key-123']);
        $this->assertStringStartsWith('v1:', $result['ltms_aveonline_api_key']);
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
}
