<?php
/**
 * LTMS API Client - Aveonline (Logística y Envíos)
 *
 * Integración con la API v2 de Aveonline:
 *   https://app.aveonline.co/api
 *
 * Flujo de autenticación:
 *   POST /comunes/v2.0/autenticarusuario.php  →  JWT (12 h)
 *   El token se almacena en transient y se renueva automáticamente.
 *
 * Endpoints utilizados:
 *   Cotización  POST /nal/v1.0/generarGuiaTransporteNacional.php  tipo=cotizar2
 *   Guía        POST /nal/v1.0/generarGuiaTransporteNacional.php  tipo=generarGuia2
 *   Estado      POST /nal/v1.0/guia.php                          tipo=obtenerEstadoAuth
 *   Recogida    POST /nal/v1.0/generarGuiaTransporteNacional.php  tipo=solicitarRecogida
 *
 * @package    LTMS
 * @subpackage LTMS/includes/api
 * @version    2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Api_Aveonline
 */
class LTMS_Api_Aveonline extends LTMS_Abstract_API_Client {

    // ── URLs base ─────────────────────────────────────────────────────────────

    const API_BASE_LIVE    = 'https://app.aveonline.co/api';
    const API_BASE_SANDBOX = 'https://sandbox.aveonline.co/api'; // fallback para entornos no-prod

    // Endpoints
    const ENDPOINT_AUTH     = '/comunes/v2.0/autenticarusuario.php';
    const ENDPOINT_GUIA     = '/nal/v1.0/generarGuiaTransporteNacional.php';
    const ENDPOINT_ESTADO   = '/nal/v1.0/guia.php';

    // Caché del token (segundos). La API otorga 12 h; renovamos a las 11 h.
    const TOKEN_TTL = 39600; // 11 horas

    /**
     * Usuario de la plataforma Aveonline.
     * @var string
     */
    private string $usuario;

    /**
     * Contraseña descifrada de la plataforma Aveonline.
     * @var string
     */
    private string $clave;

    /**
     * ID de empresa / usuario dentro de Aveonline (viene en la respuesta de auth).
     * @var int
     */
    private int $idempresa = 0;

    /**
     * Identificador del agente logístico asociado a la cuenta.
     * @var string
     */
    private string $idagente = '';

    /**
     * Código de transportadora por defecto.
     * @var string
     */
    private string $idtransportador = '';

    /**
     * Credenciales secundarias de guía (codigo / dsclavex) según doc de Aveonline.
     * @var string
     */
    private string $codigo_guia  = '';
    private string $clave_guia   = '';

    /**
     * Token JWT activo. Se obtiene mediante get_token().
     * @var string
     */
    private string $token = '';

    // ── Constructor ───────────────────────────────────────────────────────────

    public function __construct() {
        $this->api_url         = LTMS_ENVIRONMENT === 'production' ? self::API_BASE_LIVE : self::API_BASE_SANDBOX;
        $this->usuario         = LTMS_Core_Config::get( 'ltms_aveonline_usuario', '' );
        $this->clave           = LTMS_Core_Security::decrypt( LTMS_Core_Config::get( 'ltms_aveonline_clave', '' ) );
        $this->idempresa       = (int) LTMS_Core_Config::get( 'ltms_aveonline_idempresa', 0 );
        $this->idagente        = (string) LTMS_Core_Config::get( 'ltms_aveonline_idagente', '' );
        $this->idtransportador = (string) LTMS_Core_Config::get( 'ltms_aveonline_idtransportador', '' );
        $this->codigo_guia     = LTMS_Core_Config::get( 'ltms_aveonline_codigo', '' );
        $this->clave_guia      = LTMS_Core_Security::decrypt( LTMS_Core_Config::get( 'ltms_aveonline_clave_guia', '' ) );
        parent::__construct();
    }

    // ── Interfaz pública ──────────────────────────────────────────────────────

    /** {@inheritdoc} */
    public function get_provider_slug(): string {
        return 'aveonline';
    }

    /**
     * Crea una guía de envío.
     *
     * @param array $shipment_data {
     *   @type array  $origin           Datos del remitente (name, phone, email, address, city, state, zip_code, nit).
     *   @type array  $destination      Datos del destinatario (name, phone, email, address, city, state, zip_code, nit).
     *   @type array  $packages         Paquetes [ [weight_kg, length_cm, width_cm, height_cm, quantity, nombre, valor_declarado] ].
     *   @type string $reference        Referencia interna (opcional).
     *   @type string $description      Contenido del paquete.
     *   @type float  $declared_value   Valor declarado total (opcional, suma de productos si se omite).
     *   @type int    $valorrecaudo     Valor a recaudar (0 si no aplica).
     *   @type int    $contraentrega    1 si es contraentrega, 0 si no.
     *   @type int    $idasumecosto     1 si el cliente asume costo de recaudo, 0 si no.
     *   @type string $idtransportador  Código de transportadora (usa default si se omite).
     *   @type int    $idagente         Agente logístico (usa default si se omite).
     * }
     * @return array{success: bool, tracking_number: string, label_url: string, cost: float, shipment_id: string}
     * @throws \InvalidArgumentException Si faltan origin o destination.
     */
    public function create_shipment( array $shipment_data ): array {
        if ( ! isset( $shipment_data['origin'] ) ) {
            throw new \InvalidArgumentException( 'El campo origin es obligatorio.' );
        }
        if ( ! is_array( $shipment_data['origin'] ) ) {
            throw new \InvalidArgumentException( 'El campo origin debe ser un array.' );
        }
        if ( ! isset( $shipment_data['destination'] ) ) {
            throw new \InvalidArgumentException( 'El campo destination es obligatorio.' );
        }
        if ( ! is_array( $shipment_data['destination'] ) ) {
            throw new \InvalidArgumentException( 'El campo destination debe ser un array.' );
        }

        $origin      = $shipment_data['origin'];
        $destination = $shipment_data['destination'];
        $packages    = $this->format_packages( $shipment_data['packages'] ?? [] );
        $token       = $this->get_token();

        $payload = [
            'tipo'            => 'generarGuia2',
            'token'           => $token,
            'idempresa'       => $this->idempresa,
            'codigo'          => $this->codigo_guia,
            'dsclavex'        => $this->clave_guia,
            'plugin'          => 'apiave',
            // Origen
            'origen'          => $origin['city'] ?? '',
            'dsdirre'         => $origin['address'] ?? '',
            'dsbarrioo'       => $origin['barrio'] ?? '',
            'dsnitre'         => $origin['nit'] ?? $origin['documento'] ?? '',
            'dstelre'         => $origin['phone'] ?? '',
            'dscelularre'     => $origin['phone'] ?? '',
            'dscorreopre'     => $this->sanitize_email_field( $origin['email'] ?? '' ),
            'dsnombre'        => $origin['name'] ?? '',
            // Destino
            'destino'         => $destination['city'] ?? '',
            'IdTipoEntrega'   => '1',
            'dsdir'           => $destination['address'] ?? '',
            'dsbarrio'        => $destination['barrio'] ?? '',
            'dsnit'           => $destination['nit'] ?? $destination['documento'] ?? '00000',
            'dsnombrecompleto'=> $destination['name'] ?? '',
            'dscorreop'       => $this->sanitize_email_field( $destination['email'] ?? '' ),
            'dstel'           => $destination['phone'] ?? '',
            'dscelular'       => $destination['phone'] ?? '',
            // Paquetes y contenido
            'idtransportador' => $shipment_data['idtransportador'] ?? $this->idtransportador,
            'unidades'        => count( $packages ),
            'productos'       => $packages,
            'dscontenido'     => $shipment_data['description'] ?? $shipment_data['dscontenido'] ?? 'Mercancía general',
            'dscom'           => $shipment_data['dscom'] ?? '',
            // Recaudo / pago
            'valorrecaudo'    => (int) ( $shipment_data['valorrecaudo'] ?? 0 ),
            'contraentrega'   => (int) ( $shipment_data['contraentrega'] ?? 0 ),
            'idasumecosto'    => (int) ( $shipment_data['idasumecosto'] ?? 0 ),
            'valorMinimo'     => (int) ( $shipment_data['valorMinimo'] ?? 0 ),
            // Agente y opciones
            'idagente'        => $shipment_data['idagente'] ?? $this->idagente,
            'dsreferencia'    => $shipment_data['reference'] ?? '',
            'dsordendecompra' => $shipment_data['orden_compra'] ?? '',
            'bloquegenerarguia'=> '1',
            'relacion_envios' => '1',
            'enviarcorreos'   => '1',
            'cartaporte'      => '',
            'numeroFactura'   => $shipment_data['numero_factura'] ?? '',
        ];

        $response = $this->aveonline_request( self::ENDPOINT_GUIA, $payload );

        // La guía llega en resultado.guia
        $guia = $response['resultado']['guia'] ?? [];

        // Código distinto de '0' o negativo indica error de transportadora
        $codigo_guia = $guia['codigo'] ?? '-1';
        $success     = isset( $guia['numguia'] ) && (string) $codigo_guia === '0';

        return [
            'success'         => $success,
            'tracking_number' => $success ? (string) $guia['numguia'] : '',
            'label_url'       => $guia['rutaguia'] ?? $guia['rotulo'] ?? '',
            'label_url_sticker' => $guia['rutasticker'] ?? '',
            'label_base64'    => $guia['archivorotulo'] ?? '',
            'cost'            => 0.0, // Aveonline no devuelve costo en generarGuia2; usar get_rates()
            'shipment_id'     => $success ? (string) $guia['numguia'] : '',
            'transportadora'  => $guia['transportadora'] ?? '',
            'mensaje'         => $guia['mensaje'] ?? ( $response['message'] ?? '' ),
        ];
    }

    /**
     * Consulta el estado de un envío por número de guía.
     *
     * @param string $tracking_number Número de guía.
     * @return array{status: string, events: array, estimated_delivery: string, current_location: string}
     */
    public function track_shipment( string $tracking_number ): array {
        $token = $this->get_token();

        $payload = [
            'tipo'       => 'obtenerEstadoAuth',
            'token'      => $token,
            'id'         => $this->idempresa,
            'guia'       => $tracking_number,
            'ordencompra'=> '',
            'referencia' => '',
        ];

        $response = $this->aveonline_request( self::ENDPOINT_ESTADO, $payload );

        $guias = $response['guias'] ?? [];
        $guia  = $guias[0] ?? [];

        // Construir array de eventos desde historicos
        $events = [];
        foreach ( $guia['historicos'] ?? [] as $h ) {
            $events[] = [
                'date'        => $h['fechamostrar'] ?? '',
                'description' => $h['descripcion'] ?? '',
                'status'      => $h['estado'] ?? '',
                'novedad'     => (bool) ( $h['novedad'] ?? false ),
            ];
        }

        return [
            'status'             => $guia['estado'] ?? 'unknown',
            'events'             => $events,
            'estimated_delivery' => $guia['dsfechaentrega'] ?? '',
            'current_location'   => $guia['destino'] ?? '',
            'destinatario'       => $guia['destinatario'] ?? '',
            'transportadora'     => $guia['transportadora'] ?? '',
            'label_url'          => $guia['ruta_rotulo'] ?? $guia['rotulo'] ?? '',
            'sticker_url'        => $guia['ruta_sticker'] ?? '',
        ];
    }

    /**
     * Calcula tarifas de envío (cotización).
     *
     * Acepta weight_kg (canónico) o weight (alias). Si ninguno está presente usa 1.0.
     *
     * @param array $rate_query {
     *   @type string $origin_city       Ciudad de origen (nombre o código DANE).
     *   @type string $destination_city  Ciudad de destino.
     *   @type float  $weight_kg         Peso en kg (alias: weight).
     *   @type float  $length_cm         Largo en cm (default 30).
     *   @type float  $width_cm          Ancho en cm (default 20).
     *   @type float  $height_cm         Alto en cm (default 15).
     *   @type float  $declared_value    Valor declarado.
     *   @type int    $valorrecaudo      Valor a recaudar.
     *   @type int    $contraentrega     1/0.
     *   @type int    $idasumecosto      1/0.
     *   @type string $idtransportador   Código transportadora (vacío = todas).
     * }
     * @return array Lista de cotizaciones con codTransportadora, nombreTransportadora, total, diasentrega, etc.
     */
    public function get_rates( array $rate_query ): array {
        // weight_kg toma precedencia sobre el alias weight
        if ( isset( $rate_query['weight_kg'] ) ) {
            $weight = (float) $rate_query['weight_kg'];
        } elseif ( isset( $rate_query['weight'] ) ) {
            $weight = (float) $rate_query['weight'];
        } else {
            $weight = 1.0;
        }

        $token = $this->get_token();

        // Construir producto único representativo
        $producto = [
            'alto'          => (string) ( $rate_query['height_cm'] ?? 15 ),
            'largo'         => (string) ( $rate_query['length_cm'] ?? 30 ),
            'ancho'         => (string) ( $rate_query['width_cm'] ?? 20 ),
            'peso'          => (string) $weight,
            'unidades'      => 1,
            'nombre'        => 'Producto',
            'valorDeclarado'=> (string) ( $rate_query['declared_value'] ?? 10000 ),
        ];

        $payload = [
            'tipo'           => 'cotizar2',
            'token'          => $token,
            'idempresa'      => $this->idempresa,
            'origen'         => $rate_query['origin_city'] ?? '',
            'destino'        => $rate_query['destination_city'] ?? '',
            'valorrecaudo'   => (int) ( $rate_query['valorrecaudo'] ?? 0 ),
            'unidades'       => 1,
            'productos'      => [ $producto ],
            'valorMinimo'    => 0,
            'idasumecosto'   => (int) ( $rate_query['idasumecosto'] ?? 0 ),
            'contraentrega'  => (int) ( $rate_query['contraentrega'] ?? 0 ),
            'idtransportador'=> $rate_query['idtransportador'] ?? '',
            'plugin'         => 'apiave',
            'idagente'       => $this->idagente,
        ];

        $response = $this->aveonline_request( self::ENDPOINT_GUIA, $payload );

        // Filtrar solo cotizaciones sin error (-0- = sin error)
        $cotizaciones = $response['cotizaciones'] ?? [];
        return array_values( array_filter( $cotizaciones, static fn( $c ) => ( $c['numbererror'] ?? '-0-' ) === '-0-' ) );
    }

    /**
     * Descarga la etiqueta de una guía en base64.
     *
     * Aveonline devuelve el base64 en archivorotulo al generar la guía.
     * Este método lo obtiene consultando el estado y extrayendo ruta_rotulo
     * (URL directa, no base64 nativo; para base64 usar create_shipment).
     *
     * @param string $shipment_id Número de guía.
     * @return string URL del rótulo o string vacío.
     */
    public function get_label( string $shipment_id ): string {
        $tracking = $this->track_shipment( $shipment_id );
        return $tracking['label_url'] ?? '';
    }

    /**
     * Cancela / anula una guía.
     *
     * Aveonline no expone un endpoint REST de cancelación en la API pública v2.
     * Este método lanza una excepción indicativa; la cancelación debe hacerse
     * desde el panel de Aveonline o contactando al asesor logístico.
     *
     * @param string $shipment_id Número de guía.
     * @return bool
     * @throws \InvalidArgumentException Si shipment_id está vacío.
     * @throws \RuntimeException         Siempre — endpoint no disponible en la API pública.
     */
    public function cancel_shipment( string $shipment_id ): bool {
        if ( trim( $shipment_id ) === '' ) {
            throw new \InvalidArgumentException( 'El shipment_id no puede estar vacío.' );
        }
        throw new \RuntimeException( 'Aveonline no expone endpoint de cancelación en la API pública v2. Gestiona la anulación desde el panel o contacta al asesor.' );
    }

    /**
     * Verifica conectividad con la API de Aveonline intentando autenticar.
     *
     * @return array{status: string, message: string}
     */
    public function health_check(): array {
        try {
            $token = $this->refresh_token(); // fuerza renovación
            return [
                'status'  => $token ? 'ok' : 'error',
                'message' => $token ? 'Aveonline API conectado' : 'Token vacío tras autenticación',
            ];
        } catch ( \Throwable $e ) {
            return [ 'status' => 'error', 'message' => $e->getMessage() ];
        }
    }

    /**
     * Crea una guía de devolución para un envío existente.
     *
     * Aveonline gestiona las devoluciones (cartaporte/boomerang) usando
     * el parámetro cartaporte=1 en generarGuia2 con los datos invertidos.
     *
     * @param string $shipment_id     ID del envío original (número de guía).
     * @param string $reason          Motivo de la devolución. Default: 'customer_request'.
     * @param array  $additional_data Datos adicionales (deben incluir origin/destination para el retorno).
     * @return array{success: bool, tracking_number: string, label_url: string, return_id: string}
     * @throws \InvalidArgumentException Si shipment_id está vacío.
     */
    public function create_return( string $shipment_id, string $reason = 'customer_request', array $additional_data = [] ): array {
        if ( trim( $shipment_id ) === '' ) {
            throw new \InvalidArgumentException( 'El shipment_id no puede estar vacío.' );
        }

        // Para devoluciones, el origen y destino se invierten y se activa cartaporte
        $data = array_merge( $additional_data, [
            'original_shipment_id' => $shipment_id,
            'reason'               => $reason,
            'cartaporte'           => '1',
            'dscom'                => 'Devolución: ' . $reason,
        ]);

        try {
            $result = $this->create_shipment( $data );
        } catch ( \Throwable $e ) {
            return [
                'success'         => false,
                'tracking_number' => '',
                'label_url'       => '',
                'return_id'       => '',
            ];
        }

        return [
            'success'         => $result['success'] ?? false,
            'tracking_number' => $result['tracking_number'] ?? '',
            'label_url'       => $result['label_url'] ?? '',
            'return_id'       => $result['shipment_id'] ?? '',
        ];
    }

    // ── Autenticación ─────────────────────────────────────────────────────────

    /**
     * Devuelve el token JWT vigente. Lo renueva si expiró o no existe.
     *
     * @return string Token JWT.
     * @throws \RuntimeException Si la autenticación falla.
     */
    private function get_token(): string {
        if ( $this->token ) {
            return $this->token;
        }

        $cached = get_transient( 'ltms_aveonline_jwt' );
        if ( $cached ) {
            $this->token = $cached;
            return $this->token;
        }

        return $this->refresh_token();
    }

    /**
     * Fuerza renovación del token JWT y lo almacena en transient.
     *
     * @return string Nuevo token.
     * @throws \RuntimeException Si las credenciales son inválidas o la API falla.
     */
    private function refresh_token(): string {
        $auth_url = $this->api_url . self::ENDPOINT_AUTH;
        $payload  = [
            'tipo'    => 'authV2',
            'usuario' => $this->usuario,
            'clave'   => $this->clave,
        ];

        $raw_response = wp_remote_post( $auth_url, [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $payload ),
            'timeout' => 15,
        ]);

        if ( is_wp_error( $raw_response ) ) {
            throw new \RuntimeException( 'Aveonline auth error: ' . $raw_response->get_error_message() );
        }

        $body = json_decode( wp_remote_retrieve_body( $raw_response ), true );

        if ( ( $body['status'] ?? '' ) !== 'ok' || empty( $body['token'] ) ) {
            throw new \RuntimeException( 'Aveonline auth failed: ' . ( $body['message'] ?? 'respuesta inesperada' ) );
        }

        $this->token = $body['token'];
        set_transient( 'ltms_aveonline_jwt', $this->token, self::TOKEN_TTL );

        return $this->token;
    }

    // ── HTTP helper ───────────────────────────────────────────────────────────

    /**
     * Realiza una petición POST a la API de Aveonline y decodifica la respuesta.
     *
     * @param string $endpoint Ruta relativa (ej. self::ENDPOINT_GUIA).
     * @param array  $payload  Cuerpo de la petición.
     * @return array Respuesta decodificada.
     * @throws \RuntimeException En error de red o status=error de la API.
     */
    private function aveonline_request( string $endpoint, array $payload ): array {
        $url = $this->api_url . $endpoint;

        $raw = wp_remote_post( $url, [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $payload ),
            'timeout' => 30,
        ]);

        if ( is_wp_error( $raw ) ) {
            throw new \RuntimeException( 'Aveonline HTTP error: ' . $raw->get_error_message() );
        }

        $body = json_decode( wp_remote_retrieve_body( $raw ), true );

        if ( ! is_array( $body ) ) {
            throw new \RuntimeException( 'Aveonline: respuesta no es JSON válido.' );
        }

        // Si el token expiró, intentar renovar y reintentar una sola vez
        if ( ( $body['status'] ?? '' ) === 'error' && str_contains( $body['message'] ?? '', 'credenciales' ) ) {
            delete_transient( 'ltms_aveonline_jwt' );
            $this->token = '';
            $payload['token'] = $this->get_token();

            $raw  = wp_remote_post( $url, [
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( $payload ),
                'timeout' => 30,
            ]);
            $body = is_wp_error( $raw ) ? [] : json_decode( wp_remote_retrieve_body( $raw ), true );
        }

        return is_array( $body ) ? $body : [];
    }

    // ── Helpers privados ──────────────────────────────────────────────────────

    /**
     * {@inheritdoc}
     * Headers por defecto — la autenticación de Aveonline v2 va en el body (token JWT),
     * no en headers. Solo enviamos Content-Type.
     */
    protected function get_default_headers(): array {
        return [ 'Content-Type' => 'application/json' ];
    }

    /**
     * Formatea los paquetes al formato de productos de Aveonline.
     *
     * @param array $packages Lista de paquetes LTMS.
     * @return array Productos para el payload de Aveonline.
     */
    private function format_packages( array $packages ): array {
        if ( empty( $packages ) ) {
            return [[
                'alto'           => '15',
                'largo'          => '30',
                'ancho'          => '20',
                'peso'           => '1',
                'unidades'       => 1,
                'nombre'         => 'Paquete',
                'valorDeclarado' => '10000',
            ]];
        }

        return array_map( static fn( $p ) => [
            'alto'           => (string) ( $p['height_cm'] ?? 15 ),
            'largo'          => (string) ( $p['length_cm'] ?? 30 ),
            'ancho'          => (string) ( $p['width_cm'] ?? 20 ),
            'peso'           => (string) ( $p['weight_kg'] ?? 1 ),
            'unidades'       => (int) ( $p['quantity'] ?? 1 ),
            'nombre'         => $p['nombre'] ?? $p['name'] ?? 'Producto',
            'valorDeclarado' => (string) ( $p['valor_declarado'] ?? $p['declared_value'] ?? 10000 ),
        ], $packages );
    }

    /**
     * Valida y retorna un email, o string vacío si es inválido.
     *
     * @param string $email Email a validar.
     * @return string
     */
    private function sanitize_email_field( string $email ): string {
        return is_email( $email ) ? $email : '';
    }
}
