## 1. Auditoría del patrón problemático

- [x] 1.1 Ejecutar `grep -rn 'meta\[name="csrf-token"\]' /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app/resources/views/` y listar todos los archivos afectados.
- [x] 1.2 Para cada coincidencia encontrada, confirmar visualmente que está dentro de un atributo Alpine `x-data="{ ... }"` (no en HTML suelto) antes de proceder.

**Resultado auditoría:** único archivo vulnerable = `app/resources/views/admin/storages.blade.php:218`. Las demás 40 ocurrencias están en `<script>` normales (no son afectadas por el bug).

## 2. Fix de la vista principal de Storages

- [x] 2.1 En `app/resources/views/admin/storages.blade.php` línea 218, reemplazar `'meta[name="csrf-token"]'` por `'meta[name=csrf-token]'`.
- [x] 2.2 Verificar que no haya otros pares de `"` literales dentro del bloque `x-data` (líneas 6–401) usando `rg '"' app/resources/views/admin/storages.blade.php`.

## 3. Fix de vistas adicionales (si aplica)

- [x] 3.1 ~~Si la auditoría (1.1) encontró el patrón en otras vistas (p. ej. `admin/storage-users.blade.php`), aplicar el mismo reemplazo `'meta[name="csrf-token"]' → 'meta[name=csrf-token]'` en cada una.~~ **NO APLICA** — la auditoría no encontró otras vistas vulnerables. Las 40 ocurrencias restantes del patrón están en `<script>` normales (no en `x-data`), donde el `"` interno no rompe nada.
- [x] 3.2 Documentar en el commit message la lista completa de archivos tocados.

## 4. Limpieza de caché y validación

- [x] 4.1 Ejecutar `cd /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app && php artisan view:clear` para forzar la recompilación de la plantilla Blade.
- [x] 4.2 (Opcional) `php artisan cache:clear` si la vista se sirve vía cache de config.

## 5. Validación manual en navegador

- [x] 5.1 Iniciar sesión como admin en `https://cloud.mediaserver.com.co/login`. *(Kilo no tiene credenciales; el usuario debe hacerlo)*
- [x] 5.2 Navegar a `/admin/storages` y confirmar: ✅ **Confirmado por el usuario (2026-09-05)**
  - [x] 5.2.1 El bloque de JS ya NO se renderiza como texto plano en la parte superior.
  - [x] 5.2.2 La tabla muestra los storages registrados con sus columnas (ID, Nombre, Tipo, Archivos, Estado, Acciones).
  - [x] 5.2.3 Los filtros (búsqueda, tipo, estado, por página) funcionan y refrescan la lista.
  - [x] 5.2.4 El botón "Crear Storage" abre el modal.
  - [x] 5.2.5 El botón "Usuarios" abre el modal de asignaciones.
  - [x] 5.2.6 El toast aparece correctamente tras "Probar" / "Re-verificar" / "Eliminar".
  - [x] 5.2.7 El botón "Guía" inicia el tour interactivo.
- [x] 5.3 Repetir 5.2 para cada vista adicional parcheada en el paso 3. *(No aplica — la auditoría no encontró otras vistas afectadas)*

**Validación parcial automatizada (Kilo):** compilación Blade del archivo post-fix muestra 0 `"` literales dentro del bloque `x-data` (antes del fix había 2). El atributo queda correctamente delimitado para el parser HTML.

## 6. Cierre del change

- [x] 6.1 Confirmar que no hay diffs sin commit (`git status` limpio en `app/resources/views/`). *Nota:* hay otros archivos modificados en el working tree de sesiones anteriores — fuera del scope de este cambio. El fix está aislado en una sola línea (`admin/storages.blade.php:218`).
- [x] 6.2 Reportar al usuario el resultado de la validación con screenshots o resumen verbal.
