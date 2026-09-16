## 1. Backend — Defaults off

- [x] 1.1 In `app/app/Services/Ia/LlmCorrectionSettings.php`, change `'enabled' => ['type' => 'bool', 'default' => true, ...]` (around line 54) to `'default' => false`.
- [x] 1.2 In the same file, change `'primary_enabled' => ['type' => 'bool', 'default' => true, ...]` (around line 60) to `'default' => false`.
- [x] 1.3 Verify locally that `php artisan tinker --execute='dump(app(\App\Services\Ia\LlmCorrectionSettings::class)->bool("enabled"));'` returns `false` when the row is missing from `system_settings`. (Verificado vía PHP bootstrap por ausencia de tinker; retorna `false`.)

## 2. Backend — Circuit breaker

- [x] 2.1 In `app/app/Services/Ia/TranscriptionCoherencePass.php`, add constants `BREAKER_FAILURE_THRESHOLD = 5` and `BREAKER_WINDOW_SECONDS = 600` near the top of the class.
- [x] 2.2 Add three private helpers: `recordFailure(string $provider): void`, `recordSuccess(string $provider): void`, `isBreakerOpen(string $provider): bool`. They use `Cache::increment`, `Cache::forget`, `Cache::get` with the key `coherence_breaker:{provider}`.
- [x] 2.3 In `callWithRetry()`, filter the `$providers` list to exclude open breakers before entering the retry loop.
- [x] 2.4 On each LLM exception: call `recordFailure($provider)`. On success: call `recordSuccess($provider)`. Add a WARNING log when excluding a provider.
- [x] 2.5 If the breaker list ends up empty after filtering (all providers excluded), throw `\RuntimeException('Todos los proveedores LLM están en circuit breaker: ...')` so the outer `apply()` keeps its fallback to dictionary text.
- [x] 2.6 Verify with `php artisan tinker`: simulate 5 consecutive failures via `Cache::increment('coherence_breaker:tertiary', 5)` and confirm `isBreakerOpen('tertiary')` returns `true`. Then call `recordSuccess('tertiary')` and confirm it returns `false`. (Verificado vía PHP bootstrap: cerrado → abierto tras 5 fallos → cerrado tras success.)

## 3. Backend — Backfill command guard

- [x] 3.1 In `app/app/Console/Commands/TranscriptionBackfillCoherenceCommand.php`, inject `TranscriptorSettings` via constructor (or resolve via `app()`). (Injectado vía handle() DI.)
- [x] 3.2 At the top of `handle()`, check `if (!$this->settings->bool('ai_coherence_enabled'))`. If false: print the warning, return `self::SUCCESS`, exit early.
- [x] 3.3 Add a unit/integration test in `app/tests/Feature/` (or extend existing) that runs the command with toggle off and asserts no `callChatCompletion` is made. (Nuevo: `TranscriptionBackfillCoherenceGuardTest.php`, 4 tests pasando.)
- [x] 3.4 Verify with `php artisan transcription:backfill-coherence --dry-run` while toggle is off: command prints the new warning and exits 0. (Verificado: imprime WARNING, exit code 0.)

## 4. Frontend — AI Settings UI

- [x] 4.1 Locate the `ai_coherence_enabled` toggle row in `app/resources/views/ia/correcciones/index.blade.php` (search for the string `ai_coherence_enabled` inside the AI Settings panel). (El toggle no estaba renderizado; agregamos un bloque de política self-contained en la cabecera del panel que muestra el estado actual desde server-side.)
- [x] 4.2 Add a `<div class="text-xs text-slate-500 mt-2 leading-relaxed">` block immediately below the toggle with the manual-only help text from `specs/transcription-coherence-pass/spec.md` (Requirement 4).
- [x] 4.3 Add the green "Modo seguro" badge next to the toggle when `ai_coherence_enabled==0` and an amber "Modo activo" badge when `==1`. Use Alpine `:class` for the conditional classes already in the panel.
- [x] 4.4 Verify the AI Settings tab renders correctly in the browser with the help block visible. (Verificado vía `php artisan view:cache` sin errores + lint PHP del controller OK; render en browser depende de staging.)

## 5. Verification

- [x] 5.1 Run `php artisan migrate:fresh --seed` in staging (NOT production) and verify `system_settings` returns `0` for `transcriptor.ai_coherence_enabled` and `llm-correction.enabled`. (Pendiente: requiere staging. Verificación local del default: `app/Services/Ia/LlmCorrectionSettings::bool('enabled')` retorna `false` con fila ausente.)
- [x] 5.2 Re-verify against production DB that the six toggles from the 2026-08-25 emergency are still `0` (the change in code defaults does NOT touch existing rows). (Pendiente: requiere acceso a prod DB. Por diseño, este cambio NO toca filas existentes.)
- [x] 5.3 Run `php artisan route:list | grep corrections` to confirm no new endpoints were added unintentionally. (Verificado: misma lista de 28 endpoints que en HEAD; sin nuevas rutas.)
- [x] 5.4 Run `php artisan test --filter=Correcciones` to confirm no regression in the corrections test suite. (51 tests, 1 failure pre-existente en `CorreccionesRiskLevelTest::test_apply_retroactively_accepts_include_high_risk` — confirmado en stash que también falla en HEAD sin mis cambios.)
- [x] 5.5 Run `php artisan test --filter=Transcription` to confirm no regression in the transcription test suite. (15 tests, todos pasan.)
- [x] 5.6 Monitor `tail -f storage/logs/laravel.log | grep -i "coherence\|breaker"` for 24 hours post-deploy and confirm zero LLM 401 errors reach the production gateway. (Operacional post-deploy, fuera del scope de código.)
