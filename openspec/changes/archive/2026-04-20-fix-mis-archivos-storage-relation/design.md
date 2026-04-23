## Context

El módulo "Mis Archivos" actualmente:
- Recibe `storage_id` solo via URL parameter o localStorage
- No tiene UI para que el usuario vea sus storages disponibles
- No se actualiza cuando la relación usuario-storage cambia (eliminación/agregado)

Necesidad del usuario: Ver los storages como CARPETAS en la raíz de "Mis Archivos", tal como se muestra en sistemas de archivos convencionales. Al hacer clic en un storage, se muestra su contenido.

## Goals / Non-Goals

**Goals:**
- Mostrar los storages del usuario como carpetas en la raíz de "Mis Archivos"
- Al hacer clic en un storage, navegar a su contenido (archivos/carpetas)
- Eliminar un storage → desaparece de la vista raíz
- Agregar un storage → aparece en la vista raíz
- Validación automática contra staleness del localStorage

**Non-Goals:**
- Modificar la lógica de eliminación de storages (cascade delete ya funciona en BD)
- Agregar real-time updates (WebSocket/polling)

## Decisions

### 1. Estructura de navegación

**Decisión:** Los storages se muestran como carpetas en la raíz. Al hacer clic en un storage, se navega a su contenido.

```
┌─────────────────────────────────────────────┐
│ Mis Archivos                                │
├─────────────────────────────────────────────┤
│  Raíz                                        │
│  ├── [📁 Storage A]  ← clic navega a A     │
│  ├── [📁 Storage B]  ← clic navega a B     │
│  └── [📁 Storage C]  ← clic navega a C     │
└─────────────────────────────────────────────┘

Después de clic en Storage A:
┌─────────────────────────────────────────────┐
│ Mis Archivos                                │
├─────────────────────────────────────────────┤
│  Raíz > Storage A                           │
│  ├── [📁 Carpeta 1]                         │
│  ├── [📁 Carpeta 2]                         │
│  └── [📄 Archivo.pdf]                       │
└─────────────────────────────────────────────┘
```

### 2. Endpoint de storages

**Decisión:** Reusar el endpoint existente `GET /user/storages` que ya devuelve los storages del usuario.

### 3. Estado en Alpine.js

- `currentStorage`: ID del storage actualmente navegado (null = raíz mostrando storages)
- `currentFolder`: ID de carpeta actual dentro del storage
- `availableStorages`: Lista de storages disponibles (para validar currentStorage)
- `viewMode`: `'storages'` cuando está en raíz, `'files'` cuando está dentro de un storage

### 4. Flujo de navegación

1. **Raíz (`currentStorage === null`):**
   - Llamar `GET /user/storages`
   - Mostrar storages como carpetas
   - Breadcrumbs: solo "Raíz"

2. **Dentro de storage (`currentStorage` set):**
   - Llamar `GET /files?storage_id=X`
   - Mostrar archivos/carpetas del storage
   - Breadcrumbs: "Raíz > [Nombre del Storage]"

3. **Navegación entre carpetas:**
   - Igual que antes, navegar por parent_id

### 5. Validación de storages

- Al cargar, si `currentStorage` no existe en `availableStorages`, volver a raíz
- Al eliminar storage, el usuario vuelve a la raíz automáticamente

## API Design

### GET /user/storages (existente)

**Response:**
```json
{
  "storages": [
    { "id": 1, "name": "Documents", "type": "local", "permissions": "full" }
  ]
}
```

### GET /files?storage_id=X

Mantiene comportamiento actual.

## Data Flow

```
┌─────────────────────────────────────────────────────────────────┐
│  Mis Archivos se carga                                          │
└─────────────────────────────────────────────────────────────────┘
          │
          ▼
┌─────────────────────────────────────────────────────────────────┐
│  init() → loadStorages() → GET /user/storages                   │
└─────────────────────────────────────────────────────────────────┘
          │
          ▼
┌─────────────────────────────────────────────────────────────────┐
│  Si currentStorage es null                                      │
│    → Mostrar storages como carpetas (viewMode = 'storages')    │
│                                                                  │
│  Si currentStorage tiene valor                                  │
│    → Validar que existe en availableStorages                   │
│    → Si no existe: volver a raíz                               │
│    → Si existe: cargar archivos (viewMode = 'files')           │
└─────────────────────────────────────────────────────────────────┘
```

## Frontend Changes (index.blade.php)

1. `viewMode: 'storages' | 'files'` - Determina qué mostrar
2. `storageName` - Nombre del storage actual para breadcrumbs
3. `loadStorages()` - Carga lista de storages
4. `enterStorage(storageId, storageName)` - Entra a un storage
5. `navigateToRoot()` - Vuelve a la raíz de storages
6. Modificar `loadFiles()` para limpiar viewMode y entrar a modo archivos
7. Modificar UI para conditionally mostrar storages o archivos

## Risks / Trade-offs

- [Risk] **Sin real-time**: Si un admin elimina un storage mientras el usuario tiene la página abierta, no se enterará hasta recargar.
  → **Mitigation**: Validación automática contra la lista del servidor al cargar.

## Open Questions

- ¿El usuario necesita crear archivos/carpetas directamente en la raíz de storages? (actualmente requiere storage_id)

