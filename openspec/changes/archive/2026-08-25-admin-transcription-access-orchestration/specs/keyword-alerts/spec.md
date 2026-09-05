## REMOVED Requirements

### Requirement: La transcripción se habilita por (cliente, storage), no por storage
**Reason**: Diseño roto y revertido el 2026-08-20 tras una caída de 44 horas. La columna `user_storages.transcription_enabled` se introdujo el 18-08 con la idea de que la habilitación de transcripción fuera por cliente, y `storage_providers.transcription_enabled` quedó como valor derivado. Una siembra fallida dejó 175 storages apagados y el scanner sin nada que recorrer. La columna se quitó el 20-08 y la bandera global volvió a ser autoritativa. Hoy no debe existir ninguna columna que apague/encienda la transcripción desde la lógica por cliente.
**Migration**: La orquestación admin de acceso a resultados se reescribe desde cero en la capacidad `admin-transcription-access`, con una columna nueva `user_storages.transcription_access` (default `false`) que **no** controla la transcripción del storage, solo la visibilidad de los resultados para el cliente. `storage_providers.transcription_enabled` sigue siendo la bandera autoritativa del pipeline, escrita únicamente desde `ApiTranscriptorController::toggleStorage`.

### Requirement: `storage_providers.transcription_enabled` es un valor derivado
**Reason**: Incorrecto. `storage_providers.transcription_enabled` es la bandera autoritativa y NO se deriva de ninguna columna per-cliente. La derivación se eliminó precisamente porque un fallo de siembra dejó la plataforma sin transcripción durante 44 horas.
**Migration**: El control del flag global sigue siendo exclusivo de `/ia/api-transcriptor` (`ApiTranscriptorController::toggleStorage`). Cualquier intento de modificarlo desde otro módulo está prohibido.

### Requirement: El admin habilita transcripción desde la ficha del cliente
**Reason**: La habilitación de transcripción es decisión operativa del storage, no del cliente. No debe existir un control que encienda o apague `storage_providers.transcription_enabled` desde la ficha del cliente.
**Migration**: La ficha del cliente (`/ia/avisos-inteligentes/{userId}`) ahora gestiona exclusivamente `user_storages.transcription_access` — la visibilidad de los resultados ya producidos. La habilitación de la transcripción del storage se hace únicamente desde `/ia/api-transcriptor`. Ver capacidad `admin-transcription-access`.

### Requirement: La ficha del cliente informa qué canales se transcriben, sin controlarlo
**Reason**: Parcialmente obsoleto. La afirmación "sin controlarlo" ya no es cierta: la ficha ahora tiene un control (el toggle de `transcription_access`). La parte informativa sobre el estado global del storage pasa al banner read-only con X/Y, no al badge por storage.
**Migration**: Ver capacidad `admin-transcription-access` para el nuevo diseño de la ficha. El conteo "X/Y globales" se muestra en un único banner, no repetido por storage.

### Requirement: El índice de Avisos Inteligentes muestra la cobertura por cliente
**Reason**: Reemplazado por una métrica distinta. Antes mostraba "storages con transcripción / totales" basado en la derivación rota. Ahora muestra "storages con acceso / totales" basado en `transcription_access`.
**Migration**: Ver capacidad `admin-transcription-access` (`El índice de Avisos Inteligentes muestra cobertura de acceso por cliente`).

---

## ADDED Requirements

### Requirement: KeywordMatcher respeta `transcription_access` por storage

El sistema SHALL limitar la creación de `KeywordMatch` y el envío de emails de alerta a los usuarios que tengan `transcription_access = true` para el `storage_provider_id` de la transcripción recién completada. Si el usuario tiene el módulo de avisos activo pero no tiene acceso a ese storage concreto, el sistema SHALL NO crear match ni enviar email para esa transcripción. El filtro aplica desde el deploy; los `keyword_matches` históricos existentes NO se eliminan.

#### Scenario: Usuario con módulo activo pero sin acceso al storage
- **WHEN** la transcripción T del storage 11 completa sus segmentos y el usuario "prueba" tiene `enabled=true` con la keyword "paro"
- **AND** `user_storages(prueba, 11).transcription_access = false`
- **THEN** no se crea `KeywordMatch` para (prueba, T) y no se envía email

#### Scenario: Usuario con acceso al storage recibe el match
- **WHEN** la misma situación anterior pero `user_storages(prueba, 11).transcription_access = true`
- **THEN** se crean los `KeywordMatch` correspondientes y se envía el email consolidado

#### Scenario: Coalescing por storage se mantiene
- **WHEN** la transcripción T del storage 11 produce 2 matches para "prueba" y "prueba" tiene acceso a 11
- **THEN** se crean 2 `KeywordMatch` y se envía UN solo email con ambos (comportamiento existente conservado)

#### Scenario: Histórico no se borra
- **WHEN** se desplegó el filtro y existían `KeywordMatch` previos de un usuario sobre un storage al que luego se le revocó el acceso
- **THEN** esos matches históricos siguen visibles para el usuario (filtro prospectivo, no retroactivo)
