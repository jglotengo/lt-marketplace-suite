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

        // INTEGRATIONS-AUDIT P0 FIX (legal hold bypass): the retention cron
        // (class-ltms-retention-cron.php line 107-110) honors `ltms_legal_hold`,
        // but the GDPR eraser previously ignored it. An admin running "Erase
        // Personal Data" on a user under active legal hold (lawsuit, regulatory
        // investigation) would destroy evidence — exposing the operator to
        // sanctions, spoliation charges, and obstruction of justice.
        if ( get_user_meta( $user_id, 'ltms_legal_hold', true ) ) {
            return [
                'items_removed'  => false,
                'items_retained' => true,
                'messages'       => [ __( 'Usuario bajo retención legal (legal hold). Los datos no pueden ser eliminados hasta que se levante la retención.', 'ltms' ) ],
                'done'           => true,
            ];
        }

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
            // GDPR-2 FIX: ZapSign manager uses 'ltms_document_number' (without
            // the 'kyc_' prefix) as the canonical document-number meta key.
            // The original eraser only deleted 'ltms_kyc_document_number' which
            // is a DIFFERENT key — the vendor's government ID number was never
            // actually erased, violating GDPR Art. 17 right to erasure.
            'ltms_document_number',
            'ltms_kyc_approved_at',
            'ltms_phone',
            // GDPR-1 FIX: ZapSign contract data contains PII (vendor name, email,
            // masked document, sign URL tied to vendor identity). The original
            // eraser did NOT delete any contract meta, leaving the vendor's
            // contract token, sign URL, and signing timestamps in user_meta.
            'ltms_contract_token',
            'ltms_contract_status',
            'ltms_contract_sent_at',
            'ltms_contract_signed_at',
            'ltms_contract_sign_url',
            'ltms_contract_status_verified_at',
            '_ltms_zapsign_doc_token',
            '_ltms_zapsign_signed_at',
            // GDPR-3 FIX: B2 backup references — the actual PDF is deleted below
            // via the dedicated backup-deletion block, but the meta keys must
            // also be removed so the vendor's contract can no longer be located.
            'ltms_contract_b2_bucket',
            'ltms_contract_b2_key',
            'ltms_contract_pdf_hash',
            'ltms_contract_backed_up_at',
        ];

        foreach ( $kyc_meta_keys as $key ) {
            if ( get_user_meta( $user_id, $key, true ) ) {
                delete_user_meta( $user_id, $key );
                $items_removed = true;
            }
        }

        // GDPR-3 FIX: delete the signed contract backup from B2.
        // backup_signed_contract() uploads the PDF to B2 but does NOT register
        // it in lt_media_files (the ENUM does not include 'contract'). The only
        // references are the ltms_contract_b2_bucket / ltms_contract_b2_key
        // user_meta keys. Without this block, the signed contract PDF — which
        // contains the vendor's full name, email, masked document, IP, and
        // signature — would remain in B2 forever after GDPR erasure.
        $contract_bucket = get_user_meta( $user_id, 'ltms_contract_b2_bucket', true );
        $contract_key    = get_user_meta( $user_id, 'ltms_contract_b2_key', true );
        if ( $contract_bucket && $contract_key ) {
            try {
                $b2_contract = LTMS_Api_Factory::get( 'backblaze' );
                $b2_contract->delete_file( $contract_bucket, $contract_key );
                $items_removed = true;
                LTMS_Core_Logger::info( 'GDPR_ERASE_CONTRACT', "User #{$user_id} — signed contract deleted from B2: {$contract_bucket}/{$contract_key}" );
            } catch ( \Throwable $e ) {
                $items_retained = true;
                $messages[]     = sprintf(
                    __( 'No se pudo eliminar el contrato firmado en B2 (%s): %s', 'ltms' ),
                    esc_html( $contract_key ),
                    esc_html( $e->getMessage() )
                );
                LTMS_Core_Logger::error( 'GDPR_ERASE_CONTRACT_FAILED', "User #{$user_id} — {$contract_bucket}/{$contract_key}: " . $e->getMessage() );
            }
        }

        // 3. Marcar usuario como borrado por GDPR (protege de retention cron).
        // INTEGRATIONS-AUDIT P1 FIX: only mark as erased when $items_retained is
        // false. Previously, ltms_gdpr_erased_at was written unconditionally —
        // if B2 deletion partially failed, the user was still marked as erased
        // and the retention cron would never retry, orphaning B2 objects forever.
        if ( ! $items_retained ) {
            update_user_meta( $user_id, 'ltms_gdpr_erased_at', current_time( 'mysql', true ) );
            update_user_meta( $user_id, 'ltms_retention_deleted_at', current_time( 'mysql', true ) );
            $items_removed = true;
            LTMS_Core_Logger::info( 'GDPR_ERASE_COMPLETE', "User #{$user_id} ({$email}) — borrado GDPR completado." );
        } else {
            LTMS_Core_Logger::warning(
                'GDPR_ERASE_PARTIAL',
                "User #{$user_id} ({$email}) — borrado GDPR parcial. B2 objects retained — not marking as erased so retention cron will retry."
            );
        }

        return [
            'items_removed'  => $items_removed,
            'items_retained' => $items_retained,
            'messages'       => $messages,
            'done'           => true,
        ];
    }
}
