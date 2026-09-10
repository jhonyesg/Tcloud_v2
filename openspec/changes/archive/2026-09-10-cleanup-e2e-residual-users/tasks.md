# Tasks: limpieza de 31 usuarios `e2e_*`

## 1. Discovery (ya completado)
- [x] Identificar los 31 IDs (`250, 311–340`) en `users` con `username LIKE 'e2e_%'`.
- [x] Confirmar 0 referencias en 17 tablas operativas (`user_storages`, `user_keyword`, etc.).
- [x] Confirmar 13 referencias en `user_sessions` (todas de `e2e_avisos_admin`), vía FK CASCADE.
- [x] Confirmar FK behavior en `information_schema.referential_constraints` para todas las tablas que apuntan a `users`.
- [x] Trazar el flujo del dropdown para entender por qué aparecen primero en el top-30 alfabético.

## 2. Pre-flight (al ejecutar)
- [ ] Capturar timestamp exacto del momento del borrado (`date -u +"%Y-%m-%dT%H:%M:%SZ"`).
- [ ] `SELECT COUNT(*) FROM users WHERE username LIKE 'e2e_%';` → debe dar `31`.
- [ ] `SELECT COUNT(*) FROM user_sessions WHERE user_id IN (250, 311–340);` → debe dar `13`.
- [ ] (Opcional) Backup lógico selectivo si en el futuro el operador lo pide: `pg_dump -t users --where="username LIKE 'e2e_%'" tcloudstorage`.

## 3. Ejecución

```sql
BEGIN;
DELETE FROM users WHERE username LIKE 'e2e_%';
COMMIT;
```

- [ ] Ejecutar el SQL en `psql -h 127.0.0.1 -U cloud -d tcloudstorage`.
- [ ] Capturar el resultado: debe reportar `DELETE 31`.
- [ ] Anotar el `command tag` y los tiempos en el log del cambio.

## 4. Post-flight

- [ ] `SELECT COUNT(*) FROM users WHERE username LIKE 'e2e_%';` → debe dar `0`.
- [ ] `SELECT COUNT(*) FROM user_sessions WHERE user_id IN (250, 311–340);` → debe dar `0` (CASCADE).
- [ ] `curl http://cloud.mediaserver.com.co/admin/users/search?q=` y revisar que los primeros resultados ya no incluyen `@e2e_*`.
- [ ] Apertura manual en navegador: storage `01 Caracol Tv` → modal **"Usuarios del Storage"** → dropdown **"Asignar usuario"** debe mostrar usuarios reales del cliente (ej. `@ACR`, `@Massmedios`, `@Punto`, `@Stakeholders`, `@Multarchivo`) en los primeros puestos del listado alfabético.

## 5. Cierre del change

- [ ] Commit del OpenSpec change con los tres artefactos (`proposal.md`, `design.md`, `tasks.md`).
- [ ] `openspec archive 2026-09-10-cleanup-e2e-residual-users` después de validar la UI.

## 6. No-objetivos (explícitos)

- ❌ No tocar `StorageProviderController::searchUsers()` (decisión del usuario).
- ❌ No añadir filtro defensivo `WHERE email NOT LIKE '%@e2e.local'`.
- ❌ No crear spec nueva en `openspec/specs/` (es limpieza one-off, no capacidad del producto).
