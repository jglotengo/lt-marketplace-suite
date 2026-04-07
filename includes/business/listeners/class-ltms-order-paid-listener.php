<?php
/**
 * LTMS Order Paid Listener - Orquestador Principal de Ventas
 *
 * Escucha el evento woocommerce_payment_complete y coordina:
 * 1. Cálculo y acreditación de comisiones al vendedor
 * 2. Sincronización con Siigo (factura electrónica)
 * 3. Notificación al vendedor
 * 4. Registro de la venta en la red de referidos
 *
 * @package    LTMS
 * @subpackage LTMS/includes/business/listeners
 * @version    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Order_Paid_Listener
 */
final class LTMS_Order_Paid_Listener {

    use LTMS_Logger_Aware;

    /**
     * Registra los hooks del listener.
     *
     * @return void
     */
    public static function init(): void {
        add_action( 'woocommerce_payment_complete', [ __CLASS__, 'on_order_paid' ], 10, 1 );
        add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'on_order_paid' ], 10, 1 );
        add_action( 'woocommerce_checkout_order_created', [ __CLASS__, 'save_absorbed_shipping_quote' ] );
    }

    /**
     * Manejador principal del pago completado.
     *
     * @param int $order_id ID del pedido de WooCommerce.
     * @return void
     */
    public static function on_order_paid( int $order_id ): void {
        // Prevenir ejecución doble (payment_complete + status_completed)
        $already_processed = get_post_meta( $order_id, '_ltms_commissions_processed', true );
        if ( $already_processed ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order instanceof \WC_Order ) {
            return;
        }

        // Marcar como procesado inmediatamente (previene race conditions)
        $order->update_meta_data( '_ltms_commissions_processed', true );
        $order->save();

        // Disparar en orden secuencial, manejando errores individualmente
        self::process_commissions( $order );
        self::schedule_invoice_sync( $order );
        self::notify_vendor( $order );
        self::debit_absorbed_shipping( $order );

        LTMS_Core_Logger::info(
            'ORDER_PAID_PROCESSED',
            sprintf( 'Pedido #%s procesado por LTMS', $order->get_order_number() ),
            [ 'order_id' => $order_id, 'total' => $order->get_total() ]
        );
    }

    /**
     * Calcula y acredita comisiones en las billeteras de los vendedores.
     *
     * @param \WC_Order $order Pedido pagado.
     * @return void
     */
    private static function process_commissions( \WC_Order $order ): void {
        if ( ! class_exists( 'LTMS_Business_Order_Split' ) ) {
            return;
        }

        try {
            LTMS_Business_Order_Split::process( $order );
        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::error(
                'COMMISSION_PROCESS_FAILED',
                sprintf( 'Error procesando comisiones del pedido #%d: %s', $order->get_id(), $e->getMessage() ),
                [ 'order_id' => $order->get_id() ]
            );
        }
    }

    /**
     * Programa la sincronización con Siigo en la cola de trabajos (async).
     *
     * @param \WC_Order $order Pedido pagado.
     * @return void
     */
    private static function schedule_invoice_sync( \WC_Order $order ): void {
        $siigo_enabled = LTMS_Core_Config::get( 'ltms_siigo_enabled', 'no' );
        if ( $siigo_enabled !== 'yes' ) {
            return;
        }

        // Programar async para no bloquear el proceso de pago
        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action(
                time() + 30, // 30 segundos de delay para que el pedido se guarde completamente
                'ltms_sync_siigo_invoice',
                [ 'order_id' => $order->get_id() ],
                'ltms-siigo'
            );
        } else {
            // Fallback: agregar a la cola propia de LTMS
            global $wpdb;
            $table = $wpdb->prefix . 'lt_job_queue';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert( $table, [
                'hook'         => 'ltms_sync_siigo_invoice',
                'args'         => wp_json_encode( [ 'order_id' => $order->get_id() ] ),
                'priority'     => 10,
                'status'       => 'pending',
                'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() + 30 ),
                'created_at'   => LTMS_Utils::now_utc(),
            ], [ '%s', '%s', '%d', '%s', '%s', '%s' ]);
        }
    }

    /**
     * Guarda la cotización de envío absorbido en la meta del pedido.
     *
     * @param \WC_Order $order
     */
    public static function save_absorbed_shipping_quote( \WC_Order $order ): void {
        try {
            $quote = WC()->session ? WC()->session->get( 'ltms_absorbed_shipping_quote' ) : null;
            if ( ! $quote ) return;
            $order->update_meta_data( '_ltms_absorbed_shipping_cost',     (float) ( $quote['cost']     ?? 0 ) );
            $order->update_meta_data( '_ltms_absorbed_shipping_provider', sanitize_text_field( $quote['provider'] ?? '' ) );
            $order->save();
            WC()->session->__unset( 'ltms_absorbed_shipping_quote' );
        } catch ( \Throwable $e ) {
            error_log( 'LTMS save_absorbed_shipping_quote: ' . $e->getMessage() );
        }
    }

    /**
     * Debita el costo de envío absorbido de la billetera del vendedor.
     *
     * @param \WC_Order $order
     */
    private static function debit_absorbed_shipping( \WC_Order $order ): void {
        try {
            $cost = (float) $order->get_meta( '_ltms_absorbed_shipping_cost' );
            if ( $cost <= 0 ) return;

            $vendor_id = (int) $order->get_meta( '_ltms_vendor_id' );
            if ( ! $vendor_id ) return;

            $already = $order->get_meta( '_ltms_shipping_debited' );
            if ( $already ) return;

            if ( class_exists( 'LTMS_Business_Wallet' ) ) {
                LTMS_Business_Wallet::debit(
                    $vendor_id,
                    $cost,
                    'shipping_absorbed',
                    sprintf( __( 'Envío absorbido — Pedido #%d', 'ltms' ), $order->get_id() )
                );
            }

            $order->update_meta_data( '_ltms_shipping_debited', 1 );
            $order->save();
        } catch ( \Throwable $e ) {
            error_log( 'LTMS debit_absorbed_shipping order #' . $order->get_id() . ': ' . $e->getMessage() );
        }
    }

    /**
     * Envía notificación al vendedor del nuevo pedido.
     *
     * @param \WC_Order $order Pedido pagado.
     * @return void
     */
    private static function notify_vendor( \WC_Order $order ): void {
        $vendor_id = (int) $order->get_meta( '_ltms_vendor_id' );
        if ( ! $vendor_id ) {
            return;
        }

        try {
            global $wpdb;
            $table = $wpdb->prefix . 'lt_notifications';

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert( $table, [
                'user_id'    => $vendor_id,
                'type'       => 'order_new',
                'channel'    => 'inapp',
                'title'      => __( 'Nuevo Pedido', 'ltms' ),
                'message'    => sprintf(
                    /* translators: %1$s: número de pedido, %2$s: total del pedido */
                    __( 'Tienes un nuevo pedido #%1$s por %2$s', 'ltms' ),
                    $order->get_order_number(),
                    LTMS_Utils::format_money( (float) $order->get_total() )
                ),
                'data'       => wp_json_encode( [
                    'order_id'     => $order->get_id(),
                    'order_number' => $order->get_order_number(),
                    'amount'       => $order->get_total(),
                ]),
                'is_read'    => 0,
                'created_at' => LTMS_Utils::now_utc(),
            ], [ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]);
        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::warning(
                'NOTIFICATION_FAILED',
                sprintf( 'No se pudo notificar al vendedor #%d: %s', $vendor_id, $e->getMessage() )
            );
        }
    }
}
