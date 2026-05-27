<?php
/**
 * QA Runner — Heka Entrega
 *
 * Cubre los 3 servicios documentados en Api_documentación_HEKAENTREGA.pdf:
 *   1. Autenticación  (POST /api/v1/user/login)
 *   2. Cotización     (POST /api/v1/shipping/quoter)
 *   3. Guía de envío  (POST /api/v1/shipments/guide)
 *
 * Ejecutar desde el servidor:
 *   wp --path=/home/customer/www/lo-tengo.com.co/public_html \
 *      eval-file bin/ltms-qa-heka.php --allow-root 2>/dev/null
 *
 * El token JWT se captura en T-01 y se reutiliza en los tests siguientes.
 * T-09 (creación de guía real) está DESACTIVADO por defecto para evitar
 * generar guías reales en producción; cambiar $run_guide_creation = true para activarlo.
 *
 * @package    LTMS
 * @subpackage LTMS/bin
 * @version    1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ═══════════════════════════════════════════════════════════════════
// CONFIGURACIÓN
// ═══════════════════════════════════════════════════════════════════

/** Cambiar a true para ejecutar T-09 (crea guía real en Heka). */
$run_guide_creation = false;

// Credenciales de Heka leídas desde opciones de WP.
$heka_api_key   = (string) get_option( 'ltms_heka_api_key', '' );
$heka_email     = (string) get_option( 'ltms_heka_email', '' );
$heka_password  = (string) get_option( 'ltms_heka_password', '' );
$heka_channel   = (string) get_option( 'ltms_heka_channel', 'web' );
$heka_dist_id   = (string) get_option( 'ltms_heka_account_id', '' );
$heka_base_url  = 'https://api.hekaentrega.co';

// ═══════════════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════════════

$qa = [ 'pass' => 0, 'fail' => 0, 'warn' => 0, 'items' => [] ];

function qa_ok( array &$qa, string $label, string $detail = '' ): void {
    $qa['pass']++;
    $qa['items'][] = [ 'status' => 'PASS', 'label' => $label, 'detail' => $detail ];
    echo "  ✅ PASS  $label" . ( $detail ? " — $detail" : '' ) . "\n";
}

function qa_fail( array &$qa, string $label, string $detail = '' ): void {
    $qa['fail']++;
    $qa['items'][] = [ 'status' => 'FAIL', 'label' => $label, 'detail' => $detail ];
    echo "  ❌ FAIL  $label" . ( $detail ? " — $detail" : '' ) . "\n";
}

function qa_warn( array &$qa, string $label, string $detail = '' ): void {
    $qa['warn']++;
    $qa['items'][] = [ 'status' => 'WARN', 'label' => $label, 'detail' => $detail ];
    echo "  ⚠️  WARN  $label" . ( $detail ? " — $detail" : '' ) . "\n";
}

/**
 * Realiza un POST a la API de Heka con wp_remote_post().
 *
 * @return array{code: int, body: array, raw: string}
 */
function heka_post( string $base, string $endpoint, array $payload, array $extra_headers = [] ): array {
    $headers = array_merge(
        [ 'Content-Type' => 'application/json' ],
        $extra_headers
    );

    $response = wp_remote_post(
        $base . $endpoint,
        [
            'headers' => $headers,
            'body'    => wp_json_encode( $payload ),
            'timeout' => 30,
        ]
    );

    if ( is_wp_error( $response ) ) {
        return [ 'code' => 0, 'body' => [], 'raw' => $response->get_error_message() ];
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    $raw  = wp_remote_retrieve_body( $response );
    $body = json_decode( $raw, true ) ?? [];

    return [ 'code' => $code, 'body' => $body, 'raw' => $raw ];
}

// ═══════════════════════════════════════════════════════════════════
// BANNER
// ═══════════════════════════════════════════════════════════════════

echo "\n";
echo "══════════════════════════════════════════════════\n";
echo "  QA SUITE — Heka Entrega  v1.0.0\n";
echo "  " . date( 'Y-m-d H:i:s' ) . "\n";
echo "══════════════════════════════════════════════════\n\n";

// ═══════════════════════════════════════════════════════════════════
// PRE-CHECK: credenciales configuradas
// ═══════════════════════════════════════════════════════════════════

echo "── PRE-CHECK: Configuración ──────────────────────\n";

if ( empty( $heka_api_key ) ) {
    qa_fail( $qa, 'ltms_heka_api_key configurada', 'Opción vacía — ir a LTMS → Configuración → Heka Entrega' );
} else {
    qa_ok( $qa, 'ltms_heka_api_key configurada', substr( $heka_api_key, 0, 6 ) . '***' );
}

if ( empty( $heka_email ) ) {
    qa_fail( $qa, 'ltms_heka_email configurado', 'Opción vacía' );
} else {
    qa_ok( $qa, 'ltms_heka_email configurado', $heka_email );
}

if ( empty( $heka_password ) ) {
    qa_fail( $qa, 'ltms_heka_password configurado', 'Opción vacía' );
} else {
    qa_ok( $qa, 'ltms_heka_password configurado', '***' );
}

if ( $qa['fail'] > 0 ) {
    echo "\n  ⛔ Credenciales incompletas — configura las opciones antes de continuar.\n\n";
    goto print_summary;
}

echo "\n";

// ═══════════════════════════════════════════════════════════════════
// SECCIÓN 1 — AUTENTICACIÓN
// ═══════════════════════════════════════════════════════════════════

echo "── SECCIÓN 1: Autenticación ──────────────────────\n";

// T-01 — Login exitoso
echo "\n[T-01] Login exitoso + captura de token\n";
$t01 = heka_post( $heka_base_url, '/api/v1/user/login',
    [ 'email' => $heka_email, 'password' => $heka_password, 'channel' => $heka_channel ],
    [ 'api-key' => $heka_api_key ]
);

$jwt_token = '';

if ( $t01['code'] === 200 ) {
    qa_ok( $qa, 'T-01 HTTP 200', "code={$t01['code']}" );
} else {
    qa_fail( $qa, 'T-01 HTTP 200', "Recibido {$t01['code']} — raw: " . substr( $t01['raw'], 0, 200 ) );
}

$t01_token = $t01['body']['response']['token'] ?? '';
if ( ! empty( $t01_token ) ) {
    $jwt_token = $t01_token;
    qa_ok( $qa, 'T-01 token presente en response', substr( $jwt_token, 0, 20 ) . '…' );
} else {
    qa_fail( $qa, 'T-01 token presente en response', 'Campo response.token vacío' );
}

// T-02 — Contrato de respuesta
echo "\n[T-02] Contrato de respuesta login\n";
$t02_resp = $t01['body']['response'] ?? [];
foreach ( [ 'userId', 'name', 'email', 'token' ] as $field ) {
    if ( isset( $t02_resp[ $field ] ) ) {
        qa_ok( $qa, "T-02 campo response.$field presente", (string) substr( (string) $t02_resp[$field], 0, 40 ) );
    } else {
        qa_fail( $qa, "T-02 campo response.$field presente", 'Ausente en la respuesta' );
    }
}

// T-03 — Credenciales incorrectas deben ser rechazadas
echo "\n[T-03] Credenciales incorrectas → rechazo\n";
$t03 = heka_post( $heka_base_url, '/api/v1/user/login',
    [ 'email' => 'invalid@test.com', 'password' => 'wrongpass999', 'channel' => $heka_channel ],
    [ 'api-key' => $heka_api_key ]
);
if ( $t03['code'] !== 200 ) {
    qa_ok( $qa, 'T-03 credenciales inválidas rechazadas', "HTTP {$t03['code']}" );
} else {
    qa_fail( $qa, 'T-03 credenciales inválidas rechazadas', 'La API devolvió 200 con credenciales incorrectas' );
}

// T-04 — Sin header api-key debe rechazar
echo "\n[T-04] Sin header api-key → rechazo\n";
$t04 = heka_post( $heka_base_url, '/api/v1/user/login',
    [ 'email' => $heka_email, 'password' => $heka_password, 'channel' => $heka_channel ]
    // Sin api-key header
);
if ( $t04['code'] !== 200 ) {
    qa_ok( $qa, 'T-04 sin api-key rechazado', "HTTP {$t04['code']}" );
} else {
    qa_warn( $qa, 'T-04 sin api-key rechazado', 'La API devolvió 200 sin api-key — revisar configuración de seguridad en Heka' );
}

// T-05 — Body vacío debe fallar con validación
echo "\n[T-05] Body vacío → error de validación\n";
$t05 = heka_post( $heka_base_url, '/api/v1/user/login',
    [],
    [ 'api-key' => $heka_api_key ]
);
if ( $t05['code'] >= 400 ) {
    qa_ok( $qa, 'T-05 body vacío rechazado', "HTTP {$t05['code']}" );
} else {
    qa_fail( $qa, 'T-05 body vacío rechazado', "HTTP {$t05['code']} — se esperaba 4xx" );
}

echo "\n";

// Si no tenemos token, los siguientes tests no pueden ejecutarse.
if ( empty( $jwt_token ) ) {
    qa_fail( $qa, 'Token disponible para secciones 2 y 3', 'T-01 no capturó token — saltando el resto' );
    goto print_summary;
}

// ═══════════════════════════════════════════════════════════════════
// SECCIÓN 2 — COTIZACIÓN
// ═══════════════════════════════════════════════════════════════════

echo "── SECCIÓN 2: Cotización de Envío ────────────────\n";

// T-06 — Cotización válida Cali → Bogotá
echo "\n[T-06] Cotización válida Cali (76001) → Bogotá (11001)\n";
$t06_payload = [
    'city_origin'       => '76001',   // Cali - código DANE
    'city_destination'  => '11001',   // Bogotá - código DANE
    'type_payment'      => 1,         // Contraentrega
    'declared_value'    => 100000,
    'weight'            => 1,
    'height'            => 10,
    'long'              => 15,
    'width'             => 10,
    'collection_value'  => 100000,
    'withshipping_cost' => false,
];
$t06 = heka_post( $heka_base_url, '/api/v1/shipping/quoter',
    $t06_payload,
    [ 'Authorization' => 'Bearer ' . $jwt_token ]
);

if ( $t06['code'] === 200 ) {
    qa_ok( $qa, 'T-06 HTTP 200 cotización', "code={$t06['code']}" );
} else {
    qa_fail( $qa, 'T-06 HTTP 200 cotización', "HTTP {$t06['code']} — " . substr( $t06['raw'], 0, 200 ) );
}

$t06_quotes = $t06['body']['response'] ?? [];
if ( is_array( $t06_quotes ) && count( $t06_quotes ) > 0 ) {
    qa_ok( $qa, 'T-06 respuesta tiene cotizaciones', count( $t06_quotes ) . ' transportadora(s)' );

    // Verificar estructura de la primera cotización
    $first = $t06_quotes[0];
    foreach ( [ 'total' ] as $field ) {
        if ( isset( $first[ $field ] ) ) {
            qa_ok( $qa, "T-06 cotización tiene campo '$field'", (string) $first[$field] );
        } else {
            qa_warn( $qa, "T-06 cotización tiene campo '$field'", 'Ausente — verificar contrato de respuesta' );
        }
    }

    // Mostrar resumen de transportadoras
    echo "       Transportadoras disponibles:\n";
    foreach ( $t06_quotes as $q ) {
        $name  = $q['carrier'] ?? $q['name'] ?? 'N/A';
        $total = $q['total']   ?? '?';
        echo "         · $name — $" . number_format( (float) $total, 0, ',', '.' ) . " COP\n";
    }
} else {
    qa_warn( $qa, 'T-06 respuesta tiene cotizaciones', 'Array vacío o estructura inesperada — raw: ' . substr( $t06['raw'], 0, 300 ) );
}

// T-07 — Sin token → 401
echo "\n[T-07] Sin token Bearer → 401\n";
$t07 = heka_post( $heka_base_url, '/api/v1/shipping/quoter', $t06_payload );
if ( $t07['code'] === 401 ) {
    qa_ok( $qa, 'T-07 sin token rechazado con 401', "HTTP {$t07['code']}" );
} elseif ( $t07['code'] >= 400 ) {
    qa_warn( $qa, 'T-07 sin token rechazado', "HTTP {$t07['code']} (se esperaba 401 específicamente)" );
} else {
    qa_fail( $qa, 'T-07 sin token rechazado', "HTTP {$t07['code']} — la API no protegió el endpoint" );
}

// T-08 — Código DANE inválido → error descriptivo
echo "\n[T-08] Código DANE inválido → error\n";
$t08_payload = array_merge( $t06_payload, [ 'city_origin' => '99999', 'city_destination' => '88888' ] );
$t08 = heka_post( $heka_base_url, '/api/v1/shipping/quoter',
    $t08_payload,
    [ 'Authorization' => 'Bearer ' . $jwt_token ]
);
if ( $t08['code'] >= 400 ) {
    qa_ok( $qa, 'T-08 DANE inválido rechazado', "HTTP {$t08['code']}" );
} else {
    qa_warn( $qa, 'T-08 DANE inválido rechazado', "HTTP {$t08['code']} — cotizaciones devueltas: " . count( $t08['body']['response'] ?? [] ) );
}

echo "\n";

// ═══════════════════════════════════════════════════════════════════
// SECCIÓN 3 — GUÍA DE ENVÍO
// ═══════════════════════════════════════════════════════════════════

echo "── SECCIÓN 3: Guía de Envío ──────────────────────\n";

// T-09 — Sin token → 401
echo "\n[T-09] Sin token Bearer → 401\n";
$t09_base_payload = [
    'type'              => 1,
    'city_origin'       => '76001',
    'city_destination'  => '11001',
    'type_payment'      => 1,
    'total'             => 100000,
    'declared_value'    => 100000,
    'weight'            => 1,
    'height'            => 10,
    'long'              => 15,
    'width'             => 10,
    'withshipping_cost' => false,
    'collection_value'  => 100000,
    'distributor_id'    => $heka_dist_id ?: 'TEST-DIST-ID',
    'seller'            => 'QA Bot',
    'quantity'          => 1,
    'product'           => 'Producto de prueba QA',
    'extra_info'        => 'QA automatizado LTMS',
    'warehouse'         => 'Bodega principal',
    'name'              => 'Juan',
    'last_name'         => 'Pérez',
    'address'           => 'Cra 7 # 45-12',
    'phone'             => '3001234567',
    'type_document'     => 'CC',
    'document'          => '12345678',
    'neighborhood'      => 'Chapinero',
    'note_destination'  => 'Dejar en portería',
];

$t09 = heka_post( $heka_base_url, '/api/v1/shipments/guide', $t09_base_payload );
if ( $t09['code'] === 401 ) {
    qa_ok( $qa, 'T-09 sin token rechazado con 401', "HTTP {$t09['code']}" );
} elseif ( $t09['code'] >= 400 ) {
    qa_warn( $qa, 'T-09 sin token rechazado', "HTTP {$t09['code']} (se esperaba 401)" );
} else {
    qa_fail( $qa, 'T-09 sin token rechazado', "HTTP {$t09['code']} — endpoint no protegido" );
}

// T-10 — Campos faltantes → 400
echo "\n[T-10] Campos requeridos faltantes → 400\n";
$t10 = heka_post( $heka_base_url, '/api/v1/shipments/guide',
    [ 'city_origin' => '76001' ],  // payload incompleto
    [ 'Authorization' => 'Bearer ' . $jwt_token ]
);
if ( $t10['code'] >= 400 && $t10['code'] < 500 ) {
    qa_ok( $qa, 'T-10 campos faltantes rechazados', "HTTP {$t10['code']}" );
} elseif ( $t10['code'] >= 500 ) {
    qa_warn( $qa, 'T-10 campos faltantes rechazados', "HTTP {$t10['code']} — 500 en lugar de 400 (validación en servidor)" );
} else {
    qa_fail( $qa, 'T-10 campos faltantes rechazados', "HTTP {$t10['code']}" );
}

// T-11 — Creación real de guía (DESACTIVADA por defecto)
echo "\n[T-11] Creación real de guía de envío\n";
if ( ! $run_guide_creation ) {
    qa_warn( $qa, 'T-11 OMITIDO — \$run_guide_creation = false', 'Cambiar a true para ejecutar (crea guía real en producción)' );
} elseif ( empty( $heka_dist_id ) ) {
    qa_fail( $qa, 'T-11 distributor_id configurado', 'ltms_heka_account_id vacío — requerido para crear guías' );
} else {
    $t11 = heka_post( $heka_base_url, '/api/v1/shipments/guide',
        $t09_base_payload,
        [ 'Authorization' => 'Bearer ' . $jwt_token ]
    );

    if ( $t11['code'] === 200 ) {
        qa_ok( $qa, 'T-11 HTTP 200 guía creada', "code={$t11['code']}" );
    } else {
        qa_fail( $qa, 'T-11 HTTP 200 guía creada', "HTTP {$t11['code']} — " . substr( $t11['raw'], 0, 300 ) );
    }

    // T-12 — Contrato de respuesta guía
    $t11_resp = $t11['body']['response'] ?? [];
    foreach ( [ 'shipment_id', 'guide_number', 'status' ] as $field ) {
        if ( ! empty( $t11_resp[ $field ] ) ) {
            qa_ok( $qa, "T-11 campo response.$field presente", (string) $t11_resp[$field] );
        } else {
            qa_fail( $qa, "T-11 campo response.$field presente", 'Ausente o vacío' );
        }
    }

    if ( ! empty( $t11_resp['guide_number'] ) ) {
        echo "  ⚠️  Guía creada en producción: {$t11_resp['guide_number']} — recuerda anularla si es de prueba\n";
    }
}

// T-12 — Verificar clase LTMS_Api_Heka existe y es instanciable
echo "\n[T-12] Clase LTMS_Api_Heka instanciable\n";
if ( class_exists( 'LTMS_Api_Heka' ) ) {
    qa_ok( $qa, 'T-12 LTMS_Api_Heka existe en autoloader', '' );
    try {
        $heka_instance = new LTMS_Api_Heka();
        qa_ok( $qa, 'T-12 LTMS_Api_Heka instanciada sin excepciones', '' );

        // Health check
        $health = $heka_instance->health_check();
        if ( ( $health['status'] ?? '' ) === 'ok' ) {
            qa_ok( $qa, 'T-12 health_check() OK', $health['message'] ?? '' );
        } else {
            qa_fail( $qa, 'T-12 health_check() OK', $health['message'] ?? 'status=' . ( $health['status'] ?? '?' ) );
        }
    } catch ( \Throwable $e ) {
        qa_fail( $qa, 'T-12 LTMS_Api_Heka instanciada', $e->getMessage() );
    }
} else {
    qa_warn( $qa, 'T-12 LTMS_Api_Heka existe', 'Clase no encontrada — asegúrate de que class-ltms-api-heka.php esté en includes/api/ y el autoloader la cargue' );
}

echo "\n";

// ═══════════════════════════════════════════════════════════════════
// RESUMEN
// ═══════════════════════════════════════════════════════════════════

print_summary:

echo "══════════════════════════════════════════════════\n";
echo "  RESUMEN QA — Heka Entrega\n";
echo "══════════════════════════════════════════════════\n";
printf( "  ✅ PASS : %d\n", $qa['pass'] );
printf( "  ❌ FAIL : %d\n", $qa['fail'] );
printf( "  ⚠️  WARN : %d\n", $qa['warn'] );
printf( "  TOTAL  : %d pruebas\n\n", $qa['pass'] + $qa['fail'] + $qa['warn'] );

if ( $qa['fail'] === 0 ) {
    echo "  🎉 Sin fallos críticos.\n";
} else {
    echo "  🔴 Hay {$qa['fail']} fallo(s) — revisar output arriba.\n";
}

echo "\n";
