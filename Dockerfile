FROM php:8.2-fpm AS base

# bcmath: aritmética decimal exata, sem float. É o que a calculadora de atualização monetária
# (App\AtualizacaoMonetaria) usa para o cálculo intermediário com escala 10 — dinheiro em float
# acumula erro de arredondamento e o resultado deixa de bater com o do TJDFT ao centavo.
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libpq-dev \
    libzip-dev \
    libicu-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libxml2-dev \
    libonig-dev \
    zip \
    ghostscript \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j1 \
        bcmath \
        pdo \
        pdo_pgsql \
        zip \
        intl \
        mbstring \
        gd \
        dom \
        simplexml \
        xml \
        opcache \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www

RUN echo "date.timezone = America/Sao_Paulo" > /usr/local/etc/php/conf.d/timezone.ini

RUN { \
    echo "max_input_vars = 5000"; \
    echo "upload_max_filesize = 65M"; \
    echo "post_max_size = 70M"; \
} > /usr/local/etc/php/conf.d/limits.ini

# -----------------------------------------------
# DEV
# -----------------------------------------------
FROM base AS dev

ARG UID=1000
ARG GID=1000
RUN usermod -u $UID www-data && groupmod -g $GID www-data

USER www-data
EXPOSE 9000

# -----------------------------------------------
# PROD BUILDER
# -----------------------------------------------
FROM base AS prod_builder

WORKDIR /var/www/app

COPY app/composer.json app/composer.lock ./

# O build baixa 122 pacotes do GitHub. ANÔNIMO, o GitHub limita por IP e devolve
# HTTP 429 no meio do caminho — foi o que derrubou dois deploys em 17/08/2026,
# deixando o site em modo manutenção. Autenticado, o teto sobe para 5.000/hora.
#
# O token entra por SECRET do BuildKit, não por ARG/ENV: assim ele não fica
# gravado nas camadas nem no histórico da imagem. Se o segredo não for fornecido
# (build de dev), cai para `{}` e o comportamento é o de antes — o build não
# quebra por causa disso.
# O cache do composer (`type=cache`) guarda os zips já baixados e sobrevive ao build.
# Sem ele, TODA invalidação desta camada — composer.lock novo, Dockerfile editado, ou a
# poda de cache do fim do deploy — volta a baixar os 122 pacotes do GitHub. Com ele, o
# composer instala do disco e o build deixa de depender do GitHub estar de pé.
#
# Não engorda a imagem: o cache é do BuildKit, não vira camada. `sharing=locked` serializa
# os dois serviços (php e worker), que buildam o mesmo target no mesmo `compose build` —
# o composer não tranca o próprio cache dir, então `shared` deixaria os dois gravando juntos.
# O tamanho é limitado pelo próprio composer (`cache-files-maxsize` = 314572800, medido no
# Composer 2.9.5): ele faz a coleta de lixo sozinho ao passar do teto. Isso cobre `cache/files`,
# que é o volume (63 MB medidos para os 122 pacotes); `cache/repo` fica de fora e é pequeno.
RUN --mount=type=secret,id=composer_auth \
    --mount=type=cache,id=jusprime-composer,target=/tmp/composer-cache,sharing=locked \
    COMPOSER_AUTH="$(cat /run/secrets/composer_auth 2>/dev/null || echo '{}')" \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

COPY app/ ./

# ENV fake só para build
RUN printf "APP_ENV=prod\nAPP_SECRET=build_placeholder\nDATABASE_URL=pgsql://u:p@db:5432/db\nMAILER_DSN=null://null\nDATAJUD_API_KEY=x\nDATAJUD_BASE_URL=x\nDEFAULT_URI=http://localhost\n" > .env

RUN composer dump-autoload --classmap-authoritative --no-dev --no-interaction \
    && APP_ENV=prod APP_DEBUG=0 php -d memory_limit=512M bin/console cache:warmup

RUN rm .env

# -----------------------------------------------
# PROD (imagem final)
# -----------------------------------------------
FROM base AS prod

RUN { \
    echo "opcache.enable=1"; \
    echo "opcache.memory_consumption=256"; \
    echo "opcache.max_accelerated_files=20000"; \
    echo "opcache.validate_timestamps=0"; \
    echo "opcache.revalidate_freq=0"; \
} > /usr/local/etc/php/conf.d/opcache.ini

WORKDIR /var/www

COPY --from=prod_builder --chown=www-data:www-data /var/www/app /var/www/app

# Garante arquivo .env (mesmo usando env_file)
RUN touch /var/www/app/.env

# 🔥 CORREÇÃO DEFINITIVA (permissão + estrutura)
RUN mkdir -p /var/www/app/var/cache \
    /var/www/app/var/log \
    /var/www/app/public/uploads \
    && chmod -R 775 /var/www/app/var /var/www/app/public/uploads \
    && chown -R www-data:www-data /var/www/app/var /var/www/app/public/uploads

# Pasta de arquivos estáticos (nginx)
RUN mkdir -p /var/www/static \
    && chown -R www-data:www-data /var/www/static

# Entrypoint (onde garantimos tudo em runtime)
COPY app/bin/entrypoint.prod.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENV APP_ENV=prod
ENV APP_DEBUG=0

USER www-data
EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]