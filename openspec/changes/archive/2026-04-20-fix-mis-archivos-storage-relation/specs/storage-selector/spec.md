# Storage Selector Capability

## Overview

Permite al usuario seleccionar un storage desde una lista de storages disponibles. La lista se obtiene del servidor y se sincroniza con el estado local para garantizar que el storage seleccionado sigue siendo válido.

## Requirements

### FR-001: Obtener storages disponibles
- El sistema debe exponer un endpoint `GET /api/user/storages`
- El endpoint debe devolver únicamente los storages asignados al usuario autenticado
- Cada storage incluye: `id`, `name`, `type`, `permissions`

### FR-002: Dropdown de selección de storage
- La vista "Mis Archivos" debe mostrar un dropdown con los storages disponibles
- El dropdown debe permitir cambiar entre storages
- El storage seleccionado debe persistir en localStorage

### FR-003: Validación del storage seleccionado
- Al cargar, si el `currentStorage` no existe en la lista de storages disponibles, se debe:
  - Limpiar el valor de `currentStorage`
  - Limpiar `localStorage` relacionado (`tcloud_storageId`)
  - Mostrar estado vacío con mensaje apropiado

### FR-004: Sincronización de estado
- Al seleccionar un storage, se deben recargar los archivos con el `storage_id` correspondiente
- Al cambiar de storage, se debe navegar al nivel raíz (`parent_id = null`)

### FR-005: Estado sin storages disponibles
- Si el usuario no tiene storages asignados, mostrar mensaje informativo
- No mostrar dropdown de storages si la lista está vacía

## Data Model

### API Response: GET /api/user/storages

```json
{
  "storages": [
    {
      "id": 1,
      "name": "Documents",
      "type": "local",
      "permissions": "full"
    }
  ]
}
```

## User Flows

### Flow 1: Carga normal con storage válido
1. Usuario navega a `/files`
2. Sistema obtiene storages disponibles via `GET /api/user/storages`
3. Si `currentStorage` está en localStorage, verificar que existe en lista
4. Sistema carga archivos del storage seleccionado

### Flow 2: Carga con storage eliminado
1. Usuario navega a `/files`
2. Sistema obtiene storages disponibles
3. `currentStorage` (de localStorage) no existe en la lista
4. Sistema limpia localStorage
5. Sistema selecciona automáticamente el primer storage disponible (si existe)
6. Sistema carga archivos del storage seleccionado

### Flow 3: Cambio de storage
1. Usuario abre dropdown y selecciona otro storage
2. Sistema guarda `currentStorage` en localStorage
3. Sistema limpia breadcrumbs y `currentFolder`
4. Sistema navega al nivel raíz del nuevo storage
5. Sistema carga archivos del nuevo storage

## Acceptance Criteria

- [ ] `GET /api/user/storages` devuelve los storages del usuario autenticado
- [ ] Dropdown muestra todos los storages disponibles del usuario
- [ ] Seleccionar un storage diferente recarga los archivos
- [ ] Si el storage en localStorage fue eliminado, se limpia el estado
- [ ] Si no hay storages disponibles, se muestra mensaje apropiado
- [ ] El estado persiste entre recargas de página
