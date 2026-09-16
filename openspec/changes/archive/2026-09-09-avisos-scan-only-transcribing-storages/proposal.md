## Why

El dropdown "Storage" de la sub-ventana "Escaneo" en `/ia/avisos-inteligentes`
y la tabla/filtro de la pestaña "Cobertura" listan hoy **todos** los storages
asignados al usuario, incluidos aquellos cuyo `storage_providers.transcription_enabled = false`.
Esos storages están apagados globalmente en el módulo API Transcriptor y por
tanto no producen transcripciones, así que el operador termina seleccionando
un storage cuyo escaneo devolverá cero candidatos sin entender por qué. La
fuente de verdad sobre qué storages están transcribiendo es la bandera
`transcription_enabled`, escrita únicamente desde API Transcriptor, y debe
ser la que gobierne qué storages aparecen como opción seleccionable en este
módulo (sin permitir escritura, solo lectura).

## What Changes

- Añadir endpoint dedicado `GET /ia/avisos-inteligentes/storages` que devuelve
  solo los storages con `storage_providers.transcription_enabled = true`
  asignados al usuario de la sesión. El endpoint compartido `GET /user/storages`
  (`FileController::storages`) queda intacto porque lo consume el módulo de
  Files, donde el filtro no aplica.
- Cambiar `app/resources/views/ia/avisos-inteligentes/index.blade.php` (Alpine
  `init()`) para que `this.storages` se alimente del nuevo endpoint y no del
  compartido. Esto cubre los dos dropdowns del escaneo (panel "Escanear ahora"
  y modal de confirmación) en una sola edición.
- Poblar `coverage.storages` en la respuesta de `AvisosInteligentesController::coverage`
  (que hoy siempre llega vacío) usando el mismo conjunto filtrado, para que
  el filtro de storage de la pestaña Cobertura deje de estar roto.
- Aplicar el filtro `transcription_enabled = true` en
  `AvisosScanService::coveragePaginated()` (y el método deprecado `coverage()`)
  para que la tabla Cobertura y el botón "Activar histórico" (rewind) solo
  muestren pares cuyo storage esté transcribiendo.

## Capabilities

### Modified Capabilities
- `avisos-scan-configuration`: se añaden dos requirements para formalizar (a)
  que el filtro "Storage" del escaneo solo lista storages con
  `transcription_enabled=true` y (b) que la cobertura (tabla, filtro y
  rewind) se acota al mismo conjunto.

## Impact

- Controllers: `app/app/Http/Controllers/Ia/AvisosInteligentesController.php`
  (nuevo método `storages()` siguiendo el patrón de `show()` líneas 94-105;
  `coverage()` para popular `storages` en la respuesta JSON).
- Services: `app/app/Services/Ia/AvisosScanService.php` (agregar
  `where('sp.transcription_enabled', true)` al builder de
  `coveragePaginated()` y `coverage()`).
- Views: `app/resources/views/ia/avisos-inteligentes/index.blade.php`
  (cambiar URL en `init()` y consumir `coverage.storages` en `loadCoverage()`).
- Routes: `app/routes/web.php` (registrar
  `GET /ia/avisos-inteligentes/storages`).
- Sin migración de BD. Sin cambios al dispatcher ni al scanner. Sin cambios
  al endpoint compartido `GET /user/storages`.

## Non-goals

- No se modifica `FileController::storages` ni el comportamiento del módulo
  de Files.
- No se filtran los storages inactivos en el panel "Acceso a transcripciones
  por canal" (`user-detail.blade.php`); ese panel sigue mostrando storages
  inactivos con el chip "Sin producción" para que el admin pueda
  pre-otorgar `transcription_access` que se active cuando el storage se
  vuelva a encender en API Transcriptor.
- No se filtran los registros del tab Auditoría ni las tarjetas del
  Dashboard; ambos son históricos/agregados donde filtrar por el estado
  actual de `transcription_enabled` perdería información.
- No se modifica la query de `selectCandidates()` (gate de
  `user_storages.transcription_access`); el cambio es solo de UI para
  alinear lo que el operador selecciona con lo que el pipeline produce.
- No se añade storage picker al modal de "Escaneo completo" (no existe hoy
  y queda fuera del scope).
