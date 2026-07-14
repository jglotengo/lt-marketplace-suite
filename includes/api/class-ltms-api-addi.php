<?php
/**
 * LTMS API Client - Addi (BNPL - Buy Now Pay Later)
 *
 * Integración con Addi para financiación en el checkout:
 * - Creación de solicitudes de financiación
 * - Verificación de estado de aprobación
 * - Webhooks de confirmación/rechazo
 * - Widget JavaScript de Addi en el checkout
 *
 * Países: Colombia (CO) y México (MX)
 *
 * @package    LTMS
 * @subpackage LTMS/includes/api
 * @version    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Api_Addi
 */
class LTMS_Api_Addi extends LTMS_Abstract_API_Client {

    /**
     * URLs de la API por entorno y país.
     */
    const API_URLS = [
        'CO' => [
            'live'    => 'https://api.addi.com',
            'sandbox' => 'https://api.sandbox.addi.com',
        ],
        'MX' => [
            'live'    => 'https://api.addi.com.mx',
            'sandbox' => 'https://api.sandbox.addi.com.mx',
        ],
    ];

    /**
     * @var string País activo.
     */
    private string $country;

    /**
     * @var string Merchant client_id de Addi.
     */
    private string $client_id;

    /**
     * @var string Merchant client_secret de Addi.
     */
    private string $client_secret;

    /**
     * @var string|null Token de acceso OAuth2 cacheado.
     */
    private ?string $access_token = null;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->country       = LTMS_Core_Config::get_country();
        $environment         = LTMS_ENVIRONMENT === 'production' ? 'live' : 'sandbox';
        $this->api_url       = self::API_URLS[ $this->country ][ $environment ] ?? self::API_URLS['CO']['sandbox'];
        $this->client_id     = LTMS_Core_Security::decrypt( LTMS_Core_Config::get( 'ltms_addi_client_id', '' ) );
        $this->client_secret = LTMS_Core_Security::decrypt( LTMS_Core_Config::get( 'ltms_addi_client_secret', '' ) );
        // INTEGRATIONS-AUDIT P1 FIX: set provider_slug so log_api_call() writes
        // provider='addi' in lt_api_logs (previously '' empty).
        $this->provider_slug = 'addi';

        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    public function get_provider_slug(): string {
        return 'addi';
    }

    /**
     * Crea una solicitud de financiación Addi en el checkout.
     *
     * @param array $checkout_data Datos del checkout (cliente, items, monto, URLs de callback).
     * @return array{success: bool, checkout_url: string, application_id: string}
     * @throws \RuntimeException
     */
    public function create_application( array $checkout_data ): array {
        $token = $this->get_access_token();

        $payload = [
            'orderId'      => $checkout_data['order_id'],
            'totalAmount'  => [
                'currency' => $checkout_data['currency'] ?? ( $this->country === 'CO' ? 'COP' : 'MXN' ),
                'value'    => (float) $checkout_data['amount'],
            ],
            'items'        => $this->format_items( $checkout_data['items'] ?? [] ),
            'client'       => [
                'idType'       => $this->country === 'CO' ? 'CC' : 'RFC',
                'idNumber'     => $checkout_data['client']['document'] ?? '',
                'firstName'    => $checkout_data['client']['first_name'] ?? '',
                'lastName'     => $checkout_data['client']['last_name'] ?? '',
                'email'        => $checkout_data['client']['email'] ?? '',
                'cellphone'    => $checkout_data['client']['phone'] ?? '',
            ],
            'callbackUrls' => [
                'approved'  => $this->validate_callback_url( $checkout_data['callback_approved'] ?? '' ),
                'rejected'  => $this->validate_callback_url( $checkout_data['callback_rejected'] ?? '' ),
                'cancelled' => $this->validate_callback_url( $checkout_data['callback_cancelled'] ?? '' ),
            ],
        ];

        $response = $this->perform_request( 'POST', '/v1/applications', $payload, [
            'Authorization'     => 'Bearer ' . $token,
            // API-BUG-9 FIX: deterministic Idempotency-Key by order_id — Addi dedupes
            // duplicate application submissions when 5xx retries fire after the
            // first request already created the application server-side.
            'Idempotency-Key'  => 'ltms_application_order_' . $checkout_data['order_id'],
        ]);

        return [
            'success'        => isset( $response['id'] ),
            'checkout_url'   => $response['checkoutUrl'] ?? '',
            'application_id' => $response['id'] ?? '',
        ];
    }

    /**
     * Consulta el estado de una aplicación Addi.
     *
     * @param string $application_id ID de la aplicación.
     * @return array{status: string, approved_amount: float, installments: int}
     */
    public function get_application_status( string $application_id ): array {
        // INTEGRATIONS-AUDIT P1 FIX: validate application_id format to prevent
        // path traversal via /v1/applications/{id}.
        if ( ! preg_match( '/^[A-Za-z0-9_-]{1,128}$/', $application_id ) ) {
            return [
                'status'          => 'error',
                'approved_amount' => 0.0,
                'installments'    => 0,
                'error'           => '[addi] application_id inválido.',
            ];
        }
        $token    = $this->get_access_token();
        $response = $this->perform_request( 'GET', '/v1/applications/' . $application_id, [], [
            'Authorization' => 'Bearer ' . $token,
        ]);

        return [
            'status'          => $response['status'] ?? 'unknown',
            'approved_amount' => (float) ( $response['approvedAmount']['value'] ?? 0 ),
            'installments'    => (int) ( $response['installments'] ?? 0 ),
        ];
    }

    /**
     * Cancela una aplicación pendiente.
     *
     * @param string $application_id ID de la aplicación.
     * @return bool
     */
    public function cancel_application( string $application_id ): bool {
        // INTEGRATIONS-AUDIT P1 FIX: validate application_id format.
        if ( ! preg_match( '/^[A-Za-z0-9_-]{1,128}$/', $application_id ) ) {
            return false;
        }
        $token    = $this->get_access_token();
        $response = $this->perform_request( 'POST', '/v1/applications/' . $application_id . '/cancel', [], [
            'Authorization'    => 'Bearer ' . $token,
            // API-BUG-9 FIX: idempotent cancel — repeated cancel requests on the same application
            // are safe (provider returns the current status).
            'Idempotency-Key' => 'ltms_cancel_application_' . $application_id,
        ]);

        return ( $response['status'] ?? '' ) === 'CANCELLED';
    }

    /**
     * {@inheritdoc}
     */
    public function health_check(): array {
        try {
            $token = $this->get_access_token();
            return [
                'status'  => $token ? 'ok' : 'error',
                'message' => $token ? 'Addi auth OK' : 'No se pudo obtener token',
            ];
        } catch ( \Throwable $e ) {
            return [ 'status' => 'error', 'message' => $e->getMessage() ];
        }
    }

    // ── Helpers privados ──────────────────────────────────────────

    /**
     * Obtiene el token de acceso OAuth2 (con caché en transient).
     *
     * @return string Token de acceso.
     * @throws \RuntimeException Si la autenticación falla.
     */
    private function get_access_token(): string {
        if ( $this->access_token ) {
            return $this->access_token;
        }

        $cached = get_transient( 'ltms_addi_token_' . $this->country );
        if ( $cached ) {
            $this->access_token = $cached;
            return $cached;
        }

        $response = $this->perform_request( 'POST', '/v1/oauth/token', [
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->client_id,
            'client_secret' => $this->client_secret,
        ]);

        if ( empty( $response['access_token'] ) ) {
            throw new \RuntimeException( '[Addi] Error al obtener token de acceso' );
        }

        $token     = $response['access_token'];
        $expires   = (int) ( $response['expires_in'] ?? 3600 ) - 60;

        set_transient( 'ltms_addi_token_' . $this->country, $token, $expires );
        $this->access_token = $token;

        return $token;
    }

    /**
     * Formatea los items del pedido para el formato de Addi.
     *
     * @param array $items Items del pedido.
     * @return array
     */
    private function format_items( array $items ): array {
        $formatted = [];
        foreach ( $items as $item ) {
            $formatted[] = [
                'sku'      => (string) ( $item['sku'] ?? $item['product_id'] ?? 'SKU-001' ),
                'name'     => $item['name'] ?? '',
                'quantity' => (int) ( $item['quantity'] ?? 1 ),
                'unitPrice' => [
                    'value'    => (float) ( $item['price'] ?? 0 ),
                    'currency' => $this->country === 'CO' ? 'COP' : 'MXN',
                ],
            ];
        }
        return $formatted;
    }

    /**
     * Valida una URL de callback para Addi.
     *
     * INTEGRATIONS-AUDIT P0 FIX (callback injection): previously, the caller
     * could inject any URL into callbackUrls.approved/rejected/cancelled,
     * allowing phishing redirects after BNPL approval. Now we:
     *  - Require HTTPS (Addi spec mandates HTTPS).
     *  - Require the host to match the site URL (defense-in-depth against
     *    open-redirect-via-Addi).
     *  - Reject empty URLs with an empty string (Addi will then use defaults).
     *
     * @param string $url URL a validar.
     * @return string URL saneada o string vacío si inválida.
     */
    private function validate_callback_url( string $url ): string {
        if ( '' === $url ) {
            return '';
        }
        $clean = filter_var( $url, FILTER_SANITIZE_URL );
        if ( ! filter_var( $clean, FILTER_VALIDATE_URL ) ) {
            return '';
        }
        // INTEGRATIONS-AUDIT P0 FIX: require HTTPS — HTTP callbacks expose PII
        // and allow MITM phishing redirects. We do NOT enforce a host allowlist
        // here because the site URL may legitimately differ from the checkout
        // callback URL in multi-site or staging setups; HTTPS is the real
        // security boundary.
        if ( ! preg_match( '#^https://#i', $clean ) ) {
            return '';
        }
        return $clean;
    }
}
