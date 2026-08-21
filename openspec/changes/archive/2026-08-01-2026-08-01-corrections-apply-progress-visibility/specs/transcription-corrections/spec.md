## MODIFIED Requirements

### Requirement: Comando retroactivo reaplica el diccionario a todas las transcripciones
El sistema SHALL exponer `php artisan transcription:apply-corrections [--dry-run] [--chunk=500]` y su variante con tracking `php artisan corrections:apply-run --run-id=<id> [--chunk=N] [--days=N]`, que recorren los `TranscriptionSegment` en batches, reaplican el diccionario actual y actualizan `text`. NO re-envían emails (los AlertLog históricos quedan con el texto con el que se enviaron).

Cuando corre con `--run-id`, el comando SHALL reportar progreso real en la cache key `corrections_apply:{runId}` **después de cada chunk**: `processed` (conteo acumulado de segments leídos), `updated` (conteo parcial de segments modificados, no solo al final), `total`, `progress` (lastId diagnóstico) y `last_progress_at` (heartbeat ISO8601). La conversión de la colección de correcciones aprobadas a pares primitivos ordenados SHALL ejecutarse una sola vez por corrida (no por segmento).

#### Scenario: Admin corre el comando retroactivo en dry-run
- **WHEN** el admin corre `php artisan transcription:apply-corrections --dry-run`
- **THEN** el sistema reporta cuántos segments serían modificados y muestra los primeros 10 cambios propuestos, sin tocar la BD

#### Scenario: Admin corre el comando real
- **WHEN** el admin corre `php artisan transcription:apply-corrections`
- **THEN** el sistema itera en chunks de 500, actualiza `text` por cada segment, e incrementa `applies_count` en cada corrección aplicada. Imprime progreso cada 1000 segments

#### Scenario: Comando respeta transacciones por chunk
- **WHEN** el comando está procesando
- **THEN** cada chunk de 500 segments corre dentro de su propia transacción. Si un chunk falla, los anteriores quedan guardados y el error se reporta (no se pierde progreso)

#### Scenario: Progreso visible durante la corrida, no solo al final
- **WHEN** el admin lanza Re-aplicar con scope "último día" (214,396 segments) y han pasado 2 minutos
- **THEN** la cache key del run muestra `processed > 0` creciendo por chunk, `updated` con el conteo parcial de modificados y `last_progress_at` con antigüedad menor a 1 minuto
- **THEN** la UI muestra la barra avanzando (ej. "48,500 / 214,396 segmentos (22%)") en vez de quedarse en 0% hasta el final

#### Scenario: Pares de correcciones se preparan una sola vez
- **WHEN** una corrida procesa 214,396 segments con N correcciones aprobadas
- **THEN** la conversión de la colección a pares ordenados por longitud DESC ocurre UNA vez al inicio de la corrida (no 214,396 veces)

---

## ADDED Requirements

### Requirement: El sistema impide corridas retroactivas duplicadas en paralelo
El sistema SHALL mantener una cache key fija `corrections_apply:active` apuntando al `runId` de la corrida vigente. `POST /ia/correcciones/apply-retroactive` SHALL responder `409 Conflict` con el `runId` vigente cuando ya existe una corrida en estado `queued` o `running`, en vez de lanzar un proceso paralelo. El puntero se crea atómicamente (SET NX) y se limpia cuando la corrida termina en `done` o `error`. Un puntero cuyo run está en `done`/`error`, no existe, o lleva más de 5 minutos en `queued` sin `started_at` SHALL considerarse huérfano y no bloquear una nueva corrida.

#### Scenario: Admin hace doble click en Re-aplicar
- **WHEN** el admin confirma Re-aplicar mientras otra corrida está `running`
- **THEN** el servidor responde 409 con `{runId, status}` de la corrida vigente y NO lanza un segundo proceso `corrections:apply-run` (verificable: un solo proceso en `ps aux`)
- **THEN** la UI se re-adjunta al polling de la corrida vigente en vez de mostrar error

#### Scenario: Puntero huérfano no bloquea nuevas corridas
- **WHEN** existe un puntero `corrections_apply:active` cuyo run quedó en `queued` hace 30 minutos sin `started_at` (el proceso nunca arrancó)
- **THEN** un nuevo POST apply-retroactive responde 202 y lanza la corrida normalmente, reemplazando el puntero

#### Scenario: Puntero se limpia al terminar
- **WHEN** una corrida termina en `done` o `error`
- **THEN** `corrections_apply:active` se elimina y un POST posterior responde 202 con un `runId` nuevo

---

### Requirement: Admin re-adjunta una corrida en curso al recargar la página
El sistema SHALL exponer `GET /ia/correcciones/apply-retroactive-active` que retorna el estado completo de la corrida vigente (200 con `{runId, status, processed, total, updated, last_progress_at, ...}`) o 204 si no hay ninguna. Al cargar `/ia/correcciones`, la UI SHALL consultar este endpoint y, si hay corrida activa, mostrar su progreso en un banner persistente del módulo y arrancar el polling automáticamente, sin intervención del admin.

#### Scenario: Admin recarga durante una corrida
- **WHEN** el admin recarga `/ia/correcciones` a mitad de una corrida `running`
- **THEN** la página muestra el banner "Re-aplicar en curso" con el porcentaje actual en menos de 3 segundos y el polling continúa donde iba

#### Scenario: No hay corrida activa
- **WHEN** el admin carga `/ia/correcciones` sin corrida vigente
- **THEN** el endpoint responde 204 y la página no muestra banner ni hace polling

#### Scenario: Corrida terminó mientras la página estaba cerrada
- **WHEN** el admin carga la página después de que la corrida llegó a `done` (puntero ya limpiado)
- **THEN** el endpoint responde 204 y la UI arranca en estado inicial normal

---

### Requirement: UI detecta y comunica una corrida estancada
Mientras el polling está activo, la UI SHALL comparar `last_progress_at` contra el reloj local: si el estado es `running` y el heartbeat supera 3 minutos sin actualizarse, SHALL mostrar un aviso visible (ámbar) indicando que la corrida parece detenida y la hora del último avance. El aviso no detiene el polling (una corrida viva retoma el estado verde sola al próximo chunk).

#### Scenario: Proceso muere a mitad de corrida
- **WHEN** el proceso `corrections:apply-run` es killado a mitad de la corrida y pasan 3 minutos
- **THEN** la UI muestra "Sin avances desde las HH:MM — la corrida pudo haberse detenido" en vez de esperar indefinidamente con la barra congelada

#### Scenario: Corrida lenta pero viva no genera falsa alarma
- **WHEN** una corrida procesa chunks en menos de 3 minutos cada uno
- **THEN** el aviso de estancada nunca aparece (el heartbeat se renueva por chunk)

#### Scenario: Estados legibles en español
- **WHEN** el polling recibe `status: "queued"`, `"running"`, `"done"` o `"error"`
- **THEN** la UI muestra "En cola…", "Procesando…", "Terminada" o "Falló" respectivamente (no el status crudo en inglés)
