<?php
/**
 * AdminTest — Tests unitarios para LTMS_Admin
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
require_once __DIR__ . '/class-ltms-unit-test-case.php';

/**
 * @covers LTMS_Admin
 */
class AdminTest extends \LTMS\Tests\Unit\LTMS_Unit_Test_Case
{
    private \LTMS_Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        defined('LTMS_VERSION')         || define('LTMS_VERSION',         '1.5.0');
        defined('LTMS_ASSETS_URL')      || define('LTMS_ASSETS_URL',      'http://example.com/assets/');
        defined('LTMS_INCLUDES_DIR')    || define('LTMS_INCLUDES_DIR',    '/tmp/ltms/includes/');
        defined('LTMS_PLUGIN_BASENAME') || define('LTMS_PLUGIN_BASENAME', 'lt-marketplace-suite/lt-marketplace-suite.php');

        // Stub classes that LTMS_Admin depends on
        if (!class_exists('LTMS_Data_Masking', false)) {
            eval('final class LTMS_Data_Masking {
                public static function log_auditor_access(string $section): void {}
                public static function is_external_auditor(): bool {
                    return current_user_can("ltms_external_auditor");
                }
                public static function prepare_for_auditor(array $data): array {
                    if (!self::is_external_auditor()) { return $data; }
                    $mask_map = [
                        "customer_email"     => fn($v) => self::mask_email($v),
                        "billing_email"      => fn($v) => self::mask_email($v),
                        "vendor_email"       => fn($v) => self::mask_email($v),
                        "customer_phone"     => fn($v) => self::mask_phone($v),
                        "billing_phone"      => fn($v) => self::mask_phone($v),
                        "vendor_phone"       => fn($v) => self::mask_phone($v),
                        "customer_name"      => fn($v) => self::mask_name($v),
                        "billing_first_name" => fn($v) => self::mask_name($v),
                        "billing_last_name"  => fn($v) => self::mask_name($v),
                        "billing_address_1"  => fn($v) => self::mask_address($v),
                        "billing_address_2"  => fn($v) => "***",
                        "bank_account"       => fn($v) => self::mask_bank_account($v),
                        "document_number"    => fn($v) => self::mask_document($v),
                    ];
                    foreach ($mask_map as $field => $masker) {
                        if (isset($data[$field]) && !empty($data[$field])) {
                            $data[$field] = $masker($data[$field]);
                        }
                    }
                    return $data;
                }
                public static function mask_email(string $email): string {
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return "****@****.***";
                    [$u, $d] = explode("@", $email);
                    $dp = explode(".", $d);
                    return $u[0] . str_repeat("*", max(3, strlen($u)-1)) . "@" . $dp[0][0] . str_repeat("*", max(2, strlen($dp[0])-1)) . "." . end($dp);
                }
                public static function mask_phone(string $phone): string {
                    $c = preg_replace("/[^0-9]/", "", $phone);
                    return strlen($c) < 4 ? "***-***-****" : "***-***-" . substr($c, -4);
                }
                public static function mask_name(string $name): string {
                    $words = explode(" ", trim($name)); $m = [];
                    foreach ($words as $w) { $m[] = strlen($w) > 1 ? $w[0] . str_repeat("*", strlen($w)-1) : "*"; }
                    return implode(" ", $m);
                }
                public static function mask_address(string $address): string {
                    $words = explode(" ", trim($address));
                    if (count($words) <= 2) return "*** ***";
                    return $words[0] . " " . str_repeat("*", strlen($words[1] ?? "***")) . " ***";
                }
                public static function mask_bank_account(string $account): string {
                    if (str_starts_with(trim($account), "{")) return "****" . substr($account, -4);
                    $c = preg_replace("/[^0-9]/", "", $account);
                    return strlen($c) < 4 ? "****" : str_repeat("*", max(4, strlen($c)-4)) . substr($c, -4);
                }
                public static function mask_document(string $doc): string {
                    $c = preg_replace("/[^a-zA-Z0-9]/", "", $doc);
                    return strlen($c) < 4 ? "****" : str_repeat("*", max(4, strlen($c)-4)) . substr($c, -4);
                }
            }');
        }
        if (!trait_exists('LTMS_Logger_Aware', false)) {
            eval('trait LTMS_Logger_Aware {}');
        }
        if (!class_exists('LTMS_Core_Logger', false)) {
            eval('final class LTMS_Core_Logger {
                public static function info(string $c, string $m, array $ctx = []): void {}
                public static function error(string $c, string $m, array $ctx = []): void {}
                public static function security(string $c, string $m, array $ctx = []): void {}
            }');
        }

        \LTMS_Core_Config::set('LTMS_CURRENCY', 'COP');
        \LTMS_Core_Config::set('LTMS_COUNTRY', 'CO');

        // Stub WP functions used by LTMS_Admin before class is loaded.
        // Must use Brain\Monkey (not function_exists) so they're always available per-test.
        Functions\when('add_menu_page')->justReturn('ltms-suite');
        Functions\when('add_submenu_page')->justReturn('ltms-suite');
        Functions\when('sanitize_key')->alias(
            static fn(string $k): string => strtolower(preg_replace('/[^a-z0-9_\-]/', '', $k))
        );
        $this->require_class('LTMS_Admin');
        $this->admin = new \LTMS_Admin();
    }

    // ── SECCIÓN 1: init() ─────────────────────────────────────────────────────

    public function test_init_registers_admin_menu_hook(): void
    {
        $hooks = [];
        Functions\when('add_action')->alias(
            static function(string $hook) use (&$hooks): void { $hooks[] = $hook; }
        );
        Functions\when('add_filter')->alias(static function(): void {});

        \LTMS_Admin::init();

        $this->assertContains('admin_menu', $hooks);
    }

    public function test_init_registers_admin_enqueue_scripts_hook(): void
    {
        $hooks = [];
        Functions\when('add_action')->alias(
            static function(string $hook) use (&$hooks): void { $hooks[] = $hook; }
        );
        Functions\when('add_filter')->alias(static function(): void {});

        \LTMS_Admin::init();

        $this->assertContains('admin_enqueue_scripts', $hooks);
    }

    public function test_init_registers_admin_init_hook(): void
    {
        $hooks = [];
        Functions\when('add_action')->alias(
            static function(string $hook) use (&$hooks): void { $hooks[] = $hook; }
        );
        Functions\when('add_filter')->alias(static function(): void {});

        \LTMS_Admin::init();

        $this->assertContains('admin_init', $hooks);
    }

    public function test_init_registers_admin_notices_hook(): void
    {
        $hooks = [];
        Functions\when('add_action')->alias(
            static function(string $hook) use (&$hooks): void { $hooks[] = $hook; }
        );
        Functions\when('add_filter')->alias(static function(): void {});

        \LTMS_Admin::init();

        $this->assertContains('admin_notices', $hooks);
    }

    public function test_init_registers_plugin_action_links_filter(): void
    {
        $filters = [];
        Functions\when('add_filter')->alias(
            static function(string $hook) use (&$filters): void { $filters[] = $hook; }
        );
        Functions\when('add_action')->alias(static function(): void {});

        \LTMS_Admin::init();

        $found = false;
        foreach ($filters as $filter) {
            if (str_contains($filter, 'plugin_action_links_')) { $found = true; break; }
        }
        $this->assertTrue($found, 'Expected plugin_action_links_ filter to be registered');
    }

    // ── SECCIÓN 2: register_menus() ───────────────────────────────────────────

    public function test_register_menus_calls_add_submenu_page(): void
    {
        $submenus = [];
        Functions\when('add_submenu_page')->alias(
            static function(string $parent, string $pt, string $mt, string $cap, string $slug) use (&$submenus): void {
                $submenus[] = $slug;
            }
        );

        Functions\when('current_user_can')->justReturn(true);
        $this->admin->register_menus();

        $this->assertNotEmpty($submenus, 'Expected at least one submenu page registered');
    }

    public function test_register_menus_includes_dashboard_slug(): void
    {
        $slugs = [];
        Functions\when('add_submenu_page')->alias(
            static function(string $parent, string $pt, string $mt, string $cap, string $slug) use (&$slugs): void {
                $slugs[] = $slug;
            }
        );

        Functions\when('current_user_can')->justReturn(true);
        $this->admin->register_menus();

        $this->assertContains('ltms-dashboard', $slugs);
    }

    public function test_register_menus_includes_settings_slug(): void
    {
        $slugs = [];
        Functions\when('add_submenu_page')->alias(
            static function(string $parent, string $pt, string $mt, string $cap, string $slug) use (&$slugs): void {
                $slugs[] = $slug;
            }
        );

        Functions\when('current_user_can')->justReturn(true);
        $this->admin->register_menus();

        $this->assertContains('ltms-settings', $slugs);
    }

    public function test_register_menus_includes_vendors_slug(): void
    {
        $slugs = [];
        Functions\when('add_submenu_page')->alias(
            static function(string $parent, string $pt, string $mt, string $cap, string $slug) use (&$slugs): void {
                $slugs[] = $slug;
            }
        );

        Functions\when('current_user_can')->justReturn(true);
        $this->admin->register_menus();

        $this->assertContains('ltms-vendors', $slugs);
    }

    public function test_register_menus_includes_kyc_slug(): void
    {
        $slugs = [];
        Functions\when('add_submenu_page')->alias(
            static function(string $parent, string $pt, string $mt, string $cap, string $slug) use (&$slugs): void {
                $slugs[] = $slug;
            }
        );

        Functions\when('current_user_can')->justReturn(true);
        $this->admin->register_menus();

        $found = false;
        foreach ($slugs as $slug) {
            if (str_contains($slug, 'kyc')) { $found = true; break; }
        }
        $this->assertTrue($found, 'Expected a KYC submenu slug');
    }

    public function test_register_menus_includes_payouts_slug(): void
    {
        $slugs = [];
        Functions\when('add_submenu_page')->alias(
            static function(string $parent, string $pt, string $mt, string $cap, string $slug) use (&$slugs): void {
                $slugs[] = $slug;
            }
        );

        Functions\when('current_user_can')->justReturn(true);
        $this->admin->register_menus();

        $found = false;
        foreach ($slugs as $slug) {
            if (str_contains($slug, 'payout')) { $found = true; break; }
        }
        $this->assertTrue($found, 'Expected a payouts submenu slug');
    }

    public function test_register_menus_includes_bookings_slug(): void
    {
        $slugs = [];
        Functions\when('add_submenu_page')->alias(
            static function(string $parent, string $pt, string $mt, string $cap, string $slug) use (&$slugs): void {
                $slugs[] = $slug;
            }
        );

        Functions\when('current_user_can')->justReturn(true);
        $this->admin->register_menus();

        // The class registers orders as 'ltms-orders' (not 'booking').
        $found = false;
        foreach ($slugs as $slug) {
            if (str_contains($slug, 'order') || str_contains($slug, 'booking')) { $found = true; break; }
        }
        $this->assertTrue($found, 'Expected an orders/bookings submenu slug');
    }

    public function test_register_menus_registers_multiple_submenus(): void
    {
        $count = 0;
        Functions\when('add_submenu_page')->alias(
            static function() use (&$count): void { $count++; }
        );

        Functions\when('current_user_can')->justReturn(true);
        $this->admin->register_menus();

        $this->assertGreaterThanOrEqual(5, $count, 'Expected at least 5 submenu pages');
    }

    // ── SECCIÓN 3: enqueue_assets() ───────────────────────────────────────────

    public function test_enqueue_assets_does_nothing_on_non_ltms_page(): void
    {
        $enqueued = 0;
        Functions\when('wp_enqueue_style')->alias(static function() use (&$enqueued): void { $enqueued++; });
        Functions\when('wp_enqueue_script')->alias(static function() use (&$enqueued): void { $enqueued++; });

        $this->admin->enqueue_assets('edit.php');

        $this->assertSame(0, $enqueued, 'Should not enqueue anything on non-LTMS pages');
    }

    public function test_enqueue_assets_enqueues_on_ltms_page(): void
    {
        $styles  = [];
        $scripts = [];
        Functions\when('wp_enqueue_style')->alias(
            static function(string $handle) use (&$styles): void { $styles[] = $handle; }
        );
        Functions\when('wp_enqueue_script')->alias(
            static function(string $handle) use (&$scripts): void { $scripts[] = $handle; }
        );
        Functions\when('wp_localize_script')->alias(static function(): void {});
        Functions\when('wp_add_inline_script')->alias(static function(): void {});
        Functions\when('admin_url')->alias(static fn(): string => 'http://example.com/wp-admin/admin-ajax.php');
        Functions\when('rest_url')->alias(static fn(): string => 'http://example.com/wp-json/');
        Functions\when('wp_create_nonce')->alias(static fn(): string => 'test-nonce');

        $this->admin->enqueue_assets('toplevel_page_ltms-dashboard');

        $this->assertNotEmpty($styles, 'Should enqueue at least one style on LTMS pages');
        $this->assertNotEmpty($scripts, 'Should enqueue at least one script on LTMS pages');
    }

    public function test_enqueue_assets_includes_ltms_admin_style(): void
    {
        $styles = [];
        Functions\when('wp_enqueue_style')->alias(
            static function(string $handle) use (&$styles): void { $styles[] = $handle; }
        );
        Functions\when('wp_enqueue_script')->alias(static function(): void {});
        Functions\when('wp_localize_script')->alias(static function(): void {});
        Functions\when('wp_add_inline_script')->alias(static function(): void {});
        Functions\when('admin_url')->alias(static fn(): string => 'http://example.com/wp-admin/admin-ajax.php');
        Functions\when('rest_url')->alias(static fn(): string => 'http://example.com/wp-json/');
        Functions\when('wp_create_nonce')->alias(static fn(): string => 'test-nonce');

        $this->admin->enqueue_assets('ltms-settings');

        $this->assertContains('ltms-admin', $styles);
    }

    public function test_enqueue_assets_includes_chartjs(): void
    {
        $scripts = [];
        Functions\when('wp_enqueue_style')->alias(static function(): void {});
        Functions\when('wp_enqueue_script')->alias(
            static function(string $handle) use (&$scripts): void { $scripts[] = $handle; }
        );
        Functions\when('wp_localize_script')->alias(static function(): void {});
        Functions\when('wp_add_inline_script')->alias(static function(): void {});
        Functions\when('admin_url')->alias(static fn(): string => 'http://example.com/wp-admin/admin-ajax.php');
        Functions\when('rest_url')->alias(static fn(): string => 'http://example.com/wp-json/');
        Functions\when('wp_create_nonce')->alias(static fn(): string => 'test-nonce');

        $this->admin->enqueue_assets('ltms-dashboard');

        $this->assertContains('chart-js', $scripts);
    }

    public function test_enqueue_assets_calls_wp_localize_script(): void
    {
        $localized = [];
        Functions\when('wp_enqueue_style')->alias(static function(): void {});
        Functions\when('wp_enqueue_script')->alias(static function(): void {});
        Functions\when('wp_localize_script')->alias(
            static function(string $handle, string $obj, array $data) use (&$localized): void {
                $localized[$handle] = ['obj' => $obj, 'data' => $data];
            }
        );
        Functions\when('wp_add_inline_script')->alias(static function(): void {});
        Functions\when('admin_url')->alias(static fn(): string => 'http://example.com/wp-admin/admin-ajax.php');
        Functions\when('rest_url')->alias(static fn(): string => 'http://example.com/wp-json/');
        Functions\when('wp_create_nonce')->alias(static fn(): string => 'test-nonce');

        $this->admin->enqueue_assets('ltms-settings');

        $this->assertArrayHasKey('ltms-admin', $localized);
        $this->assertSame('ltmsAdmin', $localized['ltms-admin']['obj']);
    }

    public function test_enqueue_assets_localized_data_has_ajax_url(): void
    {
        $data_out = null;
        Functions\when('wp_enqueue_style')->alias(static function(): void {});
        Functions\when('wp_enqueue_script')->alias(static function(): void {});
        Functions\when('wp_localize_script')->alias(
            static function(string $handle, string $obj, array $data) use (&$data_out): void {
                if ($handle === 'ltms-admin') { $data_out = $data; }
            }
        );
        Functions\when('wp_add_inline_script')->alias(static function(): void {});
        Functions\when('admin_url')->alias(static fn(): string => 'http://example.com/wp-admin/admin-ajax.php');
        Functions\when('rest_url')->alias(static fn(): string => 'http://example.com/wp-json/');
        Functions\when('wp_create_nonce')->alias(static fn(): string => 'test-nonce');

        $this->admin->enqueue_assets('ltms-settings');

        $this->assertArrayHasKey('ajax_url', $data_out);
        $this->assertArrayHasKey('nonce', $data_out);
    }

    // ── SECCIÓN 4: add_plugin_links() ────────────────────────────────────────

    public function test_add_plugin_links_prepends_to_existing_links(): void
    {
        Functions\when('admin_url')->alias(static fn(string $p = ''): string => 'http://example.com/wp-admin/' . $p);

        $existing = ['<a href="#">Desactivar</a>'];
        $result   = $this->admin->add_plugin_links($existing);

        $this->assertGreaterThan(count($existing), count($result));
    }

    public function test_add_plugin_links_includes_configurar_link(): void
    {
        Functions\when('admin_url')->alias(static fn(string $p = ''): string => 'http://example.com/wp-admin/' . $p);

        $result = $this->admin->add_plugin_links([]);
        $html   = implode('', $result);
        $this->assertStringContainsString('ltms-settings', $html);
    }

    public function test_add_plugin_links_includes_dashboard_link(): void
    {
        Functions\when('admin_url')->alias(static fn(string $p = ''): string => 'http://example.com/wp-admin/' . $p);

        $result = $this->admin->add_plugin_links([]);
        $html   = implode('', $result);
        $this->assertStringContainsString('ltms-dashboard', $html);
    }

    public function test_add_plugin_links_preserves_existing_links(): void
    {
        Functions\when('admin_url')->alias(static fn(string $p = ''): string => 'http://example.com/wp-admin/' . $p);

        $existing = ['<a href="#">Desactivar</a>'];
        $result   = $this->admin->add_plugin_links($existing);

        $this->assertContains('<a href="#">Desactivar</a>', $result);
    }

    public function test_add_plugin_links_returns_array(): void
    {
        Functions\when('admin_url')->alias(static fn(string $p = ''): string => 'http://example.com/wp-admin/' . $p);

        $result = $this->admin->add_plugin_links([]);
        $this->assertIsArray($result);
    }

    public function test_add_plugin_links_adds_two_new_links(): void
    {
        Functions\when('admin_url')->alias(static fn(string $p = ''): string => 'http://example.com/wp-admin/' . $p);

        $result = $this->admin->add_plugin_links([]);
        $this->assertCount(2, $result);
    }

    // ── SECCIÓN 5: render_admin_notices() ────────────────────────────────────

    public function test_render_admin_notices_output_is_string(): void
    {
        ob_start();
        $this->admin->render_admin_notices();
        $output = ob_get_clean();
        $this->assertIsString($output);
    }

    public function test_render_admin_notices_emits_warning_without_encryption_key(): void
    {
        ob_start();
        $this->admin->render_admin_notices();
        $output = ob_get_clean();

        if (!defined('LTMS_ENCRYPTION_KEY')) {
            $this->assertStringContainsString('notice-warning', $output);
        } else {
            $this->assertEmpty($output);
        }
    }

    // ── SECCIÓN 6: handle_activation_redirect() ───────────────────────────────

    public function test_handle_activation_redirect_does_nothing_without_flag(): void
    {
        $redirected = false;
        Functions\when('get_option')->alias(
            static fn(string $k, mixed $d = null): mixed => ($k === 'ltms_activation_redirect') ? false : $d
        );
        Functions\when('wp_safe_redirect')->alias(
            static function() use (&$redirected): void { $redirected = true; }
        );
        Functions\when('delete_option')->alias(static fn(): bool => true);

        try {
            $this->admin->handle_activation_redirect();
        } catch (\Throwable) {}

        $this->assertFalse($redirected);
    }

    // ── SECCIÓN 7: Reflexión ─────────────────────────────────────────────────

    public function test_class_is_final(): void
    {
        $rc = new \ReflectionClass(\LTMS_Admin::class);
        $this->assertTrue($rc->isFinal());
    }

    public function test_init_is_public_static(): void
    {
        $rm = new \ReflectionMethod(\LTMS_Admin::class, 'init');
        $this->assertTrue($rm->isPublic());
        $this->assertTrue($rm->isStatic());
    }

    public function test_register_menus_is_public(): void
    {
        $rm = new \ReflectionMethod(\LTMS_Admin::class, 'register_menus');
        $this->assertTrue($rm->isPublic());
    }

    public function test_enqueue_assets_is_public(): void
    {
        $rm = new \ReflectionMethod(\LTMS_Admin::class, 'enqueue_assets');
        $this->assertTrue($rm->isPublic());
    }

    public function test_add_plugin_links_is_public(): void
    {
        $rm = new \ReflectionMethod(\LTMS_Admin::class, 'add_plugin_links');
        $this->assertTrue($rm->isPublic());
    }

    public function test_render_admin_notices_is_public(): void
    {
        $rm = new \ReflectionMethod(\LTMS_Admin::class, 'render_admin_notices');
        $this->assertTrue($rm->isPublic());
    }

    public function test_render_view_is_private(): void
    {
        $rm = new \ReflectionMethod(\LTMS_Admin::class, 'render_view');
        $this->assertTrue($rm->isPrivate());
    }

    public function test_class_has_logger_aware_trait(): void
    {
        $rc     = new \ReflectionClass(\LTMS_Admin::class);
        $traits = array_keys($rc->getTraits());
        $this->assertContains('LTMS_Logger_Aware', $traits);
    }
}

