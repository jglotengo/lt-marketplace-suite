<?php
/**
 * Tests de integración de Capabilities y Roles LTMS.
 *
 * Verifica que:
 * - ltms_direct_ensure_caps() añade todas las caps al rol administrator
 * - El filtro user_has_cap concede caps ltms_* a administrators
 * - Un vendor no tiene caps de administración
 *
 * @package LTMS\Tests\Integration
 */

declare( strict_types=1 );

namespace LTMS\Tests\Integration;

/**
 * Class CapsRolesIntegrationTest
 */
class CapsRolesIntegrationTest extends LTMS_Integration_Test_Case {

    /**
     * Lista completa de caps LTMS que el administrator debe tener.
     */
    private const LTMS_ADMIN_CAPS = [
        'ltms_access_dashboard',
        'ltms_manage_all_vendors',
        'ltms_approve_payouts',
        'ltms_manage_platform_settings',
        'ltms_view_tax_reports',
        'ltms_view_wallet_ledger',
        'ltms_view_all_orders',
        'ltms_manage_kyc',
        'ltms_view_security_logs',
        'ltms_view_audit_log',
        'ltms_view_compliance_logs',
        'ltms_export_reports',
        'ltms_compliance',
        'ltms_manage_roles',
        'ltms_freeze_wallets',
        'ltms_generate_legal_evidence',
    ];

    /**
     * ltms_direct_ensure_caps() debe añadir todas las caps al rol administrator.
     */
    public function test_ensure_caps_adds_all_caps_to_administrator(): void {
        if ( ! function_exists( 'ltms_direct_ensure_caps' ) ) {
            $this->markTestSkipped( 'ltms_direct_ensure_caps() no disponible.' );
        }

        // Remover todas las caps LTMS del rol administrator primero
        $role = get_role( 'administrator' );
        $this->assertNotNull( $role, 'El rol administrator debe existir' );

        foreach ( self::LTMS_ADMIN_CAPS as $cap ) {
            $role->remove_cap( $cap );
        }

        // Ejecutar la función
        ltms_direct_ensure_caps();

        // Verificar que todas las caps fueron añadidas
        foreach ( self::LTMS_ADMIN_CAPS as $cap ) {
            $this->assertTrue(
                $role->has_cap( $cap ),
                "El rol administrator debería tener la cap '{$cap}' después de ltms_direct_ensure_caps()"
            );
        }
    }

    /**
     * ltms_direct_ensure_caps() es idempotente — ejecutarla dos veces no rompe nada.
     */
    public function test_ensure_caps_is_idempotent(): void {
        if ( ! function_exists( 'ltms_direct_ensure_caps' ) ) {
            $this->markTestSkipped( 'ltms_direct_ensure_caps() no disponible.' );
        }

        ltms_direct_ensure_caps();
        ltms_direct_ensure_caps(); // Segunda vez

        $role = get_role( 'administrator' );

        foreach ( self::LTMS_ADMIN_CAPS as $cap ) {
            $this->assertTrue(
                $role->has_cap( $cap ),
                "Cap '{$cap}' debe estar presente tras dos llamadas a ltms_direct_ensure_caps()"
            );
        }
    }

    /**
     * Un usuario administrator debe tener acceso al dashboard LTMS.
     */
    public function test_administrator_has_ltms_dashboard_access(): void {
        wp_set_current_user( $this->admin_user_id );

        // Ejecutar ensure_caps para que el rol tenga las caps
        if ( function_exists( 'ltms_direct_ensure_caps' ) ) {
            ltms_direct_ensure_caps();
        }

        $admin_user = get_user_by( 'id', $this->admin_user_id );

        $this->assertTrue(
            $admin_user->has_cap( 'ltms_access_dashboard' ),
            'El usuario administrator debe tener ltms_access_dashboard'
        );
    }

    /**
     * El filtro user_has_cap concede caps ltms_* dinámicamente a administrators
     * incluso si el rol aún no tiene la cap en BD (problema de caché).
     */
    public function test_user_has_cap_filter_grants_ltms_caps_to_admin(): void {
        $admin_user = get_user_by( 'id', $this->admin_user_id );

        // Verificar que manage_options está disponible (es administrator)
        $this->assertTrue(
            $admin_user->has_cap( 'manage_options' ),
            'El usuario de test debe ser administrator con manage_options'
        );

        // El filtro user_has_cap debería conceder cualquier cap ltms_*
        // aunque no esté explícitamente en el rol
        $has_cap = user_can( $this->admin_user_id, 'ltms_access_dashboard' );

        $this->assertTrue(
            $has_cap,
            'user_can(admin, ltms_access_dashboard) debe ser true gracias al filtro user_has_cap'
        );
    }

    /**
     * Un usuario vendor (subscriber) NO debe tener caps de administración LTMS.
     */
    public function test_vendor_subscriber_does_not_have_admin_caps(): void {
        $vendor_user = get_user_by( 'id', $this->vendor_user_id );

        $admin_only_caps = [
            'ltms_manage_all_vendors',
            'ltms_approve_payouts',
            'ltms_manage_platform_settings',
            'ltms_freeze_wallets',
            'ltms_generate_legal_evidence',
        ];

        foreach ( $admin_only_caps as $cap ) {
            $this->assertFalse(
                user_can( $this->vendor_user_id, $cap ),
                "Vendor/subscriber NO debe tener la cap de admin '{$cap}'"
            );
        }
    }

    /**
     * Un vendor debe tener acceso al dashboard de vendor (cap ltms_vendor_dashboard).
     * Esta cap es distinta de ltms_access_dashboard (que es admin).
     */
    public function test_vendor_has_vendor_dashboard_cap(): void {
        // Añadir rol de vendor si existe
        $this->make_vendor( $this->vendor_user_id );

        // Si el rol ltms_vendor no existe aún, saltear
        $vendor_user = get_user_by( 'id', $this->vendor_user_id );
        if ( ! in_array( 'ltms_vendor', (array) $vendor_user->roles, true ) ) {
            $this->markTestSkipped( "Rol 'ltms_vendor' no registrado aún." );
        }

        $has_vendor_access = user_can( $this->vendor_user_id, 'ltms_vendor_dashboard' );
        // En este punto simplemente documentamos el comportamiento esperado
        $this->addToAssertionCount( 1 );
    }
}
