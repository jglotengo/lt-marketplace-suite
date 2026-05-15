<?php
/**
 * LTMS QA — Pruebas de integración Alegra
 * 
 * wp --path=/home/customer/www/lo-tengo.com.co/public_html \
 *    eval-file bin/ltms-qa-alegra.php --allow-root 2>/dev/null
 */

// ── Contadores (arrays para escapar scope de WP-CLI eval-file) ─────────────────
$qa = [ 'pass' => 0, 'fail' => 0, 'warn' => 0, 'log' => [] ];

function qa_ok( array &$qa, string $test, string $detail = '' ): void {
    $qa['pass']++;
    $qa['log'][] = [ 'r' => 'PASS', 't' => $test, 'd' => $detail ];
    echo "  ✅ PASS  $test" . ( $detail ? " — $detail" : '' ) . "\n";
}
function qa_fail( array &$qa, string $test, string $detail = '' ): void {
    $qa['fail']++;
    $qa['log'][] = [ 'r' => 'FAIL', 't' => $test, 'd' => $detail ];
    echo "  ❌ FAIL  $test" . ( $detail ? " — $detail" : '' ) . "\n";
}
function qa_warn( array &$qa, string $test, string $detail = '' ): void {
    $qa['warn']++;
    $qa['log'][] = [ 'r' => 'WARN', 't' => $test, 'd' => $detail ];
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

// ── T-01: AUTENTICACIÓN ────────────────────────────────────────────────────────
qa_section( 'T-01 · Autenticación y conectividad' );
$alegra = null;
try {
    $alegra = LTMS_Api_Factory::get( 'alegra' );
    qa_ok( $qa, 'Factory instancia LTMS_Api_Alegra' );
    $result = $alegra->health_check();
    if ( ( $result['status'] ?? '' ) === 'ok' ) {
        qa_ok( $qa, 'health_check()', $result['message'] . ' — ' . ( $result['latency_ms'] ?? '?' ) . 'ms' );
    } else {
        qa_fail( $qa, 'health_check()', $result['message'] ?? 'Sin mensaje' );
    }
} catch ( Throwable $e ) {
    qa_fail( $qa, 'Instanciar cliente Alegra', $e->getMessage() );
    echo "\n⛔  Sin credenciales — abortando.\n";
    exit(1);
}

// ── T-02: EMPRESA ──────────────────────────────────────────────────────────────
qa_section( 'T-02 · Información de empresa' );
try {
    $company = $alegra->get_company();
    if ( ! empty( $company['name'] ) ) {
        qa_ok( $qa, 'get_company()', 'Empresa: ' . $company['name'] );
    } else {
        qa_warn( $qa, 'get_company()', 'Sin campo name' );
    }
    $regime = $company['regime'] ?? ( $company['address']['country']['code'] ?? 'N/A' );
    echo "       Régimen: $regime\n";
    if ( ! empty( $company['id'] ) ) {
        qa_ok( $qa, 'Empresa tiene ID Alegra', 'ID: ' . $company['id'] );
    }
} catch ( Throwable $e ) {
    qa_fail( $qa, 'get_company()', $e->getMessage() );
}

// ── T-03: NUMERACIONES ─────────────────────────────────────────────────────────
qa_section( 'T-03 · Numeraciones de factura' );
$template_id = null;
try {
    $templates = $alegra->get_number_templates();
    if ( is_array( $templates ) && count( $templates ) > 0 ) {
        qa_ok( $qa, 'get_number_templates()', count( $templates ) . ' numeración(es) encontrada(s)' );
        $template_id = $templates[0]['id'] ?? null;
        foreach ( array_slice( $templates, 0, 3 ) as $t ) {
            $num = $t['fullNumber'] ?? $t['number'] ?? $t['prefix'] ?? '?';
            echo "       ID=" . ( $t['id'] ?? '?' ) . " | número=$num | tipo=" . ( $t['documentType'] ?? $t['type'] ?? '?' ) . "\n";
        }
        $configured_id = (int) LTMS_Core_Config::get( 'ltms_alegra_numbering_id', 0 );
        if ( $configured_id ) {
            $found = array_filter( $templates, fn($t) => (int)($t['id']??0) === $configured_id );
            if ( $found ) {
                qa_ok( $qa, "Numeración ID=$configured_id existe en Alegra" );
            } else {
                qa_warn( $qa, "Numeración ID=$configured_id NO encontrada", "IDs: " . implode(', ', array_column($templates,'id')) );
            }
        } else {
            qa_warn( $qa, 'ltms_alegra_numbering_id no configurado' );
        }
    } else {
        qa_warn( $qa, 'get_number_templates()', 'Sin numeraciones disponibles' );
    }
} catch ( Throwable $e ) {
    qa_fail( $qa, 'get_number_templates()', $e->getMessage() );
}

// ── T-04: CONTACTOS ────────────────────────────────────────────────────────────
qa_section( 'T-04 · CRUD de contactos' );
$test_contact_id   = null;
$test_identification = '9' . date('mdHis'); // Alegra Colombia requiere identificación numérica

// Diagnóstico directo — ver respuesta cruda de Alegra
$diag_email = get_option('ltms_alegra_email','');
// Fix: usar la clave correcta de la opción del token
$diag_token_raw = get_option('ltms_alegra_token','');
$diag_token = (str_starts_with($diag_token_raw, 'v1:') && class_exists('LTMS_Core_Security'))
    ? LTMS_Core_Security::decrypt($diag_token_raw)
    : $diag_token_raw;
$diag_url   = 'https://api.alegra.com/api/v1/contacts';
$diag_payload = wp_json_encode([
    'name' => 'QA LTMS ' . date('His'),
    'type' => ['client'],  // Alegra API v1: ARRAY requerido
    'email' => 'qa-ltms-' . date('His') . '@test.lo-tengo.com.co',
]);
$diag_response = wp_remote_post($diag_url, [
    'headers' => [
        'Authorization' => 'Basic ' . base64_encode($diag_email . ':' . $diag_token),
        'Content-Type'  => 'application/json',
        'Accept'        => 'application/json',
    ],
    'body'    => $diag_payload,
    'timeout' => 30,
]);
$diag_code = wp_remote_retrieve_response_code($diag_response);
$diag_body = wp_remote_retrieve_body($diag_response);
echo "       [DIAG] POST /contacts → HTTP $diag_code\n";
echo "       [DIAG] Body enviado: $diag_payload\n";
echo "       [DIAG] Respuesta: $diag_body\n";

try {
    // Intento 1: nombre + tipo array + email (formato correcto Alegra API v1)
    $contact = $alegra->create_contact([
        'name'  => 'QA Test LTMS ' . date('His'),
        'type'  => ['client'],
        'email' => 'qa-ltms-' . date('His') . '@test.lo-tengo.com.co',
    ]);
    if ( ! empty( $contact['id'] ) ) {
        $test_contact_id = (int) $contact['id'];
        qa_ok( $qa, 'create_contact()', "ID=$test_contact_id | nombre=" . ( $contact['name'] ?? '?' ) );
    } else {
        // Intento 2: Con identification pero sin email
        $contact2 = $alegra->create_contact([
            'name'           => 'QA LTMS ' . date('His'),
            'identification' => $test_identification,
        ]);
        if ( ! empty( $contact2['id'] ) ) {
            $test_contact_id = (int) $contact2['id'];
            qa_ok( $qa, 'create_contact()', "ID=$test_contact_id" );
        } else {
            qa_fail( $qa, 'create_contact()', 'Sin ID. Respuesta: ' . wp_json_encode( $contact2 ) );
        }
    }
} catch ( Throwable $e ) {
    // Intento 2: Con identification numérica, sin email
    echo "       Primer intento falló: " . $e->getMessage() . "\n";
    echo "       Reintentando sin email...\n";
    try {
        $contact2 = $alegra->create_contact([
            'name'           => 'QA LTMS ' . date('His'),
            'identification' => $test_identification,
        ]);
        if ( ! empty( $contact2['id'] ) ) {
            $test_contact_id = (int) $contact2['id'];
            qa_ok( $qa, 'create_contact() (sin email)', "ID=$test_contact_id" );
        } else {
            qa_fail( $qa, 'create_contact() payload mínimo', wp_json_encode( $contact2 ) );
        }
    } catch ( Throwable $e2 ) {
        qa_fail( $qa, 'create_contact()', $e2->getMessage() );
    }
}

if ( $test_contact_id ) {
    try {
        $found = $alegra->find_contact_by_identification( $test_identification );
        if ( $found && (int)($found['id']??0) === $test_contact_id ) {
            qa_ok( $qa, 'find_contact_by_identification()', "ID=$test_contact_id encontrado" );
        } else {
            qa_warn( $qa, 'find_contact_by_identification()', 'No encontrado (paginación limitada a 1ª página)' );
        }
    } catch ( Throwable $e ) {
        qa_fail( $qa, 'find_contact_by_identification()', $e->getMessage() );
    }

    try {
        $same = $alegra->get_or_create_contact([
            'name'           => 'QA LTMS',
            'identification' => $test_identification,
        ]);
        if ( (int)($same['id']??0) === $test_contact_id ) {
            qa_ok( $qa, 'get_or_create_contact() idempotente', "Retornó ID=$test_contact_id existente" );
        } else {
            qa_warn( $qa, 'get_or_create_contact()', 'Creó duplicado (ID=' . ($same['id']??'?') . ') en vez del existente' );
        }
    } catch ( Throwable $e ) {
        qa_fail( $qa, 'get_or_create_contact()', $e->getMessage() );
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
        'description' => 'Item QA LTMS — borrar',
    ]);
    if ( ! empty( $item['id'] ) ) {
        $test_item_id = (int) $item['id'];
        qa_ok( $qa, 'create_item()', "ID=$test_item_id | nombre=" . ( $item['name'] ?? '?' ) );
    } else {
        qa_fail( $qa, 'create_item()', wp_json_encode( $item ) );
    }
} catch ( Throwable $e ) {
    qa_fail( $qa, 'create_item()', $e->getMessage() );
}

if ( $test_item_id ) {
    try {
        $updated = $alegra->update_item( $test_item_id, [ 'price' => 175000 ] );
        // Alegra devuelve price como array de objetos: price[0]['price']
        $price_field = $updated['price'] ?? null;
        if ( is_array( $price_field ) ) {
            $new_price = (float)( $price_field[0]['price'] ?? 0 );
        } else {
            $new_price = (float)( $price_field ?? $updated['prices'][0]['price'] ?? 0 );
        }
        if ( $new_price >= 175000 ) {
            qa_ok( $qa, 'update_item() precio actualizado', number_format($new_price,0,',','.') . ' COP' );
        } else {
            qa_warn( $qa, 'update_item() precio en respuesta',
                "raw=" . wp_json_encode(array_intersect_key($updated, array_flip(['price','prices','id','name'])))
            );
        }
    } catch ( Throwable $e ) {
        qa_fail( $qa, 'update_item()', $e->getMessage() );
    }
}

// ── T-06: FACTURAS ─────────────────────────────────────────────────────────────
qa_section( 'T-06 · Creación y lectura de facturas' );
$test_invoice_id = null;

// Usar contacto de prueba si existe, sino buscar uno existente
if ( ! $test_contact_id ) {
    qa_warn( $qa, 'T-06: sin contacto de prueba', 'Buscando contacto existente en Alegra...' );
    try {
        $contacts_resp = $alegra->find_contact_by_identification( '' ); // lista todos
    } catch ( Throwable $e ) {}
}

if ( $test_contact_id && $test_item_id ) {
    try {
        $invoice_data = [
            'date'         => date( 'Y-m-d' ),
            'due_date'     => date( 'Y-m-d', strtotime( '+30 days' ) ),
            'client_id'    => $test_contact_id,
            'items'        => [[
                'alegra_id' => $test_item_id,
                'quantity'  => 2,
                'price'     => 150000,
                'name'      => 'QA Producto LTMS',
            ]],
            'observations' => 'Factura QA LTMS #' . date('His'),
        ];
        if ( $template_id ) {
            $invoice_data['number_template_id'] = $template_id;
        }

        $invoice = $alegra->create_invoice( $invoice_data );
        if ( ! empty( $invoice['id'] ) ) {
            $test_invoice_id = (int) $invoice['id'];
            $inv_num    = $invoice['numberTemplate']['fullNumber'] ?? '#' . $test_invoice_id;
            $inv_status = $invoice['status'] ?? '?';
            qa_ok( $qa, 'create_invoice()', "ID=$test_invoice_id | número=$inv_num | estado=$inv_status" );
        } else {
            qa_fail( $qa, 'create_invoice()', wp_json_encode( array_keys( $invoice ) ) );
        }
    } catch ( Throwable $e ) {
        qa_fail( $qa, 'create_invoice()', $e->getMessage() );
    }

    if ( $test_invoice_id ) {
        try {
            $fetched = $alegra->get_invoice( $test_invoice_id );
            if ( (int)($fetched['id']??0) === $test_invoice_id ) {
                $total = (float)( $fetched['total'] ?? 0 );
                qa_ok( $qa, 'get_invoice()', "total=" . number_format($total,0,',','.') . ' COP' );
                if ( $total >= 300000 ) {
                    qa_ok( $qa, 'Total factura correcto (2×150k)', number_format($total,0,',','.') . ' COP' );
                } else {
                    qa_warn( $qa, 'Total factura inesperado', "esperado ≥300.000, obtenido $total" );
                }
            } else {
                qa_fail( $qa, 'get_invoice()', 'ID no coincide' );
            }
        } catch ( Throwable $e ) {
            qa_fail( $qa, 'get_invoice()', $e->getMessage() );
        }

        try {
            $list     = $alegra->list_invoices( 0, 5 );
            $list_arr = $list['data'] ?? ( array_values( array_filter( $list, 'is_array' ) ) );
            $count    = count( $list_arr );
            if ( $count > 0 ) {
                qa_ok( $qa, 'list_invoices()', "$count facturas retornadas" );
            } else {
                qa_warn( $qa, 'list_invoices()', 'Sin datos — keys: ' . implode(',', array_keys($list)) );
            }
        } catch ( Throwable $e ) {
            qa_fail( $qa, 'list_invoices()', $e->getMessage() );
        }
    }
} else {
    qa_warn( $qa, 'T-06 omitido', 'Requiere contacto e item de prueba (T-04 y T-05 deben pasar)' );
}

// ── T-07: FACTURACIÓN DESDE PEDIDO WC ─────────────────────────────────────────
qa_section( 'T-07 · Facturación automática desde pedido WC' );
$orders = wc_get_orders([
    'status' => [ 'completed', 'processing' ],
    'limit'  => 5,
    'meta_query' => [[
        'key'     => '_ltms_alegra_invoice_id',
        'compare' => 'NOT EXISTS',
    ]],
]);

if ( $orders ) {
    $test_order = $orders[0];
    $oid = $test_order->get_id();
    echo "       Pedido #$oid | estado=" . $test_order->get_status() . " | total=" . number_format((float)$test_order->get_total(),0,',','.') . " | items=" . count($test_order->get_items()) . "\n";
    echo "       Billing: " . $test_order->get_billing_first_name() . ' ' . $test_order->get_billing_last_name() . " <" . $test_order->get_billing_email() . ">\n";

    try {
        $sync   = new LTMS_Alegra_Sync();
        $result = $sync->create_invoice_for_order( $test_order );
        if ( ! empty( $result['id'] ) ) {
            $inv_num = $result['numberTemplate']['fullNumber'] ?? '#' . $result['id'];
            qa_ok( $qa, 'create_invoice_for_order()', "Factura $inv_num | pedido #$oid" );
            qa_ok( $qa, 'Respuesta tiene id+status+numberTemplate', "id={$result['id']} status=" . ($result['status']??'?') );
            // NO guardamos el meta en el pedido real — solo prueba
            echo "       ⚠️  Factura creada en Alegra (ID={$result['id']}) — borrar si es de prueba\n";
        } else {
            qa_fail( $qa, 'create_invoice_for_order()', 'Sin ID en respuesta' );
        }
    } catch ( Throwable $e ) {
        qa_fail( $qa, 'create_invoice_for_order()', $e->getMessage() );
        // Diagnóstico extra: mostrar datos del cliente del pedido
        echo "       Diagnóstico — billing_email: " . $test_order->get_billing_email() . "\n";
        echo "       Diagnóstico — billing_id: " . $test_order->get_meta('_billing_identification') . "\n";
        echo "       Diagnóstico — customer_id: " . $test_order->get_customer_id() . "\n";
    }
} else {
    qa_warn( $qa, 'T-07 omitido', 'Sin pedidos completados/processing sin factura Alegra' );
}

// ── T-08: WEBHOOK HANDLER ─────────────────────────────────────────────────────
qa_section( 'T-08 · Webhook handler (simulado)' );

// Test 1: edit-invoice sin secret configurado → debe procesar
$mock1 = new WP_REST_Request( 'POST', '/ltms/v1/webhooks/alegra' );
$mock1->set_header( 'content-type', 'application/json' );
$mock1->set_body( wp_json_encode([
    'action' => 'edit-invoice',
    'data'   => [ 'id' => $test_invoice_id ?? 99999, 'status' => 'closed',
                  'numberTemplate' => [ 'fullNumber' => 'TEST-001' ] ],
]));
try {
    $r1 = LTMS_Alegra_Webhook_Handler::handle( $mock1 );
    if ( $r1->get_status() === 200 && ( $r1->get_data()['received'] ?? false ) ) {
        qa_ok( $qa, 'Webhook edit-invoice procesado', 'HTTP 200 received=true' );
    } else {
        qa_fail( $qa, 'Webhook edit-invoice', 'HTTP ' . $r1->get_status() . ' | ' . wp_json_encode($r1->get_data()) );
    }
} catch ( Throwable $e ) {
    qa_fail( $qa, 'Webhook edit-invoice', $e->getMessage() );
}

// Test 2: evento desconocido → 200 + processed=false
$mock2 = new WP_REST_Request( 'POST', '/ltms/v1/webhooks/alegra' );
$mock2->set_header( 'content-type', 'application/json' );
$mock2->set_body( wp_json_encode([ 'action' => 'new-bill', 'data' => [] ]) );
try {
    $r2 = LTMS_Alegra_Webhook_Handler::handle( $mock2 );
    $d2 = $r2->get_data();
    if ( $r2->get_status() === 200 && isset($d2['processed']) && $d2['processed'] === false ) {
        qa_ok( $qa, 'Webhook evento desconocido → 200 processed=false' );
    } else {
        qa_fail( $qa, 'Webhook evento desconocido', 'HTTP ' . $r2->get_status() . ' | ' . wp_json_encode($d2) );
    }
} catch ( Throwable $e ) {
    qa_fail( $qa, 'Webhook evento desconocido', $e->getMessage() );
}

// Test 3: token inválido → 401
update_option( 'ltms_alegra_webhook_secret', 'secret-qa-test-' . time() );
LTMS_Core_Config::flush_cache();
$mock3 = new WP_REST_Request( 'POST', '/ltms/v1/webhooks/alegra' );
$mock3->set_header( 'content-type', 'application/json' );
$mock3->set_body( wp_json_encode([ 'action' => 'edit-invoice', 'data' => ['id'=>1] ]) );
try {
    $r3 = LTMS_Alegra_Webhook_Handler::handle( $mock3 );
    if ( $r3->get_status() === 401 ) {
        qa_ok( $qa, 'Webhook token inválido → 401 Unauthorized', 'Seguridad OK' );
    } else {
        qa_fail( $qa, 'Webhook auth', 'Esperado 401, obtenido ' . $r3->get_status() );
    }
} catch ( Throwable $e ) {
    qa_fail( $qa, 'Webhook auth', $e->getMessage() );
}
delete_option( 'ltms_alegra_webhook_secret' );
LTMS_Core_Config::flush_cache();

// Test 4: payload sin event → 400
$mock4 = new WP_REST_Request( 'POST', '/ltms/v1/webhooks/alegra' );
$mock4->set_header( 'content-type', 'application/json' );
$mock4->set_body( wp_json_encode([ 'data' => ['id'=>1] ]) );
try {
    $r4 = LTMS_Alegra_Webhook_Handler::handle( $mock4 );
    if ( $r4->get_status() === 400 ) {
        qa_ok( $qa, 'Webhook sin event → 400 Bad Request' );
    } else {
        qa_warn( $qa, 'Webhook sin event', 'Esperado 400, obtenido ' . $r4->get_status() );
    }
} catch ( Throwable $e ) {
    qa_fail( $qa, 'Webhook sin event', $e->getMessage() );
}

// ── T-09: CONFIGURACIÓN ────────────────────────────────────────────────────────
qa_section( 'T-09 · Configuración guardada en BD' );
$cfg_checks = [
    'ltms_alegra_enabled'      => [ 'label' => 'Alegra activo',           'must_be' => 'yes' ],
    'ltms_alegra_email'        => [ 'label' => 'Email',                   'must_be' => null ],
    'ltms_alegra_token'        => [ 'label' => 'Token',                   'must_be' => null ],
    'ltms_alegra_numbering_id' => [ 'label' => 'ID Numeración',           'must_be' => null ],
    'ltms_alegra_auto_invoice' => [ 'label' => 'Facturación automática',  'must_be' => null ],
    'ltms_alegra_sandbox'      => [ 'label' => 'Modo sandbox',            'must_be' => null ],
];
foreach ( $cfg_checks as $key => $cfg ) {
    $val = get_option( $key, '' );
    if ( $key === 'ltms_alegra_token' ) {
        $display = $val ? '✓ ' . strlen($val) . ' chars' : '✗ vacío';
    } else {
        $display = $val ?: '(vacío)';
    }
    if ( $cfg['must_be'] && $val !== $cfg['must_be'] ) {
        qa_fail( $qa, $cfg['label'], "esperado='{$cfg['must_be']}' actual='$val'" );
    } elseif ( ! $val && $key !== 'ltms_alegra_auto_invoice' ) {
        qa_warn( $qa, $cfg['label'], 'vacío' );
    } else {
        qa_ok( $qa, $cfg['label'], $display );
    }
}

// Verificar hook registrado
if ( LTMS_Core_Config::get( 'ltms_alegra_enabled', 'no' ) === 'yes' ) {
    $has_hook = has_action( 'woocommerce_order_status_completed' );
    if ( $has_hook !== false ) {
        qa_ok( $qa, 'Hook woocommerce_order_status_completed registrado', "prioridad=$has_hook" );
    } else {
        qa_warn( $qa, 'Hook woocommerce_order_status_completed', 'No registrado — revisar si LTMS_Alegra_Sync::init() corrió' );
    }
}

// ── RESUMEN ────────────────────────────────────────────────────────────────────
echo "\n══════════════════════════════════════════════════\n";
echo "  RESUMEN QA — Alegra\n";
echo "══════════════════════════════════════════════════\n";
printf( "  ✅ PASS : %d\n", $qa['pass'] );
printf( "  ❌ FAIL : %d\n", $qa['fail'] );
printf( "  ⚠️  WARN : %d\n", $qa['warn'] );
printf( "  TOTAL  : %d pruebas\n\n", $qa['pass'] + $qa['fail'] + $qa['warn'] );

if ( $qa['fail'] === 0 ) {
    echo "  🎉 Sin fallos críticos.\n";
} else {
    echo "  🔴 {$qa['fail']} prueba(s) fallida(s):\n";
    foreach ( $qa['log'] as $entry ) {
        if ( $entry['r'] === 'FAIL' ) {
            echo "     · " . $entry['t'] . ( $entry['d'] ? " — " . $entry['d'] : '' ) . "\n";
        }
    }
}

echo "\n  Datos creados en Alegra (sandbox={$qa_sandbox}) — borrar si no son necesarios:\n";
$qa_sandbox = get_option('ltms_alegra_sandbox','no') === 'yes' ? 'sí' : 'no';
if ( isset($test_contact_id) && $test_contact_id ) echo "    · Contacto ID=$test_contact_id\n";
if ( isset($test_item_id)    && $test_item_id    ) echo "    · Item ID=$test_item_id\n";
if ( isset($test_invoice_id) && $test_invoice_id ) echo "    · Factura ID=$test_invoice_id\n";
echo "\n";
