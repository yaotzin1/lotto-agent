# FrankenPHP with PHP 8.3
FROM dunglas/frankenphp:1.2-php8.3

# Install system deps and PHP extensions (gmp)
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends git autoconf g++ make libgmp-dev; \
    docker-php-ext-install -j"$(nproc)" gmp; \
    apt-get purge -y --auto-remove autoconf g++ make; \
    rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Copy composer first if available; otherwise, download
COPY composer.json /app/composer.json
RUN set -eux; \
    if [ ! -f /usr/bin/composer ]; then \
      curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer; \
    fi; \
    composer install --no-interaction --no-progress || true

# Copy rest of the app
COPY . /app

# Expose FrankenPHP on port 80
EXPOSE 80

# Run FrankenPHP with PHP-FPM like front controller in public/
ENV SERVER_NAME=:80
ENV DOCUMENT_ROOT=/app/public

# Build assets in production if needed (no-op in dev)
# You can override with docker-compose node service running watch

CMD ["frankenphp", "run", "--workers=4", "--config=/app/public/index.php"]
