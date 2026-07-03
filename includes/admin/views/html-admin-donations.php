<?php
/**
 * Vista: Admin Donaciones — Fundación Cardio Infantil
 *
 * Panel completo de donaciones con:
 *   - Cards de resumen (total, pendientes, última/próxima transferencia)
 *   - Filtros (date range, status, vendor)
 *   - Tabla de donaciones (paginada, AJAX)
 *   - Tabla de lotes de transferencia (paginada, AJAX)
 *   - Gráficos (donaciones por mes, donaciones por día) con Chart.js
 *   - Acciones: Transferir ahora, Generar certificado, Exportar CSV
 *   - Sección de transparencia: top 10 vendedores por monto donado
 *   - Card de info de la fundación
 *
 * @package    LTMS
 * @subpackage LTMS/includes/admin/views
 * @version    1.0.0
 * @since      3.0.0  Task 60-D
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'No tienes permisos para acceder a esta página.', 'ltms' ) );
}

// Datos de la fundación (con fallbacks).
$foundation_name    = LTMS_Core_Config::get( 'ltms_donation_foundation_name', LTMS_Donation_Certificate::FOUNDATION_DEFAULTS['name'] );
$foundation_nit     = LTMS_Core_Config::get( 'ltms_donation_foundation_nit', LTMS_Donation_Certificate::FOUNDATION_DEFAULTS['nit'] );
$foundation_address = LTMS_Core_Config::get( 'ltms_donation_foundation_address', LTMS_Donation_Certificate::FOUNDATION_DEFAULTS['address'] );
$foundation_phone   = LTMS_Core_Config::get( 'ltms_donation_foundation_phone', LTMS_Donation_Certificate::FOUNDATION_DEFAULTS['phone'] );
$foundation_email   = LTMS_Core_Config::get( 'ltms_donation_foundation_email', LTMS_Donation_Certificate::FOUNDATION_DEFAULTS['email'] );
$tax_deductible     = LTMS_Core_Config::get( 'ltms_donation_tax_deductible', 'yes' ) === 'yes';
$currency           = LTMS_Core_Config::get_currency();

$nonce = wp_create_nonce( 'ltms_admin_donations' );
?>

<div class="wrap ltms-admin-wrap">

    <div class="ltms-header">
        <h1>❤️ <?php esc_html_e( 'Donaciones — Fundación Cardio Infantil', 'ltms' ); ?></h1>
        <span style="color:#666;font-size:0.85rem;margin-left:auto">
            <?php echo esc_html( $foundation_name ); ?> · NIT <?php echo esc_html( $foundation_nit ); ?>
        </span>
    </div>

    <?php if ( $tax_deductible ) : ?>
    <div class="notice notice-info inline" style="margin:8px 0 16px;padding:10px 14px;">
        <strong>✓ <?php esc_html_e( 'Donaciones deducibles de impuestos', 'ltms' ); ?></strong> —
        <?php esc_html_e( 'Los certificados generados son válidos como soporte para deducción fiscal.', 'ltms' ); ?>
    </div>
    <?php endif; ?>

    <!-- ───────────── CARDS DE RESUMEN ───────────── -->
    <div class="ltms-stats-grid" id="ltms-donations-summary">
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Total donado (período)', 'ltms' ); ?></span>
            <span class="ltms-stat-value" id="ltms-stat-total-donated">—</span>
            <span class="ltms-stat-sub" id="ltms-stat-period-label"><?php esc_html_e( 'Cargando…', 'ltms' ); ?></span>
        </div>
        <div class="ltms-stat-card" id="ltms-stat-pending-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Donaciones pendientes', 'ltms' ); ?></span>
            <span class="ltms-stat-value" id="ltms-stat-pending">—</span>
            <span class="ltms-stat-sub"><?php esc_html_e( 'Esperando ser acreditadas', 'ltms' ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Última transferencia', 'ltms' ); ?></span>
            <span class="ltms-stat-value" id="ltms-stat-last-transfer" style="font-size:1.3rem;">—</span>
            <span class="ltms-stat-sub" id="ltms-stat-last-transfer-sub"><?php esc_html_e( 'Sin transferencias aún', 'ltms' ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Próxima transferencia', 'ltms' ); ?></span>
            <span class="ltms-stat-value" id="ltms-stat-next-transfer" style="font-size:1.3rem;">—</span>
            <span class="ltms-stat-sub" id="ltms-stat-next-transfer-sub"><?php esc_html_e( 'Sin lotes pendientes', 'ltms' ); ?></span>
        </div>
    </div>

    <!-- ───────────── FILTROS GLOBALES ───────────── -->
    <div class="ltms-table-wrap" style="padding:14px 16px;margin-bottom:18px;">
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
            <label style="font-weight:600;font-size:0.85rem;">
                <?php esc_html_e( 'Período:', 'ltms' ); ?>
                <select id="ltms-donations-period" style="margin-left:6px;">
                    <option value="month"><?php esc_html_e( 'Este mes', 'ltms' ); ?></option>
                    <option value="quarter"><?php esc_html_e( 'Últimos 3 meses', 'ltms' ); ?></option>
                    <option value="year"><?php esc_html_e( 'Último año', 'ltms' ); ?></option>
                    <option value="all"><?php esc_html_e( 'Histórico', 'ltms' ); ?></option>
                </select>
            </label>
            <button type="button" class="ltms-btn ltms-btn-primary ltms-btn-sm" id="ltms-refresh-stats">
                ↻ <?php esc_html_e( 'Actualizar', 'ltms' ); ?>
            </button>
            <span style="flex:1"></span>
            <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm" id="ltms-manual-payout-btn">
                💸 <?php esc_html_e( 'Transferir ahora', 'ltms' ); ?>
            </button>
            <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm" id="ltms-export-csv-btn">
                📥 <?php esc_html_e( 'Exportar CSV', 'ltms' ); ?>
            </button>
        </div>
    </div>

    <!-- ───────────── GRÁFICOS ───────────── -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:18px;">
        <div class="ltms-table-wrap" style="padding:18px;">
            <div class="ltms-table-title"><?php esc_html_e( 'Donaciones por Mes', 'ltms' ); ?> <small style="color:#888;font-weight:normal;">(últimos 12 meses)</small></div>
            <div style="height:240px;padding:12px;">
                <canvas id="ltms-chart-by-month"></canvas>
            </div>
        </div>
        <div class="ltms-table-wrap" style="padding:18px;">
            <div class="ltms-table-title"><?php esc_html_e( 'Donaciones por Día', 'ltms' ); ?> <small style="color:#888;font-weight:normal;">(últimos 30 días)</small></div>
            <div style="height:240px;padding:12px;">
                <canvas id="ltms-chart-by-day"></canvas>
            </div>
        </div>
    </div>

    <!-- ───────────── TABLA DE DONACIONES ───────────── -->
    <div class="ltms-table-wrap" style="margin-bottom:18px;">
        <div class="ltms-table-title" style="padding:14px 16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
            <span>📋 <?php esc_html_e( 'Donaciones', 'ltms' ); ?> <small style="color:#888;" id="ltms-donations-count-label"></small></span>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;font-size:0.85rem;">
                <input type="text" id="ltms-filter-search" placeholder="<?php esc_attr_e( 'Buscar orden, vendedor, email…', 'ltms' ); ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:4px;width:240px;">
                <select id="ltms-filter-status" style="padding:6px 10px;border:1px solid #ddd;border-radius:4px;">
                    <option value="all"><?php esc_html_e( 'Todos los estados', 'ltms' ); ?></option>
                    <option value="pending"><?php esc_html_e( 'Pendiente', 'ltms' ); ?></option>
                    <option value="credited"><?php esc_html_e( 'Acreditada', 'ltms' ); ?></option>
                    <option value="processing"><?php esc_html_e( 'En proceso', 'ltms' ); ?></option>
                    <option value="paid"><?php esc_html_e( 'Pagada', 'ltms' ); ?></option>
                    <option value="reversed"><?php esc_html_e( 'Reversada', 'ltms' ); ?></option>
                    <option value="failed"><?php esc_html_e( 'Fallida', 'ltms' ); ?></option>
                </select>
                <input type="text" id="ltms-filter-vendor" placeholder="<?php esc_attr_e( 'Vendor ID', 'ltms' ); ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:4px;width:90px;">
                <input type="date" id="ltms-filter-from" style="padding:6px 10px;border:1px solid #ddd;border-radius:4px;">
                <input type="date" id="ltms-filter-to" style="padding:6px 10px;border:1px solid #ddd;border-radius:4px;">
                <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm" id="ltms-filter-apply">🔍 <?php esc_html_e( 'Filtrar', 'ltms' ); ?></button>
                <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm" id="ltms-filter-clear">✕ <?php esc_html_e( 'Limpiar', 'ltms' ); ?></button>
            </div>
        </div>
        <div style="overflow-x:auto;">
            <table class="ltms-table" id="ltms-donations-table" style="min-width:1100px;width:100%;">
                <thead>
                    <tr>
                        <th style="width:60px">ID</th>
                        <th style="width:80px"><?php esc_html_e( 'Orden', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Vendedor', 'ltms' ); ?></th>
                        <th style="text-align:right"><?php esc_html_e( 'Monto base', 'ltms' ); ?></th>
                        <th style="text-align:center">%</th>
                        <th style="text-align:right"><?php esc_html_e( 'Donación', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Fecha', 'ltms' ); ?></th>
                        <th style="width:100px"><?php esc_html_e( 'Acciones', 'ltms' ); ?></th>
                    </tr>
                </thead>
                <tbody id="ltms-donations-tbody">
                    <tr><td colspan="9" style="text-align:center;padding:40px;color:#888;"><?php esc_html_e( 'Cargando donaciones…', 'ltms' ); ?></td></tr>
                </tbody>
            </table>
        </div>
        <div style="padding:14px 16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
            <span style="color:#888;font-size:0.85rem;" id="ltms-donations-pagination-info"></span>
            <div id="ltms-donations-pagination" style="display:flex;gap:4px;"></div>
        </div>
    </div>

    <!-- ───────────── TABLA DE LOTES DE TRANSFERENCIA ───────────── -->
    <div class="ltms-table-wrap" style="margin-bottom:18px;">
        <div class="ltms-table-title" style="padding:14px 16px;display:flex;justify-content:space-between;align-items:center;">
            <span>🏦 <?php esc_html_e( 'Lotes de Transferencia', 'ltms' ); ?></span>
            <select id="ltms-batches-filter-status" style="padding:6px 10px;border:1px solid #ddd;border-radius:4px;font-size:0.85rem;">
                <option value="all"><?php esc_html_e( 'Todos', 'ltms' ); ?></option>
                <option value="pending"><?php esc_html_e( 'Pendientes', 'ltms' ); ?></option>
                <option value="paid"><?php esc_html_e( 'Pagados', 'ltms' ); ?></option>
                <option value="failed"><?php esc_html_e( 'Fallidos', 'ltms' ); ?></option>
                <option value="cancelled"><?php esc_html_e( 'Cancelados', 'ltms' ); ?></option>
            </select>
        </div>
        <div style="overflow-x:auto;">
            <table class="ltms-table" id="ltms-batches-table" style="min-width:900px;width:100%;">
                <thead>
                    <tr>
                        <th style="width:50px">ID</th>
                        <th><?php esc_html_e( 'Lote', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Período', 'ltms' ); ?></th>
                        <th style="text-align:right"><?php esc_html_e( 'Total', 'ltms' ); ?></th>
                        <th style="text-align:center"><?php esc_html_e( '# Donaciones', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Transferencia', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Certificado', 'ltms' ); ?></th>
                        <th style="width:200px"><?php esc_html_e( 'Acciones', 'ltms' ); ?></th>
                    </tr>
                </thead>
                <tbody id="ltms-batches-tbody">
                    <tr><td colspan="9" style="text-align:center;padding:40px;color:#888;"><?php esc_html_e( 'Cargando lotes…', 'ltms' ); ?></td></tr>
                </tbody>
            </table>
        </div>
        <div style="padding:14px 16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
            <span style="color:#888;font-size:0.85rem;" id="ltms-batches-pagination-info"></span>
            <div id="ltms-batches-pagination" style="display:flex;gap:4px;"></div>
        </div>
    </div>

    <!-- ───────────── TRANSPARENCIA: TOP 10 VENDEDORES ───────────── -->
    <div style="display:grid;grid-template-columns:2fr 1fr;gap:18px;">
        <div class="ltms-table-wrap">
            <div class="ltms-table-title" style="padding:14px 16px;">
                🏆 <?php esc_html_e( 'Top 10 Vendedores por Donación (período)', 'ltms' ); ?>
            </div>
            <div style="overflow-x:auto;">
                <table class="ltms-table" id="ltms-top-vendors-table" style="width:100%;">
                    <thead>
                        <tr>
                            <th style="width:40px">#</th>
                            <th><?php esc_html_e( 'Vendedor', 'ltms' ); ?></th>
                            <th style="text-align:center"><?php esc_html_e( '# Donaciones', 'ltms' ); ?></th>
                            <th style="text-align:right"><?php esc_html_e( 'Base acumulada', 'ltms' ); ?></th>
                            <th style="text-align:right"><?php esc_html_e( 'Donación total', 'ltms' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="ltms-top-vendors-tbody">
                        <tr><td colspan="5" style="text-align:center;padding:30px;color:#888;"><?php esc_html_e( 'Cargando…', 'ltms' ); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ───────────── INFO DE LA FUNDACIÓN ───────────── -->
        <div class="ltms-table-wrap">
            <div class="ltms-table-title" style="padding:14px 16px;">
                🏥 <?php esc_html_e( 'Información de la Fundación', 'ltms' ); ?>
            </div>
            <div style="padding:16px;font-size:0.9rem;line-height:1.6;">
                <p style="margin:0 0 8px;"><strong style="font-size:1.05rem;color:#1e6091;"><?php echo esc_html( $foundation_name ); ?></strong></p>
                <p style="margin:0 0 6px;color:#555;"><strong>NIT:</strong> <?php echo esc_html( $foundation_nit ); ?></p>
                <p style="margin:0 0 6px;color:#555;"><strong>Dirección:</strong> <?php echo esc_html( $foundation_address ); ?></p>
                <p style="margin:0 0 6px;color:#555;"><strong>Teléfono:</strong> <?php echo esc_html( $foundation_phone ); ?></p>
                <p style="margin:0 0 6px;color:#555;"><strong>Email:</strong>
                    <a href="mailto:<?php echo esc_attr( $foundation_email ); ?>"><?php echo esc_html( $foundation_email ); ?></a>
                </p>
                <hr style="margin:12px 0;border:none;border-top:1px solid #eee;">
                <p style="margin:0 0 6px;color:#555;">
                    <strong>¿Deducible?</strong>
                    <?php if ( $tax_deductible ) : ?>
                        <span class="ltms-badge ltms-badge-success">✓ <?php esc_html_e( 'Sí', 'ltms' ); ?></span>
                    <?php else : ?>
                        <span class="ltms-badge ltms-badge-pending"><?php esc_html_e( 'No', 'ltms' ); ?></span>
                    <?php endif; ?>
                </p>
                <p style="margin:0 0 6px;color:#555;"><strong>Moneda:</strong> <?php echo esc_html( $currency ); ?></p>
                <p style="margin:8px 0 0;font-size:0.8rem;color:#888;font-style:italic;">
                    <?php esc_html_e( 'Configura estos datos en LTMS → Configuración → Donaciones.', 'ltms' ); ?>
                </p>
            </div>
        </div>
    </div>

</div>

<!-- ───────────── MODAL: Transferir ahora ───────────── -->
<div id="ltms-payout-modal" role="dialog" aria-modal="true" aria-labelledby="ltms-payout-modal-title" tabindex="-1" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:8px;padding:28px;width:480px;max-width:95vw;box-shadow:0 8px 32px rgba(0,0,0,.2);">
        <h3 id="ltms-payout-modal-title" style="margin:0 0 16px">💸 <?php esc_html_e( 'Transferir donaciones a la fundación', 'ltms' ); ?></h3>
        <p style="margin:0 0 12px;color:#555;font-size:0.9rem;">
            <?php esc_html_e( 'Indica el período de donaciones a transferir. Se crearará un lote, se asociarán las donaciones "acreditadas" del rango, y se marcarán como "pagadas".', 'ltms' ); ?>
        </p>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
            <label style="font-size:0.85rem;">
                <?php esc_html_e( 'Fecha inicio', 'ltms' ); ?>
                <input type="date" id="ltms-payout-from" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;margin-top:4px;">
            </label>
            <label style="font-size:0.85rem;">
                <?php esc_html_e( 'Fecha fin', 'ltms' ); ?>
                <input type="date" id="ltms-payout-to" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;margin-top:4px;">
            </label>
        </div>
        <label style="font-size:0.85rem;display:block;margin-bottom:12px;">
            <?php esc_html_e( 'Referencia de transferencia (opcional)', 'ltms' ); ?>
            <input type="text" id="ltms-payout-ref" placeholder="Ej: TRF-2026-0618-001" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;margin-top:4px;">
        </label>
        <p id="ltms-payout-error" style="display:none;color:#c00;margin:10px 0 0;font-size:0.85rem"></p>
        <div style="margin-top:16px;display:flex;gap:8px;justify-content:flex-end;">
            <button type="button" id="ltms-payout-cancel" class="ltms-btn ltms-btn-outline ltms-btn-sm"><?php esc_html_e( 'Cancelar', 'ltms' ); ?></button>
            <button type="button" id="ltms-payout-confirm" class="ltms-btn ltms-btn-primary ltms-btn-sm"><?php esc_html_e( 'Confirmar transferencia', 'ltms' ); ?></button>
        </div>
    </div>
</div>

<!-- ───────────── MODAL: Detalle de donación ───────────── -->
<div id="ltms-donation-detail-modal" role="dialog" aria-modal="true" aria-labelledby="ltms-donation-detail-modal-title" tabindex="-1" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:8px;padding:28px;width:560px;max-width:95vw;max-height:90vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,.2);">
        <h3 id="ltms-donation-detail-modal-title" style="margin:0 0 16px">🔍 <?php esc_html_e( 'Detalle de Donación', 'ltms' ); ?> #<span id="ltms-detail-id"></span></h3>
        <div id="ltms-detail-content" style="font-size:0.9rem;line-height:1.7;">
            <p style="color:#888;text-align:center;padding:20px;"><?php esc_html_e( 'Cargando…', 'ltms' ); ?></p>
        </div>
        <div style="margin-top:16px;display:flex;justify-content:flex-end;">
            <button type="button" id="ltms-detail-close" class="ltms-btn ltms-btn-outline ltms-btn-sm"><?php esc_html_e( 'Cerrar', 'ltms' ); ?></button>
        </div>
    </div>
</div>

<script>
(function($){
    'use strict';

    var nonce  = <?php echo wp_json_encode( $nonce ); ?>;
    var ajaxurl = window.ajaxurl || (window.ltmsAdmin && ltmsAdmin.ajax_url) || '/wp-admin/admin-ajax.php';
    var currency = <?php echo wp_json_encode( $currency ); ?>;

    var state = {
        donations: { page: 1, perPage: 20, total: 0, totalPages: 1 },
        batches:   { page: 1, perPage: 10, total: 0, totalPages: 1 }
    };

    // ── Helpers ────────────────────────────────────────────────────
    function fmtMoney(n) {
        n = parseFloat(n) || 0;
        return currency === 'COP'
            ? '$' + n.toLocaleString('es-CO', { maximumFractionDigits: 0 })
            : '$' + n.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + currency;
    }
    function fmtDate(d) {
        if (!d) return '—';
        var date = new Date(d + (d.length === 10 ? 'T00:00:00Z' : ''));
        if (isNaN(date.getTime())) return d;
        return date.toLocaleDateString('es-CO', { day: '2-digit', month: '2-digit', year: 'numeric' });
    }
    function badgeFor(status) {
        var map = {
            pending:    { label: 'Pendiente',  cls: 'ltms-badge-warning' },
            credited:   { label: 'Acreditada', cls: 'ltms-badge-info' },
            processing: { label: 'En proceso', cls: 'ltms-badge-info' },
            paid:       { label: 'Pagada',     cls: 'ltms-badge-success' },
            reversed:   { label: 'Reversada',  cls: 'ltms-badge-danger' },
            failed:     { label: 'Fallida',    cls: 'ltms-badge-danger' }
        };
        var m = map[status] || { label: status, cls: 'ltms-badge-pending' };
        return '<span class="ltms-badge ' + m.cls + '">' + m.label + '</span>';
    }
    function batchBadge(status) {
        var map = {
            pending:   { label: 'Pendiente',  cls: 'ltms-badge-warning' },
            paid:      { label: 'Pagado',     cls: 'ltms-badge-success' },
            failed:    { label: 'Fallido',    cls: 'ltms-badge-danger' },
            cancelled: { label: 'Cancelado',  cls: 'ltms-badge-pending' }
        };
        var m = map[status] || { label: status, cls: 'ltms-badge-pending' };
        return '<span class="ltms-badge ' + m.cls + '">' + m.label + '</span>';
    }
    function notify(type, msg) {
        var color = type === 'success' ? '#00a32a' : (type === 'warning' ? '#dba617' : '#d63638');
        var $n = $('<div>').text(msg).css({
            position:'fixed', top:'40px', right:'24px', background:color, color:'#fff',
            padding:'12px 20px', borderRadius:'6px', zIndex:99998,
            boxShadow:'0 4px 12px rgba(0,0,0,.2)', fontSize:'0.9rem', maxWidth:'360px'
        }).appendTo('body');
        setTimeout(function(){ $n.fadeOut(400, function(){ $n.remove(); }); }, 4000);
    }

    // ── Modal helper (WCAG 2.1: role/aria-modal/aria-labelledby/tabindex
    //    are set on the HTML; aquí gestionamos focus trap + Escape + restore) ──
    var ltmsModal = (function() {
        var $lastFocused = null;
        var $current = null;
        var FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

        function open($modal) {
            $lastFocused = $(document.activeElement);
            $current = $modal;
            $modal.css('display', 'flex');
            // Enfocar el primer elemento focusable dentro del modal.
            var $f = $modal.find(FOCUSABLE).first();
            if ($f.length) { $f.trigger('focus'); } else { $modal.trigger('focus'); }
        }
        function close($modal) {
            $modal.css('display', 'none');
            if ($lastFocused && $lastFocused.length) { $lastFocused.trigger('focus'); }
            $current = null;
        }
        // Escape para cerrar.
        $(document).on('keydown.ltmsModal', function(e) {
            if (e.key !== 'Escape' && e.keyCode !== 27) { return; }
            if (!$current) { return; }
            e.preventDefault();
            close($current);
        });
        // Focus trap: Tab al llegar al último salta al primero (y viceversa).
        $(document).on('keydown.ltmsModalTrap', function(e) {
            if (e.key !== 'Tab' && e.keyCode !== 9) { return; }
            if (!$current || !$current.is(':visible')) { return; }
            var $f = $current.find(FOCUSABLE);
            if (!$f.length) { return; }
            var $active = $(document.activeElement);
            var first = $f.first()[0], last = $f.last()[0];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        });
        return { open: open, close: close };
    })();

    // ── Statistics ─────────────────────────────────────────────────
    function loadStats() {
        var period = $('#ltms-donations-period').val();
        $.post(ajaxurl, { action: 'ltms_get_donation_stats', nonce: nonce, period: period })
        .done(function(res){
            if (!res.success) { return; }
            var d = res.data;
            $('#ltms-stat-total-donated').text(fmtMoney(d.summary.total_donated));
            $('#ltms-stat-period-label').text(
                d.date_from + ' → ' + d.date_to
            );
            $('#ltms-stat-pending').text(d.summary.pending_count);
            $('#ltms-stat-pending-card').toggleClass('ltms-stat-warning', d.summary.pending_count > 0);

            if (d.summary.last_transfer) {
                var lt = d.summary.last_transfer;
                $('#ltms-stat-last-transfer').text(fmtMoney(lt.total_amount));
                $('#ltms-stat-last-transfer-sub').text(
                    'Lote ' + lt.batch_number + ' · ' + fmtDate(lt.transferred_at)
                );
            } else {
                $('#ltms-stat-last-transfer').text('—');
                $('#ltms-stat-last-transfer-sub').text('<?php esc_html_e( "Sin transferencias aún", "ltms" ); ?>');
            }

            if (d.summary.next_transfer) {
                var nt = d.summary.next_transfer;
                $('#ltms-stat-next-transfer').text(fmtMoney(nt.total_amount));
                $('#ltms-stat-next-transfer-sub').text(
                    'Lote ' + nt.batch_number + ' · cierra ' + fmtDate(nt.period_end)
                );
            } else {
                $('#ltms-stat-next-transfer').text('—');
                $('#ltms-stat-next-transfer-sub').text('<?php esc_html_e( "Sin lotes pendientes", "ltms" ); ?>');
            }

            renderCharts(d.charts);
            renderTopVendors(d.top_vendors);
        })
        .fail(function(){ notify('error', 'Error al cargar estadísticas.'); });
    }

    // ── Charts ─────────────────────────────────────────────────────
    var chartByMonth = null, chartByDay = null;
    function renderCharts(charts) {
        // Destroy previous instances
        if (chartByMonth) { chartByMonth.destroy(); }
        if (chartByDay)   { chartByDay.destroy(); }

        var monthCtx = document.getElementById('ltms-chart-by-month');
        var dayCtx   = document.getElementById('ltms-chart-by-day');
        if (typeof Chart === 'undefined' || !monthCtx || !dayCtx) { return; }

        var mLabels = (charts.by_month || []).map(function(r){ return r.month_key; });
        var mData   = (charts.by_month || []).map(function(r){ return parseFloat(r.total) || 0; });
        var dLabels = (charts.by_day   || []).map(function(r){ return r.day_key; });
        var dData   = (charts.by_day   || []).map(function(r){ return parseFloat(r.total) || 0; });

        chartByMonth = new Chart(monthCtx, {
            type: 'bar',
            data: {
                labels: mLabels,
                datasets: [{
                    label: '<?php esc_html_e( "Donaciones por mes", "ltms" ); ?>',
                    data: mData,
                    backgroundColor: '#1e6091',
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { ticks: { callback: function(v){ return '$' + v.toLocaleString(); } } } }
            }
        });

        chartByDay = new Chart(dayCtx, {
            type: 'line',
            data: {
                labels: dLabels,
                datasets: [{
                    label: '<?php esc_html_e( "Donaciones por día", "ltms" ); ?>',
                    data: dData,
                    borderColor: '#1e6091',
                    backgroundColor: 'rgba(30,96,145,0.15)',
                    fill: true, tension: 0.3, pointRadius: 2
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { ticks: { callback: function(v){ return '$' + v.toLocaleString(); } } } }
            }
        });
    }

    function renderTopVendors(vendors) {
        var $tbody = $('#ltms-top-vendors-tbody');
        if (!vendors || vendors.length === 0) {
            $tbody.html('<tr><td colspan="5" style="text-align:center;padding:30px;color:#888;">No hay donaciones en el período.</td></tr>');
            return;
        }
        var html = '';
        vendors.forEach(function(v, i) {
            html += '<tr>' +
                '<td><strong>' + (i+1) + '</strong></td>' +
                '<td>' + $('<div>').text(v.vendor_name || ('Vendor #' + v.vendor_id)).html() + '</td>' +
                '<td style="text-align:center">' + (parseInt(v.count) || 0) + '</td>' +
                '<td style="text-align:right">' + fmtMoney(v.base_total) + '</td>' +
                '<td style="text-align:right"><strong>' + fmtMoney(v.total) + '</strong></td>' +
            '</tr>';
        });
        $tbody.html(html);
    }

    // ── Donations list ─────────────────────────────────────────────
    function loadDonations(page) {
        if (page) state.donations.page = page;
        $('#ltms-donations-tbody').html('<tr><td colspan="9" style="text-align:center;padding:30px;color:#888;">Cargando…</td></tr>');
        $.post(ajaxurl, {
            action: 'ltms_get_donations',
            nonce: nonce,
            status:    $('#ltms-filter-status').val(),
            vendor_id: $('#ltms-filter-vendor').val(),
            date_from: $('#ltms-filter-from').val(),
            date_to:   $('#ltms-filter-to').val(),
            search:    $('#ltms-filter-search').val(),
            paged:     state.donations.page,
            per_page:  state.donations.perPage
        })
        .done(function(res){
            if (!res.success) {
                $('#ltms-donations-tbody').html('<tr><td colspan="9" style="text-align:center;padding:30px;color:#c00;">' + (res.data || 'Error') + '</td></tr>');
                return;
            }
            var items = res.data.items || [];
            state.donations.total = res.data.total;
            state.donations.totalPages = res.data.total_pages;
            $('#ltms-donations-count-label').text('(' + res.data.total + ' total)');
            if (items.length === 0) {
                $('#ltms-donations-tbody').html('<tr><td colspan="9" style="text-align:center;padding:30px;color:#888;">No hay donaciones con los filtros actuales.</td></tr>');
            } else {
                var html = '';
                items.forEach(function(d) {
                    html += '<tr>' +
                        '<td><strong>#' + d.id + '</strong></td>' +
                        '<td><a href="' + (window.location.origin + '/wp-admin/post.php?post=' + d.order_id + '&action=edit') + '" target="_blank">#' + d.order_id + '</a></td>' +
                        '<td>' + $('<div>').text(d.vendor_name || '—').html() + '<br><small style="color:#888">' + $('<div>').text(d.vendor_email || '').html() + '</small></td>' +
                        '<td style="text-align:right">' + fmtMoney(d.base_amount) + '</td>' +
                        '<td style="text-align:center">' + (parseFloat(d.percentage) || 0).toFixed(2) + '%</td>' +
                        '<td style="text-align:right"><strong>' + fmtMoney(d.donation_amount) + '</strong></td>' +
                        '<td>' + badgeFor(d.status) + '</td>' +
                        '<td style="white-space:nowrap">' + fmtDate(d.created_at) + '</td>' +
                        '<td><button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm ltms-view-donation" data-id="' + d.id + '" aria-label="<?php esc_attr_e( 'Ver detalle de la donación', 'ltms' ); ?> #" + d.id + "">🔍</button></td>' +
                    '</tr>';
                });
                $('#ltms-donations-tbody').html(html);
            }
            renderPagination('#ltms-donations-pagination', '#ltms-donations-pagination-info', state.donations, loadDonations);
        })
        .fail(function(){ $('#ltms-donations-tbody').html('<tr><td colspan="9" style="text-align:center;padding:30px;color:#c00;">Error de conexión.</td></tr>'); });
    }

    // ── Batches list ───────────────────────────────────────────────
    function loadBatches(page) {
        if (page) state.batches.page = page;
        $('#ltms-batches-tbody').html('<tr><td colspan="9" style="text-align:center;padding:30px;color:#888;">Cargando…</td></tr>');
        $.post(ajaxurl, {
            action: 'ltms_get_payout_batches',
            nonce: nonce,
            status:   $('#ltms-batches-filter-status').val(),
            paged:    state.batches.page,
            per_page: state.batches.perPage
        })
        .done(function(res){
            if (!res.success) {
                $('#ltms-batches-tbody').html('<tr><td colspan="9" style="text-align:center;padding:30px;color:#c00;">' + (res.data || 'Error') + '</td></tr>');
                return;
            }
            var items = res.data.items || [];
            state.batches.total = res.data.total;
            state.batches.totalPages = res.data.total_pages;
            if (items.length === 0) {
                $('#ltms-batches-tbody').html('<tr><td colspan="9" style="text-align:center;padding:30px;color:#888;">No hay lotes con el filtro actual.</td></tr>');
            } else {
                var html = '';
                items.forEach(function(b) {
                    var cert = b.certificate_path
                        ? '<span class="ltms-badge ltms-badge-success">✓ Generado</span>'
                        : '<span class="ltms-badge ltms-badge-pending">Pendiente</span>';
                    var certBtn = b.certificate_path
                        ? '<button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm ltms-download-cert" data-path="' + $('<div>').text(b.certificate_path).html() + '" aria-label="<?php esc_attr_e( 'Descargar certificado del lote', 'ltms' ); ?> #" + b.id + "">⬇️</button> '
                        : '';
                    html += '<tr>' +
                        '<td><strong>#' + b.id + '</strong></td>' +
                        '<td><strong>' + $('<div>').text(b.batch_number).html() + '</strong></td>' +
                        '<td>' + fmtDate(b.period_start) + ' → ' + fmtDate(b.period_end) + '</td>' +
                        '<td style="text-align:right">' + fmtMoney(b.total_amount) + '</td>' +
                        '<td style="text-align:center">' + b.transaction_count + '</td>' +
                        '<td>' + batchBadge(b.status) + '</td>' +
                        '<td>' + (b.transfer_reference ? '<code style="font-size:0.75rem">' + $('<div>').text(b.transfer_reference).html() + '</code>' : '—') + '<br><small style="color:#888">' + fmtDate(b.transferred_at) + '</small></td>' +
                        '<td>' + cert + '</td>' +
                        '<td>' +
                            (b.status === 'pending' ? '<button type="button" class="ltms-btn ltms-btn-primary ltms-btn-sm ltms-pay-batch" data-id="' + b.id + '">💸 <?php esc_html_e( 'Transferir', 'ltms' ); ?></button> ' : '') +
                            certBtn +
                            '<button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm ltms-gen-cert" data-id="' + b.id + '" aria-label="<?php esc_attr_e( 'Generar certificado del lote', 'ltms' ); ?> #" + b.id + '">📄 <?php esc_html_e( 'Certificado', 'ltms' ); ?></button>' +
                        '</td>' +
                    '</tr>';
                });
                $('#ltms-batches-tbody').html(html);
            }
            renderPagination('#ltms-batches-pagination', '#ltms-batches-pagination-info', state.batches, loadBatches);
        })
        .fail(function(){ $('#ltms-batches-tbody').html('<tr><td colspan="9" style="text-align:center;padding:30px;color:#c00;">Error de conexión.</td></tr>'); });
    }

    function renderPagination(containerSel, infoSel, s, loader) {
        var $c = $(containerSel), $i = $(infoSel);
        $i.text('Página ' + s.page + ' de ' + s.totalPages + ' (' + s.total + ' registros)');
        $c.empty();
        if (s.totalPages <= 1) return;
        for (var p = 1; p <= s.totalPages; p++) {
            var active = p === s.page;
            $c.append('<a href="#" data-page="' + p + '" style="padding:6px 12px;border:1px solid ' + (active ? '#2271b1' : '#ddd') + ';background:' + (active ? '#2271b1' : '#fff') + ';color:' + (active ? '#fff' : '#333') + ';border-radius:4px;text-decoration:none;font-size:0.85rem;">' + p + '</a>');
        }
        $c.find('a').on('click', function(e){
            e.preventDefault();
            loader(parseInt($(this).data('page')));
        });
    }

    // ── Event bindings ─────────────────────────────────────────────
    $('#ltms-refresh-stats').on('click', function(){ loadStats(); });
    $('#ltms-donations-period').on('change', function(){ loadStats(); });

    $('#ltms-filter-apply').on('click', function(){ loadDonations(1); });
    $('#ltms-filter-clear').on('click', function(){
        $('#ltms-filter-search,#ltms-filter-status,#ltms-filter-vendor,#ltms-filter-from,#ltms-filter-to').val('');
        $('#ltms-filter-status').val('all');
        loadDonations(1);
    });
    $('#ltms-batches-filter-status').on('change', function(){ loadBatches(1); });

    // ── View donation detail ───────────────────────────────────────
    $(document).on('click', '.ltms-view-donation', function(){
        var id = $(this).data('id');
        $('#ltms-detail-id').text(id);
        $('#ltms-detail-content').html('<p style="color:#888;text-align:center;padding:20px;">Cargando…</p>');
        ltmsModal.open($('#ltms-donation-detail-modal'));
        $.post(ajaxurl, { action: 'ltms_get_donation_detail', nonce: nonce, donation_id: id })
        .done(function(res){
            if (!res.success) { $('#ltms-detail-content').html('<p style="color:#c00;">' + (res.data || 'Error') + '</p>'); return; }
            var d = res.data.donation, o = res.data.order || {}, b = res.data.batch || {};
            var html = '<table style="width:100%;font-size:0.9rem;">';
            html += row('ID', '#' + d.id);
            html += row('Orden', '#' + d.order_id + (o.order_number ? ' (WC #' + o.order_number + ')' : ''));
            html += row('Estado orden', o.order_status || '—');
            html += row('Vendedor', d.vendor_name + ' <' + d.vendor_email + '>');
            html += row('Monto base', fmtMoney(d.base_amount));
            html += row('Porcentaje', (parseFloat(d.percentage) || 0).toFixed(2) + '%');
            html += row('Donación', '<strong>' + fmtMoney(d.donation_amount) + '</strong>');
            html += row('Estado', badgeFor(d.status));
            html += row('Lote', b.batch_number ? (b.batch_number + ' (#' + b.id + ')') : '—');
            html += row('Período lote', b.period_start ? (fmtDate(b.period_start) + ' → ' + fmtDate(b.period_end)) : '—');
            html += row('Transferido', b.transferred_at ? fmtDate(b.transferred_at) : '—');
            html += row('Creada', fmtDate(d.created_at));
            html += row('Acreditada', fmtDate(d.credited_at));
            html += row('Pagada', fmtDate(d.paid_at));
            html += '</table>';
            $('#ltms-detail-content').html(html);
        })
        .fail(function(){ $('#ltms-detail-content').html('<p style="color:#c00;">Error de conexión.</p>'); });
    });
    function row(label, value) {
        return '<tr><td style="padding:6px 10px;color:#555;border-bottom:1px solid #eee;width:40%;">' + label + '</td>' +
               '<td style="padding:6px 10px;border-bottom:1px solid #eee;"><strong>' + value + '</strong></td></tr>';
    }
    $('#ltms-detail-close').on('click', function(){ ltmsModal.close($('#ltms-donation-detail-modal')); });
    $('#ltms-donation-detail-modal').on('click', function(e){ if ($(e.target).is('#ltms-donation-detail-modal')) ltmsModal.close($(this)); });

    // ── Manual payout modal ────────────────────────────────────────
    $('#ltms-manual-payout-btn').on('click', function(){
        // Pre-fill with current month range
        var now = new Date();
        var first = new Date(now.getFullYear(), now.getMonth(), 1);
        $('#ltms-payout-from').val(first.toISOString().slice(0,10));
        $('#ltms-payout-to').val(now.toISOString().slice(0,10));
        $('#ltms-payout-ref').val('');
        $('#ltms-payout-error').hide();
        ltmsModal.open($('#ltms-payout-modal'));
    });
    $('#ltms-payout-cancel').on('click', function(){ ltmsModal.close($('#ltms-payout-modal')); });
    $('#ltms-payout-modal').on('click', function(e){ if ($(e.target).is('#ltms-payout-modal')) ltmsModal.close($(this)); });

    $('#ltms-payout-confirm').on('click', function(){
        var $btn = $(this);
        var data = {
            action: 'ltms_manual_payout', nonce: nonce,
            batch_id: 0,
            period_start: $('#ltms-payout-from').val(),
            period_end:   $('#ltms-payout-to').val(),
            transfer_ref: $('#ltms-payout-ref').val()
        };
        if (!data.period_start || !data.period_end) {
            $('#ltms-payout-error').text('Las fechas de inicio y fin son obligatorias.').show();
            return;
        }
        $btn.prop('disabled', true).text('Procesando…');
        $.post(ajaxurl, data)
        .done(function(res){
            $btn.prop('disabled', false).text('<?php esc_html_e( "Confirmar transferencia", "ltms" ); ?>');
            if (res.success) {
                ltmsModal.close($('#ltms-payout-modal'));
                notify('success', 'Transferencia completada. Lote #' + (res.data.batch_id || '?') + ' · ' + (res.data.donation_count || 0) + ' donaciones.');
                loadStats(); loadDonations(1); loadBatches(1);
            } else {
                $('#ltms-payout-error').text(res.data || 'Error al transferir.').show();
            }
        })
        .fail(function(){
            $btn.prop('disabled', false).text('<?php esc_html_e( "Confirmar transferencia", "ltms" ); ?>');
            $('#ltms-payout-error').text('Error de conexión.').show();
        });
    });

    // ── Pay existing batch (from batches table) ────────────────────
    $(document).on('click', '.ltms-pay-batch', function(){
        var $btn = $(this);
        var id = $btn.data('id');
        if (!confirm('¿Transferir el lote #' + id + ' ahora?')) return;
        $btn.prop('disabled', true).text('…');
        $.post(ajaxurl, { action: 'ltms_manual_payout', nonce: nonce, batch_id: id })
        .done(function(res){
            if (res.success) {
                notify('success', 'Lote #' + id + ' transferido.');
                loadStats(); loadBatches(1);
            } else {
                $btn.prop('disabled', false).text('💸 <?php esc_html_e( 'Transferir', 'ltms' ); ?>');
                notify('error', res.data || 'Error.');
            }
        })
        .fail(function(){
            $btn.prop('disabled', false).text('💸 <?php esc_html_e( 'Transferir', 'ltms' ); ?>');
            notify('error', 'Error de conexión.');
        });
    });

    // ── Generate certificate ───────────────────────────────────────
    $(document).on('click', '.ltms-gen-cert', function(){
        var $btn = $(this);
        var id = $btn.data('id');
        $btn.prop('disabled', true).text('Generando…');
        $.post(ajaxurl, { action: 'ltms_generate_certificate', nonce: nonce, batch_id: id })
        .done(function(res){
            $btn.prop('disabled', false).text('📄 <?php esc_html_e( 'Certificado', 'ltms' ); ?>');
            if (res.success) {
                notify('success', 'Certificado generado.');
                loadBatches(state.batches.page);
                // Si la respuesta incluye URL, ofrecer descarga.
                // ADMIN-BUG-10 (defense-in-depth): validar esquema https.
                if (res.data.download_url && res.data.download_url.indexOf('https://') === 0) {
                    window.open(res.data.download_url, '_blank');
                }
            } else {
                notify('error', res.data || 'Error al generar certificado.');
            }
        })
        .fail(function(){
            $btn.prop('disabled', false).text('📄 <?php esc_html_e( 'Certificado', 'ltms' ); ?>');
            notify('error', 'Error de conexión.');
        });
    });

    // ── Download existing certificate ──────────────────────────────
    $(document).on('click', '.ltms-download-cert', function(){
        notify('info', 'Para descargar el certificado, usa el botón "📄 Certificado" (regenera y devuelve URL firmada).');
    });

    // ── Export CSV ─────────────────────────────────────────────────
    $('#ltms-export-csv-btn').on('click', function(){
        var $btn = $(this);
        $btn.prop('disabled', true).text('Exportando…');
        $.post(ajaxurl, {
            action: 'ltms_export_donations_csv', nonce: nonce,
            status:    $('#ltms-filter-status').val(),
            vendor_id: $('#ltms-filter-vendor').val(),
            date_from: $('#ltms-filter-from').val(),
            date_to:   $('#ltms-filter-to').val(),
            search:    $('#ltms-filter-search').val()
        })
        .done(function(res){
            if (res.success && res.data.csv) {
                var binary = atob(res.data.csv);
                var bytes = new Uint8Array(binary.length);
                for (var i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
                var blob = new Blob([bytes], { type: 'text/csv;charset=utf-8;' });
                var url = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url; a.download = res.data.filename; a.click();
                URL.revokeObjectURL(url);
                notify('success', res.data.count + ' donaciones exportadas.');
            } else {
                notify('error', res.data || 'Error al exportar.');
            }
        })
        .fail(function(){ notify('error', 'Error de conexión.'); })
        .always(function(){ $btn.prop('disabled', false).text('📥 Exportar CSV'); });
    });

    // ── Initial load ───────────────────────────────────────────────
    $(function(){
        loadStats();
        loadDonations(1);
        loadBatches(1);
    });

})(jQuery);
</script>
