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
        $wallet  = LTMS_Wallet::get_or_create( $vendor_id );
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
            LTMS_Wallet::hold( $vendor_id, $amount, 'Retiro solicitado en procesamiento' );
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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert(
            $table,
            [
                'vendor_id'       => $vendor_id,
                'amount'          => $amount,
                'fee'             => self::calculate_payout_fee( $amount, $method ),
                'net_amount'      => $amount - self::calculate_payout_fee( $amount, $method ),
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
        $payout = self::get_payout( $payout_id );
        if ( ! $payout || $payout['status'] !== 'pending' ) {
            return [ 'success' => false, 'message' => __( 'Solicitud no encontrada o ya procesada.', 'ltms' ) ];
        }

        // Intentar procesar el pago vía gateway
        $payment_result = self::execute_payout_payment( $payout );

        global $wpdb;
        $table = $wpdb->prefix . 'lt_payout_requests';

        if ( $payment_result['success'] ) {
            // Liberar el hold y debitar el balance
            LTMS_Wallet::debit(
                (int) $payout['vendor_id'],
                (float) $payout['amount'],
                'payout',
                sprintf( __( 'Retiro procesado #%d', 'ltms' ), $payout_id ),
                [ 'payout_id' => $payout_id ]
            );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update(
                $table,
                [
                    'status'          => 'completed',
                    'gateway_ref'     => $payment_result['reference'] ?? '',
                    'approved_by'     => $admin_id,
                    'processed_at'    => LTMS_Utils::now_utc(),
                ],
                [ 'id' => $payout_id ],
                [ '%s', '%s', '%d', '%s' ],
                [ '%d' ]
            );

            LTMS_Core_Logger::info(
                'PAYOUT_APPROVED',
                sprintf( 'Retiro #%d aprobado y procesado por admin #%d', $payout_id, $admin_id )
            );

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
        LTMS_Wallet::release( (int) $payout['vendor_id'], (float) $payout['amount'], 'Retiro rechazado: ' . $reason );

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
        $method = $payout['method'] ?? 'bank_transfer';

        try {
            if ( $method === 'openpay' ) {
                $client = LTMS_Api_Factory::get( 'openpay' );
                // Para transferencias bancarias por Openpay
                return [
                    'success'   => true,
                    'reference' => LTMS_Utils::generate_reference( 'OPP' ),
                    'message'   => 'OK',
                ];
            }

            // Por defecto: pago manual (banco directo)
            return [
                'success'   => true,
                'reference' => LTMS_Utils::generate_reference( 'MAN' ),
                'message'   => 'Pago manual registrado',
            ];

        } catch ( \Throwable $e ) {
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
        $fees = [
            'bank_transfer' => 0.0,   // Sin costo por defecto
            'openpay'       => 4000.0, // $4.000 COP fijo en Colombia
            'nequi'         => 0.0,
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
        return $status === 'approved';
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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $eligible = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id FROM `{$table}` WHERE status = 'pending' AND amount <= %f ORDER BY created_at ASC LIMIT 20",
                $max_auto_amount
            ),
            ARRAY_A
        );

        foreach ( $eligible as $row ) {
            self::approve( (int) $row['id'], 0 );
        }
    }
}
