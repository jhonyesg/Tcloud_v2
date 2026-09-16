## 1. Server-side grouping helper (transversal a las 3 fases)

- [x] 1.1 Crear `app/Services/Ia/MentionBackfillService.php` con método estático `groupHits(Collection $rows): Collection` que toma filas planas (`transcription_id`, `keyword_id`, `storage_id`, `storage_name`, `filename`, `matched_at`, `start_seconds`, `segment_id`, `snippet`, `occurrences`) y devuelve un `Collection` indexado por `"{transcription_id}:{keyword_id}"`. Cada item: `{ key, transcription_id, filename, storage_name, keyword_id, keyword, total, hits: [ordered hits], first_matched_at, first_start_seconds, first_segment_id, first_snippet, can_view_file }`.
- [x] 1.2 Test unitario inline: 6 rows mismas trans+kw → 1 grupo con `count=6`; 2 keywords distintas misma transc → 2 grupos.

## 2. Fase 1 — Admin matches UI + data source

- [x] 2.1 En `app/Http/Controllers/Ia/AvisosInteligentesController.php::show()`: cambiar `$user->keywordMatches()->...->paginate(25)` por `$search->visibleHitsQuery($user)` seguido de `->select(...)->paginate(25)`. Reusar `MentionsSearchService` que ya existe.
- [x] 2.2 Server-side grouping: aplicar `MentionBackfillService::groupHits()` a `$matches` antes de pasarlo a la vista. La vista recibe `Collection $matchGroups` (cada grupo con sus filas).
- [x] 2.3 En `resources/views/ia/avisos-inteligentes/user-detail.blade.php` (sección Matches ~línea 155-185): reemplazar el `@foreach($matches as $match)` con `@foreach($matchGroups as $group)` y dentro de cada group, una fila resumen + un `<details>` con la lista de menciones individuales (igual a la UX de Histórico pero server-side).
- [x] 2.4 Reusar `filesDeepLink` (helper Alpine o nueva URL helper) para los botones Ver/Editor/Archivos. Si el admin usa Blade server-side, generar URLs server-side:
  - Ver: `/files/{transcription_id}/preview?t={start_seconds}`
  - Editor: misma URL con `?clip=1`
  - Archivos: `/files/` (vista Mis Archivos del admin navegando a esa carpeta)
- [x] 2.5 Mantener el JSON endpoint `::matches(int $userId)`: ahora devuelve `{groups: [...], total: N, current_page: ..., last_page: ...}`. Acepta `?per_page=25,50,100`.
- [x] 2.6 Validar Playwright: login admin → abrir `ia/avisos-inteligentes/3` (Multiarchivo) → sección Matches debería mostrar datos reales (≥1 row agrupada), no vacío.

## 3. Fase 2 — Idempotencia per-(transcription, keyword)

- [x] 3.1 En `app/Services/Ia/KeywordMatcher.php::run()` (líneas 36-42), reemplazar:
```php
// ANTES
$already = DB::table('segment_keyword_hits')
    ->where('transcription_id', $transcription->id)
    ->exists();
if ($already) return 0;

// DESPUÉS
$existingKws = DB::table('segment_keyword_hits')
    ->where('transcription_id', $transcription->id)
    ->pluck('keyword_id')
    ->all();
$missingKws = $keywords->pluck('id')->diff($existingKws);
if ($missingKws->isEmpty()) return 0;  // todo escaneado
```
- [x] 3.2 Iterar `$missingKws` en lugar de `$keywordIdByNorm->keys()`. Saltar keywords ya procesadas.
- [x] 3.3 Validar en PHP CLI: simular una transcripción con hits existentes para keyword A; ejecutar el matcher con candidate keywords A+B; verificar que solo se insertan hits para B, no A.
- [x] 3.4 Validar que el `Log::info` final reporta `keywords_scanned: 1` (solo el nuevo) en lugar del total.

## 4. Fase 3 — Backfill artisan command + auto-backfill on creation

- [x] 4.1 En `app/Services/Ia/MentionBackfillService.php`: agregar método `backfill(Keyword $keyword): int` que:
  - Encuentra usuarios con `user_alerts_inteligentes.enabled = true` Y `transcription_access = true` en storages con segmentos.
  - Itera chunks de segmentos accesibles (`chunkById(1000)`).
  - Para cada chunk, llama el mismo algoritmo del matcher pero en bulk via JOIN+LIKE con PHP (más rápido que iterar).
  - Inserta chunks via `insertOrIgnore` con UNIQUE constraint.
  - Retorna total de hits insertados.
- [x] 4.2 Crear `app/Console/Commands/BackfillKeywordCommand.php`:
  - Signature: `mentions:backfill-keyword {--keyword=ID?} {--all?}`
  - Si `--keyword=ID`: backfill para esa keyword.
  - Si `--all`: itera todas las keywords con 0 hits en `segment_keyword_hits` y las backfilea.
  - Si ninguno: error explicativo.
  - Output: barra de progreso de cada keyword procesada + stats finales.
- [x] 4.3 Crear `app/Jobs/BackfillKeywordMatches.php` que llama `MentionBackfillService::backfill()` con la keyword recién creada. `onQueue('default')`.
- [x] 4.4 En `app/Http/Controllers/MisAvisosController.php::storeKeyword()` (post-creación exitosa): despachar `BackfillKeywordMatches::dispatch($keyword->id)` y devolver 201 con `queued_for_backfill: true`.
- [x] 4.5 Validar Playwright: cliente crea keyword "TEST_BACKFILL_xx" → en tiempo real no aparece nada en Histórico (porque el job es async). Después de ~30s hacer refresh y ver el histórico poblado para esa keyword.

## 5. Smoke tests (end-to-end)

- [x] 5.1 Verificar que `mentions:backfill-keyword --all` corre sin error en local y devuelve estadísticas razonables (≥100 hits retroactivos para keywords tipo "Petro" ya parcialmente indexadas; ≥0 retroactivos para keywords "AV Villas" que ahora se indexan).

- [x] 5.2 Verificar que Phase 1 NO rompe admin-user-detail en otros flujos: el JSON endpoint `/ia/avisos-inteligentes/{userId}/matches` debe responder 200 con la nueva estructura `{groups: [...]}`. Programas externos que consumían `[{id, ...}]` necesitan migración (documentar en CHANGELOG).

- [x] 5.3 Verificar Phase 2: crear una keyword nueva contra Multiarchivo's storage que ya tiene 200 transcripciones indexadas con "Petro"; las 200 nuevas scans no deben tocar "Petro".

## 6. Rollback

- [x] 6.1 Para revertir Fase 1: revertir commit; la vista vuelve al modelo legacy (vacío).
- [x] 6.2 Para revertir Fase 2: revertir commit; las keywords nuevas vuelven al estado "0 hits" hasta el próximo cron.
- [x] 6.3 Para revertir Fase 3: revertir commit; el cron de scan se mantiene sin auto-backfill. La keyword creada queda registrada pero sin retroactivar.

Ninguna fase toca schema; todas son revertibles sin migraciones hacia atrás.
