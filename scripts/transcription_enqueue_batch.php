#!/usr/bin/env php
<?php
/**
 * Wrapper manual sobre `transcription:tick`.
 *
 * NO esta agendado en cron (el ciclo automatico es cada 2 min via
 * routes/console.php). Solo se ejecuta manualmente para:
 *   - Inspecionar que haria el tick sin escribir nada (--dry-run)
 *   - Forzar un ciclo extra (p. ej. tras un incidente o recuperacion)
 *
 * Toda la logica del regulador (target_redis_queue, batch, scope=current_day)
 * vive en App\Console\Commands\TranscriptionTickCommand. Este wrapper solo
 * delega para no duplicar implementacion.
 *
 * Uso:
 *   php scripts/transcription_enqueue_batch.php            # ejecuta tick real
 *   php scripts/transcription_enqueue_batch.php --dry-run  # muestra conteos sin escribir
 */
define('LARAVEL_START', microtime(true));
require __DIR__ . '/../app/vendor/autoload.php';
$app = require __DIR__ . '/../app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Artisan;

$dryRun = in_array('--dry-run', array_slice($argv, 1), true);

$exit = Artisan::call('transcription:tick', array_filter([
    '--dry-run' => $dryRun,
]));

echo Artisan::output();
exit($exit);
