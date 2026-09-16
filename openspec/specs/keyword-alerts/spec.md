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

### Requirement: El switch de transcripción de `/ia/api-transcriptor` es de solo lectura
El sistema SHALL mostrar en `/ia/api-transcriptor` el estado de transcripción de cada storage como indicador derivado, sin permitir escribirlo desde esa pantalla.

#### Scenario: El operador consulta el estado
- **WHEN** el operador abre `/ia/api-transcriptor`
- **THEN** ve qué storages transcriben, sin control de escritura, con enlace a Avisos Inteligentes para cambiarlo

#### Scenario: La ruta de escritura anterior deja de existir
- **WHEN** se hace POST a `/ia/api-transcriptor/storages/{id}/toggle`
- **THEN** la ruta ya no está registrada

---

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
