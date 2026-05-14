#!/usr/bin/env php
<?php
/**
 * ltms-store-google-secret.php
 *
 * Guarda las credenciales de Google OAuth cifradas en wp_options.
 *
 * USO RECOMENDADO (WP-CLI, carga todo el stack):
 *   LTMS_GOOGLE_ID='1023771531407-xxx.apps.googleusercontent.com' \
 *   LTMS_GOOGLE_SECRET='GOCSPX-...' \
 *   wp --path=/home/customer/www/lo-tengo.com.co/public_html \
 *      eval-file bin/ltms-store-google-secret.php --allow-root
 *
 * USO ALTERNATIVO (PHP directo, pasa la ruta a wp-config como argumento):
 *   LTMS_GOOGLE_SECRET='GOCSPX-...' \
 *   php bin/ltms-store-google-secret.php /ruta/a/wp-config.php
 */

// ── Modo WP-CLI (ABSPATH ya definido por wp eval-file) ───────────────────────
if ( ! defined( 'ABSPATH' ) ) {
    // Modo PHP directo — hacer bootstrap de WordPress
    if ( PHP_SAPI !== 'cli' ) {
        exit( "Solo para CLI.\n" );
    }
    $wp_config = $argv[1] ?? '';
    if ( ! file_exists( $wp_config ) ) {
        // Intentar ubicación estándar relativa al plugin
        $wp_config = dirname( __DIR__, 3 ) . '/wp-config.php';
    }
    if ( ! file_exists( $wp_config ) ) {
        fwrite( STDERR, "No se encontró wp-config.php.\n" );
        fwrite( STDERR, "Usa: php bin/ltms-store-google-secret.php /ruta/wp-config.php\n" );
        exit( 1 );
    }
    define( 'ABSPATH', dirname( $wp_config ) . '/' );
    define( 'WPINC', 'wp-includes' );
    require_once $wp_config;
    require_once ABSPATH . 'wp-settings.php';
}

echo "\n=== LTMS — Configurar Google OAuth ===\n\n";

// ── Leer variables de entorno ─────────────────────────────────────────────────
$client_id     = trim( (string) ( getenv( 'LTMS_GOOGLE_ID' )     ?: '' ) );
$client_secret = trim( (string) ( getenv( 'LTMS_GOOGLE_SECRET' ) ?: '' ) );

if ( empty( $client_id ) && empty( $client_secret ) ) {
    echo "[ERROR] Ninguna variable de entorno encontrada.\n";
    echo "        Usa: LTMS_GOOGLE_ID='...' LTMS_GOOGLE_SECRET='...' wp eval-file ...\n\n";
    exit( 1 );
}

// ── Guardar en ltms_settings (vía LTMS_Core_Config si disponible) ─────────────
$saved_any = false;

if ( ! empty( $client_id ) ) {
    if ( class_exists( 'LTMS_Core_Config' ) ) {
        $ok = LTMS_Core_Config::set( 'ltms_google_client_id', $client_id );
    } else {
        $settings                        = get_option( 'ltms_settings', [] );
        $settings['ltms_google_client_id'] = $client_id;
        $ok = update_option( 'ltms_settings', $settings, true );
    }
    if ( $ok ) {
        echo "[OK] Client ID guardado: " . substr( $client_id, 0, 30 ) . "...\n";
        $saved_any = true;
    } else {
        echo "[WARN] Client ID no cambió (ya era igual).\n";
        $saved_any = true; // No es error — el valor ya estaba correcto
    }
}

if ( ! empty( $client_secret ) ) {
    try {
        $value = class_exists( 'LTMS_Core_Security' )
            ? LTMS_Core_Security::encrypt( $client_secret )
            : $client_secret; // Fallback plano si la clase no cargó

        if ( class_exists( 'LTMS_Core_Config' ) ) {
            $ok = LTMS_Core_Config::set( 'ltms_google_client_secret', $value );
        } else {
            $settings = get_option( 'ltms_settings', [] );
            $settings['ltms_google_client_secret'] = $value;
            $ok = update_option( 'ltms_settings', $settings, true );
        }

        $mode = class_exists( 'LTMS_Core_Security' ) ? 'cifrado AES-256-CBC' : 'texto plano (cifrado no disponible)';
        if ( $ok ) {
            echo "[OK] Client Secret guardado ($mode, " . strlen( $value ) . " chars).\n";
            $saved_any = true;
        } else {
            echo "[WARN] Client Secret no cambió (ya era igual).\n";
            $saved_any = true;
        }
    } catch ( \Throwable $e ) {
        echo "[ERROR] No se pudo guardar el Secret: " . $e->getMessage() . "\n";
    }
}

// ── Limpiar caché y verificar ─────────────────────────────────────────────────
if ( $saved_any ) {
    if ( class_exists( 'LTMS_Core_Config' ) ) {
        LTMS_Core_Config::flush_cache();
    }

    $configured = class_exists( 'LTMS_Google_OAuth' ) && LTMS_Google_OAuth::is_configured();
    echo "\n[VERIFY] LTMS_Google_OAuth::is_configured() → " . ( $configured ? '✅ true' : '⚠️  false' ) . "\n";

    if ( $configured ) {
        echo "\n✅ Configuración completa. El botón \"Continuar con Google\" aparecerá\n";
        echo "   en /vendedores/ y /registro-vendedor/\n\n";
    } else {
        $settings  = get_option( 'ltms_settings', [] );
        $id_saved  = $settings['ltms_google_client_id']     ?? '';
        $sec_saved = $settings['ltms_google_client_secret'] ?? '';
        echo "\n[DIAG] ltms_settings['ltms_google_client_id']     : " . ( $id_saved  ? strlen( $id_saved )  . " chars ✓" : "VACÍO" ) . "\n";
        echo "[DIAG] ltms_settings['ltms_google_client_secret'] : " . ( $sec_saved ? strlen( $sec_saved ) . " chars ✓" : "VACÍO" ) . "\n";
        echo "\n[INFO] Si el plugin no estaba activo durante este script,\n";
        echo "       LTMS_Google_OAuth puede no haberse cargado pero los datos SÍ están guardados.\n";
        echo "       Verifica visitando: WP Admin → Lo Tengo → Configuración → Integraciones\n\n";
    }
}

