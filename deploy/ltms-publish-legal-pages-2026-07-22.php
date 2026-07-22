<?php
/**
 * LTMS — Publicación de páginas legales (Términos, Privacidad, Devoluciones)
 *
 * Crea (o actualiza si ya existen por slug) las 3 páginas legales del sitio
 * y sincroniza las opciones que el propio plugin ya usa para pintar los
 * enlaces en footer/checkout/cart-drawer/compliance-guardian:
 *   - ltms_terms_url
 *   - ltms_privacy_url
 *   - ltms_devoluciones_url
 *
 * IMPORTANTE — pendiente de decisión de negocio antes de considerar esto
 * "cerrado": el contenido publicado aquí cubre solo normativa colombiana
 * (Ley 1480/2011, Ley 1581/2012). Si Lo Tengo opera activamente con
 * Vendedores o Usuarios en México, falta la sección México (LFPC/PROFECO,
 * LFPDPPP) en los 3 documentos — ver hallazgo de revisión legal adjunto.
 * No se debe dar por completo el cumplimiento cross-border solo con este
 * script.
 *
 * Uso:
 *   wp --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html \
 *      eval-file deploy/ltms-publish-legal-pages-2026-07-22.php
 *
 * Idempotente — seguro de correr varias veces (actualiza por slug si ya
 * existen las páginas en vez de duplicarlas).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit( 'Ejecutar via WP-CLI eval-file.' );
}

echo "=== LTMS — Publicación páginas legales (2026-07-22) ===\n";

// ──────────────────────────────────────────────────────────────────
// 1. Términos y condiciones (slug: terminos-y-condiciones)
// ──────────────────────────────────────────────────────────────────
$content_1 = <<<'HTMLCONTENT1'
<p><strong>Última actualización:</strong> 22 de julio de 2026</p>
<h2>1. Identificación del prestador del servicio</h2>
<p><strong>Lo Tengo</strong> (en adelante, "la Plataforma" o "Lo Tengo") es un marketplace multi-vendor operado a través del sitio web <strong>lo-tengo.com.co</strong>, con domicilio en Cra 48 # 12B - 55, Of 102C, Cali, Colombia.</p>
<ul>
<li><strong>Contacto comercial:</strong> dircomercialcol@lo-tengo.com.co</li>
<li><strong>Atención a vendedores:</strong> sellerscolombia@lo-tengo.com.co</li>
<li><strong>PQR (peticiones, quejas y reclamos):</strong> pqrscolombia@lo-tengo.com.co</li>
<li><strong>Teléfono:</strong> 057 301 410 6251</li>
</ul>
<h2>2. Objeto</h2>
<p>Lo Tengo es una plataforma de comercio electrónico tipo <strong>marketplace multi-vendor</strong>: pone en contacto a vendedores independientes ("Vendedores" o "Aliados Comerciales") con compradores ("Usuarios" o "Clientes"), facilitando la exhibición, negociación y pago de productos y servicios. Lo Tengo <strong>no es el vendedor directo</strong> de los productos publicados por terceros, salvo que se indique expresamente lo contrario en una publicación concreta.</p>
<h2>3. Aceptación de los términos</h2>
<p>El acceso, navegación o uso del sitio implica la aceptación plena y sin reservas de estos Términos y Condiciones. Si el Usuario no está de acuerdo, debe abstenerse de usar la Plataforma.</p>
<h2>4. Registro de usuarios</h2>
<ul>
<li>Para comprar no siempre es obligatorio crear una cuenta, pero algunas funciones (seguimiento de pedidos, historial, wishlist) requieren registro.</li>
<li>El Usuario garantiza que la información suministrada (nombre, cédula/NIT, correo, dirección, teléfono) es veraz, completa y está actualizada.</li>
<li>El Usuario es responsable de la confidencialidad de sus credenciales de acceso.</li>
<li>Lo Tengo puede suspender o cancelar cuentas que incumplan estos Términos, presenten fraude, suplantación o actividad sospechosa.</li>
</ul>
<h2>5. Rol de los Vendedores (marketplace multi-vendor)</h2>
<ul>
<li>Cada Vendedor es un comerciante independiente, responsable de la legalidad, calidad, descripción, existencias, precio, empaque y despacho de sus productos.</li>
<li>Lo Tengo verifica la identidad y documentación de los Vendedores mediante su proceso de <strong>KYC (conoce a tu vendedor)</strong>, pero no garantiza ni se hace responsable del cumplimiento comercial de cada Vendedor más allá de lo que exige la ley para plataformas de intermediación.</li>
<li>Las facturas o soportes de compra son emitidos según corresponda por el Vendedor o por Lo Tengo, según el modelo de comisión y la operación concreta.</li>
<li>Ante incumplimientos graves o reiterados, Lo Tengo puede suspender, congelar la billetera o retirar de la Plataforma a un Vendedor, sin perjuicio de las acciones legales que correspondan.</li>
</ul>
<h2>6. Precios, pagos y facturación</h2>
<ul>
<li>Los precios publicados en la Plataforma incluyen los impuestos aplicables (IVA), salvo que se indique lo contrario, y están expresados en pesos colombianos (COP), salvo publicaciones específicas en otra moneda para operaciones autorizadas.</li>
<li>Los pagos se procesan a través de pasarelas de pago seguras (incluyendo <strong>Openpay</strong>, operada por BBVA), bajo los estándares de seguridad de la industria (PCI-DSS). Lo Tengo no almacena directamente los datos completos de tarjetas de crédito/débito.</li>
<li>Lo Tengo se reserva el derecho de cancelar o rechazar órdenes ante sospecha de fraude, error evidente de precio, o falta de disponibilidad del producto, notificando al Usuario y reembolsando cualquier valor ya cobrado.</li>
</ul>
<h2>7. Envíos y tiempos de entrega</h2>
<ul>
<li>Los tiempos de entrega informados en cada publicación son estimados y pueden variar según el Vendedor, la transportadora y la ciudad de destino.</li>
<li>El riesgo de la mercancía se transfiere al Usuario al momento de la entrega en la dirección indicada, salvo que la ley disponga algo distinto.</li>
<li>Consulte la <strong>Política de Devoluciones</strong> para conocer el procedimiento ante retrasos, productos defectuosos o no entregados.</li>
</ul>
<h2>8. Derecho de retracto</h2>
<p>De acuerdo con el artículo 47 de la Ley 1480 de 2011, el Usuario que realice compras a través de medios no tradicionales (comercio electrónico) tiene derecho de retracto dentro de los <strong>cinco (5) días hábiles</strong> siguientes a la entrega del producto, salvo las excepciones legales (productos personalizados, perecederos, de higiene personal ya destapados, contenido digital ya descargado, entre otros). Ver detalles en la <strong>Política de Devoluciones</strong>.</p>
<h2>9. Propiedad intelectual</h2>
<p>Las marcas, logotipos, textos, imágenes, catálogos y el software de la Plataforma son propiedad de Lo Tengo o de sus Vendedores/licenciantes, y están protegidos por las normas de propiedad intelectual e industrial vigentes en Colombia. Queda prohibida su reproducción, distribución o uso no autorizado.</p>
<h2>10. Conducta del usuario</h2>
<p>El Usuario se compromete a no utilizar la Plataforma para: (i) fines fraudulentos o ilícitos; (ii) publicar o transmitir contenido difamatorio, ofensivo o que vulnere derechos de terceros; (iii) intentar vulnerar la seguridad informática del sitio; (iv) suplantar la identidad de otra persona o entidad.</p>
<h2>11. Limitación de responsabilidad</h2>
<p>Lo Tengo actúa como intermediario tecnológico entre Vendedores y Usuarios. Salvo en los casos en que actúe como vendedor directo, Lo Tengo no responde por: (i) la calidad, idoneidad o legalidad de los productos publicados por terceros; (ii) el incumplimiento de un Vendedor en la entrega; (iii) daños indirectos derivados del uso de la Plataforma. Lo anterior no exime a Lo Tengo de sus obligaciones legales como plataforma de comercio electrónico frente a los consumidores, ni limita los derechos irrenunciables del consumidor bajo la Ley 1480 de 2011.</p>
<h2>12. Modificaciones</h2>
<p>Lo Tengo podrá modificar estos Términos y Condiciones en cualquier momento. Los cambios se publicarán en esta misma página con su fecha de actualización, y regirán a partir de su publicación.</p>
<h2>13. Ley aplicable y jurisdicción</h2>
<p>Estos Términos se rigen por las leyes de la República de Colombia. Cualquier controversia se someterá a los jueces competentes de Cali, Valle del Cauca, sin perjuicio del derecho del consumidor a acudir a la Superintendencia de Industria y Comercio (SIC) o a los mecanismos de protección al consumidor vigentes.</p>
<h2>14. Contacto</h2>
<p>Para dudas, quejas o reclamos relacionados con estos Términos: <strong>pqrscolombia@lo-tengo.com.co</strong>.</p>
<hr />
<p><em>Este documento es una plantilla general y no sustituye asesoría legal especializada. Se recomienda revisión por un abogado antes de su publicación definitiva.</em></p>
HTMLCONTENT1;

$page_data = [
    'post_title'     => 'Términos y condiciones',
    'post_name'      => 'terminos-y-condiciones',
    'post_content'   => $content_1,
    'post_status'    => 'publish',
    'post_type'      => 'page',
    'comment_status' => 'closed',
    'ping_status'    => 'closed',
];

$existing = get_page_by_path( 'terminos-y-condiciones', OBJECT, 'page' );
if ( $existing ) {
    $page_data['ID'] = $existing->ID;
    $page_id = wp_update_post( $page_data, true );
    $action  = 'actualizada';
} else {
    $page_id = wp_insert_post( $page_data, true );
    $action  = 'creada';
}

if ( is_wp_error( $page_id ) ) {
    echo "ERROR — Términos y condiciones: " . $page_id->get_error_message() . "\n";
} else {
    $permalink = get_permalink( $page_id );
    update_option( 'ltms_terms_url', $permalink );
    echo "OK — Términos y condiciones {$action} (ID {$page_id}) -> {$permalink}\n";
    echo "    Opción 'ltms_terms_url' actualizada a: {$permalink}\n";
}


// ──────────────────────────────────────────────────────────────────
// 2. Aviso de privacidad (slug: politica-de-privacidad)
// ──────────────────────────────────────────────────────────────────
$content_2 = <<<'HTMLCONTENT2'
<p><strong>Última actualización:</strong> 22 de julio de 2026</p>
<p>En cumplimiento de la <strong>Ley 1581 de 2012</strong>, el <strong>Decreto 1377 de 2013</strong> (compilado en el Decreto 1074 de 2015) y demás normas concordantes sobre protección de datos personales en Colombia, <strong>Lo Tengo</strong> (Cra 48 # 12B - 55, Of 102C, Cali, Colombia) pone a disposición de los titulares de datos personales el presente Aviso de Privacidad.</p>
<h2>1. Responsable del tratamiento</h2>
<ul>
<li><strong>Nombre:</strong> Lo Tengo</li>
<li><strong>Domicilio:</strong> Cra 48 # 12B - 55, Of 102C, Cali - Colombia</li>
<li><strong>Correo de contacto para temas de datos personales:</strong> pqrscolombia@lo-tengo.com.co</li>
<li><strong>Teléfono:</strong> 057 301 410 6251</li>
</ul>
<h2>2. Datos personales que recolectamos</h2>
<p>Según el tipo de usuario (Cliente, Vendedor o visitante), podemos recolectar:</p>
<ul>
<li><strong>Datos de identificación:</strong> nombre completo, tipo y número de documento (cédula, NIT), fecha de nacimiento.</li>
<li><strong>Datos de contacto:</strong> correo electrónico, teléfono, dirección de envío y facturación, ciudad.</li>
<li><strong>Datos transaccionales:</strong> historial de compras, valores, medios de pago utilizados (tokenizados por la pasarela de pago, no almacenamos números completos de tarjeta).</li>
<li><strong>Datos de Vendedores (KYC):</strong> documentos de identidad, información de cámara de comercio, datos bancarios para el pago de comisiones y giros (wallet/payouts), en cumplimiento de normas de prevención de lavado de activos.</li>
<li><strong>Datos de navegación:</strong> dirección IP, cookies, tipo de dispositivo y navegador, páginas visitadas (ver sección de cookies).</li>
</ul>
<h2>3. Finalidades del tratamiento</h2>
<p>Los datos personales serán utilizados para:</p>
<ol>
<li>Procesar y gestionar compras, pagos, envíos y devoluciones.</li>
<li>Crear y administrar cuentas de Usuarios y Vendedores.</li>
<li>Verificar la identidad de los Vendedores (KYC) y prevenir fraude o lavado de activos.</li>
<li>Atender solicitudes, peticiones, quejas y reclamos (PQR).</li>
<li>Enviar comunicaciones comerciales, promociones y novedades, cuando el titular lo haya autorizado.</li>
<li>Cumplir obligaciones legales, contables, tributarias y regulatorias.</li>
<li>Mejorar la experiencia de navegación y la seguridad de la Plataforma.</li>
</ol>
<h2>4. Autorización</h2>
<p>Al registrarse, realizar una compra o navegar aceptando el uso de cookies, el titular otorga su autorización libre, previa, expresa e informada para el tratamiento de sus datos personales conforme a este Aviso. Para datos sensibles (si aplica) se solicitará autorización expresa y diferenciada.</p>
<h2>5. Derechos del titular</h2>
<p>Conforme al artículo 8 de la Ley 1581 de 2012, el titular de los datos tiene derecho a:</p>
<ul>
<li>Conocer, actualizar y rectificar sus datos personales.</li>
<li>Solicitar prueba de la autorización otorgada.</li>
<li>Ser informado sobre el uso dado a sus datos.</li>
<li>Presentar quejas ante la Superintendencia de Industria y Comercio (SIC) por infracciones a la ley.</li>
<li>Revocar la autorización y/o solicitar la supresión de sus datos, cuando no exista un deber legal o contractual que impida su eliminación.</li>
<li>Acceder gratuitamente a sus datos personales objeto de tratamiento.</li>
</ul>
<h2>6. Cómo ejercer sus derechos</h2>
<p>El titular puede ejercer estos derechos enviando una solicitud a <strong>pqrscolombia@lo-tengo.com.co</strong>, indicando: nombre completo, documento de identidad, descripción clara de la solicitud y, si aplica, los documentos que la soporten. Lo Tengo dará respuesta dentro de los términos legales (10 días hábiles para consultas, 15 días hábiles para reclamos, prorrogables conforme a la ley).</p>
<h2>7. Transferencia y transmisión de datos</h2>
<p>Los datos personales podrán ser compartidos con:</p>
<ul>
<li>Pasarelas de pago (p. ej. <strong>Openpay/BBVA</strong>) para el procesamiento de transacciones.</li>
<li>Transportadoras y operadores logísticos, para efectos de entrega.</li>
<li>Proveedores de infraestructura tecnológica (almacenamiento en la nube), bajo acuerdos de confidencialidad y niveles de servicio.</li>
<li>Autoridades judiciales o administrativas, cuando exista requerimiento legal.</li>
</ul>
<p>Lo Tengo exige a estos terceros el cumplimiento de estándares adecuados de protección de datos.</p>
<h2>8. Seguridad de la información</h2>
<p>Lo Tengo implementa medidas técnicas, humanas y administrativas razonables para proteger los datos personales contra pérdida, uso indebido, acceso no autorizado, alteración o destrucción, incluyendo cifrado en tránsito, control de acceso y monitoreo de seguridad.</p>
<h2>9. Uso de cookies</h2>
<p>El sitio utiliza cookies propias y de terceros para: recordar preferencias, mantener la sesión activa, analizar el uso del sitio y personalizar contenido/publicidad. El Usuario puede configurar su navegador para bloquear o eliminar cookies, aunque esto puede afectar la funcionalidad del sitio.</p>
<h2>10. Menores de edad</h2>
<p>Lo Tengo no recolecta intencionalmente datos personales de menores de edad sin la autorización de sus padres o representantes legales. Si se detecta que un menor ha suministrado datos sin dicha autorización, estos serán eliminados.</p>
<h2>11. Vigencia</h2>
<p>Los datos personales se conservarán durante el tiempo necesario para cumplir las finalidades descritas y las obligaciones legales, contables y tributarias aplicables. Este Aviso rige a partir de su publicación y podrá actualizarse periódicamente.</p>
<h2>12. Contacto</h2>
<p>Preguntas, solicitudes o reclamos sobre el tratamiento de datos personales: <strong>pqrscolombia@lo-tengo.com.co</strong>.</p>
<hr />
<p><em>Este documento es una plantilla general basada en la Ley 1581 de 2012 y el Decreto 1074 de 2015. Se recomienda revisión legal antes de su publicación definitiva, especialmente en lo relativo al tratamiento de datos financieros de Vendedores (KYC/SAGRILAFT).</em></p>
HTMLCONTENT2;

$page_data = [
    'post_title'     => 'Aviso de privacidad',
    'post_name'      => 'politica-de-privacidad',
    'post_content'   => $content_2,
    'post_status'    => 'publish',
    'post_type'      => 'page',
    'comment_status' => 'closed',
    'ping_status'    => 'closed',
];

$existing = get_page_by_path( 'politica-de-privacidad', OBJECT, 'page' );
if ( $existing ) {
    $page_data['ID'] = $existing->ID;
    $page_id = wp_update_post( $page_data, true );
    $action  = 'actualizada';
} else {
    $page_id = wp_insert_post( $page_data, true );
    $action  = 'creada';
}

if ( is_wp_error( $page_id ) ) {
    echo "ERROR — Aviso de privacidad: " . $page_id->get_error_message() . "\n";
} else {
    $permalink = get_permalink( $page_id );
    update_option( 'ltms_privacy_url', $permalink );
    echo "OK — Aviso de privacidad {$action} (ID {$page_id}) -> {$permalink}\n";
    echo "    Opción 'ltms_privacy_url' actualizada a: {$permalink}\n";
}


// ──────────────────────────────────────────────────────────────────
// 3. Política de devoluciones (slug: politica-de-devoluciones)
// ──────────────────────────────────────────────────────────────────
$content_3 = <<<'HTMLCONTENT3'
<p><strong>Última actualización:</strong> 22 de julio de 2026</p>
<p>Esta política aplica a las compras realizadas en <strong>lo-tengo.com.co</strong> a Vendedores de la Plataforma, y se rige por la <strong>Ley 1480 de 2011</strong> (Estatuto del Consumidor) y sus decretos reglamentarios.</p>
<h2>1. Derecho de retracto</h2>
<p>El Usuario que compre a través de la Plataforma tiene derecho a retractarse de la compra dentro de los <strong>cinco (5) días hábiles</strong> siguientes a la entrega del producto, sin necesidad de justificar su decisión, siempre que el producto:</p>
<ul>
<li>No haya sido usado y conserve su empaque original.</li>
<li>Conserve etiquetas, sellos de seguridad y accesorios originales.</li>
<li>No corresponda a alguna de las excepciones legales (ver sección 5).</li>
</ul>
<p>Para ejercer este derecho, el Usuario debe escribir a <strong>pqrscolombia@lo-tengo.com.co</strong> indicando el número de orden, producto y motivo, dentro del plazo indicado.</p>
<h2>2. Cambios y devoluciones por producto defectuoso o error en el envío</h2>
<p>Si el producto llega defectuoso, incompleto, o no corresponde a lo solicitado, el Usuario puede solicitar cambio, reparación o devolución del dinero dentro de los <strong>treinta (30) días calendario</strong> siguientes a la entrega, conforme a la garantía legal mínima prevista en la Ley 1480 de 2011.</p>
<p><strong>Pasos para solicitar la devolución/cambio:</strong></p>
<ol>
<li>Escribir a <strong>pqrscolombia@lo-tengo.com.co</strong> con el número de orden y fotos del producto (si aplica).</li>
<li>El equipo de Lo Tengo o el Vendedor correspondiente evaluará la solicitud en un plazo máximo de <strong>48 horas hábiles</strong>.</li>
<li>Si procede, se coordina la recolección del producto o la entrega en un punto autorizado, sin costo para el Usuario cuando el motivo sea imputable al Vendedor (producto defectuoso, error de envío).</li>
<li>Una vez verificado el producto devuelto, se procesa el reembolso o el cambio en un plazo de hasta <strong>15 días hábiles</strong>.</li>
</ol>
<h2>3. Garantía legal</h2>
<p>Todos los productos vendidos en la Plataforma cuentan con la garantía legal mínima establecida en la Ley 1480 de 2011, cuyo término depende del tipo de producto (por defecto, un (1) año para productos nuevos, salvo que el Vendedor o el fabricante ofrezca un término mayor). La garantía cubre defectos de calidad, idoneidad o seguridad del producto.</p>
<h2>4. Reembolsos</h2>
<ul>
<li>Los reembolsos se realizan por el mismo medio de pago utilizado en la compra (tarjeta, Openpay, u otro medio habilitado), salvo acuerdo distinto con el Usuario.</li>
<li>El tiempo de acreditación del reembolso puede variar según la entidad financiera o procesador de pago (generalmente entre 5 y 15 días hábiles tras la aprobación de la devolución).</li>
</ul>
<h2>5. Excepciones al derecho de retracto</h2>
<p>Conforme al artículo 47 de la Ley 1480 de 2011, no aplica el derecho de retracto en los siguientes casos:</p>
<ul>
<li>Productos personalizados o hechos por encargo específico del Usuario.</li>
<li>Productos perecederos o que puedan deteriorarse o caducar con rapidez.</li>
<li>Productos de uso personal o higiene que hayan sido desprecintados/destapados después de la entrega (ropa interior, cosméticos, etc.).</li>
<li>Bienes que, por su naturaleza, no puedan devolverse o sean susceptibles de deterioro o caducidad.</li>
<li>Contenido digital descargado o consumido después de la compra, cuando el Usuario haya aceptado expresamente la renuncia al derecho de retracto para ese tipo de contenido.</li>
<li>Servicios de reservas/booking ya prestados total o parcialmente antes del plazo de retracto, con el consentimiento previo del Usuario.</li>
</ul>
<h2>6. Costos de envío en devoluciones</h2>
<ul>
<li>Si la devolución obedece a un <strong>derecho de retracto</strong> (el Usuario simplemente cambió de opinión), los costos de envío de la devolución corren por cuenta del Usuario, salvo que el Vendedor determine lo contrario.</li>
<li>Si la devolución obedece a <strong>error del Vendedor o producto defectuoso</strong>, los costos de recolección y envío corren por cuenta de Lo Tengo o del Vendedor, sin costo para el Usuario.</li>
</ul>
<h2>7. Productos de Vendedores del marketplace</h2>
<p>Al ser Lo Tengo un marketplace multi-vendor, cada solicitud de devolución se gestiona en coordinación con el Vendedor responsable del producto. Lo Tengo actúa como facilitador para garantizar que se cumplan los derechos del consumidor establecidos en la ley, independientemente de qué Vendedor haya realizado la venta.</p>
<h2>8. Contacto</h2>
<p>Para solicitudes de devolución, cambio o garantía: <strong>pqrscolombia@lo-tengo.com.co</strong> — Tel. 057 301 410 6251.</p>
<p>Si el Usuario considera que su solicitud no fue atendida adecuadamente, puede acudir a la <strong>Superintendencia de Industria y Comercio (SIC)</strong> como autoridad de protección al consumidor en Colombia.</p>
<hr />
<p><em>Este documento es una plantilla general basada en la Ley 1480 de 2011 y no sustituye asesoría legal especializada. Se recomienda revisión por un abogado antes de su publicación definitiva.</em></p>
HTMLCONTENT3;

$page_data = [
    'post_title'     => 'Política de devoluciones',
    'post_name'      => 'politica-de-devoluciones',
    'post_content'   => $content_3,
    'post_status'    => 'publish',
    'post_type'      => 'page',
    'comment_status' => 'closed',
    'ping_status'    => 'closed',
];

$existing = get_page_by_path( 'politica-de-devoluciones', OBJECT, 'page' );
if ( $existing ) {
    $page_data['ID'] = $existing->ID;
    $page_id = wp_update_post( $page_data, true );
    $action  = 'actualizada';
} else {
    $page_id = wp_insert_post( $page_data, true );
    $action  = 'creada';
}

if ( is_wp_error( $page_id ) ) {
    echo "ERROR — Política de devoluciones: " . $page_id->get_error_message() . "\n";
} else {
    $permalink = get_permalink( $page_id );
    update_option( 'ltms_devoluciones_url', $permalink );
    echo "OK — Política de devoluciones {$action} (ID {$page_id}) -> {$permalink}\n";
    echo "    Opción 'ltms_devoluciones_url' actualizada a: {$permalink}\n";
}

echo "\n=== Fin. Verifica los footers/checkout apuntando a estas URLs. ===\n";
