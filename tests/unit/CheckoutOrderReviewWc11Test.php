<?php
/**
 * CheckoutOrderReviewWc11Test - CHECKOUT-CTA-VISIBLE (2026-09-14).
 *
 * WooCommerce 11.x removió la función `woocommerce_checkout_order_review()`
 * (antes definida en wc-template-functions.php). El template nativo LTMS
 * (includes/frontend/templates/checkout.php) la llamaba como función en la
 * columna de resumen del pedido, produciendo un fatal "Call to undefined
 * function woocommerce_checkout_order_review()" que cortaba el render a
 * ~198KB y devolvía HTTP 500 — el usuario reportó que el botón "Confirmar
 * pedido" no se veía (la página moría justo después del CTA, en el order
 * review). El fix reemplaza la llamada por `woocommerce_order_review()`,
 * función vigente en WC 11.x que renderiza checkout/review-order.php
 * (tabla de items + totales).
 *
 * Tests source-based (file_get_contents + asserts), deterministas en
 * LTMS_UNIT_ONLY=true. No dependen de WooCommerce cargado.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class CheckoutOrderReviewWc11Test
 *
 * @group checkout
 */
final class CheckoutOrderReviewWc11Test extends LTMS_Unit_Test_Case {

	private const CHECKOUT_TEMPLATE = __DIR__ . '/../../includes/frontend/templates/checkout.php';

	public function test_checkout_template_does_not_call_removed_function(): void {
		$src = file_get_contents( self::CHECKOUT_TEMPLATE );

		$this->assertStringNotContainsString(
			'woocommerce_checkout_order_review();',
			$src,
			'CHECKOUT-CTA-VISIBLE: el template no debe llamar woocommerce_checkout_order_review() como función (removida en WooCommerce 11.x).'
		);
	}

	public function test_checkout_template_uses_current_order_review_function(): void {
		$src = file_get_contents( self::CHECKOUT_TEMPLATE );

		$this->assertStringContainsString(
			'woocommerce_order_review();',
			$src,
			'CHECKOUT-CTA-VISIBLE: el template debe llamar woocommerce_order_review() (función vigente en WC 11.x).'
		);

		$this->assertStringContainsString(
			'CHECKOUT-CTA-VISIBLE FIX',
			$src,
			'CHECKOUT-CTA-VISIBLE: el fix debe tener su marcador traceable en checkout.php.'
		);
	}

	public function test_checkout_template_guards_other_legacy_functions(): void {
		$src = file_get_contents( self::CHECKOUT_TEMPLATE );

		// El resto de funciones de checkout clásico que el template usa siguen
		// existiendo en WC 11.x, pero por robustez se invocan bajo function_exists().
		$this->assertStringContainsString(
			"function_exists( 'woocommerce_checkout_login_form' )",
			$src,
			'CHECKOUT-CTA-VISIBLE: woocommerce_checkout_login_form() debe invocarse solo si existe.'
		);

		$this->assertStringContainsString(
			"function_exists( 'woocommerce_checkout_coupon_form' )",
			$src,
			'CHECKOUT-CTA-VISIBLE: woocommerce_checkout_coupon_form() debe invocarse solo si existe.'
		);
	}
}