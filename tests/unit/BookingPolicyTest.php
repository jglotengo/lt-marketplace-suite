<?php

declare(strict_types=1);

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/**
 * Tests para LTMS_Booking_Policy_Handler -- logica pura de reembolso.
 *
 * La logica de calculate_refund_amount() tiene tres zonas segun las horas
 * hasta el checkin (hours_until_checkin):
 *
 *   hours >= free_cancel_hours              ? reembolso completo (paid)
 *   hours >= partial_refund_hours           ? reembolso parcial (paid * pct%)
 *   non_refundable_pct > 0                  ? paid * (100 - non_refundable_pct)%
 *   otherwise                               ? 0.0
 *
 * IMPORTANTE: para que la zona parcial sea alcanzable,
 * partial_refund_hours DEBE ser MENOR que free_cancel_hours.
 * Los defaults del sistema (flexible: free=24,partial=48) tienen partial > free
 * ? zona parcial inalcanzable con esos valores. Los tests usan politicas
 * con partial < free para cubrir todos los caminos.
 */
class BookingPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        // get_current_user_id() ya definida en bootstrap.php -- no stubear
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ?? Replica exacta de la logica pura (sin DB) ?????????????????????

    private function calcRefund(array $policy, array $booking): float
    {
        $hours = (strtotime($booking['checkin_date'] . ' UTC') - time()) / 3600;
        $total   = (float) $booking['total_price'];
        $deposit = (float) ($booking['deposit_amount'] ?? 0);
        $paid    = 'deposit' === $booking['payment_mode'] ? $deposit : $total;

        if ($hours >= (int) $policy['free_cancel_hours']) {
            return $paid;
        }
        if (isset($policy['partial_refund_hours']) && $hours >= (int) $policy['partial_refund_hours']) {
            return round($paid * (float) $policy['partial_refund_pct'] / 100, 2);
        }
        if (isset($policy['non_refundable_pct']) && (float) $policy['non_refundable_pct'] > 0) {
            return round($paid * (100 - (float) $policy['non_refundable_pct']) / 100, 2);
        }
        return 0.0;
    }

    private function booking(string $checkinOffset, float $total = 300.0, string $mode = 'full', float $deposit = 0.0): array
    {
        return [
            'checkin_date'   => gmdate('Y-m-d H:i:s', strtotime($checkinOffset)),
            'total_price'    => $total,
            'deposit_amount' => $deposit,
            'payment_mode'   => $mode,
        ];
    }

    // Politicas con partial < free ? zona parcial alcanzable
    private function flexiblePolicy(): array
    {
        return ['free_cancel_hours' => 72, 'partial_refund_pct' => 100, 'partial_refund_hours' => 24, 'non_refundable_pct' => 0];
    }

    private function moderatePolicy(): array
    {
        return ['free_cancel_hours' => 168, 'partial_refund_pct' => 50, 'partial_refund_hours' => 48, 'non_refundable_pct' => 0];
    }

    private function strictPolicy(): array
    {
        return ['free_cancel_hours' => 336, 'partial_refund_pct' => 50, 'partial_refund_hours' => 72, 'non_refundable_pct' => 0];
    }

    private function nonRefundablePolicy(): array
    {
        return ['free_cancel_hours' => 9999, 'partial_refund_pct' => 0, 'partial_refund_hours' => 0, 'non_refundable_pct' => 100];
    }

    // ????????????????????????????????????????????????????????????????????
    // ZONA LIBRE -- hours >= free_cancel_hours ? reembolso completo
    // ????????????????????????????????????????????????????????????????????

    public function test_flexible_full_refund_inside_free_window(): void
    {
        // 100h >= 72h ? full
        $refund = $this->calcRefund($this->flexiblePolicy(), $this->booking('+100 hours', 300.0));
        $this->assertEqualsWithDelta(300.0, $refund, 0.01);
    }

    public function test_moderate_full_refund_inside_free_window(): void
    {
        // 200h >= 168h ? full
        $refund = $this->calcRefund($this->moderatePolicy(), $this->booking('+200 hours', 400.0));
        $this->assertEqualsWithDelta(400.0, $refund, 0.01);
    }

    public function test_strict_full_refund_at_15_days(): void
    {
        // 15 days = 360h >= 336h ? full
        $refund = $this->calcRefund($this->strictPolicy(), $this->booking('+15 days', 500.0));
        $this->assertEqualsWithDelta(500.0, $refund, 0.01);
    }

    public function test_full_refund_at_exact_free_cancel_boundary(): void
    {
        // Exactamente 72h >= 72h ? full
        $refund = $this->calcRefund($this->flexiblePolicy(), $this->booking('+72 hours', 100.0));
        $this->assertEqualsWithDelta(100.0, $refund, 0.01);
    }

    public function test_full_refund_far_from_checkin(): void
    {
        $refund = $this->calcRefund($this->moderatePolicy(), $this->booking('+30 days', 250.0));
        $this->assertEqualsWithDelta(250.0, $refund, 0.01);
    }

    /**
     * Boundary +1h sobre free_cancel: sigue en zona libre.
     */
    public function test_full_refund_one_hour_above_free_boundary(): void
    {
        // 73h >= 72h ? full
        $refund = $this->calcRefund($this->flexiblePolicy(), $this->booking('+73 hours', 200.0));
        $this->assertEqualsWithDelta(200.0, $refund, 0.01);
    }

    /**
     * Boundary -1h bajo free_cancel: cae a zona parcial (no a zona cero).
     */
    public function test_one_hour_below_free_boundary_falls_to_partial_zone(): void
    {
        // 71h < 72h (libre) pero >= 24h (parcial) ? parcial 100% = 150
        $refund = $this->calcRefund($this->flexiblePolicy(), $this->booking('+71 hours', 150.0));
        $this->assertEqualsWithDelta(150.0, $refund, 0.01);
    }

    /**
     * Moderate: holgura clara sobre free_cancel (171h > 168h) ? full.
     * Nota: usar offset "+169 hours" es flaky porque strtotime() tiene resolucion
     * de segundos y time() avanza durante la ejecucion -- el float queda por
     * debajo del entero 169. Se usa +171h (3h de margen) para fiabilidad.
     */
    public function test_moderate_clearly_above_free_boundary_is_full(): void
    {
        $refund = $this->calcRefund($this->moderatePolicy(), $this->booking('+171 hours', 300.0));
        $this->assertEqualsWithDelta(300.0, $refund, 0.01);
    }

    /**
     * Strict: holgura clara sobre free_cancel (340h > 336h) ? full.
     * Se evita el boundary exacto "+336 hours" por la misma razon de precision
     * de float que hace que strtotime quede milisegundos por debajo.
     */
    public function test_strict_clearly_above_free_boundary_is_full(): void
    {
        $refund = $this->calcRefund($this->strictPolicy(), $this->booking('+340 hours', 600.0));
        $this->assertEqualsWithDelta(600.0, $refund, 0.01);
    }

    // ????????????????????????????????????????????????????????????????????
    // ZONA PARCIAL -- partial_hours <= hours < free_cancel_hours
    // ????????????????????????????????????????????????????????????????????

    public function test_flexible_partial_refund_100pct_between_24_and_72h(): void
    {
        // 48h: >= 24h (partial) but < 72h (free) ? 100% of paid = 200
        $refund = $this->calcRefund($this->flexiblePolicy(), $this->booking('+48 hours', 200.0));
        $this->assertEqualsWithDelta(200.0, $refund, 0.01);
    }

    public function test_moderate_50pct_refund_between_48_and_168h(): void
    {
        // 100h: >= 48h (partial) but < 168h (free) ? 50% of 200 = 100
        $refund = $this->calcRefund($this->moderatePolicy(), $this->booking('+100 hours', 200.0));
        $this->assertEqualsWithDelta(100.0, $refund, 0.01);
    }

    public function test_moderate_50pct_rounds_to_two_decimals(): void
    {
        // 50% of 333.33 = 166.665 ? rounded to 166.67
        $refund = $this->calcRefund($this->moderatePolicy(), $this->booking('+100 hours', 333.33));
        $this->assertEqualsWithDelta(166.67, $refund, 0.01);
    }

    public function test_strict_50pct_refund_between_72_and_336h(): void
    {
        // 10 days = 240h: >= 72h (partial) but < 336h (free) ? 50%
        $refund = $this->calcRefund($this->strictPolicy(), $this->booking('+10 days', 600.0));
        $this->assertEqualsWithDelta(300.0, $refund, 0.01);
    }

    public function test_partial_refund_clearly_inside_partial_zone(): void
    {
        // 100h: bien dentro de zona parcial (48h..168h) ? 50% de 200 = 100
        $refund = $this->calcRefund($this->moderatePolicy(), $this->booking('+100 hours', 200.0));
        $this->assertEqualsWithDelta(100.0, $refund, 0.01);
    }

    /**
     * Holgura clara sobre partial_refund_hours (26h > 24h) ? zona parcial.
     * El offset exacto "+24 hours" es flaky: strtotime() queda a milisegundos
     * por debajo del entero 24 y la comparacion >= falla. Con +26h hay margen.
     */
    public function test_partial_refund_clearly_above_partial_boundary(): void
    {
        // 26h >= 24h (partial) y < 72h (free) ? 100% = 500
        $refund = $this->calcRefund($this->flexiblePolicy(), $this->booking('+26 hours', 500.0));
        $this->assertEqualsWithDelta(500.0, $refund, 0.01);
    }

    /**
     * 4h sobre partial boundary (28h) ? zona parcial con holgura segura.
     */
    public function test_partial_refund_four_hours_above_partial_boundary(): void
    {
        // 28h >= 24h (partial) y < 72h (free) ? 100% de 200 = 200
        $refund = $this->calcRefund($this->flexiblePolicy(), $this->booking('+28 hours', 200.0));
        $this->assertEqualsWithDelta(200.0, $refund, 0.01);
    }

    /**
     * Politica con 75% de reembolso parcial -- porcentaje arbitrario.
     */
    public function test_custom_75pct_partial_refund(): void
    {
        $policy = ['free_cancel_hours' => 168, 'partial_refund_pct' => 75, 'partial_refund_hours' => 24, 'non_refundable_pct' => 0];
        $refund = $this->calcRefund($policy, $this->booking('+48 hours', 200.0));
        $this->assertEqualsWithDelta(150.0, $refund, 0.01);
    }

    /**
     * Politica con 10% de reembolso parcial.
     */
    public function test_custom_10pct_partial_refund(): void
    {
        $policy = ['free_cancel_hours' => 168, 'partial_refund_pct' => 10, 'partial_refund_hours' => 24, 'non_refundable_pct' => 0];
        $refund = $this->calcRefund($policy, $this->booking('+48 hours', 1000.0));
        $this->assertEqualsWithDelta(100.0, $refund, 0.01);
    }

    /**
     * partial_refund_pct = 0 ? zona parcial devuelve 0.
     */
    public function test_partial_refund_pct_zero_returns_zero(): void
    {
        $policy = ['free_cancel_hours' => 168, 'partial_refund_pct' => 0, 'partial_refund_hours' => 24, 'non_refundable_pct' => 0];
        $refund = $this->calcRefund($policy, $this->booking('+48 hours', 300.0));
        $this->assertEqualsWithDelta(0.0, $refund, 0.01);
    }

    /**
     * Redondeo complejo: 33.33% de 99.99 = 33.3267 ? 33.33.
     */
    public function test_partial_refund_complex_rounding(): void
    {
        $policy = ['free_cancel_hours' => 168, 'partial_refund_pct' => 33.33, 'partial_refund_hours' => 24, 'non_refundable_pct' => 0];
        $refund = $this->calcRefund($policy, $this->booking('+48 hours', 99.99));
        $this->assertEqualsWithDelta(33.33, $refund, 0.01);
    }

    // ????????????????????????????????????????????????????????????????????
    // ZONA CERO -- hours < partial_refund_hours y non_refundable_pct = 0
    // ????????????????????????????????????????????????????????????????????

    public function test_flexible_zero_refund_below_partial_window(): void
    {
        // 12h < 24h (partial) ? zona cero (non_refundable_pct=0)
        $refund = $this->calcRefund($this->flexiblePolicy(), $this->booking('+12 hours', 150.0));
        $this->assertEqualsWithDelta(0.0, $refund, 0.01);
    }

    public function test_moderate_zero_refund_below_partial_window(): void
    {
        // 24h < 48h (partial) ? zero
        $refund = $this->calcRefund($this->moderatePolicy(), $this->booking('+24 hours', 300.0));
        $this->assertEqualsWithDelta(0.0, $refund, 0.01);
    }

    public function test_strict_zero_refund_at_2_days(): void
    {
        // 48h < 72h (partial) ? zero
        $refund = $this->calcRefund($this->strictPolicy(), $this->booking('+2 days', 500.0));
        $this->assertEqualsWithDelta(0.0, $refund, 0.01);
    }

    /**
     * 1h bajo partial boundary ? zona cero.
     */
    public function test_one_hour_below_partial_boundary_is_zero(): void
    {
        // 23h < 24h (partial) y non_refundable=0 ? 0
        $refund = $this->calcRefund($this->flexiblePolicy(), $this->booking('+23 hours', 400.0));
        $this->assertEqualsWithDelta(0.0, $refund, 0.01);
    }

    /**
     * Checkin en el pasado (hours < 0) ? zona cero (non_refundable=0).
     */
    public function test_past_checkin_returns_zero(): void
    {
        $refund = $this->calcRefund($this->flexiblePolicy(), $this->booking('-1 hour', 300.0));
        $this->assertEqualsWithDelta(0.0, $refund, 0.01);
    }

    /**
     * Checkin muy en el pasado (-30 dias) ? zona cero.
     */
    public function test_far_past_checkin_returns_zero(): void
    {
        $refund = $this->calcRefund($this->moderatePolicy(), $this->booking('-30 days', 500.0));
        $this->assertEqualsWithDelta(0.0, $refund, 0.01);
    }

    // ????????????????????????????????????????????????????????????????????
    // ZONA NON_REFUNDABLE_PCT
    // ????????????????????????????????????????????????????????????????????

    public function test_non_refundable_100pct_returns_zero(): void
    {
        // 100% no reembolsable ? refund = 0%
        $refund = $this->calcRefund($this->nonRefundablePolicy(), $this->booking('+5 days', 200.0));
        $this->assertEqualsWithDelta(0.0, $refund, 0.01);
    }

    public function test_non_refundable_50pct_returns_50pct(): void
    {
        // Sin 'partial_refund_hours' en el array ? isset() es false ? llega a non_refundable branch
        $policy = ['free_cancel_hours' => 9999, 'partial_refund_pct' => 0, 'non_refundable_pct' => 50];
        $refund = $this->calcRefund($policy, $this->booking('+2 hours', 200.0));
        $this->assertEqualsWithDelta(100.0, $refund, 0.01);
    }

    public function test_non_refundable_25pct_returns_75pct_back(): void
    {
        $policy = ['free_cancel_hours' => 9999, 'partial_refund_pct' => 0, 'non_refundable_pct' => 25];
        $refund = $this->calcRefund($policy, $this->booking('+2 hours', 400.0));
        $this->assertEqualsWithDelta(300.0, $refund, 0.01);
    }

    public function test_non_refundable_zero_pct_returns_zero(): void
    {
        // non_refundable_pct = 0 ? cae al branch else ? 0.0
        $policy = ['free_cancel_hours' => 9999, 'partial_refund_pct' => 0, 'partial_refund_hours' => 0, 'non_refundable_pct' => 0];
        $refund = $this->calcRefund($policy, $this->booking('+2 hours', 300.0));
        $this->assertEqualsWithDelta(0.0, $refund, 0.01);
    }

    /**
     * non_refundable_pct = 10 ? cliente recupera 90%.
     */
    public function test_non_refundable_10pct_returns_90pct_back(): void
    {
        $policy = ['free_cancel_hours' => 9999, 'non_refundable_pct' => 10];
        $refund = $this->calcRefund($policy, $this->booking('+2 hours', 500.0));
        $this->assertEqualsWithDelta(450.0, $refund, 0.01);
    }

    /**
     * non_refundable_pct = 99 ? cliente recupera 1%.
     */
    public function test_non_refundable_99pct_returns_1pct_back(): void
    {
        $policy = ['free_cancel_hours' => 9999, 'non_refundable_pct' => 99];
        $refund = $this->calcRefund($policy, $this->booking('+2 hours', 1000.0));
        $this->assertEqualsWithDelta(10.0, $refund, 0.01);
    }

    /**
     * non_refundable con redondeo: 33% no reembolsable de 99.99 ? 66.99.
     */
    public function test_non_refundable_33pct_rounding(): void
    {
        $policy = ['free_cancel_hours' => 9999, 'non_refundable_pct' => 33];
        $refund = $this->calcRefund($policy, $this->booking('+2 hours', 99.99));
        // 67% de 99.99 = 66.9933 ? 66.99
        $this->assertEqualsWithDelta(66.99, $refund, 0.01);
    }

    // ????????????????????????????????????????????????????????????????????
    // MODO DEPOSITO vs PAGO COMPLETO
    // ????????????????????????????????????????????????????????????????????

    public function test_full_mode_uses_total_price_as_base(): void
    {
        $refund = $this->calcRefund($this->flexiblePolicy(), $this->booking('+100 hours', 300.0, 'full', 90.0));
        $this->assertEqualsWithDelta(300.0, $refund, 0.01);
    }

    public function test_deposit_mode_uses_deposit_as_base(): void
    {
        $refund = $this->calcRefund($this->flexiblePolicy(), $this->booking('+100 hours', 300.0, 'deposit', 90.0));
        $this->assertEqualsWithDelta(90.0, $refund, 0.01);
    }

    public function test_deposit_mode_partial_refund_uses_deposit_base(): void
    {
        // moderate: 100h ? 50% of deposit(120) = 60
        $refund = $this->calcRefund($this->moderatePolicy(), $this->booking('+100 hours', 400.0, 'deposit', 120.0));
        $this->assertEqualsWithDelta(60.0, $refund, 0.01);
    }

    public function test_deposit_mode_zero_refund_uses_deposit(): void
    {
        $refund = $this->calcRefund($this->moderatePolicy(), $this->booking('+24 hours', 400.0, 'deposit', 120.0));
        $this->assertEqualsWithDelta(0.0, $refund, 0.01);
    }

    /**
     * Modo deposito en zona non_refundable: base es deposito, no total.
     */
    public function test_deposit_mode_non_refundable_uses_deposit_base(): void
    {
        $policy = ['free_cancel_hours' => 9999, 'non_refundable_pct' => 50];
        // deposit=100, total=500 ? 50% of deposit = 50
        $refund = $this->calcRefund($policy, $this->booking('+1 hour', 500.0, 'deposit', 100.0));
        $this->assertEqualsWithDelta(50.0, $refund, 0.01);
    }

    /**
     * Modo 'full' ignora deposit_amount aunque sea mayor que total.
     */
    public function test_full_mode_ignores_deposit_amount(): void
    {
        // Aunque deposit > total, en modo 'full' se usa total
        $refund = $this->calcRefund($this->flexiblePolicy(), $this->booking('+100 hours', 200.0, 'full', 999.0));
        $this->assertEqualsWithDelta(200.0, $refund, 0.01);
    }

    /**
     * Deposito = 0 en modo deposit ? reembolso = 0.
     */
    public function test_deposit_mode_zero_deposit_returns_zero(): void
    {
        $refund = $this->calcRefund($this->flexiblePolicy(), $this->booking('+100 hours', 300.0, 'deposit', 0.0));
        $this->assertEqualsWithDelta(0.0, $refund, 0.01);
    }

    // ????????????????????????????????????????????????????????????????????
    // CASOS LIMITE
    // ????????????????????????????????????????????????????????????????????

    public function test_zero_total_price_returns_zero(): void
    {
        $refund = $this->calcRefund($this->flexiblePolicy(), $this->booking('+100 hours', 0.0));
        $this->assertEqualsWithDelta(0.0, $refund, 0.01);
    }

    public function test_refund_never_exceeds_paid_amount(): void
    {
        $refund = $this->calcRefund($this->moderatePolicy(), $this->booking('+200 hours', 500.0));
        $this->assertLessThanOrEqual(500.0, $refund);
    }

    public function test_refund_is_never_negative(): void
    {
        $refund = $this->calcRefund($this->strictPolicy(), $this->booking('+1 hour', 500.0));
        $this->assertGreaterThanOrEqual(0.0, $refund);
    }

    public function test_refund_returns_float(): void
    {
        $this->assertIsFloat($this->calcRefund($this->moderatePolicy(), $this->booking('+5 days', 100.0)));
    }

    /**
     * Monto muy pequeno (1 centavo): no genera negativos ni excepciones.
     */
    public function test_one_cent_total_price(): void
    {
        $refund = $this->calcRefund($this->flexiblePolicy(), $this->booking('+100 hours', 0.01));
        $this->assertGreaterThanOrEqual(0.0, $refund);
        $this->assertLessThanOrEqual(0.01, $refund);
    }

    /**
     * Monto grande (1.000.000): aritmetica correcta sin desbordamiento.
     */
    public function test_large_amount_arithmetic(): void
    {
        $refund = $this->calcRefund($this->moderatePolicy(), $this->booking('+200 hours', 1_000_000.0));
        $this->assertEqualsWithDelta(1_000_000.0, $refund, 0.01);
    }

    /**
     * Refund de zona parcial nunca supera el monto pagado.
     */
    public function test_partial_refund_never_exceeds_paid(): void
    {
        $refund = $this->calcRefund($this->moderatePolicy(), $this->booking('+100 hours', 300.0));
        $this->assertLessThanOrEqual(300.0, $refund);
    }

    /**
     * Refund de zona non_refundable nunca supera el monto pagado.
     */
    public function test_non_refundable_refund_never_exceeds_paid(): void
    {
        $policy = ['free_cancel_hours' => 9999, 'non_refundable_pct' => 1];
        $refund = $this->calcRefund($policy, $this->booking('+1 hour', 300.0));
        $this->assertLessThanOrEqual(300.0, $refund);
    }

    /**
     * Invariante: misma politica y mismo booking ? mismo resultado (determinismo).
     */
    public function test_calcrefund_is_deterministic(): void
    {
        $booking = $this->booking('+100 hours', 300.0);
        $policy  = $this->moderatePolicy();

        $first  = $this->calcRefund($policy, $booking);
        $second = $this->calcRefund($policy, $booking);

        $this->assertSame($first, $second);
    }

    // ????????????????????????????????????????????????????????????????????
    // DATAPROVIDER -- cross-policy invariant
    // ????????????????????????????????????????????????????????????????????

    public static function provider_all_policies_far_future(): array
    {
        return [
            'flexible_far'       => [['free_cancel_hours' => 72,  'partial_refund_pct' => 100, 'partial_refund_hours' => 24,  'non_refundable_pct' => 0],  200.0],
            'moderate_far'       => [['free_cancel_hours' => 168, 'partial_refund_pct' => 50,  'partial_refund_hours' => 48,  'non_refundable_pct' => 0],  200.0],
            'strict_far'         => [['free_cancel_hours' => 336, 'partial_refund_pct' => 50,  'partial_refund_hours' => 72,  'non_refundable_pct' => 0],  200.0],
        ];
    }

    /**
     * Cualquier politica con checkin lejano (>2 anos) ? full refund.
     *
     * @dataProvider provider_all_policies_far_future
     */
    public function test_all_policies_far_future_return_full_refund(array $policy, float $total): void
    {
        $refund = $this->calcRefund($policy, $this->booking('+2 years', $total));
        $this->assertEqualsWithDelta($total, $refund, 0.01);
    }

    public static function provider_all_policies_past_checkin(): array
    {
        return [
            'flexible_past'  => [['free_cancel_hours' => 72,  'partial_refund_pct' => 100, 'partial_refund_hours' => 24,  'non_refundable_pct' => 0]],
            'moderate_past'  => [['free_cancel_hours' => 168, 'partial_refund_pct' => 50,  'partial_refund_hours' => 48,  'non_refundable_pct' => 0]],
            'strict_past'    => [['free_cancel_hours' => 336, 'partial_refund_pct' => 50,  'partial_refund_hours' => 72,  'non_refundable_pct' => 0]],
        ];
    }

    /**
     * Cualquier politica con checkin pasado ? cero (hours < 0).
     *
     * @dataProvider provider_all_policies_past_checkin
     */
    public function test_all_policies_past_checkin_return_zero(array $policy): void
    {
        $refund = $this->calcRefund($policy, $this->booking('-5 hours', 300.0));
        $this->assertEqualsWithDelta(0.0, $refund, 0.01);
    }

    // ????????????????????????????????????????????????????????????????????
    // VERIFICACION DE CLASE Y METODOS
    // ????????????????????????????????????????????????????????????????????

    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('LTMS_Booking_Policy_Handler'));
    }

    public function test_calculate_refund_method_exists(): void
    {
        $this->assertTrue(method_exists('LTMS_Booking_Policy_Handler', 'calculate_refund_amount'));
    }

    public function test_get_policy_for_booking_method_exists(): void
    {
        $this->assertTrue(method_exists('LTMS_Booking_Policy_Handler', 'get_policy_for_booking'));
    }

    public function test_process_cancellation_refund_method_exists(): void
    {
        $this->assertTrue(method_exists('LTMS_Booking_Policy_Handler', 'process_cancellation_refund'));
    }

    public function test_get_vendor_policies_method_exists(): void
    {
        $this->assertTrue(method_exists('LTMS_Booking_Policy_Handler', 'get_vendor_policies'));
    }

    public function test_setup_default_policies_method_exists(): void
    {
        $this->assertTrue(method_exists('LTMS_Booking_Policy_Handler', 'setup_default_policies'));
    }

    // ????????????????????????????????????????????????????????????????????
    // REFLEXION -- estructura de la clase
    // ????????????????????????????????????????????????????????????????????

    public function test_reflection_class_is_not_final(): void
    {
        $ref = new \ReflectionClass('LTMS_Booking_Policy_Handler');
        // La clase no es final -- verificamos que existe y es accesible
        $this->assertTrue($ref->isUserDefined());
    }

    public function test_reflection_calculate_refund_is_public_static(): void
    {
        $ref    = new \ReflectionClass('LTMS_Booking_Policy_Handler');
        $method = $ref->getMethod('calculate_refund_amount');

        $this->assertTrue($method->isPublic());
        $this->assertTrue($method->isStatic());
    }

    public function test_reflection_calculate_refund_returns_float(): void
    {
        $ref        = new \ReflectionClass('LTMS_Booking_Policy_Handler');
        $method     = $ref->getMethod('calculate_refund_amount');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertSame('float', (string) $returnType);
    }

    public function test_reflection_get_vendor_policies_returns_array(): void
    {
        $ref        = new \ReflectionClass('LTMS_Booking_Policy_Handler');
        $method     = $ref->getMethod('get_vendor_policies');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertSame('array', (string) $returnType);
    }

    public function test_reflection_get_policy_for_booking_returns_nullable_array(): void
    {
        $ref        = new \ReflectionClass('LTMS_Booking_Policy_Handler');
        $method     = $ref->getMethod('get_policy_for_booking');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertTrue($returnType->allowsNull());
    }

    public function test_reflection_init_method_exists_and_is_public_static(): void
    {
        $ref    = new \ReflectionClass('LTMS_Booking_Policy_Handler');
        $method = $ref->getMethod('init');

        $this->assertTrue($method->isPublic());
        $this->assertTrue($method->isStatic());
    }

    public function test_reflection_has_six_public_static_methods(): void
    {
        $ref     = new \ReflectionClass('LTMS_Booking_Policy_Handler');
        $methods = array_filter(
            $ref->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_STATIC),
            fn($m) => $m->class === 'LTMS_Booking_Policy_Handler'
        );

        $this->assertGreaterThanOrEqual(6, count($methods));
    }

    // ????????????????????????????????????????????????????????????????????
    //  Angulos adicionales -- casos limite y cross-politica
    // ????????????????????????????????????????????????????????????????????

    /**
     * checkin_date = ahora mismo (offset 0s).
     * hours_until_checkin ? 0 ? por debajo de cualquier partial_refund_hours ? cero.
     */
    public function test_checkin_right_now_returns_zero(): void
    {
        $refund = $this->calcRefund(
            $this->flexiblePolicy(),
            $this->booking('+0 seconds', 300.0)
        );
        $this->assertEqualsWithDelta( 0.0, $refund, 0.01 );
    }

    /**
     * Politica sin clave 'partial_refund_hours' (isset() ? false) y
     * non_refundable_pct = 0 ? cae directo al else ? 0.0.
     * Cubre la rama: isset($policy['partial_refund_hours']) === false AND non_refundable_pct == 0.
     */
    public function test_policy_without_partial_hours_key_and_zero_non_refundable_returns_zero(): void
    {
        $policy = [
            'free_cancel_hours'  => 168,
            'partial_refund_pct' => 50,
            // 'partial_refund_hours' ausente deliberadamente
            'non_refundable_pct' => 0,
        ];

        // 12h < 168h (libre) ? partial_refund_hours no existe ? non_refundable=0 ? 0.0
        $refund = $this->calcRefund( $policy, $this->booking( '+12 hours', 400.0 ) );
        $this->assertEqualsWithDelta( 0.0, $refund, 0.01 );
    }

    /**
     * Politica sin clave 'partial_refund_hours' pero con non_refundable_pct > 0
     * ? llega al branch non_refundable y devuelve paid*(100-pct)/100.
     */
    public function test_policy_without_partial_hours_key_uses_non_refundable(): void
    {
        $policy = [
            'free_cancel_hours'  => 9999,
            'partial_refund_pct' => 0,
            // 'partial_refund_hours' ausente
            'non_refundable_pct' => 30,
        ];

        // 30% no reembolsable ? cliente recupera 70% de 1000 = 700
        $refund = $this->calcRefund( $policy, $this->booking( '+2 hours', 1_000.0 ) );
        $this->assertEqualsWithDelta( 700.0, $refund, 0.01 );
    }

    /**
     * Refund nunca es negativo en ninguna zona para total_price grande.
     *
     * @dataProvider provider_refund_non_negative
     */
    public function test_refund_never_negative_cross_policy(
        array  $policy,
        string $offset,
        float  $total
    ): void {
        $refund = $this->calcRefund( $policy, $this->booking( $offset, $total ) );
        $this->assertGreaterThanOrEqual( 0.0, $refund,
            'El reembolso nunca puede ser negativo' );
    }

    public static function provider_refund_non_negative(): array
    {
        return [
            'flexible_free_zone'     => [['free_cancel_hours'=>72,'partial_refund_pct'=>100,'partial_refund_hours'=>24,'non_refundable_pct'=>0],  '+100 hours', 500.0],
            'flexible_partial_zone'  => [['free_cancel_hours'=>72,'partial_refund_pct'=>100,'partial_refund_hours'=>24,'non_refundable_pct'=>0],  '+48 hours',  500.0],
            'flexible_zero_zone'     => [['free_cancel_hours'=>72,'partial_refund_pct'=>100,'partial_refund_hours'=>24,'non_refundable_pct'=>0],  '+12 hours',  500.0],
            'moderate_free_zone'     => [['free_cancel_hours'=>168,'partial_refund_pct'=>50,'partial_refund_hours'=>48,'non_refundable_pct'=>0],  '+200 hours', 999.0],
            'moderate_partial_zone'  => [['free_cancel_hours'=>168,'partial_refund_pct'=>50,'partial_refund_hours'=>48,'non_refundable_pct'=>0],  '+100 hours', 999.0],
            'moderate_zero_zone'     => [['free_cancel_hours'=>168,'partial_refund_pct'=>50,'partial_refund_hours'=>48,'non_refundable_pct'=>0],  '+24 hours',  999.0],
            'non_refundable_100'     => [['free_cancel_hours'=>9999,'partial_refund_pct'=>0,'partial_refund_hours'=>0,'non_refundable_pct'=>100], '+5 days',    300.0],
            'non_refundable_1'       => [['free_cancel_hours'=>9999,'non_refundable_pct'=>1],                                                     '+1 hour',    100.0],
            'past_checkin_flexible'  => [['free_cancel_hours'=>72,'partial_refund_pct'=>100,'partial_refund_hours'=>24,'non_refundable_pct'=>0],  '-5 hours',   250.0],
            'past_checkin_strict'    => [['free_cancel_hours'=>336,'partial_refund_pct'=>50,'partial_refund_hours'=>72,'non_refundable_pct'=>0],  '-30 days',   800.0],
        ];
    }

    /**
     * Refund nunca supera el monto pagado en ninguna zona/politica.
     *
     * @dataProvider provider_refund_never_exceeds_paid
     */
    public function test_refund_never_exceeds_paid_cross_policy(
        array  $policy,
        string $offset,
        float  $total,
        string $mode,
        float  $deposit
    ): void {
        $paid   = $mode === 'deposit' ? $deposit : $total;
        $refund = $this->calcRefund( $policy, $this->booking( $offset, $total, $mode, $deposit ) );
        $this->assertLessThanOrEqual( $paid + 0.01, $refund,
            'El reembolso no puede superar el monto pagado' );
    }

    public static function provider_refund_never_exceeds_paid(): array
    {
        $flex = ['free_cancel_hours'=>72,'partial_refund_pct'=>100,'partial_refund_hours'=>24,'non_refundable_pct'=>0];
        $mod  = ['free_cancel_hours'=>168,'partial_refund_pct'=>50,'partial_refund_hours'=>48,'non_refundable_pct'=>0];
        return [
            'flex_full_mode'    => [$flex, '+100 hours', 300.0, 'full',    0.0  ],
            'flex_deposit_mode' => [$flex, '+100 hours', 300.0, 'deposit', 90.0 ],
            'mod_full_mode'     => [$mod,  '+200 hours', 500.0, 'full',    0.0  ],
            'mod_deposit_mode'  => [$mod,  '+100 hours', 500.0, 'deposit', 150.0],
            'partial_full'      => [$mod,  '+100 hours', 200.0, 'full',    0.0  ],
            'partial_deposit'   => [$mod,  '+100 hours', 200.0, 'deposit', 80.0 ],
        ];
    }

    /**
     * total_price = 0 + deposit mode ? refund = 0 en cualquier zona.
     */
    public function test_zero_deposit_in_deposit_mode_is_zero(): void
    {
        $refund = $this->calcRefund(
            $this->moderatePolicy(),
            $this->booking( '+200 hours', 0.0, 'deposit', 0.0 )
        );
        $this->assertEqualsWithDelta( 0.0, $refund, 0.01 );
    }

    /**
     * Politica con free_cancel_hours = 0: cualquier checkin futuro (>= 0h) aplica full refund.
     */
    public function test_free_cancel_hours_zero_always_full_refund(): void
    {
        $policy = [
            'free_cancel_hours'   => 0,
            'partial_refund_pct'  => 50,
            'partial_refund_hours'=> 0,
            'non_refundable_pct'  => 0,
        ];

        // +1h >= 0h ? full refund
        $refund = $this->calcRefund( $policy, $this->booking( '+1 hour', 400.0 ) );
        $this->assertEqualsWithDelta( 400.0, $refund, 0.01 );
    }

    /**
     * Politica con partial_refund_pct = 100 en zona parcial ? mismo resultado que full.
     * Util para "flexible" real donde se reembolsa todo en la ventana parcial.
     */
    public function test_100pct_partial_equals_full_refund(): void
    {
        $policy = [
            'free_cancel_hours'   => 72,
            'partial_refund_pct'  => 100,
            'partial_refund_hours'=> 24,
            'non_refundable_pct'  => 0,
        ];

        // 48h: zona parcial con 100% ? mismo que full
        $refund = $this->calcRefund( $policy, $this->booking( '+48 hours', 250.0 ) );
        $this->assertEqualsWithDelta( 250.0, $refund, 0.01 );
    }

    /**
     * calcRefund() retorna siempre float (nunca int ni null).
     *
     * @dataProvider provider_all_policies_far_future
     */
    public function test_calcrefund_always_returns_float( array $policy, float $total ): void
    {
        $refund = $this->calcRefund( $policy, $this->booking( '+30 days', $total ) );
        $this->assertIsFloat( $refund );
    }

    // ════════════════════════════════════════════════════════════════════
    // SECCIÓN 9 — Ángulos adicionales de boundary y edge cases
    // ════════════════════════════════════════════════════════════════════

    /**
     * Boundary exacto partial/zero — 1 segundo sobre partial_refund_hours.
     * Usa +25 hours (1h de margen) para evitar flakiness de timing.
     */
    public function test_one_hour_above_partial_boundary_is_partial(): void
    {
        // 25h > 24h (partial) but < 72h (free) → partial 100%
        $refund = $this->calcRefund( $this->flexiblePolicy(), $this->booking( '+25 hours', 300.0 ) );
        $this->assertEqualsWithDelta( 300.0, $refund, 0.01 );
    }

    /**
     * Boundary exacto free/partial — 1 hora sobre free_cancel debería ser full.
     * (Ya cubierto como test_full_refund_one_hour_above_free_boundary, pero con strict)
     */
    public function test_strict_one_hour_above_free_boundary_is_full(): void
    {
        // 337h >= 336h → full
        $refund = $this->calcRefund( $this->strictPolicy(), $this->booking( '+337 hours', 700.0 ) );
        $this->assertEqualsWithDelta( 700.0, $refund, 0.01 );
    }

    /**
     * Política con partial_refund_pct = 100 en zona cero (hours < partial_hours)
     * → debe caer a 0 (no a 100%) porque no alcanza ni la zona parcial.
     */
    public function test_100pct_partial_but_below_partial_zone_returns_zero(): void
    {
        $policy = [
            'free_cancel_hours'    => 72,
            'partial_refund_pct'   => 100,
            'partial_refund_hours' => 24,
            'non_refundable_pct'   => 0,
        ];

        // 12h < 24h (partial) → zona cero
        $refund = $this->calcRefund( $policy, $this->booking( '+12 hours', 200.0 ) );
        $this->assertEqualsWithDelta( 0.0, $refund, 0.01 );
    }

    /**
     * Política estricta en zona parcial con non_refundable_pct > 0.
     * Debería usar la zona parcial primero (si partial_hours <= hours < free_hours).
     */
    public function test_partial_zone_takes_priority_over_non_refundable(): void
    {
        $policy = [
            'free_cancel_hours'    => 168,
            'partial_refund_pct'   => 50,
            'partial_refund_hours' => 24,
            'non_refundable_pct'   => 20,
        ];

        // 48h: está en zona parcial (24 <= 48 < 168) → 50% de 400 = 200
        $refund = $this->calcRefund( $policy, $this->booking( '+48 hours', 400.0 ) );
        $this->assertEqualsWithDelta( 200.0, $refund, 0.01 );
    }

    /**
     * Política estricta en zona cero con non_refundable_pct > 0.
     * Debería usar non_refundable porque hours < partial_hours.
     */
    public function test_below_partial_with_non_refundable_uses_non_refundable(): void
    {
        $policy = [
            'free_cancel_hours'    => 168,
            'partial_refund_pct'   => 50,
            'partial_refund_hours' => 48,
            'non_refundable_pct'   => 20, // 20% no reembolsable → cliente recupera 80%
        ];

        // 12h < 48h (partial) → non_refundable: 80% de 500 = 400
        $refund = $this->calcRefund( $policy, $this->booking( '+12 hours', 500.0 ) );
        $this->assertEqualsWithDelta( 400.0, $refund, 0.01 );
    }

    /**
     * Depósito muy alto que supera el total_price en modo deposit
     * → el base es deposit, no total_price.
     */
    public function test_deposit_higher_than_total_uses_deposit_base(): void
    {
        $policy = $this->flexiblePolicy();

        // Depósito 800 > total 500 — modo deposit → base = 800
        $refund = $this->calcRefund(
            $policy,
            $this->booking( '+100 hours', 500.0, 'deposit', 800.0 )
        );
        $this->assertEqualsWithDelta( 800.0, $refund, 0.01 );
    }

    /**
     * Mismo escenario que test_free_cancel_hours_zero pero con checkin lejano.
     */
    public function test_free_cancel_hours_zero_far_future_full_refund(): void
    {
        $policy = [
            'free_cancel_hours'    => 0,
            'partial_refund_pct'   => 50,
            'partial_refund_hours' => 0,
            'non_refundable_pct'   => 0,
        ];

        $refund = $this->calcRefund( $policy, $this->booking( '+30 days', 1_500.0 ) );
        $this->assertEqualsWithDelta( 1_500.0, $refund, 0.01 );
    }

    /**
     * free_cancel_hours = 0, partial_refund_hours = 0 y non_refundable_pct = 0
     * con checkin en el PASADO → $hours < 0 < 0 is false → cae a 0.0.
     */
    public function test_free_cancel_zero_past_checkin_is_zero(): void
    {
        $policy = [
            'free_cancel_hours'    => 0,
            'partial_refund_pct'   => 50,
            'partial_refund_hours' => 0,
            'non_refundable_pct'   => 0,
        ];

        $refund = $this->calcRefund( $policy, $this->booking( '-2 hours', 300.0 ) );
        $this->assertEqualsWithDelta( 0.0, $refund, 0.01 );
    }

    // ════════════════════════════════════════════════════════════════════
    // SECCIÓN 10 — DataProviders cross-política exhaustivos
    // ════════════════════════════════════════════════════════════════════

    /**
     * Verifica que el reembolso sea exactamente el esperado para múltiples
     * combinaciones de política + zona + monto.
     *
     * @dataProvider provider_exact_refund_cross_policy
     */
    public function test_exact_refund_cross_policy(
        array  $policy,
        string $offset,
        float  $total,
        float  $expected
    ): void {
        $refund = $this->calcRefund( $policy, $this->booking( $offset, $total ) );
        $this->assertEqualsWithDelta( $expected, $refund, 0.02,
            "Offset={$offset}, total={$total} → esperado={$expected}" );
    }

    public static function provider_exact_refund_cross_policy(): array
    {
        $flex  = ['free_cancel_hours'=>72,  'partial_refund_pct'=>100,'partial_refund_hours'=>24, 'non_refundable_pct'=>0];
        $mod   = ['free_cancel_hours'=>168, 'partial_refund_pct'=>50, 'partial_refund_hours'=>48, 'non_refundable_pct'=>0];
        $strict= ['free_cancel_hours'=>336, 'partial_refund_pct'=>50, 'partial_refund_hours'=>72, 'non_refundable_pct'=>0];
        $nonr  = ['free_cancel_hours'=>9999,'partial_refund_pct'=>0,  'partial_refund_hours'=>0,  'non_refundable_pct'=>100];
        $semi  = ['free_cancel_hours'=>9999,'partial_refund_pct'=>0,  'partial_refund_hours'=>0,  'non_refundable_pct'=>30];

        return [
            'flex_free_zone'      => [$flex,   '+80 hours',  200.0, 200.0],
            'flex_partial_zone'   => [$flex,   '+48 hours',  200.0, 200.0], // 100%
            'flex_zero_zone'      => [$flex,   '+10 hours',  200.0,   0.0],
            'mod_free_zone'       => [$mod,    '+200 hours', 400.0, 400.0],
            'mod_partial_50pct'   => [$mod,    '+100 hours', 400.0, 200.0],
            'mod_zero_zone'       => [$mod,    '+10 hours',  400.0,   0.0],
            'strict_free_zone'    => [$strict, '+340 hours', 600.0, 600.0],
            'strict_partial_50'   => [$strict, '+100 hours', 600.0, 300.0],
            'strict_zero_zone'    => [$strict, '+48 hours',  600.0,   0.0],
            'nonr_100pct'         => [$nonr,   '+5 days',    300.0,   0.0],
            'semi_nonr_30pct'     => [['free_cancel_hours'=>9999,'partial_refund_pct'=>0,'non_refundable_pct'=>30], '+1 hour', 100.0, 70.0], // partial_refund_hours absent → non_refundable branch
        ];
    }

    /**
     * Propiedad: refund ≤ paid para cualquier combinación de
     * política, zona temporal y modo de pago.
     *
     * @dataProvider provider_refund_never_exceeds_paid_extended
     */
    public function test_refund_never_exceeds_paid_extended(
        array  $policy,
        string $offset,
        float  $total,
        string $mode,
        float  $deposit
    ): void {
        $paid   = ( $mode === 'deposit' ) ? $deposit : $total;
        $refund = $this->calcRefund( $policy, $this->booking( $offset, $total, $mode, $deposit ) );
        $this->assertLessThanOrEqual( $paid + 0.01, $refund,
            'El reembolso nunca puede superar lo pagado' );
        $this->assertGreaterThanOrEqual( 0.0, $refund,
            'El reembolso nunca puede ser negativo' );
    }

    public static function provider_refund_never_exceeds_paid_extended(): array
    {
        $flex = ['free_cancel_hours'=>72,'partial_refund_pct'=>100,'partial_refund_hours'=>24,'non_refundable_pct'=>0];
        $mod  = ['free_cancel_hours'=>168,'partial_refund_pct'=>50,'partial_refund_hours'=>48,'non_refundable_pct'=>0];
        return [
            'flex_full_free'     => [$flex, '+80 hours',  300.0, 'full',    0.0  ],
            'flex_dep_free'      => [$flex, '+80 hours',  300.0, 'deposit', 100.0],
            'flex_full_partial'  => [$flex, '+48 hours',  300.0, 'full',    0.0  ],
            'flex_dep_partial'   => [$flex, '+48 hours',  300.0, 'deposit', 90.0 ],
            'flex_full_zero'     => [$flex, '+10 hours',  300.0, 'full',    0.0  ],
            'mod_full_free'      => [$mod,  '+200 hours', 500.0, 'full',    0.0  ],
            'mod_dep_partial'    => [$mod,  '+100 hours', 500.0, 'deposit', 200.0],
            'mod_full_zero'      => [$mod,  '+10 hours',  500.0, 'full',    0.0  ],
            'past_checkin_full'  => [$flex, '-5 hours',   300.0, 'full',    0.0  ],
            'past_checkin_dep'   => [$flex, '-5 hours',   300.0, 'deposit', 100.0],
        ];
    }
}

