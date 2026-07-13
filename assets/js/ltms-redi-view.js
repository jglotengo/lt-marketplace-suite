/* global jQuery, ltmsDashboard */
jQuery(function($) {
        'use strict';

        $( '#ltms-explore-redi-btn' ).on( 'click', function( e ) {
                e.preventDefault();
                var $btn       = $( this );
                var $container = $( '#ltms-redi-products-container' );
                var nonce      = $btn.data( 'nonce' );
                var ajaxUrl    = ( typeof ltmsDashboard !== 'undefined' && ltmsDashboard.ajax_url )
                        ? ltmsDashboard.ajax_url
                        : ( typeof ajaxurl !== 'undefined' ? ajaxurl : '' );

                if ( ! ajaxUrl ) {
                        $container.html( '<p>Error: no se pudo determinar la URL de AJAX.</p>' );
                        return;
                }

                $btn.prop( 'disabled', true ).text( 'Cargando...' );
                $container.html( '<p>Buscando productos disponibles...</p>' );

                $.ajax( {
                        url:    ajaxUrl,
                        method: 'POST',
                        data: {
                                action: 'ltms_get_redi_data',
                                nonce:  nonce
                        },
                        success: function( res ) {
                                $btn.prop( 'disabled', false ).text( 'Explorar Productos ReDi' );

                                if ( res.success && res.data && res.data.products && res.data.products.length ) {
                                        var products = res.data.products;
                                        var html     = '<div class="ltms-redi-products-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;margin-top:12px;">';

                                        $.each( products, function( i, product ) {
                                                var productName = $( '<div>' ).text( product.name || '' ).html();
                                                var vendorName  = $( '<div>' ).text( product.vendor_name || '' ).html();
                                                var rediRate    = parseFloat( product.redi_rate || 0 ).toFixed(2);
                                                var productUrl  = product.url ? product.url : '#';
                                                html += '<div class="ltms-redi-product-card" style="border:1px solid #ddd;border-radius:6px;padding:12px;">';
                                                html += '<strong><a href="' + productUrl + '" target="_blank" rel="noopener noreferrer">' + productName + '</a></strong>';
                                                html += '<p style="margin:4px 0 0;">Vendedor: ' + vendorName + '</p>';
                                                html += '<p style="margin:4px 0 0;">Tasa ReDi: ' + rediRate + '%</p>';
                                                html += '</div>';
                                        } );

                                        html += '</div>';
                                        $container.html( html );
                                } else {
                                        $container.html( '<p>No se encontraron productos ReDi disponibles en este momento.</p>' );
                                }
                        },
                        error: function() {
                                $btn.prop( 'disabled', false ).text( 'Explorar Productos ReDi' );
                                $container.html( '<p>Error de conexión. Intente nuevamente.</p>' );
                        }
                } );
        } );

        // AUDIT-REDI-UX-GAPS GAP-3 FIX: soft pause/resume handlers.
        // v2.9.61 DEEP-AUDIT-002 P0-1 FIX: El PHP espera 'origin_product_id' (no 'product_id').
        // Antes todos los clicks retornaban 400 "ID de producto origen inválido".
        // FIX-P1-BATCH-A: replace native confirm() (W-R-3) and LTMS.Dashboard.loadView()
        // (W-R-4) with surgical DOM updates on the affected row — preserves the
        // SPA state and avoids clobbering the server-rendered PHP view.
        function toggleRediRow( $btn, nowPaused ) {
                var $row   = $btn.closest( 'tr[data-product-id]' );
                if ( ! $row.length ) return;
                var $status = $row.children( 'td' ).eq( 6 ); // 7th column = Estado
                if ( nowPaused ) {
                        $status.html( '<span style="color:#F0B500;font-weight:600;">⏸️ ' +
                                'Pausado' + '</span>' );
                        $btn.removeClass( 'ltms-btn-secondary ltms-redi-pause-btn' )
                            .addClass( 'ltms-btn-primary ltms-redi-resume-btn' )
                            .text( '▶️ Reanudar' );
                } else {
                        $status.html( '<span style="color:#27ae60;font-weight:600;">✓ ' +
                                'Activo' + '</span>' );
                        $btn.removeClass( 'ltms-btn-primary ltms-redi-resume-btn' )
                            .addClass( 'ltms-btn-secondary ltms-redi-pause-btn' )
                            .text( '⏸️ Pausar' );
                }
        }

        $(document).on('click', '.ltms-redi-pause-btn', function(e){
                e.preventDefault();
                var $btn = $(this);
                var productId = $btn.data('product-id');
                if (!productId) return;
                // v2.9.99 P1 FIX: eliminado native confirm() — el botón es explícito.
                // Feedback via toast tras la acción.
                $btn.prop('disabled', true);
                $.post(ajaxurl, {
                        action: 'ltms_redi_soft_pause',
                        nonce: (typeof ltmsDashboard !== 'undefined') ? ltmsDashboard.nonce : '',
                        origin_product_id: productId
                }, function(resp){
                        if (resp && resp.success) {
                                // toast: ReDi pausado
                                LTMS.UX.toastSuccess('Exito', 'ReDi pausado. Los revendedores han sido notificados.');
                                toggleRediRow( $btn, true );
                                $btn.prop('disabled', false);
                        } else {
                                $btn.prop('disabled', false);
                                LTMS.UX.toastError('Error', resp && resp.data ? resp.data.message : 'Error al pausar.');
                        }
                }, 'json').fail(function(){
                        $btn.prop('disabled', false);
                        LTMS.UX.toastError('Error', 'Error de conexión.');
                });
        });

        $(document).on('click', '.ltms-redi-resume-btn', function(e){
                e.preventDefault();
                var $btn = $(this);
                var productId = $btn.data('product-id');
                if (!productId) return;
                $btn.prop('disabled', true);
                $.post(ajaxurl, {
                        action: 'ltms_redi_soft_resume',
                        nonce: (typeof ltmsDashboard !== 'undefined') ? ltmsDashboard.nonce : '',
                        origin_product_id: productId
                }, function(resp){
                        if (resp && resp.success) {
                                LTMS.UX.toastSuccess('Exito', 'ReDi reanudado. Los revendedores han sido notificados.');
                                toggleRediRow( $btn, false );
                                $btn.prop('disabled', false);
                        } else {
                                $btn.prop('disabled', false);
                                LTMS.UX.toastError('Error', resp && resp.data ? resp.data.message : 'Error al reanudar.');
                        }
                }, 'json').fail(function(){
                        $btn.prop('disabled', false);
                        LTMS.UX.toastError('Error', 'Error de conexión.');
                });
        });
});
