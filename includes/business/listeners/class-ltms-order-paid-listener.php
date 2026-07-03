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

        // v2.8.4: persistir cotización SHARED (cliente paga %, vendor absorbe resto).
        add_action( 'woocommerce_checkout_order_created', [ __CLASS__, 'save_shared_shipping_quote' ] );

        // AUDIT-SHIPPING-ENGINE #12 FIX: persistir Uber quote_id en el order
        // al crear el pedido (no solo en sesión que se pierde en redirects PSE).
        add_action( 'woocommerce_checkout_order_created', [ __CLASS__, 'persist_uber_quote_id' ] );
    }

    /**
     * Manejador principal del pago completado.
     *
     * @param int $order_id ID del pedido de WooCommerce.
     * @return void
     */
    public static function on_order_paid( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof \WC_Order ) {
            return;
        }

        // H-4 FIX: atomic SQL claim to prevent double-processing race condition.
        // The previous get_post_meta() + update_meta_data() pattern was
        // non-atomic: two concurrent processes (e.g. woocommerce_payment_complete
        // + woocommerce_order_status_completed firing in parallel, or a cron +
        // a webhook) could both read '0', both proceed past the guard, and both
        // credit commissions → double payouts / double Siigo invoices.
        //
        // We ensure the meta row exists (add_post_meta with unique=true is a
        // no-op if it already exists), then atomically flip it from '0' to '1'
        // via a conditional UPDATE. The UPDATE is serialized at the row level
        // by InnoDB, so only ONE concurrent process gets a non-zero affected
        // row count; the others see 0 and bail.
        global $wpdb;
        add_post_meta( $order_id, '_ltms_commissions_processed', '0', true );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $claimed = $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = '1' WHERE post_id = %d AND meta_key = %s AND (meta_value IS NULL OR meta_value != '1')",
            $order_id, '_ltms_commissions_processed'
        ) );
        if ( ! $claimed ) {
            return; // Already claimed by another process
        }

        // Disparar en orden secuencial, manejando errores individualmente
        self::process_commissions( $order );
        self::schedule_invoice_sync( $order );
        self::notify_vendor( $order );
        self::debit_absorbed_shipping( $order );

        // AUDIT-SHIPPING-ENGINE #4 FIX: crear envíos Heka/Uber automáticamente
        // cuando el pedido se paga y el método de envío corresponde.
        self::auto_create_shipments( $order );

        // AUDIT-SHIPPING-FINANCE FIX: registrar el costo logístico del carrier
        // en el ledger del marketplace (Lo Tengo) para conciliación.
        self::record_carrier_shipping_cost( $order );

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
            LTMS_Core_Logger::warning( 'SHIPPING_QUOTE_SAVE_FAILED', 'save_absorbed_shipping_quote: ' . $e->getMessage() );
        }
    }

    /**
     * v2.8.4: Guarda la cotización de envío SHARED (cliente paga %, vendor absorbe resto).
     *
     * Persiste en el order meta:
     *  - _ltms_shared_shipping_cost:        costo total cotizado del carrier.
     *  - _ltms_shared_customer_pays:        monto que pagó el cliente (shipping_total).
     *  - _ltms_shared_vendor_pays:          monto que el vendor debe absorber.
     *  - _ltms_shared_pct:                  % que pagó el cliente.
     *  - _ltms_shared_provider:             carrier usado.
     *
     * El débito al vendor se realiza en record_carrier_shipping_cost() →
     * record_shipping_entry() del nuevo Shipping Cost Ledger.
     *
     * @param \WC_Order $order
     */
    public static function save_shared_shipping_quote( \WC_Order $order ): void {
        try {
            $quote = WC()->session ? WC()->session->get( 'ltms_shared_shipping_quote' ) : null;
            if ( ! $quote ) return;

            $order->update_meta_data( '_ltms_shared_shipping_cost',  (float) ( $quote['cost']          ?? 0 ) );
            $order->update_meta_data( '_ltms_shared_customer_pays',  (float) ( $quote['customer_pays'] ?? 0 ) );
            $order->update_meta_data( '_ltms_shared_vendor_pays',    (float) ( $quote['vendor_pays']   ?? 0 ) );
            $order->update_meta_data( '_ltms_shared_pct',            (float) ( $quote['share_pct']     ?? 60 ) );
            $order->update_meta_data( '_ltms_shared_provider',       sanitize_text_field( $quote['provider'] ?? '' ) );
            $order->save();

            WC()->session->__unset( 'ltms_shared_shipping_quote' );
        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::warning( 'SHIPPING_SHARED_SAVE_FAILED', 'save_shared_shipping_quote: ' . $e->getMessage() );
        }
    }

    /**
     * Debita el costo de envío absorbido de la billetera del vendedor.
     *
     * B-11 FIX (v2.8.3): si LTMS_Shipping_Cost_Ledger está activo, el débito
     * lo realiza record_carrier_shipping_cost() → record_shipping_entry() →
     * debit_vendor_for_shipping() con idempotency_key. Este método legacy se
     * conserva solo como fallback para evitar doble débito cuando el nuevo
     * ledger no está cargado.
     *
     * @param \WC_Order $order
     */
    private static function debit_absorbed_shipping( \WC_Order $order ): void {
        // Si el nuevo ledger está activo, el débito lo hace él (con idempotency
        // key y multi-vendor correcto). Salir sin hacer nada aquí.
        if ( class_exists( 'LTMS_Shipping_Cost_Ledger' ) ) {
            return;
        }

        try {
            $cost = (float) $order->get_meta( '_ltms_absorbed_shipping_cost' );
            if ( $cost <= 0 ) return;

            $vendor_id = (int) $order->get_meta( '_ltms_vendor_id' );
            if ( ! $vendor_id ) return;

            $already = $order->get_meta( '_ltms_shipping_debited' );
            if ( $already ) return;

            if ( class_exists( 'LTMS_Business_Wallet' ) ) {
                // M-103: firma correcta = debit(vendor_id, amount, description:string, metadata:array, order_id:int)
                LTMS_Business_Wallet::debit(
                    $vendor_id,
                    $cost,
                    sprintf( __( 'Envío absorbido — Pedido #%d', 'ltms' ), $order->get_id() ),
                    [ 'type' => 'shipping_absorbed', 'order_id' => $order->get_id() ],
                    $order->get_id()
                );
            }

            $order->update_meta_data( '_ltms_shipping_debited', 1 );
            $order->save();
        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::warning( 'SHIPPING_DEBIT_FAILED', 'debit_absorbed_shipping order #' . $order->get_id() . ': ' . $e->getMessage() );
        }
    }

    /**
     * AUDIT-SHIPPING-ENGINE #4 FIX: crea envíos Heka/Uber automáticamente
     * cuando el pedido se paga y el método de envío corresponde.
     *
     * Antes las APIs create_shipment (Heka) y create_delivery (Uber)
     * nunca eran llamadas desde código de producción — los clientes
     * pagaban pero no se generaba el envío real.
     *
     * @param \WC_Order $order
     * @return void
     */
    private static function auto_create_shipments( \WC_Order $order ): void {
        foreach ( $order->get_shipping_methods() as $method ) {
            $method_id = $method->get_method_id();
            $vendor_id = (int) $order->get_meta( '_ltms_vendor_id' );

            // ── Heka ──────────────────────────────────────────────────
            if ( strpos( $method_id, 'heka' ) !== false && class_exists( 'LTMS_Api_Heka' ) ) {
                try {
                    $api_key_raw = LTMS_Core_Config::get( 'ltms_heka_api_key', '' );
                    $api_key = ( str_starts_with( $api_key_raw, 'v1:' ) && class_exists( 'LTMS_Core_Security' ) )
                        ? LTMS_Core_Security::decrypt( $api_key_raw ) : $api_key_raw;

                    if ( empty( $api_key ) ) {
                        LTMS_Core_Logger::warning( 'HEKA_AUTO_CREATE_SKIP', 'API key not configured' );
                        continue;
                    }

                    $api = new LTMS_Api_Heka( $api_key );
                    $result = $api->create_shipment( [
                        'order_id'    => $order->get_id(),
                        'vendor_id'   => $vendor_id,
                        'customer'    => [
                            'name'    => $order->get_formatted_billing_full_name(),
                            'phone'   => $order->get_billing_phone(),
                            'email'   => $order->get_billing_email(),
                        ],
                        'destination' => [
                            'address' => $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2(),
                            'city'    => $order->get_shipping_city(),
                            'state'   => $order->get_shipping_state(),
                            'postcode'=> $order->get_shipping_postcode(),
                            'country' => $order->get_shipping_country(),
                        ],
                        'items'       => self::build_shipment_items( $order ),
                    ] );

                    if ( ! empty( $result['tracking_number'] ) || ! empty( $result['guia'] ) ) {
                        $tracking = $result['tracking_number'] ?? $result['guia'] ?? '';
                        $order->update_meta_data( '_ltms_heka_tracking', $tracking );
                        $order->update_meta_data( '_ltms_heka_status', 'created' );
                        $order->save();

                        LTMS_Core_Logger::info( 'HEKA_SHIPMENT_CREATED',
                            sprintf( 'Order #%d: Heka shipment created (tracking: %s)', $order->get_id(), $tracking ) );
                    }
                } catch ( \Throwable $e ) {
                    LTMS_Core_Logger::error( 'HEKA_AUTO_CREATE_FAILED',
                        sprintf( 'Order #%d: %s', $order->get_id(), $e->getMessage() ) );
                }
                continue;
            }

            // ── Uber Direct ───────────────────────────────────────────
            if ( strpos( $method_id, 'uber' ) !== false && class_exists( 'LTMS_Api_Uber' ) ) {
                try {
                    // AUDIT-SHIPPING-ENGINE #12 FIX: recuperar quote_id del order meta.
                    $quote_id = $order->get_meta( '_ltms_uber_quote_id' );
                    if ( empty( $quote_id ) ) {
                        LTMS_Core_Logger::warning( 'UBER_AUTO_CREATE_SKIP',
                            sprintf( 'Order #%d: no quote_id stored — cannot create delivery', $order->get_id() ) );
                        continue;
                    }

                    $api = LTMS_Api_Factory::get( 'uber' );
                    $result = $api->create_delivery( $quote_id, [
                        'order_id'    => $order->get_id(),
                        'external_id' => (string) $order->get_id(),
                    ] );

                    if ( ! empty( $result['id'] ) ) {
                        $order->update_meta_data( '_ltms_uber_delivery_id', $result['id'] );
                        $order->update_meta_data( '_ltms_uber_status', 'created' );
                        $order->save();

                        LTMS_Core_Logger::info( 'UBER_DELIVERY_CREATED',
                            sprintf( 'Order #%d: Uber delivery created (id: %s)', $order->get_id(), $result['id'] ) );
                    }
                } catch ( \Throwable $e ) {
                    LTMS_Core_Logger::error( 'UBER_AUTO_CREATE_FAILED',
                        sprintf( 'Order #%d: %s', $order->get_id(), $e->getMessage() ) );
                }
                continue;
            }
        }
    }

    /**
     * AUDIT-SHIPPING-ENGINE #12 FIX: persiste el Uber quote_id del carrito
     * al order meta. Antes el quote_id solo vivía en WC()->session y se
     * perdía en redirects PSE/bank transfer/OXXO → create_delivery fallaba.
     *
     * @param \WC_Order $order
     * @return void
     */
    public static function persist_uber_quote_id( \WC_Order $order ): void {
        try {
            $session = WC()->session;
            if ( ! $session ) return;

            $quote_id = $session->get( 'ltms_uber_quote_id' );
            if ( $quote_id ) {
                $order->update_meta_data( '_ltms_uber_quote_id', sanitize_text_field( $quote_id ) );
                $order->save();
            }
        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::warning( 'UBER_QUOTE_PERSIST_FAILED', $e->getMessage() );
        }
    }

    /**
     * Helper: construye array de items para create_shipment.
     *
     * @param \WC_Order $order
     * @return array
     */
    private static function build_shipment_items( \WC_Order $order ): array {
        $items = [];
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            $items[] = [
                'name'     => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'weight'   => $product ? (float) $product->get_weight() : 0,
                'price'    => (float) $item->get_total(),
            ];
        }
        return $items;
    }

    /**
     * AUDIT-SHIPPING-FINANCE v2 FIX (B-01..B-12): delega al Shipping Cost Ledger
     * centralizado para registro, conciliación y control de costos logísticos.
     *
     * PROBLEMA IDENTIFICADO (brechas críticas B-01..B-12):
     * - B-01: `defined('FOUNDATION_VENDOR_ID')` retornaba false (constante de
     *   clase, no global) → el ledger de la plataforma NUNCA se escribía.
     * - B-02: Sin tabla dedicada → imposible conciliar.
     * - B-03: Multi-vendor: se debitaba todo al `_ltms_vendor_id` del pedido,
     *   pero pedidos multi-vendor tienen varias líneas de envío con distintos
     *   vendedores → vendor equivocado paga.
     * - B-04: Sin captura del costo REAL del carrier (solo cotización).
     * - B-05: Sin import de facturas del carrier → sin conciliación.
     * - B-06: Sin presupuestos por vendedor → vendor puede absorber fletes
     *   infinitos y generar deuda incobrable.
     * - B-07: Sin verificación pre-checkout → vendor con wallet vacío sigue
     *   generando envíos absorbed.
     * - B-08: Sin workflow de disputas cuando carrier cobra de más.
     * - B-09: Sin alertas (vendor sobre presupuesto, varianza, SLA disputas).
     * - B-10: Vendor no ve estado de cuenta de fletes.
     * - B-11: Sin idempotency key → doble registro en retries de cron.
     * - B-12: Sin tracking de costo real vs cotización por carrier (sin
     *   inteligencia comercial para renegociar tarifas).
     *
     * SOLUCIÓN: delegar al nuevo `LTMS_Shipping_Cost_Ledger` que:
     *  - Usa tabla dedicada `lt_shipping_cost_ledger` (1 fila por servicio).
     *  - Soporta multi-vendor (1 entry por shipping line).
     *  - Aplica idempotency_key (re-ejecuciones no duplican).
     *  - Usa correctamente FOUNDATION_VENDOR_ID como class constant.
     *  - Captura costo REAL cuando llega la factura del carrier (import CSV).
     *  - Calcula varianza automática (real - quote).
     *  - Abre disputas automáticas cuando varianza > tolerancia.
     *  - Ajusta el cargo al vendor cuando real ≠ quote.
     *  - Verifica presupuesto del vendor pre-checkout.
     *
     * @param \WC_Order $order
     * @return void
     */
    private static function record_carrier_shipping_cost( \WC_Order $order ): void {
        if ( ! class_exists( 'LTMS_Shipping_Cost_Ledger' ) ) {
            // Fallback al método legacy si la nueva clase no está cargada
            // (no debería pasar, pero defensive).
            self::record_carrier_shipping_cost_legacy( $order );
            return;
        }

        try {
            $entries = LTMS_Shipping_Cost_Ledger::record_shipping_entry( $order );

            // Migrar el comportamiento del debit_absorbed_shipping legacy:
            // si el nuevo ledger ya debitó (dentro de record_shipping_entry),
            // marcar el flag legacy para que el método antiguo no lo duplique.
            if ( ! empty( $entries ) ) {
                $order->update_meta_data( '_ltms_shipping_debited', 1 );
                $order->save();
            }

            LTMS_Core_Logger::info(
                'SHIPPING_COST_RECORDED_V2',
                sprintf(
                    'Order #%d: %d ledger entries creados.',
                    $order->get_id(),
                    count( $entries )
                ),
                [ 'order_id' => $order->get_id(), 'entries' => $entries ]
            );
        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::warning(
                'SHIPPING_COST_RECORD_FAILED',
                sprintf( 'Order #%d: %s', $order->get_id(), $e->getMessage() )
            );
        }
    }

    /**
     * Método legacy (pre-v2.8.3) conservado como fallback defensivo.
     * Solo se ejecuta si LTMS_Shipping_Cost_Ledger no está cargada.
     *
     * @param \WC_Order $order
     * @return void
     */
    private static function record_carrier_shipping_cost_legacy( \WC_Order $order ): void {
        try {
            $shipping_total  = (float) $order->get_shipping_total();
            $shipping_tax    = (float) $order->get_shipping_tax();
            $shipping_total_with_tax = $shipping_total + $shipping_tax;
            $vendor_id       = (int) $order->get_meta( '_ltms_vendor_id' );
            $absorbed_cost   = (float) $order->get_meta( '_ltms_absorbed_shipping_cost' );
            $absorbed_provider = (string) $order->get_meta( '_ltms_absorbed_shipping_provider' );

            // Determinar el carrier.
            $carrier = 'unknown';
            foreach ( $order->get_shipping_methods() as $method ) {
                $method_id = $method->get_method_id();
                if ( strpos( $method_id, 'deprisa' ) !== false ) $carrier = 'deprisa';
                elseif ( strpos( $method_id, 'aveonline' ) !== false ) $carrier = 'aveonline';
                elseif ( strpos( $method_id, 'heka' ) !== false ) $carrier = 'heka';
                elseif ( strpos( $method_id, 'uber' ) !== false ) $carrier = 'uber';
                elseif ( strpos( $method_id, 'pickup' ) !== false ) $carrier = 'pickup';
                elseif ( strpos( $method_id, 'own' ) !== false ) $carrier = 'own_delivery';
                elseif ( strpos( $method_id, 'free_absorbed' ) !== false ) $carrier = $absorbed_provider ?: 'free_absorbed';
            }

            $carrier_cost = $absorbed_cost > 0
                ? $absorbed_cost
                : ( $shipping_total > 0 && ! in_array( $carrier, [ 'own_delivery', 'pickup' ], true ) ? $shipping_total : 0.0 );

            $order->update_meta_data( '_ltms_shipping_collected_from_buyer', $shipping_total_with_tax );
            $order->update_meta_data( '_ltms_shipping_charged_to_vendor', $absorbed_cost );
            $order->update_meta_data( '_ltms_shipping_carrier', $carrier );
            $order->update_meta_data( '_ltms_shipping_carrier_cost', $carrier_cost );
            $order->update_meta_data( '_ltms_shipping_vendor_id', $vendor_id );
            $order->update_meta_data( '_ltms_shipping_reconciled', 0 );
            $order->save();

            // B-01 FIX: usar class constant en lugar de defined() global.
            if ( $carrier_cost > 0 && class_exists( 'LTMS_Business_Wallet' ) && class_exists( 'LTMS_Donation_Manager' ) ) {
                try {
                    LTMS_Business_Wallet::execute_transaction(
                        LTMS_Donation_Manager::FOUNDATION_VENDOR_ID,
                        'fee',
                        $carrier_cost,
                        sprintf(
                            'Costo logístico carrier (%s) — Pedido #%d, Vendor #%d',
                            $carrier, $order->get_id(), $vendor_id
                        ),
                        [
                            'type'           => 'shipping_carrier_cost',
                            'order_id'       => $order->get_id(),
                            'vendor_id'      => $vendor_id,
                            'carrier'        => $carrier,
                            'buyer_paid'     => $shipping_total_with_tax,
                            'vendor_charged' => $absorbed_cost,
                            'carrier_cost'   => $carrier_cost,
                        ],
                        $order->get_id(),
                        '',
                        sprintf( 'platform_shipping_o%d', $order->get_id() ) // idempotency_key B-11 FIX
                    );
                } catch ( \Throwable $e ) {
                    LTMS_Core_Logger::warning( 'SHIPPING_LEDGER_ENTRY_FAILED',
                        sprintf( 'Order #%d: %s', $order->get_id(), $e->getMessage() ) );
                }
            }
        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::warning( 'SHIPPING_COST_RECORD_FAILED',
                sprintf( 'Order #%d: %s', $order->get_id(), $e->getMessage() ) );
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

            // E-11 FIX: respetar el flag ltms_email_new_order para notificación por email al vendedor.
            if ( get_option( 'ltms_email_new_order', 'yes' ) === 'yes' ) {
                $vendor_user = get_userdata( $vendor_id );
                if ( $vendor_user && $vendor_user->user_email ) {
                    $email_subject = sprintf(
                        /* translators: %s: número de pedido */
                        __( '[Lo Tengo] Nuevo pedido #%s recibido', 'ltms' ),
                        $order->get_order_number()
                    );
                    $email_body = sprintf(
                        /* translators: 1: nombre, 2: número de pedido, 3: total, 4: URL panel */
                        __( "Hola %1\$s,

Tienes un nuevo pedido #%2\$s por %3\$s.

Revísalo en tu panel de vendedor:
%4\$s

Gracias por vender en Lo Tengo.", 'ltms' ),
                        $vendor_user->display_name,
                        $order->get_order_number(),
                        LTMS_Utils::format_money( (float) $order->get_total() ),
                        home_url( '/panel-vendedor/pedidos/' )
                    );
                    wp_mail( $vendor_user->user_email, $email_subject, $email_body );
                }
            }
        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::warning(
                'NOTIFICATION_FAILED',
                sprintf( 'No se pudo notificar al vendedor #%d: %s', $vendor_id, $e->getMessage() )
            );
        }
    }
}
