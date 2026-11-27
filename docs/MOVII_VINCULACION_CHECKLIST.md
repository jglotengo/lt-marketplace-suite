# Checklist de Vinculación con MOVii (Pasarela de Pagos)

> **Contexto**: MOVii (banktech colombiano, regulado SFC + Fogafín, 5M+ usuarios) clasifica a LTMS
> como **Agregador de Pago / PayFac**. Este checklist consolida los requisitos oficiales de
> vinculación (PDF "Requisitos de Vinculación para Agregadores de Pago", empresas.movii.com.co),
> las preguntas técnicas/comerciales para la primera reunión y las decisiones internas que
> bloquean el arranque de la integración.
>
> **Referencia técnica interna**: el contrato que la integración debe cumplir está documentado en
> el patrón de 7 piezas (cliente API, gateway WC, webhook, settings, JS, orquestador, tests) —
> ver `includes/api/class-ltms-abstract-api-client.php` y `includes/core/class-ltms-kernel.php:1011`.

---

## FASE 0 — Decisiones de negocio (bloqueantes, definir antes de contactar)

| # | Decisión | Opciones | Estado |
|---|----------|----------|--------|
| D1 | Alcance de MOVii en LTMS | (a) solo dispersión de payouts · (b) solo recaudo · (c) ambos | ☐ Pendiente |
| D2 | Posición frente a proveedores actuales | 3er proveedor en orquestador (coexiste con Openpay/Stripe) · reemplazo de Openpay | ☐ Pendiente |
| D3 | Fee de retiro vía MOVii (si D1 incluye dispersión) | $0 (absorbe plataforma) · tarifa plana (hoy Openpay = $4.000 COP) · pass-through costo MOVii | ☐ Pendiente |
| D4 | Responsable de la relación comercial con MOVii | Nombre/cargo de quien firma y del técnico de enlace | ☐ Pendiente |

**Recomendación técnica registrada** (sujeta a decisión del negocio): empezar por **D1=(a) dispersión** —
mayor ROI (payouts instantáneos 24/7 vía Transfiya/Bre-B vs. 1-3 días hábiles actuales), menor
superficie de riesgo (no toca checkout), y no requiere migración del ENUM `method` de
`bkr_lt_payout_requests` hasta estar en (c).

---

## FASE 1 — Documentos que MOVii exige para vinculación

Responsables sugeridos: **G** = Gerencia, **C** = Contabilidad, **L** = Legal/Compliance, **T** = Tecnología.

### 1.1 Identificación de la empresa

- [ ] **G** — Nombre comercial de la empresa (lo-tengo.com.co / Lo Tengo)
- [ ] **G** — Razón social de la empresa (la de los documentos de cámara de comercio)
- [ ] **G** — NIT de la empresa
- [ ] **G** — Nombre del representante legal
- [ ] **G** — Correo corporativo (no personal) de contacto
- [ ] **G** — Número de contacto corporativo
- [ ] **G** — Dirección de la empresa
- [ ] **G** — Sitio web de la empresa (https://lo-tengo.com.co)

### 1.2 Documentos legales y tributarios

- [ ] **L** — Formulario de Vinculación diligenciado (lo entrega MOVii en el proceso)
- [ ] **L** — **Certificado de Existencia y Representación Legal** (Cámara de Comercio, emitido
      con antigüedad < 90 días; verificar vigencia al adjuntar)
- [ ] **L** — **RUT de la empresa** (copiar el que ya exigimos a vendedores personas jurídicas —
      mismo estándar documental que `class-ltms-dashboard-logic.php` aplica a nuestros vendors)
- [ ] **L** — **Cédula de ciudadanía del representante legal** (copia legible, vigente)

### 1.3 Estados financieros

- [ ] **C** — **Estados financieros firmados y certificados** por contador o revisor fiscal
      (último ejercicio cerrado; si MOVii pide intermedios, confirmar en reunión)
- [ ] **C** — **Tarjeta profesional + cédula del contador/revisor fiscal** que firma

> Nota: si la operación está en crecimiento y los EEFF certificados están desactualizados,
> preguntar a MOVii si aceptan EEFF de gestión + proyecciones. No asumir.

### 1.4 Compliance y riesgo

- [ ] **T** — **Certificación PCI** — MOVii la exige. LTMS opera bajo **PCI DSS SAQ-A**
      (la plataforma no almacena PAN; tokenización en el gateway — ver
      `class-ltms-fintech-compliance.php` FT-5). Acción: confirmar con MOVii que el
      **AOC de SAQ-A** (o autoevaluación firmada) es suficiente para esquema agregador, o si
      exigen otro nivel (SAQ D) — **pregunta clave de la primera reunión**.
- [ ] **L** — **Composición accionaria detallada y certificada** (acta/extracto de libro de
      registro de accionistas o certificación de la Cámara de Comercio; incluir beneficiarios
      finales / UBO si hay participaciones indirectas)
- [ ] **L** — **Cuestionario de Riesgos y Control de MOVii** (su SARLAFT sobre nosotros).
      Insumos con los que LTMS ya cuenta para responderlo (todo implementado y auditable en el
      propio plugin):
      - KYC de vendedores con expiración anual y gating de payouts
        (`class-ltms-dashboard-logic.php`, `class-ltms-admin-payouts.php`)
      - Screening listas restrictivas OFAC/ONU fail-closed + re-screening mensual
        (`class-ltms-fintech-compliance.php:493-655`)
      - Reportes SOS UIAF (Res. 029/2014) y oficial de cumplimiento designado
      - Límites anti-estructuración (US$5.000/día, US$20.000/mes, 50 tx/día) y Travel Rule FATF R.16
      - Wallet ledger inmutable con checksum y bitácoras de acceso a PII (Ley 1581)
      - 2FA obligatorio para vendedores con payouts recientes

### 1.5 Comercial

- [ ] **G** — Presentación comercial de la empresa (deck breve: qué es Lo Tengo, GMV,
      número de vendedores activos, ticket promedio, métodos de pago actuales, proyección)
      — **solicitar al equipo el dato de GMV mensual y # vendors aprobados KYC antes de armarlo**

---

## FASE 2 — Preguntas para la primera reunión con MOVii (no cerrar sin estas respuestas)

### Comerciales / esquema
- [ ] ¿Esquema aplicable a marketplaces/agregadores: cómo quedan reportados fiscalmente los
      pagos de sub-comercios (vendedores)? ¿MOVii figura como adquirente de registro y qué
      implica para nuestros reportes DIAN exógenos y contabilidad Alegra?
- [ ] Tarifas: % de adquirencia por método (tarjeta, PSE, QR/Transfiya, efectivo red física),
      costo por dispersión (Transfiya / ACH / Visa Direct / Bre-B), cargo mensual, setup.
      (Referencia pública: PDF "Tarifas adquirencia MOVii" en su sitio — pedir vigente 2026)
- [ ] **Compensación (settlement)**: ¿cada cuánto llega el dinero recaudado a nuestra cuenta?
      ¿T+1, T+0, horas? ¿Hay hold inicial para nuevo comercio? — crítico para nuestro modelo
      gather-pay-disperse y el calendario de payouts.
- [ ] Régimen de fondos: siendo MOVii entidad vigilada SFC/Fogafín, ¿los saldos que mantengamos
      con ellos están protegidos? ¿exigen cuenta segregada para fondos de terceros?
- [ ] Límites operativos que MOVii aplicaría sobre nosotros (por transacción, diario, mensual)
      y proceso para subirlos.

### Técnicas
- [ ] **Paquete de documentación de API + acceso a sandbox** (el sitio público NO expone docs —
      sin esto no hay estimación real). Pedir: endpoints de charge/authorize, dispersión
      (payouts masivos), webhooks.
- [ ] Firma de webhooks: ¿HMAC-SHA256 con secret por comercio? ¿replay/idempotencia soportada
      por su lado? (nosotros exigimos fail-closed + idempotencia — patrón
      `class-ltms-openpay-webhook-handler.php`).
- [ ] Soporte de **idempotency-key** en sus endpoints de cobro y dispersión (anti doble pago —
      hoy Openpay usa `Idempotency-Key: ltms_payout_{ref}`, Stripe `ltms_pi_{order}`).
- [ ] API de dispersión: ¿soporta pagos a número de celular (Transfiya), cuenta bancaria
      (ACH/Bre-B) y tarjeta (Visa Direct/Mastercard Send)? ¿montos mínimos, horarios, estados
      de confirmación y webhook de resultado?
- [ ] Tokenización de tarjeta: ¿token host-side o recolección JS client-side (PCI SAQ-A)?
- [ ] Ambiente de pruebas: ¿sandbox completo con simulación de webhooks y declines?
- [ ] 3DS y antifraude (Decision Manager): ¿incluido o costo adicional? ¿quién asume el chargeback?
- [ ] SLA de disponibilidad y soporte (¿24/7?), canal de escalamiento, contacto técnico asignado.
- [ ] Tiempo estimado del proceso de vinculación completo ( KYC de ellos → firma → sandbox →
      producción).

---

## FASE 3 — Pre-requisitos técnicos de integración (una vez firmado y con sandbox)

Alcance según D1. Checklist de construcción siguiendo el patrón interno de 7 piezas:

- [ ] 1. `LTMS_Api_Movii` extendiendo `LTMS_Abstract_API_Client`
      (métodos: `health_check()`, `get_provider_slug() = 'movii'`, charge/refund/disperse)
      + registro en `LTMS_Api_Factory::$client_map`
- [ ] 2. `LTMS_Api_Gateway_Movii` extends `WC_Payment_Gateway`
      (`process_payment`, `process_refund`, `init_form_fields`, `payment_fields`)
      + registro en `LTMS_Core_Kernel::register_payment_gateways()` (kernel:1011)
- [ ] 3. `LTMS_Movii_Webhook_Handler`: firma HMAC fail-closed, idempotencia por transient,
      rate-limit 100/min, entrada en `LTMS_Api_Webhook_Router::$handlers`
- [ ] 4. Settings en `includes/admin/views/settings/section-payments.php`
      + secretos en `$encrypted_fields` de `class-ltms-admin-settings.php:114`
      (⚠️ no repetir el gap AD-SET-116: TODO secreto nuevo debe entrar a la lista de cifrado)
- [ ] 5. JS de tokenización en `assets/js/` (PAN nunca toca el servidor)
- [ ] 6. Payout método `movii` en `LTMS_Payout_Scheduler`:
      - ⚠️ Migración del ENUM `method` de `bkr_lt_payout_requests`
        (hoy: `bank_transfer,paypal,nequi,daviplata,spei,clabe`) — idempotente, patrón
        `class-ltms-db-migrations.php`
      - Fee configurable (decisión D3)
      - Webhook de resultado de dispersión → `ltms_payout_completed/failed`
- [ ] 7. Etiqueta `'movii'` en el mapa contable de `class-ltms-commission-writer.php:316`
      (registro fiscal Art. 30-B / DIAN)
- [ ] 8. Routing en `LTMS_Payment_Orchestrator::select_gateway()` + entrada en circuit breaker
      (tabla `bkr_lt_provider_health`)
- [ ] 9. Tests PHPUnit (patrón `GatewayStripeTest`) + suite completa en verde (≥ 4,851 tests)
- [ ] 10. Bump `LTMS_VERSION` para cache-busting de assets

---

## Registro de avance

| Fecha | Avance | Responsable |
|-------|---------|-------------|
|       |         |             |
