<?php
/**
 * Admin View: Historial de Cambios de Tasas Tributarias
 *
 * Muestra la tabla lt_tax_rates_history con filtros por país,
 * clave de tasa y fecha. Solo lectura.
 *
 * @package LTMS
 * @version 1.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'Sin permiso.', 'ltms' ) );
}

global $wpdb;
$table = $wpdb->prefix . 'lt_tax_rates_history';

$filter_country = isset( $_GET['country'] ) ? sanitize_text_field( $_GET['country'] ) : '';
$filter_key     = isset( $_GET['rate_key'] ) ? sanitize_text_field( $_GET['rate_key'] ) : '';
$filter_from    = isset( $_GET['from'] )     ? sanitize_text_field( $_GET['from'] ) : gmdate( 'Y-m-01' );
$filter_to      = isset( $_GET['to'] )       ? sanitize_text_field( $_GET['to'] ) : gmdate( 'Y-m-d' );

$where  = ' WHERE valid_from BETWEEN %s AND %s';
$params = [ $filter_from, $filter_to . ' 23:59:59' ];

if ( $filter_country ) {
	$where  .= ' AND country = %s';
	$params[] = $filter_country;
}
if ( $filter_key ) {
	$where  .= ' AND rate_key LIKE %s';
	$params[] = '%' . $wpdb->esc_like( $filter_key ) . '%';
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$rows = $wpdb->get_results(
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->prepare(
		"SELECT h.*, u.display_name
		 FROM `{$table}` h
		 LEFT JOIN {$wpdb->users} u ON u.ID = h.changed_by"
		. $where .
		' ORDER BY h.id DESC LIMIT 200',
		...$params
	),
	ARRAY_A
);
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Historial de Tasas Tributarias', 'ltms' ); ?></h1>
	<p><?php esc_html_e( 'Registro inmutable de todos los cambios de tasas fiscales. Se genera automáticamente al guardar las páginas de configuración fiscal.', 'ltms' ); ?></p>

	<!-- ── Filtros ───────────────────────────────────────────────── -->
	<form method="get" style="margin-bottom:16px;">
		<input type="hidden" name="page" value="ltms-tax-history">
		<table class="form-table" style="max-width:900px;">
			<tr>
				<th style="width:130px;"><label><?php esc_html_e( 'País', 'ltms' ); ?></label></th>
				<td>
					<select name="country">
						<option value=""><?php esc_html_e( 'Todos', 'ltms' ); ?></option>
						<option value="CO" <?php selected( $filter_country, 'CO' ); ?>>Colombia</option>
						<option value="MX" <?php selected( $filter_country, 'MX' ); ?>>México</option>
					</select>
				</td>
				<th style="width:160px;"><label><?php esc_html_e( 'Clave de tasa', 'ltms' ); ?></label></th>
				<td>
					<input type="text" name="rate_key" value="<?php echo esc_attr( $filter_key ); ?>"
						   placeholder="<?php esc_attr_e( 'Ej: ltms_uvt_valor', 'ltms' ); ?>" class="regular-text">
				</td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'Desde', 'ltms' ); ?></label></th>
				<td><input type="date" name="from" value="<?php echo esc_attr( $filter_from ); ?>"></td>
				<th><label><?php esc_html_e( 'Hasta', 'ltms' ); ?></label></th>
				<td><input type="date" name="to" value="<?php echo esc_attr( $filter_to ); ?>"></td>
			</tr>
		</table>
		<?php submit_button( __( 'Filtrar', 'ltms' ), 'secondary', 'filter', false ); ?>
	</form>

	<!-- ── Tabla de resultados ───────────────────────────────────── -->
	<?php if ( empty( $rows ) ) : ?>
		<p><?php esc_html_e( 'No hay registros de cambios para los filtros seleccionados.', 'ltms' ); ?></p>
	<?php else : ?>
	<p class="description">
		<?php echo esc_html( sprintf(
			/* translators: %d: number of records */
			__( 'Mostrando %d registros (máx. 200).', 'ltms' ),
			count( $rows )
		) ); ?>
	</p>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th style="width:40px;">ID</th>
				<th style="width:60px;"><?php esc_html_e( 'País', 'ltms' ); ?></th>
				<th><?php esc_html_e( 'Clave de tasa', 'ltms' ); ?></th>
				<th><?php esc_html_e( 'Valor anterior', 'ltms' ); ?></th>
				<th><?php esc_html_e( 'Valor nuevo', 'ltms' ); ?></th>
				<th><?php esc_html_e( 'Decreto', 'ltms' ); ?></th>
				<th><?php esc_html_e( 'Modificado por', 'ltms' ); ?></th>
				<th><?php esc_html_e( 'Vigencia desde', 'ltms' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $rows as $row ) :
			$delta = (float) $row['new_value'] - (float) $row['old_value'];
			$delta_class = $delta > 0 ? 'color:#d63638;' : ( $delta < 0 ? 'color:#00a32a;' : '' );
		?>
		<tr>
			<td><?php echo esc_html( $row['id'] ); ?></td>
			<td><?php echo esc_html( $row['country'] ); ?></td>
			<td><code><?php echo esc_html( $row['rate_key'] ); ?></code></td>
			<td><?php echo esc_html( number_format( (float) $row['old_value'], 6 ) ); ?></td>
			<td style="<?php echo esc_attr( $delta_class ); ?>">
				<strong><?php echo esc_html( number_format( (float) $row['new_value'], 6 ) ); ?></strong>
				<?php if ( $delta !== 0.0 ) : ?>
				<small>(<?php echo $delta > 0 ? '+' : ''; ?><?php echo esc_html( number_format( $delta, 6 ) ); ?>)</small>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( $row['decree_reference'] ?: '—' ); ?></td>
			<td><?php echo esc_html( $row['display_name'] ?: '—' ); ?></td>
			<td><?php echo esc_html( $row['valid_from'] ); ?></td>
		</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>

	<p class="description" style="margin-top:12px;">
		<em><?php esc_html_e( 'Este registro no puede modificarse ni eliminarse. Sirve como trazabilidad de auditoría.', 'ltms' ); ?></em>
	</p>
</div>
