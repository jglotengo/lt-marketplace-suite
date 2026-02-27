<?php
/**
 * LTMS Payment Orchestrator — Motor de Orquestación de Pagos
 *
 * Selecciona la pasarela óptima para cada transacción según el tipo de pago,
 * moneda y monto. Implementa circuit breaker con fallback automático y
 * registra la salud de cada proveedor en la tabla lt_provider_health.
 *
 * Algoritmo de selección:
 *   1. Métodos exclusivos de Openpay (PSE, Nequi, Daviplata, OXXO, SPEI).
 *   2. BNPL → Addi (delega; este orquestador no maneja el flujo Addi).
 *   3. Tarjeta internacional → Stripe.
 *   4. Tarjeta local → compara monto con threshold configurable; Openpay
 *      para montos pequeños, Stripe para montos mayores.
 *   5. Circuit breaker: si el proveedor elegido está "down", usa el
 *      alternativo (best-effort).
 *
 * @package    LTMS
 * @subpackage LTMS/includes/business
 * @version    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LTMS_Payment_Orchestrator
 *
 * Clase estática; no requiere instanciación ni init() en el Kernel
 * (es invocada directamente por las pasarelas de pago en el flujo de checkout).
 */
final class LTMS_Payment_Orchestrator {

	/**
	 * Tipos de pago que sólo puede procesar Openpay.
	 *
	 * @var string[]
	 */
	private const OPENPAY_EXCLUSIVE = [ 'pse', 'nequi', 'daviplata', 'oxxo', 'spei' ];

	/**
	 * Prevenir instanciación directa.
	 */
	private function __construct() {}

	// ─────────────────────────────────────────────────────────────────────────
	// Selección de pasarela
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Selecciona la pasarela óptima para una transacción.
	 *
	 * Aplica las reglas de negocio y el circuit breaker antes de devolver
	 * el slug del proveedor. No ejecuta el cobro (ver process_with_fallback).
	 *
	 * @param float  $amount       Monto de la transacción en la moneda indicada.
	 * @param string $currency     Código ISO 4217: 'COP' | 'MXN'.
	 * @param string $payment_type Tipo de pago: 'card_intl' | 'card_local' |
	 *                             'pse' | 'nequi' | 'daviplata' | 'oxxo' |
	 *                             'spei' | 'bnpl'.
	 * @param string $country      Código de país: 'CO' | 'MX'.
	 * @return string Slug del proveedor seleccionado: 'stripe'|'openpay'|'addi'.
	 */
	public static function select_gateway(
		float  $amount,
		string $currency,
		string $payment_type,
		string $country
	): string {
		// 1. Métodos exclusivos de Openpay (PSE, Nequi, Daviplata, OXXO, SPEI).
		if ( in_array( $payment_type, self::OPENPAY_EXCLUSIVE, true ) ) {
			return 'openpay';
		}

		// 2. BNPL → Addi (el orquestador no gestiona el flujo; sólo señaliza).
		if ( 'bnpl' === $payment_type ) {
			return 'addi';
		}

		// 3. Tarjeta internacional → Stripe siempre.
		if ( 'card_intl' === $payment_type ) {
			$provider = 'stripe';
		} else {
			// 4. Tarjeta local: elegir según threshold configurable por moneda.
			$threshold_cop = (float) LTMS_Core_Config::get( 'ltms_orchestration_stripe_threshold_cop', 200000 );
			$threshold_mxn = (float) LTMS_Core_Config::get( 'ltms_orchestration_stripe_threshold_mxn', 1500 );
			$threshold     = ( 'COP' === $currency ) ? $threshold_cop : $threshold_mxn;
			$provider      = ( $amount < $threshold ) ? 'openpay' : 'stripe';
		}

		// 5. Circuit breaker: si el proveedor elegido está caído, usar alternativa.
		if ( self::is_provider_down( $provider ) ) {
			$provider = ( 'stripe' === $provider ) ? 'openpay' : 'stripe';
			// Si la alternativa también está caída, devolver igual (best-effort).
		}

		return $provider;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Proceso de cobro con fallback
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Procesa el cobro con fallback automático si la pasarela primaria falla.
	 *
	 * Flujo:
	 *   1. Selecciona la pasarela primaria con select_gateway().
	 *   2. Intenta el cobro; si tiene éxito, devuelve el resultado.
	 *   3. Si la primaria falla: registra el evento, evalúa el circuit breaker,
	 *      y reintenta con la pasarela alternativa.
	 *   4. Si ambas fallan, devuelve ['success' => false, 'error' => '...'].
	 *
	 * @param float    $amount       Monto de la transacción.
	 * @param string   $currency     Código ISO 4217: 'COP' | 'MXN'.
	 * @param string   $payment_type Tipo de pago (ver select_gateway).
	 * @param array    $payment_data Datos de pago: token_id, method, etc.
	 * @param WC_Order $order        Objeto del pedido de WooCommerce.
	 * @return array{
	 *     success: bool,
	 *     transaction_id?: string,
	 *     gateway_used?: string,
	 *     fallback_used?: bool,
	 *     error?: string
	 * }
	 */
	public static function process_with_fallback(
		float    $amount,
		string   $currency,
		string   $payment_type,
		array    $payment_data,
		WC_Order $order
	): array {
		$country = LTMS_Core_Config::get_country();
		$primary = self::select_gateway( $amount, $currency, $payment_type, $country );
		$start   = microtime( true );

		try {
			$result  = self::charge_via( $primary, $amount, $currency, $payment_data, $order );
			$latency = self::elapsed_ms( $start );
			self::record_provider_event( $primary, 'success', $latency );

			$result['gateway_used']  = $primary;
			$result['fallback_used'] = false;
			return $result;

		} catch ( \Throwable $e ) {
			$latency = self::elapsed_ms( $start );
			self::record_provider_event( $primary, 'error', $latency, $e->getMessage() );
			self::maybe_trip_circuit_breaker( $primary );

			LTMS_Core_Logger::error(
				'PAYMENT_ORCHESTRATOR_PRIMARY_FAILED',
				sprintf(
					'Pasarela primaria %s falló para pedido #%d: %s',
					strtoupper( $primary ),
					$order->get_id(),
					$e->getMessage()
				),
				[
					'provider'  => $primary,
					'order_id'  => $order->get_id(),
					'amount'    => $amount,
					'currency'  => $currency,
					'latency'   => $latency,
				]
			);

			// Determinar pasarela de respaldo (sólo aplica entre stripe/openpay).
			$fallback = ( 'stripe' === $primary ) ? 'openpay' : 'stripe';

			// Si el respaldo también está caído, abortar.
			if ( self::is_provider_down( $fallback ) ) {
				return [
					'success' => false,
					'error'   => __( 'Ambas pasarelas no disponibles. Intente más tarde.', 'ltms' ),
				];
			}

			$start2 = microtime( true );
			try {
				$result   = self::charge_via( $fallback, $amount, $currency, $payment_data, $order );
				$latency2 = self::elapsed_ms( $start2 );
				self::record_provider_event( $fallback, 'success', $latency2 );

				LTMS_Core_Logger::info(
					'PAYMENT_ORCHESTRATOR_FALLBACK_SUCCESS',
					sprintf(
						'Fallback a %s exitoso para pedido #%d.',
						strtoupper( $fallback ),
						$order->get_id()
					),
					[ 'fallback_provider' => $fallback, 'order_id' => $order->get_id() ]
				);

				$result['gateway_used']  = $fallback;
				$result['fallback_used'] = true;
				return $result;

			} catch ( \Throwable $e2 ) {
				$latency2 = self::elapsed_ms( $start2 );
				self::record_provider_event( $fallback, 'error', $latency2, $e2->getMessage() );
				self::maybe_trip_circuit_breaker( $fallback );

				LTMS_Core_Logger::error(
					'PAYMENT_ORCHESTRATOR_FALLBACK_FAILED',
					sprintf(
						'Fallback %s también falló para pedido #%d: %s',
						strtoupper( $fallback ),
						$order->get_id(),
						$e2->getMessage()
					),
					[ 'fallback_provider' => $fallback, 'order_id' => $order->get_id() ]
				);

				return [ 'success' => false, 'error' => $e2->getMessage() ];
			}
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Registro de eventos y circuit breaker
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Registra el resultado de una llamada a proveedor en lt_provider_health.
	 *
	 * @param string $provider   Slug del proveedor (stripe, openpay, addi…).
	 * @param string $status     'success' | 'error' | 'timeout'.
	 * @param int    $latency_ms Latencia de la llamada en milisegundos.
	 * @param string $error_code Código o mensaje de error (vacío si no hubo error).
	 * @return void
	 */
	public static function record_provider_event(
		string $provider,
		string $status,
		int    $latency_ms,
		string $error_code = ''
	): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$wpdb->prefix . 'lt_provider_health',
			[
				'provider'   => sanitize_text_field( $provider ),
				'status'     => $status,
				'latency_ms' => $latency_ms,
				'error_code' => sanitize_text_field( substr( $error_code, 0, 100 ) ),
				'created_at' => current_time( 'mysql', true ),
			],
			[ '%s', '%s', '%d', '%s', '%s' ]
		);
	}

	/**
	 * Verifica si un proveedor tiene el circuit breaker abierto (estado "down").
	 *
	 * El circuit breaker se almacena como transient de WordPress con clave
	 * `ltms_circuit_{provider}_down`. Si existe y es truthy, el proveedor
	 * está en período de cooldown.
	 *
	 * @param string $provider Slug del proveedor.
	 * @return bool true si el proveedor está "down", false si está disponible.
	 */
	public static function is_provider_down( string $provider ): bool {
		return (bool) get_transient( 'ltms_circuit_' . $provider . '_down' );
	}

	/**
	 * Evalúa si debe activar el circuit breaker para el proveedor.
	 *
	 * Activa el circuit breaker si se detectan 3 o más errores en la
	 * última hora en la tabla lt_provider_health. Una vez activado,
	 * permanece durante el período de cooldown configurado (default: 15 min).
	 *
	 * @param string $provider Slug del proveedor.
	 * @return void
	 */
	private static function maybe_trip_circuit_breaker( string $provider ): void {
		global $wpdb;

		$one_hour_ago = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$consecutive_errors = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$wpdb->prefix}lt_provider_health`
				 WHERE provider = %s
				   AND status = 'error'
				   AND created_at >= %s",
				$provider,
				$one_hour_ago
			)
		);

		if ( $consecutive_errors >= 3 ) {
			$cooldown_minutes = (int) LTMS_Core_Config::get( 'ltms_circuit_breaker_cooldown_minutes', 15 );
			$cooldown_seconds = $cooldown_minutes * MINUTE_IN_SECONDS;

			set_transient( 'ltms_circuit_' . $provider . '_down', true, $cooldown_seconds );

			LTMS_Core_Logger::error(
				'CIRCUIT_BREAKER_TRIPPED',
				sprintf(
					'Circuit breaker activado para %s: %d errores en la última hora. Cooldown: %d min.',
					strtoupper( $provider ),
					$consecutive_errors,
					$cooldown_minutes
				),
				[ 'provider' => $provider, 'errors' => $consecutive_errors, 'cooldown_min' => $cooldown_minutes ]
			);

			self::alert_admin_provider_down( $provider, $consecutive_errors );
		}
	}

	/**
	 * Envía alerta de email al administrador cuando un proveedor activa su circuit breaker.
	 *
	 * @param string $provider  Slug del proveedor.
	 * @param int    $failures  Número de fallos detectados.
	 * @return void
	 */
	private static function alert_admin_provider_down( string $provider, int $failures ): void {
		$admin_email = (string) get_option( 'admin_email' );
		if ( empty( $admin_email ) ) {
			return;
		}

		$cooldown = (int) LTMS_Core_Config::get( 'ltms_circuit_breaker_cooldown_minutes', 15 );

		/* translators: %s: slug del proveedor en mayúsculas */
		$subject = sprintf(
			/* translators: %s: provider name in uppercase */
			__( '[LTMS] Proveedor %s — Circuit Breaker activado', 'ltms' ),
			strtoupper( $provider )
		);

		$body = sprintf(
			/* translators: 1: provider name, 2: failure count, 3: cooldown minutes */
			__(
				"El proveedor %1\$s ha tenido %2\$d errores en la última hora.\n\n"
				. "El circuit breaker está activo. Las transacciones se redirigirán\n"
				. "automáticamente a la pasarela alternativa durante %3\$d minutos.\n\n"
				. "Accede al dashboard de salud para más detalles:\n%4\$s",
				'ltms'
			),
			strtoupper( $provider ),
			$failures,
			$cooldown,
			admin_url( 'admin.php?page=ltms-provider-health' )
		);

		wp_mail( $admin_email, $subject, $body );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Ejecución del cobro
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Ejecuta el cobro contra la pasarela especificada.
	 *
	 * Lanza una excepción en caso de fallo para que process_with_fallback
	 * pueda capturarla y activar el flujo de fallback.
	 *
	 * @param string   $provider     Slug del proveedor: 'stripe' | 'openpay'.
	 * @param float    $amount       Monto de la transacción.
	 * @param string   $currency     Código ISO 4217 (mayúsculas).
	 * @param array    $payment_data Datos de pago: token_id, method, etc.
	 * @param WC_Order $order        Objeto del pedido de WooCommerce.
	 * @return array{success: bool, transaction_id: string}
	 * @throws \RuntimeException        Si la pasarela devuelve un error.
	 * @throws \InvalidArgumentException Si el proveedor es desconocido.
	 */
	private static function charge_via(
		string   $provider,
		float    $amount,
		string   $currency,
		array    $payment_data,
		WC_Order $order
	): array {
		if ( 'stripe' === $provider ) {
			return self::charge_via_stripe( $amount, $currency, $payment_data, $order );
		}

		if ( 'openpay' === $provider ) {
			return self::charge_via_openpay( $amount, $currency, $payment_data, $order );
		}

		throw new \InvalidArgumentException(
			sprintf( 'LTMS Orchestrator: Proveedor de cobro desconocido: "%s".', $provider )
		);
	}

	/**
	 * Ejecuta el cobro a través de Stripe.
	 *
	 * @param float    $amount       Monto de la transacción.
	 * @param string   $currency     Código ISO 4217.
	 * @param array    $payment_data Datos de pago (no usados directamente; Stripe usa tokens del frontend).
	 * @param WC_Order $order        Objeto del pedido.
	 * @return array{success: bool, transaction_id: string}
	 * @throws \RuntimeException Si Stripe devuelve un error.
	 */
	private static function charge_via_stripe(
		float    $amount,
		string   $currency,
		array    $payment_data,
		WC_Order $order
	): array {
		/** @var LTMS_Api_Stripe $stripe */
		$stripe = LTMS_Api_Factory::get( 'stripe' );

		$result = $stripe->create_payment_intent(
			$amount,
			strtolower( $currency ),
			$order->get_billing_email(),
			[
				'order_id'  => (string) $order->get_id(),
				'vendor_id' => (string) (int) get_post_meta( $order->get_id(), '_ltms_vendor_id', true ),
			]
		);

		if ( empty( $result['success'] ) ) {
			throw new \RuntimeException( $result['error'] ?? __( 'Error desconocido en Stripe.', 'ltms' ) );
		}

		return [
			'success'        => true,
			'transaction_id' => $result['data']['id'] ?? '',
		];
	}

	/**
	 * Ejecuta el cobro a través de Openpay.
	 *
	 * @param float    $amount       Monto de la transacción.
	 * @param string   $currency     Código ISO 4217.
	 * @param array    $payment_data Datos de pago: method, token_id, etc.
	 * @param WC_Order $order        Objeto del pedido.
	 * @return array{success: bool, transaction_id: string}
	 * @throws \RuntimeException Si Openpay devuelve un error.
	 */
	private static function charge_via_openpay(
		float    $amount,
		string   $currency,
		array    $payment_data,
		WC_Order $order
	): array {
		/** @var LTMS_Api_Openpay $openpay */
		$openpay = LTMS_Api_Factory::get( 'openpay' );

		$result = $openpay->charge( [
			'amount'      => $amount,
			'currency'    => strtolower( $currency ),
			'description' => sprintf(
				/* translators: %d: WooCommerce order ID */
				__( 'Pedido #%d', 'ltms' ),
				$order->get_id()
			),
			'order_id'    => (string) $order->get_id(),
			'method'      => $payment_data['method'] ?? 'card',
			'source_id'   => $payment_data['token_id'] ?? '',
			'customer'    => [
				'name'         => trim(
					$order->get_billing_first_name() . ' ' . $order->get_billing_last_name()
				),
				'email'        => $order->get_billing_email(),
				'phone_number' => $order->get_billing_phone(),
			],
		] );

		if ( empty( $result['success'] ) ) {
			throw new \RuntimeException( $result['error'] ?? __( 'Error desconocido en Openpay.', 'ltms' ) );
		}

		return [
			'success'        => true,
			'transaction_id' => $result['data']['id'] ?? '',
		];
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Utilidades internas
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Calcula los milisegundos transcurridos desde un punto de inicio.
	 *
	 * @param float $start Resultado de microtime(true) en el momento inicial.
	 * @return int Milisegundos transcurridos (redondeado).
	 */
	private static function elapsed_ms( float $start ): int {
		return (int) round( ( microtime( true ) - $start ) * 1000 );
	}
}
