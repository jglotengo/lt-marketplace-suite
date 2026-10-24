<?php
/**
 * LTMS — Publicación de páginas de servicio (Ayuda, Seguimiento, PQRS, Cookies, Hábeas Data)
 *
 * SERVICE-PAGES-PQRS-2026-10-24 — cierra los hallazgos de la auditoría de
 * header/footer global: la home enlazaba "Centro de ayuda" (/ayuda),
 * "Rastrear pedido" (/seguimiento) y los 4 enlaces legales (/terminos,
 * /privacidad, /cookies, /habeas-data) a páginas que NO existían (404), y
 * el sitio no tenía canal PQRS visible (requisito del Estatuto del
 * Consumidor, Ley 1480/2011 art. 27 — el propio footer exhibe el sello de
 * la CCCE).
 *
 * Crea (o actualiza si ya existen por slug) las páginas:
 *   - ayuda               → activa el template nativo help-center.php
 *                          (LTMS_Native_Templates::is_help_page)
 *   - seguimiento         → activa el template nativo order-tracking.php
 *                          (LTMS_Native_Templates::is_order_tracking_page)
 *   - pqrs                → canal PQRS visible (cumplimiento art. 27)
 *   - politica-de-cookies → política de cookies (enlace Legal de la home)
 *   - habeas-data         → aviso de tratamiento de datos (Ley 1581/2012)
 *
 * Registra los IDs en la opción ltms_installed_pages (merge — sin pisar
 * keys existentes): ltms-help, ltms-tracking, ltms-pqrs, ltms-cookies,
 * ltms-habeas-data. La home usa esas keys para resolver los enlaces.
 *
 * Uso:
 *   wp --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html \
 *      eval-file wp-content/plugins/lt-marketplace-suite/deploy/ltms-publish-service-pages-2026-10-24.php
 *
 * Idempotente — seguro de correr varias veces (actualiza por slug si ya
 * existen las páginas en vez de duplicarlas). Mismo patrón de
 * deploy/ltms-publish-legal-pages-2026-07-22.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit( 'Ejecutar via WP-CLI eval-file.' );
}

echo "=== LTMS — Publicación páginas de servicio (2026-10-24) ===\n";

/**
 * Helper: crear/actualizar una página por slug + registrar su ID.
 *
 * @param string $slug    Slug canónico (is_page() de los templates nativos).
 * @param string $title   Título de la página.
 * @param string $content Contenido HTML.
 * @param string $key     Key en ltms_installed_pages (merge sin pisar).
 * @return void
 */
function ltms_publish_service_page( string $slug, string $title, string $content, string $key ): void {
    $page_data = [
        'post_title'     => $title,
        'post_name'      => $slug,
        'post_content'   => $content,
        'post_status'    => 'publish',
        'post_type'      => 'page',
        'comment_status' => 'closed',
        'ping_status'    => 'closed',
    ];

    $existing = get_page_by_path( $slug, OBJECT, 'page' );
    if ( $existing ) {
        $page_data['ID'] = $existing->ID;
        $page_id = wp_update_post( $page_data, true );
        $action  = 'actualizada';
    } else {
        $page_id = wp_insert_post( $page_data, true );
        $action  = 'creada';
    }

    if ( is_wp_error( $page_id ) ) {
        echo "ERROR — {$title}: " . $page_id->get_error_message() . "\n";
        return;
    }

    $installed = get_option( 'ltms_installed_pages', array() );
    $installed = is_array( $installed ) ? $installed : array();
    $installed[ $key ] = (int) $page_id;
    update_option( 'ltms_installed_pages', $installed );

    $permalink = get_permalink( $page_id );
    echo "OK — {$title} {$action} (ID {$page_id}, key {$key}) -> {$permalink}\n";
}

/* ──────────────────────────────────────────────────────────────────
 * 1. Centro de ayuda (slug: ayuda) — activa el template nativo
 *    help-center.php; el contenido es placeholder (el template
 *    reemplaza el render completo).
 * ────────────────────────────────────────────────────────────────── */
ltms_publish_service_page(
    'ayuda',
    'Centro de ayuda',
    '<p>Centro de ayuda de Lo Tengo: rastrea tu pedido, revisa las preguntas frecuentes y contacta a nuestro equipo.</p>',
    'ltms-help'
);

/* ──────────────────────────────────────────────────────────────────
 * 2. Rastrea tu pedido (slug: seguimiento) — activa el template nativo
 *    order-tracking.php; el contenido es placeholder.
 * ────────────────────────────────────────────────────────────────── */
ltms_publish_service_page(
    'seguimiento',
    'Rastrea tu pedido',
    '<p>Consulta el estado de tu pedido con tu número de orden y correo electrónico.</p>',
    'ltms-tracking'
);

/* ──────────────────────────────────────────────────────────────────
 * 3. PQRS (slug: pqrs) — canal visible de Peticiones, Quejas, Reclamos
 *    y Sugerencias. Cumplimiento Ley 1480/2011 art. 27.
 * ────────────────────────────────────────────────────────────────── */
$pqrs_content = <<<'HTMLPQRS'
<p><strong>Lo Tengo</strong> pone a disposición de sus usuarios el canal <strong>PQRS</strong> (Peticiones, Quejas, Reclamos y Sugerencias), en cumplimiento del Estatuto del Consumidor (Ley 1480 de 2011, art. 27).</p>
<h2>Cómo radicar tu PQRS</h2>
<p>Escribe a <strong>pqrscolombia@lo-tengo.com.co</strong> incluyendo:</p>
<ul>
<li>Tu nombre completo y datos de contacto (correo y teléfono).</li>
<li>El número de orden o del producto relacionado (si aplica).</li>
<li>Una descripción clara del caso: qué pasó, cuándo y qué esperas como solución.</li>
<li>Soportes (capturas, comprobantes de pago, fotos del producto).</li>
</ul>
<h2>Tiempos de respuesta</h2>
<ul>
<li><strong>Acknowledge de radicación:</strong> máximo 2 días hábiles.</li>
<li><strong>Respuesta de fondo:</strong> máximo 15 días hábiles (art. 27, Ley 1480 de 2011).</li>
</ul>
<h2>Otros canales</h2>
<ul>
<li><strong>PQRS:</strong> pqrscolombia@lo-tengo.com.co</li>
<li><strong>Contacto comercial:</strong> dircomercialcol@lo-tengo.com.co</li>
<li><strong>Teléfono:</strong> 057 301 410 6251</li>
<li><strong>Dirección:</strong> Cra 48 # 12B - 55 - Of 102, Cali - Colombia</li>
</ul>
<h2>Tus derechos como consumidor</h2>
<p>Además del canal PQRS, puedes conocer tus derechos de retracto (art. 47) y garantías en nuestra <a href="/terminos-y-condiciones-2/">política de términos y condiciones</a>. Si consideras que tu caso no fue resuelto adecuadamente, puedes acudir a la <a href="https://www.sic.gov.co" target="_blank" rel="noopener noreferrer">Superintendencia de Industria y Comercio (SIC)</a>.</p>
<p><em>Este documento es una plantilla general y no sustituye asesoría legal especializada.</em></p>
HTMLPQRS;

ltms_publish_service_page( 'pqrs', 'PQRS', $pqrs_content, 'ltms-pqrs' );

/* ──────────────────────────────────────────────────────────────────
 * 4. Política de cookies (slug: politica-de-cookies)
 * ────────────────────────────────────────────────────────────────── */
$cookies_content = <<<'HTMLCOOKIES'
<p><strong>Última actualización:</strong> 24 de octubre de 2026</p>
<p><strong>Lo Tengo</strong> (lo-tengo.com.co) utiliza cookies y tecnologías similares para el funcionamiento del marketplace. Al navegar el sitio con cookies habilitadas, aceptas su uso conforme a esta política.</p>
<h2>Cookies esenciales</h2>
<p>Necesarias para el funcionamiento del sitio: sesión de usuario, carrito de compras, proceso de checkout y seguridad anti-fraude. No se pueden deshabilitar desde el sitio.</p>
<h2>Cookies de preferencias y analítica</h2>
<p>Nos permiten recordar tus preferencias (idioma, moneda, favoritos) y medir el uso del sitio para mejorarlo (estadísticas agregadas, medición de campañas).</p>
<h2>Cookies de terceros</h2>
<p>Las pasarelas de pago (Openpay) y los servicios de envío pueden establecer cookies estrictamente necesarias para procesar tus transacciones.</p>
<h2>Cómo gestionarlas</h2>
<p>Puedes bloquear o eliminar cookies desde la configuración de tu navegador. Ten en cuenta que bloquear las cookies esenciales puede afectar la compra y el acceso a tu cuenta. Para el ejercicio de tus derechos sobre datos personales, escribe a <strong>pqrscolombia@lo-tengo.com.co</strong> (ver nuestro <a href="/habeas-data/">aviso de tratamiento de datos</a>).</p>
HTMLCOOKIES;

ltms_publish_service_page( 'politica-de-cookies', 'Política de cookies', $cookies_content, 'ltms-cookies' );

/* ──────────────────────────────────────────────────────────────────
 * 5. Aviso de tratamiento de datos (slug: habeas-data) — Ley 1581/2012
 * ────────────────────────────────────────────────────────────────── */
$habeas_content = <<<'HTMLHABEAS'
<p><strong>Última actualización:</strong> 24 de octubre de 2026</p>
<p>En cumplimiento de la <strong>Ley 1581 de 2012</strong> y el Decreto 1377 de 2013, <strong>Lo Tengo</strong> (Cra 48 # 12B - 55, Of 102, Cali, Colombia) informa el tratamiento de datos personales de sus usuarios, compradores y vendedores.</p>
<h2>Responsable del tratamiento</h2>
<p>Lo Tengo, operador del marketplace lo-tengo.com.co. Contacto del área de protección de datos: <strong>pqrscolombia@lo-tengo.com.co</strong>.</p>
<h2>Datos que tratamos</h2>
<ul>
<li>Identificación y contacto (nombre, documento, correo, teléfono, dirección).</li>
<li>Datos de registro y transacciones de compra/venta.</li>
<li>Documentos KYC de vendedores (en cumplimiento SAGRILAFT).</li>
</ul>
<h2>Finalidad</h2>
<p>Gestión de la cuenta y las órdenes, prevención de fraude y lavado de activos (SAGRILAFT/UIAF), atención de PQRS, soporte al cliente y mejoramiento del servicio.</p>
<h2>Derechos del titular (ARCO)</h2>
<p>Puedes ejercer tus derechos de <strong>acceso, rectificación, cancelación y oposición</strong>, así como revocar autorizaciones, escribiendo a <strong>pqrscolombia@lo-tengo.com.co</strong> con copia de tu documento de identidad. Atenderemos tu solicitud conforme a los plazos de la ley.</p>
<p>Consulta nuestra <a href="/politica-de-privacidad-2/">política de privacidad</a> para el detalle completo del tratamiento.</p>
HTMLHABEAS;

ltms_publish_service_page( 'habeas-data', 'Aviso de tratamiento de datos', $habeas_content, 'ltms-habeas-data' );

echo "=== Fin — ltms_installed_pages actualizado con las 5 keys de servicio ===\n";
