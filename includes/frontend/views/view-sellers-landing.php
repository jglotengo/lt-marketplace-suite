<?php
/**
 * Landing page de vendedores — shortcode [ltms_sellers_landing]
 * Se renderiza en /sellers/ para captar nuevos vendedores.
 *
 * @package LTMS
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$register_url = home_url( '/registro-vendedor/' );
$login_url    = home_url( '/login-vendedor/' );
$platform     = LTMS_Core_Config::get( 'ltms_platform_name', get_bloginfo( 'name' ) );
$commission   = LTMS_Core_Config::get( 'ltms_platform_commission_rate', 0.1 );
$vendor_fee   = round( (float) $commission * 100, 1 );
$vendor_earn  = 100 - $vendor_fee;
?>
<div class="ltms-sellers-landing">

    <!-- ── HERO ─────────────────────────────────────────────────── -->
    <section class="ltms-sl-hero">
        <div class="ltms-sl-hero__inner">
            <span class="ltms-sl-hero__tag">🇨🇴 Marketplace Colombia</span>
            <h1 class="ltms-sl-hero__title">
                Vende en <strong><?php echo esc_html( $platform ); ?></strong><br>
                y llega a miles de compradores
            </h1>
            <p class="ltms-sl-hero__sub">
                Regístrate gratis, sube tus productos en minutos y empieza a vender hoy.
                Tú recibes el <strong><?php echo esc_html( $vendor_earn ); ?>%</strong> de cada venta.
            </p>
            <div class="ltms-sl-hero__cta">
                <a href="<?php echo esc_url( $register_url ); ?>" class="ltms-sl-btn ltms-sl-btn--primary">
                    🚀 Empieza a vender gratis
                </a>
                <a href="<?php echo esc_url( $login_url ); ?>" class="ltms-sl-btn ltms-sl-btn--outline">
                    Ya soy vendedor
                </a>
            </div>
        </div>
    </section>

    <!-- ── BENEFICIOS ────────────────────────────────────────────── -->
    <section class="ltms-sl-benefits">
        <h2 class="ltms-sl-section-title">¿Por qué vender con nosotros?</h2>
        <div class="ltms-sl-grid ltms-sl-grid--3">
            <div class="ltms-sl-card">
                <div class="ltms-sl-card__icon">💸</div>
                <h3>Solo pagas cuando vendes</h3>
                <p>Sin mensualidades. Solo una comisión del <?php echo esc_html( $vendor_fee ); ?>% por venta exitosa. El resto es tuyo.</p>
            </div>
            <div class="ltms-sl-card">
                <div class="ltms-sl-card__icon">📊</div>
                <h3>Panel de vendedor completo</h3>
                <p>Dashboard con métricas en tiempo real, gestión de productos, pedidos y tu billetera virtual.</p>
            </div>
            <div class="ltms-sl-card">
                <div class="ltms-sl-card__icon">🔐</div>
                <h3>Pagos garantizados</h3>
                <p>Recibe tus ganancias directamente en tu cuenta bancaria. Retiros rápidos y seguros.</p>
            </div>
            <div class="ltms-sl-card">
                <div class="ltms-sl-card__icon">🚚</div>
                <h3>Logística integrada</h3>
                <p>Conectamos con operadores logísticos para que tus envíos sean simples y rastreables.</p>
            </div>
            <div class="ltms-sl-card">
                <div class="ltms-sl-card__icon">📣</div>
                <h3>Alcance nacional</h3>
                <p>Tu tienda visible para compradores en todo Colombia. Sin límite de unidades ni categorías.</p>
            </div>
            <div class="ltms-sl-card">
                <div class="ltms-sl-card__icon">🤝</div>
                <h3>Soporte dedicado</h3>
                <p>Equipo disponible para ayudarte a configurar tu tienda y resolver cualquier duda.</p>
            </div>
        </div>
    </section>

    <!-- ── PASOS ──────────────────────────────────────────────────── -->
    <section class="ltms-sl-steps">
        <h2 class="ltms-sl-section-title">Empieza en 3 pasos</h2>
        <div class="ltms-sl-grid ltms-sl-grid--3 ltms-sl-steps__grid">
            <div class="ltms-sl-step">
                <div class="ltms-sl-step__num">1</div>
                <h3>Regístrate gratis</h3>
                <p>Crea tu cuenta de vendedor en menos de 2 minutos. Solo necesitas tu email y datos básicos.</p>
            </div>
            <div class="ltms-sl-step">
                <div class="ltms-sl-step__num">2</div>
                <h3>Sube tus productos</h3>
                <p>Agrega fotos, precios y descripción. Tu tienda queda activa de inmediato.</p>
            </div>
            <div class="ltms-sl-step">
                <div class="ltms-sl-step__num">3</div>
                <h3>Recibe y cobra</h3>
                <p>Cuando lleguen los pedidos, empaca y envía. El pago llega directo a tu billetera.</p>
            </div>
        </div>
    </section>

    <!-- ── CTA FINAL ──────────────────────────────────────────────── -->
    <section class="ltms-sl-cta">
        <div class="ltms-sl-cta__inner">
            <h2>¿Listo para empezar?</h2>
            <p>Únete a los vendedores que ya están creciendo en <?php echo esc_html( $platform ); ?>.</p>
            <a href="<?php echo esc_url( $register_url ); ?>" class="ltms-sl-btn ltms-sl-btn--primary ltms-sl-btn--lg">
                🚀 Crear mi tienda gratis
            </a>
        </div>
    </section>

</div><!-- .ltms-sellers-landing -->
