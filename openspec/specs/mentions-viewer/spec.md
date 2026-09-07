# mentions-viewer Specification

## Purpose
Define cómo el cliente de Mis Avisos actúa sobre una mención: ver la transcripción completa anclada al minuto exacto de la coincidencia, abrir el reproductor del archivo posicionado en ese minuto, y generar un corte del medio directamente desde el módulo, todo respetando la intersección de acceso (transcription_access ∩ alcance keyword→store) y los permisos de archivo existentes.

## Requirements

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

### Requirement: Ver unifica reproductor y transcripción en el modal

La acción "Ver" de cada coincidencia SHALL abrir el modal de transcripción con el reproductor iniciando automáticamente en el minuto de la mención y la transcripción anclada debajo: no existen acciones separadas para "ver video" y "ver transcripción". La selección del reproductor (video vs audio) SHALL decidirse por la extensión del archivo primero (los mimes en BD pueden estar cargados invertidos) y por el mime como respaldo.

#### Scenario: Ver abre el medio en su minuto con la transcripción
- **WHEN** el cliente pulsa "Ver" en una coincidencia del segundo 391
- **THEN** el modal abre con el medio posicionado en ese segundo y reproduciéndose, y la transcripción anclada al segmento de la mención

#### Scenario: La extensión decide el tipo de reproductor
- **WHEN** un archivo `.mp3` está registrado en BD con mime `video/mp4` (mime cargado invertido), o un `.mp4` con `audio/mp4`
- **THEN** el modal renderiza audio para el primero y video para el segundo, según la extensión

### Requirement: Deep-link de Mis Archivos a la carpeta del medio con resaltado

La acción "Archivos" de cada coincidencia y el botón "Abrir en Mis Archivos" del modal SHALL abrir el módulo de archivos en la carpeta contenedora del medio de la mención, con el archivo resaltado y la vista posicionada sobre él. El acceso SHALL respetar el permiso de lectura del cliente sobre el archivo; sin permiso, la acción no se ofrece. La página de archivos, al recibir ese deep-link, SHALL ignorar la navegación guardada en localStorage a favor del deep-link, y el editor de corte abierto desde esa sesión SHALL ofrecer el botón "Volver a Mis Avisos".

#### Scenario: Archivos cae en la carpeta de grabaciones del día
- **WHEN** el cliente pulsa "Archivos" en una coincidencia del archivo `lafm_06092026_081402.mp3` ubicado en `LA_FM/06092026`
- **THEN** el módulo abre esa carpeta (migas LA_FM → 06092026) con el archivo resaltado y centrado en pantalla

#### Scenario: Editor ofrece volver a Mis Avisos solo con origen avisos
- **WHEN** el cliente llegó al módulo de archivos desde una mención y abre el editor de corte
- **THEN** el editor muestra junto a "Salir" un botón "Volver a Mis Avisos" que navega a `/mis-avisos`
- **WHEN** el cliente abre el editor directamente desde el módulo de archivos sin origen avisos
- **THEN** el botón no aparece (solo "Salir")

### Requirement: La página del reproductor de archivos acepta segundo inicial

La página del reproductor de archivos (`/files/{id}/view?t=<segundos>`) SHALL aceptar un parámetro de segundo inicial y posicionar el elemento de audio/video en ese segundo al cargar. Esta página no SHALL construir su estado con JSON crudo dentro de atributos HTML (rompe el atributo); su data SHALL vivir en un bloque de script. Queda como vía alternativa: el flujo primario del visor es el modal de Mis Avisos.

#### Scenario: Deep-link directo posiciona el medio
- **WHEN** se abre `/files/{id}/view?t=224`
- **THEN** la página renderiza correctamente y el medio queda posicionado en el segundo 224

### Requirement: Búsqueda dentro del modal de transcripción

El modal SHALL ofrecer un recuadro de búsqueda que filtre los segmentos cargados por coincidencia de texto insensible a mayúsculas y tildes, resalte las coincidencias en color distinto al de la keyword, muestre un contador "N de M segmentos" e informe cuando no hay coincidencias en la ventana cargada. La búsqueda cubre los segmentos cargados; navegar con el scroll carga más segmentos y la expande.

#### Scenario: Búsqueda sin tildes encuentra el texto con tildes
- **WHEN** el cliente busca "misma sintonia" y el segmento dice "misma sintonía"
- **THEN** el segmento aparece filtrado con la coincidencia resaltada y el contador muestra la proporción

### Requirement: Resaltado de la keyword insensible a mayúsculas y tildes

El segmento ancla y cualquier segmento visible SHALL resaltar las ocurrencias de la keyword de la mención aunque difieran en mayúsculas o tildes ("alvaro uribe" marca "Álvaro Uribe").

#### Scenario: Keyword sin tildes marca el texto con tildes
- **WHEN** la keyword registrada es "alvaro uribe" y la transcripción dice "Álvaro Uribe"
- **THEN** la frase aparece resaltada dentro del texto del segmento

### Requirement: Capabilities por fila calculadas en el servidor

Cada coincidencia entregada al cliente (feed e histórico) SHALL incluir indicadores calculados por el servidor de si puede ver el archivo y si puede generar cortes, derivados de sus permisos reales (para el corte: admin o `media_editor_enabled` vía `canUseMediaEditor`, storage local y permiso de lectura); el cliente no deduce estas capacidades en el navegador.

#### Scenario: Capabilities coherentes con permisos reales
- **WHEN** el feed entrega una coincidencia de un storage con permiso de lectura y otra de un storage sin acceso de lectura
- **THEN** la primera expone ver-archivo y (si corresponde) cortar como disponibles, y la segunda expone solo la transcripción

#### Scenario: El admin puede cortar sin flag propio
- **WHEN** un admin sin `media_editor_enabled` consulta una coincidencia de un storage local con permiso de lectura
- **THEN** la fila expone `can_clip` disponible (admin vía `canUseMediaEditor`)

### Requirement: Corte del medio desde el módulo de avisos

El modal SHALL ofrecer generación de cortes reutilizando el endpoint existente de corte de archivos, con el rango prellenado desde el tiempo del segmento ancla y ajustable por el cliente. La acción SHALL estar sujeta a las mismas reglas del editor: flag `media_editor_enabled` del usuario (o admin), cupo mensual de cortes, archivo fuente local y con permiso de lectura del cliente. El cliente SHALL poder previsualizar el corte antes de confirmarlo y recibir el archivo resultante como descarga.

#### Scenario: Corte prellenado desde la mención
- **WHEN** el cliente con editor habilitado y cupo disponible abre el panel de corte desde un segmento que va del segundo 4925 al 4951
- **THEN** el panel propone ese rango editable, permite previsualizarlo y al confirmar descarga el corte

#### Scenario: Sin editor habilitado no hay panel de corte
- **WHEN** el cliente sin `media_editor_enabled` abre el modal de una mención
- **THEN** la acción de corte no se ofrece, y si el backend la rechaza muestra el motivo (flag, cupo o permiso) con el mensaje del servidor
