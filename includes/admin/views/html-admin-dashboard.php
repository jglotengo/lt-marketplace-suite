<?php
/**
 * Vista: Admin Dashboard - Panel Principal de LTMS
 *
 * @package LTMS
 * @version 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Obtener estadísticas globales
global $wpdb;

$commissions_table = $wpdb->prefix . 'lt_commissions';
$wallets_table     = $wpdb->prefix . 'lt_vendor_wallets';
$payouts_table     = $wpdb->prefix . 'lt_payout_requests';

$month_start = gmdate( 'Y-m-01' );

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$total_sales_month = (float) $wpdb->get_var( "SELECT SUM(gross_amount) FROM `{$commissions_table}` WHERE DATE(created_at) >= '{$month_start}'" );
$total_vendors     = (int) count_users()['avail_roles']['ltms_vendor'] ?? 0;
$pending_payouts   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$payouts_table}` WHERE status = 'pending'" );
$pending_kyc       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->prefix}lt_vendor_kyc` WHERE status = 'pending'" );
// phpcs:enable
?>
<div class="wrap ltms-admin-wrap">

    <div class="ltms-header">
        <img src="<?php echo esc_url( LTMS_ASSETS_URL . 'img/ltms-logo.svg' ); ?>" class="ltms-logo" alt="LTMS" onerror="this.style.display='none'">
        <h1><?php esc_html_e( 'LT Marketplace Suite', 'ltms' ); ?></h1>
        <span style="color:#666;font-size:0.85rem;margin-left:auto">v<?php echo esc_html( LTMS_VERSION ); ?></span>
    </div>

    <?php if ( ! defined( 'LTMS_ENCRYPTION_KEY' ) || empty( LTMS_ENCRYPTION_KEY ) ) : ?>
    <div class="notice notice-error">
        <p><strong><?php esc_html_e( 'Seguridad:', 'ltms' ); ?></strong>
        <?php esc_html_e( 'Define LTMS_ENCRYPTION_KEY en wp-config.php antes de usar el plugin en producción.', 'ltms' ); ?></p>
    </div>
    <?php endif; ?>

    <!-- Métricas del mes -->
    <div class="ltms-stats-grid">
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Ventas del Mes', 'ltms' ); ?></span>
            <span class="ltms-stat-value"><?php echo esc_html( LTMS_Utils::format_money( $total_sales_month ) ); ?></span>
            <span class="ltms-stat-sub"><?php echo esc_html( gmdate( 'F Y' ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Vendedores Activos', 'ltms' ); ?></span>
            <span class="ltms-stat-value"><?php echo esc_html( number_format( $total_vendors ) ); ?></span>
            <span class="ltms-stat-sub"><?php esc_html_e( 'Total registrados', 'ltms' ); ?></span>
        </div>
        <div class="ltms-stat-card <?php echo $pending_payouts > 0 ? 'ltms-stat-warning' : ''; ?>">
            <span class="ltms-stat-label"><?php esc_html_e( 'Retiros Pendientes', 'ltms' ); ?></span>
            <span class="ltms-stat-value"><?php echo esc_html( $pending_payouts ); ?></span>
            <span class="ltms-stat-sub">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=ltms-payouts' ) ); ?>"><?php esc_html_e( 'Ver retiros →', 'ltms' ); ?></a>
            </span>
        </div>
        <div class="ltms-stat-card <?php echo $pending_kyc > 0 ? 'ltms-stat-warning' : ''; ?>">
            <span class="ltms-stat-label"><?php esc_html_e( 'KYC Pendientes', 'ltms' ); ?></span>
            <span class="ltms-stat-value"><?php echo esc_html( $pending_kyc ); ?></span>
            <span class="ltms-stat-sub">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=ltms-kyc' ) ); ?>"><?php esc_html_e( 'Revisar →', 'ltms' ); ?></a>
            </span>
        </div>
    </div>

    <!-- Gráfico de Ventas -->
    <div class="ltms-table-wrap" style="padding:20px;margin-bottom:24px;">
        <div class="ltms-table-title">
            <?php esc_html_e( 'Ventas - Últimos 30 Días', 'ltms' ); ?>
            <select id="ltms-chart-period" style="font-size:0.85rem;padding:4px 8px;">
                <option value="30days"><?php esc_html_e( 'Últimos 30 días', 'ltms' ); ?></option>
                <option value="7days"><?php esc_html_e( 'Últimos 7 días', 'ltms' ); ?></option>
                <option value="12months"><?php esc_html_e( 'Últimos 12 meses', 'ltms' ); ?></option>
            </select>
        </div>
        <div style="height:280px;padding:16px;">
            <canvas id="ltms-sales-chart"></canvas>
        </div>
    </div>

    <!-- Gráfico de Comisiones + Accesos rápidos -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:24px;">
        <div class="ltms-table-wrap" style="padding:20px;">
            <div class="ltms-table-title"><?php esc_html_e( 'Comisiones por Vendedor (Mes)', 'ltms' ); ?></div>
            <div style="height:220px;padding:16px;">
                <canvas id="ltms-commissions-chart"></canvas>
            </div>
        </div>

        <div class="ltms-table-wrap" style="padding:20px;">
            <div class="ltms-table-title"><?php esc_html_e( 'Accesos Rápidos', 'ltms' ); ?></div>
            <div style="padding:16px;display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <?php
                $quick_links = [
                    [ 'page' => 'ltms-vendors',    'icon' => '👥', 'label' => __( 'Vendedores', 'ltms' ) ],
                    [ 'page' => 'ltms-payouts',    'icon' => '💸', 'label' => __( 'Retiros', 'ltms' ) ],
                    [ 'page' => 'ltms-kyc',        'icon' => '🪪', 'label' => __( 'KYC', 'ltms' ) ],
                    [ 'page' => 'ltms-wallets',    'icon' => '👜', 'label' => __( 'Billeteras', 'ltms' ) ],
                    [ 'page' => 'ltms-tax-reports','icon' => '📊', 'label' => __( 'Reportes', 'ltms' ) ],
                    [ 'page' => 'ltms-settings',   'icon' => '⚙️', 'label' => __( 'Configurar', 'ltms' ) ],
                ];
                foreach ( $quick_links as $link ) :
                ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $link['page'] ) ); ?>"
                   class="ltms-btn ltms-btn-outline"
                   style="justify-content:center;gap:8px;padding:12px;">
                    <?php echo esc_html( $link['icon'] ); ?>
                    <?php echo esc_html( $link['label'] ); ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Estado de las APIs -->
    <div class="ltms-table-wrap">
        <div class="ltms-table-title">
            <?php esc_html_e( 'Estado de Integraciones', 'ltms' ); ?>
        </div>
        <table class="ltms-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Integración', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Entorno', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Acción', 'ltms' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $integrations = [
                    [ 'key' => 'ltms_siigo_enabled',  'name' => 'Siigo ERP',    'provider' => 'siigo' ],
                    [ 'key' => 'ltms_openpay_enabled', 'name' => 'Openpay',      'provider' => 'openpay' ],
                    [ 'key' => 'ltms_addi_enabled',    'name' => 'Addi BNPL',    'provider' => 'addi' ],
                    [ 'key' => 'ltms_mlm_enabled',     'name' => 'TPTC Red MLM', 'provider' => 'tptc' ],
                    [ 'key' => 'ltms_xcover_enabled',  'name' => 'XCover Seguros', 'provider' => 'xcover' ],
                ];
                foreach ( $integrations as $intg ) :
                    $enabled = LTMS_Core_Config::get( $intg['key'], 'no' ) === 'yes';
                ?>
                <tr>
                    <td><strong><?php echo esc_html( $intg['name'] ); ?></strong></td>
                    <td><span class="ltms-badge <?php echo esc_attr( LTMS_ENVIRONMENT === 'production' ? 'ltms-badge-success' : 'ltms-badge-warning' ); ?>">
                        <?php echo esc_html( LTMS_ENVIRONMENT === 'production' ? __( 'Producción', 'ltms' ) : __( 'Sandbox', 'ltms' ) ); ?>
                    </span></td>
                    <td>
                        <?php if ( $enabled ) : ?>
                            <span class="ltms-badge ltms-badge-success"><?php esc_html_e( 'Activo', 'ltms' ); ?></span>
                        <?php else : ?>
                            <span class="ltms-badge ltms-badge-pending"><?php esc_html_e( 'Inactivo', 'ltms' ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ( $enabled ) : ?>
                            <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm"
                                    data-provider="<?php echo esc_attr( $intg['provider'] ); ?>"
                                    onclick="LTMS.Admin.testApiConnection('<?php echo esc_js( $intg['provider'] ); ?>', this)">
                                <?php esc_html_e( 'Probar conexión', 'ltms' ); ?>
                            </button>
                        <?php else : ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ltms-settings' ) ); ?>" class="ltms-btn ltms-btn-outline ltms-btn-sm">
                                <?php esc_html_e( 'Configurar', 'ltms' ); ?>
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</div>
