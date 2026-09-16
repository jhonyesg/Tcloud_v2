## 1. Estado Alpine y preparación

- [x] 1.1 Añadir `deletingStorageId: null` al objeto `x-data` en `app/resources/views/admin/storages.blade.php` (junto a `deletingStorage`)
- [x] 1.2 Añadir `removingUserAssignmentKey: null` al mismo objeto `x-data`

## 2. Botón Eliminar del modal de storage

- [x] 2.1 Reemplazar el botón "Eliminar" del modal (línea ~667) para incluir `:disabled="deletingStorageId === deletingStorage.id"` y clase `disabled:opacity-50`
- [x] 2.2 Añadir dos `<span>` dentro del botón: uno con texto "Eliminar" (`x-show="deletingStorageId !== deletingStorage.id"`) y otro con texto "Eliminando..." (`x-show="deletingStorageId === deletingStorage.id"`)
- [x] 2.3 Modificar `deleteStorage(id)` para: (a) `this.deletingStorageId = id` al inicio, (b) envolver el cuerpo en `try { ... } finally { this.deletingStorageId = null; }`, (c) hacer `await this.loadStorages()` antes del toast de éxito

## 3. Botón × de cada chip de usuario asignado

- [x] 3.1 Reemplazar el botón × del chip (línea ~709) para incluir `:disabled="removingUserAssignmentKey === usersModalStorage.id + '-' + a.user_id"` y clase `disabled:opacity-50`
- [x] 3.2 Dentro del botón × añadir un spinner SVG inline (`<svg x-show="removingUserAssignmentKey === ..." class="animate-spin ...">`) que se muestre solo cuando ese chip específico está procesando
- [x] 3.3 Modificar `removeAssignmentFromModal(userId)` para: (a) calcular `const key = this.usersModalStorage.id + '-' + userId` al inicio, (b) `this.removingUserAssignmentKey = key`, (c) envolver en `try { ... } finally { this.removingUserAssignmentKey = null; }`, (d) añadir rama `else` que muestre toast rojo con `err.error || 'Error al desvincular el usuario'`

## 4. Validación manual (confirmada por el usuario 2026-08-25)

- [x] 4.1 Crear un storage de prueba en `/admin/storages`, eliminarlo → verificar que el botón muestra "Eliminando...", que la lista refresca, y que aparece toast verde "Storage eliminado correctamente"
- [x] 4.2 Crear otro storage, asignarle un usuario desde el modal Usuarios, hacer click en × del chip → verificar spinner inline, toast verde "Usuario removido", chip desaparece
- [x] 4.3 Simular fallo: con DevTools Network en "Offline", intentar eliminar un storage → verificar toast rojo, botón liberado, modal permanece abierto
- [x] 4.4 Verificar que `testStorage()` sigue funcionando igual que antes (no regresión)
- [x] 4.5 Verificar que `Edit`, `Assign User` y `Assign All` / `Remove All` siguen funcionando igual que antes

## 5. Commit

- [ ] 5.1 Hacer commit con mensaje `fix(admin/storages): feedback visual en eliminar storage y desvincular usuario` (conventional commit, scope `admin/storages`)
