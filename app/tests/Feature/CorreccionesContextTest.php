<?php

namespace Tests\Feature;

use App\Models\Correction;
use App\Services\Ia\CorrectionContextFinder;
use App\Services\Ia\CorrectionService;
use Tests\LaravelTestCase;

/**
 * Tests de los ejemplos de contexto (corrections-context-examples).
 *
 * Como el resto de la suite, estos tests NO tocan BD: LaravelTestCase arranca el
 * framework sin base de datos y la de testing no tiene transcription_segments.
 * Lo que se cubre aquí es lo que decide la corrección del feature y es pura:
 *
 *  - applyRule(): por qué un ejemplo entra o se descarta. El filtro por
 *    substring del ILIKE es solo un pre-filtro; esto es lo que determina si la
 *    regla dispara de verdad.
 *  - la guarda de longitud mínima, que corta antes de llegar a la BD.
 *  - el escape de comodines LIKE.
 *
 * La consulta indexada en sí se verifica con EXPLAIN contra el corpus real; no
 * es reproducible sin los 20M de segmentos.
 */
class CorreccionesContextTest extends LaravelTestCase
{
    private function makeCorrection(string $wrong, string $correct, string $status = Correction::STATUS_PENDING): Correction
    {
        $c = new Correction();
        $c->id = 1;
        $c->wrong_text = $wrong;
        $c->correct_text = $correct;
        $c->wrong_normalized = \App\Models\Keyword::asciiLower(trim($wrong));
        $c->status = $status;

        return $c;
    }

    private function finder(): CorrectionContextFinder
    {
        return new CorrectionContextFinder(new CorrectionService());
    }

    // ============ applyRule: qué cuenta como "la regla dispara" ============

    public function test_apply_rule_replaces_whole_word(): void
    {
        $service = new CorrectionService();
        $correction = $this->makeCorrection('opportunidades', 'oportunidades');

        $this->assertSame(
            'dándole oportunidades a alguien',
            $service->applyRule($correction, 'dándole opportunidades a alguien')
        );
    }

    public function test_apply_rule_is_case_insensitive(): void
    {
        $service = new CorrectionService();
        $correction = $this->makeCorrection('ahorita', 'ahora');

        // Relevante para el moderador: la regla también toca el inicio de frase,
        // y al hacerlo se come la mayúscula. Verlo es justo el objetivo del modal.
        $this->assertSame('ahora yo creo', $service->applyRule($correction, 'Ahorita yo creo'));
    }

    public function test_apply_rule_respects_utf8_word_boundaries(): void
    {
        $service = new CorrectionService();

        // Los dos bugs históricos documentados en isWordCharAt(): sin fronteras
        // UTF-8 correctas, 'dise' rompía "diseño" y 'is' rompía "veintiséis".
        $this->assertSame(
            'al diseño de algo',
            $service->applyRule($this->makeCorrection('dise', 'de'), 'al diseño de algo')
        );
        $this->assertSame(
            'veintiséis',
            $service->applyRule($this->makeCorrection('is', 'es'), 'veintiséis')
        );
    }

    public function test_apply_rule_leaves_text_untouched_when_rule_does_not_fire(): void
    {
        $service = new CorrectionService();
        $correction = $this->makeCorrection('ahorita', 'ahora');

        // "ahora" aparece, pero "ahorita" no: sin este descarte el buscador
        // mostraba este segmento como evidencia de una regla que nunca actuó.
        $text = 'el apoyo que ha tenido la selección ahora es muy grande';

        $this->assertSame($text, $service->applyRule($correction, $text));
    }

    public function test_apply_rule_with_empty_wrong_text_is_a_noop(): void
    {
        $service = new CorrectionService();
        $correction = $this->makeCorrection('', 'algo');

        $this->assertSame('texto intacto', $service->applyRule($correction, 'texto intacto'));
    }

    // ============ Guarda de longitud mínima ============

    public function test_short_probe_short_circuits_before_touching_the_database(): void
    {
        // pg_trgm no sirve patrones de <3 caracteres: la consulta degradaría a
        // seq scan de la tabla de 8,3 GB. Debe cortarse antes de consultar.
        $result = $this->finder()->examples($this->makeCorrection('of', 'de'));

        $this->assertSame(CorrectionContextFinder::STATUS_TOO_SHORT, $result['status']);
        $this->assertSame([], $result['examples']);
        $this->assertFalse($result['truncated']);
    }

    public function test_probe_uses_correct_text_for_approved_corrections(): void
    {
        // En una aprobada el diccionario ya reescribió `text` (la columna
        // indexada), así que buscar el wrong_text ahí no encontraría nada.
        $method = new \ReflectionMethod(CorrectionContextFinder::class, 'probeFor');
        $method->setAccessible(true);

        $approved = $this->makeCorrection('national', 'nacional', Correction::STATUS_APPROVED);
        $pending = $this->makeCorrection('national', 'nacional', Correction::STATUS_PENDING);

        $this->assertSame('nacional', $method->invoke($this->finder(), $approved));
        $this->assertSame('national', $method->invoke($this->finder(), $pending));
    }

    // ============ Escape de comodines LIKE ============

    public function test_escape_like_neutralizes_wildcards(): void
    {
        $method = new \ReflectionMethod(CorrectionContextFinder::class, 'escapeLike');
        $method->setAccessible(true);
        $finder = $this->finder();

        $this->assertSame('100\\% seguro', $method->invoke($finder, '100% seguro'));
        $this->assertSame('a\\_b', $method->invoke($finder, 'a_b'));
        $this->assertSame('c\\\\d', $method->invoke($finder, 'c\\d'));
        $this->assertSame('sin comodines', $method->invoke($finder, 'sin comodines'));
    }

    // ============ Config ============

    public function test_context_config_defaults_are_present(): void
    {
        $this->assertSame(3, (int) config('corrections.context.min_probe_length'));
        $this->assertGreaterThan(0, (int) config('corrections.context.examples'));
        $this->assertGreaterThan(0, (int) config('corrections.context.timeout_ms'));
        $this->assertGreaterThanOrEqual(
            (int) config('corrections.context.examples'),
            (int) config('corrections.context.scan_limit'),
            'scan_limit debe permitir traer al menos tantas filas como ejemplos se muestran'
        );
    }
}
