## Design: Interactive Tours for Finalized Modules

### Existing Tour Engine

The `TcloudTour` engine (`app/public/js/interactive-tour.js`) provides:
- SVG spotlight mask that darkens everything except the highlighted element.
- Directional tooltip (`top`, `bottom`, `left`, `right`, `center`) with progress bar.
- Navigation: Previous / Next / Skip / Finalizar.
- Per-step callbacks: `onShow` to manipulate Alpine state before highlighting.
- Global access via `window.TcloudTour.start({ steps: [...], onComplete: fn })`.

### Pattern to Replicate

Each module follows the existing pattern seen in `files`, `grabadores`, and `canales`:

1. **Tour button**: purple button with `fa-map-marked-alt` icon, placed in the header next to the title or primary actions.
2. **Script inclusion**: `<script src="/js/interactive-tour.js"></script>` at the bottom of the view.
3. **Tour function**: `start<Module>Tour()` that calls `TcloudTour.start({ steps: [...] })`.
4. **Steps**: 4–8 objects with `title`, `content`, `icon`, `color`, `selector`, `position`, and optional `onShow`.

### Module-Specific Designs

#### 1. Compartidos (`shares/index.blade.php`)

**Button placement**: next to "Actualizar" in the header.
**Steps**:
1. Intro — center, explains what shared resources are.
2. Stats bar — highlights total/active/expired/accesos cards.
3. Toolbar — filters by permission and status.
4. Share row — explains link, expiration, password, copy link.
5. Accesos — how to view access logs for a share.

**`onShow`**: none required (no tabs).

---

#### 2. Usuarios (`admin/users.blade.php`)

**Button placement**: in header next to title.
**Steps**:
1. Intro — center, user management overview.
2. User table — columns (username, role, quota, media editor).
3. Create user — "+ Nuevo" button.
4. Edit user — edit modal, quotas, role toggle.
5. Media Editor toggle — explains the switch column.
6. Delete — warning about permanent deletion.

**`onShow`**: ensure modals are closed before highlighting table rows.

---

#### 3. Storage (`admin/storages.blade.php`)

**Button placement**: in header next to title.
**Steps**:
1. Intro — center, explains storages as S3 backends.
2. Filters — type, status, search.
3. Storage row — name, type, enabled status.
4. Users assignment — "Usuarios" button per storage.
5. Create storage — "+ Nuevo Storage".
6. Test connection — explains the test icon.

**`onShow`**: none required (single view).

---

#### 4. Editor de Medios (`admin/media-editor.blade.php`)

**Button placement**: in header next to title.
**Steps**:
1. Intro — center, what the media editor does (clip extraction).
2. Stats cards — clips this month, total, active users, failed.
3. User table — enabling/disabling per user.
4. Toggle switch — click to enable/disable.
5. Clip limit — input and save.
6. RAM disk — usage bar and warning.

**`onShow`**: ensure `loading` is false so table is rendered.

---

#### 5. Sesiones (`admin/sessions.blade.php`)

**Button placement**: in header next to title.
**Steps**:
1. Intro — center, monitoring active sessions.
2. Session row — IP, user, device, last activity.
3. Kill session — "Cerrar" button.
4. Kill all user sessions — grouped action.
5. Global settings — max sessions and lifetime.

**`onShow`**: ensure settings form is visible if highlighting it.

---

#### 6. Redes (`admin/redis.blade.php`)

**Button placement**: in header.
**Steps**:
1. Intro — center, Redis as cache/queue backend.
2. Key stats — memory usage, connected clients.
3. Key list — searching and filtering keys.
4. Flush operations — warning about data loss.

**`onShow`**: none.

---

#### 7. Correo (`admin/correo.blade.php`)

**Button placement**: in header.
**Steps**:
1. Intro — center, SMTP and templates.
2. Tabs — Configuración / Plantillas / Logs.
3. SMTP form — host, port, user, SSL.
4. Test connection — "Probar Conexión" button.
5. Plantillas table — list of templates.
6. Log table — sent emails history.

**`onShow`**: `setTab('config')`, `setTab('plantillas')`, `setTab('logs')` as needed.

---

#### 8. Sites Externos (`admin/external-sites.blade.php`)

**Button placement**: in header next to "Nuevo Site".
**Steps**:
1. Intro — center, embedded sites for quick access.
2. Site row — icon, name, URL, status badge.
3. Users button — who can see each site.
4. Edit / Delete — actions column.
5. Create site — modal fields (name, URL, icon, color).

**`onShow`**: none.

### Technical Notes

- **Selectors**: All modules use Tailwind utility classes. Selectors should target semantic elements (`h1`, `table`, `button`) or existing `x-data` containers. Avoid brittle class chains.
- **Colors**: Use brand palette `#4654a8` (brand-500) or module-specific accent colors already present in each view.
- **Icons**: Font Awesome (`fas fa-*`) matching each step's topic.
- **Accessibility**: Tooltips are fixed-position, so they work on mobile if the target is scrolled into view first (`scrollIntoView` is handled by the engine).
- **No persistence**: Tours do not track completion; the user can restart anytime via the button.
