<?php
/**
 * Vista admin: Lista de reservas
 *
 * @package LTMS
 * @version 2.0.0
 *
 * @var array  $bookings
 * @var string $status
 * @var string $date_from
 * @var string $date_to
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;
$table = $wpdb->prefix . 'lt_bookings';

$statuses = [
    ''            => __( 'Todos',      'ltms' ),
    'pending'     => __( 'Pendiente',  'ltms' ),
    'confirmed'   => __( 'Confirmada', 'ltms' ),
    'checked_in'  => __( 'Check-in',  'ltms' ),
    'checked_out' => __( 'Check-out', 'ltms' ),
    'cancelled'   => __( 'Cancelada', 'ltms' ),
    'completed'   => __( 'Completada','ltms' ),
];

$badge_classes = [
    'pending'     => 'ltms-badge-warning',
    'confirmed'   => 'ltms-badge-success',
    'checked_in'  => 'ltms-badge-info',
    'checked_out' => 'ltms-badge-secondary',
    'cancelled'   => 'ltms-badge-danger',
    'completed'   => 'ltms-badge-success',
];

// ── Stats globales ────────────────────────────────────────────────────────
$stats = [ 'total' => 0, 'confirmed' => 0, 'pending' => 0, 'cancelled' => 0, 'revenue' => 0 ];
$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore
if ( $table_exists ) {
    // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $raw = $wpdb->get_row( "SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status IN ('confirmed','checked_in','checked_out','completed') THEN 1 ELSE 0 END) as confirmed,
        SUM(CASE WHEN status='pending'   THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END) as cancelled,
        SUM(CASE WHEN status NOT IN ('cancelled') THEN COALESCE(total_price,0) ELSE 0 END) as revenue
        FROM `{$table}`", ARRAY_A );
    // phpcs:enable
    if ( $raw ) {
        $stats['total']     = (int)   $raw['total'];
        $stats['confirmed'] = (int)   $raw['confirmed'];
        $stats['pending']   = (int)   $raw['pending'];
        $stats['cancelled'] = (int)   $raw['cancelled'];
        $stats['revenue']   = (float) $raw['revenue'];
    }
}

$current_status = $status    ?? '';
$df             = $date_from ?? '';
$dt             = $date_to   ?? '';
$active_filters = $current_status || $df || $dt;
?>
<div class="wrap ltms-admin-wrap">

    <div class="ltms-header">
        <h1>&#x1F4C5; <?php esc_html_e( 'Reservas', 'ltms' ); ?></h1>
        <a class="ltms-btn ltms-btn-outline ltms-btn-sm"
           href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ltms_export_bookings_csv' ), 'ltms_export_bookings_csv' ) ); ?>">
            &#x2B07; <?php esc_html_e( 'Exportar CSV', 'ltms' ); ?>
        </a>
    </div>

    <!-- Stats -->
    <div class="ltms-stats-grid" style="margin-bottom:20px;">
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Total reservas', 'ltms' ); ?></span>
            <span class="ltms-stat-value"><?php echo esc_html( number_format( $stats['total'] ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Confirmadas / Activas', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="color:#16a34a;"><?php echo esc_html( number_format( $stats['confirmed'] ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Pendientes', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="color:#f59e0b;"><?php echo esc_html( number_format( $stats['pending'] ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Canceladas', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="color:#dc2626;"><?php echo esc_html( number_format( $stats['cancelled'] ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Ingresos totales', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="color:#2563eb;">
                $<?php echo esc_html( number_format( $stats['revenue'], 0, ',', '.' ) ); ?>
            </span>
        </div>
    </div>

    <!-- Filtros -->
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:16px;">
        <input type="hidden" name="page" value="ltms-bookings">
        <select name="status" style="padding:7px 10px;border:1px solid #ddd;border-radius:4px;">
            <?php foreach ( $statuses as $val => $label ) : ?>
                <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $current_status, $val ); ?>>
                    <?php echo esc_html( $label ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="date_from" value="<?php echo esc_attr( $df ); ?>"
               style="padding:7px 10px;border:1px solid #ddd;border-radius:4px;">
        <input type="date" name="date_to" value="<?php echo esc_attr( $dt ); ?>"
               style="padding:7px 10px;border:1px solid #ddd;border-radius:4px;">
        <button type="submit" class="ltms-btn ltms-btn-primary ltms-btn-sm">
            &#x1F50D; <?php esc_html_e( 'Filtrar', 'ltms' ); ?>
        </button>
        <?php if ( $active_filters ) : ?>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ltms-bookings' ) ); ?>"
           class="ltms-btn ltms-btn-outline ltms-btn-sm">
            &#x2715; <?php esc_html_e( 'Limpiar', 'ltms' ); ?>
        </a>
        <?php endif; ?>
    </form>

    <!-- Tabla -->
    <div class="ltms-table-wrap">
        <table class="ltms-table">
            <thead><tr>
                <th><?php esc_html_e( '#ID', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Producto', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Cliente', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Check-in', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Check-out', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Noches', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Total', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Acciones', 'ltms' ); ?></th>
            </tr></thead>
            <tbody>
            <?php if ( empty( $bookings ) ) : ?>
                <tr><td colspan="9" style="text-align:center;padding:40px;color:#888;">
                    <div style="font-size:32px;margin-bottom:8px;">&#x1F4C5;</div>
                    <?php esc_html_e( 'Sin reservas con los filtros actuales.', 'ltms' ); ?>
                </td></tr>
            <?php else : ?>
                <?php foreach ( $bookings as $b ) :
                    $nights   = max( 0, (int) round( ( strtotime( $b['checkout_date'] ) - strtotime( $b['checkin_date'] ) ) / DAY_IN_SECONDS ) );
                    $customer = get_user_by( 'id', (int) $b['customer_id'] );
                    $st_key   = $b['status'] ?? '';
                    $badge    = $badge_classes[ $st_key ] ?? 'ltms-badge-secondary';
                    $st_label = $statuses[ $st_key ] ?? strtoupper( $st_key );
                ?>
                <tr>
                    <td>
                        <strong style="font-size:13px;"><?php echo esc_html( $b['id'] ); ?></strong>
                        <?php if ( ! empty( $b['wc_order_id'] ) ) : ?>
                        <br><a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $b['wc_order_id'] . '&action=edit' ) ); ?>"
                               style="font-size:11px;color:#888;">WC#<?php echo esc_html( $b['wc_order_id'] ); ?></a>
                        <?php endif; ?>
                    </td>
                    <td style="max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        <?php echo esc_html( $b['product_name'] ?? '—' ); ?>
                    </td>
                    <td><?php echo $customer ? esc_html( $customer->display_name ) : '<span style="color:#888;">#' . (int) $b['customer_id'] . '</span>'; ?></td>
                    <td style="white-space:nowrap;"><?php echo esc_html( $b['checkin_date']  ?? '—' ); ?></td>
                    <td style="white-space:nowrap;"><?php echo esc_html( $b['checkout_date'] ?? '—' ); ?></td>
                    <td style="text-align:center;"><strong><?php echo esc_html( $nights ); ?></strong></td>
                    <td style="white-space:nowrap;">
                        <strong>$<?php echo esc_html( number_format( (float) ( $b['total_price'] ?? 0 ), 0, ',', '.' ) ); ?></strong>
                        <span style="font-size:11px;color:#888;"><?php echo esc_html( $b['currency'] ?? '' ); ?></span>
                    </td>
                    <td>
                        <span class="ltms-badge <?php echo esc_attr( $badge ); ?>">
                            <?php echo esc_html( $st_label ); ?>
                        </span>
                    </td>
                    <td>
                        <?php if ( ! in_array( $st_key, [ 'cancelled', 'checked_out', 'completed' ], true ) ) : ?>
                        <button class="ltms-btn ltms-btn-danger ltms-btn-sm ltms-cancel-booking"
                                data-id="<?php echo esc_attr( $b['id'] ); ?>"
                                data-nonce="<?php echo esc_attr( wp_create_nonce( 'ltms_admin_booking' ) ); ?>">
                            &#x274C; <?php esc_html_e( 'Cancelar', 'ltms' ); ?>
                        </button>
                        <?php else : ?>
                        <span style="color:#ccc;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

<script type="text/javascript">
/* global jQuery */
jQuery( function( $ ) {
    $( '.ltms-cancel-booking' ).on( 'click', function() {
        if ( ! window.confirm( '<?php echo esc_js( __( "¿Cancelar esta reserva? Esta acción no se puede deshacer.", "ltms" ) ); ?>' ) ) return;
        var $btn = $( this ).prop( 'disabled', true );
        $.post( ajaxurl, {
            action:         'ltms_admin_booking_action',
            booking_action: 'cancel',
            booking_id:     $btn.data( 'id' ),
            nonce:          $btn.data( 'nonce' )
        }, function( r ) {
            r.success ? location.reload() : console.warn( r.data );
        } );
    } );
} );
</script>
