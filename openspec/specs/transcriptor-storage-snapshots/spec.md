# transcriptor-storage-snapshots Specification

## Purpose
Persistir métricas por storage del transcriptor cada 15 minutos en una tabla dedicada
para alimentar la UI del tab Storages sin pagar JOINs costosos en cada render, y
para detectar drift (storage con backlog creciente, sin envíos) sin tener que
escanear la tabla completa de `transcriptions`.

## Requirements

### Requirement: Tabla `transcription_storage_snapshots` con agregados por storage

El sistema SHALL crear la tabla:

```sql
CREATE TABLE transcription_storage_snapshots (
    storage_provider_id BIGINT NOT NULL,
    captured_at         TIMESTAMPTZ NOT NULL,
    pending_count       INT NOT NULL DEFAULT 0,
    inflight_count      INT NOT NULL DEFAULT 0,
    sent_count          INT NOT NULL DEFAULT 0,
    error_count         INT NOT NULL DEFAULT 0,
    oldest_pending_age_seconds INT NULL,
    remote_queue_queued INT NULL,
    PRIMARY KEY (storage_provider_id, captured_at)
);
CREATE INDEX transcription_storage_snapshots_recent_idx
  ON transcription_storage_snapshots (storage_provider_id, captured_at DESC);
```

Los campos se llenan con agregación LEFT JOIN sobre `storage_providers LEFT JOIN
files LEFT JOIN transcriptions` filtrando por `sp.transcription_enabled = true`
(ver design.md §1.5 para el SQL completo).

#### Scenario: Snapshot normal para storage con 87 pendientes, 3 in flight, 0 done en 15 min

- **WHEN** `transcriptor:storage-snapshot` corre a las 14:30
- **THEN** INSERTa una fila `(storage_provider_id=5, captured_at=2026-09-15T14:30:00-05:00,
  pending_count=87, inflight_count=3, sent_count=0, error_count=0,
  oldest_pending_age_seconds=240, remote_queue_queued=42)`

#### Scenario: Storage sin transcripción habilitada queda excluido

- **WHEN** `storage_provider.transcription_enabled=false`
- **THEN** el LEFT JOIN produce 0 filas agregadas y el storage NO aparece en el snapshot

### Requirement: `remote_queue_queued` se persiste para evitar HTTP en cada render

El campo `remote_queue_queued` SHALL leer el valor cacheado de
`Cache::flexible('transcriptor:remote_stats', 30s)` (mismo helper que el regulador
usa), evitando golpear `/api/metrics/overview` por cada render del tab Storages.

#### Scenario: Múltiple snapshots consecutivos leen mismo cache hit

- **WHEN** tres snapshots consecutivos corren dentro de 30 s
- **THEN** solo el primero pega al upstream; los siguientes leen el cache caliente
- **AND** `remote_queue_queued` se mantiene estable entre snapshots

### Requirement: Comando `transcriptor:storage-snapshot` corre cada 15 minutos

El sistema SHALL registrar en `routes/console.php`:

```
$schedule->command('transcriptor:storage-snapshot')
    ->everyFifteenMinutes()
    ->withoutOverlapping(60);
```

El comando SHALL ser idempotente: dos ejecuciones simultáneas (si las hay) no
producen duplicados gracias al `ON CONFLICT (storage_provider_id, captured_at)
DO NOTHING`.

#### Scenario: Primera ejecución del día

- **WHEN** el operador deploya y el cron se activa por primera vez
- **THEN** el snapshot aparece a los 15 min de deploy y se actualiza cada 15 min

#### Scenario: Snapshot cacheado para render rápido

- **WHEN** la UI pide `GET /ia/api-transcriptor/storages/{id}/snapshot`
- **THEN** el controller responde con la última fila `(storage_provider_id=id,
  captured_at DESC LIMIT 1)` en <50 ms con el índice
  `transcription_storage_snapshots_recent_idx`

### Requirement: Retención de 7 días con `transcriptor:prune-storage-snapshots`

El sistema SHALL exponer `php artisan transcriptor:prune-storage-snapshots --days=7`
que borra `DELETE FROM transcription_storage_snapshots WHERE captured_at <
NOW() - interval '7 days' LIMIT 10000` en loop hasta que no queden candidatos.
Registrado en schedule como job diario a las 03:00 Bogota.

#### Scenario: 30 días de snapshots = ~2.880 filas por storage

- **WHEN** pasan 30 días sin prune
- **THEN** la tabla crece ~2.880 × N_storages filas (≈ 200k con 70 storages)
- **AND** `transcriptor:prune-storage-snapshots --days=7` las reduce a ≤ 7 días
  en pocos segundos (índice sobre `captured_at` lo cubre)

### Requirement: Endpoint `GET /ia/api-transcriptor/storages/{id}/snapshot` con delta

El sistema SHALL exponer el endpoint bajo middleware `['auth','admin']` que retorna:

```json
{
  "storage_provider_id": 5,
  "current": {
    "pending_count": 87,
    "inflight_count": 3,
    "sent_count": 12,
    "error_count": 0,
    "oldest_pending_age_seconds": 240,
    "remote_queue_queued": 42,
    "captured_at": "2026-09-15T14:30:00-05:00"
  },
  "previous": {
    "pending_count": 75,
    "captured_at": "2026-09-15T14:15:00-05:00"
  },
  "delta": {
    "pending_count": +12,
    "since": "hace 15 minutos"
  }
}
```

#### Scenario: Snapshot fresco y delta vs anterior

- **WHEN** el admin abre el tab Storages
- **THEN** cada storage muestra su último snapshot + delta textual ("Pendientes: 87
  (+12 vs hace 15 min)")

#### Scenario: Sin snapshot anterior (primera corrida)

- **WHEN** el snapshot actual es el primero del storage
- **THEN** `previous=null`, `delta=null`, y la UI muestra solo el valor actual
  con marca "primer snapshot"

### Requirement: Privacidad cliente preservada

El endpoint SHALL verificar el rol del usuario. Si `session('user')->role === 'cliente'`,
el endpoint SHALL devolver solo los campos propios del cliente (no `remote_queue_queued`
ni métricas globales del storage del admin).

#### Scenario: Cliente intenta ver snapshot de storage del admin

- **WHEN** el cliente hace `GET /ia/api-transcriptor/storages/5/snapshot` siendo storage 5
  del admin
- **THEN** el endpoint responde 403 (storage no accesible para el cliente) o un payload
  vacío según `MediaClipController::canAccessFile`
