## Purpose

Define el contrato de error y la notificación al usuario del flujo "Nueva Carpeta" en el módulo Mis Archivos: el endpoint SHALL devolver un JSON con campo `error` semántico mapeado al código HTTP apropiado, y el cliente SHALL mostrar el detalle al operador mediante un toast no-bloqueante en lugar de `alert()`.

## Requirements

### Requirement: El endpoint POST /files devuelve JSON error específico cuando falla la creación de una carpeta

Cuando la operación de creación de carpeta falle, el endpoint SHALL responder con un objeto JSON que incluya un campo `error` con un mensaje legible, y SHALL usar el código HTTP semánticamente correcto (`403` cuando el usuario no tiene permiso de escritura en el storage, `409` cuando ya existe una carpeta con ese nombre en el mismo padre, `500` solo para fallos de filesystem no recuperables). El cliente SHALL preferir `data.error` sobre `data.message` y SHALL caer a `"HTTP " + res.status` solo cuando ambos estén ausentes.

#### Scenario: Permiso denegado por storage no escribible
- **WHEN** el usuario hace POST a `/files` con `is_folder=true` sobre un storage donde su `user_storages.permissions` es `read`
- **THEN** el endpoint responde HTTP 403 con `{"error":"No tienes permiso para crear carpetas en este almacenamiento"}`

#### Scenario: Carpeta duplicada en el mismo padre
- **WHEN** el usuario hace POST a `/files` con `is_folder=true, name=X` y ya existe una carpeta con `name=X` bajo el mismo `parent_id` y `storage_provider_id`
- **THEN** el endpoint responde HTTP 409 con `{"error":"Ya existe una carpeta con ese nombre en esta ubicación"}`

#### Scenario: Fallo de filesystem (permisos de directorio o disco lleno)
- **WHEN** el `mkdir()` del filesystem falla por cualquier motivo (`E_WARNING` por permisos, ENOSPC, etc.)
- **THEN** el endpoint responde HTTP 500 con `{"error":"No se pudo crear el directorio en el almacenamiento: <motivo legible>"}`
- **AND** el servidor registra `Log::error('folder.create_failed', [...])` con `storage_id`, `path` intentado, `errno` y `error_message` antes de devolver la respuesta

#### Scenario: Respuesta exitosa
- **WHEN** la carpeta se crea correctamente en filesystem y base de datos
- **THEN** el endpoint responde HTTP 201 (o 200) con `{"file":{...},"success":true}` siguiendo el contrato existente

### Requirement: Toast no-bloqueante reemplaza alert nativo en Mis Archivos para errores de operaciones de archivo

El módulo Mis Archivos SHALL mostrar notificaciones toast Alpine no-bloqueantes (sin `alert()` del navegador) para todos los errores de operaciones asincrónicas (crear carpeta, guardar rotación de imagen). El toast SHALL usar fondo rojo para errores, SHALL aparecer en una esquina fija de la pantalla, SHALL auto-dismissarse entre 4000 y 6000 ms sin intervención del operador, y SHALL mostrar el mensaje `data.error || data.message || ("HTTP " + res.status)`.

#### Scenario: Error al crear carpeta muestra toast rojo con detalle
- **WHEN** el usuario hace clic en "Crear" en el modal "Nueva Carpeta" y la respuesta HTTP NO es `2xx`
- **THEN** el sistema muestra un toast rojo en la esquina inferior centro con el texto `data.error || data.message || ("HTTP " + res.status)` durante 5000 ms
- **AND** el modal permanece visible para que el usuario pueda corregir el nombre y reintentar
- **AND** el hilo de JavaScript NO se bloquea (el operador puede seguir interactuando con la página mientras el toast está visible)

#### Scenario: Error al guardar rotación de imagen muestra toast rojo con detalle
- **WHEN** el usuario hace clic en "Guardar rotación" en el visor de imagen y la respuesta HTTP NO es `2xx`
- **THEN** el sistema muestra un toast rojo con el texto `data.error || data.message || ("HTTP " + res.status)` durante 5000 ms

#### Scenario: Toast de éxito al crear carpeta
- **WHEN** el usuario hace clic en "Crear" en el modal "Nueva Carpeta" y la respuesta HTTP es `2xx`
- **THEN** el sistema muestra un toast verde con el texto "Carpeta creada correctamente" durante 3000 ms
- **AND** el modal se cierra
- **AND** la lista de carpetas se actualiza para incluir la nueva carpeta

### Requirement: La notificación toast nunca bloquea el hilo de JavaScript

El sistema SHALL usar Alpine + `setTimeout` para el ciclo de vida del toast. El toast SHALL desaparecer automáticamente al cumplirse el tiempo de auto-dismiss, SHALL NO requerir acción del operador para cerrarse, y SHALL NO usar `alert()` ni `confirm()` del navegador bajo ninguna circunstancia en Mis Archivos.

#### Scenario: El operador puede seguir interactuando mientras el toast está visible
- **WHEN** un toast de error o éxito está visible en pantalla
- **THEN** el operador puede hacer scroll, abrir otros modales y operar el módulo sin necesidad de cerrar el toast manualmente
- **AND** el toast desaparece solo al cumplirse su TTL sin interrumpir el flujo de trabajo

#### Scenario: Múltiples operaciones seguidas acumulan toasts independientes
- **WHEN** el operador crea dos carpetas seguidas y ambas fallan
- **THEN** el sistema muestra dos toasts secuenciales (uno por operación) sin solapamiento destructivo
- **AND** cada toast respeta su propio TTL
