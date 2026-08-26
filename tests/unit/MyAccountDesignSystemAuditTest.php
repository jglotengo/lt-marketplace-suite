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
	 * AUDIT-FE-UIUX3-MA-07 (P2): las animaciones jQuery (desvanecido del
	 * aviso y de la tarjeta cancelada) ignoraban la preferencia de
	 * movimiento reducido del usuario. Paridad con D-07 del ciclo 2.
	 */
	public function test_007_animaciones_respetan_movimiento_reducido(): void {
		$this->assertFileExists( $this->bookings_path );
		$src = file_get_contents( $this->bookings_path );

		// (1) La preferencia se consulta via matchMedia.
		$this->assertMatchesRegularExpression(
			'/matchMedia\(\s*[\'"]\(prefers-reduced-motion:\s*reduce\)[\'"]\s*\)/i',
			$src,
			'AUDIT-FE-UIUX3-MA-07 fix: falta consultar prefers-reduced-motion via matchMedia'
		);

		// (2) Ambos usos de desvanecido estan guardados por la bandera.
		$this->assertMatchesRegularExpression(
			'/reduceMotion\s*\?\s*\$n\.hide\(\)\s*:\s*\$n\.fadeOut\(/',
			$src,
			'AUDIT-FE-UIUX3-MA-07 fix: el aviso debe condicionar su desvanecido a la bandera'
		);
		$this->assertMatchesRegularExpression(
			'/if\s*\(\s*reduceMotion\s*\)\s*\{\s*\$card\.remove\(\);\s*\}\s*else\s*\{\s*\$card\.fadeOut\(/',
			$src,
			'AUDIT-FE-UIUX3-MA-07 fix: la tarjeta cancelada debe condicionar su desvanecido a la bandera'
		);
	}

	/**
	 * AUDIT-FE-UIUX3-MA-08 (backlog autorizado por producto): /mi-cuenta
	 * renderizaba con el tema porque el router apuntaba a un archivo
	 * inexistente. El template nativo debe existir, servir invitados con el
	 * form-login de WC, preservar TODOS los endpoints registrados
	 * (wc_get_account_menu_items incluye Mis Reservas y compliance turistico)
	 * y delegar el contenido a woocommerce_account_content sin reimplementar
	 * logica de negocio. Convenciones del DS: sin hojas ni scripts incrustados.
	 */
	public function test_008_template_nativo_mi_cuenta_estructura(): void {
		$tpl_path = dirname( __DIR__, 2 ) . '/includes/frontend/templates/my-account.php';
		$this->assertFileExists( $tpl_path );
		$tpl = file_get_contents( $tpl_path );

		// (1) Scope del design system y rama de invitados.
		foreach (
			array(
				'pv-scope pv-account',
				'myaccount/form-login.php',
				'is_user_logged_in()',
				"get_header( 'shop' )",
				"get_footer( 'shop' )",
			) as $needle ) {
				$this->assertStringContainsString(
					$needle,
					$tpl,
					'MA-08 fix: falta ' . $needle . ' en el template nativo de Mi Cuenta'
				);
		}

		// (2) Navegacion completa de endpoints con escape correcto.
		foreach (
			array(
				'wc_get_account_menu_items()',
				'esc_url( wc_get_account_endpoint_url( $pv_ep ) )',
				'esc_html( $pv_label )',
				'customer-logout',
			) as $needle ) {
				$this->assertStringContainsString(
					$needle,
					$tpl,
					'MA-08 fix: la navegacion debe listar todos los endpoints con salida escapada'
				);
		}

		// (3) Contenido delegado a WC (sin reimplementar logica).
		$this->assertStringContainsString(
			"do_action( 'woocommerce_account_content' )",
			$tpl,
			'MA-08 fix: el contenido del endpoint debe venir de woocommerce_account_content'
		);

		// (4) Convenciones DS: cero CSS/JS incrustados.
		$this->assertStringNotContainsString( '<style', $tpl, 'MA-08 fix: el template no debe incrustar estilos — viven en plaza-viva.css seccion 25' );
		$this->assertStringNotContainsString( '<script', $tpl, 'MA-08 fix: el template no debe incrustar scripts (CSP)' );

		// (5) El router sigue cableando el archivo (regresion de MA-08).
		$native = file_get_contents( dirname( __DIR__, 2 ) . '/includes/frontend/class-ltms-native-templates.php' );
		$this->assertStringContainsString(
			"'my-account.php'",
			$native,
			'MA-08 fix: el router debe seguir resolviendo is_account_page() al template nativo'
		);
	}

	/**
	 * AUDIT-FE-UIUX3-MA-08 (continuacion): los estilos del account viven en
	 * la seccion 25 de plaza-viva.css, sincronizados con el .min.css.
	 */
	public function test_009_css_seccion_25_account_sincronizado(): void {
		$css = file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/ltms-plaza-viva.css' );
		$css_min = file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/ltms-plaza-viva.min.css' );

		$this->assertMatchesRegularExpression(
			'/25\. MY ACCOUNT/',
			$css,
			'MA-08 fix: falta la seccion 25 MY ACCOUNT en plaza-viva.css'
		);
		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-account\{display:flex;flex-direction:column;gap:18px;padding:24px 0 48px;\}/',
			$css,
			'MA-08 fix: falta la regla raiz .pv-scope.pv-account'
		);

		// Min sincronizado (clean-css retira el punto y coma previo a la llave).
		$this->assertStringContainsString(
			'.pv-scope.pv-account{display:flex;flex-direction:column;gap:18px;padding:24px 0 48px}',
			$css_min,
			'MA-08 fix: ltms-plaza-viva.min.css desincronizado — correr npm run build:css'
		);

		// Rama de invitados presente en la fuente y navegacion movil en el min.
		$this->assertStringContainsString(
			'.pv-account__main--guest',
			$css,
			'MA-08 fix: falta el estilo de la rama de invitados para Mi Cuenta'
		);
		$this->assertStringContainsString(
			'@media (max-width:760px){.pv-scope.pv-account .pv-account__main:not(.pv-account__main--guest)',
			$css_min,
			'MA-08 fix: falta la navegacion horizontal de Mi Cuenta en movil'
		);
	}

	/**
	 * AUDIT-FE-UIUX3-MA-06 (P2): botones y paginacion carecian de estado
	 * de foco visible para navegacion por teclado (WCAG 2.4.7). Misma
	 * receta que D-03: outline solido --primary con offset 2px.
	 */
	public function test_006_foco_visible_en_controles(): void {
		$this->assertFileExists( $this->bookings_path );
		$src = file_get_contents( $this->bookings_path );

		foreach ( [ '.ltms-cb-btn:focus-visible', '.ltms-cb-page-btn:focus-visible' ] as $selector ) {
			$this->assertStringContainsString(
				$selector,
				$src,
				'AUDIT-FE-UIUX3-MA-06 fix: falta estado de foco visible para ' . $selector
			);
		}

		$this->assertMatchesRegularExpression(
			'/focus-visible\s*\{[^}]*outline\s*:\s*2px\s+solid\s+var\(--primary\)[^}]*outline-offset\s*:\s*2px/is',
			$src,
			'AUDIT-FE-UIUX3-MA-06 fix: el outline visible debe usar la receta D-03'
		);
	}

	/**
	 * AUDIT-FE-UIUX3-MA-04 (P2): los botones (~35px de alto) y las pills
	 * de paginacion (~31px) quedaban por debajo del minimo de target
	 * tactil de 44px establecido por D-06 en el ciclo 2.
	 */
	public function test_004_targets_tactiles_44px(): void {
		$this->assertFileExists( $this->bookings_path );
		$src = file_get_contents( $this->bookings_path );

		$this->assertMatchesRegularExpression(
			'/\.ltms-cb-btn\s*\{[^}]*min-height\s*:\s*44px/i',
			$src,
			'AUDIT-FE-UIUX3-MA-04 fix: .ltms-cb-btn debe garantizar 44px de alto tactil'
		);
		$this->assertMatchesRegularExpression(
			'/\.ltms-cb-page-btn\s*\{[^}]*min-height\s*:\s*44px[^}]*min-width\s*:\s*44px|\.ltms-cb-page-btn\s*\{[^}]*min-width\s*:\s*44px[^}]*min-height\s*:\s*44px/is',
			$src,
			'AUDIT-FE-UIUX3-MA-04 fix: .ltms-cb-page-btn debe garantizar area tactil de 44x44'
		);
	}

	/**
	 * AUDIT-FE-UIUX3-MA-02 (P1): la vista usaba iconografia emoji para
	 * funciones de interfaz (menu, rótulos de estado, empty state, CTA,
	 * avisos y botones). El estandar del design system desde D-17 es
	 * SVG stroke con currentColor; los rótulos de estado quedan como
	 * texto plano porque el significado lo comunica la variante del badge.
	 */
	public function test_002_sin_iconografia_emoji_svg_stroke_en_su_lugar(): void {
		$this->assertFileExists( $this->bookings_path );
		$src = file_get_contents( $this->bookings_path );

		$emojis = [
			"\u{1F3E8}", // hotel
			"\u{23F3}",  // reloj de arena
			"\u{2705}",  // check verde
			"\u{2611}",  // checkbox
			"\u{2717}",  // cruz
			"\u{1F50D}", // lupa
			"\u{1F4C4}", // documento
			"\u{26A0}\u{FE0F}", // advertencia
			"\u{26A0}",  // advertencia sin selector
		];
		foreach ( $emojis as $emoji ) {
			$this->assertStringNotContainsString(
				$emoji,
				$src,
				'AUDIT-FE-UIUX3-MA-02 fix: la iconografia emoji fue reemplazada por SVG stroke o texto'
			);
		}

		// Contrato positivo: los tres SVG funcionales siguen presentes.
		foreach (
			[
				'M3 21h18',           // edificio (header + empty state)
				'<circle cx="11" cy="11" r="8"/>', // lupa del CTA
				'M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z', // alerta
				'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z', // documento pedido
			] as $svg_signature ) {
			$this->assertStringContainsString(
				$svg_signature,
				$src,
				'AUDIT-FE-UIUX3-MA-02 fix: falta el SVG esperado en la vista'
			);
		}
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

		// (3b) Re-auditoria: el badge BASE lleva el neutral por defecto, para
		// que un estado desconocido nunca quede sin fondo (heredaba ese
		// rol el fallback del mapa retirado).
		$this->assertMatchesRegularExpression(
			'/\.ltms-cb-badge\s*\{[^}]*background\s*:\s*var\(--bg-2\)[^}]*color\s*:\s*var\(--text-2\)/is',
			$src,
			'AUDIT-FE-UIUX3-MA-05 fix: el badge base debe tener estilo neutro de respaldo'
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
