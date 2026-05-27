<?php
/**
 * LTMS — Lógica de negocio para envíos Deprisa
 * Orquesta el flujo completo: cotizar → admitir → etiqueta → recogida → asociar
 *
 * @package LTMS
 * @since   1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LTMS_Deprisa_Shipping {

	/** @var LTMS_Api_Deprisa */
	private LTMS_Api_Deprisa $api;

	public function __construct() {
		$username = get_option( 'ltms_deprisa_username', '' );
		$password = get_option( 'ltms_deprisa_password', '' );
		$sandbox  = (bool) get_option( 'ltms_deprisa_sandbox', true );

		$this->api = new LTMS_Api_Deprisa( $username, $password, $sandbox );
	}

	/* ================================================================== */
	/* FLUJO PRINCIPAL: crear guía para un pedido WooCommerce              */
	/* ================================================================== */

	/**
	 * Crea una guía Deprisa a partir de un pedido WooCommerce y guarda
	 * los datos en los meta del pedido.
	 *
	 * @param int   $order_id   ID del pedido WooCommerce
	 * @param array $opciones {
	 *   @type string $codigoServicio   default 3005
	 *   @type float  $kilos
	 *   @type bool   $contraentrega
	 *   @type float  $importeReembolso
	 *   @type string $observaciones
	 * }
	 * @return array { ok: bool, guia: string|null, error: string|null }
	 */
	public function crear_guia_para_pedido( int $order_id, array $opciones = [] ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return [ 'ok' => false, 'guia' => null, 'error' => 'Pedido no encontrado' ];
		}

		// Verificar credenciales configuradas
		if ( empty( get_option( 'ltms_deprisa_username' ) ) ) {
			return [ 'ok' => false, 'guia' => null, 'error' => 'Credenciales Deprisa no configuradas' ];
		}

		try {
			$params = $this->build_admision_params( $order, $opciones );
			$result = $this->api->admitir_envio( $params );

			if ( ! $result['ok'] ) {
				$error_msg = $this->format_errors( $result['errors'] );
				$order->add_order_note( '[Deprisa] Error al crear guía: ' . $error_msg );
				return [ 'ok' => false, 'guia' => null, 'error' => $error_msg ];
			}

			// Guardar en meta del pedido
			$order->update_meta_data( '_deprisa_guia',              $result['numeroEnvio'] );
			$order->update_meta_data( '_deprisa_fecha_objetivo',    $result['fechaObjetivo'] );
			$order->update_meta_data( '_deprisa_delegacion_destino',$result['delegacionDestino'] );
			$order->update_meta_data( '_deprisa_created_at',        current_time( 'mysql' ) );
			$order->save();

			$order->add_order_note(
				sprintf( '[Deprisa] Guía creada: %s | Entrega estimada: %s | Delegación: %s',
					$result['numeroEnvio'],
					$result['fechaObjetivo'],
					$result['delegacionDestino']
				)
			);

			// Auto-descargar etiqueta
			$etiquetas = $this->api->obtener_etiquetas( [
				[ 'numeroEnvio' => $result['numeroEnvio'], 'tipoImpresora' => 'T' ],
			] );

			if ( ! empty( $etiquetas[0]['etiquetaBase64'] ) ) {
				$order->update_meta_data( '_deprisa_etiqueta_base64', $etiquetas[0]['etiquetaBase64'] );
				$order->save();
			}

			return [
				'ok'   => true,
				'guia' => $result['numeroEnvio'],
				'data' => $result,
			];

		} catch ( LTMS_Deprisa_Exception $e ) {
			$order->add_order_note( '[Deprisa] Excepción: ' . $e->getMessage() );
			return [ 'ok' => false, 'guia' => null, 'error' => $e->getMessage() ];
		}
	}

	/* ------------------------------------------------------------------ */
	/* Cotizar antes de despachar                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Cotiza el envío de un pedido.
	 *
	 * @param int   $order_id
	 * @param float $kilos
	 * @return array { ok: bool, cotizaciones: array, errors: array }
	 */
	public function cotizar_pedido( int $order_id, float $kilos = 1.0 ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order ) return [ 'ok' => false, 'cotizaciones' => [], 'errors' => [['descripcion'=>'Pedido no encontrado']] ];

		$shipping = $order->get_address( 'shipping' );

		return $this->api->cotizar( [
			'tipoEnvio'             => 'N',
			'numeroBultos'          => 1,
			'kilos'                 => $kilos,
			'clienteRemitente'      => get_option( 'ltms_deprisa_cliente_remitente', '' ),
			'centroRemitente'       => get_option( 'ltms_deprisa_centro_remitente',  '01' ),
			'paisRemitente'         => '057',
			'poblacionRemitente'    => get_option( 'ltms_deprisa_ciudad_remitente', 'BOGOTA' ),
			'paisDestinatario'      => '057',
			'poblacionDestinatario' => strtoupper( $shipping['city'] ?? '' ),
			'importeValorDeclarado' => (float) $order->get_total(),
			'tipoMoneda'            => 'COP',
		] );
	}

	/* ------------------------------------------------------------------ */
	/* Tracking                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Obtiene el tracking de un pedido.
	 *
	 * @param int $order_id
	 * @return array|null
	 */
	public function tracking_pedido( int $order_id ): ?array {
		$order = wc_get_order( $order_id );
		if ( ! $order ) return null;

		$guia = $order->get_meta( '_deprisa_guia' );
		if ( empty( $guia ) ) return null;

		try {
			return $this->api->consultar_tracking( $guia );
		} catch ( LTMS_Deprisa_Exception $e ) {
			return [ 'error' => $e->getMessage() ];
		}
	}

	/* ------------------------------------------------------------------ */
	/* Crear y asociar recogida                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Crea una recogida y opcionalmente asocia una guía.
	 *
	 * @param array  $recogida_params  Params para crear_recogida()
	 * @param string $numero_guia      Número de guía a asociar (opcional)
	 * @return array
	 */
	public function crear_y_asociar_recogida( array $recogida_params, string $numero_guia = '' ): array {
		try {
			$result = $this->api->crear_recogida( $recogida_params );

			if ( ! $result['ok'] ) {
				return $result;
			}

			if ( ! empty( $numero_guia ) && ! empty( $result['codigoRecogida'] ) ) {
				$asoc = $this->api->asociar_guias( [
					[
						'codigoRecogida' => $result['codigoRecogida'],
						'numeroEnvio'    => $numero_guia,
					],
				] );
				$result['asociacion'] = $asoc;
			}

			return $result;

		} catch ( LTMS_Deprisa_Exception $e ) {
			return [ 'ok' => false, 'errors' => [['descripcion' => $e->getMessage()]], 'codigoRecogida' => null ];
		}
	}

	/* ================================================================== */
	/* HELPERS INTERNOS                                                     */
	/* ================================================================== */

	/**
	 * Construye los params de admisión a partir de un pedido WC.
	 */
	private function build_admision_params( \WC_Order $order, array $opciones ): array {
		$shipping = $order->get_address( 'shipping' );
		$billing  = $order->get_address( 'billing' );

		// Usar dirección de shipping; fallback a billing
		$dest_nombre   = trim( ( $shipping['first_name'] ?? '' ) . ' ' . ( $shipping['last_name'] ?? '' ) );
		if ( empty( $dest_nombre ) ) {
			$dest_nombre = trim( ( $billing['first_name'] ?? '' ) . ' ' . ( $billing['last_name'] ?? '' ) );
		}

		$contraentrega = ! empty( $opciones['contraentrega'] );

		return [
			'codigoAdmision'                => LTMS_Api_Deprisa::generar_codigo_admision( 'LTMS' ),
			'grabarEnvio'                   => 'S',
			'numeroBultos'                  => 1,

			// Remitente (datos de la tienda)
			'clienteRemitente'              => get_option( 'ltms_deprisa_cliente_remitente', '' ),
			'centroRemitente'               => get_option( 'ltms_deprisa_centro_remitente',  '01' ),
			'direccionRemitente'            => get_option( 'ltms_deprisa_direccion_remitente', '' ),
			'poblacionRemitente'            => get_option( 'ltms_deprisa_ciudad_remitente',    'BOGOTA' ),
			'codigoPostalRemitente'         => get_option( 'ltms_deprisa_cp_remitente',        '' ),
			'paisRemitente'                 => '057',
			'tipoDocRemitente'              => get_option( 'ltms_deprisa_tipo_doc_remitente', 'NIT' ),
			'documentoIdentidadRemitente'   => get_option( 'ltms_deprisa_nit_remitente',      '' ),
			'personaContactoRemitente'      => get_option( 'ltms_deprisa_contacto_remitente', '' ),
			'telefonoContactoRemitente'     => get_option( 'ltms_deprisa_telefono_remitente', '' ),

			// Destinatario
			'clienteDestinatario'           => '99999999',
			'centroDestinatario'            => '99',
			'nombreDestinatario'            => $dest_nombre,
			'direccionDestinatario'         => $shipping['address_1'] ?? $billing['address_1'] ?? '',
			'paisDestinatario'              => '057',
			'codigoPostalDestinatario'      => $shipping['postcode'] ?? $billing['postcode'] ?? '',
			'poblacionDestinatario'         => strtoupper( $shipping['city'] ?? $billing['city'] ?? '' ),
			'tipoDocDestinatario'           => 'CC',
			'documentoIdentidadDestinatario'=> $order->get_meta( '_billing_cedula' ) ?: '0',
			'telefonoContactoDestinatario'  => $shipping['phone'] ?? $billing['phone'] ?? $order->get_billing_phone(),

			// Envío
			'codigoServicio'                => $opciones['codigoServicio'] ?? ( $contraentrega ? '3027' : '3005' ),
			'kilos'                         => $opciones['kilos']          ?? 1,
			'tipoPortes'                    => 'P',
			'tipoPorteReembolsos'           => $contraentrega ? 'D' : '',
			'importeReembolso'              => $contraentrega ? ( $opciones['importeReembolso'] ?? $order->get_total() ) : '',
			'importeValorDeclarado'         => (float) $order->get_total(),
			'asegurarEnvio'                 => $opciones['asegurarEnvio'] ?? 'N',
			'tipoMoneda'                    => 'COP',
			'numeroReferencia'              => 'ORD-' . $order->get_id(),
			'observaciones1'                => $opciones['observaciones'] ?? ( 'Pedido #' . $order->get_id() ),
		];
	}

	private function format_errors( array $errors ): string {
		return implode( ' | ', array_map( fn( $e ) => $e['descripcion'] ?? $e['codigo'] ?? 'Error desconocido', $errors ) );
	}
}
