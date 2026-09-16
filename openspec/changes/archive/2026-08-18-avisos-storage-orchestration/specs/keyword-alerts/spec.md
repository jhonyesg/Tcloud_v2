# Spec: Orquestación de transcripción por cliente en Avisos Inteligentes

## ADDED Requirements

### Requirement: La transcripción se habilita por (cliente, storage), no por storage

El sistema SHALL registrar en `user_storages.transcription_enabled` si un cliente concreto tiene contratada la transcripción sobre un storage concreto. Dos clientes que comparten el mismo storage SHALL poder tener estados distintos.

#### Scenario: Un cliente contrata y el otro no
- **WHEN** el cliente A y el cliente B tienen asignado el storage "11 Telepacifico" y el admin habilita transcripción solo para A
- **THEN** `user_storages(A, Telepacifico).transcription_enabled = true` y `user_storages(B, Telepacifico).transcription_enabled = false`
- **AND** B no recibe avisos de ese storage ni ve señal alguna de que A los recibe

#### Scenario: Desactivar a un cliente no afecta a los demás
- **WHEN** un storage lo comparten 6 clientes con transcripción activa y el admin la desactiva para uno
- **THEN** los otros 5 conservan `transcription_enabled = true` y el storage sigue transcribiendo

---

### Requirement: `storage_providers.transcription_enabled` es un valor derivado

El sistema SHALL mantener `storage_providers.transcription_enabled` como la existencia de al menos un `user_storages` con `transcription_enabled = true` para ese storage, recalculándolo tras cada escritura sobre `user_storages`.

#### Scenario: El primer cliente que contrata enciende el storage
- **WHEN** un storage sin ningún cliente con transcripción recibe la primera habilitación
- **THEN** `storage_providers.transcription_enabled` pasa a `true` y el storage entra en el pipeline

#### Scenario: El último cliente que cancela apaga el storage
- **WHEN** se desactiva la transcripción del único cliente que la tenía sobre un storage
- **THEN** `storage_providers.transcription_enabled` pasa a `false` y el storage deja de transcribirse

#### Scenario: Desasignación masiva de clientes recalcula la bandera
- **WHEN** se eliminan las asignaciones de un storage mediante query builder masivo (sin eventos de modelo)
- **THEN** el punto de escritura invoca `StorageTranscriptionSync::recalculate()` y la bandera queda consistente

#### Scenario: Reconciliación detecta deriva
- **WHEN** se ejecuta `php artisan avisos:sync-storage-transcription --dry-run`
- **THEN** el sistema reporta cuántos storages tienen la bandera desalineada, sin escribir nada

---

### Requirement: El admin habilita transcripción desde la ficha del cliente

El sistema SHALL permitir al admin activar y desactivar la transcripción de cada storage asignado a un cliente desde `/ia/avisos-inteligentes/{user}`, sin poder asignar ni desasignar storages desde esa pantalla.

#### Scenario: Activar transcripción sobre un storage asignado
- **WHEN** el admin abre la ficha de "Punto" y activa "01 Radio FM Bogota"
- **THEN** el sistema marca el pivote en `true`, recalcula la bandera del storage y devuelve el estado nuevo

#### Scenario: Toggle sobre un storage no asignado al cliente
- **WHEN** se hace POST a `/ia/avisos-inteligentes/{userId}/storages/{storageId}/transcription` para un storage que ese cliente no tiene en `user_storages`
- **THEN** el sistema rechaza la petición y no modifica ninguna fila

#### Scenario: La pantalla no asigna storages
- **WHEN** el admin abre la ficha de un cliente
- **THEN** solo ve los storages ya asignados, sin controles para agregar ni quitar acceso (eso sigue en `/admin/storages`)

---

### Requirement: El índice de Avisos Inteligentes muestra la cobertura por cliente

El sistema SHALL listar en `/ia/avisos-inteligentes` los clientes con el número de storages con transcripción sobre el total asignado.

#### Scenario: Listado con cobertura
- **WHEN** el admin abre el índice
- **THEN** ve por cliente `storages con transcripción / storages totales` — p.ej. "Punto 24 / 123", "sigloprensa 0 / 12"

#### Scenario: Los filtros existentes se conservan
- **WHEN** el admin usa la búsqueda por usuario/email o el filtro de módulo activo/inactivo
- **THEN** siguen funcionando como antes del cambio

## MODIFIED Requirements

### Requirement: Admin puede activar "Avisos Inteligentes" por usuario con cupo

El sistema SHALL permitir al admin registrar una fila en `user_alerts_inteligentes` por usuario con `enabled=true`, `keywords_quota` y `emails_quota`. El cupo de keywords SHALL ser **uno solo por cliente** (global), independiente del número de storages que tenga asignados.

#### Scenario: Activar módulo para un usuario nuevo
- **WHEN** el admin asigna el módulo a un usuario con `enabled=true` y `keywords_quota=200`, `emails_quota=3`
- **THEN** se crea la fila en `user_alerts_inteligentes`, el módulo aparece en `/ia/avisos-inteligentes` y el usuario comienza a ver la entrada "Mis Avisos" en su sidebar

#### Scenario: Desactivar módulo de un usuario
- **WHEN** el admin marca `enabled=false` para un usuario
- **THEN** la entrada "Mis Avisos" desaparece del sidebar del usuario (en la siguiente request) y se detiene el envío de nuevas alertas

#### Scenario: El cupo no se multiplica por storage
- **WHEN** un cliente tiene 123 storages asignados y `keywords_quota=200`
- **THEN** su techo total sigue siendo 200 keywords, no 200 por storage

---

### Requirement: El switch de transcripción de `/ia/api-transcriptor` es de solo lectura

El sistema SHALL mostrar en `/ia/api-transcriptor` el estado de transcripción de cada storage como indicador derivado, sin permitir escribirlo desde esa pantalla.

#### Scenario: El operador consulta el estado
- **WHEN** el operador abre `/ia/api-transcriptor`
- **THEN** ve qué storages transcriben, sin control de escritura, con enlace a Avisos Inteligentes para cambiarlo

#### Scenario: La ruta de escritura anterior deja de existir
- **WHEN** se hace POST a `/ia/api-transcriptor/storages/{id}/toggle`
- **THEN** la ruta ya no está registrada
