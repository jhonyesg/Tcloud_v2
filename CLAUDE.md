# CLAUDE.md

<!-- ODD:BEGIN -->
## Organic Driven Development (ODD)

This project uses ODD. ODD corre en cada request; no hace falta pedir un workflow, un plan ni task tracking.

Protocolo: autorizar (solo lectura salvo que se autorice implementar) -> explorar (leer el código y los requisitos existentes primero) -> resolver incertidumbre (investigación o una sola pregunta de producto, solo si hay una decisión real abierta) -> clasificar (chico vs grande por el test de reanudación) -> registrar antes de la primera escritura (documento de feature) -> implementar tarea por tarea (commits por unidad de trabajo, tests y docs junto al comportamiento) -> cerrar (reportar el resultado verificado, los checks fallidos o pendientes y el próximo paso).

Clasificación: una tarea es chica solo si el resultado está entendido, el riesgo está contenido y se podría retomar desde el pedido original más `git diff` solamente. Es grande solo si ese test de reanudación falla. Los conteos de archivos, comandos, tests o líneas nunca deciden el tamaño.

El trabajo grande autorizado crea exactamente un documento de feature en `odd/tasks/<feature-name>.md` con las secciones `Specs` (S1..Sn), `Tasks` (T1..Tn), `Acceptance Criteria`, `Verification`, `Progress`, `Next Step` y `Log` (L1..). Mantenlo actualizado con ediciones puntuales; nunca reescribas el archivo entero para cambiar una línea de tarea.

Tareas: una línea por tarea, IDs estables, `S#` enlazado, ruta (inline o delegated), estado, commit y evidencia observada. Un checkbox exige prueba observada. Registra el commit que contiene cada tarea.

Ítems de alto riesgo: 1) datos o efectos irreversibles que no se pueden reconstruir, 2) seguridad, 3) cambiar o quitar contratos que otros ya consumen, 4) concurrencia, 5) entrega o entorno, 6) ningún test detectaría una regresión. Cerrá cada cambio de código con `Risk: item N (motivo)` o `Risk: none`. Un ítem de alto riesgo exige un verificador independiente antes de cerrar.

TDD: resolvé on/off desde la configuración del proyecto o una elección explícita del usuario. TDD habilitado exige un RED observado antes de implementar, luego GREEN, luego REFACTOR. TDD deshabilitado igual exige verificaciones funcionales. La presencia de tests no habilita TDD.

Entrega: las unidades de trabajo se commitean en porciones revisables de unas 400 líneas modificadas en la branch de la feature, con tests y docs junto al comportamiento. La estrategia de entrega de la feature decide qué pasa cuando el acumulado supera el presupuesto: `ask-on-risk` (por defecto), `auto-chain`, `single-pr` o `exception-ok`. Elegir una forma nunca autoriza push, creación de PR ni merge.

Recuperación: escribí primero el progreso local, después el espejo, y leé ambos de vuelta. Si el espejo no está disponible, marcalo explícitamente como pendiente; nunca afirmes un éxito que no ocurrió.

Herramientas: `odd status` muestra el progreso, `odd validate` revisa el documento de feature, `odd mirror` escribe la copia de recuperación, `odd lines` informa el conteo de líneas modificadas, `odd new` genera un documento de feature.
<!-- ODD:END -->
