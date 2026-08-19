# Change: Arreglo del extractor `ai-coherence-learn` + triage en capas de las 6.099 pendientes

## Why

El admin tiene **6.099 correcciones `pending`** en `/ia/correcciones`, de las cuales **6.035 (99%)** vienen del extractor `ai-coherence-learn` introducido el 2026-08-15 (`TranscriptionCoherencePass::learnFromCorrections` en `app/app/Services/Ia/TranscriptionCoherencePass.php:158`). El volumen bloquea la moderación manual pero la causa raíz es un bug del extractor, no el flujo de moderación:

1. **El `wrong` y `correct` son los segmentos enteros**, no la frase mínima que cambió (línea 134-138). El guard `>4 palabras` de la línea 179 se está evadiendo porque `str_word_count` cuenta diferente a la longitud real del segmento. Resultado: 5.156 reglas de 4-6 palabras (frases-enteras / traducciones literales) inflando la cola.
2. **`source_segment_id` no se popula** (línea 137): el `apply()` declara `@param array{index, text}` pero el código pide `$segments[$idx]['id']` que nunca existe — validación SQL muestra 0 coincidencias con el segmento origen en los 32 ids sueltos que sí se poblaron.
3. **Riesgo de aprobar en lote**: si se hace bulk-approve de las 6.099 sin filtrar, se repite el desastre del 2026-08-11 (`the→la`, 205.000 aplicaciones de espanglish). El proyecto documentó explícitamente esa lección en `app/routes/console.php`.

Queremos **limpiar el ruido que produce el extractor**, **triage de la cola existente en capas** (ruido → contexto válido → revisión humana mínima) y dejar el sistema produciendo solo entradas auditables.

## What Changes

### Backend
- **Fix extractor** (`TranscriptionCoherencePass::learnFromCorrections`):
  - Recibir `id` del segmento en `$segments[$idx]` desde el caller (`TranscriptionProcessor::persistSegmentsAndUpdate`) y poblar `source_segment_id`.
  - Cambiar `wrong=$before; correct=$newText` por un **diff por segmentos** que solo emita pares cuya longitud combinada ≤ 4 palabras (no el segmento entero).
  - Aplicar `looksLikeBrandOrProperNoun` + `EnEsRuleClassifier` antes de `proposeLearned()` (ya están en el service, falta llamarlos en este path).
- **Nuevo comando `corrections:triage-pending`** (`app/app/Console/Commands/CorrectionsTriagePendingCommand.php`):
  - Capa 1: descartar sin auditar reglas con `wrong>4 palabras` o `source_segment_id IS NULL` o que ya tengan `approved` con mismo `wrong_normalized`.
  - Capa 2: descartar las que `EnEsRuleClassifier::classify()` marque como `NOISE` o `QUARANTINE` (las traducciones EN→ES palabra-por-palabra que el 2026-08-11 desprogramó).
  - Capa 3: para las que sobreviven, ejecutar `WarmCorrectionContext` por segmento para que el modal "Contexto del segmento" del admin abra instantáneo.
  - Capa 4: opcional `--auto-approve-keep` para las que el classifier marca como `KEEP` (variantes ortográficas), con `bulk_action_id` registrado y undo de 5 min como el flujo bulk ya tiene.
  - Output: reporte por capa (`descartadas=..., sobreviven=...`) + lista exportable CSV de las que quedan para revisión humana.
- **Vista `/ia/correcciones`**: nuevo botón "Triage pendientes (N)" en el header junto al filtro de source, que dispara el comando vía SSH-shell (`CorrectionService::runTriagePending()` encolar un artisan en background con el patrón de `RunsBackgroundCommands` ya usado por `applyRetroactive`). Modal de progreso idéntico al de apply-retroactive.

### Frontend (Blade + Alpine.js)
- Botón "Triage pendientes" en `resources/views/ia/correcciones/index.blade.php` con badge de conteo actual.
- Modal de progreso que muestra `descartadas / sobreviven / auto-aprobadas` y un link "Ver resultado" al terminar.
- Toast de undo (5 min) si el modo `--auto-approve-keep` se usó.

## Non-goals

- **No auto-aprobamos en bulk las pendientes sin filtrar**: el desastre del 2026-08-11 lo prohíbe explícitamente. El bulk solo aplica después de pasar las 4 capas y solo a reglas `KEEP` (variantes ortográficas).
- **No tocamos el flujo `corrections:cycle-suggestions` ni `corrections:detect-english-residual`** (siguen su schedule cada 4h) — están bien y son independientes del extractor de IA.
- **No regeneramos retroactivo** desde este change: la propuesta solo **limpia la cola pendiente**. Una vez limpia, el admin puede correr `applyRetroactive` desde la UI con la nueva confianza.
- **No cambiamos el modelo del LLM** ni los thresholds del `ai-coherence`: solo arreglamos el post-proceso de los pares aprendidos.

## Capabilities

### New Capabilities
- `corrections-pending-triage`: nuevo comando + endpoint admin que aplica capas de descarte (largo, ruido, marca, classifier, contexto) a las correcciones pending existentes y produce una cola revisable acotada, con opción de auto-aprobar solo las `KEEP` del EnEsRuleClassifier.

### Modified Capabilities
- `transcription-corrections`: 2 ADDED requirements:
  1. "El extractor `ai-coherence-learn` debe poblar `source_segment_id` y emitir solo pares de ≤4 palabras (no segmentos enteros)".
  2. "El admin puede ejecutar triage en capas de las pending desde `/ia/correcciones` con reporte de descartes y red de seguridad (undo 5 min)".

## Impact

- **Code affected (modificado):**
  - `app/app/Services/Ia/TranscriptionCoherencePass.php` (líneas 124-141 y 158-201) — extracción de pares + filtro de calidad.
  - `app/app/Services/Ia/CorrectionService.php` — nuevo método `triagePending(string $mode): array` que orquesta el comando via cache + runId (mismo patrón que `applyRetroactive`).
  - `app/app/Http/Controllers/Ia/CorreccionesController.php` — nuevo método `triagePending(Request)` + ruta POST `/ia/correcciones/triage-pending` y GET `/ia/correcciones/triage-pending/{runId}` para polling.
  - `app/routes/web.php` (líneas 217-274) — agregar 2 rutas nuevas en el bloque admin IA.
  - `app/routes/console.php` — opcional: schedule semanal `corrections:triage-pending --dry-run` (solo reporte) cada domingo 02:30, después del `corrections:apply-run` actual.
  - `app/resources/views/ia/correcciones/index.blade.php` — botón "Triage pendientes (N)" + modal de progreso + toast undo.
- **Code affected (nuevo):**
  - `app/app/Console/Commands/CorrectionsTriagePendingCommand.php`
  - `app/app/Services/Ia/CorrectionTriageService.php`
- **Migrations:** ninguna (solo lee/escribe filas existentes; usar la tabla `correction_bulk_actions` para el undo).
- **Riesgos:**
  - **Bajo**: el comando es read-only por defecto; solo escribe (`--apply` o `--auto-approve-keep`) bajo opt-in del admin.
  - **Medio en modo `--auto-approve-keep`**: requiere que el `EnEsRuleClassifier` esté afinado. Mitigación: undo de 5 min + reporte de diff en el toast.
  - **Bajo en el fix del extractor**: solo cambia el formato de los pares emitidos; el `text` del segmento post-IA sigue igual.
- **Specs affected:** `transcription-corrections` (2 ADDED requirements); `corrections-pending-triage` (nueva capability completa).