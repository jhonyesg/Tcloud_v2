## Purpose

Define cómo el admin concede, persiste y consulta el acceso por (cliente, storage) a los resultados que api-transcriptor produce (transcripciones + correcciones). Esta capacidad es **la base de orquestación** que el futuro módulo cliente "Mis Transcripciones" consumirá. No controla la transcripción del storage: eso sigue siendo decisión exclusiva de api-transcriptor.

## Requirements

### Requirement: Acceso a resultados de transcripción es por (cliente, storage)

El sistema SHALL registrar en `user_storages.transcription_access` (boolean, default `false`) si un cliente concreto tiene acceso a los resultados de transcripción del storage concreto. Dos clientes que comparten el mismo storage SHALL poder tener estados distintos. Activar o desactivar esta bandera SHALL **no** modificar `storage_providers.transcription_enabled`, ni el pipeline de transcripción, ni el corrector.

#### Scenario: Admin concede acceso al cliente A sobre el storage "11 Telepacifico"
- **WHEN** el admin hace POST `transcription_access = true` para `(cliente A, storage 11 Telepacifico)`
- **THEN** `user_storages(A, 11).transcription_access = true`
- **AND** `storage_providers(11).transcription_enabled` queda intacto
- **AND** el scanner no se ve afectado

#### Scenario: Dos clientes comparten un storage con accesos distintos
- **WHEN** los clientes A y B tienen asignado el storage "11 Telepacifico", A con acceso y B sin acceso
- **THEN** A consume los resultados de las transcripciones de ese storage; B no los consume
- **AND** el storage sigue transcribiendo con la misma bandera global, independiente del acceso por cliente

#### Scenario: Default opt-in es denegado
- **WHEN** se crea una nueva fila en `user_storages` o existe sin valor explícito
- **THEN** `transcription_access = false` por defecto (sin excepciones)

---

### Requirement: api-transcriptor y correcciones no son alcanzables desde esta pantalla

El sistema SHALL impedir que cualquier ruta, método o vista de `/ia/avisos-inteligentes` modifique `storage_providers.transcription_enabled`, el estado de los jobs de transcripción o cualquier dato del corrector. La única escritura permitida desde este módulo es sobre `user_storages.transcription_access`.

#### Scenario: No existe endpoint de encendido/apagado de storage aquí
- **WHEN** el admin busca un botón o endpoint que encienda o apague la transcripción de un storage desde `/ia/avisos-inteligentes`
- **THEN** no lo encuentra; esa acción solo existe en `/ia/api-transcriptor`

#### Scenario: El corrector sigue su flujo independiente
- **WHEN** el admin concede acceso a un storage
- **THEN** ninguna columna, endpoint o cron del corrector se dispara ni cambia

---

### Requirement: La ficha del cliente muestra storages asignados con toggle de acceso

El sistema SHALL listar en `/ia/avisos-inteligentes/{userId}` los storages asignados al cliente, cada uno con un toggle independiente que escribe `user_storages.transcription_access`. La ficha SHALL incluir, además, un banner read-only con el contador global "Api-Transcriptor: X/Y storages transcribiendo" tomado de `StorageProvider::transcriptionEnabled()`.

#### Scenario: Admin abre la ficha de un cliente con storages asignados
- **WHEN** el admin entra a `/ia/avisos-inteligentes/{userId}` y el cliente tiene storages asignados
- **THEN** ve cada storage con un toggle "Dar acceso a transcripciones" (off por defecto) y el banner con X/Y globales

#### Scenario: Admin concede acceso a un storage concreto
- **WHEN** el admin activa el toggle de un storage específico
- **THEN** el sistema persiste `transcription_access = true` en esa fila de `user_storages` y devuelve el nuevo estado

#### Scenario: Admin revoca acceso a un storage concreto
- **WHEN** el admin desactiva el toggle de un storage específico
- **THEN** el sistema persiste `transcription_access = false` en esa fila

#### Scenario: Toggle sobre un storage no asignado al cliente
- **WHEN** se hace POST al endpoint para un storage que ese cliente no tiene en `user_storages`
- **THEN** el sistema rechaza la petición y no modifica ninguna fila

#### Scenario: Storage globalmente apagado con acceso concedido
- **WHEN** un storage tiene `storage_providers.transcription_enabled = false` pero el cliente tiene `transcription_access = true` sobre él
- **THEN** la ficha lo muestra con un hint explícito: "Api-Transcriptor no está produciendo aquí. El acceso se aplicará cuando vuelva a habilitarse"

#### Scenario: Concesión es uno a uno, sin acciones masivas
- **WHEN** el admin abre la ficha del cliente
- **THEN** ve toggles individuales por storage; no hay controles de selección múltiple ni "Aplicar a todos"

---

### Requirement: El índice de Avisos Inteligentes muestra cobertura de acceso por cliente

El sistema SHALL listar en `/ia/avisos-inteligentes` los clientes con el conteo "storages con acceso / storages asignados" como columna de cobertura de acceso.

#### Scenario: Listado con cobertura
- **WHEN** el admin abre el índice
- **THEN** ve por cliente `storages con acceso / storages asignados` — p.ej. "Punto 24 / 123", "sigloprensa 0 / 12"

#### Scenario: Filtros existentes se conservan
- **WHEN** el admin usa la búsqueda por usuario/email o el filtro de módulo activo/inactivo
- **THEN** siguen funcionando como antes del cambio

---

### Requirement: Contrato de datos para el futuro módulo cliente

El sistema SHALL exponer la siguiente consulta como contrato estable que el futuro módulo cliente "Mis Transcripciones" consumirá sin cambios: para un usuario autenticado U, los resultados visibles son la unión de `transcriptions` cuyo `storage_provider_id` aparece en `user_storages` donde `user_id = U AND transcription_access = true AND storage_providers.enabled = true`. Por cada transcripción, los segmentos visibles son los `TranscriptionSegment` con `text` corregido y metadatos `start_seconds`/`segment_index`.

#### Scenario: Cliente con acceso a 3 storages consulta el día actual
- **WHEN** el futuro módulo cliente pide las transcripciones del día actual para U con acceso a storages {S1, S2, S3}
- **THEN** el sistema responde solo con las transcripciones cuyo `storage_provider_id ∈ {S1, S2, S3}`; las de cualquier otro storage quedan excluidas aunque la transcripción exista

#### Scenario: Cliente sin acceso no ve nada
- **WHEN** U no tiene ningún `user_storages.transcription_access = true`
- **THEN** el contrato responde conjunto vacío, sin filtrar por storage concreto

#### Scenario: El storage se desasigna del cliente
- **WHEN** se elimina la fila `user_storages(U, S)`
- **THEN** la bandera `transcription_access` desaparece con la fila (no hay dato huérfano) y el contrato deja de incluir S para U

---

### Requirement: Ningún bulk / ninguna siembra automática

El sistema SHALL NO ofrecer acciones masivas de concesión o revocación de `transcription_access` desde la UI. SHALL NO ejecutar ninguna siembra inicial que encienda la bandera por defecto al deployar la migración: todas las filas de `user_storages` arrancan en `false` y se prenden manualmente.

#### Scenario: No hay botón "Aplicar a todos"
- **WHEN** el admin abre la ficha del cliente o el índice
- **THEN** no existe ningún botón que encienda o apague `transcription_access` para más de un storage a la vez

#### Scenario: Post-deploy todo está en false
- **WHEN** se ejecuta la migración `add_transcription_access_to_user_storages`
- **THEN** todas las filas existentes quedan con `transcription_access = false` y no se genera ningún evento ni efecto colateral
