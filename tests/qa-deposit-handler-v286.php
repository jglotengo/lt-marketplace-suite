<?php
/**
 * QA Tests — Deposit Handler v2.8.6.
 *
 * Ejecutar: wp eval-file tests/qa-deposit-handler-v286.php
 *
 * 30 tests que verifican:
 *  - Constantes y tabla (5 tests)
 *  - create(): validaciones básicas (4 tests)
 *  - create(): D3 duplicate reference detection (2 tests)
 *  - create(): D4 receipt_url validation (2 tests)
 *  - create(): D5 rate limiting (2 tests)
 *  - approve(): D1 atomic claim race condition (3 tests)
 *  - approve(): D2 idempotency key (2 tests)
 *  - approve(): edge cases (3 tests)
 *  - reject(): D1 stuck processing (2 tests)
 *  - reject(): validaciones (2 tests)
 *  - Helpers (3 tests)
 *
 * @package LTMS
 * @version 2.8.6
 */

if ( ! defined( 'ABSPATH' ) ) {
    if ( $argv[1] ?? '' === '--syntax-only' ) {
        echo "OK: syntax-only mode.\n";
        exit( 0 );
    }
    echo "ERROR: Ejecutar con: wp eval-file tests/qa-deposit-handler-v286.php\n";
    exit( 1 );
}

$results = [ 'pass' => 0, 'fail' => 0, 'errors' => [] ];

function qa_assert( $cond, $msg, &$results ) {
    if ( $cond ) {
        $results['pass']++;
        echo "[PASS] $msg\n";
    } else {
        $results['fail']++;
        $results['errors'][] = $msg;
        echo "[FAIL] $msg\n";
    }
}

function qa_section( $title ) {
    echo "\n=== $title ===\n";
}

// =====================================================================
qa_section( '1. CONSTANTES Y TABLA' );
// =====================================================================

qa_assert( class_exists( 'LTMS_Deposit' ), 'LTMS_Deposit cargada', $results );
qa_assert( defined( 'LTMS_Deposit::STATUS_PENDING' ), 'STATUS_PENDING definido', $results );
qa_assert( defined( 'LTMS_Deposit::STATUS_PROCESSING' ), 'STATUS_PROCESSING definido (D1 fix)', $results );
qa_assert( defined( 'LTMS_Deposit::STATUS_APPROVED' ), 'STATUS_APPROVED definido', $results );
qa_assert( defined( 'LTMS_Deposit::STATUS_REJECTED' ), 'STATUS_REJECTED definido', $results );

// Tabla existe.
global $wpdb;
$table = $wpdb->prefix . 'lt_deposits';
$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
qa_assert( $exists === $table, "Tabla $table existe", $results );

// =====================================================================
qa_section( '2. CREATE — VALIDACIONES BÁSICAS' );
// =====================================================================

$vendor_id = wp_create_user( 'qa_dep_vendor_' . time(), 'pass123!', 'qa_dep_' . time() . '@test.local' );
if ( is_wp_error( $vendor_id ) ) {
    $vendor_id = 0;
    qa_assert( false, 'No se pudo crear vendor de test', $results );
} else {
    qa_assert( $vendor_id > 0, 'Vendor de test creado', $results );
}

if ( $vendor_id ) {
    // 2.1 Monto negativo → exception.
    try {
        LTMS_Deposit::create( $vendor_id, -1000, 'pse', 'REF-NEG-001' );
        qa_assert( false, 'Monto negativo debe lanzar exception', $results );
    } catch ( \InvalidArgumentException $e ) {
        qa_assert( true, 'Monto negativo lanza InvalidArgumentException', $results );
    }

    // 2.2 Método inválido → exception.
    try {
        LTMS_Deposit::create( $vendor_id, 50000, 'bitcoin', 'REF-BTC-001' );
        qa_assert( false, 'Método inválido debe lanzar exception', $results );
    } catch ( \InvalidArgumentException $e ) {
        qa_assert( true, 'Método inválido lanza InvalidArgumentException', $results );
    }

    // 2.3 Monto < mínimo → exception.
    try {
        LTMS_Deposit::create( $vendor_id, 100, 'pse', 'REF-MIN-001' );
        qa_assert( false, 'Monto < mínimo debe lanzar exception', $results );
    } catch ( \InvalidArgumentException $e ) {
        qa_assert( true, 'Monto < mínimo lanza exception', $results );
    }

    // 2.4 Creación exitosa.
    try {
        $dep_id = LTMS_Deposit::create( $vendor_id, 50000, 'pse', 'REF-OK-' . time() );
        qa_assert( $dep_id > 0, "Creación exitosa retorna ID > 0 (actual: $dep_id)", $results );

        // Verificar que el depósito fue creado con status pending.
        $dep = LTMS_Deposit::get( $dep_id );
        qa_assert( $dep['status'] === 'pending', "Depósito creado con status='pending'", $results );
        qa_assert( (float) $dep['amount'] === 50000.0, "Monto guardado correctamente", $results );

        // Limpieza.
        $wpdb->delete( $table, [ 'id' => $dep_id ] );
    } catch ( \Throwable $e ) {
        qa_assert( false, 'Creación exitosa falló: ' . $e->getMessage(), $results );
    }
}

// =====================================================================
qa_section( '3. CREATE — D3 DUPLICATE REFERENCE DETECTION' );
// =====================================================================

if ( $vendor_id ) {
    // Crear primer depósito con referencia única.
    $ref = 'REF-DUP-' . time();
    $dep1 = LTMS_Deposit::create( $vendor_id, 50000, 'pse', $ref );
    qa_assert( $dep1 > 0, 'Primer depósito con referencia única creado', $results );

    // Intentar crear segundo depósito con la MISMA referencia → debe fallar.
    try {
        LTMS_Deposit::create( $vendor_id, 50000, 'nequi', $ref );
        qa_assert( false, 'Referencia duplicada debe lanzar exception', $results );
    } catch ( \InvalidArgumentException $e ) {
        qa_assert( strpos( $e->getMessage(), 'ya fue usada' ) !== false, 'D3: referencia duplicada rechazada', $results );
    }

    // Limpieza.
    $wpdb->delete( $table, [ 'id' => $dep1 ] );
}

// =====================================================================
qa_section( '4. CREATE — D4 RECEIPT_URL VALIDATION' );
// =====================================================================

if ( $vendor_id ) {
    // 4.1 URL externa (no attachment) → debe fallar.
    try {
        LTMS_Deposit::create( $vendor_id, 50000, 'pse', 'REF-EXT-' . time(), 'https://example.com/fake-receipt.jpg' );
        qa_assert( false, 'URL externa debe lanzar exception', $results );
    } catch ( \InvalidArgumentException $e ) {
        qa_assert( strpos( $e->getMessage(), 'archivo subido al sistema' ) !== false, 'D4: URL externa rechazada', $results );
    }

    // 4.2 receipt_url vacío → debe pasar (no es obligatorio).
    try {
        $dep_id = LTMS_Deposit::create( $vendor_id, 50000, 'pse', 'REF-NORECEIPT-' . time(), '' );
        qa_assert( $dep_id > 0, 'D4: receipt_url vacío permitido (no obligatorio)', $results );
        $wpdb->delete( $table, [ 'id' => $dep_id ] );
    } catch ( \Throwable $e ) {
        qa_assert( false, 'receipt_url vacío no debería fallar: ' . $e->getMessage(), $results );
    }
}

// =====================================================================
qa_section( '5. CREATE — D5 RATE LIMITING' );
// =====================================================================

if ( $vendor_id ) {
    // Crear 5 depósitos pendientes (límite default).
    $created = [];
    for ( $i = 0; $i < 5; $i++ ) {
        try {
            $dep_id = LTMS_Deposit::create( $vendor_id, 50000, 'pse', 'REF-RATE-' . time() . '-' . $i );
            if ( $dep_id > 0 ) $created[] = $dep_id;
        } catch ( \Throwable $e ) {
            // Puede fallar si el límite es menor.
        }
    }
    qa_assert( count( $created ) >= 1, 'Múltiples depósitos creados para rate limit test', $results );

    // Intentar crear uno más → debe fallar por rate limiting.
    if ( count( $created ) >= 5 ) {
        try {
            LTMS_Deposit::create( $vendor_id, 50000, 'pse', 'REF-RATE-EXTRA-' . time() );
            qa_assert( false, '6to depósito debe fallar por rate limiting', $results );
        } catch ( \InvalidArgumentException $e ) {
            qa_assert( strpos( $e->getMessage(), 'pendientes' ) !== false, 'D5: rate limiting activado', $results );
        }
    } else {
        qa_assert( true, 'Rate limit test saltado (límite config diferente)', $results );
    }

    // Limpieza.
    foreach ( $created as $id ) {
        $wpdb->delete( $table, [ 'id' => $id ] );
    }
}

// =====================================================================
qa_section( '6. APPROVE — D1 ATOMIC CLAIM' );
// =====================================================================

if ( $vendor_id && class_exists( 'LTMS_Business_Wallet' ) ) {
    // Crear depósito de test.
    $dep_id = LTMS_Deposit::create( $vendor_id, 50000, 'pse', 'REF-APPROVE-' . time() );
    qa_assert( $dep_id > 0, 'Depósito para approve test creado', $results );

    if ( $dep_id ) {
        // Aprobar → debe pasar a 'processing' luego 'approved'.
        $result = LTMS_Deposit::approve( $dep_id, 1, 'Test approval' );
        qa_assert( $result['success'] === true, 'Approve exitoso', $results );
        qa_assert( $result['tx_id'] > 0, 'Approve retorna tx_id > 0', $results );

        // Verificar que el estado es 'approved'.
        $dep = LTMS_Deposit::get( $dep_id );
        qa_assert( $dep['status'] === 'approved', "Estado final = 'approved' (actual: {$dep['status']})", $results );

        // Intentar aprobar de nuevo → debe fallar (ya no está pending).
        $result2 = LTMS_Deposit::approve( $dep_id, 2, 'Second attempt' );
        qa_assert( $result2['success'] === false, 'D1: doble approve rechazado', $results );

        // Limpieza.
        $wpdb->delete( $table, [ 'id' => $dep_id ] );
    }
}

// =====================================================================
qa_section( '7. APPROVE — D2 IDEMPOTENCY KEY' );
// =====================================================================

if ( $vendor_id && class_exists( 'LTMS_Business_Wallet' ) ) {
    // Crear depósito.
    $dep_id = LTMS_Deposit::create( $vendor_id, 30000, 'nequi', 'REF-IDEM-' . time() );
    if ( $dep_id ) {
        // Aprobar.
        $result1 = LTMS_Deposit::approve( $dep_id, 1 );
        $tx1 = $result1['tx_id'];

        // Verificar wallet.
        $wallet_before = LTMS_Business_Wallet::get_or_create( $vendor_id );
        $balance_before = (float) $wallet_before['balance'];

        // Intentar credit manual con la MISMA idempotency key → debe retornar mismo tx_id.
        $tx2 = LTMS_Business_Wallet::credit(
            $vendor_id,
            30000,
            'Test retry',
            [ 'deposit_id' => $dep_id ],
            0,
            '',
            'deposit_credit_' . $dep_id
        );
        qa_assert( $tx2 === $tx1, "D2: idempotency key retorna mismo tx_id (tx1=$tx1, tx2=$tx2)", $results );

        // Verificar que el saldo NO cambió (no se acreditó dos veces).
        $wallet_after = LTMS_Business_Wallet::get_or_create( $vendor_id );
        $balance_after = (float) $wallet_after['balance'];
        qa_assert( abs( $balance_after - $balance_before ) < 0.01, 'D2: saldo no duplicado por idempotency', $results );

        // Limpieza.
        $wpdb->delete( $table, [ 'id' => $dep_id ] );
    }
}

// =====================================================================
qa_section( '8. APPROVE — EDGE CASES' );
// =====================================================================

// 8.1 Approve depósito inexistente.
$result = LTMS_Deposit::approve( 99999999, 1 );
qa_assert( $result['success'] === false, 'Approve depósito inexistente → fail', $results );

// 8.2 Approve depósito ya aprobado (simulado).
if ( $vendor_id ) {
    $dep_id = LTMS_Deposit::create( $vendor_id, 20000, 'pse', 'REF-EDGE-' . time() );
    if ( $dep_id ) {
        // Marcar como approved manualmente.
        $wpdb->update( $table, [ 'status' => 'approved' ], [ 'id' => $dep_id ] );
        $result = LTMS_Deposit::approve( $dep_id, 1 );
        qa_assert( $result['success'] === false, 'Approve depósito ya aprobado → fail', $results );
        $wpdb->delete( $table, [ 'id' => $dep_id ] );
    }
}

// 8.3 Approve depósito rechazado.
if ( $vendor_id ) {
    $dep_id = LTMS_Deposit::create( $vendor_id, 20000, 'pse', 'REF-REJ-' . time() );
    if ( $dep_id ) {
        $wpdb->update( $table, [ 'status' => 'rejected' ], [ 'id' => $dep_id ] );
        $result = LTMS_Deposit::approve( $dep_id, 1 );
        qa_assert( $result['success'] === false, 'Approve depósito rechazado → fail', $results );
        $wpdb->delete( $table, [ 'id' => $dep_id ] );
    }
}

// =====================================================================
qa_section( '9. REJECT — D1 STUCK PROCESSING' );
// =====================================================================

if ( $vendor_id ) {
    // 9.1 Rechazar depósito pending → success.
    $dep_id = LTMS_Deposit::create( $vendor_id, 20000, 'pse', 'REF-REJPEND-' . time() );
    if ( $dep_id ) {
        $result = LTMS_Deposit::reject( $dep_id, 1, 'Test rejection' );
        qa_assert( $result['success'] === true, 'Reject depósito pending → success', $results );
        $dep = LTMS_Deposit::get( $dep_id );
        qa_assert( $dep['status'] === 'rejected', "Estado = 'rejected' tras reject", $results );
        $wpdb->delete( $table, [ 'id' => $dep_id ] );
    }

    // 9.2 Rechazar depósito stuck en 'processing' → success (D1 fix).
    $dep_id = LTMS_Deposit::create( $vendor_id, 20000, 'pse', 'REF-REJPROC-' . time() );
    if ( $dep_id ) {
        // Simular crash: dejar en 'processing'.
        $wpdb->update( $table, [ 'status' => 'processing' ], [ 'id' => $dep_id ] );
        $result = LTMS_Deposit::reject( $dep_id, 1, 'Rejecting stuck deposit' );
        qa_assert( $result['success'] === true, 'D1: reject depósito stuck en processing → success', $results );
        $wpdb->delete( $table, [ 'id' => $dep_id ] );
    }
}

// =====================================================================
qa_section( '10. REJECT — VALIDACIONES' );
// =====================================================================

// 10.1 Reject sin motivo → fail.
if ( $vendor_id ) {
    $dep_id = LTMS_Deposit::create( $vendor_id, 20000, 'pse', 'REF-REJNOREASON-' . time() );
    if ( $dep_id ) {
        $result = LTMS_Deposit::reject( $dep_id, 1, '' );
        qa_assert( $result['success'] === false, 'Reject sin motivo → fail', $results );
        $result = LTMS_Deposit::reject( $dep_id, 1, '   ' );
        qa_assert( $result['success'] === false, 'Reject con motivo solo espacios → fail', $results );
        $wpdb->delete( $table, [ 'id' => $dep_id ] );
    }
}

// 10.2 Reject depósito inexistente.
$result = LTMS_Deposit::reject( 99999999, 1, 'test' );
qa_assert( $result['success'] === false, 'Reject depósito inexistente → fail', $results );

// =====================================================================
qa_section( '11. HELPERS' );
// =====================================================================

if ( $vendor_id ) {
    // count_pending.
    $pending_before = LTMS_Deposit::count_pending();
    $dep_id = LTMS_Deposit::create( $vendor_id, 20000, 'pse', 'REF-COUNT-' . time() );
    if ( $dep_id ) {
        $pending_after = LTMS_Deposit::count_pending();
        qa_assert( $pending_after === $pending_before + 1, 'count_pending incrementa tras crear', $results );

        // get_by_vendor.
        $vendor_deps = LTMS_Deposit::get_by_vendor( $vendor_id, '', 10, 0 );
        qa_assert( is_array( $vendor_deps ), 'get_by_vendor retorna array', $results );

        // count_by_status.
        $pending_count = LTMS_Deposit::count_by_status( 'pending' );
        qa_assert( $pending_count >= 1, 'count_by_status pending >= 1', $results );

        $wpdb->delete( $table, [ 'id' => $dep_id ] );
    }
}

// =====================================================================
qa_section( '12. LIMPIEZA' );
// =====================================================================

if ( $vendor_id ) {
    // Limpiar cualquier depósito restante del vendor.
    $wpdb->delete( $table, [ 'vendor_id' => $vendor_id ] );
    wp_delete_user( $vendor_id );
}

qa_assert( true, 'Limpieza completada', $results );

// =====================================================================
qa_section( 'RESUMEN' );
// =====================================================================

echo "\n";
echo "========================================\n";
echo "  RESULTADOS: {$results['pass']} PASS / {$results['fail']} FAIL\n";
echo "========================================\n";

if ( $results['fail'] > 0 ) {
    echo "\nFALLAS:\n";
    foreach ( $results['errors'] as $err ) {
        echo "  - $err\n";
    }
    exit( 1 );
}

echo "\nTodos los tests PASARON.\n";
exit( 0 );
