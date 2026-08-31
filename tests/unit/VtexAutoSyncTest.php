<?php
/**
 * VtexAutoSyncTest — re-sync automático periódico VTEX → WooCommerce.
 *
 * VTEX-AUTOSYNC: el catálogo/precios de VTEX cambian en la cuenta del vendor y
 * el marketplace debe reflejarlos sin que el vendor pulse "Sincronizar" a mano.
 * Cubre:
 *   - init() registra el hook AUTO_SYNC_CRON_HOOK y programa el evento diario
 *     (recurrencia 'daily') de forma idempotente (guard wp_next_scheduled).
 *   - run_auto_sync() enlista vendors con credenciales configuradas y programa
 *     un single-event escalonado (+5s) por vendor reusando CRON_HOOK.
 *   - Guards: omite vendors con sync manual en curso (_ltms_vtex_sync_in_progress
 *     <10 min) o con rate-limit reciente (ltms_vtex_last_sync <2 min).
 *   - Filtros: omite non-vendors y vendors sin credenciales completas.
 *
 * NOTA: NO se stubea time() — las comparaciones de los guards usan time()
 * real del test para que sean consistentes con el time() que ejecuta la clase.
 *
 * @package LTMS\Tests\Unit
 *
 * Ejecutar con: ./vendor/bin/phpunit --group audit-vtex-autosync
 *
 * @group audit-vtex-autosync
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;

/**
 * Class VtexAutoSyncTest
 *
 * @group audit-vtex-autosync
 */
final class VtexAutoSyncTest extends LTMS_Unit_Test_Case {

	/** Eventos capturados de wp_schedule_single_event (por referencia, no copia). */
	private array $captured_events = [];

	/** Llamadas capturadas de update_user_meta. */
	private array $captured_updates = [];

	private function require_sync_class(): void {
		$this->require_class( 'LTMS_Vtex_Sync' );
		$this->require_class( 'LTMS_Api_Vtex' );
	}

	/**
	 * Mapa de user_meta por (user_id => [key => value]).
	 */
	private function stub_user_meta( array $by_user ): void {
		Monkey\Functions\when( 'get_user_meta' )->alias(
			static function ( $user_id, $key = '', $single = false ) use ( $by_user ) {
				$user_id = (int) $user_id;
				if ( isset( $by_user[ $user_id ] ) && array_key_exists( $key, $by_user[ $user_id ] ) ) {
					return $by_user[ $user_id ][ $key ];
				}
				return '';
			}
		);
	}

	private function capture_updates(): void {
		$this->captured_updates = [];
		Monkey\Functions\when( 'update_user_meta' )->alias(
			function ( ...$args ) {
				$this->captured_updates[] = $args;
				return true;
			}
		);
	}

	private function capture_single_events(): void {
		$this->captured_events = [];
		Monkey\Functions\when( 'wp_schedule_single_event' )->alias(
			function ( ...$args ) {
				$this->captured_events[] = $args;
			}
		);
	}

	/**
	 * Credenciales VTEX válidas (plain — el decrypt falla con try/catch QA-002
	 * y degrada al valor raw).
	 */
	private function vendor_meta( string $account ): array {
		return [
			'ltms_vtex_account_name'           => $account,
			'ltms_vtex_environment'            => 'vtexcommercestable',
			'ltms_vtex_app_key'                => 'vtexappkey-test',
			'ltms_vtex_app_token'              => 'vtexapptoken-test',
			'_ltms_vtex_sync_in_progress'      => 0,
			'ltms_vtex_last_sync'              => 0,
			'ltms_vtex_category_ids_csv'       => '',
			'ltms_vtex_seo_template'           => '',
		];
	}

	private function stub_userdata( array $vendor_ids ): void {
		Monkey\Functions\when( 'get_userdata' )->alias(
			static function ( $id ) use ( $vendor_ids ) {
				$id    = (int) $id;
				$user  = new \stdClass();
				$user->ID    = $id;
				$user->roles = in_array( $id, $vendor_ids, true )
					? [ 'ltms_vendor' ]
					: [ 'administrator' ];
				return $user;
			}
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// init() — registro del hook y programación del evento diario.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_init_registers_auto_sync_hook_and_schedules_daily_event(): void {
		$this->require_sync_class();

		$actions = [];
		Monkey\Functions\when( 'add_action' )->alias(
			static function ( ...$args ) use ( &$actions ) {
				$actions[] = $args;
			}
		);
		Monkey\Functions\when( 'wp_next_scheduled' )->justReturn( false );
		$scheduled = [];
		Monkey\Functions\when( 'wp_schedule_event' )->alias(
			static function ( ...$args ) use ( &$scheduled ) {
				$scheduled[] = $args;
			}
		);

		\LTMS_Vtex_Sync::init();

		$hooks = array_column( $actions, 0 );
		$this->assertTrue(
			in_array( 'ltms_vtex_auto_sync', $hooks, true ),
			'init() debe registrar el hook del auto-sync.'
		);
		$this->assertNotEmpty( $scheduled, 'init() debe programar el evento diario.' );
		$this->assertSame( 'daily', $scheduled[0][1], 'Recurrencia diaria.' );
		$this->assertSame( 'ltms_vtex_auto_sync', $scheduled[0][2], 'Hook del auto-sync.' );
	}

	public function test_init_does_not_duplicate_when_already_scheduled(): void {
		$this->require_sync_class();

		Monkey\Functions\when( 'add_action' )->justReturn( true );
		Monkey\Functions\when( 'wp_next_scheduled' )->justReturn( 1234567890 );
		$scheduled = [];
		Monkey\Functions\when( 'wp_schedule_event' )->alias(
			static function ( ...$args ) use ( &$scheduled ) {
				$scheduled[] = $args;
			}
		);

		\LTMS_Vtex_Sync::init();

		$this->assertEmpty( $scheduled, 'No debe reprogramar si el evento ya existe.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// run_auto_sync() — enumeración y programación escalonada.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_run_auto_sync_schedules_staggered_events_per_configured_vendor(): void {
		$this->require_sync_class();

		Monkey\Functions\when( 'get_users' )->alias( static fn( $args = [] ) => [ 111, 222 ] );
		$this->stub_userdata( [ 111, 222 ] );
		$this->stub_user_meta( [
			111 => $this->vendor_meta( 'tienda1' ),
			222 => $this->vendor_meta( 'tienda2' ),
		] );
		$events  = $this->capture_single_events();
		$updates = $this->capture_updates();

		\LTMS_Vtex_Sync::run_auto_sync();

		$events  = $this->captured_events;
		$updates = $this->captured_updates;

		$this->assertCount( 2, $events, 'Debe programar 1 evento por vendor.' );
		$this->assertSame( 'ltms_vtex_sync_cron', $events[0][1], 'Reusa CRON_HOOK (run_scheduled_sync).' );
		$this->assertSame( [ 111 ], $events[0][2], 'Vendor 111 como argumento.' );
		$this->assertSame( [ 222 ], $events[1][2], 'Vendor 222 como argumento.' );
		$this->assertSame( 5, $events[1][0] - $events[0][0], 'Eventos escalonados +5s.' );

		$this->assertSame( 111, $updates[0][0], 'Marca in-progress del vendor 111.' );
		$this->assertSame( '_ltms_vtex_sync_in_progress', $updates[0][1] );
		$this->assertSame( 222, $updates[1][0], 'Marca in-progress del vendor 222.' );
	}

	public function test_run_auto_sync_skips_vendor_with_manual_sync_in_progress(): void {
		$this->require_sync_class();

		$meta = $this->vendor_meta( 'tienda1' );
		$meta['_ltms_vtex_sync_in_progress'] = time() - 10; // <10 min → en curso.

		Monkey\Functions\when( 'get_users' )->alias( static fn( $args = [] ) => [ 111 ] );
		$this->stub_userdata( [ 111 ] );
		$this->stub_user_meta( [ 111 => $meta ] );
		$this->capture_single_events();

		\LTMS_Vtex_Sync::run_auto_sync();

		$this->assertEmpty( $this->captured_events, 'Vendor con sync en curso no debe reprogramarse.' );
	}

	public function test_run_auto_sync_skips_vendor_with_recent_manual_sync(): void {
		$this->require_sync_class();

		$meta = $this->vendor_meta( 'tienda1' );
		$meta['ltms_vtex_last_sync'] = time() - 30; // <2 min → rate-limit activo.

		Monkey\Functions\when( 'get_users' )->alias( static fn( $args = [] ) => [ 111 ] );
		$this->stub_userdata( [ 111 ] );
		$this->stub_user_meta( [ 111 => $meta ] );
		$this->capture_single_events();

		\LTMS_Vtex_Sync::run_auto_sync();

		$this->assertEmpty( $this->captured_events, 'Vendor con rate-limit reciente no debe reprogramarse.' );
	}

	public function test_run_auto_sync_skips_non_vendor_and_unconfigured_users(): void {
		$this->require_sync_class();

		// 111 → administrator (non-vendor); 222 → vendor pero sin appToken (incompleto).
		$incomplete = $this->vendor_meta( 'tienda2' );
		$incomplete['ltms_vtex_app_token'] = '';

		Monkey\Functions\when( 'get_users' )->alias( static fn( $args = [] ) => [ 111, 222 ] );
		$this->stub_userdata( [ 222 ] );
		$this->stub_user_meta( [
			111 => $this->vendor_meta( 'admin-test' ),
			222 => $incomplete,
		] );
		$this->capture_single_events();

		\LTMS_Vtex_Sync::run_auto_sync();

		$this->assertEmpty( $this->captured_events, 'Non-vendors y vendors sin credenciales completas se omiten.' );
	}

	public function test_run_auto_sync_with_no_configured_vendors_is_noop(): void {
		$this->require_sync_class();

		Monkey\Functions\when( 'get_users' )->alias( static fn( $args = [] ) => [] );
		$this->capture_single_events();

		\LTMS_Vtex_Sync::run_auto_sync();

		$this->assertEmpty( $this->captured_events, 'Sin vendors configurados, no programa nada.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Auditoría estática — el código fuente preserva los guards y el patrón.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_source_contains_auto_sync_hook_and_guards(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/includes/business/class-ltms-vtex-sync.php' );
		$this->assertIsString( $src );

		$this->assertStringContainsString( "const AUTO_SYNC_CRON_HOOK = 'ltms_vtex_auto_sync'", $src, 'Const del hook auto-sync.' );
		$this->assertStringContainsString( "add_action( self::AUTO_SYNC_CRON_HOOK, [ __CLASS__, 'run_auto_sync' ] )", $src, 'Handler registrado en init().' );
		$this->assertStringContainsString( 'wp_schedule_event( strtotime( \'tomorrow 03:00\' ), self::AUTO_SYNC_INTERVAL', $src, 'Programación diaria.' );
		$this->assertStringContainsString( 'wp_next_scheduled( self::AUTO_SYNC_CRON_HOOK )', $src, 'Guard idempotente de programación.' );
		$this->assertStringContainsString( 'public static function run_auto_sync()', $src, 'Handler público del auto-sync.' );
		$this->assertStringContainsString( 'private static function get_vtex_configured_vendors()', $src, 'Enumeración de vendors.' );
		$this->assertStringContainsString( 'private static function auto_sync_allowed(', $src, 'Guard por vendor.' );
		$this->assertStringContainsString( '_ltms_vtex_sync_in_progress', $src, 'Guard de sync en curso.' );
		$this->assertStringContainsString( '2 * MINUTE_IN_SECONDS', $src, 'Guard de rate-limit de 2 min.' );
	}
}