<?php
/**
 * VtexCredsAuditTest — auditoría de la conexión VTEX con credenciales reales.
 *
 * Caso real auditado: el vendor "kosmetic" (cuenta dkosmetic) no podía conectar
 * con credenciales válidas. Diagnóstico:
 *   - "Probar conexión" leía credenciales SOLO de la DB → si el vendor no pulsaba
 *     "Guardar" primero, siempre fallaba con "No has configurado tus credenciales".
 *   - test_connection usaba el Search API PÚBLICO → nunca validaba el appToken.
 *   - accountName sin normalizar (URL completa / dominio myvtex → subdominio).
 *   - pick_field devolvía un array en el error 401 → mensaje "Array".
 *
 * Cubre (funcional + source-level):
 *   - normalize_account_name() (URL/dominio/email/plain).
 *   - test_connection() valida credenciales con endpoint autenticado (PVT category tree).
 *   - request() extrae mensaje anidado de error VTEX ({error:{message}}).
 *   - El handler AJAX de test persiste credenciales del formulario.
 *   - La vista enmascara appKey/appToken (sin caracteres en claro).
 *   - El JS envía los valores del formulario al probar conexión.
 *
 * @package LTMS\Tests\Unit
 *
 * Ejecutar con: ./vendor/bin/phpunit --group audit-vtex-creds
 *
 * @group audit-vtex-creds
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;

/**
 * Class VtexCredsAuditTest
 *
 * @group audit-vtex-creds
 */
final class VtexCredsAuditTest extends LTMS_Unit_Test_Case {

	private function plugin_path( string $relative ): string {
		return dirname( __DIR__, 2 ) . '/' . $relative;
	}

	private function src( string $relative ): string {
		$content = file_get_contents( $this->plugin_path( $relative ) );
		$this->assertIsString( $content, "Debe poder leerse {$relative}." );
		return $content;
	}

	/**
	 * Stubs HTTP usados por LTMS_Api_Vtex (mismo patrón que VtexFunctionalE2ETest).
	 */
	private function stub_http( callable $request_handler ): void {
		Monkey\Functions\when( 'wp_remote_request' )->alias( $request_handler );
		Monkey\Functions\when( 'is_wp_error' )->justReturn( false );
		Monkey\Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ): int { return (int) ( $response['response']['code'] ?? 0 ); }
		);
		Monkey\Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ): string { return (string) ( $response['body'] ?? '' ); }
		);
		Monkey\Functions\when( 'wp_remote_retrieve_header' )->alias(
			static function ( $response, string $header ): string { return (string) ( $response['headers'][ $header ] ?? '' ); }
		);
		Monkey\Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, string $url ): string { return $url . '?' . http_build_query( $args ); }
		);
	}

	private function search_payload(): array {
		return [ [ 'productId' => '1001', 'productName' => 'Jeans', 'items' => [ [ 'itemId' => '2001' ] ] ] ];
	}

	// ─────────────────────────────────────────────────────────────────────────
	// VTEX-CONN-003 — normalize_account_name()
	// ─────────────────────────────────────────────────────────────────────────

	public function test_normalize_account_name_extracts_subdomain(): void {
		$this->require_class( 'LTMS_Api_Vtex' );

		$this->assertSame( 'dkosmetic', \LTMS_Api_Vtex::normalize_account_name( 'dkosmetic' ) );
		$this->assertSame( 'dkosmetic', \LTMS_Api_Vtex::normalize_account_name( 'dkosmetic.myvtex.com' ) );
		$this->assertSame( 'dkosmetic', \LTMS_Api_Vtex::normalize_account_name( 'https://dkosmetic.myvtex.com' ) );
		$this->assertSame( 'dkosmetic', \LTMS_Api_Vtex::normalize_account_name( 'https://dkosmetic.vtexcommercestable.com.br/admin' ) );
		$this->assertSame( 'dkosmetic', \LTMS_Api_Vtex::normalize_account_name( 'www.dkosmetic.myvtex.com' ) );
		$this->assertSame( 'dkosmetic', \LTMS_Api_Vtex::normalize_account_name( 'DKOSMETIC.MYVTEX.COM' ), 'Case-insensitive.' );
		$this->assertSame( 'mistienda', \LTMS_Api_Vtex::normalize_account_name( 'mistienda' ) );
		$this->assertSame( 'dkosmetic', \LTMS_Api_Vtex::normalize_account_name( 'dkosmetic.com' ) );
	}

	public function test_normalize_account_name_does_not_guess_from_email(): void {
		$this->require_class( 'LTMS_Api_Vtex' );

		$normalized = \LTMS_Api_Vtex::normalize_account_name( 'someone@gmail.com' );
		$this->assertStringContainsString( '@', $normalized, 'Un email no se puede derivar a accountName — se deja para que la validación lo rechace.' );
		$this->assertNotSame( 'someone', $normalized );
	}

	public function test_normalize_account_name_empty(): void {
		$this->require_class( 'LTMS_Api_Vtex' );
		$this->assertSame( '', \LTMS_Api_Vtex::normalize_account_name( '' ) );
		$this->assertSame( '', \LTMS_Api_Vtex::normalize_account_name( '   ' ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// VTEX-CONN-002 — test_connection() valida credenciales con endpoint auth.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_test_connection_auth_probe_401_returns_clear_message(): void {
		$this->require_class( 'LTMS_Api_Vtex' );

		$calls = 0;
		$this->stub_http( static function () use ( &$calls ) {
			$calls++;
			return [ 'response' => [ 'code' => 401 ], 'body' => wp_json_encode( [ 'error' => [ 'message' => 'Acesso não autorizado' ] ] ) ];
		} );

		$r = \LTMS_Api_Vtex::test_connection( 'mistienda', 'k', 't' );

		$this->assertFalse( $r['success'], 'Con 401 en el probe de auth la conexión debe fallar.' );
		$this->assertStringContainsString( 'Credenciales inválidas', $r['message'], 'Debe mapear 401 a un mensaje claro en español.' );
		$this->assertSame( 1, $calls, 'Con 401 no debe llegar al Search API.' );
	}

	public function test_test_connection_validates_auth_then_counts_products(): void {
		$this->require_class( 'LTMS_Api_Vtex' );

		$calls = 0;
		$this->stub_http( function () use ( &$calls ) {
			$calls++;
			if ( 1 === $calls ) {
				return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( [] ) ]; // PVT category tree (auth).
			}
			return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( $this->search_payload() ) ]; // Search API.
		} );

		$r = \LTMS_Api_Vtex::test_connection( 'mistienda', 'k', 't' );

		$this->assertTrue( $r['success'] );
		$this->assertSame( 1, $r['products_count'] );
		$this->assertSame( 2, $calls, 'Debe llamar primero al probe de auth y luego al Search API.' );
	}

	public function test_test_connection_auth_ok_but_search_fails_still_success(): void {
		$this->require_class( 'LTMS_Api_Vtex' );

		$calls = 0;
		$this->stub_http( static function () use ( &$calls ) {
			$calls++;
			if ( 1 === $calls ) {
				return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( [] ) ];
			}
			return [ 'response' => [ 'code' => 500 ], 'body' => wp_json_encode( [ 'message' => 'boom' ] ) ];
		} );

		$r = \LTMS_Api_Vtex::test_connection( 'mistienda', 'k', 't' );

		$this->assertTrue( $r['success'], 'Si las credenciales son válidas pero el search falla, la conexión sigue siendo válida.' );
		$this->assertSame( 0, $r['products_count'] );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// VTEX-CONN-005 — request() extrae el mensaje anidado de error VTEX.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_request_extracts_nested_vtex_error_message(): void {
		$this->require_class( 'LTMS_Api_Vtex' );

		$this->stub_http( static fn() => [ 'response' => [ 'code' => 401 ], 'body' => wp_json_encode( [ 'error' => [ 'code' => '1', 'message' => 'Acesso não autorizado' ] ] ) ] );

		$r = \LTMS_Api_Vtex::request( 'mistienda', 'k', 't', '/api/catalog_system/pvt/category/tree/1' );

		$this->assertFalse( $r['success'] );
		$this->assertSame( 'Acesso não autorizado', $r['error'], 'Debe extraer el mensaje anidado (no un array "Array").' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Source-level — handlers, vista y JS.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_dashboard_test_handler_persists_form_credentials(): void {
		$src = $this->src( 'includes/frontend/class-ltms-dashboard-logic.php' );

		$this->assertStringContainsString( 'private function persist_vtex_credentials(', $src, 'Debe existir el método compartido de persistencia.' );
		$this->assertStringContainsString( 'normalize_account_name', $src, 'La persistencia debe normalizar el accountName.' );
		$this->assertStringContainsString( "'account_name' => sanitize_text_field( wp_unslash( \$_POST['account_name'] ?? '' ) )", $src, 'El test handler debe leer las credenciales del formulario.' );
		$this->assertStringContainsString( 'persist_vtex_credentials( $user_id, $form )', $src, 'El test handler debe persistir antes de probar.' );
		$this->assertStringContainsString( 'AppKey y AppToken deben pegarse juntos', $src, 'Mensaje claro si solo llega una de las dos credenciales.' );
	}

	public function test_api_test_connection_uses_auth_probe(): void {
		$src = $this->src( 'includes/api/class-ltms-api-vtex.php' );

		$this->assertStringContainsString( '/api/catalog_system/pvt/category/tree/1', $src, 'test_connection debe usar un endpoint autenticado como probe.' );
		$this->assertStringContainsString( 'Credenciales inválidas', $src, 'Debe mapear 401/403 a un mensaje claro.' );
		$this->assertStringContainsString( 'public static function normalize_account_name', $src, 'Debe existir el normalizador de accountName.' );
	}

	public function test_view_masks_credentials(): void {
		$src = $this->src( 'includes/frontend/views/view-vtex.php' );

		$this->assertStringNotContainsString( "substr( \$creds['app_key']", $src, 'La vista no debe revelar el prefijo del appKey.' );
		$this->assertStringNotContainsString( "substr( \$creds['app_token']", $src, 'La vista no debe revelar el prefijo del appToken.' );
		$this->assertStringContainsString( 'vtexappkey-••••••••', $src, 'Debe mostrar un placeholder enmascarado.' );
	}

	public function test_js_test_button_sends_form_values(): void {
		$src = $this->src( 'assets/js/ltms-vtex.js' );

		$this->assertStringContainsString( "account_name: $('#ltms-vtex-account-name').val()", $src, 'El botón Probar conexión debe enviar el accountName del formulario.' );
		$this->assertStringContainsString( "app_token: $('#ltms-vtex-app-token').val()", $src, 'Debe enviar el appToken del formulario.' );
	}
}