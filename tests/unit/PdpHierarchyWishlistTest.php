<?php
/**
 * PdpHierarchyWishlistTest - PDP-HIERARCHY-001 + PDP-WISHLIST-GUEST (2026-09-04).
 *
 * 1. PDP-HIERARCHY-001 (P2, UX): el elemento "Envío gratis incluido" inyectado por
 *    PV.enhancePriceDisplay() (class-ltms-native-templates.php) llevaba estilos
 *    inline apretados (font-size:13px; margin-top:4px; SIN margin-bottom) y quedaba
 *    pegado al precio y a la barra de stock sin jerarquía visual. Fix: se eliminaron
 *    los estilos inline y el estilo vive en ltms-plaza-viva.css como pill de beneficio
 *    con margen inferior + separador del bloque de precio.
 *
 * 2. PDP-WISHLIST-GUEST (P1, funcional): el botón wishlist del PDP
 *    (.ltms-wishlist-btn-single) usa el handler legacy ltms_toggle_wishlist que
 *    exigía login (401 "Login requerido") → para GUESTS el wishlist del PDP no
 *    funcionaba (el JS no maneja .fail). Fix: se eliminó el gate de login en
 *    ajax_toggle(); el nonce por-producto (ltms_wishlist_{pid}) sigue protegiendo
 *    contra CSRF y LTMS_Wishlist::toggle() persiste guest vía cookie y logged-in vía
 *    DB (misma persistencia que ajax_pv_toggle).
 *
 * Tests source-based (patrón C20-C29): file_get_contents + asserts.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

final class PdpHierarchyWishlistTest extends LTMS_Unit_Test_Case {

	private const WISHLIST_PATH  = __DIR__ . '/../../includes/frontend/class-ltms-wishlist.php';
	private const NATIVE_PATH    = __DIR__ . '/../../includes/frontend/class-ltms-native-templates.php';
	private const PLAZA_CSS_PATH = __DIR__ . '/../../assets/css/ltms-plaza-viva.css';

	// ====================================================================
	//  PDP-WISHLIST-GUEST (P1): guests pueden agregar a wishlist en el PDP
	// ====================================================================

	public function test_ajax_toggle_no_longer_requires_login(): void {
		$src = file_get_contents( self::WISHLIST_PATH );

		$pos = strpos( $src, 'public static function ajax_toggle(): void' );
		$this->assertNotFalse( $pos, 'ajax_toggle() debe existir en LTMS_Wishlist' );
		$block = substr( $src, $pos, 1200 );

		// El gate de login (401 para guests) debe haber desaparecido del handler.
		$this->assertStringNotContainsString(
			'is_user_logged_in() ) { wp_send_json_error',
			$block,
			'PDP-WISHLIST-GUEST: ajax_toggle() NO debe exigir login (guests via cookie, paridad con ajax_pv_toggle).'
		);
	}

	public function test_ajax_toggle_keeps_csrf_nonce_and_tag(): void {
		$src = file_get_contents( self::WISHLIST_PATH );

		$pos = strpos( $src, 'public static function ajax_toggle(): void' );
		$block = substr( $src, $pos, 1200 );

		// El nonce por-producto se conserva (protección CSRF intacta).
		$this->assertStringContainsString(
			'wp_verify_nonce( $nonce, \'ltms_wishlist_\' . $product_id )',
			$block,
			'PDP-WISHLIST-GUEST: ajax_toggle() conserva la validación del nonce por-producto.'
		);
		// Tag de trazabilidad.
		$this->assertStringContainsString(
			'PDP-WISHLIST-GUEST FIX',
			$src,
			'PDP-WISHLIST-GUEST: tag de trazabilidad presente en LTMS_Wishlist.'
		);
	}

	// ====================================================================
	//  PDP-HIERARCHY-001 (P2, UX): jerarquía tras "Envío gratis incluido"
	// ====================================================================

	public function test_enhance_price_display_removes_cramped_inline_styles(): void {
		$src = file_get_contents( self::NATIVE_PATH );

		$this->assertStringNotContainsString(
			"info.style.cssText = 'font-size:13px;color:#0BA37F;font-weight:600;margin-top:4px;display:flex;align-items:center;gap:4px'",
			$src,
			'PDP-HIERARCHY-001: enhancePriceDisplay() NO debe setear estilos inline apretados.'
		);
		// Usa las clases del pill para que el design system controle el estilo.
		$this->assertStringContainsString(
			'ltms-price-shipping-info__text',
			$src,
			'PDP-HIERARCHY-001: el markup del shipping info usa la clase __text (estilo en CSS).'
		);
	}

	public function test_shipping_info_css_pill_exists(): void {
		$css = file_get_contents( self::PLAZA_CSS_PATH );

		$this->assertStringContainsString(
			'.ltms-price-shipping-info {',
			$css,
			'PDP-HIERARCHY-001: ltms-plaza-viva.css debe definir el pill .ltms-price-shipping-info.'
		);
		// Margen inferior que separa del stock (jerarquía).
		$this->assertStringContainsString(
			'margin: 6px 0 16px;',
			$css,
			'PDP-HIERARCHY-001: el pill debe tener margen inferior (16px) para separar del stock.'
		);
		// Separador del bloque de precio.
		$this->assertStringContainsString(
			'.pv-product-info__price',
			$css,
			'PDP-HIERARCHY-001: el bloque de precio debe tener su separador (border-bottom).'
		);
	}
}