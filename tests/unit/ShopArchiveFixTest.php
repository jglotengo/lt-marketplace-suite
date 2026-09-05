<?php
/**
 * ShopArchiveFixTest - SHOP-ARCHIVE-EMPTY (2026-09-05).
 *
 * El archive de productos (/tienda/ y ?post_type=product) se renderizaba vacio
 * (header+footer sin main). Causa raiz: inject_item_list_schema estaba en
 * woocommerce_shop_loop (por producto) y hacia have_posts()/the_post() que
 * CONSUMIA el loop principal y reconstruia el ItemList N veces -> shop vacio +
 * fatal de memoria (seo-enhanced.php:685). Ademas el override nativo
 * archive-product.php estaba deshabilitado.
 *
 * Fix: (1) hook movido a woocommerce_after_shop_loop + rewind_posts();
 * (2) archive-product.php re-habilitado para is_shop/is_product_taxonomy.
 *
 * Tests source-based (patrón C20-C29): file_get_contents + asserts.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

final class ShopArchiveFixTest extends LTMS_Unit_Test_Case {

	private const SEO_PATH    = __DIR__ . '/../../includes/frontend/class-ltms-seo-enhanced.php';
	private const NATIVE_PATH = __DIR__ . '/../../includes/frontend/class-ltms-native-templates.php';

	public function test_item_list_schema_moved_to_after_shop_loop(): void {
		$src = file_get_contents( self::SEO_PATH );

		// SHOP-ARCHIVE-EMPTY: el ItemList schema debe correr UNA vez tras el loop.
		$this->assertStringContainsString(
			"add_action( 'woocommerce_after_shop_loop', [ __CLASS__, 'inject_item_list_schema' ], 10 )",
			$src,
			'SHOP-ARCHIVE-EMPTY: inject_item_list_schema debe estar en woocommerce_after_shop_loop (una vez).'
		);
		// No debe quedar en woocommerce_shop_loop (por producto -> consumia el loop).
		$this->assertStringNotContainsString(
			"add_action( 'woocommerce_shop_loop', [ __CLASS__, 'inject_item_list_schema' ]",
			$src,
			'SHOP-ARCHIVE-EMPTY: inject_item_list_schema NO debe estar en woocommerce_shop_loop (por producto).'
		);
	}

	public function test_item_list_schema_rewinds_posts(): void {
		$src = file_get_contents( self::SEO_PATH );

		$pos = strpos( $src, 'public static function inject_item_list_schema' );
		$this->assertNotFalse( $pos );
		$block = substr( $src, $pos, 600 );
		$this->assertStringContainsString(
			'rewind_posts();',
			$block,
			'SHOP-ARCHIVE-EMPTY: inject_item_list_schema debe hacer rewind_posts() antes de iterar el loop.'
		);
	}

	public function test_archive_product_template_reenabled(): void {
		$src = file_get_contents( self::NATIVE_PATH );

		// SHOP-ARCHIVE-EMPTY: archive-product.php re-habilitado para shop/taxonomias.
		$this->assertStringContainsString(
			"if ( is_shop() || is_product_taxonomy() ) {",
			$src,
			'SHOP-ARCHIVE-EMPTY: maybe_override debe servir archive-product.php para is_shop/is_product_taxonomy.'
		);
		$this->assertStringContainsString(
			'archive-product.php',
			$src,
			'SHOP-ARCHIVE-EMPTY: debe referenciar archive-product.php.'
		);
	}
}