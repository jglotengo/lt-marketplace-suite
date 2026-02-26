<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class LTMS_Shipping_Method_Uber_Direct extends WC_Shipping_Method {
    public function __construct( $instance_id = 0 ) {
        $this->id                 = 'ltms_uber_direct';
        $this->instance_id        = absint( $instance_id );
        $this->method_title       = __( 'Uber Direct (Entrega Inmediata)', 'ltms' );
        $this->method_description = __( 'Envío express con Uber Direct. Disponible según tu ubicación.', 'ltms' );
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
            'enabled' => [
                'title'   => __( 'Habilitar', 'ltms' ),
                'type'    => 'checkbox',
                'default' => 'yes',
            ],
            'title' => [
                'title'   => __( 'Título', 'ltms' ),
                'type'    => 'text',
                'default' => __( 'Uber Direct', 'ltms' ),
            ],
        ];
    }

    public function calculate_shipping( $package = [] ): void {
        // Try cache first
        $cache_key = hash( 'sha256', wp_json_encode( [
            'origin'      => $package['origin'] ?? [],
            'destination' => $package['destination'] ?? [],
            'contents'    => array_map( fn($i) => ['qty' => $i['quantity'], 'pid' => $i['product_id'] ?? 0], $package['contents'] ?? [] ),
        ]) );

        global $wpdb;
        $table = $wpdb->prefix . 'lt_shipping_quotes_cache';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $cached = $wpdb->get_row( $wpdb->prepare(
            "SELECT quote_data FROM `{$table}` WHERE cache_key = %s AND provider = 'uber' AND expires_at > NOW()",
            $cache_key
        ));

        if ( $cached ) {
            $quote = json_decode( $cached->quote_data, true );
        } else {
            try {
                $uber  = LTMS_Api_Factory::get( 'uber' );
                $quote = $uber->get_quote( $this->build_quote_data( $package ) );
                // Cache for 10 minutes
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->replace( $table, [
                    'cache_key'  => $cache_key,
                    'provider'   => 'uber',
                    'quote_data' => wp_json_encode( $quote ),
                    'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 600 ),
                    'created_at' => LTMS_Utils::now_utc(),
                ], [ '%s', '%s', '%s', '%s', '%s' ] );
            } catch ( \Throwable $e ) {
                LTMS_Core_Logger::warning( 'UBER_QUOTE_FAILED', $e->getMessage() );
                return;
            }
        }

        $price = $quote['fee'] ?? $quote['currency_code'] ?? 0;
        // Try to extract numeric price from quote
        if ( isset( $quote['fee'] ) ) {
            $price = (float) $quote['fee'] / 100; // Uber returns cents
        }

        $this->add_rate( [
            'id'    => $this->get_rate_id(),
            'label' => $this->title,
            'cost'  => $price,
            'meta_data' => [ '_uber_quote_id' => $quote['id'] ?? '' ],
        ] );
    }

    private function build_quote_data( array $package ): array {
        $dest = $package['destination'] ?? [];
        return [
            'pickup_address'   => [
                'street_address' => [ LTMS_Core_Config::get( 'ltms_store_address', '' ) ],
                'city'           => LTMS_Core_Config::get( 'ltms_store_city', '' ),
                'state'          => LTMS_Core_Config::get( 'ltms_store_state', '' ),
                'zip_code'       => LTMS_Core_Config::get( 'ltms_store_zip', '' ),
                'country'        => LTMS_Core_Config::get_country() === 'MX' ? 'MX' : 'CO',
            ],
            'dropoff_address'  => [
                'street_address' => [ $dest['address'] ?? $dest['address_1'] ?? '' ],
                'city'           => $dest['city'] ?? '',
                'state'          => $dest['state'] ?? '',
                'zip_code'       => $dest['postcode'] ?? '',
                'country'        => $dest['country'] ?? 'CO',
            ],
            'manifest_items'   => $this->build_manifest( $package['contents'] ?? [] ),
        ];
    }

    private function build_manifest( array $contents ): array {
        $items = [];
        foreach ( $contents as $item ) {
            $product = $item['data'] ?? null;
            $items[] = [
                'name'     => $product ? $product->get_name() : 'Producto',
                'quantity' => (int) ( $item['quantity'] ?? 1 ),
                'size'     => 'small',
            ];
        }
        return $items ?: [ [ 'name' => 'Paquete', 'quantity' => 1, 'size' => 'small' ] ];
    }

    public static function register( array $methods ): array {
        $methods[] = 'LTMS_Shipping_Method_Uber_Direct';
        return $methods;
    }
}
