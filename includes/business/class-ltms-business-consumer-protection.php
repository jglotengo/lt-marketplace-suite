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

        if ( $inserted ) {
            self::log_info(
                'COMMISSION_HELD',
                sprintf( 'Fondos retenidos: %.2f para vendedor #%d, pedido #%d, liberación: %s', $amount, $vendor_id, $order_id, $release_at )
            );
        }

        return (bool) $inserted;
    }

    /**
     * Libera todos los holds elegibles (fecha de liberación pasada).
     * Se ejecuta desde el cron diario.
     *
     * @return void
     */
    public static function release_eligible_holds(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_wallet_holds';
        $now   = gmdate( 'Y-m-d H:i:s' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $holds = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE status = 'held' AND release_at <= %s LIMIT 100",
            $now
        ) );

        foreach ( $holds as $hold ) {
            self::release_single_hold( (int) $hold->id, (int) $hold->vendor_id );
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

        // Acreditar en billetera
        if ( class_exists( 'LTMS_Business_Wallet' ) ) {
            LTMS_Business_Wallet::credit(
                $vendor_id,
                (float) $hold->amount,
                'hold_released',
                sprintf( __( 'Fondos liberados — Hold #%d, Pedido #%d', 'ltms' ), $hold_id, $hold->order_id )
            );
        }

        self::log_info(
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
