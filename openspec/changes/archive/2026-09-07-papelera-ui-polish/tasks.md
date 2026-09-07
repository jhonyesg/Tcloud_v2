## 1. Backend — PapeleraService::statsFor

- [x] 1.1 Agregar método `statsFor(int $userId): array` que retorna `['total', 'urgent', 'critical', 'size_bytes', 'next_purge_date']`.
- [x] 1.2 Implementar queries: count total, count urgent (deleted_at >= urgentCutoff), sum size_bytes de items purgable (no linked), next_purge_date calculado en PHP.

## 2. Controller — PapeleraController::indexJson

- [x] 2.1 Modificar `indexJson` para que también llame a `$this->service->statsFor($user->id)` y lo incluya en la respuesta JSON bajo la clave `stats`.

## 3. View — papelera/index.blade.php

- [x] 3.1 Agregar Alpine state: `stats: { total, urgent, critical, size_bytes, next_purge_date }`, `filter: 'all'`.
- [x] 3.2 Inicializar `stats` con la respuesta JSON del servidor (cargar desde indexJson).
- [x] 3.3 Stat tiles: 4 tiles en grid 2x2 móvil / 1x4 desktop, mismo `rounded-lg border border-slate-200` que el resto.
- [x] 3.4 Filtros chips: `Todos`, `Por expirar (<3d)`, `Críticos (<1d)` con conteos en cada uno.
- [x] 3.5 Banner urgente: `x-show="stats.urgent > 0"`, color amber, link cambia filter.
- [x] 3.6 Progress bar por fila en la tabla: 100% / amber 10-30% / red <10%.
- [x] 3.7 Responsive: ocultar tabla en `< sm:`, mostrar cards. Ocultar cards en `≥ sm:`, mostrar tabla.
- [x] 3.8 Helper Alpine: `progressClass(item)` retorna clase Tailwind según porcentaje.
- [x] 3.9 Helper Alpine: `filteredItems()` retorna items según `filter`.

## 4. Verificación con Playwright

- [x] 4.1 Crear `tests/playwright_papelera_ui_polish.py`:
  - Stat tiles visibles con valores. (1, 1, 0 B, "hoy")
  - Progress bars presentes.
  - Click en `Por expirar` filtra la lista.
  - Banner urgente visible cuando urgent > 0.
  - En viewport 375x667: cards visibles, tabla oculta.
  - En viewport 1440x900: tabla visible, cards ocultas.
- [x] 4.2 Regresión: `playwright_papelera_view.py` (11/11), `playwright_papelera_help_panel.py` (17/17), `playwright_filter_trashed_from_browser.py` (11/11), `playwright_restore_cache_invalidation.py` (5/5), `playwright_papelera_ui_polish.py` (6/6).

## 5. Archive

- [x] 5.1 Sync spec delta a `openspec/specs/trash-module/spec.md`.
- [x] 5.2 `mv openspec/changes/papelera-ui-polish openspec/changes/archive/2026-09-07-papelera-ui-polish/`.
