<?php

namespace App\Services\Ia;

use App\Models\Correction;
use Illuminate\Support\Facades\DB;

/**
 * Auditor read-only del diccionario de correcciones (changes/2026-08-02).
 *
 * Reporta estadísticas para diagnóstico:
 *   - Totales por status
 *   - Distribución de effectiveness (applies_count buckets)
 *   - Top unigramas/bigramas/trigramas dentro de wrong_text
 *   - Clusters por overlap de tokens (Jaccard)
 *   - Duplicados exactos y conflictos (mismo wrong → distinto correct)
 *
 * NO modifica la BD. Útil para correr antes/después de cambios y entender
 * la composición real del diccionario.
 *
 * Cache: los resultados se cachean 5 min en Redis/array con key
 * `dictionary_audit:YYYYMMDDHHMM` para no recalcular en cada request.
 */
class DictionaryAudit
{
    private const CACHE_TTL_SECONDS = 300;
    private const MIN_TOKEN_LENGTH = 3;

    public function run(bool $useCache = true): array
    {
        if ($useCache) {
            $cacheKey = 'dictionary_audit:' . now()->format('YmdHi');
            return \Illuminate\Support\Facades\Cache::remember(
                $cacheKey,
                self::CACHE_TTL_SECONDS,
                fn () => $this->compute()
            );
        }
        return $this->compute();
    }

    private function compute(): array
    {
        return [
            'totals' => $this->totals(),
            'effectiveness_distribution' => $this->effectivenessDistribution(),
            'top_unigrams' => $this->topNgrams(1, 30),
            'top_bigrams' => $this->topNgrams(2, 30),
            'top_trigrams' => $this->topNgrams(3, 30),
            'duplicates_and_conflicts' => $this->duplicatesAndConflicts(),
            'clusters' => $this->clusters(),
            'risk_distribution' => $this->riskDistribution(),
        ];
    }

    public function totals(): array
    {
        $rows = Correction::query()
            ->select('status', DB::raw('count(*) as cnt'))
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->toArray();

        return [
            'approved' => (int) ($rows['approved'] ?? 0),
            'pending' => (int) ($rows['pending'] ?? 0),
            'rejected' => (int) ($rows['rejected'] ?? 0),
            'merged' => (int) ($rows['merged'] ?? 0),
            'total' => array_sum($rows),
        ];
    }

    public function effectivenessDistribution(): array
    {
        $rows = Correction::approved()
            ->select('applies_count')
            ->pluck('applies_count');

        $buckets = [
            '0' => 0,
            '1-5' => 0,
            '6-20' => 0,
            '21-100' => 0,
            '100+' => 0,
        ];
        foreach ($rows as $a) {
            $a = (int) $a;
            if ($a === 0) {
                $buckets['0']++;
            } elseif ($a <= 5) {
                $buckets['1-5']++;
            } elseif ($a <= 20) {
                $buckets['6-20']++;
            } elseif ($a <= 100) {
                $buckets['21-100']++;
            } else {
                $buckets['100+']++;
            }
        }
        return $buckets;
    }

    /**
     * Top n-gramas dentro de wrong_text de las approved.
     *
     * @return array<int, array{ngram: string, count: int}>
     */
    public function topNgrams(int $n, int $limit = 30): array
    {
        $rows = Correction::approved()
            ->where('applies_count', '>=', 0)
            ->select('wrong_text')
            ->get();

        $counts = [];
        foreach ($rows as $r) {
            $tokens = $this->tokenize((string) $r->wrong_text);
            if (count($tokens) < $n) {
                continue;
            }
            for ($i = 0; $i <= count($tokens) - $n; $i++) {
                $slice = array_slice($tokens, $i, $n);
                // Filtrar tokens muy cortos
                $skip = false;
                foreach ($slice as $tok) {
                    if (mb_strlen($tok) < self::MIN_TOKEN_LENGTH) {
                        $skip = true;
                        break;
                    }
                }
                if ($skip) {
                    continue;
                }
                $key = implode(' ', $slice);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        arsort($counts);
        $out = [];
        $i = 0;
        foreach ($counts as $ngram => $cnt) {
            if ($i++ >= $limit) {
                break;
            }
            $out[] = ['ngram' => $ngram, 'count' => (int) $cnt];
        }
        return $out;
    }

    /**
     * Top unigramas (wrapper de topNgrams para compatibilidad con spec).
     */
    public function topUnigrams(int $limit = 30): array
    {
        return $this->topNgrams(1, $limit);
    }

    public function topBigrams(int $limit = 30): array
    {
        return $this->topNgrams(2, $limit);
    }

    public function topTrigrams(int $limit = 30): array
    {
        return $this->topNgrams(3, $limit);
    }

    /**
     * @return array{exact_duplicates: int, conflicts: int}
     */
    public function duplicatesAndConflicts(): array
    {
        $rows = Correction::approved()
            ->select('wrong_text', 'correct_text')
            ->get();

        $exactGroups = [];
        $wrongGroups = [];
        foreach ($rows as $r) {
            $key = mb_strtolower(trim((string) $r->wrong_text)) . '||' . mb_strtolower(trim((string) $r->correct_text));
            $exactGroups[$key] = ($exactGroups[$key] ?? 0) + 1;

            $wKey = mb_strtolower(trim((string) $r->wrong_text));
            $wrongGroups[$wKey][$r->id] = (string) $r->correct_text;
        }

        $exactDup = 0;
        foreach ($exactGroups as $cnt) {
            if ($cnt > 1) {
                $exactDup += $cnt - 1;
            }
        }

        $conflicts = 0;
        foreach ($wrongGroups as $items) {
            $uniq = array_unique($items);
            if (count($items) > 1 && count($uniq) > 1) {
                $conflicts++;
            }
        }

        return [
            'exact_duplicates' => $exactDup,
            'conflicts' => $conflicts,
        ];
    }

    /**
     * @return array{threshold: float, min_overlaps: int, total_clusters: int, samples: array}
     */
    public function clusters(float $jaccardThreshold = 0.6, int $minOverlaps = 3): array
    {
        $rows = Correction::approved()
            ->select('id', 'wrong_text')
            ->get();

        $tokenSets = [];
        foreach ($rows as $r) {
            $tokens = array_unique(array_filter(
                $this->tokenize((string) $r->wrong_text),
                fn ($t) => mb_strlen($t) >= 4
            ));
            if (count($tokens) < 3) {
                continue;
            }
            $tokenSets[$r->id] = array_values($tokens);
        }

        $inCluster = [];
        foreach ($tokenSets as $id => $ts) {
            $shared = 0;
            foreach ($tokenSets as $id2 => $ts2) {
                if ($id === $id2) {
                    continue;
                }
                $inter = count(array_intersect($ts, $ts2));
                $union = count(array_unique(array_merge($ts, $ts2)));
                if ($union > 0 && ($inter / $union) >= $jaccardThreshold) {
                    $shared++;
                }
            }
            if ($shared >= $minOverlaps) {
                $inCluster[$id] = $shared;
            }
        }

        arsort($inCluster);
        $samples = [];
        $i = 0;
        foreach (array_slice($inCluster, 0, 10, true) as $id => $shared) {
            $sample = Correction::find($id);
            $samples[] = [
                'id' => (int) $id,
                'shared_with' => (int) $shared,
                'tokens' => count($tokenSets[$id]),
                'wrong_text' => mb_substr((string) $sample->wrong_text, 0, 100),
            ];
            if (++$i >= 10) {
                break;
            }
        }

        return [
            'threshold' => $jaccardThreshold,
            'min_overlaps' => $minOverlaps,
            'total_clusters' => count($inCluster),
            'total_with_tokens_ge_3' => count($tokenSets),
            'samples' => $samples,
        ];
    }

    public function riskDistribution(): array
    {
        $rows = Correction::approved()
            ->select('risk_level', DB::raw('count(*) as cnt'))
            ->groupBy('risk_level')
            ->pluck('cnt', 'risk_level')
            ->toArray();

        return [
            'low' => (int) ($rows['low'] ?? 0),
            'medium' => (int) ($rows['medium'] ?? 0),
            'high' => (int) ($rows['high'] ?? 0),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function tokenize(string $text): array
    {
        $tokens = preg_split('/[\s\p{P}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        return is_array($tokens) ? $tokens : [];
    }
}
