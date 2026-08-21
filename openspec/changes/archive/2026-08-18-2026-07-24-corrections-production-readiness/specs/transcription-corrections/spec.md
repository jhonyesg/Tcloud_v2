## MODIFIED Requirements

### Requirement: Correcciones activas se aplican a segmentos nuevos en el parseo del SRT
El sistema SHALL, al guardar segmentos desde un SRT nuevo, aplicar todas las correcciones `status=approved` al campo `text` de cada `TranscriptionSegment`, dejando el campo `text_raw` con el original del transcriptor (inmutable). El método `CorrectionService::applyToSegments(array $segments): array` SHALL retornar el array mutado para que `TranscriptionProcessor` lo consuma directamente; la mutación SHALL hacerse por referencia de índice (`$segments[$i]['text'] = ...`), no por copia local.

#### Scenario: SRT nuevo se parsea y aplica correcciones
- **WHEN** el módulo de transcripción termina un SRT done, el `SrtParser` extrae segmentos con `text=raw_text`, y `CorrectionService::applyToSegments($segments)` retorna el array con `text` corregido
- **THEN** cada `TranscriptionSegment` queda con `text_raw=raw` y `text=corrected`. El matching posterior usa `text` (ya corregido)

#### Scenario: applyToSegments es idempotente
- **WHEN** se invoca `applyToSegments()` dos veces seguidas sobre el mismo array
- **THEN** el resultado final es idéntico (no dobletea reemplazos)

### Requirement: Comando retroactivo reaplica el diccionario a todas las transcripciones
El sistema SHALL exponer una familia de comandos para ejecutar la aplicación retroactiva del diccionario aprobado de manera desacoplada de la petición HTTP:

- `php artisan corrections:apply-run --run-id=<id> [--dry-run] [--chunk=500]` para uso desde CLI.
- `POST /ia/correcciones/apply-retroactive` (admin) que genera un `runId`, lanza el comando en background vía `execBackground()` y registra el estado en `Cache::get("corrections_apply:{runId}")`.
- `GET /ia/correcciones/apply-retroactive/{runId}` para polling de progreso desde la UI.
- El comando y los endpoints SHALL coordinar el progreso usando la cache key compartida, con TTL 4 horas.

#### Scenario: Admin lanza el modo real desde UI
- **WHEN** el admin confirma la aplicación retroactiva en el modal
- **THEN** la UI recibe `{runId}`, hace polling cada 2 segundos a `GET /ia/correcciones/apply-retroactive/{runId}` hasta que `status` sea `done` o `error`

#### Scenario: Admin lanza desde CLI
- **WHEN** el admin corre `php artisan corrections:apply-run --run-id=cli_<timestamp> --dry-run`
- **THEN** el sistema cuenta cuántos segmentos serían modificados con el diccionario actual sin tocar la BD

#### Scenario: Comando respeta transacciones por chunk
- **WHEN** el comando está procesando
- **THEN** cada chunk de 500 segments corre dentro de su propia transacción. Si un chunk falla, los anteriores quedan guardados y el error se reporta (no se pierde progreso)

#### Scenario: Polling refleja estado
- **WHEN** el cliente hace `GET /ia/correcciones/apply-retroactive/{runId}` durante una corrida
- **THEN** recibe `{status: 'running', progress: <last_id>, total: <count>, updated: <count_so_far>, started_at, finished_at, error_message}`

### Requirement: Métricas de aplicación por corrección
El sistema SHALL incrementar `applies_count` en cada corrección cada vez que se aplica a un segmento (en parseo nuevo o comando retroactivo). El conteo SHALL ser idempotente: re-ejecutar el retroactivo sobre un segmento ya corregido NO incrementa el contador. El cálculo se hace por delta dentro de cada chunk, no como acumulador entre chunks.

#### Scenario: Contador se incrementa al aplicar
- **WHEN** se aplica una corrección a 50 segmentos durante un `corrections:apply-run`
- **THEN** la columna `applies_count` de esa corrección se incrementa en 50

#### Scenario: Re-ejecución no sobrecuenta
- **WHEN** se corre `corrections:apply-run` dos veces seguidas sobre el mismo corpus
- **THEN** el segundo run no incrementa `applies_count` para segmentos que ya estaban corregidos (la métrica refleja cuántos segmentos acabó modificando la corrección, no cuántas veces el worker la vio)

## ADDED Requirements

### Requirement: Bugfix de mutación del array
El método `CorrectionService::applyToSegments(array $segments): array` SHALL retornar el array de segmentos con `text` corregido. La mutación SHALL hacerse por referencia de índice sobre el array original, no sobre una copia local.

#### Scenario: El retorno del método se usa en TranscriptionProcessor
- **WHEN** `TranscriptionProcessor::processDoneWithSrt()` llama `applyToSegments($segmentsForCorrections)`
- **THEN** el return reemplaza `$segmentsForCorrections` en el scope del proceso y los `INSERT` posteriores escriben `text` ya corregido

### Requirement: Bugfix de CSRF selector en UI admin
El selector `document.querySelector('meta[name="csrf-token"]')` SHALL estar correctamente cerrado en todos los scripts de Alpine.js que hacen fetch al backend. El bug pre-existente en `ia/correcciones/index.blade.php:213` bloqueaba las acciones Alpine (aprobar, rechazar, nueva, re-aplicar) con error de sintaxis silencioso.

#### Scenario: Acciones Alpine funcionales
- **WHEN** el admin en `/ia/correcciones` hace click en "Aprobar" sobre una corrección pendiente
- **THEN** la petición POST a `/ia/correcciones/{id}/approve` se envía con header `X-CSRF-TOKEN` válido y la consola del navegador no muestra error de sintaxis

### Requirement: Seed inicial con realizaciones reales
El sistema SHALL disponer de un seeder `CorreccionesDictionarySeeder` que inserta las correcciones detectadas en el corpus de producción. Las realizaciones iniciales son:

```
"Active to"            → "Activa tu"
"active to"            → "Activa tu"
"valor the time"       → "valorar el tiempo"
"orgular"              → "orgullo"
"with orgasm"          → "with orgullo"
"applicate vacunes"    → "aplicarse vacunas"
```

Estas realizaciones fueron detectadas en la cuña radial "Bogotá Modo Metro" repetida en al menos 7 emisoras el 2026-07-24.

#### Scenario: Seeder es idempotente
- **WHEN** el admin corre `php artisan db:seed --class=CorreccionesDictionarySeeder` dos veces
- **THEN** la tabla `corrections` no tiene duplicados gracias a `upsertApproved()` que actualiza el `correct_text` de la fila approved existente

#### Scenario: Seeder requiere admin
- **WHEN** el seeder corre y no existe un usuario con `role='admin'`
- **THEN** el seeder aborta con mensaje claro

### Requirement: Comando async con runId
El sistema SHALL exponer `php artisan corrections:apply-run {--run-id=required} {--dry-run} {--chunk=500}` que ejecuta el retroactivo como un proceso desacoplado de la petición HTTP. El `runId` es una clave de `Cache` con TTL 4h que mantiene el estado de la corrida.

#### Scenario: Comando se lanza desde controller
- **WHEN** el controller recibe `POST /ia/correcciones/apply-retroactive` con `{dry_run: false, chunk: 500}`
- **THEN** genera `runId`, inicializa cache, ejecuta `execBackground("php artisan corrections:apply-run --run-id={runId} --chunk=500")`, retorna `{runId}` al cliente

#### Scenario: Cache tiene TTL apropiado
- **WHEN** el comando inicia una corrida
- **THEN** la cache key `corrections_apply:{runId}` tiene TTL 4h, suficiente para una corrida real (~2h) con margen

#### Scenario: Comando aborta si runId no existe
- **WHEN** se ejecuta `php artisan corrections:apply-run --run-id=orphan`
- **THEN** el comando aborta con mensaje claro y no modifica BD

## REMOVED Requirements

### Requirement: Preview sintético de retroactivo
**Eliminado**: el endpoint `GET /ia/correcciones/preview-retroactive` que ejecutaba un dry-run completo en petición HTTP. Reemplazado por la corrida async con `runId` desde `POST /ia/correcciones/apply-retroactive` y el polling `GET /ia/correcciones/apply-retroactive/{runId}`.

#### Scenario: Eliminación de la API
- **WHEN** la UI solicita el preview del retroactivo
- **THEN** el endpoint devuelve 404; la UI debe lanzar el runId para tener el conteo real
