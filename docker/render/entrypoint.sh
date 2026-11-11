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
    # --isolated takes a cache lock so that rolling deploys and multiple
    # instances cannot run the migrations on top of each other.
    #
    # It cannot be used on the very first deploy: with CACHE_STORE=database
    # that lock lives in `cache_locks`, a table the migrations themselves
    # create, so asking for it against an empty database fails and nothing
    # ever migrates. A failed `migrate:status` is how an un-migrated database
    # announces itself, so the first run goes without the lock (nothing to
    # race with yet) and every run after it takes one.
    if php artisan migrate:status >/dev/null 2>&1; then
        php artisan migrate --force --isolated
    else
        echo "First deploy: no migrations table yet, migrating without a lock."
        php artisan migrate --force
    fi

    # Create the first super admin, for hosts with no shell to run it from.
    # Set SUPER_ADMIN_EMAIL and SUPER_ADMIN_PASSWORD to turn this on.
    #
    # --if-missing makes it a no-op the moment one super admin exists, so the
    # variables are safe to leave in place across deploys. Tied to
    # RUN_MIGRATIONS so only the service that prepares the database does it.
    #
    # A failure here is reported but does not stop the boot: being unable to
    # create an account is no reason to take the whole site down.
    if [ -n "${SUPER_ADMIN_EMAIL:-}" ] && [ -n "${SUPER_ADMIN_PASSWORD:-}" ]; then
        php artisan clinic:create-super-admin \
            --name="${SUPER_ADMIN_NAME:-Super Admin}" \
            --email="${SUPER_ADMIN_EMAIL}" \
            --password="${SUPER_ADMIN_PASSWORD}" \
            --if-missing \
            --no-interaction \
            || echo "WARNING: could not create the super admin; the site is still starting."
    fi
fi

# Cache config, routes, events and views from this container's real
# environment. Must run after migrate: the permission sync that follows a
# migration writes rows this app reads.
php artisan optimize

exec "$@"
