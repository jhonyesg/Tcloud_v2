## MODIFIED Requirements

### Requirement: AI suggest inserta correcciones con estado configurable (pending o approved)

El sistema SHALL permitir al admin configurar vía `LlmCorrectionSettings::bool('auto_approve')` (override desde `/ia/correcciones → AI Settings`) o vía flag CLI `--auto-approve` en `corrections:ai-suggest`, si las correcciones detectadas por el suggester LLM-powered se insertan como `pending` (revisión manual) o como `approved` (aplicación automática a SRT nuevos + retroactivo manual). Cuando auto-approve está activo, las correcciones SHALL insertarse con `status='approved'`, `approved_by` igual al admin invocante, `approved_at` igual a `now()`, y se registrar el total auto-aprobado en el log final del comando (`Auto-approved: N`). El filtro defensivo (`LlmCorrectionSuggester::looksLikeBrandOrProperNoun` + lista `protected_brands`) SHALL seguir aplicando antes de la inserción. Las correcciones rechazadas por el filtro SHALL contar en `rejected_by_filter` y NUNCA insertarse, ni en auto-approve ni en pending. La reversión de un auto-aprobado erróneo SHALL hacerse con el botón "Eliminar" de la tabla de aprobadas (operación existente).

#### Scenario: Admin corre `corrections:ai-suggest` con auto-approve activo
- **WHEN** el setting `auto_approve=true` y el LLM devuelve un candidato "in las ofertas nunca termina" → "en las ofertas nunca termina"
- **THEN** la fila se inserta en `corrections` con `status='approved'`, `approved_by=<admin>`, `approved_at=<timestamp>`, `source='ai-suggest-YYYY-MM-DD'`
- **THEN** el log final imprime `Auto-approved: 1` además de `Inserted: 1`
- **THEN** la nueva corrección se aplica automáticamente al próximo `corrections:apply-run` retroactivo y al próximo SRT nuevo (vía `CorrectionService::applyToSegments`)

#### Scenario: Admin apaga auto-approve en AI Settings
- **WHEN** el admin cambia el toggle `auto_approve` a false desde `/ia/correcciones → AI Settings`
- **THEN** la próxima corrida (manual o cron) inserta con `status='pending'`, mostrando la fila en la pestaña "Pendientes" para revisión

#### Scenario: Auto-aprobado inserta basura por falso positivo del filtro
- **WHEN** una corrección auto-aprobada resulta ser errónea (ej. false positive que el filtro defensivo no detectó)
- **THEN** el admin abre la pestaña "Aprobadas", selecciona la fila y usa "Eliminar" (botón existente en cada fila)
- **THEN** la fila desaparece del diccionario activo y deja de aplicar a SRT nuevos

#### Scenario: Open English es marca y queda excluida
- **WHEN** un segmento contiene "Open English" como término
- **THEN** cualquier candidato cuyo `wrong` matchee Open English (completo o sub-frase) es rechazado por `looksLikeBrandOrProperNoun` antes de inserción
- **THEN** `Open English` queda intacta en el `text` corregido

---

## ADDED Requirements

### Requirement: `protected_brands` incluye empresas hispanas de enseñanza de inglés y otras marcas regionales
La lista `protected_brands` en `config/llm-correction.php` SHALL incluir explícitamente: `'open english'`, `'openenglish'`, `'ef education first'`, `'british council'`, `'epm'`, `'isa'`, `'grupo argos'`, `'nutresa'`, además de las marcas software/hardware/medios ya existentes. El admin SHALL poder agregar nuevas entradas sin tocar código más allá del config (rotación rápida ante incidentes). El prompt del sistema y el post-filtro PHP SHALL consumir esta misma lista como única fuente de verdad.

#### Scenario: Open English entra a la lista
- **WHEN** el admin agrega 'open english' a `config/llm-correction.php` y corre `php artisan config:clear`
- **THEN** `LlmCorrectionSuggester::looksLikeBrandOrProperNoun('Open English')` retorna `true` (incluyendo variantes de capitalización)
- **THEN** el prompt del sistema contiene "do NOT propose changes on: open english" en la lista de marcas protegidas

#### Scenario: Empresa regional queda excluida
- **WHEN** un segmento contiene "EPM" o "ISA" como sigla
- **THEN** el post-filtro regex de "sigla todo mayúsculas" los marca como `looksLikeBrandOrProperNoun=true` y no se traducen al español

---

### Requirement: Tablas de pendientes y aprobadas soportan búsqueda libre y filtro por origen
Las pestañas **Pendientes** y **Aprobadas** del módulo `/ia/correcciones` SHALL exponer: (1) un input `<input type="search">` que filtra en tiempo real las filas cuyo `wrong_text` o `correct_text` contenga el texto (case-insensitive), (2) un dropdown de filtro por `source` con conteo por source y opción "Todos", (3) un indicador "X visibles / Y totales" cuando hay filtro activo. La pestaña Aprobadas SHALL cargarse vía AJAX (`GET /correcciones/approved`) con el mismo patrón que la de Pendientes (reemplazando el render server-side anterior).

#### Scenario: Admin busca "Open English" en la tabla de aprobadas
- **WHEN** el admin escribe "open english" en el campo de búsqueda de la pestaña Aprobadas
- **THEN** las filas cuyo `wrong_text` o `correct_text` contengan "open english" se muestran; las demás se ocultan
- **THEN** el indicador muestra "0 visibles / N totales"

#### Scenario: Admin filtra por source=ai-suggest en Pendientes
- **WHEN** el admin selecciona `source='ai-suggest-YYYY-MM-DD'` en el dropdown
- **THEN** solo las correcciones de ese lote se muestran
- **THEN** el indicador muestra "M visibles / N totales"

#### Scenario: Pestaña Aprobadas carga vía AJAX
- **WHEN** el admin hace click en la pestaña "Aprobadas"
- **THEN** se dispara `GET /correcciones/approved` y la tabla se puebla desde la respuesta JSON (sin recargar la página)
- **WHEN** hay 0 aprobadas
- **THEN** se muestra "No hay correcciones aprobadas"

---

### Requirement: Sub-tab "AI Suggest Results" muestra historial con búsqueda libre
El módulo `/ia/correcciones` SHALL exponer una sub-tab "AI Suggest Results" accesible desde el sidebar, alimentada por `GET /correcciones/ai-suggest-results`, que retorna: (1) el resumen de las últimas 5 corridas AI Suggest (`source`, `last_run_at`, `model`, `count_auto_approved`, `count_rejected_filter`), (2) la lista de correcciones auto-aprobadas por AI Suggest con búsqueda libre. La sub-tab SHALL mantener la misma estética de tabla y soportar filtro por fecha del lote.

#### Scenario: Admin revisa historial de auto-aprobaciones
- **WHEN** el admin hace click en "AI Suggest Results"
- **THEN** la página muestra dos secciones: "Resumen de corridas" (5 últimas) y "Auto-aprobadas" (tabla con búsqueda libre)
- **THEN** el admin puede buscar por texto (`wrong_text` o `correct_text`) y filtrar por `source` (fecha del lote)
- **THEN** las correcciones auto-aprobadas incorrectas pueden eliminarse con el mismo botón "Eliminar" que las demás
