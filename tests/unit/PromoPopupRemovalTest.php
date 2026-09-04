<?php
/**
 * PromoPopupRemovalTest - REMOVE-PROMO-POPUP-001 FIX (2026-09-04).
 *
 * A petición del negocio se eliminaron de TODAS las páginas públicas:
 *  1. Banner de bienvenida "10% off primera compra" (BIENVENIDO10), que se
 *     inyectaba en wp_footer desde class-ltms-branding-engine.php
 *     (render_welcome_discount_banner, hook pri 5).
 *  2. Toasts de social proof "X compró Y" (el usuario reportaba ver
 *     "Abrelatas de Acero Inoxidable..." al cargar), que se inyectaban desde
 *     class-ltms-sales-booster.php (render_social_proof_container +
 *     ajax_get_social_proof). El CSS de v2.9.278 intentó ocultarlos pero sus
 *     selectores (.ltms-social-proof-container, clase) no matcheaban el
 *     markup real (#ltms-social-proof-container, ID; .ltms-toast excluido).
 *
 * Tests source-based (patrón C20-C29) sobre los dos archivos.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types = 1 );

namespace LTMS\Tests\Unit;

class PromoPopupRemovalTest extends LTMS_Unit_Test_Case {

	private const BRANDING_ENGINE_PATH = __DIR__ . '/../../includes/frontend/class-ltms-branding-engine.php';
	private const SALES_BOOSTER_PATH   = __DIR__ . '/../../includes/business/class-ltms-sales-booster.php';

	public function test_branding_engine_file_exists(): void {
		$this->assertFileExists( self::BRANDING_ENGINE_PATH );
	}

	public function test_welcome_banner_hook_removed(): void {
		$source = file_get_contents( self::BRANDING_ENGINE_PATH );

		$this->assertStringNotContainsString(
			"add_action( 'wp_footer', [ __CLASS__, 'render_welcome_discount_banner' ], 5 )",
			$source,
			'El hook wp_footer del banner de bienvenida debe haber sido removido.'
		);
	}

	public function test_welcome_banner_method_removed(): void {
		$source = file_get_contents( self::BRANDING_ENGINE_PATH );

		$this->assertStringNotContainsString(
			'public static function render_welcome_discount_banner(): void {',
			$source,
			'El método render_welcome_discount_banner debe haber sido eliminado.'
		);
	}

	public function test_welcome_banner_markup_and_code_removed(): void {
		$source = file_get_contents( self::BRANDING_ENGINE_PATH );

		// Firmas de código del banner que no deben existir (los comentarios
		// pueden nombrar la feature para trazabilidad, el código no).
		$this->assertStringNotContainsString( 'id="ltms-welcome-banner"', $source );
		$this->assertStringNotContainsString( 'id="ltms-welcome-close"', $source );
		$this->assertStringNotContainsString( 'BIENVENIDO10', $source );
		$this->assertStringNotContainsString( 'ltms_welcome_shown', $source );
	}

	public function test_tag_present_for_traceability(): void {
		$branding = file_get_contents( self::BRANDING_ENGINE_PATH );
		$booster  = file_get_contents( self::SALES_BOOSTER_PATH );

		$this->assertStringContainsString( 'REMOVE-PROMO-POPUP-001 FIX', $branding );
		$this->assertStringContainsString( 'REMOVE-PROMO-POPUP-001 FIX', $booster );
	}

	public function test_social_proof_ajax_endpoint_removed(): void {
		$source = file_get_contents( self::SALES_BOOSTER_PATH );

		// Firmas de código del toast de social proof que no deben existir
		// (los comentarios pueden nombrar la feature para trazabilidad).
		$this->assertStringNotContainsString( "add_action( 'wp_ajax_ltms_get_social_proof'", $source );
		$this->assertStringNotContainsString( "add_action( 'wp_ajax_nopriv_ltms_get_social_proof'", $source );
		$this->assertStringNotContainsString( 'public static function ajax_get_social_proof(): void {', $source );
		$this->assertStringNotContainsString( 'public static function record_purchase_for_social_proof( int $order_id ): void {', $source );
		$this->assertStringNotContainsString( 'id="ltms-social-proof-container"', $source );
	}

	public function test_viewer_count_preserved_as_independent_feature(): void {
		$source = file_get_contents( self::SALES_BOOSTER_PATH );

		// El viewer count (PDP) NO se tocó: método, hook y handler intactos.
		$this->assertStringContainsString( 'public static function render_viewer_count(): void {', $source );
		$this->assertStringContainsString( "add_action( 'wp_footer', [ __CLASS__, 'render_viewer_count' ], 25 )", $source );
		$this->assertStringContainsString( 'ltms_track_product_view', $source );
		$this->assertStringContainsString( 'ajax_track_product_view', $source );
	}

	public function test_init_method_preserved_and_public(): void {
		$this->require_class( '\LTMS_Sales_Booster' );

		// REGRESION P0 (2026-09-04): un docblock sin cerrar (/** sin */) en el
		// reemplazo de las constantes de social proof dejaba a init() dentro de
		// un comentario -> "Call to undefined method LTMS_Sales_Booster::init()"
		// en class-ltms-kernel.php:408, el boot abortaba en boot_business_logic y
		// boot_frontend() nunca corria: sin shortcodes de login/registro de
		// vendedores, sin endpoints AJAX (VTEX -> "error de Red"), etc.
		$this->assertTrue(
			method_exists( '\LTMS_Sales_Booster', 'init' ),
			'init() debe ser un método existente e invocable de LTMS_Sales_Booster.'
		);
		$ref = new \ReflectionMethod( '\LTMS_Sales_Booster', 'init' );
		$this->assertTrue( $ref->isPublic(), 'init() debe ser public.' );
		$this->assertTrue( $ref->isStatic(), 'init() debe ser static.' );
	}

	public function test_no_unclosed_docblock_in_sales_booster(): void {
		$source = file_get_contents( self::SALES_BOOSTER_PATH );

		// Anti-regresion: no debe existir un "/**" sin su "*/" correspondiente
		// ANTES de la declaracion de init(). Contamos docblocks abiertos y
		// cerrados en la primera mitad del archivo y exigen equilibrio.
		$before_init = substr( $source, 0, strpos( $source, 'public static function init(): void {' ) );
		$opens  = preg_match_all( '#/\*\*#', $before_init );
		$closes = preg_match_all( '#\*/#', $before_init );
		$this->assertSame( $opens, $closes, 'Todo /** antes de init() debe tener su */. Docblocks abiertos=' . $opens . ' cerrados=' . $closes );
	}
}