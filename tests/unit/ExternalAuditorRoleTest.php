<?php
/**
 * ExternalAuditorRoleTest — EXTENDED v2
 *
 * Nuevos ángulos cubiertos:
 *  - AUDITOR_CAPABILITIES: keys obligatorias presentes, sin caps de escritura WP estándar
 *  - enforce_2fa: múltiples roles (auditor + subscriber), usuario sin roles
 *  - Compliance Officer: caps heredadas no se pierden por merge, caps bloqueadas persisten
 *  - install(): no lanza excepción cuando add_role/remove_role son stubs
 *  - log_auditor_page_access(): no llama al logger si el usuario no tiene la cap
 *  - Invariantes de seguridad: ninguna cap de escritura real está en true
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @covers LTMS_External_Auditor_Role
 */
class ExternalAuditorRoleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\stubs([
            'sanitize_text_field' => static fn(string $s): string => $s,
            'current_user_can'    => static fn(): bool => false,
            'update_user_meta'    => static fn(): bool => true,
            'add_action'          => null,
            'remove_action'       => null,
            'remove_role'         => null,
            'add_role'            => null,
            '__'                  => static fn($s) => $s,
        ]);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ════════════════════════════════════════════════════════════════════════
    // Estructura de la constante
    // ════════════════════════════════════════════════════════════════════════

    /** La constante AUDITOR_CAPABILITIES existe y es pública */
    public function test_auditor_capabilities_constant_is_accessible(): void
    {
        $caps = \LTMS_External_Auditor_Role::AUDITOR_CAPABILITIES;
        $this->assertIsArray( $caps );
        $this->assertNotEmpty( $caps );
    }

    /** La constante tiene al menos 15 entradas (8 permitidas + blocked) */
    public function test_auditor_capabilities_has_minimum_count(): void
    {
        $this->assertGreaterThanOrEqual( 15, count( \LTMS_External_Auditor_Role::AUDITOR_CAPABILITIES ) );
    }

    /** Todas las keys son strings no vacías */
    public function test_auditor_capabilities_keys_are_non_empty_strings(): void
    {
        foreach ( array_keys( \LTMS_External_Auditor_Role::AUDITOR_CAPABILITIES ) as $key ) {
            $this->assertIsString( $key );
            $this->assertNotEmpty( $key );
        }
    }

    /** Todos los valores son booleanos (no 0, no 1, no null) */
    public function test_auditor_capabilities_values_are_strictly_boolean(): void
    {
        foreach ( \LTMS_External_Auditor_Role::AUDITOR_CAPABILITIES as $cap => $value ) {
            $this->assertIsBool( $value, "La cap '{$cap}' debe ser bool, no " . gettype($value) );
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // Caps de escritura WP estándar — ninguna debe estar en true
    // ════════════════════════════════════════════════════════════════════════

    public function test_auditor_cannot_upload_files(): void
    {
        $caps = \LTMS_External_Auditor_Role::AUDITOR_CAPABILITIES;
        // Si la cap no existe, se asume false (no otorgada)
        $this->assertFalse( $caps['upload_files'] ?? false );
    }

    public function test_auditor_cannot_edit_pages(): void
    {
        $caps = \LTMS_External_Auditor_Role::AUDITOR_CAPABILITIES;
        $this->assertFalse( $caps['edit_pages'] ?? false );
    }

    public function test_auditor_cannot_delete_pages(): void
    {
        $caps = \LTMS_External_Auditor_Role::AUDITOR_CAPABILITIES;
        $this->assertFalse( $caps['delete_pages'] ?? false );
    }

    public function test_auditor_cannot_install_plugins(): void
    {
        $caps = \LTMS_External_Auditor_Role::AUDITOR_CAPABILITIES;
        $this->assertFalse( $caps['install_plugins'] ?? false );
    }

    public function test_auditor_cannot_activate_plugins(): void
    {
        $caps = \LTMS_External_Auditor_Role::AUDITOR_CAPABILITIES;
        $this->assertFalse( $caps['activate_plugins'] ?? false );
    }

    /** Ninguna cap estándar de administrador WP está en true */
    public function test_auditor_has_no_wp_admin_caps_enabled(): void
    {
        $admin_caps = [
            'manage_options', 'install_plugins', 'activate_plugins',
            'install_themes', 'switch_themes', 'edit_theme_options',
            'manage_categories', 'import', 'export', 'list_users',
            'create_users', 'edit_users', 'delete_users',
        ];

        $caps = \LTMS_External_Auditor_Role::AUDITOR_CAPABILITIES;
        foreach ( $admin_caps as $admin_cap ) {
            if ( array_key_exists( $admin_cap, $caps ) ) {
                $this->assertFalse( $caps[ $admin_cap ], "Cap '$admin_cap' no debe estar habilitada para el auditor" );
            }
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // Caps LTMS bloqueadas — contrato de seguridad explícito
    // ════════════════════════════════════════════════════════════════════════

    /** ltms_manage_kyc no está en AUDITOR_CAPABILITIES como true */
    public function test_auditor_cannot_manage_kyc(): void
    {
        $caps = \LTMS_External_Auditor_Role::AUDITOR_CAPABILITIES;
        $this->assertFalse( $caps['ltms_manage_kyc'] ?? false );
    }

    /** ltms_freeze_wallets no está habilitado */
    public function test_auditor_cannot_freeze_wallets(): void
    {
        $caps = \LTMS_External_Auditor_Role::AUDITOR_CAPABILITIES;
        $this->assertFalse( $caps['ltms_freeze_wallets'] ?? false );
    }

    /** ltms_view_security_logs no otorgado al auditor básico */
    public function test_auditor_cannot_view_security_logs(): void
    {
        $caps = \LTMS_External_Auditor_Role::AUDITOR_CAPABILITIES;
        $this->assertFalse( $caps['ltms_view_security_logs'] ?? false );
    }

    // ════════════════════════════════════════════════════════════════════════
    // enforce_2fa_for_auditors() — más escenarios
    // ════════════════════════════════════════════════════════════════════════

    /** Usuario sin roles no activa el flow 2FA */
    public function test_enforce_2fa_skips_user_without_roles(): void
    {
        $user = new \WP_User( 10, [] ); // sin roles

        $called = false;
        Functions\when('update_user_meta')->alias(static function() use (&$called): bool {
            $called = true;
            return true;
        });

        \LTMS_External_Auditor_Role::enforce_2fa_for_auditors( 'nouser', $user );
        $this->assertFalse( $called );
    }

    /** Usuario admin (no auditor) no activa el flow 2FA */
    public function test_enforce_2fa_skips_admin_user(): void
    {
        $user = new \WP_User( 1, ['administrator'] );

        $called = false;
        Functions\when('update_user_meta')->alias(static function() use (&$called): bool {
            $called = true;
            return true;
        });

        \LTMS_External_Auditor_Role::enforce_2fa_for_auditors( 'admin', $user );
        $this->assertFalse( $called );
    }

    /** Usuario con auditor + subscriber — sí activa 2FA (tiene rol auditor) */
    public function test_enforce_2fa_triggers_for_user_with_auditor_and_subscriber_roles(): void
    {
        $user = new \WP_User( 12, ['subscriber', 'ltms_external_auditor'] );

        // Inyectar 'yes' en el cache de config
        $ref   = new \ReflectionClass( \LTMS_Core_Config::class );
        $cache = $ref->getProperty( 'cache' );
        $cache->setAccessible( true );
        $current = $cache->getValue( null );
        $current['ltms_2fa_required_auditors'] = 'yes';
        $cache->setValue( null, $current );

        $called = false;
        Functions\when('update_user_meta')->alias(static function() use (&$called): bool {
            $called = true;
            return true;
        });

        \LTMS_External_Auditor_Role::enforce_2fa_for_auditors( 'multi_role_user', $user );
        $this->assertTrue( $called, 'update_user_meta debe llamarse para usuario con rol auditor' );

        \LTMS_Core_Config::flush_cache();
        Functions\stubs(['get_option' => static fn($k, $d = false) => $d]);
    }

    /** enforce_2fa con 2FA='yes' establece el meta como false (sin verificar) */
    public function test_enforce_2fa_meta_value_is_false(): void
    {
        $user = new \WP_User( 15, ['ltms_external_auditor'] );

        $ref   = new \ReflectionClass( \LTMS_Core_Config::class );
        $cache = $ref->getProperty( 'cache' );
        $cache->setAccessible( true );
        $current = $cache->getValue( null );
        $current['ltms_2fa_required_auditors'] = 'yes';
        $cache->setValue( null, $current );

        $capturedValue = null;
        Functions\when('update_user_meta')->alias(
            static function( int $uid, string $key, mixed $val ) use (&$capturedValue): bool {
                $capturedValue = $val;
                return true;
            }
        );

        \LTMS_External_Auditor_Role::enforce_2fa_for_auditors( 'auditor15', $user );
        $this->assertFalse( $capturedValue );

        \LTMS_Core_Config::flush_cache();
        Functions\stubs(['get_option' => static fn($k, $d = false) => $d]);
    }

    /** enforce_2fa con 2FA='no' no llama update_user_meta */
    public function test_enforce_2fa_does_not_set_meta_when_disabled(): void
    {
        $user = new \WP_User( 20, ['ltms_external_auditor'] );

        $ref   = new \ReflectionClass( \LTMS_Core_Config::class );
        $cache = $ref->getProperty( 'cache' );
        $cache->setAccessible( true );
        $current = $cache->getValue( null );
        $current['ltms_2fa_required_auditors'] = 'no';
        $cache->setValue( null, $current );

        $called = false;
        Functions\when('update_user_meta')->alias(static function() use (&$called): bool {
            $called = true;
            return true;
        });

        \LTMS_External_Auditor_Role::enforce_2fa_for_auditors( 'auditor20', $user );
        $this->assertFalse( $called );

        \LTMS_Core_Config::flush_cache();
        Functions\stubs(['get_option' => static fn($k, $d = false) => $d]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // install() — no lanza excepciones con stubs
    // ════════════════════════════════════════════════════════════════════════

    public function test_install_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();
        \LTMS_External_Auditor_Role::install();
    }

    public function test_install_is_idempotent(): void
    {
        $this->expectNotToPerformAssertions();
        \LTMS_External_Auditor_Role::install();
        \LTMS_External_Auditor_Role::install();
    }

    // ════════════════════════════════════════════════════════════════════════
    // Compliance Officer — merge invariantes
    // ════════════════════════════════════════════════════════════════════════

    /** Compliance Officer hereda ltms_view_sagrilaft_report del auditor */
    public function test_compliance_officer_inherits_sagrilaft_cap(): void
    {
        $officer = array_merge( \LTMS_External_Auditor_Role::AUDITOR_CAPABILITIES, [
            'ltms_compliance'               => true,
            'ltms_generate_legal_evidence'  => true,
            'ltms_view_vendor_contact_info' => true,
            'ltms_manage_kyc'               => true,
            'ltms_freeze_wallets'           => true,
        ]);
        $this->assertTrue( $officer['ltms_view_sagrilaft_report'] );
    }

    /** Compliance Officer hereda ltms_view_transaction_trace */
    public function test_compliance_officer_inherits_transaction_trace(): void
    {
        $officer = array_merge( \LTMS_External_Auditor_Role::AUDITOR_CAPABILITIES, [
            'ltms_compliance'               => true,
            'ltms_generate_legal_evidence'  => true,
            'ltms_view_vendor_contact_info' => true,
            'ltms_manage_kyc'               => true,
            'ltms_freeze_wallets'           => true,
        ]);
        $this->assertTrue( $officer['ltms_view_transaction_trace'] );
    }

    /** Compliance Officer aún no puede aprobar payouts */
    public function test_compliance_officer_still_cannot_approve_payouts(): void
    {
        $officer = array_merge( \LTMS_External_Auditor_Role::AUDITOR_CAPABILITIES, [
            'ltms_compliance'               => true,
            'ltms_generate_legal_evidence'  => true,
            'ltms_view_vendor_contact_info' => true,
            'ltms_manage_kyc'               => true,
            'ltms_freeze_wallets'           => true,
        ]);
        $this->assertFalse( $officer['ltms_approve_payouts'] );
    }

    /** Compliance Officer no puede gestionar todas las tiendas */
    public function test_compliance_officer_cannot_manage_all_vendors(): void
    {
        $officer = array_merge( \LTMS_External_Auditor_Role::AUDITOR_CAPABILITIES, [
            'ltms_compliance'               => true,
            'ltms_generate_legal_evidence'  => true,
            'ltms_view_vendor_contact_info' => true,
            'ltms_manage_kyc'               => true,
            'ltms_freeze_wallets'           => true,
        ]);
        $this->assertFalse( $officer['ltms_manage_all_vendors'] );
    }

    /** Compliance Officer tiene exactamente 5 caps adicionales vs auditor */
    public function test_compliance_officer_has_exactly_5_extra_caps(): void
    {
        $extra = [
            'ltms_compliance'               => true,
            'ltms_generate_legal_evidence'  => true,
            'ltms_view_vendor_contact_info' => true,
            'ltms_manage_kyc'               => true,
            'ltms_freeze_wallets'           => true,
        ];

        $auditor_enabled  = count( array_filter( \LTMS_External_Auditor_Role::AUDITOR_CAPABILITIES ) );
        $officer_caps     = array_merge( \LTMS_External_Auditor_Role::AUDITOR_CAPABILITIES, $extra );
        $officer_enabled  = count( array_filter( $officer_caps ) );

        $this->assertSame( 5, $officer_enabled - $auditor_enabled );
    }

    // ════════════════════════════════════════════════════════════════════════
    // Invariante de seguridad: balance blocked > allowed para caps LTMS
    // ════════════════════════════════════════════════════════════════════════

    public function test_ltms_blocked_caps_outnumber_ltms_allowed_caps(): void
    {
        $caps = \LTMS_External_Auditor_Role::AUDITOR_CAPABILITIES;

        $ltms_caps    = array_filter( $caps, fn($k) => str_starts_with($k, 'ltms_'), ARRAY_FILTER_USE_KEY );
        $ltms_allowed = count( array_filter( $ltms_caps ) );
        $ltms_blocked = count( array_filter( $ltms_caps, fn($v) => $v === false ) );

        $this->assertGreaterThanOrEqual( $ltms_allowed, $ltms_blocked,
            "Deben haber más caps LTMS bloqueadas ({$ltms_blocked}) que permitidas ({$ltms_allowed})" );
    }
}
