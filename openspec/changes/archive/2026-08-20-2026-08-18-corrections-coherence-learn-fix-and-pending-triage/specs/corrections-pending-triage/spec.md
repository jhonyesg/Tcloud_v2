# Spec: corrections-pending-triage (delta — will become new capability spec on archive)

## Purpose

Permite al admin aplicar triage en capas a las correcciones `pending` para separar las que son ruido (longitud excesiva, sin segmento origen, traducciones literales EN→ES, marcas) de las que son revisables, con opción de auto-aprobar solo las variantes ortográficas seguras bajo red de seguridad de undo.

## ADDED Requirements

### Requirement: Admin puede ejecutar triage en capas sobre las pending

El sistema SHALL permitir al admin autenticado en `/ia/correcciones` ejecutar un triage que aplique capas de descarte sobre las correcciones `pending` actuales, con reporte por capa y opción de aplicar (no solo dry-run).

El triage SHALL aplicar **al menos** estas capas, en este orden:

1. **Capa de longitud (invertida por feedback admin 2026-08-18)**: descartar las reglas cuyo `wrong_text` tenga **4 palabras o menos**. La razón es histórica (`2026-08-15-en-es-mix-miner-prune-open-strategy`): las reglas de 1-4 palabras son find/replace demasiado genérico que ignora contexto y produce espanglish cuando se aplican en frases distintas a la original. Solo reglas de **5 o más palabras** sobreviven esta capa, porque tienen suficiente contexto para preservar tono, intención y contexto (feedback explícito del admin). Sistema SHALL loggear cuántas se descartan por esta razón.
2. **Capa de contexto**: descartar las reglas cuyo `source_segment_id` sea NULL, indicando que el extractor no pudo enlazarlas con un segmento auditable. Sistema SHALL loggear cuántas.
3. **Capa de duplicado**: descartar las reglas cuyo `wrong_normalized` ya exista como `approved` con el mismo `wrong_normalized` (idempotencia ya existente, ahora usada como filtro masivo).
4. **Capa de marca / nombre propio**: descartar las reglas cuyo `wrong_text` active `LlmCorrectionSuggester::looksLikeBrandOrProperNoun()`. Sistema SHALL loggear cuántas.
5. **Capa de noise/clasificador**: descartar las reglas cuyo `EnEsRuleClassifier::classify()` retorne bucket `NOISE` o `QUARANTINE` (las traducciones EN→ES literales palabra-por-palabra que el change `2026-08-15-en-es-mix-miner-prune-open-strategy` excluyó del flujo automático).
6. **Capa de contexto recalentado**: para las reglas que sobreviven las 5 capas anteriores, ejecutar el `WarmCorrectionContext` para que el modal "Contexto del segmento" del admin abra instantáneo durante la revisión humana.

#### Scenario: Admin ejecuta triage en modo dry-run
- **WHEN** el admin hace POST a `/ia/correcciones/triage-pending` con body `{ "dry_run": true }`
- **THEN** el sistema ejecuta las 6 capas en modo lectura, retorna JSON `{ "run_id": "<uuid>", "layers": [{ "name": "...", "discarded": N, "reason": "..." }, ...], "survivors_for_review": N, "auto_approve_candidates": N }` y NO modifica ni borra ninguna fila.

#### Scenario: Admin ejecuta triage con auto-approve de variantes KEEP
- **WHEN** el admin hace POST a `/ia/correcciones/triage-pending` con body `{ "dry_run": false, "auto_approve_keep": true }`
- **THEN** el sistema ejecuta las 6 capas, y para las reglas sobrevivientes cuyo `EnEsRuleClassifier::classify()` retorne `KEEP` (variante ortográfica) crea un `CorrectionBulkAction` con `action='bulk_approve'` y snapshot por ítem, igual que el flujo `bulk_approve` existente. La respuesta incluye `bulk_action_id` y `undo_expires_at` (5 minutos).

#### Scenario: Admin deshace el auto-approve dentro de la ventana de undo
- **WHEN** el admin hace POST a `/ia/correcciones/undo/{bulkActionId}` dentro de los 5 minutos posteriores al triage
- **THEN** el sistema revierte todas las auto-aprobaciones al estado `pending` original usando el snapshot de `correction_bulk_action_items`, sin recalcular `applies_count` (mismo non-goal que el flujo bulk existente).

#### Scenario: Admin intenta deshacer fuera de la ventana de undo
- **WHEN** han pasado más de 5 minutos desde el auto-approve del triage
- **THEN** el sistema responde 410 Gone con `{ "error": "undo_window_expired" }` y NO revierte nada.

#### Scenario: Triage se ejecuta mientras ya hay otra corrida activa
- **WHEN** ya existe una entrada en cache `corrections_apply:active` apuntando a un run sano (queued/running)
- **THEN** el sistema responde 409 con el `runId` vigente (mismo patrón que `applyRetroactive`) para que la UI se re-adjunte en vez de lanzar un proceso paralelo.

#### Scenario: Triage descarta todo y no queda nada para revisar
- **WHEN** las 6 capas descartan todas las pending actuales
- **THEN** el sistema retorna `survivors_for_review: 0` con código 200, la UI muestra el reporte por capa sin error, y NO crea ningún bulk action (no hay auto-aprove posible).

---

### Requirement: El triage produce un reporte por capa visible al admin

El sistema SHALL presentar al admin en `/ia/correcciones` el reporte del último triage ejecutado: nombre de la capa, cantidad descartada, razón principal de descarte, conteo acumulado de supervivientes. El reporte SHALL ser exportable a CSV (mismo formato que `/ia/correcciones/export` existente) para revisión offline.

#### Scenario: Admin abre el modal después del triage
- **WHEN** el admin abre el modal "Triage pendientes" en `/ia/correcciones` después de una corrida completada
- **THEN** la UI muestra una tabla por capa con `descartadas / razón / supervivientes_acumulados` y un botón "Descargar CSV" que genera un CSV con las reglas supervivientes, su `wrong_text`, `correct_text`, `risk_level`, `source`, `source_segment_id`, `applies_count`, y el snippet de contexto recalentado.

#### Scenario: Reporte muestra el contador de auto-aprobadas con undo activo
- **WHEN** el triage se ejecutó con `auto_approve_keep=true` y aún no han pasado 5 minutos
- **THEN** la UI muestra el toast inferior izquierdo con `auto_aprobadas: N / deshacer hasta HH:MM` vinculado al `bulk_action_id`.

---

### Requirement: El comando `corrections:triage-pending` puede correr vía CLI standalone

El sistema SHALL exponer el comando artisan `corrections:triage-pending` con flags `--dry-run`, `--apply`, `--auto-approve-keep`, `--max=10000` (tope de candidatas para no saturar), `--days=N` (limitar a pending creadas en últimos N días, default 0 = todas) para que el admin pueda correrlo fuera de la UI y pipelinearlo.

#### Scenario: Admin corre triage en CLI con auto-aprove
- **WHEN** el admin ejecuta `php artisan corrections:triage-pending --apply --auto-approve-keep` en el shell del servidor
- **THEN** el comando aplica las 6 capas, imprime el reporte por capa en stdout, crea el `CorrectionBulkAction` con undo de 5 min, y termina con exit code 0. La respuesta JSON del endpoint NO se genera (no fue invocado), pero el bulk action queda registrado con `origin='cli'` para auditoría.

#### Scenario: CLI aborta por tope de candidatas
- **WHEN** el admin ejecuta el comando con `--max=100` y hay 6.099 pending
- **THEN** el comando procesa solo las primeras 100 (ordenadas por `id` DESC, las más recientes primero), avisa por stderr que el tope se aplicó, y termina con exit code 0. Las 5.999 restantes se procesan en una siguiente corrida.
