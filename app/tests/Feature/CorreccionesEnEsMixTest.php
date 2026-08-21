<?php

namespace Tests\Feature;

use App\Console\Commands\MineEnEsCorrectionsCommand;
use App\Services\Ia\CorrectionService;
use App\Services\Ia\EnEsMixMiner;
use PHPUnit\Framework\TestCase;

/**
 * Tests del miner EN↔ES y su orquestación.
 *
 * Estos tests no tocan BD: validan la forma de los datos retornados
 * y la firma del comando Artisan. La integración real con
 * `transcription_segments` se valida manualmente en producción, dado
 * que el proyecto no tiene Laravel Testbench configurado.
 */
class CorreccionesEnEsMixTest extends TestCase
{
    // ============ KNOWN_EN_ES_MAPPINGS ============

    public function test_known_mappings_contains_in_the_world(): void
    {
        $this->assertArrayHasKey('in the world', EnEsMixMiner::KNOWN_EN_ES_MAPPINGS);
        $this->assertSame('en el mundo', EnEsMixMiner::KNOWN_EN_ES_MAPPINGS['in the world']);
    }

    public function test_known_mappings_count_at_least_50_entries(): void
    {
        $this->assertGreaterThanOrEqual(
            50,
            count(EnEsMixMiner::KNOWN_EN_ES_MAPPINGS),
            'KNOWN_EN_ES_MAPPINGS debe tener al menos 50 entradas (GRUPO A + variantes).'
        );
    }

    public function test_known_mappings_values_are_non_empty_strings(): void
    {
        foreach (EnEsMixMiner::KNOWN_EN_ES_MAPPINGS as $wrong => $correct) {
            $this->assertNotEmpty(trim($wrong), "wrong vacío en KNOWN_EN_ES_MAPPINGS");
            $this->assertNotEmpty(trim($correct), "correct vacío para '{$wrong}'");
        }
    }

    // ============ Constantes eliminadas (no deben existir) ============

    public function test_en_functions_constant_is_removed(): void
    {
        $this->assertFalse(
            defined(EnEsMixMiner::class . '::EN_FUNCTIONS'),
            'EN_FUNCTIONS fue retirada en el change 2026-08-15-en-es-mix-miner-prune-open-strategy.'
        );
    }

    public function test_common_es_nouns_constant_is_removed(): void
    {
        $this->assertFalse(
            defined(EnEsMixMiner::class . '::COMMON_ES_NOUNS'),
            'COMMON_ES_NOUNS fue retirada en el change 2026-08-15-en-es-mix-miner-prune-open-strategy.'
        );
    }

    public function test_mine_open_method_is_removed(): void
    {
        $this->assertFalse(
            method_exists(EnEsMixMiner::class, 'mineOpen'),
            'mineOpen() fue retirada en el change 2026-08-15-en-es-mix-miner-prune-open-strategy.'
        );
    }

    public function test_heuristic_spanish_method_is_removed(): void
    {
        $this->assertFalse(
            method_exists(EnEsMixMiner::class, 'heuristicSpanish'),
            'heuristicSpanish() fue retirada en el change 2026-08-15-en-es-mix-miner-prune-open-strategy.'
        );
    }

    public function test_guess_article_method_is_removed(): void
    {
        $this->assertFalse(
            method_exists(EnEsMixMiner::class, 'guessArticle'),
            'guessArticle() fue retirada en el change 2026-08-15-en-es-mix-miner-prune-open-strategy.'
        );
    }

    // ============ mine() retorna solo KNOWN ============

    public function test_mine_returns_only_known_strategy(): void
    {
        $miner = new EnEsMixMiner();
        $reflection = new \ReflectionMethod($miner, 'mine');
        $params = $reflection->getParameters();

        $this->assertCount(2, $params, 'mine() ahora solo recibe daysBack, minFreq');
        $this->assertSame('daysBack', $params[0]->getName());
        $this->assertSame('minFreq', $params[1]->getName());
    }

    // ============ Comando Artisan ============

    public function test_command_signature_has_expected_options(): void
    {
        $command = new MineEnEsCorrectionsCommand();
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('days'));
        $this->assertTrue($definition->hasOption('min-freq'));
        $this->assertFalse(
            $definition->hasOption('strategy'),
            'La opción --strategy fue retirada (solo existe la estrategia KNOWN).'
        );
        $this->assertTrue($definition->hasOption('dry-run'));

        $this->assertSame('30', $definition->getOption('days')->getDefault());
        $this->assertSame('3', $definition->getOption('min-freq')->getDefault());
    }

    public function test_command_name_is_corrections_mine_en_es(): void
    {
        $command = new MineEnEsCorrectionsCommand();
        $this->assertSame('corrections:mine-en-es', $command->getName());
    }

    public function test_command_rejects_strategy_option(): void
    {
        $command = new MineEnEsCorrectionsCommand();
        $definition = $command->getDefinition();

        $this->assertFalse(
            $definition->hasOption('strategy'),
            'Pasar --strategy=open debe fallar porque la opción ya no existe en la firma.'
        );
    }

    // ============ CorrectionService::mineEnEsMix() shape ============

    public function test_mine_en_es_mix_method_exists_with_expected_signature(): void
    {
        $reflection = new \ReflectionMethod(CorrectionService::class, 'mineEnEsMix');
        $this->assertNotNull($reflection->getDocComment(), 'mineEnEsMix debe tener docblock');

        $params = $reflection->getParameters();
        $this->assertCount(3, $params, 'mineEnEsMix ahora recibe (daysBack, minFreq, by) sin strategy');
        $this->assertSame('daysBack', $params[0]->getName());
        $this->assertSame('minFreq', $params[1]->getName());
        $this->assertSame('by', $params[2]->getName());
    }

    public function test_mine_en_es_mix_docblock_documents_return_shape(): void
    {
        $reflection = new \ReflectionMethod(CorrectionService::class, 'mineEnEsMix');
        $doc = $reflection->getDocComment();

        $this->assertStringContainsString('mined', $doc);
        $this->assertStringContainsString('inserted', $doc);
        $this->assertStringContainsString('skipped_duplicate', $doc);
        $this->assertStringContainsString('source', $doc);
    }
}
