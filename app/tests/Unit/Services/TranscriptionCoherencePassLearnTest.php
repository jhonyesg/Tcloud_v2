<?php

namespace Tests\Unit\Services;

use App\Services\Ia\TranscriptionCoherencePass;
use Tests\LaravelTestCase;

/**
 * Tests del extractor ai-coherence-learn (cambios/2026-08-18).
 *
 * El método `extractClausePairs` ahora es accesible vía reflection para
 * validar la estrategia common-prefix/suffix + split por cláusulas.
 */
class TranscriptionCoherencePassLearnTest extends LaravelTestCase
{
    /**
     * Acceso por reflection al método privado extractClausePairs.
     */
    private function extractClausePairs(string $before, string $after): array
    {
        $r = new \ReflectionClass(TranscriptionCoherencePass::class);
        $m = $r->getMethod('extractClausePairs');
        $m->setAccessible(true);
        $instance = $r->newInstanceWithoutConstructor();
        return $m->invokeArgs($instance, [$before, $after]);
    }

    public function test_extracts_simple_word_swaps(): void
    {
        $before = 'The cooperativas están dotadas of two motors';
        $after = 'Las cooperativas están dotadas de dos motores';

        $pairs = $this->extractClausePairs($before, $after);

        $this->assertNotEmpty($pairs, 'Debe extraer al menos un par');
        // Esperamos pares por palabra con su capitalización original:
        // The→Las, of→de, two→dos, motors→motores.
        // (motors/motores: el char-level strip fallaba aquí por el 's' común;
        //  el word-level diff maneja plural/inflexión correctamente.)
        $found = [];
        foreach ($pairs as $p) {
            $found[$p['wrong']] = $p['correct'];
        }
        $this->assertSame('Las', $found['The'] ?? null);
        $this->assertSame('de', $found['of'] ?? null);
        $this->assertSame('dos', $found['two'] ?? null);
        $this->assertSame('motores', $found['motors'] ?? null);
        // El diff de "cooperativas" (mismo en ambas) NO debe emitir par.
        $this->assertArrayNotHasKey('cooperativas', $found);
        $this->assertArrayNotHasKey('están', $found);
        $this->assertArrayNotHasKey('dotadas', $found);
    }

    public function test_no_pairs_when_no_change(): void
    {
        $before = 'Hola mundo';
        $after = 'Hola mundo';
        $pairs = $this->extractClausePairs($before, $after);
        $this->assertEmpty($pairs);
    }

    public function test_emits_pairs_clauses_independently(): void
    {
        // Cambio en oraciones separadas — cada cláusula es su propio par.
        $before = 'Hola. The thing is here.';
        $after = 'Hola. La cosa está aquí.';
        $pairs = $this->extractClausePairs($before, $after);

        $this->assertGreaterThanOrEqual(1, count($pairs));
        // La cláusula de "the thing is here" → "la cosa está aquí" debe estar.
        $foundClause = false;
        foreach ($pairs as $p) {
            if (stripos($p['wrong'], 'thing') !== false || stripos($p['correct'], 'aquí') !== false) {
                $foundClause = true;
                break;
            }
        }
        $this->assertTrue($foundClause, 'Debe capturar la cláusula modificada');
    }

    public function test_no_pairs_when_pure_insertion(): void
    {
        // Cuando el LLM solo añade tokens (sin modificar los existentes),
        // el word-level diff 1-a-1 no emite pares: no hay nada que sirva
        // como regla de find/replace para el diccionario.
        $before = 'A. B. C.';
        $after = 'A. B. C. D.';
        $pairs = $this->extractClausePairs($before, $after);

        $this->assertSame([], $pairs, 'Inserción pura no genera pares find/replace');
    }

    public function test_no_pairs_when_pure_deletion(): void
    {
        $before = 'A. B. C. D.';
        $after = 'A. B. C.';
        $pairs = $this->extractClausePairs($before, $after);

        $this->assertSame([], $pairs, 'Borrado puro no genera pares find/replace');
    }

    public function test_extractClausePairs_method_exists(): void
    {
        $this->assertTrue(
            method_exists(TranscriptionCoherencePass::class, 'extractClausePairs'),
            'extractClausePairs should exist on the coherence pass'
        );
    }

    public function test_hydrate_method_exists_and_is_public(): void
    {
        $reflection = new \ReflectionMethod(TranscriptionCoherencePass::class, 'hydrateCoherenceLearnedSourceSegments');
        $this->assertTrue($reflection->isPublic(), 'hydrate debe ser público para que TranscriptionProcessor pueda llamarlo');
        $this->assertSame('int', (string) $reflection->getParameters()[0]->getType());
    }

    public function test_hydrate_docblock_documents_post_insert_pattern(): void
    {
        $reflection = new \ReflectionMethod(TranscriptionCoherencePass::class, 'hydrateCoherenceLearnedSourceSegments');
        $doc = $reflection->getDocComment();
        $this->assertNotNull($doc, 'Docblock del método hydrate debe existir');
        // Doc puede estar en español o inglés; validamos los conceptos clave.
        $hasConceptPostInsert = str_contains($doc, 'post-INSERT')
            || str_contains($doc, 'después del INSERT')
            || str_contains($doc, 'DESPUÉS del INSERT');
        $this->assertTrue($hasConceptPostInsert, 'Debe documentar que la hidratación es post-INSERT');
        $this->assertStringContainsString('position(', $doc, 'Debe documentar el uso de position()');
    }
}
