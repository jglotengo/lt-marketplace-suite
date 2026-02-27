<?php
/**
 * LTMS Deactivator - Tareas al Desactivar el Plugin
 *
 * Ejecuta las tareas de limpieza necesarias al desactivar el plugin:
 * - Eliminar cron jobs programados
 * - Limpiar transients
 * - Registrar la desactivación en el log
 * - (NO elimina datos de BD — eso se hace en uninstall.php)
 *
 * @package    LTMS
 * @subpackage LTMS/includes/core/services
 * @version    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Core_Deactivator
 */
final class LTMS_Core_Deactivator {

    /**
     * Ejecuta las tareas de desactivación.
     *
     * @return void
     */
    public static function deactivate(): void {
        self::clear_cron_jobs();
        self::clear_transients();
        self::flush_rewrite_rules();
        self::log_deactivation();
        self::set_deactivation_notice();
    }

    /**
     * Elimina todos los eventos cron del plugin.
     *
     * @return void
     */
    private static function clear_cron_jobs(): void {
        $hooks = [
            'ltms_process_payouts',
            'ltms_sync_siigo',
            'ltms_integrity_check',
            'ltms_process_job_queue',
            'ltms_update_tracking',
            'ltms_cleanup_sessions',
            'ltms_generate_tax_reports',
            'ltms_approve_payout_cron',
        ];

        foreach ( $hooks as $hook ) {
            $timestamp = wp_next_scheduled( $hook );
            if ( $timestamp ) {
                wp_unschedule_event( $timestamp, $hook );
            }
            wp_clear_scheduled_hook( $hook );
        }

        // Limpiar Action Scheduler si está disponible
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( '', [], 'ltms-siigo' );
            as_unschedule_all_actions( '', [], 'ltms-payouts' );
            as_unschedule_all_actions( '', [], 'ltms' );
        }
    }

    /**
     * Limpia todos los transients del plugin.
     *
     * @return void
     */
    private static function clear_transients(): void {
        global $wpdb;

        // Transients directos
        $transients = [
            'ltms_siigo_token',
            'ltms_addi_token_CO',
            'ltms_addi_token_MX',
            'ltms_waf_blocked_ips',
            'ltms_platform_stats',
        ];

        foreach ( $transients as $transient ) {
            delete_transient( $transient );
        }

        // Transients por prefijo (WAF IP blocks)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$wpdb->options}` WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like( '_transient_ltms_' ) . '%',
                $wpdb->esc_like( '_transient_timeout_ltms_' ) . '%'
            )
        );
    }

    /**
     * Regenera las reglas de rewrite de WordPress.
     *
     * @return void
     */
    private static function flush_rewrite_rules(): void {
        flush_rewrite_rules( false );
    }

    /**
     * Registra la desactivación en el log.
     *
     * @return void
     */
    private static function log_deactivation(): void {
        $user_id    = get_current_user_id();
        $user_login = $user_id ? ( get_userdata( $user_id )->user_login ?? 'unknown' ) : 'system';

        update_option( 'ltms_last_deactivated_at', LTMS_Utils::now_utc() );
        update_option( 'ltms_last_deactivated_by', $user_login );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'PLUGIN_DEACTIVATED',
                sprintf( 'LT Marketplace Suite v%s desactivado por: %s', LTMS_VERSION, $user_login ),
                [ 'user_id' => $user_id ]
            );
        }
    }

    /**
     * Almacena una bandera para mostrar un aviso informativo al administrador
     * en la próxima carga de página tras la desactivación.
     * El aviso se consume en admin_notices (en el bootstrap del plugin si sigue activo,
     * o en el plugin que gestione esa lógica). Dado que al desactivarse este plugin
     * no hay admin_notices hook disponible de forma inmediata, se usa update_option
     * para que otro hook (o la próxima instancia de admin) lo muestre y lo elimine.
     *
     * @return void
     */
    private static function set_deactivation_notice(): void {
        // Nivel 1: Los datos permanecen intactos. Se notifica al admin.
        update_option( 'ltms_show_deactivation_notice', '1' );
    }
}
