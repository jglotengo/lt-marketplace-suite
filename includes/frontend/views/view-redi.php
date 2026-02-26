<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<div class="ltms-tab-content" id="ltms-tab-redi">

	<h2><?php esc_html_e( 'ReDi — Distribución por Revendedores', 'ltms' ); ?></h2>

	<?php
	global $wpdb;
	$vendor_id     = get_current_user_id();
	$agree_table   = $wpdb->prefix . 'lt_redi_agreements';
	$commis_table  = $wpdb->prefix . 'lt_redi_commissions';

	$agree_exists  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $agree_table ) ); // phpcs:ignore WordPress.DB
	$commis_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $commis_table ) ); // phpcs:ignore WordPress.DB
	?>

	<!-- ============================================================ -->
	<!-- SECTION 1: Mis Listados ReDi                                  -->
	<!-- ============================================================ -->
	<section class="ltms-redi-section" style="margin-bottom:32px;">
		<h3><?php esc_html_e( 'Mis Listados ReDi', 'ltms' ); ?></h3>

		<?php if ( ! $agree_exists ) : ?>
			<p><?php esc_html_e( 'No tienes listados ReDi activos en este momento.', 'ltms' ); ?></p>
		<?php else :
			$listings = $wpdb->get_results( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					"SELECT a.*, " .
					"( SELECT COALESCE( SUM(c.reseller_commission), 0 ) FROM `{$commis_table}` c WHERE c.reseller_vendor_id = a.reseller_vendor_id AND c.origin_vendor_id = a.origin_vendor_id ) AS total_commissions " .
					"FROM `{$agree_table}` a " .
					"WHERE a.reseller_vendor_id = %d AND a.status = 'active' " .
					"ORDER BY a.created_at DESC", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$vendor_id
				)
			);
		?>
			<?php if ( empty( $listings ) ) : ?>
				<p><?php esc_html_e( 'No tienes listados ReDi activos en este momento.', 'ltms' ); ?></p>
			<?php else : ?>
				<div class="ltms-table-responsive">
					<table class="ltms-table ltms-redi-listings-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Producto Origen', 'ltms' ); ?></th>
								<th><?php esc_html_e( 'Vendedor Origen', 'ltms' ); ?></th>
								<th><?php esc_html_e( 'Tasa (%)', 'ltms' ); ?></th>
								<th><?php esc_html_e( 'Total Comisiones', 'ltms' ); ?></th>
								<th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $listings as $listing ) : ?>
								<?php
								$product_id    = isset( $listing->product_id ) ? (int) $listing->product_id : 0;
								$product_title = $product_id ? esc_html( get_the_title( $product_id ) ) : esc_html__( 'N/D', 'ltms' );
								$product_url   = $product_id ? esc_url( get_permalink( $product_id ) ) : '';
								$origin_id     = isset( $listing->origin_vendor_id ) ? (int) $listing->origin_vendor_id : 0;
								$origin_data   = $origin_id ? get_userdata( $origin_id ) : false;
								$origin_name   = $origin_data ? esc_html( $origin_data->display_name ) : esc_html__( 'N/D', 'ltms' );
								$redi_rate     = isset( $listing->redi_rate ) ? number_format( (float) $listing->redi_rate, 2 ) : '0.00';
								$total_commis  = isset( $listing->total_commissions ) ? number_format( (float) $listing->total_commissions, 2 ) : '0.00';
								$status_key    = isset( $listing->status ) ? $listing->status : '';
								?>
								<tr>
									<td>
										<?php if ( $product_url ) : ?>
											<a href="<?php echo $product_url; ?>" target="_blank" rel="noopener noreferrer">
												<?php echo $product_title; ?>
											</a>
										<?php else : ?>
											<?php echo $product_title; ?>
										<?php endif; ?>
									</td>
									<td><?php echo $origin_name; ?></td>
									<td><?php echo esc_html( $redi_rate ); ?>%</td>
									<td><?php echo esc_html( $total_commis ); ?></td>
									<td>
										<span class="ltms-status-badge ltms-status-<?php echo esc_attr( $status_key ); ?>">
											<?php echo esc_html( ucfirst( $status_key ) ); ?>
										</span>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		<?php endif; ?>
	</section>

	<!-- ============================================================ -->
	<!-- SECTION 2: Comisiones ReDi                                    -->
	<!-- ============================================================ -->
	<section class="ltms-redi-section" style="margin-bottom:32px;">
		<h3><?php esc_html_e( 'Comisiones ReDi', 'ltms' ); ?></h3>

		<?php if ( ! $commis_exists ) : ?>
			<p><?php esc_html_e( 'Aún no tienes comisiones ReDi registradas.', 'ltms' ); ?></p>
		<?php else :
			$commissions = $wpdb->get_results( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					"SELECT * FROM `{$commis_table}` WHERE reseller_vendor_id = %d ORDER BY created_at DESC LIMIT 20", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$vendor_id
				)
			);

			$status_labels = [
				'pending'  => __( 'Pendiente', 'ltms' ),
				'paid'     => __( 'Pagada', 'ltms' ),
				'reversed' => __( 'Revertida', 'ltms' ),
				'held'     => __( 'Retenida', 'ltms' ),
			];
		?>
			<?php if ( empty( $commissions ) ) : ?>
				<p><?php esc_html_e( 'Aún no tienes comisiones ReDi registradas.', 'ltms' ); ?></p>
			<?php else : ?>
				<div class="ltms-table-responsive">
					<table class="ltms-table ltms-redi-commissions-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Pedido #', 'ltms' ); ?></th>
								<th><?php esc_html_e( 'Comisión', 'ltms' ); ?></th>
								<th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
								<th><?php esc_html_e( 'Fecha', 'ltms' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $commissions as $commission ) : ?>
								<?php
								$order_id   = isset( $commission->order_id ) ? (int) $commission->order_id : 0;
								$commis_amt = isset( $commission->reseller_commission ) ? number_format( (float) $commission->reseller_commission, 2 ) : '0.00';
								$status_key = isset( $commission->status ) ? $commission->status : '';
								$status_lbl = isset( $status_labels[ $status_key ] ) ? $status_labels[ $status_key ] : esc_html( ucfirst( $status_key ) );
								$created_at = isset( $commission->created_at ) ? esc_html( $commission->created_at ) : '';
								?>
								<tr>
									<td>
										<?php if ( $order_id ) : ?>
											#<?php echo esc_html( $order_id ); ?>
										<?php else : ?>
											<?php esc_html_e( 'N/D', 'ltms' ); ?>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( $commis_amt ); ?></td>
									<td>
										<span class="ltms-status-badge ltms-status-<?php echo esc_attr( $status_key ); ?>">
											<?php echo esc_html( $status_lbl ); ?>
										</span>
									</td>
									<td><?php echo $created_at; ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		<?php endif; ?>
	</section>

	<!-- ============================================================ -->
	<!-- SECTION 3: Explorar Productos ReDi                            -->
	<!-- ============================================================ -->
	<section class="ltms-redi-section">
		<h3><?php esc_html_e( 'Explorar Productos ReDi', 'ltms' ); ?></h3>

		<button
			id="ltms-explore-redi-btn"
			class="ltms-btn ltms-btn-primary"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'ltms_vendor_nonce' ) ); ?>"
		>
			<?php esc_html_e( 'Explorar Productos ReDi', 'ltms' ); ?>
		</button>

		<div id="ltms-redi-products-container" style="margin-top:16px;"></div>
	</section>

</div>

<script type="text/javascript">
/* global jQuery, ltmsDashboard */
(function( $ ) {
	'use strict';

	$( '#ltms-explore-redi-btn' ).on( 'click', function( e ) {
		e.preventDefault();
		var $btn       = $( this );
		var $container = $( '#ltms-redi-products-container' );
		var nonce      = $btn.data( 'nonce' );
		var ajaxUrl    = ( typeof ltmsDashboard !== 'undefined' && ltmsDashboard.ajax_url )
			? ltmsDashboard.ajax_url
			: ( typeof ajaxurl !== 'undefined' ? ajaxurl : '' );

		if ( ! ajaxUrl ) {
			$container.html( '<p><?php echo esc_js( __( 'Error: no se pudo determinar la URL de AJAX.', 'ltms' ) ); ?></p>' );
			return;
		}

		$btn.prop( 'disabled', true ).text( '<?php echo esc_js( __( 'Cargando...', 'ltms' ) ); ?>' );
		$container.html( '<p><?php echo esc_js( __( 'Buscando productos disponibles...', 'ltms' ) ); ?></p>' );

		$.ajax( {
			url:    ajaxUrl,
			method: 'POST',
			data: {
				action: 'ltms_get_redi_data',
				nonce:  nonce
			},
			success: function( res ) {
				$btn.prop( 'disabled', false ).text( '<?php echo esc_js( __( 'Explorar Productos ReDi', 'ltms' ) ); ?>' );

				if ( res.success && res.data && res.data.products && res.data.products.length ) {
					var products = res.data.products;
					var html     = '<div class="ltms-redi-products-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;margin-top:12px;">';

					$.each( products, function( i, product ) {
						var productName = $( '<div>' ).text( product.name || '' ).html();
						var vendorName  = $( '<div>' ).text( product.vendor_name || '' ).html();
						var rediRate    = parseFloat( product.redi_rate || 0 ).toFixed(2);
						var productUrl  = product.url ? product.url : '#';
						html += '<div class="ltms-redi-product-card" style="border:1px solid #ddd;border-radius:6px;padding:12px;">';
						html += '<strong><a href="' + productUrl + '" target="_blank" rel="noopener noreferrer">' + productName + '</a></strong>';
						html += '<p style="margin:4px 0 0;"><?php echo esc_js( __( 'Vendedor:', 'ltms' ) ); ?> ' + vendorName + '</p>';
						html += '<p style="margin:4px 0 0;"><?php echo esc_js( __( 'Tasa ReDi:', 'ltms' ) ); ?> ' + rediRate + '%</p>';
						html += '</div>';
					} );

					html += '</div>';
					$container.html( html );
				} else {
					$container.html( '<p><?php echo esc_js( __( 'No se encontraron productos ReDi disponibles en este momento.', 'ltms' ) ); ?></p>' );
				}
			},
			error: function() {
				$btn.prop( 'disabled', false ).text( '<?php echo esc_js( __( 'Explorar Productos ReDi', 'ltms' ) ); ?>' );
				$container.html( '<p><?php echo esc_js( __( 'Error de conexión. Intente nuevamente.', 'ltms' ) ); ?></p>' );
			}
		} );
	} );
}( jQuery ) );
</script>
