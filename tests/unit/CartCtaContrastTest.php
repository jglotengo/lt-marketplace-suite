<?php
/**
 * CartCtaContrastTest - CART-CTA-CONTRAST (2026-09-10).
 *
 * El botón "Finalizar compra" del carrito es un <a class="pv-btn--brand">
 * (fondo rojo --brand #E80001). La regla del tema `a { color: var(--primary) }`
 * (azul) sobreescribe el color:#fff de .pv-btn--brand, dejando texto azul sobre
 * rojo (bajo contraste). Mismo patrón que CART-EMPTY-CTA (fix 2026-09-05, ver
 * CartEmptyUxTest), que solo cubría el botón del carrito vacío, no este CTA.
 *
 * Tests source-based (patrón C20-C29): file_get_contents + asserts.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class CartCtaContrastTest
 *
 * Ejecutar con: LTMS_UNIT_ONLY=true ./vendor/bin/phpunit --group audit-cart-cta
 *
 * @group audit-cart-cta
 */
final class CartCtaContrastTest extends LTMS_Unit_Test_Case {

	private const CART_CSS_PATH = __DIR__ . '/../../assets/css/ltms-cart.css';
	private const CART_MIN_PATH = __DIR__ . '/../../assets/css/ltms-cart.min.css';

	public function test_checkout_cta_text_forced_white(): void {
		$css = file_get_contents( self::CART_CSS_PATH );

		$this->assertStringContainsString(
			'CART-CTA-CONTRAST FIX',
			$css,
			'CART-CTA-CONTRAST: el fix debe tener su marcador traceable en ltms-cart.css.'
		);
		$this->assertStringContainsString(
			'.pv-cart__cta a.pv-btn--brand',
			$css,
			'CART-CTA-CONTRAST: debe existir el override del CTA "Finalizar compra" (a.pv-btn--brand).'
		);
		$this->assertStringContainsString(
			'color: #fff !important',
			$css,
			'CART-CTA-CONTRAST: el texto del CTA debe ser blanco con !important (sobreescribe el a{color:var(--primary)} del tema).'
		);
	}

	public function test_min_css_regenerated(): void {
		$min = file_get_contents( self::CART_MIN_PATH );

		$this->assertStringContainsString(
			'pv-cart__cta a.pv-btn--brand',
			$min,
			'CART-CTA-CONTRAST: el .min.css debe contener el selector del CTA regenerado.'
		);
		$this->assertStringContainsString(
			'color:#fff!important',
			$min,
			'CART-CTA-CONTRAST: el .min.css debe tener el color blanco !important.'
		);
	}
}