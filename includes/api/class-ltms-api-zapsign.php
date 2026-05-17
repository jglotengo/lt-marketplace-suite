<?php
/**
 * LTMS API Client - ZapSign (Firma Electrónica)
 *
 * Integración con ZapSign para firma digital de contratos:
 * - Creación de documentos para firma
 * - Invitación de firmantes vía email/WhatsApp
 * - Verificación de estado de firma
 * - Descarga de documentos firmados
 * - Webhook de confirmación de firma
 *
 * @package    LTMS
 * @subpackage LTMS/includes/api
 * @version    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Api_Zapsign
 */
final class LTMS_Api_Zapsign extends LTMS_Abstract_API_Client {

    const API_BASE = 'https://api.zapsign.com.br/api/v1';

    /**
     * @var string API token de ZapSign.
     */
    private string $api_token;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->provider_slug = 'zapsign';
        // Must set api_url BEFORE parent::__construct() and verify AFTER
        $this->api_url   = self::API_BASE;
        $this->api_token = LTMS_Core_Security::decrypt( LTMS_Core_Config::get( 'ltms_zapsign_api_token', '' ) );
        parent::__construct();

        // Re-set api_url AFTER parent in case parent overrides it (defensive)
        if ( empty( $this->api_url ) ) {
            $this->api_url = self::API_BASE;
        }

        // ZapSign usa Bearer token en Authorization header
        $this->default_headers['Authorization'] = 'Bearer ' . $this->api_token;
    }

    /**
     * {@inheritdoc}
     */
    public function get_provider_slug(): string {
        return 'zapsign';
    }

    /**
     * Returns the canonical API base URL (always the constant, never empty).
     * Used by health_check() and QA diagnostics to bypass OPcache stale bytecode.
     */
    public function get_api_base_url(): string {
        return self::API_BASE;
    }

    /**
     * Override perform_request to ensure api_url is always set, even if OPcache
     * served an old version of this class without the constructor assignment.
     *
     * {@inheritdoc}
     */
    protected function perform_request(
        string $method,
        string $endpoint,
        array  $data    = [],
        array  $headers = [],
        bool   $retry   = true
    ): array {
        // M-66 definitive fix: always force api_url and Authorization header at call time.
        // OPcache on some servers serves stale bytecode that skips the constructor assignment,
        // leaving api_url empty. Using the constant here is immune to that.
        $this->api_url = self::API_BASE;

        // Also ensure auth token header is always present even if default_headers was empty.
        if ( ! empty( $this->api_token ) && empty( $headers['Authorization'] ) ) {
            $headers['Authorization'] = 'Bearer ' . $this->api_token;
        }

        return parent::perform_request( $method, $endpoint, $data, $headers, $retry );
    }

    /**
     * Crea un documento para firma y envía invitaciones a los firmantes.
     *
     * @param array $document_data Datos del documento y firmantes.
     * @return array{success: bool, doc_token: string, sign_url: string}
     * @throws \RuntimeException
     */
    public function create_document( array $document_data ): array {
        $payload = [
            'name'        => $document_data['name'] ?? 'Contrato LTMS',
            'url_pdf'     => $document_data['pdf_url'] ?? '',
            'lang'        => $document_data['language'] ?? 'es',
            'signers'     => $this->format_signers( $document_data['signers'] ?? [] ),
            'send_automatic_email' => true,
            'brand_logo'  => $document_data['brand_logo'] ?? '',
            'brand_name'  => $document_data['brand_name'] ?? get_bloginfo( 'name' ),
            'folder_path' => 'LTMS/Contratos/' . gmdate( 'Y' ),
        ];

        // Adjuntar PDF base64 si no hay URL
        if ( empty( $payload['url_pdf'] ) && ! empty( $document_data['pdf_base64'] ) ) {
            $payload['base64_pdf']   = $document_data['pdf_base64'];
            $payload['url_pdf']      = null;
        }

        $response = $this->perform_request( 'POST', '/docs/', $payload );

        return [
            'success'   => isset( $response['token'] ),
            'doc_token' => $response['token'] ?? '',
            'sign_url'  => $response['signers'][0]['sign_url'] ?? '',
        ];
    }

    /**
     * Consulta el estado de un documento.
     *
     * @param string $doc_token Token del documento.
     * @return array{status: string, signers: array}
     */
    public function get_document_status( string $doc_token ): array {
        $response = $this->perform_request( 'GET', '/docs/' . $doc_token . '/' );

        $signers = [];
        foreach ( ( $response['signers'] ?? [] ) as $signer ) {
            $signers[] = [
                'name'      => $signer['name'] ?? '',
                'email'     => $signer['email'] ?? '',
                'status'    => $signer['status'] ?? 'pending',
                'signed_at' => $signer['signed_at'] ?? null,
            ];
        }

        // Determinar estado general
        $all_signed = ! empty( $signers ) && count( array_filter( $signers, fn( $s ) => $s['status'] === 'signed' ) ) === count( $signers );

        return [
            'status'  => $all_signed ? 'completed' : 'pending',
            'signers' => $signers,
        ];
    }

    /**
     * Descarga el documento firmado en base64.
     *
     * @param string $doc_token Token del documento.
     * @return string Base64 del PDF firmado.
     */
    public function download_signed_document( string $doc_token ): string {
        $response = $this->perform_request( 'GET', '/docs/' . $doc_token . '/download/' );
        return $response['base64_pdf'] ?? '';
    }

    /**
     * Elimina un documento de ZapSign.
     *
     * @param string $doc_token Token del documento.
     * @return bool
     */
    public function delete_document( string $doc_token ): bool {
        $response = $this->perform_request( 'DELETE', '/docs/' . $doc_token . '/' );
        return empty( $response ) || isset( $response['deleted'] );
    }

    /**
     * Crea un contrato de adhesión para un nuevo vendedor y lo envía para firma.
     *
     * @param int    $vendor_id  ID del vendedor.
     * @param string $pdf_url    URL del PDF del contrato generado.
     * @return array
     */
    public function send_vendor_contract( int $vendor_id, string $pdf_url ): array {
        $user = get_userdata( $vendor_id );
        if ( ! $user ) {
            throw new \InvalidArgumentException( "Vendedor #$vendor_id no encontrado" );
        }

        $result = $this->create_document([
            'name'     => sprintf( 'Contrato Vendedor - %s - %s', $user->display_name, gmdate( 'Y' ) ),
            'pdf_url'  => $pdf_url,
            'signers'  => [[
                'name'  => $user->display_name,
                'email' => $user->user_email,
                'phone' => get_user_meta( $vendor_id, 'billing_phone', true ),
            ]],
        ]);

        if ( $result['success'] ) {
            update_user_meta( $vendor_id, 'ltms_contract_token', $result['doc_token'] );
            update_user_meta( $vendor_id, 'ltms_contract_status', 'pending' );
            update_user_meta( $vendor_id, 'ltms_contract_sent_at', LTMS_Utils::now_utc() );
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function health_check(): array {
        // ZapSign: GET /api/v1/users/ verifica token sin consumir cuota de documentos
        $response = $this->perform_request( 'GET', '/users/' );

        if ( is_wp_error( $response ) ) {
            return [
                'connected' => false,
                'status'    => 'error',
                'message'   => $response->get_error_message(),
            ];
        }

        $code = (int) ( $response['http_code'] ?? $response['status'] ?? 0 );
        if ( $code >= 200 && $code < 300 ) {
            return [
                'connected' => true,
                'status'    => 'ok',
                'account'   => $response['email'] ?? ( $response['name'] ?? 'ZapSign' ),
                'message'   => 'ZapSign API conectada correctamente',
            ];
        }

        return [
            'connected' => false,
            'status'    => 'error',
            'message'   => '[zapsign] Health check HTTP ' . $code,
        ];
    }

    // ── Helpers privados ──────────────────────────────────────────

    /**
     * {@inheritdoc}
     */
    protected function get_default_headers(): array {
        return array_merge( parent::get_default_headers(), [
            'Authorization' => 'Bearer ' . $this->api_token,
        ]);
    }

    /**
     * Formatea los firmantes para el payload de ZapSign.
     *
     * @param array $signers Lista de firmantes.
     * @return array
     */
    private function format_signers( array $signers ): array {
        return array_map( function( $signer ) {
            return [
                'name'                 => $signer['name'] ?? '',
                'email'                => $signer['email'] ?? '',
                'phone_country'        => $signer['phone_country'] ?? '57',
                'phone_number'         => preg_replace( '/\D/', '', $signer['phone'] ?? '' ),
                'auth_mode'            => $signer['auth_mode'] ?? 'assinaturaTela',
                'send_automatic_email' => true,
                'send_automatic_whatsapp' => ! empty( $signer['phone'] ),
            ];
        }, $signers );
    }
}
