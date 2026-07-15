<?php
/**
 * Vista: Envíos — Relaciones de Envíos del Vendedor
 *
 * Permite al vendedor crear manifiestos de despacho (Relaciones de Envíos)
 * agrupando guías por transportadora, con autocompletado de destinatarios
 * desde Aveonline.
 *
 * @package LTMS
 * @version 2.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Obtener transportadoras disponibles
$carriers = class_exists( 'LTMS_Business_Aveonline_Carriers' )
    ? LTMS_Business_Aveonline_Carriers::all()
    : [];
?>

<div class="ltms-view-pad">

    <!-- Header -->
    <div class="ltms-view-header" style="margin-bottom:24px;">
        <h2 style="margin:0;font-size:1.35rem;font-weight:700;color:#111827;">
            🚚 <?php esc_html_e( 'Centro de Envíos', 'ltms' ); ?>
        </h2>
        <p style="margin:4px 0 0;font-size:0.85rem;color:#6b7280;">
            <?php esc_html_e( 'Gestiona todos tus envíos: relaciones Aveonline, tracking de carriers, y estados de entrega.', 'ltms' ); ?>
        </p>
    </div>

    <!-- AUDIT-SHIPPING-ENGINE #19 FIX: Stats unificadas de envíos por carrier. -->
    <?php
    $vendor_id = get_current_user_id();
    global $wpdb;
    $ship_stats = [
        'aveonline' => 0,
        'deprisa'   => 0,
        'heka'      => 0,
        'uber'      => 0,
        'pickup'    => 0,
        'own'       => 0,
    ];
    // Count orders by shipping method for this vendor.
    $ship_counts = $wpdb->get_results( $wpdb->prepare(
        "SELECT om.meta_value AS method_id, COUNT(DISTINCT om.order_id) AS cnt
         FROM {$wpdb->prefix}wc_orders_meta om
         INNER JOIN {$wpdb->prefix}wc_orders_meta om2 ON om.order_id = om2.order_id AND om2.meta_key = '_ltms_vendor_id' AND om2.meta_value = %s
         WHERE om.meta_key = '_shipping_method_id' OR om.meta_key = '_ltms_shipping_method'
         GROUP BY om.meta_value",
        $vendor_id
    ), ARRAY_A );
    if ( empty( $ship_counts ) ) {
        // Legacy postmeta path.
        $ship_counts = $wpdb->get_results( $wpdb->prepare(
            "SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(pm.meta_value, ':', 1), ':', -1) AS method_id, COUNT(DISTINCT pm.post_id) AS cnt
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = pm.post_id AND pm2.meta_key = '_ltms_vendor_id' AND pm2.meta_value = %s
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'shop_order'
             WHERE pm.meta_key = '_shipping_method_id' OR pm.meta_key = '_ltms_shipping_method'
             GROUP BY method_id",
            $vendor_id
        ), ARRAY_A );
    }
    if ( $ship_counts ) {
        foreach ( $ship_counts as $row ) {
            $mid = strtolower( $row['method_id'] ?? '' );
            if ( strpos( $mid, 'aveonline' ) !== false ) $ship_stats['aveonline'] = (int) $row['cnt'];
            elseif ( strpos( $mid, 'deprisa' ) !== false ) $ship_stats['deprisa'] = (int) $row['cnt'];
            elseif ( strpos( $mid, 'heka' ) !== false ) $ship_stats['heka'] = (int) $row['cnt'];
            elseif ( strpos( $mid, 'uber' ) !== false ) $ship_stats['uber'] = (int) $row['cnt'];
            elseif ( strpos( $mid, 'pickup' ) !== false ) $ship_stats['pickup'] = (int) $row['cnt'];
            elseif ( strpos( $mid, 'own' ) !== false ) $ship_stats['own'] = (int) $row['cnt'];
        }
    }
    ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:10px;margin-bottom:20px;">
        <div class="ltms-card" style="padding:12px;text-align:center;">
            <div style="font-size:1.5rem;font-weight:700;color:#1a5276;"><?php echo $ship_stats['aveonline']; ?></div>
            <div style="font-size:0.7rem;color:#666;">📦 Aveonline</div>
        </div>
        <div class="ltms-card" style="padding:12px;text-align:center;">
            <div style="font-size:1.5rem;font-weight:700;color:#dc2626;"><?php echo $ship_stats['deprisa']; ?></div>
            <div style="font-size:0.7rem;color:#666;">📮 Deprisa</div>
        </div>
        <div class="ltms-card" style="padding:12px;text-align:center;">
            <div style="font-size:1.5rem;font-weight:700;color:#7c3aed;"><?php echo $ship_stats['heka']; ?></div>
            <div style="font-size:0.7rem;color:#666;">🚀 Heka</div>
        </div>
        <div class="ltms-card" style="padding:12px;text-align:center;">
            <div style="font-size:1.5rem;font-weight:700;color:#111;"><?php echo $ship_stats['uber']; ?></div>
            <div style="font-size:0.7rem;color:#666;">🚗 Uber</div>
        </div>
        <div class="ltms-card" style="padding:12px;text-align:center;">
            <div style="font-size:1.5rem;font-weight:700;color:#16a34a;"><?php echo $ship_stats['pickup']; ?></div>
            <div style="font-size:0.7rem;color:#666;">🏪 Pickup</div>
        </div>
        <div class="ltms-card" style="padding:12px;text-align:center;">
            <div style="font-size:1.5rem;font-weight:700;color:#d97706;"><?php echo $ship_stats['own']; ?></div>
            <div style="font-size:0.7rem;color:#666;">🛵 Domiciliario</div>
        </div>
    </div>

    <!-- ── CREAR RELACIÓN ─────────────────────────────────────────────── -->
    <div class="ltms-card" style="margin-bottom:24px;">
        <div class="ltms-card-header" style="padding:16px 20px;border-bottom:1px solid #f3f4f6;display:flex;align-items:center;gap:8px;">
            <span style="font-size:1rem;">📋</span>
            <h3 style="margin:0;font-size:0.95rem;font-weight:600;color:#374151;">
                <?php esc_html_e( 'Crear nueva relación', 'ltms' ); ?>
            </h3>
        </div>
        <div class="ltms-card-body" style="padding:20px;">

            <!-- Autocomplete destinatarios -->
            <div style="margin-bottom:20px;padding:14px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;">
                <div style="font-size:0.78rem;font-weight:600;color:#0284c7;margin-bottom:8px;text-transform:uppercase;letter-spacing:0.4px;">
                    🔍 <?php esc_html_e( 'Buscar destinatario en Aveonline (opcional)', 'ltms' ); ?>
                </div>
                <div style="display:flex;gap:8px;align-items:center;">
                    <input type="text" id="ltms-envios-recipient-search"
                           placeholder="<?php esc_attr_e( 'Nombre, email o documento (mín. 3 caracteres)…', 'ltms' ); ?>"
                           style="flex:1;padding:8px 12px;border:1px solid #bae6fd;border-radius:6px;font-size:0.85rem;outline:none;" />
                    <button type="button" id="ltms-envios-recipient-btn"
                            class="ltms-btn ltms-btn-sm" style="white-space:nowrap;background:#0284c7;color:#fff;border:none;border-radius:6px;padding:8px 14px;cursor:pointer;font-size:0.82rem;">
                        <?php esc_html_e( 'Buscar', 'ltms' ); ?>
                    </button>
                </div>
                <div id="ltms-envios-recipient-results" style="margin-top:8px;display:none;"></div>
            </div>

            <!-- Formulario -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
                <div>
                    <label style="display:block;font-size:0.8rem;font-weight:600;color:#374151;margin-bottom:4px;">
                        <?php esc_html_e( 'Transportadora *', 'ltms' ); ?>
                    </label>
                    <select id="ltms-envios-carrier"
                            style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:0.85rem;background:#fff;outline:none;">
                        <option value=""><?php esc_html_e( '— Selecciona transportadora —', 'ltms' ); ?></option>
                        <?php foreach ( $carriers as $c ) : ?>
                            <option value="<?php echo esc_attr( $c['id'] ); ?>">
                                <?php echo esc_html( $c['label'] ?? $c['id'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:0.8rem;font-weight:600;color:#374151;margin-bottom:4px;">
                        <?php esc_html_e( 'Número(s) de guía *', 'ltms' ); ?>
                    </label>
                    <textarea id="ltms-envios-guias" rows="2"
                              placeholder="<?php esc_attr_e( 'Ej: 044013783462, 034033950937', 'ltms' ); ?>"
                              style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:0.85rem;resize:vertical;font-family:monospace;outline:none;box-sizing:border-box;"></textarea>
                    <span style="font-size:0.72rem;color:#9ca3af;"><?php esc_html_e( 'Separa múltiples guías con comas.', 'ltms' ); ?></span>
                </div>
            </div>

            <button type="button" id="ltms-envios-create-btn"
                    class="ltms-btn" style="background:#059669;color:#fff;border:none;border-radius:6px;padding:10px 20px;font-size:0.85rem;font-weight:600;cursor:pointer;">
                ✅ <?php esc_html_e( 'Crear relación de envío', 'ltms' ); ?>
            </button>

            <!-- Resultado de creación -->
            <div id="ltms-envios-create-result" style="display:none;margin-top:16px;"></div>
        </div>
    </div>

    <!-- ── MIS RELACIONES ─────────────────────────────────────────────── -->
    <div class="ltms-card">
        <div class="ltms-card-header" style="padding:16px 20px;border-bottom:1px solid #f3f4f6;display:flex;align-items:center;justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:8px;">
                <span>📦</span>
                <h3 style="margin:0;font-size:0.95rem;font-weight:600;color:#374151;">
                    <?php esc_html_e( 'Mis relaciones', 'ltms' ); ?>
                </h3>
            </div>
            <button type="button" id="ltms-envios-refresh-btn"
                    style="background:none;border:1px solid #d1d5db;border-radius:6px;padding:6px 12px;cursor:pointer;font-size:0.78rem;color:#6b7280;">
                🔄 <?php esc_html_e( 'Actualizar', 'ltms' ); ?>
            </button>
        </div>
        <div class="ltms-card-body" style="padding:0;">
            <div id="ltms-envios-list-wrap" style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:0.82rem;">
                    <thead>
                        <tr style="background:#f9fafb;border-bottom:1px solid #e5e7eb;">
                            <th style="padding:10px 16px;text-align:left;color:#6b7280;font-weight:600;"><?php esc_html_e( '# Relación', 'ltms' ); ?></th>
                            <th style="padding:10px 16px;text-align:left;color:#6b7280;font-weight:600;"><?php esc_html_e( 'Transportadora', 'ltms' ); ?></th>
                            <th style="padding:10px 16px;text-align:left;color:#6b7280;font-weight:600;"><?php esc_html_e( 'Guías', 'ltms' ); ?></th>
                            <th style="padding:10px 16px;text-align:left;color:#6b7280;font-weight:600;"><?php esc_html_e( 'Fecha', 'ltms' ); ?></th>
                            <th style="padding:10px 16px;text-align:center;color:#6b7280;font-weight:600;"><?php esc_html_e( 'Acciones', 'ltms' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="ltms-envios-tbody">
                        <tr>
                            <td colspan="5" style="padding:32px;text-align:center;color:#9ca3af;">
                                <?php esc_html_e( 'Cargando…', 'ltms' ); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal de confirmación de eliminación -->
    <!-- EV-BUG-4: WCAG 2.1 — added role="dialog", aria-modal, aria-labelledby,
         tabindex="-1" for keyboard focus, and role="document" on the inner
         container. Keyboard handling (Escape to close, focus trap, focus
         restoration) is wired up in the external JS (ltms-envios.js). -->
    <?php
// FASE2B P0 FIX (CSP): inline <script> moved to external assets/js/ltms-envios.js
wp_enqueue_script( 'ltms-envios', LTMS_ASSETS_URL . 'js/ltms-envios.js', [ 'jquery' ], LTMS_VERSION, true );
?>
