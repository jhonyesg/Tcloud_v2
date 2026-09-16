<?php

namespace App\Console\Commands;

use App\Models\Transcription;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Repara el desfase horario de `transcriptions.recorded_at` (2026-09-16).
 *
 * Contexto del bug: la sesión de PostgreSQL quedaba en UTC mientras Laravel
 * formatea los bindings DateTime con la zona del Carbon (America/Bogota). Al
 * crear una transcripción, `TranscriptionObserver` resuelve `recorded_at` con
 * `RecordedAt::fromFilename()`, que devuelve un CarbonImmutable en hora local
 * (Bogotá). Al persistirlo bajo sesión UTC, PostgreSQL interpretaba esa hora
 * local como UTC: cada fila quedaba desplazada -5 h respecto del instante real.
 *
 * El fix de raíz es `database.connections.pgsql.timezone` (ver config). Este
 * comando corrige SOLO las filas ya escritas con el marco viejo, y solo cuando
 * el desfase es demostrable: `recorded_at` == hora del nombre interpretada
 * como UTC. Es decir, únicamente las filas derivadas del nombre del archivo,
 * que son deterministas y reversibles.
 *
 * NO toca:
 *  - Filas cuyo `recorded_at` provino del fallback `file_modified_at` o
 *    `finished_at`: no hay forma de distinguir con certeza si el escritor usó
 *    el marco viejo o el nuevo, así que quedan para revisión manual.
 *  - Filas `pending` sin `recorded_at`.
 *
 * Uso:
 *   php artisan transcription:fix-recorded-at-timezone --dry-run   (default)
 *   php artisan transcription:fix-recorded-at-timezone --apply
 *   php artisan transcription:fix-recorded-at-timezone --apply --days=7
 *
 * Idempotente: una fila ya corregida (o creada tras el fix de config) NO
 * matchea la condición de desfase y no se vuelve a tocar.
 */
class TranscriptionFixRecordedAtTimezoneCommand extends Command
{
    protected $signature = 'transcription:fix-recorded-at-timezone
                            {--apply : Ejecuta el UPDATE. Sin esta bandera solo reporta (dry-run)}
                            {--days=0 : Limita a filas creadas en los ultimos N dias (0 = todas)}
                            {--chunk=1000 : Tamano de lote}';

    protected $description = 'Corrige el desfase -5h de recorded_at en filas derivadas del nombre del archivo.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $days = (int) $this->option('days');
        $chunk = max(100, (int) $this->option('chunk'));

        $this->line($apply
            ? '<fg=red>MODO APPLY: se escribira en la BD.</>'
            : '<fg=yellow>DRY-RUN: solo reporte, no se escribe nada.</>');

        $query = DB::table('transcriptions')
            ->select('id', 'original_name', 'recorded_at', 'created_at')
            ->whereNotNull('recorded_at')
            ->whereNotNull('original_name')
            ->orderBy('id');

        if ($days > 0) {
            $query->where('created_at', '>=', now()->subDays($days));
        }

        $scanned = 0;
        $candidates = 0;
        $fixed = 0;
        $skipped = 0;
        $examples = [];

        $query->chunkById($chunk, function ($rows) use (&$scanned, &$candidates, &$fixed, &$skipped, &$examples, $apply) {
            foreach ($rows as $row) {
                $scanned++;

                $fromName = $this->filenameAsBogota($row->original_name);
                if ($fromName === null) {
                    $skipped++; // nombre no parseable: no es corregible con certeza
                    continue;
                }

                // Hora de pared que dice el nombre (ej. "2026-09-15 19:42:02").
                // El nombre expresa hora Bogota.
                $wallClock = $fromName->format('Y-m-d H:i:s');

                // Lo que hay guardado, expresado en UTC.
                $actual = CarbonImmutable::parse((string) $row->recorded_at, 'UTC')->utc();

                // Desfase DEMOSTRABLE: lo guardado es exactamente la hora de
                // pared del nombre tratada como si fuera UTC. Si el valor ya es
                // correcto (o es un caso que provino de file_modified_at /
                // finished_at) NO matchea y se deja intacto.
                if ($actual->format('Y-m-d H:i:s') !== $wallClock) {
                    $skipped++;
                    continue;
                }

                // Valor correcto: la hora del nombre es hora Bogota, asi que el
                // instante UTC real es esa misma pared interpretada en Bogota.
                $expectedUtc = CarbonImmutable::parse($wallClock, 'America/Bogota')->utc();

                $candidates++;

                if (count($examples) < 8) {
                    $examples[] = sprintf(
                        'id=%d %s  actual=%s  ->  esperado=%s',
                        $row->id,
                        substr((string) $row->original_name, 0, 34),
                        $actual->toIso8601String(),
                        $expectedUtc->toIso8601String(),
                    );
                }

                if ($apply) {
                    DB::table('transcriptions')
                        ->where('id', $row->id)
                        ->update(['recorded_at' => $expectedUtc->format('Y-m-d H:i:sP')]);
                    $fixed++;
                }
            }
        }, 'id');

        $this->newLine();
        if (!empty($examples)) {
            $this->line('Ejemplos:');
            foreach ($examples as $e) {
                $this->line('  ' . $e);
            }
            $this->newLine();
        }

        $this->table(
            ['metrica', 'valor'],
            [
                ['filas escaneadas', $scanned],
                ['candidatas a corregir', $candidates],
                ['no corregibles/ambiguas', $skipped],
                ['corregidas', $apply ? $fixed : '(dry-run)'],
            ],
        );

        Log::info('transcription.fix_recorded_at_timezone', [
            'apply' => $apply,
            'days' => $days,
            'scanned' => $scanned,
            'candidates' => $candidates,
            'fixed' => $fixed,
            'skipped' => $skipped,
        ]);

        if (!$apply && $candidates > 0) {
            $this->warn("Dry-run: {$candidates} filas se corregirian. Reejecuta con --apply para aplicarlo.");
        }

        return Command::SUCCESS;
    }

    /**
     * Parsea el nombre `*_DDMMYYYY_HHMMSS.ext` y devuelve el CarbonImmutable en
     * hora Bogota, o null si el nombre no matchea (mismo contrato que
     * RecordedAt::fromFilename, sin acoplarse a la clase para no depender de su
     * zona por defecto).
     */
    private function filenameAsBogota(?string $name): ?CarbonImmutable
    {
        if ($name === null || $name === '') {
            return null;
        }

        if (!preg_match('/_(\d{2})(\d{2})(\d{4})_(\d{2})(\d{2})(\d{2})\.[a-zA-Z0-9]+$/', $name, $m)) {
            return null;
        }

        [, $day, $month, $year, $hour, $minute, $second] = $m;

        if (!checkdate((int) $month, (int) $day, (int) $year)) {
            return null;
        }

        return CarbonImmutable::create(
            (int) $year, (int) $month, (int) $day,
            (int) $hour, (int) $minute, (int) $second,
            'America/Bogota'
        );
    }
}
