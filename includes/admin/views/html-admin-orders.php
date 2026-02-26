<?php
/**
 * Vista: Admin Orders - Pedidos de la Plataforma
 *
 * @package LTMS
 * @version 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$page_num      = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore
$status_filter = sanitize_key( $_GET['status'] ?? '' ); // phpcs:ignore
$vendor_filter = (int) ( $_GET['vendor_id'] ?? 0 ); // phpcs:ignore

$wc_args = [
    'limit'   => 25,
    'paged'   => $page_num,
    'orderby' => 'date',
    'order'   => 'DESC',
    'type'    => 'shop_order',
];

if ( $status_filter ) {
    $wc_args['status'] = 'wc-' . $status_filter;
}
if ( $vendor_filter ) {
    $wc_args['meta_query'] = [[ 'key' => '_ltms_vendor_id', 'value' => $vendor_filter ]]; // phpcs:ignore
}

$orders = wc_get_orders( $wc_args );
?>
<div class="wrap ltms-admin-wrap">

    <div class="ltms-header">
        <h1><?php esc_html_e( 'Pedidos de la Plataforma', 'ltms' ); ?></h1>
    </div>

    <!-- Filtros -->
    <form method="get" style="margin-bottom:16px;display:flex;gap:8px;flex-wrap:wrap;">
        <input type="hidden" name="page" value="ltms-orders">
        <select name="status" style="padding:8px 12px;border:1px solid #ddd;border-radius:4px;">
            <option value=""><?php esc_html_e( 'Todos los estados', 'ltms' ); ?></option>
            <option value="pending" <?php selected( $status_filter, 'pending' ); ?>><?php esc_html_e( 'Pendiente', 'ltms' ); ?></option>
            <option value="processing" <?php selected( $status_filter, 'processing' ); ?>><?php esc_html_e( 'Procesando', 'ltms' ); ?></option>
            <option value="completed" <?php selected( $status_filter, 'completed' ); ?>><?php esc_html_e( 'Completado', 'ltms' ); ?></option>
            <option value="cancelled" <?php selected( $status_filter, 'cancelled' ); ?>><?php esc_html_e( 'Cancelado', 'ltms' ); ?></option>
        </select>
        <button type="submit" class="ltms-btn ltms-btn-primary ltms-btn-sm"><?php esc_html_e( 'Filtrar', 'ltms' ); ?></button>
    </form>

    <div class="ltms-table-wrap">
        <table class="ltms-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Pedido', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Cliente', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Vendedor', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Total', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Fecha', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Acciones', 'ltms' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $orders ) ) : ?>
                <tr><td colspan="7" style="text-align:center;padding:30px;color:#888;">
                    <?php esc_html_e( 'No hay pedidos.', 'ltms' ); ?>
                </td></tr>
                <?php else : ?>
                <?php foreach ( $orders as $order ) :
                    $vendor_id   = (int) $order->get_meta( '_ltms_vendor_id' );
                    $vendor_name = $vendor_id ? get_userdata( $vendor_id )->display_name ?? '—' : '—';
                    $status_class = [
                        'completed'  => 'ltms-badge-success',
                        'processing' => 'ltms-badge-info',
                        'pending'    => 'ltms-badge-warning',
                        'cancelled'  => 'ltms-badge-danger',
                    ][ $order->get_status() ] ?? 'ltms-badge-pending';
                ?>
                <tr>
                    <td><strong>#<?php echo esc_html( $order->get_order_number() ); ?></strong></td>
                    <td><?php echo esc_html( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ); ?></td>
                    <td><?php echo esc_html( $vendor_name ); ?></td>
                    <td><strong><?php echo esc_html( LTMS_Utils::format_money( (float) $order->get_total() ) ); ?></strong></td>
                    <td><span class="ltms-badge <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></span></td>
                    <td><?php echo esc_html( $order->get_date_created() ? $order->get_date_created()->date( 'd/m/Y' ) : '—' ); ?></td>
                    <td>
                        <a href="<?php echo esc_url( get_edit_post_link( $order->get_id() ) ); ?>" class="ltms-btn ltms-btn-outline ltms-btn-sm">
                            👁 <?php esc_html_e( 'Ver', 'ltms' ); ?>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>
