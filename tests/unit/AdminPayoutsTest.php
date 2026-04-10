<?php
/**
 * AdminPayoutsTest — Tests unitarios para LTMS_Admin_Payouts
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
require_once __DIR__ . '/class-ltms-unit-test-case.php';

/**
 * @covers LTMS_Admin_Payouts
 */
class AdminPayoutsTest extends \LTMS\Tests\Unit\LTMS_Unit_Test_Case
{
    private object $original_wpdb;
    private \LTMS_Admin_Payouts $payouts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->original_wpdb = $GLOBALS['wpdb'] ?? new stdClass();

        // Only define class/trait stubs — no Brain\Monkey function stubs here.
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
        if (!class_exists('LTMS_Utils', false)) {
            eval('final class LTMS_Utils {
                public static function now_utc(): string { return date("Y-m-d H:i:s"); }
            }');
        }

        // sanitize_key is not in bootstrap — register via Brain\Monkey (post-Patchwork)
        \Brain\Monkey\Functions\stubs([
            'sanitize_key' => static fn(string $k): string => strtolower(preg_replace('/[^a-z0-9_\-]/', '', $k)),
        ]);
        $this->require_class('LTMS_Admin_Payouts');
        $this->payouts = new \LTMS_Admin_Payouts();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->original_wpdb;
        parent::tearDown();
    }

    /**
     * Captura wp_send_json_error para inspeccionar el mensaje.
     */
    private function capture_json_error(callable $callable): string
    {
        $captured = null;
        Functions\when('wp_send_json_error')->alias(
            function(mixed $data = null) use (&$captured): void {
                $captured = is_string($data) ? $data : (string)($data ?? 'error');
                throw new \RuntimeException('json_error: ' . $captured);
            }
        );

        try {
            $callable();
        } catch (\RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'json_error: ')) {
                return substr($e->getMessage(), 12);
            }
            throw $e;
        }

        return $captured ?? '';
    }

    /**
     * Captura wp_send_json_success.
     */
    private function capture_json_success(callable $callable): mixed
    {
        $captured = null;
        Functions\when('wp_send_json_success')->alias(
            function(mixed $data = null) use (&$captured): void {
                $captured = $data;
                throw new \RuntimeException('json_success');
            }
        );

        try {
            $callable();
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'json_success') {
                return $captured;
            }
            throw $e;
        }

        return null;
    }

    // ── SECCIÓN 1: init() ─────────────────────────────────────────────────────

    public function test_init_registers_approve_payout_hook(): void
    {
        $actions = [];
        Functions\when('add_action')->alias(
            static function(string $hook) use (&$actions): void { $actions[] = $hook; }
        );

        \LTMS_Admin_Payouts::init();

        $this->assertContains('wp_ajax_ltms_approve_payout', $actions);
    }

    public function test_init_registers_reject_payout_hook(): void
    {
        $actions = [];
        Functions\when('add_action')->alias(
            static function(string $hook) use (&$actions): void { $actions[] = $hook; }
        );

        \LTMS_Admin_Payouts::init();

        $this->assertContains('wp_ajax_ltms_reject_payout', $actions);
    }

    public function test_init_registers_approve_kyc_hook(): void
    {
        $actions = [];
        Functions\when('add_action')->alias(
            static function(string $hook) use (&$actions): void { $actions[] = $hook; }
        );

        \LTMS_Admin_Payouts::init();

        $this->assertContains('wp_ajax_ltms_approve_kyc', $actions);
    }

    public function test_init_registers_reject_kyc_hook(): void
    {
        $actions = [];
        Functions\when('add_action')->alias(
            static function(string $hook) use (&$actions): void { $actions[] = $hook; }
        );

        \LTMS_Admin_Payouts::init();

        $this->assertContains('wp_ajax_ltms_reject_kyc', $actions);
    }

    public function test_init_registers_freeze_wallet_hook(): void
    {
        $actions = [];
        Functions\when('add_action')->alias(
            static function(string $hook) use (&$actions): void { $actions[] = $hook; }
        );

        \LTMS_Admin_Payouts::init();

        $this->assertContains('wp_ajax_ltms_freeze_wallet', $actions);
    }

    public function test_init_registers_unfreeze_wallet_hook(): void
    {
        $actions = [];
        Functions\when('add_action')->alias(
            static function(string $hook) use (&$actions): void { $actions[] = $hook; }
        );

        \LTMS_Admin_Payouts::init();

        $this->assertContains('wp_ajax_ltms_unfreeze_wallet', $actions);
    }

    public function test_init_registers_export_payouts_hook(): void
    {
        $actions = [];
        Functions\when('add_action')->alias(
            static function(string $hook) use (&$actions): void { $actions[] = $hook; }
        );

        \LTMS_Admin_Payouts::init();

        $this->assertContains('wp_ajax_ltms_export_payouts', $actions);
    }

    public function test_init_registers_seven_hooks(): void
    {
        $count = 0;
        Functions\when('add_action')->alias(
            static function() use (&$count): void { $count++; }
        );

        \LTMS_Admin_Payouts::init();

        $this->assertSame(7, $count);
    }

    // ── SECCIÓN 2: ajax_approve_payout() ─────────────────────────────────────

    public function test_approve_payout_sends_error_when_no_permission(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('check_ajax_referer')->justReturn(true);
        $_POST = [];

        $msg = $this->capture_json_error(fn() => $this->payouts->ajax_approve_payout());

        $this->assertNotEmpty($msg);
    }

    public function test_approve_payout_sends_error_when_missing_payout_id(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('check_ajax_referer')->justReturn(true);
        $_POST = ['payout_id' => '0'];

        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            public function prepare(string $sql, mixed ...$args): string { return $sql; }
            public function get_var(mixed $q = null): mixed { return null; }
        };

        $msg = $this->capture_json_error(fn() => $this->payouts->ajax_approve_payout());

        $this->assertNotEmpty($msg);
    }

    // ── SECCIÓN 3: ajax_reject_payout() ──────────────────────────────────────

    public function test_reject_payout_sends_error_when_no_permission(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('check_ajax_referer')->justReturn(true);
        $_POST = [];

        $msg = $this->capture_json_error(fn() => $this->payouts->ajax_reject_payout());

        $this->assertNotEmpty($msg);
    }

    public function test_reject_payout_sends_error_when_no_reason(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('check_ajax_referer')->justReturn(true);
        $_POST = ['payout_id' => '5', 'reason' => ''];

        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            public function prepare(string $sql, mixed ...$args): string { return $sql; }
            public function get_var(mixed $q = null): mixed { return null; }
        };

        $msg = $this->capture_json_error(fn() => $this->payouts->ajax_reject_payout());

        $this->assertNotEmpty($msg);
    }

    // ── SECCIÓN 4: ajax_approve_kyc() ────────────────────────────────────────

    public function test_approve_kyc_sends_error_when_no_permission(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('check_ajax_referer')->justReturn(true);
        $_POST = [];

        $msg = $this->capture_json_error(fn() => $this->payouts->ajax_approve_kyc());

        $this->assertNotEmpty($msg);
    }

    public function test_approve_kyc_sends_error_when_missing_kyc_id(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('check_ajax_referer')->justReturn(true);
        $_POST = ['kyc_id' => '0'];

        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            public function prepare(string $sql, mixed ...$args): string { return $sql; }
            public function get_var(mixed $q = null): mixed { return null; }
            public function update(string $t, array $d, array $w, mixed $f = null, mixed $wf = null): int { return 0; }
        };

        $msg = $this->capture_json_error(fn() => $this->payouts->ajax_approve_kyc());

        $this->assertNotEmpty($msg);
    }

    // ── SECCIÓN 5: ajax_reject_kyc() ─────────────────────────────────────────

    public function test_reject_kyc_sends_error_when_no_permission(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('check_ajax_referer')->justReturn(true);
        $_POST = [];

        $msg = $this->capture_json_error(fn() => $this->payouts->ajax_reject_kyc());

        $this->assertNotEmpty($msg);
    }

    public function test_reject_kyc_sends_error_when_missing_data(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('check_ajax_referer')->justReturn(true);
        $_POST = ['kyc_id' => '0', 'reason' => ''];

        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            public function prepare(string $sql, mixed ...$args): string { return $sql; }
            public function get_var(mixed $q = null): mixed { return null; }
        };

        $msg = $this->capture_json_error(fn() => $this->payouts->ajax_reject_kyc());

        $this->assertNotEmpty($msg);
    }

    // ── SECCIÓN 6: ajax_freeze_wallet() ──────────────────────────────────────

    public function test_freeze_wallet_sends_error_when_no_permission(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('check_ajax_referer')->justReturn(true);
        $_POST = [];

        $msg = $this->capture_json_error(fn() => $this->payouts->ajax_freeze_wallet());

        $this->assertNotEmpty($msg);
    }

    public function test_freeze_wallet_sends_error_when_no_vendor_id(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('check_ajax_referer')->justReturn(true);
        $_POST = ['vendor_id' => '0', 'reason' => 'cumplimiento'];

        $msg = $this->capture_json_error(fn() => $this->payouts->ajax_freeze_wallet());

        $this->assertNotEmpty($msg);
    }

    public function test_freeze_wallet_sends_error_when_no_reason(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('check_ajax_referer')->justReturn(true);
        $_POST = ['vendor_id' => '5', 'reason' => ''];

        $msg = $this->capture_json_error(fn() => $this->payouts->ajax_freeze_wallet());

        $this->assertNotEmpty($msg);
    }

    // ── SECCIÓN 7: ajax_unfreeze_wallet() ────────────────────────────────────

    public function test_unfreeze_wallet_sends_error_when_no_permission(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('check_ajax_referer')->justReturn(true);
        $_POST = [];

        $msg = $this->capture_json_error(fn() => $this->payouts->ajax_unfreeze_wallet());

        $this->assertNotEmpty($msg);
    }

    public function test_unfreeze_wallet_sends_error_when_no_vendor_id(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('check_ajax_referer')->justReturn(true);
        $_POST = ['vendor_id' => '0'];

        $msg = $this->capture_json_error(fn() => $this->payouts->ajax_unfreeze_wallet());

        $this->assertNotEmpty($msg);
    }

    // ── SECCIÓN 8: CSV ─────────────────────────────────────────────────────────

    public function test_export_payouts_sends_error_when_no_permission(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('check_ajax_referer')->justReturn(true);
        $_POST = [];

        $msg = $this->capture_json_error(fn() => $this->payouts->ajax_export_payouts());

        $this->assertNotEmpty($msg);
    }

    public function test_export_payouts_with_empty_results_returns_zero_count(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('check_ajax_referer')->justReturn(true);
        $_POST = ['status' => ''];

        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            public string $users  = 'wp_users';
            public function prepare(string $sql, mixed ...$args): string { return $sql; }
            public function get_results(mixed $q = null, string $output = OBJECT): array { return []; }
        };

        $result = $this->capture_json_success(fn() => $this->payouts->ajax_export_payouts());

        $this->assertIsArray($result);
        $this->assertArrayHasKey('count', $result);
        $this->assertSame(0, $result['count']);
    }

    public function test_export_payouts_csv_contains_header_row(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('check_ajax_referer')->justReturn(true);
        $_POST = ['status' => ''];

        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            public string $users  = 'wp_users';
            public function prepare(string $sql, mixed ...$args): string { return $sql; }
            public function get_results(mixed $q = null, string $output = OBJECT): array { return []; }
        };

        $result = $this->capture_json_success(fn() => $this->payouts->ajax_export_payouts());

        $csv = base64_decode($result['csv'] ?? '');
        $this->assertStringContainsString('ID,Vendedor,Email', $csv);
    }

    // ── SECCIÓN 9: Reflexión ─────────────────────────────────────────────────

    public function test_class_is_final(): void
    {
        $rc = new \ReflectionClass(\LTMS_Admin_Payouts::class);
        $this->assertTrue($rc->isFinal());
    }

    public function test_init_is_public_static(): void
    {
        $rm = new \ReflectionMethod(\LTMS_Admin_Payouts::class, 'init');
        $this->assertTrue($rm->isPublic());
        $this->assertTrue($rm->isStatic());
    }

    public function test_ajax_approve_payout_is_public(): void
    {
        $rm = new \ReflectionMethod(\LTMS_Admin_Payouts::class, 'ajax_approve_payout');
        $this->assertTrue($rm->isPublic());
    }

    public function test_ajax_reject_payout_is_public(): void
    {
        $rm = new \ReflectionMethod(\LTMS_Admin_Payouts::class, 'ajax_reject_payout');
        $this->assertTrue($rm->isPublic());
    }

    public function test_ajax_export_payouts_is_public(): void
    {
        $rm = new \ReflectionMethod(\LTMS_Admin_Payouts::class, 'ajax_export_payouts');
        $this->assertTrue($rm->isPublic());
    }

    public function test_get_vendor_id_by_kyc_is_private(): void
    {
        $rm = new \ReflectionMethod(\LTMS_Admin_Payouts::class, 'get_vendor_id_by_kyc');
        $this->assertTrue($rm->isPrivate());
    }

    public function test_class_has_logger_aware_trait(): void
    {
        $rc     = new \ReflectionClass(\LTMS_Admin_Payouts::class);
        $traits = array_keys($rc->getTraits());
        $this->assertContains('LTMS_Logger_Aware', $traits);
    }
}

