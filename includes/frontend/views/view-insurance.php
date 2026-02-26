<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<div class="ltms-tab-content" id="ltms-tab-insurance">

	<h2><?php esc_html_e( 'Mis Seguros', 'ltms' ); ?></h2>

	<?php
	global $wpdb;
	$vendor_id  = get_current_user_id();
	$table_name = $wpdb->prefix . 'lt_insurance_policies';

	$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ); // phpcs:ignore WordPress.DB

	if ( ! $table_exists ) :
	?>
		<p><?php esc_html_e( 'Aún no tienes pólizas de seguro activas.', 'ltms' ); ?></p>
	<?php else :
		$policies = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT * FROM `{$table_name}` WHERE vendor_id = %d ORDER BY created_at DESC LIMIT 20", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$vendor_id
			)
		);

		$status_labels = [
			'active'    => __( 'Activa', 'ltms' ),
			'cancelled' => __( 'Cancelada', 'ltms' ),
			'claimed'   => __( 'Reclamada', 'ltms' ),
			'expired'   => __( 'Expirada', 'ltms' ),
		];

		$status_colors = [
			'active'    => '#27ae60',
			'cancelled' => '#e74c3c',
			'claimed'   => '#e67e22',
			'expired'   => '#95a5a6',
		];
	?>
		<?php if ( empty( $policies ) ) : ?>
			<p><?php esc_html_e( 'Aún no tienes pólizas de seguro activas.', 'ltms' ); ?></p>
		<?php else : ?>
			<div class="ltms-table-responsive">
				<table class="ltms-table ltms-insurance-table">
					<thead>
						<tr>
							<th><?php esc_html_e( '# Pedido', 'ltms' ); ?></th>
							<th><?php esc_html_e( '# Póliza', 'ltms' ); ?></th>
							<th><?php esc_html_e( 'Tipo', 'ltms' ); ?></th>
							<th><?php esc_html_e( 'Prima', 'ltms' ); ?></th>
							<th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
							<th><?php esc_html_e( 'Certificado', 'ltms' ); ?></th>
							<th><?php esc_html_e( 'Fecha', 'ltms' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $policies as $policy ) : ?>
							<?php
							$order_id    = isset( $policy->order_id ) ? (int) $policy->order_id : 0;
							$policy_num  = isset( $policy->xcover_policy_id ) ? esc_html( $policy->xcover_policy_id ) : esc_html__( 'N/D', 'ltms' );
							$policy_type = isset( $policy->policy_type ) ? esc_html( $policy->policy_type ) : esc_html__( 'N/D', 'ltms' );
							$prima       = isset( $policy->premium_amount ) ? number_format( (float) $policy->premium_amount, 2 ) : '0.00';
							$status_key  = isset( $policy->status ) ? $policy->status : '';
							$status_lbl  = isset( $status_labels[ $status_key ] ) ? $status_labels[ $status_key ] : esc_html( $status_key );
							$status_clr  = isset( $status_colors[ $status_key ] ) ? $status_colors[ $status_key ] : '#7f8c8d';
							$cert_url    = isset( $policy->certificate_url ) ? $policy->certificate_url : '';
							$created_at  = isset( $policy->created_at ) ? esc_html( $policy->created_at ) : '';
							?>
							<tr>
								<td>
									<?php if ( $order_id ) : ?>
										#<?php echo esc_html( $order_id ); ?>
									<?php else : ?>
										<?php esc_html_e( 'N/D', 'ltms' ); ?>
									<?php endif; ?>
								</td>
								<td><?php echo $policy_num; ?></td>
								<td><?php echo $policy_type; ?></td>
								<td><?php echo esc_html( $prima ); ?></td>
								<td>
									<span style="
										display:inline-block;
										padding:2px 8px;
										border-radius:3px;
										background-color:<?php echo esc_attr( $status_clr ); ?>;
										color:#fff;
										font-size:12px;
										font-weight:600;
									">
										<?php echo esc_html( $status_lbl ); ?>
									</span>
								</td>
								<td>
									<?php if ( $cert_url ) : ?>
										<a
											href="<?php echo esc_url( $cert_url ); ?>"
											target="_blank"
											rel="noopener noreferrer"
										>
											<?php esc_html_e( 'Descargar', 'ltms' ); ?>
										</a>
									<?php else : ?>
										<?php esc_html_e( 'N/D', 'ltms' ); ?>
									<?php endif; ?>
								</td>
								<td><?php echo $created_at; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	<?php endif; ?>

</div>
