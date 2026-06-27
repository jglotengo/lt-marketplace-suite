<?php
/**
 * LTMS Booking Season Manager
 *
 * Calcula modificadores de precio por temporada.
 * Lee la tabla lt_booking_season_rules:
 *   - product_id > 0 → regla para un alojamiento específico del vendedor.
 *   - product_id = 0 → regla global del vendedor (vendor_id identifica al dueño).
 *
 * @package    LTMS
 * @subpackage LTMS/includes/booking
 * @since      2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LTMS_Booking_Season_Manager
 */
class LTMS_Booking_Season_Manager {

	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		add_filter( 'ltms_booking_price_for_dates', [ self::class, 'apply_season_modifier' ], 10, 3 );

		// M-BOOKING-PLAN-02: AJAX del panel de vendedor (tab Temporadas).
		add_action( 'wp_ajax_ltms_get_vendor_seasons', [ self::class, 'ajax_get_vendor_seasons' ] );
		add_action( 'wp_ajax_ltms_save_vendor_season', [ self::class, 'ajax_save_vendor_season' ] );
		add_action( 'wp_ajax_ltms_delete_vendor_season', [ self::class, 'ajax_delete_vendor_season' ] );
	}

	/**
	 * Filtra el precio de una reserva aplicando el modificador de temporada.
	 *
	 * No resuelve vendor_id aquí: get_modifier_for_date() primero intenta la
	 * regla específica del producto, y solo recurre a una regla global de
	 * vendedor cuando se le pasa $vendor_id explícitamente (ver
	 * apply_season_modifier_for_vendor()), evitando una llamada a
	 * get_post_field() en la ruta de precio "caliente".
	 *
	 * @param float  $price         Precio base.
	 * @param int    $product_id
	 * @param string $checkin_date  Y-m-d
	 * @return float  Precio modificado.
	 */
	public static function apply_season_modifier( float $price, int $product_id, string $checkin_date ): float {
		$modifier = self::get_modifier_for_date( $product_id, $checkin_date );
		return round( $price * $modifier, 2 );
	}

	/**
	 * Variante consciente de vendedor: además de la regla de producto,
	 * resuelve reglas globales (product_id = 0) del vendedor dueño.
	 * Úsese cuando ya se conoce el vendor_id (p. ej. dentro del flujo de
	 * checkout, donde el vendor_id del producto ya fue resuelto antes).
	 *
	 * @param float  $price
	 * @param int    $product_id
	 * @param string $checkin_date
	 * @param int    $vendor_id
	 * @return float
	 */
	public static function apply_season_modifier_for_vendor( float $price, int $product_id, string $checkin_date, int $vendor_id ): float {
		$modifier = self::get_modifier_for_date( $product_id, $checkin_date, $vendor_id );
		return round( $price * $modifier, 2 );
	}

	/**
	 * Obtiene el modificador de precio para una fecha específica.
	 * Prioridad: regla de producto > regla global del vendedor (si se indica vendor_id).
	 *
	 * @param int    $product_id
	 * @param string $date       Y-m-d
	 * @param int    $vendor_id  Opcional. Necesario sólo para resolver reglas
	 *                           globales con aislamiento multi-tenant.
	 * @return float Multiplicador (1.0 = sin cambio)
	 */
	public static function get_modifier_for_date( int $product_id, string $date, int $vendor_id = 0 ): float {
		global $wpdb;

		if ( ! $wpdb ) {
			return 1.0;
		}

		if ( $product_id > 0 ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT price_modifier FROM {$wpdb->prefix}lt_booking_season_rules
					 WHERE product_id = %d AND %s BETWEEN date_from AND date_to
					 ORDER BY id DESC LIMIT 1",
					$product_id,
					$date
				),
				ARRAY_A
			);
			if ( $row ) {
				return max( 0.1, (float) $row['price_modifier'] );
			}
		}

		if ( $vendor_id > 0 ) {
			$global = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT price_modifier FROM {$wpdb->prefix}lt_booking_season_rules
					 WHERE product_id = 0 AND vendor_id = %d AND %s BETWEEN date_from AND date_to
					 ORDER BY id DESC LIMIT 1",
					$vendor_id,
					$date
				),
				ARRAY_A
			);
			if ( $global ) {
				return max( 0.1, (float) $global['price_modifier'] );
			}
		}

		return 1.0;
	}

	/**
	 * Calcula el precio total para un rango de noches aplicando temporadas.
	 *
	 * @param float  $base_price_per_night
	 * @param int    $product_id
	 * @param string $checkin_date  Y-m-d
	 * @param string $checkout_date Y-m-d
	 * @return float Precio total
	 */
	public static function calculate_total(
		float $base_price_per_night,
		int $product_id,
		string $checkin_date,
		string $checkout_date
	): float {
		$total   = 0.0;
		$current = strtotime( $checkin_date );
		$end     = strtotime( $checkout_date );

		if ( false === $current || false === $end || $current >= $end ) {
			return 0.0;
		}

		while ( $current < $end ) {
			$date     = gmdate( 'Y-m-d', $current );
			$modifier = self::get_modifier_for_date( $product_id, $date );
			$total   += $base_price_per_night * $modifier;
			$current += DAY_IN_SECONDS;
		}

		return round( $total, 2 );
	}

	/**
	 * Retorna las reglas de temporada de un producto (incluye globales product_id=0).
	 *
	 * @param int $product_id 0 = sólo globales
	 * @return array
	 */
	public static function get_rules( int $product_id = 0 ): array {
		global $wpdb;

		if ( ! $wpdb ) {
			return [];
		}

		$ids          = $product_id ? [ 0, $product_id ] : [ 0 ];
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		return $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}lt_booking_season_rules
				 WHERE product_id IN ($placeholders) ORDER BY date_from ASC",
				...$ids
			),
			ARRAY_A
		) ?: [];
	}

	/**
	 * Retorna las reglas de temporada de un vendedor:
	 *   - Reglas atadas a un producto suyo (product_id > 0, post_author = vendor).
	 *   - Reglas globales propias (product_id = 0, vendor_id = vendor).
	 *
	 * @param int $vendor_id
	 * @return array
	 */
	public static function get_vendor_rules( int $vendor_id ): array {
		global $wpdb;

		if ( ! $wpdb ) {
			return [];
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.id, r.name AS season_name, r.product_id, r.vendor_id,
				        r.date_from, r.date_to, r.price_modifier,
				        p.post_title AS product_name
				   FROM {$wpdb->prefix}lt_booking_season_rules AS r
			  LEFT JOIN {$wpdb->posts} AS p
			         ON p.ID = r.product_id AND p.post_status IN ('publish','pending','draft')
				  WHERE ( r.product_id > 0 AND p.post_author = %d )
				     OR ( r.product_id = 0 AND r.vendor_id = %d )
				  ORDER BY r.date_from ASC",
				$vendor_id,
				$vendor_id
			),
			ARRAY_A
		) ?: [];

		return $rows;
	}

	/**
	 * Guarda (insert o update) una regla de temporada.
	 * Escribe a las columnas reales de la tabla: id, name, product_id, vendor_id,
	 * price_modifier, date_from, date_to.
	 *
	 * @param array $data Keys: rule_id|id (opcional), product_id, vendor_id,
	 *                          season_name, price_modifier, date_from, date_to,
	 *                          country_code (opcional, normalizado a mayúsculas).
	 * @return int|\WP_Error ID de la regla, o WP_Error en caso de fallo.
	 */
	public static function save_rule( array $data ): int|\WP_Error {
		global $wpdb;

		if ( ! empty( $data['country_code'] ) ) {
			$data['country_code'] = strtoupper( sanitize_text_field( $data['country_code'] ) );
		}

		$row = [
			'name'           => sanitize_text_field( $data['season_name'] ?? '' ),
			'product_id'     => (int) ( $data['product_id'] ?? 0 ),
			'vendor_id'      => (int) ( $data['vendor_id'] ?? 0 ),
			'price_modifier' => (float) ( $data['price_modifier'] ?? 1.0 ),
			'date_from'      => sanitize_text_field( $data['date_from'] ?? '' ),
			'date_to'        => sanitize_text_field( $data['date_to'] ?? '' ),
		];
		$fmt = [ '%s', '%d', '%d', '%f', '%s', '%s' ];

		$rule_id = (int) ( $data['rule_id'] ?? $data['id'] ?? 0 );

		if ( $rule_id > 0 ) {
			$res = $wpdb->update( $wpdb->prefix . 'lt_booking_season_rules', $row, [ 'id' => $rule_id ], $fmt, [ '%d' ] );
			if ( false === $res ) {
				return new \WP_Error( 'db_error', __( 'Error al actualizar la regla de temporada.', 'ltms' ) );
			}
			return $rule_id;
		}

		$res = $wpdb->insert( $wpdb->prefix . 'lt_booking_season_rules', $row, $fmt );
		if ( ! $res ) {
			return new \WP_Error( 'db_error', __( 'Error al crear la regla de temporada.', 'ltms' ) );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Elimina una regla de temporada validando ownership.
	 *
	 * @param int $id        ID de la regla.
	 * @param int $vendor_id ID del vendedor que intenta eliminar (0 = sin chequeo, uso admin).
	 * @return bool
	 */
	public static function delete_rule( int $id, int $vendor_id = 0 ): bool {
		global $wpdb;

		if ( ! $wpdb ) {
			return false;
		}

		if ( $vendor_id > 0 ) {
			$rule = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT r.product_id, r.vendor_id, p.post_author
					   FROM {$wpdb->prefix}lt_booking_season_rules AS r
				  LEFT JOIN {$wpdb->posts} AS p ON p.ID = r.product_id
				      WHERE r.id = %d",
					$id
				)
			);

			if ( ! $rule ) {
				return false;
			}

			$owns = ( 0 === (int) $rule->product_id && (int) $rule->vendor_id === $vendor_id )
				 || ( (int) $rule->product_id > 0 && (int) $rule->post_author === $vendor_id );

			if ( ! $owns ) {
				return false;
			}
		}

		return (bool) $wpdb->delete( $wpdb->prefix . 'lt_booking_season_rules', [ 'id' => $id ], [ '%d' ] );
	}

	// ── AJAX (panel de vendedor) ────────────────────────────────────────

	public static function ajax_get_vendor_seasons(): void {
		check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );
		wp_send_json_success( self::get_vendor_rules( get_current_user_id() ) );
	}

	public static function ajax_save_vendor_season(): void {
		check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

		$vendor_id  = get_current_user_id();
		$rule_id    = absint( $_POST['rule_id'] ?? 0 );
		$product_id = absint( $_POST['product_id'] ?? 0 );
		$name       = sanitize_text_field( wp_unslash( $_POST['season_name'] ?? '' ) );
		$from       = sanitize_text_field( wp_unslash( $_POST['date_from'] ?? '' ) );
		$to         = sanitize_text_field( wp_unslash( $_POST['date_to'] ?? '' ) );
		$modifier   = (float) ( $_POST['price_modifier'] ?? 1.0 );

		if ( ! $name || ! $from || ! $to ) {
			wp_send_json_error( __( 'El nombre y las fechas son obligatorios.', 'ltms' ) );
		}
		if ( strtotime( $from ) >= strtotime( $to ) ) {
			wp_send_json_error( __( 'La fecha de inicio debe ser anterior a la de fin.', 'ltms' ) );
		}
		if ( $modifier <= 0 || $modifier > 10 ) {
			wp_send_json_error( __( 'El modificador debe estar entre 0.01 y 10.', 'ltms' ) );
		}

		// Validar ownership del producto (si se especifica uno).
		if ( $product_id > 0 ) {
			if ( (int) get_post_field( 'post_author', $product_id ) !== $vendor_id ) {
				wp_send_json_error( __( 'No tienes permiso sobre ese producto.', 'ltms' ) );
			}
		}
		// product_id = 0 → regla global del vendedor (válido).

		// Validar ownership de la regla a editar.
		if ( $rule_id > 0 ) {
			global $wpdb;
			$existing_rule = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT r.product_id, r.vendor_id, p.post_author
					   FROM {$wpdb->prefix}lt_booking_season_rules AS r
				  LEFT JOIN {$wpdb->posts} AS p ON p.ID = r.product_id
				      WHERE r.id = %d",
					$rule_id
				)
			);
			if ( ! $existing_rule ) {
				wp_send_json_error( __( 'Regla no encontrada.', 'ltms' ) );
			}
			$owns = ( 0 === (int) $existing_rule->product_id && (int) $existing_rule->vendor_id === $vendor_id )
				 || ( (int) $existing_rule->product_id > 0 && (int) $existing_rule->post_author === $vendor_id );
			if ( ! $owns ) {
				wp_send_json_error( __( 'No tienes permiso para editar esta temporada.', 'ltms' ) );
			}
		}

		$result = self::save_rule( [
			'rule_id'        => $rule_id,
			'product_id'     => $product_id,
			'vendor_id'      => $vendor_id,
			'season_name'    => $name,
			'price_modifier' => max( 0.1, $modifier ),
			'date_from'      => $from,
			'date_to'        => $to,
		] );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		$msg = $rule_id > 0
			? __( 'Temporada actualizada correctamente.', 'ltms' )
			: __( 'Temporada creada correctamente.', 'ltms' );

		wp_send_json_success( [ 'message' => $msg, 'id' => $result ] );
	}

	public static function ajax_delete_vendor_season(): void {
		check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

		$vendor_id = get_current_user_id();
		$rule_id   = absint( $_POST['rule_id'] ?? 0 );

		if ( ! $rule_id ) {
			wp_send_json_error( __( 'ID inválido.', 'ltms' ) );
		}

		if ( ! self::delete_rule( $rule_id, $vendor_id ) ) {
			wp_send_json_error( __( 'No tienes permiso sobre esa regla o no existe.', 'ltms' ) );
		}

		wp_send_json_success( [ 'message' => __( 'Temporada eliminada.', 'ltms' ) ] );
	}
}
