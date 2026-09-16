<?php

namespace Tests\Unit;

use App\Services\Ia\AvisosScanService;
use Tests\LaravelTestCase;

/**
 * Tests de dedupeBumpSet (fix-avisos-watermarks-cardinality-violation).
 *
 * Helper estático que agrupa por (keyword_id, storage_provider_id)
 * para evitar Cardinality violation en el bulk INSERT.
 *
 * Como es helper estático puro (no toca BD), corre sin harness ni seeds.
 */
class AvisosScanServiceBumpDedupeTest extends LaravelTestCase
{
    public function test_dedupe_with_unique_pairs_returns_unchanged(): void
    {
        $bumpSet = [
            ['keyword_id' => 94,  'storage_provider_id' => 12, 'finished_at' => '2026-07-11 05:50:18', 'hits' => 1, 'candidates' => 1],
            ['keyword_id' => 95,  'storage_provider_id' => 12, 'finished_at' => '2026-07-11 06:00:00', 'hits' => 0, 'candidates' => 1],
            ['keyword_id' => 117, 'storage_provider_id' => 12, 'finished_at' => '2026-07-11 06:10:00', 'hits' => 3, 'candidates' => 1],
        ];

        $result = AvisosScanService::dedupeBumpSet($bumpSet);
        $this->assertCount(3, $result);
        // Sin duplicados: contenido idéntico (mismo orden no garantizado).
        $this->assertEqualsCanonicalizing($bumpSet, $result);
    }

    public function test_dedupe_consolidates_8_rows_of_same_pair(): void
    {
        // Reproduce el incidente del 2026-09-09: 8 filas del mismo par.
        $bumpSet = [];
        $finishedAts = [
            '2026-07-11 05:50:18',
            '2026-07-11 06:05:11',
            '2026-07-11 06:20:17',
            '2026-07-11 06:35:12',
            '2026-07-11 06:50:16',
            '2026-07-11 07:05:12',
            '2026-07-11 07:20:18',
            '2026-07-11 07:35:11',
        ];
        foreach ($finishedAts as $fat) {
            $bumpSet[] = [
                'keyword_id' => 94,
                'storage_provider_id' => 12,
                'finished_at' => $fat,
                'hits' => 1,
                'candidates' => 1,
            ];
        }

        $result = AvisosScanService::dedupeBumpSet($bumpSet);

        $this->assertCount(1, $result, '8 filas del mismo par → 1 fila');
        $row = $result[0];
        $this->assertSame(94, $row['keyword_id']);
        $this->assertSame(12, $row['storage_provider_id']);
        $this->assertSame('2026-07-11 07:35:11', $row['finished_at'], 'finished_at = MAX');
        $this->assertSame(8, $row['candidates'], 'candidates = SUM');
        $this->assertSame(8, $row['hits'], 'hits = SUM');
    }

    public function test_dedupe_sums_hits_correctly(): void
    {
        $bumpSet = [
            ['keyword_id' => 10, 'storage_provider_id' => 5, 'finished_at' => '2026-07-11 05:00:00', 'hits' => 2, 'candidates' => 1],
            ['keyword_id' => 10, 'storage_provider_id' => 5, 'finished_at' => '2026-07-11 06:00:00', 'hits' => 0, 'candidates' => 1],
            ['keyword_id' => 10, 'storage_provider_id' => 5, 'finished_at' => '2026-07-11 07:00:00', 'hits' => 5, 'candidates' => 1],
        ];

        $result = AvisosScanService::dedupeBumpSet($bumpSet);

        $this->assertCount(1, $result);
        $this->assertSame(7, $result[0]['hits'], 'hits = 2 + 0 + 5 = 7');
        $this->assertSame(3, $result[0]['candidates'], 'candidates = 1+1+1 = 3');
        $this->assertSame('2026-07-11 07:00:00', $result[0]['finished_at'], 'MAX de finished_at');
    }

    public function test_dedupe_with_multiple_distinct_pairs(): void
    {
        // 2 pares distintos con duplicados internos cada uno.
        $bumpSet = [
            ['keyword_id' => 94, 'storage_provider_id' => 12, 'finished_at' => '2026-07-11 05:50:18', 'hits' => 1, 'candidates' => 1],
            ['keyword_id' => 94, 'storage_provider_id' => 12, 'finished_at' => '2026-07-11 06:00:00', 'hits' => 1, 'candidates' => 1],
            ['keyword_id' => 95, 'storage_provider_id' => 12, 'finished_at' => '2026-07-11 05:50:18', 'hits' => 0, 'candidates' => 1],
            ['keyword_id' => 95, 'storage_provider_id' => 12, 'finished_at' => '2026-07-11 06:30:00', 'hits' => 2, 'candidates' => 1],
            ['keyword_id' => 95, 'storage_provider_id' => 13, 'finished_at' => '2026-07-11 05:50:18', 'hits' => 0, 'candidates' => 1],
        ];

        $result = AvisosScanService::dedupeBumpSet($bumpSet);

        $this->assertCount(3, $result, '3 pares únicos');
        $byKey = [];
        foreach ($result as $r) {
            $byKey[$r['keyword_id'] . ':' . $r['storage_provider_id']] = $r;
        }
        $this->assertSame(2, $byKey['94:12']['hits']);
        $this->assertSame(2, $byKey['94:12']['candidates']);
        $this->assertSame('2026-07-11 06:00:00', $byKey['94:12']['finished_at']);
        $this->assertSame(2, $byKey['95:12']['hits']);
        $this->assertSame(2, $byKey['95:12']['candidates']);
        $this->assertSame('2026-07-11 06:30:00', $byKey['95:12']['finished_at']);
        $this->assertSame(0, $byKey['95:13']['hits']);
        $this->assertSame(1, $byKey['95:13']['candidates']);
    }

    public function test_dedupe_with_empty_or_invalid_input(): void
    {
        $this->assertSame([], AvisosScanService::dedupeBumpSet([]));
    }

    public function test_dedupe_preserves_first_occurrence_for_unknown_keys(): void
    {
        // Filas con claves faltantes o cero: se conservan en un grupo
        // sintético para no perderlas silenciosamente.
        $bumpSet = [
            ['keyword_id' => 0, 'storage_provider_id' => 0, 'finished_at' => '2026-07-11 05:50:18', 'hits' => 0, 'candidates' => 1],
            ['finished_at' => '2026-07-11 06:00:00'], // sin keyword_id
            ['keyword_id' => 94, 'storage_provider_id' => 12, 'finished_at' => '2026-07-11 07:00:00', 'hits' => 1, 'candidates' => 1],
        ];

        $result = AvisosScanService::dedupeBumpSet($bumpSet);
        $this->assertCount(3, $result, 'Filas inválidas se conservan individualmente');
        // La fila válida sigue presente con sus datos.
        $this->assertNotEmpty(array_filter($result, fn($r) => ($r['keyword_id'] ?? 0) === 94));
    }
}
