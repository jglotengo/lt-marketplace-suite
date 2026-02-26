<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class LTMS_XCover_Checkout_Handler
 * Renders insurance option in WC checkout and stores quote in session.
 */
class LTMS_XCover_Checkout_Handler {

    use LTMS_Logger_Aware;
    use LTMS_Singleton;

    public static function init(): void {
        $instance = self::get_instance();
        add_action( 'woocommerce_review_order_before_submit', [ $instance, 'render_insurance_ui' ] );
        add_action( 'wp_ajax_ltms_get_xcover_quotes', [ $instance, 'ajax_get_quotes' ] );
        add_action( 'wp_ajax_nopriv_ltms_get_xcover_quotes', [ $instance, 'ajax_get_quotes' ] );
        add_action( 'woocommerce_checkout_create_order', [ $instance, 'save_insurance_selection' ] );
    }

    public function render_insurance_ui(): void {
        $types = $this->get_enabled_insurance_types();
        if ( empty( $types ) ) return;

        $nonce = wp_create_nonce( 'ltms_xcover_nonce' );
        ?>
        <div id="ltms-insurance-section" class="ltms-checkout-insurance" style="margin-bottom:15px;padding:12px;border:1px solid #ddd;border-radius:4px;">
            <h4 style="margin-top:0;"><?php esc_html_e( 'Protege tu Pedido', 'ltms' ); ?></h4>
            <div id="ltms-insurance-loading"><?php esc_html_e( 'Cargando opciones de seguro...', 'ltms' ); ?></div>
            <div id="ltms-insurance-options" style="display:none;"></div>
            <input type="hidden" name="ltms_insurance_selected" id="ltms_insurance_selected" value="no">
            <input type="hidden" name="ltms_insurance_quote_id" id="ltms_insurance_quote_id" value="">
            <input type="hidden" name="ltms_insurance_type" id="ltms_insurance_type" value="">
        </div>
        <script>
        jQuery(document).ready(function($){
            $.ajax({
                url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
                method: 'POST',
                data: { action: 'ltms_get_xcover_quotes', nonce: '<?php echo esc_js( $nonce ); ?>' },
                success: function(res) {
                    $('#ltms-insurance-loading').hide();
                    if (res.success && res.data.quotes) {
                        var html = '';
                        $.each(res.data.quotes, function(i, q) {
                            html += '<label style="display:block;cursor:pointer;margin-bottom:8px;">' +
                                '<input type="radio" name="ltms_insurance_option" value="' + q.quote_id + '" data-type="' + q.type + '" data-quote-id="' + q.quote_id + '">' +
                                ' ' + q.label + ' — <strong>' + q.premium_display + '</strong>' +
                                '</label>';
                        });
                        html += '<label style="display:block;cursor:pointer;"><input type="radio" name="ltms_insurance_option" value="none" checked> <?php esc_html_e( 'No, gracias', 'ltms' ); ?></label>';
                        $('#ltms-insurance-options').html(html).show();
                        $('input[name="ltms_insurance_option"]').on('change', function(){
                            var val = $(this).val();
                            if (val !== 'none') {
                                $('#ltms_insurance_selected').val('yes');
                                $('#ltms_insurance_quote_id').val($(this).data('quote-id'));
                                $('#ltms_insurance_type').val($(this).data('type'));
                            } else {
                                $('#ltms_insurance_selected').val('no');
                                $('#ltms_insurance_quote_id').val('');
                                $('#ltms_insurance_type').val('');
                            }
                        });
                    }
                },
                error: function() { $('#ltms-insurance-loading').hide(); }
            });
        });
        </script>
        <?php
    }

    public function ajax_get_quotes(): void {
        check_ajax_referer( 'ltms_xcover_nonce', 'nonce' );

        $cart    = WC()->cart;
        $total   = $cart ? (float) $cart->get_total( 'numeric' ) : 0;
        $country = LTMS_Core_Config::get_country();
        $types   = $this->get_enabled_insurance_types();

        $quotes = [];
        foreach ( $types as $type ) {
            try {
                $xcover = LTMS_Api_Factory::get( 'xcover' );
                $result = $xcover->get_quotes( [
                    'insurance_type' => $type,
                    'order_total'    => $total,
                    'currency'       => LTMS_Core_Config::get_currency(),
                    'country'        => $country,
                ] );
                $quote_id = $result['quote_id'] ?? ( $result['quotes'][0]['id'] ?? '' );
                $premium  = (float) ( $result['quotes'][0]['premium'] ?? $result['premium'] ?? 0 );
                if ( $quote_id ) {
                    // Store in session
                    if ( WC()->session ) {
                        WC()->session->set( 'ltms_xcover_quote_' . $type, $quote_id );
                    }
                    $quotes[] = [
                        'quote_id'       => $quote_id,
                        'type'           => $type,
                        'label'          => $type === 'parcel_protection'
                            ? __( 'Protección del Paquete', 'ltms' )
                            : __( 'Protección de Compra', 'ltms' ),
                        'premium'        => $premium,
                        'premium_display'=> LTMS_Utils::format_money( $premium ),
                    ];
                }
            } catch ( \Throwable $e ) {
                LTMS_Core_Logger::warning( 'XCOVER_QUOTE_FAILED', $e->getMessage() );
            }
        }

        wp_send_json_success( [ 'quotes' => $quotes ] );
    }

    public function save_insurance_selection( \WC_Order $order ): void {
        $selected = sanitize_key( $_POST['ltms_insurance_selected'] ?? 'no' ); // phpcs:ignore
        if ( $selected !== 'yes' ) return;

        $quote_id = sanitize_text_field( $_POST['ltms_insurance_quote_id'] ?? '' ); // phpcs:ignore
        $type     = sanitize_key( $_POST['ltms_insurance_type'] ?? '' ); // phpcs:ignore

        if ( $quote_id && $type ) {
            $order->update_meta_data( '_ltms_insurance_selected', 'yes' );
            $order->update_meta_data( '_ltms_insurance_quote_id', $quote_id );
            $order->update_meta_data( '_ltms_insurance_type', $type );
        }
    }

    private function get_enabled_insurance_types(): array {
        $types = [];
        if ( LTMS_Core_Config::get( 'ltms_xcover_parcel_protection', 'no' ) === 'yes' ) {
            $types[] = 'parcel_protection';
        }
        if ( LTMS_Core_Config::get( 'ltms_xcover_purchase_protection', 'no' ) === 'yes' ) {
            $types[] = 'purchase_protection';
        }
        return $types;
    }
}
