# Change: Barrido histórico + miner automático de mezclas EN↔ES

## Why

El diccionario de correcciones viene creciendo manualmente:
- Round 1 (bootstrapping 2026-07-29): 96 reglas seedadas
- Round 2 (pendientes adicionales): 56 reglas
- Round 3 (análisis histórico): 87 reglas
- Total: **244 correcciones** (101 approved + 143 pending)

Pero:
1. **El barrido completo del corpus histórico nunca se ha ejecutado.** El `corrections:apply-run` ya está en producción (procesó ~30k segmentos en 90s antes de la pausa) pero solo cubre una fracción. Necesitamos un comando que cubra TODO de manera sistemática.
2. **No hay detección automática de nuevos patrones.** Hoy los typos nuevos los encontramos con scripts ad-hoc en `/tmp/kilo/`. Si un nuevo patrón EN↔ES aparece mañana (ej. un nuevo noticiero usa "at the end of the day"), no lo detectamos automáticamente.
3. **El corpus tiene 10M+ segmentos** y el patrón más común de error es la mezcla de inglés en transcripciones en español (el ASR externo mete chunks de inglés en español por interferencia o spanglish del speaker).

Necesitamos un **miner** que:
- Escanee el corpus sistemáticamente
- Detecte mezclas EN↔ES no cubiertas
- Genere candidatos como pending (no approved) para que el admin los revise
- Sea ejecutable on-demand (CLI) y agendable (cron)

```
HOY (manual):
  Script ad-hoc /tmp/kilo/explore_history.php → output manual
  → admin pega a seeder à mano (10 min por round)
  → total: 244 reglas encontradas manualmente en 3 rondas

DESEADO (automatizado):
  php artisan corrections:mine-en-es --days=30
  → genera ~50-100 candidatos/día automáticamente
  → admin aprueba en lote via bulk moderation
  → el diccionario crece solo a medida que el corpus evoluciona
```

## What Changes

### Miner: `App\Services\Ia\EnEsMixMiner`

Servicio nuevo que detecta mezclas EN↔ES en el corpus. Dos estrategias:

**A. Mapeos conocidos (alta confianza)**: una lista hardcoded de ~100 frases EN que el ASR mete en español con su reemplazo natural ES. Cada uno se cuenta en el corpus; si supera un threshold (default 3 apariciones en los últimos N días) y no está en el diccionario, se propone como pending.

**B. Detección abierta (descubrimiento)**: tokeniza segmentos y busca secuencias `FUNCTION_EN + NOUN_ES` donde:
- `FUNCTION_EN` ∈ {the, a, in, of, on, at, by, for, with, to, from, and, or, but, is, are, was, were, this, that, these, those}
- `NOUN_ES` ∈ lista de sustantivos comunes en español (top 500 palabras más frecuentes en el corpus)
- Si la frecuencia en español >> frecuencia en inglés (ratio > 5:1), el ASR probablemente metió la palabra inglesa por error → sugerir reemplazo

### Comando: `corrections:mine-en-es`

Artisan command con flags:
- `--days=N`: ventana de análisis (default 30)
- `--min-freq=N`: frecuencia mínima para proponer (default 3)
- `--dry-run`: solo muestra los candidatos, no inserta
- `--strategy=known|open|both` (default both)

Output: tabla con columnas `wrong, correct, freq, source, strategy` y conteo de candidatos insertados.

### Servicio: `CorrectionService::mineEnEsMix(int $days, int $minFreq, string $strategy)`

Lógica de negocio reutilizable que el comando usa. Retorna array con candidatos detectados y metadata.

### Definición de datos: `KNOWN_EN_ES_MAPPINGS`

Constante en el miner (puede vivir en otra parte si crece). Lista de pares `wrong => correct`:
- Continuación de los 50 del GRUPO A de bootstrapping-2026-07-29
- Más los descubiertos en rounds 2 y 3
- Es fácilmente extensible (un admin puede agregar nuevas sin código)

### Scheduling: semanal

`routes/console.php`: agendar `corrections:mine-en-es --days=14 --min-freq=5` para correr semanalmente (domingo 02:00). Los candidatos generados se acumulan como pending; el admin los revisa en `/ia/correcciones` con la bulk moderation UI (recién implementada).

### UI: badge "minería reciente"

En `/ia/correcciones` header, agregar un badge que muestre:
- "Última minería: hace 2 días" (verde si < 7d, amarillo si 7-30d, rojo si > 30d)
- "N candidatos pendientes de mining"

Endpoint simple: `GET /ia/correcciones/mining-status` que retorna el último ejecución del miner.

### Modelo: campo `strategy` en `corrections`

Ya existe el campo `source`. Se reusa: `source='mining-YYYY-MM-DD'` para distinguir correcciones minadas vs seedadas. La columna `strategy` no hace falta (es metadata del miner, no del modelo).

## Non-goals

- **Auto-approve**: el miner NO aprueba reglas. Solo propone pending. El admin sigue siendo el gatekeeper.
- **Detección en tiempo real**: es batch only (cron). No se ejecutará en el webhook de SRT.
- **Otros idiomas**: solo EN↔ES. FR↔ES, PT↔ES, etc. quedan fuera de scope (no tenemos corpus de esos).
- **Typos fonéticos** (atencion→atención): ya cubiertos por rounds 1-3. El miner se enfoca en MEZCLA EN↔ES, no en omisión de tildes.
- **Tartamudeos** ("no no"): no aplica, ya decidimos no corregirlos.
- **Minería a nivel de palabras sueltas en inglés**: cubierto parcialmente pero con alta tasa de falsos positivos. Solo se sugieren cuando el ratio ES/EN esmuy alto.

## Impact

- **Specs affected**: `transcription-corrections` (2 ADDED requirements).
- **Code affected**:
  - `app/app/Services/Ia/EnEsMixMiner.php` (NUEVO)
  - `app/app/Services/Ia/CorrectionService.php` (agrega `mineEnEsMix()`)
  - `app/app/Console/Commands/MineEnEsCorrectionsCommand.php` (NUEVO)
  - `app/app/Models/CorrectionBulkAction.php` (sin cambios, solo lectura)
  - `app/app/Http/Controllers/Ia/CorreccionesController.php` (agrega `miningStatus()`)
  - `app/routes/web.php` (1 ruta nueva)
  - `app/routes/console.php` (1 schedule semanal)
  - `app/resources/views/ia/correcciones/index.blade.php` (badge "última minería")
  - `app/tests/Feature/CorreccionesEnEsMixTest.php` (NUEVO)
- **Migrations**: ninguna.
- **OpenSpec**: `openspec/changes/2026-07-30-corrections-en-es-mix-miner/specs/transcription-corrections/spec.md` (2 ADDED requirements).