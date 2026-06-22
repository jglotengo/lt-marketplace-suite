<?php
/**
 * LTMS Emergency DB Patch v2.7.0
 *
 * Resuelve 4 bugs de desincronización entre el esquema real de las tablas
 * y las columnas que el código PHP intenta escribir:
 *
 * 1. lt_consent_log   → agrega consent_type, accepted, version (faltan)
 * 2. lt_vault_access_log → agrega accessor_id, document (faltan)
 * 3. lt_logs          → crea la tabla completa (no existía en migrations.php)
 * 4. yith_vendors_commissions → no se toca (columna order_item_id existe
 *    en la tabla de YITH, el error es de lectura no de schema de LTMS)
 *
 * Uso:
 *   wp --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html \
 *      eval-file deploy/ltms-patch-db-v2-7-0.php
 *
 * Idempotente — seguro de correr varias veces.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit( 'Ejecutar via WP-CLI eval-file.' );
}

global $wpdb;
$p       = $wpdb->prefix;
$charset = $wpdb->get_charset_collate();

echo "=== LTMS DB Patch v2.7.0 ===\n";

// ──────────────────────────────────────────────────────────────────
// 1. lt_consent_log — agrega columnas que usa class-ltms-legal-compliance.php
//    El INSERT escribe: user_id, consent_type, accepted, ip_address,
//    user_agent, version, channel, created_at
//    La tabla original (v2.3.0) tenía: user_id, purpose, policy_ver,
//    ip_hash, channel, user_agent, meta_json, created_at
// ──────────────────────────────────────────────────────────────────
$table = $p . 'lt_consent_log';

$has_consent_type = $wpdb->get_var( $wpdb->prepare(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'consent_type'",
    DB_NAME, $table
) );
if ( ! $has_consent_type ) {
    $r = $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `consent_type` VARCHAR(64) NOT NULL DEFAULT 'general' AFTER `user_id`" );
    echo ( false !== $r ? 'OK' : 'ERROR: ' . $wpdb->last_error ) . " — consent_type en lt_consent_log\n";
} else {
    echo "OK — consent_type ya existe en lt_consent_log\n";
}

$has_accepted = $wpdb->get_var( $wpdb->prepare(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'accepted'",
    DB_NAME, $table
) );
if ( ! $has_accepted ) {
    $r = $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `accepted` TINYINT(1) NOT NULL DEFAULT 1 AFTER `consent_type`" );
    echo ( false !== $r ? 'OK' : 'ERROR: ' . $wpdb->last_error ) . " — accepted en lt_consent_log\n";
} else {
    echo "OK — accepted ya existe en lt_consent_log\n";
}

$has_version = $wpdb->get_var( $wpdb->prepare(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'version'",
    DB_NAME, $table
) );
if ( ! $has_version ) {
    $r = $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `version` VARCHAR(20) NOT NULL DEFAULT '1.0' AFTER `accepted`" );
    echo ( false !== $r ? 'OK' : 'ERROR: ' . $wpdb->last_error ) . " — version en lt_consent_log\n";
} else {
    echo "OK — version ya existe en lt_consent_log\n";
}

$has_ip = $wpdb->get_var( $wpdb->prepare(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'ip_address'",
    DB_NAME, $table
) );
if ( ! $has_ip ) {
    $r = $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `ip_address` VARCHAR(45) NOT NULL DEFAULT '' AFTER `version`" );
    echo ( false !== $r ? 'OK' : 'ERROR: ' . $wpdb->last_error ) . " — ip_address en lt_consent_log\n";
} else {
    echo "OK — ip_address ya existe en lt_consent_log\n";
}

// ──────────────────────────────────────────────────────────────────
// 2. lt_vault_access_log — agrega accessor_id y document
//    El INSERT escribe: user_id, accessor_id, document, action,
//    ip_address, user_agent, context, created_at
//    La tabla original (v2.3.0) tenía: user_id, actor_id, action,
//    field_name, context, ip_address, user_agent, created_at
// ──────────────────────────────────────────────────────────────────
$vtable = $p . 'lt_vault_access_log';

$has_accessor = $wpdb->get_var( $wpdb->prepare(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'accessor_id'",
    DB_NAME, $vtable
) );
if ( ! $has_accessor ) {
    $r = $wpdb->query( "ALTER TABLE `{$vtable}` ADD COLUMN `accessor_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'Quien accede (alias de actor_id)' AFTER `user_id`" );
    echo ( false !== $r ? 'OK' : 'ERROR: ' . $wpdb->last_error ) . " — accessor_id en lt_vault_access_log\n";
} else {
    echo "OK — accessor_id ya existe en lt_vault_access_log\n";
}

$has_document = $wpdb->get_var( $wpdb->prepare(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'document'",
    DB_NAME, $vtable
) );
if ( ! $has_document ) {
    $r = $wpdb->query( "ALTER TABLE `{$vtable}` ADD COLUMN `document` VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'Nombre del campo/doc accedido' AFTER `accessor_id`" );
    echo ( false !== $r ? 'OK' : 'ERROR: ' . $wpdb->last_error ) . " — document en lt_vault_access_log\n";
} else {
    echo "OK — document ya existe en lt_vault_access_log\n";
}

// ──────────────────────────────────────────────────────────────────
// 3. lt_logs — tabla de trazabilidad forense para commission-writer
//    No existía en ninguna migración anterior
// ──────────────────────────────────────────────────────────────────
$ltable = $p . 'lt_logs';
$exists = $wpdb->get_var( "SHOW TABLES LIKE '{$ltable}'" );
if ( $exists !== $ltable ) {
    $r = $wpdb->query( "CREATE TABLE `{$ltable}` (
        `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `event_type`  VARCHAR(80)     NOT NULL DEFAULT '',
        `object_id`   BIGINT UNSIGNED DEFAULT NULL,
        `object_type` VARCHAR(40)     DEFAULT NULL,
        `user_id`     BIGINT UNSIGNED DEFAULT NULL,
        `message`     TEXT,
        `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_event`   (`event_type`),
        KEY `idx_object`  (`object_id`, `object_type`),
        KEY `idx_user`    (`user_id`),
        KEY `idx_created` (`created_at`)
    ) {$charset}" );
    echo ( false !== $r ? 'OK' : 'ERROR: ' . $wpdb->last_error ) . " — lt_logs creada\n";
} else {
    echo "OK — lt_logs ya existe\n";
}

// ──────────────────────────────────────────────────────────────────
// 4. yith_vendors_commissions / order_item_id
//    Esta es una tabla de YITH (plugin de terceros). LTMS solo hace
//    un SELECT con order_item_id — si la tabla no tiene esa columna,
//    la query silenciosamente devuelve null y LTMS cae al fallback
//    post_author. No requiere ALTER; verificamos si la columna existe
//    para documentar el estado.
// ──────────────────────────────────────────────────────────────────
$ytable = $p . 'yith_vendors_commissions';
$yexists = $wpdb->get_var( "SHOW TABLES LIKE '{$ytable}'" );
if ( $yexists === $ytable ) {
    $yhas = $wpdb->get_var( $wpdb->prepare(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'order_item_id'",
        DB_NAME, $ytable
    ) );
    echo ( $yhas ? 'OK' : 'INFO (no bloqueante)' ) . " — yith_vendors_commissions.order_item_id " . ( $yhas ? "presente" : "ausente (YITH tabla — fallback activo)" ) . "\n";
} else {
    echo "INFO — yith_vendors_commissions no existe (YITH no instalado)\n";
}

// ──────────────────────────────────────────────────────────────────
// Bump de versión
// ──────────────────────────────────────────────────────────────────
update_option( 'ltms_db_version', '2.7.0' );
echo "OK ltms_db_version -> 2.7.0\n";
echo "=== Patch v2.7.0 completado ===\n";
