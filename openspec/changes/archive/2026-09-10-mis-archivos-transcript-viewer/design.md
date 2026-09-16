## Context

Ver `proposal.md` para motivación. El estado actual relevante:

- `MisAvisosController::transcription` (líneas 617-643) y `transcriptionSegments` (líneas 649-677) ya implementan el visor con la intersección `transcription_access ∩ transcription_enabled` y el shape final.
- `MisAvisosController::index` (línea 64) renderiza `mis-avisos.index` que `@include('mis-avisos._transcript-modal')` (línea 525). El blade vive en `app/resources/views/mis-avisos/_transcript-modal.blade.php` (129 líneas).
- El componente Alpine del módulo de Mis Avisos declara `transcriptModal`, `openTranscript(row, opts)`, `closeTranscript`, `visibleSegments`, `onSegmentClick`, `onPlayerTime`, `onPlayerLoaded`, `onSegmentsScroll`, `loadBefore`, `loadAfter`, `seekToSegment`, `seekToTime`, `highlightKeyword`, `mediaKind`, `hmsLabel`, `toggleKeywordFilter`, `isKeywordFilterActive`, `openFilesTab`, `openClipFromAnchor`. Todas estas funciones están definidas en `mis-avisos/index.blade.php` líneas 710-1728.
- `FileController::index` (línea 39) sirve el listado cacheado; cache key `"folder_listing:{$storageId}:{$pid}:{$gen}:{$page}"` con TTL root=60s / today=300s / past=86400s.
- `FileController::storages` (línea 963) NO devuelve `transcription_access`. La API para el selector de storages debe extenderse.
- `transcriptions.file_id` es UNIQUE (migration `2026_07_06_170001`), lo que hace trivial el lookup transcription↔file sin riesgo de duplicados.
- `File::model` no declara `transcription()` (model/relationships aditiva).

## Goals / Non-Goals

**Goals:**
- Reusar 100% del visor ya pulido (mismo UX, mismos atajos, mismas capabilities) desde Mis Archivos y desde Mis Avisos.
- Mantener el `mentions-viewer` spec existente como contrato de comportamiento del visor; el cambio es de *invocabilidad*, no de semántica.
- Mantener el cache del listing de archivos intacto y servir el nuevo campo sin invalidación adicional.
- Cero migraciones, cero dependencias nuevas.

**Non-Goals:**
- No se introduce un visor paralelo; no se duplica JS ni Blade.
- No se cambian las reglas de acceso (`transcription_access` ∩ `transcription_enabled`) ni `can_view_file` / `can_clip`.
- No se modifica el editor de corte, el endpoint de corte ni la config `avisos.transcript.*`.
- No se agrega búsqueda full-text server-side; la búsqueda cliente sobre la ventana cargada se preserva.
- No se introduce un nuevo middleware; el gating se hace en el controller reusando `MentionsSearchService::visibleTranscription`.

## Decisions

### D1. Reuso vía `Alpine.store('transcriptViewer')` en vez de duplicar el componente

`Alpine.store` es la API nativa de Alpine.js para estado compartido entre múltiples componentes en la misma página. Como `layouts.app` se incluye tanto desde `files/index.blade.php` como desde `mis-avisos/index.blade.php`, registrar el store una sola vez en el layout garantiza una instancia singleton accesible desde ambos `Alpine.data()`.

**Por qué no duplicar el componente:** la duplicación derivaría inevitablemente (un bug se arregla en uno, el otro queda roto). El componente actual del visor ya tiene ~150 líneas de lógica fina (paginación incremental, resaltado de keyword, sync con `<video>`, manejo de teclado, etc.) que NO queremos reescribir.

**Por qué no convertir el visor en un componente Alpine global (`x-data="transcriptViewer"` registrado vía `Alpine.data()`):** un componente Alpine propio tendría su propio scope; usarlo dentro del componente `fileManager` (que ya tiene su propio `x-data`) requeriría pasar estado entre dos componentes hermanos, lo que complica `visibleSegments()` y `transcriptModal.open` (refs cruzadas). El store evita eso: ambos componentes leen/escriben el mismo objeto.

**Alternativa considerada:** extraer solo el Blade (`@include` desde ambos módulos) y mantener el JS duplicado. Descartada por divergencia futura.

### D2. El `transcriptModal` y todas las funciones pasan al store; los blades se vuelven "tontos"

El store expone:
- Estado: `open`, `loading`, `error`, `meta`, `segments`, `firstIndex`, `lastIndex`, `totalSegments`, `loadingBefore`, `loadingAfter`, `search`, `activeIndex`, `anchorSegmentId`, `hitKeyword` (opcional, null cuando el invocador no es una mención), `pendingSeek`.
- Acciones: `open(file | row)`, `openRow(row, opts)` (compat Mis Avisos), `close()`, `loadBefore()`, `loadAfter()`, `toggleKeywordFilter()`, `isKeywordFilterActive()`, `openFilesTab()`, `openClipFromAnchor()`.
- Selectores: `visibleSegments()`, `mediaKind()`, `highlightKeyword(seg)`, `hmsLabel(seconds)`, `plain0(seg)`.
- Eventos de DOM: `onSegmentClick($event, seg)`, `onPlayerTime(e)`, `onPlayerLoaded(e)`, `onSegmentsScroll(e)`, `seekToSegment(seg)`, `seekToTime(time)`.

El partial `components/transcript-viewer.blade.php` consume el store directamente (`x-show="Alpine.store('transcriptViewer').open"`, `x-text="Alpine.store('transcriptViewer').meta?.file_name"`). Esto rompe las referencias profundas a `transcriptModal.*` en el blade original — son cambios mecánicos de prefijo.

`files/index.blade.php` mantiene su `fileManager` intacto salvo la línea del botón nuevo y la línea del `@include('components.transcript-viewer')`. `mis-avisos/index.blade.php` pierde el `@include('mis-avisos._transcript-modal')` y gana el `@include('components.transcript-viewer')`, y sus llamadas `openTranscript(row, opts)` se reescriben como `Alpine.store('transcriptViewer').openRow(row, opts)`.

### D3. Endpoint `GET /files/{id}/transcription` thin wrapper sobre `MentionsSearchService`

```php
// FileController.php
public function transcription(Request $request, File $file, MentionsSearchService $search)
{
    $user = $this->getUser();
    if (!$user) return response()->json(['error' => 'Unauthorized'], 401);

    // Single lookup por file_id (UNIQUE)
    $transcription = \DB::table('transcriptions')
        ->where('file_id', $file->id)
        ->where('state', 'done')
        ->value('id');

    if (!$transcription) return response()->json(['error' => 'No encontrada'], 404);

    $meta = $search->visibleTranscription($user, (int) $transcription);
    if ($meta === null) return response()->json(['error' => 'No encontrada'], 404);

    $window = $search->pageVisibleSegments($user, (int) $transcription, null);
    if ($window === null) return response()->json(['error' => 'No encontrada'], 404);

    return response()->json([
        'transcription' => $meta,
        'segments'      => $window['segments'],
        'first_index'   => $window['first_index'],
        'last_index'    => $window['last_index'],
        'total_segments'=> $window['total_segments'],
    ]);
}
```

**Por qué un wrapper thin en vez de reusar la ruta de Mis Avisos con `transcription_id`:** la ruta `/mis-avisos/transcriptions/{id}` está bajo el prefijo `/mis-avisos` que tiene middleware `misavisos` (asegura que el módulo esté habilitado para el usuario). Crear una ruta alternativa `/files/{id}/transcription` que resuelva por `file_id` evita exponer el `transcription_id` al cliente y mantiene el contrato HTTP simétrico: el cliente Mis Archivos llama a una URL de su propio dominio.

**Por qué no agregar `transcription_id` al listado y luego reusar el endpoint existente:** ese sería 1 round-trip extra por apertura del visor para resolver el id (frontend pide listado → elige archivo → pide transcription_id → pide segments). Con el endpoint por `file_id` el cliente llama una sola vez.

**Alternativa considerada:** polimorfismo de ruta `/transcriptions/{id}` resolviendo tanto por `transcription_id` como por `file_id`. Descartada por complejidad de routing y por acoplar dominios.

### D4. `FileController::index` enriquece el listado con `transcription_id` vía `withExists` o `leftJoinSub`

Dos enfoques:

**A) `leftJoinSub` directo en el SELECT:** una sola query, sin N+1.
```php
$query->addSelect([
    'transcription_id' => DB::table('transcriptions')
        ->select('id')
        ->whereColumn('file_id', 'files.id')
        ->where('state', 'done')
        ->limit(1)
]);
```
Eloquent lo expone como atributo dinámico en el modelo. Cache del listing sigue funcionando porque el `cacheKey` no cambia y la respuesta serializada incluye el campo nuevo.

**B) Eager loading posterior:** ejecutar `$paginator->load('transcription')` y mergear `transcription_id` por id. Más queries.

**Decisión: A.** Una sola query, plano, sin sobrecosto. El cache serializa la respuesta tal cual (Laravel cachea el array ya formado), así que el primer request paga el LEFT JOIN y los siguientes sirven el JSON cacheado con el campo incluido.

**Por qué no preocupa el cache stale:** si una transcripción pasa a `done` después de cachearse, el botón aparece cuando el TTL expire (≤60s en root, ≤300s en carpetas de hoy). Es el mismo orden de magnitud que la consistencia eventual del propio storage sync. No es regresión vs el comportamiento actual.

### D5. `FileController::storages` expone `transcription_access` por storage

```php
// Cambio mínimo: añadir el campo al map() existente
return [
    'id' => ...,
    'permissions' => $us->permissions,
    'can_create_shares' => (bool) $us->can_create_shares,
    'transcription_access' => (bool) $us->transcription_access,  // NUEVO
    // ... resto igual
];
```

El botón nuevo del frontend hace `x-show="... && currentStorageTranscriptionAccess"`. La variable Alpine se hidrata desde `availableStorages` (que es la respuesta de este endpoint) buscando el storage activo por id.

**Por qué en frontend y no pedirlo al backend por archivo:** porque `transcription_access` es una propiedad del pivote `(user, storage)`, no del archivo. Todos los archivos del mismo storage comparten el mismo valor. Pedirlo por archivo sería redundante.

**Por qué no inferirlo del `transcription_id` en el listado:** el `transcription_id` no nulo no implica acceso del cliente — puede haber una transcripción done en un storage al que el cliente NO tiene `transcription_access`. La regla de gating exige mirar el pivote explícitamente.

### D6. Layout registra el store; los blades solo lo consumen

`resources/views/layouts/app.blade.php` registra `Alpine.store('transcriptViewer', { ... })` dentro del listener `alpine:init` que ya tiene. Como el layout es el mismo para ambos módulos, el store vive una vez. Si un día se necesita el visor desde un tercer módulo, basta con `@include('components.transcript-viewer')`.

### D7. Sin tests nuevos específicos del visor

El visor es una refactorización de ubicación, no de comportamiento. Los tests de `MentionsSearchServiceTest` (si existen) cubren el shape JSON y el 404 opaco. El harness `tests/harness_storage_sync_is_file_linked.php` no aplica.

Si el cambio de cache preocupa, se puede agregar un test unitario sobre `FileController::index` que verifique que el campo `transcription_id` aparece en la respuesta — opcional, decisión final en `tasks.md`.

## Risks / Trade-offs

- **R1: Refactor del visor Mis Avisos introduce regresiones sutiles** → el componente actual tiene 10 años de fixes finos (edge cases de scroll, race conditions con `pendingSeek`, manejo de cierre con video pausado). Mitigación: extracción mecánica 1:1 (renombrar `transcriptModal` → `Alpine.store('transcriptViewer')` en todo el scope, copiar funciones sin alterar lógica); comparación visual lado a lado antes/después vía los escenarios del spec `mentions-viewer`.
- **R2: Cache stale oculta transcripciones nuevas durante ≤60s** → mismo orden que storage sync; aceptable. Mitigación opcional: bumpear `folder_gen:{storageId}:{pid}` cuando una transcripción pasa a `done`. Fuera de scope salvo que el operador lo pida.
- **R3: `Alpine.store` añade un singleton global que puede ser leído por código futuro no intencionado** → Mitigación: encapsular los selectores puros (`hmsLabel`, `mediaKind`) en helpers de Alpine `magic` properties del store o funciones exportadas vía `window.tcloudTranscript` con namespace explícito. Decisión final en tasks.md.
- **R4: El endpoint nuevo `/files/{id}/transcription` hace 1 query extra (lookup transcription_id) cuando ya podríamos incluirlo en `visibleTranscription` con un nuevo método** → Mitigación: el lookup es un `SELECT id WHERE file_id=? AND state='done' LIMIT 1` que usa el índice UNIQUE; costo despreciable. Si se prefiere evitar la query, `MentionsSearchService` puede ganar un método `visibleTranscriptionByFileId($user, $fileId): ?array` que haga el join inline — opcional en tasks.md.
- **R5: El botón aumenta densidad de la columna Acciones en pantallas chicas** → Mitigación: el botón usa el mismo tamaño que los existentes (p-1.5 / p-2); en `<sm` se reduce la columna "Acciones" por la media-query existente del partial (clases `sm:p-1.5`, `sm:w-4`, `sm:h-4`). No se agregan más columnas. Si el operador quiere un menú overflow en móvil, queda como follow-up.

## Migration Plan

Sin migración de BD. Sin nuevos workers. Sin cambios en cron. Sin cambios en supervisor.

**Deploy:**
1. Merge del PR.
2. `php artisan config:cache` (no aplica — no hay config nueva).
3. `php artisan view:clear` (recomendado por la extracción de blade).
4. Rollout normal; el primer request que toque el listado de archivos paga el LEFT JOIN, los siguientes sirven del cache.

**Rollback (si el visor Mis Avisos rompe):**
1. `git revert <commit>`.
2. `php artisan view:clear`.
3. El botón nuevo en Mis Archivos desaparece (estaba en el mismo commit) y la versión vieja del `_transcript-modal` queda intacta (el commit removió el `@include` desde el path viejo). Verificar que `resources/views/mis-avisos/_transcript-modal.blade.php` no quedó huérfano (debe quedar como dead code hasta el siguiente deploy).

**Freno de emergencia (sin deploy):**
- `feature_flag.mis_archivos_transcript_viewer = false` en `system_settings` (o `.env`): el `fileManager` chequea esta flag en `init()` y oculta el botón vía `x-show` reactivo. El visor Mis Avisos NO se ve afectado (es la versión vieja del modal, no el store nuevo). Si se quiere frenar también el visor Mis Avisos, el flag se chequea dentro del store para no abrir el modal.
- Decisión: incluir el feature flag desde el inicio o no, queda abierto en `Open Questions`.

## Open Questions

- **OQ1: ¿Feature flag de rollback?** Recomiendo incluir `feature_flag.mis_archivos_transcript_viewer` (default off → on tras validar en staging) por seguridad. Si el operador prefiere deploy directo sin flag, se omite. Decisión en tasks.md.
- **OQ2: ¿Test unitario sobre `FileController::index` que verifique `transcription_id` en la respuesta?** Recomiendo sí (5 min de costo), pero es opcional. Decisión en tasks.md.
- **OQ3: ¿Renombrar `mentions-viewer` a algo más genérico (`transcript-viewer`)?** No recomendado — rompería URLs externas y referencias históricas. El visor sigue siendo de menciones conceptualmente; el cambio es que ahora también se invoca desde Mis Archivos. El nombre queda.
