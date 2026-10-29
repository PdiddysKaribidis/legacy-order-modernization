# Development image: code is mounted as a volume and served by the built-in PHP server.
FROM php:8.4-cli-alpine

RUN apk add --no-cache unzip git \
    && docker-php-ext-install pdo_mysql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
