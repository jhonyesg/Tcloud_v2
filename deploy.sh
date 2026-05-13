#!/bin/bash
# ============================================
# TCloud Storage - Production Deploy Script
# ============================================
set -e

PROJECT_DIR="/www/wwwroot/bkcloud.mediaserver.com.co/Tcloud_v2"
PHP_BIN="/www/server/php/84/bin/php"
NGINX_BIN="/www/server/nginx/sbin/nginx"

echo "=== TCloud Production Deploy ==="

# 1. Verify Docker containers
echo "[1/7] Checking Docker containers..."
if ! docker ps --format '{{.Names}}' | grep -q tcloud_postgres; then
    echo "  Starting PostgreSQL..."
    cd "$PROJECT_DIR"
    docker run -d \
      --name tcloud_postgres \
      --restart unless-stopped \
      -e POSTGRES_DB=tcloudstorage \
      -e POSTGRES_USER=cloud \
      -e POSTGRES_PASSWORD=cloud123 \
      -v "$PROJECT_DIR/data/postgres_data:/var/lib/postgresql/data" \
      -p 127.0.0.1:5432:5432 \
      --network clouding_network \
      --security-opt apparmor=unconfined \
      postgres:17-alpine
    sleep 5
fi

if ! docker ps --format '{{.Names}}' | grep -q clouding_redis; then
    echo "  WARNING: Redis container not running!"
    exit 1
fi
echo "  Docker OK"

# 2. Verify database connection
echo "[2/7] Testing database..."
$PHP_BIN -r "
chdir('$PROJECT_DIR/app');
require 'vendor/autoload.php';
\$app = require_once 'bootstrap/app.php';
\$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\$pdo = \Illuminate\Support\Facades\DB::connection()->getPdo();
echo '  PostgreSQL ' . \$pdo->getAttribute(\PDO::ATTR_SERVER_VERSION) . ' OK' . PHP_EOL;
"

# 3. Verify Redis
echo "[3/7] Testing Redis..."
$PHP_BIN -r "
chdir('$PROJECT_DIR/app');
require 'vendor/autoload.php';
\$app = require_once 'bootstrap/app.php';
\$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app('redis')->connection()->ping();
echo '  Redis OK' . PHP_EOL;
"

# 4. Permissions
echo "[4/7] Setting permissions..."
chown -R www:www "$PROJECT_DIR/app/storage/"
chown -R www:www "$PROJECT_DIR/app/bootstrap/cache/"
chown -R www:www "$PROJECT_DIR/data/storage/"
chown -R 70:70 "$PROJECT_DIR/data/postgres_data/"
chmod -R 775 "$PROJECT_DIR/app/storage/"
chmod -R 775 "$PROJECT_DIR/app/bootstrap/cache/"

# 5. Storage symlink
echo "[5/7] Storage symlink..."
if [ ! -L "$PROJECT_DIR/app/storage/app" ]; then
    rm -rf "$PROJECT_DIR/app/storage/app"
    ln -s "$PROJECT_DIR/data/storage" "$PROJECT_DIR/app/storage/app"
fi

# 6. Laravel optimization
echo "[6/7] Optimizing Laravel..."
cd "$PROJECT_DIR/app"
$PHP_BIN artisan config:clear 2>/dev/null
$PHP_BIN artisan config:cache 2>/dev/null
$PHP_BIN artisan route:cache 2>/dev/null
$PHP_BIN artisan view:cache 2>/dev/null
$PHP_BIN artisan storage:link 2>/dev/null || true
echo "  Cache cleared and rebuilt"

# 7. Reload nginx
echo "[7/7] Reloading nginx..."
$NGINX_BIN -t 2>&1 && $NGINX_BIN -s reload
echo "  Nginx reloaded"

echo ""
echo "=== Deploy Complete ==="
echo "App URL: https://bkcloud.mediaserver.com.co"
echo "Login:   jsuarez@mediaclouding.com"
echo ""
echo "Docker commands:"
echo "  PostgreSQL: docker restart tcloud_postgres"
echo "  Redis:      docker restart clouding_redis"
echo "  Logs:       docker logs tcloud_postgres -f"
