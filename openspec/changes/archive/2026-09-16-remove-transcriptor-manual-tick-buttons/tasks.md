## 0. Prerrequisito: estabilizar el working tree

- [x] 0.1 Verificar con `git status --short` que el working tree contiene los cambios sin commitear esperados. **Hallazgo del apply**: contiene **tres** bloques distintos, no uno:
  - **(a) Hotfix BogotaTime** (3 líneas `use`, urgente): `TranscriptionTickCommand.php`, `TranscriptionBackfillLostCommand.php`, `TranscriptionPurgeStalePendingCommand.php`. HEAD (`ff29aab`) los dejó rotos: usan `BogotaTime::` sin import → `Class "App\Console\Commands\BogotaTime" not found` en cada corrida del tick.
  - **(b) Feature "Procesar históricos"**: `ApiTranscriptorController.php`, `routes/web.php`, `_settings-tab.blade.php`, `index.blade.php`, `AGENTS.md`, y el sin-trackear `app/app/Services/Ia/TranscriptorWorkEstimator.php`.
  - **(c) Basura de depuración** (queda fuera de todo commit): `app/scan_one.php`, `app/tests/e2e/screenshots/pzm-*.png`, `app/tests/e2e/validate-custom-processing-*.spec.mjs`, `app/tests/e2e/screenshots/report-config-ui-*.json`.
- [x] 0.2 **Commit del hotfix, aislado y primero** — solo los 3 comandos:
  ```bash
  git add app/app/Console/Commands/TranscriptionTickCommand.php \
          app/app/Console/Commands/TranscriptionBackfillLostCommand.php \
          app/app/Console/Commands/TranscriptionPurgeStalePendingCommand.php
  git commit -m "fix(timezone): add missing BogotaTime import in 3 console commands"
  ```
  Verificar que el import muerto `use Carbon\CarbonImmutable;` de `TranscriptionTickCommand.php` **no** rompe nada (PHP tolera imports sin usar; no es tarea de este change limpiarlo). Registrar el hash.
- [x] 0.3 **Commit de la feature "Procesar históricos"**, segundo y separado:
  ```bash
  git add app/app/Http/Controllers/Ia/ApiTranscriptorController.php \
          app/app/Services/Ia/TranscriptorWorkEstimator.php \
          app/routes/web.php \
          app/resources/views/ia/api-transcriptor/_settings-tab.blade.php \
          app/resources/views/ia/api-transcriptor/index.blade.php \
          AGENTS.md
  git commit -m "feat(api-transcriptor): procesamiento personalizado de históricos"
  ```
  Registrar el hash: es la base sobre la que se aplica la eliminación.
- [x] 0.4 Confirmar con `git status --short` que solo queda sin trackear la basura de depuración del punto 0.1(c), y que no hay archivos de este change mezclados.

> Este grupo no modifica código de este change; existe para que el diff de la eliminación quede aislado y el `git revert` del paso 4 sea limpio. Si al llegar acá el árbol ya está limpio (los tres commits hechos), marcar 0.1-0.4 como completadas sin acción.

## 1. Backend: eliminar el endpoint y su lanzador

- [x] 1.1 En `app/app/Http/Controllers/Ia/TranscriptorSettingsController.php`, eliminar el método `runTick(Request $request)` completo (bloque de comentario doc + cuerpo, líneas 128-172). No tocar `index()`, `update()` ni `reset()`.
- [x] 1.2 En el mismo archivo, eliminar `use App\Http\Controllers\Concerns\RunsBackgroundCommands;` (línea 5) y la línea `use RunsBackgroundCommands;` (línea 29) del cuerpo de la clase. Verificar que no quede ninguna otra referencia a `execBackground`, `resolvePhpCli` o `generateRunId` en el archivo.
- [x] 1.3 Confirmar que ya no quedan imports huérfanos en el controller (`Log` y `Cache` siguen usándose en `update`/`reset`/`runtime`) y correr `php -l app/app/Http/Controllers/Ia/TranscriptorSettingsController.php`.
- [x] 1.4 En `app/routes/web.php`, eliminar la línea 211 `Route::post('/api-transcriptor/settings/run-tick', ...)`. Conservar las tres rutas vecinas de settings (`GET /settings`, `POST /settings`, `POST /settings/reset`) y las tres de `scan/*`.
- [x] 1.5 Limpiar la cache de rutas: `cd app && php artisan route:clear && php artisan route:cache`. Confirmar con `php artisan route:list --path=api-transcriptor/settings` que `run-tick` ya no aparece y que las rutas de settings y scan siguen registradas.

## 2. Frontend: quitar los dos botones y su handler

- [x] 2.1 En `app/resources/views/ia/api-transcriptor/_settings-tab.blade.php`, dentro del `<div data-tour="cfg-run">`, eliminar los dos `<button>` de "Simular" (`@click="runTick(true)"`) y "Ejecutar ahora" (`@click="runTick(false)"`). **Conservar el `<div data-tour="cfg-run">` completo** y el botón "Procesar históricos" (`@click="openPz()"`) que vive dentro.
- [x] 2.2 En `app/resources/views/ia/api-transcriptor/index.blade.php`, eliminar el método Alpine `async runTick(dryRun)` del objeto `x-data`. Verificar con grep que no quede ningún `runTick` en la vista ni en otros assets.
- [x] 2.3 Verificar que `cfgSaving` sigue teniendo usos legítimos (`togglePause`, `saveConfig`, `loadConfig`) y que no quedó estado huérfano. No introducir estado nuevo.
- [x] 2.4 Purgar las vistas compiladas para descartar el Blade cacheado: `cd app && php artisan view:clear`.

## 3. Limpieza operativa y verificación manual

- [x] 3.1 Borrar el log huérfano: `rm -f app/storage/logs/transcription-tick-manual.log` (verificado: ningún componente lo lee; solo lo referenciaba `runTick`, ya eliminado).
- [x] 3.2 Verificación en navegador con sesión admin, en `/ia/api-transcriptor` tab Configuración: la tarjeta "Tarea programada" muestra **un solo** botón de acción ("Procesar históricos"), y conserva `tick_last_run`, la barra de cola, workers y conteos por estado.
- [x] 3.3 Confirmar el criterio de UI del proyecto: consola del navegador **vacía** durante la carga del tab (sin `console.error`, `console.warn` ni `alert()`), y sin peticiones 404 por `run-tick` en la pestaña Network.
- [x] 3.4 Verificar que abrir y cancelar el modal "Procesar históricos" sigue funcionando (`openPz()` / `closePz()`), y que `POST /scan/estimate` responde con la estimación de solo lectura para alcance "Hoy".
- [x] 3.5 Confirmar que el polling de 10 s sigue actualizando `cfgRuntime` (el valor `tick_last_run` cambia sin recargar la página).
- [x] 3.6 Verificación de la ruta eliminada: `curl -s -o /dev/null -w '%{http_code}\n' -X POST https://cloud.mediaserver.com.co/ia/api-transcriptor/settings/run-tick` con sesión admin debe devolver `404`.

## 4. Cierre

- [x] 4.1 Correr los harnesses de regresión que tocan el módulo: `cd app && php tests/harness_dashboard_partials.php`. Si existe un harness del transcriptor relevante, correrlo también.
- [x] 4.2 Commit único con mensaje `refactor(api-transcriptor): remove manual tick buttons and run-tick endpoint`, que incluya la eliminación y la actualización de `openspec/specs/transcription-orchestrator-runtime/spec.md` (el delta se promueve al archivar el change).
- [x] 4.3 `systemctl reload php84-php-fpm` para liberar el opcode cache del controller modificado.
- [x] 4.4 Archivar el change cuando la verificación en producción esté confirmada, para aplicar el delta REMOVED sobre el spec vivo.

> **Nota de alcance**: este change NO toca `TranscriptionTickCommand`, el scheduler, la cola PG, el stager ni el poller. El flag `--dry-run` del comando se conserva (`cd app && php artisan transcription:tick --dry-run` sigue siendo la vía de inspección). No hay migración de base de datos.
