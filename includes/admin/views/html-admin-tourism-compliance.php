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
                <th><?php esc_html_e( 'Vence RNT', 'ltms' ); ?></th>
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
                <td style="white-space:nowrap;font-size:12px;">
                    <?php if ( ! empty( $row['rnt_expiry_date'] ) ) : ?>
                        <?php echo esc_html( $row['rnt_expiry_date'] ); ?>
                    <?php else : ?>
                        <span style="color:#9ca3af;">—</span>
                    <?php endif; ?>
                </td>
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

<div id="ltms-reject-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:8px;padding:28px;width:440px;max-width:90vw;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <h3 style="margin:0 0 12px;font-size:16px;">✗ <?php esc_html_e( 'Rechazar solicitud RNT', 'ltms' ); ?></h3>
        <p style="color:#6b7280;font-size:13px;margin-bottom:16px;"><?php esc_html_e( 'Indica el motivo del rechazo. El vendedor verá este mensaje en su panel.', 'ltms' ); ?></p>
        <textarea id="ltms-reject-notes" rows="4" style="width:100%;border:1px solid #d1d5db;border-radius:6px;padding:8px;font-size:13px;resize:vertical;" placeholder="<?php esc_attr_e( 'Ej: El número RNT no pudo ser verificado en el registro FONTUR…', 'ltms' ); ?>"></textarea>
        <div style="display:flex;gap:10px;margin-top:16px;justify-content:flex-end;">
            <button id="ltms-reject-cancel" class="button"><?php esc_html_e( 'Cancelar', 'ltms' ); ?></button>
            <button id="ltms-reject-confirm" class="button button-primary" style="background:#dc2626;border-color:#dc2626;"><?php esc_html_e( 'Confirmar rechazo', 'ltms' ); ?></button>
        </div>
    </div>
</div>

<div id="ltms-approve-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:8px;padding:28px;width:380px;max-width:90vw;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <h3 style="margin:0 0 12px;font-size:16px;">✓ <?php esc_html_e( 'Aprobar RNT', 'ltms' ); ?></h3>
        <p style="color:#6b7280;font-size:13px;margin-bottom:20px;"><?php esc_html_e( 'El vendedor quedará verificado y podrá publicar alojamientos.', 'ltms' ); ?></p>
        <div style="display:flex;gap:10px;justify-content:flex-end;">
            <button id="ltms-approve-cancel" class="button"><?php esc_html_e( 'Cancelar', 'ltms' ); ?></button>
            <button id="ltms-approve-confirm" class="button button-primary" style="background:#16a34a;border-color:#16a34a;"><?php esc_html_e( 'Confirmar aprobación', 'ltms' ); ?></button>
        </div>
    </div>
</div>

<script type="text/javascript">
/* global jQuery, ajaxurl */
jQuery( function( $ ) {
    var pendingVendor = 0, pendingNonce = '';

    $( '.ltms-approve-rnt' ).on( 'click', function() {
        pendingVendor = $( this ).data( 'vendor' );
        pendingNonce  = $( this ).data( 'nonce' );
        var approved  = parseInt( $( this ).data( 'approved' ), 10 );
        if ( approved ) {
            $( '#ltms-approve-modal' ).css( 'display', 'flex' );
        } else {
            $( '#ltms-reject-notes' ).val( '' );
            $( '#ltms-reject-modal' ).css( 'display', 'flex' );
        }
    } );

    function doVerify( approved, notes ) {
        $.post( ajaxurl, {
            action:    'ltms_admin_verify_rnt',
            vendor_id: pendingVendor,
            approved:  approved,
            notes:     notes,
            nonce:     pendingNonce
        }, function( r ) {
            r.success ? location.reload() : alert( r.data );
        } );
    }

    $( '#ltms-approve-confirm' ).on( 'click', function() { doVerify( 1, '' ); } );
    $( '#ltms-approve-cancel'  ).on( 'click', function() { $( '#ltms-approve-modal' ).hide(); } );
    $( '#ltms-reject-confirm'  ).on( 'click', function() { doVerify( 0, $( '#ltms-reject-notes' ).val() ); } );
    $( '#ltms-reject-cancel'   ).on( 'click', function() { $( '#ltms-reject-modal' ).hide(); } );
} );
</script>
