<?php
/**
 * Vista: Admin Cross-Border Commerce
 *
 * Panel completo de comercio internacional con:
 *   - Cards de resumen (órdenes cross-border, duties recaudados, conversiones FX, spread revenue)
 *   - Tabla de tasas FX (todos los pares, con rate mid/applied, última actualización)
 *   - Calculadora aduanera (test con valores de muestra)
 *   - Tabla de órdenes cross-border recientes
 *   - Top países origen y destino
 *   - Botones: Refresh FX, Export CSV
 *
 * @package    LTMS
 * @subpackage LTMS/includes/admin/views
 * @version    1.0.0
 * @since      3.1.0  Task 63-C
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permisos para acceder a esta página.', 'ltms' ) );
}

$base_currency       = LTMS_Core_Config::get( 'ltms_base_currency', 'USD' );
$cross_border_enabled= LTMS_Core_Config::get( 'ltms_cross_border_enabled', 'no' );
$spread_pct          = (float) LTMS_Core_Config::get( 'ltms_fx_spread_percentage', 1.5 );
$fx_provider         = LTMS_Core_Config::get( 'ltms_fx_provider', 'frankfurter' );
$nonce               = wp_create_nonce( 'ltms_admin_cross_border' );
?>

<div class="wrap ltms-admin-wrap">

        <div class="ltms-header">
                <h1>🌍 <?php esc_html_e( 'Cross-Border Commerce', 'ltms' ); ?></h1>
                <span style="color:#666;font-size:0.85rem;margin-left:auto">
                        <?php echo esc_html( sprintf(
                                /* translators: 1: moneda base, 2: proveedor FX, 3: spread % */
                                __( 'Base: %1$s · FX: %2$s · Spread: %3$s%%', 'ltms' ),
                                $base_currency, $fx_provider, $spread_pct
                        ) ); ?>
                </span>
        </div>

        <?php if ( $cross_border_enabled !== 'yes' ) : ?>
        <div class="notice notice-warning inline" style="margin:8px 0 16px;padding:10px 14px;">
                <strong>⚠ <?php esc_html_e( 'Cross-Border deshabilitado', 'ltms' ); ?></strong> —
                <?php
                printf(
                        /* translators: %s: URL a la página de settings */
                        esc_html__( 'El módulo de comercio internacional está inactivo. Actívalo en %s.', 'ltms' ),
                        '<a href="' . esc_url( admin_url( 'admin.php?page=ltms-settings&tab=cross_border' ) ) . '">' . esc_html__( 'Settings → Cross-Border', 'ltms' ) . '</a>'
                );
                ?>
        </div>
        <?php endif; ?>

        <!-- ───────────── CARDS DE RESUMEN ───────────── -->
        <div class="ltms-stats-grid" id="ltms-cross-border-summary">
                <div class="ltms-stat-card">
                        <span class="ltms-stat-label"><?php esc_html_e( 'Órdenes cross-border (período)', 'ltms' ); ?></span>
                        <span class="ltms-stat-value" id="ltms-stat-xb-orders">—</span>
                        <span class="ltms-stat-sub" id="ltms-stat-period-label"><?php esc_html_e( 'Cargando…', 'ltms' ); ?></span>
                </div>
                <div class="ltms-stat-card">
                        <span class="ltms-stat-label"><?php esc_html_e( 'Duties recaudados', 'ltms' ); ?></span>
                        <span class="ltms-stat-value" id="ltms-stat-duties">—</span>
                        <span class="ltms-stat-sub"><?php echo esc_html( sprintf( __( 'En %s', 'ltms' ), $base_currency ) ); ?></span>
                </div>
                <div class="ltms-stat-card">
                        <span class="ltms-stat-label"><?php esc_html_e( 'Conversiones FX', 'ltms' ); ?></span>
                        <span class="ltms-stat-value" id="ltms-stat-fx-conv">—</span>
                        <span class="ltms-stat-sub"><?php esc_html_e( 'Órdenes en moneda no-base', 'ltms' ); ?></span>
                </div>
                <div class="ltms-stat-card">
                        <span class="ltms-stat-label"><?php esc_html_e( 'Avg spread revenue', 'ltms' ); ?></span>
                        <span class="ltms-stat-value" id="ltms-stat-spread">—</span>
                        <span class="ltms-stat-sub"><?php echo esc_html( sprintf( __( 'Spread %s%% aplicado', 'ltms' ), $spread_pct ) ); ?></span>
                </div>
        </div>

        <!-- ───────────── FILTROS GLOBALES ───────────── -->
        <div class="ltms-table-wrap" style="padding:14px 16px;margin-bottom:18px;">
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                        <label style="font-weight:600;font-size:0.85rem;">
                                <?php esc_html_e( 'Período:', 'ltms' ); ?>
                                <select id="ltms-xb-period" style="margin-left:6px;">
                                        <option value="month"><?php esc_html_e( 'Este mes', 'ltms' ); ?></option>
                                        <option value="quarter"><?php esc_html_e( 'Últimos 3 meses', 'ltms' ); ?></option>
                                        <option value="year"><?php esc_html_e( 'Último año', 'ltms' ); ?></option>
                                        <option value="all"><?php esc_html_e( 'Histórico', 'ltms' ); ?></option>
                                </select>
                        </label>
                        <button type="button" class="ltms-btn ltms-btn-primary ltms-btn-sm" id="ltms-xb-refresh-stats">
                                ↻ <?php esc_html_e( 'Actualizar', 'ltms' ); ?>
                        </button>
                        <span style="flex:1"></span>
                        <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm" id="ltms-xb-refresh-fx">
                                🔄 <?php esc_html_e( 'Refresh FX Rates', 'ltms' ); ?>
                        </button>
                        <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm" id="ltms-xb-export-csv">
                                📥 <?php esc_html_e( 'Export CSV', 'ltms' ); ?>
                        </button>
                </div>
        </div>

        <!-- ───────────── TABLA DE TASAS FX ───────────── -->
        <div class="ltms-table-wrap" style="margin-bottom:18px;">
                <h2 style="margin:0 0 12px;padding:14px 16px 0;"><?php esc_html_e( 'Tasas FX actuales', 'ltms' ); ?></h2>
                <table class="widefat striped" id="ltms-fx-rates-table">
                        <thead>
                                <tr>
                                        <th><?php esc_html_e( 'Par', 'ltms' ); ?></th>
                                        <th><?php esc_html_e( 'Rate (mid-market)', 'ltms' ); ?></th>
                                        <th><?php esc_html_e( 'Rate aplicado (con spread)', 'ltms' ); ?></th>
                                        <th><?php esc_html_e( 'Manual?', 'ltms' ); ?></th>
                                        <th><?php esc_html_e( 'Última actualización', 'ltms' ); ?></th>
                                </tr>
                        </thead>
                        <tbody>
                                <tr><td colspan="5" style="text-align:center;color:#888;padding:20px;"><?php esc_html_e( 'Cargando tasas FX…', 'ltms' ); ?></td></tr>
                        </tbody>
                </table>
        </div>

        <!-- ───────────── CALCULADORA ADUANERA ───────────── -->
        <div class="ltms-table-wrap" style="margin-bottom:18px;">
                <h2 style="margin:0 0 12px;padding:14px 16px 0;">🧮 <?php esc_html_e( 'Calculadora aduanera', 'ltms' ); ?></h2>
                <p style="padding:0 16px;color:#666;font-size:0.85rem;">
                        <?php esc_html_e( 'Simula el cálculo de aranceles, IVA y honorarios para una orden de prueba.', 'ltms' ); ?>
                </p>
                <table class="form-table" role="presentation" style="padding:0 16px;max-width:900px;">
                        <tr>
                                <th scope="row" style="width:200px;"><label for="ltms-calc-origin"><?php esc_html_e( 'País origen', 'ltms' ); ?></label></th>
                                <td>
                                        <select id="ltms-calc-origin" class="regular-text">
                                                <option value="US">US — Estados Unidos</option>
                                                <option value="CO">CO — Colombia</option>
                                                <option value="MX">MX — México</option>
                                                <option value="BR">BR — Brasil</option>
                                                <option value="CN">CN — China</option>
                                                <option value="DE">DE — Alemania</option>
                                        </select>
                                </td>
                                <th scope="row" style="width:200px;"><label for="ltms-calc-dest"><?php esc_html_e( 'País destino', 'ltms' ); ?></label></th>
                                <td>
                                        <select id="ltms-calc-dest" class="regular-text">
                                                <option value="CO">CO — Colombia</option>
                                                <option value="MX">MX — México</option>
                                                <option value="BR">BR — Brasil</option>
                                                <option value="US">US — Estados Unidos</option>
                                                <option value="AR">AR — Argentina</option>
                                                <option value="CL">CL — Chile</option>
                                                <option value="PE">PE — Perú</option>
                                        </select>
                                </td>
                        </tr>
                        <tr>
                                <th scope="row"><label for="ltms-calc-cif"><?php esc_html_e( 'Valor CIF', 'ltms' ); ?></label></th>
                                <td><input type="number" id="ltms-calc-cif" value="100.00" step="0.01" min="0" class="regular-text" /></td>
                                <th scope="row"><label for="ltms-calc-currency"><?php esc_html_e( 'Moneda del pago', 'ltms' ); ?></label></th>
                                <td>
                                        <select id="ltms-calc-currency" class="regular-text">
                                                <option value="USD">USD</option>
                                                <option value="COP">COP</option>
                                                <option value="MXN">MXN</option>
                                                <option value="EUR">EUR</option>
                                                <option value="BRL">BRL</option>
                                        </select>
                                </td>
                        </tr>
                        <tr>
                                <th scope="row"><label for="ltms-calc-incoterm"><?php esc_html_e( 'Incoterm', 'ltms' ); ?></label></th>
                                <td>
                                        <select id="ltms-calc-incoterm" class="regular-text">
                                                <option value=""><?php esc_html_e( 'Usar default', 'ltms' ); ?></option>
                                                <option value="DDP"><?php esc_html_e( 'DDP — Delivered Duty Paid', 'ltms' ); ?></option>
                                                <option value="DDU"><?php esc_html_e( 'DDU — Delivered Duty Unpaid', 'ltms' ); ?></option>
                                        </select>
                                </td>
                                <th scope="row"><label for="ltms-calc-hs"><?php esc_html_e( 'HS Code (opcional)', 'ltms' ); ?></label></th>
                                <td><input type="text" id="ltms-calc-hs" placeholder="ej. 6109.10" class="regular-text" /></td>
                        </tr>
                        <tr>
                                <td colspan="4" style="text-align:right;padding-top:8px;">
                                        <button type="button" class="ltms-btn ltms-btn-primary ltms-btn-sm" id="ltms-calc-btn">
                                                🧮 <?php esc_html_e( 'Calcular aranceles', 'ltms' ); ?>
                                        </button>
                                </td>
                        </tr>
                </table>

                <div id="ltms-calc-result" style="display:none;padding:0 16px 16px;">
                        <table class="widefat striped" style="max-width:900px;">
                                <tbody>
                                        <tr><th><?php esc_html_e( 'Valor CIF (USD)', 'ltms' ); ?></th><td id="ltms-calc-cif-usd">—</td></tr>
                                        <tr><th><?php esc_html_e( 'De minimis aplica', 'ltms' ); ?></th><td id="ltms-calc-deminimis">—</td></tr>
                                        <tr><th><?php esc_html_e( 'Arancel', 'ltms' ); ?></th><td id="ltms-calc-duty">—</td></tr>
                                        <tr><th><?php esc_html_e( 'IVA', 'ltms' ); ?></th><td id="ltms-calc-vat">—</td></tr>
                                        <tr><th><?php esc_html_e( 'Honorarios broker', 'ltms' ); ?></th><td id="ltms-calc-broker">—</td></tr>
                                        <tr style="background:#f0f7ff;"><th><strong><?php esc_html_e( 'Total duties', 'ltms' ); ?></strong></th><td id="ltms-calc-total"><strong>—</strong></td></tr>
                                        <tr><th><?php esc_html_e( 'Landed cost estimado', 'ltms' ); ?></th><td id="ltms-calc-landed">—</td></tr>
                                        <tr><th><?php esc_html_e( 'Pagado por', 'ltms' ); ?></th><td id="ltms-calc-paidby">—</td></tr>
                                </tbody>
                        </table>
                </div>
        </div>

        <!-- ───────────── TOP PAÍSES ORIGEN/DESTINO ───────────── -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:18px;">
                <div class="ltms-table-wrap">
                        <h2 style="margin:0 0 12px;padding:14px 16px 0;">📍 <?php esc_html_e( 'Top países origen', 'ltms' ); ?></h2>
                        <table class="widefat striped" id="ltms-top-origins-table">
                                <thead><tr>
                                        <th><?php esc_html_e( 'País', 'ltms' ); ?></th>
                                        <th><?php esc_html_e( 'Órdenes', 'ltms' ); ?></th>
                                        <th><?php esc_html_e( 'Duties', 'ltms' ); ?></th>
                                </tr></thead>
                                <tbody>
                                        <tr><td colspan="3" style="text-align:center;color:#888;padding:14px;"><?php esc_html_e( 'Cargando…', 'ltms' ); ?></td></tr>
                                </tbody>
                        </table>
                </div>
                <div class="ltms-table-wrap">
                        <h2 style="margin:0 0 12px;padding:14px 16px 0;">🎯 <?php esc_html_e( 'Top países destino', 'ltms' ); ?></h2>
                        <table class="widefat striped" id="ltms-top-destinations-table">
                                <thead><tr>
                                        <th><?php esc_html_e( 'País', 'ltms' ); ?></th>
                                        <th><?php esc_html_e( 'Órdenes', 'ltms' ); ?></th>
                                        <th><?php esc_html_e( 'Duties', 'ltms' ); ?></th>
                                </tr></thead>
                                <tbody>
                                        <tr><td colspan="3" style="text-align:center;color:#888;padding:14px;"><?php esc_html_e( 'Cargando…', 'ltms' ); ?></td></tr>
                                </tbody>
                        </table>
                </div>
        </div>

        <!-- ───────────── TABLA DE ÓRDENES CROSS-BORDER RECIENTES ───────────── -->
        <div class="ltms-table-wrap" style="margin-bottom:18px;">
                <h2 style="margin:0 0 12px;padding:14px 16px 0;">📦 <?php esc_html_e( 'Órdenes cross-border recientes', 'ltms' ); ?></h2>
                <table class="widefat striped" id="ltms-xb-orders-table">
                        <thead>
                                <tr>
                                        <th><?php esc_html_e( 'ID', 'ltms' ); ?></th>
                                        <th><?php esc_html_e( 'Order', 'ltms' ); ?></th>
                                        <th><?php esc_html_e( 'Origen', 'ltms' ); ?></th>
                                        <th><?php esc_html_e( 'Destino', 'ltms' ); ?></th>
                                        <th><?php esc_html_e( 'Moneda', 'ltms' ); ?></th>
                                        <th><?php esc_html_e( 'CIF', 'ltms' ); ?></th>
                                        <th><?php esc_html_e( 'Duties', 'ltms' ); ?></th>
                                        <th><?php esc_html_e( 'Incoterm', 'ltms' ); ?></th>
                                        <th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
                                        <th><?php esc_html_e( 'Fecha', 'ltms' ); ?></th>
                                </tr>
                        </thead>
                        <tbody>
                                <tr><td colspan="10" style="text-align:center;color:#888;padding:20px;"><?php esc_html_e( 'Cargando órdenes…', 'ltms' ); ?></td></tr>
                        </tbody>
                </table>
        </div>

</div>

<script>
jQuery(function($) {
        var nonce = <?php echo wp_json_encode( $nonce ); ?>;
        var ajaxurl = window.ajaxurl || (window.ltmsAdmin && ltmsAdmin.ajax_url) || '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
        var baseCurrency = <?php echo wp_json_encode( $base_currency ); ?>;

        function notify(type, msg) {
                if (window.LTMS && LTMS.Admin && LTMS.Admin.showNotice) {
                        LTMS.Admin.showNotice(type, msg);
                } else {
                        window.console.warn(msg);
                }
        }

        /**
         * ADM-BUG-2 FIX (Task 65-C): escape HTML before injecting into .append().
         * All DB-derived strings (origin_country, declaration_status, currency
         * codes, base/quote currency, etc.) are now escaped via this helper
         * to prevent XSS via jQuery's HTML parser when .append() is called
         * with concatenated strings. Previously, a malicious value persisted
         * in order meta or customs declaration (e.g. <script> payload) could
         * execute arbitrary JS in the admin's browser.
         *
         * @param {string|number|null|undefined} text
         * @return {string} HTML-escaped text safe to inject via .append()/.html().
         */
        function escapeHtml(text) {
                if (text === null || text === undefined) return '';
                // Coerce to string; numbers/booleans pass through cleanly.
                return $('<div>').text(String(text)).html();
        }

        function formatMoney(v, curr) {
                if (v === null || v === undefined || isNaN(v)) return '—';
                var n = parseFloat(v);
                var decimals = (curr === 'COP' || curr === 'CLP') ? 0 : 2;
                return n.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }) + ' ' + (curr || '');
        }

        // ── Load stats ────────────────────────────────────────────────
        function loadStats() {
                var period = $('#ltms-xb-period').val();
                $.post(ajaxurl, { action: 'ltms_get_cross_border_stats', nonce: nonce, period: period })
                .done(function(res) {
                        if (!res.success) {
                                notify('error', res.data || 'Error al cargar estadísticas.');
                                return;
                        }
                        var s = res.data.summary || {};
                        $('#ltms-stat-xb-orders').text(s.cross_border_orders || 0);
                        $('#ltms-stat-duties').text(formatMoney(s.duties_collected, baseCurrency));
                        $('#ltms-stat-fx-conv').text(s.fx_conversions || 0);
                        $('#ltms-stat-spread').text(formatMoney(s.avg_spread_revenue, baseCurrency));
                        $('#ltms-stat-period-label').text(res.data.date_from + ' → ' + res.data.date_to);

                        // Top origins.
                        var $ot = $('#ltms-top-origins-table tbody').empty();
                        if (!res.data.top_origins || !res.data.top_origins.length) {
                                $ot.append('<tr><td colspan="3" style="text-align:center;color:#888;padding:14px;">Sin datos</td></tr>');
                        } else {
                                res.data.top_origins.forEach(function(r) {
                                        $ot.append(
                                                $('<tr>').append(
                                                        $('<td>').text(r.origin_country || ''),
                                                        $('<td>').text(r.cnt || 0),
                                                        $('<td>').text(formatMoney(r.duties, baseCurrency))
                                                )
                                        );
                                });
                        }

                        // Top destinations.
                        var $dt = $('#ltms-top-destinations-table tbody').empty();
                        if (!res.data.top_destinations || !res.data.top_destinations.length) {
                                $dt.append('<tr><td colspan="3" style="text-align:center;color:#888;padding:14px;">Sin datos</td></tr>');
                        } else {
                                res.data.top_destinations.forEach(function(r) {
                                        $dt.append(
                                                $('<tr>').append(
                                                        $('<td>').text(r.destination_country || ''),
                                                        $('<td>').text(r.cnt || 0),
                                                        $('<td>').text(formatMoney(r.duties, baseCurrency))
                                                )
                                        );
                                });
                        }

                        // Recent declarations.
                        var $rt = $('#ltms-xb-orders-table tbody').empty();
                        if (!res.data.recent_declarations || !res.data.recent_declarations.length) {
                                $rt.append('<tr><td colspan="10" style="text-align:center;color:#888;padding:14px;">Sin órdenes cross-border en el período</td></tr>');
                        } else {
                                res.data.recent_declarations.forEach(function(r) {
                                        $rt.append(
                                                $('<tr>').append(
                                                        $('<td>').text(r.id || ''),
                                                        $('<td>').text('#' + (r.order_id || '')),
                                                        $('<td>').text(r.origin_country || ''),
                                                        $('<td>').text(r.destination_country || ''),
                                                        $('<td>').text(r.currency || ''),
                                                        $('<td>').text(formatMoney(r.cif_value, r.currency)),
                                                        $('<td>').text(formatMoney(r.total_duties, r.currency)),
                                                        $('<td>').text(r.incoterm || ''),
                                                        $('<td>').text(r.declaration_status || ''),
                                                        $('<td>').text(r.created_at || '')
                                                )
                                        );
                                });
                        }

                        // FX rates table.
                        renderFxRates(res.data.fx_rates || []);
                })
                .fail(function() { notify('error', 'Error de conexión al cargar estadísticas.'); });
        }

        /**
         * ADM-BUG-1 FIX (Task 65-C): Normalize the FX rate row schema before render.
         *
         * `renderFxRates` is called from two contexts with DIFFERENT schemas:
         *   (a) loadStats() → res.data.fx_rates (from ajax_get_cross_border_stats):
         *       {base_currency, quote_currency, rate, provider, is_manual, fetched_at}
         *   (b) loadFxRates() → res.data.rates (from ajax_get_fx_rates):
         *       {base, quote, rate_mid, rate_applied, spread_pct, is_manual, fetched_at}
         *
         * Previously the function only read r.base_currency / r.quote_currency /
         * r.rate — which exist in (a) but are UNDEFINED in (b). After a "Refresh
         * FX Rates" click, loadFxRates() is called and the table shows
         * "undefined → undefined" with "—" everywhere.
         *
         * The fix normalizes any row to a single canonical schema
         * {base, quote, rate, rate_applied, is_manual, fetched_at} so the
         * rendering logic below works for both callers.
         *
         * @param {Object} r Raw row from either endpoint.
         * @return {Object} Normalized row.
         */
        function normalizeFxRow(r) {
                var base   = r.base_currency || r.base   || '';
                var quote  = r.quote_currency || r.quote || '';
                // rate: prefer the raw 'rate' field (stats endpoint), fall back
                // to 'rate_mid' (get_fx_rates endpoint). Both are mid-market.
                var rate   = (r.rate !== undefined && r.rate !== null && r.rate !== '')
                        ? r.rate
                        : r.rate_mid;
                // rate_applied: prefer the explicit field (get_fx_rates endpoint).
                // For the stats endpoint, we compute it below from rate * spread.
                var applied = (r.rate_applied !== undefined && r.rate_applied !== null)
                        ? r.rate_applied
                        : null;
                if (applied === null && rate !== null && rate !== undefined && !isNaN(parseFloat(rate)) && parseFloat(rate) > 0) {
                        applied = parseFloat(rate) * (1 - <?php echo (float) $spread_pct; ?> / 100);
                }
                return {
                        base:         base,
                        quote:        quote,
                        rate:         rate,
                        rate_applied: applied,
                        is_manual:    r.is_manual,
                        fetched_at:   r.fetched_at || '',
                };
        }

        function renderFxRates(rows) {
                var $t = $('#ltms-fx-rates-table tbody').empty();
                if (!rows || !rows.length) {
                        $t.append('<tr><td colspan="5" style="text-align:center;color:#888;padding:14px;">Sin tasas FX cacheadas. Haz clic en "Refresh FX Rates" para poblar la tabla.</td></tr>');
                        return;
                }
                rows.forEach(function(rawRow) {
                        // ADM-BUG-1: normalize schema from either endpoint.
                        var r = normalizeFxRow(rawRow);
                        var applied = r.rate_applied;
                        $t.append(
                                $('<tr>').append(
                                        $('<td>').text(r.base + ' → ' + r.quote),
                                        $('<td>').text((r.rate !== null && r.rate !== undefined && r.rate !== '') ? r.rate : '—'),
                                        $('<td>').text(applied ? Number(applied).toFixed(6) : '—'),
                                        $('<td>').text(parseInt(r.is_manual) ? '✓ Sí' : 'No'),
                                        $('<td>').text(r.fetched_at || '—')
                                )
                        );
                });
        }

        // ── Refresh FX rates (force) ──────────────────────────────────
        $('#ltms-xb-refresh-fx').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('Refrescando…');
                $.post(ajaxurl, { action: 'ltms_refresh_fx_rates', nonce: nonce })
                .done(function(res) {
                        $btn.prop('disabled', false).html('🔄 <?php esc_html_e( 'Refresh FX Rates', 'ltms' ); ?>');
                        if (res.success) {
                                notify('success', res.data.message);
                                // Recargar tasas desde el endpoint get_fx_rates.
                                loadFxRates();
                        } else {
                                notify('error', res.data || 'Error al refrescar FX.');
                        }
                })
                .fail(function() {
                        $btn.prop('disabled', false).html('🔄 <?php esc_html_e( 'Refresh FX Rates', 'ltms' ); ?>');
                        notify('error', 'Error de conexión.');
                });
        });

        function loadFxRates() {
                $.post(ajaxurl, { action: 'ltms_get_fx_rates', nonce: nonce })
                .done(function(res) {
                        if (res.success) {
                                renderFxRates(res.data.rates || []);
                        }
                });
        }

        // ── Customs calculator ────────────────────────────────────────
        $('#ltms-calc-btn').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('Calculando…');
                $.post(ajaxurl, {
                        action: 'ltms_get_customs_estimate',
                        nonce: nonce,
                        origin_country:      $('#ltms-calc-origin').val(),
                        destination_country: $('#ltms-calc-dest').val(),
                        cif_value:           $('#ltms-calc-cif').val(),
                        currency:            $('#ltms-calc-currency').val(),
                        incoterm:            $('#ltms-calc-incoterm').val(),
                        hs_code:             $('#ltms-calc-hs').val()
                })
                .done(function(res) {
                        $btn.prop('disabled', false).html('🧮 <?php esc_html_e( 'Calcular aranceles', 'ltms' ); ?>');
                        if (!res.success) {
                                notify('error', res.data || 'Error al calcular.');
                                return;
                        }
                        var d = res.data;
                        $('#ltms-calc-cif-usd').text(formatMoney(d.cif_value_usd, 'USD'));
                        $('#ltms-calc-deminimis').text(
                                d.de_minimis_applies
                                        ? '✓ Sí (exento, threshold ' + formatMoney(d.de_minimis_threshold, 'USD') + ')'
                                        : 'No (threshold ' + formatMoney(d.de_minimis_threshold, 'USD') + ')'
                        );
                        $('#ltms-calc-duty').text(d.duty_rate_pct + '% → ' + formatMoney(d.duty_amount, 'USD'));
                        $('#ltms-calc-vat').text(d.vat_rate_pct + '% → ' + formatMoney(d.vat_amount, 'USD'));
                        $('#ltms-calc-broker').text('flat ' + formatMoney(d.broker_flat, 'USD') + ' + ' + d.broker_pct + '% → ' + formatMoney(d.broker_amount, 'USD'));
                        $('#ltms-calc-total').text(formatMoney(d.total_duties, 'USD'));
                        $('#ltms-calc-landed').text(formatMoney(d.estimated_landed_cost, 'USD'));
                        $('#ltms-calc-paidby').text(d.paid_by === 'marketplace' ? 'Marketplace (DDP)' : 'Comprador (DDU)');
                        $('#ltms-calc-result').show();
                })
                .fail(function() {
                        $btn.prop('disabled', false).html('🧮 <?php esc_html_e( 'Calcular aranceles', 'ltms' ); ?>');
                        notify('error', 'Error de conexión.');
                });
        });

        // ── Export CSV ────────────────────────────────────────────────
        $('#ltms-xb-export-csv').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('Exportando…');
                $.post(ajaxurl, {
                        action: 'ltms_export_cross_border_csv',
                        nonce:  nonce,
                        period: $('#ltms-xb-period').val()
                })
                .done(function(res) {
                        $btn.prop('disabled', false).html('📥 <?php esc_html_e( 'Export CSV', 'ltms' ); ?>');
                        if (!res.success) {
                                notify('error', res.data || 'Error al exportar.');
                                return;
                        }
                        // Trigger download via data URI.
                        // HIGH-17 FIX (Task 65-C, related): atob() returns a Latin1
                        // string — non-Latin1 chars (UTF-8 BOM, emojis, CJK) corrupt.
                        // Decode to raw bytes via Uint8Array for a proper UTF-8 Blob.
                        var blob;
                        try {
                                var binary = atob(res.data.csv);
                                var bytes = new Uint8Array(binary.length);
                                for (var i = 0; i < binary.length; i++) {
                                        bytes[i] = binary.charCodeAt(i);
                                }
                                blob = new Blob([bytes], { type: 'text/csv;charset=utf-8;' });
                        } catch (e) {
                                notify('error', 'No se pudo decodificar el CSV.');
                                return;
                        }
                        var url = URL.createObjectURL(blob);
                        var a = document.createElement('a');
                        a.href = url;
                        a.download = res.data.filename || 'ltms-cross-border.csv';
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                        URL.revokeObjectURL(url);
                        notify('success', 'CSV exportado: ' + res.data.count + ' registros.');
                })
                .fail(function() {
                        $btn.prop('disabled', false).html('📥 <?php esc_html_e( 'Export CSV', 'ltms' ); ?>');
                        notify('error', 'Error de conexión.');
                });
        });

        $('#ltms-xb-refresh-stats').on('click', loadStats);
        $('#ltms-xb-period').on('change', loadStats);

        // Initial load.
        loadStats();
        loadFxRates();
});
</script>
