#!/usr/bin/env php
<?php
/**
 * ltms-store-google-secret.php
 *
 * Almacena el Client Secret de Google OAuth cifrado en wp_options.
 * Ejecutar UNA SOLA VEZ en el servidor:
 *
 *   php bin/ltms-store-google-secret.php /ruta/a/wp-config.php
 *
 * El script usa LTMS_Core_Security::encrypt() para cifrar el secret
 * antes de guardarlo, de forma que nunca quede en texto plano en la BD.
 */

if ( PHP_SAPI !== 'cli' ) {
    exit( "Solo para CLI.\n" );
}

$wp_config = $argv[1] ?? '';
if ( ! file_exists( $wp_config ) ) {
    $wp_config = dirname( __DIR__, 3 ) . '/wp-config.php'; // Suposición estándar
}
if ( ! file_exists( $wp_config ) ) {
    fwrite( STDERR, "No se encontró wp-config.php. Pásalo como argumento.\n" );
    exit( 1 );
}

// Bootstrap mínimo de WordPress
define( 'ABSPATH', dirname( $wp_config ) . '/' );
define( 'WPINC', 'wp-includes' );
require_once $wp_config;
require_once ABSPATH . 'wp-settings.php';

// Credenciales — REEMPLAZAR con los valores reales del Google Cloud Console
$client_id     = 'GOOGLE_CLIENT_ID_PLACEHOLDER';
$client_secret = getenv( 'LTMS_GOOGLE_SECRET' ) ?: ''; // Pasar via env: LTMS_GOOGLE_SECRET=xxx php bin/ltms-store-google-secret.php

if ( empty( $client_secret ) ) {
    fwrite( STDERR, "ERROR: Pasa el secret via variable de entorno:\n" );
    fwrite( STDERR, "  LTMS_GOOGLE_SECRET='GOCSPX-...' php bin/ltms-store-google-secret.php\n" );
    exit( 1 );
}

// Cifrar el secret con la clave de WordPress
if ( ! class_exists( 'LTMS_Core_Security' ) ) {
    fwrite( STDERR, "LTMS_Core_Security no encontrado. ¿Está el plugin activo?\n" );
    exit( 1 );
}

$encrypted = LTMS_Core_Security::encrypt( $client_secret );

update_option( 'ltms_google_client_id',     $client_id,  false );
update_option( 'ltms_google_client_secret', $encrypted,  false );

echo "✅ Client ID y Secret de Google OAuth almacenados correctamente.\n";
echo "   Client ID : $client_id\n";
echo "   Secret    : [cifrado - " . strlen( $encrypted ) . " chars]\n";
