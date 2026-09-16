# Tasks

- [x] 1. Cambiar default `resolveStatsCacheTtl` a 300s.
- [x] 2. Cambiar default `resolveHealthCacheTtl` a 120s.
- [x] 3. Limpiar SystemSettings legacy (60/30) en BD.
- [x] 4. Arreglar cleanup del harness (preservar/borrar en lugar de hardcodear 60/30).
- [x] 5. Actualizar AGENTS.md.
- [x] 6. Re-verificar harness pasa 21/21.
- [x] 7. Re-verificar Playwright: reload tras 200s tiene stats/health warm.

## Verificación operacional

- Playwright smoke_reload_after_wait.py: tras 200s, /stats 19ms warm, /health 71ms warm.
- Harness: 21/21 ✓.
- Console errors: 0.
