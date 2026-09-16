## REMOVED Requirements

### Requirement: Cabecera de la sub-tab Trabajos lista los seis estados con su contador

**Reason**: La spec documenta el panel colapsable en la cabecera de la sub-tab Trabajos que lista `pending`, `queued`, `processing`, `done`, `error`, `dead` con contador leído de `GET /ia/api-transcriptor/stats`. La pestaña Trabajos y el endpoint `/stats` se eliminan en el change `simplify-api-transcriptor-to-storage-and-config`.

**Migration**: Los conteos por estado siguen disponibles:
- Vía dashboard `/dashboard` → cards globales en el partial `_health.blade.php` (si existe) o el resumen tibio del dashboard (`dashboard-tiered-cache`).
- Vía SQL: `SELECT state, COUNT(*) FROM transcriptions GROUP BY state`.
- Vía el active change `transcriptor-cancel-stuck-old-jobs` que audita y limpia filas en `dead` y `error` desde CLI.