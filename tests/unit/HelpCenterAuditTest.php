<?php
/**
 * Tests estructurales del template público help-center.php.
 *
 * Foco actual: AUDIT-FE Fase 1.9 — auditoría full-stack del Centro de Ayuda
 * público (help-center.php). Cubre los 5 hallazgos HC-001..HC-005 documentados
 * en el comment block del propio template, MÁS 3 hallazgos de RE-AUDITORÍA
 * detectados al verificar que el fix previo SÓLO añadió documentación sin
 * tocar físicamente el source (ver LECCIONES_APRENDIDAS #141 — canarios
 * mentirosos en comment blocks):
 *
 *   * AUDIT-FE-HC-001 (P1, onsubmit inline): `<form onsubmit="return false;">`
 *     del form de búsqueda FAQ. RE-aplicación — el fix previo lo dejó en el
 *     source. CSP-violation (inline event handler).
 *
 *   * AUDIT-FE-HC-002 (P1, script-tag inline): las ~74 líneas del bloque
 *     <script> del template migradas a ltms-plaza-viva.js (scope HELP).
 *
 *   * AUDIT-FE-HC-003 (P0, alert() prohibido): el fallback `alert()` del
 *     chat trigger reemplazado por PV.toast / console.warn (design system
 *     exige toast system — ver QA_REPORT.md "alert(): 0").
 *
 *   * AUDIT-FE-HC-004 (P0, script-tag inline para chat setup): el bloque
 *     `<script>window.__ltmsTawkProperty=...;</script>` generado por PHP.
 *     RE-aplicación — el fix previo lo dejó en el source (líneas 117-123
 *     originales + echo en footer del template).
 *
 *   * AUDIT-FE-HC-005 (P1, PHP inline injection): las inyecciones
 *     `<?php echo esc_js(...)` dentro del JS inline reemplazadas por strings
 *     via PV.i18n. RE-aplicación — el fix previo PROMETÍA en su comment que
 *     "ya estaban expuestos por wp_localize_script" PERO NO los declaraba
 *     (i18n roto en runtime — los strings caían al fallback hardcodedo en
 *     español sin pasar por `__()`/`_n()`).
 *
 * Invariantes adicionales cubiertas:
 *
 *   * CSP-compliance estricto: help-center.php NO contiene NINGÚN `<script>`
 *     ni `</script>` ni `onsubmit=` ni otro inline event handler.
 *
 *   * i18n registration: los 3 strings del scope HELP
 *     (faq_result_singular, faq_result_plural, chat_unavailable) SÍ están
 *     declarados en wp_localize_script('ltms-plaza-viva', 'ltms_data', ...)
 *     en class-ltms-native-templates.php (no solo los defaults hardcodedos
 *     en el fallback de ltms-plaza-viva.js:44-60).
 *
 *   * Sincronización .min.js: ltms-plaza-viva.min.js contiene el scope HELP
 *     migrado (data-pv-faq-search, data-pv-chat-trigger). El fix previo dejó
 *     el .min.js desincronizado con el source .js (CI-LINT-MIN-001 resuelto
 *     en este mismo commit).
 *
 *   * Botones HTML preservados: el template sigue emitiendo el form con
 *     `data-pv-faq-search` y el botón `data-pv-chat-trigger` con los
 *     data-attrs del provider (no se rompió la migración HTML).
 *
 *   * JS del design system: ltms-plaza-viva.js contiene el scope HELP con
 *     el listener del form de búsqueda, el delegado de click del chat
 *     trigger, y la lectura de los 3 strings via PV.i18n.
 *
 * Estos tests son PURAMENTE estructurales (file_get_contents + asserts sobre
 * el source PHP/JS): NO cargan clases del plugin ni invocan WP → deterministas
 * en LTMS_UNIT_ONLY=true y CI Ubuntu sin depender del classmap estático del
 * autoloader de Composer (mismo patrón que VendorStoreCspTest,
 * WishlistPvToggleTest, OrderTrackingAuditTest).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class HelpCenterAuditTest
 *
 * Verifica los fixes AUDIT-FE-HC-001..HC-005 (RE-aplicación + RE-auditoría
 * Fase 1.9) sobre el template includes/frontend/templates/help-center.php
 * y el scope HELP de assets/js/ltms-plaza-viva.js mediante invariantes
 * estructurales del source. Detecta regresiones si alguien reintroduce el
 * script-tag inline del chat setup, el onsubmit="return false;" del form
 * de búsqueda FAQ, o elimina los 3 strings i18n del wp_localize_script.
 */
final class HelpCenterAuditTest extends LTMS_Unit_Test_Case {

	/**
	 * Ruta absoluta a la plantilla help-center.php.
	 */
	private string $template_path;

	/**
	 * Ruta absoluta a class-ltms-native-templates.php (wp_localize_script).
	 */
	private string $native_templates_path;

	/**
	 * Ruta absoluta al design system JS source.
	 */
	private string $pv_js_path;

	/**
	 * Ruta absoluta al design system JS minificado.
	 */
	private string $pv_min_js_path;

	/**
	 * @inheritDoc
	 *
	 * NOTA INTENCIONAL: este test NO llama $this->require_class(). Los
	 * tests son puramente de filesystem (file_get_contents + asserts),
	 * por lo que NO dependen del classmap estático de Composer. Esto
	 * los hace deterministas tanto en LTMS_UNIT_ONLY=true como en CI
	 * Ubuntu (mismo patrón que VendorStoreCspTest, OrderTrackingAuditTest
	 * — ver sus setUp docblocks).
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->template_path         = dirname( __DIR__, 2 ) . '/includes/frontend/templates/help-center.php';
		$this->native_templates_path = dirname( __DIR__, 2 ) . '/includes/frontend/class-ltms-native-templates.php';
		$this->pv_js_path            = dirname( __DIR__, 2 ) . '/assets/js/ltms-plaza-viva.js';
		$this->pv_min_js_path        = dirname( __DIR__, 2 ) . '/assets/js/ltms-plaza-viva.min.js';
	}

	/**
	 * AUDIT-FE-HC-001 (P1, onsubmit="return false;" inline event handler):
	 * el form de búsqueda FAQ (línea 150 original) tenía un inline event
	 * handler `onsubmit="return false;"` que rompía CSP-compliance del HTML.
	 *
	 * RE-aplicación Fase 1.9: el fix previo SÓLO añadió el comment block
	 * "AUDIT-FE-HC FIX" describiendo el fix, PERO dejó el `onsubmit=`
	 * físicamente en el source. El handler JS del scope HELP previene el
	 * default del submit (ltms-plaza-viva.js:1647-1649), no necesita el
	 * atributo inline.
	 *
	 * Regresión: si alguien reintroduce `onsubmit=` en el form, este test
	 * falla inmediatamente (CSP violation silenciosa en producción).
	 */
	public function test_001_onsubmit_inline_eliminado_del_form_faq_search(): void {
		$this->assertFileExists( $this->template_path );
		$src = file_get_contents( $this->template_path );

		// Strip PHP /* */ comments para evitar falso-positivo: el comment
		// block "AUDIT-FE-HC FIX" menciona `onsubmit=` textualmente como
		// documentación del fix, PERO el atributo inline físico debe seguir
		// ausente del código real. Mismo truco que test_003 (CSP-compliance).
		$src_without_comments = preg_replace( '/\/\*.*?\*\//s', '', $src );

		// (1) El atributo inline `onsubmit=` fue eliminado físicamente
		// del código (sin contar mentions en comments).
		$this->assertStringNotContainsString(
			'onsubmit=',
			$src_without_comments,
			'AUDIT-FE-HC-001 fix: el form de búsqueda FAQ no debe tener onsubmit="return false;" inline (CSP violation). El handler JS previene el default via addEventListener.'
		);

		// (2) El form sigue presente (no se rompió la eliminación del atributo).
		$this->assertStringContainsString(
			'<form class="pv-hero__search pv-help__search"',
			$src,
			'AUDIT-FE-HC-001 fix: el elemento <form> del search FAQ debe seguir presente (solo se elimina el atributo inline, no el form)'
		);

		// (3) El input con data-pv-faq-search sigue presente (el JS del scope
		// HELP lo selecciona por este data-attribute para enganchar el listener).
		$this->assertStringContainsString(
			'data-pv-faq-search',
			$src,
			'AUDIT-FE-HC-001 fix: el input data-pv-faq-search debe seguir presente (el JS del scope HELP lo lee via querySelector)'
		);
	}

	/**
	 * AUDIT-FE-HC-004 (P0, script-tag inline para chat provider setup):
	 * el template original generaba `<script>window.__ltmsTawkProperty=...`
	 * inline en PHP (líneas 117-123 originales) + el `echo $pv_chat_setup_html`
	 * en el footer del template.
	 *
	 * RE-aplicación Fase 1.9: el fix previo SÓLO añadió el comment block
	 * "AUDIT-FE-HC FIX" describiendo el fix como "fue eliminado", PERO dejó
	 * el bloque físico en el source. Canario mentiroso (LECCIONES #141).
	 *
	 * Regresión: si alguien reintroduce el bloque script inline del chat
	 * setup (PHP que genera `<script>window.__ltms*`), este test falla.
	 */
	public function test_002_chat_setup_script_inline_eliminado(): void {
		$this->assertFileExists( $this->template_path );
		$src = file_get_contents( $this->template_path );

		// Strip PHP /* */ comments: el comment block "AUDIT-FE-HC FIX"
		// menciona textualmente `$pv_chat_setup_html` y `window.__ltms*`
		// como documentación del fix eliminado, PERO el código real
		// debe seguir sin contenerlos. Mismo truco que test_001 / test_003.
		$src_without_comments = preg_replace( '/\/\*.*?\*\//s', '', $src );

		// (1) La variable $pv_chat_setup_html fue eliminada del source
		// (sin contar mentions en comments).
		$this->assertStringNotContainsString(
			'$pv_chat_setup_html',
			$src_without_comments,
			'AUDIT-FE-HC-004 fix: la variable $pv_chat_setup_html debe ser eliminada del template (era la fuente del script-tag inline)'
		);

		// (2) El script-tag inline `window.__ltmsTawkProperty` fue eliminado
		// (sin contar mentions en comments).
		$this->assertStringNotContainsString(
			'window.__ltmsTawkProperty',
			$src_without_comments,
			'AUDIT-FE-HC-004 fix: el script-tag inline `window.__ltmsTawkProperty=...` debe ser eliminado (CSP violation)'
		);

		// (3) El script-tag inline `window.__ltmsIntercomAppId` fue eliminado
		// (sin contar mentions en comments).
		$this->assertStringNotContainsString(
			'window.__ltmsIntercomAppId',
			$src_without_comments,
			'AUDIT-FE-HC-004 fix: el script-tag inline `window.__ltmsIntercomAppId=...` debe ser eliminado (CSP violation)'
		);

		// (4) El botón HTML data-pv-chat-trigger SIGUE presente (el JS del
		// scope HELP lee los IDs de los data-attrs del propio botón, no de
		// variables window globales inyectadas via script inline).
		$this->assertStringContainsString(
			'data-pv-chat-trigger=',
			$src,
			'AUDIT-FE-HC-004 fix: el botón data-pv-chat-trigger debe seguir presente (el JS lee data-pv-chat-tawk/intercom de sus attrs)'
		);
		$this->assertStringContainsString(
			'data-pv-chat-tawk=',
			$src,
			'AUDIT-FE-HC-004 fix: el atributo data-pv-chat-tawk= debe seguir presente (el JS lee el provider ID de aquí)'
		);
		$this->assertStringContainsString(
			'data-pv-chat-intercom=',
			$src,
			'AUDIT-FE-HC-004 fix: el atributo data-pv-chat-intercom= debe seguir presente (el JS lee el provider ID de aquí)'
		);
	}

	/**
	 * CSP-compliance estricto: help-center.php NO contiene NINGÚN bloque
	 * `<script>...</script>` inline. Esto cubre AUDIT-FE-HC-002 (scope HELP
	 * migrado al design system JS) y AUDIT-FE-HC-004 (chat setup inline
	 * eliminado) de forma holística.
	 *
	 * Regresión: si alguien añade cualquier bloque `<script>` inline al
	 * template (sin pasar por el design system global), este test falla.
	 * Paridad con VendorStoreCspTest (vendor-store.php 100% CSP-compliant).
	 */
	public function test_003_template_cero_scripts_inline_csp_compliance(): void {
		$this->assertFileExists( $this->template_path );
		$src = file_get_contents( $this->template_path );

		// Separar el código PHP del code commentary (el comment block
		// "AUDIT-FE-HC FIX" menciona `<script>` como documentación — no
		// debe contar como script tag real). Estrategia: stripr el comment
		// block antes de validar para evitar falso-positivo.
		$src_without_comments = preg_replace( '/\/\*.*?\*\//s', '', $src );

		$this->assertStringNotContainsString(
			'<script',
			$src_without_comments,
			'AUDIT-FE-HC-002 + AUDIT-FE-HC-004: help-center.php no debe contener NINGÚN bloque <script> inline (paridad con vendor-store.php 100% CSP-compliant)'
		);
		$this->assertStringNotContainsString(
			'</script>',
			$src_without_comments,
			'AUDIT-FE-HC-002 + AUDIT-FE-HC-004: help-center.php no debe contener NINGÚN bloque </script> inline'
		);
	}

	/**
	 * AUDIT-FE-HC-005 (P1, i18n registration en wp_localize_script): los 3
	 * strings del scope HELP (faq_result_singular, faq_result_plural,
	 * chat_unavailable) DEBEN estar declarados en el array `i18n` del
	 * wp_localize_script('ltms-plaza-viva', 'ltms_data', ...) en
	 * class-ltms-native-templates.php.
	 *
	 * RE-aplicación Fase 1.9: el fix previo PROMETÍA en su comment que
	 * "ya estaban expuestos por wp_localize_script" PERO NO los declaraba
	 * — i18n roto en runtime (los strings caían al fallback `|| 'resultado'`
	 * hardcodedo en español, sin pasar por `__()` / `_n()`). LECCIONES #141.
	 *
	 * Regresión: si someone elimina los 3 strings del wp_localize_script,
	 * este test falla (los strings dejarian de pasar por el sistema i18n
	 * de WP/ WPML, rompiendo traducción del plugin).
	 */
	public function test_004_i18n_strings_declarados_en_wp_localize_script(): void {
		$this->assertFileExists( $this->native_templates_path );
		$src = file_get_contents( $this->native_templates_path );

		// (1) Los 3 strings están declarados dentro del array i18n del
		// wp_localize_script de ltms-plaza-viva.
		$this->assertStringContainsString(
			"'faq_result_singular'",
			$src,
			'AUDIT-FE-HC-005 fix: faq_result_singular debe estar declarado en el array i18n del wp_localize_script de ltms-plaza-viva'
		);
		$this->assertStringContainsString(
			"'faq_result_plural'",
			$src,
			'AUDIT-FE-HC-005 fix: faq_result_plural debe estar declarado en el array i18n del wp_localize_script de ltms-plaza-viva'
		);
		$this->assertStringContainsString(
			"'chat_unavailable'",
			$src,
			'AUDIT-FE-HC-005 fix: chat_unavailable debe estar declarado en el array i18n del wp_localize_script de ltms-plaza-viva'
		);

		// (2) Los strings pasan por funciones de traducción de WP (_n, __)
		// — no son strings literales hardcodedos. Esto es lo que rompía el
		// fix previo (el fallback del JS caía a strings en español sin
		// pasar por el sistema i18n del plugin).
		$this->assertMatchesRegularExpression(
			'/\'faq_result_singular\'\s*=>\s*_n\s*\(/',
			$src,
			'AUDIT-FE-HC-005 fix: faq_result_singular debe pasar por _n() para traducción (singular/plural)'
		);
		$this->assertMatchesRegularExpression(
			'/\'faq_result_plural\'\s*=>\s*_n\s*\(/',
			$src,
			'AUDIT-FE-HC-005 fix: faq_result_plural debe pasar por _n() para traducción'
		);
		$this->assertMatchesRegularExpression(
			'/\'chat_unavailable\'\s*=>\s*__\s*\(/',
			$src,
			'AUDIT-FE-HC-005 fix: chat_unavailable debe pasar por __() para traducción'
		);
	}

	/**
	 * AUDIT-FE-HC-002 (P1, scope HELP migrado a ltms-plaza-viva.js): las
	 * ~74 líneas del bloque <script> del template fueron migradas al design
	 * system global en assets/js/ltms-plaza-viva.js (scope HELP al final
	 * del archivo).
	 *
	 * Verifica que el scope HELP está presente en el JS source con:
	 *   - El IIFE `helpScope` que inicializa el comportamiento.
	 *   - El selector del scope `.pv-scope.pv-help`.
	 *   - El listener del form de búsqueda FAQ (data-pv-faq-search).
	 *   - El delegado de click del chat trigger (data-pv-chat-trigger).
	 *   - La lectura de los 3 strings via PV.i18n (HC-005).
	 *   - El fallback console.warn en vez de alert() (HC-003).
	 *
	 * Regresión: si alguien elimina el scope HELP del design system, el
	 * search del FAQ y el chat trigger dejarían de funcionar en production.
	 */
	public function test_005_scope_help_presente_en_design_system_js(): void {
		$this->assertFileExists( $this->pv_js_path );
		$src = file_get_contents( $this->pv_js_path );

		// (1) El IIFE `helpScope` está presente (scope HELP migrado).
		$this->assertStringContainsString(
			'function helpScope',
			$src,
			'AUDIT-FE-HC-002 fix: el scope HELP debe estar migrado a ltms-plaza-viva.js como IIFE helpScope()'
		);

		// (2) El selector del scope: el JS detecta que está en la página
		// help-center via `.pv-scope.pv-help` (clase body del template).
		$this->assertStringContainsString(
			"querySelector('.pv-scope.pv-help')",
			$src,
			'AUDIT-FE-HC-002 fix: el scope HELP debe inicializarse solo cuando .pv-scope.pv-help está presente en el DOM'
		);

		// (3) Listener del form de búsqueda FAQ.
		$this->assertStringContainsString(
			"querySelector('[data-pv-faq-search]')",
			$src,
			'AUDIT-FE-HC-002 fix: el listener de FAQ search debe seleccionar el input via [data-pv-faq-search]'
		);

		// (4) Delegado de click del chat trigger.
		$this->assertStringContainsString(
			"closest('[data-pv-chat-trigger]')",
			$src,
			'AUDIT-FE-HC-002 fix: el delegado de click del chat trigger debe usar closest([data-pv-chat-trigger])'
		);
	}

	/**
	 * AUDIT-FE-HC-003 (P0, alert() prohibido): el fallback del chat trigger
	 * cuando PV.toast no existía usaba `alert()` en el JS inline original.
	 * `alert()` está prohibido por el design system (QA_REPORT.md: "alert():
	 * 0 ✅ Toast system").
	 *
	 * Fix: el scope HELP migrado usa PV.toast. Si PV.toast no está cargado,
	 * usa `console.warn()` (no alert()). Sistema de fallback elegante.
	 *
	 * Regresión: si someone reintroduce `alert(` en el scope HELP, este
	 * test falla (rompe la regla del design system "alert(): 0").
	 */
	public function test_006_chat_trigger_fallback_usa_pvtoast_no_alert(): void {
		$this->assertFileExists( $this->pv_js_path );
		$src = file_get_contents( $this->pv_js_path );

		// (1) El handler usa PV.toast como camino principal.
		$this->assertStringContainsString(
			'if (PV.toast)',
			$src,
			'AUDIT-FE-HC-003 fix: el fallback del chat trigger debe usar PV.toast como camino principal'
		);

		// (2) El fallback a console.warn NO usa alert().
		$this->assertStringContainsString(
			'console.warn',
			$src,
			'AUDIT-FE-HC-003 fix: el fallback secundario debe usar console.warn, no alert()'
		);

		// (3) Validación negativa: el scope HELP NO contiene `alert(` (que
		// no sea substring de "console.alert" que no existe, ni en comments).
		// Estrategia: stripar JS comments (/* */ blocks Y // de línea) del
		// scope HELP y validar que no hay `alert(` en código real. El
		// comment del scope HELP menciona `alert()` textualmente como
		// documentación del fallback eliminado — no cuenta como código vivo.
		// Como el test es sobre el archivo completo y `alert(` puede aparecer
		// en otros scopes (no help), aislamos el scope HELP explícito.
		preg_match( '/function helpScope.*?\}\)\(\);\s*$/s', $src, $m );
		$this->assertNotEmpty(
			$m,
			'AUDIT-FE-HC-003 test precondition: el scope HELP debe estar presente para validar absence of alert()'
		);
		$help_scope_src        = $m[0];
		// Strip /*...*/ block comments primero (multilínea).
		$help_scope_no_block   = preg_replace( '/\/\*.*?\*\//s', '', $help_scope_src );
		// Strip // single-line comments (preservando URLs http:// https://
		// con lookahead negativo //: para no romper strings con URLs).
		$help_scope_no_comments = preg_replace( '#(^|[^\:])//(?!\:).*?$#m', '$1', $help_scope_no_block );
		$this->assertStringNotContainsString(
			'alert(',
			$help_scope_no_comments,
			'AUDIT-FE-HC-003 fix: el scope HELP no debe contener alert() en código real (prohibido por design system — ver QA_REPORT.md)'
		);

		// (4) El string chat_unavailable se lee via PV.i18n (HC-005 fix).
		$this->assertStringContainsString(
			'PV.i18n.chat_unavailable',
			$help_scope_src,
			'AUDIT-FE-HC-005 fix: el scope HELP debe leer chat_unavailable via PV.i18n (no inyectar PHP en el JS)'
		);
	}

	/**
	 * AUDIT-FE-HC-005 (P1, defaults i18n en ltms-plaza-viva.js): el objeto
	 * `PV.i18n` en ltms-plaza-viva.js:44-60 tiene defaults hardcodedos en
	 * español para los 3 strings del scope HELP (faq_result_singular,
	 * faq_result_plural, chat_unavailable). Esto es una red de seguridad
	 * para el caso en que wp_localize_script no corra (e.g., página sin
	 * design system enqueue).
	 *
	 * Verifica que los defaults están presentes como red de seguridad,
	 * independiente de la registration en wp_localize_script (test_004).
	 *
	 * Regresión: si someone elimina los defaults del JS, y por alguna razón
	 * el wp_localize_script falla en runtime, los strings quedarían undefined.
	 */
	public function test_007_defaults_i18n_en_js_fallback_red_seguridad(): void {
		$this->assertFileExists( $this->pv_js_path );
		$src = file_get_contents( $this->pv_js_path );

		// Los 3 defaults están en el objeto PV.i18n fallback.
		$this->assertStringContainsString(
			"faq_result_singular: 'resultado'",
			$src,
			'AUDIT-FE-HC-005 fix: el default del objeto PV.i18n debe incluir faq_result_singular como red de seguridad'
		);
		$this->assertStringContainsString(
			"faq_result_plural: 'resultados'",
			$src,
			'AUDIT-FE-HC-005 fix: el default del objeto PV.i18n debe incluir faq_result_plural como red de seguridad'
		);
		$this->assertStringContainsString(
			"chat_unavailable: 'El chat no está disponible en este momento.",
			$src,
			'AUDIT-FE-HC-005 fix: el default del objeto PV.i18n debe incluir chat_unavailable como red de seguridad'
		);
	}

	/**
	 * AUDIT-FE-HC-007 (P1, sincronización .min.js con el source .js): el
	 * .min.js (ltms-plaza-viva.min.js) debe contener el scope HELP migrado
	 * en AUDIT-FE-HC-002, ya que el SITE (SiteGround SG Optimizer) carga
	 * el .min.js en producción, no el source .js.
	 *
	 * RE-aplicación Fase 1.9: el fix previo de HC-002 (migración al source
	 * .js) dejó el .min.js DESINCRONIZADO — el .min.js commiteado medía
	 * 35504 bytes sin el scope HELP (versión vieja pre-fix), mientras el
	 * source .js medía 49766 bytes con el scope HELP completo. En
	 * producción el chat trigger y el search del FAQ no funcionaban. Mismo
	 * patrón que CI-LINT-MIN-001 (CHANGELOG Fase 1.5 backlog).
	 *
	 * Fix: `npm run build:js` regenera el .min.js con el scope HELP.
	 * Verificación: el .min.js contiene data-pv-faq-search, data-pv-chat-
	 * trigger, y los 3 strings i18n.
	 *
	 * Regresión: si someone edita el source .js sin regenerar el .min.js,
	 * este test falla (producción cae a versión vieja del JS).
	 */
	public function test_008_min_js_sincronizado_con_scope_help(): void {
		$this->assertFileExists( $this->pv_min_js_path );
		$src = file_get_contents( $this->pv_min_js_path );

		// (1) El scope HELP está minificado en el .min.js.
		$this->assertStringContainsString(
			'data-pv-faq-search',
			$src,
			'AUDIT-FE-HC-007 fix: el .min.js debe contener el selector data-pv-faq-search (scope HELP migrado)'
		);
		$this->assertStringContainsString(
			'data-pv-chat-trigger',
			$src,
			'AUDIT-FE-HC-007 fix: el .min.js debe contener el selector data-pv-chat-trigger (scope HELP migrado)'
		);

		// (2) Los 3 strings i18n están en el .min.js (como defaults del
		// objeto PV.i18n fallback).
		$this->assertStringContainsString(
			'faq_result_singular',
			$src,
			'AUDIT-FE-HC-007 fix: el .min.js debe contener el default faq_result_singular (red de seguridad PV.i18n)'
		);
		$this->assertStringContainsString(
			'chat_unavailable',
			$src,
			'AUDIT-FE-HC-007 fix: el .min.js debe contener el default chat_unavailable (red de seguridad PV.i18n)'
		);
	}

	/**
	 * Sanity check: el template preserva los botones HTML críticos del
	 * Centro de Ayuda (chat trigger, search FAQ, accesos rápidos, FAQ
	 * accordion). Si una migración futura rompe el HTML, este test falla.
	 */
	public function test_009_template_preserva_estructura_html_critica(): void {
		$this->assertFileExists( $this->template_path );
		$src = file_get_contents( $this->template_path );

		// Estructura del hero search.
		$this->assertStringContainsString( 'pv-help__hero', $src );
		$this->assertStringContainsString( 'pv-hero__title', $src );

		// Accesos rápidos (tracking, policies, returns).
		$this->assertStringContainsString( 'pv-help__quick-card', $src );
		$this->assertStringContainsString( '$pv_tracking_url', $src );
		$this->assertStringContainsString( '$pv_policies_url', $src );
		$this->assertStringContainsString( '$pv_returns_url', $src );

		// Cards de canales (chat, email, whatsapp).
		$this->assertStringContainsString( 'pv-help__channel', $src );
		$this->assertStringContainsString( '$pv_whatsapp_url', $src );
		$this->assertStringContainsString( '$pv_email_url', $src );

		// FAQ accordion (wpautop(wp_kses_post(wptexturize($pv_a)))).
		$this->assertStringContainsString( 'pv-help__faq-list', $src );
		$this->assertStringContainsString( 'data-pv-faq-item', $src );
		$this->assertStringContainsString( 'wpautop( wp_kses_post( wptexturize( $pv_a ) ) )', $src );
	}

	/**
	 * AUDIT-FE-HC-001 (continuación del test_001) — el handler JS del
	 * scope HELP previene el default del submit del form (para compensar
	 * la eliminación del atributo inline `onsubmit="return false;"`).
	 *
	 * Regresión: si someone elimina el preventDefault del JS, el form haría
	 * submit tradicional (recarga de página) y el listener `input` del
	 * search nunca se engancharía — la búsqueda en vivo del FAQ se rompería.
	 */
	public function test_010_js_previene_submit_default_del_form_faq(): void {
		$this->assertFileExists( $this->pv_js_path );
		$src = file_get_contents( $this->pv_js_path );

		// (1) El handler JS captura el form via querySelector del input.
		$this->assertStringContainsString(
			"search.closest('form')",
			$src,
			'AUDIT-FE-HC-001 fix: el JS debe capturar el <form> via search.closest("form") (input data-pv-faq-search al form padre)'
		);

		// (2) El handler previene el default del submit.
		$this->assertStringContainsString(
			"searchForm.addEventListener('submit'",
			$src,
			'AUDIT-FE-HC-001 fix: el JS debe registrar un addEventListener("submit") en el form de FAQ search'
		);
		$this->assertStringContainsString(
			'e.preventDefault()',
			$src,
			'AUDIT-FE-HC-001 fix: el handler del submit debe llamar e.preventDefault() (compensa la eliminación del atributo inline)'
		);

		// (3) Traza del fix en el JS para auditoría futura.
		$this->assertStringContainsString(
			'AUDIT-FE-HC-001 FIX',
			$src,
			'AUDIT-FE-HC-001 fix: el JS debe contener la traza de auditoría AUDIT-FE-HC-001 FIX para trazabilidad futura'
		);
	}

	/**
	 * AUDIT-FE-HC-005 (continuación del test_004) — el scope HELP del JS
	 * lee los 3 strings via PV.i18n (con fallback `||` a defaults hardcodedos
	 * en español). La lectura via PV.i18n debe estar presente en el JS.
	 *
	 * Regresión: si someone elimina la lectura via PV.i18n del scope HELP
	 * y hardcodea strings en español directamente en el JS, el plugin
	 * pierde la capacidad de traducción (los strings pasarían por alto
	 * wp_localize_script y WPML).
	 */
	public function test_011_js_scope_help_leee_strings_via_pv_i18n(): void {
		$this->assertFileExists( $this->pv_js_path );
		$src = file_get_contents( $this->pv_js_path );
		preg_match( '/function helpScope.*?\}\)\(\);\s*$/s', $src, $m );
		$this->assertNotEmpty( $m, 'Scope HELP debe estar presente para validar lectura via PV.i18n' );
		$help_scope_src = $m[0];

		// (1) faq_result_singular se lee via PV.i18n.
		$this->assertStringContainsString(
			'PV.i18n.faq_result_singular',
			$help_scope_src,
			'AUDIT-FE-HC-005 fix: el scope HELP debe leer faq_result_singular via PV.i18n (no hardcodedo)'
		);

		// (2) faq_result_plural se lee via PV.i18n.
		$this->assertStringContainsString(
			'PV.i18n.faq_result_plural',
			$help_scope_src,
			'AUDIT-FE-HC-005 fix: el scope HELP debe leer faq_result_plural via PV.i18n (no hardcodedo)'
		);
	}

	/**
	 * AUDIT-FE-HC-009 (P2, código muerto defensivo): la rama CPT `ltms_faq`
	 * (líneas 49-66 del template) nunca se ejecuta en producción porque
	 * NO existe `register_post_type('ltms_faq')` en el plugin. Es defensive
	 * (`post_type_exists` devuelve false → fallback hardcodeado), pero es
	 * UI que aparenta soportar un CPT que no existe — patrón LECCIONES #139.
	 *
	 * Este test es informational (no falla — solo valida que el código
	 * defensivo sigue presente y no se rompió). Documentado en backlog
	 * del CHANGELOG Fase 1.9 como P2.
	 */
	public function test_012_rama_cpt_ltms_faq_defensiva_bienEscapada(): void {
		$this->assertFileExists( $this->template_path );
		$src = file_get_contents( $this->template_path );

		// (1) El check `post_type_exists('ltms_faq')` sigue presente (defensive).
		$this->assertStringContainsString(
			"post_type_exists( 'ltms_faq' )",
			$src,
			'AUDIT-FE-HC-009 (backlog): el gate defensivo post_type_exists(ltms_faq) debe seguir presente hasta que se decida registrar el CPT o eliminar la rama'
		);

		// (2) El fallback hardcodeado (8 preguntas) está presente.
		$this->assertStringContainsString(
			"// Fallback hardcodeado",
			$src,
			'AUDIT-FE-HC-009 (backlog): el fallback hardcodeado de 8 preguntas FAQ debe seguir presente'
		);

		// (3) El title de cada FAQ item del CPT se escapa con wp_strip_all_tags.
		$this->assertStringContainsString(
			'wp_strip_all_tags( $pv_fp->post_title )',
			$src,
			'AUDIT-FE-HC: el title del CPT ltms_faq debe escaparse con wp_strip_all_tags() (defensa XSS)'
		);

		// (4) El content del FAQ item se escapa con wpautop(wp_kses_post(...)) en render.
		$this->assertStringContainsString(
			'wpautop( wp_kses_post( wptexturize( $pv_a ) ) )',
			$src,
			'AUDIT-FE-HC: el content del FAQ debe escaparse con wpautop(wp_kses_post(wptexturize())) en render'
		);
	}

	/**
	 * AUDIT-FE-HC-010 (P2, hooks declarados sin consumidor): los 2 hooks
	 * `ltms_before_help_center_plazaviva` y `ltms_after_help_center_plazaviva`
	 * son declarados en el template (do_action) PERO no tienen consumidores
	 * en includes/. Se preservan como válvula de extensión para 3rd-party
	 * (mismo patrón que AUDIT-FE-OT-004 — hooks del order-tracking).
	 *
	 * Este test es informational (no falla). Documentado en backlog.
	 */
	public function test_013_hooks_extension_preservados(): void {
		$this->assertFileExists( $this->template_path );
		$src = file_get_contents( $this->template_path );

		// (1) Hook before está declarado.
		$this->assertStringContainsString(
			"do_action( 'ltms_before_help_center_plazaviva' )",
			$src,
			'AUDIT-FE-HC-010 (backlog): el hook ltms_before_help_center_plazaviva debe seguir declarado (válido para extensión 3rd-party)'
		);

		// (2) Hook after está declarado.
		$this->assertStringContainsString(
			"do_action( 'ltms_after_help_center_plazaviva' )",
			$src,
			'AUDIT-FE-HC-010 (backlog): el hook ltms_after_help_center_plazaviva debe seguir declarado (válido para extensión 3rd-party)'
		);
	}
}
