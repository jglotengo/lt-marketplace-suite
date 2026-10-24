<?php
/**
 * WcUncropThumbsTest — PROD-IMG-FIT-2026-10-24 (fase 2, file-level).
 *
 * Decisión del operador: ejecutar el cambio a nivel de archivo además del
 * CSS. woocommerce_thumbnail se generaba recortado 300x300 (WC default
 * '1:1'); el script deploy/ltms-wc-uncrop-thumbs-2026-10-24.php pone el
 * recorte en 'uncropped' (archivos proporcionales 300w) y la regeneración
 * corre vía wp-cli con --image_size=woocommerce_thumbnail.
 *
 * @package LTMS\Tests\Unit
 * @group wc-uncrop-thumbs
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class WcUncropThumbsTest
 *
 * @group wc-uncrop-thumbs
 */
final class WcUncropThumbsTest extends LTMS_Unit_Test_Case {

	private string $script_path;
	private string $webhook_path;

	protected function setUp(): void {
		parent::setUp();
		$this->script_path = dirname( __DIR__, 2 ) . '/deploy/ltms-wc-uncrop-thumbs-2026-10-24.php';
		$this->webhook_path = dirname( __DIR__, 2 ) . '/deploy/ltms-deploy-webhook.php';
	}

	/**
	 * UNCROP-A: el script pone el recorte de WC en 'uncropped' (idempotente)
	 * y limpia el object cache para que wc_get_image_size releea la opción.
	 */
	public function test_001_script_uncropped_e_idempotente(): void {
		$this->assertFileExists( $this->script_path );
		$src = (string) file_get_contents( $this->script_path );

		$this->assertStringContainsString(
			"update_option( 'woocommerce_thumbnail_cropping', 'uncropped' );",
			$src,
			'UNCROP-A: el script debe fijar woocommerce_thumbnail_cropping=uncropped (archivos proporcionales 300w, crop=0).'
		);
		$this->assertStringContainsString( 'wp_cache_flush();', $src, 'UNCROP-A: debe limpiar el object cache (WC cachea wc_get_image_size).' );
		$this->assertStringContainsString(
			'--image_size=woocommerce_thumbnail',
			$src,
			'UNCROP-A: el script documenta el comando de regeneración wp-cli (solo el tamaño woocommerce_thumbnail).'
		);
		// No cambia el ancho configurado (se conserva el 300 del sitio).
		$this->assertStringNotContainsString(
			"update_option( 'woocommerce_thumbnail_image_width'",
			$src,
			'UNCROP-A: el script NO debe cambiar el ancho de miniatura del sitio.'
		);
		// Trazabilidad.
		$this->assertStringContainsString( 'PROD-IMG-FIT-2026-10-24', $src, 'UNCROP-A: tag de trazabilidad.' );
	}

	/**
	 * UNCROP-B: whitelist del deploy webhook (lección #177).
	 */
	public function test_002_whitelist_webhook(): void {
		$webhook = (string) file_get_contents( $this->webhook_path );

		$this->assertStringContainsString(
			"'deploy/ltms-wc-uncrop-thumbs-2026-10-24.php',",
			$webhook,
			'UNCROP-B: el script debe estar en la whitelist del webhook.'
		);
		$this->assertStringContainsString(
			"'tests/unit/WcUncropThumbsTest.php',",
			$webhook,
			'UNCROP-B: este test debe estar en la whitelist del webhook (lección #177).'
		);
	}
}
