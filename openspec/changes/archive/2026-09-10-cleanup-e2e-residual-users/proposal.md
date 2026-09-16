# Proposal: Limpiar 31 usuarios residuales `e2e_*` que contaminan el dropdown de asignación de storages

## Why

Hoy al abrir la modal **"Usuarios del Storage"** en cualquier storage (ej. `01 Caracol Tv`), el dropdown **"Asignar usuario"** muestra `@e2e_dummy_11`, `@e2e_dummy_12`, `@e2e_dummy_13`, ... en lugar de los usuarios reales del cliente. El operador tiene que tipear el `@username` real en el campo de filtro para encontrar a quien quiere asignar.

Causa: `StorageProviderController::searchUsers()` (línea 215) responde con `User::orderBy('username')->limit(30)` cuando la query llega vacía — los 30 primeros usuarios alfabéticos llenan el dropdown. Resultado: como hay 30 usuarios `e2e_dummy_01..30` + `e2e_avisos_admin` creados el **2026-09-10 entre 02:49 y 02:59 UTC** (probable corrida de prueba de un agente automatizado), ocupan todo el top-30 y desplazan a los usuarios reales.

Verificación de que **no tienen valor operativo** (cero referencias en 17 tablas):

| Tabla | Filas referenciando los 31 e2e_* |
|-------|----------------------------------|
| `files` | 0 |
| `shares` | 0 |
| `user_storages` | 0 |
| `user_keyword` | 0 |
| `user_keyword_storage` | 0 |
| `user_alerts_inteligentes` | 0 |
| `mentions_exports` | 0 |
| `media_edit_jobs` | 0 |
| `keyword_matches` | 0 |
| `external_site_user` | 0 |
| `alert_deliveries` | 0 |
| `alert_logs` | 0 |
| `corrections` | 0 |
| `canales` | 0 |
| `keyword_categories` | 0 |
| `grabador_usuario` | 0 |
| `password_tokens` | 0 |
| `correo_log` | 0 |
| **`user_sessions`** | **13** (todas de `e2e_avisos_admin`, en cascada automática) |

**Total**: 31 users a borrar → 13 `user_sessions` eliminadas en cascada vía `ON DELETE CASCADE`. Ningún otro efecto colateral.

## What Changes

- **`DELETE FROM users WHERE username LIKE 'e2e_%';`** — operación única, transaccional, contra la base de datos `tcloudstorage`.
- Sin cambios de código (controller, modelos, vistas, rutas).
- Sin migración (no toca schema, solo datos).
- Sin nuevo spec (es limpieza one-off de datos, no una capacidad nueva del producto).

## Impact

### Capacidades afectadas
- **No hay capacidades nuevas ni modificadas.** Solo se eliminan filas que ya no tienen uso.
- El comportamiento del dropdown mejora como efecto secundario positivo: los usuarios reales vuelven a aparecer en el top-30 alfabético.

### Riesgos
- **Riesgo de pérdida de audit trail**: las 13 `user_sessions` de `e2e_avisos_admin` (IPs `186.119.128.186`, `192.168.1.1`, fechas `2026-09-09 22:06:59`–`23:34:54`) se eliminan con el user por la FK CASCADE. Si se requiere conservarlas, hacer backup selectivo antes. **Decisión del usuario (2026-09-10)**: borrar todo, sin backup selectivo.
- **No hay rollback a nivel de código.** Reversión solo vía restaurar el backup pre-flight de la BD. Los IDs (250, 311–340) quedan documentados en `design.md` y en el log de la ejecución.

### Decisión del usuario (2026-09-10)
- ✅ Borrar los 31 (incluido `e2e_avisos_admin`).
- ❌ No tocar el controller (`StorageProviderController::searchUsers` ni `users()`).
- ❌ No añadir filtro defensivo en queries (`WHERE email NOT LIKE '%@e2e.local'`).
