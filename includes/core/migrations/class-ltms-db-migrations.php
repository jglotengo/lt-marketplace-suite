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
     */
    private const CURRENT_VERSION = '1.7.0';

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
        $sqls[] = "CREATE TABLE `{$p}lt_vendor_wallets` (
            `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `vendor_id`         BIGINT UNSIGNED NOT NULL,
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
            UNIQUE KEY `udx_vendor_id` (`vendor_id`)
        ) {$charset}";

        // lt_wallet_transactions
        $sqls[] = "CREATE TABLE `{$p}lt_wallet_transactions` (
            `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `wallet_id`       BIGINT UNSIGNED NOT NULL,
            `vendor_id`       BIGINT UNSIGNED NOT NULL,
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
            `product_id`        BIGINT UNSIGNED NOT NULL,
            `gross_amount`      DECIMAL(15,2) NOT NULL,
            `commission_rate`   DECIMAL(5,4) NOT NULL,
            `commission_amount` DECIMAL(15,2) NOT NULL,
            `vendor_amount`     DECIMAL(15,2) NOT NULL,
            `tax_withholding`   DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `iva_amount`        DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `currency`          CHAR(3) NOT NULL DEFAULT 'COP',
            `country_code`      CHAR(2) NOT NULL DEFAULT 'CO',
            `status`            ENUM('pending','approved','paid','reversed','disputed') NOT NULL DEFAULT 'pending',
            `paid_at`           DATETIME DEFAULT NULL,
            `strategy_applied`  VARCHAR(100) DEFAULT NULL,
            `metadata`          LONGTEXT DEFAULT NULL,
            `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_order_id` (`order_id`),
            KEY `idx_vendor_id` (`vendor_id`),
            KEY `idx_status` (`status`)
        ) {$charset}";

        // lt_payout_requests
        $sqls[] = "CREATE TABLE `{$p}lt_payout_requests` (
            `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `vendor_id`        BIGINT UNSIGNED NOT NULL,
            `wallet_id`        BIGINT UNSIGNED NOT NULL,
            `amount`           DECIMAL(15,2) NOT NULL,
            `currency`         CHAR(3) NOT NULL DEFAULT 'COP',
            `method`           ENUM('bank_transfer','paypal','nequi','daviplata','spei','clabe') NOT NULL DEFAULT 'bank_transfer',
            `bank_account`     LONGTEXT NOT NULL,
            `status`           ENUM('pending','approved','processing','completed','rejected','cancelled') NOT NULL DEFAULT 'pending',
            `admin_notes`      TEXT DEFAULT NULL,
            `rejection_reason` VARCHAR(500) DEFAULT NULL,
            `transaction_id`   BIGINT UNSIGNED DEFAULT NULL,
            `external_ref`     VARCHAR(255) DEFAULT NULL,
            `processed_by`     BIGINT UNSIGNED DEFAULT NULL,
            `requested_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `processed_at`     DATETIME DEFAULT NULL,
            `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_vendor_id` (`vendor_id`),
            KEY `idx_status` (`status`)
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
            `verified_by`      BIGINT UNSIGNED DEFAULT NULL,
            `verified_at`      DATETIME DEFAULT NULL,
            `rejection_reason` VARCHAR(500) DEFAULT NULL,
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
            `referrer_id`      BIGINT UNSIGNED NOT NULL,
            `level`            TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `referral_code`    VARCHAR(50) NOT NULL,
            `source`           VARCHAR(100) DEFAULT NULL,
            `total_sales`      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `total_commission` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `status`           ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
            `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `udx_vendor_referrer` (`vendor_id`, `referrer_id`),
            KEY `idx_referral_code` (`referral_code`)
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

        // lt_vendor_drivers — Domiciliarios propios del vendedor
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_vendor_drivers` (
            `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `vendor_id`        BIGINT UNSIGNED NOT NULL,
            `name`             VARCHAR(100) NOT NULL,
            `phone`            VARCHAR(20)  NOT NULL,
            `document_number`  VARCHAR(500) DEFAULT NULL COMMENT 'Cifrado AES-256',
            `vehicle_type`     ENUM('moto','bici','carro','pie') NOT NULL DEFAULT 'moto',
            `vehicle_plate`    VARCHAR(500) DEFAULT NULL COMMENT 'Cifrado AES-256',
            `is_active`        TINYINT(1)   NOT NULL DEFAULT 1,
            `is_available`     TINYINT(1)   NOT NULL DEFAULT 1,
            `current_order_id` BIGINT UNSIGNED DEFAULT NULL,
            `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_vendor_active` (`vendor_id`, `is_active`, `is_available`)
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
        $sqls[] = "CREATE TABLE IF NOT EXISTS `{$p}lt_co_reteica_rates` (
            `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `ciiu_prefix`       CHAR(1)      NOT NULL,
            `description`       VARCHAR(200) NOT NULL,
            `rate_per_thousand` DECIMAL(8,4) NOT NULL,
            `valid_from`        DATE         NOT NULL,
            PRIMARY KEY (`id`)
        ) {$charset}";

        foreach ( $sqls as $sql ) {
            dbDelta( $sql );
        }

        // Insert default commission tiers if table is empty
        self::seed_commission_tiers();
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
     * Crea índices adicionales para optimización de consultas frecuentes.
     *
     * @return void
     */
    private static function create_indexes(): void {
        global $wpdb;

        // DDL statements (ALTER TABLE) cannot use parameterized placeholders.
        // Table names are derived exclusively from $wpdb->prefix (trusted, server-set value).
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $p = $wpdb->prefix;
        $indexes = [
            // Existing single-column indexes
            "ALTER TABLE `{$p}lt_wallet_transactions` ADD INDEX IF NOT EXISTS `idx_created_at` (`created_at`)",
            "ALTER TABLE `{$p}lt_commissions`         ADD INDEX IF NOT EXISTS `idx_paid_at`    (`paid_at`)",

            // PERF: composite indexes for common query patterns
            // notifications: user_id + is_read + created_at (dashboard unread count + list)
            "ALTER TABLE `{$p}lt_notifications` ADD INDEX IF NOT EXISTS `idx_user_unread` (`user_id`, `is_read`, `created_at`)",
            // wallet_transactions: vendor_id + type + created_at (commission reports, payout history)
            "ALTER TABLE `{$p}lt_wallet_transactions` ADD INDEX IF NOT EXISTS `idx_vendor_type_date` (`vendor_id`, `type`, `created_at`)",
            // waf_blocked_ips: expires_at (cleanup cron queries)
            "ALTER TABLE `{$p}lt_waf_blocked_ips` ADD INDEX IF NOT EXISTS `idx_expires_at` (`expires_at`)",
            // commissions: vendor_id + status + created_at (vendor commission reports)
            "ALTER TABLE `{$p}lt_commissions` ADD INDEX IF NOT EXISTS `idx_vendor_status_date` (`vendor_id`, `status`, `created_at`)",
            // audit_logs: user_id + created_at (per-user audit trail)
            "ALTER TABLE `{$p}lt_audit_logs` ADD INDEX IF NOT EXISTS `idx_user_date` (`user_id`, `created_at`)",
            // api_logs: provider + created_at (provider health dashboard)
            "ALTER TABLE `{$p}lt_api_logs` ADD INDEX IF NOT EXISTS `idx_provider_date` (`provider`, `created_at`)",
        ];

        foreach ( $indexes as $sql ) {
            $wpdb->query( $sql ); // Ignore duplicate-index errors (idempotent).
        }
        // phpcs:enable
    }
}
