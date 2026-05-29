<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LTMS_Auditor_Panel {

    public static function init(): void {
        add_action( 'ltms_auditor_dashboard_content', [ self::class, 'render_dashboard' ] );
        add_action( 'wp_ajax_ltms_export_csv',        [ self::class, 'ajax_export_csv' ] );
    }

    public static function render_dashboard(): void {
        global $wpdb;

        $date_from = sanitize_text_field( $_GET['date_from'] ?? date('Y-m-01') );
        $date_to   = sanitize_text_field( $_GET['date_to']   ?? date('Y-m-d') );
        $country   = sanitize_text_field( $_GET['country']   ?? '' );
        $nivel     = sanitize_text_field( $_GET['nivel']      ?? '' );

        // ── Métricas ──────────────────────────────────────────────────
        $where  = "WHERE status != 'sandbox' AND created_at BETWEEN %s AND %s";
        $params = [ $date_from . ' 00:00:00', $date_to . ' 23:59:59' ];
        if ( $country ) { $where .= " AND country_code = %s"; $params[] = $country; }

        $stats = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*)                          AS transacciones,
                COUNT(DISTINCT vendor_id)         AS vendedores,
                SUM(gross_amount)                 AS bruto,
                SUM(commission_amount)            AS fee,
                SUM(vendor_amount)                AS neto,
                SUM(isr_amount)                   AS isr_ret,
                SUM(iva_amount)                   AS iva_trasl,
                SUM(reteiva_amount)               AS reteiva,
                SUM(retefuente_amount)            AS retefuente,
                SUM(reteica_amount)               AS reteica,
                SUM(ieps_amount)                  AS ieps,
                SUM(CASE WHEN service_type='hospedaje'  THEN 1 ELSE 0 END) AS hosp_ops,
                SUM(CASE WHEN service_type='importacion' THEN 1 ELSE 0 END) AS imp_ops
             FROM {$wpdb->prefix}lt_commissions $where",
            ...$params
        ), ARRAY_A );

        $stats = $stats ?: [];
        $fmt   = fn($v) => number_format( (float)($v ?? 0), 2, '.', ',' );
        $nonce = wp_create_nonce('ltms_export_csv');
        $export_url = add_query_arg([
            'action'    => 'ltms_export_csv',
            'date_from' => $date_from,
            'date_to'   => $date_to,
            'country'   => $country,
            '_wpnonce'  => $nonce,
        ], admin_url('admin-ajax.php'));
        ?>
        <div style="padding:0 20px 20px;">

            <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
                <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <input type="hidden" name="page" value="ltms-auditor">
                    <label>Desde <input type="date" name="date_from" value="<?= esc_attr($date_from) ?>" style="border-radius:4px;border:1px solid #ccc;padding:4px 8px;"></label>
                    <label>Hasta <input type="date" name="date_to"   value="<?= esc_attr($date_to)   ?>" style="border-radius:4px;border:1px solid #ccc;padding:4px 8px;"></label>
                    <label>País
                        <select name="country" style="border-radius:4px;border:1px solid #ccc;padding:4px 8px;">
                            <option value="">Todos</option>
                            <option value="CO" <?= selected($country,'CO',false) ?>>Colombia</option>
                            <option value="MX" <?= selected($country,'MX',false) ?>>México</option>
                        </select>
                    </label>
                    <button type="submit" class="button button-primary">🔍 Filtrar</button>
                </form>
                <a href="<?= esc_url($export_url) ?>" class="button button-primary" style="background:#2e7d32;border-color:#2e7d32;">
                    ⬇ Exportar CSV
                </a>
            </div>

            <?php
            $cards = [
                ['TRANSACCIONES',    $stats['transacciones'] ?? 0,    '#1a5276', ''],
                ['BRUTO VENDEDOR',   $fmt($stats['bruto']),            '#154360', 'Suma gross_amount'],
                ['FEE PLATAFORMA',   $fmt($stats['fee']),              '#0e6655', 'commission_amount'],
                ['NETO VENDEDOR',    $fmt($stats['neto']),             '#1e8449', 'vendor_amount'],
                ['ISR RETENIDO',     $fmt($stats['isr_ret']),          '#784212', 'Art. 113-A LISR'],
                ['IVA TRASLADADO',   $fmt($stats['iva_trasl']),        '#6e2f84', 'LIVA Art. 1-A BIS'],
                ['RETEIVA',          $fmt($stats['reteiva']),          '#4a148c', 'LIVA Art. 18-B'],
                ['RETEFUENTE',       $fmt($stats['retefuente']),       '#880e4f', 'E.T. Art. 437-2'],
                ['RETEICA / IEPS',   $fmt($stats['ieps']),             '#b71c1c', 'LIEPS Art. 2'],
                ['OPS. HOSPEDAJE',   $stats['hosp_ops'] ?? 0,          '#c62828', 'Art. 30-B frac. II g)'],
                ['OPS. IMPORTACIÓN', $stats['imp_ops']  ?? 0,          '#ad1457', 'Art. 30-B frac. II h)'],
            ];
            echo '<div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:24px;">';
            foreach ( $cards as [$label, $val, $color, $sub] ) {
                echo "<div style='background:{$color};color:#fff;border-radius:8px;padding:16px 20px;min-width:160px;'>
                    <div style='font-size:11px;text-transform:uppercase;opacity:.8;'>{$label}</div>
                    <div style='font-size:24px;font-weight:700;margin:4px 0;'>{$val}</div>
                    <div style='font-size:10px;opacity:.7;'>{$sub}</div>
                </div>";
            }
            echo '</div>';
            ?>

            <h3 style="margin-top:20px;">MX Información Fiscal — Art. 30-B CFF</h3>
            <p>Período: <strong><?= esc_html($date_from) ?></strong> → <strong><?= esc_html($date_to) ?></strong> |
               Transacciones: <strong><?= intval($stats['transacciones'] ?? 0) ?></strong> |
               Vendedores únicos: <strong><?= intval($stats['vendedores'] ?? 0) ?></strong></p>
        </div>
        <?php
    }

    public static function ajax_export_csv(): void {
        if ( ! check_ajax_referer('ltms_export_csv', '_wpnonce', false) ) {
            wp_die('Nonce inválido', 403);
        }
        if ( ! current_user_can('ltms_export_reports') && ! current_user_can('manage_options') ) {
            wp_die('Sin permiso', 403);
        }

        $args = [
            'date_from' => sanitize_text_field( $_GET['date_from'] ?? date('Y-m-01') ),
            'date_to'   => sanitize_text_field( $_GET['date_to']   ?? date('Y-m-d') ),
            'country'   => sanitize_text_field( $_GET['country']   ?? '' ),
            'limit'     => 5000,
        ];

        require_once __DIR__ . "/class-ltms-fiscal-exporter.php";
        $result = LTMS_Fiscal_Exporter::generate_csv( $args );

        if ( isset($result['error']) ) {
            wp_die( esc_html($result['error']), 404 );
        }

        $filename = basename( $result['file'] );
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . filesize($result['file']) );
        header( 'Pragma: no-cache' );
        readfile( $result['file'] );
        exit;
    }
}
