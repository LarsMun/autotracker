# syntax=docker/dockerfile:1

# One image for the app, the queue worker and the scheduler; compose chooses the command.

# ---------- The PHP runtime, shared by every stage below ----------
FROM dunglas/frankenphp:1-php8.5 AS base

# pdo_mysql for the database, opcache for speed, pcntl so the queue worker can stop gracefully.
RUN install-php-extensions pdo_mysql opcache pcntl \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && echo "memory_limit=512M" > "$PHP_INI_DIR/conf.d/zz-auto.ini"

# mysqldump for `php artisan auto:backup`.
RUN apt-get update \
    && apt-get install -y --no-install-recommends default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# ---------- PHP dependencies, without the ones for development ----------
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN install-php-extensions zip

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --no-dev --optimize

# ---------- Frontend assets ----------
FROM node:22-alpine AS assets

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci

COPY . .
RUN npm run build

# ---------- The image that runs ----------
FROM base

COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

# storage is a volume in production; these folders are what an empty volume starts with.
RUN mkdir -p storage/app/public storage/app/private storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && php artisan package:discover \
    && php artisan storage:link

EXPOSE 80

# Traefik handles HTTPS; FrankenPHP serves plain HTTP inside the network.
CMD ["frankenphp", "php-server", "--listen", ":80", "--root", "public/"]
