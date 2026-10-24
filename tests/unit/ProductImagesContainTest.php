<?php
/**
 * ProductImagesContainTest — PROD-IMG-FIT-2026-10-24.
 *
 * Auditoría solicitada por el operador: "audita en todo el proyecto donde
 * se visualicen imágenes de productos que se vean completas, en especial
 * las de los productos recomendados, tarjetas, favoritos, upsell etc".
 *
 * Hallazgo: 16 puntos de render recortaban la imagen con
 * object-fit:cover (caja 1:1 o caja fija) — el producto se veía cortado.
 * El proyecto YA tenía el patrón correcto en la vitrina
 * (.ltms-sf-img-main: caja 1:1 + object-fit:contain + padding + fondo
 * letterbox) y en el minicart nuevo. Este fix extiende ese estándar a
 * TODOS los puntos de render de imagen de producto:
 *
 *   Card de producto (home/shop/relacionados/cross-sells/carousels),
 *   favoritos (wishlist), upsell del drawer, items del drawer clásico,
 *   quick view (modal + thumbs), historial de navegación, addons,
 *   carrito, resumen de tracking, sticky ATC, reviews con producto,
 *   bundles, categorías del home, autocomplete de la vitrina,
 *   comparador.
 *
 * Quedan cover SOLO los elementos que NO son producto (banners de
 * marketing del hero, avatares de vendedor/chofer) — correcto por diseño.
 *
 * Nota file-level (pendiente de decisión del operador): woocommerce_thumbnail
 * se genera cuadrado-recortado (300x300) para originales no cuadrados — el
 * CSS contain muestra el archivo completo pero un original alto ya perdió
 * contenido EN el archivo. La corrección (WC "Uncropped" + regeneración de
 * miniaturas) es una operación global de tienda aparte.
 *
 * @package LTMS\Tests\Unit
 * @group prod-img-contain
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class ProductImagesContainTest
 *
 * @group prod-img-contain
 */
final class ProductImagesContainTest extends LTMS_Unit_Test_Case {

	private string $pv_css;
	private string $pe_css;
	private string $cart_css;
	private string $ux_css;
	private string $sf_css;
	private string $home_path;
	private string $single_path;
	private string $wishlist_path;
	private string $amazon_path;
	private string $quick_view_path;
	private string $webhook_path;

	protected function setUp(): void {
		parent::setUp();
		$base = dirname( __DIR__, 2 );
		$this->pv_css       = $base . '/assets/css/ltms-plaza-viva.css';
		$this->pe_css       = $base . '/assets/css/ltms-product-enhancements.css';
		$this->cart_css     = $base . '/assets/css/ltms-cart.css';
		$this->ux_css       = $base . '/assets/css/ltms-ux-enhancements.css';
		$this->sf_css       = $base . '/assets/css/ltms-storefront.css';
		$this->home_path    = $base . '/includes/frontend/templates/home.php';
		$this->single_path  = $base . '/includes/frontend/templates/single-product.php';
		$this->wishlist_path = $base . '/includes/frontend/class-ltms-wishlist.php';
		$this->amazon_path  = $base . '/includes/frontend/class-ltms-amazon-enhancements.php';
		$this->quick_view_path = $base . '/includes/frontend/class-ltms-quick-view.php';
		$this->webhook_path = $base . '/deploy/ltms-deploy-webhook.php';
	}

	/**
	 * PROD-IMG-A: la CARD de producto (home trending, shop, relacionados,
	 * cross-sells, carousels — todo lo que usa content-product.php) muestra
	 * el producto COMPLETO: contain + letterbox, no cover.
	 */
	public function test_001_card_de_producto_contain(): void {
		$css = (string) file_get_contents( $this->pv_css );
		$this->assertMatchesRegularExpression(
			'/\.pv-product-card__media img\{\s*\n\s*width:100%;height:100%;object-fit:contain;padding:8px;/',
			$css,
			'PROD-IMG-A: la card de producto debe usar contain + padding (producto completo, patrón vitrina).'
		);
		$this->assertStringNotContainsString(
			'.pv-product-card__media img{' . "\n" . '  width:100%;height:100%;object-fit:cover;',
			$css,
			'PROD-IMG-A: prohibido volver a cover en la card (recorta el producto).'
		);
	}

	/**
	 * PROD-IMG-B: favoritos, upsell del drawer, items del drawer,
	 * historial de navegación y addons — los puntos nombrados por el
	 * operador muestran el producto completo.
	 */
	public function test_002_favoritos_upsell_drawer_browsing_addons(): void {
		// Favoritos: caja cuadrada + contain (antes height:160px cover).
		$wishlist = (string) file_get_contents( $this->wishlist_path );
		$this->assertStringContainsString(
			"aspect-ratio:1/1;object-fit:contain;padding:8px;background:#f8f8f8",
			$wishlist,
			'PROD-IMG-B: la imagen de favoritos debe ser caja 1:1 con contain (antes cover 160px recortaba).'
		);

		$pe = (string) file_get_contents( $this->pe_css );
		// Upsell del drawer.
		$this->assertMatchesRegularExpression(
			'/\.ltms-drawer-upsell-card__img img \{[^}]*object-fit: contain;/s',
			$pe,
			'PROD-IMG-B: el upsell del drawer debe usar contain.'
		);
		// Items del drawer.
		$this->assertMatchesRegularExpression(
			'/\.ltms-drawer-item__img img \{[^}]*object-fit: contain;/s',
			$pe,
			'PROD-IMG-B: los items del drawer deben usar contain.'
		);
		// Historial de navegación.
		$this->assertMatchesRegularExpression(
			'/\.ltms-browsing-item img \{[^}]*object-fit: contain;/s',
			$pe,
			'PROD-IMG-B: el historial de navegación debe usar contain.'
		);
		// Addons.
		$this->assertMatchesRegularExpression(
			'/\.ltms-addon-item img \{[^}]*object-fit: contain !important;/s',
			$pe,
			'PROD-IMG-B: los addons deben usar contain.'
		);
	}

	/**
	 * PROD-IMG-C: quick view (modal + thumbs), carrito, tracking, sticky
	 * ATC, reviews con producto, bundles, categorías del home,
	 * autocomplete de la vitrina y comparador.
	 */
	public function test_003_resto_de_puntos_contain(): void {
		$pv = (string) file_get_contents( $this->pv_css );
		$this->assertMatchesRegularExpression( '/\.pv-modal__media img\{[^}]*object-fit:contain;/s', $pv, 'PROD-IMG-C: quick view modal con contain.' );
		$this->assertMatchesRegularExpression( '/\.pv-sticky-atc__thumb\{[^}]*object-fit:contain;/s', $pv, 'PROD-IMG-C: sticky ATC con contain.' );
		$this->assertMatchesRegularExpression( '/\.pv-vendor-store__review-product img\{[^}]*object-fit:contain;/s', $pv, 'PROD-IMG-C: reviews con producto con contain.' );
		$this->assertMatchesRegularExpression( '/\.pv-order-summary__item-media img\{[^}]*object-fit:contain;/s', $pv, 'PROD-IMG-C: resumen de tracking con contain.' );

		$cart = (string) file_get_contents( $this->cart_css );
		$this->assertMatchesRegularExpression( '/\.pv-cart__item-media img\{[^}]*object-fit:contain;/s', $cart, 'PROD-IMG-C: items del carrito con contain.' );

		$home = (string) file_get_contents( $this->home_path );
		$this->assertMatchesRegularExpression( '/\.pv-cat-bar__img\{[^}]*object-fit:contain;/s', $home, 'PROD-IMG-C: cards de categoría del home (imagen del producto estrella) con contain.' );

		$single = (string) file_get_contents( $this->single_path );
		$this->assertMatchesRegularExpression( '/\.pv-bundle__thumb img\{[^}]*object-fit:contain;/s', $single, 'PROD-IMG-C: bundles con contain.' );

		$sf = (string) file_get_contents( $this->sf_css );
		$this->assertMatchesRegularExpression( '/\.ltms-sf-ac-img\{[^}]*object-fit:contain;/s', $sf, 'PROD-IMG-C: autocomplete de la vitrina con contain.' );

		$ux = (string) file_get_contents( $this->ux_css );
		$this->assertMatchesRegularExpression( '/\.ltms-compare-bar-item img \{[^}]*object-fit: contain;/s', $ux, 'PROD-IMG-C: comparador (barra) con contain.' );
		$this->assertMatchesRegularExpression( '/\.ltms-compare-product-img img \{[^}]*object-fit: contain;/s', $ux, 'PROD-IMG-C: comparador (tabla) con contain.' );

		// Thumbs del quick view (inline).
		$qv = (string) file_get_contents( $this->quick_view_path );
		$this->assertStringContainsString( "60px;object-fit:contain", $qv, 'PROD-IMG-C: thumbs de galería del quick view con contain.' );

		// Amazon enhancements: addons + browsing (inline).
		$amazon = (string) file_get_contents( $this->amazon_path );
		$this->assertStringContainsString( 'height:80px;object-fit:contain', $amazon, 'PROD-IMG-C: addons inline con contain.' );
		$this->assertStringContainsString( 'height:120px;object-fit:contain', $amazon, 'PROD-IMG-C: historial de navegación inline con contain.' );
	}

	/**
	 * PROD-IMG-D: los .min.css están sincronizados con el nuevo estándar
	 * (SG Optimizer sirve los min).
	 */
	public function test_004_min_css_sincronizados(): void {
		foreach ( array(
			$this->pv_css   => 'object-fit:contain;padding:8px',
			$this->pe_css   => 'object-fit:contain;padding:4px;background:#f8f8f8',
			$this->cart_css => 'object-fit:contain;padding:4px',
			$this->sf_css   => 'object-fit:contain;padding:2px',
		) as $path => $needle ) {
			$min = (string) file_get_contents( str_replace( '.css', '.min.css', $path ) );
			$this->assertStringContainsString(
				$needle,
				$min,
				'PROD-IMG-D: el .min.css de ' . basename( $path ) . ' debe contener el estándar contain (regenerado).'
			);
		}
		$ux_min = (string) file_get_contents( str_replace( '.css', '.min.css', $this->ux_css ) );
		$this->assertStringContainsString( 'object-fit:contain', $ux_min, 'PROD-IMG-D: el .min.css de ux-enhancements regenerado.' );
	}

	/**
	 * PROD-IMG-E: whitelist del deploy webhook (lección #177) — los
	 * archivos de la auditoría viajan con el fix.
	 */
	public function test_005_whitelist_webhook(): void {
		$webhook = (string) file_get_contents( $this->webhook_path );
		foreach ( array(
			"'assets/css/ltms-cart.css',",
			"'assets/css/ltms-cart.min.css',",
			"'assets/css/ltms-storefront.css',",
			"'assets/css/ltms-storefront.min.css',",
			"'includes/frontend/class-ltms-amazon-enhancements.php',",
			"'includes/frontend/class-ltms-quick-view.php',",
			"'tests/unit/ProductImagesContainTest.php',",
		) as $entry ) {
			$this->assertStringContainsString( $entry, $webhook, "PROD-IMG-E: {$entry} debe estar en la whitelist del webhook." );
		}
	}
}
