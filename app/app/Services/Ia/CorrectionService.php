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
     * Aplica el diccionario approved a un array de segmentos (in-place),
     * seteando `text` desde `text_raw`.
     *
     * @param array $segments array de arrays con clave `text_raw` (y opcional `text`)
     */
    public function applyToSegments(array $segments): void
    {
        foreach ($segments as &$segment) {
            $raw = $segment['text_raw'] ?? $segment['text'] ?? '';
            $segment['text'] = Correction::applyToText($raw);
        }
        unset($segment);
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
     * aplicada. Retorna el total de segments actualizados.
     *
     * @param callable|null $progressCb fn($current, $total)
     * @param int $chunkSize
     * @param bool $dryRun No toca la BD; retorna conteo estimado.
     */
    public function applyRetroactively(?callable $progressCb = null, int $chunkSize = 500, bool $dryRun = false): int
    {
        $corrections = Correction::approved()
            ->orderByRaw('LENGTH(wrong_normalized) DESC')
            ->get(['id', 'wrong_normalized', 'correct_text']);

        $total = TranscriptionSegment::count();
        $updated = 0;
        $appliedByCorrection = [];

        TranscriptionSegment::chunkById($chunkSize, function ($chunk) use (&$updated, &$appliedByCorrection, $corrections, $progressCb, $total, $dryRun) {
            $rows = [];
            foreach ($chunk as $segment) {
                $raw = (string) $segment->text_raw;
                $corrected = $raw;
                foreach ($corrections as $correction) {
                    if ($correction->wrong_normalized === '') {
                        continue;
                    }
                    $new = str_ireplace($correction->wrong_normalized, $correction->correct_text, $corrected);
                    if ($new !== $corrected) {
                        $appliedByCorrection[$correction->id] = ($appliedByCorrection[$correction->id] ?? 0) + 1;
                        $corrected = $new;
                    }
                }
                if ($corrected !== $raw) {
                    $updated++;
                    if (!$dryRun) {
                        $rows[$segment->id] = $corrected;
                    }
                }
            }

            if (!$dryRun && !empty($rows)) {
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
        return $this->applyRetroactively(null, 500, true);
    }
}