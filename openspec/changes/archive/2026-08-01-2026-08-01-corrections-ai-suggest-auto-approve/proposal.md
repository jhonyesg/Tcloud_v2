# Change: AI suggest auto-aprueba correcciones + búsqueda libre en tablas pending/approved

## Why

El admin pidió el 2026-08-01 (sesión actual):

> *"lo ideal es que este proceso que hacemos con Chrome, en vez de mandarlos como pendientes, los mande como aprobados, teniendo presente que el nombre de marcas, product, personas, se excluyen. Un ejemplo, la empresa Open English. Esa es una empresa, entonces se excluye también."*

Hoy el flujo `corrections:ai-suggest` (rule-based + LLM) termina SIEMPRE insertando `status='pending'` vía `CorrectionService::propose()`. El admin las aprueba a mano desde `/ia/correcciones` después. Ese paso manual es el cuello de botella que el admin quiere eliminar — la lógica de filtro defensivo (`looksLikeBrandOrProperNoun` + `protected_brands`) ya cubre la mayor parte de los falsos positivos, así que el auto-aprobado es razonable cuando la confianza es alta y los filtros defensivos pasan.

Problemas concretos a resolver:
1. **No hay path de auto-aprobado** desde `aiSuggestEnEsMix`. Hoy solo `propose()` (pending).
2. **"Open English" no está en `protected_brands`**. La memoria del admin lo tiene explícito y el filtro solo cubre marcas software/hardware + algunas colombianas. Empresa de enseñanza de inglés slipped; hay que agregarla explícitamente.
3. **No hay búsqueda libre** en la tabla de pendientes (solo filtro por `source`) ni en la tabla de aprobados (que ni siquiera tiene filtro). El admin busca texto y no encuentra.
4. **La tabla de aprobados se renderiza server-side vía Blade foreach** (sin AJAX), incoherente con la de pending que sí es AJAX. Esto bloquea búsquedas reactivas y filtros client-side.

Riesgos (mitigados en How):
- Falso positivo auto-aprobado → la capa defensiva de marcas + el "no proponer cambios sobre nombres propios" en el prompt + el post-filtro PHP son la primera línea. Aun así, cualquier error se puede revertir con el botón Eliminar de la tabla de aprobados.
- Runaway del LLM que aprueba 1000 entradas → el cache `alreadyProcessedToday` + el `withoutOverlapping(10)` de la cron limitan eso.
- Cambio de comportamiento en producción → el switch CLI `--auto-approve` + setting `auto_approve` en `LlmCorrectionSettings` permiten apagarlo en caliente desde `/ia/correcciones → AI Settings`.

## What Changes

### 1. Auto-aprobado del AI Suggest (`CorrectionService::aiSuggestEnEsMix`)

Cambiar el flujo de inserción:
- Añadir un parámetro `bool $autoApprove = false`. Cuando es true y el candidato pasa el `looksLikeBrandOrProperNoun` (ya en `LlmCorrectionSuggester::suggest()`), insertar con `status='approved'` directamente, seteando `approved_by` al user, `approved_at` a `now()`.
- Mantener el comportamiento pending como default para `corrections:mine-en-es` (regla del admin). Aunque hoy `mine-en-es` NO está en el path, el cambio aplica solo a `aiSuggestEnEsMix`.
- Idempotencia: `wrong_normalized` ya unique-checked contra approved existente (`isApproved()` del Llm suggester) y contra pending (`existingPending` del service). Si ya existe approved, no re-insertar.
- Bulk-action soporte: registrar el lote auto-aprobado igual que `bulk_approve` así el undo de 5 min sigue funcionando (insertar con `bulk_action_id = bulk-ai-<runId>`).

### 2. CLI flag `--auto-approve` en el comando (`AiSuggestEnEsCorrectionsCommand`)

- Nuevo flag `--auto-approve` (sin valor). Si está presente, llama a `aiSuggestEnEsMix(days, sample, admin, autoApprove: true)`.
- Mensaje de log final extendido: `AutoApproved: N | Mined/Inserted/Skipped/Rejected`.

### 3. Default configurable desde AI Settings (`LlmCorrectionSettings` + DB)

- Nueva key `auto_approve` (bool, default `true` para que el comportamiento pedido por el admin aplique desde ya; se explica abajo cómo apagarlo).
- Lectura en el comando: si CLI NO pasó `--auto-approve`, usar `$settings->bool('auto_approve')`.
- Toggle UI: input toggle en `/ia/correcciones → AI Settings`. Texto: "Auto-aprobar correcciones EN↔ES detectadas (recomendado si confías en el filtro de marcas)".

### 4. "Open English" agregado a `protected_brands`

- Editar `config/llm-correction.php` y agregar `'open english'`, `'openenglish'` (variantes) a la lista.
- También agregar categorías que el admin mencionó explícitamente:
  - Empresas de enseñanza de inglés mencionadas en emisiones: "Open English", "EF Education First", "British Council".
  - Productos/servicios tech que aparezcan en noticieros: "Netflix", "Spotify", "TikTok" (no aparecerán como candidatos pero como defensa ya están cubiertos por palabras inglés connues — ej. "in tiktok" sería propuesto como "en TikTok").
  - Personas en transcripciones de noticias colombianas frecuentes: por el momento ninguna — el filtro regex de "palabra capitalized en frase corta" + la lista de marcas + el prompt ya cubren la mayoría. **No agrego lista negra de personas a mano** porque requiere mantenimiento constante; en cambio, **fortalezco la heurística de "frase corta capitalized"** en `looksLikeBrandOrProperNoun` (bajar el umbral a 4 palabras y activar para frases de hasta 60 chars).

### 5. Tabla de aprobados a AJAX + búsqueda libre + filtro source (vista mejorada)

- Nuevo controller method `CorreccionesController::approved()` (GET `/correcciones/approved`). Devuelve `Correction::approved()->with('proposedBy','approvedBy')->orderByDesc('applies_count')->get()` con todos los campos que la tabla necesita.
- Blade: reemplazar el `foreach($approved)` server-side por `x-data.approved` cargado vía AJAX en `init()`.
- Reutilizar patrón de la tabla de pending: checkbox bulk, filtro source, búsqueda libre, conteos por source.
- Paginación: por ahora client-side (filtrar todo en memoria) — el limite hoy es <1000 reglas. Si crece a +5000 en producción, próximo change agrega paginación server-side.

### 6. Búsqueda libre en tabla de pendientes

- Input type=search arriba de la tabla, al lado del filtro source existente.
- Filtrado en `pendingFiltered` getter: `c.wrong_text` o `c.correct_text` que contengan el texto (case-insensitive).
- Indicador "X visibles / Y totales" cuando hay filtro activo.

### 7. Vista histórica separada "AI Suggest Results" (sub-tab dentro del módulo)

- Nueva tabla en `/ia/correcciones` que muestra SOLO las correcciones donde `source LIKE 'ai-suggest-%'`, separadas en columnas "Aprobadas" y "Pendientes" (las que quedaron pending por alguna razón: bulk rollback del undo, o si el admin apagó auto-approve temporalmente). Búsqueda + filtro por fecha del lote (`source`).
- Link desde el badge "AI Suggest" del header a esta nueva sub-tab.

### 8. Bulk-undo compatible (5 min)

- El servicio `aiSuggestEnEsMix` con `autoApprove=true` escribe cada corrección como aprobada por el admin actual. Si el LLM aprueba basura, el admin usa el flow de "Eliminar" de la tabla de aprobadas o el mismo patrón de undo de bulk.
- Para mayor robustez, agregar un helper `bulk_ai_suggest_action` que retorne un action_id y permita `undo_ai_suggest_bulk` (rechaza todas las auto-aprobadas por la corrida). Alcance: opcional, **fuera de scope** si el botón "Eliminar" por fila es suficiente. Decisión: dejar como follow-up.

### 9. Spec del módulo

- 1 MODIFIED Requirement en `transcription-corrections` para añadir auto-approve + reversibilidad.
- 2 ADDED Requirements:
  - "Open English y otras marcas hispanas de enseñanza de inglés están en la lista protegida".
  - "Las tablas de pendientes y aprobadas soportan búsqueda libre por texto y filtro por origen con conteos".

## Non-goals

- **No tocamos `corrections:mine-en-es`** — sigue inserting pending. El rule-based miner tiene más falsos positivos; el admin lo revisa en bulk desde la pestaña Pendientes con el filtro `mining-%`.
- **No cron-eamos retroactivo (`corrections:apply-run`)** — sigue 100% manual desde UI (ver `2026-08-01-corrections-apply-progress-visibility`).
- **No agregamos reentrenamiento del LLM**: si un patrón se vuelve recurrente, lo absorbe el diccionario approved (auto-crece); los prompts se versionan por `prompt_version` cuando haga falta.
- **No creamos una "vista de rechazadas"**: las rechazadas por filtro (marcas, etc.) viven solo en `rejected_by_filter` del log de cada corrida y en `storage/logs/ai-suggest-scheduled.log`. Acceder via endpoint de status (`ai-suggest-status`) si algún día se pide.
- **No paginamos server-side**: la tabla actual es <1000 aprobadas; si crece a +5000, follow-up.
- **No revertimos auto-aprobaciones específicas**: si una corrección auto-aprobada está mal, el admin usa "Eliminar" de la tabla de aprobadas (botón ya existente). El undo de bulk-ai-suggest queda como follow-up.
- **No tocamos los modos dry-run / preview**: siguen generando pending si se llama `propose()`. Solo cambia el path de inserción real.

## Impact

- **Specs affected**: `transcription-corrections` (1 MODIFIED + 2-3 ADDED Requirements).
- **Code affected (modificados)**:
  - `app/app/Services/Ia/CorrectionService.php` (`aiSuggestEnEsMix(int $days, int $sampleSize, User $by, bool $autoApprove = false): array`; helper de inserción directa con `status='approved'`)
  - `app/app/Console/Commands/AiSuggestEnEsCorrectionsCommand.php` (flag `--auto-approve`; lectura de `LlmCorrectionSettings::bool('auto_approve')`; log final con `AutoApproved`)
  - `app/app/Services/Ia/LlmCorrectionSettings.php` (nueva key `auto_approve: bool` en `SCHEMA` con default `true`)
  - `app/app/Http/Controllers/Ia/CorreccionesController.php` (`approved()` método nuevo + pasar `aiSuggestSettings.*` con `auto_approve` al cliente)
  - `app/app/Http/Controllers/Ia/CorreccionesController.php` (`aiSuggestSettings*` para incluir el toggle en API/UI)
  - `app/routes/web.php` (`Route::get('/correcciones/approved', ...)`)
  - `app/resources/views/ia/correcciones/index.blade.php` (tabla approved reescrita a AJAX + búsqueda libre en pending y approved + filtro por source en approved + UI toggle de auto-approve)
  - `app/config/llm-correction.php` (lista `protected_brands` extendida)
- **Code affected (nuevos)**: ninguno.
- **Migrations**: ninguna.
- **Costes operativos**: el toggle de auto-approve NO aumenta el coste del LLM (mismas llamadas), solo cambia el `status` de la fila insertada. Idempotencia contra `isApproved` existente evita duplicados si hay reintentos.
- **Riesgos**: bajo–medio. La defensa está en la combinación prompt + lista + post-filtro, los tres ya existentes. Si una marca nueva se cuela, agregar al array `protected_brands` y `php artisan config:clear`.

## Open questions (resueltas)

- **¿Quién decide si una corrección es auto-aprobable hoy?** Hoy, todas las que pasan el filtro (defensa triple). Si en el futuro se quiere un campo `confidence='high'|'normal'|'low'` para aprobar solo `high`, eso es un cambio futuro cuando el LLM devuelva `confidence` (hoy devuelve `confidence='normal'` fijo).
- **¿Y si el LLM aprueba una corrección que el admin no quiere?** Botón "Eliminar" de la tabla de aprobadas (ya existe).
- **¿Mine-en-es queda igual?** Sí. Solo AI Suggest cambia.
- **¿Open English desde cuándo queda excluido?** Desde hoy, en lista `protected_brands`.
- **¿Vista histórica de AI Suggest separada?** Sí, sub-tab nueva dentro del módulo.
- **¿Undo de 5 min para auto-aprobado masivo?** Out of scope. Botón Eliminar es suficiente.
