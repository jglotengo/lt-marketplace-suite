<?php
/**
 * Vista SPA: PosGold — Sincronización de catálogo.
 *
 * Permite a los vendedores:
 * - Configurar sus credenciales de PosGold (subdomain, token, etc.)
 * - Probar la conexión
 * - Sincronizar productos manualmente
 * - Ver historial de sincronizaciones
 *
 * @package LTMS
 * @version 2.9.31
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$user_id = get_current_user_id();
$creds   = LTMS_PosGold_Sync::get_vendor_credentials( $user_id );
$last_sync      = (int) get_user_meta( $user_id, 'ltms_posgold_last_sync', true );
$last_sync_count = (int) get_user_meta( $user_id, 'ltms_posgold_last_sync_count', true );
$can_sync = ( time() - $last_sync ) >= ( 2 * MINUTE_IN_SECONDS );
?>
<div style="padding:24px;" id="ltms-posgold-view">

    <div class="ltms-view-header" style="margin-bottom:24px;">
        <h2 style="margin:0;">🔗 PosGold</h2>
        <p style="color:#6b7280;margin:8px 0 0;font-size:0.875rem;">
            <?php esc_html_e( 'Sincroniza tu catálogo de PosGold hacia el marketplace. Tus productos se crearán o actualizarán automáticamente.', 'ltms' ); ?>
        </p>
    </div>

    <!-- Estado de conexión -->
    <div class="ltms-card" style="margin-bottom:20px;">
        <div class="ltms-card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <span><?php esc_html_e( 'Estado de la conexión', 'ltms' ); ?></span>
            <?php if ( $creds['configured'] ) : ?>
                <span class="ltms-badge ltms-badge-success">✓ <?php esc_html_e( 'CONFIGURADO', 'ltms' ); ?></span>
            <?php else : ?>
                <span class="ltms-badge ltms-badge-pending">⚠ <?php esc_html_e( 'NO CONFIGURADO', 'ltms' ); ?></span>
            <?php endif; ?>
        </div>
        <div class="ltms-card-body">
            <?php if ( $creds['configured'] ) : ?>
                <p style="margin:0;color:#16a34a;">
                    <?php esc_html_e( 'Tu cuenta de PosGold está configurada.', 'ltms' ); ?>
                </p>
                <p style="margin:8px 0 0;font-size:0.85rem;color:#6b7280;">
                    <strong><?php esc_html_e( 'Instancia:', 'ltms' ); ?></strong>
                    <code><?php echo esc_html( $creds['subdomain'] ); ?>.goldpos.com.co</code>
                    &nbsp;|&nbsp;
                    <strong><?php esc_html_e( 'Empresa ID:', 'ltms' ); ?></strong> <?php echo esc_html( $creds['empresaid'] ); ?>
                    &nbsp;|&nbsp;
                    <strong><?php esc_html_e( 'Bodega ID:', 'ltms' ); ?></strong> <?php echo esc_html( $creds['bodegaid'] ); ?>
                </p>
            <?php else : ?>
                <p style="margin:0;color:#dc2626;">
                    <?php esc_html_e( 'Aún no has configurado tus credenciales de PosGold. Completa el formulario abajo.', 'ltms' ); ?>
                </p>
            <?php endif; ?>

            <?php if ( $last_sync ) : ?>
            <div style="margin-top:16px;padding:12px 16px;background:#f0f9ff;border-radius:8px;border-left:3px solid #3b82f6;">
                <div style="font-size:0.8rem;color:#6b7280;margin-bottom:4px;">
                    <?php esc_html_e( 'Última sincronización', 'ltms' ); ?>
                </div>
                <div style="font-weight:600;">
                    <?php echo esc_html( date_i18n( 'd M Y H:i', $last_sync ) ); ?>
                    <span style="font-weight:400;color:#6b7280;margin-left:8px;">
                        (<?php echo esc_html( sprintf(
                            /* translators: %d: productos sincronizados */
                            _n( '%d producto procesado', '%d productos procesados', $last_sync_count, 'ltms' ),
                            $last_sync_count
                        ) ); ?>)
                    </span>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Botón de sincronización -->
    <?php if ( $creds['configured'] ) : ?>
    <div class="ltms-card" style="margin-bottom:20px;">
        <div class="ltms-card-header">
            <?php esc_html_e( 'Sincronizar productos', 'ltms' ); ?>
        </div>
        <div class="ltms-card-body">
            <p style="margin:0 0 16px;color:#374151;">
                <?php esc_html_e( 'Al hacer click en "Sincronizar ahora", se descargará tu catálogo completo de PosGold y se crearán o actualizarán los productos en el marketplace. Esto puede tardar varios minutos.', 'ltms' ); ?>
            </p>
            <?php if ( ! $can_sync ) : ?>
                <?php $remaining = ( 2 * MINUTE_IN_SECONDS ) - ( time() - $last_sync ); ?>
                <div style="padding:12px 16px;background:#fef3c7;border-radius:8px;color:#92400e;margin-bottom:16px;">
                    ⏳ <?php
                    printf(
                        /* translators: %d: segundos */
                        esc_html__( 'Debes esperar %d segundos antes de sincronizar nuevamente.', 'ltms' ),
                        $remaining
                    );
                    ?>
                </div>
            <?php endif; ?>
            <button type="button"
                    id="ltms-posgold-sync-btn"
                    class="ltms-btn ltms-btn-primary"
                    <?php echo $can_sync ? '' : 'disabled'; ?>>
                🔄 <?php esc_html_e( 'Sincronizar ahora', 'ltms' ); ?>
            </button>
            <div id="ltms-posgold-sync-result" style="margin-top:16px;display:none;"></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Configuración de credenciales -->
    <div class="ltms-card">
        <div class="ltms-card-header">
            <?php esc_html_e( 'Configuración de credenciales', 'ltms' ); ?>
        </div>
        <div class="ltms-card-body">
            <form id="ltms-posgold-config-form" method="post">
                <?php wp_nonce_field( 'ltms_posgold_save', 'ltms_posgold_nonce' ); ?>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
                    <div>
                        <label for="ltms-posgold-subdomain" style="display:block;font-weight:600;margin-bottom:4px;">
                            <?php esc_html_e( 'Subdominio PosGold *', 'ltms' ); ?>
                        </label>
                        <div style="display:flex;align-items:center;">
                            <input type="text"
                                   id="ltms-posgold-subdomain"
                                   name="ltms_posgold_subdomain"
                                   value="<?php echo esc_attr( $creds['subdomain'] ); ?>"
                                   placeholder="jugueteriataiwan"
                                   required
                                   style="flex:1;padding:8px 12px;border:1px solid #d1d5db;border-radius:4px 0 0 4px;">
                            <span style="padding:8px 12px;background:#f3f4f6;border:1px solid #d1d5db;border-left:none;border-radius:0 4px 4px 0;font-size:0.85rem;color:#6b7280;">
                                .goldpos.com.co
                            </span>
                        </div>
                        <p style="margin:4px 0 0;font-size:0.75rem;color:#9ca3af;">
                            <?php esc_html_e( 'Tu subdominio de PosGold (ej: jugueteriataiwan para jugueteriataiwan.goldpos.com.co)', 'ltms' ); ?>
                        </p>
                    </div>
                    <div>
                        <label for="ltms-posgold-bodegaid" style="display:block;font-weight:600;margin-bottom:4px;">
                            <?php esc_html_e( 'Bodega ID', 'ltms' ); ?>
                        </label>
                        <input type="number"
                               id="ltms-posgold-bodegaid"
                               name="ltms_posgold_bodegaid"
                               value="<?php echo esc_attr( $creds['bodegaid'] ); ?>"
                               min="1"
                               style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:4px;">
                        <p style="margin:4px 0 0;font-size:0.75rem;color:#9ca3af;">
                            <?php esc_html_e( 'ID de la bodega desde donde consultar stock (default: 1)', 'ltms' ); ?>
                        </p>
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
                    <div>
                        <label for="ltms-posgold-empresaid" style="display:block;font-weight:600;margin-bottom:4px;">
                            <?php esc_html_e( 'Empresa ID', 'ltms' ); ?>
                        </label>
                        <input type="number"
                               id="ltms-posgold-empresaid"
                               name="ltms_posgold_empresaid"
                               value="<?php echo esc_attr( $creds['empresaid'] ); ?>"
                               min="1"
                               style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:4px;">
                        <p style="margin:4px 0 0;font-size:0.75rem;color:#9ca3af;">
                            <?php esc_html_e( 'ID de tu empresa en PosGold (default: 1)', 'ltms' ); ?>
                        </p>
                    </div>
                    <div>
                        <label for="ltms-posgold-usuarioid" style="display:block;font-weight:600;margin-bottom:4px;">
                            <?php esc_html_e( 'Usuario ID', 'ltms' ); ?>
                        </label>
                        <input type="number"
                               id="ltms-posgold-usuarioid"
                               name="ltms_posgold_usuarioid"
                               value="<?php echo esc_attr( $creds['usuarioid'] ); ?>"
                               min="1"
                               style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:4px;">
                        <p style="margin:4px 0 0;font-size:0.75rem;color:#9ca3af;">
                            <?php esc_html_e( 'ID de tu usuario en PosGold (default: 1)', 'ltms' ); ?>
                        </p>
                    </div>
                </div>

                <div style="margin-bottom:16px;">
                    <label for="ltms-posgold-token" style="display:block;font-weight:600;margin-bottom:4px;">
                        <?php esc_html_e( 'Bearer Token (JWT) *', 'ltms' ); ?>
                    </label>
                    <textarea id="ltms-posgold-token"
                              name="ltms_posgold_token"
                              rows="3"
                              placeholder="eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..."
                              style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:4px;font-family:monospace;font-size:0.85rem;"><?php echo esc_textarea( $creds['token'] ); ?></textarea>
                    <p style="margin:4px 0 0;font-size:0.75rem;color:#9ca3af;">
                        <?php esc_html_e( 'Token JWT de autenticación. Lo obtienes desde Postman o tu panel PosGold.', 'ltms' ); ?>
                    </p>
                </div>

                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <button type="submit" name="ltms_posgold_action" value="save" class="ltms-btn ltms-btn-primary">
                        💾 <?php esc_html_e( 'Guardar credenciales', 'ltms' ); ?>
                    </button>
                    <button type="button" id="ltms-posgold-test-btn" class="ltms-btn ltms-btn-outline">
                        🔍 <?php esc_html_e( 'Probar conexión', 'ltms' ); ?>
                    </button>
                </div>

                <div id="ltms-posgold-test-result" style="margin-top:16px;display:none;"></div>
            </form>
        </div>
    </div>

    <!-- Información de ayuda -->
    <div style="margin-top:24px;padding:16px 20px;background:#f0f4ff;border-radius:8px;border-left:3px solid #3b82f6;">
        <h4 style="margin:0 0 8px;color:#1e40af;">ℹ️ <?php esc_html_e( '¿Cómo obtener tus credenciales?', 'ltms' ); ?></h4>
        <ol style="margin:0;padding-left:20px;color:#374151;font-size:0.875rem;line-height:1.6;">
            <li><?php esc_html_e( 'Inicia sesión en tu panel de PosGold (tusubdominio.goldpos.com.co)', 'ltms' ); ?></li>
            <li><?php esc_html_e( 'Ve a la sección de API o Integraciones', 'ltms' ); ?></li>
            <li><?php esc_html_e( 'Genera un Bearer Token (JWT) — suele ser de larga duración', 'ltms' ); ?></li>
            <li><?php esc_html_e( 'Copia el token y pégalo en el campo de arriba', 'ltms' ); ?></li>
            <li><?php esc_html_e( 'El Subdominio es la primera parte de tu URL (ej: jugueteriataiwan)', 'ltms' ); ?></li>
            <li><?php esc_html_e( 'Empresa ID y Usuario ID normalmente son 1 (consúltalo con tu admin PosGold si no es así)', 'ltms' ); ?></li>
        </ol>
    </div>

</div>

<script>
(function($){
    'use strict';

    var nonce = (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.nonce) || '';
    var ajaxUrl = (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.ajax_url) || ajaxurl;

    // Guardar credenciales vía AJAX
    $('#ltms-posgold-config-form').on('submit', function(e){
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find('button[type="submit"]');
        $btn.prop('disabled', true).text('Guardando...');

        $.post(ajaxUrl, {
            action: 'ltms_save_posgold_credentials',
            nonce: nonce,
            subdomain: $('#ltms-posgold-subdomain').val(),
            token: $('#ltms-posgold-token').val(),
            empresaid: $('#ltms-posgold-empresaid').val(),
            usuarioid: $('#ltms-posgold-usuarioid').val(),
            bodegaid: $('#ltms-posgold-bodegaid').val()
        }).done(function(resp){
            $btn.prop('disabled', false).html('💾 Guardar credenciales');
            if (resp.success) {
                alert('✓ ' + resp.data.message);
                window.location.reload();
            } else {
                alert('Error: ' + (resp.data.message || resp.data));
            }
        }).fail(function(){
            $btn.prop('disabled', false).html('💾 Guardar credenciales');
            alert('Error de red. Intenta de nuevo.');
        });
    });

    // Probar conexión
    $('#ltms-posgold-test-btn').on('click', function(){
        var $btn = $(this);
        $btn.prop('disabled', true).text('Probando...');

        $.post(ajaxUrl, {
            action: 'ltms_test_posgold_connection',
            nonce: nonce
        }).done(function(resp){
            $btn.prop('disabled', false).html('🔍 Probar conexión');
            var $result = $('#ltms-posgold-test-result');
            if (resp.success) {
                $result.html('<div style="padding:12px 16px;background:#dcfce7;border-radius:8px;color:#166534;">✓ ' + resp.data.message + '</div>').show();
            } else {
                $result.html('<div style="padding:12px 16px;background:#fee2e2;border-radius:8px;color:#991b1b;">✗ ' + (resp.data.message || resp.data) + '</div>').show();
            }
        }).fail(function(){
            $btn.prop('disabled', false).html('🔍 Probar conexión');
            alert('Error de red. Intenta de nuevo.');
        });
    });

    // Sincronizar productos
    $('#ltms-posgold-sync-btn').on('click', function(){
        var $btn = $(this);
        var $result = $('#ltms-posgold-sync-result');

        if (!confirm('¿Sincronizar tu catálogo de PosGold ahora? Esto puede tardar varios minutos.')) {
            return;
        }

        $btn.prop('disabled', true).text('Sincronizando...');
        $result.html('<div style="padding:16px;background:#f0f9ff;border-radius:8px;color:#1e40af;">⏳ Sincronizando productos... No cierres esta página.</div>').show();

        $.post(ajaxUrl, {
            action: 'ltms_sync_posgold_products',
            nonce: nonce
        }).done(function(resp){
            $btn.prop('disabled', false).html('🔄 Sincronizar ahora');
            if (resp.success) {
                var d = resp.data;
                var html = '<div style="padding:16px;background:#dcfce7;border-radius:8px;color:#166534;">';
                html += '<div style="font-weight:600;margin-bottom:8px;">✓ ' + d.message + '</div>';
                if (d.errors && d.errors.length > 0) {
                    html += '<div style="margin-top:8px;font-size:0.85rem;color:#7f1d1d;">';
                    html += '<strong>Errores (' + d.errors.length + '):</strong><ul style="margin:4px 0;padding-left:20px;">';
                    d.errors.slice(0, 10).forEach(function(e){ html += '<li>' + e + '</li>'; });
                    if (d.errors.length > 10) { html += '<li>... y ' + (d.errors.length - 10) + ' más</li>'; }
                    html += '</ul></div>';
                }
                html += '</div>';
                $result.html(html).show();
                setTimeout(function(){ window.location.reload(); }, 5000);
            } else {
                $result.html('<div style="padding:16px;background:#fee2e2;border-radius:8px;color:#991b1b;">✗ ' + (resp.data.message || resp.data) + '</div>').show();
            }
        }).fail(function(){
            $btn.prop('disabled', false).html('🔄 Sincronizar ahora');
            $result.html('<div style="padding:16px;background:#fee2e2;border-radius:8px;color:#991b1b;">✗ Error de red. Intenta de nuevo.</div>').show();
        });
    });

})(jQuery);
</script>
