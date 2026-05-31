<?php
/**
 * Vista: Admin ReDi - Distribucion por Revendedores
 *
 * @package LTMS
 * @version 2.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;
$agree_table  = $wpdb->prefix . 'lt_redi_agreements';
$commis_table = $wpdb->prefix . 'lt_redi_commissions';

$active_tab = sanitize_key( $_GET['redi_tab'] ?? 'agreements' ); // phpcs:ignore
if ( ! in_array( $active_tab, [ 'agreements', 'commissions' ], true ) ) $active_tab = 'agreements';

$page_num  = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore
$per_page  = 30;
$search    = sanitize_text_field( $_GET['s']      ?? '' ); // phpcs:ignore
$status_f  = sanitize_key(        $_GET['status'] ?? '' ); // phpcs:ignore
$base_url  = admin_url( 'admin.php?page=ltms-redi' );
$nonce     = wp_create_nonce( 'ltms_admin_nonce' );

// ── Verificar tablas ──────────────────────────────────────────────────────
$agree_exists  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $agree_table ) );  // phpcs:ignore
$commis_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $commis_table ) ); // phpcs:ignore

// ── Stats globales ────────────────────────────────────────────────────────
$stats = [ 'total_agreements' => 0, 'active' => 0, 'pending' => 0, 'total_commissions' => 0, 'pending_amount' => 0 ];
if ( $agree_exists ) {
    // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $raw = $wpdb->get_row( "SELECT
        COUNT(*) as total_agreements,
        SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending
        FROM `{$agree_table}`", ARRAY_A );
    if ( $raw ) { $stats['total_agreements'] = $raw['total_agreements']; $stats['active'] = $raw['active']; $stats['pending'] = $raw['pending']; }
}
if ( $commis_exists ) {
    $raw2 = $wpdb->get_row( "SELECT
        COUNT(*) as total_commissions,
        SUM(CASE WHEN status='pending' THEN COALESCE(reseller_commission,0) ELSE 0 END) as pending_amount
        FROM `{$commis_table}`", ARRAY_A );
    if ( $raw2 ) { $stats['total_commissions'] = $raw2['total_commissions']; $stats['pending_amount'] = $raw2['pending_amount']; }
    // phpcs:enable
}

// ── Query con filtros y paginacion ────────────────────────────────────────
$policies   = [];
$total_rows = 0;

if ( $active_tab === 'agreements' && $agree_exists ) {
    $where_parts = []; $where_vals = [];
    if ( $status_f ) { $where_parts[] = 'status = %s'; $where_vals[] = $status_f; }
    if ( $search )   { $where_parts[] = '(origin_vendor_id LIKE %s OR reseller_vendor_id LIKE %s)'; $where_vals[] = '%' . $wpdb->esc_like( $search ) . '%'; $where_vals[] = '%' . $wpdb->esc_like( $search ) . '%'; }
    $where_sql  = $where_parts ? 'WHERE ' . implode( ' AND ', $where_parts ) : '';
    // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $total_rows = (int) $wpdb->get_var( $where_vals ? $wpdb->prepare( "SELECT COUNT(*) FROM `{$agree_table}` {$where_sql}", ...$where_vals ) : "SELECT COUNT(*) FROM `{$agree_table}`" );
    $offset     = ( $page_num - 1 ) * $per_page;
    $policies   = $wpdb->get_results( $where_vals ? $wpdb->prepare( "SELECT * FROM `{$agree_table}` {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d", ...array_merge( $where_vals, [ $per_page, $offset ] ) ) : $wpdb->prepare( "SELECT * FROM `{$agree_table}` ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A );
    // phpcs:enable
} elseif ( $active_tab === 'commissions' && $commis_exists ) {
    $where_parts = []; $where_vals = [];
    if ( $status_f ) { $where_parts[] = 'status = %s'; $where_vals[] = $status_f; }
    if ( $search )   { $where_parts[] = 'order_id LIKE %s'; $where_vals[] = '%' . $wpdb->esc_like( $search ) . '%'; }
    $where_sql  = $where_parts ? 'WHERE ' . implode( ' AND ', $where_parts ) : '';
    // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $total_rows = (int) $wpdb->get_var( $where_vals ? $wpdb->prepare( "SELECT COUNT(*) FROM `{$commis_table}` {$where_sql}", ...$where_vals ) : "SELECT COUNT(*) FROM `{$commis_table}`" );
    $offset     = ( $page_num - 1 ) * $per_page;
    $policies   = $wpdb->get_results( $where_vals ? $wpdb->prepare( "SELECT * FROM `{$commis_table}` {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d", ...array_merge( $where_vals, [ $per_page, $offset ] ) ) : $wpdb->prepare( "SELECT * FROM `{$commis_table}` ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A );
    // phpcs:enable
}

$total_pages = max( 1, (int) ceil( $total_rows / $per_page ) );

$agree_status_labels = [
    'active'  => [ 'label' => 'ACTIVO',    'class' => 'ltms-badge-success' ],
    'paused'  => [ 'label' => 'PAUSADO',   'class' => 'ltms-badge-warning' ],
    'revoked' => [ 'label' => 'REVOCADO',  'class' => 'ltms-badge-danger'  ],
    'pending' => [ 'label' => 'PENDIENTE', 'class' => 'ltms-badge-info'    ],
];
$commis_status_labels = [
    'pending'  => [ 'label' => 'PENDIENTE', 'class' => 'ltms-badge-info'    ],
    'paid'     => [ 'label' => 'PAGADA',    'class' => 'ltms-badge-success' ],
    'reversed' => [ 'label' => 'REVERTIDA', 'class' => 'ltms-badge-danger'  ],
    'held'     => [ 'label' => 'RETENIDA',  'class' => 'ltms-badge-warning' ],
];

function ltms_redi_page_url( string $tab, int $p, string $s, string $st ): string {
    return admin_url( 'admin.php?' . http_build_query( array_filter( [ 'page' => 'ltms-redi', 'redi_tab' => $tab, 'paged' => $p > 1 ? $p : null, 's' => $s, 'status' => $st ] ) ) );
}
?>
<div class="wrap ltms-admin-wrap">

    <div class="ltms-header">
        <h1>&#x1F503; <?php esc_html_e( 'ReDi — Distribucion por Revendedores', 'ltms' ); ?></h1>
    </div>

    <!-- Stats -->
    <div class="ltms-stats-grid" style="margin-bottom:20px;">
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Acuerdos totales', 'ltms' ); ?></span>
            <span class="ltms-stat-value"><?php echo esc_html( number_format( (int) $stats['total_agreements'] ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Acuerdos activos', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="color:#16a34a;"><?php echo esc_html( number_format( (int) $stats['active'] ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Pendientes aprobacion', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="color:#f59e0b;"><?php echo esc_html( number_format( (int) $stats['pending'] ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Comisiones totales', 'ltms' ); ?></span>
            <span class="ltms-stat-value"><?php echo esc_html( number_format( (int) $stats['total_commissions'] ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Comisiones pendientes ($)', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="color:#2563eb;">
                $<?php echo esc_html( number_format( (float) $stats['pending_amount'], 2 ) ); ?>
            </span>
        </div>
    </div>

    <!-- Tabs LTMS -->
    <div style="display:flex;gap:0;border-bottom:2px solid #e5e7eb;margin-bottom:20px;">
        <?php foreach ( [ 'agreements' => 'Acuerdos', 'commissions' => 'Comisiones' ] as $slug => $label ) : ?>
        <a href="<?php echo esc_url( ltms_redi_page_url( $slug, 1, '', '' ) ); ?>"
           style="padding:10px 20px;text-decoration:none;font-weight:600;border-bottom:2px solid <?php echo $active_tab === $slug ? '#2563eb' : 'transparent'; ?>;margin-bottom:-2px;color:<?php echo $active_tab === $slug ? '#2563eb' : '#6b7280'; ?>;">
            <?php echo esc_html( $label ); ?>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Filtros -->
    <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:16px;">
        <input type="hidden" name="page" value="ltms-redi">
        <input type="hidden" name="redi_tab" value="<?php echo esc_attr( $active_tab ); ?>">
        <input type="text" name="s" value="<?php echo esc_attr( $search ); ?>"
               placeholder="<?php esc_attr_e( $active_tab === 'agreements' ? 'Buscar vendedor...' : 'Buscar por pedido...', 'ltms' ); ?>"
               style="padding:7px 12px;border:1px solid #ddd;border-radius:4px;width:180px;">
        <select name="status" style="padding:7px 10px;border:1px solid #ddd;border-radius:4px;">
            <option value=""><?php esc_html_e( 'Todos los estados', 'ltms' ); ?></option>
            <?php
            $opts = $active_tab === 'agreements'
                ? [ 'active' => 'Activo', 'pending' => 'Pendiente', 'paused' => 'Pausado', 'revoked' => 'Revocado' ]
                : [ 'pending' => 'Pendiente', 'paid' => 'Pagada', 'held' => 'Retenida', 'reversed' => 'Revertida' ];
            foreach ( $opts as $v => $l ) :
            ?>
            <option value="<?php echo esc_attr( $v ); ?>" <?php selected( $status_f, $v ); ?>><?php echo esc_html( $l ); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="ltms-btn ltms-btn-primary ltms-btn-sm">&#x1F50D; <?php esc_html_e( 'Filtrar', 'ltms' ); ?></button>
        <?php if ( $search || $status_f ) : ?>
        <a href="<?php echo esc_url( ltms_redi_page_url( $active_tab, 1, '', '' ) ); ?>" class="ltms-btn ltms-btn-outline ltms-btn-sm">&#x2715; <?php esc_html_e( 'Limpiar', 'ltms' ); ?></a>
        <?php endif; ?>
        <span style="font-size:12px;color:#888;margin-left:auto;"><?php printf( esc_html__( '%d registros', 'ltms' ), $total_rows ); ?></span>
    </form>

    <?php
    $current_table_exists = $active_tab === 'agreements' ? $agree_exists : $commis_exists;
    if ( ! $current_table_exists ) :
    ?>
    <div class="notice notice-warning inline" style="margin:16px 0;">
        <p><?php printf( esc_html__( 'La tabla de %s ReDi aun no existe. Ejecuta las migraciones del plugin.', 'ltms' ), $active_tab === 'agreements' ? 'acuerdos' : 'comisiones' ); ?></p>
    </div>
    <?php else : ?>

    <div class="ltms-table-wrap">

        <?php if ( $total_pages > 1 ) : ?>
        <div style="display:flex;justify-content:flex-end;gap:4px;padding:8px 0;flex-wrap:wrap;">
            <?php for ( $p = 1; $p <= $total_pages; $p++ ) : ?>
            <a href="<?php echo esc_url( ltms_redi_page_url( $active_tab, $p, $search, $status_f ) ); ?>"
               class="ltms-btn ltms-btn-sm <?php echo $p === $page_num ? 'ltms-btn-primary' : 'ltms-btn-outline'; ?>"
               style="min-width:30px;text-align:center;"><?php echo esc_html( $p ); ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>

        <?php if ( $active_tab === 'agreements' ) : ?>
        <table class="ltms-table">
            <thead><tr>
                <th><?php esc_html_e( 'ID', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Vendedor Origen', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Revendedor', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Producto', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Tasa ReDi', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Ventas', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Fecha', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Acciones', 'ltms' ); ?></th>
            </tr></thead>
            <tbody>
            <?php if ( empty( $policies ) ) : ?>
            <tr><td colspan="9" style="text-align:center;padding:40px;color:#888;">
                <?php esc_html_e( 'No hay acuerdos ReDi registrados.', 'ltms' ); ?>
            </td></tr>
            <?php else : ?>
            <?php foreach ( $policies as $row ) :
                $origin_id    = (int) ( $row['origin_vendor_id']   ?? 0 );
                $reseller_id  = (int) ( $row['reseller_vendor_id'] ?? 0 );
                $product_id   = (int) ( $row['product_id']         ?? 0 );
                $origin_data  = $origin_id  ? get_userdata( $origin_id )  : false;
                $resell_data  = $reseller_id ? get_userdata( $reseller_id ) : false;
                $status_key   = $row['status'] ?? '';
                $status_info  = $agree_status_labels[ $status_key ] ?? [ 'label' => strtoupper( $status_key ), 'class' => 'ltms-badge-pending' ];
            ?>
            <tr id="ltms-redi-row-<?php echo esc_attr( $row['id'] ); ?>">
                <td style="font-size:12px;color:#888;"><?php echo esc_html( $row['id'] ); ?></td>
                <td><?php echo esc_html( $origin_data ? $origin_data->display_name : $origin_id ?: '—' ); ?></td>
                <td><?php echo esc_html( $resell_data ? $resell_data->display_name : $reseller_id ?: '—' ); ?></td>
                <td style="font-size:12px;">
                    <?php if ( $product_id ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $product_id . '&action=edit' ) ); ?>">
                        <?php echo esc_html( get_the_title( $product_id ) ?: '#' . $product_id ); ?>
                    </a>
                    <?php else : ?>—<?php endif; ?>
                </td>
                <td><strong><?php echo esc_html( number_format( (float) ( $row['redi_rate'] ?? 0 ), 2 ) ); ?>%</strong></td>
                <td>$<?php echo esc_html( number_format( (float) ( $row['total_sales'] ?? 0 ), 2 ) ); ?></td>
                <td><span class="ltms-badge <?php echo esc_attr( $status_info['class'] ); ?>"><?php echo esc_html( $status_info['label'] ); ?></span></td>
                <td style="white-space:nowrap;font-size:12px;"><?php echo esc_html( $row['created_at'] ? gmdate( 'd/m/Y', strtotime( $row['created_at'] ) ) : '—' ); ?></td>
                <td style="display:flex;gap:4px;flex-wrap:wrap;">
                    <?php if ( $status_key === 'active' || $status_key === 'pending' ) : ?>
                    <button class="ltms-btn ltms-btn-danger ltms-btn-sm ltms-redi-revoke"
                            data-id="<?php echo esc_attr( $row['id'] ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
                        &#x274C; <?php esc_html_e( 'Revocar', 'ltms' ); ?>
                    </button>
                    <?php endif; ?>
                    <?php if ( in_array( $status_key, [ 'paused', 'revoked', 'pending' ], true ) ) : ?>
                    <button class="ltms-btn ltms-btn-success ltms-btn-sm ltms-redi-activate"
                            data-id="<?php echo esc_attr( $row['id'] ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
                        &#x2705; <?php esc_html_e( 'Activar', 'ltms' ); ?>
                    </button>
                    <?php endif; ?>
                    <?php if ( $status_key !== 'active' && $status_key !== 'pending' && $status_key !== 'paused' && $status_key !== 'revoked' ) : ?>
                    <span style="color:#ccc;">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <?php else : // commissions tab ?>
        <table class="ltms-table">
            <thead><tr>
                <th><?php esc_html_e( 'ID', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Pedido', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Vendedor Origen', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Revendedor', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Bruto', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Fee', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Comision Rev.', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Neto Origen', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Retencion', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Fecha', 'ltms' ); ?></th>
            </tr></thead>
            <tbody>
            <?php if ( empty( $policies ) ) : ?>
            <tr><td colspan="11" style="text-align:center;padding:40px;color:#888;">
                <?php esc_html_e( 'No hay comisiones ReDi registradas.', 'ltms' ); ?>
            </td></tr>
            <?php else : ?>
            <?php foreach ( $policies as $row ) :
                $order_id    = (int) ( $row['order_id']            ?? 0 );
                $origin_id   = (int) ( $row['origin_vendor_id']    ?? 0 );
                $reseller_id = (int) ( $row['reseller_vendor_id']  ?? 0 );
                $origin_d    = $origin_id   ? get_userdata( $origin_id )   : false;
                $resell_d    = $reseller_id ? get_userdata( $reseller_id ) : false;
                $status_key  = $row['status'] ?? '';
                $status_info = $commis_status_labels[ $status_key ] ?? [ 'label' => strtoupper( $status_key ), 'class' => 'ltms-badge-pending' ];
            ?>
            <tr>
                <td style="font-size:12px;color:#888;"><?php echo esc_html( $row['id'] ); ?></td>
                <td>
                    <?php if ( $order_id ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $order_id . '&action=edit' ) ); ?>" style="font-weight:600;">#<?php echo esc_html( $order_id ); ?></a>
                    <?php else : ?>—<?php endif; ?>
                </td>
                <td><?php echo esc_html( $origin_d ? $origin_d->display_name : $origin_id ?: '—' ); ?></td>
                <td><?php echo esc_html( $resell_d ? $resell_d->display_name : $reseller_id ?: '—' ); ?></td>
                <td>$<?php echo esc_html( number_format( (float) ( $row['gross_amount']         ?? 0 ), 2 ) ); ?></td>
                <td>$<?php echo esc_html( number_format( (float) ( $row['platform_fee']         ?? 0 ), 2 ) ); ?></td>
                <td><strong>$<?php echo esc_html( number_format( (float) ( $row['reseller_commission'] ?? 0 ), 2 ) ); ?></strong></td>
                <td>$<?php echo esc_html( number_format( (float) ( $row['origin_vendor_net']    ?? 0 ), 2 ) ); ?></td>
                <td>$<?php echo esc_html( number_format( (float) ( $row['tax_withholding']      ?? 0 ), 2 ) ); ?></td>
                <td><span class="ltms-badge <?php echo esc_attr( $status_info['class'] ); ?>"><?php echo esc_html( $status_info['label'] ); ?></span></td>
                <td style="white-space:nowrap;font-size:12px;"><?php echo esc_html( $row['created_at'] ? gmdate( 'd/m/Y H:i', strtotime( $row['created_at'] ) ) : '—' ); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php if ( $total_pages > 1 ) : ?>
        <div style="display:flex;justify-content:center;gap:6px;padding:16px;flex-wrap:wrap;">
            <?php for ( $p = 1; $p <= $total_pages; $p++ ) : ?>
            <a href="<?php echo esc_url( ltms_redi_page_url( $active_tab, $p, $search, $status_f ) ); ?>"
               class="ltms-btn ltms-btn-sm <?php echo $p === $page_num ? 'ltms-btn-primary' : 'ltms-btn-outline'; ?>"
               style="min-width:32px;text-align:center;"><?php echo esc_html( $p ); ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>

    </div>
    <?php endif; ?>

</div>

<script type="text/javascript">
/* global jQuery */
(function( $ ) {
    'use strict';
    function ltmsRediAction( action, id, nonce, $btn ) {
        $btn.prop( 'disabled', true );
        var data = { action: action, agreement_id: id, nonce: nonce };
        if ( action === 'ltms_revoke_redi_agreement' ) {
            var reason = prompt( '<?php echo esc_js( __( "Motivo de revocacion (opcional):", "ltms" ) ); ?>' );
            data.reason = reason || '';
        }
        $.post( ajaxurl, data, function( res ) {
            if ( res.success ) { window.location.reload(); }
            else { alert( res.data || '<?php echo esc_js( __( "Error al procesar la solicitud.", "ltms" ) ); ?>' ); $btn.prop( 'disabled', false ); }
        } ).fail( function() {
            alert( '<?php echo esc_js( __( "Error de conexion.", "ltms" ) ); ?>' );
            $btn.prop( 'disabled', false );
        } );
    }
    $( document ).on( 'click', '.ltms-redi-revoke', function( e ) {
        e.preventDefault();
        if ( ! confirm( '<?php echo esc_js( __( "Revocar este acuerdo?", "ltms" ) ); ?>' ) ) return;
        ltmsRediAction( 'ltms_revoke_redi_agreement', $( this ).data( 'id' ), $( this ).data( 'nonce' ), $( this ) );
    } );
    $( document ).on( 'click', '.ltms-redi-activate', function( e ) {
        e.preventDefault();
        if ( ! confirm( '<?php echo esc_js( __( "Activar este acuerdo?", "ltms" ) ); ?>' ) ) return;
        ltmsRediAction( 'ltms_approve_redi_agreement', $( this ).data( 'id' ), $( this ).data( 'nonce' ), $( this ) );
    } );
}( jQuery ) );
</script>
