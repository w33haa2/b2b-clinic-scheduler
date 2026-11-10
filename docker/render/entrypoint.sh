#!/bin/sh
# Entrypoint for the single-container "render" image, shared by the web
# service and any worker service built from the same image.
set -e

# The platform picks the port; Nginx has to be told what it chose.
export PORT="${PORT:-10000}"
envsubst '${PORT}' < /etc/nginx/nginx.conf.template > /tmp/nginx.conf

# Laravel's directories, in case storage is a fresh volume.
mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/logs

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    # --isolated takes a cache lock, so rolling deploys and multiple web
    # instances cannot run the migrations on top of each other. It needs a
    # cache store that supports locks: database or redis, not array/file.
    php artisan migrate --force --isolated
fi

# Cache config, routes, events and views from this container's real
# environment. Must run after migrate: the permission sync that follows a
# migration writes rows this app reads.
php artisan optimize

exec "$@"
