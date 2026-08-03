<?php
/**
 * EscMsgBookingOthersTest — Tests estructurales para AUDIT-EXCMSG-BIZ/BOOK/SET-001 (sub-ciclo 5)
 *
 * Sub-ciclo que cubre los archivos restantes con getMessage() destined a cliente
 * o admin (wp_send_json_error, WP_Error, return arrays con 'error'/'reason'):
 *   - business/ no-AVE (tourism-compliance, zapsign-manager, donation-certificate, donation-manager)
 *   - booking/ (booking-manager)
 *   - settings/ y deprisa/ (settings-deprisa — 2 copias idénticas en /settings/ y /deprisa/)
 *
 * Alcance (7 archivos, 10 instancias):
 *   - class-ltms-business-tourism-compliance.php : 1 wp_send_json_error (L455)
 *   - class-ltms-zapsign-manager.php             : 2 wp_send_json_error (L395, L426) + 2 return arrays (L473, L551)
 *   - class-ltms-donation-certificate.php        : 1 WP_Error (L139)
 *   - class-ltms-donation-manager.php            : 2 WP_Error (L263, L650)
 *   - class-ltms-booking-manager.php             : 2 WP_Error (L227, L386)
 *   - settings/class-ltms-settings-deprisa.php   : 1 wp_send_json_error (L432)
 *   - deprisa/class-ltms-settings-deprisa.php    : 1 wp_send_json_error (L432)
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

require_once __DIR__ . '/class-ltms-unit-test-case.php';

/**
 * @covers \LTMS_Business_Tourism_Compliance
 * @covers \LTMS_Zapsign_Manager
 * @covers \LTMS_Donation_Certificate
 * @covers \LTMS_Donation_Manager
 * @covers \LTMS_Booking_Manager
 * @covers \LTMS_Settings_Deprisa
 */
class EscMsgBookingOthersTest extends \LTMS\Tests\Unit\LTMS_Unit_Test_Case {

	private const AUDIT_TAGS = [
		'EXCMSG-FIX (AUDIT-EXCMSG-BIZ-001,',
		'EXCMSG-FIX (AUDIT-EXCMSG-BOOK-001,',
		'EXCMSG-FIX (AUDIT-EXCMSG-SET-001,',
	];

	/**
	 * Mapa path-relativo → número mínimo esperado de escapes.
	 */
	private static function expected_files(): array {
		return [
			'business/class-ltms-business-tourism-compliance.php' => 1,
			'business/class-ltms-zapsign-manager.php'             => 4,
			'business/class-ltms-donation-certificate.php'        => 1,
			'business/class-ltms-donation-manager.php'            => 2,
			'booking/class-ltms-booking-manager.php'              => 2,
			'settings/class-ltms-settings-deprisa.php'             => 1,
			'deprisa/class-ltms-settings-deprisa.php'              => 1,
		];
	}

	private static function includes_dir(): string {
		return dirname( __DIR__, 2 ) . '/includes/';
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 1 — Archivos objetivo existen
	// -----------------------------------------------------------------------

	public function test_all_target_files_exist(): void {
		foreach ( array_keys( self::expected_files() ) as $rel ) {
			$path = self::includes_dir() . $rel;
			$this->assertFileExists( $path, "Archivo objetivo debe existir: includes/{$rel}" );
		}
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 2 — REGRESIÓN P0/P1: ningún wp_send_json_error ni WP_Error usa
	//              getMessage() sin esc_html() — verificación central.
	// -----------------------------------------------------------------------

	public function test_no_unescaped_getmessage_in_wp_send_json_error_or_wp_error(): void {
		$violations = [];
		foreach ( array_keys( self::expected_files() ) as $rel ) {
			$path = self::includes_dir() . $rel;
			$src  = file_get_contents( $path );
			$lines = preg_split( '/\r\n|\r|\n/', $src );

			foreach ( $lines as $i => $line ) {
				// Detectar wp_send_json_error o new WP_Error
				$is_response_line = strpos( $line, 'wp_send_json_error' ) !== false
					|| preg_match( '/new\s+\\\\?WP_Error\s*\(/', $line );
				if ( ! $is_response_line ) {
					continue;
				}
				// Buscar en la misma línea y hasta 3 siguientes (multi-línea).
				$window = implode( ' ', array_slice( $lines, $i, 4 ) );
				if ( strpos( $window, 'getMessage()' ) === false ) {
					continue;
				}
				if ( strpos( $window, 'esc_html(' ) !== false || strpos( $window, 'esc_html (' ) !== false ) {
					continue;
				}
				$violations[] = "includes/{$rel}:" . ( $i + 1 ) . " → " . trim( $line );
			}
		}
		$this->assertSame( [], $violations, sprintf(
			'Fix AUDIT-EXCMSG-BIZ/BOOK/SET-001: ninguna línea wp_send_json_error/WP_Error con getMessage() debe estar sin esc_html(). Violaciones: %d',
			count( $violations )
		) );
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 3 — REGRESIÓN: return [ 'error' => $e->getMessage() ] y
	//              'reason' => '...' . $e->getMessage() — tampoco sin esc_html.
	// -----------------------------------------------------------------------

	public function test_no_unescaped_getmessage_in_return_arrays(): void {
		$violations = [];
		foreach ( array_keys( self::expected_files() ) as $rel ) {
			$path = self::includes_dir() . $rel;
			$src  = file_get_contents( $path );
			$lines = preg_split( '/\r\n|\r|\n/', $src );

			foreach ( $lines as $i => $line ) {
				// Detectar `return [` o arrays con clave 'error'/'reason'+'getMessage'
				if ( strpos( $line, 'return' ) === false && strpos( $line, '=>' ) === false ) {
					continue;
				}
				if ( strpos( $line, 'getMessage()' ) === false ) {
					continue;
				}
				if ( strpos( $line, 'esc_html(' ) !== false || strpos( $line, 'esc_html (' ) !== false ) {
					continue;
				}
				// Filtrar los que son return [ ... 'error'/'reason' => ... ]
				if ( preg_match( '/[\x27\x22](?:error|reason|message)[\x27\x22]\s*=>.*getMessage\(\)/', $line ) ) {
					$violations[] = "includes/{$rel}:" . ( $i + 1 ) . " → " . trim( $line );
				}
			}
		}
		$this->assertSame( [], $violations, sprintf(
			'Fix AUDIT-EXCMSG-BIZ/BOOK/SET-001: ningún return array con getMessage debe estar sin esc_html(). Violaciones: %d',
			count( $violations )
		) );
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 4 — Trazabilidad: al menos un marker EXCMSG-FIX en cada archivo
	// -----------------------------------------------------------------------

	public function test_audit_tag_present_in_each_file(): void {
		foreach ( array_keys( self::expected_files() ) as $rel ) {
			$path = self::includes_dir() . $rel;
			$src  = file_get_contents( $path );
			$found = false;
			foreach ( self::AUDIT_TAGS as $tag ) {
				if ( strpos( $src, $tag ) !== false ) {
					$found = true;
					break;
				}
			}
			$this->assertTrue( $found, "Algún marker EXCMSG-FIX debe estar en includes/{$rel}." );
		}
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 5 — Conteo de escapes por archivo (al menos el esperado)
	// -----------------------------------------------------------------------

	public function test_esc_html_count_per_file(): void {
		foreach ( self::expected_files() as $rel => $expected_count ) {
			$path = self::includes_dir() . $rel;
			$src  = file_get_contents( $path );
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
				"includes/{$rel} debe tener al menos {$expected_count} líneas con esc_html( getMessage() ). Encontradas: {$count}"
			);
		}
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 6 — catch preservado y PHP válido
	// -----------------------------------------------------------------------

	public function test_catch_block_still_present(): void {
		foreach ( array_keys( self::expected_files() ) as $rel ) {
			$path = self::includes_dir() . $rel;
			$src  = file_get_contents( $path );
			$has_catch = (bool) preg_match( '/catch\s*\(\s*\\\\?(?:Throwable|Exception|LTMS_Deprisa_Exception)\s+\$e\s*\)/', $src );
			$this->assertTrue( $has_catch, "includes/{$rel} debe preservar el bloque catch." );
		}
	}

	public function test_files_parse_as_valid_php(): void {
		foreach ( array_keys( self::expected_files() ) as $rel ) {
			$path  = self::includes_dir() . $rel;
			$src   = file_get_contents( $path );
			$tokens = @token_get_all( $src );
			$this->assertNotEmpty( $tokens, "includes/{$rel} debe parsear como PHP." );
		}
	}
}
