## 1. Backend: endpoint unificado y registry

- [x] 1.1
- [x] 1.2
- [x] 1.3
- [x] 1.4
- [x] 1.5
- [x] 1.6
- [x] 1.7

## 2. Frontend: widget flotante + Alpine store

- [x] 2.1 Crear `app/resources/views/components/bg-job-indicator.blade.php` con:
  - `<div x-data x-init="Alpine.store('bgJobs').mount(this)">` que envuelve el render del widget
  - Render condicional: solo se muestra si `$store.bgJobs.jobs.length > 0 && !$store.bgJobs.isDismissed(kind)`
  - Posición: `class="fixed bottom-4 right-4 z-40 ..."`
  - Una card por job con: nombre del módulo, label, barra de progreso (computed desde progress.estimate o progress.total_to_process), contadores clave, botón "Ver detalles" (`<a :href="job.url">`), botón "×" que llama `$store.bgJobs.dismiss(job.runId)`
  - Si hay >3 jobs, mostrar los primeros 3 con un "+N más" colapsable
- [x] 2.2 En `app/resources/views/layouts/app.blade.php`, registrar el Alpine store global `Alpine.store('bgJobs')` con:
  - `jobs: []`
  - `dismissed: {}` (mapa `runId => true` para saber qué jobs el operador ocultó)
  - `pollTimer: null`
  - `methods: { mount, dismiss, progressPct, startPolling, stopPolling, fetchJobs, handleVisibility }`
  - Lógica de polling: `setInterval(fetchJobs, 5000)`, pausa si 2 polls consecutivos vacíos, resume en `document.visibilitychange` cuando vuelve a visible
  - `fetchJobs` con `AbortController` y timeout de 8s
- [x] 2.3 Incluir el partial en el layout: `@include('components.bg-job-indicator')` cerca del cierre del `<body>`.

## 3. Frontend: quitar auto-open en módulo de avisos

- [x] 3.1 En `app/resources/views/ia/avisos-inteligentes/index.blade.php`, en el método `checkActiveScanRun()` (línea 650), eliminar las líneas:
  - `this.activeTab = 'escaneo';`
  - `this.scanModal = { open: true, phase: 'running', runId: s.runId, ... }`
- [x] 3.2 Mantener la llamada a `this.attachScanRun(s.runId)` para que el polling local siga actualizando los datos si el modal está abierto.
- [x] 3.3 Agregar al `init()` (después de `checkActiveScanRun`): leer `URLSearchParams` y si `params.get('focus')` empieza con `bg-avisos-scan-`, parsear el runId y abrir el modal con `phase: 'running'` apuntando a ese runId. Reusar la lógica de `attachScanRun` para no duplicar.

## 4. Frontend: deep-link para transcriptor

- [x] 4.1 En `app/resources/views/ia/api-transcriptor/index.blade.php`, en `init()`, leer `URLSearchParams`. Si `params.get('focus')` empieza con `bg-transcriptor-batch-`, parsear runId y abrir el modal con `runBatch()` apuntando a ese runId (o un método nuevo `resumeBatch(runId)` que abre el modal en running phase con el runId predefinido).
- [x] 4.2 Si no hay focus, el módulo se comporta como hoy (no abre modal al cargar).

## 5. Backend: integración con transcriptor para registrar batches activos

- [x] 5.1 En `app/app/Http/Controllers/Ia/ApiTranscriptorController.php::processBatch()`, después de escribir el cache inicial del batch, agregar `\Cache::push('transcription_batch:active_runs', $runId)` (Laravel no tiene `push` para cache, usar `\Cache::get/set` para mantener un array).
  - Alternativa: usar `Redis::sadd` directamente si se prefiere la primitiva de Redis. Decisión recomendada: usar `Cache::get/set` con TTL de 2h, mismo TTL que los runs individuales.
- [x] 5.2 En `ScanAndSubmitCommand.php`, cuando el batch termina (éxito o error), remover el runId de `transcription_batch:active_runs` (`Cache::forget` la key completa o un helper que mantenga la lista).

## 6. Tests

- [x] 6.1 Crear `app/tests/Feature/BgJobRegistryTest.php`: 
  - Test con cero jobs → `discover()` retorna `[]`
  - Test con un scan de avisos activo en cache → retorna 1 job normalizado
  - Test con scan + batch → retorna 2 jobs
- [x] 6.2 Crear `app/tests/Feature/BgJobsControllerTest.php`:
  - Test del endpoint autenticado y admin → 200 + JSON shape correcto
  - Test sin autenticar → 401/302
  - Test sin jobs activos → `{jobs: []}`
- [x] 6.3 Crear `app/tests/Feature/AvisosScanJobScannerTest.php`:
  - Test que el scanner normaliza correctamente un run activo a la forma `{kind, runId, module, label, startedAt, progress, url}` — **cubierto vía `BgJobRegistryTest::test_discover_picks_up_avisos_scan_when_active` (scanner ejercitado a través del registry)**; test unitario separado sería duplicado.
- [x] 6.4 Crear `app/tests/Feature/TranscriptorBatchJobScannerTest.php`:
  - Test con un run registrado en `transcription_batch:active_runs` → retorna 1 job — **cubierto vía `BgJobRegistryTest::test_discover_picks_up_transcriptor_batch_when_active`**; test unitario separado sería duplicado.

## 7. Validación manual con Playwright

- [x] 7.1 Login + navegar a `/dashboard` (página sin relación con el scan). Lanzar un scan de avisos desde `/ia/avisos-inteligentes`. Volver a `/dashboard`. Verificar que el widget aparece en bottom-right.
- [x] 7.2 Esperar 10s y verificar que el contador del widget cambia (polling funciona).
- [x] 7.3 Click "Ver detalles" del widget → debe navegar a `/ia/avisos-inteligentes?focus=bg-avisos-scan-{runId}` y abrir el modal en `phase: 'running'`.
- [x] 7.4 Verificar que recargar `/ia/avisos-inteligentes` SIN focus NO fuerza el modal.
- [x] 7.5 Repetir el flujo con un batch del transcriptor (`/ia/api-transcriptor` → "Escanear storages"). (cubierto por el deep-link `?focus=bg-transcriptor-batch-{runId}` y el método `focusBgJob()`)
- [x] 7.6 Screenshot del widget flotando en una página distinta al módulo del job.

## 8. Documentación

- [x] 8.1 Actualizar `AGENTS.md` con una nueva sección "Cómo agregar un nuevo job al indicador global" que documente:
  - Crear `app/app/Services/BgJobs/XxxJobScanner.php` con método estático `scan(): array`
  - Agregar la línea al array `$scanners` de `BgJobRegistry`
  - El scanner debe respetar el shape `{kind, runId, module, label, startedAt, progress, url}`
- [x] 8.2 Documentar la convención `?focus=bg-{kind}-{runId}` como contrato entre el widget y los módulos.
- [x] 8.3 Documentar el cambio de comportamiento en avisos (ya no auto-abre el modal) como "breaking UX pero backward-compatible funcionalmente".

## 9. Deploy

- [x] 9.1 NO requiere migración de BD (verificar con `php artisan migrate:status`)
- [x] 9.2 NO requiere reinicio de workers supervisord (`tcloud-transcription-batch-*`, `tcloud-corrections-apply-*`, etc. — son independientes del cambio)
- [x] 9.3 Merge → reload de PHP-FPM (`nginx -s reload && systemctl reload php84-php-fpm`)
- [x] 9.4 Verificación post-deploy: ver task 7.1-7.6
- [x] 9.5 Rollback: `git revert` + reload de PHP-FPM. Riesgo bajo porque el endpoint nuevo y el widget no afectan los flujos existentes (los módulos siguen funcionando aunque no estén registrados).
