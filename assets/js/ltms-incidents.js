/**
 * LTMS view-incidents — extracted from inline <script>.
 * AUDIT-PANEL-FN-03 (re-auditoría): migrated to external file for CSP compliance.
 * FASE2B P0 FIX (CSP): completes the migration of view-incidents (el inline script
 * más grande, 445 líneas) — era el último bloque grande JS no migrado en el panel.
 *
 * Strings localizadas via wp_localize_script('ltms-incidents', 'ltmsIncidents', {...}).
 * Variables dinámicas (nonce, currentUserId) también viajan via ltms_incidents.
 */
(function( $ ) {
    'use strict';

    if ( typeof ltmsIncidents === 'undefined' ) { return; }

    var C = ltmsIncidents;   // Config injected via wp_localize_script.
    var i18n = C.strings || {};
    function t( k, fallback ) { return i18n[ k ] || fallback; }
    function esc( s ) {
        if ( s === null || s === undefined ) return '';
        return $( '<div>' ).text( String( s ) ).html();
    }

    var LTMS_INCIDENTS = {
        nonce:         C.nonce || '',
        ajaxUrl:       ( typeof ltmsDashboard !== 'undefined' && ltmsDashboard.ajax_url )
            ? ltmsDashboard.ajax_url
            : ( typeof ajaxurl !== 'undefined' ? ajaxurl : '' ),
        currentUser:   parseInt( C.currentUserId, 10 ) || 0,
        currentFilter: '',
        page: 1,
        perPage: 20,

        typeLabels:   C.typeLabels || {},
        statusLabels: C.statusLabels || {},

        init: function() {
            var self = this;

            // Cargar lista al montar la vista.
            self.loadIncidents();

            // Filtro por estado.
            $( '#ltms-incident-status-filter' ).on( 'change', function() {
                self.currentFilter = $( this ).val();
                self.page = 1;
                self.loadIncidents();
            } );

            // Botón refresh.
            $( '#ltms-incident-refresh' ).on( 'click', function() {
                self.loadIncidents();
            } );

            // Abrir modal nueva incidencia.
            $( '#ltms-incident-new-btn' ).on( 'click', function() {
                $( '#ltms-incident-new-form' )[0].reset();
                self.openModal( '#ltms-modal-incident-new' );
            } );

            // Cerrar modales.
            $( '#ltms-incidents-view' ).on( 'click', '.ltms-modal-close, .ltms-modal-close-btn, .ltms-modal-backdrop', function( e ) {
                var $modal = $( this ).closest( '.ltms-modal' );
                self.closeModal( $modal );
            } );

            // Submit form nueva incidencia.
            $( '#ltms-incident-new-form' ).on( 'submit', function( e ) {
                e.preventDefault();
                self.submitNewIncident();
            } );

            // Submit comentario.
            $( '#ltms-incident-comment-form' ).on( 'submit', function( e ) {
                e.preventDefault();
                self.submitComment();
            } );

            // Delegado: botón "ver detalle" en cada fila.
            $( '#ltms-incidents-tbody' ).on( 'click', '.ltms-incident-view-btn', function( e ) {
                e.preventDefault();
                var incidentId = $( this ).data( 'id' );
                self.openDetail( incidentId );
            } );
        },

        loadIncidents: function() {
            var self = this;
            var $tbody = $( '#ltms-incidents-tbody' );

            $tbody.html(
                '<tr><td colspan="8" style="text-align:center;padding:30px;color:#9ca3af;">' +
                t('loading','Cargando...') + '</td></tr>'
            );

            $.ajax({
                url: self.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'ltms_get_incidents',
                    nonce:  self.nonce,
                    status: self.currentFilter,
                    page:   self.page,
                    per_page: self.perPage
                },
                success: function( res ) {
                    if ( res && res.success && res.data && res.data.incidents ) {
                        self.renderTable( res.data.incidents );
                        self.renderKPIs( res.data.incidents );
                    } else {
                        $tbody.html(
                            '<tr><td colspan="8" style="text-align:center;padding:30px;color:#9ca3af;">' +
                            ( res && res.data && res.data.message ? res.data.message : t('no_incidents','No hay incidencias para mostrar') ) +
                            '</td></tr>'
                        );
                    }
                },
                error: function() {
                    $tbody.html(
                        '<tr><td colspan="8" style="text-align:center;padding:30px;color:#dc2626;">' +
                        t('conn_error','Error de conexión. Intente nuevamente.') + '</td></tr>'
                    );
                }
            });
        },

        renderTable: function( incidents ) {
            var self = this;
            var $tbody = $( '#ltms-incidents-tbody' );

            if ( ! incidents.length ) {
                $tbody.html(
                    '<tr><td colspan="8" style="text-align:center;padding:30px;color:#9ca3af;">' +
                    t('no_incidents_registered','No tienes incidencias registradas.') + '</td></tr>'
                );
                return;
            }

            var html = '';
            $.each( incidents, function( i, inc ) {
                var role = ( parseInt( inc.origin_vendor_id, 10 ) === self.currentUser )
                    ? t('origin_role','Origen')
                    : t('reseller_role','Revendedor');
                var statusBadge = self.statusBadge( inc.status );
                var slaLabel    = self.slaLabel( inc );
                var created     = self.formatDate( inc.created_at );
                var typeLabel   = self.typeLabels[ inc.type ] || inc.type;

                html += '<tr>';
                html += '<td>#' + esc( inc.id ) + '</td>';
                html += '<td>#' + esc( inc.order_id ) + '</td>';
                html += '<td>' + esc( typeLabel ) + '</td>';
                html += '<td>' + esc( role ) + '</td>';
                html += '<td>' + statusBadge + '</td>';
                html += '<td>' + slaLabel + '</td>';
                html += '<td>' + esc( created ) + '</td>';
                html += '<td><button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm ltms-incident-view-btn" data-id="' + parseInt( inc.id, 10 ) + '">' + t('view','Ver') + '</button></td>';
                html += '</tr>';
            });

            $tbody.html( html );
        },

        renderKPIs: function( incidents ) {
            var counts = { open: 0, investigating: 0, escalated: 0, resolved: 0 };
            $.each( incidents, function( i, inc ) {
                if ( counts.hasOwnProperty( inc.status ) ) {
                    counts[ inc.status ]++;
                }
            });
            $( '#ltms-incident-kpi-open' ).text( counts.open );
            $( '#ltms-incident-kpi-investigating' ).text( counts.investigating );
            $( '#ltms-incident-kpi-escalated' ).text( counts.escalated );
            $( '#ltms-incident-kpi-resolved' ).text( counts.resolved );
        },

        statusBadge: function( status ) {
            var colors = {
                open:          '#3b82f6',
                investigating: '#f59e0b',
                escalated:     '#dc2626',
                resolved:      '#16a34a',
                closed:        '#6b7280'
            };
            var color = colors[ status ] || '#6b7280';
            var label = this.statusLabels[ status ] || status;
            return '<span style="display:inline-block;padding:2px 10px;border-radius:999px;color:#fff;background:' + color + ';font-size:0.75rem;font-weight:600;">' + esc( label ) + '</span>';
        },

        slaLabel: function( inc ) {
            // Si está resuelto/cerrado, no mostrar timer.
            if ( inc.status === 'resolved' || inc.status === 'closed' ) {
                return '<span style="color:#9ca3af;font-size:0.8rem;">—</span>';
            }

            var now  = Date.now();
            var sla  = Date.parse( inc.sla_due_at.replace( ' ', 'T' ) + 'Z' );
            var res  = Date.parse( inc.resolution_due_at.replace( ' ', 'T' ) + 'Z' );

            // Si venció SLA de resolución.
            if ( res < now ) {
                return '<span style="color:#dc2626;font-weight:600;font-size:0.8rem;">⚠ ' + t('expired','Vencida') + '</span>';
            }

            // Si venció SLA de 1era respuesta pero aún no el de resolución.
            if ( sla < now ) {
                var daysToResolution = Math.ceil( ( res - now ) / 86400000 );
                return '<span style="color:#f59e0b;font-weight:600;font-size:0.8rem;">' + daysToResolution + 'd' + t('to_close',' al cierre') + '</span>';
            }

            // SLA de 1era respuesta vigente — mostrar horas restantes.
            var hoursToFirst = Math.max( 0, Math.floor( ( sla - now ) / 3600000 ) );
            return '<span style="color:#16a34a;font-size:0.8rem;">' + hoursToFirst + 'h' + t('first_resp',' 1era resp') + '</span>';
        },

        formatDate: function( dt ) {
            if ( ! dt ) return '';
            // MySQL datetime → display corto.
            return dt.replace( 'T', ' ' ).substring( 0, 16 );
        },

        openDetail: function( incidentId ) {
            var self = this;
            var $body = $( '#ltms-incident-detail-body' );

            $body.html(
                '<div style="text-align:center;padding:24px;color:#9ca3af;">' +
                t('loading_detail','Cargando detalle...') + '</div>'
            );
            $( '#ltms-incident-detail-footer' ).hide();
            self.openModal( '#ltms-modal-incident-detail' );

            $.ajax({
                url: self.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'ltms_get_incident_detail',
                    nonce:  self.nonce,
                    incident_id: incidentId
                },
                success: function( res ) {
                    if ( res && res.success && res.data && res.data.incident ) {
                        self.renderDetail( res.data.incident );
                    } else {
                        $body.html(
                            '<div style="padding:24px;color:#dc2626;text-align:center;">' +
                            ( res && res.data && res.data.message ? res.data.message : t('no_detail','No se pudo cargar el detalle') ) +
                            '</div>'
                        );
                    }
                },
                error: function() {
                    $body.html(
                        '<div style="padding:24px;color:#dc2626;text-align:center;">' +
                        t('conn_error','Error de conexión') + '</div>'
                    );
                }
            });
        },

        renderDetail: function( inc ) {
            var self = this;
            var $body = $( '#ltms-incident-detail-body' );

            $( '#ltms-incident-detail-title' ).text( t('incident_hash','Novedad #') + inc.id );

            var role = ( parseInt( inc.origin_vendor_id, 10 ) === self.currentUser )
                ? t('origin_role','Origen')
                : t('reseller_role','Revendedor');

            var html = '';
            html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;font-size:0.85rem;">';
            html += '<div><strong>' + t('order_label','Pedido:') + '</strong> #' + esc( inc.order_id ) + '</div>';
            html += '<div><strong>' + t('type_label','Tipo:') + '</strong> ' + esc( self.typeLabels[ inc.type ] || inc.type ) + '</div>';
            html += '<div><strong>' + t('state_label','Estado:') + '</strong> ' + self.statusBadge( inc.status ) + '</div>';
            html += '<div><strong>' + t('my_role_label','Mi rol:') + '</strong> ' + esc( role ) + '</div>';
            html += '<div><strong>' + t('sla_label','SLA 1era resp:') + '</strong> ' + esc( self.formatDate( inc.sla_due_at ) ) + '</div>';
            html += '<div><strong>' + t('resolution_label','Vence resolución:') + '</strong> ' + esc( self.formatDate( inc.resolution_due_at ) ) + '</div>';
            html += '<div><strong>' + t('created_label','Creada:') + '</strong> ' + esc( self.formatDate( inc.created_at ) ) + '</div>';
            if ( inc.resolved_at ) {
                html += '<div><strong>' + t('resolved_label','Resuelta:') + '</strong> ' + esc( self.formatDate( inc.resolved_at ) ) + '</div>';
            }
            html += '</div>';

            html += '<div style="background:#f9fafb;padding:10px 12px;border-radius:6px;margin-bottom:16px;">';
            html += '<strong style="display:block;font-size:0.8rem;margin-bottom:4px;">' + t('description_label','Descripción:') + '</strong>';
            html += '<div style="font-size:0.9rem;white-space:pre-wrap;">' + esc( inc.description || '' ) + '</div>';
            html += '</div>';

            if ( inc.resolution_notes ) {
                html += '<div style="background:#ecfdf5;padding:10px 12px;border-radius:6px;margin-bottom:16px;">';
                html += '<strong style="display:block;font-size:0.8rem;margin-bottom:4px;">' + t('resolution_notes_label','Notas de resolución:') + '</strong>';
                html += '<div style="font-size:0.9rem;">' + esc( inc.resolution_notes ) + '</div>';
                html += '</div>';
            }

            // Hilo de comentarios.
            html += '<h4 style="font-size:0.9rem;margin:0 0 8px;">' + t('comment_thread','Hilo de comentarios') + '</h4>';
            if ( inc.comments && inc.comments.length ) {
                html += '<div style="display:flex;flex-direction:column;gap:8px;">';
                $.each( inc.comments, function( i, c ) {
                    var userName = c.user_name || ( '#' + c.user_id );
                    var isMe     = parseInt( c.user_id, 10 ) === self.currentUser;
                    html += '<div style="background:' + ( isMe ? '#eff6ff' : '#fff' ) + ';border:1px solid #e5e7eb;border-radius:6px;padding:8px 10px;">';
                    html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">';
                    html += '<strong style="font-size:0.8rem;">' + esc( userName ) + ( isMe ? ' (' + t('you','tú') + ')' : '' ) + '</strong>';
                    html += '<span style="font-size:0.7rem;color:#9ca3af;">' + esc( self.formatDate( c.created_at ) ) + '</span>';
                    html += '</div>';
                    html += '<div style="font-size:0.85rem;white-space:pre-wrap;">' + esc( c.comment ) + '</div>';
                    html += '</div>';
                });
                html += '</div>';
            } else {
                html += '<p style="color:#9ca3af;font-size:0.85rem;text-align:center;padding:12px;">' + t('no_comments','Aún no hay comentarios en esta incidencia.') + '</p>';
            }

            $body.html( html );

            // Mostrar footer con formulario de comentario (solo si no está cerrada).
            if ( inc.status !== 'closed' ) {
                $( '#ltms-incident-comment-incident-id' ).val( inc.id );
                $( '#ltms-incident-comment-text' ).val( '' );
                $( '#ltms-incident-detail-footer' ).show();
            } else {
                $( '#ltms-incident-detail-footer' ).hide();
            }
        },

        submitComment: function() {
            var self = this;
            var incidentId = parseInt( $( '#ltms-incident-comment-incident-id' ).val(), 10 );
            var comment    = $( '#ltms-incident-comment-text' ).val().trim();

            if ( ! incidentId || ! comment ) {
                return;
            }

            $.ajax({
                url: self.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'ltms_add_incident_comment',
                    nonce:  self.nonce,
                    incident_id: incidentId,
                    comment: comment
                },
                success: function( res ) {
                    if ( res && res.success ) {
                        // Recargar el detalle para mostrar el nuevo comentario.
                        self.openDetail( incidentId );
                    } else {
                        if ( typeof LTMS !== 'undefined' && LTMS.UX && LTMS.UX.toastError ) {
                            LTMS.UX.toastError( t('error','Error'), res && res.data && res.data.message ? res.data.message : t('comment_err','Error al enviar comentario') );
                        }
                    }
                },
                error: function() {
                    if ( typeof LTMS !== 'undefined' && LTMS.UX && LTMS.UX.toastError ) {
                        LTMS.UX.toastError( t('error','Error'), t('conn_error','Error de conexión') );
                    }
                }
            });
        },

        submitNewIncident: function() {
            var self = this;
            var orderId = parseInt( $( '#ltms-incident-new-order' ).val(), 10 );
            var type    = $( '#ltms-incident-new-type' ).val();
            var desc    = $( '#ltms-incident-new-desc' ).val().trim();

            if ( ! orderId || ! type || ! desc ) {
                if ( typeof LTMS !== 'undefined' && LTMS.UX && LTMS.UX.toastError ) {
                    LTMS.UX.toastError( t('attention','Atención'), t('all_fields_required','Todos los campos son obligatorios') );
                }
                return;
            }

            $.ajax({
                url: self.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'ltms_create_incident',
                    nonce:  self.nonce,
                    order_id: orderId,
                    type: type,
                    description: desc
                },
                success: function( res ) {
                    if ( res && res.success ) {
                        self.closeModal( '#ltms-modal-incident-new' );
                        self.loadIncidents();
                        if ( typeof LTMS !== 'undefined' && LTMS.UX && LTMS.UX.toastSuccess ) {
                            LTMS.UX.toastSuccess( t('done','Listo'), res.data.message || t('incident_created','Novedad creada') );
                        }
                    } else {
                        if ( typeof LTMS !== 'undefined' && LTMS.UX && LTMS.UX.toastError ) {
                            LTMS.UX.toastError( t('error','Error'), res && res.data && res.data.message ? res.data.message : t('create_err','Error al crear novedad') );
                        }
                    }
                },
                error: function() {
                    if ( typeof LTMS !== 'undefined' && LTMS.UX && LTMS.UX.toastError ) {
                        LTMS.UX.toastError( t('error','Error'), t('conn_error','Error de conexión') );
                    }
                }
            });
        },

        openModal: function( sel ) {
            var id = String( sel ).replace( /^#/, '' );
            if ( typeof LTMS !== 'undefined' && LTMS.Modal && typeof LTMS.Modal.open === 'function' ) {
                LTMS.Modal.open( id );
            } else {
                $( sel ).css( 'display', 'flex' ).attr( 'aria-hidden', 'false' );
            }
        },

        closeModal: function( sel ) {
            var id;
            if ( typeof sel === 'string' ) {
                id = sel.replace( /^#/, '' );
            } else {
                id = $( sel ).attr( 'id' );
            }
            if ( typeof LTMS !== 'undefined' && LTMS.Modal && typeof LTMS.Modal.close === 'function' ) {
                LTMS.Modal.close( id );
            } else {
                $( '#' + id ).css( 'display', 'none' ).attr( 'aria-hidden', 'true' );
            }
        }
    };

    // Inicializar cuando el DOM esté listo.
    $( function() {
        LTMS_INCIDENTS.init();
    });

    // Exponer para debugging / re-invocación desde fuera.
    window.LTMS_INCIDENTS = LTMS_INCIDENTS;

}( jQuery ) );
