## ADDED Requirements

### Requirement: System can automatically detect EN-ES mix patterns in transcriptions

El sistema SHALL exponer un miner (`App\Services\Ia\EnEsMixMiner`) con dos estrategias:

**A. Mapeos conocidos**: una lista hardcoded de frases EN que el ASR mete en español con su reemplazo natural ES. El miner cuenta cuántos segmentos del corpus las contienen (filtrado por `days_back`); si supera `min_freq` (default 3) y no está en el diccionario, propone como pending.

**B. Detección abierta**: tokeniza segmentos y busca secuencias `FUNCTION_EN + NOUN_ES` donde `FUNCTION_EN` ∈ {the, a, in, of, on, at, by, for, with, to, from, and, or, but, is, are, was, were, this, that, ...} y `NOUN_ES` ∈ lista de sustantivos comunes en español. Si la frecuencia en español >> frecuencia en inglés, sugiere reemplazo con heurística `prep_es + article + noun`.

#### Scenario: "in the world" aparece 200 veces en los últimos 30 días
- **WHEN** `php artisan corrections:mine-en-es --days=30 --min-freq=3` se ejecuta
- **AND** "in the world" aparece 200 veces en el corpus reciente
- **AND** no hay una approved correction con `wrong_normalized='in the world'`
- **THEN** el miner retorna un candidato `{wrong: "in the world", correct: "en el mundo", freq: 200, strategy: "known"}`.

#### Scenario: "in the world" ya está aprobado
- **WHEN** el diccionario tiene `wrong_normalized='in the world'` approved
- **THEN** el miner NO lo propone (ya cubierto).

#### Scenario: Frecuencia baja no genera candidato
- **WHEN** "in the world" aparece 2 veces en los últimos 30 días (por debajo de min_freq=3)
- **THEN** el miner NO lo propone.

### Requirement: Admin can trigger a mining pass on demand

El sistema SHALL exponer el comando `php artisan corrections:mine-en-es` con flags:
- `--days=N` (default 30): ventana de análisis
- `--min-freq=N` (default 3): frecuencia mínima para proponer
- `--strategy=known|open|both` (default both)
- `--dry-run`: solo muestra candidatos, no inserta

El comando invoca `CorrectionService::mineEnEsMix()` que es idempotente: si la regla ya está pending, no la duplica. Las reglas minadas se identifican con `source='mining-YYYY-MM-DD'`.

#### Scenario: Admin corre mining en dry-run para revisar primero
- **WHEN** admin corre `php artisan corrections:mine-en-es --days=30 --dry-run`
- **THEN** se imprime una tabla con `wrong, correct, freq, strategy` y NO se inserta nada.

#### Scenario: Admin corre mining real
- **WHEN** admin corre `php artisan corrections:mine-en-es --days=30`
- **THEN** se insertan N filas en `corrections` con `status='pending'` y `source='mining-2026-07-30'`.
- **THEN** la respuesta del comando muestra `Mined: X, Inserted: Y, Skipped: Z`.

#### Scenario: Mining idempotente
- **WHEN** admin corre mining 2 veces seguidas
- **THEN** la segunda corrida no duplica las pending existentes.

### Requirement: Mining runs weekly via scheduler

El sistema SHALL agendar `corrections:mine-en-es --days=14 --min-freq=5` los domingos a las 02:00 via `Schedule::command()` en `routes/console.php`. El scheduler usa `withoutOverlapping(120)` para evitar conflictos con retroactivo. El admin puede revisar los candidatos generados en `/ia/correcciones` con la bulk moderation UI.

#### Scenario: Cron ejecuta mining automático
- **WHEN** el scheduler dispara el comando cada domingo 02:00
- **THEN** se ejecuta el miner con la ventana de 14 días y min_freq=5
- **AND** los candidatos generados se acumulan como pending
- **AND** el admin los ve la próxima vez que abra `/ia/correcciones`.

#### Scenario: Mining y retroactivo corriendo en paralelo
- **WHEN** hay un `corrections:apply-run` activo y se dispara el miner
- **THEN** el `withoutOverlapping(120)` previene que ambos corran al mismo tiempo.
- **THEN** el miner espera (hasta 120 min) o se skipea si el lock no se libera.