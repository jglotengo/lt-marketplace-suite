<?php
/**
 * Vista admin: Compliance Turístico — RNT / SECTUR
 *
 * @package LTMS
 * @version 2.0.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;
$prefix = $wpdb->prefix;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$pending  = $wpdb->get_results( "SELECT tc.*, u.display_name FROM {$prefix}lt_tourism_compliance tc LEFT JOIN {$wpdb->users} u ON u.ID = tc.vendor_id WHERE tc.status = 'pending' ORDER BY tc.created_at DESC", ARRAY_A ) ?: [];
$expiring = $wpdb->get_results( "SELECT tc.*, u.display_name FROM {$prefix}lt_tourism_compliance tc LEFT JOIN {$wpdb->users} u ON u.ID = tc.vendor_id WHERE tc.rnt_expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND tc.rnt_verified = 1 ORDER BY tc.rnt_expiry_date ASC", ARRAY_A ) ?: [];
$summary  = $wpdb->get_row( "SELECT COUNT(*) as total, SUM(rnt_verified=1) as verified, SUM(status='pending') as pending_count, SUM(status='expired') as expired FROM {$prefix}lt_tourism_compliance", ARRAY_A );
// phpcs:enable
?>
<div class="wrap ltms-admin-wrap">

    <div class="ltms-header">
        <h1>&#x1F3D6; <?php esc_html_e( 'Compliance Turístico — RNT / SECTUR', 'ltms' ); ?></h1>
    </div>

    <!-- Stats -->
    <div class="ltms-stats-grid" style="margin-bottom:24px;">
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Total vendedores', 'ltms' ); ?></span>
            <span class="ltms-stat-value"><?php echo esc_html( number_format( (int) ( $summary['total'] ?? 0 ) ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Verificados', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="color:#16a34a;"><?php echo esc_html( number_format( (int) ( $summary['verified'] ?? 0 ) ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Pendientes', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="color:#f59e0b;"><?php echo esc_html( number_format( (int) ( $summary['pending_count'] ?? 0 ) ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Vencidos', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="color:#dc2626;"><?php echo esc_html( number_format( (int) ( $summary['expired'] ?? 0 ) ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Por vencer (≤30 días)', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="color:#d97706;"><?php echo esc_html( number_format( count( $expiring ) ) ); ?></span>
        </div>
    </div>

    <!-- Sección: Pendientes de verificación -->
    <div class="ltms-table-wrap" style="margin-bottom:32px;">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid #e5e7eb;">
            <h2 style="margin:0;font-size:15px;font-weight:700;">
                &#x23F3; <?php esc_html_e( 'Pendientes de verificación', 'ltms' ); ?>
                <?php if ( ! empty( $pending ) ) : ?>
                <span class="ltms-badge ltms-badge-warning" style="margin-left:8px;font-size:12px;"><?php echo count( $pending ); ?></span>
                <?php endif; ?>
            </h2>
        </div>

        <?php if ( empty( $pending ) ) : ?>
        <div style="text-align:center;padding:40px;color:#888;">
            <div style="font-size:32px;margin-bottom:8px;">&#x2705;</div>
            <?php esc_html_e( 'Sin solicitudes pendientes.', 'ltms' ); ?>
        </div>
        <?php else : ?>
        <table class="ltms-table">
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
                <td><strong><?php echo esc_html( $row['display_name'] ?? '#' . $row['vendor_id'] ); ?></strong></td>
                <td>
                    <?php $rnt = $row['rnt_number'] ?: $row['sectur_folio'] ?: ''; ?>
                    <?php if ( $rnt ) : ?>
                        <code style="background:#f3f4f6;padding:2px 6px;border-radius:3px;font-size:12px;"><?php echo esc_html( $rnt ); ?></code>
                    <?php else : ?>
                        <span style="color:#9ca3af;">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="ltms-badge <?php echo $row['country_code'] === 'CO' ? 'ltms-badge-warning' : 'ltms-badge-success'; ?>">
                        <?php echo esc_html( $row['country_code'] ); ?>
                    </span>
                </td>
                <td>
                    <?php if ( $row['sworn_declaration_signed'] ) : ?>
                        <span class="ltms-badge ltms-badge-success">&#x2713; <?php esc_html_e( 'Firmada', 'ltms' ); ?></span>
                    <?php else : ?>
                        <span class="ltms-badge ltms-badge-danger">&#x2717; <?php esc_html_e( 'Pendiente', 'ltms' ); ?></span>
                    <?php endif; ?>
                </td>
                <td style="white-space:nowrap;font-size:12px;color:#6b7280;"><?php echo esc_html( $row['created_at'] ?? '—' ); ?></td>
                <td style="display:flex;gap:6px;">
                    <button class="ltms-btn ltms-btn-success ltms-btn-sm ltms-approve-rnt"
                            data-vendor="<?php echo esc_attr( $row['vendor_id'] ); ?>"
                            data-approved="1"
                            data-nonce="<?php echo esc_attr( wp_create_nonce( 'ltms_admin_verify_rnt' ) ); ?>">
                        &#x2713; <?php esc_html_e( 'Aprobar', 'ltms' ); ?>
                    </button>
                    <button class="ltms-btn ltms-btn-danger ltms-btn-sm ltms-approve-rnt"
                            data-vendor="<?php echo esc_attr( $row['vendor_id'] ); ?>"
                            data-approved="0"
                            data-nonce="<?php echo esc_attr( wp_create_nonce( 'ltms_admin_verify_rnt' ) ); ?>">
                        &#x2717; <?php esc_html_e( 'Rechazar', 'ltms' ); ?>
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- Sección: RNT próximos a vencer -->
    <?php if ( ! empty( $expiring ) ) : ?>
    <div class="ltms-table-wrap">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid #e5e7eb;">
            <h2 style="margin:0;font-size:15px;font-weight:700;">
                &#x26A0;&#xFE0F; <?php esc_html_e( 'RNT próximos a vencer (≤ 30 días)', 'ltms' ); ?>
                <span class="ltms-badge ltms-badge-warning" style="margin-left:8px;font-size:12px;"><?php echo count( $expiring ); ?></span>
            </h2>
        </div>
        <table class="ltms-table">
            <thead><tr>
                <th><?php esc_html_e( 'Vendedor', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'N° RNT', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'País', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Vence', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Días restantes', 'ltms' ); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $expiring as $row ) :
                $days = max( 0, (int) floor( ( strtotime( $row['rnt_expiry_date'] ) - time() ) / DAY_IN_SECONDS ) );
                $days_class = $days <= 7 ? 'ltms-badge-danger' : 'ltms-badge-warning';
            ?>
            <tr>
                <td><strong><?php echo esc_html( $row['display_name'] ?? '#' . $row['vendor_id'] ); ?></strong></td>
                <td><code style="background:#f3f4f6;padding:2px 6px;border-radius:3px;font-size:12px;"><?php echo esc_html( $row['rnt_number'] ); ?></code></td>
                <td>
                    <span class="ltms-badge <?php echo $row['country_code'] === 'CO' ? 'ltms-badge-warning' : 'ltms-badge-success'; ?>">
                        <?php echo esc_html( $row['country_code'] ); ?>
                    </span>
                </td>
                <td style="white-space:nowrap;font-size:12px;"><?php echo esc_html( $row['rnt_expiry_date'] ); ?></td>
                <td><span class="ltms-badge <?php echo esc_attr( $days_class ); ?>"><?php echo esc_html( $days ); ?> <?php esc_html_e( 'días', 'ltms' ); ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

</div>

<script type="text/javascript">
/* global jQuery */
jQuery( function( $ ) {
    $( '.ltms-approve-rnt' ).on( 'click', function() {
        var approved = $( this ).data( 'approved' );
        var msg = approved
            ? '<?php echo esc_js( __( "¿Aprobar este RNT?", "ltms" ) ); ?>'
            : '<?php echo esc_js( __( "¿Rechazar este RNT?", "ltms" ) ); ?>';
        if ( ! confirm( msg ) ) return;
        var notes = approved ? '' : ( prompt( '<?php echo esc_js( __( "Motivo del rechazo (opcional):", "ltms" ) ); ?>' ) || '' );
        var $btn = $( this ).prop( 'disabled', true );
        $.post( ajaxurl, {
            action:    'ltms_admin_verify_rnt',
            vendor_id: $btn.data( 'vendor' ),
            approved:  approved,
            notes:     notes,
            nonce:     $btn.data( 'nonce' )
        }, function( r ) {
            r.success ? location.reload() : alert( r.data );
        } );
    } );
} );
</script>
