## Why

La vista admin `/admin/storages` (Blade `app/resources/views/admin/storages.blade.php`) muestra como texto plano casi todo el contenido del atributo Alpine `x-data="{ ... }"`, dejando la tabla de storages vacía y un bloque gigante de JavaScript visible en pantalla. La causa es una `"` literal dentro del JS que cierra el atributo HTML prematuramente para el parser del navegador.

Es un fix urgente: el módulo de Storages es la base desde la cual los admins gestionan backends de almacenamiento y asignación de usuarios, y actualmente es inutilizable desde la UI web.

## What Changes

- En `app/resources/views/admin/storages.blade.php:218`, reemplazar el selector `document.querySelector('meta[name="csrf-token"]')` por uno que no contenga comillas dobles literales dentro del atributo `x-data` (p. ej. `'meta[name=csrf-token]'` o un template literal con backticks).
- Validar visualmente que el JS del `x-data` ya no se renderiza como texto y que la tabla se puebla con los storages registrados.
- Auditar `apiFetch` y cualquier otra vista admin con `x-data="{ ... }"` (incluida `storage-users.blade.php`) en busca del mismo patrón `meta[name="csrf-token"]` para evitar que el bug se repita.

## Capabilities

### New Capabilities
- (ninguna)

### Modified Capabilities
- (ninguna — es un fix de rendering, no cambia el comportamiento especificado)

> Nota: se declara `skip_specs: true` en `.openspec.yaml` porque este cambio es puramente correctivo a nivel de plantilla y no introduce ni modifica requisitos de comportamiento a nivel de spec.

## Impact

- **Código afectado**: `app/resources/views/admin/storages.blade.php` (1 línea) y posiblemente `app/resources/views/admin/storage-users.blade.php` si contiene el mismo patrón.
- **Rutas afectadas**: `GET /admin/storages` (vista principal).
- **APIs**: ninguna — el endpoint `GET /api/admin/storages` no cambia.
- **Migraciones**: no requiere.
- **Dependencias externas**: ninguna.
- **Riesgo de regresión**: bajo. El cambio solo afecta cómo se codifica el selector CSS dentro de un atributo HTML; el comportamiento de la lógica JS es idéntico.

## Non-goals

- No se refactoriza el `x-data` para usar `Alpine.data()` global ni se reescribe la vista a un componente.
- No se modifica el endpoint `/admin/storages` ni los métodos del `StorageProviderController`.
- No se cambian los textos en español ni el formato del tour guiado.
- No se aborda el patrón `apiFetch` de forma transversal en otras vistas admin en este change (queda como follow-up si la auditoría lo requiere).
