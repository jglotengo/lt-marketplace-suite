<?php

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * Unit tests for LTMS_Fiscal_Annual_Close.
 *
 * Ciclo 1.3 (audit fiscal): cubre los 2 fixes P1 aplicados:
 *   - P1-1: typo `total_operazioni` (italiano) en notify_cert_available() —
 *           ahora se usa siempre la clave correcta `total_operaciones`.
 *   - P1-2: IDOR autenticado en ajax_download_cert() — un vendor sólo puede
 *           descargar su propio cert; un admin con capability
 *           `ltms_manage_platform_settings` puede descargar el de cualquiera;
 *           un usuario autenticado sin ownership ni capability recibe 403.
 *
 * Nota técnica: `get_current_user_id` y `is_user_logged_in` están definidas
 * en tests/bootstrap.php antes que Patchwork (no se pueden re-stubear con
 * Brain\Monkey). Se controlan vía los globals `$GLOBALS['__ltms_current_uid']`
 * y `$GLOBALS['__ltms_logged_in']` que el bootstrap expone.
 *
 * @package LTMS\Tests\Unit
 */
class FiscalAnnualCloseTest extends LTMS_Unit_Test_Case {

    /** @var array<string,bool> Capabilities del caller (para current_user_can). */
    private static array $caps = [];

    /** @var array<string,mixed>|null Certificado de prueba en user_meta. */
    private static ?array $cert_stub = null;

    protected function setUp(): void {
        parent::setUp();

        if ( ! class_exists( 'LTMS_Fiscal_Annual_Close' ) ) {
            $this->markTestSkipped( 'LTMS_Fiscal_Annual_Close no disponible en modo UNIT_ONLY.' );
        }

        self::$caps      = [];
        self::$cert_stub = null;
        $GLOBALS['__ltms_current_uid'] = 0;

        // Stubs Patchwork-friendly para funciones re-stubeables.
        Functions\when( 'current_user_can' )->alias( function ( string $cap ): bool {
            return self::$caps[ $cap ] ?? false;
        } );

        Functions\when( 'wp_send_json_success' )->alias( static function ( $data = null, $status = null ) {
            $GLOBALS['__ltms_json_out'] = [ 'success' => true, 'data' => $data, 'status' => $status ];
            throw new \RuntimeException( '__LTMS_JSON_SENT__' );
        } );
        Functions\when( 'wp_send_json_error' )->alias( static function ( $data = null, $status = null ) {
            $GLOBALS['__ltms_json_out'] = [ 'success' => false, 'data' => $data, 'status' => $status ];
            throw new \RuntimeException( '__LTMS_JSON_SENT__' );
        } );
        Functions\when( 'check_ajax_referer' )->alias( static fn() => true );

        Functions\when( 'get_user_meta' )->alias( static function ( $uid, $key, $single = false ) {
            if ( $key === '_ltms_withholding_cert_2024' ) {
                return self::$cert_stub;
            }
            if ( $key === '_ltms_gmf_cert_2024' ) {
                return [ 'total' => 50.0, 'details' => [] ];
            }
            return null;
        } );

        Functions\when( 'update_user_meta' )->alias( static fn() => true );
        Functions\when( 'update_option' )->alias( static fn() => true );
        Functions\when( 'get_userdata' )->alias( static fn() => null );
    }

    protected function tearDown(): void {
        unset( $GLOBALS['__ltms_json_out'], $GLOBALS['__ltms_current_uid'] );
        self::$caps      = [];
        self::$cert_stub = null;
        parent::tearDown();
    }

    /**
     * Helper: simula un caller autenticado (via globals del bootstrap) con
     * capabilities dadas (via current_user_can stub). Re-stubea
     * is_user_logged_in() en cada invocación porque Patchwork sí permite
     * redefinirla (no está pre-definida en el bootstrap).
     */
    private function act_as( int $uid, array $caps = [], bool $logged_in = true ): void {
        $GLOBALS['__ltms_current_uid'] = $uid;
        self::$caps                    = array_fill_keys( $caps, true );
        Functions\when( 'is_user_logged_in' )->justReturn( $logged_in );
    }

    /**
     * Helper: invoca ajax_download_cert() capturando la "excepción" que wp_send_json_*
     * lanza en el stub para simular wp_die(). Devuelve el array JSON emitido.
     */
    private function invoke_download_cert(): array {
        try {
            \LTMS_Fiscal_Annual_Close::ajax_download_cert();
        } catch ( \RuntimeException $e ) {
            if ( $e->getMessage() !== '__LTMS_JSON_SENT__' ) {
                throw $e;
            }
        }
        return $GLOBALS['__ltms_json_out'] ?? [ 'success' => null, 'data' => null, 'status' => null ];
    }

    // ── P1-1: typo total_operaciones ─────────────────────────────────────────

    /**
     * Verifica que generate_annual_withholding_certificates() construye el
     * certificado con la clave correcta `total_operaciones` — NO la typo
     * italiana `total_operazioni` (que estaba fallback enmascarando un bug).
     */
    public function test_cert_array_uses_correct_total_operaciones_key(): void {
        $row = [
            'vendor_id'            => 42,
            'total_retefuente'     => '100.00',
            'total_iva'            => '1900.00',
            'total_ieps'            => '0.00',
            'total_iva_retenido'   => '0.00',
            'total_ieps_retenido'  => '0.00',
            'total_ops'            => '5',
            'total_bruto'          => '10000.00',
            'total_neto'           => '9900.00',
        ];

        $GLOBALS['wpdb'] = new class( $row ) {
            public string $prefix = 'bkr_';
            public function __construct( public array $row ) {}
            public function get_results( mixed $q = null, string $output = 'OBJECT' ): array {
                return [ $this->row ];
            }
            public function prepare( string $q, mixed ...$args ): string { return $q; }
            public function update( string $t, array $d, array $w, mixed $f = null, mixed $wf = null ): int|bool { return 1; }
        };

        \LTMS_Core_Config::flush_cache();

        $out = \LTMS_Fiscal_Annual_Close::generate_annual_withholding_certificates( 2024 );

        $this->assertArrayHasKey( 'certificates', $out );
        $this->assertCount( 1, $out['certificates'] );
        $cert = $out['certificates'][0];

        $this->assertArrayHasKey( 'total_operaciones', $cert );
        $this->assertSame( 5, $cert['total_operaciones'] );

        // Regression check: la typo italiana NO debe existir.
        $this->assertArrayNotHasKey( 'total_operazioni', $cert );
    }

    // ── P1-2: IDOR en ajax_download_cert ─────────────────────────────────────

    /**
     * Escenario 1: admin con capability `ltms_manage_platform_settings`
     * puede descargar el cert de CUALQUIER vendor (caller 7, vendor 42).
     */
    public function test_download_cert_admin_can_download_any_vendor(): void {
        $this->act_as( 7, [ 'ltms_manage_platform_settings' ] );

        $_POST = [ 'vendor_id' => 42, 'year' => 2024 ];
        self::$cert_stub = [ 'vendor_id' => 42, 'year' => 2024, 'total_bruto' => 1000.0 ];

        $out = $this->invoke_download_cert();

        $this->assertNotNull( $out['success'], 'wp_send_json_* fue llamada' );
        $this->assertTrue( $out['success'], 'Admin puede descargar cert de cualquier vendor' );
        $this->assertSame( 42, $out['data']['certificate']['vendor_id'] );
    }

    /**
     * Escenario 2: vendor autenticado descargando SU PROPIO cert → success.
     * Caller UID = vendor_id = 42, sin caps.
     */
    public function test_download_cert_vendor_can_download_own(): void {
        $this->act_as( 42, [] );

        $_POST = [ 'vendor_id' => 42, 'year' => 2024 ];
        self::$cert_stub = [ 'vendor_id' => 42, 'year' => 2024, 'total_bruto' => 500.0 ];

        $out = $this->invoke_download_cert();

        $this->assertNotNull( $out['success'] );
        $this->assertTrue( $out['success'], 'Vendor puede descargar su propio cert' );
    }

    /**
     * Escenario 3 (regresión P1-2): vendor autenticado intenta descargar el
     * cert de OTRO vendor sin capability → debe recibir 403.
     *
     * Antes del fix, el check de authorization no existía — cualquier usuario
     * autenticado con el nonce admin bajaba cert ajenos. Este test garantiza
     * que la regresión se detecte de inmediato.
     */
    public function test_download_cert_vendor_cannot_download_other_vendor(): void {
        $this->act_as( 13, [] ); // caller 13 intentando leer vendor 42

        $_POST = [ 'vendor_id' => 42, 'year' => 2024 ];
        self::$cert_stub = [ 'vendor_id' => 42, 'year' => 2024, 'total_bruto' => 999.0 ];

        $out = $this->invoke_download_cert();

        $this->assertNotNull( $out['success'], 'wp_send_json_error fue llamada' );
        $this->assertFalse( $out['success'], 'Vendor no puede descargar cert ajeno' );
        $this->assertSame( 403, $out['status'], 'HTTP 403 para IDOR bloqueado' );
    }

    /**
     * Escenario 4: parámetros faltantes → error (no llega al check de authz).
     */
    public function test_download_cert_missing_params_errors(): void {
        $this->act_as( 1, [ 'ltms_manage_platform_settings' ] );

        $_POST = [ 'vendor_id' => 0, 'year' => 0 ];

        $out = $this->invoke_download_cert();

        $this->assertNotNull( $out['success'] );
        $this->assertFalse( $out['success'] );
    }

    /**
     * Escenario 5: certificado no existe en user_meta → error.
     */
    public function test_download_cert_not_found_errors(): void {
        $this->act_as( 1, [ 'ltms_manage_platform_settings' ] );
        self::$cert_stub = null;

        $_POST = [ 'vendor_id' => 42, 'year' => 2024 ];

        $out = $this->invoke_download_cert();

        $this->assertNotNull( $out['success'] );
        $this->assertFalse( $out['success'] );
    }

    /**
     * Escenario 6 (regresión adicional): caller no autenticado
     * (is_user_logged_in=false, get_current_user_id=0) → debe recibir 403
     * (no ownership posible: 0 !== vendor_id; no caps).
     */
    public function test_download_cert_unauthenticated_user_blocked(): void {
        // Sin act_as: globals default (uid=0), is_user_logged_in() = false via stub.
        Functions\when( 'is_user_logged_in' )->justReturn( false );

        $_POST = [ 'vendor_id' => 42, 'year' => 2024 ];
        self::$cert_stub = [ 'vendor_id' => 42, 'year' => 2024 ];

        $out = $this->invoke_download_cert();

        $this->assertNotNull( $out['success'] );
        $this->assertFalse( $out['success'], 'Usuario no autenticado no puede descargar' );
        $this->assertSame( 403, $out['status'] );
    }
}
