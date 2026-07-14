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
            <th><?php esc_html_e( 'Habilitar Órdenes de Compra', 'ltms' ); ?></th>
            <td>
                <input type="checkbox" name="ltms_ordenes_compra_enabled" value="yes"
                    <?php checked( get_option( 'ltms_ordenes_compra_enabled', 'no' ), 'yes' ); ?>>
                <label><?php esc_html_e( 'Mostrar el módulo "Órdenes de Compra" en el dashboard de vendors', 'ltms' ); ?></label>
                <p class="description">
                    <?php esc_html_e( 'Permite a los vendors generar órdenes de compra hacia proveedores registrados en Aveonline (dropshipping externo). Desactivado por defecto; el código y las tablas permanecen intactos al ocultarlo.', 'ltms' ); ?>
                </p>
            </td>
        </tr>

        <tr>
            <th><?php esc_html_e( 'Token Onboarding (JWT) 🔐', 'ltms' ); ?></th>
            <td>
                <input type="password" name="ltms_aveonline_onboarding_token"
                    value="" class="regular-text" autocomplete="new-password"
                    placeholder="<?php esc_attr_e( 'Dejar vacío para no cambiar', 'ltms' ); ?>">
                <p class="description">
                    <?php esc_html_e( 'Token JWT estático que Aveonline asigna a tu plataforma para la API de Onboarding de clientes (/api-onboarding/public/api/v1/external/…). Se guarda cifrado con AES-256.', 'ltms' ); ?>
                    <a href="https://integraciones.aveonline.co/docs/registro-clientes-api" target="_blank"><?php esc_html_e( 'Ver documentación →', 'ltms' ); ?></a>
                </p>
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
                <p class="description"><?php esc_html_e( 'Código de la transportadora (ej: 1016 = Interrápidísimo, 1010 = TCC, 1009 = Coordinadora, 29 = Envía, 33 = Servientrega). Dejar vacío para cotizar con todas las disponibles.', 'ltms' ); ?></p>
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
    <h3>🔔 <?php esc_html_e( 'Webhook de estados de guía', 'ltms' ); ?></h3>
    <p class="description">
        <?php esc_html_e( 'Aveonline notifica cada cambio de estado de una guía al webhook personalizado registrado en', 'ltms' ); ?>
        <a href="https://guias.aveonline.co/panel/mis-integraciones" target="_blank">Mis integraciones →</a>
    </p>
    <table class="form-table" role="presentation"><tbody>
        <tr>
            <th><?php esc_html_e( 'URL del Webhook (registrar en Aveonline)', 'ltms' ); ?></th>
            <td>
                <code style="display:inline-block;padding:6px 10px;background:#f0f0f0;border:1px solid #ccc;border-radius:3px;font-size:13px;word-break:break-all;">
                    <?php echo esc_html( rest_url( 'ltms/v1/webhooks/aveonline' ) ); ?>
                </code>
                <p class="description"><?php esc_html_e( 'Copia esta URL y pégala en el campo "webhook url" de tu integración en Aveonline.', 'ltms' ); ?></p>
            </td>
        </tr>
        <tr>
            <th><?php esc_html_e( 'Token del Webhook 🔐', 'ltms' ); ?></th>
            <td>
                <input type="text" name="ltms_aveonline_webhook_token"
                    value="<?php echo esc_attr( get_option( 'ltms_aveonline_webhook_token', '' ) ); ?>"
                    class="regular-text" autocomplete="off"
                    placeholder="<?php esc_attr_e( 'Ej: 000000^cZtUEYw', 'ltms' ); ?>">
                <p class="description">
                    <?php esc_html_e( 'Token que registraste en Aveonline al crear el webhook personalizado. Se usará para validar cada notificación entrante. Si está vacío, no se valida el token (no recomendado en producción).', 'ltms' ); ?>
                </p>
            </td>
        </tr>
    </tbody></table>

    <hr style="margin:24px 0;">
    <h3>🛰️ <?php esc_html_e( 'Ave-Hub — Reporte de estados de envíos propios', 'ltms' ); ?></h3>
    <p class="description">
        <?php esc_html_e( 'Lo Tengo actúa como proveedor logístico dentro del ecosistema Ave-Hub para los envíos gestionados directamente (domiciliario propio del vendedor, recogida en tienda, etc.). Cada cambio de estado de esos envíos se reporta a Ave-Hub mediante esta integración.', 'ltms' ); ?>
    </p>
    <table class="form-table" role="presentation"><tbody>
        <tr>
            <th><?php esc_html_e( 'ID Transportadora (Ave-Hub)', 'ltms' ); ?></th>
            <td>
                <input type="text" name="ltms_aveonline_hub_idtransportadora"
                    value="<?php echo esc_attr( get_option( 'ltms_aveonline_hub_idtransportadora', '' ) ); ?>"
                    class="regular-text" inputmode="numeric" placeholder="ej: 1026">
                <p class="description">
                    <?php esc_html_e( 'ID numérico del proveedor logístico asignado a Lo Tengo dentro de Ave-Hub (distinto del ID de empresa de la API principal).', 'ltms' ); ?>
                </p>
            </td>
        </tr>
        <tr>
            <th><?php esc_html_e( 'Estado del token Ave-Hub', 'ltms' ); ?></th>
            <td>
                <?php
                $hub_token_expires = (int) get_option( 'ltms_aveonline_hub_token_expires', 0 );
                if ( $hub_token_expires > time() ) {
                    echo '<p style="color:#0a7c00;margin:0 0 8px;">✅ ' . esc_html__( 'Token activo hasta:', 'ltms' ) . ' <strong>' . esc_html( wp_date( 'd/m/Y H:i', $hub_token_expires ) ) . '</strong></p>';
                } elseif ( $hub_token_expires > 0 ) {
                    echo '<p style="color:#d63638;margin:0 0 8px;">⚠️ ' . esc_html__( 'Token vencido — se renovará automáticamente al usar la integración.', 'ltms' ) . '</p>';
                } else {
                    echo '<p style="color:#999;margin:0 0 8px;">⚪ ' . esc_html__( 'Sin token aún — se obtendrá al hacer la primera prueba o envío.', 'ltms' ) . '</p>';
                }
                ?>
                <button type="button" id="ltms-hub-test-btn" class="button button-secondary">
                    🔌 <?php esc_html_e( 'Probar conexión Ave-Hub', 'ltms' ); ?>
                </button>
                <span id="ltms-hub-test-result" style="margin-left:12px;font-style:italic;"></span>
            </td>
        </tr>
    </tbody></table>
    <script>
    (function($){
        $('#ltms-hub-test-btn').on('click', function(){
            var $btn = $(this);
            var $res = $('#ltms-hub-test-result');
            $btn.prop('disabled', true).text('<?php echo esc_js( __( 'Probando…', 'ltms' ) ); ?>');
            $res.css('color','').text('');
            $.post(ajaxurl, {
                action: 'ltms_aveonline_hub_test_connection',
                nonce:  '<?php echo esc_js( wp_create_nonce( 'ltms_admin_nonce' ) ); ?>'
            }, function(res){
                $btn.prop('disabled', false).text('🔌 <?php echo esc_js( __( 'Probar conexión Ave-Hub', 'ltms' ) ); ?>');
                if (res.success) {
                    $res.css('color','#0a7c00').text('✅ ' + res.data.message);
                } else {
                    $res.css('color','#d63638').text('❌ ' + (res.data && res.data.message ? res.data.message : '<?php echo esc_js( __( 'Error', 'ltms' ) ); ?>'));
                }
            }).fail(function(){
                $btn.prop('disabled', false).text('🔌 <?php echo esc_js( __( 'Probar conexión Ave-Hub', 'ltms' ) ); ?>');
                $res.css('color','#d63638').text('<?php echo esc_js( __( 'Error de conexión.', 'ltms' ) ); ?>');
            });
        });
    })(jQuery);
    </script>

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

    <h3 style="margin-top:24px;"><?php esc_html_e( 'Transportadoras disponibles en tu cuenta', 'ltms' ); ?></h3>
    <?php
    $carriers_count   = class_exists( 'LTMS_Business_Aveonline_Carriers' ) ? LTMS_Business_Aveonline_Carriers::count() : 0;
    $carriers_sync_at = class_exists( 'LTMS_Business_Aveonline_Carriers' ) ? LTMS_Business_Aveonline_Carriers::last_sync_at() : null;
    $all_carriers     = class_exists( 'LTMS_Business_Aveonline_Carriers' ) ? LTMS_Business_Aveonline_Carriers::all() : [];
    ?>
    <div style="margin-bottom:12px;display:flex;align-items:center;gap:16px;">
        <span>
            <?php
            printf(
                esc_html__( '%d transportadoras sincronizadas. Última sincronización: %s', 'ltms' ),
                $carriers_count,
                $carriers_sync_at
                    ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $carriers_sync_at ) )
                    : esc_html__( 'nunca', 'ltms' )
            );
            ?>
        </span>
        <button type="button" id="ltms-sync-carriers-btn" class="button button-secondary">
            <?php esc_html_e( 'Sincronizar transportadoras', 'ltms' ); ?>
        </button>
        <span id="ltms-sync-carriers-result" style="color:green;"></span>
    </div>
    <script>
    (function($){ 'use strict';
        $('#ltms-sync-carriers-btn').on('click', function(){
            var $btn = $(this);
            $btn.prop('disabled', true).text('<?php echo esc_js( __( 'Sincronizando...', 'ltms' ) ); ?>');
            $('#ltms-sync-carriers-result').text('');
            $.post(ajaxurl, {
                action  : 'ltms_sync_aveonline_carriers',
                _wpnonce: '<?php echo wp_create_nonce( 'ltms_sync_aveonline_carriers' ); ?>'
            }, function(res){
                $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Sincronizar transportadoras', 'ltms' ) ); ?>');
                if (res.success) {
                    $('#ltms-sync-carriers-result').text(res.data.message);
                    setTimeout(function(){ location.reload(); }, 1500);
                } else {
                    $('#ltms-sync-carriers-result').css('color','red').text(res.data || '<?php echo esc_js( __( 'Error', 'ltms' ) ); ?>');
                }
            });
        });
    })(jQuery);
    </script>

    <?php if ( ! empty( $all_carriers ) ) : ?>
    <table class="widefat striped" style="max-width:600px;">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Código', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Transportadora', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Soporte oficinas', 'ltms' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $all_carriers as $id => $carrier ) : ?>
            <tr>
                <td><code><?php echo esc_html( $id ); ?></code></td>
                <td><?php echo esc_html( $carrier['label'] ); ?></td>
                <td><?php echo isset( $carrier['slug'] ) ? '&#10003;' : '&mdash;'; ?></td>
            </tr>
            <?php endforeach; ?>
            <tr style="border-top:2px solid #ddd;">
                <td><em><?php esc_html_e( 'vacío', 'ltms' ); ?></em></td>
                <td colspan="2"><?php esc_html_e( 'Cotizar con todas las habilitadas', 'ltms' ); ?></td>
            </tr>
        </tbody>
    </table>
    <?php else : ?>
    <p class="description"><?php esc_html_e( 'Haz clic en "Sincronizar transportadoras" para cargar el catálogo desde Aveonline.', 'ltms' ); ?></p>
    <?php endif; ?>
</div>

<?php
// ── Relaciones de Envíos ──────────────────────────────────────────────────────
$relations_count = class_exists( 'LTMS_Business_Aveonline_ShipmentRelations' )
    ? LTMS_Business_Aveonline_ShipmentRelations::count()
    : 0;
$relations_local = class_exists( 'LTMS_Business_Aveonline_ShipmentRelations' )
    ? LTMS_Business_Aveonline_ShipmentRelations::get_local( 20 )
    : [];
$carriers_for_select = class_exists( 'LTMS_Business_Aveonline_Carriers' )
    ? LTMS_Business_Aveonline_Carriers::all()
    : [];
?>
<div class="ltms-settings-section" style="margin-top:32px;">
    <h3><?php esc_html_e( 'Relaciones de Envíos', 'ltms' ); ?></h3>
    <p class="description">
        <?php esc_html_e( 'Una Relación de Envíos agrupa varias guías en un manifiesto que la transportadora firma al recoger los paquetes. Cada relación se imprime y se entrega al mensajero.', 'ltms' ); ?>
    </p>

    <?php /* ── Formulario crear relación ── */ ?>
    <div id="ltms-relation-form" style="background:#f9f9f9;border:1px solid #ddd;padding:16px;border-radius:4px;max-width:600px;margin-bottom:20px;">
        <h4 style="margin-top:0;"><?php esc_html_e( 'Crear nueva relación', 'ltms' ); ?></h4>

        <table class="form-table" style="margin:0;">
            <tr>
                <th style="width:160px;padding:6px 0;"><?php esc_html_e( 'Transportadora', 'ltms' ); ?> <span style="color:red">*</span></th>
                <td style="padding:6px 0;">
                    <select id="ltms-rel-transportadora" style="width:100%;max-width:320px;">
                        <option value=""><?php esc_html_e( '— Seleccionar —', 'ltms' ); ?></option>
                        <?php foreach ( $carriers_for_select as $code => $carrier ) : ?>
                        <option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $carrier['label'] ); ?> (<?php echo esc_html( $code ); ?>)</option>
                        <?php endforeach; ?>
                        <?php if ( empty( $carriers_for_select ) ) : ?>
                        <option value="1016">INTERRAPIDISIMO (1016)</option>
                        <option value="33">SERVIENTREGA (33)</option>
                        <option value="1010">TCC SA (1010)</option>
                        <option value="1009">COORDINADORA MERCANTIL (1009)</option>
                        <option value="29">ENVIA (29)</option>
                        <?php endif; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th style="padding:6px 0;"><?php esc_html_e( 'Guías', 'ltms' ); ?> <span style="color:red">*</span></th>
                <td style="padding:6px 0;">
                    <textarea id="ltms-rel-guias" rows="4" style="width:100%;max-width:420px;font-family:monospace;font-size:12px;" placeholder="<?php esc_attr_e( 'Una guía por línea o separadas por coma', 'ltms' ); ?>"></textarea>
                    <p class="description" style="margin:4px 0 0;"><?php esc_html_e( 'Pega aquí los números de guía a agrupar en esta relación.', 'ltms' ); ?></p>
                </td>
            </tr>
        </table>

        <div style="margin-top:12px;">
            <button type="button" id="ltms-btn-create-relation" class="button button-primary">
                <?php esc_html_e( 'Crear relación', 'ltms' ); ?>
            </button>
            <span id="ltms-create-relation-result" style="margin-left:12px;font-style:italic;"></span>
        </div>

        <div id="ltms-relation-created" style="display:none;margin-top:14px;padding:12px;background:#e7f5e9;border:1px solid #4caf50;border-radius:4px;">
            <strong><?php esc_html_e( '✓ Relación creada:', 'ltms' ); ?></strong>
            <code id="ltms-rel-numero" style="font-size:14px;margin-left:6px;"></code><br>
            <span style="font-size:12px;color:#555;"><?php esc_html_e( 'Fecha:', 'ltms' ); ?> <span id="ltms-rel-fecha"></span></span><br>
            <a id="ltms-rel-print-link" href="#" target="_blank" class="button button-secondary" style="margin-top:8px;">
                🖨 <?php esc_html_e( 'Imprimir manifiesto', 'ltms' ); ?>
            </a>
        </div>
    </div>

    <?php /* ── Búsqueda / filtros ── */ ?>
    <div id="ltms-relation-search" style="background:#f9f9f9;border:1px solid #ddd;padding:16px;border-radius:4px;max-width:600px;margin-bottom:20px;">
        <h4 style="margin-top:0;"><?php esc_html_e( 'Buscar relaciones en Aveonline', 'ltms' ); ?></h4>
        <p class="description" style="margin-top:0;"><?php esc_html_e( 'Opcional — usa al menos un filtro para consultar Aveonline directamente.', 'ltms' ); ?></p>

        <div style="display:flex;flex-wrap:wrap;gap:10px;">
            <div>
                <label style="display:block;font-size:12px;margin-bottom:3px;"><?php esc_html_e( 'Nº Relación', 'ltms' ); ?></label>
                <input type="text" id="ltms-search-numero" placeholder="6077101620220418..." style="width:200px;" />
            </div>
            <div>
                <label style="display:block;font-size:12px;margin-bottom:3px;"><?php esc_html_e( 'Desde (AAAA/MM/DD)', 'ltms' ); ?></label>
                <input type="text" id="ltms-search-fecha-ini" placeholder="2024/01/01" style="width:130px;" />
            </div>
            <div>
                <label style="display:block;font-size:12px;margin-bottom:3px;"><?php esc_html_e( 'Hasta (AAAA/MM/DD)', 'ltms' ); ?></label>
                <input type="text" id="ltms-search-fecha-fin" placeholder="2024/12/31" style="width:130px;" />
            </div>
            <div>
                <label style="display:block;font-size:12px;margin-bottom:3px;"><?php esc_html_e( 'Nº Guía', 'ltms' ); ?></label>
                <input type="text" id="ltms-search-guia" placeholder="034..." style="width:140px;" />
            </div>
        </div>

        <div style="margin-top:12px;">
            <button type="button" id="ltms-btn-search-relations" class="button">
                <?php esc_html_e( 'Buscar en Aveonline', 'ltms' ); ?>
            </button>
            <button type="button" id="ltms-btn-load-local" class="button" style="margin-left:8px;">
                <?php printf( esc_html__( 'Ver relaciones locales (%d)', 'ltms' ), $relations_count ); ?>
            </button>
            <span id="ltms-search-relations-result" style="margin-left:12px;font-style:italic;"></span>
        </div>
    </div>

    <?php /* ── Tabla de resultados ── */ ?>
    <div id="ltms-relations-table-wrap" style="max-width:900px;display:none;">
        <table id="ltms-relations-table" class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Nº Relación', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Transportadora', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Guías', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Fecha', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Acciones', 'ltms' ); ?></th>
                </tr>
            </thead>
            <tbody id="ltms-relations-tbody">
                <tr><td colspan="5" style="text-align:center;color:#999;"><?php esc_html_e( 'Sin datos', 'ltms' ); ?></td></tr>
            </tbody>
        </table>
    </div>
</div>

<?php /* ── Modal confirmar eliminación ── */ ?>
<div id="ltms-rel-delete-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:6px;padding:28px;max-width:400px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,.2);">
        <h3 style="margin-top:0;"><?php esc_html_e( 'Eliminar relación', 'ltms' ); ?></h3>
        <p><?php esc_html_e( '¿Confirmas que deseas eliminar la relación', 'ltms' ); ?> <strong id="ltms-rel-delete-num"></strong>? <?php esc_html_e( 'Esta acción no se puede deshacer en Aveonline.', 'ltms' ); ?></p>
        <div style="display:flex;gap:10px;justify-content:flex-end;">
            <button type="button" id="ltms-rel-delete-cancel" class="button"><?php esc_html_e( 'Cancelar', 'ltms' ); ?></button>
            <button type="button" id="ltms-rel-delete-confirm" class="button button-primary" style="background:#d63638;border-color:#d63638;">
                <?php esc_html_e( 'Sí, eliminar', 'ltms' ); ?>
            </button>
        </div>
        <span id="ltms-rel-delete-result" style="display:block;margin-top:10px;font-style:italic;"></span>
    </div>
</div>

<script>
(function($){
    var nonce = '<?php echo esc_js( wp_create_nonce( 'ltms_aveonline_relations_nonce' ) ); ?>';
    var deleteTarget = '';

    // ── Crear relación ────────────────────────────────────────────────
    $('#ltms-btn-create-relation').on('click', function(){
        var $btn  = $(this);
        var trans = $('#ltms-rel-transportadora').val();
        var guias = $.trim($('#ltms-rel-guias').val());

        if (!trans || !guias) {
            $('#ltms-create-relation-result').css('color','red').text('<?php echo esc_js( __( 'Completa transportadora y guías.', 'ltms' ) ); ?>');
            return;
        }

        $btn.prop('disabled', true).text('<?php echo esc_js( __( 'Creando…', 'ltms' ) ); ?>');
        $('#ltms-create-relation-result').css('color','').text('');
        $('#ltms-relation-created').hide();

        $.post(ajaxurl, {
            action: 'ltms_aveonline_create_relation',
            nonce: nonce,
            transportadora: trans,
            guias: guias
        }, function(res){
            $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Crear relación', 'ltms' ) ); ?>');
            if (res.success) {
                $('#ltms-rel-numero').text(res.data.relacionenvio);
                $('#ltms-rel-fecha').text(res.data.fecha);
                $('#ltms-rel-print-link').attr('href', res.data.rutaimpresion || '#');
                if (!res.data.rutaimpresion) $('#ltms-rel-print-link').hide();
                $('#ltms-relation-created').show();
                $('#ltms-create-relation-result').css('color','green').text(res.data.message);
                // limpiar guías
                $('#ltms-rel-guias').val('');
            } else {
                $('#ltms-create-relation-result').css('color','red').text(res.data.message || '<?php echo esc_js( __( 'Error', 'ltms' ) ); ?>');
            }
        }).fail(function(){
            $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Crear relación', 'ltms' ) ); ?>');
            $('#ltms-create-relation-result').css('color','red').text('<?php echo esc_js( __( 'Error de conexión.', 'ltms' ) ); ?>');
        });
    });

    // ── Buscar en Aveonline ───────────────────────────────────────────
    $('#ltms-btn-search-relations').on('click', function(){
        var $btn = $(this);
        $btn.prop('disabled', true).text('<?php echo esc_js( __( 'Buscando…', 'ltms' ) ); ?>');
        $('#ltms-search-relations-result').css('color','').text('');

        $.post(ajaxurl, {
            action: 'ltms_aveonline_list_relations',
            nonce: nonce,
            numero_relacion: $('#ltms-search-numero').val(),
            fecha_inicial:   $('#ltms-search-fecha-ini').val(),
            fecha_final:     $('#ltms-search-fecha-fin').val(),
            numero_guia:     $('#ltms-search-guia').val()
        }, function(res){
            $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Buscar en Aveonline', 'ltms' ) ); ?>');
            if (res.success) {
                renderRelations(res.data.registros, res.data.source);
                $('#ltms-search-relations-result').css('color','green').text(res.data.total + ' <?php echo esc_js( __( 'resultado(s)', 'ltms' ) ); ?>');
            } else {
                $('#ltms-search-relations-result').css('color','red').text(res.data.message);
                $('#ltms-relations-table-wrap').hide();
            }
        }).fail(function(){
            $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Buscar en Aveonline', 'ltms' ) ); ?>');
            $('#ltms-search-relations-result').css('color','red').text('<?php echo esc_js( __( 'Error de conexión.', 'ltms' ) ); ?>');
        });
    });

    // ── Ver locales ───────────────────────────────────────────────────
    $('#ltms-btn-load-local').on('click', function(){
        var $btn = $(this);
        $btn.prop('disabled', true);
        $('#ltms-search-relations-result').css('color','').text('');

        $.post(ajaxurl, {
            action: 'ltms_aveonline_list_relations',
            nonce: nonce
        }, function(res){
            $btn.prop('disabled', false);
            if (res.success) {
                renderRelations(res.data.registros, 'local');
                $('#ltms-search-relations-result').css('color','').text(res.data.total + ' <?php echo esc_js( __( 'relación(es) local(es)', 'ltms' ) ); ?>');
            } else {
                $('#ltms-search-relations-result').css('color','red').text(res.data.message);
            }
        });
    });

    // ── Render tabla ──────────────────────────────────────────────────
    function renderRelations(rows, source) {
        var $tbody = $('#ltms-relations-tbody').empty();
        if (!rows || !rows.length) {
            $tbody.append('<tr><td colspan="5" style="text-align:center;color:#999;"><?php echo esc_js( __( 'Sin resultados', 'ltms' ) ); ?></td></tr>');
            $('#ltms-relations-table-wrap').show();
            return;
        }

        rows.forEach(function(r){
            var num   = r.relacionenvio || r.id || '';
            var trans = r.transportadora || r.transportadora || '';
            var guias = '';
            if (r.guias && Array.isArray(r.guias)) {
                guias = r.guias.map(function(g){ return g.numero || g; }).join(', ');
            } else if (typeof r.guias === 'string') {
                guias = r.guias;
            }
            var fecha = r.fecha || r.fecha_aveonline || r.created_at || '';
            var numGuias = r.numeroguias || (r.guias ? r.guias.length : '—');

            var actions = '<a href="#" class="ltms-rel-delete button button-small" style="color:#d63638;border-color:#d63638;" data-rel="' + escHtml(num) + '"><?php echo esc_js( __( 'Eliminar', 'ltms' ) ); ?></a>';

            $tbody.append(
                '<tr>' +
                '<td><code>' + escHtml(num) + '</code></td>' +
                '<td>' + escHtml(trans) + '</td>' +
                '<td style="font-size:11px;max-width:260px;word-break:break-all;">' + escHtml(guias) + ' <em style="color:#999;">(' + numGuias + ')</em></td>' +
                '<td style="white-space:nowrap;font-size:12px;">' + escHtml(fecha) + '</td>' +
                '<td>' + actions + '</td>' +
                '</tr>'
            );
        });

        $('#ltms-relations-table-wrap').show();
    }

    // ── Eliminar (modal) ──────────────────────────────────────────────
    $(document).on('click', '.ltms-rel-delete', function(e){
        e.preventDefault();
        deleteTarget = $(this).data('rel');
        $('#ltms-rel-delete-num').text(deleteTarget);
        $('#ltms-rel-delete-result').text('');
        $('#ltms-rel-delete-modal').css('display','flex');
    });

    $('#ltms-rel-delete-cancel').on('click', function(){
        $('#ltms-rel-delete-modal').hide();
        deleteTarget = '';
    });

    $('#ltms-rel-delete-confirm').on('click', function(){
        var $btn = $(this);
        $btn.prop('disabled', true).text('<?php echo esc_js( __( 'Eliminando…', 'ltms' ) ); ?>');
        $('#ltms-rel-delete-result').text('');

        $.post(ajaxurl, {
            action: 'ltms_aveonline_delete_relation',
            nonce: nonce,
            relacionenvio: deleteTarget
        }, function(res){
            $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Sí, eliminar', 'ltms' ) ); ?>');
            if (res.success) {
                $('#ltms-rel-delete-result').css('color','green').text(res.data.message);
                // Remover fila de la tabla
                $('code:contains("' + deleteTarget + '")').closest('tr').fadeOut(400, function(){ $(this).remove(); });
                setTimeout(function(){ $('#ltms-rel-delete-modal').hide(); deleteTarget = ''; }, 1500);
            } else {
                $('#ltms-rel-delete-result').css('color','red').text(res.data.message);
            }
        }).fail(function(){
            $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Sí, eliminar', 'ltms' ) ); ?>');
            $('#ltms-rel-delete-result').css('color','red').text('<?php echo esc_js( __( 'Error de conexión.', 'ltms' ) ); ?>');
        });
    });

    // ── Escape HTML helper ────────────────────────────────────────────
    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

})(jQuery);
</script>

<?php /* ══════════════════════════════════════════════════════════════════════
   PANEL DE QA — SANDBOX AVEONLINE
   Permite probar obtenerEstadoAuth y avanzarEstado sin mensajero real.
   Solo funciona con empresa IDs 6077 o 25505.
══════════════════════════════════════════════════════════════════════ */ ?>
<hr style="margin:40px 0 24px;">

<h2 style="display:flex;align-items:center;gap:8px;font-size:1.25rem;">
    🧪 <?php esc_html_e( 'QA Sandbox — Aveonline', 'ltms' ); ?>
    <span style="font-size:.75rem;font-weight:400;background:#f0f0f0;border:1px solid #ccc;
        border-radius:4px;padding:2px 8px;color:#555;">
        <?php esc_html_e( 'Solo empresas 6077 / 25505', 'ltms' ); ?>
    </span>
</h2>
<p class="description" style="margin-bottom:20px;">
    <?php esc_html_e( 'Herramienta de prueba del ciclo completo Aveonline. Obtén un token con las credenciales sandbox, consulta los estados de ejemplo y avanza el estado de una guía real de prueba.', 'ltms' ); ?>
</p>

<div id="ltms-sandbox-panel" style="max-width:820px;">

    <?php /* ── Credenciales sandbox ── */ ?>
    <table class="form-table" role="presentation" style="margin-bottom:0;">
        <tr>
            <th style="width:200px;"><?php esc_html_e( 'Token Aveonline', 'ltms' ); ?></th>
            <td>
                <input type="text" id="ltms-sb-token" class="regular-text"
                    placeholder="<?php esc_attr_e( 'Pega aquí el token obtenido en la autenticación', 'ltms' ); ?>">
                <p class="description">
                    <?php esc_html_e( 'El token lo obtienes llamando al endpoint de autenticación de Aveonline (vigencia 1 hora).', 'ltms' ); ?>
                </p>
            </td>
        </tr>
        <tr>
            <th><?php esc_html_e( 'ID Empresa Sandbox', 'ltms' ); ?></th>
            <td>
                <select id="ltms-sb-id">
                    <option value="25505">25505 — Sandbox #2 (recomendada)</option>
                    <option value="6077">6077 — Sandbox #1</option>
                </select>
            </td>
        </tr>
        <tr>
            <th><?php esc_html_e( 'Número de guía', 'ltms' ); ?></th>
            <td>
                <input type="text" id="ltms-sb-guia" class="regular-text"
                    placeholder="<?php esc_attr_e( 'Ej: 212342474354', 'ltms' ); ?>">
                <p class="description">
                    <?php esc_html_e( 'Para avanzarEstado: guía real de la empresa sandbox. Para obtenerEstadoAuth: se usa como referencia de contexto (no filtra).', 'ltms' ); ?>
                </p>
            </td>
        </tr>
    </table>

    <?php /* ── Acciones ── */ ?>
    <div style="display:flex;flex-wrap:wrap;gap:12px;margin:20px 0;">
        <button type="button" id="ltms-sb-btn-estados" class="button button-primary">
            📋 <?php esc_html_e( 'Consultar todos los estados (obtenerEstadoAuth)', 'ltms' ); ?>
        </button>
        <button type="button" id="ltms-sb-btn-avanzar" class="button button-secondary">
            ▶ <?php esc_html_e( 'Avanzar estado (siguiente automático)', 'ltms' ); ?>
        </button>
    </div>

    <?php /* ── Forzar estado específico ── */ ?>
    <details style="margin-bottom:20px;">
        <summary style="cursor:pointer;font-weight:600;padding:8px 0;">
            ⚡ <?php esc_html_e( 'Forzar estado específico (avanzarEstado con parámetro)', 'ltms' ); ?>
        </summary>
        <div style="padding:16px;background:#f9f9f9;border:1px solid #e2e2e2;border-radius:4px;margin-top:8px;">
            <table class="form-table" role="presentation">
                <tr>
                    <th style="width:200px;"><?php esc_html_e( 'Estado a forzar', 'ltms' ); ?></th>
                    <td>
                        <select id="ltms-sb-estado-forzado">
                            <option value=""><?php esc_html_e( '— (avance automático, sin forzar) —', 'ltms' ); ?></option>
                            <option value="GENERADA">GENERADA</option>
                            <option value="PRODUCIDA">PRODUCIDA</option>
                            <option value="EN DESPACHO">EN DESPACHO</option>
                            <option value="EN REPARTO">EN REPARTO</option>
                            <option value="EN NOVEDAD">EN NOVEDAD</option>
                            <option value="ENTREGADA">ENTREGADA</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Descripción', 'ltms' ); ?></th>
                    <td>
                        <input type="text" id="ltms-sb-descripcion" class="regular-text"
                            placeholder="<?php esc_attr_e( 'Ej: Entregado a: JUAN PEREZ – C.C 12345678', 'ltms' ); ?>">
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Aclaración', 'ltms' ); ?></th>
                    <td>
                        <input type="text" id="ltms-sb-aclaracion" class="regular-text"
                            placeholder="<?php esc_attr_e( 'Ej: Recibido conforme', 'ltms' ); ?>">
                    </td>
                </tr>
            </table>
            <button type="button" id="ltms-sb-btn-forzar" class="button button-secondary" style="margin-top:8px;">
                ⚡ <?php esc_html_e( 'Forzar estado seleccionado', 'ltms' ); ?>
            </button>
        </div>
    </details>

    <?php /* ── Área de resultados ── */ ?>
    <div id="ltms-sb-spinner" style="display:none;margin-bottom:12px;">
        <span class="spinner is-active" style="float:none;vertical-align:middle;"></span>
        <em style="margin-left:8px;"><?php esc_html_e( 'Consultando Aveonline Sandbox…', 'ltms' ); ?></em>
    </div>

    <div id="ltms-sb-result-wrap" style="display:none;">
        <div id="ltms-sb-result-badge" style="padding:10px 14px;border-radius:4px;margin-bottom:12px;font-weight:600;"></div>
        <div id="ltms-sb-result-body" style="background:#1e1e1e;color:#d4d4d4;font-family:monospace;font-size:.82rem;
            padding:16px;border-radius:4px;max-height:500px;overflow:auto;white-space:pre-wrap;word-break:break-all;">
        </div>
    </div>

    <?php /* ── Tabla de estados del flujo (referencia) ── */ ?>
    <details style="margin-top:24px;">
        <summary style="cursor:pointer;font-weight:600;padding:8px 0;">
            📋 <?php esc_html_e( 'Referencia: flujo de estados Aveonline', 'ltms' ); ?>
        </summary>
        <table class="widefat" style="margin-top:10px;max-width:600px;">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Llamada', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Estado anterior', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Estado nuevo', 'ltms' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr><td>1ª</td><td><?php esc_html_e( '(sin estado)', 'ltms' ); ?></td><td><code>GENERADA</code></td></tr>
                <tr><td>2ª</td><td><code>GENERADA</code></td><td><code>PRODUCIDA</code></td></tr>
                <tr><td>3ª</td><td><code>PRODUCIDA</code></td><td><code>EN DESPACHO</code></td></tr>
                <tr><td>4ª</td><td><code>EN DESPACHO</code></td><td><code>EN REPARTO</code></td></tr>
                <tr><td>5ª</td><td><code>EN REPARTO</code></td><td><code>ENTREGADA</code> ✅</td></tr>
                <tr><td><?php esc_html_e( 'forzado', 'ltms' ); ?></td><td><?php esc_html_e( 'cualquiera', 'ltms' ); ?></td><td><code>EN NOVEDAD</code> / <code>ENTREGADA</code></td></tr>
            </tbody>
        </table>
    </details>

</div><!-- #ltms-sandbox-panel -->

<script>
(function($){
    'use strict';

    var nonce  = '<?php echo esc_js( wp_create_nonce( 'ltms_admin_nonce' ) ); ?>';

    function sbToken()  { return $('#ltms-sb-token').val().trim();  }
    function sbId()     { return parseInt( $('#ltms-sb-id').val(), 10 ); }
    function sbGuia()   { return $('#ltms-sb-guia').val().trim();   }

    function sbShowResult( ok, data ) {
        var $badge = $('#ltms-sb-result-badge');
        var $body  = $('#ltms-sb-result-body');
        $('#ltms-sb-result-wrap').show();

        if ( ok ) {
            $badge.css({ background: '#d4edda', color: '#155724', border: '1px solid #c3e6cb' })
                  .text( '✅ ' + ( data.message || '<?php echo esc_js( __( 'OK', 'ltms' ) ); ?>' ) );
        } else {
            $badge.css({ background: '#f8d7da', color: '#721c24', border: '1px solid #f5c6cb' })
                  .text( '❌ ' + ( data.message || '<?php echo esc_js( __( 'Error', 'ltms' ) ); ?>' ) );
        }

        $body.text( JSON.stringify( data, null, 2 ) );
    }

    function sbSetLoading( on ) {
        $('#ltms-sb-spinner').toggle( on );
        $('#ltms-sb-btn-estados, #ltms-sb-btn-avanzar, #ltms-sb-btn-forzar').prop( 'disabled', on );
    }

    // ── Consultar estados de ejemplo ─────────────────────────────────────────
    $('#ltms-sb-btn-estados').on('click', function(){
        if ( ! sbToken() ) {
            console.warn('<?php echo esc_js( __( 'Ingresa el token de Aveonline primero.', 'ltms' ) ); ?>');
            return;
        }
        sbSetLoading( true );
        $('#ltms-sb-result-wrap').hide();

        $.post( ajaxurl, {
            action : 'ltms_aveonline_sandbox_estados',
            nonce  : nonce,
            token  : sbToken(),
            id     : sbId(),
            guia   : sbGuia()
        }, function( res ) {
            sbSetLoading( false );
            if ( res.success ) {
                sbShowResult( true, res.data );
            } else {
                sbShowResult( false, res.data );
            }
        }).fail(function(){
            sbSetLoading( false );
            sbShowResult( false, { message: '<?php echo esc_js( __( 'Error de conexión con el servidor.', 'ltms' ) ); ?>' } );
        });
    });

    // ── Avanzar estado (automático) ──────────────────────────────────────────
    $('#ltms-sb-btn-avanzar').on('click', function(){
        if ( ! sbToken() || ! sbGuia() ) {
            console.warn('<?php echo esc_js( __( 'Ingresa el token y el número de guía.', 'ltms' ) ); ?>');
            return;
        }
        sbSetLoading( true );
        $('#ltms-sb-result-wrap').hide();

        $.post( ajaxurl, {
            action : 'ltms_aveonline_sandbox_avanzar',
            nonce  : nonce,
            token  : sbToken(),
            id     : sbId(),
            guia   : sbGuia()
        }, function( res ) {
            sbSetLoading( false );
            if ( res.success ) {
                sbShowResult( true, res.data );
            } else {
                sbShowResult( false, res.data );
            }
        }).fail(function(){
            sbSetLoading( false );
            sbShowResult( false, { message: '<?php echo esc_js( __( 'Error de conexión con el servidor.', 'ltms' ) ); ?>' } );
        });
    });

    // ── Forzar estado específico ─────────────────────────────────────────────
    $('#ltms-sb-btn-forzar').on('click', function(){
        if ( ! sbToken() || ! sbGuia() ) {
            console.warn('<?php echo esc_js( __( 'Ingresa el token y el número de guía.', 'ltms' ) ); ?>');
            return;
        }
        sbSetLoading( true );
        $('#ltms-sb-result-wrap').hide();

        $.post( ajaxurl, {
            action      : 'ltms_aveonline_sandbox_avanzar',
            nonce       : nonce,
            token       : sbToken(),
            id          : sbId(),
            guia        : sbGuia(),
            estado      : $('#ltms-sb-estado-forzado').val(),
            descripcion : $('#ltms-sb-descripcion').val().trim(),
            aclaracion  : $('#ltms-sb-aclaracion').val().trim()
        }, function( res ) {
            sbSetLoading( false );
            if ( res.success ) {
                sbShowResult( true, res.data );
            } else {
                sbShowResult( false, res.data );
            }
        }).fail(function(){
            sbSetLoading( false );
            sbShowResult( false, { message: '<?php echo esc_js( __( 'Error de conexión con el servidor.', 'ltms' ) ); ?>' } );
        });
    });

})(jQuery);
</script>



