## 1. Backend: service de planning y ejecución mensual

- [x] 1.1 En `app/app/Services/Ia/AvisosScanService.php`, agregar método `planMonths(array $opts): array` que:
  - Toma `$opts = ['noWindow' => true, 'force' => true, ...]`
  - Query: `SELECT min(finished_at) as min_at, max(finished_at) as max_at FROM transcriptions WHERE state = 'done'`
  - Si min/max son null → retorna `[]`
  - Si no, deriva la lista de meses `YYYY-MM` desde el primer día del mes de `min_at` hasta el último día del mes de `max_at`
  - Retorna `[['YYYY-MM', 'pending'], ...]`
- [x] 1.2 Agregar método `runMonth(string $runId, string $yearMonth, array $opts): int` que:
  - Construye `from` = primer día del mes, `to` = último día del mes (o `now()` si es el mes actual)
  - Llama `selectCandidates(['from' => $from, 'to' => $to, ...])` con limit 50
  - Procesa el matching con `KeywordMatcher::run()`
  - Acumula contadores en cache
  - Retorna el # de candidatos procesados
- [x] 1.3 Refactor menor: extraer el loop de matching que está en el comando a un método reutilizable para no duplicar lógica entre `runScanBackground` (clásico) y `runMonth` (mensual).

## 2. Backend: comando del worker con switch de modo

- [x] 2.1 En `app/app/Console/Commands/AvisosScanRunCommand.php`, detectar modo mensual:
  ```php
  $isMonthly = $this->option('no-window') && !$this->option('from') && !$this->option('to') && !$this->option('preset');
  ```
- [x] 2.2 Si es mensual:
  - Llama `planMonths()` y persiste `month_plan` en `avisos_scan_bg:{runId}`
  - Itera cada mes con `runMonth()`, marcando `running` → `done` en `month_plan`
  - Entre meses: `DB::disconnect()` + `sleep(1)` + chequea `stop_requested`
  - Al final: `status = 'done'`, `months_done = count(done months)`
- [x] 2.3 Si NO es mensual: comportamiento existente intacto (no toca este path).

## 3. Backend: controller expone `mode`, `month_count`, `first_month`, `last_month`

- [x] 3.1 En `app/app/Http/Controllers/Ia/AvisosInteligentesController.php`, `runScanBackground()`:
  - Después de validar y antes de lanzar el worker, detectar si es modo mensual (mismo flag que en el comando).
  - Si es mensual: ejecutar `planMonths()` para saber `month_count`, `first_month`, `last_month`.
  - Incluir estos campos en la respuesta 202.
  - Persistir `mode = 'monthly'` en el cache state junto con `month_plan` (que el worker actualizará después).
- [x] 3.2 Endpoint nuevo `POST /scan/run-bg/preview`:
  - Valida el body con las mismas reglas que `runScanBackground`
  - Si es modo mensual: ejecuta `planMonths()` y devuelve `{month_count, first_month, last_month, candidates_estimate}` (sin mutar nada, sin lanzar worker)
  - Si NO es modo mensual: devuelve `{mode: 'classic', message: 'No requiere preview'}`
  - **No** requiere auth adicional (basta con `auth` + `admin` del grupo)

## 4. Backend: status del run expone progreso mensual

- [x] 4.1 `scanRunStatus()` ya devuelve todo el cache state — solo verificar que incluye `mode`, `month_plan`, `current_month`, `months_total`, `months_done`. Sin cambios necesarios si el controller solo serializa el state completo. Si no, agregar un `array_merge`.

## 5. Migration: índice parcial en `transcriptions`

- [x] 5.1 Crear `database/migrations/YYYY_MM_DD_HHMMSS_add_state_finished_at_index_to_transcriptions.php`:
  - `Schema::create` no aplica; usamos `DB::statement('CREATE INDEX CONCURRENTLY transcriptions_state_finished_at_idx ON transcriptions(state, finished_at) WHERE state = \\\'done\\\';')`
  - Down: `DROP INDEX CONCURRENTLY IF EXISTS transcriptions_state_finished_at_idx;`
- [x] 5.2 Verificar que `CREATE INDEX CONCURRENTLY` no bloquee escrituras (no requiere AccessExclusiveLock).

## 6. Frontend: modal con plan mensual y barra por mes

- [x] 6.1 En `app/resources/views/ia/avisos-inteligentes/index.blade.php`, en el template del modal (`phase: 'running'`), agregar bloque condicional:
  ```html
  <template x-if="scanModal.mode === 'monthly'">
    <div>
      <p>Histórico: <span x-text="scanModal.months_done"></span>/<span x-text="scanModal.months_total"></span> meses</p>
      <p>Procesando: <span x-text="scanModal.current_month"></span></p>
      <!-- barra de progreso por mes -->
    </div>
  </template>
  ```
  El bloque clásico (escaneadas/hits/fallos) sigue visible siempre como fallback.
- [x] 6.2 En `pollScanRun()`, copiar `mode`, `months_done`, `months_total`, `current_month` del response al `scanModal`.
- [x] 6.3 Si `phase: 'done'` y `mode: 'monthly'`, agregar al texto "Total: X meses escaneados en Y minutos" en el panel de done.

## 7. Frontend: confirmación pre-launch con preview

- [x] 7.1 En `runScanBackground()` (la del frontend, NO el controller), antes del POST principal:
  - Si `scanForm.noWindow && scanForm.force && !scanForm.preset && scanForm.preset !== 'custom'`, llamar a `/scan/run-bg/preview` con el mismo body.
  - Mostrar `confirm()` con el mensaje: "Vas a procesar N meses (de FECHA_MIN a FECHA_MAX, ~K transcripciones). ¿Confirmar?"
- [x] 7.2 Si el operador cancela el `confirm()`, abortar sin disparar el POST.
- [x] 7.3 El modal de preview puede ser nativo (`window.confirm`) o un mini-modal Alpine. Para v1 usar `window.confirm` (5 líneas, suficiente).

## 8. Tests unitarios

- [x] 8.1 `tests/Unit/AvisosScanServicePlanMonthsTest.php`:
  - `planMonths()` con min/max fijos (e.g., 2024-01-15 a 2024-03-22) → retorna `[["2024-01","pending"],["2024-02","pending"],["2024-03","pending"]]`
  - `planMonths()` con min = max (e.g., 2024-06-15) → retorna un solo mes `[["2024-06","pending"]]`
  - `planMonths()` con BD vacía → retorna `[]`
- [x] 8.2 `tests/Unit/AvisosScanServiceRunMonthTest.php`:
  - Mockear `selectCandidates` y `KeywordMatcher` para verificar que `runMonth` pasa `from`/`to` correctos según el mes
  - Verificar que `runMonth` actualiza `month_plan[YYYY-MM] = 'done'` al finalizar

## 9. Tests de integración

- [x] 9.1 `tests/Feature/AvisosScanMonthlyModeTest.php`:
  - Test que el endpoint `runScanBackground` devuelve `mode: 'monthly'` cuando se dan las condiciones
  - Test que el endpoint `runScanBackground` devuelve `mode: 'classic'` para los otros casos
  - Test que `POST /scan/run-bg/preview` retorna el plan sin mutar nada en BD
- [x] 9.2 Test E2E con Playwright que verifica que durante un scan mensual:
  - El modal muestra "Procesando mes X/N" actualizándose
  - Otros endpoints del sitio siguen respondiendo <2s (mide `pg_stat_activity` debería mostrar <10 conexiones)

## 10. Validación manual con Playwright

- [x] 10.1 Login + ir a `/ia/avisos-inteligentes` → "Escaneo" tab → "Escanear ahora".
- [x] 10.2 Marcar "Histórico completo" + "Forzar" → ver el preview con # de meses antes de confirmar.
- [x] 10.3 Confirmar → ver modal "Procesando mes 1/N (YYYY-MM)".
- [x] 10.4 Mientras corre, en otro tab navegar a `/dashboard`, `/mis-archivos`, `/papelera` → todos deben responder rápido.
- [x] 10.5 Verificar en server: `PGPASSWORD=... psql -c "SELECT count(*) FROM pg_stat_activity WHERE state='active'"` durante el scan → debe ser ≤6, no 24.
- [x] 10.6 Esperar a que termine (puede ser 5-15 min según # de meses) → ver el panel de done con "X meses escaneados".

## 11. Documentación

- [x] 11.1 Actualizar `AGENTS.md`:
  - Nota: el modo mensual se activa solo con la combinación específica `noWindow=true && force=true && !from && !to && !preset`.
  - Documentar el endpoint `/scan/run-bg/preview` como nueva API pública.
- [x] 11.2 Documentar la convención `mode: 'monthly' | 'classic'` en la cache shape, en una sección de "Contratos de cache del módulo Avisos".

## 12. Deploy

- [x] 12.1 Correr migration (`php artisan migrate`) con `CONCURRENTLY` fuera de transacción; no asumir una duración fija y verificar que el índice quedó válido.
- [x] 12.2 NO requiere reinicio de workers supervisord.
- [x] 12.3 Desplegar con el feature flag desactivado → reload de PHP-FPM → activar después de validar el índice.
- [x] 12.4 Verificación post-deploy: tasks 10.1-10.6.
- [x] 12.5 Rollback: `git revert` + reload PHP-FPM. La migration down es `DROP INDEX CONCURRENTLY`. Riesgo bajo porque el path clásico sigue funcionando.

## 13. Refuerzos de confiabilidad y operación

- [x] 13.1 Centralizar la detección `noWindow && force && !from && !to && !preset` en una regla compartida por controller, command y preview; incluir el feature flag de activación.
- [x] 13.2 Persistir `scan_cutoff_at` durante planning y procesar el plan como snapshot; documentar que los datos posteriores quedan para otra corrida o el cron.
- [x] 13.3 Mantener tandas de hasta 50 dentro de cada mes, actualizando progreso y revisando `stop_requested` entre tandas.
- [x] 13.4 Usar límites mensuales semiabiertos (`>= inicio` y `< inicio del siguiente mes`) y una timezone definida para evitar huecos o duplicados en los bordes.
- [x] 13.5 Definir recuperación: un mes `running` no completado vuelve a `pending`; solo se omiten meses `done`; documentar si se reutiliza el mismo `runId` o se relanza idempotentemente.
- [x] 13.6 Proteger las actualizaciones de cache contra carreras entre worker y endpoint de stop; `stop_requested=true` no puede sobrescribirse con una copia antigua.
- [x] 13.7 Hacer que el preview ejecute únicamente planning y no un conteo exacto de candidatos mediante joins pesados.
- [x] 13.8 Añadir heartbeat/`last_progress_at` y criterio de worker stale independiente del TTL del cache.
- [x] 13.9 Validar la migration con `EXPLAIN`, `withinTransaction = false`, `CREATE INDEX CONCURRENTLY`, verificación de índice válido y rollback concurrente.
- [x] 13.10 Añadir pruebas de carreras, crash durante un mes, recuperación de `running`, cancelación entre tandas, datos nuevos después del planning y límites de timestamp.
- [x] 13.11 Desplegar con el feature flag desactivado, activar después de validar el índice y documentar el procedimiento de apagado de emergencia.
