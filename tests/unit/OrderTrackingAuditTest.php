<?php
/**
 * Tests estructurales del template público order-tracking.php.
 *
 * Foco actual: AUDIT-FE Fase 1.8 — auditoría full-stack de la página pública
 * de seguimiento de orden (order-tracking.php). Cubre 3 hallazgos:
 *
 *   * AUDIT-FE-OT-001 (P0, IDOR guest + key truthy): el Caso 3 del gate de
 *     acceso previo aceptaba CUALQUIER string truthy en ?key= para órdenes
 *     de guest → IDOR de TODAS las órdenes guest. Fix: eliminar Caso 3,
 *     dejar solo Caso 1 (dueño logueado) y Caso 2 (order_key válida con
 *     hash_equals).
 *
 *   * AUDIT-FE-OT-002 (P1, timeline cosmético engañoso): el timeline leía
 *     metas _ltms_preparing_at/_ltms_shipped_at/_ltms_in_transit_at/
 *     _ltms_driver_* que NADIE escribía en el plugin → siempre en step 0,
 *     repartidor siempre "Por asignar", ETA siempre "Por confirmar". Fix:
 *     usar status WC nativo + tracking_number/carrier + metas que SÍ se
 *     escriben en el plugin (_ltms_shipping_delivered_at/
 *     _ltms_delivered_at/_ltms_shipping_delivered_fired).
 *
 *   * AUDIT-FE-OT-003 (P1, auto-reload 60s incondicional): el reload cada
 *     60s se disparaba para siempre (las metas nunca se llenaban → loop),
 *     recargando la página mientras el usuario leía o interactuaba. Fix:
 *     solo recarga si current_step < 2 y no hay tracking_number y respeta
 *     <details open> y campos con focus.
 *
 *   * AUDIT-FE-OT-005 (P0, <script> inline rompe CSP): el template era el
 *     ÚLTIMO del design system con un bloque <script> inline (76 líneas,
 *     líneas 1071-1146 originales). Fix: los 3 behaviours (auto-scroll
 *     bounce del paso activo, live refresh 60s con guards, smooth scroll
 *     del summary toggle) migraron a assets/js/ltms-plaza-viva.js como
 *     IIFE trackingScope() (scope TRACKING al final del archivo), que lee
 *     data-order-id/data-current-step ya expuestos por el PHP en :358.
 *     El bloque inline fue eliminado FÍSICAMENTE del template (ver
 *     LECCIONES_APRENDIDAS #141 — migración física, no un comment).
 *
 * Estos tests son PURAMENTE estructurales (file_get_contents + asserts sobre
 * el source PHP/JS): NO cargan clases del plugin ni invocan WP → deterministas
 * en LTMS_UNIT_ONLY=true y CI Ubuntu sin depender del classmap estático del
 * autoloader de Composer (mismo patrón que VendorStoreCspTest, WishlistPvToggleTest).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class OrderTrackingAuditTest
 *
 * Verifica los 3 fixes AUDIT-FE-OT-001/002/003 sobre el template
 * includes/frontend/templates/order-tracking.php mediante invariantes
 * estructurales del source. Detecta regresiones si alguien reintroduce el
 * Caso 3 IDOR, las metas _ltms_* como única fuente del timeline, o el
 * auto-reload incondicional sin respetar interacción del usuario.
 */
final class OrderTrackingAuditTest extends LTMS_Unit_Test_Case {

	/**
	 * Ruta absoluta a la plantilla order-tracking.php.
	 */
	private string $template_path;

	/**
	 * Ruta absoluta al design system JS (ltms-plaza-viva.js).
	 */
	private string $js_path;

	/**
	 * Ruta absoluta al design system JS minificado (ltms-plaza-viva.min.js).
	 * Debe regenerarse con `npm run build:js` tras editar el source.
	 */
	private string $js_min_path;

	/**
	 * @inheritDoc
	 *
	 * NOTA INTENCIONAL: este test NO llama $this->require_class(). Los
	 * tests son puramente de filesystem (file_get_contents + asserts),
	 * por lo que NO dependen del classmap estático de Composer. Esto
	 * los hace deterministas tanto en LTMS_UNIT_ONLY=true como en CI
	 * Ubuntu (mismo patrón que VendorStoreCspTest — ver su setUp docblock).
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->template_path = dirname( __DIR__, 2 ) . '/includes/frontend/templates/order-tracking.php';
		$this->js_path       = dirname( __DIR__, 2 ) . '/assets/js/ltms-plaza-viva.js';
		$this->js_min_path   = dirname( __DIR__, 2 ) . '/assets/js/ltms-plaza-viva.min.js';
	}

	/**
	 * AUDIT-FE-OT-001 (P0, IDOR guest + key truthy): el Caso 3 previo
	 * (líneas 89-92 originales) aceptaba CUALQUIER string truthy en ?key=
	 * para órdenes guest sin compararlo con el order_key real → cualquier
	 * visitante podía ver TODAS las órdenes guest via ?order_id=N&key=x.
	 *
	 * Fix aplicado: el gate queda reducido a solo Caso 1 (dueño logueado)
	 * y Caso 2 (order_key válida con hash_equals timing-safe). El Caso 3
	 * fue eliminado físicamente del source.
	 */
	public function test_001_idor_caso3_eliminado_gate_solo_caso1_y_caso2(): void {
		$this->assertFileExists( $this->template_path );
		$src = file_get_contents( $this->template_path );

		// (1) El Caso 3 fue eliminado físicamente: ya NO existe el bloque
		// "guests sin key — solo si la orden pertenece a un guest".
		$this->assertStringNotContainsString(
			"// Caso 3: guests sin key",
			$src,
			'AUDIT-FE-OT-001 fix: the IDOR-vulnerable "Caso 3" comment block must be removed'
		);

		// (2) No queda la asignación `$access_granted = true;` precedida por
		// el check `$order_customer_id === 0 && $current_user_id === 0 && $pv_request_key`
		// (que era exactamente el Caso 3 IDOR — cualquier string truthy en
		// ?key= abría TODAS las órdenes guest).
		$this->assertStringNotContainsString(
			'$order_customer_id === 0 && $current_user_id === 0 && $pv_request_key',
			$src,
			'AUDIT-FE-OT-001 fix: the IDOR-vulnerable Caso 3 condition (guest + any truthy key) must be removed'
		);

		// (3) El Caso 2 sigue presente y usa hash_equals (timing-safe compare).
		// La condición del Caso 2 (`! $access_granted && $pv_request_key`) debe
		// existir, pero la its correctness vive en hash_equals de abajo.
		$this->assertStringContainsString(
			'// Caso 2: order_key',
			$src,
			'AUDIT-FE-OT-001: Caso 2 (order_key válida) must remain — it is the legitimate guest path'
		);
		$this->assertStringContainsString(
			'hash_equals( $order_key, $pv_request_key )',
			$src,
			'AUDIT-FE-OT-001: Caso 2 must compare with hash_equals (timing-safe) against the real order_key — never truthy-fallback'
		);

		// (4) Traza del fix para auditorías futuras.
		$this->assertStringContainsString(
			'AUDIT-FE-OT-001',
			$src,
			'AUDIT-FE-OT-001: template must contain the traceable fix marker comment for future audits'
		);

		// (5) El filtro ltms_order_tracking_access se mantiene como válvula
		// de extensión (terceros pueden ampliar el gate vía add_filter).
		$this->assertStringContainsString(
			"apply_filters( 'ltms_order_tracking_access'",
			$src,
			'AUDIT-FE-OT-001: the ltms_order_tracking_access filter must remain (extension valve for 3rd-party access logic)'
		);
	}

	/**
	 * AUDIT-FE-OT-002 (P1, timeline cosmético): el timeline previo solo
	 * leía metas _ltms_preparing_at/_ltms_shipped_at/_ltms_in_transit_at/
	 * _ltms_driver_* que NADIE escribía en el plugin → el timeline se
	 * quedaba pegado en step 0 para siempre, repartidor siempre "Por
	 * asignar", ETA siempre "Por confirmar".
	 *
	 * Fix aplicado: además de las metas opcionales (graceful), se usan
	 * como fuentes primarias los status nativos de WC + tracking_number/
	 * carrier + las metas que SÍ se escriben en el plugin:
	 *   - _ltms_shipping_delivered_at (Core cron manager TS-BUG-1)
	 *   - _ltms_delivered_at          (Deprisa tracking-cron)
	 *   - _ltms_shipping_delivered_fired (Aveonline, Deprisa, Uber Direct,
	 *     Own-Delivery, Pickup handler / idempotencia)
	 */
	public function test_002_timeline_usa_metas_reales_y_status_wc_nativo(): void {
		$this->assertFileExists( $this->template_path );
		$src = file_get_contents( $this->template_path );

		// (1) Lee las metas reales que SÍ se escriben en el plugin.
		$this->assertStringContainsString(
			"_ltms_shipping_delivered_fired",
			$src,
			'AUDIT-FE-OT-002: template must read _ltms_shipping_delivered_fired (idempotencia meta written by Deprisa, Aveonline, Uber Direct, Own-Delivery, Pickup)'
		);
		$this->assertStringContainsString(
			"_ltms_shipping_delivered_at",
			$src,
			'AUDIT-FE-OT-002: template must read _ltms_shipping_delivered_at (written by Core cron manager TS-BUG-1)'
		);
		$this->assertStringContainsString(
			"_ltms_delivered_at",
			$src,
			'AUDIT-FE-OT-002: template must read _ltms_delivered_at (written by Deprisa tracking-cron line 227)'
		);

		// (2) Mapa de status WC → step del timeline. La fuente de verdad
		// primaria del current_step_idx es el status nativo de WC, no las
		// metas _ltms_* nunca escritas.
		$this->assertStringContainsString(
			"\$status_to_step = array(",
			$src,
			'AUDIT-FE-OT-002: current_step_idx must derive from a status_to_step map (WC native status as source of truth)'
		);
		$this->assertStringContainsString(
			"'pending'    => 0",
			$src,
			'AUDIT-FE-OT-002: status_to_step must include pending → 0 (confirmed step)'
		);
		$this->assertStringContainsString(
			"'processing' => 1",
			$src,
			'AUDIT-FE-OT-002: status_to_step must include processing → 1 (preparing step)'
		);
		$this->assertStringContainsString(
			"'completed'  => 4",
			$src,
			'AUDIT-FE-OT-002: status_to_step must include completed → 4 (delivered step)'
		);

		// (3) Avance del step basado en tracking_number/carrier presence.
		$this->assertStringContainsString(
			"\$tracking_number && \$current_step_idx < 2",
			$src,
			'AUDIT-FE-OT-002: when tracking_number is set, step must advance to at least 2 (shipped) — real WC signal, not invented meta'
		);

		// (4) Flag is_actually_delivered combinando las 3 fuentes reales.
		$this->assertStringContainsString(
			"\$is_actually_delivered = \$delivered_fired || 'completed' === \$order_status || \$date_delivered_ts_for_meta > 0;",
			$src,
			'AUDIT-FE-OT-002: is_actually_delivered flag must combine 3 real sources (delivered_fired meta + status=completed + delivered_ts meta)'
		);

		// (5) Traza del fix para auditorías futuras.
		$this->assertStringContainsString(
			'AUDIT-FE-OT-002',
			$src,
			'AUDIT-FE-OT-002: template must contain the traceable fix marker comment for future audits'
		);
	}

	/**
	 * AUDIT-FE-OT-002 (continuación): cuando la orden usa carrier externo
	 * (hay tracking_number) PERO no hay metas _ltms_driver_* (que nadie
	 * escribe), el card del repartidor debe mostrar estado honesto
	 * ("Transportadora / Asignada") en vez del placeholder engañoso
	 * "Sin asignar aún / te notificaremos cuando asignemos tu repartidor".
	 *
	 * El card debe distinguir 3 casos: (a) driver meta set → profile real,
	 * (b) tracking_number presente + no own-delivery + no pickup →
	 * "Transportadora asignada", (c) shipping_method es pickup → pickup,
	 * (d) nada → "Sin asignar aún" (este último es el fallback honesto).
	 */
	public function test_003_repartidor_card_muestra_estado_honesto_segun_flujo(): void {
		$this->assertFileExists( $this->template_path );
		$src = file_get_contents( $this->template_path );

		// (1) Detecta el método de envío de la orden para discriminar.
		$this->assertStringContainsString(
			"\$uses_own_delivery = false;",
			$src,
			'AUDIT-FE-OT-002: template must detect own-delivery shipping method to choose honest repartidor card state'
		);
		$this->assertStringContainsString(
			"'ltms_own_delivery' === \$mid",
			$src,
			'AUDIT-FE-OT-002: template must check ltms_own_delivery shipping method id'
		);
		$this->assertStringContainsString(
			"'ltms_pickup'        === \$mid",
			$src,
			'AUDIT-FE-OT-002: template must check ltms_pickup shipping method id'
		);

		// (2) Caso carrier externo con tracking: muestra carrier en vez de
		// "Sin asignar aún".
		$this->assertStringContainsString(
			"\$tracking_number && ! \$uses_own_delivery && ! \$uses_pickup",
			$src,
			'AUDIT-FE-OT-002: when tracking_number set and not own-delivery/pickup, repartidor card must show carrier card (honest state)'
		);
		// El carrier aparece en mayúsculas o fallback "Transportadora".
		$this->assertStringContainsString(
			"\$carrier ? strtoupper( \$carrier ) : __( 'Transportadora'",
			$src,
			'AUDIT-FE-OT-002: carrier-external card must display strtoupper(carrier) or fallback "Transportadora"'
		);

		// (3) Caso pickup explícito — recoger en tienda, no repartidor.
		$this->assertStringContainsString(
			'$uses_pickup',
			$src,
			'AUDIT-FE-OT-002: pickup branch must exist in repartidor card (no driver for pickup orders)'
		);
	}

	/**
	 * AUDIT-FE-OT-003 (P1, auto-reload 60s incondicional): el reload
	 * cada 60s se disparaba para siempre porque `hasDriver` siempre era
	 * false (las metas _ltms_driver_* nunca se llenaban) y recargaba la
	 * página mientras el usuario leía o interactuaba con el detalle
	 * colapsable o el form de búsqueda.
	 *
	 * Fix: el reload solo dispara si current_step < 2 (todavía en
	 * preparación, sin tracking) y respeta <details open> y form fields
	 * con focus (no rompe UX mientras el usuario interactúa).
	 *
	 * NOTA AUDIT-FE-OT-005: este behaviour (junto con todo el bloque
	 * <script> inline) migró físicamente a assets/js/ltms-plaza-viva.js
	 * como scope TRACKING. Las invariantes se verifican ahora contra el
	 * source del design system JS (antes contra el template — ver
	 * LECCIONES #119: actualizar los tests al migrar código entre
	 * archivos, en el MISMO commit que la migración).
	 */
	public function test_004_auto_reload_no_incondicional_respeta_interaccion(): void {
		$this->assertFileExists( $this->js_path );
		$src = file_get_contents( $this->js_path );

		// (1) La condición ΟLD de "currentStep < 4" (cualquier paso no
		// entregado) fue reemplazada por "currentStep < 2" (solo en
		// preparación, sin tracking aún).
		$this->assertStringNotContainsString(
			'currentStep < 4',
			$src,
			'AUDIT-FE-OT-003 fix: the over-broad currentStep < 4 condition (fired even at shipped/in_transit) must be removed'
		);
		$this->assertStringContainsString(
			'currentStep < 2',
			$src,
			'AUDIT-FE-OT-003 fix: auto-reload must only fire if current_step < 2 (still in preparation, no tracking yet)'
		);

		// (2) La lookup de "hasDriver" (basada en metas nunca llenadas)
		// fue reemplazada por "hasTracking" (verifica presence del número
		// de seguimiento en el timeline).
		$this->assertStringNotContainsString(
			"querySelector('[data-pv-tracker] .pv-tracker-card__driver:not(.pv-tracker-card__driver--empty)')",
			$src,
			'AUDIT-FE-OT-003 fix: the hasDriver lookup (based on _ltms_driver_* metas never written) must be removed'
		);
		$this->assertStringContainsString(
			"querySelector('.pv-timeline-step__tracking-num')",
			$src,
			'AUDIT-FE-OT-003: hasTracking must check for the tracking-num element in the timeline — only real WC signal'
		);

		// (3) Respeta <details open> (anti-pérdida de scroll/contexto
		// mientras el usuario lee el detalle colapsable).
		$this->assertStringContainsString(
			"hasOpenDetails = !!scope.querySelector('details[open]')",
			$src,
			'AUDIT-FE-OT-003: auto-reload must skip when a <details> is open in the tracking page (anti scroll/context loss)'
		);

		// (4) Respeta campos con focus (anti-pérdida de input).
		$this->assertStringContainsString(
			"activeEl.tagName === 'INPUT'",
			$src,
			'AUDIT-FE-OT-003: auto-reload must skip when a form field has focus (anti input loss)'
		);

		// (5) Solo recarga si la página está visible (anti电池ja en background).
		$this->assertStringContainsString(
			"document.visibilityState === 'visible'",
			$src,
			'AUDIT-FE-OT-003: auto-reload must only fire when the document is visible (preserved from original)'
		);

		// (6) Traza del fix para auditorías futuras.
		$this->assertStringContainsString(
			'AUDIT-FE-OT-003',
			$src,
			'AUDIT-FE-OT-003: the design system JS (scope TRACKING) must contain the traceable fix marker comment for future audits'
		);
	}

	/**
	 * AUDIT-FE-OT-005 (P0, <script> inline rompe CSP): order-tracking.php
	 * era el ÚLTIMO template del design system con un bloque <script>
	 * inline (76 líneas, líneas 1071-1146 originales: auto-scroll bounce
	 * + polling 60s + smooth scroll del accordion).
	 *
	 * Fix: el bloque fue eliminado FÍSICAMENTE del template (ver
	 * LECCIONES_APRENDIDAS #141 — canarios mentirosos en comment blocks:
	 * la migración debe ser física, no un comment que declare "fue
	 * eliminado"). Este test detecta la regresión si alguien reintroduce
	 * un <script> inline en el template.
	 */
	public function test_007_bloque_script_inline_eliminado_csp(): void {
		$this->assertFileExists( $this->template_path );
		$src = file_get_contents( $this->template_path );

		$this->assertStringNotContainsString(
			'<script',
			$src,
			'AUDIT-FE-OT-005 fix: order-tracking.php must NOT contain any <script> tag (inline JS breaks CSP-compliance; behaviours live in ltms-plaza-viva.js scope TRACKING)'
		);
		$this->assertStringNotContainsString(
			'</script>',
			$src,
			'AUDIT-FE-OT-005 fix: order-tracking.php must NOT contain </script> closing tag'
		);

		// El wrapper sigue exponiendo los data-attributes que alimenta el
		// scope TRACKING migrado (si alguien los elimina, el polling y el
		// bounce dejan de funcionar silenciosamente).
		$this->assertStringContainsString(
			'data-order-id=',
			$src,
			'AUDIT-FE-OT-005: wrapper must keep data-order-id (consumed by trackingScope in ltms-plaza-viva.js)'
		);
		$this->assertStringContainsString(
			'data-current-step=',
			$src,
			'AUDIT-FE-OT-005: wrapper must keep data-current-step (consumed by trackingScope in ltms-plaza-viva.js)'
		);
	}

	/**
	 * AUDIT-FE-OT-005 (continuación): los 3 behaviours migrados deben
	 * estar presentes en assets/js/ltms-plaza-viva.js como IIFE
	 * trackingScope() (scope TRACKING al final del archivo). Detecta la
	 * regresión inversa: si alguien elimina el scope TRACKING del design
	 * system sin restaurar el inline (o viceversa), la página de
	 * seguimiento pierde auto-scroll/polling/accordion-scroll.
	 */
	public function test_008_scope_tracking_presente_en_design_system_js(): void {
		$this->assertFileExists( $this->js_path );
		$js = file_get_contents( $this->js_path );

		// (1) IIFE trackingScope presente.
		$this->assertStringContainsString(
			'function trackingScope',
			$js,
			'AUDIT-FE-OT-005 fix: el scope TRACKING debe estar migrado a ltms-plaza-viva.js como IIFE trackingScope()'
		);

		// (2) Selector del scope — solo inicializa en la página tracking.
		$this->assertStringContainsString(
			"querySelector('.pv-scope.pv-tracking')",
			$js,
			'AUDIT-FE-OT-005: trackingScope debe inicializarse solo cuando .pv-scope.pv-tracking está presente en el DOM'
		);

		// (3) Behaviour 1: bounce del paso activo via IntersectionObserver.
		$this->assertStringContainsString(
			"querySelector('.pv-timeline-step--active')",
			$js,
			'AUDIT-FE-OT-005: behaviour 1 (auto-scroll bounce del paso activo) debe leer .pv-timeline-step--active'
		);

		// (4) Behaviour 2: polling lee los data-attributes del wrapper PHP.
		$this->assertStringContainsString(
			"getAttribute('data-current-step')",
			$js,
			'AUDIT-FE-OT-005: behaviour 2 (live refresh) debe leer data-current-step del wrapper'
		);
		$this->assertStringContainsString(
			"getAttribute('data-order-id')",
			$js,
			'AUDIT-FE-OT-005: behaviour 2 (live refresh) debe leer data-order-id del wrapper'
		);

		// (5) Behaviour 3: smooth scroll del summary toggle.
		$this->assertStringContainsString(
			"querySelector('.pv-tracking__summary-toggle')",
			$js,
			'AUDIT-FE-OT-005: behaviour 3 (smooth scroll accordion) debe escuchar .pv-tracking__summary-toggle'
		);

		// (6) Traza del fix para auditorías futuras.
		$this->assertStringContainsString(
			'AUDIT-FE-OT-005',
			$js,
			'AUDIT-FE-OT-005: ltms-plaza-viva.js must contain the traceable fix marker comment for future audits'
		);
	}

	/**
	 * AUDIT-FE-OT-005 (sincronización .min.js): ltms-plaza-viva.min.js se
	 * genera con terser (`npm run build:js`) y es el archivo que sirve WP
	 * cuando está optimizado. Debe contener el scope TRACKING migrado —
	 * si alguien edita el source y no regenera el min, producción pierde
	 * los behaviours. Terser manglea variables pero preserva los string
	 * literals, por lo que las invariantes son sobre selectores/attrs.
	 */
	public function test_009_min_js_sincronizado_con_scope_tracking(): void {
		$this->assertFileExists( $this->js_min_path );
		$min = file_get_contents( $this->js_min_path );

		$this->assertStringContainsString(
			'.pv-scope.pv-tracking',
			$min,
			'AUDIT-FE-OT-005: ltms-plaza-viva.min.js desactualizado — regenerar con npm run build:js (falta selector .pv-scope.pv-tracking)'
		);
		$this->assertStringContainsString(
			'data-current-step',
			$min,
			'AUDIT-FE-OT-005: ltms-plaza-viva.min.js desactualizado — falta lectura de data-current-step (polling)'
		);
		$this->assertStringContainsString(
			'.pv-tracking__summary-toggle',
			$min,
			'AUDIT-FE-OT-005: ltms-plaza-viva.min.js desactualizado — falta .pv-tracking__summary-toggle (accordion scroll)'
		);
	}

	/**
	 * Re-audit (invariante de paridad): los hooks ltms_order_tracking_access,
	 * ltms_tracking_timeline_steps y ltms_tracking_carrier_url siguen
	 * presentes en el template después de los fixes AUDIT-FE-OT-001/002/003.
	 * Los fixes NO debieron eliminarlos — solo cambiaron las fuentes de
	 * los datos, no las válvulas de extensión.
	 *
	 * NOTA: AUDIT-FE-OT-004 (P2, hooks orphan documentado en backlog)
	 * reporta que NINGUNO de estos hooks tiene consumidores en includes/,
	 * pero se preservan como extension valve (no se eliminan; el backlog
	 * P2 queda documentado en CHANGELOG).
	 */
	public function test_re_audit_005_hooks_extension_preservados(): void {
		$this->assertFileExists( $this->template_path );
		$src = file_get_contents( $this->template_path );

		$this->assertStringContainsString(
			"apply_filters( 'ltms_order_tracking_access'",
			$src,
			'AUDIT-FE-OT re-audit: ltms_order_tracking_access filter must remain (extension valve, not removed by IDOR fix)'
		);
		$this->assertStringContainsString(
			"apply_filters( 'ltms_tracking_timeline_steps'",
			$src,
			'AUDIT-FE-OT re-audit: ltms_tracking_timeline_steps filter must remain (extension valve, not removed by rediseño del timeline)'
		);
		$this->assertStringContainsString(
			"apply_filters( 'ltms_tracking_carrier_url'",
			$src,
			'AUDIT-FE-OT re-audit: ltms_tracking_carrier_url filter must remain (extension valve for carrier URL routing)'
		);
		$this->assertStringContainsString(
			"do_action( 'ltms_before_tracking_plazaviva'",
			$src,
			'AUDIT-FE-OT re-audit: ltms_before_tracking_plazaviva action must remain (extension valve for pre-render hooks)'
		);
	}

	/**
	 * Re-audit (regresión del header): el header del template aún muestra
	 * número de orden, fecha, total y status — los fixes OT-001/002/003
	 * son de la lógica de acceso/timeline/auto-reload, no del header.
	 * Cualquier atributo del header debe seguir presente.
	 */
	public function test_re_audit_006_header_orden_intacto(): void {
		$this->assertFileExists( $this->template_path );
		$src = file_get_contents( $this->template_path );

		// El contenedor superior sigue exponiendo data-order-id y
		// data-current-step (consumidos por el bloque de auto-reload).
		$this->assertStringContainsString(
			'data-order-id=',
			$src,
			'AUDIT-FE-OT re-audit: header scope container must keep data-order-id (consumed by auto-reload block)'
		);
		$this->assertStringContainsString(
			'data-current-step=',
			$src,
			'AUDIT-FE-OT re-audit: header scope container must keep data-current-step (consumed by auto-reload block)'
		);

		// El badge de status nativo de WC sigue presente.
		$this->assertStringContainsString(
			'wc_get_order_status_name( $order_status )',
			$src,
			'AUDIT-FE-OT re-audit: header badge must still show WC native status name'
		);
	}
}
