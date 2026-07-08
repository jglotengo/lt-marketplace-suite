<?php
/**
 * Vista SPA: Novedades (Incidencias ReDi) del Vendedor
 *
 * AUDIT-REDI-UX-GAPS GAP-9 FIX.
 *
 * Lista las incidencias abiertas por o contra el vendedor actual, muestra
 * KPIs por estado, permite filtrar y abrir el detalle en un modal con el
 * hilo de comentarios. Incluye un modal para abrir nuevas incidencias.
 *
 * @package LTMS
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$current_user_id = get_current_user_id();
$dashboard_nonce = wp_create_nonce( 'ltms_dashboard_nonce' );
?>
<div class="ltms-view-pad" id="ltms-incidents-view">

    <div class="ltms-view-header">
        <h2><?php esc_html_e( 'Novedades ReDi', 'ltms' ); ?></h2>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <select id="ltms-incident-status-filter" class="ltms-btn ltms-btn-outline ltms-btn-sm" style="cursor:pointer;">
                <option value=""><?php esc_html_e( 'Todos', 'ltms' ); ?></option>
                <option value="open"><?php esc_html_e( 'Abiertas', 'ltms' ); ?></option>
                <option value="investigating"><?php esc_html_e( 'Investigando', 'ltms' ); ?></option>
                <option value="escalated"><?php esc_html_e( 'Escaladas', 'ltms' ); ?></option>
                <option value="resolved"><?php esc_html_e( 'Resueltas', 'ltms' ); ?></option>
                <option value="closed"><?php esc_html_e( 'Cerradas', 'ltms' ); ?></option>
            </select>
            <button type="button" id="ltms-incident-refresh" class="ltms-btn ltms-btn-outline ltms-btn-sm">
                🔄 <?php esc_html_e( 'Actualizar', 'ltms' ); ?>
            </button>
            <button type="button" id="ltms-incident-new-btn" class="ltms-btn ltms-btn-primary ltms-btn-sm">
                ➕ <?php esc_html_e( 'Nueva Novedad', 'ltms' ); ?>
            </button>
        </div>
    </div>

    <!-- KPIs -->
    <div class="ltms-metrics-grid" id="ltms-incident-kpis">
        <div class="ltms-metric">
            <div class="ltms-metric-icon blue">🟦</div>
            <div class="ltms-metric-label"><?php esc_html_e( 'Abiertas', 'ltms' ); ?></div>
            <div class="ltms-metric-value" id="ltms-incident-kpi-open">0</div>
        </div>
        <div class="ltms-metric">
            <div class="ltms-metric-icon orange">🔍</div>
            <div class="ltms-metric-label"><?php esc_html_e( 'Investigando', 'ltms' ); ?></div>
            <div class="ltms-metric-value" id="ltms-incident-kpi-investigating">0</div>
        </div>
        <div class="ltms-metric">
            <div class="ltms-metric-icon red">🚨</div>
            <div class="ltms-metric-label"><?php esc_html_e( 'Escaladas', 'ltms' ); ?></div>
            <div class="ltms-metric-value" id="ltms-incident-kpi-escalated">0</div>
        </div>
        <div class="ltms-metric">
            <div class="ltms-metric-icon green">✅</div>
            <div class="ltms-metric-label"><?php esc_html_e( 'Resueltas', 'ltms' ); ?></div>
            <div class="ltms-metric-value" id="ltms-incident-kpi-resolved">0</div>
        </div>
    </div>

    <!-- Tabla de incidencias -->
    <div class="ltms-card">
        <div class="ltms-card-body ltms-table-scroll" style="padding:0;max-height:60vh;overflow-y:auto;">
            <table class="ltms-dtable" style="width:100%;">
                <thead style="position:sticky;top:0;background:#fff;z-index:2;">
                    <tr>
                        <th><?php esc_html_e( '#', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Pedido', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Tipo', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Mi rol', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'SLA', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Creada', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Acciones', 'ltms' ); ?></th>
                    </tr>
                </thead>
                <tbody id="ltms-incidents-tbody">
                    <tr><td colspan="8" style="text-align:center;padding:30px;color:#9ca3af;">
                        <?php esc_html_e( 'Cargando incidencias...', 'ltms' ); ?>
                    </td></tr>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Modal: detalle de incidencia -->
<div id="ltms-modal-incident-detail" class="ltms-modal" role="dialog" aria-modal="true" aria-labelledby="ltms-incident-detail-title">
    <div class="ltms-modal-backdrop"></div>
    <div class="ltms-modal-inner" style="max-width:720px;">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid #e5e7eb;">
            <h3 id="ltms-incident-detail-title" style="margin:0;font-size:1.05rem;">
                <?php esc_html_e( 'Detalle de Novedad', 'ltms' ); ?>
            </h3>
            <button type="button" class="ltms-modal-close" aria-label="Cerrar" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:#6b7280;">✕</button>
        </div>
        <div id="ltms-incident-detail-body" style="padding:16px;max-height:70vh;overflow-y:auto;"></div>
        <div id="ltms-incident-detail-footer" style="padding:12px 16px;border-top:1px solid #e5e7eb;display:none;">
            <form id="ltms-incident-comment-form" style="display:flex;gap:8px;">
                <input type="hidden" name="incident_id" id="ltms-incident-comment-incident-id" value="0">
                <textarea name="comment" id="ltms-incident-comment-text" rows="2"
                          placeholder="<?php esc_attr_e( 'Escribe un comentario...', 'ltms' ); ?>"
                          style="flex:1;padding:8px;border:1px solid #d1d5db;border-radius:6px;resize:vertical;font-family:inherit;font-size:0.9rem;"></textarea>
                <button type="submit" class="ltms-btn ltms-btn-primary ltms-btn-sm" style="align-self:flex-start;">
                    <?php esc_html_e( 'Enviar', 'ltms' ); ?>
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Modal: nueva incidencia -->
<div id="ltms-modal-incident-new" class="ltms-modal" role="dialog" aria-modal="true" aria-labelledby="ltms-incident-new-title">
    <div class="ltms-modal-backdrop"></div>
    <div class="ltms-modal-inner" style="max-width:520px;">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid #e5e7eb;">
            <h3 id="ltms-incident-new-title" style="margin:0;font-size:1.05rem;"><?php esc_html_e( 'Nueva Novedad ReDi', 'ltms' ); ?></h3>
            <button type="button" class="ltms-modal-close" aria-label="Cerrar" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:#6b7280;">✕</button>
        </div>
        <form id="ltms-incident-new-form" style="padding:16px;display:flex;flex-direction:column;gap:12px;">
            <div>
                <label for="ltms-incident-new-order" style="display:block;font-size:0.85rem;margin-bottom:4px;font-weight:600;">
                    <?php esc_html_e( 'Pedido #', 'ltms' ); ?> <span style="color:#dc2626;">*</span>
                </label>
                <input type="number" id="ltms-incident-new-order" name="order_id" required
                       placeholder="<?php esc_attr_e( 'Ej: 12345', 'ltms' ); ?>"
                       style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
                <p style="font-size:0.75rem;color:#6b7280;margin:4px 0 0;">
                    <?php esc_html_e( 'El pedido debe contener al menos un producto ReDi.', 'ltms' ); ?>
                </p>
            </div>
            <div>
                <label for="ltms-incident-new-type" style="display:block;font-size:0.85rem;margin-bottom:4px;font-weight:600;">
                    <?php esc_html_e( 'Tipo', 'ltms' ); ?> <span style="color:#dc2626;">*</span>
                </label>
                <select id="ltms-incident-new-type" name="type" required
                        style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
                    <option value="stockout"><?php esc_html_e( 'Agotado / Sin stock', 'ltms' ); ?></option>
                    <option value="complaint"><?php esc_html_e( 'Queja del cliente', 'ltms' ); ?></option>
                    <option value="quality"><?php esc_html_e( 'Calidad del producto', 'ltms' ); ?></option>
                    <option value="shipping"><?php esc_html_e( 'Problema de envío', 'ltms' ); ?></option>
                    <option value="payment"><?php esc_html_e( 'Problema de pago', 'ltms' ); ?></option>
                    <option value="other"><?php esc_html_e( 'Otro', 'ltms' ); ?></option>
                </select>
            </div>
            <div>
                <label for="ltms-incident-new-desc" style="display:block;font-size:0.85rem;margin-bottom:4px;font-weight:600;">
                    <?php esc_html_e( 'Descripción', 'ltms' ); ?> <span style="color:#dc2626;">*</span>
                </label>
                <textarea id="ltms-incident-new-desc" name="description" required rows="4"
                          placeholder="<?php esc_attr_e( 'Describe la novedad en detalle...', 'ltms' ); ?>"
                          style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;resize:vertical;font-family:inherit;"></textarea>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm ltms-modal-close-btn">
                    <?php esc_html_e( 'Cancelar', 'ltms' ); ?>
                </button>
                <button type="submit" class="ltms-btn ltms-btn-primary ltms-btn-sm">
                    <?php esc_html_e( 'Crear Novedad', 'ltms' ); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script type="text/javascript">
/* global jQuery, ltmsDashboard */
(function( $ ) {
    'use strict';

    var LTMS_INCIDENTS = {
        nonce:  '<?php echo esc_js( $dashboard_nonce ); ?>',
        ajaxUrl: ( typeof ltmsDashboard !== 'undefined' && ltmsDashboard.ajax_url )
            ? ltmsDashboard.ajax_url
            : ( typeof ajaxurl !== 'undefined' ? ajaxurl : '' ),
        currentUser: <?php echo (int) $current_user_id; ?>,
        currentFilter: '',
        page: 1,
        perPage: 20,

        typeLabels: {
            stockout:   '<?php echo esc_js( __( 'Agotado', 'ltms' ) ); ?>',
            complaint:  '<?php echo esc_js( __( 'Queja', 'ltms' ) ); ?>',
            quality:    '<?php echo esc_js( __( 'Calidad', 'ltms' ) ); ?>',
            shipping:   '<?php echo esc_js( __( 'Envío', 'ltms' ) ); ?>',
            payment:    '<?php echo esc_js( __( 'Pago', 'ltms' ) ); ?>',
            other:      '<?php echo esc_js( __( 'Otro', 'ltms' ) ); ?>'
        },

        statusLabels: {
            open:          '<?php echo esc_js( __( 'Abierta', 'ltms' ) ); ?>',
            investigating: '<?php echo esc_js( __( 'Investigando', 'ltms' ) ); ?>',
            escalated:     '<?php echo esc_js( __( 'Escalada', 'ltms' ) ); ?>',
            resolved:      '<?php echo esc_js( __( 'Resuelta', 'ltms' ) ); ?>',
            closed:        '<?php echo esc_js( __( 'Cerrada', 'ltms' ) ); ?>'
        },

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
                '<?php echo esc_js( __( 'Cargando...', 'ltms' ) ); ?></td></tr>'
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
                            ( res && res.data && res.data.message ? res.data.message : '<?php echo esc_js( __( 'No hay incidencias para mostrar', 'ltms' ) ); ?>' ) +
                            '</td></tr>'
                        );
                    }
                },
                error: function() {
                    $tbody.html(
                        '<tr><td colspan="8" style="text-align:center;padding:30px;color:#dc2626;">' +
                        '<?php echo esc_js( __( 'Error de conexión. Intente nuevamente.', 'ltms' ) ); ?></td></tr>'
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
                    '<?php echo esc_js( __( 'No tienes incidencias registradas.', 'ltms' ) ); ?></td></tr>'
                );
                return;
            }

            var html = '';
            $.each( incidents, function( i, inc ) {
                var role = ( parseInt( inc.origin_vendor_id, 10 ) === self.currentUser )
                    ? '<?php echo esc_js( __( 'Origen', 'ltms' ) ); ?>'
                    : '<?php echo esc_js( __( 'Revendedor', 'ltms' ) ); ?>';
                var statusBadge = self.statusBadge( inc.status );
                var slaLabel    = self.slaLabel( inc );
                var created     = self.formatDate( inc.created_at );
                var typeLabel   = self.typeLabels[ inc.type ] || inc.type;

                html += '<tr>';
                html += '<td>#' + self.esc( inc.id ) + '</td>';
                html += '<td>#' + self.esc( inc.order_id ) + '</td>';
                html += '<td>' + self.esc( typeLabel ) + '</td>';
                html += '<td>' + self.esc( role ) + '</td>';
                html += '<td>' + statusBadge + '</td>';
                html += '<td>' + slaLabel + '</td>';
                html += '<td>' + self.esc( created ) + '</td>';
                html += '<td><button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm ltms-incident-view-btn" data-id="' + parseInt( inc.id, 10 ) + '"><?php echo esc_js( __( 'Ver', 'ltms' ) ); ?></button></td>';
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
            return '<span style="display:inline-block;padding:2px 10px;border-radius:999px;color:#fff;background:' + color + ';font-size:0.75rem;font-weight:600;">' + this.esc( label ) + '</span>';
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
                return '<span style="color:#dc2626;font-weight:600;font-size:0.8rem;">⚠ <?php echo esc_js( __( 'Vencida', 'ltms' ) ); ?></span>';
            }

            // Si venció SLA de 1era respuesta pero aún no el de resolución.
            if ( sla < now ) {
                var daysToResolution = Math.ceil( ( res - now ) / 86400000 );
                return '<span style="color:#f59e0b;font-weight:600;font-size:0.8rem;">' + daysToResolution + 'd<?php echo esc_js( __( ' al cierre', 'ltms' ) ); ?></span>';
            }

            // SLA de 1era respuesta vigente — mostrar horas restantes.
            var hoursToFirst = Math.max( 0, Math.floor( ( sla - now ) / 3600000 ) );
            return '<span style="color:#16a34a;font-size:0.8rem;">' + hoursToFirst + 'h<?php echo esc_js( __( ' 1era resp', 'ltms' ) ); ?></span>';
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
                '<?php echo esc_js( __( 'Cargando detalle...', 'ltms' ) ); ?></div>'
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
                            ( res && res.data && res.data.message ? res.data.message : '<?php echo esc_js( __( 'No se pudo cargar el detalle', 'ltms' ) ); ?>' ) +
                            '</div>'
                        );
                    }
                },
                error: function() {
                    $body.html(
                        '<div style="padding:24px;color:#dc2626;text-align:center;">' +
                        '<?php echo esc_js( __( 'Error de conexión', 'ltms' ) ); ?></div>'
                    );
                }
            });
        },

        renderDetail: function( inc ) {
            var self = this;
            var $body = $( '#ltms-incident-detail-body' );

            $( '#ltms-incident-detail-title' ).text(
                '<?php echo esc_js( __( 'Novedad #', 'ltms' ) ); ?>' + inc.id
            );

            var role = ( parseInt( inc.origin_vendor_id, 10 ) === self.currentUser )
                ? '<?php echo esc_js( __( 'Origen', 'ltms' ) ); ?>'
                : '<?php echo esc_js( __( 'Revendedor', 'ltms' ) ); ?>';

            var html = '';
            html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;font-size:0.85rem;">';
            html += '<div><strong><?php echo esc_js( __( 'Pedido:', 'ltms' ) ); ?></strong> #' + self.esc( inc.order_id ) + '</div>';
            html += '<div><strong><?php echo esc_js( __( 'Tipo:', 'ltms' ) ); ?></strong> ' + self.esc( self.typeLabels[ inc.type ] || inc.type ) + '</div>';
            html += '<div><strong><?php echo esc_js( __( 'Estado:', 'ltms' ) ); ?></strong> ' + self.statusBadge( inc.status ) + '</div>';
            html += '<div><strong><?php echo esc_js( __( 'Mi rol:', 'ltms' ) ); ?></strong> ' + self.esc( role ) + '</div>';
            html += '<div><strong><?php echo esc_js( __( 'SLA 1era resp:', 'ltms' ) ); ?></strong> ' + self.esc( self.formatDate( inc.sla_due_at ) ) + '</div>';
            html += '<div><strong><?php echo esc_js( __( 'Vence resolución:', 'ltms' ) ); ?></strong> ' + self.esc( self.formatDate( inc.resolution_due_at ) ) + '</div>';
            html += '<div><strong><?php echo esc_js( __( 'Creada:', 'ltms' ) ); ?></strong> ' + self.esc( self.formatDate( inc.created_at ) ) + '</div>';
            if ( inc.resolved_at ) {
                html += '<div><strong><?php echo esc_js( __( 'Resuelta:', 'ltms' ) ); ?></strong> ' + self.esc( self.formatDate( inc.resolved_at ) ) + '</div>';
            }
            html += '</div>';

            html += '<div style="background:#f9fafb;padding:10px 12px;border-radius:6px;margin-bottom:16px;">';
            html += '<strong style="display:block;font-size:0.8rem;margin-bottom:4px;"><?php echo esc_js( __( 'Descripción:', 'ltms' ) ); ?></strong>';
            html += '<div style="font-size:0.9rem;white-space:pre-wrap;">' + self.esc( inc.description || '' ) + '</div>';
            html += '</div>';

            if ( inc.resolution_notes ) {
                html += '<div style="background:#ecfdf5;padding:10px 12px;border-radius:6px;margin-bottom:16px;">';
                html += '<strong style="display:block;font-size:0.8rem;margin-bottom:4px;"><?php echo esc_js( __( 'Notas de resolución:', 'ltms' ) ); ?></strong>';
                html += '<div style="font-size:0.9rem;">' + self.esc( inc.resolution_notes ) + '</div>';
                html += '</div>';
            }

            // Hilo de comentarios.
            html += '<h4 style="font-size:0.9rem;margin:0 0 8px;"><?php echo esc_js( __( 'Hilo de comentarios', 'ltms' ) ); ?></h4>';
            if ( inc.comments && inc.comments.length ) {
                html += '<div style="display:flex;flex-direction:column;gap:8px;">';
                $.each( inc.comments, function( i, c ) {
                    var userName = c.user_name || ( '#' + c.user_id );
                    var isMe     = parseInt( c.user_id, 10 ) === self.currentUser;
                    html += '<div style="background:' + ( isMe ? '#eff6ff' : '#fff' ) + ';border:1px solid #e5e7eb;border-radius:6px;padding:8px 10px;">';
                    html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">';
                    html += '<strong style="font-size:0.8rem;">' + self.esc( userName ) + ( isMe ? ' (<?php echo esc_js( __( 'tú', 'ltms' ) ); ?>)' : '' ) + '</strong>';
                    html += '<span style="font-size:0.7rem;color:#9ca3af;">' + self.esc( self.formatDate( c.created_at ) ) + '</span>';
                    html += '</div>';
                    html += '<div style="font-size:0.85rem;white-space:pre-wrap;">' + self.esc( c.comment ) + '</div>';
                    html += '</div>';
                });
                html += '</div>';
            } else {
                html += '<p style="color:#9ca3af;font-size:0.85rem;text-align:center;padding:12px;"><?php echo esc_js( __( 'Aún no hay comentarios en esta incidencia.', 'ltms' ) ); ?></p>';
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
                            LTMS.UX.toastError( '<?php echo esc_js( __( 'Error', 'ltms' ) ); ?>', res && res.data && res.data.message ? res.data.message : '<?php echo esc_js( __( 'Error al enviar comentario', 'ltms' ) ); ?>' );
                        }
                    }
                },
                error: function() {
                    if ( typeof LTMS !== 'undefined' && LTMS.UX && LTMS.UX.toastError ) {
                        LTMS.UX.toastError( '<?php echo esc_js( __( 'Error', 'ltms' ) ); ?>', '<?php echo esc_js( __( 'Error de conexión', 'ltms' ) ); ?>' );
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
                    LTMS.UX.toastError( '<?php echo esc_js( __( 'Atención', 'ltms' ) ); ?>', '<?php echo esc_js( __( 'Todos los campos son obligatorios', 'ltms' ) ); ?>' );
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
                            LTMS.UX.toastSuccess( '<?php echo esc_js( __( 'Listo', 'ltms' ) ); ?>', res.data.message || '<?php echo esc_js( __( 'Novedad creada', 'ltms' ) ); ?>' );
                        }
                    } else {
                        if ( typeof LTMS !== 'undefined' && LTMS.UX && LTMS.UX.toastError ) {
                            LTMS.UX.toastError( '<?php echo esc_js( __( 'Error', 'ltms' ) ); ?>', res && res.data && res.data.message ? res.data.message : '<?php echo esc_js( __( 'Error al crear novedad', 'ltms' ) ); ?>' );
                        }
                    }
                },
                error: function() {
                    if ( typeof LTMS !== 'undefined' && LTMS.UX && LTMS.UX.toastError ) {
                        LTMS.UX.toastError( '<?php echo esc_js( __( 'Error', 'ltms' ) ); ?>', '<?php echo esc_js( __( 'Error de conexión', 'ltms' ) ); ?>' );
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
        },

        esc: function( s ) {
            if ( s === null || s === undefined ) return '';
            return $( '<div>' ).text( String( s ) ).html();
        }
    };

    // Inicializar cuando el DOM esté listo.
    $( function() {
        LTMS_INCIDENTS.init();
    });

    // Exponer para debugging / re-invocación desde fuera.
    window.LTMS_INCIDENTS = LTMS_INCIDENTS;

}( jQuery ) );
</script>
