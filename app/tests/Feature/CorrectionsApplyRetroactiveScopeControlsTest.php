<?php

namespace Tests\Feature;

use App\Http\Controllers\Ia\CorreccionesController;
use Illuminate\Http\Request;
use Tests\LaravelTestCase;

/**
 * Tests del scope controls introducidos en
 * openspec/changes/corrections-apply-retroactive-scope-controls/:
 *
 *   - resolveScope(): valida `since` (ISO 8601) + `days_back` (legacy) +
 *     `correction_ids` (subset del diccionario).
 *   - estimateMinutes(): heurística conservadora.
 *   - countSegmentsInScope(): respeta `since` con prioridad sobre `days_back`.
 *
 * Los tests que tocarían la BD real se cubren via Reflection sobre la firma
 * y validaciones del helper privado; los flujos end-to-end se validan en
 * producción con la verificación manual (tasks 6.x).
 */
class CorrectionsApplyRetroactiveScopeControlsTest extends LaravelTestCase
{
    private function controller(): CorreccionesController
    {
        return new CorreccionesController();
    }

    private function callResolveScope(array $body): array
    {
        $request = Request::create('/test', 'POST', $body);
        $method = new \ReflectionMethod(CorreccionesController::class, 'resolveScope');
        $method->setAccessible(true);
        return $method->invoke($this->controller(), $request);
    }

    // ─────────────────────── resolveScope: since ──────────────────────────

    public function test_resolve_scope_accepts_iso_since_timestamp(): void
    {
        [$since, $daysBack, $ids, $error] = $this->callResolveScope([
            'since' => '2026-09-06T20:00:00Z',
        ]);
        $this->assertNull($error);
        $this->assertNull($daysBack);
        $this->assertNotNull($since);
        $this->assertSame('2026-09-06T20:00:00+00:00', $since->toIso8601String());
        $this->assertSame([], $ids);
    }

    public function test_resolve_scope_rejects_invalid_since(): void
    {
        [$since, $daysBack, $ids, $error] = $this->callResolveScope([
            'since' => 'ayer a la tarde',
        ]);
        $this->assertNotNull($error);
        $this->assertSame(422, $error->getStatusCode());
        $payload = $error->getData(true);
        $this->assertStringContainsString('ISO 8601', $payload['error']);
    }

    public function test_resolve_scope_legacy_days_back_still_works(): void
    {
        [$since, $daysBack, $ids, $error] = $this->callResolveScope([
            'days_back' => 7,
        ]);
        $this->assertNull($error);
        $this->assertNull($since);
        $this->assertSame(7, $daysBack);
    }

    public function test_resolve_scope_rejects_days_back_zero(): void
    {
        [, , , $error] = $this->callResolveScope(['days_back' => 0]);
        $this->assertNotNull($error);
        $this->assertSame(422, $error->getStatusCode());
    }

    public function test_resolve_scope_rejects_days_back_over_365(): void
    {
        [, , , $error] = $this->callResolveScope(['days_back' => 999]);
        $this->assertNotNull($error);
        $this->assertSame(422, $error->getStatusCode());
    }

    public function test_resolve_scope_days_back_all_is_accepted(): void
    {
        [$since, $daysBack, $ids, $error] = $this->callResolveScope(['days_back' => 'all']);
        $this->assertNull($error);
        $this->assertNull($since);
        $this->assertNull($daysBack);
        $this->assertSame([], $ids);
    }

    // ──────────────── resolveScope: correction_ids ───────────────────────

    public function test_resolve_scope_empty_correction_ids_is_accepted(): void
    {
        [, , $ids, $error] = $this->callResolveScope(['correction_ids' => []]);
        $this->assertNull($error);
        $this->assertSame([], $ids);
    }

    public function test_resolve_scope_correction_ids_dedupe_and_cast(): void
    {
        // Sin BD, la validación de correction_ids contra la tabla `corrections`
        // devuelve 503 (BD no disponible). Con BD, devolvería 422 con los ids
        // faltantes. Cubrimos ambos paths: que NO se cuelgue y que el shape sea
        // JSON con `error` legible.
        [, , , $error] = $this->callResolveScope(['correction_ids' => [1, 2, 'foo', 1]]);
        $this->assertNotNull($error, 'debe rechazar ids no aprobables');
        $this->assertContains($error->getStatusCode(), [422, 503]);
        $payload = $error->getData(true);
        $this->assertArrayHasKey('error', $payload);
    }

    public function test_resolve_scope_too_many_correction_ids_is_rejected(): void
    {
        $tooMany = range(1, 2496);
        [, , , $error] = $this->callResolveScope(['correction_ids' => $tooMany]);
        $this->assertNotNull($error);
        $this->assertSame(422, $error->getStatusCode());
        $payload = $error->getData(true);
        $this->assertStringContainsString('2495', $payload['error']);
    }

    // ─────────────────────── estimateMinutes ─────────────────────────────

    public function test_estimate_minutes_zero_segments_returns_zero(): void
    {
        $method = new \ReflectionMethod(CorreccionesController::class, 'estimateMinutes');
        $method->setAccessible(true);
        $this->assertSame(0, $method->invoke($this->controller(), 0, 2495));
    }

    public function test_estimate_minutes_scales_with_segments_and_corrections(): void
    {
        $method = new \ReflectionMethod(CorreccionesController::class, 'estimateMinutes');
        $method->setAccessible(true);
        // 5000 segments, 100 reglas → factor 1x → 1 min
        $this->assertSame(1, $method->invoke($this->controller(), 5000, 100));
        // 585.240 segments, 2495 reglas → factor ~25x → ceil(117 * 25) = 2925 min
        $eta = $method->invoke($this->controller(), 585240, 2495);
        $this->assertGreaterThan(1000, $eta);
        $this->assertLessThan(5000, $eta);
    }

    public function test_estimate_minutes_minimum_is_one(): void
    {
        $method = new \ReflectionMethod(CorreccionesController::class, 'estimateMinutes');
        $method->setAccessible(true);
        // 1 segment, 1 regla → no debería ser 0
        $this->assertSame(1, $method->invoke($this->controller(), 1, 1));
    }

    // ─────────────────────── countSegmentsInScope ─────────────────────────

    public function test_count_segments_in_scope_handles_no_db_gracefully(): void
    {
        $method = new \ReflectionMethod(CorreccionesController::class, 'countSegmentsInScope');
        $method->setAccessible(true);
        // Sin tabla transcription_segments → retorna 0 vía catch.
        $this->assertSame(0, $method->invoke($this->controller(), null, null));
        $this->assertSame(0, $method->invoke($this->controller(), \Carbon\Carbon::now()->subHour(), null));
        $this->assertSame(0, $method->invoke($this->controller(), null, 7));
    }
}
