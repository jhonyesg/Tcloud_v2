## Purpose

Define el contrato de renombrado de columnas y el vocabulario canónico para futuras extensiones del esquema: cuándo dos nombres pueden convivir, cuándo se debe migrar de raíz, y cómo auditar nombres nuevos antes de mergear.

## ADDED Requirements

### Requirement: Cada renombre de columna entrega migration reversible atómica

When a developer renames a database column for clarity, the system MUST deliver a single transactional migration that performs the rename as `ALTER TABLE ... RENAME COLUMN ...`, includes an explicit `down()` that restores the original column name, and updates all in-repository call sites in the same commit.

#### Scenario: Renombrar files.merged_into_id a files.canonical_folder_id
- **WHEN** el operador ejecuta `php artisan migrate` con la migration de rename
- **THEN** SHALL renombrar `files.merged_into_id` a `files.canonical_folder_id`
- **AND** SHALL preservar todos los valores existentes (sin pérdida de datos)
- **AND** SHALL preservar el FK constraint `files_merged_into_id_fkey` (solo cambia nombre de columna, no de constraint)
- **AND** SHALL preservar el índice `files_merged_into_id_idx`

#### Scenario: Rollback recupera el nombre original
- **WHEN** el operador ejecuta `php artisan migrate:rollback --step=1`
- **THEN** SHALL renombrar `files.canonical_folder_id` a `files.merged_into_id`
- **AND** SHALL restaurar el código afectado en una transacción git revertible
- **AND** SHALL NO requerir dump/restore de datos

### Requirement: Columnas con mismo nombre en tablas distintas deben tener semántica distinta o renaming

When two different tables use the same column name to express different domain concepts, the system MUST treat this as a clarity debt and resolve it via renaming.

#### Scenario: Confusión entre storage_providers.merged_into_id y files.merged_into_id
- **WHEN** un agente o humano razona por analogía entre `storage_providers.merged_into_id` y `files.merged_into_id`
- **THEN** las columnas SHALL tener nombres distintos que reflejen su dominio: `storage_providers.duplicate_of_storage_id` para duplicados de nodos de storage; `files.canonical_folder_id` para folders espejo que apuntan al canónico

#### Scenario: Nombres idénticos en tablas distintas solo permitidos cuando concepto idéntico
- **WHEN** dos tablas usan una columna con el mismo nombre
- **THEN** SHALL ser válido solo si el concepto modelado es el mismo (ej. `id`, `created_at`, `updated_at`, `name`, `path`)
- **AND** SHALL NO ser válido cuando los valores apuntan a FKs distintas (no es lo mismo `merged_into_id` con FK self en `files` que en `storage_providers`)

### Requirement: El campo type vs kind sigue regla de un solo discriminador canónico

When a table has two columns that could discriminate the same concept, the system MUST designate one as the canonical source and deprecate the other.

#### Scenario: storage_providers.type permanece pero se depreca
- **WHEN** código nuevo lee `StorageProvider`
- **THEN** SHALL preferir `storage_providers.kind` (enum 'local'/'external') como discriminador canónico
- **AND** SHALL documentar `type` como @deprecated en el modelo con plan de remoción
- **AND** SHALL NO usar `type` en código nuevo (linting rule recomendada)

### Requirement: Auditoría de nombres nuevos antes de merge

When a developer proposes a new column or renames an existing one, the proposal MUST include: (1) the semantic role of the column; (2) why the chosen name reflects that role; (3) blast radius analysis via grep across `app/`, `tests/`, migrations, y harnesses; (4) reversible migration plan.

#### Scenario: PR proposal incluye blast radius
- **WHEN** un PR toca columnas de base de datos
- **THEN** SHALL incluir un grep report con # sitios afectados por archivo
- **AND** SHALL nombrar cada call site modificado en la migration

## REMOVED Requirements

### Requirement: Legacy type field (placeholder para PR 2)
**Reason**: pendiente.
**Migration**: pendiente.
