<?php
/**
 * KitchenAuditTest — auditoría del Kitchen Display System (KDS).
 *
 * KDS-AUDIT: el panel de cocina tenía DOS implementaciones duplicadas corriendo
 * al mismo tiempo (ltms-kds.min.js moderno + ltms-kitchen-view.min.js legacy),
 * field names rotos, endpoint de stats nunca llamado, query HPOS con status sin
 * prefijo 'wc-', y assets huérfanos (audio 404, JS legacy sin enqueue).
 *
 * Cubre (source-level + funcional):
 *   - KDS-001: el JS legacy duplicado se eliminó y view-kitchen.php ya no lo enqueua.
 *   - KDS-002: ltms-kds.js ya no envía `since` (borraba pedidos en cada poll).
 *   - KDS-004: ltms-kds.js llama al endpoint de stats (KPIs).
 *   - KDS-005: ajax_get_stats usa status con prefijo 'wc-' en el path HPOS.
 *   - KDS-006: enqueue de KDS usa $is_vendor_panel (fallback shortcode/slug).
 *   - KDS-007: alert_sound localizado solo si el mp3 existe.
 *   - KDS-008: markup con data-status + ltms-kds-new + data-next (match con CSS).
 *   - auto_set_kitchen_status_new() funcional (pedido → kitchen_status 'new').
 *
 * @package LTMS\Tests\Unit
 *
 * Ejecutar con: ./vendor/bin/phpunit --group audit-kitchen
 *
 * @group audit-kitchen
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;

require_once dirname( __DIR__, 2 ) . '/includes/frontend/class-ltms-kitchen-ajax.php';

/**
 * Class KitchenAuditTest
 *
 * @group audit-kitchen
 */
final class KitchenAuditTest extends LTMS_Unit_Test_Case {

	private function plugin_path( string $relative ): string {
		return dirname( __DIR__, 2 ) . '/' . $relative;
	}

	private function src( string $relative ): string {
		$content = file_get_contents( $this->plugin_path( $relative ) );
		$this->assertIsString( $content, "Debe poder leerse {$relative}." );
		return $content;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// KDS-001 — eliminación del JS legacy duplicado.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_001_legacy_duplicate_js_files_deleted(): void {
		$this->assertFileDoesNotExist( $this->plugin_path( 'assets/js/ltms-kitchen-view.js' ), 'El JS legacy duplicado debe eliminarse.' );
		$this->assertFileDoesNotExist( $this->plugin_path( 'assets/js/ltms-kitchen-view.min.js' ), 'El min legacy duplicado debe eliminarse.' );
	}

	public function test_001_view_kitchen_no_legacy_enqueue(): void {
		$src = $this->src( 'includes/frontend/views/view-kitchen.php' );

		$this->assertStringNotContainsString( "ltms_asset_url( 'js/ltms-kitchen-view' )", $src, 'view-kitchen.php no debe enqueuear el JS legacy.' );
		$this->assertStringNotContainsString( 'wp_enqueue_script( \'ltms-kitchen-view\'', $src, 'view-kitchen.php no debe enqueuear el JS legacy.' );
		$this->assertStringNotContainsString( '<audio', $src, 'No debe haber <audio> apuntando a un mp3 inexistente.' );
		$this->assertStringNotContainsString( '.ltms-kds-card {', $src, 'No debe quedar el <style> inline legacy.' );
		$this->assertStringNotContainsString( 'ltms-kds-auto-refresh', $src, 'Checkbox Auto legacy removido.' );
	}

	public function test_001_deploy_whitelist_drops_legacy_js(): void {
		$src = $this->src( 'deploy/ltms-deploy-webhook.php' );

		$this->assertStringNotContainsString( 'ltms-kitchen-view', $src, 'La whitelist de deploy no debe listar los JS legacy.' );
		$this->assertStringContainsString( 'class-ltms-kitchen-ajax.php', $src, 'El PHP del KDS sigue en la whitelist.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// KDS-002 — el JS moderno ya no borra pedidos con el filtro `since`.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_002_kds_js_does_not_send_since(): void {
		$src = $this->src( 'assets/js/ltms-kds.js' );

		$this->assertStringNotContainsString( 'since:', $src, 'ltms-kds.js no debe enviar `since` (causaba que cada poll borrara los pedidos activos).' );
		$this->assertStringNotContainsString( 'lastSince', $src, 'El estado lastSince quedó obsoleto.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// KDS-004 — stats: el JS debe llamar al endpoint dedicado.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_004_kds_js_calls_stats_endpoint(): void {
		$src = $this->src( 'assets/js/ltms-kds.js' );

		$this->assertStringContainsString( 'ltms_kitchen_get_stats', $src, 'ltms-kds.js debe llamar al endpoint de stats.' );
		$this->assertStringContainsString( 'ltms-kds-stat-new', $src, 'Debe actualizar el KPI Nuevos.' );
		$this->assertStringContainsString( 'ltms-kds-stat-served', $src, 'Debe actualizar el KPI Servidos hoy.' );
	}

	public function test_004_kds_min_regenerated_with_stats_and_data_status(): void {
		$src = $this->src( 'assets/js/ltms-kds.min.js' );

		$this->assertStringContainsString( 'ltms_kitchen_get_stats', $src, 'El .min regenerado debe incluir el endpoint de stats.' );
		$this->assertStringContainsString( 'data-status', $src, 'El .min regenerado debe incluir data-status.' );
		$this->assertStringNotContainsString( 'since', $src, 'El .min no debe tener el filtro `since`.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// KDS-005 — query HPOS de stats con prefijo 'wc-'.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_005_stats_hpos_uses_wc_prefixed_statuses(): void {
		$src = $this->src( 'includes/frontend/class-ltms-kitchen-ajax.php' );

		$this->assertStringContainsString( "o.status IN ('wc-processing', 'wc-on-hold')", $src, 'El path HPOS debe filtrar con status prefijado wc-.' );
		$this->assertStringContainsString( "o.status = 'wc-completed'", $src, 'El path HPOS de servidos debe usar wc-completed.' );
		$this->assertStringContainsString( "p.post_status IN ('wc-processing', 'wc-on-hold')", $src, 'El path legacy postmeta debe mantenerse con wc-.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// KDS-006/007 — enqueue de KDS.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_006_frontend_assets_kds_uses_is_vendor_panel(): void {
		$src = $this->src( 'includes/frontend/class-ltms-frontend-assets.php' );

		$this->assertStringContainsString( '$is_vendor_panel &&', $src, 'El KDS debe enqueuearse con $is_vendor_panel (fallback shortcode/slug).' );
		$this->assertStringContainsString( 'ltms_is_restaurant', $src, 'Debe respetar el flag de restaurante.' );
	}

	public function test_007_frontend_assets_alert_sound_guarded_by_file_exists(): void {
		$src = $this->src( 'includes/frontend/class-ltms-frontend-assets.php' );

		$this->assertStringContainsString( 'file_exists( LTMS_PLUGIN_DIR . \'assets/sounds/new-order.mp3\' )', $src, 'alert_sound solo debe apuntar al mp3 si el archivo existe.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// KDS-008 — markup del JS alineado con el CSS.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_008_kds_js_markup_matches_css(): void {
		$src = $this->src( 'assets/js/ltms-kds.js' );

		$this->assertStringContainsString( "'data-status': order.status", $src, 'La tarjeta debe llevar data-status con el kitchen status.' );
		$this->assertStringContainsString( 'ltms-kds-new', $src, 'Los pedidos nuevos deben llevar la clase de pulso.' );
		$this->assertStringContainsString( 'data-next="\' + nextStatus.key + \'"', $src, 'El botón de estado debe llevar data-next (colores del CSS).' );
	}

	public function test_008_css_has_kitchen_status_selectors(): void {
		$src = $this->src( 'assets/css/ltms-kds.css' );

		$this->assertStringContainsString( '[data-status="new"]', $src, 'CSS debe colorear el estado kitchen new.' );
		$this->assertStringContainsString( '[data-status="served"]', $src, 'CSS debe colorear el estado kitchen served.' );
		$this->assertStringContainsString( 'ltms-kds-livepulse', $src, 'Keyframes del indicador En vivo movidas al CSS.' );
		$this->assertStringContainsString( 'ltms-kds-spin', $src, 'Keyframes del skeleton movidas al CSS.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Funcional — auto_set_kitchen_status_new().
	// ─────────────────────────────────────────────────────────────────────────

	public function test_auto_set_kitchen_status_new_sets_new_for_restaurant_vendor(): void {
		$this->require_class( 'LTMS_Kitchen_Ajax' );

		$order = \Mockery::mock();
		$order->shouldReceive( 'get_meta' )->with( '_ltms_vendor_id' )->andReturn( 141 );
		$order->shouldReceive( 'get_meta' )->with( '_ltms_kitchen_status' )->andReturn( '' );
		$order->shouldReceive( 'update_meta_data' )->with( '_ltms_kitchen_status', 'new' )->once();
		$order->shouldReceive( 'update_meta_data' )->with( '_ltms_kitchen_status_at', \Mockery::type( 'string' ) )->once();
		$order->shouldReceive( 'save' )->once();

		Monkey\Functions\when( 'wc_get_order' )->alias( static fn( $id ) => $order );
		Monkey\Functions\when( 'get_user_meta' )->alias(
			static fn( $uid, $key = '', $single = false ) => ( 'ltms_is_restaurant' === $key ) ? 'yes' : ''
		);

		\LTMS_Kitchen_Ajax::auto_set_kitchen_status_new( 999 );

		$this->assertTrue( true, 'No debe lanzar y debe setear kitchen_status=new.' );
	}

	public function test_auto_set_kitchen_status_new_skips_non_restaurant_vendor(): void {
		$this->require_class( 'LTMS_Kitchen_Ajax' );

		$order = \Mockery::mock();
		$order->shouldReceive( 'get_meta' )->with( '_ltms_vendor_id' )->andReturn( 141 );
		$order->shouldNotReceive( 'update_meta_data' );

		Monkey\Functions\when( 'wc_get_order' )->alias( static fn( $id ) => $order );
		Monkey\Functions\when( 'get_user_meta' )->alias(
			static fn( $uid, $key = '', $single = false ) => ( 'ltms_is_restaurant' === $key ) ? 'no' : ''
		);

		\LTMS_Kitchen_Ajax::auto_set_kitchen_status_new( 999 );

		$this->assertTrue( true, 'Vendor no-restaurante no debe tocar el pedido.' );
	}

	public function test_auto_set_kitchen_status_new_skips_order_without_vendor_meta(): void {
		$this->require_class( 'LTMS_Kitchen_Ajax' );

		$order = \Mockery::mock();
		$order->shouldReceive( 'get_meta' )->with( '_ltms_vendor_id' )->andReturn( 0 );
		$order->shouldNotReceive( 'update_meta_data' );

		Monkey\Functions\when( 'wc_get_order' )->alias( static fn( $id ) => $order );

		\LTMS_Kitchen_Ajax::auto_set_kitchen_status_new( 999 );

		$this->assertTrue( true, 'Pedido sin vendor meta no debe tocarse.' );
	}

	public function test_auto_set_kitchen_status_new_does_not_override_existing_status(): void {
		$this->require_class( 'LTMS_Kitchen_Ajax' );

		$order = \Mockery::mock();
		$order->shouldReceive( 'get_meta' )->with( '_ltms_vendor_id' )->andReturn( 141 );
		$order->shouldReceive( 'get_meta' )->with( '_ltms_kitchen_status' )->andReturn( 'preparing' );
		$order->shouldNotReceive( 'update_meta_data' );

		Monkey\Functions\when( 'wc_get_order' )->alias( static fn( $id ) => $order );
		Monkey\Functions\when( 'get_user_meta' )->alias(
			static fn( $uid, $key = '', $single = false ) => ( 'ltms_is_restaurant' === $key ) ? 'yes' : ''
		);

		\LTMS_Kitchen_Ajax::auto_set_kitchen_status_new( 999 );

		$this->assertTrue( true, 'Un kitchen_status existente no debe sobreescribirse.' );
	}
}