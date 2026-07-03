<?php
/**
 * Vista: Admin Orders - Pedidos de la Plataforma
 *
 * @package LTMS
 * @version 1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$page_num      = max( 1, (int) ( $_GET['paged'] ?? 1 ) );       // phpcs:ignore
$per_page      = 25;
$status_filter = sanitize_key( $_GET['status'] ?? '' );          // phpcs:ignore
$vendor_filter = (int) ( $_GET['vendor_id'] ?? 0 );             // phpcs:ignore
$search        = sanitize_text_field( $_GET['s'] ?? '' );        // phpcs:ignore
$date_from     = sanitize_text_field( $_GET['date_from'] ?? '' );// phpcs:ignore
$date_to       = sanitize_text_field( $_GET['date_to'] ?? '' );  // phpcs:ignore
$base_url      = admin_url( 'admin.php?page=ltms-orders' );

// ── Conteo total para paginación (sin limit) ──────────────────────────────
$count_args = [
    'limit'   => -1,
    'return'  => 'ids',
    'type'    => 'shop_order',
];
if ( $status_filter ) $count_args['status'] = 'wc-' . $status_filter;
if ( $vendor_filter ) $count_args['meta_query'] = [[ 'key' => '_ltms_vendor_id', 'value' => $vendor_filter ]]; // phpcs:ignore
if ( $search ) $count_args['customer'] = $search;
$total_ids   = wc_get_orders( $count_args );
$total       = count( $total_ids );
$total_pages = (int) ceil( $total / $per_page );

// ── Query paginada ─────────────────────────────────────────────────────────
$wc_args = [
    'limit'   => $per_page,
    'paged'   => $page_num,
    'orderby' => 'date',
    'order'   => 'DESC',
    'type'    => 'shop_order',
];
if ( $status_filter ) $wc_args['status'] = 'wc-' . $status_filter;
if ( $vendor_filter ) $wc_args['meta_query'] = [[ 'key' => '_ltms_vendor_id', 'value' => $vendor_filter ]]; // phpcs:ignore
if ( $search )        $wc_args['customer']   = $search;
if ( $date_from && $date_to ) $wc_args['date_created'] = $date_from . '...' . $date_to;
elseif ( $date_from ) $wc_args['date_created'] = '>=' . $date_from;

$orders = wc_get_orders( $wc_args );

// ── Estados WooCommerce disponibles ───────────────────────────────────────
$wc_statuses = wc_get_order_statuses();

// ── URL helper ────────────────────────────────────────────────────────────
function ltms_orders_url( array $overrides = [] ): string {
    $params = array_filter( [
        'page'      => 'ltms-orders',
        'paged'     => (int) ( $_GET['paged'] ?? 1 ),                  // phpcs:ignore
        's'         => sanitize_text_field( $_GET['s'] ?? '' ),         // phpcs:ignore
        'status'    => sanitize_key( $_GET['status'] ?? '' ),           // phpcs:ignore
        'vendor_id' => (int) ( $_GET['vendor_id'] ?? 0 ),              // phpcs:ignore
        'date_from' => sanitize_text_field( $_GET['date_from'] ?? '' ), // phpcs:ignore
        'date_to'   => sanitize_text_field( $_GET['date_to'] ?? '' ),   // phpcs:ignore
    ] );
    $params = array_merge( $params, $overrides );
    return admin_url( 'admin.php?' . http_build_query( $params ) );
}

function ltms_order_badge_class( string $status ): string {
    return [
        'completed'  => 'ltms-badge-success',
        'processing' => 'ltms-badge-info',
        'pending'    => 'ltms-badge-warning',
        'on-hold'    => 'ltms-badge-warning',
        'cancelled'  => 'ltms-badge-danger',
        'refunded'   => 'ltms-badge-danger',
        'failed'     => 'ltms-badge-danger',
    ][ $status ] ?? 'ltms-badge-pending';
}

$has_filters = $status_filter || $vendor_filter || $search || $date_from || $date_to;
?>
<div class="wrap ltms-admin-wrap">

    <div class="ltms-header">
        <h1><?php esc_html_e( 'Pedidos de la Plataforma', 'ltms' ); ?></h1>
    </div>

    <!-- ── Barra de filtros ── -->
    <form method="get" style="margin-bottom:16px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <input type="hidden" name="page" value="ltms-orders">

        <input type="text" name="s" value="<?php echo esc_attr( $search ); ?>"
               placeholder="<?php esc_attr_e( 'Cliente o email...', 'ltms' ); ?>"
               style="padding:8px 12px;border:1px solid #ddd;border-radius:4px;width:190px;">

        <select name="status" style="padding:8px;border:1px solid #ddd;border-radius:4px;">
            <option value=""><?php esc_html_e( 'Todos los estados', 'ltms' ); ?></option>
            <?php foreach ( $wc_statuses as $slug => $label ) :
                $slug_clean = str_replace( 'wc-', '', $slug );
            ?>
            <option value="<?php echo esc_attr( $slug_clean ); ?>" <?php selected( $status_filter, $slug_clean ); ?>>
                <?php echo esc_html( $label ); ?>
            </option>
            <?php endforeach; ?>
        </select>

        <input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>"
               title="<?php esc_attr_e( 'Desde', 'ltms' ); ?>"
               style="padding:8px;border:1px solid #ddd;border-radius:4px;">

        <input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>"
               title="<?php esc_attr_e( 'Hasta', 'ltms' ); ?>"
               style="padding:8px;border:1px solid #ddd;border-radius:4px;">

        <button type="submit" class="ltms-btn ltms-btn-primary ltms-btn-sm">
            🔍 <?php esc_html_e( 'Filtrar', 'ltms' ); ?>
        </button>

        <?php if ( $has_filters ) : ?>
        <a href="<?php echo esc_url( $base_url ); ?>" class="ltms-btn ltms-btn-outline ltms-btn-sm">
            ✕ <?php esc_html_e( 'Limpiar', 'ltms' ); ?>
        </a>
        <?php endif; ?>
    </form>

    <div class="ltms-table-wrap">

        <div class="ltms-table-title" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
            <span>
                <?php printf( esc_html__( '%d pedidos', 'ltms' ), $total ); ?>
                <?php if ( $total_pages > 1 ) : ?>
                — <?php printf( esc_html__( 'Página %1$d de %2$d', 'ltms' ), $page_num, $total_pages ); ?>
                <?php endif; ?>
            </span>

            <?php if ( $total_pages > 1 ) : ?>
            <span style="display:flex;gap:4px;flex-wrap:wrap;">
                <?php if ( $page_num > 1 ) : ?>
                <a href="<?php echo esc_url( ltms_orders_url( [ 'paged' => $page_num - 1 ] ) ); ?>"
                   class="ltms-btn ltms-btn-outline ltms-btn-sm">‹ <?php esc_html_e( 'Anterior', 'ltms' ); ?></a>
                <?php endif; ?>
                <?php for ( $p = 1; $p <= $total_pages; $p++ ) : ?>
                    <?php if ( abs( $p - $page_num ) <= 2 || $p === 1 || $p === $total_pages ) : ?>
                    <a href="<?php echo esc_url( ltms_orders_url( [ 'paged' => $p ] ) ); ?>"
                       class="ltms-btn ltms-btn-sm <?php echo $p === $page_num ? 'ltms-btn-primary' : 'ltms-btn-outline'; ?>"
                       style="min-width:30px;text-align:center;"><?php echo esc_html( $p ); ?></a>
                    <?php elseif ( abs( $p - $page_num ) === 3 ) : ?>
                    <span style="padding:4px 2px;color:#888;">…</span>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ( $page_num < $total_pages ) : ?>
                <a href="<?php echo esc_url( ltms_orders_url( [ 'paged' => $page_num + 1 ] ) ); ?>"
                   class="ltms-btn ltms-btn-outline ltms-btn-sm"><?php esc_html_e( 'Siguiente', 'ltms' ); ?> ›</a>
                <?php endif; ?>
            </span>
            <?php endif; ?>
        </div>

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
                    $vendor_user = $vendor_id ? get_userdata( $vendor_id ) : false;
                    $vendor_name = ( $vendor_user && $vendor_user->display_name ) ? $vendor_user->display_name : '—';
                    $status      = $order->get_status();
                    $status_label= wc_get_order_status_name( $status );
                    $badge_class = ltms_order_badge_class( $status );
                    $edit_link   = get_edit_post_link( $order->get_id() ) ?: admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' );
                ?>
                <tr>
                    <td>
                        <strong><a href="<?php echo esc_url( $edit_link ); ?>" style="text-decoration:none;">
                            #<?php echo esc_html( $order->get_order_number() ); ?>
                        </a></strong>
                    </td>
                    <td>
                        <?php echo esc_html( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ?: '—' ); ?><br>
                        <small style="color:#888;"><?php echo esc_html( $order->get_billing_email() ); ?></small>
                    </td>
                    <td><?php echo esc_html( $vendor_name ); ?></td>
                    <td><strong><?php echo esc_html( LTMS_Utils::format_money( (float) $order->get_total() ) ); ?></strong></td>
                    <td>
                        <span class="ltms-badge <?php echo esc_attr( $badge_class ); ?>">
                            <?php echo esc_html( strtoupper( $status_label ) ); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html( $order->get_date_created() ? $order->get_date_created()->date( 'd/m/Y' ) : '—' ); ?></td>
                    <td style="display:flex;gap:4px;flex-wrap:wrap;">
                        <a href="<?php echo esc_url( $edit_link ); ?>" class="ltms-btn ltms-btn-outline ltms-btn-sm">
                            👁 <?php esc_html_e( 'Ver', 'ltms' ); ?>
                        </a>
                        <?php if ( 'pending' === $status || 'on-hold' === $status ) : ?>
                        <button type="button" class="ltms-btn ltms-btn-success ltms-btn-sm"
                                onclick="if(confirm('<?php esc_attr_e( '¿Marcar este pedido como Procesando?', 'ltms' ); ?>')) LTMS.Admin.ajaxAction('ltms_update_order_status',{order_id:<?php echo esc_js( $order->get_id() ); ?>,status:'processing'},function(r){if(r.success)location.reload();else alert(r.data||'Error');})">
                            ▶ <?php esc_html_e( 'Procesar', 'ltms' ); ?>
                        </button>
                        <?php endif; ?>
                        <?php if ( ! in_array( $status, [ 'cancelled', 'refunded', 'completed' ], true ) ) : ?>
                        <button type="button" class="ltms-btn ltms-btn-danger ltms-btn-sm"
                                onclick="if(confirm('<?php esc_attr_e( '¿Cancelar este pedido?', 'ltms' ); ?>')) LTMS.Admin.ajaxAction('ltms_update_order_status',{order_id:<?php echo esc_js( $order->get_id() ); ?>,status:'cancelled'},function(r){if(r.success)location.reload();else alert(r.data||'Error');})">
                            ✕ <?php esc_html_e( 'Cancelar', 'ltms' ); ?>
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ( $total_pages > 1 ) : ?>
        <div style="display:flex;justify-content:center;align-items:center;gap:6px;padding:16px;flex-wrap:wrap;">
            <?php if ( $page_num > 1 ) : ?>
            <a href="<?php echo esc_url( ltms_orders_url( [ 'paged' => 1 ] ) ); ?>"
               class="ltms-btn ltms-btn-outline ltms-btn-sm">« <?php esc_html_e( 'Primera', 'ltms' ); ?></a>
            <a href="<?php echo esc_url( ltms_orders_url( [ 'paged' => $page_num - 1 ] ) ); ?>"
               class="ltms-btn ltms-btn-outline ltms-btn-sm">‹ <?php esc_html_e( 'Anterior', 'ltms' ); ?></a>
            <?php endif; ?>
            <?php for ( $p = 1; $p <= $total_pages; $p++ ) : ?>
                <?php if ( abs( $p - $page_num ) <= 2 || $p === 1 || $p === $total_pages ) : ?>
                <a href="<?php echo esc_url( ltms_orders_url( [ 'paged' => $p ] ) ); ?>"
                   class="ltms-btn ltms-btn-sm <?php echo $p === $page_num ? 'ltms-btn-primary' : 'ltms-btn-outline'; ?>"
                   style="min-width:32px;text-align:center;"><?php echo esc_html( $p ); ?></a>
                <?php elseif ( abs( $p - $page_num ) === 3 ) : ?>
                <span style="padding:6px 2px;color:#888;">…</span>
                <?php endif; ?>
            <?php endfor; ?>
            <?php if ( $page_num < $total_pages ) : ?>
            <a href="<?php echo esc_url( ltms_orders_url( [ 'paged' => $page_num + 1 ] ) ); ?>"
               class="ltms-btn ltms-btn-outline ltms-btn-sm"><?php esc_html_e( 'Siguiente', 'ltms' ); ?> ›</a>
            <a href="<?php echo esc_url( ltms_orders_url( [ 'paged' => $total_pages ] ) ); ?>"
               class="ltms-btn ltms-btn-outline ltms-btn-sm"><?php esc_html_e( 'Última', 'ltms' ); ?> »</a>
            <?php endif; ?>
            <span style="font-size:12px;color:#666;margin-left:8px;">
                <?php printf(
                    esc_html__( 'Mostrando %1$d–%2$d de %3$d pedidos', 'ltms' ),
                    ( ( $page_num - 1 ) * $per_page ) + 1,
                    min( $page_num * $per_page, $total ),
                    $total
                ); ?>
            </span>
        </div>
        <?php endif; ?>

    </div><!-- .ltms-table-wrap -->

</div><!-- .wrap -->
