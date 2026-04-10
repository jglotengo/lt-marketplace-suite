<?php
/**
 * Tests for LTMS_Api_Stripe — lógica pura
 *
 * Testea los dos métodos privados de transformación de datos que son
 * completamente independientes del SDK de Stripe y de WP:
 *   - convert_amount_to_stripe_units(): conversión COP/MXN a unidad mínima Stripe
 *   - sanitize_metadata(): límites de Stripe (50 keys, 40/500 chars)
 *
 * Los métodos públicos (create_payment_intent, create_refund, etc.) llaman al
 * SDK de Stripe directamente — se cubren en tests de integración con Stripe
 * test mode, no aquí.
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

namespace LTMS\Tests\Unit;

use LTMS_Api_Stripe;

/**
 * @covers LTMS_Api_Stripe
 */
class StripeApiTest extends LTMS_Unit_Test_Case {

    /** @var LTMS_Api_Stripe */
    private LTMS_Api_Stripe $stripe;

    /** @var \ReflectionMethod */
    private \ReflectionMethod $refConvert;

    /** @var \ReflectionMethod */
    private \ReflectionMethod $refSanitize;

    protected function setUp(): void {
        parent::setUp();

        // sanitize_key y sanitize_text_field no están en el bootstrap.
        // Las stubbeamos aquí replicando el comportamiento de WordPress.
        // sanitize_email SÍ está en el bootstrap — no se toca.
        \Brain\Monkey\Functions\stubs( [
            'sanitize_key'        => static fn( string $k ): string =>
                strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $k ) ),
            'sanitize_text_field' => static fn( string $s ): string => trim( strip_tags( $s ) ),
        ] );

        // LTMS_Api_Stripe es final — no se puede subclasear.
        // Accedemos a los métodos private directamente via ReflectionMethod.
        // El constructor solo llama \Stripe\Stripe::setApiKey() si la clase
        // existe — en el entorno de test unitario no existe, así que es seguro.
        $this->stripe = new LTMS_Api_Stripe( 'sk_test_dummy_key_for_unit_tests', false );

        $this->refConvert = new \ReflectionMethod( LTMS_Api_Stripe::class, 'convert_amount_to_stripe_units' );
        $this->refConvert->setAccessible( true );

        $this->refSanitize = new \ReflectionMethod( LTMS_Api_Stripe::class, 'sanitize_metadata' );
        $this->refSanitize->setAccessible( true );
    }

    private function convert( float $amount, string $currency ): int {
        return $this->refConvert->invoke( $this->stripe, $amount, $currency );
    }

    private function sanitize( array $metadata ): array {
        return $this->refSanitize->invoke( $this->stripe, $metadata );
    }

    // ── convert_amount_to_stripe_units: COP (zero-decimal) ───────────────────

    public function test_cop_rounds_to_integer(): void {
        $this->assertSame( 50000, $this->convert( 50000.0, 'cop' ) );
    }

    public function test_cop_uppercase_works(): void {
        $this->assertSame( 150000, $this->convert( 150000.0, 'COP' ) );
    }

    public function test_cop_rounds_fractional_amount(): void {
        $this->assertSame( 50001, $this->convert( 50000.6, 'COP' ) );
        $this->assertSame( 50000, $this->convert( 50000.4, 'COP' ) );
    }

    public function test_cop_mixed_case_currency(): void {
        $this->assertSame( 10000, $this->convert( 10000.0, 'Cop' ) );
    }

    public function test_cop_zero_amount(): void {
        $this->assertSame( 0, $this->convert( 0.0, 'COP' ) );
    }

    public function test_cop_large_amount(): void {
        $this->assertSame( 5_000_000, $this->convert( 5_000_000.0, 'COP' ) );
    }

    // ── convert_amount_to_stripe_units: MXN (centavos ×100) ──────────────────

    public function test_mxn_multiplies_by_100(): void {
        $this->assertSame( 50000, $this->convert( 500.0, 'mxn' ) );
    }

    public function test_mxn_uppercase_works(): void {
        $this->assertSame( 150000, $this->convert( 1500.0, 'MXN' ) );
    }

    public function test_mxn_rounds_to_centavos(): void {
        $this->assertSame( 5050, $this->convert( 50.499, 'MXN' ) );
        $this->assertSame( 5050, $this->convert( 50.501, 'MXN' ) );
    }

    public function test_mxn_zero_amount(): void {
        $this->assertSame( 0, $this->convert( 0.0, 'MXN' ) );
    }

    public function test_mxn_one_peso(): void {
        $this->assertSame( 100, $this->convert( 1.0, 'mxn' ) );
    }

    public function test_mxn_fractional_pesos(): void {
        $this->assertSame( 150, $this->convert( 1.5, 'mxn' ) );
    }

    // ── convert_amount_to_stripe_units: otras monedas → centavos ─────────────

    public function test_unknown_currency_treated_as_decimal(): void {
        $this->assertSame( 1000, $this->convert( 10.0, 'usd' ) );
        $this->assertSame( 1000, $this->convert( 10.0, 'eur' ) );
    }

    // ── sanitize_metadata: límites de Stripe ─────────────────────────────────

    public function test_metadata_passthrough_clean_data(): void {
        $result = $this->sanitize( [ 'order_id' => '123', 'site' => 'ltms' ] );
        $this->assertSame( [ 'order_id' => '123', 'site' => 'ltms' ], $result );
    }

    public function test_metadata_empty_array_returns_empty(): void {
        $this->assertSame( [], $this->sanitize( [] ) );
    }

    public function test_metadata_key_truncated_to_40_chars(): void {
        $long_key = str_repeat( 'k', 50 );
        $result   = $this->sanitize( [ $long_key => 'value' ] );
        $keys     = array_keys( $result );
        $this->assertLessThanOrEqual( 40, strlen( $keys[0] ) );
    }

    public function test_metadata_value_truncated_to_500_chars(): void {
        $long_val = str_repeat( 'v', 600 );
        $result   = $this->sanitize( [ 'key' => $long_val ] );
        $this->assertLessThanOrEqual( 500, strlen( $result['key'] ) );
    }

    public function test_metadata_max_50_keys_enforced(): void {
        $input = [];
        for ( $i = 0; $i < 60; $i++ ) {
            $input[ "key_$i" ] = "value_$i";
        }
        $result = $this->sanitize( $input );
        $this->assertCount( 50, $result );
    }

    public function test_metadata_exactly_50_keys_all_preserved(): void {
        $input = [];
        for ( $i = 0; $i < 50; $i++ ) {
            $input[ "key_$i" ] = "val_$i";
        }
        $result = $this->sanitize( $input );
        $this->assertCount( 50, $result );
    }

    public function test_metadata_empty_key_is_excluded(): void {
        // sanitize_key('') → '' → skipped
        $result = $this->sanitize( [ '' => 'value', 'good_key' => 'ok' ] );
        $this->assertArrayNotHasKey( '', $result );
        $this->assertArrayHasKey( 'good_key', $result );
    }

    public function test_metadata_numeric_values_cast_to_string(): void {
        $result = $this->sanitize( [ 'amount' => 12345, 'rate' => 0.15 ] );
        $this->assertIsString( $result['amount'] );
        $this->assertIsString( $result['rate'] );
    }

    public function test_metadata_key_sanitized_removes_spaces(): void {
        // sanitize_key elimina espacios — la clave transformada no debe tener espacios
        $result = $this->sanitize( [ 'order id' => 'abc' ] );
        $this->assertCount( 1, $result );
        $key = array_key_first( $result );
        $this->assertStringNotContainsString( ' ', $key );
    }

    // ── get_provider_slug ─────────────────────────────────────────────────────

    public function test_provider_slug_is_stripe(): void {
        $this->assertSame( 'stripe', $this->stripe->get_provider_slug() );
    }

    // ── convert_amount_to_stripe_units: COP boundary cases ───────────

    /** @dataProvider cop_amounts_provider */
    public function test_cop_amounts_dataProvider( float $input, int $expected ): void {
        $this->assertSame( $expected, $this->convert( $input, 'COP' ) );
    }

    public static function cop_amounts_provider(): array {
        return [
            'minimum'       => [ 1.0,        1       ],
            'round_down'    => [ 999.4,       999     ],
            'round_up'      => [ 999.5,       1000    ],
            'ten_thousand'  => [ 10000.0,     10000   ],
            'half_million'  => [ 500000.0,    500000  ],
            'two_million'   => [ 2000000.0,   2000000 ],
        ];
    }

    /** @dataProvider mxn_amounts_provider */
    public function test_mxn_amounts_dataProvider( float $input, int $expected ): void {
        $this->assertSame( $expected, $this->convert( $input, 'MXN' ) );
    }

    public static function mxn_amounts_provider(): array {
        return [
            'one_centavo'   => [ 0.01,    1    ],
            'ten_pesos'     => [ 10.0,    1000 ],
            'hundred_pesos' => [ 100.0,  10000 ],
            'round_centavo' => [ 9.999,   1000 ],
            'large_amount'  => [ 5000.0, 500000],
        ];
    }

    // ── convert: result is always int ─────────────────────

    public function test_convert_always_returns_int_cop(): void {
        $this->assertIsInt( $this->convert( 12345.67, 'COP' ) );
    }

    public function test_convert_always_returns_int_mxn(): void {
        $this->assertIsInt( $this->convert( 123.45, 'MXN' ) );
    }

    public function test_convert_always_returns_int_usd(): void {
        $this->assertIsInt( $this->convert( 9.99, 'USD' ) );
    }

    // ── convert: currency case-insensitive ────────────────

    /** @dataProvider currency_case_provider */
    public function test_currency_case_insensitive( string $currency, float $amount, int $expected ): void {
        $this->assertSame( $expected, $this->convert( $amount, $currency ) );
    }

    public static function currency_case_provider(): array {
        return [
            'cop_lower'  => [ 'cop', 10000.0, 10000 ],
            'cop_upper'  => [ 'COP', 10000.0, 10000 ],
            'cop_mixed'  => [ 'Cop', 10000.0, 10000 ],
            'mxn_lower'  => [ 'mxn', 100.0,   10000 ],
            'mxn_upper'  => [ 'MXN', 100.0,   10000 ],
        ];
    }

    // ── sanitize_metadata: key exact truncation boundary ──────────

    public function test_metadata_key_exactly_40_chars_not_truncated(): void {
        $key_40 = str_repeat( 'k', 40 );
        $result = $this->sanitize( [ $key_40 => 'val' ] );
        $keys   = array_keys( $result );
        $this->assertSame( 40, strlen( $keys[0] ) );
    }

    public function test_metadata_key_41_chars_truncated_to_40(): void {
        $key_41 = str_repeat( 'k', 41 );
        $result = $this->sanitize( [ $key_41 => 'val' ] );
        $keys   = array_keys( $result );
        $this->assertSame( 40, strlen( $keys[0] ) );
    }

    public function test_metadata_value_exactly_500_chars_not_truncated(): void {
        $val_500 = str_repeat( 'v', 500 );
        $result  = $this->sanitize( [ 'key' => $val_500 ] );
        $this->assertSame( 500, strlen( $result['key'] ) );
    }

    public function test_metadata_value_501_chars_truncated_to_500(): void {
        $val_501 = str_repeat( 'v', 501 );
        $result  = $this->sanitize( [ 'key' => $val_501 ] );
        $this->assertSame( 500, strlen( $result['key'] ) );
    }

    // ── sanitize_metadata: 49 / 50 / 51 boundary ──────────────

    public function test_metadata_49_keys_all_preserved(): void {
        $input = array_fill_keys( array_map( fn($i) => "k$i", range(0,48) ), 'v' );
        $this->assertCount( 49, $this->sanitize( $input ) );
    }

    public function test_metadata_51_keys_truncated_to_50(): void {
        $input = array_fill_keys( array_map( fn($i) => "k$i", range(0,50) ), 'v' );
        $this->assertCount( 50, $this->sanitize( $input ) );
    }

    // ── sanitize_metadata: boolean and null values ─────────────

    public function test_metadata_boolean_true_cast_to_string(): void {
        $result = $this->sanitize( [ 'flag' => true ] );
        $this->assertIsString( $result['flag'] );
        $this->assertSame( '1', $result['flag'] );
    }

    public function test_metadata_boolean_false_cast_to_string(): void {
        $result = $this->sanitize( [ 'flag' => false ] );
        $this->assertIsString( $result['flag'] );
    }

    public function test_metadata_null_value_cast_to_string(): void {
        $result = $this->sanitize( [ 'key' => null ] );
        $this->assertIsString( $result['key'] );
    }

    // ── get_provider_slug: invariant ───────────────────

    public function test_provider_slug_is_string(): void {
        $this->assertIsString( $this->stripe->get_provider_slug() );
    }

    public function test_provider_slug_is_lowercase(): void {
        $slug = $this->stripe->get_provider_slug();
        $this->assertSame( strtolower( $slug ), $slug );
    }

    public function test_provider_slug_not_empty(): void {
        $this->assertNotEmpty( $this->stripe->get_provider_slug() );
    }
}

