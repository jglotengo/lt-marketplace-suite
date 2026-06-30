<?php
/**
 * LTMS QA — Pruebas de respaldo de contratos firmados a Backblaze B2 (BC-01)
 *
 * Valida el flujo nuevo introducido en class-ltms-zapsign-manager.php
 * (LTMS_ZapSign_Manager::backup_signed_contract) sin depender de un
 * contrato real firmado en ZapSign — usa un PDF sintético para probar
 * la mecánica de subida al bucket 'lotengo-contratos' específicamente
 * (las pruebas previas de bin/ltms-qa-backblaze.php solo cubrían el
 * bucket por defecto, no este).
 *
 * Ejecutar via WP-CLI:
 *   wp --path=/home/customer/www/lo-tengo.com.co/public_html \
 *      eval-file bin/ltms-qa-contract-backup.php --allow-root 2>/dev/null
 *
 * @package LTMS
 */

if ( function_exists( 'opcache_reset' ) ) { opcache_reset(); }
set_time_limit( 0 );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    // Ejecutado via wp eval-file — WordPress ya cargado
} else {
    $wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
    if ( file_exists( $wp_load ) ) {
        require_once $wp_load;
    } else {
        die( "Ejecutar via WP-CLI: wp eval-file bin/ltms-qa-contract-backup.php --allow-root\n" );
    }
}

$qa = [ 'pass' => 0, 'fail' => 0, 'warn' => 0, 'items' => [] ];

function qa_section( string $title ): void {
    echo "\n" . str_repeat( '═', 60 ) . "\n  {$title}\n" . str_repeat( '═', 60 ) . "\n";
    @ob_flush(); flush();
}
function qa_ok( array &$qa, string $label, string $detail = '' ): void {
    $qa['pass']++;
    $qa['items'][] = [ 'status' => 'PASS', 'label' => $label ];
    echo "  ✅ PASS  {$label}" . ( $detail ? " — {$detail}" : '' ) . "\n";
}
function qa_fail( array &$qa, string $label, string $detail = '' ): void {
    $qa['fail']++;
    $qa['items'][] = [ 'status' => 'FAIL', 'label' => $label, 'detail' => $detail ];
    echo "  ❌ FAIL  {$label}" . ( $detail ? " — {$detail}" : '' ) . "\n";
}
function qa_warn( array &$qa, string $label, string $detail = '' ): void {
    $qa['warn']++;
    $qa['items'][] = [ 'status' => 'WARN', 'label' => $label ];
    echo "  ⚠️  WARN  {$label}" . ( $detail ? " — {$detail}" : '' ) . "\n";
}

echo "\n🔍 LTMS QA — Respaldo de contratos firmados a B2 (BC-01)\n";
echo 'Fecha: ' . date( 'Y-m-d H:i:s' ) . "\n";

// ── T-01: Código desplegado correctamente ─────────────────────────────────────
qa_section( 'T-01 · Código desplegado (clases/métodos)' );

if ( class_exists( 'LTMS_ZapSign_Manager' ) ) {
    qa_ok( $qa, 'LTMS_ZapSign_Manager — clase presente' );
} else {
    qa_fail( $qa, 'LTMS_ZapSign_Manager — clase NO encontrada', '¿Se hizo git pull + reload del plugin?' );
}

if ( method_exists( 'LTMS_ZapSign_Manager', 'backup_signed_contract' ) ) {
    $ref = new ReflectionMethod( 'LTMS_ZapSign_Manager', 'backup_signed_contract' );
    if ( $ref->isStatic() && $ref->isPublic() ) {
        qa_ok( $qa, 'backup_signed_contract() — existe, es public static' );
    } else {
        qa_fail( $qa, 'backup_signed_contract() — existe pero firma incorrecta',
            'static=' . ( $ref->isStatic() ? 'sí' : 'no' ) . ' public=' . ( $ref->isPublic() ? 'sí' : 'no' ) );
    }
} else {
    qa_fail( $qa, 'backup_signed_contract() — método NO encontrado', 'Falta el deploy del commit c1d9e015' );
}

// Confirmar que el código viejo y buggy ya no existe
if ( ! method_exists( 'LTMS_ZapSign_Manager', 'upload_contract_to_b2' ) ) {
    qa_ok( $qa, 'upload_contract_to_b2() (código muerto/buggy) — eliminado correctamente' );
} else {
    qa_warn( $qa, 'upload_contract_to_b2() todavía existe', 'Debería haberse eliminado en commit c1d9e015' );
}

if ( method_exists( 'LTMS_Api_Zapsign', 'download_signed_document' ) ) {
    qa_ok( $qa, 'LTMS_Api_Zapsign::download_signed_document() — existe' );
} else {
    qa_fail( $qa, 'LTMS_Api_Zapsign::download_signed_document() — NO existe' );
}

// Confirmar que el webhook handler llama al nuevo método (chequeo de archivo en disco)
$webhook_file = WP_PLUGIN_DIR . '/lt-marketplace-suite/includes/api/webhooks/class-ltms-zapsign-webhook-handler.php';
if ( file_exists( $webhook_file ) ) {
    $webhook_src = file_get_contents( $webhook_file );
    if ( str_contains( $webhook_src, 'backup_signed_contract' ) ) {
        qa_ok( $qa, 'Webhook handler — llama a backup_signed_contract()' );
    } else {
        qa_fail( $qa, 'Webhook handler — NO llama a backup_signed_contract()', '¿Falta el deploy del commit a67cd3ad?' );
    }
} else {
    qa_fail( $qa, 'Webhook handler — archivo no encontrado en disco', $webhook_file );
}

// ── T-02: Configuración ───────────────────────────────────────────────────────
qa_section( 'T-02 · Configuración (gate de activación)' );

$b2_enabled = LTMS_Core_Config::get( 'ltms_backblaze_enabled', 'no' );
$bucket     = LTMS_Core_Config::get( 'ltms_backblaze_contratos_bucket', 'lotengo-contratos' ) ?: 'lotengo-contratos'; // BC-01-FIX

if ( 'yes' === $b2_enabled ) {
    qa_ok( $qa, "ltms_backblaze_enabled = 'yes'", 'El respaldo se ejecutará en producción' );
} else {
    qa_warn( $qa, "ltms_backblaze_enabled != 'yes' (valor actual: '{$b2_enabled}')",
        'backup_signed_contract() se saltará el respaldo intencionalmente. Activar en wp-admin → LT Marketplace → Configuración → Backblaze B2.' );
}

qa_ok( $qa, 'Bucket de contratos configurado', $bucket );

// ── T-03: Conectividad real con el bucket de CONTRATOS (no el bucket genérico) ──
qa_section( 'T-03 · Conectividad con bucket lotengo-contratos específicamente' );

$b2 = null;
try {
    $b2 = LTMS_Api_Factory::get( 'backblaze' );
    qa_ok( $qa, 'LTMS_Api_Factory::get(backblaze) — OK' );
} catch ( Throwable $e ) {
    qa_fail( $qa, 'Factory backblaze', $e->getMessage() );
}

if ( $b2 ) {
    try {
        $files = $b2->list_files( $bucket, '' );
        qa_ok( $qa, 'list_files() en bucket de contratos — OK', is_array( $files ) ? ( count( $files ) . ' objetos' ) : gettype( $files ) );
    } catch ( Throwable $e ) {
        qa_fail( $qa, 'list_files() en bucket de contratos', $e->getMessage() );
    }
}

// ── T-04: Simulación end-to-end con PDF sintético (sin tocar ZapSign real) ────
qa_section( 'T-04 · Simulación de subida (PDF sintético, vendor_id de prueba)' );

$fake_vendor_id = 999999; // ID inexistente, solo para namespacing de la key de prueba
$fake_doc_token = 'qa-synthetic-' . date( 'His' );
$fake_pdf       = "%PDF-1.4\n% LTMS QA synthetic PDF — " . date( 'Y-m-d H:i:s' ) . "\n%%EOF";
$test_key       = sprintf( 'contratos/%s/vendedor-%d-%s.pdf', date( 'Y/m' ), $fake_vendor_id, $fake_doc_token );

if ( ! $b2 ) {
    qa_warn( $qa, 'T-04 omitido — sin instancia de Backblaze' );
} else {
    try {
        $result = $b2->upload_file( $bucket, $test_key, $fake_pdf, 'application/pdf', [
            'vendor_id' => (string) $fake_vendor_id,
            'doc_token' => $fake_doc_token,
            'qa_test'   => '1',
        ] );
        if ( ! empty( $result['ETag'] ) || ! empty( $result['Key'] ) ) {
            qa_ok( $qa, 'upload_file() con metadata estilo contrato — OK', "Key={$test_key}" );

            // Verificar que aparece listado
            $listed = $b2->list_files( $bucket, 'contratos/' . date( 'Y/m' ) . '/vendedor-' . $fake_vendor_id );
            $found  = false;
            if ( is_array( $listed ) ) {
                foreach ( $listed as $f ) {
                    $fk = $f['Key'] ?? $f['key'] ?? '';
                    if ( str_contains( $fk, $fake_doc_token ) ) { $found = true; break; }
                }
            }
            if ( $found ) {
                qa_ok( $qa, 'Archivo de prueba visible en list_files()' );
            } else {
                qa_warn( $qa, 'Archivo no visible inmediatamente en listado', 'Puede ser latencia de consistencia eventual de B2' );
            }

            // Cleanup
            $deleted = $b2->delete_file( $bucket, $test_key );
            if ( $deleted ) {
                qa_ok( $qa, 'Cleanup — archivo de prueba eliminado' );
            } else {
                qa_warn( $qa, 'Cleanup — delete_file() retornó false', "Borrar manualmente: {$bucket}/{$test_key}" );
            }
        } else {
            qa_fail( $qa, 'upload_file() — sin ETag/Key en respuesta', wp_json_encode( $result ) );
        }
    } catch ( Throwable $e ) {
        qa_fail( $qa, 'Simulación de subida', $e->getMessage() );
    }
}

// ── T-05: Manejo correcto cuando Backblaze está desactivado ───────────────────
qa_section( 'T-05 · Comportamiento con Backblaze desactivado (no debe romper KYC)' );

if ( 'yes' !== $b2_enabled ) {
    try {
        // Llamar al método real con datos ficticios — debe retornar sin excepción
        LTMS_ZapSign_Manager::backup_signed_contract( $fake_vendor_id, 'qa-disabled-test' );
        qa_ok( $qa, 'backup_signed_contract() con B2 desactivado — no lanza excepción', 'Comportamiento esperado: skip silencioso + log' );
    } catch ( Throwable $e ) {
        qa_fail( $qa, 'backup_signed_contract() con B2 desactivado — LANZÓ EXCEPCIÓN',
            $e->getMessage() . ' — esto SÍ podría romper la aprobación de KYC real' );
    }
} else {
    qa_warn( $qa, 'T-05 omitido — Backblaze ya está activo, no se puede probar el camino "desactivado" limpiamente' );
}

// ── T-06: Pendiente — prueba real con contrato firmado de verdad ──────────────
qa_section( 'T-06 · Prueba real end-to-end (manual, pendiente)' );
echo "  📋 Para completar la validación end-to-end real:\n";
echo "     1. Activar 'Backblaze B2 Activo' en wp-admin si aún no está activo.\n";
echo "     2. Firmar en sandbox uno de los contratos 'EN CURSO' visibles en ZapSign\n";
echo "        (ej. 'Contrato Vendedor - Asistente Ventas AI - 2026', ID 4 o ID 5).\n";
echo "     3. Verificar en debug.log: buscar 'B2_CONTRACT_BACKUP_OK'.\n";
echo "     4. Verificar user_meta del vendedor: ltms_contract_b2_bucket / ltms_contract_b2_key.\n";
echo "     5. Confirmar en el panel de Backblaze que el PDF aparece en lotengo-contratos.\n";
qa_warn( $qa, 'T-06 requiere acción manual — no se puede automatizar sin firmar un contrato real' );

// ── RESUMEN ───────────────────────────────────────────────────────────────────
$sep = str_repeat( '═', 60 );
echo "\n{$sep}\n  RESUMEN QA — Respaldo de contratos a B2 (BC-01)\n{$sep}\n";
printf( "  ✅ PASS : %d\n  ❌ FAIL : %d\n  ⚠️  WARN : %d\n  TOTAL  : %d pruebas\n",
    $qa['pass'], $qa['fail'], $qa['warn'],
    $qa['pass'] + $qa['fail'] + $qa['warn'] );

if ( $qa['fail'] === 0 ) {
    echo "\n  🎉 Sin fallos críticos. Mecánica de respaldo validada — falta solo T-06 (manual).\n";
} else {
    echo "\n  🔴 {$qa['fail']} prueba(s) fallida(s):\n";
    foreach ( $qa['items'] as $item ) {
        if ( $item['status'] === 'FAIL' ) {
            echo "     · {$item['label']}" . ( isset( $item['detail'] ) ? " — {$item['detail']}" : '' ) . "\n";
        }
    }
}

echo "\n{$sep}\n\n";
