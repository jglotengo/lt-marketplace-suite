<?php
/**
 * PanelAuditFixTest — tests de los fixes del ciclo AUDIT-PANEL (re-auditoría panel vendedor).
 *
 * Cubre los 4 fixes P0/P1 aplicados en el ciclo:
 *
 * FN-03 — Migrar los 3 últimos inline <script> del panel a JS externo
 *         (view-redi, view-incidents, view-settings invoicing). Completa la migración
 *         FASE2B P0 FIX (CSP) que había dejado estas 3 vistas como excepción.
 *
 * FN-09 — Vista Analytics rota: el tab "Analytics" del nav no tenía
 *         loadAnalyticsView() y loadSalesChart() buscaba el canvas del home
 *         (ltms-vendor-sales-chart) — el canvas ltms-vendor-analytics-chart quedaba
 *         en blanco para siempre. Adicionalmente, había un <div class="ltms-view-section">
 *         nested dentro del <div id="ltms-view-analytics"> que rompía el selector global
 *         $('.ltms-view-section').hide() de loadView().
 *
 * FN-07 — Búsqueda de pedidos desconectada: el estado `ordersState.search` existía
 *         y se enviaba al server, PERO ningún handler 'input' poblaba el campo
 *         #ltms-order-search. Escribir en el input no disparaba ninguna query AJAX.
 *
 * FN-10 — Selector `$('.ltms-view-section').hide()` global sin scope — afectaba
 *         cualquier markup externo al dashboard (theme, otro plugin) que reusara
 *         la clase. Scopado a `#ltms-dashboard-container .ltms-view-section`.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use ReflectionClass;

/**
 * Class PanelAuditFixTest
 *
 * Tests unitarios para los fixes del ciclo AUDIT-PANEL (re-auditoría panel vendedor).
 * Ejecutar con: ./vendor/bin/phpunit --group audit-panel
 *
 * @group audit-panel
 */
class PanelAuditFixTest extends LTMS_Unit_Test_Case {

	private function views_path( string $relative ): string {
		return dirname( __DIR__, 2 ) . '/includes/frontend/views/' . $relative;
	}

	private function js_path( string $relative ): string {
		return dirname( __DIR__, 2 ) . '/assets/js/' . $relative;
	}

	private function assert_file_contains( string $file, string $needle, string $msg = '' ): void {
		$this->assertFileExists( $file, "Archivo requerido no encontrado: {$file}" );
		$content = file_get_contents( $file );
		$this->assertStringContainsString( $needle, $content, $msg ?: "Expected '{$needle}' in {$file}" );
	}

	private function assert_file_not_contains( string $file, string $needle, string $msg = '' ): void {
		$this->assertFileExists( $file, "Archivo requerido no encontrado: {$file}" );
		$content = file_get_contents( $file );
		$this->assertStringNotContainsString( $needle, $content, $msg ?: "Did NOT expect '{$needle}' in {$file}" );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// AUDIT-PANEL-FN-03 — Inline <script> eliminados de 3 vistas.
	// Verificamos que NINGÚN <script> inline quede en redi, incidents y en
	// el bloque invoicing de settings.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_fn03_view_redi_no_inline_script(): void {
		$file = $this->views_path( 'view-redi.php' );
		$this->assert_file_not_contains( $file, '<script type="text/javascript">',
			'view-redi.php NO debe contener <script type="text/javascript"> inline (AUDIT-PANEL-FN-03).' );
		$this->assert_file_contains( $file, "// AUDIT-PANEL-FN-03 (re-auditoría): inline <script> moved to external assets/js/ltms-redi.js",
			'view-redi.php debe tener el comentario AUDIT-PANEL-FN-03 + referencia al JS externo.' );
		$this->assert_file_contains( $file, "wp_enqueue_script( 'ltms-redi'",
			'view-redi.php debe hacer wp_enqueue_script del handle ltms-redi.' );
		$this->assert_file_contains( $file, "wp_localize_script( 'ltms-redi', 'ltmsRedi'",
			'view-redi.php debe hacer wp_localize_script del handle ltms-redi.' );
	}

	public function test_fn03_view_incidents_no_inline_script(): void {
		$file = $this->views_path( 'view-incidents.php' );
		$this->assert_file_not_contains( $file, '<script type="text/javascript">',
			'view-incidents.php NO debe contener <script type="text/javascript"> inline (AUDIT-PANEL-FN-03).' );
		$this->assert_file_contains( $file, "// AUDIT-PANEL-FN-03 (re-auditoría): inline <script> moved to external assets/js/ltms-incidents.js",
			'view-incidents.php debe tener el comentario AUDIT-PANEL-FN-03 + referencia al JS externo.' );
		$this->assert_file_contains( $file, "wp_enqueue_script( 'ltms-incidents'",
			'view-incidents.php debe hacer wp_enqueue_script del handle ltms-incidents.' );
		$this->assert_file_contains( $file, "wp_localize_script( 'ltms-incidents', 'ltmsIncidents'",
			'view-incidents.php debe hacer wp_localize_script del handle ltms-incidents.' );
	}

	public function test_fn03_view_settings_no_inline_invoicing_script(): void {
		$file = $this->views_path( 'view-settings.php' );
		// Verifica que el bloque invoicing inline fue removido. La marca era
		// "<script>\n// v2.9.222: vendor invoicing settings UI (inline"
		$this->assert_file_not_contains( $file, "<script>\n// v2.9.222: vendor invoicing settings UI",
			'view-settings.php NO debe contener el bloque <script> inline de invoicing (línea 508 previa). AUDIT-PANEL-FN-03.' );
		$this->assert_file_contains( $file, "// AUDIT-PANEL-FN-03 (re-auditoría): vendor invoicing settings",
			'view-settings.php debe tener el comentario AUDIT-PANEL-FN-03 explicando la migración.' );
	}

	public function test_fn03_external_js_files_exist(): void {
		$this->assertFileExists( $this->js_path( 'ltms-redi.js' ),
			'assets/js/ltms-redi.js debe existir (migrado desde view-redi.php inline).' );
		$this->assertFileExists( $this->js_path( 'ltms-incidents.js' ),
			'assets/js/ltms-incidents.js debe existir (migrado desde view-incidents.php inline).' );
	}

	public function test_fn03_settings_invoicing_added_to_external_js(): void {
		$file = $this->js_path( 'ltms-settings.js' );
		$this->assertFileExists( $file );
		$this->assert_file_contains( $file, 'AUDIT-PANEL-FN-03 (re-auditoría): vendor invoicing settings',
			'ltms-settings.js debe contener el bloque invoicing añadido al final del archivo (AUDIT-PANEL-FN-03).' );
		$this->assert_file_contains( $file, "ltms_vendor_save_invoicing_creds",
			'ltms-settings.js debe invocar la acción ltms_vendor_save_invoicing_creds.' );
		$this->assert_file_contains( $file, "ltms_vendor_test_invoicing_connection",
			'ltms-settings.js debe invocar la acción ltms_vendor_test_invoicing_connection.' );
	}

	public function test_fn03_external_js_push_rt_locales_via_ltmsRedi_object(): void {
		$file = $this->js_path( 'ltms-redi.js' );
		$this->assertFileExists( $file );
		$this->assert_file_contains( $file, "typeof ltmsRedi === 'undefined'",
			'ltms-redi.js debe hacer guard typeof ltmsRedi === undefined (defensivo si el enqueue no cargó).' );
		$this->assert_file_contains( $file, "var i18n = ltmsRedi.strings",
			'ltms-redi.js debe leer las strings localizadas desde ltmsRedi.strings.' );
		// AUDIT-PANEL-SEC-03 fix: productUrl debe ser validado a https:// antes de insertar en href.
		$this->assert_file_contains( $file, "/^https:\\/\\//i.test( rawUrl )",
			'ltms-redi.js debe validar productUrl con regex /^https:\\/\\//i antes de insertar en href (AUDIT-PANEL-SEC-03).' );
	}

	public function test_fn03_external_incidents_js_handles_all_4_actions(): void {
		$file = $this->js_path( 'ltms-incidents.js' );
		$this->assertFileExists( $file );
		$actions = [
			"action: 'ltms_get_incidents'",
			"action: 'ltms_get_incident_detail'",
			"action: 'ltms_add_incident_comment'",
			"action: 'ltms_create_incident'",
		];
		foreach ( $actions as $a ) {
			$this->assert_file_contains( $file, $a,
				"ltms-incidents.js debe manejar la action AJAX {$a} (paridad con los handlers del backend)." );
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// AUDIT-PANEL-FN-09 — Vista Analytics rota.
	//   (a) Dashboard wrapper NO tiene <div class="ltms-view-section"> nested.
	//   (b) El canvas ltms-vendor-analytics-chart existe en el wrapper.
	//   (c) dashboard.js define loadAnalyticsView() y renderiza sobre el canvas correcto.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_fn09_wrapper_no_nested_view_section_in_analytics(): void {
		$file = $this->views_path( 'dashboard-wrapper.php' );
		$this->assertFileExists( $file );
		$content = file_get_contents( $file );

		// Localizar el bloque del view-analytics y extraerlo.
		$start = strpos( $content, 'id="ltms-view-analytics"' );
		$this->assertNotFalse( $start, 'El bloque ltms-view-analytics debe existir en dashboard-wrapper.php.' );

		// Buscar el cierre del bloque (el </div> que está al mismo nivel del id).
		$end = strpos( $content, '</div>', $start );
		$this->assertNotFalse( $end, 'Cierre del bloque ltms-view-analytics no encontrado.' );

		// El siguiente cierre válido está tras el cierre del card.
		$block_end = strpos( $content, '</div>', $end + 6 );
		$block_end = strpos( $content, '</div>', $block_end + 6 );
		$block_end = strpos( $content, '</div>', $block_end + 6 );
		$block = substr( $content, $start, $block_end - $start + 6 );

		// AUDIT-PANEL-FN-09: dentro del bloque NO debe haber un <div class="ltms-view-section">
		// (el nested duplicado que rompía $('.ltms-view-section').hide()).
		$this->assertStringNotContainsString( '<div class="ltms-view-section">', $block,
			'dashboard-wrapper.php: el bloque ltms-view-analytics NO debe tener un <div class="ltms-view-section"> nested (AUDIT-PANEL-FN-09).' );

		// Debe contener el canvas correcto.
		$this->assertStringContainsString( '<canvas id="ltms-vendor-analytics-chart"></canvas>', $block,
			'dashboard-wrapper.php: el bloque ltms-view-analytics debe contener el canvas ltms-vendor-analytics-chart.' );
	}

	public function test_fn09_dashboard_js_defines_loadAnalyticsView(): void {
		$file = $this->js_path( 'ltms-dashboard.js' );
		$this->assertFileExists( $file );
		$this->assert_file_contains( $file, 'loadAnalyticsView(',
			'ltms-dashboard.js debe definir el método loadAnalyticsView() (AUDIT-PANEL-FN-09).' );
		$this->assert_file_contains( $file, 'AUDIT-PANEL-FN-09 (re-auditoría): cargador dedicado',
			'ltms-dashboard.js debe tener el comentario AUDIT-PANEL-FN-09 arriba de loadAnalyticsView.' );
	}

	public function test_fn09_dashboard_js_renderAnalyticsChart_uses_correct_canvas(): void {
		$file = $this->js_path( 'ltms-dashboard.js' );
		$this->assertFileExists( $file );
		$this->assert_file_contains( $file, "getElementById('ltms-vendor-analytics-chart')",
			'ltms-dashboard.js: renderAnalyticsChart() debe hacer getElementById(ltms-vendor-analytics-chart) — canvas de la vista Analytics (AUDIT-PANEL-FN-09).' );
		$this->assert_file_contains( $file, "this.charts.analytics",
			'ltms-dashboard.js: renderAnalyticsChart() debe usar this.charts.analytics (no this.charts.sales) para no destruir el chart del home.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// AUDIT-PANEL-FN-07 — Búsqueda de pedidos desconectada.
	//   bindOrderFilter() debe registrar un handler 'input' para #ltms-order-search
	//   con debounce (300ms típico) que actualice ordersState.search y llame fetchOrders.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_fn07_bindOrderFilter_has_input_handler_for_search(): void {
		$file = $this->js_path( 'ltms-dashboard.js' );
		$this->assertFileExists( $file );

		// Localizar bindOrderFilter() DEFINITION (con `{` para distinguir del call site línea 45).
		$content = file_get_contents( $file );
		$start = strpos( $content, 'bindOrderFilter() {' );
		$this->assertNotFalse( $start, 'bindOrderFilter() definition debe existir en ltms-dashboard.js.' );

		$method_body = substr( $content, $start, 4000 ); // 4KB deberian cubrir el método.

		$this->assertStringContainsString( 'AUDIT-PANEL-FN-07', $method_body,
			'bindOrderFilter() debe tener el comentario AUDIT-PANEL-FN-07 arriba del handler.' );
		$this->assertStringContainsString( "'input', '#ltms-order-search'", $method_body,
			'bindOrderFilter() debe registrar handler input para #ltms-order-search (AUDIT-PANEL-FN-07).' );
		$this->assertStringContainsString( 'ordersState.search', $method_body,
			'bindOrderFilter() debe actualizar ordersState.search en el handler.' );
		$this->assertStringContainsString( 'fetchOrders()', $method_body,
			'bindOrderFilter() debe llamar fetchOrders() tras actualizar el search.' );
		// Debe tener debounce (setTimeout o _.debounce) — validamos setTimeout.
		$this->assertStringContainsString( 'setTimeout', $method_body,
			'bindOrderFilter() debe hacer debounce del input via setTimeout (300ms) para no floodear el server.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// AUDIT-PANEL-FN-10 — Selector global sin scope.
	//   loadView() y showSection() deben prefijar sus selectores con #ltms-dashboard-container
	//   para no cerrar .ltms-view-section que pertenezcan a otros plugins/themes.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_fn10_loadView_scopes_view_section_selector(): void {
		$file = $this->js_path( 'ltms-dashboard.js' );
		$this->assertFileExists( $file );
		$content = file_get_contents( $file );

		// Validamos que la variante scoped SÍ existe.
		$this->assertStringContainsString( "#ltms-dashboard-container .ltms-view-section", $content,
			'ltms-dashboard.js: loadView() debe usar $("#ltms-dashboard-container .ltms-view-section").hide() (AUDIT-PANEL-FN-10).' );

		// Validamos que la variante UNSCOPED NO está más (la línea peligrosa original).
		// El patrón exacto es $('.ltms-view-section').hide() — hay que distinguirlo del scoped.
		// Usamos regex con lookbehind negativo — PHP PCRE soporta lookbehind fijo.
		$pattern = '/(?<!#ltms-dashboard-container )\(\'\.ltms-view-section\'\)\.hide\(\)/';
		$matches = preg_match( $pattern, $content );
		$this->assertSame( 0, $matches,
			'ltms-dashboard.js: NO debe existir $(\'.ltms-view-section\').hide() sin scope (AUDIT-PANEL-FN-10). Solo se permite dentro de #ltms-dashboard-container.' );
	}

	public function test_fn10_showSection_scopes_view_loader_selector(): void {
		$file = $this->js_path( 'ltms-dashboard.js' );
		$this->assertFileExists( $file );
		$content = file_get_contents( $file );

		$this->assertStringContainsString( "#ltms-dashboard-container .ltms-view-loader", $content,
			'ltms-dashboard.js: showSection() debe usar $("#ltms-dashboard-container .ltms-view-loader") (AUDIT-PANEL-FN-10).' );

		$pattern = '/(?<!#ltms-dashboard-container )\(\'\.ltms-view-loader\'\)\.hide\(\)/';
		$matches = preg_match( $pattern, $content );
		$this->assertSame( 0, $matches,
			'ltms-dashboard.js: NO debe existir $(\'.ltms-view-loader\').hide() sin scope en showSection() (AUDIT-PANEL-FN-10).' );
	}
}
