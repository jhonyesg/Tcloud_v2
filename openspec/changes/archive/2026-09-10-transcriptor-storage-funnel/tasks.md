## 1. Servicio de conteos por storage

- [x] 1.1 Crear `app/app/Services/Ia/StorageFunnelService.php` con imports `use Illuminate\Support\Facades\{Cache, DB, Log};` y `use App\Models\StorageProvider;`
- [x] 1.2 Implementar método público `countsForScope(int $rootId): array` que use `Cache::remember("transcriptor.funnel.scope.{$rootId}", 60, fn() => $this->computeScopeCounts(...))` y devuelva `{storageId => {pending:int, in_flight:int, done:int}}`
- [x] 1.3 Implementar método privado `computeScopeCounts(int $rootId): array` que invoque `StorageProvider::resolveInheritedTranscriptionScope($rootId)` y ejecute la query agregada única agrupada por `files.storage_provider_id` y `transcriptions.state`
- [x] 1.4 En `computeScopeCounts`, transformar la matriz de estados a las tres categorías del spec: `pending`/`error` → pending; `queued`/`processing`/`(dispatched_at IS NOT NULL AND finished_at IS NULL)` → in_flight; `done` → done
- [x] 1.5 Implementar método público `invalidate(int $rootId): void` que ejecute `Cache::forget("transcriptor.funnel.scope.{$rootId}")` con guardarraíl `Log::info('transcriptor.funnel.invalidated', ['root_id' => $rootId])`
- [x] 1.6 Implementar método público `resolveRootIdFor(int $storageId): int` que ascienda en la jerarquía de prefijo para encontrar el ancestro raíz (usado por el toggle para saber qué cache key invalidar)

## 2. Integración en el controller

- [x] 2.1 En `ApiTranscriptorController::indexData()` (`app/app/Http/Controllers/Ia/ApiTranscriptorController.php:175-198`), inyectar `StorageFunnelService` vía constructor
- [x] 2.2 Para cada storage raíz con `transcription_enabled = true`, invocar `countsForScope($storage->id)` una sola vez y mergear el resultado dentro de cada fila `storages[*].funnel`
- [x] 2.3 Para cada storage hijo dentro del scope, mergear `funnel` con el agregado del scope (mismo dict reutilizado — referencia, no copia)
- [x] 2.4 Adjuntar a cada fila del padre `overlap_warning: bool` derivado de `StorageProvider` con `allow_parent_overlap = true` que tenga descendientes con `base_path` solapado
- [x] 2.5 Adjuntar a cada fila `parent_scope_id: ?int` calculado desde `resolveInheritedTranscriptionScope` para soportar el árbol plegable
- [x] 2.6 En el endpoint que toggle `transcription_enabled` (buscar handler PATCH existente alrededor de líneas 1050-1200), después de persistir el cambio invocar `StorageFunnelService::invalidate(StorageFunnelService::resolveRootIdFor($id))`
- [x] 2.7 Exponer a la vista el `session('user_id')` para usarlo como sufijo de la clave `localStorage` (vía `@json` en el bloque `@json($userId)`)

## 3. Vista Blade y Alpine

- [x] 3.1 En `resources/views/ia/api-transcriptor/index.blade.php` línea 277-282, añadir tres `<th>` (Pendientes, En curso, Listos) y uno (Prioridad) entre las columnas existentes
- [x] 3.2 En el cuerpo de la tabla (líneas 285-319), añadir cuatro `<td>` por fila con los valores de `s.funnel.pending`, `s.funnel.in_flight`, `s.funnel.done`, `s.transcription_priority`
- [x] 3.3 Añadir el badge ⚠ dentro de la celda Pendientes cuando `s.funnel.pending > threshold || s.funnel.recent_errors > 0` (threshold inyectado desde el controller)
- [x] 3.4 Añadir el badge `⚠ solapamiento` junto al nombre del storage padre cuando `s.overlap_warning === true`
- [x] 3.5 Añadir el chevron de expansión a la izquierda del nombre, clickable, con rotación 90° cuando `expandedScopes.has(s.id)`
- [x] 3.6 En el Alpine component `apiTranscriptor()` (línea 1773), añadir `expandedScopes: new Set(JSON.parse(localStorage.getItem('transcriptor-storages-expanded:' + this.userId) || '[]'))`
- [x] 3.7 Añadir método `toggleStorageExpansion(rootId)` que togglee el Set y persista `JSON.stringify([...this.expandedScopes])` en `localStorage` con la clave por usuario
- [x] 3.8 Renderizar las filas hijas indentadas con clase `pl-10` cuando el `parent_scope_id` correspondiente está en `expandedScopes`, filtrando `storages` con un computed getter
- [x] 3.9 En la columna Prioridad, renderizar el número dentro de un `<span class="px-2 py-0.5 rounded bg-slate-100 text-slate-700 text-xs">` sin handlers de click
- [x] 3.10 Inyectar `userId` al scope del Alpine component (vía `x-data="apiTranscriptor({ userId: {{ (int) session('user_id') }} })"` o atributo equivalente)

## 4. Settings de umbral

- [x] 4.1 En el bloque de inicialización de settings dentro de `indexData()` (o donde se lean settings de transcriptor), añadir lectura lazy de `system_settings.transcriptor_pending_alert_threshold` con default 5
- [x] 4.2 Persistir el threshold en BD la primera vez que se lea si no existe, vía `SystemSetting::firstOrCreate(['key' => 'transcriptor_pending_alert_threshold'], ['value' => '5'])`
- [x] 4.3 Pasar el threshold a la vista como `pendingAlertThreshold` para que la condición del badge ⚠ sea client-side simple

## 5. Verificación manual y diagnóstico

- [x] 5.1 Levantar la página `/ia/api-transcriptor` con un storage que tenga conteos conocidos y validar que las tres columnas coinciden con `SELECT storage_provider_id, state, count(*) FROM files JOIN transcriptions ON ... GROUP BY 1,2` *(verificado vía Playwright: Caracol Tv 17/79 cuadra con DB)*
- [x] 5.2 Validar que toggle de `transcription_enabled` en un hijo invalida el cache del scope del padre (segundo request debe ejecutar la query de nuevo — confirmar vía `Log::info` en `StorageFunnelService::computeScopeCounts`)
- [x] 5.3 Validar que la query agrupada es UNA sola (no N+1) revisando logs de Laravel para un scope con 5 storages
- [x] 5.4 Validar visualmente el árbol: expandir Emisoras 1 con tres hijos, recargar, confirmar que sigue expandido
- [x] 5.5 Validar el badge ⚠: forzar un storage con >5 pendientes y confirmar que aparece con el tooltip correcto
- [x] 5.6 Validar el badge ⚠ solapamiento: marcar temporalmente un storage padre con `allow_parent_overlap = true` y un hijo con prefijo solapado, confirmar el badge *(validado en prod 2026-09-10: storage 47 "01 Emisoras 01" con flag temporal=true → scope=12 miembros → overlap_warning=true; el par real padre-hijo por prefijo ya existía (47→62,70,61,65,68...). Flag revertido a false; overlap roots=0 tras revertir. El badge en la vista (`s.overlap_warning`) ya está cableado al mismo campo.)*
- [x] 5.7 Confirmar que `transcription_priority` se muestra como número en badge gris sin handlers de edición
- [x] 5.8 Confirmar que el dispatcher (`TranscriptionTickCommand`) y el scanner (`DiskScannerService`) NO fueron modificados por este change
- [x] 5.9 Confirmar que no se creó ninguna migración nueva

## 6. Fixes post-verificación con Playwright

- [x] 6.1 Crear migración `2026_09_08_000000_restore_transcription_priority_to_storage_providers.php` que re-añade `transcription_priority` (la columna había sido eliminada por la migración `2026_07_18_120000`)
- [x] 6.2 Aplicar la migración en producción
- [x] 6.3 Pivotar diseño: solo 2 columnas (Pendientes/Listos), filtro "hoy" en timezone Bogota, columna Cantidad estructural
- [x] 6.4 Fix bug `array_merge` con claves numéricas: usar `+` operator para preservar storage IDs como claves del dict
- [x] 6.5 Documentar y verificar manualmente con credenciales `jsuarez` / `T3cn0l0g14` vía Playwright
- [x] 6.6 Pivotar Cantidad a filesystem scan (depth 2, excluye carpetas dmY y .Recycle_bin) para reflejar la vista del operador en el navegador de archivos. 02 Emisoras 01 Reg = 34 ✓
- [x] 6.7 Implementar tarjetas resumen (Cantidad Total, Cantidad Real ponderado, Pendientes hoy, Listos hoy) arriba de la tabla
- [x] 6.8 Implementar buscador + paginacion en la tabla de storages (25 por pagina, 7 paginas)
- [x] 6.9 Calcular Cantidad Real (ponderado) como suma de Cantidad solo en storages hoja (descendant_count=0), para restar duplicados padre↔hijo
