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

<script>
(function($){
    'use strict';

    var nonce = (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.nonce) || '';
    var ajaxUrl = (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.ajax_url) || ajaxurl;

    // Acordeón
    $('.ltms-posgold-accordion-header').on('click', function(){
        var $body = $(this).next('.ltms-posgold-accordion-body');
        var $icon = $(this).find('.ltms-posgold-accordion-icon');
        $body.slideToggle(200);
        $icon.text($body.is(':visible') ? '▲' : '▼');
    });

    // Guardar credenciales
    $('#ltms-posgold-config-form').on('submit', function(e){
        e.preventDefault();
        var $btn = $(this).find('button[type="submit"]');
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
            alert('Error de red.');
        });
    });

    // Guardar categorías
    $('#ltms-posgold-categories-form').on('submit', function(e){
        e.preventDefault();
        var $btn = $(this).find('button[type="submit"]');
        $btn.prop('disabled', true).text('Guardando...');

        $.post(ajaxUrl, {
            action: 'ltms_save_posgold_categories',
            nonce: nonce,
            category_ids: $('#ltms-posgold-category-ids').val()
        }).done(function(resp){
            $btn.prop('disabled', false).html('💾 Guardar categorías seleccionadas');
            if (resp.success) {
                alert('✓ ' + resp.data.message);
            } else {
                alert('Error: ' + (resp.data.message || resp.data));
            }
        }).fail(function(){
            $btn.prop('disabled', false).html('💾 Guardar categorías seleccionadas');
            alert('Error de red.');
        });
    });

    // === Cargar categorías PosGold (dropdown con checkboxes) ===

    var selectedCatIds = $('#ltms-posgold-category-ids').val().split(',').filter(function(v){ return v.trim() !== ''; });

    function renderCategoriesList(categories) {
        var $container = $('#ltms-posgold-cats-container');
        $container.empty();

        if (!categories || categories.length === 0) {
            $container.html('<p style="text-align:center;color:#9ca3af;padding:24px 0;margin:0;">No se encontraron categorías en tu PosGold.</p>');
            return;
        }

        var html = '';
        categories.forEach(function(cat) {
            var checked = selectedCatIds.indexOf(cat.id) !== -1 ? 'checked' : '';
            var countLabel = cat.count > 0 ? ' <span style="color:#9ca3af;font-size:0.8rem;">(' + cat.count + ' productos)</span>' : '';
            html += '<label style="display:flex;align-items:center;padding:8px 12px;border-radius:6px;cursor:pointer;background:#fff;margin-bottom:4px;border:1px solid #e5e7eb;">';
            html += '<input type="checkbox" class="ltms-posgold-cat-checkbox" value="' + cat.id + '" ' + checked + ' style="margin-right:8px;width:18px;height:18px;">';
            html += '<span style="flex:1;"><strong>' + cat.nombre + '</strong>' + countLabel + '<br><span style="font-size:0.7rem;color:#9ca3af;">ID: ' + cat.id + '</span></span>';
            html += '</label>';
        });
        $container.html(html);

        // Mostrar botones de acción
        $('#ltms-posgold-refresh-cats, #ltms-posgold-select-all-cats, #ltms-posgold-clear-cats').show();
        $('#ltms-posgold-load-cats').hide();

        // Manejar cambios en checkboxes
        $('.ltms-posgold-cat-checkbox').on('change', function(){
            updateSelectedCats();
        });
    }

    function updateSelectedCats() {
        selectedCatIds = [];
        $('.ltms-posgold-cat-checkbox:checked').each(function(){
            selectedCatIds.push($(this).val());
        });
        $('#ltms-posgold-category-ids').val(selectedCatIds.join(','));
        var count = selectedCatIds.length;
        $('#ltms-posgold-cats-status').text(count === 0 ? 'Ninguna seleccionada (se sincronizará TODO)' : count + ' seleccionada(s)');
    }

    function loadCategories(forceRefresh) {
        var $status = $('#ltms-posgold-cats-status');
        $status.text('Cargando categorías...');

        $.post(ajaxUrl, {
            action: 'ltms_get_posgold_categories',
            nonce: nonce,
            force_refresh: forceRefresh ? 'yes' : 'no'
        }).done(function(resp){
            if (resp.success) {
                renderCategoriesList(resp.data.categories);
                var source = resp.data.source === 'cache' ? ' (cache)' : (resp.data.source === 'fallback' ? ' (extraídas de productos)' : ' (endpoint)');
                $('#ltms-posgold-cats-status').text(resp.data.message + source);
                updateSelectedCats();
            } else {
                $('#ltms-posgold-cats-status').text('Error: ' + (resp.data.message || resp.data));
                $('#ltms-posgold-cats-container').html('<p style="text-align:center;color:#dc2626;padding:24px 0;margin:0;">✗ ' + (resp.data.message || resp.data) + '<br><br>Verifica tus credenciales en la sección "Credenciales PosGold" arriba.</p>');
            }
        }).fail(function(){
            $('#ltms-posgold-cats-status').text('Error de red.');
        });
    }

    // Cargar categorías al hacer click
    $('#ltms-posgold-load-cats, #ltms-posgold-refresh-cats').on('click', function(){
        loadCategories($(this).attr('id') === 'ltms-posgold-refresh-cats');
    });

    // Seleccionar todas / ninguna
    $('#ltms-posgold-select-all-cats').on('click', function(){
        $('.ltms-posgold-cat-checkbox').prop('checked', true);
        updateSelectedCats();
    });
    $('#ltms-posgold-clear-cats').on('click', function(){
        $('.ltms-posgold-cat-checkbox').prop('checked', false);
        updateSelectedCats();
    });

    // Cargar categorías automáticamente si ya tiene credenciales configuradas
    if ($('#ltms-posgold-subdomain').val() && $('#ltms-posgold-token').val()) {
        loadCategories(false);
    }

    // Guardar reglas de precio
    $('#ltms-posgold-rules-form').on('submit', function(e){
        e.preventDefault();
        var $btn = $(this).find('button[type="submit"]');
        $btn.prop('disabled', true).text('Guardando...');

        $.post(ajaxUrl, {
            action: 'ltms_save_posgold_rules',
            nonce: nonce,
            is_redi: $('#ltms-posgold-is-redi').is(':checked') ? 'yes' : 'no',
            transport_pct: $('input[name="transport_pct"]').val(),
            advertising_pct: $('input[name="advertising_pct"]').val(),
            returns_pct: $('input[name="returns_pct"]').val(),
            margin_pct: $('input[name="margin_pct"]').val(),
            lotengo_commission_pct: $('input[name="lotengo_commission_pct"]').val(),
            iva_pct: $('select[name="iva_pct"]').val(),
            redi_cost_pct: $('input[name="redi_cost_pct"]').val(),
            round_multiple: $('select[name="round_multiple"]').val()
        }).done(function(resp){
            $btn.prop('disabled', false).html('💾 Guardar reglas de precio');
            if (resp.success) {
                alert('✓ ' + resp.data.message);
                updatePriceExample();
            } else {
                alert('Error: ' + (resp.data.message || resp.data));
            }
        }).fail(function(){
            $btn.prop('disabled', false).html('💾 Guardar reglas de precio');
            alert('Error de red.');
        });
    });

    // Guardar SEO
    $('#ltms-posgold-seo-form').on('submit', function(e){
        e.preventDefault();
        var $btn = $(this).find('button[type="submit"]');
        $btn.prop('disabled', true).text('Guardando...');

        $.post(ajaxUrl, {
            action: 'ltms_save_posgold_seo',
            nonce: nonce,
            seo_template: $('#ltms-posgold-seo-template').val()
        }).done(function(resp){
            $btn.prop('disabled', false).html('💾 Guardar plantilla SEO');
            if (resp.success) {
                alert('✓ ' + resp.data.message);
                updateSeoPreview();
            } else {
                alert('Error: ' + (resp.data.message || resp.data));
            }
        }).fail(function(){
            $btn.prop('disabled', false).html('💾 Guardar plantilla SEO');
            alert('Error de red.');
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
            alert('Error de red.');
        });
    });

    // Sincronizar productos
    $('#ltms-posgold-sync-btn').on('click', function(){
        var $btn = $(this);
        var $result = $('#ltms-posgold-sync-result');

        if (!confirm('¿Sincronizar tu catálogo de PosGold ahora?\n\nSe aplicarán las reglas configuradas:\n- Filtro de categorías\n- Cálculo de precio\n- SEO en títulos\n- Depuración de duplicados\n\nEsto puede tardar varios minutos.')) {
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
                setTimeout(function(){ window.location.reload(); }, 6000);
            } else {
                $result.html('<div style="padding:16px;background:#fee2e2;border-radius:8px;color:#991b1b;">✗ ' + (resp.data.message || resp.data) + '</div>').show();
            }
        }).fail(function(){
            $btn.prop('disabled', false).html('🔄 Sincronizar ahora');
            $result.html('<div style="padding:16px;background:#fee2e2;border-radius:8px;color:#991b1b;">✗ Error de red.</div>').show();
        });
    });

    // Update price example
    function updatePriceExample() {
        var cost = 50000;
        var transport = parseFloat($('input[name="transport_pct"]').val()) || 0;
        var advertising = parseFloat($('input[name="advertising_pct"]').val()) || 0;
        var returns = parseFloat($('input[name="returns_pct"]').val()) || 0;
        var margin = parseFloat($('input[name="margin_pct"]').val()) || 0;
        var commission = parseFloat($('input[name="lotengo_commission_pct"]').val()) || 0;
        var iva = parseFloat($('select[name="iva_pct"]').val()) || 0;
        var redi = $('#ltms-posgold-is-redi').is(':checked') ? (parseFloat($('input[name="redi_cost_pct"]').val()) || 0) : 0;
        var round = parseInt($('select[name="round_multiple"]').val()) || 1000;

        var t = cost * transport / 100;
        var a = cost * advertising / 100;
        var r = cost * redi / 100;
        var sub1 = cost + t + a + r;
        var m = sub1 * margin / 100;
        var sub2 = sub1 + m;
        var c = commission > 0 ? (sub2 / (1 - commission/100) - sub2) : 0;
        var pw = sub2 + c;
        var ret = pw * returns / 100;
        var base = pw + ret;
        var iv = base * iva / 100;
        var final = base + iv;
        var rounded = Math.ceil(final / round) * round;

        var html = 'Costo: $' + cost.toLocaleString() + '<br>';
        html += '+ Transporte (' + transport + '%): $' + Math.round(t).toLocaleString() + '<br>';
        html += '+ Publicidad (' + advertising + '%): $' + Math.round(a).toLocaleString() + '<br>';
        if (redi > 0) { html += '+ ReDi (' + redi + '%): $' + Math.round(r).toLocaleString() + '<br>'; }
        html += '= Subtotal gastos: $' + Math.round(sub1).toLocaleString() + '<br>';
        html += '+ Margen (' + margin + '%): $' + Math.round(m).toLocaleString() + '<br>';
        html += '+ Comisión LT (' + commission + '%): $' + Math.round(c).toLocaleString() + '<br>';
        html += '+ Devoluciones (' + returns + '%): $' + Math.round(ret).toLocaleString() + '<br>';
        html += '+ IVA (' + iva + '%): $' + Math.round(iv).toLocaleString() + '<br>';
        html += '<strong>= Precio final: $' + Math.round(final).toLocaleString() + '</strong><br>';
        html += '<strong style="color:#16a34a;">→ Precio redondeado: $' + rounded.toLocaleString() + '</strong>';

        $('#ltms-posgold-price-example').html(html);
    }

    // Update SEO preview
    function updateSeoPreview() {
        var template = $('#ltms-posgold-seo-template').val() || '{nombre} {marca} {categoria}';
        var preview = template
            .replace('{nombre}', 'Monopoly Clásico')
            .replace('{marca}', 'Hasbro')
            .replace('{categoria}', 'Juegos de Mesa')
            .replace('{modelo}', 'Monopoly-001')
            .replace('{codigo}', 'ABC123')
            .replace(/\s+/g, ' ')
            .trim();
        $('#ltms-posgold-seo-preview').text(preview);
    }

    // Live updates on input change
    $('#ltms-posgold-rules-form input, #ltms-posgold-rules-form select, #ltms-posgold-is-redi').on('input change', updatePriceExample);
    $('#ltms-posgold-seo-template').on('input', updateSeoPreview);

    // Initial render
    updatePriceExample();
    updateSeoPreview();

})(jQuery);
</script>
