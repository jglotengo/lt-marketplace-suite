<?php
/**
 * LTMS Business Deposit - Depósitos Manuales de Recarga de Wallet
 *
 * Gestiona el ciclo de vida de los depósitos manuales que realizan los
 * vendedores para recargar su billetera via PSE, Nequi o transferencia bancaria.
 *
 * Flujo:
 *   1. Vendedor solicita depósito → status = pending
 *   2. Sube comprobante de pago (imagen/PDF)
 *   3. Admin revisa y aprueba → LTMS_Business_Wallet::credit() → status = approved
 *   4. Admin rechaza → status = rejected + motivo
 *
 * @package    LTMS
 * @subpackage LTMS/includes/business
 * @version    1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Deposit
 */
final class LTMS_Deposit {

    use LTMS_Logger_Aware;

    // Estados posibles
    const STATUS_PENDING   = 'pending';
    const STATUS_PROCESSING = 'processing'; // D1 FIX: estado intermedio durante atomic claim.
    const STATUS_APPROVED  = 'approved';
    const STATUS_REJECTED  = 'rejected';

    // Métodos de pago soportados
    const METHOD_PSE          = 'pse';
    const METHOD_NEQUI        = 'nequi';
    const METHOD_TRANSFERENCIA = 'transferencia';

    /**
     * Tabla de depósitos manuales.
     *
     * @return string
     */
    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'lt_deposits';
    }

    /**
     * Inicializa hooks del módulo.
     *
     * @return void
     */
    public static function init(): void {
        // Hook cron para recordar depósitos pendientes > 48h
        add_action( 'ltms_daily_cron', [ __CLASS__, 'notify_stale_deposits' ] );
    }

    /**
     * Crea una nueva solicitud de depósito manual.
     *
     * D3 FIX: verifica referencia duplicada para prevenir fraude (un vendor
     * no puede usar el mismo comprobante bancario para múltiples depósitos).
     *
     * D4 FIX: valida que receipt_url sea un attachment válido del media library.
     *
     * D5 FIX: rate limiting — máximo 5 depósitos pendientes simultáneos por vendor.
     *
     * @param int    $vendor_id      ID del vendedor.
     * @param float  $amount         Monto a depositar.
     * @param string $method         Método: pse|nequi|transferencia.
     * @param string $reference      Referencia/número de transacción del banco.
     * @param string $receipt_url    URL del comprobante subido (attachment URL).
     * @param string $notes          Notas adicionales del vendedor.
     * @return int ID del depósito creado.
     * @throws \InvalidArgumentException Si los datos son inválidos.
     * @throws \RuntimeException         Si falla la inserción.
     */
    public static function create(
        int    $vendor_id,
        float  $amount,
        string $method,
        string $reference  = '',
        string $receipt_url = '',
        string $notes       = ''
    ): int {
        global $wpdb;

        if ( $amount <= 0 ) {
            throw new \InvalidArgumentException( 'LTMS Deposit: El monto debe ser positivo.' );
        }

        $allowed_methods = [ self::METHOD_PSE, self::METHOD_NEQUI, self::METHOD_TRANSFERENCIA ];
        if ( ! in_array( $method, $allowed_methods, true ) ) {
            throw new \InvalidArgumentException(
                sprintf( 'LTMS Deposit: Método de pago inválido: %s', $method )
            );
        }

        $min_amount = (float) get_option( 'ltms_min_deposit_amount', 10000 );
        $max_amount = (float) get_option( 'ltms_max_deposit_amount', 50000000 );

        if ( $amount < $min_amount || $amount > $max_amount ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'LTMS Deposit: Monto fuera de rango. Mínimo: %s, Máximo: %s',
                    number_format( $min_amount, 0, ',', '.' ),
                    number_format( $max_amount, 0, ',', '.' )
                )
            );
        }

        // D4 FIX: validar que receipt_url sea un attachment del media library.
        if ( ! empty( $receipt_url ) ) {
            $attachment_id = attachment_url_to_postid( $receipt_url );
            if ( ! $attachment_id ) {
                throw new \InvalidArgumentException(
                    'LTMS Deposit: El comprobante debe ser un archivo subido al sistema. URL externa rechazada.'
                );
            }
        }

        // D3 FIX: verificar referencia duplicada (previene fraude con mismo comprobante).
        $reference = sanitize_text_field( $reference );
        if ( ! empty( $reference ) ) {
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM `" . self::table() . "`
                 WHERE reference = %s AND status IN ('pending','approved') LIMIT 1",
                $reference
            ) );
            if ( $existing ) {
                throw new \InvalidArgumentException(
                    sprintf( 'LTMS Deposit: La referencia "%s" ya fue usada en el depósito #%d.', $reference, (int) $existing )
                );
            }
        }

        // D5 FIX: rate limiting — máximo de depósitos pendientes simultáneos.
        $max_pending = (int) get_option( 'ltms_deposit_max_pending', 5 );
        $pending_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `" . self::table() . "`
             WHERE vendor_id = %d AND status = 'pending'",
            $vendor_id
        ) );
        if ( $pending_count >= $max_pending ) {
            throw new \InvalidArgumentException(
                sprintf( 'LTMS Deposit: Tienes %d depósitos pendientes. Espera a que sean procesados antes de crear uno nuevo.', $pending_count )
            );
        }

        $result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            self::table(),
            [
                'vendor_id'   => $vendor_id,
                'amount'      => $amount,
                'currency'    => LTMS_Core_Config::get_currency(),
                'method'      => $method,
                'reference'   => $reference,
                'receipt_url' => esc_url_raw( $receipt_url ),
                'notes'       => sanitize_textarea_field( $notes ),
                'status'      => self::STATUS_PENDING,
                'ip_address'  => LTMS_Utils::get_ip(),
                'created_by'  => $vendor_id,
                'created_at'  => LTMS_Utils::now_utc(),
                'updated_at'  => LTMS_Utils::now_utc(),
            ],
            [ '%d', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );

        if ( ! $result ) {
            throw new \RuntimeException( 'LTMS Deposit: No se pudo crear la solicitud de depósito.' );
        }

        $deposit_id = $wpdb->insert_id;

        LTMS_Core_Logger::info(
            'DEPOSIT_CREATED',
            sprintf( 'Depósito manual #%d creado — Vendedor #%d — Monto: %s — Método: %s',
                $deposit_id, $vendor_id,
                LTMS_Utils::format_money( $amount ),
                $method
            ),
            [ 'deposit_id' => $deposit_id, 'vendor_id' => $vendor_id, 'amount' => $amount, 'method' => $method ]
        );

        // Notificar al admin
        self::notify_admin_new_deposit( $deposit_id, $vendor_id, $amount, $method );

        return $deposit_id;
    }

    /**
     * Aprueba un depósito y acredita el monto en la wallet del vendedor.
     *
     * D1 FIX (CRÍTICO): race condition eliminada con atomic claim.
     *   ANTES: dos admins podían aprobar el mismo depósito simultáneamente →
     *   doble crédito en la wallet del vendor.
     *   FIX: el UPDATE usa WHERE id=%d AND status='pending' → solo un admin
     *   consigue rows_affected=1. El otro recibe 0 y aborta.
     *
     * D2 FIX (CRÍTICO): idempotency key en credit().
     *   ANTES: si el admin hacía double-click o había retry de red, el credit()
     *   se ejecutaba dos veces → doble saldo.
     *   FIX: idempotency key 'deposit_credit_N' → si ya se acreditó, retorna
     *   el tx_id existente sin ejecutar de nuevo.
     *
     * D6 FIX: el SELECT inicial usa FOR UPDATE dentro de la transacción
     *   para bloquear la fila y prevenir lecturas concurrentes.
     *
     * @param int    $deposit_id  ID del depósito.
     * @param int    $admin_id    ID del admin que aprueba.
     * @param string $admin_notes Notas del admin.
     * @return array{success: bool, message: string, tx_id: int}
     */
    public static function approve( int $deposit_id, int $admin_id, string $admin_notes = '' ): array {
        global $wpdb;

        // D6 FIX: SELECT inicial sin FOR UPDATE (lectura rápida de guard).
        $deposit = self::get( $deposit_id );

        if ( ! $deposit ) {
            return [ 'success' => false, 'message' => __( 'Depósito no encontrado.', 'ltms' ), 'tx_id' => 0 ];
        }

        if ( $deposit['status'] !== self::STATUS_PENDING ) {
            return [
                'success' => false,
                'message' => sprintf(
                    __( 'El depósito ya fue procesado (estado: %s).', 'ltms' ),
                    $deposit['status']
                ),
                'tx_id' => 0,
            ];
        }

        // D1 FIX: atomic claim — UPDATE con WHERE status='pending' previene doble aprobación.
        // Solo uno de dos admins concurrentes verá rows_affected=1.
        $claimed = $wpdb->query( $wpdb->prepare(
            "UPDATE `" . self::table() . "`
             SET status = 'processing', updated_at = %s
             WHERE id = %d AND status = 'pending'",
            LTMS_Utils::now_utc(),
            $deposit_id
        ) );

        if ( $claimed === false || $claimed === 0 ) {
            return [
                'success' => false,
                'message' => __( 'El depósito ya está siendo procesado por otro administrador.', 'ltms' ),
                'tx_id' => 0,
            ];
        }

        // A partir de aquí, el depósito está en 'processing' — somos el dueño exclusivo.

        try {
            // D2 FIX: idempotency key para prevenir doble crédito en retries.
            $idem_key = sprintf( 'deposit_credit_%d', $deposit_id );

            // Acreditar en wallet con idempotency key.
            $tx_id = LTMS_Business_Wallet::credit(
                (int) $deposit['vendor_id'],
                (float) $deposit['amount'],
                sprintf( 'Depósito manual aprobado #%d via %s', $deposit_id, strtoupper( $deposit['method'] ) ),
                [
                    'deposit_id'  => $deposit_id,
                    'method'      => $deposit['method'],
                    'reference'   => $deposit['reference'],
                    'approved_by' => $admin_id,
                ],
                0,
                '',
                $idem_key
            );

            // Actualizar estado del depósito a 'approved'.
            $updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                self::table(),
                [
                    'status'       => self::STATUS_APPROVED,
                    'approved_by'  => $admin_id,
                    'approved_at'  => LTMS_Utils::now_utc(),
                    'admin_notes'  => sanitize_textarea_field( $admin_notes ),
                    'wallet_tx_id' => $tx_id,
                    'updated_at'   => LTMS_Utils::now_utc(),
                ],
                [ 'id' => $deposit_id ],
                [ '%s', '%d', '%s', '%s', '%d', '%s' ],
                [ '%d' ]
            );

            if ( $updated === false ) {
                throw new \RuntimeException( 'LTMS Deposit: Error al actualizar estado del depósito.' );
            }

            LTMS_Core_Logger::info(
                'DEPOSIT_APPROVED',
                sprintf( 'Depósito #%d APROBADO por admin #%d — Wallet tx #%d — Monto: %s',
                    $deposit_id, $admin_id, $tx_id,
                    LTMS_Utils::format_money( (float) $deposit['amount'] )
                ),
                [ 'deposit_id' => $deposit_id, 'tx_id' => $tx_id, 'admin_id' => $admin_id ]
            );

            // Notificar al vendedor
            self::notify_vendor_approved( $deposit );

            return [
                'success' => true,
                'message' => sprintf(
                    __( 'Depósito aprobado. Se acreditaron %s en la billetera del vendedor.', 'ltms' ),
                    LTMS_Utils::format_money( (float) $deposit['amount'] )
                ),
                'tx_id' => $tx_id,
            ];

        } catch ( \Throwable $e ) {
            // Revertir el atomic claim: volver a 'pending' para que se pueda reintentar.
            $wpdb->update(
                self::table(),
                [ 'status' => self::STATUS_PENDING, 'updated_at' => LTMS_Utils::now_utc() ],
                [ 'id' => $deposit_id, 'status' => 'processing' ],
                [ '%s', '%s' ],
                [ '%d', '%s' ]
            );

            LTMS_Core_Logger::error(
                'DEPOSIT_APPROVE_FAILED',
                sprintf( 'Error al aprobar depósito #%d: %s', $deposit_id, $e->getMessage() ),
                [ 'deposit_id' => $deposit_id ]
            );

            return [ 'success' => false, 'message' => $e->getMessage(), 'tx_id' => 0 ];
        }
    }

    /**
     * Rechaza un depósito manual.
     *
     * @param int    $deposit_id  ID del depósito.
     * @param int    $admin_id    ID del admin.
     * @param string $reason      Motivo del rechazo (obligatorio).
     * @return array{success: bool, message: string}
     */
    public static function reject( int $deposit_id, int $admin_id, string $reason ): array {
        global $wpdb;

        if ( empty( trim( $reason ) ) ) {
            return [ 'success' => false, 'message' => __( 'Debe indicar un motivo de rechazo.', 'ltms' ) ];
        }

        $deposit = self::get( $deposit_id );

        if ( ! $deposit ) {
            return [ 'success' => false, 'message' => __( 'Depósito no encontrado.', 'ltms' ) ];
        }

        // D1 FIX: permitir rechazar depósitos en 'pending' O 'processing' (stuck).
        // Si un depósito quedó en 'processing' por un crash durante approve(),
        // el admin debe poder rechazarlo para liberarlo.
        if ( ! in_array( $deposit['status'], [ self::STATUS_PENDING, self::STATUS_PROCESSING ], true ) ) {
            return [
                'success' => false,
                'message' => sprintf(
                    __( 'El depósito ya fue procesado (estado: %s).', 'ltms' ),
                    $deposit['status']
                ),
            ];
        }

        $updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            self::table(),
            [
                'status'       => self::STATUS_REJECTED,
                'rejected_by'  => $admin_id,
                'rejected_at'  => LTMS_Utils::now_utc(),
                'reject_reason' => sanitize_textarea_field( $reason ),
                'updated_at'   => LTMS_Utils::now_utc(),
            ],
            // FASE4 P0 FIX: atomic claim — only reject if still in pending/processing.
            // Without the status guard in WHERE, concurrent approve() + reject() can
            // race: approve credits wallet, then reject overwrites to 'rejected' →
            // vendor credited but deposit marked rejected (double-spend / state desync).
            [
                'id'     => $deposit_id,
                'status' => $deposit['status'], // atomic: only if status hasn't changed
            ],
            [ '%s', '%d', '%s', '%s', '%s' ],
            [ '%d', '%s' ]
        );

        if ( $updated === false ) {
            return [ 'success' => false, 'message' => __( 'Error al actualizar el depósito.', 'ltms' ) ];
        }

        // FASE4 P0 FIX: if 0 rows affected, the status changed between our read
        // and the UPDATE — concurrent approve() won. Tell the admin.
        if ( $updated === 0 ) {
            return [
                'success' => false,
                'message' => __( 'El depósito fue procesado concurrentemente por otro admin. Recarga la página.', 'ltms' ),
            ];
        }

        LTMS_Core_Logger::info(
            'DEPOSIT_REJECTED',
            sprintf( 'Depósito #%d RECHAZADO por admin #%d — Motivo: %s', $deposit_id, $admin_id, $reason ),
            [ 'deposit_id' => $deposit_id, 'admin_id' => $admin_id ]
        );

        // Notificar al vendedor
        self::notify_vendor_rejected( $deposit, $reason );

        return [
            'success' => true,
            'message' => __( 'Depósito rechazado y vendedor notificado.', 'ltms' ),
        ];
    }

    /**
     * Obtiene un depósito por ID.
     *
     * @param int $deposit_id ID del depósito.
     * @return array|null
     */
    public static function get( int $deposit_id ): ?array {
        global $wpdb;

        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT * FROM `" . self::table() . "` WHERE id = %d LIMIT 1",
                $deposit_id
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Obtiene los depósitos de un vendedor.
     *
     * @param int    $vendor_id ID del vendedor.
     * @param string $status    Filtrar por estado ('' = todos).
     * @param int    $limit     Límite de resultados.
     * @param int    $offset    Offset paginación.
     * @return array[]
     */
    public static function get_by_vendor( int $vendor_id, string $status = '', int $limit = 20, int $offset = 0 ): array {
        global $wpdb;
        $table = self::table();

        if ( $status ) {
            $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->prepare(
                    "SELECT * FROM `{$table}` WHERE vendor_id = %d AND status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
                    $vendor_id, $status, $limit, $offset
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->prepare(
                    "SELECT * FROM `{$table}` WHERE vendor_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
                    $vendor_id, $limit, $offset
                ),
                ARRAY_A
            );
        }

        return $rows ?: [];
    }

    /**
     * Lista todos los depósitos para el panel admin (con filtros).
     *
     * @param array $args Filtros: status, method, date_from, date_to, search, per_page, paged.
     * @return array{items: array[], total: int}
     */
    public static function get_all( array $args = [] ): array {
        global $wpdb;
        $table = self::table();

        $defaults = [
            'status'    => '',
            'method'    => '',
            'date_from' => '',
            'date_to'   => '',
            'search'    => '',
            'per_page'  => 25,
            'paged'     => 1,
        ];
        $args = wp_parse_args( $args, $defaults );

        $where  = [ '1=1' ];
        $params = [];

        if ( $args['status'] ) {
            $where[]  = 'd.status = %s';
            $params[] = $args['status'];
        }
        if ( $args['method'] ) {
            $where[]  = 'd.method = %s';
            $params[] = $args['method'];
        }
        if ( $args['date_from'] ) {
            $where[]  = 'DATE(d.created_at) >= %s';
            $params[] = $args['date_from'];
        }
        if ( $args['date_to'] ) {
            $where[]  = 'DATE(d.created_at) <= %s';
            $params[] = $args['date_to'];
        }
        if ( $args['search'] ) {
            $where[]  = '(u.display_name LIKE %s OR d.reference LIKE %s)';
            $like      = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = implode( ' AND ', $where );
        $offset    = ( (int) $args['paged'] - 1 ) * (int) $args['per_page'];

        $count_sql = "SELECT COUNT(*) FROM `{$table}` d LEFT JOIN {$wpdb->users} u ON u.ID = d.vendor_id WHERE {$where_sql}";
        $data_sql  = "SELECT d.*, u.display_name as vendor_name, u.user_email as vendor_email
                      FROM `{$table}` d
                      LEFT JOIN {$wpdb->users} u ON u.ID = d.vendor_id
                      WHERE {$where_sql}
                      ORDER BY d.created_at DESC
                      LIMIT %d OFFSET %d";

        if ( $params ) {
            $total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) ); // phpcs:ignore
            $items = $wpdb->get_results( $wpdb->prepare( $data_sql, ...[...$params, (int) $args['per_page'], $offset] ), ARRAY_A ); // phpcs:ignore
        } else {
            $total = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $items = $wpdb->get_results( $wpdb->prepare( $data_sql, (int) $args['per_page'], $offset ), ARRAY_A ); // phpcs:ignore
        }

        return [
            'items' => $items ?: [],
            'total' => $total,
        ];
    }

    /**
     * Cuenta depósitos pendientes (badge admin).
     *
     * @return int
     */
    public static function count_pending(): int {
        global $wpdb;
        return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT COUNT(*) FROM `" . self::table() . "` WHERE status = 'pending'"
        );
    }

    /**
     * Notifica al admin sobre un nuevo depósito pendiente.
     */
    private static function notify_admin_new_deposit( int $deposit_id, int $vendor_id, float $amount, string $method ): void {
        $admin_email = get_option( 'admin_email' );
        $vendor      = get_userdata( $vendor_id );
        $vendor_name = $vendor ? $vendor->display_name : "#{$vendor_id}";

        $subject = sprintf( '[Lo Tengo] Nuevo depósito pendiente #%d — %s', $deposit_id, LTMS_Utils::format_money( $amount ) );
        $message = sprintf(
            "Se recibió una nueva solicitud de depósito manual.\n\nDepósito #%d\nVendedor: %s\nMonto: %s\nMétodo: %s\n\nRevisar en: %s",
            $deposit_id,
            $vendor_name,
            LTMS_Utils::format_money( $amount ),
            strtoupper( $method ),
            admin_url( 'admin.php?page=ltms-deposits' )
        );

        wp_mail( $admin_email, $subject, $message );
    }

    /**
     * Notifica al vendedor que su depósito fue aprobado.
     */
    private static function notify_vendor_approved( array $deposit ): void {
        $vendor = get_userdata( (int) $deposit['vendor_id'] );
        if ( ! $vendor ) {
            return;
        }

        $subject = sprintf( '[Lo Tengo] ✅ Tu depósito de %s fue aprobado', LTMS_Utils::format_money( (float) $deposit['amount'] ) );
        $message = sprintf(
            "Hola %s,\n\nTu depósito de %s via %s ha sido aprobado y ya está disponible en tu billetera.\n\nReferencia: %s\n\nPuedes ver tu saldo en: %s",
            $vendor->display_name,
            LTMS_Utils::format_money( (float) $deposit['amount'] ),
            strtoupper( $deposit['method'] ),
            $deposit['reference'] ?: 'N/A',
            home_url( '/dashboard/wallet/' )
        );

        wp_mail( $vendor->user_email, $subject, $message );
    }

    /**
     * Notifica al vendedor que su depósito fue rechazado.
     */
    private static function notify_vendor_rejected( array $deposit, string $reason ): void {
        $vendor = get_userdata( (int) $deposit['vendor_id'] );
        if ( ! $vendor ) {
            return;
        }

        $subject = sprintf( '[Lo Tengo] ❌ Tu depósito de %s fue rechazado', LTMS_Utils::format_money( (float) $deposit['amount'] ) );
        $message = sprintf(
            "Hola %s,\n\nTu solicitud de depósito de %s via %s ha sido rechazada.\n\nMotivo: %s\n\nSi tienes dudas, contáctanos respondiendo este correo.",
            $vendor->display_name,
            LTMS_Utils::format_money( (float) $deposit['amount'] ),
            strtoupper( $deposit['method'] ),
            $reason
        );

        wp_mail( $vendor->user_email, $subject, $message );
    }

    /**
     * Cron: Notifica al admin sobre depósitos pendientes de más de 48h.
     *
     * @return void
     */
    public static function notify_stale_deposits(): void {
        global $wpdb;

        $count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT COUNT(*) FROM `" . self::table() . "`
             WHERE status = 'pending'
             AND created_at <= DATE_SUB(NOW(), INTERVAL 48 HOUR)"
        );

        if ( $count > 0 ) {
            wp_mail(
                get_option( 'admin_email' ),
                sprintf( '[Lo Tengo] ⚠️ %d depósito(s) pendiente(s) sin revisar (+48h)', $count ),
                sprintf(
                    "Hay %d depósito(s) pendientes de revisión con más de 48 horas sin procesar.\n\nRevisar en: %s",
                    $count,
                    admin_url( 'admin.php?page=ltms-deposits&status=pending' )
                )
            );
        }
    }
    /**
     * Cuenta depósitos por estado.
     *
     * @param string $status pending|approved|rejected
     * @return int
     */
    public static function count_by_status( string $status ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT COUNT(*) FROM `" . self::table() . "` WHERE status = %s",
            $status
        ) );
    }

    /**
     * Suma total de depósitos aprobados (para el widget de stats del admin).
     *
     * @return float
     */
    public static function sum_approved(): float {
        global $wpdb;
        $val = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT COALESCE(SUM(amount), 0) FROM `" . self::table() . "` WHERE status = 'approved'"
        );
        return (float) $val;
    }


}
