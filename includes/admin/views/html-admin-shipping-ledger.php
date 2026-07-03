<?php
/**
 * Vista HTML — Panel Logística / Costos.
 *
 * @package LTMS
 * @version 2.8.3
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$tab = $tab ?? 'dashboard';

// Determinar período para dashboard.
$period = sanitize_key( $_GET['period'] ?? 'month' );
$valid_periods = [ 'today', 'month', 'last_30d', 'ytd', 'all' ];
if ( ! in_array( $period, $valid_periods, true ) ) {
    $period = 'month';
}

// Mensajes de éxito/error.
$imported        = isset( $_GET['imported'] ) ? (int) $_GET['imported'] : 0;
$resolved        = isset( $_GET['resolved'] ) ? (int) $_GET['resolved'] : 0;
$opened          = isset( $_GET['opened'] ) ? (int) $_GET['opened'] : 0;
$saved           = isset( $_GET['saved'] ) ? (int) $_GET['saved'] : 0;
$error_code      = isset( $_GET['error'] ) ? sanitize_key( $_GET['error'] ) : '';
?>
<div class="wrap ltms-admin-wrap">
    <div class="ltms-header">
        <h1>
            <?php esc_html_e( 'Logística / Costos — Conciliación y Control', 'ltms' ); ?>
        </h1>
        <p class="description">
            <?php esc_html_e( 'Trazabilidad financiera de cada servicio logístico: cotización, costo real, varianza, disputas y presupuestos por vendedor.', 'ltms' ); ?>
        </p>
    </div>

    <?php if ( $imported ): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e( 'Factura importada. Revisa las líneas matcheadas y disputas automáticas abajo.', 'ltms' ); ?></p>
        </div>
    <?php endif; ?>
    <?php if ( $resolved ): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo esc_html( sprintf( __( 'Disputa #%d resuelta.', 'ltms' ), $resolved ) ); ?></p>
        </div>
    <?php endif; ?>
    <?php if ( $opened ): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo esc_html( sprintf( __( 'Disputa #%d abierta.', 'ltms' ), $opened ) ); ?></p>
        </div>
    <?php endif; ?>
    <?php if ( $saved ): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e( 'Presupuesto guardado.', 'ltms' ); ?></p>
        </div>
    <?php endif; ?>
    <?php if ( $error_code ): ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo esc_html( sprintf( __( 'Error: %s', 'ltms' ), $error_code ) ); ?></p>
        </div>
    <?php endif; ?>

    <!-- Navegación por pestañas -->
    <nav class="nav-tab-wrapper" style="margin:20px 0;">
        <a href="<?php echo esc_url( LTMS_Admin_Shipping_Ledger::tab_url( 'dashboard' ) ); ?>"
           class="nav-tab <?php echo $tab === 'dashboard' ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e( 'Dashboard', 'ltms' ); ?>
        </a>
        <a href="<?php echo esc_url( LTMS_Admin_Shipping_Ledger::tab_url( 'ledger' ) ); ?>"
           class="nav-tab <?php echo $tab === 'ledger' ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e( 'Ledger', 'ltms' ); ?>
        </a>
        <a href="<?php echo esc_url( LTMS_Admin_Shipping_Ledger::tab_url( 'invoices' ) ); ?>"
           class="nav-tab <?php echo $tab === 'invoices' ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e( 'Facturas Carrier', 'ltms' ); ?>
        </a>
        <a href="<?php echo esc_url( LTMS_Admin_Shipping_Ledger::tab_url( 'disputes' ) ); ?>"
           class="nav-tab <?php echo $tab === 'disputes' ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e( 'Disputas', 'ltms' ); ?>
        </a>
        <a href="<?php echo esc_url( LTMS_Admin_Shipping_Ledger::tab_url( 'budgets' ) ); ?>"
           class="nav-tab <?php echo $tab === 'budgets' ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e( 'Presupuestos', 'ltms' ); ?>
        </a>
    </nav>

    <?php
    switch ( $tab ) {
        case 'dashboard':
            ltms_render_dashboard_tab( $period );
            break;
        case 'ledger':
            ltms_render_ledger_tab();
            break;
        case 'invoices':
            ltms_render_invoices_tab();
            break;
        case 'disputes':
            ltms_render_disputes_tab();
            break;
        case 'budgets':
            ltms_render_budgets_tab();
            break;
    }
    ?>
</div>

<?php
// =====================================================================
// RENDERIZADO DE PESTAÑAS (métodos estáticos auxiliares en la misma vista)
// =====================================================================

/**
 * Pestaña Dashboard: KPIs + tablas agregadas.
 */
function ltms_render_dashboard_tab( string $period ): void {
    $kpis = LTMS_Admin_Shipping_Ledger::get_dashboard_data( $period );

    $period_labels = [
        'today'    => __( 'Hoy', 'ltms' ),
        'month'    => __( 'Este mes', 'ltms' ),
        'last_30d' => __( 'Últimos 30 días', 'ltms' ),
        'ytd'      => __( 'Año a la fecha', 'ltms' ),
        'all'      => __( 'Histórico', 'ltms' ),
    ];
    ?>
    <div style="margin-bottom:16px;">
        <form method="get" style="display:inline-block;">
            <input type="hidden" name="page" value="ltms-shipping-ledger" />
            <input type="hidden" name="tab" value="dashboard" />
            <select name="period" onchange="this.form.submit()">
                <?php foreach ( $period_labels as $key => $label ) : ?>
                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $period, $key ); ?>>
                        <?php echo esc_html( $label ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <!-- KPIs cards -->
    <div class="ltms-stats-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;margin-bottom:24px;">
        <div class="ltms-stat-card" style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:16px;">
            <span class="ltms-stat-label" style="display:block;font-size:12px;color:#6b7280;margin-bottom:4px;"><?php esc_html_e( 'Total Envíos', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="font-size:22px;font-weight:700;color:#1f2937;"><?php echo esc_html( number_format( $kpis['total_entries'], 0 ) ); ?></span>
        </div>
        <div class="ltms-stat-card" style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:16px;">
            <span class="ltms-stat-label" style="display:block;font-size:12px;color:#6b7280;margin-bottom:4px;"><?php esc_html_e( 'Cobrado al Cliente', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="font-size:18px;font-weight:700;color:#2563eb;"><?php echo esc_html( LTMS_Admin_Shipping_Ledger::format_currency( $kpis['total_buyer_paid'] ) ); ?></span>
        </div>
        <div class="ltms-stat-card" style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:16px;">
            <span class="ltms-stat-label" style="display:block;font-size:12px;color:#6b7280;margin-bottom:4px;"><?php esc_html_e( 'Debitado a Vendors', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="font-size:18px;font-weight:700;color:#7c3aed;"><?php echo esc_html( LTMS_Admin_Shipping_Ledger::format_currency( $kpis['total_vendor_charged'] ) ); ?></span>
        </div>
        <div class="ltms-stat-card" style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:16px;">
            <span class="ltms-stat-label" style="display:block;font-size:12px;color:#6b7280;margin-bottom:4px;"><?php esc_html_e( 'Costo Real Carrier', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="font-size:18px;font-weight:700;color:#dc2626;"><?php echo esc_html( LTMS_Admin_Shipping_Ledger::format_currency( $kpis['total_real_cost'] ) ); ?></span>
        </div>
        <div class="ltms-stat-card" style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:16px;">
            <span class="ltms-stat-label" style="display:block;font-size:12px;color:#6b7280;margin-bottom:4px;"><?php esc_html_e( 'Varianza Total', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="font-size:18px;font-weight:700;color:<?php echo $kpis['total_variance'] > 0 ? '#dc2626' : '#16a34a'; ?>;">
                <?php echo esc_html( LTMS_Admin_Shipping_Ledger::format_currency( $kpis['total_variance'] ) ); ?>
            </span>
            <span style="display:block;font-size:10px;color:#6b7280;margin-top:4px;"><?php esc_html_e( '(+ = carrier cobró de más)', 'ltms' ); ?></span>
        </div>
        <div class="ltms-stat-card" style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:16px;">
            <span class="ltms-stat-label" style="display:block;font-size:12px;color:#6b7280;margin-bottom:4px;"><?php esc_html_e( 'P&L Neto', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="font-size:18px;font-weight:700;color:<?php echo $kpis['net_pnl'] >= 0 ? '#16a34a' : '#dc2626'; ?>;">
                <?php echo esc_html( LTMS_Admin_Shipping_Ledger::format_currency( $kpis['net_pnl'] ) ); ?>
            </span>
            <span style="display:block;font-size:10px;color:#6b7280;margin-top:4px;"><?php esc_html_e( '(buyer + vendor - real)', 'ltms' ); ?></span>
        </div>
    </div>

    <!-- KPIs secundarios: disputas, conciliados -->
    <div class="ltms-stats-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;margin-bottom:32px;">
        <div class="ltms-stat-card" style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:12px;">
            <span style="display:block;font-size:11px;color:#92400e;margin-bottom:4px;"><?php esc_html_e( 'Disputas Abiertas', 'ltms' ); ?></span>
            <span style="font-size:18px;font-weight:700;color:#92400e;"><?php echo esc_html( number_format( $kpis['open_disputes'], 0 ) ); ?></span>
        </div>
        <div class="ltms-stat-card" style="background:#dcfce7;border:1px solid #86efac;border-radius:8px;padding:12px;">
            <span style="display:block;font-size:11px;color:#166534;margin-bottom:4px;"><?php esc_html_e( 'Conciliados', 'ltms' ); ?></span>
            <span style="font-size:18px;font-weight:700;color:#166534;"><?php echo esc_html( number_format( $kpis['reconciled'], 0 ) ); ?></span>
        </div>
        <div class="ltms-stat-card" style="background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:12px;">
            <span style="display:block;font-size:11px;color:#991b1b;margin-bottom:4px;"><?php esc_html_e( 'Pérdidas (writeoff)', 'ltms' ); ?></span>
            <span style="font-size:18px;font-weight:700;color:#991b1b;"><?php echo esc_html( number_format( $kpis['writeoffs'], 0 ) ); ?></span>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">
        <!-- Por Carrier -->
        <div>
            <h3 style="margin-top:0;"><?php esc_html_e( 'Por Carrier', 'ltms' ); ?></h3>
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Carrier', 'ltms' ); ?></th>
                        <th style="text-align:right;"><?php esc_html_e( 'Envíos', 'ltms' ); ?></th>
                        <th style="text-align:right;"><?php esc_html_e( 'Cotizado', 'ltms' ); ?></th>
                        <th style="text-align:right;"><?php esc_html_e( 'Real', 'ltms' ); ?></th>
                        <th style="text-align:right;"><?php esc_html_e( 'Varianza $', 'ltms' ); ?></th>
                        <th style="text-align:right;"><?php esc_html_e( 'Varianza %', 'ltms' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $kpis['by_carrier'] ) ) : ?>
                        <tr><td colspan="6"><?php esc_html_e( 'Sin datos en el período seleccionado.', 'ltms' ); ?></td></tr>
                    <?php else : foreach ( $kpis['by_carrier'] as $row ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( strtoupper( $row['carrier'] ) ); ?></strong></td>
                            <td style="text-align:right;"><?php echo esc_html( number_format( $row['cnt'] ) ); ?></td>
                            <td style="text-align:right;"><?php echo esc_html( LTMS_Admin_Shipping_Ledger::format_currency( (float) $row['quote'] ) ); ?></td>
                            <td style="text-align:right;"><?php echo esc_html( LTMS_Admin_Shipping_Ledger::format_currency( (float) $row['real'] ) ); ?></td>
                            <td style="text-align:right;color:<?php echo $row['variance'] > 0 ? '#dc2626' : '#16a34a'; ?>;">
                                <?php echo esc_html( LTMS_Admin_Shipping_Ledger::format_currency( (float) $row['variance'] ) ); ?>
                            </td>
                            <td style="text-align:right;color:<?php echo $row['avg_var_pct'] > 5 ? '#dc2626' : '#6b7280'; ?>;">
                                <?php echo esc_html( number_format( (float) $row['avg_var_pct'], 2 ) ); ?>%
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Top Vendors -->
        <div>
            <h3 style="margin-top:0;"><?php esc_html_e( 'Top 10 Vendors por Gasto', 'ltms' ); ?></h3>
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Vendor', 'ltms' ); ?></th>
                        <th style="text-align:right;"><?php esc_html_e( 'Envíos', 'ltms' ); ?></th>
                        <th style="text-align:right;"><?php esc_html_e( 'Debitado', 'ltms' ); ?></th>
                        <th style="text-align:right;"><?php esc_html_e( 'Cotizado', 'ltms' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $kpis['top_vendors'] ) ) : ?>
                        <tr><td colspan="4"><?php esc_html_e( 'Sin datos.', 'ltms' ); ?></td></tr>
                    <?php else : foreach ( $kpis['top_vendors'] as $row ) : ?>
                        <tr>
                            <td><?php echo esc_html( LTMS_Admin_Shipping_Ledger::get_vendor_display_name( (int) $row['vendor_id'] ) ); ?></td>
                            <td style="text-align:right;"><?php echo esc_html( number_format( $row['cnt'] ) ); ?></td>
                            <td style="text-align:right;"><?php echo esc_html( LTMS_Admin_Shipping_Ledger::format_currency( (float) $row['charged'] ) ); ?></td>
                            <td style="text-align:right;"><?php echo esc_html( LTMS_Admin_Shipping_Ledger::format_currency( (float) $row['quote'] ) ); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

/**
 * Pestaña Ledger: tabla filtrable de entries.
 */
function ltms_render_ledger_tab(): void {
    $filters = [
        'carrier'   => sanitize_key( $_GET['f_carrier'] ?? '' ),
        'vendor_id' => (int) ( $_GET['f_vendor'] ?? 0 ),
        'status'    => sanitize_key( $_GET['f_status'] ?? '' ),
        'date_from' => sanitize_text_field( $_GET['f_date_from'] ?? '' ),
        'date_to'   => sanitize_text_field( $_GET['f_date_to'] ?? '' ),
        'per_page'  => 50,
        'page'      => max( 1, (int) ( $_GET['paged'] ?? 1 ) ),
    ];

    $entries = LTMS_Admin_Shipping_Ledger::get_ledger_data( $filters );

    $carriers = [ 'deprisa', 'heka', 'aveonline', 'uber', 'pickup', 'own_delivery', 'free_absorbed' ];
    $statuses = [ 'quoted', 'shipped', 'delivered', 'invoiced', 'disputed', 'reconciled', 'writeoff' ];
    ?>
    <form method="get" style="background:#fff;padding:12px;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:16px;">
        <input type="hidden" name="page" value="ltms-shipping-ledger" />
        <input type="hidden" name="tab" value="ledger" />

        <select name="f_carrier">
            <option value=""><?php esc_html_e( 'Todos los carriers', 'ltms' ); ?></option>
            <?php foreach ( $carriers as $c ) : ?>
                <option value="<?php echo esc_attr( $c ); ?>" <?php selected( $filters['carrier'], $c ); ?>><?php echo esc_html( strtoupper( $c ) ); ?></option>
            <?php endforeach; ?>
        </select>

        <input type="number" name="f_vendor" placeholder="<?php esc_attr_e( 'Vendor ID', 'ltms' ); ?>"
               value="<?php echo esc_attr( $filters['vendor_id'] ?: '' ); ?>" style="width:100px;" />

        <select name="f_status">
            <option value=""><?php esc_html_e( 'Todos los estados', 'ltms' ); ?></option>
            <?php foreach ( $statuses as $s ) : ?>
                <option value="<?php echo esc_attr( $s ); ?>" <?php selected( $filters['status'], $s ); ?>><?php echo esc_html( ucfirst( $s ) ); ?></option>
            <?php endforeach; ?>
        </select>

        <input type="date" name="f_date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>" />
        <input type="date" name="f_date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>" />

        <button type="submit" class="button button-primary"><?php esc_html_e( 'Filtrar', 'ltms' ); ?></button>
        <a href="<?php echo esc_url( LTMS_Admin_Shipping_Ledger::tab_url( 'ledger' ) ); ?>" class="button"><?php esc_html_e( 'Limpiar', 'ltms' ); ?></a>
    </form>

    <table class="wp-list-table widefat striped">
        <thead>
            <tr>
                <th>ID</th>
                <th><?php esc_html_e( 'Pedido', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Vendor', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Carrier', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Tracking', 'ltms' ); ?></th>
                <th style="text-align:right;"><?php esc_html_e( 'Cotizado', 'ltms' ); ?></th>
                <th style="text-align:right;"><?php esc_html_e( 'Buyer Paid', 'ltms' ); ?></th>
                <th style="text-align:right;"><?php esc_html_e( 'Vendor Charged', 'ltms' ); ?></th>
                <th style="text-align:right;"><?php esc_html_e( 'Real', 'ltms' ); ?></th>
                <th style="text-align:right;"><?php esc_html_e( 'Varianza', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Acciones', 'ltms' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $entries ) ) : ?>
                <tr><td colspan="12"><?php esc_html_e( 'No hay entries que coincidan con los filtros.', 'ltms' ); ?></td></tr>
            <?php else : foreach ( $entries as $e ) :
                $variance = (float) ( $e['variance'] ?? 0 );
                $should_show_dispute_btn = ( $e['status'] === 'invoiced' || $e['status'] === 'disputed' ) && $variance > 0;
                ?>
                <tr>
                    <td><?php echo esc_html( $e['id'] ); ?></td>
                    <td><a href="<?php echo esc_url( admin_url( 'post.php?post=' . $e['order_id'] . '&action=edit' ) ); ?>">#<?php echo esc_html( $e['order_id'] ); ?></a></td>
                    <td><?php echo esc_html( LTMS_Admin_Shipping_Ledger::get_vendor_display_name( (int) $e['vendor_id'] ) ); ?></td>
                    <td><?php echo esc_html( strtoupper( $e['carrier'] ) ); ?></td>
                    <td style="font-family:monospace;font-size:11px;"><?php echo esc_html( $e['tracking_number'] ?? '-' ); ?></td>
                    <td style="text-align:right;"><?php echo esc_html( LTMS_Admin_Shipping_Ledger::format_currency( (float) $e['quote_cost'] ) ); ?></td>
                    <td style="text-align:right;"><?php echo esc_html( LTMS_Admin_Shipping_Ledger::format_currency( (float) $e['buyer_paid'] ) ); ?></td>
                    <td style="text-align:right;"><?php echo esc_html( LTMS_Admin_Shipping_Ledger::format_currency( (float) $e['vendor_charged'] ) ); ?></td>
                    <td style="text-align:right;">
                        <?php if ( $e['real_cost'] !== null ) : ?>
                            <?php echo esc_html( LTMS_Admin_Shipping_Ledger::format_currency( (float) $e['real_cost'] ) ); ?>
                        <?php else : ?>
                            <span style="color:#9ca3af;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;color:<?php echo $variance > 0 ? '#dc2626' : ( $variance < 0 ? '#16a34a' : '#6b7280' ); ?>;">
                        <?php if ( $e['variance'] !== null ) : ?>
                            <?php echo esc_html( LTMS_Admin_Shipping_Ledger::format_currency( $variance ) ); ?>
                            <div style="font-size:10px;color:#6b7280;"><?php echo esc_html( number_format( (float) $e['variance_pct'], 1 ) ); ?>%</div>
                        <?php else : ?>
                            <span style="color:#9ca3af;">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo LTMS_Admin_Shipping_Ledger::status_badge( $e['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                    <td>
                        <?php if ( $should_show_dispute_btn && current_user_can( 'ltms_manage_platform_settings' ) ) : ?>
                            <a href="<?php echo esc_url( wp_nonce_url(
                                add_query_arg( [
                                    'page'           => 'ltms-shipping-ledger',
                                    'tab'            => 'disputes',
                                    'action'         => 'open_dispute',
                                    'ledger_id'      => $e['id'],
                                    'expected'       => $e['quote_cost'],
                                    'disputed'       => $variance,
                                ], admin_url( 'admin.php' ) ),
                                'ltms_ledger_open_dispute'
                            ) ); ?>" class="button button-small" style="background:#f97316;color:#fff;border-color:#f97316;">
                                <?php esc_html_e( 'Disputa', 'ltms' ); ?>
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
    <?php
}

/**
 * Pestaña Facturas: lista + form import CSV.
 */
function ltms_render_invoices_tab(): void {
    $invoices = LTMS_Admin_Shipping_Ledger::get_invoices( 100 );
    $carriers = [ 'deprisa', 'heka', 'aveonline', 'uber' ];
    ?>
    <?php if ( current_user_can( 'ltms_manage_platform_settings' ) ) : ?>
    <div style="background:#fff;padding:16px;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:24px;">
        <h3 style="margin-top:0;"><?php esc_html_e( 'Importar Factura de Carrier (CSV)', 'ltms' ); ?></h3>
        <p class="description">
            <?php esc_html_e( 'El CSV debe tener cabeceras: tracking_number, guide_number, order_ref, origin_city, destination_city, weight_kg, billed_amount, tax_amount, total_amount, currency.', 'ltms' ); ?>
        </p>
        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'ltms_import_carrier_invoice' ); ?>
            <input type="hidden" name="action" value="ltms_import_carrier_invoice" />
            <select name="carrier" required>
                <option value=""><?php esc_html_e( 'Selecciona carrier', 'ltms' ); ?></option>
                <?php foreach ( $carriers as $c ) : ?>
                    <option value="<?php echo esc_attr( $c ); ?>"><?php echo esc_html( strtoupper( $c ) ); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="file" name="invoice_csv" accept=".csv,text/csv" required />
            <button type="submit" class="button button-primary"><?php esc_html_e( 'Importar y conciliar', 'ltms' ); ?></button>
        </form>
    </div>
    <?php endif; ?>

    <h3><?php esc_html_e( 'Facturas Importadas', 'ltms' ); ?></h3>
    <table class="wp-list-table widefat striped">
        <thead>
            <tr>
                <th>ID</th>
                <th><?php esc_html_e( 'Carrier', 'ltms' ); ?></th>
                <th><?php esc_html_e( '# Factura', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Período', 'ltms' ); ?></th>
                <th style="text-align:right;"><?php esc_html_e( 'Total', 'ltms' ); ?></th>
                <th style="text-align:right;"><?php esc_html_e( 'Líneas', 'ltms' ); ?></th>
                <th style="text-align:right;"><?php esc_html_e( 'Matched', 'ltms' ); ?></th>
                <th style="text-align:right;"><?php esc_html_e( 'Unmatched', 'ltms' ); ?></th>
                <th style="text-align:right;"><?php esc_html_e( 'Varianza', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Importado', 'ltms' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $invoices ) ) : ?>
                <tr><td colspan="11"><?php esc_html_e( 'Aún no se han importado facturas.', 'ltms' ); ?></td></tr>
            <?php else : foreach ( $invoices as $inv ) : ?>
                <tr>
                    <td><?php echo esc_html( $inv['id'] ); ?></td>
                    <td><?php echo esc_html( strtoupper( $inv['carrier'] ) ); ?></td>
                    <td><?php echo esc_html( $inv['invoice_number'] ); ?></td>
                    <td><?php echo esc_html( $inv['period_start'] . ' → ' . $inv['period_end'] ); ?></td>
                    <td style="text-align:right;"><?php echo esc_html( LTMS_Admin_Shipping_Ledger::format_currency( (float) $inv['total_amount'] ) ); ?></td>
                    <td style="text-align:right;"><?php echo esc_html( $inv['lines_count'] ); ?></td>
                    <td style="text-align:right;color:#16a34a;font-weight:600;"><?php echo esc_html( $inv['lines_matched'] ); ?></td>
                    <td style="text-align:right;color:<?php echo $inv['lines_unmatched'] > 0 ? '#dc2626' : '#6b7280'; ?>;font-weight:600;"><?php echo esc_html( $inv['lines_unmatched'] ); ?></td>
                    <td style="text-align:right;color:<?php echo $inv['variance_total'] > 0 ? '#dc2626' : '#16a34a'; ?>;">
                        <?php echo esc_html( LTMS_Admin_Shipping_Ledger::format_currency( (float) $inv['variance_total'] ) ); ?>
                    </td>
                    <td><strong><?php echo esc_html( $inv['status'] ); ?></strong></td>
                    <td><?php echo esc_html( wp_date( 'Y-m-d H:i', strtotime( $inv['imported_at'] ) ) ); ?></td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
    <?php
}

/**
 * Pestaña Disputas: lista + form resolución.
 */
function ltms_render_disputes_tab(): void {
    $status_filter = sanitize_key( $_GET['f_status'] ?? '' );
    $disputes = LTMS_Admin_Shipping_Ledger::get_disputes( $status_filter );
    $now = time();
    ?>
    <form method="get" style="margin-bottom:16px;">
        <input type="hidden" name="page" value="ltms-shipping-ledger" />
        <input type="hidden" name="tab" value="disputes" />
        <select name="f_status" onchange="this.form.submit()">
            <option value=""><?php esc_html_e( 'Todas', 'ltms' ); ?></option>
            <option value="open" <?php selected( $status_filter, 'open' ); ?>><?php esc_html_e( 'Abiertas', 'ltms' ); ?></option>
            <option value="in_review" <?php selected( $status_filter, 'in_review' ); ?>><?php esc_html_e( 'En revisión', 'ltms' ); ?></option>
            <option value="approved" <?php selected( $status_filter, 'approved' ); ?>><?php esc_html_e( 'Aprobadas', 'ltms' ); ?></option>
            <option value="rejected" <?php selected( $status_filter, 'rejected' ); ?>><?php esc_html_e( 'Rechazadas', 'ltms' ); ?></option>
            <option value="credited" <?php selected( $status_filter, 'credited' ); ?>><?php esc_html_e( 'Con crédito', 'ltms' ); ?></option>
            <option value="expired" <?php selected( $status_filter, 'expired' ); ?>><?php esc_html_e( 'Expiradas', 'ltms' ); ?></option>
        </select>
    </form>

    <table class="wp-list-table widefat striped">
        <thead>
            <tr>
                <th>ID</th>
                <th><?php esc_html_e( 'Ledger', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Tipo', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Motivo', 'ltms' ); ?></th>
                <th style="text-align:right;"><?php esc_html_e( 'Esperado', 'ltms' ); ?></th>
                <th style="text-align:right;"><?php esc_html_e( 'Disputado', 'ltms' ); ?></th>
                <th style="text-align:right;"><?php esc_html_e( 'Crédito', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'SLA Vence', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Acciones', 'ltms' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $disputes ) ) : ?>
                <tr><td colspan="10"><?php esc_html_e( 'No hay disputas.', 'ltms' ); ?></td></tr>
            <?php else : foreach ( $disputes as $d ) :
                $sla_ts = strtotime( $d['sla_due_at'] );
                $sla_overdue = $sla_ts < $now && in_array( $d['status'], [ 'open', 'in_review' ], true );
                ?>
                <tr <?php echo $sla_overdue ? 'style="background:#fef2f2;"' : ''; ?>>
                    <td><?php echo esc_html( $d['id'] ); ?></td>
                    <td><a href="<?php echo esc_url( add_query_arg( [ 'page' => 'ltms-shipping-ledger', 'tab' => 'ledger' ], admin_url( 'admin.php' ) ) ); ?>">#<?php echo esc_html( $d['ledger_id'] ); ?></a></td>
                    <td><?php echo esc_html( str_replace( '_', ' ', $d['dispute_type'] ) ); ?></td>
                    <td style="max-width:300px;"><?php echo esc_html( wp_trim_words( $d['dispute_reason'], 15 ) ); ?></td>
                    <td style="text-align:right;"><?php echo esc_html( LTMS_Admin_Shipping_Ledger::format_currency( (float) $d['expected_amount'] ) ); ?></td>
                    <td style="text-align:right;color:#dc2626;font-weight:600;"><?php echo esc_html( LTMS_Admin_Shipping_Ledger::format_currency( (float) $d['disputed_amount'] ) ); ?></td>
                    <td style="text-align:right;color:#16a34a;"><?php echo esc_html( LTMS_Admin_Shipping_Ledger::format_currency( (float) $d['credit_amount'] ) ); ?></td>
                    <td><strong><?php echo esc_html( $d['status'] ); ?></strong></td>
                    <td <?php echo $sla_overdue ? 'style="color:#dc2626;font-weight:600;"' : ''; ?>>
                        <?php echo esc_html( wp_date( 'Y-m-d', $sla_ts ) . ( $sla_overdue ? ' (VENCIDA)' : '' ) ); ?>
                    </td>
                    <td>
                        <?php if ( in_array( $d['status'], [ 'open', 'in_review' ], true ) && current_user_can( 'ltms_manage_platform_settings' ) ) : ?>
                            <details>
                                <summary class="button button-small"><?php esc_html_e( 'Resolver', 'ltms' ); ?></summary>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;background:#f9fafb;padding:8px;border:1px solid #e5e7eb;border-radius:4px;">
                                    <?php wp_nonce_field( 'ltms_resolve_dispute' ); ?>
                                    <input type="hidden" name="action" value="ltms_resolve_dispute" />
                                    <input type="hidden" name="dispute_id" value="<?php echo esc_attr( $d['id'] ); ?>" />
                                    <select name="status">
                                        <option value="approved"><?php esc_html_e( 'Aprobar (espera crédito)', 'ltms' ); ?></option>
                                        <option value="credited"><?php esc_html_e( 'Crédito recibido', 'ltms' ); ?></option>
                                        <option value="rejected"><?php esc_html_e( 'Rechazar', 'ltms' ); ?></option>
                                        <option value="expired"><?php esc_html_e( 'Expirar', 'ltms' ); ?></option>
                                    </select>
                                    <input type="number" name="credit_amount" step="0.01" placeholder="Crédito $" style="width:120px;" />
                                    <textarea name="resolution_notes" placeholder="<?php esc_attr_e( 'Notas de resolución', 'ltms' ); ?>" rows="2" style="width:100%;"></textarea>
                                    <button type="submit" class="button button-primary button-small"><?php esc_html_e( 'Confirmar', 'ltms' ); ?></button>
                                </form>
                            </details>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
    <?php
}

/**
 * Pestaña Presupuestos: lista + form crear/editar.
 */
function ltms_render_budgets_tab(): void {
    $year  = (int) ( $_GET['f_year'] ?? current_time( 'Y' ) );
    $month = (int) ( $_GET['f_month'] ?? current_time( 'n' ) );
    $budgets = LTMS_Admin_Shipping_Ledger::get_budgets( $year, $month );

    // Vendors disponibles.
    $vendors_query = get_users( [ 'role__in' => [ 'vendor', 'seller', 'administrator' ], 'number' => 500, 'fields' => 'ID' ] );
    ?>
    <form method="get" style="margin-bottom:16px;">
        <input type="hidden" name="page" value="ltms-shipping-ledger" />
        <input type="hidden" name="tab" value="budgets" />
        <select name="f_year" onchange="this.form.submit()">
            <?php for ( $y = (int) current_time( 'Y' ); $y >= 2024; $y-- ) : ?>
                <option value="<?php echo esc_attr( $y ); ?>" <?php selected( $year, $y ); ?>><?php echo esc_html( $y ); ?></option>
            <?php endfor; ?>
        </select>
        <select name="f_month" onchange="this.form.submit()">
            <?php for ( $m = 1; $m <= 12; $m++ ) : ?>
                <option value="<?php echo esc_attr( $m ); ?>" <?php selected( $month, $m ); ?>><?php echo esc_html( wp_date( 'F', mktime( 0, 0, 0, $m, 1 ) ) ); ?></option>
            <?php endfor; ?>
        </select>
    </form>

    <?php if ( current_user_can( 'ltms_manage_platform_settings' ) ) : ?>
    <details style="margin-bottom:16px;">
        <summary class="button button-primary"><?php esc_html_e( 'Nuevo / Editar Presupuesto', 'ltms' ); ?></summary>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;background:#fff;padding:16px;border:1px solid #e5e7eb;border-radius:6px;">
            <?php wp_nonce_field( 'ltms_save_vendor_budget' ); ?>
            <input type="hidden" name="action" value="ltms_save_vendor_budget" />
            <input type="hidden" name="period_year" value="<?php echo esc_attr( $year ); ?>" />
            <input type="hidden" name="period_month" value="<?php echo esc_attr( $month ); ?>" />
            <p>
                <label><?php esc_html_e( 'Vendor', 'ltms' ); ?></label><br />
                <select name="vendor_id" required>
                    <option value=""><?php esc_html_e( 'Selecciona vendor', 'ltms' ); ?></option>
                    <?php foreach ( $vendors_query as $vid ) : ?>
                        <option value="<?php echo esc_attr( $vid ); ?>"><?php echo esc_html( LTMS_Admin_Shipping_Ledger::get_vendor_display_name( $vid ) . ' (#' . $vid . ')' ); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p>
                <label><?php esc_html_e( 'Límite mensual (COP)', 'ltms' ); ?></label><br />
                <input type="number" name="budget_limit" step="0.01" min="0" required style="width:200px;" />
                <span class="description"><?php esc_html_e( '0 = sin límite (modo ilimitado).', 'ltms' ); ?></span>
            </p>
            <p>
                <label><?php esc_html_e( 'Umbral soft (alerta, %)', 'ltms' ); ?></label>
                <input type="number" name="soft_threshold" step="0.01" min="0" max="100" value="80" style="width:80px;" />
                <label style="margin-left:16px;"><?php esc_html_e( 'Umbral hard (bloqueo, %)', 'ltms' ); ?></label>
                <input type="number" name="hard_threshold" step="0.01" min="0" max="200" value="100" style="width:80px;" />
            </p>
            <p>
                <label><?php esc_html_e( 'Notas', 'ltms' ); ?></label><br />
                <textarea name="notes" rows="2" style="width:100%;"></textarea>
            </p>
            <button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar', 'ltms' ); ?></button>
        </form>
    </details>
    <?php endif; ?>

    <table class="wp-list-table widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Vendor', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Período', 'ltms' ); ?></th>
                <th style="text-align:right;"><?php esc_html_e( 'Límite', 'ltms' ); ?></th>
                <th style="text-align:right;"><?php esc_html_e( 'Gastado', 'ltms' ); ?></th>
                <th style="text-align:right;"><?php esc_html_e( '%', 'ltms' ); ?></th>
                <th style="text-align:right;"><?php esc_html_e( 'Soft %', 'ltms' ); ?></th>
                <th style="text-align:right;"><?php esc_html_e( 'Hard %', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Bloqueado', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Alertas', 'ltms' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $budgets ) ) : ?>
                <tr><td colspan="9"><?php esc_html_e( 'No hay presupuestos configurados para este período.', 'ltms' ); ?></td></tr>
            <?php else : foreach ( $budgets as $b ) :
                $over_soft = (float) $b['spent_pct'] >= (float) $b['soft_threshold'];
                $over_hard = (float) $b['spent_pct'] >= (float) $b['hard_threshold'];
                ?>
                <tr <?php echo $over_hard ? 'style="background:#fef2f2;"' : ( $over_soft ? 'style="background:#fef3c7;"' : '' ); ?>>
                    <td><?php echo esc_html( LTMS_Admin_Shipping_Ledger::get_vendor_display_name( (int) $b['vendor_id'] ) ); ?></td>
                    <td><?php echo esc_html( $b['period_year'] . '-' . str_pad( $b['period_month'], 2, '0', STR_PAD_LEFT ) ); ?></td>
                    <td style="text-align:right;"><?php echo esc_html( LTMS_Admin_Shipping_Ledger::format_currency( (float) $b['budget_limit'] ) ); ?></td>
                    <td style="text-align:right;font-weight:600;"><?php echo esc_html( LTMS_Admin_Shipping_Ledger::format_currency( (float) $b['spent_amount'] ) ); ?></td>
                    <td style="text-align:right;color:<?php echo $over_hard ? '#dc2626' : ( $over_soft ? '#92400e' : '#16a34a' ); ?>;font-weight:600;">
                        <?php echo esc_html( number_format( (float) $b['spent_pct'], 1 ) ); ?>%
                    </td>
                    <td style="text-align:right;"><?php echo esc_html( number_format( (float) $b['soft_threshold'], 0 ) ); ?>%</td>
                    <td style="text-align:right;"><?php echo esc_html( number_format( (float) $b['hard_threshold'], 0 ) ); ?>%</td>
                    <td><?php echo (int) $b['is_blocked'] ? '<span style="color:#dc2626;font-weight:600;">' . esc_html__( 'SÍ', 'ltms' ) . '</span>' : '-'; ?></td>
                    <td>
                        <?php if ( (int) $b['alert_sent'] ) echo '<span style="color:#92400e;">' . esc_html__( 'Alerta enviada', 'ltms' ) . '</span>'; ?>
                        <?php if ( (int) $b['block_sent'] ) echo '<br><span style="color:#dc2626;">' . esc_html__( 'Bloqueo enviado', 'ltms' ) . '</span>'; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
    <?php
}

// Fin de la vista.
