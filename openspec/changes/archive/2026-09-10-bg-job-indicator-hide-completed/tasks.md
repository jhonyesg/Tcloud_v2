## 1. Backend — fix de contrato en `ScanAndSubmitCommand`

- [x] 1.1 En `app/app/Console/Commands/ScanAndSubmitCommand.php`, reemplazar el bloque "if (!alreadyListed) append" por una iteración que sobrescriba la entrada existente del `$runId` con `['runId' => $runId, 'finishedAt' => $finishedAtIso]`, manteniendo el `Cache::put` final con TTL `now()->addHours(2)`. Mantener el comportamiento de append si no se encontró el runId.

- [x] 1.2 Verificar `php -l app/app/Console/Commands/ScanAndSubmitCommand.php`.

## 2. Backend — fallback de `updated_at` en `TranscriptorBatchJobScanner`

- [x] 2.1 En `app/app/Services/BgJobs/TranscriptorBatchJobScanner.php`, cargar `$state` ANTES de calcular `$effectiveFinishedAtIso`, y usar `updated_at`/`finished_at` como fallback cuando `$finishedAtIso` es null.

- [x] 2.2 Warning+discard para jobs terminales huérfanos de timestamp.

- [x] 2.3 Verificar `php -l app/app/Services/BgJobs/TranscriptorBatchJobScanner.php`.

## 3. Frontend — TTL de `dismissed` y clave versionada en `bg-job-indicator.blade.php`

- [x] 3.1 Constantes `DISMISS_TTL_MS: 86_400_000` y `DISMISSED_KEY: 'bg_jobs:dismissed:v2'`.

- [x] 3.2 Lectura en `boot()` usa `DISMISSED_KEY`.

- [x] 3.3 Escritura en `persistDismissed()` usa `DISMISSED_KEY`.

- [x] 3.4 Cutoff del setInterval usa `DISMISS_TTL_MS`.

- [x] 3.5 Blade sirve tal cual, sin build.

## 4. Backend — remover transcriptor-batch del widget flotante (FIX DE RAÍZ)

- [x] 4.1 En `app/app/Services/BgJobRegistry.php`, **eliminar** la entrada `'transcriptor-batch' => [..., 'scan']` del array `$scanners`. Documentar en el docblock por qué se removió y cómo re-registrar si hace falta.

- [x] 4.2 Revertir `TERMINAL_TTL_SECONDS` de 60s a 300s en `TranscriptorBatchJobScanner` (queda como contrato defensivo: el scanner ya no es invocado por el widget, pero si alguien lo re-registra, mantiene el comportamiento original).

- [x] 4.3 Verificar que `/bg-jobs/active` ya no devuelve jobs de kind `transcriptor-batch`.

## 5. Smoke test post-implementación (re-escrito con Playwright — dos modos)

- [x] 5.1 `tests/playwright_bg_job_indicator_quick.py` — smoke rápido (<30s). Sin batch: login, check `/bg-jobs/active` no devuelve transcriptor-batch, verifica widget sin tarjetas en /ia/api-transcriptor, /files, /admin/storages. Barra inline oculta sin batch activo.

- [x] 5.2 `tests/playwright_bg_job_indicator_full.py` — E2E con batch real. Lanza escaneo desde UI, verifica que modal se cierra, barra inline aparece en modo compacto, toggle Más/Menos expande/colapsa, barra persiste tras finalización con × para descartar, navegación a otras páginas no muestra tarjetas.

- [x] 5.3 `tests/playwright_bg_job_indicator_run_parallel.py` — runner que ejecuta quick + full en paralelo (procesos Python independientes). Cada uno en su propio contexto de Playwright.

## 6. UX inline: barra compacta con toggle "Más" (reemplazo del modal bloqueante)

- [x] 6.1 Insertar barra inline después de la sección de ayuda en `app/resources/views/ia/api-transcriptor/index.blade.php` (~línea 116). Visible cuando `batchRunning || batchResult`. Modo compacto por defecto con label "Más"/"Menos" para expandir.

- [x] 6.2 Modo expandido muestra: archivo procesándose, lista de storages con estado por uno, errores en vivo, resumen final con grid de stats (candidatos/procesados/errores/encolados), botón × para descartar.

- [x] 6.3 Modificar `runBatch()` para que cierre el modal bloqueante (`showBatchModal = false`) tras lanzar el batch. La barra inline toma el control desde ese momento.

- [x] 6.4 Agregar `batchExpanded: false` al estado Alpine para manejar el toggle.

## Cambios durante implementación

Durante la validación con Playwright (usuario `jsuarez`), descubrimos que la solución propuesta originalmente (extender TTL de dismissed + fallback de scanner) era innecesariamente compleja. El usuario prefirió la solución de raíz: **eliminar el transcriptor-batch del widget flotante** y mostrar el progreso solo inline en el módulo, que ya tenía su propia UI (header pill línea 911 + modal automático).

Cambios respecto al diseño original:
- Tarea 4.1–4.3 reemplazan a las 4.1–4.5 originales (que asumían TTL extendido + fallback scanner como solución principal).
- Las tareas 1.1, 1.2, 2.x, 3.x se mantienen: son mejoras de calidad de datos que quedan como defensa en profundidad por si alguien re-registra el scanner en el futuro.
