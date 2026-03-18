#!/bin/bash
set -e
echo "==> Copiando assets estáticos..."
cp -rn /var/www/app/public/. /var/www/static/
echo "==> Rodando migrations..."
php /var/www/app/bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
echo "==> Iniciando PHP-FPM..."
exec "$@"