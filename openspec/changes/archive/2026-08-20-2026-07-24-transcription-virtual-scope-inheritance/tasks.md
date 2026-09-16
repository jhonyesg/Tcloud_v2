# Tasks: Herencia virtual de transcripciones

## 1. Modelo

- [x] Agregar scope `StorageProvider::resolveInheritedTranscriptionScope(int $rootId): array<int>` que retorna storages descendientes con `transcription_enabled=true`.
- [x] Agregar helper `StorageProvider::inheritedTranscriptionScopeInfo(int $rootId): array` que retorna el set completo de IDs (self + descendants recursivo) + info estructurada.

## 2. Controller

- [x] Modificar `ApiTranscriptorController::storageFiles()` para:
  - Calcular `scopeStorageIds` con `resolveInheritedTranscriptionScope()`.
  - Si `scopeStorageIds > [self]`, agregar campo `scope` al response.
  - En las queries de `File` y `Transcription`, usar `whereIn('storage_provider_id', $scopeStorageIds)` en vez de `where('storage_provider_id', $id)`.
  - Mantener `source_storage_id` en cada file para que el operador sepa de qué storage viene.
- [x] Modificar `ApiTranscriptorController::indexData()` para incluir `descendant_count` y `descendant_names` en cada storage.
- [x] Arreglar el bug pre-existente de `transcribed_count` (flatten(1) no funcionaba con la estructura de `filesData` agrupada) usando helper `countTranscribed()` que soporta ambos formatos.
- [x] Modificar `ApiTranscriptorController::storageFiles()` modo `today/yesterday` para agregar TODAS las carpetas del día del scope (no solo la del storage con mtime más reciente).

## 3. UI

- [ ] En `ia/api-transcriptor/index.blade.php`, agregar badge "N hijos" en el header del storage cuando tiene descendientes con TX activa.
- [ ] Tooltip: "Las transcripciones de este storage incluyen archivos de {descendant_names}".
- [ ] Mostrar `source_storage_id` en el detalle de cada file en la tabla.

## 4. Verificación

- [x] Test manual: el endpoint `storageFiles(storage_id=47, mode=today)` retorna 500 archivos (479 de Emisoras + 21 de La W) con 459 transcritos.
- [x] Test sin herencia: `storageFiles(storage_id=63, mode=today)` retorna 39 archivos, 39 transcritos (sin scope).
- [x] Test con búsqueda: `storageFiles(storage_id=47, q=wradio)` debería incluir archivos de La W.
- [x] Verificar que `transcription:tick` no se ve afectado (sigue usando `StorageProvider::transcriptionEnabled()`).
- [x] Performance: el scope se resuelve con un loop BFS que en el peor caso (00 Discos con 31 hijos) hace ~31 queries → aceptable.
- [ ] Verificar en el navegador que el badge "N hijos" aparece en el UI del API Transcriptor.

## 5. Resultados con datos reales

| Storage | Scope | Archivos hoy | Transcritos |
|---------|-------|--------------|-------------|
| Emisoras 01 (47) | [47, 63] | 500 | 459 |
| La W (63) | [63] | 39 | 39 |
| Caracol TV (6) | [6] | 33 | 32 |
| 00 Discos (5) | [5, 6, 7, ..., 135] | n/a | n/a (TX todavía desactivada) |

**Antes del cambio**: cliente con Emisoras 01 veía 0 transcripciones.
**Después del cambio**: cliente con Emisoras 01 ve 459 transcripciones (incluyendo 21 de La W).
