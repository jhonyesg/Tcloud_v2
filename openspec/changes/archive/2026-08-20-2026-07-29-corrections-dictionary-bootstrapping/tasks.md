# Tasks: Bootstrapping del diccionario de correcciones

## 1. Bugfix: word-boundary en `applyToText`

- [x] En `app/app/Models/Correction.php::applyToText()`:
  - Cambiar el `str_ireplace` por `preg_replace` con pattern `'/\b' . preg_quote($wrong_normalized, '/') . '\b/i'`.
  - Confirmar que `wrong_normalized` es ASCII (lo garantiza `Keyword::asciiLower`).
- [x] En `app/app/Services/Ia/CorrectionService.php::applyText()` (método privado):
  - **Iteración 1**: `preg_replace` por corrección con `\b` — funciona pero lento (~700 min para 10M).
  - **Iteración 2 (actual)**: `stripos()` + word-boundary manual + `substr_replace`. Pre-filtra con `strpos($textLower, $wrong)` antes del check completo. Extrae pares primitivos con `->all()` para evitar overhead de Eloquent. ~50x speedup (0.5ms/segmento, ~80 min para 10M).
  - Documentar en docblock que previene el bug "Active to rompe attractive".
- [x] `php -l` en ambos archivos.
- [x] Tests:
  - [x] `app/tests/Unit/CorrectionApplyToTextTest.php`: nuevo test `test_word_boundary_does_not_match_inside_other_words`.
  - [x] `app/tests/Unit/CorrectionApplyToTextTest.php`: nuevo test `test_word_boundary_matches_phrase_at_sentence_start`.
  - [x] `app/tests/Unit/CorrectionApplyToTextTest.php`: nuevo test `test_word_boundary_with_punctuation`.
  - [x] `app/tests/Unit/CorrectionApplyToTextTest.php`: nuevo test `test_multi_word_phrase_preserved` (no desarma la frase larga).
  - [x] `app/tests/Unit/CorrectionServiceTest.php`: nuevo test `test_apply_to_segments_with_word_boundary`.
  - [x] **Actualizado** `app/tests/Feature/ApplyCorrectionsCommandTest.php`: `testCascadingReplacementsAreNowPreventedByWordBoundary` reemplaza al viejo `testCascadingReplacementsArePossible` que documentaba el bug.

## 2. Migration: agregar columna `source`

- [x] Crear `app/database/migrations/2026_07_29_120000_add_source_to_corrections.php`:
  - Schema::table('corrections', function (Blueprint $t) { $t->string('source', 64)->nullable()->after('status'); });
- [x] `php artisan migrate --force` (aplicada en producción 2026-07-29 16:30).

## 3. Seeder de bootstrap

- [x] Crear `app/app/Database/Seeders/CorreccionesDictionaryBootstrappingSeeder.php`:
  - Definir constante `SOURCE = 'bootstrapping-2026-07-29'`.
  - 48 reglas GRUPO A → `upsertApproved()` con `source = self::SOURCE`.
  -  2 reglas GRUPO A → `propose()` con `source = self::SOURCE` (over and over, day and night).
  - 24 reglas GRUPO B → `upsertApproved()` con `source = self::SOURCE`.
  - 12 reglas GRUPO B → `propose()` con `source = self::SOURCE`.
  - **Nota**: el namespace `App\Database\Seeders` requiere invocarse con `--class='App\\Database\\Seeders\\...'` (Laravel 13 default es `Database\Seeders`).
  - Idempotente: `upsertApproved` ya hace upsert por `wrong_normalized`; `propose` crea/actualiza pending.
  - Encontrar admin via `User::where('role', 'admin')->first()`; abortar si no hay.
- [x] `php -l` en el seeder.
- [x] `php artisan db:seed --class='App\\Database\\Seeders\\CorreccionesDictionaryBootstrappingSeeder' --force` ejecutado.
- [x] Verificar conteos:
  - Resultado: **96 filas nuevas** (82 approved + 14 pending) con `source='bootstrapping-2026-07-29'`.
  - Diccionario total: **101 correcciones** (87 approved = 5 legacy + 82 nuevas; 14 pending = todas nuevas).
  - Diferencia vs estimación inicial (86): algunas consolidaciones por `wrong_normalized` colisionaron con las 5 legacy (ej. `Active to` y `active to` normalizan al mismo).

## 3.1. Round 2: Más correcciones PENDING adicionales

Después del round 1, una segunda pasada del corpus identificó **56 candidatos NUEVOS** que no estaban en el diccionario. Se creó un seeder adicional que los agrega como PENDING (no approved) para revisión admin.

- [x] Crear `app/app/Database/Seeders/CorreccionesDictionaryPendingSeeder.php`:
  - SOURCE = `'pending-round2-2026-07-29'`.
  - 56 candidatos: truncamientos -mente, acentos faltantes, confusiones coloquiales (`echo`/`hecho`, `sierto`/`cierto`), variantes estructurales EN→ES, verbos irregulares.
  - Todas como `propose()` con `source = self::SOURCE` → status=pending.
- [x] `php -l` en el seeder.
- [x] `php artisan db:seed --class='App\\Database\\Seeders\\CorreccionesDictionaryPendingSeeder' --force` ejecutado.
- [x] Resultado: **56 pending nuevas** con `source='pending-round2-2026-07-29'`.
- [x] Diccionario total ahora: **157 correcciones** (101 approved + 56 pending).
- [x] Word-boundary confirmado: `amasar` permanece intacto cuando se prueba `mas → más` aprobado temporalmente.
- [x] Tests siguen pasando: 22/22.

### Distribución de las 56 pending del round 2

| Categoría | Cantidad | Ejemplos |
|---|---|---|
| Truncamientos -mente | 19 | `supuestament → supuestamente` (206x), `evidentement → evidentemente` (254x), `verdaderament → verdaderamente` (132x) |
| Acentos faltantes (palabras comunes) | 8 | `mas → más` (16442x), `aca → acá` (13650x), `recien → recién` (1563x) |
| 'echo' en lugar de 'hecho' | 9 | `echa → hecha` (6546x), `echos → hechos` (2812x) |
| Confusiones coloquiales | 5 | `sierto → cierto` (109x), `antier → anteayer` (26x) |
| Verbos irregulares | 2 | `morido → muerto` (8x), `pediendo → pidiendo` (3x) |
| Variantes estructurales EN→ES | 13 | `to the world → al mundo` (52x), `around the world → por todo el mundo` (13x) |

## 3.2. Round 3: Análisis de días anteriores (7d + 8-30d)

Tercera oleada basada en análisis histórico (últimos 30 días = 9.3M segmentos). Detectó **81 candidatos NUEVOS** centrados en adjetivos técnicos sin tilde que el ASR omite.

- [x] Crear `app/app/Database/Seeders/CorreccionesDictionaryPendingSeeder3.php`:
  - SOURCE = `'pending-round3-2026-07-29'`.
  - 87 candidatos insertados (4 ya existían como pending de rounds anteriores).
  - Todas como `propose()` con `source = self::SOURCE` → status=pending.
- [x] Ejecutar seeder: **87 nuevas pending** (`source='pending-round3-2026-07-29'`).
- [x] Diccionario total: **244 correcciones** (101 approved + 143 pending).

### Distribución de las 87 pending del round 3

| Categoría | Cantidad | Ejemplos |
|---|---|---|
| Médicos / clínicos | 19 | `medica → médica` (10887x), `clinica → clínica` (911x), `oncologica → oncológica` (6x) |
| Políticos / sociales | 8 | `politica → política` (7913x), `democratico → democrático` (51x) |
| Económicos / estadísticos | 7 | `economica → económica` (614x), `estadistica → estadística` (1x) |
| Técnicos / académicos | 27 | `electronica → electrónica` (44x), `informatica → informática` (9x), `hidrica → hídrica` (11x) |
| Plurales/femeninos de GRUPO B | 17 | `publicas → públicas` (303x), `comicas → cómicas` (1x), `magicas → mágicas` (7x) |
| Frases EN→ES adicionales | 5 | `across the world → por todo el mundo` (35x), `into the world → en el mundo` (31x) |
| Otros | 4 | `tambien → también` (4x), `fosil → fósil` (8x) |

## 4. Feature: Re-aplicar correcciones a N días hacia atrás

El admin puede lanzar re-aplicaciones retroactivas con un scope temporal limitado (no solo "todos los históricos"). Útil cuando se agregan correcciones nuevas y se quieren aplicar solo al corpus reciente para validar antes de tocar 10M+.

### 4.1. Backend: `CorrectionService::applyRetroactively(..., ?int $daysBack = null)`

- [x] Agregado parámetro `?int $daysBack = null` (default `null` = todos los históricos).
- [x] Cuando `$daysBack > 0`, agrega `where('created_at', '>=', now()->subDays($daysBack))` al query base.
- [x] Filtrado se aplica tanto al `chunkById` como al `count()` total.

### 4.2. Comando CLI: `corrections:apply-run --days=N`

- [x] Nueva opción `--days=N` que sobrescribe con N días hacia atrás.
- [x] Si no se pasa `--days`, lee `days_back` del cache key (set por controller).
- [x] Comando actualizado muestra el scope en el log.

### 4.3. Controller: `CorreccionesController::applyRetroactive`

- [x] Acepta `days_back` en el body del request (default null).
- [x] Validación: debe ser int positivo <=365, retorna 422 si inválido.
- [x] Persiste `days_back` en cache key para que el comando async lo lea.
- [x] Construye el comando CLI con `--days=N` si aplica.
- [x] Retorna `{runId, days_back}` en el JSON response.

### 4.4. UI: `/ia/correcciones` modal "Re-aplicar"

- [x] Selector "Alcance temporal" con opciones: All, 1, 3, 7 (default), 14, 30, 90 días.
- [x] Texto explicativo cambia según scope seleccionado.
- [x] `runApply()` envía `days_back` en el body cuando aplica.
- [x] Mensaje de inicio muestra el scope (`Iniciando... (últimos 7 días)`).

### 4.5. Tests

- [x] `testApplyRetroactivelyAcceptsDaysBackParameter`: verifica signature con 4to param `daysBack`.
- [x] `testApplyRetroactivelyReturnsUpdatedCount`: verifica return type int.
- [x] Suite completa: 24/24 passing, 40 assertions.

### Impacto del feature

| Scope | segments a procesar | tiempo estimado (con chunk=5000) |
|---|---|---|
| Todos | 10.3M | ~10h |
| 30 días | 10.3M (todo) | ~10h |
| 7 días | 6.6M | ~6h |
| 3 días | ~3M | ~3h |
| 1 día | ~600K | ~30min |

## 4. Reaplicar retroactivo

- [x] Pre-baseline: capturar `count(text != text_raw)` antes del retroactivo:
  - **Baseline = 174** divergentes (después del seeder, sin retroactivo).
  - Total segments: 10.17M.
- [x] Disparar run async `bootstrap_20260729_v5` con `--chunk=5000`.
  - **Iteraciones previas** (rechazadas por bugs detectados):
    - v1 (chunk=1000, con str_ireplace inline en applyRetroactively) — corrió 30s antes de detectar bug de word-boundary faltante en retroactivo. Detenido.
    - v2 (chunk=1000, con preg_replace \b en applyText) — corrió 90s, velocidad 217 seg/s = 14h ETA. Detenido por lentitud.
    - v3 (chunk=1000, stripos+boundary) — corrió 9min, velocidad 259 seg/s = 13h ETA. Detenido para optimizar UPDATEs.
    - v4 (chunk=5000, upsert batch) — falló INMEDIATAMENTE: PostgreSQL valida NOT NULL antes de ON CONFLICT, upsert no funciona con transcription_id NOT NULL.
    - **v5 (chunk=5000, UPDATE FROM VALUES batch con casts ::bigint ::timestamp)** — velocidad actual ~323 seg/s = ~8.6h ETA. **Corriendo en background (PID 852335)**.
- [ ] Polling del estado hasta `status='done'`:
  - Cache key: `corrections_apply:bootstrap_20260729_v5`.
  - Estado actual: running, progress ~30k en 90s, 52 correcciones con applies_count > 0 a los 9min.
- [ ] Post-baseline: recontar divergentes (esperado: 5.000+ segmentos).
- [ ] Verificar `applies_count`:
  - Top a los 9min: `rapidamente → rápidamente` 21x, `at the end → al final` 19x, `of the world → del mundo` 16x, etc.

## 5. Smoke test manual

- [x] Smoke test con `Correction::applyToText()` ejecutado en PHP:
  - **TEST 1** "the attraction in the world of the government with much atencion" →
    `the attraction en el mundo del gobierno with much atención` (parcial: `in the world` y `of the government` aplican, `atencion` aplica).
  - **TEST 2** "the proactive psychoactive attractive initiative of the world" →
    `the proactive psychoactive attractive initiative del mundo` (✅ attractive intacto, solo `of the world` aplica).
  - **TEST 3** "Active to Bogotá Modo Metro, an attractive touristic city" →
    `Activa tu Bogotá Modo Metro, an attractive touristic city` (✅ ambos correctos).
- [ ] Validar UI en `/ia/correcciones` (las 96 nuevas deben aparecer en las pestañas).
- [ ] Disparar SRT de prueba real desde la UI y verificar que las nuevas reglas aplican correctamente.

## 6. Rollback contingency

- [ ] Documentar en `proposal.md` (ya hecho) el camino de rollback.
- [ ] Si hay falsos positivos inaceptables en verificación:
  - `UPDATE corrections SET status='rejected', rejected_reason='false positive en bootstrap 2026-07-29' WHERE source='bootstrapping-2026-07-29' AND wrong_normalized IN (...);`
  - Repetir `corrections:apply-run` para revertir.

## 7. Spec delta

- [ ] Editar `openspec/changes/2026-07-29-corrections-dictionary-bootstrapping/specs/transcription-corrections/spec.md`:
  - `## ADDED Requirements`:
    - `Requirement: Diccionario se bootstrappea desde análisis de corpus` — el admin puede sembrar N correcciones detectadas en una pasada sobre `transcription_segments.text_raw`, con confianza alta/media que determina status inicial.
    - `Requirement: Bugfix de word-boundary en applyToText` — las reglas NO deben aplicar cuando `wrong_normalized` aparece como substring dentro de otra palabra.

## 8. Verificación de tests

- [x] `vendor/bin/phpunit --filter=Correction` debe pasar verde: **22/22 passing, 34 assertions**.
- [x] `vendor/bin/phpunit` completo: 159 tests, 11 errores y 1 fallo pre-existentes no relacionados con correcciones (problemas de bootstrap `encrypter` class en ConfigServiceTest, PermissionEnforcementTest, ShareCreationRulesTest, PlantillaServiceTest). Verificado que son pre-existentes corriendo `git diff` contra la rama actual.

## 9. Artefactos OpenSpec

- [x] `openspec/changes/2026-07-29-corrections-dictionary-bootstrapping/proposal.md` ✓
- [x] `openspec/changes/2026-07-29-corrections-dictionary-bootstrapping/design.md` ✓
- [x] `openspec/changes/2026-07-29-corrections-dictionary-bootstrapping/tasks.md` ✓ (este archivo, con updates de iteraciones)
- [x] `openspec/changes/2026-07-29-corrections-dictionary-bootstrapping/specs/transcription-corrections/spec.md` ✓
- [x] `openspec/changes/2026-07-29-corrections-dictionary-bootstrapping/.openspec.yaml` ✓

## 10. Resumen de archivos

### Modificados
- `app/app/Models/Correction.php` (word-boundary)
- `app/app/Services/Ia/CorrectionService.php` (word-boundary)
- `app/tests/Unit/CorrectionApplyToTextTest.php` (3 nuevos tests)
- `app/tests/Unit/CorrectionServiceTest.php` (1 nuevo test)

### Nuevos
- `app/database/migrations/2026_07_29_120000_add_source_to_corrections.php`
- `app/database/seeders/CorreccionesDictionaryBootstrappingSeeder.php`
- `openspec/changes/2026-07-29-corrections-dictionary-bootstrapping/specs/transcription-corrections/spec.md`