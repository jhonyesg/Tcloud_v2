## Context

El esquema actual identifica el storage personal por prefijo de path (`/home/www/Usuarios_tcloud/`) en 6+ lugares de la app y dentro de un trigger de BD, mientras `files.is_personal` se escribe en 9 lugares sin que nadie la lea. `user_storages.transcription_enabled` es una columna muerta documentada en su propia migración. `external_sites.color` tiene un CHECK de 8 colores mientras la app valida 16. Ver proposal.md para la motivación.

## Goals / Non-Goals

**Goals:**
- Una única fuente de verdad para "storage personal": `storage_providers.is_personal`.
- Eliminar columnas muertas/derivadas sin pérdida de datos.
- Cerrar el gap BD↔app del catálogo de colores.
- Migración segura en producción (sin locks largos, sin downtime).

**Non-Goals:**
- No refactorizar `FileController` (1205 líneas) — es el change de backend.
- No extraer credenciales S3 del jsonb.
- No tocar `transcription_access` ni el pipeline de transcripción.

## Decisions

### D1. `is_personal` vive en `storage_providers`, no en `files`
La columna `files.is_personal` es un espejo del storage: todos los archivos de un storage personal comparten el flag. Moverlo a `storage_providers` elimina redundancia y hace la cuota consultable con un JOIN simple. Alternativa considerada: derivar siempre por path (cero migración) — descartada porque el path es un detalle operativo que ya se filtró a 6+ lugares y a un trigger de BD; la bandera lo encapsula.

### D2. Backfill por prefijo, una sola vez, en la migración
La migración marca `is_personal = true` donde `base_path LIKE '/home/www/Usuarios_tcloud/%'`. Es la única lectura del prefijo que queda en el sistema; después, todo lee la bandera. El prefijo se centraliza en `config('storage.personal_base_path')` para la creación de nuevos storages personales.

### D3. Orden de migración: primero crear bandera, después dropear columnas
Secuencia en una sola migración (o dos, si se prefiere granularidad):
1. `ADD COLUMN storage_providers.is_personal boolean NOT NULL DEFAULT false`
2. `UPDATE ... SET is_personal = true WHERE base_path LIKE ...` (backfill)
3. `DROP INDEX idx_user_storages_tx_enabled` + `DROP COLUMN user_storages.transcription_enabled`
4. `DROP INDEX idx_files_personal` + `DROP COLUMN files.is_personal`
5. Reemplazar el CHECK de `external_sites.color` (DROP + ADD con los 16 colores)
6. Recrear el trigger `fn_storage_provider_delete_quota` leyendo `is_personal`

El trigger se recrea con `CREATE OR REPLACE FUNCTION` (mismo nombre, nueva lógica) — no hay ventana sin trigger.

### D4. El trigger de cuota pasa a `is_personal`
`fn_storage_provider_delete_quota` cambia `OLD.base_path LIKE '/home/www/Usuarios_tcloud/%'` por `OLD.is_personal`. Mismo comportamiento, fuente de verdad nueva. `RecalcPersonalQuota` cambia su SQL de `sp.base_path LIKE` a `sp.is_personal = true`.

### D5. La app deja de escribir `files.is_personal`
Se quita de `File::$fillable` y `$casts`, y de los 9 puntos de escritura (FileController ×5, PublicShareController ×2, StorageSyncService, DiskScannerService ×2). El modelo ignora la columna antes de que la migración la dropee, así el deploy de código y la migración son independientes.

### D6. CHECK de color: DROP + ADD en la misma transacción
`ALTER TABLE external_sites DROP CONSTRAINT external_sites_color_check` seguido de `ADD CONSTRAINT` con los 16 colores. No hay datos que violen el nuevo CHECK (la app ya solo escribe 16 colores), así que no requiere limpieza previa.

## Risks / Trade-offs

- [El backfill marca storages personales por prefijo y un storage no-personal pudiera vivir bajo ese path] → El prefijo es el contrato operativo actual (lo usan 6+ lugares); la migración solo formaliza el estado existente. Verificación post-migración: `SELECT id, name, base_path, is_personal FROM storage_providers ORDER BY is_personal DESC`.
- [Dropear `files.is_personal` rompe código que aún la escriba] → El código se actualiza en el mismo change y el modelo deja de referenciarla; la migración corre después del deploy.
- [`idx_files_personal` era usado por alguna consulta] → Verificado: ninguna consulta en la app la usa; el índice parcial solo servía a la columna espejo.
- [Recrear el trigger con `CREATE OR REPLACE` falla si cambia la firma] → La firma no cambia (`RETURNS TRIGGER`, sin argumentos); solo cambia el cuerpo.
- [El CHECK de color nuevo rechaza datos existentes] → No hay datos fuera de los 16 colores (la app los valida desde el 2026-09-04); si aparecieran, la migración falla y se audita antes de reintentar.

## Migration Plan

1. Deploy del código (modelos, controladores, comandos, trigger) — la app sigue funcionando con las columnas viejas presentes.
2. `php artisan migrate` — ejecuta la migración de normalización (backfill + drops + CHECK + trigger).
3. Verificación: `php artisan files:recalc-personal-quota` (debe reportar 0 cambios si el backfill fue correcto), y prueba manual de crear/editar un site con color `indigo`.
4. Rollback: la migración `down()` restaura columnas, índice e índice parcial, y revierte el CHECK a 8 colores. El trigger se restaura a la versión por prefijo.

## Open Questions

Ninguna. Las decisiones de alcance (qué se normaliza y qué no) quedaron resueltas en la exploración previa y en el proposal.
