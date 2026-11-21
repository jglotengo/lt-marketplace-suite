<?php
class LTMS_Openpay_Webhook_Handler {

    /**
     * Opción donde se persiste el último verification_code enviado por Openpay.
     */
    private const VERIFICATION_OPTION = 'ltms_openpay_webhook_verification';

    /**
     * Vigencia del código de verificación mostrado al admin (24 h).
     */
    private const VERIFICATION_TTL = 86400;

    /**
     * OPENPAY-WEBHOOK-AUTH-HARDEN (2026-11-19): códigos de error que WP core
     * produce al validar un header Authorization: Basic contra Application
     * Passwords (credenciales que no corresponden a un usuario WP). SOLO estos
     * se limpian en bypass_core_basic_auth_for_webhook(); cualquier otro error
     * de rest_authentication_errors (nonces de cookie, WAF de terceros, etc.)
     * pasa intacto — el bypass no debe relajar controles ajenos al webhook.
     */
    private const APP_PASSWORD_ERROR_CODES = [
        'invalid_username',
        'incorrect_password',
        'application_passwords_disabled',
        'application_passwords_disabled_for_user',
    ];

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register_route' ] );
        add_action( 'admin_notices', [ __CLASS__, 'maybe_render_verification_notice' ] );

        // OPENPAY-WEBHOOK-BASIC-CORE-BYPASS (2026-11-19): WP core (Application
        // Passwords) intercepta cualquier header Authorization: Basic en REST y,
        // si las credenciales no son un usuario WP válido, responde 401 ANTES de
        // ejecutar el callback de la ruta. El webhook de Openpay usa HTTP Basic
        // propio, así que para sus rutas se ignora ese error de core; la
        // validación real queda en handle() (fail-closed con hash_equals).
        add_filter( 'rest_authentication_errors', [ __CLASS__, 'bypass_core_basic_auth_for_webhook' ], 1000 );
    }

    /**
     * Permite que las peticiones con Authorization: Basic lleguen al callback
     * del webhook Openpay. WP core valida Basic contra Application Passwords
     * (usuarios WP) y bloquea con 401 credenciales ajenas; este filtro corre en
     * prioridad 1000 (después del collector de core, prioridad 100) y solo para
     * las rutas del webhook Openpay sustituye ese error por "sin error". El
     * handler hace la validación real y sigue fail-closed.
     *
     * @param WP_Error|bool|null $errors Error de autenticación de core o null/true.
     * @return WP_Error|bool|null
     */
    public static function bypass_core_basic_auth_for_webhook( $errors ) {
        if ( ! is_wp_error( $errors ) ) {
            return $errors;
        }

        // AUTH-HARDEN: allowlist de códigos. Solo se limpian errores de
        // credenciales Basic ajenas a WP (Application Passwords).
        if ( ! in_array( $errors->get_error_code(), self::APP_PASSWORD_ERROR_CODES, true ) ) {
            return $errors;
        }

        // Pretty permalinks: /wp-json/ltms/v1/webhooks/openpay (y alias singular).
        // AUTH-HARDEN: el match es SOLO sobre el path (sin query string) y
        // anclado al final — un substring del webhook dentro de la query de
        // otra ruta REST (ej. ?_wpnonce=x&x=/ltms/v1/webhooks/openpay) no abre
        // el bypass.
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
        if ( '' !== $uri ) {
            $qpos = strpos( $uri, '?' );
            $path = ( false === $qpos ) ? $uri : substr( $uri, 0, $qpos );
            if ( '' !== $path && preg_match( '#/ltms/v1/webhooks?/openpay/?$#', $path ) ) {
                return true;
            }
        }

        // Plain permalinks: /?rest_route=/ltms/v1/webhooks/openpay. PHP ya
        // decodifica $_GET; se matchea el valor tal cual lo despacha core.
        $rest_route = isset( $_GET['rest_route'] ) ? wp_unslash( (string) $_GET['rest_route'] ) : '';
        if ( '' !== $rest_route && preg_match( '#/ltms/v1/webhooks?/openpay/?$#', $rest_route ) ) {
            return true;
        }

        return $errors;
    }

    /**
     * Registra la ruta REST de Openpay Webhooks.
     *
     * @return void
     */
    public static function register_route(): void {
        $args = [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle' ],
            // SEC-H2: '__return_true' es intencional — webhooks de Openpay deben ser públicamente
            // accesibles. La autenticación se aplica dentro de handle() via HTTP Basic
            // (mecanismo documentado por Openpay) o HMAC-SHA256 (legacy/fixtures).
            'permission_callback' => '__return_true',
        ];

        // Ruta canónica.
        register_rest_route( 'ltms/v1', '/webhooks/openpay', $args );

        // OPENPAY-WEBHOOK-ALIAS FIX: el dashboard de Openpay tiene registrada la
        // URL legacy en SINGULAR (/wp-json/ltms/v1/webhook/openpay). Sin este
        // alias Openpay recibe 404 y ni el handshake de verificación ni las
        // notificaciones llegan. El plural se mantiene como canónico.
        register_rest_route( 'ltms/v1', '/webhook/openpay', $args );
    }

    /**
     * Muestra el último verification_code recibido de Openpay a los admins
     * de WooCommerce. La doc oficial (protocolo de 2 pasos) envía
     * {"type":"verification","verification_code":"..."} al guardar la URL del
     * webhook; ese código debe ingresarse en el dashboard de Openpay para
     * confirmar el endpoint. Sin UI quedaría enterrado en el audit log.
     *
     * @return void
     */
    public static function maybe_render_verification_notice(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $pending = self::get_pending_verification_code();
        if ( empty( $pending['code'] ) ) {
            return;
        }

        printf(
            '<div class="notice notice-info"><p><strong>%s</strong> <code>%s</code> %s</p></div>',
            esc_html__( 'Openpay — código de verificación del webhook:', 'ltms' ),
            esc_html( (string) $pending['code'] ),
            esc_html__( 'Ingrésalo en el dashboard de Openpay para confirmar el endpoint (válido 24 h).', 'ltms' )
        );
    }

    /**
     * Devuelve el código de verificación vigente (≤ 24 h), si existe.
     *
     * @return array{code?:string,received_at?:int,ip?:string}
     */
    public static function get_pending_verification_code(): array {
        $pending = get_option( self::VERIFICATION_OPTION, [] );
        if ( ! is_array( $pending ) || empty( $pending['code'] ) ) {
            return [];
        }

        $received_at = (int) ( $pending['received_at'] ?? 0 );
        if ( $received_at <= 0 || ( time() - $received_at ) > self::VERIFICATION_TTL ) {
            return [];
        }

        return $pending;
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

        $body = $request->get_json_params();
        if ( ! is_array( $body ) ) {
            return new WP_REST_Response( [ 'error' => 'Invalid payload' ], 400 );
        }

        // OPENPAY-WEBHOOK-EMPTY-PING FIX (2026-11-19): la validación de URL
        // al dar de alta un webhook por API envía un JSON vacío ({}) y exige
        // HTTP 200 antes de crear el registro. Sin este ACK el alta falla con
        // 412/6002 "Expected 200 response ... but was 401". No hay evento ni
        // transacción que procesar, así que solo se confirma la recepción.
        if ( empty( $body ) ) {
            return new WP_REST_Response( [ 'ok' => true ], 200 );
        }

        // AUDIT-FUNNEL-PAY-23 FIX (P0, re-auditoría del ciclo): antes se usaba
        // sanitize_key() sobre el tipo de evento — pero sanitize_key ELIMINA los
        // PUNTOS ('charge.succeeded' → 'chargesucceeded', 'payout.failed' →
        // 'payoutfailed') → el switch de eventos y el str_starts_with('payout.')
        // NUNCA matcheaban → TODO el dispatch del webhook Openpay estaba muerto:
        // ni charge.succeeded (confirmación PSE/Nequi/Daviplata/OXXO/SPEI),
        // ni refund.succeeded, ni payout.* se procesaban. Los eventos de Openpay
        // usan PUNTOS (a diferencia de Alegra con guiones o ZapSign con
        // underscores, donde sanitize_key sí funciona). lowercase+sanitize_text
        // preserva el formato real del evento.
        $event_type = strtolower( sanitize_text_field( (string) ( $body['type'] ?? '' ) ) );

        // OPENPAY-WEBHOOK-VERIFICATION FIX (alineación con docs oficiales):
        // el protocolo de verificación de Openpay envía
        // {"type":"verification","verification_code":"..."} SIN transaction y
        // SIN firma. Antes el guard "empty($transaction) → 400" y el HMAC
        // obligatorio lo rechazaban, así que el endpoint no podía registrarse
        // ni re-verificarse desde el dashboard. Este branch solo persiste el
        // código (visible para manage_woocommerce ≤ 24 h) — no toca órdenes ni
        // dinero, por eso puede ir antes de la validación de firma.
        if ( 'verification' === $event_type ) {
            return self::handle_verification( $body );
        }

        // OPENPAY-WEBHOOK-AUTH (2026-11-19): la doc oficial de Openpay CO
        // (docs.openpay.co/docs/webhooks.html) NO firma las notificaciones con
        // HMAC; el mecanismo soportado es HTTP Basic opcional. Antes el handler
        // exigía X-Openpay-Signature y rechazaba con 401 todo evento real.
        //
        // Estrategia:
        //  - Si hay Basic configurado (opciones ltms_openpay[_CO]_webhook_basic_*),
        //    se exige Authorization: Basic válido.
        //  - HMAC-SHA256 sigue aceptándose como fallback legacy/fixtures (requiere
        //    la private key). Así los tests firmados y una eventual firma futura
        //    de Openpay siguen funcionando.
        //  - Si no hay Basic configurado, se mantiene el fail-closed histórico
        //    con HMAC obligatorio (WH1 FIX v2.8.9).
        // El ping vacío y `verification` ya retornaron arriba: no tienen efectos
        // financieros y no requieren autenticación.
        $country = LTMS_Core_Config::get_country();

        $basic_user     = (string) LTMS_Core_Config::get( "ltms_openpay_{$country}_webhook_basic_user", '' );
        $basic_pass_raw = (string) LTMS_Core_Config::get( "ltms_openpay_{$country}_webhook_basic_password", '' );
        if ( '' === $basic_user ) {
            $basic_user = (string) LTMS_Core_Config::get( 'ltms_openpay_webhook_basic_user', '' );
        }
        if ( '' === $basic_pass_raw ) {
            $basic_pass_raw = (string) LTMS_Core_Config::get( 'ltms_openpay_webhook_basic_password', '' );
        }

        $basic_config_error = false;
        $basic_pass         = '';
        if ( '' !== $basic_pass_raw ) {
            try {
                $basic_pass = LTMS_Core_Security::maybe_decrypt( $basic_pass_raw );
            } catch ( \Throwable $e ) {
                $basic_config_error = true;
            }
        }

        if ( $basic_config_error ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning(
                    'OPENPAY_WEBHOOK_BASIC_CONFIG_ERROR',
                    'No se pudo descifrar la clave HTTP Basic del webhook Openpay. Webhook rechazado (fail-closed).'
                );
            }
            return new WP_REST_Response( [ 'error' => 'Webhook endpoint not configured' ], 401 );
        }

        $basic_configured = ( '' !== $basic_user && '' !== $basic_pass );
        $basic_valid      = $basic_configured && self::is_basic_auth_valid( $request, $basic_user, $basic_pass );

        // AUDIT-FUNNEL-PAY-03/PAY-17 FIX (loop comprador): la private key puede
        // estar cifrada (Admin Settings) o en texto plano (gateway WC). Se usa
        // maybe_decrypt() para tolerar ambos formatos + fallback a la key genérica.
        $raw_key = (string) LTMS_Core_Config::get( "ltms_openpay_{$country}_private_key", '' );
        if ( '' === $raw_key ) {
            $raw_key = (string) LTMS_Core_Config::get( 'ltms_openpay_private_key', '' );
        }
        $private_key = LTMS_Core_Security::maybe_decrypt( $raw_key );

        $hmac_valid = false;
        if ( '' !== $private_key ) {
            $expected_hmac = 'sha256=' . hash_hmac( 'sha256', $request->get_body(), $private_key );
            $received_hmac = (string) ( $request->get_header( 'x-openpay-signature' ) ?: '' );
            $hmac_valid    = ( '' !== $received_hmac && hash_equals( $expected_hmac, $received_hmac ) );
        }

        if ( $basic_configured ) {
            if ( ! $basic_valid && ! $hmac_valid ) {
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::warning(
                        'OPENPAY_WEBHOOK_INVALID_BASIC',
                        'HTTP Basic inválido o ausente en webhook Openpay (HMAC tampoco válido).'
                    );
                }
                return new WP_REST_Response( [ 'error' => 'Invalid signature' ], 401 );
            }
        } else {
            if ( '' === $private_key ) {
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::warning(
                        'OPENPAY_WEBHOOK_NO_SECRET',
                        'Openpay private key no configurado. Webhook rechazado (fail-closed).'
                    );
                }
                return new WP_REST_Response( [ 'error' => 'Webhook endpoint not configured' ], 401 );
            }

            if ( ! $hmac_valid ) {
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::warning(
                        'OPENPAY_WEBHOOK_INVALID_SIG',
                        'Firma HMAC inválida en webhook Openpay.',
                        [ 'received_sig' => substr( (string) ( $request->get_header( 'x-openpay-signature' ) ?: '' ), 0, 40 ) ]
                    );
                }
                return new WP_REST_Response( [ 'error' => 'Invalid signature' ], 401 );
            }
        }

        $transaction = $body['transaction'] ?? [];

        // OPENPAY-WEBHOOK-AUTH-HARDEN: transaction debe ser objeto/array. Un
        // escalar (ej. {"transaction":"abc"}) pasaba el empty() y en eventos
        // payout.* moría con TypeError dentro de handle_payout_event() (firma
        // array $txn) → 500 en la ruta plural y fatal en el alias singular
        // (sin try/catch del router). Ante payload malformado autenticado se
        // responde 400, nunca 500.
        if ( ! is_array( $transaction ) ) {
            return new WP_REST_Response( [ 'error' => 'Invalid payload' ], 400 );
        }

        if ( empty( $event_type ) || empty( $transaction ) ) {
            return new WP_REST_Response( [ 'error' => 'Invalid payload' ], 400 );
        }

        // API-BUG-11 FIX: event_id idempotency. OpenPay may deliver the same event
        // 2-3 times on retry. Without dedup, payout.failed could re-send admin emails
        // and re-fire ltms_payout_failed actions. Key scoped to (event_type, txn.id).
        // API-BUG-18 FIX: return 200 immediately if already processed, so OpenPay
        // stops retrying. (Full async migration is a documented follow-up.)
        // OPENPAY-WEBHOOK-IDEMPOTENCY-RELEASE: el lock NO es permanente a toda
        // costa — si dispatch_event() lanza o responde 4xx, el lock se libera
        // (ver bloque try/catch más abajo).
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

        // OPENPAY-WEBHOOK-IDEMPOTENCY-RELEASE (2026-11-19): el transient marca
        // el evento como visto ANTES de procesar (lock anti-concurrencia), pero
        // si el procesamiento falla — Throwable o respuesta 4xx — el lock se
        // LIBERA para que el reintento de Openpay pueda reprocesar. Antes, un
        // error a mitad (ej. un listener de payment_complete lanzando) dejaba
        // el lock puesto 1h: el reintento recibia 200 "Already processed" y el
        // evento se perdia con dinero cobrado y orden sin pagar.
        try {
            $response = self::dispatch_event( $event_type, $transaction );
        } catch ( \Throwable $e ) {
            delete_transient( $seen_key );
            throw $e;
        }

        if ( $response->get_status() >= 400 ) {
            delete_transient( $seen_key );
        }

        return $response;
    }

    /**
     * Procesa un evento ya autenticado, desduplicado y con lock activo. No
     * gestiona el transient de idempotencia: handle() lo libera si este método
     * lanza o responde 4xx, para que el reintento de Openpay reprocese.
     *
     * @param string $event_type  Tipo de evento (charge.*|payout.*|...).
     * @param array  $transaction Payload transaction del evento.
     * @return WP_REST_Response
     */
    private static function dispatch_event( string $event_type, array $transaction ): WP_REST_Response {
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
            // AUDIT-FUNNEL-PAY-21 FIX: un charge.succeeded sin orden vinculada
            // desaparecía con 200 silencioso y sin rastro — dinero cobrado que
            // ninguna orden refleja. Log CRITICAL + warning para reconciliación.
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning(
                    'OPENPAY_WEBHOOK_ORPHAN_EVENT',
                    sprintf(
                        'Evento %s con transacción %s no vinculó a ninguna orden (order_id ausente y meta no encontrada). Requiere reconciliación manual.',
                        $event_type,
                        $openpay_id
                    )
                );
            }
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
            // OPENPAY-WEBHOOK-EVENTS FIX: la doc oficial lista 'charge.refunded'
            // como el evento de reembolso; 'refund.succeeded' se conserva como
            // alias legacy para no perder webhooks ya configurados.
            case 'refund.succeeded':
            case 'charge.refunded':
                // CICLO24-P1-AD-GAP-001 FIX: validar monto del refund antes de marcar el
                // pedido como 'refunded'. Antes, este case hacia update_status('refunded')
                // incondicionalmente — si un admin o API externa procesaba un refund
                // PARCIAL por Openpay (ej. reembolsar $5 de un pedido de $100), el pedido
                // WC quedaba en status 'refunded' (que WC y el vendor interpretan como
                // FULLY refunded) desincronizando el status WC vs reality. Consecuencias
                // reales: comisiones recalculadas como 0, payouts futuros omitidos,
                // reportes contables erroneos, vendor cree que el pedido fue totalmente
                // reembolsado cuando solo lo fue parcialmente.
                //
                // Openpay envia transaction.amount en el webhook (mismo formato que
                // format_amount() del API client: int para COP, float 2 dec para USD).
                // Comparamos contra $order->get_total() con tolerancia 0.01 (float compare):
                //   - amount >= total: refund COMPLETO → update_status('refunded')
                //   - amount < total: refund PARCIAL  → NO cambiar status, add_order_note
                //     con info del monto parcial para que el admin vea el registro
                //   - amount invalido/ausente: fail-safe — NO cambiar status (prefiero
                //     NO marcar como refunded sin validar monto que arriesgar marcar
                //     erroneamente). Log warning para investigar.
                //
                // Patron C24 defense-in-depth: codigo financiero critico (toca wallet,
                // comisiones, payouts futuros) — SIEMPRE fail-safe conservativo.
                // Requiere segundo modelo review antes de merge (AGENTS.md "Revisión
                // como ultimo filtro"). Mismo patron que Stripe handler
                // (handle_charge_refunded) que solo agrega add_order_note() sin tocar
                // status — Stripe confia en process_refund() del gateway para crear el
                // refund WC y solo logea el evento webhook.
                $refund_amount_raw = $charge['amount'] ?? null;
                $refund_amount     = ( is_numeric( $refund_amount_raw ) ) ? (float) $refund_amount_raw : null;
                $order_total       = (float) $order->get_total();

                if ( $refund_amount === null ) {
                    // Fail-safe: amount ausente o no-numerico. No cambiar status crítico.
                    LTMS_Core_Logger::warning(
                        'OPENPAY_REFUND_AMOUNT_MISSING',
                        sprintf(
                            'Webhook refund.succeeded para pedido #%d sin campo transaction.amount válido (raw=%s). Status NO cambiado — requiere investigacion manual.',
                            $order_id,
                            is_scalar( $refund_amount_raw ) ? var_export( $refund_amount_raw, true ) : '(non-scalar)'
                        )
                    );
                    $order->add_order_note(
                        __( 'Reembolso Openpay recibido vía webhook pero monto no reportado. Revisar manualmente en dashboard Openpay.', 'ltms' )
                    );
                } elseif ( $refund_amount >= $order_total - 0.01 ) {
                    // Refund completo (con tolerancia float 0.01) — seguro marcar como refunded.
                    $order->update_status( 'refunded', __( 'Reembolso Openpay confirmado vía webhook (total).', 'ltms' ) );
                } else {
                    // Refund parcial — NO cambiar status del pedido. Solo registro.
                    $order->add_order_note(
                        sprintf(
                            /* translators: 1: monto reembolsado, 2: total del pedido */
                            __( 'Reembolso PARCIAL Openpay: %1$s de %2$s. Status del pedido NO modificado (no es fully refunded). Verificar en dashboard Openpay.', 'ltms' ),
                            wc_price( $refund_amount ),
                            wc_price( $order_total )
                        )
                    );
                    LTMS_Core_Logger::info(
                        'OPENPAY_REFUND_PARTIAL',
                        sprintf(
                            'Refund parcial Openpay para pedido #%d: %s/%s. Status NO cambiado (defense-in-depth AD-GAP-001).',
                            $order_id,
                            $refund_amount,
                            $order_total
                        )
                    );
                }
                break;
        }

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info( 'OPENPAY_WEBHOOK', "Evento={$event_type}, pedido=#{$order_id}" );
        }

        return new WP_REST_Response( [ 'received' => true ], 200 );
    }

    /**
     * Valida el header `Authorization: Basic` contra las credenciales
     * configuradas para el webhook. Comparación en tiempo constante.
     *
     * @param WP_REST_Request $request       Request entrante.
     * @param string          $expected_user Usuario configurado.
     * @param string          $expected_pass Password configurado (texto plano).
     * @return bool
     */
    private static function is_basic_auth_valid( WP_REST_Request $request, string $expected_user, string $expected_pass ): bool {
        $header = (string) $request->get_header( 'authorization' );

        // Algunos SAPIs eliminan Authorization del request; fallback a las
        // variables PHP_AUTH_* cuando el header no está disponible.
        if ( '' === $header && isset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) ) {
            return hash_equals( $expected_user, (string) $_SERVER['PHP_AUTH_USER'] )
                && hash_equals( $expected_pass, (string) $_SERVER['PHP_AUTH_PW'] );
        }

        if ( 0 !== stripos( $header, 'Basic ' ) ) {
            return false;
        }

        $decoded = base64_decode( substr( $header, 6 ), true );
        if ( false === $decoded || ! str_contains( $decoded, ':' ) ) {
            return false;
        }

        [ $user, $pass ] = explode( ':', $decoded, 2 );

        return hash_equals( $expected_user, $user ) && hash_equals( $expected_pass, $pass );
    }

    /**
     * Handshake de verificación de webhook (protocolo de 2 pasos de Openpay).
     *
     * Payload documentado: {"type":"verification","verification_code":"..."}.
     * Se responde 200 y se persiste el código para que un admin lo copie al
     * dashboard de Openpay. No hay transacción ni efecto financiero aquí.
     *
     * @param array $body Payload ya decodificado.
     * @return WP_REST_Response
     */
    private static function handle_verification( array $body ): WP_REST_Response {
        $code = substr( sanitize_text_field( (string) ( $body['verification_code'] ?? '' ) ), 0, 128 );
        if ( '' === $code ) {
            return new WP_REST_Response( [ 'error' => 'Invalid payload' ], 400 );
        }

        update_option(
            self::VERIFICATION_OPTION,
            [
                'code'        => $code,
                'received_at' => time(),
                'ip'          => self::client_ip(),
            ],
            false
        );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'OPENPAY_WEBHOOK_VERIFICATION',
                'Código de verificación de webhook Openpay recibido. Válido 24 h para confirmar el endpoint en el dashboard.',
                [ 'code' => $code ]
            );
        }

        return new WP_REST_Response( [ 'ok' => true ], 200 );
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
     * o por reference = order_ref (legacy) o por id parseado de order_ref 'PAY-{id}'.
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

        // PAYOUT-EMPTY-ID-GUARD (2026-11-20): un evento payout.* sin transaction.id
        // no puede referir a ningún disbursement real. Sin este guard, el lookup 1
        // (gateway_ref = '') matchearía el PRIMER payout con ref vacía y la rama
        // ref='' del guard PAYOUT-RETRY-REF-MATCH procesaría un fallo falso sobre
        // un payout arbitrario. Openpay siempre envía id en eventos legítimos —
        // fail-closed: log + ACK 200.
        if ( $openpay_id === '' ) {
            LTMS_Core_Logger::warning(
                'OPENPAY_PAYOUT_WEBHOOK_NO_ID',
                "Payout webhook {$event_type} sin transaction.id — ignorado."
            );
            return new WP_REST_Response( [ 'ok' => true ], 200 );
        }

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

        // PAYOUT-LOOKUP-ORDERID (2026-11-20): el fallback por reference es código
        // muerto — create_request persiste reference='PAY-YYMMDD-XXXXXX' random
        // mientras el disbursement usa order_id='PAY-{id}' (jamás matchean,
        // lección #213 regla 4). Fallback definitivo: parsear el id del order_ref.
        // Hace alcanzable la rama ref='' del guard PAYOUT-RETRY-REF-MATCH.
        if ( ! $payout && $order_ref !== '' && preg_match( '/^PAY-(\d+)$/', $order_ref, $order_ref_m ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $payout = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM `{$table}` WHERE id = %d LIMIT 1",
                    (int) $order_ref_m[1]
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

                // OPENPAY-WEBHOOK-PAYOUT-GUARD (2026-11-19): el guard histórico
                // (!== 'rejected') re-firiaba wp_mail + do_action en cada
                // re-entrega del mismo evento: tras el primer procesamiento el
                // payout queda en 'approved' y el guard volvía a pasar (el
                // re-update es inofensivo, pero email/log/action se duplicaban
                // — riesgo amplificado desde IDEMPOTENCY-RELEASE, que libera el
                // lock de dedup ante Throwable/4xx). Guard nuevo: disparar solo
                // si el payout está en un estado desde el que un fallo bancario
                // es legítimo (processing/completed/approved) y las notes aún
                // no marcan el fallo (prefijo 'Fallo bancario Openpay:'). El
                // caso approved+notes-limpias cubre payout.created perdido;
                // 'rejected' sigue siendo terminal.
                // PAYOUT-RETRY-REF-MATCH (2026-11-20): la identidad del disbursement es
                // gateway_ref, no el status. Un payout.failed VIEJO re-entregado tras un
                // re-approve solo dispara si gateway_ref coincide con el openpay_id del
                // evento o está vacío. En producción el lookup (find_payout_by_gateway_ref)
                // ya neutraliza el evento stale (ref nueva ≠ id viejo → fila no encontrada
                // → OPENPAY_PAYOUT_WEBHOOK_UNKNOWN): este guard es defense-in-depth para
                // cuando el fallback por reference se repare (hoy es código muerto —
                // reference='PAY-YYMMDD-XXXXXX' random vs order_id='PAY-{id}').
                $notes           = (string) ( $payout['notes'] ?? '' );
                $already_flagged = str_starts_with( $notes, 'Fallo bancario Openpay:' );
                $in_flight       = in_array( $payout['status'], [ 'processing', 'completed', 'approved' ], true );
                $ref_current     = (string) ( $payout['gateway_ref'] ?? '' );
                $ref_is_current  = ( $ref_current === $openpay_id || $ref_current === '' );

                if ( $in_flight && ! $already_flagged && $ref_is_current ) {
                    // PAYOUT-RETRY-SAFETY (2026-11-20): el disbursement rechazado por el
                    // banco queda muerto — se limpia gateway_ref para que un reintento
                    // vía approve() no lo trate como "gateway ya pagado" (guard PO-CRASH-3)
                    // y salte la re-ejecución marcando el payout como completed sin que el
                    // banco reciba el dinero. El openpay_id muerto queda en el log ctx.
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    $wpdb->update(
                        $table,
                        [
                            'status'      => 'approved', // devolver a approved para reintento manual
                            'notes'       => "Fallo bancario Openpay: {$error_msg}",
                            'gateway_ref' => '',
                            'updated_at'  => $now,
                        ],
                        [ 'id' => $payout_id ],
                        [ '%s', '%s', '%s', '%s' ],
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
                        [ 'payout_id' => $payout_id, 'vendor_id' => $vendor_id, 'error' => $error_msg, 'openpay_id' => $openpay_id ]
                    );

                    do_action( 'ltms_payout_failed', $payout_id, $vendor_id, $error_msg );
                } else {
                    if ( class_exists( 'LTMS_Core_Logger' ) ) {
                        LTMS_Core_Logger::info(
                            'OPENPAY_PAYOUT_FAILED_SKIPPED',
                            "payout.failed ignorado para payout #{$payout_id} (status={$payout['status']}, fallo ya procesado o estado terminal)."
                        );
                    }
                }
                break;
        }

        return new WP_REST_Response( [ 'received' => true ], 200 );
    }

    private static function find_order_by_txn( string $txn_id ): int {
        if ( ! $txn_id ) return 0;
        global $wpdb;

        // FIX #2 (CHECKOUT-AUDIT): el handler de checkout guarda el charge_id
        // Openpay en distintas meta keys según el método de pago (PSE, Nequi,
        // Daviplata, OXXO, SPEI, tarjeta). Antes solo buscábamos
        // `_openpay_transaction_id`, que NUNCA se setea para esos métodos → el
        // webhook no encontraba el pedido → PSE/Nequi/Daviplata nunca se marcaban
        // como pagados → comisiones y order_split no se liquidaban (bug P0).
        //
        // Ahora iteramos las meta keys que el handler realmente escribe, en
        // orden de probabilidad. La consulta se hace contra HPOS (wc_orders_meta)
        // primero y luego contra postmeta (legacy CPT storage) como fallback.
        $meta_keys = [
            '_openpay_transaction_id',
            '_ltms_pse_charge_id',
            '_ltms_nequi_charge_id',
            '_ltms_daviplata_charge_id',
            '_ltms_oxxo_charge_id',
            '_ltms_spei_charge_id',
            // AUDIT-FUNNEL-PAY-16 FIX: el gateway PSE nativo WC guarda el charge_id
            // en _ltms_openpay_pse_transaction_id (class-ltms-api-gateways.php
            // process_payment) — meta que NO estaba en esta lista → si Openpay no
            // devolvía order_id en el webhook, estas órdenes nunca se encontraban.
            '_ltms_openpay_pse_transaction_id',
            '_ltms_openpay_mx_charge_id',
        ];

        foreach ( $meta_keys as $key ) {
            // HPOS-aware lookup.
            $order_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT order_id FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                $key, $txn_id
            ) );
            if ( $order_id ) return $order_id;

            // Legacy CPT storage.
            $order_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                $key, $txn_id
            ) );
            if ( $order_id ) return $order_id;
        }
        return 0;
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
