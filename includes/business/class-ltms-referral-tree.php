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
     * Punto de entrada del Kernel. Registra hooks de WooCommerce para la red de referidos.
     *
     * @return void
     */
    public static function init(): void {}

    /**
     * Tasas de comisión por nivel (porcentaje de la comisión de plataforma).
     * Configurable vía ltms_referral_rates (JSON array).
     *
     * Default: Nivel 1 = 40%, Nivel 2 = 20%, Nivel 3 = 10%
     *
     * LG-3 FIX (v2.9.7): estas tasas son del programa MLM INTERNO de Lo Tengo
     * (distribución del commission_fee entre patrocinadores).
     * NO son las tasas TPTC del contrato (Cláusula Décima Sexta).
     * TPTC: N1=0.75%, N2=1.5%, N3=0.5% del total de compra → LTMS_TPTC_Listener.
     * TPTC es operado por TPTC S.A.S. (entidad independiente).
     */
    const DEFAULT_RATES = [ 0.40, 0.20, 0.10 ];

    /**
     * Registra un nuevo nodo en la red de referidos.
     *
     * RT-2 FIX (AUDIT-BATCH2): NO existían validaciones de integridad del árbol.
     * Tres bugs corregidos:
     *
     *  1. AUTO-REFERENCIA: si $referral_code resolvía al mismo $vendor_id
     *     (re-registro, bug en Affiliates::link_to_sponsor), el nodo se insertaba
     *     con sponsor_id=$vendor_id → ancestor_path='5/5' → ciclo infinito en
     *     get_sponsor_chain() y distribute_commissions() (auto-comisión).
     *     Ahora se valida $sponsor_id !== $vendor_id.
     *
     *  2. NODO DUPLICADO: si register_node se llamaba dos veces para el mismo
     *     vendor_id (re-fire de hook, doble webhook), se insertaban DOS filas
     *     con el mismo vendor_id → get_sponsor_chain devolvía resultados
     *     inconsistentes (ORDER implícito). Ahora se verifica si ya existe fila.
     *
     *  3. CICLO CIRCULAR: si el sponsor_id es descendiente del vendor_id (p.ej.
     *     tras re-parenting manual por admin), build_ancestor_path incluiría al
     *     vendor_id en el path del sponsor, creando un ciclo. Ahora se verifica
     *     que $vendor_id NO esté en el ancestor_path del sponsor.
     *
     * @param int    $vendor_id   ID del nuevo vendedor.
     * @param string $referral_code Código del patrocinador.
     * @return bool True si se registró exitosamente.
     */
    public static function register_node( int $vendor_id, string $referral_code ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_referral_network';

        // RT-2.2: Idempotencia — si ya existe una fila para este vendor_id,
        // no insertar duplicado. Devolver true (operación idempotente).
        $existing = (int) $wpdb->get_var( // phpcs:ignore
            $wpdb->prepare( "SELECT id FROM `{$table}` WHERE vendor_id = %d LIMIT 1", $vendor_id )
        );
        if ( $existing > 0 ) {
            return true;
        }

        $sponsor_id = self::get_vendor_by_code( $referral_code );
        if ( ! $sponsor_id ) {
            return false;
        }

        // RT-2.1: Auto-referencia — bloquear.
        if ( $sponsor_id === $vendor_id ) {
            LTMS_Core_Logger::warning(
                'REFERRAL_SELF_REFERENCE_BLOCKED',
                sprintf( 'Vendedor #%d intentó auto-referenciarse (sponsor_id === vendor_id) — registro rechazado', $vendor_id ),
                [ 'vendor_id' => $vendor_id, 'referral_code' => $referral_code ]
            );
            return false;
        }

        // RT-2.3: Ciclo circular — si $vendor_id aparece en el ancestor_path
        // del sponsor, entonces el sponsor es descendiente del vendor → crear
        // el vínculo generaría un ciclo. Bloquear.
        $sponsor_path = (string) $wpdb->get_var( // phpcs:ignore
            $wpdb->prepare( "SELECT ancestor_path FROM `{$table}` WHERE vendor_id = %d LIMIT 1", $sponsor_id )
        );
        if ( $sponsor_path !== '' && $sponsor_path !== null ) {
            // Normalizamos con delimiters para evitar el bug RT-3 (substring match):
            // vendor_id=5 NO debe coincidir con "15" en el path "15/23".
            $path_parts = array_map( 'intval', explode( '/', $sponsor_path ) );
            if ( in_array( $vendor_id, $path_parts, true ) ) {
                LTMS_Core_Logger::critical(
                    'REFERRAL_CIRCULAR_BLOCKED',
                    sprintf( 'Vendedor #%d es ancestro de #%d (path="%s") — vínculo crearía ciclo circular', $vendor_id, $sponsor_id, $sponsor_path ),
                    [ 'vendor_id' => $vendor_id, 'sponsor_id' => $sponsor_id, 'sponsor_path' => $sponsor_path ]
                );
                return false;
            }
        }

        // Calcular nivel en la red
        $sponsor_level = (int) $wpdb->get_var( // phpcs:ignore
            $wpdb->prepare( "SELECT level FROM `{$table}` WHERE vendor_id = %d", $sponsor_id )
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
     * RT-1 FIX (AUDIT-BATCH2): NO existía validación de suma de tasas — el
     * admin puede configurar `ltms_referral_rates` con cada rate en [0,1]
     * (validado por Admin_Settings), pero la SUMA puede exceder 1.0.
     * Ej: [0.5, 0.5, 0.5] → 150% del platform_fee pagado en comisiones MLM
     * → la plataforma PAGA MÁS de lo que recibe en platform_fee (infinite
     * money bug para afiliados). Casos reales de mal configuración:
     *   - admin ingresa 5/3/2 (%es) creyendo que son decimales → rates=[5,3,2]
     *   - admin copia `[0.4,0.3,0.4]` (typo) → suma=1.1
     *   - admin malicioso con acceso DB escribe `[2.0]` → 200% payout
     *
     * FIX: si `array_sum($rates) > 1.0`, se normalizan proporcionalmente para
     * que la suma sea exactamente 1.0 (cap del 100% del platform_fee). Se loguea
     * como CRITICAL para que el admin corrija la configuración.
     *
     * Adicionalmente, por seguridad defensiva, el total distribuido se cap a
     * $platform_fee — si por floating point el total excede ligeramente, se
     * omite el último crédito que haría pasar el cap.
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

        // RT-1: Validar suma de tasas — cap a 1.0 (100% del platform_fee).
        $rates_sum = array_sum( $rates );
        if ( $rates_sum > 1.0 ) {
            LTMS_Core_Logger::critical(
                'REFERRAL_RATES_SUM_EXCEEDS_100',
                sprintf(
                    'Configuración MLM inválida: suma de tasas = %.4f (>1.0). Normalizando proporcionalmente para evitar pago >100%% del platform_fee. Rates originales: %s',
                    $rates_sum,
                    wp_json_encode( $rates )
                ),
                [ 'original_rates' => $rates, 'sum' => $rates_sum, 'order_id' => $order_id ]
            );
            // Normalización proporcional: cada rate se escala por 1/sum.
            $rates = array_map( static fn( $r ) => $r / $rates_sum, $rates );
        }

        // RT-1 defensivo: también validar cada rate individual (no negativo).
        $rates = array_map( static fn( $r ) => max( 0.0, (float) $r ), $rates );

        $chain = self::get_sponsor_chain( $vendor_id );
        if ( empty( $chain ) ) {
            return;
        }

        $max_levels  = count( $rates );
        $distributed = 0.0;
        // RT-1: cap duro — total distribuido NUNCA puede exceder $platform_fee.
        $hard_cap    = max( 0.0, $platform_fee );

        foreach ( $chain as $index => $sponsor_id ) {
            if ( $index >= $max_levels ) {
                break;
            }

            // RT-1 defensivo: nunca pagarle a uno mismo (auto-comisión).
            if ( $sponsor_id === $vendor_id ) {
                LTMS_Core_Logger::warning(
                    'REFERRAL_SELF_COMMISSION_SKIPPED',
                    sprintf( 'Pedido #%d: sponsor en nivel %d es el propio vendor #%d — crédito omitido', $order_id, $index + 1, $vendor_id ),
                    [ 'order_id' => $order_id, 'vendor_id' => $vendor_id, 'level' => $index + 1 ]
                );
                continue;
            }

            $rate       = $rates[ $index ];
            $commission = round( $platform_fee * $rate, 2 );

            if ( $commission <= 0 ) {
                continue;
            }

            // RT-1: si este crédito haría que el total distribuido exceda el cap
            // del platform_fee, recortar la comisión al remanente disponible.
            if ( ( $distributed + $commission ) > $hard_cap ) {
                $commission = round( $hard_cap - $distributed, 2 );
                if ( $commission <= 0 ) {
                    break; // Cap alcanzado — no hay más fondos que distribuir.
                }
            }

            try {
                // M-102: firma correcta = credit(vendor_id, amount, description:string, metadata:array, order_id:int)
                //
                // OS-3 FIX (AUDIT-OS) CRÍTICO: idempotency key por (order, source_vendor, level).
                // Antes, NO se pasaba idempotency_key → si Order_Split::process() se llamaba
                // dos veces (race condition, webhook double-fire, order re-save tras crash),
                // distribute_commissions() re-acreditaba a CADA sponsor en CADA nivel →
                // doble (o triple) distribución de comisiones de referido. El idempotency
                // check del Wallet (WL-CRASH-2) no tenía key que buscar → aplicaba el crédito.
                //
                // Key: `referral_o{order}_sv{source_vendor}_l{level}` — único por
                // (pedido, vendedor que generó la venta, nivel en la red). Si process()
                // se re-invoca, el key colisiona y el crédito se skip.
                $referral_idem_key = sprintf( 'referral_o%d_sv%d_l%d', $order_id, $vendor_id, $index + 1 );

                LTMS_Business_Wallet::credit(
                    $sponsor_id,
                    $commission,
                    sprintf(
                        /* translators: %1$d: nivel, %2$d: ID vendedor referido, %3$d: pedido */
                        __( 'Comisión referido Nivel %1$d - Vendedor #%2$d - Pedido #%3$d', 'ltms' ),
                        $index + 1,
                        $vendor_id,
                        $order_id
                    ),
                    [
                        'type'             => 'referral',
                        'source_vendor_id' => $vendor_id,
                        'order_id'         => $order_id,
                        'referral_level'   => $index + 1,
                        'rate'             => $rate,
                    ],
                    $order_id,
                    '', // currency — usa default config (las comisiones de referido se acreditan en la moneda base)
                    $referral_idem_key
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
                    'Distribuidos %s en comisiones de referido para pedido #%d (%d niveles) — platform_fee=%.2f, cap=%.2f',
                    LTMS_Utils::format_money( $distributed ),
                    $order_id,
                    min( count( $chain ), $max_levels ),
                    $platform_fee,
                    $hard_cap
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
     * RT-3 FIX (AUDIT-BATCH2): El query original usaba
     *   `WHERE ancestor_path LIKE '%{$vendor_id}%'`
     * que hace SUBSTRING matching — para vendor_id=5 coincide con cualquier
     * path que contenga "5" como substring: "5", "15", "25", "55", "5/12",
     * "12/5", "12/5/23", "12/55", etc. Esto infla falsamente el conteo de
     * referidos para cualquier vendor_id cuyo número sea substring de otro.
     * Ej: vendor 1 tiene 1000+ descendientes falsos porque casi todos los IDs
     * contienen el dígito "1".
     *
     * FIX: delimitar el LIKE con '/' en ambos extremos.ancestor_path usa '/'
     * como separador, así que vendor_id=5 aparece en el path como:
     *   - "5" (único elemento, raíz)
     *   - "5/..." (al inicio)
     *   - ".../5" (al final)
     *   - ".../5/..." (en medio)
     * El truco `CONCAT('/', ancestor_path, '/') LIKE '%/5/%'` cubre los 4 casos
     * en una sola comparación delimitada.
     *
     * @param int $vendor_id ID del vendedor.
     * @return array{total_referrals: int, active_referrals: int, total_earned: float, levels: array}
     */
    public static function get_network_stats( int $vendor_id ): array {
        global $wpdb;
        $ref_table   = $wpdb->prefix . 'lt_referral_network';
        $wallet_table = $wpdb->prefix . 'lt_wallet_transactions';

        // RT-3: Total de referidos en toda la red (cualquier nivel).
        // LIKE delimitado por '/' evita substring false-positives.
        $like_pattern = '%/' . $wpdb->esc_like( (string) $vendor_id ) . '/%';
        $total = (int) $wpdb->get_var( // phpcs:ignore
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$ref_table}` WHERE CONCAT('/', ancestor_path, '/') LIKE %s", // phpcs:ignore
                $like_pattern
            )
        );

        // Total ganado en comisiones de referido.
        // RT-3.1 FIX (AUDIT-BATCH2): el query original usaba `type = 'referral'`
        // PERO la columna `type` de lt_wallet_transactions es un ENUM que NO
        // incluye 'referral' (solo credit/debit/hold/release/reversal/...).
        // Referral commissions se guardan como type='credit' con
        // metadata->>'type'='referral' (ver distribute_commissions()). El query
        // original SIEMPRE devolvía 0 → `total_earned` era siempre 0 para todos
        // los vendedores. Ahora usamos el mismo patrón que Affiliates::get_commission_history
        // (argumento con % literales — no usar %% que es para el query string, no args).
        $earned = (float) $wpdb->get_var( // phpcs:ignore
            $wpdb->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM `{$wallet_table}`
                 WHERE vendor_id = %d AND type = 'credit'
                   AND metadata LIKE %s", // phpcs:ignore
                $vendor_id,
                '%%"type":"referral"%%' // patrón LIKE: %"type":"referral"%
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
        // M-7: los códigos se almacenan en uppercase (LTMS_Affiliates::generate_unique_code
        // hace strtoupper). Normalizar aquí para evitar fallar el lookup si el caller
        // envía el código en minúsculas.
        $users = get_users([
            'meta_key'   => 'ltms_referral_code',
            'meta_value' => strtoupper( sanitize_text_field( $code ) ),
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
                $rates = array_map( 'floatval', $decoded );
            }
        }

        if ( empty( $rates ) ) {
            $rates = self::DEFAULT_RATES;
        }

        // M-02 FIX: respetar ltms_mlm_levels — truncar el array al número de niveles configurados.
        // Si el admin configuró "2 niveles", no pagar nivel 3 aunque esté en el array.
        $max_levels = (int) LTMS_Core_Config::get( 'ltms_mlm_levels', count( $rates ) );
        if ( $max_levels > 0 ) {
            $rates = array_slice( $rates, 0, $max_levels );
        }

        return $rates;
    }
}
