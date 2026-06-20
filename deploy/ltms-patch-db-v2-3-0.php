<?php
/**
 * LTMS Emergency DB Patch — v2.3.0
 * Corrige: vendor_net en bkr_lt_bookings, lt_aveonline_cities,
 *          lt_consent_log, lt_vault_access_log.
 *
 * Uso: wp --allow-root --path=/ruta/wordpress eval-file deploy/ltms-patch-db-v2-3-0.php
 *
 * Idempotente — seguro de correr varias veces.
 */

if ( ! defined( 'ABSPATH' ) ) {
    // Cargado fuera de WP: salir.
    exit( 'Ejecutar via WP-CLI: wp eval-file deploy/ltms-patch-db-v2-3-0.php' );
}

global $wpdb;
$charset = $wpdb->get_charset_collate();
$p       = $wpdb->prefix;

echo "=== LTMS DB Patch v2.3.0 ===\n";

// ── 1. vendor_net ─────────────────────────────────────────────────────────────
$bookings_table = $p . 'lt_bookings';
$col_exists = $wpdb->get_results( $wpdb->prepare(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'vendor_net'",
    DB_NAME, $bookings_table
) );
if ( empty( $col_exists ) ) {
    $result = $wpdb->query(
        "ALTER TABLE `{$bookings_table}`
         ADD COLUMN `vendor_net` DECIMAL(15,2) DEFAULT 0.00
         COMMENT 'Neto al vendedor tras comisión'
         AFTER `balance_amount`"
    );
    echo ( $result !== false ) ? "✅ vendor_net agregada a {$bookings_table}\n" : "❌ ERROR agregando vendor_net: {$wpdb->last_error}\n";
} else {
    echo "✅ vendor_net ya existe en {$bookings_table}\n";
}

// ── 2. lt_aveonline_cities ────────────────────────────────────────────────────
$result = $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$p}lt_aveonline_cities` (
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
$msg = $result !== false ? "✅" : "❌ ({$wpdb->last_error})";
echo "{$msg} lt_aveonline_cities\n";

// ── 3. lt_consent_log ─────────────────────────────────────────────────────────
$result = $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$p}lt_consent_log` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `purpose`    VARCHAR(64)     NOT NULL DEFAULT 'register',
    `policy_ver` VARCHAR(16)     NOT NULL DEFAULT '2.0',
    `ip_hash`    VARCHAR(64)     NOT NULL DEFAULT '',
    `channel`    VARCHAR(32)     NOT NULL DEFAULT 'web',
    `user_agent` VARCHAR(255)    NOT NULL DEFAULT '',
    `meta_json`  TEXT,
    `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY  (`id`),
    KEY          `idx_user_purpose` (`user_id`, `purpose`),
    KEY          `idx_created_at`   (`created_at`)
) {$charset}" );
$msg = $result !== false ? "✅" : "❌ ({$wpdb->last_error})";
echo "{$msg} lt_consent_log\n";

// ── 4. lt_vault_access_log ────────────────────────────────────────────────────
$result = $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$p}lt_vault_access_log` (
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
$msg = $result !== false ? "✅" : "❌ ({$wpdb->last_error})";
echo "{$msg} lt_vault_access_log\n";

// ── 5. Actualizar ltms_db_version ─────────────────────────────────────────────
update_option( 'ltms_db_version', '2.3.0' );
echo "✅ ltms_db_version actualizado a 2.3.0\n";
echo "=== Patch completado. ===\n";
