## MODIFIED Requirements

### Requirement: El scan de una transcripción se ejecuta una sola vez para todas las keywords distintas

El sistema SHALL, al completarse una transcripción, calcular el conjunto de keywords distintas de todos los usuarios habilitados con `transcription_access` sobre el storage de esa transcripción (considerando el alcance keyword→store), filtrar las que ya tienen cobertura completa según el watermark del par `(keyword_id, storage_provider_id)` en `keyword_scan_watermarks` (`transcription.finished_at < watermark.scanned_until`), y escanear sus segmentos una sola vez contra el conjunto restante de keywords. El número de pasadas de texto SHALL ser independiente del número de usuarios y SHALL excluir keywords ya cubiertas.

#### Scenario: Dos clientes con la misma keyword sobre el mismo store
- **WHEN** los clientes A y B tienen la keyword "caracol" con acceso al storage 11 y termina una transcripción de 800 segmentos del storage 11 y el watermark `(caracol, storage 11).scanned_until` es anterior al `finished_at` de la transcripción
- **THEN** el texto se escanea una sola vez contra "caracol" y ambos clientes derivan el resultado de la misma coincidencia compartida

#### Scenario: Keyword ya cubierta para este storage se omite
- **WHEN** el watermark `(KW-A, storage 7).scanned_until` ya supera el `finished_at` de la nueva transcripción
- **THEN** KW-A no se incluye en el conjunto de keywords a escanear para esa transcripción

#### Scenario: Keywords distintas se acumulan en el conjunto del scan
- **WHEN** el cliente A tiene "caracol" y "petro", y el cliente B tiene "caracol" y "alcaldía", todos con acceso al storage y sin watermark previo que las cubra
- **THEN** el conjunto del scan contiene las 3 keywords distintas ("caracol", "petro", "alcaldía"), no 4 keywords de usuario

### Requirement: Las coincidencias se persisten compartidas e idempotentes

El sistema SHALL persistir cada coincidencia en una tabla compartida con unicidad por (transcripción, segmento, keyword), de modo que la coincidencia exista una sola vez sin importar cuántos usuarios la reciban, y re-ejecutar el scan no duplique filas.

#### Scenario: Coincidencia compartida se guarda una vez
- **WHEN** 3 clientes reciben el match de "caracol" en el segmento 5 de la misma transcripción
- **THEN** existe una sola fila de coincidencia para (esa transcripción, segmento 5, keyword caracol) y cada cliente la referencia por la intersección de su acceso

#### Scenario: Re-ejecución no duplica
- **WHEN** el scan se re-ejecuta para la misma transcripción
- **THEN** no se crean filas de coincidencia nuevas ni notificaciones duplicadas

### Requirement: El resultado visible por usuario se deriva por acceso

El sistema SHALL derivar, para cada usuario, las coincidencias que le corresponden mediante la intersección: keywords del usuario ∩ storages con `transcription_access = true` ∩ asignación keyword→store del usuario. Un usuario nunca ve coincidencias fuera de esa intersección.

#### Scenario: Cliente sin acceso no deriva coincidencias
- **WHEN** la transcripción del storage 11 tiene coincidencias y el cliente no tiene `transcription_access` en el storage 11
- **THEN** el feed, el histórico y la entrega de correo de ese cliente excluyen esas coincidencias

#### Scenario: Cliente con keyword asignada a otro store no deriva coincidencias
- **WHEN** la keyword "concierto" del cliente está asignada solo al storage 14 y hay coincidencias en una transcripción del storage 11
- **THEN** esas coincidencias no aparecen para ese cliente aunque tenga acceso al storage 11

### Requirement: El scan no ejecuta queries por coincidencia ni envía correo

El sistema SHALL resolver los identificadores de keywords y las asignaciones antes del escaneo, sin consultas por coincidencia detectada, y SHALL NO enviar correo durante el scan (la entrega la gestiona la cadencia).

#### Scenario: Scan de transcripción con muchas coincidencias
- **WHEN** una transcripción produce 500 coincidencias
- **THEN** el scan no ejecuta una consulta SQL por cada coincidencia detectada y termina sin enviar ningún email

## ADDED Requirements

### Requirement: El avance del watermark se materializa tras cada scan exitoso

El sistema SHALL, tras ejecutar el scan de una transcripción, actualizar de forma atómica y monotónica el watermark de cada par `(keyword_id, storage_provider_id)` procesado, avanzando `scanned_until` al `MAX(scanned_until, transcription.finished_at)`. La actualización SHALL registrar `last_scan_run_id` y SHALL acumular contadores de diagnóstico (`candidates_total`, `hits_total`). La operación SHALL ser idempotente y race-safe.

#### Scenario: Scan exitoso avanza el watermark
- **WHEN** el scan procesa una transcripción del storage 7 con `finished_at = 2026-09-09 10:00:00` para la keyword KW-A
- **THEN** el watermark `(KW-A, storage 7)` queda con `scanned_until = MAX(anterior, 2026-09-09 10:00:00)`, `last_scan_run_id = <run actual>`, `last_scanned_at = NOW()`

#### Scenario: Scan sin hits nuevos también avanza el watermark
- **WHEN** el scan procesa una transcripción sin encontrar hits para la keyword KW-A
- **THEN** el watermark de `(KW-A, storage)` avanza al `finished_at` de la transcripción (cubrir el rango es suficiente garantía de que ya se intentó)
