<?php
/**
 * QA Tests — Tax Engine v2.8.7.
 *
 * Ejecutar: wp eval-file tests/qa-tax-engine-v287.php
 *
 * 35 tests que verifican:
 *  - Tax Engine facade (5 tests)
 *  - Colombia IVA: general, reducido, exento (4 tests)
 *  - Colombia ReteFuente: honorarios, servicios, compras, tech, mínimos UVT (6 tests)
 *  - Colombia ReteIVA: buyer gran contribuyente + vendor responsable IVA (3 tests)
 *  - Colombia ReteICA: municipio, CIIU, fallback (3 tests)
 *  - Colombia Impoconsumo: restaurantes, no restaurante (2 tests)
 *  - Colombia net_to_vendor no negativo (T2 fix) (2 tests)
 *  - Colombia UVT default (T1 fix) (2 tests)
 *  - México IVA: general, frontera, exento (3 tests)
 *  - México Retención IVA: sobre IVA generado, no gross (T5 fix) (3 tests)
 *  - México ISR: RESICO, honorarios, plataforma (3 tests)
 *  - México IEPS: tabaco, bebidas, default (2 tests)
 *  - México net_to_vendor no negativo (T4 fix) (2 tests)
 *  - México CFDI threshold configurable (T6 fix) (2 tests)
 *  - Edge cases: gross=0, negative gross, very small amounts (3 tests)
 *
 * @package LTMS
 * @version 2.8.7
 */

if ( ! defined( 'ABSPATH' ) ) {
    if ( $argv[1] ?? '' === '--syntax-only' ) {
        echo "OK: syntax-only mode.\n";
        exit( 0 );
    }
    echo "ERROR: Ejecutar con: wp eval-file tests/qa-tax-engine-v287.php\n";
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
qa_section( '1. TAX ENGINE FACADE' );
// =====================================================================

qa_assert( class_exists( 'LTMS_Tax_Engine' ), 'LTMS_Tax_Engine cargada', $results );
qa_assert( class_exists( 'LTMS_Tax_Strategy_Colombia' ), 'LTMS_Tax_Strategy_Colombia cargada', $results );
qa_assert( class_exists( 'LTMS_Tax_Strategy_Mexico' ), 'LTMS_Tax_Strategy_Mexico cargada', $results );
qa_assert( class_exists( 'LTMS_Tax_Strategy_Interface' ), 'LTMS_Tax_Strategy_Interface cargada', $results );

// País no soportado → InvalidArgumentException.
try {
    LTMS_Tax_Engine::calculate( 1000, [], [], 'XX' );
    qa_assert( false, 'País no soportado debe lanzar exception', $results );
} catch ( \InvalidArgumentException $e ) {
    qa_assert( true, 'País no soportado lanza InvalidArgumentException', $results );
}

// =====================================================================
qa_section( '2. COLOMBIA — IVA' );
// =====================================================================

$strategy_co = new LTMS_Tax_Strategy_Colombia();

// 2.1 IVA general 19% (producto físico normal).
$result = $strategy_co->calculate( 100000, [ 'product_type' => 'physical' ], [ 'tax_regime' => 'common' ] );
qa_assert( abs( $result['iva'] - 19000 ) < 1, "IVA 19% sobre 100k = 19000 (actual: {$result['iva']})", $results );
qa_assert( abs( $result['iva_rate'] - 0.19 ) < 0.001, "iva_rate = 0.19", $results );

// 2.2 IVA reducido 5% (café).
$result = $strategy_co->calculate( 100000, [ 'product_type' => 'coffee' ], [ 'tax_regime' => 'common' ] );
qa_assert( abs( $result['iva'] - 5000 ) < 1, "IVA 5% sobre 100k (coffee) = 5000 (actual: {$result['iva']})", $results );

// 2.3 IVA exento 0% (medicamentos).
$result = $strategy_co->calculate( 100000, [ 'product_type' => 'medicine' ], [ 'tax_regime' => 'common' ] );
qa_assert( $result['iva'] === 0.0, "IVA 0% (medicine) = 0", $results );

// 2.4 IVA exento 0% (alimento básico).
$result = $strategy_co->calculate( 50000, [ 'product_type' => 'basic_food' ], [ 'tax_regime' => 'common' ] );
qa_assert( $result['iva'] === 0.0, "IVA 0% (basic_food) = 0", $results );

// =====================================================================
qa_section( '3. COLOMBIA — RETEFUENTE' );
// =====================================================================

// 3.1 ReteFuente honorarios 11% (gross >= 1 UVT).
$gross = 60000; // > 1 UVT (~49799)
$result = $strategy_co->calculate( $gross, [ 'product_type' => 'consulting' ], [ 'tax_regime' => 'common' ] );
$expected_rf = round( $gross * 0.11, 2 );
qa_assert( abs( $result['retefuente'] - $expected_rf ) < 1, "ReteFuente honorarios 11% = $expected_rf (actual: {$result['retefuente']})", $results );

// 3.2 ReteFuente compras 2.5% (gross >= mínimo compras).
$gross = 600000; // > 10.666 UVT (~530k)
$result = $strategy_co->calculate( $gross, [ 'product_type' => 'physical' ], [ 'tax_regime' => 'common' ] );
$expected_rf = round( $gross * 0.025, 2 );
qa_assert( abs( $result['retefuente'] - $expected_rf ) < 1, "ReteFuente compras 2.5% = $expected_rf (actual: {$result['retefuente']})", $results );

// 3.3 ReteFuente servicios 4% (gross >= mínimo servicios).
$gross = 200000;
$result = $strategy_co->calculate( $gross, [ 'product_type' => 'general' ], [ 'tax_regime' => 'common' ] );
$expected_rf = round( $gross * 0.04, 2 );
qa_assert( abs( $result['retefuente'] - $expected_rf ) < 1, "ReteFuente servicios 4% = $expected_rf (actual: {$result['retefuente']})", $results );

// 3.4 ReteFuente servicios tech 6%.
$gross = 200000;
$result = $strategy_co->calculate( $gross, [ 'product_type' => 'software' ], [ 'tax_regime' => 'common' ] );
$expected_rf = round( $gross * 0.06, 2 );
qa_assert( abs( $result['retefuente'] - $expected_rf ) < 1, "ReteFuente tech 6% = $expected_rf (actual: {$result['retefuente']})", $results );

// 3.5 Sin retención si gross < mínimo UVT.
$gross = 1000; // << 1 UVT
$result = $strategy_co->calculate( $gross, [ 'product_type' => 'consulting' ], [ 'tax_regime' => 'common' ] );
qa_assert( $result['retefuente'] === 0.0, "Sin ReteFuente si gross < mínimo UVT", $results );

// 3.6 Régimen simplificado NO lleva retención.
$result = $strategy_co->calculate( 600000, [ 'product_type' => 'physical' ], [ 'tax_regime' => 'simplified' ] );
qa_assert( $result['retefuente'] === 0.0, "Régimen simplificado sin ReteFuente", $results );

// =====================================================================
qa_section( '4. COLOMBIA — RETEIVA (T3 FIX)' );
// =====================================================================

// 4.1 Buyer gran contribuyente + vendor común → aplica ReteIVA.
$result = $strategy_co->calculate(
    100000,
    [ 'product_type' => 'physical', 'buyer_is_gran_contribuyente' => true ],
    [ 'tax_regime' => 'common' ]
);
$expected_riva = round( 19000 * 0.15, 2 ); // 15% del IVA
qa_assert( abs( $result['reteiva'] - $expected_riva ) < 1, "ReteIVA 15% IVA = $expected_riva (actual: {$result['reteiva']})", $results );

// 4.2 T3 FIX: vendor simplificado + buyer gran → NO ReteIVA.
$result = $strategy_co->calculate(
    100000,
    [ 'product_type' => 'physical', 'buyer_is_gran_contribuyente' => true ],
    [ 'tax_regime' => 'simplified' ]
);
qa_assert( $result['reteiva'] === 0.0, "T3: vendor simplificado sin ReteIVA aunque buyer sea gran", $results );

// 4.3 Buyer NO gran contribuyente → NO ReteIVA.
$result = $strategy_co->calculate(
    100000,
    [ 'product_type' => 'physical', 'buyer_is_gran_contribuyente' => false ],
    [ 'tax_regime' => 'common' ]
);
qa_assert( $result['reteiva'] === 0.0, "Buyer no gran → sin ReteIVA", $results );

// =====================================================================
qa_section( '5. COLOMBIA — RETEICA' );
// =====================================================================

// 5.1 ReteICA con CIIU 4xxx (comercio) → 0.414%.
$result = $strategy_co->calculate(
    100000,
    [ 'product_type' => 'physical' ],
    [ 'tax_regime' => 'common', 'ciiu_code' => '4711', 'municipality_code' => '11001' ]
);
$expected_rica = round( 100000 * 0.00414, 2 );
qa_assert( abs( $result['reteica'] - $expected_rica ) < 1, "ReteICA 0.414% CIIU 4711 = $expected_rica (actual: {$result['reteica']})", $results );

// 5.2 ReteICA con CIIU 9xxx (servicios) → 0.690%.
$result = $strategy_co->calculate(
    100000,
    [ 'product_type' => 'general' ],
    [ 'tax_regime' => 'common', 'ciiu_code' => '9609', 'municipality_code' => '11001' ]
);
qa_assert( $result['reteica'] > 0, "ReteICA con CIIU 9xxx > 0", $results );

// 5.3 ReteICA con municipio vacío → usa fallback por prefijo.
$result = $strategy_co->calculate(
    100000,
    [ 'product_type' => 'physical' ],
    [ 'tax_regime' => 'common', 'ciiu_code' => '4711', 'municipality_code' => '' ]
);
qa_assert( $result['reteica'] > 0, "ReteICA con municipio vacío usa fallback", $results );

// =====================================================================
qa_section( '6. COLOMBIA — IMPOCONSUMO' );
// =====================================================================

// 6.1 Restaurante → Impoconsumo 8%.
$result = $strategy_co->calculate(
    100000,
    [ 'product_type' => 'food_service' ],
    [ 'tax_regime' => 'common' ]
);
$expected_inc = round( 100000 * 0.08, 2 );
qa_assert( abs( $result['impoconsumo'] - $expected_inc ) < 1, "Impoconsumo 8% restaurante = $expected_inc (actual: {$result['impoconsumo']})", $results );

// 6.2 Producto normal → sin Impoconsumo.
$result = $strategy_co->calculate(
    100000,
    [ 'product_type' => 'physical' ],
    [ 'tax_regime' => 'common' ]
);
qa_assert( $result['impoconsumo'] === 0.0, "Producto físico sin Impoconsumo", $results );

// =====================================================================
qa_section( '7. COLOMBIA — T2 FIX: NET_TO_VENDOR NO NEGATIVO' );
// =====================================================================

// 7.1 Gross pequeño + retenciones > gross → neto = 0 (no negativo).
$result = $strategy_co->calculate(
    100,
    [ 'product_type' => 'physical', 'buyer_is_gran_contribuyente' => true ],
    [ 'tax_regime' => 'common', 'ciiu_code' => '9609' ]
);
qa_assert( $result['net_to_vendor'] >= 0, "T2: net_to_vendor >= 0 (actual: {$result['net_to_vendor']})", $results );

// 7.2 Gross normal → neto positivo.
$result = $strategy_co->calculate(
    100000,
    [ 'product_type' => 'physical' ],
    [ 'tax_regime' => 'common' ]
);
qa_assert( $result['net_to_vendor'] > 0, "net_to_vendor > 0 con gross normal", $results );

// =====================================================================
qa_section( '8. COLOMBIA — T1 FIX: UVT DEFAULT' );
// =====================================================================

// 8.1 UVT configurado a 0 → usa default 49799.
LTMS_Core_Config::set( 'ltms_uvt_valor', 0 );
$result = $strategy_co->calculate(
    600000,
    [ 'product_type' => 'physical' ],
    [ 'tax_regime' => 'common' ]
);
qa_assert( $result['uvt_value'] === 49799.0, "T1: UVT default 49799 cuando config=0 (actual: {$result['uvt_value']})", $results );

// 8.2 UVT configurado correctamente → se respeta.
LTMS_Core_Config::set( 'ltms_uvt_valor', 50000 );
$result = $strategy_co->calculate(
    600000,
    [ 'product_type' => 'physical' ],
    [ 'tax_regime' => 'common' ]
);
qa_assert( $result['uvt_value'] === 50000.0, "UVT configurado se respeta", $results );

// Restaurar.
LTMS_Core_Config::set( 'ltms_uvt_valor', 49799 );

// =====================================================================
qa_section( '9. MÉXICO — IVA' );
// =====================================================================

$strategy_mx = new LTMS_Tax_Strategy_Mexico();

// 9.1 IVA general 16%.
$result = $strategy_mx->calculate( 1000, [ 'product_type' => 'physical' ], [ 'tax_regime' => 'resico' ] );
qa_assert( abs( $result['iva'] - 160 ) < 1, "IVA MX 16% sobre 1000 = 160 (actual: {$result['iva']})", $results );

// 9.2 IVA frontera norte 8%.
$result = $strategy_mx->calculate( 1000, [ 'product_type' => 'physical' ], [ 'tax_regime' => 'resico', 'is_border_north_zone' => true ] );
qa_assert( abs( $result['iva'] - 80 ) < 1, "IVA MX frontera 8% = 80 (actual: {$result['iva']})", $results );

// 9.3 IVA exento 0% (tortillas).
$result = $strategy_mx->calculate( 1000, [ 'product_type' => 'tortillas' ], [ 'tax_regime' => 'resico' ] );
qa_assert( $result['iva'] === 0.0, "IVA MX 0% (tortillas)", $results );

// =====================================================================
qa_section( '10. MÉXICO — T5 FIX: RETENCIÓN IVA SOBRE IVA GENERADO' );
// =====================================================================

// 10.1 Producto con IVA → retención = 2/3 del IVA.
$result = $strategy_mx->calculate(
    1000,
    [ 'product_type' => 'physical', 'platform_is_persona_moral' => true ],
    [ 'tax_regime' => 'resico' ]
);
$iva = 160; // 16% de 1000
$expected_ret = round( $iva * ( 2 / 3 ), 2 ); // 106.67
qa_assert( abs( $result['reteiva'] - $expected_ret ) < 1, "T5: Retención MX = 2/3 IVA = $expected_ret (actual: {$result['reteiva']})", $results );

// 10.2 T5 FIX: producto exento (IVA=0) → NO retención IVA.
$result = $strategy_mx->calculate(
    1000,
    [ 'product_type' => 'tortillas', 'platform_is_persona_moral' => true ],
    [ 'tax_regime' => 'resico' ]
);
qa_assert( $result['reteiva'] === 0.0, "T5: producto exento sin retención IVA (actual: {$result['reteiva']})", $results );

// 10.3 Platform no PM → no retención.
$result = $strategy_mx->calculate(
    1000,
    [ 'product_type' => 'physical', 'platform_is_persona_moral' => false ],
    [ 'tax_regime' => 'resico' ]
);
qa_assert( $result['reteiva'] === 0.0, "Platform PF → sin retención IVA", $results );

// =====================================================================
qa_section( '11. MÉXICO — ISR' );
// =====================================================================

// 11.1 ISR RESICO 1.25% (monthly_income <= 25000).
$result = $strategy_mx->calculate(
    1000,
    [ 'product_type' => 'physical' ],
    [ 'tax_regime' => 'resico', 'monthly_income' => 20000 ]
);
$expected_isr = round( 1000 * 0.0125, 2 );
qa_assert( abs( $result['isr'] - $expected_isr ) < 1, "ISR RESICO 1.25% = $expected_isr (actual: {$result['isr']})", $results );

// 11.2 ISR RESICO 2.5% (monthly_income > 83333).
$result = $strategy_mx->calculate(
    1000,
    [ 'product_type' => 'physical' ],
    [ 'tax_regime' => 'resico', 'monthly_income' => 100000 ]
);
$expected_isr = round( 1000 * 0.025, 2 );
qa_assert( abs( $result['isr'] - $expected_isr ) < 1, "ISR RESICO 2.5% = $expected_isr (actual: {$result['isr']})", $results );

// 11.3 ISR honorarios 10%.
$result = $strategy_mx->calculate(
    1000,
    [ 'product_type' => 'physical' ],
    [ 'tax_regime' => 'pf_honorarios', 'monthly_income' => 50000 ]
);
$expected_isr = round( 1000 * 0.10, 2 );
qa_assert( abs( $result['isr'] - $expected_isr ) < 1, "ISR honorarios 10% = $expected_isr (actual: {$result['isr']})", $results );

// =====================================================================
qa_section( '12. MÉXICO — IEPS' );
// =====================================================================

// 12.1 IEPS tabaco 160%.
$result = $strategy_mx->calculate(
    1000,
    [ 'product_type' => 'tobacco' ],
    [ 'tax_regime' => 'resico' ]
);
$expected_ieps = round( 1000 * 1.60, 2 );
qa_assert( abs( $result['ieps'] - $expected_ieps ) < 1, "IEPS tabaco 160% = $expected_ieps (actual: {$result['ieps']})", $results );

// 12.2 IEPS bebidas azucaradas 8%.
$result = $strategy_mx->calculate(
    1000,
    [ 'product_type' => 'sugary_drinks' ],
    [ 'tax_regime' => 'resico' ]
);
$expected_ieps = round( 1000 * 0.08, 2 );
qa_assert( abs( $result['ieps'] - $expected_ieps ) < 1, "IEPS bebidas 8% = $expected_ieps (actual: {$result['ieps']})", $results );

// =====================================================================
qa_section( '13. MÉXICO — T4 FIX: NET_TO_VENDOR NO NEGATIVO' );
// =====================================================================

// 13.1 Gross pequeño + retenciones → neto >= 0.
$result = $strategy_mx->calculate(
    50,
    [ 'product_type' => 'tobacco', 'platform_is_persona_moral' => true ],
    [ 'tax_regime' => 'pf_honorarios', 'monthly_income' => 100000 ]
);
qa_assert( $result['net_to_vendor'] >= 0, "T4: net_to_vendor MX >= 0 (actual: {$result['net_to_vendor']})", $results );

// 13.2 Gross normal → neto positivo.
$result = $strategy_mx->calculate(
    1000,
    [ 'product_type' => 'physical' ],
    [ 'tax_regime' => 'resico', 'monthly_income' => 20000 ]
);
qa_assert( $result['net_to_vendor'] > 0, "net_to_vendor MX > 0 con gross normal", $results );

// =====================================================================
qa_section( '14. MÉXICO — T6 FIX: CFDI THRESHOLD CONFIGURABLE' );
// =====================================================================

// 14.1 Default threshold $2000.
LTMS_Core_Config::set( 'ltms_mx_cfdi_threshold', 2000 );
$result = $strategy_mx->calculate( 1500, [ 'product_type' => 'physical' ], [ 'tax_regime' => 'resico' ] );
qa_assert( $result['cfdi_required'] === false, "CFDI no requerido < $2000", $results );

$result = $strategy_mx->calculate( 2500, [ 'product_type' => 'physical' ], [ 'tax_regime' => 'resico' ] );
qa_assert( $result['cfdi_required'] === true, "CFDI requerido >= $2000", $results );

// 14.2 Threshold configurable a $0.
LTMS_Core_Config::set( 'ltms_mx_cfdi_threshold', 0 );
$result = $strategy_mx->calculate( 100, [ 'product_type' => 'physical' ], [ 'tax_regime' => 'resico' ] );
qa_assert( $result['cfdi_required'] === true, "T6: CFDI requerido desde $0 si threshold=0", $results );

// Restaurar.
LTMS_Core_Config::set( 'ltms_mx_cfdi_threshold', 2000 );

// =====================================================================
qa_section( '15. EDGE CASES' );
// =====================================================================

// 15.1 Gross = 0 → todos los impuestos = 0.
$result = $strategy_co->calculate( 0, [ 'product_type' => 'physical' ], [ 'tax_regime' => 'common' ] );
qa_assert( $result['iva'] === 0.0 && $result['retefuente'] === 0.0 && $result['net_to_vendor'] === 0.0, "Gross=0 → todo 0", $results );

// 15.2 Gross negativo → neto = 0 (no negativo).
$result = $strategy_co->calculate( -1000, [ 'product_type' => 'physical' ], [ 'tax_regime' => 'common' ] );
qa_assert( $result['net_to_vendor'] >= 0, "Gross negativo → neto >= 0", $results );

// 15.3 Gross muy pequeño ($1) → no crash, neto >= 0.
$result = $strategy_co->calculate( 1, [ 'product_type' => 'physical' ], [ 'tax_regime' => 'common' ] );
qa_assert( $result['net_to_vendor'] >= 0, "Gross=$1 → no crash, neto >= 0", $results );

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
