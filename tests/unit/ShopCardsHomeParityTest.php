<?php
/**
 * ShopCardsHomeParityTest - SHOP-CARD-PARITY + SHOP-VENDOR-GATE (2026-09-06).
 *
 * El usuario reportó dos bugs en /tienda/:
 *  (1) las cards del shop se ven distintas a las del home — los estilos de
 *      card (imagen cuadrada, título clamp, precio rojo, botón outline)
 *      vivían en ltms-homepage-fixes.css scoped a .elementor-wc-products
 *      (wrapper del widget de Elementor del home); el shop usa .pv-shop
 *      (wrapper de archive-product.php) y no recibía esos estilos. Fix:
 *      ampliar el scope de los selectores CSS + del JS fixProductCardImages()
 *      para cubrir también .pv-shop.
 *  (2) el botón "Explorar productos" del carrito vacío llevaba a /tienda/
 *      que mostraba el gate "Debes iniciar sesión como vendedor" porque la
 *      página Tienda tenía el shortcode [ltms_vendor_store] en su contenido
 *      (renderizado vía woocommerce_archive_description). Fix defensivo en
 *      archive-product.php: se omite cualquier bloque .ltms-empty-state-login
 *      del archive description (además del fix de datos en SG).
 *  (3) el usuario reportó además: breadcrumb "Inicio / Tienda" repetido al
 *      comienzo y un espacio blanco donde debía ir la primera tarjeta. Fix:
 *      breadcrumb del theme removido del hook (evita duplicado) y
 *      ::before/::after del ul.products neutralizados (el clearfix de WC se
 *      convertía en grid item fantasma en la primera celda del grid).
 *
 * Tests source-based (patrón C20-C29): file_get_contents + asserts.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

final class ShopCardsHomeParityTest extends LTMS_Unit_Test_Case {

	private const CSS_PATH = __DIR__ . '/../../assets/css/ltms-homepage-fixes.css';
	private const JS_PATH  = __DIR__ . '/../../assets/js/ltms-homepage-fixes.js';
	private const ARCHIVE  = __DIR__ . '/../../includes/frontend/templates/archive-product.php';

	public function test_shop_card_styles_cover_pv_shop_scope(): void {
		$src = file_get_contents( self::CSS_PATH );

		// SHOP-CARD-PARITY: el selector de card base debe cubrir .pv-shop.
		$this->assertStringContainsString(
			'.elementor-wc-products ul.products li.product,',
			$src,
			'SHOP-CARD-PARITY: card base del home debe tener selector agrupado.'
		);
		$this->assertStringContainsString(
			'.pv-shop ul.products li.product {',
			$src,
			'SHOP-CARD-PARITY: .pv-shop debe cubrir la card base (imagen/título/precio/botón del home).'
		);
		// La imagen cuadrada 1:1 también debe aplicar al shop.
		$this->assertStringContainsString(
			'.pv-shop ul.products li.product a.woocommerce-loop-product__link img',
			$src,
			'SHOP-CARD-PARITY: la imagen cuadrada (aspect-ratio 1:1) debe aplicar a .pv-shop.'
		);
		// El título clamp de 2 líneas también.
		$this->assertStringContainsString(
			'.pv-shop ul.products li.product .woocommerce-loop-product__title',
			$src,
			'SHOP-CARD-PARITY: el título clamp debe aplicar a .pv-shop.'
		);
	}

	public function test_shop_grid_responsive_defined(): void {
		$src = file_get_contents( self::CSS_PATH );

		// Grid del shop: 4 columnas desktop -> 3 -> 2 (no carrusel como el home).
		$this->assertStringContainsString(
			'.pv-scope.pv-shop ul.products {',
			$src,
			'SHOP-CARD-PARITY: debe existir grid scoped para el shop.'
		);
		$this->assertStringContainsString(
			'grid-template-columns: repeat(4, 1fr) !important;',
			$src,
			'SHOP-CARD-PARITY: grid desktop de 4 columnas.'
		);
		$this->assertStringContainsString(
			'grid-template-columns: repeat(2, 1fr) !important;',
			$src,
			'SHOP-CARD-PARITY: grid mobile de 2 columnas.'
		);
		// SHOP-CARD-PARITY: el clearfix ::before/::after de WC crea un grid item
		// fantasma en la primera celda -> primera tarjeta desplazada a la col 2
		// (espacio blanco donde debía ir la primera card). Deben neutralizarse.
		$this->assertStringContainsString(
			'.pv-scope.pv-shop ul.products::before,',
			$src,
			'SHOP-CARD-PARITY: el ::before del ul.products del shop debe neutralizarse.'
		);
		$this->assertStringContainsString(
			'content: none !important;',
			$src,
			'SHOP-CARD-PARITY: el clearfix debe quedar sin content para no ocupar celda del grid.'
		);
	}

	public function test_archive_avoids_duplicate_breadcrumb(): void {
		$src = file_get_contents( self::ARCHIVE );

		// SHOP-BREADCRUMB-DUP: el breadcrumb del theme se remueve del hook
		// woocommerce_before_main_content para evitar "Inicio / Tienda" doble.
		$this->assertStringContainsString(
			"remove_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 );",
			$src,
			'SHOP-BREADCRUMB-DUP: el breadcrumb del theme debe removerse antes del do_action.'
		);
		// Se restaura al final del template para no afectar al resto del sitio.
		$this->assertStringContainsString(
			"add_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 );",
			$src,
			'SHOP-BREADCRUMB-DUP: el breadcrumb del theme debe restaurarse tras renderizar el nuestro.'
		);
		// Nuestro breadcrumb del design system sigue presente.
		$this->assertStringContainsString(
			'pv-shop__breadcrumb',
			$src,
			'SHOP-BREADCRUMB-DUP: el breadcrumb del design system (pv-shop__breadcrumb) debe seguir.'
		);
	}

	public function test_shop_image_fix_js_covers_pv_shop(): void {
		$src = file_get_contents( self::JS_PATH );

		// SHOP-CARD-PARITY: fixProductCardImages() debe cubrir .pv-shop.
		$this->assertStringContainsString(
			"'.elementor-wc-products ul.products li.product, .pv-shop ul.products li.product'",
			$src,
			'SHOP-CARD-PARITY: el selector CARD_SELECTOR del JS debe incluir .pv-shop.'
		);
		$this->assertStringContainsString(
			'img.closest(CARD_SELECTOR)',
			$src,
			'SHOP-CARD-PARITY: el listener lazyloaded debe usar CARD_SELECTOR (incluye .pv-shop).'
		);
	}

	public function test_archive_strips_vendor_login_gate(): void {
		$src = file_get_contents( self::ARCHIVE );

		// SHOP-VENDOR-GATE: el archive description se captura y se omite si
		// contiene el login gate (.ltms-empty-state-login) del dashboard.
		$this->assertStringContainsString(
			"do_action( 'woocommerce_archive_description' );",
			$src,
			'SHOP-VENDOR-GATE: woocommerce_archive_description se sigue invocando.'
		);
		$this->assertStringContainsString(
			'ltms-empty-state-login',
			$src,
			'SHOP-VENDOR-GATE: archive-product.php debe detectar el bloque del login gate.'
		);
		$this->assertStringContainsString(
			'strpos( $pv_archive_desc, \'ltms-empty-state-login\' )',
			$src,
			'SHOP-VENDOR-GATE: el gate se detecta en el output capturado.'
		);
	}
}