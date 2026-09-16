# Tareas: Indicador visual y filtro por tipo de medio (TV/Radio)

## 1. Backend — `media_kind` derivado

- [x] 1.1 En `app/app/Services/Ia/MentionsSearchService.php::hitRow()`, agregar el cálculo de `media_kind` a partir de `$row->mime_type`:
  - `'video/%'` → `tv`
  - `'audio/%'` → `radio`
  - resto → `other`
- [x] 1.2 Incluir `media_kind` en el array retornado por `hitRow()`.
- [x] 1.3 Validar en PHP CLI: `hitRow()` retorna `tv` para un mock con `mime_type='video/mp4'`, `radio` para `'audio/mpeg'`, `other` para `'application/pdf'`.

## 2. Backend — filtro `media_type` en `applyHitFilters`

- [x] 2.1 En `applyHitFilters()`, agregar un parámetro `?string $mediaType = null`. Si no es null y no es `'all'`, agregar `whereHas('transcription.file', fn ($q) => $q->where('mime_type', 'like', $mediaType === 'tv' ? 'video/%' : 'audio/%'))` al query.
- [x] 2.2 Validar que el filtro no rompe el comportamiento actual cuando se omite o es `'all'` (devuelve todos los resultados).

## 3. Controller — `MisAvisosController`

- [x] 3.1 En `MisAvisosController::history()`, agregar lectura de `request('media_type')` con whitelist (tv/radio/all). Cualquier valor fuera de la whitelist → 422.
- [x] 3.2 Pasar `$mediaType` como argumento a `$search->searchHistory()` o al `applyHitFilters()` interno (refactor el signature si es necesario).
- [x] 3.3 Repetir mismo cambio en `MisAvisosController::feed()` para el polling En vivo.

## 4. Frontend — botones de filtro en `index.blade.php`

- [x] 4.1 Inicializar `liveFilters.media_type = 'all'` y `historyFilters.media_type = 'all'` en el state Alpine (mismo lugar donde se inicializan `storage_ids`).
- [x] 4.2 Agregar markup del grupo de 3 botones (Todas / TV / Radio) en el `<div>` de filtros del tab En vivo (justo después del input de búsqueda, para quedar al inicio del filter row).
- [x] 4.3 Replicar el mismo markup en el tab Histórico (mismo lugar relativo).
- [x] 4.4 Cada botón: `@click` que setea `xxx.mediaType = 'all'|'tv'|'radio'` y dispara `applyLiveFilters()` / `searchHistory(1)`. Las clases `:class` reflejan el estado activo (igual que los date pills existentes).
- [x] 4.5 Modificar `applyLiveFilters()` / `searchHistory(page)` para incluir el param `media_type` en la URL construida (`params.set('media_type', filters.media_type)` cuando !== 'all').
- [x] 4.6 Bug fix colateral: el scope que se pasaba a `setMediaFilter` era `'live'` / `'history'` pero el state real es `liveFilters` / `historyFilters`. Ajustar los `@click` para usar los scope names correctos.
- [x] 4.7 Posición de los botones: en AMBOS tabs, justo después del input de búsqueda, para que queden en la parte izquierda superior del filter row (el Histórico wrappea a 2 líneas; el En vivo se queda en 1 línea; ambos arrancan con el mismo bloque [Buscar | TV/Radio]).
- [x] 4.8 Consistencia visual del input de búsqueda: ambos tabs usan label "Buscar (mín. 3 caracteres)" + placeholder "término libre en las transcripciones..." + `min-w-[220px]`.

## 5. Frontend — ícono al inicio del filename en `_table-hits.blade.php`

- [x] 5.1 Localizar el `<span class="text-slate-600 break-words">` que envuelve `g.filename` (en la fila resumen) y agregar un prefijo condicional:
  - `g.first_media_kind === 'tv'` → `<i class="fas fa-tv text-slate-400 text-xs">`
  - `g.first_media_kind === 'radio'` → `<i class="fas fa-radio text-slate-400 text-xs">`
  - resto → sin prefijo
- [x] 5.2 Repetir en el header del sub-panel expandido.
- [x] 5.3 Repetir en cada `<li>` del sub-panel por hit, junto al minute_label.
- [x] 5.4 `_groupRows()` en Alpine propaga `first_media_kind` al group dict y `media_kind` a cada hit (req para que los iconos aparezcan en el frontend).
- [x] 5.5 `MentionBackfillService::groupHits()` también propaga `first_media_kind` y `media_kind` (para vistas server-side como el admin en /ia/avisos-inteligentes/{user}/matches).

## 6. Smoke tests (validación end-to-end con Playwright)

- [x] 6.1 Login como cliente Multiarchivo en Mis Avisos (`/mis-avisos?as_user=3`). Verificar que el tab Histórico tiene los 3 botones "Todas / TV / Radio" visibles, con `Todas` activo por default.
- [x] 6.2 Hacer click en `[📺 TV]`. Verificar que el query saliente a `/mis-avisos/history` incluye `?q=...&media_type=tv` y que solo aparecen filas con `fa-tv` icon. Confirmar el ícono `fa-radio` NO aparece.
- [x] 6.3 Hacer click en `[📻 Radio]`. Verificar el opuesto.
- [x] 6.4 Hacer click en `Todas`. Verificar que el param `media_type` se omite (no se envía en la URL).
- [x] 6.5 Visual: en una fila con `winsport_xxx.mp4` el ícono `fa-tv` aparece inmediatamente antes del nombre; en una fila con `redmas_xxx.mp3` aparece `fa-radio`. En la fila sub-panel expandida, cada hit individual también tiene su ícono.
- [x] 6.6 Validar la combinación de filtros: con `media_type=tv` Y `storage_ids=[42]` activos, solo aparecen files de tipo video en el storage 42 (intersección AND).
- [x] 6.7 Confirmar que la posición de los botones TV/Radio es la misma en los dos tabs (al inicio del filter row, después del input de búsqueda).

## 7. Smoke tests (validación comando + edge cases)

- [x] 7.1 `curl -X GET 'https://cloud.mediaserver.com.co/mis-avisos/history?media_type=video' -b cookies.txt` debe devolver HTTP 422 (whitelist estricta).
- [x] 7.2 `curl -X GET 'https://cloud.mediaserver.com.co/mis-avisos/history?media_type=tv'` debe devolver 200 + solo rows `tv`.
- [x] 7.3 `curl -X GET 'https://cloud.mediaserver.com.co/mis-avisos/history?media_type=all'` debe devolver 200 + todos los rows (sin filtro).
- [x] 7.4 `curl -X GET 'https://cloud.mediaserver.com.co/mis-avisos/history'` (sin param) debe funcionar igual que `'all'`.

## 8. Rollback

- [x] 8.1 Para revertir el change: revertir los commits. La vista regresa al estado previo (sin íconos ni filtros de medio). Cero migraciones, cero impacto en BD.
- [x] 8.2 No hay scripts de migración ni tareas cron para revertir.
