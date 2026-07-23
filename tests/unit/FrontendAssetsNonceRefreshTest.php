<?php

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;

/**
 * Unit tests for LTMS_Frontend_Assets::ajax_refresh_dashboard_nonce().
 *
 * FIX-403-NONCE: el nonce del panel de vendedor (ltmsDashboard.nonce) se
 * generaba una sola vez al renderizar la página, sin ningún mecanismo de
 * refresco. En sesiones largas sin recargar (ej. cuentas operadas por un
 * asistente/agente que mantiene la pestaña abierta por horas), el nonce
 * vencía y cada AJAX posterior fallaba con 403.
 *
 * FIX-403-NONCE-2: el intento original dependía de WP Heartbeat
 * (ltms_heartbeat_refresh_dashboard_nonce()), pero este hosting (SiteGround
 * Optimizer) desregistra wp_ajax_heartbeat, así que se reemplazó por un
 * endpoint AJAX propio: ajax_refresh_dashboard_nonce(), consumido por
 * initNonceRefresh() en ltms-dashboard.js vía polling directo (sin
 * heartbeat). Este test verifica ese endpoint actual.
 */
final class FrontendAssetsNonceRefreshTest extends LTMS_Unit_Test_Case {

    protected function setUp(): void {
        parent::setUp();
        $this->require_class( '\LTMS_Frontend_Assets' );
    }

    /**
     * Captura wp_send_json_success para inspeccionar el payload.
     */
    private function capture_json_success( callable $callable ): mixed {
        $captured = null;
        Monkey\Functions\when( 'wp_send_json_success' )->alias(
            function ( mixed $data = null ) use ( &$captured ): void {
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
     * Captura wp_send_json_error para inspeccionar payload y código HTTP.
     */
    private function capture_json_error( callable $callable ): array {
        $captured_data = null;
        $captured_code = null;
        Monkey\Functions\when( 'wp_send_json_error' )->alias(
            function ( mixed $data = null, ?int $status_code = null ) use ( &$captured_data, &$captured_code ): void {
                $captured_data = $data;
                $captured_code = $status_code;
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

        return [ 'data' => $captured_data, 'code' => $captured_code ];
    }

    public function test_refreshes_nonce_when_logged_in(): void {
        Monkey\Functions\stubs( [
            'is_user_logged_in' => true,
            'wp_create_nonce'   => static fn( string $action ) => 'fresh-nonce-for-' . $action,
        ] );

        $instance = new \LTMS_Frontend_Assets();

        $response = $this->capture_json_success(
            static fn() => $instance->ajax_refresh_dashboard_nonce()
        );

        $this->assertSame(
            [ 'nonce' => 'fresh-nonce-for-ltms_dashboard_nonce' ],
            $response
        );
    }

    /**
     * Regresión de seguridad: un usuario anónimo nunca debe recibir un
     * nonce fresco a través de este endpoint.
     */
    public function test_does_not_refresh_nonce_for_logged_out_user(): void {
        Monkey\Functions\stubs( [
            'is_user_logged_in' => false,
        ] );

        $instance = new \LTMS_Frontend_Assets();

        $result = $this->capture_json_error(
            static fn() => $instance->ajax_refresh_dashboard_nonce()
        );

        $this->assertSame( [ 'message' => 'not_logged_in' ], $result['data'] );
        $this->assertSame( 401, $result['code'] );
    }
}
