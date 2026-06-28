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
     * Modo sandbox (desarrollo sin plan de pago).
     */
    private bool $sandbox;

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

        // Modo sandbox: si no hay plan de pago, usar sandbox=true en dev
        $this->sandbox = ( 'yes' === LTMS_Core_Config::get( 'ltms_zapsign_sandbox', 'no' ) );

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
     * Indica si el cliente esta en modo sandbox.
     */
    public function is_sandbox(): bool {
        return $this->sandbox;
    }

    /**
     * {@inheritdoc}
     * Declared public explicitly to match abstract base class visibility.
     * Required because PHP-FPM OPcache may serve stale bytecode with protected visibility.
     */
    public function execute_http_request(
        string $method,
        string $endpoint,
        array  $data    = [],
        array  $headers = [],
        bool   $retry   = true
    ): array {
        // Ensure api_url and auth header are always set (M-66 fix).
        $this->api_url = self::API_BASE;
        if ( ! empty( $this->api_token ) && empty( $headers['Authorization'] ) ) {
            $headers['Authorization'] = 'Bearer ' . $this->api_token;
        }
        return parent::execute_http_request( $method, $endpoint, $data, $headers, $retry );
    }

    /**
     * Crea un documento para firma y envía invitaciones a los firmantes.
     *
     * @param array $document_data Datos del documento y firmantes.
     * @return array{success: bool, doc_token: string, sign_url: string}
     * @throws \RuntimeException
     */
    public function create_document( array $document_data ): array {
        // ZapSign requiere exactamente uno de: url_pdf o base64_pdf.
        // Campos nulos o vacíos en el payload causan HTTP 400.
        $payload = [
            'name'                 => $document_data['name'] ?? 'Contrato LTMS',
            'lang'                 => $document_data['language'] ?? 'es',
            'signers'              => $this->format_signers( $document_data['signers'] ?? [] ),
            'send_automatic_email' => true,
            'sandbox'              => $this->sandbox,
        ];

        // brand_name solo si está configurado (campo opcional)
        $brand = $document_data['brand_name'] ?? get_bloginfo( 'name' );
        if ( ! empty( $brand ) ) {
            $payload['brand_name'] = $brand;
        }

        // brand_logo solo si tiene valor (campo opcional)
        if ( ! empty( $document_data['brand_logo'] ) ) {
            $payload['brand_logo'] = $document_data['brand_logo'];
        }

        // folder_path — incluir siempre con valor por defecto (ZapSign lo acepta en todos los planes)
        $payload['folder_path'] = $document_data['folder_path'] ?? ( 'LTMS/Contratos/' . gmdate( 'Y' ) );

        // Fuente del PDF: template_id (preferido), url_pdf, o base64_pdf
        $template_id = $document_data['template_id'] ?? '';
        $pdf_url     = $document_data['pdf_url'] ?? '';
        $pdf_base64  = $document_data['pdf_base64'] ?? '';

        if ( ! empty( $template_id ) ) {
            // Usar plantilla ZapSign — crea documento desde el modelo sin subir PDF
            $payload['template_id'] = $template_id;
        } elseif ( ! empty( $pdf_url ) ) {
            // Sanitizar URL sin depender de esc_url_raw (no disponible en contexto CLI/test)
            $clean_url = filter_var( $pdf_url, FILTER_SANITIZE_URL );
            if ( ! filter_var( $clean_url, FILTER_VALIDATE_URL ) ) {
                throw new \RuntimeException( '[zapsign] pdf_url no es una URL válida: ' . $pdf_url );
            }
            $payload['url_pdf'] = $clean_url;
        } elseif ( ! empty( $pdf_base64 ) ) {
            $payload['base64_pdf'] = $pdf_base64;
        } else {
            throw new \RuntimeException( '[zapsign] create_document requiere pdf_url o pdf_base64.' );
        }

        $response = $this->execute_http_request( 'POST', '/docs/', $payload );

        if ( empty( $response['token'] ) ) {
            return [
                'success'   => false,
                'doc_token' => '',
                'sign_url'  => '',
                'open_id'   => '',
                'status'    => 'error',
                'error'     => $response['error'] ?? wp_json_encode( $response ),
            ];
        }

        return [
            'success'   => true,
            'doc_token' => $response['token'],
            'sign_url'  => $response['signers'][0]['sign_url'] ?? '',
            'open_id'   => $response['open_id'] ?? '',
            'status'    => $response['status'] ?? 'pending',
        ];
    }

    /**
     * Consulta el estado de un documento.
     *
     * @param string $doc_token Token del documento.
     * @return array{status: string, signers: array}
     */
    public function get_document_status( string $doc_token ): array {
        $response = $this->execute_http_request( 'GET', '/docs/' . $doc_token . '/' );

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
        $response = $this->execute_http_request( 'GET', '/docs/' . $doc_token . '/download/' );
        return $response['base64_pdf'] ?? '';
    }

    /**
     * Elimina un documento de ZapSign.
     *
     * @param string $doc_token Token del documento.
     * @return bool
     */
    public function delete_document( string $doc_token ): bool {
        $response = $this->execute_http_request( 'DELETE', '/docs/' . $doc_token . '/' );
        return empty( $response ) || isset( $response['deleted'] );
    }

    /**
     * Crea un contrato de adhesión para un nuevo vendedor y lo envía para firma.
     * Usa template_id si está configurado (preferido), o pdf_url como fallback.
     *
     * @param int    $vendor_id  ID del vendedor.
     * @param string $pdf_url    URL del PDF del contrato (fallback si no hay template).
     * @return array
     */
    public function send_vendor_contract( int $vendor_id, string $pdf_url = '' ): array {
        $user = get_userdata( $vendor_id );
        if ( ! $user ) {
            throw new \InvalidArgumentException( "Vendedor #$vendor_id no encontrado" );
        }

        // Leer template_id configurado (modo preferido — no requiere resubir PDF)
        $template_id = LTMS_Core_Config::get( 'ltms_zapsign_vendor_template_id', '' );
        if ( empty( $template_id ) ) {
            $template_id = get_option( 'ltms_zapsign_vendor_template_id', '' );
        }

        $doc_data = [
            'name'    => sprintf( 'Contrato Vendedor - %s - %s', $user->display_name, gmdate( 'Y' ) ),
            'signers' => [[
                'name'        => $user->display_name,
                'email'       => $user->user_email,
                'phone'       => get_user_meta( $vendor_id, 'billing_phone', true ),
                'external_id' => (string) $vendor_id,
            ]],
        ];

        if ( ! empty( $template_id ) ) {
            // Usar plantilla ZapSign — NO requiere PDF adicional
            $doc_data['template_id'] = $template_id;
        } elseif ( ! empty( $pdf_url ) ) {
            // Preferir base64 si el PDF es local (evita bloqueo Cloudflare en url_pdf)
            $local_path = $this->url_to_local_path( $pdf_url );
            if ( $local_path && file_exists( $local_path ) ) {
                $doc_data['pdf_base64'] = base64_encode( file_get_contents( $local_path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
            } else {
                $doc_data['pdf_url'] = $pdf_url;
            }
        } else {
            // Fallback: intentar leer PDF desde Media Library o config
            $attachment_id = (int) LTMS_Core_Config::get( 'ltms_zapsign_contract_attachment_id', 0 );
            $fallback_url  = LTMS_Core_Config::get( 'ltms_zapsign_contract_pdf_url', '' );
            if ( empty( $fallback_url ) ) {
                $fallback_url = get_option( 'ltms_zapsign_contract_pdf_url', '' );
            }
            if ( $attachment_id > 0 ) {
                $att_path = get_attached_file( $attachment_id );
                if ( $att_path && file_exists( $att_path ) ) {
                    $doc_data['pdf_base64'] = base64_encode( file_get_contents( $att_path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
                } else {
                    $doc_data['pdf_url'] = wp_get_attachment_url( $attachment_id );
                }
            } elseif ( ! empty( $fallback_url ) ) {
                $local_path = $this->url_to_local_path( $fallback_url );
                if ( $local_path && file_exists( $local_path ) ) {
                    $doc_data['pdf_base64'] = base64_encode( file_get_contents( $local_path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
                } else {
                    $doc_data['pdf_url'] = $fallback_url;
                }
            } else {
                throw new \RuntimeException( '[zapsign] send_vendor_contract: se requiere template_id, pdf_url, o configurar el PDF del contrato.' );
            }
        }

        $result = $this->create_document( $doc_data );

        if ( ! empty( $result['doc_token'] ) ) {
            update_user_meta( $vendor_id, 'ltms_contract_token', $result['doc_token'] );
            update_user_meta( $vendor_id, 'ltms_contract_status', 'pending' );
            update_user_meta( $vendor_id, 'ltms_contract_sent_at', gmdate( 'Y-m-d H:i:s' ) );
            if ( ! empty( $result['sign_url'] ) ) {
                update_user_meta( $vendor_id, 'ltms_contract_sign_url', $result['sign_url'] );
            }
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function health_check(): array {
        try {
            if ( $this->sandbox ) {
                // En sandbox: listar documentos con ?sandbox=true
                // Una lista vacía [] también es respuesta válida (200)
                $response  = $this->execute_http_request( 'GET', '/docs/' );
                // ZapSign devuelve array con 'results' o directamente un array
                $connected = is_array( $response ) && ! isset( $response['code'] );
                return [
                    'connected' => $connected,
                    'status'    => $connected ? 'ok' : 'error',
                    'message'   => $connected ? 'ZapSign API conectada (sandbox)' : ( $response['detail'] ?? ( $response['message'] ?? 'Error sandbox' ) ),
                    'sandbox'   => true,
                ];
            }

            // Producción: GET /users/ requiere plan API
            $response  = $this->execute_http_request( 'GET', '/users/' );
            $connected = is_array( $response ) && ! isset( $response['code'] );
            return [
                'connected' => $connected,
                'status'    => $connected ? 'ok' : 'error',
                'account'   => ! $connected ? null : ( $response[0]['email'] ?? ( $response['email'] ?? 'ZapSign' ) ),
                'message'   => $connected ? 'ZapSign API conectada correctamente' : ( $response['detail'] ?? ( $response['message'] ?? 'Error desconocido' ) ),
            ];
        } catch ( \Throwable $e ) {
            return [
                'connected' => false,
                'status'    => 'error',
                'message'   => $e->getMessage(),
            ];
        }
    }

    /**
     * Convierte una URL del sitio a ruta local en disco.
     * Retorna null si la URL es externa.
     */
    private function url_to_local_path( string $url ): ?string {
        // M-ZAP-1 + M-ZAP-2: convertir URL del sitio a ruta absoluta en disco.
        // Usa parse_url() nativa de PHP en vez de wp_parse_url() para evitar
        // "MissingFunctionExpectations" en entorno UNIT ONLY (Brain\Monkey).
        $abspath = defined( 'ABSPATH' ) ? rtrim( ABSPATH, '/' ) : sys_get_temp_dir();

        $parsed   = parse_url( $url );
        $url_path = $parsed['path'] ?? '';
        if ( empty( $url_path ) || '/' === $url_path ) {
            return null;
        }

        // Verificar que pertenece al sitio actual
        $siteurl   = function_exists( 'get_option' ) ? get_option( 'siteurl', '' ) : '';
        $site_parsed = parse_url( $siteurl );
        $site_host = $site_parsed['host'] ?? '';
        $url_host  = $parsed['host'] ?? '';
        if ( $url_host && $site_host && $url_host !== $site_host ) {
            return null; // URL de otro dominio
        }

        // Eliminar subdirectorio de instalación de WordPress si existe
        $wp_subdir = $site_parsed['path'] ?? '';
        if ( $wp_subdir && strpos( $url_path, $wp_subdir ) === 0 ) {
            $url_path = substr( $url_path, strlen( $wp_subdir ) );
        }

        $local = $abspath . '/' . ltrim( $url_path, '/' );
        return file_exists( $local ) ? $local : null;
    }


    /**
     * Crea un documento en ZapSign a partir de una plantilla (template_id).
     * Usa el endpoint /models/{template_id}/create-doc/ que ZapSign expone
     * para documentos basados en modelos pre-configurados.
     *
     * El payload puede incluir: name, external_id, lang, send_automatic_email,
     * y un array 'signers' con los firmantes y sus campos de datos ('data').
     *
     * @param string $template_id ID de la plantilla ZapSign.
     * @param array  $payload     Cuerpo del documento a crear.
     * @return array{success: bool, doc_token: string, sign_url: string, open_id: string, status: string}
     */
    public function create_document_from_template( string $template_id, array $payload ): array {
        $response = $this->execute_http_request( 'POST', '/models/' . $template_id . '/create-doc/', $payload );

        if ( empty( $response['token'] ) ) {
            return [
                'success'   => false,
                'doc_token' => '',
                'sign_url'  => '',
                'open_id'   => '',
                'status'    => 'error',
                'error'     => $response['error'] ?? wp_json_encode( $response ),
            ];
        }

        return [
            'success'   => true,
            'doc_token' => $response['token'],
            'sign_url'  => $response['signers'][0]['sign_url'] ?? '',
            'open_id'   => $response['open_id'] ?? '',
            'status'    => $response['status'] ?? 'pending',
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
