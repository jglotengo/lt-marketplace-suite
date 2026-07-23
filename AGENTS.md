# AGENTS.md — reglas de ingeniería para trabajo asistido por IA

## Stack y comandos
- Lenguaje/framework: PHP 8.1+, WordPress 6.3+, WooCommerce 8.0+, plugin `lt-marketplace-suite` (LTMS)
- Base de datos: MySQL 8.0, prefijo de tablas `bkr_` (no `wp_`), tablas custom con prefijo `bkr_lt_`
- Frontend: jQuery/AJAX (sin build step de JS/CSS — los assets se editan directamente)
- Hosting: SiteGround (hosting compartido) — ver notas de OPcache abajo
- Instalar dependencias: no aplica build de Node; dependencias PHP vía Composer si el módulo lo requiere (`composer install`)
- Recargar el plugin (equivalente a "build/dev"):
  ```bash
  wp plugin deactivate lt-marketplace-suite --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html
  wp plugin activate  lt-marketplace-suite --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html
  ```
- Correr TODA la suite de tests:
  ```bash
  ./vendor/bin/phpunit --configuration phpunit.xml
  ```
- Correr un solo grupo de tests:
  ```bash
  ./vendor/bin/phpunit --group kyc
  ./vendor/bin/phpunit --group commissions
  ./vendor/bin/phpunit --group aveonline
  ```
- Lint / typecheck (sintaxis PHP):
  ```bash
  php -l /home/customer/www/lo-tengo.com.co/public_html/wp-content/plugins/lt-marketplace-suite/<archivo>.php
  ```

## Flujo de trabajo obligatorio: Explorar → Planificar → Ejecutar → Revisar
No saltes directo a editar código, ni siquiera en tareas que parezcan simples.
1. **Explorar**: lee los archivos relevantes al alcance de la tarea antes de proponer
   cambios. No asumas el comportamiento de un módulo sin leerlo. Para búsquedas
   amplias en el código, usar el escaneo recursivo del árbol de Git
   (`GET /repos/{REPO}/git/trees/main?recursive=1`) es más confiable que adivinar
   rutas.
2. **Planificar**: si la tarea es ambigua, no trivial, o toca más de un archivo,
   escribe primero un plan breve (qué vas a cambiar y por qué) antes de escribir
   código. En herramientas con "plan mode" (solo lectura), úsalo.
3. **Ejecutar**: implementa siguiendo el plan. Si descubres que el plan estaba mal
   a mitad de camino, dilo explícitamente antes de desviarte, no cambies de rumbo
   en silencio.
4. **Revisar**: corre la verificación (ver abajo) antes de declarar la tarea
   terminada. "Creo que funciona" no es revisión.

## Alcance
- No modifiques código fuera del alcance de la tarea asignada, salvo que sea
  estrictamente necesario para completarla. Si detectas una mejora fuera de alcance,
  repórtala por separado.
- No toques código ya verificado como funcionando en una tarea anterior, a menos que
  la tarea actual lo requiera explícitamente.
- Si la instrucción es abierta o ambigua, define 2-3 casos de prueba concretos que
  acoten el alcance antes de codear. Si sigue ambiguo, pregunta (ver "Decisiones de
  producto" abajo) en vez de asumir.

## Verificación — no negociable
- Todo fix, sin excepción (incluyendo cambios de una línea), viene con un test que lo
  verifica cuando exista infraestructura de test para ese módulo (PHPUnit). Si el
  módulo no tiene tests, la validación mínima es `php -l` + verificación funcional
  vía SSH (ver Paso 2 de `CLAUDE.md`).
- El test debe importar y ejercer el código real, nunca reimplementar la misma lógica
  de forma aislada dentro del propio archivo de test.
- Al terminar cada cambio, corre la suite completa de PHPUnit (no solo el test nuevo)
  y confirma explícitamente que nada existente se rompió (debe mantenerse en verde,
  ≥ 3,283 tests) antes de dar la tarea por terminada.
- Validación SSH obligatoria antes de commit: `php -l`, recarga del plugin,
  `tail -n 50 error_log`, `wp cache flush`, y verificación de la clase nueva con
  `wp eval 'var_dump(class_exists("..."));'`. Ver Paso 2 completo en `CLAUDE.md`.
- Si la ambigüedad lo permite, deja verificación automática no-negociable configurada
  (`php -l` tras cada edición) en vez de depender solo del juicio del modelo en
  cada paso.
- Si abandonas un enfoque técnico a mitad de tarea (rename de método, refactor,
  pivote de diseño — ej. reemplazar una implementación por otra), actualiza o
  elimina en el MISMO commit cualquier test que cubriera el método/enfoque viejo.
  Un test huérfano no falla de inmediato (sigue siendo sintácticamente válido),
  pero rompe la suite completa en un commit futuro no relacionado, obligando a
  investigar una causa raíz vieja como si fuera nueva. Ver
  `LECCIONES_APRENDIDAS.md` #119 para un caso real de este proyecto.

## Contra los "fixes cosméticos"
- Un cambio que "se ve" resuelto no cuenta como resuelto hasta que verificaste el
  dato/comportamiento de punta a punta: desde su origen (DB, servicio, config) hasta
  su destino final (pantalla, respuesta de API, efecto real). Ejemplo real: el bug de
  checkboxes en admin settings parecía resuelto en el código pero seguía reseteando
  hasta confirmar el guardado real por sección.
- No des por buena una función, componente o módulo nuevo sin confirmar que algo más
  en el sistema realmente lo consume (hook registrado, clase cargada en el
  autoloader/kernel). Código que nadie invoca no es una solución.
- Desconfía de comentarios/documentación que describen una intención sin código real
  que la ejecute.
- Recuerda: los bloques `<style id="ltms-sf-critical">` inyectados inline vía PHP
  tienen prioridad sobre `ltms-storefront.css`. Un fix de CSS externo que no se
  refleja puede estar siendo silenciosamente sobreescrito por el bloque inline —
  hay que sincronizar ambos.
- Si un cambio de CSS/JS no se refleja en dispositivos reales, bumpear
  `LTMS_VERSION` para forzar cache-busting antes de asumir que el fix no funcionó.

## Trazabilidad
- Marca cada fix con una referencia identificable (ID de tarea) en el código y en el
  commit, usando Conventional Commits (`feat`, `fix`, `refactor`, `test`, `chore`,
  `docs`) con scope del módulo afectado — ver ejemplos en `CLAUDE.md`.
- No dejes código muerto "por si acaso". Si algo queda obsoleto, elimínalo o deja un
  comentario explícito de por qué no debe usarse — nunca lo sigas parcheando como si
  estuviera en uso.
- Si tocas documentación, verifica que coincide con el estado real del código; no la
  des por correcta solo porque ya existía.

## Seguridad por defecto
- Nunca incluyas credenciales, tokens ni secretos en código, commits, ni en el propio
  prompt/conversación. Leer siempre desde `LTMS_Core_Config::get()` o constantes de
  `wp-config.php`.
- Variables de entorno o gestor de secretos — nunca claves hardcodeadas.
- Valida todos los inputs (`sanitize_text_field()`, `absint()`, `wp_unslash()`,
  `sanitize_email()`), usa siempre `$wpdb->prepare()` en queries SQL, y todo handler
  AJAX debe comenzar con `check_ajax_referer('ltms_nonce', 'nonce')`.
- Revisa con cuidado extra la lógica de autenticación/roles/permisos que generes:
  puede verse correcta sin proteger nada realmente.
- Respuestas AJAX solo con `wp_send_json_success()` / `wp_send_json_error()`; nunca
  `echo` directo ni `die()`. Toda plantilla PHP debe abrir con
  `if ( ! defined( 'ABSPATH' ) ) exit;`.
- Si un token o contraseña queda expuesto en texto plano en un archivo del proyecto,
  una sesión de chat o un commit, **rotarlo de inmediato** — borrarlo del archivo no
  invalida el secreto ya expuesto.

## Decisiones de producto
- Si un fix requiere una decisión de negocio (comportamiento por defecto ante un caso
  ambiguo, prioridad entre soluciones válidas, elección de proveedor/canal), pregunta
  antes de asumir.
- Declara tu interpretación del alcance antes de ejecutar cambios grandes o ambiguos.

## Revisión como último filtro
- Trata cada cambio como un pull request que debe revisarse y aprobarse — nunca se
  fusiona a ciegas aunque los tests pasen.
- Para cambios críticos (pagos, autenticación, permisos, lógica de negocio con
  consecuencias reales — ej. wallet, comisiones, payouts, ZapSign/Backblaze), usa una
  segunda pasada o un segundo modelo para revisar el diff antes de darlo por bueno.
- Control de versiones real, sin excepciones: revisa diffs vía GitHub API o `git diff`
  antes de cada `git push origin main`.

---

## Loop de auditoría autónoma (Audit → Fix → Re-audit)

> Este proyecto ya opera así en la práctica: los ciclos `REG-AUDIT-001`,
> `DEEP-AUDIT-002`, `UIUX-AUDIT-001` y el ciclo "Plaza Viva" (v2.9.178→v2.9.187,
> 129 bugs, 178 tests nuevos, CI 100% verde) son ejemplos reales de este patrón
> documentados en `QA_REPORT.md` y `CHANGELOG.md`. Esta sección lo formaliza como
> procedimiento estándar para tareas de auditoría/hardening de alcance amplio
> ("audita todo el proyecto", "cierra todos los gaps de X módulo").

### Cuándo usar este loop
- Auditorías full-stack de un módulo o del proyecto completo.
- Barridos de hardening (seguridad, performance, regresiones) sin un bug puntual
  ya identificado.
- Cierre de un ciclo de release antes de un deploy mayor.

No lo uses para un fix puntual y acotado — para eso basta el flujo
Explorar → Planificar → Ejecutar → Revisar de arriba, sin el envoltorio de auditoría.

### Estructura del loop

```
1. INVENTARIO   → mapear el módulo/proyecto (árbol de archivos, clases, hooks).
2. AUDITORÍA    → identificar hallazgos concretos, uno por uno, con evidencia
                   (archivo:línea, comportamiento observado vs. esperado).
3. PRIORIZACIÓN → clasificar cada hallazgo P0 (rompe producción/dinero/datos),
                   P1 (bug funcional), P2 (cosmético/mejora).
4. FIX          → resolver 1:1, cada hallazgo con su propio fix + test, según
                   "Verificación — no negociable" de arriba.
5. RE-AUDITORÍA → repetir el escaneo del módulo tocado para detectar
                   regresiones o hallazgos nuevos introducidos por los fixes.
6. STOP CHECK   → evaluar condición de parada (ver abajo) antes de iterar de nuevo.
```

### Autonomía dentro del loop — y sus límites
El agente puede y debe operar de forma autónoma **dentro de una iteración técnica**
(auditar código, escribir fixes, correr tests, hacer commits atómicos) sin pedir
permiso en cada paso, siempre que:
- cada hallazgo tenga una solución técnica objetivamente correcta (no ambigua), y
- no toque los módulos marcados como críticos en "Revisión como último filtro"
  (wallet, comisiones, payouts, KYC/SAGRILAFT, ZapSign, Backblaze, gateways de pago)
  sin pasar igualmente por la segunda pasada de revisión ya exigida arriba.

El agente **debe pausar y preguntar** (no asumir) cuando un hallazgo requiere una
decisión de negocio — mismo criterio que "Decisiones de producto" arriba. Ejemplos:
cambiar una tasa de comisión, elegir entre dos proveedores de pago válidos, decidir
qué pasa con datos de un vendor bloqueado. La instrucción "sé autónomo, no
preguntes" aplica al *cómo* técnico, nunca a decisiones de negocio o a saltarse la
segunda revisión en módulos financieros — esas reglas del documento son
no-negociables y no se relajan por el modo loop.

### Condiciones de parada (obligatorias, no "hasta el 100% de productividad")
"100% de productividad" no es una condición verificable — sin criterio de parada
explícito el loop no converge, quema presupuesto de cómputo/tokens y arriesga
tocar código ya estable sin necesidad ("Alcance" arriba). Usa en su lugar:
- **Cobertura del inventario**: todos los archivos/módulos del alcance declarado
  fueron revisados al menos una vez.
- **Hallazgos en cero**: una re-auditoría completa del alcance no produce
  hallazgos P0/P1 nuevos (P2 puede quedar en backlog documentado).
- **Suite verde**: PHPUnit completo pasa (≥ tests existentes, sin regresiones).
- **Límite de iteraciones**: si tras 3 ciclos de re-auditoría siguen apareciendo
  hallazgos nuevos del mismo tipo, detente y reporta — probablemente hay una causa
  raíz arquitectónica que necesita decisión humana, no otro parche.
- Al llegar a cualquiera de estas condiciones, entrega un resumen: hallazgos
  encontrados, fixes aplicados, tests agregados, y lo que quedó pendiente (con
  motivo). Ver `prompt-engineering-loops.md` para el detalle de cómo estructurar
  este resumen y el prompt del propio loop.

### Registro de cada ciclo
- Cada hallazgo resuelto se documenta como una lección nueva en
  `LECCIONES_APRENDIDAS.md` si revela un patrón de error reincidente (ver sección
  11, "Reglas Preventivas para la IA" — ya hay 15 reglas ahí, más las de las
  secciones 12/13 por ciclo).
- Cada ciclo completo se resume en `CHANGELOG.md` con el mismo formato usado en
  el ciclo Plaza Viva (bugs por prioridad, tests nuevos, módulos tocados).

---
Regla madre: generar código ya no es el cuello de botella — verificar que hace lo que
dice hacer sí lo es. Cada regla de este documento mueve esa verificación al momento en
que el cambio se propone, no a cuando alguien lo descubre roto en producción.
