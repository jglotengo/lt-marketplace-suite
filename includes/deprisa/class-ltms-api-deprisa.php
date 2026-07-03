<?php
/**
 * Clase principal para la integración con la API de Deprisa (Conecta2 / APIM)
 * Basado en: Manual de Servicios APIM Rev.00 y Manual de Servicios Estándar Rev.02
 *
 * @package LT_Marketplace_Suite
 * @subpackage Deprisa
 * @version 1.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LTMS_Deprisa_API {

    /**
     * URLs base de producción y pruebas
     */
    const URL_PRODUCCION = 'https://conectados.deprisa.com/conecta2/seam/resource/restv1/auth';
    const URL_PRUEBAS    = 'https://conectadoslatincopre.alertran.net/conecta2/seam/resource/restv1/auth';

    /**
     * Credenciales de autenticación Basic Auth
     * Se configuran desde wp-admin > Ajustes > Lo Tengo > Deprisa
     */
    private $usuario;
    private $password;
    private $modo_pruebas;
    private $base_url;

    /**
     * Constructor
     *
     * Lee las credenciales con los mismos nombres de opción que usa la
     * página de ajustes (LTMS_Settings_Deprisa):
     *   - ltms_deprisa_username  (NO ltms_deprisa_usuario)
     *   - ltms_deprisa_password
     *   - ltms_deprisa_sandbox   (NO ltms_deprisa_modo_pruebas)
     */
    public function __construct() {
        $this->usuario      = get_option( 'ltms_deprisa_username', '' );
        $this->password     = get_option( 'ltms_deprisa_password', '' );
        $this->modo_pruebas = (bool) get_option( 'ltms_deprisa_sandbox', false );
        $this->base_url     = $this->modo_pruebas ? self::URL_PRUEBAS : self::URL_PRODUCCION;
    }

    // =========================================================================
    // MÉTODOS PRIVADOS DE UTILIDAD
    // =========================================================================

    /**
     * Realiza una solicitud HTTP POST a la API de Deprisa con autenticación Basic Auth
     *
     * @param string $endpoint  Endpoint relativo (ej. '/admision_envios')
     * @param string $xml_body  Cuerpo XML de la solicitud
     * @return array|WP_Error   Respuesta decodificada o error
     */
    private function hacer_request_post( $endpoint, $xml_body ) {
        $url = $this->base_url . $endpoint;

        $headers = array(
            'Content-Type'  => 'application/xml; charset=UTF-8',
            'Authorization' => 'Basic ' . base64_encode( $this->usuario . ':' . $this->password ),
        );

        $response = wp_remote_post( $url, array(
            'headers'   => $headers,
            'body'      => $xml_body,
            'timeout'   => 30,
            'sslverify' => true,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code    = wp_remote_retrieve_response_code( $response );
        $body    = wp_remote_retrieve_body( $response );

        return array(
            'code' => $code,
            'body' => $body,
            'xml'  => $this->parse_xml( $body ),
        );
    }

    /**
     * Realiza una solicitud HTTP GET a la API de Deprisa
     *
     * @param string $url_completa URL completa incluyendo parámetros de ruta
     * @return array|WP_Error
     */
    private function hacer_request_get( $url_completa ) {
        $headers = array(
            'Authorization' => 'Basic ' . base64_encode( $this->usuario . ':' . $this->password ),
        );

        $response = wp_remote_get( $url_completa, array(
            'headers'   => $headers,
            'timeout'   => 30,
            'sslverify' => true,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        return array(
            'code' => $code,
            'body' => $body,
            'xml'  => $this->parse_xml( $body ),
        );
    }

    /**
     * Parsea una cadena XML de forma segura
     *
     * @param string $xml_string
     * @return SimpleXMLElement|false
     */
    private function parse_xml( $xml_string ) {
        if ( empty( $xml_string ) ) {
            return false;
        }
        libxml_use_internal_errors( true );
        $xml = simplexml_load_string( $xml_string, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOENT );
        if ( $xml === false ) {
            $this->log( 'Error parseando XML: ' . implode( ', ', array_map( function( $e ) {
                return $e->message;
            }, libxml_get_errors() ) ) );
            libxml_clear_errors();
            return false;
        }
        return $xml;
    }

    /**
     * Verifica si una respuesta XML contiene errores de la API
     *
     * @param SimpleXMLElement $xml
     * @return array Lista de errores o array vacío
     */
    private function extraer_errores_xml( $xml ) {
        $errores = array();
        if ( ! $xml ) {
            return $errores;
        }
        if ( isset( $xml->ERRORES->ERROR ) ) {
            foreach ( $xml->ERRORES->ERROR as $error ) {
                $errores[] = array(
                    'codigo'      => (string) $error['ERROR_CODIGO'],
                    'descripcion' => (string) $error['ERROR_DESCRIPCION'],
                    'valor'       => (string) $error['VALOR_ERRONEO'],
                );
            }
        }
        return $errores;
    }

    /**
     * Log interno para debug
     *
     * @param string $mensaje
     */
    private function log( $mensaje ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[LTMS Deprisa API] ' . $mensaje );
        }
    }

    // =========================================================================
    // ADMISIÓN DE ENVÍOS
    // =========================================================================

    /**
     * Crea/admite un envío en Deprisa
     *
     * @param array $datos  Datos del envío según estructura del manual §6.2
     * @param bool  $solo_consulta  true = modo N (consulta), false = modo S (grabación)
     * @return array|WP_Error  Array con 'numero_envio', 'errores', 'raw'
     */
    public function admitir_envio( $datos, $solo_consulta = false ) {
        $grabar = $solo_consulta ? 'N' : 'S';

        $xml = $this->build_admision_xml( $datos, $grabar );
        $resultado = $this->hacer_request_post( '/admision_envios', $xml );

        if ( is_wp_error( $resultado ) ) {
            return $resultado;
        }

        $errores      = $this->extraer_errores_xml( $resultado['xml'] );
        $numero_envio = '';

        if ( empty( $errores ) && $resultado['xml'] ) {
            $admision = $resultado['xml']->ADMISIONES->RESPUESTA_ADMISION ?? null;
            if ( $admision ) {
                $numero_envio = (string) $admision->NUMERO_ENVIO;
            }
        }

        return array(
            'exito'        => empty( $errores ) && ! empty( $numero_envio ),
            'numero_envio' => $numero_envio,
            'errores'      => $errores,
            'http_code'    => $resultado['code'],
            'raw'          => $resultado['body'],
        );
    }

    /**
     * Construye el XML para admisión de envío
     *
     * @param array  $datos
     * @param string $grabar 'S' o 'N'
     * @return string XML
     */
    private function build_admision_xml( $datos, $grabar = 'S' ) {
        $d = wp_parse_args( $datos, array(
            'codigo_admision'              => '',
            'numero_bultos'                => 1,
            'fecha_hora_admision'          => '',
            'cliente_remitente'            => '',
            'centro_remitente'             => '01',
            'nombre_remitente'             => '',
            'direccion_remitente'          => '',
            'pais_remitente'               => '057',
            'codigo_postal_remitente'      => '',
            'poblacion_remitente'          => '',
            'tipo_doc_remitente'           => 'CC',
            'documento_remitente'          => '',
            'persona_contacto_remitente'   => '',
            'telefono_remitente'           => '',
            'departamento_remitente'       => '',
            'email_remitente'              => '',
            'cliente_destinatario'         => '99999999',
            'centro_destinatario'          => '99',
            'nombre_destinatario'          => '',
            'direccion_destinatario'       => '',
            'pais_destinatario'            => '057',
            'codigo_postal_destinatario'   => '',
            'poblacion_destinatario'       => '',
            'tipo_doc_destinatario'        => 'CC',
            'documento_destinatario'       => '0',
            'persona_contacto_destinatario'=> '',
            'telefono_destinatario'        => '',
            'departamento_destinatario'    => '',
            'email_destinatario'           => '',
            'codigo_servicio'              => '3005',
            'kilos'                        => 1,
            'numero_referencia'            => '',
            'importe_reembolso'            => '',
            'importe_valor_declarado'      => '0',
            'tipo_portes'                  => 'P',
            'tipo_porte_reembolsos'        => '',
            'observaciones1'               => '',
            'observaciones2'               => '',
            'asegurar_envio'               => 'N',
            'tipo_moneda'                  => 'COP',
        ) );

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<ADMISIONES>' . "\n";
        $xml .= '  <ADMISION>' . "\n";
        $xml .= '    <GRABAR_ENVIO>' . esc_xml( $grabar ) . '</GRABAR_ENVIO>' . "\n";
        $xml .= '    <CODIGO_ADMISION>' . esc_xml( $d['codigo_admision'] ) . '</CODIGO_ADMISION>' . "\n";
        $xml .= '    <NUMERO_BULTOS>' . intval( $d['numero_bultos'] ) . '</NUMERO_BULTOS>' . "\n";
        $xml .= '    <FECHA_HORA_ADMISION>' . esc_xml( $d['fecha_hora_admision'] ) . '</FECHA_HORA_ADMISION>' . "\n";
        $xml .= '    <CLIENTE_REMITENTE>' . esc_xml( $d['cliente_remitente'] ) . '</CLIENTE_REMITENTE>' . "\n";
        $xml .= '    <CENTRO_REMITENTE>' . esc_xml( $d['centro_remitente'] ) . '</CENTRO_REMITENTE>' . "\n";
        $xml .= '    <NOMBRE_REMITENTE>' . esc_xml( $d['nombre_remitente'] ) . '</NOMBRE_REMITENTE>' . "\n";
        $xml .= '    <DIRECCION_REMITENTE>' . esc_xml( $d['direccion_remitente'] ) . '</DIRECCION_REMITENTE>' . "\n";
        $xml .= '    <PAIS_REMITENTE>' . esc_xml( $d['pais_remitente'] ) . '</PAIS_REMITENTE>' . "\n";
        $xml .= '    <CODIGO_POSTAL_REMITENTE>' . esc_xml( $d['codigo_postal_remitente'] ) . '</CODIGO_POSTAL_REMITENTE>' . "\n";
        $xml .= '    <POBLACION_REMITENTE>' . esc_xml( $d['poblacion_remitente'] ) . '</POBLACION_REMITENTE>' . "\n";
        $xml .= '    <TIPO_DOC_REMITENTE>' . esc_xml( $d['tipo_doc_remitente'] ) . '</TIPO_DOC_REMITENTE>' . "\n";
        $xml .= '    <DOCUMENTO_IDENTIDAD_REMITENTE>' . esc_xml( $d['documento_remitente'] ) . '</DOCUMENTO_IDENTIDAD_REMITENTE>' . "\n";
        $xml .= '    <PERSONA_CONTACTO_REMITENTE>' . esc_xml( $d['persona_contacto_remitente'] ) . '</PERSONA_CONTACTO_REMITENTE>' . "\n";
        $xml .= '    <TELEFONO_CONTACTO_REMITENTE>' . esc_xml( $d['telefono_remitente'] ) . '</TELEFONO_CONTACTO_REMITENTE>' . "\n";
        $xml .= '    <DEPARTAMENTO_REMITENTE>' . esc_xml( $d['departamento_remitente'] ) . '</DEPARTAMENTO_REMITENTE>' . "\n";
        $xml .= '    <EMAIL_REMITENTE>' . esc_xml( $d['email_remitente'] ) . '</EMAIL_REMITENTE>' . "\n";
        $xml .= '    <CLIENTE_DESTINATARIO>' . esc_xml( $d['cliente_destinatario'] ) . '</CLIENTE_DESTINATARIO>' . "\n";
        $xml .= '    <CENTRO_DESTINATARIO>' . esc_xml( $d['centro_destinatario'] ) . '</CENTRO_DESTINATARIO>' . "\n";
        $xml .= '    <NOMBRE_DESTINATARIO>' . esc_xml( $d['nombre_destinatario'] ) . '</NOMBRE_DESTINATARIO>' . "\n";
        $xml .= '    <DIRECCION_DESTINATARIO>' . esc_xml( $d['direccion_destinatario'] ) . '</DIRECCION_DESTINATARIO>' . "\n";
        $xml .= '    <PAIS_DESTINATARIO>' . esc_xml( $d['pais_destinatario'] ) . '</PAIS_DESTINATARIO>' . "\n";
        $xml .= '    <CODIGO_POSTAL_DESTINATARIO>' . esc_xml( $d['codigo_postal_destinatario'] ) . '</CODIGO_POSTAL_DESTINATARIO>' . "\n";
        $xml .= '    <POBLACION_DESTINATARIO>' . esc_xml( $d['poblacion_destinatario'] ) . '</POBLACION_DESTINATARIO>' . "\n";
        $xml .= '    <TIPO_DOC_DESTINATARIO>' . esc_xml( $d['tipo_doc_destinatario'] ) . '</TIPO_DOC_DESTINATARIO>' . "\n";
        $xml .= '    <DOCUMENTO_IDENTIDAD_DESTINATARIO>' . esc_xml( $d['documento_destinatario'] ) . '</DOCUMENTO_IDENTIDAD_DESTINATARIO>' . "\n";
        $xml .= '    <PERSONA_CONTACTO_DESTINATARIO>' . esc_xml( $d['persona_contacto_destinatario'] ) . '</PERSONA_CONTACTO_DESTINATARIO>' . "\n";
        $xml .= '    <TELEFONO_CONTACTO_DESTINATARIO>' . esc_xml( $d['telefono_destinatario'] ) . '</TELEFONO_CONTACTO_DESTINATARIO>' . "\n";
        $xml .= '    <DEPARTAMENTO_DESTINATARIO>' . esc_xml( $d['departamento_destinatario'] ) . '</DEPARTAMENTO_DESTINATARIO>' . "\n";
        $xml .= '    <EMAIL_DESTINATARIO>' . esc_xml( $d['email_destinatario'] ) . '</EMAIL_DESTINATARIO>' . "\n";
        $xml .= '    <CODIGO_SERVICIO>' . esc_xml( $d['codigo_servicio'] ) . '</CODIGO_SERVICIO>' . "\n";
        $xml .= '    <KILOS>' . floatval( $d['kilos'] ) . '</KILOS>' . "\n";
        $xml .= '    <NUMERO_REFERENCIA>' . esc_xml( $d['numero_referencia'] ) . '</NUMERO_REFERENCIA>' . "\n";
        $xml .= '    <IMPORTE_REEMBOLSO>' . esc_xml( $d['importe_reembolso'] ) . '</IMPORTE_REEMBOLSO>' . "\n";
        $xml .= '    <IMPORTE_VALOR_DECLARADO>' . esc_xml( $d['importe_valor_declarado'] ) . '</IMPORTE_VALOR_DECLARADO>' . "\n";
        $xml .= '    <TIPO_PORTES>' . esc_xml( $d['tipo_portes'] ) . '</TIPO_PORTES>' . "\n";
        $xml .= '    <TIPO_PORTE_REEMBOLSOS>' . esc_xml( $d['tipo_porte_reembolsos'] ) . '</TIPO_PORTE_REEMBOLSOS>' . "\n";
        $xml .= '    <OBSERVACIONES1>' . esc_xml( $d['observaciones1'] ) . '</OBSERVACIONES1>' . "\n";
        $xml .= '    <OBSERVACIONES2>' . esc_xml( $d['observaciones2'] ) . '</OBSERVACIONES2>' . "\n";
        $xml .= '    <ASEGURAR_ENVIO>' . esc_xml( $d['asegurar_envio'] ) . '</ASEGURAR_ENVIO>' . "\n";
        $xml .= '    <TIPO_MONEDA>' . esc_xml( $d['tipo_moneda'] ) . '</TIPO_MONEDA>' . "\n";
        $xml .= '  </ADMISION>' . "\n";
        $xml .= '</ADMISIONES>';

        return $xml;
    }

    // =========================================================================
    // ETIQUETAS
    // =========================================================================

    /**
     * Obtiene la etiqueta PDF en base64 para un número de envío
     *
     * @param string $numero_envio   Número de guía Deprisa
     * @param string $tipo_impresora 'T' térmica | 'L' láser | 'ZPL'
     * @return array|WP_Error Array con 'base64', 'errores', 'raw'
     */
    public function obtener_etiqueta( $numero_envio, $tipo_impresora = 'T' ) {
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<ETIQUETAS>' . "\n";
        $xml .= '  <ETIQUETA>' . "\n";
        $xml .= '    <NUMERO_ENVIO>' . esc_xml( $numero_envio ) . '</NUMERO_ENVIO>' . "\n";
        $xml .= '    <TIPO_IMPRESORA>' . esc_xml( $tipo_impresora ) . '</TIPO_IMPRESORA>' . "\n";
        $xml .= '  </ETIQUETA>' . "\n";
        $xml .= '</ETIQUETAS>';

        $resultado = $this->hacer_request_post( '/admision_envios/etiquetas', $xml );

        if ( is_wp_error( $resultado ) ) {
            return $resultado;
        }

        $base64 = '';
        if ( $resultado['xml'] ) {
            $etiqueta = $resultado['xml']->RESPUESTA_ETIQUETAS->ETIQUETA ?? null;
            if ( $etiqueta ) {
                $base64 = (string) $etiqueta;
            }
        }

        return array(
            'exito'     => ! empty( $base64 ),
            'base64'    => $base64,
            'errores'   => $this->extraer_errores_xml( $resultado['xml'] ),
            'http_code' => $resultado['code'],
            'raw'       => $resultado['body'],
        );
    }

    // =========================================================================
    // TRACKING
    // =========================================================================

    /**
     * Consulta el tracking de una guía Deprisa
     *
     * @param string $numero_envio  Número de guía (mínimo 5 caracteres)
     * @return array|WP_Error  Array con datos del envío, eventos, incidencias
     */
    public function consultar_tracking( $numero_envio ) {
        if ( strlen( $numero_envio ) < 5 ) {
            return new WP_Error( 'tracking_invalido', 'El número de envío debe tener al menos 5 caracteres.' );
        }

        $url = $this->base_url . '/tracking/' . urlencode( $numero_envio );
        $resultado = $this->hacer_request_get( $url );

        if ( is_wp_error( $resultado ) ) {
            return $resultado;
        }

        // HTTP 404 = guía inexistente
        if ( $resultado['code'] === 404 ) {
            return array(
                'exito'        => false,
                'http_code'    => 404,
                'mensaje'      => 'Guía no encontrada en Deprisa.',
                'estados'      => array(),
                'incidencias'  => array(),
                'raw'          => $resultado['body'],
            );
        }

        // HTTP 400 = petición incorrecta (número muy corto o vacío)
        if ( $resultado['code'] === 400 ) {
            return array(
                'exito'     => false,
                'http_code' => 400,
                'mensaje'   => 'Número de guía inválido.',
                'raw'       => $resultado['body'],
            );
        }

        // HTTP 500 = error interno Deprisa
        if ( $resultado['code'] === 500 ) {
            return array(
                'exito'     => false,
                'http_code' => 500,
                'mensaje'   => 'Error interno en el servidor de Deprisa.',
                'raw'       => $resultado['body'],
            );
        }

        // HTTP 200 — parsear respuesta
        $xml    = $resultado['xml'];
        $envio  = array();
        $estados     = array();
        $incidencias = array();

        if ( $xml ) {
            $envio = array(
                'numero_envio'          => (string) ( $xml->NUMERO_ENVIO ?? '' ),
                'numero_referencia'     => (string) ( $xml->NUMERO_REFERENCIA ?? '' ),
                'kilos'                 => (string) ( $xml->KILOS ?? '' ),
                'fecha_hora_admision'   => (string) ( $xml->FECHA_HORA_ADMISION ?? '' ),
                'fecha_hora_entrega'    => (string) ( $xml->FECHA_HORA_ENTREGA ?? '' ),
                'codigo_servicio'       => (string) ( $xml->CODIGO_SERVICIO ?? '' ),
                'descripcion_servicio'  => (string) ( $xml->DESCRIPCION_SERVICIO ?? '' ),
                'nombre_destinatario'   => (string) ( $xml->NOMBRE_DESTINATARIO ?? '' ),
                'direccion_destinatario'=> (string) ( $xml->DIRECCION_DESTINATARIO ?? '' ),
                'poblacion_destinatario'=> (string) ( $xml->POBLACION_DESTINATARIO ?? '' ),
                'nombre_remitente'      => (string) ( $xml->NOMBRE_REMITENTE ?? '' ),
                'poblacion_remitente'   => (string) ( $xml->POBLACION_REMITENTE ?? '' ),
                'oficina_admision'      => (string) ( $xml->OFICINA_ADMISION ?? '' ),
            );

            if ( isset( $xml->ESTADOS->ESTADO ) ) {
                foreach ( $xml->ESTADOS->ESTADO as $estado ) {
                    $estados[] = array(
                        'tipo_evento'      => (string) ( $estado->TIPO_EVENTO_CODIGO ?? '' ),
                        'descripcion'      => (string) ( $estado->DESCRIPCION ?? '' ),
                        'fecha_evento'     => (string) ( $estado->FECHA_EVENTO ?? '' ),
                        'delegacion_codigo'=> (string) ( $estado->DELEGACION_CODIGO ?? '' ),
                        'delegacion_nombre'=> (string) ( $estado->DELEGACION_NOMBRE ?? '' ),
                    );
                }
            }

            if ( isset( $xml->INCIDENCIAS->INCIDENCIA ) ) {
                foreach ( $xml->INCIDENCIAS->INCIDENCIA as $inc ) {
                    $incidencias[] = array(
                        'tipo'        => (string) ( $inc->TIPO ?? '' ),
                        'id'          => (string) ( $inc->ID ?? '' ),
                        'descripcion' => (string) ( $inc->DESCRIPCION ?? '' ),
                        'fecha_alta'  => (string) ( $inc->FECHA_ALTA ?? '' ),
                        'cerrada'     => (string) ( $inc->CERRADA ?? '' ),
                    );
                }
            }
        }

        return array(
            'exito'       => true,
            'http_code'   => $resultado['code'],
            'envio'       => $envio,
            'estados'     => $estados,
            'incidencias' => $incidencias,
            'raw'         => $resultado['body'],
        );
    }

    // =========================================================================
    // COTIZACIÓN
    // =========================================================================

    /**
     * Cotiza un envío Deprisa
     *
     * @param array $datos  Datos de cotización según manual §9.2
     * @return array|WP_Error  Array con productos cotizados
     */
    public function cotizar( $datos ) {
        $d = wp_parse_args( $datos, array(
            'tipo_envio'             => 'N',
            'numero_bultos'          => 1,
            'kilos'                  => 1,
            'cliente_remitente'      => '',
            'centro_remitente'       => '01',
            'pais_remitente'         => '057',
            'poblacion_remitente'    => '',
            'pais_destinatario'      => '057',
            'poblacion_destinatario' => '',
            'codigo_servicio'        => '',
            'importe_valor_declarado'=> 0,
            'tipo_moneda'            => 'COP',
            'largo'                  => '',
            'ancho'                  => '',
            'alto'                   => '',
        ) );

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<COTIZACIONES>' . "\n";
        $xml .= '  <ADMISION>' . "\n";
        $xml .= '    <TIPO_ENVIO>' . esc_xml( $d['tipo_envio'] ) . '</TIPO_ENVIO>' . "\n";
        $xml .= '    <NUMERO_BULTOS>' . intval( $d['numero_bultos'] ) . '</NUMERO_BULTOS>' . "\n";
        $xml .= '    <KILOS>' . floatval( $d['kilos'] ) . '</KILOS>' . "\n";
        $xml .= '    <CLIENTE_REMITENTE>' . esc_xml( $d['cliente_remitente'] ) . '</CLIENTE_REMITENTE>' . "\n";
        $xml .= '    <CENTRO_REMITENTE>' . esc_xml( $d['centro_remitente'] ) . '</CENTRO_REMITENTE>' . "\n";
        $xml .= '    <PAIS_REMITENTE>' . esc_xml( $d['pais_remitente'] ) . '</PAIS_REMITENTE>' . "\n";
        $xml .= '    <POBLACION_REMITENTE>' . esc_xml( $d['poblacion_remitente'] ) . '</POBLACION_REMITENTE>' . "\n";
        $xml .= '    <PAIS_DESTINATARIO>' . esc_xml( $d['pais_destinatario'] ) . '</PAIS_DESTINATARIO>' . "\n";
        $xml .= '    <POBLACION_DESTINATARIO>' . esc_xml( $d['poblacion_destinatario'] ) . '</POBLACION_DESTINATARIO>' . "\n";
        if ( ! empty( $d['codigo_servicio'] ) ) {
            $xml .= '    <CODIGO_SERVICIO>' . esc_xml( $d['codigo_servicio'] ) . '</CODIGO_SERVICIO>' . "\n";
        }
        if ( ! empty( $d['largo'] ) ) {
            $xml .= '    <LARGO>' . intval( $d['largo'] ) . '</LARGO>' . "\n";
        }
        if ( ! empty( $d['ancho'] ) ) {
            $xml .= '    <ANCHO>' . intval( $d['ancho'] ) . '</ANCHO>' . "\n";
        }
        if ( ! empty( $d['alto'] ) ) {
            $xml .= '    <ALTO>' . intval( $d['alto'] ) . '</ALTO>' . "\n";
        }
        $xml .= '    <IMPORTE_VALOR_DECLARADO>' . floatval( $d['importe_valor_declarado'] ) . '</IMPORTE_VALOR_DECLARADO>' . "\n";
        $xml .= '    <TIPO_MONEDA>' . esc_xml( $d['tipo_moneda'] ) . '</TIPO_MONEDA>' . "\n";
        $xml .= '  </ADMISION>' . "\n";
        $xml .= '</COTIZACIONES>';

        $resultado = $this->hacer_request_post( '/admision_envios/cotizar', $xml );

        if ( is_wp_error( $resultado ) ) {
            return $resultado;
        }

        $productos = array();
        if ( $resultado['xml'] && isset( $resultado['xml']->RESPUESTA_COTIZACION ) ) {
            foreach ( $resultado['xml']->RESPUESTA_COTIZACION as $respuesta ) {
                $conceptos = array();
                if ( isset( $respuesta->CONCEPTOS ) ) {
                    foreach ( $respuesta->CONCEPTOS as $concepto ) {
                        $conceptos[] = array(
                            'codigo'      => (string) ( $concepto->CONCEPTO_CODIGO ?? '' ),
                            'descripcion' => (string) ( $concepto->CONCEPTO_DESC ?? '' ),
                            'valor'       => (string) ( $concepto->CONCEPTO_VALOR ?? '' ),
                        );
                    }
                }
                $productos[] = array(
                    'producto_codigo'      => (string) ( $respuesta->PRODUCTO_CODIGO ?? '' ),
                    'producto_descripcion' => (string) ( $respuesta->PRODUCTO_DESCRIPCION ?? '' ),
                    'producto_permitido'   => (string) ( $respuesta->PRODUCTO_PERMITIDO ?? '' ),
                    'tiempo_entrega'       => (string) ( $respuesta->TIEMPO_ENTREGA ?? '' ),
                    'total'                => (string) ( $respuesta->TOTAL ?? '' ),
                    'conceptos'            => $conceptos,
                );
            }
        }

        return array(
            'exito'     => ! empty( $productos ),
            'productos' => $productos,
            'errores'   => $this->extraer_errores_xml( $resultado['xml'] ),
            'http_code' => $resultado['code'],
            'raw'       => $resultado['body'],
        );
    }

    // =========================================================================
    // RECOGIDAS
    // =========================================================================

    /**
     * Crea una recogida en Deprisa
     *
     * @param array $datos  Datos según manual de recogidas §6.2
     * @return array|WP_Error
     */
    public function crear_recogida( $datos ) {
        $d = wp_parse_args( $datos, array(
            'codigo_admision'             => '',
            'cliente_remitente'           => '',
            'centro_remitente'            => '01',
            'nombre_remitente'            => '',
            'direccion_remitente'         => '',
            'pais_remitente'              => '057',
            'codigo_postal_remitente'     => '',
            'poblacion_remitente'         => '',
            'tipo_doc_remitente'          => 'CC',
            'documento_remitente'         => '',
            'persona_contacto_remitente'  => '',
            'telefono_remitente'          => '',
            'fecha_recogida'              => '',
            'rango_horario'               => '09:00-19:00',
            'codigo_servicio'             => '3005',
            'embalaje'                    => 'C',
            'observaciones'               => '',
            'numero_bultos'               => 1,
            'kilos'                       => 1,
        ) );

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<RECOGIDAS>' . "\n";
        $xml .= '  <RECOGIDA>' . "\n";
        $xml .= '    <CODIGO_ADMISION>' . esc_xml( $d['codigo_admision'] ) . '</CODIGO_ADMISION>' . "\n";
        $xml .= '    <CLIENTE_REMITENTE>' . esc_xml( $d['cliente_remitente'] ) . '</CLIENTE_REMITENTE>' . "\n";
        $xml .= '    <CENTRO_REMITENTE>' . esc_xml( $d['centro_remitente'] ) . '</CENTRO_REMITENTE>' . "\n";
        if ( ! empty( $d['nombre_remitente'] ) ) {
            $xml .= '    <NOMBRE_REMITENTE>' . esc_xml( $d['nombre_remitente'] ) . '</NOMBRE_REMITENTE>' . "\n";
            $xml .= '    <DIRECCION_REMITENTE>' . esc_xml( $d['direccion_remitente'] ) . '</DIRECCION_REMITENTE>' . "\n";
            $xml .= '    <PAIS_REMITENTE>' . esc_xml( $d['pais_remitente'] ) . '</PAIS_REMITENTE>' . "\n";
            $xml .= '    <CODIGO_POSTAL_REMITENTE>' . esc_xml( $d['codigo_postal_remitente'] ) . '</CODIGO_POSTAL_REMITENTE>' . "\n";
            $xml .= '    <POBLACION_REMITENTE>' . esc_xml( $d['poblacion_remitente'] ) . '</POBLACION_REMITENTE>' . "\n";
        }
        $xml .= '    <TIPO_DOC_REMITENTE>' . esc_xml( $d['tipo_doc_remitente'] ) . '</TIPO_DOC_REMITENTE>' . "\n";
        $xml .= '    <DOCUMENTO_IDENTIDAD_REMITENTE>' . esc_xml( $d['documento_remitente'] ) . '</DOCUMENTO_IDENTIDAD_REMITENTE>' . "\n";
        $xml .= '    <PERSONA_CONTACTO_REMITENTE>' . esc_xml( $d['persona_contacto_remitente'] ) . '</PERSONA_CONTACTO_REMITENTE>' . "\n";
        $xml .= '    <TELEFONO_CONTACTO_REMITENTE>' . esc_xml( $d['telefono_remitente'] ) . '</TELEFONO_CONTACTO_REMITENTE>' . "\n";
        $xml .= '    <FECHA_RECOGIDA>' . esc_xml( $d['fecha_recogida'] ) . '</FECHA_RECOGIDA>' . "\n";
        $xml .= '    <RANGO_HORARIO>' . esc_xml( $d['rango_horario'] ) . '</RANGO_HORARIO>' . "\n";
        $xml .= '    <CODIGO_SERVICIO>' . esc_xml( $d['codigo_servicio'] ) . '</CODIGO_SERVICIO>' . "\n";
        $xml .= '    <EMBALAJE>' . esc_xml( $d['embalaje'] ) . '</EMBALAJE>' . "\n";
        $xml .= '    <OBSERVACIONES>' . esc_xml( $d['observaciones'] ) . '</OBSERVACIONES>' . "\n";
        $xml .= '    <NUMERO_BULTOS>' . intval( $d['numero_bultos'] ) . '</NUMERO_BULTOS>' . "\n";
        $xml .= '    <KILOS>' . floatval( $d['kilos'] ) . '</KILOS>' . "\n";
        $xml .= '  </RECOGIDA>' . "\n";
        $xml .= '</RECOGIDAS>';

        $resultado = $this->hacer_request_post( '/recogidas/crear', $xml );

        if ( is_wp_error( $resultado ) ) {
            return $resultado;
        }

        $errores        = $this->extraer_errores_xml( $resultado['xml'] );
        $codigo_recogida = '';

        if ( empty( $errores ) && $resultado['xml'] ) {
            $recogida = $resultado['xml']->RECOGIDAS->RESPUESTA_RECOGIDA ?? null;
            if ( $recogida ) {
                $codigo_recogida = (string) $recogida->CODIGO_RECOGIDA;
            }
        }

        return array(
            'exito'           => empty( $errores ) && ! empty( $codigo_recogida ),
            'codigo_recogida' => $codigo_recogida,
            'errores'         => $errores,
            'http_code'       => $resultado['code'],
            'raw'             => $resultado['body'],
        );
    }

    /**
     * Consulta el estado de una o varias recogidas
     *
     * @param array|string $codigos_recogida  Código o array de códigos de recogida
     * @return array|WP_Error
     */
    public function ver_recogidas( $codigos_recogida ) {
        if ( ! is_array( $codigos_recogida ) ) {
            $codigos_recogida = array( $codigos_recogida );
        }

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<RECOGIDAS>' . "\n";
        foreach ( $codigos_recogida as $codigo ) {
            $xml .= '  <RECOGIDA CODIGO_RECOGIDA="' . esc_attr( $codigo ) . '"/>' . "\n";
        }
        $xml .= '</RECOGIDAS>';

        $resultado = $this->hacer_request_post( '/recogidas/ver', $xml );

        if ( is_wp_error( $resultado ) ) {
            return $resultado;
        }

        $recogidas = array();
        if ( $resultado['xml'] && isset( $resultado['xml']->RESPUESTA_RECOGIDA ) ) {
            foreach ( $resultado['xml']->RESPUESTA_RECOGIDA as $r ) {
                $recogidas[] = array(
                    'codigo_recogida' => (string) ( $r->CODIGO_RECOGIDA ?? '' ),
                    'estado'          => (string) ( $r->ESTADO_RECOGIDA ?? '' ),
                    'fecha_estado'    => (string) ( $r->FECHA_ESTADO ?? '' ),
                    'rango_horario'   => (string) ( $r->RANGO_HORARIO ?? '' ),
                    'incidencia'      => (string) ( $r->INCIDENCIA ?? '' ),
                    'ampliacion'      => (string) ( $r->AMPLIACION ?? '' ),
                );
            }
        }

        return array(
            'exito'     => ! empty( $recogidas ),
            'recogidas' => $recogidas,
            'errores'   => $this->extraer_errores_xml( $resultado['xml'] ),
            'http_code' => $resultado['code'],
            'raw'       => $resultado['body'],
        );
    }

    /**
     * Asocia guías a una recogida existente
     *
     * @param string $codigo_recogida   Número de recogida Deprisa
     * @param array  $numeros_envio     Array de guías a asociar
     * @return array|WP_Error
     */
    public function asociar_guias_recogida( $codigo_recogida, $numeros_envio ) {
        if ( ! is_array( $numeros_envio ) ) {
            $numeros_envio = array( $numeros_envio );
        }

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<RECOGIDAS>' . "\n";
        foreach ( $numeros_envio as $guia ) {
            $xml .= '  <RECOGIDA>' . "\n";
            $xml .= '    <CODIGO_RECOGIDA>' . esc_xml( $codigo_recogida ) . '</CODIGO_RECOGIDA>' . "\n";
            $xml .= '    <NUMERO_ENVIO>' . esc_xml( $guia ) . '</NUMERO_ENVIO>' . "\n";
            $xml .= '  </RECOGIDA>' . "\n";
        }
        $xml .= '</RECOGIDAS>';

        $resultado = $this->hacer_request_post( '/recogidas/asociar', $xml );

        if ( is_wp_error( $resultado ) ) {
            return $resultado;
        }

        $errores    = $this->extraer_errores_xml( $resultado['xml'] );
        $asociadas  = array();

        if ( $resultado['xml'] && isset( $resultado['xml']->RECOGIDAS->RECOGIDA ) ) {
            foreach ( $resultado['xml']->RECOGIDAS->RECOGIDA as $r ) {
                $asociadas[] = array(
                    'codigo_recogida' => (string) ( $r->CODIGO_RECOGIDA ?? '' ),
                    'numero_envio'    => (string) ( $r->NUMERO_ENVIO ?? '' ),
                );
            }
        }

        return array(
            'exito'     => empty( $errores ),
            'asociadas' => $asociadas,
            'errores'   => $errores,
            'http_code' => $resultado['code'],
            'raw'       => $resultado['body'],
        );
    }

    /**
     * Cancela una recogida
     *
     * @param string $codigo_recogida  Número de recogida
     * @param string $motivo           Motivo de cancelación (obligatorio)
     * @return array|WP_Error
     */
    public function cancelar_recogida( $codigo_recogida, $motivo ) {
        if ( empty( $motivo ) ) {
            return new WP_Error( 'motivo_requerido', 'El motivo de cancelación es obligatorio (error 100).' );
        }

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<RECOGIDAS>' . "\n";
        $xml .= '  <RECOGIDA>' . "\n";
        $xml .= '    <CODIGO_RECOGIDA>' . esc_xml( $codigo_recogida ) . '</CODIGO_RECOGIDA>' . "\n";
        $xml .= '    <MOTIVO>' . esc_xml( $motivo ) . '</MOTIVO>' . "\n";
        $xml .= '  </RECOGIDA>' . "\n";
        $xml .= '</RECOGIDAS>';

        $resultado = $this->hacer_request_post( '/recogidas/cancelar', $xml );

        if ( is_wp_error( $resultado ) ) {
            return $resultado;
        }

        $errores = $this->extraer_errores_xml( $resultado['xml'] );

        return array(
            'exito'     => empty( $errores ),
            'errores'   => $errores,
            'http_code' => $resultado['code'],
            'raw'       => $resultado['body'],
        );
    }

    /**
     * Cancela una admisión / envío ya creado (incluye devoluciones).
     *
     * Endpoint estándar de Deprisa para anular un envío admitido. Si la guía
     * ya fue entregada o está en tránsito avanzado, la API puede rechazar la
     * cancelación; el llamador debe decidir si bloquea o continúa.
     *
     * @param string $numero_envio  Número de guía Deprisa a cancelar.
     * @param string $motivo        Motivo de cancelación (obligatorio).
     * @return array|WP_Error       Array con 'exito', 'errores', 'http_code', 'raw'.
     */
    public function cancelar_envio( $numero_envio, $motivo ) {
        if ( empty( $motivo ) ) {
            return new WP_Error( 'motivo_requerido', 'El motivo de cancelación es obligatorio.' );
        }

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<ADMISIONES>' . "\n";
        $xml .= '  <ADMISION>' . "\n";
        $xml .= '    <NUMERO_ENVIO>' . esc_xml( $numero_envio ) . '</NUMERO_ENVIO>' . "\n";
        $xml .= '    <MOTIVO>' . esc_xml( $motivo ) . '</MOTIVO>' . "\n";
        $xml .= '  </ADMISION>' . "\n";
        $xml .= '</ADMISIONES>';

        $resultado = $this->hacer_request_post( '/admision_envios/cancelar', $xml );

        if ( is_wp_error( $resultado ) ) {
            return $resultado;
        }

        $errores = $this->extraer_errores_xml( $resultado['xml'] );

        return array(
            'exito'     => empty( $errores ),
            'errores'   => $errores,
            'http_code' => $resultado['code'],
            'raw'       => $resultado['body'],
        );
    }

    /**
     * Obtiene el manifiesto PDF en base64 de una o varias recogidas
     *
     * @param array|string $codigos_recogida
     * @return array|WP_Error
     */
    public function obtener_manifiesto( $codigos_recogida ) {
        if ( ! is_array( $codigos_recogida ) ) {
            $codigos_recogida = array( $codigos_recogida );
        }

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<RECOGIDAS>' . "\n";
        foreach ( $codigos_recogida as $codigo ) {
            $xml .= '  <RECOGIDA CODIGO_RECOGIDA="' . esc_attr( $codigo ) . '"/>' . "\n";
        }
        $xml .= '</RECOGIDAS>';

        $resultado = $this->hacer_request_post( '/recogidas/manifiesto', $xml );

        if ( is_wp_error( $resultado ) ) {
            return $resultado;
        }

        $manifiestos = array();
        if ( $resultado['xml'] && isset( $resultado['xml']->RESPUESTA_RECOGIDA ) ) {
            foreach ( $resultado['xml']->RESPUESTA_RECOGIDA as $r ) {
                $manifiestos[] = array(
                    'codigo_recogida' => (string) ( $r->CODIGO_RECOGIDA ?? '' ),
                    'manifiesto_b64'  => (string) ( $r->MANIFIESTO ?? '' ),
                );
            }
        }

        return array(
            'exito'       => ! empty( $manifiestos ),
            'manifiestos' => $manifiestos,
            'errores'     => $this->extraer_errores_xml( $resultado['xml'] ),
            'http_code'   => $resultado['code'],
            'raw'         => $resultado['body'],
        );
    }

    // =========================================================================
    // DEVOLUCIONES (RETORNO)
    // =========================================================================

    /**
     * Genera el payload XML para una guía de devolución/retorno
     * Usa la misma estructura de admisión_envios con los campos invertidos
     * (destinatario ↔ remitente) y el código de servicio apropiado.
     *
     * @param array $datos_pedido_original  Datos del pedido original de WooCommerce
     * @param string $motivo_devolucion     Descripción breve del motivo
     * @return array|WP_Error
     */
    public function build_devolucion_payload( $datos_pedido_original, $motivo_devolucion = '' ) {
        $d = $datos_pedido_original;

        // Para una devolución: el destinatario del envío original se convierte en remitente
        // y el remitente original (vendedor) pasa a ser el destinatario.
        $datos_devolucion = array(
            'codigo_admision'              => 'DEV-' . uniqid(),
            'numero_bultos'                => $d['numero_bultos'] ?? 1,
            'cliente_remitente'            => $d['cliente_destinatario'] ?? $d['cliente_remitente'],
            'centro_remitente'             => $d['centro_destinatario'] ?? '99',
            'nombre_remitente'             => $d['nombre_destinatario'] ?? '',
            'direccion_remitente'          => $d['direccion_destinatario'] ?? '',
            'pais_remitente'               => $d['pais_destinatario'] ?? '057',
            'codigo_postal_remitente'      => $d['codigo_postal_destinatario'] ?? '',
            'poblacion_remitente'          => $d['poblacion_destinatario'] ?? '',
            'tipo_doc_remitente'           => $d['tipo_doc_destinatario'] ?? 'CC',
            'documento_remitente'          => $d['documento_destinatario'] ?? '0',
            'persona_contacto_remitente'   => $d['persona_contacto_destinatario'] ?? '',
            'telefono_remitente'           => $d['telefono_destinatario'] ?? '',
            'email_remitente'              => $d['email_destinatario'] ?? '',
            // Destinatario = vendedor original
            'cliente_destinatario'         => $d['cliente_remitente'] ?? '99999999',
            'centro_destinatario'          => $d['centro_remitente'] ?? '99',
            'nombre_destinatario'          => $d['nombre_remitente'] ?? '',
            'direccion_destinatario'       => $d['direccion_remitente'] ?? '',
            'pais_destinatario'            => $d['pais_remitente'] ?? '057',
            'codigo_postal_destinatario'   => $d['codigo_postal_remitente'] ?? '',
            'poblacion_destinatario'       => $d['poblacion_remitente'] ?? '',
            'tipo_doc_destinatario'        => $d['tipo_doc_remitente'] ?? 'CC',
            'documento_destinatario'       => $d['documento_remitente'] ?? '0',
            'persona_contacto_destinatario'=> $d['persona_contacto_remitente'] ?? '',
            'telefono_destinatario'        => $d['telefono_remitente'] ?? '',
            'email_destinatario'           => $d['email_remitente'] ?? '',
            // Servicio de retorno — código estándar Deprisa para retornos
            'codigo_servicio'              => get_option( 'ltms_deprisa_codigo_servicio_retorno', '3005' ),
            'kilos'                        => $d['kilos'] ?? 1,
            'numero_referencia'            => 'RET-' . ( $d['numero_envio'] ?? '' ),
            'importe_valor_declarado'      => $d['importe_valor_declarado'] ?? '0',
            'tipo_portes'                  => 'P',
            'observaciones1'               => 'DEVOLUCIÓN: ' . $motivo_devolucion,
            'asegurar_envio'               => 'N',
            'tipo_moneda'                  => 'COP',
        );

        return $datos_devolucion;
    }

    /**
     * Crea efectivamente la guía de devolución en Deprisa
     *
     * @param array  $datos_pedido_original  Array con datos del envío original
     * @param string $motivo_devolucion
     * @return array|WP_Error
     */
    public function crear_devolucion( $datos_pedido_original, $motivo_devolucion = '' ) {
        $payload = $this->build_devolucion_payload( $datos_pedido_original, $motivo_devolucion );
        if ( is_wp_error( $payload ) ) {
            return $payload;
        }
        return $this->admitir_envio( $payload, false );
    }

}
