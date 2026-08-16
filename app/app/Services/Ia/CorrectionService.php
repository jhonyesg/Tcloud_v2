<?php

namespace App\Services\Ia;

use App\Models\ChannelLanguage;
use App\Models\Correction;
use App\Models\CorrectionBulkAction;
use App\Models\CorrectionBulkActionItem;
use App\Models\Keyword;
use App\Models\Transcription;
use App\Models\TranscriptionSegment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Orquesta el diccionario de correcciones (wrong -> correct) moderado
 * cliente -> admin. Las correcciones approved se aplican al campo `text`
 * (vivo) de los TranscriptionSegment al parsear SRT nuevo, y se pueden
 * reaplicar retroactivamente con applyRetroactively().
 */
class CorrectionService
{
    /**
     * Stopwords para extracción atómica (changes/2026-08-02-corrections-dictionary-atomicity).
     * Filtradas del wrong_text al extraer unigramas/bigramas candidatos.
     */
    private const ATOMICITY_STOPWORDS = [
        'the', 'and', 'of', 'to', 'a', 'in', 'is', 'it', 'that', 'for', 'on',
        'with', 'as', 'at', 'by', 'from', 'be', 'are', 'was', 'were', 'an',
        'or', 'but', 'if', 'then', 'so', 'this', 'these', 'those', 'i', 'you',
        'he', 'she', 'we', 'they', 'my', 'your', 'our', 'their',
    ];

    /**
     * Largo mínimo de token para considerarlo en sugerencias atómicas.
     */
    private const ATOMICITY_MIN_TOKEN_LENGTH = 3;

    /**
     * % de consenso mínimo en otras correcciones para asignar confidence='high'.
     */
    private const ATOMICITY_CONFIDENCE_THRESHOLD = 0.8;

    /**
     * $settings es opcional: resuelto por el contenedor en produccion (para que
     * un override desde la UI tenga efecto), y omitible en tests que instancian
     * el servicio a pelo para probar solo la logica de texto.
     */
    public function __construct(private ?TranscriptorSettings $settings = null) {}

    private function chunkSize(): int
    {
        return $this->settings?->int('corrections_chunk')
            ?? (int) config('transcriptor.corrections_chunk', 500);
    }

    /**
     * Aplica el diccionario approved a un array de segmentos y retorna el
     * array mutado. La mutación se hace por referencia de índice para que
     * el caller (`TranscriptionProcessor`) reciba los segmentos con `text`
     * corregido directamente.
     *
     * @param array $segments array de arrays con clave `text_raw` (y opcional `text`)
     * @return array el mismo array con `text` corregido
     */
    public function applyToSegments(array $segments, bool $includeHighRisk = false): array
    {
        $pairs = $this->preparePairs($this->loadCorrections($includeHighRisk));

        if (empty($pairs)) {
            return $segments;
        }

        foreach ($segments as $i => $segment) {
            $raw = $segment['text_raw'] ?? $segment['text'] ?? '';
            $segments[$i]['text'] = $this->applyTextWithPairs($raw, $pairs);
        }

        return $segments;
    }

    /**
     * Carga el diccionario approved ordenado por longitud DESC del wrong.
     * Punto único de verdad para las tres rutas que lo necesitan
     * (applyToSegments, applyRetroactively y Correction::applyToText).
     */
    private function loadCorrections(bool $includeHighRisk = false): \Illuminate\Support\Collection
    {
        return Correction::approved()
            ->orderByRaw('LENGTH(wrong_normalized) DESC')
            ->when(!$includeHighRisk, fn ($query) => $query->safe())
            ->get(['wrong_normalized', 'correct_text']);
    }

    /**
     * Aplica el diccionario approved a un texto suelto.
     *
     * Implementación ÚNICA de "aplicar el diccionario": `Correction::applyToText()`
     * delega aquí. Antes el modelo tenía su propio `preg_replace('/\b...\b/i')`
     * sin flag `/u` — el bug espejo del de aquí — así que producción y tests
     * validaban semánticas distintas.
     */
    public function applyToText(string $text, bool $includeHighRisk = false): string
    {
        return $this->applyTextWithPairs(
            $text,
            $this->preparePairs($this->loadCorrections($includeHighRisk))
        );
    }

    /**
     * Aplica UNA sola regla a un texto, ignorando el resto del diccionario.
     *
     * Permite dos cosas al moderar: comprobar que la corrección realmente dispara
     * en un segmento, y previsualizar qué dejaría si se aprueba. Que `wrong_text`
     * aparezca como substring NO basta para lo primero: la aplicación real exige
     * fronteras de palabra, así que "ahorita"→"ahora" no toca "Ahorita" ni
     * "ahoritica". Un buscador que se fíe del substring acaba mostrando ejemplos
     * donde la regla nunca actuó.
     *
     * Pasa por applyTextWithPairs() a propósito: ese matcher arrastra correcciones
     * de fronteras UTF-8 (ver isWordCharAt) que no deben reimplementarse aparte.
     */
    public function applyRule(Correction $correction, string $text): string
    {
        $wrong = $correction->wrong_normalized ?: Keyword::asciiLower(trim((string) $correction->wrong_text));

        if ($wrong === '') {
            return $text;
        }

        return $this->applyTextWithPairs($text, [[$wrong, (string) $correction->correct_text, $correction->id]]);
    }

    /**
     * Pre-computa el array de pares primitivos [wrong_normalized, correct_text]
     * ordenados por longitud DESC del wrong. Llamar UNA vez por corrida fuera
     * del loop de segmentos para evitar N usorts/array_maps innecesarios.
     *
     * @param \Illuminate\Support\Collection $corrections
     * @return array<int, array{0: string, 1: string}>
     */
    private function preparePairs(\Illuminate\Support\Collection $corrections): array
    {
        if ($corrections->isEmpty()) {
            return [];
        }

        // Extraer pares primitivos. Eloquent Models son ~50x más lentos
        // que arrays primitivos en loops calientes; convertimos UNA vez
        // al inicio. ->all() es 14000x más rápido que ->toArray().
        // El tercer elemento (id) solo viene poblado cuando el caller seleccionó
        // la columna; applyTextWithPairs() lo ignora al destructurar, y
        // applyRetroactively() lo usa para atribuir applies_count al par exacto
        // que hizo el reemplazo.
        $pairs = array_filter(
            array_map(
                fn($c) => [
                    $c->wrong_normalized ?? '',
                    $c->correct_text ?? '',
                    $c->id ?? null,
                ],
                $corrections->all()
            ),
            fn($pair) => $pair[0] !== ''
        );

        if (empty($pairs)) {
            return [];
        }

        // Ordenar por longitud DESC de wrong_normalized (frases largas
        // primero para que "in the world" se aplique antes que "the world").
        usort($pairs, fn($a, $b) => strlen($b[0]) - strlen($a[0]));

        return array_values($pairs);
    }

    /**
     * ¿El byte en $idx forma parte de una letra o un dígito?
     *
     * $text[$idx] es un BYTE, no un carácter. Con `ctype_alnum()` a secas el
     * byte líder de 'ñ'/'á' (0xC3) devuelve false, así que una letra acentuada
     * contaba como frontera de palabra: por eso la regla 'dise'->'de' convertía
     * "al diseño" en "al deño", y 'is'->'es' convertía "veintiséis" en
     * "veintisées". Aquí se recorta el carácter UTF-8 completo del borde y se
     * evalúa una sola vez contra \p{L}\p{N}.
     *
     * No vale el atajo "todo byte >= 0x80 es letra": trataría '¿', '«' o '—'
     * como letra e impediría matchear pegado a ellos.
     */
    private static function isWordCharAt(string $text, int $idx): bool
    {
        $len = strlen($text);

        if ($idx < 0 || $idx >= $len) {
            return false;
        }

        $byte = $text[$idx];
        $ord = ord($byte);

        // ASCII: decisión directa, sin coste extra en el 95% de los bordes.
        if ($ord < 0x80) {
            return ctype_alnum($byte);
        }

        // Multibyte: retroceder hasta el byte líder (los de continuación son
        // 10xxxxxx) y recortar el carácter completo.
        $start = $idx;
        while ($start > 0 && (ord($text[$start]) & 0xC0) === 0x80) {
            $start--;
        }

        $lead = ord($text[$start]);
        $charLen = match (true) {
            ($lead & 0xE0) === 0xC0 => 2,
            ($lead & 0xF0) === 0xE0 => 3,
            ($lead & 0xF8) === 0xF0 => 4,
            default => 1,
        };

        $char = substr($text, $start, $charLen);

        // Los caracteres distintos que aparecen en un borde son poquísimos
        // (acentos españoles y signos de puntuación), así que memoizar deja
        // el coste amortizado en O(1) por match.
        static $cache = [];

        return $cache[$char] ??= (bool) preg_match('/[\p{L}\p{N}]/u', $char);
    }

    /**
     * Aplica pares de correcciones pre-procesados a un texto plano. Misma
     * semántica y el mismo word-boundary manual que la implementación
     * previa; separada para evitar usort/array_filter por cada segmento
     * en corridas retroactivas de cientos de miles de segments.
     *
     * $hits, si se pasa, acumula por índice de par cuántos reemplazos REALES
     * se hicieron. Es lo que permite contar applies_count sin el
     * `substr_count()` sin frontera que inflaba los contadores.
     *
     * @param array<int, array{0: string, 1: string}> $pairs resultado de preparePairs()
     * @param array<int, int>|null $hits out-param: [índice del par => nº de reemplazos]
     */
    private function applyTextWithPairs(string $text, array $pairs, ?array &$hits = null): string
    {
        if (empty($pairs)) {
            return $text;
        }

        $textLower = strtolower($text);

        foreach ($pairs as $pairIndex => [$wrong, $correct]) {
            // Pre-filtro ultra-rapido.
            if (strpos($textLower, $wrong) === false) {
                continue;
            }

            $wrongLen = strlen($wrong);
            $correctLen = strlen($correct);
            $textLen = strlen($text);

            $pos = 0;
            while ($pos <= $textLen - $wrongLen) {
                $found = stripos($text, $wrong, $pos);
                if ($found === false) {
                    break;
                }

                $isLeftBoundary = !self::isWordCharAt($text, $found - 1);
                $isRightBoundary = !self::isWordCharAt($text, $found + $wrongLen);

                if ($isLeftBoundary && $isRightBoundary) {
                    $text = substr_replace($text, $correct, $found, $wrongLen);
                    $textLower = strtolower($text);
                    $textLen = strlen($text);
                    $pos = $found + $correctLen;

                    if ($hits !== null) {
                        $hits[$pairIndex] = ($hits[$pairIndex] ?? 0) + 1;
                    }
                } else {
                    $pos = $found + 1;
                }
            }
        }

        return $text;
    }

    /**
     * Wrapper de compatibilidad: prepara los pares y delega a applyTextWithPairs.
     * Mantener para callers externos y preview que no necesitan hoisting
     * (pocos segmentos, no afecta).
     */
    private function applyText(string $text, \Illuminate\Support\Collection $corrections): string
    {
        return $this->applyTextWithPairs($text, $this->preparePairs($corrections));
    }

    /**
     * ¿El par wrong->correct es una traducción inglés->español en vez de una
     * corrección de español?
     *
     * Guardrail contra la regresión de 2026-08-11: `corrections:ai-suggest` y
     * `corrections:mine-en-es` generaron 2.550 reglas de traducción palabra por
     * palabra que se auto-aprobaban en risk_level='low' y degradaban el texto
     * ("The cooperativas are dotadas" -> "la cooperativas están dotadas").
     */
    /**
     * ¿Se le aplica el diccionario a esta transcripción?
     *
     * Es `false` para los canales marcados con `apply_corrections = false`. A
     * diferencia de excludedTranscriptionIds(), esto resuelve un solo caso y no
     * recorre la tabla: sirve para el camino de ingesta, donde ya se tiene la
     * transcripción delante.
     */
    public function appliesToTranscription(Transcription $transcription): bool
    {
        $slug = ChannelSlug::fromFilename($transcription->original_name);

        if ($slug === null) {
            return true;
        }

        return !in_array($slug, ChannelLanguage::excludedSlugs(), true);
    }

    /**
     * IDs de transcripción cuyos segmentos no debe tocar el diccionario, como
     * conjunto indexado para poder consultarlo con isset() dentro del bucle.
     *
     * Son los canales marcados con `apply_corrections = false`: Teleislas emite
     * en criollo raizal y hay emisoras que pinchan música en inglés. Sus
     * transcripciones son correctas, y "corregirlas" es lo que producía
     * espanglish donde no había ningún error.
     *
     * @return array<int, bool>
     */
    private function excludedTranscriptionIds(): array
    {
        $slugs = ChannelLanguage::excludedSlugs();

        if (empty($slugs)) {
            return [];
        }

        $excluded = array_flip($slugs);
        $ids = [];

        // transcriptions son ~195k filas y solo se leen dos columnas. El slug se
        // deriva en PHP porque la regla cubre dos convenciones de nombre y no se
        // expresa con un LIKE sin arriesgar falsos positivos.
        Transcription::query()
            ->whereNotNull('original_name')
            ->select(['id', 'original_name'])
            ->chunkById(5000, function ($chunk) use (&$ids, $excluded) {
                foreach ($chunk as $row) {
                    $slug = ChannelSlug::fromFilename($row->original_name);

                    if ($slug !== null && isset($excluded[$slug])) {
                        $ids[$row->id] = true;
                    }
                }
            });

        return $ids;
    }

    /**
     * ¿La frase es demasiado corta para que un productor automático la proponga?
     *
     * Decisión del 2026-08-13: por debajo de tres palabras la regla dispara en
     * todo el corpus sin contexto y produce espanglish (`of love`->`de love`).
     * Solo aplica a los productores; el alta manual del admin no pasa por aquí.
     */
    private function isTooShortToPropose(string $wrong): bool
    {
        $words = preg_split('/\s+/u', trim($wrong), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return count($words) < (int) config('corrections.min_suggestion_words', 3);
    }

    private function isEnEsTranslation(string $wrong, string $correct): bool
    {
        $bucket = app(EnEsRuleClassifier::class)->classify($wrong, $correct)['bucket'];

        // NOISE entra aquí porque comparte destino con QUARANTINE —no debe
        // llegar al diccionario— aunque el motivo sea otro: una regla que no
        // cambia nada solo añade trabajo al recorrido de 20,6M de segmentos.
        return in_array($bucket, [EnEsRuleClassifier::QUARANTINE, EnEsRuleClassifier::NOISE], true);
    }

    /**
     * Crea (o actualiza) una propuesta pending del cliente.
     */
    public function propose(User $by, string $wrong, string $correct, ?int $segmentId = null): Correction
    {
        $wrongNorm = Keyword::asciiLower(trim($wrong));
        $correct = trim($correct);

        if ($wrongNorm === '' || $correct === '') {
            throw new \InvalidArgumentException('wrong y correct no pueden estar vacíos.');
        }

        return DB::transaction(function () use ($by, $wrong, $correct, $wrongNorm, $segmentId) {
            // Si ya existe una approved para el mismo wrong_normalized, la propuesta
            // queda merged (no aporta nada nuevo al diccionario).
            $existingApproved = Correction::approved()
                ->where('wrong_normalized', $wrongNorm)
                ->first();

            if ($existingApproved) {
                return Correction::create([
                    'wrong_text' => $wrong,
                    'correct_text' => $correct,
                    'wrong_normalized' => $wrongNorm,
                    'status' => Correction::STATUS_MERGED,
                    'proposed_by' => $by->id,
                    'source_segment_id' => $segmentId,
                ]);
            }

            // upsert sobre pending existente del mismo wrong_normalized
            $existing = Correction::where('status', Correction::STATUS_PENDING)
                ->where('wrong_normalized', $wrongNorm)
                ->latest()
                ->first();

            if ($existing) {
                $existing->update([
                    'correct_text' => $correct,
                    'proposed_by' => $by->id,
                    'source_segment_id' => $segmentId,
                ]);
                return $existing->fresh();
            }

            return Correction::create([
                'wrong_text' => $wrong,
                'correct_text' => $correct,
                'wrong_normalized' => $wrongNorm,
                'status' => Correction::STATUS_PENDING,
                'proposed_by' => $by->id,
                'source_segment_id' => $segmentId,
            ]);
        });
    }

    /**
     * Edita una corrección pendiente. Re-normaliza `wrong_normalized` y
     * resuelve colisiones con approved/pending del mismo normalized:
     * - Si hay approved con el mismo normalized (otra id) → la fila editada
     *   pasa a merged.
     * - Si hay OTRA pending con el mismo normalized → upsert sobre esa
     *   fila (más reciente) y se borra la actual para evitar duplicados.
     * - Si no hay colisión → update in-place.
     *
     * (corrections-pending-edit-delete) Misma semántica que propose() al
     * colisionar, para que editar y proponer converjan en el mismo estado.
     */
    public function updatePending(Correction $correction, string $wrong, string $correct): Correction
    {
        $wrongNorm = Keyword::asciiLower(trim($wrong));
        $correct = trim($correct);

        if ($wrongNorm === '' || $correct === '') {
            throw new \InvalidArgumentException('wrong y correct no pueden estar vacíos.');
        }

        return DB::transaction(function () use ($correction, $wrong, $correct, $wrongNorm) {
            $existingApproved = Correction::approved()
                ->where('wrong_normalized', $wrongNorm)
                ->where('id', '!=', $correction->id)
                ->first();
            if ($existingApproved) {
                $correction->update([
                    'wrong_text' => $wrong,
                    'correct_text' => $correct,
                    'wrong_normalized' => $wrongNorm,
                    'status' => Correction::STATUS_MERGED,
                ]);
                return $correction->fresh();
            }

            $existingPending = Correction::where('status', Correction::STATUS_PENDING)
                ->where('wrong_normalized', $wrongNorm)
                ->where('id', '!=', $correction->id)
                ->latest()
                ->first();
            if ($existingPending) {
                $existingPending->update([
                    'wrong_text' => $wrong,
                    'correct_text' => $correct,
                    'wrong_normalized' => $wrongNorm,
                ]);
                $correction->delete();
                return $existingPending->fresh();
            }

            $correction->update([
                'wrong_text' => $wrong,
                'correct_text' => $correct,
                'wrong_normalized' => $wrongNorm,
            ]);
            return $correction->fresh();
        });
    }

    /**
     * Aprueba una pendiente. Si ya existe approved con el mismo wrong_normalized,
     * actualiza el correct_text de la existente y marca la propuesta como merged.
     */
    public function approve(Correction $correction, User $by): Correction
    {
        return DB::transaction(function () use ($correction, $by) {
            if ($correction->status !== Correction::STATUS_PENDING) {
                return $correction;
            }

            $existing = Correction::approved()
                ->where('wrong_normalized', $correction->wrong_normalized)
                ->where('id', '!=', $correction->id)
                ->first();

            if ($existing) {
                $existing->update([
                    'correct_text' => $correction->correct_text,
                    'approved_at' => now(),
                    'approved_by' => $by->id,
                ]);
                $correction->update([
                    'status' => Correction::STATUS_MERGED,
                    'approved_by' => $by->id,
                    'approved_at' => now(),
                ]);
                return $correction->fresh();
            }

            $correction->update([
                'status' => Correction::STATUS_APPROVED,
                'approved_by' => $by->id,
                'approved_at' => now(),
            ]);

            return $correction->fresh();
        });
    }

    public function reject(Correction $correction, User $by, ?string $reason = null): Correction
    {
        $correction->update([
            'status' => Correction::STATUS_REJECTED,
            'rejected_reason' => $reason,
        ]);

        return $correction->fresh();
    }

    /**
     * Admin agrega directo, status=approved (upsert por wrong_normalized).
     */
    public function upsertApproved(string $wrong, string $correct, User $by): Correction
    {
        $wrongNorm = Keyword::asciiLower(trim($wrong));
        $correct = trim($correct);

        if ($wrongNorm === '' || $correct === '') {
            throw new \InvalidArgumentException('wrong y correct no pueden estar vacíos.');
        }

        return DB::transaction(function () use ($by, $wrong, $correct, $wrongNorm) {
            $existing = Correction::approved()
                ->where('wrong_normalized', $wrongNorm)
                ->first();

            if ($existing) {
                $existing->update([
                    'correct_text' => $correct,
                    'approved_by' => $by->id,
                    'approved_at' => now(),
                ]);
                return $existing->fresh();
            }

            return Correction::create([
                'wrong_text' => $wrong,
                'correct_text' => $correct,
                'wrong_normalized' => $wrongNorm,
                'status' => Correction::STATUS_APPROVED,
                'proposed_by' => $by->id,
                'approved_by' => $by->id,
                'approved_at' => now(),
            ]);
        });
    }

    /**
     * Reaplica el diccionario approved a TranscriptionSegment en chunks
     * transaccionales, recalculando siempre desde `text_raw` (inmutable).
     *
     * Idempotente: solo escribe un segmento si el resultado difiere del `text`
     * ya almacenado, así que re-ejecutar sobre datos ya correctos no toca la BD
     * ni incrementa contadores. applies_count cuenta reemplazos REALES (con
     * frontera de palabra), atribuidos a la regla que los hizo.
     *
     * Scope:
     *   - daysBack = null (default) → TODOS los segments
     *   - daysBack = N              → solo segments creados en últimos N días
     *                                 (created_at >= now - N days)
     *
     * @param callable|null $progressCb fn(int $processed, int $total, int $updatedSoFar, int $lastId)
     *                                   receives a real segment-count after each chunk
     *                                   plus the running total of segments updated and
     *                                   the last id processed (last id is debug only).
     * @param int|null $chunkSize
     * @param bool $dryRun No toca la BD; retorna conteo estimado.
     * @param int|null $daysBack Si != null, filtra por created_at.
     * @param bool $includeHighRisk Si false (default), omite correcciones con
     *                              risk_level='high' (muletillas, falsos amigos)
     *                              para no distorsionar tono/contexto. Pasar
     *                              true solo si el admin quiere aplicar bulk.
     * @param int $sleepMs Pausa entre chunks en milisegundos. Freno para correr
     *                     reparaciones masivas sin saturar la BD en producción.
     * @param array<int, int> $transcriptionIds Si no está vacío, limita a esas
     *                     transcripciones (usa el índice transcription_id).
     * @param int|null $transcriptionFrom Id mínimo de transcripción (inclusive).
     *                     Es la vía para reparar "por días": los ids de
     *                     `transcriptions` son contiguos por fecha, y filtrar
     *                     así usa transcription_segments_transcription_id_index
     *                     en vez del full-scan que obliga `created_at`.
     * @param int|null $transcriptionTo Id máximo de transcripción (inclusive).
     * @param int|null $fromId Id mínimo de segment (inclusive). Va por PK, así
     *                     que permite trocear el histórico sin full-scan:
     *                     `created_at` NO tiene índice y filtrar por días
     *                     obliga a recorrer los 4,8 GB de la tabla.
     * @param int|null $toId Id máximo de segment (inclusive).
     * @return int número de segments actualizados
     */
    public function applyRetroactively(
        ?callable $progressCb = null,
        ?int $chunkSize = null,
        bool $dryRun = false,
        ?int $daysBack = null,
        bool $includeHighRisk = false,
        int $sleepMs = 0,
        array $transcriptionIds = [],
        ?int $fromId = null,
        ?int $toId = null,
        ?int $transcriptionFrom = null,
        ?int $transcriptionTo = null
    ): int {
        // null = usar corrections_chunk, que existia en config desde siempre y
        // no lo leia nadie: aqui y en previewRetroactive() estaba hardcodeado a
        // 500. Va por el servicio para que un override desde la UI tenga efecto.
        $chunkSize = $chunkSize ?? $this->chunkSize();

        $query = Correction::approved()
            ->orderByRaw('LENGTH(wrong_normalized) DESC');

        if (!$includeHighRisk) {
            $query->safe();
        }

        $corrections = $query->get(['id', 'wrong_normalized', 'correct_text']);

        // Query base: si daysBack, filtra por created_at; si no, todos.
        $base = TranscriptionSegment::query();
        if ($daysBack !== null && $daysBack > 0) {
            $base->where('created_at', '>=', now()->subDays($daysBack));
        }
        if (!empty($transcriptionIds)) {
            $base->whereIn('transcription_id', $transcriptionIds);
        }
        if ($transcriptionFrom !== null) {
            $base->where('transcription_id', '>=', $transcriptionFrom);
        }
        if ($transcriptionTo !== null) {
            $base->where('transcription_id', '<=', $transcriptionTo);
        }
        if ($fromId !== null) {
            $base->where('id', '>=', $fromId);
        }
        if ($toId !== null) {
            $base->where('id', '<=', $toId);
        }
        $total = (clone $base)->count();
        $updated = 0;
        $processed = 0;

        // Hoist: convertir la coleccion de correcciones a pares primitivos
        // UNA sola vez por corrida (no por segmento). Antes se hacia dentro de
        // applyText() en cada llamada, costando un array_map+filter+usort
        // por cada segment — ~214k veces para una corrida de 1 dia.
        $pairs = $this->preparePairs($corrections);

        // Para el chunkById necesitamos mantener el filtro daysBack.
        // Lo hacemos envolviendo la query en un closure.
        // Canales cuyo idioma no es el español: su inglés es correcto y el
        // diccionario no debe tocarlo. Se resuelve UNA vez y se filtra en PHP
        // dentro del bucle; un NOT IN con miles de ids sobre millones de filas
        // saldría más caro que el propio recorrido.
        $skipTranscriptions = $this->excludedTranscriptionIds();

        $base->chunkById($chunkSize, function ($chunk) use (&$updated, &$processed, $pairs, $progressCb, $total, $dryRun, $sleepMs, $skipTranscriptions) {
            $rows = [];
            $appliedByCorrection = [];

            foreach ($chunk as $segment) {
                if (isset($skipTranscriptions[$segment->transcription_id])) {
                    continue;
                }

                $raw = (string) $segment->text_raw;
                $hits = [];
                $corrected = $this->applyTextWithPairs($raw, $pairs, $hits);

                // Idempotencia real: comparar contra el `text` YA GUARDADO, no
                // contra text_raw. Antes se comparaba `$corrected !== $raw`, así
                // que cada re-ejecución reescribía todas las filas ya corregidas
                // y volvía a sumar applies_count, pese a lo que promete el
                // docblock. (Se sigue calculando DESDE text_raw, que es la
                // fuente de verdad inmutable.)
                if ($corrected === (string) $segment->text) {
                    continue;
                }

                $updated++;

                if ($dryRun) {
                    continue;
                }

                $rows[$segment->id] = $corrected;

                // Conteo por reemplazo REAL. Antes era
                // `substr_count($rawLower, $wrong)` para TODA regla presente como
                // subcadena, sin frontera de palabra y aplicara o no: por eso
                // 'the' marcaba 84.011 aciertos contando "the" dentro de palabras
                // españolas.
                foreach ($hits as $pairIndex => $count) {
                    $cid = $pairs[$pairIndex][2] ?? null;
                    if ($cid !== null) {
                        $appliedByCorrection[$cid] = ($appliedByCorrection[$cid] ?? 0) + $count;
                    }
                }
            }

            if (!$dryRun && (!empty($rows) || !empty($appliedByCorrection))) {
                DB::transaction(function () use ($rows, $appliedByCorrection) {
                    if (!empty($rows)) {
                        $now = now()->toDateTimeString();
                        $valuesSql = [];
                        $bindings = [];
                        foreach ($rows as $id => $text) {
                            $valuesSql[] = '(?::bigint, ?, ?::timestamp)';
                            $bindings[] = (int) $id;
                            $bindings[] = $text;
                            $bindings[] = $now;
                        }
                        $sql = 'UPDATE transcription_segments AS t '
                            . 'SET text = v.text, updated_at = v.updated_at '
                            . 'FROM (VALUES ' . implode(', ', $valuesSql) . ') AS v(id, text, updated_at) '
                            . 'WHERE t.id = v.id';
                        DB::update($sql, $bindings);
                    }

                    if (!empty($appliedByCorrection)) {
                        foreach ($appliedByCorrection as $correctionId => $count) {
                            DB::table('corrections')
                                ->where('id', $correctionId)
                                ->increment('applies_count', $count);
                        }
                    }
                });
            }

            $processed += $chunk->count();
            if ($progressCb) {
                $progressCb($processed, $total, $updated, $chunk->last()->id);
            }

            // Freno entre lotes: una reparación sobre el histórico completo
            // recorre millones de filas y puede ahogar la BD de producción.
            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        });

        return $updated;
    }

    /**
     * Cuenta cuántos segments serían modificados sin tocar la BD (preview).
     */
    public function previewRetroactive(): int
    {
        return $this->applyRetroactively(null, null, true);
    }

    /**
     * Orquesta el miner EN↔ES sobre el corpus y persiste los candidatos
     * detectados como correcciones pending (no approved). Cada candidato
     * se inserta con `source='mining-YYYY-MM-DD'` para distinguirlo de
     * propuestas manuales o seed.
     *
     * Idempotente: si ya existe una pending con el mismo wrong_normalized,
     * no la duplica. Si existe una approved, el miner ya la excluyó en
     * su filtro interno, así que tampoco llega aquí.
     *
     * Solo ejecuta la estrategia KNOWN (mapeos hardcoded curados). La
     * antigua estrategia "open" (bigramas inferidos) fue retirada, ver
     * change 2026-08-15-en-es-mix-miner-prune-open-strategy.
     *
     * @return array{
     *   mined:int, inserted:int, skipped_duplicate:int,
     *   candidates:array<int, array{wrong:string, correct:string, freq:int, strategy:string}>,
     *   source:string
     * }
     */
    public function mineEnEsMix(int $daysBack, int $minFreq, User $by): array
    {
        $miner = new EnEsMixMiner();
        $candidates = $miner->mine($daysBack, $minFreq);

        $source = 'mining-' . now()->toDateString();
        $inserted = 0;
        $skipped = 0;

        $rejectedEnEs = 0;

        foreach ($candidates as $candidate) {
            if ($this->isEnEsTranslation($candidate['wrong'], $candidate['correct'])) {
                $rejectedEnEs++;
                continue;
            }

            if ($this->isTooShortToPropose($candidate['wrong'])) {
                $rejectedEnEs++;
                continue;
            }

            $existingPending = Correction::pending()
                ->where('wrong_normalized', Keyword::asciiLower($candidate['wrong']))
                ->exists();
            if ($existingPending) {
                $skipped++;
                continue;
            }

            // Lookup del segmento origen (changes/2026-08-12-corrections-pending-segment-context).
            // +1 query por candidato; toleramos null si no hay match.
            $segmentId = $miner->lookupSourceSegmentId($candidate['wrong']);

            $correction = $this->propose($by, $candidate['wrong'], $candidate['correct'], $segmentId);
            $correction->source = $source;
            $correction->save();
            $inserted++;
        }

        return [
            'mined' => count($candidates),
            'inserted' => $inserted,
            'skipped_duplicate' => $skipped,
            'rejected_en_es' => $rejectedEnEs,
            'candidates' => $candidates,
            'source' => $source,
        ];
    }

    /**
     * Orquesta el suggester LLM-powered EN↔ES y persiste los candidatos
     * detectados como correcciones pending. Complementa al miner rule-based:
     * captura el long-tail contextual que la heurística no ve.
     *
     * Defensa-en-profundidad: el LlmCorrectionSuggester ya aplica su propio
     * filtro de marcas antes de retornar. Aquí solo verificamos duplicados
     * contra pending existentes.
     *
     * Idempotente por dos vías:
     *   - cache de segmentos procesados hoy (TTL 25h, en el suggester)
     *   - filter de pending existentes (wrong_normalized) aquí
     *
     * @return array{
     *   mined:int, inserted:int, skipped_duplicate:int, rejected_by_filter:int,
     *   segments_processed:int, cached_today:int, source:string
     * }
     */
    public function aiSuggestEnEsMix(int $days, int $sampleSize, User $by, bool $autoApprove = false): array
    {
        $suggester = new LlmCorrectionSuggester();
        $result = $suggester->suggest($days, $sampleSize);

        if (isset($result['error']) && empty($result['candidates'])) {
            return [
                'mined' => 0,
                'inserted' => 0,
                'auto_approved' => 0,
                'skipped_duplicate' => 0,
                'rejected_by_filter' => count($result['rejected_by_filter'] ?? []),
                'segments_processed' => 0,
                'cached_today' => $result['cached_today'] ?? 0,
                'source' => $result['source'] ?? ('ai-suggest-' . now()->toDateString()),
                'error' => $result['error'],
            ];
        }

        $source = $result['source'];
        $inserted = 0;
        $autoApproved = 0;
        $skipped = 0;
        $rejectedEnEs = 0;

        foreach ($result['candidates'] as $candidate) {
            // Guardrail: un par que solo traduce del inglés no entra ni como
            // pending. Es la fábrica que produjo las 2.550 reglas puestas en
            // cuarentena el 2026-08-11 (the->la, are->están, where->donde).
            if ($this->isEnEsTranslation($candidate['wrong'], $candidate['correct'])) {
                $rejectedEnEs++;
                continue;
            }

            if ($this->isTooShortToPropose($candidate['wrong'])) {
                $rejectedEnEs++;
                continue;
            }

            $existingPending = Correction::pending()
                ->where('wrong_normalized', Keyword::asciiLower($candidate['wrong']))
                ->exists();
            if ($existingPending) {
                $skipped++;
                continue;
            }

            // Lookup del segmento origen (changes/2026-08-12-corrections-pending-segment-context).
            // +1 query por candidato; toleramos null si no hay match.
            $segmentId = $suggester->lookupSourceSegmentId($candidate['wrong']);

            // Sin auto-aprobación: todo candidato entra como pending y lo
            // aprueba un admin desde /ia/correcciones. La auto-aprobación en
            // risk_level='low' es lo que dejó que 205.467 aplicaciones de
            // reglas de traducción llegaran a producción sin que nadie las
            // mirara. $autoApprove se conserva en la firma por compatibilidad
            // con los callers, pero ya no promueve nada.
            $correction = $this->propose($by, $candidate['wrong'], $candidate['correct'], $segmentId);
            $correction->source = $source;
            $correction->save();
            $inserted++;
        }

        return [
            'mined' => count($result['candidates']),
            'inserted' => $inserted,
            'auto_approved' => $autoApproved,
            'skipped_duplicate' => $skipped,
            'rejected_en_es' => $rejectedEnEs,
            'rejected_by_filter' => count($result['rejected_by_filter'] ?? []),
            'segments_processed' => $result['segments_processed'] ?? 0,
            'cached_today' => $result['cached_today'] ?? 0,
            'source' => $source,
        ];
    }

    /**
     * Inserta (o upsert sobre pending existente) una corrección con
     * status=approved directamente, sin pasar por el paso intermedio de
     * `propose() → bulk_approve`. Diseñado para que el suggester LLM-powered
     * (corrections:ai-suggest) auto-apruebe sus hallazgos y las correcciones
     * fluyan automáticamente a SRT nuevos + retroactivo manual.
     *
     * Mismas reglas de idempotencia que propose(): si ya existe un approved
     * con el mismo wrong_normalized, se ignora (no se duplica).
     *
     * Reversibilidad: el admin puede eliminar la fila desde
     * `/ia/correcciones → Aprobadas → Eliminar`. No usa bulk_action_id
     * porque la auto-aprobación es sistemática (no masiva intencional);
     * si el admin quiere revertir una corrida entera, lo hace filtrando
     * por source='ai-suggest-YYYY-MM-DD'.
     *
     * @param User $by usuario invocante (admin)
     * @param string $wrong texto a corregir (case-insensitive)
     * @param string $correct texto corregido
     * @param string $source origen del lote (ej: 'ai-suggest-2026-08-01')
     * @return Correction modelo recién insertado
     */
    public function upsertApprovedBySystem(User $by, string $wrong, string $correct, string $source): Correction
    {
        $wrongNorm = Keyword::asciiLower(trim($wrong));
        $correct = trim($correct);

        if ($wrongNorm === '' || $correct === '') {
            throw new \InvalidArgumentException('wrong y correct no pueden estar vacíos.');
        }

        // Última puerta antes de que algo entre ya aprobado: una traducción
        // EN->ES nunca debe saltarse la moderación humana.
        if ($this->isEnEsTranslation($wrong, $correct)) {
            throw new \InvalidArgumentException(
                "Rechazado: '{$wrong}' -> '{$correct}' es una traducción EN->ES. "
                . 'El diccionario corrige español, no traduce; usa propose() para que lo revise un admin.'
            );
        }

        return DB::transaction(function () use ($by, $wrong, $correct, $wrongNorm, $source) {
            // Si ya existe approved, NO crear (idempotencia). Devolver la existente.
            $existingApproved = Correction::approved()
                ->where('wrong_normalized', $wrongNorm)
                ->first();
            if ($existingApproved) {
                return $existingApproved;
            }

            // Si existe pending, promovernos a approved en vez de duplicar.
            $existingPending = Correction::where('status', Correction::STATUS_PENDING)
                ->where('wrong_normalized', $wrongNorm)
                ->latest()
                ->first();
            if ($existingPending) {
                $existingPending->update([
                    'status' => Correction::STATUS_APPROVED,
                    'correct_text' => $correct,
                    'approved_by' => $by->id,
                    'approved_at' => now(),
                    'source' => $source,
                ]);
                return $existingPending->fresh();
            }

            return Correction::create([
                'wrong_text' => $wrong,
                'correct_text' => $correct,
                'wrong_normalized' => $wrongNorm,
                'status' => Correction::STATUS_APPROVED,
                'proposed_by' => $by->id,
                'approved_by' => $by->id,
                'approved_at' => now(),
                'source' => $source,
            ]);
        });
    }

    /**
     * Inserta candidatos ya previsualizados sin volver a llamar al LLM.
     *
     * Diseñado para que el botón "Insertar como pendiente" del modal del
     * AI Suggest sea **instantáneo**: el admin ya vio los candidatos en
     * la tabla del modal, ahora confirma y los persistimos tal cual.
     *
     * Idempotente: filtra duplicados contra pending y approved antes
     * de insertar (mismas reglas que aiSuggestEnEsMix). Solo ejecuta
     * BD writes (propose() + save()), sin network calls ni delays.
     *
     * @param array<int, array{wrong:string, correct:string}> $candidates
     *        Output típico de `LlmCorrectionSuggester::suggest()`.
     * @param string $source Origen del lote (ej: 'ai-suggest-2026-08-01').
     *
     * @return array{
     *   inserted:int,
     *   skipped_duplicate:int,
     *   skipped_empty:int,
     *   source:string
     * }
     */
    public function saveAiSuggestedCandidates(array $candidates, string $source, User $by): array
    {
        $inserted = 0;
        $skippedDup = 0;
        $skippedEmpty = 0;

        foreach ($candidates as $candidate) {
            $wrong = trim((string) ($candidate['wrong'] ?? ''));
            $correct = trim((string) ($candidate['correct'] ?? ''));

            if ($wrong === '' || $correct === '') {
                $skippedEmpty++;
                continue;
            }

            $existingPending = Correction::pending()
                ->where('wrong_normalized', Keyword::asciiLower($wrong))
                ->exists();
            if ($existingPending) {
                $skippedDup++;
                continue;
            }

            if (Correction::approved()
                ->where('wrong_normalized', Keyword::asciiLower($wrong))
                ->exists()) {
                $skippedDup++;
                continue;
            }

            $correction = $this->propose($by, $wrong, $correct);
            $correction->source = $source;
            $correction->save();
            $inserted++;
        }

        return [
            'inserted' => $inserted,
            'skipped_duplicate' => $skippedDup,
            'skipped_empty' => $skippedEmpty,
            'source' => $source,
        ];
    }

    /**
     * Marca como superseded todas las bulk actions activas del admin
     * excepto la que se pasa por id. Llamar ANTES de crear una nueva
     * bulk action para garantizar que solo la última tenga undo activo.
     */
    private function markPreviousBulkActionsSuperseded(User $by, string $excludeId): void
    {
        CorrectionBulkAction::where('performed_by', $by->id)
            ->whereNull('undone_at')
            ->whereNull('superseded_at')
            ->where('id', '!=', $excludeId)
            ->update(['superseded_at' => now()]);
    }

    /**
     * Crea un CorrectionBulkAction con un ULID y expiración según config.
     */
    private function createBulkAction(string $action, User $by, ?string $notes = null): CorrectionBulkAction
    {
        $id = (string) Str::ulid();
        $bulkAction = CorrectionBulkAction::create([
            'id' => $id,
            'action' => $action,
            'performed_by' => $by->id,
            'performed_at' => now(),
            'expires_at' => now()->addMinutes((int) config('corrections.undo_window_minutes', 5)),
            'item_count' => 0,
            'notes' => $notes,
        ]);
        $this->markPreviousBulkActionsSuperseded($by, $id);
        return $bulkAction;
    }

    /**
     * Aprueba múltiples correcciones pendientes en una sola acción.
     * Cada item se aprueba individualmente (con su propia transacción).
     * Crea un CorrectionBulkAction que permite deshacer la operación
     * dentro de la ventana configurada (default 5 minutos).
     *
     * @param array $ids
     * @param User $by
     * @return array {
     *   approved: int, merged: int, errors: [{id, message}],
     *   bulk_action_id: string, undo_expires_at: ISO8601
     * }
     */
    public function bulkApprove(array $ids, User $by): array
    {
        $bulkAction = $this->createBulkAction(CorrectionBulkAction::ACTION_BULK_APPROVE, $by);

        $approved = 0;
        $merged = 0;
        $errors = [];

        foreach ($ids as $id) {
            try {
                $correction = Correction::find($id);
                if (!$correction) {
                    CorrectionBulkActionItem::create([
                        'bulk_action_id' => $bulkAction->id,
                        'correction_id' => (int) $id,
                        'previous_status' => 'unknown',
                        'applied' => false,
                    ]);
                    $errors[] = ['id' => $id, 'message' => 'no existe'];
                    continue;
                }
                if ($correction->status !== Correction::STATUS_PENDING) {
                    CorrectionBulkActionItem::create([
                        'bulk_action_id' => $bulkAction->id,
                        'correction_id' => $correction->id,
                        'previous_status' => $correction->status,
                        'applied' => false,
                    ]);
                    $errors[] = ['id' => $id, 'message' => 'no está pendiente (status=' . $correction->status . ')'];
                    continue;
                }

                // Snapshot ANTES de approve: si hay merge, guardar el estado del merge target.
                $existingApproved = Correction::approved()
                    ->where('wrong_normalized', $correction->wrong_normalized)
                    ->where('id', '!=', $correction->id)
                    ->first();

                CorrectionBulkActionItem::create([
                    'bulk_action_id' => $bulkAction->id,
                    'correction_id' => $correction->id,
                    'previous_status' => Correction::STATUS_PENDING,
                    'merge_target_id' => $existingApproved?->id,
                    'merge_previous_correct_text' => $existingApproved?->correct_text,
                    'applied' => true,
                ]);

                $result = $this->approve($correction, $by);

                if ($result->status === Correction::STATUS_MERGED) $merged++;
                else $approved++;
            } catch (\Throwable $e) {
                $errors[] = ['id' => $id, 'message' => $e->getMessage()];
            }
        }

        $bulkAction->update(['item_count' => $approved + $merged]);

        return [
            'approved' => $approved,
            'merged' => $merged,
            'errors' => $errors,
            'bulk_action_id' => $bulkAction->id,
            'undo_expires_at' => $bulkAction->expires_at->toIso8601String(),
        ];
    }

    /**
     * Rechaza múltiples correcciones pendientes con un motivo común.
     *
     * @param array $ids
     * @param string|null $reason
     * @param User $by
     * @return array {
     *   rejected: int, errors: [{id, message}],
     *   bulk_action_id: string, undo_expires_at: ISO8601
     * }
     */
    public function bulkReject(array $ids, ?string $reason, User $by): array
    {
        $bulkAction = $this->createBulkAction(
            CorrectionBulkAction::ACTION_BULK_REJECT,
            $by,
            $reason
        );

        $rejected = 0;
        $errors = [];

        foreach ($ids as $id) {
            try {
                $correction = Correction::find($id);
                if (!$correction) {
                    CorrectionBulkActionItem::create([
                        'bulk_action_id' => $bulkAction->id,
                        'correction_id' => (int) $id,
                        'previous_status' => 'unknown',
                        'applied' => false,
                    ]);
                    $errors[] = ['id' => $id, 'message' => 'no existe'];
                    continue;
                }
                if ($correction->status !== Correction::STATUS_PENDING) {
                    CorrectionBulkActionItem::create([
                        'bulk_action_id' => $bulkAction->id,
                        'correction_id' => $correction->id,
                        'previous_status' => $correction->status,
                        'applied' => false,
                    ]);
                    $errors[] = ['id' => $id, 'message' => 'no está pendiente (status=' . $correction->status . ')'];
                    continue;
                }

                CorrectionBulkActionItem::create([
                    'bulk_action_id' => $bulkAction->id,
                    'correction_id' => $correction->id,
                    'previous_status' => Correction::STATUS_PENDING,
                    'applied' => true,
                ]);

                $this->reject($correction, $by, $reason);
                $rejected++;
            } catch (\Throwable $e) {
                $errors[] = ['id' => $id, 'message' => $e->getMessage()];
            }
        }

        $bulkAction->update(['item_count' => $rejected]);

        return [
            'rejected' => $rejected,
            'errors' => $errors,
            'bulk_action_id' => $bulkAction->id,
            'undo_expires_at' => $bulkAction->expires_at->toIso8601String(),
        ];
    }

    /**
     * Elimina múltiples correcciones aprobadas en una sola acción.
     * Guarda un snapshot completo (json) para auditoría. NO es reversible
     * vía undoBulkAction (solo bulk_approve y bulk_reject son reversibles).
     *
     * @param array $ids
     * @param User $by
     * @return array {
     *   deleted: int, errors: [{id, message}],
     *   bulk_action_id: string, undo_expires_at: ISO8601|null
     * }
     */
    public function bulkDestroy(array $ids, User $by): array
    {
        $bulkAction = $this->createBulkAction(CorrectionBulkAction::ACTION_BULK_DESTROY, $by);
        // bulk_destroy NO es reversible: expiración inmediata (1 min) para limpieza rápida del log.
        $bulkAction->update(['expires_at' => now()->addMinute()]);

        $deleted = 0;
        $errors = [];

        foreach ($ids as $id) {
            try {
                $correction = Correction::find($id);
                if (!$correction) {
                    $errors[] = ['id' => $id, 'message' => 'no existe'];
                    continue;
                }
                if ($correction->status !== Correction::STATUS_APPROVED) {
                    $errors[] = ['id' => $id, 'message' => 'no está aprobada (status=' . $correction->status . ')'];
                    continue;
                }

                // Snapshot completo antes de DELETE.
                $snapshot = [
                    'wrong_text' => $correction->wrong_text,
                    'correct_text' => $correction->correct_text,
                    'wrong_normalized' => $correction->wrong_normalized,
                    'status' => $correction->status,
                    'proposed_by' => $correction->proposed_by,
                    'approved_by' => $correction->approved_by,
                    'approved_at' => $correction->approved_at?->toIso8601String(),
                    'rejected_reason' => $correction->rejected_reason,
                    'source_segment_id' => $correction->source_segment_id,
                    'applies_count' => $correction->applies_count,
                    'source' => $correction->source,
                ];

                CorrectionBulkActionItem::create([
                    'bulk_action_id' => $bulkAction->id,
                    'correction_id' => $correction->id,
                    'previous_status' => Correction::STATUS_APPROVED,
                    'destroy_snapshot' => $snapshot,
                    'applied' => true,
                ]);

                $correction->delete();
                $deleted++;
            } catch (\Throwable $e) {
                $errors[] = ['id' => $id, 'message' => $e->getMessage()];
            }
        }

        $bulkAction->update(['item_count' => $deleted]);

        return [
            'deleted' => $deleted,
            'errors' => $errors,
            'bulk_action_id' => $bulkAction->id,
            // null indica que esta acción NO es reversible.
            'undo_expires_at' => null,
        ];
    }

    /**
     * Elimina múltiples correcciones PENDIENTES en una sola acción.
     * No es bulk-rechazar: es bulk-eliminar del ruido del miner/AI Suggest
     * (corrections-pending-edit-delete). Diferencias vs bulkDestroy:
     *  - Solo acepta status=pending. Aprobadas/rechazadas se rechazan con error.
     *  - NO guarda snapshot (las pending nunca fueron aplicadas, no hay undo).
     *  - NO usa la ventana de undo (acción irreversible e instantánea).
     *
     * Registra CorrectionBulkAction igual que bulkDestroy para mantener
     * la trazabilidad operativa ("quién eliminó qué y cuándo"), pero el
     * `undo_expires_at` siempre es null.
     *
     * @return array{deleted: int, errors: array, bulk_action_id: string, undo_expires_at: null}
     */
    public function bulkDestroyPending(array $ids, User $by): array
    {
        $bulkAction = $this->createBulkAction(CorrectionBulkAction::ACTION_BULK_DESTROY, $by);
        // bulk_destroy_pending NO es reversible: expiración inmediata (1 min).
        $bulkAction->update(['expires_at' => now()->addMinute()]);

        $deleted = 0;
        $errors = [];

        foreach ($ids as $id) {
            try {
                $correction = Correction::find($id);
                if (!$correction) {
                    $errors[] = ['id' => $id, 'message' => 'no existe'];
                    continue;
                }
                if ($correction->status !== Correction::STATUS_PENDING) {
                    $errors[] = ['id' => $id, 'message' => 'no está pendiente (status=' . $correction->status . ')'];
                    continue;
                }

                CorrectionBulkActionItem::create([
                    'bulk_action_id' => $bulkAction->id,
                    'correction_id' => $correction->id,
                    'previous_status' => Correction::STATUS_PENDING,
                    'applied' => true,
                ]);

                $correction->delete();
                $deleted++;
            } catch (\Throwable $e) {
                $errors[] = ['id' => $id, 'message' => $e->getMessage()];
            }
        }

        $bulkAction->update(['item_count' => $deleted]);

        return [
            'deleted' => $deleted,
            'errors' => $errors,
            'bulk_action_id' => $bulkAction->id,
            'undo_expires_at' => null,
        ];
    }

    /**
     * Elimina en bulk reglas inactivas (applies_count=0) creadas hace más de N días.
     * Cambios/2026-08-02-corrections-dictionary-atomicity.
     *
     * Cap defensivo: $maxCount previene borrado accidental de miles en una sola corrida.
     * Reutiliza bulkDestroy() para que la acción quede registrada en correction_bulk_actions
     * (snapshot completo + bulk_action_id para auditoría), aunque NO es reversible.
     *
     * @param int $minAgeDays  Edad mínima en días (default 30). Solo borra reglas con created_at <= now - N días.
     * @param int $maxCount    Tope defensivo de IDs a procesar (default 500).
     * @param User $by         Admin que ejecuta la acción.
     * @return array{deleted: int, errors: array, bulk_action_id: ?string, undo_expires_at: ?string, message?: string}
     */
    public function bulkDestroyInactive(int $minAgeDays, int $maxCount, User $by): array
    {
        $candidates = Correction::approved()
            ->where('applies_count', 0)
            ->where('created_at', '<=', now()->subDays($minAgeDays))
            ->orderBy('id')
            ->limit($maxCount)
            ->pluck('id')
            ->all();

        if (empty($candidates)) {
            return [
                'deleted' => 0,
                'errors' => [],
                'bulk_action_id' => null,
                'undo_expires_at' => null,
                'message' => "No hay reglas inactivas con más de {$minAgeDays} días.",
            ];
        }

        $result = $this->bulkDestroy($candidates, $by);
        // bulkDestroy retorna 'deleted'; normalizamos a 'destroyed' para consistencia con el endpoint.
        return [
            'destroyed' => $result['deleted'],
            'deleted' => $result['deleted'],
            'errors' => $result['errors'],
            'bulk_action_id' => $result['bulk_action_id'],
            'undo_expires_at' => $result['undo_expires_at'],
        ];
    }

    /**
     * Extrae sugerencias atómicas (unigramas + bigramas) del wrong_text de una
     * corrección aprobada, deduplicadas contra el diccionario existente y con
     * traducción tentativa basada en consenso (≥80% → confidence=high).
     * Cambios/2026-08-02-corrections-dictionary-atomicity.
     *
     * @param Correction $c
     * @param int $maxResults Tope total de candidatos a devolver (default 20).
     * @return array{
     *   unigrams: array<int, array{wrong: string, suggested_correct: ?string, occurrences_in_corrections: int, confidence: string, already_in_dict: bool}>,
     *   bigrams:  array<int, array{wrong: string, suggested_correct: ?string, occurrences_in_corrections: int, confidence: string, already_in_dict: bool}>,
     *   already_in_dict_unigrams: array<int, string>,
     *   already_in_dict_bigrams:  array<int, string>
     * }
     */
    public function extractAtomicitySuggestions(Correction $c, int $maxResults = 20): array
    {
        $wrongNorm = mb_strtolower((string) $c->wrong_text);
        $tokens = preg_split('/[\s\p{P}]+/u', $wrongNorm, -1, PREG_SPLIT_NO_EMPTY);
        $tokens = is_array($tokens) ? $tokens : [];
        $tokens = array_filter($tokens, fn ($t) => mb_strlen($t) >= self::ATOMICITY_MIN_TOKEN_LENGTH);
        $tokens = array_values(array_filter($tokens, fn ($t) => !in_array($t, self::ATOMICITY_STOPWORDS, true)));

        // Unigramas únicos
        $unigrams = array_values(array_unique($tokens));
        // Bigramas: pares consecutivos (sin stopwords, filtrados arriba)
        $bigrams = [];
        for ($i = 0; $i < count($tokens) - 1; $i++) {
            $bigrams[] = $tokens[$i] . ' ' . $tokens[$i + 1];
        }
        $bigrams = array_values(array_unique($bigrams));

        // Cargar approved existentes para dedupe y para encontrar traducciones tentativas
        $existing = Correction::approved()
            ->select('wrong_text', 'correct_text', 'wrong_normalized')
            ->get();

        $existingNorms = [];
        $existingByNorm = [];
        foreach ($existing as $e) {
            $existingNorms[] = (string) $e->wrong_normalized;
            $existingByNorm[(string) $e->wrong_normalized] = (string) $e->correct_text;
        }

        $buildCandidate = function (string $term) use ($existing) {
            $isInDict = false;
            $occurrences = 0;
            $byCorrect = [];

            foreach ($existing as $e) {
                $w = mb_strtolower((string) $e->wrong_text);
                // Match: la corrección approved CONTIENE el término como substring.
                // (Heurística barata; para producción se podría usar FULLTEXT).
                if (preg_match('/(^|\b)' . preg_quote($term, '/') . '(\b|$)/i', $w)) {
                    $occurrences++;
                    $byCorrect[(string) $e->correct_text] = ($byCorrect[(string) $e->correct_text] ?? 0) + 1;
                }
            }

            // Dedupe contra standalone: si existe corrección con wrong_normalized == term, ya está.
            $norm = \App\Models\Keyword::asciiLower(trim($term));
            $isInDict = in_array($norm, array_map(fn ($n) => (string) $n, $existing->pluck('wrong_normalized')->all()), true);

            $top = null;
            $topCount = 0;
            foreach ($byCorrect as $correct => $cnt) {
                if ($cnt > $topCount) {
                    $top = (string) $correct;
                    $topCount = $cnt;
                }
            }

            $confidence = 'low';
            $suggested = null;
            if ($top !== null && $occurrences > 0 && ($topCount / $occurrences) >= self::ATOMICITY_CONFIDENCE_THRESHOLD) {
                $confidence = 'high';
                $suggested = $top;
            }

            return [
                'wrong' => $term,
                'suggested_correct' => $suggested,
                'occurrences_in_corrections' => $occurrences,
                'confidence' => $confidence,
                'already_in_dict' => $isInDict,
            ];
        };

        $unigramResults = array_map($buildCandidate, $unigrams);
        $bigramResults = array_map($buildCandidate, $bigrams);

        // Separar already_in_dict para feedback
        $alreadyUnigrams = array_values(array_map(
            fn ($c) => $c['wrong'],
            array_filter($unigramResults, fn ($c) => $c['already_in_dict'])
        ));
        $alreadyBigrams = array_values(array_map(
            fn ($c) => $c['wrong'],
            array_filter($bigramResults, fn ($c) => $c['already_in_dict'])
        ));

        // Cap a maxResults priorizando NO-already_in_dict y por ocurrencias
        $unigramResults = array_values(array_filter($unigramResults, fn ($c) => !$c['already_in_dict']));
        $bigramResults = array_values(array_filter($bigramResults, fn ($c) => !$c['already_in_dict']));

        $sortByOccurrences = function (&$arr) {
            usort($arr, fn ($a, $b) => $b['occurrences_in_corrections'] <=> $a['occurrences_in_corrections']);
        };
        $sortByOccurrences($unigramResults);
        $sortByOccurrences($bigramResults);

        // Distribuir el cap entre unigramas y bigramas (60/40)
        $unigramCap = (int) ceil($maxResults * 0.6);
        $bigramCap = $maxResults - $unigramCap;
        $unigramResults = array_slice($unigramResults, 0, $unigramCap);
        $bigramResults = array_slice($bigramResults, 0, $bigramCap);

        return [
            'unigrams' => $unigramResults,
            'bigrams' => $bigramResults,
            'already_in_dict_unigrams' => $alreadyUnigrams,
            'already_in_dict_bigrams' => $alreadyBigrams,
        ];
    }

    /**
     * Revierte una bulk action. Solo bulk_approve y bulk_reject son
     * reversibles. Para bulk_destroy retorna excepción.
     *
     * @param string $bulkActionId
     * @param User $by
     * @return array {undone: true, bulk_action_id, items_restored}
     * @throws \RuntimeException si no se puede deshacer
     */
    public function undoBulkAction(string $bulkActionId, User $by): array
    {
        $bulkAction = CorrectionBulkAction::find($bulkActionId);
        if (!$bulkAction) {
            throw new \RuntimeException('Bulk action no encontrada.');
        }
        if ($bulkAction->action === CorrectionBulkAction::ACTION_BULK_DESTROY) {
            throw new \RuntimeException('bulk_destroy no es reversible.');
        }
        if ($bulkAction->isUndone()) {
            throw new \RuntimeException('Esta acción ya fue revertida.');
        }
        if ($bulkAction->isSuperseded()) {
            throw new \RuntimeException('Esta acción ya no se puede revertir (fue superada por otra).');
        }
        if ($bulkAction->isExpired()) {
            throw new \RuntimeException('La ventana de undo expiró.');
        }

        return DB::transaction(function () use ($bulkAction, $by) {
            $items = CorrectionBulkActionItem::where('bulk_action_id', $bulkAction->id)
                ->where('applied', true)
                ->get();

            $restored = 0;
            foreach ($items as $item) {
                $correction = Correction::find($item->correction_id);
                if (!$correction) continue; // ya no existe, skip silenciosamente

                if ($bulkAction->action === CorrectionBulkAction::ACTION_BULK_APPROVE) {
                    // Restaurar status a pending (cubre plain approve Y merge)
                    $correction->update([
                        'status' => Correction::STATUS_PENDING,
                        'approved_by' => null,
                        'approved_at' => null,
                    ]);

                    // Si hubo merge target, restaurar el correct_text original.
                    if ($item->merge_target_id) {
                        $target = Correction::find($item->merge_target_id);
                        if ($target && $item->merge_previous_correct_text !== null) {
                            $target->update([
                                'correct_text' => $item->merge_previous_correct_text,
                                'approved_at' => $bulkAction->performed_at,
                                'approved_by' => $bulkAction->performed_by,
                            ]);
                        }
                    }
                } elseif ($bulkAction->action === CorrectionBulkAction::ACTION_BULK_REJECT) {
                    $correction->update([
                        'status' => Correction::STATUS_PENDING,
                        'rejected_reason' => null,
                    ]);
                }

                $restored++;
            }

            $bulkAction->update([
                'undone_at' => now(),
                'undone_by' => $by->id,
            ]);

            return [
                'undone' => true,
                'bulk_action_id' => $bulkAction->id,
                'items_restored' => $restored,
            ];
        });
    }

    /**
     * Propone un par aprendido del pase de coherencia IA como corrección pending.
     *
     * (changes/2026-08-15-corrections-learn-from-ai-pass) El pase IA corrige
     * spanglish que el diccionario no cubre; cada corrección es conocimiento
     * que se pierde. Este método lo captura como `pending` para que el admin
     * lo apruebe y entre al diccionario, reduciendo la carga de IA con el tiempo.
     *
     * Idempotente: si ya existe pending/approved con el mismo wrong_normalized,
     * no duplica y retorna null.
     *
     * @return Correction|null  la corrección creada, o null si ya existía o era de baja calidad
     */
    public function proposeLearned(string $wrong, string $correct, ?int $segmentId = null): ?Correction
    {
        $wrong = trim($wrong);
        $correct = trim($correct);
        $wrongNorm = Keyword::asciiLower($wrong);

        if ($wrong === '' || $correct === '' || $wrong === $correct) {
            return null;
        }

        // Idempotencia: no duplicar si ya existe pending o approved.
        $exists = Correction::where('wrong_normalized', $wrongNorm)
            ->whereIn('status', [Correction::STATUS_PENDING, Correction::STATUS_APPROVED])
            ->exists();
        if ($exists) {
            return null;
        }

        // Filtro de calidad: no proponer segmentos enteros (> 4 palabras).
        if (str_word_count($wrong, 0, 'áéíóúñüÁÉÍÓÚÑÜ') > 4) {
            return null;
        }

        // Filtro de calidad: no proponer nombres propios/marcas.
        if (app(LlmCorrectionSuggester::class)->looksLikeBrandOrProperNoun($wrong)) {
            return null;
        }

        // Filtro de calidad: descartar ruido (wrong === correct ya cubierto).
        $bucket = app(EnEsRuleClassifier::class)->classify($wrong, $correct)['bucket'];
        if ($bucket === EnEsRuleClassifier::NOISE) {
            return null;
        }

        $adminId = (int) (DB::table('users')->where('role', 'admin')->orderBy('id')->value('id') ?? 1);

        return Correction::create([
            'wrong_text' => $wrong,
            'correct_text' => $correct,
            'wrong_normalized' => $wrongNorm,
            'status' => Correction::STATUS_PENDING,
            'risk_level' => Correction::RISK_MEDIUM,
            'proposed_by' => $adminId,
            'source_segment_id' => $segmentId,
            'source' => 'ai-coherence-learn',
            'applies_count' => 0,
        ]);
    }
}
