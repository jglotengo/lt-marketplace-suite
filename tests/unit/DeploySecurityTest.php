<?php
/**
 * DeploySecurityTest - DEPLOY-WH-CAPTCHA-2026-10-28.
 *
 * El deploy a produccion reportaba "success" sin desplegar: el webhook HTTP
 * respondia 202 + sgcaptcha (bot protection de SiteGround) a los runners de
 * GitHub y el workflow trataba 202 como exito. Ademas el webhook viejo del
 * webroot conservaba un PAT de GitHub partido en strings y el token del
 * webhook estaba hardcodeado en el repo.
 *
 * Este test fija las tres invariantes:
 *  1. El workflow despliega por SSH y falla si el SHA del servidor no es el
 *     commit pusheado (no mas verdes silenciosos).
 *  2. El webhook resuelve el token desde LTMS_DEPLOY_TOKEN (env/wp-config),
 *     sin literales hardcodeados ni PATs partidos.
 *  3. El nuevo deploy no persiste el PAT en .git/config del servidor.
 *
 * @package LTMS\Tests\Unit
 * @group deploy-security
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class DeploySecurityTest
 *
 * @group deploy-security
 */
final class DeploySecurityTest extends LTMS_Unit_Test_Case {

	private const ROOT     = __DIR__ . '/../..';
	private const WEBHOOK  = self::ROOT . '/deploy/ltms-deploy-webhook.php';
	private const WORKFLOW = self::ROOT . '/.github/workflows/deploy.yml';
	private const PV_HEADER = self::ROOT . '/includes/frontend/class-ltms-pv-header.php';

	public function test_001_webhook_no_tiene_token_hardcodeado(): void {
		$src = file_get_contents( self::WEBHOOK );

		$this->assertStringNotContainsString(
			"define('DEPLOY_TOKEN', '",
			$src,
			'DEPLOY-SEC: el token del webhook no puede volver a commitearse como literal.'
		);
		$this->assertDoesNotMatchRegularExpression(
			"/define\(\s*'DEPLOY_TOKEN'\s*,\s*'/",
			$src,
			'DEPLOY-SEC: DEPLOY_TOKEN debe resolverse en runtime, no con literal.'
		);
		$this->assertStringContainsString(
			'LTMS_DEPLOY_TOKEN',
			$src,
			'DEPLOY-SEC: el webhook debe leer LTMS_DEPLOY_TOKEN (env o wp-config).'
		);
		$this->assertStringContainsString(
			'wp-config.php',
			$src,
			'DEPLOY-SEC: sin constante definida, el webhook debe cargar wp-config.php.'
		);
	}

	public function test_002_webhook_sin_pat_de_github_partido(): void {
		$src = file_get_contents( self::WEBHOOK );

		$this->assertDoesNotMatchRegularExpression(
			"/'ghp_[A-Za-z0-9]{8,}'/",
			$src,
			'DEPLOY-SEC: ningun PAT de GitHub puede viajar hardcodeado en el webhook.'
		);
		$this->assertStringNotContainsString(
			'$gh_tok_a',
			$src,
			'DEPLOY-SEC: el patron de PAT partido en $gh_tok_a/$b/$c debe quedar eliminado.'
		);
	}

	public function test_003_workflow_despliega_por_ssh_y_falla_si_sha_no_coincide(): void {
		$src = file_get_contents( self::WORKFLOW );

		$this->assertStringContainsString(
			'Deploy via SSH',
			$src,
			'DEPLOY-SEC: el deploy debe ir por SSH, no por el webhook HTTP bloqueado por sgcaptcha.'
		);
		$this->assertStringContainsString(
			'git merge --ff-only origin/main',
			$src,
			'DEPLOY-SEC: el servidor debe avanzar al commit pusheado (sin reset destructivo).'
		);
		$this->assertStringContainsString(
			'DEPLOYED=$(git rev-parse HEAD)',
			$src,
			'DEPLOY-SEC: el workflow debe verificar el SHA realmente desplegado.'
		);
		$this->assertStringContainsString(
			'DEPLOY-FAILED: SHA mismatch',
			$src,
			'DEPLOY-SEC: un deploy que no llega al SHA esperado debe fallar el job.'
		);
		$this->assertStringNotContainsString(
			'ltms-deploy-webhook.php?token=',
			$src,
			'DEPLOY-SEC: el workflow no puede volver a depender del webhook HTTP.'
		);
		$this->assertStringNotContainsString(
			'Deploy triggered successfully',
			$src,
			'DEPLOY-SEC: el mensaje de exito viejo (202 = captcha) debe quedar eliminado.'
		);
	}

	public function test_004_workflow_no_persiste_el_pat_en_el_servidor(): void {
		$src = file_get_contents( self::WORKFLOW );

		$this->assertStringContainsString(
			'git remote set-url origin https://github.com/jglotengo/lt-marketplace-suite.git',
			$src,
			'DEPLOY-SEC: el remote del servidor debe quedar sin credenciales embebidas.'
		);
		$this->assertStringContainsString(
			'http.extraheader="Authorization: Basic $AUTH"',
			$src,
			'DEPLOY-SEC: el PAT debe viajar solo como header de git, no en .git/config.'
		);
		$this->assertStringContainsString(
			'LTMS_GH_TOKEN',
			$src,
			'DEPLOY-SEC: el token de git se resuelve desde LTMS_GH_TOKEN.'
		);
	}

	public function test_005_vitrina_conserva_favoritos_en_el_header_pv(): void {
		$src = file_get_contents( self::PV_HEADER );

		$this->assertStringContainsString(
			"apply_filters( 'ltms_wishlist_url', home_url( '/favoritos' ) )",
			$src,
			'VITRINA-WISHLIST: el header PV debe seguir exponiendo Favoritos.'
		);
		$this->assertStringContainsString(
			'data-pv-wishlist-count',
			$src,
			'VITRINA-WISHLIST: el badge de favoritos debe seguir en el header PV.'
		);
	}
}
