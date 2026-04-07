<?php
/**
 * LTMS Product Bookable
 *
 * Tipo de producto WooCommerce para reservas.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/wc-types
 * @version    2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Product_Bookable
 */
class LTMS_Product_Bookable extends WC_Product {

    public function get_type(): string { return 'ltms_bookable'; }

    public function get_booking_type( string $context = 'view' ): string {
        return $this->get_prop( 'booking_type', $context ) ?: 'accommodation';
    }
    public function set_booking_type( string $value ): void {
        $allowed = [ 'accommodation', 'experience', 'rental', 'professional_service', 'space', 'restaurant' ];
        $this->set_prop( 'booking_type', in_array( $value, $allowed, true ) ? $value : 'accommodation' );
    }

    public function get_min_nights( string $context = 'view' ): int { return (int) ( $this->get_prop( 'min_nights', $context ) ?: 1 ); }
    public function set_min_nights( int $value ): void { $this->set_prop( 'min_nights', max( 1, $value ) ); }

    public function get_max_nights( string $context = 'view' ): int { return (int) ( $this->get_prop( 'max_nights', $context ) ?: 0 ); }
    public function set_max_nights( int $value ): void { $this->set_prop( 'max_nights', max( 0, $value ) ); }

    public function get_deposit_pct( string $context = 'view' ): float { return (float) ( $this->get_prop( 'deposit_pct', $context ) ?: 0 ); }
    public function set_deposit_pct( float $value ): void { $this->set_prop( 'deposit_pct', max( 0.0, min( 100.0, $value ) ) ); }

    public function get_policy_id( string $context = 'view' ): int { return (int) ( $this->get_prop( 'policy_id', $context ) ?: 0 ); }
    public function set_policy_id( int $value ): void { $this->set_prop( 'policy_id', max( 0, $value ) ); }

    public function get_checkin_time( string $context = 'view' ): string { return $this->get_prop( 'checkin_time', $context ) ?: '15:00'; }
    public function set_checkin_time( string $value ): void { $this->set_prop( 'checkin_time', sanitize_text_field( $value ) ); }

    public function get_checkout_time( string $context = 'view' ): string { return $this->get_prop( 'checkout_time', $context ) ?: '11:00'; }
    public function set_checkout_time( string $value ): void { $this->set_prop( 'checkout_time', sanitize_text_field( $value ) ); }

    public function get_capacity( string $context = 'view' ): int { return (int) ( $this->get_prop( 'capacity', $context ) ?: 1 ); }
    public function set_capacity( int $value ): void { $this->set_prop( 'capacity', max( 1, $value ) ); }

    public function get_payment_mode( string $context = 'view' ): string { return $this->get_prop( 'payment_mode', $context ) ?: 'full'; }
    public function set_payment_mode( string $value ): void {
        $this->set_prop( 'payment_mode', in_array( $value, [ 'full', 'deposit', 'reserve_only' ], true ) ? $value : 'full' );
    }

    public function get_advance_booking_days( string $context = 'view' ): int { return (int) ( $this->get_prop( 'advance_booking_days', $context ) ?: 0 ); }
    public function set_advance_booking_days( int $value ): void { $this->set_prop( 'advance_booking_days', max( 0, $value ) ); }

    public function get_max_advance_days( string $context = 'view' ): int { return (int) ( $this->get_prop( 'max_advance_days', $context ) ?: 365 ); }
    public function set_max_advance_days( int $value ): void { $this->set_prop( 'max_advance_days', max( 0, $value ) ); }

    public function get_rnt_number( string $context = 'view' ): string { return $this->get_prop( 'rnt_number', $context ) ?: ''; }
    public function set_rnt_number( string $value ): void { $this->set_prop( 'rnt_number', sanitize_text_field( $value ) ); }

    public function get_sectur_folio( string $context = 'view' ): string { return $this->get_prop( 'sectur_folio', $context ) ?: ''; }
    public function set_sectur_folio( string $value ): void { $this->set_prop( 'sectur_folio', sanitize_text_field( $value ) ); }

    public function get_country_code( string $context = 'view' ): string { return $this->get_prop( 'country_code', $context ) ?: 'CO'; }
    public function set_country_code( string $value ): void { $this->set_prop( 'country_code', strtoupper( sanitize_text_field( $value ) ) ); }

    public function get_amenities( string $context = 'view' ): array {
        $v = $this->get_prop( 'amenities', $context );
        return is_array( $v ) ? $v : [];
    }
    public function set_amenities( array $value ): void { $this->set_prop( 'amenities', array_map( 'sanitize_text_field', $value ) ); }

    public function get_rules_text( string $context = 'view' ): string { return $this->get_prop( 'rules_text', $context ) ?: ''; }
    public function set_rules_text( string $value ): void { $this->set_prop( 'rules_text', wp_kses_post( $value ) ); }

    public function is_instant_booking(): bool { return (bool) $this->get_prop( 'instant_booking' ); }
    public function set_instant_booking( bool $value ): void { $this->set_prop( 'instant_booking', $value ); }

    protected function get_extra_data(): array {
        return [
            'booking_type'         => 'accommodation',
            'min_nights'           => 1,
            'max_nights'           => 0,
            'deposit_pct'          => 0.0,
            'policy_id'            => 0,
            'checkin_time'         => '15:00',
            'checkout_time'        => '11:00',
            'capacity'             => 1,
            'payment_mode'         => 'full',
            'advance_booking_days' => 0,
            'max_advance_days'     => 365,
            'rnt_number'           => '',
            'sectur_folio'         => '',
            'country_code'         => 'CO',
            'amenities'            => [],
            'rules_text'           => '',
            'instant_booking'      => false,
        ];
    }

    public function read_extra_data( \WC_Data_Store_Interface $data_store ): void {
        foreach ( $this->get_extra_data() as $key => $default ) {
            $fn = 'set_' . $key;
            if ( is_callable( [ $this, $fn ] ) ) {
                $value = get_post_meta( $this->get_id(), '_ltms_' . $key, true );
                if ( '' !== $value && false !== $value ) {
                    if ( is_array( $default ) && is_string( $value ) ) $value = maybe_unserialize( $value );
                    $this->$fn( $value );
                }
            }
        }
    }

    public function save_extra_data( \WC_Data_Store_Interface $data_store ): void {
        foreach ( array_keys( $this->get_extra_data() ) as $key ) {
            $fn = 'get_' . $key;
            if ( is_callable( [ $this, $fn ] ) ) {
                update_post_meta( $this->get_id(), '_ltms_' . $key, $this->$fn( 'edit' ) );
            }
        }
    }
}
