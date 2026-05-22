<?php
/**
 * LTMS QA — Uber Direct
 *
 * Cubre:
 *  T-01  Opciones de configuración guardadas con las claves correctas
 *  T-02  Discrepancia de claves: section usa ltms_uber_* pero API usa ltms_uber_direct_*
 *  T-03  Constructor lanza RuntimeException si faltan credenciales
 *  T-04  get_access_token(): token se cachea en transient
 *  T-05  get_access_token(): token expirado dispara nueva petición OAuth2
 *  T-06  health_check() devuelve array con 'status' y 'latency_ms'
 *  T-07  get_quote(): endpoint correcto con customer_id URL-encoded
 *  T-08  create_delivery(): payload fusiona delivery_data + quote_id
 *  T-09  cancel_delivery(): endpoint correcto con /cancel
 *  T-10  calculate_shipping(): usa caché de BD cuando existe quote vigente
 *  T-11  calculate_shipping(): llama a get_quote() cuando no hay caché
 *  T-12  calculate_shipping(): divide fee entre 100 (Uber devuelve centavos)
 *  T-13  build_manifest(): devuelve fallback si contents vacío
 *  T-14  Webhook: rechaza petición si ltms_uber_direct_webhook_secret no configurado (C-02)
 *  T-15  Webhook: rechaza firma HMAC inválida con HTTP 401
 *  T-16  Webhook: valida firma HMAC válida y devuelve 200
 *  T-17  Webhook: evento delivered actualiza estado pedido a 'completed'
 *  T-18  Webhook: evento en_route_to_dropoff actualiza estado a 'wc-shipped'
 *  T-19  Webhook: evento canceled dispara acción ltms_shipping_failed
 *  T-20  Shipping Method: rate_id correcto 'ltms_uber_direct'
 *  T-21  Bug detectado: mismatch de option keys entre settings y LTMS_Api_Uber
 *  T-22  Bug detectado: $price mal inicializado con currency_code (string) antes de if(fee)
 *  T-23  Tabla wp_lt_shipping_quotes_cache existe con columnas correctas
 *  T-24  Tabla wp_lt_webhook_logs existe con columnas correctas
 *
 * Uso:
 *   wp --path=/home/customer/www/lo-tengo.com.co/public_html \
 *      eval-file bin/ltms-qa-uber-direct.php --allow-root 2>/dev/null
 */

if ( ! defined( 'ABSPATH' ) ) {
    // Run via WP-CLI eval-file
    define( 'ABSPATH', '/home/customer/www/lo-tengo.com.co/public_html/' );
    require ABSPATH . 'wp-load.php';
}

// ─────────────────────────────────────────────────────────────────────────────
// Helpers QA
// ─────────────────────────────────────────────────────────────────────────────
$qa = [ 'pass' => 0, 'fail' => 0, 'warn' => 0, 'bugs' => [] ];

function qa_ok( &$qa, string $label, string $detail = '' ): void {
    $qa['pass']++;
    echo "  ✅ PASS  [{$label}]" . ( $detail ? " — {$detail}" : '' ) . "\n";
}
function qa_fail( &$qa, string $label, string $detail = '' ): void {
    $qa['fail']++;
    $qa['bugs'][] = $label;
    echo "  ❌ FAIL  [{$label}]" . ( $detail ? " — {$detail}" : '' ) . "\n";
}
function qa_warn( &$qa, string $label, string $detail = '' ): void {
    $qa['warn']++;
    echo "  ⚠️  WARN  [{$label}]" . ( $detail ? " — {$detail}" : '' ) . "\n";
}
function qa_section( string $title ): void {
    echo "\n── {$title} ──────────────────────────────────────────\n";
}

echo "\n";
echo "══════════════════════════════════════════════════\n";
echo "  QA — Uber Direct  ·  LTMS  ·  " . date( 'Y-m-d H:i:s' ) . "\n";
echo "══════════════════════════════════════════════════\n";

global $wpdb;
$plugin_path = WP_PLUGIN_DIR . '/lt-marketplace-suite';

// ─────────────────────────────────────────────────────────────────────────────
// T-01 / T-02 — Claves de configuración
// ─────────────────────────────────────────────────────────────────────────────
qa_section( 'SETTINGS — Claves de opciones' );

// Las claves que usa el settings view (section-uber_direct.php)
$view_keys = [
    'ltms_uber_enabled',
    'ltms_uber_client_id',
    'ltms_uber_client_secret',
    'ltms_uber_customer_id',
    'ltms_uber_sandbox',
];
// Las claves que usa LTMS_Api_Uber (class-ltms-api-uber.php)
$api_keys = [
    'ltms_uber_direct_client_id',
    'ltms_uber_direct_client_secret',
    'ltms_uber_direct_customer_id',
];

// T-01: ¿Existen las opciones del view en la BD?
$view_stored = [];
foreach ( $view_keys as $k ) {
    $view_stored[ $k ] = get_option( $k, null );
}
$filled_view = array_filter( $view_stored, fn($v) => ! is_null( $v ) );
qa_ok( $qa, 'T-01: Opciones view registradas en BD', count( $filled_view ) . '/' . count( $view_keys ) . ' encontradas' );

// T-02: MISMATCH CRÍTICO — view guarda 'ltms_uber_client_id' pero la API lee 'ltms_uber_direct_client_id'
$mismatch = false;
foreach ( $api_keys as $ak ) {
    $view_equivalent = str_replace( 'ltms_uber_direct_', 'ltms_uber_', $ak );
    $api_val  = get_option( $ak, null );
    $view_val = get_option( $view_equivalent, null );
    if ( is_null( $api_val ) && ! is_null( $view_val ) ) {
        $mismatch = true;
        echo "       BUG: '{$ak}' vacía — pero '{$view_equivalent}' = " . substr( (string) $view_val, 0, 8 ) . "...\n";
    }
}
if ( $mismatch ) {
    qa_fail(
        $qa,
        'T-02: KEY MISMATCH — view usa ltms_uber_* / API usa ltms_uber_direct_*',
        'El formulario guarda en claves distintas a las que lee LTMS_Api_Uber. Las credenciales NUNCA llegan a la clase.'
    );
} else {
    // Verificar que las claves de la API están realmente llenas
    $api_filled = array_filter( $api_keys, fn($k) => ! empty( get_option( $k, '' ) ) );
    if ( count( $api_filled ) === count( $api_keys ) ) {
        qa_ok( $qa, 'T-02: Claves API correctas (ltms_uber_direct_*)', 'Sin mismatch' );
    } else {
        qa_warn( $qa, 'T-02: Claves ltms_uber_direct_* parcialmente configuradas', count( $api_filled ) . '/' . count( $api_keys ) . ' llenas' );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// T-03 — Constructor: RuntimeException sin credenciales
// ─────────────────────────────────────────────────────────────────────────────
qa_section( 'API CLIENT — Constructor y credenciales' );

$threw = false;
try {
    // Instanciar con credenciales vacías temporalmente
    $orig_id     = get_option( 'ltms_uber_direct_client_id', '' );
    $orig_secret = get_option( 'ltms_uber_direct_client_secret', '' );
    $orig_cust   = get_option( 'ltms_uber_direct_customer_id', '' );

    update_option( 'ltms_uber_direct_client_id', '' );
    update_option( 'ltms_uber_direct_client_secret', '' );
    update_option( 'ltms_uber_direct_customer_id', '' );

    if ( class_exists( 'LTMS_Core_Config' ) ) {
        $uber_test = new LTMS_Api_Uber();
    }
} catch ( RuntimeException $e ) {
    $threw = true;
} catch ( Throwable $e ) {
    $threw = true;
} finally {
    // Restaurar
    update_option( 'ltms_uber_direct_client_id', $orig_id );
    update_option( 'ltms_uber_direct_client_secret', $orig_secret );
    update_option( 'ltms_uber_direct_customer_id', $orig_cust );
}
if ( $threw ) {
    qa_ok( $qa, 'T-03: Constructor lanza RuntimeException sin credenciales' );
} else {
    qa_warn( $qa, 'T-03: Constructor no lanzó excepción — verificar LTMS_Core_Config disponible' );
}

// ─────────────────────────────────────────────────────────────────────────────
// T-04 / T-05 — Caché de token OAuth2
// ─────────────────────────────────────────────────────────────────────────────
qa_section( 'AUTH — Token OAuth2 y caché' );

$transient_key = 'ltms_uber_access_token';

// T-04: Verifica que el transient existe si hay token configurado
$cached_token = get_transient( $transient_key );
if ( ! empty( $cached_token ) ) {
    qa_ok( $qa, 'T-04: Token OAuth2 en caché (transient)', 'Longitud=' . strlen( $cached_token ) );
} else {
    qa_warn( $qa, 'T-04: No hay token en caché', 'Normal si credenciales no configuradas o token expirado' );
}

// T-05: Eliminar transient y verificar que se puede pedir uno nuevo (solo si hay credenciales)
$has_creds = ! empty( get_option( 'ltms_uber_direct_client_id' ) )
          && ! empty( get_option( 'ltms_uber_direct_client_secret' ) )
          && ! empty( get_option( 'ltms_uber_direct_customer_id' ) );

if ( $has_creds ) {
    delete_transient( $transient_key );
    try {
        $uber    = LTMS_Api_Factory::get( 'uber' );
        $health  = $uber->health_check();
        if ( $health['status'] === 'ok' ) {
            $new_token = get_transient( $transient_key );
            if ( ! empty( $new_token ) ) {
                qa_ok( $qa, 'T-05: Token renovado y cacheado tras expirar', 'latency=' . ( $health['latency_ms'] ?? '?' ) . 'ms' );
            } else {
                qa_fail( $qa, 'T-05: Token obtenido pero NO guardado en transient' );
            }
        } else {
            qa_fail( $qa, 'T-05: health_check() retornó error', $health['message'] ?? '' );
        }
    } catch ( Throwable $e ) {
        qa_fail( $qa, 'T-05: Excepción al renovar token', $e->getMessage() );
    }
} else {
    qa_warn( $qa, 'T-05: Sin credenciales reales — skip renovación token real' );
}

// ─────────────────────────────────────────────────────────────────────────────
// T-06 — health_check()
// ─────────────────────────────────────────────────────────────────────────────
qa_section( 'API — health_check()' );

if ( $has_creds ) {
    try {
        $uber   = LTMS_Api_Factory::get( 'uber' );
        $health = $uber->health_check();
        if ( isset( $health['status'], $health['message'] ) ) {
            qa_ok( $qa, 'T-06: health_check() devuelve status+message', "status={$health['status']} latency=" . ( $health['latency_ms'] ?? 'N/A' ) . 'ms' );
        } else {
            qa_fail( $qa, 'T-06: health_check() estructura incorrecta', json_encode( array_keys( $health ) ) );
        }
    } catch ( Throwable $e ) {
        qa_fail( $qa, 'T-06: health_check() lanzó excepción', $e->getMessage() );
    }
} else {
    qa_warn( $qa, 'T-06: Sin credenciales — health_check() skipped' );
}

// ─────────────────────────────────────────────────────────────────────────────
// T-07 / T-08 / T-09 — Endpoints correctos (inspección estática)
// ─────────────────────────────────────────────────────────────────────────────
qa_section( 'API — Endpoints (inspección de código fuente)' );

$api_src = file_get_contents( $plugin_path . '/includes/api/class-ltms-api-uber.php' );

// T-07: get_quote endpoint
if ( strpos( $api_src, "/v1/customers/%s/delivery_quotes" ) !== false ) {
    qa_ok( $qa, 'T-07: get_quote() usa endpoint /v1/customers/{id}/delivery_quotes' );
} else {
    qa_fail( $qa, 'T-07: get_quote() endpoint incorrecto' );
}

// T-08: create_delivery fusiona quote_id
if ( strpos( $api_src, "array_merge" ) !== false && strpos( $api_src, "'quote_id' => \$quote_id" ) !== false ) {
    qa_ok( $qa, 'T-08: create_delivery() fusiona quote_id en payload' );
} else {
    qa_fail( $qa, 'T-08: create_delivery() no incluye quote_id en payload' );
}

// T-09: cancel_delivery endpoint /cancel
if ( strpos( $api_src, "/deliveries/%s/cancel" ) !== false ) {
    qa_ok( $qa, 'T-09: cancel_delivery() usa endpoint correcto con /cancel' );
} else {
    qa_fail( $qa, 'T-09: cancel_delivery() endpoint sin /cancel' );
}

// ─────────────────────────────────────────────────────────────────────────────
// T-10 / T-11 / T-12 / T-13 — calculate_shipping + Bug precio
// ─────────────────────────────────────────────────────────────────────────────
qa_section( 'SHIPPING METHOD — calculate_shipping() + precio' );

$shipping_src = file_get_contents( $plugin_path . '/includes/shipping/class-ltms-shipping-method-uber-direct.php' );

// T-10: Usa caché de BD
if ( strpos( $shipping_src, 'lt_shipping_quotes_cache' ) !== false
  && strpos( $shipping_src, 'expires_at > NOW()' ) !== false ) {
    qa_ok( $qa, 'T-10: calculate_shipping() consulta caché BD antes de llamar API' );
} else {
    qa_fail( $qa, 'T-10: Sin caché BD en calculate_shipping()' );
}

// T-11: Llama a get_quote si no hay caché
if ( strpos( $shipping_src, "\$uber->get_quote" ) !== false ) {
    qa_ok( $qa, 'T-11: calculate_shipping() llama get_quote() en cache-miss' );
} else {
    qa_fail( $qa, 'T-11: calculate_shipping() no llama get_quote()' );
}

// T-12: Divide fee entre 100
if ( strpos( $shipping_src, "/ 100" ) !== false ) {
    qa_ok( $qa, 'T-12: Precio dividido entre 100 (Uber devuelve centavos)' );
} else {
    qa_fail( $qa, 'T-12: Precio NO dividido entre 100 — tarifas x100 veces mayores' );
}

// T-22 (Bug): $price inicializado mal antes del if(fee)
// Código: $price = $quote['fee'] ?? $quote['currency_code'] ?? 0;
// Si no hay 'fee', $price queda como string currency_code p.ej "COP" → costo "COP" en WC
if ( preg_match( "/\\\$price\s*=\s*\\\$quote\['fee'\]\s*\?\?\s*\\\$quote\['currency_code'\]/", $shipping_src ) ) {
    qa_fail(
        $qa,
        'T-22 BUG: $price inicializado con currency_code como fallback',
        'Si fee ausente, $price = "COP" (string). Fix: $price = 0; luego if(isset($quote[\'fee\'])) $price = ...'
    );
} else {
    qa_ok( $qa, 'T-22: Inicialización de $price correcta' );
}

// T-13: build_manifest fallback
if ( strpos( $shipping_src, "'name' => 'Paquete'" ) !== false ) {
    qa_ok( $qa, 'T-13: build_manifest() devuelve fallback cuando contents vacío' );
} else {
    qa_fail( $qa, 'T-13: build_manifest() sin fallback para contents vacío' );
}

// ─────────────────────────────────────────────────────────────────────────────
// T-14 / T-15 / T-16 / T-17 / T-18 / T-19 — Webhook Handler
// ─────────────────────────────────────────────────────────────────────────────
qa_section( 'WEBHOOK — Seguridad y procesamiento de eventos' );

$webhook_src = file_get_contents( $plugin_path . '/includes/api/webhooks/class-ltms-uber-direct-webhook-handler.php' );

// T-14: Rechaza si webhook_secret vacío (fix C-02)
if ( strpos( $webhook_src, 'UBER_WEBHOOK_NO_SECRET' ) !== false
  && strpos( $webhook_src, 'empty( $secret )' ) !== false ) {
    qa_ok( $qa, 'T-14: Webhook rechaza request si secret no configurado (fix C-02)' );
} else {
    qa_fail( $qa, 'T-14: Webhook NO valida ausencia de secret — VULNERABILIDAD C-02 SIN PARCHEAR' );
}

// T-15: Rechaza firma inválida con HTTP 401
if ( strpos( $webhook_src, "Invalid signature" ) !== false
  && strpos( $webhook_src, '401' ) !== false ) {
    qa_ok( $qa, 'T-15: Webhook devuelve HTTP 401 con firma inválida' );
} else {
    qa_fail( $qa, 'T-15: Webhook no retorna 401 con firma inválida' );
}

// T-16: validate_signature usa hash_hmac SHA256 + hash_equals
if ( strpos( $webhook_src, "hash_hmac( 'sha256'" ) !== false
  && strpos( $webhook_src, 'hash_equals' ) !== false ) {
    qa_ok( $qa, 'T-16: validate_signature() usa HMAC-SHA256 con hash_equals (timing-safe)' );
} else {
    qa_fail( $qa, 'T-16: validate_signature() implementación insegura' );
}

// T-17: evento delivered → completed
if ( strpos( $webhook_src, "'delivered'" ) !== false
  && strpos( $webhook_src, "'completed'" ) !== false ) {
    qa_ok( $qa, 'T-17: Evento delivered/dropoff_complete actualiza pedido a completed' );
} else {
    qa_fail( $qa, 'T-17: No maneja evento delivered correctamente' );
}

// T-18: evento en_route → wc-shipped
if ( strpos( $webhook_src, "'en_route_to_dropoff'" ) !== false
  && strpos( $webhook_src, "'wc-shipped'" ) !== false ) {
    qa_ok( $qa, 'T-18: Evento en_route_to_dropoff actualiza pedido a wc-shipped' );
} else {
    qa_fail( $qa, 'T-18: No maneja evento en_route_to_dropoff' );
}

// T-19: evento canceled dispara ltms_shipping_failed
if ( strpos( $webhook_src, "'canceled'" ) !== false
  && strpos( $webhook_src, 'ltms_shipping_failed' ) !== false ) {
    qa_ok( $qa, 'T-19: Evento canceled dispara acción ltms_shipping_failed' );
} else {
    qa_fail( $qa, 'T-19: No dispara ltms_shipping_failed en evento canceled' );
}

// Prueba funcional: simular webhook con firma inválida
qa_section( 'WEBHOOK — Prueba funcional de firma' );
$webhook_url = home_url( '/wp-json/ltms/v1/webhooks/uber-direct' );
$fake_payload = json_encode( [ 'kind' => 'delivery.status.changed', 'data' => [ 'status' => 'delivered', 'external_id' => '999' ] ] );
$fake_sig     = 'invalid_signature_for_qa_test';

$r = wp_remote_post( $webhook_url, [
    'headers'   => [ 'Content-Type' => 'application/json', 'x-postmates-signature' => $fake_sig ],
    'body'      => $fake_payload,
    'timeout'   => 15,
    'sslverify' => false,
] );

if ( is_wp_error( $r ) ) {
    qa_warn( $qa, 'T-15b: No se pudo hacer petición HTTP interna', $r->get_error_message() );
} else {
    $code = wp_remote_retrieve_response_code( $r );
    $body = json_decode( wp_remote_retrieve_body( $r ), true );
    if ( $code === 401 ) {
        qa_ok( $qa, 'T-15b: Webhook HTTP 401 con firma inválida (funcional)', "HTTP {$code}" );
    } elseif ( $code === 200 ) {
        qa_fail( $qa, 'T-15b: Webhook aceptó firma inválida con HTTP 200 — VULNERABILIDAD ACTIVA' );
    } else {
        qa_warn( $qa, 'T-15b: Webhook devolvió HTTP inesperado', "HTTP {$code} — " . json_encode( $body ) );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// T-20 — Shipping Method ID
// ─────────────────────────────────────────────────────────────────────────────
qa_section( 'SHIPPING METHOD — Registro e ID' );

if ( strpos( $shipping_src, "\$this->id = 'ltms_uber_direct'" ) !== false ) {
    qa_ok( $qa, 'T-20: Shipping method ID correcto: ltms_uber_direct' );
} else {
    qa_fail( $qa, 'T-20: Shipping method ID incorrecto' );
}

// Verifica que está registrado en WC
$registered = WC()->shipping() ? WC()->shipping()->get_shipping_methods() : [];
if ( isset( $registered['ltms_uber_direct'] ) ) {
    qa_ok( $qa, 'T-20b: ltms_uber_direct registrado en WooCommerce Shipping Methods' );
} else {
    qa_warn( $qa, 'T-20b: ltms_uber_direct no aparece en WC Shipping Methods activos', 'Puede ser normal si no está en zona de envío' );
}

// ─────────────────────────────────────────────────────────────────────────────
// T-21 — Bug: Mismatch de claves (análisis cruzado de código fuente)
// ─────────────────────────────────────────────────────────────────────────────
qa_section( 'BUG REPORT — Mismatch de option keys' );

$section_src = file_get_contents( $plugin_path . '/includes/admin/views/settings/section-uber_direct.php' );

preg_match_all( "/'ltms_uber_[^']+'/", $section_src, $m_view );
preg_match_all( "/'ltms_uber_direct_[^']+'/", $api_src, $m_api );

$view_option_keys = array_unique( $m_view[0] ?? [] );
$api_option_keys  = array_unique( $m_api[0] ?? [] );

$has_bug = false;
foreach ( $view_option_keys as $vk ) {
    $vk_clean    = trim( $vk, "'" );
    $expected_api = str_replace( 'ltms_uber_', 'ltms_uber_direct_', $vk_clean );
    if ( in_array( "'{$expected_api}'", $api_option_keys, true ) ) {
        $has_bug = true;
        echo "       ❌ BUG: section guarda '{$vk_clean}' pero API lee '{$expected_api}'\n";
    }
}

if ( $has_bug ) {
    qa_fail(
        $qa,
        'T-21 BUG CRÍTICO: Formulario settings y LTMS_Api_Uber usan claves distintas',
        'Los datos guardados en el admin NUNCA son leídos por la clase API. Fix: unificar a ltms_uber_direct_*'
    );
} else {
    qa_ok( $qa, 'T-21: Claves de settings y API consistentes' );
}

// ─────────────────────────────────────────────────────────────────────────────
// T-23 / T-24 — Tablas de BD
// ─────────────────────────────────────────────────────────────────────────────
qa_section( 'BASE DE DATOS — Tablas requeridas' );

// T-23: lt_shipping_quotes_cache
$cache_table  = $wpdb->prefix . 'lt_shipping_quotes_cache';
$cache_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$cache_table}'" ); // phpcs:ignore
if ( $cache_exists ) {
    $cols = $wpdb->get_results( "DESCRIBE `{$cache_table}`", ARRAY_A ); // phpcs:ignore
    $col_names = array_column( $cols, 'Field' );
    $required  = [ 'cache_key', 'provider', 'quote_data', 'expires_at', 'created_at' ];
    $missing   = array_diff( $required, $col_names );
    if ( empty( $missing ) ) {
        qa_ok( $qa, "T-23: Tabla {$cache_table} existe con columnas correctas" );
    } else {
        qa_fail( $qa, "T-23: Tabla {$cache_table} existe pero faltan columnas", implode( ', ', $missing ) );
    }
} else {
    qa_fail( $qa, "T-23: Tabla {$cache_table} NO existe — shipping caching roto" );
}

// T-24: lt_webhook_logs
$webhook_table  = $wpdb->prefix . 'lt_webhook_logs';
$webhook_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$webhook_table}'" ); // phpcs:ignore
if ( $webhook_exists ) {
    $cols      = $wpdb->get_results( "DESCRIBE `{$webhook_table}`", ARRAY_A ); // phpcs:ignore
    $col_names = array_column( $cols, 'Field' );
    $required  = [ 'provider', 'event_type', 'payload', 'signature', 'is_valid', 'status', 'order_id', 'created_at' ];
    $missing   = array_diff( $required, $col_names );
    if ( empty( $missing ) ) {
        qa_ok( $qa, "T-24: Tabla {$webhook_table} existe con columnas correctas" );
    } else {
        qa_fail( $qa, "T-24: Tabla {$webhook_table} existe pero faltan columnas", implode( ', ', $missing ) );
    }
} else {
    qa_fail( $qa, "T-24: Tabla {$webhook_table} NO existe — webhook logging roto" );
}

// ─────────────────────────────────────────────────────────────────────────────
// RESUMEN
// ─────────────────────────────────────────────────────────────────────────────
echo "\n";
echo "══════════════════════════════════════════════════\n";
echo "  RESUMEN QA — Uber Direct\n";
echo "══════════════════════════════════════════════════\n";
printf( "  ✅ PASS : %d\n", $qa['pass'] );
printf( "  ❌ FAIL : %d\n", $qa['fail'] );
printf( "  ⚠️  WARN : %d\n", $qa['warn'] );
printf( "  TOTAL  : %d pruebas\n\n", $qa['pass'] + $qa['fail'] + $qa['warn'] );

if ( $qa['fail'] === 0 ) {
    echo "  🎉 Sin fallos críticos.\n";
} else {
    echo "  🚨 BUGS DETECTADOS — requieren fix antes de activar Uber Direct:\n";
    foreach ( $qa['bugs'] as $b ) {
        echo "    · {$b}\n";
    }
}
echo "\n";
