#!/bin/sh
# Prepares the app, then runs the given command. Only the `ads-api` service sets
# APP_BOOTSTRAP=1; the other services wait for it to become healthy.
set -e

if [ "${APP_BOOTSTRAP:-0}" = "1" ]; then
    [ -f .env ] || cp .env.example .env

    composer install --no-interaction --prefer-dist

    if [ ! -f node_modules/.package-lock.json ] || [ package-lock.json -nt node_modules/.package-lock.json ]; then
        npm ci
    fi

    grep -q '^APP_KEY=.\+' .env || php artisan key:generate --no-interaction
    php artisan migrate --force --no-interaction
    php artisan ads:seed-if-empty --no-interaction
    php artisan storage:link --no-interaction 2>/dev/null || true
fi

exec "$@"
