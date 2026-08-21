<?php

namespace Tests\Feature;

use App\Services\Ia\LlmCorrectionSuggester;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\LaravelTestCase;

/**
 * Tests del LlmCorrectionSuggester: post-filtro de marcas, system prompt,
 * y manejo de respuestas HTTP del LLM. NO tocan BD; mockean Http facade.
 */
class LlmCorrectionSuggesterTest extends LaravelTestCase
{
    private function makeSuggester(): LlmCorrectionSuggester
    {
        return new LlmCorrectionSuggester();
    }

    // ============ Post-filtro defensivo de marcas ============

    public function test_looks_like_brand_or_proper_noun_detects_all_caps_sigla(): void
    {
        $s = $this->makeSuggester();
        $this->assertTrue($s->looksLikeBrandOrProperNoun('ONU'));
        $this->assertTrue($s->looksLikeBrandOrProperNoun('USA'));
        $this->assertTrue($s->looksLikeBrandOrProperNoun('API'));
        $this->assertTrue($s->looksLikeBrandOrProperNoun('JSON'));
        $this->assertTrue($s->looksLikeBrandOrProperNoun('EE.UU.'));
    }

    public function test_looks_like_brand_or_proper_noun_detects_known_brand_lowercase(): void
    {
        // El filtro es case-insensitive sobre la lista protegida.
        config()->set('llm-correction.protected_brands', ['dionato', 'word enterprise', 'apple']);

        $s = $this->makeSuggester();
        $this->assertTrue($s->looksLikeBrandOrProperNoun('Dionato'));
        $this->assertTrue($s->looksLikeBrandOrProperNoun('Apple'));
        $this->assertTrue($s->looksLikeBrandOrProperNoun('Word Enterprise'));
        $this->assertTrue($s->looksLikeBrandOrProperNoun('dionato'));
    }

    public function test_looks_like_brand_or_proper_noun_detects_internal_capitalization(): void
    {
        $s = $this->makeSuggester();
        $this->assertTrue($s->looksLikeBrandOrProperNoun('iPhone'));
        $this->assertTrue($s->looksLikeBrandOrProperNoun('MacBook'));
        $this->assertTrue($s->looksLikeBrandOrProperNoun('PowerPoint'));
    }

    public function test_looks_like_brand_or_proper_noun_detects_brand_as_subphrase(): void
    {
        config()->set('llm-correction.protected_brands', ['microsoft']);

        $s = $this->makeSuggester();
        // Frase corta que contiene una marca.
        $this->assertTrue($s->looksLikeBrandOrProperNoun('Microsoft Office'));
    }

    public function test_looks_like_brand_or_proper_noun_allows_lowercase_english_phrase(): void
    {
        config()->set('llm-correction.protected_brands', []);

        $s = $this->makeSuggester();
        $this->assertFalse($s->looksLikeBrandOrProperNoun('in the world'));
        $this->assertFalse($s->looksLikeBrandOrProperNoun('of the government'));
        $this->assertFalse($s->looksLikeBrandOrProperNoun('at the moment'));
        $this->assertFalse($s->looksLikeBrandOrProperNoun('made a statement'));
    }

    public function test_looks_like_brand_or_proper_noun_allows_long_lowercase_phrases(): void
    {
        config()->set('llm-correction.protected_brands', ['microsoft']);

        $s = $this->makeSuggester();
        // Frase larga con varias palabras en lowercase — claramente mezcla EN-ES.
        // Pasar freq=10 (>6 palabras pero freq>=8) para que pase el filtro de longitud atómica.
        $this->assertFalse($s->looksLikeBrandOrProperNoun('the president made a public statement yesterday', 10));
    }

    public function test_looks_like_brand_or_proper_noun_rejects_too_long_phrases(): void
    {
        // Cambios/2026-08-02: filtro de longitud atómica.
        $s = $this->makeSuggester();
        // 13 palabras > 12, siempre rechazado.
        $longPhrase = 'one two three four five six seven eight nine ten eleven twelve thirteen';
        $this->assertTrue($s->looksLikeBrandOrProperNoun($longPhrase, 100), '13 palabras debe ser siempre rechazado');
    }

    public function test_looks_like_brand_or_proper_noun_rejects_long_low_freq(): void
    {
        // 9 palabras con freq=5 (>8 palabras, freq<15) → rechazado.
        $s = $this->makeSuggester();
        $phrase = 'a b c d e f g h i';
        $this->assertTrue($s->looksLikeBrandOrProperNoun($phrase, 5));
    }

    public function test_looks_like_brand_or_proper_noun_detects_short_capitalized_phrases(): void
    {
        $s = $this->makeSuggester();
        // Frase corta (≤ 3 palabras) cuya primera es mayúscula → probable nombre propio.
        $this->assertTrue($s->looksLikeBrandOrProperNoun('Microsoft Office'));
        $this->assertTrue($s->looksLikeBrandOrProperNoun('Caracol Radio'));
    }

    public function test_looks_like_brand_or_proper_noun_rejects_empty(): void
    {
        $s = $this->makeSuggester();
        $this->assertTrue($s->looksLikeBrandOrProperNoun(''));
        $this->assertTrue($s->looksLikeBrandOrProperNoun('   '));
    }

    // ============ System prompt ============

    public function test_prompt_system_message_contains_brand_exclusion_rules(): void
    {
        $s = $this->makeSuggester();
        $prompt = $s->getSystemPrompt();

        $this->assertStringContainsString('NEVER propose', $prompt);
        $this->assertStringContainsString('brand names', $prompt);
        $this->assertStringContainsString('acronyms in all-caps', $prompt);
        $this->assertStringContainsString('person names', $prompt);
        $this->assertStringContainsString('JSON', $prompt);
    }

    public function test_prompt_system_message_includes_protected_brands_list(): void
    {
        config()->set('llm-correction.protected_brands', ['Dionato', 'BrandTestXYZ']);

        $s = $this->makeSuggester();
        $prompt = $s->getSystemPrompt();

        $this->assertStringContainsString('Dionato', $prompt);
        $this->assertStringContainsString('BrandTestXYZ', $prompt);
    }

    public function test_prompt_system_message_documents_json_output_format(): void
    {
        $s = $this->makeSuggester();
        $prompt = $s->getSystemPrompt();

        $this->assertStringContainsString('candidates', $prompt);
        $this->assertStringContainsString('"wrong"', $prompt);
        $this->assertStringContainsString('"correct"', $prompt);
        $this->assertStringContainsString('"freq"', $prompt);
        $this->assertStringContainsString('"reason"', $prompt);
    }

    public function test_prompt_includes_high_precision_over_recall_guidance(): void
    {
        $s = $this->makeSuggester();
        $prompt = $s->getSystemPrompt();

        $this->assertStringContainsString('High precision over recall', $prompt);
        $this->assertStringContainsString('false-positive', $prompt);
    }

    // ============ buildUserPrompt ============

    public function test_build_user_prompt_segments_index_format(): void
    {
        $s = $this->makeSuggester();
        $segments = [
            ['id' => 1, 'text' => 'el presidente llegó tarde'],
            ['id' => 2, 'text' => 'the meeting was cancelled'],
        ];
        $prompt = $s->buildUserPrompt($segments, 1);

        $this->assertStringContainsString('2 transcription segments', $prompt);
        $this->assertStringContainsString('last 1 day', $prompt);
        $this->assertStringContainsString('0: el presidente llegó tarde', $prompt);
        $this->assertStringContainsString('1: the meeting was cancelled', $prompt);
    }

    public function test_build_user_prompt_truncates_long_segments(): void
    {
        $s = $this->makeSuggester();
        $long = str_repeat('palabra ', 500); // ~3500 chars
        $prompt = $s->buildUserPrompt([['id' => 1, 'text' => $long]], 1);

        // Cada segmento truncado a 800 chars en el output → no debe contener los 500 repeticiones enteras.
        $this->assertLessThan(strlen($long) + 200, strlen($prompt));
    }

    // ============ callChatCompletion (trait) — error handling ============

    public function test_call_chat_completion_throws_on_missing_api_key(): void
    {
        \Illuminate\Support\Facades\Cache::forget('llm_correction:api_key');
        \App\Models\SystemSetting::where('key', 'llm-correction.api_key')->delete();
        config()->set('llm-correction.api_key', '');

        $s = $this->makeSuggester();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/LLM_API_KEY/');
        $reflection = new \ReflectionMethod($s, 'callChatCompletion');
        $reflection->setAccessible(true);
        $reflection->invoke($s, 'sys', 'user');
    }

    public function test_call_chat_completion_throws_on_http_error(): void
    {
        config()->set('llm-correction.api_key', 'sk-test');
        config()->set('llm-correction.base_url', 'http://example.test');
        config()->set('llm-correction.timeout_seconds', 5);

        Http::fake([
            'example.test/*' => Http::response('{"error":"bad"}', 500),
        ]);

        $s = $this->makeSuggester();
        $reflection = new \ReflectionMethod($s, 'callChatCompletion');
        $reflection->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/HTTP 500/');
        $reflection->invoke($s, 'sys', 'user');
    }

    public function test_call_chat_completion_parses_json_response(): void
    {
        config()->set('llm-correction.api_key', 'sk-test');
        config()->set('llm-correction.base_url', 'http://example.test');
        config()->set('llm-correction.model', 'test-model');

        $fakeBody = json_encode([
            'choices' => [[
                'message' => [
                    'content' => json_encode(['candidates' => [
                        ['wrong' => 'the meeting', 'correct' => 'la reunión', 'freq' => 2, 'reason' => 'ASR mixing'],
                    ]]),
                ],
            ]],
        ]);

        Http::fake([
            'example.test/*' => Http::response($fakeBody, 200),
        ]);

        $s = $this->makeSuggester();
        $reflection = new \ReflectionMethod($s, 'callChatCompletion');
        $reflection->setAccessible(true);
        $result = $reflection->invoke($s, 'sys', 'user');

        $this->assertIsArray($result);
        $this->assertCount(1, $result['candidates']);
        $this->assertSame('the meeting', $result['candidates'][0]['wrong']);
    }

    public function test_call_chat_completion_returns_unparsed_for_prose(): void
    {
        // Comportamiento esperado desde 2026-08-01: removimos
        // response_format:json_object porque gateways proxy (OllamaCloud,
        // vLLM, local) lo rechazan con 400. En su lugar, devolvemos el
        // texto crudo para que el suggester intente extraer JSON con regex.
        config()->set('llm-correction.api_key', 'sk-test');
        config()->set('llm-correction.base_url', 'http://example.test');

        $fakeBody = json_encode([
            'choices' => [[
                'message' => ['content' => 'this is not json'],
            ]],
        ]);

        Http::fake([
            'example.test/*' => Http::response($fakeBody, 200),
        ]);

        $s = $this->makeSuggester();
        $reflection = new \ReflectionMethod($s, 'callChatCompletion');
        $reflection->setAccessible(true);
        $result = $reflection->invoke($s, 'sys', 'user');

        $this->assertIsArray($result);
        $this->assertTrue($result['unparsed'] ?? false);
        $this->assertSame('this is not json', $result['raw']['text'] ?? null);
    }

    public function test_extract_candidates_from_text_parses_json_in_prose(): void
    {
        $s = $this->makeSuggester();
        $method = new \ReflectionMethod($s, 'extractCandidatesFromText');
        $method->setAccessible(true);

        $prose = "Aquí está mi respuesta: ```json\n{\"candidates\": [{\"wrong\": \"the meeting\", \"correct\": \"la reunión\", \"freq\": 2, \"reason\": \"ASR\"}]}\n``` Espero que ayude.";
        $result = $method->invoke($s, $prose);

        $this->assertCount(1, $result);
        $this->assertSame('the meeting', $result[0]['wrong']);
        $this->assertSame('la reunión', $result[0]['correct']);
    }

    public function test_extract_candidates_from_text_handles_unfenced_json(): void
    {
        $s = $this->makeSuggester();
        $method = new \ReflectionMethod($s, 'extractCandidatesFromText');
        $method->setAccessible(true);

        $text = 'Reasoning: I analyzed the segments. Result: {"candidates": [{"wrong": "hello world", "correct": "hola mundo", "freq": 1}]}';
        $result = $method->invoke($s, $text);

        $this->assertCount(1, $result);
        $this->assertSame('hello world', $result[0]['wrong']);
    }

    public function test_extract_candidates_from_text_returns_empty_for_no_json(): void
    {
        $s = $this->makeSuggester();
        $method = new \ReflectionMethod($s, 'extractCandidatesFromText');
        $method->setAccessible(true);

        $this->assertSame([], $method->invoke($s, 'I found no candidates.'));
        $this->assertSame([], $method->invoke($s, ''));
    }
}
