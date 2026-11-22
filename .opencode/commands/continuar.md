---
description: "Reanudar sesion de auditoria LTMS desde el checkpoint guardado en C:\\Users\\User\\LTMS\\lee-archivo.txt. Lee el snapshot y arranca el loop exactamente donde se quedo (previene perdida de contexto cuando GLM-5.2 se cayo)."
agent: build
---

CONTINUAR REQUEST: recupera el contexto del checkpoint y reanuda el loop de auditoria LTMS.

## Tu tarea ahora

1. Usa la tool `read` para leer el archivo completo:
   `C:\Users\User\LTMS\lee-archivo.txt`
   - offset: 0 (inicio)
   - sin limit (lee hasta el final, puede tener miles de lineas)
   - Haz multiples lecturas encadenadas con offsets incrementales (1117, 2100, 3053, ...)
     si el archivo excede el limite por lectura. NO summarizes sin haber leido TODO el archivo.

2. Una vez leido el checkpoint completo, parsea estas secciones en orden:
   - CONTEXTO ACTUAL
   - LOOP DE AUDITORIA EN CURSO (ciclo, modulo, fase)
   - TODO LIST ACTUAL (replica EXACTA en tu todolist interna con todowrite)
   - ULTIMO FIX APLICADO
   - ULTIMO TEST CORRIDO
   - HALLAZGOS PENDIENTES
   - PROXIMO PASO RECOMENDADO
   - ARCHIVOS TOCADOS EN ESTA SESION
   - NOTAS CRITICAS / GOTCHAS
   - INSTRUCCION PARA LA PROXIMA SESION

3. Inicializa tu todolist interna con `todowrite` replicando EXACTAMENTE el estado de
   TODO LIST ACTUAL del checkpoint:
   - [✓] → status "completed"
   - [•] → status "in_progress"
   - [ ] → status "pending"
   NO inventes nuevos items. NO marquees [✓] lo que el checkpoint tenga como [ ] o [•].

4. Antes de tocar codigo, verifica el estado real de lo que dice el checkpoint:
   - Lee el archivo del ULTIMO FIX APLICADO y comprueba que el tag (ej. CICLO15-P1-RM-036 FIX)
     realmente esta en el codigo. Si NO esta, el fix se perdio (posible reset de working tree,
     stash, o checkout) - reportalo al usuario ANTES de continuar y detente.
   - Si el checkpoint dice "Suite completa verde: 3,938 tests" pero(uid)tienes acceso a PHPUnit,
     considera correr un smoke test del modulo afectado para confirmar que el baseline sigue
     verde. Si no tienes acceso (no estas en SiteGround ni tienes DB local), sáltate este paso.

5. Reanuda el loop de auditoria EXACTAMENTE desde PROXIMO PASO RECOMENDADO del checkpoint.
   - NO reinicies en INVENTARIO a menos que el modulo auditado haya cambiado estructuralmente
     o el checkpoint explicitamente diga que hay que re-empezar.
   - Respeta la fase del loop indicada en "LOOP DE AUDITORIA EN CURSO":
     * Si era FIX → aplica el proximo fix de la cola HALLAZGOS PENDIENTES.
     * Si era RE-AUDITORIA → re-audita el modulo tocado para detectar regresiones.
     * Si era STOP CHECK → evalua condicion de parada (cobertura de inventario, hallazgos en
       cero, suite verde, limite de iteraciones) y decide si seguir o cerrar el ciclo.

6. Respeta las NOTAS CRITICAS / GOTCHAS del checkpoint como reglas duras durante esta sesion.
   Son lecciones ya pagadas en tiempo/buges - no las redescubras.

7. Si el archivo `C:\Users\User\LTMS\lee-archivo.txt` NO existe o esta vacio:
   - NO inventes contexto. Reporta al usuario: "No hay checkpoint previo en
     C:\\Users\\User\\LTMS\\lee-archivo.txt. Estoy arrancando sesion limpia. Si crees que
     habia un checkpoint, revisa la ruta o ejecuta /checkpoint en la sesion anterior antes
     de cerrarla."
   - Pregunta si quiere empezar un loop nuevo o si hay otro path con el checkpoint.

8. Si el checkpoint tiene mas de 24 horas (ver CONTEXTO ACTUAL fecha), adviertele al usuario
   antes de continuar: "El checkpoint es de hace {X}h. El codigo pudo haber cambiado desde
   entonces (otro dev, otro modelo, deploy en SG). Quieres que valide el baseline antes de
   reanudar, o sigo asumiendo el estado del checkpoint?"

## Antes de responder al usuario

Confirma brevemente (2-3 lineas):
- Ciclo y modulo que estas reanudando.
- Proximo paso que vas a ejecutar.
- Cualquier discrepancia detectada entre el checkpoint y el estado real del codigo.

 Luego ejecuta el proximo paso. No pidas permiso para continuar si el checkpoint es claro - eso
 es lo que automatiza el loop. Solo pausa y pregunta si hay ambiguedad de negocio (decision de
 producto) como dicta AGENTS.md "Decisiones de producto".
