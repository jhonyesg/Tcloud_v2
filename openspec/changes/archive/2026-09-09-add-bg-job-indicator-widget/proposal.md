## Why

Cuando un operador lanza un escaneo en `/ia/avisos-inteligentes` y recarga la página (o navega a otra sección), el componente Alpine fuerza `activeTab = 'escaneo'` y abre automáticamente el modal "Escaneo de menciones" sin pedir permiso. Esto bloquea la UI y contradice el texto del propio modal ("puedes cerrar esta ventana o recargar la página sin perder el progreso"), dejando al operador atrapado en la pestaña aunque quisiera estar haciendo otra cosa. El mismo problema aplica al botón "Escanear storages" del API Transcriptor y a cualquier otro job en background que se quede activo entre requests.

## What Changes

- Nuevo endpoint unificado `GET /bg-jobs/active` que devuelve todos los jobs en background activos de todos los módulos (avisos-scan, transcriptor-batch, futuros).
- Nuevo widget flotante en `layouts/app.blade.php` (esquina inferior derecha) que muestra una card por job activo con módulo, progreso y acción de "Ver detalles".
- El widget hace polling cada 5s del endpoint unificado. Cuando no hay jobs activos, el widget se oculta y el polling se detiene automáticamente.
- Click en una card del widget → navega al módulo correspondiente (`/ia/avisos-inteligentes`, `/ia/api-transcriptor`, etc.) y, si la URL lleva un hint `?focus=bg-{kind}-{runId}`, el módulo abre su modal de detalle (no auto-abre sin ese hint).
- `checkActiveScanRun()` en el blade de avisos deja de auto-abrir el modal y auto-cambiar la pestaña; solo attacha el polling local silenciosamente. Lo mismo aplica al `runBatch()` del API Transcriptor cuando se invoque desde el widget.
- Nuevo componente Alpine `bgJobIndicator` que orquesta el polling y el render del widget. Vive en el layout (un único polling compartido, no N pollers).

## Capabilities

### New Capabilities
- `bg-job-indicator`: el widget flotante global, su contrato visual, polling, y el endpoint unificado `/bg-jobs/active` que agrega jobs de todos los módulos.

### Modified Capabilities
- `avisos-scan-configuration`: nuevo requisito "Cuando hay un escaneo activo al cargar la página, el módulo NO fuerza cambio de pestaña ni abre el modal automáticamente; solo actualiza el indicador global. El modal se abre solo cuando el operador hace click explícito en el indicador o cuando lanza un escaneo nuevo."
- `transcription-disk-scanner`: nuevo requisito sobre el comportamiento equivalente: si un batch queda activo y el operador recarga, el módulo NO fuerza el modal; solo el indicador global muestra progreso.

## Impact

**Backend nuevo:**
- `app/app/Http/Controllers/BgJobsController.php` — endpoint `GET /bg-jobs/active`
- `app/app/Services/BgJobRegistry.php` — registry que sabe cómo consultar cada módulo (método `discover(): array` que devuelve `[{kind, runId, module, label, progress, url, eta_seconds}, ...]`)
- `app/routes/web.php` — nueva ruta bajo `auth, admin` middleware

**Backend modificado:**
- `app/app/Http/Controllers/Ia/AvisosInteligentesController.php` — `checkActiveScanRun()` endpoint ya existe, sigue igual; pero el cliente ya no auto-abre
- `app/app/Http/Controllers/Ia/ApiTranscriptorController.php` — sin cambios; el batch-status ya funciona

**Frontend nuevo:**
- `app/resources/views/components/bg-job-indicator.blade.php` — partial reusable
- Alpine component `bgJobIndicator` registrado en `app/resources/views/layouts/app.blade.php`

**Frontend modificado:**
- `app/resources/views/ia/avisos-inteligentes/index.blade.php` — `checkActiveScanRun()` ya no setea `scanModal.open = true` ni `activeTab = 'escaneo'` automáticamente
- `app/resources/views/ia/api-transcriptor/index.blade.php` — sin cambios (runBatch solo se dispara con click explícito)
- `app/resources/views/layouts/app.blade.php` — incluye el partial del widget

**Tests nuevos:**
- `app/tests/Feature/BgJobRegistryTest.php` — verifica que el registry agrega correctamente jobs de avisos-scan y transcriptor-batch
- `app/tests/Feature/BgJobsControllerTest.php` — verifica respuesta del endpoint con cero, uno y múltiples jobs activos
- E2E con Playwright: navegar a otra página con scan activo → widget visible; click widget → navega al módulo sin auto-modal

**No requiere migración de BD.** No requiere reinicio de workers supervisord. Solo reload de PHP-FPM y limpieza de opcache.

## Non-goals

- No se rediseñan los modales internos de cada módulo (siguen mostrando el detalle completo del job cuando el usuario hace click explícito).
- No se cambia el mecanismo de background-launch (sigue siendo `RunsBackgroundCommands::execBackground` con bash wrapper, ya arreglado en `fix-transcriptor-batch-bg-launcher`).
- No se implementan notificaciones push ni WebSockets — el polling cada 5s es suficiente y simple.
- No se rediseña el layout global ni el sidebar — solo se agrega el widget flotante.
- No se agrega un endpoint de "cancelar job" desde el widget (cada módulo ya tiene su botón "Detener" interno).
- No se internacionalizan los strings del widget en este change (queda en español, alineado con el resto de la UI del proyecto).
