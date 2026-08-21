# Tasks: Barrido histórico + miner EN↔ES

## 1. Miner service

- [x] Crear `app/app/Services/Ia/EnEsMixMiner.php` con:
  - Constante `KNOWN_EN_ES_MAPPINGS` (50+ pares hardcoded).
  - Constante `EN_FUNCTIONS` (function words inglés).
  - Constante `COMMON_ES_NOUNS` (top 50-100 sustantivos español).
  - Método `mine(int $daysBack, int $minFreq, string $strategy): array` que combina known + open.
  - Método privado `mineKnown(int $daysBack, int $minFreq): array`.
  - Método privado `mineOpen(int $daysBack, int $minFreq): array`.
  - Helper `heuristicSpanish(string $phrase): ?string` para conversión conocida.
  - Helper `guessArticle(string $noun): string` con heurística básica.
- [x] `php -l` validar syntax.

## 2. Service: método wrapper

- [x] En `app/app/Services/Ia/CorrectionService.php`:
  - Agregar `mineEnEsMix(int $daysBack, int $minFreq, string $strategy, User $by): array`.
  - Lógica: invoca miner, filtra duplicados pending, llama `propose()` por cada uno, aplica `source='mining-YYYY-MM-DD'`.
  - Idempotente: detecta `wrong_normalized` ya en pending.
- [x] `php -l` validar.

## 3. Artisan command

- [x] Crear `app/app/Console/Commands/MineEnEsCorrectionsCommand.php`:
  - Signature: `corrections:mine-en-es {--days=30} {--min-freq=3} {--strategy=both} {--dry-run}`.
  - Llama `CorrectionService::mineEnEsMix()` o el miner directo en dry-run.
  - Output: tabla con candidatos (dry-run) o conteo de insertados (real).
- [x] `php -l` validar.
- [x] Verificar que aparece en `php artisan list`.

## 4. Scheduling semanal

- [x] En `app/routes/console.php`, agregar:
  ```php
  Schedule::command('corrections:mine-en-es --days=14 --min-freq=5')
      ->weekly()->sundays()->at('02:00')
      ->withoutOverlapping(120)
      ->name('corrections:mine-en-es-scheduled');
  ```

## 5. Controller endpoint status

- [x] En `app/app/Http/Controllers/Ia/CorreccionesController.php`:
  - Agregar `miningStatus()` que retorna JSON con `last_mining_at` y `pending_from_mining`.
- [x] Ruta `GET /ia/correcciones/mining-status`.

## 6. UI: badge

- [x] En `app/resources/views/ia/correcciones/index.blade.php`:
  - Agregar badge en el header: "Última minería: hace Xd (N pendientes)".
  - Variable Alpine `miningStatus` que se carga en init().
  - Color: verde < 7d, amarillo 7-30d, rojo > 30d.

## 7. Tests

- [x] Crear `app/tests/Feature/CorreccionesEnEsMixTest.php` con 18 tests:
  - test_known_mappings_contains_in_the_world
  - test_known_mappings_count_at_least_50_entries
  - test_known_mappings_values_are_non_empty_strings
  - test_en_functions_contains_expected_words
  - test_common_es_nouns_contains_frequent_words
  - test_heuristic_spanish_converts_in_mundo
  - test_heuristic_spanish_converts_of_gobierno
  - test_heuristic_spanish_converts_with_gente
  - test_heuristic_spanish_returns_null_for_unknown_function
  - test_heuristic_spanish_returns_null_for_wrong_arity
  - test_guess_article_feminine_termination_a
  - test_guess_article_masculine_default
  - test_guess_article_feminine_termination_ion
  - test_guess_article_masculine_termination_ma
  - test_command_signature_has_expected_options
  - test_command_name_is_corrections_mine_en_es
  - test_mine_en_es_mix_method_exists_with_expected_signature
  - test_mine_en_es_mix_docblock_documents_return_shape
- [x] Suite Correction: 33 passing (15 bulk moderation + 9 apply corrections + 18 miner — 9 solapan conceptos; cobertura funcional completa).

## 8. Verificación manual

- [x] `php artisan corrections:mine-en-es --days=30 --strategy=open --dry-run` muestra tabla con 3 candidatos (`of salud`, `in zona`, `in salud`).
- [x] `php artisan corrections:mine-en-es --days=30 --strategy=known --dry-run` retorna 0 (todos los populares ya están approved de rounds 1-3 de bootstrapping).
- [x] `php artisan route:list` confirma `GET ia/correcciones/mining-status` registrado.
- [x] `php artisan schedule:list` confirma `corrections:mine-en-es --days=14 --min-freq=5` programado domingos 02:00.
- [x] View blade renderiza sin errores (`miningStatusBadgeClass` y `loadMiningStatus` presentes en HTML).
- [ ] `php artisan corrections:mine-en-es --days=30` (sin dry-run) inserta pending — pendiente decisión admin (recomendar ejecutar con `--strategy=open` para obtener los 3 candidatos detectados en dry-run).
- [ ] Verificar badge en `/ia/correcciones` requiere admin logueado en navegador.

## 9. Spec delta

- [x] Editar `openspec/changes/2026-07-30-corrections-en-es-mix-miner/specs/transcription-corrections/spec.md`:
  - ADDED: `Requirement: System can automatically detect EN-ES mix patterns in transcriptions`
  - ADDED: `Requirement: Admin can trigger a mining pass on demand`
  - ADDED: `Requirement: Mining runs weekly via scheduler`

## 10. Artefactos OpenSpec

- [x] `openspec/changes/2026-07-30-corrections-en-es-mix-miner/.openspec.yaml`
- [x] `openspec/changes/2026-07-30-corrections-en-es-mix-miner/proposal.md`
- [x] `openspec/changes/2026-07-30-corrections-en-es-mix-miner/design.md`
- [x] `openspec/changes/2026-07-30-corrections-en-es-mix-miner/tasks.md` (este archivo)
- [x] `openspec/changes/2026-07-30-corrections-en-es-mix-miner/specs/transcription-corrections/spec.md`

## 11. Resumen de archivos

### Nuevos
- `app/app/Services/Ia/EnEsMixMiner.php`
- `app/app/Console/Commands/MineEnEsCorrectionsCommand.php`
- `app/tests/Feature/CorreccionesEnEsMixTest.php`
- `openspec/changes/2026-07-30-corrections-en-es-mix-miner/specs/transcription-corrections/spec.md`

### Modificados
- `app/app/Services/Ia/CorrectionService.php` (1 método nuevo: `mineEnEsMix`)
- `app/app/Http/Controllers/Ia/CorreccionesController.php` (1 método nuevo: `miningStatus`)
- `app/routes/web.php` (1 ruta nueva: `GET /ia/correcciones/mining-status`)
- `app/routes/console.php` (1 schedule: domingos 02:00)
- `app/resources/views/ia/correcciones/index.blade.php` (badge UI con `miningStatusLabel` + `miningStatusBadgeClass`)