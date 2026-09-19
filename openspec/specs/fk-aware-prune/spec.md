# fk-aware-prune Specification

## Purpose
Refuerza `PruneGuard` con una quinta regla que protege el trabajo terminado: una fila candidata a borrado que esté enlazada por transcripciones, comparticiones o trabajos de edición no se elimina nunca; se marca `missing` para preservar la trazabilidad sin disparar el CASCADE.

## Requirements

### Requirement: Candidatas enlazadas nunca se borran en auto-sync

`PruneGuard::decide()` SHALL aceptar `linkedCount` y SHALL evaluar la regla 5 (`orphan_linked`): cuando `linkedCount > 0`, las candidatas con FK aguas abajo SHALL marcarse `availability_state='missing'` + `missing_since_at=now()` en lugar de borrarse. Las candidatas sin FK SHALL seguir el camino original.

#### Scenario: Huérfanos vinculados a transcripciones

- **WHEN** una carpeta tiene 100 filas en BD, el escaneo ve 80, y 30 de las 20 candidatas a borrar tienen `transcriptions.file_id`
- **THEN** PruneGuard SHALL devolver `refuse('orphan_linked')` para el lote vinculado
- **AND** SHALL proponer `mark_missing=true` para esas 30 filas sin borrarlas
- **AND** las 70 sin transcripción SHALL seguir siendo candidatas a DELETE legítimo

#### Scenario: Huérfanos sin nada enlazado

- **WHEN** 20 candidatas a borrar no tienen transcripciones/shares/jobs
- **THEN** PruneGuard SHALL permitir el borrado por las reglas existentes (ratio, scanOk, etc.)

#### Scenario: Refresco manual con --force-prune y huérfanos vinculados

- **WHEN** el operador pulsa "Actualizar" con huérfanos vinculados presentes
- **THEN** `forced=true` SHALL NO levantar la regla 5 (los vinculados siguen marcándose `missing`)
- **AND** `forced=true` SHALL seguir siendo bloqueado por `scan_untrusted`

### Requirement: Estado `missing` se distingue de `unknown` y `gone`

`files.availability_state` SHALL aceptar el valor `'missing'` además de `'available'` y `'unknown'`. La columna `missing_since_at` SHALL establecerse a `now()` en la transición a `missing`.

#### Scenario: Fila pasa de unknown a missing por reconciliación

- **WHEN** el reconciliador detecta que un archivo no está en disco y la fila tiene transcripción
- **THEN** SHALL persistir `availability_state='missing'`, `missing_since_at=now()`, `last_verified_at=now()`
- **AND** SHALL NO emitir DELETE

#### Scenario: Fila vuelve a disco tras estado missing

- **WHEN** el reconciliador confirma que el archivo volvió a disco
- **THEN** SHALL persistir `availability_state='available'`, `missing_since_at=null`, `last_verified_at=now()`

### Requirement: Estado `gone` marca huérfanos confirmados sin FK

`PruneGuard` SHALL permitir el borrado efectivo solo cuando la candidata tiene FK count = 0 Y el escaneo fue fiable. Las filas que pasan el filtro SHALL persistir `availability_state='gone'` durante una ventana de auditoría de 7 días antes del DELETE físico, vía comando `files:prune-unlinked-safe`.

#### Scenario: Auditoría con ventana 7 días

- **WHEN** se ejecuta `files:prune-unlinked-safe --batch-size=500`
- **THEN** SHALL marcar 500 candidatas como `gone` y registrar log `prune_unlinked.marked { count, batch_id }`
- **THEN** en una segunda corrida con `--confirm-batch={batch_id}` SHALL ejecutar el DELETE físico
- **AND** SHALL emitir log con conteo real antes y después

### Requirement: Flag `--only-missing` filtra a filas con `availability_state='missing'`

`files:prune-unlinked-safe --only-missing` SHALL restringir la fase 1 a filas con `availability_state='missing'` Y `is_trashed=false`. Filas en estado `available` o `unknown` SHALL NO incluirse en el conteo ni en el mark, sin importar su FK count.

#### Scenario: --only-missing limita la fase 1 a archivos missing confirmados

- **WHEN** se ejecuta `files:prune-unlinked-safe --only-missing --storage=47 --dry-run` y storage 47 tiene 1154 archivos en estado `missing` (de los cuales 343 sin FKs) y 41038 en estado `available`
- **THEN** SHALL reportar como candidatos solo los 343 (no los 41038 `available` ni los 97.232 `unknown`)
- **AND** SHALL NO marcar ningún archivo en estado `available` o `unknown`

#### Scenario: --only-missing sin --storage filtra toda la BD

- **WHEN** se ejecuta `files:prune-unlinked-safe --only-missing --dry-run` sin scope de storage
- **THEN** SHALL contar solo filas `availability_state='missing' AND NOT is_trashed AND is_folder=false` sin FKs, en cualquier storage

#### Scenario: --only-missing respeta el contrato de dos fases

- **WHEN** se ejecuta `files:prune-unlinked-safe --only-missing` (fase 1)
- **THEN** SHALL marcar las candidatas como `gone` y registrar batch_id en `files_prune_batches`
- **THEN** una segunda corrida con `--confirm-batch={batch_id}` SHALL ejecutar el DELETE físico solo sobre esas filas (NO toca disponibles ni unknown)
- **AND** SHALL emitir los mismos logs `prune_unlinked.marked` / `prune_unlinked.deleted` que el comando sin flag
