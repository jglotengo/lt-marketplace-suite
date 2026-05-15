<?php
/**
 * LTMS Business Consumer Protection — Protección al Consumidor / Vesting
 *
 * Implementa las reglas de retención de fondos (vesting period) para
 * garantizar protección al consumidor en disputas. Los fondos del vendedor
 * permanecen en estado "hold" durante el período configurado antes de
 * liberarse a su billetera disponible.
 *
 * Regla: Ley 1480 de Colombia (Estatuto del Consumidor) — 5 días hábiles.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/business
 * @version    1.7.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Business_Consumer_Protection
 */
class LTMS_Business_Consumer_Protection {

    use LTMS_Logger_Aware;

    /**
     * Días de retención por defecto.
     */
    const DEFAULT_HOLD_DAYS = 5;

    /**
     * Registra los hooks del módulo.
     *
     * @return void
     */
    public static function init(): void {
        // Verificar y liberar fondos retenidos — se ejecuta en el cron diario
        add_action( 'ltms_daily_cron', [ __CLASS__, 'release_eligible_holds' ] );
        add_action( 'ltms_release_vendor_hold', [ __CLASS__, 'release_single_hold' ], 10, 2 );

        // M-202: extender hold cuando shipping provider confirma entrega
        // (Uber, Aveonline, Heka disparan ltms_shipping_delivered desde sus webhook handlers).
        add_action( 'ltms_shipping_delivered', [ __CLASS__, 'on_shipping_delivered' ], 10, 1 );
        add_action( 'ltms_shipping_failed',    [ __CLASS__, 'on_shipping_failed' ],    10, 2 );
    }

    /**
     * Retiene los fondos de una comisión durante el período de protección.
     *
     * @param int   $vendor_id  ID del vendedor.
     * @param float $amount     Monto a retener.
     * @param int   $order_id   ID del pedido asociado.
     * @return bool
     */
    public static function hold_commission( int $vendor_id, float $amount, int $order_id ): bool {
        $hold_days   = (int) LTMS_Core_Config::get( 'ltms_consumer_protection_days', self::DEFAULT_HOLD_DAYS );
        $release_at  = gmdate( 'Y-m-d H:i:s', strtotime( "+{$hold_days} weekdays" ) );

        global $wpdb;
        $table = $wpdb->prefix . 'lt_wallet_holds';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $inserted = $wpdb->insert( $table, [
            'vendor_id'  => $vendor_id,
            'amount'     => $amount,
            'order_id'   => $order_id,
            'reason'     => 'consumer_protection',
            'status'     => 'held',
            'release_at' => $release_at,
            'created_at' => gmdate( 'Y-m-d H:i:s' ),
        ], [ '%d', '%f', '%d', '%s', '%s', '%s', '%s' ] );

        if ( ! $inserted ) {
            return false;
        }

        // M-84: Acreditar y retener en wallet para que balance_pending refleje los fondos
        // retenidos y el vendedor NO los vea como disponibles hasta que venza el periodo.
        if ( class_exists( 'LTMS_Business_Wallet' ) ) {
            try {
                LTMS_Business_Wallet::credit(
                    $vendor_id,
                    $amount,
                    sprintf( 'Comision pedido #%d - en retencion (proteccion al consumidor)', $order_id ),
                    [ 'type' => 'commission', 'order_id' => $order_id, 'held_until' => $release_at ],
                    $order_id
                );
                LTMS_Business_Wallet::hold(
                    $vendor_id,
                    $amount,
                    sprintf( 'Retencion Ley 1480 - pedido #%d, libera: %s', $order_id, $release_at ),
                    [ 'type' => 'consumer_protection', 'order_id' => $order_id, 'release_at' => $release_at ]
                );
            } catch ( \Throwable $e ) {
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::log( 'COMMISSION_HOLD_WALLET_FAILED',
                        sprintf( 'Error al registrar hold en wallet vendedor #%d: %s', $vendor_id, $e->getMessage() )
                    );
                }
            }
        }

        if ( class_exists( 'LTMS_Core_Logger' ) ) LTMS_Core_Logger::log(
            'COMMISSION_HELD',
            sprintf( 'Fondos retenidos: %.2f para vendedor #%d, pedido #%d, liberacion: %s', $amount, $vendor_id, $order_id, $release_at )
        );

        return true;
    }

    /**
     * Libera todos los holds elegibles (fecha de liberación pasada).
     * Se ejecuta desde el cron diario.
     *
     * M-202: si `ltms_payout_require_delivery` = 'yes' (default), solo libera holds
     * de pedidos con entrega confirmada por shipping provider o productos digitales
     * (sin shipping). El resto queda en hold hasta confirmación o decisión manual.
     *
     * @return void
     */
    public static function release_eligible_holds(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_wallet_holds';
        $now   = gmdate( 'Y-m-d H:i:s' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $holds = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE status = 'held' AND release_at <= %s LIMIT 100",
            $now
        ) );

        $require_delivery = LTMS_Core_Config::get( 'ltms_payout_require_delivery', 'yes' ) === 'yes';

        foreach ( $holds as $hold ) {
            if ( $require_delivery && ! self::is_order_delivered_or_no_shipping( (int) $hold->order_id ) ) {
                // No liberar: el pedido no se ha entregado y el flag de protección está activo.
                // El hold se libera cuando llegue el evento ltms_shipping_delivered o cuando admin lo apruebe manualmente.
                continue;
            }
            self::release_single_hold( (int) $hold->id, (int) $hold->vendor_id );
        }
    }

    /**
     * Detecta si un pedido ya fue entregado (shipping provider confirmó) o
     * no requiere shipping (productos digitales/servicios).
     *
     * M-202: usado para gating de liberación de holds.
     *
     * @param int $order_id ID del pedido WooCommerce.
     * @return bool
     */
    public static function is_order_delivered_or_no_shipping( int $order_id ): bool {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return false;
        }

        // Pedido sin shipping (todos los items virtuales/descargables) — libera sin esperar entrega.
        if ( ! $order->needs_shipping_address() ) {
            return true;
        }

        $delivered_statuses = [
            'delivered',
            'dropoff_complete',
            'entregado',
        ];

        // Cualquier provider confirmó entrega.
        $provider_status_meta = [
            '_ltms_uber_delivery_status',
            '_ltms_aveonline_status',
            '_ltms_heka_status',
            '_ltms_proships_status',
        ];
        foreach ( $provider_status_meta as $key ) {
            $status = strtolower( (string) $order->get_meta( $key ) );
            if ( $status !== '' && in_array( $status, $delivered_statuses, true ) ) {
                return true;
            }
        }

        // Marca explícita por listener (eventos ltms_shipping_delivered).
        if ( $order->get_meta( '_ltms_delivered_at' ) ) {
            return true;
        }

        return false;
    }

    /**
     * Shipping provider confirmó entrega: actualiza release_at del hold para que el
     * período Ley 1480 cuente DESDE la entrega real, no desde la fecha de pago.
     *
     * @param int $order_id ID del pedido.
     * @return void
     */
    public static function on_shipping_delivered( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Marca canónica para que is_order_delivered_or_no_shipping() la lea sin importar el provider.
        $order->update_meta_data( '_ltms_delivered_at', gmdate( 'Y-m-d H:i:s' ) );
        $order->save();

        global $wpdb;
        $table      = $wpdb->prefix . 'lt_wallet_holds';
        $hold_days  = (int) LTMS_Core_Config::get( 'ltms_consumer_protection_days', self::DEFAULT_HOLD_DAYS );
        $release_at = gmdate( 'Y-m-d H:i:s', strtotime( "+{$hold_days} weekdays" ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update(
            $table,
            [ 'release_at' => $release_at ],
            [ 'order_id' => $order_id, 'status' => 'held' ],
            [ '%s' ],
            [ '%d', '%s' ]
        );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::log(
                'HOLD_DELIVERY_CONFIRMED',
                sprintf( 'Pedido #%d entregado — hold release_at = %s', $order_id, $release_at )
            );
        }
    }

    /**
     * Shipping provider reportó fallo/cancelación — congela el hold hasta revisión manual.
     *
     * @param int    $order_id ID del pedido.
     * @param string $reason   Motivo del fallo (opcional).
     * @return void
     */
    public static function on_shipping_failed( int $order_id, string $reason = 'shipping_failed' ): void {
        self::freeze_hold_for_dispute( $order_id, sanitize_text_field( $reason ) );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::log(
                'HOLD_FROZEN_SHIPPING_FAILED',
                sprintf( 'Pedido #%d: shipping reportó fallo (%s) — hold congelado.', $order_id, $reason )
            );
        }
    }

    /**
     * Libera un hold individual y acredita los fondos en la billetera.
     *
     * @param int $hold_id   ID del hold.
     * @param int $vendor_id ID del vendedor.
     * @return void
     */
    public static function release_single_hold( int $hold_id, int $vendor_id ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_wallet_holds';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $hold = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE id = %d AND vendor_id = %d AND status = 'held'",
            $hold_id,
            $vendor_id
        ) );

        if ( ! $hold ) {
            return;
        }

        // Marcar como liberado PRIMERO (prevenir doble liberación)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $updated = $wpdb->update(
            $table,
            [ 'status' => 'released', 'released_at' => gmdate( 'Y-m-d H:i:s' ) ],
            [ 'id' => $hold_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        if ( ! $updated ) {
            return;
        }

        // M-84: Liberar desde balance_pending → balance usando release() (NO credit).
        // El dinero ya fue acreditado en hold_commission() — solo hay que moverlo
        // de retenido a disponible para evitar doble acreditación.
        if ( class_exists( 'LTMS_Business_Wallet' ) ) {
            LTMS_Business_Wallet::release(
                $vendor_id,
                (float) $hold->amount,
                sprintf( 'Fondos liberados por vencimiento de retencion — Hold #%d, Pedido #%d', $hold_id, $hold->order_id ),
                [ 'hold_id' => $hold_id, 'order_id' => $hold->order_id ]
            );
        }

        if ( class_exists( 'LTMS_Core_Logger' ) ) LTMS_Core_Logger::log( 
            'HOLD_RELEASED',
            sprintf( 'Hold #%d liberado: %.2f para vendedor #%d', $hold_id, $hold->amount, $vendor_id )
        );
    }

    /**
     * Congela un hold (ej: cuando se abre una disputa).
     *
     * @param int    $order_id ID del pedido.
     * @param string $reason   Razón del congelamiento.
     * @return bool
     */
    public static function freeze_hold_for_dispute( int $order_id, string $reason = 'dispute' ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_wallet_holds';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (bool) $wpdb->update(
            $table,
            [ 'status' => 'frozen', 'freeze_reason' => sanitize_text_field( $reason ) ],
            [ 'order_id' => $order_id, 'status' => 'held' ],
            [ '%s', '%s' ],
            [ '%d', '%s' ]
        );
    }
}
