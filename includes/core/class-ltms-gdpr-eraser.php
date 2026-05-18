<?php
/**
 * LTMS GDPR Eraser — Derecho al Olvido (Ley 1581/2012 Art. 8 + GDPR Art. 17)
 *
 * Integra con la herramienta nativa de WordPress "Borrar datos personales"
 * (wp_privacy_personal_data_erasers) para eliminar todos los datos KYC
 * de un usuario desde Backblaze B2 y la BD local.
 *
 * @package LTMS
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LTMS_GDPR_Eraser {

    public static function init(): void {
        add_filter( 'wp_privacy_personal_data_erasers', [ __CLASS__, 'register_eraser' ] );
    }

    public static function register_eraser( array $erasers ): array {
        $erasers['ltms-kyc-eraser'] = [
            'eraser_friendly_name' => __( 'LT Marketplace Suite — Documentos KYC', 'ltms' ),
            'callback'             => [ __CLASS__, 'erase_kyc_data' ],
        ];
        return $erasers;
    }

    public static function erase_kyc_data( string $email, int $page = 1 ): array {
        $user = get_user_by( 'email', $email );

        if ( ! $user ) {
            return [
                'items_removed'  => false,
                'items_retained' => false,
                'messages'       => [],
                'done'           => true,
            ];
        }

        $user_id        = (int) $user->ID;
        $items_removed  = false;
        $items_retained = false;
        $messages       = [];

        // 1. Eliminar archivos de Backblaze B2
        global $wpdb;
        $table = $wpdb->prefix . 'lt_media_files';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, file_key, bucket FROM `{$table}` WHERE entity_id = %d AND entity_type IN ('kyc','contract') AND is_private = 1",
            $user_id
        ) );

        if ( $rows ) {
            try {
                $b2 = LTMS_Api_Factory::get( 'backblaze' );
                foreach ( $rows as $row ) {
                    try {
                        $b2->delete_file( $row->bucket, $row->file_key );
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                        $wpdb->delete( $table, [ 'id' => (int) $row->id ], [ '%d' ] );
                        $items_removed = true;
                        LTMS_Core_Logger::info( 'GDPR_ERASE_FILE', "User #{$user_id} — {$row->file_key} eliminado." );
                    } catch ( \Throwable $e ) {
                        $items_retained = true;
                        $messages[]     = sprintf(
                            __( 'No se pudo eliminar el archivo %s: %s', 'ltms' ),
                            esc_html( $row->file_key ),
                            esc_html( $e->getMessage() )
                        );
                        LTMS_Core_Logger::error( 'GDPR_ERASE_FILE_FAILED', "User #{$user_id} — {$row->file_key}: " . $e->getMessage() );
                    }
                }
            } catch ( \Throwable $e ) {
                $items_retained = true;
                $messages[]     = __( 'No se pudo conectar con Backblaze B2 para eliminar archivos KYC.', 'ltms' );
                LTMS_Core_Logger::error( 'GDPR_ERASE_B2_INIT', $e->getMessage() );
            }
        }

        // 2. Eliminar user_meta KYC
        $kyc_meta_keys = [
            'ltms_kyc_document_url',
            'ltms_kyc_selfie_url',
            'ltms_kyc_document_number',
            'ltms_kyc_document_type',
            'ltms_kyc_status',
            'ltms_kyc_archived_at',
            'ltms_kyc_verified_at',
            'ltms_kyc_rejected_reason',
        ];

        foreach ( $kyc_meta_keys as $key ) {
            if ( get_user_meta( $user_id, $key, true ) ) {
                delete_user_meta( $user_id, $key );
                $items_removed = true;
            }
        }

        // 3. Marcar usuario como borrado por GDPR (protege de retention cron)
        update_user_meta( $user_id, 'ltms_gdpr_erased_at', current_time( 'mysql', true ) );
        update_user_meta( $user_id, 'ltms_retention_deleted_at', current_time( 'mysql', true ) );
        $items_removed = true;

        LTMS_Core_Logger::info( 'GDPR_ERASE_COMPLETE', "User #{$user_id} ({$email}) — borrado GDPR completado." );

        return [
            'items_removed'  => $items_removed,
            'items_retained' => $items_retained,
            'messages'       => $messages,
            'done'           => true,
        ];
    }
}
