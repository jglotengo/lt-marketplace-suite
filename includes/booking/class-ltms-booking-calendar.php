<?php
/**
 * LTMS Booking Calendar
 *
 * Frontend: selector de fechas con Flatpickr range picker.
 * Carga fechas bloqueadas vía REST, calcula precio dinámico con temporadas.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/booking
 * @version    2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Booking_Calendar
 */
class LTMS_Booking_Calendar {

    private static bool $initialized = false;

    public static function init(): void {
        if ( self::$initialized ) return;
        self::$initialized = true;

        add_action( 'wp_enqueue_scripts',          [ self::class, 'enqueue_assets' ] );
        add_action( 'woocommerce_before_add_to_cart_button', [ self::class, 'render_calendar_widget' ] );
        add_action( 'rest_api_init',               [ self::class, 'register_rest_routes' ] );
        add_filter( 'woocommerce_add_cart_item_data', [ self::class, 'capture_booking_data' ], 10, 3 );
        add_filter( 'woocommerce_get_item_data',   [ self::class, 'display_cart_booking_data' ], 10, 2 );
        add_action( 'woocommerce_checkout_create_order_line_item', [ self::class, 'save_order_item_meta' ], 10, 4 );
    }

    // ── Assets ───────────────────────────────────────────────────────────

    public static function enqueue_assets(): void {
        if ( ! is_product() ) return;

        $product = wc_get_product( get_the_ID() );
        if ( ! $product || 'ltms_bookable' !== $product->get_type() ) return;

        wp_enqueue_style(
            'flatpickr',
            'https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/flatpickr.min.css',
            [],
            '4.6.13'
        );
        wp_enqueue_style(
            'ltms-booking-calendar',
            LTMS_PLUGIN_URL . 'assets/css/ltms-booking-calendar.css',
            [ 'flatpickr' ],
            LTMS_VERSION
        );
        wp_enqueue_script(
            'flatpickr',
            'https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/flatpickr.min.js',
            [],
            '4.6.13',
            true
        );
        wp_enqueue_script(
            'flatpickr-es',
            'https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/l10n/es.min.js',
            [ 'flatpickr' ],
            '4.6.13',
            true
        );
        wp_enqueue_script(
            'ltms-booking-calendar',
            LTMS_PLUGIN_URL . 'assets/js/ltms-booking-calendar.js',
            [ 'flatpickr', 'flatpickr-es', 'jquery' ],
            LTMS_VERSION,
            true
        );

        $blocked = class_exists( 'LTMS_Booking_Manager' )
            ? LTMS_Booking_Manager::get_blocked_dates( get_the_ID() )
            : [];

        wp_localize_script( 'ltms-booking-calendar', 'ltmsBookingData', [
            'productId'      => get_the_ID(),
            'blockedDates'   => $blocked,
            'minNights'      => method_exists( $product, 'get_min_nights' ) ? $product->get_min_nights() : 1,
            'maxNights'      => method_exists( $product, 'get_max_nights' ) ? $product->get_max_nights() : 0,
            'checkinTime'    => method_exists( $product, 'get_checkin_time' ) ? $product->get_checkin_time() : '15:00',
            'checkoutTime'   => method_exists( $product, 'get_checkout_time' ) ? $product->get_checkout_time() : '11:00',
            'pricePerNight'  => (float) $product->get_price(),
            'currency'       => get_woocommerce_currency_symbol(),
            'restUrl'        => rest_url( 'ltms/v1/booking-calendar/' ),
            'nonce'          => wp_create_nonce( 'wp_rest' ),
            'i18n'           => [
                'selectDates'    => __( 'Selecciona las fechas de tu estadía', 'ltms' ),
                'nights'         => __( 'noches', 'ltms' ),
                'night'          => __( 'noche', 'ltms' ),
                'checkin'        => __( 'Check-in', 'ltms' ),
                'checkout'       => __( 'Check-out', 'ltms' ),
                'total'          => __( 'Total estimado', 'ltms' ),
                'guests'         => __( 'Huéspedes', 'ltms' ),
                'minNightsError' => sprintf( __( 'Mínimo %d noches requeridas.', 'ltms' ), $product->get_min_nights() ),
            ],
        ] );
    }

    // ── Widget render ────────────────────────────────────────────────────

    public static function render_calendar_widget(): void {
        global $product;
        if ( ! $product || 'ltms_bookable' !== $product->get_type() ) return;

        $capacity = method_exists( $product, 'get_capacity' ) ? $product->get_capacity() : 1;
        ?>
        <div class="ltms-booking-widget" id="ltms-booking-widget">
            <h3 class="ltms-booking-widget__title"><?php esc_html_e( 'Reserva tu estadía', 'ltms' ); ?></h3>

            <div class="ltms-booking-widget__calendar">
                <label for="ltms-date-range"><?php esc_html_e( 'Fechas', 'ltms' ); ?></label>
                <input type="text" id="ltms-date-range" class="ltms-date-input" readonly
                       placeholder="<?php esc_attr_e( 'Selecciona tus fechas', 'ltms' ); ?>">
                <input type="hidden" name="ltms_checkin_date"  id="ltms-checkin">
                <input type="hidden" name="ltms_checkout_date" id="ltms-checkout">
            </div>

            <div class="ltms-booking-widget__guests">
                <label for="ltms-guests"><?php esc_html_e( 'Huéspedes', 'ltms' ); ?></label>
                <input type="number" name="ltms_guests" id="ltms-guests"
                       min="1" max="<?php echo (int) $capacity; ?>" value="1" class="input-text qty">
            </div>

            <div class="ltms-booking-widget__summary" id="ltms-booking-summary" style="display:none;">
                <div class="ltms-summary-row">
                    <span id="ltms-nights-label"></span>
                    <strong id="ltms-total-price"></strong>
                </div>
                <div class="ltms-summary-row ltms-checkin-checkout">
                    <span><?php esc_html_e( 'Check-in', 'ltms' ); ?>: <strong id="ltms-display-checkin"></strong></span>
                    <span><?php esc_html_e( 'Check-out', 'ltms' ); ?>: <strong id="ltms-display-checkout"></strong></span>
                </div>
            </div>

            <div id="ltms-calendar-error" class="woocommerce-error" style="display:none;"></div>
        </div>
        <?php
    }

    // ── REST API ─────────────────────────────────────────────────────────

    public static function register_rest_routes(): void {
        register_rest_route( 'ltms/v1', '/booking-calendar/(?P<product_id>\d+)/blocked-dates', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'rest_blocked_dates' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'product_id' => [ 'type' => 'integer', 'required' => true ],
                'from'       => [ 'type' => 'string',  'default'  => '' ],
                'to'         => [ 'type' => 'string',  'default'  => '' ],
            ],
        ] );

        register_rest_route( 'ltms/v1', '/booking-calendar/(?P<product_id>\d+)/price', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'rest_calculate_price' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'product_id'   => [ 'type' => 'integer', 'required' => true ],
                'checkin_date' => [ 'type' => 'string',  'required' => true ],
                'checkout_date'=> [ 'type' => 'string',  'required' => true ],
            ],
        ] );
    }

    public static function rest_blocked_dates( \WP_REST_Request $request ): \WP_REST_Response {
        $product_id = (int) $request->get_param( 'product_id' );
        $from       = sanitize_text_field( $request->get_param( 'from' ) );
        $to         = sanitize_text_field( $request->get_param( 'to' ) );

        $dates = class_exists( 'LTMS_Booking_Manager' )
            ? LTMS_Booking_Manager::get_blocked_dates( $product_id, $from, $to )
            : [];

        return new \WP_REST_Response( [ 'blocked_dates' => $dates ], 200 );
    }

    public static function rest_calculate_price( \WP_REST_Request $request ): \WP_REST_Response {
        $product_id    = (int) $request->get_param( 'product_id' );
        $checkin_date  = sanitize_text_field( $request->get_param( 'checkin_date' ) );
        $checkout_date = sanitize_text_field( $request->get_param( 'checkout_date' ) );

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return new \WP_REST_Response( [ 'error' => 'Product not found' ], 404 );
        }

        $base       = (float) $product->get_price();
        $total      = class_exists( 'LTMS_Booking_Season_Manager' )
            ? LTMS_Booking_Season_Manager::calculate_total( $base, $product_id, $checkin_date, $checkout_date )
            : $base * max( 1, (int) floor( ( strtotime( $checkout_date ) - strtotime( $checkin_date ) ) / DAY_IN_SECONDS ) );
        $nights     = max( 0, (int) floor( ( strtotime( $checkout_date ) - strtotime( $checkin_date ) ) / DAY_IN_SECONDS ) );
        $symbol     = get_woocommerce_currency_symbol();

        return new \WP_REST_Response( [
            'nights'         => $nights,
            'total'          => $total,
            'total_formatted'=> $symbol . number_format( $total, 0, ',', '.' ),
        ], 200 );
    }

    // ── Cart integration ─────────────────────────────────────────────────

    public static function capture_booking_data( array $cart_item_data, int $product_id, int $variation_id ): array {
        $product = wc_get_product( $product_id );
        if ( ! $product || 'ltms_bookable' !== $product->get_type() ) {
            return $cart_item_data;
        }

        $checkin  = sanitize_text_field( $_POST['ltms_checkin_date']  ?? '' );
        $checkout = sanitize_text_field( $_POST['ltms_checkout_date'] ?? '' );
        $guests   = max( 1, (int) ( $_POST['ltms_guests'] ?? 1 ) );

        if ( ! $checkin || ! $checkout ) {
            wc_add_notice( __( 'Por favor selecciona las fechas de check-in y check-out.', 'ltms' ), 'error' );
            return $cart_item_data;
        }

        if ( class_exists( 'LTMS_Booking_Manager' ) && ! LTMS_Booking_Manager::is_available( $product_id, $checkin, $checkout ) ) {
            wc_add_notice( __( 'Las fechas seleccionadas no están disponibles.', 'ltms' ), 'error' );
            return $cart_item_data;
        }

        $cart_item_data['_ltms_checkin_date']  = $checkin;
        $cart_item_data['_ltms_checkout_date'] = $checkout;
        $cart_item_data['_ltms_guests']        = $guests;
        $cart_item_data['unique_key']          = md5( $product_id . $checkin . $checkout . $guests . microtime() );

        return $cart_item_data;
    }

    public static function display_cart_booking_data( array $item_data, array $cart_item ): array {
        if ( isset( $cart_item['_ltms_checkin_date'] ) ) {
            $item_data[] = [
                'name'  => __( 'Check-in', 'ltms' ),
                'value' => esc_html( $cart_item['_ltms_checkin_date'] ),
            ];
            $item_data[] = [
                'name'  => __( 'Check-out', 'ltms' ),
                'value' => esc_html( $cart_item['_ltms_checkout_date'] ),
            ];
            $item_data[] = [
                'name'  => __( 'Huéspedes', 'ltms' ),
                'value' => (int) $cart_item['_ltms_guests'],
            ];
        }
        return $item_data;
    }

    public static function save_order_item_meta( \WC_Order_Item_Product $item, string $cart_item_key, array $values, \WC_Order $order ): void {
        foreach ( [ '_ltms_checkin_date', '_ltms_checkout_date', '_ltms_guests' ] as $key ) {
            if ( isset( $values[ $key ] ) ) {
                $item->add_meta_data( $key, $values[ $key ], true );
            }
        }
    }
}
