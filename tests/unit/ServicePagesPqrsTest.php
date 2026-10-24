<?php
/**
 * ServicePagesPqrsTest — SERVICE-PAGES-PQRS-2026-10-24.
 *
 * Hallazgos de la auditoría de header/footer global (19 páginas) que el
 * operador pidió cerrar ("soluciona los hallazgos, también lo del pqrs"):
 *
 *   1. La home enlazaba "Centro de ayuda" (/ayuda) y "Rastrear pedido"
 *      (/seguimiento) — páginas INEXISTENTES (404). El plugin ya trae los
 *      templates nativos (help-center.php, order-tracking.php) que se
 *      activan por slug (is_page('ayuda'|'seguimiento')).
 *   2. Los 4 enlaces "Legal" de la home apuntaban a /terminos, /privacidad,
 *      /cookies y /habeas-data — NINGUNO existe (las páginas reales son
 *      /terminos-y-condiciones-2/ #14524 y /politica-de-privacidad-2/
 *      #14525, registradas en ltms_installed_pages).
 *   3. El sitio NO tenía canal PQRS visible (requisito del Estatuto del
 *      Consumidor, Ley 1480/2011 art. 27 — el footer exhibe el sello CCCE).
 *      Solo existía el email pqrscolombia@ dentro del texto de la política
 *      de privacidad.
 *
 * Solución:
 *  - Publisher idempotente (deploy/ltms-publish-service-pages-2026-10-24.php)
 *    que crea las 5 páginas (ayuda, seguimiento, pqrs, politica-de-cookies,
 *    habeas-data) y registra las keys en ltms_installed_pages.
 *  - LTMS_Native_Templates::service_page_url() — resolución de URL por ID
 *    real con fallback; única fuente de verdad para home + help center.
 *  - home.php: Legal resuelto por página real + PQRS en Contacto.
 *  - help-center.php: 4ª card PQRS con SLA legal (15 días hábiles).
 *  - plaza-viva.css: channels-grid auto-fit (4 cards) + icono --brand.
 *
 * @package LTMS\Tests\Unit
 * @group service-pages-pqrs
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class ServicePagesPqrsTest
 *
 * @group service-pages-pqrs
 */
final class ServicePagesPqrsTest extends LTMS_Unit_Test_Case {

	private string $publisher_path;
	private string $native_templates_path;
	private string $home_path;
	private string $help_center_path;
	private string $pv_css_path;
	private string $pv_css_min_path;
	private string $webhook_path;

	protected function setUp(): void {
		parent::setUp();
		$this->publisher_path       = dirname( __DIR__, 2 ) . '/deploy/ltms-publish-service-pages-2026-10-24.php';
		$this->native_templates_path = dirname( __DIR__, 2 ) . '/includes/frontend/class-ltms-native-templates.php';
		$this->home_path            = dirname( __DIR__, 2 ) . '/includes/frontend/templates/home.php';
		$this->help_center_path    = dirname( __DIR__, 2 ) . '/includes/frontend/templates/help-center.php';
		$this->pv_css_path          = dirname( __DIR__, 2 ) . '/assets/css/ltms-plaza-viva.css';
		$this->pv_css_min_path      = dirname( __DIR__, 2 ) . '/assets/css/ltms-plaza-viva.min.css';
		$this->webhook_path         = dirname( __DIR__, 2 ) . '/deploy/ltms-deploy-webhook.php';
	}

	/**
	 * Helper: stripear los comentarios PHP de tipo slash-asterisco (LECCIONES #141).
	 */
	private function strip_php_comments( string $src ): string {
		return (string) preg_replace( '/\/\*.*?\*\//s', '', $src );
	}

	/**
	 * SERVICE-PAGES-A: el publisher crea las 5 páginas con los slugs EXACTOS
	 * que los templates nativos esperan (is_page('ayuda')/is_page('seguimiento')),
	 * es idempotente (update por get_page_by_path) y registra las keys en
	 * ltms_installed_pages sin pisar las existentes.
	 */
	public function test_001_publisher_paginas_y_slugs(): void {
		$this->assertFileExists( $this->publisher_path );
		$src = (string) file_get_contents( $this->publisher_path );

		// Los 5 slugs canónicos (los templates nativos dependen de ayuda/seguimiento).
		foreach ( array( 'ayuda', 'seguimiento', 'pqrs', 'politica-de-cookies', 'habeas-data' ) as $slug ) {
			$this->assertStringContainsString( "'{$slug}'", $src, "SERVICE-PAGES-A: el publisher debe crear la página /{$slug}/." );
		}
		// Idempotencia: update por get_page_by_path, nunca duplicados.
		$this->assertMatchesRegularExpression(
			'/get_page_by_path\( \$slug, OBJECT, \'page\' \)/',
			$src,
			'SERVICE-PAGES-A: el publisher debe resolver por get_page_by_path (idempotente — actualiza, no duplica).'
		);
		// Registro de keys sin pisar existentes (merge sobre la opción actual).
		$this->assertStringContainsString( "get_option( 'ltms_installed_pages', array() )", $src, 'SERVICE-PAGES-A: lee la opción actual antes de registrar (merge sin pisar).' );
		foreach ( array( 'ltms-help', 'ltms-tracking', 'ltms-pqrs', 'ltms-cookies', 'ltms-habeas-data' ) as $key ) {
			$this->assertStringContainsString( "'{$key}'", $src, "SERVICE-PAGES-A: el publisher debe registrar la key {$key} en ltms_installed_pages." );
		}
	}

	/**
	 * SERVICE-PAGES-B: el contenido PQRS del publisher cumple lo esencial del
	 * canal (art. 27 Ley 1480): SLA 15 días hábiles, canales reales y SIC.
	 */
	public function test_002_contenido_pqrs_cumplimiento(): void {
		$src = (string) file_get_contents( $this->publisher_path );

		$this->assertStringContainsString( '15 días hábiles', $src, 'SERVICE-PAGES-B: el SLA de respuesta debe ser 15 días hábiles (Ley 1480/2011 art. 27).' );
		$this->assertStringContainsString( 'pqrscolombia@lo-tengo.com.co', $src, 'SERVICE-PAGES-B: el canal PQRS usa el correo real del operador.' );
		$this->assertStringContainsString( 'sic.gov.co', $src, 'SERVICE-PAGES-B: la página PQRS debe informar la alternativa de la SIC.' );
		$this->assertStringContainsString( 'Ley 1480', $src, 'SERVICE-PAGES-B: el contenido cita el Estatuto del Consumidor.' );
	}

	/**
	 * SERVICE-PAGES-C: LTMS_Native_Templates::service_page_url() — resolución
	 * por ID real (publish) con fallback; única fuente de verdad. Además, los
	 * templates nativos de ayuda/seguimiento deben servirse TAMBIÉN con
	 * Elementor activo (hallazgo del deploy: las ramas vivían solo en el
	 * camino sin Elementor — inalcanzable en producción).
	 */
	public function test_003_service_page_url_en_native_templates(): void {
		$this->assertFileExists( $this->native_templates_path );
		$src = $this->strip_php_comments( (string) file_get_contents( $this->native_templates_path ) );

		$this->assertStringContainsString( 'public static function service_page_url( string $key, string $fallback ): string', $src, 'SERVICE-PAGES-C: el método estático debe existir en LTMS_Native_Templates (fuente única).' );
		$this->assertStringContainsString( "'publish' === get_post_status( \$page_id )", $src, 'SERVICE-PAGES-C: solo resuelve páginas publicadas (no borradores/papelera).' );
		$this->assertStringContainsString( 'return $fallback;', $src, 'SERVICE-PAGES-C: cae al fallback si no hay página registrada.' );

		// Las ramas de Elementor: extraer el bloque de la rama Elementor y
		// verificar que is_help_page/is_order_tracking_page están DENTRO
		// (antes de "return $template;" que cierra esa rama).
		if ( preg_match( '/did_action\( \'elementor\/loaded\' \) \) \{(.*?)return \$template;/s', $src, $m ) ) {
			$elementor_branch = $m[1];
			$this->assertStringContainsString( 'is_help_page()', $elementor_branch, 'SERVICE-PAGES-C: el template help-center.php debe servirse con Elementor activo (producción) — no solo en el camino sin Elementor.' );
			$this->assertStringContainsString( 'is_order_tracking_page()', $elementor_branch, 'SERVICE-PAGES-C: el template order-tracking.php debe servirse con Elementor activo (producción).' );
		} else {
			$this->fail( 'SERVICE-PAGES-C: no se encontró la rama Elementor de maybe_override para verificar el wiring de ayuda/seguimiento.' );
		}
	}

	/**
	 * SERVICE-PAGES-D: home.php — enlaces legales por página REAL y canal PQRS
	 * visible en Contacto (mailto de emergencia).
	 */
	public function test_004_home_legal_por_id_y_pqrs_en_contacto(): void {
		$src = $this->strip_php_comments( (string) file_get_contents( $this->home_path ) );

		// Legal: resuelto por service_page_url con las keys reales.
		foreach ( array(
			"service_page_url( 'ltms-terms'"        => 'términos',
			"service_page_url( 'ltms-privacy'"       => 'privacidad',
			"service_page_url( 'ltms-cookies'"       => 'cookies',
			"service_page_url( 'ltms-habeas-data'"   => 'hábeas data',
		) as $needle => $label ) {
			$this->assertStringContainsString(
				$needle,
				$src,
				"SERVICE-PAGES-D: el enlace Legal de {$label} debe resolver por la página real (antes /… inexistente → 404)."
			);
		}
		// PQRS en Contacto con fallback al correo del canal.
		$this->assertStringContainsString(
			"service_page_url( 'ltms-pqrs', 'mailto:pqrscolombia@lo-tengo.com.co' )",
			$src,
			'SERVICE-PAGES-D: el grupo Contacto debe exhibir el canal PQRS (página real con fallback al correo).'
		);
		$this->assertStringContainsString( 'PQRS', $src, 'SERVICE-PAGES-D: el label PQRS debe ser visible en la home.' );
	}

	/**
	 * SERVICE-PAGES-E: help-center.php — 4ª card PQRS con SLA legal y
	 * resolución por página real.
	 */
	public function test_005_help_center_card_pqrs(): void {
		$src = $this->strip_php_comments( (string) file_get_contents( $this->help_center_path ) );

		$this->assertStringContainsString( "pv-help__channel-icon--brand", $src, 'SERVICE-PAGES-E: la card PQRS usa la variante de icono de marca.' );
		$this->assertStringContainsString( '15 días hábiles', $src, 'SERVICE-PAGES-E: la card PQRS informa el SLA legal (Ley 1480 art. 27).' );
		$this->assertStringContainsString(
			"service_page_url( 'ltms-pqrs', 'mailto:pqrscolombia@lo-tengo.com.co' )",
			$src,
			'SERVICE-PAGES-E: el CTA de PQRS resuelve la página real con fallback al correo.'
		);
		$this->assertStringContainsString( 'Radica tu PQRS', $src, 'SERVICE-PAGES-E: el CTA de la card PQRS.' );
	}

	/**
	 * SERVICE-PAGES-F: plaza-viva.css — grid de canales auto-fit (4 cards)
	 * + variante --brand, sincronizado con el .min.css.
	 */
	public function test_006_css_grid_autofit_y_brand(): void {
		$css = (string) file_get_contents( $this->pv_css_path );

		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-help \.pv-help__channels-grid\{\s*\n\s*display:grid;grid-template-columns:repeat\(auto-fit,minmax\(240px,1fr\)\);/',
			$css,
			'SERVICE-PAGES-F: el grid de canales es auto-fit (la 4ª card PQRS fluye sin huérfanas).'
		);
		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-help \.pv-help__channel-icon--brand\{[^}]*color:var\(--brand\);\}/',
			$css,
			'SERVICE-PAGES-F: la variante --brand del icono usa el rojo de marca.'
		);
		// Sincronización .min.css.
		$min = (string) file_get_contents( $this->pv_css_min_path );
		$this->assertStringContainsString( 'auto-fit,minmax(240px,1fr)', $min, 'SERVICE-PAGES-F: el .min.css debe contener el grid auto-fit (regenerado).' );
		$this->assertStringContainsString( 'channel-icon--brand', $min, 'SERVICE-PAGES-F: el .min.css debe contener la variante --brand (regenerado).' );
	}

	/**
	 * SERVICE-PAGES-G: whitelist del deploy webhook (lección #177) — el
	 * publisher y este test viajan; home/help-center/plaza-viva/native-templates
	 * ya estaban.
	 */
	public function test_007_whitelist_webhook(): void {
		$webhook = (string) file_get_contents( $this->webhook_path );

		$this->assertStringContainsString( "'deploy/ltms-publish-service-pages-2026-10-24.php',", $webhook, 'SERVICE-PAGES-G: el publisher debe estar en la whitelist del webhook.' );
		$this->assertStringContainsString( "'tests/unit/ServicePagesPqrsTest.php',", $webhook, 'SERVICE-PAGES-G: este test debe estar en la whitelist del webhook (lección #177).' );
		$this->assertStringContainsString( "'includes/frontend/templates/help-center.php',", $webhook, 'SERVICE-PAGES-G: help-center.php ya estaba en la whitelist (se vigila).' );
		$this->assertStringContainsString( "'includes/frontend/class-ltms-native-templates.php',", $webhook, 'SERVICE-PAGES-G: la clase native-templates ya estaba en la whitelist (se vigila).' );
	}
}
