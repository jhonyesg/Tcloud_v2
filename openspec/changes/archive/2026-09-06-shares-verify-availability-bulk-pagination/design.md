## Context

`ShareController::verifyAvailability` (`:316-339`) y su cliente `verifyAllFiltered()` (`shares/index.blade.php:382-407`) implementan un patrón "pagear con LIMIT" para verificar la disponibilidad de archivos en bloques de 50. La query ya viene filtrada (`selectedSharesQuery` re-aplica filtros del usuario), y luego se le aplica `$query->limit($batchLimit)`. El cliente rompe el loop cuando el batch devuelve menos filas que el batch size, asumiendo que se agotó el dataset. Cuando el último lote es pequeño **porque el filtro recortó el resultado** (no porque se haya terminado), el cliente sale sin verificar el resto.

Adicionalmente, `selectedSharesQuery` hace `->reorder('shares.id')` (`:447`), que descarta el orden visible del usuario (`permission`, `status`, `created_at`, etc.) durante las operaciones bulk. Para una sola selección eso es aceptable; para `verifyAllFiltered` provoca que el cliente recorra IDs en un orden distinto al que ve en pantalla, lo que confunde cuando el usuario intenta correlacionar resultados con la lista.

## Goals / Non-Goals

**Goals:**
- Garantizar que `verifyAllFiltered` recorra **todos** los shares que cumplen los filtros actuales, hasta agotar el dataset.
- Devolver un cursor estable por batch para que el cliente pueda continuar sin re-procesar.
- Preservar el orden visible (`sort` + `direction`) del usuario en la query bulk.

**Non-Goals:**
- No cambiar el endpoint ni añadir nuevos.
- No paralelizar la verificación (sigue siendo un cliente → un backend secuencial).
- No introducir un job asíncrono encolarable (mantener la simplicidad actual de "verificar ahora").
- No tocar la verificación single-shot de `verifySelected()` cuando se eligen IDs explícitos (la paginación solo aplica cuando el alcance es `all_matching`).

## Decisions

### 1. Paginación con cursor estable `after_id`

En `verifyAvailability`, el cliente envía un parámetro opcional `after_id` (entero). El backend aplica `where('shares.id', '>', $afterId)` sobre la query ya filtrada y ordenada, después del `reorder`. Esto garantiza:
- Avance monotónico: cada batch ve el siguiente tramo y nunca solapa con el anterior.
- Compatibilidad con cualquier `sort`/`direction` del usuario: el cursor filtra por `id`, pero el `ORDER BY` resultante mantiene la dirección del usuario. Si el usuario pidió `desc`, los IDs mayores vienen primero y el cursor `id > X` los procesa correctamente; si pidió `asc`, también.

El batch default se mantiene en `config('shares.availability_verification_limit', 100)` y el cliente puede pedir hasta 200 (igual que hoy).

### 2. Respuesta enriquecida: `next_cursor` y `has_more`

La respuesta añade:
- `next_cursor`: el último `id` procesado en el batch (o `null` si no quedan más).
- `has_more`: boolean explícito (`true` cuando `shares.count() >= batchLimit` y existe un `next_cursor` distinto de `null`).

Con esto el cliente decide cuándo parar basándose en `has_more`, no en heurísticas sobre el tamaño del batch. Compatibilidad: `has_more` ya existe en la respuesta actual (`:337`); se reutiliza y se asegura que refleje el comportamiento correcto bajo paginación cursor-based.

### 3. Preservar el orden visible en operaciones bulk

Eliminar el `->reorder('shares.id')` de `selectedSharesQuery` cuando el alcance es `all_matching` (es decir, para `verifyAvailability` y `bulkPreview`/`bulkDelete` en modo "todos los filtrados"). El orden por defecto (`created_at DESC`) ya es estable; cualquier `sort` del usuario es estable por el tiebreaker `shares.id DESC` que `shareQuery` añade al final.

Para los endpoints donde `ids[]` viene explícito (caso `verifySelected` no bulk, o `bulkDelete` con IDs), mantener el orden por `id` porque los IDs son un set arbitrario.

### 4. Loop cliente con `next_cursor`

`verifyAllFiltered()` reescribe su `while` para:
```js
let cursor = null;
do {
  body.after_id = cursor;
  const payload = await POST ...;
  processed += payload.checked;
  cursor = payload.has_more ? payload.next_cursor : null;
} while (cursor !== null);
```

Esto elimina la condición frágil `payload.checked < batchSize` y hace explícito el avance.

### 5. Cancelable / interrumpible

Si el usuario navega o recarga durante el barrido, el cliente actual ignora la respuesta (es el mismo patrón que existe hoy). No agregamos AbortController porque ya hay `bulkLoading` flag.

## Risks / Trade-offs

- [Riesgo: si el usuario cambia de filtro mid-barrido, el cursor queda obsoleto] → Mitigación: el cursor es solo `shares.id`; si el filtro cambia, el backend lo trata como filtro nuevo y devuelve el primer tramo del nuevo conjunto. La UI lo cubre porque `applyFilters()` ya limpia la selección y reinicia `bulkLoading` indirectamente al recargar el listado.
- [Riesgo: orden `desc` por `permission`/`status` cruza IDs en orden inverso, pero el cursor `id > X` no lo aprovecha] → Mitigación: aceptable. La verificación no requiere orden de IDs contiguo; basta con que cada batch sea un tramo exclusivo. El usuario ve el orden visible en pantalla, el bulk verifica en otro orden pero completo.
- [Riesgo: si `has_more` se calcula antes del filtro de cursor, podría mentir] → Mitigación: `has_more` se calcula sobre `shares.count() >= batchLimit` después del `where id > cursor` y el `limit(batchLimit)`. Es exactamente la condición que ya existe.
- [Trade-off: `after_id` requiere que el ORDER BY final coloque `shares.id` como tiebreaker determinista] → Ya garantizado por `shareQuery` (`:443`: `orderBy('shares.id','desc')`).

## Migration Plan

Sin migración. Cambios en runtime:
1. Backend: extender `verifyAvailability` con cursor + respuesta enriquecida.
2. Backend: ajustar `selectedSharesQuery` para no `reorder` cuando `all_matching`.
3. Frontend: reescribir `verifyAllFiltered` con loop por cursor.

Rollback: revertir los tres cambios. Sin estado persistente (no hay cache de cursor del lado servidor).

## Open Questions

Ninguna. El cambio es ortogonal a la spec del usuario y a la arquitectura existente.
