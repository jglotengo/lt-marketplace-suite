<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<div class="wrap">
	<h1><?php esc_html_e( 'Pólizas de Seguro XCover', 'ltms' ); ?></h1>

	<?php
	// Filter values from GET (sanitized).
	$filter_status    = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$filter_date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$filter_date_to   = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

	$allowed_statuses = [ '', 'active', 'cancelled', 'claimed', 'expired' ];
	if ( ! in_array( $filter_status, $allowed_statuses, true ) ) {
		$filter_status = '';
	}
	?>

	<form method="GET" action="" style="margin-bottom:16px;">
		<input type="hidden" name="page" value="<?php echo esc_attr( $_GET['page'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification ?>">
		<label for="ltms-xcover-status"><?php esc_html_e( 'Estado:', 'ltms' ); ?></label>
		<select id="ltms-xcover-status" name="status" style="margin:0 8px 0 4px;">
			<option value="" <?php selected( $filter_status, '' ); ?>><?php esc_html_e( 'Todos', 'ltms' ); ?></option>
			<option value="active" <?php selected( $filter_status, 'active' ); ?>><?php esc_html_e( 'Activa', 'ltms' ); ?></option>
			<option value="cancelled" <?php selected( $filter_status, 'cancelled' ); ?>><?php esc_html_e( 'Cancelada', 'ltms' ); ?></option>
			<option value="claimed" <?php selected( $filter_status, 'claimed' ); ?>><?php esc_html_e( 'Reclamada', 'ltms' ); ?></option>
			<option value="expired" <?php selected( $filter_status, 'expired' ); ?>><?php esc_html_e( 'Expirada', 'ltms' ); ?></option>
		</select>

		<label for="ltms-xcover-date-from"><?php esc_html_e( 'Desde:', 'ltms' ); ?></label>
		<input
			type="date"
			id="ltms-xcover-date-from"
			name="date_from"
			value="<?php echo esc_attr( $filter_date_from ); ?>"
			style="margin:0 8px 0 4px;"
		>

		<label for="ltms-xcover-date-to"><?php esc_html_e( 'Hasta:', 'ltms' ); ?></label>
		<input
			type="date"
			id="ltms-xcover-date-to"
			name="date_to"
			value="<?php echo esc_attr( $filter_date_to ); ?>"
			style="margin:0 8px 0 4px;"
		>

		<button type="submit" class="button"><?php esc_html_e( 'Buscar', 'ltms' ); ?></button>
	</form>

	<?php
	global $wpdb;
	$table_name = $wpdb->prefix . 'lt_insurance_policies';

	// Check if table exists.
	$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ); // phpcs:ignore WordPress.DB

	if ( ! $table_exists ) :
	?>
		<div class="notice notice-warning">
			<p><?php esc_html_e( 'La tabla de pólizas de seguro aún no ha sido creada. Ejecute las migraciones de base de datos.', 'ltms' ); ?></p>
		</div>
	<?php else : ?>
		<?php
		$where_clauses = [];
		$where_values  = [];

		if ( $filter_status !== '' ) {
			$where_clauses[] = 'status = %s';
			$where_values[]  = $filter_status;
		}
		if ( $filter_date_from !== '' ) {
			$where_clauses[] = 'DATE(created_at) >= %s';
			$where_values[]  = $filter_date_from;
		}
		if ( $filter_date_to !== '' ) {
			$where_clauses[] = 'DATE(created_at) <= %s';
			$where_values[]  = $filter_date_to;
		}

		$where_sql = '';
		if ( ! empty( $where_clauses ) ) {
			$where_sql = 'WHERE ' . implode( ' AND ', $where_clauses );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$sql = "SELECT * FROM `{$table_name}` {$where_sql} ORDER BY created_at DESC LIMIT 50";

		if ( ! empty( $where_values ) ) {
			$policies = $wpdb->get_results( $wpdb->prepare( $sql, $where_values ) ); // phpcs:ignore
		} else {
			$policies = $wpdb->get_results( $sql ); // phpcs:ignore
		}
		// phpcs:enable

		$status_labels = [
			'active'    => [ 'label' => __( 'Activa', 'ltms' ),    'color' => '#27ae60' ],
			'cancelled' => [ 'label' => __( 'Cancelada', 'ltms' ), 'color' => '#e74c3c' ],
			'claimed'   => [ 'label' => __( 'Reclamada', 'ltms' ), 'color' => '#e67e22' ],
			'expired'   => [ 'label' => __( 'Expirada', 'ltms' ),  'color' => '#95a5a6' ],
		];
		?>

		<?php if ( empty( $policies ) ) : ?>
			<p><?php esc_html_e( 'No se encontraron pólizas con los filtros seleccionados.', 'ltms' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped posts">
				<thead>
					<tr>
						<th scope="col" style="width:50px;"><?php esc_html_e( 'ID', 'ltms' ); ?></th>
						<th scope="col"><?php esc_html_e( '# Pedido', 'ltms' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Vendedor', 'ltms' ); ?></th>
						<th scope="col"><?php esc_html_e( '# Póliza', 'ltms' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Prima', 'ltms' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Tipo', 'ltms' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
						<th scope="col"><?php esc_html_e( 'URL Certificado', 'ltms' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Fecha', 'ltms' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $policies as $policy ) : ?>
						<?php
						$order_id    = isset( $policy->order_id ) ? (int) $policy->order_id : 0;
						$vendor_id   = isset( $policy->vendor_id ) ? (int) $policy->vendor_id : 0;
						$vendor_data = $vendor_id ? get_userdata( $vendor_id ) : false;
						$vendor_name = $vendor_data ? $vendor_data->display_name : esc_html__( 'N/D', 'ltms' );
						$status_key  = isset( $policy->status ) ? $policy->status : '';
						$status_info = isset( $status_labels[ $status_key ] ) ? $status_labels[ $status_key ] : [ 'label' => esc_html( $status_key ), 'color' => '#7f8c8d' ];
						$prima       = isset( $policy->premium_amount ) ? number_format( (float) $policy->premium_amount, 2 ) : '0.00';
						$cert_url    = isset( $policy->certificate_url ) ? $policy->certificate_url : '';
						$created_at  = isset( $policy->created_at ) ? esc_html( $policy->created_at ) : '';
						$policy_type = isset( $policy->policy_type ) ? esc_html( $policy->policy_type ) : esc_html__( 'N/D', 'ltms' );
						$policy_num  = isset( $policy->xcover_policy_id ) ? esc_html( $policy->xcover_policy_id ) : esc_html__( 'N/D', 'ltms' );
						$edit_url    = $order_id ? esc_url( admin_url( 'post.php?post=' . $order_id . '&action=edit' ) ) : '';
						?>
						<tr>
							<td><?php echo esc_html( $policy->id ); ?></td>
							<td>
								<?php if ( $edit_url ) : ?>
									<a href="<?php echo $edit_url; ?>">#<?php echo esc_html( $order_id ); ?></a>
								<?php else : ?>
									<?php esc_html_e( 'N/D', 'ltms' ); ?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $vendor_name ); ?></td>
							<td><?php echo $policy_num; ?></td>
							<td><?php echo esc_html( $prima ); ?></td>
							<td><?php echo $policy_type; ?></td>
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
							<td>
								<?php if ( $cert_url ) : ?>
									<a href="<?php echo esc_url( $cert_url ); ?>" target="_blank" rel="noopener noreferrer">
										<?php esc_html_e( 'Ver', 'ltms' ); ?>
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
		<?php endif; ?>
	<?php endif; ?>
</div>
