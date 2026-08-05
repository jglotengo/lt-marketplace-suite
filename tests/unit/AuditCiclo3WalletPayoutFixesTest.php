<?php
/**
 * AuditCiclo3WalletPayoutFixesTest — Tests para los fixes P0+P1+P2 del Ciclo 3.
 *
 * Cubre los fixes aplicados al nucleo financiero (2 archivos):
 *   - PAYOUTS-EXEC-001 P0: payout-scheduler approve() — UPDATE final a
 *     status='completed' sin verificar $updated. Si fallaba (=== false o === 0),
 *     el payout quedaba en 'processing' para siempre PERO el
 *     do_action('ltms_payout_completed') se disparaba igual → downstream hooks
 *     (affiliate commission, Alegra accounting) se ejecutaban asumiendo wallet
 *     debitado. En retry manual posterior, los hooks se duplicaban (comision
 *     de afiliado doble, asiento Alegra doble).
 *   - PAYOUTS-EXEC-002 P1: catch de wallet_error no verificaba el retorno del
 *     UPDATE al status='processing' — el admin no veia el flag de error y la
 *     wallet error no se persistia.
 *   - PAYOUTS-EXEC-003 P1: ltms_payout_wallet_error se disparaba
 *     incondicionalmente incluso si el UPDATE de error fallaba → downstream
 *     listeners escuchaban 'wallet_error' asumiendo el status persistido.
 *   - PAYOUTS-EXEC-004 P1: tras fallo de gateway, SELECT notes + UPDATE en
 *     dos queries separadas — race window: dos admins concurrentes podian
 *     ambos leer notes=vacio, ambos appendar su error, el segundo sobrescribia
 *     el primero → perdida del primer error de auditoria.
 *   - PAYOUTS-EXEC-005 P1: reject() tenia SELECT notes + UPDATE no atomicos.
 *     Race condition: dos admins concurrentes podian ambos pasar el guard
 *     status==='pending' del inicio y ambos ejectuar UPDATE a 'rejected' →
 *     ltms_payout_rejected disparado dos veces, dos emails al vendor, dos
 *     asientos Alegra de reversion.
 *   - PAYOUTS-EXEC-006 P1: cron process_pending_payouts llamaba
 *     approve($payout_id, 0) — approved_by=0 rompia trazabilidad fiscal
 *     (Art. 30-B CFF exige responsable). Ahora usa el primer admin del
 *     marketplace.
 *   - W-P2-1 Wallet: UPDATE de saldo no detectaba $updated === 0 (wallet
 *     desaparecido o stale) — INSERT siguiente podia crear tx huerfana.
 *   - W-P2-2 Wallet: $wpdb->insert_id=0 no se detectaba — caller persistia
 *     $tx_id=0 rompiendo journal_post.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types = 1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * @covers PAYOUTS-EXEC-001, PAYOUTS-EXEC-002, PAYOUTS-EXEC-003,
 *         PAYOUTS-EXEC-004, PAYOUTS-EXEC-005, PAYOUTS-EXEC-006,
 *         W-P2-1, W-P2-2
 */
class AuditCiclo3WalletPayoutFixesTest extends LTMS_Unit_Test_Case {

    private const PAYOUT_SCHEDULER_PATH = __DIR__ . '/../../includes/business/class-ltms-payout-scheduler.php';
    private const WALLET_PATH           = __DIR__ . '/../../includes/business/class-ltms-wallet.php';

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

    // ── PAYOUTS-EXEC-001 P0: verificar $updated tras UPDATE a 'completed' ──

    /**
     * El UPDATE a status='completed' debe capturar su retorno en una variable
     * ($completed_updated) y verificarlo antes de disparar ltms_payout_completed.
     * Antes, el UPDATE se ejecutaba sin verificar $updated, y el hook se disparaba
     * incondicionalmente — si el UPDATE fallaba, los downstream hooks se ejecutaban
     * asumiendo wallet debitado (comision affiliate doble, asiento Alegra doble
     * en retry manual).
     */
    public function test_payout_completed_update_checks_affected_rows(): void {
        $this->assertFileExists( self::PAYOUT_SCHEDULER_PATH );
        $source = file_get_contents( self::PAYOUT_SCHEDULER_PATH );

        // El UPDATE final debe capturar el retorno en $completed_updated.
        $this->assertStringContainsString(
            '$completed_updated = $wpdb->update(',
            $source,
            'PAYOUTS-EXEC-001: el UPDATE a status=completed debe capturar su retorno en $completed_updated para verificar affected_rows.'
        );

        // Debe verificar === false Y === 0.
        $this->assertStringContainsString(
            '$completed_updated === false || $completed_updated === 0',
            $source,
            'PAYOUTS-EXEC-001: verificar $completed_updated === false (error DB) y === 0 (fila no encontrada).'
        );

        // Si el UPDATE falla, NO disparar ltms_payout_completed — disparar
        // ltms_payout_wallet_error para requerir reconciliacion manual.
        $this->assertStringContainsString(
            'PAYOUT_COMPLETED_UPDATE_FAILED',
            $source,
            'PAYOUTS-EXEC-001: log crítico PAYOUT_COMPLETED_UPDATE_FAILED debe estar presente para diagnostico.'
        );
    }

    /**
     * El hook ltms_payout_completed solo debe dispararse en el path exitoso
     * (después de verificar el UPDATE). El flujo de error debe usar
     * ltms_payout_wallet_error (no ltms_payout_completed).
     */
    public function test_payout_completed_hook_only_after_successful_update(): void {
        $this->assertFileExists( self::PAYOUT_SCHEDULER_PATH );
        $source = file_get_contents( self::PAYOUT_SCHEDULER_PATH );

        // do_action('ltms_payout_completed') debe estar DESPUES del bloque
        // if ($completed_updated === false || === 0) que retorna early.
        // Verificamos la posicion relativa: el return del path de error
        // debe aparecer antes del do_action de exito.
        $error_block_pos = strpos( $source, 'PAYOUT_COMPLETED_UPDATE_FAILED' );
        $success_hook_pos = strpos( $source, "do_action( 'ltms_payout_completed'" );

        $this->assertNotFalse( $error_block_pos, 'PAYOUTS-EXEC-001: bloque de error PAYOUT_COMPLETED_UPDATE_FAILED debe existir.' );
        $this->assertNotFalse( $success_hook_pos, 'ltms_payout_completed hook debe seguir presente para el path exitoso.' );
        $this->assertGreaterThan(
            $error_block_pos,
            $success_hook_pos,
            'PAYOUTS-EXEC-001: ltms_payout_completed debe dispararse DESPUES del bloque de error (que retorna early), garantizando que solo se llame si el UPDATE persistio.'
        );
    }

    // ── PAYOUTS-EXEC-002/003 P1: catch wallet_error verificar $updated ──────

    /**
     * El UPDATE del catch de wallet_error debe capturar su retorno en
     * $wallet_err_updated y verificarlo. Si falla, NO disparar
     * ltms_payout_wallet_error (los listeners no tienen forma de actuar
     * sobre un error no persistido).
     */
    public function test_wallet_error_catch_verifies_update_and_guards_hook(): void {
        $this->assertFileExists( self::PAYOUT_SCHEDULER_PATH );
        $source = file_get_contents( self::PAYOUT_SCHEDULER_PATH );

        // El UPDATE del catch debe capturar retorno en $wallet_err_updated.
        $this->assertStringContainsString(
            '$wallet_err_updated = $wpdb->update(',
            $source,
            'PAYOUTS-EXEC-002: el UPDATE del catch de wallet_error debe capturar su retorno en $wallet_err_updated.'
        );

        // Verificar === false o === 0.
        $this->assertStringContainsString(
            '$wallet_err_updated === false || $wallet_err_updated === 0',
            $source,
            'PAYOUTS-EXEC-002: verificar $wallet_err_updated === false (error DB) o === 0 (fila no encontrada).'
        );

        // Log critico para monitoreo cuando el UPDATE de flag de error falla.
        $this->assertStringContainsString(
            'PAYOUT_WALLET_ERROR_UPDATE_FAILED',
            $source,
            'PAYOUTS-EXEC-002: log PAYOUT_WALLET_ERROR_UPDATE_FAILED para diagnostico cuando el UPDATE de flag falla.'
        );

        // El do_action('ltms_payout_wallet_error') solo debe ejecutarse en
        // la rama else (UPDATE exitoso). Verificamos que NO este en la rama if.
        // El patron: if (... === false || ... === 0) { log } else { do_action }.
        $this->assertStringContainsString(
            '} else {',
            $source,
            'PAYOUTS-EXEC-003: el hook ltms_payout_wallet_error debe ir en una rama else (solo si el UPDATE persistio).'
        );
    }

    /**
     * El do_action('ltms_payout_wallet_error') debe estar en la rama else
     * del check de $wallet_err_updated, no en la rama if (path de fallo).
     * Verificacion estructural: la rama if contiene el log
     * PAYOUT_WALLET_ERROR_UPDATE_FAILED, no el do_action.
     */
    public function test_wallet_error_hook_not_in_failure_branch(): void {
        $this->assertFileExists( self::PAYOUT_SCHEDULER_PATH );
        $source = file_get_contents( self::PAYOUT_SCHEDULER_PATH );

        // Encontrar el bloque del check de $wallet_err_updated.
        $check_pos = strpos( $source, '$wallet_err_updated === false || $wallet_err_updated === 0' );
        $this->assertNotFalse( $check_pos, 'PAYOUTS-EXEC-003: check de $wallet_err_updated debe existir.' );

        // Tomar el bloque que sigue al check (2000 chars suficientes para ver
        // la rama if completa con su log + la rama else con el hook).
        $block = substr( $source, $check_pos, 2000 );

        // La rama if (path de fallo del UPDATE) contiene el log
        // PAYOUT_WALLET_ERROR_UPDATE_FAILED. La rama else contiene el
        // do_action('ltms_payout_wallet_error').
        $log_failure_pos = strpos( $block, 'PAYOUT_WALLET_ERROR_UPDATE_FAILED' );
        $hook_pos        = strpos( $block, "do_action( 'ltms_payout_wallet_error'" );
        $else_pos        = strpos( $block, '} else {' );

        $this->assertNotFalse( $log_failure_pos, 'PAYOUTS-EXEC-003: log PAYOUT_WALLET_ERROR_UPDATE_FAILED debe estar en la rama if.' );
        $this->assertNotFalse( $hook_pos, 'PAYOUTS-EXEC-003: hook ltms_payout_wallet_error debe seguir presente.' );
        $this->assertNotFalse( $else_pos, 'PAYOUTS-EXEC-003: debe haber una rama else que contiene el hook.' );

        // Log va primero (en la rama if), else va despues, hook va despues del else.
        $this->assertGreaterThan( $log_failure_pos, $else_pos, 'PAYOUTS-EXEC-003: else debe ir despues del log de fallo.' );
        $this->assertGreaterThan( $else_pos, $hook_pos, 'PAYOUTS-EXEC-003: hook ltms_payout_wallet_error debe estar en la rama else (no en la rama if de fallo).' );
    }

    // ── PAYOUTS-EXEC-004 P1: atomicizar SELECT notes + UPDATE tras gateway fail

    /**
     * Tras fallo de gateway, antes habia un SELECT notes seguido de UPDATE
     * no atomicos. El fix usa CONCAT() en un solo UPDATE statement para
     * appendar el error atómicamente.
     */
    public function test_gateway_fail_update_uses_atomic_concat(): void {
        $this->assertFileExists( self::PAYOUT_SCHEDULER_PATH );
        $source = file_get_contents( self::PAYOUT_SCHEDULER_PATH );

        // El fix usa CONCAT en el SET del UPDATE.
        $this->assertStringContainsString(
            'CONCAT( IFNULL(notes',
            $source,
            'PAYOUTS-EXEC-004: el UPDATE tras fallo de gateway debe usar CONCAT(IFNULL(notes,...)) para append atomico (sin SELECT previo).'
        );

        // El SELECT notes previo debe haberse eliminado (no debe quedar el
        // patron $existing_notes = $wpdb->get_var(... SELECT notes ...).
        // Verificamos que la linea que antes hacia el SELECT ya no existe en
        // el contexto del reset a pending.
        $this->assertStringNotContainsString(
            '$existing_notes = $wpdb->get_var',
            $source,
            'PAYOUTS-EXEC-004: SELECT notes previo eliminado — ahora se append atómicamente en el UPDATE.'
        );

        // El UPDATE debe capturar retorno en $gateway_fail_updated.
        $this->assertStringContainsString(
            '$gateway_fail_updated = $wpdb->query(',
            $source,
            'PAYOUTS-EXEC-004: UPDATE tras fallo de gateway debe capturar su retorno en $gateway_fail_updated.'
        );

        // Verificar retorno === false (error DB).
        $this->assertStringContainsString(
            '$gateway_fail_updated === false',
            $source,
            'PAYOUTS-EXEC-004: verificar $gateway_fail_updated === false (error DB).'
        );

        // Log critico si el UPDATE de reset tambien falla.
        $this->assertStringContainsString(
            'PAYOUT_GATEWAY_FAIL_UPDATE_FAILED',
            $source,
            'PAYOUTS-EXEC-004: log PAYOUT_GATEWAY_FAIL_UPDATE_FAILED cuando el UPDATE de reset a pending falla.'
        );
    }

    // ── PAYOUTS-EXEC-005 P1: reject() atomic claim + eliminar SELECT notes ──

    /**
     * reject() debe usar atomic claim `AND status = 'pending'` en el WHERE
     * del UPDATE (no solo `id = $payout_id`) + verificar affected_rows.
     * Mismo patron M-117 de approve().
     */
    public function test_reject_uses_atomic_claim_with_status_in_where(): void {
        $this->assertFileExists( self::PAYOUT_SCHEDULER_PATH );
        $source = file_get_contents( self::PAYOUT_SCHEDULER_PATH );

        // El UPDATE de reject debe tener `status' => 'pending'` en el WHERE.
        $this->assertStringContainsString(
            "[ 'id' => \$payout_id, 'status' => 'pending' ]",
            $source,
            'PAYOUTS-EXEC-005: atomic claim con status=pending en WHERE del UPDATE de reject (no solo id).'
        );

        // El UPDATE debe capturar retorno en $rejected_rows.
        $this->assertStringContainsString(
            '$rejected_rows = $wpdb->update(',
            $source,
            'PAYOUTS-EXEC-005: UPDATE de reject debe capturar su retorno en $rejected_rows.'
        );

        // Verificar === false o === 0.
        $this->assertStringContainsString(
            '$rejected_rows === false || $rejected_rows === 0',
            $source,
            'PAYOUTS-EXEC-005: verificar $rejected_rows === false (error DB) o === 0 (otro admin gano la carrera).'
        );

        // Log informativo cuando se pierde la carrera atomica.
        $this->assertStringContainsString(
            'PAYOUT_REJECT_RACE_LOST',
            $source,
            'PAYOUTS-EXEC-005: log PAYOUT_REJECT_RACE_LOST cuando se pierde la carrera atomica.'
        );
    }

    /**
     * El SELECT notes previo redundante debe haberse eliminado de reject()
     * (si notes no esta en el SET, MySQL lo deja intacto) y del path de
     * gateway_fail (que ahora usa CONCAT atómico).
     *
     * Nota: el SELECT notes en el path de error del P0-EXEC-001 (UPDATE a
     * 'completed' falló) sigue presente legítimamente para appendar la nota
     * de reconciliación manual — ese path es raro y no se beneficia de CONCAT.
     */
    public function test_reject_eliminates_redundant_select_notes(): void {
        $this->assertFileExists( self::PAYOUT_SCHEDULER_PATH );
        $source = file_get_contents( self::PAYOUT_SCHEDULER_PATH );

        // El bloque del UPDATE de reject no debe contener SELECT notes previo.
        // Buscamos el bloque del reject UPDATE y verificamos los 400 chars
        // antes (donde estaria el SELECT si siguiera existiendo).
        $reject_update_pos = strpos( $source, '$rejected_rows = $wpdb->update(' );
        $this->assertNotFalse( $reject_update_pos, 'PAYOUTS-EXEC-005: UPDATE de reject debe existir.' );
        $before_reject = substr( $source, max( 0, $reject_update_pos - 400 ), 400 );
        $this->assertStringNotContainsString(
            "SELECT notes FROM `{\$table}` WHERE id = %d",
            $before_reject,
            'PAYOUTS-EXEC-005: SELECT notes previo eliminado de reject() — el UPDATE preserva notes intacto al no tocarlo.'
        );

        // El bloque del UPDATE de gateway_fail tampoco debe contener SELECT notes previo.
        $gateway_fail_pos = strpos( $source, '$gateway_fail_updated = $wpdb->query(' );
        $this->assertNotFalse( $gateway_fail_pos, 'PAYOUTS-EXEC-004: UPDATE de gateway_fail debe existir.' );
        $before_gateway_fail = substr( $source, max( 0, $gateway_fail_pos - 400 ), 400 );
        $this->assertStringNotContainsString(
            "SELECT notes FROM `{\$table}` WHERE id = %d",
            $before_gateway_fail,
            'PAYOUTS-EXEC-004: SELECT notes previo eliminado de gateway_fail — se usa CONCAT atomic en su lugar.'
        );

        // El SET del UPDATE de reject NO debe incluir 'notes' (se preserva
        // intacto al no tocarlo). Tomar 400 chars despues del UPDATE.
        $reject_block = substr( $source, $reject_update_pos, 400 );

        // El SET debe contener status, rejection_reason, approved_by, processed_at.
        $this->assertStringContainsString( "'status'           => 'rejected'", $reject_block, 'PAYOUTS-EXEC-005: SET debe contener status.' );
        $this->assertStringContainsString( "'rejection_reason' => \$reason_clean", $reject_block, 'PAYOUTS-EXEC-005: SET debe contener rejection_reason.' );

        // NO debe contener 'notes' en el SET.
        $this->assertStringNotContainsString(
            "'notes'",
            $reject_block,
            'PAYOUTS-EXEC-005: SET del UPDATE de reject NO debe incluir notes — se preserva intacto al no tocarlo.'
        );
    }

    // ── PAYOUTS-EXEC-006 P1: cron approve() con primer admin del marketplace ──

    /**
     * process_pending_payouts debe hacer lookup del primer admin del
     * marketplace via get_users(['role'=>'administrator','number'=>1])
     * y usar su ID como approved_by (no 0).
     */
    public function test_cron_uses_first_admin_for_approved_by(): void {
        $this->assertFileExists( self::PAYOUT_SCHEDULER_PATH );
        $source = file_get_contents( self::PAYOUT_SCHEDULER_PATH );

        // Lookup del primer admin.
        $this->assertStringContainsString(
            "get_users( [ 'role' => 'administrator', 'number' => 1 ] )",
            $source,
            'PAYOUTS-EXEC-006: cron debe hacer lookup del primer admin del marketplace via get_users(["role"=>"administrator","number"=>1]).'
        );

        // Variable $cron_admin_id debe usarse en la llamada a approve().
        $this->assertStringContainsString(
            '$cron_admin_id',
            $source,
            'PAYOUTS-EXEC-006: variable $cron_admin_id debe estar presente.'
        );
        $this->assertStringContainsString(
            'self::approve( (int) $payout[\'id\'], $cron_admin_id )',
            $source,
            'PAYOUTS-EXEC-006: approve() debe recibir $cron_admin_id, no 0.'
        );

        // La llamada anterior a approve() con admin_id=0 hardcoded no debe
        // quedar (es decir, la literal "approve( (int) $payout['id'], 0 )" debe
        // haberse reemplazado por $cron_admin_id).
        $this->assertStringNotContainsString(
            "self::approve( (int) \$payout['id'], 0 )",
            $source,
            'PAYOUTS-EXEC-006: eliminar la llamada hardcoded approve($id, 0) — reemplazada por $cron_admin_id.'
        );

        // Warning si no se encuentra ningún admin.
        $this->assertStringContainsString(
            'PAYOUT_CRON_NO_ADMIN',
            $source,
            'PAYOUTS-EXEC-006: log PAYOUT_CRON_NO_ADMIN si no se encuentra ningún admin (traceabilidad del fallback).'
        );
    }

    // ── W-P2-1 Wallet: verificar $updated === 0 en UPDATE de saldo ──────────

    /**
     * Wallet::execute_transaction debe detectar $updated === 0 en el UPDATE
     * del saldo (wallet desaparecido o stale). Antes, solo se verificaba
     * $updated === false — el INSERT siguiente insertaba una tx con wallet_id
     * stale (FK violation o tx huerfana).
     */
    public function test_wallet_update_detects_zero_affected_rows(): void {
        $this->assertFileExists( self::WALLET_PATH );
        $source = file_get_contents( self::WALLET_PATH );

        // Verificar $updated === 0 con excepcion.
        $this->assertStringContainsString(
            'if ( $updated === 0 )',
            $source,
            'W-P2-1: deteccion explicita de $updated === 0 en UPDATE de saldo de wallet.'
        );

        // Mensaje de excepcion debe mencionar wallet_id y 0 filas.
        $this->assertStringContainsString(
            'UPDATE de saldo afecto 0 filas',
            $source,
            'W-P2-1: mensaje de excepcion debe indicar 0 filas afectadas en UPDATE de saldo.'
        );
    }

    /**
     * W-P2-1: la verificacion $updated === 0 debe estar DESPUES de la
     * verificacion $updated === false (no reemplazarla).
     */
    public function test_wallet_zero_check_after_false_check(): void {
        $this->assertFileExists( self::WALLET_PATH );
        $source = file_get_contents( self::WALLET_PATH );

        $false_check_pos = strpos( $source, 'if ( $updated === false )' );
        $zero_check_pos  = strpos( $source, 'if ( $updated === 0 )' );

        $this->assertNotFalse( $false_check_pos, 'W-P2-1: check $updated === false debe seguir presente.' );
        $this->assertNotFalse( $zero_check_pos, 'W-P2-1: check $updated === 0 debe existir.' );
        $this->assertGreaterThan(
            $false_check_pos,
            $zero_check_pos,
            'W-P2-1: check $updated === 0 debe ir DESPUES de check $updated === false (es complementario, no lo reemplaza).'
        );
    }

    // ── W-P2-2 Wallet: verificar $wpdb->insert_id > 0 tras INSERT del ledger

    /**
     * Tras el INSERT del ledger, $wpdb->insert_id debe ser > 0. Si es 0
     * (caso patologico: driver bug o PK explicita), se lanza excepcion
     * para ROLLBACK transitivo.
     */
    public function test_wallet_verifies_insert_id_positive(): void {
        $this->assertFileExists( self::WALLET_PATH );
        $source = file_get_contents( self::WALLET_PATH );

        // El check $tx_id <= 0 debe existir.
        $this->assertStringContainsString(
            'if ( $tx_id <= 0 )',
            $source,
            'W-P2-2: verificar $tx_id <= 0 tras el INSERT del ledger (insert_id patologicamente 0).'
        );

        // Mensaje de excepcion.
        $this->assertStringContainsString(
            'INSERT del ledger retorno insert_id',
            $source,
            'W-P2-2: mensaje de excepcion debe indicar insert_id inesperado.'
        );
    }

    /**
     * W-P2-2: la verificacion de insert_id debe IR DESPUES de la asignacion
     * $tx_id = $wpdb->insert_id y ANTES del COMMIT.
     */
    public function test_wallet_insert_id_check_before_commit(): void {
        $this->assertFileExists( self::WALLET_PATH );
        $source = file_get_contents( self::WALLET_PATH );

        $insert_id_assign_pos = strpos( $source, '$tx_id = $wpdb->insert_id;' );
        $insert_id_check_pos  = strpos( $source, 'if ( $tx_id <= 0 )' );
        // COMMIT es el primer "COMMIT'" despues del INSERT del ledger.
        $commit_pos           = strpos( $source, "\$wpdb->query( 'COMMIT' )", $insert_id_assign_pos );

        $this->assertNotFalse( $insert_id_assign_pos, 'W-P2-2: $tx_id = $wpdb->insert_id debe existir.' );
        $this->assertNotFalse( $insert_id_check_pos, 'W-P2-2: check $tx_id <= 0 debe existir.' );
        $this->assertNotFalse( $commit_pos, 'W-P2-2: COMMIT debe seguir presente.' );

        $this->assertGreaterThan( $insert_id_assign_pos, $insert_id_check_pos, 'W-P2-2: check $tx_id <= 0 debe ir despues de la asignacion.' );
        $this->assertGreaterThan( $insert_id_check_pos, $commit_pos, 'W-P2-2: check $tx_id <= 0 debe ir ANTES del COMMIT (si falla, se hace ROLLBACK).' );
    }

    // ── Fix tags de trazabilidad ─────────────────────────────────────────────

    /**
     * Todos los fixes del Ciclo 3 deben estar marcados con sus IDs en el
     * codigo fuente (trazabilidad sigue el patron de ciclos previos).
     */
    public function test_fix_tags_present_in_payout_scheduler(): void {
        $this->assertFileExists( self::PAYOUT_SCHEDULER_PATH );
        $source = file_get_contents( self::PAYOUT_SCHEDULER_PATH );

        $this->assertStringContainsString( 'CICLO3-P0-PAYOUTS-EXEC-001 FIX', $source );
        $this->assertStringContainsString( 'CICLO3-P1-PAYOUTS-EXEC-002', $source );
        $this->assertStringContainsString( 'CICLO3-P1-PAYOUTS-EXEC-003', $source );
        $this->assertStringContainsString( 'CICLO3-P1-PAYOUTS-EXEC-004 FIX', $source );
        $this->assertStringContainsString( 'CICLO3-P1-PAYOUTS-EXEC-005 FIX', $source );
        $this->assertStringContainsString( 'CICLO3-P1-PAYOUTS-EXEC-006 FIX', $source );
    }

    public function test_fix_tags_present_in_wallet(): void {
        $this->assertFileExists( self::WALLET_PATH );
        $source = file_get_contents( self::WALLET_PATH );

        $this->assertStringContainsString( 'CICLO3-W-P2-1 FIX', $source );
        $this->assertStringContainsString( 'CICLO3-W-P2-2 FIX', $source );
    }
}
