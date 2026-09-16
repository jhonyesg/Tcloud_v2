## 1. Backend (ShareController)

- [x] 1.1 Añadir `permission` y `status` al array `SORT_FIELDS` en `app/app/Http/Controllers/ShareController.php`.
- [x] 1.2 Ampliar la regla `'sort'` en `validateListRequest()` para aceptar los nuevos campos.
- [x] 1.3 Añadir las ramas `'permission'` y `'status'` en el `match` de `shareQuery()`, emitiendo `orderByRaw` con `CASE` por nivel de permiso y por estado de expiración respectivamente. Mantener el tiebreaker `orderBy('shares.id','desc')` ya existente.

## 2. Frontend (Blade + Alpine)

- [x] 2.1 Convertir el `<th>` "Permiso" en `app/resources/views/shares/index.blade.php` en un botón clickeable que invoca `toggleSort('permission')` y muestra `sortIcon('permission')`.
- [x] 2.2 Convertir el `<th>` "Estado" en el mismo archivo en un botón clickeable con `toggleSort('status')` y `sortIcon('status')`.
- [x] 2.3 (Opcional UX) Actualizar el texto del tour interactivo en `startSharesTour()` para mencionar los nuevos encabezados clickeables.

## 3. Validación

- [x] 3.1 Verificar manualmente que `GET /shares?sort=permission&direction=asc` devuelve Lectura → Escritura/Subida → Completo. (Confirmado en runtime: asc=read, desc=upload/write)
- [x] 3.2 Verificar manualmente que `GET /shares?sort=status&direction=asc` agrupa Sin vencimiento → Activo → Expirado. (Confirmado en runtime tras crear 3 expirados de prueba: desc=expired primero, asc=never primero; revertido en BD)
- [x] 3.3 Verificar que `GET /shares?sort=foo` sigue devolviendo `422`. (Confirmado: lanza ValidationException por `validation.in`)
- [x] 3.4 Verificar que la paginación se mantiene estable. (Confirmado: tiebreaker `shares.id DESC` preservado; mismos IDs en orden estable al alternar asc/desc)

## 4. Cierre

- [x] 4.1 Ejecutar `openspec validate shares-sort-permission-status --strict`. (`Change 'shares-sort-permission-status' is valid`)
- [x] 4.2 Confirmar que el delta de `share-management` queda listo para archive.
