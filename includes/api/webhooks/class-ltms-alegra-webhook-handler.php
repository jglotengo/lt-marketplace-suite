<?php
/**
 * LTMS Alegra Webhook Handler
 *
 * Procesa los eventos enviados por Alegra a través de webhooks:
 * - new-invoice / edit-invoice — Sincroniza estado de facturas con pedidos WC
 * - delete-invoice — Marca factura como eliminada en el pedido
 * - new-client / edit-client — Sincroniza cambios de contacto
 *
 * Endpoint: POST /wp-json/ltms/v1/webhooks/alegra
 *
 * Seguridad: token compartido en header X-Alegra-Secret (configurable
 * en LTMS → Configuración → Alegra → Webhook Secret).
 *
 * @package    LTMS
 * @subpackage LTMS/includes/api/webhooks
 * @version    2.1.0
 * @see        https://developer.alegra.com/reference/post_webhooks-subscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Alegra_Webhook_Handler
 */
final class LTMS_Alegra_Webhook_Handler {

    /**
     * Eventos de factura soportados.
     */
    private const INVOICE_EVENTS = [ 'new-invoice', 'edit-invoice', 'delete-invoice' ];

    /**
     * Eventos de contacto soportados.
     */
    private const CONTACT_EVENTS = [ 'new-client', 'edit-client', 'delete-client' ];

    /**
     * Registra hooks (no aplica para este handler, usado solo por el router).
     *
     * @return void
     */
    public static function init(): void {}

    /**
     * Maneja el request webhook entrante de Alegra.
     *
     * @param \WP_REST_Request $request Petición REST.
     * @return \WP_REST_Response
     */
    public static function handle( \WP_REST_Request $request ): \WP_REST_Response {
        // API-BUG-19 FIX: per-IP rate limit (max 100 webhooks/min) — protects DB.
        $rate_key = 'ltms_wh_rate_' . md5( self::client_ip() );
        $count    = (int) get_transient( $rate_key );
        if ( $count > 100 ) {
            return new \WP_REST_Response( [ 'error' => 'Too many requests' ], 429 );
        }
        set_transient( $rate_key, $count + 1, MINUTE_IN_SECONDS );

        // 1. Verificar token compartido
        // v2.9.129 GAP-AUDIT P0-1 FIX: fail-closed when secret not configured.
        // Before, if ltms_alegra_webhook_secret was empty, the entire auth check
        // was skipped — any attacker could send forged webhooks. Now rejects.
        $expected_secret = (string) LTMS_Core_Config::get( 'ltms_alegra_webhook_secret', '' );
        if ( empty( $expected_secret ) ) {
            LTMS_Core_Logger::warning(
                'ALEGRA_WEBHOOK_NOT_CONFIGURED',
                'Alegra webhook secret no configurado — webhook rechazado (fail-closed).'
            );
            return new \WP_REST_Response( [ 'error' => 'Webhook not configured' ], 403 );
        }
        $received_secret = (string) (
            $request->get_header( 'x-alegra-secret' )
            ?: $request->get_param( 'secret' )
            ?: ''
        );
        if ( ! hash_equals( $expected_secret, $received_secret ) ) {
            LTMS_Core_Logger::warning(
                'ALEGRA_WEBHOOK_AUTH',
                'Token de webhook Alegra inválido o ausente',
                [ 'ip' => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ) ]
            );
            return new \WP_REST_Response( [ 'error' => 'Unauthorized' ], 401 );
        }

        // 2. Parsear payload
        // get_json_params() requiere Content-Type registrado en $_SERVER.
        // En entornos de prueba (WP_REST_Request mock) puede devolver null.
        // Usar get_body() + json_decode como fallback garantiza compatibilidad.
        $body = $request->get_json_params();
        if ( empty( $body ) ) {
            $raw  = $request->get_body();
            $body = $raw ? (array) json_decode( $raw, true ) : [];
        }
        $event = sanitize_key( $body['action'] ?? $body['event'] ?? '' );

        if ( empty( $event ) ) {
            return new \WP_REST_Response( [ 'error' => 'Missing event type' ], 400 );
        }

        // API-BUG-11 FIX: event_id idempotency. Alegra may deliver the same webhook
        // multiple times on retry. The event_id is derived from (event, entity_id);
        // when no entity_id is available we hash the payload as a fallback.
        // API-BUG-18 FIX: return 200 immediately if already processed, so Alegra
        // stops retrying. (Full async migration is a documented follow-up.)
        $entity_data = $body['data'] ?? $body['invoice'] ?? $body['contact'] ?? [];
        $entity_id   = is_array( $entity_data ) ? ( $entity_data['id'] ?? 0 ) : 0;
        $event_id    = $entity_id ? $event . '_' . $entity_id : $event . '_' . md5( wp_json_encode( $body ) );
        $seen_key    = 'ltms_wh_seen_alegra_' . md5( $event_id );
        if ( get_transient( $seen_key ) ) {
            LTMS_Core_Logger::info(
                'ALEGRA_WEBHOOK_REPLAY',
                sprintf( 'Duplicate Alegra event %s ignored (already processed).', $event_id ),
                [ 'event_id' => $event_id ]
            );
            return new \WP_REST_Response( [ 'message' => 'Already processed' ], 200 );
        }
        set_transient( $seen_key, 1, HOUR_IN_SECONDS );

        // 3. Despachar por tipo de evento
        if ( in_array( $event, self::INVOICE_EVENTS, true ) ) {
            return self::handle_invoice_event( $event, $body );
        }

        if ( in_array( $event, self::CONTACT_EVENTS, true ) ) {
            return self::handle_contact_event( $event, $body );
        }

        // Evento desconocido — responder OK para no generar retries en Alegra
        LTMS_Core_Logger::info(
            'ALEGRA_WEBHOOK_UNKNOWN',
            sprintf( 'Evento Alegra no manejado: %s', $event )
        );

        return new \WP_REST_Response( [ 'received' => true, 'processed' => false ], 200 );
    }

    // ── Handlers privados ─────────────────────────────────────────

    /**
     * Procesa eventos de factura (new-invoice, edit-invoice, delete-invoice).
     *
     * @param string $event Tipo de evento.
     * @param array  $body  Payload del webhook.
     * @return \WP_REST_Response
     */
    private static function handle_invoice_event( string $event, array $body ): \WP_REST_Response {
        $invoice    = $body['data'] ?? $body['invoice'] ?? $body;
        $alegra_id  = (int) ( $invoice['id'] ?? 0 );
        $status     = sanitize_key( $invoice['status'] ?? '' );

        if ( ! $alegra_id ) {
            return new \WP_REST_Response( [ 'error' => 'Invalid invoice payload' ], 400 );
        }

        // Buscar el pedido WooCommerce asociado a esta factura Alegra
        $order_id = self::find_order_by_alegra_invoice( $alegra_id );

        if ( $order_id ) {
            $order = wc_get_order( $order_id );

            if ( $order ) {
                $order->update_meta_data( '_ltms_alegra_invoice_status', $status );

                // Guardar número de factura legible (ej: FE-001)
                if ( ! empty( $invoice['numberTemplate']['fullNumber'] ) ) {
                    $order->update_meta_data(
                        '_ltms_alegra_invoice_number',
                        sanitize_text_field( $invoice['numberTemplate']['fullNumber'] )
                    );
                }

                $order->add_order_note(
                    sprintf(
                        /* translators: %1$d: ID Alegra, %2$s: evento, %3$s: estado */
                        __( 'Alegra factura #%1$d — evento: %2$s — estado: %3$s', 'ltms' ),
                        $alegra_id,
                        $event,
                        $status ?: 'N/A'
                    )
                );

                $order->save();
            }
        }

        LTMS_Core_Logger::info(
            'ALEGRA_WEBHOOK_INVOICE',
            sprintf( 'Factura Alegra #%d — evento: %s — estado: %s — pedido WC: #%d',
                $alegra_id, $event, $status, $order_id
            ),
            [ 'alegra_invoice_id' => $alegra_id, 'wc_order_id' => $order_id ]
        );

        return new \WP_REST_Response( [ 'received' => true ], 200 );
    }

    /**
     * Procesa eventos de contacto (new-client, edit-client, delete-client).
     *
     * @param string $event Tipo de evento.
     * @param array  $body  Payload del webhook.
     * @return \WP_REST_Response
     */
    private static function handle_contact_event( string $event, array $body ): \WP_REST_Response {
        $contact   = $body['data'] ?? $body['contact'] ?? $body;
        $alegra_id = (int) ( $contact['id'] ?? 0 );

        LTMS_Core_Logger::info(
            'ALEGRA_WEBHOOK_CONTACT',
            sprintf( 'Contacto Alegra #%d — evento: %s', $alegra_id, $event ),
            [ 'alegra_contact_id' => $alegra_id ]
        );

        /**
         * Acción para que otros módulos reaccionen a cambios de contacto en Alegra.
         *
         * @param string $event     Tipo de evento (new-client, edit-client, delete-client).
         * @param array  $contact   Datos del contacto Alegra.
         */
        do_action( 'ltms_alegra_contact_event', $event, $contact );

        return new \WP_REST_Response( [ 'received' => true ], 200 );
    }

    /**
     * Busca el ID de pedido WooCommerce asociado a una factura Alegra.
     *
     * @param int $alegra_invoice_id ID de la factura en Alegra.
     * @return int ID del pedido WC, o 0 si no se encuentra.
     */
    private static function find_order_by_alegra_invoice( int $alegra_invoice_id ): int {
        global $wpdb;

        // HPOS-compatible: buscar primero en postmeta, luego en wc_orders_meta si HPOS activo
        $order_id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                  WHERE meta_key = '_ltms_alegra_invoice_id'
                    AND meta_value = %d
                  LIMIT 1",
                $alegra_invoice_id
            )
        );

        if ( ! $order_id && class_exists( 'Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore' ) ) {
            // HPOS: buscar en la tabla de meta de pedidos de WC
            $hpos_table = $wpdb->prefix . 'wc_orders_meta';
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_table ) ) ) {
                $order_id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    $wpdb->prepare(
                        "SELECT order_id FROM {$hpos_table}
                          WHERE meta_key = '_ltms_alegra_invoice_id'
                            AND meta_value = %d
                          LIMIT 1",
                        $alegra_invoice_id
                    )
                );
            }
        }

        return $order_id;
    }

    /**
     * Resuelve la IP del cliente para rate limiting (API-BUG-19).
     *
     * @return string
     */
    private static function client_ip(): string {
        $ip = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
        if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $forwarded = array_filter( array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) ) ) );
            if ( ! empty( $forwarded ) ) {
                $ip = end( $forwarded );
            }
        }
        return $ip;
    }
}
