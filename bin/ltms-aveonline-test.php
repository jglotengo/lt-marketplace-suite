<?php
/**
 * LTMS — Diagnóstico y pruebas de APIs de Aveonline
 *
 * Ejecutar con:
 *   wp eval-file wp-content/plugins/lt-marketplace-suite/bin/ltms-aveonline-test.php --allow-root
 *
 * O desde la raíz del WP:
 *   wp --path=/home/customer/www/lo-tengo.com.co/public_html \
 *      eval-file /tmp/ltms-aveonline-test.php --allow-root
 */

if ( ! defined( 'ABSPATH' ) ) {
    // Ejecutado directamente sin WP-CLI — salir
    die( "Debe ejecutarse con WP-CLI: wp eval-file ltms-aveonline-test.php --allow-root\n" );
}

$PLUGIN = '/home/customer/www/lo-tengo.com.co/public_html/wp-content/plugins/lt-marketplace-suite';

// ────────────────────────────────────────────────────────────────────
// HELPERS
// ────────────────────────────────────────────────────────────────────

function ave_line( string $msg = '' ): void {
    echo $msg . "\n";
}

function ave_ok( string $label, $value = null ): void {
    $v = $value !== null ? " → " . ( is_string($value) ? $value : json_encode($value) ) : '';
    echo "  ✅  {$label}{$v}\n";
}

function ave_warn( string $label, $value = null ): void {
    $v = $value !== null ? " → " . ( is_string($value) ? $value : json_encode($value) ) : '';
    echo "  ⚠️   {$label}{$v}\n";
}

function ave_err( string $label, $value = null ): void {
    $v = $value !== null ? " → " . ( is_string($value) ? $value : json_encode($value) ) : '';
    echo "  ❌  {$label}{$v}\n";
}

function ave_section( string $title ): void {
    $line = str_repeat('─', 60);
    echo "\n{$line}\n  {$title}\n{$line}\n";
}

function ave_http( string $label, string $url, array $args = [] ): array {
    $defaults = [
        'timeout' => 15,
        'headers' => [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ],
    ];
    $args = array_merge_recursive( $defaults, $args );

    $method = strtoupper( $args['method'] ?? 'GET' );
    unset( $args['method'] );

    $start = microtime(true);
    if ( $method === 'POST' ) {
        $res = wp_remote_post( $url, $args );
    } else {
        $res = wp_remote_get( $url, $args );
    }
    $elapsed = round( (microtime(true) - $start) * 1000 );

    if ( is_wp_error( $res ) ) {
        ave_err( $label, "WP_Error: " . $res->get_error_message() );
        return [ 'ok' => false, 'code' => 0, 'body' => null, 'ms' => $elapsed ];
    }

    $code = (int) wp_remote_retrieve_response_code( $res );
    $raw  = wp_remote_retrieve_body( $res );
    $body = json_decode( $raw, true );
    $ok   = $code >= 200 && $code < 300;

    $status = $ok ? "✅" : "❌";
    echo "  {$status} [{$code}] {$label} ({$elapsed}ms)\n";

    return [ 'ok' => $ok, 'code' => $code, 'body' => $body, 'raw' => $raw, 'ms' => $elapsed ];
}

// ════════════════════════════════════════════════════════════════════
// INICIO
// ════════════════════════════════════════════════════════════════════

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║        LTMS — Diagnóstico APIs Aveonline                     ║\n";
echo "║        " . date('Y-m-d H:i:s') . " (UTC)                         ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";

// ────────────────────────────────────────────────────────────────────
// 1. ESTADO DE CONFIGURACIÓN
// ────────────────────────────────────────────────────────────────────
ave_section("1. CONFIGURACIÓN GUARDADA EN WP OPTIONS");

$opts = [
    'ltms_aveonline_enabled'            => 'Aveonline habilitado',
    'ltms_aveonline_usuario'            => 'Usuario',
    'ltms_aveonline_clave'              => 'Contraseña (cifrada)',
    'ltms_aveonline_idempresa'          => 'ID Empresa',
    'ltms_aveonline_idagente'           => 'ID Agente',
    'ltms_aveonline_idtransportador'    => 'ID Transportador defecto',
    'ltms_aveonline_codigo'             => 'Código guía',
    'ltms_aveonline_clave_guia'         => 'Clave guía (cifrada)',
    'ltms_aveonline_onboarding_token'   => 'Token Onboarding (cifrado)',
    'ltms_aveonline_hub_idtransportadora' => 'Ave-Hub ID Transportadora',
    'ltms_ordenes_compra_enabled'       => 'Órdenes de compra habilitadas',
];

$credenciales_ok = true;
foreach ( $opts as $key => $label ) {
    $val = get_option( $key, '' );
    $es_clave = strpos($key, 'clave') !== false || strpos($key, 'token') !== false;

    if ( $val !== '' && $val !== null && $val !== false ) {
        $display = $es_clave ? '[' . strlen($val) . ' chars - cifrado]' : $val;
        ave_ok( $label, $display );
    } else {
        ave_warn( $label, 'vacío' );
        if ( in_array($key, ['ltms_aveonline_usuario', 'ltms_aveonline_clave', 'ltms_aveonline_idempresa']) ) {
            $credenciales_ok = false;
        }
    }
}

// ────────────────────────────────────────────────────────────────────
// 2. CLASES PHP — ¿CARGADAS?
// ────────────────────────────────────────────────────────────────────
ave_section("2. CLASES PHP DEL PLUGIN");

$clases = [
    'LTMS_Api_Aveonline'              => 'Cliente principal (guías, cotización)',
    'LTMS_Api_Aveonline_Hub'          => 'Ave-Hub (eventos de envío)',
    'LTMS_Api_Aveonline_Onboarding'   => 'Onboarding de clientes',
    'LTMS_Aveonline_Onboarding_Ajax'  => 'AJAX handlers de onboarding',
    'LTMS_Abstract_API_Client'        => 'Clase base abstracta',
];

foreach ( $clases as $clase => $desc ) {
    if ( class_exists( $clase ) ) {
        ave_ok( "{$clase}", $desc );
    } else {
        ave_err( "{$clase}", "NO CARGADA — {$desc}" );
    }
}

// ────────────────────────────────────────────────────────────────────
// 3. ARCHIVOS FÍSICOS
// ────────────────────────────────────────────────────────────────────
ave_section("3. ARCHIVOS EN DISCO");

$archivos = [
    "{$PLUGIN}/includes/api/class-ltms-api-aveonline.php"               => 'API principal',
    "{$PLUGIN}/includes/api/class-ltms-api-aveonline-hub.php"           => 'Ave-Hub',
    "{$PLUGIN}/includes/api/class-ltms-api-aveonline-onboarding.php"    => 'Onboarding',
    "{$PLUGIN}/includes/business/class-ltms-aveonline-onboarding-ajax.php" => 'AJAX Onboarding',
    "{$PLUGIN}/includes/admin/views/settings/section-aveonline.php"     => 'Vista admin Aveonline',
    "{$PLUGIN}/includes/shipping/class-ltms-shipping-method-aveonline.php" => 'Método envío WC',
];

foreach ( $archivos as $path => $desc ) {
    $base = basename($path);
    if ( file_exists($path) ) {
        $size = round( filesize($path) / 1024, 1 );
        ave_ok( "{$base}", "{$desc} ({$size} KB)" );
    } else {
        ave_err( "{$base}", "NO EXISTE — {$desc}" );
    }
}

// ────────────────────────────────────────────────────────────────────
// 4. CONECTIVIDAD A ENDPOINTS
// ────────────────────────────────────────────────────────────────────
ave_section("4. CONECTIVIDAD HTTP A ENDPOINTS AVEONLINE");

ave_line("  [GET] Comprobando alcance de red a los dominios...");

$endpoints_ping = [
    'https://app.aveonline.co/api/box/v1.0/ciudad.php'                                         => 'app.aveonline.co (API principal)',
    'https://api.aveonline.co/api-onboarding/public/api/v1/external/onboarding/acceptTerms'    => 'api.aveonline.co (Onboarding)',
    'https://api.aveonline.co/api-webhook/public/api/v1/login'                                 => 'api.aveonline.co (Ave-Hub)',
    'https://api.aveonline.co/api-oficinas/public/api/v1/offices/all'                          => 'api.aveonline.co (Oficinas)',
];

foreach ( $endpoints_ping as $url => $label ) {
    $res = wp_remote_get( $url, [ 'timeout' => 10 ] );
    $code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
    $err  = is_wp_error($res) ? $res->get_error_message() : '';

    if ( $code > 0 ) {
        // Cualquier respuesta HTTP (incluso 401/403/422) = servidor alcanzable
        $reachable = $code !== 0;
        if ( in_array($code, [200, 201, 401, 403, 405, 422]) ) {
            ave_ok( $label, "Alcanzable — HTTP {$code}" );
        } else {
            ave_warn( $label, "HTTP {$code} (revisar)" );
        }
    } else {
        ave_err( $label, "No alcanzable — {$err}" );
    }
}

// ────────────────────────────────────────────────────────────────────
// 5. PRUEBA DE AUTENTICACIÓN (solo si hay credenciales)
// ────────────────────────────────────────────────────────────────────
ave_section("5. AUTENTICACIÓN API v2 (POST /comunes/v2.0/autenticarusuario.php)");

$usuario = get_option('ltms_aveonline_usuario', '');
$clave_enc = get_option('ltms_aveonline_clave', '');
$clave = '';

if ( $clave_enc && class_exists('LTMS_Core_Security') ) {
    $clave = LTMS_Core_Security::decrypt( $clave_enc );
}

if ( empty($usuario) || empty($clave) ) {
    ave_warn( "Sin credenciales configuradas — saltando prueba de auth" );
    ave_line("  ℹ️   Para configurar: Admin → LTMS → Configuración → Aveonline");
    $token_v2 = null;
} else {
    ave_line("  → Usuario: {$usuario}");
    $auth_url = 'https://app.aveonline.co/api/comunes/v2.0/autenticarusuario.php';
    $res = ave_http(
        "Auth v2 — /comunes/v2.0/autenticarusuario.php",
        $auth_url,
        [
            'method' => 'POST',
            'body'   => wp_json_encode([ 'usuario' => $usuario, 'clave' => $clave ]),
        ]
    );

    $token_v2 = null;
    if ( $res['ok'] && ! empty($res['body']) ) {
        $body = $res['body'];
        // La respuesta de auth v2 tiene el token en body.token o body.data.token
        $token_v2 = $body['token'] ?? $body['data']['token'] ?? null;
        if ( $token_v2 ) {
            ave_ok( "Token JWT obtenido", substr($token_v2, 0, 40) . '...' );
            $idempresa_resp = $body['data']['cuentas'][0]['usuarios'][0]['id']
                ?? $body['cuentas'][0]['usuarios'][0]['id']
                ?? null;
            if ( $idempresa_resp ) {
                ave_ok( "idempresa en respuesta", $idempresa_resp );
            }
        } else {
            ave_warn( "Respuesta OK pero no se encontró token en body", json_encode($body, JSON_PRETTY_PRINT) );
        }
    } elseif ( ! $res['ok'] ) {
        ave_line("  ℹ️   Raw response: " . substr($res['raw'] ?? '', 0, 500) );
    }
}

// ────────────────────────────────────────────────────────────────────
// 6. PRUEBA COTIZACIÓN (solo si tenemos token)
// ────────────────────────────────────────────────────────────────────
ave_section("6. COTIZACIÓN DE ENVÍO (POST /nal/v1.0/generarGuiaTransporteNacional.php)");

if ( empty($token_v2) ) {
    ave_warn( "Sin token disponible — saltando prueba de cotización" );
    ave_line("  ℹ️   Se requiere autenticación exitosa en el paso 5" );
} else {
    $idempresa  = get_option('ltms_aveonline_idempresa', 0);
    $idagente   = get_option('ltms_aveonline_idagente', '');
    $idtransp   = get_option('ltms_aveonline_idtransportador', '');

    $payload_cotizar = [
        'tipo'              => 'cotizar2',
        'token'             => $token_v2,
        'idempresa'         => (int) $idempresa,
        'idagente'          => $idagente,
        'Direccion_Origen'  => 'Calle 10 # 4-45',
        'CodigoCiudad_Origen' => '05001', // Medellín
        'CodigoCiudad_Destino' => '11001', // Bogotá
        'Peso'              => 1,
        'Largo'             => 20,
        'Ancho'             => 15,
        'Alto'              => 10,
        'Valor_Mercancia'   => 50000,
        'Valor_Flete'       => 0,
    ];

    $res = ave_http(
        "Cotización prueba Medellín→Bogotá 1kg",
        'https://app.aveonline.co/api/nal/v1.0/generarGuiaTransporteNacional.php',
        [
            'method'  => 'POST',
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'body'    => wp_json_encode( $payload_cotizar ),
        ]
    );

    if ( $res['ok'] && $res['body'] ) {
        $b = $res['body'];
        $opciones = $b['tarifas'] ?? $b['data']['tarifas'] ?? $b['opciones'] ?? null;
        if ( $opciones ) {
            ave_ok( "Tarifas recibidas", count($opciones) . " opciones" );
            foreach ( array_slice($opciones, 0, 3) as $op ) {
                $nombre = $op['nombreTransportadora'] ?? $op['nombre'] ?? 'N/A';
                $precio = $op['valorFlete'] ?? $op['precio'] ?? 'N/A';
                $dias   = $op['diasEntrega'] ?? $op['dias'] ?? 'N/A';
                ave_line("     → {$nombre}: $" . number_format((float)$precio, 0, '.', '.') . " COP | {$dias} días");
            }
        } else {
            ave_line("  ℹ️   Respuesta: " . json_encode($b, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) );
        }
    } elseif ( ! $res['ok'] ) {
        ave_line("  ℹ️   Raw: " . substr($res['raw'] ?? '', 0, 600) );
    }
}

// ────────────────────────────────────────────────────────────────────
// 7. PRUEBA LISTADO CIUDADES
// ────────────────────────────────────────────────────────────────────
ave_section("7. CIUDADES (POST /box/v1.0/ciudad.php)");

if ( empty($token_v2) ) {
    ave_warn( "Sin token — saltando" );
} else {
    $res = ave_http(
        "Listar ciudades (primeras 5)",
        'https://app.aveonline.co/api/box/v1.0/ciudad.php',
        [
            'method' => 'POST',
            'body'   => wp_json_encode([ 'token' => $token_v2 ]),
        ]
    );

    if ( $res['ok'] && $res['body'] ) {
        $ciudades = $res['body']['ciudades'] ?? $res['body']['data'] ?? $res['body'];
        if ( is_array($ciudades) ) {
            ave_ok( "Ciudades recibidas", count($ciudades) . " total" );
            foreach ( array_slice($ciudades, 0, 5) as $c ) {
                $nombre = $c['nombre'] ?? $c['NombreCiudad'] ?? $c['ciudad'] ?? json_encode($c);
                $codigo = $c['codigo'] ?? $c['CodigoCiudad'] ?? '';
                ave_line("     → [{$codigo}] {$nombre}");
            }
        } else {
            ave_line("  ℹ️   " . substr(json_encode($res['body']), 0, 400));
        }
    }
}

// ────────────────────────────────────────────────────────────────────
// 8. PRUEBA TOKEN ONBOARDING
// ────────────────────────────────────────────────────────────────────
ave_section("8. ONBOARDING API — TOKEN JWT ESTÁTICO");

$onboarding_token_enc = get_option('ltms_aveonline_onboarding_token', '');
$onboarding_token = '';

if ( $onboarding_token_enc && class_exists('LTMS_Core_Security') ) {
    $onboarding_token = LTMS_Core_Security::decrypt( $onboarding_token_enc );
}

if ( empty($onboarding_token) ) {
    ave_warn( "Token de onboarding no configurado" );
    ave_line("  ℹ️   Admin → LTMS → Configuración → Aveonline → 'Token Onboarding (JWT)'");
    ave_line("  ℹ️   Solicitar el token a desarrollo1@aveonline.co");
} else {
    ave_ok( "Token onboarding configurado", strlen($onboarding_token) . " chars" );

    // Prueba mínima: hit al endpoint acceptTerms con datos de prueba para verificar que el token es válido
    $res = ave_http(
        "acceptTerms (verificar token JWT)",
        'https://api.aveonline.co/api-onboarding/public/api/v1/external/onboarding/acceptTerms',
        [
            'method'  => 'POST',
            'headers' => [
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'Authorization' => "Bearer {$onboarding_token}",
            ],
            'body' => wp_json_encode([
                'email'     => 'test_ltms_' . time() . '@lotengo.com.co',
                'password'  => 'Test1234!',
                'firstName' => 'LTMS',
                'lastName'  => 'Test',
                'phone'     => '3001234567',
                'docType'   => 'CC',
                'docNumber' => '1234567890',
            ]),
        ]
    );

    if ( $res['code'] === 401 || $res['code'] === 403 ) {
        ave_err( "Token inválido o expirado — HTTP {$res['code']}" );
    } elseif ( $res['code'] === 422 ) {
        ave_warn( "Token válido pero datos de prueba rechazados (422 esperado en test)", json_encode($res['body']) );
    } elseif ( $res['ok'] ) {
        ave_ok( "Token válido — seed recibido", $res['body']['data']['seed'] ?? 'N/A' );
    } else {
        ave_line("  Raw: " . substr($res['raw'] ?? '', 0, 400) );
    }
}

// ────────────────────────────────────────────────────────────────────
// 9. PRUEBA AVE-HUB
// ────────────────────────────────────────────────────────────────────
ave_section("9. AVE-HUB — Autenticación");

$hub_id_transp = (int) get_option('ltms_aveonline_hub_idtransportadora', 0);

if ( ! $hub_id_transp ) {
    ave_warn( "ltms_aveonline_hub_idtransportadora no configurado — saltando" );
} else {
    $res = ave_http(
        "Ave-Hub login (POST /api-webhook/public/api/v1/login)",
        'https://api.aveonline.co/api-webhook/public/api/v1/login',
        [
            'method' => 'POST',
            'body'   => wp_json_encode([
                'type' => 'webhook_auth',
                'data' => [
                    'idtransportadora' => $hub_id_transp,
                    'reason'           => 'actualizar_estados_guias',
                ],
            ]),
        ]
    );

    if ( $res['ok'] ) {
        $hub_token = $res['body']['token'] ?? $res['body']['data']['token'] ?? null;
        if ( $hub_token ) {
            ave_ok( "Token Ave-Hub obtenido", substr($hub_token, 0, 40) . '...' );
        }
    }
}

// ────────────────────────────────────────────────────────────────────
// 10. TRANSIENT TOKENS EN CACHÉ
// ────────────────────────────────────────────────────────────────────
ave_section("10. TOKENS EN CACHÉ (transients / options)");

$cached_token_key = 'ltms_aveonline_jwt_token'; // Ver código de la clase
$cached_v2 = get_transient( 'ltms_ave_token' ) ?: get_option('ltms_aveonline_hub_token', '');
$hub_exp   = (int) get_option('ltms_aveonline_hub_token_expires', 0);

// Buscar el transient real — la clase puede usar diferentes keys
global $wpdb;
$transients = $wpdb->get_results(
    "SELECT option_name, LENGTH(option_value) as len, option_value
     FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_ltms_ave%'
        OR option_name LIKE 'ltms_aveonline_hub_token%'
     ORDER BY option_name",
    ARRAY_A
);

if ( $transients ) {
    foreach ( $transients as $t ) {
        $name = $t['option_name'];
        $val  = strlen($t['option_value']) > 60
            ? substr($t['option_value'], 0, 60) . '...'
            : $t['option_value'];
        ave_ok( $name, "[{$t['len']} chars] {$val}" );
    }
} else {
    ave_warn( "No hay tokens en caché — se generarán al primer uso" );
}

if ( $hub_exp > 0 ) {
    $remaining = $hub_exp - time();
    if ( $remaining > 0 ) {
        ave_ok( "Ave-Hub token expira en", gmdate('H\h i\m', $remaining) );
    } else {
        ave_warn( "Ave-Hub token expirado hace " . gmdate('H\h i\m', abs($remaining)) );
    }
}

// ────────────────────────────────────────────────────────────────────
// RESUMEN
// ────────────────────────────────────────────────────────────────────
ave_section("RESUMEN");

$resumen = [
    'Aveonline habilitado'    => get_option('ltms_aveonline_enabled') === 'yes',
    'Usuario configurado'     => ! empty( get_option('ltms_aveonline_usuario') ),
    'Contraseña configurada'  => ! empty( get_option('ltms_aveonline_clave') ),
    'idempresa configurado'   => ! empty( get_option('ltms_aveonline_idempresa') ),
    'idagente configurado'    => ! empty( get_option('ltms_aveonline_idagente') ),
    'Token onboarding config' => ! empty( get_option('ltms_aveonline_onboarding_token') ),
    'Ave-Hub idtransp config' => ! empty( get_option('ltms_aveonline_hub_idtransportadora') ),
    'Clase API cargada'       => class_exists('LTMS_Api_Aveonline'),
    'Clase Onboarding cargada'=> class_exists('LTMS_Api_Aveonline_Onboarding'),
    'Clase Hub cargada'       => class_exists('LTMS_Api_Aveonline_Hub'),
];

$pendientes = [];
foreach ( $resumen as $label => $estado ) {
    if ( $estado ) {
        ave_ok( $label );
    } else {
        ave_err( $label );
        $pendientes[] = $label;
    }
}

if ( $pendientes ) {
    ave_line("");
    ave_line("  📋 PENDIENTE CONFIGURAR:");
    foreach ( $pendientes as $p ) {
        ave_line("     • {$p}");
    }
    ave_line("");
    ave_line("  → Admin → LTMS → Configuración → pestaña Aveonline");
    ave_line("  → Credenciales: app.aveonline.co → Perfil → Integraciones → API");
    ave_line("  → Token onboarding: solicitar a desarrollo1@aveonline.co");
}

ave_line("");
ave_line("  Diagnóstico completo. " . date('Y-m-d H:i:s'));
ave_line("");
