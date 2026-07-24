FROM php:8.5.6-cli-alpine AS dev

# Instalacja zależności systemowych
RUN apk add --no-cache git unzip libzip-dev

# Instalacja rozszerzeń PHP
RUN docker-php-ext-install zip

# Kopiowanie Composera
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app