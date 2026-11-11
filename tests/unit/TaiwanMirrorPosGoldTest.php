<?php
/**
 * TaiwanMirrorPosGoldTest — espejo de las reglas duras de Juguetería Taiwan
 * (sync_posgold.py) sobre la integración PosGold de LTMS.
 *
 * Reglas espejadas (tag TAIWAN-MIRROR, 2026-11-10):
 *   TM-1  Filtro duro "activo": la API V6 puede devolver filas con Activo=false
 *         (verificado en la respuesta real) — un producto desactivado en
 *         PosGold NO entra al marketplace (Taiwan: "activo + existencias + ...").
 *   TM-2  Novedades con motivo: cada producto omitido viaja con su motivo
 *         (Taiwan: novedades_*.xlsx "lo que no publica y por qué").
 *   TM-3  Contrato de formato fail-closed: respuesta 2xx sin contenedor de
 *         productos reconocible → error (Taiwan: "el cron aborta antes de tocar
 *         catalogo.js si los encabezados cambian"); payload 100% sin campo de
 *         existencias → sync abortada sin tocar nada (Taiwan: "si el catálogo
 *         quedara sin existencias, aborta SIN tocar lo publicado").
 *   TM-4  Piso de precio por vendor: precio final < min_price → skip con motivo
 *         (Taiwan regla 11: "por debajo, empacar y despachar cuesta más de lo
 *         que deja").
 *   TM-5  Dedup con mayor existencia: ante el mismo código gana la fila con
 *         MÁS stock (Taiwan regla 1 "deduplicar-mayor-existencia"; empate →
 *         la primera; sin stock se lee 0 y nunca rompe).
 *   TM-6  Clamps defensivos: reglas negativas/corruptas jamás producen un
 *         precio por debajo del costo (Taiwan: "jamás por debajo del precio
 *         rentable").
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
 * Class TaiwanMirrorPosGoldTest
 *
 * @group taiwan-mirror
 */
final class TaiwanMirrorPosGoldTest extends LTMS_Unit_Test_Case {

	private function require_classes(): void {
		$this->require_class( 'LTMS_Api_PosGold' );
		$this->require_class( 'LTMS_PosGold_Price_Calculator' );
		$this->require_class( 'LTMS_PosGold_Sync' );
	}

	/**
	 * Producto PosGold crudo con la forma real de GetProduct_V6.
	 */
	private function raw_product( string $codigo, string $nombre, float $precio, float $stock, bool $activo ): array {
		return [
			'Codigo'      => $codigo,
			'Producto'    => $nombre,
			'Precio1'     => $precio,
			'Stock'       => $stock,
			'Activo'      => $activo,
			'Imagenes'    => [],
			'Categoriaid' => 52,
		];
	}

	/**
	 * Stub HTTP de GetProduct_V6 con una respuesta Status/Msj/Datos.
	 */
	private function stub_posgold_http( array $products ): void {
		$body = wp_json_encode( [
			'Status' => true,
			'Msj'    => 'Consulta realizada exitosamente',
			'Datos'  => $products,
		] );

		Monkey\Functions\when( 'wp_remote_get' )->alias(
			static fn( $url, $args = [] ) => [
				'response' => [ 'code' => 200 ],
				'body'     => $body,
			]
		);
		Monkey\Functions\when( 'is_wp_error' )->alias(
			static fn( $thing ) => $thing instanceof \WP_Error
		);
		Monkey\Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn( $r ) => (int) ( $r['response']['code'] ?? 0 )
		);
		Monkey\Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn( $r ) => (string) ( $r['body'] ?? '' )
		);
		Monkey\Functions\when( 'add_query_arg' )->alias(
			static fn( $args, $url ) => $url . '?' . http_build_query( $args )
		);
	}

	/**
	 * Stub de credenciales + WooCommerce para sync_vendor_products().
	 */
	private function stub_sync_env( array $extra_meta = [] ): void {
		$map = array_merge( [
			'ltms_posgold_subdomain'        => 'mistienda',
			'ltms_posgold_token'            => 't',
			'ltms_posgold_empresaid'        => '1',
			'ltms_posgold_usuarioid'        => '1',
			'ltms_posgold_bodegaid'         => '1',
			'ltms_posgold_last_sync'        => '0',
			'ltms_posgold_last_sync_count'  => '0',
			'ltms_posgold_category_ids'     => '',
			'ltms_posgold_seo_template'     => '',
		], $extra_meta );

		Monkey\Functions\when( 'get_user_meta' )->alias(
			static function ( $user_id, $key = '', $single = false ) use ( $map ) {
				return $map[ $key ] ?? '';
			}
		);
		Monkey\Functions\when( 'update_user_meta' )->justReturn( true );

		// WooCommerce — path de creación (SKU nuevo → producto nuevo).
		Monkey\Functions\when( 'wc_get_product_id_by_sku' )->justReturn( 0 );
		// El gate de vendibilidad (cargado por otros tests de la suite combinada)
		// llama wc_get_product()/get_post() vía apply_gate() — sin estos stubs el
		// exception del helper no-stubeado caería en el try/catch del loop y el
		// producto no se crearía (falsos "created 0").
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
	// TM-3 — contrato de formato en get_products().
	// ─────────────────────────────────────────────────────────────────────────

	public function test_get_products_fails_closed_on_unrecognized_structure(): void {
		$this->require_class( 'LTMS_Api_PosGold' );

		// 2xx + JSON válido + Status=true, pero SIN contenedor conocido ni
		// producto suelto: drift de esquema → error explícito, no "0 productos".
		Monkey\Functions\when( 'wp_remote_get' )->alias(
			static fn( $url, $args = [] ) => [
				'response' => [ 'code' => 200 ],
				'body'     => '{"Status":true,"Msj":"ok","Total":2}',
			]
		);
		Monkey\Functions\when( 'is_wp_error' )->alias( static fn( $t ) => $t instanceof \WP_Error );
		Monkey\Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $r ) => (int) ( $r['response']['code'] ?? 0 ) );
		Monkey\Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $r ) => (string) ( $r['body'] ?? '' ) );
		Monkey\Functions\when( 'add_query_arg' )->alias( static fn( $args, $url ) => $url . '?' . http_build_query( $args ) );

		$result = \LTMS_Api_PosGold::get_products( 'jugueteriataiwan', 'jwt-token' );

		$this->assertFalse( $result['success'], 'Una 2xx sin contenedor de productos debe fallar (contrato de formato).' );
		$this->assertStringContainsStringIgnoringCase( 'estructura', (string) $result['error'] );
	}

	public function test_get_products_allows_legit_empty_catalog(): void {
		$this->require_class( 'LTMS_Api_PosGold' );

		// Catálogo legítimamente vacío: "Datos":[] presente → éxito con 0.
		Monkey\Functions\when( 'wp_remote_get' )->alias(
			static fn( $url, $args = [] ) => [
				'response' => [ 'code' => 200 ],
				'body'     => '{"Status":true,"Msj":"ok","Datos":[]}',
			]
		);
		Monkey\Functions\when( 'is_wp_error' )->alias( static fn( $t ) => $t instanceof \WP_Error );
		Monkey\Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $r ) => (int) ( $r['response']['code'] ?? 0 ) );
		Monkey\Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $r ) => (string) ( $r['body'] ?? '' ) );
		Monkey\Functions\when( 'add_query_arg' )->alias( static fn( $args, $url ) => $url . '?' . http_build_query( $args ) );

		$result = \LTMS_Api_PosGold::get_products( 'jugueteriataiwan', 'jwt-token' );

		$this->assertTrue( $result['success'], 'Un catálogo vacío legítimo (Datos:[]) sigue siendo éxito.' );
		$this->assertSame( [], $result['data'] );
	}

	public function test_normalize_product_reports_stock_presence(): void {
		$this->require_class( 'LTMS_Api_PosGold' );

		$with_stock = \LTMS_Api_PosGold::normalize_product( $this->raw_product( 'A001', 'X', 1000.0, 5.0, true ) );
		$this->assertTrue( $with_stock['_stock_present'], 'Un payload con Stock debe marcar _stock_present.' );

		// Solo Disponible (sin Stock): presencia sí (el guard no dispara),
		// aunque la fuente del VALOR siga siendo el pick de Stock.
		$raw_disponible = $this->raw_product( 'A002', 'Y', 1000.0, 0.0, true );
		unset( $raw_disponible['Stock'] );
		$raw_disponible['Disponible'] = 0.0;
		$disponible_only = \LTMS_Api_PosGold::normalize_product( $raw_disponible );
		$this->assertTrue( $disponible_only['_stock_present'], 'Disponible también cuenta como campo de existencias presente.' );

		// Sin ningún campo de existencias → false (alimenta el guard de sync).
		$raw_sin = $this->raw_product( 'A003', 'Z', 1000.0, 0.0, true );
		unset( $raw_sin['Stock'] );
		$sin_stock = \LTMS_Api_PosGold::normalize_product( $raw_sin );
		$this->assertFalse( $sin_stock['_stock_present'], 'Sin Stock/Disponible/Existencia debe marcar _stock_present=false.' );
		$this->assertSame( 0, $sin_stock['stock_quantity'], 'Sin campo de existencias el stock normalizado es 0 (por eso el guard existe).' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// TM-5 — dedup con mayor existencia.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_deduplicate_by_sku_keeps_row_with_most_stock(): void {
		$this->require_class( 'LTMS_PosGold_Price_Calculator' );

		$rows = [
			[ 'codigo' => 'A001', 'stock' => 2.0, 'name' => 'primera' ],
			[ 'codigo' => 'a001', 'stock' => 9.0, 'name' => 'segunda-mayor-stock' ],
			[ 'codigo' => 'A001', 'stock' => 5.0, 'name' => 'tercera' ],
		];

		$result = \LTMS_PosGold_Price_Calculator::deduplicate_by_sku( $rows );

		$this->assertCount( 1, $result['unique'], 'Las 3 filas del mismo código deben colapsar a 1.' );
		$this->assertSame( 9.0, (float) $result['unique'][0]['stock'], 'Debe ganar la fila con MÁS existencias (regla 1 de Taiwan).' );
		$this->assertCount( 2, $result['duplicates'], 'Las filas perdedoras van a duplicates.' );
	}

	public function test_deduplicate_by_sku_tie_keeps_first_and_empty_sku_passes(): void {
		$this->require_class( 'LTMS_PosGold_Price_Calculator' );

		$rows = [
			[ 'codigo' => 'B001', 'stock' => 4.0, 'name' => 'primera' ],
			[ 'codigo' => 'B001', 'stock' => 4.0, 'name' => 'empate' ],
			[ 'codigo' => '', 'stock' => 0.0, 'name' => 'sin-sku' ],
		];

		$result = \LTMS_PosGold_Price_Calculator::deduplicate_by_sku( $rows );

		$this->assertSame( 'primera', $result['unique'][0]['name'], 'Empate → se conserva la primera.' );
		$this->assertCount( 2, $result['unique'], 'La fila sin SKU pasa sin depurar.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// TM-6 — clamps defensivos del cálculo.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_calculate_clamps_negative_rules_so_price_never_drops_below_cost(): void {
		$this->require_class( 'LTMS_PosGold_Price_Calculator' );

		$rules = [
			'transport_amount'       => -5000.0,
			'advertising_amount'     => -3000.0,
			'returns_pct'            => -5.0,
			'margin_pct'             => -50.0,
			'lotengo_commission_pct' => -12.0,
			'iva_pct'                => -19.0,
			'redi_cost_pct'          => -10.0,
			'is_redi'                => true,
			'round_multiple'         => 1000,
		];

		$calc = \LTMS_PosGold_Price_Calculator::calculate( 10000.0, $rules );

		$this->assertSame( 0.0, (float) $calc['breakdown']['transport'], 'Un monto de transporte negativo se clampa a 0.' );
		$this->assertSame( 0.0, (float) $calc['breakdown']['advertising'], 'Un monto de publicidad negativo se clampa a 0.' );
		$this->assertSame( 0.0, (float) $calc['breakdown']['margin'], 'Un margen negativo se clampa a 0.' );
		$this->assertSame( 0.0, (float) $calc['breakdown']['iva'], 'Un IVA negativo se clampa a 0.' );
		$this->assertSame( 0.0, (float) $calc['breakdown']['redi_cost'], 'El costo ReDi negativo se clampa a 0.' );
		$this->assertSame( 10000.0, (float) $calc['price'], 'Con reglas corruptas el precio jamás queda por debajo del costo (10000 + 0s → 10000).' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// TM-1/TM-2/TM-3/TM-4 — sync_vendor_products end-to-end.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_sync_skips_inactive_product_with_novedades(): void {
		$this->require_classes();
		\WC_Product_Simple::$last_sku = '';
		$this->stub_sync_env();
		$this->stub_posgold_http( [
			$this->raw_product( 'A001', 'PRODUCTO ACTIVO', 22000.0, 5.0, true ),
			$this->raw_product( 'A002', 'PRODUCTO INACTIVO', 18000.0, 7.0, false ),
		] );

		$result = \LTMS_PosGold_Sync::sync_vendor_products( 141 );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['created'], 'Solo el producto ACTIVO se crea.' );
		$this->assertSame( 'A001', \WC_Product_Simple::$last_sku );
		$this->assertSame( 1, $result['skipped'], 'El inactivo se omite.' );
		$this->assertSame(
			[ [ 'codigo' => 'A002', 'motivo' => 'inactivo' ] ],
			$result['skipped_products'],
			'TM-2: el inactivo va a las novedades con su motivo.'
		);
		$this->assertSame( [ 'inactivo' => 1 ], $result['skipped_reasons'] );
	}

	public function test_sync_aborts_when_payload_has_no_stock_field(): void {
		$this->require_classes();
		\WC_Product_Simple::$last_sku = '';
		$this->stub_sync_env();

		// Payload activo con nombre y precio pero SIN ningún campo de
		// existencias: drift de esquema → sync abortada SIN crear productos
		// (hoy habría puesto TODOS los stocks en 0 y el gate ocultaría todo).
		$raw_a = $this->raw_product( 'B001', 'SIN STOCK EN PAYLOAD', 1000.0, 0.0, true );
		$raw_b = $this->raw_product( 'B002', 'TAMBIEN SIN STOCK', 2000.0, 0.0, true );
		unset( $raw_a['Stock'], $raw_b['Stock'] );
		$this->stub_posgold_http( [ $raw_a, $raw_b ] );

		$result = \LTMS_PosGold_Sync::sync_vendor_products( 141 );

		$this->assertFalse( $result['success'], 'TM-3: payload 100% sin existencias debe abortar la sync.' );
		$this->assertStringContainsString( 'existencias', (string) $result['message'] );
		$this->assertSame( 0, $result['created'], 'Ningún producto se toca (aborta sin tocar lo publicado).' );
		$this->assertSame( '', \WC_Product_Simple::$last_sku );
	}

	public function test_sync_skips_below_min_price_with_novedades(): void {
		$this->require_classes();
		\WC_Product_Simple::$last_sku = '';
		$this->stub_sync_env( [
			'ltms_posgold_price_min_price' => '999999', // Piso altísimo → nada pasa.
		] );
		$this->stub_posgold_http( [
			$this->raw_product( 'C001', 'BAJO EL PISO', 22000.0, 5.0, true ),
		] );

		$result = \LTMS_PosGold_Sync::sync_vendor_products( 141 );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['created'], 'TM-4: por debajo del piso de precio no se enlista.' );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame(
			[ [ 'codigo' => 'C001', 'motivo' => 'precio_minimo' ] ],
			$result['skipped_products']
		);
		$this->assertSame( [ 'precio_minimo' => 1 ], $result['skipped_reasons'] );
		$this->assertStringContainsString( 'precio_minimo', (string) $result['message'], 'El resumen de omisiones viaja en el mensaje.' );
	}

	public function test_sync_reports_incomplete_products_in_novedades(): void {
		$this->require_classes();
		\WC_Product_Simple::$last_sku = '';
		$this->stub_sync_env();

		// Producto sin precio (Precio1 ausente) → incompleto con motivo.
		$raw = $this->raw_product( 'D001', 'SIN PRECIO', 0.0, 5.0, true );
		unset( $raw['Precio1'] );
		$this->stub_posgold_http( [ $raw ] );

		$result = \LTMS_PosGold_Sync::sync_vendor_products( 141 );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['created'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame(
			[ [ 'codigo' => 'D001', 'motivo' => 'incompleto: precio' ] ],
			$result['skipped_products'],
			'TM-2: la omisión por incompletitud viaja con el campo faltante.'
		);
	}

	public function test_sync_min_price_zero_default_does_not_skip(): void {
		$this->require_classes();
		\WC_Product_Simple::$last_sku = '';
		$this->stub_sync_env(); // Sin meta min_price → default 0 = deshabilitado.
		$this->stub_posgold_http( [
			$this->raw_product( 'E001', 'PRODUCTO BARATO PERO VALIDO', 2000.0, 5.0, true ),
		] );

		$result = \LTMS_PosGold_Sync::sync_vendor_products( 141 );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['created'], 'Con min_price=0 (default) nada se omite por piso.' );
		$this->assertSame( [], $result['skipped_products'] );
	}
}
