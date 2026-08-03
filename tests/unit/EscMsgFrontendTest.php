<?php
/**
 * EscMsgFrontendTest — Tests estructurales para AUDIT-EXCMSG-FRONTEND-001 (sub-ciclo 4)
 *
 * Patrón detectado: handlers frontend que capturan `\Throwable $e` y:
 *   - P1: devuelven `$e->getMessage()` al cliente condicionalmente
 *     (ej. cuando WP_DEBUG está activo), permitiendo information disclosure
 *     en producción si el flag queda activo.  Caso: dashboard-logic.php L553
 *     añade `({$e->getMessage()})` al response si WP_DEBUG.
 *   - P2: añaden `$e->getMessage()` al order note (admin). Aunque WP admin
 *     escapa por defecto en la mayoría de vistas, defense-in-depth exige
 *     esc_html en el origen. Caso: frontend-checkout-handler.php L647.
 *
 * Alcance sub-ciclo FRONTEND (2 archivos, 2 instancias):
 *   - class-ltms-dashboard-logic.php          : 1 instancia P1 (L553)
 *   - class-ltms-frontend-checkout-handler.php : 1 instancia P2 (L647)
 *
 * Otros archivos frontend (frontend-checkout-mexico-handler.php,
 * frontend-payout-handler.php) ya tenían el sanitize correcto: getMessage
 * solo va a log interno, response usa mensaje hardcoded.
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

require_once __DIR__ . '/class-ltms-unit-test-case.php';

/**
 * @covers \LTMS_Dashboard_Logic
 * @covers \LTMS_Frontend_Checkout_Handler
 */
class EscMsgFrontendTest extends \LTMS\Tests\Unit\LTMS_Unit_Test_Case {

	private const AUDIT_TAG = 'EXCMSG-FIX (AUDIT-EXCMSG-FRONTEND-001,';

	private static function expected_files(): array {
		return [
			'class-ltms-dashboard-logic.php'           => 1,
			'class-ltms-frontend-checkout-handler.php'  => 1,
		];
	}

	private static function frontend_dir(): string {
		return dirname( __DIR__, 2 ) . '/includes/frontend/';
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 1 — Archivos objetivo existen
	// -----------------------------------------------------------------------

	public function test_all_target_frontend_files_exist(): void {
		foreach ( array_keys( self::expected_files() ) as $filename ) {
			$path = self::frontend_dir() . $filename;
			$this->assertFileExists( $path, "Archivo frontend objetivo debe existir: {$filename}" );
		}
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 2 — REGRESIÓN P1: getMessage en wp_send_json_error debe tener
	//              esc_html (caso condicional WP_DEBUG o directo).
	// -----------------------------------------------------------------------

	public function test_no_unescaped_getmessage_in_wp_send_json_error(): void {
		$violations = [];
		foreach ( array_keys( self::expected_files() ) as $filename ) {
			$path = self::frontend_dir() . $filename;
			$src  = file_get_contents( $path );
			$lines = preg_split( '/\r\n|\r|\n/', $src );

			foreach ( $lines as $i => $line ) {
				if ( strpos( $line, 'wp_send_json_error' ) === false ) {
					continue;
				}
				// Buscar en la misma línea y las 2 siguientes (.wp_send_json_error( multi-linea).
				$window = implode( ' ', array_slice( $lines, $i, 3 ) );
				if ( strpos( $window, 'getMessage()' ) === false ) {
					continue;
				}
				if ( strpos( $window, 'esc_html(' ) !== false || strpos( $window, 'esc_html (' ) !== false ) {
					continue;
				}
				$violations[] = "frontend/{$filename}:" . ( $i + 1 ) . " → " . trim( $line );
			}
		}
		$this->assertSame( [], $violations, sprintf(
			'Fix AUDIT-EXCMSG-FRONTEND-001: ninguna llamada wp_send_json_error con getMessage() debe estar sin esc_html(). Violaciones: %d',
			count( $violations )
		) );
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 3 — REGRESIÓN P2: getMessage en add_order_note debe tener
	//              esc_html (admin note visible en WP admin).
	// -----------------------------------------------------------------------

	public function test_no_unescaped_getmessage_in_add_order_note(): void {
		$violations = [];
		foreach ( array_keys( self::expected_files() ) as $filename ) {
			$path = self::frontend_dir() . $filename;
			$src  = file_get_contents( $path );
			$lines = preg_split( '/\r\n|\r|\n/', $src );

			foreach ( $lines as $i => $line ) {
				if ( strpos( $line, 'add_order_note' ) === false ) {
					continue;
				}
				$window = implode( ' ', array_slice( $lines, $i, 4 ) );
				if ( strpos( $window, 'getMessage()' ) === false ) {
					continue;
				}
				if ( strpos( $window, 'esc_html(' ) !== false || strpos( $window, 'esc_html (' ) !== false ) {
					continue;
				}
				$violations[] = "frontend/{$filename}:" . ( $i + 1 ) . " → " . trim( $line );
			}
		}
		$this->assertSame( [], $violations, sprintf(
			'Fix AUDIT-EXCMSG-FRONTEND-001: ningún add_order_note con getMessage() debe estar sin esc_html(). Violaciones: %d',
			count( $violations )
		) );
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 4 — Trazabilidad: marker EXCMSG-FIX en cada archivo
	// -----------------------------------------------------------------------

	public function test_audit_tag_present_in_each_frontend_file(): void {
		foreach ( array_keys( self::expected_files() ) as $filename ) {
			$path = self::frontend_dir() . $filename;
			$src  = file_get_contents( $path );
			$this->assertStringContainsString( self::AUDIT_TAG, $src, "Marker debe estar en frontend/{$filename}." );
		}
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 5 — PHP válido
	// -----------------------------------------------------------------------

	public function test_files_parse_as_valid_php(): void {
		foreach ( array_keys( self::expected_files() ) as $filename ) {
			$path = self::frontend_dir() . $filename;
			$src  = file_get_contents( $path );
			$tokens = @token_get_all( $src );
			$this->assertNotEmpty( $tokens, "frontend/{$filename} debe parsear como PHP." );
		}
	}
}
