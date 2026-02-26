<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class LTMS_Shipping_Method_Heka extends WC_Shipping_Method {
    public function __construct( $instance_id = 0 ) {
        $this->id                 = 'ltms_heka';
        $this->instance_id        = absint( $instance_id );
        $this->method_title       = __( 'Heka Entrega', 'ltms' );
        $this->method_description = __( 'Envío con Heka para Colombia.', 'ltms' );
        $this->supports           = [ 'shipping-zones', 'instance-settings' ];
        $this->init();
    }

    public function init(): void {
        $this->init_form_fields();
        $this->init_settings();
        $this->title   = $this->get_option( 'title', $this->method_title );
        $this->enabled = $this->get_option( 'enabled', 'yes' );
        add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );
    }

    public function init_form_fields(): void {
        $this->form_fields = [
            'enabled' => [ 'title' => __( 'Habilitar', 'ltms' ), 'type' => 'checkbox', 'default' => 'yes' ],
            'title'   => [ 'title' => __( 'Título', 'ltms' ), 'type' => 'text', 'default' => __( 'Heka Entrega', 'ltms' ) ],
        ];
    }

    public function calculate_shipping( $package = [] ): void {
        if ( LTMS_Core_Config::get_country() !== 'CO' ) {
            return; // Colombia only
        }

        $cache_key = hash( 'sha256', wp_json_encode( [
            'origin'      => $package['origin'] ?? [],
            'destination' => $package['destination'] ?? [],
            'contents'    => array_map( fn($i) => ['qty' => $i['quantity']], $package['contents'] ?? [] ),
        ]) );

        global $wpdb;
        $table = $wpdb->prefix . 'lt_shipping_quotes_cache';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $cached = $wpdb->get_row( $wpdb->prepare(
            "SELECT quote_data FROM `{$table}` WHERE cache_key = %s AND provider = 'heka' AND expires_at > NOW()",
            $cache_key
        ));

        if ( $cached ) {
            $rates = json_decode( $cached->quote_data, true );
        } else {
            try {
                $heka   = LTMS_Api_Factory::get( 'heka' );
                $dest   = $package['destination'] ?? [];
                $weight = array_sum( array_map( fn($i) => ( $i['data'] ? $i['data']->get_weight() : 0.5 ) * $i['quantity'], $package['contents'] ?? [] ) );
                $value  = array_sum( array_map( fn($i) => (float) $i['line_total'], $package['contents'] ?? [] ) );
                $rates  = $heka->get_rates( [
                    'origin_city'      => LTMS_Core_Config::get( 'ltms_store_city', 'Bogotá' ),
                    'destination_city' => $dest['city'] ?? 'Bogotá',
                    'weight'           => max( 0.1, (float) $weight ),
                    'declared_value'   => max( 0, (float) $value ),
                ] );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->replace( $table, [
                    'cache_key'  => $cache_key,
                    'provider'   => 'heka',
                    'quote_data' => wp_json_encode( $rates ),
                    'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 600 ),
                    'created_at' => LTMS_Utils::now_utc(),
                ], [ '%s', '%s', '%s', '%s', '%s' ] );
            } catch ( \Throwable $e ) {
                LTMS_Core_Logger::warning( 'HEKA_RATE_FAILED', $e->getMessage() );
                return;
            }
        }

        if ( ! is_array( $rates ) ) {
            return;
        }

        // Add each rate option returned by Heka
        foreach ( $rates as $rate ) {
            $this->add_rate( [
                'id'    => $this->get_rate_id() . '_' . ( $rate['service_code'] ?? uniqid() ),
                'label' => sprintf( '%s - %s', $this->title, $rate['service_name'] ?? __( 'Estándar', 'ltms' ) ),
                'cost'  => (float) ( $rate['price'] ?? $rate['total'] ?? 0 ),
            ] );
        }
    }

    public static function register( array $methods ): array {
        $methods[] = 'LTMS_Shipping_Method_Heka';
        return $methods;
    }
}
