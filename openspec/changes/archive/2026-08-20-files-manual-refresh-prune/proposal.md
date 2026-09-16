# Change: El botón «Actualizar» reconcilia la BD con el disco

## Why

El botón hacía la mitad del trabajo: creaba filas nuevas, pero nunca borraba las de archivos que ya no existen en disco. El usuario veía 118 elementos en «205 Nación» donde el disco tenía 4.

Dos causas, ambas verificadas en producción:

1. **`PruneGuard` recibía el argumento equivocado.** `StorageSyncService:191` pasaba `dbCount: $bdFiles->count()`, pero en ese punto `$bdFiles` ya tiene descontados los emparejados: son los **huérfanos**, no el total. Los tests de `PruneGuardTest` siempre trataron ese parámetro como el total de la carpeta. La regla de proporción medía `(huérfanos − disco) / huérfanos`, que no significa nada: con 100 filas y 40 borradas daba `max(0, 40−60)/40 = 0` y **permitía** borrar el 40% —por encima del umbral del 34% que decía proteger—, mientras que con la rotación diaria de los storages de prensa daba 0.965 y **rechazaba para siempre**. El candado se apretaba solo: cuanto más se desfasaba, más seguro era el rechazo. **2.053 rechazos** entre el 13 y el 20 de agosto, 1.786 por `mass_delete_ratio`.

2. **La UI nunca pedía la purga forzada.** `forcePrune` existía en toda la cadena desde el 2026-07-27, pero solo lo usaba `storage:sync --force-prune`. `FileController:118` lo omitía, así que no había forma desde la interfaz de reconciliar una carpeta con rotación.

Encima, el toast siempre salía en verde: `syncFolder()` devuelve el listado, nunca lo que hizo, así que «montaje caído» y «sincronizado sin cambios» se veían idénticos.

## What Changes

- **`PruneGuard` recibe el total de la carpeta**, no los huérfanos. La regla de proporción vuelve a medir lo que su nombre dice. El conteo exacto de huérfanos se registra aparte; `would_delete` pasa a llamarse `estimated_deletes` por honestidad.
- **El refresco manual fuerza la purga**: `refreshFiles()` manda `prune=1`, parámetro distinto de `sync=1` a propósito para que el `silentSync` de cada navegación siga bajo las guardas heurísticas. Exige admin o permiso `full`, porque `shares` y `transcriptions` cuelgan de `files` con `ON DELETE CASCADE`.
- **`syncFolderWithReport()`** devuelve `{files, stats}` con `status` de las siete salidas posibles. El toast dice la verdad y sale en ámbar cuando no se llegó a escanear.
- **Lock bloqueante (3 s) solo en el camino manual**: el cron corre cada 15 min y `silentSync` en cada navegación, así que rendirse al instante convertía el botón en un no-op mientras el usuario miraba.
- **`STORAGE_SYNC_EXPECTED_MOUNTS` incluye los volúmenes de bloque locales** (`Disco_A/B/C/I`), no solo los NFS.

## Non-goals

- Aflojar `PruneGuard` para el camino automático: sigue rechazando exactamente igual.
- Permitir saltarse `scan_untrusted`: ni el botón ni `--force-prune` lo levantan.
- Soft deletes en `File`: fuera de alcance, igual que en el cambio del 2026-07-27.
- Un diálogo de confirmación: decisión explícita del usuario — el botón es autoritativo.
- Purgar recursivamente hacia abajo desde la carpeta actual: el borrado de una carpeta ya arrastra su subárbol por cascada.

## Impact

- **Specs**: `storage-sync-overlap-guard` (modificada).
- **Migrations**: ninguna.
- **Código**: `StorageSyncService`, `PruneGuard`, `FileController::index()` (ruta `GET /files`), `resources/views/files/index.blade.php`.
- **Config**: `app/.env` y `app/.env.example` (sección `storage_sync` documentada por primera vez).
- **Datos**: 128 filas huérfanas eliminadas al verificar (storages 43 y 44). Quedan 9 storages desfasados que el botón reconcilia al usarse.
- **Rollback**: revertir el código restaura el comportamiento anterior. El borrado de filas **no es reversible**, pero solo afecta a filas cuyo archivo ya no existe en disco.
