## Why

El breadcrumb de Mis Archivos muestra la carpeta actual DOS veces seguidas en cualquier navegación a un folder, ej. `Disco_I > television > Telemedellin > 30092026 > 30092026`. La base de datos está limpia (la cadena `parent_id` no tiene auto-referencias ni duplicados), así que el bug es 100% de UI: el servidor devuelve el array `breadcrumbs` incluyendo la carpeta actual como último segmento, y el template Blade lo renderiza primero dentro del loop `x-for="crumb in breadcrumbs"` y luego OTRA vez en el bloque `x-if="currentFolderName"`.

El operador (jsuarez) lo reportó el 2026-09-30 después de subir archivos con éxito a una carpeta `30092026`. Es un bug cosmético pero erosiona la confianza en el módulo y dispara reportes falsos constantes.

## What Changes

- **`FileController::index`** (líneas 108-126): el array `breadcrumbs` SHALL contener **solo los ancestros** del `parent_id` solicitado, NO la carpeta actual. El CTE recursivo parte desde `parent_id`; la fila con `id = parent_id` se descarta del array resultante antes de hacer `array_reverse()`.
- Sin cambios en el template Blade. El bloque `x-if="currentFolderName"` queda como única fuente de la carpeta actual.
- Sin cambios en `FilesystemListingService` (modo FS-first): mismo contrato.
- **BREAKING**: cualquier consumidor que hoy asuma que el último elemento de `breadcrumbs` ES el current folder necesitará migrar a `currentFolderName` o `currentFolder`. Auditoría interna: solo `index.blade.php` lo consume hoy.

## Capabilities

### New Capabilities

- `files-folder-breadcrumb-chain`: contrato del array `breadcrumbs` que devuelve `/files` (AJAX) y `/files/index`: lista ordenada de ancestros de la carpeta actual, **excluyendo** la carpeta actual misma.

### Modified Capabilities

- `file-upload-ux`: la respuesta del endpoint `/files/upload` no incluye `breadcrumbs`, pero el modal de upload cierra y dispara un re-listado que sí lo usa. Sin cambio de requirement, pero se reusa la misma regla. Sin delta spec.

(El change abierto paralelo `fix-mis-archivos-breadcrumb-duplicates` ataca un caso distinto: auto-referencias reales en BD. No se fusiona con este change porque los síntomas visuales coinciden pero las causas raíz son diferentes.)

## Impact

**Código afectado**:
- `app/app/Http/Controllers/FileController.php` (modificación ~3 líneas en el bloque 108-126 del método `index`): descartar la primera fila del CTE (`id = parent_id`) antes de invertir el array.
- Sin migración de BD.
- Sin cambio en `FilesystemListingService` (FS-first): el servicio actual ya excluye el current folder del `parentChain` (verificar y aplicar el mismo patrón si difiere).

**Riesgos**:
- (R1) Cliente externo o test de integración que hoy asume `breadcrumbs[last] === currentFolder` rompe. Mitigación: grep audit en `app/` confirma que solo `index.blade.php` consume el array, y el template ya usa `currentFolderName` por separado.
- (R2) Si `FilesystemListingService::parentChain` ya hace algo distinto, hay que unificar contratos. Mitigación: el harness del change abierto `mis-archivos-fs-first` cubre este path.

**Sin breaking change observable para el usuario final**: la UI ya muestra `currentFolderName` como bloque separado, así que remover el current del array hace que se vea igual pero sin el duplicado.

**Rollback**: `git revert <commit>` restaura el comportamiento anterior (con duplicado visible). Sin datos que limpiar.
