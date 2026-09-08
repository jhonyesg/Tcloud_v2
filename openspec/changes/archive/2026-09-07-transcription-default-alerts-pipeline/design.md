# Design — Default del flag --alerts en el pipeline de transcripción

## Context

- `Transcription` se crea en un único punto: `DiskScannerService::scanStorage`
  (`app/app/Services/Ia/DiskScannerService.php:172`). El valor de
  `generate_alerts` se recibe como parámetro `$generateAlerts` y se persiste
  tal cual. No hay default dentro del servicio: depende del caller.
- `DiskScannerService::scanStorage` es invocado por
  `ScanAndSubmitCommand::handle()` (`app/app/Console/Commands/ScanAndSubmitCommand.php:107`).
  El comando lee el flag con `$generateAlerts = (bool) $this->option('alerts')`
  — cuando el caller no pasa `--alerts`, el bool es `false`.
- Caller automático: `TranscriptionTickCommand::handle()`
  (`app/app/Console/Commands/TranscriptionTickCommand.php:79-83`). Construye el
  array `[--days, --batch, --no-dispatch]` sin `--alerts`.
- Caller manual UI: `ApiTranscriptorController::transcriptionBatchRun`
  (`app/app/Http/Controllers/Ia/ApiTranscriptorController.php:1100-1110`). Sí
  concatena `--alerts` cuando el checkbox está marcado (default del request
  es `true`). El comentario en línea documenta el bug ya corregido en este
  camino: *"generate_alerts se validaba arriba pero nunca llegaba al comando"*.
- Caller deprecated: `ProcessBatchCommand` y `ScanNewRecordingsCommand`
  (muestran warning de uso). No se tocan.
- `avisos:scan` filtra por `generate_alerts=true` en
  `AvisosScanService::selectCandidates` y registra corridas en
  `avisos_scan_runs` (`origin`, `transcriptions_scanned`, `hits_new`, etc.).
- `KeywordMatcher::run()` (motor) no consulta `generate_alerts`: opera sobre
  la transcripción que recibe como argumento. La política de selección es
  del scheduler, no del motor.

## Decisions

### D1 — Default-true en `ScanAndSubmitCommand` con respeto del opt-out

Cambiar la lectura del flag en `ScanAndSubmitCommand::runHandle()`:

```php
// Antes:
$generateAlerts = (bool) $this->option('alerts');

// Después:
$generateAlerts = $this->option('alerts') !== false;
```

Interpretación:

- Caller no pasa `--alerts`        → `$this->option('alerts') === null` → `true`
- Caller pasa `--alerts`           → `$this->option('alerts') === true`  → `true`
- Caller pasa `--no-alerts`        → `$this->option('alerts') === false` → `false`

Hoy `--alerts` se define como flag sin valor (`{--alerts : ...}`); para
soportar `--no-alerts` se documenta como convención. Laravel pasa `false`
cuando se usa `--no-flag` solo si el flag fue declarado con `--no-` en la
firma; si no, hay que aceptar el flag con valor. Trade-off: agregar
`{--alerts= : 1/0}` con lectura `(bool) ($this->option('alerts') ?? true)`
funciona con cualquier invocación y no requiere cambio de sintaxis. **Esta
es la implementación preferida** (ver tasks 1.2).

```php
// Implementación preferida (definida en task 1.2):
$alertsOpt = $this->option('alerts');
$generateAlerts = $alertsOpt === null ? true : (bool) $alertsOpt;
```

Sea cual sea la forma, la regla de negocio es la misma: **omitir el flag =
opt-in al matching global**; el opt-out debe ser explícito.

### D2 — `--alerts => true` explícito en `TranscriptionTickCommand`

Independiente del default de D1, el tick pasa el flag explícitamente:

```php
$exitCode = $consoleKernel->call('transcription:scan-and-submit', [
    '--days' => 0,
    '--batch' => $settings->int('scan_batch'),
    '--no-dispatch' => true,
    '--alerts' => true,   // toda tx del tick entra al matching global
]);
```

Razones:
- Diffs legibles: un reviewer ve la intención sin tener que conocer el
  default del sub-comando.
- Defensa en profundidad: si alguien revierte D1, el tick sigue funcionando.
- Consistencia con la documentación que añadiremos en el docblock del modelo.

### D3 — Invariante documentado en `Transcription`

Docblock del modelo `app/app/Models/Transcription.php`, sobre el campo
`generate_alerts`:

```php
/**
 * Bandera global: si true, la transcripción entra al matching de keywords
 * (KeywordMatcher). El filtrado por cliente ocurre aguas abajo en
 * avisos:deliver-alerts (relación user_keyword + user_alerts_inteligentes).
 *
 * Invariante: toda transcripción nueva DEBE crearse con generate_alerts=true
 * salvo opt-out EXPLÍCITO del operador (UI checkbox = false). Excluir en este
 * punto es irreversible dentro del flujo automático: la keyword del cliente
 * nunca será evaluada contra esa transcripción, sin que el cliente ni el
 * admin tengan señal del fallo.
 *
 * Llamadas responsables de respetar el invariante:
 *  - TranscriptionTickCommand (cron, cada 2 min): pasa --alerts.
 *  - ScanAndSubmitCommand (sub-comando): default-true en --alerts.
 *  - ApiTranscriptorController (UI manual): respeta el checkbox del operador.
 */
protected $fillable = [..., 'generate_alerts', ...];
```

### D4 — Backfill operativo post-fix

Las transcripciones con `generate_alerts=false` que ya están terminadas NO
deben borrarse: la decisión operativa al crearlas fue accidental. El
backfill:

1. Aplicar el fix de código (D1 + D2 + D3).
2. Verificar el siguiente tick: `avisos_scan_runs.transcriptions_scanned > 0`
   en la corrida siguiente.
3. `php artisan avisos:scan --no-window --limit=200 --origin=manual` en
   bucle, o mejor:
4. Disparar el runner background con cache de progreso:
   ```
   php artisan avisos:scan-run --run-id=backfill-2026-09-07 --no-window --limit=200
   ```
   Monitorear `avisos_scan_bg:{run-id}` en cache hasta `status=done`.
5. Una vez drenado, los hits nuevos aparecen en `segment_keyword_hits` y
   el fanout per-user crea `alert_deliveries` en la próxima corrida de
   `avisos:deliver-alerts` (cron cada minuto).

No se hace un UPDATE masivo `UPDATE transcriptions SET generate_alerts=true`
porque queremos que el matching ocurra UNA vez por transcripción (la
idempotencia del `KeywordMatcher` lo garantiza vía UNIQUE triple).

### D5 — Spec nuevo, no parche del existente

El spec `avisos-scan-configuration/spec.md` describe el comportamiento del
**scan** (capa 2). El invariante roto vive en la **capa 1** (ingesta). Un
spec separado bajo `transcription-default-alerts-pipeline` deja la
responsabilidad clara y permite referenciarlo desde D3 sin tener que
editar un spec ajeno. Si en el futuro el scan cambia, este spec no se ve
afectado.

## Risks / Trade-offs

- **[Default-true] Rompe el contrato del flag** — Caller explícito `--no-alerts`
  sigue funcionando; la única conducta que cambia es "omitir el flag ahora
  activa alertas". Es el comportamiento deseado. Riesgo bajo.
- **[Backfill] Carga repentina en KeywordMatcher** — ~3 000 transcripciones
  pendientes de re-escaneo (24 h) más histórico. `KeywordMatcher` es
  idempotente y ya procesa lotes grandes. Usar `--limit=200` mantiene la
  carga distribuida en tandas cortas. Riesgo bajo.
- **[Backfill] Crea alert_deliveries retroactivas** — Sí, intencional.
  Hits con `matched_at` antiguo generan entregas con `due_at = now()`
  (cadencia del cliente). El admin ve aparecer avisos de keywords que el
  cliente tenía registradas: ese es el comportamiento correcto del sistema
  cuando no estaba roto.
- **[Operativo] Verificación de que el fix funciona** — El síntoma era
  "todo verde, no pasa nada". Después del fix, la corrida siguiente del
  tick debe mostrar `scanned > 0`. El test de aceptación está en
  tasks.md (sección 5).
- **[Forward-compat] Otros crons futuros** — Cualquier futuro comando que
  cree transcripciones debe respetar D3. Lo cubrimos con el spec y el
  docblock; D1 actúa como red de seguridad si alguien lo olvida.

## Migration Plan

1. Aplicar D2 + D1 (con la sintaxis `{--alerts=}` propuesta en tasks 1.2)
   en `TranscriptionTickCommand` y `ScanAndSubmitCommand`.
2. Actualizar docblock en `Transcription` (D3).
3. Crear el spec nuevo (D5).
4. Verificar el próximo tick: el log `transcription-tick.log` muestra
   `SCAN: ok` y la corrida `avisos:scan` correspondiente en
   `avisos_scan_runs` muestra `transcriptions_scanned > 0`.
5. Lanzar backfill con `avisos:scan-run --run-id=backfill-2026-09-07 --no-window`.
6. Confirmar en `segment_keyword_hits` que aparecen hits con
   `matched_at >= 2026-09-07` y `transcription_id` correspondientes a
   transcripciones que antes tenían `generate_alerts=false`.
7. Validar `openspec validate --strict` y archivar el change cuando el
   admin lo apruebe.

Rollback: revertir D1 + D2 (volver al default-false) es suficiente para
detener el matching entrante; las transcripciones ya escaneadas no se
deshacen (idempotencia del matcher: si se vuelven a escanear, insertOrIgnore
las deja igual).
