## Context

La tabla Storages del módulo API Transcriptor (`resources/views/ia/api-transcriptor/index.blade.php:275-319`) actualmente solo expone cuatro columnas (Nombre, Tipo, Toggle, Acciones). El operador no puede detectar omisiones del scanner o acumulaciones en la cola. La columna `transcription_priority` existe en BD (`storage_providers`) desde la migración `2026_07_06_170009` pero ningún path de la app la lee ni la escribe, así que está inerte. La agregación padre-hijo de storages ya está implementada vía `StorageProvider::resolveInheritedTranscriptionScope(int $rootId)` (modelo, líneas 57-89), basada en prefijo de `base_path`. `indexData()` (controller `app/app/Http/Controllers/Ia/ApiTranscriptorController.php:175-198`) ya computa `descendant_count` y `descendant_names` por storage pero no los renderiza.

Las restricciones de proyecto relevantes:
- `corrections.md`: prohibido `no_consultas_pesadas_masivas_servidor` — todo agregado debe ser acotado.
- `AGENTS.md`: facades usadas dentro de `app/app/Services/*.php` requieren `use Illuminate\Support\Facades\X;` explícito (regresión `fix-storage-sync-missing-db-facade-import`).
- Auth vía `session('user')`, nunca `auth()->user()`.
- Alpine.js (CDN, sin build step) + Tailwind CDN, paleta `brand-500 = #4654a8`.

## Goals / Non-Goals

**Goals:**
- Mostrar conteos por storage sin disparar queries masivas repetidas.
- Reusar `resolveInheritedTranscriptionScope` para la agregación padre-hijo sin re-implementar la lógica de scope.
- Reusar el patrón de cache ya presente en `ApiTranscriptorController::emptyFolders()` (líneas 1598-1659) — `Cache::remember` con TTL.
- Mantener `transcription_priority` solo-lectura (sin endpoints nuevos en esta entrega).

**Non-Goals:**
- No se modifica `TranscriptionTickCommand`, `DiskScannerService` ni `ScanAndSubmitCommand`.
- No se añade endpoint nuevo.
- No se modifica `indexData()` para incluir los conteos (se hace en una llamada aparte que el controller hace una vez y adjunta a cada storage).
- No se migra `transcription_priority` a editable.

## Decisions

### Decisión 1: Servicio nuevo `StorageFunnelService` vs método en el modelo

**Decisión**: Crear `app/app/Services/Ia/StorageFunnelService.php` con un método público `countsForScope(int $rootId): array`.

**Por qué**: El modelo `StorageProvider` ya carga `resolveInheritedTranscriptionScope` (lógica de jerarquía). Meter la query agregada + cache dentro del modelo lo saturaría con responsabilidades no-relacionales (cache key, settings lookup, threshold). Un servicio dedicado mantiene la separación: el modelo dice "cuáles son los IDs del scope", el servicio dice "cuántas filas tiene cada estado en ese scope".

**Alternativas consideradas**:
- *Extender el modelo con un método `funnelCounts()`*: rechazado, mezcla cache y policy con concerns de ORM.
- *Ponerlo en el controller como helper privado*: rechazado, haría al controller de 1885 líneas aún más largo y no testeable aisladamente.

### Decisión 2: Query agregada única agrupada por `storage_provider_id` y `state`

**Decisión**: Una sola query:

```sql
SELECT f.storage_provider_id, t.state, COUNT(*) AS cnt
FROM files f
INNER JOIN transcriptions t ON t.file_id = f.id
WHERE f.storage_provider_id = ANY(:scopeIds)
  AND f.is_folder = false
  AND f.is_trashed = false
  AND f.deleted_at IS NULL
GROUP BY f.storage_provider_id, t.state
```

**Por qué**: Una sola pasada a la BD, sin N+1. Devuelve una matriz `{storageId => {state => count}}` que el controller agrega en PHP para producir las tres columnas (Pendientes/En curso/Listos). Compatible con el índice parcial `transcriptions_pending_dispatchable_idx` (migración `2026_09_07_141700`) que cubre `state='pending' AND dispatched_at IS NULL`.

**Filtros aplicados**:
- `is_folder = false` excluye entradas directorio que el scanner puede crear como `File` rows.
- `is_trashed = false AND deleted_at IS NULL` respeta la papelera de reciclaje (`files_destroy_uses_soft_trash` en memoria).
- NO se filtra por `availability_state` ni por `mtime`: el operador pidió números crudos para tener visibilidad completa.

### Decisión 3: Cache por scope con TTL 60s + invalidación por toggle

**Decisión**: Clave `transcriptor.funnel.scope.{rootId}` con `Cache::remember(..., 60, ...)`. La invalidación ocurre en el endpoint existente que toggle `transcription_enabled` (`PATCH /ia/api-transcriptor/storages/{id}/transcription-enabled`) usando `StorageFunnelService::invalidate(int $rootId)`.

**Por qué 60s y no 30s**: el operador pidió números "no agresivos" y la tabla se actualiza al refrescar la página; 30s duplicaría la presión sobre PostgreSQL sin beneficio observable (la página no auto-refresca esta tabla — solo se recarga al navegar o al ejecutar acciones). 60s alinea con el patrón de "live but cached" usado en el resto del módulo.

**Por qué por scope y no global**: invalidar todo al toggle de un hijo sería desperdicio (otros scopes no se afectaron). Como `resolveInheritedTranscriptionScope` resuelve la raíz con un BFS por prefijo, al toggle de un hijo su scope apunta a la raíz — esa es la clave a invalidar.

**Alternativa considerada**:
- *Cache global con TTL 30s*: rechazado por `no_consultas_pesadas_masivas_servidor`.

### Decisión 4: Render del árbol en Alpine, persistencia en `localStorage`

**Decisión**: Añadir al Alpine component `apiTranscriptor()` (línea 1773 de la vista) una propiedad `expandedScopes: Set<number>` inicializada desde `localStorage.getItem('transcriptor-storages-expanded:' + userId)` y un helper `toggleStorageExpansion(scopeRootId)`. Las filas se renderizan con un `x-for` sobre una versión pre-ordenada de `storages` donde cada hijo lleva `parentScopeId` calculado en PHP durante `indexData()`.

**Por qué Alpine y no un endpoint AJAX de árbol**: el array `storages` ya viene del `indexData()`; renderizar el árbol en cliente es O(n) sin tráfico de red. La persistencia en `localStorage` evita pedir estado al server.

**Clave por usuario**: `transcriptor-storages-expanded:{userId}` para que cada admin tenga su propio estado. `userId` viene de `session('user_id')` inyectado desde el controller a la vista como `@json`.

### Decisión 5: Badge ⚠ inline en celda Pendientes

**Decisión**: El badge ⚠ se renderiza dentro de la misma `<td>` que el número de Pendientes, no como columna aparte. Texto del badge: solo el carácter `⚠`. Tooltip vía atributo `title` HTML nativo.

**Por qué**: una columna ⚠ extra haría la tabla más ancha sin información adicional. El operador ve el símbolo junto al número y entiende que ese storage requiere atención. El tooltip da el detalle al hover.

### Decisión 6: `transcription_priority` como columna nueva entre "Tipo" y "Transcripción"

**Decisión**: Insertar la columna Prioridad justo después de Tipo (entre las columnas existentes). Razón: alinea visualmente con la columna Acciones y permite ver de un vistazo las prioridades relativas entre storages hermanos (Caracol TV=10 vs RCN TV=5).

**Por qué solo-lectura**: la columna existe en BD pero ningún código la usa; activarla en la UI de forma editable requiere cambiar `TranscriptionTickCommand::evaluateRegulator()` para que respete la prioridad en `ORDER BY`, lo cual está explícitamente fuera del scope (Non-goals). Mostrarla como solo-lectura es coherente con el estado actual del sistema y permite empezar a auditar prioridades reales antes de convertirlas en编辑.

## Risks / Trade-offs

- **[Riesgo] Cache stale de 60s** → el operador puede ver conteos desactualizados hasta 60s después de un toggle. *Mitigación*: el invalidación explícita en el toggle cubre el caso común; el peor caso es ver un conteo viejo durante <60s, aceptable para un panel de monitoreo.

- **[Riesgo] Doble conteo en padres con `allow_parent_overlap=true`** → si padre e hijo escanean el mismo archivo físico, el padre suma ambos `Transcription` rows para el mismo `file_id`. *Mitigación*: el spec incluye un badge `⚠ solapamiento` en la fila del padre cuando hay overlap; el operador ve el aviso y decide si la configuración es correcta.

- **[Riesgo] `resolveInheritedTranscriptionScope` puede devolver muchos IDs en cadenas profundas** → la query `WHERE storage_provider_id = ANY(...)` con arrays grandes sigue siendo eficiente con el índice `files_storage_provider_id_idx` (asumido; verificar en EXPLAIN antes de merge). *Mitigación*: si el plan es subóptimo, agregar índice btree explícito en `files.storage_provider_id` (migración segura, no disruptiva).

- **[Riesgo] El servicio toca `system_settings` (cache facade + setting lookup)** → la regresión `fix-storage-sync-missing-db-facade-import` documentada en AGENTS.md indica que cualquier facade usada en `app/app/Services/*.php` requiere `use` explícito. *Mitigación*: el código del servicio importará `Cache`, `DB`, `Log` con `use Illuminate\Support\Facades\{Cache, DB, Log};` en el bloque de imports superior.

- **[Riesgo] `indexData()` ya tarda ~430ms por la resolución de scope** → añadir el lookup de conteos puede sumar 100-200ms más incluso con cache hit en el segundo request. *Mitigación*: el cache mitiga el steady-state; el primer request cold es aceptable. Monitorear con `Log::info('transcriptor.funnel.cold', ['duration_ms' => ...])` la primera vez.

- **[Trade-off] El badge ⚠ dispara con `error`/`dead` de las últimas 24h** → un storage con errores viejos que ya no aplican no dispara alerta, pero tampoco lo hace un storage con `error` recurrentes si todos son >24h. *Mitigación*: la ventana de 24h es el default; el spec la deja fija en esta entrega. Si el operador pide ajustar, será un cambio de settings posterior sin tocar el spec.

## Migration Plan

No hay migración de BD. No hay cambios al dispatcher ni al scanner. Pasos de despliegue:

1. Crear el servicio nuevo (sin efecto hasta que se conecte).
2. Modificar `indexData()` para invocar `StorageFunnelService::countsForScope()` por cada storage raíz y adjuntar el resultado a cada fila (`funnel: {pending, in_flight, done}`).
3. Modificar la vista Blade para renderizar las tres columnas nuevas + badge + chevron de expansión.
4. Modificar el endpoint de toggle para invocar `StorageFunnelService::invalidate()` después de cambiar `transcription_enabled`.
5. Verificación: abrir `/ia/api-transcriptor` en un entorno con storages reales, comparar conteos con una query manual `SELECT storage_provider_id, state, count(*) FROM ... GROUP BY 1,2`.

Rollback: como no hay migración ni cambios al dispatcher, revertir el merge de los tres archivos (controller, vista, servicio nuevo) deja el sistema idéntico al estado actual.

## Open Questions

- ¿El operador prefiere que `transcription_priority` se muestre a la izquierda (antes de Pendientes) en vez de entre Tipo y Transcripción? Afecta solo placement visual, no el spec; puede ajustarse al implementar tasks sin reabrir este change.
