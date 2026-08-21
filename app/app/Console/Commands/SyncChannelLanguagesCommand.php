<?php

namespace App\Console\Commands;

use App\Models\ChannelLanguage;
use App\Models\Transcription;
use App\Services\Ia\ChannelSlug;
use Illuminate\Console\Command;

/**
 * Descubre los canales presentes en el corpus y los da de alta con su idioma.
 *
 * Idempotente a propósito: solo INSERTA los que faltan, con el default seguro
 * `es`. Nunca pisa una fila existente, porque el idioma de un canal lo ajusta un
 * humano y ese ajuste no debe perderse en la siguiente corrida.
 *
 * Uso:
 *   php artisan channels:sync-languages --dry-run
 *   php artisan channels:sync-languages
 */
class SyncChannelLanguagesCommand extends Command
{
    protected $signature = 'channels:sync-languages
                            {--dry-run : Solo informa de los canales que faltan}';

    protected $description = 'Da de alta en channel_languages los canales encontrados en las transcripciones.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // distinct() sobre original_name: la tabla transcriptions son ~195k
        // filas, nada que ver con transcription_segments. Aquí no hay riesgo.
        $names = Transcription::query()
            ->whereNotNull('original_name')
            ->distinct()
            ->pluck('original_name');

        $counts = [];
        foreach ($names as $name) {
            $slug = ChannelSlug::fromFilename($name);

            if ($slug !== null) {
                $counts[$slug] = ($counts[$slug] ?? 0) + 1;
            }
        }

        ksort($counts);
        $existing = ChannelLanguage::pluck('slug')->flip();
        $nuevos = array_diff_key($counts, $existing->all());

        $this->newLine();
        $this->info(sprintf(
            'Canales en el corpus: %d · ya registrados: %d · nuevos: %d',
            count($counts),
            $existing->count(),
            count($nuevos)
        ));

        if (empty($nuevos)) {
            $this->info('Nada que dar de alta.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(
            ['slug', 'nombres de archivo distintos'],
            array_map(fn ($slug, $n) => [$slug, $n], array_keys($nuevos), $nuevos)
        );

        if ($dryRun) {
            $this->newLine();
            $this->comment('Dry-run: no se dio de alta nada.');

            return self::SUCCESS;
        }

        foreach (array_keys($nuevos) as $slug) {
            ChannelLanguage::create([
                'slug' => $slug,
                'language' => ChannelLanguage::LANG_ES,
                'apply_corrections' => true,
            ]);
        }

        ChannelLanguage::forgetExcludedSlugs();

        $this->newLine();
        $this->info(sprintf('Dados de alta %d canales con language=es.', count($nuevos)));
        $this->comment('Revisa cuáles emiten en inglés y márcalos con apply_corrections=false.');

        return self::SUCCESS;
    }
}
