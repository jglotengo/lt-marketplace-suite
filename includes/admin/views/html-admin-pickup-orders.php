<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<div class="wrap">
	<h1><?php esc_html_e( 'Pedidos para Recogida', 'ltms' ); ?></h1>

	<?php
	$pickup_orders = wc_get_orders( [
		'status'  => 'wc-ready-for-pickup',
		'limit'   => 50,
		'orderby' => 'date',
		'order'   => 'DESC',
	] );
	?>

	<?php if ( empty( $pickup_orders ) ) : ?>
		<p><?php esc_html_e( 'No hay pedidos pendientes de recogida.', 'ltms' ); ?></p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped posts">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Pedido #', 'ltms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Cliente', 'ltms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Vendedor', 'ltms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Total', 'ltms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Dirección del Local', 'ltms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Horario', 'ltms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Fecha', 'ltms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Acción', 'ltms' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $pickup_orders as $order ) : ?>
					<?php
					$order_id    = $order->get_id();
					$customer    = esc_html( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
					$vendor_id   = (int) $order->get_meta( '_ltms_vendor_id' );
					$vendor_data = $vendor_id ? get_userdata( $vendor_id ) : false;
					$vendor_name = $vendor_data ? esc_html( $vendor_data->display_name ) : esc_html__( 'N/D', 'ltms' );
					$store_address = $vendor_id ? esc_html( get_user_meta( $vendor_id, 'ltms_store_address', true ) ) : esc_html__( 'N/D', 'ltms' );
					$store_hours   = $vendor_id ? get_user_meta( $vendor_id, 'ltms_store_hours', true ) : '';
					$store_hours   = $store_hours ? esc_html( $store_hours ) : esc_html__( 'N/D', 'ltms' );
					$date_created  = $order->get_date_created();
					$date_display  = $date_created ? esc_html( $date_created->date_i18n( 'd/m/Y H:i' ) ) : esc_html__( 'N/D', 'ltms' );
					$edit_url      = esc_url( admin_url( 'post.php?post=' . $order_id . '&action=edit' ) );
					?>
					<tr>
						<td>
							<a href="<?php echo $edit_url; ?>">#<?php echo esc_html( $order_id ); ?></a>
						</td>
						<td><?php echo $customer; ?></td>
						<td><?php echo $vendor_name; ?></td>
						<td><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></td>
						<td><?php echo $store_address; ?></td>
						<td><?php echo $store_hours; ?></td>
						<td><?php echo $date_display; ?></td>
						<td>
							<button
								class="button button-primary ltms-mark-pickup-completed"
								data-order-id="<?php echo esc_attr( $order_id ); ?>"
								data-nonce="<?php echo esc_attr( wp_create_nonce( 'ltms_admin_nonce' ) ); ?>"
							>
								<?php esc_html_e( 'Marcar Entregado', 'ltms' ); ?>
							</button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>

<script type="text/javascript">
/* global jQuery */
(function( $ ) {
	'use strict';

	$( document ).on( 'click', '.ltms-mark-pickup-completed', function( e ) {
		e.preventDefault();
		var $btn    = $( this );
		var orderId = $btn.data( 'order-id' );
		var nonce   = $btn.data( 'nonce' );

		if ( ! confirm( '<?php echo esc_js( __( '¿Confirmar que el pedido fue recogido por el cliente?', 'ltms' ) ); ?>' ) ) {
			return;
		}

		$btn.prop( 'disabled', true ).text( '<?php echo esc_js( __( 'Procesando...', 'ltms' ) ); ?>' );

		$.ajax( {
			url:    ajaxurl,
			method: 'POST',
			data: {
				action:   'ltms_mark_pickup_completed',
				order_id: orderId,
				nonce:    nonce
			},
			success: function( res ) {
				if ( res.success ) {
					$btn.closest( 'tr' ).fadeOut( 400, function() {
						$( this ).remove();
					} );
				} else {
					alert( res.data || '<?php echo esc_js( __( 'Error al actualizar el pedido.', 'ltms' ) ); ?>' );
					$btn.prop( 'disabled', false ).text( '<?php echo esc_js( __( 'Marcar Entregado', 'ltms' ) ); ?>' );
				}
			},
			error: function() {
				alert( '<?php echo esc_js( __( 'Error de conexión. Intente nuevamente.', 'ltms' ) ); ?>' );
				$btn.prop( 'disabled', false ).text( '<?php echo esc_js( __( 'Marcar Entregado', 'ltms' ) ); ?>' );
			}
		} );
	} );
}( jQuery ) );
</script>
