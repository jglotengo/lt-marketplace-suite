<?php
/**
 * Vista admin: Lista de reservas
 *
 * @var array  $bookings
 * @var string $status
 * @var string $date_from
 * @var string $date_to
 * @var int    $page
 * @var int    $per_page
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$statuses = [
    ''            => __( 'Todos', 'ltms' ),
    'pending'     => __( 'Pendiente', 'ltms' ),
    'confirmed'   => __( 'Confirmada', 'ltms' ),
    'checked_in'  => __( 'Check-in', 'ltms' ),
    'checked_out' => __( 'Check-out', 'ltms' ),
    'cancelled'   => __( 'Cancelada', 'ltms' ),
    'completed'   => __( 'Completada', 'ltms' ),
];
$status_colors = [ 'pending' => '#ffc107', 'confirmed' => '#28a745', 'checked_in' => '#007bff', 'checked_out' => '#6c757d', 'cancelled' => '#dc3545', 'completed' => '#20c997' ];
?>
<div class="wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e( 'Reservas', 'ltms' ); ?></h1>
    <form method="GET" style="margin:12px 0;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <input type="hidden" name="page" value="ltms-bookings">
        <select name="status">
            <?php foreach ( $statuses as $val => $label ) : ?>
                <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $status ?? '', $val ); ?>><?php echo esc_html( $label ); ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="date_from" value="<?php echo esc_attr( $date_from ?? '' ); ?>">
        <input type="date" name="date_to"   value="<?php echo esc_attr( $date_to   ?? '' ); ?>">
        <button class="button" type="submit"><?php esc_html_e( 'Filtrar', 'ltms' ); ?></button>
        <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ltms_export_bookings_csv' ), 'ltms_export_bookings_csv' ) ); ?>">
            <?php esc_html_e( '⬇ CSV', 'ltms' ); ?>
        </a>
    </form>
    <table class="wp-list-table widefat fixed striped" style="font-size:13px;">
        <thead><tr>
            <th width="60">#ID</th>
            <th><?php esc_html_e( 'Producto', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Cliente', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Check-in', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Check-out', 'ltms' ); ?></th>
            <th width="60"><?php esc_html_e( 'Noches', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Total', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Acciones', 'ltms' ); ?></th>
        </tr></thead>
        <tbody>
            <?php if ( empty( $bookings ) ) : ?>
                <tr><td colspan="9" style="text-align:center;padding:20px;color:#666;"><?php esc_html_e( 'Sin reservas con los filtros actuales.', 'ltms' ); ?></td></tr>
            <?php else : ?>
                <?php foreach ( $bookings as $b ) :
                    $nights   = max( 0, (int) round( ( strtotime( $b['checkout_date'] ) - strtotime( $b['checkin_date'] ) ) / DAY_IN_SECONDS ) );
                    $customer = get_user_by( 'id', (int) $b['customer_id'] );
                    $color    = $status_colors[ $b['status'] ] ?? '#999';
                    ?>
                    <tr>
                        <td><strong><?php echo (int) $b['id']; ?></strong><br><small>WC#<?php echo (int) $b['wc_order_id']; ?></small></td>
                        <td><?php echo esc_html( $b['product_name'] ?? '—' ); ?></td>
                        <td><?php echo $customer ? esc_html( $customer->display_name ) : '#' . (int) $b['customer_id']; ?></td>
                        <td><?php echo esc_html( $b['checkin_date'] ); ?></td>
                        <td><?php echo esc_html( $b['checkout_date'] ); ?></td>
                        <td><?php echo (int) $nights; ?></td>
                        <td><?php echo esc_html( number_format( (float) $b['total_price'], 0, ',', '.' ) . ' ' . $b['currency'] ); ?></td>
                        <td><span style="background:<?php echo esc_attr( $color ); ?>;color:#fff;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;"><?php echo esc_html( $statuses[ $b['status'] ] ?? $b['status'] ); ?></span></td>
                        <td>
                            <?php if ( ! in_array( $b['status'], [ 'cancelled', 'checked_out', 'completed' ], true ) ) : ?>
                                <button class="button button-small ltms-cancel-booking"
                                    data-id="<?php echo (int) $b['id']; ?>"
                                    data-nonce="<?php echo esc_attr( wp_create_nonce( 'ltms_admin_booking' ) ); ?>">
                                    <?php esc_html_e( 'Cancelar', 'ltms' ); ?>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<script>
jQuery(function($){
    $('.ltms-cancel-booking').on('click',function(){
        if(!confirm('<?php echo esc_js( __( '¿Cancelar esta reserva? Esta acción no se puede deshacer.', 'ltms' ) ); ?>'))return;
        var btn=$(this).prop('disabled',true);
        $.post(ajaxurl,{action:'ltms_admin_booking_action',booking_action:'cancel',booking_id:btn.data('id'),nonce:btn.data('nonce')},function(r){
            r.success?location.reload():alert(r.data);
        });
    });
});
</script>
