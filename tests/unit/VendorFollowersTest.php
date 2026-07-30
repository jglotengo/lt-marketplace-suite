<?php

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;

/**
 * Unit tests for LTMS_Vendor_Followers (Fase 1.4 — fix AUDIT-FE-SF-006).
 *
 * AUDIT-FE-SF-006: el botón "Seguir vendedor" de vendor-store.php:353
 * (data-pv-follow-vendor) disparaba el JS inline (vendor-store.php:620-633)
 * que solo cambiaba el label "Seguir"↔"Siguiendo" — sin persistir el follow.
 * La nueva clase LTMS_Vendor_Followers registra wp_ajax(_nopriv)_ltms_follow_vendor
 * que inserta/delete una fila en `bkr_lt_vendor_followers`.
 */
final class VendorFollowersTest extends LTMS_Unit_Test_Case {

    protected function setUp(): void {
        parent::setUp();
        $this->require_class( '\LTMS_Vendor_Followers' );
    }

    /**
     * WPDB mock que asserta los SQL ejecutados.
     */
    private function make_wpdb_mock(): \stdClass {
        $wpdb = new \stdClass();
        $wpdb->prefix = 'bkr_';
        $wpdb->insertions = [];
        $wpdb->deletions = [];
        $wpdb->selects = [];
        $wpdb->insert = function ( string $table, array $data, array $fmt = null ): int|false {
            $wpdb->insertions[] = [ 'table' => $table, 'data' => $data ];
            return 1;
        };
        $wpdb->delete = function ( string $table, array $where, array $fmt = null ): int|false {
            $wpdb->deletions[] = [ 'table' => $table, 'where' => $where ];
            return 1;
        };
        $wpdb->get_var = function ( string $query ) use ( &$wpdb ) {
            $wpdb->selects[] = $query;
            // Heurística: si es COUNT(*) devolvemos 1, si es SELECT id
            // (lookup de follow existente) devolvemos null (no existe aún).
            return stripos( $query, 'COUNT(*)' ) !== false ? '1' : null;
        };
        $wpdb->prepare = static fn( string $query, ...$args ): string => $query;

        global $wpdb;
        $wpdb = $this->getMockBuilder( \stdClass::class )->addMethods( [ 'insert', 'delete', 'get_var', 'prepare' ] )->getMock();
        $wpdb->prefix = 'bkr_';
        $wpdb->method( 'insert' )->willReturn( 1 );
        $wpdb->method( 'delete' )->willReturn( 1 );
        $wpdb->method( 'get_var' )->willReturnCallback( function ( string $q ) use ( $wpdb ) {
            $wpdb->calls[] = $q;
            return stripos( $q, 'COUNT(*)' ) !== false ? '1' : null;
        } );
        $wpdb->method( 'prepare' )->willReturnCallback( static fn( $q, ...$a ) => $q );
        $wpdb->calls = [];

        return $wpdb;
    }

    /**
     * Regresión básica: vendor_id=0 → 400 sin tocar BD.
     */
    public function test_rejects_missing_vendor_id(): void {
        Monkey\Functions\when( 'check_ajax_referer' )->alias(
            static fn( string $action, string $query_arg, bool $stop = false ): bool => true
        );

        unset( $_POST['vendor_id'] );

        $captured = null;
        $captured_code = null;
        Monkey\Functions\when( 'wp_send_json_error' )->alias(
            function ( $data = null, $status_code = null ) use ( &$captured, &$captured_code ): void {
                $captured = $data;
                $captured_code = $status_code;
                throw new \RuntimeException( 'json_error' );
            }
        );

        try {
            \LTMS_Vendor_Followers::ajax_toggle_follow();
            $this->fail( 'Expected wp_send_json_error to be called' );
        } catch ( \RuntimeException $e ) {
            if ( $e->getMessage() !== 'json_error' ) throw $e;
        }

        $this->assertNotNull( $captured, 'Vendor_id=0 should error' );
        $this->assertSame( 400, $captured_code );
    }

    /**
     * count_followers con vendor_id inválido siempre devuelve 0 (defensive).
     */
    public function test_count_followers_rejects_invalid_vendor_id(): void {
        $this->assertSame( 0, \LTMS_Vendor_Followers::count_followers( 0 ) );
        $this->assertSame( 0, \LTMS_Vendor_Followers::count_followers( -1 ) );
    }

    /**
     * is_following con vendor_id inválido o sin follower_id+ip_hash devuelve false.
     */
    public function test_is_following_rejects_invalid_inputs(): void {
        $this->assertFalse( \LTMS_Vendor_Followers::is_following( 0, 5 ) );
        $this->assertFalse( \LTMS_Vendor_Followers::is_following( 123, 0, '' ) );
    }

    /**
     * init() no debe fatal — solo registra hooks (stubs no-op ya	putos).
     */
    public function test_init_runs_without_fatal(): void {
        // add_action es stub no-op en el setup del padre.
        \LTMS_Vendor_Followers::init();
        $this->assertTrue( true, 'init() should not fatal' );
    }

    // =========================================================================
    // RE-AUDITORIA FASE 1.4 (estructurales) — tras completar el fix
    // AUDIT-FE-SF-006: el handler PHP ahora valida contra el nonce global
    // `ltms_plaza_viva` (no el nonce específico `ltms_follow_vendor`) para
    // coincidir con el nonce que PV.ajax envía por defecto. El bloque JS
    // inline fue migrado a ltms-plaza-viva.js. Estos tests validan invariantes
    // estructurales para prevenir regresiones silenciosas.
    // =========================================================================

    /**
     * AUDIT-FE-SF-006 (re-audit): el handler PHP valida contra
     * 'ltms_plaza_viva' (NO contra 'ltms_follow_vendor'). El nonce
     * 'ltms_follow_vendor' nunca llega al server porque PV.ajax envía
     * siempre PV.config.nonce = wp_create_nonce('ltms_plaza_viva').
     */
    public function test_handler_validates_against_plaza_viva_nonce(): void {
        $src = file_get_contents( __DIR__ . '/../../includes/frontend/class-ltms-vendor-followers.php' );
        $this->assertStringContainsString(
            "check_ajax_referer( 'ltms_plaza_viva', 'nonce' )",
            $src,
            'AUDIT-FE-SF-006 fix: handler must validate against ltms_plaza_viva nonce (sent by PV.ajax)'
        );
        $this->assertStringNotContainsString(
            "check_ajax_referer( 'ltms_follow_vendor', 'nonce' )",
            $src,
            'AUDIT-FE-SF-006 regression: handler must NOT validate against ltms_follow_vendor nonce (never sent by PV.ajax)'
        );
    }

    /**
     * AUDIT-FE-SF-006 (re-audit, CSP): vendor-store.php ya NO contiene el
     * bloque JS inline del follow-vendor. La lógica fue migrada a
     * ltms-plaza-viva.js. Esto cierra la excepción CSP de vendor-store.php
     * para el botón de seguir.
     */
    public function test_vendor_store_template_no_inline_follow_js(): void {
        $template = __DIR__ . '/../../includes/frontend/templates/vendor-store.php';
        $this->assertFileExists( $template );
        $src = file_get_contents( $template );

        // El botón HTML sigue existiendo (migración fue del JS, no del HTML).
        $this->assertStringContainsString( 'data-pv-follow-vendor=', $src );

        // El atributo data-pv-follow-nonce fue eliminado del HTML porque el
        // handler JS usa PV.config.nonce (global), no un nonce por-botón.
        $this->assertStringNotContainsString(
            'data-pv-follow-nonce=',
            $src,
            'AUDIT-FE-SF-006: data-pv-follow-nonce must be removed — PV.ajax uses the global nonce'
        );

        // El bloque JS inline del follow (que solo cambiaba el label sin
        // persistir) fue eliminado. Verificamos la ausencia del patrón clave.
        $this->assertStringNotContainsString(
            "var btn = e.target.closest('[data-pv-follow-vendor]')",
            $src,
            'AUDIT-FE-SF-006 fix: vendor-store.php must NOT contain the inline follow JS block'
        );
    }

    /**
     * AUDIT-FE-SF-006 (re-audit): ltms-plaza-viva.js contiene el handler
     * del follow-vendor que invoca el endpoint ltms_follow_vendor vía
     * PV.ajax, con toggle optimista + revert en error.
     */
    public function test_plaza_viva_js_has_follow_handler(): void {
        $js = __DIR__ . '/../../assets/js/ltms-plaza-viva.js';
        $this->assertFileExists( $js );
        $src = file_get_contents( $js );

        // El handler escucha el click en data-pv-follow-vendor.
        $this->assertStringContainsString(
            "e.target.closest('[data-pv-follow-vendor]')",
            $src,
            'AUDIT-FE-SF-006: ltms-plaza-viva.js must delegate click on data-pv-follow-vendor'
        );

        // Invoca el endpoint ltms_follow_vendor vía PV.ajax.
        $this->assertStringContainsString(
            "PV.ajax('ltms_follow_vendor'",
            $src,
            'AUDIT-FE-SF-006: ltms-plaza-viva.js must call PV.ajax with action ltms_follow_vendor'
        );

        // Pasa vendor_id al handler (proveniente del atributo data-pv-follow-vendor).
        $this->assertStringContainsString(
            "vendor_id: vendorId",
            $src,
            'AUDIT-FE-SF-006: ltms-plaza-viva.js must pass vendor_id to the AJAX request'
        );

        // Revierte el toggle visual en caso de error (no engaña al usuario).
        $this->assertStringContainsString(
            'aria-pressed',
            $src,
            'AUDIT-FE-SF-006: ltms-plaza-viva.js must toggle aria-pressed for a11y'
        );
        $this->assertStringContainsString(
            'revertir toggle visual',
            $src,
            'AUDIT-FE-SF-006: ltms-plaza-viva.js must revert visual toggle on error'
        );
    }
}
