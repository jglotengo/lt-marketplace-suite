<?php
/**
 * Admin View: Dashboard de Salud de Proveedores
 *
 * @package LTMS
 * @version 1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

/**
 * Mapa completo de proveedores: slug => etiqueta visible
 * Sincronizado con todas las pestañas de Configuración LTMS.
 */
$provider_map = [
	// Pasarelas de pago
	'stripe'      => [ 'label' => 'STRIPE',       'group' => '💳 Pasarelas de Pago' ],
	'openpay'     => [ 'label' => 'OPENPAY',      'group' => '💳 Pasarelas de Pago' ],
	'addi'        => [ 'label' => 'ADDI',         'group' => '💳 Pasarelas de Pago' ],
	// Logística
	'aveonline'   => [ 'label' => 'AVEONLINE',    'group' => '🚚 Logística' ],
	'heka'        => [ 'label' => 'HEKA',         'group' => '🚚 Logística' ],
	'uber'        => [ 'label' => 'UBER DIRECT',  'group' => '🚚 Logística' ],
	'deprisa'     => [ 'label' => 'DEPRISA',      'group' => '🚚 Logística' ],
	// Contabilidad / Fiscal
	'alegra'      => [ 'label' => 'ALEGRA',       'group' => '🧾 Contabilidad / Fiscal' ],
	'siigo'       => [ 'label' => 'SIIGO ERP',    'group' => '🧾 Contabilidad / Fiscal' ],
	// Seguros & Firma
	'xcover'      => [ 'label' => 'XCOVER',       'group' => '🛡️ Seguros & Firma' ],
	'zapsign'     => [ 'label' => 'ZAPSIGN',      'group' => '🛡️ Seguros & Firma' ],
	// Almacenamiento
	'backblaze'   => [ 'label' => 'BACKBLAZE B2', 'group' => '☁️ Almacenamiento' ],
	// Autenticación
	'google_oauth' => [ 'label' => 'GOOGLE OAUTH', 'group' => '🔐 Autenticación' ],
];

$providers  = array_keys( $provider_map );
$since_24h  = gmdate( 'Y-m-d H:i:s', time() - 86400 );
$since_1h   = gmdate( 'Y-m-d H:i:s', time() - 3600 );

// Handle circuit breaker reset
if ( isset( $_POST['ltms_reset_provider'] ) ) {
	$p = sanitize_key( wp_unslash( $_POST['ltms_reset_provider'] ) );
	check_admin_referer( 'ltms_reset_circuit_' . $p );
	delete_transient( 'ltms_circuit_' . $p . '_down' );
	echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( 'Circuit breaker de %s reseteado.', strtoupper( $p ) ) ) . '</p></div>';
}

if ( ! function_exists( 'ltms_provider_stats' ) ) :
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
endif;

// Agrupar proveedores por grupo
$groups = [];
foreach ( $provider_map as $slug => $info ) {
	$groups[ $info['group'] ][] = $slug;
}
?>
<div class="wrap">
	<h1><?php esc_html_e( '🩺 Dashboard de Salud de Proveedores', 'ltms' ); ?></h1>
	<p><?php esc_html_e( 'Monitoreo en tiempo real de todas las integraciones activas (últimas 24 horas).', 'ltms' ); ?></p>

	<?php foreach ( $groups as $group_label => $group_slugs ) : ?>
	<h2 style="margin-top:28px;margin-bottom:10px;border-bottom:1px solid #ddd;padding-bottom:6px;">
		<?php echo esc_html( $group_label ); ?>
	</h2>
	<div style="display:flex;flex-wrap:wrap;gap:16px;margin-bottom:8px;">
	<?php foreach ( $group_slugs as $provider ) :
		$info      = $provider_map[ $provider ];
		$stats_24h = ltms_provider_stats( $provider, $since_24h );
		$total     = (int) ( $stats_24h['total'] ?? 0 );
		$has_data  = $total > 0;
		$successes = (int) ( $stats_24h['successes'] ?? 0 );
		$uptime    = $has_data ? round( ( $successes / $total ) * 100, 1 ) : null;
		$avg_lat   = (int) ( $stats_24h['avg_latency'] ?? 0 );
		$is_down   = (bool) get_transient( 'ltms_circuit_' . $provider . '_down' );
		$dot       = $is_down ? '🔴' : ( ! $has_data ? '🟡' : ( $uptime >= 95 ? '🟢' : '🟡' ) );
		$status    = $is_down ? '⛔ Down (Circuit Breaker)' : '✅ Activo';
		$alert     = ( ! $is_down && $has_data && $uptime < 95 );
	?>
	<div class="card" style="min-width:260px;max-width:320px;padding:16px;">
		<?php if ( $alert ) : ?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( '⚠️ Uptime por debajo del 95%', 'ltms' ); ?></p></div>
		<?php endif; ?>
		<h3 style="margin-top:0;"><?php echo esc_html( $dot . ' ' . $info['label'] ); ?></h3>
		<table class="widefat striped" style="width:100%;">
			<tr><td><strong><?php esc_html_e( 'Uptime 24h', 'ltms' ); ?></strong></td><td><?php echo $has_data ? esc_html( $uptime . '%' ) : '<em style="color:#888">Sin datos aún</em>'; // phpcs:ignore ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Latencia prom.', 'ltms' ); ?></strong></td><td><?php echo $has_data ? esc_html( $avg_lat . ' ms' ) : '<em style="color:#888">—</em>'; // phpcs:ignore ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Llamadas 24h', 'ltms' ); ?></strong></td><td><?php echo esc_html( (string) $total ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Estado', 'ltms' ); ?></strong></td><td><?php echo esc_html( $status ); ?></td></tr>
		</table>
		<button type="button"
			class="button button-secondary ltms-test-provider-btn"
			data-provider="<?php echo esc_attr( $provider ); ?>"
			style="margin-top:10px;width:100%;">
			🔌 <?php echo esc_html( sprintf( __( 'Probar %s', 'ltms' ), $info['label'] ) ); ?>
		</button>
		<span class="ltms-test-result-<?php echo esc_attr( $provider ); ?>" style="display:none;font-size:12px;margin-top:6px;display:block;"></span>
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
	<?php endforeach; ?>

	<script>
	jQuery(function($) {
		$('.ltms-test-provider-btn').on('click', function() {
			var $btn      = $(this);
			var provider  = $btn.data('provider');
			var $result   = $('.ltms-test-result-' + provider);
			$btn.prop('disabled', true).text('⏳ Probando...');
			$.post(ajaxurl, {
				action:   'ltms_test_api_connection',
				nonce:    ltmsAdmin.nonce,
				provider: provider,
			}, function(res) {
				if (res.success) {
					var lat = res.data && res.data.latency_ms ? ' (' + res.data.latency_ms + ' ms)' : '';
					$result.show().css('color','#27ae60').html('✅ OK' + lat + ' — recarga para ver el uptime actualizado');
					$btn.text('✅ Conectado').css('border-color','#27ae60');
				} else {
					$result.show().css('color','#e74c3c').html('❌ ' + (res.data || 'Error de conexión'));
					$btn.text('❌ Error').css('border-color','#e74c3c');
				}
				setTimeout(function(){ $btn.prop('disabled', false); }, 3000);
			}).fail(function() {
				$result.show().css('color','#e74c3c').html('❌ Error de red');
				$btn.prop('disabled', false).text('🔌 Probar ' + provider.toUpperCase());
			});
		});
	});
	</script>

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
