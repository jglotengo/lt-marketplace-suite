<?php
/**
 * AuditCiclo18AveonlineHubListenerFixesTest - Tests para los fixes del Ciclo 18.
 *
 * Cubre los fixes aplicados a
 * includes/business/listeners/class-ltms-aveonline-hub-listener.php
 * (LTMS_Aveonline_Hub_Listener - listener del hook generico
 * `ltms_report_shipment_status_to_hub` que reporta cambios de estado de
 * envios gestionados directamente por Lo Tengo - domiciliario propio,
 * pickup en tienda, etc. - al sistema Ave-Hub de Aveonline). Modulo
 * critico de integracion logistica: si un evento se pierde, Ave-Hub no
 * actualiza el estado del envio -> el cliente/vendedor ve estado
 * desactualizado en la UI de Aveonline, posibles reclamos de SLA, y
 * en el caso de estados finales (ENTREGADO), no se dispara la
 * conciliacion logica del mensaje de Ave-Hub -> el ciclo del envio
 * no cierra en el lado de Aveonline).
 *
 * El listener fue упомionado en el Ciclo 13 como pendiente ("12
 * try/catch blocks - alta densidad") pero el archivo actual tiene 1
 * solo try/catch (el archivo fue refactorizado reduciendolo; el dato
 * del checkpoint previo era de una version mucho mas vieja). La
 * auditoria real encontro 5 P1 + 3 P2:
 *
 *   - AO-051 P1: set_transient (linea 92 pre-fix) se ejecutaba ANTES
 *     de push_events. Si el proceso moria entre set_transient y
 *     push (timeout, OOM, kill), o si push_events lanzaba excepcion,
 *     el evento quedaba marcado como "ya procesado" por 1h en el
 *     transient -> el reintento dentro de esa ventana se descartaba
 *     -> el evento se perdia (no llegaba a Ave-Hub, no enconaba
 *     estado en Aveonline).
 *     Fix: MOVER set_transient al success path DESPUES de push_events
 *     exitoso. Si push lanza o el proceso muere antes, el transient
 *     NO se setea -> el reintento puede ocurrir en cualquier momento
 *     (dentro de la hora el event_id generado es el mismo -> mismo
 *     cache_key -> transient no seteado -> reintento OK; en la
 *     siguiente hora el bucket cambia y se genera nuevo event_id,
 *     tambien reintento OK). Trade-off aceptable: si Ave-Hub
 *     responde 201 pero el POST tarda y el caller re-intenta en
 *     paralelo (race condition), el segundo disparo genera un nuevo
 *     event_id en el bucket actual (mismo segundo) y produce un
 *     POST duplicado -> mitigado por Idempotency-Key en push_events()
 *     (linea 162 del API client).
 *   - AO-052 P1 (DOC/GUARD): si el caller pasa $extra['event_id']
 *     explicito, ese event_id se usa SIN bucketing por hora. El
 *     caller asume responsabilidad de unicity dentro de su
 *     dominio. Reintentos >1h con el MISMO event_id externo SI se
 *     deduplican (transient 1h). Si el caller necesita reintentar
 *     despues de 1h, debe pasar event_id distinto. Fix: documentar
 *     este contrato en el bloque de comentario AO-BUG-8 FIX.
 *   - AO-053 P1: success path (linea 112 pre-fix) llamaba
 *     LTMS_Business_Aveonline_Hub_Log::record() sin capturar su
 *     retorno. Si el INSERT en lt_aveonline_hub_push_log fallaba
 *     (false = error DB, 0 = sin filas), el push SI llego a Ave-Hub
 *     pero NO quedo auditoria local -> el panel admin Ave-Hub no
 *     muestra el evento -> diagnostico futuro/imposible. Mismo
 *     defecto que OP-050 del Ciclo 17.
 *     Fix: capturar $log_id + check ! $log_id + log critico
 *     AVEONLINE_HUB_LOG_INSERT_FAILED con info de reconciliacion
 *     manual. El push NO se reintenta (ya exito en Ave-Hub).
 *   - AO-054 P1: idtransportadora check (linea 94 pre-fix) ocurria
 *     DESPUES del set_transient (linea 92). Si idtransportadora=0
 *     (no configurado), el return limpio descartaba el envio pero
 *     transient ya estaba seteado -> el reintento en la siguiente
 *     ventana de bucket (1h) podia generar nuevo event_id y
 *     reintentar (ruido), pero el problema real era combinado con
 *     AO-051: el set_transient Anti-pattern atacaba todos los
 *     early-returns por igual. Fix: con AO-051 resuelto (mover
 *     transient al final), este return es safe-retry: no hay
 *     transient seteado, el reintento puede ocurrir.
 *   - AO-055 P1: class_exists('LTMS_Api_Aveonline_Hub') (linea 70
 *     pre-fix) mismo patron que AO-054. Fix: mismoAO-051 resuelve
 *       el problema subyacente (early return antes de transient
 *         seteado).
 *
 * Hallazgos P2 backlog (NO fixeados en este ciclo):
 *   - AO-056 P2: push_events() y get_logs() en el API client hacen
 *     doble get_token()+refresh_token() en case de 401. get_token()
 *     ya hace refresh interno si el token esta por expirar. El
 *     retry 401 es duplicado logico. No rompe nada. Backlog.
 *   - AO-058 P2: dbDelta con CREATE TABLE IF NOT EXISTS puede no
 *     recrear indices correctamente si la tabla ya existe con
 *     schema viejo. Estado actual del plugin: tabla ya creada en
 *     activaciones pasadas, no requiere migracion. Backlog.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types = 1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * @covers AO-051, AO-052, AO-053, AO-054, AO-055
 */
class AuditCiclo18AveonlineHubListenerFixesTest extends LTMS_Unit_Test_Case {

	private const AO_PATH = __DIR__ . '/../../includes/business/listeners/class-ltms-aveonline-hub-listener.php';

	protected function setUp(): void {
		parent::setUp();

		Functions\stubs( [
			'sanitize_text_field' => static fn( string $s ): string => $s,
			'__'                  => static fn( string $s ): string => $s,
			'wp_unslash'          => static fn( $v ) => is_string( $v ) ? stripslashes( $v ) : $v,
		] );
	}

	protected function tearDown(): void {
		\LTMS_Core_Config::flush_cache();
		parent::tearDown();
	}

	// -- AO-055 P1: class_exists check antes de cualquier transient --

	/**
	 * El check class_exists('LTMS_Api_Aveonline_Hub') debe ocurrir
	 * ANTES del get_transient/set_transient. Verifica orden relativo
	 * en el source.
	 */
	public function test_class_exists_check_occurs_before_transient_setget(): void {
		$this->assertFileExists( self::AO_PATH );
		$source = file_get_contents( self::AO_PATH );

		$method_pos = strpos( $source, 'function on_status_reported' );
		$this->assertNotFalse( $method_pos, 'on_status_reported debe existir.' );

		// El metodo mide ~5,800 bytes post-fix.
		$method_block = substr( $source, $method_pos, 9000 );

		$class_check_pos = strpos( $method_block, "class_exists( 'LTMS_Api_Aveonline_Hub' )" );
		$this->assertNotFalse( $class_check_pos, 'AO-055: class_exists check debe estar presente.' );

		$get_transient_pos = strpos( $method_block, 'get_transient( $cache_key )' );
		$this->assertNotFalse( $get_transient_pos, 'get_transient debe estar presente.' );

		$this->assertLessThan(
			$get_transient_pos,
			$class_check_pos,
			'AO-055: class_exists check debe ocurrir ANTES de get_transient (linea 70 pre-fix era despues).'
		);
	}

	/**
	 * Debe haber un tag CICLO18-P1-AO-055 FIX en el comentario del
	 * class_exists check.
	 */
	public function test_ao055_fix_tag_present(): void {
		$this->assertFileExists( self::AO_PATH );
		$source = file_get_contents( self::AO_PATH );

		$this->assertStringContainsString(
			'CICLO18-P1-AO-055 FIX',
			$source,
			'AO-055: tag de trazabilidad CICLO18-P1-AO-055 FIX debe estar en el source.'
		);
	}

	// -- AO-054 P1: idtransportadora check antes de cualquier transient --

	/**
	 * El check $id_transportadora > 0 debe ocurrir ANTES del
	 * set_transient. Post-fix, set_transient ocurre DESPUES del
	 * push, asi que este check es safe-retry.
	 */
	public function test_idtransportadora_check_before_set_transient(): void {
		$this->assertFileExists( self::AO_PATH );
		$source = file_get_contents( self::AO_PATH );

		$method_pos = strpos( $source, 'function on_status_reported' );
		$this->assertNotFalse( $method_pos, 'on_status_reported debe existir.' );

		$method_block = substr( $source, $method_pos, 9000 );

		$id_check_pos = strpos( $method_block, 'if ( ! $id_transportadora )' );
		$this->assertNotFalse( $id_check_pos, 'AO-054: check id_transportadora debe estar presente.' );

		$set_transient_pos = strpos( $method_block, 'set_transient( $cache_key, true, HOUR_IN_SECONDS )' );
		$this->assertNotFalse( $set_transient_pos, 'set_transient debe estar presente.' );

		$this->assertLessThan(
			$set_transient_pos,
			$id_check_pos,
			'AO-054: idtransportadora check debe ocurrir ANTES de set_transient (no debe transient-setear antes de validar config).'
		);
	}

	/**
	 * Tag CICLO18-P1-AO-054 FIX presente.
	 */
	public function test_ao054_fix_tag_present(): void {
		$this->assertFileExists( self::AO_PATH );
		$source = file_get_contents( self::AO_PATH );

		$this->assertStringContainsString(
			'CICLO18-P1-AO-054 FIX',
			$source,
			'AO-054: tag de trazabilidad debe estar presente.'
		);
	}

	// -- AO-051 P1: set_transient MOVED to success path (post push) --

	/**
	 * set_transient debe ocurrir DESPUES de push_events, no antes.
	 */
	public function test_set_transient_occurs_after_push_events(): void {
		$this->assertFileExists( self::AO_PATH );
		$source = file_get_contents( self::AO_PATH );

		$method_pos = strpos( $source, 'function on_status_reported' );
		$this->assertNotFalse( $method_pos, 'on_status_reported debe existir.' );

		$method_block = substr( $source, $method_pos, 9000 );

		$push_pos = strpos( $method_block, '$client->push_events( [ $event ] )' );
		$this->assertNotFalse( $push_pos, 'push_events call debe estar presente.' );

		$set_transient_pos = strpos( $method_block, 'set_transient( $cache_key, true, HOUR_IN_SECONDS )' );
		$this->assertNotFalse( $set_transient_pos, 'set_transient debe estar presente.' );

		$this->assertLessThan(
			$set_transient_pos,
			$push_pos,
			'AO-051: push_events debe ocurrir ANTES de set_transient (fix mueve transient al success path post-push).'
		);
	}

	/**
	 * set_transient debe estar DENTRO del try block (success path),
	 * no en el catch.
	 */
	public function test_set_transient_is_in_try_not_catch(): void {
		$this->assertFileExists( self::AO_PATH );
		$source = file_get_contents( self::AO_PATH );

		$method_pos = strpos( $source, 'function on_status_reported' );
		$this->assertNotFalse( $method_pos, 'on_status_reported debe existir.' );

		$method_block = substr( $source, $method_pos, 9000 );

		$try_pos = strpos( $method_block, 'try {' );
		$this->assertNotFalse( $try_pos, 'try block debe existir.' );

		$catch_pos = strpos( $method_block, '} catch ( \Throwable $e ) {' );
		$this->assertNotFalse( $catch_pos, 'catch block debe existir.' );

		$set_transient_pos = strpos( $method_block, 'set_transient( $cache_key, true, HOUR_IN_SECONDS )' );
		$this->assertNotFalse( $set_transient_pos, 'set_transient debe estar presente.' );

		$this->assertGreaterThan( $try_pos, $set_transient_pos, 'set_transient debe estar despues del try {' );
		$this->assertLessThan( $catch_pos, $set_transient_pos, 'AO-051: set_transient debe estar DENTRO del try (success path), no en el catch.' );
	}

	/**
	 * El catch NO debe llamar set_transient (permite retries).
	 */
	public function test_catch_block_does_not_set_transient(): void {
		$this->assertFileExists( self::AO_PATH );
		$source = file_get_contents( self::AO_PATH );

		$method_pos = strpos( $source, 'function on_status_reported' );
		$this->assertNotFalse( $method_pos, 'on_status_reported debe existir.' );

		$method_block = substr( $source, $method_pos, 9000 );

		$catch_pos = strpos( $method_block, '} catch ( \Throwable $e ) {' );
		$this->assertNotFalse( $catch_pos, 'catch block debe existir.' );

		// Bloque catch: del catch_pos hasta el final del metodo (la
		// siguiente llave cerrada del metodo). Tomar 1200 chars para
		// cubrir todo el catch (comentarios + cuerpo).
		$catch_block = substr( $method_block, $catch_pos, 2000 );

		$this->assertStringNotContainsString(
			'set_transient( $cache_key',
			$catch_block,
			'AO-051: el catch NO debe setear transient (debe permitir retries). El fix mueve set_transient solo al success path.'
		);
	}

	/**
	 * Tag CICLO18-P1-AO-051 FIX presente.
	 */
	public function test_ao051_fix_tag_present(): void {
		$this->assertFileExists( self::AO_PATH );
		$source = file_get_contents( self::AO_PATH );

		$this->assertStringContainsString(
			'CICLO18-P1-AO-051 FIX',
			$source,
			'AO-051: tag de trazabilidad debe estar presente.'
		);
	}

	// -- AO-053 P1: success path captura retorno de record() --

	/**
	 * En el success path, el record() debe capturarse en $log_id.
	 */
	public function test_success_path_captures_record_return(): void {
		$this->assertFileExists( self::AO_PATH );
		$source = file_get_contents( self::AO_PATH );

		$method_pos = strpos( $source, 'function on_status_reported' );
		$this->assertNotFalse( $method_pos, 'on_status_reported debe existir.' );

		$method_block = substr( $source, $method_pos, 9000 );

		$this->assertStringContainsString(
			'$log_id = LTMS_Business_Aveonline_Hub_Log::record(',
			$method_block,
			'AO-053: success path debe capturar el retorno de record() en $log_id (no statement suelta).'
		);
	}

	/**
	 * Verifica explicita ! $log_id en el success path.
	 */
	public function test_success_path_checks_log_id_falsy(): void {
		$this->assertFileExists( self::AO_PATH );
		$source = file_get_contents( self::AO_PATH );

		$method_pos = strpos( $source, 'function on_status_reported' );
		$this->assertNotFalse( $method_pos, 'on_status_reported debe existir.' );

		$method_block = substr( $source, $method_pos, 9000 );

		$this->assertStringContainsString(
			'if ( ! $log_id )',
			$method_block,
			'AO-053: debe haber verificacion explicita if ( ! $log_id ) que detecta INSERT fallido (false|0).'
		);
	}

	/**
	 * Log critico AVEONLINE_HUB_LOG_INSERT_FAILED en success path.
	 */
	public function test_success_path_logs_critical_insert_failed(): void {
		$this->assertFileExists( self::AO_PATH );
		$source = file_get_contents( self::AO_PATH );

		$method_pos = strpos( $source, 'function on_status_reported' );
		$this->assertNotFalse( $method_pos, 'on_status_reported debe existir.' );

		$method_block = substr( $source, $method_pos, 9000 );

		$this->assertStringContainsString(
			"'AVEONLINE_HUB_LOG_INSERT_FAILED'",
			$method_block,
			'AO-053: debe haber log critico AVEONLINE_HUB_LOG_INSERT_FAILED en success path (INSERT fallido del Hub Log).'
		);
	}

	/**
	 * Tag CICLO18-P1-AO-053 FIX presente.
	 */
	public function test_ao053_fix_tag_present(): void {
		$this->assertFileExists( self::AO_PATH );
		$source = file_get_contents( self::AO_PATH );

		$this->assertStringContainsString(
			'CICLO18-P1-AO-053 FIX',
			$source,
			'AO-053: tag de trazabilidad debe estar presente.'
		);
	}

	// -- AO-057 P1: error path tambien valida record() return --

	/**
	 * En el catch, el record() tambien debe capturarse en $log_id.
	 */
	public function test_catch_path_captures_record_return(): void {
		$this->assertFileExists( self::AO_PATH );
		$source = file_get_contents( self::AO_PATH );

		$method_pos = strpos( $source, 'function on_status_reported' );
		$this->assertNotFalse( $method_pos, 'on_status_reported debe existir.' );

		$method_block = substr( $source, $method_pos, 9000 );

		$catch_pos = strpos( $method_block, '} catch ( \Throwable $e ) {' );
		$this->assertNotFalse( $catch_pos, 'catch block debe existir.' );

		// Bloque catch: 1200 chars cubre AO-057 FIX + record() en catch.
		$catch_block = substr( $method_block, $catch_pos, 2000 );

		$this->assertStringContainsString(
			'$log_id = LTMS_Business_Aveonline_Hub_Log::record(',
			$catch_block,
			'AO-057: catch path debe capturar el retorno de record() en $log_id (no statement suelta).'
		);
	}

	/**
	 * Catch path verifica ! $log_id.
	 */
	public function test_catch_path_checks_log_id_falsy(): void {
		$this->assertFileExists( self::AO_PATH );
		$source = file_get_contents( self::AO_PATH );

		$method_pos = strpos( $source, 'function on_status_reported' );
		$this->assertNotFalse( $method_pos, 'on_status_reported debe existir.' );

		$method_block = substr( $source, $method_pos, 9000 );

		$catch_pos = strpos( $method_block, '} catch ( \Throwable $e ) {' );
		$this->assertNotFalse( $catch_pos, 'catch block debe existir.' );

		$catch_block = substr( $method_block, $catch_pos, 2000 );

		$this->assertStringContainsString(
			'if ( ! $log_id )',
			$catch_block,
			'AO-057: catch path debe verificar if ( ! $log_id ).'
		);
	}

	/**
	 * Tag CICLO18-P1-AO-057 FIX presente (en catch).
	 */
	public function test_ao057_fix_tag_present(): void {
		$this->assertFileExists( self::AO_PATH );
		$source = file_get_contents( self::AO_PATH );

		$method_pos = strpos( $source, 'function on_status_reported' );
		$this->assertNotFalse( $method_pos, 'on_status_reported debe existir.' );

		$method_block = substr( $source, $method_pos, 9000 );

		$catch_pos = strpos( $method_block, '} catch ( \Throwable $e ) {' );
		$this->assertNotFalse( $catch_pos, 'catch block debe existir.' );

		$catch_block = substr( $method_block, $catch_pos, 2000 );

		$this->assertStringContainsString(
			'CICLO18-P1-AO-057 FIX',
			$catch_block,
			'AO-057: tag de trazabilidad debe estar presente en el catch.'
		);
	}

	// -- AO-052 P1: documentacion de event_id externo sin bucketing --

	/**
	 * Debe existir DOC/GUARD explicando event_id externo.
	 */
	public function test_ao052_doc_guard_present(): void {
		$this->assertFileExists( self::AO_PATH );
		$source = file_get_contents( self::AO_PATH );

		$this->assertStringContainsString(
			'CICLO18-P1-AO-052 DOC/GUARD',
			$source,
			'AO-052: debe existir DOC/GUARD documentando el contrato del event_id externo (sin bucketing).'
		);
	}

	/**
	 * La documentacion debe mencionar event_id DISTINTO para retries >1h.
	 */
	public function test_ao052_doc_mentions_retry_event_id(): void {
		$this->assertFileExists( self::AO_PATH );
		$source = file_get_contents( self::AO_PATH );

		$this->assertStringContainsString(
			'event_id DISTINTO',
			$source,
			'AO-052: el DOC/GUARD debe mencionar que retries >1h requieren event_id DISTINTO.'
		);
	}

	// -- Test estructural: el archivo compila y carga --

	/**
	 * El archivo existe y define la clase LTMS_Aveonline_Hub_Listener.
	 */
	public function test_listener_class_defined(): void {
		$this->assertFileExists( self::AO_PATH );
		$source = file_get_contents( self::AO_PATH );

		$this->assertStringContainsString(
			'final class LTMS_Aveonline_Hub_Listener',
			$source,
			'La clase final LTMS_Aveonline_Hub_Listener debe estar definida.'
		);
	}

	/**
	 * El action hook sigue registrado en init().
	 */
	public function test_hook_still_registered_in_init(): void {
		$this->assertFileExists( self::AO_PATH );
		$source = file_get_contents( self::AO_PATH );

		$this->assertStringContainsString(
			"add_action( 'ltms_report_shipment_status_to_hub', [ __CLASS__, 'on_status_reported' ], 10, 4 )",
			$source,
			'El hook ltms_report_shipment_status_to_hub debe seguir registrado en init().'
		);
	}

	/**
	 * El AO-BUG-8 FIX original sigue presente (idempotency base).
	 */
	public function test_ao_bug8_fix_still_present(): void {
		$this->assertFileExists( self::AO_PATH );
		$source = file_get_contents( self::AO_PATH );

		$this->assertStringContainsString(
			'AO-BUG-8 FIX',
			$source,
			'AO-BUG-8 FIX (idempotency base) debe seguir presente - Ciclo 18 no lo elimina, lo refina con AO-051/052.'
		);
	}

	/**
	 * No debe haber caracteres CJK colados en el source (regla
	 * AGENTS.md "sin CJK/fullwidth" aplica a comments tambien).
	 */
	public function test_no_cjk_characters_in_source(): void {
		$this->assertFileExists( self::AO_PATH );
		$source = file_get_contents( self::AO_PATH );

		// CJK Unified Ideographs (U+4E00-U+9FFF) + Hiragana/Katakana
		// (U+3040-U+30FF) + Fullwidth forms (U+FF00-U+FFEF).
		$cjk_count = preg_match_all( '/[\x{4e00}-\x{9fff}\x{3000}-\x{30ff}\x{ff00}-\x{ffef}]/u', $source );
		$this->assertSame(
			0,
			$cjk_count,
			'AO Ciclo 18: no debe haber caracteres CJK colados en el source (incluido en comments - regla AGENTS.md).'
		);
	}
}
