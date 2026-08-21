<?php

namespace App\Console\Commands;

use App\Models\StorageProvider;
use App\Models\Transcription;
use App\Modules\Correo\Services\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Centinela de flujo del pipeline de transcripcion.
 *
 * Motivo: el 2026-08-18 el pipeline se detuvo por completo (una migracion dejo
 * `user_storages.transcription_enabled` vacio y con el la bandera derivada de
 * los 175 storages) y estuvo 44 horas parado sin que nada avisara. Cada pieza
 * reportaba su estado como si fuera normal: el tick decia "no hay pending", el
 * tune decia "workers objetivo 0". Faltaba la pregunta de arriba: ¿esta
 * entrando trabajo?
 *
 * Este comando la hace. No mira componentes, mira el resultado: si hace N horas
 * que no nace una fila en `transcriptions`, algo aguas arriba esta roto.
 *
 * La sonda es deliberadamente barata: la fila mas reciente por PK
 * (`ORDER BY id DESC LIMIT 1`), no un `WHERE created_at >= X` — no hay indice
 * por created_at solo y la tabla ronda las 240k filas.
 */
class TranscriptionHealthCheckCommand extends Command
{
    protected $signature = 'transcription:health-check
                            {--hours=3 : Horas sin transcripciones nuevas que se consideran una caida (0 = prueba de humo: fuerza el aviso)}
                            {--to= : Correo del operador a notificar (sin esto solo registra en log)}';

    protected $description = 'Avisa si el pipeline de transcripcion lleva horas sin producir nada.';

    /** Evita repetir el correo cada hora durante una caida larga. */
    private const COOLDOWN_CACHE_KEY = 'transcriptor:healthcheck:last_alert';
    private const COOLDOWN_HOURS = 6;

    public function handle(NotificationService $correo): int
    {
        // 0 se admite a propósito: es la prueba de humo del camino de aviso
        // (--hours=0 siempre dispara). El agendado usa el default de 3.
        $horas = max(0, (int) $this->option('hours'));
        $limite = CarbonImmutable::now()->subHours($horas);

        $ultima = Transcription::query()->orderByDesc('id')->value('created_at');
        $storagesHabilitados = StorageProvider::transcriptionEnabled()->count();

        if ($ultima !== null && CarbonImmutable::parse($ultima)->greaterThanOrEqualTo($limite)) {
            $this->info("Pipeline vivo: ultima transcripcion {$ultima} ({$storagesHabilitados} storages habilitados).");

            return self::SUCCESS;
        }

        // El diagnostico mas probable segun lo que se ve desde aqui. Cuando no
        // hay ningun storage habilitado es exactamente el modo de fallo del
        // 2026-08-18, asi que se nombra sin rodeos.
        $diagnostico = $storagesHabilitados === 0
            ? 'NINGUN storage tiene la transcripcion habilitada: encender los canales que corresponda en /ia/api-transcriptor.'
            : 'Hay storages habilitados, asi que el corte esta aguas abajo: revisar los workers (transcription:tune --apply), la cola Redis y la API del transcriptor.';

        $desde = $ultima !== null ? (string) $ultima : 'nunca';

        $this->error("Pipeline de transcripcion SIN PRODUCCION desde {$desde} (umbral: {$horas}h).");
        $this->line("  Storages habilitados: {$storagesHabilitados}");
        $this->line("  {$diagnostico}");

        Log::warning('TranscriptionHealthCheck: el pipeline no produce transcripciones', [
            'ultima_transcripcion' => $desde,
            'umbral_horas' => $horas,
            'storages_habilitados' => $storagesHabilitados,
            'diagnostico' => $diagnostico,
        ]);

        $this->notificar($correo, $desde, $horas, $storagesHabilitados, $diagnostico);

        return self::FAILURE;
    }

    /**
     * El correo es best-effort: si no hay destinatario, plantilla o SMTP, el
     * WARNING en laravel.log ya cumplio. Un centinela que revienta por no poder
     * avisar es peor que uno que avisa a medias.
     */
    private function notificar(
        NotificationService $correo,
        string $desde,
        int $horas,
        int $storagesHabilitados,
        string $diagnostico
    ): void {
        $to = (string) ($this->option('to') ?: config('transcriptor.health_alert_email', ''));

        if ($to === '') {
            return;
        }

        if (Cache::get(self::COOLDOWN_CACHE_KEY)) {
            $this->line('  (correo omitido: ya se notifico dentro de la ventana de ' . self::COOLDOWN_HOURS . 'h)');

            return;
        }

        try {
            $correo->send('alerta-sistema', $to, [
                'titulo' => 'Pipeline de transcripcion detenido',
                'detalle' => "No se crea ninguna transcripcion desde {$desde} (umbral: {$horas}h). "
                    . "Storages habilitados: {$storagesHabilitados}.",
                'accion' => $diagnostico,
                'fecha' => now()->format('Y-m-d H:i:s'),
            ]);

            Cache::put(self::COOLDOWN_CACHE_KEY, now()->toIso8601String(), now()->addHours(self::COOLDOWN_HOURS));
        } catch (\Throwable $e) {
            Log::warning('TranscriptionHealthCheck: no se pudo enviar el correo de aviso: ' . $e->getMessage());
        }
    }
}
