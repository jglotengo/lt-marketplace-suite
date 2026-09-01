<?php
/**
 * VtexFunctionalE2ETest — tests funcionales/e2e de la integración VTEX.
 *
 * Sin credenciales VTEX reales, se mockea la capa HTTP (wp_remote_request) con
 * payloads REALISTAS del Search API de VTEX y se ejercita el código REAL
 * (LTMS_Api_Vtex, LTMS_Vtex_Price_Calculator, LTMS_Vtex_Sync) de punta a punta:
 *   - Parseo del Search API (catalog + pricing + inventory + imágenes).
 *   - normalización de items (RefId→SKU, Price, AvailableQuantity, EAN, categoría).
 *   - filtro por categoría con ancestros (categoriesIds con slashes "/2/").
 *   - test_connection, errores HTTP, retry 429, SSRF guard.
 *   - sync_vendor_products end-to-end: API → normalizar → precio → crear producto WC.
 *
 * @package LTMS\Tests\Unit
 *
 * Ejecutar con: ./vendor/bin/phpunit --group audit-vtex-functional
 *
 * @group audit-vtex-functional
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;

// Stub de WC_Product_Simple para el e2e (path de creación de productos).
require_once __DIR__ . '/stubs/wc-product-simple-stub.php';

/**
 * Class VtexFunctionalE2ETest
 *
 * Ejecutar con: ./vendor/bin/phpunit --group audit-vtex-functional
 *
 * @group audit-vtex-functional
 */
final class VtexFunctionalE2ETest extends LTMS_Unit_Test_Case {

	private function plugin_path( string $relative ): string {
		return dirname( __DIR__, 2 ) . '/' . $relative;
	}

	/**
	 * Stubs HTTP + utilidades WP usadas por LTMS_Api_Vtex.
	 *
	 * @param callable $request_handler Recibe ($url, $args) y devuelve el array de respuesta.
	 */
	private function stub_http( callable $request_handler ): void {
		Monkey\Functions\when( 'wp_remote_request' )->alias( $request_handler );
		Monkey\Functions\when( 'is_wp_error' )->justReturn( false );
		Monkey\Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ): int { return (int) ( $response['response']['code'] ?? 0 ); }
		);
		Monkey\Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ): string { return (string) ( $response['body'] ?? '' ); }
		);
		Monkey\Functions\when( 'wp_remote_retrieve_header' )->alias(
			static function ( $response, string $header ): string { return (string) ( $response['headers'][ $header ] ?? '' ); }
		);
		Monkey\Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, string $url ): string { return $url . '?' . http_build_query( $args ); }
		);
	}

	/**
	 * Payload realista del Search API de VTEX (1 producto, 1 SKU).
	 */
	private function vtex_search_payload(): array {
		return [
			[
				'productId'      => '1001',
				'productName'    => 'Jeans Azules',
				'brand'          => 'Levis',
				'brandId'        => 2000,
				'categories'     => [ '/Moda/', '/Moda/Jeans/' ],
				'categoriesIds'  => [ '/2/', '/2/3/' ],
				'categoryId'     => '3',
				'description'    => 'Jeans de mezclilla azul talla 32',
				'items'          => [
					[
						'itemId' => '2001',
						'name'   => 'Jeans Azules 32',
						'refId'  => 'LEV-001',
						'ean'    => '7891234567890',
						'images' => [ [ 'imageUrl' => 'https://img.example/jeans.jpg', 'imageLabel' => 'Principal' ] ],
						'sellers' => [ [ 'sellerId' => '1', 'commertialOffer' => [ 'Price' => 120000, 'ListPrice' => 150000, 'AvailableQuantity' => 25, 'IsAvailable' => true ] ] ],
					],
				],
			],
		];
	}

	// ─────────────────────────────────────────────────────────────────────────
	// API client — parseo del Search API.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_get_products_search_parses_realistic_payload(): void {
		$this->require_class( 'LTMS_Api_Vtex' );
		$payload = $this->vtex_search_payload();
		$this->stub_http( static fn( $url, $args ) => [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode( $payload ),
		] );

		$result = \LTMS_Api_Vtex::get_products_search( 'mistienda', 'k', 't', 0, 49 );

		$this->assertTrue( $result['success'], 'La petición debe tener éxito.' );
		$this->assertCount( 1, $result['data'] );
		$this->assertSame( '1001', $result['data'][0]['productId'] );
		$this->assertSame( 'Jeans Azules', $result['data'][0]['productName'] );
	}

	public function test_normalize_search_item_maps_all_fields(): void {
		$this->require_class( 'LTMS_Api_Vtex' );

		$product = $this->vtex_search_payload()[0];
		$item    = $product['items'][0];
		$normalized = \LTMS_Api_Vtex::normalize_search_item( $product, $item );

		$this->assertSame( 'LEV-001', $normalized['sku'], 'RefId debe ser el SKU canónico.' );
		$this->assertSame( 120000.0, $normalized['regular_price'], 'Price del commertialOffer.' );
		$this->assertSame( 25, $normalized['stock_quantity'], 'AvailableQuantity.' );
		$this->assertSame( '7891234567890', $normalized['barcode'], 'EAN.' );
		$this->assertSame( 'https://img.example/jeans.jpg', $normalized['imagen_url'], 'Imagen principal.' );
		$this->assertSame( 'Levis', $normalized['marca'] );
		$this->assertSame( 'Moda', $normalized['categoria'], 'Primer segmento del path.' );
		$this->assertSame( 'Jeans', $normalized['grupo'], 'Segundo segmento del path.' );
		$this->assertSame( '3', $normalized['categoria_id'] );
		$this->assertSame( [ '2', '3' ], $normalized['categoria_ids'], 'categoriesIds sin slashes.' );
	}

	public function test_normalize_falls_back_to_commercial_offer_and_itemId(): void {
		$this->require_class( 'LTMS_Api_Vtex' );

		$product = $this->vtex_search_payload()[0];
		$product['items'][0]['refId'] = '';
		$product['items'][0]['sellers'][0]['commertialOffer'] = null;
		$product['items'][0]['sellers'][0]['commercialOffer'] = [ 'Price' => 50000, 'AvailableQuantity' => 3 ];

		$normalized = \LTMS_Api_Vtex::normalize_search_item( $product, $product['items'][0] );

		$this->assertSame( '2001', $normalized['sku'], 'Sin refId, debe usar itemId.' );
		$this->assertSame( 50000.0, $normalized['regular_price'], 'Debe leer commercialOffer (corregido).' );
		$this->assertSame( 3, $normalized['stock_quantity'] );
	}

	public function test_normalize_reads_refid_from_referenceId_array(): void {
		$this->require_class( 'LTMS_Api_Vtex' );

		// Payload REAL del Search API de VTEX: el RefId del vendor NO está en
		// $item['refId'] sino en $item['referenceId'][0]['Value']. Sin este fix el
		// SKU caía al itemId ("22344898") en vez del código real del vendor.
		$product = $this->vtex_search_payload()[0];
		$product['items'][0]['referenceId'] = [ [ 'Key' => 'RefId', 'Value' => '3474637279400' ] ];
		unset( $product['items'][0]['refId'] );

		$normalized = \LTMS_Api_Vtex::normalize_search_item( $product, $product['items'][0] );

		$this->assertSame( '3474637279400', $normalized['sku'], 'Debe leer referenceId[0].Value como SKU.' );
		$this->assertSame( '3474637279400', $normalized['codigo'] );
	}

	public function test_normalize_uses_deepest_category_path_when_leaf_first(): void {
		$this->require_class( 'LTMS_Api_Vtex' );

		// Payload REAL: VTEX ordena las rutas de la MÁS profunda (hoja) a la raíz.
		// Antes se usaba end() (la raíz) → categoria='Belleza y Salud', grupo=''
		// en TODOS los productos (categoría WC equivocada).
		$product = $this->vtex_search_payload()[0];
		$product['categories'] = [
			'/Belleza y Salud/Cuidado Capilar/Coloración/',
			'/Belleza y Salud/Cuidado Capilar/',
			'/Belleza y Salud/',
		];

		$normalized = \LTMS_Api_Vtex::normalize_search_item( $product, $product['items'][0] );

		$this->assertSame( 'Belleza y Salud', $normalized['categoria'] );
		$this->assertSame( 'Cuidado Capilar', $normalized['grupo'] );
		$this->assertSame( 'Coloración', $normalized['subgrupo'] );
	}

	public function test_normalize_uses_deepest_category_path_when_root_first(): void {
		$this->require_class( 'LTMS_Api_Vtex' );

		// Payload legacy (root-first): el fix por "más segmentos" debe funcionar
		// con ambos ordenamientos.
		$product = $this->vtex_search_payload()[0];

		$normalized = \LTMS_Api_Vtex::normalize_search_item( $product, $product['items'][0] );

		$this->assertSame( 'Moda', $normalized['categoria'] );
		$this->assertSame( 'Jeans', $normalized['grupo'] );
		$this->assertSame( '', $normalized['subgrupo'] );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Filtro por categoría (ancestros con slashes).
	// ─────────────────────────────────────────────────────────────────────────

	public function test_filter_by_category_matches_ancestors(): void {
		$this->require_class( 'LTMS_Vtex_Price_Calculator' );

		$product = $this->vtex_search_payload()[0];
		$item    = $product['items'][0];
		$norm    = \LTMS_Api_Vtex::normalize_search_item( $product, $item );

		// Seleccionar "Moda" (id 2, ancestro) debe incluir el producto.
		$included = \LTMS_Vtex_Price_Calculator::filter_by_category( [ $norm ], [ '2' ] );
		$this->assertCount( 1, $included, 'Seleccionar ancestro (id 2) debe incluir el producto.' );

		// Seleccionar la hoja (id 3) también.
		$included = \LTMS_Vtex_Price_Calculator::filter_by_category( [ $norm ], [ '3' ] );
		$this->assertCount( 1, $included );

		// Seleccionar una categoría no relacionada → excluye.
		$excluded = \LTMS_Vtex_Price_Calculator::filter_by_category( [ $norm ], [ '99' ] );
		$this->assertCount( 0, $excluded );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Conexión, errores, retry y SSRF.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_test_connection_success(): void {
		$this->require_class( 'LTMS_Api_Vtex' );
		$payload = $this->vtex_search_payload();
		$this->stub_http( static fn() => [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode( $payload ),
		] );

		$result = \LTMS_Api_Vtex::test_connection( 'mistienda', 'k', 't' );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['products_count'] );
		$this->assertSame( 1, $result['skus_count'] );
	}

	public function test_connection_401_reports_error(): void {
		$this->require_class( 'LTMS_Api_Vtex' );
		$this->stub_http( static fn() => [
			'response' => [ 'code' => 401 ],
			'body'     => wp_json_encode( [ 'message' => 'The credentials are not valid' ] ),
		] );

		$result = \LTMS_Api_Vtex::get_products_search( 'mistienda', 'k', 't', 0, 49 );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'The credentials are not valid', $result['error'] );
	}

	public function test_429_retries_once(): void {
		$this->require_class( 'LTMS_Api_Vtex' );

		$calls = 0;
		$this->stub_http( function () use ( &$calls ) {
			$calls++;
			if ( 1 === $calls ) {
				return [ 'response' => [ 'code' => 429 ], 'body' => '{}', 'headers' => [ 'retry-after' => '1' ] ];
			}
			return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( $this->vtex_search_payload() ) ];
		} );

		$result = \LTMS_Api_Vtex::get_products_search( 'mistienda', 'k', 't', 0, 49 );

		$this->assertTrue( $result['success'], 'Debe reintentar tras el 429.' );
		$this->assertSame( 2, $calls, 'wp_remote_request debe llamarse 2 veces (429 + retry).' );
	}

	public function test_build_base_url_ssrf_guard(): void {
		$this->require_class( 'LTMS_Api_Vtex' );

		$this->assertSame( 'https://mistienda.vtexcommercestable.com.br', \LTMS_Api_Vtex::build_base_url( 'mistienda' ) );
		$this->assertSame( 'https://mistienda.mistest.com.br', \LTMS_Api_Vtex::build_base_url( 'mistienda', 'mistest' ) );
		$this->assertSame( '', \LTMS_Api_Vtex::build_base_url( 'evil.com;rm -rf' ) );
		$this->assertSame( '', \LTMS_Api_Vtex::build_base_url( 'a.b.c' ) );
		$this->assertSame( '', \LTMS_Api_Vtex::build_base_url( 'mistienda', 'evil.com' ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Reglas de negocio (mismas que PosGold).
	// ─────────────────────────────────────────────────────────────────────────

	public function test_price_calculation_end_to_end(): void {
		$this->require_class( 'LTMS_Vtex_Price_Calculator' );
		$this->require_class( 'LTMS_PosGold_Price_Calculator' );

		$defaults = \LTMS_Vtex_Price_Calculator::get_defaults();
		$calc     = \LTMS_Vtex_Price_Calculator::calculate( 50000, $defaults );

		// Defaults: margin 30%, comisión 10%, IVA 19%, redondeo 1000.
		$this->assertGreaterThan( 50000, $calc['price'], 'El precio debe superar el costo.' );
		$this->assertSame( 0.0, fmod( $calc['price'], 1000 ), 'Debe redondear al múltiplo de 1000 por encima.' );
		$this->assertSame( $calc['price'], \LTMS_PosGold_Price_Calculator::calculate( 50000, $defaults )['price'],
			'La fórmula debe ser idéntica a la de PosGold.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// E2E — sync_vendor_products completo (API → normalizar → precio → crear WC).
	// ─────────────────────────────────────────────────────────────────────────

	public function test_sync_vendor_products_creates_product_end_to_end(): void {
		$this->require_class( 'LTMS_Vtex_Sync' );
		$this->require_class( 'LTMS_Api_Vtex' );
		$this->require_class( 'LTMS_Vtex_Price_Calculator' );

		// Credenciales del vendor (plain — no cifradas para simplicidad).
		Monkey\Functions\when( 'get_user_meta' )->alias(
			static function ( $user_id, $key = '', $single = false ) {
				$map = [
					'ltms_vtex_account_name'      => 'mistienda',
					'ltms_vtex_environment'       => 'vtexcommercestable',
					'ltms_vtex_app_key'           => 'vtexappkey-test',
					'ltms_vtex_app_token'         => 'vtexapptoken-test',
					'ltms_vtex_last_sync'         => '0',
					'ltms_vtex_category_ids_csv'  => '',
					'ltms_vtex_seo_template'      => '',
				];
				return $map[ $key ] ?? '';
			}
		);
		Monkey\Functions\when( 'update_user_meta' )->justReturn( true );

		// HTTP → 1 página con 1 producto.
		$payload = $this->vtex_search_payload();
		// Sin imagen: download_and_attach_image requiere ABSPATH/wp-admin/includes
		// (no disponible en UNIT_ONLY) — el path de imagen se cubre en normalize.
		$payload[0]['items'][0]['images'] = [];
		$this->stub_http( static fn() => [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode( $payload ),
		] );

		// WooCommerce (create path).
		Monkey\Functions\when( 'wc_get_product_id_by_sku' )->justReturn( 0 );
		Monkey\Functions\when( 'wc_get_product' )->justReturn( null );
		Monkey\Functions\when( 'get_post' )->justReturn( null );
		Monkey\Functions\when( 'wp_update_post' )->justReturn( 1 );
		Monkey\Functions\when( 'get_term_by' )->justReturn( null );
		Monkey\Functions\when( 'wp_insert_term' )->justReturn( [ 'term_id' => 5 ] );
		Monkey\Functions\when( 'sanitize_title' )->alias( static fn( $s ) => strtolower( str_replace( ' ', '-', (string) $s ) ) );
		Monkey\Functions\when( 'wp_rand' )->justReturn( 1 );
		Monkey\Functions\when( 'has_post_thumbnail' )->justReturn( false );
		Monkey\Functions\when( 'download_url' )->justReturn( '/tmp/vtex-img.jpg' );
		Monkey\Functions\when( 'media_handle_sideload' )->justReturn( 77 );
		Monkey\Functions\when( 'set_post_thumbnail' )->justReturn( true );

		$result = \LTMS_Vtex_Sync::sync_vendor_products( 141 );

		$this->assertTrue( $result['success'], 'La sync debe tener éxito: ' . ( $result['message'] ?? '' ) );
		$this->assertSame( 1, $result['created'], 'Debe crear 1 producto.' );
		$this->assertSame( 0, $result['updated'] );
		$this->assertSame( 0, $result['skipped'] );
		$this->assertSame( 'LEV-001', \WC_Product_Simple::$last_sku, 'El SKU creado en WC debe ser el RefId.' );
	}

	public function test_sync_empty_catalog_returns_zero(): void {
		$this->require_class( 'LTMS_Vtex_Sync' );

		Monkey\Functions\when( 'get_user_meta' )->alias(
			static function ( $user_id, $key = '', $single = false ) {
				$map = [
					'ltms_vtex_account_name'     => 'mistienda',
					'ltms_vtex_environment'      => 'vtexcommercestable',
					'ltms_vtex_app_key'          => 'k',
					'ltms_vtex_app_token'        => 't',
					'ltms_vtex_last_sync'        => '0',
					'ltms_vtex_category_ids_csv' => '',
					'ltms_vtex_seo_template'     => '',
				];
				return $map[ $key ] ?? '';
			}
		);
		Monkey\Functions\when( 'update_user_meta' )->justReturn( true );
		$this->stub_http( static fn() => [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode( [] ),
		] );

		$result = \LTMS_Vtex_Sync::sync_vendor_products( 141 );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['created'] );
		$this->assertSame( 0, $result['skipped'] );
	}
}