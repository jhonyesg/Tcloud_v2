## Context

El servicio `app/app/Services/StorageSyncService.php` invoca el facade `DB` desde `isFileLinked()` (líneas 511, 516, 521, 527) pero no tiene `use Illuminate\Support\Facades\DB;` en su cabecera. PHP resuelve el símbolo en el namespace actual y lanza `Class "App\Services\DB" not found`. La excepción rompe `doSyncFolder()` en cualquier folder que tenga al menos una entrada, devuelve HTTP 500 desde `FileController@index` (línea 142) y produce el toast `No se pudo cargar la carpeta (500)` que ve el usuario en el explorador.

La causa raíz fue el commit `1f710a7` ("chore(acumulado)…"): reescribió `isFileLinked()` para chequear tres tablas (`transcriptions`, `shares`, `media_edit_jobs.source_file_id`) usando `DB::selectOne(...)` y se coló sin el import. Ningún otro archivo del acumulado debe compartir el mismo defecto, pero se verificará por barrido.

Restricciones que pesan sobre el approach:
- Mínimo blast radius: el fix debe ser de una línea, no un refactor.
- No tocar la lógica: `isFileLinked()` ya tiene la consulta correcta (validada contra la migración `2026_09_04_220000_normalize_storage_schema` que añade `media_edit_jobs.source_file_id`).
- Tests existentes: la suite usa `tests/harness_*.php` contra Postgres + Redis reales. No agregamos un test pesado de Laravel/PHPUnit para una línea: el harness es la convention del proyecto (ver `AGENTS.md` y `tests/harness_sessions_users_coherence.php`).
- Proteger contra el mismo error en otros servicios del acumulado: barrido preventivo.

## Goals / Non-Goals

**Goals:**
- Restaurar la ruta `FileController@index` para que devuelva HTTP 200 en folders con archivos.
- Que `grep "StorageSyncService" app/storage/logs/laravel.log` deje de mostrar `Class "App\Services\DB" not found`.
- Detector de regresión ejecutable que falle si el import vuelve a faltar.

**Non-Goals:**
- Refactor de `StorageSyncService` a Eloquent (la raw query existe a propósito para el check de `information_schema`).
- Cambiar el contrato HTTP del explorador (sigue siendo 200 + JSON con `files`).
- Crear un linter propio o un helper global de facade-imports.
- Migración de BD, cambios de UI, cambios en cron.

## Decisions

### D1. Fix puntual: añadir solo `use Illuminate\Support\Facades\DB;`

**Por qué**: la causa raíz es un import faltante. Cualquier cambio mayor (mover a `File::query()`, refactor a un helper, mover a un trait) introduce riesgo sin valor. El linter de Laravel (`php -l`) no atrapa el problema porque no es un error de sintaxis sino de resolución de símbolo.

**Alternativas consideradas**:
- Convertir a Eloquent: descartado. La query a `information_schema` no se beneficia de Eloquent y agrega hidratación innecesaria.
- Mover el check a un helper en `app/app/Support/`: descartado. Es una sola llamada en un solo método, no justifica abstracción.
- Convertir el facade en inyección explícita de `Illuminate\Database\Connection`: descartado. Rompe el patrón del resto del archivo (que ya usa `Cache` y `Log` como facades).

### D2. Barrido preventivo por otros archivos con el mismo defecto

**Por qué**: el acumulado `1f710a7` tocó muchos archivos a la vez. Si el revisor pasó por alto un import en `StorageSyncService`, pudo pasar por alto otros. Un grep de control barato elimina el riesgo de un segundo PR de emergencia idéntico.

**Estrategia**:
```bash
# Para cada facade usado en app/app/Services/, verificar que su 'use' exista
for f in app/app/Services/*.php; do
  for facade in DB Cache Log Storage Mail Hash Config Route URL Event; do
    if grep -q "\\b${facade}::" "$f"; then
      if ! grep -q "use Illuminate\\\\Support\\\\Facades\\\\${facade}" "$f"; then
        echo "MISSING IMPORT: $f uses ${facade}:: but lacks use ...\\Facades\\${facade}"
      fi
    fi
  done
done
```

**Salida esperada**: 1 línea (`StorageSyncService.php` con `DB`). Si aparece más de un archivo, se corrigen todos en el mismo PR.

**Alternativas consideradas**:
- Usar PHPStan/Larastan: descartado, no está en el stack actual (`composer.json` del proyecto) y meterlo solo para este fix es desproporcionado.
- Confiar en el IDE del revisor: descartado, ya falló una vez.

### D3. Test de regresión como harness PHP, no PHPUnit

**Por qué**: la convención del proyecto (ver `AGENTS.md` y los `harness_*.php` archivados) es correr scripts PHP contra Postgres + Redis reales para validar integraciones que tocan múltiples servicios. Crear un test PHPUnit para una sola línea es inconsistente con el patrón existente.

**Approach**: nuevo `tests/harness_storage_sync_is_file_linked.php` que:
1. Crea un storage temporal + 1 archivo dummy.
2. Llama `app(StorageSyncService::class)->syncFolderWithReport($storage, $parentId, $userId, false)`.
3. Assert: no excepción `Class "App\Services\DB" not found`; array `$report['files']` contiene el archivo.
4. Limpia el archivo y el storage al final (`finally`).

**Alternativas consideradas**:
- Feature test PHPUnit con `actingAs()`: válido pero más lento y no aprovecha el setup actual de harness. Se deja como follow-up si el equipo lo pide.
- Test solo unitario mockeando `DB::selectOne()`: descartado, no detecta el bug (mockearía justo el facade problemático).

### D4. Sin migración, sin cambios de config

**Por qué**: el fix no toca esquema ni configuración de Laravel. No se necesita ninguna de las dos. El usuario solo necesita un `git pull` + `composer dump-autoload` (no estrictamente necesario porque solo se añadió un `use`, pero inofensivo).

## Risks / Trade-offs

- **[Riesgo] Que el barrido detecte más archivos con el mismo defecto y se infle el scope** → **Mitigación**: si el barrido encuentra N>1 archivos, se mantienen todos en el mismo PR pero se documenta cada uno en `tasks.md` con su línea exacta. Si N≥5, se reabre el debate como cambio separado antes de mergear.
- **[Riesgo] Que el test de regresión mute la BD de pruebas** → **Mitigación**: el harness usa el connection `pgsql` real pero limpia al final (`finally`) y los datos creados son `StorageProvider`/`File` con IDs UUID descartables; sigue el patrón de `tests/harness_sessions_users_coherence.php`.
- **[Riesgo] Que el import choque con un `DB` ya declarado en el namespace global** → **Mitigación**: improbable (Laravel no define `DB` en el global namespace), pero `php -l` lo detectaría si pasara.
- **[Trade-off] No agregamos Larastan/PHPStan para prevenir a futuro** → Aceptado: sería el fix correcto a largo plazo, pero queda fuera de scope. Se anota como follow-up en el final de `tasks.md` (no como tarea).
- **[Trade-off] El fix depende de que el revisor humano no vuelva a colar el PR sin import** → Aceptado: el harness atrapa el caso específico de `isFileLinked()`. La garantía total requiere linter; queda como follow-up.

## Migration Plan

### Deploy
1. Merge del PR (un único commit `fix(storage): add missing Illuminate\\Support\\Facades\\DB import in StorageSyncService`).
2. `git pull` en el servidor.
3. `composer dump-autoload` (opcional, el cambio no afecta el autoloader de clases).
4. `php artisan config:clear` (precaución, aunque no haya cambios de config).
5. Verificación manual: navegar a `https://cloud.mediaserver.com.co/files/<storage_id>` en el navegador; el toast 500 no debe aparecer; el listado se debe renderizar.
6. Verificación en logs: `grep "Class \"App\\\\Services\\\\DB\" not found" app/storage/logs/laravel.log` debe devolver 0 entradas nuevas desde el deploy.

### Rollback
- `git revert <commit-hash>` en el servidor. Como el fix solo añade un `use`, el rollback devuelve la app al estado roto actual (HTTP 500), que ya estaba en producción. No hay daño colateral peor que el estado previo.
- No requiere migraciones hacia atrás ni limpieza de caché especial.

### Post-deploy monitoring (24h)
```bash
# 1. Cero recurrencias del error en logs
grep -c "Class \"App\\\\Services\\\\DB\" not found" app/storage/logs/laravel.log
# (debe ser igual o menor que el conteo pre-deploy; idealmente 0 nuevos)

# 2. Las requests a FileController@index responden 200
grep "FileController.*index" app/storage/logs/laravel.log | grep -v "500" | wc -l

# 3. El cron de sync (si existe) sigue trabajando
grep "doSyncFolder" app/storage/logs/laravel.log | tail -20
```

## Open Questions

Ninguna. El fix es determinístico y el barrido preventivo cierra la duda sobre alcance.
