## 1. Backend: presets y granularidad horaria

- [x] 1.1 `AvisosScanService`: añadir `resolvePreset()` que traduce `8h|24h|3d|7d|today` a `from`/`to` (anclado a now/medianoche) y normalizar `from`/`to` para respetar componente de hora (solo fecha → startOfDay/endOfDay) en `selectCandidates()` y `estimate()`
- [x] 1.2 `AvisosScanService::run()`: registrar en log `avisos.scan.run` las claves `preset` y `range` resuelto; guard contra `noWindow` + rango simultáneos
- [x] 1.3 `AvisosInteligentesController@runScan`: validar `preset` (in:8h,24h,3d,7d,today) y `from`/`to` como datetime opcional; pasar `preset` al service
- [x] 1.4 `AvisosInteligentesController@scanStatus`: aceptar `preset`/`storageId`/`from`/`to`/`no_window` en query para computar `pending_estimate` con filtros

## 2. Frontend: modal con presets y estimado honesto

- [x] 2.1 Blade/Alpine: mover selección de ventana al modal fase confirm (radio presets + rango personalizado con datetime-local) y exclusividad mutua con checkbox "Histórico completo"
- [x] 2.2 Estimado de la fase confirm solicita `/scan` con los filtros elegidos y refresca al cambiar preset/storage
- [x] 2.3 `runScanSequence()` envía `preset` o `from`/`to` (nunca junto a `noWindow`) y muestra la ventana elegida en el resumen final

## 3. Verificación

- [x] 3.1 Prueba de servicio: presets resuelven rangos esperados y `from` con hora se respeta (script harness contra PG real, patrón `tests/harness_*.php`)
- [x] 3.2 `openspec validate avisos-scan-time-presets --strict` en verde
- [x] 3.3 Verificación manual en la UI del flujo completo (preset, personalizado, histórico, estimado)

## 4. Runner en background (iteración del usuario)

- [x] 4.1 `ScanMentionsRunCommand` (`avisos:scan-run`): drena tandas con excludeIds, progreso en cache `avisos_scan_bg:{runId}`, cancelación cooperativa `stop_requested` entre tandas
- [x] 4.2 Controller: `runScanBackground` (puntero atómico `avisos_scan_bg:active` + liveness ping), `scanRunStatus`, `scanRunActive` (re-attach), `scanRunStop`
- [x] 4.3 Rutas `scan/run-bg` (POST/GET active/GET status/POST stop)
- [x] 4.4 Frontend: lanzar → polling 2s → re-adjuntar al recargar (`checkActiveScanRun` en init) → detener vía API
- [x] 4.5 Verificación end-to-end: setsid sobrevive a la muerte del lanzador; 4.238 drenadas en 171 tandas; stop temprano libera puntero; vista compila