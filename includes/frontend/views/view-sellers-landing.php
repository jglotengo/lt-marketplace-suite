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

    <!-- ── TESTIMONIOS ────────────────────────────────────────────── -->
    <section class="ltms-sl-testimonials">
        <h2 class="ltms-sl-section-title">Vendedores que ya crecen con nosotros</h2>
        <div class="ltms-sl-grid ltms-sl-grid--3">
            <div class="ltms-sl-testimonial">
                <div class="ltms-sl-testimonial__stars">★★★★★</div>
                <p class="ltms-sl-testimonial__text">"Empecé con 5 productos y en 2 meses ya tengo más de 50. El panel es muy fácil de usar."</p>
                <div class="ltms-sl-testimonial__author">
                    <strong>María González</strong> · Tienda: Artesanías MG
                </div>
            </div>
            <div class="ltms-sl-testimonial">
                <div class="ltms-sl-testimonial__stars">★★★★★</div>
                <p class="ltms-sl-testimonial__text">"Los retiros a mi cuenta bancaria son rápidos. En 24 horas tengo el dinero disponible."</p>
                <div class="ltms-sl-testimonial__author">
                    <strong>Carlos Ramírez</strong> · Tienda: TechStore CO
                </div>
            </div>
            <div class="ltms-sl-testimonial">
                <div class="ltms-sl-testimonial__stars">★★★★★</div>
                <p class="ltms-sl-testimonial__text">"La integración con transportadores me ahorra horas. Solo imprimo la guía y envío."</p>
                <div class="ltms-sl-testimonial__author">
                    <strong>Laura Torres</strong> · Tienda: Moda Express
                </div>
            </div>
        </div>
    </section>

    <!-- ── CALCULADORA DE GANANCIAS ──────────────────────────────── -->
    <section class="ltms-sl-calculator">
        <h2 class="ltms-sl-section-title">Calcula tus ganancias</h2>
        <div class="ltms-sl-calc-box">
            <label for="ltms-calc-price">Precio de tu producto</label>
            <div class="ltms-sl-calc-input-wrap">
                <span class="ltms-sl-calc-currency">$</span>
                <input type="number" id="ltms-calc-price" placeholder="50000" min="0" step="1000" value="50000">
            </div>
            <div class="ltms-sl-calc-result" id="ltms-calc-result">
                <div class="ltms-sl-calc-row">
                    <span>Precio de venta</span>
                    <strong id="ltms-calc-total">$50.000</strong>
                </div>
                <div class="ltms-sl-calc-row ltms-sl-calc-row--fee">
                    <span>Comisión (<?php echo esc_html( $vendor_fee ); ?>%)</span>
                    <strong id="ltms-calc-fee">-$<?php echo esc_html( number_format( 50000 * $commission, 0, ',', '.' ) ); ?></strong>
                </div>
                <div class="ltms-sl-calc-row ltms-sl-calc-row--earn">
                    <span>Tu ganancia</span>
                    <strong id="ltms-calc-earn">$<?php echo esc_html( number_format( 50000 * (1 - $commission), 0, ',', '.' ) ); ?></strong>
                </div>
            </div>
        </div>
    </section>

    <!-- ── FAQ ────────────────────────────────────────────────────── -->
    <section class="ltms-sl-faq">
        <h2 class="ltms-sl-section-title">Preguntas frecuentes</h2>
        <div class="ltms-sl-faq-list">
            <details class="ltms-sl-faq-item">
                <summary>¿Cuánto cuesta registrarme como vendedor?</summary>
                <p>El registro es completamente gratis. No hay mensualidades ni costos ocultos. Solo pagas una comisión del <?php echo esc_html( $vendor_fee ); ?>% cuando realizas una venta.</p>
            </details>
            <details class="ltms-sl-faq-item">
                <summary>¿Cómo recibo mis pagos?</summary>
                <p>Las ganancias se acumulan en tu billetera virtual. Puedes solicitar retiros a tu cuenta bancaria cuando quieras, procesados en 1-2 días hábiles.</p>
            </details>
            <details class="ltms-sl-faq-item">
                <summary>¿Qué productos puedo vender?</summary>
                <p>Puedes vender productos físicos, digitales y servicios. Algunas categorías requieren verificación adicional (alimentos, medicamentos, etc.).</p>
            </details>
            <details class="ltms-sl-faq-item">
                <summary>¿Necesito tener una empresa constituida?</summary>
                <p>No. Puedes vender como persona natural. Solo necesitas tu documento de identidad y completar la verificación KYC.</p>
            </details>
            <details class="ltms-sl-faq-item">
                <summary>¿Cómo funcionan los envíos?</summary>
                <p>Integramos con operadores logísticos certificados. Generas la guía desde tu panel, imprimes y entregas al transportador. El seguimiento es automático.</p>
            </details>
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

    <script>
    // v2.9.79 P1: Calculadora de ganancias interactiva.
    (function() {
        var input = document.getElementById('ltms-calc-price');
        if (!input) return;
        var commission = <?php echo (float) $commission; ?>;
        var fmt = function(v) { return '$' + new Intl.NumberFormat('es-CO').format(Math.round(v)); };
        input.addEventListener('input', function() {
            var price = parseFloat(input.value) || 0;
            var fee = price * commission;
            var earn = price - fee;
            document.getElementById('ltms-calc-total').textContent = fmt(price);
            document.getElementById('ltms-calc-fee').textContent = '-' + fmt(fee);
            document.getElementById('ltms-calc-earn').textContent = fmt(earn);
        });
    })();
    </script>

</div><!-- .ltms-sellers-landing -->
