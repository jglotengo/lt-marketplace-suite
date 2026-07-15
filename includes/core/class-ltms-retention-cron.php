<?php
/**
 * LTMS Retention Cron — Política SAGRILAFT de Retención de Datos KYC
 *
 * @package LTMS
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LTMS_Retention_Cron {

    const SAGRILAFT_YEARS = 5;
    const GRACE_DAYS      = 30;
    const BATCH_SIZE      = 50;
    const CRON_HOOK       = 'ltms_retention_daily_sweep';

    /**
     * RC-2 FIX: returns the legal data-retention period in years for the
     * current operating country.
     *
     *   - Colombia (CO): SAGRILAFT / Ley 1581/2012 → 5 years.
     *   - México (MX):   Ley General de Protección de Datos Personales en
     *                    Posesión de los Particulares (LFPDPPP) Art. 16 +
     *                    SAT fiscal retention → 10 years.
     *
     * Previously the cron used a hardcoded SAGRILAFT_YEARS=5 for ALL countries,
     * which would delete MX vendor KYC data after 5 years — violating Mexican
     * law (10 years) and exposing the operator to sanctions by INAI.
     *
     * @return int Years to retain KYC data before archive/delete.
     */
    private static function get_retention_years(): int {
        $country = method_exists( 'LTMS_Core_Config', 'get_country' )
            ? LTMS_Core_Config::get_country()
            : 'CO';
        return strtoupper( $country ) === 'MX' ? 10 : self::SAGRILAFT_YEARS;
    }

    public static function init(): void {
        add_filter( 'cron_schedules', [ __CLASS__, 'add_daily_schedule' ] );
        add_action( self::CRON_HOOK, [ __CLASS__, 'run_daily_sweep' ] );

        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( strtotime( 'tomorrow 03:00:00 UTC' ), 'ltms_daily', self::CRON_HOOK );
        }

        add_action( 'wp_ajax_ltms_retention_manual_sweep', [ __CLASS__, 'handle_manual_sweep_ajax' ] );
    }

    public static function deactivate(): void {
        $ts = wp_next_scheduled( self::CRON_HOOK );
        if ( $ts ) {
            wp_unschedule_event( $ts, self::CRON_HOOK );
        }
    }

    public static function add_daily_schedule( array $schedules ): array {
        if ( ! isset( $schedules['ltms_daily'] ) ) {
            $schedules['ltms_daily'] = [
                'interval' => DAY_IN_SECONDS,
                'display'  => __( 'LTMS: Una vez al día', 'ltms' ),
            ];
        }
        return $schedules;
    }

    public static function run_daily_sweep(): void {
        LTMS_Core_Logger::info( 'RETENTION_SWEEP_START', 'Iniciando barrido de retención KYC.' );
        $start = microtime( true );
        $stats = [ 'archived' => 0, 'deleted' => 0, 'protected' => 0, 'errors' => 0 ];

        foreach ( self::get_candidates( self::BATCH_SIZE ) as $user_id ) {
            try {
                $action = self::evaluate_user( (int) $user_id );
                match ( $action ) {
                    'delete'  => ( self::delete_kyc_files( (int) $user_id ) && $stats['deleted']++ ),
                    'archive' => ( self::mark_archived( (int) $user_id ) && $stats['archived']++ ),
                    default   => $stats['protected']++,
                };
                self::log_sweep_action( (int) $user_id, $action );
            } catch ( \Throwable $e ) {
                $stats['errors']++;
                LTMS_Core_Logger::error( 'RETENTION_SWEEP_ERROR', "User #{$user_id}: " . $e->getMessage() );
            }
        }

        $elapsed = round( microtime( true ) - $start, 2 );
        LTMS_Core_Logger::info( 'RETENTION_SWEEP_DONE', sprintf(
            'Completado en %ss — Arch: %d | Del: %d | Prot: %d | Err: %d',
            $elapsed, $stats['archived'], $stats['deleted'], $stats['protected'], $stats['errors']
        ) );

        update_option( 'ltms_last_retention_sweep', [
            'timestamp' => current_time( 'mysql', true ),
            'stats'     => $stats,
            'elapsed'   => $elapsed,
        ], false );
    }

    private static function evaluate_user( int $user_id ): string {
        // RC-1 FIX: legal hold — if the user is under legal hold (active dispute,
        // investigation, lawsuit, regulatory request), NEVER archive or delete.
        // Admin sets ltms_legal_hold = '1' (or a reason string) via the admin UI.
        $legal_hold = get_user_meta( $user_id, 'ltms_legal_hold', true );
        if ( ! empty( $legal_hold ) ) {
            return 'protect';
        }

        if ( get_user_meta( $user_id, 'ltms_gdpr_erased_at', true ) ) {
            return 'protect';
        }

        $last_tx = self::get_last_transaction_date( $user_id );

        // RC-2 FIX: country-aware retention period.
        $retention_years = self::get_retention_years();

        if ( ! $last_tx ) {
            $user_data  = get_userdata( $user_id );
            $registered = $user_data ? strtotime( $user_data->user_registered ) : time();
            if ( ( time() - $registered ) / YEAR_IN_SECONDS < 1 ) {
                return 'protect';
            }
            return self::resolve_archive_or_delete( $user_id );
        }

        if ( ( time() - $last_tx ) / YEAR_IN_SECONDS < $retention_years ) {
            return 'protect';
        }

        return self::resolve_archive_or_delete( $user_id );
    }

    private static function resolve_archive_or_delete( int $user_id ): string {
        $archived_at = get_user_meta( $user_id, 'ltms_kyc_archived_at', true );
        if ( $archived_at ) {
            $grace_end = strtotime( $archived_at ) + ( self::GRACE_DAYS * DAY_IN_SECONDS );
            if ( time() > $grace_end ) {
                return 'delete';
            }
        }
        return 'archive';
    }

    private static function delete_kyc_files( int $user_id ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_media_files';

        // INTEGRATIONS-AUDIT P1 FIX: track failures so we don't mark the user
        // as fully deleted when B2 objects remain. Previously, the function
        // returned true unconditionally — orphaning B2 objects forever because
        // the cron wrote ltms_retention_deleted_at and never retried.
        $had_failure = false;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, file_key, bucket FROM `{$table}` WHERE entity_id = %d AND entity_type IN ('kyc','contract') AND is_private = 1",
            $user_id
        ) );

        if ( $rows ) {
            try {
                $b2 = LTMS_Api_Factory::get( 'backblaze' );
            } catch ( \Throwable $e ) {
                LTMS_Core_Logger::error( 'RETENTION_B2_INIT', $e->getMessage() );
                return false;
            }

            foreach ( $rows as $row ) {
                try {
                    $b2->delete_file( $row->bucket, $row->file_key );
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    $wpdb->delete( $table, [ 'id' => (int) $row->id ], [ '%d' ] );
                    LTMS_Core_Logger::info( 'RETENTION_FILE_DELETED', "User #{$user_id} — {$row->file_key}" );
                } catch ( \Throwable $e ) {
                    $had_failure = true;
                    LTMS_Core_Logger::error( 'RETENTION_FILE_DELETE_FAILED', "User #{$user_id} — {$row->file_key}: " . $e->getMessage() );
                }
            }
        }

        // RC-3 FIX: also delete the signed contract backup from B2.
        $contract_bucket = get_user_meta( $user_id, 'ltms_contract_b2_bucket', true );
        $contract_key    = get_user_meta( $user_id, 'ltms_contract_b2_key', true );
        if ( $contract_bucket && $contract_key ) {
            try {
                $b2_contract = LTMS_Api_Factory::get( 'backblaze' );
                $b2_contract->delete_file( $contract_bucket, $contract_key );
                LTMS_Core_Logger::info( 'RETENTION_CONTRACT_DELETED', "User #{$user_id} — signed contract: {$contract_bucket}/{$contract_key}" );
            } catch ( \Throwable $e ) {
                $had_failure = true;
                LTMS_Core_Logger::error( 'RETENTION_CONTRACT_DELETE_FAILED', "User #{$user_id} — {$contract_bucket}/{$contract_key}: " . $e->getMessage() );
            }
        }

        foreach ( [ 'ltms_kyc_document_url', 'ltms_kyc_selfie_url', 'ltms_kyc_document_number', 'ltms_kyc_status', 'ltms_kyc_archived_at',
                    // RC-3 FIX: also clear contract meta keys so no dangling references remain.
                    'ltms_contract_token', 'ltms_contract_status', 'ltms_contract_sent_at', 'ltms_contract_signed_at',
                    'ltms_contract_sign_url', 'ltms_contract_b2_bucket', 'ltms_contract_b2_key', 'ltms_contract_pdf_hash',
                    'ltms_contract_backed_up_at', 'ltms_document_number', 'ltms_phone' ] as $key ) {
            delete_user_meta( $user_id, $key );
        }

        if ( $had_failure ) {
            // Don't mark as deleted — cron will retry on next run.
            LTMS_Core_Logger::warning(
                'RETENTION_PARTIAL',
                "User #{$user_id} — partial deletion (some B2 objects failed). Will retry on next sweep."
            );
            return false;
        }

        update_user_meta( $user_id, 'ltms_retention_deleted_at', current_time( 'mysql', true ) );
        return true;
    }

    private static function mark_archived( int $user_id ): bool {
        if ( ! get_user_meta( $user_id, 'ltms_kyc_archived_at', true ) ) {
            update_user_meta( $user_id, 'ltms_kyc_archived_at', current_time( 'mysql', true ) );
            LTMS_Core_Logger::info( 'RETENTION_ARCHIVED', "User #{$user_id} — KYC archivado. Eliminación en " . self::GRACE_DAYS . ' días.' );

            if ( LTMS_Core_Config::get( 'ltms_retention_notify_user', true ) ) {
                self::send_archive_notification( $user_id );
            }
        }
        return true;
    }

    private static function get_candidates( int $limit ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_media_files';
        // INTEGRATIONS-AUDIT P1 FIX: add ORDER BY MAX(created_at) ASC so the
        // oldest KYC data is processed first. Without this, MySQL returned rows
        // in arbitrary order (typically primary key) — if the first 50 candidates
        // were all "protect" (recent transactions, legal hold), they occupied
        // the slots forever and users 51+ never got evaluated, leaving their
        // KYC data past the legal retention window (SAGRILAFT/Ley 1581 violation).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT entity_id FROM `{$table}` WHERE entity_type = 'kyc' AND is_private = 1 AND entity_id NOT IN ( SELECT user_id FROM `{$wpdb->usermeta}` WHERE meta_key = 'ltms_gdpr_erased_at' ) GROUP BY entity_id ORDER BY MAX(created_at) ASC LIMIT %d",
            $limit
        ) );
        return array_map( 'intval', $rows ?: [] );
    }

    private static function get_last_transaction_date( int $user_id ): ?int {
        global $wpdb;
        $hpos = $wpdb->prefix . 'wc_orders';
        // FASE6 P1 FIX: use $wpdb->prepare for SHOW TABLES to prevent SQL injection
        // via $wpdb->prefix manipulation (defense-in-depth).
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $hpos ) ) === $hpos ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $d = $wpdb->get_var( $wpdb->prepare(
                "SELECT MAX(date_created_gmt) FROM `{$hpos}` WHERE customer_id = %d AND status NOT IN ('cancelled','failed','trash')",
                $user_id
            ) );
            return $d ? strtotime( $d ) : null;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $d = $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(p.post_date_gmt) FROM `{$wpdb->posts}` p INNER JOIN `{$wpdb->postmeta}` pm ON pm.post_id = p.ID AND pm.meta_key = '_customer_user' AND pm.meta_value = %d WHERE p.post_type = 'shop_order' AND p.post_status NOT IN ('wc-cancelled','wc-failed','trash')",
            $user_id
        ) );
        return $d ? strtotime( $d ) : null;
    }

    private static function send_archive_notification( int $user_id ): void {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return;
        }
        $grace_end = date_i18n( get_option( 'date_format' ), time() + ( self::GRACE_DAYS * DAY_IN_SECONDS ) );
        $subject   = sprintf( '[%s] Tus documentos de identidad serán eliminados', get_bloginfo( 'name' ) );
        $message   = sprintf(
            "Hola %s,\n\nTus documentos KYC han cumplido el período de conservación legal (SAGRILAFT/Ley 1581/2012).\n\nProcederemos a eliminarlos el %s si no hay transacciones activas.\n\nSaludos,\nEl equipo de %s",
            $user->display_name, $grace_end, get_bloginfo( 'name' )
        );
        wp_mail( $user->user_email, $subject, $message );
    }

    private static function log_sweep_action( int $user_id, string $action ): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert( $wpdb->prefix . 'lt_retention_log', [
            'user_id'  => $user_id,
            'action'   => sanitize_key( $action ),
            'swept_at' => current_time( 'mysql', true ),
        ], [ '%d', '%s', '%s' ] );
    }

    public static function handle_manual_sweep_ajax(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_manage_kyc' ) ) {
            wp_send_json_error( __( 'Sin permiso.', 'ltms' ), 403 );
        }
        self::run_daily_sweep();
        wp_send_json_success( get_option( 'ltms_last_retention_sweep', [] ) );
    }
}
