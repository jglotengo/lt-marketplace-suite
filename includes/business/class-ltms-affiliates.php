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
        add_action( 'ltms_payout_completed',  [ $instance, 'on_payout_completed' ],  10, 3 );

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

        // Sincronizar con TPTC (M-104: verificar que el driver esté registrado antes de intentar)
        if ( LTMS_Core_Config::get( 'ltms_tptc_enabled', 'no' ) === 'yes'
             && class_exists( 'LTMS_Api_Factory' )
             && LTMS_Api_Factory::has( 'tptc' ) ) {
            try {
                $tptc = LTMS_Api_Factory::get( 'tptc' );
                $tptc->register_affiliate( $vendor_id, $code, $referral_code );
            } catch ( \Throwable $e ) {
                $this->log_warning( 'tptc_sync_failed', 'TPTC affiliate sync failed: ' . $e->getMessage(), [ 'vendor_id' => $vendor_id ] );
            }
        }
    }

    /**
     * Procesa pago completado para distribuir comisiones a la cadena.
     *
     * @param int   $vendor_id  ID del vendedor que recibió el pago.
     * @param float $net_amount Monto neto del pago.
     * @param int   $payout_id  ID del payout (FU2 fix, 0 si no disponible).
     */
    public function on_payout_completed( int $vendor_id, float $net_amount, int $payout_id = 0 ): void {
        // Solo registrar el evento; las comisiones MLM se distribuyen en order_split.
        $this->log_info( 'payout_completed', 'Payout completed for affiliate chain', [
            'vendor_id'  => $vendor_id,
            'net_amount' => $net_amount,
            'payout_id'  => $payout_id,
        ] );
    }

    // ── Code Generation ────────────────────────────────────────────

    /**
     * Genera un código de referido único.
     *
     * AF-1 FIX (AUDIT-BATCH2): Si tras 10 intentos el código sigue existiendo
     * (entropía débil de generate_code() basada en time()+vendor_id), el código
     * original RETORNABA el último duplicado — causando que dos vendedores
     * terminaran con el mismo `ltms_referral_code`. Esto rompía `get_vendor_by_code()`
     * (que retorna el primer match) → comisiones MLM se acreditarían al primero
     * encontrado, no al sponsor real. Ahora, si persiste la colisión, se
     * anexa un suffix aleatorio de 4 chars garantizando unicidad.
     *
     * @param  int    $vendor_id
     * @return string
     */
    public function generate_unique_code( int $vendor_id ): string {
        $attempts = 0;
        $code     = '';

        do {
            $code = $this->generate_code( $vendor_id, $attempts );
            $attempts++;
        } while ( $this->code_exists( $code ) && $attempts < 10 );

        // AF-1: Si tras 10 intentos sigue duplicado, forzar unicidad con suffix.
        if ( $this->code_exists( $code ) ) {
            $extra = strtoupper( substr( wp_generate_password( 8, false ), 0, 4 ) );
            $code  = substr( $code, 0, self::CODE_LENGTH - 4 ) . $extra;
            // Última verificación — si por casualidad sigue duplicado, append
            // de vendor_id (siempre único) hasta llenar CODE_LENGTH.
            $suffix_iter = 0;
            while ( $this->code_exists( $code ) && $suffix_iter < 20 ) {
                $suffix_iter++;
                $extra = strtoupper( substr( wp_generate_password( 12, false ), 0, 4 ) );
                $code  = substr( $code, 0, self::CODE_LENGTH - 4 ) . $extra;
            }
            $this->log_warning(
                'referral_code_collision_recovered',
                sprintf( 'Colisión de código de referido tras 10 intentos para vendor #%d — suffix aleatorio aplicado: %s', $vendor_id, $code ),
                [ 'vendor_id' => $vendor_id, 'final_code' => $code ]
            );
        }

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
     * AF-2 FIX (AUDIT-BATCH2): NO existía validación de auto-referencia. Un
     * usuario con cuenta A (y código X) podía crear cuenta B usando X como
     * referral_code → su sponsor_id sería el mismo usuario A. Pero lo más
     * grave: si un usuario obtenía su PROPIO código (p.ej. tras re-registrarse
     * con el mismo email tras borrar la cuenta, o por bug de flushing de
     * user_meta), `link_to_sponsor($uid, $uid_code)` lo vinculaba a sí mismo,
     * creando un ciclo en lt_referral_network donde ancestor_path contiene al
     * propio vendor_id → `get_sponsor_chain()` retorna al vendor como su propio
     * sponsor → `distribute_commissions()` le paga comisión a sí mismo por sus
     * propias ventas (auto-comisión fraudulenta).
     *
     * Ahora se valida explícitamente que `$sponsor_id !== $vendor_id`. Si coincide,
     * se rechaza, se loguea como warning (posible fraude/bug) y se registra el
     * nodo sin sponsor (raíz).
     *
     * @param  int    $vendor_id
     * @param  string $referral_code
     * @return bool
     */
    public function link_to_sponsor( int $vendor_id, string $referral_code ): bool {
        $sponsor_id = $this->get_vendor_by_code( $referral_code );

        if ( ! $sponsor_id ) {
            $this->log_warning( 'invalid_referral_code', 'Invalid referral code during registration', [
                'vendor_id' => $vendor_id,
                'code'      => $referral_code,
            ] );
            LTMS_Referral_Tree::register_node( $vendor_id, '' );
            return false;
        }

        // AF-2: Auto-referencia — rechazar.
        if ( $sponsor_id === $vendor_id ) {
            $this->log_warning(
                'self_referral_blocked',
                sprintf( 'Vendedor #%d intentó usar su propio código de referido — auto-referencia bloqueada', $vendor_id ),
                [ 'vendor_id' => $vendor_id, 'referral_code' => $referral_code ]
            );
            // Registrar como nodo raíz (sin sponsor) para no bloquear el onboarding.
            LTMS_Referral_Tree::register_node( $vendor_id, '' );
            return false;
        }

        update_user_meta( $vendor_id, 'ltms_sponsor_id', $sponsor_id );
        $registered = LTMS_Referral_Tree::register_node( $vendor_id, $referral_code );

        // AF-2: Si register_node detectó ciclo y rechazó, revertir el meta.
        if ( ! $registered ) {
            delete_user_meta( $vendor_id, 'ltms_sponsor_id' );
            return false;
        }

        $this->log_info( 'vendor_linked', 'Vendor linked to sponsor', [
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

        // M-105: las comisiones de referido se almacenan en lt_wallet_transactions
        // (acreditadas por Wallet::credit() con type=referral en metadata), NO en lt_commissions
        // v2.9.122 AFFILIATE-AUDIT P1-3 FIX: use exact JSON match, not LIKE substring.
        // Before, LIKE '%"type":"referral"%' also matched '%"type":"referral_commission"%'
        // → mixed commission types returned. Now uses JSON_EXTRACT for exact match.
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT
                wt.id,
                wt.amount,
                wt.description,
                wt.metadata,
                wt.created_at,
                u.display_name AS source_vendor_name
             FROM {$wpdb->prefix}lt_wallet_transactions wt
             LEFT JOIN {$wpdb->users} u ON u.ID = CAST(
                 JSON_UNQUOTE(JSON_EXTRACT(wt.metadata, '$.source_vendor_id')) AS UNSIGNED
             )
             WHERE wt.vendor_id = %d
               AND wt.type = 'credit'
               AND JSON_UNQUOTE(JSON_EXTRACT(wt.metadata, '$.type')) = 'referral'
             ORDER BY wt.created_at DESC
             LIMIT %d",
            $vendor_id,
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

        // M-105: usar lt_wallet_transactions para comisiones de referido
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT
                rn.sponsor_id AS vendor_id,
                u.display_name,
                COUNT(DISTINCT rn.vendor_id) AS total_referrals,
                COALESCE(SUM(wt.amount), 0) AS monthly_referral_income
             FROM {$wpdb->prefix}lt_referral_network rn
             INNER JOIN {$wpdb->users} u ON u.ID = rn.sponsor_id
             LEFT JOIN {$wpdb->prefix}lt_wallet_transactions wt
                ON wt.vendor_id = rn.sponsor_id
               AND wt.type = 'credit'
               AND wt.metadata LIKE '%%\"type\":\"referral\"%%'
               AND wt.created_at >= DATE_FORMAT(NOW(), '%%Y-%%m-01')
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

        // v2.9.122 AFFILIATE-AUDIT P1-2 FIX: restrict leaderboard to admins only.
        // Before, any vendor could see the leaderboard exposing all vendors'
        // referral income — PII/financial leak. Now requires manage_options.
        register_rest_route( 'ltms/v1', '/affiliates/leaderboard', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'rest_get_leaderboard' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
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
