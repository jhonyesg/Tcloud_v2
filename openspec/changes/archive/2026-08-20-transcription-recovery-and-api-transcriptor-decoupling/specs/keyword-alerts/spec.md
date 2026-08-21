# Spec: keyword-alerts (delta)

## ADDED Requirements

### Requirement: La ficha del cliente informa qué canales se transcriben, sin controlarlo

El sistema SHALL mostrar en `/ia/avisos-inteligentes/{userId}` los `StorageProvider` habilitados que el cliente tiene asignados, cada uno con el estado de transcripción del **storage** (`storage_providers.transcription_enabled`) en modo **solo lectura**.

La pantalla NO SHALL ofrecer ningún control para habilitar o deshabilitar la transcripción, y SHALL enlazar a `/ia/api-transcriptor`, que es donde vive esa decisión. Avisos Inteligentes consume transcripciones ya producidas; no decide qué se produce.

El listado de clientes SHALL contar los canales asignados a cada uno, y NO SHALL presentar un conteo de "canales con transcripción contratada": la transcripción es un atributo del canal, no del cliente.

#### Scenario: Admin abre la ficha de un cliente
- **WHEN** el admin entra a la ficha de un cliente con canales asignados
- **THEN** ve cada canal con la etiqueta "Transcribe" o "Sin transcripción" según la bandera del storage, sin ningún interruptor, y con enlace a API Transcriptor

#### Scenario: El canal se enciende desde el otro módulo
- **WHEN** el operador enciende ese canal en `/ia/api-transcriptor` y vuelve a la ficha del cliente
- **THEN** la ficha refleja el nuevo estado, porque lee la misma bandera del storage

#### Scenario: Dos clientes comparten un canal
- **WHEN** un mismo storage está asignado a dos clientes y se está transcribiendo
- **THEN** ambas fichas lo muestran como "Transcribe": el canal se transcribe una sola vez y sus transcripciones alimentan las keywords de cada cliente por separado
