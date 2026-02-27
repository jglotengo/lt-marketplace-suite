<?php
/**
 * LTMS Stripe Webhook Handler
 *
 * Procesa los eventos de webhook enviados por Stripe al endpoint REST.
 * Verifica la firma HMAC con el webhook secret de Stripe, despacha los
 * eventos relevantes a los pedidos de WooCommerce y registra todos los
 * eventos en la tabla lt_webhook_logs.
 *
 * Endpoint: POST /wp-json/ltms/v1/webhooks/stripe
 *
 * @package    LTMS
 * @subpackage LTMS/includes/api/webhooks
 * @version    1.5.0
 * @see        https://stripe.com/docs/webhooks
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Stripe_Webhook_Handler
 */
class LTMS_Stripe_Webhook_Handler {

    /**
     * Registra el endpoint REST y conecta el handler.
     *
     * @return void
     */
    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register_route' ] );
    }

    /**
     * Registra la ruta REST de Stripe Webhooks.
     *
     * @return void
     */
    public static function register_route(): void {
        register_rest_route(
            'ltms/v1',
            '/webhooks/stripe',
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'handle' ],
                'permission_callback' => '__return_true', // La verificación se hace dentro del handler.
            ]
        );
    }

    /**
     * Maneja la petición entrante desde Stripe.
     *
     * @param WP_REST_Request $request Petición REST de WordPress.
     * @return WP_REST_Response
     */
    public static function handle( WP_REST_Request $request ): WP_REST_Response {
        $body       = $request->get_body();
        $sig_header = $request->get_header( 'stripe_signature' ) ?: '';

        // Recuperar el webhook secret desde la configuración de la pasarela.
        $webhook_secret = self::get_webhook_secret();

        // 1. Verificar firma con el SDK de Stripe.
        try {
            $event = \Stripe\Webhook::constructEvent( $body, $sig_header, $webhook_secret );
        } catch ( \Stripe\Exception\SignatureVerificationException $e ) {
            LTMS_Core_Logger::warning(
                'STRIPE_WEBHOOK_INVALID_SIG',
                'Firma de webhook de Stripe inválida.',
                [ 'sig_header' => substr( $sig_header, 0, 60 ) ]
            );
            return new WP_REST_Response( [ 'error' => 'Invalid signature' ], 400 );
        } catch ( \UnexpectedValueException $e ) {
            LTMS_Core_Logger::warning(
                'STRIPE_WEBHOOK_BAD_PAYLOAD',
                'Payload de webhook de Stripe malformado: ' . $e->getMessage()
            );
            return new WP_REST_Response( [ 'error' => 'Invalid payload' ], 400 );
        }

        $event_type = $event->type ?? 'unknown';
        $event_data = $event->data->object ?? null;

        // 2. Registrar el evento en lt_webhook_logs antes de procesarlo.
        $log_id = self::log_webhook_event( $event_type, $body, $sig_header );

        // 3. Despachar el evento al manejador correspondiente.
        try {
            self::dispatch_event( $event_type, $event_data );

            // Marcar log como procesado.
            self::update_webhook_log( $log_id, 'processed' );

        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::error(
                'STRIPE_WEBHOOK_PROCESS_ERROR',
                $e->getMessage(),
                [ 'event_type' => $event_type, 'log_id' => $log_id ]
            );
            self::update_webhook_log( $log_id, 'failed', $e->getMessage() );
        }

        return new WP_REST_Response( [ 'received' => true ], 200 );
    }

    /**
     * Despacha el evento de Stripe al manejador de negocio correspondiente.
     *
     * @param string     $event_type Tipo de evento Stripe (ej: 'payment_intent.succeeded').
     * @param mixed|null $data       Objeto de datos del evento (StripeObject).
     * @return void
     */
    private static function dispatch_event( string $event_type, mixed $data ): void {
        if ( null === $data ) {
            return;
        }

        switch ( $event_type ) {

            case 'payment_intent.succeeded':
                self::handle_payment_intent_succeeded( $data );
                break;

            case 'payment_intent.payment_failed':
                self::handle_payment_intent_failed( $data );
                break;

            case 'charge.refunded':
                self::handle_charge_refunded( $data );
                break;

            case 'account.updated':
                self::handle_account_updated( $data );
                break;

            case 'transfer.created':
                self::handle_transfer_created( $data );
                break;

            default:
                LTMS_Core_Logger::info(
                    'STRIPE_WEBHOOK_UNHANDLED',
                    sprintf( 'Evento de Stripe no manejado: %s', $event_type )
                );
        }
    }

    /**
     * Maneja el evento payment_intent.succeeded.
     * Busca el pedido asociado y lo marca como pagado.
     *
     * @param mixed $intent Objeto PaymentIntent de Stripe.
     * @return void
     */
    private static function handle_payment_intent_succeeded( mixed $intent ): void {
        $pi_id = is_array( $intent ) ? ( $intent['id'] ?? '' ) : ( $intent->id ?? '' );
        if ( empty( $pi_id ) ) {
            return;
        }

        $order = self::find_order_by_payment_intent( $pi_id );
        if ( ! $order ) {
            LTMS_Core_Logger::warning(
                'STRIPE_WEBHOOK_ORDER_NOT_FOUND',
                sprintf( 'No se encontró pedido para PaymentIntent %s (succeeded).', $pi_id )
            );
            return;
        }

        if ( ! $order->is_paid() ) {
            $order->payment_complete( $pi_id );
            $order->add_order_note(
                sprintf(
                    /* translators: %s: PaymentIntent ID */
                    __( 'Pago confirmado por Stripe webhook. PaymentIntent: %s', 'ltms' ),
                    $pi_id
                )
            );
        }

        LTMS_Core_Logger::info(
            'STRIPE_PAYMENT_SUCCEEDED',
            sprintf( 'Pedido #%d marcado como pagado. PI: %s', $order->get_id(), $pi_id )
        );
    }

    /**
     * Maneja el evento payment_intent.payment_failed.
     * Actualiza el estado del pedido a 'failed'.
     *
     * @param mixed $intent Objeto PaymentIntent de Stripe.
     * @return void
     */
    private static function handle_payment_intent_failed( mixed $intent ): void {
        $pi_id          = is_array( $intent ) ? ( $intent['id'] ?? '' ) : ( $intent->id ?? '' );
        $failure_message = is_array( $intent )
            ? ( $intent['last_payment_error']['message'] ?? __( 'Error de pago en Stripe', 'ltms' ) )
            : ( $intent->last_payment_error->message ?? __( 'Error de pago en Stripe', 'ltms' ) );

        if ( empty( $pi_id ) ) {
            return;
        }

        $order = self::find_order_by_payment_intent( $pi_id );
        if ( ! $order ) {
            return;
        }

        $order->update_status(
            'failed',
            sprintf(
                /* translators: 1: PaymentIntent ID, 2: failure message */
                __( 'Pago fallido en Stripe. PI: %1$s | Motivo: %2$s', 'ltms' ),
                $pi_id,
                $failure_message
            )
        );

        LTMS_Core_Logger::warning(
            'STRIPE_PAYMENT_FAILED',
            sprintf( 'Pedido #%d marcado como fallido. PI: %s | %s', $order->get_id(), $pi_id, $failure_message )
        );
    }

    /**
     * Maneja el evento charge.refunded.
     * Agrega una nota en el pedido registrando el reembolso.
     *
     * @param mixed $charge Objeto Charge de Stripe.
     * @return void
     */
    private static function handle_charge_refunded( mixed $charge ): void {
        $pi_id     = is_array( $charge ) ? ( $charge['payment_intent'] ?? '' ) : ( $charge->payment_intent ?? '' );
        $amount    = is_array( $charge ) ? ( $charge['amount_refunded'] ?? 0 ) : ( $charge->amount_refunded ?? 0 );
        $currency  = is_array( $charge ) ? ( $charge['currency'] ?? 'cop' ) : ( $charge->currency ?? 'cop' );

        if ( empty( $pi_id ) ) {
            return;
        }

        $order = self::find_order_by_payment_intent( (string) $pi_id );
        if ( ! $order ) {
            return;
        }

        // Convertir de unidad mínima Stripe a moneda local.
        $display_amount = strtoupper( $currency ) === 'COP'
            ? (int) $amount
            : round( $amount / 100, 2 );

        $order->add_order_note(
            sprintf(
                /* translators: 1: amount, 2: currency, 3: PaymentIntent ID */
                __( 'Reembolso de %1$s %2$s registrado por Stripe webhook. PI: %3$s', 'ltms' ),
                number_format( $display_amount, 0, ',', '.' ),
                strtoupper( $currency ),
                $pi_id
            )
        );

        LTMS_Core_Logger::info(
            'STRIPE_CHARGE_REFUNDED',
            sprintf( 'Reembolso registrado en pedido #%d. PI: %s', $order->get_id(), $pi_id )
        );
    }

    /**
     * Maneja el evento account.updated.
     * Actualiza el estado de la cuenta Connect del vendedor en user meta.
     *
     * @param mixed $account Objeto Account de Stripe.
     * @return void
     */
    private static function handle_account_updated( mixed $account ): void {
        $account_id      = is_array( $account ) ? ( $account['id'] ?? '' ) : ( $account->id ?? '' );
        $charges_enabled = is_array( $account ) ? ( $account['charges_enabled'] ?? false ) : ( $account->charges_enabled ?? false );
        $payouts_enabled = is_array( $account ) ? ( $account['payouts_enabled'] ?? false ) : ( $account->payouts_enabled ?? false );

        if ( empty( $account_id ) ) {
            return;
        }

        // Buscar usuario con esta cuenta Connect vinculada.
        $users = get_users( [
            'meta_key'   => 'ltms_stripe_connect_account_id',
            'meta_value' => $account_id,
            'number'     => 1,
            'fields'     => 'ids',
        ] );

        if ( empty( $users ) ) {
            LTMS_Core_Logger::info(
                'STRIPE_ACCOUNT_UPDATED_NO_USER',
                sprintf( 'account.updated para acct %s sin usuario LTMS vinculado.', $account_id )
            );
            return;
        }

        $user_id = (int) $users[0];

        update_user_meta( $user_id, 'ltms_stripe_connect_status', $charges_enabled && $payouts_enabled ? 'active' : 'pending' );
        update_user_meta( $user_id, 'ltms_stripe_connect_charges_enabled', (int) $charges_enabled );
        update_user_meta( $user_id, 'ltms_stripe_connect_payouts_enabled', (int) $payouts_enabled );
        update_user_meta( $user_id, 'ltms_stripe_connect_updated_at', current_time( 'mysql', true ) );

        LTMS_Core_Logger::info(
            'STRIPE_ACCOUNT_UPDATED',
            sprintf(
                'Usuario #%d | acct: %s | charges: %s | payouts: %s',
                $user_id,
                $account_id,
                $charges_enabled ? 'yes' : 'no',
                $payouts_enabled ? 'yes' : 'no'
            )
        );
    }

    /**
     * Maneja el evento transfer.created.
     * Agrega una nota en el pedido con el ID de la transferencia.
     *
     * @param mixed $transfer Objeto Transfer de Stripe.
     * @return void
     */
    private static function handle_transfer_created( mixed $transfer ): void {
        $transfer_id     = is_array( $transfer ) ? ( $transfer['id'] ?? '' ) : ( $transfer->id ?? '' );
        $source_tx       = is_array( $transfer ) ? ( $transfer['source_transaction'] ?? '' ) : ( $transfer->source_transaction ?? '' );
        $destination     = is_array( $transfer ) ? ( $transfer['destination'] ?? '' ) : ( $transfer->destination ?? '' );
        $amount          = is_array( $transfer ) ? ( $transfer['amount'] ?? 0 ) : ( $transfer->amount ?? 0 );
        $currency        = is_array( $transfer ) ? ( $transfer['currency'] ?? 'cop' ) : ( $transfer->currency ?? 'cop' );

        if ( empty( $source_tx ) ) {
            return;
        }

        // Buscar el pedido por el PaymentIntent que originó la transferencia.
        $order = self::find_order_by_payment_intent( (string) $source_tx );
        if ( ! $order ) {
            return;
        }

        $display_amount = strtoupper( $currency ) === 'COP'
            ? (int) $amount
            : round( $amount / 100, 2 );

        $order->add_order_note(
            sprintf(
                /* translators: 1: transfer ID, 2: amount, 3: currency, 4: destination */
                __( 'Transferencia Stripe Connect creada. ID: %1$s | %2$s %3$s → %4$s', 'ltms' ),
                $transfer_id,
                number_format( $display_amount, 0, ',', '.' ),
                strtoupper( $currency ),
                $destination
            )
        );

        LTMS_Core_Logger::info(
            'STRIPE_TRANSFER_CREATED',
            sprintf( 'Transfer %s creado para pedido #%d.', $transfer_id, $order->get_id() )
        );
    }

    /**
     * Busca un pedido de WooCommerce por el ID de PaymentIntent de Stripe.
     *
     * @param string $pi_id ID del PaymentIntent (pi_...).
     * @return WC_Order|null
     */
    private static function find_order_by_payment_intent( string $pi_id ): ?WC_Order {
        if ( empty( $pi_id ) ) {
            return null;
        }

        $orders = wc_get_orders( [
            'meta_key'   => '_ltms_stripe_payment_intent_id',
            'meta_value' => $pi_id,
            'limit'      => 1,
            'return'     => 'objects',
        ] );

        if ( ! empty( $orders ) && $orders[0] instanceof WC_Order ) {
            return $orders[0];
        }

        return null;
    }

    /**
     * Inserta un registro en la tabla lt_webhook_logs.
     *
     * @param string $event_type Tipo de evento de Stripe.
     * @param string $body       Cuerpo raw del webhook (JSON).
     * @param string $signature  Header Stripe-Signature.
     * @return int ID del registro insertado (0 si falla).
     */
    private static function log_webhook_event( string $event_type, string $body, string $signature ): int {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert(
            $wpdb->prefix . 'lt_webhook_logs',
            [
                'provider'   => 'stripe',
                'event_type' => $event_type,
                'payload'    => $body,
                'signature'  => substr( $signature, 0, 255 ),
                'is_valid'   => 1,
                'status'     => 'processing',
                'order_id'   => null,
                'created_at' => class_exists( 'LTMS_Utils' ) ? LTMS_Utils::now_utc() : current_time( 'mysql', true ),
            ],
            [ '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s' ]
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Actualiza el estado de un registro de webhook en lt_webhook_logs.
     *
     * @param int    $log_id        ID del registro.
     * @param string $status        Nuevo estado: 'processed'|'failed'.
     * @param string $error_message Mensaje de error si aplica.
     * @return void
     */
    private static function update_webhook_log( int $log_id, string $status, string $error_message = '' ): void {
        if ( $log_id <= 0 ) {
            return;
        }

        global $wpdb;

        $data   = [ 'status' => $status, 'processed_at' => current_time( 'mysql', true ) ];
        $format = [ '%s', '%s' ];

        if ( ! empty( $error_message ) ) {
            $data['error_message'] = substr( $error_message, 0, 500 );
            $format[]              = '%s';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update(
            $wpdb->prefix . 'lt_webhook_logs',
            $data,
            [ 'id' => $log_id ],
            $format,
            [ '%d' ]
        );
    }

    /**
     * Obtiene el webhook secret configurado en la pasarela WooCommerce.
     *
     * @return string
     */
    private static function get_webhook_secret(): string {
        $gateways = WC()->payment_gateways()->payment_gateways();
        if ( isset( $gateways['ltms_stripe'] ) ) {
            return $gateways['ltms_stripe']->get_option( 'webhook_secret', '' );
        }
        // Fallback: leer desde LTMS_Core_Config.
        return LTMS_Core_Config::get( 'ltms_stripe_webhook_secret', '' );
    }
}
