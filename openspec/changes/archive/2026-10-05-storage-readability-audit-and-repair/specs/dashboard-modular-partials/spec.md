## ADDED Requirements

### Requirement: Storage health se renderiza solo cuando hay hallazgos

El partial `dashboard.partials._storage_health` SHALL auto-gatearse por dos condiciones combinadas: (1) `SystemSetting('storage_health_enabled')` truthy y (2) al menos un storage con `storage_health:<id>:untraversable_count > 0` o `storage_health:<id>:owner_mismatch_count > 0`. Cuando ambas condiciones son verdaderas SHALL incluir el partial desde el `DashboardController` admin con `$data` poblada por el provider. Cuando alguna es falsa SHALL omitir el `@include` sin lanzar error.

#### Scenario: Admin con flag activado y 1 storage con hallazgos

- **WHEN** un admin carga `/dashboard` con `storage_health_enabled='1'` y el storage 55 tiene `untraversable_count=8`
- **THEN** SHALL existir el contenedor `data-dashboard-partial="storage-health"` en el HTML resultante
- **AND** SHALL existir dentro del contenedor el texto "8 untraversable" para el storage 55

#### Scenario: Admin con flag activado pero cero hallazgos

- **WHEN** un admin carga `/dashboard` con flag activado pero todos los `untraversable_count` y `owner_mismatch_count` están en 0
- **THEN** SHALL NO existir el contenedor `data-dashboard-partial="storage-health"` en el HTML resultante

#### Scenario: Cliente con flag activado y hallazgos presentes

- **WHEN** un usuario con rol cliente carga `/dashboard` con `storage_health_enabled='1'` y storage 55 con `untraversable_count=8`
- **THEN** SHALL NO existir el contenedor `data-dashboard-partial="storage-health"` en el HTML resultante
- **AND** SHALL NO ejecutarse queries a `storage_health:<id>:*` para este request

### Requirement: El dashboard incluye la tarjeta storage health en el bloque correcto

El `admin.blade.php` SHALL incluir el bloque de storage health en el contexto admin, ubicado después del bloque "Módulos inteligentes" (definido por la spec `dashboard-modular-partials`) y antes del bloque "Overview" o footer del dashboard. SHALL NO requerir edición de `user.blade.php`.

#### Scenario: Render admin con tarjeta de storage health

- **WHEN** `DashboardController::buildAdmin()` retorna `$dashboardData['storage_health']` con `items` no vacíos
- **THEN** SHALL existir `@include('dashboard.partials._storage_health', ['context' => 'admin', 'data' => $dashboardData['storage_health']])` en `admin.blade.php`
- **AND** SHALL ubicarse después del bloque de Módulos Inteligentes y antes del cierre del contenedor principal

#### Scenario: Render cliente sin tarjeta de storage health

- **WHEN** un cliente carga `/dashboard`
- **THEN** SHALL NO existir ninguna referencia a `dashboard.partials._storage_health` en el HTML resultante
- **AND** SHALL NO incluirse en `user.blade.php`