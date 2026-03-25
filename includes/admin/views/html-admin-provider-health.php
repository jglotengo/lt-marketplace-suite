<?php
/**
 * Admin View: Dashboard de Salud de Proveedores
 *
 * @package LTMS
 * @version 1.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$providers  = [ 'stripe', 'openpay', 'addi', 'aveonline', 'heka', 'uber' ];
$since_24h  = gmdate( 'Y-m-d H:i:s', time() - 86400 );
$since_1h   = gmdate( 'Y-m-d H:i:s', time() - 3600 );

// Handle circuit breaker reset
if ( isset( $_POST['ltms_reset_provider'] ) ) {
	$p = sanitize_key( wp_unslash( $_POST['ltms_reset_provider'] ) );
	check_admin_referer( 'ltms_reset_circuit_' . $p );
	delete_transient( 'ltms_circuit_' . $p . '_down' );
	echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( 'Circuit breaker de %s reseteado.', strtoupper( $p ) ) ) . '</p></div>';
}

/**
 * Get provider stats for a time window.
 */
function ltms_provider_stats( string $provider, string $since ): array {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT COUNT(*) as total,
			        SUM(status = 'success') as successes,
			        ROUND(AVG(latency_ms), 0) as avg_latency
			 FROM `{$wpdb->prefix}lt_provider_health`
			 WHERE provider = %s AND created_at >= %s",
			$provider,
			$since
		),
		ARRAY_A
	);
	return $row ?: [ 'total' => 0, 'successes' => 0, 'avg_latency' => 0 ];
}
?>
<div class="wrap">
	<h1><?php esc_html_e( '🩺 Dashboard de Salud de Proveedores', 'ltms' ); ?></h1>
	<p><?php esc_html_e( 'Monitoreo en tiempo real de pasarelas de pago y proveedores logísticos (últimas 24 horas).', 'ltms' ); ?></p>

	<div style="display:flex;flex-wrap:wrap;gap:16px;margin-top:16px;">
	<?php foreach ( $providers as $provider ) :
		$stats_24h = ltms_provider_stats( $provider, $since_24h );
		$total     = max( 1, (int) ( $stats_24h['total'] ?? 0 ) );
		$successes = (int) ( $stats_24h['successes'] ?? 0 );
		$uptime    = round( ( $successes / $total ) * 100, 1 );
		$avg_lat   = (int) ( $stats_24h['avg_latency'] ?? 0 );
		$is_down   = (bool) get_transient( 'ltms_circuit_' . $provider . '_down' );
		$dot       = $is_down ? '🔴' : ( $uptime >= 95 ? '🟢' : '🟡' );
		$status    = $is_down ? '⛔ Down (Circuit Breaker)' : '✅ Activo';
		$alert     = ( ! $is_down && $uptime < 95 && (int) $stats_24h['total'] > 0 );
	?>
	<div class="card" style="min-width:280px;max-width:340px;padding:16px;">
		<?php if ( $alert ) : ?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( '⚠️ Uptime por debajo del 95%', 'ltms' ); ?></p></div>
		<?php endif; ?>
		<h3 style="margin-top:0;"><?php echo esc_html( $dot . ' ' . strtoupper( $provider ) ); ?></h3>
		<table class="widefat striped" style="width:100%;">
			<tr><td><strong><?php esc_html_e( 'Uptime 24h', 'ltms' ); ?></strong></td><td><?php echo esc_html( $uptime . '%' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Latencia prom.', 'ltms' ); ?></strong></td><td><?php echo esc_html( $avg_lat . ' ms' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Llamadas 24h', 'ltms' ); ?></strong></td><td><?php echo esc_html( (string) $stats_24h['total'] ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Estado', 'ltms' ); ?></strong></td><td><?php echo esc_html( $status ); ?></td></tr>
		</table>
		<?php if ( $is_down ) : ?>
		<form method="post" style="margin-top:8px;">
			<?php wp_nonce_field( 'ltms_reset_circuit_' . $provider ); ?>
			<input type="hidden" name="ltms_reset_provider" value="<?php echo esc_attr( $provider ); ?>">
			<input type="submit" class="button button-secondary" value="<?php esc_attr_e( 'Resetear Circuit Breaker', 'ltms' ); ?>">
		</form>
		<?php endif; ?>
	</div>
	<?php endforeach; ?>
	</div>

	<hr>
	<h2><?php esc_html_e( 'Últimos 50 eventos', 'ltms' ); ?></h2>
	<?php
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$events = $wpdb->get_results(
		"SELECT provider, status, latency_ms, error_code, created_at
		 FROM `{$wpdb->prefix}lt_provider_health`
		 ORDER BY id DESC LIMIT 50",
		ARRAY_A
	);
	?>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Proveedor', 'ltms' ); ?></th>
				<th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
				<th><?php esc_html_e( 'Latencia', 'ltms' ); ?></th>
				<th><?php esc_html_e( 'Error', 'ltms' ); ?></th>
				<th><?php esc_html_e( 'Fecha', 'ltms' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $events as $ev ) :
			$status_icon = $ev['status'] === 'success' ? '✅' : ( $ev['status'] === 'timeout' ? '⏱️' : '❌' );
		?>
		<tr>
			<td><?php echo esc_html( strtoupper( $ev['provider'] ) ); ?></td>
			<td><?php echo esc_html( $status_icon . ' ' . $ev['status'] ); ?></td>
			<td><?php echo esc_html( $ev['latency_ms'] . ' ms' ); ?></td>
			<td><?php echo esc_html( $ev['error_code'] ?: '—' ); ?></td>
			<td><?php echo esc_html( $ev['created_at'] ); ?></td>
		</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</div>
