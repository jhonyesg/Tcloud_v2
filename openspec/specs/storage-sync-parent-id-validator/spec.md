## Purpose

Capability `storage-sync-parent-id-validator`: garantiza que el servicio de sincronización de storages no cree carpetas que, al combinarse con su `parent_id` propuesto, produzcan una auto-referencia patológica en la cadena de breadcrumb. Cierra la causa raíz del bug observable en la capability `files-folder-tree-integrity-guard`, evitando nuevos casos en lugar de depender solo de la reparación posterior.

## Requirements

### Requirement: Validación pre-creación en el sync
`StorageSyncService::createFileFromScan` SHALL invocar `FileBreadcrumbIntegrityService::assertNoSelfNestedName` antes de crear una nueva fila de folder durante un sync.

#### Scenario: Padre con nombre distinto permite crear
- **WHEN** el sync intenta crear un folder con `name="28092026"` bajo un padre con `name="Alerta_Cartagena"`
- **THEN** el validator retorna `ok=true, reason=parent_name_differs`
- **THEN** la fila se crea normalmente vía `FileRegistry::ensure`

#### Scenario: Padre con mismo nombre pero path canónico libre permite crear
- **WHEN** el sync intenta crear un folder con `name="lib"` bajo un padre con `name="lib"` (caso `node_modules/tailwindcss/lib/lib`)
- **AND** no existe otro folder `lib` dentro del mismo `parent_id` con el mismo `path` canónico
- **THEN** el validator retorna `ok=true, reason=legitimate_lib_lib_case`
- **THEN** la fila se crea normalmente

#### Scenario: Padre con mismo nombre y duplicado preexistente aborta la creación
- **WHEN** el sync intenta crear un folder con `name="X"` bajo un padre con `name="X"`
- **AND** ya existe otro folder `X` dentro del mismo `parent_id` y mismo `storage_provider_id`
- **THEN** el validator retorna `ok=false, reason=duplicate_path_already_exists`
- **THEN** `createFileFromScan` NO crea la fila conflictiva
- **THEN** loguea `storage_sync.parent_id_cycle_refused` con `storage_id`, `parent_id`, `name`, `reason`, `hint` apuntando al comando `files:repair-breadcrumb-cycles --apply`
- **THEN** devuelve la fila preexistente (o el padre) para no perder el path visible

#### Scenario: Padre es null omite validación
- **WHEN** el sync escanea la raíz del storage y crea folder con `parent_id=null`
- **THEN** el validator retorna `ok=true, reason=no_parent`
- **THEN** la fila se crea sin validaciones adicionales

### Requirement: Idempotencia del validator
La función `assertNoSelfNestedName` SHALL ser pura (sin side-effects sobre BD) y SHALL poder invocarse múltiples veces sin cambiar el resultado entre llamadas si la BD no muta.

#### Scenario: Llamadas repetidas con BD estática
- **WHEN** se invoca `assertNoSelfNestedName` 100 veces seguidas con los mismos argumentos y sin mutar `files`
- **THEN** las 100 invocaciones retornan el mismo array `['ok'=>bool, 'reason'=>string]`

#### Scenario: Validator no consulta cache
- **WHEN** el validator evalúa un caso patológico
- **THEN** NO consulta Redis ni la cache de Laravel; ejecuta SQL directo contra `files`
- **THEN** la latencia es < 30 ms en frío (índice `files_storage_provider_id_path_unique` cubre)

### Requirement: Trazabilidad del refusal
Todo refusal del validator SHALL quedar registrado con suficiente detalle para que un operador pueda reproducir y diagnosticar.

#### Scenario: Log estructurado con campos completos
- **WHEN** el validator rechaza una creación
- **THEN** el log incluye `storage_id`, `parent_id`, `name`, `reason`, `existing_id` (cuando aplique), `path` del existing
- **THEN** el campo `hint` sugiere `php artisan files:repair-breadcrumb-cycles --apply --storage={storage_id}` con el storage_id concreto
