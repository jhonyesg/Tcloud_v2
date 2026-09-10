# Design: Mis Avisos — Filtrar por fecha del programa, no de detección

## Contexto arquitectónico

El módulo de avisos tiene **dos fechas canónicas** que se confunden en el query layer actual:

| Fecha | Campo actual | Significado | Cuándo se setea |
|---|---|---|---|
| Fecha del programa | **nuevo** `transcriptions.recorded_at` | Cuándo se emitió el audio en TV/radio | Al crear la transcripción (derivado del nombre del archivo o file_modified_at) |
| Fecha de procesamiento | `transcriptions.finished_at` | Cuándo el transcriptor devolvió el SRT | Al completarse la transcripción |
| Fecha de detección | `segment_keyword_hits.matched_at` | Cuándo el motor de matching encontró la keyword | Al insertarse el hit por KeywordMatcher/LegacyKeywordMatcher/MentionBackfillService |

El bug actual es que `MentionsSearchService::searchHistory` filtra por `h.matched_at` (línea 175). Cliente pide "WinSport del 7 sep 14:30" pero el `matched_at` real es del 9 sep 17:18 (cuando el cron del matching detectó la keyword, 2 días después).

## Decisiones de diseño

### D1: Nuevo campo `recorded_at` en vez de reusar `finished_at`

**Por qué no `finished_at` directamente**: en la mayoría de los casos `finished_at ≈ recorded_at` (la transcripción termina el mismo día), pero puede haber desfase (audio de madrugada que se procesa al día siguiente, batch catch-up que detecta keywords 2+ días después). Usar `finished_at` cubre el 80% pero no garantiza que el filtro refleje la fecha real del programa. Crear un campo dedicado da:
- Semántica clara e independiente del procesamiento.
- Fuente de verdad estable aunque cambien los pipelines de transcripción.
- Compatibilidad con futuros formatos de audio (live streams, archivos de audio que ya vienen con fecha incrustada).

**Por qué sí `recorded_at`**: el parseo del nombre del archivo (`*_DDMMYYYY_HHMMSS.*`) es determinístico y los archivos de TCloud siguen ese patrón de naming consistente (verificado en `Disco_C/television/WinSport/07092026/winsport_07092026_141502.mp4`).

### D2:Backfill idempotente con fallback en cascada

```
recorded_at = 
  parseFilename(name)              // *_DDMMYYYY_HHMMSS.*
  ?? file_modified_at              // timestamp con timezone
  ?? finished_at                   // transcripción terminó
  ?? NULL                          // caso degenerado: requiere atención admin
```

Tres niveles de fallback porque:
- Archivos nuevos del módulo de grabación: el patrón DDMMYYYY es el 95%+ de los casos (verificado en WinSport, La W, Caracol, RCN, NTN24).
- Archivos viejos subidos manualmente sin naming consistente: `file_modified_at` da la fecha del filesystem.
- Casos degenerados (sin pattern, sin file_modified_at): quedan NULL, visibles al admin vía dashboard de cobertura.

El backfill se hace en una sola migración con SQL puro (no Artisan command) para que sea rápido y auditable. Reporte al final con conteo de cada nivel de fallback.

### D3: `date_field` como parámetro explícito en el service, default `program`

```php
$search->searchHistory($user, [
    'date_field' => 'program',     // o 'detected'
    'from' => '2026-09-07',
    'to' => '2026-09-07',
    ...
]);
```

**Por qué explícito y no implícito**:
- Cualquier consumidor futuro del service debe decidir conscientemente qué fecha quiere. Si el default cambia (de `program` a `detected` en algún momento futuro), los call sites con default implícito rompen silenciosamente.
- Las métricas internas (`preferences` → `hitsLastWeek`) necesitan `detected` siempre; pasarlo explícito lo deja claro.

**Por qué default `program`**:
- El usuario piensa en fecha del programa. Es lo natural.
- Las métricas internas usan `detected` explícitamente, así que el default solo aplica al consumidor "natural" (Mis Avisos).
- Si en el futuro algún cliente necesita default `detected`, se puede invertir — pero ahora no hay evidencia de esa necesidad.

### D4: Toggle UI, no cambio silencioso

Default `program` es invisible para el operador que no sabía del problema. Para los pocos casos donde `detected` sí es lo correcto (auditoría interna, "¿se procesó hoy lo de ayer?"), el toggle queda a la vista:

```
Filtrar por: [● Fecha del programa] [○ Fecha de detección]
```

El tooltip explica la diferencia con un ejemplo concreto del estilo: *"Fecha del programa: cuándo se emitió el medio (p. ej. WinSport del 7 sep a las 14:30). Fecha de detección: cuándo el sistema encontró la keyword (puede ser horas o días después)."*

### D5: `AlertDeliveryService` y `SendAlertDigest` no cambian

Las alertas **son detecciones**. Si llega un correo "Omar Perez en WinSport del 7 sep 14:30", lo que importa al cliente es:
- El programa es del 7 sep 14:30 (`recorded_at`).
- La detección fue hoy o ayer (`matched_at`).

Pero el **trigger de envío** es "tengo hits nuevos desde la última alerta enviada", que es semántica de `matched_at`. Cambiar eso requiere repensar el fanout — fuera de alcance.

El `AlertDeliveryService` (línea 73) y `SendAlertDigest` (línea 68) siguen usando `h.matched_at`. Documentado en el spec nuevo.

### D6: Frontend muestra ambas fechas en la tabla

La tabla de hits agrega una columna **"Programa"** (`recorded_at` formateado) sin eliminar **"Detectado"** (`matched_at`). El operador ve:

```
| Programa          | Detectado         | Keyword       | Contexto                |
| 2026-09-07 14:30  | 2026-09-09 17:18  | Omar Perez    | "...tenía Seijas,..." |
```

Eso resuelve la ambigüedad visual sin obligar al operador a entender el toggle. La columna "Detectado" puede pasar a segundo plano visual (color gris, fuente menor) para que "Programa" sea la primaria.

### D7: Spec formal nuevo `mis-avisos-program-date`

Capacidad dedicada en `openspec/specs/mis-avisos-program-date/spec.md` con:
- Requirements sobre el campo `recorded_at` (origen, fallback, inmutabilidad post-creación).
- Requirements sobre el toggle UI (default, posiciones, whitelist).
- Requirements sobre la coexistencia con `matched_at` (ningún consumidor existente debe romperse).
- Escenarios Gherkin con datos reales del caso Monitoreoalpunto.

## Plan de implementación

### Fase 1 — Modelo de datos (migración + backfill)
1. Migración que agrega `transcriptions.recorded_at TIMESTAMPTZ` (nullable, índice `(recorded_at)` parcial para acelerar queries de histórico).
2. SQL de backfill en la misma migración, transaccional, con reporte por consola al final (`[recorded_at] parsed=85%, fallback=10%, null=5%`).
3. Migration test: verifica que el 100% de filas con nombre patrón DDMMYYYY tenga `recorded_at` correcto.

### Fase 2 — Cálculo automático en creación
4. Helper `RecordedAt::fromFilename(string $name): ?Carbon` con regex robusto y casos de prueba.
5. Helper `RecordedAt::resolve(string $name, ?Carbon $fileModifiedAt, ?Carbon $finishedAt): ?Carbon` con la cascada D2.
6. Integración en `Transcription::create()` (model boot) — setea `recorded_at` automáticamente al insertar.
7. Verificar que `ScanAndSubmitCommand`, paths manuales de upload y `ConvertAndTranscribeJob` no rompen el flujo.

### Fase 3 — Capa de servicio
8. `MentionsSearchService` acepta `date_field` en `searchHistory`, `todayHits`, y todos los métodos que filtren por fecha.
9. `hitRow` agrega `recorded_at` al array de respuesta.
10. Validación: `date_field` ∈ {'program', 'detected'}, default 'program'.

### Fase 4 — Controller
11. `MisAvisosController::history` y `feed` aceptan y propagan `date_field`.
12. `MisAvisosController::preferences` usa `detected` explícito.
13. `requestExport` propaga `date_field` al job.

### Fase 5 — Job y exportación
14. `MentionsExportJob` recibe y aplica `date_field` en su query.
15. Tests del job con ambos modos.

### Fase 6 — UI
16. Frontend: toggle en `mis-avisos/index.blade.php` (~30 líneas).
17. Frontend: columna "Programa" en `_table-hits.blade.php` (~20 líneas).
18. CSS: estilo secundario para columna "Detectado" para que no compita visualmente.
19. Tooltip con ejemplo.

### Fase 7 — Spec y validación
20. Crear `openspec/specs/mis-avisos-program-date/spec.md` con scenarios.
21. E2E con Playwright: simular login del cliente Monitoreoalpunto, buscar "Omar Perez" del 7 sep, screenshot del resultado.
22. Re-correr harnesses existentes (`harness_mis_avisos_menciones`, `harness_mis_avisos_viewer`) para verificar no-regresión.

## Tests

- **Unit**: `RecordedAt::fromFilename` con 12+ variantes de nombres válidos e inválidos.
- **Unit**: `RecordedAt::resolve` con cada nivel de fallback.
- **Integration**: backfill migration deja `recorded_at` no-nulo para 99%+ de las filas existentes (medido en migración).
- **Integration**: query con `date_field=program` devuelve hits del 7 sep; query con `date_field=detected` no.
- **E2E Playwright**: cliente Monitoreoalpunto ve las 9 menciones del 7 sep al buscar con toggle en `program`.

## Riesgos abiertos

- **Stored procedures o queries SQL ad-hoc** que usen `matched_at` directamente sin pasar por `MentionsSearchService`: si los hay, no se ven afectados por este change (no usamos su filtro), pero podrían dar lugar a inconsistencias entre la UI y otras vistas internas. Búsqueda en código limitada a lo revisado; si aparecen más tarde, son follow-ups.
- **Performance**: el índice nuevo `(recorded_at)` agrega espacio y costo de write. Estimado: ~5% sobre el tamaño de la tabla `transcriptions`. Aceptable. Si la tabla crece más allá de 5M filas (ver trigger de `partition-segment-keyword-hits`), se replantea.
- **Backfill en producción**: la migración corre sobre la BD en vivo. Si la tabla tiene millones de filas, el UPDATE puede tardar minutos y bloquear la tabla. Estrategia: `UPDATE ... WHERE id IN (subquery paginada)` en lotes de 10k con `pg_sleep(0.1)` entre lotes. Documentado en la migración.
