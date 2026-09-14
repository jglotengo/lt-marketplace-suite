<?php
/**
 * RotarTokenGithubTest - ROTAR-TOKEN-GITHUB (2026-09-13).
 *
 * Higiene de credenciales:
 *  - `bin/deploy-and-test.ps1` tenía la contraseña SSH de SG en texto plano
 *    (`$SSH_PASS = '...'`) y la rama plink la usaba con `-pw`.
 *  - El `remote origin` de git embebía un PAT de GitHub (`ghp_...`) en la URL.
 *
 * Fix aplicado en este repo: el script de deploy ya no hardcodea la contraseña;
 * usa la llave privada local `id_ed25519` (verificada contra ssh.lo-tengo.com.co
 * con -o BatchMode=yes, sin password). La rotación efectiva del PAT de GitHub y
 * el cambio de la contraseña SSH en SG son acciones del dueño de las credenciales
 * (fuera del alcance del código).
 *
 * Tests source-based (file_get_contents + asserts), deterministas en
 * LTMS_UNIT_ONLY=true.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class RotarTokenGithubTest
 *
 * @group security
 */
final class RotarTokenGithubTest extends LTMS_Unit_Test_Case {

	private const DEPLOY_SCRIPT = __DIR__ . '/../../bin/deploy-and-test.ps1';

	public function test_deploy_script_does_not_hardcode_password(): void {
		$src = file_get_contents( self::DEPLOY_SCRIPT );

		$this->assertStringNotContainsString(
			'$SSH_PASS',
			$src,
			'ROTAR-TOKEN-GITHUB: el script de deploy no debe contener una contraseña SSH en texto plano ($SSH_PASS).'
		);
		$this->assertStringNotContainsString(
			'-pw',
			$src,
			'ROTAR-TOKEN-GITHUB: el script de deploy no debe usar el flag -pw de plink (password).'
		);
		$this->assertStringNotContainsString(
			'SSHPASS',
			$src,
			'ROTAR-TOKEN-GITHUB: el script de deploy no debe exportar la contraseña vía $env:SSHPASS.'
		);
	}

	public function test_deploy_script_uses_key_auth(): void {
		$src = file_get_contents( self::DEPLOY_SCRIPT );

		$this->assertStringContainsString(
			'ROTAR-TOKEN-GITHUB FIX',
			$src,
			'ROTAR-TOKEN-GITHUB: el fix debe tener su marcador traceable en deploy-and-test.ps1.'
		);
		$this->assertStringContainsString(
			'id_ed25519',
			$src,
			'ROTAR-TOKEN-GITHUB: el script de deploy debe referenciar la llave privada id_ed25519.'
		);
		$this->assertStringContainsString(
			'-o BatchMode=yes',
			$src,
			'ROTAR-TOKEN-GITHUB: el script de deploy debe usar BatchMode (sin prompt de password).'
		);
		$this->assertStringContainsString(
			'-i $SSH_KEY',
			$src,
			'ROTAR-TOKEN-GITHUB: el script de deploy debe pasar la llave con -i $SSH_KEY.'
		);
	}
}