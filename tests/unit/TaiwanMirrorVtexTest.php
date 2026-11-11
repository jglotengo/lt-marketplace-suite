<?php
/**
 * TaiwanMirrorVtexTest — espejo de las reglas duras de Juguetería Taiwan sobre
 * la integración VTEX de LTMS (las que aplican: TM-2 novedades, TM-3 contrato
 * de formato, TM-4 piso de precio).
 *
 * No aplica en VTEX el filtro duro "activo" (TM-1): la Fase B del sync
 * (VTEX-CATALOGO-FIX) descarga DELIBERADAMENTE agotados/inactivos vía
 * sitemaps — el visibility gate los oculta del público. Tampoco aplica el
 * dedup max-stock (TM-5): VTEX dedup por productId entre fases y cada item es
 * un SKU único.
 *
 * @package LTMS\Tests\Unit
 *
 * Ejecutar con: ./vendor/bin/phpunit --testsuite=unit --group taiwan-mirror
 *
 * @group taiwan-mirror
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;

require_once __DIR__ . '/stubs/wc-product-simple-stub.php';

/**
 * Class TaiwanMirrorVtexTest
 *
 * @group taiwan-mirror
 */
final class TaiwanMirrorVtexTest extends LTMS_Unit_Test_Case {

	private function require_classes(): void {
		$this->require_class( 'LTMS_Api_Vtex' );
		$this->require_class( 'LTMS_Vtex_Price_Calculator' );
		$this->require_class( 'LTMS_Vtex_Sync' );
	}

	/**
	 * Stubs HTTP + utilidades WP usadas por LTMS_Api_Vtex (mismo harness que
	 * VtexFunctionalE2ETest).
	 */
	private function stub_vtex_http( $request_body ): void {
		Monkey\Functions\when( 'wp_remote_request' )->alias(
			static fn( $url, $args = [] ) => [
				'response' => [ 'code' => 200 ],
				'body'     => $request_body,
			]
		);
		// Sitemaps (Fase B): 404 → sin slugs, la Fase B no corre.
		Monkey\Functions\when( 'wp_remote_get' )->alias(
			static fn( $url, $args = [] ) => [
				'response' => [ 'code' => 404 ],
				'body'     => 'Not Found',
			]
		);
		Monkey\Functions\when( 'is_wp_error' )->justReturn( false );
		Monkey\Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn( $r ) => (int) ( $r['response']['code'] ?? 0 )
		);
		Monkey\Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn( $r ) => (string) ( $r['body'] ?? '' )
		);
		Monkey\Functions\when( 'wp_remote_retrieve_header' )->alias(
			static fn( $r, $h ) => (string) ( $r['headers'][ $h ] ?? '' )
		);
		Monkey\Functions\when( 'add_query_arg' )->alias(
			static fn( $args, $url ) => $url . '?' . http_build_query( $args )
		);
	}

	private function vtex_payload(): array {
		return [
			[
				'productId'      => '1001',
				'productName'    => 'Jeans Azules',
				'brand'          => 'Levis',
				'categories'     => [ '/Moda/', '/Moda/Jeans/' ],
				'categoriesIds'  => [ '/2/', '/2/3/' ],
				'categoryId'     => '3',
				'items'          => [
					[
						'itemId' => '2001',
						'name'   => 'Jeans Azules 32',
						'refId'  => 'LEV-001',
						'images' => [],
						'sellers' => [ [ 'sellerId' => '1', 'commertialOffer' => [ 'Price' => 120000, 'ListPrice' => 150000, 'AvailableQuantity' => 25, 'IsAvailable' => true ] ] ],
					],
				],
			],
		];
	}

	private function stub_sync_env( array $extra_meta = [] ): void {
		$map = array_merge( [
			'ltms_vtex_account_name'      => 'mistienda',
			'ltms_vtex_environment'       => 'vtexcommercestable',
			'ltms_vtex_app_key'           => 'k',
			'ltms_vtex_app_token'         => 't',
			'ltms_vtex_last_sync'         => '0',
			'ltms_vtex_last_sync_count'   => '0',
			'ltms_vtex_category_ids_csv'  => '',
			'ltms_vtex_seo_template'      => '',
		], $extra_meta );

		Monkey\Functions\when( 'get_user_meta' )->alias(
			static function ( $user_id, $key = '', $single = false ) use ( $map ) {
				return $map[ $key ] ?? '';
			}
		);
		Monkey\Functions\when( 'update_user_meta' )->justReturn( true );

		Monkey\Functions\when( 'wc_get_product_id_by_sku' )->justReturn( 0 );
		// Gate de vendibilidad cargado por otros tests de la suite combinada —
		// ver nota en TaiwanMirrorPosGoldTest::stub_sync_env().
		Monkey\Functions\when( 'wc_get_product' )->justReturn( null );
		Monkey\Functions\when( 'get_post' )->justReturn( null );
		Monkey\Functions\when( 'wp_update_post' )->justReturn( 1 );
		Monkey\Functions\when( 'get_terms' )->justReturn( [] );
		Monkey\Functions\when( 'get_term_by' )->justReturn( null );
		Monkey\Functions\when( 'wp_insert_term' )->justReturn( [ 'term_id' => 5 ] );
		Monkey\Functions\when( 'sanitize_title' )->alias(
			static fn( $s ) => strtolower( str_replace( ' ', '-', (string) $s ) )
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// TM-3 — contrato de formato en get_products_search().
	// ─────────────────────────────────────────────────────────────────────────

	public function test_get_products_search_fails_closed_on_non_list_payload(): void {
		$this->require_class( 'LTMS_Api_Vtex' );

		// 2xx + JSON válido pero NO es la lista que devuelve el Search API.
		$this->stub_vtex_http( '{"foo":"bar"}' );

		$result = \LTMS_Api_Vtex::get_products_search( 'mistienda', 'k', 't', 0, 49 );

		$this->assertFalse( $result['success'], 'Una 2xx sin lista de productos debe fallar (contrato de formato).' );
		$this->assertStringContainsStringIgnoringCase( 'estructura', (string) $result['error'] );
	}

	public function test_get_products_search_accepts_empty_list(): void {
		$this->require_class( 'LTMS_Api_Vtex' );

		$this->stub_vtex_http( '[]' );

		$result = \LTMS_Api_Vtex::get_products_search( 'mistienda', 'k', 't', 0, 49 );

		$this->assertTrue( $result['success'], 'Una lista vacía [] sigue siendo éxito.' );
		$this->assertSame( [], $result['data'] );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// TM-4 — defaults con piso de precio y paridad PosGold.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_defaults_include_min_price_with_posgold_parity(): void {
		$this->require_class( 'LTMS_Vtex_Price_Calculator' );
		$this->require_class( 'LTMS_PosGold_Price_Calculator' );

		$vtex    = \LTMS_Vtex_Price_Calculator::get_defaults();
		$posgold = \LTMS_PosGold_Price_Calculator::get_defaults();

		$this->assertArrayHasKey( 'min_price', $vtex, 'Los defaults VTEX deben incluir min_price (piso de precio, TM-4).' );
		$this->assertSame( 0.0, (float) $vtex['min_price'], 'El piso default es 0 (deshabilitado) — sin cambio de comportamiento.' );
		$this->assertSame( $posgold, $vtex, 'La paridad de defaults PosGold/VTEX se mantiene con la key nueva.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// TM-2/TM-4 — sync_vendor_products end-to-end.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_sync_skips_below_min_price_with_novedades(): void {
		$this->require_classes();
		\WC_Product_Simple::$last_sku = '';
		$this->stub_sync_env( [
			'ltms_vtex_price_min_price' => '999999', // Piso altísimo → nada pasa.
		] );
		$this->stub_vtex_http( wp_json_encode( $this->vtex_payload() ) );

		$result = \LTMS_Vtex_Sync::sync_vendor_products( 141 );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['created'], 'TM-4: por debajo del piso de precio no se enlista.' );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame(
			[ [ 'codigo' => 'LEV-001', 'motivo' => 'precio_minimo' ] ],
			$result['skipped_products']
		);
		$this->assertSame( [ 'precio_minimo' => 1 ], $result['skipped_reasons'] );
	}

	public function test_sync_reports_incomplete_products_in_novedades(): void {
		$this->require_classes();
		\WC_Product_Simple::$last_sku = '';
		$this->stub_sync_env();

		// SKU sin commertialOffer → precio 0 → incompleto con motivo.
		$payload = $this->vtex_payload();
		unset( $payload[0]['items'][0]['sellers'] );
		$this->stub_vtex_http( wp_json_encode( $payload ) );

		$result = \LTMS_Vtex_Sync::sync_vendor_products( 141 );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['created'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame(
			[ [ 'codigo' => 'LEV-001', 'motivo' => 'incompleto: precio' ] ],
			$result['skipped_products'],
			'TM-2: la omisión por incompletitud viaja con el campo faltante.'
		);
		$this->assertSame( [ 'incompleto: precio' => 1 ], $result['skipped_reasons'] );
	}

	public function test_sync_min_price_zero_default_does_not_skip(): void {
		$this->require_classes();
		\WC_Product_Simple::$last_sku = '';
		$this->stub_sync_env(); // Sin meta min_price → default 0 = deshabilitado.
		$this->stub_vtex_http( wp_json_encode( $this->vtex_payload() ) );

		$result = \LTMS_Vtex_Sync::sync_vendor_products( 141 );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['created'], 'Con min_price=0 (default) nada se omite por piso.' );
		$this->assertSame( [], $result['skipped_products'] );
	}
}
