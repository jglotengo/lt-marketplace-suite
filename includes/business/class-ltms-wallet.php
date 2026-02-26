<?php
/**
 * LTMS Business Wallet - Ledger Financiero ACID
 *
 * Motor de billetera con garantías de consistencia ACID usando
 * transacciones MySQL. Implementa el patrón Ledger Inmutable:
 * cada movimiento crea una nueva fila (nunca actualiza el saldo directamente).
 *
 * @package    LTMS
 * @subpackage LTMS/includes/business
 * @version    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Business_Wallet
 */
final class LTMS_Business_Wallet {

    use LTMS_Logger_Aware;

    /**
     * Inicializa hooks de billetera.
     *
     * @return void
     */
    public static function init(): void {
        // Manejar liberación automática de fondos retenidos
        add_action( 'ltms_process_payouts', [ __CLASS__, 'process_automatic_releases' ] );
    }

    /**
     * Obtiene o crea la billetera de un vendedor.
     *
     * @param int    $vendor_id ID del vendedor (WP user_id).
     * @param string $currency  Moneda de la billetera.
     * @return array{id: int, vendor_id: int, balance: float, balance_pending: float, currency: string, is_frozen: int}
     * @throws \RuntimeException Si no se puede crear la billetera.
     */
    public static function get_or_create( int $vendor_id, string $currency = '' ): array {
        global $wpdb;

        if ( empty( $currency ) ) {
            $currency = LTMS_Core_Config::get_currency();
        }

        $table = $wpdb->prefix . 'lt_vendor_wallets';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wallet = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `{$table}` WHERE vendor_id = %d LIMIT 1", $vendor_id ),
            ARRAY_A
        );

        if ( $wallet ) {
            return $wallet;
        }

        // Crear nueva billetera
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $result = $wpdb->insert(
            $table,
            [
                'vendor_id'  => $vendor_id,
                'balance'    => 0.00,
                'balance_pending' => 0.00,
                'balance_reserved' => 0.00,
                'currency'   => $currency,
                'is_frozen'  => 0,
                'total_earned'   => 0.00,
                'total_withdrawn' => 0.00,
                'created_at' => LTMS_Utils::now_utc(),
                'updated_at' => LTMS_Utils::now_utc(),
            ],
            [ '%d', '%f', '%f', '%f', '%s', '%d', '%f', '%f', '%s', '%s' ]
        );

        if ( ! $result ) {
            throw new \RuntimeException(
                sprintf( 'LTMS Wallet: No se pudo crear la billetera para el vendedor #%d', $vendor_id )
            );
        }

        $wallet_id = $wpdb->insert_id;

        LTMS_Core_Logger::info(
            'WALLET_CREATED',
            sprintf( 'Billetera #%d creada para vendedor #%d', $wallet_id, $vendor_id ),
            [ 'wallet_id' => $wallet_id, 'vendor_id' => $vendor_id, 'currency' => $currency ]
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $wallet_id ),
            ARRAY_A
        );
    }

    /**
     * Acredita un monto en la billetera (crédito disponible).
     * Ejecuta en transacción MySQL para garantizar atomicidad.
     *
     * @param int    $vendor_id   ID del vendedor.
     * @param float  $amount      Monto a acreditar.
     * @param string $description Descripción del movimiento.
     * @param array  $metadata    Metadatos adicionales.
     * @param int    $order_id    ID del pedido relacionado (opcional).
     * @return int ID de la transacción creada.
     * @throws \InvalidArgumentException Si el monto es negativo o cero.
     * @throws \RuntimeException Si la transacción falla.
     */
    public static function credit(
        int    $vendor_id,
        float  $amount,
        string $description,
        array  $metadata  = [],
        int    $order_id  = 0
    ): int {
        if ( $amount <= 0 ) {
            throw new \InvalidArgumentException( 'LTMS Wallet: El monto a acreditar debe ser positivo.' );
        }

        return self::execute_transaction( $vendor_id, 'credit', $amount, $description, $metadata, $order_id );
    }

    /**
     * Debita un monto de la billetera.
     *
     * @param int    $vendor_id   ID del vendedor.
     * @param float  $amount      Monto a debitar.
     * @param string $description Descripción.
     * @param array  $metadata    Metadatos.
     * @param int    $order_id    Pedido relacionado.
     * @return int ID de la transacción.
     * @throws \InvalidArgumentException Si fondos insuficientes.
     */
    public static function debit(
        int    $vendor_id,
        float  $amount,
        string $description,
        array  $metadata = [],
        int    $order_id = 0
    ): int {
        if ( $amount <= 0 ) {
            throw new \InvalidArgumentException( 'LTMS Wallet: El monto a debitar debe ser positivo.' );
        }

        $wallet = self::get_or_create( $vendor_id );

        if ( (float) $wallet['balance'] < $amount ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'LTMS Wallet: Saldo insuficiente. Disponible: %s, Requerido: %s',
                    $wallet['balance'],
                    $amount
                )
            );
        }

        return self::execute_transaction( $vendor_id, 'debit', $amount, $description, $metadata, $order_id );
    }

    /**
     * Pone un monto en retención (del disponible al pendiente).
     *
     * @param int    $vendor_id   ID del vendedor.
     * @param float  $amount      Monto a retener.
     * @param string $description Descripción.
     * @param array  $metadata    Metadatos.
     * @return int ID de la transacción.
     */
    public static function hold(
        int    $vendor_id,
        float  $amount,
        string $description = '',
        array  $metadata    = []
    ): int {
        return self::execute_transaction( $vendor_id, 'hold', $amount, $description, $metadata );
    }

    /**
     * Libera un monto retenido (de pendiente a disponible).
     *
     * @param int    $vendor_id   ID del vendedor.
     * @param float  $amount      Monto a liberar.
     * @param string $description Descripción.
     * @param array  $metadata    Metadatos.
     * @return int ID de la transacción.
     */
    public static function release(
        int    $vendor_id,
        float  $amount,
        string $description = '',
        array  $metadata    = []
    ): int {
        return self::execute_transaction( $vendor_id, 'release', $amount, $description, $metadata );
    }

    /**
     * Ejecuta una transacción de billetera de forma atómica (transacción MySQL).
     *
     * @param int    $vendor_id   ID del vendedor.
     * @param string $type        Tipo: credit|debit|hold|release|payout|fee|tax_withholding.
     * @param float  $amount      Monto.
     * @param string $description Descripción.
     * @param array  $metadata    Metadatos.
     * @param int    $order_id    ID de pedido.
     * @return int ID de la transacción creada.
     * @throws \RuntimeException Si la transacción de BD falla.
     */
    public static function execute_transaction(
        int    $vendor_id,
        string $type,
        float  $amount,
        string $description,
        array  $metadata  = [],
        int    $order_id  = 0
    ): int {
        global $wpdb;

        $wallets_table = $wpdb->prefix . 'lt_vendor_wallets';
        $tx_table      = $wpdb->prefix . 'lt_wallet_transactions';

        $wpdb->query( 'START TRANSACTION' );

        try {
            // 1. Bloquear la fila de la billetera para escritura (SELECT FOR UPDATE)
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wallet = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM `{$wallets_table}` WHERE vendor_id = %d FOR UPDATE",
                    $vendor_id
                ),
                ARRAY_A
            );

            if ( ! $wallet ) {
                // Crear billetera si no existe (dentro de la transacción)
                $wpdb->insert( $wallets_table, [
                    'vendor_id'   => $vendor_id,
                    'balance'     => 0.00,
                    'balance_pending' => 0.00,
                    'balance_reserved' => 0.00,
                    'currency'    => LTMS_Core_Config::get_currency(),
                    'is_frozen'   => 0,
                    'total_earned' => 0.00,
                    'total_withdrawn' => 0.00,
                    'created_at'  => LTMS_Utils::now_utc(),
                    'updated_at'  => LTMS_Utils::now_utc(),
                ], [ '%d', '%f', '%f', '%f', '%s', '%d', '%f', '%f', '%s', '%s' ]);

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wallet = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT * FROM `{$wallets_table}` WHERE vendor_id = %d FOR UPDATE",
                        $vendor_id
                    ),
                    ARRAY_A
                );
            }

            // 2. Verificar si la billetera está congelada
            if ( (int) $wallet['is_frozen'] === 1 && in_array( $type, [ 'debit', 'payout' ], true ) ) {
                throw new \RuntimeException(
                    sprintf( 'LTMS Wallet: La billetera del vendedor #%d está congelada por compliance.', $vendor_id )
                );
            }

            $balance_before = (float) $wallet['balance'];
            $pending_before = (float) $wallet['balance_pending'];

            // 3. Calcular nuevos saldos según tipo de transacción
            $new_balance         = $balance_before;
            $new_balance_pending = $pending_before;

            switch ( $type ) {
                case 'credit':
                    $new_balance = bcadd( (string) $balance_before, (string) $amount, 2 );
                    break;

                case 'debit':
                case 'payout':
                case 'fee':
                case 'tax_withholding':
                    if ( (float) $new_balance < $amount ) {
                        throw new \InvalidArgumentException(
                            "LTMS Wallet: Saldo insuficiente para {$type}. Disponible: {$balance_before}, Requerido: {$amount}"
                        );
                    }
                    $new_balance = bcsub( (string) $balance_before, (string) $amount, 2 );
                    break;

                case 'hold':
                    if ( (float) $new_balance < $amount ) {
                        throw new \InvalidArgumentException(
                            "LTMS Wallet: Saldo insuficiente para retener. Disponible: {$balance_before}"
                        );
                    }
                    $new_balance         = bcsub( (string) $balance_before, (string) $amount, 2 );
                    $new_balance_pending = bcadd( (string) $pending_before, (string) $amount, 2 );
                    break;

                case 'release':
                    if ( (float) $new_balance_pending < $amount ) {
                        $amount = (float) $new_balance_pending; // Liberar lo que hay
                    }
                    $new_balance_pending = bcsub( (string) $pending_before, (string) $amount, 2 );
                    $new_balance         = bcadd( (string) $balance_before, (string) $amount, 2 );
                    break;

                case 'reversal':
                    $new_balance = bcadd( (string) $balance_before, (string) $amount, 2 );
                    break;

                case 'adjustment':
                    // Puede ser positivo o negativo
                    $new_balance = bcadd( (string) $balance_before, (string) $amount, 2 );
                    if ( (float) $new_balance < 0 ) {
                        throw new \InvalidArgumentException( 'LTMS Wallet: El ajuste dejaría el saldo negativo.' );
                    }
                    break;
            }

            // 4. Actualizar saldo de la billetera
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $updated = $wpdb->update(
                $wallets_table,
                [
                    'balance'           => (float) $new_balance,
                    'balance_pending'   => max( 0, (float) $new_balance_pending ),
                    'last_transaction'  => LTMS_Utils::now_utc(),
                    'updated_at'        => LTMS_Utils::now_utc(),
                    'total_earned'      => in_array( $type, [ 'credit' ], true )
                        ? (float) bcadd( (string) $wallet['total_earned'], (string) $amount, 2 )
                        : (float) $wallet['total_earned'],
                    'total_withdrawn'   => in_array( $type, [ 'payout' ], true )
                        ? (float) bcadd( (string) $wallet['total_withdrawn'], (string) $amount, 2 )
                        : (float) $wallet['total_withdrawn'],
                ],
                [ 'id' => (int) $wallet['id'] ],
                [ '%f', '%f', '%s', '%s', '%f', '%f' ],
                [ '%d' ]
            );

            if ( $updated === false ) {
                throw new \RuntimeException( 'LTMS Wallet: Error al actualizar el saldo de la billetera.' );
            }

            // 5. Registrar la transacción en el ledger inmutable
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $tx_result = $wpdb->insert(
                $tx_table,
                [
                    'wallet_id'      => (int) $wallet['id'],
                    'vendor_id'      => $vendor_id,
                    'order_id'       => $order_id ?: null,
                    'type'           => $type,
                    'amount'         => $amount,
                    'balance_before' => $balance_before,
                    'balance_after'  => (float) $new_balance,
                    'currency'       => $wallet['currency'],
                    'description'    => substr( sanitize_text_field( $description ), 0, 500 ),
                    'status'         => 'completed',
                    'metadata'       => $metadata ? wp_json_encode( $metadata ) : null,
                    'ip_address'     => LTMS_Utils::get_ip(),
                    'created_by'     => get_current_user_id() ?: null,
                    'created_at'     => LTMS_Utils::now_utc(),
                ],
                [ '%d', '%d', '%d', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
            );

            if ( ! $tx_result ) {
                throw new \RuntimeException( 'LTMS Wallet: Error al registrar la transacción en el ledger.' );
            }

            $tx_id = $wpdb->insert_id;

            $wpdb->query( 'COMMIT' );

            LTMS_Core_Logger::info(
                'WALLET_TRANSACTION',
                sprintf( '[%s] Billetera vendedor #%d: %s %s → Saldo: %s',
                    strtoupper( $type ),
                    $vendor_id,
                    LTMS_Utils::format_money( $amount, $wallet['currency'] ),
                    $description,
                    LTMS_Utils::format_money( (float) $new_balance, $wallet['currency'] )
                ),
                [ 'tx_id' => $tx_id, 'vendor_id' => $vendor_id, 'amount' => $amount, 'type' => $type ]
            );

            return $tx_id;

        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );

            LTMS_Core_Logger::error(
                'WALLET_TRANSACTION_FAILED',
                sprintf( 'Transacción de billetera fallida para vendedor #%d: %s', $vendor_id, $e->getMessage() ),
                [ 'vendor_id' => $vendor_id, 'type' => $type, 'amount' => $amount ]
            );

            throw $e;
        }
    }

    /**
     * Obtiene el saldo actual de un vendedor.
     *
     * @param int $vendor_id ID del vendedor.
     * @return array{balance: float, balance_pending: float, currency: string, is_frozen: bool}
     */
    public static function get_balance( int $vendor_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_vendor_wallets';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wallet = $wpdb->get_row(
            $wpdb->prepare( "SELECT balance, balance_pending, currency, is_frozen FROM `{$table}` WHERE vendor_id = %d", $vendor_id ),
            ARRAY_A
        );

        if ( ! $wallet ) {
            return [
                'balance'         => 0.00,
                'balance_pending' => 0.00,
                'currency'        => LTMS_Core_Config::get_currency(),
                'is_frozen'       => false,
            ];
        }

        return [
            'balance'         => (float) $wallet['balance'],
            'balance_pending' => (float) $wallet['balance_pending'],
            'currency'        => $wallet['currency'],
            'is_frozen'       => (bool) $wallet['is_frozen'],
        ];
    }

    /**
     * Congela una billetera (bloquea retiros por compliance).
     *
     * @param int    $vendor_id ID del vendedor.
     * @param string $reason    Motivo del congelamiento.
     * @return bool
     */
    public static function freeze( int $vendor_id, string $reason ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_vendor_wallets';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $result = $wpdb->update(
            $table,
            [
                'is_frozen'    => 1,
                'freeze_reason' => substr( sanitize_text_field( $reason ), 0, 500 ),
                'updated_at'   => LTMS_Utils::now_utc(),
            ],
            [ 'vendor_id' => $vendor_id ],
            [ '%d', '%s', '%s' ],
            [ '%d' ]
        );

        if ( $result !== false ) {
            LTMS_Core_Logger::security(
                'WALLET_FROZEN',
                sprintf( 'Billetera del vendedor #%d CONGELADA. Motivo: %s', $vendor_id, $reason ),
                [ 'vendor_id' => $vendor_id, 'reason' => $reason, 'frozen_by' => get_current_user_id() ]
            );
        }

        return $result !== false;
    }

    /**
     * Libera todos los montos retenidos cuyo período de hold ha vencido.
     * Ejecutado por cron job diario.
     *
     * @return void
     */
    public static function process_automatic_releases(): void {
        global $wpdb;

        $hold_days = (int) LTMS_Core_Config::get( 'ltms_hold_period_days', 7 );

        // Buscar transacciones de hold antiguas que no han sido liberadas
        $tx_table = $wpdb->prefix . 'lt_wallet_transactions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $pending_holds = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT vendor_id, SUM(amount) as total_to_release
                 FROM `{$tx_table}`
                 WHERE type = 'hold'
                 AND status = 'completed'
                 AND created_at <= DATE_SUB(NOW(), INTERVAL %d DAY)
                 AND vendor_id NOT IN (
                     SELECT DISTINCT vendor_id FROM `{$tx_table}`
                     WHERE type = 'release' AND created_at > DATE_SUB(NOW(), INTERVAL %d DAY)
                 )
                 GROUP BY vendor_id",
                $hold_days,
                $hold_days
            ),
            ARRAY_A
        );

        foreach ( $pending_holds as $row ) {
            try {
                self::release(
                    (int) $row['vendor_id'],
                    (float) $row['total_to_release'],
                    sprintf( 'Liberación automática tras %d días de retención', $hold_days ),
                    [ 'auto_release' => true, 'hold_days' => $hold_days ]
                );
            } catch ( \Throwable $e ) {
                LTMS_Core_Logger::error(
                    'WALLET_AUTO_RELEASE_FAILED',
                    sprintf( 'Fallo al liberar fondos del vendedor #%d: %s', $row['vendor_id'], $e->getMessage() )
                );
            }
        }
    }
}
