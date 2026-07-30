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

<?php
// AUDIT-PANEL-FN-03 (re-auditoría): inline <script> moved to external assets/js/ltms-incidents.js.
// FASE2B P0 FIX (CSP): completes the migration of view-incidents (el inline script
// mas grande, 445 lineas) - era el ultimo bloque grande JS no migrado en el panel.
wp_enqueue_script( 'ltms-incidents', LTMS_ASSETS_URL . 'js/ltms-incidents.js', [ 'jquery' ], LTMS_VERSION, true );
wp_localize_script( 'ltms-incidents', 'ltmsIncidents', [
    'nonce'          => wp_create_nonce( 'ltms_dashboard_nonce' ),
    'currentUserId'  => get_current_user_id(),
    'typeLabels'     => [
        'stockout'  => __( 'Agotado', 'ltms' ),
        'complaint' => __( 'Queja', 'ltms' ),
        'quality'   => __( 'Calidad', 'ltms' ),
        'shipping'  => __( 'Envío', 'ltms' ),
        'payment'   => __( 'Pago', 'ltms' ),
        'other'     => __( 'Otro', 'ltms' ),
    ],
    'statusLabels'   => [
        'open'          => __( 'Abierta', 'ltms' ),
        'investigating' => __( 'Investigando', 'ltms' ),
        'escalated'     => __( 'Escalada', 'ltms' ),
        'resolved'      => __( 'Resuelta', 'ltms' ),
        'closed'        => __( 'Cerrada', 'ltms' ),
    ],
    'strings' => [
        'loading'                  => __( 'Cargando...', 'ltms' ),
        'no_incidents'             => __( 'No hay incidencias para mostrar', 'ltms' ),
        'no_incidents_registered'  => __( 'No tienes incidencias registradas.', 'ltms' ),
        'conn_error'               => __( 'Error de conexión. Intente nuevamente.', 'ltms' ),
        'view'                     => __( 'Ver', 'ltms' ),
        'origin_role'              => __( 'Origen', 'ltms' ),
        'reseller_role'            => __( 'Revendedor', 'ltms' ),
        'expired'                  => __( 'Vencida', 'ltms' ),
        'to_close'                 => __( ' al cierre', 'ltms' ),
        'first_resp'               => __( ' 1era resp', 'ltms' ),
        'loading_detail'           => __( 'Cargando detalle...', 'ltms' ),
        'no_detail'                => __( 'No se pudo cargar el detalle', 'ltms' ),
        'incident_hash'            => __( 'Novedad #', 'ltms' ),
        'order_label'              => __( 'Pedido:', 'ltms' ),
        'type_label'               => __( 'Tipo:', 'ltms' ),
        'state_label'              => __( 'Estado:', 'ltms' ),
        'my_role_label'            => __( 'Mi rol:', 'ltms' ),
        'sla_label'                => __( 'SLA 1era resp:', 'ltms' ),
        'resolution_label'         => __( 'Vence resolución:', 'ltms' ),
        'created_label'            => __( 'Creada:', 'ltms' ),
        'resolved_label'           => __( 'Resuelta:', 'ltms' ),
        'description_label'        => __( 'Descripción:', 'ltms' ),
        'resolution_notes_label'   => __( 'Notas de resolución:', 'ltms' ),
        'comment_thread'           => __( 'Hilo de comentarios', 'ltms' ),
        'you'                      => __( 'tú', 'ltms' ),
        'no_comments'              => __( 'Aún no hay comentarios en esta incidencia.', 'ltms' ),
        'error'                    => __( 'Error', 'ltms' ),
        'comment_err'              => __( 'Error al enviar comentario', 'ltms' ),
        'attention'                => __( 'Atención', 'ltms' ),
        'all_fields_required'     => __( 'Todos los campos son obligatorios', 'ltms' ),
        'done'                     => __( 'Listo', 'ltms' ),
        'incident_created'        => __( 'Novedad creada', 'ltms' ),
        'create_err'               => __( 'Error al crear novedad', 'ltms' ),
    ],
] );
