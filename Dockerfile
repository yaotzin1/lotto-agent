FROM php:8.5.6-cli-alpine AS base

# Zależności systemowe (ca-certificates jest wymagane do weryfikacji TLS)
RUN apk add --no-cache git unzip libzip-dev ca-certificates \
    && update-ca-certificates

RUN docker-php-ext-install zip

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# --- Etap deweloperski: kod montowany przez wolumen, zależności dev dostępne ---
FROM base AS dev

# --- Etap produkcyjny: kod skopiowany do obrazu, bez zależności dev ---
FROM base AS prod

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader

COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && rm -rf var/cache/* var/log/*

RUN addgroup -g 1000 app && adduser -u 1000 -G app -s /bin/sh -D app \
    && chown -R app:app /app
USER app

ENV APP_ENV=prod
ENTRYPOINT ["php", "bin/console"]
