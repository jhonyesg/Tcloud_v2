## Purpose

Permite al cliente (o gestor con clientes activos) abrir la transcripción completa de un archivo de Mis Archivos en el visor unificado de menciones cuando su storage lo permite, manteniendo intacto el flujo del reproductor a pantalla completa para quienes no tienen transcripción disponible.

## ADDED Requirements

### Requirement: El listado de archivos expone el id de su transcripción cuando existe

El endpoint `GET /files` SHALL devolver por cada archivo un campo `transcription_id` con el id de la transcripción en estado `done` asociada, o `null` si no existe o no está completa. La consulta SHALL resolverse en una sola query (LEFT JOIN contra `transcriptions` filtrado por `state='done'`) sin N+1, y SHALL respetar el cache de listing existente sin invalidarlo por este cambio.

#### Scenario: Archivo con transcripción done devuelve el id
- **WHEN** el cliente solicita el listado de una carpeta que contiene `caracol_06092026_220002.mp4` con una transcripción en estado `done`
- **THEN** la respuesta incluye para ese archivo el campo `transcription_id` con el id numérico correspondiente

#### Scenario: Archivo sin transcripción devuelve null
- **WHEN** el cliente solicita el listado de una carpeta donde un archivo no tiene transcripción o está en estado distinto a `done`
- **THEN** la respuesta incluye `transcription_id: null` para ese archivo

#### Scenario: El cache de listing sigue sirviendo sin invalidación adicional
- **WHEN** el listado se sirve desde el cache de Redis con TTL existente
- **THEN** la respuesta cacheada ya incluye el campo `transcription_id` para cada archivo

### Requirement: El botón "Ver transcripción" solo aparece cuando aplica

La columna Acciones de cada fila de Mis Archivos SHALL ofrecer un botón "Ver transcripción" solo cuando se cumplen simultáneamente todas las condiciones: (a) el archivo no es carpeta, (b) su mime es `video/*` o `audio/*`, (c) el storage del archivo tiene `transcription_enabled=true`, (d) el usuario actual tiene `transcription_access=true` en `user_storages` para ese storage, y (e) el archivo tiene `transcription_id` no nulo en la respuesta del listado. Cuando alguna condición no se cumple, el botón SHALL no renderizarse y el comportamiento del click sobre la fila SHALL seguir siendo el reproductor nativo de Mis Archivos.

#### Scenario: Cliente con acceso ve el botón en un archivo transcrito
- **WHEN** el cliente con `transcription_access=true` en el storage navega a una carpeta con `caracol_06092026_220002.mp4` que tiene transcripción done
- **THEN** la fila muestra el botón "Ver transcripción" en la columna Acciones

#### Scenario: Cliente sin acceso no ve el botón aunque haya transcripción
- **WHEN** el cliente sin `transcription_access` en el storage navega a una carpeta que contiene un archivo con transcripción done
- **THEN** la fila NO muestra el botón "Ver transcripción"

#### Scenario: Archivo sin transcripción no muestra el botón
- **WHEN** el cliente con `transcription_access=true` navega a una carpeta donde un archivo de video no tiene transcripción done
- **THEN** la fila NO muestra el botón "Ver transcripción"

#### Scenario: Click normal sobre la fila sigue abriendo el reproductor nativo
- **WHEN** el cliente hace click sobre la fila de un archivo donde no se muestra el botón
- **THEN** se abre el viewer modal nativo de Mis Archivos con el reproductor, idéntico al comportamiento previo al cambio

### Requirement: El endpoint de transcripción de archivo respeta las mismas reglas de acceso que Mis Avisos

El endpoint `GET /files/{id}/transcription` SHALL retornar el mismo shape que `GET /mis-avisos/transcriptions/{id}` (metadatos + ventana de segmentos) cuando el archivo tiene transcripción done y el usuario tiene `transcription_access` en su storage. Cuando el usuario no cumple la intersección, el endpoint SHALL responder 404 sin revelar existencia, idéntico al comportamiento existente en Mis Avisos.

#### Scenario: Cliente con acceso recibe meta y segmentos
- **WHEN** el cliente con `transcription_access` solicita `/files/{caracol_id}/transcription`
- **THEN** recibe la respuesta con metadatos del medio (nombre, storage, duración, mime), lista inicial de segmentos y cursores para expandir

#### Scenario: Cliente sin acceso recibe 404 opaco
- **WHEN** un cliente sin `transcription_access` en el storage solicita `/files/{id}/transcription` aunque conozca el id
- **THEN** el endpoint responde 404 sin contenido útil

#### Scenario: Archivo sin transcripción responde 404
- **WHEN** el cliente solicita `/files/{id}/transcription` para un archivo sin transcripción done
- **THEN** el endpoint responde 404

### Requirement: Apertura desde Mis Archivos ancla al inicio del medio

Cuando el cliente pulsa "Ver transcripción" en una fila de Mis Archivos, el visor SHALL abrirse con la transcripción posicionada en el primer segmento (sin ancla a una mención) y el reproductor detenido al inicio del medio, ofreciendo las mismas capacidades que en Mis Avisos (búsqueda, click-para-buscar-tiempo, scroll incremental de segmentos).

#### Scenario: Apertura limpia desde Mis Archivos
- **WHEN** el cliente pulsa "Ver transcripción" en `caracol_06092026_220002.mp4`
- **THEN** el modal abre con la transcripción completa desde el primer segmento y el reproductor detenido en `t=0`, sin highlight de mención

#### Scenario: Búsqueda y click-para-tiempo funcionan idéntico a Mis Avisos
- **WHEN** el cliente escribe en el buscador o pulsa un segmento dentro del modal abierto desde Mis Archivos
- **THEN** el comportamiento es indistinguible del modal abierto desde una mención de Mis Avisos
