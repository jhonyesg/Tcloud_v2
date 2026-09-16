## MODIFIED Requirements

### Requirement: El refresco manual es una orden explícita

Desde dentro de una carpeta, «desapareció el 96% por rotación» y «despareció el 96% porque el disco no responde» son indistinguibles. Ninguna heurística sobre conteos las separa; lo que las separa es **quién lo pidió**.

- El botón «Actualizar» de `files/index.blade.php` SHALL enviar `prune=1`, **parámetro distinto de `sync=1`**, y `FileController::index()` SHALL traducirlo a `forcePrune`.
- El `silentSync()` que se dispara en cada navegación SHALL seguir usando `sync=1` a secas y quedar bajo las guardas heurísticas. La purga forzada NO SHALL ocurrir nunca de fondo.
- El refresco forzado SHALL exigir rol admin o permiso `full` sobre el storage. Borrar filas es destructivo: `shares.file_id` y `transcriptions.file_id` son `ON DELETE CASCADE` y `files` no tiene soft deletes.
- Sin ese permiso el sync SHALL ejecutarse igual, sin forzar, y la UI SHALL decírselo al usuario.

Además: las candidatas a purga SHALL evaluarse por su cuenta de FKs aguas abajo (`transcriptions.file_id`, `shares.file_id`, `media_edit_jobs.source_file_id`). Las candidatas con `linkedCount > 0` SHALL pasar al estado `availability_state='missing'` (con `missing_since_at=now()`) en lugar de borrarse. Las candidatas sin FK SHALL seguir el camino original. `forced=true` SHALL NO levantar esta protección; solo los tres modos — sync normal, scan untrusted, sin permiso de purga forzada — siguen bloqueando borrado.

#### Scenario: Storage con rotación diaria

- **WHEN** una carpeta tiene 118 filas en BD y el disco conserva 4 entradas por rotación
- **THEN** el sync automático y el cron SHALL rechazar por `mass_delete_ratio`
- **AND** el refresco manual SHALL marcar como `missing` las 114 filas huérfanas enlazadas a transcripciones y borrar solo las no-enlazadas

#### Scenario: Escaneo parcial durante un refresco manual

- **WHEN** una entrada de la carpeta devuelve EIO y el usuario pulsa «Actualizar»
- **THEN** el sistema SHALL crear y actualizar con las entradas legibles
- **AND** NO SHALL borrar ni marcar `missing` ninguna fila, porque `scan_untrusted` no admite excepción por intención humana
- **AND** SHALL decírselo al usuario en vez de reportar éxito

## REMOVED Requirements

### Requirement: `dbCount` significa solo huérfanos en la regla de ratio

**Reason**: El wording anterior describía la regla de ratio en términos de "huérfanos a borrar", pero el conteo debe ser contra el total de filas en BD de esa carpeta, no contra las candidatas. Sin esto, la regla 3 era ciega en su propio rango.

**Migration**: Este cambio queda subsumido en el requisito modificado de arriba (`mass_delete_ratio` se reespecifica para comparar `dbCount` total contra `diskCount`, y la regla 5 separa el camino de las candidatas con FK).
