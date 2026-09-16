# Change: atajo "Excluir" en la tabla de pendientes y aprobadas

## Why

El admin pidió el 2026-08-01:

> *"lo ideal es en pendientes tener un botón que diga exclusion para agregar exclusiones de forma fácil"*

El flujo hoy es: el admin ve una pendiente sospechosa (algo que parece marca/nombre propio pero que el AI Suggest filtró mal), tiene que ir a otro tab (IA Suggest → Exclusiones → modal → escribir término → submit). 4 saltos entre la observación y la acción. El admin quiere un atajo CONTEXTUAL donde surge la necesidad.

Concretamente: cuando revisa la lista de pendientes o aprobadas y reconoce una que NO debería traducirse (porque es un evento, marca, persona recurrente), quiere un solo click para convertirla en exclusión y moverla.

Mismo argumento aplica a la tabla de Aprobadas: si descubre (después de aprobarla) que es una exclusión válida, debe poder convertirla sin abandonar el contexto.

## What Changes

### 1. Botón "Excluir" por fila en Pendientes y Aprobadas

- Nueva columna o nuevo botón en la misma celda "Acciones" de cada fila en ambas tablas.
- Click → modal centrado (no recargar la página) pre-llenado con:
  - **Término**: editable, valor inicial = `c.wrong_text` tal como aparece (Eloquent lo entrega con el case original). El admin puede ajustar (típicamente lowercase el LLM devuelve "Open english" → admin baja a "open english"; o ajustar frase completa como "the Black friday" → "Black Friday").
  - **Notas**: opcional, valor inicial = `"Agregada desde pendientes — corrección #<id>: <wrong_text> → <correct_text>"` para auditoría.
- Al guardar:
  - POST `/ia/correcciones/protected-terms` (endpoint existente del change 2026-08-01-corrections-protected-terms-admin).
  - 201 → toast verde "Exclusión '...' agregada". Modal cierra.
  - 422 → toast rojo con mensaje del backend (típico: "ya existe").
- **No archiva la corrección**: el admin decide manualmente qué hacer con la pendiente (aprobar / rechazar) por separado. La exclusión es paralela — bloquea el LLM a futuro sin tocar la fila actual.

### 2. Bulk "Excluir N seleccionadas" en Pendientes y Aprobadas

- Cuando hay selección > 0, aparece un botón adicional "Excluir N" en la barra de bulk.
- POST al endpoint store con un array de términos (reuso el mismo endpoint pero ampliando el body): `{terms: [{term, notes}, ...]}`.
- Para evitar modal con 50 inputs: el modal bulk es minimal — solo un checkbox "Concatenar notas con índice (corrección #1, #2...)" + un textarea único de nota compartida (default: "Bulk desde pendientes").
- Si algún término es duplicado, los demás se crean y el backend responde 207 Multi-Status con `{created: [...], skipped: [...]}` para que la UI muestre "10 excluidas, 2 duplicadas".
- Si TODOS son duplicados, 422.

### 3. Endpoint extended del cambio anterior

- El `protectedTermsStore` actual acepta `{term, category, notes}`. Lo extendemos a:
  - `{term, category?, notes?}` — como hoy (mantener compatibilidad).
  - `{terms: [{term, category?, notes?}, ...]}` — modo bulk.
- Validación por ítem: ignora silenciosamente duplicados activos (no falla todo el lote), retorna array de resultados.
- Si el body es bulk, response 201 con `{created: [...]}` o 207 con `{created: [...], skipped: [{term, reason}]}`.

### 4. UI: nuevo modal "Excluir este término" + manejo de modal bulk

Alpine state:
- `showExcludeModal: false`, `excludeSaving: false`, `excludeForm: { term: '', notes: '', wrongOriginal: '', cId: null }`.
- `showExcludeBulkModal: false`, `excludeBulkForm: { sharedNote: '', includeIndex: true }`.
- Métodos: `openExcludeFor(c)` pre-llena el modal, `openExcludeBulk()` cuando hay selección, `submitExclude()`, `submitExcludeBulk()`.

### 5. Spec delta

- 1 ADDED Requirement en `transcription-corrections`: "El admin puede convertir cualquier fila de Pendientes o Aprobadas en exclusión dinámica con un solo click, tanto para filas individuales como para selección bulk".

## Non-goals

- **No archivamos la corrección original**: el admin decide aparte (aprobar / rechazar). La conversión a exclusión es paralela.
- **No agregamos categoría en el modal de fila individual**: la metadata (categoría) es responsabilidad del subpanel Exclusiones; acá pre-llenamos solo término + nota de auditoría, sin fricción de selects.
- **No modificamos el subpanel Exclusiones**: solo añadimos atajos en otros lugares.
- **No hay un REST endpoint nuevo**: reusamos `protectedTermsStore` extendiendo el body.
- **No diferenciamos entre "excluir" y "excluir + rechazar"**: son acciones separadas, ámbos accesibles.

## Impact

- **Specs affected**: `transcription-corrections` (1 ADDED Requirement).
- **Code affected (modificados)**:
  - `app/app/Http/Controllers/Ia/CorreccionesController.php` (`protectedTermsStore` extendido a bulk)
  - `app/resources/views/ia/correcciones/index.blade.php` (botones + modales + métodos Alpine)
  - `openspec/specs/transcription-corrections/spec.md` (delta)
- **Migrations**: ninguna.
- **Riesgos**: bajo. La endpoint ya existe y valida duplicados; el modal usa el mismo flow. Si el admin hace click masivo sin querer, puede archivar después desde el subpanel.
- **Costes**: cero. Sin llamadas LLM nuevas.

## Open questions (resueltas)

- **¿Por fila o bulk?** Ambos.
- **¿Pre-fill editable?** Sí, `c.wrong_text` editable.
- **¿Bulk usar modal compartido?** Sí, con nota compartida + opción de enumerar índices.
- **¿Eliminar la corrección pendiente al excluirla?** No — son acciones independientes. El admin puede aprobar/rechazar después como siempre.
