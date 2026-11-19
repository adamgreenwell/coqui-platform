#!/usr/bin/env bash
set -euo pipefail

# Sync public template and built Vite assets into the shared public volume.
if [ -d /tmp/public-template ]; then
    cp -r /tmp/public-template/. /var/www/html/public/
fi

if [ -d /tmp/build-assets ]; then
    rm -rf /var/www/html/public/build
    cp -r /tmp/build-assets /var/www/html/public/build
fi

mkdir -p /var/www/html/storage/framework/{sessions,views,cache} /var/www/html/storage/logs
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Only run migrations from the primary php-fpm container.
if [ "${1:-}" = "php-fpm" ]; then
    # Fail loudly: a container that can't migrate must not report healthy.
    php artisan migrate --force --no-interaction
    php artisan config:cache || true
    php artisan route:cache || true
    php artisan view:cache || true
fi

exec "$@"
