# dashboard-storage-health-partial Specification

## Purpose

Expone un partial Blade auto-gated en el dashboard admin que muestra hallazgos de `storage:audit-ownership` (categorías `untraversable` y `owner_mismatch`) por `StorageProvider`, con el comando CLI exacto para repararlos. El partial NUNCA se renderiza para rol cliente.

## ADDED Requirements

### Requirement: Partial admin con gating por flag global

El partial `dashboard.partials._storage_health` SHALL renderizarse en el dashboard admin cuando `SystemSetting('storage_health_enabled')` es truthy. SHALL NO renderizar nada cuando el flag es falsy o no existe, sin lanzar excepciones ni romper el layout circundante. El partial SHALL recibir del controller `$context` con valor `'admin'` y `$data` con la forma documentada en el header del archivo.

#### Scenario: Admin con flag activado y hallazgos presentes

- **WHEN** un admin carga `/dashboard` con `SystemSetting('storage_health_enabled') === '1'` y `SystemSetting('storage_health:55:untraversable_count')` igual a `8`
- **THEN** SHALL renderizar una tarjeta con el conteo `8 untraversable` para el storage 55
- **AND** SHALL incluir el comando CLI textual a la altura de la tarjeta

#### Scenario: Admin con flag desactivado

- **WHEN** un admin carga `/dashboard` con `SystemSetting('storage_health_enabled') === '0'` o sin la fila
- **THEN** SHALL NO renderizar el contenedor del partial ni disparar queries adicionales

#### Scenario: Admin con flag activado y cero hallazgos

- **WHEN** un admin carga `/dashboard` con flag activado pero todos los `storage_health:<id>:untraversable_count` son `0` y `owner_mismatch_count` son `0`
- **THEN** SHALL NO renderizar la tarjeta (cero ruido cuando todo está saludable)

### Requirement: Partial restringido a contexto admin

El partial SHALL NO incluirse en el dashboard cliente. El controller `DashboardController::buildClient()` SHALL NO añadir la entrada `budget.services` al array `$dashboardData` que pasa a `user.blade.php`. El admin SHALL recibir `$data` con la forma `{items: [{storage_id, name, untraversable_count, owner_mismatch_count, last_audit_at}], audit_finished_at}`.

#### Scenario: Cliente carga /dashboard

- **WHEN** un usuario con rol cliente carga `/dashboard`
- **THEN** SHALL NO existir el contenedor `data-dashboard-partial="storage-health"` en el HTML resultante
- **AND** SHALL NO ejecutarse ninguna query a `SystemSetting('storage_health:*')` para este usuario

#### Scenario: Admin carga /dashboard con múltiples hallazgos

- **WHEN** un admin carga `/dashboard` con flag activado y 3 storages con hallazgos
- **THEN** SHALL recibir `$data['items']` con 3 entradas
- **AND** SHALL renderizar la tarjeta con una fila por storage, mostrando conteos y nombre legible

### Requirement: Tarjeta muestra comando CLI de remediación

La tarjeta SHALL mostrar, por cada storage con hallazgos, el comando textual exacto para inspeccionar (`storage:audit-ownership --storage=<id> --limit=N`) y para reparar (`sudo php artisan storage:repair-ownership --storage=<id> --apply --yes`). El comando SHALL estar en un bloque copy-pasteable, no en un enlace href.

#### Scenario: Storage con 8 untraversable

- **WHEN** la tarjeta renderiza el storage 55 con `untraversable_count = 8`
- **THEN** SHALL mostrar el bloque textual con el comando de inspección
- **AND** SHALL mostrar el bloque textual con el comando de reparación prefijado con `sudo`

#### Scenario: Storage con solo owner_mismatch

- **WHEN** la tarjeta renderiza un storage con `owner_mismatch_count > 0` y `untraversable_count = 0`
- **THEN** SHALL mostrar el comando de inspección (que detecta owner-mismatch sin privilegios)
- **AND** SHALL NO mostrar el comando `repair-ownership` (que es solo para untraversable)

### Requirement: Selectores estables para tours

El partial SHALL declarar `data-dashboard-partial="storage-health"` en su contenedor raíz para que los tours de onboarding apunten al elemento de forma estable ante refactors internos.

#### Scenario: Render parcial con selector

- **WHEN** la tarjeta renderiza el storage 55 con flag activado
- **THEN** SHALL existir `data-dashboard-partial="storage-health"` en el contenedor
- **AND** SHALL ser único en el DOM (no duplicar el contenedor)

### Requirement: Cache por epoch del dashboard admin

El provider SHALL leer las métricas desde `SystemSetting` directamente (no desde cache Redis), dado que el audit las escribe al finalizar y el TTL de la spec `dashboard-tiered-cache` (frío 900s) puede hacer que la tarjeta muestre valores stale cuando el audit acaba de correr. La query SHALL ser O(N) sobre los storagees con flag activado, no O(storages totales).

#### Scenario: Audit acaba de correr y admin recarga dashboard

- **WHEN** el admin carga `/dashboard` 30 segundos después de que el audit terminó
- **THEN** SHALL leer los conteos frescos desde `SystemSetting` (no del cache frío)
- **AND** SHALL renderizar la tarjeta con los conteos actualizados

#### Scenario: Sin hallazgos durante más de 24 horas

- **WHEN** el último `audit_finished_at` registrado tiene más de 24 horas de antigüedad y todos los conteos están en 0
- **THEN** SHALL NO renderizar la tarjeta (estado saludable estable)