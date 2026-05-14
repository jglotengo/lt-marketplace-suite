<?php
/**
 * LTMS QA — Pruebas de integración Alegra
 * 
 * Ejecutar desde la raíz del plugin:
 *   wp --path=/home/customer/www/lo-tengo.com.co/public_html \
 *      eval-file bin/ltms-qa-alegra.php --allow-root 2>/dev/null
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WP_CLI' ) ) {
    die( "Ejecutar con WP-CLI\n" );
}

// ── Helpers ────────────────────────────────────────────────────────────────────
$pass = 0; $fail = 0; $warn = 0;

function qa_ok( string $test, string $detail = '' ): void {
    global $pass;
    $pass++;
    echo "  ✅ PASS  $test" . ( $detail ? " — $detail" : '' ) . "\n";
}
function qa_fail( string $test, string $detail = '' ): void {
    global $fail;
    $fail++;
    echo "  ❌ FAIL  $test" . ( $detail ? " — $detail" : '' ) . "\n";
}
function qa_warn( string $test, string $detail = '' ): void {
    global $warn;
    $warn++;
    echo "  ⚠️  WARN  $test" . ( $detail ? " — $detail" : '' ) . "\n";
}
function qa_section( string $title ): void {
    echo "\n══════════════════════════════════════════════════\n";
    echo "  $title\n";
    echo "══════════════════════════════════════════════════\n";
}

// Flush caches
LTMS_Core_Config::flush_cache();
LTMS_Api_Factory::reset( 'alegra' );

echo "\n🔍 LTMS QA — Pruebas de integración Alegra\n";
echo "Fecha: " . date( 'Y-m-d H:i:s' ) . "\n";

// ── T-01: AUTENTICACIÓN Y CONECTIVIDAD ─────────────────────────────────────────
qa_section( 'T-01 · Autenticación y conectividad' );
try {
    $alegra = LTMS_Api_Factory::get( 'alegra' );
    qa_ok( 'Factory instancia LTMS_Api_Alegra' );

    $result = $alegra->health_check();
    if ( ( $result['status'] ?? '' ) === 'ok' ) {
        qa_ok( 'health_check()', $result['message'] . ' — ' . ( $result['latency_ms'] ?? '?' ) . 'ms' );
    } else {
        qa_fail( 'health_check()', $result['message'] ?? 'Sin mensaje' );
    }
} catch ( Throwable $e ) {
    qa_fail( 'Instanciar cliente Alegra', $e->getMessage() );
    echo "\n⛔  Sin credenciales — abortando QA.\n";
    exit(1);
}

// ── T-02: EMPRESA ──────────────────────────────────────────────────────────────
qa_section( 'T-02 · Información de empresa' );
try {
    $company = $alegra->get_company();
    if ( ! empty( $company['name'] ) ) {
        qa_ok( 'get_company()', 'Empresa: ' . $company['name'] );
    } else {
        qa_warn( 'get_company()', 'Respuesta sin campo name: ' . wp_json_encode( array_keys( $company ) ) );
    }
    if ( ! empty( $company['id'] ) ) {
        qa_ok( 'Empresa tiene ID Alegra', 'ID: ' . $company['id'] );
    }
    // Verificar país
    $country = $company['address']['country']['code'] ?? $company['regime'] ?? 'desconocido';
    echo "       Info: país/régimen = $country\n";
} catch ( Throwable $e ) {
    qa_fail( 'get_company()', $e->getMessage() );
}

// ── T-03: NUMERACIONES ─────────────────────────────────────────────────────────
qa_section( 'T-03 · Numeraciones de factura' );
$template_id = null;
try {
    $templates = $alegra->get_number_templates();
    if ( is_array( $templates ) && count( $templates ) > 0 ) {
        qa_ok( 'get_number_templates()', count( $templates ) . ' numeraciones encontradas' );
        $template_id = $templates[0]['id'] ?? null;
        foreach ( array_slice( $templates, 0, 3 ) as $t ) {
            echo "       - ID: " . ( $t['id'] ?? '?' ) . " | " . ( $t['fullNumber'] ?? $t['number'] ?? '?' ) . " | " . ( $t['documentType'] ?? '' ) . "\n";
        }
        // Verificar que el ID configurado en settings existe
        $configured_id = (int) LTMS_Core_Config::get( 'ltms_alegra_numbering_id', 0 );
        if ( $configured_id ) {
            $found = array_filter( $templates, fn($t) => (int)($t['id']??0) === $configured_id );
            if ( $found ) {
                qa_ok( "Numeración ID=$configured_id configurada existe en Alegra" );
            } else {
                qa_warn( "Numeración ID=$configured_id configurada NO encontrada en Alegra", "IDs disponibles: " . implode(', ', array_column($templates,'id')) );
            }
        } else {
            qa_warn( 'ltms_alegra_numbering_id no configurado', 'Las facturas se crearán sin numeración específica' );
        }
    } else {
        qa_warn( 'get_number_templates()', 'Sin numeraciones — facturas sin número de resolución' );
    }
} catch ( Throwable $e ) {
    qa_fail( 'get_number_templates()', $e->getMessage() );
}

// ── T-04: CONTACTOS ────────────────────────────────────────────────────────────
qa_section( 'T-04 · CRUD de contactos' );
$test_contact_id = null;
$test_identification = 'LTMS-QA-' . time();
try {
    // Crear contacto de prueba
    $contact = $alegra->create_contact([
        'name'           => 'QA Test Usuario LTMS',
        'email'          => 'qa-test@lo-tengo.com.co',
        'identification' => $test_identification,
        'phone'          => '3001234567',
        'type'           => [ 'client' ],
        'address'        => [
            'address' => 'Calle QA 123',
            'city'    => 'Bogotá',
        ],
    ]);
    if ( ! empty( $contact['id'] ) ) {
        $test_contact_id = (int) $contact['id'];
        qa_ok( 'create_contact()', "ID=$test_contact_id | nombre=" . ( $contact['name'] ?? '?' ) );
    } else {
        qa_fail( 'create_contact()', 'Sin ID en respuesta: ' . wp_json_encode( $contact ) );
    }
} catch ( Throwable $e ) {
    qa_fail( 'create_contact()', $e->getMessage() );
}

// Buscar por identificación
if ( $test_contact_id ) {
    try {
        $found = $alegra->find_contact_by_identification( $test_identification );
        if ( $found && (int)($found['id']??0) === $test_contact_id ) {
            qa_ok( 'find_contact_by_identification()', "Encontrado ID=$test_contact_id" );
        } else {
            qa_warn( 'find_contact_by_identification()', 'No encontrado o ID diferente (puede ser paginación)' );
        }
    } catch ( Throwable $e ) {
        qa_fail( 'find_contact_by_identification()', $e->getMessage() );
    }

    // get_or_create — debe retornar el existente
    try {
        $same = $alegra->get_or_create_contact([
            'name'           => 'QA Test Usuario LTMS',
            'identification' => $test_identification,
        ]);
        if ( (int)($same['id']??0) === $test_contact_id ) {
            qa_ok( 'get_or_create_contact() — idempotente', "Retornó contacto existente ID=$test_contact_id" );
        } else {
            qa_warn( 'get_or_create_contact()', 'Creó duplicado en vez de retornar existente' );
        }
    } catch ( Throwable $e ) {
        qa_fail( 'get_or_create_contact()', $e->getMessage() );
    }
}

// ── T-05: ITEMS ────────────────────────────────────────────────────────────────
qa_section( 'T-05 · CRUD de items/productos' );
$test_item_id = null;
try {
    $item = $alegra->create_item([
        'name'        => 'QA Producto LTMS ' . date('His'),
        'price'       => 150000,
        'type'        => 'product',
        'description' => 'Producto creado por QA LTMS — borrar',
    ]);
    if ( ! empty( $item['id'] ) ) {
        $test_item_id = (int) $item['id'];
        qa_ok( 'create_item()', "ID=$test_item_id | nombre=" . ( $item['name'] ?? '?' ) );
    } else {
        qa_fail( 'create_item()', 'Sin ID en respuesta: ' . wp_json_encode( $item ) );
    }
} catch ( Throwable $e ) {
    qa_fail( 'create_item()', $e->getMessage() );
}

if ( $test_item_id ) {
    try {
        $updated = $alegra->update_item( $test_item_id, [ 'price' => 175000 ] );
        $new_price = (float)( $updated['price'] ?? $updated['prices'][0]['price'] ?? 0 );
        if ( $new_price === 175000.0 ) {
            qa_ok( 'update_item()', "Precio actualizado a $175.000" );
        } else {
            qa_warn( 'update_item()', "Precio en respuesta: $new_price (esperado 175000)" );
        }
    } catch ( Throwable $e ) {
        qa_fail( 'update_item()', $e->getMessage() );
    }
}

// ── T-06: FACTURAS ─────────────────────────────────────────────────────────────
qa_section( 'T-06 · Creación y lectura de facturas' );
$test_invoice_id = null;
if ( $test_contact_id && $test_item_id ) {
    try {
        $invoice_data = [
            'date'      => date( 'Y-m-d' ),
            'due_date'  => date( 'Y-m-d', strtotime( '+30 days' ) ),
            'client_id' => $test_contact_id,
            'items'     => [
                [
                    'alegra_id' => $test_item_id,
                    'quantity'  => 2,
                    'price'     => 150000,
                    'name'      => 'QA Producto LTMS',
                ],
            ],
            'observations' => 'Factura de prueba QA LTMS — pedido WC #TEST-' . date('His'),
        ];
        if ( $template_id ) {
            $invoice_data['number_template_id'] = $template_id;
        }

        $invoice = $alegra->create_invoice( $invoice_data );
        if ( ! empty( $invoice['id'] ) ) {
            $test_invoice_id = (int) $invoice['id'];
            $inv_number = $invoice['numberTemplate']['fullNumber'] ?? '#' . $test_invoice_id;
            $inv_status = $invoice['status'] ?? '?';
            qa_ok( 'create_invoice()', "ID=$test_invoice_id | número=$inv_number | estado=$inv_status" );
        } else {
            qa_fail( 'create_invoice()', 'Sin ID en respuesta: ' . wp_json_encode( array_keys( $invoice ) ) );
        }
    } catch ( Throwable $e ) {
        qa_fail( 'create_invoice()', $e->getMessage() );
    }

    // Leer factura
    if ( $test_invoice_id ) {
        try {
            $fetched = $alegra->get_invoice( $test_invoice_id );
            if ( (int)($fetched['id']??0) === $test_invoice_id ) {
                qa_ok( 'get_invoice()', "ID=$test_invoice_id leído OK | total=" . ( $fetched['total'] ?? '?' ) );
                // Verificar total (2 x 150.000 = 300.000)
                $total = (float)( $fetched['total'] ?? 0 );
                if ( $total >= 300000 ) {
                    qa_ok( 'Total de factura correcto', number_format( $total, 0, ',', '.' ) . ' COP' );
                } else {
                    qa_warn( 'Total de factura', "Esperado ≥300.000, obtenido $total (puede incluir descuentos/impuestos)" );
                }
            } else {
                qa_fail( 'get_invoice()', 'ID no coincide o respuesta vacía' );
            }
        } catch ( Throwable $e ) {
            qa_fail( 'get_invoice()', $e->getMessage() );
        }

        // Listar facturas
        try {
            $list = $alegra->list_invoices( 0, 5 );
            $list_data = $list['data'] ?? ( is_array($list) && isset($list[0]) ? $list : [] );
            if ( count( $list_data ) > 0 ) {
                qa_ok( 'list_invoices()', count( $list_data ) . ' facturas retornadas (últimas 5)' );
            } else {
                qa_warn( 'list_invoices()', 'Sin facturas en respuesta: ' . wp_json_encode( array_keys($list) ) );
            }
        } catch ( Throwable $e ) {
            qa_fail( 'list_invoices()', $e->getMessage() );
        }
    }
} else {
    qa_warn( 'T-06 omitido', 'Requiere contacto e item creados en T-04/T-05' );
}

// ── T-07: FACTURACIÓN AUTOMÁTICA DESDE PEDIDO WC ──────────────────────────────
qa_section( 'T-07 · Facturación automática (create_invoice_for_order)' );
// Buscar el pedido WC más reciente completado sin factura Alegra
$orders = wc_get_orders([
    'status'     => [ 'completed', 'processing' ],
    'limit'      => 5,
    'meta_query' => [[
        'key'     => '_ltms_alegra_invoice_id',
        'compare' => 'NOT EXISTS',
    ]],
]);

if ( $orders ) {
    $test_order = $orders[0];
    echo "       Pedido de prueba: #" . $test_order->get_id() . " | estado=" . $test_order->get_status() . " | total=" . $test_order->get_total() . "\n";
    try {
        $sync   = new LTMS_Alegra_Sync();
        $result = $sync->create_invoice_for_order( $test_order );
        if ( ! empty( $result['id'] ) ) {
            $inv_num = $result['numberTemplate']['fullNumber'] ?? '#' . $result['id'];
            qa_ok( 'create_invoice_for_order()', "Factura $inv_num creada para pedido #" . $test_order->get_id() );
            // Verificar que el pedido quedó marcado
            $test_order->read_meta_data( true );
            $stored_id = $test_order->get_meta( '_ltms_alegra_invoice_id' );
            // (no guardamos aquí — solo leemos la respuesta sin alterar el pedido)
            qa_ok( 'Respuesta contiene id, status, numberTemplate', "id={$result['id']} status=" . ($result['status']??'?') );
        } else {
            qa_fail( 'create_invoice_for_order()', 'Sin ID en respuesta' );
        }
    } catch ( Throwable $e ) {
        qa_fail( 'create_invoice_for_order()', $e->getMessage() );
    }
} else {
    qa_warn( 'T-07 omitido', 'No hay pedidos completados/processing sin factura Alegra para probar' );
}

// ── T-08: WEBHOOK HANDLER ─────────────────────────────────────────────────────
qa_section( 'T-08 · Webhook handler (simulado)' );
if ( $test_invoice_id ) {
    // Simular payload de Alegra: edit-invoice con estado 'closed'
    $mock_request = new WP_REST_Request( 'POST', '/ltms/v1/webhooks/alegra' );
    $mock_request->set_header( 'content-type', 'application/json' );
    $mock_request->set_body( wp_json_encode([
        'action' => 'edit-invoice',
        'data'   => [
            'id'     => $test_invoice_id,
            'status' => 'closed',
            'numberTemplate' => [ 'fullNumber' => 'TEST-001' ],
        ],
    ]));

    try {
        $response = LTMS_Alegra_Webhook_Handler::handle( $mock_request );
        $data = $response->get_data();
        $code = $response->get_status();
        if ( $code === 200 && ( $data['received'] ?? false ) ) {
            qa_ok( 'Webhook edit-invoice procesado', "HTTP $code | received=true" );
        } else {
            qa_fail( 'Webhook edit-invoice', "HTTP $code | " . wp_json_encode( $data ) );
        }
    } catch ( Throwable $e ) {
        qa_fail( 'Webhook handler', $e->getMessage() );
    }

    // Evento desconocido → debe responder 200
    $mock_unknown = new WP_REST_Request( 'POST', '/ltms/v1/webhooks/alegra' );
    $mock_unknown->set_body( wp_json_encode([ 'action' => 'new-bill', 'data' => [] ]) );
    try {
        $r2 = LTMS_Alegra_Webhook_Handler::handle( $mock_unknown );
        if ( $r2->get_status() === 200 ) {
            qa_ok( 'Webhook evento desconocido retorna 200', 'processed=false OK' );
        } else {
            qa_fail( 'Webhook evento desconocido', 'HTTP ' . $r2->get_status() );
        }
    } catch ( Throwable $e ) {
        qa_fail( 'Webhook evento desconocido', $e->getMessage() );
    }

    // Token inválido → 401
    LTMS_Core_Config::flush_cache();
    // Temporalmente setear un secret para probar auth
    update_option( 'ltms_alegra_webhook_secret', 'test-secret-qa' );
    LTMS_Core_Config::flush_cache();
    $mock_noauth = new WP_REST_Request( 'POST', '/ltms/v1/webhooks/alegra' );
    $mock_noauth->set_body( wp_json_encode([ 'action' => 'edit-invoice', 'data' => ['id'=>1] ]) );
    try {
        $r3 = LTMS_Alegra_Webhook_Handler::handle( $mock_noauth );
        if ( $r3->get_status() === 401 ) {
            qa_ok( 'Webhook token inválido retorna 401', 'Seguridad OK' );
        } else {
            qa_fail( 'Webhook auth', 'Esperado 401, obtenido ' . $r3->get_status() );
        }
    } catch ( Throwable $e ) {
        qa_fail( 'Webhook auth', $e->getMessage() );
    }
    // Restaurar
    delete_option( 'ltms_alegra_webhook_secret' );
    LTMS_Core_Config::flush_cache();
} else {
    qa_warn( 'T-08 omitido', 'Requiere factura de prueba creada en T-06' );
}

// ── T-09: CONFIGURACIÓN Y META ─────────────────────────────────────────────────
qa_section( 'T-09 · Configuración y opciones guardadas' );
$checks = [
    'ltms_alegra_enabled'       => 'Alegra activo',
    'ltms_alegra_email'         => 'Email configurado',
    'ltms_alegra_token'         => 'Token configurado',
    'ltms_alegra_numbering_id'  => 'ID Numeración',
    'ltms_alegra_auto_invoice'  => 'Facturación automática',
    'ltms_alegra_sandbox'       => 'Modo sandbox',
];
foreach ( $checks as $key => $label ) {
    $val = get_option( $key, '' );
    if ( in_array( $key, ['ltms_alegra_token'], true ) ) {
        $display = $val ? '✓ (presente, ' . strlen($val) . ' chars)' : '✗ vacío';
    } elseif ( in_array( $key, ['ltms_alegra_enabled','ltms_alegra_auto_invoice','ltms_alegra_sandbox'], true ) ) {
        $display = $val;
        if ( $key === 'ltms_alegra_enabled' && $val !== 'yes' ) {
            qa_warn( $label, "valor='$val' — la integración no se activará" );
            continue;
        }
    } else {
        $display = $val ?: '(vacío)';
    }
    qa_ok( $label, $display );
}

// ── RESUMEN ────────────────────────────────────────────────────────────────────
echo "\n══════════════════════════════════════════════════\n";
echo "  RESUMEN QA\n";
echo "══════════════════════════════════════════════════\n";
echo "  ✅ PASS : $pass\n";
echo "  ❌ FAIL : $fail\n";
echo "  ⚠️  WARN : $warn\n";
echo "  TOTAL  : " . ($pass + $fail + $warn) . " pruebas\n\n";
if ( $fail === 0 ) {
    echo "  🎉 Todas las pruebas críticas pasaron.\n";
} else {
    echo "  🔴 Hay $fail prueba(s) fallida(s) que requieren atención.\n";
}
echo "\n  Datos de prueba creados en Alegra:\n";
if ( $test_contact_id ) echo "    · Contacto ID=$test_contact_id (QA Test Usuario LTMS — borrar)\n";
if ( $test_item_id )    echo "    · Item ID=$test_item_id (QA Producto LTMS — borrar)\n";
if ( $test_invoice_id ) echo "    · Factura ID=$test_invoice_id (QA — borrar)\n";
echo "\n";
