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
            // Convertir URLs legacy ltms-vault a key B2 relativa
            if ( str_starts_with( $key, 'http' ) ) {
                if ( str_contains( $key, '/ltms-vault/' ) ) {
                    $key = preg_replace( '#^.*/ltms-vault/#', '', $key );
                } else {
                    return $key; // URL externa real
                }
            }
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
                // 1. Campos directos de la tabla KYC
                foreach ( [ 'doc_front_url', 'doc_back_url', 'selfie_url', 'extra_doc_url', 'file_path', 'rut_path', 'camara_path', 'selfie_path' ] as $field ) {
                    if ( ! empty( $kyc[ $field ] ) ) {
                        $signed = $make_signed_url( $kyc[ $field ] );
                        if ( $signed ) $docs[] = $signed;
                    }
                }
                // 2. Documentos adicionales desde user_meta (RUT, cámara, banco, selfie)
                $vid = (int) $kyc['vendor_id'];
                foreach ( [ 'ltms_kyc_file_cedula', 'ltms_kyc_doc_path', 'ltms_kyc_file_rut', 'ltms_kyc_file_camara', 'ltms_kyc_selfie_url', 'ltms_kyc_file_banco' ] as $meta_key ) {
                    $val = get_user_meta( $vid, $meta_key, true );
                    if ( ! empty( $val ) ) {
                        $signed = $make_signed_url( $val );
                        if ( $signed && ! in_array( $signed, $docs, true ) ) $docs[] = $signed;
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
                            data-kyc-id="<?php echo esc_attr( $kyc['id'] ); ?>"
                            data-nonce="<?php echo esc_attr( $nonce ); ?>">
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
        <button id="ltms-kyc-modal-close" style="position:absolute;top:12px;right:12px;background:none;border:none;font-size:20px;cursor:pointer;color:#6b7280;" aria-label="<?php esc_attr_e( 'Cerrar', 'ltms' ); ?>">&times;</button>
        <h3 style="margin:0 0 16px;"><?php esc_html_e( 'Documentos KYC', 'ltms' ); ?></h3>
        <div id="ltms-kyc-modal-content" style="display:flex;flex-wrap:wrap;gap:12px;"></div>
    </div>
</div>

<!-- v2.9.114 KYC-AUDIT P2-4: modern confirm modal (replaces native confirm) -->
<div id="ltms-kyc-confirm-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:100000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;padding:24px;max-width:420px;width:90%;">
        <h3 style="margin:0 0 12px;"><?php esc_html_e( 'Confirmar aprobación', 'ltms' ); ?></h3>
        <p class="ltms-confirm-message" style="color:#374151;margin-bottom:20px;"></p>
        <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button type="button" class="ltms-confirm-no ltms-btn ltms-btn-outline"><?php esc_html_e( 'Cancelar', 'ltms' ); ?></button>
            <button type="button" class="ltms-confirm-yes ltms-btn ltms-btn-success"><?php esc_html_e( 'Aprobar', 'ltms' ); ?></button>
        </div>
    </div>
</div>

<!-- v2.9.114 P2-4: modern reject modal (replaces native prompt) -->
<div id="ltms-kyc-reject-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:100000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;padding:24px;max-width:500px;width:90%;">
        <h3 style="margin:0 0 12px;"><?php esc_html_e( 'Motivo del rechazo', 'ltms' ); ?></h3>
        <p style="color:#6b7280;font-size:.85rem;margin:0 0 8px;"><?php esc_html_e( 'El motivo será notificado al vendedor por correo.', 'ltms' ); ?></p>
        <textarea rows="4" style="width:100%;border:1px solid #d1d5db;border-radius:6px;padding:8px;font-size:13px;resize:vertical;" placeholder="<?php esc_attr_e( 'Ej: La cédula no es legible. Sube una foto más clara…', 'ltms' ); ?>"></textarea>
        <p class="ltms-reject-error" style="display:none;color:#dc2626;font-size:.85rem;margin:8px 0 0;"></p>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
            <button type="button" class="ltms-reject-cancel ltms-btn ltms-btn-outline"><?php esc_html_e( 'Cancelar', 'ltms' ); ?></button>
            <button type="button" class="ltms-reject-submit ltms-btn ltms-btn-danger"><?php esc_html_e( 'Rechazar', 'ltms' ); ?></button>
        </div>
    </div>
</div>

<!-- v2.9.114 P2-4: error toast -->
<div id="ltms-kyc-error-message" style="display:none;position:fixed;top:20px;right:20px;background:#dc2626;color:#fff;padding:12px 16px;border-radius:6px;z-index:100001;max-width:380px;box-shadow:0 4px 12px rgba(0,0,0,.15);"></div>

<script type="text/javascript">
/* global jQuery */
(function( $ ) {
    'use strict';

    // Modal docs — carga URLs pre-firmadas via AJAX para evitar links caducados
    function escapeHtml( s ) {
        // v2.9.114 KYC-AUDIT P2-3 FIX: prevent XSS via decoded URL filename.
        return String( s == null ? '' : s )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' )
            .replace( /'/g, '&#39;' );
    }
    function escapeAttr( s ) { return escapeHtml( s ); }

    function ltmsRenderKycDocs( docs ) {
        var $container = $( '#ltms-kyc-modal-content' ).empty();
        if ( ! docs || ! docs.length ) {
            $container.append( $( '<p>' ).css( 'color', '#6b7280' ).text( 'No hay documentos disponibles.' ) );
            return;
        }
        $.each( docs, function( i, url ) {
            if ( ! url || url === '#' ) return;
            var ext = url.split('.').pop().toLowerCase().split('?')[0];
            var isImg = ['jpg','jpeg','png','gif','webp'].indexOf(ext) !== -1;
            var label = decodeURIComponent( url.split('/').pop().split('?')[0] );
            var $wrapper = $( '<div>' ).css({ 'flex': '1', 'min-width': '200px', 'margin-bottom': '8px' });
            var $link = $( '<a>' ).attr({ 'href': url, 'target': '_blank' });
            if ( isImg ) {
                // v2.9.114 P2-2 FIX: replace inline onerror with jQuery .on('error').
                var $img = $( '<img>' ).attr({ 'src': url }).css({
                    'width': '100%', 'border-radius': '6px', 'border': '1px solid #e5e7eb'
                });
                $img.on( 'error', function() {
                    $img.hide();
                    $fallback.show();
                });
                var $fallback = $( '<span>' ).css({
                    'display': 'none', 'padding': '8px', 'background': '#f3f4f6',
                    'border-radius': '4px', 'font-size': '12px'
                }).text( '📄 ' + label );
                $link.append( $img ).append( $fallback );
            } else {
                $link.css({
                    'display': 'inline-block', 'padding': '8px 12px', 'background': '#f3f4f6',
                    'border-radius': '4px', 'font-size': '12px', 'text-decoration': 'none', 'color': '#1d4ed8'
                }).text( '📄 ' + label );
            }
            $wrapper.append( $link );
            $container.append( $wrapper );
        } );
        $( '#ltms-kyc-modal' ).removeAttr( 'style' ).css({ display: 'flex', position: 'fixed', inset: '0', background: 'rgba(0,0,0,.6)', zIndex: '99999', alignItems: 'center', justifyContent: 'center' });
    }

    $( document ).on( 'click', '.ltms-kyc-view-docs', function() {
        var $btn   = $( this ).prop( 'disabled', true );
        var kycId  = $btn.data( 'kyc-id' );
        var nonce  = $btn.data( 'nonce' );

        $( '#ltms-kyc-modal-content' ).html( '<p style="color:#6b7280;padding:24px;text-align:center;">&#x23F3; Cargando documentos...</p>' );
        $( '#ltms-kyc-modal' ).removeAttr( 'style' ).css({ display: 'flex', position: 'fixed', inset: '0', background: 'rgba(0,0,0,.6)', zIndex: '99999', alignItems: 'center', justifyContent: 'center' });

        $.post( ajaxurl, {
            action: 'ltms_get_kyc_details',
            kyc_id: kycId,
            nonce:  nonce
        }, function( res ) {
            $btn.prop( 'disabled', false );
            if ( ! res.success ) {
                $( '#ltms-kyc-modal-content' ).html( '<p style="color:#dc2626;">Error: ' + ( res.data && res.data.message ? res.data.message : 'No se pudieron cargar los documentos.' ) + '</p>' );
                return;
            }
            // Recolectar todas las URLs de documentos del response
            var d    = res.data;
            var docs = [];
            // El handler devuelve res.data.docs como objeto { cedula, rut, camara, selfie, nit, banco }
            if ( d.docs && typeof d.docs === 'object' ) {
                $.each( d.docs, function( key, url ) {
                    if ( url && url !== '#' && url !== '' ) {
                        docs.push( url );
                    }
                } );
            }
            ltmsRenderKycDocs( docs );
        } ).fail( function() {
            $btn.prop( 'disabled', false );
            $( '#ltms-kyc-modal-content' ).html( '<p style="color:#dc2626;">Error de conexión. Intente nuevamente.</p>' );
        } );
    } );
    $( '#ltms-kyc-modal-close, #ltms-kyc-modal' ).on( 'click', function( e ) {
        if ( e.target === this ) $( '#ltms-kyc-modal' ).hide();
    } );
    // v2.9.114 P2-4: ESC closes any open modal.
    $( document ).on( 'keydown', function( e ) {
        if ( e.key === 'Escape' ) {
            $( '#ltms-kyc-modal, #ltms-kyc-confirm-modal, #ltms-kyc-reject-modal' ).hide();
        }
    } );

    // Aprobar
    // v2.9.114 KYC-AUDIT P2-4 FIX: replace native confirm() with modern modal dialog.
    // The native confirm() is blocked by some browsers (notably iOS Safari in some
    // configurations) and violates the UIUX-AUDIT-001 rule of no native dialogs.
    function ltmsConfirmKycApprove( kycId, nonce, $btn ) {
        var $modal = $( '#ltms-kyc-confirm-modal' );
        $modal.find( '.ltms-confirm-message' ).text( '<?php echo esc_js( __( "Aprobar este KYC?", "ltms" ) ); ?>' );
        $modal.find( '.ltms-confirm-yes' ).off( 'click' ).on( 'click', function() {
            $modal.hide();
            $btn.prop( 'disabled', true );
            $.post( ajaxurl, {
                action: 'ltms_approve_kyc',
                kyc_id: kycId,
                nonce:  nonce
            }, function( res ) {
                if ( res.success ) { window.location.reload(); }
                else { ltmsShowKycError( res.data || '<?php echo esc_js( __( "Error.", "ltms" ) ); ?>' ); $btn.prop( 'disabled', false ); }
            } ).fail( function() { $btn.prop( 'disabled', false ); ltmsShowKycError( 'Error de conexión.' ); } );
        } );
        $modal.find( '.ltms-confirm-no' ).off( 'click' ).on( 'click', function() { $modal.hide(); });
        $modal.show();
    }

    function ltmsShowKycError( msg ) {
        var $err = $( '#ltms-kyc-error-message' ).text( msg ).show();
        setTimeout( function() { $err.fadeOut(); }, 4000 );
    }

    $( document ).on( 'click', '.ltms-kyc-approve', function() {
        var $btn = $( this );
        ltmsConfirmKycApprove( $btn.data( 'id' ), $btn.data( 'nonce' ), $btn );
    } );

    // Rechazar
    // v2.9.114 P2-4 FIX: replace native prompt() with modal containing a textarea.
    function ltmsPromptKycReject( kycId, nonce, $btn ) {
        var $modal = $( '#ltms-kyc-reject-modal' );
        $modal.find( 'textarea' ).val( '' );
        $modal.find( '.ltms-reject-submit' ).off( 'click' ).on( 'click', function() {
            var reason = $.trim( $modal.find( 'textarea' ).val() );
            if ( ! reason ) {
                $modal.find( '.ltms-reject-error' ).text( '<?php echo esc_js( __( "El motivo es obligatorio.", "ltms" ) ); ?>' ).show();
                return;
            }
            $modal.hide();
            $btn.prop( 'disabled', true );
            $.post( ajaxurl, {
                action: 'ltms_reject_kyc',
                kyc_id: kycId,
                reason: reason,
                nonce:  nonce
            }, function( res ) {
                if ( res.success ) { window.location.reload(); }
                else { ltmsShowKycError( res.data || '<?php echo esc_js( __( "Error.", "ltms" ) ); ?>' ); $btn.prop( 'disabled', false ); }
            } ).fail( function() { $btn.prop( 'disabled', false ); ltmsShowKycError( 'Error de conexión.' ); } );
        } );
        $modal.find( '.ltms-reject-cancel' ).off( 'click' ).on( 'click', function() { $modal.hide(); });
        $modal.show();
    }

    $( document ).on( 'click', '.ltms-kyc-reject', function() {
        var $btn = $( this );
        ltmsPromptKycReject( $btn.data( 'id' ), $btn.data( 'nonce' ), $btn );
    } );

}( jQuery ) );
</script>
