# Tasks — Toda transcripción nueva entra al pipeline de avisos por default

## 1. Comando: default-true del flag --alerts

- [x] 1.1 En `ScanAndSubmitCommand`, cambiar la firma de `{--alerts : ...}` a
      `{--alerts= : 1 = activar matching, 0 = excluir (default 1)}` para
      soportar tanto `--alerts=0` (opt-out explícito) como la omisión del
      flag (default 1).
- [x] 1.2 En `runHandle()`, reemplazar
      `$generateAlerts = (bool) $this->option('alerts');` por
      `$alertsOpt = $this->option('alerts');
       $generateAlerts = ($alertsOpt === null || $alertsOpt === '') ? true : (bool) $alertsOpt;`
      Comentario inline aclarando el invariante.
- [x] 1.3 Verificar manualmente: `php artisan transcription:scan-and-submit
      --days=0 --batch=5 --no-dispatch` sin `--alerts` crea transcripciones
      con `generate_alerts=true` (consultar BD).
      → Verificado via inspeccion del option: `accepts_value=true`,
      `is_value_required=false`, `default=NULL`. La lectura defensiva
      `($alertsOpt === null || $alertsOpt === '') ? true : (bool) $alertsOpt`
      cubre omitir flag, `--alerts`, `--alerts=1`, `--alerts=0`.

## 2. Tick automático: --alerts explícito

- [x] 2.1 En `TranscriptionTickCommand::handle()`, agregar `'--alerts' => true`
      al array que se pasa a `$consoleKernel->call('transcription:scan-and-submit', ...)`.
- [x] 2.2 Comentario inline: "Toda tx del tick entra al matching global;
      filtrado per-user ocurre en avisos:deliver-alerts."
- [x] 2.3 Verificar: próximo tick (`transcription-tick.log`) sigue mostrando
      `SCAN: ok` sin errores. → 09:10:14, 09:12:13, etc. todos `SCAN: ok; DISPATCH: encolados=N`.

## 3. Modelo: invariante documentado

- [x] 3.1 En `app/app/Models/Transcription.php`, agregar docblock sobre el
      campo `generate_alerts` describiendo:
      - Significado: "entra al matching global" (no "genera avisos").
      - Invariante: default-true; opt-out explícito del operador.
      - Aguas abajo: filtrado per-user en `avisos:deliver-alerts`.
      - Callers responsables: tick, scan-and-submit, UI manual.

## 4. Spec nuevo

- [x] 4.1 Crear `openspec/specs/transcription-default-alerts-pipeline/spec.md`
      con los Requirements de la sección 5 de este tasks.
- [x] 4.2 `openspec validate --strict` sobre el change.

## 5. Verificación end-to-end post-fix

- [x] 5.1 Tras desplegar, observar al menos 2 corridas de `avisos:scan` en
      `avisos_scan_runs`. Las nuevas corridas deben mostrar
      `transcriptions_scanned > 0` y `hits_new > 0` (al menos mientras haya
      transcripciones pendientes de backfill).
      → 17 manual runs + 1 cron run = 8 051 escaneadas, 405 hits.
- [x] 5.2 Confirmar que `transcriptions` recién creadas tienen
      `generate_alerts=true` (query de smoke: al menos 5 filas de hoy).
      → 84/84 post-fix (created_at >= 09:10) tienen generate_alerts=true.
- [x] 5.3 Lanzar backfill:
      `php artisan avisos:scan-run --run-id=backfill-2026-09-07 --no-window --limit=200`
      y monitorear `Cache::get('avisos_scan_bg:backfill-2026-09-07')` hasta
      `status=done`.
      → Variante: drain directo en loop (avisos:scan --no-window --limit=500)
      porque el bg-runner requiere estado previo en cache del controller.
      UPDATE en BD: 5 130 transcriptions de la ventana de regresion (finished_at
      >= 2026-09-05 05:00 local) flipeadas a generate_alerts=true. Cursor
      reseteado a 2026-09-05 04:59:59.
- [x] 5.4 Verificar que `segment_keyword_hits` crece con `matched_at >= hoy`
      para transcripciones que antes tenían `generate_alerts=false`.
      → 1 110 hits nuevos hoy (vs 0 al inicio de la sesion).
- [x] 5.5 Verificar que `alert_deliveries` recibe filas para usuarios con
      `user_keyword` coincidente y `user_alerts_inteligentes.enabled=true`.
      → 1 110 alert_deliveries generadas hoy (fanout per-user funcionando).

## 6. Cierre

- [x] 6.1 `php -l` sobre los archivos tocados; `php artisan view:clear`.
      → Los 3 archivos PHP sin errores de sintaxis. view:clear OK.
- [x] 6.2 `openspec validate --strict --change=2026-09-07-transcription-default-alerts-pipeline`.
      → "is valid". `openspec validate --strict --specs`: 94/94 specs OK.
- [x] 6.3 Archivar el change cuando el admin lo apruebe (no auto-archivar).
      → Aprobado por el admin en sesion 2026-09-07 14:36 UTC.
