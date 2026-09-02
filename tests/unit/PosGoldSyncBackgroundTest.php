<?php
/**
 * PosGoldSyncBackgroundTest — tests de la sync PosGold en background (POSGOLD-SYNC-BG).
 *
 * La sync manual pasó de ejecutarse en el request AJAX (el hosting mataba el
 * request a los pocos minutos → "Error de red") a programarse en background
 * vía WP-Cron (schedule_sync → CRON_HOOK → run_scheduled_sync), con polling de
 * estado desde el frontend. Este test cubre:
 *   - get_sync_status(): in_progress, resultado previo, flag stale (cron matado).
 *   - ajax_sync_posgold_products(): persiste el filtro de categorías ACTUAL
 *     (CSV o JSON) y programa la sync (NO la ejecuta en el request).
 *   - ajax_sync_posgold_products(): respeta el guard de sync en curso.
 *   - ajax_get_posgold_sync_status(): devuelve el estado para el polling.
 *   - normalize_category_filter(): el meta guardado como JSON se normaliza a
 *     CSV para filter_by_category (filtro que antes quedaba vacío).
 *
 * @package LTMS\Tests\Unit
 *
 * Ejecutar con: ./vendor/bin/phpunit --group audit-posgold-background
 *
 * @group audit-posgold-background
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;

/**
 * Class PosGoldSyncBackgroundTest
 *
 * @group audit-posgold-background
 */
final class PosGoldSyncBackgroundTest extends LTMS_Unit_Test_Case {

	private function require_classes(): void {
		$this->require_class( 'LTMS_PosGold_Sync' );
		$this->require_class( 'LTMS_Dashboard_Logic' );
		$this->require_class( 'LTMS_Utils' );
	}

	/**
	 * Captura wp_send_json_success para inspeccionar el payload.
	 */
	private function capture_json_success( callable $callable ): mixed {
		$captured = null;
		Monkey\Functions\when( 'wp_send_json_success' )->alias(
			static function ( $data = null ) use ( &$captured ): void {
				$captured = $data;
				throw new \RuntimeException( 'json_success' );
			}
		);

		try {
			$callable();
		} catch ( \RuntimeException $e ) {
			if ( $e->getMessage() !== 'json_success' ) {
				throw $e;
			}
		}

		return $captured;
	}

	/**
	 * Captura wp_send_json_error para inspeccionar payload.
	 */
	private function capture_json_error( callable $callable ): array {
		$captured_data = null;
		Monkey\Functions\when( 'wp_send_json_error' )->alias(
			static function ( $data = null, $status_code = null ) use ( &$captured_data ): void {
				$captured_data = $data;
				throw new \RuntimeException( 'json_error' );
			}
		);

		try {
			$callable();
		} catch ( \RuntimeException $e ) {
			if ( $e->getMessage() !== 'json_error' ) {
				throw $e;
			}
		}

		return [ 'data' => $captured_data ];
	}

	/**
	 * Simula un vendor autenticado con credenciales PosGold válidas.
	 */
	private function stub_logged_vendor( array $meta = [] ): void {
		// get_current_user_id() ya está definida en tests/bootstrap.php (lee el
		// global __ltms_current_uid) — NO se puede re-stubear con Brain\Monkey.
		$GLOBALS['__ltms_current_uid'] = 141;
		Monkey\Functions\stubs( [
			'check_ajax_referer' => true,
			'is_user_logged_in'  => true,
		] );
		Monkey\Functions\when( 'get_userdata' )->alias(
			static fn( $user_id ) => (object) [ 'roles' => [ 'ltms_vendor' ] ]
		);

		$defaults = [
			'ltms_posgold_subdomain'        => 'mistienda',
			'ltms_posgold_token'            => 't',
			'ltms_posgold_empresaid'        => '1',
			'ltms_posgold_usuarioid'        => '1',
			'ltms_posgold_bodegaid'         => '1',
			'_ltms_posgold_sync_in_progress' => '0',
			'_ltms_posgold_sync_last_result' => null,
			'ltms_posgold_last_sync'        => '0',
			'ltms_posgold_last_sync_count'  => '0',
		];
		$map = array_merge( $defaults, $meta );

		Monkey\Functions\when( 'get_user_meta' )->alias(
			static function ( $user_id, $key = '', $single = false ) use ( $map ) {
				return $map[ $key ] ?? '';
			}
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// get_sync_status()
	// ─────────────────────────────────────────────────────────────────────────

	public function test_get_sync_status_reports_in_progress_and_last_result(): void {
		$this->require_classes();
		$now = time();

		$this->stub_logged_vendor( [
			'_ltms_posgold_sync_in_progress' => (string) ( $now - 5 ), // <30 min → en curso.
			'_ltms_posgold_sync_last_result' => [
				'completed_at' => '2026-08-31 12:00:00',
				'success'      => true,
				'created'      => 3,
				'updated'      => 2,
				'skipped'      => 1,
				'errors'       => [],
			],
			'ltms_posgold_last_sync_count'   => '10',
		] );

		$status = \LTMS_PosGold_Sync::get_sync_status( 141 );

		$this->assertTrue( $status['in_progress'], 'Con flag reciente debe reportar sync en curso.' );
		$this->assertSame( 3, $status['last_result']['created'] );
		$this->assertSame( 10, $status['last_sync_count'] );
		$this->assertSame( $now - 5, $status['in_progress_since'] );
	}

	public function test_get_sync_status_treats_stale_in_progress_as_not_running(): void {
		$this->require_classes();
		$now = time();

		$this->stub_logged_vendor( [
			'_ltms_posgold_sync_in_progress' => (string) ( $now - 3600 ), // >30 min → stale (cron matado).
			'_ltms_posgold_sync_last_result' => null,
		] );

		$status = \LTMS_PosGold_Sync::get_sync_status( 141 );

		$this->assertFalse( $status['in_progress'], 'Un flag de >30 min debe considerarse stale.' );
		$this->assertNull( $status['last_result'] );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// ajax_sync_posgold_products() → programa en background
	// ─────────────────────────────────────────────────────────────────────────

	public function test_ajax_sync_schedules_in_background_and_persists_categories(): void {
		$this->require_classes();
		$this->stub_logged_vendor();

		$captured            = new \stdClass();
		$captured->schedule_args = null;
		$captured->category_ids  = null;
		$captured->category_csv  = null;

		Monkey\Functions\when( 'update_user_meta' )->alias(
			static function ( $user_id, $key, $value, $prev = '' ) use ( $captured ) {
				if ( 'ltms_posgold_category_ids' === $key ) {
					$captured->category_ids = $value;
				}
				if ( 'ltms_posgold_category_ids_csv' === $key ) {
					$captured->category_csv = $value;
				}
				return true;
			}
		);
		Monkey\Functions\when( 'wp_schedule_single_event' )->alias(
			static function ( $time, $hook, $args ) use ( $captured ) {
				$captured->schedule_args = [ $time, $hook, $args ];
				return true;
			}
		);

		// El hidden input puede llegar como JSON (legacy) — debe normalizarse a CSV.
		$_POST['category_ids'] = '["8","4"]';

		$response = $this->capture_json_success(
			static fn() => ( new \LTMS_Dashboard_Logic() )->ajax_sync_posgold_products()
		);

		$this->assertTrue( $response['scheduled'] );
		$this->assertSame( 'ltms_posgold_sync_cron', $captured->schedule_args[1], 'Debe programar en el cron hook de PosGold.' );
		$this->assertSame( [ 141 ], $captured->schedule_args[2], 'Debe pasar el vendor_id como argumento.' );
		$this->assertSame( '["8","4"]', $captured->category_ids );
		$this->assertSame( '8,4', $captured->category_csv, 'Debe persistir el filtro actual como CSV para la sync.' );
	}

	public function test_ajax_sync_persists_csv_and_schedules(): void {
		$this->require_classes();
		$this->stub_logged_vendor();

		$captured            = new \stdClass();
		$captured->category_csv = null;
		$captured->scheduled    = false;

		Monkey\Functions\when( 'update_user_meta' )->alias(
			static function ( $user_id, $key, $value, $prev = '' ) use ( $captured ) {
				if ( 'ltms_posgold_category_ids_csv' === $key ) {
					$captured->category_csv = $value;
				}
				return true;
			}
		);
		Monkey\Functions\when( 'wp_schedule_single_event' )->alias(
			static function () use ( $captured ) {
				$captured->scheduled = true;
				return true;
			}
		);

		$_POST['category_ids'] = '8,4';

		$response = $this->capture_json_success(
			static fn() => ( new \LTMS_Dashboard_Logic() )->ajax_sync_posgold_products()
		);

		$this->assertTrue( $response['scheduled'] );
		$this->assertTrue( $captured->scheduled, 'Debe programar la sync, NO ejecutarla en el request.' );
		$this->assertSame( '8,4', $captured->category_csv );
	}

	public function test_ajax_sync_respects_in_progress_guard(): void {
		$this->require_classes();
		$now = time();

		$this->stub_logged_vendor( [
			'_ltms_posgold_sync_in_progress' => (string) ( $now - 10 ), // <600s → sync en curso.
		] );
		Monkey\Functions\when( 'update_user_meta' )->justReturn( true );
		Monkey\Functions\when( 'wp_schedule_single_event' )->justReturn( true );

		$_POST['category_ids'] = '';

		$err = $this->capture_json_error(
			static fn() => ( new \LTMS_Dashboard_Logic() )->ajax_sync_posgold_products()
		);

		$this->assertIsArray( $err['data'] );
		$this->assertStringContainsString(
			'sincronización en curso',
			strtolower( (string) ( $err['data']['message'] ?? '' ) ),
			'No debe programar una segunda sync mientras hay una en curso.'
		);
	}

	public function test_ajax_sync_empty_selection_clears_filter(): void {
		$this->require_classes();
		$this->stub_logged_vendor( [
			'ltms_posgold_category_ids_csv' => '8', // Filtro guardado previamente.
		] );

		$captured            = new \stdClass();
		$captured->category_csv = 'not-set';
		$captured->scheduled    = false;

		Monkey\Functions\when( 'update_user_meta' )->alias(
			static function ( $user_id, $key, $value, $prev = '' ) use ( $captured ) {
				if ( 'ltms_posgold_category_ids_csv' === $key ) {
					$captured->category_csv = $value;
				}
				return true;
			}
		);
		Monkey\Functions\when( 'wp_schedule_single_event' )->alias(
			static function () use ( $captured ) {
				$captured->scheduled = true;
				return true;
			}
		);

		// El JS nuevo envía category_ids vacío cuando el vendor deseleccionó
		// todas las categorías → debe sincronizarse TODO (filtro limpio).
		$_POST['category_ids'] = '';

		$response = $this->capture_json_success(
			static fn() => ( new \LTMS_Dashboard_Logic() )->ajax_sync_posgold_products()
		);

		$this->assertTrue( $response['scheduled'] );
		$this->assertTrue( $captured->scheduled );
		$this->assertSame( '', $captured->category_csv, 'Un envío vacío debe limpiar el filtro (sincronizar TODO).' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// ajax_get_posgold_sync_status() → polling
	// ─────────────────────────────────────────────────────────────────────────

	public function test_ajax_get_sync_status_returns_status_for_polling(): void {
		$this->require_classes();
		$now = time();

		$this->stub_logged_vendor( [
			'_ltms_posgold_sync_in_progress' => (string) ( $now - 5 ),
			'_ltms_posgold_sync_last_result' => [ 'completed_at' => '2026-08-31 12:00:00', 'success' => true ],
			'ltms_posgold_last_sync_count'   => '10',
		] );

		$response = $this->capture_json_success(
			static fn() => ( new \LTMS_Dashboard_Logic() )->ajax_get_posgold_sync_status()
		);

		$this->assertTrue( $response['in_progress'] );
		$this->assertTrue( $response['last_result']['success'] );
		$this->assertSame( 10, $response['last_sync_count'] );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// normalize_category_filter() (meta JSON guardado por ajax_save_posgold_categories)
	// ─────────────────────────────────────────────────────────────────────────

	public function test_normalize_category_filter_handles_json_and_csv(): void {
		$this->require_classes();
		$refl = new \ReflectionMethod( \LTMS_PosGold_Sync::class, 'normalize_category_filter' );
		$refl->setAccessible( true );

		$this->assertSame( [], $refl->invoke( null, '' ) );
		$this->assertSame( [ '8', '4' ], $refl->invoke( null, '8,4' ) );
		$this->assertSame( [ '8', '4' ], $refl->invoke( null, '["8","4"]' ) );
		$this->assertSame( [ '3' ], $refl->invoke( null, '3' ) );
		$this->assertSame( [], $refl->invoke( null, 'abc,,1x' ), 'Solo se aceptan ids numéricos.' );
	}
}