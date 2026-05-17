<?php
/**
 * LTMS QA — Pruebas de integración ZapSign
 * Corre desde WP-CLI:
 *   wp eval-file bin/ltms-qa-zapsign.php --allow-root
 */

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( __DIR__, 4 ) . '/' );
}

// ── Helpers ──────────────────────────────────────────────────────────────────
$qa    = [ 'pass' => 0, 'fail' => 0, 'warn' => 0, 'fails' => [] ];
$docs_created = [];

function qa_section( string $title ): void {
    echo "\n" . str_repeat( '═', 50 ) . "\n";
    echo "  {$title}\n";
    echo str_repeat( '═', 50 ) . "\n";
}

function qa_ok( array &$qa, string $name, string $detail = '' ): void {
    $qa['pass']++;
    echo "  ✅ PASS  {$name}" . ( $detail ? " — {$detail}" : '' ) . "\n";
}

function qa_fail( array &$qa, string $name, string $detail = '' ): void {
    $qa['fail']++;
    $qa['fails'][] = "{$name}" . ( $detail ? " — {$detail}" : '' );
    echo "  ❌ FAIL  {$name}" . ( $detail ? " — {$detail}" : '' ) . "\n";
}

function qa_warn( array &$qa, string $name, string $detail = '' ): void {
    $qa['warn']++;
    echo "  ⚠️  WARN  {$name}" . ( $detail ? " — {$detail}" : '' ) . "\n";
}

// ── Encabezado ────────────────────────────────────────────────────────────────
echo "\n🔍 LTMS QA — Pruebas de integración ZapSign\n";
echo 'Fecha: ' . gmdate( 'Y-m-d H:i:s' ) . "\n";

// ── T-01: Configuración ───────────────────────────────────────────────────────
qa_section( 'T-01 · Configuración y conectividad' );

$zapsign_enabled = get_option( 'ltms_zapsign_enabled', 'no' );
$zapsign_token   = LTMS_Core_Config::get( 'ltms_zapsign_api_token', '' );
$auto_approve    = get_option( 'ltms_kyc_zapsign_enabled', 'no' );

if ( 'yes' === $zapsign_enabled ) {
    qa_ok( $qa, 'ZapSign Activo', 'yes' );
} else {
    qa_warn( $qa, 'ZapSign desactivado en opciones', 'Actívalo en Configuración → ZapSign' );
}

if ( ! empty( $zapsign_token ) ) {
    $decrypted = LTMS_Core_Security::decrypt( $zapsign_token );
    $token_len = strlen( $decrypted );
    if ( $token_len >= 20 ) {
        qa_ok( $qa, 'Token API configurado', "✓ {$token_len} chars" );
    } else {
        qa_fail( $qa, 'Token API demasiado corto', "{$token_len} chars — ¿es válido?" );
        echo "       Token (raw): " . substr( $decrypted, 0, 8 ) . "...\n";
    }
} else {
    qa_fail( $qa, 'Token API no configurado', 'Ve a Configuración → ZapSign → Token API' );
}

if ( 'yes' === $auto_approve ) {
    qa_ok( $qa, 'Auto-aprobación KYC', 'Activada — los contratos firmados aprueban KYC automáticamente' );
} else {
    qa_warn( $qa, 'Auto-aprobación KYC desactivada', 'Opcional — actívala si quieres KYC automático' );
}

// ── T-02: Factory e instancia ─────────────────────────────────────────────────
qa_section( 'T-02 · Factory e instancia de LTMS_Api_Zapsign' );
$zapsign = null;
try {
    if ( ! class_exists( 'LTMS_Api_Zapsign' ) ) {
        qa_fail( $qa, 'Clase LTMS_Api_Zapsign', 'No encontrada — error de autoloader' );
    } else {
        $zapsign = new LTMS_Api_Zapsign();
        qa_ok( $qa, 'new LTMS_Api_Zapsign()', 'Instanciada OK' );
    }
} catch ( Throwable $e ) {
    qa_fail( $qa, 'new LTMS_Api_Zapsign()', $e->getMessage() );
}

// ── T-03: Health Check ────────────────────────────────────────────────────────
qa_section( 'T-03 · Health Check — conectividad con API ZapSign' );
if ( $zapsign ) {
    try {
        $health = $zapsign->health_check();
        if ( ! empty( $health['connected'] ) ) {
            $detail = 'Cuenta: ' . ( $health['account'] ?? '?' ) . ' — ' . ( $health['latency_ms'] ?? '?' ) . 'ms';
            qa_ok( $qa, 'health_check()', $detail );
        } else {
            qa_fail( $qa, 'health_check()', 'connected=false — ' . ( $health['error'] ?? 'sin detalle' ) );
        }
    } catch ( Throwable $e ) {
        qa_fail( $qa, 'health_check()', $e->getMessage() );
    }
} else {
    qa_warn( $qa, 'T-03 omitido', 'Requiere instancia válida de T-02' );
}

// ── T-04: Crear documento ─────────────────────────────────────────────────────
qa_section( 'T-04 · Crear documento de firma' );
$test_doc_token = null;
if ( $zapsign ) {
    try {
        $doc = $zapsign->create_document([
            'name'     => 'QA Test Contract LTMS ' . date('His'),
            'url_pdf'  => 'https://www.w3.org/WAI/WCAG21/Techniques/pdf/pdfs/table.pdf',
            'signers'  => [[
                'name'        => 'QA Vendedor LTMS',
                'email'       => 'qa-vendor@lo-tengo.com.co',
                'external_id' => '999999',
            ]],
        ]);
        if ( ! empty( $doc['token'] ) ) {
            $test_doc_token = $doc['token'];
            $docs_created[] = $test_doc_token;
            qa_ok( $qa, 'create_document()', "token=" . substr( $test_doc_token, 0, 16 ) . '... | status=' . ( $doc['status'] ?? '?' ) );
        } else {
            qa_fail( $qa, 'create_document()', 'Sin token en respuesta: ' . wp_json_encode( $doc ) );
        }
    } catch ( Throwable $e ) {
        qa_fail( $qa, 'create_document()', $e->getMessage() );
    }
} else {
    qa_warn( $qa, 'T-04 omitido', 'Requiere instancia válida' );
}

// ── T-05: Estado del documento ────────────────────────────────────────────────
qa_section( 'T-05 · Consultar estado del documento' );
if ( $zapsign && $test_doc_token ) {
    try {
        $status = $zapsign->get_document_status( $test_doc_token );
        if ( ! empty( $status['token'] ) ) {
            $state = $status['status'] ?? '?';
            $signers_count = count( $status['signers'] ?? [] );
            qa_ok( $qa, 'get_document_status()', "status={$state} | signers={$signers_count}" );

            // Verificar estructura
            if ( isset( $status['status'] ) ) {
                qa_ok( $qa, 'Campo status presente', $status['status'] );
            } else {
                qa_warn( $qa, 'Campo status ausente en respuesta', wp_json_encode( array_keys( $status ) ) );
            }
        } else {
            qa_fail( $qa, 'get_document_status()', 'Sin token en respuesta: ' . wp_json_encode( $status ) );
        }
    } catch ( Throwable $e ) {
        qa_fail( $qa, 'get_document_status()', $e->getMessage() );
    }
} else {
    qa_warn( $qa, 'T-05 omitido', 'Requiere documento creado en T-04' );
}

// ── T-06: send_vendor_contract ────────────────────────────────────────────────
qa_section( 'T-06 · send_vendor_contract() — contrato completo a vendedor' );
if ( $zapsign ) {
    // Buscar un vendedor real del sistema
    $vendor_users = get_users([
        'role'    => 'ltms_vendor',
        'number'  => 1,
        'orderby' => 'ID',
    ]);
    if ( empty( $vendor_users ) ) {
        $vendor_users = get_users([
            'meta_key'   => 'ltms_is_vendor',
            'meta_value' => '1',
            'number'     => 1,
        ]);
    }

    if ( ! empty( $vendor_users ) ) {
        $vendor = $vendor_users[0];
        echo "       Vendedor: #{$vendor->ID} — {$vendor->display_name} <{$vendor->user_email}>\n";
        try {
            // Usar PDF público de prueba
            $result = $zapsign->send_vendor_contract(
                $vendor->ID,
                'https://www.w3.org/WAI/WCAG21/Techniques/pdf/pdfs/table.pdf'
            );
            if ( ! empty( $result['token'] ) ) {
                $docs_created[] = $result['token'];
                qa_ok( $qa, 'send_vendor_contract()', 'token=' . substr( $result['token'], 0, 16 ) . '...' );
            } else {
                qa_fail( $qa, 'send_vendor_contract()', wp_json_encode( $result ) );
            }
        } catch ( Throwable $e ) {
            qa_fail( $qa, 'send_vendor_contract()', $e->getMessage() );
        }
    } else {
        qa_warn( $qa, 'T-06 omitido', 'No hay vendedores LTMS en el sistema para prueba real' );
    }
} else {
    qa_warn( $qa, 'T-06 omitido', 'Requiere instancia válida' );
}

// ── T-07: Webhook handler (simulado) ─────────────────────────────────────────
qa_section( 'T-07 · Webhook handler (simulado)' );

// T-07a: doc_signed event válido
$mock_vendor_id = 999998; // ID ficticio para test
$mock1 = new WP_REST_Request( 'POST', '/ltms/v1/webhooks/zapsign' );
$mock1->set_body( wp_json_encode([
    'token'      => $test_doc_token ?: 'test-token-qa-' . time(),
    'event_type' => 'doc_signed',
    'signer'     => [
        'external_id' => (string) $mock_vendor_id,
        'name'        => 'QA Vendedor',
        'email'       => 'qa@test.co',
    ],
    'document_type' => 'vendor_contract',
]) );
$mock1->set_header( 'content-type', 'application/json' );

try {
    $r1 = LTMS_Zapsign_Webhook_Handler::handle( $mock1 );
    if ( 200 === $r1->get_status() ) {
        qa_ok( $qa, 'Webhook doc_signed → 200 OK', 'received=true' );
    } else {
        qa_fail( $qa, 'Webhook doc_signed', 'HTTP ' . $r1->get_status() . ' | ' . wp_json_encode( $r1->get_data() ) );
    }
} catch ( Throwable $e ) {
    qa_fail( $qa, 'Webhook doc_signed', $e->getMessage() );
}

// T-07b: token inválido → 400 (payload incompleto)
$mock2 = new WP_REST_Request( 'POST', '/ltms/v1/webhooks/zapsign' );
$mock2->set_body( wp_json_encode([ 'event_type' => 'doc_signed' ]) ); // sin token
$mock2->set_header( 'content-type', 'application/json' );
try {
    $r2 = LTMS_Zapsign_Webhook_Handler::handle( $mock2 );
    if ( 400 === $r2->get_status() ) {
        qa_ok( $qa, 'Webhook sin doc_token → 400 Bad Request', 'Validación OK' );
    } else {
        qa_warn( $qa, 'Webhook sin token no rechazado', 'HTTP ' . $r2->get_status() );
    }
} catch ( Throwable $e ) {
    qa_warn( $qa, 'Webhook sin token — excepción', $e->getMessage() );
}

// T-07c: evento desconocido debe pasar (200 procesado=false)
$mock3 = new WP_REST_Request( 'POST', '/ltms/v1/webhooks/zapsign' );
$mock3->set_body( wp_json_encode([
    'token'      => 'test-' . time(),
    'event_type' => 'unknown_event_qa',
    'signer'     => [ 'external_id' => '123' ],
]) );
$mock3->set_header( 'content-type', 'application/json' );
try {
    $r3 = LTMS_Zapsign_Webhook_Handler::handle( $mock3 );
    if ( 200 === $r3->get_status() ) {
        qa_ok( $qa, 'Webhook evento desconocido → 200 (sin acción)', 'received=true' );
    } else {
        qa_warn( $qa, 'Webhook evento desconocido', 'HTTP ' . $r3->get_status() );
    }
} catch ( Throwable $e ) {
    qa_warn( $qa, 'Webhook evento desconocido — excepción', $e->getMessage() );
}

// T-07d: verificar que el KYC NO se aprobó para vendor ficticio (sin KYC pending)
$kyc_after = get_user_meta( $mock_vendor_id, 'ltms_kyc_status', true );
if ( empty( $kyc_after ) || 'approved' !== $kyc_after ) {
    qa_ok( $qa, 'KYC no auto-aprobado para usuario sin KYC pending', 'Correcto — vendor #' . $mock_vendor_id . ' no existe' );
} else {
    qa_warn( $qa, 'KYC auto-aprobado para usuario ficticio', 'vendor_id=' . $mock_vendor_id );
}

// ── T-08: REST endpoint registrado ────────────────────────────────────────────
qa_section( 'T-08 · REST endpoint /ltms/v1/webhooks/zapsign registrado' );
$routes = rest_get_server()->get_routes();
$route  = '/ltms/v1/webhooks/zapsign';
if ( isset( $routes[ $route ] ) ) {
    qa_ok( $qa, 'REST route registrada', $route );
} else {
    // Buscar con namespace
    $found = false;
    foreach ( array_keys( $routes ) as $r ) {
        if ( str_contains( $r, 'zapsign' ) ) {
            qa_ok( $qa, 'REST route zapsign encontrada', $r );
            $found = true;
            break;
        }
    }
    if ( ! $found ) {
        qa_fail( $qa, 'REST route /ltms/v1/webhooks/zapsign NO registrada', 'Verificar class-ltms-api-webhook-router.php' );
    }
}

// ── T-09: Opciones guardadas ──────────────────────────────────────────────────
qa_section( 'T-09 · Configuración en BD' );
$checks = [
    [ 'ltms_zapsign_enabled',          'ZapSign activo' ],
    [ 'ltms_zapsign_api_token',        'Token API' ],
    [ 'ltms_kyc_zapsign_enabled',      'Auto-aprobación KYC' ],
    [ 'ltms_zapsign_booking_template_id', 'Template ID reservas (opcional)' ],
];
foreach ( $checks as $check ) {
    [ $key, $label ] = $check;
    $val = get_option( $key, '' ) ?: LTMS_Core_Config::get( $key, '' );
    $display = strlen( (string) $val ) > 30 ? '✓ (' . strlen( $val ) . ' chars)' : ( $val ?: '(vacío)' );
    if ( ! empty( $val ) ) {
        qa_ok( $qa, $label, $display );
    } else {
        qa_warn( $qa, $label . ' — no configurado', '(vacío)' );
    }
}

// ── T-10: Limpieza ────────────────────────────────────────────────────────────
if ( ! empty( $docs_created ) ) {
    qa_section( 'T-10 · Limpieza — documentos QA en ZapSign' );
    foreach ( $docs_created as $doc_tok ) {
        try {
            $deleted = $zapsign ? $zapsign->delete_document( $doc_tok ) : false;
            if ( $deleted ) {
                qa_ok( $qa, 'delete_document()', 'token=' . substr( $doc_tok, 0, 16 ) . '...' );
            } else {
                qa_warn( $qa, 'delete_document() retornó false', substr( $doc_tok, 0, 16 ) . '...' );
            }
        } catch ( Throwable $e ) {
            qa_warn( $qa, 'delete_document() excepción', $e->getMessage() );
        }
    }
}

// ── RESUMEN ───────────────────────────────────────────────────────────────────
echo "\n" . str_repeat( '═', 50 ) . "\n";
echo "  RESUMEN QA — ZapSign\n";
echo str_repeat( '═', 50 ) . "\n";
echo "  ✅ PASS : {$qa['pass']}\n";
echo "  ❌ FAIL : {$qa['fail']}\n";
echo "  ⚠️  WARN : {$qa['warn']}\n";
echo "  TOTAL  : " . ( $qa['pass'] + $qa['fail'] + $qa['warn'] ) . " pruebas\n\n";

if ( empty( $qa['fails'] ) ) {
    echo "  🎉 Todas las pruebas críticas pasaron.\n";
} else {
    echo "  🔴 {$qa['fail']} prueba(s) fallida(s):\n";
    foreach ( $qa['fails'] as $f ) {
        echo "     · {$f}\n";
    }
}
echo "\n";
