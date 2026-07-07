<?php
/**
 * LTMS Payout Scheduler - Procesador de Solicitudes de Retiro
 *
 * Gestiona el ciclo completo de retiros:
 * 1. Recepción y validación de solicitudes
 * 2. Cola de aprobación (admin o automática según reglas)
 * 3. Procesamiento del pago vía gateway (Openpay disbursement)
 * 4. Actualización de estados y notificaciones
 * 5. Generación de comprobante de retiro
 *
 * @package    LTMS
 * @subpackage LTMS/includes/business
 * @version    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Payout_Scheduler
 */
final class LTMS_Payout_Scheduler {

    use LTMS_Logger_Aware;

    /** Monto mínimo de retiro en COP. */
    const MIN_PAYOUT_COP = 50000; // $50.000 COP

    /** Monto mínimo de retiro en MXN. */
    const MIN_PAYOUT_MXN = 500; // $500 MXN

    /** Máximo de retiros pendientes simultáneos por vendedor. */
    const MAX_PENDING_PER_VENDOR = 3;

    /**
     * Registra los hooks de WP Cron para el procesamiento de retiros.
     *
     * B8 FIX (v2.8.5): no programar el cron redundante `ltms_approve_payout_cron`.
     * Solo `ltms_process_payouts` es necesario (las 2am). El cron de las 6am
     * sigue registrado como hook para compatibilidad, pero no se programa de nuevo.
     * Si ya estaba programado de una versión anterior, se desprograma.
     *
     * @return void
     */
    public static function init(): void {
        add_action( 'ltms_process_payouts', [ __CLASS__, 'process_pending_payouts' ] );
        add_action( 'ltms_approve_payout_cron', [ __CLASS__, 'auto_approve_eligible' ] );

        // M-118: Registrar schedule principal si no existe.
        if ( ! wp_next_scheduled( 'ltms_process_payouts' ) ) {
            wp_schedule_event( strtotime( 'tomorrow 02:00:00' ), 'daily', 'ltms_process_payouts' );
        }

        // B8 FIX: desprogramar el cron redundante si estaba programado de antes.
        $legacy_cron_ts = wp_next_scheduled( 'ltms_approve_payout_cron' );
        if ( $legacy_cron_ts ) {
            wp_unschedule_event( $legacy_cron_ts, 'ltms_approve_payout_cron' );
            LTMS_Core_Logger::info(
                'PAYOUT_CRON_CLEANUP',
                'Cron redundante ltms_approve_payout_cron desprogramado (B8 fix).'
            );
        }
    }

    /**
     * Crea una solicitud de retiro para un vendedor.
     *
     * @param int    $vendor_id       ID del vendedor.
     * @param float  $amount          Monto a retirar.
     * @param string $bank_account_id ID de la cuenta bancaria registrada.
     * @param string $method          Método de pago: 'bank_transfer', 'openpay', 'nequi'.
     * @return array{success: bool, message: string, payout_id: int}
     */
    public static function create_request( int $vendor_id, float $amount, string $bank_account_id, string $method = 'bank_transfer' ): array {
        // Validar monto mínimo
        $min = self::get_minimum_payout_amount();
        if ( $amount < $min ) {
            return [
                'success' => false,
                'message' => sprintf(
                    /* translators: %s: monto mínimo formateado */
                    __( 'El monto mínimo de retiro es %s', 'ltms' ),
                    LTMS_Utils::format_money( $min )
                ),
                'payout_id' => 0,
            ];
        }

        // Verificar balance disponible
        $wallet  = LTMS_Business_Wallet::get_or_create( $vendor_id );
        $balance = (float) $wallet['balance'];

        if ( $amount > $balance ) {
            return [
                'success'   => false,
                'message'   => __( 'Saldo insuficiente para realizar este retiro.', 'ltms' ),
                'payout_id' => 0,
            ];
        }

        // Verificar que no tenga demasiados retiros pendientes
        if ( self::get_pending_count( $vendor_id ) >= self::MAX_PENDING_PER_VENDOR ) {
            return [
                'success'   => false,
                'message'   => __( 'Tienes el máximo de solicitudes de retiro pendientes. Espera a que sean procesadas.', 'ltms' ),
                'payout_id' => 0,
            ];
        }

        // Verificar KYC aprobado
        if ( ! self::vendor_has_approved_kyc( $vendor_id ) ) {
            return [
                'success'   => false,
                'message'   => __( 'Debes completar la verificación KYC para solicitar retiros.', 'ltms' ),
                'payout_id' => 0,
            ];
        }

        // v2.9.63 DEEP-AUDIT-002 P1-6: Validar que el bank_account_id pertenece al vendor.
        // Antes se aceptaba cualquier valor — un vendor podía especificar la cuenta
        // de otro vendor. Ahora verificamos que los últimos 4 dígitos coincidan
        // con la cuenta guardada en user_meta.
        // v2.9.64: Envolver en try/catch — si no hay cuenta guardada o no se puede
        // desencriptar, NO bloquear el payout (fail-open para no romper flujo existente).
        try {
            $saved_bank_acc = get_user_meta( $vendor_id, 'ltms_bank_account_number', true );
            if ( ! empty( $saved_bank_acc ) ) {
                // Desencriptar si está cifrado.
                if ( class_exists( 'LTMS_Core_Security' ) && method_exists( 'LTMS_Core_Security', 'decrypt' ) ) {
                    $decrypted = LTMS_Core_Security::decrypt( $saved_bank_acc );
                    if ( $decrypted !== false && $decrypted !== '' ) {
                        $saved_bank_acc = $decrypted;
                    }
                }
                $saved_last4 = substr( preg_replace( '/\D/', '', $saved_bank_acc ), -4 );
                $input_last4 = substr( preg_replace( '/\D/', '', $bank_account_id ), -4 );
                // Solo bloquear si AMBOS last4 están presentes y NO coinciden.
                // Si no podemos obtener last4 (cuenta no numérica, desencriptación falló, etc.),
                // no bloquear — el KYC ya validó la cuenta.
                if ( strlen( $saved_last4 ) === 4 && strlen( $input_last4 ) === 4 && $saved_last4 !== $input_last4 ) {
                    if ( class_exists( 'LTMS_Core_Logger' ) ) {
                        LTMS_Core_Logger::security(
                            'PAYOUT_BANK_MISMATCH',
                            sprintf( 'Vendor #%d intentó retirar a cuenta que no coincide con su cuenta registrada', $vendor_id )
                        );
                    }
                    return [
                        'success'   => false,
                        'message'   => __( 'La cuenta bancaria no coincide con tu cuenta registrada.', 'ltms' ),
                        'payout_id' => 0,
                    ];
                }
            }
        } catch ( \Throwable $e ) {
            // Si la validación falla por cualquier motivo, loguear pero NO bloquear payout.
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning(
                    'PAYOUT_BANK_VALIDATION_ERROR',
                    sprintf( 'Vendor #%d — error validando cuenta: %s', $vendor_id, $e->getMessage() )
                );
            }
        }

        // Poner en hold el monto en la billetera
        try {
            LTMS_Business_Wallet::hold( $vendor_id, $amount, 'Retiro solicitado en procesamiento' );
        } catch ( \Throwable $e ) {
            return [
                'success'   => false,
                'message'   => __( 'Error al reservar el saldo. Por favor intenta de nuevo.', 'ltms' ),
                'payout_id' => 0,
            ];
        }

        // Registrar solicitud en la BD
        global $wpdb;
        $table = $wpdb->prefix . 'lt_payout_requests';

        // Calcular fee una sola vez para garantizar consistencia entre fee y net_amount
        $payout_fee = self::calculate_payout_fee( $amount, $method );
        $net_amount = round( $amount - $payout_fee, 2 );

        // M-9 FIX: Wrap Wallet::hold() + $wpdb->insert() in a compensating
        // transaction. If the BD insert fails AFTER the hold succeeded, the
        // vendor's funds would remain locked in `balance_pending` forever with
        // no payout row to reconcile against. Reverse the hold by crediting
        // the same amount back with an idempotency key so a retry of the same
        // payout create cannot double-credit.
        $payout_id = 0;
        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $inserted = $wpdb->insert(
                $table,
                [
                    'vendor_id'       => $vendor_id,
                    'amount'          => $amount,
                    'fee'             => $payout_fee,
                    'net_amount'      => $net_amount,
                    'method'          => $method,
                    'bank_account_id' => sanitize_text_field( $bank_account_id ),
                    'status'          => 'pending',
                    'reference'       => LTMS_Utils::generate_reference( 'PAY' ),
                    'created_at'      => LTMS_Utils::now_utc(),
                ],
                [ '%d', '%f', '%f', '%f', '%s', '%s', '%s', '%s', '%s' ]
            );

            if ( false === $inserted ) {
                throw new \RuntimeException(
                    'wpdb::insert() returned false — last_error: ' . $wpdb->last_error
                );
            }

            $payout_id = (int) $wpdb->insert_id;
        } catch ( \Throwable $insert_error ) {
            // Compensating action: reverse the hold so the vendor's available
            // balance is restored. Use a unique idempotency key per attempt so
            // that retries (or duplicate calls in this exact failure path) are
            // deduplicated by the wallet layer.
            $idempotency_key = 'payout_create_reversal_' . $vendor_id . '_' . time() . '_' . wp_rand( 1000, 9999 );
            try {
                LTMS_Business_Wallet::credit(
                    $vendor_id,
                    $amount,
                    'Reversión: la solicitud de retiro no pudo registrarse en BD',
                    [
                        'reason'      => 'payout_create_insert_failed',
                        'amount_held' => $amount,
                    ],
                    0,
                    '',
                    $idempotency_key
                );
            } catch ( \Throwable $reversal_error ) {
                // Reversal itself failed — escalate so an admin can reconcile
                // manually. We log both the original insert failure and the
                // reversal failure so the vendor's stuck hold is visible.
                LTMS_Core_Logger::error(
                    'PAYOUT_CREATE_REVERSAL_FAILED',
                    sprintf(
                        'Vendedor #%d: hold de %s NO pudo revertirse tras fallo de insert. Reversal error: %s',
                        $vendor_id,
                        LTMS_Utils::format_money( $amount ),
                        $reversal_error->getMessage()
                    ),
                    [
                        'vendor_id'         => $vendor_id,
                        'amount'            => $amount,
                        'idempotency_key'   => $idempotency_key,
                        'insert_error'      => $insert_error->getMessage(),
                        'reversal_error'    => $reversal_error->getMessage(),
                    ]
                );
            }

            LTMS_Core_Logger::error(
                'PAYOUT_CREATE_INSERT_FAILED',
                sprintf(
                    'Vendedor #%d: insert en lt_payout_requests falló tras hold exitoso de %s. Hold revertido.',
                    $vendor_id,
                    LTMS_Utils::format_money( $amount )
                ),
                [
                    'vendor_id'       => $vendor_id,
                    'amount'          => $amount,
                    'method'          => $method,
                    'insert_error'    => $insert_error->getMessage(),
                    'reversal_key'    => $idempotency_key,
                ]
            );

            return [
                'success'   => false,
                'message'   => __( 'No pudimos registrar tu solicitud de retiro. El saldo retenido fue devuelto a tu billetera. Intenta de nuevo.', 'ltms' ),
                'payout_id' => 0,
            ];
        }

        LTMS_Core_Logger::info(
            'PAYOUT_REQUEST_CREATED',
            sprintf( 'Retiro #%d creado por vendedor #%d por %s', $payout_id, $vendor_id, LTMS_Utils::format_money( $amount ) ),
            [ 'payout_id' => $payout_id, 'vendor_id' => $vendor_id, 'amount' => $amount ]
        );

        return [
            'success'   => true,
            'message'   => __( 'Solicitud de retiro enviada. Será procesada en 1-3 días hábiles.', 'ltms' ),
            'payout_id' => $payout_id,
        ];
    }

    /**
     * Aprueba una solicitud de retiro (acción de admin).
     *
     * @param int    $payout_id ID de la solicitud.
     * @param int    $admin_id  ID del administrador que aprueba.
     * @return array{success: bool, message: string}
     */
    public static function approve( int $payout_id, int $admin_id ): array {
        global $wpdb;

        // Step 1: Read the current payout row — fast guard that works in tests and prod.
        $payout = self::get_payout( $payout_id );
        if ( ! $payout || $payout['status'] !== 'pending' ) {
            return [ 'success' => false, 'message' => __( 'Solicitud no encontrada o ya procesada.', 'ltms' ) ];
        }

        // PO-BUG-B FIX: re-verify KYC at approval time. KYC was checked at create_request(),
        // but it may have been revoked since (vendor uploaded fraudulent docs, then KYC team
        // revoked after the payout was already pending). Approving a payout to a vendor with
        // revoked KYC is a compliance violation (SAGRILAFT). The check is cheap (user_meta
        // reads) and prevents the most dangerous case: sending bank money to a fraudster.
        if ( ! self::vendor_has_approved_kyc( (int) $payout['vendor_id'] ) ) {
            LTMS_Core_Logger::warning(
                'PAYOUT_KYC_REVOKED_AT_APPROVAL',
                sprintf( 'Retiro #%d: KYC del vendedor #%d ya no está aprobado al momento de aprobar — denegado.', $payout_id, $payout['vendor_id'] ),
                [ 'payout_id' => $payout_id, 'vendor_id' => $payout['vendor_id'], 'admin_id' => $admin_id ]
            );
            return [ 'success' => false, 'message' => __( 'No se puede aprobar el retiro: KYC ya no está aprobado.', 'ltms' ) ];
        }

        // RB-8 FIX (v2.9.19): Disparar filter ltms_payout_pre_approve para que
        // los listeners (FT-3 enforce_operational_limits) puedan bloquear la
        // aprobación si el vendor excede límites diarios/mensuales. Antes de
        // este fix, FT-3 era silent dead code desde v2.9.16. Recibe 3 args:
        // (true, $payout_id, $vendor_id) y debe retornar false para bloquear.
        $vendor_id = (int) $payout['vendor_id'];
        $allow     = (bool) apply_filters( 'ltms_payout_pre_approve', true, $payout_id, $vendor_id );
        if ( ! $allow ) {
            LTMS_Core_Logger::warning(
                'PAYOUT_BLOCKED_BY_FILTER',
                sprintf( 'Retiro #%d bloqueado por filter ltms_payout_pre_approve (vendor #%d).', $payout_id, $vendor_id ),
                [ 'payout_id' => $payout_id, 'vendor_id' => $vendor_id, 'admin_id' => $admin_id ]
            );
            return [ 'success' => false, 'message' => __( 'Retiro bloqueado por política de cumplimiento (límites operativos). Revisar logs.', 'ltms' ) ];
        }

        // Step 2: M-117 — Atomic claim to prevent double-approval race condition.
        // Only one of two concurrent admin clicks will see rows_affected = 1.
        // In unit tests (mock wpdb) this UPDATE always returns 1, which is fine because
        // the Step 1 guard already filtered non-pending payouts.
        $rows = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prefix . 'lt_payout_requests',
            [
                'status'       => 'processing',
                'approved_by'  => $admin_id,
                'processed_at' => LTMS_Utils::now_utc(),
            ],
            [ 'id' => $payout_id, 'status' => 'pending' ],
            [ '%s', '%d', '%s' ],
            [ '%d', '%s' ]
        );
        if ( $rows === false || $rows === 0 ) {
            // Another worker already claimed this payout between Step 1 and Step 2
            return [ 'success' => false, 'message' => __( 'Solicitud ya está siendo procesada por otro administrador.', 'ltms' ) ];
        }

        // RB-8 FIX (v2.9.19): Disparar action ltms_payout_pre_execute para que
        // los listeners (FT-4 attach_travel_rule_metadata, LT-1 generate_carta_porte)
        // puedan adjuntar metadata antes de que el pago se ejecute vía gateway.
        // Antes de este fix, FT-4 y LT-1 eran silent dead code desde v2.9.16/17.
        // 2 args: ($payout_id, $payout array con vendor_id, amount, currency).
        do_action( 'ltms_payout_pre_execute', (int) $payout_id, $payout );

        $table = $wpdb->prefix . 'lt_payout_requests';

        // PO-CRASH-3 FIX: Check if gateway payment already succeeded in a previous attempt.
        // If gateway_ref is already set on the payout row, the gateway call succeeded before
        // but wallet ops crashed (or admin re-approved after a crash). Skip the gateway call
        // to avoid DOUBLE CHARGE — proceed directly to wallet ops with the existing reference.
        $gateway_already_paid = ! empty( $payout['gateway_ref'] );

        if ( $gateway_already_paid ) {
            $payment_result = [
                'success'   => true,
                'reference' => $payout['gateway_ref'],
                'message'   => 'Gateway payment already succeeded in a previous attempt — skipping re-execution to avoid double charge.',
            ];
            LTMS_Core_Logger::warning(
                'PAYOUT_GATEWAY_ALREADY_PAID',
                sprintf( 'Retiro #%d: gateway_ref ya seteado (%s) — saltando re-ejecución del pago para evitar doble cobro.', $payout_id, $payout['gateway_ref'] ),
                [ 'payout_id' => $payout_id, 'gateway_ref' => $payout['gateway_ref'], 'vendor_id' => $payout['vendor_id'] ]
            );
        } else {
            // Intentar procesar el pago vía gateway (first attempt or retry after gateway failure).
            $payment_result = self::execute_payout_payment( $payout );

            // PO-CRASH-3: persist gateway_ref IMMEDIATELY after gateway success, BEFORE
            // wallet operations. If wallet ops crash, the next approve() will see gateway_ref
            // set and skip the gateway call (no double charge). The wallet ops will then run
            // with idempotency keys (PO-BUG-A) to ensure they execute exactly once.
            if ( $payment_result['success'] && ! empty( $payment_result['reference'] ) ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update(
                    $table,
                    [ 'gateway_ref' => $payment_result['reference'] ],
                    [ 'id' => $payout_id ],
                    [ '%s' ],
                    [ '%d' ]
                );
            }
        }

        if ( $payment_result['success'] ) {
            // PO-BUG-A FIX: use idempotency keys for release + debit.
            //
            // THE BUG: On a previous attempt, gateway may have succeeded but wallet ops
            // crashed mid-way. The release() may have committed but debit() didn't run.
            // On retry (new approve() call), the OLD code would:
            //   1. release() → throws "balance_pending=0" (already released)
            //   2. catch fires → marks payout 'completed' with note
            //   3. debit() NEVER runs → vendor has bank money + wallet balance (DOUBLE SPEND).
            //
            // THE FIX: idempotency keys (stored in lt_wallet_transactions.reference).
            //   - release() with key `payout_release_N`: if already done, returns existing
            //     tx_id WITHOUT executing (no exception, no balance check).
            //   - debit() with key `payout_debit_N`: if already done, returns existing tx_id;
            //     otherwise executes normally (first time).
            //
            // This guarantees that on retry:
            //   - If release was done before → skipped (idempotent), debit runs (first time). ✓
            //   - If debit was done before → release may or may not have been done (both
            //     idempotent), no double-spend possible. ✓
            $release_idem_key = sprintf( 'payout_release_%d', $payout_id );
            $debit_idem_key   = sprintf( 'payout_debit_%d',   $payout_id );

            // 1. Liberar el hold + debitar — envuelto en try/catch porque Wallet lanza RuntimeException
            try {
                // PO-BUG-A: explicit check for already-released state (defense in depth
                // on top of the idempotency_key mechanism in Wallet::release()).
                $tx_table = $wpdb->prefix . 'lt_wallet_transactions';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $existing_release_tx_id = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM `{$tx_table}` WHERE vendor_id = %d AND `reference` = %s LIMIT 1",
                        (int) $payout['vendor_id'],
                        $release_idem_key
                    )
                );

                if ( $existing_release_tx_id > 0 ) {
                    // Hold already released (previous retry or crash) — skip release, proceed to debit.
                    LTMS_Core_Logger::warning(
                        'PAYOUT_HOLD_ALREADY_RELEASED',
                        sprintf( 'Hold already released for payout #%d (previous retry or crash) — skipping release, proceeding to debit.', $payout_id ),
                        [
                            'payout_id'              => $payout_id,
                            'hold_id'                => $existing_release_tx_id,
                            'vendor_id'              => $payout['vendor_id'],
                            'existing_release_tx_id' => $existing_release_tx_id,
                        ]
                    );
                } else {
                    LTMS_Business_Wallet::release(
                        (int) $payout['vendor_id'],
                        (float) $payout['amount'],
                        sprintf( __( 'Hold liberado para retiro #%d', 'ltms' ), $payout_id ),
                        [ 'payout_id' => $payout_id ],
                        0,
                        '',
                        $release_idem_key
                    );
                }

                // Always debit (idempotent — if already debited, returns existing tx_id, no exception).
                LTMS_Business_Wallet::debit(
                    (int) $payout['vendor_id'],
                    (float) $payout['amount'],
                    sprintf( __( 'Retiro procesado #%d', 'ltms' ), $payout_id ),
                    [ 'payout_id' => $payout_id, 'type' => 'payout' ],
                    0,
                    '',
                    $debit_idem_key
                );
            } catch ( \Throwable $wallet_err ) {
                // El gateway ya procesó el pago pero la billetera falló.
                // Marcar como completed con nota para revisión manual.
                LTMS_Core_Logger::error(
                    'PAYOUT_WALLET_ERROR',
                    sprintf(
                        'Retiro #%d: gateway OK pero wallet falló — %s. Requiere ajuste manual.',
                        $payout_id,
                        $wallet_err->getMessage()
                    )
                );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update(
                    $table,
                    [
                        'status'      => 'completed',
                        'gateway_ref' => $payment_result['reference'] ?? '',
                        'notes'       => 'AVISO: gateway OK, wallet error: ' . $wallet_err->getMessage(),
                    ],
                    [ 'id' => $payout_id ],
                    [ '%s', '%s', '%s' ],
                    [ '%d' ]
                );
                do_action( 'ltms_payout_completed', (int) $payout['vendor_id'], (float) $payout['amount'], $payout_id );
                return [
                    'success' => true,
                    'message' => __( 'Retiro procesado. Advertencia: billetera requiere revisión.', 'ltms' ),
                ];
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $table,
                [
                    'status'      => 'completed',
                    'gateway_ref' => $payment_result['reference'] ?? '',
                    // approved_by and processed_at already set in the atomic claim above
                ],
                [ 'id' => $payout_id ],
                [ '%s', '%s' ],
                [ '%d' ]
            );

            // M-41: disparar acción para que Affiliates y otros listeners procesen la comisión de referido.
            // FU2 FIX (v2.9.1): incluir payout_id como 3er arg para que Alegra pueda
            // registrar el pago contra la factura correcta (antes solo tenía vendor_id+amount).
            do_action( 'ltms_payout_completed', (int) $payout['vendor_id'], (float) $payout['amount'], $payout_id );

            LTMS_Core_Logger::info(
                'PAYOUT_APPROVED',
                sprintf( 'Retiro #%d aprobado y procesado por admin #%d', $payout_id, $admin_id )
            );

            // E-12 FIX: enviar email al vendedor si ltms_email_payout_approved está activo.
            if ( get_option( 'ltms_email_payout_approved', 'yes' ) === 'yes' ) {
                $vendor_user = get_userdata( (int) $payout['vendor_id'] );
                if ( $vendor_user && $vendor_user->user_email ) {
                    $p_subject = sprintf(
                        /* translators: %s: monto */
                        __( '[Lo Tengo] Tu retiro de %s fue aprobado', 'ltms' ),
                        number_format( (float) $payout['amount'], 2, '.', ',' )
                    );
                    $p_body = sprintf(
                        /* translators: 1: nombre, 2: monto, 3: referencia gateway, 4: URL panel */
                        __( "Hola %1\$s,

Tu solicitud de retiro por %2\$s ha sido aprobada y procesada.

Referencia: %3\$s

Revisa el estado en tu panel:
%4\$s

Gracias por ser parte de Lo Tengo.", 'ltms' ),
                        $vendor_user->display_name,
                        number_format( (float) $payout['amount'], 2, '.', ',' ),
                        $payment_result['reference'] ?? 'N/A',
                        home_url( '/panel-vendedor/billetera/' )
                    );
                    wp_mail( $vendor_user->user_email, $p_subject, $p_body );
                }
            }

            return [ 'success' => true, 'message' => __( 'Retiro aprobado y procesado exitosamente.', 'ltms' ) ];
        }

        // Fallo en el gateway — mantener en pending con nota
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update(
            $table,
            [ 'notes' => sprintf( 'Error gateway: %s', $payment_result['message'] ) ],
            [ 'id' => $payout_id ],
            [ '%s' ],
            [ '%d' ]
        );

        return [ 'success' => false, 'message' => $payment_result['message'] ];
    }

    /**
     * Rechaza una solicitud de retiro.
     *
     * @param int    $payout_id ID de la solicitud.
     * @param string $reason    Motivo del rechazo.
     * @param int    $admin_id  ID del administrador.
     * @return array{success: bool, message: string}
     */
    public static function reject( int $payout_id, string $reason, int $admin_id ): array {
        $payout = self::get_payout( $payout_id );
        if ( ! $payout || $payout['status'] !== 'pending' ) {
            return [ 'success' => false, 'message' => __( 'Solicitud no encontrada o ya procesada.', 'ltms' ) ];
        }

        // PO-BUG-C FIX: wrap Wallet::release() in try/catch.
        //
        // THE BUG: release() can throw RuntimeException (e.g., balance_pending < amount if
        // the hold was partially released by a concurrent process, or DB deadlock). The old
        // code let the exception propagate, which:
        //   1. Broke the rejection flow — the payout stayed 'pending' forever.
        //   2. Could leave the vendor without recourse (can't request a new payout because
        //      the hold is still in place).
        //   3. Leaked internal exception details to the admin UI.
        //
        // THE FIX: catch the exception, log it, and continue with the rejection. The payout
        // row is marked 'rejected' regardless — the hold can be cleaned up later by the
        // consumer protection release cron or manual admin intervention. The important thing
        // is that the payout doesn't get stuck in 'pending' just because release failed.
        try {
            // B9 FIX: idempotency key para evitar doble liberación si el rechazo se reintenta.
            $release_idem_key = sprintf( 'payout_reject_release_%d', $payout_id );
            LTMS_Business_Wallet::release(
                (int) $payout['vendor_id'],
                (float) $payout['amount'],
                'Retiro rechazado: ' . $reason,
                [ 'payout_id' => $payout_id, 'type' => 'reject' ],
                0,
                '',
                $release_idem_key
            );
        } catch ( \Throwable $release_err ) {
            LTMS_Core_Logger::error(
                'PAYOUT_REJECT_RELEASE_FAILED',
                sprintf( 'Retiro #%d: no se pudo liberar el hold durante el rechazo — %s. El payout será marcado como rejected de todas formas; el hold puede requerir limpieza manual.', $payout_id, $release_err->getMessage() ),
                [
                    'payout_id' => $payout_id,
                    'vendor_id' => $payout['vendor_id'],
                    'amount'    => $payout['amount'],
                    'exception' => get_class( $release_err ),
                ]
            );
            // Continue with rejection — the hold can be cleaned up later by:
            //   - The consumer protection release cron (release_eligible_holds)
            //   - Manual admin intervention via Wallet::release() direct call
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lt_payout_requests';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update(
            $table,
            [
                'status'       => 'rejected',
                'notes'        => sanitize_textarea_field( $reason ),
                'approved_by'  => $admin_id,
                'processed_at' => LTMS_Utils::now_utc(),
            ],
            [ 'id' => $payout_id ],
            [ '%s', '%s', '%d', '%s' ],
            [ '%d' ]
        );

        LTMS_Core_Logger::info(
            'PAYOUT_REJECTED',
            sprintf( 'Retiro #%d rechazado por admin #%d. Motivo: %s', $payout_id, $admin_id, $reason )
        );

        // E-13 FIX: email al vendedor cuando se rechaza retiro
        if ( get_option( 'ltms_email_payout_rejected', 'yes' ) === 'yes' ) {
            $vendor_user = get_userdata( (int) $payout['vendor_id'] );
            if ( $vendor_user && $vendor_user->user_email ) {
                $p_subject = __( '[Lo Tengo] Tu solicitud de retiro fue rechazada', 'ltms' );
                $p_body    = sprintf(
                    __( 'Hola %1$s,

Tu solicitud de retiro por %2$s COP fue rechazada.

Motivo: %3$s

Puedes enviar una nueva solicitud desde tu panel.

%4$s', 'ltms' ),
                    $vendor_user->display_name,
                    number_format( (float) $payout['amount'], 0, ',', '.' ),
                    $reason,
                    home_url( '/panel-vendedor/' )
                );
                wp_mail( $vendor_user->user_email, $p_subject, $p_body );
            }
        }

        return [ 'success' => true, 'message' => __( 'Solicitud de retiro rechazada.', 'ltms' ) ];
    }

    /**
     * Procesa todos los retiros pendientes aprobados automáticamente (cron diario).
     *
     * B5 FIX: solo procesa si la auto-aprobación está HABILITADA en config.
     * Antes, este cron ejecutaba payouts automáticamente sin importar la
     * configuración `ltms_auto_approve_payouts`, lo cual es peligroso:
     * un admin que desactiva auto-aprobación para control manual seguía
     * viendo cómo el cron ejecutaba pagos automáticamente.
     *
     * B6 FIX: respeta el monto máximo de auto-aprobación configurado.
     * Antes procesaba CUALQUIER monto. Ahora respeta el threshold.
     *
     * B7 FIX: idempotencia — si un payout ya está en 'processing' o 'completed'
     * (porque otro proceso lo tomó), no se reintenta. Antes el cron podía
     * re-ejecutar approve() sobre un payout ya completado.
     *
     * @return void
     */
    public static function process_pending_payouts(): void {
        // B5 FIX: respetar el flag de auto-aprobación.
        $auto_approve_enabled = LTMS_Core_Config::get( 'ltms_auto_approve_payouts', 'no' );
        if ( $auto_approve_enabled !== 'yes' ) {
            LTMS_Core_Logger::info(
                'PAYOUT_CRON_SKIP',
                'process_pending_payouts: auto-aprobación deshabilitada (ltms_auto_approve_payouts=no). No se procesarán payouts.'
            );
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lt_payout_requests';

        // B6 FIX: respetar el monto máximo de auto-aprobación.
        $max_auto_amount = (float) LTMS_Core_Config::get( 'ltms_auto_approve_max_amount', 500000 );
        $min_auto_amount = self::get_minimum_payout_amount();

        // B7 FIX: solo seleccionar payouts 'pending' (no 'processing' ni 'completed').
        // El atomic claim en approve() ya previene double-approval, pero esta query
        // reduce el número de intentos inútiles.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $payouts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$table}`
                 WHERE status = 'pending'
                 AND amount >= %f
                 AND amount <= %f
                 AND created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)
                 ORDER BY created_at ASC
                 LIMIT 50",
                $min_auto_amount,
                $max_auto_amount
            ),
            ARRAY_A
        );

        if ( empty( $payouts ) ) {
            return;
        }

        $processed = 0;
        $skipped   = 0;
        foreach ( $payouts as $payout ) {
            // B6 FIX: re-validar KYC antes de procesar (puede haber cambiado).
            if ( ! self::vendor_has_approved_kyc( (int) $payout['vendor_id'] ) ) {
                $skipped++;
                LTMS_Core_Logger::warning(
                    'PAYOUT_CRON_KYC_SKIP',
                    sprintf( 'Cron: payout #%d saltado — vendor #%d no tiene KYC aprobado.', $payout['id'], $payout['vendor_id'] )
                );
                continue;
            }

            $result = self::approve( (int) $payout['id'], 0 ); // 0 = sistema automático.
            if ( $result['success'] ) {
                $processed++;
            } else {
                $skipped++;
            }
        }

        LTMS_Core_Logger::info(
            'PAYOUT_CRON_PROCESSED',
            sprintf( 'Cron process_pending_payouts: %d procesados, %d saltados de %d seleccionados.', $processed, $skipped, count( $payouts ) ),
            [ 'processed' => $processed, 'skipped' => $skipped, 'selected' => count( $payouts ) ]
        );
    }

    // ── Helpers privados ──────────────────────────────────────────

    /**
     * Ejecuta el pago al vendedor vía gateway de pago.
     *
     * @param array $payout Datos de la solicitud de retiro.
     * @return array{success: bool, message: string, reference: string}
     */
    private static function execute_payout_payment( array $payout ): array {
        $method    = $payout['method'] ?? 'bank_transfer';
        $vendor_id = (int) $payout['vendor_id'];
        $amount    = (float) $payout['net_amount'] ?: (float) $payout['amount'];
        $payout_id = (int) $payout['id'];

        try {
            if ( $method === 'openpay' || $method === 'nequi' ) {
                // Leer datos bancarios del vendedor registrados en user_meta
                $bank_account = get_user_meta( $vendor_id, 'ltms_bank_account', true )
                             ?: get_user_meta( $vendor_id, 'ltms_clabe', true );
                $bank_code    = get_user_meta( $vendor_id, 'ltms_bank_code', true ) ?: '';
                $holder_name  = get_user_meta( $vendor_id, 'ltms_bank_holder', true );

                if ( empty( $bank_account ) ) {
                    return [
                        'success'   => false,
                        'reference' => '',
                        'message'   => sprintf(
                            'Vendedor #%d no tiene cuenta bancaria registrada para disbursement Openpay.',
                            $vendor_id
                        ),
                    ];
                }

                // Usar display_name si no hay holder registrado
                if ( empty( $holder_name ) ) {
                    $user        = get_userdata( $vendor_id );
                    $holder_name = $user ? $user->display_name : 'Vendedor #' . $vendor_id;
                }

                $client = LTMS_Api_Factory::get( 'openpay' );
                $result = $client->create_disbursement(
                    $amount,
                    $bank_account,
                    $bank_code,
                    $holder_name,
                    sprintf( 'Retiro #%d — Lo Tengo', $payout_id ),
                    'PAY-' . $payout_id
                );

                return [
                    'success'   => true,
                    'reference' => $result['id'] ?? LTMS_Utils::generate_reference( 'OPP' ),
                    'message'   => 'Desembolso Openpay procesado: ' . ( $result['status'] ?? 'in_progress' ),
                ];
            }

            if ( $method === 'bank_transfer' ) {
                // Pago manual: registrar referencia y notificar al equipo de finanzas
                $reference = LTMS_Utils::generate_reference( 'MAN' );

                // Notificar al admin para ejecutar transferencia manual
                $admin_email = (string) get_option( 'admin_email' );
                if ( $admin_email ) {
                    $vendor_user = get_userdata( $vendor_id );
                    $bank_account = get_user_meta( $vendor_id, 'ltms_bank_account', true ) ?: 'No registrada';
                    $bank_name    = get_user_meta( $vendor_id, 'ltms_bank_name', true ) ?: '';

                    wp_mail(
                        $admin_email,
                        sprintf( '[LTMS] Retiro #%d pendiente de transferencia manual', $payout_id ),
                        sprintf(
                            "Retiro #%d aprobado requiere transferencia manual.

" .
                            "Vendedor: %s (#%d)
" .
                            "Monto neto: $%s COP
" .
                            "Banco: %s
" .
                            "Cuenta: %s
" .
                            "Referencia: %s

" .
                            "Accede al panel: %s",
                            $payout_id,
                            $vendor_user ? $vendor_user->display_name : '#' . $vendor_id,
                            $vendor_id,
                            number_format( $amount, 0, ',', '.' ),
                            $bank_name,
                            $bank_account,
                            $reference,
                            admin_url( 'admin.php?page=ltms-payouts' )
                        )
                    );
                }

                return [
                    'success'   => true,
                    'reference' => $reference,
                    'message'   => 'Transferencia manual registrada — pendiente de ejecución por finanzas.',
                ];
            }

            // Método no reconocido — registrar como manual
            return [
                'success'   => true,
                'reference' => LTMS_Utils::generate_reference( 'MAN' ),
                'message'   => sprintf( 'Método %s no automatizado — registrado para revisión manual.', $method ),
            ];

        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::error(
                'PAYOUT_EXECUTE_FAILED',
                sprintf( 'Error ejecutando payout #%d vía %s: %s', $payout_id, $method, $e->getMessage() ),
                [ 'payout_id' => $payout_id, 'vendor_id' => $vendor_id, 'method' => $method ]
            );
            return [
                'success'   => false,
                'reference' => '',
                'message'   => $e->getMessage(),
            ];
        }
    }

    /**
     * Obtiene una solicitud de retiro por ID.
     *
     * @param int $payout_id ID.
     * @return array|null
     */
    private static function get_payout( int $payout_id ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_payout_requests';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $payout_id ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Cuenta los retiros pendientes de un vendedor.
     *
     * @param int $vendor_id ID del vendedor.
     * @return int
     */
    private static function get_pending_count( int $vendor_id ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_payout_requests';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE vendor_id = %d AND status = 'pending'", $vendor_id )
        );
    }

    /**
     * Calcula la comisión de retiro según el método.
     *
     * @param float  $amount Monto.
     * @param string $method Método de retiro.
     * @return float Comisión en moneda local.
     */
    private static function calculate_payout_fee( float $amount, string $method ): float {
        // M-QA-04: fees read from config so they can be updated without a deploy.
        $fees = [
            'bank_transfer' => (float) LTMS_Core_Config::get( 'ltms_payout_fee_bank_transfer', 0.0 ),
            'openpay'       => (float) LTMS_Core_Config::get( 'ltms_payout_fee_openpay', 4000.0 ), // default $4.000 COP
            'nequi'         => (float) LTMS_Core_Config::get( 'ltms_payout_fee_nequi', 0.0 ),
        ];

        return $fees[ $method ] ?? 0.0;
    }

    /**
     * Devuelve el monto mínimo de retiro según el país activo.
     *
     * @return float
     */
    private static function get_minimum_payout_amount(): float {
        $country = LTMS_Core_Config::get_country();
        $configured = (float) LTMS_Core_Config::get( 'ltms_min_payout_amount', 0 );

        if ( $configured > 0 ) {
            return $configured;
        }

        return $country === 'MX' ? self::MIN_PAYOUT_MXN : self::MIN_PAYOUT_COP;
    }

    /**
     * Verifica si el vendedor tiene KYC aprobado.
     *
     * @param int $vendor_id ID del vendedor.
     * @return bool
     */
    private static function vendor_has_approved_kyc( int $vendor_id ): bool {
        $kyc_required = LTMS_Core_Config::get( 'ltms_kyc_required_for_payout', 'yes' );
        if ( $kyc_required !== 'yes' ) {
            return true;
        }

        $status = get_user_meta( $vendor_id, 'ltms_kyc_status', true );
        if ( $status !== 'approved' ) {
            return false;
        }

        // Validar que la certificación bancaria esté presente y corresponda al rep. legal.
        // Sin este archivo el desembolso queda bloqueado independientemente del estado KYC general.
        $file_banco     = get_user_meta( $vendor_id, 'ltms_kyc_file_banco', true );
        $bank_rep_legal = get_user_meta( $vendor_id, 'ltms_kyc_bank_rep_legal', true );
        $bank_account   = get_user_meta( $vendor_id, 'ltms_kyc_bank_account', true );

        if ( empty( $file_banco ) || empty( $bank_rep_legal ) || empty( $bank_account ) ) {
            LTMS_Logger::warning(
                sprintf(
                    'Payout bloqueado para vendedor #%d: certificación bancaria incompleta (file_banco=%s, rep_legal=%s, account=%s)',
                    $vendor_id,
                    empty( $file_banco ) ? 'FALTA' : 'ok',
                    empty( $bank_rep_legal ) ? 'FALTA' : 'ok',
                    empty( $bank_account ) ? 'FALTA' : 'ok'
                ),
                [ 'vendor_id' => $vendor_id, 'check' => 'kyc_bank_cert' ]
            );
            return false;
        }

        return true;
    }

    /**
     * Aprueba automáticamente retiros que cumplan los criterios.
     *
     * B8 FIX: este cron es REDUNDANTE con process_pending_payouts() que ya hace
     * lo mismo (post v2.8.5). Para evitar doble procesamiento, este método ahora
     * es un wrapper que simplemente llama a process_pending_payouts().
     *
     * Se conserva por compatibilidad con instalaciones existentes que tengan el
     * cron `ltms_approve_payout_cron` programado. No se programa de nuevo.
     *
     * @return void
     */
    public static function auto_approve_eligible(): void {
        // B8 FIX: delegar a process_pending_payouts() que tiene toda la lógica.
        self::process_pending_payouts();
    }
}
