## Context

Hoy, el único endpoint que crea un `MediaEditJob` es `MediaClipController::clip()` (rutas `POST /media/clip/{id}`). Tanto Mis Archivos como Mis Avisos terminan llamando a ese mismo endpoint:

- **Mis Archivos**: clic directo en el botón "Editor" del archivo.
- **Mis Avisos**: clic en "✂️ Recortar" → deep-link `/files?clip_file=...&clip_start=...&clip_end=...` → `files/index.blade.php:1640 applyDeepClipLink()` → mismo modal `openClipEditor()` → mismo POST.

El límite mensual (`media_editor_clip_limit`) se enforce **una sola vez** en `MediaClipController.php:126` con `if (!$isPreview && $user->hasReachedClipLimit())`. La función `User::hasReachedClipLimit()` (`User.php:124-130`) cuenta `MediaEditJob` con `status='done'` del mes actual.

Hoy **no existe un harness automatizado** que verifique este contrato. La única forma de probarlo es manualmente: login → Mis Avisos → abrir mención → confirmar recorte → ir a `/admin/media-editor` → contar. Eso es lento, no repetible, y no detecta regresiones si alguien toca `MediaClipController` o la capability `can_clip`.

## Goals / Non-Goals

**Goals:**
- Crear `tests/harness_mis_avisos_clip_limit.php` ejecutable con `php tests/harness_mis_avisos_clip_limit.php`.
- Aserciones concretas sobre el flujo Mis Avisos → `MediaClipController::clip()` con un usuario real y un storage local real.
- Limpieza automática al final del harness (todo lo creado va con prefijo `hmcl_<tag>` y se borra en el bloque `finally`).
- Documentar el harness en `AGENTS.md` con un mini-runbook.

**Non-Goals:**
- No modificar el comportamiento del límite mensual.
- No tocar `MediaClipController`, `User`, `MediaEditJob`, ni vistas.
- No agregar UI nueva (ej: contador visible "te quedan X recortes").
- No convertir el harness en test PHPUnit formal — sigue siendo script ejecutable contra BD real, mismo patrón que `harness_mis_avisos_viewer.php`.

## Decisions

### Decisión 1: PHP harness, no PHPUnit

**Por qué**: el proyecto ya tiene 7+ harnesses ejecutables en `tests/harness_*.php` (ver `tests/harness_mis_avisos_viewer.php`, `tests/harness_storage_sync_is_file_linked.php`). Es el patrón establecido para validaciones de integración rápidas. Convertirlo a PHPUnit requeriría un harness harness + un TestCase + un test runner — disproportionate para validar un contrato operacional.

**Alternativas consideradas:**
- PHPUnit feature test: más ceremonioso, requiere mocks o DB transaccional. Lo descartamos por overkill.
- Pest: igual que PHPUnit, misma evaluación.

### Decisión 2: Reusar el controller real con reflexión, no duplicar la lógica

**Por qué**: la aserción clave es "¿el endpoint respeta el límite?". Si duplicamos la lógica en el harness, estamos testeando nuestra copia, no el sistema. Mejor instancia `MediaClipController` y llama directamente a `clip($request, $id)` vía HTTP simulation (o vía reflexión sobre `hasReachedClipLimit`/`mediaEditorClipsThisMonth` para los chequeos rápidos).

**Approach híbrido** (lo que terminó siendo más limpio en el código):
- **Para el conteo**: usar `User::mediaEditorClipsThisMonth()` directamente — la función es pública y testeable.
- **Para el 403**: invocar `MediaClipController::clip($request, $id)` con un `Request` sintético. Esto SÍ exercise la guarda real del controller.
- **Para crear el `MediaEditJob`**: insertar directamente la fila con `MediaEditJob::create([...status=>'done'...])` — refleja lo que hace `processLegacySegments` (línea 198) sin necesidad de ejecutar ffmpeg.

### Decisión 3: Storage local temporal + archivo real (no mock)

**Por qué**: el chequeo de storage en `MediaClipController.php:122` (`canUseMediaEditor`) más la capability `can_clip` en `MentionsSearchService::canClip()` requieren un storage de tipo `local` real para que la cadena completa pase. Pero el clip real no necesita un mp4 válido — `MediaEditJob` solo guarda metadata + `segments_json`; el archivo físico solo se lee en `serveTemp()` / generación de thumbs (que NO exercise el harness).

**Approach**: crear un archivo de texto con nombre `*.mp4` en `/tmp/<tag>/`. Suficiente para que `isClippable()` retorne `true` por extensión. Si en el futuro alguien agrega validación de magic bytes, se ajustará el harness (es esperado).

### Decisión 4: Sin ejecutar ffmpeg en el harness

**Por qué**: ffmpeg tarda segundos, requiere binarios instalados, y el harness debe ser rápido y portable. El `MediaEditJob` con `status='done'` se puede insertar directamente en BD — refleja el estado final sin ejecutar la pipeline.

**Trade-off**: el harness valida la GUARDA (¿se cuenta? ¿se bloquea?) pero NO valida que el archivo de salida se genere correctamente. Esa parte ya está cubierta por los tests de integración existentes del transcriptor.

### Decisión 5: Tres escenarios + un caso admin

| # | Escenario | Esperado |
|---|---|---|
| 1 | Usuario con `clip_limit=2`, hace 1 clip → confirma Mis Avisos | `MediaEditJob` creado, `count = 1`, próximo clip OK |
| 2 | Mismo usuario hace el segundo clip | `count = 2`, próximo clip → 403 |
| 3 | Tercer intento | 403 "Límite mensual alcanzado (2 cortes/mes)" |
| 4 | Preview (`preview=true`) | 200 OK, `count` no incrementa |
| 5 | Admin con `clip_limit=1`, hace 3 clips | Sin 403 (admin bypass), `count` igual sube (¿o no? ver Open Question) |
| 6 | Clip con `status='failed'` | `count` no incrementa |

### Decisión 6: Reporting de avance al operador en cada paso

**Por qué**: el operador (constraint `reportar_resultados_antes_de_comandos` en project memory + AGENTS.md) exige **confirmación previa** antes de continuar. Avanzar en silencio rompe la confianza del flujo.

**Approach**: cada tarea del `tasks.md` que ejecute un comando o modifique estado debe acompañarse de un print en chat con este shape:

```
[Tarea X.Y] <descripción corta>
  → antes:  <resumen del estado previo>
  → comando: <comando exacto ejecutado>
  → después: <resumen del resultado + exit code>
```

Y entre grupos de tareas, esperar confirmación del operador antes de avanzar al siguiente grupo.

**Alternativas consideradas:**
- Solo print final al terminar todo: rechazado — viola el constraint de reporte previo.
- Logs a archivo: complementario, pero el print en chat es lo que el operador ve en vivo.

## Risks / Trade-offs

| # | Escenario | Esperado |
|---|---|---|
| 1 | Usuario con `clip_limit=2`, hace 1 clip → confirma Mis Avisos | `MediaEditJob` creado, `count = 1`, próximo clip OK |
| 2 | Mismo usuario hace el segundo clip | `count = 2`, próximo clip → 403 |
| 3 | Tercer intento | 403 "Límite mensual alcanzado (2 cortes/mes)" |
| 4 | Preview (`preview=true`) | 200 OK, `count` no incrementa |
| 5 | Admin con `clip_limit=1`, hace 3 clips | Sin 403 (admin bypass), `count` igual sube (¿o no? ver Open Question) |
| 6 | Clip con `status='failed'` | `count` no incrementa |

## Risks / Trade-offs

- **[Harness depende de `now()` del servidor]** → Si la prueba cruza la frontera del mes (23:59 último día → 00:00 primer día), el conteo puede ser off-by-one. Mitigación: el harness usa `whereMonth`/`whereYear` de Eloquent que ya maneja el cambio de mes; documentar el caveat en el header del archivo.
- **[Insertar `MediaEditJob` directo bypassa validaciones de `MediaClipController` (segments_json schema, etc.)]** → Mitigación: insertamos con la misma estructura que `processLegacySegments` (línea 198) — `source_file_id`, `source_file_name`, `segments_json` (array), `output_filename`, `status`, `error_message`. Si en el futuro se agrega validación al modelo, se ajustará.
- **[El 403 podría cambiar de mensaje y romper la aserción literal]** → Mitigación: assertar el código HTTP 403 + aserción laxa (`str_contains($body, 'Límite')`) en vez de igualdad exacta.
- **[Correr el harness en CI podría saturar la BD]** → Mitigación: el harness borra todo en `finally` por tag único `hmcl_<random>`. Si crashea a media ejecución, queda basura — agregar cleanup al inicio también como red de seguridad.
- **[No exercise el flujo JS (Alpine.js `applyDeepClipLink`)]** → Aceptado: la lógica JS solo construye el deep-link; el conteo se decide 100% en el backend. Si en el futuro el deep-link cambia para auto-crear el clip sin pasar por el controller, este harness lo detectará como fallo.

## Migration Plan

No aplica. Es tooling puro:
- Sin migración de BD.
- Sin cambio de rutas.
- Sin cambio de comportamiento.

Deploy:
1. Hacer merge del change.
2. Verificar que `php tests/harness_mis_avisos_clip_limit.php` corre limpio localmente.
3. No requiere reload de PHP-FPM (PHP carga `tests/` solo en CLI).

Rollback:
- `git revert <commit>` — sin estado intermedio.

## Open Questions

- **Q1**: ¿Admin con `clip_limit=1` que hace 3 clips: el `MediaEditJob` se crea de todas formas (count sube) pero `hasReachedClipLimit()` retorna `false` (admin bypass)?
  - **A resolver en tasks.md**, no acá. Si la respuesta es "sí se crea igual", la UI del admin debería mostrar el conteo real aunque no lo respete. Documentar el hallazgo como side-effect en el harness.
- **Q2**: ¿El conteo debe filtrar por cliente (`owner_id`) o por ejecutor (`user_id`)? Hoy `User::mediaEditorClipsThisMonth()` filtra por `user_id` (quien hizo clic). Si un admin corta un archivo de un cliente, ¿se cuenta en el cupo del admin (que no tiene) o en el del cliente (dueño)?
  - **Decisión**: se mantiene como está (por `user_id`), porque es lo más simple y consistente con el modelo "el cupo es del usuario que opera". Si en el futuro se quiere separar "cupo del editor" vs "cupo del dueño del archivo", es un change separado.
