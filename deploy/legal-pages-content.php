<?php
/**
 * Legal Pages Content — Términos y Condiciones + Política de Privacidad
 *
 * Contenido legal completo basado en:
 * - Código del plugin (cross-border, KYC, wallet, compliance)
 * - Norma colombiana: Ley 1581/2012, Ley 1480/2011, SAGRILAFT, Decreto 3075/1997
 * - Norma mexicana: LFPDPPP, NOM-035, Ley Federal del Consumidor
 * - Meta/Pixels: consentimiento de cookies, data processing
 * - Cross-border: IOSS/UE, customs declarations, FX rates
 *
 * Uso: wp post update <ID> --post_content="$(php -r 'echo file_get_contents("legal-terms-content.php");')" --allow-root
 * O: copiar el contenido HTML directamente.
 *
 * @package LTMS
 * @version 2.9.255
 */

// ═══════════════════════════════════════════════════════════════
// TÉRMINOS Y CONDICIONES (sin <h1> — WordPress ya muestra el título)
// ═══════════════════════════════════════════════════════════════

$terms_content = <<<HTML
<h2>1. Objeto y Aceptación</h2>
<p>Los presentes Términos y Condiciones ("Términos") regulan la relación entre Lo Tengo (en adelante, "la Plataforma") y las personas naturales o jurídicas registradas como vendedores ("Vendedores") en el marketplace <strong>lo-tengo.com.co</strong> (Colombia) y sus operaciones en México.</p>
<p>Al registrarte como Vendedor, aceptas estos Términos en su totalidad. Si no estás de acuerdo, no debes registrarte ni utilizar la Plataforma.</p>

<h2>2. Definiciones</h2>
<ul>
<li><strong>Plataforma:</strong> Lo Tengo, marketplace multi-vendor operado sobre WooCommerce.</li>
<li><strong>Vendedor:</strong> Persona natural o jurídica registrada con rol <code>ltms_vendor</code> que ofrece productos o servicios en la Plataforma.</li>
<li><strong>Comprador:</strong> Usuario que adquiere productos o servicios de un Vendedor.</li>
<li><strong>Comisión:</strong> Porcentaje retenido por la Plataforma sobre cada venta, según plan y volumen.</li>
<li><strong>KYC:</strong> Know Your Customer — proceso de verificación de identidad obligatorio.</li>
<li><strong>SAGRILAFT:</strong> Sistema de Gestión de Riesgo de Lavado de Activos y Financiación del Terrorismo (Colombia, Ley 526/1999).</li>
<li><strong>RNT:</strong> Registro Nacional de Turismo (Colombia, FONTUR, Ley 2068/2020).</li>
<li><strong>INVIMA:</strong> Instituto Nacional de Vigilancia de Medicamentos y Alimentos (Colombia, Decreto 3075/1997).</li>
<li><strong>COFEPRIS:</strong> Comisión Federal para la Protección contra Riesgos Sanitarios (México, NOM-251-SSA1-2009).</li>
<li><strong>CFDI:</strong> Comprobante Fiscal Digital por Internet (México, SAT 4.0).</li>
</ul>

<h2>3. Tipos de Vendedor y Requisitos Específicos</h2>
<h3>3.1 Productos Físicos</h3>
<p>El Vendedor declara que los productos físicos cumplen con las normas de calidad y seguridad aplicables. Para Colombia, debe cumplir con el Estatuto Tributario (ReteFuente, ReteIVA, ReteICA) y para México con el CFDI 4.0 y LISR art. 113-A.</p>

<h3>3.2 Productos Digitales</h3>
<p>Los productos digitales (cursos, software, diseños) están sujetos a IVA (19% CO / 16% MX). El Vendedor garantiza que posee los derechos de propiedad intelectual sobre los productos digitales ofrecidos.</p>

<h3>3.3 Servicios</h3>
<p>Los servicios profesionales están sujetos a retenciones según régimen tributario. El Vendedor declara que cuenta con las licencias y permisos necesarios para prestar los servicios ofrecidos.</p>

<h3>3.4 Turismo / Alojamiento</h3>
<p>Los Vendedores de turismo <strong>deben contar con RNT vigente</strong> (Colombia, Ley 2068/2020, FONTUR) o folio SECTUR (México). Sin RNT verificado, no podrán publicar alojamientos. El IVA aplicable es del 7% (Estatuto Tributario art. 468-1, turismo).</p>

<h3>3.5 Restaurante</h3>
<p>Los Vendedores de restaurantes <strong>deben contar con registro sanitario INVIMA</strong> (Colombia, Decreto 3075/1997 art. 4) o <strong>aviso de funcionamiento COFEPRIS</strong> (México, NOM-251-SSA1-2009). Sin registro sanitario, no se aprobará el KYC. Impoconsumo aplicable: 8% (CIIU 5611).</p>

<h2>4. Proceso de Registro y Verificación (KYC)</h2>
<ol>
<li><strong>Registro:</strong> El Vendedor completa el wizard de 3 pasos (datos personales, tienda, seguridad).</li>
<li><strong>Verificación de email:</strong> Se envía un enlace de verificación al correo registrado (válido por 48 horas).</li>
<li><strong>KYC:</strong> El Vendedor debe subir documento de identidad, selfie, y según el tipo de negocio: RNT (turismo), registro sanitario INVIMA/COFEPRIS (restaurantes), RUT (Colombia) o constancia de situación fiscal RFC (México).</li>
<li><strong>Aprobación:</strong> La Plataforma revisa los documentos en 1-2 días hábiles. Los documentos se almacenan cifrados (AES-256-GCM) en Backblaze B2.</li>
<li><strong>Desbloqueo:</strong> Tras la aprobación KYC, se desbloquea la billetera y la capacidad de publicar productos.</li>
</ol>

<h2>5. Comisiones y Pagos</h2>
<h3>5.1 Estructura de Comisiones</h3>
<p>La Plataforma retiene una comisión sobre cada venta. La tasa se determina por cascada de prioridades:</p>
<ol>
<li>Contrato individual negociado con el Vendedor</li>
<li>Tasa individual por producto</li>
<li>Tasa por tipo de producto (físico 10%, digital/servicio 15%, reservas 15%)</li>
<li>Tier de volumen mensual (CO: &lt;$5M=12%, $5-20M=10%, $20-50M=8%, &gt;$50M=6%)</li>
<li>Categoría del producto</li>
<li>Plan del vendedor (Premium 8%, Básico 10%)</li>
<li>Tasa global configurada (default 15%)</li>
</ol>

<h3>5.2 Billetera y Retiros</h3>
<ul>
<li>El saldo de la billetera se actualiza en tiempo real con transacciones ACID (MySQL SELECT FOR UPDATE).</li>
<li>El saldo disponible = balance - saldo retenido (holds para disputes o compliance).</li>
<li>Retiro mínimo: $50,000 COP (Colombia) / $500 MXN (México).</li>
<li>Máximo 3 solicitudes de retiro pendientes simultáneamente.</li>
<li>Los retiros se procesan vía Openpay (transferencia bancaria, Nequi, Daviplata en CO; SPEI, OXXO en MX).</li>
<li>La billetera puede ser congelada por cumplimiento SAGRILAFT o disputas.</li>
</ul>

<h3>5.3 Motor Fiscal</h3>
<p><strong>Colombia:</strong> ReteFuente (2.5%-11% según tipo), ReteIVA (15% del IVA), ReteICA (0.414‰-11.04‰ por CIIU y municipio), Impoconsumo (8% restaurantes). UVT 2026 = $52,752 (Decreto 2229/2024).</p>
<p><strong>México:</strong> ISR art. 113-A LISR (1.25%-10% por tramo de ingreso mensual), IVA 16%, IEPS (variable por categoría), ISH (2-5% hospedaje). CFDI 4.0 obligatorio (SAT).</p>

<h2>6. Red de Referidos (MLM)</h2>
<p>La Plataforma opera un programa de referidos de 3 niveles:</p>
<ul>
<li>Nivel 1 (patrocinador directo): 40% de la comisión de plataforma</li>
<li>Nivel 2: 20%</li>
<li>Nivel 3: 10%</li>
</ul>
<p>La distribución total no puede exceder el 100% de la comisión de plataforma. El Vendedor no puede auto-referenciarse. Los ciclos circulares en la red son detectados y bloqueados.</p>

<h2>7. Cumplimiento Legal</h2>
<h3>7.1 SAGRILAFT (Colombia)</h3>
<p>El Vendedor declara que cumple con el Sistema de Gestión de Riesgo de Lavado de Activos y Financiación del Terrorismo (Ley 526/1999). La Plataforma registra eventos de seguridad inmutables (triggers MySQL que previenen UPDATE/DELETE). Las transacciones superiores a 10,000 UVT (~$527M COP 2026) son marcadas automáticamente.</p>

<h3>7.2 Habeas Data (Colombia, Ley 1581/2012)</h3>
<p>El Vendedor autoriza el tratamiento de sus datos personales para verificación KYC, cumplimiento SAGRILAFT, prevención de fraude y gestión de la plataforma. Los datos se conservan según SAGRILAFT (5 años, Colombia) y LFPDPPP (10 años, México). El Vendedor puede ejercer sus derechos ARCO escribiendo a <strong>pqrscolombia@lo-tengo.com.co</strong>.</p>

<h3>7.3 Estatuto del Consumidor (Colombia, Ley 1480/2011)</h3>
<p>Los Vendedores están sujetos a las normas de protección al consumidor. La Plataforma ofrece un sistema de disputas (<code>lt_consumer_disputes</code>) con tipos: producto no descrito, dañado, nunca llegó, entrega tardía, producto equivocado. La resolución puede incluir reembolso parcial o total.</p>

<h3>7.4 LFPDPPP (México)</h3>
<p>El Vendedor autoriza el tratamiento de datos personales conforme a la Ley Federal de Protección de Datos Personales en Posesión de los Particulares. Los datos se almacenan cifrados (AES-256-GCM). El Vendedor puede ejercer sus derechos ARCO a través del aviso de privacidad.</p>

<h2>8. Operaciones Cross-Border</h2>
<p>La Plataforma soporta operaciones cross-border (CO ↔ MX y hacia US/EU/BR) con:</p>
<ul>
<li><strong>IOSS/OSS (UE):</strong> IVA país destino para ventas &lt; €150 (Reglamento UE 2017/2455).</li>
<li><strong>De minimis (US):</strong> $800 USD (sección 321 USC).</li>
<li><strong>Declaraciones de aduana:</strong> DIAN (Colombia) y Aduana MX. Tabla <code>lt_customs_declarations</code>.</li>
<li><strong>Tipos de cambio:</strong> Tabla <code>lt_fx_rates</code> con refresh diario.</li>
<li><strong>Incoterms 2020:</strong> DDP, DAP, EXW, FCA, CPT, CIP, DPU soportados.</li>
</ul>

<h2>9. Marketing y Datos de Terceros (Meta/Pixels)</h2>
<p>La Plataforma y los Vendedores pueden utilizar píxeles de marketing (Meta Pixel, Google Analytics 4, Facebook CAPI) sujetos al consentimiento de cookies del Comprador. El consentimiento se gestiona mediante un banner de cookies (<code>ltms_cookie_consent</code>) con tres opciones: Solo esenciales, Personalizar, Aceptar todas. Los píxeles se cargan solo tras el consentimiento.</p>
<p>El Vendedor autoriza a la Plataforma a procesar datos de transacciones para fines de marketing agregado y analítica, en cumplimiento del Reglamento UE 2016/679 (GDPR) para usuarios europeos y la Ley 1581/2012 para usuarios colombianos.</p>

<h2>10. Logística y Envíos</h2>
<p>La Plataforma integra múltiples carriers: Aveonline, Deprisa, Heka, Uber Direct, y domiciliarios propios. El Vendedor es responsable de:</p>
<ul>
<li>Empacar adecuadamente los productos</li>
<li>Generar guías de envío correctamente</li>
<li>Cumplir con los tiempos de entrega acordados</li>
<li>Gestionar devoluciones según la Ley 1480/2011 (CO) o Ley Federal del Consumidor (MX)</li>
</ul>

<h2>11. Seguros (XCover)</h2>
<p>La Plataforma ofrece seguros opcionales vía XCover para protección de envíos y compras. Las pólizas se crean automáticamente al pagar el pedido y se cancelan si el pedido se cancela o reembolsa. Los reclamos se gestionan a través del panel del vendedor.</p>

<h2>12. Suspensión y Terminación</h2>
<p>La Plataforma puede suspender o terminar cuentas por:</p>
<ul>
<li>Incumplimiento de estos Términos</li>
<li>Fraude o actividad sospechosa (SAGRILAFT)</li>
<li>Violación de derechos de propiedad intelectual</li>
<li>Retención legal (investigación, litigio, requerimiento regulatorio)</li>
<li>Inactividad prolongada sin ventas</li>
</ul>
<p>Tras la suspensión, los datos del Vendedor se retienen según los plazos legales (SAGRILAFT 5 años CO / LFPDPPP 10 años MX) antes de su eliminación.</p>

<h2>13. Limitación de Responsabilidad</h2>
<p>La Plataforma actúa como intermediario entre Vendedores y Compradores. No es responsable por la calidad, seguridad o legalidad de los productos ofrecidos por los Vendedores. La responsabilidad de la Plataforma se limita al monto de comisiones retenidas en la transacción objeto del reclamo.</p>

<h2>14. Modificaciones</h2>
<p>La Plataforma puede modificar estos Términos en cualquier momento. Los cambios se notificarán por email a los Vendedores con 15 días de anticipación. El uso continuado de la Plataforma después de los cambios constituye aceptación de los nuevos Términos.</p>

<h2>15. Ley Aplicable</h2>
<p>Para Vendedores en Colombia, estos Términos se rigen por las leyes de la República de Colombia. Para Vendedores en México, por las leyes de los Estados Unidos Mexicanos. Las disputas se resolverán en los tribunales competentes del país del Vendedor.</p>

<p><em>Última actualización: 2026-07-24 — Versión 2.0</em></p>
HTML;

// ═══════════════════════════════════════════════════════════════
// POLÍTICA DE PRIVACIDAD (sin <h1> — WordPress ya muestra el título)
// ═══════════════════════════════════════════════════════════════

$privacy_content = <<<HTML
<h2>1. Responsable del Tratamiento</h2>
<p><strong>Lo Tengo</strong>, marketplace multi-vendor operado sobre WooCommerce en <strong>lo-tengo.com.co</strong> (Colombia) y operaciones en México, es responsable del tratamiento de datos personales de Vendedores y Compradores.</p>
<ul>
<li><strong>Colombia:</strong> Ley 1581 de 2012 (Habeas Data) y Decreto 1377 de 2013.</li>
<li><strong>México:</strong> Ley Federal de Protección de Datos Personales en Posesión de los Particulares (LFPDPPP).</li>
<li><strong>Contacto DPO:</strong> pqrscolombia@lo-tengo.com.co</li>
</ul>

<h2>2. Datos Recopilados</h2>
<h3>2.1 Datos de Identidad (KYC)</h3>
<ul>
<li>Documento de identidad (CC, CE, NIT en CO; RFC, CURP en MX) — <strong>cifrado AES-256-GCM</strong></li>
<li>Selfie de verificación</li>
<li>Registro sanitario INVIMA/COFEPRIS (restaurantes)</li>
<li>RNT/SECTUR (turismo)</li>
<li>RUT (Colombia) o constancia de situación fiscal RFC (México)</li>
</ul>
<p>Los documentos KYC se almacenan cifrados en <strong>Backblaze B2</strong> (bucket <code>lotengo-kyc-docs</code>). El acceso está restringido y se registra en <code>lt_vault_access_log</code>.</p>

<h3>2.2 Datos Bancarios</h3>
<ul>
<li>Número de cuenta bancaria — <strong>cifrado AES-256-GCM</strong></li>
<li>Banco, tipo de cuenta, titular</li>
</ul>
<p>Los datos bancarios se descifran únicamente en el servidor para procesar retiros. En la interfaz se muestran enmascarados (<code>****1234</code>), nunca en texto plano en el DOM.</p>

<h3>2.3 Datos de Transacciones</h3>
<ul>
<li>Historial de ventas, comisiones, retiros</li>
<li>Balance de billetera (ACID ledger con SELECT FOR UPDATE)</li>
<li>Impuestos retenidos (ReteFuente, ReteIVA, ReteICA, ISR, IVA, IEPS)</li>
<li>Declaraciones de aduana (cross-border)</li>
</ul>

<h3>2.4 Datos de Navegación</h3>
<ul>
<li>Dirección IP (para WAF, rate limiting, y geolocalización)</li>
<li>User-Agent, URL de referencia</li>
<li>Cookies (consentimiento gestionado via banner <code>ltms_cookie_consent</code>)</li>
</ul>

<h2>3. Finalidad del Tratamiento</h2>
<ol>
<li><strong>Verificación de identidad (KYC):</strong> Cumplimiento SAGRILAFT (CO) y prevención de fraude.</li>
<li><strong>Cumplimiento fiscal:</strong> Retenciones DIAN (CO) y SAT/CFDI 4.0 (MX).</li>
<li><strong>Procesamiento de pagos:</strong> Retiros vía Openpay (transferencia, Nequi, Daviplata, SPEI, OXXO).</li>
<li><strong>Prevención de lavado de activos:</strong> SAGRILAFT (Ley 526/1999 CO). Transacciones &gt;10,000 UVT marcadas automáticamente.</li>
<li><strong>Logística:</strong> Integración con Aveonline, Deprisa, Heka, Uber Direct.</li>
<li><strong>Marketing:</strong> Píxeles de Meta (Facebook), Google Analytics 4, Facebook CAPI — sujetos al consentimiento de cookies del usuario.</li>
<li><strong>Soporte:</strong> Gestión de disputas (Ley 1480/2011 CO, Ley Federal del Consumidor MX).</li>
</ol>

<h2>4. Base Legal</h2>
<ul>
<li><strong>Colombia:</strong> Ley 1581/2012 art. 10 (datos sensibles) — autorización explícita del titular.</li>
<li><strong>México:</strong> LFPDPPP art. 8 — consentimiento del titular.</li>
<li><strong>UE (si aplica):</strong> GDPR art. 6(1)(b) (ejecución de contrato) y art. 6(1)(c) (cumplimiento legal).</li>
</ul>

<h2>5. Medidas de Seguridad</h2>
<h3>5.1 Cifrado</h3>
<ul>
<li><strong>AES-256-GCM (v2):</strong> Cifrado autenticado para datos sensibles (cuentas bancarias, documentos, tokens).</li>
<li><strong>AES-256-CBC (v1, legacy):</strong> Retrocompatible para datos cifrados antes de v2.9.61.</li>
<li><strong>Clave maestra:</strong> <code>WP_LTMS_MASTER_KEY</code> en <code>wp-config.php</code> (nunca en BD).</li>
<li><strong>Derivación de clave:</strong> PBKDF2-SHA256, 600,000 iteraciones (NIST SP 800-132).</li>
</ul>

<h3>5.2 WAF (Web Application Firewall)</h3>
<p>Firewall integrado que detecta y bloquea: SQL injection, XSS, LFI/RFI, bad bots. IP banning automático tras 10 triggers (24h). Registros en <code>lt_security_events</code> con triggers MySQL que previenen modificación (inmutabilidad forense).</p>

<h3>5.3 Autenticación de Dos Factores (2FA)</h3>
<p>TOTP RFC 6238 (Google Authenticator, Authy). Secret de 160 bits cifrado AES-256-GCM. 10 códigos de recuperación (bcrypt cost 12). Rate limiting: 5 intentos / 15 min → bloqueo temporal.</p>

<h3>5.4 Logging Forense</h3>
<p>Tabla <code>lt_forensic_log</code> con cadena hash SHA-256 (cada entry enlaza al anterior). Cualquier modificación o eliminación es detectable via <code>verify_chain()</code>.</p>

<h2>6. Retención de Datos</h2>
<table style="width:100%;border-collapse:collapse;">
<thead>
<tr style="background:#f3f4f6;">
<th style="padding:8px;border:1px solid #ddd;text-align:left;">Tipo de Dato</th>
<th style="padding:8px;border:1px solid #ddd;text-align:left;">Colombia</th>
<th style="padding:8px;border:1px solid #ddd;text-align:left;">México</th>
</tr>
</thead>
<tbody>
<tr><td style="padding:8px;border:1px solid #ddd;">KYC / Identidad</td><td style="padding:8px;border:1px solid #ddd;">5 años (SAGRILAFT)</td><td style="padding:8px;border:1px solid #ddd;">10 años (LFPDPPP + SAT)</td></tr>
<tr><td style="padding:8px;border:1px solid #ddd;">Transacciones</td><td style="padding:8px;border:1px solid #ddd;">5 años (Estatuto Tributario)</td><td style="padding:8px;border:1px solid #ddd;">10 años (CFF)</td></tr>
<tr><td style="padding:8px;border:1px solid #ddd;">Logs de seguridad</td><td style="padding:8px;border:1px solid #ddd;">Inmutables (sin expiración)</td><td style="padding:8px;border:1px solid #ddd;">Inmutables (sin expiración)</td></tr>
<tr><td style="padding:8px;border:1px solid #ddd;">Consentimiento de cookies</td><td style="padding:8px;border:1px solid #ddd;">2 años</td><td style="padding:8px;border:1px solid #ddd;">2 años</td></tr>
</tbody>
</table>
<p>Tras el período de retención, los documentos KYC se eliminan de Backblaze B2 y los datos se anonimizan en la BD. La eliminación se registra en <code>lt_retention_log</code>.</p>

<h2>7. Derechos ARCO</h2>
<p>Como titular de datos personales, puedes ejercer:</p>
<ul>
<li><strong>Acceso:</strong> Solicitar copia de tus datos personales.</li>
<li><strong>Rectificación:</strong> Corregir datos inexactos o incompletos.</li>
<li><strong>Cancelación:</strong> Solicitar la supresión de tus datos (sujeto a retención legal).</li>
<li><strong>Oposición:</strong> Oponerte al tratamiento para fines específicos (marketing).</li>
<li><strong>Portabilidad:</strong> Recibir tus datos en formato estructurado.</li>
<li><strong>Revocación del consentimiento:</strong> Retirar tu autorización en cualquier momento.</li>
</ul>
<p>Para ejercer estos derechos, escribe a <strong>pqrscolombia@lo-tengo.com.co</strong> con asunto "Derechos ARCO". La Plataforma tiene 15 días hábiles (CO) / 20 días hábiles (MX) para responder.</p>

<h2>8. Cookies y Marketing</h2>
<p>La Plataforma utiliza cookies con consentimiento explícito (banner <code>ltms_cookie_consent</code>):</p>
<ul>
<li><strong>Solo esenciales:</strong> Cookies de sesión, seguridad (WAF, nonces). Siempre activas.</li>
<li><strong>Personalizar:</strong> El usuario selecciona qué cookies acepta.</li>
<li><strong>Aceptar todas:</strong> Incluye cookies de marketing.</li>
</ul>
<p><strong>Meta Pixel (Facebook):</strong> Se carga solo tras consentimiento. Utiliza Advanced Matching para optimización de campañas. Los datos enviados a Meta se rigen por la <a href="https://www.facebook.com/legal/terms/data_processing_terms" target="_blank" rel="noopener">Data Processing Terms de Meta</a>.</p>
<p><strong>Google Analytics 4:</strong> Se carga solo tras consentimiento. IP anonimizada. Datos de analytics agregados.</p>
<p><strong>Facebook CAPI (Conversions API):</strong> Eventos de conversión enviados server-side, sujetos al consentimiento de cookies.</p>

<h2>9. Transferencias Internacionales (Cross-Border)</h2>
<p>Los datos pueden transferirse a:</p>
<ul>
<li><strong>Backblaze B2 (EE.UU.):</strong> Almacenamiento de documentos KYC cifrados.</li>
<li><strong>Openpay (CO/MX):</strong> Procesamiento de pagos.</li>
<li><strong>Meta/Google (EE.UU.):</strong> Píxeles de marketing (solo tras consentimiento).</li>
<li><strong>ZapSign (CO/MX):</strong> Firma de contratos de vendedor.</li>
<li><strong>XCover (global):</strong> Seguros de envío y compra.</li>
</ul>
<p>Cada transferencia se realiza bajo las garantías adecuadas según Ley 1581/2012 art. 26 (CO) y LFPDPPP art. 36 (MX).</p>

<h2>10. Auditor Externo</h2>
<p>La Plataforma ofrece un rol de <strong>Auditor Externo</strong> (<code>ltms_external_auditor</code>) con acceso de solo lectura a datos fiscales. Los datos comerciales sensibles (email, teléfono, dirección) se enmascaran automáticamente. Cada acceso del auditor se registra en <code>lt_security_events</code>.</p>

<h2>11. Derecho al Olvido (GDPR / Ley 1581)</h2>
<p>El Vendedor puede solicitar la eliminación de sus datos personales. La Plataforma elimina:</p>
<ul>
<li>Documentos KYC de Backblaze B2</li>
<li>Fotos de perfil y store logo</li>
<li>Datos bancarios cifrados</li>
<li>Contratos firmados (ZapSign + B2 backup)</li>
<li>Metadatos de usuario (<code>ltms_*</code> en usermeta)</li>
</ul>
<p><strong>Excepción:</strong> Si el Vendedor está bajo retención legal (<code>ltms_legal_hold</code>), los datos NO se eliminan hasta que se levante la retención.</p>

<h2>12. Cambios a esta Política</h2>
<p>La Plataforma puede actualizar esta Política de Privacidad. Los cambios se notificarán por email con 15 días de anticipación. La versión vigente se identifica por la fecha de actualización.</p>

<p><em>Última actualización: 2026-07-24 — Versión 2.0</em></p>
HTML;

// Output para wp-cli
if ( php_sapi_name() === 'cli' && isset( $argv[1] ) ) {
    echo $argv[1] === 'terms' ? $terms_content : $privacy_content;
}
HTML;

// Output para wp-cli
if ( php_sapi_name() === 'cli' && isset( $argv[1] ) ) {
    echo $argv[1] === 'terms' ? $terms_content : $privacy_content;
}
