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
	 * AUDIT-FE-UIUX3-MA-01 (P1): el CSS de Mis Reservas traia su propia
	 * paleta hex desincronizada del design system (grises Tailwind, azul
	 * literal, ambar/rojo propios). Los tokens globales (--text*, --border*,
	 * --surface, --primary*, --danger-*, --warn-*) viven en :root de
	 * ltms-plaza-viva.css, que se encola en todo el frontend, asi que la
	 * vista debe consumirlos.
	 */
	public function test_001_css_estatico_sin_hex_fuera_del_design_system(): void {
		$this->assertFileExists( $this->bookings_path );
		$src = file_get_contents( $this->bookings_path );

		$banned = [
			'#111827', '#6b7280', '#e5e7eb', '#f3f4f6', '#9ca3af', // grises tailwind heredados
			'#f59e0b', '#10b981', '#2563eb',                       // mapa dinamico de estados (retirado con MA-05)
			'#fefce8', '#fde68a', '#92400e',                       // caja de reembolso
			'#fee2e2', '#991b1b', '#fecaca',                       // boton de cancelacion
			'#d1d5db', '#374151',                                  // outline / paginacion
			'#fef2f2', '#fca5a5', '#f0fdf4', '#86efac', '#166534', // notices
		];
		foreach ( $banned as $hex ) {
			$this->assertStringNotContainsString(
				$hex,
				$src,
				"AUDIT-FE-UIUX3-MA-01 fix: el hex {$hex} fue reemplazado por tokens del design system"
			);
		}

		// Contrato positivo: las reglas clave consumen tokens del DS.
		foreach ( [ 'var(--text)', 'var(--surface)', 'var(--border)', 'var(--warn-50)', 'var(--danger-700)', 'var(--accent-700)' ] as $token_use ) {
			$this->assertStringContainsString(
				$token_use,
				$src,
				'AUDIT-FE-UIUX3-MA-01 fix: falta consumo del token ' . $token_use . ' en la vista'
			);
		}
	}

	/**
	 * AUDIT-FE-UIUX3-MA-05 (P1): los badges de estado se pintaban con un
	 * estilo inline dinamico (mapa PHP de colores + alpha concatenado),
	 * sin clases modificadoras y sin garantia de contraste AA. Ahora el
	 * estado viaja en la clase ltms-cb-badge--{status} con la receta del DS
	 * (fondo -50 + texto -700), paridad con .pv-badge--*.
	 */
	public function test_005_badges_por_clase_sin_estilo_inline_dinamico(): void {
		$this->assertFileExists( $this->bookings_path );
		$src = file_get_contents( $this->bookings_path );

		// (1) El markup emite la clase modificacion por estado (contrato HTML ↔ CSS).
		$this->assertStringContainsString(
			'ltms-cb-badge--<?php echo esc_attr( $status ); ?>',
			$src,
			'AUDIT-FE-UIUX3-MA-05 fix: el badge debe emitir la clase modificadoras por estado'
		);

		// (2) Ya no hay estilo inline de color en el badge.
		$this->assertDoesNotMatchRegularExpression(
			'/ltms-cb-badge"[^>]*style=/i',
			$src,
			'AUDIT-FE-UIUX3-MA-05 fix: el badge no debe llevar atributo style inline'
		);

		// (3) Las cuatro variantes existen en el CSS con receta -50/-700.
		foreach ( [ 'pending' => 'warn', 'confirmed' => 'accent', 'completed' => 'primary' ] as $variant => $token ) {
			$this->assertMatchesRegularExpression(
				'/\.ltms-cb-badge--' . $variant . '\s*\{[^}]*var\(--' . $token . '-50\)[^}]*var\(--' . $token . '-700\)/is',
				$src,
				"AUDIT-FE-UIUX3-MA-05 fix: falta la variante --{$variant} con tokens {$token}"
			);
		}
		$this->assertMatchesRegularExpression(
			'/\.ltms-cb-badge--cancelled\s*\{[^}]*var\(--bg-2\)[^}]*var\(--text-2\)/is',
			$src,
			'AUDIT-FE-UIUX3-MA-05 fix: falta la variante --cancelled neutral'
		);

		// (4) El mapa PHP de colores fue eliminado por completo.
		$this->assertStringNotContainsString(
			'status_colors',
			$src,
			'AUDIT-FE-UIUX3-MA-05 fix: el mapa de colores dinámico debe estar eliminado'
		);
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
