# Change: Herencia virtual de transcripciones entre storages padre e hijos

## Why

El scanner de transcripción (`DiskScannerService`) ya excluye correctamente los subpaths de storages hijos con `transcription_enabled=true` para evitar duplicación de trabajo. Sin embargo, **las consultas de la UI/API siguen siendo storage-locales**: un cliente con acceso a "01 Emisoras 01" ve solo los archivos cuyo `storage_provider_id=47`, pero no los archivos transcritos de "03 La W Bogota" (storage 63) que físicamente están bajo su `base_path`.

Resultado: la UI muestra 0 archivos transcritos en Emisoras 01 aunque existan 645 transcripciones reales en La W. El operador debe navegar manualmente a cada storage hijo para ver las transcripciones.

## What Changes

- **Helper `StorageProvider::resolveInheritedTranscriptionScope()`**: dado un storage, devuelve el conjunto de storage IDs = `{storage.id} ∪ {descendientes con transcription_enabled=true}`. Solo expande si el storage tiene `transcription_enabled=true` Y existe al menos un descendiente con TX activa.
- **`ApiTranscriptorController::storageFiles()`**: cuando se consulta un storage con scope heredado, devuelve archivos del storage Y todos sus descendientes, virtualizados bajo el nombre del padre.
- **`ApiTranscriptorController::stats()`**: cuando se pide `--scope=inherited`, agrega contadores de transcripciones del scope heredado.
- **Indicador UI**: el panel de storages muestra un badge "N hijos" cuando el storage tiene descendientes con `transcription_enabled=true`, con tooltip explicando la herencia.

## Impact

- **Specs AFFECTED**: `transcription-disk-scanner` (nuevo requirement), `transcriptor-storage-files-srt-link` (consultas con scope heredado).
- **Code affected**:
  - `app/app/Models/StorageProvider.php` — nuevo scope `withTranscriptionDescendants(int $rootId): array`
  - `app/app/Http/Controllers/Ia/ApiTranscriptorController.php` — `storageFiles()` y `stats()` aceptan scope
  - `app/resources/views/ia/api-transcriptor/index.blade.php` — badge de herencia
- **Sin cambios en el scanner**: la deduplicación ya está resuelta. Este cambio solo afecta cómo se MUESTRAN los resultados al operador.
- **Sin cambios en la tabla transcriptions**: el modelo sigue intacto (un `file_id` apunta a un `File` específico). La herencia es una vista, no una réplica.

## Behavioral rules

1. La herencia es **de solo lectura / vista**. Nunca crea ni duplica `Transcription` rows.
2. El scope se resuelve **en cada request** (no se cachea), para reflejar cambios en tiempo real (ej. activar/desactivar transcription on a child).
3. Si un descendiente tiene `transcription_enabled=false`, NO se incluye en el scope heredado (no aporta transcripciones).
4. El algoritmo recursivo respeta el `base_path` real: solo es descendiente quien tenga `base_path` que comience con `parent.base_path + '/'`.
