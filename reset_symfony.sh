#!/bin/bash

set -e  # Exit on error

echo "🔄 Limpando caches e reiniciando Symfony..."

docker exec -it jusprime_php_dev bash -c "
cd /var/www/app && \
php bin/console cache:clear && \
php bin/console doctrine:cache:clear-metadata && \
php bin/console doctrine:cache:clear-query && \
php bin/console doctrine:cache:clear-result && \
composer dump-autoload
"

echo ""
echo "✅ Caches limpos com sucesso!"
echo ""
echo "💡 Para resetar totalmente o banco de dados e recarregar fixtures, execute:"
echo "   docker exec -it jusprime_php_dev bash -c 'cd /var/www/app && php bin/console doctrine:database:drop --force && php bin/console doctrine:database:create && php bin/console doctrine:migrations:migrate --no-interaction && php bin/console doctrine:fixtures:load --purge'"
echo ""
echo "✅ Processo concluído!"