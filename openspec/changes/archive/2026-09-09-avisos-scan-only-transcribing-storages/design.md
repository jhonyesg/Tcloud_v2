## Context

Ver `proposal.md` (Why + What Changes) para motivación y alcance.

Estado actual relevante:
- `GET /user/storages` (`FileController::storages`, `app/app/Http/Controllers/FileController.php:963`) devuelve todos los storages del usuario vía el pivote `user_storages`. Es consumido por el módulo de Files (file browser) y por la pestaña "Escaneo" del módulo de Avisos Inteligentes. En Avisos se invoca desde `app/resources/views/ia/avisos-inteligentes/index.blade.php:1050` (Alpine `init()`).
- La cobertura de la pestaña "Cobertura" se sirve desde `GET /ia/avisos-inteligentes/scan/coverage` → `AvisosInteligentesController::coverage` (`AvisosInteligentesController.php:639-665`). El payload actual **no incluye** la lista de storages para poblar el dropdown del filtro (el Alpine `coverage.storages` queda `undefined`, bug latente).
- `AvisosScanService::coveragePaginated()` (`app/app/Services/Ia/AvisosScanService.php:689-740`) construye el builder con `keyword_scan_watermarks` + `keywords` + `storage_providers`. No filtra por `transcription_enabled` ni en el paginado ni en el método deprecado `coverage()`.
- La bandera `storage_providers.transcription_enabled` ya tiene un writer único (`ApiTranscriptorController::toggleStorage`, `app/app/Http/Controllers/Ia/ApiTranscriptorController.php:674-704`) y está protegida por `transcription-api-orchestrator` (es autoritativa, módulo de Avisos NO la escribe).
- El scope `StorageProvider::transcriptionEnabled()` (`app/app/Models/StorageProvider.php:37`) ya existe y es el patrón usado por `ScanAndSubmitCommand`, `TranscriptionTickCommand`, `DiskScannerService`, etc.

Restricciones de proyecto (de `AGENTS.md`):
- Auth con `session('user')`, NUNCA `auth()->user()`.
- Facades usados dentro de `app/app/Services/*.php` requieren `use` explícito (regresión documentada).
- `$validated`/`$request` para claves opcionales: extraer a variable local con `?? null` antes de usarla.
- Convention: cambios pequeños, con un único path modificado por concern; evitar endpoints compartidos para reglas del módulo.

## Goals / Non-Goals

**Goals:**
- Aislar el cambio del endpoint compartido `/user/storages` (Files sigue viendo todos los storages del usuario).
- Reusar el patrón del método `show()` (`AvisosInteligentesController.php:94-105`) — query con `where('storage_providers.enabled', true)` ya validada en producción, añadiendo solo `where('storage_providers.transcription_enabled', true)`.
- Cerrar el bug latente del filtro Cobertura (dropdown vacío) en el mismo cambio.
- Aplicar el filtro también a la tabla Cobertura para coherencia: lo que se filtra debe coincidir con lo que se muestra en la lista y con lo que el botón "Activar histórico" puede actuar.

**Non-Goals:**
- No se añade un flag `?only_transcribing` al endpoint compartido `/user/storages` (acoplaría concerns de dos módulos).
- No se reescribe la cobertura como endpoint nuevo: se reusa `coverage()` y se le añade el campo `storages` al payload.
- No se valida que un storage_id pasado al rewind pertenezca a un storage con `transcription_enabled=true` (la fila de cobertura para un storage apagado deja de existir tras el fix → el botón nunca lo invocará).

## Decisions

### Decisión 1: Endpoint dedicado en lugar de tocar `/user/storages`

**Decisión**: nuevo `GET /ia/avisos-inteligentes/storages` → método `AvisosInteligentesController::storages(Request $request)`.

**Por qué**: el endpoint `/user/storages` está compartido con el módulo de Files (file browser). Filtrar ahí reduciría los storages visibles en Files, donde el filtro NO aplica (Files debe mostrar incluso storages sin transcripción). Aislar en un endpoint dedicado respeta la separación de concerns.

**Alternativas consideradas**:
- *Parámetro `?only_transcribing=1` en `/user/storages`*: rechazado, acopla la lógica de Avisos al controller de Files y obliga a un cambio coordinado del frontend de Files (que ya funcionaría correctamente si lo ignora, pero añade un parámetro muerto en su superficie).
- *Filtro client-side en Alpine*: rechazado, descarga storages inútiles y la query de `show()` ya se cachea por epoch, así que el costo server-side es despreciable.

### Decisión 2: Reutilizar `show()` como molde, no abstraer

**Decisión**: el método nuevo es esencialmente una copia de `show()` líneas 94-105 con el filtro extra y sin pasar por la vista Blade (devuelve JSON). Sin refactor a un helper `StorageProvider::forUserActiveAndTranscribing($user)`.

**Por qué**: la query es de 4-5 líneas; abstraer ahora añadiría un helper que solo dos callers usarían y que tendría que aceptar un tercer parámetro booleano para alternar el filtro — peor que duplicar 4 líneas. Si el patrón se repite en un tercer lugar, se refactoriza.

**Alternativas consideradas**:
- *Crear `StorageProvider::scopeActiveAndTranscribing()`*: rechazado, los scopes de Eloquent deberían ser ortogonales (uno por concern). Combinar dos concerns en un solo scope dificulta reusar `transcriptionEnabled()` solo.

### Decisión 3: Poblar `coverage.storages` desde el controller, no desde el service

**Decisión**: en `AvisosInteligentesController::coverage()` (líneas 639-665), después de la llamada cacheada a `coveragePaginated()`, añadir al JSON de salida un campo `storages` con la lista filtrada (misma query que el endpoint nuevo).

**Por qué**: la cobertura cacheada ya tiene TTL de 60s con clave `coverage:{epoch}:...`. Si la lista de storages se cacheara dentro de `coveragePaginated()` cambiaría la forma del payload cacheado (backward compat con caches pre-existentes si Redis no se invalida). Añadir el campo en el controller, fuera del bloque cacheado, mantiene la cache key estable.

**Trade-off conocido**: la lista de storages se computa en cada request aunque no cambie — pero la query es barata (índice en `storage_providers.enabled` + `transcription_enabled`, retornando típicamente <50 filas) y la alternativa (incluir en la cache) requeriría bump manual del epoch cuando un admin toggle un storage.

### Decisión 4: Cache epoch en `coverage.storages`

**Decisión**: la lista de storages que devuelve `coverage()` se construye con `CacheEpoch::get()` mezclado en la cache key, así un toggle en API Transcriptor (que ya bumpea el epoch vía `CacheEpoch::bump()`) invalida automáticamente la lista junto con la tabla de cobertura.

**Por qué**: el admin que apaga un storage en API Transcriptor debe ver la tabla Cobertura y su filtro actualizados al siguiente refresh, no esperar 60s. Reusar el epoch ya existente evita una clave nueva y mantiene la consistencia con `coveragePaginated()`.

### Decisión 5: `coveragePaginated()` añade `where('sp.transcription_enabled', true)` al builder

**Decisión**: una sola línea en el builder, aplicada a `coveragePaginated()` y al método deprecado `coverage()`.

**Por qué**: la tabla muestra pares `(keyword, storage)`. Si un storage se apaga en API Transcriptor, los pares existentes en `keyword_scan_watermarks` siguen ahí pero son irrelevantes (nunca generarán transcripciones nuevas y no aportan información operativa). Filtrarlos alinea la tabla con la UI del scan.

**Nota**: NO se borran las filas de `keyword_scan_watermarks`; solo dejan de aparecer en la tabla. Si el storage se vuelve a activar, las filas reaparecen automáticamente con su `scanned_until` previo. Comportamiento reversible sin pérdida de datos.

## Risks / Trade-offs

- **[Riesgo] El cache de cobertura contiene la lista previa de storages antes del toggle** → mitigado por `CacheEpoch` (cada toggle en API Transcriptor bumpea el epoch → invalida la cache key).
- **[Riesgo] La cobertura pierde visibilidad histórica de storages desactivados** → aceptable: la UI es operativa, no histórica. La pestaña Auditoría sigue mostrando los eventos completos de `watermark_audit_log`.
- **[Riesgo] Si el admin desactiva un storage mientras un escaneo está corriendo**, las filas generadas durante esa corrida podrían quedar huérfanas (storage apagado al momento de cubrir) → aceptable: el scan ya terminó, las nuevas filas de cobertura no aparecerán en la tabla hasta que el storage vuelva a activarse (o nunca, si fue un toggle permanente).
- **[Trade-off] Duplicación de query 4-líneas entre `storages()` y `show()`** → aceptable: es el patrón actual del módulo (3-4 métodos casi idénticos en otros lugares). Refactor solo si aparece un tercer caller.
- **[Riesgo] Si el storage_id del filtro Cobertura no aparece en `coverage.storages`** (porque el admin lo acaba de apagar) → la UI lo trata como "filtro huérfano": el backend responde con la tabla filtrada a 0 filas porque la cache aún existe con la lista vieja. Mitigación: el cliente ya muestra el filtro con el nombre del storage aunque esté oculto del dropdown, pero el filtro seleccionado queda sin opción visible. Aceptable: el admin al ver 0 filas refresca o ve que el storage se apagó.

## Migration Plan

No hay migración de BD. No hay cambios al dispatcher ni al scanner.

Pasos de despliegue:
1. Crear `AvisosInteligentesController::storages()` y registrar la ruta `GET /ia/avisos-inteligentes/storages` en `app/routes/web.php` (antes de la línea de `/{userId}` para evitar colisión con el parámetro).
2. Modificar `AvisosInteligentesController::coverage()` para añadir el campo `storages` al JSON de salida, cacheado por epoch.
3. Modificar `AvisosScanService::coveragePaginated()` (y `coverage()`) con el `where('sp.transcription_enabled', true)`.
4. Modificar el Alpine `init()` en `index.blade.php:1050` para llamar al endpoint nuevo. La asignación `this.storages = d.storages || []` se mantiene igual.
5. Modificar `loadCoverage()` en `index.blade.php:1363-1388` para popular `this.coverage.storages` desde `d.storages`.
6. Validación manual: abrir `/ia/avisos-inteligentes` con un usuario que tenga storages mixtos (algunos con `transcription_enabled=true`, otros `false`). El dropdown del escaneo y el filtro de Cobertura deben mostrar solo los activos.

Rollback: como no hay migración ni cambios al dispatcher, revertir el merge de los 4 archivos (controller, service, vista, rutas) deja el sistema idéntico al estado actual.

## Open Questions

- ¿La nueva ruta debe protegerse con el middleware `admin` (las demás rutas del módulo Avisos están en el grupo `auth`+`admin`)? Sí, se asume `admin` por consistencia con el resto de `/ia/avisos-inteligentes/*`. Confirmar al implementar.
