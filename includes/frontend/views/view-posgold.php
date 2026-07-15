<?php
/**
 * Vista SPA: PosGold — Sincronización de catálogo con reglas de negocio.
 *
 * Permite a los vendedores:
 * - Configurar credenciales de PosGold (subdomain, token, etc.)
 * - Filtrar qué categorías sincronizar (categoriaid)
 * - Configurar reglas de cálculo de precio (8 componentes)
 * - Configurar plantilla SEO para títulos
 * - Indicar si los productos son ReDi o no
 * - Configurar redondeo de precios
 * - Sincronizar productos manualmente
 *
 * @package LTMS
 * @version 2.9.31
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$user_id = get_current_user_id();
$creds   = LTMS_PosGold_Sync::get_vendor_credentials( $user_id );
$rules   = LTMS_PosGold_Price_Calculator::get_vendor_rules( $user_id );

$last_sync       = (int) get_user_meta( $user_id, 'ltms_posgold_last_sync', true );
$last_sync_count = (int) get_user_meta( $user_id, 'ltms_posgold_last_sync_count', true );
$can_sync        = ( time() - $last_sync ) >= ( 2 * MINUTE_IN_SECONDS );

$category_ids = (string) get_user_meta( $user_id, 'ltms_posgold_category_ids', true );
$seo_template = (string) get_user_meta( $user_id, 'ltms_posgold_seo_template', true );
if ( empty( $seo_template ) ) {
    $seo_template = '{nombre} {marca} {categoria}';
}
?>
<div style="padding:24px;" id="ltms-posgold-view">

    <div class="ltms-view-header" style="margin-bottom:24px;">
        <h2 style="margin:0;">🔗 PosGold</h2>
        <p style="color:#6b7280;margin:8px 0 0;font-size:0.875rem;">
            <?php esc_html_e( 'Sincroniza tu catálogo de PosGold hacia el marketplace con reglas de precio, SEO y filtrado por categoría.', 'ltms' ); ?>
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
                    <?php esc_html_e( 'Aún no has configurado tus credenciales de PosGold. Completa el formulario en "Credenciales" abajo.', 'ltms' ); ?>
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
                <?php esc_html_e( 'Al sincronizar, se descargará tu catálogo de PosGold (filtrado por las categorías configuradas), se calculará el precio con tus reglas, se aplicará SEO al título, se depurarán duplicados, y se crearán o actualizarán los productos en el marketplace. Esto puede tardar varios minutos.', 'ltms' ); ?>
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
            <!-- v2.9.77 P0-UI-6: Progress indicator para sync de larga duración -->
            <div id="ltms-posgold-sync-progress" style="margin-top:16px;display:none;">
                <div style="display:flex;align-items:center;gap:12px;padding:12px 16px;background:#eff6ff;border-radius:8px;border:1px solid #bfdbfe;">
                    <div class="ltms-spinner" style="width:20px;height:20px;border:3px solid #dbeafe;border-top:3px solid #2563eb;border-radius:50%;animation:ltms-spin 1s linear infinite;flex-shrink:0;"></div>
                    <div style="flex:1;">
                        <div style="font-weight:600;color:#1e40af;font-size:0.85rem;" id="ltms-posgold-sync-status"><?php esc_html_e( 'Sincronizando productos...', 'ltms' ); ?></div>
                        <div style="font-size:0.75rem;color:#3b82f6;margin-top:2px;" id="ltms-posgold-sync-hint"><?php esc_html_e( 'Esto puede tardar varios minutos. Puedes continuar navegando.', 'ltms' ); ?></div>
                    </div>
                </div>
            </div>
            <div id="ltms-posgold-sync-result" style="margin-top:16px;display:none;"></div>
            <style>@keyframes ltms-spin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}</style>
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
                <button type="button" class="ltms-posgold-accordion-header" style="width:100%;padding:16px 20px;background:#f9fafb;border:none;border-radius:8px;text-align:left;font-weight:600;cursor:pointer;display:flex;justify-content:space-between;align-items:center;">
                    <span>🔐 <?php esc_html_e( 'Credenciales PosGold', 'ltms' ); ?></span>
                    <span class="ltms-posgold-accordion-icon">▼</span>
                </button>
                <div class="ltms-posgold-accordion-body" style="display:none;padding:20px;">
                    <form id="ltms-posgold-config-form" method="post">
                        <?php /* v2.9.70 P3-4: Dead nonce removed — JS uses ltms_dashboard_nonce via AJAX */ ?>

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
                                    <?php esc_html_e( 'Tu subdominio de PosGold', 'ltms' ); ?>
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
                                    <?php esc_html_e( 'ID de la bodega para stock (default: 1)', 'ltms' ); ?>
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
                            </div>
                        </div>

                        <div style="margin-bottom:16px;">
                            <label for="ltms-posgold-token" style="display:block;font-weight:600;margin-bottom:4px;">
                                <?php esc_html_e( 'Bearer Token (JWT) *', 'ltms' ); ?>
                            </label>
                            <?php
                            // v2.9.61 DEEP-AUDIT-002 P0-2 FIX: No mostrar el token completo (credencial sensible).
                            // Mostrar solo si está configurado (masked) + opción de actualizar.
                            $has_token = ! empty( $creds['token'] );
                            $masked_token = $has_token ? substr( $creds['token'], 0, 20 ) . '...' . substr( $creds['token'], -10 ) : '';
                            ?>
                            <?php if ( $has_token ) : ?>
                                <div style="padding:8px 12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:4px;margin-bottom:8px;font-family:monospace;font-size:0.85rem;color:#166534;">
                                    ✅ <?php esc_html_e( 'Token configurado:', 'ltms' ); ?> <code><?php echo esc_html( $masked_token ); ?></code>
                                </div>
                                <details style="margin-bottom:8px;">
                                    <summary style="cursor:pointer;font-size:0.85rem;color:#6b7280;"><?php esc_html_e( 'Actualizar token', 'ltms' ); ?></summary>
                                    <textarea id="ltms-posgold-token"
                                              name="ltms_posgold_token"
                                              rows="3"
                                              placeholder="eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..."
                                              style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:4px;font-family:monospace;font-size:0.85rem;margin-top:8px;"></textarea>
                                </details>
                            <?php else : ?>
                                <textarea id="ltms-posgold-token"
                                          name="ltms_posgold_token"
                                          rows="3"
                                          placeholder="eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..."
                                          style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:4px;font-family:monospace;font-size:0.85rem;"></textarea>
                            <?php endif; ?>
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

            <!-- Tab 2: Filtro de categorías -->
            <div style="border:1px solid #e5e7eb;border-radius:8px;margin-bottom:12px;">
                <button type="button" class="ltms-posgold-accordion-header" style="width:100%;padding:16px 20px;background:#f9fafb;border:none;border-radius:8px;text-align:left;font-weight:600;cursor:pointer;display:flex;justify-content:space-between;align-items:center;">
                    <span>🏷️ <?php esc_html_e( 'Filtro de categorías', 'ltms' ); ?></span>
                    <span class="ltms-posgold-accordion-icon">▼</span>
                </button>
                <div class="ltms-posgold-accordion-body" style="display:none;padding:20px;">
                    <form id="ltms-posgold-categories-form" method="post">
                        <?php /* v2.9.70 P3-4: Dead nonce removed */ ?>

                        <div style="margin-bottom:16px;">
                            <label style="display:block;font-weight:600;margin-bottom:4px;">
                                <?php esc_html_e( 'Selecciona las categorías a sincronizar', 'ltms' ); ?>
                            </label>
                            <p style="margin:0 0 12px;font-size:0.75rem;color:#9ca3af;">
                                <?php esc_html_e( 'Solo se sincronizarán los productos de las categorías seleccionadas. Si no seleccionas ninguna, se sincroniza TODO el catálogo.', 'ltms' ); ?>
                            </p>

                            <!-- Botones de acción -->
                            <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;align-items:center;">
                                <button type="button" id="ltms-posgold-load-cats" class="ltms-btn ltms-btn-outline ltms-btn-sm">
                                    📋 <?php esc_html_e( 'Cargar categorías', 'ltms' ); ?>
                                </button>
                                <button type="button" id="ltms-posgold-refresh-cats" class="ltms-btn ltms-btn-outline ltms-btn-sm" style="display:none;">
                                    🔄 <?php esc_html_e( 'Refrescar', 'ltms' ); ?>
                                </button>
                                <button type="button" id="ltms-posgold-select-all-cats" class="ltms-btn ltms-btn-outline ltms-btn-sm" style="display:none;">
                                    ☑️ <?php esc_html_e( 'Todas', 'ltms' ); ?>
                                </button>
                                <button type="button" id="ltms-posgold-clear-cats" class="ltms-btn ltms-btn-outline ltms-btn-sm" style="display:none;">
                                    ⬜ <?php esc_html_e( 'Ninguna', 'ltms' ); ?>
                                </button>
                                <span id="ltms-posgold-cats-status" style="font-size:0.8rem;color:#6b7280;"></span>
                            </div>

                            <!-- Contenedor de checkboxes -->
                            <div id="ltms-posgold-cats-container" style="max-height:300px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:8px;padding:12px;background:#fafafa;">
                                <p style="text-align:center;color:#9ca3af;padding:24px 0;margin:0;" id="ltms-posgold-cats-empty">
                                    <?php esc_html_e( 'Haz click en "Cargar categorías" para ver tu lista de categorías PosGold.', 'ltms' ); ?>
                                </p>
                            </div>

                            <!-- Input hidden para guardar los IDs seleccionados -->
                            <input type="hidden" id="ltms-posgold-category-ids" name="ltms_posgold_category_ids" value="<?php echo esc_attr( $category_ids ); ?>">
                        </div>

                        <button type="submit" name="ltms_posgold_action" value="save_categories" class="ltms-btn ltms-btn-primary">
                            💾 <?php esc_html_e( 'Guardar categorías seleccionadas', 'ltms' ); ?>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Tab 3: Reglas de precio -->
            <div style="border:1px solid #e5e7eb;border-radius:8px;margin-bottom:12px;">
                <button type="button" class="ltms-posgold-accordion-header" style="width:100%;padding:16px 20px;background:#f9fafb;border:none;border-radius:8px;text-align:left;font-weight:600;cursor:pointer;display:flex;justify-content:space-between;align-items:center;">
                    <span>💰 <?php esc_html_e( 'Reglas de cálculo de precio', 'ltms' ); ?></span>
                    <span class="ltms-posgold-accordion-icon">▼</span>
                </button>
                <div class="ltms-posgold-accordion-body" style="display:none;padding:20px;">
                    <form id="ltms-posgold-rules-form" method="post">
                        <?php /* v2.9.70 P3-4: Dead nonce removed */ ?>

                        <!-- Toggle ReDi -->
                        <div style="padding:12px 16px;background:#f0f4ff;border-radius:8px;margin-bottom:20px;display:flex;align-items:center;gap:12px;">
                            <input type="checkbox"
                                   name="ltms_posgold_is_redi"
                                   id="ltms-posgold-is-redi"
                                   value="yes"
                                   <?php checked( $rules['is_redi'], true ); ?>
                                   style="width:20px;height:20px;">
                            <label for="ltms-posgold-is-redi" style="font-weight:600;cursor:pointer;">
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
                                <input type="number"
                                       name="transport_pct"
                                       value="<?php echo esc_attr( $rules['transport_pct'] ); ?>"
                                       min="0" max="100" step="0.1"
                                       style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:4px;">
                                <p style="margin:4px 0 0;font-size:0.7rem;color:#9ca3af;">
                                    <?php esc_html_e( '% del costo base', 'ltms' ); ?>
                                </p>
                            </div>

                            <div>
                                <label style="display:block;font-weight:600;margin-bottom:4px;font-size:0.85rem;">
                                    <?php esc_html_e( 'Gasto publicitario (%)', 'ltms' ); ?>
                                </label>
                                <input type="number"
                                       name="advertising_pct"
                                       value="<?php echo esc_attr( $rules['advertising_pct'] ); ?>"
                                       min="0" max="100" step="0.1"
                                       style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:4px;">
                                <p style="margin:4px 0 0;font-size:0.7rem;color:#9ca3af;">
                                    <?php esc_html_e( '% del costo base', 'ltms' ); ?>
                                </p>
                            </div>

                            <div>
                                <label style="display:block;font-weight:600;margin-bottom:4px;font-size:0.85rem;">
                                    <?php esc_html_e( 'Devoluciones estimadas (%)', 'ltms' ); ?>
                                </label>
                                <input type="number"
                                       name="returns_pct"
                                       value="<?php echo esc_attr( $rules['returns_pct'] ); ?>"
                                       min="0" max="100" step="0.1"
                                       style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:4px;">
                                <p style="margin:4px 0 0;font-size:0.7rem;color:#9ca3af;">
                                    <?php esc_html_e( '% del precio final', 'ltms' ); ?>
                                </p>
                            </div>

                            <div>
                                <label style="display:block;font-weight:600;margin-bottom:4px;font-size:0.85rem;">
                                    <?php esc_html_e( 'Margen de ganancia (%)', 'ltms' ); ?>
                                </label>
                                <input type="number"
                                       name="margin_pct"
                                       value="<?php echo esc_attr( $rules['margin_pct'] ); ?>"
                                       min="0" max="500" step="0.1"
                                       style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:4px;">
                                <p style="margin:4px 0 0;font-size:0.7rem;color:#9ca3af;">
                                    <?php esc_html_e( '% sobre costo + gastos', 'ltms' ); ?>
                                </p>
                            </div>

                            <div>
                                <label style="display:block;font-weight:600;margin-bottom:4px;font-size:0.85rem;">
                                    <?php esc_html_e( 'Comisión Lo Tengo (%)', 'ltms' ); ?>
                                </label>
                                <input type="number"
                                       name="lotengo_commission_pct"
                                       value="<?php echo esc_attr( $rules['lotengo_commission_pct'] ); ?>"
                                       min="0" max="50" step="0.1"
                                       style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:4px;">
                                <p style="margin:4px 0 0;font-size:0.7rem;color:#9ca3af;">
                                    <?php esc_html_e( '% del precio final', 'ltms' ); ?>
                                </p>
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
                                <p style="margin:4px 0 0;font-size:0.7rem;color:#9ca3af;">
                                    <?php esc_html_e( 'CO: 0/5/19% — MX: 0/16%', 'ltms' ); ?>
                                </p>
                            </div>

                            <div>
                                <label style="display:block;font-weight:600;margin-bottom:4px;font-size:0.85rem;">
                                    <?php esc_html_e( 'Costo ReDi (%)', 'ltms' ); ?>
                                </label>
                                <input type="number"
                                       name="redi_cost_pct"
                                       value="<?php echo esc_attr( $rules['redi_cost_pct'] ); ?>"
                                       min="0" max="100" step="0.1"
                                       style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:4px;">
                                <p style="margin:4px 0 0;font-size:0.7rem;color:#9ca3af;">
                                    <?php esc_html_e( '% del costo base (solo si ReDi activo)', 'ltms' ); ?>
                                </p>
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
                                <p style="margin:4px 0 0;font-size:0.7rem;color:#9ca3af;">
                                    <?php esc_html_e( 'Redondear precio por encima al múltiplo', 'ltms' ); ?>
                                </p>
                            </div>
                        </div>

                        <!-- Ejemplo de cálculo -->
                        <div style="padding:16px;background:#f0f9ff;border-radius:8px;margin-bottom:16px;">
                            <h4 style="margin:0 0 8px;font-size:0.9rem;">📊 <?php esc_html_e( 'Ejemplo de cálculo', 'ltms' ); ?></h4>
                            <p style="margin:0;font-size:0.8rem;color:#374151;">
                                <?php esc_html_e( 'Para un producto con costo PosGold de $50.000:', 'ltms' ); ?>
                            </p>
                            <div id="ltms-posgold-price-example" style="font-family:monospace;font-size:0.8rem;margin-top:8px;color:#1e40af;"></div>
                        </div>

                        <button type="submit" name="ltms_posgold_action" value="save_rules" class="ltms-btn ltms-btn-primary">
                            💾 <?php esc_html_e( 'Guardar reglas de precio', 'ltms' ); ?>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Tab 4: SEO -->
            <div style="border:1px solid #e5e7eb;border-radius:8px;">
                <button type="button" class="ltms-posgold-accordion-header" style="width:100%;padding:16px 20px;background:#f9fafb;border:none;border-radius:8px;text-align:left;font-weight:600;cursor:pointer;display:flex;justify-content:space-between;align-items:center;">
                    <span>🔍 <?php esc_html_e( 'SEO — Plantilla de títulos', 'ltms' ); ?></span>
                    <span class="ltms-posgold-accordion-icon">▼</span>
                </button>
                <div class="ltms-posgold-accordion-body" style="display:none;padding:20px;">
                    <form id="ltms-posgold-seo-form" method="post">
                        <?php /* v2.9.70 P3-4: Dead nonce removed */ ?>

                        <div style="margin-bottom:16px;">
                            <label for="ltms-posgold-seo-template" style="display:block;font-weight:600;margin-bottom:4px;">
                                <?php esc_html_e( 'Plantilla para título del producto', 'ltms' ); ?>
                            </label>
                            <input type="text"
                                   id="ltms-posgold-seo-template"
                                   name="ltms_posgold_seo_template"
                                   value="<?php echo esc_attr( $seo_template ); ?>"
                                   style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:4px;font-family:monospace;">
                            <p style="margin:4px 0 0;font-size:0.75rem;color:#9ca3af;">
                                <?php esc_html_e( 'Placeholders disponibles:', 'ltms' ); ?>
                                <code>{nombre}</code> <?php esc_html_e( 'nombre del producto', 'ltms' ); ?>,
                                <code>{marca}</code> <?php esc_html_e( 'marca', 'ltms' ); ?>,
                                <code>{categoria}</code> <?php esc_html_e( 'categoría', 'ltms' ); ?>,
                                <code>{modelo}</code> <?php esc_html_e( 'modelo', 'ltms' ); ?>,
                                <code>{codigo}</code> <?php esc_html_e( 'código PosGold', 'ltms' ); ?>.
                            </p>
                        </div>

                        <!-- Preview -->
                        <div style="padding:16px;background:#f0fdf4;border-radius:8px;margin-bottom:16px;">
                            <h4 style="margin:0 0 8px;font-size:0.9rem;">👁️ <?php esc_html_e( 'Vista previa', 'ltms' ); ?></h4>
                            <div id="ltms-posgold-seo-preview" style="font-weight:600;color:#166534;"></div>
                        </div>

                        <button type="submit" name="ltms_posgold_action" value="save_seo" class="ltms-btn ltms-btn-primary">
                            💾 <?php esc_html_e( 'Guardar plantilla SEO', 'ltms' ); ?>
                        </button>
                    </form>
                </div>
            </div>

        </div>
    </div>

    <!-- Información de ayuda -->
    <div style="margin-top:24px;padding:16px 20px;background:#f0f4ff;border-radius:8px;border-left:3px solid #3b82f6;">
        <h4 style="margin:0 0 8px;color:#1e40af;">ℹ️ <?php esc_html_e( '¿Cómo obtener tus credenciales PosGold?', 'ltms' ); ?></h4>
        <ol style="margin:0;padding-left:20px;color:#374151;font-size:0.875rem;line-height:1.6;">
            <li><?php esc_html_e( 'Inicia sesión en tu panel de PosGold (tusubdominio.goldpos.com.co)', 'ltms' ); ?></li>
            <li><?php esc_html_e( 'Ve a la sección de API o Integraciones', 'ltms' ); ?></li>
            <li><?php esc_html_e( 'Genera un Bearer Token (JWT)', 'ltms' ); ?></li>
            <li><?php esc_html_e( 'Copia el token y pégalo en "Credenciales" arriba', 'ltms' ); ?></li>
            <li><?php esc_html_e( 'El Subdominio es la primera parte de tu URL (ej: jugueteriataiwan)', 'ltms' ); ?></li>
            <li><?php esc_html_e( 'Para conocer los categoriaid de tus categorías, consulta la documentación de PosGold o usa Postman', 'ltms' ); ?></li>
        </ol>
    </div>

</div>

<?php
// FASE2B P0 FIX (CSP): inline <script> moved to external assets/js/ltms-posgold.js
wp_enqueue_script( 'ltms-posgold', LTMS_ASSETS_URL . 'js/ltms-posgold.js', [ 'jquery' ], LTMS_VERSION, true );
?>
