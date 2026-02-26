<?php
/**
 * Vista: Admin Payouts - Solicitudes de Retiro
 *
 * @package LTMS
 * @version 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

$status_filter = sanitize_key( $_GET['status'] ?? 'pending' ); // phpcs:ignore
$page_num      = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore
$per_page      = 20;
$offset        = ( $page_num - 1 ) * $per_page;

$table  = $wpdb->prefix . 'lt_payout_requests';
$where  = $status_filter ? $wpdb->prepare( 'WHERE p.status = %s', $status_filter ) : '';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$payouts = $wpdb->get_results(
    "SELECT p.*, u.display_name, u.user_email FROM `{$table}` p LEFT JOIN `{$wpdb->users}` u ON u.ID = p.vendor_id {$where} ORDER BY p.created_at DESC LIMIT {$per_page} OFFSET {$offset}",
    ARRAY_A
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` p {$where}" );

$status_labels = [
    'pending'   => [ 'label' => __( 'Pendiente', 'ltms' ),  'class' => 'ltms-badge-warning' ],
    'completed' => [ 'label' => __( 'Completado', 'ltms' ), 'class' => 'ltms-badge-success' ],
    'rejected'  => [ 'label' => __( 'Rechazado', 'ltms' ),  'class' => 'ltms-badge-danger' ],
];
?>
<div class="wrap ltms-admin-wrap">

    <div class="ltms-header">
        <h1><?php esc_html_e( 'Solicitudes de Retiro', 'ltms' ); ?></h1>
    </div>

    <!-- Filtros de estado -->
    <div style="margin-bottom:20px;display:flex;gap:8px;align-items:center;">
        <?php foreach ( $status_labels as $s => $info ) : ?>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ltms-payouts&status=' . $s ) ); ?>"
           class="ltms-btn <?php echo $status_filter === $s ? 'ltms-btn-primary' : 'ltms-btn-outline'; ?> ltms-btn-sm">
            <?php echo esc_html( $info['label'] ); ?>
        </a>
        <?php endforeach; ?>
        <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm" id="ltms-export-payouts" style="margin-left:auto">
            📥 <?php esc_html_e( 'Exportar CSV', 'ltms' ); ?>
        </button>
    </div>

    <div class="ltms-table-wrap">
        <div class="ltms-table-title">
            <?php
            printf(
                /* translators: %1$s: estado, %2$d: total */
                esc_html__( 'Retiros %1$s (%2$d total)', 'ltms' ),
                esc_html( $status_labels[ $status_filter ]['label'] ?? '' ),
                $total
            );
            ?>
        </div>

        <input type="text" class="ltms-table-search" placeholder="<?php esc_attr_e( 'Buscar vendedor...', 'ltms' ); ?>"
               style="margin:12px 16px;padding:8px 12px;width:280px;border:1px solid #ddd;border-radius:4px;">

        <table class="ltms-table ltms-searchable-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'ID', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Vendedor', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Monto', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Método', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Referencia', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Fecha', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Acciones', 'ltms' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $payouts ) ) : ?>
                <tr><td colspan="8" style="text-align:center;padding:30px;color:#888;">
                    <?php esc_html_e( 'No hay solicitudes de retiro.', 'ltms' ); ?>
                </td></tr>
                <?php else : ?>
                <?php foreach ( $payouts as $payout ) :
                    $badge = $status_labels[ $payout['status'] ] ?? [ 'label' => $payout['status'], 'class' => 'ltms-badge-pending' ];
                ?>
                <tr>
                    <td>#<?php echo esc_html( $payout['id'] ); ?></td>
                    <td>
                        <?php echo esc_html( $payout['display_name'] ); ?><br>
                        <small style="color:#888"><?php echo esc_html( $payout['user_email'] ); ?></small>
                    </td>
                    <td><strong><?php echo esc_html( LTMS_Utils::format_money( (float) $payout['amount'] ) ); ?></strong></td>
                    <td><?php echo esc_html( strtoupper( $payout['method'] ) ); ?></td>
                    <td><span class="ltms-badge <?php echo esc_attr( $badge['class'] ); ?>"><?php echo esc_html( $badge['label'] ); ?></span></td>
                    <td><code><?php echo esc_html( $payout['reference'] ); ?></code></td>
                    <td><?php echo esc_html( gmdate( 'd/m/Y H:i', strtotime( $payout['created_at'] ) ) ); ?></td>
                    <td class="ltms-actions">
                        <?php if ( $payout['status'] === 'pending' ) : ?>
                        <button type="button" class="ltms-btn ltms-btn-success ltms-btn-sm ltms-approve-payout"
                                data-payout-id="<?php echo esc_attr( $payout['id'] ); ?>">
                            ✓ <?php esc_html_e( 'Aprobar', 'ltms' ); ?>
                        </button>
                        <button type="button" class="ltms-btn ltms-btn-danger ltms-btn-sm ltms-reject-payout"
                                data-payout-id="<?php echo esc_attr( $payout['id'] ); ?>">
                            ✗ <?php esc_html_e( 'Rechazar', 'ltms' ); ?>
                        </button>
                        <?php else : ?>
                        <span style="color:#888;font-size:0.8rem;"><?php esc_html_e( 'Procesado', 'ltms' ); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>
