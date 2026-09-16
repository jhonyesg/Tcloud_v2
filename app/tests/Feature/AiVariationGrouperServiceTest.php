<?php

namespace Tests\Feature;

use App\Services\Ia\AiVariationGrouperService;
use Tests\LaravelTestCase;

/**
 * Tests del parser defensivo del AiVariationGrouperService. Las llamadas
 * reales al LLM no se testean (requieren API key); estos tests verifican
 * que el JSON devuelto por el LLM se parsea correctamente en sus
 * diferentes formas (directo, envuelto en fences, malformado).
 */
class AiVariationGrouperServiceTest extends LaravelTestCase
{
    private function service(): AiVariationGrouperService
    {
        return new AiVariationGrouperService();
    }

    // ───────────────────────── estimateTokens / estimateCostUsd ────────────

    public function test_estimate_tokens_returns_positive_for_any_input(): void
    {
        $tokens = $this->service()->estimateTokens([
            ['wrong' => 'Abelardo de las Pellas', 'count' => 4],
            ['wrong' => 'Abelardo de las Prias', 'count' => 3],
        ]);
        $this->assertGreaterThan(50, $tokens);
        $this->assertLessThan(1000, $tokens);
    }

    public function test_estimate_tokens_scales_with_count(): void
    {
        // array_fill con la misma key colapsa — usar array_map para crear 50 entries distintas.
        $big = array_map(fn ($i) => ['wrong' => "test variant $i", 'count' => 1], range(1, 50));
        $small = $this->service()->estimateTokens([['wrong' => 'a', 'count' => 1]]);
        $bigTokens = $this->service()->estimateTokens($big);
        $this->assertGreaterThan($small, $bigTokens);
    }

    public function test_estimate_cost_usd_uses_model_rate(): void
    {
        // Con 1M tokens los costos son distinguibles (gpt-4o es ~16× más caro).
        $costGpt4Mini = $this->service()->estimateCostUsd(1_000_000, 'gpt-4o-mini');
        $costGpt4 = $this->service()->estimateCostUsd(1_000_000, 'gpt-4o');
        $this->assertGreaterThan($costGpt4Mini * 5, $costGpt4);
    }

    public function test_estimate_cost_usd_uses_default_for_unknown_model(): void
    {
        $costUnknown = $this->service()->estimateCostUsd(1_000_000, 'unknown-future-model-xyz');
        $costDefault = $this->service()->estimateCostUsd(1_000_000, null);
        $this->assertSame($costDefault, $costUnknown);
    }

    // ───────────────────────── parseGroups (via reflection) ────────────────

    private function callParseGroups(array $decoded): array
    {
        $method = new \ReflectionMethod(AiVariationGrouperService::class, 'parseGroups');
        $method->setAccessible(true);
        return $method->invoke($this->service(), $decoded);
    }

    public function test_parse_groups_accepts_well_formed_json(): void
    {
        $result = $this->callParseGroups([
            'groups' => [
                [
                    'canonical_correct' => 'Abelardo de la Espriella',
                    'reason' => 'Typos fonéticos',
                    'variants' => [
                        ['wrong' => 'Abelardo de las Prias', 'count' => 4, 'confidence' => 0.95],
                        ['wrong' => 'Abelardo de las Prieya', 'count' => 4, 'confidence' => 0.90],
                    ],
                ],
            ],
        ]);
        $this->assertTrue($result['ok']);
        $this->assertCount(1, $result['groups']);
        $this->assertSame('Abelardo de la Espriella', $result['groups'][0]['canonical_correct']);
        $this->assertCount(2, $result['groups'][0]['variants']);
    }

    public function test_parse_groups_filters_low_confidence(): void
    {
        $result = $this->callParseGroups([
            'groups' => [
                [
                    'canonical_correct' => 'Abelardo de la Espriella',
                    'variants' => [
                        ['wrong' => 'Abelardo de las Prias', 'count' => 4, 'confidence' => 0.95],
                        ['wrong' => 'something-else', 'count' => 1, 'confidence' => 0.3], // <0.5, debe descartarse
                    ],
                ],
            ],
        ]);
        $this->assertTrue($result['ok']);
        $this->assertCount(1, $result['groups']);
        $this->assertCount(1, $result['groups'][0]['variants']); // solo la de 0.95
        $this->assertSame('Abelardo de las Prias', $result['groups'][0]['variants'][0]['wrong']);
    }

    public function test_parse_groups_drops_empty_groups(): void
    {
        $result = $this->callParseGroups([
            'groups' => [
                [
                    'canonical_correct' => 'Abelardo de la Espriella',
                    'variants' => [
                        ['wrong' => 'low-conf', 'count' => 1, 'confidence' => 0.2], // <0.5, descartada
                    ],
                ],
            ],
        ]);
        $this->assertTrue($result['ok']);
        $this->assertCount(0, $result['groups']);
    }

    public function test_parse_groups_handles_markdown_fences(): void
    {
        $decoded = [
            'raw' => ['text' => '```json
{"groups":[{"canonical_correct":"X","variants":[{"wrong":"a","count":1,"confidence":0.9}]}]}
```'],
        ];
        $result = $this->callParseGroups($decoded);
        $this->assertTrue($result['ok']);
        $this->assertCount(1, $result['groups']);
        $this->assertSame('X', $result['groups'][0]['canonical_correct']);
    }

    public function test_parse_groups_fails_on_invalid_json(): void
    {
        $result = $this->callParseGroups(['raw' => ['text' => 'no json here, just text']]);
        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('raw', $result);
    }

    public function test_parse_groups_fails_when_canonical_correct_missing(): void
    {
        $result = $this->callParseGroups([
            'groups' => [
                ['variants' => [['wrong' => 'a', 'count' => 1, 'confidence' => 0.9]]],
            ],
        ]);
        $this->assertFalse($result['ok']);
    }

    public function test_parse_groups_fails_when_variants_missing(): void
    {
        $result = $this->callParseGroups([
            'groups' => [
                ['canonical_correct' => 'X'],
            ],
        ]);
        $this->assertFalse($result['ok']);
    }
}
