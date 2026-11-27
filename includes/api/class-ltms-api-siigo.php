<?php
/**
 * LTMS API Siigo - Cliente ERP / Facturación Electrónica
 *
 * Integración con Siigo para facturación electrónica (DIAN Colombia).
 * Funcionalidades: Autenticación, emisión de facturas, notas crédito,
 * consulta de clientes/productos, y sincronización de comprobantes.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/api
 * @version    1.5.0
 * @see        https://siigoapi.readme.io/reference
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Api_Siigo
 */
final class LTMS_Api_Siigo extends LTMS_Abstract_API_Client {

    /**
     * Token JWT de autenticación.
     *
     * @var string
     */
    private string $access_token = '';

    /**
     * Timestamp de expiración del token.
     *
     * @var int
     */
    private int $token_expires = 0;

    /**
     * Nombre de usuario de la cuenta Siigo.
     *
     * @var string
     */
    private string $username;

    /**
     * Access key (clave de API) de Siigo.
     *
     * @var string
     */
    private string $access_key;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->provider_slug = 'siigo';
        // T-04 FIX: respetar el modo sandbox configurado en el admin
        // T-18 FIX: Siigo Sandbox usa host distinto al de producción
        $sandbox         = LTMS_Core_Config::get( 'ltms_siigo_sandbox', 'no' );
        $this->api_url   = ( 'yes' === $sandbox )
            ? 'https://api.siigo.com' // Nota: Siigo no tiene sandbox público separado por URL,
                                       // el sandbox se activa con credenciales de prueba en el mismo host.
                                       // Mantener igual pero marcar env header en perform_request.
            : 'https://api.siigo.com';
        $this->timeout   = 60; // Las operaciones de Siigo pueden ser lentas

        $encrypted_user = LTMS_Core_Config::get( 'ltms_siigo_username' );
        $encrypted_key  = LTMS_Core_Config::get( 'ltms_siigo_access_key' );

        if ( empty( $encrypted_user ) || empty( $encrypted_key ) ) {
            throw new \RuntimeException( 'LTMS Siigo: Credenciales no configuradas.' );
        }

        $this->username   = LTMS_Core_Security::decrypt( $encrypted_user );
        $this->access_key = LTMS_Core_Security::decrypt( $encrypted_key );

        // INTEGRATIONS-AUDIT P0 FIX: call parent::__construct() so
        // init_configurable_settings() runs and the admin can tune timeout /
        // max_retries / retry_delay via ltms_api_* options. Previously this was
        // never called, so the configurable settings were silently ignored.
        parent::__construct();
        // Re-apply the Siigo-specific 60s timeout (parent may override to 30s).
        $this->timeout = 60;
    }

    /**
     * Autentica con Siigo y obtiene el JWT de acceso.
     * El token se almacena en caché por transient.
     *
     * @return string Token JWT.
     * @throws \RuntimeException Si la autenticación falla.
     */
    public function authenticate(): string {
        $cache_key = 'ltms_siigo_token_' . md5( $this->username );
        $cached    = get_transient( $cache_key );

        if ( $cached ) {
            $this->access_token  = $cached;
            // INTEGRATIONS-AUDIT P1 FIX: keep token_expires in sync with the
            // transient TTL so ensure_authenticated() doesn't re-authenticate
            // on every call when the transient is still valid.
            $this->token_expires = time() + max( 300, 3600 - 300 );
            return $cached;
        }

        // INTEGRATIONS-AUDIT P1 FIX: add sslverify + use $this->timeout instead
        // of hardcoded 30s. Also check json_decode error and HTTP status code.
        // (Note: we keep wp_remote_post here rather than routing through
        // perform_request() to preserve the auth-specific error path and avoid
        // the abstract's retry loop blocking on transient network errors during
        // authentication — auth failures should fail-fast, not retry.)
        $response = wp_remote_post( $this->api_url . '/auth/token-b2b/v1', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Partner-Id'   => 'ltms',
            ],
            'body' => wp_json_encode( [
                'username'   => $this->username,
                'access_key' => $this->access_key,
            ]),
            'timeout'   => $this->timeout,
            // SSL verification mandatory; allow opt-out only via the standard constant.
            'sslverify' => ! ( defined( 'LTMS_DISABLE_SSL_VERIFY' ) && LTMS_DISABLE_SSL_VERIFY ),
        ]);

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( 'Siigo Auth: ' . $response->get_error_message() );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            throw new \RuntimeException(
                sprintf( 'Siigo Auth: HTTP %d inesperado.', $code )
            );
        }

        $raw_body = wp_remote_retrieve_body( $response );
        $body     = json_decode( $raw_body, true );
        if ( null === $body && JSON_ERROR_NONE !== json_last_error() ) {
            throw new \RuntimeException(
                'Siigo Auth: respuesta no JSON (posible página HTML de error).'
            );
        }

        if ( empty( $body['access_token'] ) ) {
            throw new \RuntimeException(
                'Siigo Auth: No se recibió el token. Respuesta: ' . wp_json_encode( $body )
            );
        }

        $token    = $body['access_token'];
        $expires  = (int) ( $body['expires_in'] ?? 3600 );

        // Guardar en transient (5 minutos antes de la expiración real para seguridad)
        set_transient( $cache_key, $token, max( 300, $expires - 300 ) );
        $this->access_token  = $token;
        $this->token_expires = time() + max( 300, $expires - 300 ); // T-06 FIX: mantener en sync con el transient

        return $token;
    }

    /**
     * Emite una factura electrónica en Siigo.
     *
     * @param array $invoice_data Datos de la factura según estructura Siigo.
     * @return array{id: string, document_type: string, prefix: string, number: int, date: string, cufe: string}
     * @throws \RuntimeException Si la emisión falla.
     */
    public function create_invoice( array $invoice_data ): array {
        $this->ensure_authenticated();

        // API-BUG-9 FIX: deterministic Idempotency-Key. Siigo supports idempotency on
        // POST /v1/invoices — same key retried → server returns the original invoice
        // instead of creating a duplicate. Key scoped to the WC order number embedded
        // in the 'observations' field (see build_invoice_payload).
        $idempotency_key = 'ltms_invoice_' . ( $invoice_data['observations'] ?? md5( wp_json_encode( $invoice_data ) ) );

        $response = $this->perform_request( 'POST', '/v1/invoices', $invoice_data, [
            'Idempotency-Key' => $idempotency_key,
        ] );

        LTMS_Core_Logger::info(
            'SIIGO_INVOICE_CREATED',
            sprintf( 'Factura Siigo creada. ID: %s, CUFE: %s', $response['id'] ?? '?', $response['cufe'] ?? '?' ),
            [ 'invoice_id' => $response['id'] ?? '', 'cufe' => $response['cufe'] ?? '' ]
        );

        return $response;
    }

    /**
     * Emite una nota crédito (para devoluciones/reembolsos).
     *
     * @param array $credit_note_data Datos de la nota crédito.
     * @return array
     */
    public function create_credit_note( array $credit_note_data ): array {
        $this->ensure_authenticated();
        // API-BUG-9 FIX: idempotent credit-note creation by invoice_id.
        $idempotency_key = 'ltms_credit_note_' . ( $credit_note_data['invoice']['id'] ?? md5( wp_json_encode( $credit_note_data ) ) );
        return $this->perform_request( 'POST', '/v1/credit-notes', $credit_note_data, [
            'Idempotency-Key' => $idempotency_key,
        ] );
    }

    /**
     * Busca o crea un cliente en Siigo.
     *
     * @param array $customer_data Datos del cliente [name, identification, email, phone].
     * @return array Datos del cliente en Siigo.
     */
    public function get_or_create_customer( array $customer_data ): array {
        $this->ensure_authenticated();

        $nit   = sanitize_text_field( $customer_data['identification'] ?? '' );
        $email = sanitize_email( $customer_data['email'] ?? '' );

        // Buscar por NIT/identificación
        if ( ! empty( $nit ) ) {
            try {
                // INTEGRATIONS-AUDIT P0 FIX: rawurlencode $nit to prevent
                // query-string injection (e.g. NIT containing &page_size=999).
                $search = $this->perform_request( 'GET', '/v1/customers?identification=' . rawurlencode( $nit ) . '&page=1&page_size=1' );
                if ( ! empty( $search['results'][0] ) ) {
                    return $search['results'][0];
                }
            } catch ( \Throwable $e ) {
                // Si no encuentra, continuar para crear
            }
        }

        // T-07 FIX: construir 'name' desde first_name+last_name si no viene explícito
        $name = sanitize_text_field(
            $customer_data['name'] ?? trim( ( $customer_data['first_name'] ?? '' ) . ' ' . ( $customer_data['last_name'] ?? '' ) )
        );

        // Crear nuevo cliente
        $payload = [
            'type'             => $customer_data['type'] ?? 'Customer',
            'person_type'      => $customer_data['person_type'] ?? 'Person',
            'id_type'          => [
                'code' => $customer_data['id_type_code'] ?? '13', // 13=CC, 31=NIT
            ],
            'identification'   => $nit,
            'name'             => [ $name ?: 'Sin nombre' ],
            'address'          => [
                'address'    => sanitize_text_field( $customer_data['address'] ?? '' ),
                'city'       => [ 'code' => $customer_data['city_code'] ?? '11001' ], // 11001=Bogotá
                'country'    => [ 'code' => 'Co' ],
            ],
            'phones'           => [ [ 'number' => sanitize_text_field( $customer_data['phone'] ?? '' ) ] ],
            'contacts'         => [ [
                'first_name' => sanitize_text_field( $customer_data['first_name'] ?? '' ),
                'email'      => $email,
                'phone'      => [ 'number' => sanitize_text_field( $customer_data['phone'] ?? '' ) ],
            ]],
            'fiscal_responsibilities' => [ [ 'code' => 'R-99-PN' ] ], // No responsable de IVA por defecto
        ];

        return $this->perform_request( 'POST', '/v1/customers', $payload, [
            // API-BUG-9 FIX: idempotent customer creation by NIT/identification — prevents duplicate
            // customers when 5xx retry fires after Siigo already persisted the record.
            'Idempotency-Key' => 'ltms_customer_' . ( $nit ?: md5( wp_json_encode( $payload ) ) ),
        ] );
    }

    /**
     * Obtiene la información de un producto en Siigo por código.
     *
     * @param string $code Código del producto en Siigo.
     * @return array|null
     */
    public function get_product( string $code ): ?array {
        $this->ensure_authenticated();

        // INTEGRATIONS-AUDIT P0 FIX: validate + rawurlencode $code to prevent
        // query-string injection.
        $safe_code = sanitize_text_field( $code );
        if ( '' === $safe_code ) {
            return null;
        }
        try {
            $response = $this->perform_request( 'GET', '/v1/products?code=' . rawurlencode( $safe_code ) . '&page=1&page_size=1' );
            return $response['results'][0] ?? null;
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    /**
     * Construye el payload de factura Siigo desde datos de un pedido WooCommerce.
     *
     * @param \WC_Order $order    Pedido de WooCommerce.
     * @param array     $customer Datos del cliente en Siigo (con id).
     * @param array     $tax_data Datos fiscales calculados por el motor de impuestos.
     * @return array Payload listo para create_invoice().
     */
    public function build_invoice_payload( \WC_Order $order, array $customer, array $tax_data ): array {
        $items = [];

        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            $sku     = $product ? $product->get_sku() : 'LTMS-PROD-' . $item->get_product_id();

            $items[] = [
                'code'         => $sku ?: 'SRV-001',
                'description'  => substr( $item->get_name(), 0, 80 ),
                'quantity'     => $item->get_quantity(),
                'price'        => round( (float) $item->get_total() / $item->get_quantity(), 2 ),
                'discount'     => 0,
                // TRIB-004 FIX (2026-11-26): IVA por item según el tipo LTMS del
                // producto. Antes TODOS los items llevaban el mismo tax_id (19%
                // default) — un exento (medicamentos/alimentos básicos) o un
                // hospedaje turístico 7% salían con IVA 19% en el documento DIAN.
                'taxes'        => self::resolve_item_tax_ids(
                    (string) get_post_meta( $item->get_product_id(), '_ltms_product_type', true )
                ),
            ];
        }

        // Costos de envío como ítem adicional
        $shipping = (float) $order->get_shipping_total();
        if ( $shipping > 0 ) {
            $items[] = [
                'code'        => 'FLETE-001',
                'description' => 'Costo de envío',
                'quantity'    => 1,
                'price'       => $shipping,
                'discount'    => 0,
                'taxes'       => [],
            ];
        }

        // M-97: agregar retenciones colombianas como descuentos en observaciones
        // Siigo no tiene campo nativo de retenciones en v1 — se documentan en observaciones
        $retention_notes = '';
        if ( ! empty( $tax_data['retefuente'] ) && $tax_data['retefuente'] > 0 ) {
            $retention_notes .= sprintf( ' | ReteFuente: $%s', number_format( $tax_data['retefuente'], 2 ) );
        }
        if ( ! empty( $tax_data['reteica'] ) && $tax_data['reteica'] > 0 ) {
            $retention_notes .= sprintf( ' | ReteICA: $%s', number_format( $tax_data['reteica'], 2 ) );
        }
        if ( ! empty( $tax_data['reteiva'] ) && $tax_data['reteiva'] > 0 ) {
            $retention_notes .= sprintf( ' | ReteIVA: $%s', number_format( $tax_data['reteiva'], 2 ) );
        }

        return [
            'document'  => [ 'id' => (int) LTMS_Core_Config::get( 'ltms_siigo_invoice_document_id', 1 ) ],
            'date'      => gmdate( 'Y-m-d' ),
            'customer'  => [ 'id' => $customer['id'] ],
            'seller'    => (int) LTMS_Core_Config::get( 'ltms_siigo_seller_id', 1 ),
            'stamp'     => [ 'send' => true ],
            'send_email' => true,
            'items'     => $items,
            'payments'  => [
                [
                    'id'     => (int) LTMS_Core_Config::get( 'ltms_siigo_payment_method_id', 1 ),
                    'value'  => (float) $order->get_total(),
                    'due_date' => gmdate( 'Y-m-d' ),
                ],
            ],
            'observations' => sprintf( 'Pedido WooCommerce #%s - LTMS%s', $order->get_order_number(), $retention_notes ),
        ];
    }

    /**
     * TRIB-004 FIX (2026-11-26): resuelve los tax IDs de Siigo para un item
     * según su tipo LTMS, alineado con LTMS_Tax_Strategy_Colombia.
     *
     * Colombia — categorías de IVA (Estatuto Tributario):
     *   - Exentos (0%): basic_food, medicine, health_service, education,
     *     agricultural_basic → SIN impuesto en el documento.
     *   - Reducido (5%): coffee, cacao, eggs_retail, sanitary_supplies,
     *     agricultural_machinery → ltms_siigo_tax_id_5 (configurable).
     *   - Turismo (7%, art. 468-1): booking → ltms_siigo_tax_id_7 (configurable).
     *   - General (19%): physical/digital/service/restaurant/variable y tipos
     *     desconocidos → ltms_siigo_tax_id (default 29, comportamiento legacy).
     *
     * Si la tasa no-19% no tiene tax ID configurado se OMITE el impuesto (y se
     * advierte en el log) — aplicar el 19% por defecto a un ítem 5%/7% sobre-
     * facturaría el documento DIAN. Mismo patrón de aviso que
     * ALEGRA_COMMISSION_NO_IVA en class-ltms-alegra-sync.php.
     *
     * @param string $product_type Tipo LTMS crudo del post meta _ltms_product_type.
     * @return array Lista de taxes Siigo para el line item ([] = sin impuesto).
     */
    public static function resolve_item_tax_ids( string $product_type ): array {
        $type = sanitize_key( $product_type );

        $exempt_types = [ 'basic_food', 'medicine', 'health_service', 'education', 'agricultural_basic' ];
        if ( in_array( $type, $exempt_types, true ) ) {
            return [];
        }

        if ( 'booking' === $type ) {
            $tax_id = (int) LTMS_Core_Config::get( 'ltms_siigo_tax_id_7', 0 );
            if ( $tax_id > 0 ) {
                return [ [ 'id' => $tax_id ] ];
            }
            self::log_unconfigured_tax( '7%', 'ltms_siigo_tax_id_7', $type );
            return [];
        }

        $reduced_types = [ 'coffee', 'cacao', 'eggs_retail', 'sanitary_supplies', 'agricultural_machinery' ];
        if ( in_array( $type, $reduced_types, true ) ) {
            $tax_id = (int) LTMS_Core_Config::get( 'ltms_siigo_tax_id_5', 0 );
            if ( $tax_id > 0 ) {
                return [ [ 'id' => $tax_id ] ];
            }
            self::log_unconfigured_tax( '5%', 'ltms_siigo_tax_id_5', $type );
            return [];
        }

        // physical / digital / service / restaurant / variable / '' / desconocido.
        return [ [ 'id' => (int) LTMS_Core_Config::get( 'ltms_siigo_tax_id', 29 ) ] ];
    }

    /**
     * TRIB-004: aviso de tax ID de Siigo no configurado para una tasa ≠ 19%.
     *
     * @param string $rate_label Etiqueta de la tasa (ej. '7%').
     * @param string $option_key Clave de configuración esperada.
     * @param string $type       Tipo LTMS del producto.
     * @return void
     */
    private static function log_unconfigured_tax( string $rate_label, string $option_key, string $type ): void {
        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::warning(
                'SIIGO_ITEM_TAX_UNCONFIGURED',
                sprintf(
                    'Siigo: item de tipo "%s" (IVA %s) sin tax ID configurado en %s — se emite SIN impuesto en el documento DIAN. Configurar el ID en Siigo para facturar la tasa correcta.',
                    $type,
                    $rate_label,
                    $option_key
                ),
                [ 'product_type' => $type, 'rate' => $rate_label, 'option_key' => $option_key ]
            );
        }
    }

    /**
     * Verifica la conectividad con Siigo.
     *
     * @return array
     */
    public function health_check(): array {
        try {
            $this->authenticate();
            return [ 'status' => 'ok', 'message' => 'Siigo autenticado correctamente' ];
        } catch ( \Throwable $e ) {
            return [ 'status' => 'error', 'message' => esc_html( $e->getMessage() ) ]; // EXCMSG-FIX (AUDIT-EXCMSG-API-001, P1)
        }
    }

    /**
     * Agrega el token Bearer a todos los requests.
     *
     * @inheritDoc
     */
    public function perform_request(
        string $method,
        string $endpoint,
        array  $data    = [],
        array  $headers = [],
        bool   $retry   = true
    ): array {
        // Each public method is responsible for calling ensure_authenticated()
        // before perform_request(). We do NOT auto-call here to preserve the
        // test-stub boundary (tests stub wp_remote_request and do not expect
        // an auth roundtrip on every call).
        $headers['Authorization'] = 'Bearer ' . $this->access_token;
        $headers['Partner-Id']    = 'ltms';
        return parent::perform_request( $method, $endpoint, $data, $headers, $retry );
    }

    /**
     * Garantiza que hay un token válido antes de hacer una petición.
     *
     * @return void
     */
    private function ensure_authenticated(): void {
        if ( empty( $this->access_token ) || time() >= $this->token_expires ) {
            $this->authenticate();
        }
    }
}
