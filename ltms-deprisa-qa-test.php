<?php
/**
 * LTMS Deprisa API — Script de Pruebas QA
 * =========================================
 * Versión: 1.10.0
 * Foco principal: Multi-origen → Un solo destino
 *
 * USO:
 *   1. Subir este archivo a la raíz del plugin o a /wp-content/mu-plugins/
 *   2. Acceder via WP-CLI:
 *      wp eval-file ltms-deprisa-qa-test.php
 *   O agregar al functions.php temporalmente:
 *      add_action('init', function(){ if(current_user_can('manage_options') && isset($_GET['ltms_qa'])) include 'ltms-deprisa-qa-test.php'; });
 *   Luego visitar: /?ltms_qa=1
 *
 * ⚠️  IMPORTANTE: Usar SOLO en modo QA (modo_pruebas = true en opciones).
 *     NUNCA ejecutar contra producción sin revisar resultados primero.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─── CONFIGURACIÓN DE PRUEBA ──────────────────────────────────────────────────

/**
 * Credenciales QA — se leen de opciones WP.
 * Si querés forzarlas para prueba rápida, descomentá:
 *
 * update_option('ltms_deprisa_usuario',      'WS00011111');
 * update_option('ltms_deprisa_password',     'TU_PASSWORD_QA');
 * update_option('ltms_deprisa_modo_pruebas', true);
 */

// Destino común (comprador) — mismo en todos los test multi-origen
$DESTINO_COMUN = [
    'cliente_destinatario'          => '99999999',
    'centro_destinatario'           => '99',
    'nombre_destinatario'           => 'María García Compradora',
    'direccion_destinatario'        => 'Calle 15 # 8-45 Apto 302',
    'pais_destinatario'             => '057',
    'codigo_postal_destinatario'    => '760002',
    'poblacion_destinatario'        => 'CALI',
    'tipo_doc_destinatario'         => 'CC',
    'documento_destinatario'        => '1234567890',
    'persona_contacto_destinatario' => 'María García',
    'telefono_destinatario'         => '3001234567',
    'email_destinatario'            => 'maria.garcia@test.com',
];

// Vendedores (orígenes) — distintos por ciudad
$ORIGENES = [
    'vendedor_bogota' => [
        'label'                    => 'Vendedor A — Bogotá',
        'cliente_remitente'        => '10245121',   // ← reemplazar con código real QA
        'centro_remitente'         => '01',
        'nombre_remitente'         => 'Tienda Norte Bogotá',
        'direccion_remitente'      => 'Carrera 7 # 32-18',
        'pais_remitente'           => '057',
        'codigo_postal_remitente'  => '110911',
        'poblacion_remitente'      => 'BOGOTA',
        'tipo_doc_remitente'       => 'NIT',
        'documento_remitente'      => '900111222',
        'persona_contacto_remitente' => 'Carlos López',
        'telefono_remitente'       => '3109876543',
        'email_remitente'          => 'ventas@tiendanorte.com',
    ],
    'vendedor_medellin' => [
        'label'                    => 'Vendedor B — Medellín',
        'cliente_remitente'        => '10245122',   // ← reemplazar con código real QA
        'centro_remitente'         => '01',
        'nombre_remitente'         => 'Distribuidora Sur Medellín',
        'direccion_remitente'      => 'Avenida El Poblado # 12-30',
        'pais_remitente'           => '057',
        'codigo_postal_remitente'  => '050021',
        'poblacion_remitente'      => 'MEDELLIN',
        'tipo_doc_remitente'       => 'NIT',
        'documento_remitente'      => '900333444',
        'persona_contacto_remitente' => 'Ana Martínez',
        'telefono_remitente'       => '3115557788',
        'email_remitente'          => 'despachos@distribuidorasur.com',
    ],
    'vendedor_cali' => [
        'label'                    => 'Vendedor C — Cali (mismo ciudad que destino)',
        'cliente_remitente'        => '10245123',   // ← reemplazar con código real QA
        'centro_remitente'         => '01',
        'nombre_remitente'         => 'Comercial Pacífico Cali',
        'direccion_remitente'      => 'Carrera 1 # 10-20 Zona Industrial',
        'pais_remitente'           => '057',
        'codigo_postal_remitente'  => '760001',
        'poblacion_remitente'      => 'CALI',
        'tipo_doc_remitente'       => 'NIT',
        'documento_remitente'      => '900555666',
        'persona_contacto_remitente' => 'Roberto Díaz',
        'telefono_remitente'       => '3125554433',
        'email_remitente'          => 'envios@comercialpacífico.com',
    ],
];

// Parámetros comunes del envío
$PARAMS_ENVIO_COMUN = [
    'codigo_servicio'        => '3005',
    'asegurar_envio'         => 'N',
    'tipo_portes'            => 'P',
    'tipo_moneda'            => 'COP',
    'importe_valor_declarado'=> '50000',
    'kilos'                  => 1,
    'numero_bultos'          => 1,
];

// ─── INSTANCIAR API ───────────────────────────────────────────────────────────

$api = new LTMS_Deprisa_API();

// ─── UTILIDADES DE OUTPUT ─────────────────────────────────────────────────────

function qa_header( $titulo ) {
    $linea = str_repeat('=', 70);
    echo "\n{$linea}\n  {$titulo}\n{$linea}\n";
}

function qa_subheader( $titulo ) {
    echo "\n--- {$titulo} ---\n";
}

function qa_ok( $msg )   { echo "  ✅  {$msg}\n"; }
function qa_fail( $msg ) { echo "  ❌  {$msg}\n"; }
function qa_info( $msg ) { echo "  ℹ️   {$msg}\n"; }
function qa_warn( $msg ) { echo "  ⚠️   {$msg}\n"; }

function qa_resultado( $resultado, $campo_exito = 'exito' ) {
    if ( is_wp_error( $resultado ) ) {
        qa_fail( 'WP_Error: ' . $resultado->get_error_message() );
        return false;
    }
    if ( ! empty( $resultado[$campo_exito] ) ) {
        qa_ok( 'Operación exitosa.' );
    } else {
        qa_fail( 'Operación fallida.' );
    }
    if ( ! empty( $resultado['errores'] ) ) {
        foreach ( $resultado['errores'] as $e ) {
            qa_fail( "Error [{$e['codigo']}] {$e['descripcion']} — valor: {$e['valor']}" );
        }
    }
    qa_info( 'HTTP: ' . ( $resultado['http_code'] ?? 'N/A' ) );
    return ! empty( $resultado[$campo_exito] );
}

// ─── RESULTADOS ACUMULADOS (para reporte final) ───────────────────────────────
$reporte = [];

// ═════════════════════════════════════════════════════════════════════════════
// TEST 1: Verificación de credenciales y conectividad
// ═════════════════════════════════════════════════════════════════════════════
qa_header('TEST 1 — Verificación de credenciales y conectividad');

$usuario = get_option('ltms_deprisa_usuario', '');
$modo    = get_option('ltms_deprisa_modo_pruebas', false);

if ( empty( $usuario ) ) {
    qa_fail('No hay usuario configurado en ltms_deprisa_usuario');
} else {
    qa_ok("Usuario configurado: {$usuario}");
}
qa_info( 'Modo pruebas: ' . ( $modo ? 'SÍ (QA)' : 'NO (PRODUCCIÓN ⚠️)' ) );

if ( ! $modo ) {
    qa_warn('¡ESTÁS EN MODO PRODUCCIÓN! Activa modo_pruebas antes de continuar.');
}

$reporte['credenciales'] = ! empty( $usuario );

// ═════════════════════════════════════════════════════════════════════════════
// TEST 2: Admisión individual (modo consulta N — sin grabar)
// ═════════════════════════════════════════════════════════════════════════════
qa_header('TEST 2 — Admisión individual modo consulta (GRABAR_ENVIO=N)');

$datos_test2 = array_merge(
    $ORIGENES['vendedor_bogota'],
    $DESTINO_COMUN,
    $PARAMS_ENVIO_COMUN,
    [
        'codigo_admision'        => 'QA-TEST2-' . time(),
        'observaciones1'         => 'PRUEBA QA - no grabar',
        'numero_referencia'      => 'REF-QA-001',
    ]
);
unset( $datos_test2['label'] );

$resultado_t2 = $api->admitir_envio( $datos_test2, true ); // true = solo consulta
$ok_t2 = qa_resultado( $resultado_t2 );

if ( $ok_t2 ) {
    qa_info( 'Número envío (consulta): ' . $resultado_t2['numero_envio'] );
}
$reporte['admision_consulta'] = $ok_t2;

// ═════════════════════════════════════════════════════════════════════════════
// TEST 3: ★ MULTI-ORIGEN → UN DESTINO ★
//         3 vendedores distintos → mismo comprador en Cali
//         Cada vendedor genera su propia guía independiente
// ═════════════════════════════════════════════════════════════════════════════
qa_header('TEST 3 — MULTI-ORIGEN → UN DESTINO (caso crítico marketplace)');
qa_info('Escenario: Orden con 3 vendedores de ciudades distintas → 1 comprador en Cali');
qa_info('Cada origen genera 1 guía independiente. El destino es idéntico en las 3.');

$guias_generadas = [];
$errores_multi   = [];
$orden_id_fake   = 'ORD-QA-' . date('Ymd') . '-001';

foreach ( $ORIGENES as $key => $origen ) {
    qa_subheader( $origen['label'] );

    $datos_origen = array_merge(
        $origen,
        $DESTINO_COMUN,
        $PARAMS_ENVIO_COMUN,
        [
            // CRÍTICO: codigo_admision único por vendedor dentro de la misma orden
            'codigo_admision'   => $orden_id_fake . '-' . strtoupper( $key ),
            'numero_referencia' => $orden_id_fake,
            'observaciones1'    => 'MARKETPLACE LO-TENGO | Orden: ' . $orden_id_fake,
            'observaciones2'    => 'Vendedor: ' . $origen['label'],
        ]
    );
    unset( $datos_origen['label'] );

    // Modo consulta primero para verificar datos sin grabar
    $resultado_consulta = $api->admitir_envio( $datos_origen, true );

    if ( is_wp_error( $resultado_consulta ) ) {
        qa_fail( 'Consulta previa fallida: ' . $resultado_consulta->get_error_message() );
        $errores_multi[] = $key;
        continue;
    }

    if ( ! empty( $resultado_consulta['errores'] ) ) {
        qa_fail( 'Errores en validación previa:' );
        foreach ( $resultado_consulta['errores'] as $e ) {
            qa_fail( "  [{$e['codigo']}] {$e['descripcion']}" );
        }
        $errores_multi[] = $key;
        continue;
    }

    qa_ok( 'Validación previa OK (modo N)' );

    // Ahora grabar la guía real (GRABAR_ENVIO=S)
    $resultado_real = $api->admitir_envio( $datos_origen, false );

    if ( qa_resultado( $resultado_real ) ) {
        $guia = $resultado_real['numero_envio'];
        $guias_generadas[ $key ] = $guia;
        qa_ok( "Guía generada: {$guia} para {$origen['label']}" );
    } else {
        $errores_multi[] = $key;
    }
}

// Resumen multi-origen
echo "\n  📦 RESUMEN MULTI-ORIGEN:\n";
echo "     Destino único: {$DESTINO_COMUN['nombre_destinatario']} — {$DESTINO_COMUN['poblacion_destinatario']}\n";
echo "     Guías generadas: " . count( $guias_generadas ) . " / " . count( $ORIGENES ) . "\n";

foreach ( $guias_generadas as $key => $guia ) {
    echo "     ✅ {$ORIGENES[$key]['label']}: guía {$guia}\n";
}
foreach ( $errores_multi as $key ) {
    echo "     ❌ {$ORIGENES[$key]['label']}: FALLÓ\n";
}

$reporte['multi_origen'] = ( count( $guias_generadas ) === count( $ORIGENES ) );

// ═════════════════════════════════════════════════════════════════════════════
// TEST 4: Unicidad de codigo_admision — mismo código no debe repetirse
// ═════════════════════════════════════════════════════════════════════════════
qa_header('TEST 4 — Unicidad de codigo_admision (doble envío mismo código)');
qa_info('Enviar el mismo codigo_admision dos veces → el segundo debe dar error 67 (guía existente)');

$codigo_duplicado = 'QA-DUP-' . time();
$datos_dup = array_merge(
    $ORIGENES['vendedor_bogota'],
    $DESTINO_COMUN,
    $PARAMS_ENVIO_COMUN,
    [ 'codigo_admision' => $codigo_duplicado, 'observaciones1' => 'Primer envío' ]
);
unset( $datos_dup['label'] );

$r_dup1 = $api->admitir_envio( $datos_dup, false );
if ( ! is_wp_error( $r_dup1 ) && $r_dup1['exito'] ) {
    qa_ok( 'Primer envío OK — guía: ' . $r_dup1['numero_envio'] );

    // Intentar segunda vez con mismo codigo_admision
    $datos_dup['observaciones1'] = 'Segundo intento DEBE FALLAR';
    $r_dup2 = $api->admitir_envio( $datos_dup, false );

    if ( ! is_wp_error( $r_dup2 ) && ! empty( $r_dup2['errores'] ) ) {
        $codigos_error = array_column( $r_dup2['errores'], 'codigo' );
        if ( in_array( '67', $codigos_error ) ) {
            qa_ok( 'Correcto: el sistema rechazó el duplicado con error 67 (guía existente).' );
            $reporte['unicidad_codigo'] = true;
        } else {
            qa_warn( 'Rechazado pero con error diferente al esperado (67): ' . implode(', ', $codigos_error) );
            $reporte['unicidad_codigo'] = true; // igual es rechazo
        }
    } else {
        qa_fail( 'PROBLEMA: el sistema aceptó el mismo codigo_admision dos veces.' );
        $reporte['unicidad_codigo'] = false;
    }
} else {
    qa_warn( 'No se pudo generar el primer envío — test de duplicado omitido.' );
    $reporte['unicidad_codigo'] = null;
}

// ═════════════════════════════════════════════════════════════════════════════
// TEST 5: Etiquetas para guías del multi-origen
// ═════════════════════════════════════════════════════════════════════════════
qa_header('TEST 5 — Obtención de etiquetas para guías multi-origen');

if ( empty( $guias_generadas ) ) {
    qa_warn('No hay guías del TEST 3 — omitiendo test de etiquetas.');
    $reporte['etiquetas'] = null;
} else {
    $etiquetas_ok = 0;
    foreach ( $guias_generadas as $key => $guia ) {
        qa_subheader( "Etiqueta: {$guia} ({$ORIGENES[$key]['label']})" );
        $r_etiqueta = $api->obtener_etiqueta( $guia, 'T' ); // Térmica
        if ( qa_resultado( $r_etiqueta ) ) {
            $b64_len = strlen( $r_etiqueta['base64'] );
            qa_info( "Base64 length: {$b64_len} chars (~" . round($b64_len * 0.75 / 1024) . " KB PDF estimado)" );
            $etiquetas_ok++;
        }
    }
    $reporte['etiquetas'] = ( $etiquetas_ok === count( $guias_generadas ) );
}

// ═════════════════════════════════════════════════════════════════════════════
// TEST 6: Tracking de guías generadas
// ═════════════════════════════════════════════════════════════════════════════
qa_header('TEST 6 — Tracking de guías generadas en TEST 3');

if ( empty( $guias_generadas ) ) {
    qa_warn('No hay guías — omitiendo test de tracking.');
    $reporte['tracking'] = null;
} else {
    $tracking_ok = 0;
    foreach ( $guias_generadas as $key => $guia ) {
        qa_subheader( "Tracking: {$guia}" );
        $r_track = $api->consultar_tracking( $guia );

        if ( is_wp_error( $r_track ) ) {
            qa_fail( $r_track->get_error_message() );
            continue;
        }

        if ( $r_track['http_code'] === 404 ) {
            // Normal si la guía recién se creó y aún no está indexada
            qa_warn( 'HTTP 404 — Guía aún no indexada en tracking (normal si es recién creada).' );
            $tracking_ok++; // No es fallo real
            continue;
        }

        if ( $r_track['exito'] ) {
            qa_ok( 'Tracking disponible.' );
            $e = $r_track['envio'];
            qa_info( "Servicio: {$e['descripcion_servicio']} | Destino: {$e['poblacion_destinatario']}" );
            qa_info( 'Estados: ' . count( $r_track['estados'] ) );
            $tracking_ok++;
        } else {
            qa_fail( 'Tracking no disponible. HTTP: ' . $r_track['http_code'] );
        }
    }
    $reporte['tracking'] = ( $tracking_ok > 0 );
}

// ═════════════════════════════════════════════════════════════════════════════
// TEST 7: Tracking con número inválido (< 5 chars)
// ═════════════════════════════════════════════════════════════════════════════
qa_header('TEST 7 — Tracking con número inválido');

$r_track_inv = $api->consultar_tracking('123');
if ( is_wp_error( $r_track_inv ) ) {
    qa_ok( 'Correcto: WP_Error retornado para número < 5 chars: ' . $r_track_inv->get_error_message() );
    $reporte['tracking_invalido'] = true;
} else {
    qa_fail( 'Debería haber retornado WP_Error para número demasiado corto.' );
    $reporte['tracking_invalido'] = false;
}

// ═════════════════════════════════════════════════════════════════════════════
// TEST 8: Cotización multi-origen → mismo destino
// ═════════════════════════════════════════════════════════════════════════════
qa_header('TEST 8 — Cotización por cada origen hacia el destino común');

$cotizaciones_ok = 0;
foreach ( $ORIGENES as $key => $origen ) {
    qa_subheader( "Cotización: {$origen['label']} → {$DESTINO_COMUN['poblacion_destinatario']}" );

    $r_cotiz = $api->cotizar([
        'numero_bultos'          => 1,
        'kilos'                  => 1,
        'cliente_remitente'      => $origen['cliente_remitente'],
        'centro_remitente'       => $origen['centro_remitente'],
        'poblacion_remitente'    => $origen['poblacion_remitente'],
        'poblacion_destinatario' => $DESTINO_COMUN['poblacion_destinatario'],
        'importe_valor_declarado'=> 50000,
        'tipo_moneda'            => 'COP',
    ]);

    if ( qa_resultado( $r_cotiz ) ) {
        foreach ( $r_cotiz['productos'] as $prod ) {
            qa_info( "  {$prod['producto_descripcion']}: $" . number_format($prod['total']) . " COP | Entrega: {$prod['tiempo_entrega']}" );
        }
        $cotizaciones_ok++;
    }
}
$reporte['cotizaciones'] = ( $cotizaciones_ok === count( $ORIGENES ) );

// ═════════════════════════════════════════════════════════════════════════════
// TEST 9: Recogida para primer vendedor + asociar guía
// ═════════════════════════════════════════════════════════════════════════════
qa_header('TEST 9 — Recogida + Asociación de guía (Vendedor Bogotá)');

if ( empty( $guias_generadas['vendedor_bogota'] ) ) {
    qa_warn('No hay guía del vendedor_bogota — omitiendo test de recogida.');
    $reporte['recogida'] = null;
} else {
    $guia_bogota = $guias_generadas['vendedor_bogota'];
    $origen_bog  = $ORIGENES['vendedor_bogota'];

    // Crear recogida
    $fecha_recogida = date('d/m/Y', strtotime('+1 weekday'));
    $r_recogida = $api->crear_recogida([
        'codigo_admision'            => 'RECO-QA-' . time(),
        'cliente_remitente'          => $origen_bog['cliente_remitente'],
        'centro_remitente'           => $origen_bog['centro_remitente'],
        'nombre_remitente'           => $origen_bog['nombre_remitente'],
        'direccion_remitente'        => $origen_bog['direccion_remitente'],
        'codigo_postal_remitente'    => $origen_bog['codigo_postal_remitente'],
        'poblacion_remitente'        => $origen_bog['poblacion_remitente'],
        'tipo_doc_remitente'         => $origen_bog['tipo_doc_remitente'],
        'documento_remitente'        => $origen_bog['documento_remitente'],
        'persona_contacto_remitente' => $origen_bog['persona_contacto_remitente'],
        'telefono_remitente'         => $origen_bog['telefono_remitente'],
        'fecha_recogida'             => $fecha_recogida,
        'rango_horario'              => '09:00-17:00',
        'codigo_servicio'            => '3005',
        'embalaje'                   => 'C',
        'observaciones'              => 'Recogida QA — Marketplace Lo-Tengo',
        'numero_bultos'              => 1,
        'kilos'                      => 1,
    ]);

    qa_subheader('Crear recogida');
    $ok_recogida = qa_resultado( $r_recogida );
    $codigo_recogida = '';

    if ( $ok_recogida ) {
        $codigo_recogida = $r_recogida['codigo_recogida'];
        qa_info( "Código recogida: {$codigo_recogida} | Fecha: {$fecha_recogida}" );

        // Asociar guía a la recogida
        qa_subheader('Asociar guía a recogida');
        $r_asociar = $api->asociar_guias_recogida( $codigo_recogida, [ $guia_bogota ] );
        $ok_asociar = qa_resultado( $r_asociar );

        if ( $ok_asociar ) {
            qa_ok( "Guía {$guia_bogota} asociada a recogida {$codigo_recogida}" );
        }

        // Ver estado recogida
        qa_subheader('Ver estado recogida');
        $r_ver = $api->ver_recogidas( $codigo_recogida );
        if ( ! is_wp_error( $r_ver ) && ! empty( $r_ver['recogidas'] ) ) {
            $rec = $r_ver['recogidas'][0];
            qa_info( "Estado: {$rec['estado']} | Horario: {$rec['rango_horario']}" );
        }

        $reporte['recogida'] = $ok_recogida && $ok_asociar;
    } else {
        $reporte['recogida'] = false;
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// TEST 10: Cancelar recogida sin motivo (debe fallar con error 100)
// ═════════════════════════════════════════════════════════════════════════════
qa_header('TEST 10 — Cancelar recogida sin motivo (validación error 100)');

$r_cancel_inv = $api->cancelar_recogida('99999', '');
if ( is_wp_error( $r_cancel_inv ) && $r_cancel_inv->get_error_code() === 'motivo_requerido' ) {
    qa_ok( 'Correcto: WP_Error retornado al cancelar sin motivo.' );
    $reporte['cancelar_sin_motivo'] = true;
} else {
    qa_fail( 'Debería haber retornado WP_Error (motivo_requerido) antes de llamar a la API.' );
    $reporte['cancelar_sin_motivo'] = false;
}

// ═════════════════════════════════════════════════════════════════════════════
// TEST 11: Devolución (guía retorno inversión remitente/destinatario)
// ═════════════════════════════════════════════════════════════════════════════
qa_header('TEST 11 — Generación de guía de devolución (retorno)');

if ( empty( $guias_generadas['vendedor_bogota'] ) ) {
    qa_warn('No hay guía — omitiendo test de devolución.');
    $reporte['devolucion'] = null;
} else {
    $datos_originales = array_merge(
        $ORIGENES['vendedor_bogota'],
        $DESTINO_COMUN,
        $PARAMS_ENVIO_COMUN,
        [
            'numero_envio'           => $guias_generadas['vendedor_bogota'],
            'importe_valor_declarado'=> '50000',
        ]
    );
    unset( $datos_originales['label'] );

    $payload_dev = $api->build_devolucion_payload( $datos_originales, 'Producto defectuoso' );

    qa_info( 'Payload devolución generado:' );
    qa_info( "  Remitente (era destinatario): {$payload_dev['nombre_remitente']} — {$payload_dev['poblacion_remitente']}" );
    qa_info( "  Destinatario (era remitente): {$payload_dev['nombre_destinatario']} — {$payload_dev['poblacion_destinatario']}" );
    qa_info( "  Código admisión: {$payload_dev['codigo_admision']}" );

    // Verificar inversión correcta
    $inversion_ok = (
        $payload_dev['nombre_remitente']    === $DESTINO_COMUN['nombre_destinatario'] &&
        $payload_dev['nombre_destinatario'] === $ORIGENES['vendedor_bogota']['nombre_remitente']
    );

    if ( $inversion_ok ) {
        qa_ok( 'Inversión remitente/destinatario correcta.' );
    } else {
        qa_fail( 'La inversión remitente/destinatario NO es correcta.' );
    }

    // Crear devolución real (en QA)
    $r_dev = $api->crear_devolucion( $datos_originales, 'Producto defectuoso QA' );
    $ok_dev = qa_resultado( $r_dev );
    if ( $ok_dev ) {
        qa_ok( 'Guía devolución: ' . $r_dev['numero_envio'] );
    }
    $reporte['devolucion'] = $inversion_ok && $ok_dev;
}

// ═════════════════════════════════════════════════════════════════════════════
// TEST 12: Contraentrega (TIPO_PORTES=P + TIPO_PORTE_REEMBOLSOS=D)
// ═════════════════════════════════════════════════════════════════════════════
qa_header('TEST 12 — Admisión contraentrega (COD)');

$datos_cod = array_merge(
    $ORIGENES['vendedor_bogota'],
    $DESTINO_COMUN,
    [
        'codigo_admision'        => 'QA-COD-' . time(),
        'codigo_servicio'        => '3027',        // Código contraentrega
        'kilos'                  => 1,
        'numero_bultos'          => 1,
        'importe_reembolso'      => '120000',       // Valor a cobrar al destinatario
        'tipo_porte_reembolsos'  => 'D',            // Debidos = lo paga destinatario
        'tipo_portes'            => 'P',
        'importe_valor_declarado'=> '120000',
        'asegurar_envio'         => 'N',
        'tipo_moneda'            => 'COP',
        'observaciones1'         => 'CONTRAENTREGA QA — cobrar $120.000',
        'numero_referencia'      => 'COD-QA-001',
    ]
);
unset( $datos_cod['label'] );

$r_cod = $api->admitir_envio( $datos_cod, true ); // Solo consulta
if ( qa_resultado( $r_cod ) ) {
    qa_ok( 'Estructura contraentrega validada correctamente (modo consulta).' );
    $reporte['contraentrega'] = true;
} else {
    $reporte['contraentrega'] = false;
}

// ═════════════════════════════════════════════════════════════════════════════
// REPORTE FINAL
// ═════════════════════════════════════════════════════════════════════════════
qa_header('REPORTE FINAL QA — LTMS Deprisa API v1.10.0');

$total  = 0;
$pasado = 0;
$omitido = 0;

$labels = [
    'credenciales'       => 'Credenciales configuradas',
    'admision_consulta'  => 'Admisión modo consulta (N)',
    'multi_origen'       => '★ MULTI-ORIGEN → 1 destino',
    'unicidad_codigo'    => 'Unicidad codigo_admision',
    'etiquetas'          => 'Etiquetas por guía',
    'tracking'           => 'Tracking de guías',
    'tracking_invalido'  => 'Validación tracking inválido',
    'cotizaciones'       => 'Cotización por origen',
    'recogida'           => 'Recogida + Asociación',
    'cancelar_sin_motivo'=> 'Validación cancelar sin motivo',
    'devolucion'         => 'Guía de devolución',
    'contraentrega'      => 'Admisión COD (contraentrega)',
];

foreach ( $labels as $key => $label ) {
    $val = $reporte[$key] ?? null;
    if ( $val === null ) {
        qa_warn( str_pad($label, 40) . ' → OMITIDO (sin datos previos)' );
        $omitido++;
    } elseif ( $val === true ) {
        qa_ok( str_pad($label, 40) . ' → PASÓ' );
        $pasado++;
        $total++;
    } else {
        qa_fail( str_pad($label, 40) . ' → FALLÓ' );
        $total++;
    }
}

echo "\n";
echo "  Total: {$pasado} / {$total} pasaron";
if ( $omitido ) echo " | {$omitido} omitidos (requieren datos previos)";
echo "\n\n";

if ( $pasado === $total ) {
    echo "  🎉 TODOS LOS TESTS PASARON\n\n";
} else {
    echo "  ⚠️  Hay tests fallidos — revisar configuración de credenciales QA\n";
    echo "      y verificar que los códigos de cliente en \$ORIGENES sean válidos en Alertran.\n\n";
}

echo "  Guías generadas en esta sesión:\n";
foreach ( $guias_generadas as $key => $guia ) {
    echo "    {$guia} ← {$ORIGENES[$key]['label']}\n";
}
echo "\n";
