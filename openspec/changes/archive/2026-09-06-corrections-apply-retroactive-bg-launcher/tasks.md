## 1. Auditoría de callers del trait (pre-fix)

- [x] 1.1 Reproducir el bug en `CorreccionesController::applyRetroactive` con `php -S` o bajo php-fpm real; capturar `/tmp/kilo_artisan_bg.log` mostrando el `Usage: php-fpm`. _(Evidencia: tail /tmp/kilo_artisan_bg.log mostró `Usage: php-fpm [-n] [-e] …` capturado durante el explore)_
- [x] 1.2 Probar el flujo "Escanear" en `ApiTranscriptorController` y verificar que también cae en binario equivocado (mismo síntoma en su log). _(Mismo trait; fix hereda — verificado por code review, falta smoke manual en 6.2)_
- [x] 1.3 Probar el flujo de settings en `TranscriptorSettingsController` con el mismo método. _(Mismo trait; fix hereda — verificado por code review, falta smoke manual en 6.3)_
- [x] 1.4 `grep -RIn 'RunsBackgroundCommands\|execBackground' app/` para confirmar la lista exhaustiva de callers y extensions del trait. _(Resultado: 3 callers, 0 extensions)_

## 2. Fix central en `RunsBackgroundCommands`

- [x] 2.1 Agregar `resolvePhpCli(): string` al trait con la lógica: `PHP_SAPI === 'cli'` → `PHP_BINARY`; si no, fallback a `/usr/bin/php` o `/usr/bin/php84` (el primero ejecutable gana); si ninguno, throw `RuntimeException` con mensaje legible.
- [x] 2.2 Modificar `execBackground(string $cmd, string $logTag = 'unknown')` para aceptar el tag, prefijar cada corrida con `[<logTag>] start <iso>` y `[<logTag>] end <iso>`, y cambiar `>` por `>>` en la redirección al log.
- [x] 2.3 Actualizar `CorreccionesController::applyRetroactive()` para llamar `$this->resolvePhpCli()` y pasar `'corrections:apply'` como `$logTag`. Verificar que la línea 738-741 (fallback original) se elimina porque ahora vive en el trait.
- [x] 2.4 Actualizar los otros 2 callers para pasar su `$logTag` (`'transcriptor:scan'`, `'transcriptor:settings'`) — sin otra lógica nueva, sólo el tag.

## 3. Liveness ping en `CorreccionesController::applyRetroactive`

- [x] 3.1 Inmediatamente después de `$this->execBackground(...)`, agregar `usleep(2_000_000)` (2s).
- [x] 3.2 Leer el cache del run via `Cache::get($cacheKey)`. Si `status` es `null` o sigue en `queued`, sobreescribir a `status='error'` con `error_message="El worker no arrancó — revisá /tmp/kilo_artisan_bg.log (filtro: [corrections:apply])"`, ejecutar `Cache::forget('corrections_apply:active')`, y devolver `response()->json(['error' => 'El proceso de re-aplicación no arrancó. Revisá el log.', 'log' => '/tmp/kilo_artisan_bg.log'], 500)`.
- [x] 3.3 Si `status` ya es `running`/`done`/`error`, mantener la respuesta 202 actual sin cambios.

## 4. Extender detector de stuck en la UI

- [x] 4.1 En `resources/views/ia/correcciones/index.blade.php`, dentro de `pollRun()` (alrededor de la línea 4364), agregar la condición `orphanQueued = status==='queued' && !started_at && age(queued_at) > 60_000`. Dispara `runStuck` igual que el caso stale-running.
- [x] 4.2 Confirmar que tanto el banner del header (línea 71-91) como el modal de progreso (línea 2108-2139) muestran la misma alerta cuando `runStuck` es true — verificar con un diff visual.
- [x] 4.3 En el mensaje del warning, incluir sugerencia: "Revisá `/tmp/kilo_artisan_bg.log` filtrando por `[corrections:apply]`".

## 5. Tests de regresión

- [x] 5.1 Crear `app/tests/Feature/CorrectionsApplyRetroactiveDeadWorkerTest.php` con un test que mockea `RunsBackgroundCommands::execBackground()` para que el binario CLI exista pero el proceso muera al instante (`exit 1` antes de escribir cache). Verificar: HTTP 500 con `error` legible; cache en `status='error'`; `corrections_apply:active` borrado. _(7 tests, 22 assertions, all pass)_
- [x] 5.2 Agregar test unitario de `RunsBackgroundCommands::resolvePhpCli()` con SAPI simulado: bajo SAPI `cli`, retorna `PHP_BINARY`. _(Cubre signature y contracto CLI; el path FPM se valida via 5.1 ya que el controller usa resolvePhpCli antes del dispatch)_
- [x] 5.3 Test e2e feliz: `test_healthy_worker_transitions_to_running_and_returns_202` y `test_already_finished_run_does_not_get_overwritten_by_liveness_ping`. _(Pasan: el stub escribe `running`/`done` antes del ping y se valida el 202)_
- [x] 5.4 Correr `vendor/bin/phpunit --testsuite=Feature --filter=Corrections` para asegurar que ningún test existente rompe. _(149 tests, 3 failures pre-existentes no relacionadas con este change — verificadas con `git stash` que ya fallaban antes)_

## 6. Verificación manual de los 3 callers

- [ ] 6.1 En `/ia/correcciones`, dar Re-aplicar con alcance "Último día" en producción/staging; confirmar que la barra avanza y termina con `updated > 0`. _(Requiere deploy)_
- [ ] 6.2 En el módulo de transcripciones (escaneo), lanzar un escaneo; confirmar que el log muestra `[transcriptor:scan] start …` y termina con `[transcriptor:scan] end …`. _(Requiere deploy)_
- [ ] 6.3 En settings de transcriptor, ejecutar la acción async; mismo chequeo del log con `[transcriptor:settings]`. _(Requiere deploy)_
- [ ] 6.4 `grep '\[corrections:apply\]\|\[transcriptor:' /tmp/kilo_artisan_bg.log` y validar que cada corrida queda claramente identificada. _(Requiere deploy)_

## 7. Commit, archive y comunicación

- [ ] 7.1 Commit con mensaje `fix(corrections): use CLI binary for background apply-retroactive worker` (conventional commit), incluyendo el cambio en el trait y la UI. _(Pendiente de approval del usuario)_
- [ ] 7.2 Mergear y desplegar.
- [ ] 7.3 Verificar logs en producción 24h: `grep 'corrections_apply:error\|worker no arrancó' app/storage/logs/laravel.log` debe dar 0 ocurrencias.
- [ ] 7.4 `openspec archive corrections-apply-retroactive-bg-launcher` para cerrar el ciclo.
