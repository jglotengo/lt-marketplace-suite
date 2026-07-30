<?php
/**
 * LTMS Backfill — Re-cifra los plaintext de `lt_vendor_kyc.bank_account_number`
 * legacy (pre-v2.9.16) y los sincroniza a `ltms_bank_account_number` user_meta.
 *
 * Contexto: el fix v2.9.298 (c54ac9f7) guardó bank_account_number en plaintext
 * en la tabla KYC (porque VARCHAR(50) no aguantaba el ciphertext AES-256-GCM
 * de ~65 chars). El fix v2.9.316 (KYC-AUDIT2-01) restauró el ciphertext como
 * single-source-of-truth via ALTER TABLE VARCHAR(80) + MODIFY COLUMN.
 *
 * Esta migración corre automáticamente en el activation hook del plugin, PERO
 * también se puede ejecutar manualmente para forzar el re-cifrado fuera de una
 * activación (p.ej. tras un deploy a producción sin re-activar el plugin).
 *
 * Uso:
 *   wp eval-file bin/ltms-backfill-kyc-ciphertext.php --allow-root
 *   php bin/ltms-backfill-kyc-ciphertext.php  (con wp-load.php accesible)
 *
 * Idempotente: solo actúa sobre filas cuyo value NO empieza con 'v2:'.
 *
 * @version 1.0.0  |  2026-07-29  |  KYC-AUDIT2-01
 */

if ( ! defined( 'ABSPATH' ) ) {
    // Cargar WordPress si se ejecuta directamente.
    $wp_load = dirname( __FILE__, 3 ) . '/wp-load.php';
    if ( file_exists( $wp_load ) ) {
        require_once $wp_load;
    } else {
        echo "ERROR: No se pudo cargar wp-load.php\n";
        exit( 1 );
    }
}

global $wpdb;

echo "=== LTMS Backfill v2.9.316 — KYC-AUDIT2-01 ciphertext migration ===\n\n";

if ( ! class_exists( 'LTMS_Core_Security' ) || ! method_exists( 'LTMS_Core_Security', 'encrypt' ) ) {
    echo "ERROR: LTMS_Core_Security::encrypt() no disponible. Load el plugin antes de correr.\n";
    exit( 1 );
}

$k = $wpdb->prefix . 'lt_vendor_kyc';

if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $k ) ) !== $k ) {
    echo "ERROR: Tabla {$k} no existe.\n";
    exit( 1 );
}

// 1. ALTER COLUMN a VARCHAR(80) si no lo es ya.
$col_info = $wpdb->get_row(
    $wpdb->prepare(
        'SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
        DB_NAME, $k, 'bank_account_number'
    )
);
$current_max = (int) ( $col_info->CHARACTER_MAXIMUM_LENGTH ?? 0 );
if ( $current_max > 0 && $current_max < 80 ) {
    $wpdb->query( "ALTER TABLE `{$k}` MODIFY COLUMN `bank_account_number` VARCHAR(80) DEFAULT NULL COMMENT 'CLABE/cuenta cifrada AES-256-GCM'" );
    echo "✓ ALTER TABLE bank_account_number VARCHAR({$current_max}) → VARCHAR(80)\n";
} else {
    echo "✓ Columna ya es VARCHAR({$current_max}) — no se necesita ALTER\n";
}

// 2. Re-cifrar plaintext existentes.
$rows = $wpdb->get_results(
    "SELECT id, vendor_id, bank_account_number FROM `{$k}`
      WHERE bank_account_number IS NOT NULL
        AND bank_account_number != ''
        AND bank_account_number NOT LIKE 'v2:%'"
);
$total = count( $rows );
echo "\nRegistros a re-cifrar: {$total}\n";

if ( ! $total ) {
    echo "✓ No hay plaintext legacy — todo ya es ciphertext.\n";
    exit( 0 );
}

$reencrypted = 0;
$failed      = 0;
foreach ( $rows as $row ) {
    $plain = (string) $row->bank_account_number;
    try {
        $cipher = LTMS_Core_Security::encrypt( $plain );
        if ( ! $cipher ) {
            echo "  ✗ KYC #{$row->id}: encrypt() retornó vacío\n";
            $failed++;
            continue;
        }
        $wpdb->update(
            $k,
            [ 'bank_account_number' => $cipher ],
            [ 'id' => (int) $row->id ],
            [ '%s' ],
            [ '%d' ]
        );
        update_user_meta( (int) $row->vendor_id, 'ltms_bank_account_number', $cipher );
        $reencrypted++;
        echo "  ✓ KYC #{$row->id} (vendor #{$row->vendor_id}) re-cifrado\n";
    } catch ( \Throwable $e ) {
        echo "  ✗ KYC #{$row->id}: " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "\n=== Resultado ===\n";
echo "  Re-cifrados: {$reencrypted}\n";
echo "  Fallidos:    {$failed}\n";

if ( class_exists( 'LTMS_Core_Logger' ) ) {
    LTMS_Core_Logger::info(
        'BACKFILL_KYC_CIPHERTEXT',
        sprintf( 'Re-cifrado manual: %d OK, %d fallidos de %d totales.', $reencrypted, $failed, $total )
    );
}

exit( $failed > 0 ? 1 : 0 );
