<?php
/**
 * Template: Help Center — Plaza Viva Design System
 *
 * Centro de ayuda / soporte del marketplace. Se sirve vía `template_include`
 * cuando la página actual es la página de ayuda
 * (ver LTMS_Native_Templates::is_help_page()).
 *
 * Secciones:
 *  - Hero "¿Cómo podemos ayudarte?" con search bar para FAQ.
 *  - 3 canales de contacto: Chat en vivo (Tawk.to / Intercom),
 *    Email, WhatsApp.
 *  - FAQ accordion (8 preguntas frecuentes) — CPT `ltms_faq` si existe,
 *    fallback hardcodeado.
 *  - Acceso rápido: Rastrear pedido, Políticas, Devoluciones.
 *
 * Usa WC hooks estándar y el design system "Plaza Viva"
 * (assets/css/ltms-plaza-viva.css + assets/js/ltms-plaza-viva.js).
 *
 * @package LTMS
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Salida directa no permitida.
}

/* ---------------------------------------------------------------------------
 * 1. Datos de contacto
 * ------------------------------------------------------------------------- */
$pv_whatsapp_number = (string) get_option( 'ltms_whatsapp_number', '+57 300 000 0000' );
$pv_whatsapp_msg    = rawurlencode( __( 'Hola, necesito ayuda con mi compra en el marketplace.', 'ltms' ) );
$pv_whatsapp_url    = 'https://wa.me/' . preg_replace( '/[^0-9]/', '', $pv_whatsapp_number ) . '?text=' . $pv_whatsapp_msg;

$pv_support_email   = (string) get_option( 'ltms_support_email', get_option( 'admin_email' ) );
$pv_support_subject = rawurlencode( __( 'Solicitud de soporte — Marketplace', 'ltms' ) );
$pv_email_url       = 'mailto:' . $pv_support_email . '?subject=' . $pv_support_subject;

// Chat: Tawk.to o Intercom — sólo si está configurado.
$pv_tawk_property   = (string) get_option( 'ltms_tawk_property_id', '' );
$pv_intercom_app_id = (string) get_option( 'ltms_intercom_app_id', '' );
$pv_chat_provider   = $pv_tawk_property ? 'tawk' : ( $pv_intercom_app_id ? 'intercom' : '' );

/* ---------------------------------------------------------------------------
 * 2. FAQ items — CPT `ltms_faq` si existe, fallback hardcodeado 8 preguntas
 * ------------------------------------------------------------------------- */
$pv_faq_items = [];

if ( post_type_exists( 'ltms_faq' ) ) {
    $pv_faq_posts = get_posts( [
        'post_type'           => 'ltms_faq',
        'post_status'         => 'publish',
        'posts_per_page'      => 8,
        'orderby'             => 'menu_order',
        'order'               => 'ASC',
        'ignore_sticky_posts' => true,
        'no_found_rows'       => true,
    ] );

    foreach ( $pv_faq_posts as $pv_fp ) {
        $pv_faq_items[] = [
            'q' => wp_strip_all_tags( $pv_fp->post_title ),
            'a' => $pv_fp->post_content,
        ];
    }
}

if ( empty( $pv_faq_items ) ) {
    // Fallback hardcodeado — 8 preguntas frecuentes.
    $pv_faq_items = [
        [
            'q' => __( '¿Cómo rastreo mi pedido?', 'ltms' ),
            'a' => __( 'Entra a la sección "Rastrear pedido" con tu número de orden y correo electrónico. Verás el estado en tiempo real: confirmado, en preparación, despachado, en camino y entregado.', 'ltms' ),
        ],
        [
            'q' => __( '¿Cuánto tarda en llegar mi compra?', 'ltms' ),
            'a' => __( 'El tiempo de entrega depende de la ciudad destino y del transportador (Deprisa, Aveonline, Heka, Coordinadora). En ciudades principales: 2-3 días hábiles. En zonas alejadas: 4-7 días hábiles.', 'ltms' ),
        ],
        [
            'q' => __( '¿Mi pago está protegido?', 'ltms' ),
            'a' => __( 'Sí. Usamos Escrow: el pago al vendedor se libera solo cuando confirmas que recibiste el producto conforme. Si hay inconvenientes, abres un caso y el dinero queda retenido hasta resolver.', 'ltms' ),
        ],
        [
            'q' => __( '¿Qué métodos de pago aceptan?', 'ltms' ),
            'a' => __( 'Aceptamos PSE, tarjetas crédito/débito (Visa, Mastercard, Amex), Nequi, Daviplata, Baloto en efectivo y wallets integradas. Todos los pagos son procesados bajo certificación PCI-DSS.', 'ltms' ),
        ],
        [
            'q' => __( '¿Cómo funciona la devolución?', 'ltms' ),
            'a' => __( 'Si el producto llega dañado o no coincide con la descripción, tienes 7 días para reportarlo desde tu cuenta. Abrimos un caso, evaluamos las evidencias y procesamos reembolso o reemplazo según aplique.', 'ltms' ),
        ],
        [
            'q' => __( '¿Cómo me convierto en vendedor?', 'ltms' ),
            'a' => __( 'Regístrate en "Vender en Lo Tengo", completa tu verificación KYC (cédula y selfie), configura tus datos bancarios y empieza a publicar productos. La activación toma 24-48 horas hábiles.', 'ltms' ),
        ],
        [
            'q' => __( '¿Qué es un Star Seller?', 'ltms' ),
            'a' => __( 'Es un vendedor destacado con KYC aprobado, rating igual o superior a 4.7, menos del 2% de casos de disputa y tiempo de despacho promedio menor a 24 horas. Es un sello de confianza del marketplace.', 'ltms' ),
        ],
        [
            'q' => __( '¿Puedo cancelar una compra?', 'ltms' ),
            'a' => __( 'Sí, siempre que el vendedor aún no haya despachado. Ve a "Mis compras", selecciona la orden y pulsa "Cancelar". El reembolso se procesa en 1-3 días hábiles al mismo medio de pago.', 'ltms' ),
        ],
    ];
}

/* ---------------------------------------------------------------------------
 * 3. Accesos rápidos
 * ------------------------------------------------------------------------- */
$pv_tracking_url = home_url( '/seguimiento/' );
$pv_policies_url = home_url( '/politicas/' );
$pv_returns_url  = home_url( '/devoluciones/' );
$pv_orders_url   = function_exists( 'wc_get_page_id' ) ? get_permalink( wc_get_page_id( 'myaccount' ) ) : home_url( '/mi-cuenta/' );

/* ---------------------------------------------------------------------------
 * 4. Chat provider URL / setup
 * ------------------------------------------------------------------------- */
$pv_chat_setup_html = '';
if ( $pv_chat_provider === 'tawk' ) {
    // Tawk.to requiere encolar su script globalmente; aquí exponemos el ID.
    $pv_chat_setup_html = '<script>window.__ltmsTawkProperty=' . wp_json_encode( $pv_tawk_property ) . ';</script>';
} elseif ( $pv_chat_provider === 'intercom' ) {
    $pv_chat_setup_html = '<script>window.__ltmsIntercomAppId=' . wp_json_encode( $pv_intercom_app_id ) . ';</script>';
}

get_header();

/**
 * Hook: ltms_before_help_center_plazaviva
 */
do_action( 'ltms_before_help_center_plazaviva' );
?>

<div class="pv-scope pv-help">

    <?php
    /* =====================================================================
     * HERO — "¿Cómo podemos ayudarte?" + search bar
     * =====================================================================
     */
    ?>
    <section class="pv-section pv-help__hero-wrap" aria-labelledby="pv-help-hero-title">
        <div class="pv-hero pv-help__hero">
            <span class="pv-hero__eyebrow">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <?php esc_html_e( 'Centro de ayuda', 'ltms' ); ?>
            </span>
            <h1 id="pv-help-hero-title" class="pv-hero__title"><?php esc_html_e( '¿Cómo podemos ayudarte?', 'ltms' ); ?></h1>
            <p class="pv-hero__sub"><?php esc_html_e( 'Encuentra respuestas rápidas, rastrea tus pedidos o habla con nuestro equipo de soporte.', 'ltms' ); ?></p>

            <form class="pv-hero__search pv-help__search" role="search" autocomplete="off" onsubmit="return false;">
                <label class="pv-visually-hidden" for="pv-help-faq-search"><?php esc_html_e( 'Buscar en preguntas frecuentes', 'ltms' ); ?></label>
                <span class="pv-help__search-icon" aria-hidden="true">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                </span>
                <input type="search"
                       id="pv-help-faq-search"
                       class="pv-help__search-input"
                       placeholder="<?php esc_attr_e( 'Ej: ¿Cómo rastreo mi pedido?', 'ltms' ); ?>"
                       data-pv-faq-search
                       aria-controls="pv-help-faq-list" />
                <button type="submit" class="pv-btn pv-help__search-btn" aria-label="<?php esc_attr_e( 'Buscar', 'ltms' ); ?>">
                    <?php esc_html_e( 'Buscar', 'ltms' ); ?>
                </button>
            </form>
        </div>
    </section>

    <?php
    /* =====================================================================
     * ACCESOS RÁPIDOS — 3 atajos principales
     * =====================================================================
     */
    ?>
    <section class="pv-section pv-help__quick" aria-label="<?php esc_attr_e( 'Accesos rápidos', 'ltms' ); ?>">
        <div class="pv-help__quick-grid">
            <a class="pv-card pv-help__quick-card pv-lift" href="<?php echo esc_url( $pv_tracking_url ); ?>">
                <span class="pv-help__quick-icon pv-help__quick-icon--primary" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                </span>
                <span class="pv-help__quick-body">
                    <span class="pv-help__quick-title"><?php esc_html_e( 'Rastrear pedido', 'ltms' ); ?></span>
                    <span class="pv-help__quick-desc"><?php esc_html_e( 'Estado en tiempo real de tu envío', 'ltms' ); ?></span>
                </span>
                <svg class="pv-help__quick-arrow" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
            <a class="pv-card pv-help__quick-card pv-lift" href="<?php echo esc_url( $pv_policies_url ); ?>">
                <span class="pv-help__quick-icon pv-help__quick-icon--gold" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                </span>
                <span class="pv-help__quick-body">
                    <span class="pv-help__quick-title"><?php esc_html_e( 'Políticas', 'ltms' ); ?></span>
                    <span class="pv-help__quick-desc"><?php esc_html_e( 'Términos, condiciones y garantías', 'ltms' ); ?></span>
                </span>
                <svg class="pv-help__quick-arrow" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
            <a class="pv-card pv-help__quick-card pv-lift" href="<?php echo esc_url( $pv_returns_url ); ?>">
                <span class="pv-help__quick-icon pv-help__quick-icon--accent" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
                </span>
                <span class="pv-help__quick-body">
                    <span class="pv-help__quick-title"><?php esc_html_e( 'Devoluciones', 'ltms' ); ?></span>
                    <span class="pv-help__quick-desc"><?php esc_html_e( 'Reporta un producto y abre un caso', 'ltms' ); ?></span>
                </span>
                <svg class="pv-help__quick-arrow" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
        </div>
    </section>

    <?php
    /* =====================================================================
     * CANALES DE CONTACTO — 3 cards: Chat, Email, WhatsApp
     * =====================================================================
     */
    ?>
    <section class="pv-section pv-help__channels" aria-labelledby="pv-help-channels-title">
        <header class="pv-section__head">
            <div>
                <h2 id="pv-help-channels-title" class="pv-section__title"><?php esc_html_e( 'Habla con nosotros', 'ltms' ); ?></h2>
                <p class="pv-section__sub"><?php esc_html_e( 'Elige el canal que prefieras. Estamos para ayudarte.', 'ltms' ); ?></p>
            </div>
        </header>

        <div class="pv-help__channels-grid">
            <?php /* Chat en vivo */ ?>
            <article class="pv-card pv-help__channel <?php echo $pv_chat_provider ? '' : 'is-disabled'; ?>">
                <span class="pv-help__channel-icon pv-help__channel-icon--primary" aria-hidden="true">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                </span>
                <h3 class="pv-help__channel-title"><?php esc_html_e( 'Chat en vivo', 'ltms' ); ?></h3>
                <p class="pv-help__channel-desc"><?php esc_html_e( 'Respuesta inmediata en horario L-V 8am-8pm y S 9am-5pm.', 'ltms' ); ?></p>
                <?php if ( $pv_chat_provider ) : ?>
                    <button type="button"
                            class="pv-btn pv-btn--block pv-help__channel-cta"
                            data-pv-chat-trigger="<?php echo esc_attr( $pv_chat_provider ); ?>"
                            data-pv-chat-tawk="<?php echo esc_attr( $pv_tawk_property ); ?>"
                            data-pv-chat-intercom="<?php echo esc_attr( $pv_intercom_app_id ); ?>">
                        <?php esc_html_e( 'Abrir chat', 'ltms' ); ?>
                    </button>
                <?php else : ?>
                    <button type="button" class="pv-btn pv-btn--ghost pv-btn--block" disabled>
                        <?php esc_html_e( 'No disponible ahora', 'ltms' ); ?>
                    </button>
                <?php endif; ?>
            </article>

            <?php /* Email */ ?>
            <article class="pv-card pv-help__channel">
                <span class="pv-help__channel-icon pv-help__channel-icon--gold" aria-hidden="true">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                </span>
                <h3 class="pv-help__channel-title"><?php esc_html_e( 'Correo electrónico', 'ltms' ); ?></h3>
                <p class="pv-help__channel-desc"><?php esc_html_e( 'Te respondemos en menos de 24 horas hábiles.', 'ltms' ); ?></p>
                <a class="pv-btn pv-btn--block pv-help__channel-cta" href="<?php echo esc_url( $pv_email_url ); ?>"><?php echo esc_html( $pv_support_email ); ?></a>
            </article>

            <?php /* WhatsApp */ ?>
            <article class="pv-card pv-help__channel">
                <span class="pv-help__channel-icon pv-help__channel-icon--accent" aria-hidden="true">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.6 6.3A7.85 7.85 0 0 0 12.05 4a7.94 7.94 0 0 0-6.9 11.9L4 20l4.2-1.1a7.93 7.93 0 0 0 3.8 1h.01a7.94 7.94 0 0 0 5.6-13.6zM12.05 18.5h-.01a6.55 6.55 0 0 1-3.36-.92l-.24-.14-2.49.65.67-2.43-.16-.25a6.59 6.59 0 1 1 5.59 3.09zm3.6-4.93c-.2-.1-1.17-.58-1.36-.64s-.31-.1-.45.1-.51.64-.63.78-.23.15-.43.05a6.62 6.62 0 0 1-1.95-1.2 7.27 7.27 0 0 1-1.35-1.68c-.14-.24 0-.37.1-.49s.2-.23.3-.35a1.49 1.49 0 0 0 .2-.34.37.37 0 0 0 0-.35c0-.1-.45-1.08-.62-1.48s-.32-.33-.45-.34h-.38a.73.73 0 0 0-.53.25 2.21 2.21 0 0 0-.69 1.65 3.86 3.86 0 0 0 .8 2.03 8.78 8.78 0 0 0 3.37 2.98 11.27 11.27 0 0 0 1.12.41 2.71 2.71 0 0 0 1.24.08 2.03 2.03 0 0 0 1.33-.94 1.66 1.66 0 0 0 .12-.94c-.05-.08-.18-.13-.38-.23z"/></svg>
                </span>
                <h3 class="pv-help__channel-title"><?php esc_html_e( 'WhatsApp', 'ltms' ); ?></h3>
                <p class="pv-help__channel-desc"><?php esc_html_e( 'Atención ágil de L-S 8am-9pm. Respuesta en pocos minutos.', 'ltms' ); ?></p>
                <a class="pv-btn pv-btn--block pv-btn--accent pv-help__channel-cta"
                   href="<?php echo esc_url( $pv_whatsapp_url ); ?>"
                   target="_blank" rel="noopener noreferrer">
                    <?php echo esc_html( $pv_whatsapp_number ); ?>
                </a>
            </article>
        </div>
    </section>

    <?php
    /* =====================================================================
     * FAQ — accordion con 8 preguntas (búsqueda en vivo)
     * =====================================================================
     */
    ?>
    <section class="pv-section pv-help__faq" aria-labelledby="pv-help-faq-title">
        <header class="pv-section__head">
            <div>
                <h2 id="pv-help-faq-title" class="pv-section__title"><?php esc_html_e( 'Preguntas frecuentes', 'ltms' ); ?></h2>
                <p class="pv-section__sub"><?php esc_html_e( 'Las respuestas a las consultas más comunes del marketplace.', 'ltms' ); ?></p>
            </div>
            <span class="pv-help__faq-count" id="pv-help-faq-count" aria-live="polite">
                <?php echo esc_html( sprintf( _n( '%d resultado', '%d resultados', count( $pv_faq_items ), 'ltms' ), count( $pv_faq_items ) ) ); ?>
            </span>
        </header>

        <div class="pv-help__faq-list" id="pv-help-faq-list">
            <?php foreach ( $pv_faq_items as $pv_idx => $pv_item ) :
                $pv_q = isset( $pv_item['q'] ) ? $pv_item['q'] : '';
                $pv_a = isset( $pv_item['a'] ) ? $pv_item['a'] : '';
                $pv_open = ( $pv_idx === 0 ); // primera abierta por defecto
                ?>
                <details class="pv-accordion pv-help__faq-item" <?php echo $pv_open ? 'open' : ''; ?> data-pv-faq-item>
                    <summary class="pv-accordion__head pv-help__faq-q">
                        <span class="pv-help__faq-q-text"><?php echo esc_html( $pv_q ); ?></span>
                        <svg class="pv-accordion__icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                    </summary>
                    <div class="pv-accordion__body pv-help__faq-a">
                        <?php echo wpautop( wp_kses_post( wptexturize( $pv_a ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>

        <div class="pv-help__faq-empty pv-hidden" id="pv-help-faq-empty" role="status">
            <div class="pv-card pv-card--flat pv-help__faq-empty-card">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <h3><?php esc_html_e( 'No encontramos coincidencias', 'ltms' ); ?></h3>
                <p><?php esc_html_e( 'Intenta con otra palabra o contáctanos por los canales disponibles.', 'ltms' ); ?></p>
            </div>
        </div>
    </section>

    <?php
    /* =====================================================================
     * CTA FINAL — aún necesitas ayuda
     * =====================================================================
     */
    ?>
    <section class="pv-section pv-help__cta" aria-labelledby="pv-help-cta-title">
        <div class="pv-help__cta-card">
            <div class="pv-help__cta-text">
                <h2 id="pv-help-cta-title"><?php esc_html_e( '¿No encontraste lo que buscabas?', 'ltms' ); ?></h2>
                <p><?php esc_html_e( 'Revisa tus compras, abre un caso o escríbenos directamente. Nuestro equipo te acompaña en todo el proceso.', 'ltms' ); ?></p>
            </div>
            <div class="pv-help__cta-actions">
                <a class="pv-btn pv-btn--lg" href="<?php echo esc_url( $pv_orders_url ); ?>">
                    <?php esc_html_e( 'Ir a mis compras', 'ltms' ); ?>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                </a>
                <a class="pv-btn pv-btn--ghost pv-btn--lg" href="<?php echo esc_url( $pv_whatsapp_url ); ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e( 'Escribir por WhatsApp', 'ltms' ); ?>
                </a>
            </div>
        </div>
    </section>

</div><!-- /.pv-scope.pv-help -->

<?php echo $pv_chat_setup_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

<script>
(function(){
    /* ====================================================================
     * FAQ search — filtrado en vivo por texto (vanilla JS)
     * ==================================================================== */
    var search = document.querySelector('[data-pv-faq-search]');
    var items  = document.querySelectorAll('[data-pv-faq-item]');
    var empty  = document.getElementById('pv-help-faq-empty');
    var count  = document.getElementById('pv-help-faq-count');
    if (!search || !items.length) return;

    function norm(s){ return (s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }

    search.addEventListener('input', function(){
        var q = norm(search.value.trim());
        var visible = 0;
        items.forEach(function(it){
            var text = norm(it.textContent || '');
            var match = !q || text.indexOf(q) !== -1;
            it.style.display = match ? '' : 'none';
            if (match) visible++;
        });
        if (count) {
            count.textContent = visible + ' ' + (visible === 1 ? '<?php echo esc_js( __( 'resultado', 'ltms' ) ); ?>' : '<?php echo esc_js( __( 'resultados', 'ltms' ) ); ?>');
        }
        if (empty) {
            empty.classList.toggle('pv-hidden', visible > 0);
        }
    });

    /* ====================================================================
     * Chat trigger — abre Tawk.to o Intercom si está configurado
     * ==================================================================== */
    document.addEventListener('click', function(e){
        var btn = e.target.closest('[data-pv-chat-trigger]');
        if (!btn) return;
        e.preventDefault();
        var provider = btn.getAttribute('data-pv-chat-trigger');

        if (provider === 'tawk' && typeof window.Tawk_API !== 'undefined' && window.Tawk_API.toggle) {
            window.Tawk_API.toggle();
            return;
        }
        if (provider === 'intercom' && typeof window.Intercom !== 'undefined') {
            window.Intercom('show');
            return;
        }
        // Fallback: si el widget aún no cargó, intentamos cargar bajo demanda.
        if (provider === 'tawk' && window.__ltmsTawkProperty) {
            var s1 = document.createElement('script');
            s1.async = true; s1.src = 'https://embed.tawk.to/' + window.__ltmsTawkProperty + '/default';
            s1.charset = 'UTF-8';
            s1.setAttribute('crossorigin', '*');
            document.body.appendChild(s1);
            s1.onload = function(){
                setTimeout(function(){
                    if (window.Tawk_API && window.Tawk_API.toggle) window.Tawk_API.toggle();
                }, 600);
            };
            return;
        }
        if (provider === 'intercom' && window.__ltmsIntercomAppId) {
            (function(){var w=window;var ic=w.Intercom;if(typeof ic==="function"){ic('reattach_activator');ic('update',w.intercomSettings);}else{var d=document;var i=function(){i.c(arguments);};i.q=[];i.c=function(args){i.q.push(args);};w.Intercom=i;var l=function(){var s=d.createElement('script');s.type='text/javascript';s.async=true;s.src='https://widget.intercom.io/widget/' + w.__ltmsIntercomAppId;var x=d.getElementsByTagName('script')[0];x.parentNode.insertBefore(s,x);};if(w.attachEvent){w.attachEvent('onload',l);}else{w.addEventListener('load',l,false);}}})();
            setTimeout(function(){ if (window.Intercom) window.Intercom('show'); }, 600);
            return;
        }
        // Último recurso: mensaje de ayuda.
        if (window.PV && window.PV.toast) {
            window.PV.toast('<?php echo esc_js( __( 'El chat no está disponible en este momento. Escríbenos por WhatsApp o email.', 'ltms' ) ); ?>', { type: 'warning', duration: 3000 });
        } else {
            alert('<?php echo esc_js( __( 'El chat no está disponible en este momento. Escríbenos por WhatsApp o email.', 'ltms' ) ); ?>');
        }
    });
})();
</script>

<?php
/**
 * Hook: ltms_after_help_center_plazaviva
 */
do_action( 'ltms_after_help_center_plazaviva' );

get_footer();
