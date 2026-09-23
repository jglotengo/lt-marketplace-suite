<?php
/**
 * WelcomeEmailAccessFlowTest - CICLO33-P1-AUTH-EMAIL-FLOW (2026-09-23).
 *
 * Reportado por el operador: un nuevo vendedor se registra, ingresa usuario y
 * contraseña CORRECTOS y no puede entrar al panel. Evidencia en logs (Sep 22-23):
 * vendor #246 registrado 17:33 con ltms_email_verified=0 → LOGIN_THROTTLE 7
 * intentos — el gate AUTH-01 (bloqueo de login sin email verificado, seguridad
 * intencional) rechazó cada intento y el throttle contó los rechazos.
 *
 * Causa raíz de la confusión: el email de bienvenida NO mostraba el username
 * generado (el registro lo crea automáticamente: firstnamelastname) ni el link
 * del login — y llevaba un CTA secundario "Iniciar verificación KYC" hacia
 * /verificacion-identidad/ (página que exige login) ANTES de que el vendor
 * verificara su email o accediera una sola vez. El vendor seguía el KYC, se
 * rebotaba al login, AUTH-01 lo bloqueaba: loop.
 *
 * Fix (email-welcome-vendor.php + class-ltms-public-auth-handler.php):
 *  - Caja "Tus datos de acceso" con el username + contraseña declarada + los 2
 *    pasos del acceso (verificar email → iniciar sesión con usuario o email).
 *  - CTA KYC prematuro eliminado; el KYC se marca como paso posterior al primer
 *    acceso (dentro del panel).
 *  - send_welcome_email pasa username + login_url al template; kyc_url eliminado
 *    (código muerto tras retirar el CTA).
 *
 * El gate AUTH-01 (seguridad) NO cambia: login sin email verificado sigue
 * bloqueado. Lo correcto es que el email sea el flujo de ACCESO, no KYC-first.
 *
 * Tests source-based (file_get_contents + asserts), deterministas en
 * LTMS_UNIT_ONLY=true.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

final class WelcomeEmailAccessFlowTest extends LTMS_Unit_Test_Case {

	private const TEMPLATE_PATH = __DIR__ . '/../../templates/emails/email-welcome-vendor.php';
	private const HANDLER_PATH  = __DIR__ . '/../../includes/frontend/class-ltms-public-auth-handler.php';

	public function test_template_shows_access_credentials_box(): void {
		$tpl = (string) file_get_contents( self::TEMPLATE_PATH );

		// La caja de datos de acceso con el username generado debe existir.
		$this->assertStringContainsString(
			'Tus datos de acceso',
			$tpl,
			'CICLO33-P1-AUTH-EMAIL-FLOW: el email debe mostrar los datos de acceso (el vendor no conocía su username).'
		);
		$this->assertStringContainsString(
			"class=\"access-username\"",
			$tpl,
			'CICLO33-P1-AUTH-EMAIL-FLOW: el username debe renderizarse en la caja de acceso.'
		);
		$this->assertStringContainsString(
			"\$data['username']",
			$tpl,
			'CICLO33-P1-AUTH-EMAIL-FLOW: el template debe consumir $data[username] del handler.'
		);
	}

	public function test_template_shows_two_step_access_flow_before_kyc(): void {
		$tpl   = (string) file_get_contents( self::TEMPLATE_PATH );
		$flow  = (int) strpos( (string) $tpl, 'access-box' );
		$kyc   = (int) strpos( (string) $tpl, 'Verificar Identidad (KYC)' );

		// La caja de acceso (username + pasos de login) debe aparecer ANTES de la
		// mención de KYC — el flujo correcto es acceso-primero, no KYC-first.
		$this->assertGreaterThan( 0, $flow, 'CICLO33-P1-AUTH-EMAIL-FLOW: la caja de acceso debe existir.' );
		$this->assertGreaterThan( 0, $kyc, 'CICLO33-P1-AUTH-EMAIL-FLOW: el paso KYC debe existir (marcado post-acceso).' );
		$this->assertLessThan(
			$kyc,
			$flow,
			'CICLO33-P1-AUTH-EMAIL-FLOW: los datos de acceso deben ir ANTES del KYC en el email.'
		);
		$this->assertStringContainsString(
			'Después de tu primer acceso',
			$tpl,
			'CICLO33-P1-AUTH-EMAIL-FLOW: el KYC debe marcarse como paso posterior al primer acceso.'
		);
	}

	public function test_premature_kyc_cta_removed(): void {
		$tpl = (string) file_get_contents( self::TEMPLATE_PATH );

		// El CTA secundario llevaba a /verificacion-identidad/ (página que exige
		// login) — el vendor sin email verificado no puede acceder: loop.
		$this->assertStringNotContainsString(
			'Iniciar verificación KYC',
			$tpl,
			'CICLO33-P1-AUTH-EMAIL-FLOW: el CTA KYC prematuro debe eliminarse del email de bienvenida.'
		);
		$this->assertStringNotContainsString(
			"\$data['kyc_url']",
			$tpl,
			'CICLO33-P1-AUTH-EMAIL-FLOW: kyc_url debe eliminarse del template (código muerto).'
		);
	}

	public function test_handler_passes_username_and_login_url(): void {
		$handler = (string) file_get_contents( self::HANDLER_PATH );

		$this->assertStringContainsString(
			"'username'      => \$user->user_login,",
			$handler,
			'CICLO33-P1-AUTH-EMAIL-FLOW: send_welcome_email debe pasar el username real al template.'
		);
		$this->assertStringContainsString(
			"'login_url'     => \$login_url,",
			$handler,
			'CICLO33-P1-AUTH-EMAIL-FLOW: send_welcome_email debe pasar el link del login al template.'
		);
		$this->assertStringNotContainsString(
			"'kyc_url'       => home_url( '/verificacion-identidad/' ),",
			$handler,
			'CICLO33-P1-AUTH-EMAIL-FLOW: kyc_url eliminado del $data (código muerto).'
		);
	}

	public function test_primary_cta_still_verifies_email(): void {
		$tpl = (string) file_get_contents( self::TEMPLATE_PATH );

		// El CTA principal sigue siendo la verificación de email (no se toca).
		$this->assertStringContainsString(
			'Verificar mi email e ir a mi Panel',
			$tpl,
			'CICLO33-P1-AUTH-EMAIL-FLOW: el CTA principal de verificación de email debe conservarse.'
		);
	}

	public function test_auth01_gate_intact(): void {
		$handler = (string) file_get_contents( self::HANDLER_PATH );

		// La seguridad NO cambia: el login sin email verificado sigue bloqueado.
		$this->assertStringContainsString(
			"if ( \$is_vendor && get_option( 'ltms_require_email_verification', 'yes' ) !== 'no' ) {",
			$handler,
			'CICLO33-P1-AUTH-EMAIL-FLOW: el gate AUTH-01 (login sin email verificado) debe seguir intacto.'
		);
		$this->assertStringContainsString(
			'Debes verificar tu email antes de iniciar sesión',
			$handler,
			'CICLO33-P1-AUTH-EMAIL-FLOW: el mensaje de bloqueo AUTH-01 debe conservarse.'
		);
	}
}
