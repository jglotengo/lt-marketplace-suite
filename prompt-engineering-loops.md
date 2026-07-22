# Prompt Engineering para Loops de Auditoría Autónoma — LT Marketplace Suite

> Guía de mejores prácticas para escribir prompts que disparan loops de agente
> ("vibe coding" autónomo) sobre este repositorio. Complementa a `AGENTS.md`
> (reglas de ingeniería) y `CLAUDE.md` (flujo de trabajo de 3 pasos). Está basada
> en los ciclos de auditoría reales ya ejecutados sobre el proyecto
> (`REG-AUDIT-001`, `DEEP-AUDIT-002`, `UIUX-AUDIT-001`, ciclo "Plaza Viva"
> v2.9.178→v2.9.187) documentados en `QA_REPORT.md`, `CHANGELOG.md` y las 112+
> lecciones de `LECCIONES_APRENDIDAS.md`.

---

## 1. Por qué un prompt de loop "abierto" falla

El prompt original que motivó este documento fue, en esencia:

> *"Audita todo el proyecto, soluciona todos los hallazgos, re-audita, repite las
> veces necesarias hasta el 100% de productividad. Eres autónomo, no preguntes."*

Funciona como intención, pero como prompt de ingeniería tiene tres huecos que en
un repo de este tamaño (3.620 archivos PHP, wallet ACID, cumplimiento
DIAN/SAT, KYC) se pagan caro:

| Problema | Por qué falla | Qué pasa en la práctica |
|---|---|---|
| Sin alcance declarado | "todo el proyecto" no es un conjunto verificable | El agente re-audita módulos ya estables, gasta presupuesto, o dos ciclos paralelos se pisan (ver `AGENTS.md` → "Alcance") |
| Sin condición de parada | "100% de productividad" no es medible | El loop no converge; sigue "encontrando" hallazgos P2 cosméticos indefinidamente |
| "No preguntes" sin excepciones | Choca con reglas ya no-negociables del proyecto (financiero, KYC, segunda revisión) | El agente puede tomar una decisión de negocio (ej. tasa de comisión, proveedor de pago) sin que nadie la apruebe |

La versión mejorada no elimina la autonomía — la acota a lo que **sí** puede
decidirse sin intervención humana, y define explícitamente cuándo parar y cuándo
escalar.

---

## 2. Anatomía de un buen prompt de loop

Un prompt de loop autónomo efectivo tiene siempre estas seis partes. Faltar
cualquiera de ellas es la causa más común de loops que no convergen o que se
salen de alcance.

```xml
<contexto>
  Qué es el sistema, qué stack usa, qué documentos de referencia existen
  (CLAUDE.md, AGENTS.md, LECCIONES_APRENDIDAS.md). No asumas que el agente
  "ya sabe" — nómbralos explícitamente.
</contexto>

<alcance>
  Módulo(s) o directorio(s) exactos. "includes/business/" no "el proyecto".
  Si de verdad es todo el proyecto, dilo explícitamente y exige un inventario
  previo (paso 1 del loop) antes de tocar nada.
</alcance>

<criterio_de_hallazgo>
  Qué cuenta como "hallazgo": ¿solo bugs funcionales? ¿también deuda técnica?
  ¿también cosmético? Define P0/P1/P2 igual que QA_REPORT.md.
</criterio_de_hallazgo>

<autonomia_y_limites>
  Qué puede decidir solo el agente vs. qué requiere pausa. Nombra los módulos
  críticos explícitamente (wallet, payouts, KYC, ZapSign, gateways de pago).
</autonomia_y_limites>

<condicion_de_parada>
  Medible, no aspiracional. Ver sección 4.
</condicion_de_parada>

<formato_de_entrega>
  Qué reporte final esperas: tabla de hallazgos, changelog, lecciones nuevas,
  diffs para revisar. Ver sección 6.
</formato_de_entrega>
```

### Ejemplo aplicado a este repo

```
<contexto>
Trabajas sobre lt-marketplace-suite (LTMS), plugin WordPress/WooCommerce.
Sigue las reglas de CLAUDE.md (flujo de 3 pasos) y AGENTS.md (Explorar→
Planificar→Ejecutar→Revisar + sección "Loop de auditoría autónoma"). Consulta
LECCIONES_APRENDIDAS.md antes de tocar autoloader, nonces, o cache de SiteGround.
</contexto>

<alcance>
includes/business/ (wallet, comisiones, payouts) y su cobertura en tests/
Fuera de alcance: includes/gateway/, includes/booking/, frontend/.
</alcance>

<criterio_de_hallazgo>
P0: cualquier ruta donde el dato de dinero mostrado/persistido pueda divergir
del real (redondeo, condición de carrera, falta de lock).
P1: bug funcional sin impacto monetario directo (UI, validación, mensaje de error).
P2: deuda técnica, código muerto, falta de test en rama ya cubierta.
</criterio_de_hallazgo>

<autonomia_y_limites>
Autónomo para: leer código, escribir fixes P1/P2, escribir tests, correr
PHPUnit, hacer commits atómicos con Conventional Commits.
Pausa y pregunta si: un fix P0 cambia el comportamiento observable de un cálculo
financiero ya en producción (requiere aprobación antes de mergear, no solo de
codear). Nunca asumas la resolución de un caso de negocio ambiguo (ej. qué pasa
con un payout ya aprobado si se detecta el bug).
</autonomia_y_limites>

<condicion_de_parada>
Detente cuando: (a) los 3 archivos del alcance fueron auditados al menos una vez,
(b) una re-auditoría no arroja P0/P1 nuevos, (c) PHPUnit completo pasa en verde.
Máximo 3 ciclos de re-auditoría; si el 3° ciclo sigue arrojando hallazgos del
mismo tipo, detente y repórtalo como causa raíz arquitectónica pendiente.
</condicion_de_parada>

<formato_de_entrega>
Tabla de hallazgos (id, archivo:línea, severidad, fix, test), entrada nueva en
CHANGELOG.md, y si aplica, lección nueva en LECCIONES_APRENDIDAS.md.
</formato_de_entrega>
```

---

## 3. El loop en sí: Explorar → Auditar → Priorizar → Fix → Re-auditar

Ver `AGENTS.md` → "Loop de auditoría autónoma" para el procedimiento completo.
Puntos de prompt engineering específicos por fase:

- **Explorar/Inventario**: pide explícitamente el escaneo recursivo del árbol
  (`git/trees/{branch}?recursive=1`) en vez de dejar que el modelo adivine rutas
  por nombre de clase — el propio `AGENTS.md` ya lo señala como más confiable.
- **Auditar**: exige evidencia por hallazgo (`archivo:línea` + comportamiento
  observado vs. esperado), nunca "parece que hay un problema en X". Un hallazgo
  sin evidencia verificable no es un hallazgo, es una sospecha.
- **Priorizar**: usa la misma escala P0/P1/P2 que ya usa el proyecto
  (`QA_REPORT.md`) — reutilizar el vocabulario existente evita ambigüedad entre
  sesiones y entre agentes distintos.
- **Fix**: un hallazgo, un fix, un test — nunca un commit que agrupa "varios
  arreglos relacionados" sin desglosar, porque rompe la trazabilidad exigida en
  `AGENTS.md` → "Trazabilidad".
- **Re-auditar**: repite el auditar solo sobre lo tocado en este ciclo, no todo
  el alcance de nuevo desde cero (salvo que sea el ciclo de cierre) — si no, el
  costo crece cuadráticamente con el número de iteraciones.

---

## 4. Condiciones de parada: la parte que más se olvida

Un loop sin condición de parada verificable es la causa #1 de que un agente
"itere para siempre" o se detenga arbitrariamente a mitad de camino. Usa
condiciones que se puedan responder con sí/no a partir de datos reales del
repo, no con una sensación de "ya está bastante bien":

| Mala condición | Buena condición |
|---|---|
| "hasta el 100% de productividad" | "hasta que la re-auditoría del alcance declarado arroje 0 hallazgos P0/P1" |
| "hasta que quede perfecto" | "hasta que PHPUnit completo pase en verde (≥ N tests, sin regresión)" |
| "arréglalo todo" | "arregla los hallazgos del inventario inicial; los nuevos que aparezcan en la re-auditoría van a un ciclo 2 explícito, no se mezclan con el ciclo 1" |
| (sin límite de iteraciones) | "máximo 3 ciclos de re-auditoría por módulo; al 3° reporta causa raíz en vez de seguir parcheando síntomas" |

Un límite de iteraciones no es desconfianza en el agente — es lo mismo que un
`max_retries` en cualquier sistema: sin él, un bug que genera otro bug al
arreglarse (loop de regresión) consume presupuesto indefinidamente sin que nadie
se entere hasta que alguien revisa la factura o el historial de commits.

---

## 5. Autonomía real vs. autonomía de fachada

"No preguntes" es útil para eliminar fricción en decisiones **técnicas** donde
solo hay una respuesta correcta (¿el método debe ser `public`? sí, si otra clase
lo invoca — ver Regla #3 de `LECCIONES_APRENDIDAS.md`). Es peligroso aplicado sin
matices a decisiones **de negocio o de riesgo**, que es exactamente lo que
`AGENTS.md` ya distingue en "Decisiones de producto" y "Revisión como último
filtro".

Regla práctica para el prompt: enumera explícitamente qué NO puede decidir el
agente solo, en vez de confiar en que "usará buen juicio". En este proyecto, como
mínimo:
- Cambios de tasas, comisiones o montos.
- Elección entre proveedores/canales cuando ambos son técnicamente válidos
  (ej. Openpay custom vs. plugin oficial, ver `userMemories` → conflicto Openpay
  pendiente).
- Cualquier cambio de comportamiento observable en wallet, payouts, KYC/SAGRILAFT,
  o webhooks de ZapSign/Backblaze, sin la segunda pasada de revisión.
- Qué hacer con datos de un vendor específico ya bloqueado o en disputa.
- Rotación o manejo de credenciales expuestas — repórtalo, no lo "arregles" tú
  mismo generando una nueva credencial sin que el dueño del proyecto la rote.

---

## 6. Formato de entrega de cada ciclo

Un loop que no deja rastro verificable es indistinguible de un loop que no hizo
nada. Cada ciclo debe cerrar con:

1. **Tabla de hallazgos** (id, archivo:línea, severidad, causa raíz, fix aplicado,
   test que lo cubre) — mismo formato que ya usa `QA_REPORT.md`.
2. **Entrada en `CHANGELOG.md`** siguiendo Keep a Changelog + Conventional
   Commits, con el resumen de bugs por prioridad y tests nuevos (ver el patrón
   del ciclo Plaza Viva como referencia).
3. **Lección nueva en `LECCIONES_APRENDIDAS.md`** solo si el hallazgo revela un
   patrón reincidente o una regla preventiva nueva (no cada bug individual
   necesita una lección — solo los que enseñan algo generalizable).
4. **Backlog explícito de lo que quedó pendiente** y por qué (ambigüedad de
   negocio, requiere decisión humana, fuera del alcance declarado del ciclo).

---

## 7. Checklist rápido antes de lanzar un loop

- [ ] ¿El alcance es un conjunto de archivos/módulos verificable, no "todo"?
- [ ] ¿La condición de parada se puede responder con sí/no desde el repo (tests,
      re-auditoría, inventario), no desde una sensación subjetiva?
- [ ] ¿Hay un límite explícito de iteraciones de re-auditoría?
- [ ] ¿Está explícito qué decisiones NO puede tomar el agente solo?
- [ ] ¿El formato de entrega por ciclo está definido (tabla de hallazgos +
      changelog + lecciones)?
- [ ] ¿Los módulos financieros/KYC/pagos tienen la segunda revisión exigida en
      `AGENTS.md`, incluso dentro del loop?
- [ ] ¿Ninguna credencial o secreto va a terminar en un commit, log, o prompt
      como texto plano durante el loop?

---

## 8. Referencia cruzada

- `CLAUDE.md` — flujo de 3 pasos (Análisis → Validación SSH → Commit), datos de
  acceso, convenciones de nomenclatura.
- `AGENTS.md` — reglas de ingeniería no-negociables + sección "Loop de auditoría
  autónoma" (el procedimiento operativo que este documento explica cómo redactar
  en forma de prompt).
- `LECCIONES_APRENDIDAS.md` — 112+ lecciones ya extraídas de ciclos reales;
  léelo antes de auditar para no "redescubrir" un bug ya documentado.
- `QA_REPORT.md` / `CHANGELOG.md` — formato de referencia para reportar
  hallazgos y cerrar ciclos.
