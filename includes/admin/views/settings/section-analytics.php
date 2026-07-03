<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Sección de configuración: Analytics & Tracking
 * Nivel plataforma: GTM, GA4, Meta Pixel.
 *
 * Lógica de prioridad (igual que LTMS_Analytics_Manager::init):
 *  - Si hay GTM_ID  → se usa GTM como contenedor único (GA4 y Pixel van dentro del contenedor).
 *  - Si NO hay GTM  → se inyectan GA4 y Meta Pixel directamente en <head>.
 *
 * @since 2.3.0
 */
$gtm_id    = get_option( 'ltms_google_tag_manager_id', '' );
$ga4_id    = get_option( 'ltms_ga4_measurement_id', '' );
$pixel_id  = get_option( 'ltms_meta_pixel_id', '' );
$vendor_ga4_enabled   = get_option( 'ltms_vendor_ga4_enabled',   'yes' );
$vendor_pixel_enabled = get_option( 'ltms_vendor_pixel_enabled', 'yes' );
?>
<div class="ltms-settings-section">
    <h2 style="margin-top:24px;">📊 Analytics &amp; Tracking — Plataforma</h2>
    <p class="description" style="margin-bottom:16px;">
        Configura el tracking del marketplace (Lo Tengo). Si usas <strong>GTM</strong>, ingresa solo ese ID y
        configura GA4 / Meta Pixel <em>dentro</em> del contenedor de GTM. Si no usas GTM, ingresa GA4 y/o
        Meta Pixel directamente.
    </p>

    <table class="form-table" role="presentation"><tbody>

    <tr>
        <th scope="row">Google Tag Manager (GTM)</th>
        <td>
            <input type="text" name="ltms_google_tag_manager_id"
                   value="<?php echo esc_attr( $gtm_id ); ?>"
                   placeholder="GTM-XXXXXXX" class="regular-text">
            <p class="description">Si lo completas, GA4 y Meta Pixel directos quedan desactivados en favor de GTM.</p>
        </td>
    </tr>

    <tr id="ltms-row-ga4" <?php echo $gtm_id ? 'style="opacity:0.4;pointer-events:none;"' : ''; ?>>
        <th scope="row">Google Analytics 4 (GA4)</th>
        <td>
            <input type="text" name="ltms_ga4_measurement_id"
                   value="<?php echo esc_attr( $ga4_id ); ?>"
                   placeholder="G-XXXXXXXXXX" class="regular-text">
            <p class="description">Measurement ID de GA4. Ignorado si GTM está activo.</p>
        </td>
    </tr>

    <tr id="ltms-row-pixel" <?php echo $gtm_id ? 'style="opacity:0.4;pointer-events:none;"' : ''; ?>>
        <th scope="row">Meta Pixel (Facebook)</th>
        <td>
            <input type="text" name="ltms_meta_pixel_id"
                   value="<?php echo esc_attr( $pixel_id ); ?>"
                   placeholder="123456789012345" class="regular-text">
            <p class="description">Pixel ID de Meta Ads. Ignorado si GTM está activo.</p>
        </td>
    </tr>

    </tbody></table>

    <h2 style="margin-top:32px;">🏪 Tracking por Vendedor</h2>
    <p class="description" style="margin-bottom:16px;">
        Permite que cada vendedor configure su propio GA4 y/o Meta Pixel. Sus píxeles se inyectan
        <strong>solo en las páginas de sus productos</strong>, en paralelo con los píxeles de la plataforma.
        Los vendedores <strong>nunca</strong> tienen acceso a GTM (riesgo de seguridad).
    </p>

    <table class="form-table" role="presentation"><tbody>

    <tr>
        <th scope="row">GA4 por vendedor</th>
        <td>
            <label>
                <input type="checkbox" name="ltms_vendor_ga4_enabled" value="yes"
                       <?php checked( $vendor_ga4_enabled, 'yes' ); ?>>
                Permitir que los vendedores configuren su Google Analytics 4
            </label>
        </td>
    </tr>

    <tr>
        <th scope="row">Meta Pixel por vendedor</th>
        <td>
            <label>
                <input type="checkbox" name="ltms_vendor_pixel_enabled" value="yes"
                       <?php checked( $vendor_pixel_enabled, 'yes' ); ?>>
                Permitir que los vendedores configuren su Meta Pixel
            </label>
        </td>
    </tr>

    </tbody></table>
</div>

<script>
(function () {
    var gtmField = document.querySelector('[name="ltms_google_tag_manager_id"]');
    var ga4Row   = document.getElementById('ltms-row-ga4');
    var pixRow   = document.getElementById('ltms-row-pixel');
    if (!gtmField || !ga4Row || !pixRow) return;
    function toggleRows() {
        var hasGtm = gtmField.value.trim().length > 0;
        ga4Row.style.opacity        = hasGtm ? '0.4' : '1';
        ga4Row.style.pointerEvents  = hasGtm ? 'none' : '';
        pixRow.style.opacity        = hasGtm ? '0.4' : '1';
        pixRow.style.pointerEvents  = hasGtm ? 'none' : '';
    }
    gtmField.addEventListener('input', toggleRows);
    toggleRows();
})();
</script>
