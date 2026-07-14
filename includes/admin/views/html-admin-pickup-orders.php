<?php
/**
 * Vista: Admin Pickup Orders - Pedidos para Recogida
 *
 * @package LTMS
 * @version 2.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$tab       = sanitize_key( $_GET['tab'] ?? 'pending' ); // phpcs:ignore
$page_num  = max( 1, (int) ( $_GET['paged'] ?? 1 ) );   // phpcs:ignore
$per_page  = 25;
$base_url  = admin_url( 'admin.php?page=ltms-pickup-orders' );

$date_from = sanitize_text_field( $_GET['date_from'] ?? '' ); // phpcs:ignore
$date_to   = sanitize_text_field( $_GET['date_to']   ?? '' ); // phpcs:ignore
$search    = sanitize_text_field( $_GET['s']          ?? '' ); // phpcs:ignore

// Contar pendientes para el badge
$pending_count = count( wc_get_orders( [
    'status'  => 'wc-ready-for-pickup',
    'limit'   => -1,
    'return'  => 'ids',
] ) );

// Contar completados hoy
$completed_today = count( wc_get_orders( [
    'status'       => [ 'wc-completed' ],
    'limit'        => -1,
    'return'       => 'ids',
    'date_created' => gmdate( 'Y-m-d' ) . '...' . gmdate( 'Y-m-d', strtotime( '+1 day' ) ),
] ) );

// Query según tab
$query_args = [
    'limit'   => $per_page,
    'paged'   => $page_num,
    'orderby' => 'date',
    'order'   => 'DESC',
];

if ( $tab === 'pending' ) {
    $query_args['status'] = 'wc-ready-for-pickup';
} else {
    $query_args['status'] = [ 'wc-completed' ];
    if ( $date_from ) $query_args['date_created'] = $date_from . ( $date_to ? '...' . $date_to : '' );
}
if ( $search ) $query_args['customer'] = $search;

// Total para paginación
$count_args          = $query_args;
$count_args['limit'] = -1;
$count_args['return']= 'ids';
unset( $count_args['paged'] );
$total       = count( wc_get_orders( $count_args ) );
$total_pages = max( 1, (int) ceil( $total / $per_page ) );

$orders = wc_get_orders( $query_args );
?>
<div class="wrap ltms-admin-wrap">

    <div class="ltms-header">
        <h1><?php esc_html_e( 'Pedidos para Recogida', 'ltms' ); ?></h1>
    </div>

    <!-- Stats rápidas -->
    <div class="ltms-stats-grid" style="margin-bottom:20px;">
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Esperando recogida', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="color:<?php echo $pending_count > 0 ? '#f59e0b' : '#16a34a'; ?>;">
                <?php echo esc_html( $pending_count ); ?>
            </span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Recogidos hoy', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="color:#16a34a;"><?php echo esc_html( $completed_today ); ?></span>
        </div>
    </div>

    <!-- Tabs -->
    <div style="display:flex;gap:0;border-bottom:2px solid #e5e7eb;margin-bottom:20px;">
        <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'ltms-pickup-orders', 'tab' => 'pending' ], admin_url( 'admin.php' ) ) ); ?>"
           style="padding:10px 20px;text-decoration:none;font-weight:600;border-bottom:2px solid <?php echo $tab === 'pending' ? '#2563eb' : 'transparent'; ?>;margin-bottom:-2px;color:<?php echo $tab === 'pending' ? '#2563eb' : '#6b7280'; ?>;">
            <?php esc_html_e( 'Pendientes', 'ltms' ); ?>
            <?php if ( $pending_count > 0 ) : ?>
            <span style="background:#f59e0b;color:#fff;border-radius:999px;padding:1px 7px;font-size:11px;margin-left:4px;">
                <?php echo esc_html( $pending_count ); ?>
            </span>
            <?php endif; ?>
        </a>
        <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'ltms-pickup-orders', 'tab' => 'history' ], admin_url( 'admin.php' ) ) ); ?>"
           style="padding:10px 20px;text-decoration:none;font-weight:600;border-bottom:2px solid <?php echo $tab === 'history' ? '#2563eb' : 'transparent'; ?>;margin-bottom:-2px;color:<?php echo $tab === 'history' ? '#2563eb' : '#6b7280'; ?>;">
            <?php esc_html_e( 'Historial', 'ltms' ); ?>
        </a>
    </div>

    <?php if ( $tab === 'history' ) : ?>
    <!-- Filtros historial -->
    <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:16px;">
        <input type="hidden" name="page" value="ltms-pickup-orders">
        <input type="hidden" name="tab" value="history">
        <input type="text" name="s" value="<?php echo esc_attr( $search ); ?>"
               placeholder="<?php esc_attr_e( 'Cliente o email...', 'ltms' ); ?>"
               style="padding:7px 12px;border:1px solid #ddd;border-radius:4px;width:180px;">
        <input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>"
               style="padding:7px;border:1px solid #ddd;border-radius:4px;">
        <input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>"
               style="padding:7px;border:1px solid #ddd;border-radius:4px;">
        <button type="submit" class="ltms-btn ltms-btn-primary ltms-btn-sm">
            &#x1F50D; <?php esc_html_e( 'Filtrar', 'ltms' ); ?>
        </button>
        <?php if ( $search || $date_from || $date_to ) : ?>
        <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'ltms-pickup-orders', 'tab' => 'history' ], admin_url( 'admin.php' ) ) ); ?>"
           class="ltms-btn ltms-btn-outline ltms-btn-sm">&#x2715; <?php esc_html_e( 'Limpiar', 'ltms' ); ?></a>
        <?php endif; ?>
        <span style="font-size:12px;color:#888;margin-left:auto;">
            <?php printf( esc_html__( '%d registros', 'ltms' ), $total ); ?>
        </span>
    </form>
    <?php endif; ?>

    <div class="ltms-table-wrap">

        <!-- Cabecera + paginación superior -->
        <?php if ( $total_pages > 1 ) : ?>
        <div style="display:flex;justify-content:flex-end;gap:4px;padding:8px 0;flex-wrap:wrap;">
            <?php for ( $p = 1; $p <= $total_pages; $p++ ) : ?>
            <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'ltms-pickup-orders', 'tab' => $tab, 'paged' => $p, 's' => $search, 'date_from' => $date_from, 'date_to' => $date_to ], admin_url( 'admin.php' ) ) ); ?>"
               class="ltms-btn ltms-btn-sm <?php echo $p === $page_num ? 'ltms-btn-primary' : 'ltms-btn-outline'; ?>"
               style="min-width:30px;text-align:center;"><?php echo esc_html( $p ); ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>

        <table class="ltms-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Pedido', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Cliente', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Vendedor / Tienda', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Total', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Direccion del Local', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Horario', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Fecha', 'ltms' ); ?></th>
                    <?php if ( $tab === 'pending' ) : ?>
                    <th><?php esc_html_e( 'Accion', 'ltms' ); ?></th>
                    <?php else : ?>
                    <th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $orders ) ) : ?>
                <tr>
                    <td colspan="8" style="text-align:center;padding:40px;color:#888;">
                        <?php if ( $tab === 'pending' ) : ?>
                            &#x2705; <?php esc_html_e( 'No hay pedidos pendientes de recogida.', 'ltms' ); ?>
                        <?php else : ?>
                            <?php esc_html_e( 'No hay registros en el historial para los filtros aplicados.', 'ltms' ); ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php else : ?>
                <?php foreach ( $orders as $order ) :
                    $order_id      = $order->get_id();
                    $customer      = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ?: '—';
                    $vendor_id     = (int) $order->get_meta( '_ltms_vendor_id' );
                    $vendor_data   = $vendor_id ? get_userdata( $vendor_id ) : false;
                    $vendor_name   = $vendor_data ? $vendor_data->display_name : '—';
                    $store_name    = $vendor_id ? get_user_meta( $vendor_id, 'ltms_store_name', true ) : '';
                    $store_address = $vendor_id ? get_user_meta( $vendor_id, 'ltms_store_address', true ) : '';
                    $store_hours   = $vendor_id ? get_user_meta( $vendor_id, 'ltms_store_hours', true ) : '';
                    $date_created  = $order->get_date_created();
                    $status        = $order->get_status();
                    $status_label  = wc_get_order_status_name( $status );
                ?>
                <tr>
                    <td>
                        <strong>
                            <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $order_id . '&action=edit' ) ); ?>" style="text-decoration:none;">
                                #<?php echo esc_html( $order->get_order_number() ); ?>
                            </a>
                        </strong>
                    </td>
                    <td>
                        <?php echo esc_html( $customer ); ?><br>
                        <small style="color:#888;"><?php echo esc_html( $order->get_billing_email() ); ?></small>
                    </td>
                    <td>
                        <?php echo esc_html( $vendor_name ); ?>
                        <?php if ( $store_name ) : ?>
                        <br><small style="color:#888;"><?php echo esc_html( $store_name ); ?></small>
                        <?php endif; ?>
                    </td>
                    <td><strong><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></strong></td>
                    <td style="font-size:12px;">
                        <?php echo $store_address ? esc_html( $store_address ) : '<span style="color:#ccc;">—</span>'; // phpcs:ignore ?>
                    </td>
                    <td style="font-size:12px;">
                        <?php echo $store_hours ? esc_html( $store_hours ) : '<span style="color:#ccc;">—</span>'; // phpcs:ignore ?>
                    </td>
                    <td style="white-space:nowrap;font-size:12px;">
                        <?php echo esc_html( $date_created ? $date_created->date( 'd/m/Y H:i' ) : '—' ); ?>
                    </td>
                    <?php if ( $tab === 'pending' ) : ?>
                    <td>
                        <button class="ltms-btn ltms-btn-success ltms-btn-sm ltms-mark-pickup-completed"
                                data-order-id="<?php echo esc_attr( $order_id ); ?>"
                                data-nonce="<?php echo esc_attr( wp_create_nonce( 'ltms_admin_nonce' ) ); ?>">
                            &#x2705; <?php esc_html_e( 'Marcar Entregado', 'ltms' ); ?>
                        </button>
                    </td>
                    <?php else : ?>
                    <td>
                        <span class="ltms-badge ltms-badge-success"><?php echo esc_html( strtoupper( $status_label ) ); ?></span>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Paginación inferior -->
        <?php if ( $total_pages > 1 ) : ?>
        <div style="display:flex;justify-content:center;gap:6px;padding:16px;flex-wrap:wrap;">
            <?php for ( $p = 1; $p <= $total_pages; $p++ ) : ?>
            <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'ltms-pickup-orders', 'tab' => $tab, 'paged' => $p, 's' => $search, 'date_from' => $date_from, 'date_to' => $date_to ], admin_url( 'admin.php' ) ) ); ?>"
               class="ltms-btn ltms-btn-sm <?php echo $p === $page_num ? 'ltms-btn-primary' : 'ltms-btn-outline'; ?>"
               style="min-width:32px;text-align:center;"><?php echo esc_html( $p ); ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>

    </div>

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
        if ( ! window.confirm( '<?php echo esc_js( __( "Confirmar que el pedido fue recogido por el cliente?", "ltms" ) ); ?>' ) ) return;
        $btn.prop( 'disabled', true ).text( '<?php echo esc_js( __( "Procesando...", "ltms" ) ); ?>' );
        $.post( ajaxurl, {
            action:   'ltms_mark_pickup_completed',
            order_id: orderId,
            nonce:    nonce
        }, function( res ) {
            if ( res.success ) {
                $btn.closest( 'tr' ).fadeOut( 400, function() { $( this ).remove(); } );
            } else {
                console.warn( res.data || '<?php echo esc_js( __( "Error al actualizar el pedido.", "ltms" ) ); ?>' );
                $btn.prop( 'disabled', false ).text( '<?php echo esc_js( __( "Marcar Entregado", "ltms" ) ); ?>' );
            }
        } ).fail( function() {
            console.warn( '<?php echo esc_js( __( "Error de conexion. Intente nuevamente.", "ltms" ) ); ?>' );
            $btn.prop( 'disabled', false ).text( '<?php echo esc_js( __( "Marcar Entregado", "ltms" ) ); ?>' );
        } );
    } );
}( jQuery ) );
</script>
