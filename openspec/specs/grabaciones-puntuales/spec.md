# grabaciones-puntuales Specification

## Purpose
TBD - created by archiving change fix-ruta-destino-persistente-en-limpiar. Update Purpose after archive.
## Requirements
### Requirement: ruta_base persists in grabador_usuario pivot

The system MUST persist the `ruta_base` configured in the Grabadores module as a column on the `grabador_usuario` pivot table, so that the pair `(usuario, grabador)` has exactly one source of truth for its base path.

#### Scenario: asigarUsuario stores ruta_base on assignment
- **WHEN** an admin submits `asignarUsuario` with `ruta_base = /disco/grabaciones`
- **THEN** the system writes `ruta_base = '/disco/grabaciones'` to the row `(grabador_id, user_id)` of `grabador_usuario`
- **AND** the system writes `ruta_destino = '/disco/grabaciones/<slot_nombre>'` on each of the 10 newly created `canales`

#### Scenario: actualizarAsignacion updates ruta_base on pivot
- **WHEN** an admin submits `actualizarAsignacion` with `ruta_base = /nuevo/path`
- **THEN** the system updates `ruta_base = '/nuevo/path'` on the corresponding `grabador_usuario` row
- **AND** the system rewrites every existing `canales.ruta_destino` for that user in that grabador as `'/nuevo/path/<slot_nombre>'`

#### Scenario: migration backfills existing pares
- **WHEN** the migration runs and there exist `(grabador_id, user_id)` pairs in `grabador_usuario`
- **THEN** for each pair that has at least one `canal` with non-null `ruta_destino`, the system derives `ruta_base` by stripping the trailing segment of the first such canal and writes it to the pivot row

### Requirement: Limpiar preserves ruta_destino

The `Limpiar` action on a `Canal` (implemented by `CanalController::destroy`) MUST reset only the remote-registration state of the channel (`api_canal_id`, `link_origen`, `detalle`) and MUST NOT modify `ruta_destino`.

#### Scenario: Limpiar does not clear ruta_destino
- **WHEN** an admin confirms the `Limpiar` modal on a canal whose `ruta_destino = /disco/grabaciones/Puntual_03`
- **THEN** after the action, `canales.ruta_destino` is still `/disco/grabaciones/Puntual_03`
- **AND** `canales.api_canal_id` is `NULL`
- **AND** `canales.link_origen` is `NULL`
- **AND** `canales.detalle` is `NULL`
- **AND** the remote canal is deleted via `DELETE /canales/{api_canal_id}`

### Requirement: Channel update restores ruta_destino from pivot when empty

When `CanalController::update` is invoked with `link_origen` and the canal has no `api_canal_id`, the system MUST ensure `ruta_destino` is populated before invoking `TcloudApiService::crearCanal`. If the local `ruta_destino` is empty, the system MUST derive it from `grabador_usuario.ruta_base` concatenated with the canal's `slot_nombre`, persist it on the canal, and pass it to the service.

#### Scenario: re-adding link_origen after Limpiar restores ruta_destino
- **WHEN** an admin submits `update` on a canal with `link_origen = <url>` and `ruta_destino IS NULL` and `api_canal_id IS NULL`
- **AND** the pivot row `(grabador_id, user_id)` has `ruta_base = /disco/grabaciones`
- **THEN** the system sets `canales.ruta_destino = '/disco/grabaciones/<slot_nombre>'` and persists it
- **AND** the system calls `TcloudApiService::crearCanal` which receives the populated `ruta_destino`

#### Scenario: update without path lookup does not lose explicit ruta_destino
- **WHEN** an admin submits `update` on a canal with `ruta_destino = /explicit/path/<slot>`
- **THEN** the system preserves that `ruta_destino` and does not overwrite it with a pivot-derived value

### Requirement: TcloudApiService includes ruta_descarga from pivot when canal is empty

`TcloudApiService::crearCanal` MUST ensure that the payload sent to the remote grabador contains a non-empty `ruta_descarga` whenever a `link_origen` is present. If `$canal->ruta_destino` is empty, the service MUST look up `grabador_usuario.ruta_base` and use `<ruta_base>/<slot_nombre>`. Only if both are empty MUST the service fall back to `generarRutaDescarga()`.

#### Scenario: crearCanal uses pivot when local is empty
- **WHEN** `crearCanal` is called with a canal whose `ruta_destino IS NULL`
- **AND** the pivot row `(grabador_id, user_id)` has `ruta_base = /disco/grabaciones`
- **AND** `slot_nombre = Puntual_03`
- **THEN** the payload to the remote includes `ruta_descarga = '/disco/grabaciones/Puntual_03'`

#### Scenario: crearCanal falls back to generarRutaDescarga only as last resort
- **WHEN** `crearCanal` is called with a canal whose `ruta_destino IS NULL`
- **AND** no pivot row exists OR its `ruta_base IS NULL`
- **THEN** the payload includes `ruta_descarga` from `generarRutaDescarga()`
- **AND** the service logs a warning identifying the canal

### Requirement: Channel creation uses pivot ruta_base

`CanalController::store` MUST derive `ruta_destino` from `grabador_usuario.ruta_base` when creating an individual channel (outside the bulk `asignarUsuario` flow), so that channels created via the `crear canal` form also respect the admin-configured base path.

#### Scenario: store derives ruta_destino from pivot
- **WHEN** a non-admin user submits `store` with `grabador_id = G`, `slot_nombre = S`
- **AND** the pivot `(G, user_id)` has `ruta_base = /disco/grabaciones`
- **THEN** the system creates the canal with `ruta_destino = '/disco/grabaciones/S'`
- **AND** it passes that `ruta_destino` to `crearCanal`