<?php
class LTMS_Coupon_Attribution_Listener {

    use LTMS_Logger_Aware;

    /**
     * Registra los hooks del listener.
     *
     * @return void
     */
    public static function init(): void {
        // Capturar el código de referido cuando llega por URL
        add_action( 'init', [ __CLASS__, 'capture_referral_cookie' ] );

        // Al crear el pedido, guardar la atribución
        add_action( 'woocommerce_checkout_order_created', [ __CLASS__, 'save_attribution' ] );

        // Al pagar, acreditar al referidor si aplica
        add_action( 'woocommerce_payment_complete', [ __CLASS__, 'credit_referrer' ], 30 );
    }

    /**
     * Captura el código de referido (?ref=CODIGO) en una cookie.
     *
     * @return void
     */
    public static function capture_referral_cookie(): void {
        $ref = sanitize_text_field( $_GET['ref'] ?? '' ); // phpcs:ignore
        if ( ! $ref ) {
            return;
        }
        // Cookie dura 30 días (primer clic gana)
        if ( ! isset( $_COOKIE['ltms_referral'] ) ) {
            setcookie( 'ltms_referral', $ref, time() + ( 30 * DAY_IN_SECONDS ), COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
            $_COOKIE['ltms_referral'] = $ref;
        }
    }

    /**
     * Guarda la atribución (cupón + referido) en la meta del pedido.
     *
     * @param WC_Order $order
     * @return void
     */
    public static function save_attribution( WC_Order $order ): void {
        // Referido por cookie
        $ref_code = sanitize_text_field( $_COOKIE['ltms_referral'] ?? '' );
        if ( $ref_code ) {
            $order->update_meta_data( '_ltms_referral_code', $ref_code );
        }

        // Cupones aplicados
        $coupon_codes = $order->get_coupon_codes();
        if ( ! empty( $coupon_codes ) ) {
            $order->update_meta_data( '_ltms_applied_coupons', implode( ',', $coupon_codes ) );
        }

        $order->save();
    }

    /**
     * Acredita comisión al referidor cuando el pedido es pagado.
     *
     * @param int $order_id
     * @return void
     */
    public static function credit_referrer( int $order_id ): void {
        // Prevenir doble procesamiento
        if ( get_post_meta( $order_id, '_ltms_referral_credited', true ) ) {
            return;
        }

        $order    = wc_get_order( $order_id );
        $ref_code = $order ? $order->get_meta( '_ltms_referral_code' ) : '';

        if ( ! $ref_code ) {
            return;
        }

        // Buscar el vendedor dueño del código de referido
        $referrer_id = (int) self::get_user_by_referral_code( $ref_code );
        if ( ! $referrer_id ) {
            return;
        }

        // Calcular comisión de referido
        $rate       = (float) LTMS_Core_Config::get( 'ltms_mlm_referral_rate', 0.02 );
        $commission = (float) $order->get_total() * $rate;

        if ( $commission > 0 && class_exists( 'LTMS_Business_Wallet' ) ) {
            LTMS_Business_Wallet::credit(
                $referrer_id,
                $commission,
                'referral_commission',
                sprintf( __( 'Comisión de referido — Pedido #%d', 'ltms' ), $order_id )
            );

            update_post_meta( $order_id, '_ltms_referral_credited', 1 );
            update_post_meta( $order_id, '_ltms_referrer_id', $referrer_id );

            self::log_info(
                'REFERRAL_CREDITED',
                sprintf( 'Comisión %.2f acreditada al referidor #%d por pedido #%d', $commission, $referrer_id, $order_id )
            );
        }
    }

    /**
     * Busca el usuario dueño de un código de referido.
     *
     * @param string $code
     * @return int User ID o 0.
     */
    private static function get_user_by_referral_code( string $code ): int {
        $users = get_users( [
            'meta_key'   => '_ltms_referral_code',
            'meta_value' => sanitize_text_field( $code ),
            'number'     => 1,
            'fields'     => 'ID',
        ] );
        return ! empty( $users ) ? (int) $users[0] : 0;
    }
}
