#!/bin/bash

set -e  # Exit on error

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "⚠️  ATENCAO: Este script vai dropar e recriar o banco de dados de PRODUCAO!"
echo "🗑️  Todos os dados serao perdidos. Deseja continuar? (s/n)"
read -r confirm

if [[ "$confirm" != "s" && "$confirm" != "S" ]]; then
    echo "Operacao cancelada."
    exit 0
fi

echo ""
echo "Dropando banco de dados..."
docker exec jusprime_php_prod bash -c "
cd /var/www/app && \
APP_ENV=prod php bin/console doctrine:database:drop --force --if-exists --env=prod --no-debug
"

echo "Banco dropado!"

echo ""
echo "Criando novo banco de dados..."
docker exec jusprime_php_prod bash -c "
cd /var/www/app && \
APP_ENV=prod php bin/console doctrine:database:create --env=prod --no-debug
"

echo "Banco criado!"

echo ""
echo "Executando migrations..."
docker exec jusprime_php_prod bash -c "
cd /var/www/app && \
APP_ENV=prod php bin/console doctrine:migrations:migrate --no-interaction --env=prod --no-debug
"

echo "Migrations executadas!"

echo ""
echo "Banco de dados de producao resetado com sucesso!"
