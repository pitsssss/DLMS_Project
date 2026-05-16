#!/bin/sh
set -e

RUN_MIGRATIONS="${RUN_MIGRATIONS:-false}"
RUN_SEEDERS="${RUN_SEEDERS:-false}"

mkdir -p storage/app/public \
    storage/app/private \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

if [ -n "${APP_KEY}" ]; then
    php artisan optimize:clear || true
fi

if [ "${RUN_MIGRATIONS}" = "true" ]; then
    php artisan migrate --force
fi

if [ "${RUN_SEEDERS}" = "true" ]; then
    php artisan db:seed --force
fi

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
