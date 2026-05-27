#!/usr/bin/env php
<?php
/**
 * bin/ltms-qa-deprisa.php
 * QA automatizado para la integración Deprisa
 * Uso: wp --path=/path/to/wp eval-file bin/ltms-qa-deprisa.php --allow-root
 *
 * @package LTMS
 * @since   1.8.0
 */

define( 'LTMS_QA_DEPRISA', true );

// ─── helpers ─────────────────────────────────────────────────────────────────

$qa = [ 'pass' => 0, 'fail' => 0, 'warn' => 0 ];

function qa_ok( array &$qa, string $test, string $detail = '' ): void {
	$qa['pass']++;
	echo '  ✅ PASS : ' . $test . ( $detail ? " — $detail" : '' ) . "\n";
}

function qa_fail( array &$qa, string $test, string $detail = '' ): void {
	$qa['fail']++;
	echo '  ❌ FAIL : ' . $test . ( $detail ? " — $detail" : '' ) . "\n";
}

function qa_warn( array &$qa, string $test, string $detail = '' ): void {
	$qa['warn']++;
	echo '  ⚠️  WARN : ' . $test . ( $detail ? " — $detail" : '' ) . "\n";
}

function assert_eq( array &$qa, string $test, $expected, $actual ): void {
	if ( $expected === $actual ) {
		qa_ok( $qa, $test );
	} else {
		qa_fail( $qa, $test, "esperado=[$expected] actual=[$actual]" );
	}
}

// ─── credenciales QA ─────────────────────────────────────────────────────────

// Las credenciales se leen de las opciones WP configuradas vía admin LTMS.
// Para correr QA sin configurar opciones, se pueden pasar como env vars:
//   DEPRISA_USER=WS00011111 DEPRISA_PASS=xxx wp eval-file ...

$username = getenv( 'DEPRISA_USER' ) ?: get_option( 'ltms_deprisa_username', '' );
$password = getenv( 'DEPRISA_PASS' ) ?: get_option( 'ltms_deprisa_password', '' );

if ( empty( $username ) || empty( $password ) ) {
	echo "\n⚠️  No hay credenciales Deprisa configuradas.\n";
	echo "   Configúralas en LTMS → Ajustes → Deprisa\n";
	echo "   o exporta DEPRISA_USER y DEPRISA_PASS antes de correr el QA.\n\n";
	echo "   Ejecutando solo tests unitarios (sin red)...\n\n";
}

$sandbox = true; // siempre QA en sandbox
$api     = new LTMS_Api_Deprisa( $username, $password, $sandbox );

echo "\n══════════════════════════════════════════\n";
echo "   QA — Integración Deprisa (sandbox)\n";
echo "══════════════════════════════════════════\n\n";

// ─── T-01: Generar código de admisión ────────────────────────────────────────
echo "[ T-01 ] Utilidades estáticas\n";
$codigo = LTMS_Api_Deprisa::generar_codigo_admision( 'LTMS' );
if ( str_starts_with( $codigo, 'LTMS-' ) && strlen( $codigo ) > 10 ) {
	qa_ok( $qa, 'generar_codigo_admision()', $codigo );
} else {
	qa_fail( $qa, 'generar_codigo_admision()', $codigo );
}

$fecha = LTMS_Api_Deprisa::formatear_fecha( new DateTime( '2026-05-26' ) );
assert_eq( $qa, 'formatear_fecha()', '26/05/2026', $fecha );

echo "\n";

// ─── T-02: Instanciación correcta ────────────────────────────────────────────
echo "[ T-02 ] Instanciación del cliente\n";
try {
	$test_api = new LTMS_Api_Deprisa( 'TEST', 'TEST', true );
	qa_ok( $qa, 'new LTMS_Api_Deprisa() sin excepción' );
} catch ( \Throwable $e ) {
	qa_fail( $qa, 'new LTMS_Api_Deprisa()', $e->getMessage() );
}
echo "\n";

// ─── T-03: Cotización (requiere red + credenciales) ───────────────────────────
echo "[ T-03 ] Cotización\n";
if ( empty( $username ) ) {
	qa_warn( $qa, 'cotizar() — sin credenciales, test omitido' );
} else {
	try {
		$cot = $api->cotizar( [
			'tipoEnvio'             => 'N',
			'numeroBultos'          => 1,
			'kilos'                 => 1.0,
			'clienteRemitente'      => get_option( 'ltms_deprisa_cliente_remitente', '00000011' ),
			'centroRemitente'       => get_option( 'ltms_deprisa_centro_remitente',  '01' ),
			'paisRemitente'         => '057',
			'poblacionRemitente'    => 'BOGOTA',
			'paisDestinatario'      => '057',
			'poblacionDestinatario' => 'CALI',
			'importeValorDeclarado' => 50000,
			'tipoMoneda'            => 'COP',
		] );

		if ( $cot['ok'] && count( $cot['cotizaciones'] ) > 0 ) {
			qa_ok( $qa, 'cotizar() retorna cotizaciones', count( $cot['cotizaciones'] ) . ' producto(s)' );
			foreach ( $cot['cotizaciones'] as $c ) {
				echo "       Producto: {$c['productoDescripcion']} | Total: \${$c['total']} | Entrega: {$c['tiempoEntrega']}\n";
			}
		} elseif ( ! $cot['ok'] ) {
			$errs = implode( ', ', array_column( $cot['errors'], 'descripcion' ) );
			qa_fail( $qa, 'cotizar() con errores', $errs );
		} else {
			qa_warn( $qa, 'cotizar() devolvió 0 cotizaciones' );
		}
	} catch ( LTMS_Deprisa_Exception $e ) {
		qa_fail( $qa, 'cotizar() lanzó excepción', $e->getMessage() . ' (HTTP ' . $e->getCode() . ')' );
	}
}
echo "\n";

// ─── T-04: Admitir envío (requiere red + credenciales) ───────────────────────
$guia_generada = null;
echo "[ T-04 ] Admitir envío\n";
if ( empty( $username ) ) {
	qa_warn( $qa, 'admitir_envio() — sin credenciales, test omitido' );
} else {
	try {
		$admision = $api->admitir_envio( [
			'codigoAdmision'                => LTMS_Api_Deprisa::generar_codigo_admision( 'QA' ),
			'grabarEnvio'                   => 'S',
			'numeroBultos'                  => 1,
			'clienteRemitente'              => get_option( 'ltms_deprisa_cliente_remitente', '00000011' ),
			'centroRemitente'               => get_option( 'ltms_deprisa_centro_remitente',  '01' ),
			'clienteDestinatario'           => '99999999',
			'centroDestinatario'            => '99',
			'nombreDestinatario'            => 'QA Lo-Tengo Test',
			'direccionDestinatario'         => 'Av. 6N # 23-45 Apto 301',
			'paisDestinatario'              => '057',
			'codigoPostalDestinatario'      => '760001',
			'poblacionDestinatario'         => 'CALI',
			'tipoDocDestinatario'           => 'CC',
			'documentoIdentidadDestinatario'=> '10203040',
			'telefonoContactoDestinatario'  => '3101234567',
			'codigoServicio'                => '3005',
			'kilos'                         => 1.0,
			'tipoPortes'                    => 'P',
			'importeValorDeclarado'         => 50000,
			'asegurarEnvio'                 => 'N',
			'tipoMoneda'                    => 'COP',
			'numeroReferencia'              => 'QA-' . time(),
			'observaciones1'                => 'QA LTMS — borrar si es de prueba',
		] );

		if ( $admision['ok'] && ! empty( $admision['numeroEnvio'] ) ) {
			$guia_generada = $admision['numeroEnvio'];
			qa_ok( $qa, 'admitir_envio() guía creada', $guia_generada );
			echo "       Delegación destino: {$admision['delegacionDestino']}\n";
			echo "       Fecha objetivo:     {$admision['fechaObjetivo']}\n";
			echo "       ⚠️  Guía creada en sandbox — no genera facturación\n";
		} else {
			$errs = implode( ', ', array_column( $admision['errors'] ?? [], 'descripcion' ) );
			qa_fail( $qa, 'admitir_envio()', $errs );
		}
	} catch ( LTMS_Deprisa_Exception $e ) {
		qa_fail( $qa, 'admitir_envio() lanzó excepción', $e->getMessage() );
	}
}
echo "\n";

// ─── T-05: Etiqueta ──────────────────────────────────────────────────────────
echo "[ T-05 ] Etiqueta\n";
if ( empty( $guia_generada ) ) {
	qa_warn( $qa, 'obtener_etiquetas() — no hay guía generada en T-04, test omitido' );
} else {
	try {
		$etiquetas = $api->obtener_etiquetas( [
			[ 'numeroEnvio' => $guia_generada, 'tipoImpresora' => 'T' ],
		] );

		if ( ! empty( $etiquetas[0]['etiquetaBase64'] ) ) {
			$size = strlen( $etiquetas[0]['etiquetaBase64'] );
			qa_ok( $qa, 'obtener_etiquetas() PDF Base64 recibido', number_format( $size ) . ' bytes' );
		} else {
			qa_fail( $qa, 'obtener_etiquetas() sin Base64' );
		}
	} catch ( LTMS_Deprisa_Exception $e ) {
		qa_fail( $qa, 'obtener_etiquetas() excepción', $e->getMessage() );
	}
}
echo "\n";

// ─── T-06: Tracking ──────────────────────────────────────────────────────────
echo "[ T-06 ] Tracking\n";
if ( empty( $guia_generada ) ) {
	qa_warn( $qa, 'consultar_tracking() — no hay guía, test omitido' );
} else {
	try {
		$tracking = $api->consultar_tracking( $guia_generada );
		if ( $tracking !== null ) {
			qa_ok( $qa, 'consultar_tracking() respuesta recibida', $tracking['descripcionServicio'] ?? '?' );
		} else {
			qa_warn( $qa, 'consultar_tracking() devolvió null (guía recién creada, aún no visible)' );
		}
	} catch ( LTMS_Deprisa_Exception $e ) {
		if ( $e->getCode() === 404 ) {
			qa_warn( $qa, 'consultar_tracking() 404 (guía recién creada, normal en sandbox)' );
		} else {
			qa_fail( $qa, 'consultar_tracking() excepción', $e->getMessage() );
		}
	}
}
echo "\n";

// ─── T-07: Crear recogida ─────────────────────────────────────────────────────
$codigo_recogida = null;
echo "[ T-07 ] Crear recogida\n";
if ( empty( $username ) ) {
	qa_warn( $qa, 'crear_recogida() — sin credenciales, test omitido' );
} else {
	try {
		$manana = new DateTime( '+1 weekday' );
		$rec    = $api->crear_recogida( [
			'codigoAdmision'               => LTMS_Api_Deprisa::generar_codigo_admision( 'REC' ),
			'clienteRemitente'             => get_option( 'ltms_deprisa_cliente_remitente', '00000011' ),
			'centroRemitente'              => get_option( 'ltms_deprisa_centro_remitente',  '01' ),
			'nombreRemitente'              => 'Lo-Tengo QA',
			'direccionRemitente'           => 'Cra 50 # 26-50',
			'codigoPostalRemitente'        => '110911',
			'poblacionRemitente'           => 'BOGOTA',
			'tipoDocRemitente'             => 'NIT',
			'documentoIdentidadRemitente'  => '900999888',
			'personaContactoRemitente'     => 'QA Bot',
			'telefonoContactoRemitente'    => '3001234567',
			'fechaRecogida'                => LTMS_Api_Deprisa::formatear_fecha( $manana ),
			'rangoHorario'                 => '09:00-13:00',
			'codigoServicio'               => '3005',
			'embalaje'                     => 'C',
			'observaciones'                => 'QA LTMS recogida — borrar si es de prueba',
			'numeroBultos'                 => 1,
			'kilos'                        => 1.0,
		] );

		if ( $rec['ok'] && ! empty( $rec['codigoRecogida'] ) ) {
			$codigo_recogida = $rec['codigoRecogida'];
			qa_ok( $qa, 'crear_recogida()', "código={$codigo_recogida} estado={$rec['estadoRecogida']}" );
			echo "       ⚠️  Recogida creada en sandbox — cancelar si no es necesaria\n";
		} else {
			$errs = implode( ', ', array_column( $rec['errors'] ?? [], 'descripcion' ) );
			qa_fail( $qa, 'crear_recogida()', $errs );
		}
	} catch ( LTMS_Deprisa_Exception $e ) {
		qa_fail( $qa, 'crear_recogida() excepción', $e->getMessage() );
	}
}
echo "\n";

// ─── T-08: Asociar guía a recogida ───────────────────────────────────────────
echo "[ T-08 ] Asociar guía a recogida\n";
if ( empty( $guia_generada ) || empty( $codigo_recogida ) ) {
	qa_warn( $qa, 'asociar_guias() — dependencias T-04/T-07 no disponibles, omitido' );
} else {
	try {
		$asoc = $api->asociar_guias( [
			[ 'codigoRecogida' => $codigo_recogida, 'numeroEnvio' => $guia_generada ],
		] );

		if ( $asoc['ok'] ) {
			qa_ok( $qa, 'asociar_guias()', "guía {$guia_generada} → recogida {$codigo_recogida}" );
		} else {
			$errs = implode( ', ', array_column( $asoc['errors'], 'descripcion' ) );
			qa_fail( $qa, 'asociar_guias()', $errs );
		}
	} catch ( LTMS_Deprisa_Exception $e ) {
		qa_fail( $qa, 'asociar_guias() excepción', $e->getMessage() );
	}
}
echo "\n";

// ─── T-09: Ver recogida ───────────────────────────────────────────────────────
echo "[ T-09 ] Ver estado de recogida\n";
if ( empty( $codigo_recogida ) ) {
	qa_warn( $qa, 'ver_recogidas() — sin código, omitido' );
} else {
	try {
		$estados = $api->ver_recogidas( [ $codigo_recogida ] );
		if ( ! empty( $estados[0]['estadoRecogida'] ) ) {
			qa_ok( $qa, 'ver_recogidas()', "estado={$estados[0]['estadoRecogida']}" );
		} else {
			qa_warn( $qa, 'ver_recogidas() sin datos de estado' );
		}
	} catch ( LTMS_Deprisa_Exception $e ) {
		qa_fail( $qa, 'ver_recogidas() excepción', $e->getMessage() );
	}
}
echo "\n";

// ─── T-10: Manifiesto ─────────────────────────────────────────────────────────
echo "[ T-10 ] Manifiesto de recogida\n";
if ( empty( $codigo_recogida ) ) {
	qa_warn( $qa, 'obtener_manifiesto() — sin código, omitido' );
} else {
	try {
		$manifiestos = $api->obtener_manifiesto( [ $codigo_recogida ] );
		if ( ! empty( $manifiestos[0]['manifiestoBase64'] ) ) {
			qa_ok( $qa, 'obtener_manifiesto() PDF recibido' );
		} else {
			qa_warn( $qa, 'obtener_manifiesto() sin PDF (recogida no manifestada aún, normal)' );
		}
	} catch ( LTMS_Deprisa_Exception $e ) {
		qa_fail( $qa, 'obtener_manifiesto() excepción', $e->getMessage() );
	}
}
echo "\n";

// ─── T-11: Excepción HTTP ────────────────────────────────────────────────────
echo "[ T-11 ] Manejo de errores HTTP\n";
try {
	$bad_api = new LTMS_Api_Deprisa( 'BAD_USER', 'BAD_PASS', true );
	$bad_api->consultar_tracking( '999999999999' );
	qa_warn( $qa, 'tracking con credenciales inválidas no lanzó excepción (inesperado)' );
} catch ( LTMS_Deprisa_Exception $e ) {
	if ( in_array( $e->getCode(), [ 401, 404, 0 ], true ) ) {
		qa_ok( $qa, 'LTMS_Deprisa_Exception lanzada correctamente', 'HTTP ' . $e->getCode() );
	} else {
		qa_fail( $qa, 'código de excepción inesperado', (string) $e->getCode() );
	}
}
echo "\n";

// ─── RESUMEN ─────────────────────────────────────────────────────────────────
echo "══════════════════════════════════════════\n";
echo "  RESUMEN QA — Deprisa\n";
echo "══════════════════════════════════════════\n";
echo "  ✅ PASS : {$qa['pass']}\n";
echo "  ❌ FAIL : {$qa['fail']}\n";
echo "  ⚠️  WARN : {$qa['warn']}\n";
echo "  TOTAL  : " . array_sum( $qa ) . " pruebas\n\n";

if ( $qa['fail'] === 0 ) {
	echo "  🎉 Sin fallos críticos.\n\n";
} else {
	echo "  🚨 Hay {$qa['fail']} fallo(s) — revisar antes de subir a producción.\n\n";
}
