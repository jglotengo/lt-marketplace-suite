<?php
/**
 * Admin View: Configuración Fiscal — México
 *
 * Permite al administrador actualizar las tasas tributarias de México:
 * IVA, ISR Art. 113-A (plataformas digitales), IEPS, Retención IVA PM.
 * Registra cambios en lt_tax_rates_history.
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
$table_tramos = $wpdb->prefix . 'lt_mx_isr_tramos';
$table_ieps   = $wpdb->prefix . 'lt_mx_ieps_rates';
$table_hist   = $wpdb->prefix . 'lt_tax_rates_history';

/* ── Handle save scalar rates ────────────────────────────────────── */
if ( isset( $_POST['ltms_fiscal_mx_save'] ) ) {
	check_admin_referer( 'ltms_fiscal_mx' );

	$fields = [
		'ltms_mx_iva_general'      => (float) ( $_POST['ltms_mx_iva_general'] ?? 0.16 ),
		'ltms_mx_iva_frontera'     => (float) ( $_POST['ltms_mx_iva_frontera'] ?? 0.08 ),
		'ltms_mx_isr_honorarios'   => (float) ( $_POST['ltms_mx_isr_honorarios'] ?? 0.10 ),
		'ltms_mx_retencion_iva_pm' => (float) ( $_POST['ltms_mx_retencion_iva_pm'] ?? 0.1067 ),
	];

	$decree     = sanitize_text_field( $_POST['decree_reference'] ?? '' );
	$valid_from = sanitize_text_field( $_POST['valid_from'] ?? current_time( 'Y-m-d' ) );

	foreach ( $fields as $key => $new_value ) {
		$old_value = (float) LTMS_Core_Config::get( $key, 0 );
		update_option( $key, $new_value );

		if ( abs( $old_value - $new_value ) > 0.000001 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				$table_hist,
				[
					'country'          => 'MX',
					'rate_key'         => $key,
					'old_value'        => $old_value,
					'new_value'        => $new_value,
					'decree_reference' => $decree,
					'changed_by'       => get_current_user_id(),
					'valid_from'       => $valid_from,
				],
				[ '%s', '%s', '%f', '%f', '%s', '%d', '%s' ]
			);
		}
	}

	$notice = __( 'Configuración fiscal de México guardada.', 'ltms' );
}

/* ── Handle ISR tramo save ───────────────────────────────────────── */
if ( isset( $_POST['ltms_isr_tramo_action'] ) ) {
	check_admin_referer( 'ltms_fiscal_mx' );
	$act = sanitize_key( $_POST['ltms_isr_tramo_action'] );

	if ( $act === 'save' ) {
		$row = [
			'min_amount' => (float) ( $_POST['tramo_min'] ?? 0 ),
			'max_amount' => (float) ( $_POST['tramo_max'] ?? 0 ),
			'rate'       => (float) ( $_POST['tramo_rate'] ?? 0 ),
			'valid_from' => sanitize_text_field( $_POST['tramo_valid_from'] ?? current_time( 'Y-m-d' ) ),
		];
		$row_id = (int) ( $_POST['tramo_id'] ?? 0 );

		if ( $row_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $table_tramos, $row, [ 'id' => $row_id ], [ '%f', '%f', '%f', '%s' ], [ '%d' ] );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert( $table_tramos, $row, [ '%f', '%f', '%f', '%s' ] );
		}
		$notice_tramos = __( 'Tramo ISR guardado.', 'ltms' );
	} elseif ( $act === 'delete' ) {
		$row_id = (int) ( $_POST['tramo_id'] ?? 0 );
		if ( $row_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( $table_tramos, [ 'id' => $row_id ], [ '%d' ] );
			$notice_tramos = __( 'Tramo ISR eliminado.', 'ltms' );
		}
	}
}

/* ── Handle IEPS save ───────────────────────────────────────────── */
if ( isset( $_POST['ltms_ieps_action'] ) ) {
	check_admin_referer( 'ltms_fiscal_mx' );
	$act = sanitize_key( $_POST['ltms_ieps_action'] );

	if ( $act === 'save' ) {
		$row = [
			'category'   => sanitize_text_field( $_POST['ieps_category'] ?? '' ),
			'rate'       => (float) ( $_POST['ieps_rate'] ?? 0 ),
			'unit'       => sanitize_text_field( $_POST['ieps_unit'] ?? 'ad_valorem' ),
			'valid_from' => sanitize_text_field( $_POST['ieps_valid_from'] ?? current_time( 'Y-m-d' ) ),
			'notes'      => sanitize_text_field( $_POST['ieps_notes'] ?? '' ),
		];
		$ieps_id = (int) ( $_POST['ieps_id'] ?? 0 );

		if ( $ieps_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $table_ieps, $row, [ 'id' => $ieps_id ], [ '%s', '%f', '%s', '%s', '%s' ], [ '%d' ] );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert( $table_ieps, $row, [ '%s', '%f', '%s', '%s', '%s' ] );
		}
		$notice_ieps = __( 'Tasa IEPS guardada.', 'ltms' );
	} elseif ( $act === 'delete' ) {
		$ieps_id = (int) ( $_POST['ieps_id'] ?? 0 );
		if ( $ieps_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( $table_ieps, [ 'id' => $ieps_id ], [ '%d' ] );
			$notice_ieps = __( 'Tasa IEPS eliminada.', 'ltms' );
		}
	}
}

/* ── Fetch data ──────────────────────────────────────────────────── */
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$tramos = $wpdb->get_results(
	$wpdb->prepare( "SELECT * FROM `{$table_tramos}` ORDER BY min_amount ASC LIMIT %d", 50 ),
	ARRAY_A
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$ieps_rates = $wpdb->get_results(
	$wpdb->prepare( "SELECT * FROM `{$table_ieps}` ORDER BY category ASC LIMIT %d", 100 ),
	ARRAY_A
);

$v = static function( string $key, float $default ) : float {
	return (float) LTMS_Core_Config::get( $key, $default );
};
?>
<div class="wrap">
	<h1><?php esc_html_e( '🇲🇽 Configuración Fiscal — México', 'ltms' ); ?></h1>
	<p><?php esc_html_e( 'Actualiza tasas de IVA, ISR Art. 113-A, IEPS y Retención IVA para Persona Moral. Los cambios quedan registrados en el historial.', 'ltms' ); ?></p>

	<?php if ( isset( $notice ) ) : ?>
	<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<!-- ── Tasas generales ───────────────────────────────────────── -->
	<form method="post">
		<?php wp_nonce_field( 'ltms_fiscal_mx' ); ?>

		<h2><?php esc_html_e( 'Referencia legal', 'ltms' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><label for="ltms-mx-decree"><?php esc_html_e( 'Decreto / Artículo', 'ltms' ); ?></label></th>
				<td>
					<input type="text" id="ltms-mx-decree" name="decree_reference" class="regular-text"
						   placeholder="<?php esc_attr_e( 'Ej: DOF Art. 113-A LISR 2024', 'ltms' ); ?>">
				</td>
			</tr>
			<tr>
				<th><label for="ltms-mx-valid"><?php esc_html_e( 'Vigencia desde', 'ltms' ); ?></label></th>
				<td><input type="date" id="ltms-mx-valid" name="valid_from" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>"></td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'IVA', 'ltms' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><label for="ltms-mx-iva"><?php esc_html_e( 'IVA General (decimal)', 'ltms' ); ?></label></th>
				<td>
					<input type="number" id="ltms-mx-iva" name="ltms_mx_iva_general" step="0.01" min="0" max="1"
						   value="<?php echo esc_attr( $v( 'ltms_mx_iva_general', 0.16 ) ); ?>" class="small-text">
					<span class="description">16% = 0.16</span>
				</td>
			</tr>
			<tr>
				<th><label for="ltms-mx-iva-front"><?php esc_html_e( 'IVA Zona Fronteriza (decimal)', 'ltms' ); ?></label></th>
				<td>
					<input type="number" id="ltms-mx-iva-front" name="ltms_mx_iva_frontera" step="0.01" min="0" max="1"
						   value="<?php echo esc_attr( $v( 'ltms_mx_iva_frontera', 0.08 ) ); ?>" class="small-text">
					<span class="description">8% = 0.08</span>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'ISR Personas Físicas con Actividades Empresariales', 'ltms' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><label for="ltms-mx-isr-honorarios"><?php esc_html_e( 'ISR Honorarios (decimal)', 'ltms' ); ?></label></th>
				<td>
					<input type="number" id="ltms-mx-isr-honorarios" name="ltms_mx_isr_honorarios" step="0.001" min="0" max="1"
						   value="<?php echo esc_attr( $v( 'ltms_mx_isr_honorarios', 0.10 ) ); ?>" class="small-text">
					<span class="description">10% = 0.10</span>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Retención IVA — Persona Moral', 'ltms' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><label for="ltms-mx-ret-iva-pm"><?php esc_html_e( 'Retención IVA PM (decimal)', 'ltms' ); ?></label></th>
				<td>
					<input type="number" id="ltms-mx-ret-iva-pm" name="ltms_mx_retencion_iva_pm" step="0.0001" min="0" max="1"
						   value="<?php echo esc_attr( $v( 'ltms_mx_retencion_iva_pm', 0.1067 ) ); ?>" class="small-text">
					<span class="description">10.67% = 0.1067 (dos terceras partes del IVA 16%)</span>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Guardar tasas generales México', 'ltms' ), 'primary', 'ltms_fiscal_mx_save' ); ?>
	</form>

	<hr>

	<!-- ── Tramos ISR Art. 113-A ─────────────────────────────────── -->
	<?php if ( isset( $notice_tramos ) ) : ?>
	<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice_tramos ); ?></p></div>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Tramos ISR Art. 113-A (Plataformas digitales)', 'ltms' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Define los rangos de ingreso mensual y sus tasas de retención ISR.', 'ltms' ); ?></p>

	<table class="wp-list-table widefat fixed striped" style="max-width:700px;">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Ingreso mín. (MXN)', 'ltms' ); ?></th>
				<th><?php esc_html_e( 'Ingreso máx. (MXN)', 'ltms' ); ?></th>
				<th><?php esc_html_e( 'Tasa (%)', 'ltms' ); ?></th>
				<th><?php esc_html_e( 'Vigencia desde', 'ltms' ); ?></th>
				<th><?php esc_html_e( 'Acciones', 'ltms' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( empty( $tramos ) ) : ?>
			<tr><td colspan="5"><?php esc_html_e( 'Sin tramos registrados.', 'ltms' ); ?></td></tr>
		<?php else : ?>
			<?php foreach ( $tramos as $tramo ) : ?>
			<tr>
				<td><?php echo esc_html( number_format( (float) $tramo['min_amount'], 0 ) ); ?></td>
				<td><?php echo esc_html( number_format( (float) $tramo['max_amount'], 0 ) ); ?></td>
				<td><?php echo esc_html( number_format( (float) $tramo['rate'] * 100, 2 ) . '%' ); ?></td>
				<td><?php echo esc_html( $tramo['valid_from'] ); ?></td>
				<td>
					<form method="post" style="display:inline;">
						<?php wp_nonce_field( 'ltms_fiscal_mx' ); ?>
						<input type="hidden" name="ltms_isr_tramo_action" value="delete">
						<input type="hidden" name="tramo_id" value="<?php echo esc_attr( $tramo['id'] ); ?>">
						<button type="submit" class="button button-small button-link-delete"
								onclick="return confirm('<?php esc_attr_e( '¿Eliminar tramo?', 'ltms' ); ?>')">
							<?php esc_html_e( 'Eliminar', 'ltms' ); ?>
						</button>
					</form>
				</td>
			</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>

	<h3><?php esc_html_e( 'Agregar tramo ISR', 'ltms' ); ?></h3>
	<form method="post" style="max-width:700px;">
		<?php wp_nonce_field( 'ltms_fiscal_mx' ); ?>
		<input type="hidden" name="ltms_isr_tramo_action" value="save">
		<input type="hidden" name="tramo_id" value="0">
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Ingreso mínimo (MXN)', 'ltms' ); ?></th>
				<td><input type="number" name="tramo_min" step="1" min="0" class="regular-text" value="0"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Ingreso máximo (MXN)', 'ltms' ); ?></th>
				<td>
					<input type="number" name="tramo_max" step="1" min="0" class="regular-text" value="999999999">
					<p class="description"><?php esc_html_e( 'Usa 999999999 para "sin límite".', 'ltms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Tasa (decimal)', 'ltms' ); ?></th>
				<td>
					<input type="number" name="tramo_rate" step="0.001" min="0" max="1" class="small-text" value="0.02">
					<span class="description">Ej: 2% = 0.02</span>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Vigencia desde', 'ltms' ); ?></th>
				<td><input type="date" name="tramo_valid_from" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>"></td>
			</tr>
		</table>
		<?php submit_button( __( 'Agregar tramo', 'ltms' ), 'secondary', 'submit', false ); ?>
	</form>

	<hr>

	<!-- ── IEPS ─────────────────────────────────────────────────── -->
	<?php if ( isset( $notice_ieps ) ) : ?>
	<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice_ieps ); ?></p></div>
	<?php endif; ?>

	<h2><?php esc_html_e( 'IEPS por categoría de producto', 'ltms' ); ?></h2>

	<table class="wp-list-table widefat fixed striped" style="max-width:800px;">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Categoría', 'ltms' ); ?></th>
				<th><?php esc_html_e( 'Tasa (%)', 'ltms' ); ?></th>
				<th><?php esc_html_e( 'Tipo', 'ltms' ); ?></th>
				<th><?php esc_html_e( 'Vigencia', 'ltms' ); ?></th>
				<th><?php esc_html_e( 'Notas', 'ltms' ); ?></th>
				<th><?php esc_html_e( 'Acción', 'ltms' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( empty( $ieps_rates ) ) : ?>
			<tr><td colspan="6"><?php esc_html_e( 'Sin tasas IEPS registradas.', 'ltms' ); ?></td></tr>
		<?php else : ?>
			<?php foreach ( $ieps_rates as $ieps ) : ?>
			<tr>
				<td><?php echo esc_html( $ieps['category'] ); ?></td>
				<td><?php echo esc_html( number_format( (float) $ieps['rate'] * 100, 2 ) . '%' ); ?></td>
				<td><?php echo esc_html( $ieps['unit'] ); ?></td>
				<td><?php echo esc_html( $ieps['valid_from'] ); ?></td>
				<td><?php echo esc_html( $ieps['notes'] ); ?></td>
				<td>
					<form method="post" style="display:inline;">
						<?php wp_nonce_field( 'ltms_fiscal_mx' ); ?>
						<input type="hidden" name="ltms_ieps_action" value="delete">
						<input type="hidden" name="ieps_id" value="<?php echo esc_attr( $ieps['id'] ); ?>">
						<button type="submit" class="button button-small button-link-delete"
								onclick="return confirm('<?php esc_attr_e( '¿Eliminar?', 'ltms' ); ?>')">
							<?php esc_html_e( 'Eliminar', 'ltms' ); ?>
						</button>
					</form>
				</td>
			</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>

	<h3><?php esc_html_e( 'Agregar tasa IEPS', 'ltms' ); ?></h3>
	<form method="post" style="max-width:700px;">
		<?php wp_nonce_field( 'ltms_fiscal_mx' ); ?>
		<input type="hidden" name="ltms_ieps_action" value="save">
		<input type="hidden" name="ieps_id" value="0">
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Categoría del producto', 'ltms' ); ?></th>
				<td>
					<input type="text" name="ieps_category" maxlength="100" class="regular-text"
						   placeholder="<?php esc_attr_e( 'Ej: bebidas_azucaradas, cigarros', 'ltms' ); ?>">
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Tasa (decimal)', 'ltms' ); ?></th>
				<td>
					<input type="number" name="ieps_rate" step="0.001" min="0" max="2" class="small-text" value="0.08">
					<span class="description">Ej: 8% = 0.08</span>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Tipo de tasa', 'ltms' ); ?></th>
				<td>
					<select name="ieps_unit">
						<option value="ad_valorem"><?php esc_html_e( 'Ad valorem (%)', 'ltms' ); ?></option>
						<option value="cuota_fija"><?php esc_html_e( 'Cuota fija (MXN/unidad)', 'ltms' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Vigencia desde', 'ltms' ); ?></th>
				<td><input type="date" name="ieps_valid_from" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Notas', 'ltms' ); ?></th>
				<td><input type="text" name="ieps_notes" maxlength="255" class="large-text"></td>
			</tr>
		</table>
		<?php submit_button( __( 'Agregar IEPS', 'ltms' ), 'secondary', 'submit', false ); ?>
	</form>

	<hr>
	<p class="description">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=ltms-tax-history&country=MX' ) ); ?>">
			<?php esc_html_e( 'Ver historial de cambios — México →', 'ltms' ); ?>
		</a>
	</p>
</div>
