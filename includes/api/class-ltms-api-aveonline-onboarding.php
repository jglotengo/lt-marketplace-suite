<?php
/**
 * LTMS API Client — Aveonline Onboarding (Registro de Clientes)
 *
 * Orquesta el flujo de 4 pasos para registrar vendedores en Aveonline:
 *   Paso 1 → acceptTerms        (crea el registro inicial)
 *   Paso 2 → createLead         (datos del lead + password → retorna seed)
 *   Paso 3 → createCompanyStepOne (identidad + documentos + CIFIN)
 *   Paso 4 → createCompanyStepTwo (datos comerciales → crea empresa en AVE)
 *
 * URL Base: https://api.aveonline.co/api-onboarding/public/api/v1/external/onboarding
 *
 * Autenticación: JWT Bearer token configurado en ltms_aveonline_onboarding_token
 *
 * @package    LTMS
 * @subpackage LTMS/includes/api
 * @version    1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Api_Aveonline_Onboarding
 */
class LTMS_Api_Aveonline_Onboarding {

    // ── URLs ──────────────────────────────────────────────────────────────────

    const API_BASE = 'https://api.aveonline.co/api-onboarding/public/api/v1/external/onboarding';

    const EP_ACCEPT_TERMS      = '/acceptTerms';
    const EP_CREATE_LEAD       = '/createLead';
    const EP_COMPANY_STEP_ONE  = '/createCompanyStepOne';
    const EP_COMPANY_STEP_TWO  = '/createCompanyStepTwo';

    // Timeout en segundos para llamadas con base64 (documentos pesados)
    const TIMEOUT_DEFAULT = 30;
    const TIMEOUT_DOCS    = 60;

    // Option key donde se guarda el JWT de onboarding
    const OPTION_JWT = 'ltms_aveonline_onboarding_token';

    // ── Singleton ─────────────────────────────────────────────────────────────

    /** @var self|null */
    private static $instance = null;

    /** @var string */
    private $jwt = '';

    private function __construct() {
        $this->jwt = (string) get_option( self::OPTION_JWT, '' );
    }

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ── Paso 1: Aceptar Términos ──────────────────────────────────────────────

    /**
     * Registra la aceptación de T&C y crea el lead inicial en Aveonline.
     *
     * @param array $data {
     *   email?          string
     *   phone?          string|int
     *   name?           string
     *   phoneCode?      string  Ej: '+57'
     *   numberShipments? string Ej: '0-100'
     *   ecommerce?      string  Ej: 'WooCommerce'
     *   codeIso?        string  Ej: 'CO'
     * }
     * @return array { success: bool, code: int, data: mixed, message: string }
     */
    public function accept_terms( array $data ): array {
        if ( empty( $data['email'] ) && empty( $data['phone'] ) ) {
            return $this->error( 'Se requiere email o phone para aceptar términos.' );
        }

        // Siempre inyectamos la fuente de origen
        $data['ecommerce']     = $data['ecommerce']     ?? 'WooCommerce';
        $data['urlLeadSource'] = $data['urlLeadSource'] ?? get_site_url();
        $data['codeIso']       = $data['codeIso']       ?? 'CO';

        return $this->post( self::EP_ACCEPT_TERMS, $data );
    }

    // ── Paso 2: Crear Lead ────────────────────────────────────────────────────

    /**
     * Completa los datos del lead. Retorna el seed UUID del proceso.
     *
     * @param array $data {
     *   name*     string
     *   email*    string
     *   phone*    string|int
     *   password* string
     *   phoneCode? string
     *   numberShipments? string
     * }
     * @return array { success: bool, code: int, seed?: string, data: mixed }
     */
    public function create_lead( array $data ): array {
        $required = [ 'name', 'email', 'phone', 'password' ];
        foreach ( $required as $field ) {
            if ( empty( $data[ $field ] ) ) {
                return $this->error( "El campo '{$field}' es requerido en el Paso 2." );
            }
        }

        $data['ecommerce']     = $data['ecommerce']     ?? 'WooCommerce';
        $data['urlLeadSource'] = $data['urlLeadSource'] ?? get_site_url();
        $data['phoneCode']     = $data['phoneCode']     ?? '+57';
        $data['codeIso']       = $data['codeIso']       ?? 'CO';

        $result = $this->post( self::EP_CREATE_LEAD, $data );

        // Normalizar seed (viene en 201 y en 211)
        if ( $result['success'] || $result['code'] === 211 ) {
            $result['seed'] = $result['data']['seed'] ?? null;
            // 211 no es error — el proceso puede continuar
            if ( $result['code'] === 211 ) {
                $result['success'] = true;
                $result['message'] = 'Proceso de onboarding ya iniciado — usando seed existente.';
            }
        }

        return $result;
    }

    // ── Paso 3: Empresa — Identidad y Documentos ──────────────────────────────

    /**
     * Registra identidad y documentos. Incluye validación CIFIN.
     *
     * Para persona NATURAL (documentType != 3):
     *   seed*, documentType*, idDocument*, fullName*, lastname*,
     *   cedulaFront* { base64, name }, cedulaBack* { base64, name }
     *
     * Para persona JURÍDICA (documentType == 3):
     *   seed*, documentType*, idDocument*, businessName*,
     *   nombrelegal*, cedulalegal*,
     *   rut* { base64, name }, camara_comercio* { base64, name }
     *
     * @param array $data
     * @return array { success: bool, code: int, score?: int, data: mixed }
     */
    public function company_step_one( array $data ): array {
        if ( empty( $data['seed'] ) ) {
            return $this->error( 'Se requiere el seed del Paso 2.' );
        }
        if ( empty( $data['documentType'] ) || empty( $data['idDocument'] ) ) {
            return $this->error( 'documentType e idDocument son requeridos.' );
        }

        $is_juridica = (int) $data['documentType'] === 3;

        if ( $is_juridica ) {
            foreach ( [ 'businessName', 'nombrelegal', 'cedulalegal', 'rut', 'camara_comercio' ] as $f ) {
                if ( empty( $data[ $f ] ) ) {
                    return $this->error( "El campo '{$f}' es requerido para persona jurídica." );
                }
            }
        } else {
            foreach ( [ 'fullName', 'lastname', 'cedulaFront', 'cedulaBack' ] as $f ) {
                if ( empty( $data[ $f ] ) ) {
                    return $this->error( "El campo '{$f}' es requerido para persona natural." );
                }
            }
        }

        $result = $this->post( self::EP_COMPANY_STEP_ONE, $data, self::TIMEOUT_DOCS );

        if ( $result['success'] ) {
            $result['score'] = $result['data']['score'] ?? 0;
        }

        // 406 = CIFIN fallido → no continuar al Paso 4
        if ( $result['code'] === 406 ) {
            $result['success'] = false;
            $result['cifin_failed'] = true;
            $result['message'] = 'La validación CIFIN no fue exitosa. El proceso no puede continuar.';
        }

        return $result;
    }

    // ── Paso 4: Empresa — Datos Comerciales ───────────────────────────────────

    /**
     * Completa los datos comerciales y crea la empresa en Aveonline.
     * Retorna id_empresa_ave para asociar futuros envíos y pedidos.
     *
     * @param array $data {
     *   seed*      string UUID
     *   tradename* string
     *   address*   string
     *   city*      string Código postal. Ej: '110111'
     * }
     * @return array { success: bool, code: int, id_empresa_ave?: int, data: mixed }
     */
    public function company_step_two( array $data ): array {
        foreach ( [ 'seed', 'tradename', 'address', 'city' ] as $f ) {
            if ( empty( $data[ $f ] ) ) {
                return $this->error( "El campo '{$f}' es requerido en el Paso 4." );
            }
        }

        $result = $this->post( self::EP_COMPANY_STEP_TWO, $data );

        if ( $result['success'] ) {
            $result['id_empresa_ave'] = $result['data']['id_empresa_ave'] ?? null;
        }

        return $result;
    }

    // ── Flujo completo (helper para registro automático) ──────────────────────

    /**
     * Ejecuta los 4 pasos secuencialmente.
     * Útil para registro programático (ej: cuando un vendedor completa el form en LTMS).
     *
     * @param array $step1 Datos para acceptTerms
     * @param array $step2 Datos para createLead (sin email/phone, se toman de step1)
     * @param array $step3 Datos para companyStepOne (sin seed, se inyecta automáticamente)
     * @param array $step4 Datos para companyStepTwo (sin seed, se inyecta automáticamente)
     * @return array { success: bool, id_empresa_ave?: int, seed?: string, errors: array }
     */
    public function register_full(
        array $step1,
        array $step2,
        array $step3,
        array $step4
    ): array {
        $errors = [];

        // Paso 1
        $r1 = $this->accept_terms( $step1 );
        if ( ! $r1['success'] && $r1['code'] !== 211 && $r1['code'] !== 212 ) {
            return [ 'success' => false, 'step' => 1, 'errors' => [ $r1['message'] ] ];
        }

        // Paso 2 — heredar email/phone del step1
        $step2 = array_merge( [
            'email' => $step1['email'] ?? '',
            'phone' => $step1['phone'] ?? '',
        ], $step2 );

        $r2 = $this->create_lead( $step2 );
        if ( ! $r2['success'] ) {
            return [ 'success' => false, 'step' => 2, 'errors' => [ $r2['message'] ] ];
        }

        $seed = $r2['seed'] ?? null;
        if ( ! $seed ) {
            return [ 'success' => false, 'step' => 2, 'errors' => [ 'No se obtuvo el seed del Paso 2.' ] ];
        }

        // Paso 3
        $step3['seed'] = $seed;
        $r3 = $this->company_step_one( $step3 );
        if ( ! $r3['success'] ) {
            return [
                'success'      => false,
                'step'         => 3,
                'seed'         => $seed,
                'cifin_failed' => $r3['cifin_failed'] ?? false,
                'errors'       => [ $r3['message'] ],
            ];
        }

        // Paso 4
        $step4['seed'] = $seed;
        $r4 = $this->company_step_two( $step4 );
        if ( ! $r4['success'] ) {
            return [
                'success' => false,
                'step'    => 4,
                'seed'    => $seed,
                'errors'  => [ $r4['message'] ],
            ];
        }

        return [
            'success'       => true,
            'seed'          => $seed,
            'id_empresa_ave'=> $r4['id_empresa_ave'],
            'score_cifin'   => $r3['score'] ?? 0,
            'errors'        => [],
        ];
    }

    // ── HTTP ──────────────────────────────────────────────────────────────────

    /**
     * POST a la API de onboarding de Aveonline.
     *
     * @param string $endpoint
     * @param array  $body
     * @param int    $timeout
     * @return array { success: bool, code: int, data: mixed, message: string }
     */
    private function post( string $endpoint, array $body, int $timeout = self::TIMEOUT_DEFAULT ): array {
        if ( empty( $this->jwt ) ) {
            return $this->error(
                'Token JWT de onboarding no configurado. ' .
                'Ve a LTMS → Configuración → Aveonline y guarda el token de onboarding.'
            );
        }

        $url      = self::API_BASE . $endpoint;
        // INTEGRATIONS-AUDIT P1 FIX: deterministic Idempotency-Key based on
        // endpoint + body hash. Prevents duplicate leads / companies / CIFIN
        // checks on caller retry (especially important for company_step_two
        // which creates real AVE companies and company_step_one which triggers
        // paid CIFIN credit-bureau checks).
        $idem_key = 'ltms_ave_onb_' . substr( md5( $endpoint . wp_json_encode( $body ) ), 0, 32 );
        $response = wp_remote_post( $url, [
            'timeout'     => $timeout,
            'sslverify'   => ! ( defined( 'LTMS_DISABLE_SSL_VERIFY' ) && LTMS_DISABLE_SSL_VERIFY ),
            'headers'     => [
                'Content-Type'   => 'application/json',
                'Accept'         => 'application/json',
                'Authorization'  => 'Bearer ' . $this->jwt,
                'Idempotency-Key'=> $idem_key,
            ],
            'body'        => wp_json_encode( $body ),
            'data_format' => 'body',
        ] );

        if ( is_wp_error( $response ) ) {
            return $this->error( 'Error de conexión con Aveonline: ' . $response->get_error_message() );
        }

        $code    = (int) wp_remote_retrieve_response_code( $response );
        $raw     = wp_remote_retrieve_body( $response );
        $decoded = json_decode( $raw, true );

        // Códigos de éxito: 200, 201, 211 (proceso en curso — seed retornado)
        $success = in_array( $code, [ 200, 201, 211 ], true );

        if ( ! $success ) {
            $message = $this->extract_error_message( $decoded, $code );
            return [
                'success' => false,
                'code'    => $code,
                'data'    => $decoded,
                'message' => $message,
            ];
        }

        return [
            'success' => true,
            'code'    => $code,
            'data'    => $decoded['data'] ?? $decoded,
            'message' => $decoded['message'] ?? 'OK',
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function extract_error_message( ?array $decoded, int $code ): string {
        if ( isset( $decoded['errors'][0]['detail'] ) ) {
            return $decoded['errors'][0]['detail'];
        }
        if ( isset( $decoded['message'] ) ) {
            return $decoded['message'];
        }

        $codes = [
            401 => 'Token JWT inválido o expirado.',
            406 => 'Validación CIFIN fallida — el prospecto no califica.',
            409 => 'Error al guardar en base de datos de Aveonline.',
            412 => 'El cliente no ha aceptado los términos (ejecuta el Paso 1 primero).',
            422 => 'Error de validación en los campos enviados.',
            500 => 'Error interno del servidor de Aveonline.',
        ];

        return $codes[ $code ] ?? "Error desconocido (HTTP {$code}).";
    }

    private function error( string $message ): array {
        return [
            'success' => false,
            'code'    => 0,
            'data'    => null,
            'message' => $message,
        ];
    }

    // ── Utilidades públicas ───────────────────────────────────────────────────

    /**
     * Verifica si el JWT de onboarding está configurado.
     */
    public function has_token(): bool {
        return ! empty( $this->jwt );
    }

    /**
     * Convierte un archivo subido (path local) a base64 con prefijo MIME.
     * Soporta JPG, PNG, PDF.
     */
    public static function file_to_base64( string $file_path ): ?string {
        if ( ! file_exists( $file_path ) ) {
            return null;
        }

        // INTEGRATIONS-AUDIT P1 FIX: cap file size before reading — a 100 MB
        // upload would exhaust PHP memory and crash the onboarding mid-call.
        $max_bytes = 10 * 1024 * 1024; // 10 MB
        $size      = @filesize( $file_path );
        if ( false === $size || $size > $max_bytes ) {
            return null;
        }

        $mime_map = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'pdf'  => 'application/pdf',
        ];

        $ext  = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
        $mime = $mime_map[ $ext ] ?? null;

        if ( ! $mime ) {
            return null;
        }

        // INTEGRATIONS-AUDIT P1 FIX: validate actual MIME via finfo (extension
        // is trivially spoofable — a file named evil.pdf containing arbitrary
        // binary would pass the extension check).
        if ( function_exists( 'finfo_open' ) ) {
            $finfo = finfo_open( FILEINFO_MIME_TYPE );
            if ( $finfo ) {
                $real_mime = finfo_file( $finfo, $file_path );
                finfo_close( $finfo );
                // Allow aliases — finfo may report 'image/x-png' for legacy PNGs.
                $allowed_real = [ 'image/jpeg', 'image/png', 'image/x-png', 'application/pdf' ];
                if ( ! in_array( $real_mime, $allowed_real, true ) ) {
                    return null;
                }
            }
        }

        $content = file_get_contents( $file_path );
        if ( false === $content ) {
            return null;
        }

        return 'data:' . $mime . ';base64,' . base64_encode( $content );
    }

    /**
     * Convierte base64 de WordPress Media Library a formato Aveonline.
     * Útil cuando los documentos ya están en WP como attachments.
     *
     * @param int $attachment_id
     * @return array|null { base64: string, name: string }
     */
    public static function attachment_to_aveonline( int $attachment_id ): ?array {
        $file_path = get_attached_file( $attachment_id );
        if ( ! $file_path ) {
            return null;
        }

        $b64 = self::file_to_base64( $file_path );
        if ( ! $b64 ) {
            return null;
        }

        return [
            'base64' => $b64,
            'name'   => basename( $file_path ),
        ];
    }
}
