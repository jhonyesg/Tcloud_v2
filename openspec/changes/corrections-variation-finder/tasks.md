## 1. Backend: endpoint de búsqueda

- [x] 1.1 Nuevo método `CorreccionesController::findVariations(Request $request)` que valida `word` (1-200 chars), `since` (ISO 8601 opcional) y `limit` (default 100, max 500).
- [x] 1.2 Construir query PostgreSQL: `SELECT id, text, created_at FROM transcription_segments WHERE text ILIKE '%' || $word || '%' [AND created_at >= $since] LIMIT 10000`.
- [x] 1.3 Por cada match, calcular la ventana de contexto ±25 chars alrededor del primer match del word en `text` (lower()). Usar `mb_strpos` para multibyte.
- [x] 1.4 Normalizar la ventana: `lowercase + trim + collapse whitespace + collapse punctuation`. Agrupar por esa key.
- [x] 1.5 Pre-cargar correcciones existentes via `Correction::whereIn('wrong_normalized', array_keys($grouped))` y mergear al resultado (`is_approved_rule`, `is_pending_rule`, `existing_rule_id`).
- [x] 1.6 Ordenar por `count DESC`, slice `[:limit]`. Si el total de matches crudos == 10000, agregar `truncated: true` + `next_since: <oldest result + 1s>`.
- [x] 1.7 Registrar ruta `POST /ia/correcciones/variations/find` en `app/routes/web.php`.

## 2. Backend: endpoint de creación bulk

- [x] 2.1 Nuevo método `CorreccionesController::bulkCreateFromVariations(Request $request)` que recibe `{ variants: string[], correct: string }`.
- [x] 2.2 Validar `correct` (1-500 chars) y `variants` (1-100 items).
- [x] 2.3 Para cada variant: normalizar, verificar que NO exista regla con mismo `wrong_normalized`. Si existe, abortar todo con HTTP 422 y nombre conflictivo.
- [x] 2.4 En una transacción DB: crear N reglas con `status=pending`, `proposed_by=session('user_id')`, `source='variation-finder-<YYYY-MM-DD>'`.
- [x] 2.5 Devolver `{ created: int, correction_ids: int[] }`.
- [x] 2.6 Registrar ruta `POST /ia/correcciones/variations/bulk-create`.

## 3. Frontend: nueva tab + form de búsqueda

- [x] 3.1 Tab button "Variation Finder" agregado entre IA Suggest y AI Suggest Results (estilo verde esmeralda para distinguir del morado de IA).
- [x] 3.2 Panel con input `word`, select `since` con optgroup Horas/Días/all + botón "Buscar variantes".
- [x] 3.3 Tabla de resultados: checkbox | Variante | Frecuencia | Estado (badge) | Acción. Header checkbox "seleccionar todas las sin regla".
- [x] 3.4 Action buttons condicionales según `is_approved_rule` / `is_pending_rule` / ninguno.
- [x] 3.5 Botón "Crear corrección para N seleccionadas" visible cuando hay checkboxes marcadas.

## 4. Frontend: modal de creación individual

- [x] 4.1 Modal con campo `wrong` readonly pre-llenado + `correct` vacío + estado error + spinner.
- [x] 4.2 Botón "Crear pendiente" llama al endpoint bulk-create con 1 variant.
- [x] 4.3 Al éxito: toast + cerrar modal + actualizar la fila in-place a `is_pending_rule`.
- [x] 4.4 pendingCount++ para feedback en el header.

## 5. Frontend: modal de creación bulk

- [x] 5.1 Modal con input `correct` + lista scrolleable de `wrong` por variante.
- [x] 5.2 Botón "Crear N pendientes" llama a bulk-create con todas las seleccionadas.
- [x] 5.3 Al éxito: toast + cerrar modal + marcar cada fila como pending.
- [x] 5.4 Al error 422 (duplicate): mostrar el error en el modal sin cerrarlo.

## 6. Tests

- [x] 6.1 `tests/Feature/CorrectionsVariationFinderTest.php` con 13 tests cubriendo:
  - normalizeVariant (3 tests: lowercase/trim/collapse, acentos, empty)
  - findVariations validation (6 tests: empty word, missing word, too long, no DB, limit too large, limit at max)
  - bulkCreateFromVariations (4 tests: validates input, empty variants, empty correct, too many)
- [x] 6.2 Suite Filter=Correc: 175 tests, 2 failures pre-existentes (verificado con git stash que no son de este change).

## 7. Verificación manual post-deploy

- [ ] 7.1 Login como admin.
- [ ] 7.2 Abrir `/ia/correcciones` → tab "Variation Finder".
- [ ] 7.3 Buscar "Pellas" con scope 8h → ver variantes con frecuencias.
- [ ] 7.4 Buscar "Pellas" con scope "Todos los históricos" → ver `truncated: true` (si >10k matches).
- [ ] 7.5 Click "Crear corrección" en una variante sin regla → modal abre con wrong pre-llenado.
- [ ] 7.6 Seleccionar 3 sin regla → click "Crear para 3" → completar correct → confirmar → ver 3 pending en el tab Pendientes.

## 8. Cierre

- [ ] 8.1 Commit con mensaje `feat(corrections): variation finder tab for targeted variant discovery`.
- [ ] 8.2 Merge + deploy.
- [ ] 8.3 `openspec archive corrections-variation-finder`.
