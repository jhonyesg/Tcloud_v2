<?php

namespace App\Console\Commands;

use App\Models\Correction;
use App\Services\Ia\EnEsRuleClassifier;
use App\Services\Ia\EnglishResidualSegmentDetector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cycle automático de sugerencias EN→ES para el corrector.
 *
 * Hace dos cosas:
 *  1. Lee segmentos con mezcla EN/ES significativa (score >= threshold)
 *     que aún no están marcados como needs_review por transcription_reviews.
 *  2. Extrae trigramas recurrentes (ES_ancla + function_en + ES_noun) y los
 *     inserta como correcciones **pending** (nunca approved) con
 *     source='auto-cycle'.
 *
 * El ancla de la izquierda es lo que impide que la regla dispare fuera de su
 * contexto: extraer solo el par daba `of emergency`->`de emergency`, que se
 * aplicaba en cualquier frase y dejaba el espanglish igual (2026-08-13).
 *
 * Cambios/2026-08-11-english-residual-segment-detector — variante operativa.
 *
 * Diseñado para correr en cron cada 4h. El admin revisa las pendientes
 * en /ia/correcciones y las aprueba o rechaza.
 *
 * Uso:
 *   php artisan corrections:cycle-suggestions --days=1 --threshold=0.25 --min-freq=5
 *   php artisan corrections:cycle-suggestions --days=1 --dry-run
 */
class CycleSuggestionsCommand extends Command
{
    protected $signature = 'corrections:cycle-suggestions
                            {--days=1 : Ventana de análisis en días (default 1, recomendado <= 1 para corrida manual)}
                            {--hours= : Alternativa a --days, ventana en horas (ej: 4)}
                            {--threshold=0.25 : Score mínimo de mezcla por segmento}
                            {--min-freq=5 : Frecuencia mínima del trigrama para proponer}
                            {--max-rules=50 : Tope de reglas a proponer por corrida}
                            {--dry-run : Solo muestra, no inserta ni marca}
                            {--confirm : Confirmación explícita para ventanas > 24h sin --dry-run}';

    protected $description = 'Cycle automático: detecta segmentos con inglés + propone reglas pending.';

    /** Ventana (horas) a partir de la cual la escritura exige --confirm. */
    private const CONFIRM_WINDOW_HOURS = 24;

    public function handle(EnglishResidualSegmentDetector $detector): int
    {
        $hoursOpt = $this->option('hours');
        if ($hoursOpt !== null) {
            $windowHours = max(1, (int) $hoursOpt);
        } else {
            $windowHours = max(1, (int) $this->option('days')) * 24;
        }
        $days = max(1, (int) ceil($windowHours / 24));
        $threshold = (float) $this->option('threshold');
        $minFreq = max(2, (int) $this->option('min-freq'));
        $maxRules = max(1, (int) $this->option('max-rules'));
        $dryRun = (bool) $this->option('dry-run');

        // Guardrail manual-only (change: corrections-manual-only-and-context-search):
        // escritura real con ventana > 24h sin --confirm degrada a dry-run.
        if (!$dryRun && $windowHours > self::CONFIRM_WINDOW_HOURS && !$this->option('confirm')) {
            $dryRun = true;
            $this->warn("Ventana {$windowHours}h > " . self::CONFIRM_WINDOW_HOURS . "h sin --dry-run requiere --confirm. Degradado a dry-run.");
        }

        $this->info("Cycle suggestions: days={$days} threshold={$threshold} min-freq={$minFreq} max-rules={$maxRules}"
            . ($dryRun ? ' [DRY-RUN]' : ''));

        // 1) Encontrar transcripciones con segmentos flagged.
        $flagged = $detector->findFlaggedTranscriptions($threshold, $days);
        $this->info("Transcripciones con segmentos flagged: " . count($flagged));

        if (empty($flagged)) {
            $this->warn('0 transcripciones flagged. Saliendo.');
            return self::SUCCESS;
        }

        // 2) Extraer trigramas directamente desde los segmentos flagged
        //    (optimizado: ventana de fecha + regex, filtramos a flagged en PHP).
        $bigrams = $this->collectAndExtractBigrams($flagged, $days);
        $this->info("Trigramas distintos: " . count($bigrams));

        if (empty($bigrams)) {
            $this->warn('0 trigramas. Saliendo.');
            return self::SUCCESS;
        }

        // 4) Construir candidatos con heurística + validar.
        $candidates = $this->buildCandidates($bigrams, $minFreq, $maxRules);
        $this->info("Candidatos (freq >= {$minFreq}): " . count($candidates));

        if (empty($candidates)) {
            $this->warn('0 candidatos para proponer.');
            return self::SUCCESS;
        }

        // 5) Filtrar duplicados contra pending + approved existentes.
        $candidates = $this->filterExisting($candidates);
        $this->info("Candidatos nuevos (no en pending/approved): " . count($candidates));

        if (empty($candidates)) {
            $this->warn('0 candidatos nuevos.');
            return self::SUCCESS;
        }

        // 6) Output.
        $this->table(
            ['Wrong', 'Correct', 'Freq', 'Risk'],
            array_map(
                fn($c) => [$c['wrong'], $c['correct'], $c['freq'], $c['risk']],
                $candidates
            )
        );

        if ($dryRun) {
            $this->info('Dry-run: ' . count($candidates) . " candidatos. Usar sin --dry-run para insertar.");
            return self::SUCCESS;
        }

        // 7) Insertar como pending.
        $adminId = (int) (DB::table('users')->where('role', 'admin')->orderBy('id')->value('id') ?? 1);
        $source = 'auto-cycle-' . now()->toDateString();
        $inserted = 0;

        foreach ($candidates as $c) {
            Correction::create([
                'wrong_text' => $c['wrong'],
                'correct_text' => $c['correct'],
                'wrong_normalized' => $c['wrong'],
                'status' => 'pending',
                'risk_level' => $c['risk'],
                'proposed_by' => $adminId,
                'source' => $source,
                'applies_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $inserted++;
        }

        $this->info("Inserted: {$inserted} pending rules con source={$source}");
        return self::SUCCESS;
    }

    /**
     * Carga los segmentos con EN function words y extrae trigramas.
     *
     * OPTIMIZACIÓN: usar la ventana de días (finished_at) en vez de
     * whereIn(transcription_id) — el index sobre finished_at es mucho
     * más rápido que un IN con miles de IDs combinado con regex sobre text.
     *
     * @return array<string, int> { "the X" => count, ... }
     */
    private function collectAndExtractBigrams(array $flagged, int $days): array
    {
        $flaggedIds = array_flip(array_column($flagged, 'transcription_id'));
        if (empty($flaggedIds)) return [];

        $regex = '\m(the|and|of|is|are|was|for|with|at|from|this|that)\M';
        $since = now()->subDays(max(1, $days));

        $rows = DB::table('transcription_segments as ts')
            ->join('transcriptions as t', 't.id', '=', 'ts.transcription_id')
            ->where('t.state', 'done')
            ->where('t.finished_at', '>=', $since)
            ->whereNotNull('ts.text')
            ->whereRaw('ts.text ~* ?', [$regex])
            ->select('ts.text', 'ts.transcription_id')
            ->get();

        $bigrams = [];
        foreach ($rows as $row) {
            // Filtrar a solo las flagged transcriptions
            if (!isset($flaggedIds[(int) $row->transcription_id])) continue;

            $txt = mb_strtolower((string) $row->text);
            $tokens = preg_split('/[\s\p{P}]+/u', $txt, -1, PREG_SPLIT_NO_EMPTY);
            if (!is_array($tokens) || count($tokens) < 3) continue;
            $count = count($tokens);
            // Se arranca en 1 porque el n-grama incluye la palabra ANTERIOR a la
            // function word: sin ese ancla el par sale como `of emergency` y la
            // regla dispara en cualquier sitio. Con ella queda `servicios of
            // emergency`, que solo actúa donde el contexto es el mismo.
            for ($i = 1; $i < $count - 1; $i++) {
                $fn = $tokens[$i];
                if (!$this->isEnFunction($fn)) continue;
                $next = $tokens[$i + 1];
                if ($this->isEnFunction($next)) continue;
                $prev = $tokens[$i - 1];
                if ($this->isEnFunction($prev)) continue;
                $trigram = $prev . ' ' . $fn . ' ' . $next;
                $bigrams[$trigram] = ($bigrams[$trigram] ?? 0) + 1;
            }
        }

        return $bigrams;
    }

    private function isEnFunction(string $word): bool
    {
        static $set = null;
        if ($set === null) {
            $set = array_flip([
                'the','and','of','for','with','at','from',
                'is','are','was','were','this','that','these','those',
                'have','has','had','does','did','will','would','should',
                'could','may','might','must',
            ]);
        }
        return isset($set[$word]);
    }

    /**
     * Cuenta trigramas (ES_ancla, function_en, ANY_WORD) en los hits que ya
     * están dentro de segmentos flagged. Solo cuenta ternas donde el token
     * central es una function EN y los laterales no lo son.
     *
     * @return array<string, int> { "servicios of emergency" => count, ... }
     */
    private function extractBigrams(array $segHits): array
    {
        $bigrams = [];

        foreach ($segHits as $hits) {
            // hits vienen del scoreSegment: [['token'=>..., 'lang'=>'en'], ...]
            $tokens = array_column($hits, 'token');
            $langs = array_column($hits, 'lang');
            // Desde 1: el n-grama arrastra la palabra anterior como ancla de
            // contexto, para que la regla no dispare en cualquier frase.
            for ($i = 1; $i < count($tokens) - 1; $i++) {
                if (($langs[$i] ?? '') !== 'en') continue;
                $next = $tokens[$i + 1];
                $secondLang = $langs[$i + 1] ?? 'unknown';
                // Queremos pares (en, es) o (en, unknown) — ambos pueden ser
                // candidatos útiles; descartamos (en, en) que es solo english.
                if ($secondLang === 'en') continue;
                if (($langs[$i - 1] ?? '') === 'en') continue;
                $trigram = mb_strtolower($tokens[$i - 1]) . ' '
                    . mb_strtolower($tokens[$i]) . ' '
                    . mb_strtolower($next);
                $bigrams[$trigram] = ($bigrams[$trigram] ?? 0) + 1;
            }
        }

        return $bigrams;
    }

    /**
     * Valida y construye la lista de candidatos finales.
     * - Filtra por min-freq
     * - Solo acepta pares donde el segundo token es un sustantivo ES común
     *   (heurística de sufijo + lista semilla)
     * - Calcula correct_text con PREP_MAP + heurística de artículo
     * - Asigna risk (low si palabra ancla ES clara, medium si no)
     */
    private function buildCandidates(array $bigrams, int $minFreq, int $maxRules): array
    {
        $prepMap = [
            'the' => null,           // 'the X' → 'el/la X' (no preposición)
            'and' => null,           // 'and X' → 'y X'
            'of'  => 'de',
            'for' => 'para',
            'with' => 'con',
            'at' => 'en',
            'from' => 'de',
            'is' => null,            // 'is X' → 'es X'
            'are' => null,           // 'are X' → 'son/están X'
            'was' => null,           // 'was X' → 'era/estaba X'
            'were' => null,
            'this' => null,          // 'this X' → 'este/esta X'
            'that' => null,          // 'that X' → 'ese/esa X'
            'these' => null,
            'those' => null,
            'have' => null,
            'has' => null,
            'had' => null,
            'does' => null,
            'did' => null,
            'will' => null,
            'would' => null,
            'should' => null,
            'could' => null,
            'may' => null,
            'might' => null,
            'must' => null,
        ];

        $candidates = [];
        foreach ($bigrams as $ngram => $freq) {
            if ($freq < $minFreq) continue;

            $parts = explode(' ', $ngram);
            if (count($parts) !== 3) continue;
            [$anchor, $fn, $noun] = $parts;
            if (!isset($prepMap[$fn])) continue;

            // El ancla tiene que ser española: si es otra palabra inglesa, la
            // regla no está arreglando una mezcla, está dentro de una frase
            // inglesa entera y ahí el diccionario no pinta nada.
            if (!$this->looksSpanishNoun($anchor)) continue;

            // Heurística de sustantivo español: terminación + lista semilla
            if (!$this->looksSpanishNoun($noun)) continue;

            $correct = $this->heuristicSpanish($fn, $noun);
            if ($correct === null) continue;

            $risk = $this->riskFor($fn, $noun);
            $candidates[] = [
                'wrong' => $ngram,
                'correct' => $anchor . ' ' . $correct,
                'freq' => $freq,
                'risk' => $risk,
            ];
        }

        // Ordenar por freq desc, cap a max-rules
        usort($candidates, fn($a, $b) => $b['freq'] - $a['freq']);
        return array_slice($candidates, 0, $maxRules);
    }

    private function looksSpanishNoun(string $noun): bool
    {
        // Lista de tokens que NO deben ser tratados como sustantivos ES
        // cuando vienen después de una function EN. Incluye pronombres,
        // adjetivos comunes, adverbios y otros tokens que requieren
        // traducción especial (no "el/la").
        $reject = [
            // Pronombres / posesivos
            'you','your','yours','i','me','my','mine','we','our','ours','us',
            'they','them','their','theirs','he','she','it','its','his','her',
            'him','hers','myself','yourself','themselves','ourselves',
            // Demostrativos / interrogativos
            'this','that','these','those','what','which','who','whom','whose',
            'where','when','why','how',
            // Adverbios / cuantificadores comunes
            'all','some','any','many','much','more','most','few','little','less',
            'very','too','also','just','only','even','still','already','yet',
            'so','such','quite','rather','almost','enough','really','actually',
            'probably','certainly','definitely','clearly','obviously','simply',
            // Sustantivos/adjetivos comunes en inglés que NO deben mapearse
            // palabra-por-palabra con "el/la X"
            'people','thing','things','time','times','day','days','year','years',
            'way','ways','man','woman','child','children','place','world','life',
            'people','things','part','parts','case','cases','point','points',
            'state','study','work','home','house','school','city','country',
            'system','team','week','month','hour','minute','second',
            'one','two','three','four','five','six','seven','eight','nine','ten',
            'first','second','third','last','next','best','worst','least','most',
            'every','each','both','either','neither','any','some','all',
            'kind','sort','type','form','sort','group','number','others',
            'round','good','great','big','small','large','long','short','high','low',
            'old','young','new','real','full','sure','right','wrong','true','false',
            'els','vor',
            // Verbos auxiliares/ de uso gramatical
            'is','are','was','were','be','been','being','have','has','had',
            'do','does','did','will','would','should','could','may','might','must',
            'can','cannot','cant','wont','dont','doesnt','didnt',
            // Preposiciones / artículos
            'of','to','in','on','at','by','for','with','from','as','into',
            'about','over','under','between','against','without','within',
            'an','a','or','and','but','if','then','than','so','because',
            // Conectores típicos en inglés
            'because','however','therefore','moreover','furthermore','although',
            'though','since','unless','while','whereas','whenever',
            'something','anything','nothing','everything','someone','anyone',
            'everyone','somewhere','anywhere','nowhere','everywhere',
        ];
        if (in_array($noun, $reject, true)) return false;

        $seed = [
            'mundo','gente','gobierno','país','día','tiempo','momento','presidente','ciudad',
            'noche','comunidad','estado','nación','región','departamento','zona','sociedad',
            'historia','mañana','centro','caso','agencia','sistema','edificio','hospital',
            'universidad','programa','manera','grupo','familia','equipo','problema','tema',
            'punto','idea','información','servicio','trabajo','parte','número','lado',
            'hecho','palabra','agua','dinero','área','fuerza','cambio','razón','nivel',
            'camino','año','siglo','mes','semana','hora','memoria','viaje','equipos',
            'temas','casos','padre','madre','hijo','hija','amigo','amiga','abuelo',
            'abuela','esposa','esposo','hermano','hermana','primo','prima',
            'colombia','bogotá','méxico','medellín','cali','barcelona','españa','madrid',
            'deportes','política','economía','cultura','educación','salud','seguridad',
            'poder','partido','elecciones','muerte','vida','guerra','paz','mundo',
            'partido','fútbol','deporte','arte','música','cine','libro','obra',
            'agua','fuego','tierra','aire','sol','luna','estrella','cielo','mar',
            'campo','ciudad','pueblo','barrio','edificio','calle','avenida','plaza',
            'momento','situación','problema','caso','tema','asunto','cuestión',
        ];
        if (in_array($noun, $seed, true)) return true;
        // Heurística: noun debe tener terminación "natural" español
        // (no terminaciones típicamente inglesas como -ing, -tion, -ness, -ly, -ment)
        if (preg_match('/(ing|tion|sion|ness|ment|ful|less|ous|ive|ize|ise|ed|er|est|ly)$/', $noun)) {
            return false;
        }
        // Longitud mínima
        if (strlen($noun) < 3) return false;

        // Prueba POSITIVA de morfología española. Todo lo de arriba es una
        // denylist, y una denylist solo sabe lo que ya le enseñaron: `emergency`
        // no estaba en ella ni encajaba en los sufijos, así que pasó, y de ahí
        // salió la regla `of emergency` -> `de emergency`.
        return app(EnEsRuleClassifier::class)->looksSpanishWord($noun);
    }

    private function looksSpanishNoun_ORIG(string $noun): bool
    {
        $seed = [
            'mundo','gente','gobierno','país','día','tiempo','momento','presidente','ciudad',
            'noche','comunidad','estado','nación','región','departamento','zona','sociedad',
            'historia','mañana','centro','caso','agencia','sistema','edificio','hospital',
            'universidad','programa','manera','grupo','familia','equipo','problema','tema',
            'punto','idea','información','servicio','trabajo','parte','número','lado',
            'hecho','palabra','agua','dinero','área','fuerza','cambio','razón','nivel',
            'camino','año','siglo','mes','semana','hora','memoria','memoria','momento',
            'viaje','equipos','temas','casos','padre','madre','hijo','hija','amigo','amiga',
            'abuelo','abuela','esposa','esposo','hermano','hermana','primo','prima',
            'momento','momento','momento','momento','momento',
        ];
        if (in_array($noun, $seed, true)) return true;
        // Heurística: noun debe tener terminación "natural" español
        // (no terminaciones típicamente inglesas como -ing, -tion, -ness, -ly, -ment)
        if (preg_match('/(ing|tion|sion|ness|ment|ful|less|ous|ive|ize|ise|ed|er|est|ly)$/', $noun)) {
            return false;
        }
        // Longitud mínima
        if (strlen($noun) < 3) return false;
        return true;
    }

    private function heuristicSpanish(string $fn, string $noun): ?string
    {
        // Estrategia CONSERVADORA: para preposiciones simples, generar
        // `prep X` SIN artículo (deja al admin agregar el/la si quiere).
        // Para verbos/the/and, mapear directamente.
        $prepMap = [
            'of'  => 'de',
            'for' => 'para',
            'with'=> 'con',
            'at'  => 'en',
            'from'=> 'de',
        ];
        if (isset($prepMap[$fn])) {
            return $prepMap[$fn] . ' ' . $noun;
        }

        // Para verbos/the/and, generar `function_es noun` solo si noun es
        // inequívocamente ES (de la seed). Si no, retorna null — el admin
        // puede proponer manualmente.
        $verbMap = [
            'the' => 'el', 'and' => 'y',
            'is' => 'es', 'are' => 'son', 'was' => 'era', 'were' => 'eran',
            'this' => 'este', 'that' => 'ese', 'these' => 'estos', 'those' => 'esos',
            'have' => 'ha', 'has' => 'ha', 'had' => 'había',
            'does' => 'hace', 'did' => 'hizo',
        ];
        if (isset($verbMap[$fn])) {
            return $verbMap[$fn] . ' ' . $noun;
        }

        // will/would/should/could/may/might/must → no intentar
        return null;
    }

    private function guessArticle(string $noun): string
    {
        if (preg_match('/(ción|sión|umbre|ie|d|tad|dad|nidad)$/', $noun)) return 'la';
        if (preg_match('/(ma|pa|ta|na|ra|ça|bla)$/', $noun)) return 'la';
        return 'el';
    }

    private function riskFor(string $fn, string $noun): string
    {
        // TODO el output del cron es sugerencias automáticas; conservadoras.
        // Siempre medium — el admin debe aprobar explícitamente.
        return 'medium';
    }

    /**
     * Filtra candidatos que ya existen como pending o approved.
     */
    private function filterExisting(array $candidates): array
    {
        $wrongs = array_column($candidates, 'wrong');
        $existing = Correction::whereIn('wrong_normalized', $wrongs)
            ->whereIn('status', ['pending', 'approved'])
            ->pluck('wrong_normalized')
            ->all();
        $existingSet = array_flip($existing);

        return array_values(array_filter(
            $candidates,
            fn($c) => !isset($existingSet[$c['wrong']])
        ));
    }
}
