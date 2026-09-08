# Diseño: Indicador visual y filtro por tipo de medio (TV/Radio)

## Context

Mis Avisos (`/mis-avisos`) muestra las menciones detectadas por el sistema de matching para el cliente autenticado. Hoy cada fila es un hit con su nombre de archivo, emisor, snippet y minuto — pero **no hay forma visual de saber si ese archivo es video o audio**. Los storages de radio pueden contener mp3/m4a, los de TV mp4. El campo `mime_type` en `files` ya distingue los tipos pero nunca se proyecta a la UI.

Adicionalmente, no hay filtro por tipo de medio: el cliente que quiere ver "solo lo que es TV" tiene que revisarlo visualmente.

## Goals / Non-Goals

**Metas:**
- Cada hit expone `media_kind ∈ {tv, radio, other}` en su row JSON.
- El ícono `fa-tv` aparece al inicio del filename en filas de tipo `tv`.
- El ícono `fa-radio` aparece al inicio del filename en filas de tipo `radio`.
- 3 botones toggle "Todas / TV / Radio" en En vivo y Histórico, con el mismo patrón visual que "Hoy/Ayer/3d/7d".
- El filtro combina con los demás vía AND.

**No-metas:**
- No modificar el storage `kind` (es 'local'|'external', no relacionado).
- No tocar el admin `ia/avisos-inteligentes/{userId}` (scope paralelo opcional después).
- No agregar ícono para mime_types != video/audio.
- No persistir el filtro en URL (no bookmarkeable por ahora).

## Decisions

### 1. Derivar `media_kind` en `hitRow()`, no en la vista
**Decisión:** agregar un campo calculado en `MentionsSearchService::hitRow()` que ya centraliza el shape de cada hit. La vista simplemente lee `$row->media_kind`. Single source of truth.

**Por qué:** ya tenemos `f.mime_type` en el `hitSelect()`. El cálculo es trivial (`str_starts_with($mime, 'video/')` → tv, `audio/` → radio, sino → other). Hacerlo en la vista lo acopla al layout; hacerlo en el service lo deja reutilizable (admin puede sumarlo después sin re-implementar).

**Alternativas:**
- *Hacerlo en la vista con `x-init` y computed Alpine* → menos consistente entre Mis Avisos y admin.
- *Materializarlo en la tabla `segment_keyword_hits`* → redundante porque el mime_type ya está en `files`.

### 2. Usar `fa-tv` y `fa-radio` (literal: TV vs Radio)
**Decisión:** el ícono de TV es `fas fa-tv` (monitor clásico). El ícono de Radio es `fas fa-radio` (radio con dial/antena). Ambos ya están cargados vía FontAwesome en `fontawesome.min.css`.

**Por qué:** la user pidió explícitamente "un radio" y "un televisor" — no "una antena" ni "un micrófono". `fa-radio` y `fa-tv` son la representación más literal.

**Alternativas:**
- `fa-broadcast-tower` para radio (antena transmisora) — descartado: visualmente es menos "radio" y más "antena de broadcast".
- `fa-microphone` para radio — descartado por la misma razón: es un micrófono, no una radio.

### 3. 3 botones toggle, no dropdown
**Decisión:** usar el mismo patrón visual que los date pills "Hoy/Ayer/3d/7d". Mantiene el lenguaje visual consistente del módulo y agrupa bien visualmente.

**Por qué:** el dropdown tendría más fricción (abrir, elegir, cerrar). El toggle es 1 click para cambiar.

### 4. Filtro per-tab (no compartido)
**Decisión:** `liveFilters.media_type` y `historyFilters.media_type` viven separados igual que `storage_ids`. Cambiar de tab mantiene cada vista con su filtro.

**Por qué:** distinto uso. El En vivo suele pedir "todo lo que cae hoy", el Histórico suele ser "filtrado por rango y storage". Forzar uno común los acopla de manera forzada.

### 5. Sin URL bookmarkeable
**Decisión:** el filtro se mantiene en el state Alpine; no se persiste en query string.

**Por qué:** agregar `?media_type=tv` a `/mis-avisos` lo haría bookmarkeable pero requiere también lógica en el controller para leerlo en carga inicial. Salir de explore y entrar a implementación: agregar si la user lo pide después.

### 6. Posición del ícono: al inicio del filename (dentro del `<td>`)
**Decisión:** el ícono aparece como un `<span class="..."><i class="fas fa-tv"></i></span>` inmediatamente antes del texto del filename. La celda del filename pasa a tener un layout flex con `align-items-center`.

**Por qué:** la user pidió "al inicio del nombre de archivo" (textualmente dentro del nombre). El ícono aparece como prefijo visual sin alterar el texto real del filename (importante: el `filename` sigue siendo la fuente para `file_url` y los hrefs Ver/Editor).

### 7. Color del ícono: gris medio neutral
**Decisión:** el ícono usa `text-slate-400` (gris neutral). No compite con el snippet ni con la keyword badge violeta.

**Por qué:** la jerarquía visual existente prioriza: keyword (violeta), archivo (texto base), snippet (slate-500). El ícono es soporte, no foco de atención — gris medio conserva la jerarquía.

### 8. Aceptar solo whitelist en backend
**Decisión:** el controller acepta solo `tv`, `radio`, `all`. Cualquier otro valor → HTTP 422.

**Por qué:** el filtro va a LIKE al SQL. Inyectar `video/' OR 1=1` sería inyección. Whitelist + validación estricta blinda esto.

## Risks / Trade-offs

- **[Trade-off] Detección por mime_type puede fallar con archivos mal etiquetados.** Si alguien subió un mp3 con `mime_type='application/octet-stream'`, sale como `other` aunque sea audio. Es la realidad. Mitigation: si el cliente nota el caso, abrir el archivo y/o reforzar el mime_type detection a nivel de upload (cambio aparte).

- **[Trade-off] El ícono "other" no se muestra.** Si por algún motivo aparece un pdf o json en Mis Avisos, no tendrá ícono. Eso es intencional — solo señalamos TV y Radio.

- **[Risk] El Admin `ia/avisos-inteligentes/{userId}` no recibe el cambio.** El admin sigue sin ver íconos ni filtro de tipo en su módulo de Matches. Out-of-scope por pedido de la user. Si después lo piden, se hace en change paralelo.

## Migration Plan

1. Cambiar `MentionsSearchService::hitRow()` para agregar `media_kind` (privado, no schema).
2. Cambiar `applyHitFilters()` para aceptar filtro opcional de `media_type`.
3. Cambiar `MisAvisosController::history()` y `::feed()` para leer `request('media_type')` y validar whitelist.
4. Cambiar `index.blade.php` para agregar estado `liveFilters.media_type` / `historyFilters.media_type`, UI de los 3 botones, y `pollLive()` / `searchHistory()` para enviar el nuevo filtro.
5. Cambiar `_table-hits.blade.php` para agregar el ícono en la columna del filename.
6. Validar con Playwright en Mis Avisos con `as_user=3` (Multiarchivo, que tiene audios + videos).

Rollback: revertir los commits. Cero migrations, cero impacto en BD.

## Open Questions

- ¿La user quiere que el filtro aparezca también en el admin (Mis Avisos → fase-1 admin module) en un change futuro? Sí / No. Si sí, abrimos un `ia-avisos-inteligentes-media-kind` change paralelo después de archivado este.
- ¿Persiste el filtro en URL para bookmarking? Sí / No. Default No.
