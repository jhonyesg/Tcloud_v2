## Purpose

Hace que el módulo `Mis Archivos` comunique honestamente al usuario cuando el storage que está navegando está caído, leyendo el estado de accesibilidad que ya mantiene el módulo Storages, sin acoplarse a su lógica de negocio.

## ADDED Requirements

### Requirement: Files conoce la accesibilidad del storage activo

`GET /files?storage_id={id}` SHALL incluir en su respuesta JSON los campos `storage_accessible` (bool) y `storage_kind` (`local`/`external`). Estos campos SHALL derivarse de `storage_providers.is_accessible` y `storage_providers.kind`, no de una segunda consulta a disco.

#### Scenario: Listado normal con storage accesible

- **WHEN** `GET /files?storage_id=5` se ejecuta y `storage_providers[5].is_accessible=true`
- **THEN** el JSON SHALL contener `"storage_accessible": true, "storage_kind": "external"`
- **AND** SHALL NO renderizar banner de aviso

#### Scenario: Listado con storage caído

- **WHEN** `GET /files?storage_id=5` se ejecuta y `storage_providers[5].is_accessible=false`
- **THEN** el JSON SHALL contener `"storage_accessible": false, "storage_kind": "external"`
- **AND** `files/index.blade.php` SHALL renderizar un banner amarillo `bg-amber-50` con el texto "Disco 5 no disponible — los datos pueden estar desactualizados"

### Requirement: Banner desaparece al reescanear

El banner SHALL ocultarse automáticamente cuando `storage_accessible` pasa a `true` en la siguiente navegación, sin recarga completa. SHALL usar la respuesta JSON del endpoint como fuente.

#### Scenario: Navegación tras remontaje

- **WHEN** el usuario navega de una carpeta a otra en `Mis Archivos` tras remontar el disco
- **THEN** SHALL pedir el nuevo listado via `loadFiles()`
- **AND** SHALL ocultar el banner si la respuesta trae `storage_accessible=true`

### Requirement: Búsqueda respeta accesibilidad

Cuando `storage_accessible=false`, `GET /files?q=...` SHALL seguir funcionando pero SHALL incluir un campo `search_unreliable=true` para que el frontend advierta al usuario que los resultados pueden no corresponderse con el disco actual.

#### Scenario: Búsqueda en disco caído

- **WHEN** el usuario busca con un disco no accesible
- **THEN** SHALL devolver los archivos del último estado conocido en BD
- **AND** SHALL añadir `"search_unreliable": true` en la respuesta
- **AND** SHALL no impedir la búsqueda (el usuario tiene derecho a intentarlo)
