#!/bin/bash

set -e  # Exit on error

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "Limpando caches e reiniciando Symfony (PRODUCAO)..."

docker exec jusprime_php_prod bash -c "
cd /var/www/app && \
APP_ENV=prod php bin/console cache:clear --env=prod --no-debug && \
APP_ENV=prod php bin/console cache:warmup --env=prod --no-debug && \
composer dump-autoload --optimize --no-dev
"

echo "Reiniciando PHP e Nginx para recarregar OPcache e templates..."
docker compose --project-directory "$SCRIPT_DIR" -f docker-compose.prod.yml restart php nginx

echo ""
echo "Reiniciando todos os containers..."
docker restart $(docker ps -q)

echo ""
echo "Caches limpos e containers reiniciados com sucesso!"
echo ""
echo "Para executar migrations em producao:"
echo "   docker exec -it jusprime_php_prod bash -c 'cd /var/www/app && APP_ENV=prod php bin/console doctrine:migrations:migrate --no-interaction --env=prod'"
echo ""
echo "Processo concluido!"
