<?php
/**
 * QA Script — XCover Seguros LTMS
 * Uso: wp --path=... eval-file bin/ltms-qa-xcover.php --allow-root
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$qa = [ 'pass' => 0, 'fail' => 0, 'warn' => 0 ];
function qa_ok( &$qa, string $label, string $detail = '' ): void {
    $qa['pass']++; echo "  ✅ PASS  $label" . ( $detail ? " — $detail" : '' ) . "\n";
}
function qa_fail( &$qa, string $label, string $detail = '' ): void {
    $qa['fail']++; echo "  ❌ FAIL  $label" . ( $detail ? " — $detail" : '' ) . "\n";
}
function qa_warn( &$qa, string $label, string $detail = '' ): void {
    $qa['warn']++; echo "  ⚠️  WARN  $label" . ( $detail ? " — $detail" : '' ) . "\n";
}

echo "\n══════════════════════════════════════════════════\n";
echo "  QA — XCover Seguros LTMS\n";
echo "══════════════════════════════════════════════════\n\n";

// ─── T-01: Clases PHP existentes ─────────────────────────────────────────────
echo "── T-01: Clases PHP existentes\n";
$classes = [
    'LTMS_Api_XCover'               => 'API Client XCover',
    'LTMS_XCover_Checkout_Handler'  => 'Checkout Handler',
    'LTMS_XCover_Policy_Listener'   => 'Policy Listener',
];
foreach ( $classes as $class => $label ) {
    if ( class_exists( $class ) ) {
        qa_ok( $qa, "$label ($class)" );
    } else {
        qa_fail( $qa, "$label ($class) — clase no existe" );
    }
}

// ─── T-02: Instanciación sin errores ─────────────────────────────────────────
echo "\n── T-02: Instanciación API Client\n";
$xcover = null;
try {
    $xcover = new LTMS_Api_XCover();
    qa_ok( $qa, "new LTMS_Api_XCover() sin error" );
} catch ( \Throwable $e ) {
    qa_fail( $qa, "new LTMS_Api_XCover() excepción", $e->getMessage() );
}

// ─── T-03: Métodos del API Client ────────────────────────────────────────────
echo "\n── T-03: Métodos API Client\n";
$methods = [ 'get_quotes', 'create_policy', 'get_policy', 'cancel_policy', 'health_check', 'get_provider_slug' ];
foreach ( $methods as $m ) {
    if ( method_exists( 'LTMS_Api_XCover', $m ) ) {
        qa_ok( $qa, "LTMS_Api_XCover::$m() existe" );
    } else {
        qa_fail( $qa, "LTMS_Api_XCover::$m() — método no existe" );
    }
}

// ─── T-04: Credenciales configuradas ─────────────────────────────────────────
echo "\n── T-04: Credenciales en BD\n";
$s = get_option( 'ltms_settings', [] );
$api_key      = $s['ltms_xcover_api_key']      ?? get_option( 'ltms_xcover_api_key', '' );
$partner_code = $s['ltms_xcover_partner_code'] ?? get_option( 'ltms_xcover_partner_code', '' );
$enabled      = $s['ltms_xcover_enabled']      ?? get_option( 'ltms_xcover_enabled', '' );

if ( ! empty( $api_key ) ) {
    qa_ok( $qa, 'XCover API Key configurada' );
} else {
    qa_warn( $qa, 'XCover API Key vacía — configurar en LT Marketplace > Configuración > Seguros XCover' );
}
if ( ! empty( $partner_code ) ) {
    qa_ok( $qa, 'XCover Partner Code configurado' );
} else {
    qa_warn( $qa, 'XCover Partner Code vacío — configurar en LT Marketplace > Configuración > Seguros XCover' );
}
$activo = ( $enabled === 'yes' || $enabled === '1' || $enabled === true );
if ( $activo ) {
    qa_ok( $qa, 'XCover Activo: sí' );
} else {
    qa_warn( $qa, 'XCover inactivo — habilitar en Seguros XCover cuando tengas credenciales' );
}

// ─── T-05: Health check API (solo si hay credenciales) ───────────────────────
echo "\n── T-05: XCover API health check\n";
if ( $xcover && ! empty( $api_key ) && ! empty( $partner_code ) ) {
    try {
        $health = $xcover->health_check();
        if ( ( $health['status'] ?? '' ) === 'ok' ) {
            qa_ok( $qa, 'XCover health_check()', $health['message'] ?? '' );
        } else {
            qa_warn( $qa, 'XCover health_check() — respuesta inesperada', json_encode( $health ) );
        }
    } catch ( \Throwable $e ) {
        qa_warn( $qa, 'XCover health_check() excepción', $e->getMessage() );
    }
} else {
    qa_warn( $qa, 'XCover health_check omitido — sin credenciales configuradas' );
}

// ─── T-06: Checkout Handler hooks ────────────────────────────────────────────
echo "\n── T-06: Checkout Handler hooks\n";
if ( class_exists( 'LTMS_XCover_Checkout_Handler' ) ) {
    $hooks = [
        'woocommerce_cart_item_name'          => 'Nombre item en carrito',
        'woocommerce_checkout_order_processed' => 'Orden procesada (crear póliza)',
    ];
    foreach ( $hooks as $hook => $label ) {
        if ( has_filter( $hook ) || has_action( $hook ) ) {
            qa_ok( $qa, "$label ($hook) enganchado" );
        } else {
            qa_warn( $qa, "$label ($hook) — no enganchado aún (normal si XCover inactivo)" );
        }
    }
} else {
    qa_fail( $qa, 'Checkout Handler no existe — no se pueden verificar hooks' );
}

// ─── T-07: Policy Listener hooks ─────────────────────────────────────────────
echo "\n── T-07: Policy Listener hooks\n";
if ( class_exists( 'LTMS_XCover_Policy_Listener' ) ) {
    $hooks = [
        'woocommerce_order_status_cancelled' => 'Cancelación de orden (cancelar póliza)',
        'woocommerce_order_refunded'         => 'Reembolso (cancelar póliza)',
    ];
    foreach ( $hooks as $hook => $label ) {
        if ( has_action( $hook ) ) {
            qa_ok( $qa, "$label ($hook) enganchado" );
        } else {
            qa_warn( $qa, "$label ($hook) — no enganchado aún (normal si XCover inactivo)" );
        }
    }
} else {
    qa_fail( $qa, 'Policy Listener no existe — no se pueden verificar hooks' );
}

// ─── T-08: Archivos de vistas ────────────────────────────────────────────────
echo "\n── T-08: Archivos de vistas\n";
$views = [
    LTMS_INCLUDES_DIR . 'admin/views/html-admin-xcover-policies.php' => 'Vista admin pólizas',
    LTMS_INCLUDES_DIR . 'admin/views/settings/section-xcover.php'    => 'Vista settings XCover',
];
foreach ( $views as $path => $label ) {
    if ( file_exists( $path ) ) {
        qa_ok( $qa, "$label existe" );
    } else {
        qa_fail( $qa, "$label NO existe — $path" );
    }
}

// ─── T-09: Provider slug ─────────────────────────────────────────────────────
echo "\n── T-09: Provider slug\n";
if ( $xcover ) {
    $slug = $xcover->get_provider_slug();
    if ( ! empty( $slug ) ) {
        qa_ok( $qa, "get_provider_slug()", $slug );
    } else {
        qa_fail( $qa, "get_provider_slug() vacío" );
    }
}

// ─── RESUMEN ──────────────────────────────────────────────────────────────────
$total = $qa['pass'] + $qa['fail'] + $qa['warn'];
echo "\n══════════════════════════════════════════════════\n";
echo "  RESUMEN QA — XCover Seguros\n";
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
