<?php
/**
 * PosGoldApiDocsParityTest — paridad del cliente PosGold con la documentación
 * REAL de la API (Postman "posgold-api", verificada live contra
 * jugueteriataiwan.goldpos.com.co el 2026-09-27).
 *
 * Hallazgos cubiertos (POSGOLD-DOCS-PARITY, causa raíz de "no me carga
 * categorías" en la conexión de datos del panel de vendedor):
 *
 *   - P0: la respuesta real de la API es {"Status":true,"Msj":"...","Datos":[...]}
 *     — la clave "Datos" faltaba en extract_products_array()/
 *     extract_categories_array() → el catálogo llegaba vacío → el dropdown de
 *     categorías del filtro PosGold mostraba "No se encontraron categorías" y
 *     la sync encontraba 0 productos. (Verificado live: HTTP 200 con 4.6 MB de
 *     productos bajo "Datos".)
 *   - P1: el endpoint de categorías usado (/apiGold/CategoriaApi/GetCategoria)
     NO existe en la API real (HTTP 404 live). La doc define
     /apiGold/CategoriaAPI/GetCategoriasGrupos?empresaid=1 (HTTP 200 live).
 *   - P1: normalize_product() no mapeaba los nombres reales de la API V6:
 *     "Producto" (nombre), "Precio1" (precio), "Categoriaid" (categoría —
 *     case-sensitive: 'CategoriaId' NO matchea), "ProductoImpuestoPorcentaje"
 *     (IVA), "Imagenes"/"CodigoBarras" (arrays), "Producto_ref" (referencia).
 *     Sin esto, los productos se omitían por "incompletos" (sin nombre/precio)
 *     y el filtro por categoriaid nunca matcheaba.
 *   - P0 relacionado: la respuesta puede venir HTTP 200 con Status=false y el
 *     motivo en "Msj" — antes se trataba como catálogo vacío silencioso.
 *   - POSGOLD-CAT-DROPDOWN: view-products.php lista product_cat con get_terms()
 *     plano → los duplicados heredados de la sync (mismo nombre, slug distinto)
 *     repetían cada categoría en el select del modal Nuevo/Editar del panel de
 *     vendedor. Ahora usa LTMS_Utils::get_deduped_product_categories().
 *
 * Respuesta real documentada (Productos_V6, Postman):
 *   {"Status":true,"Msj":"Consulta realizada exitosamente","Datos":[
 *     {"Productoid":30316,"Producto_cod":"%102547%","Producto_ref":"T146TFB66LPVC",
 *      "Codigo":"%102547%","Producto":"DOMINO COLOR SOLIDO ESTUCHE","Precio1":22000.0,
 *      "CodigoBarras":[],"Stock":0.0,"Imagenes":["04142-1.jpg"],"Categoria":"JUEGO DE MESA",
 *      "Grupo":"NO APLICA","Categoriaid":52,"Grupoid":50,
 *      "ProductoImpuestoPorcentaje":19.0,"Tags":[],"Activo":false}, ...]}
 *
 * @package LTMS\Tests\Unit
 *
 * Ejecutar con: ./vendor/bin/phpunit --testsuite=unit --group posgold-api-docs
 *
 * @group posgold-api-docs
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * Class PosGoldApiDocsParityTest
 *
 * @group posgold-api-docs
 */
final class PosGoldApiDocsParityTest extends LTMS_Unit_Test_Case {

	/**
	 * Respuesta REAL (condensada) de GetProduct_V6 capturada live contra
	 * jugueteriataiwan.goldpos.com.co — estructura Status/Msj/Datos.
	 */
	private function real_api_response(): array {
		return [
			'Status' => true,
			'Msj'    => 'Consulta realizada exitosamente',
			'Datos'  => [
				[
					'Productoid'                 => 30316,
					'Producto_cod'               => '%102547%',
					'Producto_ref'               => 'T146TFB66LPVC',
					'Disponible'                 => 0.0,
					'Codigo'                     => '%102547%',
					'Texto_adicional'            => null,
					'Producto'                   => 'DOMINO COLOR SOLIDO ESTUCHE',
					'Precio1'                    => 22000.0,
					'Precio2'                    => 28000.0,
					'Precio9'                    => 35000.0,
					'CodigoBarras'               => [],
					'Stock'                      => 0.0,
					'Imagenes'                   => [ '04142-1.jpg' ],
					'Categoria'                  => 'JUEGO DE MESA',
					'Grupo'                      => 'NO APLICA',
					'Categoriaid'                => 52,
					'Grupoid'                    => 50,
					'ProductoGrupoDescripcion'   => 'GRAVADOS AL 19%',
					'ProductoImpuestoPorcentaje' => 19.0,
					'Tags'                       => [],
					'Activo'                     => false,
				],
				[
					'Productoid'                 => 30345,
					'Producto_cod'               => '001309',
					'Producto_ref'               => '865',
					'Codigo'                     => '001309',
					'Producto'                   => 'ABEJA BASTON BOLSA',
					'Precio1'                    => 6500.0,
					'CodigoBarras'               => [],
					'Stock'                      => 5.0,
					'Imagenes'                   => [ '001309-1.jpeg' ],
					'Categoria'                  => 'BEBES',
					'Grupo'                      => 'NO APLICA',
					'Categoriaid'                => 42,
					'Grupoid'                    => 50,
					'ProductoImpuestoPorcentaje' => 19.0,
					'Activo'                     => true,
				],
			],
		];
	}

	/**
	 * Stubs de HTTP + add_query_arg para ejercer el cliente real sin red.
	 * Devuelve $captured_urls con cada URL solicitada.
	 */
	private function stub_http( array $responses_by_marker, array &$captured_urls ): void {
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url = null ) {
				if ( is_array( $args ) ) {
					$base = (string) $url;
					$sep  = str_contains( $base, '?' ) ? '&' : '?';
					return $base . $sep . http_build_query( $args );
				}
				return (string) $url;
			}
		);
		Functions\when( 'wp_remote_get' )->alias(
			static function ( $url, $args = [] ) use ( $responses_by_marker, &$captured_urls ) {
				$captured_urls[] = (string) $url;
				foreach ( $responses_by_marker as $marker => $response ) {
					if ( str_contains( (string) $url, $marker ) ) {
						return $response;
					}
				}
				return [ 'response' => [ 'code' => 404 ], 'body' => '' ];
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return is_array( $response ) ? ( $response['response']['code'] ?? 0 ) : 0;
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ) {
				return is_array( $response ) ? ( $response['body'] ?? '' ) : '';
			}
		);
		Functions\when( 'is_wp_error' )->alias(
			static fn( $thing ) => $thing instanceof \WP_Error
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Endpoint de categorías — paridad con la doc (Obtener Categorias y Grupos)
	// ─────────────────────────────────────────────────────────────────────────

	public function test_endpoint_categories_matches_documented_path(): void {
		$this->require_class( 'LTMS_Api_PosGold' );

		$this->assertSame(
			'/apiGold/CategoriaAPI/GetCategoriasGrupos',
			\LTMS_Api_PosGold::ENDPOINT_CATEGORIES,
			'La doc de PosGold define /apiGold/CategoriaAPI/GetCategoriasGrupos — el path anterior (CategoriaApi/GetCategoria) devuelve HTTP 404 en la API real.'
		);
	}

	public function test_endpoint_products_matches_documented_path(): void {
		$this->require_class( 'LTMS_Api_PosGold' );

		$this->assertSame(
			'/apiGold/ProductoApi/GetProduct_V6',
			\LTMS_Api_PosGold::ENDPOINT_PRODUCTS,
			'La doc de PosGold define /apiGold/ProductoApi/GetProduct_V6 (Productos_V6).'
		);
	}

	public function test_get_categories_calls_docs_endpoint_with_only_empresaid(): void {
		$this->require_class( 'LTMS_Api_PosGold' );

		$captured = [];
		// El endpoint de la doc devuelve un array desnudo ([] verificado live con
		// esta empresa) → sin categorías → cae al fallback (productos). Solo
		// verificamos aquí la URL del primer intento.
		$this->stub_http(
			[ 'GetCategoriasGrupos' => [ 'response' => [ 'code' => 200 ], 'body' => '[]' ] ],
			$captured
		);

		\LTMS_Api_PosGold::get_categories( 'jugueteriataiwan', 'jwt-token', 1, 1 );

		$this->assertNotEmpty( $captured, 'Debe haber al menos un request HTTP.' );
		$first = $captured[0];
		$this->assertStringContainsString( 'https://jugueteriataiwan.goldpos.com.co/apiGold/CategoriaAPI/GetCategoriasGrupos', $first, 'Debe llamar al endpoint documentado del subdominio del vendor.' );
		$this->assertStringContainsString( 'empresaid=1', $first, 'Debe enviar empresaid.' );
		$this->assertStringNotContainsString( 'usuarioid=', $first, 'La doc del endpoint de categorías solo define empresaid.' );
		$this->assertStringNotContainsString( 'activo=', $first, 'La doc del endpoint de categorías solo define empresaid.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Extracción "Datos" — causa raíz de "no me carga categorías"
	// ─────────────────────────────────────────────────────────────────────────

	public function test_get_products_extracts_datos_key_from_real_response(): void {
		$this->require_class( 'LTMS_Api_PosGold' );

		$captured = [];
		$this->stub_http(
			[ 'GetProduct_V6' => [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( $this->real_api_response() ) ] ],
			$captured
		);

		$result = \LTMS_Api_PosGold::get_products( 'jugueteriataiwan', 'jwt-token' );

		$this->assertTrue( $result['success'], 'La respuesta real (Status/Msj/Datos) debe extraer el catálogo completo.' );
		$this->assertCount( 2, $result['data'], 'La clave "Datos" faltaba en extract_products_array — antes devolvía 0 productos.' );
		// get_products() devuelve los productos RAW (la normalización corre en el
		// sync vía normalize_product) — los keys aquí son los crudos de la API.
		$this->assertSame( 'DOMINO COLOR SOLIDO ESTUCHE', $result['data'][0]['Producto'] );
		$this->assertSame( 22000.0, $result['data'][0]['Precio1'] );
		$this->assertSame( 'JUEGO DE MESA', $result['data'][0]['Categoria'] );
		$this->assertSame( 52, $result['data'][0]['Categoriaid'] );
	}

	public function test_get_products_status_false_returns_error_with_msj(): void {
		$this->require_class( 'LTMS_Api_PosGold' );

		$captured = [];
		$this->stub_http(
			[ 'GetProduct_V6' => [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( [ 'Status' => false, 'Msj' => 'Empresa no encontrada' ] ) ] ],
			$captured
		);

		$result = \LTMS_Api_PosGold::get_products( 'jugueteriataiwan', 'jwt-token' );

		$this->assertFalse( $result['success'], 'HTTP 200 con Status=false debe tratarse como error.' );
		$this->assertSame( 'Empresa no encontrada', $result['error'], 'El motivo debe salir del campo Msj (patrón de la doc PosGold).' );
		$this->assertEmpty( $result['data'] );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// normalize_product — nombres reales de la API V6
	// ─────────────────────────────────────────────────────────────────────────

	public function test_normalize_product_maps_real_api_field_names(): void {
		$this->require_class( 'LTMS_Api_PosGold' );

		$normalized = \LTMS_Api_PosGold::normalize_product( $this->real_api_response()['Datos'][0] );

		$this->assertSame( 'DOMINO COLOR SOLIDO ESTUCHE', $normalized['name'], 'El nombre viene en "Producto" — antes quedaba vacío y el producto se omitía por incompleto.' );
		$this->assertSame( 'DOMINO COLOR SOLIDO ESTUCHE', $normalized['descripcion'] );
		$this->assertSame( 22000.0, $normalized['precio'], 'El precio viene en "Precio1" — antes quedaba en 0.' );
		$this->assertSame( 22000.0, $normalized['regular_price'] );
		$this->assertSame( '52', $normalized['categoria_id'], 'La categoría viene en "Categoriaid" — case-sensitive, "CategoriaId" no matchea. El filtro de categorías dependía de esto.' );
		$this->assertSame( 'JUEGO DE MESA', $normalized['categoria'] );
		$this->assertSame( '50', $normalized['grupo_id'], 'El grupo viene en "Grupoid" (case-sensitive).' );
		$this->assertSame( 19.0, $normalized['iva'], 'El IVA viene en "ProductoImpuestoPorcentaje".' );
		$this->assertSame( 'T146TFB66LPVC', $normalized['modelo'], 'La referencia viene en "Producto_ref".' );
		$this->assertSame( '%102547%', $normalized['codigo'] );
	}

	public function test_normalize_product_bare_image_filename_is_discarded(): void {
		$this->require_class( 'LTMS_Api_PosGold' );

		// La API devuelve filenames desnudos ("04142-1.jpg") — las rutas del
		// subdominio devuelven 404 y el catálogo requiere sesión: no hay URL base
		// verificable para construir una URL descargable.
		$normalized = \LTMS_Api_PosGold::normalize_product( $this->real_api_response()['Datos'][0] );

		$this->assertSame( '', $normalized['imagen_url'], 'Un filename desnuo no debe usarse como URL de imagen (download_url fallaría silenciosamente).' );
	}

	public function test_normalize_product_keeps_absolute_image_url(): void {
		$this->require_class( 'LTMS_Api_PosGold' );

		$raw           = $this->real_api_response()['Datos'][0];
		$raw['Imagenes'] = [ 'https://cdn.goldpos.com.co/imagenes/04142-1.jpg' ];

		$normalized = \LTMS_Api_PosGold::normalize_product( $raw );

		$this->assertSame( 'https://cdn.goldpos.com.co/imagenes/04142-1.jpg', $normalized['imagen_url'], 'Una URL absoluta sí es descargable y debe conservarse.' );
	}

	public function test_normalize_product_takes_first_barcode_from_array(): void {
		$this->require_class( 'LTMS_Api_PosGold' );

		$raw              = $this->real_api_response()['Datos'][1];
		$raw['CodigoBarras'] = [ '7701234567890', '7701234567891' ];

		$normalized = \LTMS_Api_PosGold::normalize_product( $raw );

		$this->assertSame( '7701234567890', $normalized['barcode'], 'CodigoBarras viene como array — debe tomarse el primer elemento.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Fallback de categorías + filtro — end-to-end con la respuesta real
	// ─────────────────────────────────────────────────────────────────────────

	public function test_get_categories_fallback_extracts_from_products_with_datos(): void {
		$this->require_class( 'LTMS_Api_PosGold' );
		$this->require_class( 'LTMS_PosGold_Price_Calculator' );

		$captured = [];
		$this->stub_http(
			[
				// Endpoint de la doc devuelve [] para esta empresa (verificado live).
				'GetCategoriasGrupos' => [ 'response' => [ 'code' => 200 ], 'body' => '[]' ],
				// Fallback: productos con la estructura real Status/Msj/Datos.
				'GetProduct_V6'       => [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( $this->real_api_response() ) ],
			],
			$captured
		);

		$result = \LTMS_Api_PosGold::get_categories( 'jugueteriataiwan', 'jwt-token', 1, 1 );

		$this->assertTrue( $result['success'], 'El fallback debe extraer categorías desde productos cuando el endpoint de la doc devuelve vacío.' );
		$this->assertSame( 'fallback', $result['source'] );
		$this->assertNotEmpty( $result['categories'], 'Deben extraerse las 2 categorías (JUEGO DE MESA 52, BEBES 42) — antes: "No se encontraron categorías".' );
		$this->assertCount( 2, $result['categories'] );

		$categories = $result['categories'];
		$ids        = array_map( static fn( $c ) => $c['id'], $categories );
		$this->assertContains( '52', $ids, 'La categoría 52 (JUEGO DE MESA) debe venir de Categoriaid.' );
		$this->assertContains( '42', $ids, 'La categoría 42 (BEBES) debe venir de Categoriaid.' );
	}

	public function test_filter_by_category_matches_real_categoriaid(): void {
		$this->require_class( 'LTMS_PosGold_Price_Calculator' );
		$this->require_class( 'LTMS_Api_PosGold' );

		$normalized = array_map(
			[ \LTMS_Api_PosGold::class, 'normalize_product' ],
			$this->real_api_response()['Datos']
		);

		$filtered = \LTMS_PosGold_Price_Calculator::filter_by_category( $normalized, [ '52' ] );

		$this->assertCount( 1, $filtered, 'El filtro por categoriaid debe matchear con el valor real "52" de la API — antes categoria_id quedaba vacío y el filtro excluía TODO.' );
		$first = array_values( $filtered )[0];
		$this->assertSame( 'JUEGO DE MESA', $first['categoria'] );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// POSGOLD-CAT-DROPDOWN — dedup de product_cat en el form de productos
	// ─────────────────────────────────────────────────────────────────────────

	public function test_get_deduped_product_categories_collapses_duplicates_by_name(): void {
		$this->require_class( 'LTMS_Utils' );

		// La query SQL hace el GROUP BY (el dedup real ocurre en MySQL); el mock
		// devuelve las filas YA agrupadas (3 nombres únicos con el term_id
		// canónico MIN) para verificar la normalización a objetos — mismo patrón
		// que StorefrontCategoryDedupTest::mock_wpdb_with_categories().
		global $wpdb;
		$wpdb = new class() {
			public string $terms          = 'wp_terms';
			public string $term_taxonomy  = 'wp_term_taxonomy';
			public function get_results( $query ): array {
				return [
					(object) [ 'term_id' => '12', 'name' => 'Juego de Mesa' ],
					(object) [ 'term_id' => '4',  'name' => 'Bebes' ],
					(object) [ 'term_id' => '7',  'name' => 'Muñecas' ],
				];
			}
		};

		$cats = \LTMS_Utils::get_deduped_product_categories();

		$this->assertIsArray( $cats );
		$this->assertCount( 3, $cats, 'Debe devolver las 3 categorías únicas (lo que get_terms() plano mostraba repetido N veces en el select del panel).' );

		$by_name = [];
		foreach ( $cats as $cat ) {
			$this->assertIsObject( $cat );
			$this->assertGreaterThan( 0, $cat->term_id, 'Cada categoría debe exponer su term_id canónico.' );
			$this->assertNotSame( '', $cat->name );
			$by_name[ $cat->name ] = $cat->term_id;
		}

		$this->assertSame( 12, $by_name['Juego de Mesa'] );
		$this->assertSame( 4, $by_name['Bebes'] );
		$this->assertSame( 7, $by_name['Muñecas'] );
	}

	public function test_deduped_query_groups_by_name_with_canonical_term_id(): void {
		// El dedup real ocurre en la query SQL (GROUP BY t.name + MIN(term_id)) —
		// misma estrategia que la migración CAT-DEDUP-001 y
		// get_vendor_categories() del storefront.
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/includes/core/utils/class-ltms-utils.php' );
		$this->assertIsString( $src, 'Debe poder leerse class-ltms-utils.php.' );

		$pos  = strpos( $src, 'function get_deduped_product_categories' );
		$body = substr( $src, $pos, 1800 );

		$this->assertStringContainsString( 'GROUP BY t.name', $body, 'La query debe agrupar por nombre (colapsa duplicados con distinto slug/parent).' );
		$this->assertStringContainsString( 'MIN(t.term_id) AS term_id', $body, 'Debe devolver el term_id más bajo como canónico.' );
		$this->assertStringContainsString( "tt.taxonomy = 'product_cat'", $body, 'Debe filtrar la taxonomía product_cat.' );
		$this->assertStringNotContainsString( 'number', $body, 'Sin límite de filas — el GROUP BY ya colapsa los duplicados.' );
	}

	public function test_get_deduped_product_categories_handles_wpdb_error(): void {
		$this->require_class( 'LTMS_Utils' );

		global $wpdb;
		$wpdb = new class() {
			public string $terms         = 'wp_terms';
			public string $term_taxonomy = 'wp_term_taxonomy';
			public function get_results( $query ) {
				return null;
			}
		};

		$this->assertSame( [], \LTMS_Utils::get_deduped_product_categories(), 'Un fallo de DB debe devolver [] sin crashear.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Source-level — el form de productos usa el helper de dedup
	// ─────────────────────────────────────────────────────────────────────────

	public function test_view_products_uses_deduped_helper_for_both_selects(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/includes/frontend/views/view-products.php' );
		$this->assertIsString( $src, 'Debe poder leerse view-products.php.' );

		$this->assertStringContainsString( 'LTMS_Utils::get_deduped_product_categories()', $src, 'El form de productos debe usar el helper de dedup.' );
		$this->assertStringNotContainsString( "get_terms([ 'taxonomy' => 'product_cat'", $src, 'El get_terms plano de product_cat mostraba duplicados heredados en el select — no debe quedar.' );
		$this->assertSame( 1, substr_count( $src, 'LTMS_Utils::get_deduped_product_categories()' ), 'La lista se computa UNA vez y se reutiliza en ambos selects (Nuevo + Editar).' );
	}

	public function test_dead_get_categories_handler_is_removed(): void {
		// POSGOLD-DOCS-PARITY v2.9.397: el handler AJAX ltms_get_categories de
		// LTMS_Products_Ajax era dead code — ningún JS del repo lo invocaba
		// (verificado repo-completo 2026-09-27; el dropdown del form de productos
		// se renderiza server-side). Patrón del repo: C5-1 FIX (handler eliminado).
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/includes/frontend/class-ltms-products-ajax.php' );
		$this->assertIsString( $src, 'Debe poder leerse class-ltms-products-ajax.php.' );

		$this->assertStringNotContainsString( "wp_ajax_ltms_get_categories'", $src, 'El hook del handler dead code debe estar eliminado.' );
		$this->assertStringNotContainsString( 'public function get_categories(', $src, 'El método get_categories debe estar eliminado.' );
		// Los handlers vivos no deben haberse tocado.
		$this->assertStringContainsString( "wp_ajax_ltms_get_products_data", $src, 'Los handlers vivos permanecen.' );
		$this->assertStringContainsString( "wp_ajax_ltms_create_product", $src, 'Los handlers vivos permanecen.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// LTMS-SAVE-CREDS-FIX (2026-09-27) — "Error de red" al guardar credenciales
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Mapa de user_meta con tracking de writes (update_user_meta).
	 *
	 * Devuelve un OBJETO holder (los objetos van por handle — un array
	 * devuelto por valor sería una COPIA y el closure seguiría escribiendo en
	 * la variable original: los asserts del test leerían una copia vacía).
	 */
	private function stub_vendor_meta_with_writes( int $user_id, array $meta ): object {
		$GLOBALS['__ltms_current_uid'] = $user_id;
		$map    = $meta;
		$holder = new \stdClass();
		$holder->writes = [];
		Monkey\Functions\when( 'get_user_meta' )->alias(
			static function ( $uid, $key = '', $single = false ) use ( $user_id, &$map ) {
				return ( (int) $uid === $user_id && isset( $map[ $key ] ) ) ? $map[ $key ] : '';
			}
		);
		Monkey\Functions\when( 'update_user_meta' )->alias(
			static function ( $uid, $key, $value ) use ( &$map, $holder ) {
				$holder->writes[ $key ] = $value;
				$map[ $key ]            = $value;
				return true;
			}
		);
		Monkey\Functions\stubs( [
			'check_ajax_referer' => true,
			'is_user_logged_in'  => true,
		] );
		Monkey\Functions\when( 'get_userdata' )->alias(
			static fn( $uid ) => (object) [ 'roles' => [ 'ltms_vendor' ] ]
		);
		return $holder;
	}

	private function invoke_save_credentials( callable $call ): array {
		$payload     = null;
		$payload_err = null;

		Monkey\Functions\when( 'wp_send_json_success' )->alias(
			static function ( $data = null ) use ( &$payload ): void {
				$payload = $data;
				throw new \RuntimeException( 'json_success' );
			}
		);
		Monkey\Functions\when( 'wp_send_json_error' )->alias(
			static function ( $data = null, $status = null ) use ( &$payload_err ): void {
				$payload_err = $data;
				throw new \RuntimeException( 'json_error' );
			}
		);

		try {
			$call();
		} catch ( \RuntimeException $e ) {
			if ( ! in_array( $e->getMessage(), [ 'json_success', 'json_error' ], true ) ) {
				throw $e;
			}
		}

		return [ 'success_payload' => $payload, 'error_payload' => $payload_err ];
	}

	private function post_save( string $subdomain, string $token ): void {
		$_POST['subdomain'] = $subdomain;
		$_POST['token']     = $token;
		$_POST['empresaid'] = '1';
		$_POST['usuarioid'] = '1';
		$_POST['bodegaid']  = '1';
		( new \LTMS_Dashboard_Logic() )->ajax_save_posgold_credentials();
	}

	public function test_save_credentials_keeps_existing_token_when_field_empty(): void {
		$this->require_class( 'LTMS_Dashboard_Logic' );
		$this->require_class( 'LTMS_Utils' );
		$this->require_class( 'LTMS_Core_Security' );

		// Escenario EXACTO del vendor (verificado end-to-end en producción con
		// sesión real): token ya configurado → la vista oculta el campo dentro
		// de <details> colapsado → re-guardar enviaba token='' → 400 → el JS
		// mostraba "Error de red.". El fix conserva el token guardado.
		$encrypted = \LTMS_Core_Security::encrypt( 'jwt-vendor-real' );
		$holder = $this->stub_vendor_meta_with_writes( 168, [
			'ltms_posgold_subdomain' => 'jugueteriataiwan',
			'ltms_posgold_token'     => $encrypted,
		] );

		$out = $this->invoke_save_credentials( fn() => $this->post_save( 'jugueteriataiwan', '' ) );

		$this->assertNotNull( $out['success_payload'], 'Con token ya configurado, guardar con el campo vacío debe tener éxito (conserva el guardado) — antes: 400 "Subdominio y Token son obligatorios." → "Error de red."' );
		$this->assertSame( 'Credenciales guardadas correctamente.', $out['success_payload']['message'] );
		$this->assertNull( $out['error_payload'], 'No debe haber error 400.' );
		$this->assertArrayNotHasKey( 'ltms_posgold_token', $holder->writes, 'El token guardado NO debe sobreescribirse con el campo vacío.' );
	}

	public function test_save_credentials_still_rejects_when_nothing_saved_and_fields_empty(): void {
		$this->require_class( 'LTMS_Dashboard_Logic' );
		$this->require_class( 'LTMS_Utils' );

		// Primera configuración con campos vacíos → el 400 con mensaje claro sigue.
		$this->stub_vendor_meta_with_writes( 168, [] );

		$out = $this->invoke_save_credentials( fn() => $this->post_save( '', '' ) );

		$this->assertNotNull( $out['error_payload'], 'Sin nada configurado y campos vacíos debe seguir rechazando.' );
		$this->assertSame( 'Subdominio y Token son obligatorios.', $out['error_payload']['message'] );
	}

	public function test_save_credentials_updates_token_when_provided(): void {
		$this->require_class( 'LTMS_Dashboard_Logic' );
		$this->require_class( 'LTMS_Utils' );
		$this->require_class( 'LTMS_Core_Security' );

		// El vendor abre <details> y pega un token nuevo → se cifra y se guarda.
		$old_encrypted = \LTMS_Core_Security::encrypt( 'jwt-viejo' );
		$holder = $this->stub_vendor_meta_with_writes( 168, [
			'ltms_posgold_subdomain' => 'jugueteriataiwan',
			'ltms_posgold_token'     => $old_encrypted,
		] );

		$out = $this->invoke_save_credentials( fn() => $this->post_save( 'jugueteriataiwan', 'jwt-nuevo-123' ) );

		$this->assertNotNull( $out['success_payload'], 'Guardar con token nuevo debe tener éxito.' );
		$this->assertArrayHasKey( 'ltms_posgold_token', $holder->writes, 'El token nuevo debe persistirse.' );
		$this->assertNotSame( 'jwt-nuevo-123', $holder->writes['ltms_posgold_token'], 'El token debe ir cifrado a user_meta.' );
		$decrypted = \LTMS_Core_Security::decrypt( $holder->writes['ltms_posgold_token'] );
		$this->assertSame( 'jwt-nuevo-123', $decrypted, 'El token cifrado debe descifrar al valor nuevo.' );
	}

	public function test_js_fail_handlers_read_response_json(): void {
		// LTMS-SAVE-CREDS-FIX: los .fail() de ltms-posgold.js mostraban
		// 'Error de red.' e ignoraban xhr.responseJSON — cualquier
		// wp_send_json_error(...,4xx) (validación/permisos) se veía como
		// "Error de red." en vez del mensaje real.
		$js_src = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/ltms-posgold.js' );
		$this->assertIsString( $js_src, 'Debe poder leerse ltms-posgold.js.' );

		$this->assertStringContainsString( 'function posgoldFailMsg(xhr, fallback)', $js_src, 'Debe existir el helper posgoldFailMsg.' );
		$this->assertStringContainsString( 'xhr.responseJSON', $js_src, 'El helper debe leer xhr.responseJSON (el mensaje real del 4xx).' );
		$this->assertSame( 0, substr_count( $js_src, "toastError('Error', 'Error de red.');" ), 'Ningún .fail debe mostrar "Error de red." hardcodeado sin leer la respuesta.' );

		$min_src = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/ltms-posgold.min.js' );
		$this->assertIsString( $min_src, 'Debe poder leerse ltms-posgold.min.js.' );
		$this->assertStringContainsString( 'responseJSON', $min_src, 'El .min.js regenerado debe contener el fix.' );
	}
}
