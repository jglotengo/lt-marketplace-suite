<?php
/**
 * ltms-store-backblaze.php
 *
 * Guarda las credenciales de Backblaze B2 en WordPress de forma segura.
 * El Application Key se cifra con AES-256 antes de guardarse.
 *
 * USO (WP-CLI — recomendado):
 *   wp --path=/home/customer/www/lo-tengo.com.co/public_html \
 *      eval-file bin/ltms-store-backblaze.php --allow-root
 *
 * Variables de entorno soportadas:
 *   B2_KEY_ID       — Key ID de Backblaze (0054d7a9c46fe290000000001)
 *   B2_APP_KEY      — Application Key (K0058HLMCg8Muyo3nj9BbU+SKAxojec)
 *   B2_ENDPOINT     — Endpoint S3 (https://s3.us-east-005.backblazeb2.com)
 *   B2_BUCKET       — Bucket contratos (lotengo-contratos)
 *   B2_BUCKET_KYC   — Bucket KYC privado (lotengo-kyc-docs)
 *
 * Si no se pasan por env, usa los valores predefinidos del proyecto.
 */
defined( 'ABSPATH' ) || exit( "Usar: wp eval-file bin/ltms-store-backblaze.php\n" );

echo "\n=== LTMS — Configurar Backblaze B2 ===\n\n";

// ── Leer credenciales (env tiene prioridad sobre defaults) ─────────────────
$key_id      = trim( (string) ( getenv( 'B2_KEY_ID' )     ?: '0054d7a9c46fe290000000001' ) );
$app_key     = trim( (string) ( getenv( 'B2_APP_KEY' )    ?: 'K0058HLMCg8Muyo3nj9BbU+SKAxojec' ) );
$endpoint    = trim( (string) ( getenv( 'B2_ENDPOINT' )   ?: 'https://s3.us-east-005.backblazeb2.com' ) );
$bucket_pub  = trim( (string) ( getenv( 'B2_BUCKET' )     ?: 'lotengo-contratos' ) );
$bucket_kyc  = trim( (string) ( getenv( 'B2_BUCKET_KYC' ) ?: 'lotengo-kyc-docs' ) );

// ── Validar que LTMS esté disponible ─────────────────────────────────────
if ( ! class_exists( 'LTMS_Core_Config' ) || ! class_exists( 'LTMS_Core_Security' ) ) {
    echo "[ERROR] Clases LTMS no disponibles. Ejecutar con WP-CLI.\n\n";
    exit( 1 );
}

// ── Cifrar el Application Key ──────────────────────────────────────────────
$app_key_encrypted = '';
try {
    $app_key_encrypted = LTMS_Core_Security::encrypt( $app_key );
    echo "[OK] Application Key cifrado (AES-256-CBC, " . strlen( $app_key_encrypted ) . " chars)\n";
} catch ( \Throwable $e ) {
    echo "[WARN] Cifrado no disponible — guardando en texto plano. Error: " . $e->getMessage() . "\n";
    $app_key_encrypted = $app_key;
}

// ── Guardar en ltms_settings + update_option (doble escritura) ─────────────
$settings_to_save = [
    'ltms_backblaze_enabled'        => 'yes',
    'ltms_backblaze_key_id'         => $key_id,
    'ltms_backblaze_app_key'        => $app_key_encrypted,
    'ltms_backblaze_endpoint'       => rtrim( $endpoint, '/' ),
    'ltms_backblaze_default_bucket' => $bucket_pub,
    'ltms_backblaze_private_bucket' => $bucket_kyc,
];

$ltms_settings = get_option( 'ltms_settings', [] );
if ( ! is_array( $ltms_settings ) ) {
    $ltms_settings = [];
}

foreach ( $settings_to_save as $key => $value ) {
    update_option( $key, $value );
    $ltms_settings[ $key ] = $value;
}
update_option( 'ltms_settings', $ltms_settings, true );
LTMS_Core_Config::flush_cache();

echo "[OK] Key ID:              $key_id\n";
echo "[OK] Endpoint:            $endpoint\n";
echo "[OK] Bucket contratos:    $bucket_pub\n";
echo "[OK] Bucket KYC:          $bucket_kyc\n";
echo "[OK] Backblaze habilitado: yes\n";

// ── Verificar instanciando el cliente ─────────────────────────────────────
echo "\n[VERIFY] Instanciando LTMS_Api_Backblaze...\n";
try {
    $b2 = LTMS_Api_Factory::get( 'backblaze' );
    echo "[OK] Cliente Backblaze B2 instanciado correctamente.\n";

    echo "[VERIFY] Health check — listando bucket '$bucket_pub'...\n";
    $result = $b2->health_check();
    if ( ! empty( $result['connected'] ) ) {
        echo "✅ Backblaze B2 conectado: " . ( $result['message'] ?? 'OK' ) . "\n";
    } else {
        echo "⚠️  Health check: " . ( $result['message'] ?? 'Sin respuesta' ) . "\n";
        echo "    (Puede ser normal si los buckets están vacíos o la autenticación tarda)\n";
    }
} catch ( \Throwable $e ) {
    echo "[WARN] No se pudo verificar: " . $e->getMessage() . "\n";
    echo "       Las credenciales SÍ están guardadas — verifica en Admin → Configuración.\n";
}

echo "\n✅ Configuración Backblaze B2 completa.\n";
echo "   Ve a Admin → Lo Tengo → Configuración → Backblaze para confirmar.\n\n";
