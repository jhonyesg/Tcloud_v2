# Tasks: atajo "Excluir" en Pendientes y Aprobadas

## 1. Backend: extender `protectedTermsStore` a bulk

- [ ] En `app/app/Http/Controllers/Ia/CorreccionesController.php`:
  - Modificar `protectedTermsStore(Request $request, CorrectionProtectedTermsService $svc)`:
    - Detectar si el body es bulk: `$request->has('terms') && is_array($request->input('terms'))`.
    - Modo single (como hoy): `{term, category?, notes?}` → devuelve 201 con `{ok, item}`.
    - Modo bulk: para cada ítem, intentar `$svc->add(...)`. Recolectar resultados.
    - Si todos creados: 201 con `{created: [...], skipped: []}`.
    - Si algunos duplicados: 207 (o 201 con flag) `{created: [...], skipped: [{term, reason}]}`.
    - Si todos duplicados o falla total: 422.
  - Mantener compatibilidad con el caller del subpanel Exclusiones (que envía {term, category, notes}).
- [ ] `php -l` validar.

## 2. UI: botón "Excluir" por fila en Pendientes

- [ ] En `app/resources/views/ia/correcciones/index.blade.php`:
  - En la celda "Acciones" de cada fila de la tabla de Pendientes, agregar botón "Excluir" (estilo amber/secondary para no confundir con Aprobar verde / Rechazar rojo).
  - Click → `openExcludeFor(c)`.

## 3. UI: botón "Excluir" por fila en Aprobadas

- [ ] Misma mecánica para la fila de la tabla de Aprobadas. Botón "Excluir" en la celda Acciones.

## 4. UI: bulk "Excluir N seleccionadas"

- [ ] En la barra de bulk de Pendientes (donde aparece "Aprobar N") agregar botón "Excluir N" solo cuando hay selección > 0.
- [ ] Mismo para Aprobadas (junto a "Eliminar N").

## 5. UI: Modal single (pre-llenado editable)

- [ ] Definir Alpine state:
  - `showExcludeModal: false`
  - `excludeSaving: false`
  - `excludeError: ''`
  - `excludeForm: { term: '', notes: '', cId: null }`
- [ ] Modal con:
  - Input "Término a excluir" editable, autocapitalize=off, autocorrect=off.
  - Textarea "Nota" opcional.
  - Botones "Guardar" / "Cancelar".
  - Toast verde si 201, rojo si 422 (duplicado).
- [ ] Método `openExcludeFor(c)`: pre-llena `term = c.wrong_text`, `notes = "Agregada desde pendientes — corrección #<id>: <wrong> → <correct>"`.
- [ ] Método `submitExclude()`: POST al endpoint; manejar 201/207/422; toast.

## 6. UI: Modal bulk (nota compartida)

- [ ] Modal más simple: textarea "Nota compartida" + checkbox "Enumerar notas con índice (#1, #2…)".
- [ ] POST al mismo endpoint con `{terms: [{term, notes}, ...]}` derivado de las filas seleccionadas.
- [ ] Mostrar resultado: "X creadas, Y duplicadas".

## 7. Hook: `loadExclusiones()` desde éxito

- [ ] Si la exclusión se crea desde un atajo (no desde el subpanel), NO refrescar exclusiones para no spammear. La lista se actualiza en cache 5min y al reingresar al subpanel.
- [ ] Excepción: si el admin tiene el subpanel abierto y crea desde pendientes, refrescar.

## 8. Verificación

- [ ] Smoke con curl admin autenticado:
  - POST con `{terms: [{term: 'test1', notes: ''}, {term: 'test2', notes: ''}]}` → 201 con 2 ids.
  - Repetir el mismo POST → 207 con 2 skipped (duplicados).
- [ ] UI: en `/ia/correcciones`, ir a Pendientes, click "Excluir" en una fila → modal pre-llenado → guardar → toast verde.
- [ ] UI: seleccionar 3 pendientes, click "Excluir 3" → modal bulk → guardar → toast "3 excluidas".

## 9. Spec delta

- [ ] Append 1 ADDED Requirement al spec canónico.

## 10. Archivar

- [ ] Mover a `openspec/changes/archive/2026-08-01-2026-08-01-corrections-protected-terms-shortcut/`.
