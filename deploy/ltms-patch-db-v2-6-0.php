<?php
/**
 * LTMS Emergency DB Patch v2.6.0
 *
 * Recrea la tabla lt_deposits con el esquema correcto para depósitos manuales.
 * La tabla original tenía esquema de garantías de torneo (incompatible).
 *
 * Idempotente: seguro de correr varias veces.
 *
 * Uso:
 *   wp --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html \
 *      eval-file /home/customer/.../lt-marketplace-suite/deploy/ltms-patch-db-v2-6-0.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit( 'Ejecutar via WP-CLI eval-file.' );
}

global $wpdb;
$p       = $wpdb->prefix;
$charset = $wpdb->get_charset_collate();

echo "=== LTMS DB Patch v2.6.0 ===\n";

// Verificar si la tabla ya tiene el esquema correcto
$col = $wpdb->get_results( $wpdb->prepare(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'method'",
    DB_NAME, $p . 'lt_deposits'
) );

if ( empty( $col ) ) {
    // Tabla existe con esquema de garantías — renombrar
    $existing = $wpdb->get_var( $wpdb->prepare(
        "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
        DB_NAME, $p . 'lt_deposits'
    ) );

    if ( $existing ) {
        $r = $wpdb->query( "RENAME TABLE `{$p}lt_deposits` TO `{$p}lt_deposits_garantias`" );
        echo ( false !== $r ? 'OK' : 'ERROR: ' . $wpdb->last_error ) . " — lt_deposits → lt_deposits_garantias\n";
    } else {
        echo "OK — lt_deposits no existía, creando desde cero\n";
    }

    // Crear la tabla correcta
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

    $verify = $wpdb->get_results( $wpdb->prepare(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'method'",
        DB_NAME, $p . 'lt_deposits'
    ) );
    echo ( ! empty( $verify ) ? 'OK' : 'ERROR: tabla creada pero columna method no encontrada' ) . " — lt_deposits creada con esquema correcto\n";

} else {
    echo "OK — lt_deposits ya tiene columna method (esquema correcto, nada que hacer)\n";
}

update_option( 'ltms_db_version', '2.6.0' );
echo "OK ltms_db_version -> 2.6.0\n";
echo "=== Patch v2.6.0 completado ===\n";
