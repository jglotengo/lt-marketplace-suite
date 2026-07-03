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
                    // HK-BUG-1 FIX: API client reads 'weight_kg' (see LTMS_Api_Heka::get_rates).
                    // Previously the key was 'weight', which the client ignored — resulting
                    // in weight_kg=0.0 being sent to Heka and all quotes being for 0kg.
                    'weight_kg'        => max( 0.1, (float) $weight ),
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

        // Add each rate option returned by Heka.
        // HK-BUG-2 FIX: the API client's get_rates() docblock documents the
        // returned fields as carrier, price, eta_days, service_type — there is
        // no 'service_code' nor 'service_name'. Reading those keys returned
        // uniqid() (unstable rate id, breaking cache/dedupe) and the generic
        // 'Estándar' label for every option (no way to distinguish Express vs
        // Estándar vs Día Siguiente). Use 'service_type' for both the rate id
        // suffix and the display label, falling back to a translated 'Estándar'
        // when the API omits it.
        foreach ( $rates as $rate ) {
            $service_type = $rate['service_type'] ?? '';
            $this->add_rate( [
                'id'    => $this->get_rate_id() . '_' . ( $service_type !== '' ? sanitize_key( $service_type ) : uniqid() ),
                'label' => sprintf(
                    '%s - %s',
                    $this->title,
                    $service_type !== '' ? $service_type : __( 'Estándar', 'ltms' )
                ),
                'cost'  => (float) ( $rate['price'] ?? $rate['total'] ?? 0 ),
            ] );
        }
    }

    public static function register( array $methods ): array {
        $methods[] = 'LTMS_Shipping_Method_Heka';
        return $methods;
    }
}
