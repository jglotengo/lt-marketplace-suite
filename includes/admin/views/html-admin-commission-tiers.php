<?php
/**
 * Admin View: Gestión de Niveles de Comisión
 *
 * CRUD para la tabla lt_commission_tiers. Permite al admin
 * configurar los rangos de volumen de ventas y sus tasas de comisión
 * por país sin necesidad de desplegar código.
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
$table = $wpdb->prefix . 'lt_commission_tiers';

/* ── Handle POST actions ─────────────────────────────────────────── */
if ( isset( $_POST['ltms_tier_action'] ) ) {
	check_admin_referer( 'ltms_commission_tiers' );
	$action = sanitize_key( $_POST['ltms_tier_action'] );

	if ( $action === 'save' ) {
		$data = [
			'country'    => sanitize_text_field( $_POST['country'] ?? 'CO' ),
			'min_amount' => (float) ( $_POST['min_amount'] ?? 0 ),
			'max_amount' => (float) ( $_POST['max_amount'] ?? 0 ),
			'rate'       => (float) ( $_POST['rate'] ?? 0 ),
			'label'      => sanitize_text_field( $_POST['label'] ?? '' ),
			'currency'   => sanitize_text_field( $_POST['currency'] ?? 'COP' ),
			'is_active'  => isset( $_POST['is_active'] ) ? 1 : 0,
			'sort_order' => (int) ( $_POST['sort_order'] ?? 0 ),
		];
		$tier_id = (int) ( $_POST['tier_id'] ?? 0 );

		if ( $tier_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $table, $data, [ 'id' => $tier_id ], [ '%s', '%f', '%f', '%f', '%s', '%s', '%d', '%d' ], [ '%d' ] );
			$notice = __( 'Nivel actualizado.', 'ltms' );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert( $table, $data, [ '%s', '%f', '%f', '%f', '%s', '%s', '%d', '%d' ] );
			$notice = __( 'Nivel creado.', 'ltms' );
		}
	} elseif ( $action === 'delete' ) {
		$tier_id = (int) ( $_POST['tier_id'] ?? 0 );
		if ( $tier_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( $table, [ 'id' => $tier_id ], [ '%d' ] );
			$notice = __( 'Nivel eliminado.', 'ltms' );
		}
	}
}

/* ── Fetch tiers ─────────────────────────────────────────────────── */
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$tiers = $wpdb->get_results(
	$wpdb->prepare( "SELECT * FROM `{$table}` ORDER BY country ASC, sort_order ASC LIMIT %d", 200 ),
	ARRAY_A
);

/* ── Edit mode ───────────────────────────────────────────────────── */
$editing = null;
if ( isset( $_GET['edit'] ) ) {
	$edit_id = (int) $_GET['edit'];
	foreach ( $tiers as $t ) {
		if ( (int) $t['id'] === $edit_id ) {
			$editing = $t;
			break;
		}
	}
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Niveles de Comisión', 'ltms' ); ?></h1>
	<p><?php esc_html_e( 'Define las tasas de comisión de la plataforma según el volumen de ventas mensuales del vendedor.', 'ltms' ); ?></p>

	<?php if ( isset( $notice ) ) : ?>
	<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<!-- ── Tabla de niveles ──────────────────────────────────────── -->
	<h2><?php esc_html_e( 'Niveles configurados', 'ltms' ); ?></h2>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'País', 'ltms' ); ?></th>
				<th><?php esc_html_e( 'Volumen mín.', 'ltms' ); ?></th>
				<th><?php esc_html_e( 'Volumen máx.', 'ltms' ); ?></th>
				<th><?php esc_html_e( 'Tasa (%)', 'ltms' ); ?></th>
				<th><?php esc_html_e( 'Etiqueta', 'ltms' ); ?></th>
				<th><?php esc_html_e( 'Moneda', 'ltms' ); ?></th>
				<th><?php esc_html_e( 'Activo', 'ltms' ); ?></th>
				<th><?php esc_html_e( 'Orden', 'ltms' ); ?></th>
				<th><?php esc_html_e( 'Acciones', 'ltms' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( empty( $tiers ) ) : ?>
			<tr><td colspan="9"><?php esc_html_e( 'No hay niveles configurados.', 'ltms' ); ?></td></tr>
		<?php else : ?>
			<?php foreach ( $tiers as $tier ) : ?>
			<tr>
				<td><?php echo esc_html( $tier['country'] ); ?></td>
				<td><?php echo esc_html( number_format( (float) $tier['min_amount'], 0, ',', '.' ) ); ?></td>
				<td><?php echo esc_html( number_format( (float) $tier['max_amount'], 0, ',', '.' ) ); ?></td>
				<td><strong><?php echo esc_html( number_format( (float) $tier['rate'] * 100, 2 ) . '%' ); ?></strong></td>
				<td><?php echo esc_html( $tier['label'] ); ?></td>
				<td><?php echo esc_html( $tier['currency'] ); ?></td>
				<td><?php echo (int) $tier['is_active'] ? '✅' : '❌'; ?></td>
				<td><?php echo esc_html( $tier['sort_order'] ); ?></td>
				<td>
					<a href="<?php echo esc_url( add_query_arg( 'edit', $tier['id'] ) ); ?>" class="button button-small">
						<?php esc_html_e( 'Editar', 'ltms' ); ?>
					</a>
					<form method="post" style="display:inline;" onsubmit="return confirm('<?php esc_attr_e( '¿Eliminar este nivel?', 'ltms' ); ?>');">
						<?php wp_nonce_field( 'ltms_commission_tiers' ); ?>
						<input type="hidden" name="ltms_tier_action" value="delete">
						<input type="hidden" name="tier_id" value="<?php echo esc_attr( $tier['id'] ); ?>">
						<button type="submit" class="button button-small button-link-delete">
							<?php esc_html_e( 'Eliminar', 'ltms' ); ?>
						</button>
					</form>
				</td>
			</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>

	<!-- ── Formulario de alta / edición ─────────────────────────── -->
	<h2><?php echo $editing ? esc_html__( 'Editar nivel', 'ltms' ) : esc_html__( 'Agregar nuevo nivel', 'ltms' ); ?></h2>
	<form method="post">
		<?php wp_nonce_field( 'ltms_commission_tiers' ); ?>
		<input type="hidden" name="ltms_tier_action" value="save">
		<input type="hidden" name="tier_id" value="<?php echo esc_attr( $editing['id'] ?? 0 ); ?>">

		<table class="form-table">
			<tr>
				<th><label for="ltms-tier-country"><?php esc_html_e( 'País', 'ltms' ); ?></label></th>
				<td>
					<select id="ltms-tier-country" name="country">
						<option value="CO" <?php selected( $editing['country'] ?? 'CO', 'CO' ); ?>>Colombia (CO)</option>
						<option value="MX" <?php selected( $editing['country'] ?? '', 'MX' ); ?>>México (MX)</option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="ltms-tier-min"><?php esc_html_e( 'Volumen mínimo mensual', 'ltms' ); ?></label></th>
				<td>
					<input type="number" id="ltms-tier-min" name="min_amount" min="0" step="1000"
						   value="<?php echo esc_attr( $editing['min_amount'] ?? 0 ); ?>" class="regular-text">
				</td>
			</tr>
			<tr>
				<th><label for="ltms-tier-max"><?php esc_html_e( 'Volumen máximo mensual', 'ltms' ); ?></label></th>
				<td>
					<input type="number" id="ltms-tier-max" name="max_amount" min="0" step="1000"
						   value="<?php echo esc_attr( $editing['max_amount'] ?? 999999999 ); ?>" class="regular-text">
					<p class="description"><?php esc_html_e( 'Usa 999999999 para "sin límite".', 'ltms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="ltms-tier-rate"><?php esc_html_e( 'Tasa de comisión (decimal)', 'ltms' ); ?></label></th>
				<td>
					<input type="number" id="ltms-tier-rate" name="rate" min="0" max="1" step="0.001"
						   value="<?php echo esc_attr( $editing['rate'] ?? 0.10 ); ?>" class="small-text">
					<p class="description"><?php esc_html_e( 'Ej: 0.10 = 10%, 0.06 = 6%', 'ltms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="ltms-tier-label"><?php esc_html_e( 'Etiqueta', 'ltms' ); ?></label></th>
				<td>
					<input type="text" id="ltms-tier-label" name="label" maxlength="100"
						   value="<?php echo esc_attr( $editing['label'] ?? '' ); ?>" class="regular-text"
						   placeholder="<?php esc_attr_e( 'Ej: Bronce, Plata, Oro', 'ltms' ); ?>">
				</td>
			</tr>
			<tr>
				<th><label for="ltms-tier-currency"><?php esc_html_e( 'Moneda', 'ltms' ); ?></label></th>
				<td>
					<select id="ltms-tier-currency" name="currency">
						<option value="COP" <?php selected( $editing['currency'] ?? 'COP', 'COP' ); ?>>COP</option>
						<option value="MXN" <?php selected( $editing['currency'] ?? '', 'MXN' ); ?>>MXN</option>
					</select>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Activo', 'ltms' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="is_active" value="1"
							   <?php checked( (int) ( $editing['is_active'] ?? 1 ), 1 ); ?>>
						<?php esc_html_e( 'Habilitar este nivel', 'ltms' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th><label for="ltms-tier-sort"><?php esc_html_e( 'Orden de evaluación', 'ltms' ); ?></label></th>
				<td>
					<input type="number" id="ltms-tier-sort" name="sort_order" min="0"
						   value="<?php echo esc_attr( $editing['sort_order'] ?? 0 ); ?>" class="small-text">
					<p class="description"><?php esc_html_e( 'Menor número = se evalúa primero.', 'ltms' ); ?></p>
				</td>
			</tr>
		</table>

		<p>
			<?php submit_button( $editing ? __( 'Actualizar nivel', 'ltms' ) : __( 'Crear nivel', 'ltms' ), 'primary', 'submit', false ); ?>
			<?php if ( $editing ) : ?>
			<a href="<?php echo esc_url( remove_query_arg( 'edit' ) ); ?>" class="button button-secondary">
				<?php esc_html_e( 'Cancelar edición', 'ltms' ); ?>
			</a>
			<?php endif; ?>
		</p>
	</form>
</div>
