<?php
/**
 * Vista SPA: Reservas del Vendedor
 *
 * Lista de reservas, detalle en modal, y acción de cancelar con motivo.
 * Los datos se cargan vía AJAX desde LTMS_Frontend_Booking_Handler.
 *
 * @package LTMS
 * @version 2.8.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="ltms-view-pad">

    <!-- M-BOOKING-PLAN-02/03: tabs ──────────────────────────────── -->
    <div style="display:flex;gap:0;margin-bottom:24px;border-bottom:2px solid #e5e7eb;">
        <button type="button" class="ltms-booking-tab ltms-booking-tab-active"
                data-target="ltms-bk-reservas"
                style="background:none;border:none;padding:10px 20px;font-size:.88rem;font-weight:600;
                       cursor:pointer;color:#1a5276;border-bottom:2px solid #1a5276;margin-bottom:-2px;">
            📅 <?php esc_html_e( 'Mis Reservas', 'ltms' ); ?>
        </button>
        <button type="button" class="ltms-booking-tab"
                data-target="ltms-bk-seasons"
                style="background:none;border:none;padding:10px 20px;font-size:.88rem;font-weight:600;
                       cursor:pointer;color:#6b7280;border-bottom:2px solid transparent;margin-bottom:-2px;">
            🌤 <?php esc_html_e( 'Temporadas', 'ltms' ); ?>
        </button>
        <button type="button" class="ltms-booking-tab"
                data-target="ltms-bk-policies"
                style="background:none;border:none;padding:10px 20px;font-size:.88rem;font-weight:600;
                       cursor:pointer;color:#6b7280;border-bottom:2px solid transparent;margin-bottom:-2px;">
            📋 <?php esc_html_e( 'Políticas', 'ltms' ); ?>
        </button>
        <!-- v2.9.93 P2: Calendar tab -->
        <button type="button" class="ltms-booking-tab"
                data-target="ltms-bk-calendar"
                style="background:none;border:none;padding:10px 20px;font-size:.88rem;font-weight:600;
                       cursor:pointer;color:#6b7280;border-bottom:2px solid transparent;margin-bottom:-2px;">
            🗓️ <?php esc_html_e( 'Calendario', 'ltms' ); ?>
        </button>
    </div>
    <div id="ltms-bk-reservas">

    <div class="ltms-view-header">
        <h2><?php esc_html_e( 'Mis Reservas', 'ltms' ); ?></h2>
        <div style="display:flex;gap:8px;align-items:center;">
            <select id="ltms-bk-status-filter" class="ltms-btn ltms-btn-outline ltms-btn-sm" style="cursor:pointer;">
                <option value=""><?php esc_html_e( 'Todos los estados', 'ltms' ); ?></option>
                <option value="pending"><?php esc_html_e( 'Pendiente', 'ltms' ); ?></option>
                <option value="confirmed"><?php esc_html_e( 'Confirmada', 'ltms' ); ?></option>
                <option value="checked_in"><?php esc_html_e( 'En curso', 'ltms' ); ?></option>
                <option value="completed"><?php esc_html_e( 'Completada', 'ltms' ); ?></option>
                <option value="cancelled"><?php esc_html_e( 'Cancelada', 'ltms' ); ?></option>
            </select>
            <input type="date" id="ltms-bk-date-from"
                   class="ltms-btn ltms-btn-outline ltms-btn-sm"
                   placeholder="<?php esc_attr_e( 'Desde', 'ltms' ); ?>"
                   style="cursor:pointer;">
            <input type="date" id="ltms-bk-date-to"
                   class="ltms-btn ltms-btn-outline ltms-btn-sm"
                   placeholder="<?php esc_attr_e( 'Hasta', 'ltms' ); ?>"
                   style="cursor:pointer;">
            <a href="#" id="ltms-bk-export-csv" class="ltms-btn ltms-btn-outline ltms-btn-sm" style="text-decoration:none;">
                📥 <?php esc_html_e( 'Exportar CSV', 'ltms' ); ?>
            </a>
        </div>
    </div>

    <!-- Tarjetas de resumen -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:20px;">
        <div class="ltms-card" style="text-align:center;padding:16px 12px;">
            <div style="font-size:1.7rem;font-weight:700;color:#1a5276;" id="ltms-bk-stat-total">—</div>
            <div style="font-size:0.75rem;color:#6b7280;margin-top:4px;"><?php esc_html_e( 'Total reservas', 'ltms' ); ?></div>
        </div>
        <div class="ltms-card" style="text-align:center;padding:16px 12px;">
            <div style="font-size:1.7rem;font-weight:700;color:#f59e0b;" id="ltms-bk-stat-pending">—</div>
            <div style="font-size:0.75rem;color:#6b7280;margin-top:4px;"><?php esc_html_e( 'Pendientes', 'ltms' ); ?></div>
        </div>
        <div class="ltms-card" style="text-align:center;padding:16px 12px;">
            <div style="font-size:1.7rem;font-weight:700;color:#16a34a;" id="ltms-bk-stat-confirmed">—</div>
            <div style="font-size:0.75rem;color:#6b7280;margin-top:4px;"><?php esc_html_e( 'Confirmadas', 'ltms' ); ?></div>
        </div>
        <div class="ltms-card" style="text-align:center;padding:16px 12px;">
            <div style="font-size:1.7rem;font-weight:700;color:#1a5276;" id="ltms-bk-stat-revenue">—</div>
            <div style="font-size:0.75rem;color:#6b7280;margin-top:4px;"><?php esc_html_e( 'Ingresos (neto)', 'ltms' ); ?></div>
        </div>
    </div>

    <!-- Tabla de reservas -->
    <div class="ltms-card">
        <div class="ltms-card-body ltms-table-scroll" style="padding:0;">
            <table class="ltms-dtable" style="width:100%;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( '#', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Producto', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Huésped', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Check-in', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Check-out', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Huéspedes', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Total', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Acciones', 'ltms' ); ?></th>
                    </tr>
                </thead>
                <tbody id="ltms-bk-tbody">
                    <tr><td colspan="9" style="text-align:center;padding:30px;color:#9ca3af;">
                        <?php esc_html_e( 'Cargando reservas...', 'ltms' ); ?>
                    </td></tr>
                </tbody>
            </table>
        </div>
        <!-- Paginación -->
        <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-top:1px solid #e5e7eb;">
            <span style="font-size:0.8rem;color:#6b7280;" id="ltms-bk-count-label"></span>
            <div style="display:flex;gap:8px;">
                <button class="ltms-btn ltms-btn-outline ltms-btn-sm" id="ltms-bk-prev" disabled>
                    ← <?php esc_html_e( 'Anterior', 'ltms' ); ?>
                </button>
                <button class="ltms-btn ltms-btn-outline ltms-btn-sm" id="ltms-bk-next" disabled>
                    <?php esc_html_e( 'Siguiente', 'ltms' ); ?> →
                </button>
            </div>
        </div>
    </div>

    </div><!-- #ltms-bk-reservas -->

    <!-- TAB 2: Temporadas (M-BOOKING-PLAN-02) -->
    <div id="ltms-bk-seasons" style="display:none;">
        <div class="ltms-view-header" style="margin-bottom:16px;">
            <h2><?php esc_html_e( 'Temporadas y modificadores de precio', 'ltms' ); ?></h2>
            <button type="button" class="ltms-btn ltms-btn-primary ltms-btn-sm" id="ltms-season-add-btn">
                + <?php esc_html_e( 'Nueva temporada', 'ltms' ); ?>
            </button>
        </div>
        <p style="font-size:.85rem;color:#6b7280;margin-bottom:8px;">
            <?php esc_html_e( 'Define períodos donde tus precios suben o bajan automáticamente según la época del año.', 'ltms' ); ?>
        </p>
        <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:.82rem;color:#0369a1;line-height:1.6;">
            💡 <strong><?php esc_html_e( 'Ejemplos:', 'ltms' ); ?></strong>
            <?php esc_html_e( 'Semana Santa → Modificador 1.50 (precios +50% más caros). Temporada baja → Modificador 0.80 (precios 20% más baratos). Sin cambio → Modificador 1.00.', 'ltms' ); ?>
        </div>
        <div class="ltms-card" style="margin-bottom:20px;overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;">
                <thead><tr style="background:#f9fafb;font-size:.8rem;color:#374151;">
                    <th style="padding:10px 12px;text-align:left;"><?php esc_html_e( 'Nombre', 'ltms' ); ?></th>
                    <th style="padding:10px 12px;text-align:left;"><?php esc_html_e( 'Producto', 'ltms' ); ?></th>
                    <th style="padding:10px 12px;text-align:left;"><?php esc_html_e( 'Desde', 'ltms' ); ?></th>
                    <th style="padding:10px 12px;text-align:left;"><?php esc_html_e( 'Hasta', 'ltms' ); ?></th>
                    <th style="padding:10px 12px;text-align:left;"><?php esc_html_e( 'Modificador', 'ltms' ); ?></th>
                    <th style="padding:10px 12px;"></th>
                </tr></thead>
                <tbody id="ltms-seasons-tbody">
                    <tr><td colspan="6" style="text-align:center;padding:30px;color:#9ca3af;">
                        <?php esc_html_e( 'Cargando...', 'ltms' ); ?>
                    </td></tr>
                </tbody>
            </table>
        </div>
        <div id="ltms-season-form" class="ltms-card" style="display:none;padding:20px;margin-bottom:20px;">
            <h4 id="ltms-season-form-title" style="margin:0 0 16px;font-size:.95rem;"><?php esc_html_e( 'Nueva temporada', 'ltms' ); ?></h4>
            <input type="hidden" id="ltms-season-id" value="0">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                <div style="grid-column:span 2;">
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:5px;"><?php esc_html_e( 'Alojamiento', 'ltms' ); ?></label>
                    <select id="ltms-season-product" class="ltms-form-control">
                        <option value=""><?php esc_html_e( '— Cargando alojamientos... —', 'ltms' ); ?></option>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:5px;"><?php esc_html_e( 'Nombre *', 'ltms' ); ?></label>
                    <input type="text" id="ltms-season-name" class="ltms-form-control" placeholder="<?php esc_attr_e( 'Ej: Semana Santa', 'ltms' ); ?>">
                </div>
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:5px;"><?php esc_html_e( 'Modificador *', 'ltms' ); ?></label>
                    <input type="number" id="ltms-season-modifier" class="ltms-form-control" step="0.05" min="0.1" max="10" value="1.50">
                    <span style="font-size:.75rem;color:#6b7280;margin-top:4px;display:block;"><?php esc_html_e( '1.50 = +50% · 0.80 = −20% · 1.00 = sin cambio', 'ltms' ); ?></span>
                </div>
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:5px;"><?php esc_html_e( 'Fecha inicio *', 'ltms' ); ?></label>
                    <input type="date" id="ltms-season-from" class="ltms-form-control">
                </div>
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:5px;"><?php esc_html_e( 'Fecha fin *', 'ltms' ); ?></label>
                    <input type="date" id="ltms-season-to" class="ltms-form-control">
                </div>
            </div>
            <div id="ltms-season-notice" style="display:none;margin-bottom:12px;padding:10px;border-radius:6px;font-size:.85rem;"></div>
            <div style="display:flex;gap:8px;">
                <button type="button" class="ltms-btn ltms-btn-primary" id="ltms-season-save-btn"><?php esc_html_e( 'Guardar', 'ltms' ); ?></button>
                <button type="button" class="ltms-btn ltms-btn-outline" id="ltms-season-cancel-btn"><?php esc_html_e( 'Cancelar', 'ltms' ); ?></button>
            </div>
        </div>
    </div><!-- #ltms-bk-seasons -->

    <!-- TAB 3: Políticas de cancelación (M-BOOKING-PLAN-03) -->
    <div id="ltms-bk-policies" style="display:none;">
        <div class="ltms-view-header" style="margin-bottom:16px;">
            <h2><?php esc_html_e( 'Políticas de cancelación', 'ltms' ); ?></h2>
            <button type="button" class="ltms-btn ltms-btn-primary ltms-btn-sm" id="ltms-policy-add-btn">
                + <?php esc_html_e( 'Nueva política', 'ltms' ); ?>
            </button>
        </div>
        <p style="font-size:.85rem;color:#6b7280;margin-bottom:8px;">
            <?php esc_html_e( 'Define cuándo y cuánto le devuelves al huésped si cancela su reserva.', 'ltms' ); ?>
            <?php esc_html_e( 'La política marcada "Por defecto" aplica a alojamientos sin política asignada.', 'ltms' ); ?>
        </p>
        <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:.82rem;line-height:1.7;">
            <strong style="color:#15803d;display:block;margin-bottom:6px;">💡 <?php esc_html_e( '¿Qué tipo elegir?', 'ltms' ); ?></strong>
            <table style="width:100%;border-collapse:collapse;">
                <tr>
                    <td style="padding:3px 8px 3px 0;font-weight:600;white-space:nowrap;color:#374151;"><?php esc_html_e( 'Flexible', 'ltms' ); ?></td>
                    <td style="padding:3px 0;color:#6b7280;"><?php esc_html_e( 'Reembolso completo si cancela con +24h. Sin reembolso después. Atrae más reservas.', 'ltms' ); ?></td>
                </tr>
                <tr>
                    <td style="padding:3px 8px 3px 0;font-weight:600;white-space:nowrap;color:#374151;"><?php esc_html_e( 'Moderada', 'ltms' ); ?></td>
                    <td style="padding:3px 0;color:#6b7280;"><?php esc_html_e( 'Reembolso completo con +72h, parcial entre 24–72h. Balance entre flexibilidad e ingresos.', 'ltms' ); ?></td>
                </tr>
                <tr>
                    <td style="padding:3px 8px 3px 0;font-weight:600;white-space:nowrap;color:#374151;"><?php esc_html_e( 'Estricta', 'ltms' ); ?></td>
                    <td style="padding:3px 0;color:#6b7280;"><?php esc_html_e( 'Sin reembolso o solo con muchos días de anticipación. Protege ingresos en temporada alta.', 'ltms' ); ?></td>
                </tr>
            </table>
        </div>
        <div id="ltms-policies-list" style="display:grid;gap:14px;margin-bottom:20px;">
            <div style="text-align:center;padding:30px;color:#9ca3af;"><?php esc_html_e( 'Cargando...', 'ltms' ); ?></div>
        </div>
        <div id="ltms-policy-form" class="ltms-card" style="display:none;padding:20px;">
            <h4 id="ltms-policy-form-title" style="margin:0 0 16px;font-size:.95rem;"><?php esc_html_e( 'Nueva política', 'ltms' ); ?></h4>
            <input type="hidden" id="ltms-policy-id" value="0">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:5px;"><?php esc_html_e( 'Nombre *', 'ltms' ); ?></label>
                    <input type="text" id="ltms-policy-name" class="ltms-form-control" placeholder="<?php esc_attr_e( 'Ej: Flexible, Estricta', 'ltms' ); ?>">
                </div>
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:5px;"><?php esc_html_e( 'Tipo', 'ltms' ); ?></label>
                    <select id="ltms-policy-type" class="ltms-form-control">
                        <option value="flexible"><?php esc_html_e( 'Flexible', 'ltms' ); ?></option>
                        <option value="moderate"><?php esc_html_e( 'Moderada', 'ltms' ); ?></option>
                        <option value="strict"><?php esc_html_e( 'Estricta', 'ltms' ); ?></option>
                        <option value="non_refundable"><?php esc_html_e( 'Sin reembolso', 'ltms' ); ?></option>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:5px;"><?php esc_html_e( 'Cancelación gratuita (horas)', 'ltms' ); ?></label>
                    <input type="number" id="ltms-policy-free-hours" class="ltms-form-control" min="0" step="1" value="24">
                    <span style="font-size:.75rem;color:#6b7280;margin-top:4px;display:block;"><?php esc_html_e( 'Ej: 24 = reembolso completo si cancela con más de 24h de anticipación.', 'ltms' ); ?></span>
                </div>
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:5px;"><?php esc_html_e( '% reembolso parcial', 'ltms' ); ?></label>
                    <input type="number" id="ltms-policy-partial-pct" class="ltms-form-control" min="0" max="100" step="1" value="50">
                    <span style="font-size:.75rem;color:#6b7280;margin-top:4px;display:block;"><?php esc_html_e( 'Ej: 50 = devuelve la mitad. 0 = sin reembolso parcial (política Flexible).', 'ltms' ); ?></span>
                </div>
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:5px;"><?php esc_html_e( 'Ventana reembolso parcial (horas)', 'ltms' ); ?></label>
                    <input type="number" id="ltms-policy-partial-hours" class="ltms-form-control" min="0" step="1" value="48">
                </div>
                <div style="display:flex;align-items:center;gap:8px;padding-top:22px;">
                    <input type="checkbox" id="ltms-policy-default" value="1">
                    <label for="ltms-policy-default" style="font-size:.85rem;cursor:pointer;"><?php esc_html_e( 'Política por defecto', 'ltms' ); ?></label>
                </div>
            </div>
            <div id="ltms-policy-notice" style="display:none;margin-bottom:12px;padding:10px;border-radius:6px;font-size:.85rem;"></div>
            <div style="display:flex;gap:8px;">
                <button type="button" class="ltms-btn ltms-btn-primary" id="ltms-policy-save-btn"><?php esc_html_e( 'Guardar', 'ltms' ); ?></button>
                <button type="button" class="ltms-btn ltms-btn-outline" id="ltms-policy-cancel-btn"><?php esc_html_e( 'Cancelar', 'ltms' ); ?></button>
            </div>
        </div>
    </div><!-- #ltms-bk-policies -->

    <!-- v2.9.93 P2: Calendar view -->
    <div id="ltms-bk-calendar" style="display:none;">
        <div class="ltms-view-header">
            <h2>🗓️ <?php esc_html_e( 'Calendario de Reservas', 'ltms' ); ?></h2>
            <div style="display:flex;gap:8px;align-items:center;">
                <button type="button" id="ltms-cal-prev" class="ltms-btn ltms-btn-outline ltms-btn-sm" aria-label="<?php esc_attr_e( 'Mes anterior', 'ltms' ); ?>">‹</button>
                <span id="ltms-cal-month-label" style="font-weight:600;font-size:0.9rem;min-width:160px;text-align:center;"></span>
                <button type="button" id="ltms-cal-next" class="ltms-btn ltms-btn-outline ltms-btn-sm" aria-label="<?php esc_attr_e( 'Mes siguiente', 'ltms' ); ?>">›</button>
                <button type="button" id="ltms-cal-today" class="ltms-btn ltms-btn-outline ltms-btn-sm"><?php esc_html_e( 'Hoy', 'ltms' ); ?></button>
            </div>
        </div>
        <div class="ltms-card">
            <div class="ltms-card-body" style="overflow-x:auto;">
                <table id="ltms-cal-table" style="width:100%;border-collapse:collapse;font-size:0.8rem;">
                    <thead>
                        <tr style="background:#f9fafb;">
                            <th style="padding:8px;text-align:center;border:1px solid #e5e7eb;width:14.28%;">Lun</th>
                            <th style="padding:8px;text-align:center;border:1px solid #e5e7eb;width:14.28%;">Mar</th>
                            <th style="padding:8px;text-align:center;border:1px solid #e5e7eb;width:14.28%;">Mié</th>
                            <th style="padding:8px;text-align:center;border:1px solid #e5e7eb;width:14.28%;">Jue</th>
                            <th style="padding:8px;text-align:center;border:1px solid #e5e7eb;width:14.28%;">Vie</th>
                            <th style="padding:8px;text-align:center;border:1px solid #e5e7eb;width:14.28%;">Sáb</th>
                            <th style="padding:8px;text-align:center;border:1px solid #e5e7eb;width:14.28%;">Dom</th>
                        </tr>
                    </thead>
                    <tbody id="ltms-cal-tbody"></tbody>
                </table>
            </div>
        </div>
        <!-- Legend -->
        <div style="display:flex;gap:16px;margin-top:12px;font-size:0.75rem;color:#6b7280;">
            <span><span style="display:inline-block;width:12px;height:12px;background:#dbeafe;border-radius:3px;margin-right:4px;"></span> Pendiente</span>
            <span><span style="display:inline-block;width:12px;height:12px;background:#d1fae5;border-radius:3px;margin-right:4px;"></span> Confirmada</span>
            <span><span style="display:inline-block;width:12px;height:12px;background:#fef3c7;border-radius:3px;margin-right:4px;"></span> En curso</span>
            <span><span style="display:inline-block;width:12px;height:12px;background:#e5e7eb;border-radius:3px;margin-right:4px;"></span> Completada</span>
            <span><span style="display:inline-block;width:12px;height:12px;background:#fee2e2;border-radius:3px;margin-right:4px;"></span> Cancelada</span>
        </div>
    </div><!-- #ltms-bk-calendar -->

</div><!-- .ltms-view-pad -->

<!-- Modal: Detalle de reserva -->
<div class="ltms-modal" id="ltms-modal-booking-detail" role="dialog" aria-modal="true" aria-labelledby="ltms-bk-modal-title">
    <div class="ltms-modal-backdrop"></div>
    <div class="ltms-modal-inner" style="max-width:560px;background:#fff;border-radius:12px;padding:28px;margin:auto;position:relative;z-index:1;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;">
            <h3 id="ltms-bk-modal-title" style="margin:0;font-size:1.1rem;"><?php esc_html_e( 'Detalle de Reserva', 'ltms' ); ?></h3>
            <button type="button" class="ltms-modal-close" aria-label="<?php esc_attr_e( 'Cerrar', 'ltms' ); ?>" style="background:none;border:none;cursor:pointer;font-size:1.1rem;">✕</button>
        </div>
        <div id="ltms-bk-modal-body" style="font-size:0.9rem;line-height:1.7;"></div>
        <p id="ltms-bk-cancel-unavailable-note" style="display:none;font-size:0.8rem;color:#9ca3af;margin-top:14px;margin-bottom:0;">
            <?php esc_html_e( 'Esta reserva ya no se puede cancelar desde aquí porque está completada o ya fue cancelada.', 'ltms' ); ?>
        </p>
        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:20px;">
            <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm ltms-modal-close">
                <?php esc_html_e( 'Cerrar', 'ltms' ); ?>
            </button>
            <button type="button" class="ltms-btn ltms-btn-sm" style="background:#dc2626;color:#fff;" id="ltms-bk-cancel-btn">
                <?php esc_html_e( 'Cancelar Reserva', 'ltms' ); ?>
            </button>
        </div>
    </div>
</div>

<!-- Modal: Confirmar cancelación de reserva -->
<div class="ltms-modal" id="ltms-modal-booking-cancel" role="dialog" aria-modal="true" aria-labelledby="ltms-bk-cancel-title">
    <div class="ltms-modal-backdrop"></div>
    <div class="ltms-modal-inner" style="max-width:440px;background:#fff;border-radius:12px;padding:28px;margin:auto;position:relative;z-index:1;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;">
            <h3 id="ltms-bk-cancel-title" style="margin:0;font-size:1.1rem;"><?php esc_html_e( 'Cancelar Reserva', 'ltms' ); ?></h3>
            <button type="button" class="ltms-modal-close" aria-label="<?php esc_attr_e( 'Cerrar', 'ltms' ); ?>" style="background:none;border:none;cursor:pointer;font-size:1.1rem;">✕</button>
        </div>
        <p style="color:#6b7280;margin-bottom:12px;">
            <?php esc_html_e( 'Esta acción generará un reembolso según la política de cancelación del producto y notificará al huésped.', 'ltms' ); ?>
        </p>
        <label style="display:block;font-size:0.85rem;font-weight:600;margin-bottom:6px;">
            <?php esc_html_e( 'Motivo de cancelación', 'ltms' ); ?>
        </label>
        <textarea id="ltms-bk-cancel-reason" rows="3"
                  placeholder="<?php esc_attr_e( 'Ej: Mantenimiento programado, overbooking, etc.', 'ltms' ); ?>"
                  style="width:100%;border:1px solid #d1d5db;border-radius:6px;padding:8px;font-size:0.875rem;resize:vertical;"></textarea>
        <div id="ltms-bk-cancel-notice" style="margin-top:8px;font-size:0.82rem;"></div>
        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:20px;">
            <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm ltms-modal-close">
                <?php esc_html_e( 'Volver', 'ltms' ); ?>
            </button>
            <button type="button" class="ltms-btn ltms-btn-sm" style="background:#dc2626;color:#fff;" id="ltms-bk-confirm-cancel-btn">
                <?php esc_html_e( 'Confirmar Cancelación', 'ltms' ); ?>
            </button>
        </div>
    </div>
</div>

<!-- Modal: Confirmar eliminación (reutilizable para Temporada y Política) -->
<div class="ltms-modal" id="ltms-modal-booking-confirm-delete" role="dialog" aria-modal="true" aria-labelledby="ltms-bk-confirm-delete-title">
    <div class="ltms-modal-backdrop"></div>
    <div class="ltms-modal-inner" style="max-width:400px;background:#fff;border-radius:12px;padding:28px;margin:auto;position:relative;z-index:1;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;">
            <h3 id="ltms-bk-confirm-delete-title" style="margin:0;font-size:1.05rem;"><?php esc_html_e( '¿Eliminar?', 'ltms' ); ?></h3>
            <button type="button" class="ltms-modal-close" aria-label="<?php esc_attr_e( 'Cerrar', 'ltms' ); ?>" style="background:none;border:none;cursor:pointer;font-size:1.1rem;">✕</button>
        </div>
        <p id="ltms-bk-confirm-delete-body" style="color:#6b7280;margin:0;"></p>
        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:20px;">
            <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm ltms-modal-close">
                <?php esc_html_e( 'Cancelar', 'ltms' ); ?>
            </button>
            <button type="button" class="ltms-btn ltms-btn-sm" style="background:#dc2626;color:#fff;" id="ltms-bk-confirm-delete-btn">
                <?php esc_html_e( 'Sí, eliminar', 'ltms' ); ?>
            </button>
        </div>
    </div>
</div>



<?php
// FASE2B P0 FIX (CSP): inline <script> moved to external assets/js/ltms-bookings.js
wp_enqueue_script( 'ltms-bookings', LTMS_ASSETS_URL . 'js/ltms-bookings.js', [ 'jquery' ], LTMS_VERSION, true );
?>

