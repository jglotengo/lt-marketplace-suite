<?php
/**
 * ProductVendorCardNameTest — tests del endpoint ltms_pv_product_vendor
 * (VENDOR-CARD-NAME FIX).
 *
 * Las tarjetas de catálogo de Elementor/WoodMart (li.product) no llevan el
 * nombre del vendedor server-side y el JS inyectaba el literal "Tienda Lo
 * Tengo" en todas. Este test cubre el endpoint nuevo que resuelve la cadena
 * canónica (ltms_store_name → display_name → user_login):
 *   - devuelve ltms_store_name cuando existe.
 *   - cae a display_name → user_login cuando no hay store_name.
 *   - valida el nonce y rechaza product_id inválido.
 *   - el JS fuente ya NO contiene el literal hardcodeado.
 *
 * @package LTMS\Tests\Unit
 *
 * Ejecutar con: ./vendor/bin/phpunit tests/unit/ProductVendorCardNameTest.php
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;

/**
 * Class ProductVendorCardNameTest
 */
final class ProductVendorCardNameTest extends LTMS_Unit_Test_Case {

	private const PLUGIN_JS = __DIR__ . '/../../assets/js/ltms-plaza-viva.js';
	private const CLASS_FILE = __DIR__ . '/../../includes/frontend/class-ltms-native-templates.php';

	private function require_classes(): void {
		$this->require_class( 'LTMS_Native_Templates' );
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
	 * Prepara stubs para un producto cuyo autor tiene store_name.
	 */
	private function stub_vendor_with_store_name(): void {
		Monkey\Functions\stubs( [
			'check_ajax_referer' => true,
		] );
		Monkey\Functions\when( 'wc_get_product' )->alias(
			static fn() => new class() {
				public function get_id(): int { return 20587; }
			}
		);
		Monkey\Functions\when( 'get_post_field' )->alias(
			static fn( $field, $pid ) => 223 // post_author.
		);
		Monkey\Functions\when( 'get_user_meta' )->alias(
			static fn( $uid, $key = '', $single = false ) => ( 'ltms_store_name' === $key ) ? 'Kosmetic' : ''
		);
		Monkey\Functions\when( 'get_userdata' )->alias(
			static fn( $uid ) => (object) [ 'display_name' => 'Erick Leon', 'user_login' => 'erickleon' ]
		);
		// home_url está definido en bootstrap.php — no se puede re-stubear.
		// El endpoint lo llama con '/vendor/{id}/' y lo pasa por apply_filters;
		// stubeamos apply_filters para forzar la URL canónica esperada.
		Monkey\Functions\when( 'apply_filters' )->alias(
			static fn( $hook, $default ) => $default
		);
		Monkey\Functions\when( 'esc_url_raw' )->alias(
			static fn( $url ) => $url
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Endpoint ltms_pv_product_vendor
	// ─────────────────────────────────────────────────────────────────────────

	public function test_ajax_product_vendor_returns_store_name(): void {
		$this->require_classes();
		$this->stub_vendor_with_store_name();
		$_POST['product_id'] = '20587';

		$response = $this->capture_json_success(
			static fn() => \LTMS_Native_Templates::ajax_product_vendor()
		);

		$this->assertSame( 'Kosmetic', $response['vendor_name'], 'Debe devolver ltms_store_name del autor.' );
		$this->assertSame( 223, $response['vendor_id'] );
		$this->assertStringContainsString( '/vendor/223/', $response['vendor_url'] );
	}

	public function test_ajax_product_vendor_falls_back_to_display_name(): void {
		$this->require_classes();
		$this->stub_vendor_with_store_name();
		// Sin ltms_store_name → display_name.
		Monkey\Functions\when( 'get_user_meta' )->alias(
			static fn( $uid, $key = '', $single = false ) => ''
		);

		$_POST['product_id'] = '20588';

		$response = $this->capture_json_success(
			static fn() => \LTMS_Native_Templates::ajax_product_vendor()
		);

		$this->assertSame( 'Erick Leon', $response['vendor_name'], 'Debe caer a display_name sin store_name.' );
	}

	public function test_ajax_product_vendor_invalid_product_id_errors(): void {
		$this->require_classes();
		Monkey\Functions\stubs( [ 'check_ajax_referer' => true ] );
		Monkey\Functions\when( 'wc_get_product' )->justReturn( null );

		$_POST['product_id'] = '99999';

		$err = $this->capture_json_error(
			static fn() => \LTMS_Native_Templates::ajax_product_vendor()
		);

		$this->assertIsArray( $err['data'] );
		$this->assertStringContainsString(
			'Product not found',
			(string) ( $err['data']['message'] ?? '' )
		);
	}

	public function test_hook_registered(): void {
		$this->assertFileExists( self::CLASS_FILE );
		$source = file_get_contents( self::CLASS_FILE );

		$this->assertStringContainsString(
			"wp_ajax_nopriv_ltms_pv_product_vendor",
			$source,
			'El endpoint público del vendor name debe estar registrado.'
		);
		$this->assertStringContainsString(
			'function ajax_product_vendor',
			$source,
			'El handler ajax_product_vendor debe existir.'
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// JS fuente: el literal "Tienda Lo Tengo" no debe seguir en las cards
	// ─────────────────────────────────────────────────────────────────────────

	public function test_js_source_does_not_hardcode_tienda_lo_tengo(): void {
		$this->assertFileExists( self::PLUGIN_JS );
		$source = file_get_contents( self::PLUGIN_JS );

		// El bloque enhanceElementorCards debe consultar el endpoint real.
		$this->assertStringContainsString(
			'ltms_pv_product_vendor',
			$source,
			'El JS debe consultar el endpoint ltms_pv_product_vendor para el nombre del vendedor.'
		);
		$this->assertStringNotContainsString(
			"'Tienda Lo Tengo'",
			$source,
			'El JS no debe hardcodear "Tienda Lo Tengo" como nombre de vendedor en las cards.'
		);
		$this->assertStringNotContainsString(
			'"Tienda Lo Tengo"',
			$source,
			'El JS no debe hardcodear "Tienda Lo Tengo" (doble comilla) en las cards.'
		);
	}
}