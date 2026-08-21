## Context

Estado actual (ver `proposal.md` - Why para motivación completa):

- `EnEsMixMiner` ofrece dos estrategias vía `mine(int $daysBack, int $minFreq, string $strategy)`: `mineKnown()` (frases hardcoded, 71+ entradas) y `mineOpen()` (bigramas inferidos con heurística `prep_es + article + noun`).
- `mineOpen()` contradice el guard transversal `corrections.min_suggestion_words=3` (`app/config/corrections.php:47`) porque emite frases de exactamente 2 palabras.
- El comando CLI `corrections:mine-en-es` está desprogramado desde el 2026-08-11 (`app/routes/console.php:113`); el scheduler que lo invocaba con `--strategy=both` ya no existe.
- No hay specs canónicos que documenten la estrategia `open` (los deltas del change `2026-07-30-corrections-en-es-mix-miner` nunca fueron archivados).

Restricciones del proyecto:

- PHP 8.4, Laravel 13.
- Tests en `app/tests/Feature/CorreccionesEnEsMixTest.php` (18 casos).
- Convención del proyecto: commits `conventional commits`, sin comentarios nuevos salvo que el usuario los pida.

## Goals / Non-Goals

**Goals:**

- Reducir la superficie pública de `EnEsMixMiner` a una sola estrategia (KNOWN), eliminando código muerto que induce a error.
- Mantener el comportamiento observable para usuarios (UI, retroactivo, aplicación de correcciones): ninguno cambia.
- Cubrir con tests el contrato nuevo: `--strategy=open` se rechaza; `mine()` solo retorna `strategy='known'`.

**Non-Goals:**

- No se modifica `LlmCorrectionSuggester`, `ContextShiftAuditor`, `EnEsRuleClassifier`, `corrections:quarantine-en-es`.
- No se reintroduce el scheduler de `corrections:mine-en-es` (sigue desprogramado).
- No se cambian los mapeos KNOWN hardcoded.
- No se modifica la firma pública de `mineKnown()` (mantiene `int $daysBack, int $minFreq`).

## Decisions

### D1. Eliminar el método público `mineOpen()` y todas sus dependencias internas, no deprecarlo

**Decisión**: borrar `mineOpen`, `heuristicSpanish`, `guessArticle`, `EN_FUNCTIONS`, `COMMON_ES_NOUNS`, `PREP_MAP` en una sola operación.

**Rationale**: el método nunca se invoca desde producción (cron desprogramado), y deprecarlo con `@deprecated` solo pospone la confusión. El audit ya lo marca como obsoleto desde 2026-08-13.

**Alternativas consideradas**:

- **A. Deprecar `mineOpen` con `@deprecated` durante una release**: introduce ruido en IDEs y mantiene ~180 líneas sin uso real. Descartado.
- **B. Mover `mineOpen` a un miner separado (`EnEsOpenMiner`)**: complica la arquitectura sin beneficio. Descartado.

### D2. Eliminar la opción `--strategy` del CLI, no aceptar solo `known`

**Decisión**: quitar la opción completa del `$signature` y de `handle()`. `--strategy=open` o `--strategy=both` se rechazarán como opción desconocida por Laravel.

**Rationale**: mantener `--strategy=known` como valor único conservaría código de branching por una opción sin alternativas. Es ruido. Eliminar la opción entera deja la intención clara.

**Alternativas consideradas**:

- **A. Mantener `--strategy=known` como no-op explícito**: preserva scripts que la pasan hoy pero suma branching. Descartado por simplicidad.
- **B. Renombrar el comando a `corrections:mine-known`**: cambio de mayor superficie; el comando ya está desprogramado y solo se invoca manualmente. Descartado.

### D3. Quitar el parámetro `string $strategy` de `CorrectionService::mineEnEsMix()`

**Decisión**: nueva firma `mineEnEsMix(int $daysBack, int $minFreq, User $by): array`. Llamadas internas al método actualizan a la nueva firma.

**Rationale**: la única razón del parámetro era ramificar entre `known`/`open`/`both`. Sin `open`, no hay razón para aceptar el parámetro.

**Alternativas consideradas**:

- **A. Mantener `string $strategy` pero aceptar solo `known`**: preserva firma pero suma validación. Descartado.

### D4. Actualizar tests existentes, no añadir suite nueva

**Decisión**: en `app/tests/Feature/CorreccionesEnEsMixTest.php`, eliminar los casos que invocan `--strategy=open` o `--strategy=both`, y añadir un caso que verifique que `--strategy=open` ahora falla con exit code no-cero.

**Rationale**: el contrato del comando cambió (la opción ya no existe); los tests deben reflejarlo. No se introduce suite nueva porque la cobertura existente ya ejercita `mineKnown` correctamente.

**Alternativas consideradas**:

- **A. Reescribir toda la suite con el contrato nuevo**: trabajo extra sin beneficio. Descartado.

## Risks / Trade-offs

- **R1. Script externo o cron huérfano pasa `--strategy=open` → falla** → Mitigación: el comando está desprogramado desde el 2026-08-11. Si existe un script manual, el error de opción desconocida es explícito (`The '--strategy' option does not exist`) y no produce resultados incorrectos silenciosamente. Aceptable.
- **R2. Alguien lee el código y cree que `mineOpen()` se puede rehabilitar trivialmente** → Mitigación: el comentario en `EnEsMixMiner` ahora documenta por qué se eliminó (umbral `min_suggestion_words=3`, espanglish) y apunta a `LlmCorrectionSuggester` como ruta de producción para long-tail contextual.
- **R3. Tests rotos pasan inadvertidos al borrar casos** → Mitigación: la task incluye ejecutar `php artisan test --filter=CorreccionesEnEsMixTest` como verificación explícita antes de cerrar.

## Migration Plan

Sin esquema. Sin deploy escalonado. Sin rollback de BD.

**Pasos de deploy**:

1. Merge del PR.
2. Deploy normal (PHP-FPM reload).
3. Verificación post-deploy: ejecutar `php artisan corrections:mine-en-es --days=1 --dry-run` debe retornar candidatos `strategy='known'` y NO aceptar `--strategy=open`.

**Rollback**: revert del commit. No hay estado persistente que limpiar.

## Open Questions

Ninguna. Las decisiones tomadas son reversibles (código sin estado) y los call sites son trazables (un grep en `app/` confirma que `mineOpen` solo se invoca desde `EnEsMixMiner::mine()`).
