## Resumen

Este change introduce tres cambios coordinados al scanner automático del módulo API Transcriptor:

1. **Layout-aware scanning** para soportar storages con estructura `base_path/<subcarpeta>/dmY/*` (emisoras consolidadas).
2. **Scope-aware deduplication** con semántica de **owner único** (el storage más específico gana).
3. **Eliminación completa** del campo `transcription_priority` (DB + UI + cola Redis) porque no se observa efecto operativo y la prioridad real se asigna desde el panel del API Transcriptor.

Los tres cambios tocan el mismo servicio central (`DiskScannerService`) y la misma tabla (`storage_providers`), por lo que se agrupan en un único change para minimizar el riesgo de migración y mantener la coherencia del modelo de ownership.

---

## 1. Schema migration

```php
// database/migrations/2026_07_18_120000_add_folder_layout_and_dedup_to_storage_providers.php

public function up(): void
{
    Schema::table('storage_providers', function (Blueprint $table) {
        $table->string('folder_layout', 20)
            ->default('flat')
            ->after('transcription_enabled');
        $table->boolean('allow_parent_overlap')
            ->default(false)
            ->after('folder_layout');

        // Backfill de constraint para el enum
        DB::statement("ALTER TABLE storage_providers
                       ADD CONSTRAINT storage_providers_folder_layout_check
                       CHECK (folder_layout IN ('flat', 'grouped_by_subfolder'))");
    });

    // Seed inicial: storages emisoras consolidados → grouped_by_subfolder
    DB::table('storage_providers')
        ->whereIn('id', [47, 49])  // 01 Emisoras 01, 02 Emisoras 01 Reg
        ->update(['folder_layout' => 'grouped_by_subfolder']);

    // DROP transcription_priority (dividido en 2 pasos por si la columna no existe aún)
    if (Schema::hasColumn('storage_providers', 'transcription_priority')) {
        Schema::table('storage_providers', function (Blueprint $table) {
            $table->dropColumn('transcription_priority');
        });
    }
}

public function down(): void
{
    Schema::table('storage_providers', function (Blueprint $table) {
        $table->integer('transcription_priority')->default(0)->after('folder_layout');
    });
    DB::table('storage_providers')->update(['transcription_priority' => 0]);

    DB::statement("ALTER TABLE storage_providers DROP CONSTRAINT IF EXISTS storage_providers_folder_layout_check");

    if (Schema::hasColumn('storage_providers', 'allow_parent_overlap')) {
        Schema::table('storage_providers', function (Blueprint $table) {
            $table->dropColumn('allow_parent_overlap');
        });
    }
    if (Schema::hasColumn('storage_providers', 'folder_layout')) {
        Schema::table('storage_providers', function (Blueprint $table) {
            $table->dropColumn('folder_layout');
        });
    }
}
```

**Validación del seed**: confirmar que `47 (Emisoras 01)` y `49 (Emisoras 01 Reg)` son los únicos consolidados activos. Si en el futuro se agregan más, ejecutar `UPDATE storage_providers SET folder_layout='grouped_by_subfolder' WHERE id = <nuevo_id>;`.

---

## 2. Algoritmo de scan (scope-aware + layout-aware)

### 2.1 Constantes y configuración

```php
// app/app/Services/Ia/DiskScannerService.php

const LAYOUT_FLAT = 'flat';
const LAYOUT_GROUPED = 'grouped_by_subfolder';

public function scanStorage(
    StorageProvider $storage,
    int $daysBack = 0,
    bool $all = false,
    ?int $batchOverride = null
): array {
    $basePath = rtrim((string) $storage->base_path, '/');
    if (!is_dir($basePath) || !is_readable($basePath)) {
        return $this->emptyStats();
    }

    $layout = $storage->folder_layout ?? self::LAYOUT_FLAT;
    $batch = $batchOverride ?? (int) config('transcriptor.scan_batch', 100);
    $minAge = (int) config('transcriptor.scan_min_age_seconds', 60);
    $cutoff = time() - $minAge;

    // Paso 1: calcular subpaths a excluir (storages hijos con transcription_enabled)
    $excludedFirstSegments = $storage->allow_parent_overlap
        ? []
        : $this->computeExcludedSubpaths($storage);

    // Paso 2: descubrir carpetas de día según layout
    $folderPaths = match ($layout) {
        self::LAYOUT_GROUPED => $this->dayFoldersGrouped($basePath, $daysBack, $all),
        default              => $this->dayFolders($basePath, $daysBack),
    };

    // Paso 3: iterar carpetas, excluyendo las que caen bajo un hijo
    $candidates = [];
    foreach ($folderPaths as $folder) {
        if ($this->isInExcludedPath($folder, $basePath, $excludedFirstSegments)) {
            Log::info("DiskScanner: skip {$folder} (storage hijo toma control)");
            continue;
        }
        $candidates = array_merge($candidates, $this->collectCandidates($folder, $cutoff));
    }

    // Resto del método (sort, batch, create File/Transcription) igual al actual
    // ... PERO antes de crear el File, validar dedup por absolute_path:
    foreach ($candidates as $c) {
        $absolutePath = $basePath . '/' . $c['path'];
        $existingOwner = $this->findOwnerByAbsolutePath($absolutePath, $storage->id);
        if ($existingOwner !== null) {
            Log::info("DiskScanner: skip {$absolutePath} (dueño: storage {$existingOwner->id} {$existingOwner->name})");
            continue;
        }
        // ... crear File + Transcription como hoy ...
    }
}
```

### 2.2 Funciones nuevas

```php
/**
 * Devuelve la lista de primeros segmentos de path (relativos a $storage->base_path)
 * que son base_path de otros storages con transcription_enabled=true.
 * El scanner omitirá esos subdirectorios completos para no duplicar.
 */
private function computeExcludedSubpaths(StorageProvider $storage): array
{
    $prefix = rtrim($storage->base_path, '/') . '/';

    return StorageProvider::transcriptionEnabled()
        ->where('id', '!=', $storage->id)
        ->where('allow_parent_overlap', false)
        ->where('base_path', 'LIKE', $prefix . '%')
        ->pluck('base_path')
        ->map(function ($otherBase) use ($storage) {
            $relative = ltrim(substr($otherBase, strlen(rtrim($storage->base_path, '/'))), '/');
            return explode('/', $relative)[0]; // solo el primer segmento
        })
        ->unique()
        ->values()
        ->toArray();
}

/**
 * Determina si una carpeta absoluta cae dentro de algún subpath excluido.
 */
private function isInExcludedPath(string $absoluteFolder, string $basePath, array $excludedFirstSegments): bool
{
    if (empty($excludedFirstSegments)) return false;

    $relative = ltrim(substr($absoluteFolder, strlen(rtrim($basePath, '/'))), '/');
    $firstSegment = explode('/', $relative)[0] ?? '';

    return in_array($firstSegment, $excludedFirstSegments, true);
}

/**
 * Busca si ya existe un File con el mismo absolute_path bajo OTRO storage.
 * Devuelve el StorageProvider dueño, o null si nadie lo tiene.
 *
 * absolute_path = storage_provider.base_path + '/' + file.path
 */
private function findOwnerByAbsolutePath(string $absolutePath, int $excludeStorageId): ?StorageProvider
{
    return StorageProvider::where('id', '!=', $excludeStorageId)
        ->whereHas('files', function ($q) use ($absolutePath) {
            $q->whereRaw("? LIKE (storage_providers.base_path || '/%') || files.path", [$absolutePath])
              // más estricto: exact match usando concat
              ->orWhereRaw("storage_providers.base_path || '/' || files.path = ?", [$absolutePath]);
        })
        ->first();
}

/**
 * Para layout 'grouped_by_subfolder': devuelve TODAS las rutas absolutas
 * de carpetas <subcarpeta>/dmY/ bajo basePath.
 *
 * Ejemplo: basePath=/.../Radios/Bogota, días=0
 *   → /.../Radios/Bogota/CARACOL/18072026
 *   → /.../Radios/Bogota/BLURADIO/18072026
 *   → /.../Radios/Bogota/LA_W/18072026
 *   → ...
 */
private function dayFoldersGrouped(string $basePath, int $daysBack, bool $all): array
{
    $folders = [];
    $dayNames = [];
    for ($i = 0; $i <= $daysBack; $i++) {
        $dayNames[] = now()->subDays($i)->format('dmY');
    }

    $subfolders = $all
        ? $this->allFoldersRecursive($basePath, 1) // profundidad 1
        : $this->immediateSubfolders($basePath);

    foreach ($subfolders as $sub) {
        foreach ($dayNames as $dayName) {
            $candidate = $sub . '/' . $dayName;
            if (is_dir($candidate) && is_readable($candidate)) {
                $folders[] = $candidate;
            }
        }
    }
    return $folders;
}

private function immediateSubfolders(string $basePath): array
{
    $result = [];
    foreach (scandir($basePath) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if (str_starts_with($entry, '.')) continue;
        $full = $basePath . '/' . $entry;
        if (is_dir($full) && is_readable($full)) {
            $result[] = $full;
        }
    }
    return $result;
}
```

### 2.3 Diagrama de flujo

```
scanStorage(storage 47 "01 Emisoras 01")
├─ basePath = /.../Radios/Bogota
├─ folder_layout = 'grouped_by_subfolder'
├─ allow_parent_overlap = false
│
├─ computeExcludedSubpaths(47):
│   └─ storage 63 (LA_W) base_path startsWith /.../Radios/Bogota/
│   └─ excluded = ['LA_W']
│
├─ dayFoldersGrouped(/.../Radios/Bogota, 0, false):
│   └─ subfolders = [CARACOL, BLURADIO, LA_W, MELODIA, ...]
│   └─ for each: /.../Radios/Bogota/<sub>/18072026/ existe?
│   └─ resultado: ~20 paths
│
├─ Por cada carpeta:
│   ├─ /Radios/Bogota/CARACOL/18072026 → no excluida → escanea ✓
│   ├─ /Radios/Bogota/BLURADIO/18072026 → no excluida → escanea ✓
│   ├─ /Radios/Bogota/LA_W/18072026 → EXCLUIDA → SKIP ✓
│   └─ /Radios/Bogota/MELODIA/18072026 → no excluida → escanea ✓
│
└─ Por cada archivo:
   ├─ absolute_path = /Radios/Bogota/CARACOL/18072026/caracolradio_*.mp3
   ├─ findOwnerByAbsolutePath → null (nadie la tiene)
   └─ crear File + Transcription normalmente


scanStorage(storage 63 "La W Bogota")
├─ basePath = /.../Radios/Bogota/LA_W
├─ folder_layout = 'flat' (default)
├─ allow_parent_overlap = false
│
├─ computeExcludedSubpaths(63): vacío (ningún storage es descendiente)
├─ dayFolders(/.../Radios/Bogota/LA_W, 0):
│   └─ /.../Radios/Bogota/LA_W/18072026/ ✓ existe
│
└─ Por cada archivo:
   ├─ absolute_path = /.../Radios/Bogota/LA_W/18072026/wradio_*.mp3
   ├─ findOwnerByAbsolutePath:
   │   └─ storage 47 tiene este absolute_path (registrado previamente)
   │   └─ DEVUELVE StorageProvider 47
   ├─ SKIP — log "dueño: storage 47"
   └─ NO crear File, NO crear Transcription
```

### 2.4 Casos borde cubiertos

| Caso | Comportamiento |
|------|----------------|
| Storage padre habilitado, hijo deshabilitado | Padre escanea todo (incluyendo dir del hijo deshabilitado) |
| Ambos padre e hijo habilitados | Hijo gana su subdir; padre escanea el resto |
| Padre con `allow_parent_overlap=true` | Padre NO excluye subdirs de hijos (escape hatch para casos raros) |
| Hijo con `allow_parent_overlap=true` | No cambia comportamiento del hijo, solo afecta al padre |
| Archivo físico ya registrado bajo otro storage | `findOwnerByAbsolutePath` lo detecta y omite |
| Dos storages con `base_path` idéntico | Cada uno con su propio path relativo; el orden de llegada define dueño. Documentado como anti-patrón. |
| Storage sin la columna `folder_layout` (estado previo a migración) | Fallback a `'flat'` por el operador `??` |

---

## 3. Eliminación de `transcription_priority`

### 3.1 Modelo

```php
// app/app/Models/StorageProvider.php
protected $fillable = [
    'name', 'type', 'config', 'base_path',
    'enabled', 'is_accessible', 'last_checked_at',
    'transcription_enabled',
    'folder_layout', 'allow_parent_overlap',  // nuevos
    // 'transcription_priority'             ← REMOVIDO
];
```

### 3.2 Job

```php
// app/app/Jobs/ConvertAndTranscribeJob.php

// ANTES:
public static function calculatePriority(int $storagePriority, bool $isToday, bool $isManual): int
public static function dispatchWithPriority(int $fileId, bool $generateAlerts = true, int $priority = 0)

// DESPUÉS:
public static function dispatch(int $fileId, bool $generateAlerts = true)
{
    $instance = new self($fileId, $generateAlerts);
    $instance->onQueue('transcription');  // una sola cola
    return \dispatch($instance);
}
```

### 3.3 Comando de scan

```php
// app/app/Console/Commands/ScanAndSubmitCommand.php

// ANTES:
$priority = ConvertAndTranscribeJob::calculatePriority(
    (int) ($storage->transcription_priority ?? 0),
    true,
    false
);
ConvertAndTranscribeJob::dispatchWithPriority($tx->file_id, (bool) $tx->generate_alerts, $priority);

// DESPUÉS:
ConvertAndTranscribeJob::dispatch($tx->file_id, (bool) $tx->generate_alerts);
```

### 3.4 Controller

```php
// app/app/Http/Controllers/Ia/ApiTranscriptorController.php

// REMOVER de $storages:
->select(['id', 'name', 'type', 'transcription_enabled', 'transcription_priority'])
//                ↑ quitar este

// REMOVER orderByDesc:
->orderByDesc('transcription_priority')

// REMOVER:
$storagePriorityCache = StorageProvider::pluck('transcription_priority', 'id');

// REMOVER validación (línea 334):
'transcription_priority' => 'nullable|integer|min:0',

// REMOVER store (líneas 341-342):
if ($request->has('transcription_priority')) {
    $data['transcription_priority'] = (int) $request->input('transcription_priority');
}

// REMOVER del return (línea 347):
return response()->json($storage->only(['id', 'name', 'transcription_enabled', 'transcription_priority']));
//                                                              ↑ quitar este
```

### 3.5 UI

```html
<!-- app/resources/views/ia/api-transcriptor/index.blade.php -->

<!-- REMOVER línea 159:
     <select x-model.number="s.transcription_priority" @change="savePriority(s)"> ... </select>
-->

<!-- REMOVER líneas 1036-1037:
     <span :class="s.priority > 0 ? 'bg-brand-100 text-brand-700' : 'bg-slate-200 text-slate-500'"
           x-text="'P' + s.priority"></span>
-->

<!-- REMOVER método Alpine.js savePriority(s)
     REMOVER transcription_priority del body en líneas 2036 y 2046
-->
```

### 3.6 Diagnóstico

```php
// app/app/Console/Commands/DiagnosePendingTranscriptionsCommand.php

// REMOVER:
->pluck('transcription_priority', 'id')
```

### 3.7 Supervisor

```ini
# /etc/supervisor/conf.d/tcloud-transcription-worker.conf

# ANTES:
command=... queue:work redis --queue=transcription-high,transcription-medium,transcription-low ...

# DESPUÉS:
command=... queue:work redis --queue=transcription ...
```

---

## 4. Buscadores manuales — cobertura heredada

Estos endpoints del controller **no requieren cambios** porque delegan a `DiskScannerService::scanStorage()`:

| Método | Línea aprox. | Acción |
|--------|--------------|--------|
| `scanStorage()` | ~180 | Escanea un storage completo (sin --days, --all) |
| `processFolder()` | ~200 | Procesa una carpeta específica |
| `processDay()` | ~220 | Procesa el día actual |
| `processBatch()` | ~240 | Lanza scan-and-submit en background (nohup) |

El fix en `DiskScannerService` se propaga automáticamente. La sección "Impact" del proposal lo documenta explícitamente para que quede claro que el alcance NO incluye reescribir esos endpoints.

**Verificación post-cambio**: ejecutar manualmente `php artisan transcription:scan-and-submit` con `47 + 63` habilitados y confirmar:
- Emisoras 01 descubre archivos nuevos en `CARACOL/18072026`, `MELODIA/18072026`, etc.
- LA_W no aparece en los candidatos de 47
- LA_W sí aparece en los candidatos de 63 pero son omitidos por `findOwnerByAbsolutePath` (los 4,580 archivos ya están bajo storage 47)

---

## 5. Estrategia de despliegue

1. **Deploy del código** (sin migración): el código nuevo respeta los defaults (`flat`, `allow_parent_overlap=false`). Los storages existentes funcionan igual que antes.
2. **Migración** (1 minuto):
   ```bash
   php artisan migrate
   ```
   Esto añade las columnas, hace seed de storage 47 y 49 a `grouped_by_subfolder`, y dropea `transcription_priority`.
3. **Reinicio supervisor**:
   ```bash
   sudo supervisorctl restart tcloud-transcription-worker
   ```
4. **Verificación**:
   ```bash
   # Confirmar dedup en logs
   tail -f storage/logs/laravel.log | grep "DiskScanner"
   # Confirmar que ningún archivo nuevo de storage 47 se duplicó bajo 63
   PGPASSWORD=... psql -c "
     SELECT sp.name, COUNT(f.id) AS files, COUNT(t.id) AS transcriptions
     FROM storage_providers sp
     LEFT JOIN files f ON f.storage_provider_id = sp.id
     LEFT JOIN transcriptions t ON t.file_id = f.id
     WHERE sp.id IN (47, 63) AND f.is_folder = false
     GROUP BY sp.name;"
   ```

---

## 6. Riesgos y mitigaciones

| Riesgo | Mitigación |
|--------|-----------|
| Migración elimina columna `transcription_priority` mientras UI todavía la referencia | Migración + deploy atómico. Deploy primero sin migración, después migrate, después limpiar UI. **O** dividir en 2 cambios (1 = DB+code, 2 = UI cleanup). Recomendado atómico. |
| `findOwnerByAbsolutePath` no encuentra el dueño por diferencia de 1 char en path (e.g., trailing slash) | Normalizar con `rtrim($basePath, '/')` y `ltrim($relative, '/')`. Validado en código. |
| `dayFoldersGrouped` itera muchos subfolders y es lento | Para 20-30 emisoras × 1 día = 20-30 stats. Bajo costo. Si crece, se añade cache de short-circuit por subfolder. |
| Storages emisoras futuros se agregan como `flat` por default | Documentar en UI de creación de storage que para emisoras consolidadas se debe seleccionar `grouped_by_subfolder`. (No es alcance de este change, solo nota para change futuro.) |
| Re-habilitar storage 63 después de deshabilitarlo: se re-escanean archivos que ya están bajo 47 | `findOwnerByAbsolutePath` los omite. Sin duplicados. |
| Retroactivo: los 4,580 archivos LA_W existentes bajo 47 no se "migran" a 63 | Decisión confirmada del usuario: status quo. Si en el futuro se requiere reasignar, change aparte. |