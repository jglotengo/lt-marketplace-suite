<?php
/**
 * CartEmptyUxTest - CART-EMPTY-CTA + CART-EMPTY-PRODUCTS (2026-09-05).
 *
 * 1. CART-EMPTY-CTA (P1): en /carrito/ vacio, el boton "Explorar productos"
 *    tenia el texto azul-sobre-azul (una regla del tema `a { color:
 *    var(--primary) }` sobreescribia el color:#fff de .pv-btn) -> invisible.
 *    Fix: color:#fff !important en el boton del carrito vacio.
 * 2. CART-EMPTY-PRODUCTS (P2): mostrar productos directamente en el carrito
 *    vacio (4 recientes via content-product.php) en vez de solo el boton.
 *
 * Tests source-based (patrón C20-C29): file_get_contents + asserts.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

final class CartEmptyUxTest extends LTMS_Unit_Test_Case {

	private const CART_PATH = __DIR__ . '/../../includes/frontend/templates/cart.php';
	private const CART_CSS_PATH = __DIR__ . '/../../assets/css/ltms-cart.css';

	public function test_empty_cart_button_text_forced_white(): void {
		$css = file_get_contents( self::CART_CSS_PATH );

		$this->assertStringContainsString(
			'.pv-cart__empty-card a.pv-btn',
			$css,
			'CART-EMPTY-CTA: debe existir el override del boton del carrito vacio.'
		);
		$this->assertStringContainsString(
			'color: #fff !important;',
			$css,
			'CART-EMPTY-CTA: el texto del boton debe ser blanco con !important (sobreescribe el a{color} del tema).'
		);
	}

	public function test_empty_cart_shows_products_directly(): void {
		$src = file_get_contents( self::CART_PATH );

		$this->assertStringContainsString(
			'Productos para ti',
			$src,
			'CART-EMPTY-PRODUCTS: el carrito vacio debe mostrar el titulo "Productos para ti".'
		);
		$this->assertStringContainsString(
			"wc_get_template_part( 'content', 'product' )",
			$src,
			'CART-EMPTY-PRODUCTS: debe renderizar las cards de producto via content-product.php.'
		);
		$this->assertStringContainsString(
			'new WP_Query(',
			$src,
			'CART-EMPTY-PRODUCTS: debe consultar productos recientes con WP_Query.'
		);
	}

	public function test_empty_products_grid_css(): void {
		$css = file_get_contents( self::CART_CSS_PATH );

		$this->assertStringContainsString(
			'.pv-cart-empty-grid',
			$css,
			'CART-EMPTY-PRODUCTS: el CSS del grid de productos debe existir.'
		);
		$this->assertStringContainsString(
			'grid-template-columns:repeat(4,1fr)',
			$css,
			'CART-EMPTY-PRODUCTS: el grid debe ser de 4 columnas en desktop.'
		);
	}

	public function test_empty_products_cards_fill_grid_cell(): void {
		$css = file_get_contents( self::CART_CSS_PATH );

		// CART-EMPTY-OVERFLOW FIX: las li.product traen el estilo default de WC
		// (float:left + width pequeña) que chocaba con el grid -> las cards se
		// colapsaban a ~35px y desbordaban. El override debe desactivar el float
		// y forzar width:100% para llenar la celda.
		$this->assertStringContainsString(
			'.pv-cart-empty-grid li.product',
			$css,
			'CART-EMPTY-OVERFLOW: debe existir el override de li.product en el grid.'
		);
		$this->assertStringContainsString(
			'float:none;',
			$css,
			'CART-EMPTY-OVERFLOW: li.product no debe flotar (float:none).'
		);
		$this->assertStringContainsString(
			'width:100% !important;',
			$css,
			'CART-EMPTY-OVERFLOW: li.product debe llenar la celda (width:100% !important).'
		);
	}

	public function test_min_css_regenerated(): void {
		$min = file_get_contents( __DIR__ . '/../../assets/css/ltms-cart.min.css' );

		$this->assertStringContainsString( 'color:#fff!important', $min, 'CART-EMPTY-CTA: el .min.css debe tener el color blanco !important.' );
		$this->assertStringContainsString( 'pv-cart-empty-grid', $min, 'CART-EMPTY-PRODUCTS: el .min.css debe tener el grid.' );
	}
}