# Tasks: Visibilidad real de progreso en Re-aplicar correcciones

## 1. Servicio: conteo real + hoist de pares

- [ ] En `app/app/Services/Ia/CorrectionService.php`:
  - grep para confirmar que los únicos callers del callback de `applyRetroactively()` son `CorrectionsApplyRunCommand` (y preview/dry-run si aplica).
  - Agregar contador `$processed` acumulado dentro del loop `chunkById`; cambiar la invocación del callback a `$progressCb($processed, $total, $updated, $chunk->last()->id)`.
  - Extraer la conversión de correcciones → pares ordenados (hoy dentro de `applyText()`, líneas ~90-110) a un método `preparePairs(Collection $corrections): array`.
  - Agregar `applyTextWithPairs(string $text, array $pairs): string` con la lógica de reemplazo actual; `applyText()` queda como wrapper (prepare + delegate) para no romper sus otros callers.
  - En `applyRetroactively()` (y el loop de preview de línea ~57 si aplica el mismo hoist): llamar `preparePairs()` UNA vez antes de `chunkById` y usar `applyTextWithPairs()` dentro del loop.
- [ ] `php -l` validar.

## 2. Comando: progreso por chunk + limpieza de puntero

- [ ] En `app/app/Console/Commands/CorrectionsApplyRunCommand.php`:
  - Actualizar el callback a la nueva firma `fn($processed, $total, $updatedSoFar, $lastId)`.
  - Escribir por chunk en cache: `processed`, `total`, `updated` (parcial), `progress` (lastId), `last_progress_at` (ISO8601).
  - Al terminar (tanto `done` como `error`): `Cache::forget('corrections_apply:active')`.
- [ ] `php -l` validar.
- [ ] Verificar `php artisan list | grep corrections:apply-run` registra.

## 3. Controller: anti-duplicado + endpoint de activa + fix redirect

- [ ] En `app/app/Http/Controllers/Ia/CorreccionesController.php`:
  - `applyRetroactive()`: antes de crear el run, leer `Cache::get('corrections_apply:active')`; si apunta a run sano (`queued`/`running` no huérfano) → responder 409 `{error, runId, status}`. Regla de huérfano: run inexistente, `done`/`error`, o `queued` >5 min sin `started_at`.
  - Agregar `queued_at` al estado inicial del run.
  - Crear el puntero con `Cache::add('corrections_apply:active', ['runId' => $runId], TTL)` (SET NX atómico) antes de `execBackground`.
  - Quitar `> /tmp/kilo_artisan_apply.log 2>&1` del `$cmd` (el wrapper `RunsBackgroundCommands::execBackground()` ya redirige a `kilo_artisan_bg.log`; el redirect doble deja el primer log vacío).
  - Agregar `activeApplyRun()`: 204 si no hay puntero o el run terminó; 200 con `{runId, ...state}` si hay corrida activa.
- [ ] En `app/routes/web.php`: agregar `Route::get('/correcciones/apply-retroactive-active', [CorreccionesController::class, 'activeApplyRun'])` junto a las rutas existentes de apply-retroactive (líneas ~220-221).
- [ ] `php -l` validar ambos archivos.

## 4. UI: barra honesta, re-attach, stuck detection

- [ ] En `app/resources/views/ia/correcciones/index.blade.php`:
  - `pollRun()`: usar `d.processed ?? d.updated ?? 0` para barra y texto de progreso.
  - Mapa de labels ES: `{queued: 'En cola…', running: 'Procesando…', done: 'Terminada', error: 'Falló'}` para `runStatusText`.
  - Stuck detection en cada poll: si `status==='running'` y `Date.now() - Date.parse(d.last_progress_at) > 180000` → mostrar aviso ámbar "Sin avances desde las HH:MM — la corrida pudo haberse detenido"; ocultarlo cuando el heartbeat se renueve. Eliminar el `runStuckTimer` declarado y nunca usado.
  - `runApply()`: manejar 409 → tomar `d.runId` y re-adjuntar (mismo camino que re-attach) en vez de mostrar error.
  - Re-attach en `init()`: `fetch('/ia/correcciones/apply-retroactive-active')`; si 200, poblar `runId`+estado, arrancar intervalo de polling y mostrar banner persistente en el header del módulo ("Re-aplicar en curso: X% — ver") que abre el modal; si 204, nada.
  - Banner HTML: visible aunque el modal esté cerrado; se oculta solo cuando la corrida llega a `done`/`error`.
- [ ] Verificar sintaxis del blade: `php artisan view:clear` + cargar `/ia/correcciones` sin errores 500.

## 5. Verificación end-to-end (producción local)

- [ ] Lanzar Re-aplicar scope "último día" desde la UI → la barra muestra % creciente dentro del primer minuto (antes: 0% eterno). Verificar en Redis (`redis-cli -n 1 get "*corrections_apply:correction_apply_*"`) que `processed` crece por chunk y `last_progress_at` se renueva.
- [ ] Recargar la página a mitad de corrida → banner/barra reaparecen solos con el % actual.
- [ ] Intentar lanzar otra corrida durante la primera → 409 + re-attach; `ps aux | grep apply-run` muestra UN solo proceso.
- [ ] `kill -9` al PID del comando a mitad de corrida → a los ~3 min aparece el aviso de estancada.
- [ ] Corrida completa hasta `done` → puntero limpiado (POST posterior responde 202), UI hace reload y muestra segmentos actualizados.
- [ ] `/tmp/kilo_artisan_bg.log` contiene la salida del comando (ya no vacío).
- [ ] Perf smoke: tiempo por chunk visiblemente menor vs. corrida previa (hoist de pares); sin cambios en el resultado (spot-check de segments corregidos igual que antes).

## 6. Archivar

- [ ] Mover el change a `openspec/changes/archive/2026-08-01-corrections-apply-progress-visibility/` (nombre ya date-prefixed: mover tal cual, sin duplicar fecha — ver corrección `openspec_archive_flow`).
- [ ] Aplicar el delta al spec `openspec/specs/transcription-corrections/spec.md` (1 MODIFIED + 3 ADDED).
