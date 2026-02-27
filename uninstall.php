<?php
/**
 * LTMS Uninstall — Sistema de Desinstalación de 3 Niveles
 *
 * Ejecutado por WordPress cuando el administrador elimina el plugin desde
 * el panel de plugins (Plugins > Eliminar).
 *
 * NIVELES:
 *  Nivel 1 (Desactivación)  — Solo cron/transients. Ver class-ltms-deactivator.php.
 *  Nivel 2 (Desinstalación) — Siempre: opciones, transients, páginas, roles, caps.
 *                             Las tablas lt_* se PRESERVAN para recuperación de datos.
 *  Nivel 3 (Total)          — Solo si LTMS_UNINSTALL_DELETE_ALL_DATA === true en wp-config.php.
 *                             Genera backup SQL, elimina tablas lt_*, logs y usermeta ltms_.
 *
 * @package LTMS
 * @version 1.5.0
 */

// Verificar que la desinstalación la inició WordPress, no un acceso directo.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// ── NIVEL 2: Siempre ejecutar ─────────────────────────────────────────────────
ltms_uninstall_level2();

// ── NIVEL 3: Solo si el administrador definió la constante en wp-config.php ───
if ( defined( 'LTMS_UNINSTALL_DELETE_ALL_DATA' ) && true === LTMS_UNINSTALL_DELETE_ALL_DATA ) {
    ltms_uninstall_level3();
}

// ─────────────────────────────────────────────────────────────────────────────
// FUNCIONES DE DESINSTALACIÓN
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Nivel 2: Elimina opciones, transients, páginas, roles y capacidades LTMS.
 * Las tablas de base de datos lt_* se preservan para permitir recuperación de datos.
 *
 * @return void
 */
function ltms_uninstall_level2(): void {
    global $wpdb;

    // 1. Borrar todas las opciones wp_options con prefijo 'ltms_'
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM `{$wpdb->options}` WHERE option_name LIKE %s",
            $wpdb->esc_like( 'ltms_' ) . '%'
        )
    );

    // En instalaciones multisite, también limpiar sitemeta.
    if ( is_multisite() ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$wpdb->sitemeta}` WHERE meta_key LIKE %s",
                $wpdb->esc_like( 'ltms_' ) . '%'
            )
        );
    }

    // 2. Borrar transients con prefijo 'ltms_' (incluyendo sus timeouts)
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM `{$wpdb->options}` WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like( '_transient_ltms_' ) . '%',
            $wpdb->esc_like( '_transient_timeout_ltms_' ) . '%'
        )
    );

    // 3. Borrar páginas creadas por el plugin.
    // ltms_installed_pages puede ser un array indexado o asociativo según versión.
    $installed_pages = get_option( 'ltms_installed_pages', [] );
    if ( is_array( $installed_pages ) ) {
        foreach ( $installed_pages as $page_id ) {
            $page_id = absint( $page_id );
            if ( $page_id > 0 && get_post( $page_id ) ) {
                wp_delete_post( $page_id, true );
            }
        }
    }

    // 4. Eliminar roles LTMS.
    $ltms_roles = [
        'ltms_vendor',
        'ltms_vendor_premium',
        'ltms_external_auditor',
        'ltms_compliance_officer',
        'ltms_support_agent',
    ];
    foreach ( $ltms_roles as $role ) {
        remove_role( $role );
    }

    // 5. Eliminar capacidades LTMS del rol Administrator.
    $admin_role = get_role( 'administrator' );
    if ( $admin_role ) {
        $ltms_caps = [
            'ltms_access_dashboard',
            'ltms_manage_vendors',
            'ltms_manage_all_vendors',
            'ltms_manage_payouts',
            'ltms_approve_payouts',
            'ltms_manage_commissions',
            'ltms_manage_kyc',
            'ltms_view_audit_log',
            'ltms_view_security_logs',
            'ltms_view_tax_reports',
            'ltms_view_all_orders',
            'ltms_view_wallet_ledger',
            'ltms_manage_settings',
            'ltms_manage_platform_settings',
            'ltms_access_auditor_dashboard',
        ];
        foreach ( $ltms_caps as $cap ) {
            $admin_role->remove_cap( $cap );
        }
    }

    // 6. Eliminar metadatos de pedidos con prefijo _ltms_ (post_meta legacy).
    $order_meta_keys = [
        '_ltms_vendor_id',
        '_ltms_commission_calculated',
        '_ltms_commission_paid',
        '_ltms_split_status',
        '_ltms_openpay_charge_id',
        '_ltms_openpay_order_id',
        '_ltms_siigo_invoice_id',
        '_ltms_siigo_sync_status',
        '_ltms_addi_application_id',
        '_ltms_aveonline_guide',
        '_ltms_aveonline_tracking',
        '_ltms_zapsign_document_token',
        '_ltms_tptc_order_id',
        '_ltms_tptc_synced',
        '_ltms_xcover_quote_id',
        '_ltms_xcover_policy_id',
        '_ltms_tax_engine_data',
        '_ltms_invoice_pdf_path',
    ];
    foreach ( $order_meta_keys as $meta_key ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->delete( $wpdb->postmeta, [ 'meta_key' => $meta_key ] );
    }

    // phpcs:ignore WordPress.PHP.DevelopmentFunctions
    error_log( 'LTMS: Nivel 2 de desinstalación completado. Tablas de base de datos preservadas para recuperación.' );
}

/**
 * Nivel 3: Eliminación total de datos.
 * Solo se ejecuta si LTMS_UNINSTALL_DELETE_ALL_DATA === true en wp-config.php.
 *
 * - Genera un backup SQL en wp-content antes de eliminar.
 * - Elimina todas las tablas lt_*.
 * - Elimina logs físicos del plugin.
 * - Elimina metadatos de usuarios con prefijo ltms_.
 *
 * @return void
 */
function ltms_uninstall_level3(): void {
    global $wpdb;

    $backup_file = '';

    // ── Generar backup SQL antes de eliminar ───────────────────────────────────
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
    $tables = $wpdb->get_col( "SHOW TABLES LIKE 'lt\\_%'" );

    if ( ! empty( $tables ) ) {
        $sql_backup = "-- LTMS Backup SQL\n";
        $sql_backup .= '-- Generado: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n";
        $sql_backup .= "-- ADVERTENCIA: Este archivo contiene datos sensibles. Tratar con confidencialidad.\n\n";
        $sql_backup .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ( $tables as $table ) {
            // Estructura de la tabla.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $create_row = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
            if ( $create_row ) {
                $sql_backup .= "DROP TABLE IF EXISTS `{$table}`;\n";
                $sql_backup .= $create_row[1] . ";\n\n";
            }

            // Datos de la tabla.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results( "SELECT * FROM `{$table}`", ARRAY_A );
            if ( ! empty( $rows ) ) {
                foreach ( $rows as $row ) {
                    $values = array_map(
                        static function ( $v ) use ( $wpdb ) {
                            if ( null === $v ) {
                                return 'NULL';
                            }
                            return "'" . esc_sql( $v ) . "'";
                        },
                        $row
                    );
                    $sql_backup .= "INSERT INTO `{$table}` VALUES (" . implode( ', ', $values ) . ");\n";
                }
                $sql_backup .= "\n";
            }
        }

        $sql_backup .= "SET FOREIGN_KEY_CHECKS=1;\n";

        $backup_file = WP_CONTENT_DIR . '/ltms-backup-' . gmdate( 'Y-m-d-H-i-s' ) . '.sql';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
        file_put_contents( $backup_file, $sql_backup, LOCK_EX );

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions
        error_log( 'LTMS: Backup SQL generado en ' . $backup_file );
    }

    // ── Eliminar todas las tablas lt_* ─────────────────────────────────────────
    foreach ( $tables as $table ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
    }

    // ── Eliminar archivos de log físicos ──────────────────────────────────────
    $log_dir = WP_CONTENT_DIR . '/uploads/ltms-logs/';
    if ( is_dir( $log_dir ) ) {
        ltms_uninstall_delete_dir( $log_dir );
    }

    // ── Eliminar bóveda de archivos (KYC, contratos) ──────────────────────────
    $vault_dir = WP_CONTENT_DIR . '/uploads/ltms-secure-vault/';
    if ( is_dir( $vault_dir ) ) {
        ltms_uninstall_delete_dir( $vault_dir );
    }

    // ── Eliminar metadatos de usuarios con prefijo ltms_ ─────────────────────
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM `{$wpdb->usermeta}` WHERE meta_key LIKE %s",
            $wpdb->esc_like( 'ltms_' ) . '%'
        )
    );

    // También meta_keys con prefijo guion bajo (_ltms_).
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM `{$wpdb->usermeta}` WHERE meta_key LIKE %s",
            $wpdb->esc_like( '_ltms_' ) . '%'
        )
    );

    $backup_msg = $backup_file ? ' Backup guardado en: ' . $backup_file : ' No se generó backup (no había tablas).';

    // phpcs:ignore WordPress.PHP.DevelopmentFunctions
    error_log( 'LTMS: Nivel 3 de desinstalación completado. Todas las tablas y datos han sido eliminados.' . $backup_msg );
}

/**
 * Elimina un directorio de forma recursiva.
 * Solo para uso durante la desinstalación.
 *
 * @param string $dir Ruta absoluta del directorio a eliminar.
 * @return void
 */
function ltms_uninstall_delete_dir( string $dir ): void {
    if ( ! is_dir( $dir ) ) {
        return;
    }

    $entries = array_diff( (array) scandir( $dir ), [ '.', '..' ] );

    foreach ( $entries as $entry ) {
        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        if ( is_dir( $path ) ) {
            ltms_uninstall_delete_dir( $path );
        } else {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            unlink( $path );
        }
    }

    rmdir( $dir );
}
