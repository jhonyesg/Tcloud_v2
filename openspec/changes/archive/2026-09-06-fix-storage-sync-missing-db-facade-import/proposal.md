## Why

`StorageSyncService::isFileLinked()` (introducido/reescrito en el commit `1f710a7` — "chore(acumulado)…") usa el facade `DB` (líneas 511, 516, 521, 527) pero el archivo **no** tiene `use Illuminate\Support\Facades\DB;`. PHP resuelve `DB` dentro del namespace actual `App\Services`, lanza `Error: Class "App\Services\DB" not found` y Laravel lo traduce a HTTP 500. Resultado visible: el explorador de archivos ("Mis Archivos / 30 Television Bk" y demás storages) muestra el toast `No se pudo cargar la carpeta (500). Intenta de nuevo.` cada vez que el sync recorre al menos una entrada. El bug es 100 % reintroducible hoy y la única "regresión" que lo desactiva es volver a agregar el `use` que faltó en el merge.

## What Changes

- Agregar `use Illuminate\Support\Facades\DB;` al bloque `use` de `app/app/Services/StorageSyncService.php` para que `DB::selectOne(...)` resuelva correctamente.
- Barrido preventivo en `app/app/Services/*.php` para detectar otros archivos del acumulado `1f710a7` (o de merges previos) que usen `DB::*`, `Cache::*`, `Log::*`, `Storage::*`, `Mail::*` o cualquier facade sin su `use` correspondiente; corregir los hallazgos en el mismo PR.
- Test de regresión que ejercite el path real `FileController@index → StorageSyncService::syncFolderWithReport() → doSyncFolder() → isFileLinked()` contra un storage con al menos un archivo, y valide HTTP 200 + ausencia de la excepción `Class "App\Services\DB" not found` en logs.
- Sanity de imports: ejecutar `php -l` sobre los archivos tocados y un grep de control para confirmar que no quedan llamadas a facades sin su `use`.

## Capabilities

### New Capabilities
<!-- None: el cambio restaura el comportamiento existente. -->

### Modified Capabilities
<!-- None: ningún requirement observable cambia. -->

## Impact

- `app/app/Services/StorageSyncService.php`: añadir el `use Illuminate\Support\Facades\DB;`. Sin cambios de lógica.
- `app/app/Services/*.php`: barrido por el mismo defecto (esperado: 0 archivos adicionales con el problema, pero se verifica).
- `app/tests/Feature/` o `tests/harness_*.php`: nuevo harness o test de regresión que dispare la ruta de folder listing contra un storage real (Postgres + filesystem) y verifique 200.
- Sin cambios en BD, sin migraciones, sin cambios de UI, sin cambios de contratos públicos.
- Sin impacto en sesiones Redis (problema independiente, ya resuelto en `2026-09-05-fix-session-service-redis-db`).

## Non-goals

- No se refactoriza `StorageSyncService` ni se mueven queries a Eloquent. La consulta raw existe a propósito (chequeo barato contra `information_schema`).
- No se cambia la semántica de `isFileLinked()` (sigue marcando "linkeado" si hay fila en `transcriptions`, `shares` o `media_edit_jobs.source_file_id`).
- No se reescribe `doSyncFolder()` ni `syncFolderWithReport()`.
- No se crea un helper global de facade-imports; el fix es puntual y documentado.
- No se tocan los acumulados `bb71b9c`, `1f710a7` ni los archivos `archive/` de OpenSpec.
