<?php
/**
 * LTMS API Openpay - Cliente de Pasarela de Pago
 *
 * Integración completa con la API de Openpay para Colombia y México.
 * Soporta: Cobros con tarjeta (tokenización), PSE (Colombia), OXXO (México),
 * reembolsos, consulta de transacciones y webhooks.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/api
 * @version    1.5.0
 * @see        https://www.openpay.mx/docs/api/
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Api_Openpay
 */
final class LTMS_Api_Openpay extends LTMS_Abstract_API_Client {

    /**
     * ID de la cuenta Openpay.
     *
     * @var string
     */
    private string $merchant_id;

    /**
     * Clave privada de la API.
     *
     * @var string
     */
    private string $private_key;

    /**
     * País de operación (CO|MX).
     *
     * @var string
     */
    private string $country;

    /**
     * Constructor.
     *
     * @throws \RuntimeException Si las credenciales no están configuradas.
     */
    public function __construct( ?string $country_override = null ) {
        $this->provider_slug = 'openpay';
        $this->country       = $country_override
                             ?? LTMS_Core_Config::get_country();
        $this->timeout       = 45; // Openpay puede tomar hasta 30s

        $environment = LTMS_Core_Config::is_production() ? 'live' : 'sandbox';
        $env_key     = "ltms_openpay_{$environment}";

        // URLs según país y entorno
        if ( $this->country === 'MX' ) {
            $this->api_url = LTMS_Core_Config::is_production()
                ? 'https://api.openpay.mx/v1'
                : 'https://sandbox-api.openpay.mx/v1';
        } else {
            // Colombia
            $this->api_url = LTMS_Core_Config::is_production()
                ? 'https://api.openpay.co/v1'
                : 'https://sandbox-api.openpay.co/v1';
        }

        // Buscar primero por clave con país (ltms_openpay_CO_*), luego genérica (ltms_openpay_*)
        $merchant_raw = LTMS_Core_Config::get( "ltms_openpay_{$this->country}_merchant_id" )
                     ?: LTMS_Core_Config::get( 'ltms_openpay_merchant_id' );
        $private_raw  = LTMS_Core_Config::get( "ltms_openpay_{$this->country}_private_key" )
                     ?: LTMS_Core_Config::get( 'ltms_openpay_private_key' );

        if ( empty( $merchant_raw ) || empty( $private_raw ) ) {
            throw new \RuntimeException(
                sprintf( 'LTMS Openpay: Credenciales no configuradas para país %s.', $this->country )
            );
        }

        // merchant_id no se cifra; private_key solo se descifra si tiene formato 'v1:...'
        $this->merchant_id = (string) $merchant_raw;
        $this->private_key = str_starts_with( (string) $private_raw, 'v1:' )
            ? LTMS_Core_Security::decrypt( $private_raw )
            : (string) $private_raw;
    }

    /**
     * Realiza un cobro con token de tarjeta de crédito/débito.
     *
     * @param string $token_id     Token de la tarjeta (generado por JS de Openpay).
     * @param float  $amount       Monto en la moneda local (COP o MXN).
     * @param string $description  Descripción del cobro.
     * @param array  $customer     Datos del cliente [name, email, phone_number].
     * @param string $order_id     ID de referencia interna del pedido.
     * @param string $device_id    ID del dispositivo (anti-fraude).
     * @param bool   $capture      true=captura inmediata, false=preautorización.
     * @return array{id: string, status: string, amount: float, authorization: string}
     * @throws \RuntimeException Si el cobro falla.
     */
    public function create_charge(
        string $token_id,
        float  $amount,
        string $description,
        array  $customer,
        string $order_id,
        string $device_id = '',
        bool   $capture   = true
    ): array {
        $payload = [
            'method'        => 'card',
            'source_id'     => $token_id,
            'amount'        => $this->format_amount( $amount ),
            'currency'      => $this->country === 'MX' ? 'MXN' : 'COP',
            'description'   => substr( sanitize_text_field( $description ), 0, 250 ),
            'order_id'      => $order_id,
            'capture'       => $capture,
            'customer'      => [
                'name'         => sanitize_text_field( $customer['name'] ?? '' ),
                'email'        => sanitize_email( $customer['email'] ?? '' ),
                'phone_number' => LTMS_Utils::sanitize_phone( $customer['phone'] ?? '' ),
            ],
        ];

        if ( ! empty( $device_id ) ) {
            $payload['device_session_id'] = $device_id;
        }

        $response = $this->perform_request(
            'POST',
            "/{$this->merchant_id}/charges",
            $payload
        );

        return [
            'id'            => $response['id'] ?? '',
            'status'        => $response['status'] ?? '',
            'amount'        => (float) ( $response['amount'] ?? 0 ),
            'authorization' => $response['authorization'] ?? '',
            'raw'           => $response,
        ];
    }

    /**
     * Crea un cargo por PSE (Colombia) - Débito bancario directo.
     *
     * @param float  $amount       Monto en COP.
     * @param string $description  Descripción.
     * @param array  $customer     Datos del cliente.
     * @param string $bank_code    Código del banco (ej: '1022' = Bancolombia).
     * @param string $redirect_url URL de retorno después del pago.
     * @param string $order_id     ID de referencia.
     * @return array{id: string, payment_method: array, status: string}
     */
    public function create_pse_charge(
        float  $amount,
        string $description,
        array  $customer,
        string $bank_code,
        string $redirect_url,
        string $order_id
    ): array {
        if ( $this->country !== 'CO' ) {
            throw new \RuntimeException( 'PSE solo disponible en Colombia.' );
        }

        $payload = [
            'method'       => 'bank_account',
            'amount'       => $this->format_amount( $amount ),
            'currency'     => 'COP',
            'description'  => substr( $description, 0, 250 ),
            'order_id'     => $order_id,
            'redirect_url' => esc_url_raw( $redirect_url ),
            'customer'     => [
                'name'    => sanitize_text_field( $customer['name'] ?? '' ),
                'email'   => sanitize_email( $customer['email'] ?? '' ),
                'address' => [
                    'city'         => sanitize_text_field( $customer['city'] ?? 'Bogotá' ),
                    'country_code' => 'CO',
                ],
            ],
            'payment_method' => [
                'type'      => 'bank_transfer',
                'bank_code' => $bank_code,
            ],
        ];

        return $this->perform_request(
            'POST',
            "/{$this->merchant_id}/charges",
            $payload
        );
    }

    /**
     * Crea una referencia SPEI para México (transferencia bancaria).
     * M-114: el checkout México usaba create_pse_charge() (Colombia) incorrectamente.
     *
     * @param float  $amount      Monto en MXN.
     * @param string $description Descripción.
     * @param array  $customer    Datos del cliente.
     * @param string $order_id    ID de referencia.
     * @return array{id: string, payment_method: array{clabe: string, name: string}}
     */
    public function create_spei_reference(
        float  $amount,
        string $description,
        array  $customer,
        string $order_id
    ): array {
        if ( $this->country !== 'MX' ) {
            throw new \RuntimeException( 'SPEI solo disponible en México.' );
        }

        $payload = [
            'method'      => 'bank_account',
            'amount'      => $this->format_amount( $amount ),
            'currency'    => 'MXN',
            'description' => substr( $description, 0, 250 ),
            'order_id'    => $order_id,
            'customer'    => [
                'name'  => sanitize_text_field( $customer['name'] ?? '' ),
                'email' => sanitize_email( $customer['email'] ?? '' ),
            ],
        ];

        return $this->perform_request( 'POST', "/{$this->merchant_id}/charges", $payload );
    }

    /**
     * Crea un cargo por OXXO (México).
     *
     * @param float  $amount      Monto en MXN.
     * @param string $description Descripción.
     * @param array  $customer    Datos del cliente.
     * @param string $order_id    ID de referencia.
     * @return array{id: string, payment_method: array{reference: string, barcode_url: string}}
     */
    public function create_oxxo_charge(
        float  $amount,
        string $description,
        array  $customer,
        string $order_id
    ): array {
        if ( $this->country !== 'MX' ) {
            throw new \RuntimeException( 'OXXO solo disponible en México.' );
        }

        $payload = [
            'method'      => 'store',
            'amount'      => $this->format_amount( $amount ),
            'currency'    => 'MXN',
            'description' => substr( $description, 0, 250 ),
            'order_id'    => $order_id,
            'customer'    => [
                'name'  => sanitize_text_field( $customer['name'] ?? '' ),
                'email' => sanitize_email( $customer['email'] ?? '' ),
            ],
        ];

        return $this->perform_request(
            'POST',
            "/{$this->merchant_id}/charges",
            $payload
        );
    }

    /**
     * Procesa un reembolso total o parcial.
     *
     * @param string $charge_id   ID del cobro original en Openpay.
     * @param float  $amount      Monto a reembolsar (0 = reembolso total).
     * @param string $description Motivo del reembolso.
     * @return array
     * @throws \RuntimeException Si el reembolso falla.
     */
    public function create_refund( string $charge_id, float $amount = 0.0, string $description = '' ): array {
        $payload = [ 'description' => substr( $description ?: 'Reembolso', 0, 250 ) ];

        if ( $amount > 0 ) {
            $payload['amount'] = $this->format_amount( $amount );
        }

        return $this->perform_request(
            'POST',
            "/{$this->merchant_id}/charges/{$charge_id}/refund",
            $payload
        );
    }

    /**
     * Consulta el estado de un cobro por su ID.
     *
     * @param string $charge_id ID del cobro en Openpay.
     * @return array
     */
    public function get_charge( string $charge_id ): array {
        return $this->perform_request(
            'GET',
            "/{$this->merchant_id}/charges/{$charge_id}"
        );
    }

    /**
     * Verifica la salud de la conexión con Openpay.
     *
     * @return array{status: string, message: string, latency_ms?: int}
     */
    /**
     * Obtiene la lista de bancos disponibles para PSE (Colombia).
     * Cachea el resultado 6 horas en un transient de WordPress.
     *
     * @return array Lista de bancos [ ['name' => ..., 'code' => ...] ]
     */
    public function get_pse_banks(): array {
        $cache_key = 'ltms_pse_banks_' . strtolower( $this->country );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) && ! empty( $cached ) ) {
            return $cached;
        }

        try {
            $result = $this->perform_request( 'GET', "/{$this->merchant_id}/pse/banks", [], [], false );
            if ( ! empty( $result ) && is_array( $result ) ) {
                set_transient( $cache_key, $result, 6 * HOUR_IN_SECONDS );
                return $result;
            }
        } catch ( \Throwable $e ) {
            // Silencioso — retornar lista vacía
        }

        return [];
    }

    public function health_check(): array {
        $start = microtime( true );

        try {
            $response = $this->perform_request(
                'GET',
                "/{$this->merchant_id}/charges",
                [],
                [],
                false
            );
            $latency = (int) round( ( microtime( true ) - $start ) * 1000 );

            return [
                'status'     => 'ok',
                'message'    => 'Openpay conectado correctamente',
                'latency_ms' => $latency,
                'country'    => $this->country,
            ];
        } catch ( \Throwable $e ) {
            return [
                'status'  => 'error',
                'message' => 'Error conectando a Openpay: ' . $e->getMessage(),
            ];
        }
    }


    /**
     * Crea un desembolso (payout) a la cuenta bancaria de un vendedor.
     *
     * Openpay soporta payouts a cuentas CLABE (MX) y cuentas bancarias CO.
     * Endpoint: POST /{merchant_id}/payouts
     *
     * @param float  $amount         Monto a desembolsar.
     * @param string $bank_account   Número de cuenta / CLABE del vendedor.
     * @param string $bank_code      Código del banco (CO: NIT banco, MX: no requerido para CLABE).
     * @param string $holder_name    Nombre del titular de la cuenta.
     * @param string $description    Descripción del pago.
     * @param string $order_id       Referencia interna (payout_id).
     * @return array{id: string, status: string, amount: float}
     * @throws \RuntimeException Si el desembolso falla.
     */
    public function create_disbursement(
        float  $amount,
        string $bank_account,
        string $bank_code,
        string $holder_name,
        string $description,
        string $order_id
    ): array {
        $currency = $this->country === 'MX' ? 'MXN' : 'COP';

        $payload = [
            'method'      => 'bank_account',
            'amount'      => $this->format_amount( $amount ),
            'currency'    => $currency,
            'description' => substr( sanitize_text_field( $description ), 0, 250 ),
            'order_id'    => $order_id,
            'bank_account' => [
                'clabe'        => $bank_account, // CLABE en MX, cuenta en CO
                'bank_code'    => $bank_code,
                'holder_name'  => sanitize_text_field( $holder_name ),
            ],
        ];

        $response = $this->perform_request(
            'POST',
            "/{$this->merchant_id}/payouts",
            $payload
        );

        if ( empty( $response['id'] ) ) {
            throw new \RuntimeException(
                sprintf( 'Openpay disbursement falló para orden %s: %s',
                    $order_id,
                    $response['description'] ?? 'Error desconocido'
                )
            );
        }

        LTMS_Core_Logger::info(
            'OPENPAY_DISBURSEMENT',
            sprintf( 'Desembolso Openpay #%s — %s %s — vendedor: %s',
                $response['id'],
                $currency,
                $amount,
                $holder_name
            ),
            [ 'openpay_id' => $response['id'], 'order_id' => $order_id, 'amount' => $amount ]
        );

        return [
            'id'     => $response['id'],
            'status' => $response['status'] ?? 'in_progress',
            'amount' => (float) ( $response['amount'] ?? $amount ),
            'raw'    => $response,
        ];
    }

    /**
     * Alias para compatibilidad con WC_Payment_Gateway::process_payment().
     * Acepta array de parámetros y delega a create_charge().
     *
     * @param array $params [source_id, amount, description, customer, order_id, device_session_id].
     * @return array
     */
    public function charge( array $params ): array {
        return $this->create_charge(
            $params['source_id']          ?? '',
            (float) ( $params['amount']   ?? 0 ),
            $params['description']        ?? '',
            $params['customer']           ?? [],
            (string) ( $params['order_id'] ?? '' ),
            $params['device_session_id']  ?? '',
            true
        );
    }

    /**
     * Alias para compatibilidad con WC_Payment_Gateway::process_refund().
     *
     * @param string $charge_id   ID del cobro Openpay.
     * @param float  $amount      Monto a reembolsar (0 = total).
     * @param string $description Motivo.
     * @return array
     */
    public function refund( string $charge_id, float $amount = 0.0, string $description = '' ): array {
        return $this->create_refund( $charge_id, $amount, $description );
    }

    /**
     * Sobrescribe perform_request para agregar autenticación HTTP Basic.
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
        // Openpay usa HTTP Basic Auth: private_key como usuario, sin contraseña
        $auth_header = 'Basic ' . base64_encode( $this->private_key . ':' );
        $headers['Authorization'] = $auth_header;

        return parent::perform_request( $method, $endpoint, $data, $headers, $retry );
    }

    /**
     * Formatea el monto según los requisitos de Openpay.
     * Colombia: monto en pesos (sin decimales para COP). México: decimales.
     *
     * @param float $amount Monto.
     * @return float|int
     */
    private function format_amount( float $amount ): float|int {
        return $this->country === 'CO'
            ? (int) round( $amount ) // COP sin decimales
            : round( $amount, 2 );   // MXN con 2 decimales
    }
}
