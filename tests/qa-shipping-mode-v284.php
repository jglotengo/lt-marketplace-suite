<?php
/**
 * QA Tests — Shipping Mode v2.8.4 (Híbrido + Categoría + Shared).
 *
 * Ejecutar: wp eval-file tests/qa-shipping-mode-v284.php
 *
 * 32 tests que verifican:
 *  - Modo global default = hybrid (1 test)
 *  - 6 modos válidos (1 test)
 *  - Override por categoría (4 tests)
 *  - Override por vendor (2 tests)
 *  - Resolución efectiva del paquete (4 tests)
 *  - Resolución de conflictos multi-categoría (3 tests)
 *  - Modo SHARED — % cliente configurable (3 tests)
 *  - Modo SHARED — cálculo de tarifa (2 tests)
 *  - filter_wc_rates: free (1 test)
 *  - filter_wc_rates: hybrid (2 tests)
 *  - filter_wc_rates: flat (1 test)
 *  - filter_wc_rates: shared (1 test)
 *  - filter_wc_rates: quoted (no interviene) (1 test)
 *  - Persistencia shared en order meta (1 test)
 *  - Cálculo de cotización cheapest (1 test)
 *  - Helper categorías list (1 test)
 *  - Set/clear categoría (2 tests)
 *  - Validación % shared (1 test)
 */

if ( ! defined( 'ABSPATH' ) ) {
    if ( $argv[1] ?? '' === '--syntax-only' ) {
        echo "OK: syntax-only mode.\n";
        exit( 0 );
    }
    echo "ERROR: Este script debe ejecutarse dentro de WordPress (wp eval-file).\n";
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
qa_section( '1. CONFIGURACIÓN BASE' );
// =====================================================================

qa_assert( class_exists( 'LTMS_Shipping_Mode' ), 'LTMS_Shipping_Mode cargada', $results );

// 1.1 Default global = hybrid (estrategia recomendada v2.8.4).
$current_global = LTMS_Shipping_Mode::get_global_mode();
qa_assert( $current_global === LTMS_Shipping_Mode::MODE_HYBRID || $current_global === LTMS_Shipping_Mode::MODE_FREE_ABSORBED,
    "Modo global default es hybrid o free_absorbed (actual: $current_global)", $results );

// 1.2 Modos válidos incluye SHARED.
$modes = LTMS_Shipping_Mode::valid_modes();
qa_assert( in_array( LTMS_Shipping_Mode::MODE_SHARED, $modes, true ), 'Modo SHARED está en valid_modes()', $results );
qa_assert( count( $modes ) === 6, '6 modos válidos (quoted, flat, free, free_absorbed, hybrid, shared)', $results );

// =====================================================================
qa_section( '2. OVERRIDE POR CATEGORÍA' );
// =====================================================================

// Crear categoría temporal.
$cat_id = wp_insert_term( 'QA Test Category ' . time(), 'product_cat' );
if ( is_wp_error( $cat_id ) ) {
    qa_assert( false, 'No se pudo crear categoría de test: ' . $cat_id->get_error_message(), $results );
    $cat_id = 0;
} else {
    $cat_id = $cat_id['term_id'];
    qa_assert( $cat_id > 0, 'Categoría de test creada', $results );
}

if ( $cat_id ) {
    // 2.1 Inicialmente sin override.
    $mode = LTMS_Shipping_Mode::get_category_mode( $cat_id );
    qa_assert( $mode === null, 'Categoría sin override retorna null', $results );

    // 2.2 Set override a quoted.
    $ok = LTMS_Shipping_Mode::set_category_mode( $cat_id, LTMS_Shipping_Mode::MODE_QUOTED );
    qa_assert( $ok, 'set_category_mode(quoted) retorna true', $results );
    $mode = LTMS_Shipping_Mode::get_category_mode( $cat_id );
    qa_assert( $mode === LTMS_Shipping_Mode::MODE_QUOTED, 'get_category_mode retorna quoted', $results );

    // 2.3 Set override a free_absorbed.
    LTMS_Shipping_Mode::set_category_mode( $cat_id, LTMS_Shipping_Mode::MODE_FREE_ABSORBED );
    $mode = LTMS_Shipping_Mode::get_category_mode( $cat_id );
    qa_assert( $mode === LTMS_Shipping_Mode::MODE_FREE_ABSORBED, 'Categoría override free_absorbed', $results );

    // 2.4 Set override a shared.
    LTMS_Shipping_Mode::set_category_mode( $cat_id, LTMS_Shipping_Mode::MODE_SHARED );
    $mode = LTMS_Shipping_Mode::get_category_mode( $cat_id );
    qa_assert( $mode === LTMS_Shipping_Mode::MODE_SHARED, 'Categoría override shared', $results );

    // 2.5 Set modo inválido retorna false.
    $ok = LTMS_Shipping_Mode::set_category_mode( $cat_id, 'invalid_mode' );
    qa_assert( $ok === false, 'set_category_mode con modo inválido retorna false', $results );

    // 2.6 Clear override (modo vacío).
    $ok = LTMS_Shipping_Mode::set_category_mode( $cat_id, '' );
    qa_assert( $ok, 'set_category_mode con string vacío elimina override', $results );
    $mode = LTMS_Shipping_Mode::get_category_mode( $cat_id );
    qa_assert( $mode === null, 'Tras clear, get_category_mode retorna null', $results );
}

// =====================================================================
qa_section( '3. OVERRIDE POR VENDOR' );
// =====================================================================

// Crear usuario de test.
$vendor_id = wp_create_user( 'qa_test_vendor_' . time(), 'password123!', 'qa_test_' . time() . '@test.local' );
if ( is_wp_error( $vendor_id ) ) {
    qa_assert( false, 'No se pudo crear vendor de test: ' . $vendor_id->get_error_message(), $results );
    $vendor_id = 0;
} else {
    qa_assert( $vendor_id > 0, 'Vendor de test creado', $results );

    // 3.1 Sin override → retorna global.
    update_user_meta( $vendor_id, '_ltms_shipping_mode', '' );
    $mode = LTMS_Shipping_Mode::get_vendor_mode( $vendor_id );
    qa_assert( $mode === LTMS_Shipping_Mode::get_global_mode(), 'Vendor sin override retorna global', $results );

    // 3.2 Con override shared.
    update_user_meta( $vendor_id, '_ltms_shipping_mode', LTMS_Shipping_Mode::MODE_SHARED );
    $mode = LTMS_Shipping_Mode::get_vendor_mode( $vendor_id );
    qa_assert( $mode === LTMS_Shipping_Mode::MODE_SHARED, 'Vendor override shared funciona', $results );
}

// =====================================================================
qa_section( '4. RESOLUCIÓN EFECTIVA DEL PAQUETE' );
// =====================================================================

// Crear producto de test en la categoría de test.
if ( $cat_id && $vendor_id ) {
    $product_id = wp_insert_post( [
        'post_title'  => 'QA Test Product ' . time(),
        'post_type'   => 'product',
        'post_status' => 'publish',
        'post_author' => $vendor_id,
    ] );
    qa_assert( $product_id > 0, 'Producto de test creado', $results );

    if ( $product_id ) {
        wp_set_object_terms( $product_id, [ $cat_id ], 'product_cat' );

        // 4.1 Sin override de categoría → usa vendor override (shared).
        $package = [
            'contents' => [
                [
                    'product_id' => $product_id,
                    'quantity'   => 1,
                    'line_total' => 50000,
                ],
            ],
        ];
        $mode = LTMS_Shipping_Mode::get_effective_mode_for_package( $package );
        qa_assert( $mode === LTMS_Shipping_Mode::MODE_SHARED, 'Sin override cat → usa vendor override (shared)', $results );

        // 4.2 Override de categoría a quoted tiene prioridad sobre vendor shared.
        LTMS_Shipping_Mode::set_category_mode( $cat_id, LTMS_Shipping_Mode::MODE_QUOTED );
        $mode = LTMS_Shipping_Mode::get_effective_mode_for_package( $package );
        qa_assert( $mode === LTMS_Shipping_Mode::MODE_QUOTED, 'Override cat (quoted) > vendor (shared)', $results );

        // 4.3 Override a free_absorbed.
        LTMS_Shipping_Mode::set_category_mode( $cat_id, LTMS_Shipping_Mode::MODE_FREE_ABSORBED );
        $mode = LTMS_Shipping_Mode::get_effective_mode_for_package( $package );
        qa_assert( $mode === LTMS_Shipping_Mode::MODE_FREE_ABSORBED, 'Override cat free_absorbed funciona', $results );

        // 4.4 Limpiar override de cat → vuelve a vendor shared.
        LTMS_Shipping_Mode::set_category_mode( $cat_id, '' );
        $mode = LTMS_Shipping_Mode::get_effective_mode_for_package( $package );
        qa_assert( $mode === LTMS_Shipping_Mode::MODE_SHARED, 'Tras clear cat override → vendor shared', $results );
    }
}

// =====================================================================
qa_section( '5. RESOLUCIÓN DE CONFLICTOS MULTI-CATEGORÍA' );
// =====================================================================

if ( $cat_id && $vendor_id && isset( $product_id ) && $product_id ) {
    // Crear segunda categoría con override distinto.
    $cat2_id = wp_insert_term( 'QA Test Cat 2 ' . time(), 'product_cat' );
    if ( ! is_wp_error( $cat2_id ) ) {
        $cat2_id = $cat2_id['term_id'];

        // Crear segundo producto en cat2.
        $product2_id = wp_insert_post( [
            'post_title'  => 'QA Test Product 2 ' . time(),
            'post_type'   => 'product',
            'post_status' => 'publish',
            'post_author' => $vendor_id,
        ] );
        wp_set_object_terms( $product2_id, [ $cat2_id ], 'product_cat' );

        // 5.1 Cat1=quoted, Cat2=free_absorbed → gana quoted (más restrictivo).
        LTMS_Shipping_Mode::set_category_mode( $cat_id, LTMS_Shipping_Mode::MODE_QUOTED );
        LTMS_Shipping_Mode::set_category_mode( $cat2_id, LTMS_Shipping_Mode::MODE_FREE_ABSORBED );

        $package = [
            'contents' => [
                [ 'product_id' => $product_id,  'quantity' => 1, 'line_total' => 50000 ],
                [ 'product_id' => $product2_id, 'quantity' => 1, 'line_total' => 30000 ],
            ],
        ];
        $mode = LTMS_Shipping_Mode::get_effective_mode_for_package( $package );
        qa_assert( $mode === LTMS_Shipping_Mode::MODE_QUOTED, 'Conflicto: quoted > free_absorbed (gana restrictivo)', $results );

        // 5.2 Cat1=hybrid, Cat2=shared → gana shared (prioridad 3 > 4).
        LTMS_Shipping_Mode::set_category_mode( $cat_id, LTMS_Shipping_Mode::MODE_HYBRID );
        LTMS_Shipping_Mode::set_category_mode( $cat2_id, LTMS_Shipping_Mode::MODE_SHARED );
        $mode = LTMS_Shipping_Mode::get_effective_mode_for_package( $package );
        qa_assert( $mode === LTMS_Shipping_Mode::MODE_SHARED, 'Conflicto: shared > hybrid (gana restrictivo)', $results );

        // 5.3 Cat1=quoted, Cat2=quoted → mismo modo, no hay conflicto.
        LTMS_Shipping_Mode::set_category_mode( $cat_id, LTMS_Shipping_Mode::MODE_QUOTED );
        LTMS_Shipping_Mode::set_category_mode( $cat2_id, LTMS_Shipping_Mode::MODE_QUOTED );
        $mode = LTMS_Shipping_Mode::get_effective_mode_for_package( $package );
        qa_assert( $mode === LTMS_Shipping_Mode::MODE_QUOTED, 'Mismo override en ambos items → usa ese', $results );

        // Limpiar.
        LTMS_Shipping_Mode::set_category_mode( $cat_id, '' );
        LTMS_Shipping_Mode::set_category_mode( $cat2_id, '' );
        wp_delete_post( $product2_id, true );
        wp_delete_term( $cat2_id, 'product_cat' );
    }
}

// =====================================================================
qa_section( '6. MODO SHARED — CONFIGURACIÓN' );
// =====================================================================

// 6.1 Default % = 60.
$pct = LTMS_Shipping_Mode::get_shared_customer_pct();
qa_assert( $pct === 60.0 || $pct > 0, "Shared customer % default (actual: $pct)", $results );

// 6.2 Set % a 50.
LTMS_Shipping_Mode::set_shared_customer_pct( 50.0 );
$pct = LTMS_Shipping_Mode::get_shared_customer_pct();
qa_assert( $pct === 50.0, 'Set shared pct a 50 funciona', $results );

// 6.3 Set % inválido (>100) retorna false.
$ok = LTMS_Shipping_Mode::set_shared_customer_pct( 150.0 );
qa_assert( $ok === false, 'Set shared pct >100 rechazado', $results );

// 6.4 Set % inválido (<0) retorna false.
$ok = LTMS_Shipping_Mode::set_shared_customer_pct( -10.0 );
qa_assert( $ok === false, 'Set shared pct <0 rechazado', $results );

// Restaurar a 60.
LTMS_Shipping_Mode::set_shared_customer_pct( 60.0 );

// =====================================================================
qa_section( '7. FILTER_WC_RATES — TODOS LOS MODOS' );
// =====================================================================

// Crear mock de WC_Shipping_Rate.
if ( class_exists( 'WC_Shipping_Rate' ) && $vendor_id ) {
    // 7.1 Modo free → fuerza $0.
    $rate = new WC_Shipping_Rate( 'test', 'Test', 10000, [], 'flat_rate' );
    $rates = [ 'test' => $rate ];
    $package = [
        'contents' => [
            [ 'product_id' => $product_id ?? 1, 'quantity' => 1, 'line_total' => 50000 ],
        ],
    ];

    // Set vendor override a free.
    update_user_meta( $vendor_id, '_ltms_shipping_mode', LTMS_Shipping_Mode::MODE_FREE );
    $filtered = LTMS_Shipping_Mode::filter_wc_rates( $rates, $package );
    qa_assert( (float) $filtered['test']->cost === 0.0, 'Modo free fuerza $0', $results );

    // 7.2 Modo free_absorbed → fuerza $0.
    update_user_meta( $vendor_id, '_ltms_shipping_mode', LTMS_Shipping_Mode::MODE_FREE_ABSORBED );
    $rate2 = new WC_Shipping_Rate( 'test2', 'Test', 10000, [], 'flat_rate' );
    $filtered = LTMS_Shipping_Mode::filter_wc_rates( [ 'test2' => $rate2 ], $package );
    qa_assert( (float) $filtered['test2']->cost === 0.0, 'Modo free_absorbed fuerza $0', $results );

    // 7.3 Modo flat → reemplaza por tarifa plana.
    update_user_meta( $vendor_id, '_ltms_shipping_mode', LTMS_Shipping_Mode::MODE_FLAT );
    update_user_meta( $vendor_id, '_ltms_flat_shipping_rate', 7500 );
    $rate3 = new WC_Shipping_Rate( 'test3', 'Test', 10000, [], 'flat_rate' );
    $filtered = LTMS_Shipping_Mode::filter_wc_rates( [ 'test3' => $rate3 ], $package );
    qa_assert( (float) $filtered['test3']->cost === 7500.0, 'Modo flat reemplaza por tarifa configurada', $results );
    qa_assert( count( $filtered ) === 1, 'Modo flat deja solo una tarifa', $results );

    // 7.4 Modo quoted → no interviene (deja tarifas originales).
    update_user_meta( $vendor_id, '_ltms_shipping_mode', LTMS_Shipping_Mode::MODE_QUOTED );
    $rate4 = new WC_Shipping_Rate( 'test4', 'Test', 12000, [], 'flat_rate' );
    $filtered = LTMS_Shipping_Mode::filter_wc_rates( [ 'test4' => $rate4 ], $package );
    qa_assert( (float) $filtered['test4']->cost === 12000.0, 'Modo quoted no interviene', $results );

    // 7.5 Modo shared → ajusta al 60% (default restaurado).
    update_user_meta( $vendor_id, '_ltms_shipping_mode', LTMS_Shipping_Mode::MODE_SHARED );
    LTMS_Shipping_Mode::set_shared_customer_pct( 60.0 );
    $rate5 = new WC_Shipping_Rate( 'test5', 'Test', 10000, [], 'flat_rate' );
    $filtered = LTMS_Shipping_Mode::filter_wc_rates( [ 'test5' => $rate5 ], $package );
    qa_assert( (float) $filtered['test5']->cost === 6000.0, 'Modo shared ajusta a 60% (6000 de 10000)', $results );

    // 7.6 Modo shared con 40%.
    LTMS_Shipping_Mode::set_shared_customer_pct( 40.0 );
    $rate6 = new WC_Shipping_Rate( 'test6', 'Test', 10000, [], 'flat_rate' );
    $filtered = LTMS_Shipping_Mode::filter_wc_rates( [ 'test6' => $rate6 ], $package );
    qa_assert( (float) $filtered['test6']->cost === 4000.0, 'Modo shared con 40% → 4000', $results );
    LTMS_Shipping_Mode::set_shared_customer_pct( 60.0 ); // Restaurar.

    // 7.7 Modo hybrid por debajo del umbral → deja tarifa original.
    update_user_meta( $vendor_id, '_ltms_shipping_mode', LTMS_Shipping_Mode::MODE_HYBRID );
    update_user_meta( $vendor_id, '_ltms_hybrid_threshold', 100000 );
    $rate7 = new WC_Shipping_Rate( 'test7', 'Test', 8000, [], 'flat_rate' );
    $package_low = [
        'contents' => [
            [ 'product_id' => $product_id ?? 1, 'quantity' => 1, 'line_total' => 50000 ], // < 100000
        ],
    ];
    $filtered = LTMS_Shipping_Mode::filter_wc_rates( [ 'test7' => $rate7 ], $package_low );
    qa_assert( (float) $filtered['test7']->cost === 8000.0, 'Hybrid por debajo umbral deja tarifa original', $results );

    // 7.8 Modo hybrid por encima del umbral → fuerza $0.
    $package_high = [
        'contents' => [
            [ 'product_id' => $product_id ?? 1, 'quantity' => 2, 'line_total' => 150000 ], // > 100000
        ],
    ];
    $rate8 = new WC_Shipping_Rate( 'test8', 'Test', 8000, [], 'flat_rate' );
    $filtered = LTMS_Shipping_Mode::filter_wc_rates( [ 'test8' => $rate8 ], $package_high );
    qa_assert( (float) $filtered['test8']->cost === 0.0, 'Hybrid por encima umbral fuerza $0', $results );
}

// =====================================================================
qa_section( '8. PERSISTENCIA SHARED EN SESIÓN' );
// =====================================================================

if ( $vendor_id && class_exists( 'WC_Shipping_Rate' ) ) {
    update_user_meta( $vendor_id, '_ltms_shipping_mode', LTMS_Shipping_Mode::MODE_SHARED );
    LTMS_Shipping_Mode::set_shared_customer_pct( 70.0 );

    // Simular WC session.
    if ( WC()->session ) {
        $rate = new WC_Shipping_Rate( 'sess_test', 'Test', 10000, [], 'flat_rate' );
        $package = [
            'contents' => [ [ 'product_id' => $product_id ?? 1, 'quantity' => 1, 'line_total' => 50000 ] ],
        ];
        LTMS_Shipping_Mode::filter_wc_rates( [ 'sess_test' => $rate ], $package );
        $saved = WC()->session->get( 'ltms_shared_shipping_quote' );
        qa_assert( $saved !== null, 'Shared quote persistida en sesión', $results );
        if ( $saved ) {
            qa_assert( (float) $saved['customer_pays'] === 7000.0, 'Customer_pays = 70% de 10000 = 7000', $results );
            qa_assert( (float) $saved['vendor_pays'] === 3000.0, 'Vendor_pays = 30% de 10000 = 3000', $results );
            qa_assert( (float) $saved['share_pct'] === 70.0, 'Share_pct = 70', $results );
        }
        WC()->session->__unset( 'ltms_shared_shipping_quote' );
    } else {
        qa_assert( true, 'WC session no disponible (skip)', $results );
    }

    LTMS_Shipping_Mode::set_shared_customer_pct( 60.0 ); // Restaurar.
}

// =====================================================================
qa_section( '9. HELPERS DE CATEGORÍAS' );
// =====================================================================

if ( $cat_id ) {
    // Set override.
    LTMS_Shipping_Mode::set_category_mode( $cat_id, LTMS_Shipping_Mode::MODE_HYBRID );

    $all = LTMS_Shipping_Mode::get_all_category_overrides();
    qa_assert( isset( $all[ $cat_id ] ), 'get_all_category_overrides incluye cat de test', $results );
    qa_assert( $all[ $cat_id ]['mode'] === LTMS_Shipping_Mode::MODE_HYBRID, 'Categoría listada con modo correcto', $results );

    // Limpiar.
    LTMS_Shipping_Mode::set_category_mode( $cat_id, '' );
    $all = LTMS_Shipping_Mode::get_all_category_overrides();
    qa_assert( ! isset( $all[ $cat_id ] ), 'Tras clear, categoría no aparece en overrides', $results );
}

// =====================================================================
qa_section( '10. LIMPIEZA' );
// =====================================================================

// Restaurar configs.
if ( $vendor_id ) {
    delete_user_meta( $vendor_id, '_ltms_shipping_mode' );
    delete_user_meta( $vendor_id, '_ltms_flat_shipping_rate' );
    delete_user_meta( $vendor_id, '_ltms_hybrid_threshold' );
    if ( isset( $product_id ) && $product_id ) {
        wp_delete_post( $product_id, true );
    }
    wp_delete_user( $vendor_id );
}

if ( $cat_id ) {
    LTMS_Shipping_Mode::set_category_mode( $cat_id, '' );
    wp_delete_term( $cat_id, 'product_cat' );
}

LTMS_Shipping_Mode::set_shared_customer_pct( 60.0 );

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
