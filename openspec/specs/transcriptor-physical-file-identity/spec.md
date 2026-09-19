## Purpose

Establece la identidad del pipeline de transcripción alrededor de la ruta absoluta del archivo (`transcriptions.source_absolute_path`), no del `file_id` ni del `storage_provider_id`. Un mismo archivo físico (misma ruta absoluta) tiene UNA sola transcripción a través de todos los storages.

Establece la identidad del pipeline de transcripción alrededor de la ruta absoluta del archivo (`transcriptions.source_absolute_path`), no del `file_id` ni del `storage_provider_id`. Un mismo archivo físico (misma ruta absoluta) tiene UNA sola transcripción a través de todos los storages. Esto es la regla que `createFileFromScan` aplica consistentemente y que el bug del change `fix-storage-sync-missing-db-facade-import` restauró después de que el facade `DB` se rompiera.


## Requirements

### Requirement: La identidad del pipeline es la ruta absoluta

El sistema SHALL identificar cada archivo a transcribir por su ruta absoluta en disco, compuesta como `rtrim(storage_providers.base_path,'/') . '/' . files.path` del storage dueño efectivo. `transcriptions` SHALL registrar esa ruta en la columna `source_absolute_path` (varchar nullable).

#### Scenario: Dos filas files, un archivo físico
- **WHEN** el mismo audio tiene una fila `files` bajo el storage padre y otra bajo el storage hijo
- **THEN** ambas producen la misma `source_absolute_path` y el pipeline las trata como un único archivo

#### Scenario: El archivo se transcribe desde el storage dueño efectivo
- **WHEN** el dueño efectivo de la ruta es el storage hijo
- **THEN** la fila canónica de `transcriptions` apunta a un `files.id` cuyo `storage_provider_id` es el del hijo

### Requirement: Una sola transcripción por archivo físico

El sistema SHALL garantizar mediante índice `UNIQUE` parcial que no existan dos filas en `transcriptions` con la misma `source_absolute_path` no nula. Un intento de crear una segunda transcripción para el mismo archivo físico SHALL reutilizar la fila existente en lugar de insertar.

#### Scenario: Descubrimiento repetido del mismo archivo
- **WHEN** el scanner vuelve a encontrar un archivo que ya tiene transcripción, por el mismo storage o por otro storage de la cadena
- **THEN** no crea una segunda fila y el conteo de transcripciones nuevas no incrementa

#### Scenario: El mismo audio con transcripción en padre e hijo (caso histórico)
- **WHEN** existen dos transcripciones con la misma ruta absoluta en dos storages distintos (una `done` y otra `dead`)
- **THEN** la reconciliación conserva la fila `done`, apunta su `file_id` a la fila canónica del dueño efectivo, y elimina la otra junto con sus datos dependientes

#### Scenario: Reintento de un fallido reutiliza la fila
- **WHEN** el operador reprocesa un archivo con `--include-failed` o `--include-done`
- **THEN** la misma fila de `transcriptions` vuelve a `pending` (no se inserta una nueva) y el índice único no se viola

### Requirement: Mis Archivos no cambia su modelo de identidad

El sistema SHALL NOT aplicar a la tabla `files` ninguna restricción de unicidad sobre la ruta absoluta, ni consolidar, borrar, reparentar o migrar filas duplicadas de `files`. La tabla `files` SHALL conservar el índice único existente `(storage_provider_id, path)` como su contrato de identidad.

#### Scenario: Dos filas files del mismo archivo siguen existiendo
- **WHEN** el archivo X tiene fila bajo el padre y fila bajo el hijo
- **THEN** ambas filas permanecen en BD, listables y navegables, y ninguna operación de este change las modifica

#### Scenario: La navegación de Mis Archivos no se ve afectada
- **WHEN** el usuario navega el padre y el hijo
- **THEN** ve los archivos como antes, sin errores de unicidad y sin cambios en cuotas, shares ni papelera

### Requirement: El descubrimiento escribe solo contra la fila canónica

`DiskScannerService` SHALL resolver, antes de crear la transcripción, la fila canónica del archivo físico vía el servicio de identidad y SHALL escribir la transcripción contra esa fila. El scanner SHALL NOT crear transcripciones sobre filas no canónicas.

#### Scenario: Archivo reclamado por dos gestores
- **WHEN** el padre y el hijo ven el mismo archivo físico y ambos tienen el descubrimiento corriendo
- **THEN** el primero que lo procesa crea la transcripción con su `source_absolute_path`, y el segundo reutiliza esa fila sin crear otra

#### Scenario: Archivo cuyo dueño efectivo no tiene fila files
- **WHEN** el dueño efectivo de una ruta es el padre habilitado pero la fila `files` solo existe bajo el hijo
- **THEN** el scanner crea la fila canónica bajo el dueño efectivo mediante `FileRegistry` y escribe la transcripción contra ella

### Requirement: La reconciliación es auditable e idempotente

El sistema SHALL exponer `php artisan transcription:reconcile-physical-identities` con `--dry-run` (default) y `--apply`, que: (a) rellena `source_absolute_path` en transcripciones existentes con valor nulo, y (b) consolida los grupos que violan el índice único. El comando SHALL ser idempotente y emitir un reporte por fila afectada.

#### Scenario: Dry-run no muta nada
- **WHEN** el operador corre el comando sin `--apply`
- **THEN** el comando reporta cuántas filas llenaría y cuántos grupos consolidaría, sin modificar la BD

#### Scenario: Segunda corrida no cambia nada
- **WHEN** el comando se corre con `--apply` dos veces seguidas
- **THEN** la segunda corrida reporta 0 filas a llenar y 0 grupos a consolidar

#### Scenario: La consolidación preserva el resultado válido
- **WHEN** un grupo duplicado tiene una fila `done` y otra `dead`
- **THEN** la fila `done` sobrevive con su `srt_content` intacto y la `dead` se elimina
