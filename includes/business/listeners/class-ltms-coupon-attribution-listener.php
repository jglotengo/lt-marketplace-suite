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

        // AUDIT-LISTENERS-001 P1-2 FIX (Ciclo 1.5): enganchar también
        // woocommerce_order_status_completed. Antes, pedidos marcados como
        // Completed manualmente desde admin (offline, COD, transferencia
        // bancaria, contraentrega) NO disparaban credit_referrer() porque
        // woocommerce_payment_complete no se gatilla para esos métodos.
        // Solo pasaban por ahí pedidos pagados online (PSE, card, etc.).
        // Resultado: comisiones de referido perdidas para órdenes offline.
        if ( ! has_action( 'woocommerce_order_status_completed', [ __CLASS__, 'credit_referrer' ] ) ) {
            add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'credit_referrer' ], 30 );
        }
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
        // AUDIT-LISTENERS-001 P1-2 FIX (Ciclo 1.5): try/catch + atomic
        // claim. Antes, get_post_meta() + update_post_meta() era no
        // atómico — dos procesos concurrentes (woocommerce_payment_complete
        // + woocommerce_order_status_completed si ambos se gatillan en
        // secuencia, o cron retry) podían ambos leer 'false', ambos
        // pasar el guard, ambos llamar Wallet::credit. La idempotency_key
        // del wallet (P0-3 FIX v2.9.122) ya evita el doble crédito real,
        // pero el atomic claim previene también clientes en la cola de
        // notificaciones y el ruido de logs/DB. Mismo patrón que H-4 FIX
        // del Order_Paid_Listener.
        global $wpdb;
        add_post_meta( $order_id, '_ltms_referral_credited', '0', true );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $claimed = $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = '1' WHERE post_id = %d AND meta_key = %s AND (meta_value IS NULL OR meta_value != '1')",
            $order_id, '_ltms_referral_credited'
        ) );
        if ( ! $claimed ) {
            return; // Already claimed by another process
        }

        try {
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

                update_post_meta( $order_id, '_ltms_referrer_id', $referrer_id );

                self::log_info_static(
                    'REFERRAL_CREDITED',
                    sprintf( 'Comisión %.2f acreditada al referidor #%d por pedido #%d', $commission, $referrer_id, $order_id )
                );
            }
        } catch ( \Throwable $e ) {
            // AUDIT-LISTENERS-001 P1-2 FIX (Ciclo 1.5): resetear el flag
            // ante fallo transitorio para permitir retry. Sin este reset,
            // si Wallet::credit lanzaba (DB timeout, vendor nonexistent),
            // el flag quedaba en '1' y no había path de retry.
            // CICLO13-P1-CA-031 FIX: el UPDATE de reset no se verificaba -
            // mismo patron que P1-RL-025 (Ciclo 11, ReDi listener) y
            // P1-TL-028 (Ciclo 12, TPTC listener). Si el reset fallaba
            // silenciosamente (false = error DB con last_error, 0 = no
            // rows por schema drift), el flag quedaba en '1' y el retry
            // nunca ocurria -> la comision de referido nunca se acreditaba
            // al referrer para siempre (vendor pierde comision MLM de
            // referido silenciosamente, sin alerta al admin, sin path de
            // reconciliacion). Patron recurrente documentado en Ciclos
            // 5-12 (9 ciclos consecutivos). Mismo fix estandar: capturar
            // + check explicito false/0 + log critico con SQL de
            // reconciliacion manual.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $reset_result = $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->postmeta} SET meta_value = '0' WHERE post_id = %d AND meta_key = %s",
                $order_id, '_ltms_referral_credited'
            ) );
            if ( false === $reset_result || 0 === (int) $reset_result ) {
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::critical(
                        'REFERRAL_CREDIT_FLAG_RESET_FAILED',
                        sprintf(
                            'Comision de referido fallo Y el reset del flag _ltms_referral_credited tambien fallo. order_id=%d exception=%s reset_result=%s last_error=%s. La comision de referido NO se acredita al referrer para siempre - el vendor pierde comision MLM de referido silenciosamente. Reconciliacion manual: UPDATE %spostmeta SET meta_value=\'0\' WHERE post_id=%d AND meta_key=\'_ltms_referral_credited\'.',
                            $order_id,
                            get_class( $e ),
                            var_export( $reset_result, true ),
                            $wpdb->last_error ?: '(no error)',
                            $wpdb->prefix,
                            $order_id
                        ),
                        [
                            'order_id'      => $order_id,
                            'exception'     => get_class( $e ),
                            'exception_msg' => $e->getMessage(),
                            'reset_result'  => var_export( $reset_result, true ),
                            'last_error'    => $wpdb->last_error ?: '(no error)',
                        ]
                    );
                }
            }
            self::log_warning_static(
                'REFERRAL_CREDIT_FAILED',
                sprintf( 'credit_referrer order #%d: %s', $order_id, $e->getMessage() ),
                [ 'order_id' => $order_id, 'exception' => get_class( $e ) ]
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
