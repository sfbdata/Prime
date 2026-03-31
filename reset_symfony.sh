#!/bin/bash

echo "🔄 Limpando caches e reiniciando Symfony..."

docker exec -it jusprime_php_dev bash -c "
cd /var/www/app && \
php bin/console cache:clear && \
php bin/console doctrine:cache:clear-metadata && \
php bin/console doctrine:cache:clear-query && \
php bin/console doctrine:cache:clear-result && \
composer dump-autoload
"

echo "✅ Processo concluído!"