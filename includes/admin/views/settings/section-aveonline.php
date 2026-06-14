<?php
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="ltms-settings-section">
    <h2 style="margin-top:24px;">🚚 Aveonline — Logística y Envíos Colombia</h2>
    <p style="color:#666; margin-bottom:16px;">
        Integración con la <strong>API v2</strong> de Aveonline (<code>https://app.aveonline.co/api</code>).
        El token JWT se renueva automáticamente cada 11 horas.
        <a href="https://integraciones.aveonline.co/docs/nacional/autenticacion" target="_blank">Ver documentación →</a>
    </p>

    <table class="form-table" role="presentation"><tbody>

        <tr>
            <th><?php esc_html_e( 'Habilitar Aveonline', 'ltms' ); ?></th>
            <td>
                <input type="checkbox" name="ltms_aveonline_enabled" value="yes"
                    <?php checked( get_option( 'ltms_aveonline_enabled', 'no' ), 'yes' ); ?>>
                <label><?php esc_html_e( 'Activar integración de envíos con Aveonline', 'ltms' ); ?></label>
            </td>
        </tr>

        <tr>
            <th><?php esc_html_e( 'Usuario Aveonline', 'ltms' ); ?></th>
            <td>
                <input type="text" name="ltms_aveonline_usuario"
                    value="<?php echo esc_attr( get_option( 'ltms_aveonline_usuario', '' ) ); ?>"
                    class="regular-text" autocomplete="off">
                <p class="description"><?php esc_html_e( 'Usuario de ingreso a la plataforma Aveonline.', 'ltms' ); ?></p>
            </td>
        </tr>

        <tr>
            <th><?php esc_html_e( 'Contraseña 🔐', 'ltms' ); ?></th>
            <td>
                <input type="password" name="ltms_aveonline_clave"
                    value="" class="regular-text" autocomplete="new-password"
                    placeholder="<?php esc_attr_e( 'Dejar vacío para no cambiar', 'ltms' ); ?>">
                <p class="description"><?php esc_html_e( 'Contraseña de la plataforma. Se guarda cifrada con AES-256.', 'ltms' ); ?></p>
            </td>
        </tr>

        <tr>
            <th><?php esc_html_e( 'ID Empresa (idempresa)', 'ltms' ); ?></th>
            <td>
                <input type="text" name="ltms_aveonline_idempresa"
                    value="<?php echo esc_attr( get_option( 'ltms_aveonline_idempresa', '' ) ); ?>"
                    class="regular-text" inputmode="numeric">
                <p class="description">
                    <?php esc_html_e( 'Número de ID de la empresa dentro de Aveonline.', 'ltms' ); ?>
                    <?php esc_html_e( 'Se obtiene en la respuesta de autenticación: cuentas[0].usuarios[0].id', 'ltms' ); ?>
                </p>
            </td>
        </tr>

        <tr>
            <th><?php esc_html_e( 'ID Agente (idagente)', 'ltms' ); ?></th>
            <td>
                <input type="text" name="ltms_aveonline_idagente"
                    value="<?php echo esc_attr( get_option( 'ltms_aveonline_idagente', '' ) ); ?>"
                    class="regular-text">
                <p class="description"><?php esc_html_e( 'Agente logístico asociado a la cuenta. Se obtiene del listado de agentes de Aveonline.', 'ltms' ); ?></p>
            </td>
        </tr>

        <tr>
            <th><?php esc_html_e( 'Transportadora por defecto', 'ltms' ); ?></th>
            <td>
                <input type="text" name="ltms_aveonline_idtransportador"
                    value="<?php echo esc_attr( get_option( 'ltms_aveonline_idtransportador', '' ) ); ?>"
                    class="regular-text" placeholder="ej: 29">
                <p class="description"><?php esc_html_e( 'Código de la transportadora (ej: 29 = ENVIA, 1010 = TCC, 1022 = SAFERBO). Dejar vacío para cotizar con todas las disponibles.', 'ltms' ); ?></p>
            </td>
        </tr>

        <tr>
            <th><?php esc_html_e( 'Código de guía (codigo)', 'ltms' ); ?></th>
            <td>
                <input type="text" name="ltms_aveonline_codigo"
                    value="<?php echo esc_attr( get_option( 'ltms_aveonline_codigo', '' ) ); ?>"
                    class="regular-text">
                <p class="description"><?php esc_html_e( 'Usuario secundario requerido para el endpoint generarGuia2.', 'ltms' ); ?></p>
            </td>
        </tr>

        <tr>
            <th><?php esc_html_e( 'Clave de guía 🔐 (dsclavex)', 'ltms' ); ?></th>
            <td>
                <input type="password" name="ltms_aveonline_clave_guia"
                    value="" class="regular-text" autocomplete="new-password"
                    placeholder="<?php esc_attr_e( 'Dejar vacío para no cambiar', 'ltms' ); ?>">
                <p class="description"><?php esc_html_e( 'Contraseña secundaria para generación de guías. Se guarda cifrada.', 'ltms' ); ?></p>
            </td>
        </tr>

        <tr>
            <th><?php esc_html_e( 'Ciudad de origen (bodega)', 'ltms' ); ?></th>
            <td>
                <input type="text" name="ltms_store_city"
                    value="<?php echo esc_attr( get_option( 'ltms_store_city', 'Bogotá' ) ); ?>"
                    class="regular-text" placeholder="ej: MEDELLIN(ANTIOQUIA)">
                <p class="description"><?php esc_html_e( 'Ciudad desde donde se despachan los paquetes. Usar el nombre exacto del listado de ciudades de Aveonline. Ej: BOGOTA(CUNDINAMARCA)', 'ltms' ); ?></p>
            </td>
        </tr>

    </tbody></table>

    <hr style="margin:24px 0;">
    <h3><?php esc_html_e( 'Estado de conexión', 'ltms' ); ?></h3>
    <?php
    $token_cached = get_transient( 'ltms_aveonline_jwt' );
    if ( $token_cached ) {
        echo '<p style="color:#0a7c00;">✅ ' . esc_html__( 'Token JWT activo en caché.', 'ltms' ) . '</p>';
    } else {
        echo '<p style="color:#999;">⚪ ' . esc_html__( 'Sin token activo — se obtendrá al realizar la primera cotización o guía.', 'ltms' ) . '</p>';
    }
    ?>

    <hr style="margin:24px 0;">
    <h3><?php esc_html_e( 'Catálogo de ciudades Aveonline', 'ltms' ); ?></h3>
    <p class="description"><?php esc_html_e( 'El plugin sincroniza automáticamente el listado oficial de ciudades de Aveonline cada 24 horas. Puedes forzar una sincronización manual aquí.', 'ltms' ); ?></p>
    <?php
    if ( class_exists( 'LTMS_Business_Aveonline_Cities' ) ) {
        $city_count = LTMS_Business_Aveonline_Cities::count();
        $last_sync  = LTMS_Business_Aveonline_Cities::last_sync_at();
        if ( $city_count > 0 ) {
            echo '<p style="color:#0a7c00;">✅ <strong>' . esc_html( number_format( $city_count ) ) . '</strong> ' . esc_html__( 'ciudades en el catálogo local.', 'ltms' );
            if ( $last_sync ) {
                echo ' ' . esc_html__( 'Última sincronización:', 'ltms' ) . ' <strong>' . esc_html( wp_date( 'd/m/Y H:i', $last_sync ) ) . '</strong>';
            }
            echo '</p>';
        } else {
            echo '<p style="color:#d63638;">⚠️ ' . esc_html__( 'Catálogo vacío — haz clic en "Sincronizar ahora" para cargar las ciudades.', 'ltms' ) . '</p>';
        }
    }
    ?>
    <p>
        <button type="button" id="ltms-sync-cities-btn" class="button button-secondary">
            🔄 <?php esc_html_e( 'Sincronizar ciudades ahora', 'ltms' ); ?>
        </button>
        <span id="ltms-sync-cities-status" style="margin-left:12px;display:none;"></span>
    </p>
    <script>
    (function($){
        $('#ltms-sync-cities-btn').on('click', function(){
            var $btn    = $(this);
            var $status = $('#ltms-sync-cities-status');
            $btn.prop('disabled', true).text('<?php echo esc_js( __( 'Sincronizando...', 'ltms' ) ); ?>');
            $status.hide();
            $.post(ajaxurl, {
                action: 'ltms_aveonline_sync_cities',
                nonce:  '<?php echo esc_js( wp_create_nonce( 'ltms_aveonline_sync_cities' ) ); ?>'
            }, function(resp){
                $btn.prop('disabled', false).text('🔄 <?php echo esc_js( __( 'Sincronizar ciudades ahora', 'ltms' ) ); ?>');
                if (resp.success) {
                    $status.css('color','#0a7c00').text('✅ ' + resp.data.message).show();
                } else {
                    $status.css('color','#d63638').text('❌ ' + (resp.data.message || 'Error')).show();
                }
            }).fail(function(){
                $btn.prop('disabled', false).text('🔄 <?php echo esc_js( __( 'Sincronizar ciudades ahora', 'ltms' ) ); ?>');
                $status.css('color','#d63638').text('❌ <?php echo esc_js( __( 'Error de red. Intenta de nuevo.', 'ltms' ) ); ?>').show();
            });
        });
    })(jQuery);
    </script>

    <h3 style="margin-top:16px;"><?php esc_html_e( 'Referencia de códigos de transportadora', 'ltms' ); ?></h3>
    <table class="widefat striped" style="max-width:500px;">
        <thead><tr><th><?php esc_html_e( 'Código', 'ltms' ); ?></th><th><?php esc_html_e( 'Transportadora', 'ltms' ); ?></th></tr></thead>
        <tbody>
            <tr><td>29</td><td>ENVIA - nacional</td></tr>
            <tr><td>1010</td><td>TCC SA - nacional</td></tr>
            <tr><td>1022</td><td>SAFERBO - nacional</td></tr>
            <tr><td>33</td><td>SERVIENTREGA</td></tr>
            <tr><td><em><?php esc_html_e( 'vacío', 'ltms' ); ?></em></td><td><?php esc_html_e( 'Todas las habilitadas en tu cuenta', 'ltms' ); ?></td></tr>
        </tbody>
    </table>
</div>
