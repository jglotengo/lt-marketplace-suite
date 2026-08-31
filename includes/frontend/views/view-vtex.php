<?php
/**
 * Vista SPA: VTEX — Sincronización de catálogo con reglas de negocio.
 *
 * Permite a los vendedores:
 * - Configurar credenciales de VTEX (accountName, appKey, appToken, environment)
 * - Filtrar qué categorías sincronizar (árbol de categorías VTEX)
 * - Configurar reglas de cálculo de precio (mismas que PosGold)
 * - Configurar plantilla SEO para títulos
 * - Indicar si los productos son ReDi o no
 * - Configurar redondeo de precios
 * - Sincronizar productos manualmente
 *
 * @package LTMS
 * @version 2.9.323
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$user_id = get_current_user_id();
$creds   = LTMS_Vtex_Sync::get_vendor_credentials( $user_id );
$rules   = LTMS_Vtex_Price_Calculator::get_vendor_rules( $user_id );

$last_sync       = (int) get_user_meta( $user_id, 'ltms_vtex_last_sync', true );
$last_sync_count = (int) get_user_meta( $user_id, 'ltms_vtex_last_sync_count', true );
$can_sync        = ( time() - $last_sync ) >= ( 2 * MINUTE_IN_SECONDS );

$category_ids = (string) get_user_meta( $user_id, 'ltms_vtex_category_ids', true );
$seo_template = (string) get_user_meta( $user_id, 'ltms_vtex_seo_template', true );
if ( empty( $seo_template ) ) {
    $seo_template = '{nombre} {marca} {categoria}';
}
?>
<div style="padding:24px;" id="ltms-vtex-view">

    <div class="ltms-view-header" style="margin-bottom:24px;">
        <h2 style="margin:0;">🔗 VTEX</h2>
        <p style="color:#6b7280;margin:8px 0 0;font-size:0.875rem;">
            <?php esc_html_e( 'Sincroniza tu catálogo de VTEX hacia el marketplace con reglas de precio, SEO y filtrado por categoría.', 'ltms' ); ?>
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
                    <?php esc_html_e( 'Tu cuenta de VTEX está configurada.', 'ltms' ); ?>
                </p>
                <p style="margin:8px 0 0;font-size:0.85rem;color:#6b7280;">
                    <strong><?php esc_html_e( 'Cuenta:', 'ltms' ); ?></strong>
                    <code><?php echo esc_html( $creds['account_name'] ); ?>.<?php echo esc_html( $creds['environment'] ); ?>.com.br</code>
                </p>
            <?php else : ?>
                <p style="margin:0;color:#dc2626;">
                    <?php esc_html_e( 'Aún no has configurado tus credenciales de VTEX. Completa el formulario en "Credenciales" abajo.', 'ltms' ); ?>
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
                <?php esc_html_e( 'Al sincronizar, se descargará tu catálogo de VTEX (filtrado por las categorías configuradas), se calculará el precio con tus reglas, se aplicará SEO al título, y se crearán o actualizarán los productos en el marketplace. Esto puede tardar varios minutos.', 'ltms' ); ?>
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
                    id="ltms-vtex-sync-btn"
                    class="ltms-btn ltms-btn-primary"
                    <?php echo $can_sync ? '' : 'disabled'; ?>>
                🔄 <?php esc_html_e( 'Sincronizar ahora', 'ltms' ); ?>
            </button>
            <div id="ltms-vtex-sync-result" style="margin-top:16px;display:none;"></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Acordeón de configuración -->
    <div class="ltms-card">
        <div class="ltms-card-header">
            <?php esc_html_e( 'Configuración de reglas de negocio', 'ltms' ); ?>
        </div>
        <div class="ltms-card-body">

            <!-- Tab 1: Credenciales -->
            <div style="border:1px solid #e5e7eb;border-radius:8px;margin-bottom:12px;">
                <button type="button" class="ltms-vtex-accordion-header" style="width:100%;padding:16px 20px;background:#f9fafb;border:none;border-radius:8px;text-align:left;font-weight:600;cursor:pointer;display:flex;justify-content:space-between;align-items:center;">
                    <span>🔐 <?php esc_html_e( 'Credenciales VTEX', 'ltms' ); ?></span>
                    <span class="ltms-vtex-accordion-icon">▼</span>
                </button>
                <div class="ltms-vtex-accordion-body" style="display:none;padding:20px;">
                    <form id="ltms-vtex-config-form" method="post">

                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
                            <div>
                                <label for="ltms-vtex-account-name" style="display:block;font-weight:600;margin-bottom:4px;">
                                    <?php esc_html_e( 'Nombre de cuenta VTEX (accountName) *', 'ltms' ); ?>
                                </label>
                                <input type="text"
                                       id="ltms-vtex-account-name"
                                       name="ltms_vtex_account_name"
                                       value="<?php echo esc_attr( $creds['account_name'] ); ?>"
                                       placeholder="mistienda"
                                       required
                                       style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:4px;">
                                <p style="margin:4px 0 0;font-size:0.75rem;color:#9ca3af;">
                                    <?php esc_html_e( 'El nombre de tu cuenta VTEX (primera parte de tu URL).', 'ltms' ); ?>
                                </p>
                            </div>
                            <div>
                                <label for="ltms-vtex-environment" style="display:block;font-weight:600;margin-bottom:4px;">
                                    <?php esc_html_e( 'Environment (opcional)', 'ltms' ); ?>
                                </label>
                                <input type="text"
                                       id="ltms-vtex-environment"
                                       name="ltms_vtex_environment"
                                       value="<?php echo esc_attr( $creds['environment'] ); ?>"
                                       placeholder="vtexcommercestable"
                                       style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:4px;">
                                <p style="margin:4px 0 0;font-size:0.75rem;color:#9ca3af;">
                                    <?php esc_html_e( 'Default: vtexcommercestable (producción).', 'ltms' ); ?>
                                </p>
                            </div>
                        </div>

                        <div style="margin-bottom:16px;">
                            <label for="ltms-vtex-app-key" style="display:block;font-weight:600;margin-bottom:4px;">
                                <?php esc_html_e( 'AppKey (X-VTEX-API-AppKey) *', 'ltms' ); ?>
                            </label>
                            <?php $has_key = ! empty( $creds['app_key'] ); ?>
                            <?php if ( $has_key ) : ?>
                                <!-- VTEX-CONN-004 FIX: no exponer el prefijo del appKey/appToken
                                     descifrado (antes se mostraban los primeros 12 caracteres en claro). -->
                                <div style="padding:8px 12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:4px;margin-bottom:8px;font-family:monospace;font-size:0.85rem;color:#166534;">
                                    ✅ <?php esc_html_e( 'AppKey configurada:', 'ltms' ); ?>
                                    <code>vtexappkey-••••••••</code>
                                </div>
                                <details style="margin-bottom:8px;">
                                    <summary style="cursor:pointer;font-size:0.85rem;color:#6b7280;"><?php esc_html_e( 'Actualizar AppKey', 'ltms' ); ?></summary>
                                    <input type="text"
                                           id="ltms-vtex-app-key"
                                           name="ltms_vtex_app_key"
                                           placeholder="vtexappkey-..."
                                           style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:4px;font-family:monospace;font-size:0.85rem;margin-top:8px;">
                                </details>
                            <?php else : ?>
                                <input type="text"
                                       id="ltms-vtex-app-key"
                                       name="ltms_vtex_app_key"
                                       placeholder="vtexappkey-..."
                                       style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:4px;font-family:monospace;font-size:0.85rem;">
                            <?php endif; ?>
                        </div>

                        <div style="margin-bottom:16px;">
                            <label for="ltms-vtex-app-token" style="display:block;font-weight:600;margin-bottom:4px;">
                                <?php esc_html_e( 'AppToken (X-VTEX-API-AppToken) *', 'ltms' ); ?>
                            </label>
                            <?php $has_token = ! empty( $creds['app_token'] ); ?>
                            <?php if ( $has_token ) : ?>
                                <!-- VTEX-CONN-004 FIX: token enmascarado, sin caracteres en claro. -->
                                <div style="padding:8px 12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:4px;margin-bottom:8px;font-family:monospace;font-size:0.85rem;color:#166534;">
                                    ✅ <?php esc_html_e( 'AppToken configurado:', 'ltms' ); ?>
                                    <code>••••••••••••••••</code>
                                </div>
                                <details style="margin-bottom:8px;">
                                    <summary style="cursor:pointer;font-size:0.85rem;color:#6b7280;"><?php esc_html_e( 'Actualizar AppToken', 'ltms' ); ?></summary>
                                    <input type="password"
                                           id="ltms-vtex-app-token"
                                           name="ltms_vtex_app_token"
                                           placeholder="Ingresa el nuevo AppToken"
                                           style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:4px;font-family:monospace;font-size:0.85rem;margin-top:8px;">
                                </details>
                            <?php else : ?>
                                <input type="password"
                                       id="ltms-vtex-app-token"
                                       name="ltms_vtex_app_token"
                                       placeholder="Ingresa el AppToken"
                                       style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:4px;font-family:monospace;font-size:0.85rem;">
                            <?php endif; ?>
                            <p style="margin:4px 0 0;font-size:0.75rem;color:#9ca3af;">
                                <?php esc_html_e( 'Los generas en tu admin VTEX → Configuración de la cuenta → Credenciales de aplicación.', 'ltms' ); ?>
                            </p>
                        </div>

                        <div style="display:flex;gap:8px;flex-wrap:wrap;">
                            <button type="submit" name="ltms_vtex_action" value="save" class="ltms-btn ltms-btn-primary">
                                💾 <?php esc_html_e( 'Guardar credenciales', 'ltms' ); ?>
                            </button>
                            <button type="button" id="ltms-vtex-test-btn" class="ltms-btn ltms-btn-outline">
                                🔍 <?php esc_html_e( 'Probar conexión', 'ltms' ); ?>
                            </button>
                        </div>

                        <div id="ltms-vtex-test-result" style="margin-top:16px;display:none;"></div>
                    </form>
                </div>
            </div>

            <!-- Tab 2: Filtro de categorías -->
            <div style="border:1px solid #e5e7eb;border-radius:8px;margin-bottom:12px;">
                <button type="button" class="ltms-vtex-accordion-header" style="width:100%;padding:16px 20px;background:#f9fafb;border:none;border-radius:8px;text-align:left;font-weight:600;cursor:pointer;display:flex;justify-content:space-between;align-items:center;">
                    <span>🏷️ <?php esc_html_e( 'Filtro de categorías', 'ltms' ); ?></span>
                    <span class="ltms-vtex-accordion-icon">▼</span>
                </button>
                <div class="ltms-vtex-accordion-body" style="display:none;padding:20px;">
                    <form id="ltms-vtex-categories-form" method="post">

                        <div style="margin-bottom:16px;">
                            <label style="display:block;font-weight:600;margin-bottom:4px;">
                                <?php esc_html_e( 'Selecciona las categorías a sincronizar', 'ltms' ); ?>
                            </label>
                            <p style="margin:0 0 12px;font-size:0.75rem;color:#9ca3af;">
                                <?php esc_html_e( 'Solo se sincronizarán los productos de las categorías seleccionadas (incluye subcategorías). Si no seleccionas ninguna, se sincroniza TODO el catálogo.', 'ltms' ); ?>
                            </p>

                            <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;align-items:center;">
                                <button type="button" id="ltms-vtex-load-cats" class="ltms-btn ltms-btn-outline ltms-btn-sm">
                                    📋 <?php esc_html_e( 'Cargar categorías', 'ltms' ); ?>
                                </button>
                                <button type="button" id="ltms-vtex-refresh-cats" class="ltms-btn ltms-btn-outline ltms-btn-sm" style="display:none;">
                                    🔄 <?php esc_html_e( 'Refrescar', 'ltms' ); ?>
                                </button>
                                <button type="button" id="ltms-vtex-select-all-cats" class="ltms-btn ltms-btn-outline ltms-btn-sm" style="display:none;">
                                    ☑️ <?php esc_html_e( 'Todas', 'ltms' ); ?>
                                </button>
                                <button type="button" id="ltms-vtex-clear-cats" class="ltms-btn ltms-btn-outline ltms-btn-sm" style="display:none;">
                                    ⬜ <?php esc_html_e( 'Ninguna', 'ltms' ); ?>
                                </button>
                                <span id="ltms-vtex-cats-status" style="font-size:0.8rem;color:#6b7280;"></span>
                            </div>

                            <div id="ltms-vtex-cats-container" style="max-height:300px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:8px;padding:12px;background:#fafafa;">
                                <p style="text-align:center;color:#9ca3af;padding:24px 0;margin:0;" id="ltms-vtex-cats-empty">
                                    <?php esc_html_e( 'Haz click en "Cargar categorías" para ver tu árbol de categorías VTEX.', 'ltms' ); ?>
                                </p>
                            </div>

                            <input type="hidden" id="ltms-vtex-category-ids" name="ltms_vtex_category_ids" value="<?php echo esc_attr( $category_ids ); ?>">
                        </div>

                        <button type="submit" name="ltms_vtex_action" value="save_categories" class="ltms-btn ltms-btn-primary">
                            💾 <?php esc_html_e( 'Guardar categorías seleccionadas', 'ltms' ); ?>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Tab 3: Reglas de precio -->
            <div style="border:1px solid #e5e7eb;border-radius:8px;margin-bottom:12px;">
                <button type="button" class="ltms-vtex-accordion-header" style="width:100%;padding:16px 20px;background:#f9fafb;border:none;border-radius:8px;text-align:left;font-weight:600;cursor:pointer;display:flex;justify-content:space-between;align-items:center;">
                    <span>💰 <?php esc_html_e( 'Reglas de cálculo de precio', 'ltms' ); ?></span>
                    <span class="ltms-vtex-accordion-icon">▼</span>
                </button>
                <div class="ltms-vtex-accordion-body" style="display:none;padding:20px;">
                    <form id="ltms-vtex-rules-form" method="post">

                        <div style="padding:12px 16px;background:#f0f4ff;border-radius:8px;margin-bottom:20px;display:flex;align-items:center;gap:12px;">
                            <input type="checkbox"
                                   name="ltms_vtex_is_redi"
                                   id="ltms-vtex-is-redi"
                                   value="yes"
                                   <?php checked( $rules['is_redi'], true ); ?>
                                   style="width:20px;height:20px;">
                            <label for="ltms-vtex-is-redi" style="font-weight:600;cursor:pointer;">
                                🔁 <?php esc_html_e( 'Los productos sincronizados son ReDi', 'ltms' ); ?>
                            </label>
                            <p style="margin:0;font-size:0.75rem;color:#6b7280;">
                                <?php esc_html_e( 'Si activas esto, se aplicará el costo ReDi configurado abajo.', 'ltms' ); ?>
                            </p>
                        </div>

                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:20px;">
                            <div>
                                <label style="display:block;font-weight:600;margin-bottom:4px;font-size:0.85rem;">
                                    <?php esc_html_e( 'Transporte (%)', 'ltms' ); ?>
                                </label>
                                <input type="number" name="transport_pct" value="<?php echo esc_attr( $rules['transport_pct'] ); ?>" min="0" max="100" step="0.1" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:4px;">
                                <p style="margin:4px 0 0;font-size:0.7rem;color:#9ca3af;"><?php esc_html_e( '% del costo base', 'ltms' ); ?></p>
                            </div>
                            <div>
                                <label style="display:block;font-weight:600;margin-bottom:4px;font-size:0.85rem;">
                                    <?php esc_html_e( 'Gasto publicitario (%)', 'ltms' ); ?>
                                </label>
                                <input type="number" name="advertising_pct" value="<?php echo esc_attr( $rules['advertising_pct'] ); ?>" min="0" max="100" step="0.1" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:4px;">
                                <p style="margin:4px 0 0;font-size:0.7rem;color:#9ca3af;"><?php esc_html_e( '% del costo base', 'ltms' ); ?></p>
                            </div>
                            <div>
                                <label style="display:block;font-weight:600;margin-bottom:4px;font-size:0.85rem;">
                                    <?php esc_html_e( 'Devoluciones estimadas (%)', 'ltms' ); ?>
                                </label>
                                <input type="number" name="returns_pct" value="<?php echo esc_attr( $rules['returns_pct'] ); ?>" min="0" max="100" step="0.1" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:4px;">
                                <p style="margin:4px 0 0;font-size:0.7rem;color:#9ca3af;"><?php esc_html_e( '% del precio final', 'ltms' ); ?></p>
                            </div>
                            <div>
                                <label style="display:block;font-weight:600;margin-bottom:4px;font-size:0.85rem;">
                                    <?php esc_html_e( 'Margen de ganancia (%)', 'ltms' ); ?>
                                </label>
                                <input type="number" name="margin_pct" value="<?php echo esc_attr( $rules['margin_pct'] ); ?>" min="0" max="500" step="0.1" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:4px;">
                                <p style="margin:4px 0 0;font-size:0.7rem;color:#9ca3af;"><?php esc_html_e( '% sobre costo + gastos', 'ltms' ); ?></p>
                            </div>
                            <div>
                                <label style="display:block;font-weight:600;margin-bottom:4px;font-size:0.85rem;">
                                    <?php esc_html_e( 'Comisión Lo Tengo (%)', 'ltms' ); ?>
                                </label>
                                <input type="number" name="lotengo_commission_pct" value="<?php echo esc_attr( $rules['lotengo_commission_pct'] ); ?>" min="0" max="50" step="0.1" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:4px;">
                                <p style="margin:4px 0 0;font-size:0.7rem;color:#9ca3af;"><?php esc_html_e( '% del precio final', 'ltms' ); ?></p>
                            </div>
                            <div>
                                <label style="display:block;font-weight:600;margin-bottom:4px;font-size:0.85rem;">
                                    <?php esc_html_e( 'IVA (%)', 'ltms' ); ?>
                                </label>
                                <select name="iva_pct" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:4px;">
                                    <?php
                                    $iva_options = [ 0, 5, 19, 16 ];
                                    foreach ( $iva_options as $opt ) :
                                        $label = $opt === 0 ? '0% (Exento)' : $opt . '%';
                                        ?>
                                        <option value="<?php echo esc_attr( $opt ); ?>" <?php selected( (float) $rules['iva_pct'], (float) $opt ); ?>>
                                            <?php echo esc_html( $label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p style="margin:4px 0 0;font-size:0.7rem;color:#9ca3af;"><?php esc_html_e( 'CO: 0/5/19% — MX: 0/16%', 'ltms' ); ?></p>
                            </div>
                            <div>
                                <label style="display:block;font-weight:600;margin-bottom:4px;font-size:0.85rem;">
                                    <?php esc_html_e( 'Costo ReDi (%)', 'ltms' ); ?>
                                </label>
                                <input type="number" name="redi_cost_pct" value="<?php echo esc_attr( $rules['redi_cost_pct'] ); ?>" min="0" max="100" step="0.1" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:4px;">
                                <p style="margin:4px 0 0;font-size:0.7rem;color:#9ca3af;"><?php esc_html_e( '% del costo base (solo si ReDi activo)', 'ltms' ); ?></p>
                            </div>
                            <div>
                                <label style="display:block;font-weight:600;margin-bottom:4px;font-size:0.85rem;">
                                    <?php esc_html_e( 'Redondeo de precio', 'ltms' ); ?>
                                </label>
                                <select name="round_multiple" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:4px;">
                                    <?php
                                    $round_options = [ 100 => '$100', 500 => '$500', 1000 => '$1.000', 5000 => '$5.000', 10000 => '$10.000' ];
                                    foreach ( $round_options as $val => $label ) :
                                        ?>
                                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( (int) $rules['round_multiple'], $val ); ?>>
                                            <?php echo esc_html( $label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p style="margin:4px 0 0;font-size:0.7rem;color:#9ca3af;"><?php esc_html_e( 'Redondear precio por encima al múltiplo', 'ltms' ); ?></p>
                            </div>
                        </div>

                        <!-- Ejemplo de cálculo -->
                        <div style="padding:16px;background:#f0f9ff;border-radius:8px;margin-bottom:16px;">
                            <h4 style="margin:0 0 8px;font-size:0.9rem;">📊 <?php esc_html_e( 'Ejemplo de cálculo', 'ltms' ); ?></h4>
                            <p style="margin:0;font-size:0.8rem;color:#374151;">
                                <?php esc_html_e( 'Para un producto con costo VTEX de $50.000:', 'ltms' ); ?>
                            </p>
                            <div id="ltms-vtex-price-example" style="font-family:monospace;font-size:0.8rem;margin-top:8px;color:#1e40af;"></div>
                        </div>

                        <button type="submit" name="ltms_vtex_action" value="save_rules" class="ltms-btn ltms-btn-primary">
                            💾 <?php esc_html_e( 'Guardar reglas de precio', 'ltms' ); ?>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Tab 4: SEO -->
            <div style="border:1px solid #e5e7eb;border-radius:8px;">
                <button type="button" class="ltms-vtex-accordion-header" style="width:100%;padding:16px 20px;background:#f9fafb;border:none;border-radius:8px;text-align:left;font-weight:600;cursor:pointer;display:flex;justify-content:space-between;align-items:center;">
                    <span>🔍 <?php esc_html_e( 'SEO — Plantilla de títulos', 'ltms' ); ?></span>
                    <span class="ltms-vtex-accordion-icon">▼</span>
                </button>
                <div class="ltms-vtex-accordion-body" style="display:none;padding:20px;">
                    <form id="ltms-vtex-seo-form" method="post">

                        <div style="margin-bottom:16px;">
                            <label for="ltms-vtex-seo-template" style="display:block;font-weight:600;margin-bottom:4px;">
                                <?php esc_html_e( 'Plantilla para título del producto', 'ltms' ); ?>
                            </label>
                            <input type="text"
                                   id="ltms-vtex-seo-template"
                                   name="ltms_vtex_seo_template"
                                   value="<?php echo esc_attr( $seo_template ); ?>"
                                   style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:4px;font-family:monospace;">
                            <p style="margin:4px 0 0;font-size:0.75rem;color:#9ca3af;">
                                <?php esc_html_e( 'Placeholders disponibles:', 'ltms' ); ?>
                                <code>{nombre}</code> <?php esc_html_e( 'nombre del producto', 'ltms' ); ?>,
                                <code>{marca}</code> <?php esc_html_e( 'marca', 'ltms' ); ?>,
                                <code>{categoria}</code> <?php esc_html_e( 'categoría', 'ltms' ); ?>,
                                <code>{modelo}</code> <?php esc_html_e( 'modelo', 'ltms' ); ?>,
                                <code>{codigo}</code> <?php esc_html_e( 'código SKU', 'ltms' ); ?>.
                            </p>
                        </div>

                        <div style="padding:16px;background:#f0fdf4;border-radius:8px;margin-bottom:16px;">
                            <h4 style="margin:0 0 8px;font-size:0.9rem;">👁️ <?php esc_html_e( 'Vista previa', 'ltms' ); ?></h4>
                            <div id="ltms-vtex-seo-preview" style="font-weight:600;color:#166534;"></div>
                        </div>

                        <button type="submit" name="ltms_vtex_action" value="save_seo" class="ltms-btn ltms-btn-primary">
                            💾 <?php esc_html_e( 'Guardar plantilla SEO', 'ltms' ); ?>
                        </button>
                    </form>
                </div>
            </div>

        </div>
    </div>

    <!-- Información de ayuda -->
    <div style="margin-top:24px;padding:16px 20px;background:#f0f4ff;border-radius:8px;border-left:3px solid #3b82f6;">
        <h4 style="margin:0 0 8px;color:#1e40af;">ℹ️ <?php esc_html_e( '¿Cómo obtener tus credenciales VTEX?', 'ltms' ); ?></h4>
        <ol style="margin:0;padding-left:20px;color:#374151;font-size:0.875rem;line-height:1.6;">
            <li><?php esc_html_e( 'Inicia sesión en tu admin VTEX (tuidentificadordetienda.myvtex.com/admin)', 'ltms' ); ?></li>
            <li><?php esc_html_e( 'Ve a Configuración de la cuenta → Credenciales de aplicación', 'ltms' ); ?></li>
            <li><?php esc_html_e( 'Crea una aplicación (AppKey) con permisos de Catálogo, Precios e Inventario (Logística)', 'ltms' ); ?></li>
            <li><?php esc_html_e( 'Copia el AppKey y el AppToken y pégalos en "Credenciales" arriba', 'ltms' ); ?></li>
            <li><?php esc_html_e( 'El accountName es la primera parte de tu URL VTEX (ej: mistienda para mistienda.vtexcommercestable.com.br)', 'ltms' ); ?></li>
        </ol>
    </div>

</div>

<?php
// CSP FIX (patrón FASE2B): inline <script> moved to external assets/js/ltms-vtex.js
wp_enqueue_script( 'ltms-vtex', ltms_asset_url( 'js/ltms-vtex' ), [ 'jquery' ], LTMS_VERSION, true );