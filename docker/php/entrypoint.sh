#!/bin/sh
# Production entrypoint shared by the app, queue and scheduler services.
set -e

# The storage volume may be fresh, so make sure Laravel's directories exist.
mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/logs

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
fi

# Cache config, routes, events and views using this container's environment.
php artisan optimize

exec docker-php-entrypoint "$@"
