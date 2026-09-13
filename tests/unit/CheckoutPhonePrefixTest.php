<?php
/**
 * CheckoutPhonePrefixTest - CHECKOUT-PHONE-PREFIX (2026-09-12).
 *
 * El campo de teléfono del checkout mostraba el +57 DENTRO del mismo input
 * (placeholder "+57 300 000 0000"), obligando al usuario a escribir el prefijo.
 * Se separa: el +57 es un addon fijo (.pv-phone-prefix) y el usuario escribe
 * solo sus 10 dígitos. En el POST se normaliza a +57XXXXXXXXXX para que pedido
 * e integradores (Aveonline, ZapSign, SAGRILAFT) reciban el número completo.
 *
 * Tests source-based (file_get_contents + asserts), deterministas en
 * LTMS_UNIT_ONLY=true.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class CheckoutPhonePrefixTest
 *
 * Ejecutar con: LTMS_UNIT_ONLY=true ./vendor/bin/phpunit --group audit-phone-prefix
 *
 * @group audit-phone-prefix
 */
final class CheckoutPhonePrefixTest extends LTMS_Unit_Test_Case {

	private const CHECKOUT_PATH = __DIR__ . '/../../includes/frontend/templates/checkout.php';
	private const HANDLER_PATH = __DIR__ . '/../../includes/frontend/class-ltms-frontend-checkout-handler.php';
	private const CSS_PATH     = __DIR__ . '/../../assets/css/ltms-plaza-viva.css';
	private const CSS_MIN      = __DIR__ . '/../../assets/css/ltms-plaza-viva.min.css';

	public function test_phone_prefix_addon_in_markup(): void {
		$src = file_get_contents( self::CHECKOUT_PATH );

		$this->assertStringContainsString(
			'CHECKOUT-PHONE-PREFIX FIX',
			$src,
			'CHECKOUT-PHONE-PREFIX: la traza del fix debe estar en checkout.php.'
		);
		$this->assertStringContainsString(
			'pv-phone-prefix',
			$src,
			'CHECKOUT-PHONE-PREFIX: debe existir el addon fijo +57 junto al input.'
		);
		$this->assertStringContainsString(
			'300 000 0000',
			$src,
			'CHECKOUT-PHONE-PREFIX: el placeholder debe ser solo los 10 dígitos (sin +57).'
		);
		$this->assertStringContainsString(
			'maxlength="10"',
			$src,
			'CHECKOUT-PHONE-PREFIX: el input debe limitarse a 10 dígitos.'
		);
	}

	public function test_display_value_strips_country_prefix(): void {
		$src = file_get_contents( self::CHECKOUT_PATH );

		// El valor precargado se muestra sin el prefijo de país (evita "+57 [ +57... ]").
		$this->assertStringContainsString(
			'$phone_display',
			$src,
			'CHECKOUT-PHONE-PREFIX: debe normalizarse el valor mostrado (sin +57) para no duplicar el prefijo.'
		);
	}

	public function test_server_normalization_registered(): void {
		$src = file_get_contents( self::HANDLER_PATH );

		$this->assertStringContainsString(
			'woocommerce_checkout_posted_data',
			$src,
			'CHECKOUT-PHONE-PREFIX: debe registrarse el filtro que normaliza billing_phone.'
		);
		$this->assertStringContainsString(
			'function normalize_checkout_phone',
			$src,
			'CHECKOUT-PHONE-PREFIX: debe existir el método normalize_checkout_phone().'
		);
		$this->assertStringContainsString(
			"] = '+57'",
			$src,
			'CHECKOUT-PHONE-PREFIX: un celular de 10 dígitos debe guardarse como +57XXXXXXXXXX.'
		);
	}

	public function test_css_prefix_style_and_min(): void {
		$css = file_get_contents( self::CSS_PATH );
		$min = file_get_contents( self::CSS_MIN );

		$this->assertStringContainsString( '.pv-phone-prefix', $css, 'CHECKOUT-PHONE-PREFIX: debe existir el estilo del addon en ltms-plaza-viva.css.' );
		$this->assertStringContainsString( '.pv-phone-prefix', $min, 'CHECKOUT-PHONE-PREFIX: el .min.css debe regenerarse con el addon.' );
	}
}