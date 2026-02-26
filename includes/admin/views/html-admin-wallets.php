<?php
/**
 * Vista: Admin Wallets - Billeteras de Vendedores
 *
 * @package LTMS
 * @version 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;
$table    = $wpdb->prefix . 'lt_vendor_wallets';
$page_num = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore
$per_page = 25;
$offset   = ( $page_num - 1 ) * $per_page;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wallets = $wpdb->get_results(
    "SELECT w.*, u.display_name, u.user_email FROM `{$table}` w LEFT JOIN `{$wpdb->users}` u ON u.ID = w.user_id ORDER BY w.balance DESC LIMIT {$per_page} OFFSET {$offset}",
    ARRAY_A
);
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$total_balance = (float) $wpdb->get_var( "SELECT SUM(balance) FROM `{$table}`" );
$total_held    = (float) $wpdb->get_var( "SELECT SUM(held_balance) FROM `{$table}`" ); // phpcs:ignore
?>
<div class="wrap ltms-admin-wrap">

    <div class="ltms-header">
        <h1><?php esc_html_e( 'Billeteras de Vendedores', 'ltms' ); ?></h1>
    </div>

    <!-- Resumen total -->
    <div class="ltms-stats-grid" style="margin-bottom:24px;">
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Total en Billeteras', 'ltms' ); ?></span>
            <span class="ltms-stat-value"><?php echo esc_html( LTMS_Utils::format_money( $total_balance ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Total en Tránsito', 'ltms' ); ?></span>
            <span class="ltms-stat-value"><?php echo esc_html( LTMS_Utils::format_money( $total_held ) ); ?></span>
        </div>
    </div>

    <div class="ltms-table-wrap">
        <div class="ltms-table-title">
            <?php esc_html_e( 'Billeteras de Vendedores', 'ltms' ); ?>
        </div>
        <input type="text" class="ltms-table-search"
               placeholder="<?php esc_attr_e( 'Buscar vendedor...', 'ltms' ); ?>"
               style="margin:12px 16px;padding:8px 12px;width:280px;border:1px solid #ddd;border-radius:4px;">
        <table class="ltms-table ltms-searchable-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Vendedor', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Balance', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'En Tránsito', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Disponible', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Acciones', 'ltms' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $wallets ) ) : ?>
                <tr><td colspan="6" style="text-align:center;padding:30px;color:#888;">
                    <?php esc_html_e( 'No hay billeteras registradas.', 'ltms' ); ?>
                </td></tr>
                <?php else : ?>
                <?php foreach ( $wallets as $wallet ) :
                    $available = (float) $wallet['balance'] - (float) $wallet['held_balance'];
                ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html( $wallet['display_name'] ); ?></strong><br>
                        <small style="color:#888;"><?php echo esc_html( $wallet['user_email'] ); ?></small>
                    </td>
                    <td><strong><?php echo esc_html( LTMS_Utils::format_money( (float) $wallet['balance'] ) ); ?></strong></td>
                    <td><?php echo esc_html( LTMS_Utils::format_money( (float) $wallet['held_balance'] ) ); ?></td>
                    <td><?php echo esc_html( LTMS_Utils::format_money( $available ) ); ?></td>
                    <td>
                        <?php if ( $wallet['is_frozen'] ) : ?>
                        <span class="ltms-badge ltms-badge-danger"><?php esc_html_e( 'Congelada', 'ltms' ); ?></span>
                        <?php else : ?>
                        <span class="ltms-badge ltms-badge-success"><?php esc_html_e( 'Activa', 'ltms' ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ( (int) $wallet['is_frozen'] ) : ?>
                        <button type="button" class="ltms-btn ltms-btn-success ltms-btn-sm"
                                onclick="LTMS.Admin.ajaxAction('ltms_unfreeze_wallet', {vendor_id: <?php echo esc_js( $wallet['user_id'] ); ?>}, function(r){if(r.success)location.reload();})">
                            🔓 <?php esc_html_e( 'Descongelar', 'ltms' ); ?>
                        </button>
                        <?php else : ?>
                        <button type="button" class="ltms-btn ltms-btn-danger ltms-btn-sm ltms-freeze-wallet"
                                data-vendor-id="<?php echo esc_attr( $wallet['user_id'] ); ?>">
                            🔒 <?php esc_html_e( 'Congelar', 'ltms' ); ?>
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>
