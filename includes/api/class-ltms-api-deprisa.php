<?php
/**
 * LTMS — Integración Deprisa (Latin Logistics)
 * Protocolo: REST/HTTPS · Autenticación: Basic Auth · Formato: XML
 *
 * @package LTMS
 * @since   1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LTMS_Api_Deprisa {

        /* ------------------------------------------------------------------ */
        /* Constantes de entorno                                                */
        /* ------------------------------------------------------------------ */

        const BASE_PROD = 'https://conectados.deprisa.com/conecta2/seam/resource/restv1/auth';
        const BASE_QA   = 'https://conectadoslatincopre.alertran.net/conecta2/seam/resource/restv1/auth';

        /** @var string */
        private string $username;

        /** @var string */
        private string $password;

        /** @var string */
        private string $base_url;

        /** @var bool */
        private bool $sandbox;

        /* ------------------------------------------------------------------ */
        /* Constructor                                                           */
        /* ------------------------------------------------------------------ */

        /**
         * @param string $username  Usuario asignado por Deprisa (ej: WS00011111)
         * @param string $password  Contraseña asignada por Deprisa
         * @param bool   $sandbox   true = QA, false = producción
         */
        public function __construct( string $username, string $password, bool $sandbox = true ) {
                $this->username = $username;
                $this->password = $password;
                $this->sandbox  = $sandbox;
                $this->base_url = $sandbox ? self::BASE_QA : self::BASE_PROD;
        }

        /* ================================================================== */
        /* ENDPOINTS PÚBLICOS                                                   */
        /* ================================================================== */

        /* ------------------------------------------------------------------ */
        /* 1. COTIZACIÓN                                                         */
        /* ------------------------------------------------------------------ */

        /**
         * Cotiza un envío.
         *
         * @param array $p {
         *   @type string $tipoEnvio            N|I|C  (default N)
         *   @type int    $numeroBultos
         *   @type float  $kilos
         *   @type string $clienteRemitente     8 dígitos
         *   @type string $centroRemitente
         *   @type string $paisRemitente        default 057
         *   @type string $poblacionRemitente
         *   @type string $paisDestinatario     default 057
         *   @type string $poblacionDestinatario
         *   @type float  $importeValorDeclarado
         *   @type string $tipoMoneda           COP|USD
         *   @type string $codigoServicio       (opcional)
         *   @type int    $largo
         *   @type int    $ancho
         *   @type int    $alto
         * }
         * @return array { ok: bool, errors: array, cotizaciones: array }
         */
        public function cotizar( array $p ): array {
                $xml = $this->build_cotizacion_xml( $p );
                $doc = $this->post( '/admision_envios/cotizar', $xml );
                return $this->parse_cotizacion( $doc );
        }

        /* ------------------------------------------------------------------ */
        /* 2. ADMISIÓN DE ENVÍOS                                                */
        /* ------------------------------------------------------------------ */

        /**
         * Admite (registra) un envío y obtiene número de guía.
         *
         * @param array $p  Ver build_admision_xml() para campos completos.
         * @return array { ok: bool, errors: array, numeroEnvio: string, ... }
         */
        public function admitir_envio( array $p ): array {
                $xml = $this->build_admision_xml( $p );
                $doc = $this->post( '/admision_envios', $xml );
                return $this->parse_admision( $doc );
        }

        /* ------------------------------------------------------------------ */
        /* 3. ETIQUETAS                                                          */
        /* ------------------------------------------------------------------ */

        /**
         * Obtiene etiqueta(s) PDF en Base64.
         *
         * @param array $items  [ ['numeroEnvio'=>'...', 'tipoImpresora'=>'T'], ... ]
         *                      tipoImpresora: L=láser, T=térmica (default), ZPL
         * @return array  [ ['numeroEnvio'=>'...', 'etiquetaBase64'=>'...'], ... ]
         */
        public function obtener_etiquetas( array $items ): array {
                $xml = $this->build_etiquetas_xml( $items );
                $doc = $this->post( '/admision_envios/etiquetas', $xml );
                return $this->parse_etiquetas( $doc );
        }

        /* ------------------------------------------------------------------ */
        /* 4. TRACKING                                                           */
        /* ------------------------------------------------------------------ */

        /**
         * Consulta el estado de una guía.
         *
         * @param string $numero_envio
         * @return array|null  null si no existe, array con datos si existe
         * @throws LTMS_Deprisa_Exception  HTTP 400 / 500
         */
        public function consultar_tracking( string $numero_envio ): ?array {
                $doc = $this->get( '/tracking/' . rawurlencode( $numero_envio ) );
                return $this->parse_tracking( $doc );
        }

        /* ------------------------------------------------------------------ */
        /* 5. RECOGIDAS — Crear                                                  */
        /* ------------------------------------------------------------------ */

        /**
         * Crea una recogida en estado Pendiente.
         *
         * @param array $p {
         *   @type string $codigoAdmision
         *   @type string $clienteRemitente
         *   @type string $centroRemitente
         *   @type string $nombreRemitente         (escenario 2)
         *   @type string $direccionRemitente      (escenario 2)
         *   @type string $codigoPostalRemitente   (escenario 2)
         *   @type string $poblacionRemitente      (escenario 2)
         *   @type string $paisRemitente
         *   @type string $tipoDocRemitente
         *   @type string $documentoIdentidadRemitente
         *   @type string $personaContactoRemitente
         *   @type string $telefonoContactoRemitente
         *   @type string $fechaRecogida           DD/MM/YYYY
         *   @type string $rangoHorario            hh:mm-hh:mm
         *   @type string $codigoServicio
         *   @type string $embalaje                C|S
         *   @type string $observaciones
         *   @type int    $numeroBultos
         *   @type float  $kilos
         * }
         * @return array { ok: bool, errors: array, codigoRecogida: string, ... }
         */
        public function crear_recogida( array $p ): array {
                $xml = $this->build_recogida_xml( $p );
                $doc = $this->post( '/recogidas/crear', $xml );
                return $this->parse_recogida( $doc );
        }

        /* ------------------------------------------------------------------ */
        /* 6. RECOGIDAS — Ver estado                                             */
        /* ------------------------------------------------------------------ */

        /**
         * Consulta el estado de una o varias recogidas.
         *
         * @param string[] $codigos_recogida
         * @return array  [ ['codigoRecogida'=>..., 'estadoRecogida'=>..., ...], ... ]
         */
        public function ver_recogidas( array $codigos_recogida ): array {
                $xml = $this->build_ver_recogidas_xml( $codigos_recogida );
                $doc = $this->post( '/recogidas/ver', $xml );
                return $this->parse_ver_recogidas( $doc );
        }

        /* ------------------------------------------------------------------ */
        /* 7. RECOGIDAS — Asociar guías                                          */
        /* ------------------------------------------------------------------ */

        /**
         * Asocia guías a una recogida existente.
         *
         * @param array $asociaciones  [ ['codigoRecogida'=>..., 'numeroEnvio'=>...], ... ]
         * @return array { ok: bool, errors: array, recogidas: array }
         */
        public function asociar_guias( array $asociaciones ): array {
                $xml = $this->build_asociar_xml( $asociaciones );
                $doc = $this->post( '/recogidas/asociar', $xml );
                return $this->parse_asociacion( $doc );
        }

        /* ------------------------------------------------------------------ */
        /* 8. RECOGIDAS — Cancelar                                               */
        /* ------------------------------------------------------------------ */

        /**
         * Cancela una o varias recogidas.
         *
         * @param array $items  [ ['codigoRecogida'=>..., 'motivo'=>...], ... ]
         * @return array { ok: bool, errors: array, canceladas: array }
         */
        public function cancelar_recogidas( array $items ): array {
                $xml = $this->build_cancelar_xml( $items );
                $doc = $this->post( '/recogidas/cancelar', $xml );
                return $this->parse_cancelacion( $doc );
        }

        /* ------------------------------------------------------------------ */
        /* 9. RECOGIDAS — Manifiesto                                             */
        /* ------------------------------------------------------------------ */

        /**
         * Obtiene el manifiesto de recogidas en PDF Base64.
         *
         * @param string[] $codigos_recogida
         * @return array  [ ['codigoRecogida'=>..., 'manifiestoBase64'=>...], ... ]
         */
        public function obtener_manifiesto( array $codigos_recogida ): array {
                $xml = $this->build_manifiesto_xml( $codigos_recogida );
                $doc = $this->post( '/recogidas/manifiesto', $xml );
                return $this->parse_manifiesto( $doc );
        }

        /**
         * AUDIT-SHIPPING-ENGINE #6 FIX: cancelar_envio method — antes solo
         * existía en LTMS_Deprisa_API (snake_case) pero no en LTMS_Api_Deprisa
         * (camelCase). Las devoluciones llamaban $api->cancelar_envio() sobre
         * una instancia de LTMS_Deprisa_API que no cargaba correctamente →
         * Fatal Error en cada cancelación de devolución.
         *
         * @param string $numero_envio Número de guía a cancelar.
         * @param string $motivo       Motivo de cancelación.
         * @return array { exito: bool, errores: array }
         */
        public function cancelar_envio( string $numero_envio, string $motivo ): array {
                if ( empty( $motivo ) ) {
                        return [ 'exito' => false, 'errores' => [ [ 'descripcion' => 'Motivo requerido' ] ] ];
                }

                $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
                $xml .= '<ADMISIONES>' . "\n";
                $xml .= '  <ADMISION>' . "\n";
                $xml .= '    <NUMERO_ENVIO>' . htmlspecialchars( $numero_envio, ENT_XML1 ) . '</NUMERO_ENVIO>' . "\n";
                $xml .= '    <MOTIVO>' . htmlspecialchars( $motivo, ENT_XML1 ) . '</MOTIVO>' . "\n";
                $xml .= '  </ADMISION>' . "\n";
                $xml .= '</ADMISIONES>';

                try {
                        $doc = $this->post( '/admision_envios/cancelar', $xml );
                        $errors = $this->extract_errors( $doc );
                        return [
                                'exito'   => empty( $errors ),
                                'errores' => $errors,
                        ];
                } catch ( \Throwable $e ) {
                        return [
                                'exito'   => false,
                                'errores' => [ [ 'descripcion' => $e->getMessage() ] ],
                        ];
                }
        }

        /**
         * AUDIT-SHIPPING-ENGINE #6 FIX: build_devolucion_payload — alias para
         * compatibilidad con LTMS_Deprisa_API que tiene este método.
         */
        public function build_devolucion_payload( array $datos_pedido_original, string $motivo_devolucion = '' ): array {
                // Invertir remitente/destinatario para devolución.
                $payload = $datos_pedido_original;
                $temp = $payload['remitente'] ?? [];
                $payload['remitente'] = $payload['destinatario'] ?? [];
                $payload['destinatario'] = $temp;
                $payload['motivo_devolucion'] = $motivo_devolucion;
                $payload['es_devolucion'] = true;
                return $payload;
        }

        /* ================================================================== */
        /* CONSTRUCTORES DE XML                                                 */
        /* ================================================================== */

        private function xml_header(): string {
                return '<?xml version="1.0" encoding="UTF-8"?>';
        }

        private function tag( string $name, $value ): string {
                if ( $value === null || $value === '' ) return "<{$name}/>";
                return "<{$name}>" . esc_xml( (string) $value ) . "</{$name}>";
        }

        private function build_admision_xml( array $p ): string {
                $t = [ $this->xml_header(), '<ADMISIONES>', '  <ADMISION>' ];

                $campos = [
                        'GRABAR_ENVIO'                       => $p['grabarEnvio']                       ?? 'S',
                        'CODIGO_ADMISION'                    => $p['codigoAdmision']                    ?? '',
                        'NUMERO_BULTOS'                      => $p['numeroBultos']                      ?? 1,
                        'FECHA_HORA_ADMISION'                => $p['fechaHoraAdmision']                 ?? '',
                        'CLIENTE_REMITENTE'                  => $p['clienteRemitente']                  ?? '',
                        'CENTRO_REMITENTE'                   => $p['centroRemitente']                   ?? '',
                        'NOMBRE_REMITENTE'                   => $p['nombreRemitente']                   ?? '',
                        'DIRECCION_REMITENTE'                => $p['direccionRemitente']                ?? '',
                        'PAIS_REMITENTE'                     => $p['paisRemitente']                     ?? '',
                        'CODIGO_POSTAL_REMITENTE'            => $p['codigoPostalRemitente']             ?? '',
                        'POBLACION_REMITENTE'                => $p['poblacionRemitente']                ?? '',
                        'TIPO_DOC_REMITENTE'                 => $p['tipoDocRemitente']                  ?? '',
                        'DOCUMENTO_IDENTIDAD_REMITENTE'      => $p['documentoIdentidadRemitente']       ?? '',
                        'PERSONA_CONTACTO_REMITENTE'         => $p['personaContactoRemitente']          ?? '',
                        'TELEFONO_CONTACTO_REMITENTE'        => $p['telefonoContactoRemitente']         ?? '',
                        'DEPARTAMENTO_REMITENTE'             => $p['departamentoRemitente']             ?? '',
                        'EMAIL_REMITENTE'                    => $p['emailRemitente']                    ?? '',
                        'CLIENTE_DESTINATARIO'               => $p['clienteDestinatario']               ?? '99999999',
                        'CENTRO_DESTINATARIO'                => $p['centroDestinatario']                ?? '99',
                        'NOMBRE_DESTINATARIO'                => $p['nombreDestinatario']                ?? '',
                        'DIRECCION_DESTINATARIO'             => $p['direccionDestinatario']             ?? '',
                        'PAIS_DESTINATARIO'                  => $p['paisDestinatario']                  ?? '057',
                        'CODIGO_POSTAL_DESTINATARIO'         => $p['codigoPostalDestinatario']          ?? '',
                        'POBLACION_DESTINATARIO'             => $p['poblacionDestinatario']             ?? '',
                        'TIPO_DOC_DESTINATARIO'              => $p['tipoDocDestinatario']               ?? '',
                        'DOCUMENTO_IDENTIDAD_DESTINATARIO'   => $p['documentoIdentidadDestinatario']    ?? '',
                        'PERSONA_CONTACTO_DESTINATARIO'      => $p['personaContactoDestinatario']       ?? '',
                        'TELEFONO_CONTACTO_DESTINATARIO'     => $p['telefonoContactoDestinatario']      ?? '',
                        'DEPARTAMENTO_DESTINATARIO'          => $p['departamentoDestinatario']          ?? '',
                        'EMAIL_DESTINATARIO'                 => $p['emailDestinatario']                 ?? '',
                        'INCOTERM'                           => $p['incoterm']                          ?? '',
                        'RAZON_EXPORTAR'                     => $p['razonExportar']                     ?? '',
                        'EMBALAJE'                           => $p['embalaje']                          ?? '',
                        'CODIGO_SERVICIO'                    => $p['codigoServicio']                    ?? '',
                        'KILOS'                              => $p['kilos']                             ?? '',
                        'VOLUMEN'                            => $p['volumen']                           ?? '',
                        'LARGO'                              => $p['largo']                             ?? '',
                        'ANCHO'                              => $p['ancho']                             ?? '',
                        'ALTO'                               => $p['alto']                              ?? '',
                        'NUMERO_REFERENCIA'                  => $p['numeroReferencia']                  ?? '',
                        'IMPORTE_REEMBOLSO'                  => $p['importeReembolso']                  ?? '',
                        'IMPORTE_VALOR_DECLARADO'            => $p['importeValorDeclarado']             ?? '',
                        'TIPO_PORTES'                        => $p['tipoPortes']                        ?? 'P',
                        'TIPO_PORTE_REEMBOLSOS'              => $p['tipoPorteReembolsos']               ?? '',
                        'OBSERVACIONES1'                     => $p['observaciones1']                    ?? '',
                        'OBSERVACIONES2'                     => $p['observaciones2']                    ?? '',
                        'TIPO_MERCANCIA'                     => $p['tipoMercancia']                     ?? '',
                        'ASEGURAR_ENVIO'                     => $p['asegurarEnvio']                     ?? 'N',
                        'TIPO_MONEDA'                        => $p['tipoMoneda']                        ?? 'COP',
                ];

                foreach ( $campos as $k => $v ) {
                        $t[] = '    ' . $this->tag( $k, $v );
                }

                $t[] = '  </ADMISION>';
                $t[] = '</ADMISIONES>';
                return implode( "\n", $t );
        }

        private function build_etiquetas_xml( array $items ): string {
                $t = [ $this->xml_header(), '<ETIQUETAS>' ];
                foreach ( $items as $i ) {
                        $t[] = '  <ETIQUETA>';
                        $t[] = '    ' . $this->tag( 'NUMERO_ENVIO', $i['numeroEnvio'] ?? '' );
                        $t[] = '    ' . $this->tag( 'TIPO_IMPRESORA', $i['tipoImpresora'] ?? 'T' );
                        $t[] = '  </ETIQUETA>';
                }
                $t[] = '</ETIQUETAS>';
                return implode( "\n", $t );
        }

        private function build_cotizacion_xml( array $p ): string {
                $t = [ $this->xml_header(), '<COTIZACIONES>', '  <ADMISION>' ];

                $campos = [
                        'TIPO_ENVIO'              => $p['tipoEnvio']              ?? 'N',
                        'NUMERO_BULTOS'           => $p['numeroBultos']           ?? 1,
                        'KILOS'                   => $p['kilos']                  ?? '',
                        'CLIENTE_REMITENTE'       => $p['clienteRemitente']       ?? '',
                        'CENTRO_REMITENTE'        => $p['centroRemitente']        ?? '',
                        'PAIS_REMITENTE'          => $p['paisRemitente']          ?? '057',
                        'POBLACION_REMITENTE'     => $p['poblacionRemitente']     ?? '',
                        'PAIS_DESTINATARIO'       => $p['paisDestinatario']       ?? '057',
                        'POBLACION_DESTINATARIO'  => $p['poblacionDestinatario']  ?? '',
                        'INCOTERM'                => $p['incoterm']               ?? '',
                        'CODIGO_SERVICIO'         => $p['codigoServicio']         ?? '',
                        'LARGO'                   => $p['largo']                  ?? '',
                        'ANCHO'                   => $p['ancho']                  ?? '',
                        'ALTO'                    => $p['alto']                   ?? '',
                        'TIPO_MERCANCIA'          => $p['tipoMercancia']          ?? '',
                        'CONTENEDOR_MERCANCIA'    => $p['contenedorMercancia']    ?? '',
                        'IMPORTE_VALOR_DECLARADO' => $p['importeValorDeclarado']  ?? '',
                        'TIPO_MONEDA'             => $p['tipoMoneda']             ?? 'COP',
                ];

                foreach ( $campos as $k => $v ) {
                        $t[] = '    ' . $this->tag( $k, $v );
                }

                $t[] = '  </ADMISION>';
                $t[] = '</COTIZACIONES>';
                return implode( "\n", $t );
        }

        private function build_recogida_xml( array $p ): string {
                $t = [ $this->xml_header(), '<RECOGIDAS>', '  <RECOGIDA>' ];

                $campos = [
                        'CODIGO_ADMISION'                => $p['codigoAdmision']               ?? '',
                        'CLIENTE_REMITENTE'              => $p['clienteRemitente']              ?? '',
                        'CENTRO_REMITENTE'               => $p['centroRemitente']               ?? '',
                        'NOMBRE_REMITENTE'               => $p['nombreRemitente']               ?? '',
                        'DIRECCION_REMITENTE'            => $p['direccionRemitente']            ?? '',
                        'PAIS_REMITENTE'                 => $p['paisRemitente']                 ?? '',
                        'CODIGO_POSTAL_REMITENTE'        => $p['codigoPostalRemitente']         ?? '',
                        'POBLACION_REMITENTE'            => $p['poblacionRemitente']            ?? '',
                        'TIPO_DOC_REMITENTE'             => $p['tipoDocRemitente']              ?? '',
                        'DOCUMENTO_IDENTIDAD_REMITENTE'  => $p['documentoIdentidadRemitente']  ?? '',
                        'PERSONA_CONTACTO_REMITENTE'     => $p['personaContactoRemitente']     ?? '',
                        'TELEFONO_CONTACTO_REMITENTE'    => $p['telefonoContactoRemitente']    ?? '',
                        'FECHA_RECOGIDA'                 => $p['fechaRecogida']                ?? '',
                        'RANGO_HORARIO'                  => $p['rangoHorario']                 ?? '',
                        'CODIGO_SERVICIO'                => $p['codigoServicio']               ?? '',
                        'EMBALAJE'                       => $p['embalaje']                     ?? '',
                        'OBSERVACIONES'                  => $p['observaciones']                ?? '',
                        'NUMERO_BULTOS'                  => $p['numeroBultos']                 ?? '',
                        'KILOS'                          => $p['kilos']                        ?? '',
                ];

                foreach ( $campos as $k => $v ) {
                        $t[] = '    ' . $this->tag( $k, $v );
                }

                $t[] = '  </RECOGIDA>';
                $t[] = '</RECOGIDAS>';
                return implode( "\n", $t );
        }

        private function build_ver_recogidas_xml( array $codigos ): string {
                $t = [ $this->xml_header(), '<RECOGIDAS>' ];
                foreach ( $codigos as $c ) {
                        $t[] = '  <RECOGIDA CODIGO_RECOGIDA="' . esc_attr( $c ) . '"/>';
                }
                $t[] = '</RECOGIDAS>';
                return implode( "\n", $t );
        }

        private function build_asociar_xml( array $asociaciones ): string {
                $t = [ $this->xml_header(), '<RECOGIDAS>' ];
                foreach ( $asociaciones as $a ) {
                        $t[] = '  <RECOGIDA>';
                        $t[] = '    ' . $this->tag( 'CODIGO_RECOGIDA', $a['codigoRecogida'] ?? '' );
                        $t[] = '    ' . $this->tag( 'NUMERO_ENVIO',    $a['numeroEnvio']    ?? '' );
                        $t[] = '  </RECOGIDA>';
                }
                $t[] = '</RECOGIDAS>';
                return implode( "\n", $t );
        }

        private function build_cancelar_xml( array $items ): string {
                $t = [ $this->xml_header(), '<RECOGIDAS>' ];
                foreach ( $items as $i ) {
                        $t[] = '  <RECOGIDA>';
                        $t[] = '    ' . $this->tag( 'CODIGO_RECOGIDA', $i['codigoRecogida'] ?? '' );
                        $t[] = '    ' . $this->tag( 'MOTIVO',          $i['motivo']         ?? '' );
                        $t[] = '  </RECOGIDA>';
                }
                $t[] = '</RECOGIDAS>';
                return implode( "\n", $t );
        }

        private function build_manifiesto_xml( array $codigos ): string {
                $t = [ $this->xml_header(), '<RECOGIDAS>' ];
                foreach ( $codigos as $c ) {
                        $t[] = '  <RECOGIDA CODIGO_RECOGIDA="' . esc_attr( $c ) . '"/>';
                }
                $t[] = '</RECOGIDAS>';
                return implode( "\n", $t );
        }

        /* ================================================================== */
        /* PARSERS DE RESPUESTA                                                 */
        /* ================================================================== */

        /**
         * Parsea un string XML y devuelve SimpleXMLElement.
         *
         * @throws LTMS_Deprisa_Exception
         */
        private function parse_xml( string $body ): \SimpleXMLElement {
                libxml_use_internal_errors( true );
                $xml = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOENT );
                if ( false === $xml ) {
                        $errors = libxml_get_errors();
                        libxml_clear_errors();
                        throw new LTMS_Deprisa_Exception(
                                'XML de respuesta inválido: ' . ( $errors[0]->message ?? 'error desconocido' ),
                                0,
                                $body
                        );
                }
                return $xml;
        }

        private function extract_errors( \SimpleXMLElement $xml ): array {
                $errors = [];
                foreach ( $xml->xpath( '//ERROR' ) as $e ) {
                        $errors[] = [
                                'codigo'      => (string) ( $e['ERROR_CODIGO'] ?? $e['CODIGO'] ?? '' ),
                                'descripcion' => (string) ( $e['ERROR_DESCRIPCION'] ?? '' ),
                                'valor'       => (string) ( $e['VALOR_ERRONEO'] ?? '' ),
                        ];
                }
                return $errors;
        }

        private function parse_admision( \SimpleXMLElement $xml ): array {
                $errors   = $this->extract_errors( $xml );
                $admision = $xml->xpath( '//RESPUESTA_ADMISION' );

                if ( empty( $admision ) ) {
                        return [ 'ok' => false, 'errors' => $errors ];
                }

                $a = $admision[0];
                return [
                        'ok'                   => true,
                        'errors'               => $errors,
                        'codigoAdmision'       => (string) ( $a['CODIGO_ADMISION']         ?? '' ),
                        'numeroEnvio'          => (string) ( $a->NUMERO_ENVIO              ?? '' ),
                        'fechaObjetivo'        => (string) ( $a->FECHA_OBJETIVO            ?? '' ),
                        'delegacionDestino'    => (string) ( $a->NOMBRE_DELEGACION_DESTINO ?? '' ),
                        'direccionDestino'     => (string) ( $a->DIRECCION_DESTINO         ?? '' ),
                        'codigoEncaminamiento' => (string) ( $a->CODIGO_ENCAMINAMIENTO     ?? '' ),
                        'abreviaturaServicio'  => (string) ( $a->ABREVIATURA_SERVICIO      ?? '' ),
                        'poblacionDestino'     => (string) ( $a->POBLACION_DESTINO         ?? '' ),
                ];
        }

        private function parse_etiquetas( \SimpleXMLElement $xml ): array {
                $result = [];
                foreach ( $xml->xpath( '//RESPUESTA_ETIQUETAS' ) as $r ) {
                        $result[] = [
                                'numeroEnvio'    => (string) ( $r['NUMERO_ENVIO'] ?? '' ),
                                'etiquetaBase64' => (string) ( $r->ETIQUETA       ?? '' ),
                        ];
                }
                return $result;
        }

        private function parse_tracking( \SimpleXMLElement $xml ): ?array {
                $envio = $xml->xpath( '//ENVIO' );
                if ( empty( $envio ) ) return null;

                $e = $envio[0];

                $estados = [];
                foreach ( $e->xpath( 'ESTADOS/ESTADO' ) as $s ) {
                        $estados[] = [
                                'tipoEventoCodigo' => (string) ( $s->TIPO_EVENTO_CODIGO ?? '' ),
                                'descripcion'      => (string) ( $s->DESCRIPCION         ?? '' ),
                                'fechaEvento'      => (string) ( $s->FECHA_EVENTO         ?? '' ),
                                'delegacionNombre' => (string) ( $s->DELEGACION_NOMBRE    ?? '' ),
                        ];
                }

                $incidencias = [];
                foreach ( $e->xpath( 'INCIDENCIAS/INCIDENCIA' ) as $i ) {
                        $incidencias[] = [
                                'id'          => (string) ( $i->ID          ?? '' ),
                                'descripcion' => (string) ( $i->DESCRIPCION ?? '' ),
                                'fechaAlta'   => (string) ( $i->FECHA_ALTA  ?? '' ),
                                'cerrada'     => (string) ( $i->CERRADA      ?? '' ),
                        ];
                }

                return [
                        'numeroEnvio'           => (string) ( $e->NUMERO_ENVIO           ?? '' ),
                        'numeroReferencia'      => (string) ( $e->NUMERO_REFERENCIA       ?? '' ),
                        'kilos'                 => (string) ( $e->KILOS                   ?? '' ),
                        'flete'                 => (string) ( $e->FLETE                   ?? '' ),
                        'fechaHoraAdmision'     => (string) ( $e->FECHA_HORA_ADMISION     ?? '' ),
                        'fechaHoraEntrega'      => (string) ( $e->FECHA_HORA_ENTREGA      ?? '' ),
                        'numeroPiezas'          => (string) ( $e->NUMERO_TOTAL_PIEZAS     ?? '' ),
                        'codigoServicio'        => (string) ( $e->CODIGO_SERVICIO          ?? '' ),
                        'descripcionServicio'   => (string) ( $e->DESCRIPCION_SERVICIO     ?? '' ),
                        'nombreDestinatario'    => (string) ( $e->NOMBRE_DESTINATARIO      ?? '' ),
                        'direccionDestinatario' => (string) ( $e->DIRECCION_DESTINATARIO   ?? '' ),
                        'poblacionDestinatario' => (string) ( $e->POBLACION_DESTINATARIO   ?? '' ),
                        'estados'               => $estados,
                        'incidencias'           => $incidencias,
                ];
        }

        private function parse_cotizacion( \SimpleXMLElement $xml ): array {
                $errors      = $this->extract_errors( $xml );
                $cotizaciones = [];

                foreach ( $xml->xpath( '//RESPUESTA_COTIZACION' ) as $r ) {
                        $conceptos = [];
                        foreach ( $r->xpath( 'CONCEPTOS' ) as $c ) {
                                $conceptos[] = [
                                        'codigo'      => (string) ( $c->CONCEPTO_CODIGO ?? '' ),
                                        'descripcion' => (string) ( $c->CONCEPTO_DESC   ?? '' ),
                                        'valor'       => (float)  ( $c->CONCEPTO_VALOR  ?? 0 ),
                                ];
                        }
                        $cotizaciones[] = [
                                'productoCode'        => (string) ( $r->PRODUCTO_CODIGO       ?? '' ),
                                'productoDescripcion' => (string) ( $r->PRODUCTO_DESCRIPCION  ?? '' ),
                                'productoPermitido'   => (string) ( $r->PRODUCTO_PERMITIDO    ?? '' ),
                                'tiempoEntrega'       => (string) ( $r->TIEMPO_ENTREGA        ?? '' ),
                                'total'               => (float)  ( $r->TOTAL                  ?? 0 ),
                                'conceptos'           => $conceptos,
                        ];
                }

                return [
                        'ok'           => empty( $errors ),
                        'errors'       => $errors,
                        'cotizaciones' => $cotizaciones,
                ];
        }

        private function parse_recogida( \SimpleXMLElement $xml ): array {
                $errors   = $this->extract_errors( $xml );
                $recogida = $xml->xpath( '//RESPUESTA_RECOGIDA' );

                if ( empty( $recogida ) ) {
                        return [ 'ok' => false, 'errors' => $errors ];
                }

                $r = $recogida[0];
                return [
                        'ok'             => true,
                        'errors'         => $errors,
                        'codigoAdmision' => (string) ( $r['CODIGO_ADMISION'] ?? $r->CODIGO_ADMISION ?? '' ),
                        'codigoRecogida' => (string) ( $r->CODIGO_RECOGIDA   ?? '' ),
                        'estadoRecogida' => (string) ( $r->ESTADO_RECOGIDA   ?? '' ),
                        'fechaRecogida'  => (string) ( $r->FECHA_RECOGIDA    ?? '' ),
                        'rangoHorario'   => (string) ( $r->RANGO_HORARIO     ?? '' ),
                ];
        }

        private function parse_ver_recogidas( \SimpleXMLElement $xml ): array {
                $result = [];
                foreach ( $xml->xpath( '//RESPUESTA_RECOGIDA' ) as $r ) {
                        $result[] = [
                                'codigoRecogida' => (string) ( $r->CODIGO_RECOGIDA ?? '' ),
                                'estadoRecogida' => (string) ( $r->ESTADO_RECOGIDA ?? '' ),
                                'fechaEstado'    => (string) ( $r->FECHA_ESTADO    ?? '' ),
                                'ampliacion'     => (string) ( $r->AMPLIACION      ?? '' ),
                                'incidencia'     => (string) ( $r->INCIDENCIA      ?? '' ),
                        ];
                }
                return $result;
        }

        private function parse_asociacion( \SimpleXMLElement $xml ): array {
                $errors    = $this->extract_errors( $xml );
                $recogidas = [];
                foreach ( $xml->xpath( '//RECOGIDAS/RECOGIDA' ) as $r ) {
                        $recogidas[] = [
                                'codigoRecogida' => (string) ( $r->CODIGO_RECOGIDA ?? '' ),
                                'numeroEnvio'    => (string) ( $r->NUMERO_ENVIO    ?? '' ),
                        ];
                }
                return [ 'ok' => empty( $errors ), 'errors' => $errors, 'recogidas' => $recogidas ];
        }

        private function parse_cancelacion( \SimpleXMLElement $xml ): array {
                $errors    = $this->extract_errors( $xml );
                $canceladas = [];
                foreach ( $xml->xpath( '//RECOGIDAS/RECOGIDA' ) as $r ) {
                        $canceladas[] = [ 'codigoRecogida' => (string) ( $r->CODIGO_RECOGIDA ?? '' ) ];
                }
                return [ 'ok' => empty( $errors ), 'errors' => $errors, 'canceladas' => $canceladas ];
        }

        private function parse_manifiesto( \SimpleXMLElement $xml ): array {
                $result = [];
                foreach ( $xml->xpath( '//RESPUESTA_RECOGIDA' ) as $r ) {
                        $result[] = [
                                'codigoRecogida'   => (string) ( $r->CODIGO_RECOGIDA   ?? '' ),
                                'manifiestoBase64' => (string) ( $r->MANIFIESTO        ?? '' ),
                        ];
                }
                return $result;
        }

        /* ================================================================== */
        /* HTTP HELPERS                                                         */
        /* ================================================================== */

        private function auth_header(): string {
                return 'Basic ' . base64_encode( "{$this->username}:{$this->password}" );
        }

        /**
         * POST con body XML.
         *
         * @throws LTMS_Deprisa_Exception
         */
        private function post( string $path, string $xml_body ): \SimpleXMLElement {
                $url      = $this->base_url . $path;
                try {
                        $response = wp_remote_post( $url, [
                                'timeout'    => 30,
                                'headers'    => [
                                        'Authorization' => $this->auth_header(),
                                        'Content-Type'  => 'application/xml; charset=UTF-8',
                                        'Accept'        => 'application/xml',
                                ],
                                'body'       => $xml_body,
                                // API-BUG-5 FIX: SSL verification must ALWAYS be true. The
                                // previous `! $this->sandbox` was inverted-by-default (sandbox
                                // = true in constructor) → SSL was disabled for the QA endpoint
                                // which has a valid public cert. No MITM risk mitigation reason.
                                'sslverify'  => ! ( defined( 'LTMS_DISABLE_SSL_VERIFY' ) && LTMS_DISABLE_SSL_VERIFY ),
                        ] );
                } catch ( \Throwable $e ) {
                        // API-BUG-10 FIX: wrap HTTP calls in try/catch + structured logging.
                        if ( class_exists( 'LTMS_Core_Logger' ) ) {
                                LTMS_Core_Logger::error(
                                        'DEPRISA_HTTP_ERROR',
                                        sprintf( 'POST %s falló: %s', $path, $e->getMessage() ),
                                        [ 'provider' => 'deprisa', 'endpoint' => $path ]
                                );
                        }
                        throw new LTMS_Deprisa_Exception( 'Error en POST ' . $path . ': ' . $e->getMessage(), 0 );
                }

                return $this->handle_response( $response, $path );
        }

        /**
         * GET.
         *
         * @throws LTMS_Deprisa_Exception
         */
        private function get( string $path ): \SimpleXMLElement {
                $url      = $this->base_url . $path;
                try {
                        $response = wp_remote_get( $url, [
                                'timeout'   => 30,
                                'headers'   => [
                                        'Authorization' => $this->auth_header(),
                                        'Accept'        => 'application/xml',
                                ],
                                // API-BUG-5 FIX: SSL verification must ALWAYS be true (see post()).
                                'sslverify' => ! ( defined( 'LTMS_DISABLE_SSL_VERIFY' ) && LTMS_DISABLE_SSL_VERIFY ),
                        ] );
                } catch ( \Throwable $e ) {
                        // API-BUG-10 FIX: wrap HTTP calls in try/catch + structured logging.
                        if ( class_exists( 'LTMS_Core_Logger' ) ) {
                                LTMS_Core_Logger::error(
                                        'DEPRISA_HTTP_ERROR',
                                        sprintf( 'GET %s falló: %s', $path, $e->getMessage() ),
                                        [ 'provider' => 'deprisa', 'endpoint' => $path ]
                                );
                        }
                        throw new LTMS_Deprisa_Exception( 'Error en GET ' . $path . ': ' . $e->getMessage(), 0 );
                }

                return $this->handle_response( $response, $path );
        }

        /**
         * Procesa la respuesta WP HTTP.
         *
         * @throws LTMS_Deprisa_Exception
         */
        private function handle_response( $response, string $path ): \SimpleXMLElement {
                if ( is_wp_error( $response ) ) {
                        // API-BUG-10 FIX: log structured error before throwing.
                        if ( class_exists( 'LTMS_Core_Logger' ) ) {
                                LTMS_Core_Logger::error(
                                        'DEPRISA_NETWORK_ERROR',
                                        sprintf( 'Error de red al llamar %s: %s', $path, $response->get_error_message() ),
                                        [ 'provider' => 'deprisa', 'endpoint' => $path ]
                                );
                        }
                        throw new LTMS_Deprisa_Exception(
                                'Error de red al llamar ' . $path . ': ' . $response->get_error_message(),
                                0
                        );
                }

                $code = wp_remote_retrieve_response_code( $response );
                $body = wp_remote_retrieve_body( $response );

                if ( $code === 404 ) throw new LTMS_Deprisa_Exception( 'Guía no encontrada', 404, $body );
                if ( $code === 400 ) throw new LTMS_Deprisa_Exception( 'Petición incorrecta', 400, $body );
                if ( $code === 401 ) throw new LTMS_Deprisa_Exception( 'Credenciales inválidas', 401, $body );
                if ( $code >= 500  ) throw new LTMS_Deprisa_Exception( "Error interno Deprisa ({$code})", $code, $body );

                if ( empty( $body ) ) {
                        throw new LTMS_Deprisa_Exception( "Respuesta vacía de {$path}", $code );
                }

                return $this->parse_xml( $body );
        }

        /* ================================================================== */
        /* UTILIDADES ESTÁTICAS                                                 */
        /* ================================================================== */

        /**
         * Genera un código de admisión único.
         */
        public static function generar_codigo_admision( string $prefijo = 'LTMS' ): string {
                return $prefijo . '-' . time() . '-' . wp_rand( 1000, 9999 );
        }

        /**
         * Formatea una fecha PHP al formato DD/MM/YYYY requerido por Deprisa.
         */
        public static function formatear_fecha( \DateTime $fecha = null ): string {
                $fecha = $fecha ?? new \DateTime();
                return $fecha->format( 'd/m/Y' );
        }
}

/* ================================================================== */
/* Excepción específica Deprisa                                        */
/* ================================================================== */

class LTMS_Deprisa_Exception extends \RuntimeException {

        /** @var string */
        public string $raw_response;

        public function __construct( string $message, int $code = 0, string $raw_response = '' ) {
                parent::__construct( $message, $code );
                $this->raw_response = $raw_response;
        }
}
