<?php
/**
 * AdminBookingsTest — Tests unitarios para LTMS_Admin_Bookings
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
require_once __DIR__ . '/class-ltms-unit-test-case.php';

/**
 * @covers LTMS_Admin_Bookings
 */
class AdminBookingsTest extends \LTMS\Tests\Unit\LTMS_Unit_Test_Case
{
    private object $original_wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->original_wpdb = $GLOBALS['wpdb'] ?? new stdClass();

        defined('LTMS_PLUGIN_DIR') || define('LTMS_PLUGIN_DIR', '/tmp/ltms/');

        // Only define class stubs — no Brain\Monkey function stubs here.
        if (!class_exists('WP_Error', false)) {
            eval('class WP_Error {
                private string $code; private string $msg;
                public function __construct(string $c = "", string $m = "") { $this->code = $c; $this->msg = $m; }
                public function get_error_message(): string { return $this->msg; }
                public function get_error_code(): string { return $this->code; }
            }');
        }

        $this->reset_initialized_flag();
        $this->require_class('LTMS_Admin_Bookings');
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->original_wpdb;
        $this->reset_initialized_flag();
        parent::tearDown();
    }

    private function reset_initialized_flag(): void
    {
        if (class_exists('LTMS_Admin_Bookings', false)) {
            try {
                $rp = new \ReflectionProperty(\LTMS_Admin_Bookings::class, 'initialized');
                $rp->setAccessible(true);
                $rp->setValue(null, false);
            } catch (\ReflectionException) {}
        }
    }

    // ── SECCIÓN 1: Singleton guard ────────────────────────────────────────────

    public function test_init_only_initializes_once(): void
    {
        $count = 0;
        Functions\when('add_action')->alias(
            static function(string $hook) use (&$count): void {
                if ($hook === 'admin_menu') { $count++; }
            }
        );

        \LTMS_Admin_Bookings::init();
        \LTMS_Admin_Bookings::init();
        \LTMS_Admin_Bookings::init();

        $this->assertSame(1, $count, 'admin_menu hook should only be registered once');
    }

    // ── SECCIÓN 2: Hooks de init() ────────────────────────────────────────────

    public function test_init_registers_admin_menu_hook(): void
    {
        $hooks = [];
        Functions\when('add_action')->alias(
            static function(string $hook) use (&$hooks): void { $hooks[] = $hook; }
        );

        \LTMS_Admin_Bookings::init();

        $this->assertContains('admin_menu', $hooks);
    }

    public function test_init_registers_admin_booking_action_hook(): void
    {
        $hooks = [];
        Functions\when('add_action')->alias(
            static function(string $hook) use (&$hooks): void { $hooks[] = $hook; }
        );

        \LTMS_Admin_Bookings::init();

        $this->assertContains('wp_ajax_ltms_admin_booking_action', $hooks);
    }

    public function test_init_registers_admin_verify_rnt_hook(): void
    {
        $hooks = [];
        Functions\when('add_action')->alias(
            static function(string $hook) use (&$hooks): void { $hooks[] = $hook; }
        );

        \LTMS_Admin_Bookings::init();

        $this->assertContains('wp_ajax_ltms_admin_verify_rnt', $hooks);
    }

    public function test_init_registers_export_csv_post_hook(): void
    {
        $hooks = [];
        Functions\when('add_action')->alias(
            static function(string $hook) use (&$hooks): void { $hooks[] = $hook; }
        );

        \LTMS_Admin_Bookings::init();

        $this->assertContains('admin_post_ltms_export_bookings_csv', $hooks);
    }

    public function test_init_registers_four_hooks(): void
    {
        $count = 0;
        Functions\when('add_action')->alias(
            static function() use (&$count): void { $count++; }
        );

        \LTMS_Admin_Bookings::init();

        $this->assertSame(4, $count);
    }

    // ── SECCIÓN 3: add_menu_pages() ───────────────────────────────────────────

    public function test_add_menu_pages_registers_bookings_submenu(): void
    {
        $slugs = [];
        Functions\when('add_submenu_page')->alias(
            static function(string $parent, string $pt, string $mt, string $cap, string $slug) use (&$slugs): void {
                $slugs[] = $slug;
            }
        );

        \LTMS_Admin_Bookings::add_menu_pages();

        $this->assertContains('ltms-bookings', $slugs);
    }

    public function test_add_menu_pages_registers_calendar_submenu(): void
    {
        $slugs = [];
        Functions\when('add_submenu_page')->alias(
            static function(string $parent, string $pt, string $mt, string $cap, string $slug) use (&$slugs): void {
                $slugs[] = $slug;
            }
        );

        \LTMS_Admin_Bookings::add_menu_pages();

        $this->assertContains('ltms-booking-calendar', $slugs);
    }

    public function test_add_menu_pages_registers_tourism_compliance_submenu(): void
    {
        $slugs = [];
        Functions\when('add_submenu_page')->alias(
            static function(string $parent, string $pt, string $mt, string $cap, string $slug) use (&$slugs): void {
                $slugs[] = $slug;
            }
        );

        \LTMS_Admin_Bookings::add_menu_pages();

        $this->assertContains('ltms-tourism-compliance', $slugs);
    }

    public function test_add_menu_pages_registers_exactly_three_submenus(): void
    {
        $count = 0;
        Functions\when('add_submenu_page')->alias(
            static function() use (&$count): void { $count++; }
        );

        \LTMS_Admin_Bookings::add_menu_pages();

        $this->assertSame(3, $count);
    }

    // ── SECCIÓN 4: handle_ajax() guard ───────────────────────────────────────

    public function test_handle_ajax_sends_error_when_no_permission(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('wp_send_json_error')->alias(
            static fn(): never => throw new \RuntimeException('json_error')
        );
        $_POST = ['booking_action' => 'cancel', 'booking_id' => '5'];

        $threw = false;
        try {
            \LTMS_Admin_Bookings::handle_ajax();
        } catch (\RuntimeException $e) {
            $threw = str_contains($e->getMessage(), 'json_error');
        }

        $this->assertTrue($threw);
    }

    public function test_handle_ajax_sends_error_for_unknown_action(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('wp_send_json_error')->alias(
            static fn(): never => throw new \RuntimeException('json_error')
        );
        $_POST = ['booking_action' => 'unknown_action', 'booking_id' => '5'];

        $threw = false;
        try {
            \LTMS_Admin_Bookings::handle_ajax();
        } catch (\RuntimeException $e) {
            $threw = str_contains($e->getMessage(), 'json_error');
        }

        $this->assertTrue($threw);
    }

    public function test_handle_ajax_sends_error_when_booking_id_missing(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('wp_send_json_error')->alias(
            static fn(): never => throw new \RuntimeException('json_error')
        );
        $_POST = ['booking_action' => 'cancel', 'booking_id' => '0'];

        $threw = false;
        try {
            \LTMS_Admin_Bookings::handle_ajax();
        } catch (\RuntimeException $e) {
            $threw = str_contains($e->getMessage(), 'json_error');
        }

        $this->assertTrue($threw);
    }

    // ── SECCIÓN 5: ajax_verify_rnt() guard ───────────────────────────────────

    public function test_ajax_verify_rnt_sends_error_when_no_permission(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('wp_send_json_error')->alias(
            static fn(): never => throw new \RuntimeException('json_error')
        );
        $_POST = ['vendor_id' => '5', 'approved' => '1', 'notes' => ''];

        $threw = false;
        try {
            \LTMS_Admin_Bookings::ajax_verify_rnt();
        } catch (\RuntimeException $e) {
            $threw = str_contains($e->getMessage(), 'json_error');
        }

        $this->assertTrue($threw);
    }

    // ── SECCIÓN 6: export_csv() guard ────────────────────────────────────────

    public function test_export_csv_dies_without_manage_options(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('check_admin_referer')->justReturn(true);
        Functions\when('wp_die')->alias(
            static fn(): never => throw new \RuntimeException('wp_die')
        );

        $threw = false;
        try {
            \LTMS_Admin_Bookings::export_csv();
        } catch (\RuntimeException $e) {
            $threw = str_contains($e->getMessage(), 'wp_die');
        }

        $this->assertTrue($threw);
    }

    // ── SECCIÓN 7: render_booking_calendar() ─────────────────────────────────

    public function test_render_booking_calendar_outputs_wrap_div(): void
    {
        Functions\when('wp_enqueue_script')->alias(static function(): void {});
        Functions\when('wp_add_inline_script')->alias(static function(): void {});

        ob_start();
        \LTMS_Admin_Bookings::render_booking_calendar();
        $output = ob_get_clean();

        $this->assertStringContainsString('wrap', $output);
    }

    public function test_render_booking_calendar_enqueues_fullcalendar(): void
    {
        $enqueued = [];
        Functions\when('wp_enqueue_script')->alias(
            static function(string $handle) use (&$enqueued): void { $enqueued[] = $handle; }
        );
        Functions\when('wp_add_inline_script')->alias(static function(): void {});

        ob_start();
        \LTMS_Admin_Bookings::render_booking_calendar();
        ob_end_clean();

        $this->assertContains('fullcalendar', $enqueued);
    }

    public function test_render_booking_calendar_includes_calendar_div_id(): void
    {
        Functions\when('wp_enqueue_script')->alias(static function(): void {});
        Functions\when('wp_add_inline_script')->alias(static function(): void {});

        ob_start();
        \LTMS_Admin_Bookings::render_booking_calendar();
        $output = ob_get_clean();

        $this->assertStringContainsString('ltms-admin-booking-calendar', $output);
    }

    // ── SECCIÓN 8: Reflexión ─────────────────────────────────────────────────

    public function test_class_is_not_final(): void
    {
        $rc = new \ReflectionClass(\LTMS_Admin_Bookings::class);
        $this->assertFalse($rc->isFinal());
    }

    public function test_init_is_public_static(): void
    {
        $rm = new \ReflectionMethod(\LTMS_Admin_Bookings::class, 'init');
        $this->assertTrue($rm->isPublic());
        $this->assertTrue($rm->isStatic());
    }

    public function test_add_menu_pages_is_public_static(): void
    {
        $rm = new \ReflectionMethod(\LTMS_Admin_Bookings::class, 'add_menu_pages');
        $this->assertTrue($rm->isPublic());
        $this->assertTrue($rm->isStatic());
    }

    public function test_handle_ajax_is_public_static(): void
    {
        $rm = new \ReflectionMethod(\LTMS_Admin_Bookings::class, 'handle_ajax');
        $this->assertTrue($rm->isPublic());
        $this->assertTrue($rm->isStatic());
    }

    public function test_ajax_verify_rnt_is_public_static(): void
    {
        $rm = new \ReflectionMethod(\LTMS_Admin_Bookings::class, 'ajax_verify_rnt');
        $this->assertTrue($rm->isPublic());
        $this->assertTrue($rm->isStatic());
    }

    public function test_export_csv_is_public_static(): void
    {
        $rm = new \ReflectionMethod(\LTMS_Admin_Bookings::class, 'export_csv');
        $this->assertTrue($rm->isPublic());
        $this->assertTrue($rm->isStatic());
    }

    public function test_initialized_property_is_private_static(): void
    {
        $rp = new \ReflectionProperty(\LTMS_Admin_Bookings::class, 'initialized');
        $this->assertTrue($rp->isStatic());
        $this->assertTrue($rp->isPrivate());
    }

    public function test_render_bookings_list_is_public_static(): void
    {
        $rm = new \ReflectionMethod(\LTMS_Admin_Bookings::class, 'render_bookings_list');
        $this->assertTrue($rm->isPublic());
        $this->assertTrue($rm->isStatic());
    }

    public function test_render_compliance_panel_is_public_static(): void
    {
        $rm = new \ReflectionMethod(\LTMS_Admin_Bookings::class, 'render_compliance_panel');
        $this->assertTrue($rm->isPublic());
        $this->assertTrue($rm->isStatic());
    }
}
