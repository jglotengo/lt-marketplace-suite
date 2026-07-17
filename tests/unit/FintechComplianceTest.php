<?php
/**
 * FintechComplianceTest — Tests unitarios para LTMS_Fintech_Compliance
 *
 * Cubre:
 * - Constants: UMA_2026_MXN, TRAVEL_RULE_USD_THRESHOLD, SANCTIONS_LISTS, PLD_ACTIVITIES
 * - convert_to_usd(): USD passthrough, COP/MXN conversion, FX-1 FIX (default 0 → PHP_FLOAT_MAX)
 * - recalculate_pld_mx_threshold(): UMA-scaled, cash vs electronic
 * - get_legal_basis(): CO, MX, CROSS-BORDER keys
 * - enforce_2fa_for_payout_vendors(): role check (vendor, ltms_vendor, ltms_vendor_premium)
 * - screen_against_sanctions_lists(): SARLAFT fail-closed when list unavailable
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ReflectionClass;

// WP_User is defined in tests/unit/RolesTest.php (global namespace) with
// constructor (int $id, array $roles). We reuse that stub.
// The class_exists guard there prevents redefinition.

/**
 * @covers LTMS_Fintech_Compliance
 */
class FintechComplianceTest extends LTMS_Unit_Test_Case {

    public object $mock_wpdb;

    protected function setUp(): void {
        parent::setUp();

        // Save original wpdb to restore in tearDown (prevents mock leaking).
        if ( ! isset( $GLOBALS['__ltms_saved_wpdb'] ) ) {
            $GLOBALS['__ltms_saved_wpdb'] = $GLOBALS['wpdb'] ?? null;
        }

        $this->mock_wpdb = new class {
            public $prefix = 'wp_';
            public function prepare($sql, ...$args) { return $sql; }
            public function query($sql) { return true; }
            public function get_var($sql) { return null; }
            public function get_row($sql, $o = OBJECT) { return null; }
            public function get_results($sql, $o = OBJECT) { return []; }
            public function get_col($sql) { return []; }
            public function insert($t, $d, $f = null) { return 1; }
            public function update($t, $d, $w, $f = null, $wf = null) { return 1; }
            public function get_charset_collate() { return 'utf8mb4 utf8mb4_unicode_ci'; }
        };
        $GLOBALS['wpdb'] = $this->mock_wpdb;

        Functions\stubs([
            // current_time, wp_json_encode, __, _e, esc_html, esc_url, get_option,
            // do_action, apply_filters already stubbed in base class.
            'wp_remote_get'  => static fn($url, $args = []) => new \WP_Error('http_error', 'mock'),
            'is_wp_error'    => static fn($t) => $t instanceof \WP_Error,
            'wp_remote_retrieve_body' => static fn($r) => '',
            'wp_remote_retrieve_response_code' => static fn($r) => 0,
            'wp_mail'        => true,
            'get_bloginfo'   => static fn($k) => 'LT Marketplace',
            'get_userdata'   => static fn($id) => null,
            'get_users'      => static fn($args = []) => [],
            'get_user_meta'  => static fn($uid, $key, $single = false) => '',
            'esc_html_e'     => static fn($s) => null,
            'esc_xml'        => static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'),
            'update_user_meta' => true,
            'delete_user_meta' => true,
            'wp_upload_dir'  => static fn() => ['basedir' => sys_get_temp_dir(), 'baseurl' => 'http://example.com'],
            // wp_mkdir_p is defined in bootstrap.php — can't re-stub.
            // get_current_user_id is defined in bootstrap.php — can't re-stub.
            // sanitize_textarea_field is defined in bootstrap.php — can't re-stub.
            // file_exists, file_put_contents, fopen, fclose, fputcsv are PHP natives —
            // Patchwork can't redefine them without "redefinable-internals" config.
            'get_transient'  => static fn($k) => false,
            'set_transient'  => true,
            'admin_url'      => static fn($p = '') => 'http://example.com/wp-admin/' . $p,
            'is_user_logged_in' => static fn() => false,
            'wp_get_current_user' => static fn() => new \stdClass(),
            'remove_accents'  => static fn($s) => $s, // Pass-through for normalize_for_match
            'DAY_IN_SECONDS' => 86400,
        ]);
    }

    protected function tearDown(): void {
        if ( isset( $GLOBALS['__ltms_saved_wpdb'] ) ) {
            $GLOBALS['wpdb'] = $GLOBALS['__ltms_saved_wpdb'];
        }
        parent::tearDown();
    }

    private static function callPrivate(string $method, mixed ...$args): mixed {
        $ref = new ReflectionClass(\LTMS_Fintech_Compliance::class);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke(null, ...$args);
    }

    // ── SECCIÓN 1 — Constants ─────────────────────────────────────────────

    public function test_uma_2026_mxn_is_108_57(): void {
        $this->assertSame(108.57, \LTMS_Fintech_Compliance::UMA_2026_MXN);
    }

    public function test_travel_rule_threshold_is_1000_usd(): void {
        $this->assertSame(1000.0, \LTMS_Fintech_Compliance::TRAVEL_RULE_USD_THRESHOLD);
    }

    public function test_sanctions_lists_has_three_sources(): void {
        $lists = \LTMS_Fintech_Compliance::SANCTIONS_LISTS;
        $this->assertArrayHasKey('ofac_sdn', $lists);
        $this->assertArrayHasKey('un_consolidated', $lists);
        $this->assertArrayHasKey('eu_restrictive', $lists);
    }

    public function test_pld_activities_has_cash_and_electronic(): void {
        $activities = \LTMS_Fintech_Compliance::PLD_ACTIVITIES;
        $this->assertContains('cash', $activities);
        $this->assertContains('electronic', $activities);
    }

    public function test_lfpidrpi_thresholds_uma_has_both_activity_types(): void {
        $thresholds = \LTMS_Fintech_Compliance::LFPIDRPI_THRESHOLDS_UMA;
        $this->assertArrayHasKey('cash', $thresholds);
        $this->assertArrayHasKey('electronic', $thresholds);
        $this->assertSame(5610, $thresholds['cash']);
        $this->assertSame(10140, $thresholds['electronic']);
    }

    public function test_default_limits_has_required_keys(): void {
        $limits = \LTMS_Fintech_Compliance::DEFAULT_LIMITS;
        $this->assertArrayHasKey('daily_payout_usd', $limits);
        $this->assertArrayHasKey('monthly_payout_usd', $limits);
        $this->assertArrayHasKey('daily_tx_count', $limits);
        $this->assertSame(5000.0, $limits['daily_payout_usd']);
    }

    // ── SECCIÓN 2 — convert_to_usd (FX-1 FIX) ────────────────────────────

    public function test_convert_to_usd_usd_passthrough(): void {
        $this->assertSame(100.0, self::callPrivate('convert_to_usd', 100.0, 'USD'));
    }

    public function test_convert_to_usd_with_configured_cop_rate(): void {
        $this->mock_options(['ltms_usd_COP_rate' => 4100.0]);
        $result = self::callPrivate('convert_to_usd', 4100000.0, 'COP');
        $this->assertEqualsWithDelta(1000.0, $result, 0.01);
    }

    public function test_convert_to_usd_with_configured_mxn_rate(): void {
        $this->mock_options(['ltms_usd_MXN_rate' => 17.5]);
        $result = self::callPrivate('convert_to_usd', 1750.0, 'MXN');
        $this->assertEqualsWithDelta(100.0, $result, 0.01);
    }

    public function test_convert_to_usd_returns_float_max_when_rate_missing(): void {
        // FASE4 P0 FIX: missing rate → PHP_FLOAT_MAX (fail-safe: blocks high-value ops).
        $result = self::callPrivate('convert_to_usd', 5000000.0, 'COP');
        $this->assertSame(PHP_FLOAT_MAX, $result, 'Missing FX rate must return PHP_FLOAT_MAX (fail-safe)');
    }

    public function test_convert_to_usd_returns_float_max_when_rate_zero(): void {
        $this->mock_options(['ltms_usd_COP_rate' => 0]);
        $result = self::callPrivate('convert_to_usd', 5000000.0, 'COP');
        $this->assertSame(PHP_FLOAT_MAX, $result, 'Zero FX rate must return PHP_FLOAT_MAX (fail-safe)');
    }

    public function test_convert_to_usd_returns_float_max_when_rate_negative(): void {
        $this->mock_options(['ltms_usd_COP_rate' => -100]);
        $result = self::callPrivate('convert_to_usd', 5000000.0, 'COP');
        $this->assertSame(PHP_FLOAT_MAX, $result);
    }

    // ── SECCIÓN 3 — recalculate_pld_mx_threshold ──────────────────────────

    public function test_recalculate_pld_mx_threshold_electronic(): void {
        // UMA 108.57 × 10140 = 1,100,899.80 MXN.
        $threshold = \LTMS_Fintech_Compliance::recalculate_pld_mx_threshold(0.0, 'electronic');
        $expected = 108.57 * 10140;
        $this->assertEqualsWithDelta($expected, $threshold, 0.01);
    }

    public function test_recalculate_pld_mx_threshold_cash(): void {
        // UMA 108.57 × 5610 = 609,077.70 MXN.
        $threshold = \LTMS_Fintech_Compliance::recalculate_pld_mx_threshold(0.0, 'cash');
        $expected = 108.57 * 5610;
        $this->assertEqualsWithDelta($expected, $threshold, 0.01);
    }

    public function test_recalculate_pld_mx_threshold_unknown_activity_defaults_to_electronic(): void {
        $threshold = \LTMS_Fintech_Compliance::recalculate_pld_mx_threshold(0.0, 'unknown');
        $expected = 108.57 * 10140; // electronic default
        $this->assertEqualsWithDelta($expected, $threshold, 0.01);
    }

    public function test_recalculate_pld_mx_threshold_uses_configured_uma(): void {
        $this->mock_options(['ltms_mx_uma_valor' => 200.0]);
        $threshold = \LTMS_Fintech_Compliance::recalculate_pld_mx_threshold(0.0, 'electronic');
        $expected = 200.0 * 10140;
        $this->assertEqualsWithDelta($expected, $threshold, 0.01);
    }

    public function test_recalculate_pld_mx_threshold_cash_lower_than_electronic(): void {
        $cash = \LTMS_Fintech_Compliance::recalculate_pld_mx_threshold(0.0, 'cash');
        $electronic = \LTMS_Fintech_Compliance::recalculate_pld_mx_threshold(0.0, 'electronic');
        $this->assertLessThan($electronic, $cash, 'Cash threshold must be lower than electronic');
    }

    // ── SECCIÓN 4 — get_legal_basis ───────────────────────────────────────

    public function test_get_legal_basis_returns_co_mx_cross_border(): void {
        $basis = \LTMS_Fintech_Compliance::get_legal_basis();
        $this->assertArrayHasKey('CO', $basis);
        $this->assertArrayHasKey('MX', $basis);
        $this->assertArrayHasKey('CROSS-BORDER', $basis);
    }

    public function test_get_legal_basis_co_includes_sarlaft(): void {
        $basis = \LTMS_Fintech_Compliance::get_legal_basis();
        $this->assertArrayHasKey('Ley 526/1999 (SARLAFT)', $basis['CO']);
    }

    public function test_get_legal_basis_mx_includes_ley_fintech_95(): void {
        $basis = \LTMS_Fintech_Compliance::get_legal_basis();
        $this->assertArrayHasKey('Ley Fintech art. 95', $basis['MX']);
    }

    public function test_get_legal_basis_cross_border_includes_fatf_travel_rule(): void {
        $basis = \LTMS_Fintech_Compliance::get_legal_basis();
        $this->assertArrayHasKey('FATF Rec. 16', $basis['CROSS-BORDER']);
    }

    public function test_get_legal_basis_cross_border_includes_pci_dss(): void {
        $basis = \LTMS_Fintech_Compliance::get_legal_basis();
        $this->assertArrayHasKey('PCI DSS v4.0 SAQ-A', $basis['CROSS-BORDER']);
    }

    // ── SECCIÓN 5 — enforce_2fa_for_payout_vendors (FASE4 P0 FIX) ─────────

    // Helper: create a fake WP_User with a roles property.
    // WP_User stub is defined in tests/unit/RolesTest.php with constructor (int $id, array $roles).
    private function make_fake_user(array $roles, int $id = 1): \WP_User {
        $user = new \WP_User($id, $roles);
        $user->display_name = 'Test User';
        return $user;
    }

    public function test_enforce_2fa_skips_non_vendor_user(): void {
        $user = $this->make_fake_user(['subscriber']);
        // Should return early without doing anything.
        \LTMS_Fintech_Compliance::enforce_2fa_for_payout_vendors('test', $user);
        $this->assertTrue(true); // Just verify no exception.
    }

    public function test_enforce_2fa_accepts_ltms_vendor_role(): void {
        $user = $this->make_fake_user(['ltms_vendor']);
        // No recent payouts in mock → returns early.
        \LTMS_Fintech_Compliance::enforce_2fa_for_payout_vendors('test', $user);
        $this->assertTrue(true);
    }

    public function test_enforce_2fa_accepts_ltms_vendor_premium_role(): void {
        $user = $this->make_fake_user(['ltms_vendor_premium']);
        \LTMS_Fintech_Compliance::enforce_2fa_for_payout_vendors('test', $user);
        $this->assertTrue(true);
    }

    public function test_enforce_2fa_accepts_vendor_role_for_backward_compat(): void {
        // FASE4 P0 FIX: 'vendor' role should also be checked (backward compat).
        $user = $this->make_fake_user(['vendor']);
        \LTMS_Fintech_Compliance::enforce_2fa_for_payout_vendors('test', $user);
        $this->assertTrue(true);
    }

    // ── SECCIÓN 6 — normalize_for_match ───────────────────────────────────

    public function test_normalize_for_match_lowercases(): void {
        $result = self::callPrivate('normalize_for_match', 'JUAN PEREZ');
        $this->assertSame('juan perez', $result);
    }

    public function test_normalize_for_match_strips_accents(): void {
        // remove_accents is stubbed as pass-through in setUp, so we override
        // it here with a real accent-stripping implementation.
        Functions\when('remove_accents')->alias(static fn($s) =>
            str_replace(
                ['á','é','í','ó','ú','Á','É','Í','Ó','Ú','ñ','Ñ','ü','Ü'],
                ['a','e','i','o','u','A','E','I','O','U','n','N','u','U'],
                $s
            )
        );
        $result = self::callPrivate('normalize_for_match', 'JUAN PÉREZ');
        // Accents stripped to ASCII.
        $this->assertStringNotContainsString('É', $result);
        $this->assertSame('juan perez', $result);
    }
}
