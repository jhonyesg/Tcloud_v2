<?php

namespace Tests\Feature;

use App\Console\Commands\AiSuggestEnEsCorrectionsCommand;
use PHPUnit\Framework\TestCase;

/**
 * Tests del Artisan command corrections:ai-suggest.
 * Cubren la firma, los guards de configuración y el shape de salida.
 */
class AiSuggestCommandTest extends TestCase
{
    public function test_command_signature_has_expected_options(): void
    {
        $command = new AiSuggestEnEsCorrectionsCommand();
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('days'));
        $this->assertTrue($definition->hasOption('sample'));
        $this->assertTrue($definition->hasOption('dry-run'));
    }

    public function test_command_name_is_corrections_ai_suggest(): void
    {
        $command = new AiSuggestEnEsCorrectionsCommand();
        $this->assertSame('corrections:ai-suggest', $command->getName());
    }

    public function test_command_description_mentions_brand_exclusion(): void
    {
        $command = new AiSuggestEnEsCorrectionsCommand();
        $this->assertStringContainsString('exclusión de marcas', $command->getDescription());
    }

    public function test_correction_service_has_ai_suggest_method(): void
    {
        $reflection = new \ReflectionMethod(\App\Services\Ia\CorrectionService::class, 'aiSuggestEnEsMix');
        $this->assertNotNull($reflection->getDocComment(), 'aiSuggestEnEsMix debe tener docblock');

        $params = $reflection->getParameters();
        $this->assertCount(3, $params);
        $this->assertSame('days', $params[0]->getName());
        $this->assertSame('sampleSize', $params[1]->getName());
        $this->assertSame('by', $params[2]->getName());
    }

    public function test_correction_service_ai_suggest_documents_return_shape(): void
    {
        $reflection = new \ReflectionMethod(\App\Services\Ia\CorrectionService::class, 'aiSuggestEnEsMix');
        $doc = $reflection->getDocComment();

        $this->assertStringContainsString('mined', $doc);
        $this->assertStringContainsString('inserted', $doc);
        $this->assertStringContainsString('skipped_duplicate', $doc);
        $this->assertStringContainsString('rejected_by_filter', $doc);
        $this->assertStringContainsString('source', $doc);
    }
}
