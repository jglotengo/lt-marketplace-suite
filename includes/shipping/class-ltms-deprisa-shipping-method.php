<?php
/**
 * LTMS — Método de envío WooCommerce para Deprisa
 *
 * Aparece como opción de envío en el checkout de WooCommerce.
 * Cotiza el precio en tiempo real según origen / destino y peso del carrito.
 *
 * Ubicación:
 *   includes/shipping/class-ltms-deprisa-shipping-method.php
 *
 * Registro (en el loader o functions.php):
 *   add_filter( 'woocommerce_shipping_methods', function( $methods ) {
 *       $methods['ltms_deprisa'] = 'LTMS_Deprisa_Shipping_Method';
 *       return $methods;
 *   });
 *
 * @package LTMS
 * @since   1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'WC_Shipping_Method' ) ) return;

class LTMS_Deprisa_Shipping_Method extends WC_Shipping_Method {

	/* ------------------------------------------------------------------ */
	/* Constructor                                                         */
	/* ------------------------------------------------------------------ */

	public function __construct( int $instance_id = 0 ) {
		$this->id                 = 'ltms_deprisa';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = 'Deprisa (LTMS)';
		$this->method_description = 'Envío nacional con Deprisa — cotización en tiempo real.';
		$this->supports           = [ 'shipping-zones', 'instance-settings' ];
		$this->title              = 'Deprisa';

		$this->init();
	}

	/* ------------------------------------------------------------------ */
	/* Init: cargar settings                                               */
	/* ------------------------------------------------------------------ */

	public function init(): void {
		$this->init_form_fields();
		$this->init_settings();

		$this->title          = $this->get_option( 'title', 'Deprisa — Envío Nacional' );
		$this->tax_status     = $this->get_option( 'tax_status', 'taxable' );
		$this->free_min_total = (float) $this->get_option( 'free_min_total', 0 );
		$this->markup_pct     = (float) $this->get_option( 'markup_pct', 0 );
		$this->markup_fixed   = (float) $this->get_option( 'markup_fixed', 0 );
		$this->fallback_cost  = (float) $this->get_option( 'fallback_cost', 0 );
		$this->codigo_servicio= $this->get_option( 'codigo_servicio', '' );
		$this->kg_por_defecto = (float) $this->get_option( 'kg_por_defecto', 1.0 );
		$this->usar_peso_real = wc_string_to_bool( $this->get_option( 'usar_peso_real', 'yes' ) );

		add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );
	}

	/* ------------------------------------------------------------------ */
	/* Campos del panel de administración de la zona de envío             */
	/* ------------------------------------------------------------------ */

	public function init_form_fields(): void {
		$this->instance_form_fields = [

			'title' => [
				'title'       => 'Nombre visible',
				'type'        => 'text',
				'default'     => 'Deprisa — Envío Nacional',
				'description' => 'Nombre que ve el cliente en el checkout.',
			],

			'codigo_servicio' => [
				'title'       => 'Código de servicio Deprisa',
				'type'        => 'select',
				'options'     => [
					''     => '— Usar el código configurado en LTMS Ajustes —',
					'3005' => '3005 — Estándar B2B',
					'3027' => '3027 — Contraentrega',
				],
				'default'     => '',
				'description' => 'Filtra la cotización por este producto. Vacío = todos los productos disponibles.',
			],

			'usar_peso_real' => [
				'title'   => 'Calcular peso del carrito',
				'type'    => 'checkbox',
				'label'   => 'Sí, sumar los pesos de los productos en el carrito',
				'default' => 'yes',
			],

			'kg_por_defecto' => [
				'title'       => 'Peso por defecto (kg)',
				'type'        => 'number',
				'default'     => '1',
				'custom_attributes' => [ 'min' => '0.1', 'step' => '0.1' ],
				'description' => 'Usado cuando los productos no tienen peso definido.',
			],

			'markup_pct' => [
				'title'       => 'Margen (%)',
				'type'        => 'number',
				'default'     => '0',
				'custom_attributes' => [ 'min' => '0', 'step' => '1' ],
				'description' => 'Porcentaje adicional sobre el costo cotizado.',
			],

			'markup_fixed' => [
				'title'       => 'Cargo fijo adicional ($)',
				'type'        => 'number',
				'default'     => '0',
				'custom_attributes' => [ 'min' => '0', 'step' => '1000' ],
				'description' => 'Monto fijo (COP) que se suma al costo cotizado.',
			],

			'free_min_total' => [
				'title'       => 'Envío gratis desde ($)',
				'type'        => 'number',
				'default'     => '0',
				'custom_attributes' => [ 'min' => '0', 'step' => '1000' ],
				'description' => 'Monto mínimo de compra para envío gratis. 0 = deshabilitado.',
			],

			'fallback_cost' => [
				'title'       => 'Tarifa fija si cotización falla ($)',
				'type'        => 'number',
				'default'     => '0',
				'custom_attributes' => [ 'min' => '0', 'step' => '1000' ],
				'description' => 'Si la API de Deprisa no responde, usar este monto. 0 = ocultar método.',
			],

			'tax_status' => [
				'title'   => 'Estado de impuesto',
				'type'    => 'select',
				'options' => [ 'taxable' => 'Gravable', 'none' => 'Sin impuesto' ],
				'default' => 'taxable',
			],
		];
	}

	/* ------------------------------------------------------------------ */
	/* calculate_shipping — llamado por WooCommerce                       */
	/* ------------------------------------------------------------------ */

	public function calculate_shipping( $package = [] ): void {
		// Verificar que la integración esté activa y configurada
		if ( ! (bool) get_option( 'ltms_deprisa_enabled', false ) ) return;

		$username = get_option( 'ltms_deprisa_username', '' );
		$password = get_option( 'ltms_deprisa_password', '' );
		if ( empty( $username ) || empty( $password ) ) return;

		// Destino
		$ciudad_destino = strtoupper( trim( $package['destination']['city'] ?? '' ) );
		if ( empty( $ciudad_destino ) ) return;

		// Peso
		$kilos = $this->calcular_kilos( $package );

		// Valor total del carrito (para valor declarado y envío gratis)
		$total_carrito = WC()->cart ? (float) WC()->cart->get_subtotal() : 0.0;

		// ¿Envío gratis?
		if ( $this->free_min_total > 0 && $total_carrito >= $this->free_min_total ) {
			$this->add_rate( [
				'id'    => $this->get_rate_id(),
				'label' => $this->title . ' 🎁 (gratis)',
				'cost'  => 0,
			] );
			return;
		}

		// Cotizar
		$costo = $this->cotizar_en_deprisa( $ciudad_destino, $kilos, $total_carrito );

		if ( $costo === null ) {
			// Fallback
			if ( $this->fallback_cost > 0 ) {
				$this->add_rate( [
					'id'    => $this->get_rate_id(),
					'label' => $this->title,
					'cost'  => $this->fallback_cost,
				] );
			}
			return;
		}

		// Aplicar margen
		$costo_final = $costo;
		if ( $this->markup_pct > 0 ) {
			$costo_final *= ( 1 + $this->markup_pct / 100 );
		}
		$costo_final += $this->markup_fixed;
		$costo_final  = round( $costo_final, -2 ); // Redondear a centenas

		$this->add_rate( [
			'id'         => $this->get_rate_id(),
			'label'      => $this->title,
			'cost'       => $costo_final,
			'tax_status' => $this->tax_status,
			'meta_data'  => [
				'ltms_deprisa_kilos'          => $kilos,
				'ltms_deprisa_ciudad_destino' => $ciudad_destino,
			],
		] );
	}

	/* ------------------------------------------------------------------ */
	/* Helpers internos                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Calcula el peso total del carrito.
	 */
	private function calcular_kilos( array $package ): float {
		if ( ! $this->usar_peso_real ) {
			return $this->kg_por_defecto;
		}

		$total_kg = 0.0;
		foreach ( $package['contents'] as $item ) {
			$product = $item['data'];
			$weight  = (float) $product->get_weight();
			if ( $weight <= 0 ) {
				$weight = $this->kg_por_defecto;
			} else {
				// Convertir de la unidad de peso WC a kg
				$weight = wc_get_weight( $weight, 'kg' );
			}
			$total_kg += $weight * $item['quantity'];
		}

		return max( 0.1, $total_kg );
	}

	/**
	 * Llama a la API de Deprisa y devuelve el costo mínimo disponible.
	 * Usa caché de transient para no llamar en cada render de página.
	 *
	 * @return float|null  null si falla o no hay cobertura
	 */
	private function cotizar_en_deprisa( string $ciudad_destino, float $kilos, float $valor_declarado ): ?float {
		$ciudad_origen = strtoupper( get_option( 'ltms_deprisa_ciudad_remitente', 'BOGOTA' ) );
		$cache_key     = 'ltms_dep_cot_' . md5( $ciudad_origen . $ciudad_destino . round( $kilos, 1 ) . $this->codigo_servicio );

		$cached = get_transient( $cache_key );
		if ( $cached !== false ) {
			return (float) $cached;
		}

		try {
			$api = new LTMS_Api_Deprisa(
				get_option( 'ltms_deprisa_username' ),
				get_option( 'ltms_deprisa_password' ),
				(bool) get_option( 'ltms_deprisa_sandbox', true )
			);

			$params = [
				'tipoEnvio'             => 'N',
				'numeroBultos'          => 1,
				'kilos'                 => $kilos,
				'clienteRemitente'      => get_option( 'ltms_deprisa_cliente_remitente', '' ),
				'centroRemitente'       => get_option( 'ltms_deprisa_centro_remitente', '01' ),
				'paisRemitente'         => '057',
				'poblacionRemitente'    => $ciudad_origen,
				'paisDestinatario'      => '057',
				'poblacionDestinatario' => $ciudad_destino,
				'importeValorDeclarado' => max( 4500, $valor_declarado ),
				'tipoMoneda'            => 'COP',
			];

			if ( $this->codigo_servicio ) {
				$params['codigoServicio'] = $this->codigo_servicio;
			}

			$result = $api->cotizar( $params );

			if ( ! $result['ok'] || empty( $result['cotizaciones'] ) ) {
				return null;
			}

			// Tomar el total mínimo disponible entre los productos cotizados
			$totales = array_column( $result['cotizaciones'], 'total' );
			$minimo  = min( array_filter( $totales, fn( $t ) => $t > 0 ) );

			if ( ! $minimo ) return null;

			// Cachear 30 minutos
			set_transient( $cache_key, $minimo, 30 * MINUTE_IN_SECONDS );

			return (float) $minimo;

		} catch ( LTMS_Deprisa_Exception $e ) {
			// Log silencioso — no romper el checkout
			error_log( '[LTMS Deprisa] Error al cotizar en checkout: ' . $e->getMessage() );
			return null;
		}
	}
}
