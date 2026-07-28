# Спільний base-образ для всіх SOA-сервісів (PHP 8.2 + SOAP + Postgres + Redis + AMQP).
FROM php:8.2-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev libxml2-dev librabbitmq-dev unzip git \
    && docker-php-ext-install -j"$(nproc)" soap pdo_pgsql \
    && pecl install redis amqp \
    && docker-php-ext-enable redis amqp \
    && apt-get purge -y --auto-remove \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["entrypoint.sh"]
