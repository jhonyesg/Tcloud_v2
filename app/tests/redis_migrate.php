<?php
/**
 * Migración DB 0 → DB 2 de sesiones Redis sin downtime.
 * Preserva el TTL usando DUMP + RESTORE a través del cliente Redis
 * del propio Laravel (maneja binarios correctamente, sin quoting de shell).
 *
 * Uso: php tests/redis_migrate.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Redis;

$pattern = 'tcloud_tcloud_cache_*';

echo "=== SCAN en DB 0 ===\n";
$src = Redis::connection('default');

// Predis SCAN devuelve un iterador. Para evitar eso, usamos raw command.
$cursor = '0';
$keys = [];
do {
    // Predis::scan acepta: cursor, [MATCH pattern], [COUNT count]
    $result = $src->scan($cursor, ['match' => $pattern, 'count' => 1000]);
    [$cursor, $batch] = $result;
    foreach ($batch as $key) {
        if (str_starts_with($key, $pattern)) {
            $keys[] = $key;
        }
    }
} while ($cursor !== '0' && $cursor !== 0);

echo "→ Encontradas " . count($keys) . " claves en DB 0\n";

$dst = Redis::connection('session');

$migrated = 0;
$skipped = 0;
$errors = 0;

foreach ($keys as $key) {
    // ¿Ya existe en DB 2?
    if ($dst->exists($key)) {
        echo "↻ SKIP (ya en DB 2): $key\n";
        $src->del($key);
        $skipped++;
        continue;
    }

    $ttl = (int) $src->ttl($key);
    if ($ttl < 0) $ttl = 0;
    $ttlMs = $ttl * 1000;

    $payload = $src->command('DUMP', [$key]);
    if (!$payload) {
        echo "✗ EMPTY DUMP: $key\n";
        $errors++;
        continue;
    }

    // Predis RESTORE: key, milliseconds, payload (binario)
    try {
        $result = $dst->command('RESTORE', [$key, $ttlMs, $payload]);
        // Predis suele devolver 'OK' como Status object
        $statusOk = is_string($result) ? $result === 'OK' : (method_exists($result, 'getPayload') ? $result->getPayload() === 'OK' : true);
        if ($statusOk) {
            $src->del($key);
            echo "✓ MIGRATED (TTL {$ttl}s): $key\n";
            $migrated++;
        } else {
            echo "✗ RESTORE unexpected: " . var_export($result, true) . " — $key\n";
            $errors++;
        }
    } catch (\Throwable $e) {
        echo "✗ RESTORE FAILED: {$e->getMessage()} — $key\n";
        $errors++;
    }
}

echo "\n=== RESUMEN ===\n";
echo "Migradas: $migrated\n";
echo "Saltadas: $skipped\n";
echo "Errores:  $errors\n";
exit($errors > 0 ? 1 : 0);
