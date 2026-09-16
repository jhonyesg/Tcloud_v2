# Design: Impedir y limpiar la duplicación de filas en `files`

## Contexto medido

| | Antes | Después |
|---|---|---|
| Filas en `files` | 1.007.515 | 936.880 |
| Duplicados por `(storage_provider_id, path)` | **70.804** | **0** |
| Peor caso individual | 36 copias | 1 |
| Storage 134, nivel raíz | 51 filas | **17** = disco |
| Índice único | no existía | válido |

Los 3 duplicados del storage 134 se crearon en el **mismo segundo** (`10:43:57`): es una carrera
concurrente, no ejecuciones repetidas. Las 36 copias del storage 5 tienen ids en un rango de ~120,
así que salieron de **una sola ejecución** de `fullSync()` recorriendo 36 filas de carpeta duplicadas.

## D1: Una sola identidad, en un solo sitio

Convivían tres nociones, ninguna respaldada por la BD:

| Sitio | Clave |
|---|---|
| `StorageSyncService:43-46` | `(storage, parent_id, path)` + `keyBy('path')` |
| `DiskScannerService:131` | `(storage, path)` |
| `FileController::store():232` | `(parent_id, name, storage)` |

**Canónica: `(storage_provider_id, path)`**, porque `path` es la ruta completa relativa a `base_path`
e identifica el archivo sin depender de que el padre esté bien resuelto. La clave de sync, acotada por
`parent_id`, no veía una fila con la misma ruta bajo otro padre y creaba una copia — así se propagaba
la duplicación hacia abajo en el árbol.

`FileRegistry::ensure()` es un upsert semántico: busca, **sana el `parent_id`** si difiere (lo que
hace converger un árbol escrito por tres productores distintos), y si no existe crea capturando
`SQLSTATE 23505` para releer al ganador.

*Por qué no `upsert()` / `ON CONFLICT` directo*: Postgres lo rechaza si el índice no existe todavía, y
el índice no puede existir hasta que se limpie. Esta forma funciona **antes y después**, sin un
segundo despliegue.

## D2: El lock vive en el servicio, no en los llamadores

Cinco puntos de entrada llamaban a `syncFolder()` sin coordinación: el listado web (`?sync=1` y el
auto-escaneo de carpeta vacía), el visor de enlaces públicos, `syncStorage()` del API Transcriptor, y
el comando programado. Poner el lock en cada llamador garantiza que alguien lo olvide.

`Cache::lock()` **no bloqueante** dentro de `syncFolder()`: N peticiones concurrentes producen **un**
escaneo y N listados baratos, en vez de N escaneos pisándose. Más un lock por storage en `fullSync()`.
Jerarquía estricta storage → carpeta, así que no hay deadlock posible.

## D3: El escaneo tiene que poder decir "no sé"

`scanDirectory()` devolvía `[]` para cinco situaciones: directorio vacío de verdad, no es directorio,
no legible, `scandir()` fallido, y excepción. `StorageSyncService` no podía diferenciarlas.

`ScanResult` (`ok(entries)` / `failed(reason)`) se eligió sobre una excepción porque la señal de "no
fiable" tiene que viajar **junto a las entradas** para alimentar `PruneGuard`, y porque así es
comprobable sin base de datos. Radio de impacto mínimo: **un solo llamador en producción**.

`clearstatcache()` al entrar, porque NFS cachea atributos y puede devolver un `is_dir` obsoleto de
antes de que el montaje cayera.

## D4: Cuatro reglas para no volver a borrar un millón de filas

`PruneGuard` es una función pura:

1. Escaneo no fiable → **nunca** purgar, ni con `--force-prune`.
2. Disco vacío con filas en BD → no purgar. *(Esta regla sola habría evitado el incidente entero.)*
3. Se borraría >34% de la carpeta → no purgar. No aplica por debajo de 5 filas: borrar 1 de 2 archivos
   es legítimo y superaría cualquier ratio.
4. `--force-prune` salta 2 y 3, jamás 1.

**Compromiso explícito**: la regla 2 deja filas obsoletas si alguien vacía de verdad una carpeta,
hasta que se ejecute `--force-prune`. Filas obsoletas es cosmético; borrar un millón de filas no.

Cada rechazo se registra con contexto. Un goteo de `prune_refused` sobre un storage concreto es ahora
la **alerta temprana** del problema de NFS que antes se manifestaba como un borrado silencioso.

`MountGuard` complementa: si una ruta declarada en `storage_sync.mounts.expected` comparte número de
dispositivo con su directorio padre, no hay nada montado encima. Al detectarlo marca
`storage_providers.is_accessible = false` para que la condición **se vea en la UI**.

## D5: Por qué el de-duplicado converge en una sola pasada

1. `keep_id` es siempre un superviviente, y los supervivientes **nunca** aparecen como `dup_id`. El
   mapeo dup → keep es una **función de un paso, no una cadena**: no hay clausura transitiva.

2. Los subárboles duplicados **no necesitan ordenarse por profundidad**, porque `path` es la ruta
   completa, no el nombre relativo al padre. Si una carpeta está duplicada 36 veces, los descendientes
   de cada copia comparten `(storage, path)` con los del superviviente: ya son sus propios grupos de
   duplicados. Fusionar subárboles es **re-etiquetar una columna**.
   *(Si la identidad fuera `(parent_id, name)`, dependería de la identidad del padre y sí haría falta
   procesar por profundidad con pasadas repetidas.)*

3. Sin colisiones tras la fusión: dos filas solo colisionan bajo el padre fusionado si comparten
   `(storage, path)`, y entonces una es el superviviente y la otra está condenada.

4. Sin ciclos: el invariante `path = parent.path || '/' || name` implica que `parent.path` es prefijo
   estricto de `child.path`; re-parentar al superviviente de la misma ruta lo preserva.

Los puntos 1-4 descansan en ese invariante, **verificado en el 100% de 1.004.491 filas**. Por eso el
comando lo reafirma y aborta si alguna vez deja de cumplirse.

## D6: El orden del borrado no es negociable

`files_parent_id_fkey` es `ON DELETE CASCADE`. **1.339 filas legítimas** colgaban de padres
condenados: un `DELETE` directo se las habría llevado.

Secuencia: mapa → repuntar referencias → **re-parentar** → **aserción de seguridad** → borrar por
lotes de 500 → invalidar cachés.

La aserción (`ningún superviviente cuelga de un padre condenado`) es la comprobación más importante
del comando y aborta la ejecución si no da 0.

**Transcripciones: fusionar, no repuntar.** `transcriptions_file_id_unique` es UNIQUE, así que un
`UPDATE ... SET file_id = keep_id` fallaría con 23505. Las 8 duplicadas eran de solo 4 archivos, todas
`done`, y el superviviente ya tenía la suya con el mismo número de segmentos: se descartan con su fila.

**Cachés**: `folder_listing:{storage}:{pid}:{gen}:{page}` tiene TTL de hasta 24 h y solo se invalida
subiendo `folder_gen`. Sin ese paso la UI seguiría mostrando duplicados un día entero. Las carpetas
afectadas se recogen **antes** de borrar — por eso el mapa guarda `parent_id_before`.

## D7: El índice, al final y con concurrencia

Postgres no admite `NOT VALID` para índices únicos: o se construye sobre datos limpios o falla. De ahí
el orden limpiar → indexar.

`CREATE UNIQUE INDEX CONCURRENTLY` evita el lock `SHARE` que bloquearía toda escritura en `files`
durante la construcción, y exige `public $withinTransaction = false` porque Laravel envuelve las
migraciones de Postgres en una transacción. La migración verifica `indisvalid` después y limpia si
quedó inválido.

Con el índice activo, varios `File::create` que antes pasaban en silencio pueden lanzar violaciones
(`copyFolderRecursively` y `PublicShareController:528` no tienen comprobación previa). En vez de ocho
`try/catch` dispersos, un `render()` en el `withExceptions()` de `bootstrap/app.php` los convierte en
el mismo **409** que ya devuelven las comprobaciones previas.

## D8: Riesgos y rollback

- **Riesgo**: la regla 2 de `PruneGuard` deja filas obsoletas tras un vaciado real. Mitigado con
  `--force-prune` y el log de rechazos.
- **Riesgo**: `MountGuard` solo actúa sobre rutas declaradas en `expected`. Sin declararlas no puede
  distinguir "montaje caído" de "directorio local normal", y suponerlo sería peor que no comprobar.
  Los 5 montajes NFS reales quedaron declarados en `.env`.
- **Rollback**: el índice tiene `down()` limpio. El de-duplicado **no es reversible**; el `pg_dump` de
  742 MB previo a la ejecución es la única vuelta atrás, y por eso el dry-run y la aserción de D6 no
  son opcionales.

## Archivos

**Nuevos**: `Services/FileRegistry.php` · `Services/ScanResult.php` · `Services/PruneGuard.php` ·
`Services/PruneDecision.php` · `Services/MountGuard.php` · `Services/Dedupe/DedupePlanner.php` ·
`Services/Dedupe/DedupePlan.php` · `Console/Commands/DedupeFiles.php` · `config/storage_sync.php` ·
`database/migrations/2026_07_27_150000_restore_files_storage_path_unique_index.php` ·
5 tests unitarios

**Modificados**: `Services/StorageSyncService.php` · `Services/FileScannerService.php` ·
`Http/Controllers/FileController.php` · `Services/Ia/DiskScannerService.php` ·
`Http/Controllers/Ia/ApiTranscriptorController.php` · `Console/Commands/SyncStorage.php` ·
`routes/console.php` · `bootstrap/app.php`

**Borrados**: `app/debug_sync55b.php`
