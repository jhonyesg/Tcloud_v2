## ADDED Requirements

### Requirement: Estado de navegación persiste entre recargas
El sistema SHALL guardar la posición de navegación actual en `localStorage` cada vez que el usuario navega, y restaurarla al cargar la página.

#### Scenario: Recarga desde carpeta anidada
- **WHEN** el usuario está dentro de un storage en una subcarpeta y recarga la página
- **THEN** el módulo restaura el mismo storage, la misma carpeta y los breadcrumbs correctos, y carga los archivos de esa carpeta

#### Scenario: Recarga desde vista de storages
- **WHEN** el usuario está en la pantalla de selección de storages y recarga
- **THEN** el módulo arranca en la vista de storages (sin storage seleccionado)

### Requirement: Navegación a raíz limpia el estado guardado
El sistema SHALL eliminar el estado guardado de `localStorage` cuando el usuario navega explícitamente a la raíz.

#### Scenario: Click en Volver a Storages limpia el estado
- **WHEN** el usuario hace click en "Volver a Storages" o en el breadcrumb raíz
- **THEN** el estado en `localStorage` es eliminado y la próxima recarga arranca en la raíz

### Requirement: Modo de vista de archivos persiste
El sistema SHALL recordar si el usuario prefería vista de cuadrícula o lista y restaurarla al recargar.

#### Scenario: Preferencia de vista persiste
- **WHEN** el usuario cambia a vista de lista y recarga
- **THEN** la vista de lista sigue activa después de la recarga
