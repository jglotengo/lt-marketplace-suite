<?php
/**
 * Vista: Admin XCover Policies - Polizas de Seguro
 *
 * @package LTMS
 * @version 2.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;
$table = $wpdb->prefix . 'lt_insurance_policies';

// Filtros
$filter_status = sanitize_key(        $_GET['status']    ?? '' ); // phpcs:ignore
$date_from     = sanitize_text_field( $_GET['date_from'] ?? '' ); // phpcs:ignore
$date_to       = sanitize_text_field( $_GET['date_to']   ?? '' ); // phpcs:ignore
$search        = sanitize_text_field( $_GET['s']         ?? '' ); // phpcs:ignore
$page_num      = max( 1, (int) ( $_GET['paged'] ?? 1 ) );         // phpcs:ignore
$per_page      = 30;
$base_url      = admin_url( 'admin.php?page=ltms-xcover-policies' );

$allowed_statuses = [ '', 'active', 'cancelled', 'claimed', 'expired' ];
if ( ! in_array( $filter_status, $allowed_statuses, true ) ) $filter_status = '';

// Verificar tabla
$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore

$status_labels = [
    'active'    => [ 'label' => 'ACTIVA',     'class' => 'ltms-badge-success' ],
    'cancelled' => [ 'label' => 'CANCELADA',  'class' => 'ltms-badge-danger'  ],
    'claimed'   => [ 'label' => 'RECLAMADA',  'class' => 'ltms-badge-warning' ],
    'expired'   => [ 'label' => 'EXPIRADA',   'class' => 'ltms-badge-pending' ],
];

$policies = [];
$total    = 0;
$stats    = [ 'total' => 0, 'active' => 0, 'claimed' => 0, 'prima_total' => 0 ];

if ( $table_exists ) {
    // WHERE dinámico
    $where_parts = [];
    $where_vals  = [];
    if ( $filter_status ) { $where_parts[] = 'p.status = %s';               $where_vals[] = $filter_status; }
    if ( $date_from )     { $where_parts[] = 'DATE(p.created_at) >= %s';    $where_vals[] = $date_from; }
    if ( $date_to )       { $where_parts[] = 'DATE(p.created_at) <= %s';    $where_vals[] = $date_to; }
    if ( $search )        { $where_parts[] = 'p.xcover_policy_id LIKE %s';  $where_vals[] = '%' . $wpdb->esc_like( $search ) . '%'; }
    $where_sql = $where_parts ? 'WHERE ' . implode( ' AND ', $where_parts ) : '';

    // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $total = (int) $wpdb->get_var(
        $where_vals
            ? $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` p {$where_sql}", ...$where_vals )
            : "SELECT COUNT(*) FROM `{$table}` p"
    );

    $offset   = ( $page_num - 1 ) * $per_page;
    $policies = $wpdb->get_results(
        $where_vals
            ? $wpdb->prepare( "SELECT p.* FROM `{$table}` p {$where_sql} ORDER BY p.created_at DESC LIMIT %d OFFSET %d", ...array_merge( $where_vals, [ $per_page, $offset ] ) )
            : $wpdb->prepare( "SELECT p.* FROM `{$table}` p ORDER BY p.created_at DESC LIMIT %d OFFSET %d", $per_page, $offset ),
        ARRAY_A
    );

    // Stats globales (sin filtros)
    $raw = $wpdb->get_row( "SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN status='claimed' THEN 1 ELSE 0 END) as claimed,
        SUM(COALESCE(premium_amount,0)) as prima_total
        FROM `{$table}`", ARRAY_A );
    if ( $raw ) $stats = $raw;
    // phpcs:enable
}

$total_pages = max( 1, (int) ceil( $total / $per_page ) );
$has_filters = $filter_status || $date_from || $date_to || $search;

// Export CSV
if ( isset( $_GET['export_csv'] ) && $table_exists ) { // phpcs:ignore
    header( 'Content-Type: text/csv; charset=UTF-8' );
    header( 'Content-Disposition: attachment; filename="polizas-xcover-' . gmdate( 'Ymd' ) . '.csv"' );
    echo "\xEF\xBB\xBF"; // BOM UTF-8
    $all = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY created_at DESC", ARRAY_A ); // phpcs:ignore
    if ( $all ) {
        echo implode( ',', array_keys( $all[0] ) ) . "\n";
        foreach ( $all as $row ) {
            echo implode( ',', array_map( function( $v ) { return '"' . str_replace( '"', '""', $v ) . '"'; }, $row ) ) . "\n";
        }
    }
    exit;
}
?>
<div class="wrap ltms-admin-wrap">

    <div class="ltms-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
        <h1>&#x1F6E1;&#xFE0F; <?php esc_html_e( 'Polizas de Seguro XCover', 'ltms' ); ?></h1>
        <?php if ( $table_exists ) : ?>
        <a href="<?php echo esc_url( add_query_arg( 'export_csv', '1', $base_url ) ); ?>"
           class="ltms-btn ltms-btn-outline ltms-btn-sm">
            &#x2B07; <?php esc_html_e( 'Exportar CSV', 'ltms' ); ?>
        </a>
        <?php endif; ?>
    </div>

    <?php if ( ! $table_exists ) : ?>
    <div class="notice notice-warning inline" style="margin:16px 0;">
        <p><?php esc_html_e( 'La tabla de polizas aun no existe. Ejecuta las migraciones del plugin.', 'ltms' ); ?></p>
    </div>
    <?php else : ?>

    <!-- Stats -->
    <div class="ltms-stats-grid" style="margin-bottom:20px;">
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Total polizas', 'ltms' ); ?></span>
            <span class="ltms-stat-value"><?php echo esc_html( number_format( (int) $stats['total'] ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Activas', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="color:#16a34a;"><?php echo esc_html( number_format( (int) $stats['active'] ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Reclamadas', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="color:#f59e0b;"><?php echo esc_html( number_format( (int) $stats['claimed'] ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Prima total cobrada', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="color:#2563eb;">
                $<?php echo esc_html( number_format( (float) $stats['prima_total'], 2 ) ); ?>
            </span>
        </div>
    </div>

    <!-- Filtros -->
    <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:16px;">
        <input type="hidden" name="page" value="ltms-xcover-policies">

        <select name="status" style="padding:7px 10px;border:1px solid #ddd;border-radius:4px;">
            <option value=""><?php esc_html_e( 'Todos los estados', 'ltms' ); ?></option>
            <option value="active"    <?php selected( $filter_status, 'active' ); ?>><?php esc_html_e( 'Activa', 'ltms' ); ?></option>
            <option value="cancelled" <?php selected( $filter_status, 'cancelled' ); ?>><?php esc_html_e( 'Cancelada', 'ltms' ); ?></option>
            <option value="claimed"   <?php selected( $filter_status, 'claimed' ); ?>><?php esc_html_e( 'Reclamada', 'ltms' ); ?></option>
            <option value="expired"   <?php selected( $filter_status, 'expired' ); ?>><?php esc_html_e( 'Expirada', 'ltms' ); ?></option>
        </select>

        <input type="text" name="s" value="<?php echo esc_attr( $search ); ?>"
               placeholder="<?php esc_attr_e( 'N. poliza o pedido...', 'ltms' ); ?>"
               style="padding:7px 12px;border:1px solid #ddd;border-radius:4px;width:180px;">

        <input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>"
               style="padding:7px;border:1px solid #ddd;border-radius:4px;">
        <span style="color:#888;">—</span>
        <input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>"
               style="padding:7px;border:1px solid #ddd;border-radius:4px;">

        <button type="submit" class="ltms-btn ltms-btn-primary ltms-btn-sm">
            &#x1F50D; <?php esc_html_e( 'Filtrar', 'ltms' ); ?>
        </button>
        <?php if ( $has_filters ) : ?>
        <a href="<?php echo esc_url( $base_url ); ?>" class="ltms-btn ltms-btn-outline ltms-btn-sm">
            &#x2715; <?php esc_html_e( 'Limpiar', 'ltms' ); ?>
        </a>
        <?php endif; ?>
        <span style="font-size:12px;color:#888;margin-left:auto;">
            <?php printf( esc_html__( '%d polizas', 'ltms' ), $total ); ?>
        </span>
    </form>

    <div class="ltms-table-wrap">

        <?php if ( $total_pages > 1 ) : ?>
        <div style="display:flex;justify-content:flex-end;gap:4px;padding:8px 0;flex-wrap:wrap;">
            <?php for ( $p = 1; $p <= $total_pages; $p++ ) : ?>
            <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'ltms-xcover-policies', 'paged' => $p, 'status' => $filter_status, 's' => $search, 'date_from' => $date_from, 'date_to' => $date_to ], admin_url( 'admin.php' ) ) ); ?>"
               class="ltms-btn ltms-btn-sm <?php echo $p === $page_num ? 'ltms-btn-primary' : 'ltms-btn-outline'; ?>"
               style="min-width:30px;text-align:center;"><?php echo esc_html( $p ); ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>

        <table class="ltms-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'ID', 'ltms' ); ?></th>
                    <th><?php esc_html_e( '# Pedido', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Vendedor', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'No. Poliza', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Tipo', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Prima', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Certificado', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Fecha', 'ltms' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $policies ) ) : ?>
                <tr>
                    <td colspan="9" style="text-align:center;padding:40px;color:#888;">
                        <?php esc_html_e( 'No se encontraron polizas con los filtros seleccionados.', 'ltms' ); ?>
                    </td>
                </tr>
                <?php else : ?>
                <?php foreach ( $policies as $policy ) :
                    $order_id    = (int) ( $policy['order_id']  ?? 0 );
                    $vendor_id   = (int) ( $policy['vendor_id'] ?? 0 );
                    $vendor_data = $vendor_id ? get_userdata( $vendor_id ) : false;
                    $vendor_name = $vendor_data ? $vendor_data->display_name : '—';
                    $status_key  = $policy['status'] ?? '';
                    $status_info = $status_labels[ $status_key ] ?? [ 'label' => strtoupper( $status_key ), 'class' => 'ltms-badge-pending' ];
                    $prima       = number_format( (float) ( $policy['premium_amount'] ?? 0 ), 2 );
                    $cert_url    = $policy['certificate_url'] ?? '';
                    $policy_num  = $policy['xcover_policy_id'] ?? '—';
                    $policy_type = $policy['policy_type'] ?? '—';
                    $created_at  = $policy['created_at'] ?? '';
                ?>
                <tr>
                    <td style="font-size:12px;color:#888;"><?php echo esc_html( $policy['id'] ?? '' ); ?></td>
                    <td>
                        <?php if ( $order_id ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $order_id . '&action=edit' ) ); ?>" style="font-weight:600;">
                            #<?php echo esc_html( $order_id ); ?>
                        </a>
                        <?php else : ?>—<?php endif; ?>
                    </td>
                    <td><?php echo esc_html( $vendor_name ); ?></td>
                    <td style="font-family:monospace;font-size:11px;"><?php echo esc_html( $policy_num ); ?></td>
                    <td style="font-size:12px;"><?php echo esc_html( $policy_type ); ?></td>
                    <td><strong>$<?php echo esc_html( $prima ); ?></strong></td>
                    <td>
                        <span class="ltms-badge <?php echo esc_attr( $status_info['class'] ); ?>">
                            <?php echo esc_html( $status_info['label'] ); ?>
                        </span>
                    </td>
                    <td>
                        <?php if ( $cert_url ) : ?>
                        <a href="<?php echo esc_url( $cert_url ); ?>" target="_blank" rel="noopener noreferrer"
                           class="ltms-btn ltms-btn-outline ltms-btn-sm">
                            &#x1F4C4; <?php esc_html_e( 'Ver', 'ltms' ); ?>
                        </a>
                        <?php else : ?><span style="color:#ccc;">—</span><?php endif; ?>
                    </td>
                    <td style="white-space:nowrap;font-size:12px;">
                        <?php echo esc_html( $created_at ? gmdate( 'd/m/Y H:i', strtotime( $created_at ) ) : '—' ); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ( $total_pages > 1 ) : ?>
        <div style="display:flex;justify-content:center;gap:6px;padding:16px;flex-wrap:wrap;">
            <?php for ( $p = 1; $p <= $total_pages; $p++ ) : ?>
            <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'ltms-xcover-policies', 'paged' => $p, 'status' => $filter_status, 's' => $search, 'date_from' => $date_from, 'date_to' => $date_to ], admin_url( 'admin.php' ) ) ); ?>"
               class="ltms-btn ltms-btn-sm <?php echo $p === $page_num ? 'ltms-btn-primary' : 'ltms-btn-outline'; ?>"
               style="min-width:32px;text-align:center;"><?php echo esc_html( $p ); ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>

    </div>

    <?php endif; ?>

</div>
