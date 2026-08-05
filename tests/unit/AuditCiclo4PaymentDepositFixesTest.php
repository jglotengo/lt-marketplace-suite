<?php
/**
 * AuditCiclo4PaymentDepositFixesTest — Tests para los fixes P0+P1+P2 del Ciclo 4.
 *
 * Cubre los fixes aplicados al nucleo de pago (3 archivos):
 *   - DEPOSIT-001 P0: deposit approve() UPDATE final a 'approved' no detectaba
 *     $updated === 0 (deposit row desaparecido). El credito de wallet ya estaba
 *     aplicado (idempotency_key), pero el UPDATE reportaba 0 filas afectadas
 *     silenciosamente — el admin veia 'pending' aunque la wallet ya acreditaba.
 *     Fix: detectar === 0 + lanzar excepcion con mensaje claro del desync
 *     (wallet-ya-acreditado / status-revertido), el catch revierte el atomic
 *     claim a 'pending' (idempotency_key previene doble credito en retry).
 *   - DEPOSIT-002 P1: en el catch de approve(), el rollback del atomic claim
 *     (UPDATE a 'pending') no se verificaba. Si fallaba, el deposito quedaba
 *     stuck en 'processing' para siempre — el admin no podia approve() (status
 *     !=pending) ni reject() (acepta pending O processing, pero pondria
 *     'rejected' saltandose el credito). Sin este fix, el deposito stuck era
 *     invisible hasta que el vendor reclamara.
 *   - ORCH-001 P1: payment-orchestrator record_provider_event() $wpdb->insert
 *     en lt_provider_health no se verificaba. Si fallaba silenciosamente,
 *     maybe_trip_circuit_breaker() nunca veia los 3 errores necesarios para
 *     activar el circuit breaker → un gateway caido seguia recibiendo trafico
 *     indefinidamente. Fix: verificar === false + log critico
 *     PROVIDER_HEALTH_INSERT_FAILED.
 *   - XCOVER-001 P2: save_insurance_selection() leia $_POST con
 *     sanitize_text_field/sanitize_key sin wp_unslash previo. Patron de
 *     seguridad WP exige wp_unslash antes de sanitize (wp_magic_quotes añade
 *     slashes a superglobals, sanitize_text_field no los quita).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types = 1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * @covers DEPOSIT-001, DEPOSIT-002, ORCH-001, XCOVER-001
 */
class AuditCiclo4PaymentDepositFixesTest extends LTMS_Unit_Test_Case {

    private const DEPOSIT_PATH      = __DIR__ . '/../../includes/business/class-ltms-deposit.php';
    private const ORCHESTRATOR_PATH = __DIR__ . '/../../includes/business/class-ltms-payment-orchestrator.php';
    private const XCOVER_PATH       = __DIR__ . '/../../includes/business/class-ltms-xcover-checkout-handler.php';

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

    // ── DEPOSIT-001 P0: approve() UPDATE final detecta === 0 + log desync ──────

    /**
     * El UPDATE final a 'approved' en approve() debe capturar su retorno y
     * verificar tanto === false como === 0.
     */
    public function test_deposit_approve_update_detects_zero_affected_rows(): void {
        $this->assertFileExists( self::DEPOSIT_PATH );
        $source = file_get_contents( self::DEPOSIT_PATH );

        // El check $updated === false sigue presente.
        $this->assertStringContainsString(
            'if ( $updated === false )',
            $source,
            'DEPOSIT-001: check $updated === false debe seguir presente.'
        );

        // El check $updated === 0 debe agregarse.
        $this->assertStringContainsString(
            'if ( $updated === 0 )',
            $source,
            'DEPOSIT-001: check $updated === 0 debe agregarse para detectar deposit row desaparecido.'
        );
    }

    /**
     * La excepcion al detectar $updated === 0 debe mencionar el desync
     * (wallet ya acreditado / status sera revertido) y el idempotency_key
     * para auditoria.
     */
    public function test_deposit_approve_zero_check_logs_desync_with_idem_key(): void {
        $this->assertFileExists( self::DEPOSIT_PATH );
        $source = file_get_contents( self::DEPOSIT_PATH );

        // El mensaje de la excepcion debe mencionar el desync wallet-ya-acreditado.
        $this->assertStringContainsString(
            'UPDATE a approved afecto 0 filas',
            $source,
            'DEPOSIT-001: mensaje de excepcion debe indicar 0 filas afectadas en UPDATE a approved.'
        );

        // Debe mencionar el idempotency_key para auditoria.
        $this->assertStringContainsString(
            'idem_key',
            $source,
            'DEPOSIT-001: mensaje de excepcion debe mencionar idem_key para auditoria del credito wallet ya aplicado.'
        );

        // Debe mencionar el tx_id del credito wallet ya aplicado.
        $this->assertStringContainsString(
            'tx_id',
            $source,
            'DEPOSIT-001: mensaje de excepcion debe mencionar tx_id del credito wallet ya aplicado.'
        );

        // Debe mencionar la situacion de desync explicita para el admin.
        $this->assertStringContainsString(
            'la wallet tiene el saldo',
            $source,
            'DEPOSIT-001: mensaje debe describir el desync (wallet tiene saldo, status sera revertido a pending).'
        );
    }

    /**
     * La verificacion $updated === 0 debe ir DESPUES de $updated === false
     * (es complementaria, no la reemplaza).
     */
    public function test_deposit_zero_check_after_false_check(): void {
        $this->assertFileExists( self::DEPOSIT_PATH );
        $source = file_get_contents( self::DEPOSIT_PATH );

        $false_check_pos = strpos( $source, 'if ( $updated === false )' );
        $zero_check_pos  = strpos( $source, 'if ( $updated === 0 )' );

        $this->assertNotFalse( $false_check_pos, 'DEPOSIT-001: check $updated === false debe seguir presente.' );
        $this->assertNotFalse( $zero_check_pos, 'DEPOSIT-001: check $updated === 0 debe existir.' );
        $this->assertGreaterThan(
            $false_check_pos,
            $zero_check_pos,
            'DEPOSIT-001: check $updated === 0 debe ir DESPUES de check $updated === false (es complementario).'
        );
    }

    // ── DEPOSIT-002 P1: catch verifica rollback UPDATE del atomic claim ──────

    /**
     * En el catch de approve(), el rollback del atomic claim (UPDATE a
     * 'pending') debe capturar su retorno y verificarlo. Si falla, el
     * deposito quedara stuck en 'processing' para siempre.
     */
    public function test_deposit_catch_verifies_rollback_update(): void {
        $this->assertFileExists( self::DEPOSIT_PATH );
        $source = file_get_contents( self::DEPOSIT_PATH );

        // El rollback debe capturar su retorno en $rollback_updated.
        $this->assertStringContainsString(
            '$rollback_updated = $wpdb->update(',
            $source,
            'DEPOSIT-002: el rollback del atomic claim debe capturar su retorno en $rollback_updated.'
        );

        // Verificar === false o === 0.
        $this->assertStringContainsString(
            '$rollback_updated === false || $rollback_updated === 0',
            $source,
            'DEPOSIT-002: verificar $rollback_updated === false (error DB) o === 0 (status cambio / fila desaparecida).'
        );

        // Log critico si el rollback falla.
        $this->assertStringContainsString(
            'DEPOSIT_ATOMIC_CLAIM_ROLLBACK_FAILED',
            $source,
            'DEPOSIT-002: log DEPOSIT_ATOMIC_CLAIM_ROLLBACK_FAILED para detectar depositos stuck en processing.'
        );

        // El log debe mencionar que el deposito quedara stuck en processing.
        $this->assertStringContainsString(
            'stuck en processing',
            $source,
            'DEPOSIT-002: log debe avisar que el deposito quedara stuck en processing.'
        );
    }

    /**
     * El bloque if del rollback check debe ir inmediatamente despues del
     * UPDATE del rollback y antes del LTMS_Core_Logger::error original.
     */
    public function test_deposit_rollback_check_before_original_error_log(): void {
        $this->assertFileExists( self::DEPOSIT_PATH );
        $source = file_get_contents( self::DEPOSIT_PATH );

        $rollback_update_pos = strpos( $source, '$rollback_updated = $wpdb->update(' );
        $rollback_check_pos   = strpos( $source, '$rollback_updated === false || $rollback_updated === 0' );
        $orig_log_pos         = strpos( $source, 'DEPOSIT_APPROVE_FAILED' );

        $this->assertNotFalse( $rollback_update_pos, 'DEPOSIT-002: rollback UPDATE debe existir.' );
        $this->assertNotFalse( $rollback_check_pos, 'DEPOSIT-002: rollback check debe existir.' );
        $this->assertNotFalse( $orig_log_pos, 'DEPOSIT-002: log original DEPOSIT_APPROVE_FAILED debe seguir presente.' );

        // Orden: rollback update → rollback check → original error log.
        $this->assertGreaterThan( $rollback_update_pos, $rollback_check_pos, 'DEPOSIT-002: rollback check debe ir DESPUES del rollback UPDATE.' );
        $this->assertGreaterThan( $rollback_check_pos, $orig_log_pos, 'DEPOSIT-002: log original DEPOSIT_APPROVE_FAILED debe ir DESPUES del rollback check (que es mas especifico del estado del atomic claim).' );
    }

    // ── ORCH-001 P1: record_provider_event verifica $wpdb->insert ──────────────

    /**
     * record_provider_event() debe capturar el retorno del $wpdb->insert en
     * $inserted y verificar === false. Si falla, el circuit breaker no se
     * activa correctamente.
     */
    public function test_orchestrator_record_event_verifies_insert(): void {
        $this->assertFileExists( self::ORCHESTRATOR_PATH );
        $source = file_get_contents( self::ORCHESTRATOR_PATH );

        // El INSERT debe capturar su retorno en $inserted.
        $this->assertStringContainsString(
            '$inserted = $wpdb->insert(',
            $source,
            'ORCH-001: record_provider_event debe capturar el retorno del INSERT en $inserted.'
        );

        // Verificar === false.
        $this->assertStringContainsString(
            'if ( $inserted === false )',
            $source,
            'ORCH-001: verificar $inserted === false (error DB en INSERT de lt_provider_health).'
        );

        // Log critico si el INSERT falla.
        $this->assertStringContainsString(
            'PROVIDER_HEALTH_INSERT_FAILED',
            $source,
            'ORCH-001: log PROVIDER_HEALTH_INSERT_FAILED cuando el INSERT de lt_provider_health falla.'
        );

        // El log debe mencionar que el circuit breaker puede no activarse.
        $this->assertStringContainsString(
            'Circuit breaker puede no activarse',
            $source,
            'ORCH-001: log debe avisar que el circuit breaker puede no activarse sin eventos registrados.'
        );
    }

    /**
     * El check de $inserted === false NO debe lanzar excepcion (el metodo se
     * llama desde catch blocks donde una excepcion adicional enmascararia el
     * error original del gateway). Verificamos que NO haya throw en el bloque.
     */
    public function test_orchestrator_record_event_no_throw_on_insert_failure(): void {
        $this->assertFileExists( self::ORCHESTRATOR_PATH );
        $source = file_get_contents( self::ORCHESTRATOR_PATH );

        // Encontrar el bloque del check $inserted === false.
        $check_pos = strpos( $source, 'if ( $inserted === false )' );
        $this->assertNotFalse( $check_pos, 'ORCH-001: check $inserted === false debe existir.' );

        // Tomar el bloque que sigue al check (800 chars suficientes para ver
        // todo el bloque if + el cierre del metodo).
        $block = substr( $source, $check_pos, 800 );

        // El bloque debe contener LTMS_Core_Logger::error (loguear, no throw).
        $this->assertStringContainsString( 'LTMS_Core_Logger::error', $block, 'ORCH-001: el bloque de error debe loguear, no lanzar excepcion.' );

        // El bloque NO debe contener 'throw new'.
        $this->assertStringNotContainsString(
            'throw new',
            $block,
            'ORCH-001: el bloque de error NO debe lanzar excepcion (se llama desde catch blocks, una excepcion adicional enmascararia el error original del gateway).'
        );
    }

    // ── XCOVER-001 P2: save_insurance_selection usa wp_unslash ─────────────────

    /**
     * save_insurance_selection() debe llamar wp_unslash antes de sanitize
     * para todos los campos $_POST leidos.
     */
    public function test_xcover_save_selection_uses_wp_unslash(): void {
        $this->assertFileExists( self::XCOVER_PATH );
        $source = file_get_contents( self::XCOVER_PATH );

        // Los 3 campos $_POST (ltms_insurance_selected, ltms_insurance_quote_id,
        // ltms_insurance_type) deben envolverse en wp_unslash.
        $this->assertStringContainsString(
            "wp_unslash( \$_POST['ltms_insurance_selected']",
            $source,
            'XCOVER-001: ltms_insurance_selected debe envolverse en wp_unslash antes de sanitize.'
        );
        $this->assertStringContainsString(
            "wp_unslash( \$_POST['ltms_insurance_quote_id']",
            $source,
            'XCOVER-001: ltms_insurance_quote_id debe envolverse en wp_unslash antes de sanitize.'
        );
        $this->assertStringContainsString(
            "wp_unslash( \$_POST['ltms_insurance_type']",
            $source,
            'XCOVER-001: ltms_insurance_type debe envolverse en wp_unslash antes de sanitize.'
        );
    }

    /**
     * Los 3 campos deben conservar su sanitize respectivo despues de
     * wp_unslash (sanitize_key para selected y type, sanitize_text_field
     * para quote_id).
     */
    public function test_xcover_save_selection_preserves_sanitize_after_unslash(): void {
        $this->assertFileExists( self::XCOVER_PATH );
        $source = file_get_contents( self::XCOVER_PATH );

        // $selected usa sanitize_key wp_unslash(...).
        $this->assertStringContainsString(
            "sanitize_key( wp_unslash( \$_POST['ltms_insurance_selected']",
            $source,
            'XCOVER-001: $selected debe combinar wp_unslash + sanitize_key.'
        );

        // $quote_id usa sanitize_text_field wp_unslash(...).
        $this->assertStringContainsString(
            "sanitize_text_field( wp_unslash( \$_POST['ltms_insurance_quote_id']",
            $source,
            'XCOVER-001: $quote_id debe combinar wp_unslash + sanitize_text_field.'
        );

        // $type usa sanitize_key wp_unslash(...).
        $this->assertStringContainsString(
            "sanitize_key( wp_unslash( \$_POST['ltms_insurance_type']",
            $source,
            'XCOVER-001: $type debe combinar wp_unslash + sanitize_key.'
        );
    }

    // ── Fix tags de trazabilidad ─────────────────────────────────────────────

    /**
     * Todos los fixes del Ciclo 4 deben estar marcados con sus IDs en el
     * codigo fuente.
     */
    public function test_fix_tags_present_in_deposit(): void {
        $this->assertFileExists( self::DEPOSIT_PATH );
        $source = file_get_contents( self::DEPOSIT_PATH );

        $this->assertStringContainsString( 'CICLO4-P0-DEPOSIT-001 FIX', $source );
        $this->assertStringContainsString( 'CICLO4-P1-DEPOSIT-002 FIX', $source );
    }

    public function test_fix_tags_present_in_orchestrator(): void {
        $this->assertFileExists( self::ORCHESTRATOR_PATH );
        $source = file_get_contents( self::ORCHESTRATOR_PATH );

        $this->assertStringContainsString( 'CICLO4-P1-ORCH-001 FIX', $source );
    }

    public function test_fix_tags_present_in_xcover(): void {
        $this->assertFileExists( self::XCOVER_PATH );
        $source = file_get_contents( self::XCOVER_PATH );

        $this->assertStringContainsString( 'CICLO4-P2-XCOVER-001 FIX', $source );
    }
}
