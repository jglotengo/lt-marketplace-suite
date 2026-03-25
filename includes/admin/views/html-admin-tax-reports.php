<?php
/**
 * Vista: Admin Tax Reports - Reportes Fiscales
 *
 * @package LTMS
 * @version 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

LTMS_Data_Masking::log_auditor_access( 'tax_reports_view' );

$country  = LTMS_Core_Config::get_country();
$year     = (int) ( $_GET['year'] ?? gmdate( 'Y' ) ); // phpcs:ignore
$month    = (int) ( $_GET['month'] ?? 0 ); // phpcs:ignore

global $wpdb;
$commissions_table = $wpdb->prefix . 'lt_commissions';

// phpcs:disable WordPress.DB.DirectDatabaseQuery
if ( $month ) {
    $tax_summary = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT
                SUM(gross_amount)    as total_gross,
                SUM(platform_fee)    as total_platform_fee,
                SUM(vendor_net)      as total_vendor_net,
                COUNT(*)             as total_transactions
            FROM `{$commissions_table}` c
            WHERE status = 'paid'
              AND DATE_FORMAT(c.created_at, '%%Y-%%m') = %s",
            sprintf( '%04d-%02d', $year, $month )
        ),
        ARRAY_A
    );
} else {
    $tax_summary = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT
                SUM(gross_amount)    as total_gross,
                SUM(platform_fee)    as total_platform_fee,
                SUM(vendor_net)      as total_vendor_net,
                COUNT(*)             as total_transactions
            FROM `{$commissions_table}` c
            WHERE status = 'paid'
              AND YEAR(c.created_at) = %d",
            $year
        ),
        ARRAY_A
    );
}
// phpcs:enable
?>
<div class="wrap ltms-admin-wrap">

    <div class="ltms-header">
        <h1><?php esc_html_e( 'Reportes Fiscales', 'ltms' ); ?>
            <span class="ltms-badge ltms-badge-primary" style="font-size:0.8rem;margin-left:8px;">
                <?php echo esc_html( $country === 'CO' ? 'Colombia' : 'México' ); ?>
            </span>
        </h1>
    </div>

    <!-- Filtros de período -->
    <form method="get" style="margin-bottom:20px;display:flex;gap:8px;align-items:center;">
        <input type="hidden" name="page" value="ltms-tax-reports">
        <label><?php esc_html_e( 'Año:', 'ltms' ); ?></label>
        <select name="year" style="padding:8px 12px;border:1px solid #ddd;border-radius:4px;">
            <?php for ( $y = gmdate( 'Y' ); $y >= 2023; $y-- ) : ?>
            <option value="<?php echo esc_attr( $y ); ?>" <?php selected( $year, $y ); ?>><?php echo esc_html( $y ); ?></option>
            <?php endfor; ?>
        </select>
        <label><?php esc_html_e( 'Mes:', 'ltms' ); ?></label>
        <select name="month" style="padding:8px 12px;border:1px solid #ddd;border-radius:4px;">
            <option value="0"><?php esc_html_e( 'Todo el año', 'ltms' ); ?></option>
            <?php for ( $m = 1; $m <= 12; $m++ ) : ?>
            <option value="<?php echo esc_attr( $m ); ?>" <?php selected( $month, $m ); ?>><?php echo esc_html( gmdate( 'F', mktime( 0, 0, 0, $m, 1 ) ) ); ?></option>
            <?php endfor; ?>
        </select>
        <button type="submit" class="ltms-btn ltms-btn-primary ltms-btn-sm"><?php esc_html_e( 'Generar', 'ltms' ); ?></button>
    </form>

    <!-- Resumen fiscal -->
    <div class="ltms-stats-grid" style="margin-bottom:24px;">
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Ventas Brutas', 'ltms' ); ?></span>
            <span class="ltms-stat-value"><?php echo esc_html( LTMS_Utils::format_money( (float) $tax_summary['total_gross'] ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Fee Plataforma', 'ltms' ); ?></span>
            <span class="ltms-stat-value ltms-stat-success"><?php echo esc_html( LTMS_Utils::format_money( (float) $tax_summary['total_platform_fee'] ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Pagado a Vendedores', 'ltms' ); ?></span>
            <span class="ltms-stat-value"><?php echo esc_html( LTMS_Utils::format_money( (float) $tax_summary['total_vendor_net'] ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Transacciones', 'ltms' ); ?></span>
            <span class="ltms-stat-value"><?php echo esc_html( number_format( (int) $tax_summary['total_transactions'] ) ); ?></span>
        </div>
    </div>

    <div class="ltms-alert ltms-alert-info">
        <p style="margin:0;">
            <strong><?php esc_html_e( 'Nota:', 'ltms' ); ?></strong>
            <?php if ( $country === 'CO' ) : ?>
            <?php esc_html_e( 'Los reportes incluyen ReteFuente, ReteIVA e ICA según aplique (Ley 1819/2016, Decreto 2229/2024).', 'ltms' ); ?>
            <?php else : ?>
            <?php esc_html_e( 'Los reportes incluyen ISR Plataformas (Art. 113-A LISR), IVA y IEPS según aplique.', 'ltms' ); ?>
            <?php endif; ?>
        </p>
    </div>

</div>
