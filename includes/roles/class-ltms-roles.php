<?php
/**
 * LTMS Roles - Definición de Roles y Capacidades RBAC
 *
 * Define e instala los roles personalizados del plugin con sus
 * capacidades granulares. Soporta el modelo de seguridad de LTMS
 * (Vendedor, Auditor Externo, Oficial de Cumplimiento, Soporte).
 *
 * @package    LTMS
 * @subpackage LTMS/includes/roles
 * @version    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Roles
 */
final class LTMS_Roles {

    /**
     * Capacidades del rol Vendedor estándar.
     *
     * @var array<string, bool>
     */
    public const VENDOR_CAPABILITIES = [
        'read'                             => true,
        'upload_files'                     => true,

        // Capacidades de negocio LTMS
        'ltms_access_dashboard'            => true,
        'ltms_manage_own_products'         => true,
        'ltms_view_own_orders'             => true,
        'ltms_view_own_wallet'             => true,
        'ltms_request_payout'              => true,
        'ltms_view_own_commissions'        => true,
        'ltms_manage_own_settings'         => true,
        'ltms_view_own_analytics'          => true,
        'ltms_manage_coupons'              => true,
        'ltms_download_marketing_assets'   => true,
        'ltms_view_referral_network'       => true,
        'ltms_use_pos'                     => true,

        // Restricciones explícitas
        'ltms_view_other_vendor_data'      => false,
        'ltms_approve_payouts'             => false,
        'ltms_manage_platform_settings'    => false,
        'ltms_view_tax_reports'            => false,
        'ltms_export_customer_db'          => false,
        'ltms_compliance'                  => false,
    ];

    /**
     * Capacidades del rol Vendedor Premium (mayor acceso).
     *
     * @var array<string, bool>
     */
    public const VENDOR_PREMIUM_CAPABILITIES = [
        'read'                             => true,
        'upload_files'                     => true,
        'ltms_access_dashboard'            => true,
        'ltms_manage_own_products'         => true,
        'ltms_view_own_orders'             => true,
        'ltms_view_own_wallet'             => true,
        'ltms_request_payout'              => true,
        'ltms_view_own_commissions'        => true,
        'ltms_manage_own_settings'         => true,
        'ltms_view_own_analytics'          => true,
        'ltms_manage_coupons'              => true,
        'ltms_download_marketing_assets'   => true,
        'ltms_view_referral_network'       => true,
        'ltms_use_pos'                     => true,
        'ltms_bulk_import_products'        => true,
        'ltms_advanced_analytics'          => true,
        'ltms_custom_commission_rates'     => true,
    ];

    /**
     * Capacidades del Agente de Soporte.
     *
     * @var array<string, bool>
     */
    public const SUPPORT_AGENT_CAPABILITIES = [
        'read'                              => true,
        'ltms_access_dashboard'             => true,
        'ltms_view_vendor_data_readonly'    => true,
        'ltms_view_all_orders_readonly'     => true,
        'ltms_create_support_tickets'       => true,
        'ltms_update_order_notes'           => true,
        'ltms_view_tracking'                => true,
    ];

    /**
     * Inicializa el sistema de roles (en cada request).
     *
     * @return void
     */
    public static function init(): void {
        // Los roles se instalan solo una vez (en activación).
        // Aquí solo registramos filtros de capacidades dinámicos.
        add_filter( 'user_has_cap', [ __CLASS__, 'dynamic_capabilities' ], 10, 4 );
    }

    /**
     * Instala los roles en la base de datos de WordPress.
     * Llamado únicamente en la activación del plugin.
     *
     * @return void
     */
    public static function install(): void {
        // Vendedor estándar
        remove_role( 'ltms_vendor' );
        add_role( 'ltms_vendor', __( 'Vendedor LTMS', 'ltms' ), self::VENDOR_CAPABILITIES );

        // Vendedor premium
        remove_role( 'ltms_vendor_premium' );
        add_role( 'ltms_vendor_premium', __( 'Vendedor Premium LTMS', 'ltms' ), self::VENDOR_PREMIUM_CAPABILITIES );

        // Agente de soporte
        remove_role( 'ltms_support_agent' );
        add_role( 'ltms_support_agent', __( 'Soporte LTMS', 'ltms' ), self::SUPPORT_AGENT_CAPABILITIES );

        // Agregar capacidades de gestión al rol Administrator de WordPress
        $admin_role = get_role( 'administrator' );
        if ( $admin_role ) {
            $admin_caps = [
                'ltms_access_dashboard',
                'ltms_manage_all_vendors',
                'ltms_approve_payouts',
                'ltms_manage_platform_settings',
                'ltms_view_tax_reports',
                'ltms_view_compliance_logs',
                'ltms_view_wallet_ledger',
                'ltms_view_all_orders',
                'ltms_manage_kyc',
                'ltms_export_reports',
                'ltms_compliance',
                'ltms_view_security_logs',
                'ltms_manage_roles',
                'ltms_freeze_wallets',
                'ltms_generate_legal_evidence',
                'ltms_view_audit_log',            // required by Historial Fiscal submenu
            ];

            foreach ( $admin_caps as $cap ) {
                $admin_role->add_cap( $cap, true );
            }
        }
    }

    /**
     * Desinstala los roles del plugin.
     * Llamado en uninstall.php.
     *
     * @return void
     */
    public static function uninstall(): void {
        remove_role( 'ltms_vendor' );
        remove_role( 'ltms_vendor_premium' );
        remove_role( 'ltms_support_agent' );
        remove_role( 'ltms_external_auditor' );
        remove_role( 'ltms_compliance_officer' );
    }

    /**
     * Filtro de capacidades dinámicas: añade restricciones contextuales.
     * Ej: Un vendedor solo puede gestionar sus propios productos.
     *
     * @param bool[]   $allcaps Todas las capacidades del usuario.
     * @param string[] $caps    Capacidades solicitadas.
     * @param array    $args    Argumentos (0=cap, 1=user_id, 2=object_id).
     * @param \WP_User $user    Usuario actual.
     * @return bool[]
     */
    public static function dynamic_capabilities( array $allcaps, array $caps, array $args, \WP_User $user ): array {
        // Si no hay args[2] (object_id), no hay restricción adicional
        if ( empty( $args[2] ) ) {
            return $allcaps;
        }

        $capability = $args[0] ?? '';
        $object_id  = (int) $args[2];

        // Restricción: Vendedor solo puede editar sus propios productos
        if (
            $capability === 'edit_post' &&
            in_array( 'ltms_vendor', (array) $user->roles, true ) &&
            $object_id > 0
        ) {
            $post = get_post( $object_id );
            if ( $post && $post->post_author != $user->ID ) {
                $allcaps['edit_post'] = false;
            }
        }

        return $allcaps;
    }

    /**
     * Obtiene el nombre legible de un rol LTMS.
     *
     * @param string $role_slug Slug del rol.
     * @return string Nombre legible.
     */
    public static function get_role_label( string $role_slug ): string {
        $labels = [
            'ltms_vendor'            => __( 'Vendedor', 'ltms' ),
            'ltms_vendor_premium'    => __( 'Vendedor Premium', 'ltms' ),
            'ltms_external_auditor'  => __( 'Auditor Externo', 'ltms' ),
            'ltms_compliance_officer'=> __( 'Oficial de Cumplimiento', 'ltms' ),
            'ltms_support_agent'     => __( 'Soporte', 'ltms' ),
            'administrator'          => __( 'Administrador', 'ltms' ),
        ];

        return $labels[ $role_slug ] ?? ucfirst( str_replace( [ 'ltms_', '_' ], [ '', ' ' ], $role_slug ) );
    }
}
