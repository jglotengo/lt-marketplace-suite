<?php
/**
 * LTMS Business Aveonline Agents
 *
 * Gestiona la creación y resolución de agentes Aveonline por vendedor.
 * Cada vendedor de la plataforma tiene su propio agente en Aveonline,
 * lo que permite trazabilidad de envíos y comisiones independientes.
 *
 * Flujo:
 *   1. Vendedor se registra → hook ltms_vendor_registered (prioridad 25)
 *   2. Se llama create_agent() → POST /agentes.php con tipo:"crearAgente"
 *   3. El id retornado se guarda en user_meta _ltms_aveonline_idagente
 *   4. Al cotizar/generar guía, get_vendor_idagente() retorna el meta o el global
 *
 * Si ya existe el agente (validacion de existencia) se intenta find_agent_id_by_email()
 * para recuperar el ID real y guardarlo. Si falla, se hace retry via WP-Cron.
 *
 * Meta keys:
 *   _ltms_aveonline_idagente   — ID del agente Aveonline (string)
 *   _ltms_aveonline_agent_status — 'ok' | 'pending' | 'error'
 *
 * @package    LTMS
 * @subpackage LTMS/includes/business
 * @version    1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LTMS_Business_Aveonline_Agents {

	use LTMS_Logger_Aware;

	// ── Boot ──────────────────────────────────────────────────────

	public static function init(): void {
		if ( LTMS_Core_Config::get( 'ltms_aveonline_enabled', 'no' ) !== 'yes' ) {
			return;
		}

		$instance = new self();

		// Vendedor registrado → crear agente (prioridad 25: después de Affiliates/Alegra)
		add_action( 'ltms_vendor_registered', [ $instance, 'on_vendor_registered' ], 25, 1 );

		// Cron de retry para agentes que fallaron al crearse
		add_action( 'ltms_aveonline_retry_create_agent', [ $instance, 'retry_create_agent' ] );
	}

	// ── Handlers ──────────────────────────────────────────────────

	/**
	 * Crea el agente Aveonline cuando se registra un vendedor.
	 *
	 * @param int $vendor_id ID del usuario WordPress del vendedor.
	 */
	public function on_vendor_registered( int $vendor_id ): void {
		// Idempotencia — no re-crear si ya existe
		if ( get_user_meta( $vendor_id, '_ltms_aveonline_idagente', true ) ) {
			return;
		}

		try {
			$agent_id = $this->create_agent_for_vendor( $vendor_id );
			if ( $agent_id ) {
				update_user_meta( $vendor_id, '_ltms_aveonline_idagente', (string) $agent_id );
				update_user_meta( $vendor_id, '_ltms_aveonline_agent_status', 'ok' );
				$this->log_info(
					'aveonline_agent_created',
					sprintf( 'Agente Aveonline #%s creado para vendedor #%d', $agent_id, $vendor_id )
				);
			}
		} catch ( \Throwable $e ) {
			update_user_meta( $vendor_id, '_ltms_aveonline_agent_status', 'pending' );
			$this->log_warning(
				'aveonline_agent_create_failed',
				sprintf( 'No se pudo crear agente Aveonline para vendedor #%d: %s', $vendor_id, $e->getMessage() )
			);
			// Programar retry en 5 minutos
			if ( ! wp_next_scheduled( 'ltms_aveonline_retry_create_agent', [ $vendor_id ] ) ) {
				wp_schedule_single_event( time() + 300, 'ltms_aveonline_retry_create_agent', [ $vendor_id ] );
			}
		}
	}

	/**
	 * Reintento programado de creación de agente (WP-Cron).
	 *
	 * @param int $vendor_id ID del vendedor.
	 */
	public function retry_create_agent( int $vendor_id ): void {
		// No reintentar si ya fue resuelto
		if ( get_user_meta( $vendor_id, '_ltms_aveonline_idagente', true ) ) {
			return;
		}

		try {
			$agent_id = $this->create_agent_for_vendor( $vendor_id );
			if ( $agent_id ) {
				update_user_meta( $vendor_id, '_ltms_aveonline_idagente', (string) $agent_id );
				update_user_meta( $vendor_id, '_ltms_aveonline_agent_status', 'ok' );
				$this->log_info(
					'aveonline_agent_retry_ok',
					sprintf( 'Agente Aveonline #%s creado en retry para vendedor #%d', $agent_id, $vendor_id )
				);
			}
		} catch ( \Throwable $e ) {
			update_user_meta( $vendor_id, '_ltms_aveonline_agent_status', 'error' );
			$this->log_warning(
				'aveonline_agent_retry_failed',
				sprintf( 'Retry fallido para vendedor #%d: %s', $vendor_id, $e->getMessage() )
			);
		}
	}

	// ── Public helpers ────────────────────────────────────────────

	/**
	 * Retorna el idagente del vendedor, con fallback al global de configuración.
	 *
	 * @param int|null $vendor_id ID del vendedor WordPress. Null → retorna el global.
	 * @return string ID del agente.
	 */
	public static function get_vendor_idagente( ?int $vendor_id ): string {
		if ( $vendor_id && $vendor_id > 0 ) {
			$meta = get_user_meta( $vendor_id, '_ltms_aveonline_idagente', true );
			if ( $meta ) {
				return (string) $meta;
			}
		}
		return (string) LTMS_Core_Config::get( 'ltms_aveonline_idagente', '' );
	}

	/**
	 * Resuelve el vendor_id del primer ítem de un WooCommerce package.
	 *
	 * @param array $package WooCommerce package con contents.
	 * @return int|null Vendor ID o null si no hay items.
	 */
	public static function get_vendor_id_from_package( array $package ): ?int {
		$contents = $package['contents'] ?? [];
		if ( empty( $contents ) ) {
			return null;
		}
		$first = reset( $contents );
		$pid   = (int) ( $first['product_id'] ?? 0 );
		if ( ! $pid ) {
			return null;
		}
		$author = (int) get_post_field( 'post_author', $pid );
		return $author > 0 ? $author : null;
	}

	// ── Private ───────────────────────────────────────────────────

	/**
	 * Construye el payload y llama a create_agent() de la API.
	 *
	 * @param int $vendor_id ID del vendedor.
	 * @return string|null ID del agente creado, o null si ya existía y se recuperó.
	 * @throws \RuntimeException Si la API retorna error.
	 */
	private function create_agent_for_vendor( int $vendor_id ): ?string {
		$user = get_userdata( $vendor_id );
		if ( ! $user ) {
			throw new \RuntimeException( "Usuario #{$vendor_id} no encontrado." );
		}

		// Datos del vendedor — con fallbacks razonables
		$nombre   = trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name ?: $user->user_login;
		$email    = $user->user_email;
		$telefono = get_user_meta( $vendor_id, 'billing_phone', true )
			     ?: get_user_meta( $vendor_id, 'ltms_phone', true )
			     ?: '0000000000';
		$direccion = get_user_meta( $vendor_id, 'billing_address_1', true )
			      ?: get_user_meta( $vendor_id, 'ltms_address', true )
			      ?: 'Dirección pendiente';
		$ciudad   = get_user_meta( $vendor_id, 'billing_city', true )
			     ?: LTMS_Core_Config::get( 'ltms_store_city', 'BOGOTA(CUNDINAMARCA)' );
		$nit      = get_user_meta( $vendor_id, 'ltms_nit', true )
			     ?: get_user_meta( $vendor_id, 'ltms_document_number', true )
			     ?: (string) $vendor_id; // fallback: usar el user_id como NIT

		/** @var LTMS_Api_Aveonline $api */
		$api = LTMS_Api_Factory::get( 'aveonline' );

		return $api->create_agent( [
			'nombre'          => $nombre,
			'idnit'           => $nit,
			'telefono'        => preg_replace( '/\D/', '', $telefono ) ?: '0000000000',
			'direccion'       => $direccion,
			'correo'          => $email,
			'ciudad'          => $ciudad,
			'nombreContacto'  => $nombre,
			'email1'          => $email,
			'email2'          => $email,
			'idvalorminimo'   => 2, // sin mínimos
			'verRecaudos'     => 1, // no ver recaudos
			'agentePrincipal' => 2, // no es principal
		] );
	}
}
