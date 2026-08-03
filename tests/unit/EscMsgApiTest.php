<?php
/**
 * EscMsgApiTest — Tests estructurales para AUDIT-EXCMSG-API-001 (sub-ciclo 2 de AUDIT-EXCMSG-001)
 *
 * Patrón detectado (P1, defense-in-depth): API clients que capturan
 * `\Throwable $e` y devuelven `return [ 'status' => 'error', 'message' => $e->getMessage() ]`
 * (o variantes 'error', 'error_message', 'descripcion') sin esc_html. Aunque la
 * salida inmediata NO sea wp_send_json_error, el array retornado se propaga por
 * la cadena caller → caller → handler → cliente (admin view o AJAX). Ejemplo
 * real: `LTMS_Api_Aveonline::health_check()` → resultado mostrado en
 * `class-ltms-admin-settings.php:486` → persistido en `lt_provider_health`
 * → mostrado en `html-admin-provider-health.php` via jQuery. Si en algún
 * punto de esa cadena el mensaje se renderiza via .html() en vez de .text(),
 * se produce XSS reflected.
 *
 * Fix aplicado: envolver `$e->getMessage()` con `esc_html()` en el ORIGEN
 * (defense-in-depth). Logs internos (Logger::error, error_log) se mantienen
 * sin esc_html.
 *
 * Alcance de este test (sub-ciclo API): 13 archivos en includes/api/ y
 * includes/api/gateways/ con instancias P1 en return arrays o wc_add_notice:
 *   - class-ltms-abstract-api-client.php        (1 instancia)
 *   - class-ltms-api-addi.php                    (1 instancia)
 *   - class-ltms-api-alegra.php                  (1 instancia)
 *   - class-ltms-api-aveonline.php              (1 instancia)
 *   - class-ltms-api-backblaze.php               (1 instancia)
 *   - class-ltms-api-heka.php                    (1 instancia)
 *   - class-ltms-api-openpay.php                 (1 instancia)
 *   - class-ltms-api-siigo.php                   (1 instancia)
 *   - class-ltms-api-stripe.php                  (17 instancias 'error' + 1 'message')
 *   - class-ltms-api-tptc.php                    (1 instancia)
 *   - class-ltms-api-uber.php                    (1 instancia)
 *   - class-ltms-api-xcover.php                  (1 instancia)
 *   - class-ltms-api-zapsign.php                 (1 instancia)
 *   - class-ltms-api-gateways.php                (3 wc_add_notice + 1 WP_Error)
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

require_once __DIR__ . '/class-ltms-unit-test-case.php';

/**
 * @covers \LTMS_Abstract_API_Client
 * @covers \LTMS_Api_Addi
 * @covers \LTMS_Api_Alegra
 * @covers \LTMS_Api_Aveonline
 * @covers \LTMS_Api_Backblaze
 * @covers \LTMS_Api_Heka
 * @covers \LTMS_Api_Openpay
 * @covers \LTMS_Api_Siigo
 * @covers \LTMS_Api_Stripe
 * @covers \LTMS_Api_Tptc
 * @covers \LTMS_Api_Uber
 * @covers \LTMS_Api_Xcover
 * @covers \LTMS_Api_Zapsign
 * @covers \LTMS_Api_Gateways
 */
class EscMsgApiTest extends \LTMS\Tests\Unit\LTMS_Unit_Test_Case {

	private const AUDIT_TAG = 'EXCMSG-FIX (AUDIT-EXCMSG-API-001, P1)';

	/**
	 * Mapa archivo → número mínimo esperado de instancias P1 fixeadas
	 * (algunos archivos pueden tener más ocurrencias del marker comment por
	 * comentarios múltiples — el assert usa >=).
	 */
	private static function expected_files(): array {
		return [
			'class-ltms-abstract-api-client.php'  => 1,
			'class-ltms-api-addi.php'             => 1,
			'class-ltms-api-alegra.php'           => 1,
			'class-ltms-api-aveonline.php'        => 1,
			'class-ltms-api-backblaze.php'        => 1,
			'class-ltms-api-heka.php'             => 1,
			'class-ltms-api-openpay.php'          => 1,
			'class-ltms-api-siigo.php'            => 1,
			'class-ltms-api-stripe.php'           => 18,
			'class-ltms-api-tptc.php'             => 1,
			'class-ltms-api-uber.php'             => 1,
			'class-ltms-api-xcover.php'           => 1,
			'class-ltms-api-zapsign.php'          => 1,
		];
	}

	private static function gateways_files(): array {
		return [
			'class-ltms-api-gateways.php' => 4, // 3 wc_add_notice + 1 WP_Error
		];
	}

	private static function api_dir(): string {
		return dirname( __DIR__, 2 ) . '/includes/api/';
	}

	private static function gateways_dir(): string {
		return dirname( __DIR__, 2 ) . '/includes/api/gateways/';
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 1 — Archivos objetivo existen
	// -----------------------------------------------------------------------

	public function test_all_target_api_files_exist(): void {
		foreach ( array_keys( self::expected_files() ) as $filename ) {
			$path = self::api_dir() . $filename;
			$this->assertFileExists( $path, "Archivo objetivo debe existir: api/{$filename}" );
		}
	}

	public function test_all_gateway_files_exist(): void {
		foreach ( array_keys( self::gateways_files() ) as $filename ) {
			$path = self::gateways_dir() . $filename;
			$this->assertFileExists( $path, "Archivo objetivo debe existir: api/gateways/{$filename}" );
		}
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 2 — REGRESIÓN P1: ningún return [...] array usa getMessage()
	//              SIN esc_html() en los API clients.
	// -----------------------------------------------------------------------

	public function test_no_unescaped_getmessage_in_return_arrays(): void {
		$violations = [];
		foreach ( array_keys( self::expected_files() ) as $filename ) {
			$path = self::api_dir() . $filename;
			$src  = file_get_contents( $path );
			$lines = preg_split( '/\r\n|\r|\n/', $src );

			foreach ( $lines as $i => $line ) {
				// Detectar `return [...` (return de array)
				if ( strpos( $line, 'return' ) === false || strpos( $line, '[' ) === false ) {
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
				$violations[] = "api/{$filename}:" . ( $i + 1 ) . " → " . trim( $line );
			}
		}
		$this->assertSame( [], $violations, sprintf(
			'Fix AUDIT-EXCMSG-API-001: ningún return array debe usar $e->getMessage() sin esc_html(). Violaciones: %d',
			count( $violations )
		) );
	}

	public function test_no_unescaped_getmessage_in_wc_add_notice_or_wp_error(): void {
		$violations = [];
		foreach ( array_keys( self::gateways_files() ) as $filename ) {
			$path = self::gateways_dir() . $filename;
			$src  = file_get_contents( $path );
			$lines = preg_split( '/\r\n|\r|\n/', $src );

			foreach ( $lines as $i => $line ) {
				// Detectar wc_add_notice o new WP_Error
				if ( strpos( $line, 'wc_add_notice' ) === false && strpos( $line, 'new WP_Error' ) === false ) {
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
				$violations[] = "api/gateways/{$filename}:" . ( $i + 1 ) . " → " . trim( $line );
			}
		}
		$this->assertSame( [], $violations, sprintf(
			'Fix AUDIT-EXCMSG-API-001: ninguna wc_add_notice/WP_Error debe usar $e->getMessage() sin esc_html(). Violaciones: %d',
			count( $violations )
		) );
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 3 — Trazabilidad: marker EXCMSG-FIX presente en cada archivo
	// -----------------------------------------------------------------------

	public function test_audit_tag_present_in_each_api_file(): void {
		foreach ( array_keys( self::expected_files() ) as $filename ) {
			$path = self::api_dir() . $filename;
			$src  = file_get_contents( $path );
			$this->assertStringContainsString( self::AUDIT_TAG, $src, "El marker del fix debe estar en api/{$filename}." );
		}
	}

	public function test_audit_tag_present_in_gateway_files(): void {
		foreach ( array_keys( self::gateways_files() ) as $filename ) {
			$path = self::gateways_dir() . $filename;
			$src  = file_get_contents( $path );
			$this->assertStringContainsString( self::AUDIT_TAG, $src, "El marker del fix debe estar en api/gateways/{$filename}." );
		}
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 4 — Conteo específico de escapes por archivo
	// -----------------------------------------------------------------------

	public function test_stripe_has_at_least_18_esc_html_getmessage(): void {
		$path = self::api_dir() . 'class-ltms-api-stripe.php';
		$src  = file_get_contents( $path );
		$count = substr_count( $src, 'esc_html( $e->getMessage()' );
		$this->assertGreaterThanOrEqual( 18, $count, "Stripe debe tener al menos 18 instancias de esc_html( \$e->getMessage() ). Encontradas: {$count}" );
	}

	public function test_gateways_has_at_least_4_esc_html_getmessage(): void {
		$path = self::gateways_dir() . 'class-ltms-api-gateways.php';
		$src  = file_get_contents( $path );
		$count = substr_count( $src, 'esc_html( $e->getMessage()' );
		$this->assertGreaterThanOrEqual( 4, $count, "Gateways debe tener al menos 4 instancias de esc_html( \$e->getMessage() ). Encontradas: {$count}" );
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 5 — PHP válido
	// -----------------------------------------------------------------------

	public function test_files_parse_as_valid_php(): void {
		$all_files = array_merge(
			array_keys( self::expected_files() ),
			array_keys( self::gateways_files() )
		);
		foreach ( $all_files as $filename ) {
			if ( isset( self::expected_files()[ $filename ] ) ) {
				$path = self::api_dir() . $filename;
			} else {
				$path = self::gateways_dir() . $filename;
			}
			$src    = file_get_contents( $path );
			$tokens = @token_get_all( $src );
			$this->assertNotEmpty( $tokens, "{$filename}: debe parsear como PHP." );
		}
	}
}
