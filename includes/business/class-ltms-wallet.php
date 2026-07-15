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
        // M-98: process_automatic_releases deshabilitado — Consumer_Protection::release_eligible_holds()
        // gestiona la liberación individual de holds via ltms_daily_cron (lt_wallet_holds como fuente
        // de verdad). La query de process_automatic_releases usaba lt_wallet_transactions con un
        // subquery incorrecto que podría causar doble liberación o exclusión incorrecta de vendors.
        // add_action( 'ltms_process_payouts', [ __CLASS__, 'process_automatic_releases' ] );

        // CR-CRASH-1: recovery cron for phantom wallet transactions.
        // Runs every hour to clean up journal records stuck in 'pending' > 5 minutes
        // (which indicate a PHP crash mid-operation). If a matching committed tx is found,
        // the journal is marked 'completed'; otherwise it's marked 'failed' + CRITICAL alert.
        add_action( 'ltms_recover_pending_wallet_txs', [ __CLASS__, 'recover_pending_wallet_txs' ] );
        if ( ! wp_next_scheduled( 'ltms_recover_pending_wallet_txs' ) ) {
            wp_schedule_event( time() + 300, 'hourly', 'ltms_recover_pending_wallet_txs' );
        }
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
            $wpdb->prepare( "SELECT * FROM `{$table}` WHERE vendor_id = %d AND currency = %s LIMIT 1", $vendor_id, $currency ),
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
                'vendor_id'       => $vendor_id,
                'balance'         => 0.00,
                'balance_pending' => 0.00,
                'balance_reserved'=> 0.00,
                'currency'        => $currency,
                'is_frozen'       => 0,
                'total_earned'    => 0.00,
                'total_withdrawn' => 0.00,
                'created_at'      => LTMS_Utils::now_utc(),
                'updated_at'      => LTMS_Utils::now_utc(),
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
     * @param int    $vendor_id        ID del vendedor.
     * @param float  $amount           Monto a acreditar.
     * @param string $description      Descripción del movimiento.
     * @param array  $metadata         Metadatos adicionales.
     * @param int    $order_id         ID del pedido relacionado (opcional).
     * @param string $currency         Moneda de la transacción (WL-BUG-A). Default = config.
     * @param string $idempotency_key  Clave de idempotencia (WL-CRASH-2). Si se provee y ya
     *                                 existe una tx con esa referencia, se retorna su ID sin
     *                                 ejecutar una nueva transacción.
     * @return int ID de la transacción creada.
     * @throws \InvalidArgumentException Si el monto es negativo o cero.
     * @throws \RuntimeException Si la transacción falla.
     */
    public static function credit(
        int    $vendor_id,
        float  $amount,
        string $description,
        array  $metadata  = [],
        int    $order_id  = 0,
        string $currency  = '',
        string $idempotency_key = ''
    ): int {
        if ( $amount <= 0 ) {
            throw new \InvalidArgumentException( 'LTMS Wallet: El monto a acreditar debe ser positivo.' );
        }

        return self::execute_transaction( $vendor_id, 'credit', $amount, $description, $metadata, $order_id, $currency, $idempotency_key );
    }

    /**
     * Debita un monto de la billetera.
     *
     * @param int    $vendor_id        ID del vendedor.
     * @param float  $amount           Monto a debitar.
     * @param string $description      Descripción.
     * @param array  $metadata         Metadatos.
     * @param int    $order_id         Pedido relacionado.
     * @param string $currency         Moneda (WL-BUG-A). Default = config.
     * @param string $idempotency_key  Clave de idempotencia (WL-CRASH-2).
     * @return int ID de la transacción.
     * @throws \InvalidArgumentException Si fondos insuficientes.
     */
    public static function debit(
        int    $vendor_id,
        float  $amount,
        string $description,
        array  $metadata = [],
        int    $order_id = 0,
        string $currency = '',
        string $idempotency_key = ''
    ): int {
        if ( $amount <= 0 ) {
            throw new \InvalidArgumentException( 'LTMS Wallet: El monto a debitar debe ser positivo.' );
        }

        $wallet = self::get_or_create( $vendor_id, $currency );

        if ( (float) $wallet['balance'] < $amount ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'LTMS Wallet: Saldo insuficiente. Disponible: %s, Requerido: %s',
                    $wallet['balance'],
                    $amount
                )
            );
        }

        return self::execute_transaction( $vendor_id, 'debit', $amount, $description, $metadata, $order_id, $currency, $idempotency_key );
    }

    /**
     * Pone un monto en retención (del disponible al pendiente).
     *
     * @param int    $vendor_id        ID del vendedor.
     * @param float  $amount           Monto a retener.
     * @param string $description      Descripción.
     * @param array  $metadata         Metadatos.
     * @param int    $order_id         Pedido relacionado (opcional).
     * @param string $currency         Moneda (WL-BUG-A). Default = config.
     * @param string $idempotency_key  Clave de idempotencia (WL-CRASH-2).
     * @return int ID de la transacción.
     */
    public static function hold(
        int    $vendor_id,
        float  $amount,
        string $description = '',
        array  $metadata    = [],
        int    $order_id    = 0,
        string $currency    = '',
        string $idempotency_key = ''
    ): int {
        return self::execute_transaction( $vendor_id, 'hold', $amount, $description, $metadata, $order_id, $currency, $idempotency_key );
    }

    /**
     * Libera un monto retenido (de pendiente a disponible).
     *
     * @param int    $vendor_id        ID del vendedor.
     * @param float  $amount           Monto a liberar.
     * @param string $description      Descripción.
     * @param array  $metadata         Metadatos.
     * @param int    $order_id         Pedido relacionado (opcional).
     * @param string $currency         Moneda (WL-BUG-A). Default = config.
     * @param string $idempotency_key  Clave de idempotencia (WL-CRASH-2).
     * @return int ID de la transacción.
     */
    public static function release(
        int    $vendor_id,
        float  $amount,
        string $description = '',
        array  $metadata    = [],
        int    $order_id    = 0,
        string $currency    = '',
        string $idempotency_key = ''
    ): int {
        return self::execute_transaction( $vendor_id, 'release', $amount, $description, $metadata, $order_id, $currency, $idempotency_key );
    }

    /**
     * OS-BUG-B FIX: Crédito que NO inicia su propia transacción MySQL.
     *
     * MySQL no soporta transacciones anidadas — cuando `Wallet::credit()` abre su
     * START TRANSACTION interno, hace COMMIT implícito del START TRANSACTION
     * externo. Esto rompe el patrón `try { ... } catch { ROLLBACK }` del caller,
     * porque el ROLLBACK ya no puede deshacer el crédito.
     *
     * Este método hace el mismo trabajo que `credit()` pero asume que el caller
     * ya abrió la transacción (START TRANSACTION) y la cerrará (COMMIT/ROLLBACK).
     * Si la operación falla, lanza una excepción — el caller debe ROLLBACK.
     *
     * @param int    $vendor_id        ID del vendedor.
     * @param float  $amount           Monto a acreditar.
     * @param string $description      Descripción.
     * @param array  $metadata         Metadatos.
     * @param int    $order_id         Pedido relacionado.
     * @param string $currency         Moneda (WL-BUG-A).
     * @param string $idempotency_key  Clave de idempotencia (WL-CRASH-2).
     * @return int ID de la transacción creada.
     * @throws \InvalidArgumentException Si el monto no es positivo.
     * @throws \RuntimeException Si la operación falla (caller debe ROLLBACK).
     */
    public static function credit_within_transaction(
        int    $vendor_id,
        float  $amount,
        string $description,
        array  $metadata  = [],
        int    $order_id  = 0,
        string $currency  = '',
        string $idempotency_key = ''
    ): int {
        if ( $amount <= 0 ) {
            throw new \InvalidArgumentException( 'LTMS Wallet: El monto a acreditar debe ser positivo.' );
        }

        // $managed_externally = true → skip START TRANSACTION / COMMIT / ROLLBACK.
        return self::execute_transaction( $vendor_id, 'credit', $amount, $description, $metadata, $order_id, $currency, $idempotency_key, true );
    }

    /**
     * WL-BUG-2 FIX (Task 65-C): Débito que NO inicia su propia transacción MySQL.
     *
     * Contrapartida de `credit_within_transaction` para operaciones de débito.
     * Se usa en `convert_balance()` para envolver débito + crédito en una sola
     * transacción MySQL externa y lograr atomicidad real (WL-BUG-2).
     *
     * El balance check ocurre DENTRO de la transacción externa (vía
     * `execute_transaction`'s switch case 'debit' con SELECT FOR UPDATE),
     * lo que es más seguro que el pre-check de `debit()` (race-condition-safe).
     *
     * @param int    $vendor_id        ID del vendedor.
     * @param float  $amount           Monto a debitar.
     * @param string $description      Descripción.
     * @param array  $metadata         Metadatos.
     * @param int    $order_id         Pedido relacionado.
     * @param string $currency         Moneda (WL-BUG-A).
     * @param string $idempotency_key  Clave de idempotencia (WL-CRASH-2).
     * @return int ID de la transacción.
     * @throws \InvalidArgumentException Si el monto no es positivo o fondos insuficientes.
     * @throws \RuntimeException Si la operación falla (caller debe ROLLBACK).
     */
    public static function debit_within_transaction(
        int    $vendor_id,
        float  $amount,
        string $description,
        array  $metadata  = [],
        int    $order_id  = 0,
        string $currency  = '',
        string $idempotency_key = ''
    ): int {
        if ( $amount <= 0 ) {
            throw new \InvalidArgumentException( 'LTMS Wallet: El monto a debitar debe ser positivo.' );
        }

        // $managed_externally = true → skip START TRANSACTION / COMMIT / ROLLBACK.
        // The insufficient-balance check is performed inside execute_transaction's
        // 'debit' switch case (SELECT FOR UPDATE → check → throw if insufficient),
        // so it is race-condition-safe within the caller's outer transaction.
        return self::execute_transaction( $vendor_id, 'debit', $amount, $description, $metadata, $order_id, $currency, $idempotency_key, true );
    }

    /**
     * Ejecuta una transacción de billetera de forma atómica (transacción MySQL).
     *
     * @param int    $vendor_id           ID del vendedor.
     * @param string $type                Tipo: credit|debit|hold|release|payout|fee|tax_withholding.
     * @param float  $amount              Monto.
     * @param string $description         Descripción.
     * @param array  $metadata            Metadatos.
     * @param int    $order_id            ID de pedido.
     * @param string $currency            Moneda de la transacción (WL-BUG-A). Default = config.
     * @param string $idempotency_key     Clave de idempotencia (WL-CRASH-2).
     * @param bool   $managed_externally  Si true, NO abre/commit/rollback la tx MySQL
     *                                    (caller gestiona la transacción — OS-BUG-B).
     * @return int ID de la transacción creada.
     * @throws \RuntimeException Si la transacción de BD falla o currency mismatch (WL-BUG-A).
     * @throws \RuntimeException Si el tipo es desconocido (WL-BUG-C).
     */
    public static function execute_transaction(
        int    $vendor_id,
        string $type,
        float  $amount,
        string $description,
        array  $metadata  = [],
        int    $order_id  = 0,
        string $currency  = '',
        string $idempotency_key = '',
        bool   $managed_externally = false
    ): int {
        global $wpdb;

        // v2.9.116 WALLET-AUDIT P0-3 FIX: reject NaN/INF/negative amounts at the entry point.
        // Before, NaN slipped through every check (NaN > 0 is false, NaN <= 0 is false)
        // and would reach bcadd/bcsub which produce "0" for NaN input — resulting in
        // a wallet transaction that records amount=NaN but applies 0 balance change.
        // Negative amounts would invert credit/debit semantics (credit of -100 = debit of 100).
        if ( is_nan( $amount ) || is_infinite( $amount ) ) {
            throw new \InvalidArgumentException(
                sprintf( 'LTMS Wallet: Monto inválido (NaN/INF) para transacción tipo %s.', $type )
            );
        }
        if ( $amount < 0 ) {
            throw new \InvalidArgumentException(
                sprintf( 'LTMS Wallet: Monto negativo (%s) no permitido para tipo %s. Use adjustment para movimientos bidireccionales.', $amount, $type )
            );
        }

        $wallets_table = $wpdb->prefix . 'lt_vendor_wallets';
        $tx_table      = $wpdb->prefix . 'lt_wallet_transactions';

        // WL-BUG-A: default currency = config currency.
        if ( empty( $currency ) ) {
            $currency = LTMS_Core_Config::get_currency();
        }

        // WL-CRASH-2: Idempotency — if idempotency_key provided, check for existing tx.
        // The reference column on lt_wallet_transactions is repurposed as idempotency key
        // storage (VARCHAR(255), already in schema). If a tx with this reference exists,
        // return its ID without re-executing the operation. This prevents double-credit /
        // double-debit if a cron retries after a partial crash.
        //
        // SCHEMA NOTE: an index on `reference` is recommended for performance:
        //   ALTER TABLE lt_wallet_transactions ADD INDEX idx_reference (`reference`);
        if ( ! empty( $idempotency_key ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $existing_tx_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM `{$tx_table}` WHERE `reference` = %s LIMIT 1",
                    $idempotency_key
                )
            );
            if ( $existing_tx_id ) {
                LTMS_Core_Logger::info(
                    'WALLET_IDEMPOTENT_SKIP',
                    sprintf( 'Idempotency hit — tx with reference=%s already exists as id=%d, skipping new operation.', $idempotency_key, $existing_tx_id ),
                    [ 'idempotency_key' => $idempotency_key, 'existing_tx_id' => (int) $existing_tx_id, 'vendor_id' => $vendor_id, 'type' => $type, 'amount' => $amount ]
                );
                return (int) $existing_tx_id;
            }
        }

        // CR-CRASH-1: Pre-write a 'pending' record to the journal. If PHP crashes mid-op,
        // the recovery cron `ltms_recover_pending_wallet_txs` finds this record > 5min
        // later and either marks it 'completed' (if a matching tx was committed) or 'failed'
        // (phantom — no matching tx found → CRITICAL alert).
        $journal_id = self::journal_pre( $vendor_id, $type, $amount, $currency, $order_id, $idempotency_key );

        if ( ! $managed_externally ) {
            $wpdb->query( 'START TRANSACTION' );
        }

        try {
            // 1. Bloquear la fila de la billetera para escritura (SELECT FOR UPDATE).
            // WL-BUG-A FIX: filtrar por currency para evitar debitar COP de wallet MXN
            // (o viceversa). Antes el SELECT solo filtraba por vendor_id, así que si el
            // vendor tenía múltiples wallets en distintas monedas, se seleccionaba la
            // primera y la transacción se aplicaba sin verificar la currency.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wallet = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM `{$wallets_table}` WHERE vendor_id = %d AND currency = %s FOR UPDATE",
                    $vendor_id,
                    $currency
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
                    'currency'    => $currency,
                    'is_frozen'   => 0,
                    'total_earned' => 0.00,
                    'total_withdrawn' => 0.00,
                    'created_at'  => LTMS_Utils::now_utc(),
                    'updated_at'  => LTMS_Utils::now_utc(),
                ], [ '%d', '%f', '%f', '%f', '%s', '%d', '%f', '%f', '%s', '%s' ]);

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wallet = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT * FROM `{$wallets_table}` WHERE vendor_id = %d AND currency = %s FOR UPDATE",
                        $vendor_id,
                        $currency
                    ),
                    ARRAY_A
                );
            }

            // WL-BUG-A FIX: Defensa adicional — si por una race condition la wallet
            // retornada tiene una currency distinta a la transacción, abortar con excepción.
            // Esto previene debitar COP de una wallet MXN (o viceversa).
            if ( ! $wallet || $wallet['currency'] !== $currency ) {
                $wallet_currency = $wallet['currency'] ?? '(none)';
                throw new \RuntimeException(
                    sprintf( 'Currency mismatch: wallet is %s, transaction is %s', $wallet_currency, $currency )
                );
            }

            // 2. Verificar si la billetera está congelada.
            // WL-BUG-B FIX: añadir 'fee' y 'tax_withholding' a la lista de tipos bloqueados.
            // Antes, una wallet congelada solo bloqueaba debit/payout/hold/adjustment — los
            // fees y retenciones de impuestos se aplicaban igual, permitiendo que una wallet
            // congelada por compliance perdiera fondos silenciosamente. Una wallet congelada
            // debe bloquear TODAS las operaciones salientes.
            // credit y release siguen permitidos para no atrapar fondos indefinidamente.
            //
            // W1 FIX (v2.9.0): añadir 'reversal' a la lista de tipos bloqueados. Antes, una
            // wallet congelada podía recibir reversals (que son créditos disfrazados), lo cual
            // permitía mover fondos fuera de una wallet congelada via reversal de transacciones
            // previas. Una wallet congelada debe ser completamente estática salvo credit/release.
            if ( (int) $wallet['is_frozen'] === 1 && in_array( $type, [ 'debit', 'payout', 'hold', 'adjustment', 'fee', 'tax_withholding', 'reversal' ], true ) ) {
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
                        throw new \InvalidArgumentException(
                            sprintf(
                                'LTMS Wallet: No se puede liberar %s — saldo pendiente disponible: %s.',
                                $amount,
                                $new_balance_pending
                            )
                        );
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

                // WL-BUG-C FIX: default case throws on unknown type. Antes, un tipo
                // desconocido caía silenciosamente al final del switch sin cambiar el
                // saldo, pero la transacción se registraba con status='completed' y
                // amount=lo que fuera — un "phantom" transaction con zero balance change.
                default:
                    throw new \RuntimeException(
                        sprintf( 'LTMS Wallet: Unknown transaction type: %s', $type )
                    );
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

            // 5. Registrar la transacción en el ledger inmutable.
            // WL-CRASH-2: si se proveyó idempotency_key, se almacena en la columna `reference`
            // (ya existe en el schema VARCHAR(255)). La recovery cron puede buscar por esta
            // columna para detectar duplicados.
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
                    'reference'      => $idempotency_key ?: null,
                    'status'         => 'completed',
                    'metadata'       => $metadata ? wp_json_encode( $metadata ) : null,
                    'ip_address'     => LTMS_Utils::get_ip(),
                    'created_by'     => get_current_user_id() ?: null,
                    'created_at'     => LTMS_Utils::now_utc(),
                ],
                [ '%d', '%d', '%d', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
            );

            if ( ! $tx_result ) {
                throw new \RuntimeException( 'LTMS Wallet: Error al registrar la transacción en el ledger.' );
            }

            $tx_id = $wpdb->insert_id;

            if ( ! $managed_externally ) {
                $wpdb->query( 'COMMIT' );
            }

            // CR-CRASH-1: marcar journal como completado.
            self::journal_post( $journal_id, 'completed', $tx_id );

            // FASE1-REAUDIT P0 FIX: do_action + logging moved OUTSIDE the try/catch.
            // Previously, if a listener on 'ltms_wallet_tx_committed' threw, execution
            // fell into the catch block which called ROLLBACK — but the transaction was
            // already committed, so ROLLBACK was a no-op. The exception propagated to
            // the caller, which believed the operation failed and retried → double
            // credit. Now: post-commit actions are wrapped in their own try/catch
            // that swallows non-critical errors.
            $currency = $wallet['currency'] ?? LTMS_Core_Config::get_currency();
            $return_tx_id = $tx_id;

        } catch ( \Throwable $e ) {
            if ( ! $managed_externally ) {
                $wpdb->query( 'ROLLBACK' );
            }

            // CR-CRASH-1: marcar journal como fallido (si la tx externa está abierta,
            // el caller hará ROLLBACK; el journal update es autónomo).
            self::journal_post( $journal_id, 'failed', 0, $e->getMessage() );

            LTMS_Core_Logger::error(
                'WALLET_TRANSACTION_FAILED',
                sprintf( 'Transacción de billetera fallida para vendedor #%d: %s', $vendor_id, $e->getMessage() ),
                [ 'vendor_id' => $vendor_id, 'type' => $type, 'amount' => $amount, 'currency' => $currency ?? '', 'idempotency_key' => $idempotency_key ]
            );

            throw $e;
        }

        // Post-commit hooks + logging — OUTSIDE try/catch so listener exceptions
        // don't cause false-failure retries (which would double-credit the wallet).
        try {
            do_action( 'ltms_wallet_tx_committed', $return_tx_id, $vendor_id, $type, $amount, $currency );

            LTMS_Core_Logger::info(
                'WALLET_TRANSACTION',
                sprintf( '[%s] Billetera vendedor #%d: %s %s → Saldo: %s',
                    strtoupper( $type ),
                    $vendor_id,
                    LTMS_Utils::format_money( $amount, $wallet['currency'] ),
                    $description,
                    LTMS_Utils::format_money( (float) $new_balance, $wallet['currency'] )
                ),
                [ 'tx_id' => $return_tx_id, 'vendor_id' => $vendor_id, 'amount' => $amount, 'type' => $type, 'currency' => $currency, 'idempotency_key' => $idempotency_key ]
            );
        } catch ( \Throwable $hook_e ) {
            // Log but don't rethrow — the transaction already committed.
            LTMS_Core_Logger::error(
                'WALLET_POST_COMMIT_HOOK_ERROR',
                sprintf( 'Post-commit hook/logging failed for tx #%d (transaction was committed): %s', $return_tx_id, $hook_e->getMessage() )
            );
        }

        return $return_tx_id;
    }

    /**
     * Obtiene el saldo actual de un vendedor.
     *
     * Cross-Border motor (Task 63-D): if the vendor has wallets in multiple
     * currencies, this method returns the FIRST wallet's balance (legacy
     * behaviour). Use get_wallets() to retrieve every wallet and
     * get_balance_for_currency() to get a specific currency's balance.
     *
     * @param int $vendor_id ID del vendedor.
     * @return array{balance: float, balance_pending: float, currency: string, is_frozen: bool}
     */
    public static function get_balance( int $vendor_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_vendor_wallets';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wallet = $wpdb->get_row(
            $wpdb->prepare( "SELECT balance, balance_pending, currency, is_frozen FROM `{$table}` WHERE vendor_id = %d ORDER BY id ASC LIMIT 1", $vendor_id ),
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
     * Cross-Border motor (Task 63-D): retrieves ALL wallets for a vendor.
     *
     * Each vendor can have one wallet per currency. This method returns
     * every wallet row that exists for the vendor (it does not create
     * missing wallets — use get_or_create() for that).
     *
     * @param int $vendor_id ID del vendedor.
     * @return array<int, array{id: int, vendor_id: int, balance: float, balance_pending: float, balance_reserved: float, currency: string, is_frozen: int, total_earned: float, total_withdrawn: float}>
     */
    public static function get_wallets( int $vendor_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_vendor_wallets';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE vendor_id = %d ORDER BY id ASC",
                $vendor_id
            ),
            ARRAY_A
        );

        if ( ! $rows ) {
            return [];
        }

        // Normalise numeric types for callers.
        foreach ( $rows as &$row ) {
            $row['id']              = (int) $row['id'];
            $row['vendor_id']       = (int) $row['vendor_id'];
            $row['balance']         = (float) $row['balance'];
            $row['balance_pending'] = (float) $row['balance_pending'];
            $row['balance_reserved']= (float) ( $row['balance_reserved'] ?? 0 );
            $row['is_frozen']       = (int) $row['is_frozen'];
            $row['total_earned']    = (float) ( $row['total_earned'] ?? 0 );
            $row['total_withdrawn'] = (float) ( $row['total_withdrawn'] ?? 0 );
        }
        unset( $row );

        return $rows;
    }

    /**
     * Cross-Border motor (Task 63-D): gets the balance for a specific
     * currency wallet (creating the wallet row on first access).
     *
     * @param int    $vendor_id ID del vendedor.
     * @param string $currency  ISO 4217 currency code.
     * @return array{balance: float, balance_pending: float, currency: string, is_frozen: bool}
     */
    public static function get_balance_for_currency( int $vendor_id, string $currency ): array {
        $wallet = self::get_or_create( $vendor_id, $currency );

        return [
            'balance'         => (float) $wallet['balance'],
            'balance_pending' => (float) $wallet['balance_pending'],
            'currency'        => $wallet['currency'],
            'is_frozen'       => (bool) $wallet['is_frozen'],
        ];
    }

    /**
     * Cross-Border motor (Task 63-D): converts an amount between two of
     * the vendor's wallets (debit source, credit destination).
     *
     * The conversion uses LTMS_FX_Rate_Provider to fetch the live mid-market
     * rate (no spread — internal transfers are at-cost). Both legs are
     * logged as separate wallet transactions so the audit trail reflects
     * the FX conversion explicitly.
     *
     * Idempotency: the conversion key `fxconvert_o{order}` is recorded on
     * both the debit and the credit so a retry after a crash does not
     * double-convert.
     *
     * WL-BUG-2 FIX (Task 65-C): Atomicity across both wallets. Previously,
     * `Wallet::credit` and `Wallet::debit` each opened their own MySQL
     * transaction — if the credit failed after the debit committed, the
     * vendor's funds were lost until manual reconciliation. Now both
     * operations are wrapped in a single outer MySQL transaction using
     * `debit_within_transaction` / `credit_within_transaction` with
     * `managed_externally=true`. If either leg fails, the outer ROLLBACK
     * undoes both. MySQL does not support nested transactions, so the
     * `_within_transaction` variants skip their internal START/COMMIT/ROLLBACK.
     *
     * @param int    $vendor_id     ID del vendedor.
     * @param string $from_currency ISO 4217 source currency.
     * @param string $to_currency   ISO 4217 target currency.
     * @param float  $amount        Amount in source currency.
     * @param string $idempotency   Optional idempotency key (default auto-generated).
     * @return array {
     *     @type bool   $success       Whether the conversion succeeded.
     *     @type float  $source_amount Amount debited (in source currency).
     *     @type float  $target_amount Amount credited (in target currency).
     *     @type float  $rate          FX rate used (1 source = X target).
     *     @type string $from_currency Source currency code.
     *     @type string $to_currency   Target currency code.
     *     @type string $error         Error message (empty on success).
     * }
     */
    public static function convert_balance(
        int $vendor_id,
        string $from_currency,
        string $to_currency,
        float $amount,
        string $idempotency = ''
    ): array {
        global $wpdb;

        $from_currency = strtoupper( $from_currency );
        $to_currency   = strtoupper( $to_currency );

        // Edge case: same currency → no-op (return early without DB write).
        if ( $from_currency === $to_currency ) {
            return [
                'success'        => true,
                'source_amount'  => round( $amount, 2 ),
                'target_amount'  => round( $amount, 2 ),
                'rate'           => 1.0,
                'from_currency'  => $from_currency,
                'to_currency'    => $to_currency,
                'error'          => '',
            ];
        }

        if ( $amount <= 0 ) {
            return [
                'success'        => false,
                'source_amount'  => 0.0,
                'target_amount'  => 0.0,
                'rate'           => 0.0,
                'from_currency'  => $from_currency,
                'to_currency'    => $to_currency,
                'error'          => 'Amount must be positive',
            ];
        }

        // Defensive: if the cross-border motor is not loaded, the conversion
        // cannot be performed — return an explicit error rather than silently
        // falling back to a 1:1 rate (which would lose the vendor money).
        if ( ! class_exists( 'LTMS_FX_Rate_Provider' ) ) {
            return [
                'success'        => false,
                'source_amount'  => 0.0,
                'target_amount'  => 0.0,
                'rate'           => 0.0,
                'from_currency'  => $from_currency,
                'to_currency'    => $to_currency,
                'error'          => 'FX rate provider not available',
            ];
        }

        $rate = LTMS_FX_Rate_Provider::get_rate( $from_currency, $to_currency );
        if ( $rate === null || $rate <= 0 ) {
            return [
                'success'        => false,
                'source_amount'  => 0.0,
                'target_amount'  => 0.0,
                'rate'           => 0.0,
                'from_currency'  => $from_currency,
                'to_currency'    => $to_currency,
                'error'          => sprintf( 'FX rate unavailable for %s→%s', $from_currency, $to_currency ),
            ];
        }

        $target_amount = round( $amount * $rate, 2 );
        $idempotency   = $idempotency !== ''
            ? $idempotency
            : sprintf( 'fxconv_v%d_%s%s_%d', $vendor_id, $from_currency, $to_currency, time() );

        $description = sprintf(
            'FX conversion %s %s → %s %s (rate %.6f)',
            number_format( $amount, 2 ),
            $from_currency,
            number_format( $target_amount, 2 ),
            $to_currency,
            $rate
        );

        $metadata_debit = [
            'type'          => 'fx_conversion',
            'direction'     => 'debit',
            'from_currency' => $from_currency,
            'to_currency'   => $to_currency,
            'rate'          => $rate,
            'target_amount' => $target_amount,
        ];
        $metadata_credit = [
            'type'          => 'fx_conversion',
            'direction'     => 'credit',
            'from_currency' => $from_currency,
            'to_currency'   => $to_currency,
            'rate'          => $rate,
            'source_amount' => $amount,
        ];

        // WL-BUG-2 FIX (Task 65-C): wrap both legs in a single MySQL transaction.
        // The _within_transaction variants delegate to execute_transaction with
        // $managed_externally=true, so they neither START nor COMMIT/ROLLBACK —
        // that responsibility belongs to this outer scope. If either leg throws,
        // ROLLBACK undoes both. No more "funds lost between debit and credit".
        $wpdb->query( 'START TRANSACTION' );

        try {
            self::debit_within_transaction(
                $vendor_id,
                $amount,
                $description,
                $metadata_debit,
                0,
                $from_currency,
                $idempotency . '_dbt'
            );

            self::credit_within_transaction(
                $vendor_id,
                $target_amount,
                $description,
                $metadata_credit,
                0,
                $to_currency,
                $idempotency . '_cdt'
            );

            $wpdb->query( 'COMMIT' );
        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );

            LTMS_Core_Logger::critical(
                'WALLET_CONVERT_BALANCE_FAILED',
                sprintf(
                    'FX conversion rolled back for vendor #%d (%s %s → %s %s, rate %.6f). Both legs reverted: %s',
                    $vendor_id,
                    $amount,
                    $from_currency,
                    $target_amount,
                    $to_currency,
                    $rate,
                    $e->getMessage()
                ),
                [
                    'vendor_id'      => $vendor_id,
                    'from_currency'  => $from_currency,
                    'to_currency'    => $to_currency,
                    'amount'         => $amount,
                    'target_amount'  => $target_amount,
                    'rate'           => $rate,
                    'idempotency'    => $idempotency,
                ]
            );

            return [
                'success'        => false,
                'source_amount'  => 0.0,
                'target_amount'  => 0.0,
                'rate'           => $rate,
                'from_currency'  => $from_currency,
                'to_currency'    => $to_currency,
                'error'          => 'Conversion failed (rolled back): ' . $e->getMessage(),
            ];
        }

        /**
         * Fires after a successful wallet FX conversion (both legs committed).
         *
         * @param int    $vendor_id      Vendor ID.
         * @param string $from_currency  Source currency.
         * @param string $to_currency    Target currency.
         * @param float  $source_amount  Amount debited.
         * @param float  $target_amount  Amount credited.
         * @param float  $rate           FX rate used.
         */
        do_action( 'ltms_wallet_fx_converted', $vendor_id, $from_currency, $to_currency, $amount, $target_amount, $rate );

        return [
            'success'        => true,
            'source_amount'  => $amount,
            'target_amount'  => $target_amount,
            'rate'           => $rate,
            'from_currency'  => $from_currency,
            'to_currency'    => $to_currency,
            'error'          => '',
        ];
    }

    /**
     * Obtiene el historial de transacciones de un vendor.
     *
     * @param int $vendor_id ID del vendedor.
     * @param int $limit     Número máximo de registros a retornar.
     * @param int $offset    Desplazamiento para paginación.
     * @return array[]
     */
    public static function get_transactions( int $vendor_id, int $limit = 20, int $offset = 0 ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_wallet_transactions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE vendor_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $vendor_id,
                $limit,
                $offset
            ),
            ARRAY_A
        );

        return $rows ?: [];
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

        // v2.9.116 WALLET-AUDIT P0-1 FIX: freeze ALL of the vendor's wallets (every currency).
        // Before, the WHERE clause was `vendor_id = %d` without currency filter, which
        // matched the first wallet row by primary key — but $wpdb->update without a
        // LIMIT clause actually updates ALL matching rows, so this was technically OK
        // for the freeze flag. However, the freeze_reason was also written to all rows,
        // which is correct. The real bug: if the vendor had wallets in COP + MXN and
        // only one was frozen (e.g., MXN frozen by a prior partial call), this UPDATE
        // would unfreeze none but overwrite the freeze_reason of BOTH. Now we
        // explicitly scope to all currencies and log which wallets were affected.
        $reason_clean = substr( sanitize_text_field( $reason ), 0, 500 );
        if ( $reason_clean === '' ) {
            // v2.9.116 P0-2: reject empty reason — a freeze without reason is
            // non-compliant (SAGRILAFT requires documented justification).
            LTMS_Core_Logger::warning(
                'WALLET_FREEZE_NO_REASON',
                sprintf( 'Intento de congelar billetera del vendedor #%d sin motivo. Bloqueado.', $vendor_id ),
                [ 'vendor_id' => $vendor_id, 'admin_id' => get_current_user_id() ]
            );
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $result = $wpdb->update(
            $table,
            [
                'is_frozen'    => 1,
                'freeze_reason' => $reason_clean,
                'updated_at'   => LTMS_Utils::now_utc(),
            ],
            [ 'vendor_id' => $vendor_id ],
            [ '%d', '%s', '%s' ],
            [ '%d' ]
        );

        if ( $result !== false ) {
            // v2.9.116 P1-3: fire ltms_wallet_frozen action so listeners (fraud alert,
            // vendor notification, accounting hold) can react.
            do_action( 'ltms_wallet_frozen', $vendor_id, $reason_clean, get_current_user_id() );

            LTMS_Core_Logger::security(
                'WALLET_FROZEN',
                sprintf( 'Billetera del vendedor #%d CONGELADA. Motivo: %s', $vendor_id, $reason_clean ),
                [ 'vendor_id' => $vendor_id, 'reason' => $reason_clean, 'frozen_by' => get_current_user_id(), 'wallets_affected' => (int) $result ]
            );
        }

        return $result !== false;
    }

    /**
     * v2.9.116 WALLET-AUDIT P1-4: Descongela la billetera de un vendedor.
     *
     * Antes no existía el método unfreeze() en la clase Wallet — solo el handler
     * ajax_unfreeze_wallet en admin-payouts.php hacía el UPDATE directo. Ahora
     * centralizamos la lógica para que el action ltms_wallet_unfrozen se dispare
     * consistentemente y el log de seguridad se registre siempre.
     *
     * @param int    $vendor_id ID del vendedor.
     * @param string $reason    Motivo de la descongelación (para audit trail).
     * @return bool
     */
    public static function unfreeze( int $vendor_id, string $reason = '' ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_vendor_wallets';

        $reason_clean = $reason !== '' ? substr( sanitize_text_field( $reason ), 0, 500 ) : '';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $result = $wpdb->update(
            $table,
            [
                'is_frozen'    => 0,
                'freeze_reason' => null,
                'updated_at'   => LTMS_Utils::now_utc(),
            ],
            [ 'vendor_id' => $vendor_id ],
            [ '%d', '%s', '%s' ],
            [ '%d' ]
        );

        if ( $result !== false ) {
            do_action( 'ltms_wallet_unfrozen', $vendor_id, $reason_clean, get_current_user_id() );

            LTMS_Core_Logger::security(
                'WALLET_UNFROZEN',
                sprintf( 'Billetera del vendedor #%d DESCONGELADA. Motivo: %s', $vendor_id, $reason_clean ?: '(no especificado)' ),
                [ 'vendor_id' => $vendor_id, 'reason' => $reason_clean, 'unfrozen_by' => get_current_user_id(), 'wallets_affected' => (int) $result ]
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

    // â”€â”€ Métodos de instancia requeridos por WalletTest â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // Estos métodos encapsulan la lógica matemática pura del ledger.
    // No tocan la BD — son calculos puros, testeables sin WordPress.

    /**
     * Calcula el balance disponible: balance total menos fondos retenidos.
     *
     * @param float $balance Balance total del vendedor.
     * @param float $held    Fondos actualmente retenidos (hold).
     * @return float Balance disponible para operar.
     */
    public function get_available_balance( float $balance, float $held ): float {
        return max( 0.0, round( $balance - $held, 2 ) );
    }

    /**
     * Valida si un débito es posible dado el balance disponible actual.
     *
     * @param float $amount    Monto a debitar.
     * @param float $available Balance disponible.
     * @return bool True si el débito es válido (amount <= available).
     */
    public function validate_debit( float $amount, float $available ): bool {
        // v2.9.116 WALLET-AUDIT P1-7 FIX: reject NaN and INF values.
        // Before, is_nan($amount) || is_infinite($amount) would slip through the
        // $amount > 0 check (NaN comparisons always return false) and could cause
        // BCMath to produce unexpected results downstream.
        if ( is_nan( $amount ) || is_infinite( $amount ) || is_nan( $available ) || is_infinite( $available ) ) {
            return false;
        }
        return $amount > 0 && $amount <= $available;
    }

    /**
     * Valida si un monto de transacción es positivo y mayor a cero.
     *
     * @param float $amount Monto a validar.
     * @return bool True si el monto es válido (> 0).
     */
    public function validate_amount( float $amount ): bool {
        // v2.9.116 P1-7: reject NaN and INF.
        if ( is_nan( $amount ) || is_infinite( $amount ) ) {
            return false;
        }
        return $amount > 0;
    }

    /**
     * Valida si un hold es posible dado el balance disponible actual.
     *
     * @param float $hold_amount Monto a retener.
     * @param float $available   Balance disponible.
     * @return bool True si el hold es válido (hold_amount <= available).
     */
    public function validate_hold( float $hold_amount, float $available ): bool {
        // v2.9.116 P1-7: reject NaN and INF.
        if ( is_nan( $hold_amount ) || is_infinite( $hold_amount ) || is_nan( $available ) || is_infinite( $available ) ) {
            return false;
        }
        return $hold_amount > 0 && $hold_amount <= $available;
    }

    /**
     * Verifica si un tipo de transacción es válido para el sistema.
     *
     * @param string $type Tipo de transacción a verificar.
     * @return bool True si el tipo es reconocido por el sistema.
     */
    public function is_valid_transaction_type( string $type ): bool {
        return in_array( $type, [
            'credit',
            'debit',
            'hold',
            'release',
            'commission',
            'payout',
            'refund',
            'adjustment',
            // v2.9.116 WALLET-AUDIT P1-8: add the remaining valid types that
            // execute_transaction accepts but this validator was missing.
            // Before, 'fee', 'tax_withholding', and 'reversal' were rejected
            // by this method despite being valid in execute_transaction's switch.
            'fee',
            'tax_withholding',
            'reversal',
        ], true );
    }

    // ── CR-CRASH-1: Transaction journal for crash recovery ────────────────
    //
    // The journal is a best-effort out-of-band log of every wallet operation.
    // Before any credit/debit/hold/release, we write a 'pending' record. After
    // the operation completes (success or failure), we update it to 'completed'
    // or 'failed'. If PHP crashes mid-operation (OOM, fatal, kill -9, DB timeout),
    // the journal record stays in 'pending' forever.
    //
    // The recovery cron `ltms_recover_pending_wallet_txs` (registered in init())
    // runs hourly and finds journal records pending > 5 minutes. For each one:
    //   - If a matching wallet tx was committed (by idempotency_key / matching
    //     details), mark the journal as 'completed'.
    //   - If no matching tx found, mark as 'failed' and log a CRITICAL alert
    //     (phantom transaction — funds may be missing or duplicated).
    //
    // SCHEMA NOTE: The journal table is created lazily via dbDelta on first
    // use (see ensure_journal_table). A formal migration should be added to
    // includes/core/migrations/class-ltms-db-migrations.php in a future task.

    /** @var bool Ensures journal table existence is checked at most once per request. */
    private static bool $journal_table_ensured = false;

    /**
     * Crea la tabla lt_wallet_journal si no existe (lazy, idempotente vía dbDelta).
     *
     * @return void
     */
    private static function ensure_journal_table(): void {
        if ( self::$journal_table_ensured ) {
            return;
        }
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        global $wpdb;
        $table   = $wpdb->prefix . 'lt_wallet_journal';
        $charset = $wpdb->get_charset_collate();
        $sql     = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `operation`       VARCHAR(50)     NOT NULL,
            `wallet_id`       BIGINT UNSIGNED DEFAULT NULL,
            `vendor_id`       BIGINT UNSIGNED NOT NULL,
            `amount`          DECIMAL(15,2)   NOT NULL,
            `currency`        CHAR(3)         NOT NULL,
            `order_id`        BIGINT UNSIGNED DEFAULT NULL,
            `reference_hash`  VARCHAR(64)     DEFAULT NULL,
            `idempotency_key` VARCHAR(255)    DEFAULT NULL,
            `status`          ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending',
            `tx_id`           BIGINT UNSIGNED DEFAULT NULL,
            `error_message`   TEXT            DEFAULT NULL,
            `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_status_created` (`status`, `created_at`),
            KEY `idx_reference_hash` (`reference_hash`),
            KEY `idx_vendor_id` (`vendor_id`)
        ) {$charset}";
        dbDelta( $sql );
        self::$journal_table_ensured = true;
    }

    /**
     * Escribe un registro 'pending' al journal antes de ejecutar una operación de wallet.
     * Best-effort: si la escritura falla, retorna 0 (la operación continúa sin journal).
     *
     * @param int    $vendor_id       ID del vendedor.
     * @param string $type            Tipo de operación.
     * @param float  $amount          Monto.
     * @param string $currency        Moneda.
     * @param int    $order_id        Pedido asociado.
     * @param string $idempotency_key Clave de idempotencia.
     * @return int Journal ID (0 si falló).
     */
    private static function journal_pre(
        int    $vendor_id,
        string $type,
        float  $amount,
        string $currency,
        int    $order_id,
        string $idempotency_key
    ): int {
        self::ensure_journal_table();
        global $wpdb;
        $table = $wpdb->prefix . 'lt_wallet_journal';

        // reference_hash = sha256 of operation signature + nonce. Used by recovery cron
        // to deduplicate journal entries for the same logical operation.
        $reference_hash = hash( 'sha256', wp_json_encode( [
            'vendor_id'       => $vendor_id,
            'type'            => $type,
            'amount'          => $amount,
            'currency'        => $currency,
            'order_id'        => $order_id,
            'idempotency_key' => $idempotency_key ?: '',
            'nonce'           => bin2hex( random_bytes( 8 ) ),
        ] ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $inserted = $wpdb->insert(
            $table,
            [
                'operation'       => $type,
                'vendor_id'       => $vendor_id,
                'amount'          => $amount,
                'currency'        => $currency,
                'order_id'        => $order_id ?: null,
                'reference_hash'  => $reference_hash,
                'idempotency_key' => $idempotency_key ?: null,
                'status'          => 'pending',
                'created_at'      => LTMS_Utils::now_utc(),
            ],
            [ '%s', '%d', '%f', '%s', '%d', '%s', '%s', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            LTMS_Core_Logger::warning(
                'WALLET_JOURNAL_PRE_FAILED',
                'Could not write journal pre-record: ' . $wpdb->last_error,
                [ 'vendor_id' => $vendor_id, 'type' => $type, 'amount' => $amount, 'currency' => $currency ]
            );
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Actualiza un registro del journal a 'completed' o 'failed' tras la operación.
     * Best-effort: si la actualización falla, solo se loguea (no rompe la operación).
     *
     * @param int    $journal_id     ID del registro (0 si no se creó).
     * @param string $status         'completed' o 'failed'.
     * @param int    $tx_id          ID de la tx creada (si completed).
     * @param string $error_message  Mensaje de error (si failed).
     * @return void
     */
    private static function journal_post( int $journal_id, string $status, int $tx_id = 0, string $error_message = '' ): void {
        if ( ! $journal_id ) {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'lt_wallet_journal';

        $data   = [
            'status'     => $status,
            'updated_at' => LTMS_Utils::now_utc(),
        ];
        $format = [ '%s', '%s' ];

        if ( $tx_id > 0 ) {
            $data['tx_id'] = $tx_id;
            $format[]      = '%d';
        }
        if ( $error_message ) {
            $data['error_message'] = substr( $error_message, 0, 1000 );
            $format[]              = '%s';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $updated = $wpdb->update(
            $table,
            $data,
            [ 'id' => $journal_id, 'status' => 'pending' ],
            $format,
            [ '%d', '%s' ]
        );

        if ( false === $updated ) {
            LTMS_Core_Logger::warning(
                'WALLET_JOURNAL_POST_FAILED',
                'Could not update journal post-record: ' . $wpdb->last_error,
                [ 'journal_id' => $journal_id, 'status' => $status, 'tx_id' => $tx_id ]
            );
        }
    }

    /**
     * CR-CRASH-1: Recovery cron callback.
     *
     * Finds journal records stuck in 'pending' > 5 minutes (PHP crash mid-op).
     * For each one:
     *   - If a matching wallet tx was committed, mark journal as 'completed'.
     *   - If not, mark as 'failed' and log CRITICAL alert (phantom transaction).
     *
     * Hooked to: `ltms_recover_pending_wallet_txs` (hourly).
     *
     * @return void
     */
    public static function recover_pending_wallet_txs(): void {
        self::ensure_journal_table();
        global $wpdb;

        $journal_table = $wpdb->prefix . 'lt_wallet_journal';
        $tx_table      = $wpdb->prefix . 'lt_wallet_transactions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $pending = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$journal_table}` WHERE status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE) ORDER BY created_at ASC LIMIT 100"
            ),
            ARRAY_A
        );

        if ( empty( $pending ) ) {
            return;
        }

        $recovered_count = 0;
        $phantom_count   = 0;

        foreach ( $pending as $journal ) {
            $journal_id = (int) $journal['id'];

            // If idempotency_key was recorded, look up by reference column directly.
            $matching_tx_id = 0;
            if ( ! empty( $journal['idempotency_key'] ) ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $matching_tx_id = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM `{$tx_table}` WHERE `reference` = %s LIMIT 1",
                        $journal['idempotency_key']
                    )
                );
            }

            // Fallback: match by vendor_id + operation + amount + currency + time window.
            if ( ! $matching_tx_id ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $matching_tx_id = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM `{$tx_table}`
                         WHERE vendor_id = %d
                           AND type = %s
                           AND amount = %f
                           AND currency = %s
                           AND created_at >= DATE_SUB(%s, INTERVAL 1 MINUTE)
                           AND created_at <= DATE_ADD(%s, INTERVAL 10 MINUTE)
                         ORDER BY created_at ASC LIMIT 1",
                        $journal['vendor_id'],
                        $journal['operation'],
                        $journal['amount'],
                        $journal['currency'],
                        $journal['created_at'],
                        $journal['created_at']
                    )
                );
            }

            if ( $matching_tx_id > 0 ) {
                // Wallet tx was committed — journal is recovered (completed).
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update(
                    $journal_table,
                    [
                        'status'     => 'completed',
                        'tx_id'      => $matching_tx_id,
                        'updated_at' => LTMS_Utils::now_utc(),
                    ],
                    [ 'id' => $journal_id, 'status' => 'pending' ],
                    [ '%s', '%d', '%s' ],
                    [ '%d', '%s' ]
                );
                $recovered_count++;

                LTMS_Core_Logger::info(
                    'WALLET_JOURNAL_RECOVERED',
                    sprintf( 'Journal #%d recovered — matching wallet tx found (tx_id=%d).', $journal_id, $matching_tx_id ),
                    [ 'journal_id' => $journal_id, 'tx_id' => $matching_tx_id, 'vendor_id' => $journal['vendor_id'] ]
                );
            } else {
                // No matching tx — phantom. PHP crashed mid-op before the tx was committed.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update(
                    $journal_table,
                    [
                        'status'        => 'failed',
                        'error_message' => 'Phantom transaction: no matching wallet tx found after 5min grace period.',
                        'updated_at'    => LTMS_Utils::now_utc(),
                    ],
                    [ 'id' => $journal_id, 'status' => 'pending' ],
                    [ '%s', '%s', '%s' ],
                    [ '%d', '%s' ]
                );
                $phantom_count++;

                LTMS_Core_Logger::critical(
                    'WALLET_JOURNAL_PHANTOM',
                    sprintf(
                        'Journal #%d marked FAILED — phantom transaction (operation=%s, vendor=%d, amount=%s %s, order_id=%s). PHP likely crashed mid-operation.',
                        $journal_id,
                        $journal['operation'],
                        $journal['vendor_id'],
                        $journal['amount'],
                        $journal['currency'],
                        $journal['order_id'] ?? '(none)'
                    ),
                    [
                        'journal_id'      => $journal_id,
                        'vendor_id'       => $journal['vendor_id'],
                        'operation'       => $journal['operation'],
                        'amount'          => $journal['amount'],
                        'currency'        => $journal['currency'],
                        'order_id'        => $journal['order_id'] ?? null,
                        'idempotency_key' => $journal['idempotency_key'] ?? null,
                        'reference_hash'  => $journal['reference_hash'] ?? null,
                        'created_at'      => $journal['created_at'],
                    ]
                );
            }
        }

        if ( $recovered_count > 0 || $phantom_count > 0 ) {
            LTMS_Core_Logger::info(
                'WALLET_JOURNAL_RECOVERY_SUMMARY',
                sprintf( 'Journal recovery: %d recovered, %d phantom (CRITICAL).', $recovered_count, $phantom_count ),
                [ 'recovered' => $recovered_count, 'phantom' => $phantom_count ]
            );
        }
    }

}

// Alias de compatibilidad
class_alias( 'LTMS_Business_Wallet', 'LTMS_Wallet' );
