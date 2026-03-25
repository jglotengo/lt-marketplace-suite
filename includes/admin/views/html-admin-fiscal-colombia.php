<?php
/**
 * Admin View: Configuración Fiscal — Colombia
 *
 * Permite al administrador actualizar las tasas tributarias de Colombia
 * (UVT, IVA, ReteFuente, ReteIVA, ReteICA, Impoconsumo) sin necesidad
 * de editar código. Registra cambios en lt_tax_rates_history.
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

/* ── Handle save ─────────────────────────────────────────────────── */
if ( isset( $_POST['ltms_fiscal_co_save'] ) ) {
	check_admin_referer( 'ltms_fiscal_co' );

	$fields = [
		'ltms_uvt_valor'                       => (float) ( $_POST['ltms_uvt_valor'] ?? 49799 ),
		'ltms_iva_general'                     => (float) ( $_POST['ltms_iva_general'] ?? 0.19 ),
		'ltms_iva_reducido'                    => (float) ( $_POST['ltms_iva_reducido'] ?? 0.05 ),
		'ltms_retefuente_honorarios'           => (float) ( $_POST['ltms_retefuente_honorarios'] ?? 0.11 ),
		'ltms_retefuente_servicios'            => (float) ( $_POST['ltms_retefuente_servicios'] ?? 0.04 ),
		'ltms_retefuente_compras'              => (float) ( $_POST['ltms_retefuente_compras'] ?? 0.025 ),
		'ltms_retefuente_tech'                 => (float) ( $_POST['ltms_retefuente_tech'] ?? 0.06 ),
		'ltms_reteiva_rate'                    => (float) ( $_POST['ltms_reteiva_rate'] ?? 0.15 ),
		'ltms_impoconsumo_rate'                => (float) ( $_POST['ltms_impoconsumo_rate'] ?? 0.08 ),
		'ltms_retefuente_min_compras_uvt'      => (float) ( $_POST['ltms_retefuente_min_compras_uvt'] ?? 10.666 ),
		'ltms_retefuente_min_servicios_uvt'    => (float) ( $_POST['ltms_retefuente_min_servicios_uvt'] ?? 2.666 ),
		'ltms_sagrilaft_uvt_threshold'         => (float) ( $_POST['ltms_sagrilaft_uvt_threshold'] ?? 10000 ),
	];

	global $wpdb;
	$history_table = $wpdb->prefix . 'lt_tax_rates_history';
	$decree        = sanitize_text_field( $_POST['decree_reference'] ?? '' );
	$valid_from    = sanitize_text_field( $_POST['valid_from'] ?? current_time( 'Y-m-d' ) );

	foreach ( $fields as $key => $new_value ) {
		$old_value = (float) LTMS_Core_Config::get( $key, 0 );
		update_option( $key, $new_value );

		if ( abs( $old_value - $new_value ) > 0.000001 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				$history_table,
				[
					'country'          => 'CO',
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

	$notice = __( 'Configuración fiscal de Colombia guardada correctamente.', 'ltms' );
}

/* ── Current values ──────────────────────────────────────────────── */
$v = static function( string $key, float $default ) : float {
	return (float) LTMS_Core_Config::get( $key, $default );
};
?>
<div class="wrap">
	<h1><?php esc_html_e( '🇨🇴 Configuración Fiscal — Colombia', 'ltms' ); ?></h1>
	<p><?php esc_html_e( 'Actualiza las tasas tributarias vigentes. Los cambios se registran en el historial con el decreto de referencia.', 'ltms' ); ?></p>

	<?php if ( isset( $notice ) ) : ?>
	<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<form method="post">
		<?php wp_nonce_field( 'ltms_fiscal_co' ); ?>

		<h2><?php esc_html_e( 'Referencia del decreto', 'ltms' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><label for="ltms-decree"><?php esc_html_e( 'Decreto / Resolución', 'ltms' ); ?></label></th>
				<td>
					<input type="text" id="ltms-decree" name="decree_reference" class="regular-text"
						   placeholder="<?php esc_attr_e( 'Ej: Decreto 2229/2024', 'ltms' ); ?>">
				</td>
			</tr>
			<tr>
				<th><label for="ltms-valid-from"><?php esc_html_e( 'Vigencia desde', 'ltms' ); ?></label></th>
				<td>
					<input type="date" id="ltms-valid-from" name="valid_from"
						   value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>">
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Unidad de Valor Tributario (UVT)', 'ltms' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><label for="ltms-uvt-valor"><?php esc_html_e( 'Valor UVT (COP)', 'ltms' ); ?></label></th>
				<td>
					<input type="number" id="ltms-uvt-valor" name="ltms_uvt_valor" step="1"
						   value="<?php echo esc_attr( $v( 'ltms_uvt_valor', 49799.0 ) ); ?>" class="regular-text">
					<p class="description"><?php esc_html_e( 'UVT 2025 = $49.799 (Decreto 2229/2024)', 'ltms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="ltms-sagrilaft-uvt"><?php esc_html_e( 'Umbral SAGRILAFT (# UVT)', 'ltms' ); ?></label></th>
				<td>
					<input type="number" id="ltms-sagrilaft-uvt" name="ltms_sagrilaft_uvt_threshold" step="100"
						   value="<?php echo esc_attr( $v( 'ltms_sagrilaft_uvt_threshold', 10000.0 ) ); ?>" class="small-text">
					<p class="description"><?php esc_html_e( 'Retiros ≥ (UVT × este valor) aparecerán como alertas SAGRILAFT. Default: 10.000 UVT = ~$497.990.000 COP (2025).', 'ltms' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'IVA', 'ltms' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><label for="ltms-iva-general"><?php esc_html_e( 'IVA General (decimal)', 'ltms' ); ?></label></th>
				<td>
					<input type="number" id="ltms-iva-general" name="ltms_iva_general" step="0.01" min="0" max="1"
						   value="<?php echo esc_attr( $v( 'ltms_iva_general', 0.19 ) ); ?>" class="small-text">
					<span class="description"><?php esc_html_e( 'Actual: 19% = 0.19', 'ltms' ); ?></span>
				</td>
			</tr>
			<tr>
				<th><label for="ltms-iva-reducido"><?php esc_html_e( 'IVA Reducido (decimal)', 'ltms' ); ?></label></th>
				<td>
					<input type="number" id="ltms-iva-reducido" name="ltms_iva_reducido" step="0.01" min="0" max="1"
						   value="<?php echo esc_attr( $v( 'ltms_iva_reducido', 0.05 ) ); ?>" class="small-text">
					<span class="description"><?php esc_html_e( 'Actual: 5% = 0.05', 'ltms' ); ?></span>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Retención en la Fuente', 'ltms' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><label for="ltms-rete-honorarios"><?php esc_html_e( 'Honorarios (decimal)', 'ltms' ); ?></label></th>
				<td>
					<input type="number" id="ltms-rete-honorarios" name="ltms_retefuente_honorarios" step="0.001" min="0" max="1"
						   value="<?php echo esc_attr( $v( 'ltms_retefuente_honorarios', 0.11 ) ); ?>" class="small-text">
					<span class="description">11% = 0.11</span>
				</td>
			</tr>
			<tr>
				<th><label for="ltms-rete-servicios"><?php esc_html_e( 'Servicios (decimal)', 'ltms' ); ?></label></th>
				<td>
					<input type="number" id="ltms-rete-servicios" name="ltms_retefuente_servicios" step="0.001" min="0" max="1"
						   value="<?php echo esc_attr( $v( 'ltms_retefuente_servicios', 0.04 ) ); ?>" class="small-text">
					<span class="description">4% = 0.04</span>
				</td>
			</tr>
			<tr>
				<th><label for="ltms-rete-compras"><?php esc_html_e( 'Compras (decimal)', 'ltms' ); ?></label></th>
				<td>
					<input type="number" id="ltms-rete-compras" name="ltms_retefuente_compras" step="0.001" min="0" max="1"
						   value="<?php echo esc_attr( $v( 'ltms_retefuente_compras', 0.025 ) ); ?>" class="small-text">
					<span class="description">2.5% = 0.025</span>
				</td>
			</tr>
			<tr>
				<th><label for="ltms-rete-tech"><?php esc_html_e( 'Servicios tecnológicos (decimal)', 'ltms' ); ?></label></th>
				<td>
					<input type="number" id="ltms-rete-tech" name="ltms_retefuente_tech" step="0.001" min="0" max="1"
						   value="<?php echo esc_attr( $v( 'ltms_retefuente_tech', 0.06 ) ); ?>" class="small-text">
					<span class="description">6% = 0.06</span>
				</td>
			</tr>
			<tr>
				<th><label for="ltms-rete-min-compras"><?php esc_html_e( 'Umbral compras (# UVT)', 'ltms' ); ?></label></th>
				<td>
					<input type="number" id="ltms-rete-min-compras" name="ltms_retefuente_min_compras_uvt" step="0.001"
						   value="<?php echo esc_attr( $v( 'ltms_retefuente_min_compras_uvt', 10.666 ) ); ?>" class="small-text">
					<span class="description"><?php esc_html_e( 'UVT × este valor = monto mínimo para aplicar ReteFuente en compras. Default: 10.666 UVT', 'ltms' ); ?></span>
				</td>
			</tr>
			<tr>
				<th><label for="ltms-rete-min-servicios"><?php esc_html_e( 'Umbral servicios (# UVT)', 'ltms' ); ?></label></th>
				<td>
					<input type="number" id="ltms-rete-min-servicios" name="ltms_retefuente_min_servicios_uvt" step="0.001"
						   value="<?php echo esc_attr( $v( 'ltms_retefuente_min_servicios_uvt', 2.666 ) ); ?>" class="small-text">
					<span class="description"><?php esc_html_e( 'Default: 2.666 UVT', 'ltms' ); ?></span>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'ReteIVA e Impoconsumo', 'ltms' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><label for="ltms-reteiva"><?php esc_html_e( 'ReteIVA (decimal)', 'ltms' ); ?></label></th>
				<td>
					<input type="number" id="ltms-reteiva" name="ltms_reteiva_rate" step="0.01" min="0" max="1"
						   value="<?php echo esc_attr( $v( 'ltms_reteiva_rate', 0.15 ) ); ?>" class="small-text">
					<span class="description">15% del IVA = 0.15</span>
				</td>
			</tr>
			<tr>
				<th><label for="ltms-impoconsumo"><?php esc_html_e( 'Impoconsumo (decimal)', 'ltms' ); ?></label></th>
				<td>
					<input type="number" id="ltms-impoconsumo" name="ltms_impoconsumo_rate" step="0.01" min="0" max="1"
						   value="<?php echo esc_attr( $v( 'ltms_impoconsumo_rate', 0.08 ) ); ?>" class="small-text">
					<span class="description">8% = 0.08 (restaurantes, bebidas, etc.)</span>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Guardar configuración fiscal Colombia', 'ltms' ), 'primary', 'ltms_fiscal_co_save' ); ?>
	</form>

	<hr>
	<p class="description">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=ltms-tax-history&country=CO' ) ); ?>">
			<?php esc_html_e( 'Ver historial de cambios — Colombia →', 'ltms' ); ?>
		</a>
	</p>
</div>
