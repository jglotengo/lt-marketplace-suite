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
        // v2.9.122 AFFILIATE-AUDIT P0-1 FIX: validate referral code format.
        // Before, any string was accepted as referral code. A malicious string
        // could be stored in the cookie and later used in SQL queries (though
        // prepared, it's defense in depth). Now validates alphanumeric + length.
        if ( ! preg_match( '/^[A-Za-z0-9_\-]{2,32}$/', $ref ) ) {
            return;
        }
        // Cookie dura 30 días (primer clic gana)
        if ( ! isset( $_COOKIE['ltms_referral'] ) ) {
            setcookie( 'ltms_referral', $ref, [ 'expires' => time() + ( 30 * DAY_IN_SECONDS ), 'path' => COOKIEPATH, 'domain' => COOKIE_DOMAIN, 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Strict', ] );
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

        // v2.9.122 AFFILIATE-AUDIT P1-1 FIX: verify referrer is a vendor.
        // Before, any user with a referral code (including customers) could
        // receive wallet commissions. Now checks LTMS_Utils::is_ltms_vendor().
        if ( class_exists( 'LTMS_Utils' ) && ! LTMS_Utils::is_ltms_vendor( $referrer_id ) ) {
            self::log_warning_static(
                'REFERRAL_CREDIT_NON_VENDOR',
                sprintf( 'Referrer #%d is not a vendor — commission skipped for order #%d', $referrer_id, $order_id ),
                [ 'referrer_id' => $referrer_id, 'order_id' => $order_id ]
            );
            return;
        }

        // Calcular comisión de referido
        $rate       = (float) LTMS_Core_Config::get( 'ltms_mlm_referral_rate', 0.02 );
        // v2.9.122 P0-2 FIX: bound commission to configurable max.
        // Before, a $1,000,000 order would generate $20,000 commission (2%).
        // Now capped at 500,000 (configurable via ltms_max_referral_commission).
        $max_commission = (float) LTMS_Core_Config::get( 'ltms_max_referral_commission', 500000 );
        $commission = min( (float) $order->get_total() * $rate, $max_commission );

        if ( $commission > 0 && class_exists( 'LTMS_Business_Wallet' ) ) {
            // v2.9.122 P0-3 FIX: add idempotency key to prevent double credit.
            // Before, if credit_referrer was called twice (race between
            // woocommerce_payment_complete hooks), the meta guard might not be
            // set yet → double wallet credit. Now uses idempotency key.
            $idempotency_key = 'referral_credit_o' . $order_id;

            // M-107: firma correcta = credit(vendor, amount, description:string, metadata:array, order_id:int)
            LTMS_Business_Wallet::credit(
                $referrer_id,
                $commission,
                sprintf( __( 'Comisión de referido — Pedido #%d', 'ltms' ), $order_id ),
                [ 'type' => 'referral_commission', 'order_id' => $order_id ],
                $order_id,
                '',
                $idempotency_key
            );

            update_post_meta( $order_id, '_ltms_referral_credited', 1 );
            update_post_meta( $order_id, '_ltms_referrer_id', $referrer_id );

            self::log_info_static(
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
        // REG-BUG-1 FIX (regresión de LS-BUG-5 / Task 53-C): el meta de USUARIO
        // para referral codes es 'ltms_referral_code' (sin underscore), verificado
        // en class-ltms-api-tptc.php::register_affiliate() y
        // class-ltms-affiliates.php. El anterior '_ltms_referral_code' (con
        // underscore) no matcheaba ningún usuario → las comisiones de referido
        // nunca se acreditaban. NOTA: el ORDER meta '_ltms_referral_code' (con
        // underscore, ver save_attribution() arriba) SÍ es correcto — solo este
        // lookup de USER meta estaba mal.
        $users = get_users( [
            'meta_key'   => 'ltms_referral_code',
            'meta_value' => sanitize_text_field( $code ),
            'number'     => 1,
            'fields'     => 'ID',
        ] );
        return ! empty( $users ) ? (int) $users[0] : 0;
    }
}
