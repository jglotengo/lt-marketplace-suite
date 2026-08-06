<?php
/**
 * AuditCiclo19XcoverPolicyListenerFixesTest - Tests para los fixes del Ciclo 19.
 *
 * Cubre los fixes aplicados a:
 *   - includes/business/listeners/class-ltms-xcover-policy-listener.php
 *     (LTMS_XCover_Policy_Listener - files/cancels XCover insurance policies
 *     on order events: woocommerce_payment_complete, order_status_completed,
 *     order_status_cancelled, order_status_refunded + ltms_xcover_file_claim
 *     custom action hook).
 *   - includes/api/class-ltms-api-xcover.php (LTMS_Api_XCover - API client
 *     XCover: get_quotes, create_policy, get_policy, cancel_policy,
 *     file_claim (added C19), health_check).
 *
 * El listener orquesta el feature end-to-end de seguros de productos XCover
 * (insurtech): crear poliza al pagar, cancelar al reembolsar, reclamar cuando
 * el customer disputea por dano/perdida. Caller del hook ltms_xcover_file_claim:
 * LTMS_Business_Consumer_Protection::maybe_trigger_insurance_claim.
 *
 * Hallazgos fixeados en este ciclo (todos source-based — el test inspecciona
 * el source real del listener + API client, no reimplementa la logica):
 *
 *   - XP-046 P0: contrato caller/listener roto desde v2.9.179. El caller
 *     do_action('ltms_xcover_file_claim', $policy_id, $dispute_id, $order_id,
 *     $reason) pasa 4 args, pero el listener estaba registrado con
 *     accepted_args=3 y firma ($dispute_id, $order_id, $reason). PHP mapeaba
 *     el primer arg ($policy_id, string "pol_xxx") a int $dispute_id →
 *     TypeError fatal en disputas damaged/lost con poliza asociada (todo el
 *     feature de claims silenciosamente roto en prod desde v2.9.179).
 *     Fix: add_action(..., 10, 4) + firma on_file_claim(string $policy_id,
 *     int $dispute_id, int $order_id, string $reason).
 *   - XP-047 P0: meta key mismatch. El caller lee _ltms_xcover_policy_id
 *     (linea 1458) pero el listener escribia _ltms_insurance_policy_id
 *     (lineas 39, 127). Aunque el hook se invocara correctamente, el caller
 *     no encontraria la poliza (NULL) → do_action nunca se disparaba. Doble
 *     bug P0 con XP-046 — el feature de claims era una cascada de 2 fallos
 *     P0 silenciosos.
 *     Fix: el listener confia en el $policy_id que pasa el caller (validado,
 *     no-vacio) en vez de re-buscar meta key localmente. Eliminada la llamada
 *     a $order->get_meta('_ltms_insurance_policy_id') dentro de on_file_claim.
 *   - XP-048 P1: LTMS_Api_XCover::file_claim() no existia. El listener ya
 *     tenia fallback method_exists() + warning XCOVER_CLAIM_METHOD_MISSING,
 *     pero el feature end-to-end era incompleto — el claim nunca se fileaba
 *     en XCover aun cuando el hook se invocaba con contrato correcto.
 *     Fix: anadido LTMS_Api_XCover::file_claim(array $claim_data) que llama
 *     a POST /partners/{code}/policies/{policy_id}/claims/ con Idempotency-Key
 *     proveida por el listener.
 *   - XP-050 P1: $order->save() no verificado en on_order_paid. Si save()
 *     retornaba false (DB timeout, replica lag), el meta
 *     _ltms_insurance_policy_created NO se persistia pero la poliza SI se
 *     creo en XCover → proxima ejecucion del handler (re-entry por hooks WC,
 *     retry de payment, etc.) re-crea la poliza → double policy + double
 *     premium al vendor. Patron recurrente de los Ciclos 5-18.
 *     Fix: capturar $saved + check false === + log critico
 *     XCOVER_POLICY_META_SAVE_FAILED con SQL de reconciliacion manual.
 *   - XP-051 P1: $wpdb->insert no verificado en record_policy(). Si el INSERT
 *     en lt_insurance_policies fallaba (DB timeout), la poliza se creaba en
 *     XCover + meta se persistia localmente, pero el mirror en
 *     lt_insurance_policies no existia → cancel_policy() no encontraba la
 *     fila → refund/reconciliacion perdido silenciosamente.
 *     Fix: capturar $inserted + check false === + log critico
 *     XCOVER_POLICY_RECORD_INSERT_FAILED con SQL de reconciliacion manual.
 *   - XP-052 P1: $order->save() + $wpdb->update no verificados en
 *     on_order_cancelled. Si save() fallaba, el meta _cancelled NO se
 *     persistia pero la tabla lt_insurance_policies se actualizaba al
 *     status='cancelled' anyway → inconsistencia. Si el $wpdb->update fallaba
 *     (DB error), el meta SI se persistia → retry no ejecuta cancel_policy()
 *     (idempotency check hace bail) pero la tabla local sigue en status='active'.
 *     Fix: save() verificado PRIMERO (si falla, no tocar tabla local). Luego
 *     $wpdb->update verificado — distinguir false (error DB → log critico
 *     XCOVER_POLICY_CANCEL_DB_UPDATE_FAILED con SQL reconciliacion) de 0
 *     (no rows matched → warning XCOVER_POLICY_CANCEL_NO_LOCAL_ROW, poliza
 *     cancelada en XCover pero no mirrorada localmente).
 *   - XP-053 P1: file_claim() sin idempotency_key determinista. Cuando se
 *     implemente (XP-048), un 5xx retry dispararia el handler 2nda vez sin
 *     dedup server-side → double claim.
 *     Fix: construir idempotency_key 'ltms_claim_dispute_{D}_order_{O}' en
 *     el listener y propagar al API client via $claim_data['idempotency_key'].
 *   - XP-054 P1: contexto insuficiente en logs de error del catch (solo
 *     "Order #%d: %s" sin policy_id ni vendor_id para diagnostico traceability).
 *     Fix: anadir policy_id + dispute_id (en on_file_claim) al log del catch
 *     + al de warning method_missing.
 *   - XP-055 P1: en on_file_claim, el meta _ltms_xcover_claim_filed_{dispute_id}
 *     se marcaba ANTES de verificar que file_claim() existiera en el API
 *     client. Con XP-048 ya no es problema (metodo existe), pero se añade
 *     verificacion de $order->save() en el success path del metodo existente
 *     para idempotency meta persistido (mismo patron que XP-050).
 *
 * Hallazgos descartados:
 *   - INSURANCE-AUDIT P0-2 (record_policy duplicate check before INSERT, linea
 *     222): ya fixeado en v2.9.121, no se toca. Cross-check de regresion
 *     incluido en este test.
 *   - M-110/M-112/M-113 (on_order_paid quote_id handling): ya fixeado en
 *     version anterior. Cross-check de regresion incluido.
 *   - v2.9.179 (init add_action ltms_xcover_file_claim): corrige el registro
 *     del hook (aceptaba 3 args, ahora 4 con este fix). Cross-check incluido.
 *   - API-BUG-9 FIX + v2.9.121 INSURANCE-AUDIT P1-1/P1-2 FIX en API client:
 *     ya aplicados en class-ltms-api-xcover.php. Cross-checks incluidos.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types = 1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * @covers XP-046, XP-047, XP-048, XP-050, XP-051, XP-052, XP-053, XP-054, XP-055
 */
class AuditCiclo19XcoverPolicyListenerFixesTest extends LTMS_Unit_Test_Case {

	private const LISTENER_PATH = __DIR__ . '/../../includes/business/listeners/class-ltms-xcover-policy-listener.php';
	private const API_PATH      = __DIR__ . '/../../includes/api/class-ltms-api-xcover.php';

	protected function setUp(): void {
		parent::setUp();

		// NOTA: NO stubear sanitize_textarea_field() aqui — Patchwork la define
		// antes que Brain\Monkey arranque, lo que lanza DefinedTooEarly. La
		// base class LTMS_Unit_Test_Case ya stubea sanitize_text_field, __,
		// wp_unslash, etc. El test es source-based (file_get_contents +
		// strpos + assertStringContainsString) — no necesita invocar
		// sanitize_textarea_field en runtime.
		Functions\stubs( [
			'__' => static fn( string $s ): string => $s,
		] );
	}

	protected function tearDown(): void {
		\LTMS_Core_Config::flush_cache();
		parent::tearDown();
	}

	// -- XP-046 P0: contrato caller/listener — accepted_args=4 + firma 4 args --

	/**
	 * add_action para ltms_xcover_file_claim debe declarar 4 accepted_args.
	 */
	public function test_init_registers_file_claim_hook_with_4_args(): void {
		$this->assertFileExists( self::LISTENER_PATH );
		$source = file_get_contents( self::LISTENER_PATH );

		$init_pos = strpos( $source, 'function init(): void' );
		$this->assertNotFalse( $init_pos, 'init() debe existir.' );

		// CICLO19: init() mide ~1000+ bytes post-fix (incluye el comment
		// v2.9.179 + el comment CICLO19-P0-XP-046 FIX explicando accepted_args=4).
		$init_block = substr( $source, $init_pos, 1500 );

		$this->assertStringContainsString(
			"'ltms_xcover_file_claim'",
			$init_block,
			'XP-046: el hook ltms_xcover_file_claim debe estar registrado.'
		);

		// Verificar accepted_args=4 explicito. El tag de fix debe estar cerca.
		$this->assertStringContainsString(
			"'ltms_xcover_file_claim', [ __CLASS__, 'on_file_claim' ], 10, 4",
			$init_block,
			'XP-046: add_action debe declarar accepted_args=4 (no 3) — el caller pasa ($policy_id, $dispute_id, $order_id, $reason).'
		);
	}

	/**
	 * La firma de on_file_claim debe aceptar 4 params con tipos correctos.
	 */
	public function test_on_file_claim_signature_has_4_params(): void {
		$this->assertFileExists( self::LISTENER_PATH );
		$source = file_get_contents( self::LISTENER_PATH );

		// Usar single quotes para evitar interpolacion PHP y \T escape issues.
		$this->assertStringContainsString(
			'public static function on_file_claim( string $policy_id, int $dispute_id, int $order_id, string $reason ): void {',
			$source,
			'XP-046: on_file_claim debe tener firma (string $policy_id, int $dispute_id, int $order_id, string $reason) — alineada al caller.'
		);
	}

	/**
	 * El tag CICLO19-P0-XP-046 FIX debe estar presente (es 2 tags: add_action + docblock).
	 */
	public function test_xp046_fix_tags_present(): void {
		$this->assertFileExists( self::LISTENER_PATH );
		$source = file_get_contents( self::LISTENER_PATH );

		$this->assertStringContainsString( 'CICLO19-P0-XP-046 FIX', $source );
		$this->assertGreaterThan(
			1,
			substr_count( $source, 'CICLO19-P0-XP-046 FIX' ),
			'XP-046: debe tener al menos 2 tags (add_action + docblock).'
		);
	}

	// -- XP-047 P0: meta key mismatch — listener confia en $policy_id del caller --

	/**
	 * on_file_claim NO debe re-buscar el policy_id con get_meta local.
	 * Confia en el $policy_id que pasa el caller (que ya valido su existencia).
	 */
	public function test_on_file_claim_does_not_re_fetch_policy_id_meta(): void {
		$this->assertFileExists( self::LISTENER_PATH );
		$source = file_get_contents( self::LISTENER_PATH );

		$method_pos = strpos( $source, 'function on_file_claim' );
		$this->assertNotFalse( $method_pos, 'on_file_claim debe existir.' );

		// on_file_claim mide ~3,800 bytes post-fix.
		// CICLO19: on_file_claim mide ~6,226 bytes post-fix (firma 4 args +
		// idempotency_key + save() verificado + 2 log paths con SQLs). 7000
		// para cubrir todo el metodo.
		$method_block = substr( $source, $method_pos, 7000 );

		// NO debe haber get_meta('_ltms_insurance_policy_id') dentro de on_file_claim.
		$this->assertStringNotContainsString(
			"_ltms_insurance_policy_id",
			$method_block,
			'XP-047: on_file_claim no debe re-buscar _ltms_insurance_policy_id (meta key mismatch con caller que usa _ltms_xcover_policy_id). Confia en el $policy_id del caller.'
		);

		// El early bail en policy_id vacio debe existir.
		$this->assertStringContainsString(
			'if ( ! $policy_id ) return;',
			$method_block,
			'XP-047: debe hacer bail silencioso si $policy_id llega vacio (caller no encontro poliza).'
		);
	}

	/**
	 * El tag CICLO19-P0-XP-047 FIX debe estar presente.
	 */
	public function test_xp047_fix_tags_present(): void {
		$this->assertFileExists( self::LISTENER_PATH );
		$source = file_get_contents( self::LISTENER_PATH );

		$this->assertStringContainsString( 'CICLO19-P0-XP-047 FIX', $source );
	}

	// -- XP-048 P1: LTMS_Api_XCover::file_claim() añadido al API client --

	/**
	 * El metodo file_claim debe existir en el API client.
	 */
	public function test_api_client_has_file_claim_method(): void {
		$this->assertFileExists( self::API_PATH );
		$source = file_get_contents( self::API_PATH );

		$this->assertStringContainsString(
			'public function file_claim( array $claim_data ): array {',
			$source,
			'XP-048: LTMS_Api_XCover::file_claim(array $claim_data) debe existir como metodo publico.'
		);
	}

	/**
	 * file_claim debe validar policy_id con preg_match (path traversal guard).
	 */
	public function test_api_file_claim_validates_policy_id(): void {
		$this->assertFileExists( self::API_PATH );
		$source = file_get_contents( self::API_PATH );

		$method_pos = strpos( $source, 'function file_claim' );
		$this->assertNotFalse( $method_pos, 'file_claim debe existir.' );

		$method_block = substr( $source, $method_pos, 2000 );

		// CICLO19 GOTCHA linea 79 checkpoint: usar single quotes en strings con
		// $ (dolar) — double quotes interpola $policy_id como variable PHP
		// (Undefined variable) y deja el string vacio. Single quote → literal.
		$this->assertStringContainsString(
			'preg_match( \'/^[A-Za-z0-9_\\-]{1,128}$/\', $policy_id )',
			$method_block,
			'XP-048: file_claim debe validar policy_id con preg_match antes de construir URL path (mismo patron que get_policy/cancel_policy).'
		);
	}

	/**
	 * file_claim debe propagar Idempotency-Key header.
	 */
	public function test_api_file_claim_propagates_idempotency_key(): void {
		$this->assertFileExists( self::API_PATH );
		$source = file_get_contents( self::API_PATH );

		$method_pos = strpos( $source, 'function file_claim' );
		$this->assertNotFalse( $method_pos );

		$method_block = substr( $source, $method_pos, 2500 );

		$this->assertStringContainsString(
			"'Idempotency-Key' => \$idem_key",
			$method_block,
			'XP-048: file_claim debe propagar Idempotency-Key header a perform_request.'
		);

		// Debe priorizar la key proveida por el listener.
		$this->assertStringContainsString(
			"\$claim_data['idempotency_key']",
			$method_block,
			'XP-048: file_claim debe priorizar la idempotency_key del caller (listener).'
		);
	}

	/**
	 * El tag CICLO19-P1-XP-048 FIX debe estar presente en el API client.
	 */
	public function test_xp048_fix_tag_present(): void {
		$this->assertFileExists( self::API_PATH );
		$source = file_get_contents( self::API_PATH );

		$this->assertStringContainsString( 'CICLO19-P1-XP-048', $source );
	}

	// -- XP-050 P1: $order->save() verificado en on_order_paid --

	/**
	 * on_order_paid debe capturar $order->save() en $saved.
	 */
	public function test_on_order_paid_captures_save_in_saved_var(): void {
		$this->assertFileExists( self::LISTENER_PATH );
		$source = file_get_contents( self::LISTENER_PATH );

		$method_pos = strpos( $source, 'function on_order_paid' );
		$this->assertNotFalse( $method_pos, 'on_order_paid debe existir.' );

		// on_order_paid mide ~3,200 bytes post-fix.
		$method_block = substr( $source, $method_pos, 4000 );

		$this->assertStringContainsString(
			'$saved = $order->save();',
			$method_block,
			'XP-050: el save() debe capturar su retorno en $saved (no ser statement suelta).'
		);
	}

	/**
	 * Debe haber verificacion false === $saved explicita.
	 */
	public function test_on_order_paid_checks_save_false_explicitly(): void {
		$this->assertFileExists( self::LISTENER_PATH );
		$source = file_get_contents( self::LISTENER_PATH );

		$method_pos = strpos( $source, 'function on_order_paid' );
		$this->assertNotFalse( $method_pos );

		$method_block = substr( $source, $method_pos, 4000 );

		$this->assertStringContainsString(
			'false === $saved',
			$method_block,
			'XP-050: check explicito false === $saved debe estar presente para detectar save() fallido.'
		);
	}

	/**
	 * El log critical debe ser XCOVER_POLICY_META_SAVE_FAILED.
	 */
	public function test_on_order_paid_save_failure_logs_critical(): void {
		$this->assertFileExists( self::LISTENER_PATH );
		$source = file_get_contents( self::LISTENER_PATH );

		$token_pos = strpos( $source, "'XCOVER_POLICY_META_SAVE_FAILED'," );
		$this->assertNotFalse( $token_pos, 'XP-050: log XCOVER_POLICY_META_SAVE_FAILED debe existir.' );

		// Tomar 250 chars antes del token — debe contener ::critical( o ::error(.
		$before_token = substr( $source, max( 0, $token_pos - 250 ), 250 );
		$this->assertStringContainsString(
			'::error(',
			$before_token,
			'XP-050: el log debe ser ::error (Logger_Aware expone ::error aqui, no ::critical).'
		);
	}

	/**
	 * El log debe incluir SQL de reconciliacion manual INSERT INTO %spostmeta.
	 */
	public function test_on_order_paid_save_failure_log_includes_reconciliation_sql(): void {
		$this->assertFileExists( self::LISTENER_PATH );
		$source = file_get_contents( self::LISTENER_PATH );

		$this->assertStringContainsString(
			'INSERT INTO %spostmeta',
			$source,
			'XP-050: log debe incluir SQL de reconciliacion INSERT INTO %spostmeta (manual meta persist).'
		);

		$this->assertStringContainsString(
			'_ltms_insurance_policy_created',
			$source,
			'XP-050: SQL de reconciliacion debe mencionar _ltms_insurance_policy_created meta key.'
		);
	}

	/**
	 * El tag CICLO19-P1-XP-050 FIX debe estar presente.
	 */
	public function test_xp050_fix_tags_present(): void {
		$this->assertFileExists( self::LISTENER_PATH );
		$source = file_get_contents( self::LISTENER_PATH );

		$this->assertStringContainsString( 'CICLO19-P1-XP-050 FIX', $source );
	}

	// -- XP-051 P1: $wpdb->insert verificado en record_policy --

	/**
	 * record_policy debe capturar $wpdb->insert en $inserted.
	 */
	public function test_record_policy_captures_insert_in_inserted_var(): void {
		$this->assertFileExists( self::LISTENER_PATH );
		$source = file_get_contents( self::LISTENER_PATH );

		$method_pos = strpos( $source, 'function record_policy' );
		$this->assertNotFalse( $method_pos, 'record_policy debe existir.' );

		// record_policy mide ~4,000 bytes post-fix.
		// CICLO19: on_file_claim mide ~6,226 bytes post-fix (firma 4 args +
		// idempotency_key + save() verificado + 2 log paths con SQLs). 7000
		// para cubrir todo el metodo.
		$method_block = substr( $source, $method_pos, 7000 );

		$this->assertStringContainsString(
			'$inserted = $wpdb->insert(',
			$method_block,
			'XP-051: el INSERT debe capturar su retorno en $inserted (no ser statement suelta).'
		);
	}

	/**
	 * Debe haber verificacion false === $inserted.
	 */
	public function test_record_policy_checks_insert_false_explicitly(): void {
		$this->assertFileExists( self::LISTENER_PATH );
		$source = file_get_contents( self::LISTENER_PATH );

		$method_pos = strpos( $source, 'function record_policy' );
		$this->assertNotFalse( $method_pos );

		// CICLO19: on_file_claim mide ~6,226 bytes post-fix (firma 4 args +
		// idempotency_key + save() verificado + 2 log paths con SQLs). 7000
		// para cubrir todo el metodo.
		$method_block = substr( $source, $method_pos, 7000 );

		$this->assertStringContainsString(
			'false === $inserted',
			$method_block,
			'XP-051: check explicito false === $inserted debe estar presente.'
		);
	}

	/**
	 * El log critical debe ser XCOVER_POLICY_RECORD_INSERT_FAILED.
	 */
	public function test_record_policy_failure_logs_critical(): void {
		$this->assertFileExists( self::LISTENER_PATH );
		$source = file_get_contents( self::LISTENER_PATH );

		$token_pos = strpos( $source, "'XCOVER_POLICY_RECORD_INSERT_FAILED'," );
		$this->assertNotFalse( $token_pos, 'XP-051: log XCOVER_POLICY_RECORD_INSERT_FAILED debe existir.' );

		$before_token = substr( $source, max( 0, $token_pos - 250 ), 250 );
		$this->assertStringContainsString(
			'::error(',
			$before_token,
			'XP-051: el log debe ser ::error (no warning).'
		);
	}

	/**
	 * El log debe incluir SQL de reconciliacion INSERT INTO %slt_insurance_policies.
	 */
	public function test_record_policy_log_includes_reconciliation_sql(): void {
		$this->assertFileExists( self::LISTENER_PATH );
		$source = file_get_contents( self::LISTENER_PATH );

		$this->assertStringContainsString(
			'INSERT INTO %slt_insurance_policies',
			$source,
			'XP-051: log debe incluir SQL de reconciliacion INSERT INTO lt_insurance_policies.'
		);
	}

	/**
	 * El tag CICLO19-P1-XP-051 FIX debe estar presente.
	 */
	public function test_xp051_fix_tag_present(): void {
		$this->assertFileExists( self::LISTENER_PATH );
		$source = file_get_contents( self::LISTENER_PATH );

		$this->assertStringContainsString( 'CICLO19-P1-XP-051 FIX', $source );
	}

	// -- XP-052 P1: $order->save() + $wpdb->update verificados en on_order_cancelled --

	/**
	 * on_order_cancelled debe capturar $order->save() en $saved.
	 */
	public function test_on_order_cancelled_captures_save_in_saved_var(): void {
		$this->assertFileExists( self::LISTENER_PATH );
		$source = file_get_contents( self::LISTENER_PATH );

		$method_pos = strpos( $source, 'function on_order_cancelled' );
		$this->assertNotFalse( $method_pos, 'on_order_cancelled debe existir.' );

		// on_order_cancelled mide ~4,500 bytes post-fix.
		$method_block = substr( $source, $method_pos, 5000 );

		$this->assertStringContainsString(
			'$saved = $order->save();',
			$method_block,
			'XP-052: el save() debe capturar su retorno en $saved antes de tocar la tabla lt_insurance_policies.'
		);
	}

	/**
	 * El save() debe verificarse ANTES del $wpdb->update (orden importa).
	 */
	public function test_on_order_cancelled_save_check_before_wpdb_update(): void {
		$this->assertFileExists( self::LISTENER_PATH );
		$source = file_get_contents( self::LISTENER_PATH );

		$method_pos = strpos( $source, 'function on_order_cancelled' );
		$this->assertNotFalse( $method_pos );

		$method_block = substr( $source, $method_pos, 5000 );

		$save_check_pos    = strpos( $method_block, 'false === $saved' );
		$wpdb_update_pos   = strpos( $method_block, '$updated = $wpdb->update' );

		$this->assertNotFalse( $save_check_pos, 'XP-052: debe haber check false === $saved.' );
		$this->assertNotFalse( $wpdb_update_pos, 'XP-052: debe haber $updated = $wpdb->update.' );
		$this->assertLessThan(
			$wpdb_update_pos,
			$save_check_pos,
			'XP-052: el check false === $saved debe aparecer ANTES del $wpdb->update (si save falla, no tocar la tabla local).'
		);
	}

	/**
	 * $wpdb->update debe capturarse en $updated.
	 */
	public function test_on_order_cancelled_captures_wpdb_update_in_updated_var(): void {
		$this->assertFileExists( self::LISTENER_PATH );
		$source = file_get_contents( self::LISTENER_PATH );

		$method_pos = strpos( $source, 'function on_order_cancelled' );
		$this->assertNotFalse( $method_pos );

		$method_block = substr( $source, $method_pos, 5000 );

		$this->assertStringContainsString(
			'$updated = $wpdb->update(',
			$method_block,
			'XP-052: el $wpdb->update debe capturar su retorno en $updated.'
		);
	}

	/**
	 * Debe distinguir false (error DB) de 0 (no rows matched).
	 */
	public function test_on_order_cancelled_distinguishes_false_from_zero_wpdb_update(): void {
		$this->assertFileExists( self::LISTENER_PATH );
		$source = file_get_contents( self::LISTENER_PATH );

		$method_pos = strpos( $source, 'function on_order_cancelled' );
		$this->assertNotFalse( $method_pos );

		$method_block = substr( $source, $method_pos, 5000 );

		$this->assertStringContainsString(
			'false === $updated',
			$method_block,
			'XP-052: check explicito false === $updated debe estar presente (error DB).'
		);

		$this->assertStringContainsString(
			'0 === $updated',
			$method_block,
			'XP-052: check explicito 0 === $updated debe estar presente (no rows matched — poliza no mirrorada localmente).'
		);
	}

	/**
	 * El log critico debe ser XCOVER_POLICY_CANCEL_DB_UPDATE_FAILED.
	 */
	public function test_on_order_cancelled_update_failure_logs_critical(): void {
		$this->assertFileExists( self::LISTENER_PATH );
		$source = file_get_contents( self::LISTENER_PATH );

		$token_pos = strpos( $source, "'XCOVER_POLICY_CANCEL_DB_UPDATE_FAILED'," );
		$this->assertNotFalse( $token_pos, 'XP-052: log XCOVER_POLICY_CANCEL_DB_UPDATE_FAILED debe existir.' );

		$before_token = substr( $source, max( 0, $token_pos - 250 ), 250 );
		$this->assertStringContainsString(
			'::error(',
			$before_token,
			'XP-052: el log debe ser ::error.'
		);
	}

	/**
	 * Debe existir warning XCOVER_POLICY_CANCEL_NO_LOCAL_ROW (path 0 rows).
	 */
	public function test_on_order_cancelled_zero_rows_logs_warning(): void {
		$this->assertFileExists( self::LISTENER_PATH );
		$source = file_get_contents( self::LISTENER_PATH );

		$token_pos = strpos( $source, "'XCOVER_POLICY_CANCEL_NO_LOCAL_ROW'," );
		$this->assertNotFalse( $token_pos, 'XP-052: warning XCOVER_POLICY_CANCEL_NO_LOCAL_ROW debe existir.' );

		$before_token = substr( $source, max( 0, $token_pos - 250 ), 250 );
		$this->assertStringContainsString(
			'::warning(',
			$before_token,
			'XP-052: el log 0 rows debe ser ::warning (no critico).'
		);
	}

	/**
	 * El log critico de save() fallido debe ser XCOVER_POLICY_CANCEL_META_SAVE_FAILED.
	 */
	public function test_on_order_cancelled_save_failure_logs_critical(): void {
		$this->assertFileExists( self::LISTENER_PATH );
		$source = file_get_contents( self::LISTENER_PATH );

		$token_pos = strpos( $source, "'XCOVER_POLICY_CANCEL_META_SAVE_FAILED'," );
		$this->assertNotFalse( $token_pos, 'XP-052: log XCOVER_POLICY_CANCEL_META_SAVE_FAILED debe existir.' );

		$before_token = substr( $source, max( 0, $token_pos - 250 ), 250 );
		$this->assertStringContainsString(
			'::error(',
			$before_token,
			'XP-052: el log save fail debe ser ::error.'
		);
	}

	/**
	 * El tag CICLO19-P1-XP-052 FIX debe estar presente.
	 */
	public function test_xp052_fix_tag_present(): void {
		$this->assertFileExists( self::LISTENER_PATH );
		$source = file_get_contents( self::LISTENER_PATH );

		$this->assertStringContainsString( 'CICLO19-P1-XP-052 FIX', $source );
	}

	// -- XP-053 P1: idempotency_key determinista en on_file_claim --

	/**
	 * on_file_claim debe construir idempotency_key determinista.
	 */
	public function test_on_file_claim_builds_deterministic_idempotency_key(): void {
		$this->assertFileExists( self::LISTENER_PATH );
		$source = file_get_contents( self::LISTENER_PATH );

		$method_pos = strpos( $source, 'function on_file_claim' );
		$this->assertNotFalse( $method_pos );

		// CICLO19: on_file_claim mide ~6,226 bytes post-fix (firma 4 args +
		// idempotency_key + save() verificado + 2 log paths con SQLs). 7000
		// para cubrir todo el metodo.
		$method_block = substr( $source, $method_pos, 7000 );

		$this->assertStringContainsString(
			"'ltms_claim_dispute_' . \$dispute_id . '_order_' . \$order_id",
			$method_block,
			'XP-053: idempotency_key determinista ltms_claim_dispute_{D}_order_{O} debe construirse.'
		);

		$this->assertStringContainsString(
			"'idempotency_key' => \$idem_key",
			$method_block,
			'XP-053: la idempotency_key debe propagarse al claim_data enviado al API client.'
		);
	}

	/**
	 * El tag CICLO19-P1-XP-053 FIX debe estar presente.
	 */
	public function test_xp053_fix_tag_present(): void {
		$this->assertFileExists( self::LISTENER_PATH );
		$source = file_get_contents( self::LISTENER_PATH );

		$this->assertStringContainsString( 'CICLO19-P1-XP-053 FIX', $source );
	}

	// -- XP-054 P1: contexto full en logs de error/warning --

	/**
	 * El warning XCOVER_CLAIM_METHOD_MISSING debe incluir policy_id.
	 */
	public function test_xcover_method_missing_warning_includes_policy_id(): void {
		$this->assertFileExists( self::LISTENER_PATH );
		$source = file_get_contents( self::LISTENER_PATH );

		$method_pos = strpos( $source, 'function on_file_claim' );
		$this->assertNotFalse( $method_pos );

		// CICLO19: on_file_claim mide ~6,226 bytes post-fix (firma 4 args +
		// idempotency_key + save() verificado + 2 log paths con SQLs). 7000
		// para cubrir todo el metodo.
		$method_block = substr( $source, $method_pos, 7000 );

		$warning_pos = strpos( $method_block, "'XCOVER_CLAIM_METHOD_MISSING'," );
		$this->assertNotFalse( $warning_pos );

		// Tomar 600 chars despues del warning — el sprintf debe mencionar policy %s.
		$after_warning = substr( $method_block, $warning_pos, 600 );

		$this->assertStringContainsString(
			'$policy_id',
			$after_warning,
			'XP-054: el warning XCOVER_CLAIM_METHOD_MISSING debe incluir $policy_id en el sprintf (contexto para diagnostico).'
		);
	}

	/**
	 * El error XCOVER_CLAIM_FILE_FAILED del catch debe incluir policy_id.
	 */
	public function test_xcover_claim_file_failed_includes_policy_id(): void {
		$this->assertFileExists( self::LISTENER_PATH );
		$source = file_get_contents( self::LISTENER_PATH );

		$method_pos = strpos( $source, 'function on_file_claim' );
		$this->assertNotFalse( $method_pos );

		// CICLO19: on_file_claim mide ~6,226 bytes post-fix (firma 4 args +
		// idempotency_key + save() verificado + 2 log paths con SQLs). 7000
		// para cubrir todo el metodo.
		$method_block = substr( $source, $method_pos, 7000 );

		$error_pos = strpos( $method_block, "'XCOVER_CLAIM_FILE_FAILED'," );
		$this->assertNotFalse( $error_pos );

		// Tomar 600 chars despues del error — el sprintf debe mencionar Policy %s.
		$after_error = substr( $method_block, $error_pos, 600 );

		$this->assertStringContainsString(
			'$policy_id',
			$after_error,
			'XP-054: el error XCOVER_CLAIM_FILE_FAILED debe incluir $policy_id en el sprintf.'
		);
	}

	/**
	 * El tag CICLO19-P1-XP-054 FIX debe estar presente.
	 */
	public function test_xp054_fix_tag_present(): void {
		$this->assertFileExists( self::LISTENER_PATH );
		$source = file_get_contents( self::LISTENER_PATH );

		$this->assertStringContainsString( 'CICLO19-P1-XP-054 FIX', $source );
	}

	// -- XP-055 P1: $order->save() verificado en success path de on_file_claim --

	/**
	 * on_file_claim debe capturar $order->save() en success path del method_exists branch.
	 */
	public function test_on_file_claim_captures_save_in_success_path(): void {
		$this->assertFileExists( self::LISTENER_PATH );
		$source = file_get_contents( self::LISTENER_PATH );

		$method_pos = strpos( $source, 'function on_file_claim' );
		$this->assertNotFalse( $method_pos );

		// CICLO19: on_file_claim mide ~6,226 bytes post-fix (firma 4 args +
		// idempotency_key + save() verificado + 2 log paths con SQLs). 7000
		// para cubrir todo el metodo.
		$method_block = substr( $source, $method_pos, 7000 );

		$this->assertStringContainsString(
			'$saved = $order->save();',
			$method_block,
			'XP-055: el save() debe capturar su retorno en $saved en el success path del method_exists branch.'
		);
	}

	/**
	 * Debe haber verificacion false === $saved en on_file_claim.
	 */
	public function test_on_file_claim_checks_save_false_explicitly(): void {
		$this->assertFileExists( self::LISTENER_PATH );
		$source = file_get_contents( self::LISTENER_PATH );

		$method_pos = strpos( $source, 'function on_file_claim' );
		$this->assertNotFalse( $method_pos );

		// CICLO19: on_file_claim mide ~6,226 bytes post-fix (firma 4 args +
		// idempotency_key + save() verificado + 2 log paths con SQLs). 7000
		// para cubrir todo el metodo.
		$method_block = substr( $source, $method_pos, 7000 );

		$this->assertStringContainsString(
			'false === $saved',
			$method_block,
			'XP-055: check explicito false === $saved debe estar presente en on_file_claim.'
		);
	}

	/**
	 * El log XCOVER_CLAIM_META_SAVE_FAILED debe existir.
	 */
	public function test_on_file_claim_has_claim_meta_save_failed_log(): void {
		$this->assertFileExists( self::LISTENER_PATH );
		$source = file_get_contents( self::LISTENER_PATH );

		$this->assertStringContainsString(
			"'XCOVER_CLAIM_META_SAVE_FAILED',",
			$source,
			'XP-055: log XCOVER_CLAIM_META_SAVE_FAILED debe existir.'
		);
	}

	/**
	 * El tag CICLO19-P1-XP-049 FIX debe estar presente (XP-055 aplica el mismo
	 * patron de save() verificado en on_file_claim — el tag DOC/GUARD de XP-049
	 * cubre la decision de no marcar meta en method_missing path).
	 */
	public function test_xp049_fix_tag_present(): void {
		$this->assertFileExists( self::LISTENER_PATH );
		$source = file_get_contents( self::LISTENER_PATH );

		$this->assertStringContainsString( 'CICLO19-P1-XP-049 FIX', $source );
	}

	// -- Cross-checks: fixes previos intactos (regression guards) --

	/**
	 * Cross-check: el INSURANCE-AUDIT P0-2 FIX (duplicate check en record_policy)
	 * debe seguir presente (v2.9.121). Regresion guard — el fix XP-051 anade
	 * verificacion de INSERT pero NO debe eliminar el duplicate check previo.
	 */
	public function test_insurance_audit_p0_2_duplicate_check_still_present(): void {
		$this->assertFileExists( self::LISTENER_PATH );
		$source = file_get_contents( self::LISTENER_PATH );

		$this->assertStringContainsString(
			'INSURANCE-AUDIT P0-2 FIX',
			$source,
			'Cross-check: INSURANCE-AUDIT P0-2 FIX (duplicate check) debe seguir presente.'
		);

		$this->assertStringContainsString(
			'XCOVER_POLICY_DUPLICATE_SKIP',
			$source,
			'Cross-check: log XCOVER_POLICY_DUPLICATE_SKIP debe seguir presente.'
		);
	}

	/**
	 * Cross-check: los M-110/M-112/M-113 FIX (quote_id handling en on_order_paid)
	 * deben seguir presentes. Regresion guard — el fix XP-050 anade save()
	 * verification pero NO debe eliminar el quote_id preservation.
	 */
	public function test_m113_quote_id_preservation_still_present(): void {
		$this->assertFileExists( self::LISTENER_PATH );
		$source = file_get_contents( self::LISTENER_PATH );

		$this->assertStringContainsString(
			'M-113',
			$source,
			'Cross-check: M-113 FIX (preservar quote_id antes de API call) debe seguir presente.'
		);

		$this->assertStringContainsString(
			'M-110/M-112',
			$source,
			'Cross-check: M-110/M-112 FIX (separar quote_id del payload de cliente) debe seguir presente.'
		);
	}

	/**
	 * Cross-check: el comment v2.9.179 sobre el registro del hook debe seguir presente.
	 */
	public function test_v2_9_179_registration_comment_still_present(): void {
		$this->assertFileExists( self::LISTENER_PATH );
		$source = file_get_contents( self::LISTENER_PATH );

		$this->assertStringContainsString(
			'v2.9.179',
			$source,
			'Cross-check: el comment v2.9.179 sobre el registro del hook ltms_xcover_file_claim debe seguir presente.'
		);
	}

	/**
	 * Cross-check: el API-BUG-9 FIX (Idempotency-Key en create_policy) debe seguir presente en API client.
	 */
	public function test_api_bug_9_create_policy_idempotency_still_present(): void {
		$this->assertFileExists( self::API_PATH );
		$source = file_get_contents( self::API_PATH );

		$this->assertStringContainsString(
			'API-BUG-9 FIX',
			$source,
			'Cross-check: API-BUG-9 FIX (Idempotency-Key en create_policy) debe seguir presente.'
		);
	}

	/**
	 * Cross-check: el v2.9.121 INSURANCE-AUDIT P1-2 FIX (policy_id validation
	 * en cancel_policy) debe seguir presente en API client.
	 */
	public function test_v2_9_121_cancel_policy_validation_still_present(): void {
		$this->assertFileExists( self::API_PATH );
		$source = file_get_contents( self::API_PATH );

		$this->assertStringContainsString(
			'INSURANCE-AUDIT P1-2 FIX',
			$source,
			'Cross-check: v2.9.121 INSURANCE-AUDIT P1-2 FIX (policy_id validation en cancel_policy) debe seguir presente.'
		);
	}

	/**
	 * Cross-check: el INTEGRATIONS-AUDIT P1 FIX (provider_slug='xcover' en API client) debe seguir presente.
	 */
	public function test_integrations_audit_provider_slug_still_present(): void {
		$this->assertFileExists( self::API_PATH );
		$source = file_get_contents( self::API_PATH );

		$this->assertStringContainsString(
			"INTEGRATIONS-AUDIT P1 FIX",
			$source,
			'Cross-check: INTEGRATIONS-AUDIT P1 FIX (provider_slug=xcover) debe seguir presente.'
		);
	}

	/**
	 * Cross-check: los hooks init deben seguir registrados (regresion guard).
	 */
	public function test_init_hooks_still_registered(): void {
		$this->assertFileExists( self::LISTENER_PATH );
		$source = file_get_contents( self::LISTENER_PATH );

		$this->assertStringContainsString(
			"add_action( 'woocommerce_payment_complete'",
			$source,
			'Cross-check: hook woocommerce_payment_complete debe seguir registrado.'
		);

		$this->assertStringContainsString(
			"add_action( 'woocommerce_order_status_completed'",
			$source,
			'Cross-check: hook woocommerce_order_status_completed debe seguir registrado.'
		);

		$this->assertStringContainsString(
			"add_action( 'woocommerce_order_status_cancelled'",
			$source,
			'Cross-check: hook woocommerce_order_status_cancelled debe seguir registrado.'
		);

		$this->assertStringContainsString(
			"add_action( 'woocommerce_order_status_refunded'",
			$source,
			'Cross-check: hook woocommerce_order_status_refunded debe seguir registrado.'
		);
	}

	/**
	 * Cross-check: la linea `if ( ! defined( 'ABSPATH' ) ) exit;` debe seguir presente.
	 */
	public function test_abspath_guard_still_present(): void {
		$this->assertFileExists( self::LISTENER_PATH );
		$source = file_get_contents( self::LISTENER_PATH );

		$this->assertStringContainsString(
			"if ( ! defined( 'ABSPATH' ) ) { exit; }",
			$source,
			'Cross-check: ABSPATH guard debe seguir presente (seguridad).'
		);
	}
}
