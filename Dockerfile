FROM dunglas/frankenphp:1-php8.4-alpine

RUN apk add --no-cache \
    acl \
    fcgi \
    file \
    gettext \
    git \
    gnu-libiconv \
    postgresql-dev \
    libzip-dev

# install php extensions
RUN set -eux; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS; \
    docker-php-ext-install -j$(nproc) \
        intl \
        pdo_pgsql \
        zip \
    ; \
    pecl install \
        apcu \
    ; \
    docker-php-ext-enable \
        apcu \
        opcache \
    ; \
    runDeps="$( \
        scanelf --needed --nobanner --format '%n#p' --recursive /usr/local/lib/php/extensions \
            | tr ',' '\n' \
            | sort -u \
            | awk 'system("[ -e /usr/local/lib/" $1 " ]") == 0 { next } { print "so:" $1 }' \
    )"; \
    apk add --no-cache --virtual .api-phpexts-rundeps $runDeps; \
    apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# https://getcomposer.org/doc/03-cli.md#composer-allow-superuser
ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app

# build for production
ENV APP_ENV=prod

# prevent the reinstallation of vendors at every changes in the source code
COPY composer.* symfony.* ./
RUN set -eux; \
    if [ -f composer.json ]; then \
        composer install --prefer-dist --no-dev --no-autoloader --no-scripts --no-progress; \
        composer clear-cache; \
    fi

# copy sources
COPY . .

RUN set -eux; \
    mkdir -p var/cache var/log; \
    if [ -f composer.json ]; then \
        composer dump-autoload --classmap-authoritative --no-dev; \
        composer dump-env prod; \
        composer run-script --no-dev post-install-cmd; \
        chmod +x bin/console; sync; \
    fi