# Campaign API - application image
# Chris Delport <chrisd@chrisd.co.za>
#
# Multi-stage: 'development' mounts source + dev tooling, 'production'
# bakes vendor in with --no-dev and runs as www-data. php-fpm is the
# workhorse; nginx (see docker/nginx.conf) terminates HTTP and proxies
# FastCGI to the 'app' service on port 9000.

FROM php:8.4-fpm-alpine AS base

RUN apk add --no-cache \
    icu-dev \
    libzip-dev \
    zip \
    oniguruma-dev \
    $PHPIZE_DEPS \
    && docker-php-ext-install pdo_mysql bcmath intl zip opcache pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# ---- development target: full source + dev tools (xdebug-ready) ----
FROM base AS development

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apk add --no-cache linux-headers autoconf gcc g++ make

WORKDIR /var/www/html

COPY --from=base /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --prefer-dist --no-interaction --no-progress

COPY . .
RUN chown -R www-data:www-data storage bootstrap/cache

USER www-data

EXPOSE 9000

CMD ["php-fpm"]

# ---- production target ----
FROM base AS production

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction --no-progress

COPY . .
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

EXPOSE 9000

CMD ["php-fpm"]
