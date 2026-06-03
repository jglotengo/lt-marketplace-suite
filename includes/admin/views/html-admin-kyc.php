<?php
/**
 * Vista: Admin KYC — Verificacion de Identidad de Vendedores
 *
 * @package LTMS
 * @version 2.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;
$table  = $wpdb->prefix . 'lt_vendor_kyc';
$status = sanitize_key( $_GET['status'] ?? 'pending' ); // phpcs:ignore
$nonce  = wp_create_nonce( 'ltms_admin_nonce' );

// Contadores
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$counts   = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM `{$table}` GROUP BY status", ARRAY_A );
$count_map = [ 'pending' => 0, 'approved' => 0, 'rejected' => 0 ];
foreach ( $counts as $row ) { $count_map[ $row['status'] ] = (int) $row['total']; }

$kyc_records = $wpdb->get_results(
    $wpdb->prepare( "SELECT k.*, u.display_name, u.user_email FROM `{$table}` k LEFT JOIN `{$wpdb->users}` u ON u.ID = k.vendor_id WHERE k.status = %s ORDER BY k.submitted_at DESC LIMIT 100", $status ),
    ARRAY_A
);
// phpcs:enable

$total_kyc = array_sum( $count_map );
?>
<div class="wrap ltms-admin-wrap">

    <div class="ltms-header">
        <h1>&#x1FAA6; <?php esc_html_e( 'Verificacion KYC de Vendedores', 'ltms' ); ?></h1>
        <span style="font-size:12px;color:#6b7280;margin-left:auto;">Vault Log activo &middot; Ley 1581/2012</span>
    </div>

    <!-- Stats -->
    <div class="ltms-stats-grid" style="margin-bottom:20px;">
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Total KYC', 'ltms' ); ?></span>
            <span class="ltms-stat-value"><?php echo esc_html( number_format( $total_kyc ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Pendientes', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="color:#f59e0b;"><?php echo esc_html( number_format( $count_map['pending'] ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Aprobados', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="color:#16a34a;"><?php echo esc_html( number_format( $count_map['approved'] ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Rechazados', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="color:#dc2626;"><?php echo esc_html( number_format( $count_map['rejected'] ) ); ?></span>
        </div>
    </div>

    <!-- Tabs LTMS -->
    <div style="display:flex;gap:0;border-bottom:2px solid #e5e7eb;margin-bottom:20px;">
        <?php
        $tabs = [
            'pending'  => 'Pendientes',
            'approved' => 'Aprobados',
            'rejected' => 'Rechazados',
        ];
        foreach ( $tabs as $s => $label ) :
            $active = $status === $s;
        ?>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ltms-kyc&status=' . $s ) ); ?>"
           style="padding:10px 20px;text-decoration:none;font-weight:600;border-bottom:2px solid <?php echo $active ? '#2563eb' : 'transparent'; ?>;margin-bottom:-2px;color:<?php echo $active ? '#2563eb' : '#6b7280'; ?>;">
            <?php echo esc_html( $label ); ?>
            <span class="ltms-badge <?php echo $s === 'pending' ? 'ltms-badge-warning' : ( $s === 'approved' ? 'ltms-badge-success' : 'ltms-badge-danger' ); ?>"
                  style="margin-left:4px;">
                <?php echo esc_html( $count_map[ $s ] ); ?>
            </span>
        </a>
        <?php endforeach; ?>
    </div>

    <div class="ltms-table-wrap">
    <?php if ( empty( $kyc_records ) ) : ?>
        <div style="text-align:center;padding:48px;color:#9ca3af;">
            <div style="font-size:48px;margin-bottom:12px;">&#x1F4ED;</div>
            <p style="margin:0;"><?php esc_html_e( 'No hay registros en este estado.', 'ltms' ); ?></p>
        </div>
    <?php else : ?>
        <?php
        // Helper: genera URL pre-firmada (1 h) para una key de B2, o devuelve la URL tal cual si ya es http.
        // Los docs KYC están en bucket privado lotengo-kyc-docs; nunca exponer URL directa.
        $kyc_bucket   = LTMS_Core_Config::get( 'ltms_backblaze_kyc_bucket',
                            LTMS_Core_Config::get( 'ltms_backblaze_bucket_name', 'lotengo-kyc-docs' ) );
        $b2_client    = null;
        $b2_available = LTMS_Core_Config::get( 'ltms_backblaze_enabled', 'no' ) === 'yes';
        if ( $b2_available ) {
            try { $b2_client = new LTMS_Api_Backblaze(); } catch ( \Throwable $e ) { $b2_client = null; }
        }
        $make_signed_url = static function( string $key ) use ( $b2_client, $kyc_bucket ): string {
            if ( empty( $key ) ) return '';
            // Si ya es una URL completa (http/https) devolverla tal cual
            if ( str_starts_with( $key, 'http' ) ) return $key;
            // Generar URL pre-firmada — TTL 3600 s (1 hora)
            if ( $b2_client ) {
                try { return $b2_client->get_signed_url( $kyc_bucket, $key, 3600 ); } catch ( \Throwable $e ) {}
            }
            // Fallback: construir URL con endpoint público (solo si bucket fuera público)
            $endpoint = rtrim( LTMS_Core_Config::get( 'ltms_backblaze_endpoint', '' ), '/' );
            return $endpoint ? $endpoint . '/' . $kyc_bucket . '/' . ltrim( $key, '/' ) : '#';
        };
        ?>
        <table class="ltms-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Vendedor', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Doc.', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Certificación Bancaria', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Enviado', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Acciones', 'ltms' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $kyc_records as $kyc ) :
                $doc_type = strtoupper( $kyc['document_type'] ?? '—' );
                $submitted = $kyc['submitted_at'] ? gmdate( 'd/m/Y H:i', strtotime( $kyc['submitted_at'] ) ) : '—';
                $status_badges = [
                    'pending'  => 'ltms-badge-warning',
                    'approved' => 'ltms-badge-success',
                    'rejected' => 'ltms-badge-danger',
                ];
                $status_labels = [
                    'pending'  => 'Pendiente',
                    'approved' => 'Aprobado',
                    'rejected' => 'Rechazado',
                ];
                $badge_class  = $status_badges[ $kyc['status'] ] ?? 'ltms-badge-pending';
                $status_label = $status_labels[ $kyc['status'] ] ?? strtoupper( $kyc['status'] );

                // Generar URLs pre-firmadas para documentos del modal (B2 privado)
                $docs = [];
                foreach ( [ 'doc_front_url', 'doc_back_url', 'selfie_url', 'extra_doc_url', 'file_path' ] as $field ) {
                    if ( ! empty( $kyc[ $field ] ) ) {
                        $signed = $make_signed_url( $kyc[ $field ] );
                        if ( $signed ) $docs[] = $signed;
                    }
                }
            ?>
            <tr id="ltms-kyc-row-<?php echo esc_attr( $kyc['id'] ); ?>">
                <td>
                    <strong><?php echo esc_html( $kyc['display_name'] ?? '—' ); ?></strong><br>
                    <small style="color:#6b7280;"><?php echo esc_html( $kyc['user_email'] ?? '' ); ?></small><br>
                    <small style="color:#9ca3af;">ID <?php echo esc_html( $kyc['vendor_id'] ); ?> &middot; KYC #<?php echo esc_html( $kyc['id'] ); ?></small>
                </td>
                <td><code><?php echo esc_html( $doc_type ); ?></code></td>
                <td style="font-size:11px;">
                    <?php
                    $banco_key   = get_user_meta( (int) $kyc['vendor_id'], 'ltms_kyc_file_banco', true );
                    $rep_legal   = get_user_meta( (int) $kyc['vendor_id'], 'ltms_kyc_bank_rep_legal', true );
                    $bank_name_m = get_user_meta( (int) $kyc['vendor_id'], 'ltms_kyc_bank_name', true );
                    $acct_num    = get_user_meta( (int) $kyc['vendor_id'], 'ltms_kyc_bank_account', true );
                    $banco_url   = $banco_key ? $make_signed_url( $banco_key ) : '';
                    if ( $banco_url ) : ?>
                        <span style="color:#16a34a;font-weight:600;">✓</span>
                        <a href="<?php echo esc_url( $banco_url ); ?>" target="_blank"
                           style="font-size:11px;color:#2563eb;display:block;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                           <?php esc_html_e( 'Ver certificado', 'ltms' ); ?>
                        </a>
                        <?php if ( $rep_legal ) : ?>
                            <small style="color:#374151;display:block;"><strong><?php esc_html_e( 'Rep. Legal:', 'ltms' ); ?></strong> <?php echo esc_html( $rep_legal ); ?></small>
                        <?php endif; ?>
                        <?php if ( $bank_name_m ) : ?>
                            <small style="color:#6b7280;display:block;"><?php echo esc_html( $bank_name_m ); ?><?php echo $acct_num ? ' · ****' . esc_html( substr( $acct_num, -4 ) ) : ''; ?></small>
                        <?php endif; ?>
                    <?php else : ?>
                        <span style="color:#dc2626;font-weight:600;">⚠ <?php esc_html_e( 'Pendiente', 'ltms' ); ?></span>
                    <?php endif; ?>
                </td>
                <td style="white-space:nowrap;font-size:12px;"><?php echo esc_html( $submitted ); ?></td>
                <td><span class="ltms-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $status_label ); ?></span></td>
                <td style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                    <?php if ( ! empty( $docs ) ) : ?>
                    <button class="ltms-btn ltms-btn-outline ltms-btn-sm ltms-kyc-view-docs"
                            data-docs="<?php echo esc_attr( json_encode( $docs ) ); ?>">
                        &#x1F50D; <?php esc_html_e( 'Ver docs', 'ltms' ); ?>
                    </button>
                    <?php endif; ?>
                    <?php if ( $kyc['status'] !== 'approved' ) : ?>
                    <button class="ltms-btn ltms-btn-success ltms-btn-sm ltms-kyc-approve"
                            data-id="<?php echo esc_attr( $kyc['id'] ); ?>"
                            data-nonce="<?php echo esc_attr( $nonce ); ?>">
                        &#x2714; <?php esc_html_e( 'Aprobar', 'ltms' ); ?>
                    </button>
                    <?php endif; ?>
                    <?php if ( $kyc['status'] !== 'rejected' ) : ?>
                    <button class="ltms-btn ltms-btn-danger ltms-btn-sm ltms-kyc-reject"
                            data-id="<?php echo esc_attr( $kyc['id'] ); ?>"
                            data-nonce="<?php echo esc_attr( $nonce ); ?>">
                        &#x2715; <?php esc_html_e( 'Rechazar', 'ltms' ); ?>
                    </button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    </div>

</div>

<!-- Modal docs -->
<div id="ltms-kyc-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:99999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;padding:24px;max-width:700px;width:90%;max-height:85vh;overflow-y:auto;position:relative;">
        <button id="ltms-kyc-modal-close" style="position:absolute;top:12px;right:12px;background:none;border:none;font-size:20px;cursor:pointer;color:#6b7280;">&times;</button>
        <h3 style="margin:0 0 16px;"><?php esc_html_e( 'Documentos KYC', 'ltms' ); ?></h3>
        <div id="ltms-kyc-modal-content" style="display:flex;flex-wrap:wrap;gap:12px;"></div>
    </div>
</div>

<script type="text/javascript">
/* global jQuery */
(function( $ ) {
    'use strict';

    // Modal docs
    $( document ).on( 'click', '.ltms-kyc-view-docs', function() {
        var docs = JSON.parse( $( this ).data( 'docs' ) || '[]' );
        var html = '';
        $.each( docs, function( i, url ) {
            html += '<div style="flex:1;min-width:200px;">' +
                '<a href="' + url + '" target="_blank">' +
                '<img src="' + url + '" style="width:100%;border-radius:6px;border:1px solid #e5e7eb;" ' +
                'onerror="this.style.display=\'none\';this.nextSibling.style.display=\'block\';">' +
                '<span style="display:none;padding:8px;background:#f3f4f6;border-radius:4px;font-size:12px;">&#x1F4C4; ' + url.split( '/' ).pop() + '</span>' +
                '</a></div>';
        } );
        $( '#ltms-kyc-modal-content' ).html( html );
        $( '#ltms-kyc-modal' ).css( 'display', 'flex' );
    } );
    $( '#ltms-kyc-modal-close, #ltms-kyc-modal' ).on( 'click', function( e ) {
        if ( e.target === this ) $( '#ltms-kyc-modal' ).hide();
    } );

    // Aprobar
    $( document ).on( 'click', '.ltms-kyc-approve', function() {
        if ( ! confirm( '<?php echo esc_js( __( "Aprobar este KYC?", "ltms" ) ); ?>' ) ) return;
        var $btn = $( this ).prop( 'disabled', true );
        $.post( ajaxurl, {
            action: 'ltms_approve_kyc',
            kyc_id: $btn.data( 'id' ),
            nonce:  $btn.data( 'nonce' )
        }, function( res ) {
            if ( res.success ) { window.location.reload(); }
            else { alert( res.data || '<?php echo esc_js( __( "Error.", "ltms" ) ); ?>' ); $btn.prop( 'disabled', false ); }
        } ).fail( function() { $btn.prop( 'disabled', false ); } );
    } );

    // Rechazar
    $( document ).on( 'click', '.ltms-kyc-reject', function() {
        var reason = prompt( '<?php echo esc_js( __( "Motivo del rechazo (requerido):", "ltms" ) ); ?>' );
        if ( ! reason ) return;
        var $btn = $( this ).prop( 'disabled', true );
        $.post( ajaxurl, {
            action: 'ltms_reject_kyc',
            kyc_id: $btn.data( 'id' ),
            reason: reason,
            nonce:  $btn.data( 'nonce' )
        }, function( res ) {
            if ( res.success ) { window.location.reload(); }
            else { alert( res.data || '<?php echo esc_js( __( "Error.", "ltms" ) ); ?>' ); $btn.prop( 'disabled', false ); }
        } ).fail( function() { $btn.prop( 'disabled', false ); } );
    } );

}( jQuery ) );
</script>
