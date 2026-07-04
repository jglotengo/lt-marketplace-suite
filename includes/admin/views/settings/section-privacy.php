<?php
/**
 * Settings section: Privacidad / ARCO (v2.9.13)
 *
 * Política de retención + herramientas de derechos ARCO / Habeas Data.
 *
 * @package LTMS
 * @version 2.9.13
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Valores actuales.
// Safety: si LTMS_Privacy_Toolkit no esta cargado (autoloader falla), usar defaults hardcodeados.
if ( class_exists( 'LTMS_Privacy_Toolkit' ) ) {
    $retention_config = LTMS_Privacy_Toolkit::get_retention_config();
    $legal_basis      = LTMS_Privacy_Toolkit::get_legal_basis();
} else {
    // Defaults mirror LTMS_Privacy_Toolkit::RETENTION_DEFAULTS — kept in sync manually.
    $retention_config = [
        'transactional' => 365 * 5,   // 5 años (ET art. 632 CO, CFF art. 30 MX)
        'kyc_docs'      => 365 * 10,  // 10 años (SAGRILAFT Res. 314/2021 CO)
        'audit_logs'    => 365 * 5,   // 5 años (Ley 1581/2012 art. 15 CO)
        'consent_log'   => 365 * 10,  // 10 años (LFPDPPP art. 24 MX)
    ];
    $legal_basis = [];
}
$last_run    = get_option( 'ltms_retention_last_run', null );
$nonce       = wp_create_nonce( 'ltms_retention_nonce' );

?>
<div class="ltms-form-section">
    <h2><?php esc_html_e( 'Privacidad / Habeas Data / ARCO', 'ltms' ); ?></h2>
    <p class="description">
        <?php esc_html_e( 'Configura la política de retención de datos personales. Las normas aplicables son: Colombia (Ley 1581/2012, ET art. 632) y México (LFPDPPP, LISR art. 30, CFF art. 30).', 'ltms' ); ?>
    </p>

    <h3><?php esc_html_e( 'Periodos de retención (en días)', 'ltms' ); ?></h3>
    <table class="form-table" role="presentation">
        <tbody>
        <?php foreach ( LTMS_Privacy_Toolkit::RETENTION_DEFAULTS as $key => $default ) : ?>
            <tr>
                <th scope="row">
                    <label for="ltms_retention_<?php echo esc_attr( $key ); ?>">
                        <?php echo esc_html( ucfirst( str_replace( '_', ' ', $key ) ) ); ?>
                    </label>
                </th>
                <td>
                    <input type="number"
                           id="ltms_retention_<?php echo esc_attr( $key ); ?>"
                           name="ltms_retention_<?php echo esc_attr( $key ); ?>"
                           value="<?php echo esc_attr( (string) ( $retention_config[ $key ] ?? $default ) ); ?>"
                           min="30" max="3650" step="1" class="small-text" />
                    <span class="description">
                        <?php
                        echo esc_html( sprintf(
                            /* translators: 1: default value, 2: legal basis */
                            __( 'Default: %1$d días. %2$s', 'ltms' ),
                            $default,
                            $legal_basis[ 'lt_' . $key ] ?? ''
                        ) );
                        ?>
                    </span>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h3><?php esc_html_e( 'Estado de la política de retención', 'ltms' ); ?></h3>
    <p>
        <?php if ( $last_run ) : ?>
            <?php echo esc_html( sprintf(
                /* translators: 1: last run date */
                __( 'Última ejecución: %s', 'ltms' ),
                $last_run['run_at'] ?? __( '(desconocida)', 'ltms' )
            ) ); ?>
        <?php else : ?>
            <?php esc_html_e( 'La política de retención aún no se ha ejecutado. Se ejecutará automáticamente cada día vía cron.', 'ltms' ); ?>
        <?php endif; ?>
    </p>

    <p>
        <button type="button"
                id="ltms-run-retention-now"
                class="button button-secondary"
                data-nonce="<?php echo esc_attr( $nonce ); ?>">
            <?php esc_html_e( 'Ejecutar política de retención ahora', 'ltms' ); ?>
        </button>
        <span id="ltms-retention-status" style="margin-left:12px;font-style:italic;"></span>
    </p>

    <h3><?php esc_html_e( 'Herramientas de WordPress', 'ltms' ); ?></h3>
    <p class="description">
        <?php
        echo wp_kses_post( sprintf(
            /* translators: 1: URL to export tool, 2: URL to erasure tool */
            __( 'Los derechos ARCO (Acceso, Rectificación, Cancelación, Oposición) y Habeas Data se gestionan desde las herramientas nativas de WordPress: <a href="%1$s">Exportar datos personales</a> · <a href="%2$s">Borrar datos personales</a>.', 'ltms' ),
            esc_url( admin_url( 'tools.php?page=export_personal_data' ) ),
            esc_url( admin_url( 'tools.php?page=remove_personal_data' ) )
        ) );
        ?>
    </p>
    <p class="description">
        <?php esc_html_e( 'LTMS registra automáticamente 6 exporters (perfil, KYC, billetera, comisiones, payouts, consentimientos) y 2 erasers (archivos KYC en B2 + datos extendidos en 10+ tablas).', 'ltms' ); ?>
    </p>

    <h3><?php esc_html_e( 'Endpoints REST para autoservicio ARCO', 'ltms' ); ?></h3>
    <p class="description">
        <code>GET /wp-json/ltms/v1/arco/access</code> — <?php esc_html_e( 'Obtener todos mis datos personales.', 'ltms' ); ?><br>
        <code>POST /wp-json/ltms/v1/arco/rectify</code> — <?php esc_html_e( 'Rectificar mis datos.', 'ltms' ); ?><br>
        <code>POST /wp-json/ltms/v1/arco/cancel</code> — <?php esc_html_e( 'Solicitar supresión (anonimización inmediata + retención programada de registros fiscales).', 'ltms' ); ?><br>
        <code>POST /wp-json/ltms/v1/arco/oppose</code> — <?php esc_html_e( 'Oponerse al tratamiento (marketing, profiling, data sharing).', 'ltms' ); ?>
    </p>
</div>

<script>
jQuery(function($){
    $('#ltms-run-retention-now').on('click', function(){
        var $btn = $(this), $status = $('#ltms-retention-status');
        $btn.prop('disabled', true);
        $status.text('<?php echo esc_js( __( 'Ejecutando...', 'ltms' ) ); ?>');
        $.post(ajaxurl, {
            action: 'ltms_run_retention_policy',
            nonce: $btn.data('nonce')
        }).done(function(resp){
            if (resp && resp.success) {
                var rows = 0;
                if (resp.data && resp.data.tables) {
                    $.each(resp.data.tables, function(k,v){ rows += (v.rows||0); });
                }
                $status.text('<?php echo esc_js( __( 'OK —', 'ltms' ) ); ?> ' + rows + ' <?php echo esc_js( __( 'filas procesadas.', 'ltms' ) ); ?>');
            } else {
                $status.text('<?php echo esc_js( __( 'Error.', 'ltms' ) ); ?>');
            }
        }).fail(function(){
            $status.text('<?php echo esc_js( __( 'Error de red.', 'ltms' ) ); ?>');
        }).always(function(){
            $btn.prop('disabled', false);
        });
    });
});
</script>
