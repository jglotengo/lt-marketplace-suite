<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class LTMS_Redi_Order_Listener
 * Listens for payment complete and processes ReDi item splits (priority 20).
 *
 * AUDIT-REDI-UX-GAPS GAP-5 FIX: notifies origin vendor + reseller on ReDi sale.
 * AUDIT-REDI-UX-GAPS GAP-6 FIX: notifies both vendors on cancellation/refund.
 */
class LTMS_Redi_Order_Listener {

    use LTMS_Logger_Aware;

    public static function init(): void {
        add_action( 'woocommerce_payment_complete', [ __CLASS__, 'on_order_paid' ], 20 );
        add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'on_order_paid' ], 20 );
        add_action( 'woocommerce_order_status_cancelled', [ __CLASS__, 'on_order_cancelled' ], 10 );
        add_action( 'woocommerce_order_status_refunded', [ __CLASS__, 'on_order_cancelled' ], 10 );
    }

    public static function on_order_paid( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        if ( ! class_exists( 'LTMS_Business_Redi_Manager' ) ) return;

        $redi_items = LTMS_Business_Redi_Manager::detect_redi_items( $order );
        if ( empty( $redi_items ) ) return;

        // H-5 FIX: atomic SQL claim to prevent double-processing race condition.
        // The previous get_post_meta() + update_post_meta() pattern was
        // non-atomic: two concurrent processes (payment_complete +
        // status_completed, or a cron + webhook) could both read ''/false,
        // both pass the guard, both detect ReDi items, and both run
        // LTMS_Business_Redi_Order_Split::process() → double stock deduction,
        // double commission rows, double vendor notifications.
        //
        // The claim is placed AFTER detect_redi_items() (not at the top of the
        // method) so that non-ReDi orders are NOT marked as processed. This
        // preserves the original semantics relied on by on_order_cancelled(),
        // which reads _ltms_redi_processed to decide whether to reverse ReDi
        // commissions — marking non-ReDi orders would cause that method to run
        // its reversal loop (and log a misleading 'REDI_ORDER_REVERSED' entry)
        // for orders that never had ReDi processing. detect_redi_items() is a
        // pure read, so both concurrent callers can safely run it; the claim
        // is the atomic gate that ensures only one proceeds to mutate state.
        global $wpdb;
        add_post_meta( $order_id, '_ltms_redi_processed', '0', true );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $claimed = $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = '1' WHERE post_id = %d AND meta_key = %s AND (meta_value IS NULL OR meta_value != '1')",
            $order_id, '_ltms_redi_processed'
        ) );
        if ( ! $claimed ) return; // Already claimed by another process

        // H-5 FIX: removed update_post_meta( $order_id, '_ltms_redi_processed', true )
        // here — the atomic claim above already marked the order as processed.

        // AUDIT-REDI-UX-GAPS GAP-7 FIX: persistir _ltms_redi_origin_vendor_id
        // en el ORDER meta para que get_vendor_orders() lo retorne.
        $first_origin_id = 0;
        foreach ( $redi_items as $item ) {
            $oid = (int) ( $item['origin_vendor_id'] ?? 0 );
            if ( $oid && ! $first_origin_id ) { $first_origin_id = $oid; break; }
        }
        if ( $first_origin_id ) {
            update_post_meta( $order_id, '_ltms_redi_origin_vendor_id', $first_origin_id );
        }

        LTMS_Business_Redi_Order_Split::process( $order, $redi_items );
        LTMS_Business_Redi_Manager::deduct_origin_stock( $order );

        // AUDIT-REDI-UX-GAPS GAP-5 FIX: notificar al origin vendor + reseller.
        // El origin vendor necesita saber que debe enviar el producto al cliente.
        self::notify_redi_vendors_order_paid( $order, $redi_items );

        LTMS_Core_Logger::info(
            'REDI_ORDER_PROCESSED',
            sprintf( 'ReDi processed for order #%d: %d items', $order_id, count( $redi_items ) )
        );
    }

    /**
     * AUDIT-REDI-UX-GAPS GAP-5 FIX: notifica al origin vendor y al reseller.
     * Origin: email con dirección de envío completa + productos a enviar.
     * Reseller: email con comisión (sin dirección del cliente — no la necesita).
     */
    private static function notify_redi_vendors_order_paid( \WC_Order $order, array $redi_items ): void {
        global $wpdb;
        $order_number = $order->get_order_number();
        $order_id     = $order->get_id();

        // Agrupar items por origin vendor.
        $by_origin = [];
        $reseller_total_commission = 0.0;
        $reseller_id = 0;

        foreach ( $redi_items as $item ) {
            $origin_id  = (int) ( $item['origin_vendor_id'] ?? 0 );
            $reseller_id = (int) ( $item['reseller_id'] ?? 0 );
            $gross       = (float) ( $item['gross'] ?? 0 );
            $redi_rate   = (float) ( $item['redi_rate'] ?? 0 );
            $reseller_commission = round( $gross * $redi_rate, 2 );

            if ( ! isset( $by_origin[ $origin_id ] ) ) {
                $by_origin[ $origin_id ] = [ 'items' => [], 'total_commission' => 0.0 ];
            }
            $product_name = '';
            if ( ! empty( $item['product_id'] ) ) {
                $p = wc_get_product( $item['product_id'] );
                if ( $p ) $product_name = $p->get_name();
            }
            $by_origin[ $origin_id ]['items'][] = [ 'product_name' => $product_name, 'gross' => $gross ];
            $by_origin[ $origin_id ]['total_commission'] += $reseller_commission;
            $reseller_total_commission += $reseller_commission;
        }

        // Notificar a cada origin vendor (con dirección de envío completa).
        foreach ( $by_origin as $origin_id => $data ) {
            $reseller_store = get_user_meta( $reseller_id, 'ltms_store_name', true ) ?: __( 'Revendedor', 'ltms' );

            self::create_notification(
                $origin_id,
                'redi_order_new_origin',
                sprintf( __( 'Pedido ReDi #%s — Debes enviar al cliente', 'ltms' ), $order_number ),
                sprintf(
                    _n( 'Tienes %1$d producto ReDi para enviar (vía %2$s).', 'Tienes %1$d productos ReDi para enviar (vía %2$s).', count( $data['items'] ), 'ltms' ),
                    count( $data['items'] ), $reseller_store
                ),
                $order_id,
                home_url( '/dashboard?view=orders' )
            );

            self::send_redi_email( $origin_id, 'redi_order_new_origin',
                sprintf( '[%s] 📦 Pedido ReDi #%s — Debes enviar al cliente', get_bloginfo( 'name' ), $order_number ),
                __( 'Tienes un pedido ReDi para enviar al cliente.', 'ltms' ),
                $order, $data['items'], $reseller_store, true, 'origin'
            );
        }

        // Notificar al reseller (sin dirección del cliente).
        if ( $reseller_id && $reseller_total_commission > 0 ) {
            $commission_formatted = wp_strip_all_tags( wc_price( $reseller_total_commission, [ 'currency' => $order->get_currency() ] ) );

            self::create_notification(
                $reseller_id,
                'redi_order_new_reseller',
                sprintf( __( '¡Tu producto ReDi vendió! Pedido #%s', 'ltms' ), $order_number ),
                sprintf( __( 'Tu comisión ReDi: %s. El origin vendor enviará al cliente.', 'ltms' ), $commission_formatted ),
                $order_id,
                home_url( '/dashboard?view=orders' )
            );

            self::send_redi_email( $reseller_id, 'redi_order_new_reseller',
                sprintf( '[%s] 🎉 Tu producto ReDi vendió — Pedido #%s', get_bloginfo( 'name' ), $order_number ),
                sprintf( __( '¡Tu producto ReDi vendió! Tu comisión: %s', 'ltms' ), $commission_formatted ),
                $order, [], '', false, 'reseller'
            );
        }
    }

    public static function on_order_cancelled( int $order_id ): void {
        if ( ! get_post_meta( $order_id, '_ltms_redi_processed', true ) ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        global $wpdb;
        $commissions = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
            "SELECT * FROM `{$wpdb->prefix}lt_redi_commissions` WHERE order_id = %d AND status = 'paid'",
            $order_id
        ) );

        foreach ( $commissions as $commission ) {
            try {
                LTMS_Business_Wallet::debit(
                    (int) $commission->origin_vendor_id,
                    (float) $commission->origin_vendor_net,
                    sprintf( __( 'Reversión ReDi pedido #%s', 'ltms' ), $order->get_order_number() ),
                    [ 'type' => 'reversal', 'order_id' => $order_id, 'redi_commission_id' => $commission->id ],
                    $order_id
                );
                LTMS_Business_Wallet::debit(
                    (int) $commission->reseller_vendor_id,
                    (float) $commission->reseller_commission,
                    sprintf( __( 'Reversión ReDi pedido #%s (revendedor)', 'ltms' ), $order->get_order_number() ),
                    [ 'type' => 'reversal', 'order_id' => $order_id ],
                    $order_id
                );
            } catch ( \Throwable $e ) {
                LTMS_Core_Logger::error( 'REDI_REVERSAL_FAILED', $e->getMessage() );
            }

            // AUDIT-REDI-UX-GAPS GAP-6 FIX: notificar a AMBOS vendors.
            $origin_id   = (int) $commission->origin_vendor_id;
            $reseller_id = (int) $commission->reseller_vendor_id;

            self::create_notification(
                $origin_id, 'redi_order_cancelled',
                sprintf( __( 'Pedido ReDi #%s cancelado — suspende envío', 'ltms' ), $order->get_order_number() ),
                __( 'El pedido fue cancelado. Si preparaste el envío, suspéndelo. Comisión reversada.', 'ltms' ),
                $order_id, home_url( '/dashboard?view=orders' )
            );
            self::send_redi_email( $origin_id, 'redi_order_cancelled',
                sprintf( '[%s] ⚠️ Pedido ReDi #%s cancelado', get_bloginfo( 'name' ), $order->get_order_number() ),
                __( 'El pedido ReDi fue cancelado. Suspende el envío si ya lo preparaste.', 'ltms' ),
                $order, [], '', false, 'origin'
            );

            self::create_notification(
                $reseller_id, 'redi_order_cancelled',
                sprintf( __( 'Pedido ReDi #%s cancelado — comisión reversada', 'ltms' ), $order->get_order_number() ),
                __( 'El pedido fue cancelado. Tu comisión ReDi fue reversada.', 'ltms' ),
                $order_id, home_url( '/dashboard?view=orders' )
            );
            self::send_redi_email( $reseller_id, 'redi_order_cancelled',
                sprintf( '[%s] ⚠️ Pedido ReDi #%s cancelado — comisión reversada', get_bloginfo( 'name' ), $order->get_order_number() ),
                __( 'El pedido fue cancelado. Tu comisión fue reversada.', 'ltms' ),
                $order, [], '', false, 'reseller'
            );

            $wpdb->update(
                $wpdb->prefix . 'lt_redi_commissions',
                [ 'status' => 'reversed' ],
                [ 'id' => $commission->id ],
                [ '%s' ], [ '%d' ]
            );
        }

        // Restore origin stock
        if ( class_exists( 'LTMS_Business_Redi_Manager' ) ) {
            foreach ( $order->get_items() as $item ) {
                $pid = $item->get_product_id();
                if ( LTMS_Business_Redi_Manager::is_redi_product( $pid ) ) {
                    $origin_pid = LTMS_Business_Redi_Manager::get_origin_product_id( $pid );
                    if ( $origin_pid ) {
                        $origin_product = wc_get_product( $origin_pid );
                        if ( $origin_product && $origin_product->managing_stock() ) {
                            $origin_product->set_stock_quantity( $origin_product->get_stock_quantity() + $item->get_quantity() );
                            $origin_product->save();
                        }
                    }
                }
            }
        }

        LTMS_Core_Logger::info( 'REDI_ORDER_REVERSED', sprintf( 'ReDi reversed for order #%d', $order_id ) );
    }

    // ── Notification helpers ──────────────────────────────────────────

    /**
     * AUDIT-REDI-UX-GAPS GAP-5 FIX: inserta una notificación en lt_notifications.
     */
    private static function create_notification( int $user_id, string $type, string $title, string $message, int $order_id = 0, string $link = '' ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_notifications';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert( $table, [
            'user_id'    => $user_id,
            'type'       => $type,
            'title'      => sanitize_text_field( $title ),
            'message'    => sanitize_text_field( $message ),
            'link'       => esc_url_raw( $link ),
            'is_read'    => 0,
            'created_at' => current_time( 'mysql', true ),
        ], [ '%d', '%s', '%s', '%s', '%s', '%d', '%s' ] );
    }

    /**
     * AUDIT-REDI-UX-GAPS GAP-5 FIX: envía email ReDi (template o fallback texto).
     */
    private static function send_redi_email( int $user_id, string $event, string $subject, string $short_message, \WC_Order $order = null, array $items = [], string $reseller_store = '', bool $show_shipping_addr = false, string $role = '' ): void {
        $user = get_userdata( $user_id );
        if ( ! $user || ! $user->user_email ) return;

        // Opt-out check.
        if ( get_user_meta( $user_id, 'ltms_email_redi_notifications', true ) === 'no' ) return;

        // AUDIT-REDI-UX-GAPS GAP-8 FIX: buscar template HTML específico.
        // Si existe templates/emails/email-{event}.php, usarlo. Si no, fallback texto.
        $template_path = defined( 'LTMS_PLUGIN_DIR' )
            ? LTMS_PLUGIN_DIR . 'templates/emails/email-' . $event . '.php'
            : plugin_dir_path( __DIR__ . '/../../' ) . 'templates/emails/email-' . $event . '.php';

        $email_body = '';

        if ( file_exists( $template_path ) ) {
            // Construir $data para el template.
            $data = [
                'order'              => $order,
                'items'              => $items,
                'reseller_store'     => $reseller_store,
                'show_shipping_addr' => $show_shipping_addr,
                'role'               => $role,
                'commission'         => 0.0, // Para reseller — se calcula en el caller.
            ];

            // Si es el evento de reseller, calcular comisión total.
            if ( $event === 'redi_order_new_reseller' && ! empty( $items ) ) {
                $data['commission'] = array_sum( array_map( fn( $i ) => (float) ( $i['gross'] ?? 0 ), $items ) );
            }

            ob_start();
            include $template_path;
            $email_body = ob_get_clean();
        }

        // Fallback a texto plano si no hay template o está vacío.
        if ( empty( $email_body ) ) {
            $body  = "Hola,\n\n";
            $body .= $short_message . "\n\n";
            if ( $order ) {
                $body .= "Pedido #" . $order->get_order_number() . "\n";
            }
            if ( $reseller_store ) {
                $body .= "Revendedor: " . $reseller_store . "\n";
            }
            if ( ! empty( $items ) ) {
                $body .= "\nProductos:\n";
                foreach ( $items as $item ) {
                    $body .= "• " . $item['product_name'] . " — " . wp_strip_all_tags( wc_price( $item['gross'] ) ) . "\n";
                }
            }
            if ( $show_shipping_addr && $order ) {
                $body .= "\nDirección de envío del cliente:\n";
                $body .= $order->get_formatted_shipping_address() . "\n";
                $phone = $order->get_shipping_phone() ?: $order->get_billing_phone();
                if ( $phone ) $body .= "Tel: " . $phone . "\n";
            }
            if ( $role === 'origin' ) {
                $body .= "\nAccede a tu panel para gestionar el envío.\n";
            }
            $body .= "\n---\n" . get_bloginfo( 'name' );
            $email_body = nl2br( esc_html( $body ) );
        }

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>',
        ];
        wp_mail( $user->user_email, $subject, $email_body, $headers );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info( 'REDI_EMAIL_SENT',
                sprintf( 'Email %s → vendor #%d (template: %s)', $event, $user_id, file_exists( $template_path ) ? 'HTML' : 'plain' ),
                [ 'user_id' => $user_id, 'event' => $event ]
            );
        }
    }
}
