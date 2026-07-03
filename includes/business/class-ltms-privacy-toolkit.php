<?php
/**
 * LTMS Privacy Toolkit — Cumplimiento integral de derechos ARCO + Habeas Data.
 *
 * v2.9.13 — Cierra las brechas de privacidad detectadas en la auditoría v2.9.12:
 *
 *  PR-2 (CRÍTICO): WordPress Data Exporter.
 *    Norma: Ley 1581/2012 art. 8 lit. a (CO — Habeas Data, derecho de acceso);
 *           LFPDPPP art. 22-24 (MX — ARCO: Acceso); GDPR art. 15 (Right of access).
 *    Antes: solo existía el Eraser (wp_privacy_personal_data_erasers). El admin
 *           NO podía usar "Herramientas → Exportar datos personales" de WordPress
 *           para generar el reporte JSON exigido por la ley.
 *    Fix: registra wp_privacy_personal_data_exporters con 6 exporters que cubren
 *         perfil, KYC, wallet, comisiones, payouts, consentimientos.
 *
 *  PR-3 (CRÍTICO): Extender el Eraser a TODAS las tablas PII.
 *    Norma: Ley 1581/2012 art. 8 lit. e (CO — Supresión); LFPDPPP art. 25 (MX);
 *           GDPR art. 17 (Right to erasure / "right to be forgotten").
 *    Antes: LTMS_GDPR_Eraser solo borraba archivos KYC en B2 + 17 user_meta keys.
 *           Las 7+ tablas lt_* con PII (wallet_transactions, commissions, payouts,
 *           audit_logs, vendor_kyc, notifications, api_logs, webhook_logs,
 *           referral_network) permanecían intactas → violación del derecho
 *           de supresión.
 *    Fix: hook wp_privacy_personal_data_erasers con un eraser extendido que
 *         anonimiza (no destruye) las filas en tablas con obligación de
 *         retención fiscal, y destruye las filas en tablas sin obligación.
 *
 *  PR-5 (HIGH): Cron de política de retención.
 *    Norma: Ley 1581/2012 art. 11 (CO — limitación temporal); LFPDPPP art. 12
 *           (MX — supresión tras fin del tratamiento); ET art. 632 (CO — 5 años
 *           para documentos fiscales); LISR art. 30 (MX — 5 años).
 *    Antes: no existía ningún cron que eliminara datos tras el periodo de
 *           retención. Los datos personales se conservaban indefinidamente.
 *    Fix: run_retention_policy() se ejecuta diariamente (ltms_daily_cron) y
 *         anonimiza/anonymiza según la política configurada por tipo de dato:
 *           - KYC docs: 3 años tras cierre de cuenta
 *           - Audit logs / consent log: 5 años
 *           - Wallet transactions / commissions: 5 años (obligación fiscal)
 *           - Notifications: 1 año
 *           - API/Webhook logs: 90 días
 *
 *  PR-6: Configuración de retención en admin UI (pestaña Privacidad).
 *
 * Normas cubiertas:
 *  - Colombia: Ley 1581/2012 (Habeas Data), Decreto 1377/2013, ET art. 632.
 *  - México: LFPDPPP, Reglamento de la LFPDPPP, LISR art. 30, CFF art. 30.
 *  - GDPR (si aplica a usuarios EU): arts. 7, 12-22.
 *
 * @package LTMS
 * @version 2.9.13
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LTMS_Privacy_Toolkit {

    /**
     * Periodos de retención por defecto (en días).
     * Configurables via ltms_retention_* options.
     */
    public const RETENTION_DEFAULTS = [
        'kyc_docs'            => 1095, // 3 años tras cierre de cuenta.
        'audit_logs'          => 1825, // 5 años (ET art. 632 / LISR art. 30).
        'consent_log'         => 1825, // 5 años (evidencia de consentimiento).
        'wallet_transactions' => 1825, // 5 años (obligación fiscal).
        'commissions'         => 1825, // 5 años (obligación fiscal).
        'payouts'             => 1825, // 5 años (obligación fiscal).
        'notifications'       => 365,  // 1 año.
        'api_logs'            => 90,   // 90 días.
        'webhook_logs'        => 90,   // 90 días.
        'referral_network'    => 1095, // 3 años.
    ];

    /**
     * Tablas PII con obligación fiscal (no se destruyen, solo se anonimizan).
     * Las filas se conservan para cumplimiento ET art. 632 / LISR art. 30,
     * pero los campos PII se sustituyen por placeholders.
     */
    private const FISCAL_RETENTION_TABLES = [
        'lt_wallet_transactions' => [ 'description' ],
        'lt_commissions'         => [],
        'lt_payout_requests'     => [ 'bank_account', 'bank_reference' ],
    ];

    /**
     * Tablas PII que se DESTRUYEN (DELETE) tras el periodo de retención.
     * No tienen obligación fiscal de conservación.
     */
    private const DESTRUCTIBLE_TABLES = [
        'lt_notifications',
        'lt_api_logs',
        'lt_webhook_logs',
        'lt_consent_log',
    ];

    // ================================================================
    // INIT — Registro de hooks.
    // ================================================================

    public static function init(): void {
        // PR-2: Registrar exporters para "Herramientas → Exportar datos personales".
        add_filter( 'wp_privacy_personal_data_exporters', [ __CLASS__, 'register_exporters' ], 20 );

        // PR-3: Reemplazar el eraser básico de LTMS_GDPR_Eraser con uno completo.
        // Priority 20 para que se ejecute DESPUÉS del eraser original (que solo
        // borra KYC docs en B2). El original se mantiene para backward compat.
        add_filter( 'wp_privacy_personal_data_erasers', [ __CLASS__, 'register_extended_eraser' ], 20 );

        // PR-5: Cron diario de política de retención.
        add_action( 'ltms_daily_cron', [ __CLASS__, 'run_retention_policy' ] );
        add_action( 'wp_ajax_ltms_run_retention_policy', [ __CLASS__, 'ajax_run_retention_policy' ] );

        // PR-5b: AJAX para consultar el reporte de retención.
        add_action( 'wp_ajax_ltms_retention_status', [ __CLASS__, 'ajax_retention_status' ] );
    }

    // ================================================================
    // PR-2: DATA EXPORTERS — WordPress "Tools → Export Personal Data".
    // ================================================================

    /**
     * Registra 6 exporters que cubren todos los datos personales del usuario.
     *
     * Cada exporter devuelve un array de items con 'group_id', 'group_label',
     * 'item_id', 'data' (pares clave/valor).
     *
     * @param array $exporters Exporters existentes.
     * @return array
     */
    public static function register_exporters( array $exporters ): array {
        $exporters['ltms-profile'] = [
            'exporter_friendly_name' => __( 'LT Marketplace Suite — Perfil de usuario', 'ltms' ),
            'callback'               => [ __CLASS__, 'export_profile' ],
        ];
        $exporters['ltms-kyc'] = [
            'exporter_friendly_name' => __( 'LTMS — Datos KYC', 'ltms' ),
            'callback'               => [ __CLASS__, 'export_kyc' ],
        ];
        $exporters['ltms-wallet'] = [
            'exporter_friendly_name' => __( 'LTMS — Transacciones de billetera', 'ltms' ),
            'callback'               => [ __CLASS__, 'export_wallet' ],
        ];
        $exporters['ltms-commissions'] = [
            'exporter_friendly_name' => __( 'LTMS — Comisiones', 'ltms' ),
            'callback'               => [ __CLASS__, 'export_commissions' ],
        ];
        $exporters['ltms-payouts'] = [
            'exporter_friendly_name' => __( 'LTMS — Pagos realizados', 'ltms' ),
            'callback'               => [ __CLASS__, 'export_payouts' ],
        ];
        $exporters['ltms-consents'] = [
            'exporter_friendly_name' => __( 'LTMS — Registro de consentimientos', 'ltms' ),
            'callback'               => [ __CLASS__, 'export_consents' ],
        ];

        return $exporters;
    }

    /**
     * Exporter: Perfil de usuario (user + user_meta PII).
     */
    public static function export_profile( string $email, int $page = 1 ): array {
        $user = get_user_by( 'email', $email );
        if ( ! $user ) {
            return [ 'data' => [], 'done' => true ];
        }

        $user_id = (int) $user->ID;
        $data    = [
            [
                'item_id'     => "user-{$user_id}",
                'data'        => [
                    __( 'Username', 'ltms' )         => $user->user_login,
                    __( 'Email', 'ltms' )            => $user->user_email,
                    __( 'Display name', 'ltms' )     => $user->display_name,
                    __( 'Registered on', 'ltms' )    => $user->user_registered,
                    __( 'Roles', 'ltms' )            => implode( ', ', $user->roles ),
                ],
            ],
        ];

        // PII user_meta keys (NO export internal flags/hashes).
        $pii_keys = [
            'first_name', 'last_name', 'nickname', 'ltms_phone',
            'ltms_document_number', 'ltms_document_type',
            'ltms_kyc_status', 'ltms_kyc_verified_at',
            'ltms_bank_account', 'ltms_bank_name', 'ltms_bank_account_type',
            'ltms_tax_id', 'ltms_tax_regime', 'ltms_ciiu',
            'ltms_country', 'ltms_state', 'ltms_city', 'ltms_address',
            'ltms_registration_ip', 'ltms_terms_version', 'ltms_privacy_version',
            'ltms_sagrilaft_version',
        ];

        $meta_data = [];
        foreach ( $pii_keys as $key ) {
            $val = get_user_meta( $user_id, $key, true );
            if ( '' !== $val && null !== $val ) {
                $meta_data[ $key ] = $val;
            }
        }

        if ( $meta_data ) {
            $data[] = [
                'item_id' => "user-meta-{$user_id}",
                'data'    => $meta_data,
            ];
        }

        return [
            'data' => $data,
            'done' => true,
        ];
    }

    /**
     * Exporter: KYC (fila de lt_vendor_kyc + flags).
     */
    public static function export_kyc( string $email, int $page = 1 ): array {
        global $wpdb;
        $user = get_user_by( 'email', $email );
        if ( ! $user ) {
            return [ 'data' => [], 'done' => true ];
        }
        $user_id = (int) $user->ID;

        $table = $wpdb->prefix . 'lt_vendor_kyc';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE user_id = %d ORDER BY id DESC LIMIT 1",
            $user_id
        ), ARRAY_A );

        if ( ! $row ) {
            return [ 'data' => [], 'done' => true ];
        }

        // Redact sensitive file URLs (just say "yes/no" instead of B2 keys).
        $redacted = [];
        foreach ( $row as $k => $v ) {
            if ( in_array( $k, [ 'file_key', 'file_bucket', 'selfie_key', 'document_url', 'selfie_url' ], true ) ) {
                $redacted[ $k ] = $v ? __( '(archivo almacenado)', 'ltms' ) : __( '(vacío)', 'ltms' );
            } else {
                $redacted[ $k ] = $v;
            }
        }

        return [
            'data' => [
                [
                    'item_id' => "kyc-{$user_id}",
                    'data'    => $redacted,
                ],
            ],
            'done' => true,
        ];
    }

    /**
     * Exporter: Wallet transactions (lt_wallet_transactions).
     */
    public static function export_wallet( string $email, int $page = 1 ): array {
        global $wpdb;
        $user = get_user_by( 'email', $email );
        if ( ! $user ) {
            return [ 'data' => [], 'done' => true ];
        }
        $user_id = (int) $user->ID;

        $per_page = 250;
        $offset   = ( $page - 1 ) * $per_page;
        $table    = $wpdb->prefix . 'lt_wallet_transactions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, type, amount, currency, description, status, created_at
               FROM `{$table}` WHERE vendor_id = %d
               ORDER BY created_at DESC
               LIMIT %d OFFSET %d",
            $user_id, $per_page, $offset
        ), ARRAY_A );

        $data = [];
        if ( $rows ) {
            foreach ( $rows as $row ) {
                $data[] = [
                    'item_id' => "wallet-tx-{$row['id']}",
                    'data'    => [
                        __( 'ID', 'ltms' )          => $row['id'],
                        __( 'Tipo', 'ltms' )        => $row['type'],
                        __( 'Monto', 'ltms' )       => $row['amount'] . ' ' . $row['currency'],
                        __( 'Descripción', 'ltms' ) => $row['description'],
                        __( 'Estado', 'ltms' )      => $row['status'],
                        __( 'Fecha', 'ltms' )       => $row['created_at'],
                    ],
                ];
            }
        }

        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE vendor_id = %d",
            $user_id
        ) );
        $done = ( $offset + $per_page ) >= $total;

        return [
            'data' => $data,
            'done' => $done,
        ];
    }

    /**
     * Exporter: Comisiones (lt_commissions).
     */
    public static function export_commissions( string $email, int $page = 1 ): array {
        global $wpdb;
        $user = get_user_by( 'email', $email );
        if ( ! $user ) {
            return [ 'data' => [], 'done' => true ];
        }
        $user_id = (int) $user->ID;

        $per_page = 250;
        $offset   = ( $page - 1 ) * $per_page;
        $table    = $wpdb->prefix . 'lt_commissions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, order_id, commission_amount, commission_rate, status, created_at
               FROM `{$table}` WHERE vendor_id = %d
               ORDER BY created_at DESC
               LIMIT %d OFFSET %d",
            $user_id, $per_page, $offset
        ), ARRAY_A );

        $data = [];
        if ( $rows ) {
            foreach ( $rows as $row ) {
                $data[] = [
                    'item_id' => "commission-{$row['id']}",
                    'data'    => [
                        __( 'ID', 'ltms' )           => $row['id'],
                        __( 'Pedido', 'ltms' )       => $row['order_id'],
                        __( 'Comisión', 'ltms' )     => $row['commission_amount'],
                        __( 'Tasa', 'ltms' )         => $row['commission_rate'],
                        __( 'Estado', 'ltms' )       => $row['status'],
                        __( 'Fecha', 'ltms' )        => $row['created_at'],
                    ],
                ];
            }
        }

        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE vendor_id = %d",
            $user_id
        ) );
        $done = ( $offset + $per_page ) >= $total;

        return [
            'data' => $data,
            'done' => $done,
        ];
    }

    /**
     * Exporter: Payouts (lt_payout_requests) — incluye datos bancarios.
     */
    public static function export_payouts( string $email, int $page = 1 ): array {
        global $wpdb;
        $user = get_user_by( 'email', $email );
        if ( ! $user ) {
            return [ 'data' => [], 'done' => true ];
        }
        $user_id = (int) $user->ID;

        $per_page = 100;
        $offset   = ( $page - 1 ) * $per_page;
        $table    = $wpdb->prefix . 'lt_payout_requests';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, amount, currency, method, status, bank_account, bank_reference, created_at, processed_at
               FROM `{$table}` WHERE vendor_id = %d
               ORDER BY created_at DESC
               LIMIT %d OFFSET %d",
            $user_id, $per_page, $offset
        ), ARRAY_A );

        $data = [];
        if ( $rows ) {
            foreach ( $rows as $row ) {
                // Mask bank account for security (show only last 4).
                $bank_acct = $row['bank_account'] ?? '';
                $masked    = $bank_acct ? str_repeat( '*', max( 0, strlen( $bank_acct ) - 4 ) ) . substr( $bank_acct, -4 ) : '';
                $data[]    = [
                    'item_id' => "payout-{$row['id']}",
                    'data'    => [
                        __( 'ID', 'ltms' )               => $row['id'],
                        __( 'Monto', 'ltms' )            => $row['amount'] . ' ' . $row['currency'],
                        __( 'Método', 'ltms' )           => $row['method'],
                        __( 'Estado', 'ltms' )           => $row['status'],
                        __( 'Cuenta bancaria', 'ltms' )  => $masked,
                        __( 'Referencia bancaria', 'ltms' ) => $row['bank_reference'],
                        __( 'Solicitado', 'ltms' )       => $row['created_at'],
                        __( 'Procesado', 'ltms' )        => $row['processed_at'],
                    ],
                ];
            }
        }

        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE vendor_id = %d",
            $user_id
        ) );
        $done = ( $offset + $per_page ) >= $total;

        return [
            'data' => $data,
            'done' => $done,
        ];
    }

    /**
     * Exporter: Consent log (lt_consent_log).
     */
    public static function export_consents( string $email, int $page = 1 ): array {
        global $wpdb;
        $user = get_user_by( 'email', $email );
        if ( ! $user ) {
            return [ 'data' => [], 'done' => true ];
        }
        $user_id = (int) $user->ID;

        $table = $wpdb->prefix . 'lt_consent_log';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, consent_type, accepted, version, ip_address, channel, created_at
               FROM `{$table}` WHERE user_id = %d
               ORDER BY created_at DESC",
            $user_id
        ), ARRAY_A );

        $data = [];
        if ( $rows ) {
            foreach ( $rows as $row ) {
                $data[] = [
                    'item_id' => "consent-{$row['id']}",
                    'data'    => [
                        __( 'ID', 'ltms' )                => $row['id'],
                        __( 'Tipo', 'ltms' )              => $row['consent_type'],
                        __( 'Aceptado', 'ltms' )         => $row['accepted'] ? __( 'Sí', 'ltms' ) : __( 'No', 'ltms' ),
                        __( 'Versión del documento', 'ltms' ) => $row['version'],
                        __( 'IP', 'ltms' )               => $row['ip_address'],
                        __( 'Canal', 'ltms' )            => $row['channel'],
                        __( 'Fecha', 'ltms' )            => $row['created_at'],
                    ],
                ];
            }
        }

        return [
            'data' => $data,
            'done' => true,
        ];
    }

    // ================================================================
    // PR-3: EXTENDED ERASER — Anonimiza/destruye TODAS las tablas PII.
    // ================================================================

    /**
     * Registra el eraser extendido que complementa al LTMS_GDPR_Eraser original.
     *
     * El eraser original (LTMS_GDPR_Eraser::erase_kyc_data) se ejecuta primero
     * (priority 10) y borra los archivos KYC en B2 + user_meta keys.
     * Este eraser (priority 20) anonimiza las filas en tablas con obligación
     * fiscal y destruye las filas en tablas sin obligación.
     *
     * @param array $erasers Erasers existentes.
     * @return array
     */
    public static function register_extended_eraser( array $erasers ): array {
        $erasers['ltms-extended-eraser'] = [
            'eraser_friendly_name' => __( 'LTMS — Datos de transacciones y logs (anonimización)', 'ltms' ),
            'callback'             => [ __CLASS__, 'erase_extended_data' ],
        ];
        return $erasers;
    }

    /**
     * Eraser extendido — anonimiza/destruye todas las tablas PII.
     *
     * @param string $email Email del usuario.
     * @param int    $page  Página (no paginamos, todo se hace en 1 paso).
     * @return array
     */
    public static function erase_extended_data( string $email, int $page = 1 ): array {
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
        global $wpdb;

        // 1. ANONIMIZAR tablas con obligación fiscal de retención
        // (mantenemos la fila para auditoría fiscal, pero reemplazamos PII).
        foreach ( self::FISCAL_RETENTION_TABLES as $table_name => $pii_columns ) {
            $full = $wpdb->prefix . $table_name;

            // Detect vendor_id column (some tables use vendor_id, others user_id).
            $vendor_col = ( $table_name === 'lt_payout_requests' ) ? 'vendor_id' : 'vendor_id';

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$full}` WHERE `{$vendor_col}` = %d",
                $user_id
            ) );

            if ( $count > 0 ) {
                // Anonymize PII columns: replace with "[GDPR-anonimizado]".
                $updates = [ 'description' => '[GDPR-anonimizado ' . gmdate( 'Y-m-d' ) . ']' ];
                foreach ( $pii_columns as $col ) {
                    $updates[ $col ] = '[GDPR-anonimizado]';
                }
                $set_clauses = [];
                $values      = [];
                foreach ( $updates as $col => $val ) {
                    $set_clauses[] = "`{$col}` = %s";
                    $values[]      = $val;
                }
                $values[] = $user_id;

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $affected = $wpdb->query( $wpdb->prepare(
                    "UPDATE `{$full}` SET " . implode( ', ', $set_clauses ) . " WHERE `{$vendor_col}` = %d",
                    $values
                ) );

                if ( $affected > 0 ) {
                    $items_retained = true; // Retained for fiscal obligation.
                    $messages[]     = sprintf(
                        // translators: 1: número de filas, 2: nombre de tabla.
                        __( '%1$d filas en %2$s anonimizadas (retenidas por obligación fiscal ET art. 632 / LISR art. 30).', 'ltms' ),
                        $affected, $table_name
                    );
                    LTMS_Core_Logger::info(
                        'GDPR_ERASE_EXTENDED',
                        "User #{$user_id} — {$affected} filas anonimizadas en {$table_name} (retenidas fiscal)."
                    );
                }
            }
        }

        // 2. DESTRUIR filas en tablas sin obligación fiscal.
        foreach ( self::DESTRUCTIBLE_TABLES as $table_name ) {
            $full = $wpdb->prefix . $table_name;
            // Determine the user_id column (default 'user_id').
            $user_col = 'user_id';

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$full}` WHERE `{$user_col}` = %d",
                $user_id
            ) );

            if ( $count > 0 ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $affected = $wpdb->delete( $full, [ $user_col => $user_id ], [ '%d' ] );
                if ( $affected > 0 ) {
                    $items_removed = true;
                    $messages[]    = sprintf(
                        // translators: 1: número de filas, 2: nombre de tabla.
                        __( '%1$d filas destruidas en %2$s.', 'ltms' ),
                        $affected, $table_name
                    );
                    LTMS_Core_Logger::info(
                        'GDPR_ERASE_EXTENDED',
                        "User #{$user_id} — {$affected} filas destruidas en {$table_name}."
                    );
                }
            }
        }

        // 3. Destruir KYC DB row (lt_vendor_kyc). Los archivos B2 ya los borró
        // el eraser original.
        $kyc_table = $wpdb->prefix . 'lt_vendor_kyc';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $kyc_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$kyc_table}` WHERE user_id = %d",
            $user_id
        ) );
        if ( $kyc_count > 0 ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->delete( $kyc_table, [ 'user_id' => $user_id ], [ '%d' ] );
            $items_removed = true;
            $messages[]    = __( 'Registro KYC en BD eliminado.', 'ltms' );
        }

        // 4. Destruir referral_network entries (MLM downline).
        $ref_table = $wpdb->prefix . 'lt_referral_network';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $ref_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$ref_table}` WHERE user_id = %d OR referred_by = %d",
            $user_id, $user_id
        ) );
        if ( $ref_count > 0 ) {
            // Anonymize rather than destroy — downline integrity matters for
            // commission calculations on past orders.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->query( $wpdb->prepare(
                "UPDATE `{$ref_table}` SET user_id = 0 WHERE user_id = %d",
                $user_id
            ) );
            $items_retained = true;
            $messages[]     = __( 'Red de referidos anonimizada (retenida para integridad de comisiones pasadas).', 'ltms' );
        }

        // 5. Audit logs (lt_audit_logs + lt_security_events) — anonimizar.
        foreach ( [ 'lt_audit_logs', 'lt_security_events' ] as $log_table ) {
            $full = $wpdb->prefix . $log_table;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $log_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$full}` WHERE user_id = %d",
                $user_id
            ) );
            if ( $log_count > 0 ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->query( $wpdb->prepare(
                    "UPDATE `{$full}` SET user_id = 0, ip_address = '' WHERE user_id = %d",
                    $user_id
                ) );
                $items_retained = true;
                $messages[]     = sprintf(
                    // translators: 1: número de filas, 2: nombre de tabla.
                    __( '%1$d filas anonimizadas en %2$s (retenidas 5 años por ET art. 632).', 'ltms' ),
                    $log_count, $log_table
                );
            }
        }

        // 6. Mark user as fully erased (flag for retention cron to skip).
        update_user_meta( $user_id, '_ltms_gdpr_full_erasure_at', current_time( 'mysql', true ) );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'GDPR_ERASE_EXTENDED_COMPLETE',
                "User #{$user_id} ({$email}) — erasure extendida completada. removed=" . ( $items_removed ? '1' : '0' ) . ' retained=' . ( $items_retained ? '1' : '0' )
            );
        }

        return [
            'items_removed'  => $items_removed,
            'items_retained' => $items_retained,
            'messages'       => $messages,
            'done'           => true,
        ];
    }

    // ================================================================
    // PR-5: RETENTION POLICY CRON.
    // ================================================================

    /**
     * Ejecuta la política de retención diariamente.
     *
     * - Para cada tabla PII, calcula el corte (hoy - retention_days).
     * - Si la tabla está en FISCAL_RETENTION_TABLES o tiene obligación fiscal:
     *   anonimiza las filas más antiguas que el corte.
     * - Si la tabla está en DESTRUCTIBLE_TABLES:
     *   destruye las filas más antiguas que el corte.
     *
     * @return array Reporte de filas procesadas por tabla.
     */
    public static function run_retention_policy(): array {
        global $wpdb;
        $today    = current_time( 'mysql', true );
        $report   = [
            'run_at'  => $today,
            'tables'  => [],
        ];

        // Tablas con obligación fiscal (anonimizar, no destruir).
        $anon_tables = [
            'lt_wallet_transactions' => [ 'description', 'idempotency_key' ],
            'lt_commissions'         => [],
            'lt_payout_requests'     => [ 'bank_account', 'bank_reference' ],
            'lt_audit_logs'          => [ 'ip_address' ],
            'lt_security_events'     => [ 'ip_address' ],
        ];

        foreach ( $anon_tables as $table_name => $pii_cols ) {
            // Strip "lt_" prefix to match RETENTION_DEFAULTS keys (e.g. "wallet_transactions").
            $opt_key = preg_replace( '/^lt_/', '', $table_name );
            $days    = (int) get_option( "ltms_retention_{$opt_key}", self::RETENTION_DEFAULTS[ $opt_key ] ?? 1825 );
            $cutoff  = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
            $full    = $wpdb->prefix . $table_name;

            // Build UPDATE statement.
            $sets   = [ '`description` = IF(`description` IS NULL OR `description` = "", `description`, CONCAT("[ret-", %s, "]"))' ];
            $params = [ gmdate( 'Y-m-d' ) ];

            foreach ( $pii_cols as $col ) {
                $sets[]   = "`{$col}` = ''";
            }
            $sets[]   = '`retention_anonymized_at` = %s';
            $params[] = $today;

            // Add WHERE clause (created_at < cutoff).
            $params[] = $cutoff;

            // Check if retention_anonymized_at column exists.
            $has_anon_col = (bool) $wpdb->get_var( $wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                DB_NAME, $full, 'retention_anonymized_at'
            ) );

            if ( ! $has_anon_col ) {
                $wpdb->query(
                    "ALTER TABLE `{$full}` ADD COLUMN `retention_anonymized_at` DATETIME DEFAULT NULL COMMENT 'v2.9.13: fecha de anonimización por política de retención'"
                );
            }

            // Only anonymize rows that haven't been anonymized yet.
            $sql = $wpdb->prepare(
                "UPDATE `{$full}` SET " . implode( ', ', $sets ) . "
                  WHERE created_at < %s
                    AND (`retention_anonymized_at` IS NULL OR `retention_anonymized_at` = '')",
                $params
            );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $affected = $wpdb->query( $sql );

            $report['tables'][ $table_name ] = [
                'action'   => 'anonymized',
                'rows'     => (int) $affected,
                'cutoff'   => $cutoff,
                'days'     => $days,
            ];
        }

        // Tablas destructibles (DELETE).
        foreach ( self::DESTRUCTIBLE_TABLES as $table_name ) {
            // Strip "lt_" prefix to match RETENTION_DEFAULTS keys.
            $opt_key = preg_replace( '/^lt_/', '', $table_name );
            $days    = (int) get_option( "ltms_retention_{$opt_key}", self::RETENTION_DEFAULTS[ $opt_key ] ?? 365 );
            $cutoff  = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
            $full    = $wpdb->prefix . $table_name;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $affected = $wpdb->query( $wpdb->prepare(
                "DELETE FROM `{$full}` WHERE created_at < %s",
                $cutoff
            ) );

            $report['tables'][ $table_name ] = [
                'action'   => 'deleted',
                'rows'     => (int) $affected,
                'cutoff'   => $cutoff,
                'days'     => $days,
            ];
        }

        // Vendor KYC docs — anonymize after 3 years post-account-closure.
        // We only process users with `_ltms_account_closed_at` older than 3y.
        $kyc_days  = (int) get_option( 'ltms_retention_kyc_docs', self::RETENTION_DEFAULTS['kyc_docs'] );
        $kyc_cut   = gmdate( 'Y-m-d H:i:s', time() - ( $kyc_days * DAY_IN_SECONDS ) );
        $kyc_table = $wpdb->prefix . 'lt_vendor_kyc';

        // Find users whose account closure metadata is older than the cutoff.
        $closed_users = get_users( [
            'meta_key'     => '_ltms_account_closed_at',
            'meta_compare' => '<',
            'meta_value'   => $kyc_cut,
            'fields'       => 'ID',
            'number'       => 500,
        ] );

        $kyc_anon_count = 0;
        if ( $closed_users ) {
            foreach ( $closed_users as $uid ) {
                // Anonymize KYC row.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->query( $wpdb->prepare(
                    "UPDATE `{$kyc_table}`
                        SET document_number = '[redacted]',
                            document_type   = '[redacted]',
                            file_key        = '',
                            file_bucket     = '',
                            selfie_key      = '',
                            status          = 'anonymized'
                      WHERE user_id = %d",
                    $uid
                ) );
                ++$kyc_anon_count;
            }
        }
        $report['tables']['lt_vendor_kyc'] = [
            'action'   => 'anonymized',
            'rows'     => $kyc_anon_count,
            'cutoff'   => $kyc_cut,
            'days'     => $kyc_days,
        ];

        // Persist report for admin UI.
        update_option( 'ltms_retention_last_run', $report, false );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'RETENTION_POLICY_RUN',
                'v2.9.13: política de retención ejecutada. ' . wp_json_encode( $report )
            );
        }

        return $report;
    }

    /**
     * AJAX: ejecutar política de retención manualmente.
     */
    public static function ajax_run_retention_policy(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permisos insuficientes.', 'ltms' ) ], 403 );
        }
        check_ajax_referer( 'ltms_retention_nonce', 'nonce' );

        $report = self::run_retention_policy();
        wp_send_json_success( $report );
    }

    /**
     * AJAX: estado de la política de retención.
     */
    public static function ajax_retention_status(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permisos insuficientes.', 'ltms' ) ], 403 );
        }
        check_ajax_referer( 'ltms_retention_nonce', 'nonce' );

        $last_run = get_option( 'ltms_retention_last_run', null );
        wp_send_json_success( [ 'last_run' => $last_run ] );
    }

    // ================================================================
    // HELPERS.
    // ================================================================

    /**
     * Devuelve la configuración de retención (con defaults).
     *
     * @return array
     */
    public static function get_retention_config(): array {
        $config = [];
        foreach ( self::RETENTION_DEFAULTS as $key => $default ) {
            $config[ $key ] = (int) get_option( "ltms_retention_{$key}", $default );
        }
        return $config;
    }

    /**
     * Devuelve las normas legales aplicables a cada tabla.
     *
     * @return array
     */
    public static function get_legal_basis(): array {
        return [
            'lt_wallet_transactions' => 'ET art. 632 (CO) / LISR art. 30 (MX) — 5 años',
            'lt_commissions'         => 'ET art. 632 (CO) / LISR art. 30 (MX) — 5 años',
            'lt_payout_requests'     => 'ET art. 632 (CO) / LISR art. 30 (MX) — 5 años',
            'lt_audit_logs'          => 'ET art. 632 (CO) / LISR art. 30 (MX) — 5 años',
            'lt_security_events'     => 'ET art. 632 (CO) / LISR art. 30 (MX) — 5 años',
            'lt_consent_log'         => 'Ley 1581 art. 10 (CO) / LFPDPPP art. 11 (MX) — 5 años',
            'lt_notifications'       => 'Política interna — 1 año',
            'lt_api_logs'            => 'Política interna — 90 días',
            'lt_webhook_logs'        => 'Política interna — 90 días',
            'lt_vendor_kyc'          => 'Ley 1581 art. 11 (CO) — 3 años tras cierre',
            'lt_referral_network'    => 'Política interna — 3 años',
        ];
    }
}
