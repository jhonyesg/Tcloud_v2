## ADDED Requirements

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

---

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

---

### Requirement: Compatibilidad con conteo del footer del modal
El sistema SHALL mantener `has_transcription: boolean` en el JSON de `storageFiles` para que el contador `transcribed_count` mostrado en el footer del modal siga calculándose sin cambios.

#### Scenario: Conteo de transcritos sigue funcionando
- **WHEN** el usuario abre el modal "Ver archivos" con 10 archivos (7 con transcripción, 3 sin)
- **THEN** el footer muestra `transcribed_count: 7` y `files_total: 10`, igual que antes del cambio