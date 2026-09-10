## Why

Cuando el operador elige **"Histórico completo (sin límite de fechas)"** en `/ia/avisos-inteligentes`, el escaneo manual genera una query candidata de tipo `SELECT count(*) FROM transcriptions t INNER JOIN files f INNER JOIN keywords k WHERE t.state = 'done'` que **escanea 355k transcripciones × 65 keywords = ~22M filas virtuales** sin filtro de fecha. Esa query tarda ~5 segundos por sí sola, y el worker itera sobre cada par keyword × storage lanzando varias en paralelo, lo que **satura las 24 conexiones de PostgreSQL**. Mientras el scan corre, PHP-FPM no puede servir ninguna otra request → toda la plataforma queda colgada (clicks lentos, páginas que no cargan).

Reproducción del 2026-09-09 con Playwright + `pg_stat_activity`:
- 8 queries pesadas corriendo en paralelo, 5 con duración >2 minutos
- 24 conexiones de BD activas, ninguna libre para servir tráfico del front
- 0 progreso en cache (`scanned: 0, batches: 0, hits_new: 0`) después de 3 minutos

Los fixes anteriores (índice en `transcriptions`, rate-limit, confirmación UI) son **paliativos**: ninguno reduce el volumen total de trabajo, solo lo hace menos catastrófico. La causa raíz es que el path `noWindow=true` itera sobre TODO el historial sin acotar la unidad de trabajo.

## What Changes

- Nuevo modo de ejecución para el caso `noWindow=true` + `force=true` + sin rango explícito: **catch-up por fases mensuales** en lugar de un scan monolítico.
- **Planning**: al lanzar, el worker calcula `min(finished_at)` y `max(finished_at)` de `transcriptions WHERE state='done'`, captura un `scan_cutoff_at`, deriva la lista de meses entre esos dos extremos, y persiste el plan en `avisos_scan_bg:{runId}` bajo la clave `month_plan[YYYY-MM] = status` (`pending|running|done|failed`). El plan es un snapshot; las transcripciones posteriores quedan para otro scan o el cron.
- **Iteración**: el worker procesa cada mes secuencialmente llamando al motor existente `selectCandidates` con un intervalo temporal acotado. El mes es la unidad visible y reanudable, pero cada mes conserva el drenaje interno por tandas de ≤50 candidatos, con actualización de progreso y chequeo de cancelación entre tandas. La lógica de matching actual se mantiene (idempotente con UNIQUE(transcription, segment, keyword)).
- **Yielding entre meses**: después de cada mes, el worker hace `DB::disconnect()` y `sleep(1-2s)` para liberar conexiones de BD y darle espacio a PHP-FPM.
- **Resumible**: el plan se persiste en cache con TTL renovable. Si el worker crashea, un mes `running` se trata como no completado y la próxima ejecución continúa desde ese mes sin reprocesar los meses `done`; el mecanismo exacto de reanudación (mismo `runId` o relanzamiento idempotente) queda explicitado en el contrato del worker.
- **UX nueva**: el modal muestra el plan (e.g., "30 meses detectados: 2024-03 a 2026-09 · Procesando mes 5/30 (julio 2024)"). El operador ve progreso por mes, no por candidato.
- **Confirmación antes de launch**: cuando el operador elige "Histórico completo" + "Forzar", el frontend muestra el # de meses detectado y pide confirmación (siguiendo el patrón del full scan existente). El preview no ejecuta un conteo exacto de candidatos ni reproduce los joins pesados.
- **Migration opcional**: índice parcial sobre `finished_at` para filas `state='done'`, validado con `EXPLAIN` y creado concurrentemente. No es bloqueante: el plan mensual ya acota el trabajo aunque no haya índice.
- **Protección operativa**: el modo mensual queda detrás de una configuración/feature flag de emergencia para poder desactivarlo sin revertir el despliegue.
- **Backward compat**: todos los demás casos (preset "24h", from/to explícito, catch-up por keyword con rewind) siguen usando el path existente sin cambios.

## Capabilities

### New Capabilities
- `avisos-scan-monthly-catchup`: el modo de escaneo iterativo por mes. Cubre el planning, la iteración secuencial, el yielding entre meses, la persistencia del plan y la recuperación ante crashes. También define la API del plan (`month_plan`, `current_month`, `progress_per_month`) que consume el widget global y el modal.

### Modified Capabilities
- `avisos-scan-configuration`: añadir un requisito sobre el nuevo modo mensual cuando `noWindow=true && force=true`. El endpoint SHALL responder 202 con un JSON que incluya `month_count` (plan detectado) y `runId`. El modal SHALL mostrar el plan en lugar del spinner genérico.
- `transcription-disk-scanner`: añadir un requisito equivalente para el endpoint del transcriptor si tiene el mismo problema de saturación (a evaluar; podría NO necesitar cambio porque el transcriptor ya opera por storages acotados y por batch).

## Impact

**Backend:**
- `app/app/Services/Ia/AvisosScanService.php`:
  - Nuevo método `planMonths(array $opts): array` — devuelve la lista de meses, el `scan_cutoff_at` y el plan vacío.
  - Nuevo método `runMonth(string $runId, string $yearMonth, array $opts): int` — drena UN mes por tandas y actualiza cache/progreso.
  - Modificación de `selectCandidates()` para respetar `from`/`to` que ya recibe (sin cambios si ya lo hace).
- `app/app/Console/Commands/AvisosScanRunCommand.php`:
  - Cuando `noWindow=true && force=true && !from && !to && !preset` y el feature flag está habilitado: switch al modo mensual.
  - Llama `planMonths` → itera `runMonth` por tandas con yielding → marca done/failed → exit. Un mes `running` de una ejecución interrumpida se reanuda como pendiente.
- `app/app/Http/Controllers/Ia/AvisosInteligentesController.php`:
  - `runScanBackground()`: añadir log de "monthly mode" si detecta el caso, persistir `month_count` en cache para que el frontend lo muestre.
  - Sin cambios en endpoints de status/stop (el polling ya lee `month_plan` y `current_month` del cache).

**Frontend:**
- `app/resources/views/ia/avisos-inteligentes/index.blade.php`:
  - Modal: cuando recibe `month_count > 0`, muestra "Escaneo histórico: N meses detectados (FECHA_MIN → FECHA_MAX) · Procesando mes X/N (YYYY-MM)" en lugar del contador de candidatos.
  - Nueva confirmación pre-launch: si eligió "Histórico completo" + "Forzar", mostrar confirmación con # de meses (llama a un endpoint ligero `POST /scan/run-bg/preview` que ejecuta únicamente el planning sin mutar ni estimar candidatos mediante joins pesados).
  - El botón "Detener" sigue funcionando (cooperative cancel entre meses).

**Migration:**
- `database/migrations/YYYY_MM_DD_HHMMSS_add_state_finished_at_index_to_transcriptions.php`:
  - `CREATE INDEX CONCURRENTLY transcriptions_finished_at_done_idx ON transcriptions(finished_at) WHERE state = 'done'`
  - Partial index para no inflar el catálogo con estados que no nos interesan; validar con `EXPLAIN` antes de fijar la forma definitiva.

**Cache shape:**
```php
'avisos_scan_bg:{runId}' => [
  'status' => 'running',
  'mode' => 'monthly',  // nuevo: 'classic' | 'monthly'
  'month_plan' => [
    '2024-03' => 'done',
    '2024-04' => 'running',
    '2024-05' => 'pending',
    ...
  ],
  'current_month' => '2024-04',
  'months_total' => 30,
  'months_done' => 1,
  // ...existing fields (scanned, hits_new, batches, etc.) se mantienen para compat
]
```

**Tests:**
- `tests/Feature/AvisosScanMonthlyPlanTest.php`:
  - Plan con 0 transcripciones → `month_count = 0`, status `done` inmediato
  - Plan con 3 meses de datos → itera secuencialmente, marca cada mes done
  - Plan con un mes parcial actual → usa el `scan_cutoff_at` capturado como límite superior
  - Yielding: simular un mes con sleep, verificar que la conexión de BD se libera entre meses
- `tests/Unit/AvisosScanServicePlanMonthsTest.php`:
  - `planMonths()` con min/max fijos retorna lista correcta de YYYY-MM
  - `planMonths()` con min = max retorna un solo mes
- Test E2E con Playwright: lanzar scan histórico, verificar que el modal muestra "mes X/N" y que el servidor sigue respondiendo a otras requests durante el scan.

**No requiere reinicio de workers supervisord.** El cambio vive en el controller + service + command. Solo reload de PHP-FPM. El feature flag permite desplegar desactivado y activarlo después de validar el índice y el comportamiento en producción.

## Non-goals

- Cambiar la lógica de matching de keywords (KeywordMatcher) ni el shape de `segment_keyword_hits`.
- Migrar scans en curso: si un scan ya empezó en modo "classic", sigue corriendo como siempre. Solo se aplica el modo mensual a nuevos scans con la combinación específica `noWindow=true && force=true && !from && !to && !preset`.
- Eliminar el path clásico: se mantiene para casos como un `force` simple sin `noWindow`, o un catch-up de keyword con storage acotado. Solo el caso "todo, sin filtros, forzado" cambia.
- Cambiar el widget global: el shape del job es compatible (los campos `progress.scanned`, etc., se siguen llenando, solo añadimos `progress.month_current` y `progress.month_total`).
- No implementar paralelización entre meses: cada mes es secuencial para mantener el orden de procesamiento y simplificar el modelo de "stop_requested".
- No agregar el modo mensual al cron automático: el cron ya respeta `windowHours` por diseño. El modo mensual es solo para catch-up manual.
- No paralelizar meses en esta primera versión: la protección de conexiones, la cancelación y la recuperación tienen prioridad sobre reducir el tiempo total.
- No calcular una estimación exacta de candidatos durante el preview: el preview solo informa el plan temporal y, opcionalmente, una estimación claramente aproximada de transcripciones.
