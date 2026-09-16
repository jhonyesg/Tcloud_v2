# ARCHIVED 2026-09-16: change remove-api-transcriptor-orphan-files-modal — la UI y los endpoints que esta spec documentaba fueron eliminados en 2026-09-15-simplify-api-transcriptor-to-storage-and-config y este cambio limpia el residuo.
# Spec: transcriptor-storage-files-srt-link

## Purpose
Define cómo el modal "Ver archivos" del módulo API Transcriptor expone el estado de transcripción asociado a cada archivo y permite navegar al detalle del job desde el nombre del archivo.

## Requirements

### Requirement: Endpoint storageFiles expone id y estado de la Transcription asociada
El sistema SHALL devolver, para cada archivo listado por `GET /ia/api-transcriptor/storages/{id}/files`, los campos `transcription_id` (entero nullable) y `transcription_state` (string nullable) cuando exista una fila en `transcriptions` vinculada por `file_id`. Cuando no exista transcripción asociada, ambos campos serán `null`. El campo `has_transcription` (boolean) se SHALL mantener para compatibilidad con el conteo del footer del modal.

#### Scenario: Archivo con transcripción en estado done
- **WHEN** el archivo `f.id = 42` tiene una `Transcription` con `id = 100`, `state = "done"`, `file_id = 42`
- **THEN** el JSON incluye `"transcription_id": 100`, `"transcription_state": "done"`, `"has_transcription": true` en la entrada de ese archivo

#### Scenario: Archivo con transcripción en estado error
- **WHEN** el archivo `f.id = 43` tiene una `Transcription` con `id = 101`, `state = "error"`, `file_id = 43`
- **THEN** el JSON incluye `"transcription_id": 101`, `"transcription_state": "error"`, `"has_transcription": true`

#### Scenario: Archivo sin transcripción asociada
- **WHEN** el archivo `f.id = 44` no tiene ninguna fila en `transcriptions` con `file_id = 44`
- **THEN** el JSON incluye `"transcription_id": null`, `"transcription_state": null`, `"has_transcription": false`

#### Scenario: Múltiples transcripciones para el mismo archivo
- **WHEN** el archivo `f.id = 45` tiene más de una fila en `transcriptions` (ej. reprocesado)
- **THEN** el JSON expone los campos de la transcripción más reciente (mayor `id`), descartando las anteriores

### Requirement: Fila de archivo con transcripción es un hipervínculo al job-detail
El sistema SHALL renderizar, en el modal "Ver archivos" del módulo API Transcriptor (modos `browse`, `today`, `yesterday` y `search`), el nombre de cada archivo como un elemento `<a href="/ia/api-transcriptor/jobs/{transcription_id}">` cuando `transcription_id` no sea `null`. El enlace aplica a TODOS los estados de transcripción (`pending`, `queued`, `processing`, `done`, `error`, `dead`).

#### Scenario: Archivo transcrito en estado done muestra link
- **WHEN** el usuario abre el modal "Ver archivos" de un storage y navega a un archivo con `transcription_state = "done"`
- **THEN** el nombre del archivo se renderiza como `<a href="/ia/api-transcriptor/jobs/{id}">` con estilo de hipervínculo (subrayado/color brand) y al hacer clic navega a la vista detalle del job

#### Scenario: Archivo con transcripción en estado error muestra link a reintento
- **WHEN** el usuario navega a un archivo con `transcription_state = "error"`
- **THEN** el nombre del archivo es un `<a>` que al hacer clic abre el job-detail mostrando el botón "Reintentar" disponible

#### Scenario: Archivo sin transcripción asociada no muestra link
- **WHEN** el usuario navega a un archivo con `transcription_id = null`
- **THEN** el nombre del archivo se renderiza como texto plano (sin `<a>`), manteniendo el badge "Pendiente" actual

#### Scenario: Link funciona en todos los modos del modal
- **WHEN** el usuario abre el modal en modo `browse`, `today`, `yesterday` o `search`
- **THEN** el comportamiento del hipervínculo es idéntico: archivos con transcripción son clicables, archivos sin transcripción no

### Requirement: Compatibilidad con conteo del footer del modal
El sistema SHALL mantener `has_transcription: boolean` en el JSON de `storageFiles` para que el contador `transcribed_count` mostrado en el footer del modal siga calculándose sin cambios.

#### Scenario: Conteo de transcritos sigue funcionando
- **WHEN** el usuario abre el modal "Ver archivos" con 10 archivos (7 con transcripción, 3 sin)
- **THEN** el footer muestra `transcribed_count: 7` y `files_total: 10`, igual que antes del cambio

### Requirement: El modal "Ver archivos" se monta dentro del scope `apiTranscriptor`
El sistema SHALL renderizar el contenedor del modal "Ver archivos" (elemento con `x-show="showFiles"`) como **hijo directo** del `<div x-data="apiTranscriptor(...)">` en `app/resources/views/ia/api-transcriptor/index.blade.php`. Esto garantiza que las directivas Alpine del modal (`x-text`, `:class`, `x-show`, `@click`) resuelvan `showFiles`, `filesMode`, `currentStorage`, `filesSearch`, `breadcrumb`, `filesLoading`, `syncing`, `colFilters` y `filesSort` contra el scope del componente, no contra el scope del sidebar.

#### Scenario: El modal abre con el título del storage correcto
- **WHEN** el operador abre el explorador de un storage haciendo click en el botón correspondiente (banner, tarjeta de storage, o `data.openFiles({...})`)
- **THEN** el `<h2>` del modal renderiza "Archivos — <nombre del storage>" (no vacío) porque `currentStorage.name` resuelve desde el scope `apiTranscriptor`

#### Scenario: Botones de modo muestran el resaltado activo
- **WHEN** el modal está abierto en modo `browse` (estado inicial tras `openFiles()`)
- **THEN** el botón "Explorar" aplica las clases de resaltado activo (`:class="filesMode === 'browse' ? ..."`) y los botones "Hoy" / "Ayer" no

#### Scenario: Consola del navegador libre de errores del modal
- **WHEN** el operador navega a `/ia/api-transcriptor` con un navegador moderno (Chrome ≥ 120) logueado como jsuarez
- **THEN** la consola del navegador NO emite `Alpine Expression Error: showFiles/currentStorage/filesMode/breadcrumb/filesSearch/filesLoading/syncing/colFilters/filesSort is not defined` originados desde elementos del modal "Ver archivos"

#### Scenario: HTML servido contiene el modal dentro del wrapper de Alpine
- **WHEN** un test automatizado pide `GET /ia/api-transcriptor` autenticado
- **THEN** el HTML resultante contiene `<div x-show="showFiles"` en algún punto posterior a `<div x-data="apiTranscriptor(...)"` y anterior al cierre `</div>` de ese wrapper (es decir, anidado, no como hermano)
