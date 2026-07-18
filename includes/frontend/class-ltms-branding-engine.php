<?php
/**
 * LTMS Branding Engine — Logo en Google Search + psicología de color +
 * gatillos mentales de conversión.
 *
 * v2.9.30 — Optimiza branding para:
 *   1. Logo visible en Google Knowledge Panel (Organization schema + logo)
 *   2. Favicon / Apple Touch Icon / MS Tile
 *   3. Psicología de color aplicada a CTAs, trust signals, urgency
 *   4. Gatillos mentales: urgencia, escasez, prueba social, reciprocidad,
 *      compromiso, aversión a la pérdida, anclaje
 *
 * @package LTMS
 * @version 2.9.30
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LTMS_Branding_Engine {

    /**
     * Paleta de colores basada en psicología de color para e-commerce.
     *
     * AZUL (#1e40af / #2563eb): Confianza, seguridad, profesionalismo.
     *   → Usar en: header, links, botones de pago, logos, trust badges.
     *
     * VERDE (#16a34a): Éxito, ahorro, "sí se puede", confirmación.
     *   → Usar en: precios, "envío gratis", "compra exitosa", savings.
     *
     * ROJO (#dc2626): Urgencia, peligro, acción inmediata.
     *   → Usar en: countdown timers, "¡Solo quedan N!", errores, "oferta termina".
     *
     * AMARILLO/NARANJA (#f59e0b): Atención, entusiasmo, CTA secundario.
     *   → Usar en: badges de oferta, "nuevo", banners, propinas.
     *
     * GRIS (#6b7280): Neutralidad, texto secundario, separadores.
     *   → Usar en: descripciones largas, "hace X min", desactivados.
     *
     * MORADO OSCURO (#1A1A4E / #2D2D6E): Premium, exclusividad, marca.
     *   → Usar en: headers premium, gradientes de marca, footer.
     */
    public const COLOR_PALETTE = [
        'primary'        => '#1e40af',  // Azul confianza — botones de pago.
        'primary_hover'  => '#1e3a8a',
        'primary_light'  => '#dbeafe',
        'success'        => '#16a34a',  // Verde — precios, savings, confirmaciones.
        'success_light'  => '#dcfce7',
        'danger'         => '#dc2626',  // Rojo — urgencia, errores, stock bajo.
        'danger_light'   => '#fee2e2',
        'warning'        => '#f59e0b',  // Amarillo — ofertas, badges, atención.
        'warning_light'  => '#fef3c7',
        'premium'        => '#1A1A4E',  // Morado oscuro — branding premium.
        'premium_light'  => '#2D2D6E',
        'neutral'        => '#6b7280',  // Gris — texto secundario.
        'neutral_light'  => '#f3f4f6',
        'white'          => '#ffffff',
        'dark'           => '#1e293b',
    ];

    /**
     * Gatillos mentales de conversión.
     */
    public const MENTAL_TRIGGERS = [
        'urgency' => [
            'name'        => 'Urgencia',
            'description' => 'Tiempo limitado → acción inmediata',
            'color'       => '#dc2626',
            'elements'    => [ 'countdown_timer', 'flash_sale_badge', 'cart_reservation' ],
        ],
        'scarcity' => [
            'name'        => 'Escasez',
            'description' => 'Stock limitado → miedo a perder',
            'color'       => '#dc2626',
            'elements'    => [ 'stock_counter', 'only_n_left', 'limited_edition' ],
        ],
        'social_proof' => [
            'name'        => 'Prueba Social',
            'description' => 'Otros compraron → yo también debería',
            'color'       => '#16a34a',
            'elements'    => [ 'recent_purchase_toast', 'viewer_count', 'reviews', 'rating_stars' ],
        ],
        'authority' => [
            'name'        => 'Autoridad',
            'description' => 'Verificado, certificado → confianza',
            'color'       => '#1e40af',
            'elements'    => [ 'kyc_verified_badge', 'sagrilaft_badge', 'ley_1480_badge', 'rnt_badge' ],
        ],
        'reciprocity' => [
            'name'        => 'Reciprocidad',
            'description' => 'Regalo primero → cliente devuelve favor',
            'color'       => '#f59e0b',
            'elements'    => [ 'free_shipping_threshold', 'welcome_discount', 'tip_option', 'donation' ],
        ],
        'loss_aversion' => [
            'name'        => 'Aversión a la Pérdida',
            'description' => 'Pierdes $X si no actúas → acción',
            'color'       => '#dc2626',
            'elements'    => [ 'price_increase_warning', 'cart_expiry', 'lost_savings_display' ],
        ],
        'anchoring' => [
            'name'        => 'Anclaje',
            'description' => 'Precio original tachado → percepción de ahorro',
            'color'       => '#16a34a',
            'elements'    => [ 'sale_price_strikethrough', 'savings_amount', 'was_now_pricing' ],
        ],
        'commitment' => [
            'name'        => 'Compromiso',
            'description' => 'Pequeño sí primero → sí mayor después',
            'color'       => '#1e40af',
            'elements'    => [ 'wishlist', 'newsletter_signup', 'free_account', 'trial' ],
        ],
    ];

    // ================================================================
    // INIT.
    // ================================================================

    public static function init(): void {
        // BR-1: Organization schema con logo para Google Knowledge Panel.
        add_filter( 'ltms_organization_schema', [ __CLASS__, 'enhance_organization_schema_with_logo' ] );

        // BR-2: Meta tags de favicon / apple touch icon / MS tile.
        add_action( 'wp_head', [ __CLASS__, 'inject_brand_meta_tags' ], 1 );

        // BR-3: CSS variables de psicología de color.
        add_action( 'wp_head', [ __CLASS__, 'inject_color_psychology_css' ], 2 );
        add_action( 'wp_head', [ __CLASS__, 'inject_mental_trigger_css' ], 3 );

        // BR-4: Open Graph image con logo (no solo site icon).
        add_filter( 'ltms_og_data', [ __CLASS__, 'ensure_logo_in_og_image' ] );

        // BR-5: Trust signals estratégicos en checkout.
        add_action( 'woocommerce_checkout_before_customer_details', [ __CLASS__, 'render_checkout_trust_signals' ] );

        // BR-6: Loss aversion en carrito.
        add_action( 'woocommerce_after_cart_totals', [ __CLASS__, 'render_loss_aversion_message' ] );

        // BR-7: Reciprocidad — welcome discount banner.
        add_action( 'wp_footer', [ __CLASS__, 'render_welcome_discount_banner' ], 5 );

        // BR-8: Anchoring — savings display en PDP.
        add_action( 'woocommerce_single_product_summary', [ __CLASS__, 'render_savings_display' ], 15 );
    }

    // ================================================================
    // BR-1: ORGANIZATION SCHEMA CON LOGO.
    // ================================================================

    /**
     * Enriquece el Organization schema con logo, misma As, contactPoint.
     *
     * Esto hace que Google muestre el logo en el Knowledge Panel
     * y en los resultados de búsqueda (no solo un link).
     */
    public static function enhance_organization_schema_with_logo( array $schema ): array {
        $logo_url = self::get_logo_url( 'white' );
        $logo_dark = self::get_logo_url( 'dark' );

        // Logo: Schema.org requiere URL absoluta + dimensión mínima 112x112.
        $schema['logo'] = $logo_url;
        $schema['image'] = $logo_url; // image = logo para Knowledge Panel.

        // SameAs: redes sociales (ayuda a Google a conectar marca).
        $schema['sameAs'] = array_values( array_filter( [
            LTMS_Core_Config::get( 'ltms_social_facebook', '' ),
            LTMS_Core_Config::get( 'ltms_social_instagram', '' ),
            LTMS_Core_Config::get( 'ltms_social_twitter', '' ),
            LTMS_Core_Config::get( 'ltms_social_linkedin', '' ),
            LTMS_Core_Config::get( 'ltms_social_youtube', '' ),
            LTMS_Core_Config::get( 'ltms_social_tiktok', '' ),
        ] ) );

        // ContactPoint: teléfono/email de soporte (aparece en Knowledge Panel).
        $schema['contactPoint'] = [
            '@type'       => 'ContactPoint',
            'telephone'   => LTMS_Core_Config::get( 'ltms_contact_phone', '' ),
            'contactType' => 'customer service',
            'email'       => LTMS_Core_Config::get( 'ltms_contact_email', get_option( 'admin_email' ) ),
            'areaServed'  => [ 'CO', 'MX' ],
            'availableLanguage' => [ 'Spanish' ],
        ];

        // Address: dirección física (aparece en Knowledge Panel + Maps).
        $schema['address'] = [
            '@type'           => 'PostalAddress',
            'streetAddress'   => LTMS_Core_Config::get( 'ltms_store_address', '' ),
            'addressLocality' => LTMS_Core_Config::get( 'ltms_store_city', 'Cali' ),
            'addressRegion'   => LTMS_Core_Config::get( 'ltms_store_state', 'Valle del Cauca' ),
            'postalCode'      => LTMS_Core_Config::get( 'ltms_store_zip', '' ),
            'addressCountry'  => LTMS_Core_Config::get_country(),
        ];

        // Founder / CEO (aparece en Knowledge Panel).
        $schema['founder'] = [
            '@type' => 'Person',
            'name'  => LTMS_Core_Config::get( 'ltms_founder_name', '' ),
        ];

        // foundingDate.
        $schema['foundingDate'] = LTMS_Core_Config::get( 'ltms_founding_date', '2024' );

        // numberOfEmployees (rango).
        $schema['numberOfEmployees'] = [
            '@type'    => 'QuantitativeValue',
            'minValue' => 10,
            'maxValue' => 50,
        ];

        // slogan.
        $schema['slogan'] = LTMS_Core_Config::get( 'ltms_brand_slogan', 'Compra con confianza, vende sin límites' );

        return $schema;
    }

    // ================================================================
    // BR-2: META TAGS DE FAVICON / APPLE TOUCH / MS TILE.
    // ================================================================

    /**
     * Inyecta meta tags de favicon, apple-touch-icon, MS tile.
     *
     * Sin estos tags, Google y navegadores muestran un icono genérico
     * en lugar del logo de marca.
     */
    public static function inject_brand_meta_tags(): void {
        $logo_url = self::get_logo_url( 'white' );
        $logo_dark = self::get_logo_url( 'dark' );

        // Favicon (16x16, 32x32).
        echo '<link rel="icon" type="image/jpeg" href="' . esc_url( $logo_url ) . '" sizes="32x32" />' . "\n";
        echo '<link rel="icon" type="image/jpeg" href="' . esc_url( $logo_url ) . '" sizes="16x16" />' . "\n";

        // Apple Touch Icon (180x180 — iOS home screen).
        echo '<link rel="apple-touch-icon" href="' . esc_url( $logo_url ) . '" />' . "\n";

        // MS Tile (Windows Start Menu).
        echo '<meta name="msapplication-TileImage" content="' . esc_url( $logo_url ) . '" />' . "\n";
        echo '<meta name="msapplication-TileColor" content="' . esc_attr( self::COLOR_PALETTE['premium'] ) . '" />' . "\n";

        // Theme color (mobile browser UI bar).
        echo '<meta name="theme-color" content="' . esc_attr( self::COLOR_PALETTE['primary'] ) . '" />' . "\n";

        // Mask icon (Safari pinned tab).
        echo '<link rel="mask-icon" href="' . esc_url( $logo_url ) . '" color="' . esc_attr( self::COLOR_PALETTE['primary'] ) . '" />' . "\n";

        // Manifest link (PWA) — v2.9.99 fix: apuntar al manifest real del plugin (no a home_url que no existe).
        $manifest_url = defined( 'LTMS_ASSETS_URL' ) ? LTMS_ASSETS_URL . 'json/manifest.json' : '';
        if ( $manifest_url ) {
            echo '<link rel="manifest" href="' . esc_url( $manifest_url ) . '" />' . "\n";
        }

        // og:logo (Facebook认可的 marca logo).
        echo '<meta property="og:logo" content="' . esc_url( $logo_url ) . '" />' . "\n";
    }

    // ================================================================
    // BR-3: CSS VARIABLES DE PSICOLOGÍA DE COLOR.
    // ================================================================

    /**
     * Inyecta CSS variables con la paleta de colores basada en psicología.
     *
     * Todos los componentes del plugin pueden usar var(--ltms-color-primary)
     * en lugar de hardcoded colors, permitiendo branding consistente.
     */
    public static function inject_color_psychology_css(): void {
        $p = self::COLOR_PALETTE;
        ?>
        <style id="ltms-color-psychology">
        :root {
            /* Azul confianza — botones de pago, links, header */
            --ltms-color-primary: <?php echo esc_html( $p['primary'] ); ?>;
            --ltms-color-primary-hover: <?php echo esc_html( $p['primary_hover'] ); ?>;
            --ltms-color-primary-light: <?php echo esc_html( $p['primary_light'] ); ?>;

            /* Verde éxito — precios, savings, confirmaciones */
            --ltms-color-success: <?php echo esc_html( $p['success'] ); ?>;
            --ltms-color-success-light: <?php echo esc_html( $p['success_light'] ); ?>;

            /* Rojo urgencia — countdown, stock bajo, errores */
            --ltms-color-danger: <?php echo esc_html( $p['danger'] ); ?>;
            --ltms-color-danger-light: <?php echo esc_html( $p['danger_light'] ); ?>;

            /* Amarillo atención — ofertas, badges, novedades */
            --ltms-color-warning: <?php echo esc_html( $p['warning'] ); ?>;
            --ltms-color-warning-light: <?php echo esc_html( $p['warning_light'] ); ?>;

            /* Morado premium — branding, gradientes */
            --ltms-color-premium: <?php echo esc_html( $p['premium'] ); ?>;
            --ltms-color-premium-light: <?php echo esc_html( $p['premium_light'] ); ?>;

            /* Neutros */
            --ltms-color-neutral: <?php echo esc_html( $p['neutral'] ); ?>;
            --ltms-color-neutral-light: <?php echo esc_html( $p['neutral_light'] ); ?>;
            --ltms-color-dark: <?php echo esc_html( $p['dark'] ); ?>;
            --ltms-color-white: <?php echo esc_html( $p['white'] ); ?>;

            /* Gradientes psicológicos */
            --ltms-gradient-trust: linear-gradient(135deg, <?php echo esc_html( $p['primary'] ); ?>, <?php echo esc_html( $p['primary_hover'] ); ?>);
            --ltms-gradient-premium: linear-gradient(135deg, <?php echo esc_html( $p['premium'] ); ?>, <?php echo esc_html( $p['premium_light'] ); ?>);
            --ltms-gradient-urgency: linear-gradient(135deg, <?php echo esc_html( $p['danger'] ); ?>, #991b1b);
            --ltms-gradient-success: linear-gradient(135deg, <?php echo esc_html( $p['success'] ); ?>, #15803d);

            /* Sombras psicológicas */
            --ltms-shadow-soft: 0 2px 8px rgba(0,0,0,0.08);
            --ltms-shadow-trust: 0 4px 16px rgba(30,64,175,0.15);
            --ltms-shadow-urgency: 0 4px 16px rgba(220,38,38,0.2);

            /* Radios */
            --ltms-radius-sm: 4px;
            --ltms-radius-md: 8px;
            --ltms-radius-lg: 12px;
            --ltms-radius-pill: 24px;

            /* Transiciones */
            --ltms-transition-fast: 0.15s ease;
            --ltms-transition-normal: 0.3s ease;
            --ltms-transition-bounce: 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55);
        }

        /* Override de botones WC con psicología de color */
        .ltms-btn-primary, .woocommerce #place_order, .woocommerce a.button.alt {
            background: var(--ltms-color-primary) !important;
            color: var(--ltms-color-white) !important;
            border-radius: var(--ltms-radius-md) !important;
            font-weight: 700 !important;
            transition: all var(--ltms-transition-fast) !important;
            box-shadow: var(--ltms-shadow-trust) !important;
        }
        .ltms-btn-primary:hover, .woocommerce #place_order:hover, .woocommerce a.button.alt:hover {
            background: var(--ltms-color-primary-hover) !important;
            transform: translateY(-1px) !important;
            box-shadow: 0 6px 20px rgba(30,64,175,0.25) !important;
        }

        /* Precios en verde (psicología: verde = ahorro, éxito) */
        .ltms-price, .woocommerce-Price-amount.amount {
            color: var(--ltms-color-success) !important;
            font-weight: 700 !important;
        }

        /* "Envío gratis" en verde con icono */
        .ltms-free-shipping-badge {
            background: var(--ltms-color-success-light) !important;
            color: var(--ltms-color-success) !important;
            border: 1px solid var(--ltms-color-success) !important;
        }

        /* Urgencia: countdown timers en rojo */
        .ltms-flash-countdown, .ltms-cart-countdown {
            color: var(--ltms-color-danger) !important;
            font-weight: 900 !important;
            font-family: monospace !important;
        }

        /* Ofertas: precio tachado en gris, precio nuevo en verde */
        .ltms-sale-price {
            color: var(--ltms-color-success) !important;
        }
        del .woocommerce-Price-amount {
            color: var(--ltms-color-neutral) !important;
            text-decoration: line-through !important;
            opacity: 0.6 !important;
        }

        /* Trust badges en azul */
        .ltms-trust-badge {
            background: var(--ltms-color-primary-light) !important;
            color: var(--ltms-color-primary) !important;
            border: 1px solid var(--ltms-color-primary) !important;
            border-radius: var(--ltms-radius-pill) !important;
        }

        /* Premium gradient para headers de marca */
        .ltms-premium-header {
            background: var(--ltms-gradient-premium) !important;
            color: var(--ltms-color-white) !important;
        }
        </style>
        <?php
    }

    // ================================================================
    // BR-3b: CSS DE GATILLOS MENTALES.
    // ================================================================

    /**
     * Inyecta CSS específico para gatillos mentales de conversión.
     */
    public static function inject_mental_trigger_css(): void {
        ?>
        <style id="ltms-mental-triggers">
        /* URGENCIA: animación pulse para elementos de tiempo limitado */
        .ltms-urgency-pulse {
            animation: ltms-urgency-pulse 1.5s infinite;
        }
        @keyframes ltms-urgency-pulse {
            0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(220,38,38,0.4); }
            50% { transform: scale(1.02); box-shadow: 0 0 0 8px rgba(220,38,38,0); }
        }

        /* ESCASEZ: barra de progreso que se vacía */
        .ltms-scarcity-bar {
            background: var(--ltms-color-danger);
            height: 6px;
            border-radius: 3px;
            transition: width 1s ease;
        }
        .ltms-scarcity-bar.low { background: var(--ltms-color-danger); animation: ltms-scarcity-blink 1s infinite; }
        @keyframes ltms-scarcity-blink { 50% { opacity: 0.5; } }

        /* PRUEBA SOCIAL: toast slide-in */
        .ltms-social-proof-toast {
            animation: ltms-slide-in-left 0.4s cubic-bezier(0.68,-0.55,0.265,1.55);
        }
        @keyframes ltms-slide-in-left {
            from { transform: translateX(-120%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        /* AUTORIDAD: badge con check verde */
        .ltms-authority-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: var(--ltms-color-primary-light);
            color: var(--ltms-color-primary);
            padding: 4px 12px;
            border-radius: var(--ltms-radius-pill);
            font-size: 12px;
            font-weight: 600;
            border: 1px solid var(--ltms-color-primary);
        }
        .ltms-authority-badge::before {
            content: "✓";
            font-weight: 900;
            color: var(--ltms-color-success);
        }

        /* RECIPROCIDAD: gift icon */
        .ltms-reciprocity-gift {
            background: var(--ltms-color-warning-light);
            border: 1px solid var(--ltms-color-warning);
            border-radius: var(--ltms-radius-md);
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .ltms-reciprocity-gift::before {
            content: "🎁";
            font-size: 20px;
        }

        /* AVERSIÓN A LA PÉRDIDA: mensaje en rojo */
        .ltms-loss-aversion {
            color: var(--ltms-color-danger);
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .ltms-loss-aversion::before {
            content: "⚠️";
        }

        /* ANCLAJE: savings display */
        .ltms-anchoring-savings {
            background: var(--ltms-color-success-light);
            color: var(--ltms-color-success);
            padding: 4px 10px;
            border-radius: var(--ltms-radius-pill);
            font-size: 13px;
            font-weight: 700;
            display: inline-block;
        }

        /* COMPROMISO: micro-commitment button */
        .ltms-commitment-btn {
            background: var(--ltms-color-primary-light);
            color: var(--ltms-color-primary);
            border: 2px dashed var(--ltms-color-primary);
            border-radius: var(--ltms-radius-md);
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all var(--ltms-transition-fast);
        }
        .ltms-commitment-btn:hover {
            background: var(--ltms-color-primary);
            color: var(--ltms-color-white);
            border-style: solid;
        }
        </style>
        <?php
    }

    // ================================================================
    // BR-4: OPEN GRAPH IMAGE CON LOGO.
    // ================================================================

    /**
     * Asegura que og:image tenga el logo de marca (no solo site icon).
     */
    public static function ensure_logo_in_og_image( array $og ): array {
        if ( empty( $og['og:image'] ) || $og['og:image'] === get_site_icon_url( 512 ) ) {
            $logo = self::get_logo_url( 'white' );
            if ( $logo ) {
                $og['og:image'] = $logo;
                $og['og:image:width'] = '1200';
                $og['og:image:height'] = '630';
                $og['og:image:alt'] = get_bloginfo( 'name' ) . ' — Logo oficial';
            }
        }
        return $og;
    }

    // ================================================================
    // BR-5: TRUST SIGNALS EN CHECKOUT.
    // ================================================================

    /**
     * Renderiza trust signals estratégicos en checkout.
     *
     * Gatillo: AUTORIDAD (badges de verificación) + SEGURIDAD (cifrado).
     */
    public static function render_checkout_trust_signals(): void {
        ?>
        <div class="ltms-checkout-trust" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;justify-content:center;">
            <span class="ltms-authority-badge">KYC Verificado</span>
            <span class="ltms-authority-badge">Ley 1480/2011</span>
            <span class="ltms-authority-badge">Pago Cifrado AES-256</span>
            <span class="ltms-authority-badge">PCI DSS SAQ-A</span>
            <span class="ltms-authority-badge">SAGRILAFT</span>
        </div>
        <div style="text-align:center;font-size:12px;color:var(--ltms-color-neutral);margin-bottom:16px;">
            🔒 <?php esc_html_e( 'Tu pago está protegido con cifrado bancario. Derecho de retracto garantizado.', 'ltms' ); ?>
        </div>
        <?php
    }

    // ================================================================
    // BR-6: LOSS AVERSION EN CARRITO.
    // ================================================================

    /**
     * Renderiza mensaje de aversión a la pérdida en carrito.
     *
     * Gatillo: AVERSIÓN A LA PÉRDIDA ("pierdes $X en envío si no agregas más").
     */
    public static function render_loss_aversion_message(): void {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) return;

        $country = LTMS_Core_Config::get_country();
        $threshold = LTMS_Sales_Booster::FREE_SHIPPING_THRESHOLDS[ $country ] ?? 150000;
        $cart_total = (float) WC()->cart->get_cart_contents_total();
        $remaining = max( 0, $threshold - $cart_total );

        if ( $remaining <= 0 ) return;

        $shipping_cost = $country === 'CO' ? 8000 : 50;
        $currency = LTMS_Core_Config::get_currency();

        echo '<div class="ltms-loss-aversion" style="margin-top:12px;text-align:center;">';
        echo wp_kses_post( sprintf(
            /* translators: 1: costo de envío formateado como HTML de precio de WooCommerce, 2: monto restante para envío gratis. */
            __( 'Estás perdiendo %1$s en envío. Agrega %2$s más para envío gratis.', 'ltms' ),
            wc_price( $shipping_cost, [ 'currency' => $currency ] ),
            wc_price( $remaining, [ 'currency' => $currency ] )
        ) );
        echo '</div>';
    }

    // ================================================================
    // BR-7: RECIPROCIDAD — WELCOME DISCOUNT.
    // ================================================================

    /**
     * Banner de bienvenida con descuento (gatillo: reciprocidad).
     *
     * "Te damos 10% off en tu primera compra" → cliente se siente en deuda.
     */
    public static function render_welcome_discount_banner(): void {
        if ( is_admin() || is_user_logged_in() ) return;
        if ( isset( $_COOKIE['ltms_welcome_shown'] ) ) return;
        ?>
        <div id="ltms-welcome-banner" style="position:fixed;top:0;left:0;right:0;background:var(--ltms-gradient-premium);color:#fff;padding:12px 20px;text-align:center;z-index:99999;transform:translateY(-100%);transition:transform 0.5s ease;">
            <span style="font-size:14px;">🎉 <?php esc_html_e( '¡Bienvenido! Usa el código', 'ltms' ); ?>
            <code style="background:rgba(255,255,255,0.2);padding:2px 8px;border-radius:4px;font-weight:bold;">BIENVENIDO10</code>
            <?php esc_html_e( 'para 10% off en tu primera compra', 'ltms' ); ?></span>
            <button id="ltms-welcome-close" style="float:right;background:none;border:none;color:#fff;cursor:pointer;font-size:18px;">×</button>
        </div>
        <script>
        setTimeout(function() {
            document.getElementById('ltms-welcome-banner').style.transform = 'translateY(0)';
            document.cookie = 'ltms_welcome_shown=1; max-age=604800; path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
        }, 3000);
        document.getElementById('ltms-welcome-close').onclick = function() {
            document.getElementById('ltms-welcome-banner').style.transform = 'translateY(-100%)';
        };
        </script>
        <?php
    }

    // ================================================================
    // BR-8: ANCLAJE — SAVINGS DISPLAY EN PDP.
    // ================================================================

    /**
     * Muestra el ahorro en PDP (gatillo: anclaje).
     *
     * "Ahorras $X (Y%)" en verde → refuerza percepción de valor.
     */
    public static function render_savings_display(): void {
        global $product;
        if ( ! $product || ! $product->is_on_sale() ) return;

        $regular = (float) $product->get_regular_price();
        $sale = (float) $product->get_sale_price();
        if ( $regular <= 0 || $sale <= 0 || $sale >= $regular ) return;

        $savings = $regular - $sale;
        $pct = round( ( $savings / $regular ) * 100 );
        $currency = get_woocommerce_currency();

        echo '<div class="ltms-anchoring-savings" style="margin:8px 0;">';
        echo esc_html( sprintf(
            __( '💰 Ahorras %s (%d%% OFF)', 'ltms' ),
            wc_price( $savings, [ 'currency' => $currency ] ),
            $pct
        ) );
        echo '</div>';
    }

    // ================================================================
    // HELPERS.
    // ================================================================

    /**
     * Devuelve la URL del logo según variante.
     *
     * @param string $variant 'white' (fondo blanco) o 'dark' (fondo negro).
     * @return string URL del logo.
     */
    public static function get_logo_url( string $variant = 'white' ): string {
        // 1. Intentar option configurada.
        $option_key = 'ltms_logo_' . $variant . '_url';
        $url = LTMS_Core_Config::get( $option_key, '' );
        if ( ! empty( $url ) ) return $url;

        // 2. Intentar assets del plugin.
        $assets_url = defined( 'LTMS_ASSETS_URL' ) ? LTMS_ASSETS_URL : plugin_dir_url( __DIR__ . '/../../lt-marketplace-suite.php' ) . 'assets/';
        $file = $variant === 'dark' ? 'img/logo-dark-bg.jpg' : 'img/logo-white-bg.jpg';
        $full_path = LTMS_PLUGIN_DIR . 'assets/' . $file;
        if ( file_exists( $full_path ) ) {
            return $assets_url . $file;
        }

        // 3. Fallback: site icon.
        return get_site_icon_url( 512 ) ?: '';
    }

    /**
     * Devuelve la paleta de colores.
     */
    public static function get_palette(): array {
        return self::COLOR_PALETTE;
    }

    /**
     * Devuelve los gatillos mentales.
     */
    public static function get_triggers(): array {
        return self::MENTAL_TRIGGERS;
    }
}
