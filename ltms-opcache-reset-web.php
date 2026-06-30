<?php
/**
 * LTMS — Reset de OPcache via HTTP (proceso PHP-FPM real, no CLI).
 *
 * Uso temporal de diagnóstico: wp-cli y PHP-FPM corren en pools de
 * OPcache separados en este hosting compartido. opcache_reset() desde
 * `wp eval-file` no limpia el cache que usan las peticiones web reales
 * (incluido el webhook de ZapSign), causando que el código deployado
 * por git no se refleje en producción hasta que expire el TTL del cache.
 *
 * ELIMINAR este archivo después de confirmar el fix (no debe quedar
 * un endpoint público sin autenticación en producción).
 */

$secret = 'ltms-opcache-reset-bc01-temp-2026';

if ( ( $_GET['key'] ?? '' ) !== $secret ) {
    http_response_code( 403 );
    echo "forbidden\n";
    exit;
}

header( 'Content-Type: text/plain' );

if ( function_exists( 'opcache_reset' ) ) {
    $result = opcache_reset();
    echo $result ? "opcache_reset() OK\n" : "opcache_reset() devolvio false (opcache puede estar deshabilitado o restringido)\n";
} else {
    echo "opcache_reset() no disponible en este proceso PHP\n";
}

if ( function_exists( 'opcache_get_status' ) ) {
    $status = opcache_get_status( false );
    echo "opcache_enabled: " . ( $status['opcache_enabled'] ?? 'desconocido' ) . "\n";
    echo "cache_full: " . ( $status['cache_full'] ?? 'desconocido' ) . "\n";
    echo "num_cached_scripts: " . ( $status['opcache_statistics']['num_cached_scripts'] ?? 'desconocido' ) . "\n";
}

echo "\nPHP SAPI: " . php_sapi_name() . "\n";
echo "Timestamp: " . date( 'Y-m-d H:i:s' ) . "\n";
