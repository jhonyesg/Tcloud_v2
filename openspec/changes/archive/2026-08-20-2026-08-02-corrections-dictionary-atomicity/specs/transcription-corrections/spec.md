# Spec deltas: transcription-corrections

## ADDED Requirements

### Requirement: Admin can prune inactive correction rules in bulk

El sistema SHALL permitir al admin identificar y eliminar en bulk las correcciones aprobadas con `applies_count = 0` (reglas que nunca se han aplicado) creadas hace más de N días.

#### Scenario: Admin audita la efectividad del diccionario

- **WHEN** admin ejecuta `php artisan corrections:dictionary-audit`
- **THEN** el comando imprime un reporte con: totales por status, distribución de `applies_count` en buckets (0 / 1-5 / 6-20 / 21-100 / 100+), top 30 unigramas/bigramas/trigramas dentro de `wrong_text`, conteo de duplicados exactos y conflictos, conteo de clusters con Jaccard ≥60%.

#### Scenario: Admin filtra y elimina inactivas desde la UI

- **WHEN** admin está en `/ia/correcciones` tab Aprobadas y activa el filtro "Solo inactivas" con "Creadas hace más de 30 días"
- **THEN** la tabla muestra solo las correcciones con `applies_count = 0` y `created_at <= now() - 30 días`.
- **AND** aparece un botón naranja "Eliminar N inactivas".
- **WHEN** admin hace click en el botón y confirma el modal
- **THEN** el frontend llama `POST /ia/correcciones/bulk-destroy-inactive` con `{min_age_days: 30, max_count: 500}`.
- **THEN** el backend devuelve `{destroyed, bulk_action_id, undo_expires_at}`.
- **AND** la lista de aprobadas se recarga sin esas filas.

#### Scenario: Bulk destroy respeta cap defensivo

- **WHEN** hay 5,000 inactivas candidatas y `max_count: 500`
- **THEN** el endpoint destruye solo las 500 más antiguas (ordenadas por `id` asc) y devuelve `destroyed: 500`.
- **AND** un segundo POST con los mismos parámetros destruiría las siguientes 500.

#### Scenario: Bulk destroy registra la acción

- **WHEN** el endpoint destruye N correcciones
- **THEN** se crea una fila en `correction_bulk_actions` con `action='bulk_destroy'`, `actor_user_id`, `created_at`, `expires_at = created_at + config('corrections.undo_window_minutes')`.
- **AND** se crean N filas en `correction_bulk_action_items` con los `correction_id` borrados.

#### Scenario: UI sort por applications

- **WHEN** admin hace click en el header de la columna "Aplicaciones"
- **THEN** las filas se ordenan asc/desc por `applies_count`.
- **AND** un ícono de flecha indica la dirección actual del sort.

#### Scenario: Badge de effectiveness

- **WHEN** admin ve una corrección aprobada
- **THEN** la fila muestra un dot de color junto al `applies_count`: verde (≥100), ámbar (1-99), rojo (0).
- **AND** el dot permite identificar visualmente las reglas "killer" vs las inactivas.

### Requirement: Admin can see atomicity suggestions for any approved correction

El sistema SHALL, para cada corrección aprobada, extraer los tokens sueltos (unigramas) y bigramas contenidos en su `wrong_text` que aún NO estén en el diccionario como standalone, y proponer traducciones tentativas basadas en la frecuencia de uso en otras correcciones aprobadas.

#### Scenario: Admin expande atomicity de una frase larga

- **WHEN** admin hace click en "Ver atomicidad" en una fila aprobada
- **THEN** el frontend llama `GET /ia/correcciones/{id}/atomicity-suggestions`.
- **AND** la respuesta es JSON con listas `unigrams`, `bigrams`, y `already_in_dict_unigrams`/`already_in_dict_bigrams`.
- **THEN** el panel renderiza los candidatos con checkboxes + un input `correct` editable inline.
- **AND** los candidatos con `confidence='high'` muestran el `suggested_correct` prellenado.
- **AND** los candidatos con `confidence='low'` muestran el campo `correct` vacío para que el admin lo llene.

#### Scenario: Confidence alta cuando hay consenso ≥80%

- **WHEN** un token aparece en 5 correcciones aprobadas y 4 de ellas lo traducen a la misma cadena
- **THEN** el sistema retorna `confidence='high'` y `suggested_correct=<esa cadena>`.

#### Scenario: Confidence baja cuando no hay consenso

- **WHEN** un token aparece en 4 correcciones aprobadas y se traduce a 3 cadenas distintas con distribución 50/25/25
- **THEN** el sistema retorna `confidence='low'` y `suggested_correct=null`.

#### Scenario: Dedupe contra diccionario existente

- **WHEN** un token ya está como corrección aprobada standalone (ej: `the` → `el`)
- **THEN** NO aparece en la lista de sugerencias atómicas.
- **AND** sí aparece en `already_in_dict_unigrams` para feedback visual.

#### Scenario: Admin acepta varias sugerencias atómicas en bulk

- **WHEN** admin marca 3 candidatos en el panel y hace click en "Agregar 3 como nuevas correcciones"
- **THEN** el frontend llama `POST /ia/correcciones/{id}/atomicity-suggestions/bulk-add` con `{items: [{wrong, correct}, ...]}`.
- **AND** el backend crea 3 correcciones nuevas con `status='approved'`, `source='atomicity-from-{parentId}'`, `proposed_by` y `approved_by` = admin actual.
- **THEN** el toast confirma "3 correcciones agregadas" y la lista de aprobadas se recarga con las nuevas filas.

#### Scenario: Stopwords filtradas

- **WHEN** la corrección es "the touristic attractives of the country"
- **THEN** la lista de unigramas sugeridos NO incluye `the` ni `of` (stopwords).
- **AND** SÍ incluye `touristic`, `attractives`, `country`.

### Requirement: LLM suggester prefers atomic (unigram/bigram) candidates over long phrases

El sistema SHALL, en el suggester LLM-powered (`corrections:ai-suggest`), sesgar la generación de candidatos hacia reglas atómicas (palabras sueltas y bigramas) y reportar métricas que permitan diagnosticar el efecto.

#### Scenario: System prompt incluye regla de atomicidad

- **WHEN** el suggester construye el system prompt
- **THEN** el prompt contiene una sección "REGLA DE ATOMICIDAD" que instruye al LLM a:
  - Preferir la versión más corta (palabra suelta) si aparece ≥3 veces en el corpus.
  - Solo proponer frases de >4 palabras si aparecen ≥8 veces textuales Y sus palabras constituyentes NO son traducibles individualmente.
  - Penalizar frases con >8 palabras salvo frecuencia ≥15.
  - No proponer nunca frases con >12 palabras.

#### Scenario: Post-filtro descarta frases largas con baja frecuencia

- **WHEN** el LLM retorna un candidato con `wrong` de 7 palabras y `freq: 2`
- **THEN** el post-filtro PHP lo descarta con `reason='rejected_by_length'`.
- **AND** el contador `rejected_by_length` se incrementa en el output del comando.

#### Scenario: LLM retorna atomic_candidates para frases largas aceptadas

- **WHEN** el LLM retorna un candidato principal con `wrong` de 9 palabras y `freq: 12` (válido)
- **AND** el JSON del LLM incluye un campo `atomic_candidates: [{wrong: "...", correct: "..."}, ...]`
- **THEN** el sistema extrae los `atomic_candidates` y los inserta como correcciones adicionales con `source='ai-suggest-YYYY-MM-DD-atomic-from-{parent_candidate_id}'`.
- **AND** el contador `promoted_to_atomic` se incrementa.

#### Scenario: Reporte final incluye contadores nuevos

- **WHEN** admin corre `php artisan corrections:ai-suggest --days=1`
- **THEN** el output final muestra:
  ```
  Mined: N
  Inserted: N
  Skipped (duplicate): N
  Rejected by filter: N  (marcas/siglas)
  Rejected by length: N  (frases demasiado largas)   ← NUEVO
  Promoted to atomic: N  (bigramas extraídos)        ← NUEVO
  ```

### Requirement: System flags context-shifting corrections and excludes them from automatic application

El sistema SHALL proteger el tono y contexto original de las transcripciones identificando correcciones cuyo `wrong_text` contiene patrones que cambian el registro (muletillas, falsos amigos, palabras ambiguas) y excluyéndolas del `applyToText()` automático.

#### Scenario: Columna risk_level en correcciones

- **WHEN** se ejecuta la migración `2026_08_02_xxxxxx_add_risk_level_to_corrections.php`
- **THEN** la tabla `corrections` tiene una nueva columna `risk_level ENUM('low','medium','high') NOT NULL DEFAULT 'low'`.
- **AND** las correcciones existentes quedan con `risk_level='low'` (default), requiriendo un backfill explícito (`php artisan corrections:context-audit --apply`) para marcar las sensibles.

#### Scenario: Blocklist de muletillas y falsos amigos

- **WHEN** admin inspecciona `app/config/corrections.php` bajo `context_sensitive.terms`
- **THEN** existen dos listas: `filler_words` (≥11 entradas: like, you know, i mean, basically, literally, honestly, obviously, sort of, kind of, right, okay) y `false_friends` (≥7 entradas: actually, eventually, sensitive, sympathetic, actual, realize, eventual) con `risk` y `note` por entrada.

#### Scenario: ContextShiftAuditor detecta false friend unsafe

- **WHEN** una corrección aprobada tiene `wrong_text="actually"` y `correct_text="actualmente"`
- **AND** la blocklist marca `actually` con `unsafe=['actualmente']`
- **THEN** `ContextShiftAuditor::evaluateOne()` retorna `{risk: 'high', reason: "false friend: 'actually' translated as 'actualmente' (unsafe); safe: en realidad, de hecho, la verdad"}`.

#### Scenario: ContextShiftAuditor detecta filler word

- **WHEN** una corrección aprobada tiene `wrong_text="you know, it's complicated"` y `correct_text="sabes, es complicado"`
- **THEN** `evaluateOne()` retorna `{risk: 'high', reason: "contains 'you know' (muletilla)"}`.

#### Scenario: ContextShiftAuditor retorna null para corrección segura

- **WHEN** una corrección tiene `wrong_text="the president"` y `correct_text="el presidente"`
- **THEN** `evaluateOne()` retorna `null` (sin issue; no se sugiere cambio de risk).

#### Scenario: applyToText omite risk=high por default

- **WHEN** existen correcciones con `risk_level='high'` en el diccionario
- **AND** se llama `Correction::applyToText($text)` (sin parámetros extra)
- **THEN** las reglas `risk='high'` NO se aplican al texto.
- **AND** solo `risk='low'` y `risk='medium'` se ejecutan.

#### Scenario: applyToText incluye risk=high cuando se pide

- **WHEN** admin llama `Correction::applyToText($text, includeHighRisk: true)` explícitamente
- **THEN** las reglas `risk='high'` SÍ se aplican.

#### Scenario: Command retroactivo respeta risk=high

- **WHEN** admin corre `php artisan corrections:apply-run` (sin flag)
- **THEN** el comando pasa `includeHighRisk=false` a `applyToText()`.
- **WHEN** admin corre `php artisan corrections:apply-run --include-high-risk`
- **THEN** el comando pasa `includeHighRisk=true`.

#### Scenario: CLI context-audit dry-run

- **WHEN** admin corre `php artisan corrections:context-audit` (sin `--apply`)
- **THEN** el comando NO modifica la BD.
- **AND** imprime una tabla con: id, wrong, correct, current_risk, suggested_risk, reason.
- **AND** al final muestra "N correcciones marcadas como risk distinto de low" sin haber persistido nada.

#### Scenario: CLI context-audit --apply

- **WHEN** admin corre `php artisan corrections:context-audit --apply`
- **THEN** el comando persiste los cambios en `corrections.risk_level` solo donde el valor actual es `'low'` (no pisa overrides manuales).
- **AND** retorna `{updated: N, skipped_manual: M}`.

#### Scenario: Pre-approval safeguard al aprobar corrección con filler

- **WHEN** admin aprueba `POST /correcciones` con `wrong="you know"` y `correct="sabes"`
- **THEN** el endpoint retorna `201` con body `{correction: {...}, context_warning: {risk: 'high', matched: 'you know', type: 'filler', note: 'muletilla'}}`.
- **AND** el frontend muestra un modal de confirmación: "Esta corrección contiene `you know` (muletilla). ¿Confirmas que debe aplicarse en todos los contextos?"
- **WHEN** el admin cancela
- **THEN** la corrección NO queda persistida.

#### Scenario: Pre-approval safeguard al aprobar false friend

- **WHEN** admin aprueba `POST /correcciones` con `wrong="actually"` y `correct="actualmente"`
- **THEN** el endpoint retorna `{correction: {...}, context_warning: {risk: 'high', matched: 'actually', type: 'false_friend', note: 'falso amigo — safe: en realidad, de hecho'}}`.
- **AND** el frontend sugiere automáticamente la traducción segura como alternativa inline.

#### Scenario: UI tab "Contexto sensible" lista reglas flagged

- **WHEN** admin hace click en la tab "Contexto sensible"
- **THEN** la tabla lista todas las correcciones con `risk_level IN ('medium', 'high')`.
- **AND** cada fila muestra: id, original, corrección, badge de risk, razón del flag, acciones (cambiar a low / editar / eliminar).

#### Scenario: Override manual de risk

- **WHEN** admin en la tab "Contexto sensible" hace click en "Cambiar a low" para una corrección
- **THEN** se setea `risk_level='low'` en esa corrección.
- **AND** la próxima corrida de `corrections:context-audit --apply` NO la vuelve a marcar (skip por no ser 'low'... wait, sí la va a marcar porque ahora es 'low'; el override es one-shot, documentar).

> **Nota de implementación**: el override manual persiste hasta la próxima corrida del auditor (que sí lo sobrescribirá). Esto es intencional para que el admin pueda "limpiar" el flag después de editar manualmente la traducción. Si quiere preservar el override permanentemente, debe editar la regla en sí (`wrong_text`/`correct_text`) de modo que no matchee la blocklist.

#### Scenario: Badge risk_level en tab Aprobadas

- **WHEN** admin ve la lista de correcciones aprobadas
- **THEN** cada fila tiene un dot de color junto al `applies_count`: verde (risk=low), ámbar (risk=medium), rojo (risk=high).
- **AND** un tooltip al hover muestra la razón del flag cuando risk != 'low'.

#### Scenario: Migración + backfill idempotente

- **WHEN** admin corre `php artisan migrate` por segunda vez sin nuevos cambios
- **THEN** la migración es no-op (idempotente).
- **WHEN** admin corre `php artisan corrections:context-audit --apply` dos veces seguidas
- **THEN** la segunda corrida actualiza 0 (todas las que matchearon ya tienen `risk != 'low'`).

#### Scenario: Bulk apply de sugerencias del auditor

- **WHEN** admin hace click en "Aplicar sugerencias del auditor" en la tab Contexto sensible
- **THEN** se llama `POST /correcciones/context-audit` que ejecuta `ContextShiftAuditor::applyToDb(false)`.
- **AND** retorna `{updated: N, skipped_manual: M}` y muestra un toast con el conteo.
- **AND** la tabla se recarga con los nuevos `risk_level`.
