## 1. Aplicar whereNumber a 4 rutas DELETE

- [x] 1.1 `/papelera/{file}` (l.116): agregar `->whereNumber('file')`
- [x] 1.2 `/api-transcriptor/jobs/{id}` (l.187): agregar `->whereNumber('id')`
- [x] 1.3 `/avisos-inteligentes/{userId}/keywords/{keywordId}` (l.235): agregar `->whereNumber('userId')->whereNumber('keywordId')`
- [x] 1.4 `/mis-avisos/keywords/{keywordId}` (l.345): agregar `->whereNumber('keywordId')`

## 2. Verificación

- [x] 2.1 Playwright: DELETE con id no numérico en cada ruta → 404 ✓ (4/4)
- [x] 2.2 Playwright: DELETE con id numérico inválido → 404 desde el controller (no desde el route constraint) ✓

## 3. Archive

- [x] 3.1 Sync spec delta a `openspec/specs/ia-routing/spec.md` (nueva spec).
- [x] 3.2 `mv openspec/changes/audit-fix-delete-routes-where-number openspec/changes/archive/2026-09-07-audit-fix-delete-routes-where-number/`.
