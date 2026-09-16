## REMOVED Requirements

### Requirement: Operador elige alcance de escaneo (HOY / rango de fechas / histórico completo)
### Requirement: Estimación previa muestra conteo de candidatos sin encolar
### Requirement: Escaneo en background con `runId`, polling de progreso, deep-link desde widget global

**Reason**: La spec documenta el modal "Escanear storages" con presets de ventana temporal (HOY/rango/histórico), el endpoint `POST /ia/api-transcriptor/scan/estimate` que mostraba conteo previo, el endpoint `POST /ia/api-transcriptor/process-batch` que lanzaba el escaneo en background, y el endpoint `GET /ia/api-transcriptor/batch-status/{runId}` para el polling. El modal y los endpoints se eliminan en el change `simplify-api-transcriptor-to-storage-and-config`.

**Migration**: El escaneo automático cada 2 minutos (`transcription:tick` Phase 1) sigue siendo la fuente de verdad y respeta el alcance "solo día en curso" (`mtime >= now()->startOfDay()`). Para forzar un escaneo manual con ventana distinta:
- Día actual: `php artisan transcription:scan-and-submit --days=0 --batch=200`
- Día anterior: `--days=1`
- Rango: `--from=DDMMYYYY --to=DDMMYYYY`
- Histórico: `--days=N` con N grande (ojo: el bulk se respeta el regulador con `computeDispatchBatch`)

El widget global de bg-jobs (`bg-job-indicator-widget`) sigue siendo útil para otros scans (Correcciones, etc.), pero ya no se activa desde este módulo.