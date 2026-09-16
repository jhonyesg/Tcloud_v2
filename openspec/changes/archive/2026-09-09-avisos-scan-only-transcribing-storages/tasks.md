## 1. Backend — Endpoint dedicado de storages transcribiendo

- [x] 1.1 Crear método `storages(Request $request)` en `app/app/Http/Controllers/Ia/AvisosInteligentesController.php`. Patrón a seguir: query de `show()` líneas 94-105 con `where('storage_providers.transcription_enabled', true)` adicional. Devolver JSON `{storages: [{id, name, type, transcription_enabled: true}]}`. Obtener usuario vía `session('user')` (convenio del proyecto).
- [x] 1.2 Registrar la ruta `GET /ia/avisos-inteligentes/storages` en `app/routes/web.php`, dentro del grupo `['auth', 'admin']` que contiene el resto del módulo (línea 232+). Ubicar ANTES de la línea `/{userId}` para evitar colisión de parámetro (mismo criterio que las rutas `/scan/*`).
- [x] 1.3 Verificar que `FileController::storages` (línea 963) NO se modificó y que el módulo de Files sigue trayendo todos los storages.

## 2. Backend — Cobertura acotada y dropdown poblado

- [x] 2.1 En `AvisosScanService::coveragePaginated()` (línea 689 de `app/app/Services/Ia/AvisosScanService.php`), añadir `->where('sp.transcription_enabled', true)` al builder, justo después del `leftJoin('storage_providers as sp', ...)`.
- [x] 2.2 Repetir el mismo `where('sp.transcription_enabled', true)` en el método deprecado `coverage()` (mismo archivo, buscar el método legacy) para mantener coherencia.
- [x] 2.3 En `AvisosInteligentesController::coverage()` (línea 639 del controller), añadir al JSON de salida el campo `storages` con la lista filtrada (misma query del método `storages()`). Computar FUERA del bloque `Cache::remember` (líneas 658-662) para no cambiar la cache key de los datos cacheados.
- [x] 2.4 Confirmar que la cache key incluye `CacheEpoch::get()` para que el toggle en API Transcriptor (que bumpea el epoch) invalide automáticamente la lista de storages del payload.

## 3. Frontend — Alpine consume el endpoint nuevo

- [x] 3.1 En `app/resources/views/ia/avisos-inteligentes/index.blade.php` Alpine `init()` (línea 1050), cambiar `apiFetch('/user/storages', ...)` por `apiFetch('/ia/avisos-inteligentes/storages', ...)`. Mantener la asignación `this.storages = d.storages || []`.
- [x] 3.2 En el mismo archivo, dentro de `loadCoverage()` (línea 1363), popular `this.coverage.storages = d.storages || []` tras un response exitoso, antes del bloque `summary`. Esto cierra el bug latente del dropdown de filtro Cobertura que siempre estaba vacío.

## 4. Verificación manual

- [x] 4.1 En `/ia/avisos-inteligentes`, desplegar el dropdown "Storage" del panel "Escanear ahora" → solo aparecen storages con `transcription_enabled=true`. ✅ 12 de 24 storages asignados, dropdown matchea endpoint nuevo.
- [x] 4.2 Abrir el modal "Escanear ahora" → su dropdown muestra el mismo conjunto filtrado. ✅ Idéntico al panel.
- [x] 4.3 Abrir la pestaña "Cobertura" → el dropdown de filtro está poblado con storages transcribiendo (antes estaba vacío). ✅ Cierra bug latente.
- [x] 4.4 Apagar un storage en `/ia/api-transcriptor`, recargar `/ia/avisos-inteligentes` → ese storage desaparece del dropdown del escaneo y del filtro de Cobertura. Si tenía pares en la tabla, también desaparecen. ✅ "01 Caracol Tv" removido del dropdown.
- [x] 4.5 Reactivar el storage, recargar → el storage y sus pares vuelven a aparecer en la tabla Cobertura con `scanned_until` previo. ✅ Dropdown restaurado al baseline idéntico.
- [x] 4.6 Navegar a `/files` (módulo Files) → el árbol sigue mostrando todos los storages del usuario (sin regresión). ✅ `/user/storages` sigue retornando 24.
