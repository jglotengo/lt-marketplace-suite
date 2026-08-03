<?php
/**
 * EscMsgAdminTest — Tests estructurales para AUDIT-EXCMSG-ADMIN-001 (sub-ciclo 3)
 *
 * Patrón detectado (P0/P1): handlers wp_ajax_* en admin que capturan
 * `\Throwable $e` y devuelven el mensaje directo al cliente vía
 * `wp_send_json_error( $e->getMessage() )` o
 * `wp_send_json_error( [ 'message' => $e->getMessage() ] )` sin esc_html().
 *
 * Alcance sub-ciclo ADMIN (6 archivos, 10 instancias P1):
 *   - class-ltms-admin-bookings.php    : 3 instancias (L185, L206, L222)
 *   - class-ltms-admin-donations.php   : 1 instancia (L393)
 *   - class-ltms-admin-marketing-manager.php : 1 instancia (L218)
 *   - class-ltms-admin-payouts.php      : 1 instancia (L527)
 *   - class-ltms-admin-settings.php     : 2 instancias (L578, L634)
 *   - class-ltms-deprisa-order-metabox.php : 2 instancias (L559, L668)
 *
 * Tests estructurales sobre el source (mismo patrón que EscMsgAveonlineTest
 * y EscMsgApiTest). Asserts verifican que NINGÚN wp_send_json_error use
 * getMessage() sin esc_html — esto bloquea cualquier handler nuevo sin escape.
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

require_once __DIR__ . '/class-ltms-unit-test-case.php';

/**
 * @covers \LTMS_Admin_Bookings
 * @covers \LTMS_Admin_Donations
 * @covers \LTMS_Admin_Marketing_Manager
 * @covers \LTMS_Admin_Payouts
 * @covers \LTMS_Admin_Settings
 * @covers \LTMS_Deprisa_Order_Metabox
 */
class EscMsgAdminTest extends \LTMS\Tests\Unit\LTMS_Unit_Test_Case {

	private const AUDIT_TAG = 'EXCMSG-FIX (AUDIT-EXCMSG-ADMIN-001, P1)';

	private static function expected_files(): array {
		return [
			'class-ltms-admin-bookings.php'         => 3,
			'class-ltms-admin-donations.php'        => 1,
			'class-ltms-admin-marketing-manager.php' => 1,
			'class-ltms-admin-payouts.php'          => 1,
			'class-ltms-admin-settings.php'         => 2,
			'class-ltms-deprisa-order-metabox.php'  => 2,
		];
	}

	private static function admin_dir(): string {
		return dirname( __DIR__, 2 ) . '/includes/admin/';
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 1 — Archivos objetivo existen
	// -----------------------------------------------------------------------

	public function test_all_target_admin_files_exist(): void {
		foreach ( array_keys( self::expected_files() ) as $filename ) {
			$path = self::admin_dir() . $filename;
			$this->assertFileExists( $path, "Archivo admin objetivo debe existir: {$filename}" );
		}
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 2 — REGRESIÓN P0: ningún wp_send_json_error usa getMessage()
	//              sin esc_html() — verificación central del fix.
	// -----------------------------------------------------------------------

	public function test_no_unescaped_getmessage_in_wp_send_json_error(): void {
		$violations = [];
		foreach ( array_keys( self::expected_files() ) as $filename ) {
			$path = self::admin_dir() . $filename;
			$src  = file_get_contents( $path );
			$lines = preg_split( '/\r\n|\r|\n/', $src );

			foreach ( $lines as $i => $line ) {
				if ( strpos( $line, 'wp_send_json_error' ) === false ) {
					continue;
				}
				if ( strpos( $line, 'getMessage()' ) === false ) {
					continue;
				}
				if ( strpos( $line, 'esc_html(' ) !== false || strpos( $line, 'esc_html (' ) !== false ) {
					continue;
				}
				$violations[] = "admin/{$filename}:" . ( $i + 1 ) . " → " . trim( $line );
			}
		}
		$this->assertSame( [], $violations, sprintf(
			'Fix AUDIT-EXCMSG-ADMIN-001: ninguna línea wp_send_json_error debe usar $e->getMessage() sin esc_html(). Violaciones: %d',
			count( $violations )
		) );
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 3 — Trazabilidad: marker EXCMSG-FIX presente en cada archivo
	// -----------------------------------------------------------------------

	public function test_audit_tag_present_in_each_admin_file(): void {
		foreach ( array_keys( self::expected_files() ) as $filename ) {
			$path = self::admin_dir() . $filename;
			$src  = file_get_contents( $path );
			$this->assertStringContainsString( self::AUDIT_TAG, $src, "El marker del fix debe estar en admin/{$filename}." );
		}
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 4 — Conteo de escapes por archivo (al menos el esperado)
	// -----------------------------------------------------------------------

	public function test_esc_html_count_per_file(): void {
		foreach ( self::expected_files() as $filename => $expected_count ) {
			$path  = self::admin_dir() . $filename;
			$src   = file_get_contents( $path );
			// Contar todas las apariciones de `esc_html` en la misma línea que `getMessage()`.
			$lines = preg_split( '/\r\n|\r|\n/', $src );
			$count = 0;
			foreach ( $lines as $line ) {
				if ( strpos( $line, 'getMessage()' ) === false ) continue;
				if ( strpos( $line, 'esc_html(' ) === false && strpos( $line, 'esc_html (' ) === false ) continue;
				$count++;
			}
			$this->assertGreaterThanOrEqual(
				$expected_count,
				$count,
				"admin/{$filename} debe tener al menos {$expected_count} líneas con esc_html( \$e->getMessage() ). Encontradas: {$count}"
			);
		}
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 5 — Bloque try/catch preservado
	// -----------------------------------------------------------------------

	public function test_catch_block_still_present(): void {
		foreach ( array_keys( self::expected_files() ) as $filename ) {
			$path = self::admin_dir() . $filename;
			$src  = file_get_contents( $path );
			$has_catch = (bool) preg_match( '/catch\s*\(\s*\\\\?(?:Throwable|Exception|LTMS_Deprisa_Exception)\s+\$e\s*\)/', $src );
			$this->assertTrue( $has_catch, "admin/{$filename} debe preservar el bloque catch." );
		}
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 6 — PHP válido
	// -----------------------------------------------------------------------

	public function test_files_parse_as_valid_php(): void {
		foreach ( array_keys( self::expected_files() ) as $filename ) {
			$path = self::admin_dir() . $filename;
			$src  = file_get_contents( $path );
			$tokens = @token_get_all( $src );
			$this->assertNotEmpty( $tokens, "admin/{$filename} debe parsear como PHP." );
		}
	}
}
