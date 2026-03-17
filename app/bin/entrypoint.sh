#!/bin/bash
set -e

# Instala dependências se não existir vendor
if [ ! -d "/var/www/app/vendor" ]; then
    composer install --no-interaction --prefer-dist --optimize-autoloader
fi

# Limpa e aquece o cache
php bin/console cache:clear || true
php bin/console cache:warmup || true

exec "$@"
