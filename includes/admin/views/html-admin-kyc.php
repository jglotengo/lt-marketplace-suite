<?php
/**
 * Vista: Admin KYC - Verificación de Identidad de Vendedores
 *
 * @package LTMS
 * @version 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;
$table  = $wpdb->prefix . 'lt_vendor_kyc';
$status = sanitize_key( $_GET['status'] ?? 'pending' ); // phpcs:ignore

// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$kyc_records = $wpdb->get_results(
    $wpdb->prepare( "SELECT k.*, u.display_name, u.user_email FROM `{$table}` k LEFT JOIN `{$wpdb->users}` u ON u.ID = k.vendor_id WHERE k.status = %s ORDER BY k.submitted_at DESC LIMIT 50", $status ),
    ARRAY_A
);
?>
<div class="wrap ltms-admin-wrap">

    <div class="ltms-header">
        <h1><?php esc_html_e( 'Verificación KYC', 'ltms' ); ?></h1>
    </div>

    <div style="display:flex;gap:8px;margin-bottom:20px;">
        <?php foreach ( [ 'pending' => __( 'Pendiente', 'ltms' ), 'approved' => __( 'Aprobado', 'ltms' ), 'rejected' => __( 'Rechazado', 'ltms' ) ] as $s => $label ) : ?>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ltms-kyc&status=' . $s ) ); ?>"
           class="ltms-btn <?php echo $status === $s ? 'ltms-btn-primary' : 'ltms-btn-outline'; ?> ltms-btn-sm">
            <?php echo esc_html( $label ); ?>
        </a>
        <?php endforeach; ?>
    </div>

    <div class="ltms-table-wrap">
        <table class="ltms-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Vendedor', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Tipo Documento', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Fecha Envío', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Acciones', 'ltms' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $kyc_records ) ) : ?>
                <tr><td colspan="5" style="text-align:center;padding:30px;color:#888;">
                    <?php esc_html_e( 'No hay registros KYC en este estado.', 'ltms' ); ?>
                </td></tr>
                <?php else : ?>
                <?php foreach ( $kyc_records as $kyc ) :
                    $badge_class = $kyc['status'] === 'approved' ? 'ltms-badge-success' : ( $kyc['status'] === 'rejected' ? 'ltms-badge-danger' : 'ltms-badge-warning' );
                ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html( $kyc['display_name'] ); ?></strong><br>
                        <small style="color:#888;"><?php echo esc_html( $kyc['user_email'] ); ?></small>
                    </td>
                    <td><?php echo esc_html( $kyc['document_type'] ?? '—' ); ?></td>
                    <td><?php echo esc_html( $kyc['submitted_at'] ? gmdate( 'd/m/Y H:i', strtotime( $kyc['submitted_at'] ) ) : '—' ); ?></td>
                    <td><span class="ltms-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( ucfirst( $kyc['status'] ) ); ?></span></td>
                    <td class="ltms-actions">
                        <?php if ( $kyc['status'] === 'pending' ) : ?>
                        <button type="button" class="ltms-btn ltms-btn-success ltms-btn-sm ltms-approve-kyc"
                                data-kyc-id="<?php echo esc_attr( $kyc['id'] ); ?>">
                            ✓ <?php esc_html_e( 'Aprobar', 'ltms' ); ?>
                        </button>
                        <button type="button" class="ltms-btn ltms-btn-danger ltms-btn-sm"
                                onclick="var r=prompt('<?php echo esc_js( __( 'Motivo del rechazo:', 'ltms' ) ); ?>');if(r){jQuery.post(ltmsAdmin.ajax_url,{action:'ltms_reject_kyc',nonce:ltmsAdmin.nonce,kyc_id:<?php echo (int)$kyc['id']; ?>,reason:r},function(res){if(res.success)location.reload();})}">
                            ✗ <?php esc_html_e( 'Rechazar', 'ltms' ); ?>
                        </button>
                        <?php else : ?>
                        <span style="color:#888;font-size:0.8rem;"><?php echo esc_html( ucfirst( $kyc['status'] ) ); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>
