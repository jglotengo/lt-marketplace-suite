<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<div class="wrap">
	<h1><?php esc_html_e( 'ReDi — Distribución por Revendedores', 'ltms' ); ?></h1>

	<?php
	$active_tab = isset( $_GET['redi_tab'] ) ? sanitize_key( $_GET['redi_tab'] ) : 'agreements'; // phpcs:ignore WordPress.Security.NonceVerification
	$allowed_tabs = [ 'agreements', 'commissions' ];
	if ( ! in_array( $active_tab, $allowed_tabs, true ) ) {
		$active_tab = 'agreements';
	}

	$base_url     = esc_url( remove_query_arg( 'redi_tab' ) );
	$tab_agree    = esc_url( add_query_arg( 'redi_tab', 'agreements', $base_url ) );
	$tab_commis   = esc_url( add_query_arg( 'redi_tab', 'commissions', $base_url ) );
	?>

	<nav class="nav-tab-wrapper wp-clearfix" style="margin-bottom:16px;">
		<a href="<?php echo $tab_agree; ?>" class="nav-tab <?php echo $active_tab === 'agreements' ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Acuerdos', 'ltms' ); ?>
		</a>
		<a href="<?php echo $tab_commis; ?>" class="nav-tab <?php echo $active_tab === 'commissions' ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Comisiones', 'ltms' ); ?>
		</a>
	</nav>

	<?php
	global $wpdb;
	$agree_table  = $wpdb->prefix . 'lt_redi_agreements';
	$commis_table = $wpdb->prefix . 'lt_redi_commissions';

	$admin_nonce = wp_create_nonce( 'ltms_admin_nonce' );

	// ------------------------------------------------------------------ //
	// TAB: ACUERDOS
	// ------------------------------------------------------------------ //
	if ( $active_tab === 'agreements' ) :
		$agree_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $agree_table ) ); // phpcs:ignore WordPress.DB

		if ( ! $agree_exists ) :
		?>
			<div class="notice notice-warning">
				<p><?php esc_html_e( 'La tabla de acuerdos ReDi aún no ha sido creada. Ejecute las migraciones de base de datos.', 'ltms' ); ?></p>
			</div>
		<?php else :
			$agreements = $wpdb->get_results( // phpcs:ignore WordPress.DB
				"SELECT * FROM `{$agree_table}` ORDER BY created_at DESC LIMIT 50" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);

			$status_labels = [
				'active'  => [ 'label' => __( 'Activo', 'ltms' ),  'color' => '#27ae60' ],
				'paused'  => [ 'label' => __( 'Pausado', 'ltms' ), 'color' => '#e67e22' ],
				'revoked' => [ 'label' => __( 'Revocado', 'ltms' ),'color' => '#e74c3c' ],
				'pending' => [ 'label' => __( 'Pendiente', 'ltms' ),'color' => '#3498db' ],
			];
		?>
			<?php if ( empty( $agreements ) ) : ?>
				<p><?php esc_html_e( 'No hay acuerdos ReDi registrados.', 'ltms' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped posts">
					<thead>
						<tr>
							<th scope="col" style="width:50px;"><?php esc_html_e( 'ID', 'ltms' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Vendedor Origen', 'ltms' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Revendedor', 'ltms' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Producto Origen', 'ltms' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Tasa ReDi (%)', 'ltms' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Ventas Totales', 'ltms' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Fecha', 'ltms' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Acciones', 'ltms' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $agreements as $agreement ) : ?>
							<?php
							$origin_id    = isset( $agreement->origin_vendor_id ) ? (int) $agreement->origin_vendor_id : 0;
							$reseller_id  = isset( $agreement->reseller_vendor_id ) ? (int) $agreement->reseller_vendor_id : 0;
							$product_id   = isset( $agreement->product_id ) ? (int) $agreement->product_id : 0;
							$redi_rate    = isset( $agreement->redi_rate ) ? number_format( (float) $agreement->redi_rate, 2 ) : '0.00';
							$total_sales  = isset( $agreement->total_sales ) ? number_format( (float) $agreement->total_sales, 2 ) : '0.00';
							$status_key   = isset( $agreement->status ) ? $agreement->status : '';
							$status_info  = isset( $status_labels[ $status_key ] ) ? $status_labels[ $status_key ] : [ 'label' => esc_html( $status_key ), 'color' => '#7f8c8d' ];
							$created_at   = isset( $agreement->created_at ) ? esc_html( $agreement->created_at ) : '';

							$origin_data   = $origin_id ? get_userdata( $origin_id ) : false;
							$origin_name   = $origin_data ? esc_html( $origin_data->display_name ) : esc_html( $origin_id );
							$reseller_data = $reseller_id ? get_userdata( $reseller_id ) : false;
							$reseller_name = $reseller_data ? esc_html( $reseller_data->display_name ) : esc_html( $reseller_id );

							$product_link  = $product_id ? esc_url( admin_url( 'post.php?post=' . $product_id . '&action=edit' ) ) : '';
							$product_title = $product_id ? esc_html( get_the_title( $product_id ) ) : esc_html__( 'N/D', 'ltms' );
							?>
							<tr id="ltms-redi-row-<?php echo esc_attr( $agreement->id ); ?>">
								<td><?php echo esc_html( $agreement->id ); ?></td>
								<td><?php echo $origin_name; ?></td>
								<td><?php echo $reseller_name; ?></td>
								<td>
									<?php if ( $product_link ) : ?>
										<a href="<?php echo $product_link; ?>"><?php echo $product_title; ?></a>
									<?php else : ?>
										<?php echo $product_title; ?>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $redi_rate ); ?>%</td>
								<td>
									<span style="
										display:inline-block;
										padding:2px 8px;
										border-radius:3px;
										background-color:<?php echo esc_attr( $status_info['color'] ); ?>;
										color:#fff;
										font-size:12px;
										font-weight:600;
									">
										<?php echo esc_html( $status_info['label'] ); ?>
									</span>
								</td>
								<td><?php echo esc_html( $total_sales ); ?></td>
								<td><?php echo $created_at; ?></td>
								<td>
									<?php if ( $status_key === 'active' ) : ?>
										<button
											class="button button-secondary ltms-redi-revoke"
											data-id="<?php echo esc_attr( $agreement->id ); ?>"
											data-nonce="<?php echo esc_attr( $admin_nonce ); ?>"
										>
											<?php esc_html_e( 'Revocar', 'ltms' ); ?>
										</button>
									<?php elseif ( in_array( $status_key, [ 'paused', 'revoked' ], true ) ) : ?>
										<button
											class="button button-primary ltms-redi-activate"
											data-id="<?php echo esc_attr( $agreement->id ); ?>"
											data-nonce="<?php echo esc_attr( $admin_nonce ); ?>"
										>
											<?php esc_html_e( 'Activar', 'ltms' ); ?>
										</button>
									<?php else : ?>
										&mdash;
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		<?php endif; ?>

	<?php
	// ------------------------------------------------------------------ //
	// TAB: COMISIONES
	// ------------------------------------------------------------------ //
	elseif ( $active_tab === 'commissions' ) :
		$commis_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $commis_table ) ); // phpcs:ignore WordPress.DB

		if ( ! $commis_exists ) :
		?>
			<div class="notice notice-warning">
				<p><?php esc_html_e( 'La tabla de comisiones ReDi aún no ha sido creada. Ejecute las migraciones de base de datos.', 'ltms' ); ?></p>
			</div>
		<?php else :
			$commissions = $wpdb->get_results( // phpcs:ignore WordPress.DB
				"SELECT * FROM `{$commis_table}` ORDER BY created_at DESC LIMIT 50" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);

			$status_labels_c = [
				'pending'  => [ 'label' => __( 'Pendiente', 'ltms' ),  'color' => '#3498db' ],
				'paid'     => [ 'label' => __( 'Pagada', 'ltms' ),     'color' => '#27ae60' ],
				'reversed' => [ 'label' => __( 'Revertida', 'ltms' ),  'color' => '#e74c3c' ],
				'held'     => [ 'label' => __( 'Retenida', 'ltms' ),   'color' => '#e67e22' ],
			];
		?>
			<?php if ( empty( $commissions ) ) : ?>
				<p><?php esc_html_e( 'No hay comisiones ReDi registradas.', 'ltms' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped posts">
					<thead>
						<tr>
							<th scope="col" style="width:50px;"><?php esc_html_e( 'ID', 'ltms' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Pedido #', 'ltms' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Vendedor Origen', 'ltms' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Revendedor', 'ltms' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Bruto', 'ltms' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Fee Plataforma', 'ltms' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Comisión Revendedor', 'ltms' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Neto Origen', 'ltms' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Retención', 'ltms' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Fecha', 'ltms' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $commissions as $commission ) : ?>
							<?php
							$order_id    = isset( $commission->order_id ) ? (int) $commission->order_id : 0;
							$origin_id   = isset( $commission->origin_vendor_id ) ? (int) $commission->origin_vendor_id : 0;
							$reseller_id = isset( $commission->reseller_vendor_id ) ? (int) $commission->reseller_vendor_id : 0;

							$origin_data   = $origin_id ? get_userdata( $origin_id ) : false;
							$origin_name   = $origin_data ? esc_html( $origin_data->display_name ) : esc_html( $origin_id );
							$reseller_data = $reseller_id ? get_userdata( $reseller_id ) : false;
							$reseller_name = $reseller_data ? esc_html( $reseller_data->display_name ) : esc_html( $reseller_id );

							$status_key  = isset( $commission->status ) ? $commission->status : '';
							$status_info = isset( $status_labels_c[ $status_key ] ) ? $status_labels_c[ $status_key ] : [ 'label' => esc_html( $status_key ), 'color' => '#7f8c8d' ];

							$edit_url = $order_id ? esc_url( admin_url( 'post.php?post=' . $order_id . '&action=edit' ) ) : '';
							?>
							<tr>
								<td><?php echo esc_html( $commission->id ); ?></td>
								<td>
									<?php if ( $edit_url ) : ?>
										<a href="<?php echo $edit_url; ?>">#<?php echo esc_html( $order_id ); ?></a>
									<?php else : ?>
										<?php esc_html_e( 'N/D', 'ltms' ); ?>
									<?php endif; ?>
								</td>
								<td><?php echo $origin_name; ?></td>
								<td><?php echo $reseller_name; ?></td>
								<td><?php echo esc_html( number_format( (float) ( $commission->gross_amount ?? 0 ), 2 ) ); ?></td>
								<td><?php echo esc_html( number_format( (float) ( $commission->platform_fee ?? 0 ), 2 ) ); ?></td>
								<td><?php echo esc_html( number_format( (float) ( $commission->reseller_commission ?? 0 ), 2 ) ); ?></td>
								<td><?php echo esc_html( number_format( (float) ( $commission->origin_vendor_net ?? 0 ), 2 ) ); ?></td>
								<td><?php echo esc_html( number_format( (float) ( $commission->tax_withholding ?? 0 ), 2 ) ); ?></td>
								<td>
									<span style="
										display:inline-block;
										padding:2px 8px;
										border-radius:3px;
										background-color:<?php echo esc_attr( $status_info['color'] ); ?>;
										color:#fff;
										font-size:12px;
										font-weight:600;
									">
										<?php echo esc_html( $status_info['label'] ); ?>
									</span>
								</td>
								<td><?php echo esc_html( $commission->created_at ?? '' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		<?php endif; ?>
	<?php endif; ?>
</div>

<script type="text/javascript">
/* global jQuery */
(function( $ ) {
	'use strict';

	function ltmsRediAction( action, agreementId, nonce, $btn ) {
		$btn.prop( 'disabled', true );

		var data = {
			action:       action,
			agreement_id: agreementId,
			nonce:        nonce
		};

		if ( action === 'ltms_revoke_redi_agreement' ) {
			var reason = prompt( '<?php echo esc_js( __( 'Motivo de revocación (opcional):', 'ltms' ) ); ?>' );
			data.reason = reason || '';
		}

		$.ajax( {
			url:    ajaxurl,
			method: 'POST',
			data:   data,
			success: function( res ) {
				if ( res.success ) {
					window.location.reload();
				} else {
					alert( res.data || '<?php echo esc_js( __( 'Error al procesar la solicitud.', 'ltms' ) ); ?>' );
					$btn.prop( 'disabled', false );
				}
			},
			error: function() {
				alert( '<?php echo esc_js( __( 'Error de conexión. Intente nuevamente.', 'ltms' ) ); ?>' );
				$btn.prop( 'disabled', false );
			}
		} );
	}

	$( document ).on( 'click', '.ltms-redi-revoke', function( e ) {
		e.preventDefault();
		var $btn = $( this );
		if ( ! confirm( '<?php echo esc_js( __( '¿Está seguro de que desea revocar este acuerdo?', 'ltms' ) ); ?>' ) ) {
			return;
		}
		ltmsRediAction( 'ltms_revoke_redi_agreement', $btn.data( 'id' ), $btn.data( 'nonce' ), $btn );
	} );

	$( document ).on( 'click', '.ltms-redi-activate', function( e ) {
		e.preventDefault();
		var $btn = $( this );
		if ( ! confirm( '<?php echo esc_js( __( '¿Está seguro de que desea activar este acuerdo?', 'ltms' ) ); ?>' ) ) {
			return;
		}
		ltmsRediAction( 'ltms_approve_redi_agreement', $btn.data( 'id' ), $btn.data( 'nonce' ), $btn );
	} );
}( jQuery ) );
</script>
