<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class LTMS_Shipping_Method_Aveonline extends WC_Shipping_Method {
    public function __construct( $instance_id = 0 ) {
        $this->id                 = 'ltms_aveonline';
        $this->instance_id        = absint( $instance_id );
        $this->method_title       = __( 'Aveonline (Courier CO)', 'ltms' );
        $this->method_description = __( 'Envío con Aveonline para Colombia.', 'ltms' );
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
            'title'   => [ 'title' => __( 'Título', 'ltms' ), 'type' => 'text', 'default' => __( 'Aveonline', 'ltms' ) ],
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
            "SELECT quote_data FROM `{$table}` WHERE cache_key = %s AND provider = 'aveonline' AND expires_at > NOW()",
            $cache_key
        ));

        if ( $cached ) {
            $rates = json_decode( $cached->quote_data, true );
        } else {
            try {
                $aveonline = LTMS_Api_Factory::get( 'aveonline' );
                $dest      = $package['destination'] ?? [];
                $weight    = array_sum( array_map( fn($i) => ( $i['data'] ? $i['data']->get_weight() : 0.5 ) * $i['quantity'], $package['contents'] ?? [] ) );
                $value     = array_sum( array_map( fn($i) => (float) $i['line_total'], $package['contents'] ?? [] ) );
                $rates     = $aveonline->get_rates( [
                    'origin_city'      => LTMS_Core_Config::get( 'ltms_store_city', 'Bogotá' ),
                    'destination_city' => $dest['city'] ?? 'Bogotá',
                    'weight_kg'        => max( 0.1, (float) $weight ),
                    'declared_value'   => max( 0, (float) $value ),
                ] );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->replace( $table, [
                    'cache_key'  => $cache_key,
                    'provider'   => 'aveonline',
                    'quote_data' => wp_json_encode( $rates ),
                    'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 600 ),
                    'created_at' => LTMS_Utils::now_utc(),
                ], [ '%s', '%s', '%s', '%s', '%s' ] );
            } catch ( \Throwable $e ) {
                LTMS_Core_Logger::warning( 'AVEONLINE_RATE_FAILED', $e->getMessage() );
                return;
            }
        }

        if ( ! is_array( $rates ) ) {
            return;
        }

        // Aveonline v2 retorna array 'cotizaciones' con la estructura del endpoint cotizar2.
        // Por cada transportadora se registran DOS rates: entrega a domicilio y recogida en oficina.
        // Las transportadoras sin soporte de oficinas (ej: Envia=29) solo generan el rate de domicilio.
        foreach ( $rates as $rate ) {
            $cost = (float) ( $rate['total'] ?? $rate['valorTotal'] ?? $rate['price'] ?? 0 );
            if ( $cost <= 0 ) {
                continue;
            }
            $carrier_code   = (string) ( $rate['codTransportadora'] ?? $rate['service_code'] ?? '' );
            $transportadora = $rate['nombreTransportadora'] ?? $rate['service_name'] ?? __( 'Estandar', 'ltms' );
            $dias           = (int) ( $rate['diasentrega'] ?? $rate['estimated_days'] ?? 0 );
            $rate_suffix    = $carrier_code ?: sanitize_key( $transportadora );
            $dias_label     = $dias > 0 ? sprintf( ' (%d %s)', $dias, __( 'dias', 'ltms' ) ) : '';

            // Rate 1: entrega a domicilio (siempre disponible).
            $this->add_rate( [
                'id'        => $this->get_rate_id() . '_' . $rate_suffix . '_domicilio',
                'label'     => sprintf(
                    '%s — %s — %s%s',
                    $this->title,
                    $transportadora,
                    __( 'Envio a domicilio', 'ltms' ),
                    $dias_label
                ),
                'cost'      => $cost,
                'meta_data' => [
                    'ltms_delivery_mode' => 'domicilio',
                    'ltms_carrier_code'  => $carrier_code,
                    'ltms_carrier_name'  => $transportadora,
                ],
            ] );

            // Rate 2: recogida en oficina (solo transportadoras soportadas por /offices/all).
            $has_offices = class_exists( 'LTMS_Business_Aveonline_Offices' )
                && LTMS_Business_Aveonline_Offices::is_valid_carrier( $carrier_code );

            if ( $has_offices ) {
                $this->add_rate( [
                    'id'        => $this->get_rate_id() . '_' . $rate_suffix . '_oficina',
                    'label'     => sprintf(
                        '%s — %s — %s%s',
                        $this->title,
                        $transportadora,
                        __( 'Recoger en oficina', 'ltms' ),
                        $dias_label
                    ),
                    'cost'      => $cost,
                    'meta_data' => [
                        'ltms_delivery_mode' => 'oficina',
                        'ltms_carrier_code'  => $carrier_code,
                        'ltms_carrier_name'  => $transportadora,
                    ],
                ] );
            }
        }
    }

    public static function register( array $methods ): array {
        $methods[] = 'LTMS_Shipping_Method_Aveonline';
        return $methods;
    }
}
