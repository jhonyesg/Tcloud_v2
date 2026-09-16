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

### Requirement: AI-suggest is manual-only (no scheduled cron)

`corrections:ai-suggest` SHALL NOT estar agendado en `routes/console.php` en ninguna cadencia (cambio 2026-09-05: el schedule cada 4 horas fue eliminado definitivamente). La única vía de ejecución es la acción explícita del admin: POST `/ia/correcciones/ai-suggest-now` o `php artisan corrections:ai-suggest`.

#### Scenario: Cron dispara AI-suggest automático
- **WHEN** pasan 4 horas desde la última corrida
- **THEN** el scheduler NO ejecuta `corrections:ai-suggest`: la entrada fue eliminada de `routes/console.php` y no se consumen tokens de LLM.

#### Scenario: AI-suggest y otro proceso largo corren en paralelo
- **WHEN** hay un `corrections:apply-run` activo o un `corrections:mine-en-es` activo y el admin dispara el AI-suggest manualmente
- **THEN** la corrida manual no degrada al otro proceso: la idempotencia por caché de segmentos y el filtro de duplicados pending evitan efectos dobles.
- **THEN** sin cron, no existe colisión de `withoutOverlapping` que gestionar.

#### Scenario: La única vía es manual
- **WHEN** admin hace click en "AI Suggest 1d" del header de `/ia/correcciones` o corre `php artisan corrections:ai-suggest --days=1`
- **THEN** el comando corre como corrida única on-demand con el guardrail del master switch (`llm-correction.enabled`).

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

### Requirement: LLM correction master switch defaults to OFF

El toggle maestro `llm-correction.enabled` SHALL tener default `false` en el schema (`LlmCorrectionSettings::SCHEMA`) y SHALL estar persistido con valor `0` en `system_settings` tras este deploy (migración de settings idempotente). Con la fila persistida, el estado OFF deja de depender de la ausencia de variables env: estado real detectado 2026-09-05 — `.env` sin variables LLM y fila ausente hacían que el default env `true` ganara. Ningún proceso automático SHALL cambiar este valor.

Esta política está activa en producción desde el incidente del 2026-08-25 05:28 UTC (hemorragia de tokens con HTTP 401 por toggle maestro encendido sin revisión) y se ratifica el 2026-09-05 con la persistencia en BD.

#### Scenario: Fresh install con defaults del código
- **WHEN** un admin corre `php artisan migrate:fresh --seed`
- **THEN** `SELECT value FROM system_settings WHERE key='llm-correction.enabled'` retorna `'0'` (la migración de settings de este change persiste la fila).
- **AND** `php artisan corrections:ai-suggest --days=1` sale con el WARNING del switch apagado y código SUCCESS, sin gastar tokens.

#### Scenario: Admin prende el toggle desde la UI
- **WHEN** admin abre AI Settings y activa "Switch maestro"
- **THEN** la fila `llm-correction.enabled` queda en `'1'`.
- **AND** el suggester queda operativo solo para corridas manuales (no existe cron que lo invoque).

#### Scenario: Admin desactiva desde .env por emergencia
- **WHEN** admin edita `.env` y pone `LLM_CORRECTION_ENABLED=false`
- **THEN** sin importar el valor en `system_settings`, el suggester considera el toggle apagado.
- **AND** `LlmCorrectionSettings::bool('enabled')` retorna `false` independientemente de la fila de DB.

### Requirement: Primary provider defaults to OFF

El toggle `llm-correction.primary_enabled` SHALL tener default `false` en el schema. El provider primario (Kilo Gateway) solo se usa si:

1. El admin lo activó explícitamente desde AI Settings, o
2. El admin lo activó vía `.env` (`LLM_PRIMARY_ENABLED=true`), o
3. El provider primario es el único `enabled` y se requiere para el round-robin del coherence pass.

#### Scenario: Kilo Gateway apagado por default
- **WHEN** un admin revisa AI Settings en una instalación nueva
- **THEN** el toggle "Proveedor primario (Kilo) habilitado" muestra estado apagado y un badge "manual-only".
- **AND** el coherence pass no invoca Kilo aunque `llm-correction.enabled=true`.

#### Scenario: Round-robin no incluye primary si está apagado
- **WHEN** el coherence pass arma la lista de providers en `callWithRetry()`
- **AND** `primary_enabled=false`
- **THEN** `primary` no aparece en `$providers[]`.
- **AND** el round-robin salta directo a `secondary/tertiary/quaternary` si están prendidos.
- **AND** si ninguno está prendido, lanza `RuntimeException('No hay proveedores LLM habilitados.')` como antes.

### Requirement: manual-only policy documented in suggester SPEC

El SPEC de `llm-correction-suggestion` SHALL explicitar que el suggester es **manual-only en default**: el admin decide cuándo correrlo (modal AI Suggest + botones 1-click del header en `/ia/correcciones`). El cron de scheduling (cada 4h) sigue deshabilitado del 2026-08-11 y esta Spec ratifica esa decisión: el suggester no se reactiva automáticamente en el scheduler aunque `llm-correction.enabled=true` en DB.

#### Scenario: Admin verifica el estado del scheduler
- **WHEN** admin corre `php artisan schedule:list`
- **THEN** la salida NO contiene `corrections:ai-suggest` en la lista de jobs agendados.
- **AND** el archivo `routes/console.php` mantiene el `corrections:ai-suggest` comentado en el bloque histórico del 2026-08-11.

#### Scenario: Admin quiere correr el suggester manualmente
- **WHEN** admin hace click en el botón "AI Suggest 1d" del header de `/ia/correcciones`
- **THEN** el comando se dispara como corrida on-demand única con `days=1`, `sample=200`, `dry-run=false` (o según config admin).
- **AND** los resultados se insertan como `pending` o se auto-aprueban según `auto_approve`.

### Requirement: Admin can trigger an AI-context-correct pass inline on a single example

El sistema SHALL exponer un flujo de corrección inline por ejemplo desde el modal "Ejemplos en transcripciones" de `/ia/correcciones`. El flujo SHALL estar expuesto vía dos endpoints nuevos:

- `POST /ia/correcciones/{correctionId}/examples/{exampleId}/ai-context-correct` — solicita al LLM una corrección contextualizada de la frase del ejemplo y devuelve `{wrong, correct, reason, model, tokens_used}`.
- `POST /ia/correcciones/{correctionId}/examples/{exampleId}/ai-context-correct/approve` — persiste la corrección aceptada como `pending` con `source='ai-context-correct-YYYY-MM-DD'`.

Ambos SHALL respetar los mismos gates que `aiSuggestNow`: master switch `llm-correction.enabled=1` en `system_settings` y `LlmCorrectionSettings::apiKey()` no vacía. Las respuestas SHALL ser idempotentes en cache durante `config('corrections.ai_context_correct.cache_ttl', 86400)` segundos.

#### Scenario: La corrección inline consume el mismo master switch
- **WHEN** admin hace click en "Corregir esta frase con IA"
- **AND** `llm-correction.enabled = 0` en `system_settings`
- **THEN** el endpoint responde 503 con el mismo contrato y mensaje que `/ia/correcciones/ai-suggest-now`.

#### Scenario: Aprobación entra al pool de pendientes con origen trazable
- **WHEN** admin aprueba una corrección IA inline
- **THEN** se inserta una fila en `corrections` con `status='pending'`, `source='ai-context-correct-YYYY-MM-DD'`, `risk_level` derivado del LLM (`medium` por default)
- **AND** la nueva fila aparece en la pestaña "Pendientes" filtrable por origen `ai-context-correct-*`.

#### Scenario: Idempotencia por cache entre re-aperturas del modal
- **WHEN** admin abre y cierra el modal de contexto varias veces dentro de 24 h
- **THEN** el segundo click sobre el mismo ejemplo muestra la respuesta cacheada sin consumir tokens
- **AND** el botón "Reintentar" sigue invocando al LLM y refrescando la cache.

#### Scenario: Auto-filtrado por origen en la UI de pendientes
- **WHEN** admin filtra la pestaña "Pendientes" por origen `ai-context-correct-2026-09-05`
- **THEN** la lista muestra solo las correcciones inline IA creadas ese día, independientemente del modo (masivas, individuales, de ejemplo).
