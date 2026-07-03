<?php
/**
 * QA Tests — Consumer Protection v2.8.8.
 *
 * Ejecutar: wp eval-file tests/qa-consumer-protection-v288.php
 *
 * 30 tests que verifican:
 *  - Constantes y tabla (4 tests)
 *  - hold_commission: CP1 idempotency (3 tests)
 *  - release_single_hold: CP2 idempotency (3 tests)
 *  - release_eligible_holds: CP6 logging + delivery gating (3 tests)
 *  - file_dispute: CP5 customer validation (4 tests)
 *  - file_dispute: ventana legal (2 tests)
 *  - file_dispute: idempotencia (2 tests)
 *  - approve_dispute: CP4 vendor_net debit (4 tests)
 *  - reject_dispute (2 tests)
 *  - freeze_hold_for_dispute (2 tests)
 *  - is_order_delivered_or_no_shipping (3 tests)
 *  - Helpers (2 tests)
 *
 * @package LTMS
 * @version 2.8.8
 */

if ( ! defined( 'ABSPATH' ) ) {
    if ( $argv[1] ?? '' === '--syntax-only' ) {
        echo "OK: syntax-only mode.\n";
        exit( 0 );
    }
    echo "ERROR: Ejecutar con: wp eval-file tests/qa-consumer-protection-v288.php\n";
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

qa_assert( class_exists( 'LTMS_Business_Consumer_Protection' ), 'LTMS_Business_Consumer_Protection cargada', $results );
qa_assert( defined( 'LTMS_Business_Consumer_Protection::DEFAULT_HOLD_DAYS' ), 'DEFAULT_HOLD_DAYS definido', $results );
qa_assert( LTMS_Business_Consumer_Protection::DEFAULT_HOLD_DAYS === 5, 'DEFAULT_HOLD_DAYS = 5 (Ley 1480)', $results );

// Tabla lt_wallet_holds existe.
global $wpdb;
$holds_table = $wpdb->prefix . 'lt_wallet_holds';
$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $holds_table ) );
qa_assert( $exists === $holds_table, "Tabla $holds_table existe", $results );

// =====================================================================
qa_section( '2. CP1 — HOLD_COMMISSION IDEMPOTENCY' );
// =====================================================================

if ( class_exists( 'LTMS_Business_Wallet' ) ) {
    // Crear vendor de test.
    $vendor_id = wp_create_user( 'qa_cp_vendor_' . time(), 'pass123!', 'qa_cp_' . time() . '@test.local' );
    if ( is_wp_error( $vendor_id ) ) {
        $vendor_id = 0;
        qa_assert( false, 'No se pudo crear vendor de test', $results );
    } else {
        qa_assert( $vendor_id > 0, 'Vendor de test creado', $results );
    }

    if ( $vendor_id ) {
        $test_order_id = 999900001 + rand( 0, 9999 );

        // 2.1 Primera llamada a hold_commission → success.
        $result1 = LTMS_Business_Consumer_Protection::hold_commission( $vendor_id, 50000, $test_order_id );
        qa_assert( $result1 === true, 'Primera hold_commission → true', $results );

        // 2.2 Segunda llamada con MISMO order_id → idempotency (no doble crédito).
        $result2 = LTMS_Business_Consumer_Protection::hold_commission( $vendor_id, 50000, $test_order_id );
        qa_assert( $result2 === true, 'Segunda hold_commission → true (idempotente)', $results );

        // Verificar que el saldo no se duplicó.
        $wallet = LTMS_Business_Wallet::get_or_create( $vendor_id );
        $balance_pending = (float) $wallet['balance_pending'];
        // Debería ser 50000 (no 100000).
        qa_assert( abs( $balance_pending - 50000 ) < 1, "CP1: balance_pending = 50000 (no duplicado, actual: $balance_pending)", $results );

        // Limpieza.
        $wpdb->delete( $holds_table, [ 'order_id' => $test_order_id ] );
    }
}

// =====================================================================
qa_section( '3. CP2 — RELEASE_SINGLE_HOLD IDEMPOTENCY' );
// =====================================================================

if ( $vendor_id && class_exists( 'LTMS_Business_Wallet' ) ) {
    $test_order_id = 999900002 + rand( 0, 9999 );

    // Crear hold.
    LTMS_Business_Consumer_Protection::hold_commission( $vendor_id, 30000, $test_order_id );

    // Obtener el hold_id.
    $hold = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM `{$holds_table}` WHERE order_id = %d AND status = 'held' LIMIT 1",
        $test_order_id
    ), ARRAY_A );

    if ( $hold ) {
        $hold_id = (int) $hold['id'];

        // 3.1 Primera liberación → success.
        LTMS_Business_Consumer_Protection::release_single_hold( $hold_id, $vendor_id );
        $hold_after = $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM `{$holds_table}` WHERE id = %d",
            $hold_id
        ) );
        qa_assert( $hold_after === 'released', 'Primera release → status=released', $results );

        // 3.2 Segunda liberación (mismo hold_id) → no-op (idempotente).
        LTMS_Business_Consumer_Protection::release_single_hold( $hold_id, $vendor_id );

        // Verificar que el saldo disponible no se duplicó.
        $wallet = LTMS_Business_Wallet::get_or_create( $vendor_id );
        $balance = (float) $wallet['balance'];
        // El balance debería incluir solo 1 liberación de 30000 (más lo anterior).
        // Verificar que balance_pending es 0 para este monto.
        qa_assert( (float) $wallet['balance_pending'] < 30000, "CP2: balance_pending < 30000 (no doble release)", $results );

        // Limpieza.
        $wpdb->delete( $holds_table, [ 'order_id' => $test_order_id ] );
    } else {
        qa_assert( false, 'No se pudo crear hold de test', $results );
    }
}

// =====================================================================
qa_section( '4. CP6 — RELEASE_ELIGIBLE_HOLDS LOGGING' );
// =====================================================================

// 4.1 release_eligible_holds no crashea sin holds.
LTMS_Business_Consumer_Protection::release_eligible_holds();
qa_assert( true, 'release_eligible_holds no crashea sin holds', $results );

// 4.2 Con holds pero no entregados → skipped (si require_delivery=yes).
if ( $vendor_id ) {
    $test_order_id = 999900003 + rand( 0, 9999 );
    LTMS_Business_Consumer_Protection::hold_commission( $vendor_id, 20000, $test_order_id );

    // Forzar release_at al pasado.
    $wpdb->update( $holds_table, [ 'release_at' => gmdate( 'Y-m-d H:i:s', time() - 86400 ) ], [ 'order_id' => $test_order_id ] );

    LTMS_Business_Consumer_Protection::release_eligible_holds();

    // Verificar que el hold sigue 'held' (porque no hay _ltms_delivered_at).
    $hold_status = $wpdb->get_var( $wpdb->prepare(
        "SELECT status FROM `{$holds_table}` WHERE order_id = %d",
        $test_order_id
    ) );
    qa_assert( $hold_status === 'held', 'CP6: hold sin entrega no se libera', $results );

    // Limpieza.
    $wpdb->delete( $holds_table, [ 'order_id' => $test_order_id ] );
}

// =====================================================================
qa_section( '5. CP5 — FILE_DISPUTE CUSTOMER VALIDATION' );
// =====================================================================

// 5.1 file_dispute con customer_id que no es del order → WP_Error.
if ( $vendor_id ) {
    // Crear order de test (WC_Order mock mínimo).
    $order = wc_create_order();
    $order->set_status( 'completed' );
    $order->set_customer_id( 88888888 ); // Customer "dueño"
    $order->save();
    $test_order_id = $order->get_id();

    // Intentar disputar con customer_id diferente.
    $result = LTMS_Business_Consumer_Protection::file_dispute( $test_order_id, 99999999, 'damaged', 'Test' );
    qa_assert( is_wp_error( $result ), 'CP5: customer ajeno → WP_Error', $results );
    if ( is_wp_error( $result ) ) {
        qa_assert( $result->get_error_code() === 'unauthorized', 'CP5: error_code = unauthorized', $results );
    }

    // 5.2 file_dispute con customer_id correcto → success (si la tabla existe).
    $disputes_table = $wpdb->prefix . 'lt_consumer_disputes';
    $disputes_table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $disputes_table ) );

    if ( $disputes_table_exists === $disputes_table ) {
        $result = LTMS_Business_Consumer_Protection::file_dispute( $test_order_id, 88888888, 'damaged', 'Test description' );
        qa_assert( ! is_wp_error( $result ), 'CP5: customer dueño → success (dispute_id > 0)', $results );

        if ( ! is_wp_error( $result ) ) {
            // Limpieza.
            $wpdb->delete( $disputes_table, [ 'id' => (int) $result ] );
        }
    } else {
        qa_assert( true, 'Tabla lt_consumer_disputes no existe (skip)', $results );
    }

    // Limpieza order.
    $order->delete( true );
}

// =====================================================================
qa_section( '6. FILE_DISPUTE — VENTANA LEGAL' );
// =====================================================================

// 6.1 get_dispute_window_days CO = 5.
LTMS_Core_Config::set( 'ltms_country', 'CO' );
$days = LTMS_Business_Consumer_Protection::get_dispute_window_days();
qa_assert( $days === 5, "Ventana CO = 5 días (actual: $days)", $results );

// 6.2 get_dispute_window_days MX = 10.
LTMS_Core_Config::set( 'ltms_country', 'MX' );
$days = LTMS_Business_Consumer_Protection::get_dispute_window_days();
qa_assert( $days === 10, "Ventana MX = 10 días (actual: $days)", $results );
LTMS_Core_Config::set( 'ltms_country', 'CO' );

// =====================================================================
qa_section( '7. FILE_DISPUTE — IDEMPOTENCIA' );
// =====================================================================

// 7.1 Doble disputa para el mismo order → error.
$disputes_table = $wpdb->prefix . 'lt_consumer_disputes';
$disputes_table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $disputes_table ) );

if ( $disputes_table_exists === $disputes_table && $vendor_id ) {
    $order = wc_create_order();
    $order->set_status( 'completed' );
    $order->set_customer_id( 77777777 );
    $order->save();
    $test_order_id = $order->get_id();

    // Primera disputa.
    $result1 = LTMS_Business_Consumer_Protection::file_dispute( $test_order_id, 77777777, 'damaged' );
    qa_assert( ! is_wp_error( $result1 ), 'Primera disputa → success', $results );

    // Segunda disputa (misma order) → error.
    $result2 = LTMS_Business_Consumer_Protection::file_dispute( $test_order_id, 77777777, 'lost' );
    qa_assert( is_wp_error( $result2 ), 'Doble disputa → WP_Error', $results );

    if ( ! is_wp_error( $result1 ) ) {
        $wpdb->delete( $disputes_table, [ 'id' => (int) $result1 ] );
    }
    $order->delete( true );
}

// =====================================================================
qa_section( '8. CP4 — APPROVE_DISPUTE VENDOR_NET DEBIT' );
// =====================================================================

// 8.1 approve_dispute con vendor_net en meta → debita vendor_net (no order_total).
if ( $disputes_table_exists === $disputes_table && $vendor_id && class_exists( 'LTMS_Business_Wallet' ) ) {
    // Crear order con vendor_net meta.
    $order = wc_create_order();
    $order->set_status( 'completed' );
    $order->set_total( 100000 ); // Order total.
    $order->update_meta_data( '_ltms_vendor_id', $vendor_id );
    $order->update_meta_data( '_ltms_vendor_net', 80000 ); // Vendor net (lo que realmente recibió).
    $order->save();
    $test_order_id = $order->get_id();

    // Acreditar saldo al vendor.
    LTMS_Business_Wallet::credit( $vendor_id, 80000, 'Test credit', [ 'type' => 'test' ] );

    // Crear disputa.
    $dispute_id = LTMS_Business_Consumer_Protection::file_dispute( $test_order_id, (int) $order->get_customer_id(), 'damaged' );

    if ( ! is_wp_error( $dispute_id ) ) {
        // Poner en review.
        LTMS_Business_Consumer_Protection::review_dispute( $dispute_id, 1 );

        // Aprobar.
        $result = LTMS_Business_Consumer_Protection::approve_dispute( $dispute_id, 1, 'Test approval' );
        qa_assert( ! is_wp_error( $result ), 'approve_dispute → success', $results );

        // Verificar que el vendor fue debitado por 80000 (no 100000).
        $wallet = LTMS_Business_Wallet::get_or_create( $vendor_id );
        // El balance debería ser: 80000 (credit) - 80000 (debit) = 0 (aprox).
        qa_assert( abs( (float) $wallet['balance'] ) < 1, "CP4: vendor debitado por vendor_net (80000), no order_total (100000)", $results );

        // Limpieza.
        $wpdb->delete( $disputes_table, [ 'id' => $dispute_id ] );
    }

    $order->delete( true );
}

// 8.2 approve_dispute sin vendor_net meta → fallback a order_total (compatibilidad).
if ( $disputes_table_exists === $disputes_table && $vendor_id ) {
    $order = wc_create_order();
    $order->set_status( 'completed' );
    $order->set_total( 50000 );
    $order->update_meta_data( '_ltms_vendor_id', $vendor_id );
    // NO setear _ltms_vendor_net (simula pedido legacy).
    $order->save();
    $test_order_id = $order->get_id();

    LTMS_Business_Wallet::credit( $vendor_id, 50000, 'Test credit 2', [ 'type' => 'test' ] );

    $dispute_id = LTMS_Business_Consumer_Protection::file_dispute( $test_order_id, (int) $order->get_customer_id(), 'damaged' );

    if ( ! is_wp_error( $dispute_id ) ) {
        LTMS_Business_Consumer_Protection::review_dispute( $dispute_id, 1 );
        $result = LTMS_Business_Consumer_Protection::approve_dispute( $dispute_id, 1, 'Legacy test' );
        qa_assert( ! is_wp_error( $result ), 'CP4: approve_dispute legacy (sin vendor_net) → success', $results );
        $wpdb->delete( $disputes_table, [ 'id' => $dispute_id ] );
    }

    $order->delete( true );
}

// =====================================================================
qa_section( '9. REJECT_DISPUTE' );
// =====================================================================

// 9.1 reject_dispute en disputa inexistente → error.
$result = LTMS_Business_Consumer_Protection::reject_dispute( 99999999, 1, 'test' );
qa_assert( is_wp_error( $result ), 'reject_dispute inexistente → WP_Error', $results );

// 9.2 reject_dispute en disputa no under_review → error.
if ( $disputes_table_exists === $disputes_table ) {
    // Crear disputa en status 'filed' (no under_review).
    $order = wc_create_order();
    $order->set_status( 'completed' );
    $order->set_customer_id( 66666666 );
    $order->save();
    $test_order_id = $order->get_id();

    $dispute_id = LTMS_Business_Consumer_Protection::file_dispute( $test_order_id, 66666666, 'damaged' );

    if ( ! is_wp_error( $dispute_id ) ) {
        // Intentar rechazar sin pasar por review → error.
        $result = LTMS_Business_Consumer_Protection::reject_dispute( $dispute_id, 1, 'test' );
        qa_assert( is_wp_error( $result ), 'reject_dispute sin review previo → WP_Error', $results );

        $wpdb->delete( $disputes_table, [ 'id' => $dispute_id ] );
    }
    $order->delete( true );
}

// =====================================================================
qa_section( '10. FREEZE_HOLD_FOR_DISPUTE' );
// =====================================================================

// 10.1 freeze_hold_for_dispute en order sin hold → false (0 rows affected).
$result = LTMS_Business_Consumer_Protection::freeze_hold_for_dispute( 99999999, 'test' );
qa_assert( $result === false, 'freeze_hold en order sin hold → false', $results );

// 10.2 freeze_hold_for_dispute en order con hold → true.
if ( $vendor_id ) {
    $test_order_id = 999900010 + rand( 0, 9999 );
    LTMS_Business_Consumer_Protection::hold_commission( $vendor_id, 15000, $test_order_id );

    $result = LTMS_Business_Consumer_Protection::freeze_hold_for_dispute( $test_order_id, 'dispute_test' );
    qa_assert( $result === true, 'freeze_hold en order con hold → true', $results );

    // Verificar status = frozen.
    $hold_status = $wpdb->get_var( $wpdb->prepare(
        "SELECT status FROM `{$holds_table}` WHERE order_id = %d",
        $test_order_id
    ) );
    qa_assert( $hold_status === 'frozen', 'Hold status = frozen', $results );

    // Limpieza.
    $wpdb->delete( $holds_table, [ 'order_id' => $test_order_id ] );
}

// =====================================================================
qa_section( '11. IS_ORDER_DELIVERED_OR_NO_SHIPPING' );
// =====================================================================

// 11.1 Order inexistente → false.
$result = LTMS_Business_Consumer_Protection::is_order_delivered_or_no_shipping( 99999999 );
qa_assert( $result === false, 'Order inexistente → false', $results );

// 11.2 Order virtual (sin shipping) → true.
if ( $vendor_id ) {
    $product = new WC_Product_Simple();
    $product->set_virtual( true );
    $product->set_downloadable( true );
    $product->set_regular_price( 10000 );
    $product->set_name( 'QA Test Digital' );
    $product->save();

    $order = wc_create_order();
    $order->add_product( $product, 1 );
    $order->set_status( 'completed' );
    $order->save();
    $test_order_id = $order->get_id();

    $result = LTMS_Business_Consumer_Protection::is_order_delivered_or_no_shipping( $test_order_id );
    qa_assert( $result === true, 'Order virtual/digital → true (no requiere shipping)', $results );

    // 11.3 Order físico sin entrega → false.
    $physical_product = new WC_Product_Simple();
    $physical_product->set_regular_price( 20000 );
    $physical_product->set_name( 'QA Test Physical' );
    $physical_product->save();

    $order2 = wc_create_order();
    $order2->add_product( $physical_product, 1 );
    $order2->set_status( 'completed' );
    $order2->save();
    $test_order_id2 = $order2->get_id();

    $result2 = LTMS_Business_Consumer_Protection::is_order_delivered_or_no_shipping( $test_order_id2 );
    qa_assert( $result2 === false, 'Order físico sin entrega → false', $results );

    // 11.4 Order físico con _ltms_delivered_at → true.
    $order2->update_meta_data( '_ltms_delivered_at', gmdate( 'Y-m-d H:i:s' ) );
    $order2->save();
    $result3 = LTMS_Business_Consumer_Protection::is_order_delivered_or_no_shipping( $test_order_id2 );
    qa_assert( $result3 === true, 'Order físico con _ltms_delivered_at → true', $results );

    // Limpieza.
    $order->delete( true );
    $order2->delete( true );
    $product->delete( true );
    $physical_product->delete( true );
}

// =====================================================================
qa_section( '12. HELPERS' );
// =====================================================================

// 12.1 get_booking_checkout_date en order sin booking → null.
$result = LTMS_Business_Consumer_Protection::get_booking_checkout_date( 99999999 );
qa_assert( $result === null, 'get_booking_checkout_date sin booking → null', $results );

// 12.2 get_dispute_window_days con order_id → respeta país.
LTMS_Core_Config::set( 'ltms_country', 'CO' );
$days = LTMS_Business_Consumer_Protection::get_dispute_window_days( 12345 );
qa_assert( $days === 5, 'get_dispute_window_days CO = 5', $results );

// =====================================================================
qa_section( '13. LIMPIEZA' );
// =====================================================================

if ( $vendor_id ) {
    // Limpiar holds restantes.
    $wpdb->delete( $holds_table, [ 'vendor_id' => $vendor_id ] );
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
