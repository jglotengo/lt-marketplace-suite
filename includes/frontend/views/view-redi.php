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
                                                                                        <a href="<?php echo esc_url( $product_url ); ?>" target="_blank" rel="noopener noreferrer">
                                                                                                <?php echo esc_html( $product_title ); ?>
                                                                                        </a>
                                                                                <?php else : ?>
                                                                                        <?php echo esc_html( $product_title ); ?>
                                                                                <?php endif; ?>
                                                                        </td>
                                                                        <td><?php echo esc_html( $origin_name ); ?></td>
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
        <!-- AUDIT-REDI-UX-GAPS GAP-3: SECTION 1.5 — Mis Productos ReDi (Origin) -->
        <!-- ============================================================ -->
        <?php
        $origin_products = class_exists( 'LTMS_Business_Redi_Manager' )
            ? LTMS_Business_Redi_Manager::get_origin_products_for_vendor( $vendor_id, 20, 0 )
            : [];
        $origin_count    = class_exists( 'LTMS_Business_Redi_Manager' )
            ? LTMS_Business_Redi_Manager::count_origin_products_for_vendor( $vendor_id )
            : 0;
        ?>
        <section class="ltms-redi-section" style="margin-bottom:32px;">
            <h3>📦 <?php esc_html_e( 'Mis Productos ReDi (Origin)', 'ltms' ); ?>
                <span class="ltms-badge" style="background:#E80001;color:#fff;padding:2px 8px;border-radius:10px;font-size:0.75rem;margin-left:8px;"><?php echo (int) $origin_count; ?></span>
            </h3>
            <p style="font-size:0.85rem;color:#666;margin-bottom:16px;">
                <?php esc_html_e( 'Productos que has habilitado para distribución ReDi. Los revendedores pueden adoptarlos y revenderlos. Tú mantienes el inventario y envías al cliente.', 'ltms' ); ?>
            </p>

            <?php if ( empty( $origin_products ) ) : ?>
                <div style="padding:24px;text-align:center;background:#f9f9f9;border-radius:8px;color:#999;">
                    <p style="font-size:1.1rem;margin-bottom:8px;">📋</p>
                    <p><?php esc_html_e( 'No tienes productos habilitados como origin ReDi.', 'ltms' ); ?></p>
                    <p style="font-size:0.85rem;"><?php esc_html_e( 'Ve a la sección Productos y activa "Habilitar ReDi" en cualquier producto para que los revendedores puedan distribuirlo.', 'ltms' ); ?></p>
                </div>
            <?php else : ?>
                <div class="ltms-table-responsive">
                    <table class="ltms-table" style="width:100%;">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Producto', 'ltms' ); ?></th>
                                <th><?php esc_html_e( 'Tasa ReDi', 'ltms' ); ?></th>
                                <th><?php esc_html_e( 'Revendedores activos', 'ltms' ); ?></th>
                                <th><?php esc_html_e( 'Comisión origin (mes)', 'ltms' ); ?></th>
                                <th><?php esc_html_e( 'Comisión origin (total)', 'ltms' ); ?></th>
                                <th><?php esc_html_e( 'Última venta', 'ltms' ); ?></th>
                                <th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
                                <th><?php esc_html_e( 'Acciones', 'ltms' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $origin_products as $p ) : ?>
                                <tr data-product-id="<?php echo (int) $p['ID']; ?>">
                                    <td>
                                        <strong><?php echo esc_html( $p['post_title'] ); ?></strong>
                                        <?php if ( ! empty( $p['resellers'] ) ) : ?>
                                            <div style="font-size:0.75rem;color:#666;margin-top:2px;">
                                                <?php
                                                $names = array_slice( array_map( fn( $r ) => esc_html( $r['name'] ), $p['resellers'] ), 0, 3 );
                                                echo implode( ', ', $names );
                                                if ( count( $p['resellers'] ) > 3 ) {
                                                    echo ' +' . ( count( $p['resellers'] ) - 3 );
                                                }
                                                ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html( number_format( (float) $p['redi_rate'] * 100, 1 ) ); ?>%</td>
                                    <td>
                                        <span style="font-weight:600;color:#27ae60;"><?php echo (int) $p['active_agreements']; ?></span>
                                        <?php if ( $p['paused_agreements'] > 0 ) : ?>
                                            <span style="color:#999;font-size:0.8rem;"> (+<?php echo (int) $p['paused_agreements']; ?> pausados)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html( wc_price( $p['month_origin_commission'] ) ); ?></td>
                                    <td><?php echo esc_html( wc_price( $p['total_origin_commission'] ) ); ?></td>
                                    <td><?php echo $p['last_sale'] ? esc_html( $p['last_sale'] ) : '—'; ?></td>
                                    <td>
                                        <?php if ( $p['is_paused'] ) : ?>
                                            <span style="color:#F0B500;font-weight:600;">⏸️ Pausado</span>
                                        <?php else : ?>
                                            <span style="color:#27ae60;font-weight:600;">✓ Activo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ( $p['is_paused'] ) : ?>
                                            <button type="button" class="ltms-btn ltms-btn-sm ltms-btn-primary ltms-redi-resume-btn"
                                                    data-product-id="<?php echo (int) $p['ID']; ?>"
                                                    style="padding:4px 12px;font-size:0.8rem;">
                                                ▶️ <?php esc_html_e( 'Reanudar', 'ltms' ); ?>
                                            </button>
                                        <?php else : ?>
                                            <button type="button" class="ltms-btn ltms-btn-sm ltms-btn-secondary ltms-redi-pause-btn"
                                                    data-product-id="<?php echo (int) $p['ID']; ?>"
                                                    style="padding:4px 12px;font-size:0.8rem;">
                                                ⏸️ <?php esc_html_e( 'Pausar', 'ltms' ); ?>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
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
                                                                        <td><?php echo esc_html( $created_at ); ?></td>
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
                        data-nonce="<?php echo esc_attr( wp_create_nonce( 'ltms_dashboard_nonce' ) ); ?>"
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

        // AUDIT-REDI-UX-GAPS GAP-3 FIX: soft pause/resume handlers.
        // v2.9.61 DEEP-AUDIT-002 P0-1 FIX: El PHP espera 'origin_product_id' (no 'product_id').
        // Antes todos los clicks retornaban 400 "ID de producto origen inválido".
        $(document).on('click', '.ltms-redi-pause-btn', function(e){
                e.preventDefault();
                var $btn = $(this);
                var productId = $btn.data('product-id');
                if (!productId) return;
                if (!confirm('<?php echo esc_js( __( '¿Pausar distribución ReDi de este producto? Las copias de los revendedores se marcarán como agotadas y serán notificados. Tu producto seguirá visible para venta directa.', 'ltms' ) ); ?>')) return;
                $btn.prop('disabled', true);
                $.post(ajaxurl, {
                        action: 'ltms_redi_soft_pause',
                        nonce: (typeof ltmsDashboard !== 'undefined') ? ltmsDashboard.nonce : '',
                        origin_product_id: productId
                }, function(resp){
                        if (resp && resp.success) {
                                // toast: ReDi pausado
                                LTMS.UX.toastSuccess('Exito', '<?php echo esc_js( __( 'ReDi pausado. Los revendedores han sido notificados.', 'ltms' ) ); ?>');
                                LTMS.Dashboard.loadView('redi', true);
                        } else {
                                $btn.prop('disabled', false);
                                LTMS.UX.toastError('Error', resp && resp.data ? resp.data.message : '<?php echo esc_js( __( 'Error al pausar.', 'ltms' ) ); ?>');
                        }
                }, 'json').fail(function(){
                        $btn.prop('disabled', false);
                        LTMS.UX.toastError('Error', '<?php echo esc_js( __( 'Error de conexión.', 'ltms' ) ); ?>');
                });
        });

        $(document).on('click', '.ltms-redi-resume-btn', function(e){
                e.preventDefault();
                var $btn = $(this);
                var productId = $btn.data('product-id');
                if (!productId) return;
                $btn.prop('disabled', true);
                $.post(ajaxurl, {
                        action: 'ltms_redi_soft_resume',
                        nonce: (typeof ltmsDashboard !== 'undefined') ? ltmsDashboard.nonce : '',
                        origin_product_id: productId
                }, function(resp){
                        if (resp && resp.success) {
                                LTMS.UX.toastSuccess('Exito', '<?php echo esc_js( __( 'ReDi reanudado. Los revendedores han sido notificados.', 'ltms' ) ); ?>');
                                LTMS.Dashboard.loadView('redi', true);
                        } else {
                                $btn.prop('disabled', false);
                                LTMS.UX.toastError('Error', resp && resp.data ? resp.data.message : '<?php echo esc_js( __( 'Error al reanudar.', 'ltms' ) ); ?>');
                        }
                }, 'json').fail(function(){
                        $btn.prop('disabled', false);
                        LTMS.UX.toastError('Error', '<?php echo esc_js( __( 'Error de conexión.', 'ltms' ) ); ?>');
                });
        });
}( jQuery ) );
</script>
