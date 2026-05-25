<?php
if ( ! defined( 'ABSPATH' ) ) exit;
// M-01 FIX: ltms_mlm_l1_rate / ltms_mlm_l2_rate / ltms_mlm_l3_rate eran ignorados —
// LTMS_Referral_Tree::get_referral_rates() lee ltms_referral_rates (JSON array de decimales).
// Se unifica en un solo campo textarea JSON, igual que se hizo en section-commissions.php (C-02).
// M-02 FIX: ltms_mlm_levels ahora controla cuántos niveles se envían en el JSON.
// M-03 FIX: ltms_mlm_min_sales_activate se marca tipo 'integer' para sanitizarse con absint.
$levels_value     = get_option( 'ltms_mlm_levels', '2' );
$ref_rates_raw    = get_option( 'ltms_referral_rates', '' );
$ref_rates_arr    = ( $ref_rates_raw !== '' ) ? json_decode( $ref_rates_raw, true ) : null;

// Convertir tasas guardadas (decimales) a porcentaje para mostrar en UI
$l1_display = isset( $ref_rates_arr[0] ) ? round( (float) $ref_rates_arr[0] * 100, 4 ) : 5;
$l2_display = isset( $ref_rates_arr[1] ) ? round( (float) $ref_rates_arr[1] * 100, 4 ) : 2;
$l3_display = isset( $ref_rates_arr[2] ) ? round( (float) $ref_rates_arr[2] * 100, 4 ) : 1;
?>
<div class="ltms-settings-section">
    <h2 style="margin-top:24px;">🤝 Marketing / Red de Afiliados (MLM)</h2>
    <table class="form-table" role="presentation"><tbody>

    <tr>
        <th scope="row">MLM Activo</th>
        <td>
            <?php $v = get_option('ltms_mlm_enabled','no'); ?>
            <label><input type="checkbox" name="ltms_mlm_enabled" value="yes" <?php checked($v,'yes');?>>
            Activar sistema de referidos multi-nivel</label>
        </td>
    </tr>

    <tr>
        <th scope="row">Niveles de Comisión</th>
        <td>
            <select name="ltms_mlm_levels" id="ltms_mlm_levels_select">
                <option value="1" <?php selected($levels_value,'1');?>>1 nivel</option>
                <option value="2" <?php selected($levels_value,'2');?>>2 niveles</option>
                <option value="3" <?php selected($levels_value,'3');?>>3 niveles</option>
            </select>
        </td>
    </tr>

    <tr>
        <th scope="row">Comisión Nivel 1 (%)</th>
        <td>
            <input type="number" step="0.01" min="0" max="100"
                   name="ltms_mlm_ui_l1" id="ltms_mlm_ui_l1"
                   value="<?php echo esc_attr($l1_display); ?>" class="small-text"> %
        </td>
    </tr>

    <tr id="ltms_mlm_row_l2" <?php echo ($levels_value < 2) ? 'style="display:none"' : ''; ?>>
        <th scope="row">Comisión Nivel 2 (%)</th>
        <td>
            <input type="number" step="0.01" min="0" max="100"
                   name="ltms_mlm_ui_l2" id="ltms_mlm_ui_l2"
                   value="<?php echo esc_attr($l2_display); ?>" class="small-text"> %
        </td>
    </tr>

    <tr id="ltms_mlm_row_l3" <?php echo ($levels_value < 3) ? 'style="display:none"' : ''; ?>>
        <th scope="row">Comisión Nivel 3 (%)</th>
        <td>
            <input type="number" step="0.01" min="0" max="100"
                   name="ltms_mlm_ui_l3" id="ltms_mlm_ui_l3"
                   value="<?php echo esc_attr($l3_display); ?>" class="small-text"> %
        </td>
    </tr>

    <?php
    // Campo oculto: ltms_referral_rates se construye en JS antes del submit
    // a partir de ltms_mlm_ui_l1/l2/l3 y ltms_mlm_levels — en decimales.
    ?>
    <input type="hidden" name="ltms_referral_rates" id="ltms_referral_rates_json"
           value="<?php echo esc_attr( $ref_rates_raw !== '' ? $ref_rates_raw : '[0.05,0.02,0.01]' ); ?>">

    <tr>
        <th scope="row">Ventas mínimas para activar red</th>
        <td>
            <?php $v = absint( get_option('ltms_mlm_min_sales_activate', 1) ); ?>
            <input type="number" step="1" min="0" name="ltms_mlm_min_sales_activate"
                   value="<?php echo esc_attr($v); ?>" class="small-text">
            <p class="description">Número mínimo de ventas completadas para activar la red de referidos del vendedor.</p>
        </td>
    </tr>

    </tbody></table>
</div>
<script>
(function(){
    function syncReferralRatesJSON() {
        var levels  = parseInt(document.getElementById('ltms_mlm_levels_select').value, 10);
        var l1      = parseFloat(document.getElementById('ltms_mlm_ui_l1').value) || 0;
        var l2      = parseFloat(document.getElementById('ltms_mlm_ui_l2').value) || 0;
        var l3      = parseFloat(document.getElementById('ltms_mlm_ui_l3').value) || 0;
        var rates   = [];
        // Convert % → decimal and respect levels count
        if (levels >= 1) rates.push(parseFloat((l1 / 100).toFixed(6)));
        if (levels >= 2) rates.push(parseFloat((l2 / 100).toFixed(6)));
        if (levels >= 3) rates.push(parseFloat((l3 / 100).toFixed(6)));
        document.getElementById('ltms_referral_rates_json').value = JSON.stringify(rates);
        // Show/hide rows
        document.getElementById('ltms_mlm_row_l2').style.display = (levels >= 2) ? '' : 'none';
        document.getElementById('ltms_mlm_row_l3').style.display = (levels >= 3) ? '' : 'none';
    }
    document.getElementById('ltms_mlm_levels_select').addEventListener('change', syncReferralRatesJSON);
    // Sync on any rate input change too
    ['ltms_mlm_ui_l1','ltms_mlm_ui_l2','ltms_mlm_ui_l3'].forEach(function(id){
        document.getElementById(id).addEventListener('input', syncReferralRatesJSON);
    });
    // Run once on load to ensure JSON field is in sync
    syncReferralRatesJSON();
    // Ensure sync happens just before form submit
    var form = document.getElementById('ltms_mlm_levels_select').closest('form');
    if (form) form.addEventListener('submit', syncReferralRatesJSON);
})();
</script>
