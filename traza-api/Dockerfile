FROM php:8.3-cli-alpine AS base

WORKDIR /var/www/html

RUN apk add --no-cache \
        postgresql-dev \
        libzip-dev \
        icu-dev \
        gmp-dev \
        oniguruma-dev \
        linux-headers \
        $PHPIZE_DEPS \
    # gmp: la aritmética de 96 bits del codec SGTIN-96 (docs/04 §4)
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql bcmath gmp intl zip pcntl sockets \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

FROM base AS development
ENV APP_ENV=local
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --prefer-dist
COPY . .
RUN composer dump-autoload --optimize
EXPOSE 8000 8080
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]

FROM base AS production
ENV APP_ENV=production
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-dev --no-scripts --prefer-dist --optimize-autoloader
COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && php artisan config:cache \
    && php artisan route:cache \
    && php artisan event:cache
EXPOSE 8000 8080
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
