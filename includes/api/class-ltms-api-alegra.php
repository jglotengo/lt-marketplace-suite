<?php
/**
 * LTMS API Client - Alegra (Contabilidad y Facturación)
 *
 * Integración con Alegra para gestión contable y facturación electrónica:
 * - Creación de facturas de venta al momento del pago del pedido
 * - Sincronización de contactos (compradores/vendedores)
 * - Sincronización de items (productos del marketplace)
 * - Registro de pagos de comisiones / retiros
 * - Webhooks para recibir confirmaciones del estado de facturas
 *
 * Autenticación: HTTP Basic Auth — base64(email:token)
 * Base URL: https://api.alegra.com/api/v1/
 * Países soportados: Colombia (CO) y México (MX)
 *
 * @package    LTMS
 * @subpackage LTMS/includes/api
 * @version    2.1.0
 * @see        https://developer.alegra.com/reference/autenticacion
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Api_Alegra
 */
final class LTMS_Api_Alegra extends LTMS_Abstract_API_Client {

    /**
     * URL base de la API de Alegra.
     */
    const API_BASE = 'https://api.alegra.com/api/v1';

    /**
     * Email de la cuenta Alegra (parte del Basic Auth).
     *
     * @var string
     */
    private string $email;

    /**
     * Token de API Alegra (parte del Basic Auth).
     *
     * @var string
     */
    private string $api_token;

    /**
     * Constructor.
     *
     * @throws \RuntimeException Si las credenciales no están configuradas.
     */
    public function __construct() {
        $this->provider_slug = 'alegra';
        $this->api_url       = self::API_BASE;
        $this->timeout       = 30;

        $this->email     = (string) LTMS_Core_Config::get( 'ltms_alegra_email', '' );
        $raw_token       = (string) LTMS_Core_Config::get( 'ltms_alegra_token', '' );
        $this->api_token = str_starts_with( $raw_token, 'v1:' )
            ? LTMS_Core_Security::decrypt( $raw_token )
            : $raw_token;

        if ( empty( $this->email ) || empty( $this->api_token ) ) {
            throw new \RuntimeException(
                'LTMS Alegra: Credenciales no configuradas. Ve a LTMS → Configuración → Alegra.'
            );
        }

        parent::__construct();

        // BUG FIX: El abstract client solo tiene Content-Type/Accept en default_headers.
        // Alegra usa HTTP Basic Auth — agregar el header Authorization aquí después de
        // que parent::__construct() inicialice la clase, usando las credenciales ya validadas.
        $this->default_headers['Authorization'] = 'Basic ' . base64_encode( $this->email . ':' . $this->api_token );
    }

    /**
     * {@inheritdoc}
     */
    public function get_provider_slug(): string {
        return 'alegra';
    }

    // ── CONTACTOS ──────────────────────────────────────────────────

    /**
     * Crea un contacto en Alegra (cliente o proveedor).
     *
     * @param array $contact_data Datos del contacto.
     * @return array{id: int, name: string, identification: string}
     * @throws \RuntimeException
     */
    public function create_contact( array $contact_data ): array {
        $payload = [
            'name' => sanitize_text_field( $contact_data['name'] ?? 'Sin nombre' ),
            // Alegra Colombia API v1 requiere 'type' como ARRAY: ['client'] o ['supplier'].
            // get_or_create_buyer_contact() ya envía ['client'] — respetar el valor del caller.
            'type' => is_array( $contact_data['type'] ?? 'client' ) ? ( $contact_data['type'] ?? ['client'] ) : [ $contact_data['type'] ?? 'client' ],
        ];

        if ( ! empty( $contact_data['identification'] ) ) {
            $payload['identification'] = sanitize_text_field( $contact_data['identification'] );
        }
        if ( ! empty( $contact_data['email'] ) ) {
            $payload['email'] = sanitize_email( $contact_data['email'] );
        }
        if ( ! empty( $contact_data['phone'] ) ) {
            // Alegra Colombia acepta solo dígitos en phonePrimary (máx 10 dígitos)
            $phone = preg_replace( '/\D/', '', $contact_data['phone'] );
            if ( strlen( $phone ) >= 7 ) {
                $payload['phonePrimary'] = substr( $phone, 0, 10 );
            }
        }
        if ( ! empty( $contact_data['address']['address'] ) ) {
            $payload['address'] = [
                'address' => sanitize_text_field( $contact_data['address']['address'] ),
                'city'    => sanitize_text_field( $contact_data['address']['city'] ?? '' ),
            ];
        }

        // Nota: ignoreRepeated NO es un campo válido de la API Alegra Colombia (causa 400).
        // La deduplicación se maneja vía get_or_create_contact() + find_contact_by_identification().

        return $this->perform_request( 'POST', '/contacts', $payload );
    }

    /**
     * Busca un contacto en Alegra por número de identificación (NIT/CC/RFC).
     *
     * @param string $identification Número de identificación fiscal.
     * @return array|null Datos del contacto o null si no existe.
     */
    public function find_contact_by_identification( string $identification ): ?array {
        $response = $this->perform_request( 'GET', '/contacts', [], [], false );
        $contacts = $response['data'] ?? $response;
        if ( ! is_array( $contacts ) ) {
            return null;
        }
        foreach ( $contacts as $contact ) {
            if ( ( $contact['identification'] ?? '' ) === $identification ) {
                return $contact;
            }
        }
        return null;
    }

    /**
     * Busca contacto por email. Alegra rechaza (905) emails duplicados.
     */
    public function find_contact_by_email( string $email ): ?array {
        if ( ! $email ) {
            return null;
        }
        $response = $this->perform_request( 'GET', '/contacts', [], [], false );
        $contacts = $response['data'] ?? $response;
        if ( ! is_array( $contacts ) ) {
            return null;
        }
        $email_lower = strtolower( trim( $email ) );
        foreach ( $contacts as $contact ) {
            if ( strtolower( trim( $contact['email'] ?? '' ) ) === $email_lower ) {
                return $contact;
            }
        }
        return null;
    }

    /**
     * Obtiene o crea un contacto. Deduplica por identification y luego por email.
     * Alegra retorna 905 si el email ya existe — evitar con búsqueda previa.
     */
    public function get_or_create_contact( array $contact_data ): array {
        $identification = $contact_data['identification'] ?? '';
        if ( $identification ) {
            $existing = $this->find_contact_by_identification( $identification );
            if ( $existing ) {
                return $existing;
            }
        }

        $email = $contact_data['email'] ?? '';
        if ( $email ) {
            $existing = $this->find_contact_by_email( $email );
            if ( $existing ) {
                return $existing;
            }
        }

        return $this->create_contact( $contact_data );
    }

    // ── ITEMS (PRODUCTOS) ──────────────────────────────────────────

    /**
     * Crea un item/producto en Alegra.
     *
     * @param array $item_data Datos del item.
     * @return array{id: int, name: string}
     * @throws \RuntimeException
     */
    public function create_item( array $item_data ): array {
        $payload = [
            'name'  => substr( sanitize_text_field( $item_data['name'] ?? 'Producto LTMS' ), 0, 150 ),
            'price' => (float) ( $item_data['price'] ?? 0 ),
            'type'  => $item_data['type'] ?? 'product',
        ];

        if ( ! empty( $item_data['tax'] ) ) {
            $payload['tax'] = is_array( $item_data['tax'] ) ? $item_data['tax'] : [ $item_data['tax'] ];
        }
        if ( ! empty( $item_data['description'] ) ) {
            $payload['description'] = substr( sanitize_textarea_field( $item_data['description'] ), 0, 500 );
        }

        return $this->perform_request( 'POST', '/items', $payload );
    }

    /**
     * Actualiza un item existente en Alegra.
     *
     * @param int   $alegra_item_id ID del item en Alegra.
     * @param array $item_data      Datos a actualizar.
     * @return array
     */
    public function update_item( int $alegra_item_id, array $item_data ): array {
        $payload = array_filter( [
            'name'  => isset( $item_data['name'] ) ? substr( sanitize_text_field( $item_data['name'] ), 0, 150 ) : null,
            'price' => isset( $item_data['price'] ) ? (float) $item_data['price'] : null,
        ] );

        return $this->perform_request( 'PUT', '/items/' . $alegra_item_id, $payload );
    }

    // ── FACTURAS ───────────────────────────────────────────────────

    /**
     * Crea una factura de venta en Alegra.
     *
     * @param array $invoice_data Datos de la factura.
     * @return array{id: int, numberTemplate: array, status: string}
     * @throws \RuntimeException
     */
    public function create_invoice( array $invoice_data ): array {
        $payload = [
            'date'    => $invoice_data['date']     ?? current_time( 'Y-m-d' ),
            'dueDate' => $invoice_data['due_date'] ?? current_time( 'Y-m-d' ),
            'client'  => [ 'id' => (int) $invoice_data['client_id'] ],
            'items'   => $this->format_invoice_items( $invoice_data['items'] ?? [] ),
        ];

        if ( ! empty( $invoice_data['number_template_id'] ) ) {
            $payload['numberTemplate'] = [ 'id' => (int) $invoice_data['number_template_id'] ];
        }

        if ( ! empty( $invoice_data['observations'] ) ) {
            $payload['observations'] = substr( sanitize_textarea_field( $invoice_data['observations'] ), 0, 500 );
        }

        if ( ! empty( $invoice_data['seller_id'] ) ) {
            $payload['seller'] = [ 'id' => (int) $invoice_data['seller_id'] ];
        }

        if ( ! empty( $invoice_data['currency'] ) && $invoice_data['currency'] !== 'COP' ) {
            $payload['currency'] = [
                'code'         => $invoice_data['currency'],
                'exchangeRate' => (float) ( $invoice_data['exchange_rate'] ?? 1 ),
            ];
        }

        return $this->perform_request( 'POST', '/invoices', $payload );
    }

    /**
     * Obtiene una factura por su ID.
     *
     * @param int $invoice_id ID de la factura en Alegra.
     * @return array
     */
    public function get_invoice( int $invoice_id ): array {
        return $this->perform_request( 'GET', '/invoices/' . $invoice_id );
    }

    /**
     * Envía una factura por email desde Alegra.
     *
     * @param int    $invoice_id ID de la factura.
     * @param array  $emails     Lista de emails destino.
     * @param bool   $copy_user  Enviar copia al usuario de Alegra.
     * @return array
     */
    public function send_invoice_email( int $invoice_id, array $emails, bool $copy_user = false ): array {
        $valid_emails = array_filter( array_map( 'sanitize_email', $emails ) );

        if ( empty( $valid_emails ) ) {
            throw new \InvalidArgumentException( '[Alegra] Ningún email válido para enviar la factura.' );
        }

        return $this->perform_request( 'POST', '/invoices/' . $invoice_id . '/email', [
            'emails'          => array_values( $valid_emails ),
            'sendCopyToUser'  => $copy_user,
        ] );
    }

    /**
     * Lista las facturas (paginado, máx 30 por página).
     *
     * @param int    $start    Offset de inicio.
     * @param int    $limit    Cantidad (máx 30).
     * @param array  $filters  Filtros opcionales (client_id, status, date, etc.).
     * @return array
     */
    public function list_invoices( int $start = 0, int $limit = 30, array $filters = [] ): array {
        $params = array_merge( $filters, [
            'start'    => $start,
            'limit'    => min( $limit, 30 ),
            'metadata' => 'true',
        ] );

        $endpoint = '/invoices?' . http_build_query( $params );
        return $this->perform_request( 'GET', $endpoint );
    }

    // ── PAGOS ──────────────────────────────────────────────────────

    /**
     * Registra un pago en Alegra.
     *
     * @param array $payment_data Datos del pago.
     * @return array{id: int}
     * @throws \RuntimeException
     */
    public function create_payment( array $payment_data ): array {
        $payload = [
            'date'          => $payment_data['date']    ?? current_time( 'Y-m-d' ),
            'bankAccount'   => [ 'id' => (int) ( $payment_data['bank_account_id'] ?? 0 ) ],
            'paymentMethod' => $payment_data['payment_method'] ?? 'transfer',
            'type'          => $payment_data['type']           ?? 'in',
        ];

        if ( ! empty( $payment_data['client_id'] ) ) {
            $payload['client'] = [ 'id' => (int) $payment_data['client_id'] ];
        }

        if ( ! empty( $payment_data['invoice_id'] ) ) {
            $payload['invoices'] = [ [ 'id' => (int) $payment_data['invoice_id'] ] ];
        }

        if ( ! empty( $payment_data['observations'] ) ) {
            $payload['observations'] = substr( sanitize_textarea_field( $payment_data['observations'] ), 0, 500 );
        }

        return $this->perform_request( 'POST', '/payments', $payload );
    }

    // ── NUMERACIONES ───────────────────────────────────────────────

    /**
     * Lista las plantillas de numeración de facturas.
     *
     * @return array Lista de numeraciones.
     */
    public function get_number_templates(): array {
        $response = $this->perform_request( 'GET', '/number-templates?documentType=invoice' );
        return is_array( $response ) ? $response : [];
    }

    // ── INFORMACIÓN DE LA EMPRESA ──────────────────────────────────

    /**
     * Obtiene la información de la empresa en Alegra.
     *
     * @return array
     */
    public function get_company(): array {
        return $this->perform_request( 'GET', '/company' );
    }

    // ── WEBHOOKS ───────────────────────────────────────────────────

    /**
     * Registra una suscripción de webhook en Alegra.
     *
     * Eventos disponibles: new-invoice, edit-invoice, delete-invoice,
     * new-bill, edit-bill, delete-bill, new-client, edit-client, delete-client,
     * new-item, edit-item, delete-item
     *
     * @param string $event Nombre del evento Alegra.
     * @param string $url   URL destino del webhook (debe ser HTTPS).
     * @return array
     */
    public function subscribe_webhook( string $event, string $url ): array {
        return $this->perform_request( 'POST', '/webhooks/subscriptions', [
            'event' => $event,
            'url'   => esc_url_raw( $url ),
        ] );
    }

    // ── HEALTH CHECK ───────────────────────────────────────────────

    /**
     * {@inheritdoc}
     */
    public function health_check(): array {
        $start = microtime( true );

        try {
            $company = $this->get_company();
            $latency = (int) round( ( microtime( true ) - $start ) * 1000 );

            return [
                'status'     => 'ok',
                'message'    => sprintf( 'Alegra conectado — empresa: %s', $company['name'] ?? 'N/A' ),
                'latency_ms' => $latency,
            ];
        } catch ( \Throwable $e ) {
            return [
                'status'  => 'error',
                'message' => 'Error conectando a Alegra: ' . $e->getMessage(),
            ];
        }
    }

    // ── Overrides de la clase abstracta ───────────────────────────

    /**
     * Sobrescribe perform_request para inyectar HTTP Basic Auth de Alegra.
     * Alegra usa base64(email:token) como Authorization header.
     *
     * @inheritDoc
     */
    protected function perform_request(
        string $method,
        string $endpoint,
        array  $data    = [],
        array  $headers = [],
        bool   $retry   = true
    ): array {
        $headers['Authorization'] = 'Basic ' . base64_encode( $this->email . ':' . $this->api_token );
        return parent::perform_request( $method, $endpoint, $data, $headers, $retry );
    }

    // ── Helpers privados ──────────────────────────────────────────

    /**
     * Formatea los items para el payload de una factura Alegra.
     *
     * @param array $items Items del pedido.
     * @return array Items formateados.
     */
    private function format_invoice_items( array $items ): array {
        $formatted = [];

        foreach ( $items as $item ) {
            $entry = [
                'quantity' => (int) ( $item['quantity'] ?? 1 ),
                'price'    => (float) ( $item['price']    ?? 0 ),
            ];

            if ( ! empty( $item['alegra_id'] ) ) {
                $entry['id'] = (int) $item['alegra_id'];
            } elseif ( ! empty( $item['name'] ) ) {
                // Si no hay ID de Alegra, enviar el nombre directamente
                $entry['name'] = substr( sanitize_text_field( $item['name'] ), 0, 150 );
            }

            if ( ! empty( $item['tax'] ) ) {
                $entry['tax'] = is_array( $item['tax'] ) ? $item['tax'] : [ $item['tax'] ];
            }

            $formatted[] = $entry;
        }

        return $formatted;
    }
}
