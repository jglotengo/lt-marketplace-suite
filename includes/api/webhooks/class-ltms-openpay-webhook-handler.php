<?php
class LTMS_Openpay_Webhook_Handler {

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register_route' ] );
    }

    /**
     * Registra la ruta REST de Openpay Webhooks.
     *
     * @return void
     */
    public static function register_route(): void {
        register_rest_route(
            'ltms/v1',
            '/webhooks/openpay',
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'handle' ],
                // SEC-H2: '__return_true' es intencional — webhooks de Openpay deben ser públicamente
                // accesibles. La autenticación se aplica dentro de handle() via HMAC-SHA256.
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function handle( WP_REST_Request $request ): WP_REST_Response {
        // API-BUG-19 FIX: per-IP rate limit (max 100 webhooks/min) — protects DB
        // from flood of invalid webhook attempts.
        $rate_key = 'ltms_wh_rate_' . md5( self::client_ip() );
        $count    = (int) get_transient( $rate_key );
        if ( $count > 100 ) {
            return new WP_REST_Response( [ 'error' => 'Too many requests' ], 429 );
        }
        set_transient( $rate_key, $count + 1, MINUTE_IN_SECONDS );

        // M-104: Verificar firma HMAC-SHA256 del payload con la clave privada de Openpay
        // Openpay envía el header X-Openpay-Signature: sha256=<hmac>
        //
        // WH1 FIX (v2.8.9) CRÍTICO: si private_key está vacío, RECHAZAR el webhook
        // (fail-closed). Antes, si no había key configurada, se omitía la validación
        // de firma → cualquier atacante podía forjar webhooks y completar pedidos.
        $country     = LTMS_Core_Config::get_country();
        $enc_key     = LTMS_Core_Config::get( "ltms_openpay_{$country}_private_key", '' );
        $private_key = $enc_key ? LTMS_Core_Security::decrypt( $enc_key ) : '';

        if ( empty( $private_key ) ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning(
                    'OPENPAY_WEBHOOK_NO_SECRET',
                    'Openpay private key no configurado. Webhook rechazado (fail-closed).'
                );
            }
            return new WP_REST_Response( [ 'error' => 'Webhook endpoint not configured' ], 401 );
        }

        // Validar firma HMAC-SHA256 (obligatorio).
        $raw_body      = $request->get_body();
        $expected_hmac = 'sha256=' . hash_hmac( 'sha256', $raw_body, $private_key );
        $received_hmac = $request->get_header( 'x-openpay-signature' ) ?: '';

        if ( ! hash_equals( $expected_hmac, $received_hmac ) ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning(
                    'OPENPAY_WEBHOOK_INVALID_SIG',
                    'Firma HMAC inválida en webhook Openpay.',
                    [ 'received_sig' => substr( $received_hmac, 0, 40 ) ]
                );
            }
            return new WP_REST_Response( [ 'error' => 'Invalid signature' ], 401 );
        }

        $body = $request->get_json_params();

        $event_type  = sanitize_key( $body['type'] ?? '' );
        $transaction = $body['transaction'] ?? [];

        if ( empty( $event_type ) || empty( $transaction ) ) {
            return new WP_REST_Response( [ 'error' => 'Invalid payload' ], 400 );
        }

        // API-BUG-11 FIX: event_id idempotency. OpenPay may deliver the same event
        // 2-3 times on retry. Without dedup, payout.failed could re-send admin emails
        // and re-fire ltms_payout_failed actions. Key scoped to (event_type, txn.id).
        // API-BUG-18 FIX: return 200 immediately if already processed, so OpenPay
        // stops retrying. (Full async migration is a documented follow-up.)
        $openpay_id = sanitize_text_field( $transaction['id'] ?? '' );
        $event_id   = $openpay_id ?: ( $event_type . '_' . md5( wp_json_encode( $transaction ) ) );
        $seen_key   = 'ltms_wh_seen_openpay_' . md5( $event_type . '|' . $event_id );
        if ( get_transient( $seen_key ) ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::info( 'OPENPAY_WEBHOOK_REPLAY', "Duplicate webhook ignored: event={$event_type}, txn={$event_id}" );
            }
            return new WP_REST_Response( [ 'message' => 'Already processed' ], 200 );
        }
        set_transient( $seen_key, 1, HOUR_IN_SECONDS );

        $openpay_id  = sanitize_text_field( $transaction['id'] ?? '' );
        $order_ref   = sanitize_text_field( $transaction['order_id'] ?? '' );

        // ── F-06: Eventos de PAYOUT (desembolso a vendedor) ──────────────
        // Openpay envía 'payout.created', 'payout.succeeded', 'payout.failed'
        if ( str_starts_with( $event_type, 'payout.' ) ) {
            return self::handle_payout_event( $event_type, $transaction, $openpay_id, $order_ref );
        }

        // ── Eventos de COBRO (cargo a cliente) ────────────────────────────
        $charge   = $transaction;
        $order_id = absint( $charge['order_id'] ?? 0 );
        if ( ! $order_id ) {
            $order_id = (int) self::find_order_by_txn( $openpay_id );
        }

        if ( ! $order_id ) {
            return new WP_REST_Response( [ 'ok' => true ], 200 );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return new WP_REST_Response( [ 'error' => 'Order not found' ], 404 );
        }

        switch ( $event_type ) {
            case 'charge.succeeded':
                if ( $order->needs_payment() ) {
                    $order->payment_complete( $openpay_id );
                    $order->add_order_note( __( 'Pago Openpay confirmado vía webhook.', 'ltms' ) );
                }
                break;
            case 'charge.failed':
                $order->update_status( 'failed', __( 'Pago Openpay fallido vía webhook.', 'ltms' ) );
                break;
            case 'refund.succeeded':
                $order->update_status( 'refunded', __( 'Reembolso Openpay confirmado vía webhook.', 'ltms' ) );
                break;
        }

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info( 'OPENPAY_WEBHOOK', "Evento={$event_type}, pedido=#{$order_id}" );
        }

        return new WP_REST_Response( [ 'received' => true ], 200 );
    }

    /**
     * F-06: Procesa eventos de payout (desembolso a vendedor) de Openpay.
     *
     * Openpay envía:
     *   - payout.created   → el desembolso fue recibido por el banco (en proceso)
     *   - payout.succeeded → el banco confirmó la acreditación
     *   - payout.failed    → el banco rechazó la transferencia
     *
     * Busca el payout en lt_payout_requests por gateway_ref = openpay_id
     * o por reference = order_ref (PAY-{id}).
     *
     * @param string $event_type  Tipo de evento (payout.succeeded, etc.)
     * @param array  $txn         Datos de la transacción Openpay.
     * @param string $openpay_id  ID del payout en Openpay.
     * @param string $order_ref   order_id enviado al crear el payout (ej: PAY-42).
     * @return WP_REST_Response
     */
    private static function handle_payout_event(
        string $event_type,
        array  $txn,
        string $openpay_id,
        string $order_ref
    ): WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_payout_requests';

        // Buscar por gateway_ref = openpay_id primero
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $payout = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE gateway_ref = %s LIMIT 1",
                $openpay_id
            ),
            ARRAY_A
        );

        // Fallback: buscar por reference = order_ref (ej: PAY-42)
        if ( ! $payout && $order_ref ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $payout = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM `{$table}` WHERE reference = %s LIMIT 1",
                    $order_ref
                ),
                ARRAY_A
            );
        }

        if ( ! $payout ) {
            LTMS_Core_Logger::warning(
                'OPENPAY_PAYOUT_WEBHOOK_UNKNOWN',
                "Payout webhook {$event_type} sin payout local: openpay_id={$openpay_id}, order_ref={$order_ref}"
            );
            return new WP_REST_Response( [ 'ok' => true ], 200 );
        }

        $payout_id = (int) $payout['id'];
        $vendor_id = (int) $payout['vendor_id'];
        $now       = gmdate( 'Y-m-d H:i:s' );

        switch ( $event_type ) {

            case 'payout.created':
                // Banco recibió la instrucción — marcar como processing si aún está en approved
                if ( in_array( $payout['status'], [ 'approved', 'processing' ], true ) ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    $wpdb->update(
                        $table,
                        [
                            'status'      => 'processing',
                            'gateway_ref' => $openpay_id,
                            'updated_at'  => $now,
                        ],
                        [ 'id' => $payout_id ],
                        [ '%s', '%s', '%s' ],
                        [ '%d' ]
                    );
                    LTMS_Core_Logger::info(
                        'OPENPAY_PAYOUT_PROCESSING',
                        "Payout #{$payout_id} en procesamiento bancario. Openpay ID: {$openpay_id}"
                    );
                }
                break;

            case 'payout.succeeded':
                // Banco confirmó la acreditación — marcar como completed
                if ( $payout['status'] !== 'completed' ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    $wpdb->update(
                        $table,
                        [
                            'status'       => 'completed',
                            'gateway_ref'  => $openpay_id,
                            'processed_at' => $now,
                            'updated_at'   => $now,
                        ],
                        [ 'id' => $payout_id ],
                        [ '%s', '%s', '%s', '%s' ],
                        [ '%d' ]
                    );

                    // Notificar al vendedor
                    $vendor_user = get_userdata( $vendor_id );
                    if ( $vendor_user && $vendor_user->user_email ) {
                        wp_mail(
                            $vendor_user->user_email,
                            '[Lo Tengo] ¡Tu retiro fue acreditado exitosamente!',
                            sprintf(
                                "Hola %s,

" .
                                "Tu retiro #%d por $%s COP fue acreditado exitosamente en tu cuenta bancaria.

" .
                                "Referencia Openpay: %s

" .
                                "Si tienes alguna pregunta, contáctanos.

Equipo Lo Tengo",
                                $vendor_user->display_name,
                                $payout_id,
                                number_format( (float) $payout['net_amount'], 0, ',', '.' ),
                                $openpay_id
                            )
                        );
                    }

                    LTMS_Core_Logger::info(
                        'OPENPAY_PAYOUT_COMPLETED',
                        "Payout #{$payout_id} completado. Vendedor #{$vendor_id}. Openpay ID: {$openpay_id}",
                        [ 'payout_id' => $payout_id, 'vendor_id' => $vendor_id, 'openpay_id' => $openpay_id ]
                    );

                    do_action( 'ltms_payout_completed', $vendor_id, (float) $payout['amount'], $payout_id );
                }
                break;

            case 'payout.failed':
                // Banco rechazó la transferencia — volver a approved para reintento
                $error_msg = sanitize_text_field( $txn['error_message'] ?? $txn['description'] ?? 'Error bancario' );

                if ( $payout['status'] !== 'rejected' ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    $wpdb->update(
                        $table,
                        [
                            'status'     => 'approved', // devolver a approved para reintento manual
                            'notes'      => "Fallo bancario Openpay: {$error_msg}",
                            'updated_at' => $now,
                        ],
                        [ 'id' => $payout_id ],
                        [ '%s', '%s', '%s' ],
                        [ '%d' ]
                    );

                    // Notificar al admin
                    $admin_email = (string) get_option( 'admin_email' );
                    if ( $admin_email ) {
                        wp_mail(
                            $admin_email,
                            "[LTMS] ⚠ Payout #{$payout_id} falló — requiere revisión",
                            "El payout #{$payout_id} al vendedor #{$vendor_id} fue rechazado por Openpay.

" .
                            "Motivo: {$error_msg}
" .
                            "Openpay ID: {$openpay_id}

" .
                            "El retiro fue devuelto a estado 'approved' para reintento.

" .
                            admin_url( 'admin.php?page=ltms-payouts&status=approved' )
                        );
                    }

                    LTMS_Core_Logger::error(
                        'OPENPAY_PAYOUT_FAILED',
                        "Payout #{$payout_id} rechazado por banco. Motivo: {$error_msg}",
                        [ 'payout_id' => $payout_id, 'vendor_id' => $vendor_id, 'error' => $error_msg ]
                    );

                    do_action( 'ltms_payout_failed', $payout_id, $vendor_id, $error_msg );
                }
                break;
        }

        return new WP_REST_Response( [ 'received' => true ], 200 );
    }

    private static function find_order_by_txn( string $txn_id ): int {
        if ( ! $txn_id ) return 0;
        global $wpdb;
        // HPOS-aware: intentar primero meta de órdenes
        $order_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT order_id FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = '_openpay_transaction_id' AND meta_value = %s LIMIT 1",
            $txn_id
        ) );
        if ( ! $order_id ) {
            $order_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_openpay_transaction_id' AND meta_value = %s LIMIT 1",
                $txn_id
            ) );
        }
        return $order_id;
    }

    /**
     * Resuelve la IP del cliente para rate limiting (API-BUG-19).
     *
     * @return string
     */
    private static function client_ip(): string {
        // WH3 FIX (v2.8.9): delegar a LTMS_Core_Security::get_client_ip_safe().
        if ( class_exists( 'LTMS_Core_Security' ) ) {
            return LTMS_Core_Security::get_client_ip_safe();
        }
        return sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SIIGO
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Class LTMS_Siigo_Webhook_Handler
 *
 * Procesa callbacks de Siigo (estado de facturas electrónicas DIAN).
 */
