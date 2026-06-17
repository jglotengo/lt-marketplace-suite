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
     * @return void
     */
    public static function init(): void {
        add_action( 'ltms_process_payouts', [ __CLASS__, 'process_pending_payouts' ] );
        add_action( 'ltms_approve_payout_cron', [ __CLASS__, 'auto_approve_eligible' ] );

        // M-118: Registrar schedules si no existen (idempotente — safe to call on every request).
        if ( ! wp_next_scheduled( 'ltms_process_payouts' ) ) {
            wp_schedule_event( strtotime( 'tomorrow 02:00:00' ), 'daily', 'ltms_process_payouts' );
        }
        if ( ! wp_next_scheduled( 'ltms_approve_payout_cron' ) ) {
            wp_schedule_event( strtotime( 'tomorrow 06:00:00' ), 'daily', 'ltms_approve_payout_cron' );
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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert(
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

        $payout_id = (int) $wpdb->insert_id;

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

        // Intentar procesar el pago vía gateway
        $payment_result = self::execute_payout_payment( $payout );

        $table = $wpdb->prefix . 'lt_payout_requests';

        if ( $payment_result['success'] ) {
            // 1. Liberar el hold + debitar — envuelto en try/catch porque Wallet lanza RuntimeException
            try {
                LTMS_Business_Wallet::release(
                    (int) $payout['vendor_id'],
                    (float) $payout['amount'],
                    sprintf( __( 'Hold liberado para retiro #%d', 'ltms' ), $payout_id )
                );

                LTMS_Business_Wallet::debit(
                    (int) $payout['vendor_id'],
                    (float) $payout['amount'],
                    sprintf( __( 'Retiro procesado #%d', 'ltms' ), $payout_id ),
                    [ 'payout_id' => $payout_id, 'type' => 'payout' ]
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
                do_action( 'ltms_payout_completed', (int) $payout['vendor_id'], (float) $payout['amount'] );
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
            do_action( 'ltms_payout_completed', (int) $payout['vendor_id'], (float) $payout['amount'] );

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

        // Liberar el hold en la billetera
        LTMS_Business_Wallet::release( (int) $payout['vendor_id'], (float) $payout['amount'], 'Retiro rechazado: ' . $reason );

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
     * @return void
     */
    public static function process_pending_payouts(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_payout_requests';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $payouts = $wpdb->get_results(
            "SELECT * FROM `{$table}` WHERE status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 1 DAY) ORDER BY created_at ASC LIMIT 50",
            ARRAY_A
        );

        foreach ( $payouts as $payout ) {
            self::approve( (int) $payout['id'], 0 ); // 0 = sistema automático
        }
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
     * @return void
     */
    public static function auto_approve_eligible(): void {
        $auto_approve_enabled = LTMS_Core_Config::get( 'ltms_auto_approve_payouts', 'no' );
        if ( $auto_approve_enabled !== 'yes' ) {
            return;
        }

        $max_auto_amount = (float) LTMS_Core_Config::get( 'ltms_auto_approve_max_amount', 500000 );

        global $wpdb;
        $table = $wpdb->prefix . 'lt_payout_requests';

        // M-QA-03: include minimum payout amount guard to prevent approving dust amounts.
        $min_auto_amount = self::get_minimum_payout_amount();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $eligible = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id FROM `{$table}` WHERE status = 'pending' AND amount >= %f AND amount <= %f ORDER BY created_at ASC LIMIT 20",
                $min_auto_amount,
                $max_auto_amount
            ),
            ARRAY_A
        );

        foreach ( $eligible as $row ) {
            self::approve( (int) $row['id'], 0 );
        }
    }
}
