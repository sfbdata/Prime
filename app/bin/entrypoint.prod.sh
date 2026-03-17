#!/bin/bash
set -e

echo "==> Rodando migrations..."
php /var/www/app/bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

echo "==> Iniciando PHP-FPM..."
exec "$@"