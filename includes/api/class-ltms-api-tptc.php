<?php
/**
 * LTMS API Client - TPTC (Red de Afiliados / MLM Network)
 *
 * Integración con TPTC para sincronización de la red MLM:
 * - Registro de nuevos afiliados en la red TPTC
 * - Sincronización de ventas para cálculo de puntos/comisiones
 * - Consulta de estado de afiliados
 * - Reporte de volumen de puntos por período
 *
 * @package    LTMS
 * @subpackage LTMS/includes/api
 * @version    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Api_Tptc
 */
class LTMS_Api_Tptc extends LTMS_Abstract_Api_Client {

    const API_BASE_LIVE    = 'https://api.tptc.com.co/v2';
    const API_BASE_SANDBOX = 'https://sandbox.api.tptc.com.co/v2';

    /**
     * @var string API key de TPTC.
     */
    private string $api_key;

    /**
     * @var string ID del programa de afiliados en TPTC.
     */
    private string $program_id;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->base_url   = LTMS_ENVIRONMENT === 'production' ? self::API_BASE_LIVE : self::API_BASE_SANDBOX;
        $this->api_key    = LTMS_Core_Security::decrypt( LTMS_Core_Config::get( 'ltms_tptc_api_key', '' ) );
        $this->program_id = LTMS_Core_Config::get( 'ltms_tptc_program_id', '' );
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    public function get_provider_slug(): string {
        return 'tptc';
    }

    /**
     * Registra un nuevo afiliado en la red TPTC.
     *
     * @param array $affiliate_data Datos del afiliado.
     * @return array{success: bool, affiliate_id: string, referral_code: string}
     */
    public function register_affiliate( array $affiliate_data ): array {
        $payload = [
            'program_id'   => $this->program_id,
            'external_id'  => 'ltms_' . $affiliate_data['vendor_id'],
            'first_name'   => $affiliate_data['first_name'] ?? '',
            'last_name'    => $affiliate_data['last_name'] ?? '',
            'email'        => $affiliate_data['email'] ?? '',
            'phone'        => LTMS_Utils::format_phone_e164( $affiliate_data['phone'] ?? '' ),
            'document'     => $affiliate_data['document'] ?? '',
            'document_type' => $affiliate_data['document_type'] ?? 'CC',
            'sponsor_code' => $affiliate_data['sponsor_code'] ?? '',
            'country'      => LTMS_Core_Config::get_country(),
        ];

        $response = $this->perform_request( 'POST', '/affiliates', $payload );

        if ( ! empty( $response['affiliate_id'] ) ) {
            // Guardar IDs de TPTC en el perfil del vendedor
            update_user_meta( $affiliate_data['vendor_id'], 'ltms_tptc_affiliate_id', $response['affiliate_id'] );
            update_user_meta( $affiliate_data['vendor_id'], 'ltms_referral_code', $response['referral_code'] ?? '' );
        }

        return [
            'success'       => ! empty( $response['affiliate_id'] ),
            'affiliate_id'  => $response['affiliate_id'] ?? '',
            'referral_code' => $response['referral_code'] ?? '',
        ];
    }

    /**
     * Sincroniza una venta realizada con TPTC para acreditación de puntos.
     *
     * @param array $sale_data Datos de la venta.
     * @return array{success: bool, points_credited: int}
     */
    public function sync_sale( array $sale_data ): array {
        $affiliate_id = get_user_meta( $sale_data['vendor_id'], 'ltms_tptc_affiliate_id', true );
        if ( ! $affiliate_id ) {
            return [ 'success' => false, 'points_credited' => 0, 'message' => 'Afiliado no registrado en TPTC' ];
        }

        $payload = [
            'program_id'   => $this->program_id,
            'affiliate_id' => $affiliate_id,
            'external_order_id' => 'ltms_order_' . $sale_data['order_id'],
            'amount'       => (float) $sale_data['amount'],
            'currency'     => $sale_data['currency'] ?? 'COP',
            'sale_date'    => $sale_data['sale_date'] ?? LTMS_Utils::now_utc(),
            'product_type' => $sale_data['product_type'] ?? 'physical',
        ];

        $response = $this->perform_request( 'POST', '/sales', $payload );

        return [
            'success'         => isset( $response['transaction_id'] ),
            'points_credited' => (int) ( $response['points_credited'] ?? 0 ),
            'transaction_id'  => $response['transaction_id'] ?? '',
        ];
    }

    /**
     * Consulta el estado y balance de puntos de un afiliado.
     *
     * @param int $vendor_id ID del vendedor en LTMS.
     * @return array{status: string, points: int, rank: string}
     */
    public function get_affiliate_status( int $vendor_id ): array {
        $affiliate_id = get_user_meta( $vendor_id, 'ltms_tptc_affiliate_id', true );
        if ( ! $affiliate_id ) {
            return [ 'status' => 'not_registered', 'points' => 0, 'rank' => '' ];
        }

        $response = $this->perform_request( 'GET', '/affiliates/' . $affiliate_id . '/status' );

        return [
            'status' => $response['status'] ?? 'unknown',
            'points' => (int) ( $response['points_balance'] ?? 0 ),
            'rank'   => $response['current_rank'] ?? '',
            'downline_count' => (int) ( $response['downline_count'] ?? 0 ),
        ];
    }

    /**
     * Obtiene el reporte de volumen de un afiliado por período.
     *
     * @param int    $vendor_id  ID del vendedor.
     * @param string $period     Período (YYYY-MM o YYYY-QN).
     * @return array
     */
    public function get_volume_report( int $vendor_id, string $period ): array {
        $affiliate_id = get_user_meta( $vendor_id, 'ltms_tptc_affiliate_id', true );
        if ( ! $affiliate_id ) {
            return [];
        }

        return $this->perform_request( 'GET', '/affiliates/' . $affiliate_id . '/volume/' . $period );
    }

    /**
     * {@inheritdoc}
     */
    public function health_check(): array {
        try {
            $response = $this->perform_request( 'GET', '/health' );
            return [
                'status'  => ( $response['status'] ?? '' ) === 'ok' ? 'ok' : 'error',
                'message' => 'TPTC API conectado',
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
            'X-Api-Key' => $this->api_key,
        ]);
    }
}
