## Purpose

Define cómo el cliente de Mis Avisos actúa sobre una mención: ver la transcripción completa anclada al minuto exacto de la coincidencia, abrir el reproductor del archivo posicionado en ese minuto, y generar un corte del medio directamente desde el módulo, todo respetando la intersección de acceso (transcription_access ∩ alcance keyword→store) y los permisos de archivo existentes.

## ADDED Requirements

### Requirement: El cliente puede abrir la transcripción completa de una mención anclada a su segmento

El sistema SHALL proveer al cliente un endpoint sobre una transcripción cuya mención ve, que retorne los metadatos del medio (nombre, emisora, duración) y sus segmentos de transcripción. El acceso SHALL validarse contra la misma intersección que gobierna feed e histórico: si la transcripción no pertenece a un storage con `transcription_access` del cliente, el endpoint SHALL responder 404 sin revelar existencia. Cuando se solicita con el segmento de una mención como ancla, SHALL retornar una ventana acotada de segmentos alrededor del ancla en lugar de la transcripción completa, y SHALL exponer cursores para expandir la ventana hacia atrás y adelante bajo demanda del modal.

#### Scenario: Apertura anclada desde un hit del feed
- **WHEN** el cliente pulsa "Ver transcripción" en una coincidencia cuyo segmento es el índice 412 de una transcripción de 3800 segmentos
- **THEN** recibe los metadatos del medio y una ventana acotada de segmentos que contiene el índice 412 con su texto, índice y tiempos de inicio/fin
- **AND** la respuesta incluye cursores para pedir más segmentos anteriores y posteriores

#### Scenario: Transcripción sin acceso no revela existencia
- **WHEN** el cliente solicita la transcripción de un storage sin `transcription_access` para él (aunque conozca el id)
- **THEN** el sistema responde 404 y no retorna ningún segmento

#### Scenario: Expansión incremental sin sobrecargar el servidor
- **WHEN** el cliente llega al borde de la ventana cargada y sigue navegando en el modal
- **THEN** el sistema entrega la siguiente porción de segmentos mediante range-scan acotado por índice, sin cargar nunca la transcripción completa de una sola vez

### Requirement: El modal ubica y resalta el segmento de la mención

El modal de transcripción SHALL hacer scroll automático hasta el segmento ancla y resaltarlo visualmente junto con la keyword mencionada, diferenciándolo del resto del texto.

#### Scenario: Apertura posicionada en la mención
- **WHEN** el modal abre con ancla en el segmento 412
- **THEN** la vista queda posicionada sobre el segmento 412, que aparece resaltado con la keyword de la mención destacada dentro de su texto

### Requirement: Reproductor sincronizado dentro del modal cuando hay permiso de archivo

El modal SHALL ofrecer un reproductor del medio (audio/video) sincronizado con la transcripción: al pulsar un segmento el reproductor busca su tiempo de inicio, y durante la reproducción el segmento activo se resalta. Este reproductor SHALL mostrarse únicamente cuando el cliente tiene permiso de lectura sobre el archivo (admin, propietario o permiso `read` en el storage); sin ese permiso el modal muestra solo la transcripción.

#### Scenario: Cliente con permiso de lectura ve el reproductor sincronizado
- **WHEN** el cliente con permiso `read` en el storage abre el modal y pulsa el segmento 415
- **THEN** el reproductor continúa desde el tiempo de inicio del segmento 415 y, al reproducirse, el segmento activo queda resaltado

#### Scenario: Cliente sin permiso de lectura ve solo la transcripción
- **WHEN** el cliente tiene `transcription_access` en el storage pero sin permiso de lectura sobre el archivo
- **THEN** el modal muestra la transcripción completa anclada sin reproductor ni acciones de archivo

### Requirement: Deep-link del reproductor de archivos al minuto exacto

La página del reproductor de archivos SHALL aceptar un parámetro de segundo inicial y posicionar el elemento de audio/video en ese segundo al cargar. Todos los links de archivo que Mis Avisos muestra (feed, histórico, modal) SHALL apuntar a la página del reproductor con el segundo de la mención, no al endpoint de preview en línea.

#### Scenario: Link desde una mención abre el archivo en su minuto
- **WHEN** el cliente pulsa el nombre del archivo en una coincidencia ocurrida en el segundo 4925
- **THEN** abre la página del reproductor de ese archivo y el medio está posicionado en el segundo 4925 listo para reproducir

### Requirement: Capabilities por fila calculadas en el servidor

Cada coincidencia entregada al cliente (feed e histórico) SHALL incluir indicadores calculados por el servidor de si puede ver el archivo y si puede generar cortes, derivados de sus permisos reales; el cliente no deduce estas capacidades en el navegador.

#### Scenario: Botones coherentes con permisos reales
- **WHEN** el feed entrega una coincidencia de un storage con `transcription_access` y permiso `read`, y otra de un storage con `transcription_access` sin permiso `read`
- **THEN** la primera expone ver-archivo y (si corresponde) cortar como disponibles, y la segunda expone solo la transcripción

### Requirement: Corte del medio desde el módulo de avisos

El modal SHALL ofrecer generación de cortes reutilizando el endpoint existente de corte de archivos, con el rango prellenado desde el tiempo del segmento ancla y ajustable por el cliente. La acción SHALL estar sujeta a las mismas reglas del editor: flag `media_editor_enabled` del usuario (o admin), cupo mensual de cortes, archivo fuente local y con permiso de lectura del cliente. El cliente SHALL poder previsualizar el corte antes de confirmarlo y recibir el archivo resultante como descarga.

#### Scenario: Corte prellenado desde la mención
- **WHEN** el cliente con editor habilitado y cupo disponible abre el panel de corte desde un segmento que va del segundo 4925 al 4951
- **THEN** el panel propone ese rango editable, permite previsualizarlo y al confirmar descarga el corte

#### Scenario: Sin editor habilitado no hay panel de corte
- **WHEN** el cliente sin `media_editor_enabled` abre el modal de una mención
- **THEN** la acción de corte no se ofrece, y si el backend la rechaza muestra el motivo (flag, cupo o permiso) con el mensaje del servidor
