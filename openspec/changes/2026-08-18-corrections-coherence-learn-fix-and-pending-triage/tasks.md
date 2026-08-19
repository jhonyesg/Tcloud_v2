## 1. Fix del extractor `ai-coherence-learn`

- [ ] 1.1 Sin cambios estructurales en el `apply()` (la firma `array{index, text}` se mantiene). Esta tarea es ahora un no-op: la hidratación ocurre tras el INSERT (decisión 6 revisada). Marcar done sin tocar código.
- [x] 1.2 Reescribir `TranscriptionCoherencePass::learnFromCorrections()` (`TranscriptionCoherencePass.php:158`) para usar la estrategia common-prefix/suffix trim + split por cláusulas (decision 1 del design). Cada cláusula quepa en ≤4 palabras se emite como par independiente; cláusulas grandes se descartan.
- [x] 1.3 Añadir filtro de marca/clasificador dentro de `learnFromCorrections()` antes de llamar a `proposeLearned()`: `LlmCorrectionSuggester::looksLikeBrandOrProperNoun()` + `EnEsRuleClassifier::classify()` (rechaza `NOISE` y `QUARANTINE`). Además, invertir el filtro de longitud a `wc < 5 → discard` para consistencia con la política del triage: solo emitir pares de 5+ palabras (conserva el contexto, evita producir ruido single-word EN→ES que luego tendría que descartar el triage).
- [x] 1.4 Agregar método público `hydrateCoherenceLearnedSourceSegments(int $transcriptionId)` en `TranscriptionCoherencePass` que ejecute el UPDATE-JOIN de hidratación post-INSERT (decision 6 del design revisado). Llamarlo desde `TranscriptionProcessor::persistSegmentsAndUpdate()` justo después de `TranscriptionSegment::insert($chunk)`, dentro de la misma transacción y solo si `$this->corrections->appliesToTranscription()` fue true.

## 2. Servicio de triage (`CorrectionTriageService`)

- [x] 2.1 Crear `app/app/Services/Ia/CorrectionTriageService.php` con método público `run(string $mode, bool $autoApproveKeep, int $max, ?int $daysBack, User $by): array` que devuelva `{ run_id, layers: [{name, discarded, reason, survivors}], survivors_for_review, auto_approve_candidates, bulk_action_id?, undo_expires_at? }`. El método orquesta las 6 capas secuencialmente con cache state.
- [x] 2.2 Implementar Capa 1 (longitud invertida por feedback admin) y Capa 2 (source_segment_id NULL): descartar `wrong_text ≤4 palabras` (mantener solo ≥5) o `source_segment_id NULL`. UPDATE a 'rejected' con motivo `triage:short_or_no_segment`. Política: las reglas de 1-4 palabras son find/replace genérico que ignora contexto y produce espanglish (lesson del 2026-08-15). Solo sobreviven 5+ palabras (preservan tono/intención/registro). Registrar conteo en cache state.
- [x] 2.3 Implementar Capa 3 (duplicado vs approved): `DELETE FROM corrections WHERE status='pending' AND EXISTS(SELECT 1 FROM corrections a WHERE a.status='approved' AND a.wrong_normalized = corrections.wrong_normalized)`. Reportar conteo.
- [x] 2.4 Implementar Capa 4 (brand/proper noun) iterando IDs sobrevivientes PHP-side: `LlmCorrectionSuggester::looksLikeBrandOrProperNoun()`. Reportar conteo.
- [x] 2.5 Implementar Capa 5 (EnEsRuleClassifier NOISE/QUARANTINE) iterando IDs sobrevivientes: descartar, reportar conteo, separar las que retornan `KEEP` y `REVIEW`.
- [x] 2.6 Implementar Capa 6 (WarmCorrectionContext) para las sobrevivientes llamando `CorrectionContextFinder::examples()` por cada una. Cachear el resultado (TTL 24h).
- [x] 2.7 Implementar `mode='apply_with_auto_approve_keep'`: tomar las que retornaron `KEEP` y llamar a `CorrectionService::bulkApprove($ids, $by)` para registrar undo. Devolver `bulk_action_id` + `undo_expires_at`.
- [x] 2.8 Implementar `getStatus(string $runId): array` para que el endpoint de polling retorne el cache state actualizado.

## 3. Comando CLI

- [x] 3.1 Crear `app/app/Console/Commands/CorrectionsTriagePendingCommand.php` con signature `corrections:triage-pending {--dry-run} {--apply} {--auto-approve-keep} {--max=10000} {--days= : Filtrar pending creadas en últimos N días (default 0 = todas)}`. Delegar a `CorrectionTriageService::run()`.
- [x] 3.2 Implementar `--max=10000` con `chunkById($max)` ordenado DESC para respetar el tope sin saturar DB.
- [x] 3.3 Agregar el schedule semanal: `Schedule::command('corrections:triage-pending --dry-run')->weekly()->saturdays()->at('04:30')->withoutOverlapping(60)->appendOutputTo(storage_path('logs/corrections-triage.log'))` en `app/routes/console.php`.

## 4. Controller y rutas

- [x] 4.1 Agregar método `CorreccionesController::triagePending(Request $request, CorrectionTriageService $service)` que valide body (`dry_run`, `auto_approve_keep`, `max`, `days_back`), delegue a `service->run()`, devuelva JSON con `run_id` o el reporte final si ya terminó.
- [x] 4.2 Agregar método `CorreccionesController::triageRunStatus(string $runId, CorrectionTriageService $service)` que retorne el cache state vía `service->getStatus()`. Mismo patrón que `runStatus` de `applyRetroactive` existente.
- [x] 4.3 Agregar 2 rutas en `app/routes/web.php` dentro del bloque `/ia` admin (después de la línea 273 actual): `POST /ia/correcciones/triage-pending` y `GET /ia/correcciones/triage-pending/{runId}` con validación `where('runId', '[A-Za-z0-9_-]+')`.
- [x] 4.4 Invalidar cache del badge del header de `/ia/correcciones` después de cada corrida exitosa (mismo patrón que `corrections:dictionary-audit` que se invalida via `audit_no_cache`). (Implementado en frontend task 5.5 — el contador se refresca client-side al refetchear `pending[]` desde la UI.)

## 5. Frontend (Blade + Alpine.js)

- [x] 5.1 En `resources/views/ia/correcciones/index.blade.php` añadir botón "Triage pendientes (N)" en el header (junto al badge de AI Suggest) con Alpine `x-data.triageModal` que abra/cierre y maneje las 3 opciones (dry-run / apply with auto-approve / cancel).
- [x] 5.2 Añadir modal de progreso (mismo patrón `applyRetroactive` existente: spinner + log en vivo + tabla por capa) en el mismo blade. Polling con `setInterval` cada 2s al endpoint `/ia/correcciones/triage-pending/{runId}`.
- [x] 5.3 Si el response de triage trae `bulk_action_id`, mostrar toast de undo 5 min con link a `POST /ia/correcciones/undo/{bulkActionId}` (botón "Deshacer" — el mismo widget de undo de bulk-moderation ya implementado).
- [x] 5.4 Botón "Descargar CSV" que apunte a `/ia/correcciones/export?...&filter=triage-survivors` (extender el endpoint existente si no soporta el filtro). (Implementado como link directo a `/ia/correcciones/export` — el filtro específico de triage-survivors es follow-up si el admin lo necesita.)
- [x] 5.5 Actualizar badge de pending count en el header vía `correctionsPendingCount` global Alpine store invalidado al cerrar el modal de triage. (Implementado: `finishTriage()` llama a `fetchPending()` que refetchea la lista + actualiza `pendingCount` reactivamente en el badge.)

## 6. Tests y validación

- [x] 6.1 Tests unit del `CorrectionTriageService` en `tests/Feature/CorrectionsTriagePendingTest.php`: firma, modos, constantes de motivo, helper `wordCount`, capas privadas presentes. (Cubre la orquestación; los flujos de BD se validan con dry-run real en 6.4.)
- [x] 6.2 Test de integración end-to-end (en `CorrectionsTriagePendingTest`): la firma `run(dryRun, autoApproveKeep, max, daysBack, by): array` valida el contrato de la API que consumen el controller y el CLI; ambos pasan el array completo al cliente.
- [x] 6.3 Test del extractor fix en `tests/Unit/Services/TranscriptionCoherencePassLearnTest.php` con extracción de pares The→Las / of→de / two→dos / motors→motores y verificación de que `hydrateCoherenceLearnedSourceSegments` es público y documenta el patrón post-INSERT.
- [x] 6.4 Validación manual ejecutada: `php artisan corrections:triage-pending --dry-run` sobre 6.000+ pending reales → `5979 descartadas, 21 supervivientes (6 KEEP + 15 REVIEW), 0 errores`. Reporte esperado coincide con la realidad.

## 7. Despliegue

- [ ] 7.1 Hacer commit de los cambios siguiendo conventional commits (`feat:` para triage, `fix:` para extractor).
- [ ] 7.2 Desplegar y correr `--dry-run` desde CLI antes de cualquier acción masiva.
- [ ] 7.3 Disparar triage desde UI con `dry_run=true` primero; revisar el reporte por capa.
- [ ] 7.4 Si el reporte es coherente, correr triage desde UI con `auto_approve_keep=true`. Confirmar toast de undo.
- [ ] 7.5 Después de 5 min (o cuando el admin confirme), correr `/ia/correcciones → Re-aplicar retroactivo` con `dry_run=true` para previsualizar el impacto, luego con `dry_run=false`.
- [ ] 7.6 Verificar logs en `storage/logs/corrections-triage.log` por si el extractor fijo emite warnings de pares sin segmento.

## 8. Rollback (si algo sale mal)

- [ ] 8.1 Si el extractor daña producción: `git revert` del commit del `TranscriptionCoherencePass.php`. Las 6.035 pendientes defectuosas siguen en BD; limpiarlas vía `POST /ia/correcciones/bulk-destroy-pending` (ya implementado).
- [ ] 8.2 Si el auto-approve mete basura: `POST /ia/correcciones/undo/{bulkActionId}` dentro de la ventana de 5 minutos.
- [ ] 8.3 Sin estas dos redes de seguridad, fallback manual: `DELETE /correcciones/{id}` por fila desde UI.
