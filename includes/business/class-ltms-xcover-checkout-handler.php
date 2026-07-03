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
        // XC-3 FIX: add insurance premium as a WooCommerce cart fee so the
        // customer actually pays for the insurance. Previously the premium was
        // never charged — the listener created a policy via XCover after payment,
        // but the order total did not include the premium, so the merchant was
        // billed by XCover out of pocket (free insurance for the customer).
        add_action( 'woocommerce_cart_calculate_fees', [ $instance, 'add_insurance_fee' ], 40 );
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

                // XC-2 FIX: reject negative or zero premiums. A negative premium
                // would be a fraud vector (credit to the customer) and a zero
                // premium indicates a malformed quote. XCover premiums must be > 0.
                if ( $premium <= 0 ) {
                    LTMS_Core_Logger::warning( 'XCOVER_QUOTE_INVALID_PREMIUM',
                        sprintf( 'Rejected quote %s for type %s: premium=%.4f (must be > 0)', $quote_id, $type, $premium ) );
                    continue;
                }

                if ( $quote_id ) {
                    // Store in session — BOTH quote_id and premium, so the checkout
                    // can validate the user-submitted quote_id (XC-1) and add the
                    // premium as a cart fee (XC-3).
                    if ( WC()->session ) {
                        WC()->session->set( 'ltms_xcover_quote_' . $type, $quote_id );
                        WC()->session->set( 'ltms_xcover_premium_' . $type, $premium );
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

        // XC-1 FIX: validate quote_id format — XCover quote IDs are opaque
        // alphanumeric tokens (typically UUIDs or short hashes). Reject anything
        // with weird characters to prevent injection / spoofing.
        if ( ! preg_match( '/^[A-Za-z0-9_\-]{1,128}$/', $quote_id ) ) {
            LTMS_Core_Logger::warning( 'XCOVER_QUOTE_ID_INVALID',
                sprintf( 'Order #%d: rejected invalid quote_id format: %s', $order->get_id(), $quote_id ) );
            return;
        }

        if ( ! in_array( $type, [ 'parcel_protection', 'purchase_protection' ], true ) ) {
            LTMS_Core_Logger::warning( 'XCOVER_TYPE_INVALID',
                sprintf( 'Order #%d: rejected invalid insurance type: %s', $order->get_id(), $type ) );
            return;
        }

        // XC-1 FIX: validate that the submitted quote_id was actually issued by
        // XCover for this session/cart. Previously the user could submit ANY
        // quote_id (e.g. one issued for a $1 cart total → cheap premium) and
        // apply it to a $1000 cart. Now we cross-check against the session.
        $session_quote_id = '';
        $session_premium  = 0.0;
        if ( WC()->session ) {
            $session_quote_id = (string) WC()->session->get( 'ltms_xcover_quote_' . $type, '' );
            $session_premium  = (float) WC()->session->get( 'ltms_xcover_premium_' . $type, 0 );
        }

        if ( ! $session_quote_id || ! hash_equals( $session_quote_id, $quote_id ) ) {
            LTMS_Core_Logger::warning( 'XCOVER_QUOTE_ID_MISMATCH',
                sprintf( 'Order #%d: submitted quote_id %s does not match session quote_id %s for type %s — possible fraud attempt',
                    $order->get_id(), $quote_id, $session_quote_id, $type ) );
            return;
        }

        // XC-2 FIX: defensive re-check — premium must be positive.
        if ( $session_premium <= 0 ) {
            LTMS_Core_Logger::warning( 'XCOVER_PREMIUM_INVALID',
                sprintf( 'Order #%d: session premium for type %s is %.4f — refusing to save insurance selection',
                    $order->get_id(), $type, $session_premium ) );
            return;
        }

        if ( $quote_id && $type ) {
            $order->update_meta_data( '_ltms_insurance_selected', 'yes' );
            $order->update_meta_data( '_ltms_insurance_quote_id', $quote_id );
            $order->update_meta_data( '_ltms_insurance_type', $type );
            // XC-3 FIX: persist the verified premium on the order so the listener
            // and admin can audit that the customer was actually charged.
            $order->update_meta_data( '_ltms_insurance_premium', $session_premium );
        }
    }

    /**
     * XC-3 FIX: adds the insurance premium as a WooCommerce cart fee so the
     * customer actually pays for the insurance as part of their order.
     *
     * Reads the user's selected insurance type from the checkout POST (during
     * AJAX order-review updates) or from the WC session (fallback), then looks
     * up the premium from the session (set by ajax_get_quotes). The premium is
     * never trusted from the client — it always comes from the server-side
     * session that was populated directly from the XCover API response.
     *
     * @param \WC_Cart $cart
     */
    public function add_insurance_fee( \WC_Cart $cart ): void {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }
        if ( ! WC()->session ) {
            return;
        }

        // Determine selected type: prefer POST (live checkout update), fall back to session.
        $selected = 'no';
        $type     = '';
        if ( ! empty( $_POST['ltms_insurance_selected'] ) ) { // phpcs:ignore
            $selected = sanitize_key( $_POST['ltms_insurance_selected'] ); // phpcs:ignore
            $type     = sanitize_key( $_POST['ltms_insurance_type'] ?? '' ); // phpcs:ignore
        }
        if ( $selected !== 'yes' ) {
            $selected = (string) WC()->session->get( 'ltms_xcover_selected', 'no' );
            $type     = (string) WC()->session->get( 'ltms_xcover_selected_type', '' );
        }
        if ( $selected !== 'yes' || ! $type ) {
            return;
        }

        if ( ! in_array( $type, [ 'parcel_protection', 'purchase_protection' ], true ) ) {
            return;
        }

        $premium = (float) WC()->session->get( 'ltms_xcover_premium_' . $type, 0 );
        // XC-2 FIX: never add a non-positive fee.
        if ( $premium <= 0 ) {
            return;
        }

        // Persist selection in session so the fee survives subsequent AJAX updates.
        WC()->session->set( 'ltms_xcover_selected', 'yes' );
        WC()->session->set( 'ltms_xcover_selected_type', $type );

        $label = $type === 'parcel_protection'
            ? __( 'Seguro de Paquete', 'ltms' )
            : __( 'Seguro de Compra', 'ltms' );

        $cart->add_fee( $label, $premium, true );
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
