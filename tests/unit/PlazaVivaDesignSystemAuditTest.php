<?php
/**
 * Tests estructurales del design system Plaza Viva (Ciclo 1 de auditoría).
 *
 * Cubre hallazgos P0 de la auditoría del sistema de diseño compartido
 * (assets/css/ltms-plaza-viva.css + assets/js/ltms-plaza-viva.js + los 8
 * templates públicos + content-product.php). Los fixes son CSS-level, por
 * lo que las invariantes son estructurales sobre el source:
 *
 *   * AUDIT-FE-PV-DS-001 (P0, badges sin reglas CSS): content-product.php
 *     referencia .pv-product-card__discount--soft ("Oferta", :190) y
 *     --muted ("Agotado", :197) pero ltms-plaza-viva.css no definía reglas
 *     para esos modificadores → ambos badges heredaban el rojo --danger
 *     del badge de descuento (-X%), mostrando "Agotado" como si fuera una
 *     alerta de oferta agresiva. Fix: reglas --soft (dorado var(--gold))
 *     y --muted (neutral var(--bg-2)/var(--text-2)) después de la base.
 *
 * Estos tests son PURAMENTE estructurales (file_get_contents + asserts
 * sobre el source CSS/PHP): NO cargan clases del plugin ni invocan WP →
 * deterministas en LTMS_UNIT_ONLY=true y CI Ubuntu (mismo patrón que
 * OrderTrackingAuditTest).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class PlazaVivaDesignSystemAuditTest
 *
 * Verifica los fixes AUDIT-FE-PV-DS-001+ sobre el design system
 * ltms-plaza-viva.css mediante invariantes estructurales del source.
 * Detecta regresiones si alguien elimina los modificadores de badge o
 * desincroniza el .min.css.
 */
final class PlazaVivaDesignSystemAuditTest extends LTMS_Unit_Test_Case {

	/**
	 * Ruta absoluta al design system CSS.
	 */
	private string $css_path;

	/**
	 * Ruta absoluta al design system CSS minificado.
	 */
	private string $css_min_path;

	/**
	 * Ruta absoluta al template part content-product.php.
	 */
	private string $card_part_path;

	/**
	 * @inheritDoc
	 *
	 * NOTA INTENCIONAL: este test NO llama $this->require_class(). Los
	 * tests son puramente de filesystem (file_get_contents + asserts),
	 * por lo que NO dependen del classmap estático de Composer. Esto
	 * los hace deterministas tanto en LTMS_UNIT_ONLY=true como en CI
	 * Ubuntu (mismo patrón que OrderTrackingAuditTest — ver su setUp).
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->css_path       = dirname( __DIR__, 2 ) . '/assets/css/ltms-plaza-viva.css';
		$this->css_min_path   = dirname( __DIR__, 2 ) . '/assets/css/ltms-plaza-viva.min.css';
		$this->card_part_path = dirname( __DIR__, 2 ) . '/includes/frontend/templates/wc-parts/content-product.php';
	}

	/**
	 * AUDIT-FE-PV-DS-001 (P0, badges sin reglas CSS): el HTML de
	 * content-product.php emite dos modificadores del badge de descuento
	 * que no tenían reglas en el design system — caían al look del badge
	 * base (-X% rojo --danger):
	 *
	 *   * --soft  → "Oferta" (on_sale sin % calculable) debía verse como
	 *               aviso suave dorado, no rojo agresivo.
	 *   * --muted → "Agotado" (out of stock) debía verse neutral/apagado,
	 *               no como alarma roja.
	 */
	public function test_001_badges_soft_y_muted_definidos_en_design_system(): void {
		$this->assertFileExists( $this->css_path );
		$css = file_get_contents( $this->css_path );
		$this->assertFileExists( $this->card_part_path );
		$part = file_get_contents( $this->card_part_path );

		// (1) El HTML referencia ambos modificadores (contrato render ↔ CSS).
		$this->assertStringContainsString(
			'pv-product-card__discount--soft',
			$part,
			'AUDIT-FE-PV-DS-001: content-product.php debe seguir emitiendo el badge --soft (Oferta)'
		);
		$this->assertStringContainsString(
			'pv-product-card__discount--muted',
			$part,
			'AUDIT-FE-PV-DS-001: content-product.php debe seguir emitiendo el badge --muted (Agotado)'
		);

		// (2) El CSS define ambas reglas (el fix).
		$this->assertStringContainsString(
			'.pv-product-card__discount--soft{',
			$css,
			'AUDIT-FE-PV-DS-001 fix: falta la regla .pv-product-card__discount--soft en ltms-plaza-viva.css'
		);
		$this->assertStringContainsString(
			'.pv-product-card__discount--muted{',
			$css,
			'AUDIT-FE-PV-DS-001 fix: falta la regla .pv-product-card__discount--muted en ltms-plaza-viva.css'
		);

		// (3) El fix usa los tokens del sistema (no hex sueltos): soft con
		// fondo dorado var(--gold), muted con neutrals var(--bg-2) y borde.
		$this->assertMatchesRegularExpression(
			'/\.pv-product-card__discount--soft\{[^}]*var\(--gold\)[^}]*\}/',
			$css,
			'AUDIT-FE-PV-DS-001: --soft debe usar el token dorado var(--gold) del sistema'
		);
		$this->assertMatchesRegularExpression(
			'/\.pv-product-card__discount--muted\{[^}]*var\(--bg-2\)[^}]*var\(--border\)[^}]*\}/',
			$css,
			'AUDIT-FE-PV-DS-001: --muted debe usar tokens neutrales var(--bg-2) + borde var(--border)'
		);

		// (4) Traza del fix para auditorías futuras.
		$this->assertStringContainsString(
			'AUDIT-FE-PV-DS-001',
			$css,
			'AUDIT-FE-PV-DS-001: ltms-plaza-viva.css must contain the traceable fix marker comment for future audits'
		);
	}

	/**
	 * AUDIT-FE-PV-DS-001 (sincronización .min.css): ltms-plaza-viva.min.css
	 * se genera con clean-css (`npm run build:css`). Si alguien edita el
	 * source y no regenera el min, producción pierde las reglas nuevas.
	 */
	public function test_002_min_css_sincronizado_con_badges(): void {
		$this->assertFileExists( $this->css_min_path );
		$min = file_get_contents( $this->css_min_path );

		$this->assertStringContainsString(
			'.pv-product-card__discount--soft',
			$min,
			'AUDIT-FE-PV-DS-001: ltms-plaza-viva.min.css desactualizado — regenerar con npm run build:css (falta --soft)'
		);
		$this->assertStringContainsString(
			'.pv-product-card__discount--muted',
			$min,
			'AUDIT-FE-PV-DS-001: ltms-plaza-viva.min.css desactualizado — regenerar con npm run build:css (falta --muted)'
		);
	}
}
