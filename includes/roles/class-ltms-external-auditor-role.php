<?php
/**
 * LTMS External Auditor Role - Protocolo de Cuarto Limpio
 *
 * Define el rol de Auditor Externo con acceso de solo lectura a datos
 * fiscales, enmascarando la información comercial sensible (GDPR + SAGRILAFT).
 *
 * @package    LTMS
 * @subpackage LTMS/includes/roles
 * @version    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_External_Auditor_Role
 */
final class LTMS_External_Auditor_Role {

    /**
     * Capacidades del Auditor Externo (DIAN/SAT/Supersociedades).
     * Solo lectura de datos fiscales. SIN acceso a datos comerciales.
     *
     * @var array<string, bool>
     */
    public const AUDITOR_CAPABILITIES = [
        'read'                             => true,

        // Datos Fiscales (VISIBLES al auditor)
        'ltms_view_tax_reports'            => true,
        'ltms_view_compliance_logs'        => true,
        'ltms_view_wallet_ledger'          => true,
        'ltms_view_invoice_registry'       => true,
        'ltms_view_kyc_status'             => true,     // Estado KYC, NO los archivos
        'ltms_view_transaction_trace'      => true,     // Trazabilidad Pedido→Cobro→Factura
        'ltms_view_sagrilaft_report'       => true,     // Origen de fondos Colombia
        'ltms_access_auditor_dashboard'    => true,

        // Datos Comerciales (BLOQUEADOS para el auditor)
        'ltms_view_vendor_contact_info'    => false,
        'ltms_export_customer_db'          => false,
        'ltms_view_all_orders'             => false,
        'ltms_manage_platform_settings'    => false,
        'ltms_approve_payouts'             => false,
        'ltms_manage_all_vendors'          => false,
        'ltms_compliance'                  => false,
        'ltms_generate_legal_evidence'     => false,

        // WordPress standard - Mínimos permisos
        'edit_posts'                       => false,
        'delete_posts'                     => false,
        'publish_posts'                    => false,
        'manage_options'                   => false,
        'list_users'                       => false,
    ];

    /**
     * Inicializa hooks relacionados con el auditor externo.
     *
     * @return void
     */
    public static function init(): void {
        // Registrar logging automático de acceso del auditor
        add_action( 'admin_init', [ __CLASS__, 'log_auditor_page_access' ] );

        // Forzar 2FA para auditores externos (si está habilitado)
        add_action( 'wp_login', [ __CLASS__, 'enforce_2fa_for_auditors' ], 10, 2 );
    }

    /**
     * Instala el rol en WordPress.
     *
     * @return void
     */
    public static function install(): void {
        remove_role( 'ltms_external_auditor' );
        add_role(
            'ltms_external_auditor',
            __( 'Auditor Externo LTMS', 'ltms' ),
            self::AUDITOR_CAPABILITIES
        );

        // Oficial de Cumplimiento (acceso completo pero solo para datos legales)
        remove_role( 'ltms_compliance_officer' );
        add_role(
            'ltms_compliance_officer',
            __( 'Oficial de Cumplimiento LTMS', 'ltms' ),
            array_merge( self::AUDITOR_CAPABILITIES, [
                'ltms_compliance'                  => true,
                'ltms_generate_legal_evidence'     => true,
                'ltms_view_vendor_contact_info'    => true,
                'ltms_manage_kyc'                  => true,
                'ltms_freeze_wallets'              => true,
            ])
        );
    }

    /**
     * Registra en el log forense cada vez que el auditor visita una página.
     *
     * @return void
     */
    public static function log_auditor_page_access(): void {
        if ( ! current_user_can( 'ltms_access_auditor_dashboard' ) ) {
            return;
        }

        $page = sanitize_text_field( $_GET['page'] ?? 'admin' );

        LTMS_Core_Logger::security(
            'AUDITOR_ACCESS',
            sprintf(
                'Auditor externo (ID:%d) accedió a la página: %s',
                get_current_user_id(),
                $page
            ),
            [
                'user_id'    => get_current_user_id(),
                'page'       => $page,
                'ip'         => LTMS_Utils::get_ip(),
                'user_agent' => sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' ),
            ]
        );
    }

    /**
     * Enforces 2FA login for external auditors.
     * If 2FA is enabled and auditor doesn't have verified session, redirect to 2FA.
     *
     * @param string   $user_login Username.
     * @param \WP_User $user       User object.
     * @return void
     */
    public static function enforce_2fa_for_auditors( string $user_login, \WP_User $user ): void {
        if ( ! in_array( 'ltms_external_auditor', (array) $user->roles, true ) ) {
            return;
        }

        $two_fa_required = LTMS_Core_Config::get( 'ltms_2fa_required_auditors', 'yes' );
        if ( $two_fa_required !== 'yes' ) {
            return;
        }

        // Marcar sesión como pendiente de verificación 2FA
        update_user_meta( $user->ID, '_ltms_2fa_session_verified', false );

        // La verificación real de 2FA se implementa en class-ltms-public-auth-handler.php
        LTMS_Core_Logger::security(
            'AUDITOR_LOGIN',
            sprintf( 'Auditor externo "%s" inició sesión. 2FA requerido.', $user_login ),
            [ 'user_id' => $user->ID, 'ip' => LTMS_Utils::get_ip() ]
        );
    }
}
