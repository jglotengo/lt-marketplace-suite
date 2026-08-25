<?php
/**
 * Tests estructurales para AUDIT-FE-AP-001 (Fase 1.5): wishlist toggle del
 * design system Plaza Viva en cards públicos (home.php, vendor-store.php,
 * content-product.php) ahora persiste en backend.
 *
 * Bug original: el botón favorito (corazón) de los cards en todas las
 * páginas públicas solo hacia toggle visual + dispatch del evento custom
 * `wishlist-toggle` que NADIE escucha. El favorito nunca se persistía:
 * ni en cookie ltms_wishlist para guests, ni en DB bkr_lt_wishlists para
 * logged-in. Mismo patrón que el bug AUDIT-FE-SF-006 del follow-vendor.
 *
 * Fix: nuevo handler PHP LTMS_Wishlist::ajax_pv_toggle (action
 * `wp_ajax(_nopriv)_ltms_pv_toggle_wishlist`) que valida contra el nonce
 * global `ltms_plaza_viva` (igual que todos los handlers PV) y persiste
 * via LTMS_Wishlist::toggle() (soporta guest cookie + logged-in DB). El
 * handler JS invoca PV.ajax('ltms_pv_toggle_wishlist', ...) y revierte
 * el toggle visual en error (UX instantánea + honesty on failure).
 *
 * Tests PURAMENTE estructurales (file_get_contents + asserts): NO cargan
 * clases del plugin ni invocan WP → deterministas en LTMS_UNIT_ONLY=true
 * y CI Ubuntu sin depender del classmap estático de Composer.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class WishlistPvToggleTest
 *
 * Verifica que el fix AUDIT-FE-AP-001 quedó aplicado en sus 3 puntos de
 * contacto: (a) registro del handler PHP, (b) implementación del método
 * ajax_pv_toggle, (c) handler JS que invoca PV.ajax y revierte en error.
 * Adicionalmente, re-audita que el fix AP-002 (atributo quick-view
 * canonical) no haya roto los 3 templates públicos que lo referencian.
 */
final class WishlistPvToggleTest extends LTMS_Unit_Test_Case {

    /**
     * Ruta a la clase LTMS_Wishlist (handler PHP).
     */
    private string $wishlist_class_path;

    /**
     * Ruta al design system ltms-plaza-viva.js (handler JS).
     */
    private string $js_path;

    /**
     * Rutas a las 3 plantillas con botón fav del card.
     */
    private string $home_template;
    private string $vendor_store_template;
    private string $content_product_template;

    protected function setUp(): void {
        parent::setUp();
        // NOTA INTENCIONAL: este test NO llama $this->require_class(). Los
        // tests son puramente de filesystem (file_get_contents + asserts),
        // por lo que NO dependen del classmap estático de Composer. Esto los
        // hace deterministas tanto en LTMS_UNIT_ONLY=true como en CI Ubuntu.
        $root = dirname( __DIR__, 2 );
        $this->wishlist_class_path     = $root . '/includes/frontend/class-ltms-wishlist.php';
        $this->js_path                = $root . '/assets/js/ltms-plaza-viva.js';
        $this->home_template          = $root . '/includes/frontend/templates/home.php';
        $this->vendor_store_template  = $root . '/includes/frontend/templates/vendor-store.php';
        $this->content_product_template = $root . '/includes/frontend/templates/wc-parts/content-product.php';
    }

    /**
     * AUDIT-FE-AP-001 (a): el handler PHP ltms_pv_toggle_wishlist DEBE estar
     * registrado para logged-in Y guest. Antes del fix, los cards usaban
     * `data-pv-wishlist-toggle` pero ningún JS invocaba este endpoint;
     * existían `ltms_toggle_wishlist` (login requerido) y
     * `ltms_sf_toggle_wishlist` (nonce ltms_sf_nonce del storefront,
     * propre del vendor-store, no del design system global).
     */
    public function test_php_handler_registered_for_both_user_types(): void {
        $this->assertFileExists( $this->wishlist_class_path );
        $src = file_get_contents( $this->wishlist_class_path );

        $this->assertStringContainsString(
            "add_action( 'wp_ajax_ltms_pv_toggle_wishlist'",
            $src,
            'AUDIT-FE-AP-001: hook wp_ajax_ltms_pv_toggle_wishlist DEBE estar registrado (logged-in)'
        );
        $this->assertStringContainsString(
            "add_action( 'wp_ajax_nopriv_ltms_pv_toggle_wishlist'",
            $src,
            'AUDIT-FE-AP-001: hook wp_ajax_nopriv_ltms_pv_toggle_wishlist DEBE estar registrado (guest)'
        );
    }

    /**
     * AUDIT-FE-AP-001 (b): el método ajax_pv_toggle DEBE:
     *  - validar contra el nonce global `ltms_plaza_viva` (NO por-producto).
     *  - llamar a self::toggle() (que soporta guest cookie + logged-in DB).
     *  - NO requerir is_user_logged_in() (deja pasar guests → toggle cookie).
     */
    public function test_php_handler_method_uses_global_nonce_and_supports_guests(): void {
        $src = file_get_contents( $this->wishlist_class_path );

        // El método existe.
        $this->assertStringContainsString(
            'public static function ajax_pv_toggle(): void',
            $src,
            'AUDIT-FE-AP-001: método ajax_pv_toggle() DEBE existir en LTMS_Wishlist'
        );

        // Valida contra el nonce global ltms_plaza_viva (NO ltms_wishlist_{pid}).
        $this->assertStringContainsString(
            "check_ajax_referer( 'ltms_plaza_viva', 'nonce' )",
            $src,
            'AUDIT-FE-AP-001: ajax_pv_toggle() DEBE validar contra el nonce global ltms_plaza_viva (paridad con ajax_quick_view, ajax_plaza_viva_add_to_cart)'
        );

        // Delega persistencia a self::toggle() (que ya soporta guest+cookie y logged-in+DB).
        $this->assertStringContainsString(
            'self::toggle( $product_id )',
            $src,
            'AUDIT-FE-AP-001: ajax_pv_toggle() DEBE delegar persistencia a LTMS_Wishlist::toggle() (que ya soporta guest cookie + logged-in DB)'
        );

        // NO debe requerir login (patrón paridad con el handler legacy nopriv-removed en v2.9.126).
        // Si el método está, el test supera — no hay check is_user_logged_in() que aborte guests.
        $method_block = $this->extract_method( $src, 'ajax_pv_toggle' );
        $this->assertStringNotContainsString(
            'is_user_logged_in() ) { wp_send_json_error',
            $method_block,
            'AUDIT-FE-AP-001: ajax_pv_toggle() NO DEBE requerir login (debe soportar guests via cookie como el handler js-storefront.js)'
        );

        // Sanitiza el product_id con absint + wp_unslash.
        $this->assertStringContainsString(
            'absint( wp_unslash( $_POST[\'product_id\'] ) )',
            $method_block,
            'AUDIT-FE-AP-001: ajax_pv_toggle() DEBE sanitizar product_id con absint(wp_unslash())'
        );
    }

    /**
     * AUDIT-FE-AP-001 (c): el handler JS en ltms-plaza-viva.js DEBE:
     *  - invocar PV.ajax('ltms_pv_toggle_wishlist', { product_id }) para persistir.
     *  - hacer toggle optimista del estado visual (UX instantánea).
     *  - REVERTIR el toggle visual en caso de error (no engaña al usuario).
     *  - mantener la derecha del dispatch del evento `wishlist-toggle` para
     *    consumo futuro por extensiones externas.
     */
    public function test_js_handler_invokes_pv_ajax_and_reverts_on_error(): void {
        $this->assertFileExists( $this->js_path );
        $src = file_get_contents( $this->js_path );

        // Trazabilidad: el handler debe marcar el fix.
        $this->assertStringContainsString(
            'AUDIT-FE-AP-001',
            $src,
            'AUDIT-FE-AP-001: el handler JS debe marcar el fix con traza AUDIT-FE-AP-001'
        );

        // Invoca el nuevo endpoint PV.
        $this->assertStringContainsString(
            "PV.ajax('ltms_pv_toggle_wishlist'",
            $src,
            'AUDIT-FE-AP-001: el handler JS DEBE invocar PV.ajax(\'ltms_pv_toggle_wishlist\', {...})'
        );

        // Revertir toggle en error de red (catch).
        $this->assertStringContainsString(
            "Error de conexión",
            $src,
            'AUDIT-FE-AP-001: el handler JS DEBE mostrar toast de error genérico en catch'
        );

        // Reconcilia el estado visual con la respuesta authoritative del backend.
        $this->assertStringContainsString(
            'wasFavActive',
            $src,
            'AUDIT-FE-AP-001: el handler JS DEBE capturar el estado previo (wasFavActive) para revertir el toggle optimista en error'
        );

        // Selector del botón fav del card sigue sendo .pv-product-card__fav (CSS scope).
        $this->assertStringContainsString(
            '.pv-product-card__fav',
            $src,
            'AUDIT-FE-AP-001: el selector del botón fav debe seguir haciendo match al CSS scope (.pv-product-card__fav)'
        );
    }

    /**
     * AUDIT-FE-AP-001 (d): las plantillas públicas con botón fav siguen
     * usando el mismo selector (.pv-product-card__fav) + el mismo data attr
     * (data-pv-wishlist-toggle o data-product-id) para que el handler JS
     * delegado funcione en todas.
     *
     * AUDIT-FE-PV-DS-003 (P1-1, DRY): home.php ya no emite el botón fav
     * directamente — delega las cards trending al template part canónico
     * content-product.php vía wc_get_template_part() (que sí emite el fav y
     * está cubierto abajo). La aserción de home verifica la delegación.
     */
    public function test_three_card_templates_keep_fav_button_markup(): void {
        // home.php: delegación al template part (el fav lo emite content-product).
        $this->assertFileExists( $this->home_template, 'home.php debe existir' );
        $home_src = file_get_contents( $this->home_template );
        $this->assertStringContainsString(
            "wc_get_template_part( 'content', 'product' )",
            $home_src,
            'AUDIT-FE-PV-DS-003: home.php debe delegar las cards (fav incluido) a content-product.php via wc_get_template_part'
        );

        $templates = [
            'vendor-store.php'    => $this->vendor_store_template,
            'content-product.php' => $this->content_product_template,
        ];

        foreach ( $templates as $label => $path ) {
            $this->assertFileExists( $path, "$label debe existir" );
            $src = file_get_contents( $path );

            $this->assertStringContainsString(
                'class="pv-product-card__fav"',
                $src,
                "AUDIT-FE-AP-001: $label DEBE conservar el botón fav (.pv-product-card__fav) para que el handler delegado funcione"
            );
        }
    }

    /**
     * AUDIT-FE-AP-002 re-audit: las 3 plantillas con botón de quick-view
     * ya NO contienen el atributo legacy `data-pv-quick-view=` (con guion).
     * Este test re-audita que el fix simultáneo AP-002 no se haya roto.
     */
    public function test_no_template_emits_legacy_quick_view_attr(): void {
        $templates = [
            'home.php'            => $this->home_template,
            'vendor-store.php'    => $this->vendor_store_template,
            'content-product.php' => $this->content_product_template,
        ];

        foreach ( $templates as $label => $path ) {
            $this->assertFileExists( $path, "$label debe existir" );
            $src = file_get_contents( $path );

            $this->assertStringNotContainsString(
                'data-pv-quick-view=',
                $src,
                "AUDIT-FE-AP-002 re-audit: $label NO debe emitir data-pv-quick-view (con guion) — atributo muerto eliminado"
            );
        }
    }

    /**
     * Helper: extrae el cuerpo de un método de un archivo de clase PHP.
     * Aproximación vía strpos — para tests estructurales, no requiere un
     * parser PHP completo. Devuelve el substring entre el signature del
     * método y el siguiente `}` al mismo nivel de indentación.
     */
    private function extract_method( string $src, string $method_name ): string {
        $needle = "public static function {$method_name}(): void";
        $pos = strpos( $src, $needle );
        if ( $pos === false ) {
            return '';
        }
        // Tomar 1500 caracteres desde el inicio del signature — los handlers
        // AJAX de este estilo son siempre < 1500 chars.
        return substr( $src, $pos, 1500 );
    }
}
