---
description: "Volcar contexto actual a C:\\Users\\User\\LTMS\\lee-archivo.txt para no perderlo cuando GLM-5.2 se cae por limite de consumo (~17% sesion). Pensado para loop de auditoria LTMS."
agent: build
---

CHECKPOINT REQUEST: $ARGUMENTS

Estas en una sesion de auditoria del plugin LTMS (lt-marketplace-suite). El modelo GLM-5.2 se cae
aleatoriamente por limite de consumo de API (~17% de uso de sesion). Este comando genera un
checkpoint que permite a una sesion nueva continuar el trabajo EXACTAMENTE donde lo dejaste,
sin perder contexto del loop Audit → Fix → Re-audit en curso.

## Tu tarea ahora

1. NO leas ni modifies codigo en este paso. Solo redacta el checkpoint.

2. Revisa el historico completo de esta sesion (mensajes previos del usuario, tus respuestas,
   tool calls ejecutados, todolists, hallazgos, fixes aplicados, tests corridos, commits hechos,
   archivos tocados, ciclos completados). Basate en LO QUE YA PASO en esta conversacion, no en
   suposiciones.

3. Redacta el checkpoint en este formato EXACTO y escribelo con la tool `write` al path
   `C:\Users\User\LTMS\lee-archivo.txt` (sobreescribiendo el anterior - es un snapshot, no un
   append):

   ```
   # LTMS CHECKPOINT - {fecha y hora ISO 8601}

   ## CONTEXTO ACTUAL
   - Sesion iniciada: {approx, si lo recuerdas}
   - Modelo: z-ai/glm-5.2 (nvidia/z-ai/glm-5.2)
   - Comando que genero este checkpoint: /checkpoint $ARGUMENTS
   - Nota del usuario: $ARGUMENTS

   ## LOOP DE AUDITORIA EN CURSO
   - Ciclo actual: {numero, ej. "Ciclo 15"}
   - Modulo auditado: {archivo o area, ej. "class-ltms-business-redi-manager.php"}
   - Fase del loop: {INVENTARIO | AUDITORIA | PRIORIZACION | FIX | RE-AUDITORIA | STOP CHECK}
   - Motivo del stop anterior (si hubo uno): {texto o "n/a, primer checkpoint"}

   ## TODO LIST ACTUAL (estado real)
   - [✓] {item completado}
   - [•] {item en progreso}
   - [ ] {item pendiente}
   (Replica exactamente las marcas [✓] [•] [ ] que YA existen en tu todolist interna.
   No inventes nuevos items. No omitas los que ya estan.)

   ## ULTIMO FIX APLICADO
   - ID de hallazgo: {ej. "P1-RM-036"}
   - Archivo: {path}
   - Cambio: {resumen 2-3 lineas}
   - Tag en codigo: {ej. "CICLO15-P1-RM-036 FIX"}
   - Commit hash: {hash o "sin commit todavia"}

   ## ULTIMO TEST CORRIDO
   - Archivo de test: {path o "n/a"}
   - Comando: {comando exacto, ej. "LTMS_UNIT_ONLY=true php -d zend.assertions=1 ..."}
   - Resultado: {PASS | FAIL | "no corrido todavia"}
   - Suite completa conteo: {ej. "3,938 tests verdes"}

   ## HALLAZGOS PENDIENTES (cola de la auditoria)
   1. {ID} - {descripcion 1 linea} - prioridad {P0|P1|P2}
   2. {ID} - {descripcion 1 linea} - prioridad {P0|P1|P2}
   (Lista COMPLETA de hallazgos identificados pero NO fixeados todavia, en orden de prioridad.)

   ## PROXIMO PASO RECOMENDADO
   {una sola frase precisa, ej. "Aplicar fix P1-RM-037 (ROLLBACK en path de fallo de save) y
   agregar test source-based en tests/unit/AuditCiclo15RediManagerFixesTest.php"}

   ## ARCHIVOS TOCADOS EN ESTA SESION
   - {path 1}
   - {path 2}
   (Solo los que MODIFICASTE o CREASTE en esta sesion, no los que solo leiste.)

   ## NOTAS CRITICAS / GOTCHAS
   - {cualquier gotcha, decision de negocio tomada, blame de un bug raro, o detail no obvio
      que la proxima sesion DEBE saber antes de tocar nada}
   - {ej. "El bloque if (!new_product_id) en adopt_product tiene un comentario CICLO15-P1-RM-037
      FIX de 15 lineas antes del ROLLBACK - los tests source-based necesitan capturar 500 chars
      despues del if, no 250, o falls espurio en RM-037. Leccion #119 aplicada."}

   ## INSTRUCCION PARA LA PROXIMA SESION
   Lee este archivo completo con la tool `read` (offset 0, sin limit). Luego ejecuta
   `/continuar` para reanudar exactamente desde PROXIMO PASO RECOMENDADO. NO reinicies el
   loop desde INVENTARIO - respeta lo ya avanzado a menos que el modulo auditado haya
   cambiado estructuralmente.
   ```

4. Reglas criticas para el contenido del checkpoint:
   - Sea HONESTO sobre lo que se hizo vs lo que queda pendiente. Un checkpoint optimista
     (marcar [✓] algo que no se completo) rompe la siguiente sesion.
   - Si hay un fix aplicado pero su test aun falla, el TODO debe ser [•] no [✓].
   - Si no recuerdas algun campo, escribe "desconocido" - NO inventes.
   - Las posiciones de linea (file:line) son obligatorias para PROXIMO PASO y HALLAZGOS
     PENDIENTES - sin eso la proxima sesion pierde tiempo re-localizando.

5. Al terminar, NO corras tests, NO hagas commit, NO continues el loop. Solo confirma al
   usuario: "Checkpoint escrito en C:\\Users\\User\\LTMS\\lee-archivo.txt (N lineas). Cierra
   esta sesion y abre una nueva con /continuar para reanudar."

6. Si el usuario paso un argumento (ej. `/checkpoint antes de que se caiga`), incluso en
   "Nota del usuario" del bloque CONTEXTO ACTUAL, pero no cambia el formato.
