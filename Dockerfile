FROM php:8.2-fpm AS base

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libpq-dev \
    libzip-dev \
    libicu-dev \
    zip \
    && docker-php-ext-install pdo pdo_pgsql zip intl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www

RUN echo "date.timezone = America/Sao_Paulo" > /usr/local/etc/php/conf.d/timezone.ini

RUN { \
    echo "max_input_vars = 5000"; \
} > /usr/local/etc/php/conf.d/limits.ini

FROM base AS dev

ARG UID=1000
ARG GID=1000
RUN usermod -u $UID www-data && groupmod -g $GID www-data

USER www-data
EXPOSE 9000

FROM base AS prod_builder

WORKDIR /var/www/app
COPY app/composer.json app/composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

COPY app/ ./
RUN composer dump-autoload --classmap-authoritative --no-dev --no-interaction \
    && APP_ENV=prod APP_DEBUG=0 php bin/console cache:warmup

FROM base AS prod

WORKDIR /var/www
COPY --from=prod_builder --chown=www-data:www-data /var/www/app /var/www/app
RUN mkdir -p /var/www/app/var/cache /var/www/app/var/log \
    && chown -R www-data:www-data /var/www/app/var

ENV APP_ENV=prod
ENV APP_DEBUG=0

USER www-data
EXPOSE 9000
