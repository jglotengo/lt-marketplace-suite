<?php
/**
 * Vista del Dashboard: Gestión de Domiciliarios Propios
 *
 * Permite al vendedor gestionar su flota de domiciliarios:
 * agregar, editar, activar/desactivar y configurar disponibilidad.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/frontend/views
 * @version    1.7.0
 */

defined( 'ABSPATH' ) || exit;

$vendor_id = get_current_user_id();
if ( ! $vendor_id ) {
	return;
}

global $wpdb;
$table = $wpdb->prefix . 'lt_vendor_drivers';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$drivers = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT id, name, phone, vehicle_type, is_active, is_available, current_order_id, created_at
		 FROM `{$table}` WHERE vendor_id = %d ORDER BY name ASC",
		$vendor_id
	),
	ARRAY_A
);

$delivery_price   = (float) get_user_meta( $vendor_id, 'ltms_own_delivery_price', true );
$delivery_eta_min = (int) get_user_meta( $vendor_id, 'ltms_own_delivery_eta_minutes', true );
$delivery_zones   = (string) get_user_meta( $vendor_id, 'ltms_own_delivery_zones', true );
$delivery_message = (string) get_user_meta( $vendor_id, 'ltms_own_delivery_message', true );
?>

<section class="ltms-view ltms-view-drivers">
	<div class="ltms-view-header">
		<h2><?php esc_html_e( 'Mis Domiciliarios', 'ltms' ); ?></h2>
		<p class="ltms-view-desc">
			<?php esc_html_e( 'Gestiona tu flota de repartidores. El método "Domiciliario propio" aparecerá en el checkout solo cuando tengas al menos un repartidor activo y disponible.', 'ltms' ); ?>
		</p>
	</div>

	<!-- ── Configuración general de entrega ──────────────────────── -->
	<div class="ltms-card ltms-card-delivery-config">
		<h3><?php esc_html_e( 'Configuración de Entrega', 'ltms' ); ?></h3>
		<form id="ltms-delivery-settings-form" class="ltms-form">
			<?php wp_nonce_field( 'ltms_dashboard_nonce', 'nonce' ); ?>
			<input type="hidden" name="action" value="ltms_save_delivery_settings">

			<div class="ltms-form-row">
				<label for="ltms-delivery-price"><?php esc_html_e( 'Precio de domicilio (COP)', 'ltms' ); ?></label>
				<input type="number" id="ltms-delivery-price" name="delivery_price"
					   min="0" step="100"
					   value="<?php echo esc_attr( $delivery_price > 0 ? $delivery_price : '' ); ?>"
					   placeholder="0">
				<p class="ltms-field-hint"><?php esc_html_e( 'Ingresa 0 para envío gratuito.', 'ltms' ); ?></p>
			</div>

			<div class="ltms-form-row">
				<label for="ltms-delivery-eta"><?php esc_html_e( 'Tiempo estimado (minutos)', 'ltms' ); ?></label>
				<input type="number" id="ltms-delivery-eta" name="delivery_eta_minutes"
					   min="1" max="480" step="5"
					   value="<?php echo esc_attr( $delivery_eta_min > 0 ? $delivery_eta_min : 60 ); ?>">
			</div>

			<div class="ltms-form-row">
				<label for="ltms-delivery-zones"><?php esc_html_e( 'Zonas de cobertura', 'ltms' ); ?></label>
				<input type="text" id="ltms-delivery-zones" name="delivery_zones"
					   value="<?php echo esc_attr( $delivery_zones ); ?>"
					   placeholder="<?php esc_attr_e( 'Ej: Chapinero, Usaquén, Suba', 'ltms' ); ?>"
					   maxlength="500">
				<p class="ltms-field-hint"><?php esc_html_e( 'Describe las zonas donde realizas entregas.', 'ltms' ); ?></p>
			</div>

			<div class="ltms-form-row">
				<label for="ltms-delivery-message"><?php esc_html_e( 'Mensaje al cliente', 'ltms' ); ?></label>
				<input type="text" id="ltms-delivery-message" name="delivery_message"
					   value="<?php echo esc_attr( $delivery_message ); ?>"
					   placeholder="<?php esc_attr_e( 'Ej: Solo Bogotá norte. Llámanos antes de pedir.', 'ltms' ); ?>"
					   maxlength="200">
			</div>

			<div class="ltms-form-actions">
				<button type="submit" class="ltms-btn ltms-btn-primary" id="ltms-save-delivery-settings">
					<?php esc_html_e( 'Guardar configuración', 'ltms' ); ?>
				</button>
				<span class="ltms-spinner" style="display:none;"></span>
			</div>
		</form>
	</div>

	<!-- ── Lista de domiciliarios ────────────────────────────────── -->
	<div class="ltms-card">
		<div class="ltms-card-header-row">
			<h3><?php esc_html_e( 'Repartidores registrados', 'ltms' ); ?></h3>
			<button class="ltms-btn ltms-btn-secondary ltms-btn-sm" id="ltms-add-driver-btn">
				+ <?php esc_html_e( 'Agregar repartidor', 'ltms' ); ?>
			</button>
		</div>

		<?php if ( empty( $drivers ) ) : ?>
			<p class="ltms-empty-state">
				<?php esc_html_e( 'Aún no tienes repartidores registrados. Agrega el primero para habilitar la opción de domiciliario propio en el checkout.', 'ltms' ); ?>
			</p>
		<?php else : ?>
		<table class="ltms-table ltms-drivers-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Nombre', 'ltms' ); ?></th>
					<th><?php esc_html_e( 'Teléfono', 'ltms' ); ?></th>
					<th><?php esc_html_e( 'Vehículo', 'ltms' ); ?></th>
					<th><?php esc_html_e( 'Activo', 'ltms' ); ?></th>
					<th><?php esc_html_e( 'Disponible', 'ltms' ); ?></th>
					<th><?php esc_html_e( 'Pedido actual', 'ltms' ); ?></th>
					<th><?php esc_html_e( 'Acciones', 'ltms' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $drivers as $driver ) :
				$vehicle_labels = [
					'bicycle' => __( 'Bicicleta', 'ltms' ),
					'moto'    => __( 'Moto', 'ltms' ),
					'car'     => __( 'Carro', 'ltms' ),
					'walking' => __( 'A pie', 'ltms' ),
				];
				$vehicle_label = $vehicle_labels[ $driver['vehicle_type'] ] ?? ucfirst( $driver['vehicle_type'] );
				$active_class  = $driver['is_active'] ? 'ltms-badge-success' : 'ltms-badge-secondary';
				$avail_class   = $driver['is_available'] ? 'ltms-badge-success' : 'ltms-badge-warning';
			?>
			<tr data-driver-id="<?php echo esc_attr( $driver['id'] ); ?>">
				<td><?php echo esc_html( $driver['name'] ); ?></td>
				<td><?php echo esc_html( $driver['phone'] ); ?></td>
				<td><?php echo esc_html( $vehicle_label ); ?></td>
				<td>
					<span class="ltms-badge <?php echo esc_attr( $active_class ); ?>">
						<?php echo $driver['is_active'] ? esc_html__( 'Sí', 'ltms' ) : esc_html__( 'No', 'ltms' ); ?>
					</span>
				</td>
				<td>
					<span class="ltms-badge <?php echo esc_attr( $avail_class ); ?>">
						<?php echo $driver['is_available'] ? esc_html__( 'Disponible', 'ltms' ) : esc_html__( 'Ocupado', 'ltms' ); ?>
					</span>
				</td>
				<td>
					<?php echo $driver['current_order_id']
						? '<a href="' . esc_url( wc_get_order( (int) $driver['current_order_id'] )->get_view_order_url() ) . '">#' . esc_html( $driver['current_order_id'] ) . '</a>'
						: '—'; ?>
				</td>
				<td class="ltms-driver-actions">
					<button class="ltms-btn ltms-btn-link ltms-driver-toggle-active"
							data-driver-id="<?php echo esc_attr( $driver['id'] ); ?>"
							data-active="<?php echo esc_attr( $driver['is_active'] ); ?>">
						<?php echo $driver['is_active'] ? esc_html__( 'Desactivar', 'ltms' ) : esc_html__( 'Activar', 'ltms' ); ?>
					</button>
					<button class="ltms-btn ltms-btn-link ltms-driver-toggle-available"
							data-driver-id="<?php echo esc_attr( $driver['id'] ); ?>"
							data-available="<?php echo esc_attr( $driver['is_available'] ); ?>">
						<?php echo $driver['is_available'] ? esc_html__( 'Marcar ocupado', 'ltms' ) : esc_html__( 'Marcar disponible', 'ltms' ); ?>
					</button>
					<button class="ltms-btn ltms-btn-link ltms-driver-delete ltms-btn-danger-link"
							data-driver-id="<?php echo esc_attr( $driver['id'] ); ?>">
						<?php esc_html_e( 'Eliminar', 'ltms' ); ?>
					</button>
				</td>
			</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
	</div>

	<!-- ── Modal: Agregar / Editar repartidor ────────────────────── -->
	<div id="ltms-driver-modal" class="ltms-modal" style="display:none;" role="dialog" aria-modal="true">
		<div class="ltms-modal-overlay"></div>
		<div class="ltms-modal-content">
			<h3 class="ltms-modal-title"><?php esc_html_e( 'Agregar repartidor', 'ltms' ); ?></h3>
			<form id="ltms-driver-form" class="ltms-form" novalidate>
				<?php wp_nonce_field( 'ltms_dashboard_nonce', 'nonce' ); ?>
				<input type="hidden" name="action" value="ltms_save_driver">
				<input type="hidden" name="driver_id" value="0">

				<div class="ltms-form-row">
					<label for="ltms-driver-name"><?php esc_html_e( 'Nombre completo *', 'ltms' ); ?></label>
					<input type="text" id="ltms-driver-name" name="driver_name" required maxlength="200">
				</div>

				<div class="ltms-form-row">
					<label for="ltms-driver-phone"><?php esc_html_e( 'Teléfono *', 'ltms' ); ?></label>
					<input type="tel" id="ltms-driver-phone" name="driver_phone" required maxlength="20">
				</div>

				<div class="ltms-form-row">
					<label for="ltms-driver-doc"><?php esc_html_e( 'N.º Documento *', 'ltms' ); ?></label>
					<input type="text" id="ltms-driver-doc" name="driver_document_number" required maxlength="20">
					<p class="ltms-field-hint"><?php esc_html_e( 'Se almacena cifrado (AES-256).', 'ltms' ); ?></p>
				</div>

				<div class="ltms-form-row">
					<label for="ltms-driver-vehicle"><?php esc_html_e( 'Tipo de vehículo *', 'ltms' ); ?></label>
					<select id="ltms-driver-vehicle" name="driver_vehicle_type" required>
						<option value=""><?php esc_html_e( 'Selecciona...', 'ltms' ); ?></option>
						<option value="bicycle"><?php esc_html_e( 'Bicicleta', 'ltms' ); ?></option>
						<option value="moto"><?php esc_html_e( 'Moto', 'ltms' ); ?></option>
						<option value="car"><?php esc_html_e( 'Carro', 'ltms' ); ?></option>
						<option value="walking"><?php esc_html_e( 'A pie', 'ltms' ); ?></option>
					</select>
				</div>

				<div class="ltms-form-row">
					<label for="ltms-driver-plate"><?php esc_html_e( 'Placa del vehículo', 'ltms' ); ?></label>
					<input type="text" id="ltms-driver-plate" name="driver_vehicle_plate" maxlength="10"
						   placeholder="<?php esc_attr_e( 'Ej: ABC123', 'ltms' ); ?>">
					<p class="ltms-field-hint"><?php esc_html_e( 'Se almacena cifrado. Opcional para bicicletas y peatones.', 'ltms' ); ?></p>
				</div>

				<div class="ltms-modal-footer">
					<button type="submit" class="ltms-btn ltms-btn-primary">
						<?php esc_html_e( 'Guardar', 'ltms' ); ?>
					</button>
					<button type="button" class="ltms-btn ltms-btn-secondary ltms-modal-close">
						<?php esc_html_e( 'Cancelar', 'ltms' ); ?>
					</button>
				</div>
			</form>
		</div>
	</div>

</section>
