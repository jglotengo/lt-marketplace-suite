<?php
/**
 * Template: Order Tracking — Plaza Viva Design System
 *
 * Página pública de seguimiento de orden con timeline vertical Rappi-style.
 * Reemplaza al template por defecto vía `template_include` (ver
 * LTMS_Native_Templates::maybe_override() — integra order-tracking.php
 * cuando is_page('seguimiento') || is_page('tracking') o query var
 * ltms_page = 'tracking').
 *
 * Características:
 *  - Header con número de orden, fecha y total.
 *  - Timeline vertical 5 pasos (Confirmado → En preparación → Enviado →
 *    En camino → Entregado) Rappi-style con animación.
 *  - ETA card con fecha estimada de entrega.
 *  - Repartidor card con foto, nombre, rating, botones llamar/chat.
 *  - Order summary colapsable (items + totales).
 *  - Botón "Reportar problema".
 *  - Acceso verificado: customer_id coincide o order_key válida.
 *
 * Metas usadas:
 *  - _ltms_preparing_at        (ts preparación)
 *  - _ltms_shipped_at          (ts envío)
 *  - _ltms_in_transit_at       (ts en camino)
 *  - _ltms_estimated_delivery  (ts ETA)
 *  - _ltms_driver_name         (string)
 *  - _ltms_driver_phone        (string)
 *  - _ltms_driver_rating       (float)
 *  - _ltms_driver_photo        (string URL)
 *  - _ltms_tracking_number     (string)
 *  - _ltms_carrier             (string)
 *
 * @package LTMS
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Salida directa no permitida.
}

// Garantizar WooCommerce cargado.
if ( ! function_exists( 'wc_get_order' ) ) {
    get_header();
    echo '<div class="pv-scope pv-tracking"><main class="pv-section pv-fallback__section"><p class="pv-fallback__msg">' . esc_html__( 'WooCommerce no está activo. El seguimiento requiere WooCommerce.', 'ltms' ) . '</p></main></div>';
    get_footer();
    return;
}

/* ---------------------------------------------------------------------------
 * 1. Resolver order_id desde $_GET (order_id | order) y verificar acceso.
 * ------------------------------------------------------------------------- */
$pv_request_order_id = 0;
if ( isset( $_GET['order_id'] ) ) {
    $pv_request_order_id = absint( $_GET['order_id'] );
} elseif ( isset( $_GET['order'] ) ) {
    $pv_request_order_id = absint( $_GET['order'] );
}

$pv_request_key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';

$order = $pv_request_order_id ? wc_get_order( $pv_request_order_id ) : false;

$access_denied_reason = '';

if ( ! $order ) {
    $access_denied_reason = __( 'No encontramos la orden solicitada. Verifica el enlace o contáctanos.', 'ltms' );
} else {
    $order_customer_id = (int) $order->get_customer_id();
    $current_user_id   = (int) get_current_user_id();
    $order_key         = $order->get_order_key(); // "wc_order_xxxx".

    $access_granted = false;

    // Caso 1: usuario logueado y es dueño de la orden.
    if ( $current_user_id > 0 && $order_customer_id === $current_user_id ) {
        $access_granted = true;
    }

    // Caso 2: order_key válida (enlaces guest desde email). Comparación estricta
    // contra el real order_key con hash_equals (timing-safe). Sin fallback truthy.
    // AUDIT-FE-OT-001 FIX: Caso 3 previo aceptaba CUALQUIER string truthy en
    // ?key= para órdenes guest → IDOR de todas las órdenes guest. Eliminado.
    if ( ! $access_granted && $pv_request_key ) {
        $key_clean       = str_replace( 'wc_order_', '', $pv_request_key );
        $order_key_clean = str_replace( 'wc_order_', '', $order_key );
        if ( hash_equals( $order_key, $pv_request_key ) || hash_equals( $order_key_clean, $key_clean ) ) {
            $access_granted = true;
        }
    }

    /**
     * Filter: ltms_order_tracking_access
     * Permite a terceros (módulos de soporte, repartidores) verificar acceso.
     */
    $access_granted = (bool) apply_filters( 'ltms_order_tracking_access', $access_granted, $order );

    if ( ! $access_granted ) {
        $access_denied_reason = __( 'No tienes permiso para ver el seguimiento de esta orden. Inicia sesión con la cuenta asociada o usa el enlace desde tu correo.', 'ltms' );
    }
}

/* ---------------------------------------------------------------------------
 * 2. Si no hay acceso, mostrar bloque de acceso denegado / búsqueda.
 * ------------------------------------------------------------------------- */
if ( $access_denied_reason ) {
    get_header();
    ?>
    <div class="pv-scope pv-tracking">
        <main class="pv-section pv-tracking__main pv-tracking__main--error" role="main">
            <div class="pv-card pv-tracking__error-card">
                <div class="pv-tracking__error-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="56" height="56" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="10"/><path d="M12 8v5M12 16h.01" stroke-linecap="round"/></svg>
                </div>
                <h1 class="pv-tracking__error-title"><?php esc_html_e( 'Orden no encontrada', 'ltms' ); ?></h1>
                <p class="pv-tracking__error-sub"><?php echo esc_html( $access_denied_reason ); ?></p>
                <form class="pv-tracking__search-form" method="get" action="<?php echo esc_url( home_url( '/seguimiento' ) ); ?>">
                    <label for="pv-tracking-search" class="pv-visually-hidden"><?php esc_html_e( 'Número de orden o correo', 'ltms' ); ?></label>
                    <div class="pv-input-group">
                        <input type="text" id="pv-tracking-search" name="order_id" class="pv-input" placeholder="<?php esc_attr_e( 'Ingresa tu número de orden', 'ltms' ); ?>" />
                        <button type="submit" class="pv-btn"><?php esc_html_e( 'Buscar', 'ltms' ); ?></button>
                    </div>
                </form>
                <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="pv-btn pv-btn--ghost pv-btn--sm"><?php esc_html_e( 'Volver al inicio', 'ltms' ); ?></a>
            </div>
        </main>
    </div>
    <?php
    get_footer();
    return;
}

/* ---------------------------------------------------------------------------
 * 3. Datos de la orden y estados del timeline.
 *
 * AUDIT-FE-OT-002 FIX (Fase 1.8): el timeline previo leía metas
 * _ltms_preparing_at/_ltms_shipped_at/_ltms_in_transit_at/_ltms_driver_* que
 * NADIE escribía en el plugin → el timeline siempre quedaba pegado en step 0,
 * repartidor siempre "Por asignar", ETA siempre "Por confirmar", engañando al
 * usuario con UI cosmética estática. Las metas se conservan como OPCIONALES
 * (si un módulo externo las llena, se usan), pero la fuente primaria pasa a
 * ser el estado nativo de WC + metas reales que SÍ se escriben en el plugin
 * (_ltms_shipping_delivered_at/_ltms_delivered_at escritos por Aveonline,
 * Deprisa, Uber Direct, Own-Delivery y el Core cron manager) + el carrier
 * tracking_number (escrito por Deprisa/Aveonline cuando generan guía).
 * ------------------------------------------------------------------------- */
$order_id       = (int) $order->get_id();
$order_number   = $order->get_order_number();
$order_status   = $order->get_status(); // e.g. "processing", "completed".
$order_total    = $order->get_total();
$order_total_fm = wp_kses_post( wc_price( $order_total, array( 'currency' => $order->get_currency() ) ) );
$order_date     = $order->get_date_created();
$order_date_fm  = $order_date ? $order_date->date_i18n( __( 'j \d\e F, Y \a \l\a\s H:i', 'ltms' ) ) : '';
$order_modified = $order->get_date_modified();

// Tracking number y carrier (escritos por Deprisa/Aveonline al generar guía).
$tracking_number = (string) $order->get_meta( '_ltms_tracking_number' );
$carrier         = (string) $order->get_meta( '_ltms_carrier' );

// ¿La orden usa carrier externo o entrega propia/pickup?
$shipping_methods = $order->get_shipping_methods();
$uses_own_delivery = false;
$uses_pickup       = false;
foreach ( $shipping_methods as $sm ) {
    $mid = $sm->get_method_id();
    if ( 'ltms_own_delivery' === $mid ) { $uses_own_delivery = true; }
    if ( 'ltms_pickup'        === $mid ) { $uses_pickup       = true; }
}

// Fechas de cada etapa del timeline.
$date_confirmed   = $order->get_date_created();
$date_preparing   = (int) $order->get_meta( '_ltms_preparing_at' );
$date_shipped     = (int) $order->get_meta( '_ltms_shipped_at' );
$date_in_transit  = (int) $order->get_meta( '_ltms_in_transit_at' );
$date_delivered   = $order->get_date_completed();
$eta_ts           = (int) $order->get_meta( '_ltms_estimated_delivery' );

// AUDIT-FE-OT-002 fallbacks desde metas reales escritas por el plugin:
// _ltms_delivered_at (Deprisa tracking-cron), _ltms_shipping_delivered_at
// (Core cron manager TS-BUG-1) y _ltms_shipping_delivered_fired (idempotencia).
$delivered_fired = (bool) $order->get_meta( '_ltms_shipping_delivered_fired' );
$delivered_ts    = (int) $order->get_meta( '_ltms_shipping_delivered_at' );
if ( ! $delivered_ts ) {
    $raw = $order->get_meta( '_ltms_delivered_at' );
    if ( $raw ) {
        try {
            $dt             = new WC_DateTime( $raw );
            $delivered_ts   = (int) $dt->getTimestamp();
        } catch ( Throwable $e ) {
            $delivered_ts = 0;
        }
    }
}
if ( $delivered_ts > 0 ) {
    $date_delivered = $date_delivered ?: new WC_DateTime( '@' . $delivered_ts );
    $date_delivered_ts_for_meta = $delivered_ts;
} else {
    $date_delivered_ts_for_meta = $date_delivered ? $date_delivered->getTimestamp() : 0;
}

// Fallback de preparación: si status=processing y no hay meta, inferir desde
// date_modified (cuando WC cambió a processing marca la preparación).
if ( $date_preparing <= 0 && 'processing' === $order_status && $order_modified ) {
    $date_preparing = (int) $order_modified->getTimestamp();
}

// Fallback de "shipped": si tracking_number set + status avanzado desde
// processing, inferir desde date_modified de la última transición relevante.
if ( $date_shipped <= 0 && $tracking_number && $order_modified && in_array( $order_status, array( 'completed', 'in_transit', 'shipped' ), true ) ) {
    $date_shipped = (int) $order_modified->getTimestamp();
}

// Fallback de entregado: usar _ltms_shipping_delivered_at / _ltms_delivered_at
// si existen (escritos por Deprisa/Aveonline/Uber Direct/Core cron), si no
// date_completed nativa de WC.
if ( $delivered_ts > 0 && ( ! $date_delivered || $delivered_ts > $date_delivered->getTimestamp() ) ) {
    try {
        $date_delivered = new WC_DateTime( '@' . $delivered_ts );
    } catch ( Throwable $e ) {
        // Mantener el date_delivered nativo si falla el cast.
    }
}

// Indicador "delivered" claro para el paso 4: cualquiera de los tres.
$is_actually_delivered = $delivered_fired || 'completed' === $order_status || $date_delivered_ts_for_meta > 0;

// Repartidor — metas opcionales. Si la orden usa carrier externo sin
// _ltms_driver_*, mostramos estado "Asignado por transportadora" (más
// honesto que "Por asignar").
$driver_name   = (string) $order->get_meta( '_ltms_driver_name' );
$driver_phone  = (string) $order->get_meta( '_ltms_driver_phone' );
$driver_rating = (float) $order->get_meta( '_ltms_driver_rating' );
$driver_photo  = (string) $order->get_meta( '_ltms_driver_photo' );

/**
 * Filter: ltms_tracking_timeline_steps
 * Permite personalizar los pasos del timeline.
 */
$timeline_steps = apply_filters( 'ltms_tracking_timeline_steps', array(
    array(
        'key'     => 'confirmed',
        'label'   => __( 'Pedido confirmado', 'ltms' ),
        'desc'    => __( 'Hemos recibido tu pedido y el pago está en custodia.', 'ltms' ),
        'icon'    => 'check',
        'date_ts' => $date_confirmed ? $date_confirmed->getTimestamp() : 0,
    ),
    array(
        'key'     => 'preparing',
        'label'   => __( 'En preparación', 'ltms' ),
        'desc'    => __( 'El vendedor está preparando tu pedido para envío.', 'ltms' ),
        'icon'    => 'box',
        'date_ts' => $date_preparing,
    ),
    array(
        'key'     => 'shipped',
        'label'   => __( 'Enviado', 'ltms' ),
        'desc'    => $tracking_number
            ? sprintf( __( 'Pedido enviado. Número de seguimiento: %s', 'ltms' ), $tracking_number )
            : __( 'Tu pedido ha sido enviado por el vendedor.', 'ltms' ),
        'icon'    => 'truck',
        'date_ts' => $date_shipped,
    ),
    array(
        'key'     => 'in_transit',
        'label'   => __( 'En camino', 'ltms' ),
        'desc'    => $carrier
            ? sprintf( __( 'Transportadora: %s. El repartidor va en camino.', 'ltms' ), strtoupper( $carrier ) )
            : __( 'Tu pedido va en camino a tu dirección de entrega.', 'ltms' ),
        'icon'    => 'route',
        'date_ts' => $date_in_transit,
    ),
    array(
        'key'     => 'delivered',
        'label'   => __( 'Entregado', 'ltms' ),
        'desc'    => __( 'Confirma la recepción para liberar el pago al vendedor.', 'ltms' ),
        'icon'    => 'home',
        'date_ts' => $date_delivered ? $date_delivered->getTimestamp() : 0,
    ),
) );

/* ---------------------------------------------------------------------------
 * 4. Calcular el paso actual y completados.
 *
 * AUDIT-FE-OT-002 FIX: la fuente de verdad primaria del paso actual es el
 * status nativo de WC, con override hacia delante cuando hay tracking_number
 * set / meta delivered fireada. Elimina el bug del timeline siempre en step 0
 * cuando las metas _ltms_* (nunca escritas) no aportan nada.
 * ------------------------------------------------------------------------- */
$current_step_idx = 0;

// Snapshot de status nativos WC → step del timeline.
$status_to_step = array(
    'pending'    => 0,
    'processing' => 1,
    'on-hold'    => 0,
    'completed'  => 4,
);
$current_step_idx = $status_to_step[ $order_status ] ?? 1; // Default 1 (preparación) si status custom/avanzado.

// Avanzar a "shipped" si ya hay tracking_number + carrier set
// (carrier externo escupe tracking_number al generar guía).
if ( $tracking_number && $current_step_idx < 2 ) {
    $current_step_idx = 2;
}

// Avanzar a "in_transit" si status nativo = 'shipped' (custom LTMS) o hay meta.
if ( ( 'shipped' === $order_status || $date_in_transit > 0 ) && $current_step_idx < 3 ) {
    $current_step_idx = 3;
}

// Avanzar a "delivered" con cualquiera de los 3 indicadores reales.
if ( $is_actually_delivered ) {
    $current_step_idx = 4;
}

// Override: si el loop anterior ya encontró date_ts en un step superior, lo usamos.
foreach ( $timeline_steps as $i => $step ) {
    if ( $step['date_ts'] > 0 && $i > $current_step_idx ) {
        $current_step_idx = $i;
    }
}

// Ajuste por status de WC si no hay metas.
if ( 'completed' === $order_status && $date_delivered ) {
    $current_step_idx = 4;
} elseif ( 'cancelled' === $order_status || 'refunded' === $order_status ) {
    $current_step_idx = -1; // Estado especial: cancelado.
}

$is_cancelled = in_array( $order_status, array( 'cancelled', 'refunded', 'failed' ), true );

// ETA format.
$eta_fm = '';
if ( $eta_ts > 0 ) {
    $eta_fm = date_i18n( __( 'l, j \d\e F \d\e Y', 'ltms' ), $eta_ts );
}
$eta_time_fm = '';
if ( $eta_ts > 0 ) {
    $eta_time_fm = date_i18n( __( 'H:i', 'ltms' ), $eta_ts );
}

// Tiempo restante a ETA.
$eta_remaining = $eta_ts > 0 ? human_time_diff( time(), $eta_ts ) : '';

/**
 * Filter: ltms_tracking_carrier_url
 * Permite generar URL externa de seguimiento por carrier.
 */
$carrier_tracking_url = apply_filters( 'ltms_tracking_carrier_url', '', $carrier, $tracking_number );

/**
 * Hook: ltms_before_tracking_plazaviva
 */
do_action( 'ltms_before_tracking_plazaviva', $order );

get_header();
?>

<?php
/**
 * AUDIT-FE-OT-005 FIX: los behaviours JS de esta página (auto-scroll bounce
 * del paso activo del timeline, live refresh 60s con guards de interacción,
 * smooth scroll al abrir el accordion del summary) viven en
 * assets/js/ltms-plaza-viva.js — scope TRACKING (IIFE trackingScope al final
 * del archivo). Este template NO contiene JS inline (CSP-compliant); el
 * wrapper de abajo expone data-order-id y data-current-step que el scope
 * migrado lee.
 */
?>
<div class="pv-scope pv-tracking" data-order-id="<?php echo esc_attr( $order_id ); ?>" data-current-step="<?php echo esc_attr( $current_step_idx ); ?>">

    <!-- ===================================================================
         HEADER: Order info (número, fecha, total)
         =================================================================== -->
    <header class="pv-tracking__header pv-section">
        <div class="pv-tracking__header-inner">

            <div class="pv-tracking__order-info">
                <div class="pv-tracking__order-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 7h11v8H3zM14 10h4l3 3v2h-7" stroke-linecap="round" stroke-linejoin="round"/><circle cx="7" cy="18" r="1.5"/><circle cx="17" cy="18" r="1.5"/></svg>
                </div>
                <div class="pv-tracking__order-meta">
                    <span class="pv-tracking__order-label"><?php esc_html_e( 'Pedido', 'ltms' ); ?></span>
                    <h1 class="pv-tracking__order-number">#<?php echo esc_html( $order_number ); ?></h1>
                    <span class="pv-tracking__order-date">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 9h18M8 3v4M16 3v4" stroke-linecap="round"/></svg>
                        <?php echo esc_html( $order_date_fm ); ?>
                    </span>
                </div>
            </div>

            <div class="pv-tracking__order-side">
                <?php
                $status_label = ucfirst( wc_get_order_status_name( $order_status ) );
                $status_class = 'pv-tracking__status--' . sanitize_html_class( $order_status );
                ?>
                <span class="pv-badge pv-badge--lg pv-tracking__status <?php echo esc_attr( $status_class ); ?> pv-badge--dot">
                    <?php echo esc_html( $status_label ); ?>
                </span>
                <div class="pv-tracking__order-total">
                    <span class="pv-tracking__order-total-label"><?php esc_html_e( 'Total', 'ltms' ); ?></span>
                    <span class="pv-tracking__order-total-value"><?php echo $order_total_fm; ?></span>
                </div>
            </div>
        </div>
    </header>

    <!-- ===================================================================
         MAIN: Timeline + sidebar (ETA / repartidor / summary)
         =================================================================== -->
    <main class="pv-tracking__main pv-section" role="main">
        <div class="pv-tracking__layout">

            <!-- ===========================================================
                 COLUMNA IZQUIERDA: Timeline vertical Rappi-style
                 =========================================================== -->
            <div class="pv-tracking__timeline-col">
                <h2 class="pv-tracking__section-title">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83" stroke-linecap="round"/></svg>
                    <?php esc_html_e( 'Estado del envío', 'ltms' ); ?>
                </h2>

                <?php if ( $is_cancelled ) : ?>
                    <div class="pv-tracking__cancelled-banner">
                        <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M15 9l-6 6M9 9l6 6" stroke-linecap="round"/></svg>
                        <div>
                            <strong><?php esc_html_e( 'Pedido cancelado', 'ltms' ); ?></strong>
                            <p><?php esc_html_e( 'Este pedido fue cancelado. Si tienes preguntas, contáctanos.', 'ltms' ); ?></p>
                        </div>
                    </div>
                <?php endif; ?>

                <ol class="pv-timeline" role="list" aria-label="<?php esc_attr_e( 'Timeline de seguimiento', 'ltms' ); ?>">
                    <?php foreach ( $timeline_steps as $i => $step ) :
                        $is_done    = ( $i < $current_step_idx ) || ( $i === $current_step_idx && 'confirmed' === $step['key'] );
                        $is_active  = ( $i === $current_step_idx );
                        $is_pending = ( $i > $current_step_idx );

                        $step_class = 'pv-timeline-step';
                        if ( $is_active ) { $step_class .= ' pv-timeline-step--active'; }
                        if ( $is_done )    { $step_class .= ' pv-timeline-step--done'; }
                        if ( $is_pending ) { $step_class .= ' pv-timeline-step--pending'; }

                        $date_fm = '';
                        if ( $step['date_ts'] > 0 ) {
                            $date_fm = date_i18n( __( 'j M, H:i', 'ltms' ), (int) $step['date_ts'] );
                        }

                        // SVG icon per step.
                        $icon_svg = '';
                        switch ( $step['icon'] ) {
                            case 'check':
                                $icon_svg = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 13l4 4L19 7" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                                break;
                            case 'box':
                                $icon_svg = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 7l9-4 9 4v10l-9 4-9-4V7z" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 7l9 4 9-4M12 11v10" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                                break;
                            case 'truck':
                                $icon_svg = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 7h11v8H3zM14 10h4l3 3v2h-7" stroke-linecap="round" stroke-linejoin="round"/><circle cx="7" cy="18" r="1.5"/><circle cx="17" cy="18" r="1.5"/></svg>';
                                break;
                            case 'route':
                                $icon_svg = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="6" cy="18" r="2"/><circle cx="18" cy="6" r="2"/><path d="M8 18h6a3 3 0 0 0 0-6H10a3 3 0 0 1 0-6h6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                                break;
                            case 'home':
                                $icon_svg = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 11l9-7 9 7M5 10v9h14v-9" stroke-linecap="round" stroke-linejoin="round"/><path d="M9 19v-5h6v5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                                break;
                        }
                        ?>
                        <li class="<?php echo esc_attr( $step_class ); ?>" data-step-key="<?php echo esc_attr( $step['key'] ); ?>" data-step-idx="<?php echo esc_attr( $i ); ?>">

                            <!-- Icon node -->
                            <div class="pv-timeline-step__node" aria-hidden="true">
                                <span class="pv-timeline-step__icon"><?php echo $icon_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — svg estático ?></span>
                                <?php if ( $i < count( $timeline_steps ) - 1 ) : ?>
                                    <span class="pv-timeline-step__line"></span>
                                <?php endif; ?>
                            </div>

                            <!-- Content -->
                            <div class="pv-timeline-step__content">
                                <header class="pv-timeline-step__head">
                                    <h3 class="pv-timeline-step__label"><?php echo esc_html( $step['label'] ); ?></h3>
                                    <?php if ( $date_fm ) : ?>
                                        <time class="pv-timeline-step__date" datetime="<?php echo esc_attr( gmdate( 'c', (int) $step['date_ts'] ) ); ?>"><?php echo esc_html( $date_fm ); ?></time>
                                    <?php elseif ( $is_pending ) : ?>
                                        <span class="pv-timeline-step__date pv-timeline-step__date--pending"><?php esc_html_e( 'Pendiente', 'ltms' ); ?></span>
                                    <?php endif; ?>
                                </header>
                                <p class="pv-timeline-step__desc"><?php echo esc_html( $step['desc'] ); ?></p>

                                <?php if ( 'shipped' === $step['key'] && $tracking_number ) : ?>
                                    <div class="pv-timeline-step__tracking">
                                        <span class="pv-timeline-step__tracking-label"><?php esc_html_e( 'N° de seguimiento:', 'ltms' ); ?></span>
                                        <code class="pv-timeline-step__tracking-num"><?php echo esc_html( $tracking_number ); ?></code>
                                        <?php if ( $carrier_tracking_url ) : ?>
                                            <a href="<?php echo esc_url( $carrier_tracking_url ); ?>" target="_blank" rel="noopener noreferrer" class="pv-btn pv-btn--ghost pv-btn--sm">
                                                <?php esc_html_e( 'Ver en web', 'ltms' ); ?>
                                                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17L17 7M17 7H8M17 7v9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>

                <!-- Order summary colapsable -->
                <details class="pv-accordion pv-tracking__summary-toggle">
                    <summary class="pv-accordion__head">
                        <span><?php esc_html_e( 'Ver detalle del pedido', 'ltms' ); ?></span>
                        <svg class="pv-accordion__icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </summary>
                    <div class="pv-accordion__body pv-tracking__summary-toggle-body">
                        <div class="pv-order-summary">
                            <?php
                            $order_items = $order->get_items();
                            foreach ( $order_items as $item_id => $item ) :
                                $product      = $item->get_product();
                                $product_name = $item->get_name();
                                $qty          = (int) $item->get_quantity();
                                $line_total   = wp_kses_post( wc_price( $item->get_total(), array( 'currency' => $order->get_currency() ) ) );
                                $permalink    = $product ? $product->get_permalink() : '';
                                $thumbnail    = $product ? $product->get_image( 'woocommerce_thumbnail', array( 'class' => 'pv-order-summary__item-img' ), false ) : '';
                                ?>
                                <div class="pv-order-summary__item">
                                    <div class="pv-order-summary__item-media">
                                        <?php if ( $permalink ) : ?>
                                            <a href="<?php echo esc_url( $permalink ); ?>"><?php echo wp_kses_post( $thumbnail ); ?></a>
                                        <?php else : ?>
                                            <?php echo wp_kses_post( $thumbnail ); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="pv-order-summary__item-info">
                                        <?php if ( $permalink ) : ?>
                                            <a href="<?php echo esc_url( $permalink ); ?>" class="pv-order-summary__item-name"><?php echo esc_html( $product_name ); ?></a>
                                        <?php else : ?>
                                            <span class="pv-order-summary__item-name"><?php echo esc_html( $product_name ); ?></span>
                                        <?php endif; ?>
                                        <span class="pv-order-summary__item-qty"><?php echo esc_html( sprintf( __( 'Cantidad: %d', 'ltms' ), $qty ) ); ?></span>
                                    </div>
                                    <div class="pv-order-summary__item-total"><?php echo $line_total; ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Totales -->
                        <table class="pv-order-summary__totals">
                            <?php
                            $subtotal = wp_kses_post( wc_price( $order->get_subtotal(), array( 'currency' => $order->get_currency() ) ) );
                            $shipping = wp_kses_post( wc_price( $order->get_shipping_total(), array( 'currency' => $order->get_currency() ) ) );
                            $discount = (float) $order->get_discount_total();
                            $tax      = (float) $order->get_total_tax();
                            ?>
                            <tr><th><?php esc_html_e( 'Subtotal', 'ltms' ); ?></th><td><?php echo $subtotal; ?></td></tr>
                            <?php if ( $discount > 0 ) : ?>
                                <tr><th><?php esc_html_e( 'Descuento', 'ltms' ); ?></th><td>−<?php echo wp_kses_post( wc_price( $discount, array( 'currency' => $order->get_currency() ) ) ); ?></td></tr>
                            <?php endif; ?>
                            <tr><th><?php esc_html_e( 'Envío', 'ltms' ); ?></th><td><?php echo $shipping; ?></td></tr>
                            <?php if ( $tax > 0 ) : ?>
                                <tr><th><?php esc_html_e( 'Impuestos', 'ltms' ); ?></th><td><?php echo wp_kses_post( wc_price( $tax, array( 'currency' => $order->get_currency() ) ) ); ?></td></tr>
                            <?php endif; ?>
                            <tr class="pv-order-summary__totals-row--total"><th><?php esc_html_e( 'Total', 'ltms' ); ?></th><td><?php echo $order_total_fm; ?></td></tr>
                        </table>
                    </div>
                </details>

                <!-- Reportar problema -->
                <div class="pv-tracking__actions">
                    <a href="<?php echo esc_url( apply_filters( 'ltms_tracking_report_url', home_url( '/ayuda' ), $order ) ); ?>" class="pv-btn pv-btn--ghost pv-btn--sm pv-tracking__report-btn">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4M12 17h.01M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <?php esc_html_e( 'Reportar un problema', 'ltms' ); ?>
                    </a>
                </div>
            </div><!-- /.pv-tracking__timeline-col -->

            <!-- ===========================================================
                 COLUMNA DERECHA: ETA + Repartidor
                 =========================================================== -->
            <aside class="pv-tracking__sidebar" aria-label="<?php esc_attr_e( 'Información de entrega', 'ltms' ); ?>">

                <!-- ETA CARD -->
                <div class="pv-eta-card pv-card pv-card--pad-lg" data-pv-eta>
                    <header class="pv-eta-card__head">
                        <span class="pv-eta-card__label"><?php esc_html_e( 'Entrega estimada', 'ltms' ); ?></span>
                        <?php
                        /**
                         * AUDIT-FE-UIUX2-D09 FIX (P1): el badge "En curso" se
                         * imprimía siempre, incluso con la orden cancelada —
                         * contradictorio con el banner rojo de cancelado que
                         * aparece al lado. Ahora refleja el estado real.
                         */
                        if ( $is_cancelled ) : ?>
                            <span class="pv-badge pv-badge--danger pv-badge--dot"><?php esc_html_e( 'Cancelada', 'ltms' ); ?></span>
                        <?php else : ?>
                            <span class="pv-badge pv-badge--trust pv-badge--dot"><?php esc_html_e( 'En curso', 'ltms' ); ?></span>
                        <?php endif; ?>
                    </header>
                    <?php if ( $eta_fm ) : ?>
                        <div class="pv-eta-card__date"><?php echo esc_html( $eta_fm ); ?></div>
                        <?php if ( $eta_time_fm ) : ?>
                            <div class="pv-eta-card__time">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <?php echo esc_html( sprintf( __( 'Aprox. %s', 'ltms' ), $eta_time_fm ) ); ?>
                            </div>
                        <?php endif; ?>
                        <?php if ( $eta_remaining && ! $is_cancelled ) : ?>
                            <div class="pv-eta-card__remaining">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <?php
                                /* translators: %s: tiempo restante legible (ej. "3 horas"). */
                                echo esc_html( sprintf( __( 'Faltan ~%s', 'ltms' ), $eta_remaining ) );
                                ?>
                            </div>
                        <?php endif; ?>
                    <?php else : ?>
                        <div class="pv-eta-card__date pv-eta-card__date--muted"><?php esc_html_e( 'Por confirmar', 'ltms' ); ?></div>
                        <p class="pv-eta-card__hint"><?php esc_html_e( 'Te avisaremos por correo y WhatsApp cuando tengamos la ventana de entrega.', 'ltms' ); ?></p>
                    <?php endif; ?>

                    <!-- Escrow disclosure -->
                    <div class="pv-escrow-notice pv-escrow-notice--compact pv-eta-card__escrow" role="note">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2l8 4v6c0 5-3.4 8.5-8 10-4.6-1.5-8-5-8-10V6l8-4z" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span><?php esc_html_e( 'Pago en escrow hasta que confirmes la recepción.', 'ltms' ); ?></span>
                    </div>
                </div>

                <!-- REPARTIDOR CARD -->
                <div class="pv-tracker-card pv-card pv-card--pad-lg" data-pv-tracker>
                    <?php if ( $driver_name ) : ?>
                        <header class="pv-tracker-card__head">
                            <span class="pv-tracker-card__label"><?php esc_html_e( 'Tu repartidor', 'ltms' ); ?></span>
                            <?php if ( $driver_rating > 0 ) : ?>
                                <span class="pv-tracker-card__rating">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M12 2l3 7h7l-5.5 4.5L18 21l-6-4.5L6 21l1.5-7.5L2 9h7z"/></svg>
                                    <?php echo esc_html( number_format_i18n( $driver_rating, 1 ) ); ?>
                                </span>
                            <?php endif; ?>
                        </header>

                        <div class="pv-tracker-card__driver">
                            <div class="pv-tracker-card__avatar">
                                <?php if ( $driver_photo ) : ?>
                                    <img src="<?php echo esc_url( $driver_photo ); ?>" alt="<?php echo esc_attr( sprintf( __( 'Foto de %s', 'ltms' ), $driver_name ) ); ?>" loading="lazy" />
                                <?php else : ?>
                                    <span aria-hidden="true"><?php echo esc_html( strtoupper( mb_substr( $driver_name, 0, 1 ) ) ); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="pv-tracker-card__driver-info">
                                <span class="pv-tracker-card__driver-name"><?php echo esc_html( $driver_name ); ?></span>
                                <span class="pv-tracker-card__driver-status pv-badge--dot"><?php esc_html_e( 'En ruta', 'ltms' ); ?></span>
                            </div>
                        </div>

                        <div class="pv-tracker-card__actions">
                            <?php if ( $driver_phone ) : ?>
                                <a href="tel:<?php echo esc_attr( preg_replace( '/\s+/', '', $driver_phone ) ); ?>" class="pv-btn pv-btn--block pv-tracker-card__call-btn" aria-label="<?php echo esc_attr( sprintf( __( 'Llamar a %s', 'ltms' ), $driver_name ) ); ?>">
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    <?php esc_html_e( 'Llamar', 'ltms' ); ?>
                                </a>
                            <?php endif; ?>
                            <a href="<?php echo esc_url( apply_filters( 'ltms_tracking_chat_url', home_url( '/ayuda' ) . '?order_id=' . $order_id, $order ) ); ?>" class="pv-btn pv-btn--ghost pv-btn--block pv-tracker-card__chat-btn" aria-label="<?php esc_attr_e( 'Chatear con el repartidor', 'ltms' ); ?>">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <?php esc_html_e( 'Chatear', 'ltms' ); ?>
                            </a>
                        </div>
                    <?php else : ?>
                        <!-- AUDIT-FE-OT-002: estado honesto según flujo de entrega real. -->
                        <?php if ( $tracking_number && ! $uses_own_delivery && ! $uses_pickup ) : ?>
                            <!-- Carrier externo con tracking pero sin driver asignado en plugin -->
                            <header class="pv-tracker-card__head">
                                <span class="pv-tracker-card__label"><?php esc_html_e( 'Transportadora', 'ltms' ); ?></span>
                                <span class="pv-badge pv-badge--info pv-badge--dot"><?php esc_html_e( 'Asignada', 'ltms' ); ?></span>
                            </header>
                            <div class="pv-tracker-card__driver pv-tracker-card__driver--empty">
                                <div class="pv-tracker-card__avatar pv-tracker-card__avatar--placeholder">
                                    <svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 7h11v8H3zM14 10h4l3 3v2h-7" stroke-linecap="round" stroke-linejoin="round"/><circle cx="7" cy="18" r="1.5"/><circle cx="17" cy="18" r="1.5"/></svg>
                                </div>
                                <div class="pv-tracker-card__driver-info">
                                    <span class="pv-tracker-card__driver-name"><?php echo esc_html( $carrier ? strtoupper( $carrier ) : __( 'Transportadora', 'ltms' ) ); ?></span>
                                    <span class="pv-tracker-card__driver-hint"><?php esc_html_e( 'Tu pedido será entregado por la transportadora asignada. Sigue el envío con el número de seguimiento.', 'ltms' ); ?></span>
                                </div>
                            </div>
                        <?php elseif ( $uses_pickup ) : ?>
                            <!-- Recogida en tienda: no hay repartidor -->
                            <header class="pv-tracker-card__head">
                                <span class="pv-tracker-card__label"><?php esc_html_e( 'Entrega', 'ltms' ); ?></span>
                                <span class="pv-badge pv-badge--info pv-badge--dot"><?php esc_html_e( 'Recogida', 'ltms' ); ?></span>
                            </header>
                            <div class="pv-tracker-card__driver pv-tracker-card__driver--empty">
                                <div class="pv-tracker-card__avatar pv-tracker-card__avatar--placeholder">
                                    <svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 11l9-7 9 7M5 10v9h14v-9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </div>
                                <div class="pv-tracker-card__driver-info">
                                    <span class="pv-tracker-card__driver-name"><?php esc_html_e( 'Recogida en tienda', 'ltms' ); ?></span>
                                    <span class="pv-tracker-card__driver-hint"><?php esc_html_e( 'Recoge tu pedido directamente con el vendedor. Coordinarán por chat.', 'ltms' ); ?></span>
                                </div>
                            </div>
                        <?php else : ?>
                            <!-- Sin repartidor asignado todavía -->
                            <header class="pv-tracker-card__head">
                                <span class="pv-tracker-card__label"><?php esc_html_e( 'Repartidor', 'ltms' ); ?></span>
                                <span class="pv-badge pv-badge--warning pv-badge--dot"><?php esc_html_e( 'Por asignar', 'ltms' ); ?></span>
                            </header>
                            <div class="pv-tracker-card__driver pv-tracker-card__driver--empty">
                                <div class="pv-tracker-card__avatar pv-tracker-card__avatar--placeholder">
                                    <svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="8" r="3.5"/><path d="M5 21a7 7 0 0 1 14 0" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </div>
                                <div class="pv-tracker-card__driver-info">
                                    <span class="pv-tracker-card__driver-name"><?php esc_html_e( 'Sin asignar aún', 'ltms' ); ?></span>
                                    <span class="pv-tracker-card__driver-hint"><?php esc_html_e( 'Te notificaremos cuando asignemos tu repartidor.', 'ltms' ); ?></span>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <?php
                /**
                 * Hook: ltms_tracking_sidebar_extra
                 * Permite inyectar cards adicionales (mapa, contacto, etc.).
                 */
                do_action( 'ltms_tracking_sidebar_extra', $order );
                ?>
            </aside>

        </div><!-- /.pv-tracking__layout -->
    </main>

    <?php
    /**
     * Hook: ltms_after_tracking_main
     */
    do_action( 'ltms_after_tracking_main', $order );
    ?>
</div><!-- /.pv-scope.pv-tracking -->

<!-- AUDIT-FE-UIUX-BACKLOG-D20 FIX: los estilos scoped de esta pagina viven ahora en
     la seccion 24 de assets/css/ltms-plaza-viva.css (design system encolado global). -->

<?php
get_footer();
