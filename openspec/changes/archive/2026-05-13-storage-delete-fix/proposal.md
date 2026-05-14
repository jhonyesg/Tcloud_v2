## Why

Al intentar eliminar un storage desde el panel admin (`/admin/storages`), la operación falla silenciosamente cuando el storage tiene archivos asociados. El controller bloquea el delete con HTTP 400, pero el frontend no maneja ese error — no muestra ningún toast ni mensaje, el modal permanece abierto y el admin no sabe qué pasó.

Adicionalmente, el guard en el backend es innecesario: las tablas `user_storages` y `files` ya tienen `onDelete('cascade')` definido en sus migraciones, por lo que la BD elimina automáticamente asignaciones y archivos en cascada al borrar un storage.

Por último, la paginación de la tabla de storages solo ofrece 10 / 25 / 50 registros por página, insuficiente para administradores con muchos storages configurados.

## What Changes

**Backend — `StorageProviderController::destroy()`**
- Eliminar el guard `if ($storage->files()->exists()) { return 400; }` — la cascada de BD lo maneja correctamente.
- El método queda limpio: `findOrFail` → `delete()` → respuesta 200.

**Frontend — modal de confirmación de eliminación**
- Actualizar el texto de advertencia: de "Los archivos no serán eliminados, pero quedarán huérfanos" a "Se eliminarán permanentemente X archivos y todas las asignaciones de usuarios de este storage." (usando `deletingStorage.files_count` que ya viene en el JSON).
- Agregar manejo de error en `deleteStorage()`: si `!res.ok`, leer el body JSON y mostrar toast rojo con el mensaje.

**Frontend — selector de registros por página**
- Agregar opciones `100`, `250` y `500` al `<select x-model="perPage">`.
- Opciones finales: 10 / 25 / 50 / 100 / 250 / 500.

## Non-goals

- No migrar ni recuperar archivos huérfanos existentes (eso es trabajo de limpieza separado).
- No agregar soft-delete ni papelera de reciclaje para storages.
- No modificar la lógica de cascade a nivel de BD (ya está correcta).
- No cambiar la paginación de otras tablas del admin (solo storages).

## Affected Files

- `app/app/Http/Controllers/StorageProviderController.php` — método `destroy()`
- `app/resources/views/admin/storages.blade.php` — función `deleteStorage()`, modal de delete, select `perPage`
