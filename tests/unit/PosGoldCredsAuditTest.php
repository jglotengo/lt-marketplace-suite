<?php
/**
 * PosGoldCredsAuditTest — auditoría de credenciales PosGold (mismo ciclo que VTEX-CREDS-AUDIT).
 *
 * Hallazgos cubiertos:
 *   - POSGOLD-001 (P1): get_vendor_credentials() sin try/catch en decrypt — un token
 *     corrupto o en texto plano legacy lanzaba InvalidArgumentException y crasheaba
 *     la sync. Mismo patrón QA-VTEX aplicado a VTEX.
 *   - POSGOLD-002 (P1): el botón "Probar conexión" no enviaba las credenciales del
 *     formulario (solo action+nonce) y el handler leía SOLO de la DB — mismo bug
 *     raíz VTEX-CONN-001. Ahora persiste el formulario antes de probar.
 *   - POSGOLD-003 (P2): la vista revelaba 20 chars del token descifrado en HTML.
 *   - POSGOLD-004 (P2): el subdomain no se normalizaba (URL/dominio goldpos.com.co
 *     pegado completo se rechazaba con error genérico). Ahora se extrae el subdominio
 *     (patrón VTEX-CONN-003).
 *
 * @package LTMS\Tests\Unit
 *
 * Ejecutar con: ./vendor/bin/phpunit --group audit-posgold
 *
 * @group audit-posgold
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;

/**
 * Class PosGoldCredsAuditTest
 *
 * @group audit-posgold
 */
final class PosGoldCredsAuditTest extends LTMS_Unit_Test_Case {

	private function plugin_path( string $relative ): string {
		return dirname( __DIR__, 2 ) . '/' . $relative;
	}

	private function src( string $relative ): string {
		$content = file_get_contents( $this->plugin_path( $relative ) );
		$this->assertIsString( $content, "Debe poder leerse {$relative}." );
		return $content;
	}

	/**
	 * Mapa de user_meta por (user_id => [key => value]).
	 */
	private function stub_user_meta( array $by_user ): void {
		Monkey\Functions\when( 'get_user_meta' )->alias(
			static function ( $user_id, $key = '', $single = false ) use ( $by_user ) {
				$user_id = (int) $user_id;
				if ( isset( $by_user[ $user_id ] ) && array_key_exists( $key, $by_user[ $user_id ] ) ) {
					return $by_user[ $user_id ][ $key ];
				}
				return '';
			}
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// POSGOLD-001 — get_vendor_credentials() con try/catch en decrypt.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_get_vendor_credentials_decrypts_valid_token(): void {
		$this->require_class( 'LTMS_PosGold_Sync' );

		$encrypted = \LTMS_Core_Security::encrypt( 'jwt-plain-123' );
		$this->assertNotSame( 'jwt-plain-123', $encrypted, 'El token debe ir cifrado a user_meta.' );

		$this->stub_user_meta( [
			7 => [
				'ltms_posgold_subdomain' => 'jugueteriataiwan',
				'ltms_posgold_token'     => $encrypted,
				'ltms_posgold_empresaid' => 2,
				'ltms_posgold_usuarioid' => 3,
				'ltms_posgold_bodegaid'  => 4,
			],
		] );

		$creds = \LTMS_PosGold_Sync::get_vendor_credentials( 7 );

		$this->assertTrue( $creds['configured'] );
		$this->assertSame( 'jwt-plain-123', $creds['token'], 'El token cifrado debe descifrarse.' );
		$this->assertSame( 'jugueteriataiwan', $creds['subdomain'] );
		$this->assertSame( 2, $creds['empresaid'] );
		$this->assertSame( 3, $creds['usuarioid'] );
		$this->assertSame( 4, $creds['bodegaid'] );
	}

	public function test_get_vendor_credentials_corrupt_token_does_not_crash(): void {
		$this->require_class( 'LTMS_PosGold_Sync' );

		// "token" en texto plano legacy (sin formato vN:iv:cipher) → decrypt() LANZA.
		// POSGOLD-001: el try/catch debe degradar al raw en vez de crashear.
		$this->stub_user_meta( [
			7 => [
				'ltms_posgold_subdomain' => 'jugueteriataiwan',
				'ltms_posgold_token'     => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.legacy-plain',
				'ltms_posgold_empresaid' => 1,
				'ltms_posgold_usuarioid' => 1,
				'ltms_posgold_bodegaid'  => 1,
			],
		] );

		$creds = \LTMS_PosGold_Sync::get_vendor_credentials( 7 );

		$this->assertTrue( $creds['configured'], 'Token plano legacy sigue siendo una credencial válida.' );
		$this->assertSame( 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.legacy-plain', $creds['token'], 'Debe degradar al raw sin crashear.' );
	}

	public function test_get_vendor_credentials_not_configured_when_missing(): void {
		$this->require_class( 'LTMS_PosGold_Sync' );

		$this->stub_user_meta( [ 7 => [] ] );

		$creds = \LTMS_PosGold_Sync::get_vendor_credentials( 7 );

		$this->assertFalse( $creds['configured'] );
		$this->assertSame( 1, $creds['empresaid'], 'Defaults cuando no hay meta.' );
		$this->assertSame( 1, $creds['usuarioid'] );
		$this->assertSame( 1, $creds['bodegaid'] );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// POSGOLD-004 — normalize_subdomain().
	// ─────────────────────────────────────────────────────────────────────────

	public function test_normalize_subdomain_extracts_slug(): void {
		$this->require_class( 'LTMS_Api_PosGold' );

		$this->assertSame( 'jugueteriataiwan', \LTMS_Api_PosGold::normalize_subdomain( 'jugueteriataiwan' ) );
		$this->assertSame( 'jugueteriataiwan', \LTMS_Api_PosGold::normalize_subdomain( 'JUGUETERIATAIWAN' ), 'Case-insensitive.' );
		$this->assertSame( 'jugueteriataiwan', \LTMS_Api_PosGold::normalize_subdomain( 'jugueteriataiwan.goldpos.com.co' ) );
		$this->assertSame( 'jugueteriataiwan', \LTMS_Api_PosGold::normalize_subdomain( 'https://jugueteriataiwan.goldpos.com.co' ) );
		$this->assertSame( 'jugueteriataiwan', \LTMS_Api_PosGold::normalize_subdomain( 'https://jugueteriataiwan.goldpos.com.co/admin' ) );
		$this->assertSame( 'jugueteriataiwan', \LTMS_Api_PosGold::normalize_subdomain( 'www.jugueteriataiwan.goldpos.com.co' ) );
		$this->assertSame( 'mistienda', \LTMS_Api_PosGold::normalize_subdomain( 'mistienda.com.co' ), 'Dominio arbitrario → primer label.' );
	}

	public function test_normalize_subdomain_does_not_guess_from_email(): void {
		$this->require_class( 'LTMS_Api_PosGold' );

		$normalized = \LTMS_Api_PosGold::normalize_subdomain( 'alguien@gmail.com' );
		$this->assertStringContainsString( '@', $normalized, 'Un email no se deriva a subdominio — la validación lo rechaza.' );
		$this->assertNotSame( 'alguien', $normalized );
	}

	public function test_normalize_subdomain_empty(): void {
		$this->require_class( 'LTMS_Api_PosGold' );
		$this->assertSame( '', \LTMS_Api_PosGold::normalize_subdomain( '' ) );
		$this->assertSame( '', \LTMS_Api_PosGold::normalize_subdomain( '   ' ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Source-level — handler de test, JS y vista.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_dashboard_test_handler_persists_form_credentials(): void {
		$src = $this->src( 'includes/frontend/class-ltms-dashboard-logic.php' );

		$this->assertStringContainsString( 'private function persist_posgold_credentials(', $src, 'Debe existir el método compartido de persistencia.' );
		$this->assertStringContainsString( 'normalize_subdomain', $src, 'La persistencia debe normalizar el subdomain.' );
		$this->assertStringContainsString( "'subdomain' => sanitize_text_field( wp_unslash( \$_POST['subdomain'] ?? '' ) )", $src, 'El test handler debe leer las credenciales del formulario.' );
		$this->assertStringContainsString( 'persist_posgold_credentials( $user_id, $form )', $src, 'El test handler debe persistir antes de probar.' );
		$this->assertStringContainsString( 'Completa el formulario y pulsa "Guardar credenciales"', $src, 'Mensaje claro si no hay credenciales.' );
	}

	public function test_js_test_button_sends_form_values(): void {
		$src = $this->src( 'assets/js/ltms-posgold.js' );

		$this->assertStringContainsString( "subdomain: $('#ltms-posgold-subdomain').val()", $src, 'El botón Probar conexión debe enviar el subdomain del formulario.' );
		$this->assertStringContainsString( "token: $('#ltms-posgold-token').val()", $src, 'Debe enviar el token del formulario.' );
	}

	public function test_view_masks_token(): void {
		$src = $this->src( 'includes/frontend/views/view-posgold.php' );

		$this->assertStringNotContainsString( "substr( \$creds['token']", $src, 'La vista no debe revelar caracteres del token descifrado.' );
		$this->assertStringNotContainsString( '$masked_token', $src, 'No debe computar un token enmascarado con caracteres reales.' );
		$this->assertStringContainsString( '••••••••••••••••••••••••', $src, 'Debe mostrar un placeholder enmascarado.' );
	}
}