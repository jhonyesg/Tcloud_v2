## Why

La estrategia B ("open") de `EnEsMixMiner` está documentada como obsoleta desde la auditoría del 2026-08-13: emite **bigramas** (`function_en + noun_es`) que contradicen el umbral `corrections.min_suggestion_words=3` que ya purgó 306 reglas de una sola palabra con 86.593 aplicaciones, porque a 2 palabras el reemplazo dispara en todo el corpus sin contexto y produce espanglish (`of love` → `de love`, `the cooperativas` → `la cooperativas`). El comando CLI ya está desprogramado desde el 2026-08-11 (`app/routes/console.php:113`) precisamente porque el find/replace sin contexto degrada el texto en vez de mejorarlo. Mantener el código de `mineOpen` + heurística `prep_es + article + noun` es deuda muerta que confunde a futuros mantenedores y ocupa ~180 líneas + 3 constantes.

## What Changes

- **Eliminar** de `app/app/Services/Ia/EnEsMixMiner.php`:
  - Método público `mineOpen(int $daysBack, int $minFreq, int $sampleSize = 50000): array`
  - Método público `heuristicSpanish(string $phrase): ?string`
  - Método público `guessArticle(string $noun): string`
  - Constantes públicas `EN_FUNCTIONS` y `COMMON_ES_NOUNS`
  - Constante privada `PREP_MAP`
- **Eliminar** de `app/app/Console/Commands/MineEnEsCorrectionsCommand.php` la opción `--strategy=known|open|both`. La firma queda con un único modo (KNOWN); la opción se elimina por completo (no se acepta como no-op) para evitar confusión sobre un comportamiento inexistente.
- **Eliminar** de `app/app/Services/Ia/CorrectionService.php:820` (`mineEnEsMix`) el parámetro `string $strategy`. La firma queda `mineEnEsMix(int $daysBack, int $minFreq, User $by): array` y siempre ejecuta la estrategia KNOWN.
- **Eliminar** en `EnEsMixMiner::mine()` el branching por estrategia; queda solo `mineKnown()`.
- **BREAKING**: cualquier caller que hoy pase `--strategy=open` o `--strategy=both` obtendrá error de opción desconocida. Aceptable porque el comando está desprogramado desde el 2026-08-11 y solo se invoca manualmente.
- Agregar tests que cubren los escenarios que se rompen al pasar `--strategy=open` (la opción debe ser rechazada) y que `mine()` solo retorna candidatos `strategy='known'`.
- Actualizar comentarios en `EnEsMixMiner` para indicar que la estrategia B fue retirada en favor de `LlmCorrectionSuggester` (quien sí tiene contexto) y de los mapeos KNOWN (curados manualmente).

## Non-goals

- No se reintroduce `min_suggestion_words` en `EnEsMixMiner`. El filtrado por longitud ya vive en `CorrectionService::isTooShortToPropose()` (`app/app/Services/Ia/CorrectionService.php:380`) como guard transversal; `mineKnown()` emite frases ≥3 palabras por construcción (los mapeos son hardcoded ≥3 tokens), así que no necesita el filtro.
- No se desactiva `LlmCorrectionSuggester` ni `corrections:ai-suggest`. Ese pipeline sí tiene contexto y es la ruta de producción.
- No se modifica `ContextShiftAuditor`, `EnEsRuleClassifier` ni `corrections:quarantine-en-es` (todos ortogonales al miner).
- No se migra la BD ni se purgan reglas existentes.

## Capabilities

### New Capabilities
Ninguna.

### Modified Capabilities
Ninguna. La estrategia open no está documentada en ningún spec canónico (`openspec/specs/transcription-corrections/spec.md` no contiene requisitos de miner — los requisitos de `2026-07-30-corrections-en-es-mix-miner` viven solo como delta en el change hermano y nunca fueron archivados al spec canónico). El cambio es **skip_specs**: refactor + remoción de código muerto sin cambio de comportamiento observable para usuarios.

## Impact

- **Código eliminado**:
  - `app/app/Services/Ia/EnEsMixMiner.php` (~180 líneas: `mineOpen`, `heuristicSpanish`, `guessArticle`, `EN_FUNCTIONS`, `COMMON_ES_NOUNS`, `PREP_MAP`).
  - `app/app/Console/Commands/MineEnEsCorrectionsCommand.php` (opción `--strategy`, branching por estrategia).
  - `app/app/Services/Ia/CorrectionService.php:820` (parámetro `string $strategy` en `mineEnEsMix`).
- **Tests afectados**:
  - `app/tests/Feature/CorreccionesEnEsMixTest.php` — quitar casos que ejercen `--strategy=open`; añadir caso negativo (rechazo de opción desconocida).
- **Cron / scheduler**: ninguno. El comando ya está desprogramado (`app/routes/console.php:113`).
- **UI**: ninguna. No hay vistas que expongan la opción.
- **Migración BD**: no requerida.
