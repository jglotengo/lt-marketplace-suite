<?php
/**
 * QA Script — Pasarelas de Pago LTMS v2
 * Cubre: Openpay CO, PSE, Addi, Stripe, Openpay MX
 *
 * Uso: wp --path=... eval-file bin/ltms-qa-payments-v2.php --allow-root
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$qa = [ 'pass' => 0, 'fail' => 0, 'warn' => 0 ];

function qa_ok( &$qa, string $label, string $detail = '' ): void {
    $qa['pass']++;
    echo "  ✅ PASS  $label" . ( $detail ? " — $detail" : '' ) . "\n";
}
function qa_fail( &$qa, string $label, string $detail = '' ): void {
    $qa['fail']++;
    echo "  ❌ FAIL  $label" . ( $detail ? " — $detail" : '' ) . "\n";
}
function qa_warn( &$qa, string $label, string $detail = '' ): void {
    $qa['warn']++;
    echo "  ⚠️  WARN  $label" . ( $detail ? " — $detail" : '' ) . "\n";
}

echo "\n══════════════════════════════════════════════════\n";
echo "  QA — Pasarelas de Pago LTMS\n";
echo "══════════════════════════════════════════════════\n\n";

// ─── T-01: Gateways registrados en WooCommerce ─────────────────────────────
echo "── T-01: Gateways registrados\n";
$gws = WC()->payment_gateways()->payment_gateways();
$expected = [
    'ltms_openpay'    => 'Openpay CO',
    'ltms_pse'        => 'PSE CO',
    'ltms_addi'       => 'Addi BNPL',
    'ltms_stripe'     => 'Stripe',
    'ltms_openpay_mx' => 'Openpay MX',
];
foreach ( $expected as $id => $label ) {
    if ( isset( $gws[$id] ) ) {
        qa_ok( $qa, "$label ($id) registrado" );
    } else {
        qa_fail( $qa, "$label ($id) NO registrado" );
    }
}

// ─── T-02: Clases existentes ───────────────────────────────────────────────
echo "\n── T-02: Clases PHP existentes\n";
$classes = [
    'LTMS_Api_Gateway_Openpay'    => 'Gateway Openpay CO',
    'LTMS_Api_Gateway_PSE'        => 'Gateway PSE',
    'LTMS_Api_Gateway_Addi'       => 'Gateway Addi',
    'LTMS_Gateway_Stripe'         => 'Gateway Stripe',
    'LTMS_Api_Gateway_Openpay_MX' => 'Gateway Openpay MX',
    'LTMS_Api_Openpay'            => 'API Client Openpay',
    'LTMS_Api_Stripe'             => 'API Client Stripe',
    'LTMS_Api_Addi'               => 'API Client Addi',
];
foreach ( $classes as $class => $label ) {
    if ( class_exists( $class ) ) {
        qa_ok( $qa, "$label ($class)" );
    } else {
        qa_fail( $qa, "$label ($class) — clase no existe" );
    }
}

// ─── T-03: Instanciación sin errores ──────────────────────────────────────
echo "\n── T-03: Instanciación de gateways\n";
$gateway_classes = [
    'LTMS_Api_Gateway_Openpay'    => 'Openpay CO',
    'LTMS_Api_Gateway_PSE'        => 'PSE',
    'LTMS_Api_Gateway_Addi'       => 'Addi',
    'LTMS_Gateway_Stripe'         => 'Stripe',
    'LTMS_Api_Gateway_Openpay_MX' => 'Openpay MX',
];
$instances = [];
foreach ( $gateway_classes as $class => $label ) {
    try {
        if ( class_exists( $class ) ) {
            $instances[$class] = new $class();
            qa_ok( $qa, "new $class() sin error" );
        } else {
            qa_fail( $qa, "new $class() — clase no existe" );
        }
    } catch ( \Throwable $e ) {
        qa_fail( $qa, "new $class() lanzó excepción", $e->getMessage() );
    }
}

// ─── T-04: IDs correctos ──────────────────────────────────────────────────
echo "\n── T-04: IDs de gateway\n";
$expected_ids = [
    'LTMS_Api_Gateway_Openpay'    => 'ltms_openpay',
    'LTMS_Api_Gateway_PSE'        => 'ltms_pse',
    'LTMS_Api_Gateway_Addi'       => 'ltms_addi',
    'LTMS_Gateway_Stripe'         => 'ltms_stripe',
    'LTMS_Api_Gateway_Openpay_MX' => 'ltms_openpay_mx',
];
foreach ( $expected_ids as $class => $expected_id ) {
    if ( isset( $instances[$class] ) ) {
        $actual_id = $instances[$class]->id;
        if ( $actual_id === $expected_id ) {
            qa_ok( $qa, "$class → id='$actual_id'" );
        } else {
            qa_fail( $qa, "$class → id='$actual_id' (esperado '$expected_id')" );
        }
    }
}

// ─── T-05: Credenciales configuradas ──────────────────────────────────────
echo "\n── T-05: Credenciales en BD\n";
$s = get_option( 'ltms_settings', [] );
$creds = [
    'openpay_merchant_id' => 'Openpay CO Merchant ID',
    'openpay_public_key'  => 'Openpay CO Public Key',
    'openpay_private_key' => 'Openpay CO Private Key',
];
foreach ( $creds as $key => $label ) {
    $v = $s[$key] ?? get_option( 'ltms_' . $key, '' );
    if ( ! empty( $v ) ) {
        qa_ok( $qa, "$label configurado" );
    } else {
        qa_warn( $qa, "$label vacío — configurar en WC > Pagos" );
    }
}
$creds_warn = [
    'openpay_mx_merchant_id' => 'Openpay MX Merchant ID',
    'openpay_mx_private_key' => 'Openpay MX Private Key',
    'addi_client_id'         => 'Addi Client ID',
    'stripe_publishable_key' => 'Stripe Publishable Key',
    'stripe_secret_key'      => 'Stripe Secret Key',
];
foreach ( $creds_warn as $key => $label ) {
    $v = $s[$key] ?? get_option( 'ltms_' . $key, '' );
    if ( ! empty( $v ) ) {
        qa_ok( $qa, "$label configurado" );
    } else {
        qa_warn( $qa, "$label vacío — pendiente de configurar" );
    }
}

// ─── T-06: Openpay CO — sandbox ping ──────────────────────────────────────
echo "\n── T-06: Openpay CO API ping (sandbox)\n";
$op_merchant = $s['openpay_merchant_id'] ?? get_option( 'ltms_openpay_merchant_id', '' );
$op_privkey  = $s['openpay_private_key']  ?? get_option( 'ltms_openpay_private_key', '' );
$op_pubkey   = $s['openpay_public_key']   ?? get_option( 'ltms_openpay_public_key', '' );

if ( ! empty( $op_merchant ) && ! empty( $op_privkey ) && class_exists( 'LTMS_Api_Openpay' ) ) {
    try {
        $openpay_co = new LTMS_Api_Openpay( $op_merchant, $op_privkey, $op_pubkey, 'CO', true );
        $health     = $openpay_co->health_check();
        if ( ( $health['status'] ?? '' ) === 'ok' ) {
            qa_ok( $qa, 'Openpay CO health_check()', $health['message'] ?? '' );
        } else {
            qa_warn( $qa, 'Openpay CO health_check() — respuesta inesperada', json_encode( $health ) );
        }
    } catch ( \Throwable $e ) {
        qa_warn( $qa, 'Openpay CO health_check() excepción', $e->getMessage() );
    }
} else {
    qa_warn( $qa, 'Openpay CO ping omitido — sin credenciales configuradas' );
}

// ─── T-07: Openpay CO — PSE banks list ────────────────────────────────────
echo "\n── T-07: Openpay CO PSE bank list\n";
if ( ! empty( $op_merchant ) && ! empty( $op_privkey ) && class_exists( 'LTMS_Api_Openpay' ) ) {
    try {
        $openpay_co = new LTMS_Api_Openpay( $op_merchant, $op_privkey, $op_pubkey, 'CO', true );
        $banks = $openpay_co->get_pse_banks();
        if ( ! empty( $banks ) && is_array( $banks ) ) {
            qa_ok( $qa, 'PSE banks list', count( $banks ) . ' bancos' );
        } else {
            qa_warn( $qa, 'PSE banks list vacía o inesperada', json_encode( $banks ) );
        }
    } catch ( \Throwable $e ) {
        qa_warn( $qa, 'PSE banks list excepción', $e->getMessage() );
    }
} else {
    qa_warn( $qa, 'PSE banks omitido — sin credenciales Openpay CO' );
}

// ─── T-08: Stripe — clave publicable presente ─────────────────────────────
echo "\n── T-08: Stripe config\n";
$stripe_gw = $instances['LTMS_Gateway_Stripe'] ?? null;
if ( $stripe_gw ) {
    $pk = $stripe_gw->get_option( 'publishable_key', '' );
    $sk = $stripe_gw->get_option( 'secret_key', '' );
    if ( ! empty( $pk ) ) {
        qa_ok( $qa, 'Stripe publishable_key (sandbox) configurado' );
    } else {
        qa_warn( $qa, 'Stripe publishable_key vacío — configurar en WC > Pagos > Stripe' );
    }
    if ( ! empty( $sk ) ) {
        qa_ok( $qa, 'Stripe secret_key (sandbox) configurado' );
    } else {
        qa_warn( $qa, 'Stripe secret_key vacío' );
    }
    $mode = $stripe_gw->get_option( 'testmode', 'yes' ) === 'yes' ? 'sandbox' : 'live';
    qa_ok( $qa, "Stripe modo: $mode" );
} else {
    qa_fail( $qa, 'Stripe gateway no instanciado' );
}

// ─── T-09: Openpay MX — gateway configurado ───────────────────────────────
echo "\n── T-09: Openpay MX config\n";
$mx_gw = $instances['LTMS_Api_Gateway_Openpay_MX'] ?? null;
if ( $mx_gw ) {
    $mx_merchant = $mx_gw->get_option( 'merchant_id', '' );
    $mx_privkey  = $mx_gw->get_option( 'private_key', '' );
    if ( ! empty( $mx_merchant ) ) {
        qa_ok( $qa, 'Openpay MX merchant_id configurado', $mx_merchant );
    } else {
        qa_warn( $qa, 'Openpay MX merchant_id vacío — configurar en WC > Pagos > Openpay MX' );
    }
    if ( ! empty( $mx_privkey ) ) {
        qa_ok( $qa, 'Openpay MX private_key configurado' );
    } else {
        qa_warn( $qa, 'Openpay MX private_key vacío' );
    }
    $mx_method = $mx_gw->get_option( 'payment_method', 'card' );
    qa_ok( $qa, "Openpay MX método seleccionado: $mx_method" );
} else {
    qa_fail( $qa, 'Openpay MX gateway no instanciado' );
}

// ─── T-10: Webhook handlers registrados ───────────────────────────────────
echo "\n── T-10: Webhook handlers\n";
$wh_classes = [
    'LTMS_Openpay_Webhook_Handler' => 'Openpay',
    'LTMS_Stripe_Webhook_Handler'  => 'Stripe',
    'LTMS_Addi_Webhook_Handler'    => 'Addi',
];
foreach ( $wh_classes as $class => $label ) {
    if ( class_exists( $class ) ) {
        qa_ok( $qa, "$label Webhook Handler existe" );
    } else {
        qa_fail( $qa, "$label Webhook Handler ($class) no existe" );
    }
}

// ─── T-11: REST endpoints de webhooks ─────────────────────────────────────
echo "\n── T-11: REST endpoints webhook\n";
$rest_routes = rest_get_server()->get_routes();
$expected_routes = [
    '/ltms/v1/webhooks/openpay' => 'Openpay webhook',
    '/ltms/v1/webhooks/stripe'  => 'Stripe webhook',
    '/ltms/v1/webhooks/addi'    => 'Addi webhook',
];
foreach ( $expected_routes as $route => $label ) {
    if ( isset( $rest_routes[$route] ) ) {
        qa_ok( $qa, "$label route existe", $route );
    } else {
        qa_warn( $qa, "$label route no encontrado", $route );
    }
}

// ─── RESUMEN ───────────────────────────────────────────────────────────────
$total = $qa['pass'] + $qa['fail'] + $qa['warn'];
echo "\n══════════════════════════════════════════════════\n";
echo "  RESUMEN QA — Pasarelas de Pago\n";
echo "══════════════════════════════════════════════════\n";
echo "  ✅ PASS : {$qa['pass']}\n";
echo "  ❌ FAIL : {$qa['fail']}\n";
echo "  ⚠️  WARN : {$qa['warn']}\n";
echo "  TOTAL  : $total pruebas\n\n";
if ( $qa['fail'] === 0 ) {
    echo "  🎉 Sin fallos críticos.\n\n";
} else {
    echo "  🚨 Hay {$qa['fail']} fallo(s) que requieren atención.\n\n";
}
