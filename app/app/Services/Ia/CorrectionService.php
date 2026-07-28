<?php

namespace App\Services\Ia;

use App\Models\Correction;
use App\Models\Keyword;
use App\Models\TranscriptionSegment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orquesta el diccionario de correcciones (wrong -> correct) moderado
 * cliente -> admin. Las correcciones approved se aplican al campo `text`
 * (vivo) de los TranscriptionSegment al parsear SRT nuevo, y se pueden
 * reaplicar retroactivamente con applyRetroactively().
 */
class CorrectionService
{
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
    public function applyToSegments(array $segments): array
    {
        $corrections = Correction::approved()
            ->orderByRaw('LENGTH(wrong_normalized) DESC')
            ->get(['wrong_normalized', 'correct_text']);

        if ($corrections->isEmpty()) {
            return $segments;
        }

        foreach ($segments as $i => $segment) {
            $raw = $segment['text_raw'] ?? $segment['text'] ?? '';
            $segments[$i]['text'] = $this->applyText($raw, $corrections);
        }

        return $segments;
    }

    /**
     * Aplica la lista de correcciones approved a un texto plano, en orden
     * de longitud DESC del wrong_normalized para evitar que un substring
     * corto sobreescriba uno largo.
     */
    private function applyText(string $text, \Illuminate\Support\Collection $corrections): string
    {
        foreach ($corrections as $correction) {
            if ($correction->wrong_normalized === '') {
                continue;
            }
            $text = str_ireplace($correction->wrong_normalized, $correction->correct_text, $text);
        }
        return $text;
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
     * Reaplica el diccionario approved a TODOS los TranscriptionSegment
     * en chunks transaccionales. Incrementa applies_count por corrección
     * aplicada, con idempotencia: re-ejecutar el retroactivo sobre un
     * segmento ya corregido NO incrementa el contador. El conteo se hace
     * por delta dentro de cada chunk y se resetea después del commit.
     *
     * @param callable|null $progressCb fn($current, $total)
     * @param int $chunkSize
     * @param bool $dryRun No toca la BD; retorna conteo estimado.
     */
    public function applyRetroactively(?callable $progressCb = null, ?int $chunkSize = null, bool $dryRun = false): int
    {
        // null = usar corrections_chunk, que existia en config desde siempre y
        // no lo leia nadie: aqui y en previewRetroactive() estaba hardcodeado a
        // 500. Va por el servicio para que un override desde la UI tenga efecto.
        $chunkSize = $chunkSize ?? $this->chunkSize();

        $corrections = Correction::approved()
            ->orderByRaw('LENGTH(wrong_normalized) DESC')
            ->get(['id', 'wrong_normalized', 'correct_text']);

        $total = TranscriptionSegment::count();
        $updated = 0;

        TranscriptionSegment::chunkById($chunkSize, function ($chunk) use (&$updated, $corrections, $progressCb, $total, $dryRun) {
            $rows = [];
            $appliedByCorrection = [];

            foreach ($chunk as $segment) {
                $raw = (string) $segment->text_raw;
                $corrected = $raw;
                $delta = [];

                foreach ($corrections as $correction) {
                    if ($correction->wrong_normalized === '') {
                        continue;
                    }
                    $new = str_ireplace($correction->wrong_normalized, $correction->correct_text, $corrected);
                    if ($new !== $corrected) {
                        $delta[$correction->id] = ($delta[$correction->id] ?? 0) + 1;
                        $corrected = $new;
                    }
                }

                if ($corrected !== $raw) {
                    $updated++;
                    if (!$dryRun) {
                        $rows[$segment->id] = $corrected;
                        foreach ($delta as $cid => $n) {
                            $appliedByCorrection[$cid] = ($appliedByCorrection[$cid] ?? 0) + $n;
                        }
                    }
                }
            }

            if (!$dryRun && (!empty($rows) || !empty($appliedByCorrection))) {
                DB::transaction(function () use ($rows, $appliedByCorrection) {
                    foreach ($rows as $id => $text) {
                        DB::table('transcription_segments')
                            ->where('id', $id)
                            ->update(['text' => $text, 'updated_at' => now()]);
                    }
                    foreach ($appliedByCorrection as $correctionId => $count) {
                        DB::table('corrections')
                            ->where('id', $correctionId)
                            ->increment('applies_count', $count);
                    }
                });
            }

            if ($progressCb) {
                $progressCb($chunk->last()->id, $total);
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
}