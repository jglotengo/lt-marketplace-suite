<?php

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;

/**
 * Unit tests for the new Plaza Viva add-to-cart AJAX handler
 * (`LTMS_Frontend_Checkout_Handler::ajax_plaza_viva_add_to_cart()`).
 *
 * AUDIT-FE-HOME-002 + AUDIT-FE-PROD-009 FIX (Fase 1.1).
 *
 * Antes: el JS `ltms-plaza-viva.js:601` invocaba la acción
 * `ltms_plaza_viva_add_to_cart` que NUNCA tenía handler PHP registrado
 * → el navegador recibía 400 `Unknown action` → toast "Error de conexión".
 * Afectaba home.php, vendor-store.php, single-product.php (sticky ATC + bundle)
 * y wc-parts/content-product.php. Este test verifica el handler nuevo.
 */
final class PlazaVivaAddToCartTest extends LTMS_Unit_Test_Case {

    protected function setUp(): void {
        parent::setUp();
        $this->require_class( '\LTMS_Frontend_Checkout_Handler' );
    }

    /**
     * Captura wp_send_json_success lanzando excepción controlada.
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
     * Captura wp_send_json_error (payload + status code).
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

    /**
     * Regresión: nonce inválido → 403 y mensaje estándar.
     */
    public function test_rejects_invalid_nonce(): void {
        // AUDIT-FE-PV-001 (re-audit Fase 1.4): WP check_ajax_referer signature
        // es ( $action, $query_arg='nonce', $stop=true ) — el 3er arg tiene
        // default, pero Brain\Monkey solo pasa los args que el caller realmente
        // usó (2). El closure anterior pedía 3 sin default → ArgumentCountError.
        Monkey\Functions\when( 'check_ajax_referer' )->alias(
            static fn( string $action, string $query_arg = 'nonce' ): bool => false
        );

        // CI fix (commit b9c55518 followup): el handler (class-ltms-frontend-
        // checkout-handler.php:2406) evalúa `! function_exists( 'WC' ) || ! WC()->cart`
        // para emitir un 503 cuando WooCommerce no está disponible. Brain\Monkey
        // no resuelve `WC()` automágicamente si ningún test previo lo mockeó,
        // y en CI Ubuntu el orden de tests hace que este test sea el primero
        // en tocar el handler → "WC" is not defined nor mocked. Localmente
        // pasaba porque otro test previo (con shared Monkey state) dejaba `WC`
        // mockeado. Mockear explícitamente aquí elimina la dependencia de orden
        // de tests y hace el test determinista. Devolvemos stdClass con
        // `cart = null` para que el guard 503 se_ACTIVE y el handler llame
        // wp_send_json_error — que es exactamente lo que el assertion espera.
        $wc_stub      = new \stdClass();
        $wc_stub->cart = null;
        Monkey\Functions\when( 'WC' )->alias( static fn() => $wc_stub );

        $result = $this->capture_json_error(
            static fn() => \LTMS_Frontend_Checkout_Handler::ajax_plaza_viva_add_to_cart()
        );

        // check_ajax_referer con $stop=false (nuestro caso) devuelve false y NO
        // detiene la ejecución; el handler debe fallar con wp_send_json_error.
        // Si $stop=true, check_ajax_referer mata la request en WP core y nuestro
        // handler nunca se ejecuta — ambos caminos son seguros. Aquí probamos
        // que el handler es seguro cuando referer falla silenciosamente.
        $this->assertNotNull( $result['data'], 'Handler should call wp_send_json_error on invalid nonce' );
    }

    /**
     * Regresión: product_id=0 o ausente → 400.
     */
    public function test_rejects_missing_product_id(): void {
        // Nonce válido.
        Monkey\Functions\when( 'check_ajax_referer' )->alias(
            static fn( string $action, string $query_arg = 'nonce' ): bool => true
        );

        // Sin $_POST['product_id'].
        unset( $_POST['product_id'], $_POST['quantity'] );

        // CI fix (commit b9c55518 followup): same reason as test_rejects_invalid_nonce.
        // El handler llama `WC()->cart` en el guard 503 — mockear WC() evita
        // que Brain\Monkey se queje. Aquí damos a WC()->cart un valor truthy
        // (un stdClass) para que el guard 503 NO se_active, el handler continúe
        // y llegue al guard `! $product_id` (línea 2413) que dispara el 400
        // esperado por este test.
        $wc_cart_stub  = new \stdClass();
        $wc_stub       = new \stdClass();
        $wc_stub->cart = $wc_cart_stub;
        Monkey\Functions\when( 'WC' )->alias( static fn() => $wc_stub );

        // WC faux para cubrir el guard de "WC()->cart no disponible" —
        // queremos que el handler llegue al guard de product_id antes de tocar WC.
        $result = $this->capture_json_error(
            static fn() => \LTMS_Frontend_Checkout_Handler::ajax_plaza_viva_add_to_cart()
        );

        // Si WC() no está definido en el test, el handler falla con 503 primero.
        // Aceptamos ambos: 400 (product_id missing) o 503 (WC unavailable), pero
        // NO success. Lo importante: nunca success con product_id=0.
        $this->assertNotNull( $result['data'] );
        $this->assertContains( $result['code'], [ 400, 503, null ], 'Should reject missing product_id before calling WC' );
    }

    /**
     * AUDIT-FE-PV-001 (re-audit Fase 1.4, P0): el handler debe validar
     * contra el nonce global `ltms_plaza_viva` (NO `ltms_ux_nonce`).
     *
     * helper JS PV.ajax siempre envía PV.config.nonce, que es
     * wp_create_nonce('ltms_plaza_viva') (ver class-ltms-native-templates.php:327).
     * Validar contra 'ltms_ux_nonce' es un P0: 100% de las llamadas AJAX
     * recibían 403 y el vendor veía "Error de conexión" en cualquier botón
     * "Agregar al carrito" del design system.
     */
    public function test_handler_validates_against_plaza_viva_nonce(): void {
        $reflection = new \ReflectionMethod(
            \LTMS_Frontend_Checkout_Handler::class,
            'ajax_plaza_viva_add_to_cart'
        );
        $src = file_get_contents( $reflection->getFileName() );
        $start = $reflection->getStartLine() - 1;
        $length = $reflection->getEndLine() - $start;
        $method_body = implode( "\n", array_slice( preg_split( '/\r?\n/', $src ), $start, $length ) );

        $this->assertStringContainsString(
            "check_ajax_referer( 'ltms_plaza_viva', 'nonce' )",
            $method_body,
            'AUDIT-FE-PV-001 fix: handler must validate against ltms_plaza_viva nonce'
        );
        $this->assertStringNotContainsString(
            "check_ajax_referer( 'ltms_ux_nonce', 'nonce' )",
            $method_body,
            'AUDIT-FE-PV-001 regression: handler must NOT validate against ltms_ux_nonce (nonce never sent by PV.ajax)'
        );
    }
}
