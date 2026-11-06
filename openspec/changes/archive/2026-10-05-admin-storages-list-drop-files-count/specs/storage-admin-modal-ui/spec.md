## ADDED Requirements

### Requirement: El modal de borrado obtiene el conteo de archivos bajo demanda

El modal de confirmación de borrado de storage SHALL mostrar el número de archivos asociados al storage seleccionado, obtenido mediante `GET /admin/storages/{id}` en el momento de abrir el modal — nunca a partir del listado. Mientras el conteo no esté disponible, SHALL mostrar un estado "Calculando archivos asociados…"; si el request falla, SHALL mostrar el texto genérico "los archivos asociados a este storage" sin inventar un número. El botón "Eliminar" SHALL permanecer habilitado en todos los casos.

#### Scenario: Conteo disponible al abrir el modal
- **WHEN** el admin hace clic en "Eliminar" sobre una fila de storage
- **THEN** el modal se abre mostrando el nombre del storage y un estado "Calculando archivos asociados…"
- **AND** al responder `GET /admin/storages/{id}`, el aviso muestra "…se eliminarán permanentemente: N archivo(s) asociado(s)" con el número exacto

#### Scenario: Conteo no disponible — fallback textual
- **WHEN** el request `GET /admin/storages/{id}` falla o no responde a tiempo
- **THEN** el aviso muestra "los archivos asociados a este storage" sin cifra
- **AND** el botón "Eliminar" sigue habilitado y el borrado procede normalmente

#### Scenario: El conteo no se pide al listar
- **WHEN** el admin carga `/admin/storages` (listado)
- **THEN** NO se dispara ningún `GET /admin/storages/{id}` por fila
- **AND** el conteo solo se solicita al abrir el modal de borrado de un storage concreto
