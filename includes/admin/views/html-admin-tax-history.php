<?php
/**
 * Admin View: Historial de Cambios de Tasas Tributarias
 *
 * @package LTMS
 * @version 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Sin permiso.', 'ltms' ) );

global $wpdb;
$table = $wpdb->prefix . 'lt_tax_rates_history';

$filter_country = sanitize_text_field( $_GET['country']  ?? '' ); // phpcs:ignore
$filter_key     = sanitize_text_field( $_GET['rate_key'] ?? '' ); // phpcs:ignore
$filter_from    = sanitize_text_field( $_GET['from']     ?? gmdate( 'Y-m-01' ) ); // phpcs:ignore
$filter_to      = sanitize_text_field( $_GET['to']       ?? gmdate( 'Y-m-d' ) );  // phpcs:ignore

$where  = ' WHERE valid_from BETWEEN %s AND %s';
$params = [ $filter_from, $filter_to . ' 23:59:59' ];

if ( $filter_country ) { $where .= ' AND country = %s';       $params[] = $filter_country; }
if ( $filter_key )     { $where .= ' AND rate_key LIKE %s';   $params[] = '%' . $wpdb->esc_like( $filter_key ) . '%'; }

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$rows = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT h.*, u.display_name FROM `{$table}` h LEFT JOIN {$wpdb->users} u ON u.ID = h.changed_by" . $where . ' ORDER BY h.id DESC LIMIT 500',
        ...$params
    ),
    ARRAY_A
);

// Stats
$stats_raw = $wpdb->get_results(
    "SELECT country, COUNT(*) as total FROM `{$table}` GROUP BY country",
    ARRAY_A
);
// phpcs:enable

$stats_by_country = [];
foreach ( $stats_raw as $s ) {
    $stats_by_country[ $s['country'] ] = (int) $s['total'];
}
$total_changes = array_sum( $stats_by_country );

$base_url = admin_url( 'admin.php?page=ltms-tax-history' );
$has_filters = $filter_country || $filter_key || $filter_from !== gmdate( 'Y-m-01' ) || $filter_to !== gmdate( 'Y-m-d' );

$country_labels = [
    'CO' => '🇨🇴 Colombia',
    'MX' => '🇲🇽 México',
];
?>
<div class="wrap ltms-admin-wrap">

    <div class="ltms-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <h1>📋 <?php esc_html_e( 'Historial de Tasas Tributarias', 'ltms' ); ?></h1>
        <div style="display:flex;gap:8px;">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ltms-fiscal-co' ) ); ?>" class="ltms-btn ltms-btn-outline ltms-btn-sm">
                🇨🇴 <?php esc_html_e( 'Fiscal CO', 'ltms' ); ?>
            </a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ltms-fiscal-mx' ) ); ?>" class="ltms-btn ltms-btn-outline ltms-btn-sm">
                🇲🇽 <?php esc_html_e( 'Fiscal MX', 'ltms' ); ?>
            </a>
        </div>
    </div>

    <!-- Stats -->
    <div class="ltms-stats-grid" style="margin-bottom:24px;">
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Total cambios', 'ltms' ); ?></span>
            <span class="ltms-stat-value"><?php echo esc_html( number_format( $total_changes ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Cambios Colombia', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="color:#f59e0b;"><?php echo esc_html( number_format( $stats_by_country['CO'] ?? 0 ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Cambios México', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="color:#16a34a;"><?php echo esc_html( number_format( $stats_by_country['MX'] ?? 0 ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Resultados filtro', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="color:#2563eb;"><?php echo esc_html( count( $rows ) ); ?></span>
        </div>
    </div>

    <!-- Filtros -->
    <form method="get" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:20px;padding:16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
        <input type="hidden" name="page" value="ltms-tax-history">

        <div>
            <label style="display:block;font-size:0.75rem;font-weight:600;color:#374151;margin-bottom:3px;"><?php esc_html_e( 'País', 'ltms' ); ?></label>
            <select name="country" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;background:#fff;">
                <option value=""><?php esc_html_e( 'Todos', 'ltms' ); ?></option>
                <option value="CO" <?php selected( $filter_country, 'CO' ); ?>>🇨🇴 Colombia</option>
                <option value="MX" <?php selected( $filter_country, 'MX' ); ?>>🇲🇽 México</option>
            </select>
        </div>

        <div>
            <label style="display:block;font-size:0.75rem;font-weight:600;color:#374151;margin-bottom:3px;"><?php esc_html_e( 'Clave de tasa', 'ltms' ); ?></label>
            <input type="text" name="rate_key" value="<?php echo esc_attr( $filter_key ); ?>"
                   placeholder="<?php esc_attr_e( 'Ej: ltms_uvt_valor', 'ltms' ); ?>"
                   style="padding:7px 12px;border:1px solid #d1d5db;border-radius:6px;width:200px;font-family:monospace;font-size:12px;">
        </div>

        <div>
            <label style="display:block;font-size:0.75rem;font-weight:600;color:#374151;margin-bottom:3px;"><?php esc_html_e( 'Desde', 'ltms' ); ?></label>
            <input type="date" name="from" value="<?php echo esc_attr( $filter_from ); ?>"
                   style="padding:7px;border:1px solid #d1d5db;border-radius:6px;">
        </div>

        <div>
            <label style="display:block;font-size:0.75rem;font-weight:600;color:#374151;margin-bottom:3px;"><?php esc_html_e( 'Hasta', 'ltms' ); ?></label>
            <input type="date" name="to" value="<?php echo esc_attr( $filter_to ); ?>"
                   style="padding:7px;border:1px solid #d1d5db;border-radius:6px;">
        </div>

        <button type="submit" class="ltms-btn ltms-btn-primary ltms-btn-sm">
            🔍 <?php esc_html_e( 'Filtrar', 'ltms' ); ?>
        </button>

        <?php if ( $has_filters ) : ?>
        <a href="<?php echo esc_url( $base_url ); ?>" class="ltms-btn ltms-btn-outline ltms-btn-sm">
            ✕ <?php esc_html_e( 'Limpiar', 'ltms' ); ?>
        </a>
        <?php endif; ?>
    </form>

    <!-- Tabla -->
    <div class="ltms-table-wrap">
        <div class="ltms-table-title" style="display:flex;justify-content:space-between;align-items:center;">
            <span><?php esc_html_e( 'Registro de cambios', 'ltms' ); ?></span>
            <span style="font-size:12px;color:#888;">
                <?php if ( empty( $rows ) ) : ?>
                    <?php esc_html_e( '0 registros', 'ltms' ); ?>
                <?php else : ?>
                    <?php printf( esc_html__( '%d registros (máx. 500)', 'ltms' ), count( $rows ) ); ?>
                <?php endif; ?>
            </span>
        </div>

        <?php if ( empty( $rows ) ) : ?>
        <div style="text-align:center;padding:48px;color:#888;">
            <div style="font-size:2rem;margin-bottom:8px;">📋</div>
            <p style="margin:0 0 4px;font-weight:600;"><?php esc_html_e( 'No hay registros para los filtros seleccionados.', 'ltms' ); ?></p>
            <p style="font-size:0.85rem;color:#aaa;margin:0;"><?php esc_html_e( 'El historial se genera automáticamente al guardar cambios en Fiscal CO o Fiscal MX.', 'ltms' ); ?></p>
        </div>
        <?php else : ?>
        <table class="ltms-table">
            <thead>
                <tr>
                    <th style="width:50px;">ID</th>
                    <th style="width:90px;"><?php esc_html_e( 'País', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Clave de tasa', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Valor anterior', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Valor nuevo', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Cambio', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Decreto', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Modificado por', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Vigencia desde', 'ltms' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $rows as $row ) :
                $delta       = (float) $row['new_value'] - (float) $row['old_value'];
                $delta_color = $delta > 0 ? '#dc2626' : ( $delta < 0 ? '#16a34a' : '#6b7280' );
                $delta_sign  = $delta > 0 ? '+' : '';
                $country_lbl = $country_labels[ $row['country'] ] ?? $row['country'];
            ?>
            <tr>
                <td style="font-size:11px;color:#9ca3af;"><?php echo esc_html( $row['id'] ); ?></td>
                <td>
                    <span class="ltms-badge <?php echo $row['country'] === 'CO' ? 'ltms-badge-warning' : 'ltms-badge-success'; ?>">
                        <?php echo esc_html( $row['country'] ); ?>
                    </span>
                </td>
                <td>
                    <code style="font-size:11px;background:#f1f5f9;padding:2px 6px;border-radius:3px;color:#334155;">
                        <?php echo esc_html( $row['rate_key'] ); ?>
                    </code>
                </td>
                <td style="font-size:13px;color:#6b7280;">
                    <?php echo esc_html( number_format( (float) $row['old_value'], 4 ) ); ?>
                </td>
                <td style="font-size:13px;font-weight:600;color:<?php echo esc_attr( $delta_color ); ?>;">
                    <?php echo esc_html( number_format( (float) $row['new_value'], 4 ) ); ?>
                </td>
                <td>
                    <?php if ( abs( $delta ) > 0.000001 ) : ?>
                    <span style="font-size:11px;font-weight:600;color:<?php echo esc_attr( $delta_color ); ?>;">
                        <?php echo esc_html( $delta_sign . number_format( $delta, 4 ) ); ?>
                    </span>
                    <?php else : ?>
                    <span style="color:#d1d5db;">—</span>
                    <?php endif; ?>
                </td>
                <td style="font-size:12px;color:#6b7280;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                    title="<?php echo esc_attr( $row['decree_reference'] ?? '' ); ?>">
                    <?php echo esc_html( $row['decree_reference'] ?: '—' ); ?>
                </td>
                <td style="font-size:12px;"><?php echo esc_html( $row['display_name'] ?: '—' ); ?></td>
                <td style="font-size:12px;white-space:nowrap;color:#6b7280;">
                    <?php echo esc_html( $row['valid_from'] ? gmdate( 'd/m/Y', strtotime( $row['valid_from'] ) ) : '—' ); ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <div style="padding:12px 16px;border-top:1px solid #f3f4f6;background:#f8fafc;">
            <em style="font-size:0.8rem;color:#9ca3af;">
                🔒 <?php esc_html_e( 'Este registro no puede modificarse ni eliminarse. Sirve como trazabilidad de auditoría.', 'ltms' ); ?>
            </em>
        </div>
    </div>

</div>
