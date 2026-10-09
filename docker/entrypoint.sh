#!/bin/sh
set -e

if [ ! -f .env ]; then
    cp .env.example .env
fi

if ! grep -q '^APP_KEY=base64:' .env; then
    php artisan key:generate --force
fi

mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    storage/app/public

chown -R www-data:www-data storage bootstrap/cache

# Wait for MySQL to accept connections before migrating.
if [ "${DB_CONNECTION:-}" = "mysql" ]; then
    echo "Waiting for database..."
    attempts=0
    until php -r '
        $host = getenv("DB_HOST") ?: "db";
        $port = (int) (getenv("DB_PORT") ?: 3306);
        try {
            new PDO("mysql:host={$host};port={$port}", getenv("DB_USERNAME"), getenv("DB_PASSWORD"));
            exit(0);
        } catch (Throwable $e) {
            exit(1);
        }
    '; do
        attempts=$((attempts + 1))
        if [ "$attempts" -ge 30 ]; then
            echo "Database did not become ready in time." >&2
            exit 1
        fi
        sleep 2
    done
    echo "Database ready."
fi

php artisan migrate --force
# No storage:link: uploads are served through GET /api/files/{id} after an
# authorisation check, never as a public symlink.

exec "$@"