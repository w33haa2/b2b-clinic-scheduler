# syntax=docker/dockerfile:1

# =============================================================================
# Clinic App — multi-stage image
#
# Targets:
#   development  PHP-FPM + Composer + Node for local work (source is bind-mounted)
#   production   Hardened PHP-FPM image with vendor + compiled assets baked in
#   web          Nginx image serving public/ and proxying PHP to the app service
#   ssr          Node server that pre-renders Inertia pages (SEO, first paint)
#
# See docs/docker.md for how each target is used.
# =============================================================================

ARG PHP_VERSION=8.5
ARG NODE_VERSION=22

FROM node:${NODE_VERSION}-trixie-slim AS node

# -----------------------------------------------------------------------------
# base: PHP-FPM with the extensions the app needs in every environment
# -----------------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-trixie AS base

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

# OPcache is built into PHP 8.5, so it is not listed here.
RUN install-php-extensions bcmath intl pcntl pdo_mysql redis zip

COPY docker/php/conf.d/app.ini "$PHP_INI_DIR/conf.d/zz-app.ini"

WORKDIR /var/www/html

# -----------------------------------------------------------------------------
# tooling: base + Composer + Node. The Wayfinder Vite plugin runs
# `php artisan wayfinder:generate`, so Vite always needs PHP alongside Node.
# -----------------------------------------------------------------------------
FROM base AS tooling

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

# -----------------------------------------------------------------------------
# development: used by compose.yaml. Runs as www-data remapped to the host
# user's UID/GID so files created in the bind mount stay owned by you.
# -----------------------------------------------------------------------------
FROM tooling AS development

ARG UID=1000
ARG GID=1000

RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini" \
    && groupmod --non-unique --gid "$GID" www-data \
    && usermod --non-unique --uid "$UID" --gid "$GID" www-data \
    && mkdir -p /var/www/html/node_modules \
    && chown www-data:www-data /var/www /var/www/html /var/www/html/node_modules

USER www-data

CMD ["php-fpm"]

# -----------------------------------------------------------------------------
# vendor: production Composer dependencies + application source
# -----------------------------------------------------------------------------
FROM tooling AS vendor

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY . .
RUN composer dump-autoload --no-dev --optimize --no-interaction

# -----------------------------------------------------------------------------
# assets: compile the Vue/Inertia frontend into public/build, plus the
# server-side rendering bundle into bootstrap/ssr
# -----------------------------------------------------------------------------
FROM vendor AS assets

# VITE_* values are compiled into the JS bundle, so they are build arguments.
ARG VITE_APP_NAME="Clinic App"
ENV VITE_APP_NAME=${VITE_APP_NAME}

COPY package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund

RUN npm run build:ssr

# -----------------------------------------------------------------------------
# production: final PHP-FPM image. Code is owned by root (read-only to the
# app); only storage/ and bootstrap/cache/ are writable by www-data.
# -----------------------------------------------------------------------------
FROM base AS production

# XDG_CONFIG_HOME: $HOME is not writable by www-data, and Tinker (PsySH) aborts
# if it cannot write its config directory.
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    XDG_CONFIG_HOME=/tmp/.config

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/conf.d/opcache.ini "$PHP_INI_DIR/conf.d/zz-opcache.ini"
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint

COPY --from=vendor /var/www/html /var/www/html
COPY --from=assets /var/www/html/public/build /var/www/html/public/build
# Laravel only calls the SSR server when it can see the bundle.
COPY --from=assets /var/www/html/bootstrap/ssr /var/www/html/bootstrap/ssr

RUN mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache \
    && ln -s ../storage/app/public public/storage \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# -----------------------------------------------------------------------------
# web: Nginx serving the compiled public/ directory
# -----------------------------------------------------------------------------
FROM nginx:1.28-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=production /var/www/html/public /var/www/html/public

# -----------------------------------------------------------------------------
# ssr: Inertia's SSR server. Laravel posts each first page load to it
# (INERTIA_SSR_URL) and sends crawlers fully rendered HTML. The bundle keeps
# npm packages external, so production dependencies are installed alongside it.
# -----------------------------------------------------------------------------
FROM node AS ssr-deps

WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci --omit=dev --no-audit --no-fund

FROM node AS ssr

WORKDIR /app
ENV NODE_ENV=production

COPY package.json ./
COPY --from=ssr-deps /app/node_modules ./node_modules
COPY --from=assets /var/www/html/bootstrap/ssr ./bootstrap/ssr

USER node
EXPOSE 13714

CMD ["node", "bootstrap/ssr/app.js"]
