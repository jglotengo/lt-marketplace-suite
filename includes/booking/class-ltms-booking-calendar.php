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
        // AUDIT-BOOKING-ENGINE #8 FIX: ajustar precio del carrito para deposit/reserve_only.
        add_filter( 'woocommerce_add_cart_item',   [ self::class, 'adjust_cart_item_price_for_deposit' ], 10, 1 );
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

        // AUDIT-BOOKING-ENGINE #10: add booking_type to JS data for conditional UI.
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
            'bookingNonce'   => wp_create_nonce( 'ltms_add_booking_' . get_the_ID() ),
            // AUDIT-BOOKING-ENGINE #7 FIX: booking_type para UI condicional.
            'bookingType'    => method_exists( $product, 'get_booking_type' ) ? $product->get_booking_type() : 'accommodation',
            'paymentMode'    => method_exists( $product, 'get_payment_mode' ) ? $product->get_payment_mode() : 'full',
            'depositPct'     => method_exists( $product, 'get_deposit_pct' )  ? $product->get_deposit_pct()  : 0,
            'i18n'           => [
                'selectDates'    => __( 'Selecciona las fechas de tu estadía', 'ltms' ),
                'selectDate'     => __( 'Selecciona la fecha', 'ltms' ),
                'nights'         => __( 'noches', 'ltms' ),
                'night'          => __( 'noche', 'ltms' ),
                'checkin'        => __( 'Check-in', 'ltms' ),
                'checkout'       => __( 'Check-out', 'ltms' ),
                'total'          => __( 'Total estimado', 'ltms' ),
                'guests'         => __( 'Huéspedes', 'ltms' ),
                'minNightsError' => sprintf( __( 'Mínimo %d noches requeridas.', 'ltms' ), $product->get_min_nights() ),
                'selectTime'     => __( 'Hora', 'ltms' ),
                'depositNow'     => __( 'Pagar ahora (depósito)', 'ltms' ),
                'balanceLater'   => __( 'Saldo al check-in', 'ltms' ),
            ],
        ] );
    }

    // ── Widget render ────────────────────────────────────────────────────

    public static function render_calendar_widget(): void {
        global $product;
        if ( ! $product || 'ltms_bookable' !== $product->get_type() ) return;

        $capacity = method_exists( $product, 'get_capacity' ) ? $product->get_capacity() : 1;
        $booking_type = method_exists( $product, 'get_booking_type' ) ? $product->get_booking_type() : 'accommodation';
        $is_hourly = in_array( $booking_type, [ 'experience', 'rental', 'professional_service', 'restaurant' ], true );
        $checkin_time  = method_exists( $product, 'get_checkin_time' )  ? $product->get_checkin_time()  : '';
        $checkout_time = method_exists( $product, 'get_checkout_time' ) ? $product->get_checkout_time() : '';
        $payment_mode  = method_exists( $product, 'get_payment_mode' )  ? $product->get_payment_mode()  : 'full';
        $deposit_pct   = method_exists( $product, 'get_deposit_pct' )   ? $product->get_deposit_pct()   : 0;
        ?>
        <div class="ltms-booking-widget" id="ltms-booking-widget">
            <h3 class="ltms-booking-widget__title">
                <?php echo $is_hourly ? esc_html__( 'Reserva tu experiencia', 'ltms' ) : esc_html__( 'Reserva tu estadía', 'ltms' ); ?>
            </h3>

            <input type="hidden" name="ltms_booking_nonce" value="<?php echo esc_attr( wp_create_nonce( 'ltms_add_booking_' . $product->get_id() ) ); ?>">

            <div class="ltms-booking-widget__calendar">
                <label for="ltms-date-range">
                    <?php echo $is_hourly ? esc_html_e( 'Fecha', 'ltms' ) : esc_html_e( 'Fechas', 'ltms' ); ?>
                </label>
                <input type="text" id="ltms-date-range" class="ltms-date-input" readonly
                       placeholder="<?php echo $is_hourly ? esc_attr_e( 'Selecciona la fecha', 'ltms' ) : esc_attr_e( 'Selecciona tus fechas', 'ltms' ); ?>">
                <input type="hidden" name="ltms_checkin_date"  id="ltms-checkin">
                <input type="hidden" name="ltms_checkout_date" id="ltms-checkout">
            </div>

            <?php if ( $is_hourly ) : ?>
            <!-- AUDIT-BOOKING-ENGINE #7 FIX: time-slot picker para tours/experiences/rentals. -->
            <div class="ltms-booking-widget__time">
                <label for="ltms-booking-time"><?php esc_html_e( 'Hora de inicio', 'ltms' ); ?></label>
                <select name="ltms_booking_time" id="ltms-booking-time" class="ltms-time-select">
                    <?php
                    // Generar opciones de tiempo cada 30 min de 6:00 a 22:00.
                    for ( $h = 6; $h <= 22; $h++ ) {
                        foreach ( [ '00', '30' ] as $m ) {
                            $time = sprintf( '%02d:%s', $h, $m );
                            $selected = ( $checkin_time === $time ) ? 'selected' : '';
                            echo "<option value=\"{$time}\" {$selected}>{$time}</option>";
                        }
                    }
                    ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="ltms-booking-widget__guests">
                <label for="ltms-guests">
                    <?php echo $booking_type === 'restaurant' ? esc_html_e( 'Personas', 'ltms' ) : esc_html_e( 'Huéspedes', 'ltms' ); ?>
                </label>
                <input type="number" name="ltms_guests" id="ltms-guests"
                       min="1" max="<?php echo (int) $capacity; ?>" value="1" class="input-text qty">
            </div>

            <div class="ltms-booking-widget__summary" id="ltms-booking-summary" style="display:none;">
                <div class="ltms-summary-row">
                    <span id="ltms-nights-label"></span>
                    <strong id="ltms-total-price"></strong>
                </div>
                <?php if ( ! $is_hourly ) : ?>
                <div class="ltms-summary-row ltms-checkin-checkout">
                    <span><?php esc_html_e( 'Check-in', 'ltms' ); ?>: <strong id="ltms-display-checkin"></strong></span>
                    <span><?php esc_html_e( 'Check-out', 'ltms' ); ?>: <strong id="ltms-display-checkout"></strong></span>
                </div>
                <?php endif; ?>
                <?php if ( $payment_mode === 'deposit' && $deposit_pct > 0 ) : ?>
                <!-- AUDIT-BOOKING-ENGINE #8: mostrar desglose deposit/balance en el widget. -->
                <div class="ltms-summary-row ltms-deposit-breakdown" style="margin-top:8px;padding-top:8px;border-top:1px dashed #ddd;">
                    <small style="color:#666;">
                        <?php printf( esc_html__( 'Depósito (%d%%):', 'ltms' ), $deposit_pct ); ?>
                        <strong id="ltms-deposit-price"></strong>
                    </small><br>
                    <small style="color:#666;">
                        <?php esc_html_e( 'Saldo al check-in:', 'ltms' ); ?>
                        <strong id="ltms-balance-price"></strong>
                    </small>
                </div>
                <?php endif; ?>
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
            'permission_callback' => function() {
                // SEC-2 FIX: Booking calendar requiere WP nonce para prevenir scraping.
                return is_user_logged_in() || ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'wp_rest' ) );
            },
            'args'                => [
                'product_id' => [ 'type' => 'integer', 'required' => true ],
                'from'       => [ 'type' => 'string',  'default'  => '' ],
                'to'         => [ 'type' => 'string',  'default'  => '' ],
            ],
        ] );

        register_rest_route( 'ltms/v1', '/booking-calendar/(?P<product_id>\d+)/price', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'rest_calculate_price' ],
            'permission_callback' => function() {
                // SEC-2 FIX: Price endpoint requiere WP nonce.
                return is_user_logged_in() || ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'wp_rest' ) );
            },
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

        // AUDIT-BOOKING-ENGINE #8 FIX: devolver desglose deposit/balance
        // según payment_mode del producto. Antes el frontend siempre cobraba
        // el total completo sin importar si el producto era 'deposit' o
        // 'reserve_only'.
        $payment_mode = method_exists( $product, 'get_payment_mode' ) ? $product->get_payment_mode() : 'full';
        $deposit_pct  = method_exists( $product, 'get_deposit_pct' )  ? $product->get_deposit_pct()  : 0.0;
        $deposit_amt  = 0.0;
        $balance_amt  = $total;
        $charge_now   = $total; // Lo que se cobra al checkout.

        if ( $payment_mode === 'deposit' && $deposit_pct > 0 ) {
            $deposit_amt = round( $total * $deposit_pct / 100, 2 );
            $balance_amt = round( $total - $deposit_amt, 2 );
            $charge_now  = $deposit_amt;
        } elseif ( $payment_mode === 'reserve_only' ) {
            // reserve_only = solo cobra una reserva simbólica (ej: 10%).
            $reserve_pct = (float) LTMS_Core_Config::get( 'ltms_booking_reserve_only_pct', 10 );
            $deposit_amt = round( $total * $reserve_pct / 100, 2 );
            $balance_amt = round( $total - $deposit_amt, 2 );
            $charge_now  = $deposit_amt;
        }

        return new \WP_REST_Response( [
            'nights'          => $nights,
            'total'           => $total,
            'total_formatted' => $symbol . number_format( $total, 0, ',', '.' ),
            // AUDIT-BOOKING-ENGINE #8: desglose de pago.
            'payment_mode'    => $payment_mode,
            'deposit_pct'     => $deposit_pct,
            'deposit_amount'  => $deposit_amt,
            'deposit_formatted' => $symbol . number_format( $deposit_amt, 0, ',', '.' ),
            'balance_amount'  => $balance_amt,
            'balance_formatted' => $symbol . number_format( $balance_amt, 0, ',', '.' ),
            'charge_now'      => $charge_now,
            'charge_now_formatted' => $symbol . number_format( $charge_now, 0, ',', '.' ),
        ], 200 );
    }

    // ── Cart integration ─────────────────────────────────────────────────

    public static function capture_booking_data( array $cart_item_data, int $product_id, int $variation_id ): array {
        $product = wc_get_product( $product_id );
        if ( ! $product || 'ltms_bookable' !== $product->get_type() ) {
            return $cart_item_data;
        }

        // Verify nonce before reading booking POST data.
        $nonce = sanitize_text_field( wp_unslash( $_POST['ltms_booking_nonce'] ?? '' ) );
        if ( ! wp_verify_nonce( $nonce, 'ltms_add_booking_' . $product_id ) ) {
            wc_add_notice( __( 'Error de seguridad. Por favor recarga la página e inténtalo de nuevo.', 'ltms' ), 'error' );
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

        // AUDIT-BOOKING-ENGINE #8 FIX: ajustar el precio del carrito según
        // payment_mode. Antes el cliente siempre pagaba el total completo
        // incluso si el producto era 'deposit' o 'reserve_only'.
        $payment_mode = method_exists( $product, 'get_payment_mode' ) ? $product->get_payment_mode() : 'full';
        $deposit_pct  = method_exists( $product, 'get_deposit_pct' )  ? $product->get_deposit_pct()  : 0.0;

        if ( $payment_mode === 'deposit' && $deposit_pct > 0 ) {
            $cart_item_data['_ltms_payment_mode']    = 'deposit';
            $cart_item_data['_ltms_deposit_pct']     = $deposit_pct;
            // El precio del carrito se ajusta en el hook woocommerce_add_cart_item
            // (ver adjust_cart_item_price_for_deposit más abajo).
        } elseif ( $payment_mode === 'reserve_only' ) {
            $cart_item_data['_ltms_payment_mode'] = 'reserve_only';
        }

        $cart_item_data['unique_key'] = md5( $product_id . $checkin . $checkout . $guests . microtime() );

        return $cart_item_data;
    }

    /**
     * AUDIT-BOOKING-ENGINE #8 FIX: ajusta el precio del carrito para que
     * WC cobre solo el depósito cuando payment_mode='deposit' o 'reserve_only'.
     *
     * El balance se cobra posteriormente (manualmente o via cron de reminder).
     * El booking SIEMPRE guarda total_price como el precio completo — el
     * depósito es solo lo que se cobra al checkout.
     */
    public static function adjust_cart_item_price_for_deposit( array $cart_item ): array {
        if ( ! isset( $cart_item['_ltms_payment_mode'] ) ) {
            return $cart_item;
        }

        $product = $cart_item['data'] ?? null;
        if ( ! $product ) {
            return $cart_item;
        }

        $total_price = (float) $product->get_price();
        $payment_mode = $cart_item['_ltms_payment_mode'];

        if ( $payment_mode === 'deposit' && ! empty( $cart_item['_ltms_deposit_pct'] ) ) {
            $deposit_pct = (float) $cart_item['_ltms_deposit_pct'];
            $charge_now  = round( $total_price * $deposit_pct / 100, 2 );
            $product->set_price( $charge_now );
        } elseif ( $payment_mode === 'reserve_only' ) {
            $reserve_pct = (float) LTMS_Core_Config::get( 'ltms_booking_reserve_only_pct', 10 );
            $charge_now  = round( $total_price * $reserve_pct / 100, 2 );
            $product->set_price( $charge_now );
        }

        return $cart_item;
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
