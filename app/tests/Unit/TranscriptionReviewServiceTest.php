<?php

namespace Tests\Unit;

use App\Services\Ia\TranscriptionReviewService;
use PHPUnit\Framework\TestCase;

class TranscriptionReviewServiceTest extends TestCase
{
    public function test_latest_alias_normalizes_to_requested(): void
    {
        $service = new TranscriptionReviewService();

        $this->assertSame('requested', $service->normalizeMode('latest'));
        $this->assertSame('requested', $service->normalizeMode('invalid'));
    }

    public function test_supported_modes_are_canonical(): void
    {
        $service = new TranscriptionReviewService();

        $this->assertSame('requested', $service->normalizeMode('requested'));
        $this->assertSame('completed', $service->normalizeMode('completed'));
        $this->assertSame('sensitive', $service->normalizeMode('sensitive'));
    }

    public function test_list_has_distinct_requested_and_completed_ordering(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Ia/TranscriptionReviewService.php');

        $this->assertIsString($source);
        $this->assertStringContainsString('$query->orderByDesc(\'created_at\')->orderByDesc(\'id\');', $source);
        $this->assertStringContainsString('$query->orderByRaw(\'finished_at DESC NULLS LAST\')', $source);
        $this->assertStringContainsString("'recency_mode' => \$mode", $source);
    }
}
