<?php
/**
 * LTMS Referral Tree - Red de Referidos MLM (Multi-Level Marketing)
 *
 * Gestiona la red de referidos de múltiples niveles:
 * - Registro de la cadena de referidos al momento del registro del vendedor
 * - Distribución de comisiones por venta a través de la cadena
 * - Cálculo de métricas de red (ancho, profundidad, ingresos por nivel)
 * - Visualización del árbol de referidos
 *
 * La estructura es una red binaria/ramificada sin límite de profundidad,
 * con tasas decrecientes por nivel (configurables).
 *
 * @package    LTMS
 * @subpackage LTMS/includes/business
 * @version    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Referral_Tree
 */
final class LTMS_Referral_Tree {

    use LTMS_Logger_Aware;

    /**
     * Tasas de comisión por nivel (porcentaje de la comisión de plataforma).
     * Configurable vía ltms_referral_rates (JSON array).
     *
     * Default: Nivel 1 = 40%, Nivel 2 = 20%, Nivel 3 = 10%
     */
    const DEFAULT_RATES = [ 0.40, 0.20, 0.10 ];

    /**
     * Registra un nuevo nodo en la red de referidos.
     *
     * @param int    $vendor_id   ID del nuevo vendedor.
     * @param string $referral_code Código del patrocinador.
     * @return bool True si se registró exitosamente.
     */
    public static function register_node( int $vendor_id, string $referral_code ): bool {
        $sponsor_id = self::get_vendor_by_code( $referral_code );
        if ( ! $sponsor_id ) {
            return false;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lt_referral_network';

        // Calcular nivel en la red
        $sponsor_level = (int) $wpdb->get_var( // phpcs:ignore
            $wpdb->prepare( "SELECT level FROM `{$table}` WHERE vendor_id = %d", $sponsor_id ) // phpcs:ignore
        );
        $vendor_level = $sponsor_level + 1;

        // Construir path de ancestros
        $ancestor_path = self::build_ancestor_path( $sponsor_id, $table );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $inserted = $wpdb->insert(
            $table,
            [
                'vendor_id'     => $vendor_id,
                'sponsor_id'    => $sponsor_id,
                'level'         => $vendor_level,
                'ancestor_path' => $ancestor_path,
                'joined_at'     => LTMS_Utils::now_utc(),
            ],
            [ '%d', '%d', '%d', '%s', '%s' ]
        );

        if ( $inserted ) {
            // Guardar código de referido del patrocinador en el perfil del vendedor
            update_user_meta( $vendor_id, 'ltms_sponsor_id', $sponsor_id );
            update_user_meta( $vendor_id, 'ltms_referral_level', $vendor_level );

            LTMS_Core_Logger::info(
                'REFERRAL_NODE_REGISTERED',
                sprintf( 'Vendedor #%d registrado como referido de #%d (nivel %d)', $vendor_id, $sponsor_id, $vendor_level )
            );
        }

        return (bool) $inserted;
    }

    /**
     * Distribuye comisiones de referido entre los ancestros de la red.
     *
     * @param int   $vendor_id    ID del vendedor que realizó la venta.
     * @param float $platform_fee Comisión total de la plataforma (base para cálculo).
     * @param int   $order_id     ID del pedido original.
     * @return void
     */
    public static function distribute_commissions( int $vendor_id, float $platform_fee, int $order_id ): void {
        $rates = self::get_referral_rates();
        if ( empty( $rates ) ) {
            return;
        }

        $chain = self::get_sponsor_chain( $vendor_id );
        if ( empty( $chain ) ) {
            return;
        }

        $max_levels  = count( $rates );
        $distributed = 0.0;

        foreach ( $chain as $index => $sponsor_id ) {
            if ( $index >= $max_levels ) {
                break;
            }

            $rate       = $rates[ $index ];
            $commission = round( $platform_fee * $rate, 2 );

            if ( $commission <= 0 ) {
                continue;
            }

            try {
                LTMS_Wallet::credit(
                    $sponsor_id,
                    $commission,
                    'referral',
                    sprintf(
                        /* translators: %1$d: nivel, %2$d: ID vendedor referido, %3$d: pedido */
                        __( 'Comisión referido Nivel %1$d - Vendedor #%2$d - Pedido #%3$d', 'ltms' ),
                        $index + 1,
                        $vendor_id,
                        $order_id
                    ),
                    [
                        'source_vendor_id' => $vendor_id,
                        'order_id'         => $order_id,
                        'referral_level'   => $index + 1,
                        'rate'             => $rate,
                    ]
                );
                $distributed += $commission;

            } catch ( \Throwable $e ) {
                LTMS_Core_Logger::error(
                    'REFERRAL_COMMISSION_ERROR',
                    sprintf( 'Error acreditando comisión referido nivel %d a vendedor #%d: %s', $index + 1, $sponsor_id, $e->getMessage() )
                );
            }
        }

        if ( $distributed > 0 ) {
            LTMS_Core_Logger::info(
                'REFERRAL_COMMISSIONS_DISTRIBUTED',
                sprintf(
                    'Distribuidos %s en comisiones de referido para pedido #%d (%d niveles)',
                    LTMS_Utils::format_money( $distributed ),
                    $order_id,
                    min( count( $chain ), $max_levels )
                )
            );
        }
    }

    /**
     * Obtiene la cadena de patrocinadores (del más cercano al más lejano).
     *
     * @param int $vendor_id ID del vendedor.
     * @return int[] Array de IDs de patrocinadores.
     */
    public static function get_sponsor_chain( int $vendor_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_referral_network';

        // Obtener el path del vendedor
        $row = $wpdb->get_row( // phpcs:ignore
            $wpdb->prepare( "SELECT sponsor_id, ancestor_path FROM `{$table}` WHERE vendor_id = %d", $vendor_id ), // phpcs:ignore
            ARRAY_A
        );

        if ( ! $row || ! $row['sponsor_id'] ) {
            return [];
        }

        // ancestor_path es tipo "1/5/12/23" (root primero, sponsor directo último)
        $path = array_filter( explode( '/', (string) $row['ancestor_path'] ) );
        $path = array_reverse( array_values( $path ) ); // Sponsor directo primero

        return array_map( 'intval', $path );
    }

    /**
     * Obtiene el árbol de descendientes de un vendedor para visualización.
     *
     * @param int $vendor_id  ID del vendedor raíz.
     * @param int $max_depth  Profundidad máxima a recuperar.
     * @return array Árbol jerárquico de descendientes.
     */
    public static function get_descendant_tree( int $vendor_id, int $max_depth = 3 ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_referral_network';

        // Obtener todos los descendientes directos del primer nivel
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $direct = $wpdb->get_results(
            $wpdb->prepare( "SELECT vendor_id, level FROM `{$table}` WHERE sponsor_id = %d ORDER BY joined_at ASC", $vendor_id ),
            ARRAY_A
        );

        if ( empty( $direct ) || $max_depth <= 0 ) {
            return [];
        }

        $tree = [];
        foreach ( $direct as $child ) {
            $child_id  = (int) $child['vendor_id'];
            $user      = get_userdata( $child_id );
            $tree[]    = [
                'vendor_id'    => $child_id,
                'display_name' => $user ? $user->display_name : __( 'Usuario eliminado', 'ltms' ),
                'level'        => (int) $child['level'],
                'children'     => self::get_descendant_tree( $child_id, $max_depth - 1 ),
            ];
        }

        return $tree;
    }

    /**
     * Estadísticas de la red de un vendedor.
     *
     * @param int $vendor_id ID del vendedor.
     * @return array{total_referrals: int, active_referrals: int, total_earned: float, levels: array}
     */
    public static function get_network_stats( int $vendor_id ): array {
        global $wpdb;
        $ref_table   = $wpdb->prefix . 'lt_referral_network';
        $wallet_table = $wpdb->prefix . 'lt_wallet_transactions';

        // Total de referidos en toda la red (cualquier nivel)
        $total = (int) $wpdb->get_var( // phpcs:ignore
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$ref_table}` WHERE ancestor_path LIKE %s", // phpcs:ignore
                '%' . $wpdb->esc_like( (string) $vendor_id ) . '%'
            )
        );

        // Total ganado en comisiones de referido
        $earned = (float) $wpdb->get_var( // phpcs:ignore
            $wpdb->prepare(
                "SELECT SUM(amount) FROM `{$wallet_table}` WHERE user_id = %d AND type = 'referral'", // phpcs:ignore
                $vendor_id
            )
        );

        return [
            'total_referrals'  => $total,
            'total_earned'     => $earned,
        ];
    }

    // ── Helpers privados ──────────────────────────────────────────

    /**
     * Obtiene el vendor_id a partir de un código de referido.
     *
     * @param string $code Código de referido (8 chars alfanumérico).
     * @return int 0 si no existe.
     */
    private static function get_vendor_by_code( string $code ): int {
        $users = get_users([
            'meta_key'   => 'ltms_referral_code',
            'meta_value' => sanitize_text_field( $code ),
            'number'     => 1,
            'fields'     => 'ID',
        ]);

        return ! empty( $users ) ? (int) $users[0] : 0;
    }

    /**
     * Construye el path de ancestros para un nuevo nodo.
     *
     * @param int    $sponsor_id ID del patrocinador.
     * @param string $table      Nombre de la tabla.
     * @return string Path tipo "1/5/12".
     */
    private static function build_ancestor_path( int $sponsor_id, string $table ): string {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $parent_path = (string) $wpdb->get_var(
            $wpdb->prepare( "SELECT ancestor_path FROM `{$table}` WHERE vendor_id = %d", $sponsor_id )
        );

        $path = $parent_path ? $parent_path . '/' . $sponsor_id : (string) $sponsor_id;
        return $path;
    }

    /**
     * Devuelve las tasas de comisión por nivel.
     *
     * @return float[]
     */
    private static function get_referral_rates(): array {
        $configured = LTMS_Core_Config::get( 'ltms_referral_rates', '' );
        if ( $configured ) {
            $decoded = json_decode( $configured, true );
            if ( is_array( $decoded ) && ! empty( $decoded ) ) {
                return array_map( 'floatval', $decoded );
            }
        }
        return self::DEFAULT_RATES;
    }
}
