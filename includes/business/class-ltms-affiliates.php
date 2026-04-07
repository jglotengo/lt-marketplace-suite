<?php
/**
 * LTMS Affiliates - Gestión de Red de Afiliados y Comisiones MLM
 *
 * @package    LTMS\Business
 * @version    1.5.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class LTMS_Affiliates
 *
 * Gestiona la red de afiliados, genera códigos de referido,
 * calcula comisiones y sincroniza con TPTC.
 */
class LTMS_Affiliates {

    use LTMS_Singleton;
    use LTMS_Logger_Aware;

    /**
     * Longitud del código de referido.
     */
    private const CODE_LENGTH = 8;

    /**
     * Tasas de comisión por nivel (% del platform_fee).
     */
    private const COMMISSION_RATES = [
        1 => 0.40,
        2 => 0.20,
        3 => 0.10,
    ];

    /**
     * Init hooks.
     */
    public static function init(): void {
        $instance = self::get_instance();
        add_action( 'ltms_vendor_registered', [ $instance, 'on_vendor_registered' ], 10, 2 );
        add_action( 'ltms_payout_completed',  [ $instance, 'on_payout_completed' ],  10, 2 );

        // REST endpoint para estadísticas de afiliados
        add_action( 'rest_api_init', [ $instance, 'register_rest_routes' ] );
    }

    // ── Lifecycle Hooks ────────────────────────────────────────────

    /**
     * Procesa registro de un nuevo vendedor.
     *
     * @param int    $vendor_id    ID del nuevo vendedor.
     * @param string $referral_code Código de referido del sponsor (opcional).
     */
    public function on_vendor_registered( int $vendor_id, string $referral_code = '' ): void {
        // Generar código único para este vendedor
        $code = $this->generate_unique_code( $vendor_id );
        update_user_meta( $vendor_id, 'ltms_referral_code', $code );

        // Registrar en red si hay sponsor
        if ( $referral_code ) {
            $this->link_to_sponsor( $vendor_id, $referral_code );
        } else {
            LTMS_Referral_Tree::register_node( $vendor_id, '' );
        }

        // Sincronizar con TPTC
        try {
            $tptc = LTMS_Api_Factory::get( 'tptc' );
            $tptc->register_affiliate( $vendor_id, $code, $referral_code );
        } catch ( \Exception $e ) {
            $this->log_warning( 'TPTC affiliate sync failed: ' . $e->getMessage(), [ 'vendor_id' => $vendor_id ] );
        }
    }

    /**
     * Procesa pago completado para distribuir comisiones a la cadena.
     *
     * @param int   $vendor_id  ID del vendedor que recibió el pago.
     * @param float $net_amount Monto neto del pago.
     */
    public function on_payout_completed( int $vendor_id, float $net_amount ): void {
        // Solo registrar el evento; las comisiones MLM se distribuyen en order_split.
        $this->log_info( 'Payout completed for affiliate chain', [
            'vendor_id'  => $vendor_id,
            'net_amount' => $net_amount,
        ] );
    }

    // ── Code Generation ────────────────────────────────────────────

    /**
     * Genera un código de referido único.
     *
     * @param  int    $vendor_id
     * @return string
     */
    public function generate_unique_code( int $vendor_id ): string {
        $attempts = 0;

        do {
            $code = $this->generate_code( $vendor_id, $attempts );
            $attempts++;
        } while ( $this->code_exists( $code ) && $attempts < 10 );

        return strtoupper( $code );
    }

    /**
     * Genera el código base.
     *
     * @param  int $vendor_id
     * @param  int $attempt
     * @return string
     */
    private function generate_code( int $vendor_id, int $attempt = 0 ): string {
        $store_name = get_user_meta( $vendor_id, 'ltms_store_name', true ) ?: '';
        $prefix     = strtoupper( substr( preg_replace( '/[^a-zA-Z0-9]/', '', $store_name ), 0, 3 ) );

        if ( strlen( $prefix ) < 2 ) {
            $prefix = 'LT' . $prefix;
        }

        $suffix = strtoupper( substr( base_convert( (string) ( $vendor_id + $attempt + time() ), 10, 36 ), 0, 5 ) );

        return substr( $prefix . $suffix, 0, self::CODE_LENGTH );
    }

    /**
     * Verifica si un código ya existe.
     *
     * @param  string $code
     * @return bool
     */
    private function code_exists( string $code ): bool {
        global $wpdb;
        $result = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'ltms_referral_code' AND meta_value = %s",
            $code
        ) );
        return (int) $result > 0;
    }

    // ── Sponsor Linking ────────────────────────────────────────────

    /**
     * Vincula un vendedor nuevo a su sponsor usando el código de referido.
     *
     * @param  int    $vendor_id
     * @param  string $referral_code
     * @return bool
     */
    public function link_to_sponsor( int $vendor_id, string $referral_code ): bool {
        $sponsor_id = $this->get_vendor_by_code( $referral_code );

        if ( ! $sponsor_id ) {
            $this->log_warning( 'Invalid referral code during registration', [
                'vendor_id' => $vendor_id,
                'code'      => $referral_code,
            ] );
            LTMS_Referral_Tree::register_node( $vendor_id, '' );
            return false;
        }

        update_user_meta( $vendor_id, 'ltms_sponsor_id', $sponsor_id );
        LTMS_Referral_Tree::register_node( $vendor_id, $referral_code );

        $this->log_info( 'Vendor linked to sponsor', [
            'vendor_id'  => $vendor_id,
            'sponsor_id' => $sponsor_id,
        ] );

        return true;
    }

    /**
     * Obtiene un vendedor por su código de referido.
     *
     * @param  string $code
     * @return int|null
     */
    public function get_vendor_by_code( string $code ): ?int {
        $users = get_users( [
            'meta_key'   => 'ltms_referral_code',
            'meta_value' => strtoupper( $code ),
            'number'     => 1,
            'fields'     => 'ID',
        ] );

        return ! empty( $users ) ? (int) $users[0] : null;
    }

    // ── Statistics ──────────────────────────────────────────────────

    /**
     * Obtiene estadísticas de la red de un vendedor.
     *
     * @param  int   $vendor_id
     * @return array{referral_code: string, total_referrals: int, active_referrals: int, total_earned: float, tree: array}
     */
    public function get_stats( int $vendor_id ): array {
        global $wpdb;

        $referral_code = get_user_meta( $vendor_id, 'ltms_referral_code', true ) ?: '';
        $network_stats = LTMS_Referral_Tree::get_network_stats( $vendor_id );

        // Referidos activos (con al menos 1 orden en los últimos 30 días)
        $active = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT rn.vendor_id)
             FROM {$wpdb->prefix}lt_referral_network rn
             INNER JOIN {$wpdb->prefix}lt_commissions c ON c.vendor_id = rn.vendor_id
             WHERE rn.sponsor_id = %d
               AND c.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            $vendor_id
        ) );

        return [
            'referral_code'   => $referral_code,
            'total_referrals' => $network_stats['total_referrals'],
            'active_referrals' => $active,
            'total_earned'    => $network_stats['total_earned'],
            'tree'            => LTMS_Referral_Tree::get_descendant_tree( $vendor_id, 3 ),
        ];
    }

    /**
     * Obtiene historial de comisiones de afiliado.
     *
     * @param  int $vendor_id
     * @param  int $limit
     * @return array
     */
    public function get_commission_history( int $vendor_id, int $limit = 20 ): array {
        global $wpdb;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT c.*, u.display_name AS source_vendor_name
             FROM {$wpdb->prefix}lt_commissions c
             LEFT JOIN {$wpdb->users} u ON u.ID = c.vendor_id
             WHERE c.type = 'referral'
               AND c.notes LIKE %s
             ORDER BY c.created_at DESC
             LIMIT %d",
            '%vendor_id:' . $vendor_id . '%',
            $limit
        ), ARRAY_A );
    }

    // ── Leaderboard ────────────────────────────────────────────────

    /**
     * Obtiene el leaderboard de afiliados por comisiones del mes.
     *
     * @param  int $limit
     * @return array
     */
    public function get_leaderboard( int $limit = 10 ): array {
        global $wpdb;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT
                rn.sponsor_id AS vendor_id,
                u.display_name,
                COUNT(DISTINCT rn.vendor_id) AS total_referrals,
                COALESCE(SUM(c.vendor_net), 0) AS monthly_referral_income
             FROM {$wpdb->prefix}lt_referral_network rn
             INNER JOIN {$wpdb->users} u ON u.ID = rn.sponsor_id
             LEFT JOIN {$wpdb->prefix}lt_commissions c
                ON c.type = 'referral'
               AND c.notes LIKE CONCAT('%vendor_id:', rn.sponsor_id, '%')
               AND c.created_at >= DATE_FORMAT(NOW(), '%%Y-%%m-01')
             GROUP BY rn.sponsor_id, u.display_name
             ORDER BY monthly_referral_income DESC
             LIMIT %d",
            $limit
        ), ARRAY_A );
    }

    // ── REST API ────────────────────────────────────────────────────

    /**
     * Registra rutas REST para la red de afiliados.
     */
    public function register_rest_routes(): void {
        register_rest_route( 'ltms/v1', '/affiliates/stats', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'rest_get_stats' ],
            'permission_callback' => fn() => is_user_logged_in() && ltms_is_vendor(),
        ] );

        register_rest_route( 'ltms/v1', '/affiliates/leaderboard', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'rest_get_leaderboard' ],
            'permission_callback' => fn() => is_user_logged_in() && ltms_is_vendor(),
        ] );
    }

    /**
     * REST: Estadísticas de afiliados del vendedor actual.
     *
     * @param  \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function rest_get_stats( \WP_REST_Request $request ): \WP_REST_Response {
        $vendor_id = get_current_user_id();
        return rest_ensure_response( $this->get_stats( $vendor_id ) );
    }

    /**
     * REST: Leaderboard global.
     *
     * @param  \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function rest_get_leaderboard( \WP_REST_Request $request ): \WP_REST_Response {
        return rest_ensure_response( $this->get_leaderboard() );
    }
}
