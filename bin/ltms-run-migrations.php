<?php
/**
 * Script de migraciones + setup para staging.
 * Ejecutar: wp eval-file bin/ltms-run-migrations.php --allow-root
 */
if ( ! defined( 'ABSPATH' ) ) { die; }

if ( function_exists( 'opcache_reset' ) ) {
    opcache_reset();
    echo "[OK] OPcache flushed\n";
}

if ( class_exists( 'LTMS_DB_Migrations' ) ) {
    LTMS_DB_Migrations::run();
    echo "[OK] Migrations executed\n";
} else {
    echo "[WARN] LTMS_DB_Migrations not found\n";
}

$cron_hook = 'ltms_daily_cron';
if ( ! wp_next_scheduled( $cron_hook ) ) {
    wp_schedule_event( time(), 'daily', $cron_hook );
    echo "[OK] $cron_hook scheduled\n";
} else {
    echo "[OK] $cron_hook already scheduled\n";
}

echo "[DONE]\n";
