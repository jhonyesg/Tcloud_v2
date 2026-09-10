<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transcription extends Model
{
    /**
     * `generate_alerts` (bool): bandera global del modulo de avisos.
     *
     * Si true, la transcripcion entra al matching de keywords
     * (`KeywordMatcher` via `avisos:scan`). NO controla directamente el
     * envio de correos: el filtrado por cliente ocurre aguas abajo en
     * `avisos:deliver-alerts` (union de `user_keyword` con la keyword
     * coincidente + `user_alerts_inteligentes.enabled=true`).
     *
     * Invariante: toda transcripcion nueva DEBE crearse con
     * `generate_alerts=true` salvo opt-out EXPLICITO del operador (UI
     * checkbox en `transcription:scan-and-submit` = false, o flag
     * `--alerts=0`). Excluir en este punto es irreversible dentro del flujo
     * automatico: la keyword del cliente nunca sera evaluada contra esa
     * transcripcion, sin que el cliente ni el admin tengan senal del fallo.
     *
     * Callers responsables de respetar el invariante:
     *  - `TranscriptionTickCommand` (cron, cada 2 min): pasa `--alerts`.
     *  - `ScanAndSubmitCommand` (sub-comando): default-true en `--alerts`.
     *  - `ApiTranscriptorController` (UI manual): respeta el checkbox del
     *    operador; default del request es true.
     */
    protected $fillable = [
        'file_id', 'original_name', 'job_id', 'node_url', 'node_id', 'state', 'corrected', 'generate_alerts', 'language',
        'srt_content', 'duration_seconds', 'word_count',
        'started_at', 'finished_at', 'recorded_at', 'requeue_after_at', 'last_polled_at', 'error_message', 'retries',
        'discovered_at', 'dispatched_at', 'submission_committed_at', 'regulator_skip_reason',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        // change 2026-09-10-mis-avisos-program-date-filter: fecha real del
        // programa (cuándo se emitió el audio), distinta de finished_at
        // (cuándo se procesó). Seteada por TranscriptionObserver en `creating`,
        // inmutable después.
        'recorded_at' => 'datetime',
        'requeue_after_at' => 'datetime',
        'last_polled_at' => 'datetime',
        'discovered_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'submission_committed_at' => 'datetime',
        'duration_seconds' => 'integer',
        'word_count' => 'integer',
        'retries' => 'integer',
        'corrected' => 'integer',
        'generate_alerts' => 'boolean',
    ];

    public const STATE_PENDING = 'pending';
    public const STATE_QUEUED = 'queued';
    public const STATE_PROCESSING = 'processing';
    public const STATE_DONE = 'done';
    public const STATE_ERROR = 'error';
    public const STATE_DEAD = 'dead';

    /**
     * Estado de la corrección async de idioma (whisper-turbo) reportada por
     * la API del transcriptor. Ver docs §2 "Semántica expandida de corrected":
     *   null  = sin info (job aún no terminó, o respuesta legacy sin el campo)
     *   0     = job en done con SRT sin corregir; el corrector reescribirá el SRT
     *   1     = SRT corregido disponible
     *   -1    = no recuperable (timeout del corrector o motor reiniciado)
     */
    public const CORRECTED_PENDING = 0;
    public const CORRECTED_DONE = 1;
    public const CORRECTED_LOST = -1;

    /**
     * Prefijos de `error_message` que marcan una fila cerrada SIN resultado
     * pero recuperable re-enviando el audio (transcription:backfill-lost).
     *
     * Son vocabulario compartido entre quien cierra la fila
     * (TranscriptionPollingService) y quien la rescata: el backfill los usa
     * para seleccionar candidatas, asi que deben mantenerse estables.
     */
    public const LOSS_MARK_SRT = 'SRT perdido upstream';
    public const LOSS_MARK_JOB = 'Job inexistente upstream';
    public const LOSS_MARK_AGED = 'Sin resolver tras';

    /** @return string[] */
    public static function lossMarks(): array
    {
        return [self::LOSS_MARK_SRT, self::LOSS_MARK_JOB, self::LOSS_MARK_AGED];
    }

    /**
     * Filas cerradas por perdida del resultado upstream, no por un fallo del
     * audio o de la conversion: son las que tiene sentido reintentar.
     */
    public function scopeUpstreamLost(Builder $q): Builder
    {
        return $q->where(function (Builder $inner) {
            foreach (self::lossMarks() as $mark) {
                $inner->orWhere('error_message', 'like', $mark . '%');
            }
        });
    }

    public function isCorrectedResolved(): bool
    {
        return in_array($this->corrected, [self::CORRECTED_DONE, self::CORRECTED_LOST], true);
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function segments(): HasMany
    {
        return $this->hasMany(TranscriptionSegment::class);
    }

    public function keywordMatches(): HasMany
    {
        return $this->hasMany(KeywordMatch::class);
    }

    public function alertLogs(): HasMany
    {
        return $this->hasMany(AlertLog::class);
    }

    public function review(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(TranscriptionReview::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('state', [self::STATE_PENDING, self::STATE_QUEUED, self::STATE_PROCESSING]);
    }

    public function scopeRecent(Builder $query, int $days = 7): Builder
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    public function isTerminalError(): bool
    {
        return in_array($this->state, [self::STATE_ERROR, self::STATE_DEAD], true);
    }
}
