# Change: Dictionary atomicity — pruning, effectiveness UI y prompts atómicos

## Why

Auditoría del diccionario real (2026-08-02, 2,678 correcciones aprobadas) reveló:

- **78.6% de las reglas NUNCA se han aplicado** (`applies_count = 0`).
- Promedio de **10 palabras por `wrong_text`**; hay reglas de hasta 85 palabras.
- Top 10 reglas más aplicadas son **palabras sueltas/bigramas**: `the` (84k), `in` (41k), `and` (38k), `for` (13k), `with` (7k), `we have` (1.9k), `Ok` (1.2k).
- **0 duplicados exactos y 0 conflictos** → la calidad es buena, no hay redundancia.
- **0 clusters con ≥60% de tokens compartidos** → las frases largas no son variantes de un mismo lema, son cada una un "overfit" del LLM que rara vez matchea contra texto nuevo.
- **75 reglas con cambio de contexto/tono** detectadas por auditoría de falsos amigos y muletillas: 10 falsos amigos (ej: `actually → actualmente` cuando debería ser `en realidad`) + 65 cambios de registro (ej: `you know → sabes` aplana muletilla, `like → como` pierde matiz de filler).

Causa raíz: el miner rule-based (`corrections:mine-en-es`) y el AI suggest (`corrections:ai-suggest`) están configurados para proponer **frases largas** cuando en realidad el sweet spot del diccionario son **palabras sueltas y bigramas**. Esto produce:

1. **Ruido operativo** para el admin (revisar 2,600 frases largas cuando bastaría con 200 palabras/bigramas).
2. **Costo de `Correction::applyToText()`** — cada transcripción corre el regex de TODAS las reglas aprobadas; 2,678 reglas ejecutándose por cada SRT es innecesario si la mayoría no aplica nunca.
3. **Pérdida de cobertura real** — al gastar tokens LLM y revisión humana en frases que no se repiten, se deja de lado el largo cola de palabras sueltas que sí matchearían miles de veces.
4. **Distorsión semántica** — el match regex con `\b{wrong}\b` no distingue entre `like` como comparación (OK traducir) y `like` como muletilla (NO traducir). El resultado: una transcripción con tono poético/casual/formal puede quedar "arrasada" porque el diccionario aplana su registro sin saber en qué contexto está.

El usuario confirmó que en su workflow diario pega ~2,600 frases al LLM para validarlas; queremos que esa inversión rinda más. Y pidió explícitamente: **"no deseo que una transcripción tenga un tono o vaya hacia un propósito y la traducción lo cambie y lo trastoque"** — es decir, el diccionario debe respetar el contexto/registro original.

## What Changes

### 1. UI: métricas de effectiveness en la tab Aprobadas

Hoy la tab Aprobadas muestra columnas básicas (original, corrección, proponente, aprobador, aplicaciones, origen, fecha) pero **no permite ordenar, filtrar ni agrupar por effectiveness**. Se agregan:

- **Sort por `applies_count`** (asc/desc) — click en el header "Aplicaciones".
- **Filtro "Solo inactivas"** — toggle que muestra solo `applies_count = 0`. Combinable con filtros existentes.
- **Filtro "Inactivas > N días"** — input numérico (default 30). Solo muestra inactivas creadas hace más de N días.
- **Botón "Eliminar inactivas"** — acción bulk que archiva todas las inactivas filtradas (usa el endpoint `bulk-destroy` existente).
- **Badge de effectiveness** por fila: 🟢 ≥ 100 aplicaciones, 🟡 1–99, 🔴 0.

### 2. Bulk cleanup de reglas inactivas

Endpoint nuevo `POST /ia/correcciones/bulk-destroy-inactive` con body `{ min_age_days?: int = 30, max_count?: int = 500 }`:

- Selecciona todas las reglas `status='approved'` con `applies_count=0` y `created_at <= now() - min_age_days`.
- Cap defensivo `max_count` (default 500) para evitar borrar miles en una sola corrida accidental.
- Reutiliza `CorrectionService::bulkDestroy()` para que la acción quede registrada en `correction_bulk_actions` y sea reversible dentro de la ventana de undo.
- Respuesta: `{ destroyed, skipped_protected, bulk_action_id, undo_expires_at }`.

UI: el botón "Eliminar inactivas" llama este endpoint y muestra confirmación con el conteo antes de ejecutar (modal estilo "Vas a eliminar X correcciones aprobadas con 0 aplicaciones creadas hace más de 30 días.").

### 3. Atomicity suggestions panel (Strategy 4)

Para cada corrección aprobada visualizada en la tab Aprobadas, un panel colapsable **"Sugerencias atómicas"** que:

1. Tokeniza el `wrong_text` (lowercase + split en `[\s\p{P}]+`).
2. Filtra stopwords y tokens < 3 chars.
3. Genera candidatos:
   - **Unigramas** (cada palabra suelta que NO esté ya en el diccionario como standalone).
   - **Bigramas** (pares consecutivos que NO estén ya como standalone ni como parte de otra corrección aprobada).
4. Para cada candidato, hace una **traducción tentativa** buscando si en otras correcciones ya aprobadas la misma palabra/bigrama tiene una traducción consistente. Si el 80% de las veces se traduce igual, propone esa traducción. Si no, marca `confidence=low` y deja el `correct` en blanco para que el admin lo llene.
5. Muestra la lista con checkboxes + un input inline para editar `correct` antes de aceptar.
6. Botón "Agregar N como nuevas correcciones" que crea `bulkApprove()` atómico con todos los marcados.

Implementación: nuevo endpoint `GET /ia/correcciones/{id}/atomicity-suggestions` + nuevo método `CorrectionService::extractAtomicitySuggestions(Correction $c): array`.

### 4. Re-prompting atómico del miner y AI suggest

Cambios a los prompts y heurísticas para **preferir atomicidad**:

#### 4a. `corrections:mine-en-es` (rule-based)

- Los 71 pares hardcodeados ya son atómicos (`in the world` → `en el mundo`, etc.). No cambian.
- La heurística de generación de candidatos (que arma frases a partir de patrones estructurales) se acota a `len(wrong_text) <= 6 palabras`. Si el match tiene más palabras, se subdivide en bigramas constituyentes y se inserta cada uno por separado.

#### 4b. `corrections:ai-suggest` (LLM)

Cambios al system prompt:

```
REGLA DE ATOMICIDAD (NUEVO):
- PREFERÍ siempre la versión más corta que capture la corrección. Si una palabra suelta
  es traducible de forma estable, proponé ESA, no la frase entera.
- SOLO proponé una frase de >4 palabras si la frase aparece ≥8 veces textuales en el corpus
  Y si sus palabras constituyentes individualmente NO son traducibles de forma estable.
- Para frases largas, adicionalmente proponé los bigramas constituyentes como candidatos
  separados (lista atómica).
- Penalizá frases con >8 palabras salvo que la frecuencia sea muy alta (≥15).
```

Cambios al post-filtro PHP en `LlmCorrectionSuggester::looksLikeBrandOrProperNoun`:

- Si el candidato tiene > 6 palabras y `freq < 8`, se descarta automáticamente con razón `rejected_by_length`.
- Si el candidato tiene > 10 palabras, se descarta siempre (con razón `rejected_by_length`).
- Los candidatos descartados por longitud se reportan en `rejected_by_length` (nuevo counter) para diagnóstico.

#### 4c. Nueva métrica: rejection rate

`corrections:ai-suggest` reporta al final:
- `rejected_by_filter` (existente: marcas, siglas)
- `rejected_by_length` (nuevo: frases demasiado largas)
- `promoted_to_atomic` (nuevo: candidategados a N bigramas atómicos extraídos)

### 5. Script de análisis one-shot para auditoría

Comando CLI `corrections:dictionary-audit` que imprime el mismo análisis que corrimos hoy (top palabras, bigramas, trigramas, clusters, distribución `applies_count`) sin tocar BD de escritura. Útil para:

- Verificar impacto de los cambios antes/después.
- Que el admin tenga una herramienta de diagnóstico sin tener que pedirle a Kilo.

Output: tabla markdown-friendly que se puede pegar en un issue.

### 6. Context-shift protection (risk_level)

Migración: agregar columna `risk_level ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'low'` a la tabla `corrections`. Semántica:

- `low` = segura, se aplica automáticamente en cualquier contexto. Default para la mayoría de reglas.
- `medium` = requiere contexto, se aplica pero conviene revisar.
- `high` = se omite del `applyToText()` automático. Solo se aplica si el admin la activa explícitamente (caso por caso o bulk force).

#### Blocklist de palabras/patrones sensibles

Lista estática en `app/config/corrections.php` bajo `context_sensitive.terms`:

```php
'context_sensitive' => [
    'filler_words' => [
        // EN muletillas que NO deben traducirse automáticamente
        ['term' => 'like', 'risk' => 'high', 'note' => 'muletilla cuando no es comparación'],
        ['term' => 'you know', 'risk' => 'high', 'note' => 'muletilla'],
        ['term' => 'i mean', 'risk' => 'high', 'note' => 'muletilla'],
        ['term' => 'basically', 'risk' => 'medium', 'note' => 'puede ser contenido o muletilla'],
        ['term' => 'literally', 'risk' => 'medium', 'note' => 'muletilla intensificadora'],
        ['term' => 'honestly', 'risk' => 'medium', 'note' => 'muletilla o adverbio'],
        ['term' => 'obviously', 'risk' => 'medium', 'note' => 'muletilla o adverbio'],
        ['term' => 'sort of', 'risk' => 'high', 'note' => 'muletilla dubitativa'],
        ['term' => 'kind of', 'risk' => 'high', 'note' => 'muletilla dubitativa'],
        ['term' => 'right', 'risk' => 'medium', 'note' => 'tag question'],
        ['term' => 'okay', 'risk' => 'medium', 'note' => 'muletilla o respuesta'],
    ],
    'false_friends' => [
        ['term' => 'actually', 'safe_translations' => ['en realidad', 'de hecho'], 'unsafe' => ['actualmente'], 'risk' => 'high'],
        ['term' => 'eventually', 'safe_translations' => ['con el tiempo'], 'unsafe' => ['finalmente'], 'risk' => 'high'],
        ['term' => 'sensitive', 'safe_translations' => ['sensible'], 'unsafe' => ['susceptible'], 'risk' => 'medium'],
        ['term' => 'sympathetic', 'safe_translations' => ['comprensivo', 'empático'], 'unsafe' => ['simpático'], 'risk' => 'high'],
        ['term' => 'actual', 'safe_translations' => ['real', 'verdadero'], 'unsafe' => ['actual'], 'risk' => 'high'],
        ['term' => 'realize', 'safe_translations' => ['darse cuenta'], 'risk' => 'low'],
        ['term' => 'eventual', 'safe_translations' => ['final', 'posterior'], 'risk' => 'medium'],
    ],
],
```

#### `ContextShiftAuditor` service

Servicio nuevo `App\Services\Ia\ContextShiftAuditor` que:

1. Recorre todas las correcciones `status='approved'`.
2. Para cada una, aplica las reglas de la blocklist:
   - Si `wrong_text` contiene una `filler_word` → asigna `risk_level` correspondiente (con override admin siempre editable).
   - Si `wrong_text` matchea una `false_friend` y el `correct_text` contiene una `unsafe_translation` → asigna `risk='high'` con nota específica.
3. Devuelve un dict `{correction_id: {risk: 'high', reason: '...'}}` con la sugerencia.
4. CLI: `php artisan corrections:context-audit` aplica las sugerencias a la BD (UPDATE solo si `risk_level` actual es 'low', para no pisar overrides manuales). Dry-run por default.

#### `Correction::applyToText()` modificado

```php
public static function applyToText(string $text, bool $includeHighRisk = false): string
{
    $corrections = static::approved()
        ->when(!$includeHighRisk, fn($q) => $q->where('risk_level', '!=', 'high'))
        ->orderByRaw('LENGTH(wrong_normalized) DESC')
        ->get(['wrong_normalized', 'correct_text']);
    // ... resto sin cambios
}
```

Por defecto, `includeHighRisk = false`. El comando retroactivo `corrections:apply-run` también respeta esto: NO aplica reglas `risk='high'` salvo que se pase flag `--include-high-risk` (que el admin debe usar conscientemente).

#### UI: tab "Contexto sensible"

Nueva tab en `/ia/correcciones` que lista todas las correcciones con `risk_level IN ('medium', 'high')`. Cada fila muestra:

- Original + corrección.
- Razón del flag (ej: "contiene `like` (muletilla)").
- Acciones: `Cambiar a low` (sobrescribir), `Editar traducción` (corregir false friend in-place), `Mantener high` (confirmar), `Eliminar` (borrar).

Bulk: botón "Aplicar sugerencias del auditor" que ejecuta `ContextShiftAuditor::applyToDb()` y muestra preview antes de confirmar.

#### Pre-approval safeguard

Cuando el admin aprueba una nueva corrección manual (`POST /correcciones` o approve individual) cuyo `wrong_text` contiene una `filler_word` o un `false_friend`, el endpoint retorna un warning adicional:

```json
{
  "ok": true,
  "correction": {...},
  "context_warning": {
    "risk": "high",
    "matched": "you know",
    "type": "filler",
    "note": "muletilla — no se aplicará automáticamente. ¿Confirmas que esta traducción debe aplicarse en todos los contextos?"
  }
}
```

El frontend muestra un modal de confirmación si `context_warning` viene presente.

## Non-goals

- **No auto-elimina reglas inactivas**: el admin siempre confirma el bulk destroy. La limpieza es manual y reversible dentro de la ventana de undo.
- **No toca correcciones con `applies_count > 0`**, incluso si son largas: si están rindiendo, se quedan.
- **No cambia el formato del CSV export** (cambio previo reciente).
- **No introduce un modelo de stemming/lemmatization**: fuera de scope. Las reglas siguen siendo literales.
- **No modifica el cron existente** del AI suggest — solo cambia qué devuelve.

## Impact

### Specs affected

- `transcription-corrections` — 4 ADDED requirements (effectiveness UI, atomicity suggestions, atomicity-first prompting, context-shift protection).

### Code affected (nuevos)

- `app/app/Services/Ia/DictionaryAudit.php` — análisis one-shot, sin BD de escritura.
- `app/app/Services/Ia/ContextShiftAuditor.php` — detecta falsos amigos y muletillas en correcciones aprobadas.
- `app/app/Console/Commands/DictionaryAuditCommand.php` — `corrections:dictionary-audit`.
- `app/app/Console/Commands/ContextAuditCommand.php` — `corrections:context-audit` (dry-run + apply).

### Code affected (modificados)

- `app/app/Services/Ia/CorrectionService.php` — `+extractAtomicitySuggestions(Correction $c): array`, `+bulkDestroyInactive(int $minAgeDays, int $maxCount, User $by): array`.
- `app/app/Services/Ia/LlmCorrectionSuggester.php` — `getSystemPrompt()` reescrito con regla de atomicidad; `looksLikeBrandOrProperNoun()` ahora también filtra por longitud.
- `app/app/Models/Correction.php` — scope `safe()` (excluye high-risk); `applyToText(string $text, bool $includeHighRisk = false)`.
- `app/app/Http/Controllers/Ia/CorreccionesController.php` — `+atomicitySuggestions()`, `+bulkDestroyInactive()`, `+auditReport()`, `+contextAudit()`, modificación de `approve()`/`store()` para retornar `context_warning` cuando aplique.
- `app/app/Console/Commands/ApplyCorrectionsCommand.php` — flag `--include-high-risk` (default false).
- `app/config/corrections.php` — bloque `context_sensitive.terms` con filler_words y false_friends.
- `app/resources/views/ia/correcciones/index.blade.php` — sort por applies_count, filtros inactivas, panel atomicity, botón bulk cleanup, tab "Contexto sensible", badge de risk_level en cada fila, modal de confirmación al aprobar con warning.
- `app/routes/web.php` — `+GET /correcciones/{id}/atomicity-suggestions`, `+POST /correcciones/bulk-destroy-inactive`, `+GET /correcciones/dictionary-audit`, `+POST /correcciones/context-audit`.

### Migrations

- `2026_08_02_xxxxxx_add_risk_level_to_corrections.php`: `ALTER TABLE corrections ADD COLUMN risk_level ENUM('low','medium','high') NOT NULL DEFAULT 'low'`. Backfill inicial vía `ContextShiftAuditor::applyToDb()` en el mismo deploy.

### Tests nuevos (estimado)

- `DictionaryAuditTest.php` — top palabras/bigramas/trigramas con dataset chico sintético.
- `ContextShiftAuditorTest.php` — detecta los 10 falsos amigos, las 11 muletillas, asigna risk correcto.
- `AtomicitySuggestionsTest.php` — extracción de unigramas/bigramas, dedupe contra existentes, fallback `confidence=low`.
- `BulkDestroyInactiveTest.php` — solo inactivas, respeta `min_age_days`, respeta `max_count`, queda registrado en `correction_bulk_actions`.
- `CorreccionesRiskLevelTest.php` — `applyToText()` omite `risk='high'`, lo incluye con `includeHighRisk=true`.
- `CorreccionesEffectivenessUITest.php` — sort/filter/bulk-delete inactivas vía reflection sobre el componente Alpine + endpoints.

### Riesgos

- **Bajo**: la limpieza de inactivas es reversible dentro de la ventana de undo (`corrections.undo_window_minutes` actual).
- **Bajo**: el panel de atomicity es solo aditivo (no modifica correcciones existentes).
- **Medio**: cambiar el prompt del LLM puede alterar ligeramente la calidad de las sugerencias. Mitigación: el cambio es conservador (prefer atómico, no "solo atómico") y se mide con el audit antes/después.
- **Medio**: el flag `risk_level='high'` cambia el comportamiento default de `applyToText()`. Si la migración se ejecuta antes que el backfill, hay un período donde las reglas nuevas marcadas como high aún no se han detectado. Mitigación: la migración y el backfill corren en el mismo deploy (un comando `php artisan migrate` seguido de `php artisan corrections:context-audit --apply`).

## Open questions (resueltas)

- **¿Auto-eliminar inactivas?** → NO. Manual con confirmación, reversible vía undo.
- **¿Cambiar el AI suggest a "solo palabras sueltas"?** → NO. Sigue proponiendo frases si la frecuencia lo justifica, solo cambia el sesgo.
- **¿Stemming/lemmatization?** → OUT OF SCOPE. La atomicidad por tokens literarios ya captura el 80% del valor.

## Acceptance criteria

1. La tab Aprobadas permite ordenar por `applies_count` asc/desc y filtrar por "inactivas > 30 días".
2. El botón "Eliminar inactivas" muestra confirmación con conteo y llama a `bulkDestroyInactive`. La acción queda registrada en `correction_bulk_actions` con `action='bulk_destroy'`.
3. Cada corrección aprobada tiene un panel colapsable con sugerencias atómicas (unigramas/bigramas) deduplicadas contra el diccionario existente.
4. `corrections:ai-suggest` reporta `rejected_by_length` cuando descarta candidatos largos con `freq < 8`. Las ejecuciones de prueba (dry-run) muestran que el nuevo prompt produce más unigramas/bigramas y menos frases largas que el prompt anterior.
5. `corrections:dictionary-audit` imprime el reporte de auditoría en <5 segundos para el diccionario actual.
6. Suite de tests pasa (incluyendo los nuevos). ~120+ tests.
7. Documentar en `openspec/changes/<este>/specs/transcription-corrections/spec.md` los 4 ADDED requirements.
