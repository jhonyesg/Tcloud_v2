## Context

See `proposal.md` for motivation. The 2026-08-25 05:28 UTC incident (300 LLM requests in 6 minutes, all returning HTTP 401) exposed three latent issues:

1. **Defaults in code were on.** `LlmCorrectionSettings::SCHEMA` has `enabled => default true` and `primary_enabled => default true`. A fresh DB would re-arm the situation.
2. **No circuit breaker in `TranscriptionCoherencePass::callWithRetry()`.** The method retries 3 times with exponential backoff per call, but if the underlying provider is hard-down (revoked key, gateway outage), it keeps spending `sleep(5) → sleep(10) → sleep(20)` per transcription, with no memory across calls.
3. **`transcription:backfill-coherence` ignored `ai_coherence_enabled`.** The artisan command bypassed the master switch. An admin running it manually with the toggle off would burn tokens without realizing it.

The operational mitigation is already in place (DB toggles flipped + worker restart). This change adds the code-level guarantees so the situation can't re-arm.

## Goals / Non-Goals

**Goals:**
- Make the four LLM master switches resilient to DB-clean scenarios by flipping SCHEMA defaults to `false`.
- Add a Redis-backed circuit breaker to `TranscriptionCoherencePass::callWithRetry()` that excludes a provider for the rest of a job after N consecutive failures.
- Make `transcription:backfill-coherence` honor `ai_coherence_enabled` (refuse + warning if off).
- Surface the manual-only policy in the AI Settings UI so admins see the implication of flipping the toggle.

**Non-Goals:**
- Removing the API keys, URLs, or model names from `system_settings` — the user explicitly wants them preserved for on-demand use.
- Re-introducing the cron schedules for AI suggester. They were deshabilitado on 2026-08-11 per `routes/console.php:132-158` and stay commented.
- Migrating the SCHEMA defaults into DB rows. Defaults live in code; rows are UI overrides.
- Touching `corrections:cycle-suggestions` and `corrections:detect-english-residual` — they are heuristic-only (no LLM tokens) and outside this change's scope.

## Decisions

### Decision 1: SCHEMA defaults flipped via simple literal edits, no migration

**Choice**: Change `'default' => true` to `'default' => false` for `enabled` and `primary_enabled` in `LlmCorrectionSettings::SCHEMA` (lines 54 and 60). No migration file.

**Rationale**: The defaults are consulted only when the row is missing from `system_settings` (see `effective()` in the same file). Once a row exists with `value='1'`, the literal default is irrelevant for that key. Adding a migration that touches every existing row would change behavior on already-configured systems (which is the user's actual setup). For fresh installs only the code default matters.

**Alternatives considered**:
- *Migration that flips existing rows to `0`*: rejected — would silently disable production for any operator who already set them to `1` intentionally. The user flipped the DB rows manually on 2026-08-25; we leave those rows alone.
- *Adding a new gate key like `coherence_default_off_enforced`*: rejected — over-engineers a one-line semantic change.
- *Per-environment config*: rejected — the `env_key` mechanism already supports per-env override; the only thing it can't do is flip the **fresh-install** default.

### Decision 2: Circuit breaker state in Laravel Cache (Redis) per provider

**Choice**: Counter under key `coherence_breaker:{provider}` storing the count of consecutive failures since the last success. TTL = window size (default 600s). Each `callWithRetry()` invocation reads/writes this counter atomically.

```php
private const BREAKER_FAILURE_THRESHOLD = 5;
private const BREAKER_WINDOW_SECONDS = 600;

private function recordFailure(string $provider): void {
    Cache::increment("coherence_breaker:{$provider}");
    Cache::put("coherence_breaker:{$provider}", $count, now()->addSeconds(self::BREAKER_WINDOW_SECONDS));
}

private function recordSuccess(string $provider): void {
    Cache::forget("coherence_breaker:{$provider}");
}

private function isOpen(string $provider): bool {
    return (int) Cache::get("coherence_breaker:{$provider}", 0) >= self::BREAKER_FAILURE_THRESHOLD;
}
```

`callWithRetry()` builds the round-robin list, filters out open providers, and adds a WARNING log when excluding.

**Rationale**: Laravel's cache layer is already configured (`CACHE_DRIVER=redis`); no new infrastructure. The window is per-job because `Cache::increment` is process-global; resetting the counter requires either a successful call or window expiry. A worker that hasn't invoked the coherence pass in 600s naturally returns to the closed state.

**Alternatives considered**:
- *Counter in DB*: rejected — DB roundtrip on every LLM call adds latency and a failure mode (DB down → counter stuck).
- *In-memory counter*: rejected — each queue job is a fresh process; in-memory state evaporates between jobs.
- *Mark provider as bad for the whole worker lifetime*: rejected — too coarse-grained; a 24h stale cache means the worker gives up on a provider for the whole day even if it recovers at minute 5.

### Decision 3: Backfill command returns SUCCESS with WARNING, not FAILURE

**Choice**: `transcription:backfill-coherence` with `ai_coherence_enabled=0` prints `[WARNING]` and exits with code `0`. No exception, no error.

**Rationale**: The user's intent is "don't burn tokens unless I asked". Returning FAILURE would break any cron/admin script that ran `transcription:backfill-coherence` as a no-op when the toggle is off (e.g., a defensive scheduled dry-run). A WARNING that ends in SUCCESS is the right balance: it tells the admin that nothing happened, but doesn't fail the CI/CD pipeline if one exists.

**Alternative considered**:
- *Return code 2 (misuse)*: rejected — Laravel/Artisan treats 2 as an error and may surface this to monitoring as a real failure.
- *Throw exception*: rejected — same reason as FAILURE.

### Decision 4: UI help block as static text, not a new component

**Choice**: Add the manual-only help text as a `<div class="text-xs text-slate-500 ...">` block inside the existing AI Settings panel of `resources/views/ia/correcciones/index.blade.php`, adjacent to the `ai_coherence_enabled` toggle. No new Vue/Alpine component, no new fetch.

**Rationale**: The toggle row already exists; adding an info block is one inline edit. The user is more concerned with operational correctness than UI polish, and the AI settings panel has `<style>`-scoped UI that doesn't need a refactor.

**Alternatives considered**:
- *Banner at top of `/ia/correcciones`*: rejected — would show always, even when admin isn't touching settings.
- *Modal confirmation when activating*: rejected — adds friction for the legit case (admin deliberately enabling for a maintenance window); informational banner is sufficient.

## Risks / Trade-offs

[**Risk**: TTL drift between cache nodes if Redis cluster rebalances during a job] → **Mitigation**: The breaker is per-process; if cache misses, it falls back to closed state (failsafe). Worst case: one extra failed LLM call. Better than failing closed and silently skipping every LLM call forever.

[**Risk**: Admin sets `BREAKER_FAILURE_THRESHOLD` too low (e.g., 2) and a single noisy network blip excludes a provider for 10 min] → **Mitigation**: Defaults are `5` / `600s`. The values are constants in `TranscriptionCoherencePass` for now; if the user wants UI exposure later, add it via `transcriptor.ai_coherence_breaker_threshold` setting.

[**Risk**: Existing AI suggester also calls `LlmCorrectionSuggester::suggest()` which uses `LlmCorrectionSuggester::executePostFilter()` and doesn't go through `callWithRetry()`] → **Mitigation**: Out of scope. The suggester runs only via UI click and is already behind a master toggle; manual invocation. If/when an admin runs it during a provider outage, the existing try/catch already swallows errors gracefully. Future improvement can reuse the same breaker; not in this change.

[**Trade-off**: Breaker state lives in Redis, so two parallel workers calling the same provider race on `Cache::increment`.] → **Accepted**: `Cache::increment` is atomic in Redis; worst case one extra failure counted. The breaker opens at threshold 5, so a single race is tolerable.

[**Trade-off**: AI Settings UI gets one more inline block in `index.blade.php`, which is already a 4422-line file.] → **Accepted**: Adding ~30 lines to a 4422-line file is locally grown scope. A `partials/ai-settings.blade.php` refactor is sensible but out of scope here.

## Migration Plan

1. Land code changes in a single PR.
2. No DB migrations.
3. No service restarts required (none of the changed paths touch cache keys currently used).
4. Verify post-merge: `php artisan migrate:fresh --seed` (in staging only) should produce `transcriptor.ai_coherence_enabled=0` and `llm-correction.enabled=0`.
5. **Rollback**: revert the PR. The defaults revert to `true` again; if production DB still has `value='0'` from the 2026-08-25 emergency intervention, the system stays safe. If a fresh DB has been re-created since then, a rollback re-introduces the original failure mode — that's the expected blast radius of the rollback.

## Open Questions

None. The user explicitly chose the surgical scope (defaults off + breaker + backfill guard + UI help text), and all the decisions above derive from that scope. No outstanding spec-level ambiguity.
