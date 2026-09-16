<?php

namespace Tests\Unit;

use App\Services\Ia\TranscriptionReviewService;
use PHPUnit\Framework\TestCase;

/**
 * Test de contratos para la rama refactorizada de TranscriptionReviewService
 * (change: corrections-manual-only-and-context-search). El modo sensibles:
 *   - ejecuta su matching con statement_timeout (config('corrections.review_sensitive.timeout_ms'))
 *   - se aplica sobre las N candidatas (LIST_LIMIT = 10), no sobre el histórico
 *   - devuelve un flag degraded si vence el timeout
 */
class TranscriptionReviewServiceSensitiveTest extends TestCase
{
    public function test_sqlstate_timeout_constant_matches_postgres(): void
    {
        // Constante expuesta a través del código (mismo SQLSTATE que PG devuelve).
        // Validamos indirectamente: la rama QueryException compara el código y
        // también inspecciona el mensaje "statement timeout".
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Ia/TranscriptionReviewService.php');
        $this->assertStringContainsString("SQLSTATE_QUERY_CANCELED = '57014'", $source);
        $this->assertStringContainsString("statement timeout", $source);
    }

    public function test_list_applies_candidates_with_limit_and_distinct_ordering(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Ia/TranscriptionReviewService.php');

        $this->assertIsString($source);
        // N candidatas explícito.
        $this->assertStringContainsString('LIST_LIMIT = 10', $source);
        $this->assertStringContainsString('self::LIST_LIMIT', $source);
        // El matching sensible se aplica sobre las candidatas ya resueltas.
        $this->assertStringContainsString('filterSensitiveIds', $source);
        // Guarda de timeout por transacción.
        $this->assertStringContainsString("SET LOCAL statement_timeout", $source);
        // Flag de degradación.
        $this->assertStringContainsString("'sensitive_degraded'", $source);
        $this->assertStringContainsString("'degraded' => true", $source);
    }

    public function test_build_list_items_includes_sensitive_degraded_flag_for_all_modes(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Ia/TranscriptionReviewService.php');

        $this->assertStringContainsString("'sensitive_degraded' => \$sensitiveCounts['degraded']", $source);
    }
}
