#!/bin/bash
set -e

# Instala dependências se não existir vendor
if [ ! -d "/var/www/app/vendor" ]; then
    composer install --no-interaction --prefer-dist --optimize-autoloader --working-dir=/var/www/app
fi

# Limpa e aquece o cache
php /var/www/app/bin/console cache:clear || true
php /var/www/app/bin/console cache:warmup || true

exec "$@"
