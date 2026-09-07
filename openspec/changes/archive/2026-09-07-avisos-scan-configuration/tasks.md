# Tasks — Sub-ventana de configuración del escaneo de menciones

## 1. Base de datos y servicio de escaneo

- [x] 1.1 Migración `avisos_scan_runs` (origin, status, params JSON, transcriptions_scanned, hits_new, failed_count, duration_ms, error, started_at, finished_at) con índices (origin, created_at) y (status, created_at)
- [x] 1.2 Crear app/app/Services/Ia/AvisosScanService.php según design D3: run(opts), selectCandidates(opts) (LEFT JOIN anti-hits + ventana + lote), scanOne() con try/catch por transcripción, settings()/saveSettings() sobre SystemSetting con validación de mínimos, lastRun()
- [x] 1.3 Implementar corrida real: registrar fila en avisos_scan_runs con resumen (origin, params, conteos, duración, status); corridas omitidas solo Log sin fila (D4)
- [x] 1.4 Soporte dry-run (conteo de candidatos sin escanear) y --force (borrado previo de hits de los objetivos, solo explícito)
- [x] 1.5 php -l / pint y prueba del servicio aislada con datos temporales (3 transcripciones: sin hits, con hits, generate_alerts=false) verificando selección, idempotencia y no-correos

## 2. Comando y cron

- [x] 2.1 Crear app/app/Console/Commands/ScanMentionsCommand.php (signature `avisos:scan {--storage=} {--transcription=} {--from=} {--to=} {--limit=} {--force} {--dry-run} {--origin=cron}`) delegando en AvisosScanService
- [x] 2.2 Registrar el tick en app/routes/console.php: `Schedule::command('avisos:scan')->everyFiveMinutes()->withoutOverlapping(15)` con comentario del patrón settings (tick fijo + decisión interna, igual que sessions_cleanup_interval_minutes)
- [x] 2.3 Decisión interna del comando: avisos_scan_enabled=false → salir; dentro de ventana de intervalo → salir con Log avisos.scan.skipped; nueva corrida solo cuando toca
- [x] 2.4 Prueba: avisos:scan --dry-run con datos temporales; corrida real verificando fila en avisos_scan_runs y que NO se crea ninguna alert_deliveries ni correo

## 3. Endpoints admin

- [x] 3.1 `GET /ia/avisos-inteligentes/scan` → settings vigentes + últimas 10 corridas + estimación de pendientes en la ventana
- [x] 3.2 `PUT /ia/avisos-inteligentes/scan/settings` → validar y persistir enabled/interval_minutes/window_hours (interval ≥ 5, window ≥ 1); respuesta con valores corregidos
- [x] 3.3 `POST /ia/avisos-inteligentes/scan/run` → corrida manual con filtros (storage, from/to, force); estimación previa si supera el lote (respuesta pide confirmación con confirmed=true)
- [x] 3.4 Proteger las tres rutas con middleware admin (grupo existente) y throttle razonable (settings/run: 10/min)

## 4. Sub-ventana en la UI admin

- [x] 4.1 Pestaña "Escaneo" en ia/avisos-inteligentes/index.blade.php (tabs Clientes/Escaneo con el mismo stack Blade+Alpine+Tailwind, sin build step)
- [x] 4.2 Panel de configuración: switch automático + intervalo (min 5, con corrección visible) + ventana de re-escaneo (horas); guardar → PUT settings con feedback
- [x] 4.3 Panel "Escanear ahora": storage (select de storages con gestores), rango de fechas, checkbox forzar con confirmación si la estimación supera el lote; resultado con resumen de la corrida
- [x] 4.4 Panel de estado: tarjeta última corrida (fecha, origen, duración, escaneadas, hits nuevos, estado/error) + tabla de últimas 10 corridas + próximo tick estimado
- [x] 4.5 Estados vacíos y errores: "nunca ha corrido" con defaults propuestos, mensajes del servidor en rechazo (422/403)

## 4b. Modal de progreso (feedback del usuario)

- [x] 4.6 Modal de progreso que reemplaza confirm/alert nativos: fase confirm con estimacion, fase running con barra + contadores acumulados en vivo (tandas de 50), boton Detener, resumen final con errores listados
- [x] 4.7 Bucle de drenaje en cliente (corrida tras corrida hasta vaciar candidatos o Detener), con correccion de doble lectura del response (bug detectado por E2E)

## 5. Validacion final

- [x] 5.1 php -l / pint sobre archivos tocados; harness o test aislado del servicio (selección por ventana, idempotencia, no-correos, corrida manual con force)
- [x] 5.2 E2E manual con Playwright/documentado: activar automático con intervalo 5 → esperar tick → corrida registrada; "Escanear ahora" con filtro de storage → resumen; ver corrida fallida con error inyectado
- [x] 5.3 Verificar separación estricta scan→entrega: grep de que AvisosScanService/ScanMentionsCommand no llaman AlertDispatcher ni crean alert_deliveries
- [x] 5.4 `openspec validate --strict` y archivar si el usuario lo pide
