<?php
/**
 * StorefrontFiltersMobileTest - STORE-FILTERS-MOBILE (2026-09-12).
 *
 * El sidebar de filtros de la vitrina del vendedor (class-ltms-vendor-storefront.php)
 * se ocultaba en móvil (`display:none`) pero no existía ningún botón para abrirlo:
 * el JS ya escuchaba `#ltms-sf-sidebar-toggle` pero ese elemento no estaba en el markup.
 *
 * Se añade: un toggle "Filtros" en la barra superior (solo móvil), una cabecera
 * "Filtros" + botón cerrar dentro del drawer, un overlay, y el drawer slide-in
 * desde la izquierda (transform:translateX) — paridad con el slide-in de /tienda/.
 *
 * Tests source-based (file_get_contents + asserts), deterministas en
 * LTMS_UNIT_ONLY=true.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class StorefrontFiltersMobileTest
 *
 * Ejecutar con: LTMS_UNIT_ONLY=true ./vendor/bin/phpunit --group audit-storefront-filters
 *
 * @group audit-storefront-filters
 */
final class StorefrontFiltersMobileTest extends LTMS_Unit_Test_Case {

	private const PHP_PATH = __DIR__ . '/../../includes/frontend/class-ltms-vendor-storefront.php';
	private const CSS_PATH = __DIR__ . '/../../assets/css/ltms-storefront.css';
	private const CSS_MIN  = __DIR__ . '/../../assets/css/ltms-storefront.min.css';
	private const JS_PATH  = __DIR__ . '/../../assets/js/ltms-storefront.js';
	private const JS_MIN   = __DIR__ . '/../../assets/js/ltms-storefront.min.js';

	public function test_toggle_button_present_in_markup(): void {
		$src = file_get_contents( self::PHP_PATH );

		$this->assertStringContainsString(
			'id="ltms-sf-sidebar-toggle"',
			$src,
			'STORE-FILTERS-MOBILE: debe existir el botón toggle de filtros (el JS ya lo escuchaba).'
		);
		$this->assertStringContainsString(
			'class="ltms-sf-sidebar-toggle"',
			$src,
			'STORE-FILTERS-MOBILE: el toggle debe tener la clase para estilarse solo en móvil.'
		);
	}

	public function test_drawer_head_and_overlay_present(): void {
		$src = file_get_contents( self::PHP_PATH );

		$this->assertStringContainsString(
			'ltms-sf-sidebar-head',
			$src,
			'STORE-FILTERS-MOBILE: el drawer debe tener cabecera con título "Filtros".'
		);
		$this->assertStringContainsString(
			'id="ltms-sf-sidebar-close"',
			$src,
			'STORE-FILTERS-MOBILE: debe existir el botón cerrar del drawer.'
		);
		$this->assertStringContainsString(
			'id="ltms-sf-sidebar-overlay"',
			$src,
			'STORE-FILTERS-MOBILE: debe existir el overlay del drawer.'
		);
		$this->assertStringContainsString(
			'STORE-FILTERS-MOBILE',
			$src,
			'STORE-FILTERS-MOBILE: la traza del fix debe estar en el markup.'
		);
	}

	public function test_css_has_slide_in_drawer(): void {
		$css = file_get_contents( self::CSS_PATH );

		$this->assertStringContainsString(
			'STORE-FILTERS-MOBILE',
			$css,
			'STORE-FILTERS-MOBILE: el fix debe tener su marcador traceable en ltms-storefront.css.'
		);
		$this->assertStringContainsString(
			'.ltms-sf-sidebar-toggle',
			$css,
			'STORE-FILTERS-MOBILE: debe existir el estilo del toggle.'
		);
		$this->assertStringContainsString(
			'.ltms-sf-sidebar-head',
			$css,
			'STORE-FILTERS-MOBILE: debe existir el estilo de la cabecera del drawer.'
		);
		$this->assertStringContainsString(
			'transform: translateX(-100%)',
			$css,
			'STORE-FILTERS-MOBILE: el drawer debe deslizarse desde la izquierda (translateX).'
		);
	}

	public function test_js_has_close_and_overlay_handlers(): void {
		$js = file_get_contents( self::JS_PATH );

		$this->assertStringContainsString(
			'sfSetSidebar',
			$js,
			'STORE-FILTERS-MOBILE: el JS debe tener el helper sfSetSidebar (abrir/cerrar + overlay + scroll lock).'
		);
		$this->assertStringContainsString(
			'#ltms-sf-sidebar-close',
			$js,
			'STORE-FILTERS-MOBILE: el JS debe cerrar el drawer con el botón cerrar.'
		);
		$this->assertStringContainsString(
			'#ltms-sf-sidebar-overlay',
			$js,
			'STORE-FILTERS-MOBILE: el JS debe cerrar el drawer al pulsar el overlay.'
		);
	}

	public function test_min_assets_regenerated(): void {
		$css = file_get_contents( self::CSS_MIN );
		$js  = file_get_contents( self::JS_MIN );

		$this->assertStringContainsString( '.ltms-sf-sidebar-toggle', $css, 'STORE-FILTERS-MOBILE: el .min.css debe regenerarse con el toggle.' );
		// En el .min.js, `sfSetSidebar` se minifica (renombrada por terser), así que se
		// valida por los selectores/string literales que sí sobreviven a la minificación.
		$this->assertStringContainsString( '#ltms-sf-sidebar-close', $js, 'STORE-FILTERS-MOBILE: el .min.js debe cerrar el drawer con el botón cerrar.' );
		$this->assertStringContainsString( 'ltms-storefront-filters-open', $js, 'STORE-FILTERS-MOBILE: el .min.js debe bloquear el scroll del body al abrir.' );
	}
}