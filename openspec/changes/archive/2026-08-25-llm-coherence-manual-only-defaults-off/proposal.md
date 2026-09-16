## Why

El 2026-08-25 a las 05:28 UTC el sistema disparó ~300 requests/6 minutos a `api.minimax.io/v1` con HTTP 401 (key revocada) porque `transcriptor.ai_coherence_enabled=1` y `llm-correction.tertiary_enabled=1` quedaron prendidos del deploy del 2026-08-19. La hemorragia siguió hasta que se aplicaron `UPDATE`s en `system_settings` y se reiniciaron los 12 queue workers `tcloud-transcription-batch-*`. El pase LLM debía ser manual, on-demand desde la UI: las fases automatizadas de búsqueda (`corrections:ai-suggest` y `corrections:cycle-suggestions`) ya están deshabilitadas de cron, pero el coherence pass (`TranscriptionCoherencePass`) no tenía guarda equivalente y consumió tokens en producción sin acción humana. Además los defaults de `LlmCorrectionSettings::SCHEMA` (`enabled=true`, `primary_enabled=true`) reintroducirán el riesgo en el próximo `migrate:fresh` o limpieza de `system_settings`.

## What Changes

- **Defaults off**: cambiar los defaults de los toggles maestro `llm-correction.enabled` y `llm-correction.primary_enabled` de `true` a `false` en `LlmCorrectionSettings::SCHEMA`. Cualquier fresh-install arranca con LLM apagado.
- **Circuit breaker**: `TranscriptionCoherencePass::callWithRetry()` deja de reintentar tras `N` fallos consecutivos en una ventana móvil de `X` minutos (actualmente reintenta con backoff exponencial hasta 3 veces, sin memoria del pasado). El provider que falla 5 veces en 10 min queda excluido por el resto del job.
- **`transcription:backfill-coherence` debe chequear `ai_coherence_enabled`**: hoy el artisan command llama al pase sin pasar por el toggle. Si admin lo corre con `enabled=0` por error, gasta tokens igual.
- **Documentar la política manual-only** en la pantalla AI Settings (texto explicativo al lado del toggle maestro), de modo que cualquier admin que lo prenda sepa que está saliendo del modo seguro.
- **No borrar** ninguna key, URL ni modelo configurado en `system_settings` — solo deshabilitar. Los proveedores siguen listos para uso on-demand.

## Capabilities

### New Capabilities
- `transcription-coherence-pass`: Define el comportamiento del pase de coherencia IA (`App\Services\Ia\TranscriptionCoherencePass`): default-off en código, gate por `ai_coherence_enabled`, circuit breaker ante fallos consecutivos, exclusión por provider caído, política manual-only. Cubre tanto el flujo automático desde `TranscriptionProcessor` como el `transcription:backfill-coherence`.

### Modified Capabilities
- `llm-correction-suggestion`: Agrega el requisito de que el toggle maestro `llm-correction.enabled` arranca en `false` por default en código (cambio del default en `LlmCorrectionSettings::SCHEMA`), de modo que un fresh-install no enciende el suggester accidentalmente. El admin debe prenderlo explícitamente desde AI Settings o vía `.env`.

## Impact

**Código**:
- `app/app/Services/Ia/LlmCorrectionSettings.php:53,59` — flip defaults a `false`.
- `app/app/Services/Ia/TranscriptionCoherencePass.php:81,481` — agregar circuit breaker y check defensivo en `apply()`.
- `app/app/Console/Commands/TranscriptionBackfillCoherenceCommand.php:38` — chequear `ai_coherence_enabled` antes de procesar; salir con warning si está apagado.
- `app/resources/views/ia/correcciones/index.blade.php` (settings panel) — texto explicativo junto al toggle maestro.

**Memoria**:
- Guardar decisión: `corrections:ai-suggest` y `transcription:backfill-coherence` no gastan tokens si el toggle maestro está en `false`. La política manual-only queda escrita en AI Settings.

**No afecta**:
- Routes / no cambia endpoints.
- Migraciones: ninguna — los defaults cambian en PHP, no en DB.
- Cron: `corrections:ai-suggest` sigue deshabilitado (off desde 2026-08-11), `corrections:cycle-suggestions` y `corrections:detect-english-residual` siguen activos pero son heurísticos (sin LLM).
- Proveedores: URLs, modelos y keys siguen en `system_settings`. Solo se apaga `*_enabled`.

**Operational pre-requisito** (ya ejecutado en sesión 2026-08-25 10:40-10:48 UTC, fuera del alcance del change):
- `UPDATE system_settings SET value='0'` para los 6 toggles maestros y providers.
- `systemctl restart tcloud-transcription-batch-{1..12}.service` para releer DB.
