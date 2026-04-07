<?php
/**
 * LTMS Booking Season Manager
 *
 * Calcula modificadores de precio por temporada.
 * Lee la tabla lt_booking_season_rules (product_id=0 → reglas globales).
 *
 * @package    LTMS
 * @subpackage LTMS/includes/booking
 * @version    2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Booking_Season_Manager
 */
class LTMS_Booking_Season_Manager {

    private static bool $initialized = false;

    public static function init(): void {
        if ( self::$initialized ) return;
        self::$initialized = true;

        add_filter( 'ltms_booking_price_for_dates', [ self::class, 'apply_season_modifier' ], 10, 3 );
    }

    /**
     * Filtra el precio de una reserva aplicando el modificador de temporada.
     *
     * @param float  $price       Precio base.
     * @param int    $product_id
     * @param string $checkin_date Y-m-d
     * @return float  Precio modificado.
     */
    public static function apply_season_modifier( float $price, int $product_id, string $checkin_date ): float {
        $modifier = self::get_modifier_for_date( $product_id, $checkin_date );
        return round( $price * $modifier, 2 );
    }

    /**
     * Obtiene el modificador de precio para una fecha específica.
     * Prioridad: regla de producto > regla global.
     *
     * @param int    $product_id
     * @param string $date Y-m-d
     * @return float Multiplicador (1.0 = sin cambio)
     */
    public static function get_modifier_for_date( int $product_id, string $date ): float {
        global $wpdb;

        // Producto específico primero.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT price_modifier FROM {$wpdb->prefix}lt_booking_season_rules
                 WHERE product_id = %d AND %s BETWEEN date_from AND date_to
                 ORDER BY id DESC LIMIT 1",
                $product_id,
                $date
            ),
            ARRAY_A
        );

        if ( $row ) {
            return max( 0.1, (float) $row['price_modifier'] );
        }

        // Regla global (product_id = 0).
        $global = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT price_modifier FROM {$wpdb->prefix}lt_booking_season_rules
                 WHERE product_id = 0 AND %s BETWEEN date_from AND date_to
                 ORDER BY id DESC LIMIT 1",
                $date
            ),
            ARRAY_A
        );

        return $global ? max( 0.1, (float) $global['price_modifier'] ) : 1.0;
    }

    /**
     * Calcula el precio total para un rango de noches aplicando temporadas.
     *
     * @param float  $base_price_per_night
     * @param int    $product_id
     * @param string $checkin_date  Y-m-d
     * @param string $checkout_date Y-m-d
     * @return float Precio total
     */
    public static function calculate_total(
        float  $base_price_per_night,
        int    $product_id,
        string $checkin_date,
        string $checkout_date
    ): float {
        $total   = 0.0;
        $current = strtotime( $checkin_date );
        $end     = strtotime( $checkout_date );

        while ( $current < $end ) {
            $date     = gmdate( 'Y-m-d', $current );
            $modifier = self::get_modifier_for_date( $product_id, $date );
            $total   += $base_price_per_night * $modifier;
            $current += DAY_IN_SECONDS;
        }

        return round( $total, 2 );
    }

    /**
     * Retorna las reglas de temporada activas de un producto (incluye globales).
     *
     * @param int $product_id 0 = sólo globales
     * @return array
     */
    public static function get_rules( int $product_id = 0 ): array {
        global $wpdb;

        $ids = $product_id ? [ 0, $product_id ] : [ 0 ];
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        return $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}lt_booking_season_rules
                 WHERE product_id IN ($placeholders) ORDER BY date_from ASC",
                ...$ids
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Guarda (upsert) una regla de temporada.
     *
     * @param array $data Keys: product_id, season_name, price_modifier, date_from, date_to, country_code
     * @return int|\WP_Error
     */
    public static function save_rule( array $data ): int|\WP_Error {
        global $wpdb;

        $insert = [
            'product_id'     => (int) ( $data['product_id'] ?? 0 ),
            'season_name'    => sanitize_text_field( $data['season_name'] ?? '' ),
            'price_modifier' => (float) ( $data['price_modifier'] ?? 1.0 ),
            'date_from'      => sanitize_text_field( $data['date_from'] ?? '' ),
            'date_to'        => sanitize_text_field( $data['date_to'] ?? '' ),
            'country_code'   => strtoupper( sanitize_text_field( $data['country_code'] ?? '' ) ),
            'created_at'     => current_time( 'mysql' ),
            'updated_at'     => current_time( 'mysql' ),
        ];

        if ( isset( $data['id'] ) && (int) $data['id'] > 0 ) {
            unset( $insert['created_at'] );
            $insert['updated_at'] = current_time( 'mysql' );
            $wpdb->update( $wpdb->prefix . 'lt_booking_season_rules', $insert, [ 'id' => (int) $data['id'] ] );
            return (int) $data['id'];
        }

        $wpdb->insert( $wpdb->prefix . 'lt_booking_season_rules', $insert );
        if ( ! $wpdb->insert_id ) {
            return new \WP_Error( 'db_error', __( 'Error al guardar la regla de temporada.', 'ltms' ) );
        }
        return (int) $wpdb->insert_id;
    }
}
