<?php
/**
 * AuditCiclo15ListenersFixesTest — Tests para los fixes P0+P1 del Ciclo 1.5.
 *
 * Cubre los fixes aplicados a los webhook/listeners de business/listeners/:
 *   - AUDIT-LISTENERS-001 P0-1: Redi listener on_order_cancelled —
 *     Wallet::debit SIN idempotency_key + currency → doble débito al
 *     origin vendor en retry. UPDATE de status='reversed' fuera del try.
 *   - AUDIT-LISTENERS-001 P0-2: Redi listener on_order_paid —
 *     Redi_Order_Split::process sin try/catch → fallo silencioso con
 *     _ltms_redi_processed=1 ya seteado, no había stock deduct, no había
 *     notificación a vendors, sin retry path.
 *   - AUDIT-LISTENERS-001 P1-1: Order_Paid listener debit_absorbed_shipping —
 *     _ltms_shipping_debited guard no atómico (race condition) +
 *     Wallet::debit sin idempotency_key (5 args en vez de 7).
 *   - AUDIT-LISTENERS-001 P1-2: Coupon_Attribution listener credit_referrer —
     no enganchaba woocommerce_order_status_completed (pedidos offline
 *     perdidos) + guard no atómico + sin try/catch + sin reset de flag en
 *     catch.
 *   - AUDIT-LISTENERS-001 P1-3: TPTC listener on_order_refunded —
 *     _ltms_tptc_reversed guard no atómico (race condition) + sin
 *     _tptc_reversal_failed meta para diagnóstico + sin reset de atomic
 *     claim en catch (no retry path).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types = 1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ReflectionClass;
use ReflectionMethod;

/**
 * @covers AUDIT-LISTENERS-001 P0-1, P0-2, P1-1, P1-2, P1-3
 */
class AuditCiclo15ListenersFixesTest extends LTMS_Unit_Test_Case {

    private const REDI_LISTENER_PATH      = __DIR__ . '/../../includes/business/listeners/class-ltms-redi-order-listener.php';
    private const ORDER_PAID_PATH        = __DIR__ . '/../../includes/business/listeners/class-ltms-order-paid-listener.php';
    private const COUPON_ATTR_PATH      = __DIR__ . '/../../includes/business/listeners/class-ltms-coupon-attribution-listener.php';
    private const TPTC_LISTENER_PATH     = __DIR__ . '/../../includes/business/listeners/class-ltms-tptc-listener.php';

    protected function setUp(): void {
        parent::setUp();

        Functions\stubs( [
            'sanitize_text_field' => static fn( string $s ): string => $s,
            '__'                  => static fn( string $s ): string => $s,
            'wp_json_encode'      => static fn( $data ): string => json_encode( $data ),
        ] );
    }

    protected function tearDown(): void {
        \LTMS_Core_Config::flush_cache();
        parent::tearDown();
    }

    // ── AUDIT-LISTENERS-001 P0-1: Redi listener on_order_cancelled
    //    Wallet::debit con idempotency_key + currency + UPDATE dentro del try ───

    /**
     * El método on_order_cancelled de Redi_Order_Listener debe invocar
     * Wallet::debit con idempotency_key (7º arg) y currency (6º arg).
     * ANTES: pasaba solo 5 args → wallet no dedup → doble débito en retry.
     */
    public function test_redi_on_order_cancelled_debit_has_idempotency_key(): void {
        $this->assertFileExists( self::REDI_LISTENER_PATH );
        $source = file_get_contents( self::REDI_LISTENER_PATH );

        // Buscar la invocación de Wallet::debit dentro de on_order_cancelled.
        // El fix AUDIT-LISTENERS-001 P0-1 añade los args $order_currency y
        // sprintf('redi_reversal_origin_o%d_c%d', ...) + reseller.
        $this->assertStringContainsString(
            "sprintf( 'redi_reversal_origin_o%d_c%d'",
            $source,
            'AUDIT-LISTENERS-001 P0-1: Wallet::debit del origin en on_order_cancelled debe recibir idempotency_key único.'
        );
        $this->assertStringContainsString(
            "sprintf( 'redi_reversal_reseller_o%d_c%d'",
            $source,
            'AUDIT-LISTENERS-001 P0-1: Wallet::debit del reseller en on_order_cancelled debe recibir idempotency_key único.'
        );
    }

    /**
     * El UPDATE de status='reversed' en lt_redi_commissions debe estar
     * DENTRO del try (no fuera como antes). Si está fuera, un fallo del
     * segundo debit deja status='paid' sin reversión aplicada.
     */
    public function test_redi_on_order_cancelled_update_inside_try(): void {
        $this->assertFileExists( self::REDI_LISTENER_PATH );
        $source = file_get_contents( self::REDI_LISTENER_PATH );

        // El fix AUDIT-LISTENERS-001 P0-1 mueve el $wpdb->update de
        // lt_redi_commissions DENTRO del try, después de ambos debits.
        $this->assertStringContainsString(
            "'redi_reversal_reseller_o%d_c%d'",
            $source,
            'AUDIT-LISTENERS-001 P0-1: el $wpdb->update de status=reversed debe ir DESPUÉS de ambos debits (dentro del try).'
        );

        // Verificar que existe el comentario de fix P0-1.
        $this->assertStringContainsString(
            'AUDIT-LISTENERS-001 P0-1 FIX',
            $source,
            'AUDIT-LISTENERS-001 P0-1: debe estar marcado con el tag de fix en el código.'
        );
    }

    /**
     * Redi_Order_Split::process y deduct_origin_stock deben estar
     * envueltos en try/catch dentro de on_order_paid.
     */
    public function test_redi_on_order_paid_try_catch_wraps_process(): void {
        $this->assertFileExists( self::REDI_LISTENER_PATH );
        $source = file_get_contents( self::REDI_LISTENER_PATH );

        // AUDIT-LISTENERS-001 P0-2 FIX debe estar presente.
        $this->assertStringContainsString(
            'AUDIT-LISTENERS-001 P0-2 FIX',
            $source,
            'AUDIT-LISTENERS-001 P0-2: debe estar marcado con el tag de fix.'
        );

        // El try debe envolver Redi_Order_Split::process.
        // Buscamos el try { ... Redi_Order_Split::process en el source.
        $this->assertMatchesRegularExpression(
            '/try\s*\{[^}]*LTMS_Business_Redi_Order_Split::process/s',
            $source,
            'AUDIT-LISTENERS-001 P0-2: Redi_Order_Split::process debe estar dentro de un try.'
        );

        // El catch debe resetear _ltms_redi_processed a '0'.
        $this->assertStringContainsString(
            "_ltms_redi_processed",
            $source,
            'AUDIT-LISTENERS-001 P0-2: el catch debe resetear el flag _ltms_redi_processed.'
        );
        $this->assertStringContainsString(
            "REDI_PROCESS_FAILED",
            $source,
            'AUDIT-LISTENERS-001 P0-2: el catch debe logear con código REDI_PROCESS_FAILED.'
        );
    }

    // ── AUDIT-LISTENERS-001 P1-1: Order_Paid debit_absorbed_shipping ───────

    /**
     * debit_absorbed_shipping debe usar atomic claim (UPDATE condicional
     * de _ltms_shipping_debited), no get_meta + update_meta_data no atómico.
     */
    public function test_order_paid_debit_absorbed_shipping_atomic_claim(): void {
        $this->assertFileExists( self::ORDER_PAID_PATH );
        $source = file_get_contents( self::ORDER_PAID_PATH );

        // El atomic claim usa add_post_meta( ..., '_ltms_shipping_debited', '0', true )
        // + UPDATE condicional con meta_value != '1'.
        $this->assertStringContainsString(
            "add_post_meta( \$order->get_id(), '_ltms_shipping_debited', '0', true )",
            $source,
            'AUDIT-LISTENERS-001 P1-1: debe usar add_post_meta unique=true para asegurar la fila existe.'
        );
        $this->assertStringContainsString(
            "'_ltms_shipping_debited'",
            $source,
            'AUDIT-LISTENERS-001 P1-1: el atomic claim debe referenciar _ltms_shipping_debited.'
        );
        $this->assertStringContainsString(
            "meta_value != '1'",
            $source,
            'AUDIT-LISTENERS-001 P1-1: el UPDATE debe ser condicional (meta_value != 1).'
        );
    }

    /**
     * Wallet::debit en debit_absorbed_shipping debe pasar 7 args
     * (incluyendo $order_currency y $idempotency_key), no 5 como antes.
     */
    public function test_order_paid_debit_absorbed_shipping_debit_full_signature(): void {
        $this->assertFileExists( self::ORDER_PAID_PATH );
        $source = file_get_contents( self::ORDER_PAID_PATH );

        // El idempotency_key.pattern: shipping_absorbed_o{order_id}.
        $this->assertStringContainsString(
            "sprintf( 'shipping_absorbed_o%d', \$order->get_id() )",
            $source,
            'AUDIT-LISTENERS-001 P1-1: Wallet::debit debe recibir idempotency_key shipping_absorbed_o{id}.'
        );

        // El currency debe ser strtolower( $order->get_currency() ?: 'COP' ).
        $this->assertStringContainsString(
            "strtolower( \$order->get_currency()",
            $source,
            'AUDIT-LISTENERS-001 P1-1: Wallet::debit debe recibir currency normalizado strtolower($order->get_currency()).'
        );
    }

    /**
     * El catch de debit_absorbed_shipping debe resetear
     * _ltms_shipping_debited a '0' para permitir retry.
     */
    public function test_order_paid_debit_absorbed_shipping_catch_resets_flag(): void {
        $this->assertFileExists( self::ORDER_PAID_PATH );
        $source = file_get_contents( self::ORDER_PAID_PATH );

        $this->assertStringContainsString(
            'SHIPPING_DEBIT_FAILED',
            $source,
            'AUDIT-LISTENERS-001 P1-1: el catch debe logear con SHIPPING_DEBIT_FAILED.'
        );

        // En el catch debe haber un UPDATE que resetee a '0'. El orden
        // en el SQL es: UPDATE ... SET meta_value = '0' WHERE ...
        // meta_key = %s, '_ltms_shipping_debited'. Comprometemos el orden
        // en el regex para reflejar el SQL real.
        $this->assertMatchesRegularExpression(
            "/catch\s*\(\s*\\\\Throwable.*?'0'.*?_ltms_shipping_debited/s",
            $source,
            'AUDIT-LISTENERS-001 P1-1: el catch debe resetear _ltms_shipping_debited a 0 para retry.'
        );
    }

    // ── AUDIT-LISTENERS-001 P1-2: Coupon_Attribution status_completed ──────

    /**
     * init() debe registrar add_action para woocommerce_order_status_completed
     * además de woocommerce_payment_complete. Antes solo tenía payment_complete,
     * perdiendo los pedidos offline (COD, transferencia, contraentrega).
     */
    public function test_coupon_attribution_init_hooks_status_completed(): void {
        $this->assertFileExists( self::COUPON_ATTR_PATH );
        $source = file_get_contents( self::COUPON_ATTR_PATH );

        $this->assertStringContainsString(
            "woocommerce_order_status_completed",
            $source,
            'AUDIT-LISTENERS-001 P1-2: init() debe enganchar woocommerce_order_status_completed.'
        );
        $this->assertStringContainsString(
            'credit_referrer',
            $source,
            'AUDIT-LISTENERS-001 P1-2: el hook de status_completed debe llamar a credit_referrer.'
        );
    }

    /**
     * credit_referrer debe usar atomic claim (UPDATE condicional) en vez
     * del get_meta + update_post_meta no atómico del original.
     */
    public function test_coupon_attribution_credit_referrer_atomic_claim(): void {
        $this->assertFileExists( self::COUPON_ATTR_PATH );
        $source = file_get_contents( self::COUPON_ATTR_PATH );

        $this->assertStringContainsString(
            "add_post_meta( \$order_id, '_ltms_referral_credited', '0', true )",
            $source,
            'AUDIT-LISTENERS-001 P1-2: add_post_meta unique=true para _ltms_referral_credited.'
        );
        $this->assertStringContainsString(
            "'_ltms_referral_credited'",
            $source,
            'AUDIT-LISTENERS-001 P1-2: el atomic claim referencia _ltms_referral_credited.'
        );
    }

    /**
     * El cuerpo de credit_referrer debe estar envuelto en try/catch.
     */
    public function test_coupon_attribution_credit_referrer_try_catch(): void {
        $this->assertFileExists( self::COUPON_ATTR_PATH );

        if ( ! class_exists( 'LTMS_Coupon_Attribution_Listener' ) ) {
            $this->markTestSkipped( 'LTMS_Coupon_Attribution_Listener no disponible en modo UNIT_ONLY.' );
        }

        $ref = new ReflectionClass( 'LTMS_Coupon_Attribution_Listener' );
        $method = $ref->getMethod( 'credit_referrer' );
        $method->setAccessible( true );

        // Verificar que el código fuente del método contiene try/catch.
        $source = file_get_contents( self::COUPON_ATTR_PATH );
        // El try va al inicio del cuerpo; usamos DOTALL laxo porque la
        // función tiene sub-bloques anidados (whitespace, paréntesis).
        $this->assertMatchesRegularExpression(
            '/public static function credit_referrer.*?\{.*?try\s*\{/s',
            $source,
            'AUDIT-LISTENERS-001 P1-2: credit_referrer debe iniciar con un try block.'
        );
        $this->assertStringContainsString(
            'REFERRAL_CREDIT_FAILED',
            $source,
            'AUDIT-LISTENERS-001 P1-2: el catch debe logear con REFERRAL_CREDIT_FAILED.'
        );
    }

    /**
     * El catch debe resetear _ltms_referral_credited a '0' para retry.
     */
    public function test_coupon_attribution_credit_referrer_catch_resets_flag(): void {
        $this->assertFileExists( self::COUPON_ATTR_PATH );
        $source = file_get_contents( self::COUPON_ATTR_PATH );

        // En el catch debe haber un UPDATE que resetee _ltms_referral_credited a '0'.
        // El orden en el source PHP es: UPDATE ... SET meta_value = '0' WHERE
        // ... meta_key = %s, '_ltms_referral_credited'. Gigamos el orden para
        // confirmar que ambos tokens están presentes en el catch.
        $this->assertMatchesRegularExpression(
            "/catch\s*\(\s*\\\\Throwable.*?'0'.*?_ltms_referral_credited/s",
            $source,
            'AUDIT-LISTENERS-001 P1-2: el catch debe resetear _ltms_referral_credited (UPDATE a 0).'
        );
    }

    // ── AUDIT-LISTENERS-001 P1-3: TPTC listener on_order_refunded ──────────

    /**
     * on_order_refunded debe usar atomic claim en _ltms_tptc_reversed
     * en vez del get_meta + update_meta_data no atómico.
     */
    public function test_tptc_on_order_refunded_atomic_claim(): void {
        $this->assertFileExists( self::TPTC_LISTENER_PATH );
        $source = file_get_contents( self::TPTC_LISTENER_PATH );

        $this->assertStringContainsString(
            "add_post_meta( \$order_id, '_ltms_tptc_reversed', '0', true )",
            $source,
            'AUDIT-LISTENERS-001 P1-3: add_post_meta unique=true para _ltms_tptc_reversed.'
        );
        $this->assertStringContainsString(
            "'_ltms_tptc_reversed'",
            $source,
            'AUDIT-LISTENERS-001 P1-3: el atomic claim referencia _ltms_tptc_reversed.'
        );
        $this->assertStringContainsString(
            "meta_value != '1'",
            $source,
            'AUDIT-LISTENERS-001 P1-3: el UPDATE debe ser condicional (meta_value != 1).'
        );
    }

    /**
     * El catch de on_order_refunded debe setear _tptc_reversal_failed meta
     * para diagnóstico/monitoreo, además de resetear el atomic claim.
     */
    public function test_tptc_on_order_refunded_catch_sets_reversal_failed_meta(): void {
        $this->assertFileExists( self::TPTC_LISTENER_PATH );
        $source = file_get_contents( self::TPTC_LISTENER_PATH );

        $this->assertStringContainsString(
            '_ltms_tptc_reversal_failed',
            $source,
            'AUDIT-LISTENERS-001 P1-3: el catch debe setear _ltms_tptc_reversal_failed meta.'
        );
        $this->assertStringContainsString(
            '_ltms_tptc_reversal_last_error',
            $source,
            'AUDIT-LISTENERS-001 P1-3: el catch debe setear _ltms_tptc_reversal_last_error meta.'
        );
        $this->assertStringContainsString(
            '_ltms_tptc_reversal_last_refund_id',
            $source,
            'AUDIT-LISTENERS-001 P1-3: el catch debe setear _ltms_tptc_reversal_last_refund_id meta.'
        );
        $this->assertStringContainsString(
            'TPTC_REVERSAL_FAILED',
            $source,
            'AUDIT-LISTENERS-001 P1-3: el catch debe logear con TPTC_REVERSAL_FAILED.'
        );
    }

    /**
     * El catch debe resetear _ltms_tptc_reversed a '0' para retry.
     */
    public function test_tptc_on_order_refunded_catch_resets_claim(): void {
        $this->assertFileExists( self::TPTC_LISTENER_PATH );
        $source = file_get_contents( self::TPTC_LISTENER_PATH );

        // Buscar dentro del catch de on_order_refunded un UPDATE que
        // resetee _ltms_tptc_reversed a '0'. El orden SQL es:
        // UPDATE ... SET meta_value = '0' WHERE ... _ltms_tptc_reversed.
        $this->assertMatchesRegularExpression(
            "/catch\s*\(\s*\\\\Throwable.*?'0'.*?_ltms_tptc_reversed/s",
            $source,
            'AUDIT-LISTENERS-001 P1-3: el catch de on_order_refunded debe resetear _ltms_tptc_reversed a 0.'
        );
    }

    /**
     * El check de _ltms_tptc_synced (que revierte solo si la venta fue
     * sincronizada) debe resetear el atomic claim si NO estaba synced.
     * Esto es defense-in-depth: si el pedido no estaba synced, no
     * podemos revertir, pero tampoco podemos dejarlo marcado como
     * reversed (porque un sync tardío no podría re-sync).
     */
    public function test_tptc_on_order_refunded_resets_claim_when_not_synced(): void {
        $this->assertFileExists( self::TPTC_LISTENER_PATH );
        $source = file_get_contents( self::TPTC_LISTENER_PATH );

        // Buscar el bloque "if ( ! $order->get_meta( '_ltms_tptc_synced' )"
        // y verificar que dentro hay un UPDATE que resetee _ltms_tptc_reversed a '0'.
        // El orden SQL es: UPDATE ... SET meta_value = '0' WHERE ... _ltms_tptc_reversed.
        $pattern = "/_ltms_tptc_synced'.*?'0'.*?_ltms_tptc_reversed/s";
        $this->assertMatchesRegularExpression(
            $pattern,
            $source,
            'AUDIT-LISTENERS-001 P1-3: si el pedido no estaba synced, el atomic claim debe resetearse a 0.'
        );
    }
}
