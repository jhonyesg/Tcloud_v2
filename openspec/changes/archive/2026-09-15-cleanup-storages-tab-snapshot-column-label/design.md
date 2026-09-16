## Context

Ver `proposal.md` para motivación. Resumen del estado actual:

- `app/resources/views/ia/api-transcriptor/index.blade.php` muestra una tabla de storages dentro de la pestaña Storages del módulo API Transcriptor (`/ia/api-transcriptor`).
- La columna con cabecera **"Snapshot transcriptor"** (línea 256) renderiza `s.funnel?.pending` con icono ⚠ si `shouldWarnPending(s)`. Su contenido NO proviene de la tabla `transcription_storage_snapshots` — es un conteo en vivo del funnel. La cabecera induce a error.
- La columna **"Pendientes (hoy)"** (líneas 259-265) muestra otro número del funnel, ordenable por `pending`. Visualmente es un duplicado.
- La celda de snapshot REAL (con cola remota + errores hoy) está al final de la fila (líneas 380-425), técnicamente "dentro" del `<td>` de Acciones pero visualmente como una sub-card debajo de "Ver archivos". Es la que el usuario confirmó útil.
- El badge "Errores hoy: N" del header (líneas 181-188) se alimenta del `snapshotErrors` map, que se llena cuando la celda detallada hace fetch. Si quitamos esa celda, el badge se cae.
- La etiqueta del header anterior decía "Snapshot por storage del transcriptor (cola PG + upstream). Se actualiza cada 15 min." — la celda NO se actualiza cada 15 min, se actualiza en cada `pagedStorages()` render (que es por request). Esta es la mentira que estamos corrigiendo con el rename.

## Goals / Non-Goals

**Goals:**
- Eliminar la contradicción entre cabecera y contenido sin tocar lógica ni endpoints.
- Mantener intacta la utilidad validada por el usuario: `cola remota`, `delta vs 15 min`, `errores hoy` por storage, badge "Errores hoy".
- Cero regresiones downstream (Correcciones / Avisos / Mis Avisos no consumen estas cabeceras, pero validamos que `ApiTranscriptorController::index` y los métodos de fila siguen funcionando).
- Cero migración de BD, cero deploy disruptivo — un `php artisan view:clear` + reload del browser alcanza.

**Non-Goals:**
- No mover la celda detallada de snapshot a otro lugar de la fila (eso es Opción B del explore).
- No eliminar la tabla `transcription_storage_snapshots`, el cron, ni el endpoint `/snapshot` — son dependencias vivas de la celda detallada y del badge.
- No aplicar el estándar Mis Avisos a esta tabla — ya cumple con elipsis ±2, no se toca.
- No tocar `storage-table-sortable` (spec legacy de la tabla de storages principal `/admin/storages`, no la del módulo API Transcriptor).

## Decisions

### Decisión 1: Renombrar "Snapshot transcriptor" → "Pendientes (live)" en lugar de eliminar la columna

**Razón**: la celda sigue siendo útil como indicador "live" y el usuario está acostumbrado a verla. Es la única fuente de warning ⚠ por storage (de `shouldWarnPending(s)`), que alerta cuando el volumen de pendientes supera el umbral. Quitarla perdería esa señal.

**Alternativas consideradas**:
- (a) Renombrar a "Pendientes (live)" ← elegido
- (b) Renombrar a "Pendientes funnel" — más técnico, menos claro para operador final
- (c) Eliminar la columna entera y mover el `s.funnel?.pending` adentro de la celda detallada de snapshot como primer item — agrega complejidad visual sin ahorro real

### Decisión 2: Eliminar "Pendientes (hoy)" y el sort key `pending`

**Razón**: bajo la cabecera "Pendientes (hoy)" se renderiza una de las variantes del funnel pending; "Snapshot transcriptor" (renombrada a "Pendientes (live)") muestra el mismo dato. Las dos son ordenables, ambas van al mismo backend (`s.funnel?.pending`). Eliminar una reduce ruido sin perder capacidad de orden (la columna restante sigue siendo ordenable).

**Verificación previa**: hay que confirmar que `setStoragesSort('pending')` y `storagesSortIcon('pending')` no se invocan en ningún otro control del módulo. Si apareciera un caller oculto, en lugar de quitarlo solo se elimina la cabecera visible y el `td`, conservando el método Alpine por compatibilidad.

**Alternativa considerada**:
- (a) Mantener "Pendientes (hoy)" como sort alias de la otra columna — confuso, dos cabeceras para el mismo sort.
- (b) Renombrar una de las dos columnas y fusionar sort — visualmente igual a la decisión tomada, pero con más diff para el mismo resultado.

### Decisión 3: Mantener la celda detallada exactamente donde está

**Razón**: moverla requiere reorganizar el grid de columnas, redefinir anchos (`pr-3`, `text-right`, etc.) y probablement re-tunear el responsive. Es trabajo extra sin beneficio funcional. El usuario ya la lee bien.

**Riesgo aceptado**: la celda detallada sigue "anidada" en el `<td>` de Acciones. Eso ya era así antes del change y no es un bug; queda fuera de scope reorganizar.

### Decisión 4: No tocar `shouldWarnPending` / `pendingWarningTitle`

**Razón**: viven en la columna renombrada. Su semántica (`pending > threshold → warn`) sigue siendo válida. La decisión es solo de cabecera.

### Decisión 5: Renombrar title del `<th>` para reflejar la realidad

- Actual: `title="Snapshot por storage del transcriptor (cola PG + upstream). Se actualiza cada 15 min."` (mentira — se actualiza por request)
- Nuevo: `title="Conteo en vivo del funnel de pendientes por storage (calculado en cada render)."`

## Risks / Trade-offs

- **[Risk] El usuario que ya se acostumbró al nombre "Snapshot transcriptor" se confunde con "Pendientes (live)"** → **Mitigation**: el `<th>` title actualizado explica explícitamente que es conteo en vivo. Y el badge "Errores hoy" + la celda detallada mantienen visible la palabra "snapshot" para quien la busca.

- **[Risk] Alguien external (script, harness) consulta `data-column-key="snapshot"` en el `<th>`** → **Mitigation**: búsqueda previa en el repo confirma que no hay selectores que dependan del texto literal de la cabecera. La columna no tenía `data-*` attribute, solo el título y el texto visible.

- **[Risk] Tras eliminar el `<td>` de "Pendientes (hoy)", alguna parte del Alpine JS queda leyendo una propiedad que ya no se renderiza** → **Mitigation**: la única propiedad de esa celda era el número de pendientes, que ya viene en `s.funnel?.pending` y sigue disponible en la celda adyacente. No hay subscripciones reactivas que dependan del DOM deleted.

- **[Risk] Algún test/harness de regresión captura el orden de columnas** → **Mitigation**: no hay harness que cubra el layout de columnas de la pestaña Storages del módulo API Transcriptor (verificado: ningún `harness_*.php` con snapshot o storages transcriptor relacionados). El único harness relacionado, `harness_transcriptor_storage_snapshots.php`, valida la tabla `transcription_storage_snapshots` (no se toca).

- **[Trade-off]** El usuario pierde un criterio de orden (`pending`), pero el sort sigue funcionando con la columna "Pendientes (live)" conservada.

- **[Trade-off]** Si en el futuro quieren ver el conteo del snapshot (15 min) vs el conteo en vivo lado a lado, ya no tendrán columna dedicada. Se puede resolver agregando una nueva columna después, o mostrando `pending_count` (snapshot) vs `current` (live) en la celda detallada (queda como follow-up).

## Migration Plan

No hay migración. Pasos de deploy:

1. Edit de un solo archivo (`app/resources/views/ia/api-transcriptor/index.blade.php`):
   - Renombrar `<th>` de línea 256 y su `title`.
   - Eliminar bloque `<th>` de líneas 259-265 (cabecera "Pendientes (hoy)") y su botón sort.
   - Eliminar bloque `<td>` de líneas 338-345 (la celda con `s.funnel?.pending` que se corresponde con la cabecera eliminada — verificar emparejamiento header↔celda antes de borrar).
2. Verificar emparejamiento: la columna "Pendientes (live)" (renombrada) debe quedar sobre la celda que renderiza `s.funnel?.pending` + ⚠.
3. Sin tocar la celda detallada (líneas 380-425) ni nada del flujo del snapshot.
4. Validación:
   - `php -l resources/views/ia/api-transcriptor/index.blade.php` (sanity, no es PHP puro pero sirve para detectar leaks).
   - Reload manual en `/ia/api-transcriptor` con consola del browser vacía (per AGENTS.md `ui_validation_clean_console`).
   - Confirmar que el badge "Errores hoy" sigue actualizándose y que la celda detallada sigue mostrando `cola remota` + `errores hoy`.
   - Confirmar que la paginación con elipsis ±2 sigue renderizando bien.

**Rollback**: `git revert` del commit del cambio. Sin estado que limpiar (Blade recompila en el siguiente request, no hay opcode cache persistente que purgar para vistas).

## Open Questions

Ninguna. Las decisiones tomadas son reversibles mediante `git revert` y no dependen de inputs pendientes del usuario.
