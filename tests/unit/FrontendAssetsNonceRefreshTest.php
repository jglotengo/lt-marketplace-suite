<?php

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;

/**
 * Unit tests for LTMS_Frontend_Assets::ltms_heartbeat_refresh_dashboard_nonce().
 *
 * FIX-403-NONCE: el nonce del panel de vendedor (ltmsDashboard.nonce) se
 * generaba una sola vez al renderizar la página, sin ningún mecanismo de
 * refresco. En sesiones largas sin recargar (ej. cuentas operadas por un
 * asistente/agente que mantiene la pestaña abierta por horas), el nonce
 * vencía y cada AJAX posterior fallaba con 403. Este test verifica que el
 * filtro de WP Heartbeat devuelve un nonce fresco cuando el JS lo solicita,
 * y que se ignora en los casos donde no debe actuar (usuario no logueado,
 * o el tick de heartbeat no pidió el refresco).
 */
final class FrontendAssetsNonceRefreshTest extends LTMS_Unit_Test_Case {

    protected function setUp(): void {
        parent::setUp();
        $this->require_class( '\LTMS_Frontend_Assets' );
    }

    public function test_refreshes_nonce_when_requested_and_logged_in(): void {
        Monkey\Functions\stubs( [
            'is_user_logged_in' => true,
            'wp_create_nonce'   => static fn( string $action ) => 'fresh-nonce-for-' . $action,
        ] );

        $instance = new \LTMS_Frontend_Assets();
        $response = $instance->ltms_heartbeat_refresh_dashboard_nonce(
            [],
            [ 'ltms_refresh_dashboard_nonce' => true ]
        );

        $this->assertArrayHasKey( 'ltms_dashboard_nonce', $response );
        $this->assertSame( 'fresh-nonce-for-ltms_dashboard_nonce', $response['ltms_dashboard_nonce'] );
    }

    public function test_does_not_touch_response_when_not_requested(): void {
        Monkey\Functions\stubs( [
            'is_user_logged_in' => true,
            'wp_create_nonce'   => static fn( string $action ) => 'fresh-nonce-for-' . $action,
        ] );

        $instance = new \LTMS_Frontend_Assets();
        $response = $instance->ltms_heartbeat_refresh_dashboard_nonce(
            [ 'some_other_key' => 'untouched' ],
            [] // el tick de heartbeat no pidió refresco.
        );

        $this->assertArrayNotHasKey( 'ltms_dashboard_nonce', $response );
        $this->assertSame( [ 'some_other_key' => 'untouched' ], $response );
    }

    /**
     * Regresión de seguridad: no se debe emitir un nonce para un usuario
     * anónimo, aunque el payload del heartbeat lo pida (evita que un cliente
     * no autenticado obtenga un nonce válido llamando al heartbeat público).
     */
    public function test_does_not_refresh_nonce_for_logged_out_user(): void {
        Monkey\Functions\stubs( [
            'is_user_logged_in' => false,
            'wp_create_nonce'   => static fn( string $action ) => 'fresh-nonce-for-' . $action,
        ] );

        $instance = new \LTMS_Frontend_Assets();
        $response = $instance->ltms_heartbeat_refresh_dashboard_nonce(
            [],
            [ 'ltms_refresh_dashboard_nonce' => true ]
        );

        $this->assertArrayNotHasKey( 'ltms_dashboard_nonce', $response );
    }
}
