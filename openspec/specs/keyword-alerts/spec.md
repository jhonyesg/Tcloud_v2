# Spec — Keyword Alerts (Avisos Inteligentes)

## Purpose

Define el módulo de Avisos Inteligentes: cómo el admin lo activa por cliente con cupos de keywords y correos, cómo se detectan coincidencias sobre las transcripciones ya producidas y cómo se notifica al cliente.

## Requirements

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

### Requirement: Admin registra los correos de aviso del usuario
El sistema SHALL permitir al admin registrar hasta `emails_quota` direcciones de email en `user_alerts_inteligentes.emails` (campo `jsonb` o tabla pivote `user_alert_emails`).

#### Scenario: Registrar correos
- **WHEN** el admin registra hasta N correos donde N ≤ `emails_quota`
- **THEN** el sistema los guarda y serán los destinatarios de TODAS las alertas generadas para ese usuario

#### Scenario: Exceder el cupo de correos
- **WHEN** el admin intenta registrar un correo que sumaría > `emails_quota`
- **THEN** el sistema rechaza con 422 "Cupo de correos excedido (quedan X cupos disponibles)"

---

### Requirement: Admin gestiona las keywords de búsqueda por usuario
El sistema SHALL permitir al admin agregar, eliminar y listar las keywords de un usuario en `/ia/avisos-inteligentes/{user}` sin exceder `keywords_quota`.

#### Scenario: Agregar keyword dentro del cupo
- **WHEN** el admin agrega "paro nacional" y el usuario tiene `keywords_quota=200` con 50 keywords registradas
- **THEN** el sistema crea/encuentra la keyword (tabla global `keywords` por `normalized`), crea fila en `user_keyword`, queda en 51/200

#### Scenario: Exceder el cupo de keywords
- **WHEN** el usuario ya tiene `keywords_quota` keywords y se intenta agregar una nueva
- **THEN** el sistema rechaza con 422 "Cupo de keywords alcanzado (X/Y)"

#### Scenario: Keyword duplicada para el mismo usuario
- **WHEN** el admin agrega "paro nacional" dos veces al mismo usuario
- **THEN** el sistema lo trata como upsert: no crea duplicado, devuelve 200

#### Scenario: Keyword existente se reutiliza entre usuarios
- **WHEN** el cliente A tiene "presidente" y el admin agrega "presidente" al cliente B
- **THEN** se reutiliza la misma fila en `keywords` (UNIQUE en `normalized`) y solo se crea la fila en `user_keyword` para B

---

### Requirement: Matching automático al completarse una transcripción
El sistema SHALL ejecutar `KeywordMatcher::run($transcription)` inmediatamente después de poblar `TranscriptionSegment`, sin esperar a un cron.

#### Scenario: Match encontrado para un usuario
- **WHEN** una transcripción completa sus segmentos y el usuario "prueba" tiene keywords `paro` y `cocaína`
- **THEN** el sistema crea `KeywordMatch` por cada (segment, keyword) que matchea y al final agrupa los matches del usuario en UNA alerta por email

#### Scenario: Sin matches para un usuario
- **WHEN** los segmentos de la transcripción no contienen ninguna de las keywords del usuario
- **THEN** el sistema no crea `KeywordMatch` ni envía email

#### Scenario: Múltiples matches en segmentos distintos
- **WHEN** la transcripción contiene "paro" en el segmento 5 y "cocaína" en el segmento 18
- **THEN** se crean 2 `KeywordMatch`, pero se envía UN solo email al usuario con ambos matches listados (coalescing)

---

### Requirement: Email de alerta se envía vía Modules/Correo
El sistema SHALL enviar un email por (transcripción, usuario) usando el módulo `App\Modules\Correo` con subject "Coincidencia en grabación {filename}", template `ia-alert-match`, y data `{user, transcription, file_url, matches: [{keyword, segment_index, minute_label, snippet}, ...]}`.

#### Scenario: Envío exitoso
- **WHEN** se agrupan matches para un usuario y todos los correos configurados existen
- **THEN** el email se envía, se crea un `AlertLog` con `status=sent` y `sent_at=NOW()` por cada destinatario

#### Scenario: Falla SMTP
- **WHEN** el envío de email falla por timeout o error SMTP
- **THEN** se crea `AlertLog` con `status=failed` y `error_message`, pero la `KeywordMatch` queda persistida (no se pierde el match)

---

### Requirement: Idempotencia de matches — no se duplican alertas
El sistema SHALL garantizar que una misma (transcripción, usuario) genere a lo sumo UN email, incluso si el matching se ejecuta dos veces por reintento del job.

#### Scenario: Re-ejecución de matching no duplica alertas
- **WHEN** `KeywordMatcher::run` se ejecuta dos veces para la misma `Transcription`
- **THEN** el segundo pass detecta que ya existen `KeywordMatch` con esa `transcription_id` y `user_id` y no crea nuevos ni envía emails duplicados

---

### Requirement: Admin puede ver historial de matches por usuario
El sistema SHALL listar las `KeywordMatch` de un usuario en `/ia/avisos-inteligentes/{user}` ordenadas por `matched_at DESC` con link al `File` original.

#### Scenario: Listado de matches
- **WHEN** el admin abre el detalle de un usuario
- **THEN** ve una tabla con columnas: fecha, filename, minuto, keyword, snippet, y badge "Email enviado" si hay `AlertLog` con `status=sent`

---

### Requirement: Admin puede enviar email de prueba a un correo registrado
El sistema SHALL permitir al admin enviar un email de prueba usando la plantilla `ia-alert-match` con datos ficticios a uno de los correos del usuario.

#### Scenario: Test enviado correctamente
- **WHEN** el admin hace POST a `/ia/avisos-inteligentes/{user}/emails/{email}/test`
- **THEN** el sistema envía un email de prueba con `subject="[TEST] Coincidencia..."` y registra `AlertLog` con `status=sent`

---

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

---

### Requirement: El switch de transcripción de `/ia/api-transcriptor` es de solo lectura
El sistema SHALL mostrar en `/ia/api-transcriptor` el estado de transcripción de cada storage como indicador derivado, sin permitir escribirlo desde esa pantalla.

#### Scenario: El operador consulta el estado
- **WHEN** el operador abre `/ia/api-transcriptor`
- **THEN** ve qué storages transcriben, sin control de escritura, con enlace a Avisos Inteligentes para cambiarlo

#### Scenario: La ruta de escritura anterior deja de existir
- **WHEN** se hace POST a `/ia/api-transcriptor/storages/{id}/toggle`
- **THEN** la ruta ya no está registrada

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
