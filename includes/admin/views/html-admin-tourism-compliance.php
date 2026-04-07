<?php
/**
 * Vista admin: Compliance Turístico — RNT / SECTUR
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;
$pending  = $wpdb->get_results( "SELECT tc.*, u.display_name FROM {$wpdb->prefix}lt_tourism_compliance tc LEFT JOIN {$wpdb->users} u ON u.ID = tc.vendor_id WHERE tc.status = 'pending' ORDER BY tc.created_at DESC", ARRAY_A ) ?: [];
$expiring = $wpdb->get_results( "SELECT tc.*, u.display_name FROM {$wpdb->prefix}lt_tourism_compliance tc LEFT JOIN {$wpdb->users} u ON u.ID = tc.vendor_id WHERE tc.rnt_expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND tc.rnt_verified = 1 ORDER BY tc.rnt_expiry_date ASC", ARRAY_A ) ?: [];
$summary  = $wpdb->get_row( "SELECT COUNT(*) as total, SUM(rnt_verified=1) as verified, SUM(status='pending') as pending_count, SUM(status='expired') as expired FROM {$wpdb->prefix}lt_tourism_compliance", ARRAY_A );
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Compliance Turístico — RNT / SECTUR', 'ltms' ); ?></h1>

    <div style="display:flex;gap:16px;margin-bottom:24px;flex-wrap:wrap;">
        <?php foreach ( [
            [ 'Total vendedores', $summary['total'] ?? 0, '#0073aa' ],
            [ 'Verificados', $summary['verified'] ?? 0, '#28a745' ],
            [ 'Pendientes', $summary['pending_count'] ?? 0, '#ffc107' ],
            [ 'Vencidos', $summary['expired'] ?? 0, '#dc3545' ],
        ] as [ $label, $value, $color ] ) : ?>
            <div style="background:#fff;border-left:4px solid <?php echo esc_attr( $color ); ?>;padding:16px 24px;border-radius:4px;box-shadow:0 1px 4px rgba(0,0,0,.1);min-width:130px;">
                <div style="font-size:28px;font-weight:700;color:<?php echo esc_attr( $color ); ?>;"><?php echo (int) $value; ?></div>
                <div style="color:#666;font-size:12px;"><?php echo esc_html( $label ); ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <h2><?php esc_html_e( '⏳ Pendientes de verificación', 'ltms' ); ?></h2>
    <?php if ( empty( $pending ) ) : ?>
        <p style="color:#666;"><?php esc_html_e( 'Sin solicitudes pendientes. ✓', 'ltms' ); ?></p>
    <?php else : ?>
        <table class="wp-list-table widefat striped">
            <thead><tr>
                <th><?php esc_html_e( 'Vendedor', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'N° RNT / Folio', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'País', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Decl. Jurada', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Fecha envío', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Acciones', 'ltms' ); ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ( $pending as $row ) : ?>
                    <tr>
                        <td><?php echo esc_html( $row['display_name'] ?? '#' . $row['vendor_id'] ); ?></td>
                        <td><strong><?php echo esc_html( $row['rnt_number'] ?: $row['sectur_folio'] ?: '—' ); ?></strong></td>
                        <td><?php echo esc_html( $row['country_code'] ); ?></td>
                        <td><?php echo $row['sworn_declaration_signed'] ? '<span style="color:#28a745">✓ Firmada</span>' : '<span style="color:#dc3545">✗ Pendiente</span>'; ?></td>
                        <td><?php echo esc_html( $row['created_at'] ); ?></td>
                        <td>
                            <button class="button button-primary button-small ltms-approve-rnt"
                                data-vendor="<?php echo (int) $row['vendor_id']; ?>" data-approved="1"
                                data-nonce="<?php echo esc_attr( wp_create_nonce( 'ltms_admin_verify_rnt' ) ); ?>">
                                ✓ <?php esc_html_e( 'Aprobar', 'ltms' ); ?>
                            </button>
                            <button class="button button-small ltms-approve-rnt" style="color:#dc3545;"
                                data-vendor="<?php echo (int) $row['vendor_id']; ?>" data-approved="0"
                                data-nonce="<?php echo esc_attr( wp_create_nonce( 'ltms_admin_verify_rnt' ) ); ?>">
                                ✗ <?php esc_html_e( 'Rechazar', 'ltms' ); ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ( ! empty( $expiring ) ) : ?>
        <h2 style="margin-top:32px;"><?php esc_html_e( '⚠️ RNT próximos a vencer (≤ 30 días)', 'ltms' ); ?></h2>
        <table class="wp-list-table widefat striped">
            <thead><tr>
                <th><?php esc_html_e( 'Vendedor', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'N° RNT', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Vence', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Días restantes', 'ltms' ); ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ( $expiring as $row ) :
                    $days = max( 0, (int) floor( ( strtotime( $row['rnt_expiry_date'] ) - time() ) / DAY_IN_SECONDS ) );
                    ?>
                    <tr>
                        <td><?php echo esc_html( $row['display_name'] ?? '#' . $row['vendor_id'] ); ?></td>
                        <td><?php echo esc_html( $row['rnt_number'] ); ?></td>
                        <td><?php echo esc_html( $row['rnt_expiry_date'] ); ?></td>
                        <td><strong style="color:<?php echo $days <= 7 ? '#dc3545' : '#ffc107'; ?>"><?php echo (int) $days; ?> días</strong></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<script>
jQuery(function($){
    $('.ltms-approve-rnt').on('click',function(){
        var approved=$(this).data('approved');
        var msg=approved?'<?php echo esc_js(__('¿Aprobar este RNT?','ltms')); ?>':'<?php echo esc_js(__('¿Rechazar este RNT?','ltms')); ?>';
        if(!confirm(msg))return;
        var notes=approved?'':prompt('<?php echo esc_js(__('Motivo del rechazo (opcional):','ltms')); ?>')||'';
        $.post(ajaxurl,{action:'ltms_admin_verify_rnt',vendor_id:$(this).data('vendor'),approved:approved,notes:notes,nonce:$(this).data('nonce')},function(r){
            r.success?location.reload():alert(r.data);
        });
    });
});
</script>
