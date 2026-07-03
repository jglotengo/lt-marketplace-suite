<?php
/**
 * LTMS Integration Test Suite — Pruebas End-to-End en Servidor
 *
 * Ejecutar via PHP directamente (sin WP-CLI):
 *
 *   php bin/ltms-integration-tests.php > /tmp/ltms-tests.log 2>&1 &
 *   cat /tmp/ltms-tests.log
 *
 * También funciona con WP-CLI (legacy):
 *   wp --path=... eval-file bin/ltms-integration-tests.php --allow-root
 *
 * @package LTMS
 * @version 1.1.0
 */

// ── Cargar WordPress ────────────────────────────────────────────────────────
// Soporta dos modos: php directo (carga wp-load) o WP-CLI eval-file (ABSPATH ya definido)
if ( ! defined( 'ABSPATH' ) ) {
    $wp_path = '/home/customer/www/lo-tengo.com.co/public_html';
    if ( ! file_exists( $wp_path . '/wp-load.php' ) ) {
        die( "ERROR: wp-load.php no encontrado en $wp_path\n" );
    }
    $_SERVER['HTTP_HOST']   = 'lo-tengo.com.co';
    $_SERVER['REQUEST_URI'] = '/';
    // Silenciar output de WP al cargar
    ob_start();
    require_once $wp_path . '/wp-load.php';
    ob_end_clean();
}


// Forzar invalidación de OPcache para que las clases recién actualizadas se lean del disco.
if ( function_exists( 'opcache_reset' ) ) {
    opcache_reset();
}

// Evitar timeout de SiteGround (max 30s por defecto para scripts CLI).
// Con set_time_limit(0) el script puede correr indefinidamente.
set_time_limit( 0 );
// Guardar output a archivo para que no se pierda si la conexión SSH se corta.
$ltms_log_path = '/tmp/ltms-integration-' . date('Ymd-Hi') . '.log';
$ltms_log_fh   = fopen( $ltms_log_path, 'w' );
// Función helper para imprimir y loggear simultáneamente.
function ltms_out( string $line ): void {
    global $ltms_log_fh;
    echo $line;
    if ( $ltms_log_fh ) fwrite( $ltms_log_fh, $line );
}

// ─────────────────────────────────────────────────────────────────────────────
// Framework de pruebas minimalista
// ─────────────────────────────────────────────────────────────────────────────

$ltms_results = [];
$ltms_group   = 'General';
$ltms_pass    = 0;
$ltms_fail    = 0;
$ltms_warn    = 0;
$ltms_sep     = str_repeat( '═', 65 );
$ltms_sep2    = str_repeat( '─', 65 );

function ltms_test( string $name, callable $fn ): void {
    global $ltms_results, $ltms_group, $ltms_pass, $ltms_fail, $ltms_warn;
    $start = microtime( true );
    try {
        [ $status, $detail ] = $fn();
    } catch ( \Throwable $e ) {
        $status = 'FAIL';
        $detail = 'EXCEPTION: ' . $e->getMessage() . ' in ' . basename( $e->getFile() ) . ':' . $e->getLine();
    }
    $ms            = round( ( microtime( true ) - $start ) * 1000 );
    $ltms_results[] = compact( 'ltms_group', 'name', 'status', 'detail', 'ms' );
    $icon = $status === 'PASS' ? '✅' : ( $status === 'WARN' ? '⚠️ ' : '❌' );
    printf( "  %s [%4dms] %s: %s\n", $icon, $ms, $name, $detail );
    if ( $status === 'PASS' )      $ltms_pass++;
    elseif ( $status === 'WARN' )  $ltms_warn++;
    else                           $ltms_fail++;
}

function ltms_group( string $name ): void {
    global $ltms_group, $ltms_sep;
    $ltms_group = $name;
    echo "\n{$ltms_sep}\n GRUPO: {$name}\n{$ltms_sep}\n";
    @ob_flush(); flush(); // Flush para que el output no se pierda si se corta la conexión
}

function ltms_create_test_vendor(): int {
    $ts  = time() . rand( 100, 999 );
    $uid = wp_insert_user( [
        'user_login' => "qa_vendor_{$ts}",
        'user_pass'  => wp_generate_password( 20 ),
        'user_email' => "qa_vendor_{$ts}@test-ltms.local",
        'role'       => 'ltms_vendor', // Usar rol real — subscriber es bloqueado por is_ltms_vendor()
    ] );
    if ( is_wp_error( $uid ) ) {
        // Fallback: si ltms_vendor no existe aún, crear con subscriber + meta
        $uid2 = wp_insert_user( [
            'user_login' => "qa_vendor_{$ts}b",
            'user_pass'  => wp_generate_password( 20 ),
            'user_email' => "qa_vendor_{$ts}b@test-ltms.local",
            'role'       => 'subscriber',
        ] );
        if ( is_wp_error( $uid2 ) ) return 0;
        $uid = $uid2;
    }
    update_user_meta( $uid, 'ltms_is_vendor',  true );
    update_user_meta( $uid, 'ltms_store_name', 'QA Test Store' );
    update_user_meta( $uid, 'ltms_kyc_status', 'approved' );
    update_user_meta( $uid, 'ltms_tax_regime', 'simplified' );
    return $uid;
}

function ltms_cleanup_user( int $uid ): void {
    if ( $uid > 0 ) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user( $uid );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// CABECERA
// ─────────────────────────────────────────────────────────────────────────────
echo "\n{$ltms_sep}\n";
echo " LTMS INTEGRATION TEST SUITE v1.0\n";
echo ' Servidor: ' . home_url() . "\n";
echo ' Fecha:    ' . gmdate( 'Y-m-d H:i:s T' ) . "\n";
echo ' PHP:      ' . PHP_VERSION . "\n";
echo ' WP:       ' . get_bloginfo( 'version' ) . "\n";
if ( defined( 'WC_VERSION' ) ) echo ' WC:       ' . WC_VERSION . "\n";
if ( defined( 'LTMS_VERSION' ) ) echo ' LTMS:     ' . LTMS_VERSION . "\n";
echo "{$ltms_sep}\n";

// ─────────────────────────────────────────────────────────────────────────────
// GRUPO 1: Plugin cargado y clases activas
// ─────────────────────────────────────────────────────────────────────────────
ltms_group( '1 — Plugin: clases y autoloader' );

ltms_test( 'Plugin LTMS activo en active_plugins', function () {
    $active = in_array( 'lt-marketplace-suite/lt-marketplace-suite.php', get_option( 'active_plugins', [] ), true );
    return $active ? [ 'PASS', 'OK' ] : [ 'FAIL', 'Plugin NO activo' ];
} );

ltms_test( 'LTMS_VERSION definida', function () {
    return defined( 'LTMS_VERSION' )
        ? [ 'PASS', 'v' . LTMS_VERSION ]
        : [ 'FAIL', 'LTMS_VERSION no definida' ];
} );

ltms_test( 'LTMS_PLUGIN_DIR existe en disco', function () {
    if ( ! defined( 'LTMS_PLUGIN_DIR' ) ) return [ 'FAIL', 'Constante no definida' ];
    return is_dir( LTMS_PLUGIN_DIR )
        ? [ 'PASS', LTMS_PLUGIN_DIR ]
        : [ 'FAIL', 'Dir no existe: ' . LTMS_PLUGIN_DIR ];
} );

$core_classes = [
    'LTMS_Core_Config', 'LTMS_Core_Logger', 'LTMS_Core_Security', 'LTMS_Utils',
    'LTMS_Business_Wallet', 'LTMS_Payout_Scheduler', 'LTMS_Api_Factory',
    'LTMS_Frontend_Checkout_Handler', 'LTMS_Stripe_Webhook_Handler',
    'LTMS_Core_Firewall', 'LTMS_Affiliates', 'LTMS_Referral_Tree',
    'LTMS_XCover_Policy_Listener', 'LTMS_Secure_Downloads',
    'LTMS_Order_Paid_Listener', 'LTMS_Business_Consumer_Protection',
    'LTMS_Api_TPTC', 'LTMS_Api_XCover', 'LTMS_Api_Heka',
];

foreach ( $core_classes as $cls ) {
    ltms_test( "Clase {$cls} cargada por autoloader", function () use ( $cls ) {
        return class_exists( $cls )
            ? [ 'PASS', 'OK' ]
            : [ 'FAIL', "{$cls} no encontrada — error de autoloader" ];
    } );
}

// ─────────────────────────────────────────────────────────────────────────────
// GRUPO 2: Base de datos — tablas y migraciones
// ─────────────────────────────────────────────────────────────────────────────
ltms_group( '2 — Base de datos: tablas' );

global $wpdb;

$expected_tables = [
    'lt_vendor_wallets', 'lt_wallet_transactions', 'lt_wallet_holds',
    'lt_payout_requests', 'lt_referral_network',
    'lt_vendor_kyc', 'lt_notifications', 'lt_waf_blocked_ips',
    'lt_insurance_policies', 'lt_bookings',
];

foreach ( $expected_tables as $tbl ) {
    ltms_test( "Tabla {$wpdb->prefix}{$tbl}", function () use ( $wpdb, $tbl ) {
        $full   = $wpdb->prefix . $tbl;
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) );
        return $exists === $full
            ? [ 'PASS', 'OK' ]
            : [ 'FAIL', "Tabla {$full} NO existe — ejecutar migraciones" ];
    } );
}

ltms_test( 'BD sin errores de conexión', function () {
    global $wpdb;
    $wpdb->get_var( 'SELECT 1' );
    return empty( $wpdb->last_error )
        ? [ 'PASS', 'Conexión OK' ]
        : [ 'FAIL', 'Error BD: ' . $wpdb->last_error ];
} );

// ─────────────────────────────────────────────────────────────────────────────
// GRUPO 3: Config
// ─────────────────────────────────────────────────────────────────────────────
ltms_group( '3 — Config y constantes' );

ltms_test( 'get_country() retorna CO o MX', function () {
    if ( ! class_exists( 'LTMS_Core_Config' ) ) return [ 'FAIL', 'Clase no cargada' ];
    $c = LTMS_Core_Config::get_country();
    return in_array( $c, [ 'CO', 'MX' ], true )
        ? [ 'PASS', "País: {$c}" ]
        : [ 'WARN', "País inesperado: '{$c}'" ];
} );

ltms_test( 'get_currency() retorna COP o MXN', function () {
    if ( ! class_exists( 'LTMS_Core_Config' ) ) return [ 'FAIL', 'Clase no cargada' ];
    $c = LTMS_Core_Config::get_currency();
    return in_array( $c, [ 'COP', 'MXN' ], true )
        ? [ 'PASS', "Moneda: {$c}" ]
        : [ 'WARN', "Moneda: '{$c}'" ];
} );

ltms_test( 'Encryption key presente (≥ 32 chars)', function () {
    if ( ! class_exists( 'LTMS_Core_Config' ) ) return [ 'FAIL', 'Clase no cargada' ];
    $k = LTMS_Core_Config::get_encryption_key();
    return strlen( $k ) >= 32
        ? [ 'PASS', strlen( $k ) . ' chars ✓' ]
        : [ 'FAIL', 'Key ausente o corta: ' . strlen( $k ) . ' chars' ];
} );

// ─────────────────────────────────────────────────────────────────────────────
// GRUPO 4: Seguridad — cifrado, hashing y tokens
// ─────────────────────────────────────────────────────────────────────────────
ltms_group( '4 — Seguridad: cifrado y hashing' );

ltms_test( 'AES-256 Encrypt/Decrypt round-trip', function () {
    if ( ! class_exists( 'LTMS_Core_Security' ) ) return [ 'FAIL', 'Clase no cargada' ];
    $plain     = 'NIT-900123456-7';
    $encrypted = LTMS_Core_Security::encrypt( $plain );
    $decrypted = LTMS_Core_Security::decrypt( $encrypted );
    if ( $decrypted !== $plain )   return [ 'FAIL', "Decrypt='{$decrypted}' ≠ '{$plain}'" ];
    if ( $encrypted === $plain )   return [ 'FAIL', 'Cifrado devolvió texto plano — sin cifrar' ];
    return [ 'PASS', 'AES-256 OK' ];
} );

ltms_test( 'HMAC hash y verify_hash consistentes', function () {
    if ( ! class_exists( 'LTMS_Core_Security' ) ) return [ 'FAIL', 'Clase no cargada' ];
    $val  = 'cuenta_bancaria_123';
    $hash = LTMS_Core_Security::hash( $val );
    if ( ! LTMS_Core_Security::verify_hash( $val, $hash ) )
        return [ 'FAIL', 'verify_hash rechaza hash correcto' ];
    if ( LTMS_Core_Security::verify_hash( 'otro', $hash ) )
        return [ 'FAIL', 'verify_hash acepta valor incorrecto — RIESGO' ];
    return [ 'PASS', 'HMAC-SHA256 OK' ];
} );

ltms_test( 'generate_token() produce tokens únicos ≥ 32 chars', function () {
    if ( ! class_exists( 'LTMS_Core_Security' ) ) return [ 'FAIL', 'Clase no cargada' ];
    $t1 = LTMS_Core_Security::generate_token();
    $t2 = LTMS_Core_Security::generate_token();
    if ( $t1 === $t2 )         return [ 'FAIL', 'Tokens idénticos — sin entropía' ];
    if ( strlen( $t1 ) < 32 )  return [ 'FAIL', "Token corto: {$t1}" ];
    return [ 'PASS', strlen( $t1 ) . " chars, únicos ✓" ];
} );

ltms_test( 'verify_webhook_signature() HMAC correcto', function () {
    if ( ! class_exists( 'LTMS_Core_Security' ) ) return [ 'FAIL', 'Clase no cargada' ];
    $secret  = 'test_secret_qa';
    $payload = '{"event":"order.paid"}';
    $sig     = hash_hmac( 'sha256', $payload, $secret );
    $valid   = LTMS_Core_Security::verify_webhook_signature( $payload, $sig, $secret );
    $invalid = LTMS_Core_Security::verify_webhook_signature( $payload, 'wrong', $secret );
    if ( ! $valid )   return [ 'FAIL', 'Firma válida rechazada' ];
    if ( $invalid )   return [ 'FAIL', 'Firma inválida aceptada — RIESGO CRÍTICO' ];
    return [ 'PASS', 'HMAC webhook OK' ];
} );

ltms_test( 'LTMS_Utils::sanitize_phone() limpia teléfonos (M-35)', function () {
    if ( ! class_exists( 'LTMS_Utils' ) ) return [ 'FAIL', 'Clase no cargada' ];
    if ( ! method_exists( 'LTMS_Utils', 'sanitize_phone' ) )
        return [ 'FAIL', 'sanitize_phone() no existe — M-35 no aplicado' ];
    $result = LTMS_Utils::sanitize_phone( '+57 (300) 123-4567' );
    return str_contains( $result, '573001234567' ) || $result === '573001234567'
        ? [ 'PASS', "sanitize_phone → '{$result}'" ]
        : [ 'WARN', "Resultado: '{$result}' (verificar formato)" ];
} );

// ─────────────────────────────────────────────────────────────────────────────
// GRUPO 5: Utils
// ─────────────────────────────────────────────────────────────────────────────
ltms_group( '5 — Utils: helpers' );

ltms_test( 'LTMS_Utils::format_money() formatea correctamente', function () {
    if ( ! class_exists( 'LTMS_Utils' ) ) return [ 'FAIL', 'Clase no cargada' ];
    $result = LTMS_Utils::format_money( 150000 );
    return str_contains( $result, '150' )
        ? [ 'PASS', "format_money(150000) = '{$result}'" ]
        : [ 'WARN', "Resultado: '{$result}'" ];
} );

ltms_test( 'LTMS_Utils::is_ltms_vendor() — usuario normal = false', function () {
    $uid = wp_insert_user( [
        'user_login' => 'qa_plain_' . time(),
        'user_pass'  => 'pass',
        'user_email' => 'plain_' . time() . '@test.local',
        'role'       => 'subscriber',
    ] );
    if ( is_wp_error( $uid ) ) return [ 'WARN', 'No se pudo crear usuario de prueba' ];
    $result = LTMS_Utils::is_ltms_vendor( $uid );
    wp_delete_user( $uid );
    return ! $result
        ? [ 'PASS', 'Usuario sin ltms_is_vendor = false ✓' ]
        : [ 'FAIL', 'Usuario normal identificado como vendedor' ];
} );

ltms_test( 'LTMS_Utils::is_ltms_vendor() — vendedor = true', function () {
    $uid = ltms_create_test_vendor();
    if ( ! $uid ) return [ 'FAIL', 'No se pudo crear vendedor de prueba' ];
    $result = LTMS_Utils::is_ltms_vendor( $uid );
    ltms_cleanup_user( $uid );
    return $result
        ? [ 'PASS', 'Vendedor identificado correctamente ✓' ]
        : [ 'FAIL', 'Vendedor no reconocido como tal' ];
} );

ltms_test( 'LTMS_Utils::now_utc() formato MySQL válido', function () {
    if ( ! method_exists( 'LTMS_Utils', 'now_utc' ) ) return [ 'WARN', 'now_utc() no existe' ];
    $ts = LTMS_Utils::now_utc();
    return preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $ts )
        ? [ 'PASS', "now_utc() = '{$ts}'" ]
        : [ 'FAIL', "Formato inválido: '{$ts}'" ];
} );

// ─────────────────────────────────────────────────────────────────────────────
// GRUPO 6: Wallet — transacciones y balance_pending (M-25)
// ─────────────────────────────────────────────────────────────────────────────
ltms_group( '6 — Wallet: CRUD y balance_pending (M-25)' );

$qa_vendor = ltms_create_test_vendor();

ltms_test( 'Wallet::get_or_create() crea wallet', function () use ( $qa_vendor ) {
    if ( ! $qa_vendor ) return [ 'FAIL', 'Sin usuario de prueba' ];
    $wallet = LTMS_Business_Wallet::get_or_create( $qa_vendor );
    return ( $wallet && isset( $wallet['balance'] ) )
        ? [ 'PASS', "balance: {$wallet['balance']}" ]
        : [ 'FAIL', 'Wallet no creado o sin key balance' ];
} );

ltms_test( 'Wallet::credit() acredita fondos', function () use ( $qa_vendor ) {
    if ( ! $qa_vendor ) return [ 'FAIL', 'Sin usuario de prueba' ];
    LTMS_Business_Wallet::credit( $qa_vendor, 50000, 'Crédito QA', [], 0 );
    $wallet = LTMS_Business_Wallet::get_or_create( $qa_vendor );
    return (float) $wallet['balance'] >= 50000
        ? [ 'PASS', "balance: {$wallet['balance']} ✓" ]
        : [ 'FAIL', "balance esperado ≥ 50000, obtenido: {$wallet['balance']}" ];
} );

ltms_test( 'Wallet::hold() usa columna balance_pending (M-25)', function () use ( $qa_vendor ) {
    if ( ! $qa_vendor ) return [ 'FAIL', 'Sin usuario de prueba' ];
    LTMS_Business_Wallet::hold( $qa_vendor, 10000, 'Hold QA', [] );
    $wallet  = LTMS_Business_Wallet::get_or_create( $qa_vendor );
    // M-25: la columna correcta es balance_pending (no held_balance)
    $pending = (float) ( $wallet['balance_pending'] ?? -1 );
    if ( $pending < 0 )
        return [ 'FAIL', 'balance_pending no existe en wallet — M-25 puede no estar en DB' ];
    return $pending >= 10000
        ? [ 'PASS', "balance_pending: {$pending} ✓" ]
        : [ 'WARN', "balance_pending={$pending}, esperado ≥ 10000" ];
} );

ltms_test( 'Wallet::get_transactions() retorna historial', function () use ( $qa_vendor ) {
    if ( ! $qa_vendor ) return [ 'FAIL', 'Sin usuario de prueba' ];
    $txs = LTMS_Business_Wallet::get_transactions( $qa_vendor, 10 );
    return is_array( $txs ) && count( $txs ) > 0
        ? [ 'PASS', count( $txs ) . ' transacciones ✓' ]
        : [ 'WARN', 'Sin transacciones (normal en BD vacía)' ];
} );

// ─────────────────────────────────────────────────────────────────────────────
// GRUPO 7: Payout Scheduler
// ─────────────────────────────────────────────────────────────────────────────
ltms_group( '7 — Payout Scheduler: validaciones' );

ltms_test( 'create_request() rechaza monto ≤ 0', function () use ( $qa_vendor ) {
    if ( ! $qa_vendor ) return [ 'FAIL', 'Sin usuario' ];
    $r = LTMS_Payout_Scheduler::create_request( $qa_vendor, 0, '', 'bank_transfer' );
    return ( isset( $r['success'] ) && ! $r['success'] )
        ? [ 'PASS', 'Monto 0 rechazado ✓' ]
        : [ 'WARN', 'Monto 0 no rechazado: ' . json_encode( $r ) ];
} );

ltms_test( 'create_request() rechaza sin KYC aprobado', function () {
    $uid = wp_insert_user( [
        'user_login' => 'qa_nokyc_' . time(),
        'user_pass'  => 'pass',
        'user_email' => 'nokyc_' . time() . '@test.local',
        'role'       => 'subscriber',
    ] );
    if ( is_wp_error( $uid ) ) return [ 'WARN', 'No se pudo crear usuario' ];
    update_user_meta( $uid, 'ltms_is_vendor', true );
    update_user_meta( $uid, 'ltms_kyc_status', 'pending' );
    $r = LTMS_Payout_Scheduler::create_request( $uid, 50000, '', 'bank_transfer' );
    wp_delete_user( $uid );
    return ( isset( $r['success'] ) && ! $r['success'] )
        ? [ 'PASS', 'Sin KYC rechazado ✓' ]
        : [ 'FAIL', 'Retiro aceptado sin KYC — RIESGO DE NEGOCIO' ];
} );

ltms_test( 'create_request() crea solicitud para vendedor KYC aprobado', function () use ( $qa_vendor ) {
    if ( ! $qa_vendor ) return [ 'FAIL', 'Sin usuario' ];
    // Saldo previo holgado y monto > 50.000 COP (mínimo de retiro vigente).
    LTMS_Business_Wallet::credit( $qa_vendor, 100000, 'Saldo previo QA', [], 0 );
    $r = LTMS_Payout_Scheduler::create_request( $qa_vendor, 60000, '', 'bank_transfer' );
    return ( isset( $r['success'] ) && $r['success'] )
        ? [ 'PASS', 'Solicitud creada ✓ — ID: ' . ( $r['payout_id'] ?? '?' ) ]
        : [ 'WARN', 'No creada: ' . json_encode( $r ) ];
} );

// ─────────────────────────────────────────────────────────────────────────────
// GRUPO 8: API Factory — cobertura completa (M-33)
// ─────────────────────────────────────────────────────────────────────────────
ltms_group( '8 — API Factory: todos los providers (M-29/M-33)' );

$api_providers = [
    'openpay', 'siigo', 'addi', 'aveonline', 'zapsign',
    'tptc', 'xcover', 'backblaze', 'uber', 'stripe', 'heka',
];

foreach ( $api_providers as $provider ) {
    ltms_test( "Api_Factory map contiene '{$provider}'", function () use ( $provider ) {
        if ( ! class_exists( 'LTMS_Api_Factory' ) ) return [ 'FAIL', 'Clase no cargada' ];
        // Usamos reflexión para verificar el client_map sin instanciar
        $r = new \ReflectionClass( 'LTMS_Api_Factory' );
        try {
            $prop = $r->getProperty( 'client_map' );
            $prop->setAccessible( true );
            $map = $prop->getValue( null );
            return isset( $map[ $provider ] )
                ? [ 'PASS', "→ {$map[$provider]}" ]
                : [ 'FAIL', "'{$provider}' ausente en client_map — runtime exception al llamarlo" ];
        } catch ( \Throwable $e ) {
            return [ 'WARN', 'No se pudo leer client_map: ' . $e->getMessage() ];
        }
    } );
}

// ─────────────────────────────────────────────────────────────────────────────
// GRUPO 9: Firewall WAF
// ─────────────────────────────────────────────────────────────────────────────
ltms_group( '9 — Firewall WAF: detección de ataques' );

$waf_attacks = [
    'XSS script tag'         => '<script>alert(1)</script>',
    'SQL UNION SELECT'       => "' OR 1=1 UNION SELECT 1,2,3--",
    'LFI path traversal'     => '../../wp-config.php',
    'PHP RCE attempt'        => '<?php system($_GET[cmd]); ?>',
];

foreach ( $waf_attacks as $label => $payload ) {
    ltms_test( "WAF detecta: {$label}", function () use ( $payload ) {
        if ( ! class_exists( 'LTMS_Core_Firewall' ) ) return [ 'FAIL', 'Clase no cargada' ];
        try {
            $r = new \ReflectionClass( 'LTMS_Core_Firewall' );
            if ( ! $r->hasMethod( 'check_patterns' ) )
                return [ 'WARN', 'check_patterns privado — no accesible vía reflexión' ];
            $m = $r->getMethod( 'check_patterns' );
            $m->setAccessible( true );
            $result = $m->invoke( null, $payload );
            return $result !== null
                ? [ 'PASS', "Detectado → regla: {$result}" ]
                : [ 'WARN', 'Patrón no detectado — revisar reglas WAF' ];
        } catch ( \Throwable $e ) {
            return [ 'WARN', 'Reflexión fallida: ' . $e->getMessage() ];
        }
    } );
}

ltms_test( 'lt_waf_blocked_ips existe en BD', function () {
    global $wpdb;
    $table  = $wpdb->prefix . 'lt_waf_blocked_ips';
    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    return $exists === $table
        ? [ 'PASS', "{$table} existe ✓" ]
        : [ 'FAIL', "Tabla {$table} faltante — block_ip() fallará en runtime" ];
} );

// ─────────────────────────────────────────────────────────────────────────────
// GRUPO 10: REST API — endpoints y permisos
// ─────────────────────────────────────────────────────────────────────────────
ltms_group( '10 — REST API: endpoints registrados' );

ltms_test( 'Namespace ltms/v1 en REST server', function () {
    $routes = rest_get_server()->get_routes();
    foreach ( array_keys( $routes ) as $r ) {
        if ( str_starts_with( $r, '/ltms/v1' ) ) return [ 'PASS', 'ltms/v1 registrado ✓' ];
    }
    return [ 'FAIL', 'ltms/v1 NO registrado — revisar Kernel::boot()' ];
} );

$rest_routes = [
    '/ltms/v1/webhooks/stripe',
    '/ltms/v1/webhooks/openpay',
    '/ltms/v1/affiliates/stats',
    '/ltms/v1/affiliates/leaderboard',
];

foreach ( $rest_routes as $route ) {
    ltms_test( "REST route {$route}", function () use ( $route ) {
        $routes = rest_get_server()->get_routes();
        foreach ( array_keys( $routes ) as $r ) {
            if ( $r === $route || str_starts_with( $r, $route ) )
                return [ 'PASS', 'Registrada ✓' ];
        }
        return [ 'FAIL', "NO encontrada en REST server" ];
    } );
}

// ─────────────────────────────────────────────────────────────────────────────
// GRUPO 11: AJAX Hooks registrados
// ─────────────────────────────────────────────────────────────────────────────
ltms_group( '11 — AJAX hooks: todos los handlers registrados' );

$ajax_actions = [
    'ltms_process_checkout', 'ltms_get_pse_banks',
    'ltms_get_dashboard_data', 'ltms_get_orders_data', 'ltms_get_analytics_data',
    'ltms_get_products_data', 'ltms_create_product', 'ltms_update_product',
    'ltms_get_wallet_data', 'ltms_request_payout',
    'ltms_get_notifications', 'ltms_mark_notification_read',
    'ltms_kitchen_get_orders', 'ltms_kitchen_update_status',
    'ltms_get_shipping_quotes', 'ltms_live_search',
    'ltms_save_vendor_settings', 'ltms_get_vendor_settings',
    'ltms_create_oxxo_reference', 'ltms_create_spei_reference',
    'ltms_get_msi_options', 'ltms_download_oxxo_voucher',
    'ltms_vendor_login', 'ltms_vendor_register',
    'ltms_submit_kyc', 'ltms_upload_kyc_document',
];

foreach ( $ajax_actions as $action ) {
    ltms_test( "Hook wp_ajax_{$action}", function () use ( $action ) {
        global $wp_filter;
        $priv   = isset( $wp_filter[ "wp_ajax_{$action}" ] );
        $nopriv = isset( $wp_filter[ "wp_ajax_nopriv_{$action}" ] );
        if ( $priv || $nopriv ) {
            $type = $priv ? 'priv' : 'nopriv-only';
            return [ 'PASS', "Registrado ({$type}) ✓" ];
        }
        return [ 'FAIL', "Hook wp_ajax_{$action} NO registrado" ];
    } );
}

// ─────────────────────────────────────────────────────────────────────────────
// GRUPO 12: Cron jobs programados
// ─────────────────────────────────────────────────────────────────────────────
ltms_group( '12 — Cron jobs' );

$cron_hooks = [
    'ltms_daily_cron'       => 'Cron diario',
    'ltms_update_tracking'  => 'Actualización de tracking (M-32)',
];

foreach ( $cron_hooks as $hook => $label ) {
    ltms_test( "Cron '{$hook}' programado", function () use ( $hook, $label ) {
        $next = wp_next_scheduled( $hook );
        if ( $next ) {
            $diff = $next - time();
            $when = $diff > 0 ? "próxima en {$diff}s" : "atrasado {$diff}s";
            return [ 'PASS', "{$label} — {$when}" ];
        }
        return [ 'WARN', "{$label} — NO programado (re-activar plugin)" ];
    } );
}

ltms_test( 'Handler add_action(ltms_update_tracking) registrado (M-32)', function () {
    global $wp_filter;
    return isset( $wp_filter['ltms_update_tracking'] )
        ? [ 'PASS', 'Handler ltms_update_tracking registrado ✓' ]
        : [ 'FAIL', 'ltms_update_tracking sin handler — tracking de envíos no funciona' ];
} );

// ─────────────────────────────────────────────────────────────────────────────
// GRUPO 13: Seguridad HTTP Headers (M-201)
// ─────────────────────────────────────────────────────────────────────────────
ltms_group( '13 — HTTP Security Headers (M-201)' );

ltms_test( 'send_security_headers() existe en LTMS_Core_Security', function () {
    return method_exists( 'LTMS_Core_Security', 'send_security_headers' )
        ? [ 'PASS', 'Método presente ✓' ]
        : [ 'FAIL', 'send_security_headers() NO encontrado — M-201 no deployado' ];
} );

ltms_test( 'Hook send_headers registrado por LTMS_Core_Security', function () {
    global $wp_filter;
    foreach ( $wp_filter['send_headers']->callbacks ?? [] as $priority => $cbs ) {
        foreach ( $cbs as $cb ) {
            if ( is_array( $cb['function'] ) && $cb['function'][0] === 'LTMS_Core_Security' )
                return [ 'PASS', "Priority {$priority} ✓" ];
        }
    }
    return [ 'WARN', 'Hook no registrado — headers de seguridad pueden faltar' ];
} );

// ─────────────────────────────────────────────────────────────────────────────
// GRUPO 14: MLM — métodos correctos (M-34)
// ─────────────────────────────────────────────────────────────────────────────
ltms_group( '14 — MLM/Referral Tree (M-34)' );

ltms_test( 'LTMS_Referral_Tree::get_descendant_tree() existe (M-34)', function () {
    return method_exists( 'LTMS_Referral_Tree', 'get_descendant_tree' )
        ? [ 'PASS', 'get_descendant_tree() presente ✓' ]
        : [ 'FAIL', 'Método no existe' ];
} );

ltms_test( 'LTMS_Referral_Tree::get_tree() NO existe (M-34 anti-regresión)', function () {
    return ! method_exists( 'LTMS_Referral_Tree', 'get_tree' )
        ? [ 'PASS', 'get_tree() correctamente ausente ✓' ]
        : [ 'WARN', 'get_tree() existe (puede ser alias) — verificar que no causa duplicidad' ];
} );

ltms_test( 'LTMS_Referral_Tree::get_network_stats() existe', function () {
    return method_exists( 'LTMS_Referral_Tree', 'get_network_stats' )
        ? [ 'PASS', 'get_network_stats() presente ✓' ]
        : [ 'FAIL', 'Método no existe' ];
} );

// ─────────────────────────────────────────────────────────────────────────────
// GRUPO 15: Notificaciones — total_unread (M-15)
// ─────────────────────────────────────────────────────────────────────────────
ltms_group( '15 — Notificaciones: total_unread en respuesta AJAX (M-15)' );

ltms_test( 'ltms_get_notifications devuelve count (total_unread)', function () use ( $qa_vendor ) {
    if ( ! $qa_vendor ) return [ 'FAIL', 'Sin usuario de prueba' ];
    wp_set_current_user( $qa_vendor );
    $_POST    = [ 'action' => 'ltms_get_notifications', 'nonce' => wp_create_nonce( 'ltms_dashboard_nonce' ), 'since' => 0 ];
    $_REQUEST = $_POST;
    ob_start();
    do_action( 'wp_ajax_ltms_get_notifications' );
    $output = ob_get_clean();
    wp_set_current_user( 0 );
    $_POST = [];
    $_REQUEST = [];
    $data = json_decode( $output, true );
    if ( ! $data ) return [ 'WARN', "Output no es JSON: " . substr( $output, 0, 80 ) ];
    // Handler devuelve 'count' (total_unread del badge) y 'new_count' (desde `since`)
    $has = array_key_exists( 'count', $data['data'] ?? [] );
    return $has
        ? [ 'PASS', "count(total_unread)={$data['data']['count']} new_count={$data['data']['new_count']} ✓" ]
        : [ 'FAIL', 'Faltan keys count/new_count. Keys: ' . implode( ', ', array_keys( $data['data'] ?? [] ) ) ];
} );

// ─────────────────────────────────────────────────────────────────────────────
// GRUPO 16: Assets en disco
// ─────────────────────────────────────────────────────────────────────────────
ltms_group( '16 — Assets JS/CSS en disco' );

$assets = [
    'assets/js/ltms-dashboard.js',
    'assets/js/ltms-checkout.js',
    'assets/js/ltms-kds.js',
    'assets/js/ltms-checkout-mexico.js',
    'assets/js/ltms-login-register.js',
    'assets/css/ltms-dashboard.css',
    'assets/css/ltms-frontend-extensions.css',
    'assets/css/ltms-kds.css',
];

foreach ( $assets as $asset ) {
    ltms_test( "Asset: {$asset}", function () use ( $asset ) {
        $base = defined( 'LTMS_PLUGIN_DIR' ) ? LTMS_PLUGIN_DIR : WP_PLUGIN_DIR . '/lt-marketplace-suite/';
        $path = rtrim( $base, '/' ) . '/' . $asset;
        if ( ! file_exists( $path ) ) return [ 'FAIL', "No encontrado: {$path}" ];
        $kb = round( filesize( $path ) / 1024, 1 );
        if ( $kb < 0.1 ) return [ 'WARN', "Archivo vacío: {$kb} KB" ];
        return [ 'PASS', "{$kb} KB ✓" ];
    } );
}

// ─────────────────────────────────────────────────────────────────────────────
// GRUPO 17: WooCommerce integración
// ─────────────────────────────────────────────────────────────────────────────
ltms_group( '17 — WooCommerce: gateway y hooks' );

ltms_test( 'WooCommerce activo', function () {
    return class_exists( 'WooCommerce' )
        ? [ 'PASS', 'WC ' . WC_VERSION . ' ✓' ]
        : [ 'FAIL', 'WooCommerce no encontrado' ];
} );

ltms_test( 'Hook woocommerce_payment_complete tiene listeners LTMS', function () {
    global $wp_filter;
    $hooks = $wp_filter['woocommerce_payment_complete'] ?? null;
    $found = [];
    foreach ( $hooks->callbacks ?? [] as $priority => $cbs ) {
        foreach ( $cbs as $cb ) {
            if ( is_array( $cb['function'] ) && str_starts_with( $cb['function'][0] ?? '', 'LTMS_' ) )
                $found[] = $cb['function'][0];
        }
    }
    return count( $found ) > 0
        ? [ 'PASS', 'Listeners: ' . implode( ', ', array_unique( $found ) ) ]
        : [ 'WARN', 'Sin listeners LTMS en woocommerce_payment_complete' ];
} );

ltms_group( '18 — ReteICA territorialidad municipal (M-200)' );

ltms_test( 'Tabla lt_co_dane_municipalities existe', function () {
    global $wpdb;
    $t = $wpdb->prefix . 'lt_co_dane_municipalities';
    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t;
    return $exists ? [ 'PASS', 'tabla OK' ] : [ 'FAIL', "tabla {$t} no existe" ];
} );

ltms_test( 'Catálogo DANE poblado (>= 40 municipios)', function () {
    global $wpdb;
    $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->prefix}lt_co_dane_municipalities`" );
    return $count >= 40 ? [ 'PASS', "{$count} municipios" ] : [ 'FAIL', "solo {$count} municipios (esperado >=40)" ];
} );

ltms_test( 'Bogotá (11001) y Cali (76001) en catálogo', function () {
    global $wpdb;
    $b = $wpdb->get_var( "SELECT municipality_name FROM `{$wpdb->prefix}lt_co_dane_municipalities` WHERE code='11001'" );
    $c = $wpdb->get_var( "SELECT municipality_name FROM `{$wpdb->prefix}lt_co_dane_municipalities` WHERE code='76001'" );
    return ( $b && $c ) ? [ 'PASS', "Bogotá={$b}, Cali={$c}" ] : [ 'FAIL', "Bogotá={$b}, Cali={$c}" ];
} );

ltms_test( 'Tabla lt_co_reteica_rates_municipal existe y poblada', function () {
    global $wpdb;
    $t = $wpdb->prefix . 'lt_co_reteica_rates_municipal';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
        return [ 'FAIL', "tabla {$t} no existe" ];
    }
    $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$t}`" );
    return $count >= 25 ? [ 'PASS', "{$count} tarifas seeded" ] : [ 'FAIL', "solo {$count} tarifas (esperado >=25)" ];
} );

ltms_test( 'ReteICA Bogotá CIIU 4791 ≠ Cali CIIU 4791 (diferenciación municipal)', function () {
    if ( ! class_exists( 'LTMS_Tax_Engine' ) ) return [ 'FAIL', 'Tax Engine no cargado' ];
    $r_bog = LTMS_Tax_Engine::calculate( 1_000_000.0,
        [ 'product_type' => 'physical' ],
        [ 'tax_regime' => 'common', 'municipality_code' => '11001', 'ciiu_code' => '4791' ],
        'CO'
    );
    $r_cal = LTMS_Tax_Engine::calculate( 1_000_000.0,
        [ 'product_type' => 'physical' ],
        [ 'tax_regime' => 'common', 'municipality_code' => '76001', 'ciiu_code' => '4791' ],
        'CO'
    );
    if ( abs( $r_bog['reteica'] - $r_cal['reteica'] ) < 0.01 ) {
        return [ 'FAIL', "Bog={$r_bog['reteica']} == Cali={$r_cal['reteica']} (sin diferenciación)" ];
    }
    return [ 'PASS', "Bog={$r_bog['reteica']} (4.14‰), Cali={$r_cal['reteica']} (5.5‰)" ];
} );

ltms_test( 'Regla híbrida: comprador Cali gran contribuyente → tarifa Cali', function () {
    if ( ! class_exists( 'LTMS_Tax_Engine' ) ) return [ 'FAIL', 'Tax Engine no cargado' ];
    $result = LTMS_Tax_Engine::calculate( 1_000_000.0,
        [
            'product_type'                => 'physical',
            'buyer_is_gran_contribuyente' => true,
            'buyer_municipality_code'     => '76001', // Cali
        ],
        [ 'tax_regime' => 'common', 'municipality_code' => '11001', 'ciiu_code' => '4791' ], // Vendor Bogotá
        'CO'
    );
    return abs( $result['reteica'] - 5500.0 ) < 0.01
        ? [ 'PASS', "Bog→Cali gran contrib: ReteICA={$result['reteica']} (5.5‰ Cali ✓)" ]
        : [ 'FAIL', "ReteICA={$result['reteica']} (esperado 5500=5.5‰ Cali)" ];
} );

ltms_test( 'Regla híbrida: comprador Cali NO gran contribuyente → tarifa vendedor (Bogotá)', function () {
    if ( ! class_exists( 'LTMS_Tax_Engine' ) ) return [ 'FAIL', 'Tax Engine no cargado' ];
    $result = LTMS_Tax_Engine::calculate( 1_000_000.0,
        [
            'product_type'                => 'physical',
            'buyer_is_gran_contribuyente' => false,
            'buyer_municipality_code'     => '76001',
        ],
        [ 'tax_regime' => 'common', 'municipality_code' => '11001', 'ciiu_code' => '4791' ],
        'CO'
    );
    return abs( $result['reteica'] - 4140.0 ) < 0.01
        ? [ 'PASS', "B2C: usa tarifa Bogotá (4.14‰ = {$result['reteica']})" ]
        : [ 'FAIL', "ReteICA={$result['reteica']} (esperado 4140; regla híbrida usa municipio incorrecto)" ];
} );

ltms_test( 'Fallback hardcoded cuando municipio desconocido (back-compat)', function () {
    if ( ! class_exists( 'LTMS_Tax_Engine' ) ) return [ 'FAIL', 'Tax Engine no cargado' ];
    $result = LTMS_Tax_Engine::calculate( 1_000_000.0,
        [ 'product_type' => 'physical' ],
        [ 'tax_regime' => 'common', 'municipality_code' => '99999', 'ciiu_code' => '5000' ],
        'CO'
    );
    return abs( $result['reteica'] - 9660.0 ) < 0.01
        ? [ 'PASS', "Fallback prefix '5' → {$result['reteica']} (0.966%)" ]
        : [ 'FAIL', "ReteICA={$result['reteica']} (esperado 9660 desde fallback hardcoded)" ];
} );

// ─────────────────────────────────────────────────────────────────────────────
// CLEANUP
// ─────────────────────────────────────────────────────────────────────────────
ltms_cleanup_user( $qa_vendor );

// ─────────────────────────────────────────────────────────────────────────────
// REPORTE FINAL
// ─────────────────────────────────────────────────────────────────────────────
$total = $ltms_pass + $ltms_warn + $ltms_fail;
echo "\n{$ltms_sep}\n REPORTE FINAL\n{$ltms_sep}\n";
printf( "\n  Total pruebas:    %d\n", $total );
printf( "  ✅ Pasadas:       %d\n", $ltms_pass );
printf( "  ⚠️  Advertencias:  %d\n", $ltms_warn );
printf( "  ❌ Fallidas:      %d\n\n", $ltms_fail );
$score = $total > 0 ? round( 100 * $ltms_pass / $total ) : 0;
printf( "  Score: %d/%d (%d%%)\n\n", $ltms_pass, $total, $score );

echo "{$ltms_sep2}\nResultados por grupo:\n";
$by_group = [];
foreach ( $ltms_results as $r ) {
    $g = $r['ltms_group'];
    $by_group[ $g ] = $by_group[ $g ] ?? [ 'pass' => 0, 'warn' => 0, 'fail' => 0 ];
    $by_group[ $g ][ strtolower( $r['status'] ) ]++;
}
foreach ( $by_group as $g => $c ) {
    $icon = $c['fail'] > 0 ? '❌' : ( $c['warn'] > 0 ? '⚠️ ' : '✅' );
    printf( "  %s %-42s  ✅%-3d ⚠️ %-3d ❌%-3d\n", $icon, $g, $c['pass'], $c['warn'], $c['fail'] );
}

if ( $ltms_fail > 0 ) {
    echo "\n{$ltms_sep2}\n❌ PRUEBAS FALLIDAS (acción requerida):\n";
    foreach ( $ltms_results as $r ) {
        if ( $r['status'] === 'FAIL' )
            printf( "  ❌ [%s] %s\n     → %s\n\n", $r['ltms_group'], $r['name'], $r['detail'] );
    }
}

if ( $ltms_warn > 0 ) {
    echo "\n{$ltms_sep2}\n⚠️  ADVERTENCIAS (revisar en producción):\n";
    foreach ( $ltms_results as $r ) {
        if ( $r['status'] === 'WARN' )
            printf( "  ⚠️  [%s] %s\n     → %s\n\n", $r['ltms_group'], $r['name'], $r['detail'] );
    }
}

echo "\n{$ltms_sep}\n";
echo " Fin: " . gmdate( 'Y-m-d H:i:s T' ) . "\n";
echo " Para ejecutar en staging:\n";
echo "   wp --path=/home/customer/www/lo-tengo.com.co/public_html \\\n";
echo "      eval-file wp-content/plugins/lt-marketplace-suite/bin/ltms-integration-tests.php \\\n";
echo "      --allow-root 2>&1 | tee /tmp/ltms-qa-\$(date +%Y%m%d-%H%M).log\n";
echo "{$ltms_sep}\n\n";

if ( isset($ltms_log_fh) && $ltms_log_fh ) {
    fclose( $ltms_log_fh );
    echo "📄 Log guardado en: $ltms_log_path\n";
}
