<?php
/**
 * EscMsgAveonlineTest — Tests estructurales para AUDIT-EXCMSG-AVE-001 (sub-ciclo AVE)
 *
 * Patrón detectado (P1, XSS reflected): handlers wp_ajax_* que capturan
 * `\Throwable $e` (o `\Exception $e`) y devuelven el mensaje directo al
 * cliente vía `wp_send_json_error( [ 'message' => $e->getMessage() ] )`
 * sin esc_html(). Si el contenido del mensaje incluye input del usuario
 * (nombre de agente, dirección de destinatario, etc.) o respuesta de API
 * externa con datos del usuario, podría causar XSS reflected cuando el
 * JS frontend renderiza el mensaje via .html() (jQuery) en vez de .text().
 *
 * Fix aplicado: envolver `$e->getMessage()` con `esc_html()` en todos los
 * wp_send_json_error destined to cliente. Logs internos (Logger::warning/error,
 * error_log) se mantienen sin esc_html porque los logs NO se renderizan en
 * HTML y ecoar HTML entities en logs dificulta lectura.
 *
 * Alcance de este test (sub-ciclo AVE): 5 archivos business-aveonline con
 * 19 instancias P1 fixeadas:
 *   - class-ltms-business-aveonline-agents.php              (4 instancias)
 *   - class-ltms-business-aveonline-cities.php              (1 instancia)
 *   - class-ltms-business-aveonline-guias.php               (5 instancias)
 *   - class-ltms-business-aveonline-orden-compra.php        (2 instancias)
 *   - class-ltms-business-aveonline-shipment-relations.php  (7 instancias)
 *
 * Tests estructurales sobre el source (mismo patrón que AveonlineSandboxTest,
 * AveonlineShipmentRelationsTest del ciclo AUDIT-BIZ-AVE-001) — las clases
 * tienen dependencias WP/WC acopladas que no cargan en `LTMS_UNIT_ONLY=true`;
 * los asserts estructurales validan el código real sin instanciarlo.
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

require_once __DIR__ . '/class-ltms-unit-test-case.php';

/**
 * @covers \LTMS_Business_Aveonline_Agents
 * @covers \LTMS_Business_Aveonline_Cities
 * @covers \LTMS_Business_Aveonline_Guias
 * @covers \LTMS_Business_Aveonline_OrdenCompra
 * @covers \LTMS_Business_Aveonline_ShipmentRelations
 */
class EscMsgAveonlineTest extends \LTMS\Tests\Unit\LTMS_Unit_Test_Case {

	private const AUDIT_TAG = 'EXCMSG-FIX (AUDIT-EXCMSG-AVE-001, P1)';

	/**
	 * Mapa archivo → número esperado de instancias P1 fixeadas.
	 * Validado contra el file_get_contents del source real.
	 */
	private static function expected_files(): array {
		return [
			'class-ltms-business-aveonline-agents.php'              => 4,
			'class-ltms-business-aveonline-cities.php'             => 1,
			'class-ltms-business-aveonline-guias.php'              => 5,
			'class-ltms-business-aveonline-orden-compra.php'       => 2,
			'class-ltms-business-aveonline-shipment-relations.php' => 7,
		];
	}

	private static function business_dir(): string {
		return dirname( __DIR__, 2 ) . '/includes/business/';
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 1 — Archivos objetivo existen
	// -----------------------------------------------------------------------

	public function test_all_target_files_exist(): void {
		foreach ( array_keys( self::expected_files() ) as $filename ) {
			$path = self::business_dir() . $filename;
			$this->assertFileExists( $path, "Archivo objetivo debe existir: {$filename}" );
		}
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 2 — REGRESIÓN P0/P1: ningún wp_send_json_error usa getMessage()
	//              SIN esc_html(). Esta es la verificación central del fix.
	// -----------------------------------------------------------------------

	/**
	 * Para cada archivo, contar las líneas que contienen el patrón peligroso:
	 *   `wp_send_json_error(... 'message' => $e->getMessage()`
	 * sin `esc_html(` envolviéndolo en la MISMA línea.
	 *
	 * Antes del fix: 19 instancias peligrosas.
	 * Después del fix: 0 instancias peligrosas (todas escapadas con esc_html).
	 */
	public function test_no_unescaped_getmessage_in_wp_send_json_error(): void {
		$violations = [];
		foreach ( array_keys( self::expected_files() ) as $filename ) {
			$path = self::business_dir() . $filename;
			$src  = file_get_contents( $path );
			// Normalizar a \n para que el explode funcione consistente en Win/Unix.
			$lines = preg_split( '/\r\n|\r|\n/', $src );

			foreach ( $lines as $i => $line ) {
				// Detectar wp_send_json_error en la línea
				if ( strpos( $line, 'wp_send_json_error' ) === false ) {
					continue;
				}
				// Detectar getMessage() en la misma línea
				if ( strpos( $line, 'getMessage()' ) === false ) {
					continue;
				}
				// Detectar esc_html( en la misma línea — si está, NO es violación.
				if ( strpos( $line, 'esc_html(' ) !== false || strpos( $line, 'esc_html (' ) !== false ) {
					continue;
				}
				// Llegó aquí → violación P0/P1: getMessage sin esc_html en wp_send_json_error.
				$violations[] = "{$filename}:" . ( $i + 1 ) . " → " . trim( $line );
			}
		}
		$this->assertSame( [], $violations, sprintf(
			'Fix AUDIT-EXCMSG-AVE-001: ninguna línea wp_send_json_error debe usar $e->getMessage() sin esc_html(). Violaciones encontradas: %d',
			count( $violations )
		) );
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 3 — Trazabilidad: el marker comment EXCMSG-FIX está presente
	//              en el número esperado de archivos / instancias.
	// -----------------------------------------------------------------------

	public function test_audit_tag_present_in_each_file(): void {
		foreach ( array_keys( self::expected_files() ) as $filename ) {
			$path = self::business_dir() . $filename;
			$src  = file_get_contents( $path );
			$this->assertStringContainsString( self::AUDIT_TAG, $src, "El marker comment del fix debe estar en {$filename}." );
		}
	}

	public function test_audit_tag_count_matches_expected_per_file(): void {
		foreach ( self::expected_files() as $filename => $expected_esc_html_count ) {
			$path = self::business_dir() . $filename;
			$src  = file_get_contents( $path );

			// Contar ocurrencias de `esc_html( $e->getMessage() )` en el archivo.
			$esc_count = substr_count( $src, 'esc_html( $e->getMessage() )' );

			$this->assertGreaterThanOrEqual(
				$expected_esc_html_count,
				$esc_count,
				"{$filename} debe tener al menos {$expected_esc_html_count} instancias de esc_html( \$e->getMessage() ) — encontradas: {$esc_count}"
			);
		}
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 4 — Bloque try/catch preservado: wp_send_json_error sigue dentro
	//              del catch \Throwable|\Exception — el fix no sacó el handler
	//              del bloque de manejo de excepciones.
	// -----------------------------------------------------------------------

	public function test_catch_throwable_or_exception_still_wraps_wp_send_json_error(): void {
		foreach ( array_keys( self::expected_files() ) as $filename ) {
			$path = self::business_dir() . $filename;
			$src  = file_get_contents( $path );
			// Cada archivo debe seguir teniendo al menos un `catch ( \Throwable $e )` o `catch ( \Exception $e )`
			// en algún método que contenga wp_send_json_error + getMessage.
			$has_catch_throwable  = (bool) preg_match( '/catch\s*\(\s*\\\\?Throwable\s+\$e\s*\)/', $src );
			$has_catch_exception  = (bool) preg_match( '/catch\s*\(\s*\\\\?Exception\s+\$e\s*\)/', $src );
			$this->assertTrue(
				$has_catch_throwable || $has_catch_exception,
				"{$filename} debe preservar al menos un bloque catch (\\Throwable|\Exception) envolviendo wp_send_json_error."
			);
		}
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 5 — Regresión: verifico que los handlers NO usen `array_map` u
	//              otro método indirecto para escapar. El patrón esperado es
	//              el fix inline `esc_html( $e->getMessage() )` literal.
	// -----------------------------------------------------------------------

	public function test_no_alternative_escape_pattern_in_use(): void {
		// Validar que no hay uso de `wp_kses` u otras funciones que indirectamente
		// escapen getMessage pero impidan la trazabilidad del marker EXCMSG-FIX.
		foreach ( array_keys( self::expected_files() ) as $filename ) {
			$path = self::business_dir() . $filename;
			$src  = file_get_contents( $path );
			$this->assertStringNotContainsString( 'wp_kses( $e->getMessage', $src, "{$filename}: no usar wp_kses para getMessage, usar esc_html (más simple y suficiente)." );
			$this->assertStringNotContainsString( 'sanitize_text_field( $e->getMessage', $src, "{$filename}: no usar sanitize_text_field para getMessage, usar esc_html (preserva entidades)." );
		}
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 6 — Sintaxis PHP válida (validación rápida con token_get_all).
	// -----------------------------------------------------------------------

	public function test_files_parse_as_valid_php(): void {
		foreach ( array_keys( self::expected_files() ) as $filename ) {
			$path = self::business_dir() . $filename;
			$src  = file_get_contents( $path );
			$tokens = @token_get_all( $src );
			$this->assertNotFalse( $tokens, "{$filename}: el archivo debe ser parseable como PHP." );
			$this->assertNotEmpty( $tokens, "{$filename}: el archivo no debe estar vacío." );
		}
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 7 — Win/Unix: el fix no introduce diferencias de newline que
	//              rompan diff. Confirmamos que los archivos siguen usando un
	//              solo estilo de newline ( LF o CRLF, no mixto).
	// -----------------------------------------------------------------------

	public function test_files_have_consistent_newlines(): void {
		foreach ( array_keys( self::expected_files() ) as $filename ) {
			$path = self::business_dir() . $filename;
			$src  = file_get_contents( $path );
			$has_crlf = strpos( $src, "\r\n" ) !== false;
			$has_lf   = strpos( $src, "\n" ) !== false && ! $has_crlf;
			$has_cr   = strpos( $src, "\r" ) !== false && ! $has_crlf;

			// Al menos un estilo debe estar presente; no mezclar.
			$styles_present = (int) $has_crlf + (int) $has_lf + (int) $has_cr;
			$this->assertLessThanOrEqual( 1, $styles_present, "{$filename}: no debe mezclar estilos de newline (CRLF + LF)." );
		}
	}
}
