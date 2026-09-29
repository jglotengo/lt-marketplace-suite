<?php
/**
 * SyncVisibilityGateTest — gate de vendibilidad pública de productos
 * sincronizados (SYNC-VIS-GATE, 2026-09-28).
 *
 * Regla de negocio: un producto (PosGold/VTEX/panel del vendedor) que NO cumpla
 * TODAS las condiciones de vendibilidad (stock disponible, imagen destacada,
 * precio > 0) NO debe aparecer en NINGUNA página pública — solo en el panel del
 * vendedor.
 *
 * Hallazgos cubiertos:
 *   - P1: los syncs PosGold/VTEX hacían set_catalog_visibility('visible')
 *     INCONDICIONAL — un producto sin precio/imagen quedaba visible.
 *   - GAP: vendor-store.php (vitrina pública) y las queries AJAX de
 *     vendor-storefront.php NO filtraban visibilidad.
 *   - GAP: la URL directa de un producto no vendible renderizaba el producto
 *     (single-product.php sin guard).
 *   - GAP: quick-view y live-search servían productos ocultos.
 *
 * El test importa y ejerce la clase REAL del gate (no reimplementa la lógica)
 * con productos fake que extienden el stub WC_Product del bootstrap. Los
 * call-sites (syncs, templates, kernel, handlers) se verifican a nivel de
 * fuente (mismo patrón que CartEmptyUxTest/AutoloaderTest).
 *
 * Ejecutar con: ./vendor/bin/phpunit --testsuite=unit --group sync-vis-gate
 *
 * @group sync-vis-gate
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * Class SyncVisibilityGateTest
 *
 * @group sync-vis-gate
 */
final class SyncVisibilityGateTest extends LTMS_Unit_Test_Case {

	/**
	 * Producto fake que extiende el stub WC_Product del bootstrap con los
	 * métodos que el gate ejercita.
	 */
	private function make_product( array $config = [] ): object {
		return new class( $config ) extends \WC_Product {
			private string $status             = 'publish';
			private string $catalog_visibility = 'visible';
			private string $stock_status       = 'instock';
			private        $image_id           = '';
			private        $price              = '';
			private string $backorders         = 'no';
			private array  $meta               = [];
			public int     $save_count         = 0;

			public function __construct( array $config = [] ) {
				if ( isset( $config['id'] ) ) {
					$this->id = (int) $config['id'];
				}
				if ( isset( $config['status'] ) ) {
					$this->status = $config['status'];
				}
				if ( isset( $config['catalog_visibility'] ) ) {
					$this->catalog_visibility = $config['catalog_visibility'];
				}
				if ( isset( $config['stock_status'] ) ) {
					$this->stock_status = $config['stock_status'];
				}
				if ( isset( $config['image_id'] ) ) {
					$this->image_id = $config['image_id'];
				}
				if ( isset( $config['price'] ) ) {
					$this->price = $config['price'];
				}
				if ( isset( $config['backorders'] ) ) {
					$this->backorders = $config['backorders'];
				}
			}

			public function get_status( $context = 'view' ): string {
				return $this->status;
			}
			public function get_catalog_visibility( $context = 'view' ): string {
				return $this->catalog_visibility;
			}
			public function set_catalog_visibility( $visibility ): void {
				$this->catalog_visibility = (string) $visibility;
			}
			public function get_stock_status( $context = 'view' ): string {
				return $this->stock_status;
			}
			public function is_in_stock(): bool {
				return 'instock' === $this->stock_status;
			}
			public function get_image_id( $context = 'view' ) {
				return $this->image_id;
			}
			public function get_price( $context = 'view' ) {
				return $this->price;
			}
			public function get_backorders( $context = 'view' ): string {
				return $this->backorders;
			}
			public function get_meta( string $key, bool $single = true ) {
				return $this->meta[ $key ] ?? '';
			}
			public function update_meta_data( string $key, $value ): void {
				$this->meta[ $key ] = $value;
			}
			public function delete_meta_data( string $key ): void {
				unset( $this->meta[ $key ] );
			}
			public function save(): int {
				$this->save_count++;
				return $this->id > 0 ? $this->id : 1;
			}
		};
	}

	private function load_gate_class(): bool {
		if ( class_exists( 'LTMS_Business_Sync_Visibility_Gate' ) ) {
			return true;
		}
		$file = dirname( __DIR__, 2 ) . '/includes/business/class-ltms-business-sync-visibility-gate.php';
		if ( file_exists( $file ) ) {
			require_once $file;
			return class_exists( 'LTMS_Business_Sync_Visibility_Gate' );
		}
		return false;
	}

	private function read_source( string $relative ): string {
		$path = dirname( __DIR__, 2 ) . '/' . $relative;
		if ( ! file_exists( $path ) ) {
			$this->fail( "Archivo fuente no encontrado: {$relative}" );
		}
		return (string) file_get_contents( $path );
	}

	// ─── SECCIÓN 1: missing_conditions ───────────────────────────────────────

	public function test_missing_conditions_all_present_returns_empty(): void {
		$this->assertTrue( $this->load_gate_class() );
		$product = $this->make_product( [
			'id'       => 10,
			'price'    => '22000',
			'image_id' => '55',
			'stock_status' => 'instock',
		] );
		$this->assertSame( [], \LTMS_Business_Sync_Visibility_Gate::missing_conditions( $product ) );
	}

	public function test_missing_conditions_detects_missing_image(): void {
		$this->assertTrue( $this->load_gate_class() );
		$product = $this->make_product( [ 'id' => 11, 'price' => '6500' ] );
		$this->assertSame( [ 'image' ], \LTMS_Business_Sync_Visibility_Gate::missing_conditions( $product ) );
	}

	public function test_missing_conditions_detects_missing_price(): void {
		$this->assertTrue( $this->load_gate_class() );
		$product = $this->make_product( [ 'id' => 12, 'image_id' => '55', 'price' => '' ] );
		$this->assertSame( [ 'price' ], \LTMS_Business_Sync_Visibility_Gate::missing_conditions( $product ) );

		// Precio 0.00 también es "sin precio" (producto gratuito no vendible).
		$product2 = $this->make_product( [ 'id' => 13, 'image_id' => '55', 'price' => '0.00' ] );
		$this->assertSame( [ 'price' ], \LTMS_Business_Sync_Visibility_Gate::missing_conditions( $product2 ) );
	}

	public function test_missing_conditions_detects_out_of_stock(): void {
		$this->assertTrue( $this->load_gate_class() );
		$product = $this->make_product( [ 'id' => 14, 'price' => '6500', 'image_id' => '55', 'stock_status' => 'outofstock' ] );
		$this->assertSame( [ 'stock' ], \LTMS_Business_Sync_Visibility_Gate::missing_conditions( $product ) );
	}

	public function test_missing_conditions_backordered_product_is_sellable(): void {
		$this->assertTrue( $this->load_gate_class() );
		// Paridad con single-product.php: "Disponible bajo pedido" (backorders
		// yes/notify) NO cuenta como falta de stock.
		$product = $this->make_product( [
			'id'        => 15,
			'price'     => '6500',
			'image_id'  => '55',
			'stock_status' => 'outofstock',
			'backorders' => 'yes',
		] );
		$this->assertSame( [], \LTMS_Business_Sync_Visibility_Gate::missing_conditions( $product ) );
	}

	public function test_missing_conditions_combined(): void {
		$this->assertTrue( $this->load_gate_class() );
		$product = $this->make_product( [ 'id' => 16, 'stock_status' => 'outofstock' ] );
		$this->assertSame(
			[ 'stock', 'image', 'price' ],
			\LTMS_Business_Sync_Visibility_Gate::missing_conditions( $product )
		);
	}

	public function test_is_unsellable_flags_any_missing_condition(): void {
		$this->assertTrue( $this->load_gate_class() );
		$this->assertTrue( \LTMS_Business_Sync_Visibility_Gate::is_unsellable( $this->make_product( [ 'id' => 17 ] ) ) );
		$this->assertFalse( \LTMS_Business_Sync_Visibility_Gate::is_unsellable( $this->make_product( [
			'id' => 18, 'price' => '100', 'image_id' => '5',
		] ) ) );
	}

	// ─── SECCIÓN 2: apply_gate ────────────────────────────────────────────────

	public function test_apply_gate_hides_unsellable_published_product(): void {
		$this->assertTrue( $this->load_gate_class() );
		$product = $this->make_product( [ 'id' => 20, 'price' => '6500', 'stock_status' => 'outofstock' ] );
		Functions\when( 'wc_get_product' )->alias( static fn( $id ) => $product );

		$outcome = \LTMS_Business_Sync_Visibility_Gate::apply_gate( 20 );

		$this->assertSame( 'hidden', $outcome );
		$this->assertSame( 'hidden', $product->get_catalog_visibility() );
		// Sin stock Y sin imagen (el producto fake no tiene image_id).
		$this->assertSame( '["stock","image"]', $product->get_meta( '_ltms_visibility_blocked' ) );
		$this->assertSame( 1, $product->save_count );
	}

	public function test_apply_gate_unchanged_when_already_hidden_with_same_reasons(): void {
		$this->assertTrue( $this->load_gate_class() );
		$product = $this->make_product( [
			'id'                 => 21,
			'price'              => '6500',
			'stock_status'       => 'outofstock',
			'catalog_visibility' => 'hidden',
		] );
		$product->update_meta_data( '_ltms_visibility_blocked', '["stock","image"]' );
		Functions\when( 'wc_get_product' )->alias( static fn( $id ) => $product );

		$outcome = \LTMS_Business_Sync_Visibility_Gate::apply_gate( 21 );

		$this->assertSame( 'unchanged', $outcome );
		// Sin cambios de motivos → SIN save extra (idempotencia en re-syncs).
		$this->assertSame( 0, $product->save_count );
	}

	public function test_apply_gate_restores_when_conditions_met_and_was_blocked(): void {
		$this->assertTrue( $this->load_gate_class() );
		$product = $this->make_product( [
			'id'                 => 22,
			'price'              => '6500',
			'image_id'           => '55',
			'catalog_visibility' => 'hidden',
		] );
		$product->update_meta_data( '_ltms_visibility_blocked', '["image"]' );
		Functions\when( 'wc_get_product' )->alias( static fn( $id ) => $product );

		$outcome = \LTMS_Business_Sync_Visibility_Gate::apply_gate( 22 );

		$this->assertSame( 'restored', $outcome );
		$this->assertSame( 'visible', $product->get_catalog_visibility() );
		$this->assertSame( '', $product->get_meta( '_ltms_visibility_blocked' ) );
		$this->assertSame( 1, $product->save_count );
	}

	public function test_apply_gate_never_touches_manually_hidden_sellable_product(): void {
		$this->assertTrue( $this->load_gate_class() );
		// Producto vendible ocultado MANUALMENTE (sin meta del gate) → el gate
		// NO lo restaura (respeta la decisión del admin/vendor).
		$product = $this->make_product( [
			'id'                 => 23,
			'price'              => '6500',
			'image_id'           => '55',
			'catalog_visibility' => 'hidden',
		] );
		Functions\when( 'wc_get_product' )->alias( static fn( $id ) => $product );

		$outcome = \LTMS_Business_Sync_Visibility_Gate::apply_gate( 23 );

		$this->assertSame( 'unchanged', $outcome );
		$this->assertSame( 'hidden', $product->get_catalog_visibility() );
		$this->assertSame( 0, $product->save_count );
	}

	public function test_apply_gate_ignores_draft_products(): void {
		$this->assertTrue( $this->load_gate_class() );
		// Producto no publicado: nada que ocultar (se evaluará al publicar).
		$product = $this->make_product( [ 'id' => 24, 'status' => 'draft' ] );
		Functions\when( 'wc_get_product' )->alias( static fn( $id ) => $product );

		$outcome = \LTMS_Business_Sync_Visibility_Gate::apply_gate( 24 );

		$this->assertSame( 'unchanged', $outcome );
		$this->assertSame( 'visible', $product->get_catalog_visibility() );
		$this->assertSame( 0, $product->save_count );
	}

	public function test_apply_gate_ignores_invalid_ids_and_null_products(): void {
		$this->assertTrue( $this->load_gate_class() );
		Functions\when( 'wc_get_product' )->alias( static fn( $id ) => null );

		$this->assertSame( 'unchanged', \LTMS_Business_Sync_Visibility_Gate::apply_gate( 0 ) );
		$this->assertSame( 'unchanged', \LTMS_Business_Sync_Visibility_Gate::apply_gate( 99 ) );
	}

	// ─── SECCIÓN 3: is_blocked_for_user (guard de URL directa) ───────────────

	public function test_guest_is_blocked_from_unsellable_product(): void {
		$this->assertTrue( $this->load_gate_class() );
		$product = $this->make_product( [ 'id' => 30, 'price' => '6500', 'stock_status' => 'outofstock' ] );
		Functions\when( 'get_post_field' )->alias( static fn( $field, $post_id ) => 168 );

		$this->assertTrue( \LTMS_Business_Sync_Visibility_Gate::is_blocked_for_user( $product, 0, false ) );
	}

	public function test_owner_vendor_can_view_own_unsellable_product(): void {
		$this->assertTrue( $this->load_gate_class() );
		$product = $this->make_product( [ 'id' => 31, 'price' => '6500', 'stock_status' => 'outofstock' ] );
		Functions\when( 'get_post_field' )->alias( static fn( $field, $post_id ) => 168 );

		$this->assertFalse( \LTMS_Business_Sync_Visibility_Gate::is_blocked_for_user( $product, 168, false ) );
	}

	public function test_manager_is_not_blocked(): void {
		$this->assertTrue( $this->load_gate_class() );
		$product = $this->make_product( [ 'id' => 32, 'price' => '6500', 'stock_status' => 'outofstock' ] );

		$this->assertFalse( \LTMS_Business_Sync_Visibility_Gate::is_blocked_for_user( $product, 1, true ) );
	}

	public function test_sellable_product_is_not_blocked_for_anyone(): void {
		$this->assertTrue( $this->load_gate_class() );
		$product = $this->make_product( [ 'id' => 33, 'price' => '6500', 'image_id' => '55' ] );

		$this->assertFalse( \LTMS_Business_Sync_Visibility_Gate::is_blocked_for_user( $product, 0, false ) );
	}

	// ─── SECCIÓN 4: visibility_exclude_clause + sweep_all ────────────────────

	public function test_visibility_exclude_clause_builds_not_in_clause(): void {
		$this->assertTrue( $this->load_gate_class() );
		Functions\when( 'wc_get_product_visibility_term_ids' )->alias( static fn() => [
			'exclude-from-catalog' => 15,
			'exclude-from-search'  => 16,
			'outofstock'           => 17,
		] );

		$clause = \LTMS_Business_Sync_Visibility_Gate::visibility_exclude_clause();

		$this->assertSame( 'product_visibility', $clause['taxonomy'] );
		$this->assertSame( 'term_taxonomy_id', $clause['field'] );
		$this->assertSame( 15, $clause['terms'] );
		$this->assertSame( 'NOT IN', $clause['operator'] );
	}

	public function test_visibility_exclude_clause_empty_without_catalog_term(): void {
		$this->assertTrue( $this->load_gate_class() );
		// Sin el término exclude-from-catalog (WC sin taxonomías inicializadas)
		// → cláusula vacía (sin fatal).
		Functions\when( 'wc_get_product_visibility_term_ids' )->alias( static fn() => [ 'outofstock' => 17 ] );

		$this->assertSame( [], \LTMS_Business_Sync_Visibility_Gate::visibility_exclude_clause() );
	}

	public function test_sweep_all_counts_outcomes(): void {
		$this->assertTrue( $this->load_gate_class() );
		// 3 productos: 20 (agotado → hidden), 22 (vendible con meta → restored),
		// 23 (vendible sin meta → unchanged).
		$products = [
			20 => $this->make_product( [ 'id' => 20, 'price' => '6500', 'stock_status' => 'outofstock' ] ),
			22 => $this->make_product( [ 'id' => 22, 'price' => '6500', 'image_id' => '55', 'catalog_visibility' => 'hidden' ] ),
			23 => $this->make_product( [ 'id' => 23, 'price' => '6500', 'image_id' => '55' ] ),
		];
		$products[22]->update_meta_data( '_ltms_visibility_blocked', '["image"]' );

		Functions\when( 'wc_get_products' )->alias( static fn( $args ) => [ 20, 22, 23 ] );
		Functions\when( 'wc_get_product' )->alias( static fn( $id ) => $products[ (int) $id ] );

		$result = \LTMS_Business_Sync_Visibility_Gate::sweep_all();

		$this->assertSame( 1, $result['hidden'] );
		$this->assertSame( 1, $result['restored'] );
		$this->assertSame( 1, $result['unchanged'] );
		$this->assertSame( 'hidden', $products[20]->get_catalog_visibility() );
		$this->assertSame( 'visible', $products[22]->get_catalog_visibility() );
	}

	// ─── SECCIÓN 5: call-sites (paridad a nivel de fuente) ───────────────────

	public function test_posgold_sync_calls_apply_gate_in_create_and_update(): void {
		$source = $this->read_source( 'includes/business/class-ltms-posgold-sync.php' );

		// 2 call-sites: create_product (tras download_and_attach_image) y
		// update_product_fields (al final).
		$this->assertSame(
			2,
			substr_count( $source, 'LTMS_Business_Sync_Visibility_Gate::apply_gate' ),
			'posgold-sync debe llamar apply_gate en create_product Y update_product_fields.'
		);
		// El call-site de create va DESPUÉS de la descarga de imagen.
		$download_pos = strpos( $source, 'download_and_attach_image( $product[\'imagen_url\'], $wc_product->get_id() );' );
		$gate_pos     = strpos( $source, 'LTMS_Business_Sync_Visibility_Gate::apply_gate' );
		$this->assertNotFalse( $download_pos );
		$this->assertNotFalse( $gate_pos );
		$this->assertGreaterThan( $download_pos, $gate_pos, 'apply_gate debe evaluarse DESPUÉS de descargar la imagen (la imagen se adjunta POST-save).' );
	}

	public function test_vtex_sync_calls_apply_gate_in_create_and_update(): void {
		$source = $this->read_source( 'includes/business/class-ltms-vtex-sync.php' );

		$this->assertSame(
			2,
			substr_count( $source, 'LTMS_Business_Sync_Visibility_Gate::apply_gate' ),
			'vtex-sync debe llamar apply_gate en create_product Y update_product_fields.'
		);
	}

	public function test_vendor_store_template_filters_visibility(): void {
		$source = $this->read_source( 'includes/frontend/templates/vendor-store.php' );

		$this->assertStringContainsString( "SYNC-VIS-GATE", $source, 'vendor-store.php debe documentar el filtro del gate.' );
		$this->assertStringContainsString( '$pv_p->is_visible()', $source, 'vendor-store.php debe excluir productos no visibles (is_visible).' );
	}

	public function test_vendor_storefront_queries_exclude_gate_hidden_products(): void {
		$source = $this->read_source( 'includes/frontend/class-ltms-vendor-storefront.php' );

		// 3 queries custom: render inicial, búsqueda AJAX y load_more.
		$this->assertSame(
			3,
			substr_count( $source, 'visibility_exclude_clause' ),
			'vendor-storefront debe excluir productos ocultos en las 3 queries custom.'
		);
	}

	public function test_quick_view_guard_blocks_unsellable_products(): void {
		$source = $this->read_source( 'includes/frontend/class-ltms-quick-view.php' );

		$this->assertStringContainsString( 'LTMS_Business_Sync_Visibility_Gate::is_unsellable', $source, 'quick-view debe rechazar productos no vendibles.' );
	}

	public function test_live_search_filters_visibility(): void {
		$source = $this->read_source( 'includes/frontend/class-ltms-frontend-live-search.php' );

		$this->assertStringContainsString( 'SYNC-VIS-GATE', $source, 'live-search debe documentar el filtro del gate.' );
		$this->assertStringContainsString( '$p->is_visible()', $source, 'live-search debe excluir productos no visibles.' );
	}

	public function test_kernel_registers_gate_init(): void {
		$source = $this->read_source( 'includes/core/class-ltms-kernel.php' );

		$this->assertStringContainsString( 'LTMS_Business_Sync_Visibility_Gate::init()', $source, 'El kernel debe registrar el gate (hooks + guard).' );
	}
}
