## ADDED Requirements

### Requirement: El filtro "Storage" del escaneo solo lista storages transcribiendo

El dropdown "Storage (opcional)" de la sub-ventana "Escaneo" SHALL listar únicamente los `StorageProvider` asignados al usuario de la sesión que tengan `storage_providers.transcription_enabled = true`. El endpoint que alimenta el dropdown SHALL ser dedicado del módulo Avisos Inteligentes (no SHALL reutilizar `/user/storages`, que sirve al módulo de Files y debe seguir devolviendo todos los storages del usuario). El filtro SHALL cubrir también el dropdown equivalente del modal de confirmación del escaneo. El criterio de inclusión SHALL ser la bandera global `transcription_enabled` (escrita únicamente desde API Transcriptor); la bandera por cliente `user_storages.transcription_access` SHALL NOT ser criterio de exclusión del dropdown.

#### Scenario: Dropdown muestra solo storages con transcription_enabled=true
- **WHEN** el admin abre la sub-ventana "Escaneo" y despliega el dropdown "Storage (opcional)"
- **AND** el usuario tiene 8 storages asignados, 5 con `transcription_enabled=true` y 3 con `transcription_enabled=false`
- **THEN** el dropdown lista exactamente los 5 storages con `transcription_enabled=true`
- **AND** la opción por defecto sigue siendo "Todos los storages"

#### Scenario: Dropdown se actualiza tras toggle en API Transcriptor
- **WHEN** el admin apaga un storage en API Transcriptor (`transcription_enabled: true → false`)
- **AND** recarga `/ia/avisos-inteligentes` o espera al siguiente refresh
- **THEN** el dropdown ya no muestra ese storage

#### Scenario: Files no se ve afectado
- **WHEN** el admin navega al módulo de Files
- **THEN** el árbol de archivos sigue mostrando todos los storages asignados al usuario (incluidos los que tienen `transcription_enabled=false`)

#### Scenario: Modal de confirmación usa el mismo conjunto filtrado
- **WHEN** el admin hace clic en "Escanear ahora" y abre el modal de confirmación
- **THEN** su dropdown "Storage (opcional)" lista exactamente los mismos storages que el dropdown del panel, no un conjunto más amplio

### Requirement: La pestaña Cobertura se acota a storages transcribiendo

La pestaña "Cobertura" (filtro dropdown, tabla de pares `(keyword, storage)` y botón "Activar histórico" / rewind) SHALL operar únicamente sobre los `StorageProvider` con `storage_providers.transcription_enabled = true` que estén asignados al usuario. La respuesta del endpoint de cobertura SHALL incluir el campo `storages` con la lista filtrada para alimentar el dropdown del filtro. Las filas de `keyword_scan_watermarks` cuyos `storage_provider_id` correspondan a storages apagados SHALL NO aparecer en la tabla ni ser objetivo del rewind. NO SHALL eliminarse las filas de `keyword_scan_watermarks` de storages apagados: solo dejan de incluirse en la respuesta paginada; si el storage vuelve a activarse, las filas reaparecen con su `scanned_until` previo.

#### Scenario: Dropdown del filtro Cobertura se puebla con storages transcribiendo
- **WHEN** el admin abre la pestaña "Cobertura"
- **THEN** el dropdown "Storage" del filtro muestra la lista de storages con `transcription_enabled=true`
- **AND** la opción por defecto "Todos los storages" sigue presente

#### Scenario: Tabla excluye pares de storages apagados
- **WHEN** la tabla Cobertura se carga y existe un par `(keyword_id=10, storage_provider_id=7)` en `keyword_scan_watermarks`
- **AND** el storage 7 tiene `transcription_enabled=false`
- **THEN** ese par NO aparece en la respuesta paginada

#### Scenario: Rewind no es invocable sobre un storage apagado
- **WHEN** la tabla Cobertura no muestra ningún par del storage 7 (porque está apagado)
- **THEN** el botón "Activar histórico" no es alcanzable para pares de ese storage desde la UI
- **AND** un POST directo a `/scan/rewind` con `storage_provider_id=7` sigue siendo aceptado por el endpoint si el par existe en BD (la UI no lo invoca; el backend no es responsable de este guardrail en esta entrega)

#### Scenario: Reactivar un storage hace reaparecer sus pares
- **WHEN** el admin reactiva un storage en API Transcriptor (`transcription_enabled: false → true`)
- **AND** recarga la pestaña Cobertura
- **THEN** los pares de `keyword_scan_watermarks` para ese storage vuelven a aparecer en la tabla con su `scanned_until` previo
