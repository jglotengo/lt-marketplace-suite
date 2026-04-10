<?php

/**
 * WC stubs — global namespace block (must precede LTMS_Product_Bookable autoload).
 */
namespace {
    if ( ! interface_exists( 'WC_Data_Store_Interface' ) ) {
        interface WC_Data_Store_Interface {}
    }
    if ( ! class_exists( 'WC_Data' ) ) {
        class WC_Data {
            protected array $extra_data = [];
            protected array $data       = [];
            protected array $changes    = [];
            public function get_data(): array { return array_merge( $this->data, $this->extra_data ); }
        }
    }
    if ( ! class_exists( 'WC_Product' ) ) {
        class WC_Product extends WC_Data {
            private array $props = [];
            public function get_prop( string $key, string $context = 'view' ) {
                return $this->props[ $key ] ?? null;
            }
            public function set_prop( string $key, $value ): void {
                $this->props[ $key ] = $value;
            }
            public function get_id(): int      { return 0; }
            public function get_type(): string { return 'bookable'; }
        }
    }
}

namespace LTMS\Tests\Unit {

    use Brain\Monkey;
    use PHPUnit\Framework\TestCase;

    /**
     * Unit tests for LTMS_Product_Bookable (v2.0.0).
     *
     * Covers every getter/setter + schema invariants.
     */
    class ProductBookableTest extends TestCase
    {
        private \LTMS_Product_Bookable $p;

        protected function setUp(): void
        {
            parent::setUp();
            Monkey\setUp();

            Monkey\Functions\stubs( [
                'sanitize_text_field' => static fn( $v ) => trim( strip_tags( (string) $v ) ),
                'wp_kses_post'        => static fn( $v ) => (string) $v,
            ] );

            $this->p = new \LTMS_Product_Bookable();
        }

        protected function tearDown(): void
        {
            Monkey\tearDown();
            parent::tearDown();
        }

        // ------------------------------------------------------------------ //
        //  get_type
        // ------------------------------------------------------------------ //

        public function test_get_type_returns_ltms_bookable(): void
        {
            $this->assertSame( 'ltms_bookable', $this->p->get_type() );
        }

        // ------------------------------------------------------------------ //
        //  booking_type  — enum: accommodation|experience|rental|
        //                        professional_service|space|restaurant
        // ------------------------------------------------------------------ //

        /** @dataProvider validBookingTypes */
        public function test_set_booking_type_valid( string $type ): void
        {
            $this->p->set_booking_type( $type );
            $this->assertSame( $type, $this->p->get_booking_type() );
        }

        public static function validBookingTypes(): array
        {
            return [
                [ 'accommodation' ],
                [ 'experience' ],
                [ 'rental' ],
                [ 'professional_service' ],
                [ 'space' ],
                [ 'restaurant' ],
            ];
        }

        public function test_set_booking_type_invalid_falls_back_to_accommodation(): void
        {
            $this->p->set_booking_type( 'hotel' );
            $this->assertSame( 'accommodation', $this->p->get_booking_type() );
        }

        public function test_set_booking_type_empty_falls_back_to_accommodation(): void
        {
            $this->p->set_booking_type( '' );
            $this->assertSame( 'accommodation', $this->p->get_booking_type() );
        }

        public function test_get_booking_type_default_is_accommodation(): void
        {
            $this->assertSame( 'accommodation', $this->p->get_booking_type() );
        }

        public function test_set_booking_type_overwrite_keeps_last_valid(): void
        {
            $this->p->set_booking_type( 'experience' );
            $this->p->set_booking_type( 'rental' );
            $this->assertSame( 'rental', $this->p->get_booking_type() );
        }

        public function test_set_booking_type_invalid_then_valid_keeps_valid(): void
        {
            $this->p->set_booking_type( 'unknown' );
            $this->p->set_booking_type( 'space' );
            $this->assertSame( 'space', $this->p->get_booking_type() );
        }

        public function test_get_booking_type_edit_context(): void
        {
            $this->p->set_booking_type( 'restaurant' );
            $this->assertSame( 'restaurant', $this->p->get_booking_type( 'edit' ) );
        }

        // ------------------------------------------------------------------ //
        //  min_nights  — clamp ≥ 1
        // ------------------------------------------------------------------ //

        public function test_set_min_nights_normal(): void
        {
            $this->p->set_min_nights( 3 );
            $this->assertSame( 3, $this->p->get_min_nights() );
        }

        public function test_set_min_nights_one_boundary(): void
        {
            $this->p->set_min_nights( 1 );
            $this->assertSame( 1, $this->p->get_min_nights() );
        }

        public function test_set_min_nights_zero_clamps_to_one(): void
        {
            $this->p->set_min_nights( 0 );
            $this->assertSame( 1, $this->p->get_min_nights() );
        }

        public function test_set_min_nights_negative_clamps_to_one(): void
        {
            $this->p->set_min_nights( -10 );
            $this->assertSame( 1, $this->p->get_min_nights() );
        }

        public function test_set_min_nights_large_value(): void
        {
            $this->p->set_min_nights( 365 );
            $this->assertSame( 365, $this->p->get_min_nights() );
        }

        public function test_set_min_nights_overwrite(): void
        {
            $this->p->set_min_nights( 5 );
            $this->p->set_min_nights( 2 );
            $this->assertSame( 2, $this->p->get_min_nights() );
        }

        /** @dataProvider minNightsClampCases */
        public function test_min_nights_always_at_least_one( int $input ): void
        {
            $this->p->set_min_nights( $input );
            $this->assertGreaterThanOrEqual( 1, $this->p->get_min_nights() );
        }

        public static function minNightsClampCases(): array
        {
            return [ [-100], [-1], [0], [1], [2], [30] ];
        }

        // ------------------------------------------------------------------ //
        //  max_nights  — clamp ≥ 0
        // ------------------------------------------------------------------ //

        public function test_set_max_nights_normal(): void
        {
            $this->p->set_max_nights( 14 );
            $this->assertSame( 14, $this->p->get_max_nights() );
        }

        public function test_set_max_nights_zero_is_valid(): void
        {
            $this->p->set_max_nights( 0 );
            $this->assertSame( 0, $this->p->get_max_nights() );
        }

        public function test_set_max_nights_negative_clamps_to_zero(): void
        {
            $this->p->set_max_nights( -5 );
            $this->assertSame( 0, $this->p->get_max_nights() );
        }

        public function test_set_max_nights_large_value(): void
        {
            $this->p->set_max_nights( 730 );
            $this->assertSame( 730, $this->p->get_max_nights() );
        }

        public function test_get_max_nights_default_is_zero(): void
        {
            $this->assertSame( 0, $this->p->get_max_nights() );
        }

        // ------------------------------------------------------------------ //
        //  deposit_pct  — clamp [0.0, 100.0]
        // ------------------------------------------------------------------ //

        public function test_set_deposit_pct_normal(): void
        {
            $this->p->set_deposit_pct( 30.0 );
            $this->assertSame( 30.0, $this->p->get_deposit_pct() );
        }

        public function test_set_deposit_pct_zero_boundary(): void
        {
            $this->p->set_deposit_pct( 0.0 );
            $this->assertSame( 0.0, $this->p->get_deposit_pct() );
        }

        public function test_set_deposit_pct_hundred_boundary(): void
        {
            $this->p->set_deposit_pct( 100.0 );
            $this->assertSame( 100.0, $this->p->get_deposit_pct() );
        }

        public function test_set_deposit_pct_below_zero_clamps(): void
        {
            $this->p->set_deposit_pct( -1.0 );
            $this->assertSame( 0.0, $this->p->get_deposit_pct() );
        }

        public function test_set_deposit_pct_above_hundred_clamps(): void
        {
            $this->p->set_deposit_pct( 101.0 );
            $this->assertSame( 100.0, $this->p->get_deposit_pct() );
        }

        public function test_set_deposit_pct_decimal_precision(): void
        {
            $this->p->set_deposit_pct( 33.33 );
            $this->assertSame( 33.33, $this->p->get_deposit_pct() );
        }

        /** @dataProvider depositEdgeCases */
        public function test_deposit_pct_invariant_always_in_range( float $input ): void
        {
            $this->p->set_deposit_pct( $input );
            $v = $this->p->get_deposit_pct();
            $this->assertGreaterThanOrEqual( 0.0, $v );
            $this->assertLessThanOrEqual( 100.0, $v );
        }

        public static function depositEdgeCases(): array
        {
            return [ [-999.0], [-0.01], [0.0], [50.0], [100.0], [100.01], [999.0] ];
        }

        public function test_set_deposit_pct_99_99(): void
        {
            $this->p->set_deposit_pct( 99.99 );
            $this->assertSame( 99.99, $this->p->get_deposit_pct() );
        }

        public function test_set_deposit_pct_50_5(): void
        {
            $this->p->set_deposit_pct( 50.5 );
            $this->assertSame( 50.5, $this->p->get_deposit_pct() );
        }

        // ------------------------------------------------------------------ //
        //  policy_id  — clamp ≥ 0
        // ------------------------------------------------------------------ //

        public function test_set_policy_id_normal(): void
        {
            $this->p->set_policy_id( 42 );
            $this->assertSame( 42, $this->p->get_policy_id() );
        }

        public function test_set_policy_id_zero_is_valid(): void
        {
            $this->p->set_policy_id( 0 );
            $this->assertSame( 0, $this->p->get_policy_id() );
        }

        public function test_set_policy_id_negative_clamps_to_zero(): void
        {
            $this->p->set_policy_id( -1 );
            $this->assertSame( 0, $this->p->get_policy_id() );
        }

        public function test_set_policy_id_large_value(): void
        {
            $this->p->set_policy_id( 99999 );
            $this->assertSame( 99999, $this->p->get_policy_id() );
        }

        // ------------------------------------------------------------------ //
        //  checkin_time / checkout_time
        // ------------------------------------------------------------------ //

        public function test_get_checkin_time_default_is_1500(): void
        {
            $this->assertSame( '15:00', $this->p->get_checkin_time() );
        }

        public function test_set_checkin_time_stores_value(): void
        {
            $this->p->set_checkin_time( '14:00' );
            $this->assertSame( '14:00', $this->p->get_checkin_time() );
        }

        public function test_get_checkout_time_default_is_1100(): void
        {
            $this->assertSame( '11:00', $this->p->get_checkout_time() );
        }

        public function test_set_checkout_time_stores_value(): void
        {
            $this->p->set_checkout_time( '10:00' );
            $this->assertSame( '10:00', $this->p->get_checkout_time() );
        }

        public function test_set_checkin_time_midnight(): void
        {
            $this->p->set_checkin_time( '00:00' );
            $this->assertSame( '00:00', $this->p->get_checkin_time() );
        }

        public function test_set_checkout_time_midnight(): void
        {
            $this->p->set_checkout_time( '00:00' );
            $this->assertSame( '00:00', $this->p->get_checkout_time() );
        }

        public function test_set_checkin_time_strips_html(): void
        {
            $this->p->set_checkin_time( '<b>16:00</b>' );
            // sanitize_text_field strips tags → '16:00'
            $this->assertSame( '16:00', $this->p->get_checkin_time() );
        }

        public function test_set_checkout_time_strips_html(): void
        {
            $this->p->set_checkout_time( '<script>12:00</script>' );
            $this->assertSame( '12:00', $this->p->get_checkout_time() );
        }

        // ------------------------------------------------------------------ //
        //  capacity  — clamp ≥ 1
        // ------------------------------------------------------------------ //

        public function test_set_capacity_normal(): void
        {
            $this->p->set_capacity( 20 );
            $this->assertSame( 20, $this->p->get_capacity() );
        }

        public function test_set_capacity_one_boundary(): void
        {
            $this->p->set_capacity( 1 );
            $this->assertSame( 1, $this->p->get_capacity() );
        }

        public function test_set_capacity_zero_clamps_to_one(): void
        {
            $this->p->set_capacity( 0 );
            $this->assertSame( 1, $this->p->get_capacity() );
        }

        public function test_set_capacity_negative_clamps_to_one(): void
        {
            $this->p->set_capacity( -99 );
            $this->assertSame( 1, $this->p->get_capacity() );
        }

        public function test_set_capacity_large_value(): void
        {
            $this->p->set_capacity( 500 );
            $this->assertSame( 500, $this->p->get_capacity() );
        }

        /** @dataProvider capacityClampCases */
        public function test_capacity_always_at_least_one( int $input ): void
        {
            $this->p->set_capacity( $input );
            $this->assertGreaterThanOrEqual( 1, $this->p->get_capacity() );
        }

        public static function capacityClampCases(): array
        {
            return [ [-50], [-1], [0], [1], [10], [100] ];
        }

        // ------------------------------------------------------------------ //
        //  payment_mode  — enum: full | deposit | reserve_only
        // ------------------------------------------------------------------ //

        /** @dataProvider validPaymentModes */
        public function test_set_payment_mode_valid( string $mode ): void
        {
            $this->p->set_payment_mode( $mode );
            $this->assertSame( $mode, $this->p->get_payment_mode() );
        }

        public static function validPaymentModes(): array
        {
            return [ [ 'full' ], [ 'deposit' ], [ 'reserve_only' ] ];
        }

        public function test_set_payment_mode_invalid_falls_back_to_full(): void
        {
            $this->p->set_payment_mode( 'installments' );
            $this->assertSame( 'full', $this->p->get_payment_mode() );
        }

        public function test_get_payment_mode_default_is_full(): void
        {
            $this->assertSame( 'full', $this->p->get_payment_mode() );
        }

        public function test_set_payment_mode_empty_falls_back_to_full(): void
        {
            $this->p->set_payment_mode( '' );
            $this->assertSame( 'full', $this->p->get_payment_mode() );
        }

        public function test_set_payment_mode_reserve_only_edit_context(): void
        {
            $this->p->set_payment_mode( 'reserve_only' );
            $this->assertSame( 'reserve_only', $this->p->get_payment_mode( 'edit' ) );
        }

        // ------------------------------------------------------------------ //
        //  advance_booking_days  — clamp ≥ 0
        // ------------------------------------------------------------------ //

        public function test_set_advance_booking_days_normal(): void
        {
            $this->p->set_advance_booking_days( 7 );
            $this->assertSame( 7, $this->p->get_advance_booking_days() );
        }

        public function test_set_advance_booking_days_zero_is_valid(): void
        {
            $this->p->set_advance_booking_days( 0 );
            $this->assertSame( 0, $this->p->get_advance_booking_days() );
        }

        public function test_set_advance_booking_days_negative_clamps_to_zero(): void
        {
            $this->p->set_advance_booking_days( -3 );
            $this->assertSame( 0, $this->p->get_advance_booking_days() );
        }

        public function test_set_advance_booking_days_large(): void
        {
            $this->p->set_advance_booking_days( 90 );
            $this->assertSame( 90, $this->p->get_advance_booking_days() );
        }

        /** @dataProvider advanceBookingDayCases */
        public function test_advance_booking_days_always_non_negative( int $input ): void
        {
            $this->p->set_advance_booking_days( $input );
            $this->assertGreaterThanOrEqual( 0, $this->p->get_advance_booking_days() );
        }

        public static function advanceBookingDayCases(): array
        {
            return [ [-30], [-1], [0], [1], [7], [60] ];
        }

        // ------------------------------------------------------------------ //
        //  max_advance_days  — clamp ≥ 0; getter uses ?: 365
        // ------------------------------------------------------------------ //

        public function test_get_max_advance_days_default_is_365(): void
        {
            $this->assertSame( 365, $this->p->get_max_advance_days() );
        }

        public function test_set_max_advance_days_normal(): void
        {
            $this->p->set_max_advance_days( 90 );
            $this->assertSame( 90, $this->p->get_max_advance_days() );
        }

        public function test_set_max_advance_days_large_value(): void
        {
            $this->p->set_max_advance_days( 730 );
            $this->assertSame( 730, $this->p->get_max_advance_days() );
        }

        public function test_set_max_advance_days_zero_returns_default_365(): void
        {
            // max(0,0)=0 stored; (int)(0 ?: 365) = 365 — 0 is falsy
            $this->p->set_max_advance_days( 0 );
            $this->assertSame( 365, $this->p->get_max_advance_days() );
        }

        public function test_set_max_advance_days_negative_clamps_and_returns_default(): void
        {
            // max(0,-1)=0 stored; getter returns 365
            $this->p->set_max_advance_days( -1 );
            $this->assertSame( 365, $this->p->get_max_advance_days() );
        }

        public function test_set_max_advance_days_one_is_valid(): void
        {
            $this->p->set_max_advance_days( 1 );
            $this->assertSame( 1, $this->p->get_max_advance_days() );
        }

        // ------------------------------------------------------------------ //
        //  rnt_number / sectur_folio  — string sanitize
        // ------------------------------------------------------------------ //

        public function test_set_rnt_number_stores_value(): void
        {
            $this->p->set_rnt_number( 'RNT-12345' );
            $this->assertSame( 'RNT-12345', $this->p->get_rnt_number() );
        }

        public function test_get_rnt_number_default_is_empty(): void
        {
            $this->assertSame( '', $this->p->get_rnt_number() );
        }

        public function test_set_sectur_folio_stores_value(): void
        {
            $this->p->set_sectur_folio( 'MX-9999' );
            $this->assertSame( 'MX-9999', $this->p->get_sectur_folio() );
        }

        public function test_get_sectur_folio_default_is_empty(): void
        {
            $this->assertSame( '', $this->p->get_sectur_folio() );
        }

        public function test_set_rnt_number_strips_html_tags(): void
        {
            $this->p->set_rnt_number( '<b>RNT-99999</b>' );
            $this->assertSame( 'RNT-99999', $this->p->get_rnt_number() );
        }

        public function test_set_sectur_folio_strips_html_tags(): void
        {
            $this->p->set_sectur_folio( '<script>MX-EVIL</script>' );
            $this->assertSame( 'MX-EVIL', $this->p->get_sectur_folio() );
        }

        public function test_set_rnt_number_trims_whitespace(): void
        {
            $this->p->set_rnt_number( '  RNT-777  ' );
            $this->assertSame( 'RNT-777', $this->p->get_rnt_number() );
        }

        // ------------------------------------------------------------------ //
        //  country_code  — strtoupper + sanitize_text_field
        // ------------------------------------------------------------------ //

        public function test_get_country_code_default_is_co(): void
        {
            $this->assertSame( 'CO', $this->p->get_country_code() );
        }

        public function test_set_country_code_lowercased_input_gets_uppercased(): void
        {
            $this->p->set_country_code( 'co' );
            $this->assertSame( 'CO', $this->p->get_country_code() );
        }

        public function test_set_country_code_already_uppercase(): void
        {
            $this->p->set_country_code( 'MX' );
            $this->assertSame( 'MX', $this->p->get_country_code() );
        }

        public function test_set_country_code_mixed_case(): void
        {
            $this->p->set_country_code( 'Us' );
            $this->assertSame( 'US', $this->p->get_country_code() );
        }

        public function test_set_country_code_br(): void
        {
            $this->p->set_country_code( 'br' );
            $this->assertSame( 'BR', $this->p->get_country_code() );
        }

        public function test_set_country_code_pe_uppercase(): void
        {
            $this->p->set_country_code( 'PE' );
            $this->assertSame( 'PE', $this->p->get_country_code() );
        }

        // ------------------------------------------------------------------ //
        //  amenities  — array of sanitized strings
        // ------------------------------------------------------------------ //

        public function test_get_amenities_default_is_empty_array(): void
        {
            $this->assertSame( [], $this->p->get_amenities() );
        }

        public function test_set_amenities_stores_array(): void
        {
            $this->p->set_amenities( [ 'wifi', 'pool', 'parking' ] );
            $this->assertSame( [ 'wifi', 'pool', 'parking' ], $this->p->get_amenities() );
        }

        public function test_set_amenities_empty_array(): void
        {
            $this->p->set_amenities( [] );
            $this->assertSame( [], $this->p->get_amenities() );
        }

        public function test_set_amenities_single_item(): void
        {
            $this->p->set_amenities( [ 'gym' ] );
            $this->assertSame( [ 'gym' ], $this->p->get_amenities() );
        }

        public function test_set_amenities_preserves_order(): void
        {
            $list = [ 'spa', 'breakfast', 'airport_transfer', 'gym' ];
            $this->p->set_amenities( $list );
            $this->assertSame( $list, $this->p->get_amenities() );
        }

        public function test_set_amenities_strips_html_from_items(): void
        {
            $this->p->set_amenities( [ '<b>wifi</b>', 'pool' ] );
            $this->assertSame( [ 'wifi', 'pool' ], $this->p->get_amenities() );
        }

        public function test_set_amenities_count_preserved(): void
        {
            $this->p->set_amenities( [ 'a', 'b', 'c', 'd', 'e' ] );
            $this->assertCount( 5, $this->p->get_amenities() );
        }

        // ------------------------------------------------------------------ //
        //  rules_text  — wp_kses_post applied
        // ------------------------------------------------------------------ //

        public function test_get_rules_text_default_is_empty(): void
        {
            $this->assertSame( '', $this->p->get_rules_text() );
        }

        public function test_set_rules_text_stores_value(): void
        {
            $this->p->set_rules_text( 'No smoking.' );
            $this->assertSame( 'No smoking.', $this->p->get_rules_text() );
        }

        public function test_set_rules_text_html_preserved_via_kses(): void
        {
            // Our stub passes through all HTML (wp_kses_post stub returns as-is)
            $html = '<p>No pets allowed.</p>';
            $this->p->set_rules_text( $html );
            $this->assertSame( $html, $this->p->get_rules_text() );
        }

        public function test_set_rules_text_multiline(): void
        {
            $text = "Rule 1: No smoking.\nRule 2: No pets.";
            $this->p->set_rules_text( $text );
            $this->assertSame( $text, $this->p->get_rules_text() );
        }

        // ------------------------------------------------------------------ //
        //  instant_booking  — bool
        // ------------------------------------------------------------------ //

        public function test_is_instant_booking_default_false(): void
        {
            $this->assertFalse( $this->p->is_instant_booking() );
        }

        public function test_set_instant_booking_true(): void
        {
            $this->p->set_instant_booking( true );
            $this->assertTrue( $this->p->is_instant_booking() );
        }

        public function test_set_instant_booking_false(): void
        {
            $this->p->set_instant_booking( false );
            $this->assertFalse( $this->p->is_instant_booking() );
        }

        public function test_set_instant_booking_toggle(): void
        {
            $this->p->set_instant_booking( true );
            $this->p->set_instant_booking( false );
            $this->assertFalse( $this->p->is_instant_booking() );
        }

        public function test_set_instant_booking_toggle_back(): void
        {
            $this->p->set_instant_booking( false );
            $this->p->set_instant_booking( true );
            $this->assertTrue( $this->p->is_instant_booking() );
        }

        // ------------------------------------------------------------------ //
        //  Schema — get_extra_data() via reflection (method is protected)
        // ------------------------------------------------------------------ //

        private function schema(): array
        {
            $ref = new \ReflectionMethod( \LTMS_Product_Bookable::class, 'get_extra_data' );
            $ref->setAccessible( true );
            return $ref->invoke( $this->p );
        }

        public function test_extra_data_has_all_17_keys(): void
        {
            $schema = $this->schema();
            $expected = [
                'booking_type', 'min_nights', 'max_nights', 'deposit_pct',
                'policy_id', 'checkin_time', 'checkout_time', 'capacity',
                'payment_mode', 'advance_booking_days', 'max_advance_days',
                'rnt_number', 'sectur_folio', 'country_code',
                'amenities', 'rules_text', 'instant_booking',
            ];
            $this->assertCount( 17, $schema );
            foreach ( $expected as $key ) {
                $this->assertArrayHasKey( $key, $schema, "Schema missing key: {$key}" );
            }
        }

        public function test_extra_data_defaults_match_spec(): void
        {
            $s = $this->schema();
            $this->assertSame( 'accommodation', $s['booking_type'] );
            $this->assertSame( 1,               $s['min_nights'] );
            $this->assertSame( 0,               $s['max_nights'] );
            $this->assertSame( 0.0,             $s['deposit_pct'] );
            $this->assertSame( 0,               $s['policy_id'] );
            $this->assertSame( '15:00',         $s['checkin_time'] );
            $this->assertSame( '11:00',         $s['checkout_time'] );
            $this->assertSame( 1,               $s['capacity'] );
            $this->assertSame( 'full',          $s['payment_mode'] );
            $this->assertSame( 0,               $s['advance_booking_days'] );
            $this->assertSame( 365,             $s['max_advance_days'] );
            $this->assertSame( '',              $s['rnt_number'] );
            $this->assertSame( '',              $s['sectur_folio'] );
            $this->assertSame( 'CO',            $s['country_code'] );
            $this->assertSame( [],              $s['amenities'] );
            $this->assertSame( '',              $s['rules_text'] );
            $this->assertFalse(                 $s['instant_booking'] );
        }

        // ------------------------------------------------------------------ //
        //  Reflexión — métodos públicos y protegidos
        // ------------------------------------------------------------------ //

        public function test_reflection_get_extra_data_is_protected(): void
        {
            $ref = new \ReflectionMethod( \LTMS_Product_Bookable::class, 'get_extra_data' );
            $this->assertTrue( $ref->isProtected() );
        }

        public function test_reflection_read_extra_data_is_public(): void
        {
            $ref = new \ReflectionMethod( \LTMS_Product_Bookable::class, 'read_extra_data' );
            $this->assertTrue( $ref->isPublic() );
        }

        public function test_reflection_save_extra_data_is_public(): void
        {
            $ref = new \ReflectionMethod( \LTMS_Product_Bookable::class, 'save_extra_data' );
            $this->assertTrue( $ref->isPublic() );
        }

        public function test_reflection_set_booking_type_param_is_string(): void
        {
            $ref    = new \ReflectionMethod( \LTMS_Product_Bookable::class, 'set_booking_type' );
            $params = $ref->getParameters();
            $this->assertCount( 1, $params );
            $this->assertSame( 'string', $params[0]->getType()->getName() );
        }

        public function test_reflection_set_deposit_pct_param_is_float(): void
        {
            $ref    = new \ReflectionMethod( \LTMS_Product_Bookable::class, 'set_deposit_pct' );
            $params = $ref->getParameters();
            $this->assertSame( 'float', $params[0]->getType()->getName() );
        }

        public function test_reflection_is_instant_booking_return_type_bool(): void
        {
            $ref = new \ReflectionMethod( \LTMS_Product_Bookable::class, 'is_instant_booking' );
            $this->assertSame( 'bool', $ref->getReturnType()->getName() );
        }

        public function test_reflection_get_amenities_return_type_array(): void
        {
            $ref = new \ReflectionMethod( \LTMS_Product_Bookable::class, 'get_amenities' );
            $this->assertSame( 'array', $ref->getReturnType()->getName() );
        }

        // ------------------------------------------------------------------ //
        //  Cross-field invariants
        // ------------------------------------------------------------------ //

        public function test_deposit_pct_and_capacity_are_independent(): void
        {
            $this->p->set_deposit_pct( 50.0 );
            $this->p->set_capacity( 10 );
            $this->assertSame( 50.0, $this->p->get_deposit_pct() );
            $this->assertSame( 10,   $this->p->get_capacity() );
        }

        public function test_min_and_max_nights_stored_independently(): void
        {
            $this->p->set_min_nights( 3 );
            $this->p->set_max_nights( 14 );
            $this->assertSame( 3,  $this->p->get_min_nights() );
            $this->assertSame( 14, $this->p->get_max_nights() );
        }

        public function test_rnt_and_sectur_stored_independently(): void
        {
            $this->p->set_rnt_number( 'RNT-111' );
            $this->p->set_sectur_folio( 'MX-222' );
            $this->assertSame( 'RNT-111', $this->p->get_rnt_number() );
            $this->assertSame( 'MX-222',  $this->p->get_sectur_folio() );
        }

        public function test_all_defaults_intact_on_fresh_instance(): void
        {
            $p = new \LTMS_Product_Bookable();
            $this->assertSame( 'accommodation', $p->get_booking_type() );
            $this->assertSame( 1,               $p->get_min_nights() );
            $this->assertSame( 0,               $p->get_max_nights() );
            $this->assertSame( 0.0,             $p->get_deposit_pct() );
            $this->assertSame( 0,               $p->get_policy_id() );
            $this->assertSame( '15:00',         $p->get_checkin_time() );
            $this->assertSame( '11:00',         $p->get_checkout_time() );
            $this->assertSame( 1,               $p->get_capacity() );
            $this->assertSame( 'full',          $p->get_payment_mode() );
            $this->assertSame( 0,               $p->get_advance_booking_days() );
            $this->assertSame( 365,             $p->get_max_advance_days() );
            $this->assertSame( '',              $p->get_rnt_number() );
            $this->assertSame( '',              $p->get_sectur_folio() );
            $this->assertSame( 'CO',            $p->get_country_code() );
            $this->assertSame( [],              $p->get_amenities() );
            $this->assertSame( '',              $p->get_rules_text() );
            $this->assertFalse(                 $p->is_instant_booking() );
        }
    }
}

