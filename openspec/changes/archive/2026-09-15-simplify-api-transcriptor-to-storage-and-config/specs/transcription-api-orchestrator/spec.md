## REMOVED Requirements

### Requirement: Admin puede ver transcriptions recientes y sus detalles
**Reason**: La pestaña Trabajos y la página de detalle `/ia/api-transcriptor/jobs/{id}` se eliminan porque el upstream no está enviando trabajos (delay de despacho promedio de 3.7h verificado el 2026-09-15), así que el operador ve listas vacías o stuck que no puede actuar desde la UI. La consulta del estado de transcripciones de un storage pasa al dashboard (`dashboard-modular-partials`) y a Mis Avisos (que ya lee `transcriptions` por storage).

**Migration**: Ver mis-avisos (`/ia/avisos-inteligentes`) para consultar el detalle de transcripciones de un storage concreto, y el dashboard (`/dashboard`) para el resumen global. La consulta SQL directa sigue siendo `SELECT * FROM transcriptions WHERE file_id IN (SELECT id FROM files WHERE storage_provider_id = ?)`.

### Requirement: Admin puede re-encolar un job fallido manualmente
**Reason**: El botón "Reintentar" del detalle del job dependía de la pestaña Trabajos que se elimina. El reintento masivo sigue siendo posible vía CLI: `php artisan transcription:retry-batch-upstream --max-age-hours=168` corre semanalmente desde cron (lunes 04:00 UTC).

**Migration**: Reintento individual: usar `php artisan tinker` y `Transcription::find($id)->requeue()` o ejecutar `transcription:retry-batch-upstream --dry-run` para auditar antes de aplicar. AGENTS.md documenta el flujo CLI.

### Requirement: Procesamiento por lote en background con alertas opcionales
**Reason**: El botón "Escanear storages" + modal de progreso dependía de la UI completa de Trabajos. El discovery automático vía `transcription:scan-and-submit` cada 2 minutos (Phase 1 del tick) sigue activo y es la fuente de verdad; no necesita disparo manual.

**Migration**: Si el operador quiere forzar un barrido inmediato: `php artisan transcription:scan-and-submit --days=0 --batch=200 --from=YYYYMMDD --to=YYYYMMDD`. El comando respeta el regulador (`computeDispatchBatch`), no satura.

### Requirement: Procesamiento manual por carpeta o día
**Reason**: El navegador de archivos por storage (`storageFiles`) y los botones "Procesar carpeta" / "Procesar día" dependían del modal de archivos del módulo. La operativa de "transcribir todo de un día" sigue siendo posible vía CLI.

**Migration**: `php artisan transcription:scan-and-submit --days=1 --batch=200` cubre AYER; con `--days=0` cubre HOY. Para una carpeta concreta: `--from=DDMMYYYY --to=DDMMYYYY` con filtro por storage. AGENTS.md mantiene la cheatsheet.

### Requirement: Re-encolar upstream de jobs zombies vía `unstick`
**Reason**: El botón "Re-encolar upstream" del detalle del job se elimina con la pestaña Trabajos.

**Migration**: Mismo flujo que `retry`: la API upstream `POST /v1/jobs/{id}/unstick` se puede llamar desde tinker, o esperar al `transcription:retry-batch-upstream` semanal.

### Requirement: Eliminar upstream + local con `delete_upstream`
**Reason**: El botón "Eliminar" del detalle del job se elimina con la pestaña Trabajos. La eliminación masiva se hace por el comando `transcription:purge-stale-pending` (ya scheduled) o desde BD.

**Migration**: Desde CLI: `php artisan tinker` → `Transcription::where('state', 'dead')->where('created_at', '<', now()->subDays(30))->delete()`. AGENTS.md documenta las ventanas de retención.

### Requirement: Reintento masivo con `retry-batch`
**Reason**: El botón "Reintentar todos los fallidos" se elimina con la pestaña Trabajos.

**Migration**: `php artisan transcription:retry-batch-upstream --max-age-hours=168` (scheduled lunes 04:00 UTC) o manual vía CLI.

### Requirement: Cancelación upstream centralizada en cliente
**Reason**: El botón "Cancelar" del detalle del job se elimina con la pestaña Trabajos.

**Migration**: `TranscriptorApiClient::cancelUpstream($jobId)` sigue existiendo en el código backend (lo sigue usando `transcription:cancel-stuck-old-jobs` desde el active change `transcriptor-cancel-stuck-old-jobs`); solo desaparece el wrapper del controller.