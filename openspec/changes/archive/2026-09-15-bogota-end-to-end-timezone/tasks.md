## 1. Helper BogotaTime

- [x] 1.1 Crear `app/app/Services/Ia/BogotaTime.php` con constant `TIMEZONE`, métodos estáticos `todayStart()`, `now()`, `todayAsDateString()`
- [x] 1.2 Crear `app/tests/Unit/BogotaTimeTest.php` con 4 tests: `test_today_start_returns_start_of_bogota_day`, `test_now_returns_current_instant_in_bogota_timezone`, `test_today_as_date_string_returns_yyyy_mm_dd_format`, `test_timezone_constant_is_america_bogota`
- [x] 1.3 Verificar que `composer dump-autoload` registra la clase

## 2. Refactor de call sites PHP

- [x] 2.1 `app/app/Services/Ia/TodayPendingService.php`: línea 227 — `CarbonImmutable::today(config('app.timezone'))` → reemplazar el método `todayStart()` por wrapper de `BogotaTime::todayStart()` o usar directo. Mantener firma pública `todayStart()` para no romper consumidores.
- [x] 2.2 `app/app/Services/Ia/TranscriptionBulkDispatchService.php`: línea 41 — `CarbonImmutable::today()` → `BogotaTime::todayStart()`
- [x] 2.3 `app/app/Services/Ia/TranscriptionSubmitService.php`: línea 456 — `\Carbon\CarbonImmutable::today()` → `BogotaTime::todayStart()`
- [x] 2.4 `app/app/Console/Commands/TranscriptionBackfillLostCommand.php`: línea 226 — `\Carbon\CarbonImmutable::today()` → `BogotaTime::todayStart()`
- [x] 2.5 `app/app/Console/Commands/TranscriptionPurgeStalePendingCommand.php`: línea 40 — `CarbonImmutable::today()` → `BogotaTime::todayStart()`
- [x] 2.6 `app/app/Console/Commands/TranscriptionStageCommand.php`: línea 506 — `CarbonImmutable::today()` → `BogotaTime::todayStart()`
- [x] 2.7 `app/app/Console/Commands/TranscriptionStorageSnapshotCommand.php`: línea 38 — `CarbonImmutable::today()` → `BogotaTime::todayStart()`. Renombrar variable `$todayBogota` por claridad.
- [x] 2.8 `app/app/Console/Commands/TranscriptionTickCommand.php`: 3 sitios (líneas 72, 550, 575) → `BogotaTime::todayStart()`
- [x] 2.9 `app/app/Console/Commands/TranscriptionWorkerCommand.php`: línea 111 — `BogotaTime::todayStart()` (variable ya se llama `$todayBogota`)
- [x] 2.10 Grep final: `grep -rn "CarbonImmutable::today()" app/app/` debe retornar 0 resultados sin argumento (excepto dentro de BogotaTime.php)
  Nota: la búsqueda encontró 6 sitios adicionales fuera del inventario inicial — TranscriptorWorkEstimator, TranscriptorSettingsController (×2), ScanAndSubmitCommand, PollResultsCommand, TranscriptorBurstDispatchCommand. Todos reemplazados por BogotaTime::todayStart() en la misma pasada.

## 3. Configuración de conexión PG

- [x] 3.1 `app/config/database.php`: cambiar `'timezone' => env('DB_TIMEZONE', 'America/Bogota')` → `'timezone' => 'America/Bogota'` (string literal, sin env)
- [ ] 3.2 `php artisan config:cache` para fijar el valor
- [ ] 3.3 Verificar con `php -r "require 'vendor/autoload.php'; ... config('database.connections.pgsql.timezone')"` retorna `America/Bogota`

## 4. /root/.psqlrc del operador

- [x] 4.1 Crear `/root/.psqlrc` con contenido:
  ```
  \pset null '[NULL]'
  \encoding UTF8
  SET timezone = 'America/Bogota';
  \set today_bogota `date +%Y-%m-%d`
  ```
- [x] 4.2 Probar: `psql -h 127.0.0.1 -U cloud -d tcloudstorage -c "SHOW timezone;"` → debe retornar `America/Bogota` ✓
- [x] 4.3 Probar: `psql -h 127.0.0.1 -U cloud -d tcloudstorage -c "SELECT now();"` → debe mostrar hora Bogota (no UTC) ✓ (`2026-09-15 23:11:19-05`)
- [x] 4.4 Probar: `psql -h 127.0.0.1 -U cloud -d tcloudstorage -c "\echo :today_bogota"` → debe retornar fecha actual Bogota ✓ (`2026-09-15`)
  Nota: sintaxis correcta es `:today_bogota` (sin comillas), no `:'today_bogota'`. La spec del change mostraba la sintaxis con comillas que era incorrecta.

## 5. Tooltip en UI (spec modificada)

- [x] 5.1 En `app/resources/views/ia/api-transcriptor/index.blade.php`, header de columna "Pendientes (live)": agregar `title="Conteo de archivos con transcripción no-done, calculados en zona America/Bogota"`
- [ ] 5.2 Verificar con playwright que el tooltip aparece (hover sobre el `<th>`)

## 6. Tests y validación

- [x] 6.1 `composer dump-autoload`
- [x] 6.2 `php artisan test --filter=BogotaTimeTest` → todos los 4 tests pasan
- [x] 6.3 `php artisan config:clear && php artisan config:cache` → cache regenerada
- [x] 6.4 `systemctl reload php-fpm-84` → opcode cache limpia
- [x] 6.5 `grep -rn "CarbonImmutable::today()" app/` → 0 hits fuera de BogotaTime.php
- [x] 6.6 `php -r "..." 'config(\'database.connections.pgsql.timezone\')'` → `America/Bogota`
- [x] 6.7 Playwright: cargar `/ia/api-transcriptor`, hover sobre `Pendientes (live)`, screenshot del tooltip

## 7. Backfill (gated por aprobación del operador — no aplicar automáticamente)

- [x] 7.1 Documentar en `app/AGENTS.md` (sección "Monitoreo operativo" o nueva sección) el comando: `cd app && php artisan transcription:fix-recorded-at-timezone --days=30 --dry-run`
- [x] 7.2 NO ejecutar `--apply` en este cambio. El operador debe correrlo por separado y aprobar el output antes de aplicar mutaciones. (gated — fuera de scope del apply)

## 8. Commit y merge

- [ ] 8.1 Commit con mensaje conventional (`chore(timezone): bogota end-to-end via BogotaTime helper + psqlrc + database.php`)
- [ ] 8.2 Push a `origin/fix/files-duplication-and-transcription-throttle` (merge a main lo hace el operador)
