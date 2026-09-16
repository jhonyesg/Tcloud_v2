<?php

namespace Tests\Unit;

use App\Services\Ia\MentionsSearchService;
use PHPUnit\Framework\TestCase;

/**
 * change 2026-09-10-mis-avisos-program-date-filter — Task 3.2.
 *
 * Test de la lógica de whitelist/default de `date_field`. No requiere BD:
 * solo ejercita el método público `resolveDateField` del service.
 *
 * Los tests E2E de la query con fecha_field='program' vs 'detected'
 * requieren BD sembrada y se cubren en Playwright (`tests/e2e/mis-avisos-program-date.mjs`).
 */
class MentionsSearchServiceDateFieldTest extends TestCase
{
    public function test_explicit_program_kept(): void
    {
        $svc = new MentionsSearchService();
        $this->assertSame('program', $svc->resolveDateField('program'));
    }

    public function test_explicit_detected_kept(): void
    {
        $svc = new MentionsSearchService();
        $this->assertSame('detected', $svc->resolveDateField('detected'));
    }

    public function test_null_falls_back_to_program(): void
    {
        $svc = new MentionsSearchService();
        $this->assertSame('program', $svc->resolveDateField(null));
    }

    public function test_empty_string_falls_back_to_program(): void
    {
        $svc = new MentionsSearchService();
        $this->assertSame('program', $svc->resolveDateField(''));
    }

    public function test_invalid_value_falls_back_to_program(): void
    {
        $svc = new MentionsSearchService();
        $this->assertSame('program', $svc->resolveDateField('foo'));
        $this->assertSame('program', $svc->resolveDateField('PRO')); // case-sensitive
        $this->assertSame('program', $svc->resolveDateField('detection')); // singular
        $this->assertSame('program', $svc->resolveDateField('program ')); // whitespace
    }

    public function test_default_for_unset_argument(): void
    {
        $svc = new MentionsSearchService();
        $this->assertSame('program', $svc->resolveDateField(null));
        $this->assertSame('program', $svc->resolveDateField(null)); // segundo llamado sin arg
    }
}
