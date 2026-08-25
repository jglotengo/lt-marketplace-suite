<?php
/**
 * Tests estructurales de la auditoria UI/UX de Mi Cuenta (Ciclo 3).
 *
 * Cubre los hallazgos MY-ACCOUNT-DS-CICLO3 sobre la unica superficie que
 * el plugin aporta hoy a /mi-cuenta: la extension "Mis Reservas"
 * (includes/frontend/class-ltms-frontend-customer-bookings.php), cuyo
 * markup/CSS/JS viaja inline en render_page().
 *
 * Los invariantes son estructurales sobre el source (file_get_contents +
 * asserts): NO cargan clases del plugin ni invocan WP → deterministas en
 * LTMS_UNIT_ONLY=true y CI Ubuntu (mismo patron que
 * PlazaVivaDesignSystemAuditTest y OrderTrackingAuditTest).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class MyAccountDesignSystemAuditTest
 *
 * Verifica los fixes AUDIT-FE-UIUX3-MA-* mediante invariantes del source
 * PHP que contiene la vista Mis Reservas. Detecta regresiones si alguien
 * reintroduce colores fuera del design system, transiciones comodin o
 * iconografia emoji.
 */
final class MyAccountDesignSystemAuditTest extends LTMS_Unit_Test_Case {

	/**
	 * Ruta absoluta a la extension Mis Reservas de Mi Cuenta.
	 */
	private string $bookings_path;

	/**
	 * @inheritDoc
	 *
	 * Igual que PlazaVivaDesignSystemAuditTest: sin require_class(), solo
	 * filesystem puro para ser determinista en UNIT_ONLY.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->bookings_path = dirname( __DIR__, 2 ) . '/includes/frontend/class-ltms-frontend-customer-bookings.php';
	}

	/**
	 * AUDIT-FE-UIUX3-MA-03 (P1): los botones de Mis Reservas usaban una
	 * transicion comodin que animaba TODAS las propiedades CSS (incluyendo
	 * layout como padding/width) — mismo patron prohibido por D-27 del
	 * ciclo 2 en checkout.css. La transicion debe declarar una lista
	 * explicita de propiedades visuales.
	 */
	public function test_003_botones_transicion_lista_explicita(): void {
		$this->assertFileExists( $this->bookings_path );
		$src = file_get_contents( $this->bookings_path );

		// (1) Ninguna regla CSS declara la transicion comodin.
		$this->assertDoesNotMatchRegularExpression(
			'/transition\s*:\s*all\b/i',
			$src,
			'AUDIT-FE-UIUX3-MA-03 fix: la vista Mis Reservas no debe declarar transiciones comodin'
		);

		// (2) .ltms-cb-btn declara transicion con lista explicita de propiedades visuales.
		$this->assertMatchesRegularExpression(
			'/\.ltms-cb-btn\s*\{[^}]*transition\s*:\s*(background|border-color|color)\b/i',
			$src,
			'AUDIT-FE-UIUX3-MA-03 fix: .ltms-cb-btn debe listar propiedades explicitas en su transicion'
		);
	}
}
