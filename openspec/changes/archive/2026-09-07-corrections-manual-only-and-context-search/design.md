# Design: corrections-manual-only-and-context-search

## Context

Ver proposal.md — Why. Estado verificado el 2026-09-05:

- `routes/console.php` agienda 2 crons automáticos sin LLM: `corrections:detect-english-residual --hours=4 --threshold=0.5 --apply` (cada 4 h) y `corrections:cycle-suggestions --hours=4 --threshold=0.7 --min-freq=15 --max-rules=5` (cada 4 h). Ambos usan solo regex/PHP/Postgres, pero generan trabajo no solicitado (~4.500 `needs_review`/día; pila 119.405).
- `llm-correction.enabled` NO existe en `system_settings`; `LlmCorrectionSettings::get()` (app/app/Services/Ia/LlmCorrectionSettings.php:483) cae a `config('llm-correction.enabled')` → `env('LLM_CORRECTION_ENABLED', true)`; `.env` no tiene variables LLM → default `true` efectivo. Contradice la política defaults-off (spec 2026-08-25).
- `transcription_segments`: 49.802.574 filas, 13 GB, índices solo `pkey` + `transcription_id`. Extensión `pg_trgm` instalada, **cero índices GIN**.
- `CorrectionContextFinder::search()` (app/app/Services/Ia/CorrectionContextFinder.php:166-184) corre 2× `ILIKE '%…%'` (text y text_raw) con `SET LOCAL statement_timeout = 10s`. Medido: seq scan no termina en 120 s → timeout siempre → "La búsqueda tardó demasiado y se canceló" del admin.
- `TranscriptionReviewService::list()` modo `sensitive` (app/app/Services/Ia/TranscriptionReviewService.php:51-64): `whereExists` con `position(lower(wrong_normalized) in lower(text_raw))` contra 2.354 reglas approved medium/high, SIN acotar transcripciones y SIN statement_timeout. Medido: acotado a 10 transcripciones = 5,8 s; sin acotar no termina en 120 s. PHP-FPM `request_terminate_timeout=600`, nginx `fastcgi_read_timeout=300`.
- Skill `.kilocode/skills/corrections-ai-suggest/SKILL.md` afirma "corre cada 4 horas via Laravel scheduler" — falso desde 2026-08-11.

## Goals / Non-Goals

Goals:
- Cero procesos programados que generen pendientes o marcas de revisión.
- Búsqueda de contexto y modo sensibles responden dentro de su timeout usando índice.
- Documentación (skill) alineada con la realidad operativo.

Non-Goals:
- Purgar la pila `needs_review` (decisión aparte del admin).
- Tocar el transcriptor/ASR.
- Kill-switches adicionales para los crons rule-based.

## Decisions

### D1 — Desprogramar los 2 crons, sin kill-switch
Eliminar las líneas `Schedule::command(...)` de `detect-english-residual` y `cycle-suggestions` con comentario de fecha y razón (patrón del bloque histórico del 2026-08-11 que ya documenta `mine-en-es`/`ai-suggest`).
- *Alternativa descartada*: kill-switch en `system_settings`. Mantendría un tick inútil cada 4 h y una superficie de settings que nadie pidió. El historial del proyecto muestra que la desprogramación comentada es el patrón establecido (bloque 2026-08-11).
- Los comandos siguen existiendo para corrida manual.

### D2 — Guardrail `--confirm` en escritura masiva
Ambos comandos ya son seguros en ventanas cortas; el riesgo es una corrida manual `--days=30 --apply` que escriba cientos de miles de filas. Regla: si `--apply` (detector) o escritura real (cycle) con ventana > 24 h, exigir `--confirm`; sin él, degradar a dry-run con aviso. Ventanas ≤ 24 h no piden confirmación (flujo manual habitual intacto).
- *Alternativa descartada*: prompt interactivo (rompe uso no-interactivo; el proyecto ya usa flags explícitos en sus comandos operativos).

### D3 — `llm-correction.enabled=0` persistido via migración de settings
Migración idempotente que inserta la fila si no existe (patrón `SystemSetting`). Esto cierra la brecha detectada (env ausente → default true). La spec del 2026-08-25 ya exige defaults-off; este deploy la hace verificable en BD.
- *Alternativa descartada*: cambiar el default del schema a false en código. Ya es false en schema — el problema es la resolución env→true cuando la fila no existe en BD. Persistir la fila es la única forma robusta.

### D4 — Índice GIN trgm en `(text, text_raw)`
Migración que crea `CREATE INDEX ... USING gin (text gin_trgm_ops)` y `gin (text_raw gin_trgm_ops)` sobre `transcription_segments`. Dos índices separados (no multicolumna) porque el contexto busca por cualquiera de las dos columnas.
- Ejecución: la tabla tiene 13 GB y ~50 M filas; la creación bloquea escrituras. Se crea con `AlterTable` normal fuera de horario (ventana de madrugada; los escritos a segments ocurren solo tras transcripciones terminadas, y `transcription:tick` re-encola), o si el admin prefiere cero bloqueo, `CREATE INDEX CONCURRENTLY` en un paso manual posterior — la migración soporta ambos vía flag en su constructor.
- *Alternativas descartadas*: `tsvector`/FTS (cambia semántica de búsqueda; el finder usa ILIKE literal); índice trgm multicolumna único (no sirve ambas columnas).
- Efecto colateral positivo: el `whereExists position()` del modo sensibles y los `ILIKE` de `EnEsMixMiner`/`CorrectionContextFinder` pasan de seq scan a index scan.

### D5 — Timeout + acotación en modo sensibles
`TranscriptionReviewService::list()`:
1. Resolver primero las N transcripciones candidatas (latest done, limit 10) — query barata.
2. El conteo "sensibles" y el filtro `whereExists` corren dentro de una transacción con `SET LOCAL statement_timeout` (default 10 s, `config('corrections.review_sensitive.timeout_ms', 10000)`).
3. En timeout: capturar QueryException, degradar esa pieza (conteo 0 + flag `degraded: true` en el payload) y responder 200.
- El matching por `position()` sigue siendo exacto (no trgm) para las 10 candidatas: 5,8 s medidos es aceptable; el índice GIN lo acelerará aún más cuando aplique.
- *Alternativa descartada*: reescribir el matching a `similarity()` trgm (cambia resultados; no es lo que pide la spec).

### D6 — Actualizar la skill corrections-ai-suggest
Reescribir la sección "Frecuencia automática": aclarar que NO hay cron desde 2026-08-11 (ratificado 2026-09-05), que el master switch está OFF en BD y que la skill solo debe ejecutarse bajo pedido explícito del admin, con dry-run primero.

## Risks / Trade-offs

- [Crear GIN sobre 13 GB puede tardar decenas de minutos y bloquear writes en segments] → Migración marcada para correr en ventana de madrugada; opción CONCURRENTLY manual documentada en tasks; si la ventana no aparece, el índice queda como tarea pendiente y el resto del change es independiente.
- [El índice GIN agrega overhead de mantenimiento (~15-25 % de tamaño extra) en cada write masivo de segments] → Aceptado: los writes por transcripción terminada son batch; el beneficio de búsqueda domina.
- [Desprogramar `detect-english-residual` elimina la única marca automática de transcripciones con inglés residual] → Intencional (decisión manual-only); la revisión queda 100% bajo demanda del admin. La pila histórica se conserva.
- [Degradación del modo sensibles con flag `degraded` cambia el payload] → Aditivo; la UI existente ignora claves nuevas.
- [Sin crons, nadie mira si el ASR empeora] → Fuera de alcance; el watchdog `transcription:health-check` sigue vigilando el flujo, no la calidad lingüística.

## Migration Plan

1. Deploy de código (routes/console.php, comandos, service, migraciones).
2. `php artisan migrate` — crea fila `llm-correction.enabled=0` (idempotente) y los 2 índices GIN (bloquea writes de segments; correr de madrugada o usar CONCURRENTLY manual).
3. Verificación: `schedule:list` sin los 2 crons; `system_settings` con el switch en 0; `EXPLAIN` del finder usa Bitmap/Index Scan.
4. Rollback: `git revert` + `DROP INDEX CONCURRENTLY`; la fila de setting se puede borrar sin efecto adverso (vuelve el default env).

## Open Questions

- Si el admin prefiere el índice GIN vía `CREATE INDEX CONCURRENTLY` (sin bloqueo, no transaccional en migración Laravel): se documenta el comando manual en tasks y la migración queda como fallback estándar. Resolución por defecto: migración estándar en ventana de madrugada.