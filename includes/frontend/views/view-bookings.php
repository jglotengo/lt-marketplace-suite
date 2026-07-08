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

<script>
(function($){
    'use strict';

    // ── Estado ─────────────────────────────────────────────────────────────
    var BK = {
        page: 1,
        perPage: 20,
        total: 0,
        activeBookingId: null,
        stats: { total:0, pending:0, confirmed:0, revenue:0 },

        statusLabels: {
            pending:    '⏳ <?php esc_html_e("Pendiente","ltms"); ?>',
            confirmed:  '✅ <?php esc_html_e("Confirmada","ltms"); ?>',
            checked_in: '🏠 <?php esc_html_e("En curso","ltms"); ?>',
            completed:  '🎉 <?php esc_html_e("Completada","ltms"); ?>',
            cancelled:  '❌ <?php esc_html_e("Cancelada","ltms"); ?>',
        },
        statusColors: {
            pending:    '#f59e0b',
            confirmed:  '#16a34a',
            checked_in: '#2563eb',
            completed:  '#6b7280',
            cancelled:  '#dc2626',
        }
    };

    // ── Helpers ────────────────────────────────────────────────────────────
    function fmtDate(d) {
        if (!d || d === '0000-00-00') return '—';
        var p = d.split('-');
        return p[2] + '/' + p[1] + '/' + p[0];
    }
    function fmtCOP(v) {
        return 'COP ' + parseFloat(v||0).toLocaleString('es-CO', {minimumFractionDigits:0});
    }
    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        var $div = $('<div/>');
        $div.text(String(text));
        return $div.html().replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function statusBadge(s) {
        var lbl = BK.statusLabels[s] || s;
        var col = BK.statusColors[s] || '#6b7280';
        return '<span style="background:' + col + '18;color:' + col + ';font-weight:600;font-size:0.75rem;padding:3px 8px;border-radius:20px;white-space:nowrap;">' + lbl + '</span>';
    }

    // ── Carga principal ────────────────────────────────────────────────────
    function loadBookings() {
        $('#ltms-bk-tbody').html('<tr><td colspan="9" style="text-align:center;padding:30px;color:#9ca3af;"><?php esc_html_e("Cargando…","ltms"); ?></td></tr>');

        $.ajax({
            url: ltmsDashboard.ajax_url,
            method: 'POST',
            data: {
                action:      'ltms_get_vendor_bookings',
                nonce:       ltmsDashboard.nonce,
                status:      $('#ltms-bk-status-filter').val(),
                date_from:   $('#ltms-bk-date-from').val(),
                date_to:     $('#ltms-bk-date-to').val(),
                page:        BK.page,
                per_page:    BK.perPage,
            },
            success: function(res) {
                if (!res.success) {
                    $('#ltms-bk-tbody').html('<tr><td colspan="9" style="text-align:center;padding:20px;color:#dc2626;">' + escapeHtml(res.data || '<?php esc_html_e("Error al cargar reservas.","ltms"); ?>') + '</td></tr>');
                    return;
                }
                var d = res.data;
                BK.total = d.total || 0;
                renderTable(d.bookings || []);
                renderStats(d.stats || {});
                renderPagination();
            },
            error: function() {
                $('#ltms-bk-tbody').html('<tr><td colspan="9" style="text-align:center;padding:20px;color:#dc2626;"><?php esc_html_e("Error de conexión.","ltms"); ?></td></tr>');
            }
        });
    }

    function renderTable(bookings) {
        if (!bookings.length) {
            $('#ltms-bk-tbody').html('<tr><td colspan="9" style="text-align:center;padding:30px;color:#9ca3af;">🏨 <?php esc_html_e("Sin reservas para los filtros seleccionados.","ltms"); ?></td></tr>');
            return;
        }
        var rows = '';
        $.each(bookings, function(i, b) {
            var canCancel = (b.status === 'pending' || b.status === 'confirmed');
            var bId = escapeHtml(b.id);
            rows += '<tr>'
                + '<td><a href="#" class="ltms-bk-detail-link" data-id="' + bId + '" style="font-weight:600;color:#1a5276;">#' + bId + '</a></td>'
                + '<td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + escapeHtml(b.product_name || '—') + '</td>'
                + '<td>' + escapeHtml(b.customer_name || '—') + '</td>'
                + '<td>' + escapeHtml(fmtDate(b.checkin_date)) + '</td>'
                + '<td>' + escapeHtml(fmtDate(b.checkout_date)) + '</td>'
                + '<td style="text-align:center;">' + escapeHtml(b.guests || 1) + '</td>'
                + '<td style="white-space:nowrap;">' + escapeHtml(fmtCOP(b.total_price)) + '</td>'
                + '<td>' + statusBadge(b.status) + '</td>'
                + '<td style="white-space:nowrap;">'
                +   '<button class="ltms-btn ltms-btn-outline ltms-btn-xs ltms-bk-detail-link" data-id="' + bId + '" style="margin-right:4px;">Ver</button>'
                +   (canCancel ? '<button class="ltms-btn ltms-btn-xs ltms-bk-quick-cancel" data-id="' + bId + '" style="background:#dc2626;color:#fff;border-color:#dc2626;">Cancelar</button>' : '')
                + '</td>'
                + '</tr>';
        });
        $('#ltms-bk-tbody').html(rows);
    }

    function renderStats(s) {
        $('#ltms-bk-stat-total').text(s.total || 0);
        $('#ltms-bk-stat-pending').text(s.pending || 0);
        $('#ltms-bk-stat-confirmed').text(s.confirmed || 0);
        $('#ltms-bk-stat-revenue').text(fmtCOP(s.vendor_net || 0));
    }

    function renderPagination() {
        var pages = Math.ceil(BK.total / BK.perPage) || 1;
        var from  = Math.min( (BK.page - 1) * BK.perPage + 1, BK.total );
        var to    = Math.min( BK.page * BK.perPage, BK.total );
        $('#ltms-bk-count-label').text(BK.total
            ? from + '–' + to + ' de ' + BK.total
            : '<?php esc_html_e("Sin resultados","ltms"); ?>'
        );
        $('#ltms-bk-prev').prop('disabled', BK.page <= 1);
        $('#ltms-bk-next').prop('disabled', BK.page >= pages);
    }

    // ── Detalle ────────────────────────────────────────────────────────────
    function openDetail(bookingId) {
        BK.activeBookingId = bookingId;
        $('#ltms-bk-modal-title').text('<?php esc_html_e("Reserva","ltms"); ?> #' + bookingId);
        $('#ltms-bk-modal-body').html('<div style="text-align:center;padding:20px;color:#9ca3af;"><?php esc_html_e("Cargando…","ltms"); ?></div>');
        LTMS.Modal.open('ltms-modal-booking-detail');

        $.ajax({
            url: ltmsDashboard.ajax_url,
            method: 'POST',
            data: { action:'ltms_get_vendor_booking_detail', nonce:ltmsDashboard.nonce, booking_id: bookingId },
            success: function(res) {
                if (!res.success) { $('#ltms-bk-modal-body').html('<p style="color:#dc2626;">' + escapeHtml(res.data||'Error') + '</p>'); return; }
                var b = res.data;
                var canCancel = (b.status === 'pending' || b.status === 'confirmed');
                $('#ltms-bk-cancel-btn').toggle(canCancel);
                $('#ltms-bk-cancel-unavailable-note').toggle(!canCancel);

                var nights = 0;
                if (b.checkin_date && b.checkout_date && b.checkin_date !== '0000-00-00') {
                    var d1 = new Date(b.checkin_date), d2 = new Date(b.checkout_date);
                    nights = Math.max(0, Math.round((d2 - d1) / 86400000));
                }

                $('#ltms-bk-modal-body').html(
                    '<table style="width:100%;border-collapse:collapse;font-size:0.875rem;">' +
                    row('<?php esc_html_e("Producto","ltms"); ?>', '<strong>' + escapeHtml(b.product_name||'—') + '</strong>') +
                    row('<?php esc_html_e("Estado","ltms"); ?>', statusBadge(b.status)) +
                    row('<?php esc_html_e("Huésped","ltms"); ?>', escapeHtml(b.customer_name||'—') + (b.customer_email ? ' &lt;' + escapeHtml(b.customer_email) + '&gt;' : '')) +
                    row('<?php esc_html_e("Check-in","ltms"); ?>', escapeHtml(fmtDate(b.checkin_date) + (b.checkin_time ? ' · ' + b.checkin_time : ''))) +
                    row('<?php esc_html_e("Check-out","ltms"); ?>', escapeHtml(fmtDate(b.checkout_date) + (b.checkout_time ? ' · ' + b.checkout_time : ''))) +
                    row('<?php esc_html_e("Noches","ltms"); ?>', nights || '—') +
                    row('<?php esc_html_e("Huéspedes","ltms"); ?>', escapeHtml(b.guests || 1)) +
                    row('<?php esc_html_e("Total","ltms"); ?>', escapeHtml(fmtCOP(b.total_price))) +
                    row('<?php esc_html_e("Neto vendedor","ltms"); ?>', escapeHtml(fmtCOP(b.vendor_net))) +
                    row('<?php esc_html_e("Modo de pago","ltms"); ?>', escapeHtml(b.payment_mode || '—')) +
                    row('<?php esc_html_e("Orden WC","ltms"); ?>', b.wc_order_id ? '#' + escapeHtml(b.wc_order_id) : '—') +
                    (b.notes ? row('<?php esc_html_e("Notas","ltms"); ?>', escapeHtml(b.notes)) : '') +
                    (b.cancel_notes ? row('<?php esc_html_e("Motivo cancel.","ltms"); ?>', '<span style="color:#dc2626;">' + escapeHtml(b.cancel_notes) + '</span>') : '') +
                    '</table>'
                );
            }
        });
    }

    function row(label, val) {
        return '<tr style="border-bottom:1px solid #f3f4f6;">' +
            '<td style="padding:8px 4px;color:#6b7280;font-weight:600;white-space:nowrap;width:40%;">' + label + '</td>' +
            '<td style="padding:8px 4px;">' + val + '</td>' +
            '</tr>';
    }

    // ── Cancelación ────────────────────────────────────────────────────────
    function openCancel(bookingId) {
        BK.activeBookingId = bookingId;
        $('#ltms-bk-cancel-reason').val('');
        $('#ltms-bk-cancel-notice').text('').css('color','');
        LTMS.Modal.open('ltms-modal-booking-cancel');
    }

    function doCancel() {
        var reason = $.trim($('#ltms-bk-cancel-reason').val());
        if (!reason) {
            $('#ltms-bk-cancel-notice').css('color','#dc2626').text('<?php esc_html_e("Por favor indica el motivo de la cancelación.","ltms"); ?>');
            return;
        }
        $('#ltms-bk-confirm-cancel-btn').prop('disabled', true).text('<?php esc_html_e("Procesando…","ltms"); ?>');
        $.ajax({
            url: ltmsDashboard.ajax_url,
            method: 'POST',
            data: {
                action:     'ltms_vendor_cancel_booking',
                nonce:      ltmsDashboard.nonce,
                booking_id: BK.activeBookingId,
                reason:     reason,
            },
            success: function(res) {
                $('#ltms-bk-confirm-cancel-btn').prop('disabled', false).text('<?php esc_html_e("Confirmar Cancelación","ltms"); ?>');
                if (res.success) {
                    LTMS.Modal.close('ltms-modal-booking-cancel');
                    LTMS.Modal.close('ltms-modal-booking-detail');
                    BK.page = 1;
                    loadBookings();
                } else {
                    $('#ltms-bk-cancel-notice').css('color','#dc2626').text(res.data || '<?php esc_html_e("Error al cancelar.","ltms"); ?>');
                }
            },
            error: function() {
                $('#ltms-bk-confirm-cancel-btn').prop('disabled', false).text('<?php esc_html_e("Confirmar Cancelación","ltms"); ?>');
                $('#ltms-bk-cancel-notice').css('color','#dc2626').text('<?php esc_html_e("Error de conexión.","ltms"); ?>');
            }
        });
    }

    // ── Eventos ────────────────────────────────────────────────────────────
    $(document).on('click', '#ltms-view-bookings .ltms-bk-detail-link', function(e){
        e.preventDefault();
        openDetail($(this).data('id'));
    });

    $(document).on('click', '#ltms-view-bookings .ltms-bk-quick-cancel', function(e){
        e.preventDefault();
        openCancel($(this).data('id'));
    });

    $('#ltms-bk-cancel-btn').on('click', function(){
        LTMS.Modal.close('ltms-modal-booking-detail');
        openCancel(BK.activeBookingId);
    });

    $('#ltms-bk-confirm-cancel-btn').on('click', doCancel);

    // Paginación
    $('#ltms-bk-prev').on('click', function(){ if (BK.page > 1) { BK.page--; loadBookings(); } });
    $('#ltms-bk-next').on('click', function(){
        var pages = Math.ceil(BK.total / BK.perPage);
        if (BK.page < pages) { BK.page++; loadBookings(); }
    });

    // Filtros
    var filterTimer;
    $('#ltms-bk-status-filter, #ltms-bk-date-from, #ltms-bk-date-to').on('change', function(){
        clearTimeout(filterTimer);
        filterTimer = setTimeout(function(){ BK.page = 1; loadBookings(); }, 300);
    });

    // M-BOOKING-UI-02: exportar CSV respetando los filtros activos.
    $('#ltms-bk-export-csv').on('click', function(e){
        e.preventDefault();
        var params = new URLSearchParams();
        params.append('action', 'ltms_export_vendor_bookings_csv');
        params.append('nonce', ltmsDashboard.export_nonce || ltmsDashboard.nonce);
        var status = $('#ltms-bk-status-filter').val();
        var from   = $('#ltms-bk-date-from').val();
        var to     = $('#ltms-bk-date-to').val();
        if (status) params.append('status', status);
        if (from)   params.append('date_from', from);
        if (to)     params.append('date_to', to);
        window.location.href = ltmsDashboard.ajax_url + '?' + params.toString();
    });

    // ── Inicialización al entrar en la pestaña ─────────────────────────────
    $(document).on('click', '[data-view="bookings"]', function(){
        if (BK.total === 0 && BK.page === 1) loadBookings();
    });

    // Si la pestaña se abre directamente vía hash
    if ( window.location.hash === '#bookings' ) {
        setTimeout(loadBookings, 400);
    }

    // M-FIX-BOOKINGS-03: en la página standalone /mis-reservas/ no existe ningún
    // elemento [data-view="bookings"] en el que el vendedor pueda hacer clic —
    // esta vista ES la página completa, no una pestaña del SPA — así que
    // loadBookings() nunca se disparaba y la tabla quedaba pegada en
    // "Cargando reservas..." indefinidamente. Si no hay nav del SPA en el DOM,
    // cargamos de una vez. Dentro de /panel-vendedor/ esta condición es falsa
    // (el nav sí existe) y se preserva la carga perezosa al hacer clic.
    if ( $('[data-view="bookings"]').length === 0 ) {
        loadBookings();
    }

})(jQuery);
</script>

<script>
/* global jQuery, ltmsDashboard */
(function($) {
    'use strict';

    var productsLoaded = false;

    $(document).on('click', '.ltms-booking-tab', function() {
        var target = $(this).data('target');
        $('.ltms-booking-tab').css({ color: '#6b7280', 'border-bottom-color': 'transparent' }).removeClass('ltms-booking-tab-active');
        $(this).css({ color: '#1a5276', 'border-bottom-color': '#1a5276' }).addClass('ltms-booking-tab-active');
        $('#ltms-bk-reservas, #ltms-bk-seasons, #ltms-bk-policies, #ltms-bk-calendar').hide();
        $('#' + target).show();
        if (target === 'ltms-bk-seasons') {
            if (!productsLoaded) ltmsLoadVendorProducts();
            if (!$('#ltms-seasons-tbody').data('loaded')) ltmsLoadSeasons();
        }
        if (target === 'ltms-bk-policies' && !$('#ltms-policies-list').data('loaded')) ltmsLoadPolicies();
        // v2.9.93 P2: Load calendar
        if (target === 'ltms-bk-calendar') ltmsLoadCalendar();
    });

    // v2.9.93 P2: Calendar logic
    var calDate = new Date();
    var calBookings = [];

    function ltmsLoadCalendar() {
        calDate.setDate(1);
        renderCalendar();
        // Fetch bookings for this month
        var monthStr = calDate.getFullYear() + '-' + String(calDate.getMonth() + 1).padStart(2, '0');
        $.ajax({
            url: ltmsDashboard.ajax_url, method: 'POST',
            data: { action: 'ltms_get_vendor_bookings', nonce: ltmsDashboard.nonce, per_page: 50 },
            success: function(res) {
                if (res.success && res.data && res.data.bookings) {
                    calBookings = res.data.bookings;
                    renderCalendar();
                }
            }
        });
    }

    function renderCalendar() {
        var year = calDate.getFullYear();
        var month = calDate.getMonth();
        var monthNames = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
        $('#ltms-cal-month-label').text(monthNames[month] + ' ' + year);

        var firstDay = new Date(year, month, 1).getDay();
        // Convert Sunday(0) to Monday-based (0=Mon, 6=Sun)
        firstDay = firstDay === 0 ? 6 : firstDay - 1;
        var daysInMonth = new Date(year, month + 1, 0).getDate();
        var today = new Date();
        var todayStr = today.getFullYear() + '-' + String(today.getMonth()+1).padStart(2,'0') + '-' + String(today.getDate()).padStart(2,'0');

        var statusColors = {
            'pending': '#dbeafe', 'confirmed': '#d1fae5',
            'checked_in': '#fef3c7', 'completed': '#e5e7eb', 'cancelled': '#fee2e2'
        };

        var html = '';
        var day = 1;
        for (var w = 0; w < 6; w++) {
            if (day > daysInMonth) break;
            html += '<tr>';
            for (var d = 0; d < 7; d++) {
                if (w === 0 && d < firstDay) {
                    html += '<td style="padding:4px;border:1px solid #e5e7eb;min-height:60px;background:#f9fafb;">&nbsp;</td>';
                } else if (day <= daysInMonth) {
                    var dateStr = year + '-' + String(month+1).padStart(2,'0') + '-' + String(day).padStart(2,'0');
                    var dayBookings = calBookings.filter(function(b) {
                        return (b.checkin_date || '').startsWith(dateStr) || (b.checkout_date || '').startsWith(dateStr);
                    });
                    var bg = dateStr === todayStr ? '#eff6ff' : '#fff';
                    var bookingHtml = dayBookings.map(function(b) {
                        var color = statusColors[b.status] || '#f3f4f6';
                        return '<div style="background:' + color + ';border-radius:4px;padding:2px 4px;margin:2px 0;font-size:0.65rem;cursor:pointer;" data-cal-booking="1">' +
                            escapeHtml(b.customer_name || 'Reserva') + '</div>';
                    }).join('');
                    var isWeekend = d >= 5;
                    html += '<td style="padding:4px;border:1px solid #e5e7eb;vertical-align:top;min-height:60px;background:' + bg + ';' + (isWeekend ? 'background:#fafafa;' : '') + '">' +
                        '<div style="font-weight:600;font-size:0.75rem;' + (dateStr === todayStr ? 'color:#2563eb;' : 'color:#374151;') + '">' + day + '</div>' +
                        bookingHtml +
                    '</td>';
                    day++;
                } else {
                    html += '<td style="padding:4px;border:1px solid #e5e7eb;background:#f9fafb;">&nbsp;</td>';
                }
            }
            html += '</tr>';
        }
        $('#ltms-cal-tbody').html(html);
    }

    $('#ltms-cal-prev').on('click', function() {
        calDate.setMonth(calDate.getMonth() - 1);
        ltmsLoadCalendar();
    });
    $('#ltms-cal-next').on('click', function() {
        calDate.setMonth(calDate.getMonth() + 1);
        ltmsLoadCalendar();
    });
    $('#ltms-cal-today').on('click', function() {
        calDate = new Date();
        ltmsLoadCalendar();
    });
    // v2.9.93: Click en reserva del calendario → ir a tab reservas
    $(document).on('click', '[data-cal-booking]', function() {
        $('[data-target="ltms-bk-reservas"]').click();
    });

    function ltmsSeasonNotice(msg, type) {
        var ok = type === 'success';
        $('#ltms-season-notice').css({ background: ok ? '#f0fdf4' : '#fef2f2', color: ok ? '#166534' : '#991b1b',
            border: '1px solid ' + (ok ? '#86efac' : '#fca5a5'), 'border-radius': '6px' }).text(msg).show();
    }
    function ltmsPolicyNotice(msg, type) {
        var ok = type === 'success';
        $('#ltms-policy-notice').css({ background: ok ? '#f0fdf4' : '#fef2f2', color: ok ? '#166534' : '#991b1b',
            border: '1px solid ' + (ok ? '#86efac' : '#fca5a5'), 'border-radius': '6px' }).text(msg).show();
    }

    function ltmsLoadVendorProducts() {
        $.post(ltmsDashboard.ajax_url, { action: 'ltms_get_products_data', nonce: ltmsDashboard.nonce }, function(res) {
            productsLoaded = true;
            var $sel = $('#ltms-season-product').empty();
            $sel.append('<option value="0">— Todos mis alojamientos —</option>');
            if (res.success && res.data.products.length) {
                res.data.products.forEach(function(p) {
                    $sel.append('<option value="' + p.id + '">' + $('<span/>').text(p.name).html() + '</option>');
                });
            }
        });
    }

    function ltmsLoadSeasons() {
        $('#ltms-seasons-tbody').html('<tr><td colspan="6" style="text-align:center;padding:20px;color:#9ca3af;">Cargando...</td></tr>');
        $.post(ltmsDashboard.ajax_url, { action: 'ltms_get_vendor_seasons', nonce: ltmsDashboard.nonce }, function(res) {
            $('#ltms-seasons-tbody').data('loaded', true);
            if (!res.success || !res.data.length) {
                $('#ltms-seasons-tbody').html('<tr><td colspan="6" style="text-align:center;padding:30px;color:#9ca3af;">Sin temporadas. Crea una para aplicar precios especiales a un alojamiento.</td></tr>');
                return;
            }
            var html = res.data.map(function(s) {
                var mod = parseFloat(s.price_modifier || 1).toFixed(2);
                var pct = Math.round((mod - 1) * 100);
                var modHtml = pct > 0 ? '<span style="color:#16a34a;">+' + pct + '% (' + mod + 'x)</span>'
                    : (pct < 0 ? '<span style="color:#dc2626;">' + pct + '% (' + mod + 'x)</span>'
                                : '<span style="color:#6b7280;">Sin cambio</span>');
                return '<tr style="border-top:1px solid #f3f4f6;"><td style="padding:10px 12px;">' + $('<span/>').text(s.season_name).html() + '</td>' +
                    '<td style="padding:10px 12px;font-size:.82rem;color:#6b7280;">' + (s.product_name ? $('<span/>').text(s.product_name).html() : '—') + '</td>' +
                    '<td style="padding:10px 12px;font-size:.85rem;">' + s.date_from + '</td>' +
                    '<td style="padding:10px 12px;font-size:.85rem;">' + s.date_to + '</td>' +
                    '<td style="padding:10px 12px;">' + modHtml + '</td>' +
                    '<td style="padding:10px 12px;"><button class="ltms-btn ltms-btn-outline ltms-btn-sm ltms-season-edit" data-id="' + s.id + '" aria-label="<?php echo esc_js( __( 'Editar temporada', 'ltms' ) ); ?>"' +
                    ' data-name="' + encodeURIComponent(s.season_name) + '" data-from="' + s.date_from + '" data-to="' + s.date_to + '"' +
                    ' data-mod="' + s.price_modifier + '" data-pid="' + (s.product_id || 0) + '">✏️</button> ' +
                    '<button class="ltms-btn ltms-btn-sm ltms-season-del" data-id="' + s.id + '" aria-label="<?php echo esc_js( __( 'Eliminar temporada', 'ltms' ) ); ?>" style="background:#fee2e2;color:#991b1b;">🗑️</button></td></tr>';
            }).join('');
            $('#ltms-seasons-tbody').html(html);
        });
    }

    $(document).on('click', '#ltms-season-add-btn', function() {
        $('#ltms-season-id').val('0');
        $('#ltms-season-name,#ltms-season-from,#ltms-season-to').val('');
        $('#ltms-season-modifier').val('1.50'); $('#ltms-season-product').val('0');
        $('#ltms-season-form-title').text('Nueva temporada'); $('#ltms-season-notice').hide();
        $('#ltms-season-form').show(); $('#ltms-season-name').focus();
    });
    $(document).on('click', '.ltms-season-edit', function() {
        var $b = $(this);
        $('#ltms-season-id').val($b.data('id')); $('#ltms-season-name').val(decodeURIComponent($b.data('name')));
        $('#ltms-season-from').val($b.data('from')); $('#ltms-season-to').val($b.data('to'));
        $('#ltms-season-modifier').val($b.data('mod')); $('#ltms-season-product').val($b.data('pid') || '0');
        $('#ltms-season-form-title').text('Editar temporada'); $('#ltms-season-notice').hide();
        $('#ltms-season-form').show(); $('#ltms-season-name').focus();
    });
    $(document).on('click', '#ltms-season-save-btn', function() {
        var name = $('#ltms-season-name').val().trim(), from = $('#ltms-season-from').val(), to = $('#ltms-season-to').val();
        var pid = $('#ltms-season-product').val();
        if (!name || !from || !to) { ltmsSeasonNotice('El nombre y las fechas son obligatorios.', 'error'); return; }
        if (pid === '' || pid === null) { ltmsSeasonNotice('Espera a que carguen tus alojamientos e intenta de nuevo.', 'error'); return; }
        $(this).prop('disabled', true).text('Guardando...');
        $.post(ltmsDashboard.ajax_url, { action: 'ltms_save_vendor_season', nonce: ltmsDashboard.nonce,
            rule_id: $('#ltms-season-id').val(), season_name: name, date_from: from, date_to: to,
            price_modifier: $('#ltms-season-modifier').val(), product_id: pid },
        function(res) {
            $('#ltms-season-save-btn').prop('disabled', false).text('Guardar');
            if (res.success) { ltmsSeasonNotice('✅ ' + res.data.message, 'success'); $('#ltms-seasons-tbody').removeData('loaded');
                setTimeout(function() { $('#ltms-season-form').hide(); ltmsLoadSeasons(); }, 1200); }
            else { ltmsSeasonNotice('✗ ' + (res.data || 'Error'), 'error'); }
        });
    });
    $(document).on('click', '#ltms-season-cancel-btn', function() { $('#ltms-season-form').hide(); });
    $(document).on('click', '.ltms-season-del', function() {
        ltmsConfirmDelete('season', $(this).data('id'), '<?php echo esc_js( __( '¿Eliminar esta temporada? Esta acción no se puede deshacer.', 'ltms' ) ); ?>');
    });

    function ltmsLoadPolicies() {
        $('#ltms-policies-list').html('<div style="text-align:center;padding:30px;color:#9ca3af;">Cargando...</div>');
        $.post(ltmsDashboard.ajax_url, { action: 'ltms_get_vendor_policies', nonce: ltmsDashboard.nonce }, function(res) {
            $('#ltms-policies-list').data('loaded', true);
            if (!res.success || !res.data.length) {
                $('#ltms-policies-list').html('<div style="text-align:center;padding:30px;color:#9ca3af;">Sin políticas. Crea una para asignarla a tus alojamientos.</div>');
                return;
            }
            var tl = { flexible: 'Flexible ✅', moderate: 'Moderada ⚖️', strict: 'Estricta 🔒', non_refundable: 'Sin reembolso ❌' };
            var tc = { flexible: '#16a34a', moderate: '#f59e0b', strict: '#ef4444', non_refundable: '#6b7280' };
            var html = res.data.map(function(p) {
                return '<div class="ltms-card" style="padding:16px;">' +
                    '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;">' +
                    '<div><div style="font-weight:700;font-size:.95rem;">' + $('<span/>').text(p.name).html() +
                    (p.is_default == 1 ? ' <span style="font-size:.72rem;background:#eff6ff;color:#1d4ed8;padding:2px 8px;border-radius:99px;margin-left:6px;">Por defecto</span>' : '') + '</div>' +
                    '<div style="font-size:.8rem;color:' + (tc[p.policy_type]||'#6b7280') + ';margin-top:4px;">' + (tl[p.policy_type]||p.policy_type) + '</div>' +
                    '<div style="font-size:.8rem;color:#6b7280;margin-top:6px;">Gratis hasta ' + p.free_cancel_hours + 'h · ' + p.partial_refund_pct + '% dentro de ' + p.partial_refund_hours + 'h</div></div>' +
                    '<div style="display:flex;gap:6px;flex-shrink:0;">' +
                    '<button class="ltms-btn ltms-btn-outline ltms-btn-sm ltms-policy-edit" data-id="' + p.id + '" data-name="' + encodeURIComponent(p.name) + '"' +
                    ' data-type="' + p.policy_type + '" data-free="' + p.free_cancel_hours + '" data-pct="' + p.partial_refund_pct + '"' +
                    ' data-phours="' + p.partial_refund_hours + '" data-default="' + p.is_default + '">✏️ Editar</button>' +
                    '<button class="ltms-btn ltms-btn-sm ltms-policy-del" data-id="' + p.id + '" aria-label="<?php echo esc_js( __( 'Eliminar política', 'ltms' ) ); ?>" style="background:#fee2e2;color:#991b1b;">🗑️</button>' +
                    '</div></div></div>';
            }).join('');
            $('#ltms-policies-list').html(html);
        });
    }

    $(document).on('click', '#ltms-policy-add-btn', function() {
        $('#ltms-policy-id').val('0'); $('#ltms-policy-name').val(''); $('#ltms-policy-type').val('flexible');
        $('#ltms-policy-free-hours').val('24'); $('#ltms-policy-partial-pct').val('50');
        $('#ltms-policy-partial-hours').val('48'); $('#ltms-policy-default').prop('checked', false);
        $('#ltms-policy-form-title').text('Nueva política de cancelación'); $('#ltms-policy-notice').hide();
        $('#ltms-policy-form').show(); $('#ltms-policy-name').focus();
    });
    $(document).on('click', '.ltms-policy-edit', function() {
        var $b = $(this);
        $('#ltms-policy-id').val($b.data('id')); $('#ltms-policy-name').val(decodeURIComponent($b.data('name')));
        $('#ltms-policy-type').val($b.data('type')); $('#ltms-policy-free-hours').val($b.data('free'));
        $('#ltms-policy-partial-pct').val($b.data('pct')); $('#ltms-policy-partial-hours').val($b.data('phours'));
        $('#ltms-policy-default').prop('checked', $b.data('default') == 1);
        $('#ltms-policy-form-title').text('Editar política'); $('#ltms-policy-notice').hide();
        $('#ltms-policy-form').show(); $('#ltms-policy-name').focus();
    });
    $(document).on('click', '#ltms-policy-save-btn', function() {
        var name = $('#ltms-policy-name').val().trim();
        if (!name) { ltmsPolicyNotice('El nombre es obligatorio.', 'error'); return; }
        $(this).prop('disabled', true).text('Guardando...');
        $.post(ltmsDashboard.ajax_url, { action: 'ltms_save_vendor_policy', nonce: ltmsDashboard.nonce,
            policy_id: $('#ltms-policy-id').val(), policy_name: name, policy_type: $('#ltms-policy-type').val(),
            free_cancel_hours: $('#ltms-policy-free-hours').val(), partial_refund_pct: $('#ltms-policy-partial-pct').val(),
            partial_refund_hours: $('#ltms-policy-partial-hours').val(), is_default: $('#ltms-policy-default').is(':checked') ? 1 : 0 },
        function(res) {
            $('#ltms-policy-save-btn').prop('disabled', false).text('Guardar');
            if (res.success) { ltmsPolicyNotice('✅ ' + res.data.message, 'success'); $('#ltms-policies-list').removeData('loaded');
                setTimeout(function() { $('#ltms-policy-form').hide(); ltmsLoadPolicies(); }, 1200); }
            else { ltmsPolicyNotice('✗ ' + (res.data || 'Error'), 'error'); }
        });
    });
    $(document).on('click', '#ltms-policy-cancel-btn', function() { $('#ltms-policy-form').hide(); });
    $(document).on('click', '.ltms-policy-del', function() {
        ltmsConfirmDelete('policy', $(this).data('id'), '<?php echo esc_js( __( '¿Eliminar esta política de cancelación? Los productos que la usan quedarán sin política asignada.', 'ltms' ) ); ?>');
    });

    // ── Confirmación de eliminación (Temporada / Política), modal compartido ──
    var pendingDelete = null; // { type: 'season'|'policy', id: number }

    function ltmsConfirmDelete(type, id, message) {
        pendingDelete = { type: type, id: id };
        var title = type === 'season'
            ? '<?php echo esc_js( __( 'Eliminar temporada', 'ltms' ) ); ?>'
            : '<?php echo esc_js( __( 'Eliminar política', 'ltms' ) ); ?>';
        $('#ltms-bk-confirm-delete-title').text(title);
        $('#ltms-bk-confirm-delete-body').text(message);
        $('#ltms-bk-confirm-delete-btn').prop('disabled', false).text('<?php echo esc_js( __( 'Sí, eliminar', 'ltms' ) ); ?>');
        LTMS.Modal.open('ltms-modal-booking-confirm-delete');
    }

    $('#ltms-bk-confirm-delete-btn').on('click', function() {
        if (!pendingDelete) return;
        var $btn = $(this).prop('disabled', true).text('<?php echo esc_js( __( 'Eliminando…', 'ltms' ) ); ?>');
        var action = pendingDelete.type === 'season' ? 'ltms_delete_vendor_season' : 'ltms_delete_vendor_policy';
        var data = { action: action, nonce: ltmsDashboard.nonce };
        if (pendingDelete.type === 'season') { data.rule_id = pendingDelete.id; } else { data.policy_id = pendingDelete.id; }

        $.post(ltmsDashboard.ajax_url, data, function(res) {
            $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Sí, eliminar', 'ltms' ) ); ?>');
            if (res.success) {
                LTMS.Modal.close('ltms-modal-booking-confirm-delete');
                if (pendingDelete.type === 'season') {
                    $('#ltms-seasons-tbody').removeData('loaded'); ltmsLoadSeasons();
                } else {
                    $('#ltms-policies-list').removeData('loaded'); ltmsLoadPolicies();
                }
            } else {
                var errMsg = res.data || '<?php echo esc_js( __( 'Error al eliminar.', 'ltms' ) ); ?>';
                if (typeof LTMS !== 'undefined' && LTMS.UX && LTMS.UX.toastError) {
                    LTMS.UX.toastError('Error', errMsg);
                }
            }
            pendingDelete = null;
        }).fail(function() {
            $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Sí, eliminar', 'ltms' ) ); ?>');
            if (typeof LTMS !== 'undefined' && LTMS.UX && LTMS.UX.toastError) {
                LTMS.UX.toastError('Error', '<?php echo esc_js( __( 'Error de conexión.', 'ltms' ) ); ?>');
            }
        });
    });

})(jQuery);
</script>

