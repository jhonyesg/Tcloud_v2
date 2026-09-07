## Why

El botón "Verificar disponibilidad de los resultados" en `Mis Recursos Compartidos` realiza un barrido en lotes de 50 para no saturar el servidor, pero **se detiene antes de agotar el conjunto filtrado**. El cliente rompe el `while` cuando `payload.checked < batchSize`, sin notar que ese último batch pudo haber estado acotado por el filtro (no por el final del dataset). Con un usuario que tiene ~70 enlaces con la misma disponibilidad, el barrido verifica ~50 y luego sale, dejando sin verificar los últimos registros que sí cumplen el filtro. El usuario percibe que "no verifica como 70 registros" aunque en realidad nunca se pidió la verificación completa.

## What Changes

- Cambiar `ShareController::verifyAvailability` para paginar con un cursor estable por `id` (`after_id`) en lugar de un `LIMIT` global, y devolver explícitamente `processed_ids`, `next_cursor` y `has_more` además de los conteos actuales. Esto garantiza que cada batch avanza sobre el siguiente tramo del resultado filtrado, no sobre el mismo tramo o uno aleatorio.
- Actualizar `verifyAllFiltered()` en `shares/index.blade.php` para iterar usando `next_cursor`/`has_more` en vez de la heurística actual de `payload.checked < batchSize`. Aplicar el mismo cursor a `verifySelected()` solo si el alcance es "todos los filtrados".
- Preservar el orden visible del usuario (`sort` + `direction`) en la query de verificación, eliminando el `->reorder('shares.id')` que hoy ignora ese orden en operaciones bulk.
- Documentar el contrato nuevo en la spec `share-management` para que el escenario "Verificación bulk completa" sea testeable.

No se introducen migraciones ni nuevos endpoints. Se mantiene la misma ruta `POST /shares/availability/verify`.

## Capabilities

### New Capabilities
- (ninguna)

### Modified Capabilities
- `share-management`: el requisito de verificación bulk SHALL garantizar el procesamiento completo del conjunto filtrado, SHALL devolver un cursor estable para iterar y SHALL preservar el orden visible del usuario.

## Impact

- Backend: `app/app/Http/Controllers/ShareController.php` (`verifyAvailability`, `selectedSharesQuery`).
- Frontend: `app/resources/views/shares/index.blade.php` (`verifyAllFiltered`, lectura de respuesta).
- Spec: `openspec/specs/share-management/spec.md` (delta sobre el requisito de "Depuración bulk" o un nuevo requisito dedicado a verificación bulk).
- `app/app/Services/ShareAvailabilityService.php`: sin cambios funcionales; la firma `verify()` se mantiene, solo cambia el orquestador del batch.
- Sin migración, sin nuevos endpoints, sin cambios de rutas. `validateBulkRequest` se mantiene.
