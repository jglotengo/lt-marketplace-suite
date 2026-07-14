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
    const ENDPOINT_AUTH          = '/comunes/v2.0/autenticarusuario.php';
    const ENDPOINT_GUIA          = '/nal/v1.0/generarGuiaTransporteNacional.php';
    const ENDPOINT_ESTADO        = '/nal/v1.0/guia.php';
    const ENDPOINT_AGENTES       = '/comunes/v1.0/agentes.php';
    const ENDPOINT_CIUDADES      = '/box/v1.0/ciudad.php';
    const ENDPOINT_TRANSPORTADORAS = '/box/v1.0/transportadora.php';
    const ENDPOINT_DESTINATARIOS = '/comunes/v1.0/destinatarios.php';

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
            'cartaporte'      => $shipment_data['cartaporte'] ?? '',
            'numeroFactura'   => $shipment_data['numero_factura'] ?? '',
        ];

        $response = $this->aveonline_request( self::ENDPOINT_GUIA, $payload, [
            // AO-BUG-10 FIX: Idempotency-Key determinista por orden_compra (o hash
            // del payload) para que un doble-clic en "Generar guía" no cree dos
            // guías en Aveonline. La API v2 ignora el header si no lo soporta.
            // INTEGRATIONS-AUDIT P0 FIX: hash the orden_compra to prevent
            // header-injection via CRLF in user-supplied order references.
            'Idempotency-Key' => 'ltms_ave_generar_guia_' . md5( (string) ( $shipment_data['orden_compra'] ?? '' ) . wp_json_encode( $shipment_data ) ),
        ] );

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
            'idagente'       => $rate_query['idagente'] ?? $this->idagente,
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
     * Crea un agente en Aveonline asociado al idempresa configurado.
     *
     * Documentación: POST https://app.aveonline.co/api/comunes/v1.0/agentes.php
     * tipo: "crearAgente"
     *
     * Si el agente ya existe ("El registro ya existe con el cliente asociado"),
     * intenta recuperar su ID mediante find_agent_id_by_email().
     *
     * @param array $agent_data {
     *   @type string $nombre          Nombre del agente. Requerido.
     *   @type string $idnit           NIT o cédula del agente. Requerido.
     *   @type string $telefono        Teléfono (solo dígitos). Requerido.
     *   @type string $direccion       Dirección. Requerido.
     *   @type string $correo          Email del agente. Requerido.
     *   @type string $ciudad          Nombre o código DANE de la ciudad. Requerido.
     *   @type string $email1          Email para novedades. Requerido.
     *   @type string $email2          Email comercial. Requerido.
     *   @type int    $idvalorminimo   1=con mínimos, 2=sin mínimos. Requerido.
     *   @type int    $verRecaudos     0=sí puede ver, 1=no puede ver. Requerido.
     *   @type int    $agentePrincipal 1=sí, 2=no. Requerido.
     *   @type string $nombreContacto  Nombre de contacto. Opcional.
     * }
     * @return string|null ID del agente creado o recuperado. Null si no se puede obtener.
     * @throws \RuntimeException Si la API retorna error definitivo.
     */
    public function create_agent( array $agent_data ): ?string {
        $token = $this->get_token();

        $payload = array_merge(
            [
                'tipo'           => 'crearAgente',
                'token'          => $token,
                'identificacion' => $this->idempresa,
            ],
            $agent_data
        );

        $response = wp_remote_post(
            $this->api_url . self::ENDPOINT_AGENTES,
            [
                'headers' => [
                    'Content-Type'    => 'application/json',
                    // AO-BUG-10 FIX: Idempotency-Key por NIT/correo del agente —
                    // un retry no debe crear dos agentes idénticos en Aveonline.
                    'Idempotency-Key' => 'ltms_ave_crear_agente_' . md5( (string) ( $agent_data['idnit'] ?? '' ) . (string) ( $agent_data['correo'] ?? '' ) ),
                ],
                'body'    => wp_json_encode( $payload ),
                'timeout' => 30,
                // INTEGRATIONS-AUDIT P1 FIX: sslverify explicit (was missing).
                'sslverify' => ! ( defined( 'LTMS_DISABLE_SSL_VERIFY' ) && LTMS_DISABLE_SSL_VERIFY ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( 'Error de red al crear agente: ' . $response->get_error_message() );
        }

        $body   = json_decode( wp_remote_retrieve_body( $response ), true );
        $status = $body['status'] ?? 'error';

        // Agente creado exitosamente
        if ( $status === 'ok' && ! empty( $body['id'] ) ) {
            return (string) $body['id'];
        }

        // Agente ya existe — recuperar ID por email
        $message = $body['message'] ?? '';
        if ( $status === 'ok' && strpos( $message, 'ya existe' ) !== false ) {
            $email = $agent_data['correo'] ?? '';
            if ( $email ) {
                return $this->find_agent_id_by_email( $email );
            }
            return null;
        }

        throw new \RuntimeException(
            sprintf( 'Aveonline crearAgente error: %s', $message ?: wp_json_encode( $body ) )
        );
    }

    /**
     * Busca un agente por email en el listado de agentes de la empresa.
     *
     * Documentación: POST /agentes.php tipo:"listarAgentesPorEmpresaAuth"
     * La respuesta incluye: id, nombre, email, direccion, telefono, idciudad, principal.
     *
     * @param string $email Email a buscar.
     * @return string|null ID del agente o null si no se encuentra.
     */
    public function find_agent_id_by_email( string $email ): ?string {
        if ( ! is_email( $email ) ) {
            return null;
        }

        $token = $this->get_token();

        $response = wp_remote_post(
            $this->api_url . self::ENDPOINT_AGENTES,
            [
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( [
                    'tipo'      => 'listarAgentesPorEmpresaAuth',
                    'token'     => $token,
                    'idempresa' => $this->idempresa,
                ] ),
                'timeout' => 30,
                // INTEGRATIONS-AUDIT P1 FIX: sslverify explicit (was missing).
                'sslverify' => ! ( defined( 'LTMS_DISABLE_SSL_VERIFY' ) && LTMS_DISABLE_SSL_VERIFY ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            return null;
        }

        $body   = json_decode( wp_remote_retrieve_body( $response ), true );
        $agents = $body['agentes'] ?? [];

        foreach ( $agents as $agent ) {
            if ( isset( $agent['email'] ) && strtolower( $agent['email'] ) === strtolower( $email ) ) {
                return (string) $agent['id'];
            }
        }

        return null;
    }

    /**
     * Actualiza los datos de un agente existente en Aveonline.
     *
     * Documentación: POST /agentes.php tipo:"ActualizarAgente"
     *
     * @param int   $agent_id    ID numérico del agente en Aveonline.
     * @param array $agent_data  Campos a actualizar (mismos que crearAgente, más 'id').
     * @return array{status: string, message: string}
     * @throws \RuntimeException Si hay error de red o la API retorna error.
     */
    public function update_agent( int $agent_id, array $agent_data ): array {
        $token = $this->get_token();

        $payload = array_merge(
            [
                'tipo'           => 'ActualizarAgente',
                'token'          => $token,
                'identificacion' => $this->idempresa,
                'id'             => $agent_id,
            ],
            $agent_data
        );

        $response = wp_remote_post(
            $this->api_url . self::ENDPOINT_AGENTES,
            [
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( $payload ),
                'timeout' => 30,
                // INTEGRATIONS-AUDIT P1 FIX: sslverify explicit (was missing).
                'sslverify' => ! ( defined( 'LTMS_DISABLE_SSL_VERIFY' ) && LTMS_DISABLE_SSL_VERIFY ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( 'Error de red al actualizar agente: ' . $response->get_error_message() );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ( $body['status'] ?? '' ) !== 'ok' ) {
            throw new \RuntimeException(
                sprintf( 'Aveonline ActualizarAgente error: %s', $body['message'] ?? wp_json_encode( $body ) )
            );
        }

        return $body;
    }

    /**
     * Actualiza el estado activo/inactivo de un agente en Aveonline.
     *
     * Documentación: POST /agentes.php tipo:"actualizarEstadoAgente"
     *
     * @param int $agent_nit  NIT o identificación del agente (campo 'id' en la API).
     * @param int $estado     0 = inactivo, 1 = activo (o según los valores de Aveonline).
     * @return array{status: string, message: string}
     * @throws \RuntimeException Si hay error de red o la API retorna error.
     */
    public function update_agent_status( int $agent_nit, int $estado ): array {
        $token = $this->get_token();

        $response = wp_remote_post(
            $this->api_url . self::ENDPOINT_AGENTES,
            [
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( [
                    'tipo'           => 'actualizarEstadoAgente',
                    'token'          => $token,
                    'identificacion' => $this->idempresa,
                    'estado'         => $estado,
                    'id'             => $agent_nit,
                ] ),
                'timeout' => 30,
                // INTEGRATIONS-AUDIT P1 FIX: sslverify explicit (was missing).
                'sslverify' => ! ( defined( 'LTMS_DISABLE_SSL_VERIFY' ) && LTMS_DISABLE_SSL_VERIFY ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( 'Error de red al actualizar estado agente: ' . $response->get_error_message() );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ( $body['status'] ?? '' ) !== 'ok' ) {
            throw new \RuntimeException(
                sprintf( 'Aveonline actualizarEstadoAgente error: %s', $body['message'] ?? wp_json_encode( $body ) )
            );
        }

        return $body;
    }

    /**
     * Crea un usuario de acceso asociado a un agente Aveonline.
     *
     * Documentación: POST /agentes.php tipo:"crearUsuarioAgente"
     *
     * @param array $user_data {
     *   @type string $idAgente      ID del agente. Requerido.
     *   @type string $idCiudad      ID de ciudad del agente. Requerido.
     *   @type string $idActivo      ID activo (estado). Requerido.
     *   @type string $cod           Código del agente. Requerido.
     *   @type string $login         Login del nuevo usuario. Requerido.
     *   @type string $password      Contraseña. Requerido.
     *   @type string $nombre        Nombre del usuario. Requerido.
     *   @type string $correo        Email. Requerido.
     *   @type string $telefono      Teléfono. Requerido.
     * }
     * @return array{status: string, message: string}
     * @throws \RuntimeException Si hay error de red o la API retorna error.
     */
    public function create_agent_user( array $user_data ): array {
        $token = $this->get_token();

        $payload = array_merge(
            [
                'tipo'           => 'crearUsuarioAgente',
                'token'          => $token,
                'identificacion' => (string) $this->idempresa,
            ],
            $user_data
        );

        $response = wp_remote_post(
            $this->api_url . self::ENDPOINT_AGENTES,
            [
                'headers' => [
                    'Content-Type'    => 'application/json',
                    // AO-BUG-10 FIX: Idempotency-Key por login del usuario agente.
                    'Idempotency-Key' => 'ltms_ave_crear_usuario_agente_' . md5( (string) ( $user_data['login'] ?? '' ) . (string) ( $user_data['correo'] ?? '' ) ),
                ],
                'body'    => wp_json_encode( $payload ),
                'timeout' => 30,
                // INTEGRATIONS-AUDIT P1 FIX: sslverify explicit (was missing).
                'sslverify' => ! ( defined( 'LTMS_DISABLE_SSL_VERIFY' ) && LTMS_DISABLE_SSL_VERIFY ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( 'Error de red al crear usuario agente: ' . $response->get_error_message() );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ( $body['status'] ?? '' ) !== 'ok' ) {
            $msg = $body['message'] ?? $body['Campos Requeridos'] ?? wp_json_encode( $body );
            throw new \RuntimeException( sprintf( 'Aveonline crearUsuarioAgente error: %s', $msg ) );
        }

        return $body;
    }

    /**
     * Lista todos los agentes asociados a la empresa en Aveonline.
     *
     * Documentación: POST /agentes.php tipo:"listarAgentesPorEmpresaAuth"
     *
     * @return array  Array de agentes con id, nombre, email, direccion, telefono, idciudad, principal.
     * @throws \RuntimeException Si hay error de red.
     */
    public function list_agents(): array {
        $token = $this->get_token();

        $response = wp_remote_post(
            $this->api_url . self::ENDPOINT_AGENTES,
            [
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( [
                    'tipo'      => 'listarAgentesPorEmpresaAuth',
                    'token'     => $token,
                    'idempresa' => $this->idempresa,
                ] ),
                'timeout' => 30,
                // INTEGRATIONS-AUDIT P1 FIX: sslverify explicit (was missing).
                'sslverify' => ! ( defined( 'LTMS_DISABLE_SSL_VERIFY' ) && LTMS_DISABLE_SSL_VERIFY ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( 'Error de red al listar agentes: ' . $response->get_error_message() );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        return $body['agentes'] ?? [];
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
            // INTEGRATIONS-AUDIT P1 FIX: sslverify explicit (was missing).
            'sslverify' => ! ( defined( 'LTMS_DISABLE_SSL_VERIFY' ) && LTMS_DISABLE_SSL_VERIFY ),
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
     * @param string $endpoint      Ruta relativa (ej. self::ENDPOINT_GUIA).
     * @param array  $payload       Cuerpo de la petición.
     * @param array  $extra_headers Headers adicionales (ej. Idempotency-Key).
     * @return array Respuesta decodificada.
     * @throws \RuntimeException En error de red o status=error de la API.
     */
    private function aveonline_request( string $endpoint, array $payload, array $extra_headers = [] ): array {
        $url     = $this->api_url . $endpoint;
        $headers = array_merge( [ 'Content-Type' => 'application/json' ], $extra_headers );

        $raw = wp_remote_post( $url, [
            'headers' => $headers,
            'body'    => wp_json_encode( $payload ),
            'timeout' => 30,
            // INTEGRATIONS-AUDIT P1 FIX: sslverify explicit (was missing).
            'sslverify' => ! ( defined( 'LTMS_DISABLE_SSL_VERIFY' ) && LTMS_DISABLE_SSL_VERIFY ),
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
                'headers' => $headers,
                'body'    => wp_json_encode( $payload ),
                'timeout' => 30,
                // INTEGRATIONS-AUDIT P1 FIX: sslverify explicit (was missing).
                'sslverify' => ! ( defined( 'LTMS_DISABLE_SSL_VERIFY' ) && LTMS_DISABLE_SSL_VERIFY ),
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
     * Consulta las oficinas y puntos de atención de una transportadora.
     *
     * Endpoint: GET https://api.aveonline.co/api-oficinas/public/api/v1/offices/all
     * Requiere JWT en cabecera Authorization: Bearer <token>.
     *
     * @param int|string  $carrier Código o slug de la transportadora
     *                             (1016|'inter', 1010|'tcc', 1009|'coordinadora', 33|'servientrega').
     * @param string|null $city_id Código DANE de 8 dígitos (ej: 11001000 = Bogotá). Opcional.
     * @param string|null $nombre  Filtro parcial por nombre del punto de venta. Opcional.
     * @param string|null $direccion Filtro parcial por dirección física. Opcional.
     * @return array {
     *     @type string $status     'success' o 'error'.
     *     @type array  $operadores Lista de operadores; cada uno con 'nombre' y 'oficinas'
     *                              (array de ['nombre','direccion','ciudad']).
     *     @type string $message    Vacío en éxito, mensaje de error en fallo.
     * }
     */

    /**
     * Busca ciudades disponibles en Aveonline por nombre (búsqueda dinámica).
     *
     * Complementa el JSON estático de LTMS_Business_Aveonline_Cities.
     * Útil para autocompletar en tiempo real o verificar id/codigoDANE oficial.
     *
     * Endpoint: POST https://app.aveonline.co/api/box/v1.0/ciudad.php
     *
     * @param  string $query     Nombre parcial o completo de la ciudad.
     * @param  int    $registros Máximo de resultados (0 = sin límite, default 10).
     * @return array  Array de ciudades: [ ['nombre'=>..., 'id'=>..., 'codigoDANE'=>...], ... ]
     * @throws \RuntimeException Si hay error de red.
     */
    public function search_cities( string $query, int $registros = 10 ): array {
        $response = wp_remote_post(
            $this->api_url . self::ENDPOINT_CIUDADES,
            [
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( [
                    'tipo'      => 'listar',
                    'data'      => $query,
                    'registros' => $registros ?: '',
                ] ),
                'timeout' => 15,
                // INTEGRATIONS-AUDIT P1 FIX: sslverify explicit (was missing).
                'sslverify' => ! ( defined( 'LTMS_DISABLE_SSL_VERIFY' ) && LTMS_DISABLE_SSL_VERIFY ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( 'Error de red al buscar ciudades: ' . $response->get_error_message() );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        // 'registros no encontrados' es un resultado vacío válido, no un error fatal
        if ( ( $body['status'] ?? '' ) === 'error' && ( $body['message'] ?? '' ) !== 'registros no encontrados' ) {
            throw new \RuntimeException( 'Aveonline ciudad error: ' . ( $body['message'] ?? wp_json_encode( $body ) ) );
        }

        return $body['ciudades'] ?? [];
    }

    public function get_carrier_offices( $carrier, ?string $city_id = null, ?string $nombre = null, ?string $direccion = null ): array {
        $base_url = 'https://api.aveonline.co/api-oficinas/public/api/v1/offices/all';

        $params = [ 'operador' => (string) $carrier ];
        if ( ! empty( $city_id ) ) {
            $params['ciudad'] = $city_id;
        }
        if ( ! empty( $nombre ) ) {
            $params['nombre'] = $nombre;
        }
        if ( ! empty( $direccion ) ) {
            $params['direccion'] = $direccion;
        }

        $url   = $base_url . '?' . http_build_query( $params );
        $token = $this->get_token();

        $response = wp_remote_get( $url, [
            'timeout' => 15,
            // INTEGRATIONS-AUDIT P1 FIX: sslverify explicit (was missing).
            'sslverify' => ! ( defined( 'LTMS_DISABLE_SSL_VERIFY' ) && LTMS_DISABLE_SSL_VERIFY ),
            'headers' => [
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return [
                'status'     => 'error',
                'operadores' => [],
                'message'    => $response->get_error_message(),
            ];
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body      = wp_remote_retrieve_body( $response );
        $decoded   = json_decode( $body, true );

        if ( ! is_array( $decoded ) ) {
            return [
                'status'     => 'error',
                'operadores' => [],
                'message'    => "HTTP {$http_code}: respuesta no válida",
            ];
        }

        // Normalizar al formato esperado si la respuesta no trae 'operadores'
        if ( ! isset( $decoded['operadores'] ) ) {
            $decoded['operadores'] = [];
        }

        return $decoded;
    }


    /**
     * Obtiene el listado de transportadoras disponibles para la empresa.
     *
     * Endpoint: POST https://app.aveonline.co/api/box/v1.0/transportadora.php
     * tipo: "listarTransportadorasPorEmpresa"
     *
     * @return array Lista de transportadoras. Cada elemento:
     *               [ 'id' => int, 'text' => string, 'imagen' => string, 'imagen2' => string ]
     *               o array vacio en caso de error.
     */
    public function get_carriers(): array {
        $token = $this->get_token();

        $payload = [
            'tipo'  => 'listarTransportadorasPorEmpresa',
            'token' => $token,
            'id'    => $this->idempresa,
        ];

        $response = wp_remote_post(
            $this->api_url . self::ENDPOINT_TRANSPORTADORAS,
            [
                'timeout' => 15,
                // INTEGRATIONS-AUDIT P1 FIX: sslverify explicit (was missing).
                'sslverify' => ! ( defined( 'LTMS_DISABLE_SSL_VERIFY' ) && LTMS_DISABLE_SSL_VERIFY ),
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( $payload ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            return [];
        }

        $body    = wp_remote_retrieve_body( $response );
        $decoded = json_decode( $body, true );

        if ( ! isset( $decoded['status'] ) || $decoded['status'] !== 'ok' ) {
            return [];
        }

        return $decoded['transportadoras'] ?? [];
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

    // ── Relaciones de Envíos ──────────────────────────────────────────────────

    /**
     * Crea una Relación de Envíos (manifiesto de despacho).
     *
     * Agrupa varias guías bajo un número único para su entrega a la transportadora.
     *
     * @param string       $transportadora Código de la transportadora (ej: '1016', '33').
     * @param string|array $guias          Número(s) de guía. Array o string separado por comas.
     * @return array {
     *   @type bool   $success       true si el status de Aveonline es 'ok'.
     *   @type string $relacionenvio Número de la relación creada.
     *   @type string $fecha         Fecha/hora de registro.
     *   @type string $rutaimpresion URL del PDF de manifiesto.
     *   @type string $message       Mensaje de la API.
     * }
     * @throws \RuntimeException Si la petición HTTP falla.
     */
    public function create_shipment_relation( string $transportadora, $guias ): array {
        if ( is_array( $guias ) ) {
            $guias = implode( ', ', array_filter( array_map( 'trim', $guias ) ) );
        }

        $token = $this->get_token();

        $payload = [
            'tipo'          => 'relacionEnvios',
            'token'         => $token,
            'idempresa'     => $this->idempresa,
            'transportadora'=> $transportadora,
            'guias'         => $guias,
        ];

        // AO-BUG-10 FIX: Idempotency-Key determinista para evitar duplicados de
        // relación de envíos en caso de retry por timeout de red.
        // INTEGRATIONS-AUDIT P0 FIX: hash transportadora to prevent header injection.
        $idem_key = 'ltms_ave_relacion_' . md5( $transportadora . '_' . $guias );

        $response = $this->aveonline_request( self::ENDPOINT_GUIA, $payload, [
            'Idempotency-Key' => $idem_key,
        ] );
        $success  = ( $response['status'] ?? '' ) === 'ok';

        return [
            'success'        => $success,
            'relacionenvio'  => $response['relacionenvio'] ?? '',
            'fecha'          => $response['fecha'] ?? '',
            'rutaimpresion'  => $response['rutaimpresion'] ?? '',
            'message'        => $response['message'] ?? '',
        ];
    }

    /**
     * Lista relaciones de envíos filtradas por distintos criterios.
     *
     * Se debe pasar al menos uno de: $numero_relacion, ($fecha_inicial + $fecha_final) o $numero_guia.
     *
     * @param string|null $numero_relacion Número de relación (ej: '6077101620220418145538').
     * @param string|null $fecha_inicial   Fecha inicio AAAA/MM/DD.
     * @param string|null $fecha_final     Fecha fin AAAA/MM/DD.
     * @param string|null $numero_guia     Número de guía individual.
     * @return array {
     *   @type bool   $success    true si se encontraron registros.
     *   @type array  $registros  Lista de relaciones; cada una con id, transportadora, fecha,
     *                            numeroguias, oc, guias[].
     *   @type string $message    Mensaje de la API.
     * }
     * @throws \RuntimeException Si la petición HTTP falla.
     */
    public function list_shipment_relations(
        ?string $numero_relacion = null,
        ?string $fecha_inicial   = null,
        ?string $fecha_final     = null,
        ?string $numero_guia     = null
    ): array {
        $token = $this->get_token();

        $payload = [
            'tipo'      => 'listarRelacionEnvios',
            'token'     => $token,
            'idempresa' => (string) $this->idempresa,
        ];

        if ( $numero_relacion ) {
            $payload['numeroRelacionEnvios'] = $numero_relacion;
        }
        if ( $fecha_inicial ) {
            $payload['fechainicial'] = $fecha_inicial;
        }
        if ( $fecha_final ) {
            $payload['fechafinal'] = $fecha_final;
        }
        if ( $numero_guia ) {
            $payload['numeroguia'] = $numero_guia;
        }

        $response = $this->aveonline_request( self::ENDPOINT_GUIA, $payload );
        $success  = ( $response['status'] ?? '' ) === 'ok';

        return [
            'success'   => $success,
            'registros' => $response['registros'] ?? [],
            'message'   => $response['message'] ?? '',
        ];
    }

    /**
     * Elimina una Relación de Envíos.
     *
     * Usa el endpoint v2.0 con JWT en cabecera Authorization (diferente al resto de métodos).
     *
     * @param string $numero_relacion Número de relación a eliminar.
     * @return array {
     *   @type bool   $success true si Aveonline confirmó la eliminación.
     *   @type string $message Mensaje de la API.
     * }
     * @throws \RuntimeException Si la petición HTTP falla.
     */
    public function delete_shipment_relation( string $numero_relacion ): array {
        if ( trim( $numero_relacion ) === '' ) {
            throw new \InvalidArgumentException( 'El número de relación no puede estar vacío.' );
        }

        $token = $this->get_token();

        // Este endpoint usa v2.0 y el JWT va en el header Authorization, no en el body.
        $url     = $this->api_url . '/nal/v2.0/generarGuiaTransporteNacional.php';
        $payload = [
            'tipo'                  => 'eliminarRelacionEnvios',
            'usuario'               => $this->usuario,
            'numeroRelacionEnvios'  => $numero_relacion,
        ];

        $raw = wp_remote_post( $url, [
            'headers' => [
                'Content-Type'     => 'application/json',
                // INTEGRATIONS-AUDIT P0 FIX: add 'Bearer ' prefix — Aveonline v2.0
                // JWT endpoints expect 'Bearer <token>', not the raw token.
                'Authorization'    => 'Bearer ' . $token,
                // AO-BUG-10 FIX: Idempotency-Key determinista por número de
                // relación para que un retry no elimine dos veces.
                // INTEGRATIONS-AUDIT P0 FIX: hash numero_relacion to prevent header injection.
                'Idempotency-Key'  => 'ltms_ave_eliminar_relacion_' . md5( (string) $numero_relacion ),
            ],
            'body'    => wp_json_encode( $payload ),
            'timeout' => 20,
            // INTEGRATIONS-AUDIT P1 FIX: sslverify explicit (was missing).
            'sslverify' => ! ( defined( 'LTMS_DISABLE_SSL_VERIFY' ) && LTMS_DISABLE_SSL_VERIFY ),
        ] );

        if ( is_wp_error( $raw ) ) {
            throw new \RuntimeException( 'Aveonline HTTP error (deleteRelacion): ' . $raw->get_error_message() );
        }

        $body    = json_decode( wp_remote_retrieve_body( $raw ), true );
        $success = is_array( $body ) && ( $body['status'] ?? '' ) === 'ok';

        return [
            'success' => $success,
            'message' => is_array( $body ) ? ( $body['message'] ?? '' ) : 'Respuesta inválida',
        ];
    }

    // ── Destinatarios ──────────────────────────────────────────────────────────

    /**
     * Busca destinatarios registrados en Aveonline por término de búsqueda.
     *
     * @param  string $param  Término de búsqueda (mínimo 3 caracteres).
     * @return array{status:string, destinatarios:array}
     * @throws \RuntimeException Si la petición HTTP falla.
     */
    public function search_recipients( string $param ): array {
        $token = $this->get_token();
        $url   = $this->api_url . self::ENDPOINT_DESTINATARIOS;

        $payload = [
            'tipo'       => 'listardestinatarios',
            'token'      => $token,
            'idempresa'  => (int) $this->idempresa,
            'param'      => $param,
        ];

        $raw = wp_remote_post( $url, [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $payload ),
            'timeout' => 15,
            // INTEGRATIONS-AUDIT P1 FIX: sslverify explicit (was missing).
            'sslverify' => ! ( defined( 'LTMS_DISABLE_SSL_VERIFY' ) && LTMS_DISABLE_SSL_VERIFY ),
        ] );

        if ( is_wp_error( $raw ) ) {
            throw new \RuntimeException( 'Aveonline HTTP error (destinatarios): ' . $raw->get_error_message() );
        }

        $body = json_decode( wp_remote_retrieve_body( $raw ), true );

        if ( ! is_array( $body ) || ( $body['status'] ?? '' ) !== 'ok' ) {
            return [ 'status' => 'error', 'destinatarios' => [] ];
        }

        return [
            'status'        => 'ok',
            'destinatarios' => $body['destinatarios'] ?? [],
        ];
    }

    // ── Reimpresión y recogidas ─────────────────────────────────────────────────

    /**
     * Reimprime los documentos (rótulo/sticker) de una o varias guías.
     *
     * Endpoint: POST /nal/v1.0/generarGuiaTransporteNacional.php  tipo:"imprimirGuia"
     *
     * AO-BUG-3 FIX: el manager de guías llamaba `$api->reprint_guides()` que no
     * existía en el cliente, produciendo un PHP \Error fatal (HTTP 500). Este
     * método expone la operación con el nombre esperado por el manager.
     *
     * @param int       $idoperador ID de la transportadora en Aveonline.
     * @param int       $idcliente  ID de empresa (`ltms_aveonline_idempresa`).
     * @param string[]  $guias      Lista de números de guía.
     * @return array {
     *   @type string $status     'ok' | 'error'.
     *   @type array  $resultado  Lista de documentos por guía (rutaguia, rotulo, sticker).
     *   @type string $message    Mensaje de la API.
     * }
     * @throws \RuntimeException En error de red.
     */
    public function reprint_guides( int $idoperador, int $idcliente, array $guias ): array {
        $guias_clean = array_values( array_filter( array_map( 'strval', $guias ) ) );
        if ( empty( $idoperador ) || empty( $idcliente ) || empty( $guias_clean ) ) {
            return [
                'status'    => 'error',
                'resultado' => [],
                'message'   => 'idoperador, idcliente y guías son obligatorios.',
            ];
        }

        $token = $this->get_token();

        $payload = [
            'tipo'          => 'imprimirGuia',
            'token'         => $token,
            'idempresa'     => $idcliente,
            'idtransportador' => $idoperador,
            'guias'         => implode( ',', $guias_clean ),
        ];

        // AO-BUG-10 FIX: Idempotency-Key determinista — reimpresión de la misma
        // lista de guías no debería generar solicitudes duplicadas en Aveonline.
        $idem_key = 'ltms_ave_reimprimir_' . $idoperador . '_' . md5( implode( ',', $guias_clean ) );

        $response = $this->aveonline_request( self::ENDPOINT_GUIA, $payload, [
            'Idempotency-Key' => $idem_key,
        ] );

        return [
            'status'    => ( $response['status'] ?? '' ) === 'ok' ? 'ok' : 'error',
            'resultado' => $response['resultado'] ?? $response['guias'] ?? [],
            'message'   => $response['message'] ?? '',
        ];
    }

    /**
     * Solicita la recogida de paquetes para una o varias guías.
     *
     * Endpoint: POST /nal/v1.0/generarGuiaTransporteNacional.php  tipo:"solicitarRecogida"
     *
     * AO-BUG-4 FIX: el manager de guías llamaba `$api->request_pickup()` que no
     * existía en el cliente, produciendo un PHP \Error fatal (HTTP 500).
     *
     * @param string[] $guias Lista de números de guía.
     * @param string   $dscom  Comentarios adicionales (opcional).
     * @return array {
     *   @type string $status  'ok' | 'error'.
     *   @type string $message Mensaje de la API.
     * }
     * @throws \RuntimeException En error de red.
     */
    public function request_pickup( array $guias, string $dscom = '' ): array {
        $guias_clean = array_values( array_filter( array_map( 'strval', $guias ) ) );
        if ( empty( $guias_clean ) ) {
            return [
                'status'  => 'error',
                'message' => 'Se requiere al menos un número de guía.',
            ];
        }

        $token = $this->get_token();

        $payload = [
            'tipo'      => 'solicitarRecogida',
            'token'     => $token,
            'idempresa' => $this->idempresa,
            'guias'     => implode( ',', $guias_clean ),
            'dscom'     => $dscom,
        ];

        // AO-BUG-10 FIX: Idempotency-Key determinista — una doble solicitación
        // de recogida para las mismas guías no debe generar dos turnos.
        $idem_key = 'ltms_ave_recogida_' . md5( implode( ',', $guias_clean ) );

        $response = $this->aveonline_request( self::ENDPOINT_GUIA, $payload, [
            'Idempotency-Key' => $idem_key,
        ] );

        return [
            'status'  => ( $response['status'] ?? '' ) === 'ok' ? 'ok' : 'error',
            'message' => $response['message'] ?? '',
        ];
    }

    // ── Órdenes de Compra ───────────────────────────────────────────────────────

    /**
     * Lista los proveedores disponibles para OC en Aveonline.
     *
     * Endpoint: POST /nal/v2.0/ordendeCompra.php  tipo:"listarproveedores"
     *
     * AO-BUG-9 FIX: el manager de OC usaba `wp_remote_post()` directo, sin pasar
     * por este cliente. Eso omitía logging, reintentos, y verificación SSL del
     * abstract client. Centralizamos la llamada aquí.
     *
     * @return array Respuesta decodificada (agentes/proveedores + status).
     * @throws \RuntimeException En error de red.
     */
    public function list_proveedores_oc(): array {
        $payload = [
            'tipo'      => 'listarproveedores',
            'idempresa' => $this->idempresa,
        ];

        return $this->aveonline_request( '/nal/v2.0/ordendeCompra.php', $payload );
    }

    /**
     * Crea una orden de compra en Aveonline.
     *
     * Endpoint: POST /nal/v2.0/ordendeCompra.php  tipo:"generarorden"
     *
     * AO-BUG-9 FIX: centraliza la generación de OC en el cliente de API para que
     * aproveche reintentos, logging y verificación SSL del abstract client.
     * AO-BUG-10 FIX: incluye header `Idempotency-Key` determinista por número de
     * OC para que un doble-submit no cree dos OC en Aveonline.
     *
     * @param array $payload Datos de la OC (idproveedor, ordencompra, detalle, ...).
     * @return array Respuesta decodificada.
     * @throws \RuntimeException En error de red.
     */
    public function crear_orden_compra( array $payload ): array {
        $payload['token']     = $this->get_token();
        $payload['idempresa'] = $this->idempresa;

        $ordencompra = (string) ( $payload['ordencompra'] ?? '' );
        $idem_key    = 'ltms_ave_crear_oc_' . ( $ordencompra !== '' ? $ordencompra : md5( wp_json_encode( $payload ) ) );

        return $this->aveonline_request( '/nal/v2.0/ordendeCompra.php', $payload, [
            'Idempotency-Key' => $idem_key,
        ] );
    }
}
