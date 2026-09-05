## 1. admin/users.blade.php — eliminar usuario

- [x] 1.1 Añadir `deletingUserId: null` al objeto `x-data`
- [x] 1.2 Añadir `:disabled` + spinner SVG inline + texto "Eliminando..." al botón "Eliminar" del modal de confirmación de borrado (línea ~333)
- [x] 1.3 Modificar `deleteUser(id)`: try/finally con `deletingUserId`, `await this.loadUsers()`, rama `else` con toast rojo

## 2. admin/external-sites.blade.php — eliminar site y quitar usuario

- [x] 2.1 Añadir `deletingSiteId: null` y `removingSiteUserKey: null` al `x-data`
- [x] 2.2 Añadir `:disabled` + spinner al botón de confirmación de eliminar site (encontrar el botón `@click="deleteSite(site)"` o equivalente)
- [x] 2.3 Modificar `deleteSite(site)`: try/finally con `deletingSiteId`, rama `else` con toast explícito
- [x] 2.4 Añadir `:disabled` + spinner al botón × de cada chip de usuario asignado
- [x] 2.5 Modificar `removeUser(u)`: try/finally con `removingSiteUserKey`, rama `else` con toast

## 3. admin/sessions.blade.php — cerrar sesiones

- [x] 3.1 Añadir `killingSessionId: null` al `x-data`
- [x] 3.2 Añadir `:disabled` + spinner al botón de cerrar sesión en cada fila (botones `killSession(id)`)
- [x] 3.3 Añadir `:disabled` + spinner al botón de cerrar todas las sesiones de un usuario (`killUserSessions(userId)`)
- [x] 3.4 Modificar `killSession(id)`: try/finally con `killingSessionId`, rama `else` con toast (actualmente falla en silencio)
- [x] 3.5 Modificar `killUserSessions(userId, userEmail)`: try/finally con `killingSessionId`, rama `else` con toast (actualmente falla en silencio)

## 4. admin/user-storages.blade.php — remover storage de usuario

- [x] 4.1 Añadir `removingStorageKey: null` al `x-data`
- [x] 4.2 Añadir `:disabled` + spinner al botón "Remover" de cada fila (botón `@click="removeAssignment(assignment.storage_provider_id)"` línea 135)
- [x] 4.3 Modificar `removeAssignment(storageId)`: try/finally con `removingStorageKey`, rama `else` con toast (actualmente falla en silencio)

## 5. admin/storage-users.blade.php — remover usuario de storage

- [x] 5.1 Añadir `removingStorageUserKey: null` al `x-data`
- [x] 5.2 Añadir `:disabled` + spinner al botón "Remover" de cada fila (botón `@click="removeAssignment(userId)"` — buscar el template del botón)
- [x] 5.3 Modificar `removeAssignment(userId)`: try/finally con `removingStorageUserKey`, rama `else` con toast (actualmente falla en silencio)

## 6. Validación manual por vista (confirmada por el usuario 2026-08-25)

- [x] 6.1 `users`: eliminar usuario de prueba → ver "Eliminando..." + toast verde; forzar error → toast rojo
- [x] 6.2 `external-sites`: eliminar site de prueba + quitar usuario asignado → ver spinner + toast
- [x] 6.3 `sessions`: cerrar sesión de prueba → ver spinner + toast verde; forzar error → toast rojo (NO silencio)
- [x] 6.4 `user-storages`: remover storage asignado a usuario de prueba → ver spinner + toast
- [x] 6.5 `storage-users`: remover usuario asignado a storage de prueba → ver spinner + toast
- [x] 6.6 Verificar que `admin/storages` sigue funcionando (no regresión — ya arreglado en change anterior)
- [x] 6.7 Verificar que `admin/correo` sigue funcionando (ya tenía su propio patrón `confirmDelete.loading`)

## 7. Commit

- [ ] 7.1 Commit único con mensaje `fix(admin): feedback visual en operaciones destructivas de users, external-sites, sessions, user-storages, storage-users` (conventional commit, scope `admin`)
