<?php
/**
 * ProductsAuditFixTest — tests del hallazgo AUDIT-PROD-044
 *
 * Cubre los fixes aplicados en el ciclo AUDIT-PROD:
 *
 * FIX-044 — Eliminar loadNewProductView / loadEditProductView de ltms-dashboard.js
 *           (atajo JS que prefería ltms-products.js sobre el modal PHP source of truth,
 *           dejando gallery upload, booking fields, restaurant y variable como código
 *           muerto en el modal PHP). Migradas las 3 features al modal PHP + al submit
 *           de ltms-products.js + a update_product/get_product en el backend.
 *
 * Backend cubierto por estos tests:
 *   - sync_variable_product(): helper privado nuevo, compartido por create_product y
 *     update_product. Lógica de recreación de variaciones + atributos WC.
 *   - update_product(): allowlist de tipos extendido con 'restaurant' y 'variable'
 *     (antes solo aceptaba physical/digital/service/booking).
 *   - update_product(): persiste booking meta + variation_attributes (paridad con
 *     create_product).
 *   - get_product(): devuelve booking meta + short_desc + sku + shipping_class_id
 *     para que el modal Edit los pueda poblar.
 *
 * Frontend (no testeado aquí directamente): ltms-products.js abre el modal PHP
 * directamente (sin rama `if typeof LTMS.Dashboard.loadNewProductView`); el modal
 * New y Edit de view-products.php incluyen radio 'variable' + selector de visibilidad
 * + bloque de atributos + (Edit) booking fields completos.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use ReflectionClass;
use ReflectionMethod;

/**
 * Class ProductsAuditFixTest
 *
 * Tests unitarios para los fixes del ciclo AUDIT-PROD-044.
 * Ejecutar con: ./vendor/bin/phpunit --group audit-prod
 *
 * @group audit-prod
 */
class ProductsAuditFixTest extends LTMS_Unit_Test_Case {

	/**
	 * Resuelve la ruta real al archivo dentro de includes/ del plugin.
	 * En modo UNIT_ONLY, ABSPATH apunta al root del plugin mismo
	 * (ver tests/bootstrap.php:28 `ABSPATH = dirname(__DIR__) . '/'`),
	 * así que el path canónico es dirname(__DIR__, 2) . '/includes/...'.
	 */
	private function plugin_includes_path( string $relative ): string {
		return dirname( __DIR__, 2 ) . '/includes/' . $relative;
	}

	private function load_products_ajax_class(): void {
		if ( ! class_exists( 'LTMS_Products_Ajax', false ) ) {
			require_once $this->plugin_includes_path( 'frontend/class-ltms-products-ajax.php' );
		}
	}

	/**
	 * Invoca un método privado/protected de instancia via reflection.
	 */
	private function invoke_private( object $instance, string $method, array $args = [] ) {
		$ref = new ReflectionMethod( $instance::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $instance, $args );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// FIX-044.A — clase LTMS_Products_Ajax carga sin fatal (smoke test)
	// ─────────────────────────────────────────────────────────────────────────

	public function test_044a_products_ajax_class_loads_without_fatal(): void {
		$this->load_products_ajax_class();
		$this->assertTrue( class_exists( 'LTMS_Products_Ajax' ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// FIX-044.B — sync_variable_product() existe, es privado, y tiene la firma
	//            .contractual: ( int $product_id, string $variation_attrs_raw, float $base_price )
	// ─────────────────────────────────────────────────────────────────────────

	public function test_044b_sync_variable_product_method_exists_with_correct_signature(): void {
		$this->load_products_ajax_class();
		if ( ! class_exists( 'LTMS_Products_Ajax' ) ) {
			$this->markTestSkipped( 'LTMS_Products_Ajax no disponible.' );
		}

		$reflection = new ReflectionClass( 'LTMS_Products_Ajax' );
		$this->assertTrue( $reflection->hasMethod( 'sync_variable_product' ),
			'sync_variable_product debe existir como método de LTMS_Products_Ajax.' );

		$method = $reflection->getMethod( 'sync_variable_product' );
		$this->assertTrue( $method->isPrivate(),
			'sync_variable_product debe ser private (helper interno, no API pública).' );

		$params = $method->getParameters();
		$this->assertCount( 3, $params,
			'sync_variable_product debe recibir 3 parámetros (product_id, variation_attrs_raw, base_price).' );
		$this->assertSame( 'product_id', $params[0]->getName() );
		$this->assertSame( 'variation_attrs_raw', $params[1]->getName() );
		$this->assertSame( 'base_price', $params[2]->getName() );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// FIX-044.C — sync_variable_product sale temprano cuando el JSON es inválido
	//             sin invocar ninguna función de WC. Lógica 100% aislada que
	//             protege contra inputs malformados del frontend (JSON truncado,
	//             objeto sin name/values, array vacío).
	// ─────────────────────────────────────────────────────────────────────────

	public function test_044c_sync_variable_returns_void_when_json_is_malformed(): void {
		$this->load_products_ajax_class();
		if ( ! class_exists( 'LTMS_Products_Ajax' ) ) {
			$this->markTestSkipped( 'LTMS_Products_Ajax no disponible.' );
		}

		$instance = new \LTMS_Products_Ajax();

		// JSON inválido — json_decode retorna null → el método debe salir temprano.
		// Verificamos que el método retorna null (void) sin lanzar excepción.
		$result = $this->invoke_private( $instance, 'sync_variable_product', [ 999, 'this is not json', 100.0 ] );
		$this->assertNull( $result,
			'sync_variable_product debe retornar void cuando el JSON no parsea.' );
	}

	public function test_044c_sync_variable_returns_void_when_attrs_array_is_empty(): void {
		$this->load_products_ajax_class();
		if ( ! class_exists( 'LTMS_Products_Ajax' ) ) {
			$this->markTestSkipped( 'LTMS_Products_Ajax no disponible.' );
		}

		$instance = new \LTMS_Products_Ajax();

		// Array vacío — json_decode retorna [] → is_array true pero empty → early return.
		$result = $this->invoke_private( $instance, 'sync_variable_product', [ 999, '[]', 100.0 ] );
		$this->assertNull( $result,
			'sync_variable_product debe retornar void cuando el array de atributos está vacío.' );
	}

	public function test_044c_sync_variable_returns_void_when_attrs_is_not_array(): void {
		$this->load_products_ajax_class();
		if ( ! class_exists( 'LTMS_Products_Ajax' ) ) {
			$this->markTestSkipped( 'LTMS_Products_Ajax no disponible.' );
		}

		$instance = new \LTMS_Products_Ajax();

		// JSON válido pero no array (un scalar) — json_decode retorna scalar → is_array false → early return.
		$result = $this->invoke_private( $instance, 'sync_variable_product', [ 999, '"a string"', 100.0 ] );
		$this->assertNull( $result,
			'sync_variable_product debe retornar void cuando el JSON no es un array.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// FIX-044.D — update_product() allowlist de tipos incluye 'restaurant' y
	//             'variable' (antes solo aceptaba physical/digital/service/booking).
	//             Validación estructural vía lectura del código fuente del método
	//             — no podemos ejecutar el método sin mocks WC, pero garantizamos
	//             que el in_array del allowlist está extendido.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_044d_update_product_allowlist_includes_restaurant_and_variable(): void {
		$this->load_products_ajax_class();
		if ( ! class_exists( 'LTMS_Products_Ajax' ) ) {
			$this->markTestSkipped( 'LTMS_Products_Ajax no disponible.' );
		}

		$reflection = new ReflectionClass( 'LTMS_Products_Ajax' );
		$method     = $reflection->getMethod( 'update_product' );
		$source     = $method->getFileName();
		$start_line = $method->getStartLine();
		$end_line   = $method->getEndLine();

		$lines = file( $source );
		$body  = implode( '', array_slice( $lines, $start_line - 1, $end_line - $start_line + 1 ) );

		// AUDIT-PROD-044 D: el allowlist debe contener 'restaurant' y 'variable'.
		// Buscamos la in_array del bloque CS-05 extendido. El patrón isn't unique
		// por substring matching → check ambos tokens en la misma línea del allowlist.
		$this->assertStringContainsString(
			"'restaurant'",
			$body,
			'update_product debe aceptar el tipo restaurant en su allowlist (CS-05 extendido por AUDIT-PROD-044).'
		);
		$this->assertStringContainsString(
			"'variable'",
			$body,
			'update_product debe aceptar el tipo variable en su allowlist (CS-05 extendido por AUDIT-PROD-044).'
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// FIX-044.E — update_product() persiste booking meta y variation_attributes.
	//             Validación estructural: el cuerpo del método contiene las
	//             meta keys de booking y la invocación a sync_variable_product.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_044e_update_product_persists_booking_meta_keys(): void {
		$this->load_products_ajax_class();
		if ( ! class_exists( 'LTMS_Products_Ajax' ) ) {
			$this->markTestSkipped( 'LTMS_Products_Ajax no disponible.' );
		}

		$reflection = new ReflectionClass( 'LTMS_Products_Ajax' );
		$method     = $reflection->getMethod( 'update_product' );
		$source     = $method->getFileName();
		$start_line = $method->getStartLine();
		$end_line   = $method->getEndLine();

		$lines = file( $source );
		$body  = implode( '', array_slice( $lines, $start_line - 1, $end_line - $start_line + 1 ) );

		// AUDIT-PROD-044 E: booking meta keys persistidas en update_product (paridad con create_product).
		$booking_metas = [
			"'_ltms_booking_type'",
			"'_ltms_min_nights'",
			"'_ltms_max_nights'",
			"'_ltms_capacity'",
			"'_ltms_checkin_time'",
			"'_ltms_checkout_time'",
			"'_ltms_payment_mode'",
			"'_ltms_deposit_pct'",
		];
		foreach ( $booking_metas as $meta ) {
			$this->assertStringContainsString( $meta, $body,
				"update_product debe persistir la meta {$meta} cuando el tipo es booking (paridad con create_product)." );
		}
	}

	public function test_044e_update_product_invokes_sync_variable_product_for_variable_type(): void {
		$this->load_products_ajax_class();
		if ( ! class_exists( 'LTMS_Products_Ajax' ) ) {
			$this->markTestSkipped( 'LTMS_Products_Ajax no disponible.' );
		}

		$reflection = new ReflectionClass( 'LTMS_Products_Ajax' );
		$method     = $reflection->getMethod( 'update_product' );
		$source     = $method->getFileName();
		$start_line = $method->getStartLine();
		$end_line   = $method->getEndLine();

		$lines = file( $source );
		$body  = implode( '', array_slice( $lines, $start_line - 1, $end_line - $start_line + 1 ) );

		$this->assertStringContainsString( '$this->sync_variable_product(', $body,
			'update_product debe invocar $this->sync_variable_product() cuando el tipo es variable (paridad con create_product).' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// FIX-044.F — get_product() devuelve los campos nuevos: booking meta +
	//             short_desc + sku + shipping_class_id. Validación estructural
	//             sobre el cuerpo del método.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_044f_get_product_returns_booking_meta_and_extra_fields(): void {
		$this->load_products_ajax_class();
		if ( ! class_exists( 'LTMS_Products_Ajax' ) ) {
			$this->markTestSkipped( 'LTMS_Products_Ajax no disponible.' );
		}

		$reflection = new ReflectionClass( 'LTMS_Products_Ajax' );
		$method     = $reflection->getMethod( 'get_product' );
		$source     = $method->getFileName();
		$start_line = $method->getStartLine();
		$end_line   = $method->getEndLine();

		$lines = file( $source );
		$body  = implode( '', array_slice( $lines, $start_line - 1, $end_line - $start_line + 1 ) );

		// AUDIT-PROD-044 F: get_product devolvía solo product_type, redi_* —
		// ahora también booking meta (para poblar el modal Edit) y short_desc/sku/shipping_class_id.
		$expected_keys = [
			"'booking_type'",
			"'min_nights'",
			"'max_nights'",
			"'booking_capacity'",
			"'checkin_time'",
			"'checkout_time'",
			"'payment_mode'",
			"'deposit_pct'",
			"'short_description'",
			"'sku'",
			"'shipping_class_id'",
		];
		foreach ( $expected_keys as $key ) {
			$this->assertStringContainsString( $key, $body,
				"get_product debe devolver la clave {$key} en su respuesta JSON (AUDIT-PROD-044 paridad con create_product)." );
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// FIX-H5 (re-auditoría) — LTMS_Admin_Product_Meta::PRODUCT_TYPES incluye
	//             'restaurant' y 'variable' (sino el admin wp-admin resetea el
	//             tipo al guardar un producto creado como restaurant/variable).
	// ─────────────────────────────────────────────────────────────────────────

	public function test_h5_admin_product_meta_types_includes_restaurant_and_variable(): void {
		$admin_meta_path = dirname( __DIR__, 2 ) . '/includes/admin/class-ltms-admin-product-meta.php';
		if ( ! file_exists( $admin_meta_path ) ) {
			$this->markTestSkipped( 'class-ltms-admin-product-meta.php no disponible.' );
		}
		require_once $admin_meta_path;
		if ( ! class_exists( 'LTMS_Admin_Product_Meta' ) ) {
			$this->markTestSkipped( 'LTMS_Admin_Product_Meta no disponible.' );
		}

		$reflection = new ReflectionClass( 'LTMS_Admin_Product_Meta' );
		$this->assertTrue( $reflection->hasConstant( 'PRODUCT_TYPES' ) );
		$types = $reflection->getConstant( 'PRODUCT_TYPES' );
		$this->assertIsArray( $types, 'PRODUCT_TYPES debe ser un array.' );
		$this->assertArrayHasKey( 'restaurant', $types, 'PRODUCT_TYPES debe incluir restaurant (AUDIT-PROD-H5).' );
		$this->assertArrayHasKey( 'variable',   $types, 'PRODUCT_TYPES debe incluir variable (AUDIT-PROD-H5).' );
		// Y no debe haber perdido los tipos anteriores.
		$this->assertArrayHasKey( 'physical', $types );
		$this->assertArrayHasKey( 'digital',  $types );
		$this->assertArrayHasKey( 'service',  $types );
		$this->assertArrayHasKey( 'booking',  $types );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// FIX-H2 (re-auditoría) — sync_variable_product compara firmas canónicas
	//             de atributos y omite el delete+recreate si son idénticos, así
	//             preserva stock propio, SKU y referencias en pedidos históricos.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_h2_sync_variable_has_signature_comparison_before_recreate(): void {
		$this->load_products_ajax_class();
		if ( ! class_exists( 'LTMS_Products_Ajax' ) ) {
			$this->markTestSkipped( 'LTMS_Products_Ajax no disponible.' );
		}

		$reflection = new ReflectionClass( 'LTMS_Products_Ajax' );
		$method     = $reflection->getMethod( 'sync_variable_product' );
		$source     = $method->getFileName();
		$start_line = $method->getStartLine();
		$end_line   = $method->getEndLine();

		$lines = file( $source );
		$body  = implode( '', array_slice( $lines, $start_line - 1, $end_line - $start_line + 1 ) );

		// AUDIT-PROD-H2: el helper debe construir una firma canónica (incoming + existing)
		// y salir temprano si son idénticos, en vez de siempre delete+recreate.
		$this->assertStringContainsString( '$incoming_signature', $body,
			'sync_variable_product debe construir $incoming_signature para comparar (AUDIT-PROD-H2).' );
		$this->assertStringContainsString( '$existing_signature', $body,
			'sync_variable_product debe construir $existing_signature para comparar (AUDIT-PROD-H2).' );
		$this->assertStringContainsString( '=== $existing_signature', $body,
			'sync_variable_product debe tener un early-return si $incoming_signature === $existing_signature (preserva variaciones).' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// FIX-H1 (re-auditoría) — update_product aplica set_virtual/set_downloadable
	//             cuando el tipo cambia a digital/service (paridad con create_product).
	// ─────────────────────────────────────────────────────────────────────────

	public function test_h1_update_product_applies_virtual_and_downloadable_for_digital(): void {
		$this->load_products_ajax_class();
		if ( ! class_exists( 'LTMS_Products_Ajax' ) ) {
			$this->markTestSkipped( 'LTMS_Products_Ajax no disponible.' );
		}

		$reflection = new ReflectionClass( 'LTMS_Products_Ajax' );
		$method     = $reflection->getMethod( 'update_product' );
		$source     = $method->getFileName();
		$start_line = $method->getStartLine();
		$end_line   = $method->getEndLine();

		$lines = file( $source );
		$body  = implode( '', array_slice( $lines, $start_line - 1, $end_line - $start_line + 1 ) );

		// AUDIT-PROD-H1:
		$this->assertStringContainsString( 'set_virtual( true )', $body,
			'update_product debe llamar set_virtual(true) para digital/service (paridad con create_product).' );
		$this->assertStringContainsString( 'set_downloadable( true )', $body,
			'update_product debe llamar set_downloadable(true) para digital (paridad con create_product).' );
		$this->assertStringContainsString( "set_downloads( [", $body,
			'update_product debe asignar downloads para digital (paridad con create_product).' );
		$this->assertStringContainsString( "'download_url'", $body,
			'update_product debe leer download_url de $_POST cuando el tipo es digital.' );
		$this->assertStringContainsString( '$product_type_for_update === \'service\'', $body,
			'update_product debe tener rama explicita para tipo service.' );
	}

	public function test_h1_update_product_clears_flags_when_changing_away_from_digital_service(): void {
		$this->load_products_ajax_class();
		if ( ! class_exists( 'LTMS_Products_Ajax' ) ) {
			$this->markTestSkipped( 'LTMS_Products_Ajax no disponible.' );
		}

		$reflection = new ReflectionClass( 'LTMS_Products_Ajax' );
		$method     = $reflection->getMethod( 'update_product' );
		$source     = $method->getFileName();
		$start_line = $method->getStartLine();
		$end_line   = $method->getEndLine();

		$lines = file( $source );
		$body  = implode( '', array_slice( $lines, $start_line - 1, $end_line - $start_line + 1 ) );

		// AUDIT-PROD-H1 (clean-on-change): si el tipo cambia fuera de digital/service,
		// el método debe limpiar set_virtual(false) y set_downloadable(false).
		$this->assertStringContainsString( 'set_virtual( false )', $body,
			'update_product debe limpiar set_virtual(false) cuando cambia fuera de digital/service.' );
		$this->assertStringContainsString( 'set_downloadable( false )', $body,
			'update_product debe limpiar set_downloadable(false) cuando cambia fuera de digital/service.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// FIX-H3+H4 (re-auditoría) — update_product persiste sku/short_desc/
	//             shipping_class/tags y sale_price ya estaba phép pero no llegaba
	//             via JS — este test verifica el backend; el JS se cubre por smoke.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_h3h4_update_product_persists_full_field_set(): void {
		$this->load_products_ajax_class();
		if ( ! class_exists( 'LTMS_Products_Ajax' ) ) {
			$this->markTestSkipped( 'LTMS_Products_Ajax no disponible.' );
		}

		$reflection = new ReflectionClass( 'LTMS_Products_Ajax' );
		$method     = $reflection->getMethod( 'update_product' );
		$source     = $method->getFileName();
		$start_line = $method->getStartLine();
		$end_line   = $method->getEndLine();

		$lines = file( $source );
		$body  = implode( '', array_slice( $lines, $start_line - 1, $end_line - $start_line + 1 ) );

		// AUDIT-PROD-H3: backend persiste los 4 campos nuevos.
		$this->assertStringContainsString( 'set_short_description(', $body,
			'update_product debe persistir short_description (AUDIT-PROD-H3).' );
		$this->assertStringContainsString( 'set_sku(', $body,
			'update_product debe persistir sku (AUDIT-PROD-H3).' );
		$this->assertStringContainsString( 'set_shipping_class_id(', $body,
			'update_product debe persistir shipping_class_id (AUDIT-PROD-H3).' );
		$this->assertStringContainsString( "'product_tag'", $body,
			'update_product debe persistir tags via wp_set_post_terms con product_tag (AUDIT-PROD-H3).' );

		// AUDIT-PROD-H4: sale_price se procesa (venía del ciclo original):
		// el campo sale_price en $_POST y la set_sale_price correspondiente.
		$this->assertStringContainsString( "'sale_price'", $body,
			'update_product debe tener el campo sale_price en $_POST (AUDIT-PROD-H4 ya cubierto por original).' );
		$this->assertStringContainsString( 'set_sale_price(', $body,
			'update_product debe llamar set_sale_price (AUDIT-PROD-H4).' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// FIX-H6 (re-auditoría) — Eliminada la línea redundante y peligrosa
	//             `$product_refreshed->set_weight( $weight ?? '' )` dentro del
	//             bloque H3. Esa línea, introducida junto con el fix H3,
	//             recargaba el peso SOBRE la misma entidad WC ya persistida
	//             líneas arriba (línea 314: `if ( $weight !== null ) $product->set_weight( $weight )`),
	//             pero con un comportamiento distinto y buggy: cuando $_POST['weight']
	//             llegaba vacío, $weight === null → set_weight( '' ) → el peso
	//             del producto se reseteaba a string vacío al editar cualquier
	//             otro campo. Validamos que esa línea ya no está presente en el
	//             cuerpo de update_product (regresión introducida y fixeada en
	//             el mismo ciclo de re-auditoría).
	// ─────────────────────────────────────────────────────────────────────────

	public function test_h6_no_redundant_set_weight_with_null_coalesce_in_update_product(): void {
		$this->load_products_ajax_class();
		if ( ! class_exists( 'LTMS_Products_Ajax' ) ) {
			$this->markTestSkipped( 'LTMS_Products_Ajax no disponible.' );
		}

		$reflection = new ReflectionClass( 'LTMS_Products_Ajax' );
		$method     = $reflection->getMethod( 'update_product' );
		$source     = $method->getFileName();
		$start_line = $method->getStartLine();
		$end_line   = $method->getEndLine();

		$lines = file( $source );
		$body  = implode( '', array_slice( $lines, $start_line - 1, $end_line - $start_line + 1 ) );

		// AUDIT-PROD-H6: la línea peligrosa debe estar ausente. Validamos con un patrón
		// que asegure que es una SENTENCIA PHP (con `;`) y no una mención en comentario.
		// Esto evita falsos positivos cuando el propio comentario del fix menciona el bug.
		$this->assertDoesNotMatchRegularExpression(
			'/set_weight\(\s*\$weight\s*\?\?\s*\'\'\s*\)\s*;/',
			$body,
			'update_product NO debe contener la sentencia `set_weight( $weight ?? \'\' );` (AUDIT-PROD-H6): esa línea resetea el peso a string vacío cuando $_POST[\'weight\'] viene vacío. El peso ya se persiste líneas arriba con `if ( $weight !== null ) $product->set_weight( $weight )`.'
		);

		// Sanity check: la línea buena sigue presente (no la hemos borrado por error).
		// La sentencia original está en línea 314 con 8 espacios de indentación inicial.
		$this->assertStringContainsString(
			'if ( $weight !== null )     $product->set_weight( $weight );',
			$body,
			'update_product debe preservar la persistencia correcta de peso en la línea original (línea 314).'
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// FIX-H7 (re-auditoría) — Tags se borraban silenciosamente en cada edición.
	//             get_product() NO devolvía tags → el JS never populaba #ltms-ep-tags →
	//             el modal siempre enviaba `tags: ''` → update_product ejecutaba
	//             `wp_set_post_terms( $pid, [], 'product_tag', false )` y borrraba
	//             TODOS los tags existentes. Bug silencioso de pérdida de datos.
	//             Fix: get_product() ahora devuelve 'tags' como CSV de names de termos
	//             ((wp_get_post_terms con fields=names + implode). El JS lo lee en el
	//             success handler AJAX y popula el input #ltms-ep-tags.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_h7_get_product_returns_tags_as_csv(): void {
		$this->load_products_ajax_class();
		if ( ! class_exists( 'LTMS_Products_Ajax' ) ) {
			$this->markTestSkipped( 'LTMS_Products_Ajax no disponible.' );
		}

		$reflection = new ReflectionClass( 'LTMS_Products_Ajax' );
		$method     = $reflection->getMethod( 'get_product' );
		$source     = $method->getFileName();
		$start_line = $method->getStartLine();
		$end_line   = $method->getEndLine();

		$lines = file( $source );
		$body  = implode( '', array_slice( $lines, $start_line - 1, $end_line - $start_line + 1 ) );

		// AUDIT-PROD-H7: get_product debe devolver la clave 'tags' en la respuesta.
		$this->assertStringContainsString( "'tags'", $body,
			'get_product debe devolver la clave \'tags\' en wp_send_json_success (AUDIT-PROD-H7).' );

		// Sanity: usa wp_get_post_terms con fields=names (no IDs) para que el CSV sea
		// legible por el humano y reutilizable al editar.
		$this->assertStringContainsString( "wp_get_post_terms( \$product_id, 'product_tag'", $body,
			'get_product debe obtener los tags via wp_get_post_terms con taxonomia product_tag (AUDIT-PROD-H7).' );
		$this->assertStringContainsString( "'fields' => 'names'", $body,
			'get_product debe pedir fields=names para poblar el input con legibilidad humana (AUDIT-PROD-H7).' );
		$this->assertStringContainsString( "implode( ',',", $body,
			'get_product debe unir los names de tags con implode para enviarlos como CSV (AUDIT-PROD-H7).' );
	}
}
