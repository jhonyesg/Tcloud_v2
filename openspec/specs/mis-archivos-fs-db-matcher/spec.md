## Purpose

Capability `mis-archivos-fs-db-matcher`: define cómo un job periódico reconcilia el filesystem con la tabla `files`, manteniendo la BD caliente con metadata operativa (shares, transcripciones, permisos) sin que el listado dependa de ella.

## Requirements

### Requirement: Job periódico con modos configurables
Un job SHALL correr periódicamente para reconciliar BD↔filesystem con tres modos seleccionables.

#### Scenario: Modo hot (paths recientes)
- **WHEN** se ejecuta `mis-archivos:match-fs-db --mode=hot_only` o `--mode=hot_warm` o `--mode=hot_warm_cold`
- **THEN** el matcher consulta `Cache::get("mis_archivos:recent_paths:{storageId}")` (paths tocados por uploads/downloads en últimas 2h)
- **THEN** para cada path en esa lista, ejecuta `matchOne()` que crea/actualiza la fila en BD o marca `pending_deletion_at` si el path no existe en disco
- **THEN** retorna estadísticas `{scanned, created, updated, missing_marked}`

#### Scenario: Modo warm (niveles 1-3)
- **WHEN** se ejecuta con `--mode=hot_warm` o `--mode=hot_warm_cold`
- **THEN** el matcher hace `scandir()` recursivo hasta nivel 3 desde `storage.base_path`
- **THEN** ignora dotfiles (`.`, `..`, archivos ocultos) y directorios `node_modules`, `.git`
- **THEN** para cada directorio/archivo encontrado, ejecuta `matchOne()`

#### Scenario: Modo cold (full sweep)
- **WHEN** se ejecuta con `--mode=hot_warm_cold`
- **THEN** el matcher recorre `files` con `chunkById(500, ...)` buscando filas con `pending_deletion_at IS NULL`
- **THEN** para cada fila, verifica si el path existe en disco; si no, llama `markMissing()`

### Requirement: Resolver por nombre y ruta
El matcher SHALL identificar filas en BD por la combinación `(storage_provider_id, path)`.

#### Scenario: Match exacto crea nueva fila
- **WHEN** el matcher encuentra el path `Bolivar/28092026` en disco y NO existe fila en BD para `(134, 'Bolivar/28092026')`
- **THEN** crea la fila con `name='28092026'`, `path='Bolivar/28092026'`, `storage_provider_id=134`, `owner_id` desde `user_storages` (preferentemente el primer full-permission user, fallback a 1), `is_folder=true`, `size=0`, `pending_deletion_at=null`
- **THEN** incrementa contador `created`

#### Scenario: Match exacto actualiza fila existente
- **WHEN** el matcher encuentra el path `Bolivar/28092026` con `mtime` distinto al `file_modified_at` en BD
- **THEN** actualiza `size`, `is_folder`, `file_modified_at`, `updated_at`
- **THEN** limpia `pending_deletion_at` si estaba seteado (recuperación)
- **THEN** incrementa contador `updated`

#### Scenario: Path en BD sin contraparte en disco
- **WHEN** el matcher revisa una fila con `path='Bolivar/28092026'` y `realpath()` retorna false o `is_dir()/is_file()` retorna false
- **THEN** setea `pending_deletion_at = now()` en esa fila (si estaba null)
- **THEN** incrementa contador `missing_marked`

### Requirement: Purga tras ventana de gracia
Las filas con `pending_deletion_at` vencido SHALL purgarse automáticamente.

#### Scenario: Gracia vencida → DELETE
- **WHEN** una fila tiene `pending_deletion_at < now() - INTERVAL '7 days'` (o `SystemSetting('mis_archivos.missing_grace_days')` días)
- **THEN** el modo cold la borra (DELETE FROM files WHERE id = ?)
- **THEN** el FK CASCADE borra `shares.file_id` correspondientes
- **THEN** el FK SET NULL pone `transcriptions.file_id = NULL` para no perder el `srt_content`
- **THEN** incrementa contador `missing_purged`

#### Scenario: Gracia vigente → preserva
- **WHEN** una fila tiene `pending_deletion_at` dentro de la ventana de gracia
- **THEN** NO se borra, se loguea en `mis_archivos.matcher_grace_pending` con conteo

### Requirement: Idempotencia
Ejecutar el matcher múltiples veces SHALL producir el mismo estado final.

#### Scenario: Doble ejecución no duplica filas
- **WHEN** se ejecuta el matcher dos veces seguidas sin cambios en disco
- **THEN** la segunda ejecución no crea filas nuevas (todas las entradas en disco ya tienen match en BD)
- **THEN** los contadores `created`, `missing_marked`, `missing_purged` quedan en cero
- **THEN** solo `scanned > 0` y posiblemente `updated = 0` también

#### Scenario: Cambios durante ejecución se reconcilian en próxima corrida
- **WHEN** entre dos ejecuciones del matcher se crea un archivo en disco
- **THEN** la primera ejecución no lo ve (aún no estaba)
- **THEN** la segunda ejecución lo crea en BD (incremental)

### Requirement: Rate limiting opcional
El matcher SHALL respetar un límite de filas procesadas por minuto cuando el setting está activo.

#### Scenario: Rate limit activo
- **WHEN** `SystemSetting('mis_archivos.matcher_rows_per_minute', 2000)` está configurado y se excede
- **THEN** el matcher pausa (`sleep`) hasta cumplir el rate
- **THEN** loguea `mis_archivos.matcher_throttled` con conteo y pausa aplicada
- **THEN** NO aborta, continúa en la próxima iteración

### Requirement: Schedule registration
El matcher SHALL correr automáticamente vía Laravel scheduler.

#### Scenario: Hot_warm cada 5 minutos
- **WHEN** el scheduler ejecuta `routes/console.php`
- **THEN** `Schedule::command('mis-archivos:match-fs-db --mode=hot_warm')->everyFiveMinutes()->withoutOverlapping(4)` corre sin overlap con corridas previas

#### Scenario: Hot_warm_cold diario a las 03:00 Bogota
- **WHEN** el scheduler llega a las 03:00 hora Bogota
- **THEN** `Schedule::command('mis-archivos:match-fs-db --mode=hot_warm_cold')->dailyAt('03:00')->withoutOverlapping(120)` corre con lock de 2h para evitar overlap

### Requirement: Comando admin para diagnóstico
Un comando SHALL permitir al operador ver drift sin mutar nada.

#### Scenario: Reporte de drift
- **WHEN** se ejecuta `php artisan mis-archivos:fs-drift-report --storage=134`
- **THEN** lista paths en BD con `pending_deletion_at IS NOT NULL` (candidatos a purga)
- **THEN** lista paths en disco que NO están en BD (candidatos a crear)
- **THEN** retorna exit code 1 si drift > threshold, 0 en caso contrario
- **THEN** NO modifica ninguna fila
