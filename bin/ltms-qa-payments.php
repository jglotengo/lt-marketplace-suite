<?php
/**
 * LTMS QA — Pasarelas de Pago
 * Cubre: Openpay CO, Openpay MX, Stripe, Addi (BNPL), PSE
 *
 * Uso:
 *   wp --path=/home/customer/www/lo-tengo.com.co/public_html \
 *      eval-file bin/ltms-qa-payments.php --allow-root 2>/dev/null
 *
 * @version 1.0.0
 */

// ─── Bootstrap mínimo ─────────────────────────────────────────────────────────
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( __FILE__ ) . '/' );
}

// ─── Helpers ─────────────────────────────────────────────────────────────────
$qa = [ 'pass' => 0, 'fail' => 0, 'warn' => 0, 'details' => [] ];

function qa_ok( &$qa, string $test, string $detail = '' ): void {
    $qa['pass']++;
    $qa['details'][] = "  ✅ PASS  [{$test}]" . ( $detail ? " — {$detail}" : '' );
}
function qa_fail( &$qa, string $test, string $detail = '' ): void {
    $qa['fail']++;
    $qa['details'][] = "  ❌ FAIL  [{$test}]" . ( $detail ? " — {$detail}" : '' );
}
function qa_warn( &$qa, string $test, string $detail = '' ): void {
    $qa['warn']++;
    $qa['details'][] = "  ⚠️  WARN  [{$test}]" . ( $detail ? " — {$detail}" : '' );
}
function section( string $title ): void {
    echo "\n" . str_repeat( '─', 60 ) . "\n";
    echo "  🔷 {$title}\n";
    echo str_repeat( '─', 60 ) . "\n";
}

echo "\n" . str_repeat( '═', 60 ) . "\n";
echo "  LTMS QA — Pasarelas de Pago\n";
echo str_repeat( '═', 60 ) . "\n";

// ══════════════════════════════════════════════════════════════
// T-01  Opciones guardadas en BD
// ══════════════════════════════════════════════════════════════
section( 'T-01 · Opciones guardadas en BD' );

$settings = get_option( 'ltms_settings', [] );
if ( ! is_array( $settings ) ) {
    $settings = [];
}

// Campos esperados por pasarela según las capturas
$expected_fields = [
    // Openpay Colombia
    'openpay_enabled'        => 'Openpay CO activo (flag)',
    'openpay_merchant_id'    => 'Openpay CO — Merchant ID',
    'openpay_public_key'     => 'Openpay CO — Public Key',
    'openpay_private_key'    => 'Openpay CO — Private Key',
    'openpay_pse_enabled'    => 'Openpay CO — PSE activo',
    'openpay_webhook_token'  => 'Openpay CO — Token Webhook',
    // Addi BNPL
    'addi_enabled'           => 'Addi BNPL activo (flag)',
    'addi_client_id'         => 'Addi — Client ID',
    'addi_client_secret'     => 'Addi — Client Secret',
    'addi_ally_slug'         => 'Addi — Ally Slug',
    // Stripe
    'stripe_enabled'         => 'Stripe activo (flag)',
    'stripe_publishable_key' => 'Stripe — Publishable Key',
    'stripe_secret_key'      => 'Stripe — Secret Key',
    'stripe_webhook_secret'  => 'Stripe — Webhook Secret',
    // Openpay México
    'openpay_mx_enabled'     => 'Openpay MX activo (flag)',
    'openpay_mx_merchant_id' => 'Openpay MX — Merchant ID',
    'openpay_mx_public_key'  => 'Openpay MX — Public Key',
    'openpay_mx_private_key' => 'Openpay MX — Private Key',
];

// También pueden estar en wp_options como claves individuales
foreach ( $expected_fields as $key => $label ) {
    $val_settings = $settings[ $key ] ?? null;
    $val_option   = get_option( "ltms_{$key}", null );
    $val          = $val_settings ?? $val_option;

    if ( $val !== null && $val !== '' ) {
        // Enmascarar claves privadas en el log
        $display = in_array( $key, [ 'openpay_private_key', 'stripe_secret_key', 'addi_client_secret', 'openpay_mx_private_key' ], true )
            ? '***' . substr( (string) $val, -6 )
            : substr( (string) $val, 0, 40 );
        qa_ok( $qa, "Opción: {$key}", "{$label} → {$display}" );
    } else {
        qa_warn( $qa, "Opción ausente: {$key}", "{$label} — no configurado" );
    }
}

// ══════════════════════════════════════════════════════════════
// T-02  Clases de pasarela existen y son instanciables
// ══════════════════════════════════════════════════════════════
section( 'T-02 · Clases de pasarela — existencia e instanciación' );

$gateway_classes = [
    'LTMS_Gateway_Openpay'    => 'Openpay Colombia',
    'LTMS_Gateway_Openpay_MX' => 'Openpay México',
    'LTMS_Gateway_Stripe'     => 'Stripe Internacional',
    'LTMS_Gateway_Addi'       => 'Addi BNPL',
    'LTMS_Gateway_PSE'        => 'PSE (Openpay)',
];

foreach ( $gateway_classes as $class => $label ) {
    if ( class_exists( $class ) ) {
        qa_ok( $qa, "class_exists({$class})", $label );
        // Intentar instanciar
        try {
            $obj = new $class();
            qa_ok( $qa, "new {$class}()", 'Instanciación OK' );
        } catch ( \Throwable $e ) {
            qa_fail( $qa, "new {$class}()", 'ERROR: ' . $e->getMessage() );
        }
    } else {
        qa_warn( $qa, "class_exists({$class})", "{$label} — clase no encontrada (¿módulo desactivado?)" );
    }
}

// ══════════════════════════════════════════════════════════════
// T-03  Registro en WooCommerce payment_gateways
// ══════════════════════════════════════════════════════════════
section( 'T-03 · Registro en WooCommerce payment_gateways' );

$wc_gateways_raw = WC()->payment_gateways()->payment_gateways();
$wc_ids          = array_keys( $wc_gateways_raw );

$expected_wc_ids = [
    'ltms_openpay'    => 'Openpay CO',
    'ltms_openpay_mx' => 'Openpay MX',
    'ltms_stripe'     => 'Stripe',
    'ltms_addi'       => 'Addi BNPL',
    'ltms_pse'        => 'PSE',
];

foreach ( $expected_wc_ids as $gw_id => $label ) {
    if ( in_array( $gw_id, $wc_ids, true ) ) {
        $gw  = $wc_gateways_raw[ $gw_id ];
        $ena = $gw->enabled ?? 'no';
        qa_ok( $qa, "WC gateway: {$gw_id}", "{$label} registrado — enabled={$ena}" );
    } else {
        qa_warn( $qa, "WC gateway: {$gw_id}", "{$label} — NO registrado en WC" );
    }
}

// ══════════════════════════════════════════════════════════════
// T-04  Openpay CO — validación de credenciales (ping API)
// ══════════════════════════════════════════════════════════════
section( 'T-04 · Openpay CO — Ping a API' );

$op_merchant = $settings['openpay_merchant_id'] ?? get_option( 'ltms_openpay_merchant_id', '' );
$op_pub_key  = $settings['openpay_public_key']   ?? get_option( 'ltms_openpay_public_key', '' );
$op_prv_key  = $settings['openpay_private_key']  ?? get_option( 'ltms_openpay_private_key', '' );

if ( $op_merchant && $op_prv_key ) {
    // Endpoint sandbox y producción
    $endpoints = [
        'sandbox' => "https://sandbox-api.openpay.co/v1/{$op_merchant}/",
        'prod'    => "https://api.openpay.co/v1/{$op_merchant}/",
    ];

    foreach ( $endpoints as $env => $url ) {
        $resp = wp_remote_get( $url, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( $op_prv_key . ':' ),
            ],
            'timeout'   => 10,
            'sslverify' => true,
        ] );
        if ( is_wp_error( $resp ) ) {
            qa_warn( $qa, "Openpay CO ping [{$env}]", 'WP_Error: ' . $resp->get_error_message() );
        } else {
            $code = wp_remote_retrieve_response_code( $resp );
            if ( in_array( $code, [ 200, 201 ], true ) ) {
                qa_ok( $qa, "Openpay CO ping [{$env}]", "HTTP {$code} — credenciales válidas" );
            } elseif ( $code === 401 ) {
                qa_fail( $qa, "Openpay CO ping [{$env}]", "HTTP 401 — credenciales INVÁLIDAS" );
            } elseif ( $code === 404 ) {
                qa_warn( $qa, "Openpay CO ping [{$env}]", "HTTP 404 — merchant no encontrado en {$env}" );
            } else {
                qa_warn( $qa, "Openpay CO ping [{$env}]", "HTTP {$code}" );
            }
        }
    }
} else {
    qa_warn( $qa, 'Openpay CO ping', 'Sin merchant_id o private_key — omitiendo ping' );
}

// ══════════════════════════════════════════════════════════════
// T-05  Openpay CO — PSE habilitado
// ══════════════════════════════════════════════════════════════
section( 'T-05 · Openpay CO — PSE' );

$pse_enabled = $settings['openpay_pse_enabled'] ?? get_option( 'ltms_openpay_pse_enabled', '' );
if ( $pse_enabled ) {
    qa_ok( $qa, 'PSE enabled flag', "Valor: {$pse_enabled}" );
} else {
    qa_warn( $qa, 'PSE enabled flag', 'PSE no activado en configuración' );
}

// ══════════════════════════════════════════════════════════════
// T-06  Openpay CO — Webhook token configurado
// ══════════════════════════════════════════════════════════════
section( 'T-06 · Openpay CO — Webhook token' );

$webhook_token = $settings['openpay_webhook_token'] ?? get_option( 'ltms_openpay_webhook_token', '' );
if ( $webhook_token ) {
    qa_ok( $qa, 'Webhook token configurado', '***' . substr( $webhook_token, -6 ) );
} else {
    qa_warn( $qa, 'Webhook token', 'No configurado — webhooks de Openpay no podrán verificarse' );
}

// URL del webhook endpoint
$webhook_url = home_url( '/wp-json/ltms/v1/webhook/openpay' );
$resp        = wp_remote_get( $webhook_url, [ 'timeout' => 8, 'sslverify' => false ] );
if ( ! is_wp_error( $resp ) ) {
    $code = wp_remote_retrieve_response_code( $resp );
    // 200, 400 o 401 son aceptables (endpoint existe pero rechaza GET sin payload)
    if ( in_array( $code, [ 200, 400, 401, 403, 405 ], true ) ) {
        qa_ok( $qa, 'Webhook endpoint accesible', "GET {$webhook_url} → HTTP {$code}" );
    } else {
        qa_warn( $qa, 'Webhook endpoint', "HTTP {$code} — revisar registro de ruta REST" );
    }
} else {
    qa_warn( $qa, 'Webhook endpoint', 'Error al verificar: ' . $resp->get_error_message() );
}

// ══════════════════════════════════════════════════════════════
// T-07  Stripe — Ping API con Secret Key
// ══════════════════════════════════════════════════════════════
section( 'T-07 · Stripe — Ping API' );

$stripe_sk = $settings['stripe_secret_key'] ?? get_option( 'ltms_stripe_secret_key', '' );

if ( $stripe_sk ) {
    $resp = wp_remote_get( 'https://api.stripe.com/v1/balance', [
        'headers' => [ 'Authorization' => 'Bearer ' . $stripe_sk ],
        'timeout' => 10,
    ] );
    if ( is_wp_error( $resp ) ) {
        qa_warn( $qa, 'Stripe ping', 'WP_Error: ' . $resp->get_error_message() );
    } else {
        $code = wp_remote_retrieve_response_code( $resp );
        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( $code === 200 ) {
            $avail = $body['available'][0]['amount'] ?? '?';
            $curr  = $body['available'][0]['currency'] ?? '?';
            qa_ok( $qa, 'Stripe ping', "HTTP 200 — balance disponible: {$avail} {$curr}" );
        } elseif ( $code === 401 ) {
            qa_fail( $qa, 'Stripe ping', 'HTTP 401 — Secret Key INVÁLIDA' );
        } else {
            qa_warn( $qa, 'Stripe ping', "HTTP {$code}" );
        }
    }
} else {
    qa_warn( $qa, 'Stripe ping', 'Sin secret_key — omitiendo ping' );
}

// ══════════════════════════════════════════════════════════════
// T-08  Stripe — Publishable Key formato correcto
// ══════════════════════════════════════════════════════════════
section( 'T-08 · Stripe — Formato de claves' );

$stripe_pk = $settings['stripe_publishable_key'] ?? get_option( 'ltms_stripe_publishable_key', '' );

if ( $stripe_pk ) {
    if ( str_starts_with( $stripe_pk, 'pk_test_' ) ) {
        qa_ok( $qa, 'Stripe publishable key', 'Formato TEST válido (pk_test_...)' );
    } elseif ( str_starts_with( $stripe_pk, 'pk_live_' ) ) {
        qa_ok( $qa, 'Stripe publishable key', 'Formato LIVE válido (pk_live_...)' );
    } else {
        qa_fail( $qa, 'Stripe publishable key', 'Formato inesperado: ' . substr( $stripe_pk, 0, 10 ) . '...' );
    }
}
if ( $stripe_sk ) {
    if ( str_starts_with( $stripe_sk, 'sk_test_' ) ) {
        qa_ok( $qa, 'Stripe secret key', 'Formato TEST válido (sk_test_...)' );
    } elseif ( str_starts_with( $stripe_sk, 'sk_live_' ) ) {
        qa_ok( $qa, 'Stripe secret key', 'Formato LIVE válido (sk_live_...)' );
    } else {
        qa_fail( $qa, 'Stripe secret key', 'Formato inesperado: ' . substr( $stripe_sk, 0, 10 ) . '...' );
    }
}

// Coherencia test/live
if ( $stripe_pk && $stripe_sk ) {
    $pk_is_test = str_starts_with( $stripe_pk, 'pk_test_' );
    $sk_is_test = str_starts_with( $stripe_sk, 'sk_test_' );
    if ( $pk_is_test === $sk_is_test ) {
        qa_ok( $qa, 'Stripe coherencia test/live', 'PK y SK están en el mismo entorno' );
    } else {
        qa_fail( $qa, 'Stripe coherencia test/live', 'PK y SK están en ENTORNOS DISTINTOS — mezcla test/live' );
    }
}

// ══════════════════════════════════════════════════════════════
// T-09  Addi BNPL — Client ID / Secret presentes
// ══════════════════════════════════════════════════════════════
section( 'T-09 · Addi BNPL — Credenciales y Ping' );

$addi_client_id     = $settings['addi_client_id']     ?? get_option( 'ltms_addi_client_id', '' );
$addi_client_secret = $settings['addi_client_secret'] ?? get_option( 'ltms_addi_client_secret', '' );
$addi_ally_slug     = $settings['addi_ally_slug']     ?? get_option( 'ltms_addi_ally_slug', '' );

if ( $addi_client_id && $addi_client_secret ) {
    qa_ok( $qa, 'Addi credenciales presentes', "Client ID: {$addi_client_id}" );

    // Addi token endpoint (sandbox)
    $token_resp = wp_remote_post( 'https://auth-staging.addi.com/oauth/token', [
        'body'    => [
            'grant_type'    => 'client_credentials',
            'client_id'     => $addi_client_id,
            'client_secret' => $addi_client_secret,
            'audience'      => 'https://api-staging.addi.com',
        ],
        'timeout'   => 10,
        'sslverify' => true,
    ] );

    if ( is_wp_error( $token_resp ) ) {
        qa_warn( $qa, 'Addi token (staging)', 'WP_Error: ' . $token_resp->get_error_message() );
    } else {
        $code = wp_remote_retrieve_response_code( $token_resp );
        $body = json_decode( wp_remote_retrieve_body( $token_resp ), true );
        if ( $code === 200 && ! empty( $body['access_token'] ) ) {
            qa_ok( $qa, 'Addi token (staging)', 'HTTP 200 — access_token obtenido' );

            // Ping al endpoint de ally
            if ( $addi_ally_slug ) {
                $ally_resp = wp_remote_get(
                    "https://api-staging.addi.com/allies/{$addi_ally_slug}",
                    [
                        'headers' => [ 'Authorization' => 'Bearer ' . $body['access_token'] ],
                        'timeout' => 10,
                    ]
                );
                if ( ! is_wp_error( $ally_resp ) ) {
                    $ac = wp_remote_retrieve_response_code( $ally_resp );
                    if ( $ac === 200 ) {
                        qa_ok( $qa, 'Addi ally_slug válido', "GET /allies/{$addi_ally_slug} → 200" );
                    } else {
                        qa_warn( $qa, 'Addi ally_slug', "GET /allies/{$addi_ally_slug} → HTTP {$ac}" );
                    }
                }
            } else {
                qa_warn( $qa, 'Addi ally_slug', 'No configurado' );
            }
        } elseif ( $code === 401 ) {
            qa_fail( $qa, 'Addi token (staging)', 'HTTP 401 — credenciales inválidas' );
        } else {
            qa_warn( $qa, 'Addi token (staging)', "HTTP {$code}" );
        }
    }
} else {
    qa_warn( $qa, 'Addi credenciales', 'client_id o client_secret no configurados — omitiendo ping' );
}

// ══════════════════════════════════════════════════════════════
// T-10  Openpay MX — credenciales presentes
// ══════════════════════════════════════════════════════════════
section( 'T-10 · Openpay MX — credenciales y ping' );

$op_mx_merchant = $settings['openpay_mx_merchant_id'] ?? get_option( 'ltms_openpay_mx_merchant_id', '' );
$op_mx_pub      = $settings['openpay_mx_public_key']  ?? get_option( 'ltms_openpay_mx_public_key', '' );
$op_mx_prv      = $settings['openpay_mx_private_key'] ?? get_option( 'ltms_openpay_mx_private_key', '' );

if ( $op_mx_merchant && $op_mx_prv ) {
    $resp = wp_remote_get(
        "https://api.openpay.mx/v1/{$op_mx_merchant}/",
        [
            'headers' => [ 'Authorization' => 'Basic ' . base64_encode( $op_mx_prv . ':' ) ],
            'timeout' => 10,
        ]
    );
    if ( is_wp_error( $resp ) ) {
        qa_warn( $qa, 'Openpay MX ping', 'WP_Error: ' . $resp->get_error_message() );
    } else {
        $code = wp_remote_retrieve_response_code( $resp );
        if ( in_array( $code, [ 200, 201 ], true ) ) {
            qa_ok( $qa, 'Openpay MX ping', "HTTP {$code} — credenciales válidas" );
        } elseif ( $code === 401 ) {
            qa_fail( $qa, 'Openpay MX ping', 'HTTP 401 — credenciales INVÁLIDAS' );
        } else {
            qa_warn( $qa, 'Openpay MX ping', "HTTP {$code}" );
        }
    }
} else {
    qa_warn( $qa, 'Openpay MX', 'Sin credenciales — omitiendo ping' );
}

// ══════════════════════════════════════════════════════════════
// T-11  Encriptación de claves privadas en BD
// ══════════════════════════════════════════════════════════════
section( 'T-11 · Seguridad — claves privadas encriptadas en BD' );

global $wpdb;
$raw_private_keys = [
    'ltms_openpay_private_key',
    'ltms_stripe_secret_key',
    'ltms_addi_client_secret',
    'ltms_openpay_mx_private_key',
];
foreach ( $raw_private_keys as $opt_name ) {
    $raw = $wpdb->get_var( $wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
        $opt_name
    ) );
    if ( $raw === null ) {
        // También puede estar dentro de ltms_settings serializado
        qa_warn( $qa, "Encriptación: {$opt_name}", 'No encontrado como opción individual' );
        continue;
    }
    // Si el valor parece texto plano (comienza con sk_, pk_, etc.) es señal de que NO está cifrado
    if ( preg_match( '/^(sk_|pk_|rk_|whsec_)[a-zA-Z0-9_]{6,}/', $raw ) ) {
        qa_fail( $qa, "Encriptación: {$opt_name}", '⚠️  Valor en texto plano en BD — debe estar encriptado' );
    } elseif ( strlen( $raw ) > 20 ) {
        qa_ok( $qa, "Encriptación: {$opt_name}", 'Valor no es texto plano de API conocido (posible encriptado)' );
    } else {
        qa_warn( $qa, "Encriptación: {$opt_name}", "Valor corto ({$raw}) — verificar" );
    }
}

// También revisar dentro de ltms_settings como serializado
$raw_settings_blob = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'ltms_settings' LIMIT 1" );
if ( $raw_settings_blob ) {
    $danger_patterns = [ 'sk_test_', 'sk_live_', 'whsec_' ];
    foreach ( $danger_patterns as $pat ) {
        if ( str_contains( $raw_settings_blob, $pat ) ) {
            qa_fail( $qa, "ltms_settings BD contiene '{$pat}'", 'Clave Stripe en texto plano dentro de ltms_settings' );
        }
    }
}

// ══════════════════════════════════════════════════════════════
// T-12  Openpay CO — Merchant ID / Public Key no vacíos
// ══════════════════════════════════════════════════════════════
section( 'T-12 · Openpay CO — Credenciales de las capturas' );

// Según las capturas: mjnd8chjd6ujvwstd57k / pk_e7d5bf8f7c6e4111959559bc54687032
$expected_merchant = 'mjnd8chjd6ujvwstd57k';
$expected_pub_key  = 'pk_e7d5bf8f7c6e4111959559bc54687032';

if ( $op_merchant === $expected_merchant ) {
    qa_ok( $qa, 'Openpay CO merchant_id match', $op_merchant );
} elseif ( $op_merchant ) {
    qa_warn( $qa, 'Openpay CO merchant_id', "Guardado: {$op_merchant} | Esperado (capturas): {$expected_merchant}" );
} else {
    qa_fail( $qa, 'Openpay CO merchant_id', 'Vacío en BD' );
}

if ( $op_pub_key === $expected_pub_key ) {
    qa_ok( $qa, 'Openpay CO public_key match', $op_pub_key );
} elseif ( $op_pub_key ) {
    qa_warn( $qa, 'Openpay CO public_key', "Guardado: {$op_pub_key} | Esperado: {$expected_pub_key}" );
} else {
    qa_fail( $qa, 'Openpay CO public_key', 'Vacío en BD' );
}

// ══════════════════════════════════════════════════════════════
// T-13  Hooks de WooCommerce payment activos
// ══════════════════════════════════════════════════════════════
section( 'T-13 · Hooks WooCommerce — payment gateways filter' );

$hooks_to_check = [
    'woocommerce_payment_gateways'   => 'Registro de pasarelas en WC',
    'woocommerce_before_checkout_process' => 'Pre-process checkout',
    'woocommerce_checkout_order_processed' => 'Post-process checkout',
    'ltms_payment_openpay_webhook'   => 'Webhook Openpay (custom hook)',
    'ltms_payment_stripe_webhook'    => 'Webhook Stripe (custom hook)',
];

global $wp_filter;
foreach ( $hooks_to_check as $hook => $label ) {
    if ( isset( $wp_filter[ $hook ] ) && count( $wp_filter[ $hook ]->callbacks ) > 0 ) {
        $count = array_sum( array_map( 'count', $wp_filter[ $hook ]->callbacks ) );
        qa_ok( $qa, "Hook: {$hook}", "{$label} — {$count} callback(s)" );
    } else {
        qa_warn( $qa, "Hook: {$hook}", "{$label} — sin callbacks registrados" );
    }
}

// ══════════════════════════════════════════════════════════════
// T-14  REST API endpoints de pago
// ══════════════════════════════════════════════════════════════
section( 'T-14 · REST API — endpoints de pago registrados' );

$rest_routes = rest_get_server()->get_routes();
$payment_routes = [
    '/ltms/v1/webhook/openpay'    => 'Webhook Openpay CO',
    '/ltms/v1/webhook/stripe'     => 'Webhook Stripe',
    '/ltms/v1/webhook/addi'       => 'Webhook Addi',
    '/ltms/v1/payment/openpay'    => 'Payment Openpay',
    '/ltms/v1/payment/stripe'     => 'Payment Stripe',
    '/ltms/v1/payment/addi'       => 'Payment Addi',
];

foreach ( $payment_routes as $route => $label ) {
    if ( array_key_exists( $route, $rest_routes ) ) {
        $methods = array_keys( $rest_routes[ $route ] );
        qa_ok( $qa, "REST: {$route}", "{$label} — métodos: " . implode( ', ', $methods ) );
    } else {
        qa_warn( $qa, "REST: {$route}", "{$label} — ruta NO registrada" );
    }
}

// ══════════════════════════════════════════════════════════════
// T-15  Tabla de transacciones de pago en BD
// ══════════════════════════════════════════════════════════════
section( 'T-15 · BD — Tabla ltms_payment_transactions' );

$table = $wpdb->prefix . 'ltms_payment_transactions';
$exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
if ( $exists === $table ) {
    $count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
    $cols   = $wpdb->get_results( "DESCRIBE `{$table}`", ARRAY_A );
    $col_names = array_column( $cols, 'Field' );
    qa_ok( $qa, "Tabla {$table} existe", "{$count} transacciones registradas" );

    $required_cols = [ 'id', 'order_id', 'gateway', 'status', 'amount', 'currency', 'created_at' ];
    foreach ( $required_cols as $col ) {
        if ( in_array( $col, $col_names, true ) ) {
            qa_ok( $qa, "Columna: {$col}", "En {$table}" );
        } else {
            qa_fail( $qa, "Columna faltante: {$col}", "No existe en {$table}" );
        }
    }
} else {
    qa_warn( $qa, "Tabla {$table}", 'No existe — pagos no se están registrando en BD propia' );
}

// ══════════════════════════════════════════════════════════════
// T-16  Openpay CO — Listado de métodos de pago disponibles
// ══════════════════════════════════════════════════════════════
section( 'T-16 · Openpay CO — Obtener lista PSE bancos (API real)' );

if ( $op_merchant && $op_prv_key ) {
    $pse_url  = "https://api.openpay.co/v1/{$op_merchant}/pseBanks";
    $resp     = wp_remote_get( $pse_url, [
        'headers' => [ 'Authorization' => 'Basic ' . base64_encode( $op_prv_key . ':' ) ],
        'timeout' => 10,
    ] );
    if ( ! is_wp_error( $resp ) ) {
        $code  = wp_remote_retrieve_response_code( $resp );
        $banks = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( $code === 200 && is_array( $banks ) ) {
            qa_ok( $qa, 'Openpay CO — pseBanks', count( $banks ) . ' bancos disponibles' );
        } elseif ( $code === 401 ) {
            qa_fail( $qa, 'Openpay CO — pseBanks', 'HTTP 401 — credenciales inválidas' );
        } else {
            qa_warn( $qa, 'Openpay CO — pseBanks', "HTTP {$code}" );
        }
    } else {
        qa_warn( $qa, 'Openpay CO — pseBanks', $resp->get_error_message() );
    }
} else {
    qa_warn( $qa, 'Openpay CO — pseBanks', 'Sin credenciales' );
}

// ══════════════════════════════════════════════════════════════
// T-17  Modo test/producción detectado
// ══════════════════════════════════════════════════════════════
section( 'T-17 · Modo TEST vs PRODUCCIÓN' );

$openpay_mode = $settings['openpay_test_mode'] ?? get_option( 'ltms_openpay_test_mode', 'unknown' );
$stripe_mode  = $stripe_pk ? ( str_starts_with( $stripe_pk, 'pk_test_' ) ? 'test' : 'live' ) : 'unknown';

echo "  ℹ️  Openpay mode flag: " . ( $openpay_mode ?: 'no definido' ) . "\n";
echo "  ℹ️  Stripe (por PK): {$stripe_mode}\n";

if ( $openpay_mode === 'test' || $openpay_mode === '1' ) {
    qa_warn( $qa, 'Openpay en modo TEST', 'Las transacciones NO son reales' );
} elseif ( $openpay_mode === 'live' || $openpay_mode === '0' || $openpay_mode === '' ) {
    qa_ok( $qa, 'Openpay en modo LIVE', 'Producción activa' );
} else {
    qa_warn( $qa, 'Openpay mode', 'No determinado — revisar ltms_openpay_test_mode' );
}

if ( $stripe_mode === 'test' ) {
    qa_warn( $qa, 'Stripe en modo TEST', 'Claves pk_test/sk_test — no es producción' );
} elseif ( $stripe_mode === 'live' ) {
    qa_ok( $qa, 'Stripe en modo LIVE', 'Claves pk_live/sk_live' );
}

// ══════════════════════════════════════════════════════════════
// RESUMEN FINAL
// ══════════════════════════════════════════════════════════════
echo "\n" . str_repeat( '═', 60 ) . "\n";
echo "  RESUMEN QA — Pasarelas de Pago\n";
echo str_repeat( '═', 60 ) . "\n";

foreach ( $qa['details'] as $line ) {
    echo $line . "\n";
}

echo "\n" . str_repeat( '─', 60 ) . "\n";
printf( "  ✅ PASS : %d\n", $qa['pass'] );
printf( "  ❌ FAIL : %d\n", $qa['fail'] );
printf( "  ⚠️  WARN : %d\n", $qa['warn'] );
printf( "  TOTAL  : %d pruebas\n", $qa['pass'] + $qa['fail'] + $qa['warn'] );
echo str_repeat( '─', 60 ) . "\n";

if ( $qa['fail'] === 0 ) {
    echo "\n  🎉 Sin fallos críticos.\n\n";
} else {
    echo "\n  🚨 Hay {$qa['fail']} fallos que requieren atención.\n\n";
}
