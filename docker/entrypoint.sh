#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

# Generate an app key on first boot if the environment did not supply one.
if [ -z "${APP_KEY:-}" ] && ! grep -q '^APP_KEY=.\+' .env 2>/dev/null; then
    php artisan key:generate --force || true
fi

# Wait for the database to accept connections before migrating.
until php artisan db:show >/dev/null 2>&1; do
    echo "Waiting for database..."
    sleep 2
done

# The primary app container runs migrations; workers skip this via RUN_MIGRATIONS=false.
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force
fi

# Cache config only when explicitly opted in (set CONFIG_CACHE=true in production).
# Otherwise keep config live so edits to .env are picked up on the next request
# without recreating the container. config:cache only — routes/settings.php defines
# a closure route (passkeys) that cannot be route:cache'd.
if [ "${CONFIG_CACHE:-false}" = "true" ]; then
    php artisan config:cache
else
    php artisan config:clear
fi

exec "$@"
