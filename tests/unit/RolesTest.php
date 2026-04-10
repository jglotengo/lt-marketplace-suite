<?php
/**
 * RolesTest — Tests unitarios para LTMS_Roles
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

namespace {
    // WP_User no está en el bootstrap unit — stub mínimo para dynamic_capabilities().
    if (!class_exists('WP_User')) {
        class WP_User {
            public int   $ID    = 0;
            public array $roles = [];
            public function __construct(int $id = 0, array $roles = []) {
                $this->ID    = $id;
                $this->roles = $roles;
            }
        }
    }
}

namespace LTMS\Tests\Unit {

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;


/**
 * @covers LTMS_Roles
 */
class RolesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // __ es usado por get_role_label()
        Functions\stubs([
            '__'         => static fn($text) => $text,
            'add_action' => null,
            'add_filter' => null,
        ]);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ════════════════════════════════════════════════════════════════════════
    // VENDOR_CAPABILITIES — contrato RBAC del vendedor estándar
    // ════════════════════════════════════════════════════════════════════════

    public function test_vendor_has_read_capability(): void
    {
        $this->assertTrue(\LTMS_Roles::VENDOR_CAPABILITIES['read']);
    }

    public function test_vendor_has_upload_files(): void
    {
        $this->assertTrue(\LTMS_Roles::VENDOR_CAPABILITIES['upload_files']);
    }

    public function test_vendor_has_manage_own_products(): void
    {
        $this->assertTrue(\LTMS_Roles::VENDOR_CAPABILITIES['ltms_manage_own_products']);
    }

    public function test_vendor_has_view_own_orders(): void
    {
        $this->assertTrue(\LTMS_Roles::VENDOR_CAPABILITIES['ltms_view_own_orders']);
    }

    public function test_vendor_has_view_own_wallet(): void
    {
        $this->assertTrue(\LTMS_Roles::VENDOR_CAPABILITIES['ltms_view_own_wallet']);
    }

    public function test_vendor_has_request_payout(): void
    {
        $this->assertTrue(\LTMS_Roles::VENDOR_CAPABILITIES['ltms_request_payout']);
    }

    public function test_vendor_cannot_access_dashboard(): void
    {
        // Los vendedores usan el SPA frontend, no el admin de WP
        $this->assertFalse(\LTMS_Roles::VENDOR_CAPABILITIES['ltms_access_dashboard']);
    }

    public function test_vendor_cannot_view_other_vendor_data(): void
    {
        $this->assertFalse(\LTMS_Roles::VENDOR_CAPABILITIES['ltms_view_other_vendor_data']);
    }

    public function test_vendor_cannot_approve_payouts(): void
    {
        $this->assertFalse(\LTMS_Roles::VENDOR_CAPABILITIES['ltms_approve_payouts']);
    }

    public function test_vendor_cannot_manage_platform_settings(): void
    {
        $this->assertFalse(\LTMS_Roles::VENDOR_CAPABILITIES['ltms_manage_platform_settings']);
    }

    public function test_vendor_cannot_view_tax_reports(): void
    {
        $this->assertFalse(\LTMS_Roles::VENDOR_CAPABILITIES['ltms_view_tax_reports']);
    }

    public function test_vendor_cannot_export_customer_db(): void
    {
        $this->assertFalse(\LTMS_Roles::VENDOR_CAPABILITIES['ltms_export_customer_db']);
    }

    public function test_vendor_cannot_compliance(): void
    {
        $this->assertFalse(\LTMS_Roles::VENDOR_CAPABILITIES['ltms_compliance']);
    }

    /** NEW — VENDOR_CAPABILITIES es array no vacío */
    public function test_vendor_capabilities_is_non_empty_array(): void
    {
        $this->assertIsArray(\LTMS_Roles::VENDOR_CAPABILITIES);
        $this->assertNotEmpty(\LTMS_Roles::VENDOR_CAPABILITIES);
    }

    /** NEW — todas las caps del vendor son bool */
    public function test_vendor_capabilities_all_values_are_bool(): void
    {
        foreach (\LTMS_Roles::VENDOR_CAPABILITIES as $cap => $value) {
            $this->assertIsBool($value, "Cap '{$cap}' no es booleana");
        }
    }

    /** NEW — vendor no puede congelar wallets */
    public function test_vendor_cannot_freeze_wallets(): void
    {
        if (isset(\LTMS_Roles::VENDOR_CAPABILITIES['ltms_freeze_wallets'])) {
            $this->assertFalse(\LTMS_Roles::VENDOR_CAPABILITIES['ltms_freeze_wallets']);
        } else {
            $this->assertArrayNotHasKey('ltms_freeze_wallets', \LTMS_Roles::VENDOR_CAPABILITIES);
        }
    }

    /** NEW — vendor no puede ver logs de seguridad */
    public function test_vendor_cannot_view_security_logs(): void
    {
        if (isset(\LTMS_Roles::VENDOR_CAPABILITIES['ltms_view_security_logs'])) {
            $this->assertFalse(\LTMS_Roles::VENDOR_CAPABILITIES['ltms_view_security_logs']);
        } else {
            $this->assertArrayNotHasKey('ltms_view_security_logs', \LTMS_Roles::VENDOR_CAPABILITIES);
        }
    }

    /** NEW — vendor no puede gestionar roles */
    public function test_vendor_cannot_manage_roles(): void
    {
        if (isset(\LTMS_Roles::VENDOR_CAPABILITIES['ltms_manage_roles'])) {
            $this->assertFalse(\LTMS_Roles::VENDOR_CAPABILITIES['ltms_manage_roles']);
        } else {
            $this->assertArrayNotHasKey('ltms_manage_roles', \LTMS_Roles::VENDOR_CAPABILITIES);
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // VENDOR_PREMIUM_CAPABILITIES — contrato del vendedor premium
    // ════════════════════════════════════════════════════════════════════════

    public function test_vendor_premium_has_bulk_import(): void
    {
        $this->assertTrue(\LTMS_Roles::VENDOR_PREMIUM_CAPABILITIES['ltms_bulk_import_products']);
    }

    public function test_vendor_premium_has_advanced_analytics(): void
    {
        $this->assertTrue(\LTMS_Roles::VENDOR_PREMIUM_CAPABILITIES['ltms_advanced_analytics']);
    }

    public function test_vendor_premium_has_custom_commission_rates(): void
    {
        $this->assertTrue(\LTMS_Roles::VENDOR_PREMIUM_CAPABILITIES['ltms_custom_commission_rates']);
    }

    public function test_vendor_premium_cannot_access_dashboard(): void
    {
        // Igual que el vendedor estándar: SPA frontend
        $this->assertFalse(\LTMS_Roles::VENDOR_PREMIUM_CAPABILITIES['ltms_access_dashboard']);
    }

    public function test_vendor_premium_has_all_standard_vendor_positive_caps(): void
    {
        $standard_positive = array_filter(
            \LTMS_Roles::VENDOR_CAPABILITIES,
            fn($v) => $v === true
        );

        foreach (array_keys($standard_positive) as $cap) {
            $this->assertArrayHasKey($cap, \LTMS_Roles::VENDOR_PREMIUM_CAPABILITIES,
                "El vendor premium debería tener la cap '{$cap}' del vendedor estándar");
            $this->assertTrue(\LTMS_Roles::VENDOR_PREMIUM_CAPABILITIES[$cap],
                "El vendor premium debería tener '{$cap}' en true");
        }
    }

    /** NEW — VENDOR_PREMIUM_CAPABILITIES es array no vacío */
    public function test_vendor_premium_capabilities_is_non_empty_array(): void
    {
        $this->assertIsArray(\LTMS_Roles::VENDOR_PREMIUM_CAPABILITIES);
        $this->assertNotEmpty(\LTMS_Roles::VENDOR_PREMIUM_CAPABILITIES);
    }

    /** NEW — premium tiene más caps que el estándar (superconjunto) */
    public function test_vendor_premium_has_more_caps_than_standard(): void
    {
        $standard_true = count(array_filter(\LTMS_Roles::VENDOR_CAPABILITIES, fn($v) => $v === true));
        $premium_true  = count(array_filter(\LTMS_Roles::VENDOR_PREMIUM_CAPABILITIES, fn($v) => $v === true));
        $this->assertGreaterThan($standard_true, $premium_true,
            'El vendedor premium debería tener más capabilities habilitadas que el estándar');
    }

    /** NEW — premium tampoco puede aprobar pagos */
    public function test_vendor_premium_cannot_approve_payouts(): void
    {
        if (isset(\LTMS_Roles::VENDOR_PREMIUM_CAPABILITIES['ltms_approve_payouts'])) {
            $this->assertFalse(\LTMS_Roles::VENDOR_PREMIUM_CAPABILITIES['ltms_approve_payouts']);
        } else {
            $this->assertArrayNotHasKey('ltms_approve_payouts', \LTMS_Roles::VENDOR_PREMIUM_CAPABILITIES);
        }
    }

    /** NEW — premium tampoco puede gestionar configuración de plataforma */
    public function test_vendor_premium_cannot_manage_platform_settings(): void
    {
        if (isset(\LTMS_Roles::VENDOR_PREMIUM_CAPABILITIES['ltms_manage_platform_settings'])) {
            $this->assertFalse(\LTMS_Roles::VENDOR_PREMIUM_CAPABILITIES['ltms_manage_platform_settings']);
        } else {
            $this->assertArrayNotHasKey('ltms_manage_platform_settings', \LTMS_Roles::VENDOR_PREMIUM_CAPABILITIES);
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // SUPPORT_AGENT_CAPABILITIES
    // ════════════════════════════════════════════════════════════════════════

    public function test_support_agent_has_dashboard_access(): void
    {
        $this->assertTrue(\LTMS_Roles::SUPPORT_AGENT_CAPABILITIES['ltms_access_dashboard']);
    }

    public function test_support_agent_has_readonly_vendor_data(): void
    {
        $this->assertTrue(\LTMS_Roles::SUPPORT_AGENT_CAPABILITIES['ltms_view_vendor_data_readonly']);
    }

    public function test_support_agent_has_readonly_orders(): void
    {
        $this->assertTrue(\LTMS_Roles::SUPPORT_AGENT_CAPABILITIES['ltms_view_all_orders_readonly']);
    }

    public function test_support_agent_can_update_order_notes(): void
    {
        $this->assertTrue(\LTMS_Roles::SUPPORT_AGENT_CAPABILITIES['ltms_update_order_notes']);
    }

    /** NEW — support agent no puede aprobar pagos */
    public function test_support_agent_cannot_approve_payouts(): void
    {
        if (isset(\LTMS_Roles::SUPPORT_AGENT_CAPABILITIES['ltms_approve_payouts'])) {
            $this->assertFalse(\LTMS_Roles::SUPPORT_AGENT_CAPABILITIES['ltms_approve_payouts']);
        } else {
            $this->assertArrayNotHasKey('ltms_approve_payouts', \LTMS_Roles::SUPPORT_AGENT_CAPABILITIES);
        }
    }

    /** NEW — support agent no puede gestionar configuración de plataforma */
    public function test_support_agent_cannot_manage_platform_settings(): void
    {
        if (isset(\LTMS_Roles::SUPPORT_AGENT_CAPABILITIES['ltms_manage_platform_settings'])) {
            $this->assertFalse(\LTMS_Roles::SUPPORT_AGENT_CAPABILITIES['ltms_manage_platform_settings']);
        } else {
            $this->assertArrayNotHasKey('ltms_manage_platform_settings', \LTMS_Roles::SUPPORT_AGENT_CAPABILITIES);
        }
    }

    /** NEW — SUPPORT_AGENT_CAPABILITIES es array no vacío */
    public function test_support_agent_capabilities_is_non_empty_array(): void
    {
        $this->assertIsArray(\LTMS_Roles::SUPPORT_AGENT_CAPABILITIES);
        $this->assertNotEmpty(\LTMS_Roles::SUPPORT_AGENT_CAPABILITIES);
    }

    // ════════════════════════════════════════════════════════════════════════
    // ADMIN_CAPABILITIES — contrato del administrador
    // ════════════════════════════════════════════════════════════════════════

    public function test_admin_caps_contains_access_dashboard(): void
    {
        $this->assertContains('ltms_access_dashboard', \LTMS_Roles::ADMIN_CAPABILITIES);
    }

    public function test_admin_caps_contains_approve_payouts(): void
    {
        $this->assertContains('ltms_approve_payouts', \LTMS_Roles::ADMIN_CAPABILITIES);
    }

    public function test_admin_caps_contains_manage_platform_settings(): void
    {
        $this->assertContains('ltms_manage_platform_settings', \LTMS_Roles::ADMIN_CAPABILITIES);
    }

    public function test_admin_caps_contains_view_tax_reports(): void
    {
        $this->assertContains('ltms_view_tax_reports', \LTMS_Roles::ADMIN_CAPABILITIES);
    }

    public function test_admin_caps_contains_view_security_logs(): void
    {
        $this->assertContains('ltms_view_security_logs', \LTMS_Roles::ADMIN_CAPABILITIES);
    }

    public function test_admin_caps_contains_compliance(): void
    {
        $this->assertContains('ltms_compliance', \LTMS_Roles::ADMIN_CAPABILITIES);
    }

    public function test_admin_caps_contains_manage_roles(): void
    {
        $this->assertContains('ltms_manage_roles', \LTMS_Roles::ADMIN_CAPABILITIES);
    }

    public function test_admin_caps_is_non_empty_array(): void
    {
        $this->assertIsArray(\LTMS_Roles::ADMIN_CAPABILITIES);
        $this->assertNotEmpty(\LTMS_Roles::ADMIN_CAPABILITIES);
    }

    public function test_admin_caps_are_unique(): void
    {
        $caps = \LTMS_Roles::ADMIN_CAPABILITIES;
        $this->assertSame(count($caps), count(array_unique($caps)),
            'ADMIN_CAPABILITIES contiene caps duplicadas');
    }

    /** NEW — admin contiene export_customer_db */
    public function test_admin_caps_contains_export_customer_db(): void
    {
        $this->assertContains('ltms_export_customer_db', \LTMS_Roles::ADMIN_CAPABILITIES);
    }

    /** NEW — admin contiene freeze_wallets */
    public function test_admin_caps_contains_freeze_wallets(): void
    {
        $this->assertContains('ltms_freeze_wallets', \LTMS_Roles::ADMIN_CAPABILITIES);
    }

    /** NEW — todas las caps admin son strings no vacíos */
    public function test_admin_caps_all_strings(): void
    {
        foreach (\LTMS_Roles::ADMIN_CAPABILITIES as $cap) {
            $this->assertIsString($cap);
            $this->assertNotSame('', $cap);
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // Invariantes de seguridad — vendor NO debe tener caps de admin
    // ════════════════════════════════════════════════════════════════════════

    public function test_vendor_caps_dont_include_admin_only_caps(): void
    {
        $admin_only = [
            'ltms_approve_payouts',
            'ltms_manage_platform_settings',
            'ltms_view_tax_reports',
            'ltms_export_customer_db',
            'ltms_view_security_logs',
            'ltms_manage_roles',
            'ltms_freeze_wallets',
            'ltms_compliance',
        ];

        foreach ($admin_only as $cap) {
            if (isset(\LTMS_Roles::VENDOR_CAPABILITIES[$cap])) {
                $this->assertFalse(
                    \LTMS_Roles::VENDOR_CAPABILITIES[$cap],
                    "Vendor NO debe tener '{$cap}' en true"
                );
            } else {
                // No está presente: correcto
                $this->assertArrayNotHasKey($cap, \LTMS_Roles::VENDOR_CAPABILITIES);
            }
        }
    }

    public function test_vendor_premium_caps_dont_include_admin_only_caps(): void
    {
        $admin_only = [
            'ltms_approve_payouts',
            'ltms_manage_platform_settings',
            'ltms_view_tax_reports',
            'ltms_manage_roles',
            'ltms_compliance',
        ];

        foreach ($admin_only as $cap) {
            if (isset(\LTMS_Roles::VENDOR_PREMIUM_CAPABILITIES[$cap])) {
                $this->assertFalse(
                    \LTMS_Roles::VENDOR_PREMIUM_CAPABILITIES[$cap],
                    "Vendor Premium NO debe tener '{$cap}' en true"
                );
            } else {
                $this->assertArrayNotHasKey($cap, \LTMS_Roles::VENDOR_PREMIUM_CAPABILITIES);
            }
        }
    }

    /** NEW — support agent tampoco tiene caps de admin destructivas */
    public function test_support_agent_caps_dont_include_destructive_admin_caps(): void
    {
        $destructive = [
            'ltms_export_customer_db',
            'ltms_freeze_wallets',
            'ltms_manage_roles',
            'ltms_approve_payouts',
        ];

        foreach ($destructive as $cap) {
            if (isset(\LTMS_Roles::SUPPORT_AGENT_CAPABILITIES[$cap])) {
                $this->assertFalse(
                    \LTMS_Roles::SUPPORT_AGENT_CAPABILITIES[$cap],
                    "Support Agent NO debe tener '{$cap}' en true"
                );
            } else {
                $this->assertArrayNotHasKey($cap, \LTMS_Roles::SUPPORT_AGENT_CAPABILITIES);
            }
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // get_role_label()
    // ════════════════════════════════════════════════════════════════════════

    public function test_get_role_label_vendor(): void
    {
        $this->assertSame('Vendedor', \LTMS_Roles::get_role_label('ltms_vendor'));
    }

    public function test_get_role_label_vendor_premium(): void
    {
        $this->assertSame('Vendedor Premium', \LTMS_Roles::get_role_label('ltms_vendor_premium'));
    }

    public function test_get_role_label_external_auditor(): void
    {
        $this->assertSame('Auditor Externo', \LTMS_Roles::get_role_label('ltms_external_auditor'));
    }

    public function test_get_role_label_support_agent(): void
    {
        $this->assertSame('Soporte', \LTMS_Roles::get_role_label('ltms_support_agent'));
    }

    public function test_get_role_label_administrator(): void
    {
        $this->assertSame('Administrador', \LTMS_Roles::get_role_label('administrator'));
    }

    public function test_get_role_label_compliance_officer(): void
    {
        $this->assertSame('Oficial de Cumplimiento', \LTMS_Roles::get_role_label('ltms_compliance_officer'));
    }

    public function test_get_role_label_unknown_role_returns_formatted_slug(): void
    {
        // Slug desconocido: strip ltms_ + replace _ → espacio + ucfirst
        $label = \LTMS_Roles::get_role_label('ltms_custom_role');
        $this->assertSame('Custom role', $label);
    }

    public function test_get_role_label_unknown_non_ltms_role(): void
    {
        $label = \LTMS_Roles::get_role_label('editor');
        $this->assertSame('Editor', $label);
    }

    /** NEW — get_role_label siempre retorna string no vacío */
    public function test_get_role_label_returns_non_empty_string(): void
    {
        foreach (['ltms_vendor', 'ltms_vendor_premium', 'administrator', 'ltms_support_agent'] as $role) {
            $label = \LTMS_Roles::get_role_label($role);
            $this->assertIsString($label);
            $this->assertNotSame('', $label, "Label para role '{$role}' no debe ser vacío");
        }
    }

    /** NEW — slug con múltiples palabras se formatea correctamente */
    public function test_get_role_label_multi_word_unknown_slug(): void
    {
        $label = \LTMS_Roles::get_role_label('ltms_my_custom_role');
        $this->assertIsString($label);
        $this->assertNotSame('', $label);
    }

    // ════════════════════════════════════════════════════════════════════════
    // dynamic_capabilities()
    // ════════════════════════════════════════════════════════════════════════

    public function test_dynamic_capabilities_returns_allcaps_unchanged_when_no_object_id(): void
    {
        $wp_user = new \WP_User(10, ['ltms_vendor']);

        $allcaps = ['read' => true, 'edit_post' => true];
        // args[2] vacío → sin restricción
        $result = \LTMS_Roles::dynamic_capabilities($allcaps, ['edit_post'], ['edit_post', 10, 0], $wp_user);
        $this->assertSame($allcaps, $result);
    }

    public function test_dynamic_capabilities_vendor_cannot_edit_others_post(): void
    {
        Functions\when('get_post')->justReturn((object)['post_author' => 99]);

        $wp_user = new \WP_User(10, ['ltms_vendor']);

        $allcaps = ['edit_post' => true];
        $result  = \LTMS_Roles::dynamic_capabilities(
            $allcaps,
            ['edit_post'],
            ['edit_post', 10, 5],  // object_id = 5, autor = 99 ≠ user 10
            $wp_user
        );

        $this->assertFalse($result['edit_post']);
    }

    public function test_dynamic_capabilities_vendor_can_edit_own_post(): void
    {
        Functions\when('get_post')->justReturn((object)['post_author' => 10]);

        $wp_user = new \WP_User(10, ['ltms_vendor']);

        $allcaps = ['edit_post' => true];
        $result  = \LTMS_Roles::dynamic_capabilities(
            $allcaps,
            ['edit_post'],
            ['edit_post', 10, 5],  // object_id = 5, autor = 10 = user
            $wp_user
        );

        $this->assertTrue($result['edit_post']);
    }

    public function test_dynamic_capabilities_admin_not_restricted(): void
    {
        // Un administrador no tiene ltms_vendor en roles → sin restricción
        $wp_user = new \WP_User(1, ['administrator']);

        $allcaps = ['edit_post' => true];
        $result  = \LTMS_Roles::dynamic_capabilities(
            $allcaps,
            ['edit_post'],
            ['edit_post', 1, 5],
            $wp_user
        );

        $this->assertTrue($result['edit_post']);
    }

    /** NEW — dynamic_capabilities retorna array */
    public function test_dynamic_capabilities_returns_array(): void
    {
        $wp_user = new \WP_User(10, ['ltms_vendor']);
        $allcaps = ['read' => true];
        $result  = \LTMS_Roles::dynamic_capabilities($allcaps, ['read'], ['read', 10, 0], $wp_user);
        $this->assertIsArray($result);
    }

    /** NEW — vendor premium tampoco puede editar posts de otros */
    public function test_dynamic_capabilities_vendor_premium_cannot_edit_others_post(): void
    {
        Functions\when('get_post')->justReturn((object)['post_author' => 99]);

        $wp_user = new \WP_User(20, ['ltms_vendor_premium']);

        $allcaps = ['edit_post' => true];
        $result  = \LTMS_Roles::dynamic_capabilities(
            $allcaps,
            ['edit_post'],
            ['edit_post', 20, 7],
            $wp_user
        );

        $this->assertFalse($result['edit_post']);
    }

    /** NEW — vendor premium puede editar sus propios posts */
    public function test_dynamic_capabilities_vendor_premium_can_edit_own_post(): void
    {
        Functions\when('get_post')->justReturn((object)['post_author' => 20]);

        $wp_user = new \WP_User(20, ['ltms_vendor_premium']);

        $allcaps = ['edit_post' => true];
        $result  = \LTMS_Roles::dynamic_capabilities(
            $allcaps,
            ['edit_post'],
            ['edit_post', 20, 7],
            $wp_user
        );

        $this->assertTrue($result['edit_post']);
    }

    /** NEW — support agent no tiene restricción de edición de posts */
    public function test_dynamic_capabilities_support_agent_not_restricted(): void
    {
        $wp_user = new \WP_User(5, ['ltms_support_agent']);

        $allcaps = ['edit_post' => true];
        $result  = \LTMS_Roles::dynamic_capabilities(
            $allcaps,
            ['edit_post'],
            ['edit_post', 5, 99],
            $wp_user
        );

        // Support agent no es vendor → sus caps no se modifican
        $this->assertTrue($result['edit_post']);
    }
}
}
