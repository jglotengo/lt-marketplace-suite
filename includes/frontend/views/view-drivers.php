<?php
/**
 * Vista Vendor — Mis Domiciliarios (Flota propia).
 *
 * Permite al vendedor gestionar su flota de domiciliarios:
 *  - KPIs: total repartidores, activos, disponibles ahora.
 *  - Búsqueda por nombre / teléfono / placa.
 *  - Filtro por estado y tipo de vehículo.
 *  - CRUD: agregar, editar, activar/desactivar, disponible/ocupado, eliminar.
 *  - Configuración de entrega propia (precio, ETA, zonas, mensaje).
 *
 * @package    LTMS
 * @subpackage LTMS/includes/frontend/views
 * @version    2.9.97
 */

defined( 'ABSPATH' ) || exit;

$vendor_id = get_current_user_id();
if ( ! $vendor_id ) {
    return;
}

global $wpdb;
$table = $wpdb->prefix . 'lt_vendor_drivers';

// ── Lectura de repartidores del vendedor ───────────────────────────
$drivers = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->prepare(
        "SELECT id, full_name, phone, vehicle_type, vehicle_plate, status, created_at
         FROM `{$table}` WHERE vendor_id = %d ORDER BY full_name ASC",
        $vendor_id
    ),
    ARRAY_A
);

// ── KPIs ───────────────────────────────────────────────────────────
$total_drivers  = count( $drivers );
$active_drivers = 0;
$available_now  = 0;
foreach ( $drivers as $d ) {
    if ( ( $d['status'] ?? '' ) === 'active' ) {
        $active_drivers++;
        if ( get_transient( 'ltms_driver_available_' . $d['id'] ) ) {
            $available_now++;
        }
    }
}

// ── Configuración de entrega ──────────────────────────────────────
$delivery_price   = (float) get_user_meta( $vendor_id, 'ltms_own_delivery_price', true );
$delivery_eta_min = (int) get_user_meta( $vendor_id, 'ltms_own_delivery_eta_minutes', true );
$delivery_zones   = (string) get_user_meta( $vendor_id, 'ltms_own_delivery_zones', true );
$delivery_message = (string) get_user_meta( $vendor_id, 'ltms_own_delivery_message', true );

// ── Etiquetas localizadas ─────────────────────────────────────────
$vehicle_labels = [
    'bicycle' => __( 'Bicicleta', 'ltms' ),
    'bici'    => __( 'Bicicleta', 'ltms' ),
    'moto'    => __( 'Moto', 'ltms' ),
    'car'     => __( 'Carro', 'ltms' ),
    'carro'   => __( 'Carro', 'ltms' ),
    'walking' => __( 'A pie', 'ltms' ),
    'pie'     => __( 'A pie', 'ltms' ),
];

$vehicle_icons = [
    'bicycle' => '🚲',
    'bici'    => '🚲',
    'moto'    => '🏍️',
    'car'     => '🚗',
    'carro'   => '🚗',
    'walking' => '🚶',
    'pie'     => '🚶',
];

$currency = class_exists( 'LTMS_Core_Config' ) ? LTMS_Core_Config::get_currency() : 'COP';
?>

<section class="ltms-view ltms-view-drivers">
    <div class="ltms-view-header">
        <h2><?php esc_html_e( 'Mis Domiciliarios', 'ltms' ); ?></h2>
        <p class="ltms-view-desc">
            <?php esc_html_e( 'Gestiona tu flota de repartidores. El método "Domiciliario propio" aparecerá en el checkout solo cuando tengas al menos un repartidor activo.', 'ltms' ); ?>
        </p>
    </div>

    <!-- ── KPIs ──────────────────────────────────────────────────── -->
    <div class="ltms-stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:24px;">
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Total repartidores', 'ltms' ); ?></span>
            <span class="ltms-stat-value"><?php echo esc_html( number_format( $total_drivers ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Activos', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="color:#16a34a;"><?php echo esc_html( number_format( $active_drivers ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Disponibles ahora', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="color:#2563eb;"><?php echo esc_html( number_format( $available_now ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Método activo', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="font-size:0.95rem;color:<?php echo ( $active_drivers > 0 ) ? '#16a34a' : '#dc2626'; ?>;">
                <?php echo ( $active_drivers > 0 ) ? esc_html__( 'Habilitado', 'ltms' ) : esc_html__( 'Deshabilitado', 'ltms' ); ?>
            </span>
        </div>
    </div>

    <!-- ── Configuración general de entrega ─────────────────────── -->
    <div class="ltms-card ltms-card-delivery-config" style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-bottom:24px;">
        <h3 style="margin-top:0;"><?php esc_html_e( 'Configuración de Entrega', 'ltms' ); ?></h3>
        <form id="ltms-delivery-settings-form" class="ltms-form">
            <?php wp_nonce_field( 'ltms_dashboard_nonce', 'nonce' ); ?>
            <input type="hidden" name="action" value="ltms_save_delivery_settings">

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;">
                <div class="ltms-form-row">
                    <label for="ltms-delivery-price"><?php esc_html_e( 'Precio domicilio (COP)', 'ltms' ); ?></label>
                    <input type="number" id="ltms-delivery-price" name="delivery_price"
                               min="0" step="100"
                               value="<?php echo esc_attr( $delivery_price > 0 ? $delivery_price : '' ); ?>"
                               placeholder="0">
                    <p class="ltms-field-hint"><?php esc_html_e( '0 = envío gratuito.', 'ltms' ); ?></p>
                </div>

                <div class="ltms-form-row">
                    <label for="ltms-delivery-eta"><?php esc_html_e( 'Tiempo estimado (min)', 'ltms' ); ?></label>
                    <input type="number" id="ltms-delivery-eta" name="delivery_eta_minutes"
                               min="1" max="480" step="5"
                               value="<?php echo esc_attr( $delivery_eta_min > 0 ? $delivery_eta_min : 60 ); ?>">
                </div>

                <div class="ltms-form-row">
                    <label for="ltms-delivery-zones"><?php esc_html_e( 'Zonas de cobertura', 'ltms' ); ?></label>
                    <input type="text" id="ltms-delivery-zones" name="delivery_zones"
                               value="<?php echo esc_attr( $delivery_zones ); ?>"
                               placeholder="<?php esc_attr_e( 'Ej: Chapinero, Usaquén, Suba', 'ltms' ); ?>"
                               maxlength="500">
                </div>

                <div class="ltms-form-row">
                    <label for="ltms-delivery-message"><?php esc_html_e( 'Mensaje al cliente', 'ltms' ); ?></label>
                    <input type="text" id="ltms-delivery-message" name="delivery_message"
                               value="<?php echo esc_attr( $delivery_message ); ?>"
                               placeholder="<?php esc_attr_e( 'Ej: Solo Bogotá norte.', 'ltms' ); ?>"
                               maxlength="200">
                </div>
            </div>

            <div class="ltms-form-actions" style="margin-top:16px;display:flex;gap:12px;align-items:center;">
                <button type="submit" class="ltms-btn ltms-btn-primary" id="ltms-save-delivery-settings">
                    <?php esc_html_e( 'Guardar configuración', 'ltms' ); ?>
                </button>
                <span class="ltms-spinner" id="ltms-delivery-spinner" style="display:none;"></span>
                <span class="ltms-form-msg" id="ltms-delivery-msg" style="font-size:0.85rem;"></span>
            </div>
        </form>
    </div>

    <!-- ── Lista de domiciliarios ────────────────────────────────── -->
    <div class="ltms-card" style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;">
        <div class="ltms-card-header-row" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px;">
            <h3 style="margin:0;"><?php esc_html_e( 'Repartidores registrados', 'ltms' ); ?></h3>
            <button class="ltms-btn ltms-btn-secondary ltms-btn-sm" id="ltms-add-driver-btn" type="button">
                + <?php esc_html_e( 'Agregar repartidor', 'ltms' ); ?>
            </button>
        </div>

        <?php if ( empty( $drivers ) ) : ?>
            <div class="ltms-empty-state" style="text-align:center;padding:60px 24px;">
                <svg class="ltms-empty-icon" width="56" height="56" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                     style="opacity:0.3;color:#6b7280;margin-bottom:16px;">
                    <rect x="1" y="3" width="15" height="13"/>
                    <polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/>
                    <circle cx="5.5" cy="18.5" r="2.5"/>
                    <circle cx="18.5" cy="18.5" r="2.5"/>
                </svg>
                <h3 style="margin:0 0 8px;font-size:1.1rem;color:#374151;font-weight:600;">
                    <?php esc_html_e( 'Aún no tienes repartidores', 'ltms' ); ?>
                </h3>
                <p style="margin:0 auto 20px;max-width:400px;font-size:0.9rem;color:#6b7280;line-height:1.5;">
                    <?php esc_html_e( 'Agrega tu primer repartidor para habilitar la opción de domiciliario propio en el checkout.', 'ltms' ); ?>
                </p>
                <button type="button" class="ltms-btn ltms-btn-primary ltms-btn-sm" id="ltms-add-driver-btn-empty">
                    + <?php esc_html_e( 'Agregar primer repartidor', 'ltms' ); ?>
                </button>
            </div>
        <?php else : ?>
            <!-- Barra de filtros -->
            <div class="ltms-filter-bar" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:16px;">
                <div style="position:relative;flex:1;min-width:200px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                         style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#9ca3af;pointer-events:none;">
                        <circle cx="11" cy="11" r="8"/>
                        <path d="M21 21l-4.35-4.35"/>
                    </svg>
                    <input type="search" id="ltms-driver-search"
                           placeholder="<?php esc_attr_e( 'Buscar por nombre, teléfono o placa...', 'ltms' ); ?>"
                           style="width:100%;padding:8px 12px 8px 34px;border:1px solid #d1d5db;border-radius:8px;font-size:0.9rem;">
                </div>
                <select id="ltms-driver-status-filter"
                        style="padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:0.9rem;">
                    <option value=""><?php esc_html_e( 'Todos los estados', 'ltms' ); ?></option>
                    <option value="active"><?php esc_html_e( 'Activos', 'ltms' ); ?></option>
                    <option value="inactive"><?php esc_html_e( 'Inactivos', 'ltms' ); ?></option>
                </select>
                <select id="ltms-driver-vehicle-filter"
                        style="padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:0.9rem;">
                    <option value=""><?php esc_html_e( 'Todos los vehículos', 'ltms' ); ?></option>
                    <option value="bicycle"><?php esc_html_e( 'Bicicleta', 'ltms' ); ?></option>
                    <option value="moto"><?php esc_html_e( 'Moto', 'ltms' ); ?></option>
                    <option value="car"><?php esc_html_e( 'Carro', 'ltms' ); ?></option>
                    <option value="walking"><?php esc_html_e( 'A pie', 'ltms' ); ?></option>
                </select>
            </div>

            <div class="ltms-table-responsive">
                <table class="ltms-table ltms-drivers-table" id="ltms-drivers-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Nombre', 'ltms' ); ?></th>
                            <th><?php esc_html_e( 'Teléfono', 'ltms' ); ?></th>
                            <th><?php esc_html_e( 'Vehículo', 'ltms' ); ?></th>
                            <th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
                            <th><?php esc_html_e( 'Disponible', 'ltms' ); ?></th>
                            <th><?php esc_html_e( 'Acciones', 'ltms' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $drivers as $driver ) :
                        $v_type       = $driver['vehicle_type'] ?? '';
                        $vehicle_lbl  = $vehicle_labels[ $v_type ] ?? ucfirst( $v_type );
                        $vehicle_ico  = $vehicle_icons[ $v_type ] ?? '🚚';
                        $plate        = $driver['vehicle_plate'] ?? '';
                        $is_active    = ( 'active' === ( $driver['status'] ?? '' ) );
                        $is_available = (bool) get_transient( 'ltms_driver_available_' . $driver['id'] );
                        $created_lbl  = $driver['created_at']
                            ? wp_date( 'd M Y', strtotime( $driver['created_at'] ) )
                            : '—';
                    ?>
                    <tr data-driver-id="<?php echo esc_attr( $driver['id'] ); ?>"
                        data-status="<?php echo esc_attr( $driver['status'] ?? 'active' ); ?>"
                        data-vehicle="<?php echo esc_attr( $v_type ); ?>"
                        data-search="<?php echo esc_attr( strtolower(
                            $driver['full_name'] . ' ' . $driver['phone'] . ' ' . $plate
                        ) ); ?>">
                        <td>
                            <strong><?php echo esc_html( $driver['full_name'] ); ?></strong>
                            <br><small style="color:#9ca3af;font-size:0.75rem;">
                                <?php esc_html_e( 'Desde:', 'ltms' ); ?> <?php echo esc_html( $created_lbl ); ?>
                            </small>
                        </td>
                        <td>
                            <a href="tel:<?php echo esc_attr( preg_replace( '/\D+/', '', $driver['phone'] ?? '' ) ); ?>"
                               style="color:#2563eb;">
                                <?php echo esc_html( $driver['phone'] ?: '—' ); ?>
                            </a>
                        </td>
                        <td>
                            <span style="display:inline-flex;align-items:center;gap:6px;">
                                <span aria-hidden="true"><?php echo esc_html( $vehicle_ico ); ?></span>
                                <?php echo esc_html( $vehicle_lbl ); ?>
                                <?php if ( $plate ) : ?>
                                    <code style="font-size:0.75em;color:#6b7280;background:#f3f4f6;padding:1px 5px;border-radius:3px;">
                                        <?php echo esc_html( $plate ); ?>
                                    </code>
                                <?php endif; ?>
                            </span>
                        </td>
                        <td>
                            <span class="ltms-status-badge <?php echo $is_active ? 'delivered' : 'failed'; ?>">
                                <?php echo $is_active ? esc_html__( 'Activo', 'ltms' ) : esc_html__( 'Inactivo', 'ltms' ); ?>
                            </span>
                        </td>
                        <td>
                            <span class="ltms-status-badge <?php echo $is_available ? 'paid' : 'pending'; ?>">
                                <?php echo $is_available ? esc_html__( 'Disponible', 'ltms' ) : esc_html__( 'Ocupado', 'ltms' ); ?>
                            </span>
                        </td>
                        <td class="ltms-driver-actions" style="white-space:nowrap;">
                            <button class="ltms-btn ltms-btn-link ltms-btn-sm ltms-driver-edit"
                                    data-driver-id="<?php echo esc_attr( $driver['id'] ); ?>"
                                    data-name="<?php echo esc_attr( $driver['full_name'] ); ?>"
                                    data-phone="<?php echo esc_attr( $driver['phone'] ); ?>"
                                    data-vehicle="<?php echo esc_attr( $v_type ); ?>"
                                    data-plate="<?php echo esc_attr( $plate ); ?>"
                                    type="button">
                                ✏️ <?php esc_html_e( 'Editar', 'ltms' ); ?>
                            </button>
                            <button class="ltms-btn ltms-btn-link ltms-btn-sm ltms-driver-toggle-active"
                                            data-driver-id="<?php echo esc_attr( $driver['id'] ); ?>"
                                            data-active="<?php echo esc_attr( $is_active ? 1 : 0 ); ?>"
                                            type="button">
                                <?php echo $is_active ? esc_html__( 'Desactivar', 'ltms' ) : esc_html__( 'Activar', 'ltms' ); ?>
                            </button>
                            <button class="ltms-btn ltms-btn-link ltms-btn-sm ltms-driver-toggle-available"
                                            data-driver-id="<?php echo esc_attr( $driver['id'] ); ?>"
                                            data-available="<?php echo esc_attr( $is_available ? 1 : 0 ); ?>"
                                            type="button">
                                <?php echo $is_available ? esc_html__( 'Marcar ocupado', 'ltms' ) : esc_html__( 'Marcar disponible', 'ltms' ); ?>
                            </button>
                            <button class="ltms-btn ltms-btn-link ltms-btn-sm ltms-btn-danger-link ltms-driver-delete"
                                            data-driver-id="<?php echo esc_attr( $driver['id'] ); ?>"
                                            data-name="<?php echo esc_attr( $driver['full_name'] ); ?>"
                                            type="button"
                                            style="color:#dc2626;">
                                🗑️ <?php esc_html_e( 'Eliminar', 'ltms' ); ?>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <p style="margin-top:12px;font-size:0.8rem;color:#6b7280;">
                <?php
                printf(
                    /* translators: %d: número de repartidores */
                    esc_html__( 'Mostrando %d repartidores.', 'ltms' ),
                    count( $drivers )
                );
                ?>
            </p>
        <?php endif; ?>
    </div>

    <!-- ── Modal: Agregar / Editar repartidor ────────────────────── -->
    <div id="ltms-driver-modal" class="ltms-modal" style="display:none;" role="dialog"
         aria-modal="true" aria-labelledby="ltms-driver-modal-title">
        <div class="ltms-modal-overlay" data-modal-close></div>
        <div class="ltms-modal-content" style="background:#fff;border-radius:8px;max-width:480px;width:90%;margin:5vh auto;padding:24px;position:relative;">
            <button type="button" class="ltms-modal-close-btn" data-modal-close
                    aria-label="<?php esc_attr_e( 'Cerrar', 'ltms' ); ?>"
                    style="position:absolute;top:12px;right:12px;background:none;border:none;font-size:1.4rem;cursor:pointer;color:#6b7280;line-height:1;">&times;</button>
            <h3 class="ltms-modal-title" id="ltms-driver-modal-title" style="margin-top:0;">
                <?php esc_html_e( 'Agregar repartidor', 'ltms' ); ?>
            </h3>
            <form id="ltms-driver-form" class="ltms-form" novalidate>
                <?php wp_nonce_field( 'ltms_dashboard_nonce', 'nonce' ); ?>
                <input type="hidden" name="action" value="ltms_save_driver">
                <input type="hidden" name="driver_id" value="0">

                <div class="ltms-form-row" style="margin-bottom:12px;">
                    <label for="ltms-driver-name"><?php esc_html_e( 'Nombre completo *', 'ltms' ); ?></label>
                    <input type="text" id="ltms-driver-name" name="driver_name" required maxlength="200"
                           style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;">
                </div>

                <div class="ltms-form-row" style="margin-bottom:12px;">
                    <label for="ltms-driver-phone"><?php esc_html_e( 'Teléfono *', 'ltms' ); ?></label>
                    <input type="tel" id="ltms-driver-phone" name="driver_phone" required maxlength="20"
                           style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;">
                </div>

                <div class="ltms-form-row" style="margin-bottom:12px;">
                    <label for="ltms-driver-doc"><?php esc_html_e( 'N.º Documento *', 'ltms' ); ?></label>
                    <input type="text" id="ltms-driver-doc" name="driver_document_number" required maxlength="20"
                           style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;">
                    <p class="ltms-field-hint" style="font-size:0.78rem;color:#6b7280;margin:4px 0 0;">
                        <?php esc_html_e( 'Se almacena cifrado (AES-256).', 'ltms' ); ?>
                    </p>
                </div>

                <div class="ltms-form-row" style="margin-bottom:12px;">
                    <label for="ltms-driver-vehicle"><?php esc_html_e( 'Tipo de vehículo *', 'ltms' ); ?></label>
                    <select id="ltms-driver-vehicle" name="driver_vehicle_type" required
                            style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;">
                        <option value=""><?php esc_html_e( 'Selecciona...', 'ltms' ); ?></option>
                        <option value="bicycle"><?php esc_html_e( 'Bicicleta', 'ltms' ); ?></option>
                        <option value="moto"><?php esc_html_e( 'Moto', 'ltms' ); ?></option>
                        <option value="car"><?php esc_html_e( 'Carro', 'ltms' ); ?></option>
                        <option value="walking"><?php esc_html_e( 'A pie', 'ltms' ); ?></option>
                    </select>
                </div>

                <div class="ltms-form-row" style="margin-bottom:16px;">
                    <label for="ltms-driver-plate"><?php esc_html_e( 'Placa del vehículo', 'ltms' ); ?></label>
                    <input type="text" id="ltms-driver-plate" name="driver_vehicle_plate" maxlength="10"
                               placeholder="<?php esc_attr_e( 'Ej: ABC123', 'ltms' ); ?>"
                               style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;text-transform:uppercase;">
                    <p class="ltms-field-hint" style="font-size:0.78rem;color:#6b7280;margin:4px 0 0;">
                        <?php esc_html_e( 'Opcional para bicicletas y peatones.', 'ltms' ); ?>
                    </p>
                </div>

                <div class="ltms-modal-footer" style="display:flex;gap:8px;justify-content:flex-end;">
                    <button type="submit" class="ltms-btn ltms-btn-primary">
                        <?php esc_html_e( 'Guardar', 'ltms' ); ?>
                    </button>
                    <button type="button" class="ltms-btn ltms-btn-secondary" data-modal-close>
                        <?php esc_html_e( 'Cancelar', 'ltms' ); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Modal: Confirmar eliminación ─────────────────────────── -->
    <div id="ltms-driver-delete-modal" class="ltms-modal" style="display:none;" role="dialog"
         aria-modal="true" aria-labelledby="ltms-driver-delete-title">
        <div class="ltms-modal-overlay" data-delete-modal-close></div>
        <div class="ltms-modal-content" style="background:#fff;border-radius:8px;max-width:400px;width:90%;margin:10vh auto;padding:24px;position:relative;">
            <h3 id="ltms-driver-delete-title" style="margin-top:0;color:#dc2626;">
                <?php esc_html_e( 'Eliminar repartidor', 'ltms' ); ?>
            </h3>
            <p style="color:#4b5563;line-height:1.5;">
                <?php
                printf(
                    /* translators: %s: nombre del repartidor */
                    esc_html__( '¿Seguro que deseas eliminar a "%s"? Esta acción no se puede deshacer.', 'ltms' ),
                    '<strong id="ltms-delete-driver-name">—</strong>'
                );
                ?>
            </p>
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:20px;">
                <button type="button" class="ltms-btn ltms-btn-secondary" data-delete-modal-close>
                    <?php esc_html_e( 'Cancelar', 'ltms' ); ?>
                </button>
                <button type="button" class="ltms-btn ltms-btn-danger" id="ltms-confirm-delete-driver"
                        style="background:#dc2626;color:#fff;border:none;padding:8px 16px;border-radius:6px;cursor:pointer;">
                    <?php esc_html_e( 'Sí, eliminar', 'ltms' ); ?>
                </button>
            </div>
        </div>
    </div>

</section>

