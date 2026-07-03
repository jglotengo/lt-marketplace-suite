<?php
/**
 * Vista: Admin Tax Reports - Reportes Fiscales
 *
 * @package LTMS
 * @version 2.1.0
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
    $period_filter = $wpdb->prepare( "AND DATE_FORMAT(c.created_at, '%%Y-%%m') = %s", sprintf( '%04d-%02d', $year, $month ) );
} else {
    $period_filter = $wpdb->prepare( 'AND YEAR(c.created_at) = %d', $year );
}

// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$tax_summary = $wpdb->get_row(
    "SELECT
        SUM(c.gross_amount)                                    as total_gross,
        SUM(c.commission_amount)                               as total_platform_fee,
        SUM(c.vendor_amount)                                   as total_vendor_net,
        SUM(c.iva_amount)                                      as total_iva,
        SUM(COALESCE(c.retefuente_amount, c.tax_withholding, 0)) as total_retefuente,
        SUM(COALESCE(c.reteiva_amount, 0))                     as total_reteiva,
        SUM(COALESCE(c.reteica_amount, 0))                     as total_reteica,
        COUNT(*)                                               as total_transactions,
        COUNT(DISTINCT c.vendor_id)                            as total_vendors
    FROM `{$commissions_table}` c
    WHERE c.status IN ('paid','approved')
      AND c.country_code = '" . esc_sql( $country ) . "'
      {$period_filter}",
    ARRAY_A
);
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
            <span class="ltms-stat-label"><?php esc_html_e( 'IVA Causado', 'ltms' ); ?></span>
            <span class="ltms-stat-value"><?php echo esc_html( LTMS_Utils::format_money( (float) $tax_summary['total_iva'] ) ); ?></span>
        </div>
        <?php if ( $country === 'CO' ) : ?>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'ReteFuente', 'ltms' ); ?></span>
            <span class="ltms-stat-value ltms-stat-warning"><?php echo esc_html( LTMS_Utils::format_money( (float) $tax_summary['total_retefuente'] ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'ReteIVA', 'ltms' ); ?></span>
            <span class="ltms-stat-value ltms-stat-warning"><?php echo esc_html( LTMS_Utils::format_money( (float) $tax_summary['total_reteiva'] ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'ReteICA', 'ltms' ); ?></span>
            <span class="ltms-stat-value ltms-stat-warning"><?php echo esc_html( LTMS_Utils::format_money( (float) $tax_summary['total_reteica'] ) ); ?></span>
        </div>
        <?php endif; ?>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Pagado a Vendedores', 'ltms' ); ?></span>
            <span class="ltms-stat-value"><?php echo esc_html( LTMS_Utils::format_money( (float) $tax_summary['total_vendor_net'] ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Transacciones', 'ltms' ); ?></span>
            <span class="ltms-stat-value"><?php echo esc_html( number_format( (int) $tax_summary['total_transactions'] ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Vendedores activos', 'ltms' ); ?></span>
            <span class="ltms-stat-value"><?php echo esc_html( number_format( (int) $tax_summary['total_vendors'] ) ); ?></span>
        </div>
    </div>

    <!-- Botón reporte DIAN exógena -->
    <?php if ( $country === 'CO' ) : ?>
    <div style="margin-bottom:20px;">
        <button type="button" id="ltms-dian-export-btn" class="ltms-btn ltms-btn-primary"
                data-year="<?php echo esc_attr( $year ); ?>"
                data-month="<?php echo esc_attr( $month ); ?>"
                data-nonce="<?php echo esc_attr( wp_create_nonce( 'ltms_admin_nonce' ) ); ?>">
            <?php esc_html_e( '⬇ Exportar Exógena DIAN (JSON)', 'ltms' ); ?>
        </button>
        <span id="ltms-dian-status" style="margin-left:12px;font-style:italic;"></span>
    </div>
    <script>
    (function(){
        document.getElementById('ltms-dian-export-btn').addEventListener('click', function(){
            var btn = this;
            var status = document.getElementById('ltms-dian-status');
            btn.disabled = true;
            status.textContent = '<?php echo esc_js( __( 'Generando…', 'ltms' ) ); ?>';
            fetch(ajaxurl, {
                method: 'POST',
                headers: {'Content-Type':'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    action: 'ltms_generate_dian_report',
                    nonce:  btn.dataset.nonce,
                    year:   btn.dataset.year,
                    month:  btn.dataset.month,
                })
            })
            .then(r => r.json())
            .then(function(res){
                btn.disabled = false;
                if(res.success){
                    var d = res.data;
                    status.textContent = '✅ ' + d.transaction_count + ' transacciones, ' + d.vendor_count + ' vendedores';
                    // Descargar JSON
                    var blob = new Blob([JSON.stringify(d, null, 2)], {type:'application/json'});
                    var a = document.createElement('a');
                    a.href = URL.createObjectURL(blob);
                    a.download = 'dian-exogena-' + d.year + (d.month !== 'all' ? '-m'+d.month : '') + '.json';
                    a.click();
                } else {
                    status.textContent = '❌ ' + (res.data || '<?php echo esc_js( __( 'Error al generar reporte.', 'ltms' ) ); ?>');
                }
            })
            .catch(function(e){ btn.disabled = false; status.textContent = '❌ ' + e.message; });
        });
    })();
    </script>
    <?php endif; ?>

    <div class="ltms-alert ltms-alert-info">
        <p style="margin:0;">
            <strong><?php esc_html_e( 'Nota:', 'ltms' ); ?></strong>
            <?php if ( $country === 'CO' ) : ?>
            <?php esc_html_e( 'Los reportes incluyen ReteFuente, ReteIVA e ICA por transacción y vendedor (ET art. 368, Decreto 0572/2025, UVT 2026 = $52.752). Exógena DIAN conforme al art. 623 ET.', 'ltms' ); ?>
            <?php else : ?>
            <?php esc_html_e( 'Los reportes incluyen ISR Plataformas (Art. 113-A LISR), IVA y IEPS según aplique. Acceso en línea conforme Art. 30-B CFF y RMF 2025.', 'ltms' ); ?>
            <?php endif; ?>
        </p>
    </div>

</div>
