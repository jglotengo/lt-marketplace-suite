<?php
/**
 * LTMS Frontend Payout Handler - Controlador AJAX de Retiros (Frontend Vendedor)
 *
 * Expone los endpoints AJAX que el dashboard del vendedor necesita para:
 *  - Obtener datos de billetera (balance + historial de movimientos)
 *  - Enviar una solicitud de retiro
 *
 * Esta clase es el puente entre la vista `view-wallet.php` / `ltms-dashboard.js`
 * y el backend `LTMS_Payout_Scheduler` + `LTMS_Wallet`.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/frontend
 * @version    2.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Frontend_Payout_Handler
 */
final class LTMS_Frontend_Payout_Handler {

    use LTMS_Logger_Aware;

    /** Número de transacciones a devolver en el historial. */
    const HISTORY_LIMIT = 50;

    /**
     * Registra los hooks AJAX del handler.
     *
     * @return void
     */
    public static function init(): void {
        $instance = new self();

        // Datos de la billetera (balance + transacciones)
        add_action( 'wp_ajax_ltms_get_wallet_data',  [ $instance, 'ajax_get_wallet_data' ] );

        // Solicitar retiro
        add_action( 'wp_ajax_ltms_request_payout',   [ $instance, 'ajax_request_payout' ] );
    }

    // =========================================================================
    // AJAX: ltms_get_wallet_data
    // =========================================================================

    /**
     * Devuelve el balance y el historial de la billetera del vendedor autenticado.
     *
     * Respuesta JSON exitosa:
     * {
     *   success: true,
     *   data: {
     *     balance: float,
     *     available: float,
     *     held: float,
     *     currency: string,
     *     transactions: [ { id, type, amount, formatted, description, date, status } ]
     *   }
     * }
     *
     * @return void
     */
    public function ajax_get_wallet_data(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( __( 'Debes iniciar sesión.', 'ltms' ), 401 );
        }

        $vendor_id = get_current_user_id();

        // Verificar que el usuario tiene rol de vendedor
        if ( ! $this->user_is_vendor( $vendor_id ) ) {
            wp_send_json_error( __( 'Acceso restringido a vendedores.', 'ltms' ), 403 );
        }

        try {
            $wallet    = LTMS_Wallet::get_or_create( $vendor_id );
            $balance   = (float) ( $wallet['balance']      ?? 0 );
            $held      = (float) ( ( $wallet['balance_pending'] ?? $wallet['balance_reserved'] ?? 0 ) ?? 0 );
            $available = max( 0.0, $balance - $held );

            $transactions = $this->get_wallet_transactions( $vendor_id );

            wp_send_json_success( [
                'balance'      => $balance,
                'available'    => $available,
                'held'         => $held,
                'currency'     => $this->get_currency(),
                'transactions' => $transactions,
            ] );

        } catch ( \Throwable $e ) {
            $this->log_error( 'ajax_get_wallet_data: ' . $e->getMessage() );
            wp_send_json_error( __( 'Error al cargar los datos de la billetera.', 'ltms' ), 500 );
        }
    }

    // =========================================================================
    // AJAX: ltms_request_payout
    // =========================================================================

    /**
     * Procesa una solicitud de retiro enviada desde el dashboard del vendedor.
     *
     * POST params:
     *  - nonce          string  Nonce de seguridad
     *  - amount         float   Monto a retirar
     *  - bank_account_id string  ID / número de cuenta destino
     *  - method         string  'bank_transfer' | 'nequi' | 'openpay'
     *
     * @return void
     */
    public function ajax_request_payout(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( __( 'Debes iniciar sesión.', 'ltms' ), 401 );
        }

        $vendor_id = get_current_user_id();

        if ( ! $this->user_is_vendor( $vendor_id ) ) {
            wp_send_json_error( __( 'Acceso restringido a vendedores.', 'ltms' ), 403 );
        }

        // Sanitización de inputs
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $amount          = (float) ( $_POST['amount']          ?? 0 );
        $bank_account_id = sanitize_text_field( wp_unslash( $_POST['bank_account_id'] ?? '' ) );
        $method          = sanitize_key( $_POST['method'] ?? 'bank_transfer' );
        // phpcs:enable

        // Validación básica de campos
        if ( $amount <= 0 ) {
            wp_send_json_error( __( 'El monto debe ser mayor a cero.', 'ltms' ) );
        }

        if ( empty( $bank_account_id ) ) {
            wp_send_json_error( __( 'La cuenta bancaria es requerida.', 'ltms' ) );
        }

        $allowed_methods = [ 'bank_transfer', 'nequi', 'openpay' ];
        if ( ! in_array( $method, $allowed_methods, true ) ) {
            wp_send_json_error( __( 'Método de pago no válido.', 'ltms' ) );
        }

        // Delegar lógica de negocio al Payout Scheduler
        try {
            $result = LTMS_Payout_Scheduler::create_request(
                $vendor_id,
                $amount,
                $bank_account_id,
                $method
            );
        } catch ( \Throwable $e ) {
            $this->log_error( 'ajax_request_payout: ' . $e->getMessage() );
            wp_send_json_error( __( 'Error interno al procesar la solicitud. Inténtalo de nuevo.', 'ltms' ), 500 );
        }

        if ( ! ( $result['success'] ?? false ) ) {
            wp_send_json_error( $result['message'] ?? __( 'Error desconocido.', 'ltms' ) );
        }

        wp_send_json_success( [
            'message'   => $result['message'] ?? __( 'Solicitud de retiro creada correctamente.', 'ltms' ),
            'payout_id' => $result['payout_id'] ?? 0,
        ] );
    }

    // =========================================================================
    // Helpers privados
    // =========================================================================

    /**
     * Obtiene el historial de transacciones de un vendedor desde el ledger.
     *
     * @param int $vendor_id ID del vendedor.
     * @return array<int, array<string, mixed>>
     */
    private function get_wallet_transactions( int $vendor_id ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'lt_wallet_ledger';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, type, amount, description, created_at, status
                   FROM {$table}
                  WHERE vendor_id = %d
               ORDER BY id DESC
                  LIMIT %d",
                $vendor_id,
                self::HISTORY_LIMIT
            ),
            ARRAY_A
        );

        if ( ! is_array( $rows ) ) {
            return [];
        }

        $currency = $this->get_currency();

        return array_map( static function ( array $row ) use ( $currency ): array {
            $amount = (float) $row['amount'];
            return [
                'id'          => (int) $row['id'],
                'type'        => $row['type'],
                'amount'      => $amount,
                'formatted'   => LTMS_Utils::format_money( $amount, $currency ),
                'description' => $row['description'] ?? '',
                'date'        => wp_date( 'd/m/Y H:i', strtotime( $row['created_at'] ) ),
                'status'      => $row['status'] ?? 'completed',
            ];
        }, $rows );
    }

    /**
     * Verifica si un usuario tiene rol de vendedor LTMS.
     *
     * @param int $user_id ID del usuario.
     * @return bool
     */
    private function user_is_vendor( int $user_id ): bool {
        if ( ! $user_id ) {
            return false;
        }
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return false;
        }
        $vendor_roles = [ 'ltms_vendor', 'administrator' ];
        return (bool) array_intersect( $vendor_roles, (array) $user->roles );
    }

    /**
     * Devuelve la moneda configurada para el sitio.
     *
     * @return string ISO 4217 ('COP', 'MXN', etc.)
     */
    private function get_currency(): string {
        if ( class_exists( 'LTMS_Core_Config' ) ) {
            return LTMS_Core_Config::get_currency();
        }
        return get_option( 'woocommerce_currency', 'COP' );
    }
}
