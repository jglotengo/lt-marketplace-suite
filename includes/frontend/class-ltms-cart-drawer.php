<?php
/**
 * LTMS Cart Drawer — Carrito lateral (slide-in) con upsells + free shipping bar.
 *
 * Reemplaza el redirect a /cart con un drawer AJAX que se desliza desde la derecha.
 * Incluye:
 *  - Barra de progreso de envío gratis (conecta con MODE_HYBRID threshold).
 *  - Upsells: productos del mismo vendor que ya están en el carrito.
 *  - Countdown timer: "Tu carrito está reservado por X minutos".
 *  - Integración con WooCommerce AJAX fragments.
 *
 * @package LTMS
 * @version 2.9.2
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class LTMS_Cart_Drawer {

    public static function init(): void {
        // Render drawer HTML en el footer de todas las páginas.
        add_action( 'wp_footer', [ __CLASS__, 'render_drawer_html' ] );

        // v2.9.59: Output buffering — inyectar el script inline directamente
        // en el HTML final. SiteGround Optimizer remueve scripts inline de
        // wp_head/wp_footer, pero NO puede tocar el HTML modificado vía ob.
        add_action( 'template_redirect', [ __CLASS__, 'start_output_buffer' ] );

        // AJAX: obtener contenido del drawer (refresh).
        add_action( 'wp_ajax_ltms_refresh_drawer', [ __CLASS__, 'ajax_refresh_drawer' ] );
        add_action( 'wp_ajax_nopriv_ltms_refresh_drawer', [ __CLASS__, 'ajax_refresh_drawer' ] );

        // AJAX: remove item from cart via drawer.
        add_action( 'wp_ajax_ltms_drawer_remove_item', [ __CLASS__, 'ajax_remove_item' ] );
        add_action( 'wp_ajax_nopriv_ltms_drawer_remove_item', [ __CLASS__, 'ajax_remove_item' ] );

        // AJAX: update quantity via drawer.
        add_action( 'wp_ajax_ltms_drawer_update_qty', [ __CLASS__, 'ajax_update_qty' ] );
        add_action( 'wp_ajax_nopriv_ltms_drawer_update_qty', [ __CLASS__, 'ajax_update_qty' ] );

        // Hook into WC add_to_cart to trigger drawer open (via JS).
        add_filter( 'woocommerce_add_to_cart_fragments', [ __CLASS__, 'add_drawer_fragment' ] );

        // Disable WC redirect to cart after add (we use drawer instead).
        add_filter( 'woocommerce_cart_redirect_after_add', [ __CLASS__, 'disable_cart_redirect' ] );
    }

    /**
     * v2.9.59: Inicia output buffering para inyectar el script inline
     * directamente en el HTML final, evitando que SiteGround lo remueva.
     */
    public static function start_output_buffer(): void {
        // No aplicar en admin, AJAX, REST, o feeds.
        if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() ) {
            return;
        }

        ob_start( [ __CLASS__, 'inject_cart_script_into_html' ] );
    }

    /**
     * v2.9.59: Callback del output buffer — inyecta el script inline
     * antes de </head> o al inicio de <body>.
     */
    public static function inject_cart_script_into_html( string $html ): string {
        // Si no es HTML, devolver sin modificar.
        if ( stripos( $html, '<html' ) === false || stripos( $html, '</head>' ) === false ) {
            return $html;
        }

        $script = self::get_cart_buttons_script_html();

        // Intentar inyectar antes de </head>
        $pos = stripos( $html, '</head>' );
        if ( $pos !== false ) {
            return substr( $html, 0, $pos ) . $script . substr( $html, $pos );
        }

        // Fallback: inyectar después de <body>
        $pos = stripos( $html, '<body' );
        if ( $pos !== false ) {
            $close_tag = stripos( $html, '>', $pos );
            if ( $close_tag !== false ) {
                return substr( $html, 0, $close_tag + 1 ) . $script . substr( $html, $close_tag + 1 );
            }
        }

        return $html;
    }

    /**
     * v2.9.59: Devuelve el HTML del script inline como string.
     */
    public static function get_cart_buttons_script_html(): string {
        $ajax_url = admin_url( 'admin-ajax.php' );
        $nonce = wp_create_nonce( 'ltms_ux_nonce' );

        return '<!-- LTMS-CART-SCRIPT-v2.9.59-START -->' . "\n" .
            '<script>' . "\n" .
            self::get_cart_script_js( $ajax_url, $nonce ) . "\n" .
            '</script>' . "\n" .
            '<!-- LTMS-CART-SCRIPT-v2.9.59-END -->' . "\n";
    }

    /**
     * v2.9.59: Devuelve el código JS del script inline.
     */
    private static function get_cart_script_js( string $ajax_url, string $nonce ): string {
        $ajax_url_js = esc_js( $ajax_url );
        $nonce_js = esc_js( $nonce );

        return <<<JS
window.LTMS_CART = {
    ajaxUrl: '{$ajax_url_js}',
    nonce: '{$nonce_js}',
    busy: false,
    debug: window.location.search.indexOf('ltmsCartDebug=1') !== -1,
    log: function(msg) { if (window.LTMS_CART.debug) console.log('[LTMS_CART]', msg); },
    err: function(msg, e) { console.error('[LTMS_CART]', msg, e || ''); }
};
// v2.9.206: Capture-phase click listener. Matches BOTH the new dynamic drawer
// button classes (.ltms-cart-qty-inc/dec, .ltms-cart-item-remove) AND the legacy
// jQuery drawer button classes (.ltms-drawer-qty-plus/minus, .ltms-drawer-item__remove).
// Using capture=true + stopImmediatePropagation so we beat any bubble-phase
// jQuery handler that may be attached to the legacy drawer.
document.addEventListener('click', function(e) {
    if (!e.target || typeof e.target.closest !== 'function') return;
    var incBtn = e.target.closest('.ltms-cart-qty-inc, .ltms-drawer-qty-plus');
    var decBtn = e.target.closest('.ltms-cart-qty-dec, .ltms-drawer-qty-minus');
    var removeBtn = e.target.closest('.ltms-cart-item-remove, .ltms-drawer-item__remove');
    if (!incBtn && !decBtn && !removeBtn) return;
    e.preventDefault();
    e.stopPropagation();
    e.stopImmediatePropagation();
    if (window.LTMS_CART.busy) {
        window.LTMS_CART.log('busy, ignoring click');
        return;
    }
    window.LTMS_CART.busy = true;
    setTimeout(function() { window.LTMS_CART.busy = false; }, 300);
    var key = (incBtn || decBtn || removeBtn).dataset.key;
    if (!key) {
        window.LTMS_CART.err('No data-key on button', (incBtn || decBtn || removeBtn));
        return;
    }
    window.LTMS_CART.log('click: key=' + key + ' inc=' + !!incBtn + ' dec=' + !!decBtn + ' rm=' + !!removeBtn);
    if (incBtn || decBtn) {
        LTMS_CART.updateQty(key, incBtn ? 1 : -1);
    } else if (removeBtn) {
        LTMS_CART.removeItem(key);
    }
}, true);
// v2.9.207: Use XMLHttpRequest instead of fetch. SiteGround's security layer
// (mod_security rules + Cloudflare-style proxy) has been known to silently
// drop fetch() POST requests with application/x-www-form-urlencoded bodies
// while allowing jQuery's $.post (which uses XHR). Using XHR directly matches
// jQuery's behavior and is more resilient to intermediary interference.
window.LTMS_CART.ajax = function(body, onSuccess, onError) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', LTMS_CART.ajaxUrl, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
    xhr.withCredentials = true;
    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) return;
        if (xhr.status >= 200 && xhr.status < 300) {
            try {
                var json = JSON.parse(xhr.responseText);
                if (json && json.success) {
                    window.LTMS_CART.log('AJAX success');
                    onSuccess(json.data, json);
                } else {
                    window.LTMS_CART.err('AJAX returned error', json);
                    if (onError) onError(json, xhr.status);
                    else LTMS_CART.notify('Error: ' + (json && json.data && json.data.message || 'desconocido'));
                }
            } catch (parseErr) {
                window.LTMS_CART.err('AJAX response not JSON', xhr.responseText.substring(0, 200));
                if (onError) onError(null, xhr.status);
                else LTMS_CART.notify('Error de red — ver consola');
            }
        } else {
            window.LTMS_CART.err('AJAX HTTP ' + xhr.status, xhr.responseText.substring(0, 200));
            if (onError) onError(null, xhr.status);
            else LTMS_CART.notify('HTTP ' + xhr.status + ' — ver consola');
        }
    };
    xhr.onerror = function() {
        window.LTMS_CART.err('XHR network error');
        if (onError) onError(null, 0);
        else LTMS_CART.notify('Error de red');
    };
    xhr.send(body);
};
window.LTMS_CART.notify = function(msg) {
    var t = document.createElement('div');
    t.style.cssText = 'position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#DC2626;color:#fff;padding:12px 20px;border-radius:8px;font-size:14px;z-index:999999;box-shadow:0 4px 12px rgba(0,0,0,0.2);font-family:sans-serif';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(function() { if (t.parentNode) t.parentNode.removeChild(t); }, 3500);
};
window.LTMS_CART.updateQty = function(key, change) {
    // v2.9.206: Update qty display in BOTH drawer types simultaneously.
    // New dynamic drawer: <span class="ltms-cart-qty-value"> inside [data-cart-item-key]
    // Legacy jQuery drawer: sibling <span> between +/- buttons (no .ltms-cart-qty-value class)
    var itemEls = document.querySelectorAll('[data-cart-item-key="' + key + '"]');
    var currentQty = 1;
    var newQty = 1;
    for (var i = 0; i < itemEls.length; i++) {
        var el = itemEls[i];
        // New drawer: <span class="ltms-cart-qty-value">
        var qtySpan = el.querySelector('.ltms-cart-qty-value');
        // Legacy drawer: any <span> inside .ltms-drawer-item__qty (between +/- buttons)
        if (!qtySpan) {
            var legacyQtyWrap = el.querySelector('.ltms-drawer-item__qty');
            if (legacyQtyWrap) {
                var spans = legacyQtyWrap.querySelectorAll('span');
                // First span that contains just a number is the qty
                for (var j = 0; j < spans.length; j++) {
                    if (!spans[j].className || spans[j].className.indexOf('remove') === -1) {
                        var t = parseInt(spans[j].textContent, 10);
                        if (!isNaN(t)) { qtySpan = spans[j]; break; }
                    }
                }
            }
        }
        if (qtySpan) {
            currentQty = parseInt(qtySpan.textContent, 10) || 1;
            newQty = Math.max(1, currentQty + change);
            qtySpan.textContent = newQty;
        }
    }
    if (newQty < 1) newQty = 1;
    window.LTMS_CART.log('updateQty: ' + key + ' ' + currentQty + ' -> ' + newQty);
    var body = 'action=ltms_drawer_update_qty&nonce=' + encodeURIComponent(LTMS_CART.nonce) + '&cart_item_key=' + encodeURIComponent(key) + '&qty=' + newQty;
    LTMS_CART.ajax(body, function(data) {
        LTMS_CART.reload();
    }, function(err, status) {
        LTMS_CART.reload();
    });
};
window.LTMS_CART.removeItem = function(key) {
    var itemEls = document.querySelectorAll('[data-cart-item-key="' + key + '"]');
    window.LTMS_CART.log('removeItem: ' + key + ' (found ' + itemEls.length + ' element[s])');
    for (var i = 0; i < itemEls.length; i++) {
        var el = itemEls[i];
        el.style.transition = 'opacity 0.2s, transform 0.2s';
        el.style.opacity = '0';
        el.style.transform = 'translateX(20px)';
        (function(node) {
            setTimeout(function() { if (node && node.parentNode) node.parentNode.removeChild(node); }, 200);
        })(el);
    }
    var body = 'action=ltms_drawer_remove_item&nonce=' + encodeURIComponent(LTMS_CART.nonce) + '&cart_item_key=' + encodeURIComponent(key);
    LTMS_CART.ajax(body, function(data) {
        LTMS_CART.reload();
    }, function(err, status) {
        LTMS_CART.reload();
    });
};
window.LTMS_CART.reload = function() {
    var body = 'action=ltms_get_cart&nonce=' + encodeURIComponent(LTMS_CART.nonce);
    LTMS_CART.ajax(body, function(data) {
        if (data.count !== undefined) {
            document.querySelectorAll('.ltms-sf-cart-count, .ltms-cart-count, .cart-count').forEach(function(el){el.textContent=data.count;});
        }
        if (data.total_formatted) {
            var subtotalEl = document.querySelector('#ltms-cart-subtotal');
            if (!subtotalEl) {
                var footer = document.querySelector('#ltms-drawer-footer') || document.querySelector('#ltms-cart-drawer-footer');
                if (footer && !footer.querySelector('.ltms-cart-subtotal-display')) {
                    var d = document.createElement('div');
                    d.className = 'ltms-cart-subtotal-display';
                    d.style.cssText = 'padding:8px 0;font-size:18px;font-weight:700;display:flex;justify-content:space-between;';
                    d.innerHTML = '<span>Subtotal</span><span class="ltms-cart-subtotal-value"></span>';
                    footer.insertBefore(d, footer.firstChild);
                }
                subtotalEl = document.querySelector('.ltms-cart-subtotal-value');
            }
            if (subtotalEl) subtotalEl.innerHTML = data.total_formatted;
        }
        // v2.9.206: Update BOTH drawer containers if they exist in the DOM.
        // Before, this used a short-circuit || which always picked the hidden
        // static #ltms-drawer-items container (always present via wp_footer)
        // and NEVER updated the visible dynamic #ltms-cart-drawer-items one.
        // Result: user saw optimistic qty update but no item list refresh.
        var containers = [];
        var c1 = document.querySelector('#ltms-cart-drawer-items');
        if (c1) containers.push(c1);
        var c2 = document.querySelector('#ltms-drawer-items');
        if (c2 && containers.indexOf(c2) === -1) containers.push(c2);
        if (!containers.length) {
            window.LTMS_CART.log('reload: no containers found');
            return;
        }
        window.LTMS_CART.log('reload: ' + containers.length + ' container[s], ' + (data.items ? data.items.length : 0) + ' item[s]');
        var html;
        if (!data.items || !data.items.length) {
            html = '<div style="text-align:center;padding:40px 20px;"><p>Tu carrito esta vacio</p></div>';
            for (var i = 0; i < containers.length; i++) containers[i].innerHTML = html;
            return;
        }
        html = data.items.map(function(item){
            var k = LTMS_CART.esc(item.key);
            return '<div class="ltms-cart-item" data-cart-item-key="'+k+'">' +
                '<div class="ltms-cart-item-img">'+(item.image?'<img src="'+LTMS_CART.esc(item.image)+'" alt="'+LTMS_CART.esc(item.name)+'" loading="lazy">':'<div>&#128230;</div>')+'</div>' +
                '<div class="ltms-cart-item-info">' +
                '<a href="'+LTMS_CART.esc(item.product_url||'#')+'" class="ltms-cart-item-name">'+LTMS_CART.esc(item.name)+'</a>' +
                '<div class="ltms-cart-item-price">'+(item.price_formatted||'')+'</div>' +
                '<div class="ltms-cart-item-qty">' +
                '<button type="button" class="ltms-cart-qty-btn ltms-cart-qty-dec" data-key="'+k+'">&#8722;</button>' +
                '<span class="ltms-cart-qty-value">'+item.quantity+'</span>' +
                '<button type="button" class="ltms-cart-qty-btn ltms-cart-qty-inc" data-key="'+k+'">+</button>' +
                '</div></div>' +
                '<button type="button" class="ltms-cart-item-remove" data-key="'+k+'" aria-label="Eliminar">&#10005;</button>' +
                '</div>';
        }).join('');
        for (var j = 0; j < containers.length; j++) containers[j].innerHTML = html;
    }, function(err, status) {
        window.LTMS_CART.err('reload failed', err);
    });
};
window.LTMS_CART.esc = function(str){
    if(!str) return '';
    return String(str).replace(/[&<>"']/g, function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});
};
JS;
    }

    /**
     * Desactiva el redirect a /cart después de add-to-cart (usamos drawer).
     */
    public static function disable_cart_redirect(): bool {
        return false;
    }

    /**
     * Añade un fragment para que el JS sepa que el carrito cambió.
     */
    public static function add_drawer_fragment( array $fragments ): array {
        $fragments['div.ltms-drawer-fragments'] = '<div class="ltms-drawer-fragments" data-cart-count="' . esc_attr( WC()->cart ? WC()->cart->get_cart_contents_count() : 0 ) . '"></div>';
        return $fragments;
    }

    /**
     * Renderiza el HTML del drawer en el footer.
     */
    public static function render_drawer_html(): void {
        ?>
        <!-- Drawer Overlay -->
        <div id="ltms-cart-drawer-overlay" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:99998;opacity:0;transition:opacity 0.3s;"></div>

        <!-- Cart Drawer -->
        <div id="ltms-cart-drawer" style="position:fixed;top:0;right:-450px;width:100%;max-width:420px;height:100vh;background:#fff;z-index:99999;box-shadow:-4px 0 20px rgba(0,0,0,0.15);transition:right 0.3s ease-in-out;display:flex;flex-direction:column;">

            <!-- Header -->
            <div style="padding:16px 20px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;background:#fff;">
                <h3 style="margin:0;font-size:16px;font-weight:700;">
                    &#x1F6D2; <?php esc_html_e( 'Tu carrito', 'ltms' ); ?>
                    <span id="ltms-drawer-count" style="font-size:13px;color:#6b7280;font-weight:400;"></span>
                </h3>
                <button type="button" id="ltms-drawer-close" style="background:none;border:none;font-size:24px;cursor:pointer;color:#6b7280;padding:0;width:32px;height:32px;line-height:1;">&times;</button>
            </div>

            <!-- Free Shipping Progress Bar -->
            <div id="ltms-drawer-shipping-bar" style="padding:12px 20px;background:#f0fdf4;border-bottom:1px solid #d1fae5;"></div>

            <!-- Countdown Timer -->
            <div id="ltms-drawer-countdown" style="padding:8px 20px;background:#fffbeb;border-bottom:1px solid #fde68a;font-size:12px;color:#92400e;text-align:center;display:none;"></div>

            <!-- Cart Items (scrollable) -->
            <div id="ltms-drawer-items" style="flex:1;overflow-y:auto;padding:0 20px;"></div>

            <!-- Upsells -->
            <div id="ltms-drawer-upsells" style="padding:12px 20px;border-top:1px solid #e5e7eb;background:#f9fafb;display:none;"></div>

            <!-- Footer -->
            <div id="ltms-drawer-footer" style="padding:16px 20px;border-top:1px solid #e5e7eb;background:#fff;"></div>
        </div>
        <?php
        // v2.9.54: El script inline ahora se registra con add_action('wp_footer', 1)
        // para que se renderice con prioridad alta. No se llama aquí para evitar
        // doble renderizado.
    }

    /**
     * v2.9.59: Método legacy eliminado — ahora se usa output buffering.
     * El script se inyecta vía inject_cart_script_into_html() que SiteGround
     * no puede remover porque modifica el HTML final después de todos los
     * plugins de optimización.
     */

    /**
     * AJAX: refresca todo el contenido del drawer.
     *
     * PERF v2.9.49: skip_heavy_data por defecto. El frontend puede pasar
     * ?full=1 para obtener upsells y badges.
     *
     * v2.9.52: Guests permitidos (carrito funciona sin login).
     * Nonce: ltms_ux_nonce (el que el JS ltms-ux-enhancements envía).
     */
    public static function ajax_refresh_drawer(): void {
        if ( ! check_ajax_referer( 'ltms_ux_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Token inválido.', 'ltms' ) ], 403 );
        }
        // v2.9.123 CHECKOUT-AUDIT P1-1 FIX: rate limit drawer refresh.
        // Before, bots could spam this endpoint → high CPU from get_drawer_data.
        // Now capped at 30 per IP per minute.
        $ip = method_exists( 'LTMS_Core_Security', 'get_client_ip_safe' ) ? LTMS_Core_Security::get_client_ip_safe() : ( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
        $rl_key = 'ltms_drawer_rl_' . md5( $ip );
        $rl_count = (int) get_transient( $rl_key );
        if ( $rl_count > 30 ) {
            wp_send_json_error( [ 'message' => __( 'Demasiadas solicitudes.', 'ltms' ) ], 429 );
        }
        set_transient( $rl_key, $rl_count + 1, MINUTE_IN_SECONDS );

        $full = isset( $_POST['full'] ) && $_POST['full'] === '1';
        wp_send_json_success( self::get_drawer_data( ! $full ) );
    }

    /**
     * AJAX: elimina un item del carrito.
     *
     * v2.9.52: Guests permitidos. Nonce: ltms_ux_nonce.
     */
    public static function ajax_remove_item(): void {
        if ( ! check_ajax_referer( 'ltms_ux_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Token inválido.', 'ltms' ) ], 403 );
        }
        // v2.9.207: Ensure WC()->cart is initialized for guests in AJAX context.
        if ( function_exists( 'WC' ) && WC()->cart && method_exists( WC()->cart, 'get_cart' ) && empty( WC()->cart->get_cart() ) && WC()->session ) {
            WC()->cart->get_cart_from_session();
        }
        $cart_item_key = sanitize_text_field( $_POST['cart_item_key'] ?? '' );
        if ( $cart_item_key && WC()->cart ) {
            WC()->cart->remove_cart_item( $cart_item_key );
            // v2.9.207: Force session + totals save so the change persists
            // across the immediately-following ltms_get_cart AJAX call.
            if ( WC()->session ) {
                WC()->cart->set_session();
                WC()->cart->calculate_totals();
            }
        }
        wp_send_json_success( self::get_drawer_data( true ) );
    }

    /**
     * AJAX: actualiza cantidad.
     *
     * v2.9.52: Guests permitidos. Nonce: ltms_ux_nonce.
     */
    public static function ajax_update_qty(): void {
        if ( ! check_ajax_referer( 'ltms_ux_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Token inválido.', 'ltms' ) ], 403 );
        }
        // v2.9.207: Ensure WC()->cart is initialized for guests in AJAX context.
        if ( function_exists( 'WC' ) && WC()->cart && method_exists( WC()->cart, 'get_cart' ) && empty( WC()->cart->get_cart() ) && WC()->session ) {
            WC()->cart->get_cart_from_session();
        }
        $cart_item_key = sanitize_text_field( $_POST['cart_item_key'] ?? '' );
        $qty = (int) ( $_POST['qty'] ?? 1 );
        // v2.9.123 CHECKOUT-AUDIT P0-2 FIX: bound qty to reasonable max.
        $qty = max( 1, min( $qty, 999 ) );
        if ( $cart_item_key && WC()->cart ) {
            $result = WC()->cart->set_quantity( $cart_item_key, $qty, true );
            // v2.9.207: Force session + totals save so the change persists
            // across the immediately-following ltms_get_cart AJAX call.
            // set_quantity with $refresh_totals=true SHOULD do this, but some
            // WC versions don't save to session immediately for guests.
            if ( WC()->session ) {
                WC()->cart->set_session();
                WC()->cart->calculate_totals();
            }
        }
        wp_send_json_success( self::get_drawer_data( true ) );
    }

    /**
     * Construye todos los datos del drawer para AJAX.
     *
     * PERF v2.9.49: $skip_heavy_data = true omite los upsells (WP_Query costosa)
     * y los badges de pago. Usar en el flujo de add-to-cart para respuesta rápida.
     * El drawer completo se carga después con $skip_heavy_data = false si el
     * usuario lo solicita explícitamente.
     */
    private static function get_drawer_data( bool $skip_heavy_data = false ): array {
        $cart = WC()->cart;
        if ( ! $cart ) return [ 'items_html' => '', 'count' => 0, 'subtotal' => '0' ];

        $items = [];
        $vendor_ids = [];

        // v2.9.49: Cache de vendor names para evitar get_userdata() por item.
        $vendor_name_cache = [];

        foreach ( $cart->get_cart() as $key => $item ) {
            $product = $item['data'];
            $vendor_id = (int) get_post_field( 'post_author', $item['product_id'] );
            $vendor_ids[ $vendor_id ] = true;

            // Cache de vendor name
            if ( ! isset( $vendor_name_cache[ $vendor_id ] ) ) {
                $vendor_name_cache[ $vendor_id ] = self::get_vendor_name( $vendor_id );
            }

            $items[] = [
                'key' => $key,
                'name' => $product->get_name(),
                'price' => $product->get_price_html(),
                'image' => $product->get_image( 'thumbnail' ),
                'qty' => $item['quantity'],
                'permalink' => $product->get_permalink(),
                'subtotal' => wc_price( $item['line_subtotal'] ),
                'vendor_name' => $vendor_name_cache[ $vendor_id ],
            ];
        }

        // Free shipping bar data (liviana, siempre se calcula).
        $shipping_bar = self::get_shipping_bar_data( $cart );

        // v2.9.49: Skip upsells si es un refresco rápido (add-to-cart).
        // Los upsells hacen 1 WP_Query por vendor (hasta 3) — muy costoso.
        $upsells = $skip_heavy_data ? [] : self::get_upsell_products( array_keys( $vendor_ids ), $cart );

        return [
            'count' => $cart->get_cart_contents_count(),
            'subtotal' => wc_price( $cart->get_cart_subtotal() ),
            'shipping_bar' => $shipping_bar,
            'items' => $items,
            'upsells' => $upsells,
            'cart_url' => wc_get_cart_url(),
            'checkout_url' => wc_get_checkout_url(),
            'payment_badges' => $skip_heavy_data ? [] : self::get_payment_badges(),
            'checkout_note' => self::get_checkout_note(),
            'tnc_required' => self::is_tnc_required(),
            'tnc_url' => LTMS_Core_Config::get( 'ltms_terms_url', '' ),
            'tnc_version' => class_exists( 'LTMS_Legal_Compliance' ) ? LTMS_Legal_Compliance::TERMS_VERSION : '1.0',
        ];
    }

    /**
     * F6: Obtiene los badges de métodos de pago activos.
     * Muestra iconos de Visa, Mastercard, PSE, Nequi, etc. según gateways activos.
     */
    private static function get_payment_badges(): array {
        $badges = [];
        $country = LTMS_Core_Config::get_country();

        // Stripe (tarjetas internacionales).
        if ( LTMS_Core_Config::get( 'ltms_stripe_enabled', 'no' ) === 'yes' ) {
            $badges[] = [ 'name' => 'Visa', 'icon' => '&#x1F4B3;' ];
            $badges[] = [ 'name' => 'Mastercard', 'icon' => '&#x1F4B3;' ];
            $badges[] = [ 'name' => 'Amex', 'icon' => '&#x1F4B3;' ];
        }

        // Openpay (México + Colombia).
        if ( LTMS_Core_Config::get( 'ltms_openpay_enabled', 'no' ) === 'yes' ) {
            $badges[] = [ 'name' => 'Openpay', 'icon' => '&#x1F4F1;' ];
        }

        // PSE (Colombia).
        if ( $country === 'CO' ) {
            $badges[] = [ 'name' => 'PSE', 'icon' => '&#x1F4F1;' ];
        }

        // Nequi / Daviplata (Colombia).
        if ( $country === 'CO' ) {
            $badges[] = [ 'name' => 'Nequi', 'icon' => '&#x1F4F1;' ];
            $badges[] = [ 'name' => 'Daviplata', 'icon' => '&#x1F4F1;' ];
        }

        // Addi (BNPL Colombia + México).
        if ( LTMS_Core_Config::get( 'ltms_addi_enabled', 'no' ) === 'yes' ) {
            $badges[] = [ 'name' => 'Addi', 'icon' => '&#x1F4B5;' ];
        }

        // PayPal (si está activo via Stripe).
        if ( LTMS_Core_Config::get( 'ltms_stripe_enabled', 'no' ) === 'yes' ) {
            $badges[] = [ 'name' => 'PayPal', 'icon' => '&#x1F4B3;' ];
        }

        return $badges;
    }

    /**
     * F5: Obtiene el note informativo debajo del botón de checkout.
     */
    private static function get_checkout_note(): string {
        $country = LTMS_Core_Config::get_country();
        $currency = LTMS_Core_Config::get_currency();

        if ( $country === 'MX' ) {
            return __( 'Impuestos incluidos. Envío calculado al finalizar la compra.', 'ltms' );
        }

        return __( 'IVA incluido. Envío calculado al finalizar la compra.', 'ltms' );
    }

    /**
     * F3: Verifica si el checkbox de T&C es obligatorio.
     * Conecta con LTMS_Legal_Compliance para respetar la config.
     */
    private static function is_tnc_required(): bool {
        if ( ! class_exists( 'LTMS_Legal_Compliance' ) ) return false;
        return LTMS_Core_Config::get( 'ltms_require_tnc_at_checkout', 'yes' ) === 'yes';
    }

    /**
     * Datos de la barra de envío gratis.
     */
    private static function get_shipping_bar_data( \WC_Cart $cart ): array {
        $subtotal = (float) $cart->get_cart_subtotal();

        // Obtener threshold de envío gratis.
        $threshold = 0;
        if ( class_exists( 'LTMS_Shipping_Mode' ) ) {
            $threshold = (float) LTMS_Core_Config::get( 'ltms_shipping_hybrid_threshold', 100000 );
        }

        if ( $threshold <= 0 ) {
            return [ 'show' => false ];
        }

        $remaining = max( 0, $threshold - $subtotal );
        $pct = min( 100, ( $subtotal / $threshold ) * 100 );

        return [
            'show' => true,
            'threshold' => $threshold,
            'remaining' => $remaining,
            'percentage' => $pct,
            'threshold_formatted' => wc_price( $threshold ),
            'remaining_formatted' => wc_price( $remaining ),
            'message' => $remaining > 0
                ? sprintf( __( 'Te faltan %s para envío gratis', 'ltms' ), wc_price( $remaining ) )
                : __( '&#x1F389; ¡Tienes envío gratis!', 'ltms' ),
        ];
    }

    /**
     * Obtiene productos de upsell: del mismo vendor, no en carrito, mismo categoría.
     */
    private static function get_upsell_products( array $vendor_ids, \WC_Cart $cart ): array {
        if ( empty( $vendor_ids ) ) return [];

        // IDs ya en carrito.
        $in_cart = [];
        foreach ( $cart->get_cart() as $item ) {
            $in_cart[] = (int) $item['product_id'];
        }

        $upsells = [];
        foreach ( array_slice( $vendor_ids, 0, 3 ) as $vid ) {
            $query = new \WP_Query( [
                'post_type' => 'product',
                'post_status' => 'publish',
                'posts_per_page' => 3,
                'author' => $vid,
                'post__not_in' => $in_cart,
                'fields' => 'ids',
                'orderby' => 'rand',
            ] );

            foreach ( $query->posts as $pid ) {
                $p = wc_get_product( $pid );
                if ( ! $p || $p->get_price() <= 0 ) continue;
                $upsells[] = [
                    'id' => $pid,
                    'name' => $p->get_name(),
                    'price' => $p->get_price_html(),
                    'image' => $p->get_image( 'thumbnail' ),
                    'permalink' => $p->get_permalink(),
                    'add_to_cart_url' => '?add-to-cart=' . $pid,
                ];
                if ( count( $upsells ) >= 5 ) break 2;
            }
        }

        return $upsells;
    }

    /**
     * Obtiene el nombre del vendor.
     */
    private static function get_vendor_name( int $vendor_id ): string {
        $user = get_userdata( $vendor_id );
        return $user ? $user->display_name : '';
    }
}
