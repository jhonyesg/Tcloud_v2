## Context

`/ia/avisos-inteligentes` hoy mezcla dos cosas: (a) gestión de alertas por keyword (cupos, emails, keywords, matches) y (b) un espejo read-only del flag global `storage_providers.transcription_enabled` que se muestra como badge por storage en la ficha del cliente. Ese espejo es engañoso: parece un control de acceso pero solo refleja el estado del pipeline de api-transcriptor.

Históricamente existió un intento de mover el control de transcripción a `user_storages.transcription_enabled` (migración `2026_08_18_210000`). Esa siembra fallida apagó 175 storages y produjo una caída de 44 horas documentada en `ApiTranscriptorController:587-600` y en `2026_08_20_120000_reseed_user_storages_transcription`. La columna se quitó el 20-08 y `storage_providers.transcription_enabled` volvió a ser autoritativa.

La bandera per-(user, storage) que el admin necesita **no es** la misma que se quitó. La diferencia es semántica y de blast radius:

| | Diseño roto (2026-08-18) | Diseño nuevo |
|---|---|---|
| Nombre de la columna | `user_storages.transcription_enabled` | `user_storages.transcription_access` |
| Semántica | "este cliente contrata la transcripción del storage" | "este cliente puede ver los resultados de la transcripción del storage" |
| Efecto en `storage_providers.transcription_enabled` | La encendía/apagaba (derivada) | Ninguno |
| Blast radius si se siembra mal | Apaga el scanner global | Solo afecta la visibilidad de resultados para ese cliente |

## Goals / Non-Goals

**Goals:**
- Una columna nueva en `user_storages` con blast radius local (solo visibilidad de resultados).
- Un toggle admin por (cliente, storage) en la ficha del cliente, sin afectar api-transcriptor.
- Que `KeywordMatcher` y `MisAvisosController` respeten esa columna prospectivamente.
- Documentar el contrato de datos que el futuro módulo cliente consumirá.

**Non-goals:**
- Acciones masivas de concesión/revocación.
- Siembra inicial.
- Módulo cliente "Mis Transcripciones".
- Rate limiting del cliente.
- Cualquier cambio a `storage_providers.transcription_enabled`, `ApiTranscriptorController`, `DiskScannerService`, `TranscriptionPollingService`, `TranscriptionProcessor`, `CorreccionesController`.

## Decisions

### D1 — Columna: `user_storages.transcription_access` (boolean, default `false`)

**Razón**: el nombre debe distinguirse del flag global quemado. "access" describe un permiso de lectura (ver resultados), no de producción. Default `false` evita fugas de privacidad por siembra masiva accidental.

**Alternativas consideradas**:
- `user_storages.can_view_transcripts` — válido, pero más largo y menos paralelo al resto del esquema.
- Tabla nueva `user_storage_transcription_access` — desacopla más, pero rompe el patrón del proyecto (siempre se ha modelado el per-(user, storage) en el pivote `user_storages`).
- Reutilizar el nombre viejo `transcription_enabled` — descartado por la lección del 18-08.

### D2 — Endpoint: `POST /ia/avisos-inteligentes/{userId}/storages/{storageId}/transcription-access`

Body: `{ "access": true|false }`. No es toggle binario: recibe el estado deseado (idempotente).

**Razón**: idempotente y consistente con el patrón de los otros endpoints del módulo (`POST /ia/avisos-inteligentes/{userId}/emails`, etc.).

**Alternativas consideradas**:
- `PUT /.../transcription-access` — overkill para un único campo.
- Toggle implícito (lee estado actual y voltea) — no idempotente, fuente de race conditions.

### D3 — Filtrado en `KeywordMatcher::run()`

La consulta actual `User::whereHas('alertsInteligente', fn ($q) => $q->where('enabled', true))` se reemplaza por una subconsulta con join sobre `user_storages`:

```php
$users = User::whereHas('alertsInteligente', fn ($q) => $q->where('enabled', true))
    ->whereHas('userStorages', function ($q) use ($storageId) {
        $q->where('storage_provider_id', $storageId)
          ->where('transcription_access', true);
    })
    ->with(['userKeywords:id,normalized', 'alertsInteligente'])
    ->get();
```

`$storageId` proviene de `$transcription->file->storage_provider_id`. Si el file no existe (caso raro), la transcripción no produce matches para nadie — fail-safe.

**Razón**: filtra a nivel de la colección de usuarios candidatos, antes del loop por segmentos. Una sola consulta adicional en el peor caso.

**Alternativas consideradas**:
- Filtrar dentro del loop por usuario — funcionaría, pero haría una consulta por usuario.
- Hacer un `EXISTS` en una sola query — más complejo, mismo resultado.

### D4 — Filtrado en `MisAvisosController::matches()`

La relación `keywordMatches()` se queda como está (cargará todos los matches históricos). El filtrado por storage se hace en el paginador con `whereHas`:

```php
$matches = $user->keywordMatches()
    ->whereHas('transcription.file', fn ($q) => $q->whereHas('storageProvider.userStorages', function ($sq) use ($user) {
        $sq->where('user_id', $user->id)->where('transcription_access', true);
    }))
    ->with(['transcription.file', 'keyword'])
    ->orderByDesc('matched_at')
    ->paginate(25);
```

**Razón**: respeta el spec (histórico no se borra; solo el listado se filtra prospectivamente). El `whereHas` encadenado resuelve con joins, no con N+1.

### D5 — UI de la ficha: toggle + banner global

`resources/views/ia/avisos-inteligentes/user-detail.blade.php` se modifica así:

- **Elimina**: el badge read-only "Transcribe / Sin transcripción" por storage.
- **Añade**: por storage, un toggle que llama `POST /ia/avisos-inteligentes/{userId}/storages/{storageId}/transcription-access`. Estado inicial = `s.transcription_access`. Refleja el cambio inmediatamente (optimistic update o re-fetch del response).
- **Añade** un banner global en el header: "Api-Transcriptor: {{ $globalTranscribing }} / {{ $globalStorages }} storages transcribiendo".
- **Añade** un hint contextual: si `storage_providers.enabled = false` o `transcription_enabled = false`, mostrar "Api-Transcriptor no está produciendo aquí".

Alpine.js `userDetail(...)` agrega:
- `accessStates: { [storageId]: boolean }` — estado actual por storage.
- `togglingAccess: Set<storageId>` — evita doble click mientras guarda.
- `setAccess(storageId, access)` — fetch + actualiza estado + alerta de error.

El contador global se pasa desde el controlador en `show()`:

```php
$globalStorages = StorageProvider::where('enabled', true)->count();
$globalTranscribing = StorageProvider::transcriptionEnabled()->count();
```

### D6 — Índice: nueva columna "Acceso"

`resources/views/ia/avisos-inteligentes/index.blade.php` cambia la columna "Canales" (hoy solo cuenta storages asignados) por "Acceso: X / Y" donde X = storages con `transcription_access=true` e Y = storages asignados. Esto requiere un nuevo `withCount` en el controlador `index()`.

```php
->withCount([
    'userKeywords as keywords_count',
    'storageProviders as storages_count' => fn ($q) => $q->where('storage_providers.enabled', true),
    'storageProviders as storages_with_access' => fn ($q) => $q->where('user_storages.transcription_access', true),
])
```

### D7 — Limpieza del `withPivot` obsoleto en `User::storageProviders()`

Hoy la relación declara `->withPivot('permissions', 'can_create_shares', 'transcription_enabled', 'assigned_at')`. `transcription_enabled` ya no existe en la tabla; queda como referencia fantasma. Se quita y se reemplaza por `transcription_access`.

### D8 — Tour guiado

`public/js/interactive-tour.js` agrega un paso al tour del módulo avisos-inteligentes. El atributo `data-tour="storage-access-toggle"` se coloca en el primer toggle del listado. Texto del paso: "Activa el acceso por storage para darle al cliente permiso de ver los resultados de las transcripciones de ese canal. No afecta si api-transcriptor transcribe o no."

## Risks / Trade-offs

- **Filtro de KeywordMatcher mal desplegado → clientes dejan de recibir alertas que ya recibían**. Mitigación: el filtro es por usuario-storage, no por storage global. Si el admin ya dio acceso a los storages correctos antes del deploy, el comportamiento es idéntico al actual. Si no, los clientes verán MENOS alertas (no más), lo cual es fail-safe por privacidad. Test manual con 1 usuario conocido que tenga storage con y sin acceso antes de promover.
- **`withPivot` actualizado a `transcription_access` puede romper otros lugares que lean `pivot->transcription_enabled`**. Mitigación: grep previo al deploy para detectar referencias huérfanas. Si aparecen, se actualizan.
- **El cliente que ya recibe matches históricos de un storage perderá visibilidad prospectiva pero conservará el histórico**. Mitigación: documentado en el spec; comportamiento esperado.
- **El banner global X/Y hace una query extra en cada carga de ficha**. Mitigación: query trivial (count sobre `storage_providers` con índice primario), aceptable.
- **El toggle optimista puede desincronizarse si el servidor rechaza**. Mitigación: rollback del estado local en el `.catch()` del fetch, alerta de error al admin.

## Migration Plan

1. Crear migración `add_transcription_access_to_user_storages` (default `false`). Sin backfill: todas las filas existentes arrancan en `false`.
2. Actualizar `UserStorage` (fillable + cast) y `User::storageProviders()` (withPivot).
3. Actualizar `AvisosInteligentesController::index()` (withCount) y `show()` (incluir `transcription_access`, contar globales).
4. Añadir método `toggleStorageAccess()` y ruta nueva en `routes/web.php`.
5. Reescribir `user-detail.blade.php` (toggle + banner global + hint contextual).
6. Actualizar `index.blade.php` (columna "Acceso: X / Y").
7. Añadir filtro a `KeywordMatcher::run()`.
8. Añadir filtro a `MisAvisosController::matches()` y `index()`.
9. Añadir paso de tour en `interactive-tour.js`.
10. Validar manualmente con 1 usuario conocido que tenga storage con y sin acceso.

**Rollback**:
- Revertir la migración (drop column). El comportamiento del KeywordMatcher y de MisAvisosController vuelve al estado anterior (sin filtro). No se borran `keyword_matches`.
- Revertir los cambios en controladores y vistas con `git revert`.
- No hay datos huérfanos porque la columna no se cruza con otras tablas.

## Open Questions

- ¿Cuántos toggles individuales por storage tolera visualmente la ficha del cliente antes de volverse inmanejable? Hoy hay clientes con hasta 175 storages asignados. La decisión actual es 1 toggle por fila con scroll vertical. Si pasa de ~30 storages por cliente, podría necesitarse búsqueda o grouping. Se documenta como follow-up si surge feedback real; no cambia el spec.
- ¿El contador global X/Y debe aparecer también en el índice de avisos, o solo en la ficha? Decisión actual: solo en la ficha, donde el admin está a punto de tomar la decisión. El índice muestra solo el ratio por cliente. Se puede mover si el feedback lo pide.
