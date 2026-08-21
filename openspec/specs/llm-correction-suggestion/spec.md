# Spec: llm-correction-suggestion

## Purpose

Define el suggester de correcciones con LLM (`App\Services\Ia\LlmCorrectionSuggester`): un tercer nivel (después del miner rule-based y la creación manual) que lee muestras de transcripciones recientes, usa un modelo de lenguaje para detectar mezclas EN↔ES estructurales y palabras/frases en inglés insertadas en español que el rule-based no captura, propone candidatos `wrong → correct` con `source='ai-suggest-YYYY-MM-DD'`, y aplica exclusión de marcas/productos/nombres propios/siglas con defensa en profundidad (prompt + post-filtro PHP). Es ejecutable on-demand vía `corrections:ai-suggest` y agendado cada 4 horas; es idempotente y nunca auto-aprueba (el admin sigue siendo el gatekeeper).

---

## Requirements

### Requirement: System can suggest corrections using an LLM with brand exclusion rules

El sistema SHALL exponer un suggester (`App\Services\Ia\LlmCorrectionSuggester`) que:

1. Lee una muestra de N segmentos recientes de `transcription_segments` (configurable: `--days`, `--sample`).
2. Construye un prompt con reglas explícitas de exclusión de marcas/productos/nombres propios/siglas en mayúsculas.
3. Llama a un proveedor OpenAI-compatible (configurable vía `LLM_BASE_URL`, `LLM_API_KEY`, `LLM_MODEL`; default `minimax/minimax-m3` vía Kilo Gateway).
4. Aplica post-filtro defensivo PHP para descartar candidatos cuyo `wrong` parezca marca, nombre propio, sigla en mayúsculas, o esté en la lista `config('llm-correction.protected_brands')`.
5. Inserta los candidatos aceptados como `pending` con `source='ai-suggest-YYYY-MM-DD'` (vía `CorrectionService::aiSuggestEnEsMix()`).
6. Es idempotente: usa caché de segmentos procesados hoy (TTL 25h, key `ai_suggest:processed:{id}:{YYYY-MM-DD}`) y filtra duplicados contra pending existentes.

#### Scenario: LLM propone una mezcla EN↔ES estructural
- **WHEN** una muestra de 200 segmentos contiene "the president made a statement"
- **AND** el LLM retorna `{"wrong": "made a statement", "correct": "hizo una declaración", "freq": 3}`
- **AND** ningún filtro defensivo descarta "made a statement"
- **THEN** se inserta una corrección pending con `source='ai-suggest-2026-08-01'`.

#### Scenario: LLM propone eliminar una marca
- **WHEN** un segmento contiene "el equipo usa Word Enterprise"
- **AND** el LLM retorna `{"wrong": "Word Enterprise", "correct": "procesador de texto empresarial"}`
- **AND** la post-filtrado PHP detecta `Word Enterprise` en la lista de marcas protegidas
- **THEN** el candidato SE DESCARTA, NO se inserta en la BD, y aparece en el contador `rejected_by_filter` del output del comando.

#### Scenario: LLM propone cambiar una sigla todo mayúsculas
- **WHEN** un segmento contiene "la ONU aprobó la resolución"
- **AND** el LLM retorna `{"wrong": "ONU", "correct": "Naciones Unidas"}`
- **AND** la post-filtrado detecta que `ONU` es sigla todo-mayúsculas (≥ 2 chars)
- **THEN** el candidato SE DESCARTA, NO se inserta.

#### Scenario: Configuración deshabilitada
- **WHEN** `LLM_CORRECTION_ENABLED=false` en `.env`
- **THEN** `php artisan corrections:ai-suggest` sale con `success` y un warning, sin tocar BD ni gastar tokens.

#### Scenario: API key faltante
- **WHEN** `LLM_API_KEY` no está configurada
- **THEN** el comando falla con código FAILURE y mensaje "LLM_API_KEY no configurada".

### Requirement: Admin can trigger an AI-suggest pass on demand

El sistema SHALL exponer el comando `php artisan corrections:ai-suggest` con flags:
- `--days=N` (default `config('llm-correction.days_back_default')` = 1): ventana de análisis
- `--sample=N` (default 200): número de segmentos a muestrear
- `--dry-run`: solo muestra candidatos, no inserta

En dry-run imprime tabla con `Wrong, Correct, Freq, Confidence, Reason` y contadores `rejected_by_filter`, `segments_processed`, `cached_today`.

En modo real invoca `CorrectionService::aiSuggestEnEsMix()` y reporta `Mined, Inserted, Skipped (pending duplicado), Rechazados por filtro, Source`.

#### Scenario: Admin lanza AI-suggest en horario laboral
- **WHEN** admin corre `php artisan corrections:ai-suggest --days=1 --dry-run` desde el shell
- **THEN** el sistema imprime la tabla de candidatos propuestos por el LLM con el filtro aplicado, pero NO inserta nada.
- **THEN** el comando termina en <60 segundos (timeout configurable vía `LLM_TIMEOUT_SECONDS`).

#### Scenario: Admin confirma y lanza AI-suggest real
- **WHEN** admin corre `php artisan corrections:ai-suggest --days=1`
- **THEN** se insertan N filas en `corrections` con `status='pending'` y `source='ai-suggest-2026-08-01'`.
- **THEN** la respuesta muestra `Mined: X, Inserted: Y, Skipped: Z, Rechazados por filtro: W`.

#### Scenario: Idempotencia entre corridas del mismo día
- **WHEN** admin corre AI-suggest dos veces en el mismo día
- **THEN** la segunda corrida skipea los segmentos ya procesados (cache hit) y los candidatos que ya están pending no se duplican.

### Requirement: AI-suggest runs every 4 hours via scheduler

El sistema SHALL agendar `corrections:ai-suggest` (con defaults) cada 4 horas via `Schedule::command()` en `routes/console.php`. El scheduler usa `withoutOverlapping(120)` para evitar colisión con retroactivo, miner rule-based, y otras corridas AI-suggest solapadas.

#### Scenario: Cron dispara AI-suggest automático
- **WHEN** pasan 4 horas desde la última corrida exitosa
- **THEN** el scheduler ejecuta `corrections:ai-suggest` con defaults (days=1, sample=200).
- **AND** los candidatos se insertan como pending.

#### Scenario: AI-suggest y otro proceso largo corren en paralelo
- **WHEN** hay un `corrections:apply-run` activo o un `corrections:mine-en-es` activo y se dispara el AI-suggest
- **THEN** el `withoutOverlapping(120)` previene que ambos corran al mismo tiempo.
- **THEN** la AI-suggest espera (hasta 120 min) o se skipea si el lock no se libera.

### Requirement: Brand and proper-noun exclusion is enforced (defense-in-depth)

El sistema SHALL aplicar la regla de exclusión de marcas con DOS barreras:

1. **Capa 1 — System prompt** (en cada llamada al LLM): instruye explícitamente NO proponer cambios sobre:
   - Marcas registradas (Dionato, Microsoft, Apple, Google, Sony, Samsung, etc.)
   - Productos comerciales (Word Enterprise, Office 365, Salesforce, etc.)
   - Nombres de personas
   - Nombres de organizaciones
   - Siglas en mayúsculas sostenidas (ONU, EE.UU., API, JSON)
   - Términos técnicos consolidados en su forma original (iPhone, AirPods)

2. **Capa 2 — Post-filtro PHP** (después de cada respuesta del LLM): descarta candidatos cuyo `wrong`:
   - Matchea `/^[A-Z]{2,}$/` (sigla mayúsculas)
   - Contiene capitalización interna en una sola palabra (`/MacBook/`, `/iPhone/`)
   - Está (completo o como sub-frase) en `config('llm-correction.protected_brands')`
   - Empieza con mayúscula no siendo primera palabra de oración (probable nombre propio)

Los candidatos descartados se cuentan en `rejected_by_filter` y NO se insertan. NO se reportan al admin (sería ruido); sí se loguean a nivel debug para auditoría.

#### Scenario: Defensa en profundidad — LLM pasa una marca y el filtro la atrapa
- **WHEN** el LLM retorna `{"wrong": "Microsoft", "correct": "..."}` por error (ignora la regla del prompt)
- **THEN** el filtro PHP lo descarta porque `microsoft` está en `protected_brands`.
- **THEN** NO se inserta en BD.

#### Scenario: Defensa en profundidad — el filtro atrapa una sigla que el LLM sí siguió las reglas
- **WHEN** el LLM correctamente sigue las reglas y NO retorna siglas como candidatos
- **BUT** un test adversarial intenta inyectar `{"wrong": "API"}` en la respuesta
- **THEN** el filtro PHP lo descarta porque matchea `/^[A-Z]{2,}$/`.

#### Scenario: Lista de marcas protegida actualizable sin redeploy
- **WHEN** se agrega 'NuevoProducto' a `config/llm-correction.php` en el array `protected_brands`
- **THEN** el siguiente `corrections:ai-suggest` descarta candidatos cuyo `wrong` contenga 'NuevoProducto' sin necesidad de tocar el código del filtro.