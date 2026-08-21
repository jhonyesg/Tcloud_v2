# Change: AI suggest EN↔ES programado cada 2 horas

## Why

El modelo ASR del transcriptor cambió recientemente (2026-08-01) y mezcla más inglés dentro de frases en español. El miner rule-based (`corrections:mine-en-es`, ya programado semanal) captura patrones estructurales pero deja escapar el **long-tail** (palabras sueltas EN insertadas, frases nuevas no vistas antes).

El suggester LLM-powered (`corrections:ai-suggest`, `2026-08-01-corrections-ai-suggest-context-aware`) está diseñado exactamente para esto, pero **solo se invoca manualmente** desde el botón "AI Suggest" en `/ia/correcciones`. El admin lo lanza cuando recuerda, con gaps de horas-días entre corridas — y entre corrida y corrida el corpus acumula segmentos nuevos con mezclas EN↔ES que no se proponen como `corrections` pending.

Resultado: el admin aprueba correcciones a mano, pero muchas veces retroactivamente — cuando llega una notificación, cuando revisa SRTs, etc. Mientras tanto, **trascripciones que ya están en producción llevan inglés no corregido** que el cliente recibe en crudo porque el diccionario approved no crece a tiempo.

El admin pidió el 2026-08-01 (sesión actual): *"es necesario y más que todo porque hay mucho texto en inglés y necesito que eso se corrija o se pase en aprobados para que se corrija en automático en la transcripción que va al otro módulo con la capa de corrección aplicada"*.

El flujo que queremos:
1. AI suggest cada 2h propone `pending` para mezclas nuevas del último día.
2. Admin aprueba en lote desde `/ia/correcciones` (flujo bulk existente).
3. Una vez `approved`, la corrección fluye automáticamente a `CorrectionService::applyToSegments()` que se aplica a SRT nuevos Y al retroactivo manual (`Re-aplicar`).

Coste operativo: ~$0.05-0.10 por corrida con `minimax/minimax-m3` vía Kilo Gateway. 12 corridas/día × ~$0.07 ≈ **$25/mes**. Aceptable y justificado por la mejora real en calidad de las trascripciones que llegan al cliente.

## What Changes

### Una línea en `app/routes/console.php`

```php
// Reemplaza el bloque "ON-DEMAND ÚNICAMENTE" con:
Schedule::command('corrections:ai-suggest --days=1 --sample=200')
    ->everyTwoHours()
    ->withoutOverlapping(10)
    ->name('corrections:ai-suggest-scheduled')
    ->appendOutputTo(storage_path('logs/ai-suggest-scheduled.log'));
```

- **`everyTwoHours`**: 12 corridas/día. Cobertura del corpus "fresco" (los SRT del día entran al sampling de 1 día de ventana).
- **`--days=1 --sample=200`**: defaults confirmados en propuesta archivada original; --days=1 mantiene el costo acotado y --sample=200 es el sweet spot para 200 segments × ~4-5s/segment ≈ 3-5 min/corrida (cabe holgado entre runs de 2h).
- **`withoutOverlapping(10)`**: defensivo. La corrida típica cabe en 10 min; si una se cuelga, la siguiente se salta en vez de apilar procesos LLM-burning.
- **`appendOutputTo`**: log persistente con stdout de cada corrida (`Mined/Inserted/Skipped/Rejected`) para diagnósticar sin tener que habilitar debug en prod.

### Idempotencia y anti-duplicados — ya cubierto por código existente

El suggester ya tiene:
- `source='ai-suggest-YYYY-MM-DD'` por corrida → segreda al admin UI qué lote aprobó y permite rollback selectivo.
- `alreadyProcessedToday(int $segmentId)` + `markProcessedToday()` con cache SHA256 de `text_raw` (TTL 25h, según `2026-08-01-corrections-ai-suggest-context-aware/proposal.md`) → segments que el LLM ya vio hoy no se vuelven a mandar. Resultado: las corridas cada 2h con `--days=1` re-evalúan los mismos segmentos una y otra vez pero el LLM casi nunca reprocesa (skipeo por cache). El costo real termina dominado por **segments nuevos** que llegaron entre corrida y corrida.
- Post-filtro defensivo PHP + prompt del sistema → las `pending` propuestas pasan por una segunda capa anti-marca antes de inserción.
- `bulk-approve` (2026-07-30-corrections-bulk-moderation) + undo de 5 min → el admin limpia errores de aprobación cuando pasen.

Por eso `--reset-disabled-pending` NO se usa en cron: la política de "vencimiento" de pending viejos queda a discreción del admin desde la UI.

### Surface de UI: no cambia

- El badge "AI Suggest" en `/ia/correcciones` ya muestra `last_ai_suggest_at` y `pending_from_ai_suggest` desde el endpoint existente `GET /ia/correcciones/ai-suggest-status`. Con la cron cada 2h, el admin verá el timestamp actualizándose solo.
- El admin sigue aprobando/rechazando igual que hoy (bulk moderation o una a una).
- Si quisiera ver la corrida que se está ejecutando AHORA: `php artisan schedule:list` lista la próxima 12 entradas; `storage/logs/ai-suggest-scheduled.log` muestra las pasadas.

## Non-goals

- **No deshabilita el botón manual**: el admin sigue pudiendo lanzar AI Suggest desde la UI fuera de schedule (útil para una pasada grande con `--days=7 --sample=500` antes de un review masivo). El schedule corre en paralelo en `everyTwoHours`; `withoutOverlapping(10)` evita que el trigger manual se pelee con la cron (cualquiera que arranque primero mantiene el lock 10 min). Si se quiere coherencia estricta "una sola corrida a la vez", el admin puede bajar `enabled` desde AI Settings.
- **No cron-eamos `corrections:mine-en-es`** (ya está semanal domingos 02:00) ni `transcription:apply-corrections` (sigue 100% manual desde UI — la razón está en `2026-08-01-corrections-apply-progress-visibility`).
- **No auto-aprobamos pending del suggester**: la propuesta original ya descartaba esto (palancas de política editorial). El filtro defensivo anti-marca minimiza el riesgo de basura insertada, pero la revisión admin sigue siendo el gatekeeper.
- **No ajustamos `Kilo Gateway rate limits`**: 12 corridas/día × ~3-5 min cada una es trivial contra el rate limit del gateway (60+ req/min). No toca.
- **No cambiamos `LlmCorrectionSettings`** ni los defaults del modelo (`minimax/minimax-m3`). El override desde AI Settings sigue aplicando — si el admin quiere `--days=2`, lo cambia desde la UI y el próximo tick del Laravel scheduler lee el override desde DB. (Confirmar: la propuesta archivada dice DB-overridable. Si solo es env, el cambio NO se refleja en el comando ya armado — esto requiere una mini-revisión aparte del comando.)

## Impact

- **Specs affected**: `transcription-corrections` (1 ADDED Requirement: programación cada 2h del suggester).
- **Code affected (modificados)**:
  - `app/routes/console.php` (eliminar bloque "ON-DEMAND ÚNICAMENTE", agregar 1 línea de Schedule)
  - `openspec/changes/2026-08-01-corrections-ai-suggest-scheduled/specs/transcription-corrections/spec.md` (1 ADDED)
  - `openspec/specs/transcription-corrections/spec.md` (delta aplicado al archivar)
- **Code affected (nuevos)**: ninguno.
- **Migrations**: ninguna.
- **Costes operativos**: $25/mes estimados (12 corridas/día × $0.07/corrida con `minimax/minimax-m3`).
- **Riesgos**:
  - Bajo. El código del suggester ya está en producción y validado. El cambio es 1 línea de schedule + log.
  - Riesgo residual: si `LlmCorrectionSettings` no lee DB-overrides cuando se invoca via cron, los parámetros `--days=1 --sample=200` siempre van a leer los `.env`. Aclarar con grep en `LlmCorrectionSettings::int('days_back')` — si es `env('LLM_DAYS_BACK', '1')`, entonces el override UI no aplica a la corrida programada. Documentar y dejar nota en la propuesta.
  - Riesgo de costo desbocado: si el admin toca `LLM_API_KEY` apuntando a un modelo más caro (Claude Opus). El `LlmCorrectionSettings` lee `model` de DB-overridable, pero el código no valida presupuesto. Aceptable dado que el admin controla `model` desde UI.

## Open questions (resueltas)

- **Frecuencia**: cada 2h (vs 4h de la propuesta original). Razón: el admin pidió automatización, y 2h reduce el delta entre propuesta y aprobación. Coste 2× pero sigue bajo.
- **Parámetros**: `--days=1 --sample=200` (defaults recomendados en propuesta archivada; cambiable desde AI Settings si la implementación soporta override desde cron).
- **Schedule style**: `everyTwoHours` (determinista, sin desviación por drift de `cron`). Coherente con otros schedules Laravel del módulo (`storage:sync --all` cada 15 min, `corrections:mine-en-es` semanal).
