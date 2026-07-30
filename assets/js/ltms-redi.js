/**
 * LTMS view-redi — extracted from inline <script>.
 * AUDIT-PANEL-FN-03 (re-auditoría): migrated to external file for CSP compliance.
 * FASE2B P0 FIX (CSP): completes the migration that left this view + view-incidents
 * + view-settings (invoicing section) as the only 3 with inline scripts.
 *
 * Strings localizadas via wp_localize_script('ltms-redi', 'ltmsRedi', {...}).
 * Si ltmsRedi no está definido (vista cargada sin enqueue), se cae a strings
 * hardcoded en inglés como último recurso.
 */
(function( $ ) {
    'use strict';

    if ( typeof ltmsRedi === 'undefined' ) { return; }

    var i18n = ltmsRedi.strings || {};
    function t( k, fallback ) { return i18n[ k ] || fallback; }

    $( '#ltms-explore-redi-btn' ).on( 'click', function( e ) {
        e.preventDefault();
        var $btn       = $( this );
        var $container = $( '#ltms-redi-products-container' );
        var nonce      = $btn.data( 'nonce' );
        var ajaxUrl    = ( typeof ltmsDashboard !== 'undefined' && ltmsDashboard.ajax_url )
                ? ltmsDashboard.ajax_url
                : ( typeof ajaxurl !== 'undefined' ? ajaxurl : '' );

        if ( ! ajaxUrl ) {
            $container.html( '<p>' + t( 'no_ajax_url', 'Error: no se pudo determinar la URL de AJAX.' ) + '</p>' );
            return;
        }

        $btn.prop( 'disabled', true ).text( t( 'loading', 'Cargando...' ) );
        $container.html( '<p>' + t( 'searching', 'Buscando productos disponibles...' ) + '</p>' );

        $.ajax( {
            url:    ajaxUrl,
            method: 'POST',
            data: {
                action: 'ltms_get_redi_data',
                nonce:  nonce
            },
            success: function( res ) {
                $btn.prop( 'disabled', false ).text( t( 'explore_btn', 'Explorar Productos ReDi' ) );

                if ( res.success && res.data && res.data.products && res.data.products.length ) {
                    var products = res.data.products;
                    var html     = '<div class="ltms-redi-products-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;margin-top:12px;">';

                    $.each( products, function( i, product ) {
                        var productName = $( '<div>' ).text( product.name || '' ).html();
                        var vendorName  = $( '<div>' ).text( product.vendor_name || '' ).html();
                        var rediRate    = parseFloat( product.redi_rate || 0 ).toFixed(2);
                        // AUDIT-PANEL-SEC-03 fix: validar productUrl antes de insertar en href.
                        var rawUrl      = product.url ? String( product.url ) : '#';
                        var productUrl  = ( rawUrl === '#' || /^https:\/\//i.test( rawUrl ) ) ? rawUrl : '#';
                        html += '<div class="ltms-redi-product-card" style="border:1px solid #ddd;border-radius:6px;padding:12px;">';
                        html += '<strong><a href="' + encodeURI( productUrl ) + '" target="_blank" rel="noopener noreferrer">' + productName + '</a></strong>';
                        html += '<p style="margin:4px 0 0;">' + t( 'vendor_label', 'Vendedor:' ) + ' ' + vendorName + '</p>';
                        html += '<p style="margin:4px 0 0;">' + t( 'redi_rate_label', 'Tasa ReDi:' ) + ' ' + rediRate + '%</p>';
                        html += '</div>';
                    } );

                    html += '</div>';
                    $container.html( html );
                } else {
                    $container.html( '<p>' + t( 'no_products', 'No se encontraron productos ReDi disponibles en este momento.' ) + '</p>' );
                }
            },
            error: function() {
                $btn.prop( 'disabled', false ).text( t( 'explore_btn', 'Explorar Productos ReDi' ) );
                $container.html( '<p>' + t( 'conn_error', 'Error de conexión. Intente nuevamente.' ) + '</p>' );
            }
        } );
    } );

    // AUDIT-REDI-UX-GAPS GAP-3 FIX: soft pause/resume handlers.
    // v2.9.61 DEEP-AUDIT-002 P0-1 FIX: El PHP espera 'origin_product_id' (no 'product_id').
    // v2.9.99 P1 FIX: eliminado native confirm() — el botón es explícito.
    // FIX-P1-BATCH-A: surgical DOM updates on the affected row (preserves SPA state).
    function toggleRediRow( $btn, nowPaused ) {
        var $row   = $btn.closest( 'tr[data-product-id]' );
        if ( ! $row.length ) return;
        var $status = $row.children( 'td' ).eq( 6 ); // 7th column = Estado
        if ( nowPaused ) {
            $status.html( '<span style="color:#F0B500;font-weight:600;">⏸️ ' +
                t( 'paused', 'Pausado' ) + '</span>' );
            $btn.removeClass( 'ltms-btn-secondary ltms-redi-pause-btn' )
                .addClass( 'ltms-btn-primary ltms-redi-resume-btn' )
                .text( '▶️ ' + t( 'resume', 'Reanudar' ) );
        } else {
            $status.html( '<span style="color:#27ae60;font-weight:600;">✓ ' +
                t( 'active', 'Activo' ) + '</span>' );
            $btn.removeClass( 'ltms-btn-primary ltms-redi-resume-btn' )
                .addClass( 'ltms-btn-secondary ltms-redi-pause-btn' )
                .text( '⏸️ ' + t( 'pause', 'Pausar' ) );
        }
    }

    $(document).on('click', '.ltms-redi-pause-btn', function(e){
        e.preventDefault();
        var $btn = $(this);
        var productId = $btn.data('product-id');
        if (!productId) return;
        $btn.prop('disabled', true);
        $.post(ltmsDashboard.ajax_url, {
            action: 'ltms_redi_soft_pause',
            nonce: (typeof ltmsDashboard !== 'undefined') ? ltmsDashboard.nonce : '',
            origin_product_id: productId
        }, function(resp){
            if (resp && resp.success) {
                LTMS.UX.toastSuccess(t('success','Éxito'), t('paused_msg', 'ReDi pausado. Los revendedores han sido notificados.'));
                toggleRediRow( $btn, true );
                $btn.prop('disabled', false);
            } else {
                $btn.prop('disabled', false);
                LTMS.UX.toastError(t('error','Error'), resp && resp.data ? resp.data.message : t('pause_err', 'Error al pausar.'));
            }
        }, 'json').fail(function(){
            $btn.prop('disabled', false);
            LTMS.UX.toastError(t('error','Error'), t('conn_error', 'Error de conexión.'));
        });
    });

    $(document).on('click', '.ltms-redi-resume-btn', function(e){
        e.preventDefault();
        var $btn = $(this);
        var productId = $btn.data('product-id');
        if (!productId) return;
        $btn.prop('disabled', true);
        $.post(ltmsDashboard.ajax_url, {
            action: 'ltms_redi_soft_resume',
            nonce: (typeof ltmsDashboard !== 'undefined') ? ltmsDashboard.nonce : '',
            origin_product_id: productId
        }, function(resp){
            if (resp && resp.success) {
                LTMS.UX.toastSuccess(t('success','Éxito'), t('resumed_msg', 'ReDi reanudado. Los revendedores han sido notificados.'));
                toggleRediRow( $btn, false );
                $btn.prop('disabled', false);
            } else {
                $btn.prop('disabled', false);
                LTMS.UX.toastError(t('error','Error'), resp && resp.data ? resp.data.message : t('resume_err', 'Error al reanudar.'));
            }
        }, 'json').fail(function(){
            $btn.prop('disabled', false);
            LTMS.UX.toastError(t('error','Error'), t('conn_error', 'Error de conexión.'));
        });
    });
}( jQuery ) );
