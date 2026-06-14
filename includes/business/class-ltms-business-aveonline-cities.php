<?php
/**
 * LTMS Business Aveonline Cities
 *
 * Sincroniza y provee acceso al catálogo oficial de ciudades de Aveonline.
 *
 * Fuente: https://app.aveonline.co/assets/resources/public/listadociudades.json
 * Aveonline actualiza este JSON periódicamente. El plugin lo sincroniza
 * automáticamente cada 24 horas via WP-Cron y al activar/actualizar el plugin.
 *
 * Tabla: {prefix}lt_aveonline_cities
 *   - nombre      → "BOGOTA(CUNDINAMARCA)"   ← formato que espera la API
 *   - codigodane  → "11001000"               ← 8 dígitos Aveonline
 *   - departamento→ "CUNDINAMARCA"
 *   - nombremun   → "BOGOTA"                 ← nombre corto del municipio
 *   - synced_at   → timestamp de última sincronización
 *
 * Uso principal:
 *   // Buscar nombre exacto para API a partir del texto libre del usuario
 *   $city = LTMS_Business_Aveonline_Cities::find_by_name('Bogotá');
 *   // → 'BOGOTA(CUNDINAMARCA)'
 *
 *   // Obtener todas las ciudades para un select2/dropdown
 *   $options = LTMS_Business_Aveonline_Cities::get_options();
 *
 *   // Verificar si un nombre ya está en formato correcto
 *   $valid = LTMS_Business_Aveonline_Cities::exists('MEDELLIN(ANTIOQUIA)');
 *
 * @package    LTMS
 * @subpackage LTMS/includes/business
 * @version    1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LTMS_Business_Aveonline_Cities {

	use LTMS_Logger_Aware;

	/** URL oficial del JSON de ciudades de Aveonline */
	public const SOURCE_URL = 'https://app.aveonline.co/assets/resources/public/listadociudades.json';

	/** Transient con el timestamp de la última sincronización exitosa */
	private const TRANSIENT_LAST_SYNC = 'ltms_aveonline_cities_last_sync';

	/** Cron hook para sincronización diaria */
	private const CRON_HOOK = 'ltms_aveonline_cities_sync';

	/** Máximo de ciudades esperadas (seguridad contra respuestas malformadas) */
	private const MAX_CITIES = 20000;

	/** TTL mínimo entre re-sincronizaciones automáticas (segundos) */
	private const SYNC_INTERVAL = DAY_IN_SECONDS;

	// ── Boot ──────────────────────────────────────────────────────

	public static function init(): void {
		$instance = new self();

		// Cron diario de sincronización
		add_action( self::CRON_HOOK, [ $instance, 'sync' ] );

		// Programar cron si no está programado
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}

		// Sincronizar en activación/actualización del plugin si la tabla está vacía
		add_action( 'ltms_plugin_activated', [ $instance, 'maybe_sync' ] );
		add_action( 'ltms_plugin_updated',   [ $instance, 'maybe_sync' ] );

		// AJAX admin: sincronización manual desde el panel
		add_action( 'wp_ajax_ltms_aveonline_sync_cities',    [ $instance, 'ajax_sync'          ] );

		// AJAX público + admin: búsqueda dinámica de ciudades (autocompletar checkout/vendedor)
		add_action( 'wp_ajax_ltms_aveonline_search_cities',        [ __CLASS__, 'ajax_search_cities' ] );
		add_action( 'wp_ajax_nopriv_ltms_aveonline_search_cities', [ __CLASS__, 'ajax_search_cities' ] );
	}

	// ── Sincronización ────────────────────────────────────────────

	/**
	 * Sincroniza el catálogo desde Aveonline. Hace upsert completo (INSERT…ON DUPLICATE KEY UPDATE).
	 * Seguro para llamar múltiples veces — es idempotente.
	 *
	 * @return array{synced: int, errors: int, message: string}
	 */
	public function sync(): array {
		$data = $this->fetch_source();

		if ( empty( $data ) ) {
			return [ 'synced' => 0, 'errors' => 0, 'message' => 'No se pudo obtener el listado de ciudades de Aveonline.' ];
		}

		if ( count( $data ) > self::MAX_CITIES ) {
			$data = array_slice( $data, 0, self::MAX_CITIES );
		}

		global $wpdb;
		$table   = $wpdb->prefix . 'lt_aveonline_cities';
		$synced  = 0;
		$errors  = 0;
		$now     = current_time( 'mysql', true );

		// Upsert en lotes de 500 para no bloquear la BD
		$chunks = array_chunk( $data, 500 );
		foreach ( $chunks as $chunk ) {
			$values      = [];
			$placeholders = [];

			foreach ( $chunk as $city ) {
				$nombre      = trim( (string) ( $city['nombre']      ?? '' ) );
				$codigodane  = trim( (string) ( $city['codigodane']  ?? '' ) );
				$departamento = trim( (string) ( $city['departamento'] ?? '' ) );
				$nombremun   = trim( (string) ( $city['nombremun']   ?? '' ) );

				if ( $nombre === '' ) {
					$errors++;
					continue;
				}

				$placeholders[] = '(%s, %s, %s, %s, %s)';
				array_push( $values, $nombre, $codigodane, $departamento, $nombremun, $now );
			}

			if ( empty( $placeholders ) ) {
				continue;
			}

			$sql = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLNotPrepared
				"INSERT INTO `{$table}` (nombre, codigodane, departamento, nombremun, synced_at)
				 VALUES " . implode( ', ', $placeholders ) . "
				 ON DUPLICATE KEY UPDATE
				   codigodane   = VALUES(codigodane),
				   departamento = VALUES(departamento),
				   nombremun    = VALUES(nombremun),
				   synced_at    = VALUES(synced_at)",
				...$values
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			$result = $wpdb->query( $sql );
			if ( $result !== false ) {
				$synced += count( $chunk ) - $errors;
			} else {
				$errors += count( $chunk );
			}
		}

		set_transient( self::TRANSIENT_LAST_SYNC, time(), self::SYNC_INTERVAL * 2 );

		$message = sprintf(
			'Catálogo Aveonline sincronizado: %d ciudades procesadas, %d errores.',
			$synced,
			$errors
		);

		if ( class_exists( 'LTMS_Core_Logger' ) ) {
			LTMS_Core_Logger::info( 'aveonline_cities_sync', $message );
		}

		return compact( 'synced', 'errors', 'message' );
	}

	/**
	 * Sincroniza solo si la tabla está vacía o pasaron más de SYNC_INTERVAL segundos.
	 */
	public function maybe_sync(): void {
		$last_sync = get_transient( self::TRANSIENT_LAST_SYNC );

		if ( $last_sync && ( time() - (int) $last_sync ) < self::SYNC_INTERVAL ) {
			return;
		}

		// Si la tabla está vacía, sincronizar siempre
		global $wpdb;
		$table = $wpdb->prefix . 'lt_aveonline_cities';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );

		if ( $count === 0 || ! $last_sync ) {
			$this->sync();
		}
	}

	/**
	 * Handler AJAX de sincronización manual desde el admin.
	 */
	public function ajax_sync(): void {
		check_ajax_referer( 'ltms_aveonline_sync_cities', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
		}

		$result = $this->sync();
		wp_send_json_success( $result );
	}

	// ── Consulta ──────────────────────────────────────────────────

	/**
	 * Devuelve el nombre en formato Aveonline dado un texto libre.
	 * Busca por coincidencia exacta en nombre, luego por nombremun parcial.
	 *
	 * @param string $input Texto libre: "Bogotá", "bogota", "BOGOTA(CUNDINAMARCA)", etc.
	 * @return string Nombre en formato Aveonline o el input si no se encuentra.
	 */
	public static function find_by_name( string $input ): string {
		if ( $input === '' ) {
			return '';
		}

		global $wpdb;
		$table = $wpdb->prefix . 'lt_aveonline_cities';
		$clean = strtoupper( trim( $input ) );

		// 1. Coincidencia exacta en nombre (ya está en formato API)
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exact = $wpdb->get_var( $wpdb->prepare(
			"SELECT nombre FROM `{$table}` WHERE UPPER(nombre) = %s LIMIT 1",
			$clean
		) );
		if ( $exact ) {
			return (string) $exact;
		}

		// 2. Coincidencia en nombremun (nombre corto del municipio)
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$by_mun = $wpdb->get_var( $wpdb->prepare(
			"SELECT nombre FROM `{$table}` WHERE UPPER(nombremun) = %s LIMIT 1",
			$clean
		) );
		if ( $by_mun ) {
			return (string) $by_mun;
		}

		// 3. Búsqueda parcial en nombremun (ej: "Bogotá" → "BOGOTA")
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$partial = $wpdb->get_var( $wpdb->prepare(
			"SELECT nombre FROM `{$table}` WHERE UPPER(nombremun) LIKE %s LIMIT 1",
			$wpdb->esc_like( $clean ) . '%'
		) );
		if ( $partial ) {
			return (string) $partial;
		}

		// 4. Fallback: retornar el input original para no romper el flujo
		return $input;
	}

	/**
	 * Retorna el código DANE de 8 dígitos dado un nombre de ciudad (en cualquier formato).
	 * Primero normaliza el nombre con find_by_name(), luego busca el codigodane.
	 *
	 * @param string $input Texto libre: "Bogotá", "BOGOTA(CUNDINAMARCA)", código DANE de 5 dígitos, etc.
	 * @return string Código DANE de 8 dígitos (ej: "11001000") o '' si no se encuentra.
	 */
	public static function get_dane_code( string $input ): string {
		if ( $input === '' ) {
			return '';
		}

		global $wpdb;
		$table = $wpdb->prefix . 'lt_aveonline_cities';
		$clean = strtoupper( trim( $input ) );

		// Busca por nombre exacto en formato Aveonline primero
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$dane = $wpdb->get_var( $wpdb->prepare(
			"SELECT codigodane FROM `{$table}` WHERE UPPER(nombre) = %s LIMIT 1",
			$clean
		) );
		if ( $dane ) {
			return (string) $dane;
		}

		// Normaliza el nombre y reintenta
		$normalizado = self::find_by_name( $input );
		if ( $normalizado && $normalizado !== $input ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$dane = $wpdb->get_var( $wpdb->prepare(
				"SELECT codigodane FROM `{$table}` WHERE nombre = %s LIMIT 1",
				$normalizado
			) );
			if ( $dane ) {
				return (string) $dane;
			}
		}

		return '';
	}

	/**
	 * Verifica si un nombre ya está en el catálogo de Aveonline (en su formato exacto).
	 *
	 * @param string $nombre Nombre en formato Aveonline. Ej: "MEDELLIN(ANTIOQUIA)".
	 * @return bool
	 */
	public static function exists( string $nombre ): bool {
		if ( $nombre === '' ) {
			return false;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'lt_aveonline_cities';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM `{$table}` WHERE nombre = %s LIMIT 1",
			$nombre
		) );
	}

	/**
	 * Retorna todas las ciudades para un <select>.
	 * Formato: ['BOGOTA(CUNDINAMARCA)' => 'BOGOTA — CUNDINAMARCA', ...]
	 *
	 * @param bool $include_blank Si true, agrega '— Selecciona ciudad —' al inicio.
	 * @return array<string,string>
	 */
	public static function get_options( bool $include_blank = true ): array {
		$cached = get_transient( 'ltms_aveonline_city_options' );
		if ( is_array( $cached ) ) {
			return $include_blank
				? array_merge( [ '' => __( '— Selecciona ciudad —', 'ltms' ) ], $cached )
				: $cached;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'lt_aveonline_cities';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT nombre, nombremun, departamento FROM `{$table}` ORDER BY departamento ASC, nombremun ASC",
			ARRAY_A
		);

		$options = [];
		foreach ( (array) $rows as $row ) {
			$nombre = (string) ( $row['nombre'] ?? '' );
			if ( $nombre !== '' ) {
				$options[ $nombre ] = sprintf( '%s — %s', $row['nombremun'] ?? '', $row['departamento'] ?? '' );
			}
		}

		if ( ! empty( $options ) ) {
			set_transient( 'ltms_aveonline_city_options', $options, 12 * HOUR_IN_SECONDS );
		}

		return $include_blank
			? array_merge( [ '' => __( '— Selecciona ciudad —', 'ltms' ) ], $options )
			: $options;
	}

	/**
	 * Retorna el total de ciudades en el catálogo local.
	 *
	 * @return int
	 */
	public static function count(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'lt_aveonline_cities';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
	}

	/**
	 * Retorna el timestamp de la última sincronización exitosa o null.
	 *
	 * @return int|null Unix timestamp.
	 */
	public static function last_sync_at(): ?int {
		$ts = get_transient( self::TRANSIENT_LAST_SYNC );
		return $ts ? (int) $ts : null;
	}

	/**
	 * Limpia el cache de opciones. Llamar después de una sincronización.
	 */
	public static function flush_options_cache(): void {
		delete_transient( 'ltms_aveonline_city_options' );
	}

	// ── Private ───────────────────────────────────────────────────

	/**
	 * Descarga y decodifica el JSON de ciudades desde Aveonline.
	 *
	 * @return array Raw array de ciudades o vacío si falla.
	 */
	private function fetch_source(): array {
		$response = wp_remote_get( self::SOURCE_URL, [
			'timeout'    => 30,
			'user-agent' => 'LTMS-Plugin/' . ( defined( 'LTMS_VERSION' ) ? LTMS_VERSION : '1.0' ),
		] );

		if ( is_wp_error( $response ) ) {
			if ( class_exists( 'LTMS_Core_Logger' ) ) {
				LTMS_Core_Logger::warning(
					'aveonline_cities_fetch_failed',
					'Error al descargar listadociudades.json: ' . $response->get_error_message()
				);
			}
			return [];
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( (int) $code !== 200 ) {
			if ( class_exists( 'LTMS_Core_Logger' ) ) {
				LTMS_Core_Logger::warning(
					'aveonline_cities_http_error',
					"HTTP {$code} al obtener listadociudades.json"
				);
			}
			return [];
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
			if ( class_exists( 'LTMS_Core_Logger' ) ) {
				LTMS_Core_Logger::warning(
					'aveonline_cities_json_error',
					'JSON inválido en listadociudades.json: ' . json_last_error_msg()
				);
			}
			return [];
		}

		return $data;
	}

	// ── Búsqueda dinámica (API en tiempo real) ────────────────────

	/**
	 * AJAX: busca ciudades en tiempo real via API de Aveonline.
	 *
	 * Disponible para usuarios logueados y visitantes (para el checkout).
	 * Protegido con nonce 'ltms_search_cities'.
	 *
	 * POST params:
	 *   query     (string) Texto a buscar. Mínimo 2 caracteres.
	 *   registros (int)    Máximo de resultados. Default 10.
	 *
	 * Respuesta success: { ciudades: [ {nombre, id, codigoDANE}, ... ] }
	 *
	 * action: ltms_aveonline_search_cities
	 */
	public static function ajax_search_cities(): void {
		check_ajax_referer( 'ltms_search_cities', 'nonce' );

		$query     = sanitize_text_field( wp_unslash( $_POST['query']     ?? '' ) );
		$registros = absint( $_POST['registros'] ?? 10 );

		if ( mb_strlen( $query ) < 2 ) {
			wp_send_json_error( [ 'message' => __( 'Ingresa al menos 2 caracteres para buscar.', 'ltms' ) ] );
		}

		if ( is_numeric( $query ) ) {
			wp_send_json_error( [ 'message' => __( 'El término de búsqueda no puede ser solo números.', 'ltms' ) ] );
		}

		// 1. Intentar primero desde la tabla local (más rápido, sin llamada externa)
		$local = self::search_local( $query, $registros );
		if ( ! empty( $local ) ) {
			wp_send_json_success( [ 'ciudades' => $local, 'source' => 'local' ] );
		}

		// 2. Fallback a la API de Aveonline si la tabla local no tiene resultados
		try {
			/** @var LTMS_Api_Aveonline $api */
			$api     = LTMS_Api_Factory::get( 'aveonline' );
			$results = $api->search_cities( $query, $registros ?: 10 );
			wp_send_json_success( [ 'ciudades' => $results, 'source' => 'api' ] );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	/**
	 * Busca ciudades en la tabla local lt_aveonline_cities.
	 *
	 * @param  string $query     Texto a buscar (LIKE %query%).
	 * @param  int    $limit     Máximo de resultados.
	 * @return array  Array de [ nombre, id, codigoDANE ].
	 */
	private static function search_local( string $query, int $limit = 10 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'lt_aveonline_cities';

		// Verificar que la tabla existe antes de consultar
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return [];
		}

		$like  = '%' . $wpdb->esc_like( $query ) . '%';
		$limit = max( 1, min( $limit, 50 ) ); // cap a 50

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT nombre, codigodane AS codigoDANE FROM {$table} WHERE nombre LIKE %s ORDER BY nombre ASC LIMIT %d",
				$like,
				$limit
			),
			ARRAY_A
		);

		return $rows ?: [];
	}
}
