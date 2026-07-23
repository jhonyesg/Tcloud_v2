## Context

El módulo **API Transcriptor** tiene tres puntos de entrada relacionados con archivos:

| Punto de entrada | Ruta | Fuente de datos |
|---|---|---|
| Tab "Jobs" (pendientes/completados) | `/ia/api-transcriptor` (Alpine tab `jobs`) | Tabla `transcriptions` |
| Modal "Ver archivos" del storage | `/ia/api-transcriptor` → modal `showFiles` con `filesMode` | Endpoint `GET /ia/api-transcriptor/storages/{id}/files` (lee `files` + cruza con `transcriptions`) |
| Detalle de job | `/ia/api-transcriptor/jobs/{id}` | Tabla `transcriptions` + relación `segments` |

Hoy el **modal "Ver archivos"** lista los `File` de un `StorageProvider` y marca con `has_transcription: true` los que ya tienen `Transcription`. El JSON del endpoint `storageFiles` solo expone ese boolean (`ApiTranscriptorController.php:320-339`). En la vista, el badge "Transcrito" o "Pendiente" (`index.blade.php:335-340, 378-383`) es plano — no hay `<a>` que conecte la fila del archivo con la vista detalle de la transcripción.

La vista detalle (`job-detail.blade.php`) ya existe y muestra: nombre del archivo, job_id, node_url, estado, duración, palabras, segmentos, SRT completo con botón "Descargar .srt". Es decir, ya tenemos el destino: solo falta cablear el origen.

**Restricción clave del proyecto**: el blade usa directivas Alpine.js (`x-show`, `x-data`, `@click`, etc.) que conviven con HTML plano dentro de loops `template x-for`. El link debe ser HTML estándar (no `x-on:click.prevent` ni `fetch`) para mantener consistencia con cómo el tab "Jobs" ya enlaza al detalle (línea ~641 de `index.blade.php`: `<a href="/ia/api-transcriptor/jobs/{id}">`).

## Goals / Non-Goals

**Goals:**
- Cada archivo listado en el modal "Ver archivos" con transcripción asociada debe ser un hipervínculo navegable a `/ia/api-transcriptor/jobs/{transcription_id}`.
- Aplicar a todos los estados de transcripción (`pending`, `queued`, `processing`, `done`, `error`, `dead`).
- Reutilizar la vista `job-detail.blade.php` y la ruta `GET /ia/api-transcriptor/jobs/{id}` sin modificarlas.
- Mantener el badge "Pendiente" para archivos sin `Transcription` asociada (no inventar un link inerte).

**Non-Goals:**
- No agregar reproductor embebido ni highlight sincronizado en el listado.
- No modificar el scanner, ni `job-detail`, ni la ruta `/jobs/{id}`.
- No cambiar el comportamiento de selección múltiple, "Procesar carpeta/día", ni el botón "Iniciar transcripción" del modal.
- No migrar el JSON de `storageFiles` (mantener `has_transcription: bool` además de los nuevos campos para compatibilidad).

## Decisions

### Decisión 1: Exponer `transcription_id` y `transcription_state` desde el backend (no solo inferir en frontend)

**Por qué**: hoy la vista infiere `has_transcription` revisando si `f.id` está en una lista pre-cargada. Para construir un link real, el frontend necesita el `id` de la `Transcription` (no solo el boolean). Calcularlo en el backend con una sola query `whereIn` es trivial y más limpio que exponer todos los `transcriptions` al cliente.

**Alternativa considerada**: devolver todas las `transcriptions` completas al frontend. Descartada por volumen y porque rompe el contrato existente.

### Decisión 2: Reemplazar el badge `Transcrito` por el link (no añadir link + dejar badge)

**Por qué**: el badge plano "Transcrito" sin acción es exactamente lo que el usuario reporta como callejón sin salida. Mantenerlo duplicaría información visual sin valor. Para los estados no-`done` no había badge — solo se sumará el link.

**Alternativa considerada**: añadir un ícono/link pequeño al lado del nombre, dejando el badge. Descartada por ruido visual y porque el nombre del archivo ya es la fila más prominente.

### Decisión 3: Link en TODOS los estados, no solo `done`

**Por qué**: el `job-detail` ya maneja todos los estados correctamente (incluye botón Reintentar para `error`/`dead`, muestra progreso para `pending`/`queued`/`processing`). Forzar al usuario a entrar al tab Jobs para ver un error o reintentar es fricción innecesaria cuando ya está viendo el archivo.

**Confirmado explícitamente con el usuario**.

### Decisión 4: Mantener `has_transcription: bool` en el JSON además de los nuevos campos

**Por qué**: el campo es usado por `transcribed_count` (`ApiTranscriptorController.php:378-379`) para el footer del modal. Eliminarlo obligaría a recalcular el conteo desde los nuevos campos — pequeño riesgo de regresión sin beneficio.

### Decisión 5: HTML estándar `<a>`, no SPA navigation ni `fetch`

**Por qué**: el tab "Jobs" ya usa `<a href="/ia/api-transcriptor/jobs/{id}">` (navegación full-page). El spec `spa-navigation` (si existe regla) parece aplicarse solo a rutas globales (sidebar, layout), no a destinos de detalle dentro de módulos. Mantener HTML estándar evita acoplar este cambio a la capa SPA.

**Verificación pendiente**: confirmar que `spa-navigation` no intercepta enlaces `/ia/...` — si lo hace, ajustar a `data-spa-link` o equivalente.

## Risks / Trade-offs

- **[Riesgo] Cambio en contrato JSON**: agregar `transcription_id`/`transcription_state` rompe consumidores externos si existen.  
  **Mitigación**: el único consumidor conocido es `index.blade.php` del mismo módulo. Sin embargo, conviene revisar si algún endpoint público o frontend distinto consume `storageFiles`. Búsqueda en código durante implementación.

- **[Riesgo] Selección múltiple del modal**: si la fila es un `<a>`, un clic accidental navega en vez de seleccionar.  
  **Mitigación**: la selección múltiple del modal actual usa checkboxes explícitos (`x-show="..."` con `selectMode` independiente, según línea ~316-340). El `<a>` envuelve el nombre, no toda la fila; los checkboxes siguen funcionando. Verificar visualmente después de implementar.

- **[Riesgo] Performance**: agregar `Transcription::whereIn(file_id, ...)->get(['id','state','file_id'])` para hasta 500 archivos por llamada.  
  **Mitigación**: una sola query indexada por `file_id` (ya hay índice por convención de Laravel para FKs). 500 filas es trivial. Misma escala que la implementación actual (`pluck('file_id')`).

- **[Riesgo] Archivos con múltiples `Transcription`** (ej. reprocesado): `whereIn(file_id)` puede devolver varias filas por `file_id`.  
  **Mitigación**: usar `keyBy('file_id')` para tomar la más reciente (por `id` desc) o la única. Verificar en el modelo si existe una regla "una Transcription activa por File" — si no, tomar la más reciente.

- **[Trade-off] Estados no-`done` muestran un link pero el SRT puede estar vacío** (job aún no terminó).  
  **Aceptado**: el `job-detail` ya maneja esto mostrando el bloque SRT solo si `$job->srt_content` está presente (`job-detail.blade.php:56-67`). El usuario entiende que "Ver TX" significa "ir a la página de esa TX", no necesariamente "ver SRT inmediatamente".

## Migration Plan

Sin migraciones de DB. Sin nuevos procesos. Sin cambios en schedules.

**Despliegue**:
1. Modificar `ApiTranscriptorController::storageFiles` para incluir `transcription_id` y `transcription_state` en el JSON.
2. Modificar `index.blade.php` (modal `showFiles`) en los dos puntos de render de filas.
3. Verificar manualmente en el navegador: storage con archivos transcritos + sin transcribir + en estado `error`, en modos `browse`, `today`, `yesterday`, `search`.

**Rollback**: revertir los dos archivos. Sin estado intermedio persistente.

## Open Questions — resueltos durante implementación

- **¿`spa-navigation` intercepta `<a>` a `/ia/...`?** El spec existe (`openspec/specs/spa-navigation/spec.md`) y describe navegación tipo Turbo. Sin embargo, búsqueda exhaustiva en `resources/views/**/*.blade.php` y JS no encontró ningún `data-turbo`, `data-spa-link` ni interceptor activo. Conclusión: los `<a href="/ia/api-transcriptor/jobs/{id}">` NO serán interceptados y harán navegación full-page estándar, consistente con el tab Jobs actual. No se requiere acción.

- **¿`Transcription` permite múltiples filas por `file_id`?** NO. La migración `2026_07_06_170001_create_transcriptions_table.php` línea 13 define `$table->foreignId('file_id')->unique()->constrained('files')->cascadeOnDelete();` — constraint `unique()` a nivel DB. Esto simplifica la query: `Transcription::whereIn('file_id', ...)->get(['id', 'file_id', 'state'])->keyBy('file_id')` es seguro y robusto, y `orderByDesc('id')->keyBy('file_id')` también es válido (devolverá una sola fila por `file_id` por la constraint). Se mantiene la implementación del design original sin cambios.