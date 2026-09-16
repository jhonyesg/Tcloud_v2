## Why

El listado de "Mis Recursos Compartidos" ya permite ordenar ascendente/descendente por Recurso, Expira, Accesos y Creado, pero los encabezados **Permiso** y **Estado** siguen siendo etiquetas estáticas. Esto obliga al usuario a exportar o filtrar manualmente para revisar, por ejemplo, todos los enlaces de "Solo lectura" o todos los "Expirados" en bloque, una fricción innecesaria cuando ya existe la infraestructura de ordenamiento server-side para los demás campos.

## What Changes

- Hacer clickeables los encabezados "Permiso" y "Estado" en `shares/index.blade.php`, reutilizando el patrón existente (`toggleSort` + `sortIcon`) y añadiendo los nuevos valores `permission` y `status` al cliente.
- Ampliar la whitelist `SORT_FIELDS` del `ShareController`, su validación `sort` y la rama `match` para:
  - `permission` → orden por nivel real del permiso (Lectura → Escritura/Subida → Completo) usando `CASE`, no por orden alfabético de la cadena.
  - `status` → orden por estado de expiración (`Sin vencimiento` → `Activo` → `Expirado`) usando `CASE` sobre `expires_at`.
- Preservar el tiebreaker `orderBy('shares.id','desc')` para mantener la paginación determinista.
- Actualizar la spec `share-management` para reflejar los dos nuevos campos de orden soportados y dejar explícito el orden lógico (no alfabético) de `permission` y `status`.

No se introducen migraciones de BD ni cambios en la API pública. La validación existente (`'sort' => 'nullable|in:...'` y `'direction' => 'nullable|in:asc,desc'`) sigue garantizando que el backend rechace valores inválidos con `422`.

## Capabilities

### New Capabilities
- (ninguna)

### Modified Capabilities
- `share-management`: se añaden `permission` y `status` a la lista de campos de orden aceptados por el listado server-side y se documenta el orden lógico aplicado (no alfabético).

## Impact

- Controlador: `app/app/Http/Controllers/ShareController.php` (`SORT_FIELDS`, `validateListRequest`, `shareQuery`).
- Vista: `app/resources/views/shares/index.blade.php` (encabezados de tabla y objeto `sort` por defecto, sin nuevos archivos).
- Spec: `openspec/specs/share-management/spec.md` (delta para incorporar `permission` y `status` al requisito de ordenamiento).
- No requiere migración. No afecta endpoints ni el contrato JSON (`data` / `meta` / `counters` se mantienen).
- No hay cambios de performance significativos: las filas se filtran por `created_by = user.id` antes del orden, y los `ORDER BY` derivados usan expresiones sobre columnas ya indexadas (`idx_shares_owner_expires_at`).
