<?php
/**
 * LTMS DB Migrations - Instalación y Actualización del Esquema de BD
 *
 * Ejecuta las migraciones de base de datos de forma idempotente.
 * Usa la versión almacenada en ltms_db_version para determinar
 * qué migraciones aplicar.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/core/migrations
 * @version    1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_DB_Migrations
 */
final class LTMS_DB_Migrations {

    /**
     * Versión actual del esquema de BD.
     *
     * Bumped 2.8.1 → 2.8.2 (AUDIT-REDI-UX-GAPS GAP-9) para forzar la
     * creación de las tablas lt_redi_incidents y lt_redi_incident_comments
     * en sites ya migrados a 2.8.1.
     */
    private const CURRENT_VERSION = '2.9.13';

    /**
     * Ejecuta las migraciones pendientes.
     *
     * @return void
     */
    public static function run(): void {
        $installed_version = get_option( 'ltms_db_version', '0.0.0' );

        if ( version_compare( $installed_version, self::CURRENT_VERSION, '>=' ) ) {
            return; // Todo actualizado
        }

        // Ejecutar migraciones en orden
        self::create_tables();
        self::create_indexes();

        // Migraciones de actualización de esquema (no cubiertas por dbDelta)
        if ( version_compare( $installed_version, '2.2.0', '<' ) ) {
            self::migrate_2_2_0_fiscal_sat_mexico();
        }

        if ( version_compare( $installed_version, '2.1.0', '<' ) ) {
            self::migrate_2_1_0_fiscal_dian();
        }

        if ( version_compare( $installed_version, '2.0.1', '<' ) ) {
            self::migrate_2_0_1_payout_schema();
        }

        if ( version_compare( $installed_version, '2.3.0', '<' ) ) {
            self::migrate_2_3_0();
        }

        if ( version_compare( $installed_version, '2.4.0', '<' ) ) {
            self::migrate_2_4_0();
        }

        if ( version_compare( $installed_version, '2.5.0', '<' ) ) {
            self::migrate_2_5_0();
            self::migrate_2_6_0();
        }

        if ( version_compare( $installed_version, '2.7.0', '<' ) ) {
            self::migrate_2_7_0_donations();
        }

        if ( version_compare( $installed_version, '2.7.1', '<' ) ) {
            self::migrate_2_7_1_fintech_fixes();
        }

        if ( version_compare( $installed_version, '2.8.0', '<' ) ) {
            self::migrate_2_8_0_cross_border();
        }

        if ( version_compare( $installed_version, '2.8.1', '<' ) ) {
            self::migrate_2_8_1_wallet_multicurrency();
        }

        if ( version_compare( $installed_version, '2.8.3', '<' ) ) {
            self::migrate_2_8_3_shipping_cost_ledger();
        }

        if ( version_compare( $installed_version, '2.9.1', '<' ) ) {
            self::migrate_2_9_1_forensic_retention_tables();
        }

        if ( version_compare( $installed_version, '2.9.8', '<' ) ) {
            self::migrate_2_9_8_fiscal_30b_columns();
        }

        if ( version_compare( $installed_version, '2.9.13', '<' ) ) {
            self::migrate_2_9_13_consent_log_schema_fix();
        }

        update_option( 'ltms_db_version', self::CURRENT_VERSION );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'DB_MIGRATION_COMPLETE',
                sprintf( 'BD actualizada de v%s a v%s', $installed_version, self::CURRENT_VERSION )
            );
        }
    }

    /**
     * Crea o actualiza todas las tablas del plugin usando dbDelta.
     *
     * @return void
     */
    private static function create_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $p       = $wpdb->prefix;

        $sqls = [];

        // lt_vendor_wallets
        // NOTE: vendor_id is BIGINT (signed) to allow the foundation wallet sentinel
        // value of -1 (LTMS_Donation_Manager::FOUNDATION_VENDOR_ID). BIGINT UNSIGNED
        // would reject INSERTs with vendor_id=-1 on strict MySQL (INT-BUG-1 / Task 62-C).
        $sqls[] = "CREATE TABLE `{$p}lt_vendor_wallets` (
            `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `vendor_id`         BIGINT NOT NULL,
            `balance`           DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `balance_pending`   DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `balance_reserved`  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `currency`          CHAR(3) NOT NULL DEFAULT 'COP',
            `is_frozen`         TINYINT(1) NOT NULL DEFAULT 0,
            `freeze_reason`     VARCHAR(500) DEFAULT NULL,
            `total_earned`      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `total_withdrawn`   DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `last_transaction`  DATETIME DEFAULT NULL,
            `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `checksum`          CHAR(64) DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `udx_vendor_currency` (`vendor_id`, `currency`)
        ) {$charset}";

        // lt_wallet_transactions
        // NOTE: vendor_id is BIGINT (signed) — credit/debit operations on the
        // foundation wallet insert vendor_id=-1 here; UNSIGNED would reject them.
        // INT-BUG-1 / Task 62-C.
        $sqls[] = "CREATE TABLE `{$p}lt_wallet_transactions` (
            `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `wallet_id`       BIGINT UNSIGNED NOT NULL,
            `vendor_id`       BIGINT NOT NULL,
            `order_id`        BIGINT UNSIGNED DEFAULT NULL,
            `type`            ENUM('credit','debit','hold','release','reversal','adjustment','payout','fee','tax_withholding') NOT NULL,
            `amount`          DECIMAL(15,2) NOT NULL,
            `balance_before`  DECIMAL(15,2) NOT NULL,
            `balance_after`   DECIMAL(15,2) NOT NULL,
            `currency`        CHAR(3) NOT NULL DEFAULT 'COP',
            `description`     VARCHAR(500) NOT NULL,
            `reference`       VARCHAR(255) DEFAULT NULL,
            `status`          ENUM('pending','completed','failed','reversed') NOT NULL DEFAULT 'completed',
            `metadata`        LONGTEXT DEFAULT NULL,
            `ip_address`      VARCHAR(45) DEFAULT NULL,
            `created_by`      BIGINT UNSIGNED DEFAULT NULL,
            `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `checksum`        CHAR(64) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_wallet_id` (`wallet_id`),
            KEY `idx_vendor_id` (`vendor_id`),
            KEY `idx_order_id` (`order_id`)
        ) {$charset}";

        // lt_commissions
        $sqls[] = "CREATE TABLE `{$p}lt_commissions` (
            `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `order_id`          BIGINT UNSIGNED NOT NULL,
            `order_item_id`     BIGINT UNSIGNED DEFAULT NULL,
            `vendor_id`         BIGINT UNSIGNED NOT NULL,
            `product_id`        BIGINT UNSIGNED DEFAULT NULL,
            `type`              VARCHAR(50) NOT NULL DEFAULT 'commission' COMMENT 'commission | referral | redi | adjustment',
            `gross_amount`      DECIMAL(15,2) NOT NULL,
            `commission_rate`   DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
            `commission_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Comisión de la plataforma',
            `vendor_amount`     DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Monto neto para el vendedor',
            `tax_withholding`   DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `iva_amount`        DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `currency`          CHAR(3) NOT NULL DEFAULT 'COP',
            `country_code`      CHAR(2) NOT NULL DEFAULT 'CO',
            `status`            ENUM('pending','approved','paid','reversed','disputed') NOT NULL DEFAULT 'pending',
            `paid_at`           DATETIME DEFAULT NULL,
            `strategy_applied`  VARCHAR(100) DEFAULT NULL,
            `notes`             TEXT DEFAULT NULL COMMENT 'Notas internas; ej: vendor_id:123 para referidos',
            `metadata`          LONGTEXT DEFAULT NULL,
            `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_order_id`  (`order_id`),
            KEY `idx_vendor_id` (`vendor_id`),
            KEY `idx_status`    (`status`),
            KEY `idx_type`      (`type`)
        ) {$charset}";

        // lt_payout_requests
        $sqls[] = "CREATE TABLE `{$p}lt_payout_requests` (
            `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `vendor_id`        BIGINT UNSIGNED NOT NULL,
            `wallet_id`        BIGINT UNSIGNED DEFAULT NULL,
            `amount`           DECIMAL(15,2) NOT NULL,
            `fee`              DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Comisión de retiro',
            `net_amount`       DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Monto neto después de fee',
            `currency`         CHAR(3) NOT NULL DEFAULT 'COP',
            `method`           ENUM('bank_transfer','paypal','nequi','daviplata','spei','clabe','openpay') NOT NULL DEFAULT 'bank_transfer',
            `bank_account`     LONGTEXT DEFAULT NULL,
            `bank_account_id`  VARCHAR(100) DEFAULT NULL COMMENT 'ID de cuenta bancaria registrada',
            `reference`        VARCHAR(100) DEFAULT NULL COMMENT 'Referencia interna LTMS (ej: PAY-XXXX)',
            `gateway_ref`      VARCHAR(255) DEFAULT NULL COMMENT 'Referencia externa del gateway de pago',
            `status`           ENUM('pending','approved','processing','completed','rejected','cancelled') NOT NULL DEFAULT 'pending',
            `notes`            TEXT DEFAULT NULL COMMENT 'Notas internas / errores de gateway',
            `admin_notes`      TEXT DEFAULT NULL,
            `rejection_reason` VARCHAR(500) DEFAULT NULL,
            `reconciled`       TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Marcado como conciliado por banco',
            `reconciled_at`    DATETIME DEFAULT NULL,
            `transaction_id`   BIGINT UNSIGNED DEFAULT NULL,
            `external_ref`     VARCHAR(255) DEFAULT NULL,
            `approved_by`      BIGINT UNSIGNED DEFAULT NULL COMMENT 'ID del admin que aprobó',
            `processed_by`     BIGINT UNSIGNED DEFAULT NULL,
            `requested_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `processed_at`     DATETIME DEFAULT NULL,
            `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_vendor_id`    (`vendor_id`),
            KEY `idx_status`       (`status`),
            KEY `idx_reconciled`   (`reconciled`),
            KEY `idx_created_at`   (`created_at`)
        ) {$charset}";

        // lt_audit_logs
        $sqls[] = "CREATE TABLE `{$p}lt_audit_logs` (
            `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `event_code`  VARCHAR(100) NOT NULL,
            `message`     TEXT NOT NULL,
            `context`     LONGTEXT DEFAULT NULL,
            `level`       ENUM('DEBUG','INFO','WARNING','ERROR','CRITICAL','SECURITY') NOT NULL DEFAULT 'INFO',
            `user_id`     BIGINT UNSIGNED DEFAULT NULL,
            `ip_address`  VARCHAR(45) DEFAULT NULL,
            `user_agent`  VARCHAR(500) DEFAULT NULL,
            `url`         VARCHAR(2048) DEFAULT NULL,
            `source`      VARCHAR(100) DEFAULT NULL,
            `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_event_code` (`event_code`),
            KEY `idx_level` (`level`),
            KEY `idx_created_at` (`created_at`)
        ) {$charset}";

        // lt_security_events
        $sqls[] = "CREATE TABLE `{$p}lt_security_events` (
            `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `event_type`      VARCHAR(100) NOT NULL,
            `severity`        ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
            `ip_address`      VARCHAR(45) NOT NULL,
            `user_id`         BIGINT UNSIGNED DEFAULT NULL,
            `request_uri`     VARCHAR(2048) DEFAULT NULL,
            `request_method`  VARCHAR(10) DEFAULT NULL,
            `payload`         TEXT DEFAULT NULL,
            `rule_matched`    VARCHAR(255) DEFAULT NULL,
            `blocked`         TINYINT(1) NOT NULL DEFAULT 1,
            `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_ip_address` (`ip_address`),
            KEY `idx_event_type` (`event_type`),
            KEY `idx_created_at` (`created_at`)
        ) {$charset}";

        // lt_waf_blocked_ips
        $sqls[] = "CREATE TABLE `{$p}lt_waf_blocked_ips` (
            `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `ip_address`   VARCHAR(45) NOT NULL,
            `reason`       VARCHAR(255) NOT NULL,
            `block_count`  INT UNSIGNED NOT NULL DEFAULT 1,
            `expires_at`   DATETIME DEFAULT NULL,
            `is_permanent` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `udx_ip` (`ip_address`)
        ) {$charset}";

        // lt_vendor_kyc
        $sqls[] = "CREATE TABLE `{$p}lt_vendor_kyc` (
            `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `vendor_id`        BIGINT UNSIGNED NOT NULL,
            `document_type`    VARCHAR(20) NOT NULL,
            `document_number`  VARCHAR(255) NOT NULL,
            `full_name`        VARCHAR(255) NOT NULL,
            `file_path`        VARCHAR(500) DEFAULT NULL,
            `file_hash`        CHAR(64) DEFAULT NULL,
            `status`           ENUM('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending',
            `reviewed_by`      BIGINT UNSIGNED DEFAULT NULL,
            `reviewed_at`      DATETIME DEFAULT NULL,
            `notes`            VARCHAR(500) DEFAULT NULL,
            `submitted_at`     DATETIME DEFAULT CURRENT_TIMESTAMP,
            `expires_at`       DATE DEFAULT NULL,
            `country_code`     CHAR(2) NOT NULL DEFAULT 'CO',
            `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_vendor_id` (`vendor_id`),
            KEY `idx_status` (`status`)
        ) {$charset}";

        // lt_referral_network
        $sqls[] = "CREATE TABLE `{$p}lt_referral_network` (
            `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `vendor_id`        BIGINT UNSIGNED NOT NULL,
            `sponsor_id`       BIGINT UNSIGNED NOT NULL,
            `level`            TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `ancestor_path`    VARCHAR(1000) DEFAULT NULL COMMENT 'Slash-separated ancestor IDs root-first',
            `source`           VARCHAR(100) DEFAULT NULL,
            `total_sales`      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `total_commission` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `status`           ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
            `joined_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `udx_vendor_sponsor` (`vendor_id`, `sponsor_id`),
            KEY `idx_sponsor_id` (`sponsor_id`),
            KEY `idx_joined_at`  (`joined_at`)
        ) {$charset}";

        // lt_notifications
        $sqls[] = "CREATE TABLE `{$p}lt_notifications` (
            `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id`    BIGINT UNSIGNED NOT NULL,
            `type`       VARCHAR(100) NOT NULL,
            `channel`    ENUM('inapp','email','whatsapp','sms','push') NOT NULL DEFAULT 'inapp',
            `title`      VARCHAR(255) NOT NULL,
            `message`    TEXT NOT NULL,
            `data`       LONGTEXT DEFAULT NULL,
            `is_read`    TINYINT(1) NOT NULL DEFAULT 0,
            `read_at`    DATETIME DEFAULT NULL,
            `sent_at`    DATETIME DEFAULT NULL,
            `expires_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`),
            KEY `idx_is_read` (`is_read`)
        ) {$charset}";

        // lt_api_logs
        $sqls[] = "CREATE TABLE `{$p}lt_api_logs` (
            `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `provider`      VARCHAR(50) NOT NULL,
            `endpoint`      VARCHAR(500) NOT NULL,
            `method`        VARCHAR(10) NOT NULL,
            `request_body`  MEDIUMTEXT DEFAULT NULL,
            `response_code` SMALLINT DEFAULT NULL,
            `response_body` MEDIUMTEXT DEFAULT NULL,
            `duration_ms`   INT UNSIGNED DEFAULT NULL,
            `status`        ENUM('success','error','timeout','retry') NOT NULL DEFAULT 'success',
            `order_id`      BIGINT UNSIGNED DEFAULT NULL,
            `error_message` TEXT DEFAULT NULL,
            `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_provider` (`provider`),
            KEY `idx_status` (`status`),
            KEY `idx_created_at` (`created_at`)
        ) {$charset}";

        // lt_webhook_logs
        $sqls[] = "CREATE TABLE `{$p}lt_webhook_logs` (
            `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `provider`      VARCHAR(50) NOT NULL,
            `event_type`    VARCHAR(100) NOT NULL,
            `payload`       MEDIUMTEXT NOT NULL,
            `signature`     VARCHAR(500) DEFAULT NULL,
            `is_valid`      TINYINT(1) DEFAULT NULL,
            `status`        ENUM('received','processing','processed','failed','ignored') NOT NULL DEFAULT 'received',
            `order_id`      BIGINT UNSIGNED DEFAULT NULL,
            `error_message` TEXT DEFAULT NULL,
            `ip_address`    VARCHAR(45) DEFAULT NULL,
            `attempts`      TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `processed_at`  DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_provider` (`provider`),
            KEY `idx_status` (`status`),
            KEY `idx_created_at` (`created_at`)
        ) {$charset}";

        // lt_job_queue
        $sqls[] = "CREATE TABLE `{$p}lt_job_queue` (
            `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `hook`          VARCHAR(200) NOT NULL,
            `args`          LONGTEXT DEFAULT NULL,
            `priority`      TINYINT UNSIGNED NOT NULL DEFAULT 10,
            `status`        ENUM('pending','processing','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
            `attempts`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `max_attempts`  TINYINT UNSIGNED NOT NULL DEFAULT 3,
            `scheduled_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `started_at`    DATETIME DEFAULT NULL,
            `completed_at`  DATETIME DEFAULT NULL,
            `error_message` TEXT DEFAULT NULL,
            `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_hook` (`hook`),
            KEY `idx_status` (`status`),
            KEY `idx_scheduled_at` (`scheduled_at`)
        ) {$charset}";

        // lt_marketing_banners
        $sqls[] = "CREATE TABLE `{$p}lt_marketing_banners` (
            `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `title`           VARCHAR(255) NOT NULL,
            `type`            ENUM('banner','flyer','social_post','email_template','video') NOT NULL DEFAULT 'banner',
            `file_url`        VARCHAR(500) NOT NULL,
            `thumbnail_url`   VARCHAR(500) DEFAULT NULL,
            `dimensions`      VARCHAR(50) DEFAULT NULL,
            `category`        VARCHAR(100) DEFAULT NULL,
            `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
            `download_count`  INT UNSIGNED NOT NULL DEFAULT 0,
            `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_type` (`type`),
            KEY `idx_is_active` (`is_active`)
        ) {$charset}";

        // lt_deposits
        $sqls[] = "CREATE TABLE `{$p}lt_deposits` (
            `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `vendor_id`        BIGINT UNSIGNED NOT NULL,
            `amount`           DECIMAL(15,2) NOT NULL,
            `currency`         CHAR(3) NOT NULL DEFAULT 'COP',
            `type`             ENUM('security','tournament','promotional') NOT NULL DEFAULT 'security',
            `status`           ENUM('held','released','forfeited','partial_release') NOT NULL DEFAULT 'held',
            `reason`           VARCHAR(500) DEFAULT NULL,
            `released_amount`  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `release_date`     DATE DEFAULT NULL,
            `order_id`         BIGINT UNSIGNED DEFAULT NULL,
            `admin_notes`      TEXT DEFAULT NULL,
            `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_vendor_id` (`vendor_id`),
            KEY `idx_status` (`status`)
        ) {$charset}";

        // lt_tax_reports
        $sqls[] = "CREATE TABLE `{$p}lt_tax_reports` (
            `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `period_start`    DATE NOT NULL,
            `period_end`      DATE NOT NULL,
            `country_code`    CHAR(2) NOT NULL,
            `report_type`     VARCHAR(50) NOT NULL,
            `total_sales`     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `total_tax`       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `total_withheld`  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `vendor_count`    INT UNSIGNED NOT NULL DEFAULT 0,
            `order_count`     INT UNSIGNED NOT NULL DEFAULT 0,
            `report_data`     LONGTEXT DEFAULT NULL,
            `pdf_path`        VARCHAR(500) DEFAULT NULL,
            `xml_path`        VARCHAR(500) DEFAULT NULL,
            `status`          ENUM('draft','generated','submitted','accepted','rejected') NOT NULL DEFAULT 'draft',
            `generated_by`    BIGINT UNSIGNED NOT NULL,
            `generated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_period` (`period_start`, `period_end`),
            KEY `idx_country` (`country_code`)
        ) {$charset}";

        // lt_rate_limits
        $sqls[] = "CREATE TABLE `{$p}lt_rate_limits` (
            `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `identifier`     VARCHAR(255) NOT NULL,
            `action`         VARCHAR(100) NOT NULL,
            `attempts`       INT UNSIGNED NOT NULL DEFAULT 1,
            `window_start`   DATETIME NOT NULL,
            `blocked_until`  DATETIME DEFAULT NULL,
            `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `udx_identifier_action` (`identifier`(191), `action`)
        ) {$charset}";

        // ── v1.6.0 Tables ────────────────────────────────────────────

        // lt_media_files — Backblaze B2 file tracking
        $sqls[] = "CREATE TABLE `{$p}lt_media_files` (
            `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `file_key`      VARCHAR(500) NOT NULL,
            `bucket`        VARCHAR(255) NOT NULL,
            `original_name` VARCHAR(500) DEFAULT NULL,
            `mime_type`     VARCHAR(100) DEFAULT NULL,
            `file_size`     BIGINT UNSIGNED DEFAULT NULL,
            `file_hash`     CHAR(64) DEFAULT NULL,
            `entity_type`   ENUM('kyc','product','invoice','marketing','other') NOT NULL,
            `entity_id`     BIGINT UNSIGNED NOT NULL,
            `is_private`    TINYINT(1) NOT NULL DEFAULT 1,
            `uploader_id`   BIGINT UNSIGNED NOT NULL,
            `b2_file_id`    VARCHAR(255) DEFAULT NULL,
            `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `udx_file_key` (`file_key`(191)),
            KEY `idx_entity_type_id` (`entity_type`, `entity_id`)
        ) {$charset}";

        // lt_shipping_quotes_cache — Caches shipping quotes per provider
        $sqls[] = "CREATE TABLE `{$p}lt_shipping_quotes_cache` (
            `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `cache_key`   CHAR(64) NOT NULL,
            `provider`    VARCHAR(50) NOT NULL,
            `quote_data`  LONGTEXT NOT NULL,
            `expires_at`  DATETIME NOT NULL,
            `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `udx_cache_key_provider` (`cache_key`, `provider`),
            KEY `idx_expires_at` (`expires_at`)
        ) {$charset}";

        // lt_insurance_policies — XCover insurance policy records
        $sqls[] = "CREATE TABLE `{$p}lt_insurance_policies` (
            `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `order_id`         BIGINT UNSIGNED NOT NULL,
            `vendor_id`        BIGINT UNSIGNED NOT NULL,
            `quote_id`         VARCHAR(255) NOT NULL,
            `policy_id`        VARCHAR(255) NOT NULL,
            `policy_number`    VARCHAR(255) DEFAULT NULL,
            `certificate_url`  VARCHAR(1000) DEFAULT NULL,
            `insurance_type`   ENUM('parcel_protection','purchase_protection','other') NOT NULL DEFAULT 'parcel_protection',
            `premium_amount`   DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `currency`         CHAR(3) NOT NULL DEFAULT 'COP',
            `status`           ENUM('active','cancelled','claimed','expired') NOT NULL DEFAULT 'active',
            `cancellation_ref` VARCHAR(255) DEFAULT NULL,
            `cancelled_at`     DATETIME DEFAULT NULL,
            `cancel_reason`    VARCHAR(500) DEFAULT NULL,
            `refund_amount`    DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `metadata`         LONGTEXT DEFAULT NULL,
            `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `udx_policy_id` (`policy_id`(191)),
            KEY `idx_order_id` (`order_id`),
            KEY `idx_vendor_id` (`vendor_id`),
            KEY `idx_status` (`status`),
            KEY `idx_created_at` (`created_at`)
        ) {$charset}";

        // lt_redi_agreements — ReDi reseller-origin product agreements
        $sqls[] = "CREATE TABLE `{$p}lt_redi_agreements` (
            `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `origin_vendor_id`    BIGINT UNSIGNED NOT NULL,
            `reseller_vendor_id`  BIGINT UNSIGNED NOT NULL,
            `origin_product_id`   BIGINT UNSIGNED NOT NULL,
            `reseller_product_id` BIGINT UNSIGNED DEFAULT NULL,
            `redi_rate`           DECIMAL(5,4) NOT NULL,
            `status`              ENUM('active','paused','revoked') NOT NULL DEFAULT 'active',
            `revoked_at`          DATETIME DEFAULT NULL,
            `revocation_reason`   VARCHAR(500) DEFAULT NULL,
            `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `udx_reseller_origin_product` (`reseller_vendor_id`, `origin_product_id`),
            KEY `idx_origin_vendor_id` (`origin_vendor_id`),
            KEY `idx_reseller_vendor_id` (`reseller_vendor_id`),
            KEY `idx_origin_product_id` (`origin_product_id`),
            KEY `idx_status` (`status`)
        ) {$charset}";

        // lt_redi_commissions — Per-item ReDi commission ledger
        $sqls[] = "CREATE TABLE `{$p}lt_redi_commissions` (
            `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `agreement_id`         BIGINT UNSIGNED NOT NULL,
            `order_id`             BIGINT UNSIGNED NOT NULL,
            `order_item_id`        BIGINT UNSIGNED DEFAULT NULL,
            `origin_vendor_id`     BIGINT UNSIGNED NOT NULL,
            `reseller_vendor_id`   BIGINT UNSIGNED NOT NULL,
            `gross_amount`         DECIMAL(15,2) NOT NULL,
            `platform_fee`         DECIMAL(15,2) NOT NULL,
            `reseller_commission`  DECIMAL(15,2) NOT NULL,
            `origin_vendor_gross`  DECIMAL(15,2) NOT NULL,
            `tax_withholding`      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `origin_vendor_net`    DECIMAL(15,2) NOT NULL,
            `redi_rate`            DECIMAL(5,4) NOT NULL,
            `currency`             CHAR(3) NOT NULL DEFAULT 'COP',
            `status`               ENUM('paid','reversed','disputed') NOT NULL DEFAULT 'paid',
            `origin_tx_id`         BIGINT UNSIGNED DEFAULT NULL,
            `reseller_tx_id`       BIGINT UNSIGNED DEFAULT NULL,
            `metadata`             LONGTEXT DEFAULT NULL,
            `created_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_order_id` (`order_id`),
            KEY `idx_origin_vendor_id` (`origin_vendor_id`),
            KEY `idx_reseller_vendor_id` (`reseller_vendor_id`),
            KEY `idx_agreement_id` (`agreement_id`),
            KEY `idx_status` (`status`)
        ) {$charset}";

        // AUDIT-REDI-UX-GAPS GAP-9 FIX: lt_redi_incidents — cabecera de incidencias ReDi.
        // Una incidencia es una "novedad" reportada por el vendedor origen o el
        // revendedor sobre un pedido que contiene productos ReDi. Tiene SLA de
        // primera respuesta (48h) y resolución (15d), controlados por cron hourly.
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_redi_incidents` (
            `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `order_id`            BIGINT UNSIGNED NOT NULL,
            `origin_vendor_id`    BIGINT UNSIGNED NOT NULL,
            `reseller_vendor_id`  BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `customer_id`         BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `type`                ENUM('stockout','complaint','quality','shipping','payment','other') NOT NULL DEFAULT 'other',
            `description`         TEXT NOT NULL,
            `status`              ENUM('open','investigating','escalated','resolved','closed') NOT NULL DEFAULT 'open',
            `sla_due_at`          DATETIME NOT NULL,
            `resolution_due_at`   DATETIME NOT NULL,
            `resolution_notes`    TEXT DEFAULT NULL,
            `created_by`          BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `resolved_at`         DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_incident_order_id` (`order_id`),
            KEY `idx_incident_origin_vendor_id` (`origin_vendor_id`),
            KEY `idx_incident_reseller_vendor_id` (`reseller_vendor_id`),
            KEY `idx_incident_status` (`status`),
            KEY `idx_incident_sla_due_at` (`sla_due_at`),
            KEY `idx_incident_resolution_due_at` (`resolution_due_at`)
        ) {$charset}";

        // AUDIT-REDI-UX-GAPS GAP-9 FIX: lt_redi_incident_comments — hilo de
        // comentarios de cada incidencia (autor + texto + timestamp).
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_redi_incident_comments` (
            `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `incident_id` BIGINT UNSIGNED NOT NULL,
            `user_id`     BIGINT UNSIGNED NOT NULL,
            `comment`     TEXT NOT NULL,
            `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_incident_comment_incident_id` (`incident_id`),
            KEY `idx_incident_comment_user_id` (`user_id`)
        ) {$charset}";

        // ── v1.7.0 Tables ────────────────────────────────────────────

        // lt_provider_health — Circuit breaker & uptime monitoring
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_provider_health` (
            `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `provider`    VARCHAR(50)  NOT NULL,
            `status`      ENUM('success','error','timeout') NOT NULL,
            `latency_ms`  INT UNSIGNED NOT NULL DEFAULT 0,
            `error_code`  VARCHAR(100) NOT NULL DEFAULT '',
            `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_provider_created` (`provider`, `created_at`)
        ) {$charset}";

        // lt_commission_tiers — Tiers de volumen configurables desde admin
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_commission_tiers` (
            `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `country`    CHAR(2)          NOT NULL,
            `min_amount` DECIMAL(20,2)    NOT NULL DEFAULT 0,
            `max_amount` DECIMAL(20,2)    NOT NULL DEFAULT 999999999.99,
            `rate`       DECIMAL(5,4)     NOT NULL,
            `label`      VARCHAR(100)     NOT NULL,
            `currency`   CHAR(3)          NOT NULL,
            `is_active`  TINYINT(1)       NOT NULL DEFAULT 1,
            `sort_order` INT UNSIGNED     NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_country_active` (`country`, `is_active`, `sort_order`)
        ) {$charset}";

        // lt_tax_rates_history — Auditoría de cambios de tasas tributarias
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_tax_rates_history` (
            `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `country`          CHAR(2)       NOT NULL,
            `rate_key`         VARCHAR(100)  NOT NULL,
            `old_value`        DECIMAL(15,6) NOT NULL DEFAULT 0,
            `new_value`        DECIMAL(15,6) NOT NULL,
            `decree_reference` VARCHAR(200)  DEFAULT NULL,
            `notes`            TEXT          DEFAULT NULL,
            `changed_by`       BIGINT UNSIGNED DEFAULT NULL,
            `valid_from`       DATE          NOT NULL,
            `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_country_key` (`country`, `rate_key`, `valid_from`)
        ) {$charset}";

        // lt_mx_ieps_rates — Tasas IEPS México por categoría (editables)
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_mx_ieps_rates` (
            `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `category`   VARCHAR(100) NOT NULL,
            `rate`       DECIMAL(8,4) NOT NULL,
            `unit`       VARCHAR(20)  NOT NULL DEFAULT 'percent',
            `valid_from` DATE         NOT NULL,
            `notes`      VARCHAR(200) DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) {$charset}";

        // lt_mx_isr_tramos — Tramos ISR México Art. 113-A (editables)
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_mx_isr_tramos` (
            `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `min_amount` DECIMAL(15,2) NOT NULL,
            `max_amount` DECIMAL(15,2) NOT NULL,
            `rate`       DECIMAL(5,4)  NOT NULL,
            `valid_from` DATE          NOT NULL,
            PRIMARY KEY (`id`)
        ) {$charset}";

        // lt_co_reteica_rates — ReteICA Colombia por CIIU (editable)
        // DEPRECATED desde v2.1.x: usar lt_co_reteica_rates_municipal (soporta municipio + CIIU completo).
        // Se mantiene para back-compat de instalaciones que tengan datos manuales.
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_co_reteica_rates` (
            `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `ciiu_prefix`       CHAR(1)      NOT NULL,
            `description`       VARCHAR(200) NOT NULL,
            `rate_per_thousand` DECIMAL(8,4) NOT NULL,
            `valid_from`        DATE         NOT NULL,
            PRIMARY KEY (`id`)
        ) {$charset}";

        // lt_co_dane_municipalities — Catálogo DANE de municipios Colombia.
        // Fuente de verdad para dropdowns (checkout, registro vendedor) y lookups fiscales.
        // Códigos DANE de 5 dígitos: 2 departamento + 3 municipio.
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_co_dane_municipalities` (
            `code`              VARCHAR(8)   NOT NULL,
            `department_code`   VARCHAR(2)   NOT NULL,
            `department_name`   VARCHAR(80)  NOT NULL,
            `municipality_name` VARCHAR(120) NOT NULL,
            `is_active`         TINYINT(1)   NOT NULL DEFAULT 1,
            PRIMARY KEY (`code`),
            KEY `idx_active` (`is_active`),
            KEY `idx_name` (`municipality_name`)
        ) {$charset}";

        // lt_aveonline_cities — Catálogo de ciudades de Aveonline sincronizado desde
        // https://app.aveonline.co/assets/resources/public/listadociudades.json
        // nombre = formato "BOGOTA(CUNDINAMARCA)" usado en la API. codigodane = 8 dígitos Aveonline.
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_aveonline_cities` (
            `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `nombre`       VARCHAR(160) NOT NULL,
            `codigodane`   VARCHAR(12)  NOT NULL DEFAULT \'\',
            `departamento` VARCHAR(80)  NOT NULL DEFAULT \'\',
            `nombremun`    VARCHAR(120) NOT NULL DEFAULT \'\',
            `synced_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_nombre` (`nombre`),
            KEY `idx_codigodane` (`codigodane`),
            KEY `idx_departamento` (`departamento`)
        ) {$charset}";


        // lt_co_reteica_rates_municipal — ReteICA por (municipio DANE + CIIU completo).
        // Reemplaza a lt_co_reteica_rates. rate_per_thousand expresada en por mil (4.1400 = 0.414%).
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_co_reteica_rates_municipal` (
            `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `municipality_code` VARCHAR(8)   NOT NULL,
            `ciiu_code`         VARCHAR(10)  NOT NULL,
            `description`       VARCHAR(200) DEFAULT NULL,
            `rate_per_thousand` DECIMAL(8,4) NOT NULL,
            `legal_reference`   VARCHAR(200) DEFAULT NULL,
            `valid_from`        DATE         NOT NULL,
            `valid_to`          DATE         DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_lookup` (`municipality_code`, `ciiu_code`, `valid_from`),
            KEY `idx_municipality` (`municipality_code`)
        ) {$charset}";

        // ── v2.0.0 Tables — Módulo Booking ──────────────────────────

        // lt_bookings — Reservas principales
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_bookings` (
            `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `wc_order_id`          BIGINT UNSIGNED NOT NULL,
            `deposit_wc_order_id`  BIGINT UNSIGNED DEFAULT NULL,
            `product_id`           BIGINT UNSIGNED NOT NULL,
            `vendor_id`            BIGINT UNSIGNED NOT NULL,
            `customer_id`          BIGINT UNSIGNED NOT NULL,
            `booking_type`         VARCHAR(30)   NOT NULL DEFAULT 'accommodation',
            `checkin_date`         DATE          NOT NULL,
            `checkout_date`        DATE          NOT NULL,
            `checkin_time`         TIME          DEFAULT NULL,
            `checkout_time`        TIME          DEFAULT NULL,
            `guests`               TINYINT UNSIGNED DEFAULT 1,
            `total_price`          DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `deposit_amount`       DECIMAL(15,2) DEFAULT 0.00,
            `balance_amount`       DECIMAL(15,2) DEFAULT 0.00,
            `vendor_net`           DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Neto al vendedor tras comisión',
            `currency`             VARCHAR(3)    NOT NULL DEFAULT 'COP',
            `payment_mode`         VARCHAR(20)   NOT NULL DEFAULT 'full',
            `status`               VARCHAR(20)   NOT NULL DEFAULT 'pending',
            `cancellation_reason`  TEXT          DEFAULT NULL,
            `cancelled_by`         VARCHAR(50)   DEFAULT NULL COMMENT 'system | customer | vendor | admin',
            `cancel_notes`         TEXT          DEFAULT NULL COMMENT 'Notas internas de cancelación',
            `instant_booking`      TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1 = confirmación automática sin aprobación del vendedor',
            `insurance_quote_id`   VARCHAR(255)  DEFAULT NULL COMMENT 'ID de cotización de seguro (xCover/similar)',
            `zapsign_doc_token`    VARCHAR(255)  DEFAULT NULL,
            `xcover_policy_id`     VARCHAR(255)  DEFAULT NULL,
            `rnt_number`           VARCHAR(50)   DEFAULT NULL,
            `sectur_folio`         VARCHAR(100)  DEFAULT NULL,
            `notes`                TEXT          DEFAULT NULL,
            `vendor_notes`         TEXT          DEFAULT NULL,
            `ip_address`           VARCHAR(45)   DEFAULT NULL,
            `check_in_at`          DATETIME      DEFAULT NULL COMMENT 'AUDIT-BOOKING-ENGINE #11: timestamp de check-in',
            `check_out_at`         DATETIME      DEFAULT NULL COMMENT 'AUDIT-BOOKING-ENGINE #11: timestamp de check-out',
            `completed_at`         DATETIME      DEFAULT NULL COMMENT 'AUDIT-BOOKING-ENGINE #11: timestamp de completado',
            `created_at`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_wc_order` (`wc_order_id`),
            KEY `idx_product`  (`product_id`),
            KEY `idx_vendor`   (`vendor_id`),
            KEY `idx_customer` (`customer_id`),
            KEY `idx_status`   (`status`),
            KEY `idx_checkin`  (`checkin_date`),
            KEY `idx_checkout` (`checkout_date`),
            KEY `idx_type`     (`booking_type`)
        ) {$charset}";

        // lt_booking_slots — Disponibilidad por fecha/slot
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_booking_slots` (
            `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `product_id`   BIGINT UNSIGNED NOT NULL,
            `slot_date`    DATE            NOT NULL,
            `slot_time`    TIME            DEFAULT NULL,
            `capacity`     SMALLINT UNSIGNED DEFAULT 1,
            `booked`       SMALLINT UNSIGNED DEFAULT 0,
            `is_blocked`   TINYINT(1)      DEFAULT 0,
            `block_reason` VARCHAR(255)    DEFAULT NULL,
            `base_price`   DECIMAL(15,2)   DEFAULT NULL,
            `created_at`   DATETIME        DEFAULT CURRENT_TIMESTAMP,
            `updated_at`   DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_product_date_time` (`product_id`, `slot_date`, `slot_time`),
            KEY `idx_date`  (`slot_date`),
            KEY `idx_avail` (`product_id`, `slot_date`, `is_blocked`, `booked`, `capacity`)
        ) {$charset}";

        // lt_booking_policies — Políticas de cancelación por vendedor
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_booking_policies` (
            `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `vendor_id`             BIGINT UNSIGNED NOT NULL,
            `name`                  VARCHAR(100)    NOT NULL,
            `policy_type`           VARCHAR(20)     NOT NULL DEFAULT 'flexible',
            `free_cancel_hours`     SMALLINT UNSIGNED DEFAULT 24,
            `partial_refund_pct`    TINYINT UNSIGNED  DEFAULT 50,
            `partial_refund_hours`  SMALLINT UNSIGNED DEFAULT 0,
            `no_refund_hours`       SMALLINT UNSIGNED DEFAULT 0,
            `non_refundable_pct`    TINYINT UNSIGNED  DEFAULT 0 COMMENT 'Porcentaje no reembolsable al cancelar',
            `deposit_pct`           TINYINT UNSIGNED  DEFAULT 0,
            `deposit_deadline_days` TINYINT UNSIGNED  DEFAULT 0,
            `force_majeure_enabled` TINYINT(1)        DEFAULT 1,
            `force_majeure_docs`    TEXT              DEFAULT NULL,
            `is_default`            TINYINT(1)        DEFAULT 0,
            `created_at`            DATETIME          DEFAULT CURRENT_TIMESTAMP,
            `updated_at`            DATETIME          DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_vendor`  (`vendor_id`),
            KEY `idx_type`    (`policy_type`),
            KEY `idx_default` (`vendor_id`, `is_default`)
        ) {$charset}";

        // lt_tourism_compliance — RNT Colombia + SECTUR México por vendedor
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_tourism_compliance` (
            `id`                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `vendor_id`                   BIGINT UNSIGNED NOT NULL,
            `country_code`                VARCHAR(3)   NOT NULL DEFAULT 'CO',
            `rnt_number`                  VARCHAR(50)  DEFAULT NULL,
            `rnt_category`                VARCHAR(100) DEFAULT NULL,
            `rnt_expiry_date`             DATE         DEFAULT NULL,
            `rnt_doc_b2_key`              VARCHAR(500) DEFAULT NULL,
            `rnt_verified`                TINYINT(1)   DEFAULT 0,
            `rnt_verified_at`             DATETIME     DEFAULT NULL,
            `rnt_verified_by`             BIGINT UNSIGNED DEFAULT NULL,
            `rnt_rejection_reason`        TEXT         DEFAULT NULL,
            `sworn_declaration_signed`    TINYINT(1)   DEFAULT 0,
            `sworn_declaration_signed_at` DATETIME     DEFAULT NULL,
            `sworn_declaration_ip`        VARCHAR(45)  DEFAULT NULL,
            `rfc`                         VARCHAR(13)  DEFAULT NULL,
            `sectur_folio`                VARCHAR(100) DEFAULT NULL,
            `sectur_category`             VARCHAR(100) DEFAULT NULL,
            `sectur_expiry_date`          DATE         DEFAULT NULL,
            `sectur_doc_b2_key`           VARCHAR(500) DEFAULT NULL,
            `sectur_verified`             TINYINT(1)   DEFAULT 0,
            `status`                      VARCHAR(20)  NOT NULL DEFAULT 'pending',
            `admin_notes`                 TEXT         DEFAULT NULL,
            `created_at`                  DATETIME     DEFAULT CURRENT_TIMESTAMP,
            `updated_at`                  DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `udx_vendor_id` (`vendor_id`),
            PRIMARY KEY (`id`),
            KEY `idx_country` (`country_code`),
            KEY `idx_status`  (`status`),
            KEY `idx_rnt`     (`rnt_number`),
            KEY `idx_rnt_exp` (`rnt_expiry_date`)
        ) {$charset}";

        // lt_booking_season_rules — Modificadores de precio por temporada
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_booking_season_rules` (
            `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `product_id`     BIGINT UNSIGNED NOT NULL,
            `season_name`    VARCHAR(100)    NOT NULL COMMENT 'Nombre de la temporada',
            `season_type`    VARCHAR(20)     NOT NULL DEFAULT 'custom',
            `country_code`   VARCHAR(3)      DEFAULT NULL,
            `date_from`      DATE            NOT NULL,
            `date_to`        DATE            NOT NULL,
            `price_modifier` DECIMAL(8,4)    NOT NULL DEFAULT 1.0000,
            `min_nights`     TINYINT UNSIGNED DEFAULT 1,
            `is_active`      TINYINT(1)      DEFAULT 1,
            `created_at`     DATETIME        DEFAULT CURRENT_TIMESTAMP,
            `updated_at`     DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_product` (`product_id`),
            KEY `idx_dates`   (`date_from`, `date_to`),
            KEY `idx_country` (`country_code`)
        ) {$charset}";

        // lt_legal_snapshots — Evidencia inmutable de aceptación de T&C / contratos legales
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_legal_snapshots` (
            `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id`       BIGINT UNSIGNED NOT NULL,
            `order_id`      BIGINT UNSIGNED DEFAULT NULL,
            `document_type` VARCHAR(60)     NOT NULL DEFAULT 'terms',
            `document_hash` VARCHAR(64)     NOT NULL COMMENT 'SHA-256 del contenido del documento',
            `ip_address`    VARCHAR(45)     DEFAULT NULL,
            `user_agent`    VARCHAR(512)    DEFAULT NULL,
            `accepted_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user`     (`user_id`),
            KEY `idx_order`    (`order_id`),
            KEY `idx_doc_type` (`document_type`)
        ) {$charset}";

        // lt_order_snapshots — Evidencia legal inmutable de snapshots de pedidos (legal-evidence-handler)
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_order_snapshots` (
            `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `order_id`       BIGINT UNSIGNED NOT NULL,
            `status`         VARCHAR(50)     NOT NULL DEFAULT '',
            `total`          DECIMAL(15,2)   NOT NULL DEFAULT 0.00,
            `vendor_id`      BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `customer_email` VARCHAR(255)    DEFAULT NULL,
            `items_json`     LONGTEXT        DEFAULT NULL,
            `meta_json`      LONGTEXT        DEFAULT NULL,
            `trigger`        VARCHAR(100)    NOT NULL DEFAULT '',
            `actor_id`       BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `ip_address`     VARCHAR(45)     DEFAULT NULL,
            `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_order_id`  (`order_id`),
            KEY `idx_vendor_id` (`vendor_id`),
            KEY `idx_trigger`   (`trigger`),
            KEY `idx_created_at`(`created_at`)
        ) {$charset}";

        // DEPRECATED F-01 (2026-05-29): lt_wallet_ledger era un alias de lt_wallet_transactions.
        // La tabla fue renombrada a bkr_lt_DEPRECATED_wallet_ledger en BD.
        // El ledger canónico es lt_wallet_transactions (class-ltms-wallet.php).
        // $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_wallet_ledger` ..."; // DEPRECATED

        // lt_wallet_holds — Saldos retenidos de la billetera (consumer protection / vesting)
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_wallet_holds` (
            `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `vendor_id`      BIGINT UNSIGNED NOT NULL,
            `amount`         DECIMAL(15,2)   NOT NULL,
            `order_id`       BIGINT UNSIGNED DEFAULT NULL COMMENT 'Pedido WooCommerce asociado',
            `reason`         VARCHAR(255)    NOT NULL DEFAULT '',
            `freeze_reason`  VARCHAR(255)    DEFAULT NULL COMMENT 'Razón de congelamiento por disputa',
            `status`         VARCHAR(20)     NOT NULL DEFAULT 'held' COMMENT 'held | released | frozen | cancelled',
            `release_at`     DATETIME        DEFAULT NULL COMMENT 'Fecha programada de liberación',
            `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `released_at`    DATETIME        DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_vendor`     (`vendor_id`),
            KEY `idx_status`     (`status`),
            KEY `idx_release_at` (`release_at`),
            KEY `idx_order_id`   (`order_id`)
        ) {$charset}";

        // ── v2.7.0 Tables — Donaciones Fundación Cardio Infantil ────────────────
        // lt_donations — Registro por orden de la donación calculada automáticamente.
        // El motor de donaciones (class-ltms-donation-engine.php) inserta una fila
        // por cada orden completada cuando ltms_donation_enabled='yes'. El monto
        // se calcula sobre la base seleccionada (platform_fee | order_total |
        // vendor_net | platform_profit) y se redondea según ltms_donation_rounding.
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_donations` (
            `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `order_id`              BIGINT UNSIGNED NOT NULL,
            `vendor_id`             BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `customer_id`           BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `basis_amount`          DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Monto base usado para el cálculo (ej: comisión de la plataforma)',
            `basis_type`            VARCHAR(32)  NOT NULL DEFAULT 'platform_fee' COMMENT 'platform_fee | order_total | vendor_net | platform_profit',
            `donation_percentage`   DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Porcentaje aplicado (0-100)',
            `donation_amount`       DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Donación calculada = basis_amount * percentage / 100',
            `currency`              VARCHAR(3)   NOT NULL DEFAULT 'COP',
            `customer_extra_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Donación extra del cliente (opt-in checkout)',
            `total_donation`        DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'donation_amount + customer_extra_amount',
            `status`                VARCHAR(32)  NOT NULL DEFAULT 'pending' COMMENT 'pending | credited | failed | processing | paid | reversed',
            `payout_batch_id`       BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'FK a lt_donation_payouts.id (0 = aún no asignada)',
            `alegra_entry_id`       BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'ID del asiento contable en Alegra (0 = no sincronizado)',
            `certificate_id`        VARCHAR(64)  NOT NULL DEFAULT '' COMMENT 'ID del certificado de donación generado',
            `created_at`            DATETIME     NOT NULL,
            `paid_at`               DATETIME     DEFAULT NULL,
            `metadata`              TEXT         DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_order` (`order_id`),
            KEY `idx_order`         (`order_id`),
            KEY `idx_vendor`        (`vendor_id`),
            KEY `idx_status`        (`status`),
            KEY `idx_payout_batch`  (`payout_batch_id`),
            KEY `idx_created_at`    (`created_at`)
        ) {$charset}";

        // lt_donation_payouts — Lotes de transferencia a la fundación.
        // El cron de pagos agrupa donaciones por período (weekly/monthly/quarterly)
        // y crea un batch con la suma total. El admin marca el batch como
        // 'transferred' al confirmar la transferencia bancaria.
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_donation_payouts` (
            `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `batch_number`        VARCHAR(32)  NOT NULL COMMENT 'Identificador legible (ej: DON-2026-001)',
            `period_start`        DATE         NOT NULL,
            `period_end`          DATE         NOT NULL,
            `total_amount`        DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Suma de total_donation de todas las donaciones del lote',
            `currency`            VARCHAR(3)   NOT NULL DEFAULT 'COP',
            `transaction_count`   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Número de donaciones incluidas',
            `status`              VARCHAR(32)  NOT NULL DEFAULT 'pending' COMMENT 'pending | transferred | failed | cancelled',
            `transfer_reference`  VARCHAR(128) NOT NULL DEFAULT '' COMMENT 'Referencia bancaria de la transferencia',
            `transfer_method`     VARCHAR(32)  NOT NULL DEFAULT '' COMMENT 'bank_transfer | pse | nequi | daviplata',
            `alegra_entry_id`     BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `certificate_path`    VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Ruta al PDF del certificado mensual',
            `created_by`          BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `created_at`          DATETIME     NOT NULL,
            `transferred_at`      DATETIME     DEFAULT NULL,
            `metadata`            TEXT         DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_batch` (`batch_number`),
            KEY `idx_status`       (`status`),
            KEY `idx_period`       (`period_start`, `period_end`)
        ) {$charset}";

        // ── v2.8.0 Tables — Cross-Border Commerce ─────────────────────────────
        // lt_fx_rates — Tasas de cambio cacheadas + overrides manuales.
        // El provider (LTMS_FX_Rate_Provider) usa transients para caché en memoria,
        // pero esta tabla es el source-of-truth persistente: almacena la última tasa
        // conocida por par, el provider que la devolvió, si es override manual y
        // el timestamp de fetch. El admin panel (html-admin-cross-border.php) la
        // consulta directamente para mostrar la matriz de tasas en el dashboard.
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_fx_rates` (
            `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `base_currency`  VARCHAR(3) NOT NULL,
            `quote_currency` VARCHAR(3) NOT NULL,
            `rate`           DECIMAL(12,6) NOT NULL,
            `provider`       VARCHAR(32) NOT NULL DEFAULT 'frankfurter',
            `is_manual`      TINYINT(1) NOT NULL DEFAULT 0,
            `fetched_at`     DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_pair` (`base_currency`, `quote_currency`),
            KEY `idx_fetched` (`fetched_at`)
        ) {$charset}";

        // lt_customs_declarations — Declaraciones aduaneras por orden.
        // Una fila por cada orden cross-border con origen ≠ destino. Guarda el
        // desglose completo de costos aduaneros (arancel, IVA, honorarios broker,
        // otros impuestos), el incoterm aplicado, quién paga (buyer|marketplace),
        // la moneda original, el número de declaración (si existe) y el estado
        // del despacho (pending|in_transit|cleared|rejected|reversed).
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_customs_declarations` (
            `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `order_id`            BIGINT UNSIGNED NOT NULL,
            `origin_country`      VARCHAR(2) NOT NULL,
            `destination_country` VARCHAR(2) NOT NULL,
            `hs_code`             VARCHAR(16) NOT NULL DEFAULT '',
            `cif_value`           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `duty_rate`           DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            `duty_amount`         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `vat_rate`            DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            `vat_amount`          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `customs_fee`         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `other_taxes`         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `total_duties`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `incoterm`            VARCHAR(8) NOT NULL DEFAULT 'DDU',
            `paid_by`             VARCHAR(16) NOT NULL DEFAULT 'buyer',
            `currency`            VARCHAR(3) NOT NULL DEFAULT 'USD',
            `declaration_number`  VARCHAR(64) NOT NULL DEFAULT '',
            `declaration_status`  VARCHAR(32) NOT NULL DEFAULT 'pending',
            `created_at`          DATETIME NOT NULL,
            `cleared_at`          DATETIME DEFAULT NULL,
            `metadata`            TEXT,
            PRIMARY KEY (`id`),
            KEY `idx_order` (`order_id`),
            KEY `idx_route` (`origin_country`, `destination_country`),
            KEY `idx_status` (`declaration_status`),
            KEY `idx_created_at` (`created_at`)
        ) {$charset}";

        // lt_logs — Tabla de trazabilidad forense ligera.
        // M-1 FIX: esta tabla se creaba únicamente vía deploy/ltms-patch-db-v2-7-0.php
        // (un parche one-shot que los sites nuevos o restaurados nunca corrían),
        // por lo que instalaciones frescas y migraciones posteriores no la creaban.
        // Al no estar en migrations.php, cualquier código que escriba en lt_logs
        // (cron retention, commission-writer, kyc backfill, etc.) fallaba en
        // silencio. La agregamos aquí como CREATE TABLE IF NOT EXISTS idempotente:
        // dbDelta compara el esquema existente y solo AÑADE columnas/keys que falten
        // (nunca elimina columnas existentes), por lo que los sites que ya tienen
        // la versión con `event_type`/`object_id`/`object_type`/`user_id` del
        // patch v2-7-0 conservan esas columnas y reciben las nuevas (`level`,
        // `source`, `context`) sin pérdida de datos.
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_logs` (
            `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `level`      VARCHAR(20)  NOT NULL DEFAULT 'INFO',
            `source`     VARCHAR(100) NOT NULL DEFAULT '',
            `message`    TEXT,
            `context`    LONGTEXT,
            `created_at` DATETIME     NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_level` (`level`),
            KEY `idx_created` (`created_at`)
        ) {$charset}";

        foreach ( $sqls as $sql ) {
            dbDelta( $sql );
        }

        // Insert default commission tiers if table is empty
        self::seed_commission_tiers();

        // v2.0.0: Insert booking season seed data
        self::seed_booking_seasons();

        // v2.1.x: Catálogo DANE + tarifas ReteICA municipales (cumplimiento territorialidad)
        self::seed_dane_municipalities();
        self::seed_reteica_municipal_rates();

        // M-200: migra user_meta `ltms_municipality` de slug legacy ('bogota') a código DANE ('11001')
        // para vendedores registrados antes del dropdown DANE. One-shot, idempotente.
        self::migrate_vendor_municipality_slugs_to_dane();
    }

    /**
     * Seeds default commission tiers after table creation.
     */
    private static function seed_commission_tiers(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_commission_tiers';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a trusted constant string
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
        if ( $count > 0 ) {
            return;
        }
        $defaults = [
            [ 'CO', 0,          4999999.99,  0.12, 'Tier 1 — Inicio',     'COP', 1 ],
            [ 'CO', 5000000,   19999999.99,  0.10, 'Tier 2 — Creciente',  'COP', 2 ],
            [ 'CO', 20000000,  49999999.99,  0.08, 'Tier 3 — Establecido','COP', 3 ],
            [ 'CO', 50000000, 999999999.99,  0.06, 'Tier 4 — Platinum',   'COP', 4 ],
            [ 'MX', 0,            24999.99,  0.12, 'Tier 1 — Inicio',     'MXN', 1 ],
            [ 'MX', 25000,        99999.99,  0.08, 'Tier 2 — Creciente',  'MXN', 2 ],
            [ 'MX', 100000,      299999.99,  0.06, 'Tier 3 — Establecido','MXN', 3 ],
            [ 'MX', 300000,   999999999.99,  0.04, 'Tier 4 — Platinum',   'MXN', 4 ],
        ];
        foreach ( $defaults as $row ) {
            $wpdb->insert( $table, [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                'country'    => $row[0],
                'min_amount' => $row[1],
                'max_amount' => $row[2],
                'rate'       => $row[3],
                'label'      => $row[4],
                'currency'   => $row[5],
                'sort_order' => $row[6],
            ], [ '%s', '%f', '%f', '%f', '%s', '%s', '%d' ] );
        }
    }

    /**
     * Inserta datos semilla de temporadas CO y MX (product_id=0 = regla global).
     * Solo inserta si la tabla está vacía.
     *
     * @return void
     */
    private static function seed_booking_seasons(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_booking_season_rules';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
        if ( $count > 0 ) {
            return;
        }

        $year      = (int) gmdate( 'Y' );
        $next_year = $year + 1;

        $seeds = [
            // Colombia
            [ 0, 'Mitad de año CO',   'high',   'CO', "{$year}-06-25", "{$year}-07-15", 1.3000, 3 ],
            [ 0, 'Fin de año CO',     'high',   'CO', "{$year}-12-15", "{$next_year}-01-15", 1.5000, 2 ],
            [ 0, 'Feria de Cali',     'high',   'CO', "{$year}-12-25", "{$year}-12-31", 1.4000, 2 ],
            // México
            [ 0, 'Verano MX',         'high',   'MX', "{$year}-07-01", "{$year}-08-31", 1.2500, 2 ],
            [ 0, 'Día de Muertos MX', 'high',   'MX', "{$year}-10-28", "{$year}-11-03", 1.3000, 2 ],
            [ 0, 'Navidad/AñoNuevo MX','high',  'MX', "{$year}-12-20", "{$next_year}-01-06", 1.5000, 2 ],
            [ 0, 'Temporada baja MX', 'low',    'MX', "{$year}-02-01", "{$year}-03-31", 0.8500, 1 ],
        ];

        foreach ( $seeds as $seed ) {
            $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $table,
                [
                    'product_id'     => $seed[0],
                    'season_name'    => $seed[1],
                    'season_type'    => $seed[2],
                    'country_code'   => $seed[3],
                    'date_from'      => $seed[4],
                    'date_to'        => $seed[5],
                    'price_modifier' => $seed[6],
                    'min_nights'     => $seed[7],
                    'is_active'      => 1,
                ],
                [ '%d', '%s', '%s', '%s', '%s', '%s', '%f', '%d', '%d' ]
            );
        }

        // ── v2.1.0 Tables — M-122 FIX: tablas usadas en el código pero ausentes en migraciones ──

        // lt_booking_policies — Políticas de cancelación y depósito por producto
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_booking_policies` (
            `id`                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `product_id`             BIGINT UNSIGNED NOT NULL,
            `cancellation_policy`    ENUM('flexible','moderate','strict','non_refundable') NOT NULL DEFAULT 'moderate',
            `deposit_required`       TINYINT(1) NOT NULL DEFAULT 0,
            `deposit_percentage`     DECIMAL(5,2) NOT NULL DEFAULT 30.00,
            `min_nights`             TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `max_nights`             SMALLINT UNSIGNED DEFAULT NULL,
            `advance_booking_days`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `created_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `udx_product` (`product_id`)
        ) {$charset}";

        // lt_booking_season_rules — Reglas de temporada por producto (si no existe ya)
        if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'lt_booking_season_rules' ) ) ) {
            $sqls[] = "CREATE TABLE `{$p}lt_booking_season_rules` (
                `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `product_id`     BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = regla global',
                `name`           VARCHAR(120) NOT NULL,
                `season_type`    ENUM('low','medium','high','very_high') NOT NULL DEFAULT 'medium',
                `country_code`   CHAR(2) NOT NULL DEFAULT 'CO',
                `date_from`      DATE NOT NULL,
                `date_to`        DATE NOT NULL,
                `price_modifier` DECIMAL(8,4) NOT NULL DEFAULT 1.0000,
                `min_nights`     TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
                `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_product_season` (`product_id`, `date_from`, `date_to`)
            ) {$charset}";
        }

        // lt_commission_tiers — Escalonado de comisiones por nivel de ventas
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_commission_tiers` (
            `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`            VARCHAR(120) NOT NULL,
            `min_gmv`         DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'GMV mínimo para este tier',
            `commission_rate` DECIMAL(5,4) NOT NULL DEFAULT 0.1500 COMMENT 'Tasa de comisión (0.15 = 15%)',
            `country_code`    CHAR(2) NOT NULL DEFAULT 'CO',
            `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
            `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_country_gmv` (`country_code`, `min_gmv`)
        ) {$charset}";

        // lt_mx_ieps_rates — Tasas IEPS para México (Impuesto Especial)
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_mx_ieps_rates` (
            `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `category`     VARCHAR(120) NOT NULL,
            `rate`         DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
            `is_active`    TINYINT(1) NOT NULL DEFAULT 1,
            `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) {$charset}";

        // lt_mx_isr_tramos — Tramos ISR para retención en México
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_mx_isr_tramos` (
            `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `lower_limit`     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `upper_limit`     DECIMAL(15,2) DEFAULT NULL COMMENT 'NULL = sin límite superior',
            `fixed_fee`       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `rate_excess`     DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
            `year`            SMALLINT UNSIGNED NOT NULL DEFAULT 2024,
            `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_year_lower` (`year`, `lower_limit`)
        ) {$charset}";

        // lt_order_snapshots — Snapshot del pedido en el momento del pago (auditoría fiscal)
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_order_snapshots` (
            `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `order_id`    BIGINT UNSIGNED NOT NULL,
            `snapshot`    LONGTEXT NOT NULL COMMENT 'JSON serializado del WC_Order',
            `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `udx_order` (`order_id`)
        ) {$charset}";

        // lt_tax_rates_history — Histórico de tasas de impuestos para auditoría
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_tax_rates_history` (
            `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `country_code`  CHAR(2) NOT NULL,
            `tax_type`      VARCHAR(20) NOT NULL COMMENT 'IVA, ISR, IEPS, ICA, etc.',
            `rate`          DECIMAL(5,4) NOT NULL,
            `effective_from` DATE NOT NULL,
            `effective_to`  DATE DEFAULT NULL,
            `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_country_type_date` (`country_code`, `tax_type`, `effective_from`)
        ) {$charset}";

        // lt_tourism_compliance — Registro de cumplimiento turístico (RNT/SECTUR)
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_tourism_compliance` (
            `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `vendor_id`       BIGINT UNSIGNED NOT NULL,
            `product_id`      BIGINT UNSIGNED DEFAULT NULL,
            `country_code`    CHAR(2) NOT NULL DEFAULT 'CO',
            `registry_number` VARCHAR(100) DEFAULT NULL COMMENT 'RNT (CO) o Folio SECTUR (MX)',
            `status`          ENUM('pending','active','expired','suspended') NOT NULL DEFAULT 'pending',
            `expires_at`      DATE DEFAULT NULL,
            `notes`           TEXT DEFAULT NULL,
            `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_vendor` (`vendor_id`),
            KEY `idx_product` (`product_id`)
        ) {$charset}";

        // lt_vendor_drivers — Domiciliarios/conductores asociados al vendedor
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_vendor_drivers` (
            `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `vendor_id`       BIGINT UNSIGNED NOT NULL,
            `wp_user_id`      BIGINT UNSIGNED DEFAULT NULL COMMENT 'WP user si tiene cuenta',
            `full_name`       VARCHAR(200) NOT NULL,
            `document_number` VARCHAR(30) NOT NULL,
            `phone`           VARCHAR(20) DEFAULT NULL,
            `vehicle_type`    VARCHAR(50) DEFAULT NULL,
            `vehicle_plate`   VARCHAR(20) DEFAULT NULL,
            `status`          ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
            `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_vendor` (`vendor_id`),
            KEY `idx_wp_user` (`wp_user_id`)
        ) {$charset}";

        // L-2: Auditoría de acceso a datos sensibles (Habeas Data — Ley 1581/2012 art. 8 lit. g).
        // Registra cada lectura/escritura de documentos cifrados (cédula, NIT, RUT).
        // lt_consent_log — Auditoría de consentimientos (Ley 1581/2012 Habeas Data)
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_consent_log` (
            `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `purpose`    VARCHAR(64)     NOT NULL DEFAULT 'register',
            `policy_ver` VARCHAR(16)     NOT NULL DEFAULT '2.0',
            `ip_hash`    VARCHAR(64)     NOT NULL DEFAULT '',
            `channel`    VARCHAR(32)     NOT NULL DEFAULT 'web',
            `user_agent` VARCHAR(255)    NOT NULL DEFAULT '',
            `meta_json`  TEXT,
            `created_at` DATETIME        NOT NULL,
            PRIMARY KEY  (`id`),
            KEY          `idx_user_purpose` (`user_id`, `purpose`),
            KEY          `idx_created_at`   (`created_at`)
        ) {$charset_collate};";

        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_vault_access_log` (
            `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id`     BIGINT UNSIGNED NOT NULL COMMENT 'Usuario cuyo dato fue accedido',
            `actor_id`    BIGINT UNSIGNED DEFAULT NULL COMMENT 'Quien accedió (admin/cron=0)',
            `action`      ENUM('read','write','decrypt','export','delete') NOT NULL,
            `field_name`  VARCHAR(100) NOT NULL COMMENT 'Ej: ltms_document, ltms_nit',
            `context`     VARCHAR(255) DEFAULT NULL COMMENT 'Contexto: kyc, payout, audit',
            `ip_address`  VARCHAR(45) DEFAULT NULL,
            `user_agent`  VARCHAR(300) DEFAULT NULL,
            `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user`    (`user_id`),
            KEY `idx_actor`   (`actor_id`),
            KEY `idx_created` (`created_at`)
        ) {$charset}";

        // L-3: Consentimientos explícitos de datos personales (Ley 1581/2012 art. 9; SIC Res. 2019).
        // Guarda el token de consentimiento, versión de política y timestamp para auditoría SIC.
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_data_consents` (
            `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id`         BIGINT UNSIGNED DEFAULT NULL COMMENT 'NULL = visitante anónimo',
            `session_id`      VARCHAR(128) DEFAULT NULL,
            `consent_type`    ENUM('registration','checkout','kyc','marketing','zapsign','cookies') NOT NULL,
            `consent_given`   TINYINT(1) NOT NULL DEFAULT 1,
            `policy_version`  VARCHAR(20) NOT NULL DEFAULT '1.0',
            `ip_address`      VARCHAR(45) DEFAULT NULL,
            `user_agent`      VARCHAR(300) DEFAULT NULL,
            `page_url`        VARCHAR(500) DEFAULT NULL,
            `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user`    (`user_id`),
            KEY `idx_type`    (`consent_type`),
            KEY `idx_created` (`created_at`)
        ) {$charset}";

        // M-210: tabla de retenciones de Consumer Protection (Ley 1480)
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_wallet_holds` (
            `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `vendor_id`     BIGINT UNSIGNED NOT NULL,
            `order_id`      BIGINT UNSIGNED NOT NULL,
            `amount`        DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `reason`        VARCHAR(255) DEFAULT NULL,
            `freeze_reason` VARCHAR(255) DEFAULT NULL,
            `status`        VARCHAR(20) NOT NULL DEFAULT 'held',
            `release_at`    DATETIME NOT NULL,
            `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `released_at`   DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `vendor_id` (`vendor_id`),
            KEY `order_id` (`order_id`),
            KEY `status_release` (`status`, `release_at`)
        ) {$charset}";

        // M-210: tabla de tasas de comisión por volumen y país
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_commission_tiers` (
            `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `country`    CHAR(2)      NOT NULL DEFAULT 'CO',
            `tier_name`  VARCHAR(50)  NOT NULL DEFAULT 'base',
            `min_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `max_amount` DECIMAL(15,2) NOT NULL DEFAULT 999999999.00,
            `rate`       DECIMAL(5,4) NOT NULL DEFAULT 0.1000,
            `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
            `sort_order` INT(11)      NOT NULL DEFAULT 10,
            `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `country_active` (`country`, `is_active`)
        ) {$charset}";

        // v2.9.69 DEEP-AUDIT-002 P2-24: Backorder subscriptions custom table.
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_backorder_subscriptions` (
            `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `product_id` BIGINT UNSIGNED NOT NULL,
            `email`      VARCHAR(255) NOT NULL,
            `user_id`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `ip_address` VARCHAR(45) NOT NULL DEFAULT '',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `notified_at` DATETIME NULL DEFAULT NULL,
            `status`     VARCHAR(20) NOT NULL DEFAULT 'pending',
            PRIMARY KEY (`id`),
            UNIQUE KEY `udx_product_email` (`product_id`, `email`),
            KEY `idx_product_pending` (`product_id`, `status`)
        ) {$charset}";

        // v2.9.69 DEEP-AUDIT-002 P2-25: Review helpful votes custom table.
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_review_votes` (
            `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `comment_id` BIGINT UNSIGNED NOT NULL,
            `user_id`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `ip_address` VARCHAR(45) NOT NULL DEFAULT '',
            `vote_type`  VARCHAR(10) NOT NULL DEFAULT 'helpful',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `udx_comment_ip` (`comment_id`, `ip_address`),
            KEY `idx_comment_id` (`comment_id`)
        ) {$charset}";

    }

    /**
     * Inserta el catálogo DANE de municipios Colombia (capitales departamentales + ciudades principales).
     * Solo inserta si la tabla está vacía. Datos públicos DANE — pueden expandirse vía admin.
     *
     * @return void
     */
    private static function seed_dane_municipalities(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_co_dane_municipalities';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
        if ( $count > 0 ) {
            return;
        }

        // [code DANE 5-dig, dept_code, dept_name, municipality_name]
        $seeds = [
            [ '05001', '05', 'Antioquia',          'Medellín' ],
            [ '05088', '05', 'Antioquia',          'Bello' ],
            [ '05266', '05', 'Antioquia',          'Envigado' ],
            [ '05360', '05', 'Antioquia',          'Itagüí' ],
            [ '05631', '05', 'Antioquia',          'Sabaneta' ],
            [ '08001', '08', 'Atlántico',          'Barranquilla' ],
            [ '08758', '08', 'Atlántico',          'Soledad' ],
            [ '11001', '11', 'Bogotá D.C.',        'Bogotá' ],
            [ '13001', '13', 'Bolívar',            'Cartagena' ],
            [ '15001', '15', 'Boyacá',             'Tunja' ],
            [ '17001', '17', 'Caldas',             'Manizales' ],
            [ '18001', '18', 'Caquetá',            'Florencia' ],
            [ '19001', '19', 'Cauca',              'Popayán' ],
            [ '20001', '20', 'Cesar',              'Valledupar' ],
            [ '23001', '23', 'Córdoba',            'Montería' ],
            [ '25175', '25', 'Cundinamarca',       'Chía' ],
            [ '25430', '25', 'Cundinamarca',       'Madrid' ],
            [ '25754', '25', 'Cundinamarca',       'Soacha' ],
            [ '27001', '27', 'Chocó',              'Quibdó' ],
            [ '41001', '41', 'Huila',              'Neiva' ],
            [ '44001', '44', 'La Guajira',         'Riohacha' ],
            [ '47001', '47', 'Magdalena',          'Santa Marta' ],
            [ '50001', '50', 'Meta',               'Villavicencio' ],
            [ '52001', '52', 'Nariño',             'Pasto' ],
            [ '54001', '54', 'Norte de Santander', 'Cúcuta' ],
            [ '63001', '63', 'Quindío',            'Armenia' ],
            [ '66001', '66', 'Risaralda',          'Pereira' ],
            [ '68001', '68', 'Santander',          'Bucaramanga' ],
            [ '68276', '68', 'Santander',          'Floridablanca' ],
            [ '70001', '70', 'Sucre',              'Sincelejo' ],
            [ '73001', '73', 'Tolima',             'Ibagué' ],
            [ '76001', '76', 'Valle del Cauca',    'Cali' ],
            [ '76109', '76', 'Valle del Cauca',    'Buenaventura' ],
            [ '76520', '76', 'Valle del Cauca',    'Palmira' ],
            [ '76834', '76', 'Valle del Cauca',    'Tuluá' ],
            [ '81001', '81', 'Arauca',             'Arauca' ],
            [ '85001', '85', 'Casanare',           'Yopal' ],
            [ '86001', '86', 'Putumayo',           'Mocoa' ],
            [ '88001', '88', 'San Andrés',         'San Andrés' ],
            [ '91001', '91', 'Amazonas',           'Leticia' ],
            [ '94001', '94', 'Guainía',            'Inírida' ],
            [ '95001', '95', 'Guaviare',           'San José del Guaviare' ],
            [ '97001', '97', 'Vaupés',             'Mitú' ],
            [ '99001', '99', 'Vichada',            'Puerto Carreño' ],
        ];

        foreach ( $seeds as $row ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert(
                $table,
                [
                    'dane_code'  => $row[0],
                    'name'       => $row[3],
                    'department' => $row[2],
                    'is_active'  => 1,
                ],
                [ '%s', '%s', '%s', '%d' ]
            );
        }
    }

    /**
     * Inserta tarifas ReteICA por municipio y CIIU. Solo si tabla vacía.
     * IMPORTANTE: estos valores son referencia 2024-2025. Deben validarse con asesor tributario
     * y actualizarse cuando los municipios modifiquen sus estatutos.
     *
     * @return void
     */
    private static function seed_reteica_municipal_rates(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_co_reteica_rates_municipal';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
        if ( $count > 0 ) {
            return;
        }

        $valid_from = '2024-01-01';

        // [municipality_code, ciiu_code, rate_per_thousand, description, legal_reference]
        $seeds = [
            // Bogotá D.C. (11001) — Acuerdo 65/2002
            [ '11001', '4711',  4.1400, 'Comercio al por menor',                  'Acuerdo 65/2002 Bogotá' ],
            [ '11001', '4791',  4.1400, 'Comercio electrónico / por correo',      'Acuerdo 65/2002 Bogotá' ],
            [ '11001', '5611',  9.6600, 'Expendio a la mesa de comidas',          'Acuerdo 65/2002 Bogotá' ],
            [ '11001', '5510',  9.6600, 'Alojamiento en hoteles',                 'Acuerdo 65/2002 Bogotá' ],
            [ '11001', '7990',  9.6600, 'Otros servicios de reserva turística',   'Acuerdo 65/2002 Bogotá' ],
            [ '11001', '6201', 11.0400, 'Desarrollo de sistemas informáticos',    'Acuerdo 65/2002 Bogotá' ],

            // Cali (76001) — Acuerdo 357/2013
            [ '76001', '4711',  5.5000, 'Comercio al por menor',                  'Acuerdo 357/2013 Cali' ],
            [ '76001', '4791',  5.5000, 'Comercio electrónico',                   'Acuerdo 357/2013 Cali' ],
            [ '76001', '5611', 10.0000, 'Restaurantes',                           'Acuerdo 357/2013 Cali' ],
            [ '76001', '5510', 10.0000, 'Hoteles',                                'Acuerdo 357/2013 Cali' ],
            [ '76001', '7990', 10.0000, 'Turismo',                                'Acuerdo 357/2013 Cali' ],
            [ '76001', '6201', 11.0000, 'Servicios informáticos',                 'Acuerdo 357/2013 Cali' ],

            // Medellín (05001) — Acuerdo 67/2008
            [ '05001', '4711',  3.0000, 'Comercio minorista',                     'Acuerdo 67/2008 Medellín' ],
            [ '05001', '4791',  3.0000, 'Comercio electrónico',                   'Acuerdo 67/2008 Medellín' ],
            [ '05001', '5611',  7.0000, 'Restaurantes',                           'Acuerdo 67/2008 Medellín' ],
            [ '05001', '5510',  7.0000, 'Hoteles',                                'Acuerdo 67/2008 Medellín' ],
            [ '05001', '7990',  7.0000, 'Turismo',                                'Acuerdo 67/2008 Medellín' ],
            [ '05001', '6201',  7.0000, 'Servicios informáticos',                 'Acuerdo 67/2008 Medellín' ],

            // Barranquilla (08001) — Estatuto Tributario Distrital
            [ '08001', '4711',  6.0000, 'Comercio',                               'Estatuto Tributario Barranquilla' ],
            [ '08001', '4791',  6.0000, 'Comercio electrónico',                   'Estatuto Tributario Barranquilla' ],
            [ '08001', '5611', 10.0000, 'Restaurantes',                           'Estatuto Tributario Barranquilla' ],
            [ '08001', '5510', 10.0000, 'Hoteles',                                'Estatuto Tributario Barranquilla' ],

            // Cartagena (13001) — Acuerdo 41/2006
            [ '13001', '4711',  7.0000, 'Comercio',                               'Acuerdo 41/2006 Cartagena' ],
            [ '13001', '4791',  7.0000, 'Comercio electrónico',                   'Acuerdo 41/2006 Cartagena' ],
            [ '13001', '5611', 10.0000, 'Restaurantes',                           'Acuerdo 41/2006 Cartagena' ],
            [ '13001', '5510', 10.0000, 'Hoteles',                                'Acuerdo 41/2006 Cartagena' ],

            // Bucaramanga (68001)
            [ '68001', '4711',  4.0000, 'Comercio',                               'Estatuto Bucaramanga' ],
            [ '68001', '4791',  4.0000, 'Comercio electrónico',                   'Estatuto Bucaramanga' ],
            [ '68001', '5611',  7.0000, 'Restaurantes',                           'Estatuto Bucaramanga' ],
        ];

        foreach ( $seeds as $row ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert(
                $table,
                [
                    'municipality_code' => $row[0],
                    'ciiu_code'         => $row[1],
                    'rate_per_thousand' => $row[2],
                    'description'       => $row[3],
                    'legal_reference'   => $row[4],
                    'valid_from'        => $valid_from,
                ],
                [ '%s', '%s', '%f', '%s', '%s', '%s' ]
            );
        }
    }

    /**
     * Migra user_meta `ltms_municipality` de slug ('bogota', 'cali', etc.) a código DANE.
     * One-shot, idempotente: usa option `ltms_municipality_slug_migrated` como guard.
     *
     * @return void
     */
    private static function migrate_vendor_municipality_slugs_to_dane(): void {
        if ( get_option( 'ltms_municipality_slug_migrated' ) === '1' ) {
            return;
        }

        $slug_to_dane = [
            'bogota'        => '11001', 'bogotá'        => '11001',
            'medellin'      => '05001', 'medellín'      => '05001',
            'cali'          => '76001',
            'barranquilla'  => '08001',
            'cartagena'     => '13001',
            'bucaramanga'   => '68001',
            'pereira'       => '66001',
            'manizales'     => '17001',
            'cucuta'        => '54001', 'cúcuta'        => '54001',
            'ibague'        => '73001', 'ibagué'        => '73001',
            'villavicencio' => '50001',
            'pasto'         => '52001',
            'monteria'      => '23001', 'montería'      => '23001',
            'neiva'         => '41001',
            'armenia'       => '63001',
            'santa marta'   => '47001', 'santamarta'    => '47001',
            'valledupar'    => '20001',
            'tunja'         => '15001',
            'popayan'       => '19001', 'popayán'       => '19001',
        ];

        global $wpdb;
        $meta_table = $wpdb->usermeta;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( "SELECT user_id, meta_value FROM `{$meta_table}` WHERE meta_key = 'ltms_municipality' AND meta_value != ''", ARRAY_A );
        $migrated = 0;
        foreach ( (array) $rows as $row ) {
            $value = strtolower( trim( (string) ( $row['meta_value'] ?? '' ) ) );
            if ( $value === '' || preg_match( '/^\d{5}$/', $value ) ) {
                continue; // ya es DANE o vacío
            }
            if ( isset( $slug_to_dane[ $value ] ) ) {
                update_user_meta( (int) $row['user_id'], 'ltms_municipality', $slug_to_dane[ $value ] );
                $migrated++;
            }
        }

        update_option( 'ltms_municipality_slug_migrated', '1', false );

        if ( $migrated > 0 && class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'MUNI_SLUG_MIGRATED',
                sprintf( 'Migrados %d vendedores: ltms_municipality slug → código DANE.', $migrated )
            );
        }
    }

    /**
     * Crea índices adicionales para optimización de consultas frecuentes.
     *
     * @return void
     */
    private static function create_indexes(): void {
        global $wpdb;

        // DDL statements (ALTER TABLE) cannot use parameterized placeholders.
        // Table names are derived exclusively from $wpdb->prefix (trusted, server-set value).
        // ADD INDEX IF NOT EXISTS is MariaDB-only — we use a SELECT-based guard for MySQL compat.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $p  = $wpdb->prefix;
        $db = DB_NAME;

        /**
         * Each entry: [ table_without_prefix, index_name, column_list ]
         * We check information_schema before altering to stay idempotent on MySQL.
         */
        $indexes = [
            [ 'lt_wallet_transactions', 'idx_created_at',       '(`created_at`)' ],
            [ 'lt_commissions',         'idx_paid_at',           '(`paid_at`)' ],
            [ 'lt_notifications',       'idx_user_unread',       '(`user_id`, `is_read`, `created_at`)' ],
            [ 'lt_wallet_transactions', 'idx_vendor_type_date',  '(`vendor_id`, `type`, `created_at`)' ],
            [ 'lt_waf_blocked_ips',     'idx_expires_at',        '(`expires_at`)' ],
            [ 'lt_commissions',         'idx_vendor_status_date','(`vendor_id`, `status`, `created_at`)' ],
            [ 'lt_audit_logs',          'idx_user_date',         '(`user_id`, `created_at`)' ],
            [ 'lt_api_logs',            'idx_provider_date',     '(`provider`, `created_at`)' ],
        ];

        foreach ( $indexes as [ $tbl_suffix, $idx_name, $cols ] ) {
            $table = $p . $tbl_suffix;

            // Skip if the index already exists (MySQL + MariaDB compatible).
            $exists = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s",
                    $db,
                    $table,
                    $idx_name
                )
            );

            if ( $exists ) {
                continue;
            }

            $wpdb->query( "ALTER TABLE `{$table}` ADD INDEX `{$idx_name}` {$cols}" );
        }
        // phpcs:enable
    }

    /**
     * v2.0.1: Fix lt_payout_requests schema.
     *
     * - wallet_id was deployed as NOT NULL with no default, blocking all INSERTs.
     * - Missing columns (fee, net_amount, bank_account_id, reference, gateway_ref,
     *   notes, approved_by) are added by dbDelta() in create_tables() above.
     *   This method only handles what dbDelta cannot: changing NOT NULL → nullable.
     *
     * @return void
     */
    /**
     * v2.1.0 — Módulo Fiscal DIAN / Acceso en Línea (Colombia)
     *
     * 1. lt_commissions: agrega columnas de desglose fiscal detallado.
     * 2. lt_vendor_kyc:  agrega campos tributarios y bancarios requeridos
     *    por el art. 30-B CFF (MX) y el equivalente colombiano (Exógena DIAN).
     * 3. Nueva tabla lt_dian_online_access: log de accesos del auditor fiscal.
     * 4. Actualiza UVT 2026 ($52.752).
     *
     * Idempotente: cada ALTER se guarda solo si la columna no existe.
     */
    private static function migrate_2_1_0_fiscal_dian(): void {
        global $wpdb;

        $helper = static function ( string $table, string $column ): bool {
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
            return (bool) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                    DB_NAME, $table, $column
                )
            );
        };

        // ── 1. lt_commissions: columnas de desglose fiscal ───────────────────
        $c = $wpdb->prefix . 'lt_commissions';

        $commission_cols = [
            'retefuente_amount' => "DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'ReteFuente aplicada al vendedor'",
            'retefuente_rate'   => "DECIMAL(8,6) NOT NULL DEFAULT 0.000000 COMMENT 'Tasa ReteFuente aplicada'",
            'reteiva_amount'    => "DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'ReteIVA aplicada'",
            'reteiva_rate'      => "DECIMAL(8,6) NOT NULL DEFAULT 0.000000",
            'reteica_amount'    => "DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'ReteICA aplicada'",
            'reteica_rate'      => "DECIMAL(8,6) NOT NULL DEFAULT 0.000000",
            'isr_amount'        => "DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'ISR retención MX'",
            'isr_rate'          => "DECIMAL(8,6) NOT NULL DEFAULT 0.000000",
            'ieps_amount'       => "DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'IEPS MX'",
            'impoconsumo_amount'=> "DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Impoconsumo CO'",
            'vendor_nit'        => "VARCHAR(30) DEFAULT NULL COMMENT 'NIT/RUT/RFC del vendedor al momento de la transacción'",
            'vendor_regime'     => "VARCHAR(50) DEFAULT NULL COMMENT 'Régimen tributario del vendedor'",
            'vendor_ciiu'       => "VARCHAR(10) DEFAULT NULL COMMENT 'Código CIIU del vendedor'",
            'vendor_municipality'=> "VARCHAR(10) DEFAULT NULL COMMENT 'Código DANE del municipio'",
            'cfdi_folio'        => "VARCHAR(50) DEFAULT NULL COMMENT 'Folio CFDI (México) o factura electrónica CO'",
            'payment_method'    => "VARCHAR(30) DEFAULT NULL COMMENT 'Método de pago (PSE, tarjeta, efectivo, etc.)'",
        ];

        foreach ( $commission_cols as $col => $definition ) {
            if ( ! $helper( $c, $col ) ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
                $wpdb->query( "ALTER TABLE `{$c}` ADD COLUMN `{$col}` {$definition}" );
            }
        }

        // ── 2. lt_vendor_kyc: campos tributarios y bancarios ─────────────────
        $k = $wpdb->prefix . 'lt_vendor_kyc';

        $kyc_cols = [
            'tax_regime'        => "VARCHAR(50) DEFAULT NULL COMMENT 'Régimen tributario (common, simplified, gran_contribuyente, resico…)'",
            'ciiu_code'         => "VARCHAR(10) DEFAULT NULL COMMENT 'Código de actividad económica CIIU/SCIAN'",
            'municipality_code' => "VARCHAR(10) DEFAULT NULL COMMENT 'Código DANE del municipio fiscal'",
            'bank_name'         => "VARCHAR(100) DEFAULT NULL COMMENT 'Entidad financiera para depósitos'",
            'bank_account_type' => "ENUM('ahorros','corriente','clabe','otro') DEFAULT NULL",
            'bank_account_number'=> "VARCHAR(50) DEFAULT NULL COMMENT 'CLABE/cuenta cifrada AES-256'",
            'address_fiscal'    => "VARCHAR(500) DEFAULT NULL COMMENT 'Domicilio fiscal declarado'",
            'is_sagrilaft_flag' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 si superó umbral SAGRILAFT'",
            'sagrilaft_flagged_at'=> "DATETIME DEFAULT NULL",
        ];

        foreach ( $kyc_cols as $col => $definition ) {
            if ( ! $helper( $k, $col ) ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
                $wpdb->query( "ALTER TABLE `{$k}` ADD COLUMN `{$col}` {$definition}" );
            }
        }

        // ── 3. Nueva tabla: lt_dian_online_access ────────────────────────────
        // Registra cada acceso del auditor DIAN/SAT al portal de acceso en línea.
        $a = $wpdb->prefix . 'lt_dian_online_access';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS `{$a}` (
                `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `session_token` CHAR(64) NOT NULL COMMENT 'Token de sesión hasheado',
                `auditor_name`  VARCHAR(255) DEFAULT NULL,
                `auditor_nit`   VARCHAR(50)  DEFAULT NULL COMMENT 'NIT de la autoridad fiscal',
                `access_type`   ENUM('login','query_transactions','query_vendor','export','logout') NOT NULL,
                `filter_from`   DATE DEFAULT NULL,
                `filter_to`     DATE DEFAULT NULL,
                `filter_vendor` VARCHAR(50) DEFAULT NULL,
                `rows_returned` INT UNSIGNED DEFAULT 0,
                `ip_address`    VARCHAR(45) DEFAULT NULL,
                `user_agent`    VARCHAR(500) DEFAULT NULL,
                `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_session` (`session_token`),
                KEY `idx_access_type` (`access_type`),
                KEY `idx_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Registro de accesos fiscales en línea (Art. 30-B CFF / DIAN Colombia)';"
        );

        // ── 4. UVT 2026 ──────────────────────────────────────────────────────
        // Solo actualiza si el valor anterior era el de 2025 ($49.799) o 2024 ($47.065).
        // No sobreescribe si el admin ya lo actualizó manualmente.
        $current_uvt = (float) get_option( 'ltms_uvt_valor', 0 );
        if ( $current_uvt > 0 && $current_uvt < 52000 ) {
            update_option( 'ltms_uvt_valor', 52752.0 );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert(
                $wpdb->prefix . 'lt_tax_rates_history',
                [
                    'country'          => 'CO',
                    'rate_key'         => 'ltms_uvt_valor',
                    'old_value'        => $current_uvt,
                    'new_value'        => 52752.0,
                    'decree_reference' => 'Resolución DIAN 000187/2025 — UVT 2026',
                    'changed_by'       => 0, // 0 = sistema automático
                    'valid_from'       => '2026-01-01',
                ],
                [ '%s', '%s', '%f', '%f', '%s', '%d', '%s' ]
            );
        }
    }

    private static function migrate_2_0_1_payout_schema(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_payout_requests';

        // Check whether wallet_id is still NOT NULL (idempotent guard).
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $col = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT IS_NULLABLE, COLUMN_TYPE
                   FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = %s
                    AND TABLE_NAME   = %s
                    AND COLUMN_NAME  = 'wallet_id'",
                DB_NAME,
                $table
            ),
            ARRAY_A
        );

        if ( $col && $col['IS_NULLABLE'] === 'NO' ) {
            $wpdb->query(
                "ALTER TABLE `{$table}`
                 MODIFY COLUMN `wallet_id` BIGINT UNSIGNED DEFAULT NULL"
            );
        }
        // phpcs:enable
    }

    /**
     * Migración v2.2.0 — Cumplimiento Art. 30-B CFF (SAT México)
     *
     * lt_commissions: vendor_rfc, vendor_curp, vendor_clabe, isr_rate,
     *                 is_import, aranceles_amount, is_hospedaje, property_address_mx,
     *                 ieps_rate, sat_cfdi_folio
     * lt_vendor_kyc:  rfc_mx, curp_mx, fiscal_regime_mx, clabe_mx, domicilio_fiscal_mx
     * Nueva tabla:    lt_sat_online_access (log auditor SAT, ficha 168/CFF)
     * Siembra:        tramos ISR Art. 113-A 2025 si la tabla está vacía
     *
     * @return void
     */
    private static function migrate_2_2_0_fiscal_sat_mexico(): void {
        global $wpdb;
        $c = $wpdb->prefix . 'lt_commissions';
        $k = $wpdb->prefix . 'lt_vendor_kyc';
        $s = $wpdb->prefix . 'lt_sat_online_access';

        // ── 1. lt_commissions — campos SAT México ────────────────────────────
        $commission_cols = [
            'vendor_rfc'          => "VARCHAR(13)   DEFAULT NULL COMMENT 'RFC vendedor al momento de la transacción'",
            'vendor_curp'         => "CHAR(18)      DEFAULT NULL COMMENT 'CURP vendedor (PF residentes MX)'",
            'customer_rfc'        => "VARCHAR(13)   DEFAULT NULL COMMENT 'RFC del receptor/comprador cuando solicita CFDI (Art.30-B frac.I inc.b)'",
            'payment_method_vendor'   => "VARCHAR(30)   DEFAULT NULL COMMENT 'Método de pago del oferente (Art.30-B frac.II f-iv)'",
            'payment_method_buyer'    => "VARCHAR(30)   DEFAULT NULL COMMENT 'Método de pago del adquiriente (Art.30-B frac.II f-iv)'",
            'payment_method_platform' => "VARCHAR(30)   DEFAULT NULL COMMENT 'Método de pago de la plataforma intermediaria (Art.30-B frac.II f-iv)'",
            'vendor_clabe'        => "CHAR(18)      DEFAULT NULL COMMENT 'CLABE interbancaria de depósito'",
            'isr_rate'            => "DECIMAL(8,6)  NOT NULL DEFAULT 0.000000 COMMENT 'Tasa ISR aplicada'",
            'is_import'           => "TINYINT(1)    NOT NULL DEFAULT 0 COMMENT 'Flag: importación (Art.30-B frac.II inc.h)'",
            'aranceles_amount'    => "DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Aranceles en importación'",
            'is_hospedaje'        => "TINYINT(1)    NOT NULL DEFAULT 0 COMMENT 'Flag: servicio de hospedaje (Art.30-B frac.II inc.g)'",
            'property_address_mx'=> "VARCHAR(500)  DEFAULT NULL COMMENT 'Dirección completa del inmueble (hospedaje)'",
            'ieps_rate'           => "DECIMAL(8,6)  NOT NULL DEFAULT 0.000000 COMMENT 'Tasa IEPS aplicada'",
            'sat_cfdi_folio'      => "VARCHAR(36)   DEFAULT NULL COMMENT 'UUID CFDI 4.0 emitido al comprador'",
        ];

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $existing_cols = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $c
            )
        );

        foreach ( $commission_cols as $col => $definition ) {
            if ( ! in_array( $col, $existing_cols, true ) ) {
                $wpdb->query( "ALTER TABLE `{$c}` ADD COLUMN `{$col}` {$definition}" );
            }
        }

        // ── 2. lt_vendor_kyc — campos RFC/CURP/CLABE México ─────────────────
        $kyc_cols = [
            'rfc_mx'              => "VARCHAR(13)  DEFAULT NULL COMMENT 'RFC SAT'",
            'curp_mx'             => "CHAR(18)     DEFAULT NULL COMMENT 'CURP (18 dígitos, solo PF residentes MX)'",
            'fiscal_regime_mx'    => "VARCHAR(50)  DEFAULT NULL COMMENT 'Régimen SAT: resico, pf_actividad, pm, arrendamiento'",
            'clabe_mx'            => "CHAR(18)     DEFAULT NULL COMMENT 'CLABE interbancaria para pagos MX'",
            'domicilio_fiscal_mx' => "VARCHAR(500) DEFAULT NULL COMMENT 'Domicilio fiscal completo'",
        ];

        $existing_kyc = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $k
            )
        );

        foreach ( $kyc_cols as $col => $definition ) {
            if ( ! in_array( $col, $existing_kyc, true ) ) {
                $wpdb->query( "ALTER TABLE `{$k}` ADD COLUMN `{$col}` {$definition}" );
            }
        }

        // ── 3. Nueva tabla: lt_sat_online_access ─────────────────────────────
        $charset = $wpdb->get_charset_collate();
        $table_exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $s
            )
        );

        if ( ! $table_exists ) {
            $wpdb->query(
                "CREATE TABLE `{$s}` (
                    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `session_token` CHAR(64)        NOT NULL COMMENT 'SHA-256 del token de sesión SAT',
                    `auditor_name`  VARCHAR(255)    DEFAULT NULL,
                    `auditor_rfc`   VARCHAR(13)     DEFAULT NULL COMMENT 'RFC del auditor SAT',
                    `access_type`   VARCHAR(100)    NOT NULL COMMENT 'query_transactions|export_csv|view_vendor|view_summary',
                    `filter_from`   DATE            DEFAULT NULL,
                    `filter_to`     DATE            DEFAULT NULL,
                    `filter_vendor` VARCHAR(13)     DEFAULT NULL COMMENT 'RFC filtrado',
                    `filter_period` VARCHAR(7)      DEFAULT NULL COMMENT 'YYYY-MM',
                    `rows_returned` INT UNSIGNED    NOT NULL DEFAULT 0,
                    `ip_address`    VARCHAR(45)     DEFAULT NULL,
                    `user_agent`    VARCHAR(500)    DEFAULT NULL,
                    `accessed_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_session`  (`session_token`),
                    KEY `idx_accessed` (`accessed_at`),
                    KEY `idx_auditor`  (`auditor_rfc`)
                ) {$charset}"
            );
        }

        // ── 4. Sembrar tramos ISR Art. 113-A 2025 si la tabla está vacía ─────
        $t = $wpdb->prefix . 'lt_mx_isr_tramos';
        $tramo_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$t}`" );

        if ( 0 === $tramo_count ) {
            $tramos = [
                [ 0.00,       25000.00,    0.02, '2025-01-01' ],
                [ 25000.01,  100000.00,    0.05, '2025-01-01' ],
                [ 100000.01, 300000.00,    0.10, '2025-01-01' ],
                [ 300000.01, 999999999.00, 0.17, '2025-01-01' ],
            ];
            foreach ( $tramos as $tr ) {
                $wpdb->insert(
                    $t,
                    [
                        'min_amount' => $tr[0],
                        'max_amount' => $tr[1],
                        'rate'       => $tr[2],
                        'valid_from' => $tr[3],
                    ],
                    [ '%f', '%f', '%f', '%s' ]
                );
            }
        }
        // phpcs:enable
    }
    private static function run_v2_4_1(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        // Tablas de auditoría fiscal SAT (MX) y DIAN (CO)
        $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}lt_sat_online_access` (
            `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `auditor_rfc`   VARCHAR(13)  NOT NULL DEFAULT '',
            `auditor_name`  VARCHAR(200) NOT NULL DEFAULT '',
            `access_type`   VARCHAR(50)  NOT NULL DEFAULT '',
            `filter_period` VARCHAR(20)  DEFAULT NULL,
            `filter_vendor` VARCHAR(200) DEFAULT NULL,
            `rows_returned` INT          NOT NULL DEFAULT 0,
            `ip_address`    VARCHAR(45)  DEFAULT NULL,
            `accessed_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_accessed_at` (`accessed_at`),
            KEY `idx_auditor_rfc` (`auditor_rfc`)
        ) {$charset}" );

        $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}lt_dian_online_access` (
            `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `auditor_nit`   VARCHAR(20)  NOT NULL DEFAULT '',
            `auditor_name`  VARCHAR(200) NOT NULL DEFAULT '',
            `access_type`   VARCHAR(50)  NOT NULL DEFAULT '',
            `filter_from`   DATE         DEFAULT NULL,
            `filter_vendor` VARCHAR(200) DEFAULT NULL,
            `rows_returned` INT          NOT NULL DEFAULT 0,
            `ip_address`    VARCHAR(45)  DEFAULT NULL,
            `accessed_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_accessed_at` (`accessed_at`),
            KEY `idx_auditor_nit` (`auditor_nit`)
        ) {$charset}" );

        LTMS_Core_Logger::info( "DB_MIGRATION", "v2.4.1: lt_sat_online_access + lt_dian_online_access OK." );
    }

    private static function run_v2_4_0(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $table   = $wpdb->prefix . 'lt_accounting_entries';
        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `account_code` VARCHAR(10)     NOT NULL,
            `entry_type`   ENUM('debit','credit') NOT NULL,
            `amount`       DECIMAL(15,2)   NOT NULL DEFAULT 0.00,
            `currency`     CHAR(3)         NOT NULL DEFAULT 'COP',
            `description`  VARCHAR(500)    NOT NULL DEFAULT '',
            `vendor_id`    BIGINT UNSIGNED NULL,
            `source_id`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `source_type`  VARCHAR(50)     NOT NULL DEFAULT '',
            `created_by`   BIGINT UNSIGNED NULL,
            `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_account_code` (`account_code`),
            KEY `idx_source`       (`source_type`, `source_id`),
            KEY `idx_vendor`       (`vendor_id`),
            KEY `idx_created_at`   (`created_at`)
        ) {$charset};";
        require_once ABSPATH . "wp-admin/includes/upgrade.php";
        dbDelta( $sql );
        LTMS_Core_Logger::info( "DB_MIGRATION", "v2.4.0: lt_accounting_entries OK." );
    }

    /**
     * v2.3.0 — Corrección de esquema: columnas y tablas faltantes.
     *
     * Idempotente: cada ALTER/CREATE se guarda solo si ya no existe.
     *
     * Corrige:
     *  - vendor_net en bkr_lt_bookings (Error: Unknown column 'vendor_net')
     *  - lt_aveonline_cities (Error: Table doesn't exist en LTMS_Business_Aveonline_Agents)
     *  - lt_consent_log     (Error: Table doesn't exist en LTMS_Legal_Compliance::log_consent)
     *  - lt_vault_access_log (Error: Table doesn't exist en LTMS_Legal_Compliance)
     *
     * @return void
     */
    private static function migrate_2_3_0(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $p       = $wpdb->prefix;

        // ── 1. vendor_net en lt_bookings ──────────────────────────────────
        $bookings_table = $p . 'lt_bookings';
        $col_exists = $wpdb->get_results( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'vendor_net'",
            DB_NAME, $bookings_table
        ) );
        if ( empty( $col_exists ) ) {
            $wpdb->query(
                "ALTER TABLE `{$bookings_table}`
                 ADD COLUMN `vendor_net` DECIMAL(15,2) DEFAULT 0.00
                 COMMENT 'Neto al vendedor tras comisión'
                 AFTER `balance_amount`"
            );
        }

        // ── 2. lt_aveonline_cities ────────────────────────────────────────
        $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$p}lt_aveonline_cities` (
            `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `nombre`       VARCHAR(160) NOT NULL,
            `codigodane`   VARCHAR(12)  NOT NULL DEFAULT '',
            `departamento` VARCHAR(80)  NOT NULL DEFAULT '',
            `nombremun`    VARCHAR(120) NOT NULL DEFAULT '',
            `synced_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_nombre` (`nombre`),
            KEY `idx_codigodane` (`codigodane`),
            KEY `idx_departamento` (`departamento`)
        ) {$charset}" );

        // ── 3. lt_consent_log ─────────────────────────────────────────────
        $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$p}lt_consent_log` (
            `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `purpose`    VARCHAR(64)     NOT NULL DEFAULT 'register',
            `policy_ver` VARCHAR(16)     NOT NULL DEFAULT '2.0',
            `ip_hash`    VARCHAR(64)     NOT NULL DEFAULT '',
            `channel`    VARCHAR(32)     NOT NULL DEFAULT 'web',
            `user_agent` VARCHAR(255)    NOT NULL DEFAULT '',
            `meta_json`  TEXT,
            `created_at` DATETIME        NOT NULL,
            PRIMARY KEY  (`id`),
            KEY          `idx_user_purpose` (`user_id`, `purpose`),
            KEY          `idx_created_at`   (`created_at`)
        ) {$charset}" );

        // ── 4. lt_vault_access_log ────────────────────────────────────────
        $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$p}lt_vault_access_log` (
            `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id`     BIGINT UNSIGNED NOT NULL COMMENT 'Usuario cuyo dato fue accedido',
            `actor_id`    BIGINT UNSIGNED DEFAULT NULL COMMENT 'Quien accedió (admin/cron=0)',
            `action`      ENUM('read','write','decrypt','export','delete') NOT NULL,
            `field_name`  VARCHAR(100) NOT NULL COMMENT 'Ej: ltms_document, ltms_nit',
            `context`     VARCHAR(255) DEFAULT NULL COMMENT 'Contexto: kyc, payout, audit',
            `ip_address`  VARCHAR(45)  DEFAULT NULL,
            `user_agent`  VARCHAR(300) DEFAULT NULL,
            `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user`    (`user_id`),
            KEY `idx_actor`   (`actor_id`),
            KEY `idx_created` (`created_at`)
        ) {$charset}" );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'DB_MIGRATION',
                'v2.3.0: vendor_net + lt_aveonline_cities + lt_consent_log + lt_vault_access_log OK.'
            );
        }
    }



    /**
     * Migración 2.4.0
     */
    private static function migrate_2_4_0(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $col = $wpdb->get_results( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'vendor_id'",
            DB_NAME, $p . 'lt_booking_season_rules'
        ) );
        if ( empty( $col ) ) {
            $wpdb->query( "ALTER TABLE `{$p}lt_booking_season_rules` ADD COLUMN `vendor_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Vendedor dueno de la regla' AFTER `product_id`, ADD INDEX `idx_vendor_id` (`vendor_id`)" );
            $wpdb->query( "UPDATE `{$p}lt_booking_season_rules` AS sr JOIN `{$p}posts` AS p ON p.ID = sr.product_id SET sr.vendor_id = p.post_author WHERE sr.product_id > 0 AND sr.vendor_id = 0" );
            if ( class_exists( 'LTMS_Core_Logger' ) ) LTMS_Core_Logger::info( 'MIGRATION', '2.4.0: vendor_id en lt_booking_season_rules OK.' );
        }

        $col2 = $wpdb->get_results( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'policy_id'",
            DB_NAME, $p . 'lt_bookings'
        ) );
        if ( empty( $col2 ) ) {
            $wpdb->query( "ALTER TABLE `{$p}lt_bookings` ADD COLUMN `policy_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK a bkr_lt_booking_policies' AFTER `vendor_net`, ADD INDEX `idx_policy_id` (`policy_id`)" );
            if ( class_exists( 'LTMS_Core_Logger' ) ) LTMS_Core_Logger::info( 'MIGRATION', '2.4.0: policy_id en lt_bookings OK.' );
        }
    }


    /**
     * Migración 2.5.0 — Agrega vendor_net a lt_bookings si no existe.
     *
     * La columna fue definida en el DDL pero la tabla pudo haberse creado
     * con una versión anterior que no la incluia. dbDelta no agrega columnas
     * nuevas; este ALTER TABLE lo hace de forma idempotente.
     */
    private static function migrate_2_5_0(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $col = $wpdb->get_results( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'vendor_net'",
            DB_NAME, $p . 'lt_bookings'
        ) );

        if ( empty( $col ) ) {
            $wpdb->query(
                "ALTER TABLE `{$p}lt_bookings`
                 ADD COLUMN `vendor_net` DECIMAL(15,2) DEFAULT 0.00
                 COMMENT 'Neto al vendedor tras comision'
                 AFTER `balance_amount`"
            );
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::info( 'MIGRATION', '2.5.0: vendor_net agregado a lt_bookings.' );
            }
        }

        // AUDIT-BOOKING-ENGINE #11 FIX: agregar columnas de lifecycle (check_in_at, check_out_at, completed_at).
        $lifecycle_cols = [ 'check_in_at', 'check_out_at', 'completed_at' ];
        foreach ( $lifecycle_cols as $lcol ) {
            $exists = $wpdb->get_results( $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                DB_NAME, $p . 'lt_bookings', $lcol
            ) );
            if ( empty( $exists ) ) {
                $wpdb->query( "ALTER TABLE `{$p}lt_bookings` ADD COLUMN `{$lcol}` DATETIME DEFAULT NULL COMMENT 'AUDIT-BOOKING-ENGINE #11: lifecycle timestamp'" );
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::info( 'MIGRATION', "AUDIT-BOOKING-ENGINE #11: {$lcol} agregado a lt_bookings." );
                }
            }
        }
    }

    /**
     * Migración 2.6.0: Recrea lt_deposits con el esquema correcto para depósitos manuales.
     *
     * El DDL original de lt_deposits era para garantías de torneo (type ENUM held/released).
     * LTMS_Deposit (módulo de recarga de wallet) necesita columnas distintas: method,
     * reference, receipt_url, approved_by/at, rejected_by/at/reason, wallet_tx_id.
     *
     * Acción: si la tabla lt_deposits existe pero SIN la columna `method`, se renombra
     * a lt_deposits_garantias (para no perder datos) y se crea la tabla correcta.
     *
     * @return void
     */
    private static function migrate_2_6_0(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $p       = $wpdb->prefix;

        $col = $wpdb->get_results( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'method'",
            DB_NAME, $p . 'lt_deposits'
        ) );

        if ( empty( $col ) ) {
            // Tabla existe con esquema de garantías — renombrar para preservar datos
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME, $p . 'lt_deposits'
            ) );

            if ( $existing ) {
                $wpdb->query( "RENAME TABLE `{$p}lt_deposits` TO `{$p}lt_deposits_garantias`" ); // phpcs:ignore
                LTMS_Core_Logger::info( 'DB_MIGRATE', 'lt_deposits → lt_deposits_garantias (2.6.0)' );
            }

            $sql = "CREATE TABLE `{$p}lt_deposits` (
                `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `vendor_id`     BIGINT UNSIGNED NOT NULL,
                `amount`        DECIMAL(15,2) NOT NULL,
                `currency`      CHAR(3) NOT NULL DEFAULT 'COP',
                `method`        VARCHAR(30) NOT NULL COMMENT 'pse|nequi|transferencia',
                `reference`     VARCHAR(120) NOT NULL DEFAULT '',
                `receipt_url`   VARCHAR(500) NOT NULL DEFAULT '',
                `notes`         TEXT,
                `status`        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                `ip_address`    VARCHAR(45) NOT NULL DEFAULT '',
                `created_by`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `approved_by`   BIGINT UNSIGNED DEFAULT NULL,
                `approved_at`   DATETIME DEFAULT NULL,
                `admin_notes`   TEXT,
                `wallet_tx_id`  BIGINT UNSIGNED DEFAULT NULL,
                `rejected_by`   BIGINT UNSIGNED DEFAULT NULL,
                `rejected_at`   DATETIME DEFAULT NULL,
                `reject_reason` TEXT,
                `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_vendor`     (`vendor_id`),
                KEY `idx_status`     (`status`),
                KEY `idx_created_at` (`created_at`)
            ) {$charset}";

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta( $sql );
            LTMS_Core_Logger::info( 'DB_MIGRATE', 'lt_deposits recreada con esquema de depósitos manuales (2.6.0)' );
        } else {
            LTMS_Core_Logger::info( 'DB_MIGRATE', 'lt_deposits ya tiene columna method — skip 2.6.0' );
        }
    }

    /**
     * Migración 2.7.0 — Donaciones Fundación Cardio Infantil.
     *
     * Crea las tablas lt_donations y lt_donation_payouts usadas por el motor
     * de donaciones automático (class-ltms-donation-engine.php) y el generador
     * de certificados PDF mensuales.
     *
     * Aunque las tablas también se crean en create_tables() vía dbDelta (que es
     * idempotente), este método actúa como red de seguridad explícita versionada:
     * si dbDelta falla silenciosamente por algún motivo, el CREATE TABLE IF NOT
     * EXISTS directo asegura que las tablas existan antes de que el motor intente
     * insertar donaciones. El flag de versión ltms_db_version garantiza que solo
     * se ejecuta una vez por instalación.
     *
     * @return void
     */
    private static function migrate_2_7_0_donations(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $p       = $wpdb->prefix;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        // Table names are derived from $wpdb->prefix (trusted, server-set value).

        // lt_donations — Registro por orden de la donación calculada.
        $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$p}lt_donations` (
            `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `order_id`              BIGINT UNSIGNED NOT NULL,
            `vendor_id`             BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `customer_id`           BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `basis_amount`          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `basis_type`            VARCHAR(32)  NOT NULL DEFAULT 'platform_fee',
            `donation_percentage`   DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            `donation_amount`       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `currency`              VARCHAR(3)   NOT NULL DEFAULT 'COP',
            `customer_extra_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `total_donation`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `status`                VARCHAR(32)  NOT NULL DEFAULT 'pending',
            `payout_batch_id`       BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `alegra_entry_id`       BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `certificate_id`        VARCHAR(64)  NOT NULL DEFAULT '',
            `created_at`            DATETIME     NOT NULL,
            `paid_at`               DATETIME     DEFAULT NULL,
            `metadata`              TEXT,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_order` (`order_id`),
            KEY `idx_order`         (`order_id`),
            KEY `idx_vendor`        (`vendor_id`),
            KEY `idx_status`        (`status`),
            KEY `idx_payout_batch`  (`payout_batch_id`),
            KEY `idx_created_at`    (`created_at`)
        ) {$charset}" );

        // lt_donation_payouts — Lotes de transferencia a la fundación.
        $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$p}lt_donation_payouts` (
            `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `batch_number`        VARCHAR(32)  NOT NULL,
            `period_start`        DATE         NOT NULL,
            `period_end`          DATE         NOT NULL,
            `total_amount`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `currency`            VARCHAR(3)   NOT NULL DEFAULT 'COP',
            `transaction_count`   INT UNSIGNED NOT NULL DEFAULT 0,
            `status`              VARCHAR(32)  NOT NULL DEFAULT 'pending',
            `transfer_reference`  VARCHAR(128) NOT NULL DEFAULT '',
            `transfer_method`     VARCHAR(32)  NOT NULL DEFAULT '',
            `alegra_entry_id`     BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `certificate_path`    VARCHAR(255) NOT NULL DEFAULT '',
            `created_by`          BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `created_at`          DATETIME     NOT NULL,
            `transferred_at`      DATETIME     DEFAULT NULL,
            `metadata`            TEXT,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_batch` (`batch_number`),
            KEY `idx_status`       (`status`),
            KEY `idx_period`       (`period_start`, `period_end`)
        ) {$charset}" );
        // phpcs:enable

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'DB_MIGRATION',
                'v2.7.0: lt_donations + lt_donation_payouts OK (Donaciones Fundación Cardio Infantil).'
            );
        }
    }

    /**
     * Migración v2.7.1 — Fintech Integration Fixes (Task 62-C).
     *
     * Aplica correcciones a esquemas existentes que dbDelta no puede aplicar
     * automáticamente (cambios de tipo de columna y claves únicas).
     *
     * 1. INT-BUG-1 (CRITICAL): `lt_vendor_wallets.vendor_id`,
     *    `lt_wallet_transactions.vendor_id` y `lt_wallet_journal.vendor_id`
     *    pasan de BIGINT UNSIGNED a BIGINT (signed) para permitir el sentinel
     *    vendor_id=-1 usado por la wallet de la fundación. Sin este cambio,
     *    `LTMS_Business_Wallet::get_or_create(-1, ...)` falla en MySQL strict
     *    mode y TODAS las donaciones se marcan 'failed'.
     *
     * 2. INT-BUG-5 (HIGH): Agrega UNIQUE KEY `uniq_order` a `lt_donations.order_id`
     *    para prevenir donaciones duplicadas por race condition (WC webhook
     *    double-fire). El handler `on_order_paid` usa SELECT-then-INSERT — el
     *    UNIQUE KEY hace que el segundo INSERT falle y el handler puede tratar
     *    el duplicado como idempotente (re-SELECT).
     *
     * Idempotente: cada paso verifica el estado actual antes de ejecutar el ALTER.
     *
     * @return void
     */
    private static function migrate_2_7_1_fintech_fixes(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        // Table names are derived from $wpdb->prefix (trusted, server-set value).

        $tables_to_fix = [
            $p . 'lt_vendor_wallets',
            $p . 'lt_wallet_transactions',
            $p . 'lt_wallet_journal', // created lazily by LTMS_Business_Wallet::ensure_journal_table()
        ];

        foreach ( $tables_to_fix as $table ) {
            // Verify the table exists before attempting the ALTER.
            $exists = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM information_schema.TABLES
                      WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                    DB_NAME,
                    $table
                )
            );
            if ( $exists < 1 ) {
                continue; // Table not created yet (e.g., wallet_journal is lazy).
            }

            // INT-BUG-1: change vendor_id from BIGINT UNSIGNED to BIGINT (signed).
            $col = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT COLUMN_TYPE
                       FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = %s
                        AND TABLE_NAME   = %s
                        AND COLUMN_NAME  = 'vendor_id'",
                    DB_NAME,
                    $table
                ),
                ARRAY_A
            );

            // Only MODIFY if the column is currently UNSIGNED. Idempotent guard.
            if ( $col && stripos( $col['COLUMN_TYPE'], 'unsigned' ) !== false ) {
                $wpdb->query( "ALTER TABLE `{$table}` MODIFY COLUMN `vendor_id` BIGINT NOT NULL" );
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::info(
                        'DB_MIGRATION',
                        sprintf( 'v2.7.1: %s.vendor_id BIGINT UNSIGNED → BIGINT (INT-BUG-1 fix)', $table )
                    );
                }
            }
        }

        // INT-BUG-5: add UNIQUE KEY `uniq_order` on `lt_donations.order_id`.
        $donations_table = $p . 'lt_donations';
        $donations_exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $donations_table
            )
        );
        if ( $donations_exists > 0 ) {
            // Check if the unique index already exists.
            $idx_exists = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM information_schema.STATISTICS
                      WHERE TABLE_SCHEMA = %s
                        AND TABLE_NAME   = %s
                        AND INDEX_NAME   = 'uniq_order'",
                    DB_NAME,
                    $donations_table
                )
            );

            if ( $idx_exists < 1 ) {
                // Check for duplicate order_id values first — if duplicates exist,
                // the ADD UNIQUE KEY would fail and abort the migration. Log a
                // CRITICAL warning so the admin can manually resolve duplicates,
                // and skip the index creation rather than failing the whole migration.
                $dup_count = (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM (
                        SELECT order_id
                          FROM `{$donations_table}`
                         GROUP BY order_id
                         HAVING COUNT(*) > 1
                     ) AS dup"
                );

                if ( $dup_count > 0 ) {
                    if ( class_exists( 'LTMS_Core_Logger' ) ) {
                        LTMS_Core_Logger::critical(
                            'DB_MIGRATION',
                            sprintf(
                                'v2.7.1: lt_donations tiene %d order_id duplicados — no se puede agregar UNIQUE KEY uniq_order. Resuelve los duplicados manualmente y re-ejecuta la migración.',
                                $dup_count
                            )
                        );
                    }
                } else {
                    $wpdb->query( "ALTER TABLE `{$donations_table}` ADD UNIQUE KEY `uniq_order` (`order_id`)" );
                    if ( class_exists( 'LTMS_Core_Logger' ) ) {
                        LTMS_Core_Logger::info(
                            'DB_MIGRATION',
                            'v2.7.1: lt_donations UNIQUE KEY uniq_order (order_id) agregado (INT-BUG-5 fix)'
                        );
                    }
                }
            }
        }
        // phpcs:enable
    }

    /**
     * Migración v2.8.0 — Cross-Border Commerce (Task 63-C).
     *
     * Crea las tablas lt_fx_rates y lt_customs_declarations usadas por el
     * panel de administración cross-border (LTMS_Admin_Cross_Border) y por
     * el motor de cálculo de aranceles.
     *
     * Aunque las tablas también se crean en create_tables() vía dbDelta
     * (que es idempotente), este método actúa como red de seguridad
     * explícita versionada: si dbDelta falla silenciosamente, el CREATE
     * TABLE IF NOT EXISTS directo asegura que las tablas existan antes
     * de que el admin panel intente consultarlas. El flag de versión
     * ltms_db_version garantiza que solo se ejecuta una vez por instalación.
     *
     * @return void
     */
    private static function migrate_2_8_0_cross_border(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $p       = $wpdb->prefix;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        // Table names are derived from $wpdb->prefix (trusted, server-set value).

        // lt_fx_rates — Tasas de cambio cacheadas + overrides manuales.
        $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$p}lt_fx_rates` (
            `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `base_currency`  VARCHAR(3) NOT NULL,
            `quote_currency` VARCHAR(3) NOT NULL,
            `rate`           DECIMAL(12,6) NOT NULL,
            `provider`       VARCHAR(32) NOT NULL DEFAULT 'frankfurter',
            `is_manual`      TINYINT(1) NOT NULL DEFAULT 0,
            `fetched_at`     DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_pair` (`base_currency`, `quote_currency`),
            KEY `idx_fetched` (`fetched_at`)
        ) {$charset}" );

        // lt_customs_declarations — Declaraciones aduaneras por orden.
        $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$p}lt_customs_declarations` (
            `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `order_id`            BIGINT UNSIGNED NOT NULL,
            `origin_country`      VARCHAR(2) NOT NULL,
            `destination_country` VARCHAR(2) NOT NULL,
            `hs_code`             VARCHAR(16) NOT NULL DEFAULT '',
            `cif_value`           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `duty_rate`           DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            `duty_amount`         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `vat_rate`            DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            `vat_amount`          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `customs_fee`         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `other_taxes`         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `total_duties`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `incoterm`            VARCHAR(8) NOT NULL DEFAULT 'DDU',
            `paid_by`             VARCHAR(16) NOT NULL DEFAULT 'buyer',
            `currency`            VARCHAR(3) NOT NULL DEFAULT 'USD',
            `declaration_number`  VARCHAR(64) NOT NULL DEFAULT '',
            `declaration_status`  VARCHAR(32) NOT NULL DEFAULT 'pending',
            `created_at`          DATETIME NOT NULL,
            `cleared_at`          DATETIME DEFAULT NULL,
            `metadata`            TEXT,
            PRIMARY KEY (`id`),
            KEY `idx_order` (`order_id`),
            KEY `idx_route` (`origin_country`, `destination_country`),
            KEY `idx_status` (`declaration_status`),
            KEY `idx_created_at` (`created_at`)
        ) {$charset}" );
        // phpcs:enable

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'DB_MIGRATION',
                'v2.8.0: lt_fx_rates + lt_customs_declarations OK (Cross-Border Commerce).'
            );
        }
    }

    /**
     * Migración v2.8.1 — Wallet Multi-Currency Fix (Task 65-C, WL-BUG-1).
     *
     * Cambia el UNIQUE KEY de `lt_vendor_wallets` de `vendor_id` solo a
     * un composite `(vendor_id, currency)`. Sin este cambio, el feature
     * cross-border multi-currency está completamente roto: un vendor solo
     * puede tener UNA wallet (la primera currency), y cualquier intento
     * de crear una wallet secundaria (USD cuando ya tiene COP, o viceversa)
     * falla con `errno 1062 duplicate key`. Esto bloquea get_wallets(),
     * get_balance_for_currency(), convert_balance() y credit_within_transaction().
     *
     * Idempotente: verifica si el composite index ya existe antes de hacer
     * el ALTER. Verifica también si hay duplicados (vendor_id con varias
     * filas) — si los hay, el ADD UNIQUE KEY fallaría y abortaría la
     * migración. En ese caso se loguea CRITICAL para que el admin resuelva
     * manualmente y se omite el ALTER (los demás pasos de la migración
     * continúan).
     *
     * @return void
     */
    private static function migrate_2_8_1_wallet_multicurrency(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $table = $p . 'lt_vendor_wallets';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        // Table names are derived from $wpdb->prefix (trusted, server-set value).

        // Verifica que la tabla exista.
        $exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $table
            )
        );
        if ( $exists < 1 ) {
            return; // La tabla se creará vía create_tables() con el schema ya corregido.
        }

        // Verifica si el composite index ya existe (idempotente).
        $composite_exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = %s
                    AND TABLE_NAME   = %s
                    AND INDEX_NAME   = 'udx_vendor_currency'",
                DB_NAME,
                $table
            )
        );
        if ( $composite_exists > 0 ) {
            return; // Ya migrado (composite index presente).
        }

        // Verifica si el viejo index udx_vendor_id (solo vendor_id) existe.
        $old_index_exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = %s
                    AND TABLE_NAME   = %s
                    AND INDEX_NAME   = 'udx_vendor_id'",
                DB_NAME,
                $table
            )
        );

        // Si existen vendor_id duplicados en la tabla actual, el ADD UNIQUE KEY
        // (vendor_id, currency) podría fallar si el duplicado tiene la MISMA
        // currency. Verificamos antes de aplicar el ALTER.
        $dup_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM (
                SELECT vendor_id, currency
                  FROM `{$table}`
                 GROUP BY vendor_id, currency
                 HAVING COUNT(*) > 1
             ) AS dup"
        );

        if ( $dup_count > 0 ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::critical(
                    'DB_MIGRATION',
                    sprintf(
                        'v2.8.1: lt_vendor_wallets tiene %d pares (vendor_id, currency) duplicados — no se puede agregar UNIQUE KEY udx_vendor_currency. Resuelve los duplicados manualmente y re-ejecuta la migración (WL-BUG-1).',
                        $dup_count
                    )
                );
            }
            return; // No aplicar el ALTER, pero dejar que la migración continúe.
        }

        // Drop del viejo index y ADD del composite. Hacerlos en una sola
        // sentencia para minimizar la ventana sin UNIQUE KEY (aunque el
        // DROP+ADD en un solo ALTER es atómico para InnoDB).
        if ( $old_index_exists > 0 ) {
            $wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `udx_vendor_id`, ADD UNIQUE KEY `udx_vendor_currency` (`vendor_id`, `currency`)" );
        } else {
            // El viejo index no existe (posible si la tabla fue creada manualmente
            // o ya estaba parcialmente migrada) — solo ADD el composite.
            $wpdb->query( "ALTER TABLE `{$table}` ADD UNIQUE KEY `udx_vendor_currency` (`vendor_id`, `currency`)" );
        }

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'DB_MIGRATION',
                'v2.8.1: lt_vendor_wallets UNIQUE KEY cambiado a (vendor_id, currency) — multi-currency wallets habilitadas (WL-BUG-1 fix).'
            );
        }
        // phpcs:enable
    }

    /**
     * Migración v2.8.3 — Shipping Cost Ledger & Reconciliation Engine.
     *
     * Crea 5 tablas para cerrar el flujo financiero de costos logísticos
     * absorbidos por la plataforma (Lo Tengo):
     *
     * 1. lt_shipping_cost_ledger     — 1 fila por servicio logístico (quote + real + varianza).
     * 2. lt_carrier_invoices         — Facturas mensuales de los carriers.
     * 3. lt_carrier_invoice_lines    — Líneas de factura (1 por guía).
     * 4. lt_shipping_disputes        — Disputas abiertas con el carrier por sobrecobro.
     * 5. lt_vendor_shipping_budgets  — Presupuesto mensual por vendor (soft/hard limit).
     *
     * @return void
     */
    private static function migrate_2_8_3_shipping_cost_ledger(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $p       = $wpdb->prefix;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        // 1. lt_shipping_cost_ledger — libro mayor de costos logísticos.
        $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$p}lt_shipping_cost_ledger` (
            `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `order_id`            BIGINT UNSIGNED NOT NULL,
            `order_item_id`       BIGINT UNSIGNED DEFAULT NULL COMMENT 'ID del shipping line item en WC',
            `vendor_id`           BIGINT NOT NULL COMMENT 'Vendedor que generó el envío (-1 = platform)',
            `carrier`             VARCHAR(32) NOT NULL COMMENT 'deprisa|heka|aveonline|uber|pickup|own_delivery|free_absorbed',
            `tracking_number`     VARCHAR(128) DEFAULT NULL,
            `quote_cost`          DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Costo cotizado al momento del checkout',
            `buyer_paid`          DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Monto cobrado al cliente (shipping_total)',
            `vendor_charged`      DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Monto debitado al vendor (modo absorbed)',
            `real_cost`           DECIMAL(15,2) DEFAULT NULL COMMENT 'Costo real facturado por carrier (NULL = sin conciliar)',
            `currency`            CHAR(3) NOT NULL DEFAULT 'COP',
            `country_code`        CHAR(2) NOT NULL DEFAULT 'CO',
            `status`              ENUM('quoted','shipped','delivered','invoiced','disputed','reconciled','writeoff') NOT NULL DEFAULT 'quoted',
            `invoice_id`          BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK a lt_carrier_invoices.id',
            `invoice_line_id`     BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK a lt_carrier_invoice_lines.id',
            `dispute_id`          BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK a lt_shipping_disputes.id',
            `variance`            DECIMAL(15,2) DEFAULT NULL COMMENT 'real_cost - quote_cost (calculado al conciliar)',
            `variance_pct`        DECIMAL(6,2) DEFAULT NULL COMMENT 'variance / quote_cost * 100',
            `wallet_tx_id`        BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK a lt_wallet_transactions.id (debit al vendor)',
            `platform_wallet_tx_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK a lt_wallet_transactions.id (costo en ledger Lo Tengo)',
            `quote_at`            DATETIME DEFAULT NULL,
            `shipped_at`          DATETIME DEFAULT NULL,
            `delivered_at`        DATETIME DEFAULT NULL,
            `invoiced_at`         DATETIME DEFAULT NULL,
            `reconciled_at`       DATETIME DEFAULT NULL,
            `metadata`            LONGTEXT DEFAULT NULL,
            `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `udx_order_item` (`order_id`, `order_item_id`),
            KEY `idx_vendor` (`vendor_id`),
            KEY `idx_carrier` (`carrier`),
            KEY `idx_status` (`status`),
            KEY `idx_tracking` (`tracking_number`),
            KEY `idx_invoice` (`invoice_id`),
            KEY `idx_dispute` (`dispute_id`),
            KEY `idx_created` (`created_at`)
        ) {$charset}" );

        // 2. lt_carrier_invoices — facturas mensuales recibidas de los carriers.
        $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$p}lt_carrier_invoices` (
            `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `carrier`          VARCHAR(32) NOT NULL,
            `invoice_number`   VARCHAR(64) NOT NULL COMMENT 'Número de factura del carrier',
            `invoice_date`     DATE NOT NULL,
            `period_start`     DATE NOT NULL COMMENT 'Inicio del período facturado',
            `period_end`       DATE NOT NULL COMMENT 'Fin del período facturado',
            `total_amount`     DECIMAL(15,2) NOT NULL,
            `currency`         CHAR(3) NOT NULL DEFAULT 'COP',
            `tax_amount`       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `subtotal_amount`  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `lines_count`      INT UNSIGNED NOT NULL DEFAULT 0,
            `lines_matched`    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Líneas matcheadas a un ledger entry',
            `lines_unmatched`  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Líneas sin match (phantom charges)',
            `matched_amount`   DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `unmatched_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `variance_total`   DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Sum(real_cost - quote_cost) de líneas matcheadas',
            `status`           ENUM('imported','matching','matched','partial','disputed','reconciled') NOT NULL DEFAULT 'imported',
            `source_file`      VARCHAR(255) DEFAULT NULL COMMENT 'Nombre del archivo CSV/JSON importado',
            `imported_by`      BIGINT UNSIGNED DEFAULT NULL,
            `imported_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `reconciled_at`    DATETIME DEFAULT NULL,
            `metadata`         LONGTEXT DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `udx_carrier_invoice` (`carrier`, `invoice_number`),
            KEY `idx_period` (`period_start`, `period_end`),
            KEY `idx_status` (`status`)
        ) {$charset}" );

        // 3. lt_carrier_invoice_lines — una fila por guía facturada por el carrier.
        $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$p}lt_carrier_invoice_lines` (
            `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `invoice_id`        BIGINT UNSIGNED NOT NULL,
            `line_number`       INT UNSIGNED NOT NULL COMMENT 'Posición en la factura',
            `tracking_number`   VARCHAR(128) DEFAULT NULL,
            `guide_number`      VARCHAR(128) DEFAULT NULL,
            `order_ref`         VARCHAR(64) DEFAULT NULL COMMENT 'Referencia del pedido si el carrier la incluye',
            `origin_city`       VARCHAR(64) DEFAULT NULL,
            `destination_city`  VARCHAR(64) DEFAULT NULL,
            `weight_kg`         DECIMAL(8,3) DEFAULT NULL,
            `billed_amount`     DECIMAL(15,2) NOT NULL,
            `tax_amount`        DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `total_amount`      DECIMAL(15,2) NOT NULL,
            `currency`          CHAR(3) NOT NULL DEFAULT 'COP',
            `ledger_id`         BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK a lt_shipping_cost_ledger.id (NULL = sin match)',
            `match_status`      ENUM('pending','matched','unmatched','disputed') NOT NULL DEFAULT 'pending',
            `match_method`      VARCHAR(32) DEFAULT NULL COMMENT 'tracking|order_ref|manual',
            `matched_at`        DATETIME DEFAULT NULL,
            `metadata`          LONGTEXT DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `udx_invoice_line` (`invoice_id`, `line_number`),
            KEY `idx_tracking` (`tracking_number`),
            KEY `idx_invoice` (`invoice_id`),
            KEY `idx_match_status` (`match_status`),
            KEY `idx_ledger` (`ledger_id`)
        ) {$charset}" );

        // 4. lt_shipping_disputes — disputas con carriers por sobrecobro.
        $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$p}lt_shipping_disputes` (
            `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `ledger_id`         BIGINT UNSIGNED NOT NULL COMMENT 'FK a lt_shipping_cost_ledger.id',
            `invoice_id`        BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK a lt_carrier_invoices.id',
            `invoice_line_id`   BIGINT UNSIGNED DEFAULT NULL,
            `dispute_type`      ENUM('overcharge','phantom_charge','duplicate','wrong_weight','wrong_zone','service_not_used','other') NOT NULL,
            `dispute_reason`    VARCHAR(500) NOT NULL,
            `evidence_url`      VARCHAR(500) DEFAULT NULL COMMENT 'URL del archivo de evidencia (PDF, screenshot)',
            `expected_amount`   DECIMAL(15,2) NOT NULL COMMENT 'Monto que LTMS considera correcto (quote_cost)',
            `disputed_amount`   DECIMAL(15,2) NOT NULL COMMENT 'Monto en disputa (real_cost - expected)',
            `credit_amount`     DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Crédito otorgado por el carrier',
            `status`            ENUM('open','in_review','approved','rejected','credited','expired') NOT NULL DEFAULT 'open',
            `opened_by`         BIGINT UNSIGNED NOT NULL,
            `opened_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `sla_due_at`        DATETIME NOT NULL COMMENT 'Fecha límite para resolver (default +15 días)',
            `resolved_at`       DATETIME DEFAULT NULL,
            `resolved_by`       BIGINT UNSIGNED DEFAULT NULL,
            `resolution_notes`  TEXT DEFAULT NULL,
            `metadata`          LONGTEXT DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_ledger` (`ledger_id`),
            KEY `idx_invoice` (`invoice_id`),
            KEY `idx_status` (`status`),
            KEY `idx_sla` (`sla_due_at`)
        ) {$charset}" );

        // 5. lt_vendor_shipping_budgets — presupuesto mensual por vendor.
        $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$p}lt_vendor_shipping_budgets` (
            `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `vendor_id`         BIGINT NOT NULL,
            `period_year`       SMALLINT UNSIGNED NOT NULL,
            `period_month`      TINYINT UNSIGNED NOT NULL COMMENT '1-12',
            `budget_limit`      DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT '0 = sin límite',
            `soft_threshold`    DECIMAL(5,2) NOT NULL DEFAULT 80.00 COMMENT '% del budget para alerta (default 80%)',
            `hard_threshold`    DECIMAL(5,2) NOT NULL DEFAULT 100.00 COMMENT '% del budget para bloqueo (default 100%)',
            `spent_amount`      DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Acumulado del mes (calculado)',
            `spent_pct`         DECIMAL(6,2) NOT NULL DEFAULT 0.00 COMMENT 'spent_amount / budget_limit * 100',
            `alert_sent`        TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = alerta soft enviada',
            `block_sent`        TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = notificación hard enviada',
            `is_blocked`        TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = vendor bloqueado de generar envíos absorbed',
            `notes`             TEXT DEFAULT NULL,
            `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `udx_vendor_period` (`vendor_id`, `period_year`, `period_month`),
            KEY `idx_period` (`period_year`, `period_month`),
            KEY `idx_blocked` (`is_blocked`)
        ) {$charset}" );
        // phpcs:enable

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'DB_MIGRATION',
                'v2.8.3: lt_shipping_cost_ledger + lt_carrier_invoices + lt_carrier_invoice_lines + lt_shipping_disputes + lt_vendor_shipping_budgets OK (Shipping Cost Ledger & Reconciliation Engine).'
            );
        }
    }

    /**
     * Migración v2.9.1 — Forensic Log + Retention Log tables.
     *
     * Crea las tablas lt_forensic_log y lt_retention_log que eran referenciadas
     * por class-ltms-forensic-log.php y class-ltms-retention-cron.php pero
     * nunca se creaban en la migración canónica (solo en un patch script
     * deploy/ltms-patch-db-v2-7-0.php que no se ejecutaba en instalaciones nuevas).
     *
     * Tablas creadas:
     * 1. lt_forensic_log — Log inmutable con hash chain (SHA-256) para cumplimiento
     *    forensic. Cada entry incluye entry_hash + prev_hash para detectar tampering.
     * 2. lt_retention_log — Auditoría del cron de retención de datos (SAGRILAFT/Ley 1581).
     *
     * @return void
     */
    private static function migrate_2_9_1_forensic_retention_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $p       = $wpdb->prefix;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        // 1. lt_forensic_log — Log forensic con hash chain (FL-1 fix).
        $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$p}lt_forensic_log` (
            `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `action`      VARCHAR(100) NOT NULL COMMENT 'Tipo de evento forensic (login_failed, kyc_viewed, etc.)',
            `user_id`     BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `ip`          VARCHAR(45) NOT NULL DEFAULT '',
            `user_agent`  VARCHAR(500) NOT NULL DEFAULT '',
            `request_uri` VARCHAR(500) NOT NULL DEFAULT '',
            `context`     LONGTEXT DEFAULT NULL COMMENT 'JSON con detalles del evento + __prev_hash + __entry_hash',
            `entry_hash`  CHAR(64) DEFAULT NULL COMMENT 'SHA-256 hash del entry actual (FL-1 fix)',
            `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_action` (`action`),
            KEY `idx_user_id` (`user_id`),
            KEY `idx_ip` (`ip`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_entry_hash` (`entry_hash`)
        ) {$charset}" );

        // 2. lt_retention_log — Auditoría del cron de retención.
        $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$p}lt_retention_log` (
            `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id`    BIGINT UNSIGNED NOT NULL,
            `action`     VARCHAR(50) NOT NULL COMMENT 'notified|swept|protected|skipped',
            `swept_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `metadata`   LONGTEXT DEFAULT NULL COMMENT 'JSON con detalles de qué se borró/retuvo',
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`),
            KEY `idx_action` (`action`),
            KEY `idx_swept_at` (`swept_at`)
        ) {$charset}" );
        // phpcs:enable

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'DB_MIGRATION',
                'v2.9.1: lt_forensic_log + lt_retention_log OK (Forensic hash chain + Retention audit).'
            );
        }
    }

    /**
     * Migración v2.9.8 — Columnas faltantes Art. 30-B CFF (SAT México).
     *
     * Añade a lt_commissions las columnas que la norma requiere pero que
     * no existían en el schema:
     *  - service_type (Fracción I inciso a: tipo de servicio/operación)
     *  - iva_retenido (Fracción II inciso f-vi: IVA retenido por plataforma)
     *  - ieps_retenido (Fracción II inciso f-vii: IEPS retenido por plataforma)
     *
     * Idempotente: verifica si la columna ya existe antes de hacer ALTER.
     *
     * @return void
     */
    private static function migrate_2_9_8_fiscal_30b_columns(): void {
        global $wpdb;
        $c = $wpdb->prefix . 'lt_commissions';

        $helper = static function ( string $table, string $column ): bool {
            global $wpdb;
            return (bool) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                    DB_NAME, $table, $column
                )
            );
        };

        // NF-1: service_type — tipo de servicio u operación (Art. 30-B frac. I inc. a).
        if ( ! $helper( $c, 'service_type' ) ) {
            $wpdb->query( "ALTER TABLE `{$c}` ADD COLUMN `service_type` VARCHAR(50) DEFAULT NULL COMMENT 'Tipo de servicio/operación (Art.30-B frac.I inc.a)' AFTER `type`" );
        }

        // NF-2a: iva_retenido — IVA retenido por la plataforma (Art. 30-B frac. II inc. f-vi).
        if ( ! $helper( $c, 'iva_retenido' ) ) {
            $wpdb->query( "ALTER TABLE `{$c}` ADD COLUMN `iva_retenido` DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'IVA retenido por plataforma (Art.30-B frac.II inc.f-vi)' AFTER `iva_amount`" );
        }

        // NF-2b: ieps_retenido — IEPS retenido por la plataforma (Art. 30-B frac. II inc. f-vii).
        if ( ! $helper( $c, 'ieps_retenido' ) ) {
            $wpdb->query( "ALTER TABLE `{$c}` ADD COLUMN `ieps_retenido` DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'IEPS retenido por plataforma (Art.30-B frac.II inc.f-vii)' AFTER `ieps_amount`" );
        }

        // NF-5: crear tabla lt_fiscal_access_credentials para SAT/DIAN online access.
        $fac = $wpdb->prefix . 'lt_fiscal_access_credentials';
        $charset = $wpdb->get_charset_collate();
        $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$fac}` (
            `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `authority`       VARCHAR(10) NOT NULL COMMENT 'SAT (MX) o DIAN (CO)',
            `username`        VARCHAR(100) NOT NULL,
            `password_hash`   VARCHAR(255) NOT NULL,
            `created_by`      BIGINT UNSIGNED NOT NULL,
            `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `revoked_at`      DATETIME DEFAULT NULL,
            `last_access_at`  DATETIME DEFAULT NULL,
            `access_count`    INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `udx_authority_username` (`authority`, `username`),
            KEY `idx_authority` (`authority`)
        ) {$charset}" );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'DB_MIGRATION',
                'v2.9.8: lt_commissions +service_type +iva_retenido +ieps_retenido + lt_fiscal_access_credentials OK (Art. 30-B CFF compliance).'
            );
        }
    }

    /**
     * v2.9.13 — PR-1 CRITICAL BUG FIX: lt_consent_log schema mismatch.
     *
     * The original migration (v2.3.0) created `lt_consent_log` with columns:
     *   purpose, policy_ver, ip_hash, channel, user_agent, meta_json
     *
     * But LTMS_Legal_Compliance::log_consent() and the guest checkout flow
     * in log_checkout_consent() insert into DIFFERENT column names:
     *   consent_type, accepted, ip_address, version, channel, user_agent
     *
     * Result: every $wpdb->insert to lt_consent_log SILENTLY FAILED
     * (MySQL accepts unknown columns only in IGNORE mode; in default mode
     * it raises an error that $wpdb->insert captures as last_error but
     * the calling code never checks).
     *
     * This means consent logging has been BROKEN since v2.3.0 — violating
     * Ley 1581/2012 art. 10 (CO) and LFPDPPP art. 11 (MX) which require
     * demonstrable proof of consent.
     *
     * Fix: ADD the missing columns (additive ALTER TABLE — does not drop
     * existing data). Going forward, log_consent() writes use the canonical
     * column names (consent_type, accepted, ip_address, version).
     *
     * @return void
     */
    private static function migrate_2_9_13_consent_log_schema_fix(): void {
        global $wpdb;
        $table    = $wpdb->prefix . 'lt_consent_log';
        $charset  = $wpdb->get_charset_collate();

        $helper = static function ( string $t, string $column ): bool {
            global $wpdb;
            return (bool) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                    DB_NAME, $t, $column
                )
            );
        };

        // PR-1a: consent_type — replaces ad-hoc usage of `purpose` for type key.
        if ( ! $helper( $table, 'consent_type' ) ) {
            $wpdb->query(
                "ALTER TABLE `{$table}` ADD COLUMN `consent_type` VARCHAR(64) NOT NULL DEFAULT 'register'
                 COMMENT 'Tipo de consentimiento (terms, privacy, sagrilaft, checkout, marketing, data_rectification, etc.)'
                 AFTER `user_id`"
            );
        }

        // PR-1b: accepted — boolean flag (Ley 1581 art. 9 requires consent to be
        // affirmative, so storing the bool is critical evidence).
        if ( ! $helper( $table, 'accepted' ) ) {
            $wpdb->query(
                "ALTER TABLE `{$table}` ADD COLUMN `accepted` TINYINT(1) NOT NULL DEFAULT 1
                 COMMENT '1 = aceptado, 0 = rechazado/revocado (Ley 1581 art. 9)'
                 AFTER `consent_type`"
            );
        }

        // PR-1c: version — version of the document the user consented to
        // (terms v4.1, privacy v1.3, sagrilaft v1.0, etc.). Required for
        // demonstrable consent under GDPR art. 7(1).
        if ( ! $helper( $table, 'version' ) ) {
            $wpdb->query(
                "ALTER TABLE `{$table}` ADD COLUMN `version` VARCHAR(16) NOT NULL DEFAULT '1.0'
                 COMMENT 'Versión del documento consentido (TERMS_VERSION, PRIVACY_VERSION, etc.)'
                 AFTER `accepted`"
            );
        }

        // PR-1d: ip_address — raw IP (NOT hashed). The original schema used
        // ip_hash for pseudonymization, but forensic/evidence requirements
        // (GDPR art. 7(1), Ley 1581 art. 10 lit. d) demand the original IP
        // be retained for the duration of the consent relationship. The
        // ip_hash column is retained for backward compatibility.
        if ( ! $helper( $table, 'ip_address' ) ) {
            $wpdb->query(
                "ALTER TABLE `{$table}` ADD COLUMN `ip_address` VARCHAR(45) NOT NULL DEFAULT ''
                 COMMENT 'IP del usuario al consentir (IPv4/IPv6). Retenido como evidencia.'
                 AFTER `version`"
            );
        }

        // PR-1e: Backfill ip_hash from ip_address for legacy rows (no-op if
        // both are empty, which is the case for the previously-failed inserts).
        $wpdb->query(
            "UPDATE `{$table}` SET `consent_type` = `purpose` WHERE `consent_type` = 'register' AND `purpose` <> 'register'"
        );
        $wpdb->query(
            "UPDATE `{$table}` SET `version` = `policy_ver` WHERE `version` = '1.0' AND `policy_ver` <> '2.0'"
        );

        // PR-1f: Add composite index for the canonical access pattern
        // (arco_access queries by user_id ORDER BY created_at DESC).
        $idx_exists = (bool) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s',
                DB_NAME, $table, 'idx_user_consent_type'
            )
        );
        if ( ! $idx_exists ) {
            $wpdb->query(
                "ALTER TABLE `{$table}` ADD INDEX `idx_user_consent_type` (`user_id`, `consent_type`)"
            );
        }

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'DB_MIGRATION',
                'v2.9.13 PR-1: lt_consent_log schema fix — added consent_type, accepted, version, ip_address columns + idx_user_consent_type (Ley 1581 art. 10 / LFPDPPP art. 11 / GDPR art. 7(1) compliance).'
            );
        }
    }


}
