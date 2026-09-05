## Purpose

Detecta el estado de accesibilidad de los `StorageProvider` declarados y dispara una reconciliación paced cuando un mount externo vuelve a estar disponible, sin sobrecargar el server.

## ADDED Requirements

### Requirement: Health-check periódico de accesibilidad

`storage:health` SHALL ejecutarse cada 5 minutos y, para cada storage con `enabled=true`, SHALL calcular accesibilidad combinando `is_dir()` + `is_readable()` + `MountGuard::detachedAncestor()`. SHALL actualizar `is_accessible` y `last_checked_at` cuando el valor calculado difiera del almacenado. SHALL NO tocar tablas `files`, `transcriptions`, `shares` ni `media_edit_jobs`.

#### Scenario: Storage local accesible todo el tiempo

- **WHEN** `storage:health` se ejecuta y un storage de `kind='local'` tiene `is_dir` + `is_readable` verdaderos
- **THEN** SHALL persistir `is_accessible=true` y `last_checked_at=now()`
- **AND** SHALL NOT emitir registros de log

#### Scenario: Storage external recién remontado

- **WHEN** `storage:health` se ejecuta y un storage de `kind='external'` pasa de `is_accessible=false` a `is_accessible=true` detectado en este tick
- **THEN** SHALL despachar `storage:reconcile --storage={id}` como proceso independiente
- **AND** SHALL registrar en log: `storage_health.reconcile_dispatched { storage_id, kind, previous_accessible }`
- **AND** SHALL escribir un TTL de 1 hora en `Cache` para no redespachar en el siguiente tick si `is_accessible` sigue siendo true

#### Scenario: Storage con mount caido

- **WHEN** `storage:health` se ejecuta y `MountGuard::detachedAncestor(base_path)` devuelve una ruta
- **THEN** SHALL persistir `is_accessible=false` y `last_checked_at=now()`
- **AND** SHALL NO despachar nada

### Requirement: Reconciliación paced al remontar

`storage:reconcile` SHALL tomar el lock `sync:storage:{id}` (no bloqueante), y si lo adquiere SHALL ejecutar `fullSync` con `force=true`. SHALL iterar las carpetas del storage usando `chunkById(50)` en lugar de `get()`, con un `sleep` configurable (`RECONCILE_PACE_SECONDS`, default 2) entre cada chunk. SHALL emitir un evento de log por cada chunk procesado con conteo de filas creadas/actualizadas/marcadas.

#### Scenario: Disco de 720k filas reconcilia en background

- **WHEN** se ejecuta `storage:reconcile --storage=5` y Disco F está accesible
- **THEN** SHALL escanear todas sus carpetas en chunks de 50 filas con 2 s entre chunks
- **AND** SHALL permitir que la UI siga respondiendo porque `fullSync` ya tiene su lock
- **AND** SHALL finalizar cuando el último chunk devuelva vacío

#### Scenario: No se reescanea si el lock está tomado

- **WHEN** otro proceso tiene `sync:storage:{id}` y se invoca `storage:reconcile --storage={id}`
- **THEN** SHALL devolver `status=skipped_locked` sin reescanear
- **AND** SHALL registrarse en log con severidad info

#### Scenario: Reconciliación con escaneo no fiable

- **WHEN** el disco es accesible pero el escaneo devuelve `scan_untrusted` para alguna carpeta
- **THEN** SHALL continuar con las carpetas que sí pudieron escanearse
- **AND** SHALL marcar las candidatas de las carpetas no fiables como `availability_state='unknown'` (NO 'missing' ni DELETE)

### Requirement: Operador puede forzar reconciliación inmediata

`admin/storages.blade.php` SHALL exponer un botón "Re-verificar" por storage con `kind='external'`. Al pulsarlo SHALL invocar `POST /admin/storages/{id}/reconcile`. La acción SHALL despachar `storage:reconcile --storage={id} --no-pacing` y devolver 202 Accepted.

#### Scenario: Botón "Re-verificar" desde admin

- **WHEN** un operador pulsa "Re-verificar" en un storage `kind='external'` con `is_accessible=true`
- **THEN** SHALL invocar `storage:reconcile --storage={id} --no-pacing` en proceso independiente
- **AND** SHALL mostrar toast "Reconciliación en curso"

#### Scenario: Operador sin permiso no ve el botón

- **WHEN** un usuario sin rol admin intenta acceder a `POST /admin/storages/{id}/reconcile`
- **THEN** SHALL devolver 403
