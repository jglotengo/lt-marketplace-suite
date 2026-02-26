<?php
/**
 * LTMS API Client - XCover (Insurtech - Seguros de Productos)
 *
 * Integración con XCover para ofrecer seguros en el checkout:
 * - Consulta de opciones de seguro disponibles para un producto
 * - Creación de pólizas al momento de la compra
 * - Gestión de reclamaciones
 * - Cancelación y reembolso de pólizas
 *
 * @package    LTMS
 * @subpackage LTMS/includes/api
 * @version    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Api_Xcover
 */
class LTMS_Api_Xcover extends LTMS_Abstract_Api_Client {

    const API_BASE_LIVE    = 'https://api.xcover.com/xcover';
    const API_BASE_SANDBOX = 'https://api.staging.xcover.com/xcover';

    /**
     * @var string Partner code de XCover.
     */
    private string $partner_code;

    /**
     * @var string API key de XCover.
     */
    private string $api_key;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->base_url     = LTMS_ENVIRONMENT === 'production' ? self::API_BASE_LIVE : self::API_BASE_SANDBOX;
        $this->partner_code = LTMS_Core_Config::get( 'ltms_xcover_partner_code', '' );
        $this->api_key      = LTMS_Core_Security::decrypt( LTMS_Core_Config::get( 'ltms_xcover_api_key', '' ) );
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    public function get_provider_slug(): string {
        return 'xcover';
    }

    /**
     * Obtiene cotizaciones de seguro para un producto.
     *
     * @param array $product_data Datos del producto a asegurar.
     * @return array Lista de opciones de cobertura con precios.
     */
    public function get_quotes( array $product_data ): array {
        $payload = [
            'partner_code' => $this->partner_code,
            'request'      => [[
                'policyType'     => $product_data['insurance_type'] ?? 'product_protection',
                'productName'    => $product_data['name'] ?? '',
                'productPrice'   => [
                    'amount'   => (float) $product_data['price'],
                    'currency' => $product_data['currency'] ?? 'COP',
                ],
                'productCategory' => $product_data['category'] ?? 'general',
                'country'         => LTMS_Core_Config::get_country(),
                'policyEndDate'   => gmdate( 'Y-m-d', strtotime( '+1 year' ) ),
            ]],
        ];

        $response = $this->perform_request( 'POST', '/partners/' . $this->partner_code . '/quotes/', $payload );

        return $response['quotes'] ?? [];
    }

    /**
     * Crea una póliza al confirmar la compra.
     *
     * @param string $quote_id    ID de la cotización seleccionada.
     * @param array  $policy_data Datos del asegurado y cobertura.
     * @return array{success: bool, policy_id: string, policy_number: string}
     */
    public function create_policy( string $quote_id, array $policy_data ): array {
        $payload = [
            'quote_id'   => $quote_id,
            'holder'     => [
                'first_name'   => $policy_data['first_name'] ?? '',
                'last_name'    => $policy_data['last_name'] ?? '',
                'email'        => $policy_data['email'] ?? '',
                'phone'        => LTMS_Utils::format_phone_e164( $policy_data['phone'] ?? '' ),
                'country'      => LTMS_Core_Config::get_country(),
            ],
            'order_id'   => $policy_data['order_id'] ?? '',
            'purchase_date' => LTMS_Utils::now_utc(),
        ];

        $response = $this->perform_request(
            'POST',
            '/partners/' . $this->partner_code . '/policies/',
            $payload
        );

        return [
            'success'       => isset( $response['id'] ),
            'policy_id'     => $response['id'] ?? '',
            'policy_number' => $response['policyNumber'] ?? '',
            'certificate_url' => $response['certificateUrl'] ?? '',
        ];
    }

    /**
     * Consulta el estado de una póliza.
     *
     * @param string $policy_id ID de la póliza.
     * @return array
     */
    public function get_policy( string $policy_id ): array {
        return $this->perform_request( 'GET', '/partners/' . $this->partner_code . '/policies/' . $policy_id . '/' );
    }

    /**
     * Cancela una póliza (dentro del período de desistimiento).
     *
     * @param string $policy_id ID de la póliza.
     * @param string $reason    Motivo de cancelación.
     * @return array{success: bool, refund_amount: float}
     */
    public function cancel_policy( string $policy_id, string $reason ): array {
        $payload  = [ 'reason' => sanitize_text_field( $reason ) ];
        $response = $this->perform_request(
            'DELETE',
            '/partners/' . $this->partner_code . '/policies/' . $policy_id . '/',
            $payload
        );

        return [
            'success'       => isset( $response['refundAmount'] ),
            'refund_amount' => (float) ( $response['refundAmount']['amount'] ?? 0 ),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function health_check(): array {
        try {
            $response = $this->perform_request( 'GET', '/partners/' . $this->partner_code . '/' );
            return [
                'status'  => isset( $response['partnerCode'] ) ? 'ok' : 'error',
                'message' => 'XCover API conectado',
            ];
        } catch ( \Throwable $e ) {
            return [ 'status' => 'error', 'message' => $e->getMessage() ];
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function get_default_headers(): array {
        return array_merge( parent::get_default_headers(), [
            'Authorization' => 'ApiKey ' . $this->partner_code . ':' . $this->api_key,
        ]);
    }
}
