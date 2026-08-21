# Tasks: Interactive Tours for Finalized Modules

## 1. Compartidos (`shares/index.blade.php`)

- [ ] Add purple "Tour" button with `fa-map-marked-alt` in the header next to "Actualizar".
- [ ] Include `<script src="/js/interactive-tour.js"></script>` at the bottom of the view.
- [ ] Implement `startSharesTour()` with 5 steps:
  1. Intro (center)
  2. Stats bar (bottom)
  3. Toolbar filters (bottom)
  4. Share row actions (left)
  5. Access logs (bottom)
- [ ] Verify selectors exist when DOM is fully loaded (post-Alpine init).

## 2. Usuarios (`admin/users.blade.php`)

- [ ] Add purple "Tour" button in the header.
- [ ] Include `interactive-tour.js` script at the bottom.
- [ ] Implement `startUsersTour()` with 6 steps:
  1. Intro (center)
  2. User table (bottom)
  3. Create user button (bottom)
  4. Edit modal fields (left or center)
  5. Media Editor toggle (left)
  6. Delete warning (center)
- [ ] Ensure `onShow` closes any open modals before highlighting table rows.

## 3. Storage (`admin/storages.blade.php`)

- [ ] Add purple "Tour" button in the header.
- [ ] Include `interactive-tour.js` script at the bottom.
- [ ] Implement `startStoragesTour()` with 6 steps:
  1. Intro (center)
  2. Filters toolbar (bottom)
  3. Storage row (left)
  4. Users assignment button (bottom)
  5. Create storage button (bottom)
  6. Test connection icon (left)

## 4. Editor de Medios (`admin/media-editor.blade.php`)

- [ ] Add purple "Tour" button in the header.
- [ ] Include `interactive-tour.js` script at the bottom.
- [ ] Implement `startMediaEditorTour()` with 6 steps:
  1. Intro (center)
  2. Stats cards (bottom)
  3. User table (bottom)
  4. Toggle switch (left)
  5. Clip limit input (bottom)
  6. RAM disk usage (bottom)
- [ ] Add `onShow` to wait for `loading === false` so table is rendered.

## 5. Sesiones (`admin/sessions.blade.php`)

- [ ] Add purple "Tour" button in the header.
- [ ] Include `interactive-tour.js` script at the bottom.
- [ ] Implement `startSessionsTour()` with 5 steps:
  1. Intro (center)
  2. Session row (bottom)
  3. Kill session button (left)
  4. Kill all user sessions (left)
  5. Global settings form (bottom)

## 6. Redes (`admin/redis.blade.php`)

- [ ] Add purple "Tour" button in the header.
- [ ] Include `interactive-tour.js` script at the bottom.
- [ ] Implement `startRedisTour()` with 4 steps:
  1. Intro (center)
  2. Stats cards (bottom)
  3. Key list table (bottom)
  4. Flush buttons (left)

## 7. Correo (`admin/correo.blade.php`)

- [ ] Add purple "Tour" button in the header.
- [ ] Include `interactive-tour.js` script at the bottom.
- [ ] Implement `startCorreoTour()` with 6 steps:
  1. Intro (center)
  2. Tabs navigation (bottom)
  3. SMTP config form (bottom)
  4. Test connection button (left)
  5. Plantillas table (bottom) — `onShow: setTab('plantillas')`
  6. Log table (bottom) — `onShow: setTab('logs')`

## 8. Sites Externos (`admin/external-sites.blade.php`)

- [ ] Add purple "Tour" button in the header next to "Nuevo Site".
- [ ] Include `interactive-tour.js` script at the bottom.
- [ ] Implement `startExternalSitesTour()` with 5 steps:
  1. Intro (center)
  2. Site row (bottom)
  3. Users button (left)
  4. Edit/Delete actions (left)
  5. Create site modal (center)

## 9. Cross-module Verification

- [ ] Check that no view loads `interactive-tour.js` twice (if layout already includes it, skip).
- [ ] Verify all 8 modules render correctly on desktop and mobile.
- [ ] Ensure `TcloudTour` global is available on every targeted page.
- [ ] Smoke-test one tour end-to-end: start, navigate Next through all steps, dismiss.

## Estimates

- Each module: ~20–30 min (button + 4–6 steps + testing).
- Total: ~3.5–4 hours across 8 modules.
- Verification: ~30 min.

## No migrations or backend changes required.
